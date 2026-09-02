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
		add_action( 'yogb_bm_report_v2_selection_event', [ __CLASS__, 'report_v2_selection_event' ], 10, 2 );
		add_action( 'yogb_bm_auth_recovery', [ __CLASS__, 'auth_recovery' ] );
		add_action( 'yogb_bm_control_sync_event', [ __CLASS__, 'control_sync_event' ], 10, 2 );
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

	public static function report_v2_selection_event( string $state, array $context ) : void {
		self::record(
			'report.selection',
			[
				'contract' => sanitize_key( (string) ( $context['contract'] ?? 'v1' ) ),
				'state'    => sanitize_key( $state ),
			]
		);
	}

	public static function control_sync_event( string $event, array $context ) : void {
		self::record( 'control.' . sanitize_key( $event ), $context );
	}

	public static function snapshot() : array {
		return self::$metrics;
	}

	public static function diagnostics() : array {
		global $wpdb;
		$tier_sync_hook = class_exists( 'YOGB_BM_Tier_Sync' )
			? YOGB_BM_Tier_Sync::REPAIR_HOOK
			: 'yogb_bm_tier_sync_repair';
		$table  = $wpdb->prefix . 'wc_blacklist_gbl_outbox';
		$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$outbox = $exists ? (array) $wpdb->get_results( "SELECT status,COUNT(*) count FROM {$table} GROUP BY status", ARRAY_A ) : [];
		$delivery = $exists ? (array) $wpdb->get_results(
			"SELECT event_type,status,COUNT(*) count FROM {$table} WHERE event_type IN ('report','report_v2') GROUP BY event_type,status",
			ARRAY_A
		) : [];
		$failures = $exists ? (array) $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN event_type='report_v2' AND last_http_code=409 AND last_error='report_v2_unavailable' THEN 1 ELSE 0 END) report_v2_unavailable,
				SUM(CASE WHEN event_type='report_v2' AND status='dead' THEN 1 ELSE 0 END) report_v2_dead
			FROM {$table}",
			ARRAY_A
		) : [];
		return [
			'connected' => (bool) get_option( 'yogb_bm_api_key' ) && (bool) get_option( 'yogb_bm_api_secret' ) && (bool) get_option( 'yogb_bm_reporter_id' ),
			'tier'      => sanitize_key( (string) get_option( 'yogb_bm_tier', 'free' ) ),
			'report_v2_capability' => self::capability_diagnostics(),
			'report_delivery'      => $delivery,
			'report_v2_failures'   => [
				'unavailable' => (int) ( $failures['report_v2_unavailable'] ?? 0 ),
				'dead'        => (int) ( $failures['report_v2_dead'] ?? 0 ),
			],
			'outbox'    => $outbox,
			'cron'      => [
				'sweep'     => wp_next_scheduled( YOGB_BM_Outbox::SWEEP_HOOK ) ?: null,
				'tier_sync' => wp_next_scheduled( $tier_sync_hook ) ?: null,
			],
		];
	}

	/** Return a bounded state/freshness summary without the signed capability payload or timestamp. */
	public static function capability_diagnostics( ?int $now = null ) : array {
		$now = null === $now ? time() : $now;
		$state = class_exists( 'YOGB_BM_Report_V2' )
			? YOGB_BM_Report_V2::capability_state( $now )
			: [ 'fresh' => false, 'supported' => false, 'verified_at' => 0, 'capabilities' => [] ];
		$verified_at = max( 0, (int) ( $state['verified_at'] ?? 0 ) );
		$age = $verified_at > 0 && $now >= $verified_at ? $now - $verified_at : null;
		if ( null === $age ) {
			$freshness = 'unverified';
		} elseif ( $age <= 300 ) {
			$freshness = 'under_5m';
		} elseif ( $age <= 3600 ) {
			$freshness = 'under_1h';
		} elseif ( $age <= YOGB_BM_Report_V2::CAPABILITY_FRESH_SECONDS ) {
			$freshness = 'under_2h';
		} else {
			$freshness = 'stale';
		}
		$capabilities = (array) ( $state['capabilities'] ?? [] );
		$status = ! empty( $state['supported'] )
			? 'supported'
			: ( empty( $state['fresh'] ) ? ( $verified_at > 0 ? 'stale' : 'unverified' ) : 'absent' );
		return [
			'state'     => $status,
			'freshness' => $freshness,
			'v2_listed' => in_array( 'report_admission_v2', $capabilities, true ),
		];
	}

	private static function record( string $name, array $context ) : void {
		$allowed = [];
		foreach ( [ 'route', 'code', 'error_code', 'type', 'attempts', 'source', 'contract', 'state' ] as $key ) {
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
