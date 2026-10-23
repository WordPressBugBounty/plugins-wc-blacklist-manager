<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YOGB_BM_Tier_Sync {
	const REPAIR_HOOK     = 'yogb_bm_tier_sync_repair';
	const REPAIR_SCHEDULE = 'yogb_six_hourly';
	const REPAIR_INTERVAL = 21600;
	const REFRESH_OPTION = 'yogb_bm_tier_refresh_v1';
	const CAPABILITY = 1;
	const CONTROL = 2;
	const USAGE = 4;
	private static $owner = null;

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

	/** Fleet repair needs control and usage; surface tickets never promote into retries. */
	public static function run_repair( bool $surface = false ) : void {
		self::background( $surface ? self::CAPABILITY : self::CONTROL | self::USAGE, $surface );
	}

	public static function refresh_background() : void {
		self::background( 0, false );
	}

	private static function identity() : string {
		$s = YOGB_BM_Registrar::committed_credential_snapshot( false );
		if ( empty( $s['ok'] ) ) return '';
		return hash( 'sha256', strtolower( untrailingslashit( home_url( '/' ) ) ) . '|' . get_current_blog_id()
			. '|' . YOGB_BM_Report::server_base() . '|' . ( $s['reporter_id'] ?? 0 )
			. '|' . ( $s['credential_fingerprint'] ?? '' ) . '|' . ( $s['recovery_epoch'] ?? '' ) );
	}

	/** Raw database authority; never repair malformed or unreadable bytes. */
	private static function read_record( string $identity, int $now ) : array {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", self::REFRESH_OPTION ) );
		if ( '' !== (string) $wpdb->last_error || '' === $identity ) return [ false, null ];
		if ( null === $raw ) return [ null, self::empty_record( $identity ) ];
		$r = is_string( $raw ) && strlen( $raw ) < 1024 ? json_decode( $raw, true ) : null;
		if ( ! is_array( $r ) || array_keys( $r ) !== [ 'v', 'identity', 'last', 'due', 'failures', 'pending', 'active', 'owner', 'lease' ]
			|| 1 !== $r['v'] || ! is_string( $r['identity'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $r['identity'] )
			|| ! is_string( $r['owner'] ) || ( '' !== $r['owner'] && ! preg_match( '/^[a-f0-9]{32}$/D', $r['owner'] ) ) ) return [ false, null ];
		foreach ( [ 'last', 'due', 'failures', 'pending', 'active', 'lease' ] as $key ) {
			if ( ! is_int( $r[ $key ] ) || $r[ $key ] < 0 ) return [ false, null ];
		}
		if ( $r['failures'] > 8 || $r['pending'] > 7 || $r['active'] > 7 || $r['last'] > $now
			|| ( 0 === $r['last'] && ( 0 !== $r['due'] || 0 !== $r['failures'] || '' !== $r['owner'] ) )
			|| ( $r['last'] > 0 && $r['due'] < $r['last'] + 300 )
			|| ( '' === $r['owner'] && ( 0 !== $r['lease'] || 0 !== $r['active'] ) )
			|| $r['due'] > max( $r['last'], $now ) + self::REPAIR_INTERVAL
			|| ( '' !== $r['owner'] && ( $r['lease'] > $r['last'] + 60 || $r['lease'] < $r['last'] ) ) ) return [ false, null ];
		// A different committed identity may replace only a valid predecessor by CAS.
		return [ $raw, $r['identity'] === $identity ? $r : self::empty_record( $identity ) ];
	}

	private static function empty_record( string $identity ) : array {
		return [ 'v' => 1, 'identity' => $identity, 'last' => 0, 'due' => 0, 'failures' => 0, 'pending' => 0, 'active' => 0, 'owner' => '', 'lease' => 0 ];
	}

	/** Exactly one insert/CAS per transition, with no request-local retry. */
	private static function save_record( $raw, array $r ) : bool {
		global $wpdb;
		$value = wp_json_encode( $r );
		if ( ! is_string( $value ) || strlen( $value ) >= 1024 ) return false;
		$sql = null === $raw
			? $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", self::REFRESH_OPTION, $value )
			: $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s,autoload='no' WHERE option_name=%s AND BINARY option_value=%s", $value, self::REFRESH_OPTION, $raw );
		if ( 1 !== $wpdb->query( $sql ) ) return false;
		wp_cache_delete( self::REFRESH_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return true;
	}

	private static function arm( int $due ) : void {
		$hook = YOGB_BM_Report_V2::CAPABILITY_REFRESH_HOOK;
		$due = max( time() + 5, $due );
		$next = wp_next_scheduled( $hook );
		if ( false !== $next && (int) $next > $due ) {
			wp_unschedule_event( (int) $next, $hook );
			$next = false;
		}
		if ( false === $next ) wp_schedule_single_event( $due, $hook );
	}

	/** Capability, control and usage demand share one due time; merging never postpones it. */
	public static function request_refresh( int $intent = self::CAPABILITY ) : void {
		$now = time();
		$intent &= 7;
		if ( self::CAPABILITY === $intent && YOGB_BM_Report_V2::capability_state()['fresh'] ) return;
		[ $raw, $r ] = self::read_record( self::identity(), $now );
		if ( null === $r ) return;
		$pending = $r['pending'] | $intent;
		if ( $pending !== $r['pending'] ) {
			$r['pending'] = $pending;
			if ( ! self::save_record( $raw, $r ) ) return;
		}
		if ( $pending || $r['active'] ) self::arm( max( $now + 5, $r['due'], $r['lease'] ) );
	}

	/** A complete push satisfies control/capabilities only. Usage keeps its own freshness. */
	public static function webhook_satisfied( int $intent = 3 ) : void {
		[ $raw, $r ] = self::read_record( self::identity(), time() );
		$intent &= 3;
		if ( null === $r || null === $raw || ! ( ( $r['pending'] | $r['active'] ) & $intent ) ) return;
		$r['pending'] &= ~ $intent;
		$r['active'] &= ~ $intent;
		self::save_record( $raw, $r );
	}

	private static function owner_current() : bool {
		if ( null === self::$owner ) return true;
		[ $raw, $r ] = self::read_record( self::identity(), time() );
		return null !== $r && $r['identity'] === self::$owner['identity']
			&& $r['owner'] === self::$owner['owner'] && $r['lease'] > time();
	}

	private static function background( int $intent, bool $surface ) : void {
		$now = time();
		[ $raw, $r ] = self::read_record( self::identity(), $now );
		if ( null === $r ) {
			do_action( 'yogb_bm_control_sync_event', 'pull_deferred', [ 'source' => 'background', 'state' => 'refresh_storage_unavailable' ] );
			return;
		}
		$prior_pending = $r['pending'] | $r['active'];
		$work = $prior_pending | $intent;
		if ( ! $work ) return;
		if ( $r['due'] > $now || $r['lease'] > $now ) {
			if ( ! $surface ) self::request_refresh( $intent );
			return;
		}
		// Separate admitted ownership from arrivals while this worker is in flight.
		// A new surface ticket is consumed, never promoted into retryable work.
		$r['active'] = $surface ? $prior_pending : $work;
		$r['pending'] = 0;
		$r['last'] = $now;
		$r['due'] = $now + 300;
		$r['owner'] = bin2hex( random_bytes( 16 ) );
		$r['lease'] = $now + 60;
		if ( ! self::save_record( $raw, $r ) ) return;
		self::$owner = $r;
		try {
			$result = self::perform_pull( 'background', $surface );
			if ( ! self::owner_current() ) return;
			[ $current_raw, $current ] = self::read_record( $r['identity'], time() );
			if ( null === $current || $current['owner'] !== $r['owner'] ) return;
			// An ordered no-write stale response has not fulfilled newer repair demand.
			$ok = ! empty( $result['ok'] ) && empty( $result['repair_required'] )
				&& ! in_array( $result['status'] ?? '', [ 'stale_version_ignored', 'duplicate_ignored' ], true );
			$current['failures'] = $ok ? 0 : min( 8, $r['failures'] + 1 );
			$delay = $ok ? 300 : self::retry_delay( $current['failures'], (int) ( $result['retry_after'] ?? 0 ) );
			$current['due'] = max( $r['due'], $now + $delay );
			// Retire only this attempt. New arrivals and webhook acknowledgments own
			// their own bits; failed/crashed work is retried only while still active.
			if ( ! $ok ) $current['pending'] |= $current['active'];
			$current['active'] = 0;
			$current['owner'] = ''; $current['lease'] = 0;
			if ( self::save_record( $current_raw, $current ) && $current['pending'] ) self::arm( $current['due'] );
		} finally {
			self::$owner = null;
		}
	}

	public static function retry_delay( int $failures, int $floor = 0 ) : int {
		$base = min( self::REPAIR_INTERVAL, 300 * ( 1 << max( 0, min( 7, $failures - 1 ) ) ) );
		return min( self::REPAIR_INTERVAL, max( $base, min( self::REPAIR_INTERVAL, $floor ) ) + wp_rand( 0, 60 ) );
	}

	/** Saturate before integer conversion; malformed/unbounded dates cannot overflow. */
	public static function retry_after( $value ) : int {
		if ( ! is_string( $value ) || strlen( $value ) > 128 ) return 0;
		$value = trim( $value );
		if ( preg_match( '/^[0-9]+$/D', $value ) ) {
			$digits = ltrim( $value, '0' );
			return strlen( $digits ) > 5 ? self::REPAIR_INTERVAL : min( self::REPAIR_INTERVAL, (int) $digits );
		}
		if ( ! preg_match( '/^[A-Za-z]{3}, [0-9]{2} [A-Za-z]{3} [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT$/D', $value ) ) return 0;
		$at = strtotime( $value );
		return false === $at || $at <= time() ? 0 : (int) min( self::REPAIR_INTERVAL, $at - time() );
	}

	private static function perform_pull( string $source, bool $surface = false ) : array {
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

		if ( ! self::owner_current() ) return [ 'ok' => false, 'status' => 'refresh_owner_lost', 'availability' => true ];
		// Immediate callers bypass cooldown, but never an active optional owner.
		if ( null === self::$owner ) {
			[ $raw, $r ] = self::read_record( self::identity(), time() );
			if ( null !== $r && $r['lease'] > time() ) return [ 'ok' => false, 'status' => 'refresh_busy', 'availability' => true ];
		}
		$ready = YOGB_BM_Reconnect_Guard::run( $credential_snapshot, static function() { return [ 'ok' => true ]; } );
		if ( empty( $ready['ok'] ) ) {
			do_action( 'yogb_bm_control_sync_event', 'pull_deferred', [ 'source' => $source, 'state' => sanitize_key( $ready['error'] ?? 'control_apply_storage_failed' ) ] );
			if ( ! in_array( $ready['error'] ?? '', [ 'reconnect_busy', 'reconnect_storage_unavailable' ], true ) ) return $ready + [ 'status' => $ready['error'] ?? 'preflight_failed' ];
			return $ready + [ 'status' => 'reconnect_busy' === $ready['error'] ? 'control_apply_busy' : 'control_apply_storage_failed', 'availability' => true ];
		}

		$server_base = rtrim( $server_base, '/' );
		$rest_route  = '/' . ltrim( $rest_route, '/' );
		if ( false === strpos( $server_base, '/wp-json' ) ) {
			$server_base .= '/wp-json';
		}
		$ts  = (string) time();
		$epoch = (string) ( $credential_snapshot['recovery_epoch'] ?? '' );
		$sig = base64_encode( hash_hmac( 'sha256', $api_key . "\n" . $ts . ( '' !== $epoch ? "\n" . $epoch : '' ), $secret, true ) );
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
    'X-YOGB-Recovery-Epoch' => $epoch,
				'Cache-Control'       => 'no-cache',
				'Pragma'              => 'no-cache',
			],
			'reject_unsafe_urls' => ! $allow_unsafe_local,
		];
		if ( class_exists( 'YOGB_BM_Registrar' ) && ( ! YOGB_BM_Registrar::credential_snapshot_is_current( $credential_snapshot ) || ( $recovery_source && ! YOGB_BM_Registrar::auth_recovery_lock_is_current() ) ) ) {
			return [ 'ok' => false, 'status' => $recovery_source ? 'recovery_lock_lost' : 'credential_epoch_changed' ];
		}
		if ( ! self::owner_current() ) return [ 'ok' => false, 'status' => 'refresh_owner_lost', 'availability' => true ];
		$res = $allow_unsafe_local ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
		if ( class_exists( 'YOGB_BM_Registrar' ) && ( ! YOGB_BM_Registrar::credential_snapshot_is_current( $credential_snapshot ) || ( $recovery_source && ! YOGB_BM_Registrar::auth_recovery_lock_is_current() ) ) ) {
			return [ 'ok' => false, 'status' => $recovery_source ? 'recovery_lock_lost' : 'credential_epoch_changed' ];
		}
  if ( ! self::owner_current() ) return [ 'ok' => false, 'status' => 'refresh_owner_lost', 'availability' => true ];
  $guarded = YOGB_BM_Reconnect_Guard::run( $credential_snapshot, static function() use ( $res, $credential_snapshot, $credential_fingerprint, $secret, $source, $surface, $recovery_source ) {

		if ( ! self::owner_current() ) return [ 'ok' => false, 'status' => 'refresh_owner_lost', 'availability' => true ];
		if ( is_wp_error( $res ) ) {
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( 'transport_error', 'tier_pull' );
			do_action( 'yogb_bm_control_sync_event', 'pull_failed', [ 'source' => $source, 'state' => 'transport' ] );
			return [ 'ok' => false, 'status' => 'transport_error', 'availability' => true ];
		}

		$http_code = (int) wp_remote_retrieve_response_code( $res );
		$body_raw  = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $http_code ) {
			if ( 0 === $http_code || in_array( $http_code, [ 408, 425, 429 ], true ) || $http_code >= 500 ) {
				do_action( 'yogb_bm_control_sync_event', 'pull_failed', [ 'source' => $source, 'state' => 'availability' ] );
				return [ 'ok' => false, 'status' => 'availability', 'availability' => true, 'code' => $http_code,
					'retry_after' => self::retry_after( wp_remote_retrieve_header( $res, 'retry-after' ) ) ];
			}
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
				do_action( 'yogb_bm_control_sync_event', 'pull_deferred', [ 'source' => $source, 'state' => $error ] );
				return $result + [ 'availability' => true ];
			}
			if ( class_exists( 'YOGB_BM_Registrar' ) ) YOGB_BM_Registrar::mark_connection_error( sanitize_key( (string) ( $result['error'] ?? 'invalid_tier_payload' ) ), 'tier_pull' );
			do_action( 'yogb_bm_control_sync_event', 'pull_failed', [ 'source' => $source, 'state' => 'payload' ] );
			return $result;
		}
		// Optional notification authority cannot change control/authentication outcomes.
		if ( class_exists( 'YOGB_BM_Usage' ) && 'stale_version_ignored' !== ( $result['status'] ?? '' ) ) {
			try { YOGB_BM_Usage::ingest( $body_raw, (int) $resp_ts, $resp_sig ); } catch ( Throwable $error ) { /* Notifications fail closed. */ }
		}
		do_action( 'yogb_bm_control_sync_event', 'pull_applied', [ 'source' => $source, 'state' => sanitize_key( (string) ( $result['status'] ?? 'ok' ) ) ] );
		return $result;

  } );
  if ( in_array( $guarded['error'] ?? '', [ 'reconnect_busy', 'reconnect_storage_unavailable' ], true ) ) {
   $error = 'reconnect_busy' === $guarded['error'] ? 'control_apply_busy' : 'control_apply_storage_failed';
   do_action( 'yogb_bm_control_sync_event', 'pull_deferred', [ 'source' => $source, 'state' => $error ] );
   return [ 'ok' => false, 'error' => $error, 'code' => 503, 'repair_required' => true, 'availability' => true ];
  }
  return $guarded;
}

	private static function stable_jitter() : int {
		$identity = untrailingslashit( strtolower( (string) home_url( '/' ) ) ) . '|blog:' . (int) get_current_blog_id();
		return (int) ( hexdec( substr( hash( 'sha256', $identity ), 0, 8 ) ) % 3600 );
	}

}

YOGB_BM_Tier_Sync::init();
