<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private site-local readiness and repair controller for Core-owned outcome indexes.
 */
final class WC_Blacklist_Manager_Schema_Readiness {
	const CONTRACT_VERSION = 1;
	const VERSION_OPTION   = 'wc_blacklist_manager_schema_contract_version';
	const STATE_OPTION     = 'wc_blacklist_manager_schema_state_v1';
	const LOCK_OPTION      = 'wc_blacklist_manager_schema_migration_lock_v1';
	const CRON_HOOK        = 'wc_blacklist_manager_schema_migration_v1';
	const ADMIN_ACTION     = 'wc_blacklist_manager_schedule_schema_repair_v1';
	const STATE_FORMAT     = 1;
	const LOCK_TTL         = 5 * MINUTE_IN_SECONDS;

	private static $instance = null;
	private static $test_prefix = '';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'refresh_readiness' ), 5 );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'handle_schedule_request' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_worker' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_admin_notice' ) );
	}

	/** Return the finite site-local contract declaration. */
	public static function contracts() {
		return array(
			'blacklist_outcome' => array(
				'table_suffix' => 'wc_blacklist',
				'index'        => 'outcome_date_status',
				'columns'      => array( 'date_added', 'is_blocked' ),
				'label'        => 'Blacklist outcome history',
			),
			'address_outcome' => array(
				'table_suffix' => 'wc_blacklist_addresses',
				'index'        => 'outcome_date_status',
				'columns'      => array( 'date_added', 'is_blocked' ),
				'label'        => 'Address outcome history',
			),
			'global_outcome' => array(
				'table_suffix' => 'wc_blacklist_detection_log',
				'index'        => 'outcome_timestamp',
				'columns'      => array( 'timestamp' ),
				'label'        => 'Global retained-decision history',
			),
		);
	}

	/** WP-CLI fixture isolation only; production callers cannot override table ownership. */
	public static function set_test_prefix( $prefix ) {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return false;
		}

		$prefix = (string) $prefix;
		if ( '' !== $prefix && ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			return false;
		}

		self::$test_prefix = $prefix;
		return true;
	}

	/** Cheap metadata inspection; never changes an index. */
	public function refresh_readiness() {
		$stored = (int) get_option( self::VERSION_OPTION, 0 );
		if ( $stored > self::CONTRACT_VERSION ) {
			return $this->write_state( 'newer_than_code', array(), 'newer_schema_contract', 'The stored schema contract is newer than this Core version.' );
		}

		$inspection = self::inspect_physical_contracts();
		if ( ! empty( $inspection['ready'] ) ) {
			update_option( self::VERSION_OPTION, self::CONTRACT_VERSION, false );
			return $this->write_state( 'ready', array(), '', '' );
		}

		$current = get_option( self::STATE_OPTION, array() );
		$status  = is_array( $current ) ? (string) ( $current['status'] ?? '' ) : '';
		if ( ! in_array( $status, array( 'failed', 'manual_required' ), true ) ) {
			$status = 'pending';
		}

		return $this->write_state(
			$status,
			$inspection['pending'],
			is_array( $current ) ? (string) ( $current['error_code'] ?? '' ) : '',
			is_array( $current ) ? (string) ( $current['error_message'] ?? '' ) : ''
		);
	}

	/** Readiness requires both the finite marker/state and exact physical metadata. */
	public static function is_ready() {
		if ( self::CONTRACT_VERSION !== (int) get_option( self::VERSION_OPTION, 0 ) ) {
			return false;
		}

		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || 'ready' !== (string) ( $state['status'] ?? '' ) ) {
			return false;
		}

		$inspection = self::inspect_physical_contracts();
		return ! empty( $inspection['ready'] );
	}

	/** Exact ordered-column validator shared by bounded P2/P3 readers. */
	public static function index_matches( $contract_id ) {
		$contracts = self::contracts();
		if ( ! isset( $contracts[ $contract_id ] ) ) {
			return false;
		}

		$result = self::inspect_contract( $contracts[ $contract_id ] );
		return 'exact' === $result['status'];
	}

	/** Validate every physical contract without trusting a stored marker. */
	public static function inspect_physical_contracts() {
		$pending = array();
		$details = array();

		foreach ( self::contracts() as $id => $contract ) {
			$result         = self::inspect_contract( $contract );
			$details[ $id ] = $result['status'];
			if ( 'exact' !== $result['status'] ) {
				$pending[] = $id;
			}
		}

		return array(
			'ready'   => empty( $pending ),
			'pending' => $pending,
			'details' => $details,
		);
	}

	/** Execute at most one contract-changing ALTER in background mode. */
	public function run_scheduled_worker() {
		return $this->run_worker( false );
	}

	/** Internal/CLI worker. Blocking DDL is permitted only for explicit WP-CLI maintenance. */
	public function run_worker( $allow_blocking = false ) {
		$allow_blocking = (bool) $allow_blocking && defined( 'WP_CLI' ) && WP_CLI;
		$stored         = (int) get_option( self::VERSION_OPTION, 0 );
		if ( $stored > self::CONTRACT_VERSION ) {
			$this->write_state( 'newer_than_code', array(), 'newer_schema_contract', 'The stored schema contract is newer than this Core version.' );
			return array( 'status' => 'newer_than_code', 'changed' => false );
		}

		$token = $this->acquire_lock();
		if ( '' === $token ) {
			return array( 'status' => 'locked', 'changed' => false );
		}

		try {
			$inspection = self::inspect_physical_contracts();
			if ( ! empty( $inspection['ready'] ) ) {
				update_option( self::VERSION_OPTION, self::CONTRACT_VERSION, false );
				$this->write_state( 'ready', array(), '', '' );
				return array( 'status' => 'ready', 'changed' => false );
			}

			$this->write_state( 'running', $inspection['pending'], '', '' );
			$contract_id = reset( $inspection['pending'] );
			$contracts   = self::contracts();
			$result      = $this->repair_one_contract( $contracts[ $contract_id ], $allow_blocking );

			if ( ! $this->owns_lock( $token ) ) {
				return array( 'status' => 'failed', 'changed' => ! is_wp_error( $result ), 'error' => 'migration_lock_lost' );
			}
			if ( is_wp_error( $result ) ) {
				$status = 'online_ddl_unavailable' === $result->get_error_code() ? 'manual_required' : 'failed';
				$this->write_state( $status, $inspection['pending'], $result->get_error_code(), $result->get_error_message(), true );
				return array( 'status' => $status, 'changed' => false, 'error' => $result->get_error_code() );
			}

			$after = self::inspect_physical_contracts();
			if ( in_array( $contract_id, $after['pending'], true ) ) {
				$this->write_state( 'failed', $after['pending'], 'postcondition_failed', 'The index change completed without satisfying the exact contract.', true );
				return array( 'status' => 'failed', 'changed' => true, 'error' => 'postcondition_failed' );
			}

			if ( ! empty( $after['ready'] ) ) {
				update_option( self::VERSION_OPTION, self::CONTRACT_VERSION, false );
				$this->write_state( 'ready', array(), '', '', true );
				return array( 'status' => 'ready', 'changed' => true );
			}

			$this->write_state( 'pending', $after['pending'], '', '', true );
			$this->schedule_event();
			return array( 'status' => 'pending', 'changed' => true );
		} finally {
			try {
				$this->release_lock( $token );
			} finally {
				$this->release_database_lock();
			}
		}
	}

	/** Nonce/capability-protected scheduler; no DDL executes in this request. */
	public function handle_schedule_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to schedule this database repair.', 'wc-blacklist-manager' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ADMIN_ACTION );
		$this->schedule_event();
		$inspection = self::inspect_physical_contracts();
		$this->write_state( 'pending', $inspection['pending'], '', '' );

		wp_safe_redirect( add_query_arg( 'yobm_schema_repair', 'scheduled', wp_get_referer() ?: admin_url() ) );
		exit;
	}

	/** Factual operational notice, deliberately separate from commercial surfaces. */
	public function render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state  = get_option( self::STATE_OPTION, array() );
		$status = is_array( $state ) ? (string) ( $state['status'] ?? '' ) : '';
		if ( ! in_array( $status, array( 'pending', 'failed', 'manual_required', 'newer_than_code' ), true ) ) {
			return;
		}

		$pending = is_array( $state ) ? array_values( array_intersect( array_keys( self::contracts() ), (array) ( $state['pending'] ?? array() ) ) ) : array();
		$labels  = array();
		foreach ( $pending as $contract_id ) {
			$labels[] = self::contracts()[ $contract_id ]['label'];
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Blacklist Manager database readiness requires attention.', 'wc-blacklist-manager' ) . '</strong></p>';
		if ( ! empty( $labels ) ) {
			echo '<p>' . esc_html( implode( ', ', $labels ) ) . '</p>';
		}
		if ( ! empty( $state['error_message'] ) ) {
			echo '<p>' . esc_html( (string) $state['error_message'] ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Large stores should schedule a maintenance window and verify database capacity before retrying. Core will not silently use blocking index DDL from a web request.', 'wc-blacklist-manager' ) . '</p>';

		if ( 'newer_than_code' !== $status ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_ACTION ) . '">';
			wp_nonce_field( self::ADMIN_ACTION );
			echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Schedule database readiness repair', 'wc-blacklist-manager' ) . '</button>';
			echo '</form>';
		}
		echo '</div>';
	}

	private static function inspect_contract( array $contract ) {
		global $wpdb;

		$table = self::table_prefix() . $contract['table_suffix'];
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return array( 'status' => 'metadata_error', 'columns' => array() );
		}

		$previous = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results(
			$wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $contract['index'] ),
			ARRAY_A
		);
		$error    = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $previous );

		if ( '' !== $error || ! is_array( $rows ) ) {
			return array( 'status' => 'metadata_error', 'columns' => array() );
		}
		if ( empty( $rows ) ) {
			return array( 'status' => 'missing', 'columns' => array() );
		}

		usort(
			$rows,
			static function ( $left, $right ) {
				return (int) ( $left['Seq_in_index'] ?? 0 ) <=> (int) ( $right['Seq_in_index'] ?? 0 );
			}
		);
		$columns = array_map(
			static function ( $row ) {
				return (string) ( $row['Column_name'] ?? '' );
			},
			$rows
		);

		return array(
			'status'  => $columns === array_values( $contract['columns'] ) ? 'exact' : 'wrong',
			'columns' => $columns,
		);
	}

	private function repair_one_contract( array $contract, $allow_blocking ) {
		global $wpdb;

		$table = self::table_prefix() . $contract['table_suffix'];
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return new WP_Error( 'invalid_table_identifier', 'The site table prefix cannot be used safely.' );
		}

		$current = self::inspect_contract( $contract );
		if ( 'exact' === $current['status'] ) {
			return true;
		}

		$index   = $contract['index'];
		$columns = implode( ', ', array_map( static function ( $column ) { return '`' . $column . '`'; }, $contract['columns'] ) );
		$changes = 'wrong' === $current['status']
			? "DROP INDEX `{$index}`, ADD INDEX `{$index}` ({$columns})"
			: "ADD INDEX `{$index}` ({$columns})";
		$sql     = "ALTER TABLE `{$table}` {$changes}";
		if ( ! $allow_blocking ) {
			$sql .= ', ALGORITHM=INPLACE, LOCK=NONE';
		}

		$previous = $wpdb->suppress_errors( true );
		$result   = $wpdb->query( $sql );
		$error    = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $previous );

		if ( false === $result ) {
			$online_error = ! $allow_blocking && preg_match( '/(?:ALGORITHM|LOCK|not supported|unsupported|inplace)/i', $error );
			return new WP_Error(
				$online_error ? 'online_ddl_unavailable' : 'schema_ddl_failed',
				$this->bounded_error_message( $error ?: 'The database rejected the index change.' )
			);
		}

		return true;
	}

	private function acquire_lock() {
		global $wpdb;

		if ( ! $this->acquire_database_lock() ) {
			return '';
		}

		$now   = time();
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : wp_generate_password( 32, false, false );
		$value = wp_json_encode( array( 'token' => $token, 'expires_at' => $now + self::LOCK_TTL ) );
		$added = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::LOCK_OPTION,
				$value
			)
		);
		$this->clear_option_cache( self::LOCK_OPTION );
		if ( 1 === (int) $added ) {
			return $token;
		}

		$current_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
		$current     = is_string( $current_raw ) ? json_decode( $current_raw, true ) : null;
		if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) >= $now ) {
			$this->release_database_lock();
			return '';
		}

		$replaced = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND option_value = %s",
				$value,
				self::LOCK_OPTION,
				$current_raw
			)
		);
		$this->clear_option_cache( self::LOCK_OPTION );

		if ( 1 === (int) $replaced ) {
			return $token;
		}

		$this->release_database_lock();
		return '';
	}

	private function acquire_database_lock() {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::database_lock_name() ) );
		$wpdb->suppress_errors( $previous );

		return 1 === (int) $acquired;
	}

	private function release_database_lock() {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::database_lock_name() ) );
		$wpdb->suppress_errors( $previous );
	}

	private static function database_lock_name() {
		$database = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		return 'bm_schema_' . substr( hash( 'sha256', $database . '|' . self::table_prefix() ), 0, 40 );
	}

	private function owns_lock( $token ) {
		global $wpdb;

		$current_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
		$current     = is_string( $current_raw ) ? json_decode( $current_raw, true ) : null;
		$database_owner = $wpdb->get_var(
			$wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::database_lock_name() )
		);

		return 1 === (int) $database_owner
			&& is_array( $current )
			&& hash_equals( (string) ( $current['token'] ?? '' ), (string) $token );
	}

	private function release_lock( $token ) {
		global $wpdb;

		$current_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
		$current     = is_string( $current_raw ) ? json_decode( $current_raw, true ) : null;
		if ( is_array( $current ) && hash_equals( (string) ( $current['token'] ?? '' ), (string) $token ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					self::LOCK_OPTION,
					$current_raw
				)
			);
			$this->clear_option_cache( self::LOCK_OPTION );
		}
	}

	private function schedule_event() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	private function write_state( $status, array $pending, $error_code = '', $error_message = '', $attempted = false ) {
		$allowed = array( 'ready', 'pending', 'running', 'failed', 'manual_required', 'newer_than_code' );
		$status  = in_array( $status, $allowed, true ) ? $status : 'failed';
		$current = get_option( self::STATE_OPTION, array() );
		$state   = array(
			'format'           => self::STATE_FORMAT,
			'target_contract'  => self::CONTRACT_VERSION,
			'status'           => $status,
			'pending'          => array_values( array_intersect( array_keys( self::contracts() ), $pending ) ),
			'last_checked_at'  => time(),
			'last_attempt_at'  => $attempted ? time() : ( is_array( $current ) ? (int) ( $current['last_attempt_at'] ?? 0 ) : 0 ),
			'last_success_at'  => 'ready' === $status ? time() : ( is_array( $current ) ? (int) ( $current['last_success_at'] ?? 0 ) : 0 ),
			'error_code'       => sanitize_key( (string) $error_code ),
			'error_message'    => $this->bounded_error_message( $error_message ),
		);

		if ( false === get_option( self::STATE_OPTION, false ) ) {
			add_option( self::STATE_OPTION, $state, '', 'no' );
		} else {
			update_option( self::STATE_OPTION, $state, false );
		}

		return $state;
	}

	private function bounded_error_message( $message ) {
		$message = sanitize_text_field( (string) $message );
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 240 ) : substr( $message, 0, 240 );
	}

	private static function table_prefix() {
		global $wpdb;

		if ( defined( 'WP_CLI' ) && WP_CLI && '' !== self::$test_prefix ) {
			return self::$test_prefix;
		}

		return (string) $wpdb->prefix;
	}

	private function clear_option_cache( $option ) {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	final class WC_Blacklist_Manager_Schema_CLI_Command {
		public function status() {
			$state = WC_Blacklist_Manager_Schema_Readiness::instance()->refresh_readiness();
			WP_CLI::line( wp_json_encode( $state ) );
		}

		public function repair( $args, $assoc_args ) {
			$allow_blocking = isset( $assoc_args['allow-blocking'] );
			$result         = WC_Blacklist_Manager_Schema_Readiness::instance()->run_worker( $allow_blocking );
			WP_CLI::line( wp_json_encode( $result ) );
			if ( ! in_array( $result['status'], array( 'ready', 'pending' ), true ) ) {
				WP_CLI::halt( 1 );
			}
		}
	}

	WP_CLI::add_command( 'blacklist-manager schema', 'WC_Blacklist_Manager_Schema_CLI_Command' );
}

WC_Blacklist_Manager_Schema_Readiness::instance();
