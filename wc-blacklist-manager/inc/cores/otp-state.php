<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal schema lifecycle for the Core-owned OTP state contract.
 *
 * The marker is deliberately independent from the product version. Ordinary
 * frontend reads may schedule repair, but never execute schema DDL.
 */
final class WC_Blacklist_Manager_OTP_Schema {
	const GENERATION       = 1;
	const VERSION_OPTION   = 'wc_blacklist_manager_otp_schema_generation';
	const REPAIR_HOOK      = 'wc_blacklist_manager_otp_schema_repair_v1';
	const CLEANUP_HOOK     = 'wc_blacklist_manager_otp_state_cleanup_v1';
	const REPAIR_LOCK      = 'wc_blacklist_manager_otp_schema_repair_lock_v1';
	const CLEANUP_BATCH    = 200;

	private static $ready_cache = null;

	public static function init() {
		register_activation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( __CLASS__, 'activate' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ), 6 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
		add_action( self::REPAIR_HOOK, array( __CLASS__, 'install' ) );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
	}

	public static function state_table() {
		global $wpdb;
		return $wpdb->prefix . 'wc_blacklist_otp_state';
	}

	public static function rate_table() {
		global $wpdb;
		return $wpdb->prefix . 'wc_blacklist_otp_rate';
	}

	public static function activate() {
		self::install();
		self::schedule_cleanup();
	}

	public static function after_upgrade( $upgrader, $options ) {
		if ( empty( $options['plugins'] ) || ! is_array( $options['plugins'] ) ) {
			return;
		}

		if ( in_array( WC_BLACKLIST_MANAGER_PLUGIN_BASENAME, $options['plugins'], true ) ) {
			self::install();
		}
	}

	public static function maybe_install() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::is_ready( true ) ) {
			self::install();
		}

		self::schedule_cleanup();
	}

	public static function is_ready( $refresh = false ) {
		if ( ! $refresh && null !== self::$ready_cache ) {
			return self::$ready_cache;
		}

		$marker = (int) get_option( self::VERSION_OPTION, 0 );
		if ( self::GENERATION !== $marker ) {
			self::$ready_cache = false;
			return false;
		}

		self::$ready_cache = self::schema_matches();
		return self::$ready_cache;
	}

	public static function schedule_repair() {
		if ( self::is_ready() || wp_next_scheduled( self::REPAIR_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::REPAIR_HOOK );
	}

	private static function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	public static function install() {
		if ( get_transient( self::REPAIR_LOCK ) ) {
			return false;
		}

		set_transient( self::REPAIR_LOCK, 1, 2 * MINUTE_IN_SECONDS );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$state   = self::state_table();
		$rate    = self::rate_table();

		$state_sql = "CREATE TABLE {$state} (
			subject_key varchar(64) NOT NULL,
			channel varchar(16) NOT NULL,
			secret_fingerprint varchar(64) NOT NULL,
			identity_key varchar(64) NOT NULL DEFAULT '',
			revision bigint(20) unsigned NOT NULL DEFAULT 0,
			generation bigint(20) unsigned NOT NULL DEFAULT 0,
			challenge_id varchar(32) NOT NULL DEFAULT '',
			otp_verifier varchar(64) NOT NULL DEFAULT '',
			challenge_status varchar(16) NOT NULL DEFAULT '',
			challenge_issued_at bigint(20) unsigned NOT NULL DEFAULT 0,
			challenge_expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
			resend_available_at bigint(20) unsigned NOT NULL DEFAULT 0,
			attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
			resend_count smallint(5) unsigned NOT NULL DEFAULT 0,
			proof_id varchar(32) NOT NULL DEFAULT '',
			proof_identity_key varchar(64) NOT NULL DEFAULT '',
			proof_verified_at bigint(20) unsigned NOT NULL DEFAULT 0,
			proof_expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
			last_request_id varchar(64) NOT NULL DEFAULT '',
			last_request_fingerprint varchar(64) NOT NULL DEFAULT '',
			last_request_operation varchar(16) NOT NULL DEFAULT '',
			last_request_result varchar(32) NOT NULL DEFAULT '',
			operation_token varchar(32) NOT NULL DEFAULT '',
			operation_expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (subject_key,channel),
			KEY challenge_expiry (challenge_expires_at),
			KEY proof_expiry (proof_expires_at),
			KEY proof_owner (proof_id,proof_identity_key,channel),
			KEY operation_expiry (operation_expires_at),
			KEY updated_at (updated_at)
		) {$charset};";

		$rate_sql = "CREATE TABLE {$rate} (
			dimension_key varchar(64) NOT NULL,
			channel varchar(16) NOT NULL,
			window_start bigint(20) unsigned NOT NULL,
			secret_fingerprint varchar(64) NOT NULL,
			request_count int(10) unsigned NOT NULL DEFAULT 0,
			expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (dimension_key,window_start),
			KEY expiry (expires_at)
		) {$charset};";

		dbDelta( $state_sql );
		dbDelta( $rate_sql );
		self::$ready_cache = null;

		if ( self::schema_matches() ) {
			update_option( self::VERSION_OPTION, self::GENERATION, false );
			self::$ready_cache = true;
			delete_transient( self::REPAIR_LOCK );
			self::schedule_cleanup();
			return true;
		}

		delete_transient( self::REPAIR_LOCK );
		return false;
	}

	private static function schema_matches() {
		global $wpdb;
		$state          = self::state_table();
		$rate           = self::rate_table();
		$state_columns  = array(
			'subject_key', 'channel', 'secret_fingerprint', 'identity_key', 'revision', 'generation',
			'challenge_id', 'otp_verifier', 'challenge_status', 'challenge_issued_at', 'challenge_expires_at',
			'resend_available_at', 'attempt_count', 'resend_count', 'proof_id', 'proof_identity_key',
			'proof_verified_at', 'proof_expires_at', 'last_request_id', 'last_request_fingerprint',
			'last_request_operation', 'last_request_result', 'operation_token', 'operation_expires_at',
			'created_at', 'updated_at',
		);
		$rate_columns   = array( 'dimension_key', 'channel', 'window_start', 'secret_fingerprint', 'request_count', 'expires_at', 'created_at' );
		$state_indexes  = array(
			'PRIMARY'          => array( 'subject_key', 'channel' ),
			'challenge_expiry' => array( 'challenge_expires_at' ),
			'proof_expiry'     => array( 'proof_expires_at' ),
			'proof_owner'      => array( 'proof_id', 'proof_identity_key', 'channel' ),
			'operation_expiry' => array( 'operation_expires_at' ),
			'updated_at'       => array( 'updated_at' ),
		);
		$rate_indexes   = array(
			'PRIMARY' => array( 'dimension_key', 'window_start' ),
			'expiry'  => array( 'expires_at' ),
		);

		return self::table_matches( $state, $state_columns, $state_indexes )
			&& self::table_matches( $rate, $rate_columns, $rate_indexes );
	}

	private static function table_matches( $table, array $columns, array $indexes ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $found ) {
			return false;
		}

		$quoted_table   = str_replace( '`', '``', $table );
		$actual_columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$quoted_table}`", 0 );
		if ( array_diff( $columns, $actual_columns ) ) {
			return false;
		}

		$actual_indexes = array();
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM `{$quoted_table}`", ARRAY_A ) as $index ) {
			$name = isset( $index['Key_name'] ) ? (string) $index['Key_name'] : '';
			$seq  = isset( $index['Seq_in_index'] ) ? (int) $index['Seq_in_index'] : 0;
			if ( '' !== $name && $seq > 0 ) {
				$actual_indexes[ $name ][ $seq ] = (string) $index['Column_name'];
			}
		}
		foreach ( $actual_indexes as &$actual_index ) {
			ksort( $actual_index );
			$actual_index = array_values( $actual_index );
		}
		unset( $actual_index );

		foreach ( $indexes as $name => $expected_columns ) {
			if ( ! isset( $actual_indexes[ $name ] ) || $expected_columns !== $actual_indexes[ $name ] ) {
				return false;
			}
		}
		return true;
	}

	public static function cleanup() {
		if ( ! self::is_ready( true ) ) {
			self::install();
			return;
		}

		WC_Blacklist_Manager_OTP_State::instance()->cleanup_expired( self::CLEANUP_BATCH );
	}
}

/** Core-owned OTP state/CAS/crypto/rate contract v1. */
final class WC_Blacklist_Manager_OTP_State {
	const CHALLENGE_TTL = 300;
	const PROOF_TTL     = 300;
	const RATE_WINDOW   = 3600;
	const LEASE_SECONDS = 30;
	const MAX_ATTEMPTS  = 5;

	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function now() {
		return (int) apply_filters( 'wc_blacklist_manager_otp_now', time() );
	}

	private function key( $purpose ) {
		return hash_hmac( 'sha256', 'wc-blacklist-manager:otp-state:v1:' . $purpose, wp_salt( 'auth' ), true );
	}

	private function hmac( $purpose, $value ) {
		return hash_hmac( 'sha256', (string) $value, $this->key( $purpose ) );
	}

	private function secret_fingerprint() {
		return $this->hmac( 'secret-fingerprint', 'v1' );
	}

	private function random_id( $entropy_source = null ) {
		try {
			$bytes = is_callable( $entropy_source ) ? call_user_func( $entropy_source, 16 ) : random_bytes( 16 );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'yobm_otp_entropy_unavailable', __( 'Verification is temporarily unavailable. Please try again shortly.', 'wc-blacklist-manager' ) );
		}

		if ( ! is_string( $bytes ) || 16 !== strlen( $bytes ) ) {
			return new WP_Error( 'yobm_otp_entropy_unavailable', __( 'Verification is temporarily unavailable. Please try again shortly.', 'wc-blacklist-manager' ) );
		}

		return bin2hex( $bytes );
	}

	public function is_ready() {
		$ready = WC_Blacklist_Manager_OTP_Schema::is_ready();
		if ( ! $ready ) {
			WC_Blacklist_Manager_OTP_Schema::schedule_repair();
		}
		return $ready;
	}

	public function resolve_subject() {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return array(
				'key'  => $this->hmac( 'subject', 'user:v1:' . $user_id ),
				'type' => 'user',
			);
		}

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return new WP_Error( 'yobm_otp_subject_unavailable', __( 'Verification is temporarily unavailable. Please refresh and try again.', 'wc-blacklist-manager' ) );
		}

		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		$customer_id = is_callable( array( WC()->session, 'get_customer_id' ) ) ? trim( (string) WC()->session->get_customer_id() ) : '';
		if ( '' === $customer_id ) {
			return new WP_Error( 'yobm_otp_subject_unavailable', __( 'Verification is temporarily unavailable. Please refresh and try again.', 'wc-blacklist-manager' ) );
		}

		return array(
			'key'  => $this->hmac( 'subject', 'woo-guest:v1:' . $customer_id ),
			'type' => 'guest',
		);
	}

	public function identity_key( $channel, $identity ) {
		return $this->hmac( 'identity:' . sanitize_key( $channel ), trim( (string) $identity ) );
	}

	private function normalize_ip( $ip ) {
		$ip = trim( (string) $ip );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = inet_pton( $ip );
		return false === $packed ? '' : (string) inet_ntop( $packed );
	}

	private function ip_in_cidr( $ip, $cidr ) {
		$parts = explode( '/', (string) $cidr, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! filter_var( $parts[0], FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$address = inet_pton( $ip );
		$network = inet_pton( $parts[0] );
		$bits    = (int) $parts[1];
		if ( false === $address || false === $network || strlen( $address ) !== strlen( $network ) || $bits < 0 || $bits > 8 * strlen( $address ) ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;
		if ( $bytes && substr( $address, 0, $bytes ) !== substr( $network, 0, $bytes ) ) {
			return false;
		}
		if ( ! $rest ) {
			return true;
		}
		$mask = 0xff << ( 8 - $rest );
		return ( ord( $address[ $bytes ] ) & $mask ) === ( ord( $network[ $bytes ] ) & $mask );
	}

	private function cloudflare_ranges() {
		return apply_filters( 'yobmp_cloudflare_proxies', array(
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
			'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
			'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
			'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32',
			'2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
			'2a06:98c0::/29', '2c0f:f248::/32',
		) );
	}

	private function is_trusted_proxy( $ip, $include_cloudflare = true ) {
		$ranges = (array) apply_filters( 'yobmp_trusted_proxies', array() );
		if ( $include_cloudflare ) {
			$ranges = array_merge( $ranges, (array) $this->cloudflare_ranges() );
		}
		foreach ( $ranges as $range ) {
			if ( $this->ip_in_cidr( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	private function is_cloudflare( $ip ) {
		foreach ( (array) $this->cloudflare_ranges() as $range ) {
			if ( $this->ip_in_cidr( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	public function canonical_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? $this->normalize_ip( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $remote ) {
			return '';
		}

		if ( $this->is_cloudflare( $remote ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = $this->normalize_ip( explode( ',', wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) )[0] );
			if ( '' !== $cf ) {
				return $cf;
			}
		}

		if ( $this->is_trusted_proxy( $remote ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$chain   = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$chain[] = $remote;
			for ( $index = count( $chain ) - 1; $index >= 0; $index-- ) {
				$candidate = $this->normalize_ip( $chain[ $index ] );
				if ( '' === $candidate ) {
					continue;
				}
				if ( ! $this->is_trusted_proxy( $candidate ) ) {
					return $candidate;
				}
			}
		}

		return $remote;
	}

	private function get_row( $subject_key, $channel ) {
		global $wpdb;
		$table = WC_Blacklist_Manager_OTP_Schema::state_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE subject_key = %s AND channel = %s LIMIT 1", $subject_key, $channel ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array();
		}
		if ( empty( $row['secret_fingerprint'] ) || ! hash_equals( $this->secret_fingerprint(), (string) $row['secret_fingerprint'] ) ) {
			$wpdb->delete( $table, array( 'subject_key' => $subject_key, 'channel' => $channel ) );
			return array();
		}
		return $row;
	}

	private function ensure_row( $subject_key, $channel ) {
		global $wpdb;
		$now   = $this->now();
		$table = WC_Blacklist_Manager_OTP_Schema::state_table();
		$sql   = $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (subject_key,channel,secret_fingerprint,created_at,updated_at) VALUES (%s,%s,%s,%d,%d)",
			$subject_key,
			$channel,
			$this->secret_fingerprint(),
			$now,
			$now
		);
		return false !== $wpdb->query( $sql );
	}

	private function expire_row( $row ) {
		if ( empty( $row ) ) {
			return $row;
		}
		$now               = $this->now();
		$challenge_expired = ! empty( $row['challenge_id'] ) && (int) $row['challenge_expires_at'] <= $now;
		$proof_expired     = ! empty( $row['proof_id'] ) && (int) $row['proof_expires_at'] <= $now;
		if ( ! $challenge_expired && ! $proof_expired ) {
			return $row;
		}

		global $wpdb;
		$table = WC_Blacklist_Manager_OTP_Schema::state_table();
		$set   = array( 'revision = revision + 1', 'updated_at = ' . (int) $now );
		if ( $challenge_expired ) {
			$set[] = "challenge_id = ''";
			$set[] = "otp_verifier = ''";
			$set[] = "challenge_status = ''";
			$set[] = 'challenge_issued_at = 0';
			$set[] = 'challenge_expires_at = 0';
			$set[] = 'resend_available_at = 0';
			$set[] = 'attempt_count = 0';
			$set[] = "operation_token = ''";
			$set[] = 'operation_expires_at = 0';
		}
		if ( $proof_expired ) {
			$set[] = "proof_id = ''";
			$set[] = "proof_identity_key = ''";
			$set[] = 'proof_verified_at = 0';
			$set[] = 'proof_expires_at = 0';
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ',', $set ) . ' WHERE subject_key = %s AND channel = %s AND revision = %d', $row['subject_key'], $row['channel'], (int) $row['revision'] ) );
		return $this->get_row( $row['subject_key'], $row['channel'] );
	}

	private function controls( $operation, $subject_key, $channel, $identity_key, array $args ) {
		$request_id = isset( $args['request_id'] ) ? trim( (string) $args['request_id'] ) : '';
		if ( '' === $request_id ) {
			$request_id = $this->random_id();
		}
		if ( is_wp_error( $request_id ) ) {
			return $request_id;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $request_id ) ) {
			return new WP_Error( 'yobm_otp_invalid_request_id', __( 'The verification request is invalid. Please refresh and try again.', 'wc-blacklist-manager' ) );
		}
		foreach ( array( 'expected_revision', 'expected_generation' ) as $expected_key ) {
			if ( isset( $args[ $expected_key ] ) && '' !== (string) $args[ $expected_key ] && ! preg_match( '/^\d{1,18}$/', (string) $args[ $expected_key ] ) ) {
				return new WP_Error( 'yobm_otp_invalid_expected_state', __( 'The verification state is invalid. Please refresh and try again.', 'wc-blacklist-manager' ) );
			}
		}
		$revision   = isset( $args['expected_revision'] ) && '' !== (string) $args['expected_revision'] ? max( 0, (int) $args['expected_revision'] ) : null;
		$generation = isset( $args['expected_generation'] ) && '' !== (string) $args['expected_generation'] ? max( 0, (int) $args['expected_generation'] ) : null;
		$challenge  = isset( $args['expected_challenge_id'] ) ? trim( (string) $args['expected_challenge_id'] ) : '';
		if ( '' !== $challenge && ! preg_match( '/^[a-f0-9]{32}$/i', $challenge ) ) {
			return new WP_Error( 'yobm_otp_invalid_challenge_id', __( 'The verification challenge is invalid. Please refresh and try again.', 'wc-blacklist-manager' ) );
		}
		$binding    = implode( '|', array( $subject_key, $channel, $request_id, $operation, $identity_key, null === $revision ? '-' : $revision, null === $generation ? '-' : $generation, $challenge ) );
		return array(
			'request_id'   => $request_id,
			'revision'     => $revision,
			'generation'   => $generation,
			'challenge_id' => $challenge,
			'fingerprint'  => $this->hmac( 'request', $binding ),
		);
	}

	private function retry_result( $row, $controls ) {
		if ( empty( $row['last_request_id'] ) || ! hash_equals( (string) $row['last_request_id'], $controls['request_id'] ) ) {
			return null;
		}
		if ( empty( $row['last_request_fingerprint'] ) || ! hash_equals( (string) $row['last_request_fingerprint'], $controls['fingerprint'] ) ) {
			return new WP_Error( 'yobm_otp_request_context_mismatch', __( 'The verification request no longer matches the active challenge.', 'wc-blacklist-manager' ) );
		}
		return array( 'retry' => true, 'result' => isset( $row['last_request_result'] ) ? $row['last_request_result'] : '' );
	}

	private function validate_expected( $row, $controls ) {
		if ( null !== $controls['revision'] && (int) $row['revision'] !== $controls['revision'] ) {
			return new WP_Error( 'yobm_otp_stale_revision', __( 'Verification state changed. Please try again.', 'wc-blacklist-manager' ) );
		}
		if ( null !== $controls['generation'] && (int) $row['generation'] !== $controls['generation'] ) {
			return new WP_Error( 'yobm_otp_stale_generation', __( 'The verification code was replaced. Please use the latest code.', 'wc-blacklist-manager' ) );
		}
		if ( '' !== $controls['challenge_id'] && ! hash_equals( (string) $row['challenge_id'], $controls['challenge_id'] ) ) {
			return new WP_Error( 'yobm_otp_stale_challenge', __( 'The verification code was replaced. Please use the latest code.', 'wc-blacklist-manager' ) );
		}
		return true;
	}

	private function verifier( $channel, $challenge_id, $generation, $identity_key, $code ) {
		$value = implode( '|', array( 'v1', $channel, $challenge_id, (int) $generation, $identity_key, (string) $code ) );
		return hash_hmac( 'sha256', $value, $this->key( 'otp-verifier' ) );
	}

	private function increment_rate( $channel, $purpose, $value, $limit ) {
		$limit = max( 1, (int) $limit );
		$now   = $this->now();
		$start = $now - ( $now % self::RATE_WINDOW );
		$key   = $this->hmac( 'rate:' . $purpose, $channel . '|' . $value );
		global $wpdb;
		$table = WC_Blacklist_Manager_OTP_Schema::rate_table();
		$sql   = $wpdb->prepare(
			"INSERT INTO {$table} (dimension_key,channel,window_start,secret_fingerprint,request_count,expires_at,created_at) VALUES (%s,%s,%d,%s,1,%d,%d) ON DUPLICATE KEY UPDATE request_count = IF(secret_fingerprint = VALUES(secret_fingerprint) AND request_count < %d, request_count + 1, request_count)",
			$key,
			$channel,
			$start,
			$this->secret_fingerprint(),
			$start + self::RATE_WINDOW,
			$now,
			$limit
		);
		$result = $wpdb->query( $sql );
		return false !== $result && 0 !== (int) $result;
	}

	private function release_rate( $channel, $purpose, $value ) {
		$now   = $this->now();
		$start = $now - ( $now % self::RATE_WINDOW );
		$key   = $this->hmac( 'rate:' . $purpose, $channel . '|' . $value );
		global $wpdb;
		$table = WC_Blacklist_Manager_OTP_Schema::rate_table();
		$sql   = $wpdb->prepare(
			"UPDATE {$table} SET request_count = request_count - 1 WHERE dimension_key=%s AND window_start=%d AND secret_fingerprint=%s AND request_count > 0",
			$key,
			$start,
			$this->secret_fingerprint()
		);
		return 1 === (int) $wpdb->query( $sql );
	}

	public function project( $channel, $identity ) {
		$channel = sanitize_key( (string) $channel );
		if ( ! $this->is_ready() ) {
			return new WP_Error( 'yobm_otp_schema_unavailable', __( 'Verification is temporarily unavailable. Please try again shortly.', 'wc-blacklist-manager' ) );
		}
		$subject = $this->resolve_subject();
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}
		$row          = $this->expire_row( $this->get_row( $subject['key'], $channel ) );
		$identity_key = $this->identity_key( $channel, $identity );
		return $this->projection( $row, $identity_key );
	}

	private function projection( $row, $identity_key ) {
		$now      = $this->now();
		$verified = ! empty( $row['proof_id'] ) && ! empty( $row['proof_identity_key'] )
			&& hash_equals( (string) $row['proof_identity_key'], $identity_key ) && (int) $row['proof_expires_at'] > $now;
		$pending  = ! empty( $row['challenge_id'] ) && ! empty( $row['otp_verifier'] )
			&& ! empty( $row['identity_key'] ) && hash_equals( (string) $row['identity_key'], $identity_key )
			&& (int) $row['challenge_expires_at'] > $now;
		$status = $verified ? 'verified' : ( $pending ? (string) $row['challenge_status'] : 'required' );
		if ( $pending && 'sending' === $status && (int) $row['operation_expires_at'] <= $now ) {
			$status = 'uncertain';
		}
		return array(
			'verified'            => $verified,
			'pending'             => $pending,
			'status'              => $status,
			'revision'            => isset( $row['revision'] ) ? (int) $row['revision'] : 0,
			'generation'          => isset( $row['generation'] ) ? (int) $row['generation'] : 0,
			'challenge_id'        => $pending ? (string) $row['challenge_id'] : '',
			'identity_token'      => $this->hmac( 'identity-token', $identity_key ),
			'resend_available_at' => $pending ? (int) $row['resend_available_at'] : 0,
			'retry_after'         => $pending && 'sending' === (string) $row['challenge_status'] ? max( 0, (int) $row['operation_expires_at'] - $now ) : 0,
			'proof_id'            => $verified ? (string) $row['proof_id'] : '',
			'proof_verified_at'   => $verified ? (int) $row['proof_verified_at'] : 0,
			'proof_expires_at'    => $verified ? (int) $row['proof_expires_at'] : 0,
		);
	}

	public function reserve_dispatch( $channel, $identity, array $args = array() ) {
		$channel  = sanitize_key( (string) $channel );
		$identity = trim( (string) $identity );
		if ( '' === $identity || ! $this->is_ready() ) {
			return new WP_Error( 'yobm_otp_unavailable', __( 'Verification is temporarily unavailable. Please try again shortly.', 'wc-blacklist-manager' ) );
		}
		$subject = $this->resolve_subject();
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}
		$identity_key = $this->identity_key( $channel, $identity );
		$operation    = ! empty( $args['resend'] ) ? 'resend' : 'issue';
		$controls     = $this->controls( $operation, $subject['key'], $channel, $identity_key, $args );
		if ( is_wp_error( $controls ) ) {
			return $controls;
		}
		$this->ensure_row( $subject['key'], $channel );
		$row   = $this->expire_row( $this->get_row( $subject['key'], $channel ) );
		$retry = $this->retry_result( $row, $controls );
		if ( is_wp_error( $retry ) ) {
			return $retry;
		}
		if ( is_array( $retry ) ) {
			return array(
				'dispatch'         => false,
				'idempotent'       => true,
				'operation_result' => $retry['result'],
				'state'            => $this->projection( $row, $identity_key ),
			);
		}

		$expected = $this->validate_expected( $row, $controls );
		if ( is_wp_error( $expected ) ) {
			return $expected;
		}
		$now = $this->now();
		if ( ! empty( $row['identity_key'] ) && ! hash_equals( (string) $row['identity_key'], $identity_key ) ) {
			$this->invalidate_row( $row );
			$row = $this->get_row( $subject['key'], $channel );
		}
		$active = ! empty( $row['challenge_id'] ) && ! empty( $row['otp_verifier'] ) && (int) $row['challenge_expires_at'] > $now;
		if ( 'issue' === $operation && $active ) {
			return array( 'dispatch' => false, 'idempotent' => true, 'state' => $this->projection( $row, $identity_key ) );
		}
		if ( ! empty( $row['operation_token'] ) && (int) $row['operation_expires_at'] > $now ) {
			return new WP_Error( 'yobm_otp_busy', __( 'A verification request is already in progress. Please wait a moment.', 'wc-blacklist-manager' ), array( 'retry_after' => (int) $row['operation_expires_at'] - $now ) );
		}
		if ( 'resend' === $operation ) {
			if ( ! $active ) {
				return new WP_Error( 'yobm_otp_missing_challenge', __( 'No verification code was found. Please request a new one.', 'wc-blacklist-manager' ) );
			}
			if ( (int) $row['resend_available_at'] > $now ) {
				return new WP_Error( 'yobm_otp_resend_cooldown', __( 'Please wait before requesting a new code.', 'wc-blacklist-manager' ), array( 'retry_after' => (int) $row['resend_available_at'] - $now ) );
			}
			$resend_limit = isset( $args['resend_limit'] ) ? max( 1, min( 10, (int) $args['resend_limit'] ) ) : 0;
			if ( $resend_limit > 0 && (int) $row['resend_count'] >= $resend_limit ) {
				return new WP_Error( 'yobm_otp_resend_limited', __( 'You have reached the resend limit. Please contact support.', 'wc-blacklist-manager' ) );
			}
		}

		$code_length = max( 6, min( 10, isset( $args['code_length'] ) ? (int) $args['code_length'] : 6 ) );
		try {
			$code = (string) random_int( (int) pow( 10, $code_length - 1 ), (int) pow( 10, $code_length ) - 1 );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'yobm_otp_entropy_unavailable', __( 'Verification is temporarily unavailable. Please try again shortly.', 'wc-blacklist-manager' ) );
		}
		$challenge_id = $this->random_id();
		$token        = $this->random_id();
		if ( is_wp_error( $challenge_id ) ) {
			return $challenge_id;
		}
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$generation   = (int) $row['generation'] + 1;
		$cooldown     = max( 30, min( 3600, isset( $args['cooldown'] ) ? (int) $args['cooldown'] : 60 ) );
		$lease        = max( 5, min( 120, (int) apply_filters( 'wc_blacklist_manager_otp_operation_lease', self::LEASE_SECONDS, $channel ) ) );
		$resends      = 'resend' === $operation ? (int) $row['resend_count'] + 1 : 0;

		$identity_limit = max( 1, isset( $args['identity_rate_limit'] ) ? (int) $args['identity_rate_limit'] : 5 );
		$rate_ok        = $this->increment_rate( $channel, 'send-identity', $identity_key, $identity_limit );
		$ip             = $this->canonical_ip();
		$ip_key         = '';
		if ( $rate_ok && '' !== $ip ) {
			$ip_key  = $this->hmac( 'ip', $ip );
			$rate_ok = $this->increment_rate( $channel, 'send-ip', $ip_key, isset( $args['ip_rate_limit'] ) ? (int) $args['ip_rate_limit'] : 20 );
		}
		if ( ! $rate_ok ) {
			if ( '' !== $ip_key ) {
				$this->release_rate( $channel, 'send-identity', $identity_key );
			}
			return new WP_Error( 'yobm_otp_rate_limited', __( 'Too many verification code requests. Please wait before trying again.', 'wc-blacklist-manager' ) );
		}

		global $wpdb;
		$table = WC_Blacklist_Manager_OTP_Schema::state_table();
		$sql   = $wpdb->prepare(
			"UPDATE {$table} SET secret_fingerprint=%s,identity_key=%s,revision=revision+1,generation=%d,challenge_id=%s,otp_verifier=%s,challenge_status='sending',challenge_issued_at=%d,challenge_expires_at=%d,resend_available_at=%d,attempt_count=0,resend_count=%d,proof_id='',proof_identity_key='',proof_verified_at=0,proof_expires_at=0,last_request_id=%s,last_request_fingerprint=%s,last_request_operation=%s,last_request_result='reserved',operation_token=%s,operation_expires_at=%d,updated_at=%d WHERE subject_key=%s AND channel=%s AND revision=%d",
			$this->secret_fingerprint(), $identity_key, $generation, $challenge_id,
			$this->verifier( $channel, $challenge_id, $generation, $identity_key, $code ),
			$now, $now + self::CHALLENGE_TTL, $now + $cooldown, $resends,
			$controls['request_id'], $controls['fingerprint'], $operation, $token, $now + $lease, $now,
			$subject['key'], $channel, (int) $row['revision']
		);
		if ( 1 !== (int) $wpdb->query( $sql ) ) {
			$this->release_rate( $channel, 'send-identity', $identity_key );
			if ( '' !== $ip_key ) {
				$this->release_rate( $channel, 'send-ip', $ip_key );
			}
			return new WP_Error( 'yobm_otp_conflict', __( 'Verification state changed. Please try again.', 'wc-blacklist-manager' ) );
		}

		$reservation = array(
			'subject_key' => $subject['key'], 'channel' => $channel, 'identity_key' => $identity_key,
			'challenge_id' => $challenge_id, 'generation' => $generation, 'operation_token' => $token,
			'request_id' => $controls['request_id'], 'code' => $code, 'dispatch' => true,
		);
		return $reservation;
	}

	public function finalize_dispatch( array $reservation, $outcome ) {
		$outcome = in_array( $outcome, array( 'success', 'failed', 'uncertain' ), true ) ? $outcome : 'uncertain';
		$status  = 'success' === $outcome ? 'sent' : $outcome;
		global $wpdb;
		$table = WC_Blacklist_Manager_OTP_Schema::state_table();
		$clear = 'failed' === $outcome ? ",otp_verifier='',challenge_expires_at=0,resend_available_at=0" : '';
		$sql   = $wpdb->prepare(
			"UPDATE {$table} SET challenge_status=%s,last_request_result=%s,operation_token='',operation_expires_at=0,revision=revision+1,updated_at=%d{$clear} WHERE subject_key=%s AND channel=%s AND challenge_id=%s AND generation=%d AND operation_token=%s",
			$status, $status, $this->now(), $reservation['subject_key'], $reservation['channel'], $reservation['challenge_id'], (int) $reservation['generation'], $reservation['operation_token']
		);
		$result = $wpdb->query( $sql );
		return 1 === (int) $result ? $this->projection( $this->get_row( $reservation['subject_key'], $reservation['channel'] ), $reservation['identity_key'] ) : false;
	}

	private function invalidate_row( $row ) {
		global $wpdb;
		$table = WC_Blacklist_Manager_OTP_Schema::state_table();
		return $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET identity_key='',revision=revision+1,challenge_id='',otp_verifier='',challenge_status='',challenge_issued_at=0,challenge_expires_at=0,resend_available_at=0,attempt_count=0,resend_count=0,proof_id='',proof_identity_key='',proof_verified_at=0,proof_expires_at=0,last_request_id='',last_request_fingerprint='',last_request_operation='',last_request_result='',operation_token='',operation_expires_at=0,updated_at=%d WHERE subject_key=%s AND channel=%s AND revision=%d",
			$this->now(), $row['subject_key'], $row['channel'], (int) $row['revision']
		) );
	}

	private function store_request_error( $row, $controls, $result ) {
		global $wpdb;
		$table = WC_Blacklist_Manager_OTP_Schema::state_table();
		return $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET last_request_id=%s,last_request_fingerprint=%s,last_request_operation='verify',last_request_result=%s,updated_at=%d WHERE subject_key=%s AND channel=%s AND revision=%d",
			$controls['request_id'], $controls['fingerprint'], $result, $this->now(), $row['subject_key'], $row['channel'], (int) $row['revision']
		) );
	}

	public function verify( $channel, $identity, $submitted_code, array $args = array() ) {
		$channel      = sanitize_key( (string) $channel );
		$identity     = trim( (string) $identity );
		$submitted_code = trim( (string) $submitted_code );
		if ( '' === $identity || ! $this->is_ready() ) {
			return new WP_Error( 'yobm_otp_unavailable', __( 'Verification is temporarily unavailable. Please try again shortly.', 'wc-blacklist-manager' ) );
		}
		$subject = $this->resolve_subject();
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}
		$identity_key = $this->identity_key( $channel, $identity );
		$controls     = $this->controls( 'verify', $subject['key'], $channel, $identity_key, $args );
		if ( is_wp_error( $controls ) ) {
			return $controls;
		}
		$row   = $this->expire_row( $this->get_row( $subject['key'], $channel ) );
		$retry = $this->retry_result( $row, $controls );
		if ( is_wp_error( $retry ) ) {
			return $retry;
		}
		if ( is_array( $retry ) ) {
			if ( 'verified' === $retry['result'] ) {
				$projection = $this->projection( $row, $identity_key );
				if ( ! empty( $projection['verified'] ) && ! empty( $row['proof_id'] ) ) {
					return array( 'transitioned' => false, 'state' => $projection, 'proof_id' => (string) $row['proof_id'] );
				}
				return new WP_Error( 'yobm_otp_proof_expired', __( 'The verification proof expired. Please request a new code.', 'wc-blacklist-manager' ) );
			}
			return new WP_Error( 'yobm_otp_' . sanitize_key( $retry['result'] ), __( 'The verification request was not accepted.', 'wc-blacklist-manager' ) );
		}

		$ip = $this->canonical_ip();
		if ( '' !== $ip && ! $this->increment_rate( $channel, 'verify-ip', $this->hmac( 'ip', $ip ), 5 ) ) {
			return new WP_Error( 'yobm_otp_verify_rate_limited', __( 'Too many attempts. Please try again later.', 'wc-blacklist-manager' ) );
		}
		if ( empty( $row['challenge_id'] ) || empty( $row['otp_verifier'] ) ) {
			return new WP_Error( 'yobm_otp_missing_challenge', __( 'No verification code was found. Please request a new one.', 'wc-blacklist-manager' ) );
		}
		$expected = $this->validate_expected( $row, $controls );
		if ( is_wp_error( $expected ) ) {
			return $expected;
		}
		if ( empty( $row['identity_key'] ) || ! hash_equals( (string) $row['identity_key'], $identity_key ) ) {
			$this->invalidate_row( $row );
			return new WP_Error( 'yobm_otp_identity_changed', __( 'The verification destination changed. Please request a new code.', 'wc-blacklist-manager' ) );
		}
		if ( (int) $row['challenge_expires_at'] <= $this->now() ) {
			return new WP_Error( 'yobm_otp_expired', __( 'Code expired. Please request a new one.', 'wc-blacklist-manager' ) );
		}
		if ( ! preg_match( '/^\d{6,10}$/', $submitted_code ) ) {
			$this->store_request_error( $row, $controls, 'missing_data' );
			return new WP_Error( 'yobm_otp_missing_data', __( 'Missing verification data. Please try again.', 'wc-blacklist-manager' ) );
		}

		$expected_verifier = $this->verifier( $channel, $row['challenge_id'], (int) $row['generation'], $identity_key, $submitted_code );
		global $wpdb;
		$table = WC_Blacklist_Manager_OTP_Schema::state_table();
		if ( ! hash_equals( (string) $row['otp_verifier'], $expected_verifier ) ) {
			$next   = (int) $row['attempt_count'] + 1;
			$result = $next >= self::MAX_ATTEMPTS ? 'attempt_limited' : 'invalid_code';
			$clear  = $next >= self::MAX_ATTEMPTS ? ",otp_verifier='',challenge_status='exhausted',challenge_expires_at=0" : '';
			$sql    = $wpdb->prepare(
				"UPDATE {$table} SET attempt_count=attempt_count+1,revision=revision+1,last_request_id=%s,last_request_fingerprint=%s,last_request_operation='verify',last_request_result=%s,updated_at=%d{$clear} WHERE subject_key=%s AND channel=%s AND revision=%d AND challenge_id=%s AND generation=%d AND attempt_count < %d",
				$controls['request_id'], $controls['fingerprint'], $result, $this->now(), $subject['key'], $channel, (int) $row['revision'], $row['challenge_id'], (int) $row['generation'], self::MAX_ATTEMPTS
			);
			if ( 1 !== (int) $wpdb->query( $sql ) ) {
				return new WP_Error( 'yobm_otp_conflict', __( 'Verification state changed. Please try again.', 'wc-blacklist-manager' ) );
			}
			return new WP_Error( 'yobm_otp_' . $result, $next >= self::MAX_ATTEMPTS ? __( 'Too many failed attempts. Please request a new verification code.', 'wc-blacklist-manager' ) : __( 'Invalid code. Please try again.', 'wc-blacklist-manager' ) );
		}

		$proof_id = $this->random_id();
		if ( is_wp_error( $proof_id ) ) {
			return $proof_id;
		}
		$now      = $this->now();
		$sql      = $wpdb->prepare(
			"UPDATE {$table} SET revision=revision+1,challenge_id='',otp_verifier='',challenge_status='',challenge_issued_at=0,challenge_expires_at=0,resend_available_at=0,attempt_count=0,operation_token='',operation_expires_at=0,proof_id=%s,proof_identity_key=%s,proof_verified_at=%d,proof_expires_at=%d,last_request_id=%s,last_request_fingerprint=%s,last_request_operation='verify',last_request_result='verified',updated_at=%d WHERE subject_key=%s AND channel=%s AND revision=%d AND challenge_id=%s AND generation=%d AND otp_verifier=%s",
			$proof_id, $identity_key, $now, $now + self::PROOF_TTL, $controls['request_id'], $controls['fingerprint'], $now,
			$subject['key'], $channel, (int) $row['revision'], $row['challenge_id'], (int) $row['generation'], $row['otp_verifier']
		);
		if ( 1 !== (int) $wpdb->query( $sql ) ) {
			return new WP_Error( 'yobm_otp_conflict', __( 'Verification state changed. Please try again.', 'wc-blacklist-manager' ) );
		}
		return array( 'transitioned' => true, 'proof_id' => $proof_id, 'state' => $this->projection( $this->get_row( $subject['key'], $channel ), $identity_key ) );
	}

	public function cleanup_proof( $channel, $identity, $proof_id ) {
		if ( ! $this->is_ready() || '' === (string) $proof_id ) {
			return false;
		}
		$channel      = sanitize_key( $channel );
		$identity_key = $this->identity_key( $channel, $identity );
		global $wpdb;
		$table = WC_Blacklist_Manager_OTP_Schema::state_table();
		$sql   = $wpdb->prepare(
			"UPDATE {$table} SET proof_id='',proof_identity_key='',proof_verified_at=0,proof_expires_at=0,revision=revision+1,updated_at=%d WHERE channel=%s AND proof_id=%s AND proof_identity_key=%s",
			$this->now(), $channel, $proof_id, $identity_key
		);
		return 1 === (int) $wpdb->query( $sql );
	}

	public function import_legacy( $channel, $identity, array $legacy ) {
		if ( ! $this->is_ready() ) {
			return new WP_Error( 'yobm_otp_schema_unavailable', __( 'Verification is temporarily unavailable.', 'wc-blacklist-manager' ) );
		}
		$subject = $this->resolve_subject();
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}
		$channel      = sanitize_key( $channel );
		$identity_key = $this->identity_key( $channel, $identity );
		$existing     = $this->get_row( $subject['key'], $channel );
		if ( ! empty( $existing ) && ( ! empty( $existing['challenge_id'] ) || ! empty( $existing['proof_id'] ) || (int) $existing['generation'] > 0 ) ) {
			$this->invalidate_row( $existing );
			return new WP_Error( 'yobm_otp_legacy_conflict', __( 'Verification state conflicted with an older session. Please verify again.', 'wc-blacklist-manager' ) );
		}
		$sent_at = isset( $legacy['sent_at'] ) ? (int) $legacy['sent_at'] : 0;
		$expires = $sent_at + self::CHALLENGE_TTL;
		if ( $sent_at <= 0 || $expires <= $this->now() ) {
			return new WP_Error( 'yobm_otp_legacy_expired', __( 'The previous verification code expired.', 'wc-blacklist-manager' ) );
		}
		if ( ! $this->ensure_row( $subject['key'], $channel ) ) {
			return new WP_Error( 'yobm_otp_legacy_persistence_failed', __( 'The previous verification state could not be migrated. Please try again.', 'wc-blacklist-manager' ) );
		}
		$row = $this->get_row( $subject['key'], $channel );
		if ( empty( $row ) ) {
			return new WP_Error( 'yobm_otp_legacy_persistence_failed', __( 'The previous verification state could not be migrated. Please try again.', 'wc-blacklist-manager' ) );
		}
		$now = $this->now();
		global $wpdb;
		$table = WC_Blacklist_Manager_OTP_Schema::state_table();
		if ( ! empty( $legacy['verified'] ) ) {
			$proof_id = $this->random_id();
			if ( is_wp_error( $proof_id ) ) {
				return $proof_id;
			}
			$sql = $wpdb->prepare(
				"UPDATE {$table} SET secret_fingerprint=%s,identity_key=%s,revision=revision+1,generation=1,proof_id=%s,proof_identity_key=%s,proof_verified_at=%d,proof_expires_at=%d,updated_at=%d WHERE subject_key=%s AND channel=%s AND revision=%d AND generation=0",
				$this->secret_fingerprint(), $identity_key, $proof_id, $identity_key, $sent_at, $expires, $now, $subject['key'], $channel, (int) $row['revision']
			);
		} else {
			$code = isset( $legacy['code'] ) ? trim( (string) $legacy['code'] ) : '';
			if ( ! preg_match( '/^\d{6,10}$/', $code ) ) {
				return new WP_Error( 'yobm_otp_legacy_malformed', __( 'The previous verification state was invalid.', 'wc-blacklist-manager' ) );
			}
			$challenge_id = $this->random_id();
			if ( is_wp_error( $challenge_id ) ) {
				return $challenge_id;
			}
			$sql = $wpdb->prepare(
				"UPDATE {$table} SET secret_fingerprint=%s,identity_key=%s,revision=revision+1,generation=1,challenge_id=%s,otp_verifier=%s,challenge_status='sent',challenge_issued_at=%d,challenge_expires_at=%d,resend_available_at=%d,attempt_count=%d,resend_count=%d,updated_at=%d WHERE subject_key=%s AND channel=%s AND revision=%d AND generation=0",
				$this->secret_fingerprint(), $identity_key, $challenge_id, $this->verifier( $channel, $challenge_id, 1, $identity_key, $code ),
				$sent_at, $expires, min( $expires, isset( $legacy['resend_available_at'] ) ? (int) $legacy['resend_available_at'] : $sent_at ),
				min( self::MAX_ATTEMPTS, isset( $legacy['verify_attempts'] ) ? (int) $legacy['verify_attempts'] : 0 ),
				min( 10, isset( $legacy['resend_count'] ) ? (int) $legacy['resend_count'] : 0 ), $now,
				$subject['key'], $channel, (int) $row['revision']
			);
		}
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			return new WP_Error( 'yobm_otp_legacy_persistence_failed', __( 'The previous verification state could not be migrated. Please try again.', 'wc-blacklist-manager' ) );
		}

		return 1 === (int) $result ? true : new WP_Error( 'yobm_otp_legacy_conflict', __( 'Verification state changed. Please verify again.', 'wc-blacklist-manager' ) );
	}

	public function legacy_import_disposition( $result ) {
		if ( true === $result ) {
			return 'persisted';
		}
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'yobm_otp_legacy_expired', 'yobm_otp_legacy_malformed', 'yobm_otp_legacy_conflict' ), true ) ) {
			return 'terminal';
		}

		return 'retryable';
	}

	public function cleanup_expired( $limit = 200 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		$now   = $this->now();
		$state = WC_Blacklist_Manager_OTP_Schema::state_table();
		$rate  = WC_Blacklist_Manager_OTP_Schema::rate_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$rate} WHERE expires_at <= %d ORDER BY expires_at ASC LIMIT %d", $now, $limit ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$state} WHERE challenge_expires_at > 0 AND challenge_expires_at <= %d AND proof_expires_at = 0 AND operation_expires_at <= %d ORDER BY challenge_expires_at ASC LIMIT %d", $now, $now, $limit ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$state} WHERE proof_expires_at > 0 AND proof_expires_at <= %d AND challenge_expires_at = 0 AND operation_expires_at <= %d ORDER BY proof_expires_at ASC LIMIT %d", $now, $now, $limit ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$state} WHERE challenge_expires_at = 0 AND proof_expires_at = 0 AND operation_expires_at <= %d AND updated_at <= %d ORDER BY updated_at ASC LIMIT %d", $now, $now - self::CHALLENGE_TTL, $limit ) );
	}
}

function wc_blacklist_manager_otp_state() {
	return WC_Blacklist_Manager_OTP_State::instance();
}

WC_Blacklist_Manager_OTP_Schema::init();
