<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures payment-provider case IDs without treating order or transaction IDs as evidence.
 */
final class YOGB_BM_Evidence_Reference_Resolver {
	const META_CANDIDATES = '_yogb_gbl_evidence_reference_candidates';

	private static $allowed_types = [ 'chargeback', 'payment_processor_alert' ];

	public static function init() : void {
		add_action( 'wc_stripe_webhook_charge_dispute_created', [ __CLASS__, 'capture_stripe_dispute' ], 5, 1 );
		add_action( 'wc_stripe_webhook_review_opened', [ __CLASS__, 'capture_stripe_review' ], 5, 1 );
		add_action( 'wc_ppcp_webhook_event_customer.dispute.created', [ __CLASS__, 'capture_paypal_dispute' ], 5, 2 );
		add_action( 'yogb_bm_payment_dispute_opened', [ __CLASS__, 'capture_declared_chargeback' ], 5, 3 );
		add_action( 'yogb_bm_chargeback_confirmed', [ __CLASS__, 'capture_declared_chargeback' ], 5, 3 );
		add_action( 'yogb_bm_payment_provider_alert', [ __CLASS__, 'capture_declared_alert' ], 5, 3 );
	}

	public static function capture_stripe_dispute( $dispute ) : void {
		if ( ! is_object( $dispute ) || empty( $dispute->id ) || empty( $dispute->charge ) || ! function_exists( 'wc_stripe_get_order_from_transaction' ) ) {
			return;
		}
		$order = wc_stripe_get_order_from_transaction( (string) $dispute->charge );
		self::record( $order, 'chargeback', (string) $dispute->id, 'stripe_dispute_webhook', __( 'Stripe dispute webhook', 'wc-blacklist-manager' ) );
	}

	public static function capture_stripe_review( $review ) : void {
		if ( ! is_object( $review ) || empty( $review->id ) ) {
			return;
		}
		$order = null;
		if ( ! empty( $review->charge ) && function_exists( 'wc_stripe_get_order_from_transaction' ) ) {
			$order = wc_stripe_get_order_from_transaction( (string) $review->charge );
		}
		if ( ! $order && ! empty( $review->payment_intent ) && function_exists( 'wc_stripe_get_order_from_transaction' ) ) {
			$order = wc_stripe_get_order_from_transaction( (string) $review->payment_intent );
		}
		self::record( $order, 'payment_processor_alert', (string) $review->id, 'stripe_radar_review_webhook', __( 'Stripe Radar review webhook', 'wc-blacklist-manager' ) );
	}

	public static function capture_paypal_dispute( $dispute, $event = null ) : void {
		unset( $event );
		if ( ! is_object( $dispute ) || empty( $dispute->dispute_id ) || empty( $dispute->disputed_transactions ) ) {
			return;
		}
		$query_class = '\PaymentPlugins\WooCommerce\PPCP\Utilities\QueryUtil';
		if ( ! class_exists( $query_class ) || ! is_callable( [ $query_class, 'get_wc_order_from_paypal_txn' ] ) ) {
			return;
		}
		foreach ( (array) $dispute->disputed_transactions as $transaction ) {
			if ( ! is_object( $transaction ) || empty( $transaction->seller_transaction_id ) ) {
				continue;
			}
			$order = call_user_func( [ $query_class, 'get_wc_order_from_paypal_txn' ], (string) $transaction->seller_transaction_id );
			if ( self::record( $order, 'chargeback', (string) $dispute->dispute_id, 'paypal_dispute_webhook', __( 'PayPal dispute webhook', 'wc-blacklist-manager' ) ) ) {
				break;
			}
		}
	}

	public static function capture_declared_chargeback( $order_id, $reference = '', $provider = '' ) : void {
		if ( '' === trim( (string) $reference ) ) {
			return;
		}
		$provider = self::provider_label( $provider );
		self::record(
			$order_id,
			'chargeback',
			(string) $reference,
			sanitize_key( $provider ) . '_declared_chargeback',
			sprintf( __( '%s chargeback integration', 'wc-blacklist-manager' ), $provider )
		);
	}

	public static function capture_declared_alert( $order_id, $reference = '', $provider = '' ) : void {
		if ( '' === trim( (string) $reference ) ) {
			return;
		}
		$provider = self::provider_label( $provider );
		self::record(
			$order_id,
			'payment_processor_alert',
			(string) $reference,
			sanitize_key( $provider ) . '_declared_alert',
			sprintf( __( '%s risk-alert integration', 'wc-blacklist-manager' ), $provider )
		);
	}

	/**
	 * Store only the latest verified reference per evidence type.
	 *
	 * @param WC_Order|int|null $order
	 */
	public static function record( $order, string $evidence_type, string $reference, string $source, string $source_label ) : bool {
		$order = is_object( $order ) && is_a( $order, 'WC_Order' ) ? $order : wc_get_order( absint( $order ) );
		$evidence_type = sanitize_key( $evidence_type );
		$reference = substr( trim( sanitize_text_field( $reference ) ), 0, 128 );
		if ( ! $order || ! in_array( $evidence_type, self::$allowed_types, true ) || ! self::valid_reference( $order, $reference ) ) {
			return false;
		}

		$candidates = $order->get_meta( self::META_CANDIDATES, true );
		$candidates = is_array( $candidates ) ? $candidates : [];
		$candidates[ $evidence_type ] = [
			'reference'  => $reference,
			'source'     => sanitize_key( $source ),
			'source_label' => substr( sanitize_text_field( $source_label ), 0, 80 ),
			'observed_at'=> gmdate( 'c' ),
		];
		$order->update_meta_data( self::META_CANDIDATES, $candidates );
		$order->save();
		return true;
	}

	public static function for_order( WC_Order $order ) : array {
		$candidates = $order->get_meta( self::META_CANDIDATES, true );
		$candidates = is_array( $candidates ) ? $candidates : [];

		$mollie_ids = $order->get_meta( '_mollie_processed_chargeback_ids', true );
		if ( empty( $candidates['chargeback'] ) && is_array( $mollie_ids ) ) {
			$mollie_ids = array_values( array_filter( array_map( 'sanitize_text_field', $mollie_ids ) ) );
			$reference = empty( $mollie_ids ) ? '' : (string) end( $mollie_ids );
			if ( self::valid_reference( $order, $reference ) ) {
				$candidates['chargeback'] = [
					'reference'    => substr( $reference, 0, 128 ),
					'source'       => 'mollie_order_chargeback',
					'source_label' => __( 'Mollie chargeback webhook', 'wc-blacklist-manager' ),
					'observed_at'  => '',
				];
			}
		}

		$candidates = apply_filters( 'yogb_bm_evidence_reference_candidates', $candidates, $order );
		$resolved = [];
		foreach ( self::$allowed_types as $type ) {
			if ( empty( $candidates[ $type ] ) || ! is_array( $candidates[ $type ] ) ) {
				continue;
			}
			$candidate = $candidates[ $type ];
			$reference = isset( $candidate['reference'] ) ? substr( trim( sanitize_text_field( $candidate['reference'] ) ), 0, 128 ) : '';
			if ( ! self::valid_reference( $order, $reference ) ) {
				continue;
			}
			$resolved[ $type ] = [
				'reference'    => $reference,
				'source'       => sanitize_key( $candidate['source'] ?? '' ),
				'source_label' => substr( sanitize_text_field( $candidate['source_label'] ?? '' ), 0, 80 ),
			] + self::source_provenance( sanitize_key( $candidate['source'] ?? '' ) );
			if ( ! empty( $candidate['observed_at'] ) ) {
				$resolved[ $type ]['detected_at'] = substr( sanitize_text_field( (string) $candidate['observed_at'] ), 0, 64 );
			}
		}
		return $resolved;
	}

	public static function provenance_for_reference( WC_Order $order, string $evidence_type, string $reference ) : array {
		$evidence_type = sanitize_key( $evidence_type );
		$reference = substr( trim( sanitize_text_field( $reference ) ), 0, 128 );
		$candidates = self::for_order( $order );
		if ( ! isset( $candidates[ $evidence_type ] )
			|| ! hash_equals( (string) $candidates[ $evidence_type ]['reference'], $reference ) ) {
			return [];
		}
		$candidate = $candidates[ $evidence_type ];
		return [
			'evidence_origin'     => $candidate['evidence_origin'],
			'evidence_provider'   => $candidate['evidence_provider'],
			'evidence_event_type' => $candidate['evidence_event_type'],
			'adapter_version'     => $candidate['adapter_version'],
			'detected_at'         => $candidate['detected_at'],
		];
	}

	private static function source_provenance( string $source ) : array {
		$map = [
			'stripe_dispute_webhook' => [ 'client_gateway_detected', 'stripe', 'charge.dispute.created' ],
			'stripe_radar_review_webhook' => [ 'client_gateway_detected', 'stripe', 'review.opened' ],
			'paypal_dispute_webhook' => [ 'client_gateway_detected', 'paypal', 'customer.dispute.created' ],
			'mollie_order_chargeback' => [ 'client_gateway_detected', 'mollie', 'payment.chargeback.detected' ],
		];
		if ( isset( $map[ $source ] ) ) {
			return [
				'evidence_origin'     => $map[ $source ][0],
				'evidence_provider'   => $map[ $source ][1],
				'evidence_event_type' => $map[ $source ][2],
				'adapter_version'     => 'wc-blacklist-manager/1',
				'detected_at'         => gmdate( 'c' ),
			];
		}
		$provider = preg_replace( '/_(?:declared_chargeback|declared_alert)$/', '', $source );
		return [
			'evidence_origin'     => 'client_system_detected',
			'evidence_provider'   => sanitize_key( (string) $provider ),
			'evidence_event_type' => false !== strpos( $source, 'declared_alert' ) ? 'risk_alert.detected' : 'chargeback.detected',
			'adapter_version'     => 'wc-blacklist-manager/1',
			'detected_at'         => gmdate( 'c' ),
		];
	}

	private static function valid_reference( WC_Order $order, string $reference ) : bool {
		if ( '' === $reference || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $reference ) ) {
			return false;
		}
		if ( hash_equals( (string) $order->get_id(), $reference ) ) {
			return false;
		}
		$transaction_id = trim( (string) $order->get_transaction_id() );
		return '' === $transaction_id || ! hash_equals( $transaction_id, $reference );
	}

	private static function provider_label( $provider ) : string {
		$provider = trim( sanitize_text_field( (string) $provider ) );
		return '' === $provider ? __( 'Payment provider', 'wc-blacklist-manager' ) : substr( $provider, 0, 48 );
	}
}

YOGB_BM_Evidence_Reference_Resolver::init();
