<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lifecycle for the internal Phase 4 evidence/trust schema.
 *
 * Ordinary frontend paths may inspect readiness and schedule repair, but they
 * never execute DDL or generate the durable evidence key.
 */
final class WC_Blacklist_Manager_Evidence_Trust_Schema {
	const GENERATION     = 1;
	const VERSION_OPTION = 'wc_blacklist_manager_evidence_schema_generation';
	const KEY_OPTION     = 'wc_blacklist_manager_evidence_key_v1';
	const REPAIR_HOOK    = 'wc_blacklist_manager_evidence_schema_repair_v1';
	const REPAIR_LOCK    = 'wc_blacklist_manager_evidence_schema_repair_lock_v1';

	private static $ready_cache = null;

	public static function init() {
		register_activation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, array( __CLASS__, 'activate' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ), 7 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
		add_action( self::REPAIR_HOOK, array( __CLASS__, 'install' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wc_blacklist_evidence_trust';
	}

	public static function activate() {
		self::install();
	}

	public static function after_upgrade( $upgrader, $options ) {
		if (
			empty( $options['plugins'] )
			|| ! is_array( $options['plugins'] )
			|| ! in_array( WC_BLACKLIST_MANAGER_PLUGIN_BASENAME, $options['plugins'], true )
		) {
			return;
		}

		self::install();
	}

	public static function maybe_install() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::is_ready( true ) ) {
			self::install();
		}
	}

	public static function schedule_repair() {
		if ( self::is_ready() || wp_next_scheduled( self::REPAIR_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::REPAIR_HOOK );
	}

	public static function evidence_key() {
		$key = (string) get_option( self::KEY_OPTION, '' );
		return preg_match( '/^[a-f0-9]{64}$/', $key ) ? $key : '';
	}

	public static function is_ready( $refresh = false ) {
		if ( ! $refresh && null !== self::$ready_cache ) {
			return self::$ready_cache;
		}

		if ( self::GENERATION !== (int) get_option( self::VERSION_OPTION, 0 ) || '' === self::evidence_key() ) {
			self::$ready_cache = false;
			return false;
		}

		self::$ready_cache = self::schema_matches();
		return self::$ready_cache;
	}

	public static function install() {
		if ( get_transient( self::REPAIR_LOCK ) ) {
			return false;
		}

		set_transient( self::REPAIR_LOCK, 1, 2 * MINUTE_IN_SECONDS );
		$key_ready = self::install_key();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			contract_version tinyint(3) unsigned NOT NULL DEFAULT 1,
			identity_key char(64) NOT NULL,
			channel varchar(16) NOT NULL,
			category varchar(32) NOT NULL,
			source varchar(32) NOT NULL,
			policy varchar(32) NOT NULL DEFAULT '',
			first_observed_at bigint(20) unsigned NOT NULL DEFAULT 0,
			latest_observed_at bigint(20) unsigned NOT NULL DEFAULT 0,
			expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
			revoked_at bigint(20) unsigned NOT NULL DEFAULT 0,
			source_ref varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY semantic_identity (identity_key,channel,category,source,policy),
			KEY active_lookup (identity_key,channel,category,policy,revoked_at,expires_at)
		) {$charset};";

		dbDelta( $sql );
		self::$ready_cache = null;

		$ready = $key_ready && self::schema_matches();
		if ( $ready ) {
			update_option( self::VERSION_OPTION, self::GENERATION, false );
			self::$ready_cache = true;
		} else {
			delete_option( self::VERSION_OPTION );
			self::$ready_cache = false;
		}

		delete_transient( self::REPAIR_LOCK );
		return $ready;
	}

	private static function install_key() {
		$key = self::evidence_key();
		if ( '' === $key ) {
			try {
				$key = bin2hex( random_bytes( 32 ) );
			} catch ( Exception $exception ) {
				return false;
			}

			if ( false === get_option( self::KEY_OPTION, false ) ) {
				if ( ! add_option( self::KEY_OPTION, $key, '', 'no' ) ) {
					return false;
				}
			} elseif ( ! update_option( self::KEY_OPTION, $key, false ) ) {
				return false;
			}
		}

		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => self::KEY_OPTION ), array( '%s' ), array( '%s' ) );

		return '' !== self::evidence_key();
	}

	private static function schema_matches() {
		global $wpdb;
		$table = self::table();
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return false;
		}

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		$required_columns = array(
			'id', 'contract_version', 'identity_key', 'channel', 'category', 'source', 'policy',
			'first_observed_at', 'latest_observed_at', 'expires_at', 'revoked_at', 'source_ref',
		);
		if ( array_diff( $required_columns, is_array( $columns ) ? $columns : array() ) ) {
			return false;
		}

		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		$found   = array();
		foreach ( is_array( $indexes ) ? $indexes : array() as $index ) {
			$name = isset( $index['Key_name'] ) ? (string) $index['Key_name'] : '';
			if ( in_array( $name, array( 'semantic_identity', 'active_lookup' ), true ) ) {
				$found[ $name ] = true;
			}
		}

		return isset( $found['semantic_identity'], $found['active_lookup'] );
	}
}

/** Internal, finite evidence/trust semantic contract v1. */
final class WC_Blacklist_Manager_Evidence_Trust {
	const VERSION = 1;

	const CATEGORY_AUDIT     = 'verification_audit';
	const CATEGORY_TRUST     = 'customer_order_trust';
	const CATEGORY_EXEMPTION = 'policy_exemption';

	const SOURCE_OTP_TRANSITION = 'otp_transition';
	const SOURCE_COMPLETED_ORDER = 'completed_order';

	const POLICY_REPEAT             = 'repeat_verification';
	const POLICY_EMAIL_VALIDATION   = 'email_validation';
	const POLICY_SUSPECT_RESOLUTION = 'suspect_resolution';

	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function is_ready() {
		$ready = WC_Blacklist_Manager_Evidence_Trust_Schema::is_ready();
		if ( ! $ready ) {
			WC_Blacklist_Manager_Evidence_Trust_Schema::schedule_repair();
		}
		return $ready;
	}

	public function upsert( $channel, $identity, $category, $source, $policy = '', $source_ref = '', $observed_at = 0, $expires_at = 0 ) {
		$values = $this->validated_semantics( $channel, $identity, $category, $source, $policy );
		if ( ! $values || ! $this->is_ready() ) {
			return false;
		}

		$identity_key = $this->identity_key( $values['channel'], $values['identity'] );
		if ( '' === $identity_key ) {
			return false;
		}

		$now        = $observed_at > 0 ? absint( $observed_at ) : time();
		$expires_at = absint( $expires_at );
		$source_ref = preg_match( '/^[a-z0-9:_-]{0,64}$/', (string) $source_ref ) ? (string) $source_ref : '';

		global $wpdb;
		$table = WC_Blacklist_Manager_Evidence_Trust_Schema::table();
		$sql   = $wpdb->prepare(
			"INSERT INTO {$table}
				(contract_version,identity_key,channel,category,source,policy,first_observed_at,latest_observed_at,expires_at,revoked_at,source_ref)
			VALUES (%d,%s,%s,%s,%s,%s,%d,%d,%d,0,%s)
			ON DUPLICATE KEY UPDATE
				contract_version=VALUES(contract_version),
				first_observed_at=LEAST(first_observed_at,VALUES(first_observed_at)),
				expires_at=CASE
					WHEN VALUES(latest_observed_at)>latest_observed_at THEN VALUES(expires_at)
					WHEN VALUES(latest_observed_at)=latest_observed_at AND VALUES(source_ref)>source_ref THEN VALUES(expires_at)
					ELSE expires_at
				END,
				source_ref=CASE
					WHEN VALUES(latest_observed_at)>latest_observed_at THEN VALUES(source_ref)
					WHEN VALUES(latest_observed_at)=latest_observed_at THEN GREATEST(source_ref,VALUES(source_ref))
					ELSE source_ref
				END,
				latest_observed_at=GREATEST(latest_observed_at,VALUES(latest_observed_at)),
				revoked_at=0",
			self::VERSION,
			$identity_key,
			$values['channel'],
			$values['category'],
			$values['source'],
			$values['policy'],
			$now,
			$now,
			$expires_at,
			$source_ref
		);

		return false !== $wpdb->query( $sql );
	}

	public function record_otp_transition( $channel, $identity, $proof_id, $verified_at, $suspect_resolved = false ) {
		$proof_id   = strtolower( (string) $proof_id );
		$verified_at = absint( $verified_at );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $proof_id ) || 0 === $verified_at ) {
			return false;
		}

		$proof_ref = $this->correlation_key( 'proof', $proof_id );
		$results   = array(
			$this->upsert( $channel, $identity, self::CATEGORY_AUDIT, self::SOURCE_OTP_TRANSITION, '', $proof_ref, $verified_at ),
			$this->upsert( $channel, $identity, self::CATEGORY_EXEMPTION, self::SOURCE_OTP_TRANSITION, self::POLICY_REPEAT, $proof_ref, $verified_at ),
		);
		if ( 'email' === $channel ) {
			$results[] = $this->upsert( $channel, $identity, self::CATEGORY_EXEMPTION, self::SOURCE_OTP_TRANSITION, self::POLICY_EMAIL_VALIDATION, $proof_ref, $verified_at );
		}
		if ( $suspect_resolved ) {
			$results[] = $this->upsert( $channel, $identity, self::CATEGORY_EXEMPTION, self::SOURCE_OTP_TRANSITION, self::POLICY_SUSPECT_RESOLUTION, $proof_ref, $verified_at );
		}

		return ! in_array( false, $results, true );
	}

	public function record_completed_order_trust( $channel, $identity, $order_id, $observed_at = 0 ) {
		$order_id = absint( $order_id );
		if ( 0 === $order_id ) {
			return false;
		}

		$source_ref = 'order:' . $order_id;
		$results    = array(
			$this->upsert( $channel, $identity, self::CATEGORY_TRUST, self::SOURCE_COMPLETED_ORDER, '', $source_ref, $observed_at ),
			$this->upsert( $channel, $identity, self::CATEGORY_EXEMPTION, self::SOURCE_COMPLETED_ORDER, self::POLICY_REPEAT, $source_ref, $observed_at ),
		);
		if ( 'email' === $channel ) {
			$results[] = $this->upsert( $channel, $identity, self::CATEGORY_EXEMPTION, self::SOURCE_COMPLETED_ORDER, self::POLICY_EMAIL_VALIDATION, $source_ref, $observed_at );
		}

		return ! in_array( false, $results, true );
	}

	public function resolve_identity( $channel, $identity ) {
		$identity = $this->normalize_identity( $channel, $identity );
		if ( '' === $identity || ! $this->is_ready() ) {
			return $this->empty_resolution();
		}

		$identity_key = $this->identity_key( $channel, $identity );
		$now          = time();
		global $wpdb;
		$table = WC_Blacklist_Manager_Evidence_Trust_Schema::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT category,source,policy,first_observed_at,latest_observed_at,expires_at,source_ref
				FROM {$table}
				WHERE identity_key=%s AND channel=%s AND revoked_at=0 AND (expires_at=0 OR expires_at>%d)",
				$identity_key,
				$channel,
				$now
			),
			ARRAY_A
		);

		$resolution = $this->empty_resolution();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$category = isset( $row['category'] ) ? (string) $row['category'] : '';
			if ( self::CATEGORY_AUDIT === $category ) {
				$resolution['audit'][] = $this->public_record( $row );
			} elseif ( self::CATEGORY_TRUST === $category ) {
				$resolution['trust'][] = $this->public_record( $row );
			} elseif ( self::CATEGORY_EXEMPTION === $category ) {
				$resolution['exemptions'][] = $this->public_record( $row );
			}
		}

		return $resolution;
	}

	public function resolve_policy_exemption( $channel, $identity, $policy ) {
		if ( ! in_array( $policy, $this->policies(), true ) ) {
			return array( 'exempt' => false, 'category' => self::CATEGORY_EXEMPTION, 'provenance' => 'none', 'source' => '', 'policy' => '' );
		}

		$resolution = $this->resolve_identity( $channel, $identity );
		foreach ( $resolution['exemptions'] as $record ) {
			if ( $policy === $record['policy'] ) {
				return array( 'exempt' => true, 'category' => self::CATEGORY_EXEMPTION, 'provenance' => 'versioned', 'source' => $record['source'], 'policy' => $policy );
			}
		}

		if ( $this->legacy_policy_applies( $channel, $identity, $policy ) ) {
			return array( 'exempt' => true, 'category' => 'legacy_ambiguous', 'provenance' => 'legacy', 'source' => $this->legacy_source( $policy ), 'policy' => $policy );
		}

		return array( 'exempt' => false, 'category' => self::CATEGORY_EXEMPTION, 'provenance' => 'none', 'source' => '', 'policy' => $policy );
	}

	public function order_audit_companion( $channel, $identity, $proof_id, $verified_at ) {
		$identity    = $this->normalize_identity( $channel, $identity );
		$proof_id    = strtolower( (string) $proof_id );
		$verified_at = absint( $verified_at );
		if ( '' === $identity || ! preg_match( '/^[a-f0-9]{32}$/', $proof_id ) || 0 === $verified_at || ! $this->is_ready() ) {
			return array();
		}

		return array(
			'version'       => self::VERSION,
			'category'      => self::CATEGORY_AUDIT,
			'source'        => self::SOURCE_OTP_TRANSITION,
			'channel'       => $channel,
			'verified_at'   => $verified_at,
			'identity_key'  => $this->identity_key( $channel, $identity ),
			'proof_ref'     => $this->correlation_key( 'proof', $proof_id ),
		);
	}

	public function classify_order_evidence( $order, $channel ) {
		if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_meta' ) ) || ! in_array( $channel, $this->channels(), true ) ) {
			return array( 'category' => 'none', 'provenance' => 'none', 'label' => '' );
		}

		$companion_key = '_wc_blacklist_manager_' . $channel . '_evidence_v1';
		$verified_key  = '_verified_' . $channel;
		$proof_key     = '_wc_blacklist_manager_' . $channel . '_proof_id';
		$companion     = $order->get_meta( $companion_key, true );
		$verified      = 1 === (int) $order->get_meta( $verified_key, true );
		$proof_id      = strtolower( (string) $order->get_meta( $proof_key, true ) );
		$proof_ref     = preg_match( '/^[a-f0-9]{32}$/', $proof_id ) ? $this->correlation_key( 'proof', $proof_id ) : '';
		$identity      = $this->order_identity( $order, $channel );
		$identity_key  = '' !== $identity ? $this->identity_key( $channel, $identity ) : '';
		$companion_identity_key = is_array( $companion ) && isset( $companion['identity_key'] )
			? (string) $companion['identity_key']
			: '';

		if (
			is_array( $companion )
			&& self::VERSION === (int) ( isset( $companion['version'] ) ? $companion['version'] : 0 )
			&& self::CATEGORY_AUDIT === ( isset( $companion['category'] ) ? $companion['category'] : '' )
			&& self::SOURCE_OTP_TRANSITION === ( isset( $companion['source'] ) ? $companion['source'] : '' )
			&& $channel === ( isset( $companion['channel'] ) ? $companion['channel'] : '' )
			&& preg_match( '/^[a-f0-9]{64}$/', $companion_identity_key )
			&& ( '' === $identity_key || ! hash_equals( $identity_key, $companion_identity_key ) )
		) {
			return array( 'category' => 'legacy_ambiguous', 'provenance' => 'identity_mismatch', 'label' => 'Order identity differs from retained OTP evidence' );
		}

		if (
			is_array( $companion )
			&& $verified
			&& '' !== $proof_ref
			&& '' !== $identity_key
			&& self::VERSION === (int) ( isset( $companion['version'] ) ? $companion['version'] : 0 )
			&& self::CATEGORY_AUDIT === ( isset( $companion['category'] ) ? $companion['category'] : '' )
			&& self::SOURCE_OTP_TRANSITION === ( isset( $companion['source'] ) ? $companion['source'] : '' )
			&& $channel === ( isset( $companion['channel'] ) ? $companion['channel'] : '' )
			&& 0 < absint( isset( $companion['verified_at'] ) ? $companion['verified_at'] : 0 )
			&& hash_equals( $identity_key, $companion_identity_key )
			&& hash_equals( $proof_ref, (string) ( isset( $companion['proof_ref'] ) ? $companion['proof_ref'] : '' ) )
		) {
			return array( 'category' => self::CATEGORY_AUDIT, 'provenance' => 'versioned', 'label' => 'OTP audit evidence' );
		}

		if ( $verified ) {
			if ( preg_match( '/^[a-f0-9]{32}$/', $proof_id ) ) {
				return array( 'category' => self::CATEGORY_AUDIT, 'provenance' => 'phase3_compatibility', 'label' => 'Phase 3 OTP order evidence (compatibility)' );
			}
			return array( 'category' => 'legacy_ambiguous', 'provenance' => 'legacy', 'label' => 'Legacy verification marker' );
		}

		return array( 'category' => 'none', 'provenance' => 'none', 'label' => '' );
	}

	public function activity_correlation( $channel, $identity ) {
		$identity = $this->normalize_identity( $channel, $identity );
		return '' === $identity || ! $this->is_ready() ? '' : $this->correlation_key( 'activity:' . $channel, $identity );
	}

	public function identity_key( $channel, $identity ) {
		$identity = $this->normalize_identity( $channel, $identity );
		$key      = WC_Blacklist_Manager_Evidence_Trust_Schema::evidence_key();
		if ( '' === $identity || '' === $key ) {
			return '';
		}
		return hash_hmac( 'sha256', 'identity:v1:' . $channel . ':' . $identity, $key );
	}

	private function correlation_key( $purpose, $value ) {
		$key = WC_Blacklist_Manager_Evidence_Trust_Schema::evidence_key();
		return '' === $key || '' === (string) $value ? '' : hash_hmac( 'sha256', 'correlation:v1:' . $purpose . ':' . (string) $value, $key );
	}

	private function validated_semantics( $channel, $identity, $category, $source, $policy ) {
		$channel  = sanitize_key( (string) $channel );
		$category = sanitize_key( (string) $category );
		$source   = sanitize_key( (string) $source );
		$policy   = sanitize_key( (string) $policy );
		$identity = $this->normalize_identity( $channel, $identity );

		if (
			'' === $identity
			|| ! in_array( $channel, $this->channels(), true )
			|| ! in_array( $category, $this->categories(), true )
			|| ! in_array( $source, $this->sources(), true )
			|| ( self::CATEGORY_EXEMPTION === $category && ! in_array( $policy, $this->policies(), true ) )
			|| ( self::CATEGORY_EXEMPTION !== $category && '' !== $policy )
			|| ( self::POLICY_EMAIL_VALIDATION === $policy && 'email' !== $channel )
		) {
			return array();
		}

		return compact( 'channel', 'identity', 'category', 'source', 'policy' );
	}

	private function normalize_identity( $channel, $identity ) {
		if ( 'email' === $channel ) {
			$identity = function_exists( 'yobm_normalize_email' ) ? yobm_normalize_email( $identity ) : sanitize_email( $identity );
			return is_string( $identity ) ? strtolower( $identity ) : '';
		}
		if ( 'phone' === $channel ) {
			$identity = function_exists( 'yobm_normalize_phone' ) ? yobm_normalize_phone( $identity ) : preg_replace( '/\D+/', '', (string) $identity );
			return is_string( $identity ) ? $identity : '';
		}
		return '';
	}

	private function order_identity( $order, $channel ) {
		if ( 'email' === $channel ) {
			$email = is_callable( array( $order, 'get_billing_email' ) ) ? $order->get_billing_email() : '';
			return $this->normalize_identity( 'email', $email );
		}

		$billing_phone = $this->order_value( $order, 'get_billing_phone' );
		$shipping_phone = $this->order_value( $order, 'get_shipping_phone', '_shipping_phone' );
		$phone = '' !== trim( (string) $billing_phone ) ? $billing_phone : $shipping_phone;
		$billing_dial = $this->order_value( $order, 'get_billing_dial_code', '_billing_dial_code' );
		$shipping_dial = $this->order_value( $order, 'get_shipping_dial_code', '_shipping_dial_code' );
		$dial_code = '' !== trim( (string) $billing_dial ) ? $billing_dial : $shipping_dial;
		$billing_country = $this->order_value( $order, 'get_billing_country' );
		$shipping_country = $this->order_value( $order, 'get_shipping_country' );
		$country = '' !== trim( (string) $billing_country ) ? $billing_country : $shipping_country;
		if ( '' === trim( (string) $dial_code ) && '' !== trim( (string) $country ) && function_exists( 'yobm_get_country_dial_code' ) ) {
			$dial_code = yobm_get_country_dial_code( $country );
		}

		if ( function_exists( 'yobm_normalize_phone' ) ) {
			return yobm_normalize_phone( $phone, $dial_code );
		}

		return $this->normalize_identity( 'phone', $phone );
	}

	private function order_value( $order, $getter, $meta_key = '' ) {
		if ( is_callable( array( $order, $getter ) ) ) {
			$value = $order->{$getter}();
			if ( '' !== trim( (string) $value ) || '' === $meta_key ) {
				return $value;
			}
		}

		return '' !== $meta_key && is_callable( array( $order, 'get_meta' ) ) ? $order->get_meta( $meta_key, true ) : '';
	}

	private function legacy_policy_applies( $channel, $identity, $policy ) {
		$identity = $this->normalize_identity( $channel, $identity );
		if ( '' === $identity ) {
			return false;
		}

		global $wpdb;
		if ( self::POLICY_SUSPECT_RESOLUTION === $policy ) {
			$table  = $wpdb->prefix . 'wc_blacklist';
			$column = 'email' === $channel ? 'normalized_email' : 'normalized_phone';
			return $this->table_exists( $table ) && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE {$column}=%s AND is_blocked=2 LIMIT 1", $identity ) );
		}

		$table = $wpdb->prefix . 'wc_whitelist';
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}
		$column = 'email' === $channel ? 'email' : 'phone';
		if ( self::POLICY_EMAIL_VALIDATION === $policy ) {
			return 'email' === $channel && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE email=%s LIMIT 1", $identity ) );
		}
		$verified = 'email' === $channel ? 'verified_email' : 'verified_phone';
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE {$column}=%s AND {$verified}=1 LIMIT 1", $identity ) );
	}

	private function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function legacy_source( $policy ) {
		return self::POLICY_SUSPECT_RESOLUTION === $policy ? 'legacy_blacklist_state_2' : 'legacy_whitelist';
	}

	private function public_record( array $row ) {
		return array(
			'category'           => sanitize_key( isset( $row['category'] ) ? $row['category'] : '' ),
			'source'             => sanitize_key( isset( $row['source'] ) ? $row['source'] : '' ),
			'policy'             => sanitize_key( isset( $row['policy'] ) ? $row['policy'] : '' ),
			'first_observed_at'  => absint( isset( $row['first_observed_at'] ) ? $row['first_observed_at'] : 0 ),
			'latest_observed_at' => absint( isset( $row['latest_observed_at'] ) ? $row['latest_observed_at'] : 0 ),
			'expires_at'         => absint( isset( $row['expires_at'] ) ? $row['expires_at'] : 0 ),
			'source_ref'         => sanitize_text_field( isset( $row['source_ref'] ) ? $row['source_ref'] : '' ),
		);
	}

	private function empty_resolution() {
		return array( 'version' => self::VERSION, 'audit' => array(), 'trust' => array(), 'exemptions' => array() );
	}

	private function channels() {
		return array( 'email', 'phone' );
	}

	private function categories() {
		return array( self::CATEGORY_AUDIT, self::CATEGORY_TRUST, self::CATEGORY_EXEMPTION );
	}

	private function sources() {
		return array( self::SOURCE_OTP_TRANSITION, self::SOURCE_COMPLETED_ORDER );
	}

	private function policies() {
		return array( self::POLICY_REPEAT, self::POLICY_EMAIL_VALIDATION, self::POLICY_SUSPECT_RESOLUTION );
	}
}

function wc_blacklist_manager_evidence_trust() {
	return WC_Blacklist_Manager_Evidence_Trust::instance();
}

function wc_blacklist_manager_evidence_trust_resolve_identity( $channel, $identity ) {
	return wc_blacklist_manager_evidence_trust()->resolve_identity( $channel, $identity );
}

function wc_blacklist_manager_evidence_trust_resolve_policy( $channel, $identity, $policy ) {
	return wc_blacklist_manager_evidence_trust()->resolve_policy_exemption( $channel, $identity, $policy );
}

function wc_blacklist_manager_evidence_trust_record_otp( $channel, $identity, $proof_id, $verified_at, $suspect_resolved = false ) {
	return wc_blacklist_manager_evidence_trust()->record_otp_transition( $channel, $identity, $proof_id, $verified_at, $suspect_resolved );
}

function wc_blacklist_manager_evidence_trust_record_completed_order( $channel, $identity, $order_id, $observed_at = 0 ) {
	return wc_blacklist_manager_evidence_trust()->record_completed_order_trust( $channel, $identity, $order_id, $observed_at );
}

function wc_blacklist_manager_evidence_trust_order_companion( $channel, $identity, $proof_id, $verified_at ) {
	return wc_blacklist_manager_evidence_trust()->order_audit_companion( $channel, $identity, $proof_id, $verified_at );
}

function wc_blacklist_manager_evidence_trust_classify_order( $order, $channel ) {
	return wc_blacklist_manager_evidence_trust()->classify_order_evidence( $order, $channel );
}

function wc_blacklist_manager_legacy_blacklist_state_semantics( $state ) {
	$state = (int) $state;
	if ( 1 === $state ) {
		return array( 'code' => 'blocked', 'blocked' => true, 'label' => 'Blocked' );
	}
	if ( 2 === $state ) {
		return array( 'code' => 'legacy_resolved_exempt', 'blocked' => false, 'label' => 'Resolved/exempt (legacy)' );
	}
	return array( 'code' => 'suspect', 'blocked' => false, 'label' => 'Suspect' );
}

WC_Blacklist_Manager_Evidence_Trust_Schema::init();
