<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YOGB_BM_Tier_Sync {

	public static function init() : void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_schedule' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );
		add_action( 'yogb_bm_tier_sync_hourly', [ __CLASS__, 'run' ] );
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
		$server_base = (string) YOGB_BM_Report::SERVER_BASE;
		$rest_route  = (string) YOGB_BM_Report::REST_ROUTE;

		if ( '' === $api_key || '' === $secret || '' === $server_base || '' === $rest_route ) {
			return;
		}

		$server_base = rtrim( $server_base, '/' );
		$rest_route  = '/' . ltrim( $rest_route, '/' );
		$ts          = (string) time();
		$sig         = base64_encode(
			hash_hmac( 'sha256', $api_key . "\n" . $ts, $secret, true )
		);

		$url = add_query_arg(
			[
				'api_key' => rawurlencode( $api_key ),
				'ts'      => rawurlencode( $ts ),
				'sig'     => rawurlencode( $sig ),
			],
			$server_base . $rest_route . '/client/tier'
		);

		$res = wp_safe_remote_get(
			$url,
			[
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $res ) ) {
			return;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $http_code ) {
			return;
		}

		$body_raw = (string) wp_remote_retrieve_body( $res );
		$resp_ts  = (string) wp_remote_retrieve_header( $res, 'x-yogb-timestamp' );
		$resp_sig = (string) wp_remote_retrieve_header( $res, 'x-yogb-signature' );

		if ( '' === $body_raw || '' === $resp_ts || '' === $resp_sig ) {
			return;
		}

		$expected = base64_encode(
			hash_hmac( 'sha256', $body_raw . "\n" . $resp_ts, $secret, true )
		);

		if ( ! hash_equals( $expected, $resp_sig ) ) {
			return;
		}

		$payload = json_decode( $body_raw, true );
		if ( ! is_array( $payload ) ) {
			return;
		}

		YOGB_BM_Tier_Webhook::apply_tier_payload(
			$payload,
			[
				'source' => 'pull',
			]
		);
	}
}

YOGB_BM_Tier_Sync::init();