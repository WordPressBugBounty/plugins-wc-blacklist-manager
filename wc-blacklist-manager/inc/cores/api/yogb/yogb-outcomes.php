<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Privacy-safe decision outcome instrumentation. */
final class YOGB_BM_Outcomes {
	const META_REF               = '_yogb_gbl_decision_ref';
	const META_AT                = '_yogb_gbl_decision_at';
	const OPT_CAPABILITIES       = 'yogb_bm_server_capabilities';
	const AUTOMATION_CAPABILITY  = 'decision_outcome_automation_v1';
	const OUTCOME_V2_CAPABILITY  = 'decision_outcomes_v2';
	const OUTCOME_PROVENANCE_CAPABILITY = 'decision_outcomes_v2_provenance';
	const MATURE_HOOK            = 'yogb_bm_capture_mature_order_outcome';
	const ACTION_GROUP           = 'yogb-global-blacklist';
	const DEFAULT_MATURE_DAYS    = 30;
	const AUTOMATION_VERSION     = 1;

	/** @var array<int,string> Status changes initiated by Global Blacklist in this request. */
	private static $system_status_changes = [];

	public static function init() : void {
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'capture_payment_complete' ] );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'capture_order_completed' ], 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'capture_order_cancelled' ], 10, 2 );
		add_action( 'woocommerce_order_status_failed', [ __CLASS__, 'capture_order_failed' ], 10, 2 );
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'capture_status_change' ], 10, 4 );
		add_action( 'woocommerce_order_refunded', [ __CLASS__, 'capture_refund' ], 10, 2 );
		add_action( self::MATURE_HOOK, [ __CLASS__, 'capture_mature_order' ], 10, 2 );

		add_action( 'wc_blacklist_manager_order_suspected', [ __CLASS__, 'capture_suspected_order' ], 10, 2 );
		add_action( 'wc_blacklist_manager_order_blocked', [ __CLASS__, 'capture_blocked_order' ], 10, 2 );
		add_action( 'wc_blacklist_manager_order_blacklist_removed', [ __CLASS__, 'capture_removed_order' ], 10, 2 );

		add_action( 'yogb_bm_payment_dispute_opened', static function( $id ) {
			self::capture_automation( $id, 'payment_dispute_opened', 'payment_processor' );
		} );
		add_action( 'yogb_bm_chargeback_confirmed', static function( $id ) {
			self::capture( $id, 'chargeback_confirmed', 'processor' );
		} );
		add_action( 'yogb_bm_confirm_fraud', static function( $id ) {
			self::capture( $id, 'fraud_confirmed', 'merchant' );
		} );
		add_action( 'yogb_bm_confirm_false_positive', static function( $id ) {
			self::capture( $id, 'false_positive_confirmed', 'merchant' );
		} );
		add_action( 'yogb_bm_appeal_accepted', static function( $id ) {
			self::capture( $id, 'appeal_accepted', 'appeal' );
		} );
		add_action( 'yogb_bm_appeal_rejected', static function( $id ) {
			self::capture( $id, 'appeal_rejected', 'appeal' );
		} );
		add_action( 'yogb_bm_manual_review_passed', static function( $id ) {
			self::capture( $id, 'manual_review_passed', 'merchant' );
		} );
		add_action( 'yogb_bm_manual_review_failed', static function( $id ) {
			self::capture( $id, 'manual_review_failed', 'merchant' );
		} );
	}

	public static function supports() : bool {
		return self::supports_capability( 'decision_outcomes_v1' );
	}

	public static function supports_automation() : bool {
		return self::supports_capability( self::AUTOMATION_CAPABILITY );
	}

	public static function supports_v2() : bool {
		return self::supports_capability( self::OUTCOME_V2_CAPABILITY );
	}

	public static function mark_system_status_change( int $order_id, string $status ) : void {
		if ( $order_id > 0 ) {
			self::$system_status_changes[ $order_id ] = sanitize_key( $status );
		}
	}

	public static function capture_payment_complete( $order_id ) : void {
		self::capture( $order_id, 'payment_completed', 'woocommerce' );
	}

	public static function capture_order_completed( $order_id, $order = null ) : void {
		self::capture( $order_id, 'order_completed', 'woocommerce' );
		$order = $order instanceof WC_Order ? $order : wc_get_order( absint( $order_id ) );
		if ( $order instanceof WC_Order ) {
			self::schedule_mature_order( $order );
		}
	}

	public static function capture_order_cancelled( $order_id, $order = null ) : void {
		if ( self::is_system_status_change( absint( $order_id ), 'cancelled' ) ) {
			return;
		}
		self::capture( $order_id, 'order_cancelled', 'woocommerce' );
	}

	public static function capture_order_failed( $order_id, $order = null ) : void {
		if ( self::is_system_status_change( absint( $order_id ), 'failed' ) ) {
			return;
		}
		self::capture_automation( $order_id, 'order_failed', 'woocommerce_lifecycle' );
	}

	public static function capture_status_change( $order_id, $from, $to, $order = null ) : void {
		$order_id = absint( $order_id );
		$from     = sanitize_key( (string) $from );
		$to       = sanitize_key( (string) $to );
		if ( self::is_system_status_change( $order_id, $to ) ) {
			unset( self::$system_status_changes[ $order_id ] );
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$decision = sanitize_key( (string) $order->get_meta( '_yogb_gbl_decision', true ) );
		$is_override = in_array( $decision, [ 'block', 'challenge' ], true )
			&& in_array( $from, [ 'cancelled', 'on-hold', 'failed' ], true )
			&& in_array( $to, [ 'processing', 'completed' ], true );
		if ( ! $is_override ) {
			return;
		}

		self::capture_automation(
			$order_id,
			'merchant_override',
			'woocommerce_order',
			[
				'previous_status'   => $from,
				'current_status'    => $to,
				'decision'          => $decision,
				'automation_version'=> self::AUTOMATION_VERSION,
			]
		);
	}

	public static function capture_mature_order( $order_id, $decision_ref ) : void {
		if ( ! self::supports_automation() ) {
			return;
		}
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || ! $order->has_status( 'completed' ) ) {
			return;
		}
		$current_ref = (string) $order->get_meta( self::META_REF, true );
		if ( ! hash_equals( $current_ref, (string) $decision_ref ) || (float) $order->get_total_refunded() > 0 ) {
			return;
		}
		$completed = $order->get_date_completed();
		$age_days  = $completed ? max( 0, (int) floor( ( time() - $completed->getTimestamp() ) / DAY_IN_SECONDS ) ) : 0;
		$min_days  = self::mature_days( $order );
		if ( $age_days < $min_days ) {
			self::schedule_mature_order( $order, ( $min_days - $age_days ) * DAY_IN_SECONDS );
			return;
		}

		self::capture_automation(
			$order,
			'order_completed_matured',
			'woocommerce_lifecycle',
			[
				'order_age_days'    => $age_days,
				'payment_state'     => $order->is_paid() ? 'paid' : 'unknown',
				'automation_version'=> self::AUTOMATION_VERSION,
			]
		);
	}

	public static function capture_suspected_order( $event, $order = null ) : void {
		$order = self::order_from_event( $event, $order );
		if ( ! $order ) {
			return;
		}
		self::capture_automation(
			$order,
			'merchant_suspected',
			'merchant_order_action',
			[
				'reason_code'       => 'suspect',
				'automation_version'=> self::AUTOMATION_VERSION,
			]
		);
	}

	public static function capture_blocked_order( $event, $order = null ) : void {
		$order = self::order_from_event( $event, $order );
		if ( ! $order ) {
			return;
		}
		$reason = is_array( $event ) ? sanitize_key( (string) ( $event['reason_code'] ?? '' ) ) : '';
		$meta   = [
			'reason_code'       => $reason,
			'automation_version'=> self::AUTOMATION_VERSION,
		];
		self::capture_automation( $order, 'merchant_blocked', 'merchant_order_action', $meta );

		if ( 'chargeback' === $reason ) {
			self::capture( $order, 'chargeback_confirmed', 'merchant_order_action', $meta );
		} elseif ( in_array( $reason, [ 'stolen_card', 'fraud_network' ], true ) ) {
			self::capture( $order, 'fraud_confirmed', 'merchant_order_action', $meta );
		}
	}

	public static function capture_removed_order( $event, $order = null ) : void {
		$order = self::order_from_event( $event, $order );
		if ( ! $order ) {
			return;
		}
		$reason   = is_array( $event ) ? sanitize_key( (string) ( $event['reason_code'] ?? '' ) ) : '';
		$decision = sanitize_key( (string) $order->get_meta( '_yogb_gbl_decision', true ) );
		if ( ! in_array( $reason, [ 'customer_appeal', 'merchant_error' ], true )
			|| ! in_array( $decision, [ 'block', 'challenge' ], true ) ) {
			return;
		}
		self::capture(
			$order,
			'false_positive_confirmed',
			'merchant_order_action',
			[
				'reason_code'       => $reason,
				'decision'          => $decision,
				'automation_version'=> self::AUTOMATION_VERSION,
			]
		);
	}

	private static function supports_capability( string $capability ) : bool {
		return in_array(
			$capability,
			(array) get_option( self::OPT_CAPABILITIES, [] ),
			true
		);
	}

	/**
	 * Capture an observational or integration event exactly once per
	 * decision/type/source tuple.
	 */
	public static function capture( $order_id, string $type, string $source = 'woocommerce', array $metadata = [] ) : int {
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order ) {
			return 0;
		}
		$ref        = (string) $order->get_meta( self::META_REF, true );
		$event_key  = $ref . '|' . $type . '|' . $source;
		$event_uuid = self::uuid_from_hash( hash( 'sha256', $event_key ) );

		return self::enqueue(
			$order,
			$type,
			$source,
			$event_uuid,
			gmdate( 'c' ),
			$metadata
		);
	}

	private static function capture_automation( $order_id, string $type, string $source, array $metadata = [] ) : int {
		if ( ! self::supports_automation() ) {
			return 0;
		}
		return self::capture( $order_id, $type, $source, $metadata );
	}

	/**
	 * Queue a merchant revision using an already-persisted UUID.
	 *
	 * Retrying a saved revision reuses the same UUID. Changing the conclusion
	 * creates a new revision/UUID in YOGB_BM_Decision_Actions.
	 */
	public static function capture_manual_revision(
		WC_Order $order,
		string $type,
		string $event_uuid,
		string $occurred_at,
		int $revision,
		array $provenance = []
	) : int {
		return self::enqueue(
			$order,
			$type,
			'merchant_feedback',
			$event_uuid,
			$occurred_at,
			[
				'review_source' => 'woocommerce_order_feedback',
				'revision'      => max( 1, $revision ),
			]
		);
	}

	/**
	 * Queue a manually reviewed V2 conclusion. The server owns classification
	 * and strength; the client sends only the conclusion and supporting basis.
	 */
	public static function capture_manual_revision_v2(
		WC_Order $order,
		string $conclusion,
		string $review_status,
		string $evidence_type,
		string $reason_code,
		string $evidence_reference,
		string $event_uuid,
		string $occurred_at,
		int $revision
	) : int {
		if ( ! self::supports_v2() || ! class_exists( 'YOGB_BM_Outbox' ) ) {
			return 0;
		}
		$ref = (string) $order->get_meta( self::META_REF, true );
		if ( ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $ref ) || ! wp_is_uuid( $event_uuid, 4 ) ) {
			return 0;
		}

		$payload = [
			'schema_version' => 2,
			'event_uuid'     => $event_uuid,
			'decision_ref'   => $ref,
			'conclusion'     => sanitize_key( $conclusion ),
			'review_status'  => sanitize_key( $review_status ),
			'evidence_type'  => sanitize_key( $evidence_type ),
			'occurred_at'    => sanitize_text_field( $occurred_at ),
			'metadata'       => [
				'review_source' => 'woocommerce_order_outcome',
				'reason_code'   => substr( sanitize_key( $reason_code ), 0, 64 ),
				'revision'      => max( 1, $revision ),
			],
		];
		$evidence_reference = substr( trim( sanitize_text_field( $evidence_reference ) ), 0, 128 );
		if ( '' !== $evidence_reference ) {
			$payload['evidence_reference'] = $evidence_reference;
		}
		if ( self::supports_capability( self::OUTCOME_PROVENANCE_CAPABILITY ) && ! empty( $provenance ) ) {
			foreach ( [ 'evidence_origin', 'evidence_provider', 'evidence_event_type', 'adapter_version' ] as $key ) {
				if ( isset( $provenance[ $key ] ) && is_scalar( $provenance[ $key ] ) ) {
					$payload[ $key ] = substr( sanitize_text_field( (string) $provenance[ $key ] ), 0, 64 );
				}
			}
			if ( ! empty( $provenance['detected_at'] ) ) {
				$payload['metadata']['detected_at'] = substr( sanitize_text_field( (string) $provenance['detected_at'] ), 0, 64 );
			}
		}
		return YOGB_BM_Outbox::enqueue_outcome_v2( $payload, (int) $order->get_id() );
	}

	public static function capture_refund( $order_id, $refund_id = 0 ) : void {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return;
		}
		$total    = max( 0.0, (float) $order->get_total() );
		$refunded = abs( (float) $order->get_total_refunded() );
		$ratio    = $total > 0 ? min( 1.0, $refunded / $total ) : 0.0;
		self::capture(
			$order_id,
			$ratio >= 0.999 ? 'refund_full' : 'refund_partial',
			'woocommerce_refund',
			[
				'refund_ratio' => (string) round( $ratio, 4 ),
				'reason_code'  => 'refund',
			]
		);
	}

	private static function schedule_mature_order( WC_Order $order, int $delay = 0 ) : void {
		if ( ! self::supports_automation() ) {
			return;
		}
		$ref = (string) $order->get_meta( self::META_REF, true );
		if ( ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $ref ) ) {
			return;
		}
		$delay = $delay > 0 ? $delay : self::mature_days( $order ) * DAY_IN_SECONDS;
		$args  = [ (int) $order->get_id(), $ref ];
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_next_scheduled_action( self::MATURE_HOOK, $args, self::ACTION_GROUP ) ) {
				as_schedule_single_action( time() + max( MINUTE_IN_SECONDS, $delay ), self::MATURE_HOOK, $args, self::ACTION_GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::MATURE_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( MINUTE_IN_SECONDS, $delay ), self::MATURE_HOOK, $args );
		}
	}

	private static function mature_days( WC_Order $order ) : int {
		return min(
			90,
			max( 7, (int) apply_filters( 'yogb_bm_mature_safe_days', self::DEFAULT_MATURE_DAYS, $order ) )
		);
	}

	private static function is_system_status_change( int $order_id, string $status ) : bool {
		return isset( self::$system_status_changes[ $order_id ] )
			&& sanitize_key( $status ) === self::$system_status_changes[ $order_id ];
	}

	private static function order_from_event( $event, $order ) {
		if ( $order instanceof WC_Order ) {
			return $order;
		}
		$order_id = is_array( $event ) ? absint( $event['order_id'] ?? 0 ) : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		return $order instanceof WC_Order ? $order : false;
	}

	private static function enqueue(
		WC_Order $order,
		string $type,
		string $source,
		string $event_uuid,
		string $occurred_at,
		array $metadata
	) : int {
		if ( ! self::supports() || ! class_exists( 'YOGB_BM_Outbox' ) ) {
			return 0;
		}
		$ref = (string) $order->get_meta( self::META_REF, true );
		if ( ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $ref ) || ! wp_is_uuid( $event_uuid, 4 ) ) {
			return 0;
		}

		$safe = [];
		foreach ( [ 'refund_ratio', 'payment_state', 'review_source', 'reason_code', 'order_age_days', 'revision', 'previous_status', 'current_status', 'decision', 'automation_version' ] as $key ) {
			if ( isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ) {
				$safe[ $key ] = substr( sanitize_text_field( (string) $metadata[ $key ] ), 0, 64 );
			}
		}

		return YOGB_BM_Outbox::enqueue_outcome(
			[
				'event_uuid'  => $event_uuid,
				'decision_ref'=> $ref,
				'outcome_type'=> sanitize_key( $type ),
				'source'      => substr( sanitize_key( $source ), 0, 32 ),
				'occurred_at' => sanitize_text_field( $occurred_at ),
				'metadata'    => $safe,
			],
			(int) $order->get_id()
		);
	}

	private static function uuid_from_hash( string $hash ) : string {
		$hex = substr( $hash, 0, 32 );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-a' . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
	}
}

YOGB_BM_Outcomes::init();
