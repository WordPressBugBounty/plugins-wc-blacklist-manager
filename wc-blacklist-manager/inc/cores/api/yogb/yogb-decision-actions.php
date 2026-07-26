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

	public static function init() : void {
		add_action( 'admin_post_yogb_gbl_view_decision', [ __CLASS__, 'handle_view_decision' ] );
		add_action( 'admin_post_yogb_gbl_record_outcome', [ __CLASS__, 'handle_record_outcome' ] );
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
		if ( ! self::supports( 'decision_outcomes_v1' ) ) {
			self::redirect_to_order( $order, 'outcome_unsupported' );
		}

		$type    = isset( $_POST['outcome_type'] ) ? sanitize_key( wp_unslash( $_POST['outcome_type'] ) ) : '';
		$allowed = self::outcome_labels();
		if ( ! isset( $allowed[ $type ] ) ) {
			self::redirect_to_order( $order, 'feedback_invalid' );
		}
		if ( 'manual_review_inconclusive' === $type
			&& ( ! class_exists( 'YOGB_BM_Outcomes' ) || ! YOGB_BM_Outcomes::supports_automation() ) ) {
			self::redirect_to_order( $order, 'feedback_unsupported' );
		}
		$ref = (string) $order->get_meta( YOGB_BM_Check_Orders::META_DECISION_REF, true );
		if ( ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $ref ) ) {
			self::redirect_to_order( $order, 'feedback_missing' );
		}

		$current_type = (string) $order->get_meta( self::META_OUTCOME_TYPE, true );
		$event_uuid   = (string) $order->get_meta( self::META_OUTCOME_UUID, true );
		$revision     = max( 0, (int) $order->get_meta( self::META_OUTCOME_REVISION, true ) );
		$occurred_at  = (string) $order->get_meta( self::META_OUTCOME_AT, true );
		if ( $type !== $current_type || ! wp_is_uuid( $event_uuid, 4 ) || '' === $occurred_at ) {
			$revision++;
			$event_uuid  = wp_generate_uuid4();
			$occurred_at = gmdate( 'c' );
		}

		$order->update_meta_data( self::META_OUTCOME_TYPE, $type );
		$order->update_meta_data( self::META_OUTCOME_REVISION, max( 1, $revision ) );
		$order->update_meta_data( self::META_OUTCOME_USER_ID, get_current_user_id() );
		$order->update_meta_data( self::META_OUTCOME_AT, $occurred_at );
		$order->update_meta_data( self::META_OUTCOME_UUID, $event_uuid );
		$order->update_meta_data( self::META_OUTCOME_DELIVERY, 'queueing' );
		$order->save();

		$outbox_id = YOGB_BM_Outcomes::capture_manual_revision(
			$order,
			$type,
			$event_uuid,
			$occurred_at,
			max( 1, $revision )
		);
		$order->update_meta_data( self::META_OUTCOME_DELIVERY, $outbox_id > 0 ? 'queued' : 'queue_failed' );
		$order->save();

		self::redirect_to_order( $order, $outbox_id > 0 ? 'feedback_saved' : 'feedback_queue_failed' );
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
