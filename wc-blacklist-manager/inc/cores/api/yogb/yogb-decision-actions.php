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
		$order_id       = (int) $order->get_id();
		$can_manage_woo = current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
		$can_edit_order = current_user_can( 'edit_shop_order', $order_id )
			|| current_user_can( 'edit_post', $order_id )
			|| $can_manage_woo;
		return $order_id > 0 && $can_manage_woo && $can_edit_order;
	}

	public static function detail_url( WC_Order $order ) : string {
		$ref = (string) $order->get_meta( YOGB_BM_Check_Orders::META_DECISION_REF, true );
		if ( ! self::supports( 'decision_detail_view_v1' ) || ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $ref ) || ! self::can_manage_order( $order ) ) {
			return '';
		}
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => 'yogb_gbl_view_decision',
					'order_id' => (int) $order->get_id(),
				],
				admin_url( 'admin-post.php' )
			),
			'yogb_gbl_view_decision_' . (int) $order->get_id()
		);
	}

	public static function handle_view_decision() : void {
		$order = self::requested_order( 'get' );
		if ( ! $order || ! self::can_manage_order( $order ) ) {
			wp_die( esc_html__( 'You do not have permission to view this decision.', 'wc-blacklist-manager' ), 403 );
		}
		$order_id = (int) $order->get_id();
		check_admin_referer( 'yogb_gbl_view_decision_' . $order_id );
		if ( ! self::supports( 'decision_detail_view_v1' ) ) {
			self::redirect_to_order( $order, 'detail_unsupported' );
		}

		$ref = (string) $order->get_meta( YOGB_BM_Check_Orders::META_DECISION_REF, true );
		if ( ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $ref ) ) {
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
		$use_v2 = self::supports_v2();
		if ( ! $use_v2 && ! self::supports( 'decision_outcomes_v1' ) ) {
			self::redirect_to_order( $order, 'outcome_unsupported' );
		}

		$ref = (string) $order->get_meta( YOGB_BM_Check_Orders::META_DECISION_REF, true );
		if ( ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $ref ) ) {
			self::redirect_to_order( $order, 'feedback_missing' );
		}

		$type          = '';
		$conclusion    = '';
		$review_status = '';
		$evidence_type = '';
		$reason_code   = '';
		$reference     = '';
		if ( $use_v2 ) {
			$decision  = sanitize_key( (string) $order->get_meta( '_yogb_gbl_decision', true ) );
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
			implode( '|', [ $use_v2 ? '2' : '1', $type, $conclusion, $review_status, $evidence_type, $reason_code, hash( 'sha256', $reference ) ] )
		);
		$current_signature = (string) $order->get_meta( self::META_OUTCOME_SIGNATURE, true );
		$event_uuid   = (string) $order->get_meta( self::META_OUTCOME_UUID, true );
		$revision     = max( 0, (int) $order->get_meta( self::META_OUTCOME_REVISION, true ) );
		$occurred_at  = (string) $order->get_meta( self::META_OUTCOME_AT, true );
		if ( ! hash_equals( $signature, $current_signature ) || ! wp_is_uuid( $event_uuid, 4 ) || '' === $occurred_at ) {
			$revision++;
			$event_uuid  = wp_generate_uuid4();
			$occurred_at = gmdate( 'c' );
		}

		$order->update_meta_data( self::META_OUTCOME_TYPE, $type );
		$order->update_meta_data( self::META_OUTCOME_SCHEMA, $use_v2 ? 2 : 1 );
		$order->update_meta_data( self::META_OUTCOME_CONCLUSION, $conclusion );
		$order->update_meta_data( self::META_OUTCOME_REVIEW_STATUS, $review_status );
		$order->update_meta_data( self::META_OUTCOME_EVIDENCE, $evidence_type );
		$order->update_meta_data( self::META_OUTCOME_REASON, $reason_code );
		$order->update_meta_data( self::META_OUTCOME_SIGNATURE, $signature );
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

		self::redirect_to_order( $order, $outbox_id > 0 ? 'outcome_saved' : 'outcome_queue_failed' );
	}

	public static function handle_retry_outcome() : void {
		$order = self::requested_order( 'post' );
		if ( ! $order || ! self::can_manage_order( $order ) ) {
			wp_die( esc_html__( 'You do not have permission to retry this outcome.', 'wc-blacklist-manager' ), 403 );
		}
		$order_id = (int) $order->get_id();
		check_admin_referer( 'yogb_gbl_retry_outcome_' . $order_id );
		$event_uuid = (string) $order->get_meta( self::META_OUTCOME_UUID, true );
		$queued = wp_is_uuid( $event_uuid, 4 )
			&& class_exists( 'YOGB_BM_Outbox' )
			&& YOGB_BM_Outbox::retry_outcome( $event_uuid, $order_id );
		if ( $queued ) {
			$order->update_meta_data( self::META_OUTCOME_DELIVERY, 'queued' );
			$order->delete_meta_data( self::META_OUTCOME_ERROR );
			$order->delete_meta_data( self::META_OUTCOME_NEXT_AT );
			$order->save();
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

	private static function posted_key( string $name ) : string {
		if ( ! isset( $_POST[ $name ] ) || ! is_scalar( $_POST[ $name ] ) ) {
			return '';
		}
		return sanitize_key( wp_unslash( (string) $_POST[ $name ] ) );
	}

	private static function posted_text( string $name ) : string {
		if ( ! isset( $_POST[ $name ] ) || ! is_scalar( $_POST[ $name ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $_POST[ $name ] ) );
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
