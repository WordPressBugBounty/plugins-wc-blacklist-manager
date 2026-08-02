<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YOGB_BM_Tier_Sync {

	public static function init() : void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_schedule' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );
		add_action( 'yogb_bm_tier_sync_hourly', [ __CLASS__, 'run' ] );
		add_action( 'yogb_bm_registration_completed', [ __CLASS__, 'run' ] );
	}

	public static function add_schedule( array $schedules ) : array {
		if ( ! isset( $schedules['yogb_hourly'] ) ) {
			$schedules['yogb_hourly'] = [
				'interval' => HOUR_IN_SECONDS,
				'display'  => __( 'Every Hour (YOGB)', 'wc-blacklist-manager' ),
			];
		}

		return $schedules;
	}

	public static function schedule() : void {
		if ( ! wp_next_scheduled( 'yogb_bm_tier_sync_hourly' ) ) {
			wp_schedule_event( time() + 300, 'yogb_hourly', 'yogb_bm_tier_sync_hourly' );
		}
	}

	public static function run() : void {
		$api_key     = (string) get_option( YOGB_BM_Report::OPT_KEY );
		$secret      = (string) get_option( YOGB_BM_Report::OPT_SECRET );
		$server_base = YOGB_BM_Report::server_base();
		$rest_route  = (string) YOGB_BM_Report::REST_ROUTE;

		if ( '' === $api_key || '' === $secret || '' === $server_base || '' === $rest_route ) {
			return;
		}

		$server_base = rtrim( $server_base, '/' );
		$rest_route  = '/' . ltrim( $rest_route, '/' );

		// Make sure REST base includes /wp-json.
		if ( false === strpos( $server_base, '/wp-json' ) ) {
			$server_base .= '/wp-json';
		}

		$ts  = (string) time();
		$sig = base64_encode(
			hash_hmac( 'sha256', $api_key . "\n" . $ts, $secret, true )
		);

		$url = add_query_arg(
			'yogb_cb',
			rawurlencode( $ts ),
			$server_base . $rest_route . '/client/tier'
		);

		$transport_host = wp_parse_url( $url, PHP_URL_HOST );
		$local_default = defined( 'YOGB_BM_ALLOW_NONPROD' ) && YOGB_BM_ALLOW_NONPROD && 'production' !== wp_get_environment_type() && is_string( $transport_host ) && (bool) preg_match( '/\.local$/i', $transport_host );
		$allow_unsafe_local = (bool) apply_filters( 'yogb_bm_allow_unsafe_local_url', $local_default, $url, $rest_route . '/client/tier' );
		$args = [
				'timeout' => 15,
				'headers' => [
					'X-API-Key'           => $api_key,
					'X-Request-Timestamp' => $ts,
					'X-Signature'         => $sig,
					'Cache-Control'       => 'no-cache',
					'Pragma'              => 'no-cache',
				],
				'reject_unsafe_urls' => ! $allow_unsafe_local,
			];
		$res = $allow_unsafe_local ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );

		if ( is_wp_error( $res ) ) {
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( 'transport_error', 'tier_pull' );
			return;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $res );
		$body_raw  = (string) wp_remote_retrieve_body( $res );

		if ( 200 !== $http_code ) {
			if ( 401 === $http_code && class_exists( 'YOGB_BM_Registrar' ) ) {
				YOGB_BM_Registrar::handle_auth_failure( 'tier_pull' );
			} elseif ( class_exists( 'YOGB_BM_Registrar' ) ) {
				YOGB_BM_Registrar::mark_connection_error( 'http_' . $http_code, 'tier_pull' );
			}
			return;
		}

		$body_raw = (string) wp_remote_retrieve_body( $res );
		$resp_ts  = (string) wp_remote_retrieve_header( $res, 'x-yogb-timestamp' );
		$resp_sig = (string) wp_remote_retrieve_header( $res, 'x-yogb-signature' );

		if ( '' === $body_raw || '' === $resp_ts || '' === $resp_sig ) {
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( 'missing_response_signature', 'tier_pull' );
			return;
		}

		$expected = base64_encode(
			hash_hmac( 'sha256', $body_raw . "\n" . $resp_ts, $secret, true )
		);

		if ( ! hash_equals( $expected, $resp_sig ) ) {
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( 'bad_response_signature', 'tier_pull' );
			return;
		}

		$payload = json_decode( $body_raw, true );
		if ( ! is_array( $payload ) ) {
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( 'invalid_json', 'tier_pull' );
			return;
		}
		if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_auth_success();
		update_option('yogb_bm_server_capabilities',array_values(array_filter(array_map('sanitize_key',(array)($payload['capabilities']??[])))),false);

		$result = YOGB_BM_Tier_Webhook::apply_tier_payload(
			$payload,
			[
				'source' => 'pull',
			]
		);
	}
}

YOGB_BM_Tier_Sync::init();
