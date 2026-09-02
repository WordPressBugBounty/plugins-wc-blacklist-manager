<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YOGB_BM_Tier_Sync {
	const REPAIR_HOOK     = 'yogb_bm_tier_sync_repair';
	const REPAIR_SCHEDULE = 'yogb_six_hourly';
	const REPAIR_INTERVAL = 21600;
	const LEGACY_HOOK     = 'yogb_bm_tier_sync_hourly';

	public static function init() : void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_schedule' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );
		add_action( self::REPAIR_HOOK, [ __CLASS__, 'run_repair' ] );
		add_action( 'yogb_bm_registration_completed', [ __CLASS__, 'run' ] );
	}

	public static function add_schedule( array $schedules ) : array {
		if ( ! isset( $schedules[ self::REPAIR_SCHEDULE ] ) ) {
			$schedules[ self::REPAIR_SCHEDULE ] = [
				'interval' => self::REPAIR_INTERVAL,
				'display'  => __( 'Every Six Hours (YOGB)', 'wc-blacklist-manager' ),
			];
		}
		return $schedules;
	}

	/** Migrate the legacy hourly poll once and avoid cron-option churn on steady init. */
	public static function schedule() : void {
		if ( wp_next_scheduled( self::LEGACY_HOOK ) ) {
			wp_clear_scheduled_hook( self::LEGACY_HOOK );
		}
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::REPAIR_HOOK ) : null;
		$valid = is_object( $event )
			&& self::REPAIR_SCHEDULE === (string) ( $event->schedule ?? '' )
			&& self::REPAIR_INTERVAL === (int) ( $event->interval ?? 0 );
		if ( $valid ) {
			return;
		}
		if ( wp_next_scheduled( self::REPAIR_HOOK ) ) {
			wp_clear_scheduled_hook( self::REPAIR_HOOK );
		}
		wp_schedule_event( time() + 300 + self::stable_jitter(), self::REPAIR_SCHEDULE, self::REPAIR_HOOK );
	}

	/** Immediate compatibility path used by registration, activation and manual calls. */
	public static function run() : void {
		self::perform_pull( 'immediate' );
	}

	/** Auth recovery uses the same signed control stream and returns its outcome. */
	public static function run_recovery() : array {
		return self::perform_pull( 'auth_recovery' );
	}

	/** Coalesced scheduled/demand repair path. Lock failure degrades to live repair. */
	public static function run_repair() : void {
		global $wpdb;
		$lock_name = self::lock_name();
		$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) );
		if ( '0' === (string) $acquired ) {
			do_action( 'yogb_bm_control_sync_event', 'repair_coalesced', [ 'source' => 'repair', 'state' => 'lock_busy' ] );
			return;
		}
		if ( '1' !== (string) $acquired ) {
			do_action( 'yogb_bm_control_sync_event', 'repair_lock_degraded', [ 'source' => 'repair', 'state' => 'unavailable' ] );
			self::perform_pull( 'repair_uncoalesced' );
			return;
		}
		try {
			self::perform_pull( 'repair_coalesced' );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	private static function perform_pull( string $source ) : array {
		$recovery_source = 'auth_recovery' === $source;
		if ( $recovery_source && class_exists( 'YOGB_BM_Registrar' ) && ! YOGB_BM_Registrar::auth_recovery_lock_is_current() ) {
			return [ 'ok' => false, 'status' => 'recovery_lock_lost' ];
		}
		$credential_snapshot = class_exists( 'YOGB_BM_Registrar' )
			? YOGB_BM_Registrar::committed_credential_snapshot( $recovery_source )
			: [];
		if ( class_exists( 'YOGB_BM_Registrar' ) && empty( $credential_snapshot['ok'] ) ) {
			return [ 'ok' => false, 'status' => sanitize_key( (string) ( $credential_snapshot['status'] ?? 'auth_paused' ) ) ];
		}
		$api_key     = ! empty( $credential_snapshot ) ? (string) $credential_snapshot['api_key'] : (string) get_option( YOGB_BM_Report::OPT_KEY );
		$secret      = ! empty( $credential_snapshot ) ? (string) $credential_snapshot['api_secret'] : (string) get_option( YOGB_BM_Report::OPT_SECRET );
		$server_base = YOGB_BM_Report::server_base();
		$rest_route  = (string) YOGB_BM_Report::REST_ROUTE;
		if ( '' === $api_key || '' === $secret || '' === $server_base || '' === $rest_route ) {
			return [ 'ok' => false, 'status' => 'not_configured' ];
		}

		$server_base = rtrim( $server_base, '/' );
		$rest_route  = '/' . ltrim( $rest_route, '/' );
		if ( false === strpos( $server_base, '/wp-json' ) ) {
			$server_base .= '/wp-json';
		}
		$ts  = (string) time();
		$sig = base64_encode( hash_hmac( 'sha256', $api_key . "\n" . $ts, $secret, true ) );
		$credential_fingerprint = class_exists( 'YOGB_BM_Registrar' )
			? (string) $credential_snapshot['credential_fingerprint']
			: hash( 'sha256', $api_key . "\0" . $secret );
		$url = add_query_arg( 'yogb_cb', rawurlencode( $ts ), $server_base . $rest_route . '/client/tier' );
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
		if ( class_exists( 'YOGB_BM_Registrar' ) && ( ! YOGB_BM_Registrar::credential_snapshot_is_current( $credential_snapshot ) || ( $recovery_source && ! YOGB_BM_Registrar::auth_recovery_lock_is_current() ) ) ) {
			return [ 'ok' => false, 'status' => $recovery_source ? 'recovery_lock_lost' : 'credential_epoch_changed' ];
		}
		$res = $allow_unsafe_local ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
		if ( class_exists( 'YOGB_BM_Registrar' ) && ( ! YOGB_BM_Registrar::credential_snapshot_is_current( $credential_snapshot ) || ( $recovery_source && ! YOGB_BM_Registrar::auth_recovery_lock_is_current() ) ) ) {
			return [ 'ok' => false, 'status' => $recovery_source ? 'recovery_lock_lost' : 'credential_epoch_changed' ];
		}
		if ( is_wp_error( $res ) ) {
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( 'transport_error', 'tier_pull' );
			do_action( 'yogb_bm_control_sync_event', 'pull_failed', [ 'source' => $source, 'state' => 'transport' ] );
			return [ 'ok' => false, 'status' => 'transport_error' ];
		}

		$http_code = (int) wp_remote_retrieve_response_code( $res );
		$body_raw  = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $http_code ) {
			$error_body = json_decode( $body_raw, true );
			$error_code = is_array( $error_body ) ? sanitize_key( (string) ( $error_body['error'] ?? '' ) ) : '';
			if ( 401 === $http_code && class_exists( 'YOGB_BM_Registrar' ) ) {
				YOGB_BM_Registrar::handle_auth_failure( 'tier_pull', $credential_fingerprint );
			} elseif ( class_exists( 'YOGB_BM_Registrar' ) ) {
				YOGB_BM_Registrar::mark_connection_error( $error_code ?: 'http_' . $http_code, 'tier_pull' );
			}
			do_action( 'yogb_bm_control_sync_event', 'pull_failed', [ 'source' => $source, 'state' => 'http' ] );
			$status = 401 === $http_code ? 'unauthorized' : ( 404 === $http_code ? 'not_found' : ( $error_code ?: 'http_error' ) );
			return [ 'ok' => false, 'status' => $status, 'code' => $http_code ];
		}

		$resp_ts  = (string) wp_remote_retrieve_header( $res, 'x-yogb-timestamp' );
		$resp_sig = (string) wp_remote_retrieve_header( $res, 'x-yogb-signature' );
		if ( '' === $body_raw || '' === $resp_ts || '' === $resp_sig ) {
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( 'missing_response_signature', 'tier_pull' );
			return [ 'ok' => false, 'status' => 'missing_response_signature' ];
		}
		if ( ! ctype_digit( $resp_ts ) || abs( time() - (int) $resp_ts ) > 900 ) {
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( 'stale_response', 'tier_pull' );
			return [ 'ok' => false, 'status' => 'stale_response' ];
		}
		$expected = base64_encode( hash_hmac( 'sha256', $body_raw . "\n" . $resp_ts, $secret, true ) );
		if ( ! hash_equals( $expected, $resp_sig ) ) {
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( 'bad_response_signature', 'tier_pull' );
			return [ 'ok' => false, 'status' => 'bad_response_signature' ];
		}

		$payload = json_decode( $body_raw, true );
		if ( ! is_array( $payload ) ) {
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( 'invalid_json', 'tier_pull' );
			return [ 'ok' => false, 'status' => 'invalid_json' ];
		}
		if ( $recovery_source && class_exists( 'YOGB_BM_Registrar' ) && ! YOGB_BM_Registrar::auth_recovery_lock_is_current() ) {
			return [ 'ok' => false, 'status' => 'recovery_lock_lost' ];
		}
		if ( class_exists( 'YOGB_BM_Registrar' ) && ! YOGB_BM_Registrar::credential_snapshot_is_current( $credential_snapshot ) ) {
			return [ 'ok' => false, 'status' => 'credential_epoch_changed' ];
		}
		if ( class_exists( 'YOGB_BM_Registrar' ) && ! YOGB_BM_Registrar::validate_control_authority( $payload, $credential_fingerprint ) ) {
			return [ 'ok' => false, 'status' => 'reporter_mismatch' ];
		}
		$result = YOGB_BM_Tier_Webhook::apply_tier_payload(
			$payload,
			[
				'source'                     => 'pull',
				'bind_verified_auth_control' => true,
				'credential_fingerprint'     => $credential_fingerprint,
				'credential_snapshot'        => $credential_snapshot,
			]
		);
		// The ordered applier calls record_verified_capabilities only after every
		// present control component has validated and passed generation ordering.
		if ( empty( $result['ok'] ) ) {
			$error = sanitize_key( (string) ( $result['error'] ?? '' ) );
			if ( in_array( $error, [ 'control_apply_busy', 'control_apply_storage_failed' ], true ) ) {
				if ( class_exists( 'YOGB_BM_Report_V2' ) ) {
					YOGB_BM_Report_V2::schedule_capability_refresh();
				}
				do_action( 'yogb_bm_control_sync_event', 'pull_deferred', [ 'source' => $source, 'state' => $error ] );
				return $result;
			}
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( sanitize_key( (string) ( $result['error'] ?? 'invalid_tier_payload' ) ), 'tier_pull' );
			do_action( 'yogb_bm_control_sync_event', 'pull_failed', [ 'source' => $source, 'state' => 'payload' ] );
			return $result;
		}
		do_action( 'yogb_bm_control_sync_event', 'pull_applied', [ 'source' => $source, 'state' => sanitize_key( (string) ( $result['status'] ?? 'ok' ) ) ] );
		return $result;
	}

	private static function stable_jitter() : int {
		$identity = untrailingslashit( strtolower( (string) home_url( '/' ) ) ) . '|blog:' . (int) get_current_blog_id();
		return (int) ( hexdec( substr( hash( 'sha256', $identity ), 0, 8 ) ) % 3600 );
	}

	private static function lock_name() : string {
		$identity = untrailingslashit( strtolower( (string) home_url( '/' ) ) ) . '|blog:' . (int) get_current_blog_id();
		return 'yogb_tier_repair_' . substr( hash( 'sha256', $identity ), 0, 40 );
	}
}

YOGB_BM_Tier_Sync::init();
