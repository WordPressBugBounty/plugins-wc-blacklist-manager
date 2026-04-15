<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_Device_Identity {

	/**
	 * Singleton instance.
	 *
	 * @var WC_Blacklist_Manager_Device_Identity|null
	 */
	protected static $instance = null;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	protected $version = '';

	/**
	 * Plugin file path.
	 *
	 * @var string
	 */
	protected $plugin_file = '';

	/**
	 * Get instance.
	 *
	 * @return WC_Blacklist_Manager_Device_Identity
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin_file = defined( 'WC_BLACKLIST_MANAGER_PLUGIN_FILE' ) ? WC_BLACKLIST_MANAGER_PLUGIN_FILE : '';
		$this->version     = defined( 'WC_BLACKLIST_MANAGER_VERSION' ) ? WC_BLACKLIST_MANAGER_VERSION : '1.0.0';

		add_action( 'init', array( $this, 'maybe_set_device_cookie' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Core device capture to order meta lives here.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_device_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'after_order_processed' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'store_api_after_order_processed' ), 20, 1 );
	}

	/**
	 * Check whether device identity core should run.
	 *
	 * @return bool
	 */
	public function is_device_identity_available() {
		return true;
	}

	/**
	 * Check whether premium device links logic is enabled.
	 *
	 * @return bool
	 */
	public function is_device_links_enabled() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		return $premium_active && '1' === get_option( 'wc_blacklist_enable_device_identity', '0' );
	}

	/**
	 * Whether current request should load the device JS.
	 *
	 * @return bool
	 */
	public function should_enqueue_script() {
		if ( is_admin() ) {
			return false;
		}

		if ( ! $this->is_device_identity_available() ) {
			return false;
		}

		if ( $this->is_checkout_context() ) {
			return true;
		}

		if ( $this->is_register_context() ) {
			return true;
		}

		if ( $this->is_login_context() ) {
			return true;
		}

		return false;
	}

	protected function is_checkout_context() {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}

		return false;
	}

	protected function is_register_context() {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		if ( function_exists( 'wp_registration_url' ) && $this->is_wp_register_request() ) {
			return true;
		}

		return false;
	}

	protected function is_login_context() {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		if ( $this->is_wp_login_request() ) {
			return true;
		}

		return false;
	}

	protected function is_wp_login_request() {
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? wp_unslash( $_SERVER['SCRIPT_NAME'] ) : '';
		return false !== strpos( $script, 'wp-login.php' );
	}

	protected function is_wp_register_request() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
		return $this->is_wp_login_request() && 'register' === $action;
	}

	/**
	 * Enqueue device identity JS.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		if ( ! $this->should_enqueue_script() ) {
			return;
		}

		if ( empty( $this->plugin_file ) ) {
			return;
		}

		$script_url  = plugin_dir_url( $this->plugin_file ) . 'js/device-identity.js';
		$script_path = plugin_dir_path( $this->plugin_file ) . 'js/device-identity.js';

		wp_enqueue_script(
			'wc-blacklist-device-identity',
			$script_url,
			array( 'jquery' ),
			file_exists( $script_path ) ? filemtime( $script_path ) : $this->version,
			true
		);

		wp_localize_script(
			'wc-blacklist-device-identity',
			'wcBlacklistDeviceIdentity',
			array(
				'is_checkout' => $this->is_checkout_context(),
				'is_register' => $this->is_register_context(),
				'is_login'    => $this->is_login_context(),
			)
		);
	}

	/**
	 * Set a first-party seed cookie.
	 *
	 * @return void
	 */
	public function maybe_set_device_cookie() {
		if ( headers_sent() || ! $this->is_device_identity_available() ) {
			return;
		}

		if ( empty( $_COOKIE['wc_bm_did_seed'] ) ) {
			$seed = wp_generate_password( 32, false, false );

			setcookie(
				'wc_bm_did_seed',
				$seed,
				time() + YEAR_IN_SECONDS,
				COOKIEPATH ? COOKIEPATH : '/',
				COOKIE_DOMAIN,
				is_ssl(),
				true
			);

			$_COOKIE['wc_bm_did_seed'] = $seed;
		}
	}

	/**
	 * Validate device ID format.
	 *
	 * @param string $device_id Device ID.
	 * @return bool
	 */
	public function is_valid_device_id_format( $device_id ) {
		$device_id = sanitize_text_field( (string) $device_id );
		return (bool) preg_match( '/^[a-f0-9]{32,64}$/', $device_id );
	}

	/**
	 * Get device payload from request.
	 *
	 * @return array
	 */
	public function get_request_device_payload() {
		$payload = array();

		if ( isset( $_POST['wc_blacklist_device'] ) ) {
			if ( is_array( $_POST['wc_blacklist_device'] ) ) {
				$payload = wp_unslash( $_POST['wc_blacklist_device'] );
			} elseif ( is_string( $_POST['wc_blacklist_device'] ) ) {
				$decoded = json_decode( wp_unslash( $_POST['wc_blacklist_device'] ), true );
				if ( is_array( $decoded ) ) {
					$payload = $decoded;
				}
			}
		}

		if ( empty( $payload ) ) {
			$raw_body = file_get_contents( 'php://input' );

			if ( ! empty( $raw_body ) ) {
				$decoded = json_decode( $raw_body, true );

				if ( isset( $decoded['wc_blacklist_device'] ) && is_array( $decoded['wc_blacklist_device'] ) ) {
					$payload = $decoded['wc_blacklist_device'];
				} elseif ( isset( $decoded['extensions']['wc_blacklist_device'] ) && is_array( $decoded['extensions']['wc_blacklist_device'] ) ) {
					$payload = $decoded['extensions']['wc_blacklist_device'];
				}
			}
		}

		$flags = array();
		if ( isset( $payload['flags'] ) && is_array( $payload['flags'] ) ) {
			$flags = array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_text_field', $payload['flags'] )
					)
				)
			);
		}

		return array(
			'version'    => sanitize_text_field( $payload['version'] ?? '' ),
			'device_id'  => sanitize_text_field( $payload['device_id'] ?? '' ),
			'browser_id' => sanitize_text_field( $payload['browser_id'] ?? '' ),
			'session_id' => sanitize_text_field( $payload['session_id'] ?? '' ),
			'fp_hash'    => sanitize_text_field( $payload['fp_hash'] ?? '' ),
			'confidence' => sanitize_key( $payload['confidence'] ?? '' ),
			'flags'      => $flags,
		);
	}

	/**
	 * Validate device payload.
	 *
	 * @param array $device Device payload.
	 * @return array
	 */
	public function validate_device_payload( $device ) {
		$result = array(
			'valid'   => true,
			'reasons' => array(),
		);

		$version    = isset( $device['version'] ) ? (string) $device['version'] : '';
		$device_id  = isset( $device['device_id'] ) ? (string) $device['device_id'] : '';
		$browser_id = isset( $device['browser_id'] ) ? (string) $device['browser_id'] : '';
		$session_id = isset( $device['session_id'] ) ? (string) $device['session_id'] : '';
		$fp_hash    = isset( $device['fp_hash'] ) ? (string) $device['fp_hash'] : '';
		$confidence = isset( $device['confidence'] ) ? (string) $device['confidence'] : '';
		$flags      = isset( $device['flags'] ) && is_array( $device['flags'] ) ? $device['flags'] : array();

		if ( '' !== $version && 'v1' !== strtolower( $version ) ) {
			$result['valid']     = false;
			$result['reasons'][] = 'invalid_version';
		}

		if ( '' === $device_id ) {
			$result['valid']     = false;
			$result['reasons'][] = 'missing_device_id';
		} elseif ( ! $this->is_valid_device_id_format( $device_id ) ) {
			$result['valid']     = false;
			$result['reasons'][] = 'invalid_device_id_format';
		}

		if ( '' !== $browser_id && ! preg_match( '/^[a-z0-9]{16,128}$/', strtolower( $browser_id ) ) ) {
			$result['valid']     = false;
			$result['reasons'][] = 'invalid_browser_id';
		}

		if ( '' !== $session_id && strlen( $session_id ) > 128 ) {
			$result['valid']     = false;
			$result['reasons'][] = 'invalid_session_id';
		}

		if ( '' !== $fp_hash && ! preg_match( '/^[a-f0-9]{32,64}$/', $fp_hash ) ) {
			$result['valid']     = false;
			$result['reasons'][] = 'invalid_fp_hash';
		}

		if ( '' !== $confidence && ! in_array( $confidence, array( 'low', 'medium', 'high' ), true ) ) {
			$result['valid']     = false;
			$result['reasons'][] = 'invalid_confidence';
		}

		if ( ! empty( $flags ) ) {
			foreach ( $flags as $flag ) {
				if ( ! is_string( $flag ) || '' === $flag ) {
					$result['valid']     = false;
					$result['reasons'][] = 'invalid_flags';
					break;
				}

				if ( strlen( $flag ) > 64 ) {
					$result['valid']     = false;
					$result['reasons'][] = 'flag_too_long';
					break;
				}
			}
		}

		$result['reasons'] = array_values( array_unique( $result['reasons'] ) );

		return $result;
	}

	/**
	 * Get normalized device meta snapshot from order.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public function get_order_device_meta_snapshot( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return array();
		}

		$validation_reasons = $order->get_meta( '_wc_bm_device_validation_reasons', true );

		if ( is_array( $validation_reasons ) ) {
			$validation_reasons = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $validation_reasons ) ) ) );
		} else {
			$validation_reasons = array();
		}

		return array(
			'version'            => (string) $order->get_meta( '_wc_bm_device_version', true ),
			'device_id'          => (string) $order->get_meta( '_wc_bm_device_id', true ),
			'browser_id'         => (string) $order->get_meta( '_wc_bm_device_browser_id', true ),
			'session_id'         => (string) $order->get_meta( '_wc_bm_session_id', true ),
			'fp_hash'            => (string) $order->get_meta( '_wc_bm_device_fp_hash', true ),
			'confidence'         => (string) $order->get_meta( '_wc_bm_device_confidence', true ),
			'flags'              => $order->get_meta( '_wc_bm_device_flags', true ),
			'payload_valid_raw'  => (string) $order->get_meta( '_wc_bm_device_payload_valid', true ),
			'payload_valid'      => 'no' === (string) $order->get_meta( '_wc_bm_device_payload_valid', true ) ? 0 : 1,
			'validation_reasons' => $validation_reasons,
		);
	}

	/**
	 * Save parsed device payload to order meta.
	 *
	 * @param WC_Order $order  Order object.
	 * @param array    $device Device payload.
	 * @param array    $validation Validation result.
	 * @return void
	 */
	protected function write_device_meta_to_order( $order, $device, $validation ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		if ( ! empty( $device['version'] ) ) {
			$order->update_meta_data( '_wc_bm_device_version', $device['version'] );
		}

		if ( ! empty( $device['device_id'] ) ) {
			$order->update_meta_data( '_wc_bm_device_id', $device['device_id'] );
		}

		if ( ! empty( $device['browser_id'] ) ) {
			$order->update_meta_data( '_wc_bm_device_browser_id', $device['browser_id'] );
		}

		if ( ! empty( $device['session_id'] ) ) {
			$order->update_meta_data( '_wc_bm_session_id', $device['session_id'] );
		}

		if ( ! empty( $device['fp_hash'] ) ) {
			$order->update_meta_data( '_wc_bm_device_fp_hash', $device['fp_hash'] );
		}

		if ( ! empty( $device['confidence'] ) ) {
			$order->update_meta_data( '_wc_bm_device_confidence', $device['confidence'] );
		}

		if ( ! empty( $device['flags'] ) ) {
			$order->update_meta_data( '_wc_bm_device_flags', $device['flags'] );
		}

		$order->update_meta_data( '_wc_bm_device_payload_valid', ! empty( $validation['valid'] ) ? 'yes' : 'no' );

		if ( ! empty( $validation['reasons'] ) ) {
			$order->update_meta_data( '_wc_bm_device_validation_reasons', $validation['reasons'] );
		} else {
			$order->delete_meta_data( '_wc_bm_device_validation_reasons' );
		}
	}

	/**
	 * Save device data into order meta during order creation.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Checkout data.
	 * @return void
	 */
	public function save_order_device_data( $order, $data ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$device     = $this->get_request_device_payload();
		$validation = $this->validate_device_payload( $device );

		$this->write_device_meta_to_order( $order, $device, $validation );
	}

	/**
	 * Fallback after Classic checkout order processed.
	 *
	 * @param int      $order_id    Order ID.
	 * @param array    $posted_data Posted data.
	 * @param WC_Order $order       Order.
	 * @return void
	 */
	public function after_order_processed( $order_id, $posted_data, $order ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->save_missing_device_meta( $order );
	}

	/**
	 * Fallback after Store API checkout order processed.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function store_api_after_order_processed( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$this->save_missing_device_meta( $order );
	}

	/**
	 * Save missing device meta only if not already saved.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	protected function save_missing_device_meta( $order ) {
		$existing_device_id = (string) $order->get_meta( '_wc_bm_device_id', true );

		if ( '' !== $existing_device_id ) {
			return;
		}

		$device     = $this->get_request_device_payload();
		$validation = $this->validate_device_payload( $device );

		$this->write_device_meta_to_order( $order, $device, $validation );
		$order->save();
	}

	/**
	 * Get device links table name.
	 *
	 * @return string
	 */
	public function get_device_links_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_blacklist_device_links';
	}

	/**
	 * Get devices table name.
	 *
	 * @return string
	 */
	public function get_devices_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_blacklist_devices';
	}

	/**
	 * Get device DB record by device ID.
	 *
	 * @param string $device_id Device ID.
	 * @return array
	 */
	public function get_device_record( $device_id ) {
		global $wpdb;

		$device_id = sanitize_text_field( (string) $device_id );

		if ( ! $this->is_valid_device_id_format( $device_id ) ) {
			return array();
		}

		$table_name = $this->get_devices_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table_name}
				WHERE device_id = %s
				LIMIT 1",
				$device_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Insert or update a device identity link.
	 *
	 * Only runs when premium is active and the device identity option is enabled.
	 *
	 * @param string $device_id      Device ID.
	 * @param string $identity_type  Identity type.
	 * @param string $identity_value Identity value.
	 * @param int    $order_id       Order ID.
	 * @return void
	 */
	public function upsert_device_link( $device_id, $identity_type, $identity_value, $order_id = 0 ) {
		global $wpdb;

		if ( ! $this->is_device_links_enabled() ) {
			return;
		}

		$device_id      = sanitize_text_field( (string) $device_id );
		$identity_type  = sanitize_key( (string) $identity_type );
		$identity_value = sanitize_text_field( (string) $identity_value );
		$order_id       = (int) $order_id;

		if ( '' === $device_id || '' === $identity_type || '' === $identity_value ) {
			return;
		}

		$table_name = $this->get_device_links_table_name();
		$now        = current_time( 'mysql' );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, order_count
				FROM {$table_name}
				WHERE device_id = %s
				AND identity_type = %s
				AND identity_value = %s
				LIMIT 1",
				$device_id,
				$identity_type,
				$identity_value
			),
			ARRAY_A
		);

		if ( $existing ) {
			$wpdb->update(
				$table_name,
				array(
					'last_seen'     => $now,
					'order_count'   => (int) $existing['order_count'] + 1,
					'last_order_id' => $order_id,
				),
				array(
					'id' => (int) $existing['id'],
				),
				array(
					'%s',
					'%d',
					'%d',
				),
				array(
					'%d',
				)
			);
		} else {
			$wpdb->insert(
				$table_name,
				array(
					'device_id'      => $device_id,
					'identity_type'  => $identity_type,
					'identity_value' => $identity_value,
					'first_seen'     => $now,
					'last_seen'      => $now,
					'order_count'    => 1,
					'last_order_id'  => $order_id,
				),
				array(
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%d',
					'%d',
				)
			);
		}
	}

	/**
	 * Count distinct links for a device by type.
	 *
	 * Only runs when premium is active and the device identity option is enabled.
	 *
	 * @param string $device_id     Device ID.
	 * @param string $identity_type Identity type.
	 * @return int
	 */
	public function count_device_links_by_type( $device_id, $identity_type ) {
		global $wpdb;

		if ( ! $this->is_device_links_enabled() ) {
			return 0;
		}

		$device_id     = sanitize_text_field( (string) $device_id );
		$identity_type = sanitize_key( (string) $identity_type );

		if ( '' === $device_id || '' === $identity_type ) {
			return 0;
		}

		$table_name = $this->get_device_links_table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id)
				FROM {$table_name}
				WHERE device_id = %s
				AND identity_type = %s",
				$device_id,
				$identity_type
			)
		);
	}

	/**
	 * Get linked identities for a device by type.
	 *
	 * Only runs when premium is active and the device identity option is enabled.
	 *
	 * @param string $device_id     Device ID.
	 * @param string $identity_type Identity type.
	 * @param int    $limit         Limit.
	 * @return array
	 */
	public function get_device_links_by_type( $device_id, $identity_type, $limit = 50 ) {
		global $wpdb;

		if ( ! $this->is_device_links_enabled() ) {
			return array();
		}

		$device_id     = sanitize_text_field( (string) $device_id );
		$identity_type = sanitize_key( (string) $identity_type );
		$limit         = max( 1, (int) $limit );

		if ( '' === $device_id || '' === $identity_type ) {
			return array();
		}

		$table_name = $this->get_device_links_table_name();

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT identity_value
				FROM {$table_name}
				WHERE device_id = %s
				AND identity_type = %s
				ORDER BY last_seen DESC
				LIMIT %d",
				$device_id,
				$identity_type,
				$limit
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get current request device ID.
	 *
	 * @return string
	 */
	public function get_current_request_device_id() {
		$payload = $this->get_request_device_payload();
		return (string) $payload['device_id'];
	}
}

WC_Blacklist_Manager_Device_Identity::instance();