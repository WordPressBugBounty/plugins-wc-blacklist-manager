<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hook global blacklist checks into new orders (classic + blocks checkout),
 * controlled by:
 *
 * - wc_blacklist_enable_global_blacklist (1 = enabled)
 * - wc_blacklist_global_blacklist_decision_mode:
 *      light    → notes only
 *      moderate → notes + status changes
 *      strict   → notes + status changes + hard block at checkout for "block"
 */
final class YOGB_BM_Check_Orders {

	const META_DECISION               = '_yogb_gbl_decision';
	const META_TIER                   = '_yogb_gbl_tier';
	const META_REASONS                = '_yogb_gbl_reasons';
	const META_RAW                    = '_yogb_gbl_raw';
	const META_REASON_SUMMARIES       = '_yogb_gbl_reason_summaries';
	const META_REPORT_SUMMARIES       = '_yogb_gbl_report_summaries';
	const META_SIGNAL_SUMMARIES       = '_yogb_gbl_signal_summaries';
	const META_CHECKED                = '_yogb_gbl_checked';
	const META_CHECK_STATUS           = '_yogb_gbl_check_status';
	const META_CHECK_ATTEMPTS         = '_yogb_gbl_check_attempts';
	const META_CHECK_STARTED_AT       = '_yogb_gbl_check_started_at';
	const META_CHECK_NEXT_RETRY_AT    = '_yogb_gbl_check_next_retry_at';
	const META_CHECK_LAST_ERROR       = '_yogb_gbl_check_last_error';
	const META_CHECK_LAST_HTTP_CODE   = '_yogb_gbl_check_last_http_code';
	const META_DECISION_REF          = '_yogb_gbl_decision_ref';
	const META_DECISION_AT           = '_yogb_gbl_decision_at';
	const META_DECISION_SUMMARY      = '_yogb_gbl_decision_summary';
	const META_DECISION_REASON_CODE  = '_yogb_gbl_decision_reason_code';
	const META_RESPONSE_SCHEMA       = '_yogb_gbl_response_schema';
	const META_DETAIL_AVAILABLE      = '_yogb_gbl_detail_available';
	const META_STORAGE_PROFILE       = '_yogb_gbl_storage_profile';

	// Structured Phase 3 signal meta.
	const META_EFFECTIVE_SCORE        = '_yogb_gbl_effective_score';
	const META_DIRECT_SCORE           = '_yogb_gbl_direct_score';
	const META_LINKED_BOOST           = '_yogb_gbl_linked_boost';
	const META_LINKED_NEIGHBORS_COUNT = '_yogb_gbl_linked_neighbors_count';
	const META_MATCHED_IDENTITIES     = '_yogb_gbl_matched_identities';
	const META_PRIMARY_SIGNAL_TYPE    = '_yogb_gbl_primary_signal_type';
	const META_PRIMARY_RISK_LEVEL     = '_yogb_gbl_primary_risk_level';
	const META_PRIMARY_LAST_REPORTED  = '_yogb_gbl_primary_last_reported';
	const META_MATCHED_IDENTITY_NODES        = '_yogb_gbl_matched_identity_nodes';
	const META_PRIMARY_MATCH_MODE            = '_yogb_gbl_primary_match_mode';
	const META_PRIMARY_MATCHED_VARIANT       = '_yogb_gbl_primary_matched_variant';
	const META_PRIMARY_MATCHED_IDENTITY_COUNT = '_yogb_gbl_primary_matched_identity_count';

	/**
	 * Bootstrap.
	 */
	public static function init() : void {
		$enabled = (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 );
		$development_mode = (int) get_option( 'wc_blacklist_development_mode', '1' );

		if ( 1 !== $enabled || 1 === $development_mode ) {
			return;
		}

		$api_key     = trim( (string) get_option( 'yogb_bm_api_key', '' ) );
		$api_secret  = trim( (string) get_option( 'yogb_bm_api_secret', '' ) );
		$reporter_id = trim( (string) get_option( 'yogb_bm_reporter_id', '' ) );

		$missing_connection = ( '' === $api_key || '' === $api_secret || '' === $reporter_id );
		if ( $missing_connection ) {
			return;
		}

		$mode = self::get_decision_mode();

		add_action(
			'woocommerce_checkout_order_processed',
			[ __CLASS__, 'enqueue_global_check_async' ],
			20,
			3
		);

		add_action(
			'woocommerce_store_api_checkout_order_processed',
			[ __CLASS__, 'enqueue_global_check_async' ],
			20,
			1
		);

		add_action(
			'yogb_gbl_run_check_async',
			[ __CLASS__, 'run_global_check_async' ],
			10,
			1
		);

		add_action(
			'admin_post_yogb_gbl_manual_order_check',
			[ __CLASS__, 'handle_manual_order_check' ]
		);

		add_filter( 'bulk_actions-edit-shop_order', [ __CLASS__, 'register_bulk_recheck_action' ] );
		add_filter( 'handle_bulk_actions-edit-shop_order', [ __CLASS__, 'handle_bulk_recheck_action' ], 10, 3 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'register_bulk_recheck_action' ] );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'handle_bulk_recheck_action' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'show_bulk_recheck_notice' ] );

		if ( 'strict' === $mode ) {
			add_action(
				'woocommerce_after_checkout_validation',
				[ __CLASS__, 'validate_classic_strict' ],
				20,
				2
			);

			add_action(
				'woocommerce_store_api_checkout_update_order_meta',
				[ __CLASS__, 'validate_store_api_strict' ],
				20,
				1
			);
		}
	}

	/**
	 * Enqueue a background Global Blacklist check for the order.
	 */
	public static function enqueue_global_check_async( $order_or_id, $posted_data = [], $legacy_order = null ) : void {
		if ( ! class_exists( 'YOGB_BM_Check' ) ) {
			return;
		}

		$enabled = (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 );
		if ( 1 !== $enabled ) {
			return;
		}

		$order    = null;
		$order_id = 0;

		if ( $order_or_id instanceof WC_Order ) {
			$order    = $order_or_id;
			$order_id = $order->get_id();
		} else {
			$order_id = (int) $order_or_id;
			if ( $legacy_order instanceof WC_Order ) {
				$order = $legacy_order;
			} elseif ( $order_id > 0 ) {
				$order = wc_get_order( $order_id );
			}
		}

		if ( ! $order instanceof WC_Order || $order_id <= 0 ) {
			return;
		}

		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$existing = as_next_scheduled_action(
				'yogb_gbl_run_check_async',
				[ 'order_id' => $order_id ],
				'yogb-global-blacklist'
			);
			if ( $existing ) {
				return;
			}
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 3,
				'yogb_gbl_run_check_async',
				[ 'order_id' => $order_id ],
				'yogb-global-blacklist'
			);
		} else {
			self::run_global_check_async( $order_id );
		}
	}

	/**
	 * Get decision mode: light | moderate | strict
	 */
	private static function get_decision_mode() : string {
		$mode = get_option( 'wc_blacklist_global_blacklist_decision_mode', 'light' );
		$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : 'light';

		if ( ! in_array( $mode, [ 'light', 'moderate', 'strict' ], true ) ) {
			$mode = 'light';
		}

		return $mode;
	}

	private static function is_global_check_complete( WC_Order $order ) : bool {
		$status = (string) $order->get_meta( self::META_CHECK_STATUS, true );
		if ( in_array( $status, [ 'success', 'skipped_rate_limit' ], true ) ) {
			return true;
		}

		if ( $order->get_meta( self::META_CHECKED, true ) ) {
			$legacy_decision = (string) $order->get_meta( self::META_DECISION, true );
			return '' !== $legacy_decision && 'check_failed' !== $legacy_decision;
		}

		return false;
	}

	private static function has_recent_pending_check( WC_Order $order ) : bool {
		$status     = (string) $order->get_meta( self::META_CHECK_STATUS, true );
		$started_at = (int) $order->get_meta( self::META_CHECK_STARTED_AT, true );

		return 'pending' === $status && $started_at > ( time() - 5 * MINUTE_IN_SECONDS );
	}

	private static function begin_global_check_attempt( WC_Order $order ) : int {
		$attempts = absint( $order->get_meta( self::META_CHECK_ATTEMPTS, true ) );
		$attempts++;

		$order->update_meta_data( self::META_CHECK_STATUS, 'pending' );
		$order->update_meta_data( self::META_CHECK_ATTEMPTS, $attempts );
		$order->update_meta_data( self::META_CHECK_STARTED_AT, time() );
		$order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT );
		$order->delete_meta_data( self::META_CHECK_LAST_ERROR );
		$order->delete_meta_data( self::META_CHECK_LAST_HTTP_CODE );
		$order->delete_meta_data( self::META_CHECKED );
		$order->save();

		return $attempts;
	}

	private static function get_check_error_message( array $resp ) : string {
		if ( ! empty( $resp['err'] ) ) {
			return (string) $resp['err'];
		}

		$code = isset( $resp['code'] ) ? (int) $resp['code'] : 0;
		if ( $code > 0 ) {
			return 'http_' . $code;
		}

		return 'unknown_error';
	}

	private static function is_retryable_check_response( array $resp ) : bool {
		$code = isset( $resp['code'] ) ? (int) $resp['code'] : 0;

		if ( 0 === $code ) {
			return true;
		}

		return 408 === $code || 425 === $code || 429 === $code || $code >= 500;
	}

	private static function get_retry_delay_seconds( int $attempt, array $resp = [] ) : int {
		$code        = isset( $resp['code'] ) ? (int) $resp['code'] : 0;
		$error_code  = isset( $resp['err'] ) ? sanitize_key( (string) $resp['err'] ) : '';
		$retry_after = isset( $resp['retry_after'] ) ? max( 0, (int) $resp['retry_after'] ) : 0;

		if ( 429 === $code && 'rate_limited' === $error_code && $retry_after > 0 ) {
			return min( HOUR_IN_SECONDS, max( 5, $retry_after ) );
		}

		$delays = [
			1 => 5 * MINUTE_IN_SECONDS,
			2 => 15 * MINUTE_IN_SECONDS,
		];

		return $delays[ $attempt ] ?? HOUR_IN_SECONDS;
	}

	private static function schedule_global_check_retry( int $order_id, int $delay ) : bool {
		if ( $order_id <= 0 || $delay <= 0 ) {
			return false;
		}

		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$existing = as_next_scheduled_action(
				'yogb_gbl_run_check_async',
				[ 'order_id' => $order_id ],
				'yogb-global-blacklist'
			);
			if ( $existing ) {
				return true;
			}
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + $delay,
				'yogb_gbl_run_check_async',
				[ 'order_id' => $order_id ],
				'yogb-global-blacklist'
			);
			return true;
		}

		if ( ! wp_next_scheduled( 'yogb_gbl_run_check_async', [ $order_id ] ) ) {
			return (bool) wp_schedule_single_event( time() + $delay, 'yogb_gbl_run_check_async', [ $order_id ] );
		}

		return true;
	}

	private static function normalize_strict_cache_payload( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::normalize_strict_cache_payload( $child );
		}

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}

		return $value;
	}

	private static function get_strict_cache_key( WC_Order $order ) : string {
		if ( ! class_exists( 'YOGB_BM_Report' ) || ! class_exists( 'YOGB_BM_Check' ) ) {
			return '';
		}

		$payload = YOGB_BM_Report::build_check_payload_from_order( $order );

		if ( empty( $payload['identities'] ) || ! is_array( $payload['identities'] ) ) {
			return '';
		}

		$cache_material = array(
			'version'          => 2,
			'site'             => home_url( '/' ),
			'mode'             => self::get_decision_mode(),
			'tier'             => YOGB_BM_Check::get_tier(),
			'checkout_attempt' => self::get_strict_checkout_attempt_cache_material( $order ),
			'payload'          => self::normalize_strict_cache_payload( $payload ),
		);

		return 'yogb_gbl_strict_' . hash( 'sha256', wp_json_encode( $cache_material ) );
	}

	private static function get_strict_checkout_attempt_cache_material( WC_Order $order ) : array {
		return array(
			'cart_hash'          => self::strict_cart_hash(),
			'wc_session_hash'    => self::strict_hash_value( self::strict_wc_session_fingerprint() ),
			'billing_email_hash' => self::strict_hash_value( strtolower( sanitize_email( (string) $order->get_billing_email() ) ) ),
			'billing_phone_hash' => self::strict_hash_value( self::strict_normalize_phone_for_cache( (string) $order->get_billing_phone() ) ),
			'ip_hash'            => self::strict_hash_value( self::strict_client_ip() ),
			'user_agent_hash'    => self::strict_hash_value( self::strict_user_agent() ),
		);
	}

	private static function strict_cart_hash() : string {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			return (string) WC()->cart->get_cart_hash();
		}

		return '';
	}

	private static function strict_wc_session_fingerprint() : string {
		foreach ( $_COOKIE as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'wp_woocommerce_session_' ) ) {
				$parts = explode( '||', (string) $value );
				return sanitize_text_field( (string) ( $parts[0] ?? $value ) );
			}
		}

		if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			return sanitize_text_field( (string) WC()->session->get_customer_id() );
		}

		return '';
	}

	private static function strict_normalize_phone_for_cache( string $phone ) : string {
		$digits = preg_replace( '/\D+/', '', $phone );

		return is_string( $digits ) ? $digits : '';
	}

	private static function strict_client_ip() : string {
		if ( function_exists( 'get_real_customer_ip' ) ) {
			return (string) get_real_customer_ip();
		}

		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $key ) {
			$value = isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';

			if ( '' === $value ) {
				continue;
			}

			if ( 'HTTP_X_FORWARDED_FOR' === $key ) {
				$parts = explode( ',', $value );
				$value = trim( (string) $parts[0] );
			}

			return $value;
		}

		return '';
	}

	private static function strict_user_agent() : string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	private static function strict_hash_value( string $value ) : string {
		if ( '' === $value ) {
			return '';
		}

		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}

	private static function get_strict_cache_ttl() : int {
		$ttl = (int) apply_filters( 'yogb_gbl_strict_cache_ttl', 5 * MINUTE_IN_SECONDS );

		return max( 0, $ttl );
	}

	private static function get_cached_strict_check( WC_Order $order ) : ?array {
		$key = self::get_strict_cache_key( $order );

		if ( '' === $key ) {
			return null;
		}

		$cached = get_transient( $key );

		return is_array( $cached ) ? $cached : null;
	}

	private static function cache_strict_check( WC_Order $order, array $resp ) : void {
		if ( empty( $resp['ok'] ) ) {
			return;
		}

		$ttl = self::get_strict_cache_ttl();
		if ( $ttl <= 0 ) {
			return;
		}

		$key = self::get_strict_cache_key( $order );
		if ( '' === $key ) {
			return;
		}

		set_transient( $key, $resp, $ttl );
		$transfer_key = self::get_strict_transfer_cache_key( $order );
		if ( '' !== $transfer_key ) {
			set_transient( $transfer_key, $resp, $ttl );
		}
	}

	private static function get_strict_transfer_cache_key( WC_Order $order ) : string {
		if ( ! class_exists( 'YOGB_BM_Report' ) || ! class_exists( 'YOGB_BM_Check' ) ) {
			return '';
		}
		$payload = YOGB_BM_Report::build_check_payload_from_order( $order );
		if ( empty( $payload['identities'] ) ) {
			return '';
		}
		$material = [
			'version' => 1,
			'site'    => home_url( '/' ),
			'tier'    => YOGB_BM_Check::get_tier(),
			'payload' => self::normalize_strict_cache_payload( $payload ),
		];
		return 'yogb_gbl_strict_transfer_' . hash( 'sha256', wp_json_encode( $material ) );
	}

	private static function get_transferred_strict_check( WC_Order $order ) : ?array {
		$key = self::get_strict_transfer_cache_key( $order );
		if ( '' === $key ) {
			return null;
		}
		$value = get_transient( $key );
		return is_array( $value ) ? $value : null;
	}

	private static function check_order_for_strict_mode( WC_Order $order ) : array {
		$cached = self::get_cached_strict_check( $order );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$resp = YOGB_BM_Check::check_order( $order );

		self::cache_strict_check( $order, $resp );

		return $resp;
	}

	/**
	 * STRICT MODE (classic checkout):
	 * Validate before the order is created.
	 *
	 * @param array              $fields
	 * @param WP_Error|WC_Errors $errors
	 */
	public static function validate_classic_strict( $fields, $errors ) : void {
		if ( ! class_exists( 'YOGB_BM_Check' ) ) {
			return;
		}

		$enabled = (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 );
		if ( 1 !== $enabled || 'strict' !== self::get_decision_mode() ) {
			return;
		}

		$order = self::build_ephemeral_order_from_fields( (array) $fields );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$resp     = self::check_order_for_strict_mode( $order );
		$decision = YOGB_BM_Check::get_overall_decision( $resp );

		if ( 'block' === $decision ) {
			$message = __(
				'Your order cannot be placed at this time due to our fraud protection rules. Please contact the store owner for assistance.',
				'wc-blacklist-manager'
			);

			if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
				$errors->add( 'yogb_gbl_blocked', $message );
			} else {
				wc_add_notice( $message, 'error' );
			}

			self::record_global_decision_activity( $order, $decision, $resp, 'classic_strict_checkout', 'woo_checkout' );
		}
	}

	/**
	 * Build a temporary WC_Order object from checkout fields for strict validation.
	 */
	private static function build_ephemeral_order_from_fields( array $fields ) : ?WC_Order {
		if ( ! class_exists( 'WC_Order' ) ) {
			return null;
		}

		$order = new WC_Order();

		if ( isset( $fields['billing_first_name'] ) ) {
			$order->set_billing_first_name( wc_clean( $fields['billing_first_name'] ) );
		}
		if ( isset( $fields['billing_last_name'] ) ) {
			$order->set_billing_last_name( wc_clean( $fields['billing_last_name'] ) );
		}
		if ( isset( $fields['billing_email'] ) ) {
			$order->set_billing_email( sanitize_email( $fields['billing_email'] ) );
		}
		if ( isset( $fields['billing_phone'] ) ) {
			$order->set_billing_phone( wc_clean( $fields['billing_phone'] ) );
		}
		if ( isset( $fields['billing_address_1'] ) ) {
			$order->set_billing_address_1( wc_clean( $fields['billing_address_1'] ) );
		}
		if ( isset( $fields['billing_address_2'] ) ) {
			$order->set_billing_address_2( wc_clean( $fields['billing_address_2'] ) );
		}
		if ( isset( $fields['billing_city'] ) ) {
			$order->set_billing_city( wc_clean( $fields['billing_city'] ) );
		}
		if ( isset( $fields['billing_state'] ) ) {
			$order->set_billing_state( wc_clean( $fields['billing_state'] ) );
		}
		if ( isset( $fields['billing_postcode'] ) ) {
			$order->set_billing_postcode( wc_clean( $fields['billing_postcode'] ) );
		}
		if ( isset( $fields['billing_country'] ) ) {
			$order->set_billing_country( wc_clean( $fields['billing_country'] ) );
		}

		if ( isset( $fields['ship_to_different_address'] ) && $fields['ship_to_different_address'] ) {
			if ( isset( $fields['shipping_first_name'] ) ) {
				$order->set_shipping_first_name( wc_clean( $fields['shipping_first_name'] ) );
			}
			if ( isset( $fields['shipping_last_name'] ) ) {
				$order->set_shipping_last_name( wc_clean( $fields['shipping_last_name'] ) );
			}
			if ( isset( $fields['shipping_address_1'] ) ) {
				$order->set_shipping_address_1( wc_clean( $fields['shipping_address_1'] ) );
			}
			if ( isset( $fields['shipping_address_2'] ) ) {
				$order->set_shipping_address_2( wc_clean( $fields['shipping_address_2'] ) );
			}
			if ( isset( $fields['shipping_city'] ) ) {
				$order->set_shipping_city( wc_clean( $fields['shipping_city'] ) );
			}
			if ( isset( $fields['shipping_state'] ) ) {
				$order->set_shipping_state( wc_clean( $fields['shipping_state'] ) );
			}
			if ( isset( $fields['shipping_postcode'] ) ) {
				$order->set_shipping_postcode( wc_clean( $fields['shipping_postcode'] ) );
			}
			if ( isset( $fields['shipping_country'] ) ) {
				$order->set_shipping_country( wc_clean( $fields['shipping_country'] ) );
			}
		}

		if ( class_exists( 'WC_Geolocation' ) ) {
			$order->set_customer_ip_address( WC_Geolocation::get_ip_address() );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$order->set_customer_ip_address( wc_clean( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) );
		}

		return $order;
	}

	/**
	 * STRICT MODE (blocks checkout):
	 * Run the check during Store API checkout and throw an exception if "block".
	 *
	 * @param WC_Order $order
	 *
	 * @throws Exception When order is blocked.
	 */
	public static function validate_store_api_strict( $order ) : void {
		if ( ! class_exists( 'YOGB_BM_Check' ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$enabled = (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 );
		if ( 1 !== $enabled || 'strict' !== self::get_decision_mode() ) {
			return;
		}

		$resp     = self::check_order_for_strict_mode( $order );
		$decision = YOGB_BM_Check::get_overall_decision( $resp );

		if ( 'block' === $decision ) {
			$message = __(
				'Your order cannot be placed at this time due to our fraud protection rules. Please contact the store owner for assistance.',
				'wc-blacklist-manager'
			);
			self::record_global_decision_activity( $order, $decision, $resp, 'store_api_strict_checkout', 'woo_store_api_checkout' );
			throw new Exception( esc_html( $message ) );
		}
	}

	/**
	 * Background worker: actually run the Global Blacklist check for an order.
	 *
	 * @param int $order_id
	 */
	public static function run_global_check_async( int $order_id ) : void {
		if ( ! class_exists( 'YOGB_BM_Check' ) ) {
			return;
		}

		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( self::is_global_check_complete( $order ) || self::has_recent_pending_check( $order ) ) {
			return;
		}

		$attempt = self::begin_global_check_attempt( $order );
		$mode    = self::get_decision_mode();

		$resp = 'strict' === $mode ? self::get_transferred_strict_check( $order ) : null;
		if ( ! is_array( $resp ) ) {
			$resp = YOGB_BM_Check::check_order( $order );
		}
		$tier      = isset( $resp['tier'] ) ? (string) $resp['tier'] : '';
		$http_code = isset( $resp['code'] ) ? (int) $resp['code'] : 0;
		$error_code = isset( $resp['err'] ) ? sanitize_key( (string) $resp['err'] ) : '';

		if ( empty( $resp['ok'] ) && 429 === $http_code && in_array( $error_code, [ 'plan_quota_exceeded', 'rate_month' ], true ) ) {
			$order->add_order_note(
				sprintf(
					__( 'Global Blacklist Decisions check skipped: monthly limit exceeded for tier "%1$s" (HTTP %2$d).', 'wc-blacklist-manager' ),
					$tier ?: 'free',
					$http_code
				)
			);
			$order->update_meta_data( self::META_DECISION, 'skipped_rate_limit' );
			$order->update_meta_data( self::META_TIER, $tier );
			$order->update_meta_data( self::META_CHECK_STATUS, 'skipped_rate_limit' );
			$order->update_meta_data( self::META_CHECKED, 1 );
			$order->update_meta_data( self::META_CHECK_LAST_HTTP_CODE, $http_code );
			$order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT );
			$order->delete_meta_data( self::META_CHECK_LAST_ERROR );

			$tier_safe = $tier ?: 'free';

			YOGB_BM_Check::mark_monthly_limit_reached( $tier_safe, 0, 'order_skip' );

			$order->save();

			do_action( 'yogb_after_gbl_check', $order->get_id(), 'yogb_gbl_run_check_async' );
			return;
		}

		if ( empty( $resp['ok'] ) ) {
			$error_message = self::get_check_error_message( $resp );
			$max_attempts  = max( 1, (int) apply_filters( 'yogb_gbl_check_max_attempts', 3, $order, $resp ) );
			$will_retry    = $attempt < $max_attempts && self::is_retryable_check_response( $resp );
			$retry_delay   = $will_retry ? self::get_retry_delay_seconds( $attempt, $resp ) : 0;
			$retry_at      = $will_retry ? time() + $retry_delay : 0;

			$order->update_meta_data( self::META_DECISION, 'check_failed' );
			$order->update_meta_data( self::META_TIER, $tier );
			$order->update_meta_data( self::META_CHECK_STATUS, 'failed' );
			$order->update_meta_data( self::META_CHECK_LAST_ERROR, $error_message );
			$order->update_meta_data( self::META_CHECK_LAST_HTTP_CODE, $http_code );
			$order->delete_meta_data( self::META_CHECKED );

			if ( $will_retry ) {
				$order->update_meta_data( self::META_CHECK_NEXT_RETRY_AT, $retry_at );
				self::schedule_global_check_retry( $order->get_id(), $retry_delay );
				$order->add_order_note(
					sprintf(
						__( 'Global Blacklist Decisions check could not be completed (HTTP %1$d, attempt %2$d/%3$d, error: %4$s). Retrying in %5$d minutes. Order allowed until the check completes.', 'wc-blacklist-manager' ),
						$http_code,
						$attempt,
						$max_attempts,
						$error_message,
						max( 1, (int) ceil( $retry_delay / MINUTE_IN_SECONDS ) )
					)
				);
			} else {
				$order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT );
				$order->add_order_note(
					sprintf(
						__( 'Global Blacklist Decisions check could not be completed (HTTP %1$d, attempt %2$d/%3$d, error: %4$s). No automatic retries remain. Order allowed by default.', 'wc-blacklist-manager' ),
						$http_code,
						$attempt,
						$max_attempts,
						$error_message
					)
				);
			}

			$order->save();

			do_action( 'yogb_after_gbl_check', $order->get_id(), 'yogb_gbl_run_check_async' );
			return;
		}

		$snapshot  = YOGB_BM_Check::get_decision_snapshot( $resp );
		$decision  = (string) $snapshot['overall'];
		$tier      = '' !== (string) $snapshot['tier'] ? (string) $snapshot['tier'] : $tier;
		$summary   = (string) $snapshot['summary'];
		$reason    = (string) $snapshot['reason_code'];

		$order->update_meta_data( self::META_DECISION, $decision );
		$order->update_meta_data( self::META_TIER, $tier );
		$order->update_meta_data( self::META_DECISION_SUMMARY, $summary );
		$order->update_meta_data( self::META_DECISION_REASON_CODE, $reason );
		$order->update_meta_data( self::META_RESPONSE_SCHEMA, (string) $snapshot['schema'] );
		$order->update_meta_data( self::META_DETAIL_AVAILABLE, ! empty( $snapshot['detail_available'] ) ? 1 : 0 );
		$order->update_meta_data( self::META_STORAGE_PROFILE, 'compact_v1' );

		$decision_ref = (string) $snapshot['decision_ref'];
		if ( '' !== $decision_ref ) {
			$order->update_meta_data( self::META_DECISION_REF, $decision_ref );
			$order->update_meta_data( self::META_DECISION_AT, time() );
		} else {
			$order->delete_meta_data( self::META_DECISION_REF );
			$order->delete_meta_data( self::META_DECISION_AT );
		}

		// Do not persist the full server response or per-identity scoring
		// payloads on new checks. The server remains the detailed source of
		// truth; these keys are deleted on recheck to avoid stale diagnostics.
		self::delete_legacy_verbose_meta( $order );

		self::apply_decision_to_order(
			$order,
			$decision,
			$tier,
			$mode,
			$summary,
			$reason
		);

		if ( in_array( $decision, [ 'block', 'challenge' ], true ) ) {
			self::record_global_decision_activity( $order, $decision, $resp, 'async_order_check', 'woo_order_' . (int) $order->get_id() );
		}

		$order->update_meta_data( self::META_CHECK_STATUS, 'success' );
		$order->update_meta_data( self::META_CHECKED, 1 );
		$order->delete_meta_data( self::META_CHECK_LAST_ERROR );
		$order->delete_meta_data( self::META_CHECK_LAST_HTTP_CODE );
		$order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT );

		$order->save();

		do_action( 'yogb_after_gbl_check', $order->get_id(), 'yogb_gbl_run_check_async' );
	}

	public static function handle_manual_order_check() : void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		if ( $order_id <= 0 ) {
			wp_die( esc_html__( 'Invalid order.', 'wc-blacklist-manager' ) );
		}

		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_die( esc_html__( 'You do not have permission to recheck this order.', 'wc-blacklist-manager' ) );
		}

		check_admin_referer( 'yogb_gbl_manual_order_check_' . $order_id );

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'wc-blacklist-manager' ) );
		}

		self::reset_order_check_state( $order );
		$order->save();

		self::run_global_check_async( $order_id );

		wp_safe_redirect(
			add_query_arg(
				[
					'post'   => $order_id,
					'action' => 'edit',
					'yogb_rechecked' => 1,
				],
				admin_url( 'post.php' )
			)
		);
		exit;
	}

	private static function reset_order_check_state( WC_Order $order ) : void {
		// Allow recheck even if a previous async attempt marked it checked or failed.
		$order->delete_meta_data( self::META_CHECKED );
		$order->delete_meta_data( self::META_CHECK_STATUS );
		$order->delete_meta_data( self::META_CHECK_ATTEMPTS );
		$order->delete_meta_data( self::META_CHECK_STARTED_AT );
		$order->delete_meta_data( self::META_CHECK_NEXT_RETRY_AT );
		$order->delete_meta_data( self::META_CHECK_LAST_ERROR );
		$order->delete_meta_data( self::META_CHECK_LAST_HTTP_CODE );
		$transfer_key = self::get_strict_transfer_cache_key( $order );
		if ( '' !== $transfer_key ) {
			delete_transient( $transfer_key );
		}
	}

	public static function register_bulk_recheck_action( array $actions ) : array {
		$actions['yogb_gbl_recheck_skipped'] = __( 'Recheck skipped Global Blacklist orders', 'wc-blacklist-manager' );
		return $actions;
	}

	public static function handle_bulk_recheck_action( string $redirect_to, string $action, array $order_ids ) : string {
		if ( 'yogb_gbl_recheck_skipped' !== $action ) {
			return $redirect_to;
		}

		$queued = 0;
		foreach ( array_unique( array_map( 'absint', $order_ids ) ) as $order_id ) {
			if ( $order_id <= 0 || ! current_user_can( 'edit_shop_order', $order_id ) ) {
				continue;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$decision = strtolower( trim( (string) $order->get_meta( self::META_DECISION, true ) ) );
			if ( 'skipped_rate_limit' !== $decision || ! $order->has_status( [ 'pending', 'processing', 'on-hold', 'failed' ] ) ) {
				continue;
			}

			self::reset_order_check_state( $order );
			$order->save();
			self::enqueue_global_check_async( $order );
			$queued++;
		}

		return add_query_arg( 'yogb_gbl_bulk_rechecked', $queued, $redirect_to );
	}

	public static function show_bulk_recheck_notice() : void {
		if ( ! isset( $_GET['yogb_gbl_bulk_rechecked'] ) ) {
			return;
		}

		$count = absint( wp_unslash( $_GET['yogb_gbl_bulk_rechecked'] ) );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of orders queued for Global Blacklist recheck. */
					_n( '%d skipped order queued for recheck.', '%d skipped orders queued for recheck.', $count, 'wc-blacklist-manager' ),
					$count
				)
			)
		);
	}

	/**
	 * Parse the raw server response and extract identity details.
	 *
	 * @param array $resp Response from YOGB_BM_Check::check_order()
	 * @return array{
	 *     signal_summaries: string[],
	 *     reason_summaries: string[],
	 *     report_summaries: string[]
	 * }
	 */
	private static function extract_identity_details_from_response( array $resp ) : array {
		$signal_summaries       = [];
		$reason_summaries       = [];
		$report_summaries       = [];
		$matched_identity_nodes = 0;

		$primary_meta = [
			'type'                   => '',
			'risk_level'             => '',
			'last_reported'          => '',
			'match_mode'             => '',
			'matched_variant'        => '',
			'matched_identity_count' => 0,
			'effective_score'        => 0.0,
		];

		$payload = $resp['json'] ?? null;

		if ( ! is_array( $payload ) && ! empty( $resp['body'] ) && is_string( $resp['body'] ) ) {
			$decoded = json_decode( $resp['body'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}

		if ( ! is_array( $payload ) || empty( $payload['results'] ) || ! is_array( $payload['results'] ) ) {
			return [
				'signal_summaries'       => $signal_summaries,
				'reason_summaries'       => $reason_summaries,
				'report_summaries'       => $report_summaries,
				'matched_identity_nodes' => 0,
				'primary_meta'           => $primary_meta,
			];
		}

		foreach ( $payload['results'] as $res ) {
			if ( ! is_array( $res ) ) {
				continue;
			}

			$type            = isset( $res['type'] ) ? (string) $res['type'] : 'unknown';
			$match_mode      = isset( $res['match_mode'] ) ? (string) $res['match_mode'] : 'none';
			$matched_variant = isset( $res['matched_variant'] ) ? (string) $res['matched_variant'] : '';
			$matched_count   = isset( $res['matched_identity_count'] ) ? (int) $res['matched_identity_count'] : 0;

			$aggregate = ( isset( $res['aggregate'] ) && is_array( $res['aggregate'] ) ) ? $res['aggregate'] : [];
			$matches   = ( isset( $res['matches'] ) && is_array( $res['matches'] ) ) ? $res['matches'] : [];

			$report_count           = isset( $aggregate['report_count'] ) ? (int) $aggregate['report_count'] : 0;
			$direct_score           = isset( $aggregate['direct_score'] ) ? (float) $aggregate['direct_score'] : 0.0;
			$linked_boost           = isset( $aggregate['linked_boost'] ) ? (float) $aggregate['linked_boost'] : 0.0;
			$effective_score        = isset( $aggregate['score'] ) ? (float) $aggregate['score'] : 0.0;
			$linked_neighbors_count = isset( $aggregate['linked_neighbors_count'] ) ? (int) $aggregate['linked_neighbors_count'] : 0;
			$risk_level             = isset( $aggregate['risk_level'] ) ? (string) $aggregate['risk_level'] : 'low';
			$last_reported          = isset( $aggregate['last_reported'] ) ? (string) $aggregate['last_reported'] : '';

			$found = ! empty( $matches ) || $report_count > 0 || $effective_score > 0 || $direct_score > 0 || $linked_boost > 0;

			if ( $found ) {
				$matched_identity_nodes += max( 1, $matched_count );

				$type_label = self::format_identity_type_label_static( $type );

				$summary_parts = [];

				if ( 'none' !== $match_mode && '' !== $match_mode ) {
					$summary_parts[] = sprintf(
						__( '%1$s matched through %2$s.', 'wc-blacklist-manager' ),
						$type_label,
						strtolower( self::format_match_mode_label_static( $match_mode ) )
					);
				} else {
					$summary_parts[] = sprintf(
						__( '%s matched.', 'wc-blacklist-manager' ),
						$type_label
					);
				}

				$summary_parts[] = sprintf(
					__( 'Risk: %s.', 'wc-blacklist-manager' ),
					strtolower( $risk_level )
				);

				if ( $report_count > 0 ) {
					$summary_parts[] = sprintf(
						__( 'Reports: %d.', 'wc-blacklist-manager' ),
						$report_count
					);
				}

				if ( $matched_count > 0 ) {
					$summary_parts[] = sprintf(
						__( 'Related records: %d.', 'wc-blacklist-manager' ),
						$matched_count
					);
				}

				if ( '' !== $matched_variant && 'submitted' !== strtolower( $matched_variant ) ) {
					$summary_parts[] = sprintf(
						__( 'Matched detail: %s.', 'wc-blacklist-manager' ),
						self::format_matched_variant_label_static( $matched_variant )
					);
				}

				if ( '' !== $last_reported ) {
					$summary_parts[] = sprintf(
						__( 'Last reported: %s.', 'wc-blacklist-manager' ),
						$last_reported
					);
				}

				// Keep score details, but soften wording.
				if ( $effective_score > 0 || $direct_score > 0 || $linked_boost > 0 ) {
					$summary_parts[] = sprintf(
						__( 'Scores — direct %1$s, related +%2$s, effective %3$s.', 'wc-blacklist-manager' ),
						number_format_i18n( $direct_score, 2 ),
						number_format_i18n( $linked_boost, 2 ),
						number_format_i18n( $effective_score, 2 )
					);
				}

				if ( $linked_neighbors_count > 0 ) {
					$summary_parts[] = sprintf(
						__( 'Related neighbors: %d.', 'wc-blacklist-manager' ),
						$linked_neighbors_count
					);
				}

				$signal_summaries[] = implode( ' ', $summary_parts );
			}

			if ( $effective_score > (float) $primary_meta['effective_score'] ) {
				$primary_meta = [
					'type'                   => $type,
					'risk_level'             => $risk_level,
					'last_reported'          => $last_reported,
					'match_mode'             => $match_mode,
					'matched_variant'        => $matched_variant,
					'matched_identity_count' => $matched_count,
					'effective_score'        => $effective_score,
				];
			}

			$chunks = [];

			if ( ! empty( $res['reason_stats'] ) && is_array( $res['reason_stats'] ) ) {
				foreach ( $res['reason_stats'] as $code => $info ) {
					if ( ! is_array( $info ) ) {
						continue;
					}

					$label = isset( $info['label'] ) && is_string( $info['label'] )
						? $info['label']
						: ucfirst( str_replace( '_', ' ', (string) $code ) );

					$total = isset( $info['total'] ) ? (int) $info['total'] : 0;

					$chunks[] = sprintf(
						__( '%1$s — %2$d', 'wc-blacklist-manager' ),
						$label,
						$total
					);
				}
			} elseif ( ! empty( $res['reasons_all_time'] ) && is_array( $res['reasons_all_time'] ) ) {
				foreach ( $res['reasons_all_time'] as $item ) {
					if ( ! is_string( $item ) ) {
						continue;
					}
					$chunks[] = $item;
				}
			}

			if ( ! empty( $chunks ) ) {
				$type_label = self::format_identity_type_label_static( $type );

				$prefix = sprintf(
					__( 'Past reasons for %1$s: %2$s.', 'wc-blacklist-manager' ),
					strtolower( $type_label ),
					implode( ', ', $chunks )
				);

				$reason_meta = [];

				if ( 'none' !== $match_mode && '' !== $match_mode ) {
					$reason_meta[] = self::format_match_mode_label_static( $match_mode );
				}

				if ( '' !== $matched_variant && 'submitted' !== strtolower( $matched_variant ) ) {
					$reason_meta[] = self::format_matched_variant_label_static( $matched_variant );
				}

				if ( ! empty( $reason_meta ) ) {
					$prefix .= ' ' . sprintf(
						__( 'Match detail: %s.', 'wc-blacklist-manager' ),
						implode( ' · ', $reason_meta )
					);
				}

				$reason_summaries[] = $prefix;
			}

			if ( ! empty( $res['reports'] ) && is_array( $res['reports'] ) ) {
				foreach ( $res['reports'] as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$reason_label = '';
					if ( ! empty( $row['reason'] ) ) {
						$reason_label = (string) $row['reason'];
					} elseif ( ! empty( $row['reason_code'] ) ) {
						$reason_label = ucfirst( str_replace( '_', ' ', (string) $row['reason_code'] ) );
					}

					$reporter = isset( $row['reporter'] ) ? (string) $row['reporter'] : '';
					$status   = isset( $row['status'] ) ? (string) $row['status'] : 'active';
					$created  = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';

					$type_label = self::format_identity_type_label_static( $type );

					$parts = [
						sprintf(
							__( '%1$s report: %2$s.', 'wc-blacklist-manager' ),
							$type_label,
							$reason_label
						),
					];

					if ( '' !== $reporter ) {
						$parts[] = sprintf(
							__( 'Source: %s.', 'wc-blacklist-manager' ),
							$reporter
						);
					}

					if ( '' !== $status ) {
						$parts[] = sprintf(
							__( 'Status: %s.', 'wc-blacklist-manager' ),
							$status
						);
					}

					if ( '' !== $created ) {
						$parts[] = sprintf(
							__( 'Date: %s.', 'wc-blacklist-manager' ),
							$created
						);
					}

					$report_meta = [];

					if ( 'none' !== $match_mode && '' !== $match_mode ) {
						$report_meta[] = self::format_match_mode_label_static( $match_mode );
					}

					if ( '' !== $matched_variant && 'submitted' !== strtolower( $matched_variant ) ) {
						$report_meta[] = self::format_matched_variant_label_static( $matched_variant );
					}

					if ( ! empty( $report_meta ) ) {
						$parts[] = sprintf(
							__( 'Match detail: %s.', 'wc-blacklist-manager' ),
							implode( ' / ', $report_meta )
						);
					}

					$report_summaries[] = implode( ' ', $parts );
				}
			}
		}

		unset( $primary_meta['effective_score'] );

		return [
			'signal_summaries'       => $signal_summaries,
			'reason_summaries'       => $reason_summaries,
			'report_summaries'       => $report_summaries,
			'matched_identity_nodes' => $matched_identity_nodes,
			'primary_meta'           => $primary_meta,
		];
	}

	private static function record_global_decision_activity(
		WC_Order $order,
		string $decision,
		array $resp,
		string $context,
		string $source
	) : void {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		$decision       = sanitize_key( $decision );
		$context        = sanitize_key( $context );
		$source         = sanitize_key( $source );
		$tier           = isset( $resp['tier'] ) ? sanitize_key( (string) $resp['tier'] ) : '';
		$mode           = self::get_decision_mode();
		$snapshot       = class_exists( 'YOGB_BM_Check' )
			? YOGB_BM_Check::get_decision_snapshot( $resp )
			: [];
		$action         = 'challenge' === $decision ? 'challenge' : 'block';

		$view = array(
			'schema'         => 'yogb_gbl_decision_v2',
			'context'        => $context,
			'mode'           => $mode,
			'decision'       => $decision,
			'tier'           => $tier ?: 'free',
			'decision_ref'   => sanitize_text_field( (string) ( $snapshot['decision_ref'] ?? '' ) ),
			'reason_code'    => sanitize_key( (string) ( $snapshot['reason_code'] ?? '' ) ),
			'summary'        => sanitize_text_field( (string) ( $snapshot['summary'] ?? '' ) ),
			'response_schema'=> sanitize_key( (string) ( $snapshot['schema'] ?? '' ) ),
		);

		$order_id = (int) $order->get_id();
		if ( $order_id > 0 ) {
			$view['order_id'] = $order_id;
		}

		$wpdb->insert(
			$wpdb->prefix . 'wc_blacklist_detection_log',
			array(
				'timestamp' => current_time( 'mysql' ),
				'type'      => 'bot',
				'source'    => $source,
				'action'    => $action,
				'details'   => 'global_blacklist_decision:' . $decision . ' context:' . $context,
				'view'      => wp_json_encode( $view ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Map global decision to WooCommerce order status / notes.
	 *
	 * Decision: 'allow' | 'challenge' | 'block'
	 * Mode:     'light' | 'moderate' | 'strict'
	 *
	 * @param WC_Order $order
	 * @param string   $decision
	 * @param string   $tier
	 * @param string   $mode
	 * @param string   $summary           Compact server explanation.
	 * @param string   $reason_code       Compact machine-readable reason.
	 */
	private static function apply_decision_to_order(
		WC_Order $order,
		string $decision,
		string $tier,
		string $mode,
		string $summary = '',
		string $reason_code = ''
	) : void {
		$note_lines   = [];
		$note_lines[] = sprintf(
			/* translators: 1: decision, 2: tier */
			__( 'Global Blacklist decision: %1$s (tier: %2$s).', 'wc-blacklist-manager' ),
			$decision,
			$tier ?: 'free'
		);
		if ( '' !== $summary ) {
			$note_lines[] = $summary;
		} elseif ( '' !== $reason_code ) {
			$note_lines[] = sprintf(
				__( 'Reason: %s.', 'wc-blacklist-manager' ),
				ucwords( str_replace( '_', ' ', $reason_code ) )
			);
		}

		$note = implode( "\n", $note_lines );

		if ( 'light' === $mode ) {
			$order->add_order_note( $note );
			return;
		}

		switch ( $decision ) {
			case 'block':
				if ( class_exists( 'YOGB_BM_Outcomes' ) ) {
					YOGB_BM_Outcomes::mark_system_status_change( (int) $order->get_id(), 'cancelled' );
				}
				$order->set_status(
					'cancelled',
					__( 'Order cancelled: blocked by Global Blacklist Decisions.', 'wc-blacklist-manager' )
				);
				$order->add_order_note( $note );
				$order->update_meta_data( '_yogb_gbl_blocked', '1' );
				break;

			case 'challenge':
				if ( $order->has_status( [ 'pending', 'processing' ] ) ) {
					if ( class_exists( 'YOGB_BM_Outcomes' ) ) {
						YOGB_BM_Outcomes::mark_system_status_change( (int) $order->get_id(), 'on-hold' );
					}
					$order->set_status(
						'on-hold',
						__( 'Order placed on hold: requires review by Global Blacklist Decisions.', 'wc-blacklist-manager' )
					);
				}
				$order->add_order_note( $note );
				$order->update_meta_data( '_yogb_gbl_challenged', '1' );
				break;

			case 'allow':
			default:
				$order->add_order_note( $note );
				break;
		}
	}

	private static function format_match_mode_label_static( string $mode ) : string {
		switch ( strtolower( $mode ) ) {
			case 'exact':
				return __( 'Exact match', 'wc-blacklist-manager' );

			case 'variant_core':
				return __( 'Main address match', 'wc-blacklist-manager' );

			case 'variant_premise':
				return __( 'Unit / apartment match', 'wc-blacklist-manager' );

			case 'linked':
				return __( 'Related match', 'wc-blacklist-manager' );

			case 'none':
			default:
				return __( 'No match', 'wc-blacklist-manager' );
		}
	}

	private static function format_matched_variant_label_static( string $variant ) : string {
		switch ( strtolower( $variant ) ) {
			case 'submitted':
				return __( 'Submitted details', 'wc-blacklist-manager' );

			case 'core':
				return __( 'Main address', 'wc-blacklist-manager' );

			case 'premise':
				return __( 'Unit / apartment', 'wc-blacklist-manager' );

			case 'full':
				return __( 'Full address', 'wc-blacklist-manager' );

			default:
				return '' !== $variant ? ucfirst( str_replace( '_', ' ', $variant ) ) : '';
		}
	}

	private static function format_identity_type_label_static( string $type ) : string {
		switch ( strtolower( $type ) ) {
			case 'email':
				return __( 'Email', 'wc-blacklist-manager' );

			case 'phone':
				return __( 'Phone', 'wc-blacklist-manager' );

			case 'ip':
				return __( 'IP address', 'wc-blacklist-manager' );

			case 'address':
				return __( 'Address', 'wc-blacklist-manager' );

			case 'domain':
				return __( 'Domain', 'wc-blacklist-manager' );

			default:
				return '' !== $type ? ucfirst( str_replace( '_', ' ', $type ) ) : __( 'Unknown', 'wc-blacklist-manager' );
			}
		}

	/**
	 * Meta written by versions before compact_v1.
	 *
	 * @return string[]
	 */
	public static function legacy_verbose_meta_keys() : array {
		return [
			self::META_REASONS,
			self::META_RAW,
			self::META_REASON_SUMMARIES,
			self::META_REPORT_SUMMARIES,
			self::META_SIGNAL_SUMMARIES,
			self::META_EFFECTIVE_SCORE,
			self::META_DIRECT_SCORE,
			self::META_LINKED_BOOST,
			self::META_LINKED_NEIGHBORS_COUNT,
			self::META_MATCHED_IDENTITIES,
			self::META_PRIMARY_SIGNAL_TYPE,
			self::META_PRIMARY_RISK_LEVEL,
			self::META_PRIMARY_LAST_REPORTED,
			self::META_MATCHED_IDENTITY_NODES,
			self::META_PRIMARY_MATCH_MODE,
			self::META_PRIMARY_MATCHED_VARIANT,
			self::META_PRIMARY_MATCHED_IDENTITY_COUNT,
		];
	}

	public static function delete_legacy_verbose_meta( WC_Order $order ) : void {
		foreach ( self::legacy_verbose_meta_keys() as $meta_key ) {
			$order->delete_meta_data( $meta_key );
		}
	}
}

YOGB_BM_Check_Orders::init();
