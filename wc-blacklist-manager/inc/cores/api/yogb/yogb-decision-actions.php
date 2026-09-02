<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Secure order-admin actions for Global Blacklist decision details/outcomes.
 */
final class YOGB_BM_Decision_Actions {
	const META_OUTCOME_TYPE     = '_yogb_gbl_outcome_type';
	const META_OUTCOME_REVISION = '_yogb_gbl_outcome_revision';
	const META_OUTCOME_USER_ID  = '_yogb_gbl_outcome_user_id';
	const META_OUTCOME_AT       = '_yogb_gbl_outcome_at';
	const META_OUTCOME_UUID     = '_yogb_gbl_outcome_event_uuid';
	const META_OUTCOME_DELIVERY = '_yogb_gbl_outcome_delivery';
	const META_OUTCOME_SCHEMA   = '_yogb_gbl_outcome_schema';
	const META_OUTCOME_CONCLUSION = '_yogb_gbl_outcome_conclusion';
	const META_OUTCOME_REVIEW_STATUS = '_yogb_gbl_outcome_review_status';
	const META_OUTCOME_EVIDENCE = '_yogb_gbl_outcome_evidence';
	const META_OUTCOME_REASON   = '_yogb_gbl_outcome_reason';
	const META_OUTCOME_SIGNATURE = '_yogb_gbl_outcome_signature';
	const META_OUTCOME_HTTP_CODE = '_yogb_gbl_outcome_http_code';
	const META_OUTCOME_ERROR    = '_yogb_gbl_outcome_error';
	const META_OUTCOME_ATTEMPTS = '_yogb_gbl_outcome_attempts';
	const META_OUTCOME_NEXT_AT  = '_yogb_gbl_outcome_next_at';
	const META_OUTCOME_DECISION_REF = '_yogb_gbl_outcome_decision_ref';
	const META_OUTCOME_DELIVERY_STATE_PREFIX = '_yogb_gbl_outcome_delivery_state_';

	public static function init() : void {
		add_action( 'admin_post_yogb_gbl_view_decision', [ __CLASS__, 'handle_view_decision' ] );
		add_action( 'admin_post_yogb_gbl_record_outcome', [ __CLASS__, 'handle_record_outcome' ] );
		add_action( 'admin_post_yogb_gbl_retry_outcome', [ __CLASS__, 'handle_retry_outcome' ] );
	}

	public static function outcome_labels() : array {
		return [
			'fraud_confirmed'         => __( 'Fraud was confirmed', 'wc-blacklist-manager' ),
			'false_positive_confirmed'=> __( 'Customer was legitimate', 'wc-blacklist-manager' ),
			'manual_review_inconclusive'=> __( 'Not sure', 'wc-blacklist-manager' ),
			'manual_review_passed'    => __( 'Manual review passed', 'wc-blacklist-manager' ),
			'manual_review_failed'    => __( 'Manual review failed', 'wc-blacklist-manager' ),
		];
	}

	public static function feedback_labels() : array {
		$labels = self::outcome_labels();
		$keys   = [ 'false_positive_confirmed', 'fraud_confirmed' ];
		if ( class_exists( 'YOGB_BM_Outcomes' ) && YOGB_BM_Outcomes::supports_automation() ) {
			$keys[] = 'manual_review_inconclusive';
		}
		return array_intersect_key( $labels, array_flip( $keys ) );
	}

	public static function supports_v2() : bool {
		return self::supports( 'decision_outcomes_v2' );
	}

	public static function current_decision_context( WC_Order $order ) : array {
		$decision = sanitize_key( (string) $order->get_meta( YOGB_BM_Check_Orders::META_DECISION, true ) );
		$status   = sanitize_key( (string) $order->get_meta( YOGB_BM_Check_Orders::META_CHECK_STATUS, true ) );
		$ref      = (string) $order->get_meta( YOGB_BM_Check_Orders::META_DECISION_REF, true );
		$at       = max( 0, (int) $order->get_meta( YOGB_BM_Check_Orders::META_DECISION_AT, true ) );

		if ( 'success' !== $status
			|| ! in_array( $decision, [ 'allow', 'challenge', 'block' ], true )
			|| ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $ref )
			|| $at <= 0 ) {
			return [];
		}

		return [
			'decision'     => $decision,
			'decision_ref' => $ref,
			'decision_at'  => $at,
		];
	}

	public static function outcome_protocol() : string {
		if ( self::supports_v2() ) {
			return 'v2';
		}
		return self::supports( 'decision_outcomes_v1' ) ? 'v1' : '';
	}

	public static function outcome_delivery_state( WC_Order $order, string $decision_ref, string $event_uuid ) : array {
		$key = self::outcome_delivery_state_key( $decision_ref, $event_uuid );
		if ( '' === $key ) {
			return [];
		}
		$state = $order->get_meta( $key, true );
		if ( ! is_array( $state )
			|| ! hash_equals( $decision_ref, (string) ( $state['decision_ref'] ?? '' ) )
			|| ! hash_equals( strtolower( $event_uuid ), (string) ( $state['event_uuid'] ?? '' ) ) ) {
			return [];
		}
		return [
			'status'     => sanitize_key( (string) ( $state['status'] ?? '' ) ),
			'http_code'  => max( 0, (int) ( $state['http_code'] ?? 0 ) ),
			'error'      => substr( sanitize_key( (string) ( $state['error'] ?? '' ) ), 0, 255 ),
			'attempts'   => max( 0, (int) ( $state['attempts'] ?? 0 ) ),
			'next_at'    => sanitize_text_field( (string) ( $state['next_at'] ?? '' ) ),
		];
	}

	public static function store_outcome_delivery_state(
		WC_Order $order,
		string $decision_ref,
		string $event_uuid,
		string $status,
		int $http_code = 0,
		string $error = '',
		int $attempts = 0,
		string $next_at = ''
	) : bool {
		$key = self::outcome_delivery_state_key( $decision_ref, $event_uuid );
		if ( '' === $key ) {
			return false;
		}
		$order->update_meta_data(
			$key,
			[
				'decision_ref' => $decision_ref,
				'event_uuid'   => strtolower( $event_uuid ),
				'status'       => sanitize_key( $status ),
				'http_code'    => max( 0, $http_code ),
				'error'        => substr( sanitize_key( $error ), 0, 255 ),
				'attempts'     => max( 0, $attempts ),
				'next_at'      => sanitize_text_field( $next_at ),
			]
		);
		$order->save_meta_data();
		return true;
	}

	public static function actual_outcome_labels( string $decision ) : array {
		$labels = [
			'risk'         => __( 'Risk or fraud was confirmed', 'wc-blacklist-manager' ),
			'safe'         => __( 'Customer was legitimate', 'wc-blacklist-manager' ),
			'inconclusive' => __( 'Review was inconclusive', 'wc-blacklist-manager' ),
		];
		$keys = 'allow' === sanitize_key( $decision )
			? [ 'risk', 'inconclusive' ]
			: [ 'risk', 'safe', 'inconclusive' ];
		return array_intersect_key( $labels, array_flip( $keys ) );
	}

	public static function evidence_labels( string $conclusion = '' ) : array {
		$labels = [
			'chargeback'                   => __( 'Chargeback confirmed', 'wc-blacklist-manager' ),
			'stolen_card'                  => __( 'Stolen card confirmed', 'wc-blacklist-manager' ),
			'payment_processor_alert'      => __( 'Payment provider risk alert', 'wc-blacklist-manager' ),
			'fraud_network'                => __( 'Fraud network confirmation', 'wc-blacklist-manager' ),
			'identity_verified'            => __( 'Customer identity verified', 'wc-blacklist-manager' ),
			'customer_verification_passed' => __( 'Customer verification passed', 'wc-blacklist-manager' ),
			'known_customer'               => __( 'Known legitimate customer', 'wc-blacklist-manager' ),
			'merchant_error'               => __( 'Merchant or staff error', 'wc-blacklist-manager' ),
			'incorrect_identity_match'     => __( 'Identity match was incorrect', 'wc-blacklist-manager' ),
			'manual_investigation'         => __( 'Manual investigation', 'wc-blacklist-manager' ),
			'other'                        => __( 'Other documented reason', 'wc-blacklist-manager' ),
		];
		$keys = [
			'risk' => [ 'chargeback', 'stolen_card', 'payment_processor_alert', 'fraud_network', 'manual_investigation', 'other' ],
			'safe' => [ 'identity_verified', 'customer_verification_passed', 'known_customer', 'merchant_error', 'incorrect_identity_match', 'manual_investigation', 'other' ],
		];
		return isset( $keys[ $conclusion ] )
			? array_intersect_key( $labels, array_flip( $keys[ $conclusion ] ) )
			: $labels;
	}

	public static function reference_required( string $evidence_type ) : bool {
		return in_array(
			sanitize_key( $evidence_type ),
			[ 'chargeback', 'stolen_card', 'payment_processor_alert', 'fraud_network', 'identity_verified', 'customer_verification_passed' ],
			true
		);
	}

	public static function evidence_reference_placeholder( string $evidence_type ) : string {
		$placeholders = [
			'chargeback'                   => __( 'e.g. payment provider dispute ID', 'wc-blacklist-manager' ),
			'stolen_card'                  => __( 'e.g. provider fraud case ID', 'wc-blacklist-manager' ),
			'payment_processor_alert'      => __( 'e.g. payment provider alert ID', 'wc-blacklist-manager' ),
			'fraud_network'                => __( 'e.g. internal fraud incident ID', 'wc-blacklist-manager' ),
			'identity_verified'            => __( 'e.g. KYC verification session ID', 'wc-blacklist-manager' ),
			'customer_verification_passed' => __( 'e.g. verification check or ticket ID', 'wc-blacklist-manager' ),
			'known_customer'               => __( 'e.g. internal review ticket ID', 'wc-blacklist-manager' ),
			'merchant_error'               => __( 'e.g. internal correction ticket ID', 'wc-blacklist-manager' ),
			'incorrect_identity_match'     => __( 'e.g. identity-review ticket ID', 'wc-blacklist-manager' ),
			'manual_investigation'         => __( 'e.g. internal investigation ID', 'wc-blacklist-manager' ),
			'other'                        => __( 'e.g. documented case ID', 'wc-blacklist-manager' ),
		];
		$evidence_type = sanitize_key( $evidence_type );
		return $placeholders[ $evidence_type ] ?? __( 'e.g. case or ticket ID', 'wc-blacklist-manager' );
	}

	public static function can_manage_order( WC_Order $order ) : bool {
		return function_exists( 'wc_blacklist_manager_user_can_moderate_order' )
			&& wc_blacklist_manager_user_can_moderate_order( $order );
	}

	public static function detail_url( WC_Order $order ) : string {
		$context = self::current_decision_context( $order );
		$ref     = (string) ( $context['decision_ref'] ?? '' );
		if ( ! self::supports( 'decision_detail_view_v1' ) || '' === $ref || ! self::can_manage_order( $order ) ) {
			return '';
		}
		return wp_nonce_url(
			add_query_arg(
				[
					'action'       => 'yogb_gbl_view_decision',
					'order_id'     => (int) $order->get_id(),
					'decision_ref' => $ref,
				],
				admin_url( 'admin-post.php' )
			),
			'yogb_gbl_view_decision_' . (int) $order->get_id() . '_' . $ref
		);
	}

	public static function handle_view_decision() : void {
		$order = self::requested_order( 'get' );
		if ( ! $order || ! self::can_manage_order( $order ) ) {
			wp_die( esc_html__( 'You do not have permission to view this decision.', 'wc-blacklist-manager' ), 403 );
		}
		$order_id = (int) $order->get_id();
		$submitted_ref = self::requested_text( 'decision_ref', 'get' );
		check_admin_referer( 'yogb_gbl_view_decision_' . $order_id . '_' . $submitted_ref );
		if ( ! self::supports( 'decision_detail_view_v1' ) ) {
			self::redirect_to_order( $order, 'detail_unsupported' );
		}

		$context = self::current_decision_context( $order );
		$ref     = (string) ( $context['decision_ref'] ?? '' );
		if ( ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $submitted_ref )
			|| '' === $ref
			|| ! hash_equals( $ref, $submitted_ref ) ) {
			self::redirect_to_order( $order, 'detail_missing' );
		}
		$response = YOGB_BM_Report::post_json_signed(
			YOGB_BM_Report::REST_ROUTE . '/decision/view-grants',
			[ 'decision_ref' => $ref ]
		);
		$payload  = json_decode( (string) ( $response['body'] ?? '' ), true );
		$view_url = is_array( $payload ) ? (string) ( $payload['view_url'] ?? '' ) : '';
		if ( empty( $response['ok'] ) || ! self::is_trusted_server_url( $view_url ) ) {
			self::redirect_to_order( $order, 'detail_error' );
		}

		wp_redirect( esc_url_raw( $view_url ), 302, 'Blacklist Manager' );
		exit;
	}

	public static function handle_record_outcome() : void {
		$order = self::requested_order( 'post' );
		if ( ! $order || ! self::can_manage_order( $order ) ) {
			wp_die( esc_html__( 'You do not have permission to update this outcome.', 'wc-blacklist-manager' ), 403 );
		}
		$order_id = (int) $order->get_id();
		check_admin_referer( 'yogb_gbl_record_outcome_' . $order_id );
		$protocol           = self::outcome_protocol();
		$submitted_protocol = self::posted_key( 'outcome_protocol' );
		if ( '' === $protocol || ! hash_equals( $protocol, $submitted_protocol ) ) {
			self::redirect_to_order( $order, 'outcome_unsupported' );
		}

		$context       = self::current_decision_context( $order );
		$ref           = (string) ( $context['decision_ref'] ?? '' );
		$submitted_ref = self::posted_text( 'decision_ref' );
		if ( ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $submitted_ref )
			|| '' === $ref
			|| ! hash_equals( $ref, $submitted_ref ) ) {
			self::redirect_to_order( $order, 'outcome_stale' );
		}
		$use_v2 = 'v2' === $protocol;

		$type          = '';
		$conclusion    = '';
		$review_status = '';
		$evidence_type = '';
		$reason_code   = '';
		$reference     = '';
		if ( $use_v2 ) {
			$decision  = (string) $context['decision'];
			$conclusion = self::posted_key( 'conclusion' );
			$allowed    = self::actual_outcome_labels( $decision );
			if ( ! in_array( $decision, [ 'allow', 'challenge', 'block' ], true ) || ! isset( $allowed[ $conclusion ] ) ) {
				self::redirect_to_order( $order, 'outcome_invalid' );
			}

			$review_status = 'inconclusive' === $conclusion ? 'inconclusive' : 'resolved';
			$evidence_type = 'inconclusive' === $conclusion
				? 'none'
				: self::posted_key( 'evidence_type' );
			$reason_code   = $evidence_type;
			if ( 'resolved' === $review_status && ! isset( self::evidence_labels( $conclusion )[ $evidence_type ] ) ) {
				self::redirect_to_order( $order, 'outcome_reason_required' );
			}

			$reference = trim( self::posted_text( 'evidence_reference' ) );
			$reference = substr( $reference, 0, 128 );
			if ( self::reference_required( $evidence_type ) && '' === $reference ) {
				self::redirect_to_order( $order, 'outcome_reference_required' );
			}
			$type = 'review_' . $conclusion;
			$provenance = class_exists( 'YOGB_BM_Evidence_Reference_Resolver' )
				? YOGB_BM_Evidence_Reference_Resolver::provenance_for_reference( $order, $evidence_type, $reference )
				: [];
		} else {
			$type    = self::posted_key( 'outcome_type' );
			$allowed = self::feedback_labels();
			if ( ! isset( $allowed[ $type ] ) ) {
				self::redirect_to_order( $order, 'feedback_invalid' );
			}
			$conclusion = self::legacy_conclusion( $type );
			$review_status = 'manual_review_inconclusive' === $type ? 'inconclusive' : 'resolved';
			$evidence_type = 'legacy_merchant_assertion';
			$provenance = [];
		}

		$signature = hash(
			'sha256',
			implode( '|', [ $ref, $use_v2 ? '2' : '1', $type, $conclusion, $review_status, $evidence_type, $reason_code, hash( 'sha256', $reference ) ] )
		);
		$identity    = self::manual_revision_identity( $order, $ref, $signature );
		$revision    = (int) $identity['revision'];
		$event_uuid  = (string) $identity['event_uuid'];
		$occurred_at = (string) $identity['occurred_at'];

		$order->update_meta_data( self::META_OUTCOME_TYPE, $type );
		$order->update_meta_data( self::META_OUTCOME_SCHEMA, $use_v2 ? 2 : 1 );
		$order->update_meta_data( self::META_OUTCOME_CONCLUSION, $conclusion );
		$order->update_meta_data( self::META_OUTCOME_REVIEW_STATUS, $review_status );
		$order->update_meta_data( self::META_OUTCOME_EVIDENCE, $evidence_type );
		$order->update_meta_data( self::META_OUTCOME_REASON, $reason_code );
		$order->update_meta_data( self::META_OUTCOME_SIGNATURE, $signature );
		$order->update_meta_data( self::META_OUTCOME_DECISION_REF, $ref );
		$order->update_meta_data( self::META_OUTCOME_REVISION, max( 1, $revision ) );
		$order->update_meta_data( self::META_OUTCOME_USER_ID, get_current_user_id() );
		$order->update_meta_data( self::META_OUTCOME_AT, $occurred_at );
		$order->update_meta_data( self::META_OUTCOME_UUID, $event_uuid );
		$order->update_meta_data( self::META_OUTCOME_DELIVERY, 'queueing' );
		$order->delete_meta_data( self::META_OUTCOME_HTTP_CODE );
		$order->delete_meta_data( self::META_OUTCOME_ERROR );
		$order->delete_meta_data( self::META_OUTCOME_ATTEMPTS );
		$order->delete_meta_data( self::META_OUTCOME_NEXT_AT );
		$order->save();
		self::store_outcome_delivery_state( $order, $ref, $event_uuid, 'queueing' );

		$outbox_id = $use_v2
			? YOGB_BM_Outcomes::capture_manual_revision_v2(
				$order,
				$conclusion,
				$review_status,
				$evidence_type,
				$reason_code,
				$reference,
				$event_uuid,
				$occurred_at,
				max( 1, $revision ),
				$provenance
			)
			: YOGB_BM_Outcomes::capture_manual_revision(
				$order,
				$type,
				$event_uuid,
				$occurred_at,
				max( 1, $revision )
			);
		$order->update_meta_data( self::META_OUTCOME_DELIVERY, $outbox_id > 0 ? 'queued' : 'queue_failed' );
		$order->save();
		self::store_outcome_delivery_state( $order, $ref, $event_uuid, $outbox_id > 0 ? 'queued' : 'queue_failed' );

		self::redirect_to_order( $order, $outbox_id > 0 ? 'outcome_saved' : 'outcome_queue_failed' );
	}

	public static function handle_retry_outcome() : void {
		$order = self::requested_order( 'post' );
		if ( ! $order || ! self::can_manage_order( $order ) ) {
			wp_die( esc_html__( 'You do not have permission to retry this outcome.', 'wc-blacklist-manager' ), 403 );
		}
		$order_id = (int) $order->get_id();
		check_admin_referer( 'yogb_gbl_retry_outcome_' . $order_id );
		$protocol           = self::outcome_protocol();
		$submitted_protocol = self::posted_key( 'outcome_protocol' );
		$submitted_ref      = self::posted_text( 'decision_ref' );
		$submitted_uuid     = self::posted_text( 'event_uuid' );
		$context            = self::current_decision_context( $order );
		$current_ref        = (string) ( $context['decision_ref'] ?? '' );
		$saved_ref          = (string) $order->get_meta( self::META_OUTCOME_DECISION_REF, true );
		$event_uuid         = (string) $order->get_meta( self::META_OUTCOME_UUID, true );
		$saved_schema       = max( 0, (int) $order->get_meta( self::META_OUTCOME_SCHEMA, true ) );
		$expected_schema    = 'v2' === $protocol ? 2 : 1;
		$binding_valid      = '' !== $protocol
			&& hash_equals( $protocol, $submitted_protocol )
			&& preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $submitted_ref )
			&& '' !== $current_ref
			&& hash_equals( $current_ref, $submitted_ref )
			&& hash_equals( $current_ref, $saved_ref )
			&& $expected_schema === $saved_schema
			&& wp_is_uuid( $event_uuid, 4 )
			&& hash_equals( $event_uuid, $submitted_uuid );
		if ( ! $binding_valid ) {
			self::redirect_to_order( $order, 'outcome_stale' );
		}

		$queued = class_exists( 'YOGB_BM_Outbox' )
			&& YOGB_BM_Outbox::retry_outcome( $event_uuid, $order_id );
		if ( $queued ) {
			$order->update_meta_data( self::META_OUTCOME_DELIVERY, 'queued' );
			$order->delete_meta_data( self::META_OUTCOME_ERROR );
			$order->delete_meta_data( self::META_OUTCOME_NEXT_AT );
			$order->save();
			self::store_outcome_delivery_state( $order, $current_ref, $event_uuid, 'queued' );
		}
		self::redirect_to_order( $order, $queued ? 'outcome_retry_queued' : 'outcome_retry_failed' );
	}

	private static function legacy_conclusion( string $type ) : string {
		if ( 'fraud_confirmed' === $type ) {
			return 'risk';
		}
		if ( 'false_positive_confirmed' === $type ) {
			return 'safe';
		}
		return 'inconclusive';
	}

	private static function manual_revision_identity( WC_Order $order, string $ref, string $signature ) : array {
		$current_signature = (string) $order->get_meta( self::META_OUTCOME_SIGNATURE, true );
		$current_ref       = (string) $order->get_meta( self::META_OUTCOME_DECISION_REF, true );
		$event_uuid       = (string) $order->get_meta( self::META_OUTCOME_UUID, true );
		$revision         = max( 0, (int) $order->get_meta( self::META_OUTCOME_REVISION, true ) );
		$occurred_at      = (string) $order->get_meta( self::META_OUTCOME_AT, true );
		$same_ref         = preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $current_ref ) && hash_equals( $ref, $current_ref );

		if ( ! $same_ref ) {
			return [
				'revision'    => 1,
				'event_uuid'  => wp_generate_uuid4(),
				'occurred_at' => gmdate( 'c' ),
			];
		}
		if ( hash_equals( $signature, $current_signature ) && wp_is_uuid( $event_uuid, 4 ) && '' !== $occurred_at ) {
			return [
				'revision'    => max( 1, $revision ),
				'event_uuid'  => $event_uuid,
				'occurred_at' => $occurred_at,
			];
		}

		return [
			'revision'    => max( 0, $revision ) + 1,
			'event_uuid'  => wp_generate_uuid4(),
			'occurred_at' => gmdate( 'c' ),
		];
	}

	private static function outcome_delivery_state_key( string $decision_ref, string $event_uuid ) : string {
		$event_uuid = strtolower( $event_uuid );
		if ( ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $decision_ref ) || ! wp_is_uuid( $event_uuid, 4 ) ) {
			return '';
		}
		return self::META_OUTCOME_DELIVERY_STATE_PREFIX . str_replace( '-', '', $event_uuid );
	}

	private static function posted_key( string $name ) : string {
		if ( ! isset( $_POST[ $name ] ) || ! is_scalar( $_POST[ $name ] ) ) {
			return '';
		}
		return sanitize_key( wp_unslash( (string) $_POST[ $name ] ) );
	}

	private static function posted_text( string $name ) : string {
		return self::requested_text( $name, 'post' );
	}

	private static function requested_text( string $name, string $method ) : string {
		$source = 'get' === $method ? $_GET : $_POST;
		if ( ! isset( $source[ $name ] ) || ! is_scalar( $source[ $name ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $source[ $name ] ) );
	}

	private static function supports( string $capability ) : bool {
		return in_array(
			$capability,
			(array) get_option( 'yogb_bm_server_capabilities', [] ),
			true
		);
	}

	private static function requested_order( string $method ) {
		$source   = 'post' === $method ? $_POST : $_GET;
		$order_id = isset( $source['order_id'] ) ? absint( wp_unslash( $source['order_id'] ) ) : 0;
		return $order_id > 0 ? wc_get_order( $order_id ) : false;
	}

	private static function is_trusted_server_url( string $url ) : bool {
		$view   = wp_parse_url( $url );
		$server = wp_parse_url( YOGB_BM_Report::server_base() );
		if ( ! is_array( $view ) || ! is_array( $server ) ) {
			return false;
		}
		$view_host   = strtolower( (string) ( $view['host'] ?? '' ) );
		$server_host = strtolower( (string) ( $server['host'] ?? '' ) );
		$view_scheme = strtolower( (string) ( $view['scheme'] ?? '' ) );
		$server_port = isset( $server['port'] ) ? (int) $server['port'] : 0;
		$view_port   = isset( $view['port'] ) ? (int) $view['port'] : 0;
		$is_https    = 'https' === $view_scheme;
		$is_local    = defined( 'YOGB_BM_ALLOW_NONPROD' )
			&& YOGB_BM_ALLOW_NONPROD
			&& 'production' !== wp_get_environment_type()
			&& (bool) preg_match( '/\.local$/i', $view_host )
			&& 'http' === $view_scheme;

		return '' !== $view_host
			&& $view_host === $server_host
			&& $view_port === $server_port
			&& ( $is_https || $is_local )
			&& empty( $view['user'] )
			&& empty( $view['pass'] );
	}

	private static function redirect_to_order( WC_Order $order, string $notice ) : void {
		$url = method_exists( $order, 'get_edit_order_url' )
			? $order->get_edit_order_url()
			: admin_url( 'post.php?post=' . (int) $order->get_id() . '&action=edit' );
		wp_safe_redirect( add_query_arg( 'yogb_gbl_notice', sanitize_key( $notice ), $url ) );
		exit;
	}
}

YOGB_BM_Decision_Actions::init();
