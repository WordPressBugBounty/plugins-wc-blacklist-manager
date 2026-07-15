<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Request-local metrics and privacy-safe diagnostics for the YOGB client. */
final class YOGB_BM_Observability {
	private static $metrics = [];

	public static function init() : void {
		add_action( 'yogb_bm_http_event', [ __CLASS__, 'http_event' ], 10, 2 );
		add_action( 'yogb_bm_outbox_event', [ __CLASS__, 'outbox_event' ], 10, 2 );
		add_action( 'yogb_bm_auth_recovery', [ __CLASS__, 'auth_recovery' ] );
	}

	public static function http_event( string $event, array $context ) : void {
		self::record( 'http.' . sanitize_key( $event ), $context );
	}

	public static function outbox_event( string $event, array $context ) : void {
		self::record( 'outbox.' . sanitize_key( $event ), $context );
	}

	public static function auth_recovery( string $source ) : void {
		self::record( 'auth.recovery', [ 'source' => sanitize_key( $source ) ] );
	}

	public static function snapshot() : array {
		return self::$metrics;
	}

	public static function diagnostics() : array {
		global $wpdb;
		$table  = $wpdb->prefix . 'wc_blacklist_gbl_outbox';
		$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$outbox = $exists ? (array) $wpdb->get_results( "SELECT status,COUNT(*) count FROM {$table} GROUP BY status", ARRAY_A ) : [];
		return [
			'connected' => (bool) get_option( 'yogb_bm_api_key' ) && (bool) get_option( 'yogb_bm_api_secret' ) && (bool) get_option( 'yogb_bm_reporter_id' ),
			'tier'      => sanitize_key( (string) get_option( 'yogb_bm_tier', 'free' ) ),
			'outbox'    => $outbox,
			'cron'      => [
				'sweep'     => wp_next_scheduled( YOGB_BM_Outbox::SWEEP_HOOK ) ?: null,
				'tier_sync' => wp_next_scheduled( 'yogb_bm_tier_sync_hourly' ) ?: null,
			],
		];
	}

	private static function record( string $name, array $context ) : void {
		$allowed = [];
		foreach ( [ 'route', 'code', 'error_code', 'type', 'attempts', 'source' ] as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$allowed[ $key ] = is_string( $context[ $key ] ) ? substr( sanitize_text_field( $context[ $key ] ), 0, 160 ) : $context[ $key ];
			}
		}
		if ( ! isset( self::$metrics[ $name ] ) ) {
			self::$metrics[ $name ] = [ 'count' => 0, 'last' => [] ];
		}
		self::$metrics[ $name ]['count']++;
		self::$metrics[ $name ]['last'] = $allowed;
		do_action( 'yogb_bm_metric', $name, $allowed );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && in_array( $name, [ 'http.transport_error', 'outbox.dead', 'auth.recovery' ], true ) ) {
			error_log( '[YOGB-BM] ' . wp_json_encode( [ 'event' => $name, 'context' => $allowed ] ) );
		}
	}
}

YOGB_BM_Observability::init();
