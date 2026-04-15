<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_Order_Actions {
	private $version          = '2.1.2';
	private $suspect_nonce_key = 'blacklist_ajax_nonce';
	private $block_nonce_key   = 'block_ajax_nonce';
	private $remove_nonce_key  = 'remove_ajax_nonce';

	/**
	 * Per-request caches.
	 *
	 * @var bool|null
	 */
	private $premium_active_cache = null;

	/**
	 * @var bool|null
	 */
	private $permission_cache = null;

	/**
	 * @var WC_Order|null|false
	 */
	private $current_order_cache = false;

	/**
	 * Computed order state cache keyed by order ID.
	 *
	 * @var array<int,array>
	 */
	private $order_state_cache = array();

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'add_button_to_order_edit' ) );
		add_action( 'wp_ajax_add_to_blacklist', array( $this, 'handle_add_to_suspects' ) );
		add_action( 'wp_ajax_block_customer', array( $this, 'handle_add_to_blocklist' ) );
		add_action( 'wp_ajax_remove_from_blacklist', array( $this, 'handle_remove_from_blacklist' ) );
		add_action( 'woocommerce_admin_order_data_after_payment_info', array( $this, 'display_blacklist_notices' ) );
	}

	/**
	 * Cached premium status.
	 */
	private function is_premium_active(): bool {
		if ( null === $this->premium_active_cache ) {
			$settings_instance          = new WC_Blacklist_Manager_Settings();
			$this->premium_active_cache = (bool) $settings_instance->is_premium_active();
		}

		return $this->premium_active_cache;
	}

	/**
	 * Cached permission check.
	 */
	private function current_user_can_manage_blacklist(): bool {
		if ( null !== $this->permission_cache ) {
			return $this->permission_cache;
		}

		if ( current_user_can( 'manage_options' ) ) {
			$this->permission_cache = true;
			return true;
		}

		$allowed_roles = get_option( 'wc_blacklist_dashboard_permission', array() );
		$allowed       = false;

		if ( is_array( $allowed_roles ) && ! empty( $allowed_roles ) ) {
			foreach ( $allowed_roles as $role ) {
				if ( current_user_can( $role ) ) {
					$allowed = true;
					break;
				}
			}
		}

		$this->permission_cache = $allowed;
		return $allowed;
	}

	/**
	 * Get current order object on edit-order admin pages only.
	 */
	private function get_current_admin_order() {
		if ( false !== $this->current_order_cache ) {
			return $this->current_order_cache;
		}

		global $pagenow;

		$order_id = 0;

		$is_legacy_edit_order_page = (
			'post.php' === $pagenow &&
			isset( $_GET['post'], $_GET['action'] ) &&
			'edit' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) &&
			'shop_order' === get_post_type( absint( $_GET['post'] ) )
		);

		$is_hpos_edit_order_page = (
			'admin.php' === $pagenow &&
			isset( $_GET['page'], $_GET['id'] ) &&
			'wc-orders' === sanitize_text_field( wp_unslash( $_GET['page'] ) )
		);

		if ( $is_legacy_edit_order_page ) {
			$order_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		} elseif ( $is_hpos_edit_order_page ) {
			$order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		}

		if ( $order_id <= 0 ) {
			$this->current_order_cache = null;
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			$this->current_order_cache = null;
			return null;
		}

		$this->current_order_cache = $order;
		return $order;
	}

	private function get_order_address_payloads( WC_Order $order ): array {
		$billing_country = sanitize_text_field( $order->get_billing_country() );

		$billing_address_data = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_billing_address_1() ),
				'address_2' => sanitize_text_field( $order->get_billing_address_2() ),
				'city'      => sanitize_text_field( $order->get_billing_city() ),
				'state'     => sanitize_text_field( $order->get_billing_state() ),
				'postcode'  => sanitize_text_field( $order->get_billing_postcode() ),
				'country'   => $billing_country,
			)
		);

		$shipping_address_data = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_shipping_address_1() ),
				'address_2' => sanitize_text_field( $order->get_shipping_address_2() ),
				'city'      => sanitize_text_field( $order->get_shipping_city() ),
				'state'     => sanitize_text_field( $order->get_shipping_state() ),
				'postcode'  => sanitize_text_field( $order->get_shipping_postcode() ),
				'country'   => sanitize_text_field( $order->get_shipping_country() ),
			)
		);

		return array(
			'billing'  => $billing_address_data,
			'shipping' => $shipping_address_data,
		);
	}

	private function get_existing_address_row_id( string $address_table_name, array $address_data, int $is_blocked ): int {
		global $wpdb;

		if ( empty( $address_data['address_hash'] ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				FROM {$address_table_name}
				WHERE match_type = %s
				AND address_hash = %s
				AND is_blocked = %d
				LIMIT 1",
				'address',
				$address_data['address_hash'],
				$is_blocked
			)
		);
	}

	private function insert_address_row( string $address_table_name, ?int $blacklist_id, array $address_data, int $is_blocked, string $notes = '' ): int {
		global $wpdb;

		if ( empty( $address_data['address_full_norm'] ) ) {
			return 0;
		}

		$inserted = $wpdb->insert(
			$address_table_name,
			array(
				'blacklist_id'               => $blacklist_id ? $blacklist_id : null,
				'match_type'                 => 'address',
				'is_blocked'                 => $is_blocked,
				'country_code'               => $address_data['country_code'] ?? '',
				'state_code'                 => $address_data['state_code'] ?? '',
				'city_norm'                  => $address_data['city_norm'] ?? '',
				'postcode_norm'              => $address_data['postcode_norm'] ?? '',
				'address_line_norm'          => $address_data['address_line_norm'] ?? '',
				'address_core_norm'          => $address_data['address_core_norm'] ?? '',
				'address_full_norm'          => $address_data['address_full_norm'] ?? '',
				'address_core_hash'          => $address_data['address_core_hash'] ?? '',
				'address_line_postcode_hash' => $address_data['address_line_postcode_hash'] ?? '',
				'address_hash'               => $address_data['address_hash'] ?? '',
				'address_display'            => $address_data['address_display'] ?? '',
				'notes'                      => $notes,
				'date_added'                 => current_time( 'mysql', 1 ),
			),
			array(
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	private function get_address_match_state( string $address_table_name, array $address_data ): array {
		global $wpdb;

		$result = array(
			'blocked_exact' => false,
			'suspect_exact' => false,
			'blocked_core'  => false,
			'suspect_core'  => false,
		);

		if ( ! empty( $address_data['address_hash'] ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT is_blocked
					FROM {$address_table_name}
					WHERE match_type = %s
					AND address_hash = %s
					LIMIT 2",
					'address',
					$address_data['address_hash']
				),
				ARRAY_A
			);

			if ( ! empty( $rows ) ) {
				foreach ( $rows as $row ) {
					if ( 1 === (int) $row['is_blocked'] ) {
						$result['blocked_exact'] = true;
					} else {
						$result['suspect_exact'] = true;
					}
				}
			}
		}

		if ( ! empty( $address_data['address_core_hash'] ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT is_blocked
					FROM {$address_table_name}
					WHERE match_type IN ('address', 'address_core')
					AND address_core_hash = %s
					LIMIT 2",
					$address_data['address_core_hash']
				),
				ARRAY_A
			);

			if ( ! empty( $rows ) ) {
				foreach ( $rows as $row ) {
					if ( 1 === (int) $row['is_blocked'] ) {
						$result['blocked_core'] = true;
					} else {
						$result['suspect_core'] = true;
					}
				}
			}
		}

		return $result;
	}

	private function get_order_device_id( WC_Order $order ): string {
		if ( ! $this->is_premium_active() ) {
			return '';
		}

		$device_id = (string) $order->get_meta( '_wc_bm_device_id', true );

		if ( '' === $device_id ) {
			return '';
		}

		$device_id = sanitize_text_field( $device_id );

		if ( ! preg_match( '/^[a-f0-9]{32,64}$/', $device_id ) ) {
			return '';
		}

		return $device_id;
	}

	private function sync_device_record_status( string $device_id, string $status = '', string $reason = '' ): void {
		global $wpdb;

		if ( empty( $device_id ) || ! preg_match( '/^[a-f0-9]{32,64}$/', $device_id ) ) {
			return;
		}

		$table_name = $wpdb->prefix . 'wc_blacklist_devices';

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE device_id = %s LIMIT 1",
				$device_id
			)
		);

		if ( ! $existing_id ) {
			return;
		}

		$status = sanitize_key( $status );
		if ( ! in_array( $status, array( '', 'suspect', 'blocked' ), true ) ) {
			$status = '';
		}

		$is_blocked = ( 'blocked' === $status ) ? 1 : 0;

		$wpdb->update(
			$table_name,
			array(
				'status'       => $status,
				'is_blocked'   => $is_blocked,
				'block_reason' => ( 'blocked' === $status ) ? sanitize_text_field( $reason ) : '',
			),
			array(
				'device_id' => $device_id,
			),
			array(
				'%s',
				'%d',
				'%s',
			),
			array(
				'%s',
			)
		);
	}

	private function maybe_sync_order_device_status( WC_Order $order, string $status = '', string $reason = '' ): void {
		$device_identity_enabled = get_option( 'wc_blacklist_enable_device_identity', 0 );

		if ( ! $this->is_premium_active() || '1' !== (string) $device_identity_enabled ) {
			return;
		}

		$device_id = $this->get_order_device_id( $order );

		if ( empty( $device_id ) ) {
			return;
		}

		$this->sync_device_record_status( $device_id, $status, $reason );
	}

	/**
	 * Build all identity inputs once.
	 */
	private function get_order_identity_context( WC_Order $order ): array {
		$phone      = sanitize_text_field( $order->get_billing_phone() );
		$email      = sanitize_email( $order->get_billing_email() );
		$first_name = sanitize_text_field( $order->get_billing_first_name() );
		$last_name  = sanitize_text_field( $order->get_billing_last_name() );
		$full_name  = trim( $first_name . ' ' . $last_name );
		$device_id  = $this->get_order_device_id( $order );

		if ( $this->is_premium_active() ) {
			$ip = get_post_meta( $order->get_id(), '_customer_ip_address', true );
			if ( empty( $ip ) ) {
				$ip = sanitize_text_field( $order->get_customer_ip_address() );
			}
		} else {
			$ip = sanitize_text_field( $order->get_customer_ip_address() );
		}

		$billing_country   = sanitize_text_field( $order->get_billing_country() );
		$billing_dial_code = yobm_get_country_dial_code( $billing_country );

		$normalized_phone = '';
		if ( ! empty( $phone ) ) {
			$normalized_phone = yobm_normalize_phone( $phone, $billing_dial_code );
		}

		$normalized_email = '';
		if ( ! empty( $email ) && is_email( $email ) ) {
			$normalized_email = yobm_normalize_email( $email );
		}

		$address_payloads = $this->get_order_address_payloads( $order );

		return array(
			'phone'                 => $phone,
			'email'                 => $email,
			'ip'                    => $ip,
			'first_name'            => $first_name,
			'last_name'             => $last_name,
			'full_name'             => $full_name,
			'device_id'             => $device_id,
			'billing_country'       => $billing_country,
			'billing_dial_code'     => $billing_dial_code,
			'normalized_phone'      => $normalized_phone,
			'normalized_email'      => $normalized_email,
			'billing_address_data'  => $address_payloads['billing'],
			'shipping_address_data' => $address_payloads['shipping'],
		);
	}

	/**
	 * Compute once per request/order and reuse everywhere.
	 */
	private function get_order_blacklist_state( WC_Order $order ): array {
		global $wpdb;

		$order_id = $order->get_id();

		if ( isset( $this->order_state_cache[ $order_id ] ) ) {
			return $this->order_state_cache[ $order_id ];
		}

		$table         = $wpdb->prefix . 'wc_blacklist';
		$address_table = $wpdb->prefix . 'wc_blacklist_addresses';

		$premium_active          = $this->is_premium_active();
		$ip_enabled              = ( '1' === (string) get_option( 'wc_blacklist_ip_enabled', 0 ) );
		$address_enabled         = ( '1' === (string) get_option( 'wc_blacklist_enable_customer_address_blocking', 0 ) );
		$shipping_address_enabled = ( '1' === (string) get_option( 'wc_blacklist_enable_shipping_address_blocking', 0 ) );
		$name_enabled            = ( '1' === (string) get_option( 'wc_blacklist_customer_name_blocking_enabled', 0 ) );
		$device_identity_enabled = ( '1' === (string) get_option( 'wc_blacklist_enable_device_identity', 0 ) );

		$ctx = $this->get_order_identity_context( $order );

		$show_remove_button_suspect_main    = $order->get_meta( '_blacklist_suspect_ids_main', true );
		$show_remove_button_suspect_address = $order->get_meta( '_blacklist_suspect_ids_address', true );
		$show_remove_button_block_main      = $order->get_meta( '_blacklist_blocked_ids_main', true );
		$show_remove_button_block_address   = $order->get_meta( '_blacklist_blocked_ids_address', true );
		$legacy_suspect                     = $order->get_meta( '_blacklist_suspect_id', true );
		$legacy_blocked                     = $order->get_meta( '_blacklist_blocked_id', true );

		$has_remove_button = ! empty( $show_remove_button_suspect_main )
			|| ! empty( $show_remove_button_suspect_address )
			|| ! empty( $show_remove_button_block_main )
			|| ! empty( $show_remove_button_block_address )
			|| ! empty( $legacy_suspect )
			|| ! empty( $legacy_blocked );

		$blocked_labels = array();
		$suspect_labels = array();

		$main_rows = array();

		$conditions = array();
		$params     = array();

		if ( '' !== $ctx['phone'] || '' !== $ctx['normalized_phone'] ) {
			$conditions[] = '( phone_number = %s OR ( %s <> "" AND normalized_phone = %s ) )';
			$params[]     = $ctx['phone'];
			$params[]     = $ctx['normalized_phone'];
			$params[]     = $ctx['normalized_phone'];
		}

		if ( '' !== $ctx['email'] && is_email( $ctx['email'] ) ) {
			$conditions[] = '( email_address = %s OR ( %s <> "" AND normalized_email = %s ) )';
			$params[]     = $ctx['email'];
			$params[]     = $ctx['normalized_email'];
			$params[]     = $ctx['normalized_email'];
		}

		if ( $ip_enabled && '' !== $ctx['ip'] ) {
			$conditions[] = 'ip_address = %s';
			$params[]     = $ctx['ip'];
		}

		if ( $premium_active && $device_identity_enabled && '' !== $ctx['device_id'] ) {
			$conditions[] = 'device_id = %s';
			$params[]     = $ctx['device_id'];
		}

		if ( $premium_active && $name_enabled && '' !== $ctx['full_name'] ) {
			$conditions[] = 'CONCAT(first_name, " ", last_name) = %s';
			$params[]     = $ctx['full_name'];
		}

		if ( ! empty( $conditions ) ) {
			$sql = "SELECT id, is_blocked, phone_number, normalized_phone, email_address, normalized_email, ip_address, device_id, first_name, last_name
				FROM {$table}
				WHERE " . implode( ' OR ', $conditions ) . '
				LIMIT 100';

			$prepared  = $wpdb->prepare( $sql, $params );
			$main_rows = $wpdb->get_results( $prepared, ARRAY_A );
		}

		$phone_exists   = true;
		$email_exists   = true;
		$ip_exists      = true;
		$device_exists  = true;
		$name_exists    = true;
		$phone_blocked  = true;
		$email_blocked  = true;
		$ip_blocked     = true;
		$device_blocked = true;
		$name_blocked   = true;

		if ( '' !== $ctx['phone'] || '' !== $ctx['normalized_phone'] ) {
			$phone_exists  = false;
			$phone_blocked = false;
		}

		if ( '' !== $ctx['email'] && is_email( $ctx['email'] ) ) {
			$email_exists  = false;
			$email_blocked = false;
		}

		if ( $ip_enabled && '' !== $ctx['ip'] ) {
			$ip_exists  = false;
			$ip_blocked = false;
		}

		if ( $premium_active && $device_identity_enabled && '' !== $ctx['device_id'] ) {
			$device_exists  = false;
			$device_blocked = false;
		}

		if ( $premium_active && $name_enabled && '' !== $ctx['full_name'] ) {
			$name_exists  = false;
			$name_blocked = false;
		}

		foreach ( $main_rows as $row ) {
			$is_blocked = 1 === (int) $row['is_blocked'];

			$row_full_name = trim( (string) $row['first_name'] . ' ' . (string) $row['last_name'] );

			$phone_match = (
				( '' !== $ctx['phone'] && (string) $row['phone_number'] === $ctx['phone'] ) ||
				( '' !== $ctx['normalized_phone'] && (string) $row['normalized_phone'] === $ctx['normalized_phone'] )
			);

			$email_match = (
				'' !== $ctx['email'] &&
				is_email( $ctx['email'] ) &&
				(
					(string) $row['email_address'] === $ctx['email'] ||
					( '' !== $ctx['normalized_email'] && (string) $row['normalized_email'] === $ctx['normalized_email'] )
				)
			);

			$ip_match = ( $ip_enabled && '' !== $ctx['ip'] && (string) $row['ip_address'] === $ctx['ip'] );
			$device_match = ( $premium_active && $device_identity_enabled && '' !== $ctx['device_id'] && (string) $row['device_id'] === $ctx['device_id'] );
			$name_match = ( $premium_active && $name_enabled && '' !== $ctx['full_name'] && $row_full_name === $ctx['full_name'] );

			if ( $phone_match ) {
				$phone_exists = true;
				if ( $is_blocked ) {
					$phone_blocked = true;
				}
			}

			if ( $email_match ) {
				$email_exists = true;
				if ( $is_blocked ) {
					$email_blocked = true;
				}
			}

			if ( $ip_match ) {
				$ip_exists = true;
				if ( $is_blocked ) {
					$ip_blocked = true;
				}
			}

			if ( $device_match ) {
				$device_exists = true;
				if ( $is_blocked ) {
					$device_blocked = true;
				}
			}

			if ( $name_match ) {
				$name_exists = true;
				if ( $is_blocked ) {
					$name_blocked = true;
				}
			}
		}

		if ( '' !== $ctx['phone'] || '' !== $ctx['normalized_phone'] ) {
			if ( $phone_blocked ) {
				$blocked_labels[] = __( 'phone', 'wc-blacklist-manager' );
			} elseif ( $phone_exists ) {
				$suspect_labels[] = __( 'phone', 'wc-blacklist-manager' );
			}
		}

		if ( '' !== $ctx['email'] && is_email( $ctx['email'] ) ) {
			if ( $email_blocked ) {
				$blocked_labels[] = __( 'email', 'wc-blacklist-manager' );
			} elseif ( $email_exists ) {
				$suspect_labels[] = __( 'email', 'wc-blacklist-manager' );
			}
		}

		if ( $ip_enabled && '' !== $ctx['ip'] ) {
			if ( $ip_blocked ) {
				$blocked_labels[] = __( 'IP', 'wc-blacklist-manager' );
			} elseif ( $ip_exists ) {
				$suspect_labels[] = __( 'IP', 'wc-blacklist-manager' );
			}
		}

		if ( $premium_active && $device_identity_enabled && '' !== $ctx['device_id'] ) {
			if ( $device_blocked ) {
				$blocked_labels[] = __( 'device identity', 'wc-blacklist-manager' );
			} elseif ( $device_exists ) {
				$suspect_labels[] = __( 'device identity', 'wc-blacklist-manager' );
			}
		}

		if ( $premium_active && $name_enabled && '' !== $ctx['full_name'] ) {
			$name_label = sprintf( __( 'customer name (%s)', 'wc-blacklist-manager' ), $ctx['full_name'] );

			if ( $name_blocked ) {
				$blocked_labels[] = $name_label;
			} elseif ( $name_exists ) {
				$suspect_labels[] = $name_label;
			}
		}

		if ( $address_enabled && $premium_active && ! empty( $ctx['billing_address_data']['address_full_norm'] ) ) {
			$billing_state = $this->get_address_match_state( $address_table, $ctx['billing_address_data'] );

			if ( $billing_state['blocked_exact'] ) {
				$blocked_labels[] = __( 'billing address', 'wc-blacklist-manager' );
			} elseif ( $billing_state['suspect_exact'] ) {
				$suspect_labels[] = __( 'billing address', 'wc-blacklist-manager' );
			} elseif ( $billing_state['blocked_core'] ) {
				$blocked_labels[] = __( 'billing address (core match)', 'wc-blacklist-manager' );
			} elseif ( $billing_state['suspect_core'] ) {
				$suspect_labels[] = __( 'billing address (core match)', 'wc-blacklist-manager' );
			}
		}

		if (
			$address_enabled &&
			$premium_active &&
			$shipping_address_enabled &&
			! empty( $ctx['shipping_address_data']['address_full_norm'] ) &&
			$ctx['shipping_address_data']['address_hash'] !== $ctx['billing_address_data']['address_hash']
		) {
			$shipping_state = $this->get_address_match_state( $address_table, $ctx['shipping_address_data'] );

			if ( $shipping_state['blocked_exact'] ) {
				$blocked_labels[] = __( 'shipping address', 'wc-blacklist-manager' );
			} elseif ( $shipping_state['suspect_exact'] ) {
				$suspect_labels[] = __( 'shipping address', 'wc-blacklist-manager' );
			} elseif ( $shipping_state['blocked_core'] ) {
				$blocked_labels[] = __( 'shipping address (core match)', 'wc-blacklist-manager' );
			} elseif ( $shipping_state['suspect_core'] ) {
				$suspect_labels[] = __( 'shipping address (core match)', 'wc-blacklist-manager' );
			}
		}

		$blocked_labels = array_values( array_unique( array_filter( $blocked_labels ) ) );
		$suspect_labels = array_values( array_unique( array_filter( $suspect_labels ) ) );

		$all_fields_exist = true;
		$all_fields_blocked = true;

		if ( '' !== $ctx['phone'] || '' !== $ctx['normalized_phone'] ) {
			$all_fields_exist   = $all_fields_exist && $phone_exists;
			$all_fields_blocked = $all_fields_blocked && $phone_blocked;
		}

		if ( '' !== $ctx['email'] && is_email( $ctx['email'] ) ) {
			$all_fields_exist   = $all_fields_exist && $email_exists;
			$all_fields_blocked = $all_fields_blocked && $email_blocked;
		}

		if ( $ip_enabled && '' !== $ctx['ip'] ) {
			$all_fields_exist   = $all_fields_exist && $ip_exists;
			$all_fields_blocked = $all_fields_blocked && $ip_blocked;
		}

		if ( $premium_active && $device_identity_enabled && '' !== $ctx['device_id'] ) {
			$all_fields_exist   = $all_fields_exist && $device_exists;
			$all_fields_blocked = $all_fields_blocked && $device_blocked;
		}

		$state = array(
			'ctx'                  => $ctx,
			'blocked_labels'       => $blocked_labels,
			'suspect_labels'       => $suspect_labels,
			'show_suspect_button'  => ! $all_fields_exist,
			'show_block_button'    => ! $all_fields_blocked,
			'has_remove_button'    => $has_remove_button,
			'has_suspect_meta'     => ! empty( $show_remove_button_suspect_main ) || ! empty( $show_remove_button_suspect_address ) || ! empty( $legacy_suspect ),
			'has_blocked_meta'     => ! empty( $show_remove_button_block_main ) || ! empty( $show_remove_button_block_address ) || ! empty( $legacy_blocked ),
		);

		$this->order_state_cache[ $order_id ] = $state;
		return $state;
	}

	public function enqueue_script() {
		if ( ! $this->current_user_can_manage_blacklist() ) {
			return;
		}

		$order = $this->get_current_admin_order();

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$state = $this->get_order_blacklist_state( $order );

		if ( ! $state['show_suspect_button'] && ! $state['show_block_button'] && ! $state['has_remove_button'] ) {
			return;
		}

		wp_enqueue_script(
			'yobm-order-actions',
			plugins_url( '../../js/blacklist-actions.js', __FILE__ ),
			array( 'jquery' ),
			$this->version,
			true
		);

		wp_localize_script(
			'yobm-order-actions',
			'yobmOrderActions',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'orderId' => (string) $order->get_id(),
				'nonces'  => array(
					'suspect' => wp_create_nonce( $this->suspect_nonce_key ),
					'block'   => wp_create_nonce( $this->block_nonce_key ),
					'remove'  => wp_create_nonce( $this->remove_nonce_key ),
				),
				'suspect' => array(
					'confirmMessage' => esc_html__( 'Are you sure you want to add this to the suspects list?', 'wc-blacklist-manager' ),
					'processingText' => esc_html__( 'Processing...', 'wc-blacklist-manager' ),
				),
				'block'   => array(
					'reasons' => array(
						'stolen_card'   => __( 'Stolen card', 'wc-blacklist-manager' ),
						'chargeback'    => __( 'Chargeback', 'wc-blacklist-manager' ),
						'fraud_network' => __( 'Fraud network', 'wc-blacklist-manager' ),
						'spam'          => __( 'Spam', 'wc-blacklist-manager' ),
						'policy_abuse'  => __( 'Policy abuse', 'wc-blacklist-manager' ),
						'other'         => __( 'Other', 'wc-blacklist-manager' ),
					),
					'descriptions' => array(
						'stolen_card'   => __( 'Payment appears to have been made using a stolen or unauthorized card/payment method.', 'wc-blacklist-manager' ),
						'chargeback'    => __( 'The transaction was charged back by the bank or payment provider as fraud or cardholder dispute.', 'wc-blacklist-manager' ),
						'fraud_network' => __( 'Identity is linked to multiple suspicious orders, bots, or coordinated fraud patterns across your store.', 'wc-blacklist-manager' ),
						'spam'          => __( 'Orders are clearly fake, low-value, or automated (e.g. card testing, dummy data, or bulk spam).', 'wc-blacklist-manager' ),
						'policy_abuse'  => __( 'Customer repeatedly abuses your store policies (refund abuse, return abuse, reselling, or similar).', 'wc-blacklist-manager' ),
					),
					'labels' => array(
						'modal_title'       => __( 'Block customer', 'wc-blacklist-manager' ),
						'reason_label'      => __( 'Reason', 'wc-blacklist-manager' ),
						'select_reason'     => __( 'Select a reason...', 'wc-blacklist-manager' ),
						'description_label' => __( 'Description / internal note', 'wc-blacklist-manager' ),
						'required_reason'   => __( 'Please select a reason.', 'wc-blacklist-manager' ),
						'required_desc'     => __( 'Please enter a description for “Other”.', 'wc-blacklist-manager' ),
						'cancel'            => __( 'Cancel', 'wc-blacklist-manager' ),
						'confirm'           => __( 'Confirm block', 'wc-blacklist-manager' ),
						'processingText'    => __( 'Processing...', 'wc-blacklist-manager' ),
					),
				),
				'remove'  => array(
					'reasons' => array(
						'customer_appeal'    => __( 'Customer appeal / cleared', 'wc-blacklist-manager' ),
						'merchant_error'     => __( 'Merchant error / mis-report', 'wc-blacklist-manager' ),
						'processor_decision' => __( 'Payment provider decision', 'wc-blacklist-manager' ),
						'duplicate'          => __( 'Duplicate report', 'wc-blacklist-manager' ),
						'test_data'          => __( 'Test / QA data', 'wc-blacklist-manager' ),
						'rvk_other'          => __( 'Other', 'wc-blacklist-manager' ),
					),
					'descriptions' => array(
						'customer_appeal'    => __( 'Customer provided evidence; after manual review, merchant agrees the customer is legitimate.', 'wc-blacklist-manager' ),
						'merchant_error'     => __( 'Wrong order / wrong customer / mis-click / misunderstood reason when reporting.', 'wc-blacklist-manager' ),
						'processor_decision' => __( 'Payment provider or bank dispute outcome indicates the original report no longer stands.', 'wc-blacklist-manager' ),
						'duplicate'          => __( 'Same incident or same identity accidentally reported multiple times by the same merchant.', 'wc-blacklist-manager' ),
						'test_data'          => __( 'Report was created from test / QA / staging data that slipped into production.', 'wc-blacklist-manager' ),
					),
					'labels' => array(
						'modal_title'       => __( 'Remove from the blacklist', 'wc-blacklist-manager' ),
						'reason_label'      => __( 'Revoke reason', 'wc-blacklist-manager' ),
						'select_reason'     => __( 'Select a reason...', 'wc-blacklist-manager' ),
						'description_label' => __( 'Note', 'wc-blacklist-manager' ),
						'required_reason'   => __( 'Please select a reason.', 'wc-blacklist-manager' ),
						'required_desc'     => __( 'Please enter a note for “Other”.', 'wc-blacklist-manager' ),
						'cancel'            => __( 'Cancel', 'wc-blacklist-manager' ),
						'confirm'           => __( 'Confirm remove', 'wc-blacklist-manager' ),
						'processingText'    => __( 'Processing...', 'wc-blacklist-manager' ),
					),
				),
			)
		);
	}

	public function add_button_to_order_edit( $order ) {
		if ( method_exists( $order, 'get_type' ) && 'shop_subscription' === $order->get_type() ) {
			return;
		}

		$premium_active  = $this->is_premium_active();
		$premium_version = defined( 'WC_BLACKLIST_MANAGER_PREMIUM_VERSION' ) ? WC_BLACKLIST_MANAGER_PREMIUM_VERSION : null;

		if (
			$premium_active &&
			$premium_version &&
			version_compare( $premium_version, '2.3.6', '<' )
		) {
			return;
		}

		if ( ! $this->current_user_can_manage_blacklist() ) {
			return;
		}

		$state = $this->get_order_blacklist_state( $order );

		echo '<div style="margin-top: 12px;" class="bm_order_actions">';
		echo '<h3>' . esc_html__( 'Blacklist actions', 'wc-blacklist-manager' ) . '</h3>';

		$install_date = get_option( 'wc_blacklist_manager_first_install_date' );

		if ( $install_date ) {
			$installed_time = strtotime( $install_date );
			$days_since     = ( time() - $installed_time ) / DAY_IN_SECONDS;

			if ( $days_since <= 7 && '1' === get_option( 'wc_blacklist_development_mode', '1' ) ) {
				$enable_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=wc_blacklist_enable_production_mode' ),
					'wc_blacklist_enable_production_mode'
				);

				echo '<div class="notice notice-warning inline" style="margin-top:10px;">';
				echo '<p>';
				echo '<strong>' . esc_html__( 'Development Mode Active.', 'wc-blacklist-manager' ) . '</strong> ';
				echo esc_html__( 'The blacklist system is currently running in development mode.', 'wc-blacklist-manager' ) . ' ';
				echo '<a href="' . esc_url( $enable_url ) . '">' . esc_html__( 'Switch to Production Mode', 'wc-blacklist-manager' ) . '</a>';
				echo '</p>';
				echo '</div>';
			}
		}

		echo '<p>';

		if ( $state['show_suspect_button'] ) {
			echo '<button id="add_to_blacklist" class="button button-secondary icon-button" title="' . esc_attr__( 'Add to the suspects list', 'wc-blacklist-manager' ) . '"><span class="dashicons dashicons-flag" style="margin-right: 3px;"></span> ' . esc_html__( 'Suspect', 'wc-blacklist-manager' ) . '</button> ';
		}

		if ( $state['show_block_button'] ) {
			echo '<button id="block_customer" class="button red-button" title="' . esc_attr__( 'Add to blocklist', 'wc-blacklist-manager' ) . '"><span class="dashicons dashicons-dismiss" style="margin-right: 3px;"></span> ' . esc_html__( 'Block', 'wc-blacklist-manager' ) . '</button>';
		} elseif ( ! $state['show_block_button'] && ! $state['has_blocked_meta'] ) {
			echo '<span style="color:#b32d2e;">' . esc_html__( 'This customer is already blocked.', 'wc-blacklist-manager' ) . '</span>';
		}

		if ( $state['has_suspect_meta'] || $state['has_blocked_meta'] ) {
			echo ' <button id="remove_from_blacklist" class="button button-secondary icon-button" title="' . esc_attr__( 'Remove', 'wc-blacklist-manager' ) . '"><span class="dashicons dashicons-remove" style="margin-right: 3px;"></span> ' . esc_html__( 'Remove', 'wc-blacklist-manager' ) . '</button>';
		}

		echo '</p>';

		if ( ! $premium_active ) {
			echo '<p class="bm_description"><a href="https://yoohw.com/product/blacklist-manager-premium/" target="_blank" title="Upgrade Premium"><span class="yoohw_unlock">Unlock</span></a> ' . esc_html__( 'to power up the blacklist management', 'wc-blacklist-manager' ) . '</p>';
		}

		echo '</div>';

		echo '
		<div class="bm-modal-backdrop" id="bmModalBackdrop"></div>
			<div class="bm-modal" id="bmModal" style="display:none;">
			<header><span id="bmModalTitle"></span></header>
			<div class="bm-body">
				<div class="bm-field">
					<label for="bm_reason" id="bmReasonLabel"></label>
					<select id="bm_reason"></select>
				</div>
				<div class="bm-field bm-reason-desc" id="bmReasonDescWrap" style="display:none;">
					<p id="bmReasonDesc"></p>
				</div>
				<div class="bm-field">
					<label for="bm_description" id="bmDescLabel"></label>
					<textarea id="bm_description" rows="4"></textarea>
				</div>
				<div class="bm-field bm-error" id="bmError" style="display:none;color:#b32d2e;"></div>
			</div>
			<footer>
				<button type="button" class="button" id="bmCancel"></button>
				<button type="button" class="button button-primary" id="bmConfirm"></button>
			</footer>
		</div>
		';
	}

	public function handle_add_to_suspects() {
		check_ajax_referer( $this->suspect_nonce_key, 'nonce' );

		global $wpdb;
		$table_name          = $wpdb->prefix . 'wc_blacklist';
		$address_table_name  = $wpdb->prefix . 'wc_blacklist_addresses';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
		$dev_mode            = get_option( 'wc_blacklist_development_mode', '0' );

		if ( ! $this->current_user_can_manage_blacklist() ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Permission denied.', 'wc-blacklist-manager' ),
				)
			);
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( $order_id <= 0 ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Invalid order ID.', 'wc-blacklist-manager' ),
				)
			);
		}

		$ip_blacklist_enabled           = get_option( 'wc_blacklist_ip_enabled', 0 );
		$address_blocking_enabled       = get_option( 'wc_blacklist_enable_customer_address_blocking', 0 );
		$shipping_blocking_enabled      = get_option( 'wc_blacklist_enable_shipping_address_blocking', 0 );
		$customer_name_blocking_enabled = get_option( 'wc_blacklist_customer_name_blocking_enabled', 0 );
		$device_identity_enabled        = get_option( 'wc_blacklist_enable_device_identity', 0 );
		$premium_active                 = $this->is_premium_active();

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Invalid order.', 'wc-blacklist-manager' ),
				)
			);
		}

		$ctx = $this->get_order_identity_context( $order );

		$source = sprintf( __( 'Order ID: %d', 'wc-blacklist-manager' ), $order_id );

		$contact_data = array(
			'sources'    => $source,
			'is_blocked' => 0,
			'order_id'   => $order_id,
			'date_added' => current_time( 'mysql', 1 ),
		);

		$insert_data = array();

		if ( ! empty( $ctx['phone'] ) ) {
			$insert_data['phone_number']     = $ctx['phone'];
			$insert_data['normalized_phone'] = $ctx['normalized_phone'];
		}

		if ( ! empty( $ctx['email'] ) && is_email( $ctx['email'] ) ) {
			$insert_data['email_address']    = $ctx['email'];
			$insert_data['normalized_email'] = $ctx['normalized_email'];
		}

		if ( $ip_blacklist_enabled && ! empty( $ctx['ip'] ) ) {
			$insert_data['ip_address'] = $ctx['ip'];
		}

		if ( $premium_active && $customer_name_blocking_enabled && ( ! empty( $ctx['first_name'] ) || ! empty( $ctx['last_name'] ) ) ) {
			$insert_data['first_name'] = $ctx['first_name'];
			$insert_data['last_name']  = $ctx['last_name'];
		}

		if ( $premium_active && $device_identity_enabled && ! empty( $ctx['device_id'] ) ) {
			$insert_data['device_id'] = $ctx['device_id'];
		}

		$insert_data = array_merge( $contact_data, $insert_data );

		$created_main_ids    = array();
		$created_address_ids = array();
		$new_blacklist_id    = 0;

		if ( ! empty( $insert_data ) ) {
			$wpdb->insert( $table_name, $insert_data );
			$new_blacklist_id = (int) $wpdb->insert_id;

			if ( $new_blacklist_id > 0 ) {
				$created_main_ids[] = $new_blacklist_id;

				if ( $premium_active && 'host' === get_option( 'wc_blacklist_connection_mode' ) && '0' === $dev_mode ) {
					$customer_domain = '';
					$is_blocked      = 0;
					$site_url        = site_url();
					$clean_domain    = preg_replace( '/^https?:\/\//', '', $site_url );
					$sources         = $clean_domain . '[' . $new_blacklist_id . ']';

					if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $ctx['phone'], $ctx['email'], $ctx['ip'], $customer_domain, $is_blocked, $sources, '', $ctx['first_name'], $ctx['last_name'] ) ) ) {
						wp_schedule_single_event(
							time() + 5,
							'wc_blacklist_connection_send_to_subsite',
							array( $ctx['phone'], $ctx['email'], $ctx['ip'], $customer_domain, $is_blocked, $sources, '', $ctx['first_name'], $ctx['last_name'] )
						);
					}
				}

				if ( $premium_active && 'sub' === get_option( 'wc_blacklist_connection_mode' ) && '0' === $dev_mode ) {
					if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) );
					}
				}
			}
		}

		if ( $premium_active && $address_blocking_enabled && ! empty( $ctx['billing_address_data']['address_full_norm'] ) ) {
			$existing_billing_id = $this->get_existing_address_row_id( $address_table_name, $ctx['billing_address_data'], 0 );

			if ( 0 === $existing_billing_id ) {
				$billing_address_id = $this->insert_address_row(
					$address_table_name,
					$new_blacklist_id > 0 ? $new_blacklist_id : null,
					$ctx['billing_address_data'],
					0,
					''
				);

				if ( $billing_address_id > 0 ) {
					$created_address_ids[] = $billing_address_id;

					if ( 'host' === get_option( 'wc_blacklist_connection_mode' ) && '0' === $dev_mode ) {
						$site_url     = site_url();
						$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
						$sources      = $clean_domain . '[address:' . $billing_address_id . ']';

						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( '', '', '', '', 0, $sources, $ctx['billing_address_data']['address_display'], '', '' ) ) ) {
							wp_schedule_single_event(
								time() + 5,
								'wc_blacklist_connection_send_to_subsite',
								array( '', '', '', '', 0, $sources, $ctx['billing_address_data']['address_display'], '', '' )
							);
						}
					}

					if ( 'sub' === get_option( 'wc_blacklist_connection_mode' ) && '0' === $dev_mode ) {
						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $billing_address_id ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $billing_address_id ) );
						}
					}
				}
			}
		}

		if (
			$premium_active &&
			$address_blocking_enabled &&
			$shipping_blocking_enabled &&
			! empty( $ctx['shipping_address_data']['address_full_norm'] ) &&
			$ctx['shipping_address_data']['address_hash'] !== $ctx['billing_address_data']['address_hash']
		) {
			$existing_shipping_id = $this->get_existing_address_row_id( $address_table_name, $ctx['shipping_address_data'], 0 );

			if ( 0 === $existing_shipping_id ) {
				$shipping_address_id = $this->insert_address_row(
					$address_table_name,
					$new_blacklist_id > 0 ? $new_blacklist_id : null,
					$ctx['shipping_address_data'],
					0,
					''
				);

				if ( $shipping_address_id > 0 ) {
					$created_address_ids[] = $shipping_address_id;

					if ( 'host' === get_option( 'wc_blacklist_connection_mode' ) && '0' === $dev_mode ) {
						$site_url     = site_url();
						$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
						$sources      = $clean_domain . '[address:' . $shipping_address_id . ']';

						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( '', '', '', '', 0, $sources, $ctx['shipping_address_data']['address_display'], '', '' ) ) ) {
							wp_schedule_single_event(
								time() + 5,
								'wc_blacklist_connection_send_to_subsite',
								array( '', '', '', '', 0, $sources, $ctx['shipping_address_data']['address_display'], '', '' )
							);
						}
					}

					if ( 'sub' === get_option( 'wc_blacklist_connection_mode' ) && '0' === $dev_mode ) {
						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $shipping_address_id ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $shipping_address_id ) );
						}
					}
				}
			}
		}

		if ( ! empty( $created_main_ids ) || ! empty( $created_address_ids ) ) {
			foreach ( $created_main_ids as $main_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_suspect_ids_main', $main_id );
			}

			foreach ( $created_address_ids as $address_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_suspect_ids_address', $address_id );
			}

			$order->delete_meta_data( '_blacklist_blocked_ids_main' );
			$order->delete_meta_data( '_blacklist_blocked_ids_address' );
			$order->delete_meta_data( '_blacklist_blocked_id' );

			$current_user = wp_get_current_user();
			$shop_manager = $current_user->display_name;

			$order->add_order_note(
				sprintf(
					esc_html__( 'Added to the suspect list by %s.', 'wc-blacklist-manager' ),
					$shop_manager
				),
				false
			);

			$order->save();

			if ( $premium_active ) {
				$details   = 'suspected_added_to_suspects_list_by:' . $shop_manager;
				$view_json = '';

				$wpdb->insert(
					$table_detection_log,
					array(
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'woo_order_' . $order_id,
						'action'    => 'suspect',
						'details'   => $details,
						'view'      => $view_json,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}

			$this->maybe_sync_order_device_status( $order, 'suspect', '' );

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Added to suspects list successfully.', 'wc-blacklist-manager' ),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => esc_html__( 'Nothing to add to the suspects list.', 'wc-blacklist-manager' ),
			)
		);
	}

	public function handle_add_to_blocklist() {
		check_ajax_referer( $this->block_nonce_key, 'nonce' );

		global $wpdb;
		$table_name          = $wpdb->prefix . 'wc_blacklist';
		$address_table_name  = $wpdb->prefix . 'wc_blacklist_addresses';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
		$premium_active      = $this->is_premium_active();
		$dev_mode            = get_option( 'wc_blacklist_development_mode', '0' );

		if ( ! $this->current_user_can_manage_blacklist() ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Permission denied.', 'wc-blacklist-manager' ),
				)
			);
		}

		$allowed_reasons = array( 'stolen_card', 'chargeback', 'fraud_network', 'spam', 'policy_abuse', 'other' );
		$reason_code_raw = isset( $_POST['reason_code'] ) ? wp_unslash( $_POST['reason_code'] ) : '';
		$reason_code     = sanitize_key( $reason_code_raw );

		if ( ! in_array( $reason_code, $allowed_reasons, true ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Invalid reason.', 'wc-blacklist-manager' ),
				)
			);
		}

		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		$reason_labels = array(
			'stolen_card'   => __( 'Stolen card', 'wc-blacklist-manager' ),
			'chargeback'    => __( 'Chargeback', 'wc-blacklist-manager' ),
			'fraud_network' => __( 'Fraud network', 'wc-blacklist-manager' ),
			'spam'          => __( 'Spam', 'wc-blacklist-manager' ),
			'policy_abuse'  => __( 'Policy abuse', 'wc-blacklist-manager' ),
			'other'         => __( 'Other', 'wc-blacklist-manager' ),
		);
		$reason_label = $reason_labels[ $reason_code ] ?? $reason_code;

		if ( 'other' === $reason_code && '' === $description ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please provide a description for “Other”.', 'wc-blacklist-manager' ),
				)
			);
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( $order_id <= 0 ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Invalid order ID.', 'wc-blacklist-manager' ),
				)
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Invalid order.', 'wc-blacklist-manager' ),
				)
			);
		}

		$current_user = wp_get_current_user();
		$shop_manager = $current_user && $current_user->exists() ? $current_user->display_name : __( 'System', 'wc-blacklist-manager' );

		$suspect_main_ids    = function_exists( 'yobm_parse_meta_id_list' ) ? yobm_parse_meta_id_list( $order->get_meta( '_blacklist_suspect_ids_main', true ) ) : array();
		$suspect_address_ids = function_exists( 'yobm_parse_meta_id_list' ) ? yobm_parse_meta_id_list( $order->get_meta( '_blacklist_suspect_ids_address', true ) ) : array();

		if ( empty( $suspect_main_ids ) ) {
			$legacy_suspect_ids = $order->get_meta( '_blacklist_suspect_id', true );
			if ( function_exists( 'yobm_parse_meta_id_list' ) ) {
				$suspect_main_ids = yobm_parse_meta_id_list( $legacy_suspect_ids );
			}
		}

		$moved_main_ids    = array();
		$moved_address_ids = array();

		if ( ! empty( $suspect_main_ids ) || ! empty( $suspect_address_ids ) ) {
			foreach ( $suspect_main_ids as $bid ) {
				$updated = $wpdb->update(
					$table_name,
					array(
						'is_blocked'  => 1,
						'reason_code' => $reason_code,
						'description' => $description,
					),
					array( 'id' => $bid ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);

				if ( false !== $updated ) {
					$moved_main_ids[] = (int) $bid;

					if ( $premium_active && 'host' === get_option( 'wc_blacklist_connection_mode' ) && '0' === $dev_mode ) {
						$is_blocked   = 1;
						$site_url     = site_url();
						$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
						$sources      = $clean_domain . '[' . $bid . ']';

						if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) );
						}
					}

					if ( $premium_active && 'sub' === get_option( 'wc_blacklist_connection_mode' ) && '0' === $dev_mode ) {
						if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_hostsite', array( $bid ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_hostsite', array( $bid ) );
						}
					}
				}
			}

			foreach ( $suspect_address_ids as $aid ) {
				$updated = $wpdb->update(
					$address_table_name,
					array(
						'is_blocked' => 1,
						'notes'      => $description,
					),
					array( 'id' => $aid ),
					array( '%d', '%s' ),
					array( '%d' )
				);

				if ( false !== $updated ) {
					$moved_address_ids[] = (int) $aid;

					if ( $premium_active && 'host' === get_option( 'wc_blacklist_connection_mode' ) && '0' === $dev_mode ) {
						$is_blocked   = 1;
						$site_url     = site_url();
						$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
						$sources      = $clean_domain . '[address:' . $aid . ']';

						if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) );
						}
					}

					if ( $premium_active && 'sub' === get_option( 'wc_blacklist_connection_mode' ) && '0' === $dev_mode ) {
						if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_hostsite', array( $aid ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_hostsite', array( $aid ) );
						}
					}
				}
			}

			$user_id      = $order->get_user_id();
			$user_blocked = false;

			if ( $user_id && '1' === (string) get_option( 'wc_blacklist_enable_user_blocking' ) ) {
				$user = get_userdata( $user_id );

				if ( $user && ! in_array( 'administrator', (array) $user->roles, true ) ) {
					$user_blocked = update_user_meta( $user_id, 'user_blocked', '1' );
				}
			}

			$order->delete_meta_data( '_blacklist_suspect_ids_main' );
			$order->delete_meta_data( '_blacklist_suspect_ids_address' );
			$order->delete_meta_data( '_blacklist_suspect_id' );

			foreach ( $moved_main_ids as $main_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_blocked_ids_main', $main_id );
			}

			foreach ( $moved_address_ids as $address_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_blocked_ids_address', $address_id );
			}

			$note_desc = '' !== $description ? ' — ' . $description : '';
			$order->add_order_note(
				sprintf(
					esc_html__( 'Moved to blocklist by %1$s. Reason: %2$s%3$s', 'wc-blacklist-manager' ),
					$shop_manager,
					$reason_label,
					$note_desc
				),
				false
			);

			$order->save();

			if ( YOGB_BM_Report::is_ready() && '0' === $dev_mode ) {
				YOGB_BM_Report::queue_report_from_order( $order, $reason_code, $description );
			}

			if ( $premium_active ) {
				$details = 'blocked_added_to_blocklist_by:' . $shop_manager;
				if ( ! empty( $user_blocked ) ) {
					$details .= ', blocked_user_attempt:' . $user_id;
				}

				$view_json = wp_json_encode(
					array(
						'reason_code' => $reason_code,
						'description' => $description,
					)
				);

				$wpdb->insert(
					$table_detection_log,
					array(
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'woo_order_' . $order_id,
						'action'    => 'block',
						'details'   => $details,
						'view'      => $view_json,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}

			$this->maybe_sync_order_device_status( $order, 'blocked', $reason_code );

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Moved to the blocklist successfully.', 'wc-blacklist-manager' ),
				)
			);
		}

		$ip_blacklist_enabled           = get_option( 'wc_blacklist_ip_enabled', 0 );
		$address_blocking_enabled       = get_option( 'wc_blacklist_enable_customer_address_blocking', 0 );
		$shipping_blocking_enabled      = get_option( 'wc_blacklist_enable_shipping_address_blocking', 0 );
		$customer_name_blocking_enabled = get_option( 'wc_blacklist_customer_name_blocking_enabled', 0 );
		$device_identity_enabled        = get_option( 'wc_blacklist_enable_device_identity', 0 );

		$ctx    = $this->get_order_identity_context( $order );
		$source = sprintf( __( 'Order ID: %d', 'wc-blacklist-manager' ), $order_id );

		$contact_data = array(
			'sources'     => $source,
			'is_blocked'  => 1,
			'order_id'    => $order_id,
			'reason_code' => $reason_code,
			'description' => $description,
			'date_added'  => current_time( 'mysql', 1 ),
		);

		$insert_data = array();

		if ( ! empty( $ctx['phone'] ) ) {
			$insert_data['phone_number']     = $ctx['phone'];
			$insert_data['normalized_phone'] = $ctx['normalized_phone'];
		}

		if ( ! empty( $ctx['email'] ) && is_email( $ctx['email'] ) ) {
			$insert_data['email_address']    = $ctx['email'];
			$insert_data['normalized_email'] = $ctx['normalized_email'];
		}

		if ( $ip_blacklist_enabled && ! empty( $ctx['ip'] ) ) {
			$insert_data['ip_address'] = $ctx['ip'];
		}

		if ( $premium_active && $customer_name_blocking_enabled && ( ! empty( $ctx['first_name'] ) || ! empty( $ctx['last_name'] ) ) ) {
			$insert_data['first_name'] = $ctx['first_name'];
			$insert_data['last_name']  = $ctx['last_name'];
		}

		if ( $premium_active && $device_identity_enabled && ! empty( $ctx['device_id'] ) ) {
			$insert_data['device_id'] = $ctx['device_id'];
		}

		$insert_data = array_merge( $contact_data, $insert_data );

		$created_main_ids    = array();
		$created_address_ids = array();
		$new_blacklist_id    = 0;

		if ( ! empty( $insert_data ) ) {
			$wpdb->insert( $table_name, $insert_data );
			$new_blacklist_id = (int) $wpdb->insert_id;

			if ( $new_blacklist_id > 0 ) {
				$created_main_ids[] = $new_blacklist_id;
			}
		}

		$user_id      = $order->get_user_id();
		$user_blocked = false;

		if ( $user_id && '1' === (string) get_option( 'wc_blacklist_enable_user_blocking' ) ) {
			$user = get_userdata( $user_id );

			if ( $user && ! in_array( 'administrator', (array) $user->roles, true ) ) {
				$user_blocked = update_user_meta( $user_id, 'user_blocked', '1' );
			}
		}

		if ( $premium_active && 'host' === get_option( 'wc_blacklist_connection_mode' ) && $new_blacklist_id > 0 && '0' === $dev_mode ) {
			$customer_domain = '';
			$is_blocked      = 1;
			$site_url        = site_url();
			$clean_domain    = preg_replace( '/^https?:\/\//', '', $site_url );
			$sources         = $clean_domain . '[' . $new_blacklist_id . ']';

			if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $ctx['phone'], $ctx['email'], $ctx['ip'], $customer_domain, $is_blocked, $sources, '', $ctx['first_name'], $ctx['last_name'] ) ) ) {
				wp_schedule_single_event(
					time() + 5,
					'wc_blacklist_connection_send_to_subsite',
					array( $ctx['phone'], $ctx['email'], $ctx['ip'], $customer_domain, $is_blocked, $sources, '', $ctx['first_name'], $ctx['last_name'] )
				);
			}
		}

		if ( $premium_active && 'sub' === get_option( 'wc_blacklist_connection_mode' ) && $new_blacklist_id > 0 && '0' === $dev_mode ) {
			if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) ) ) {
				wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) );
			}
		}

		if ( $premium_active && $address_blocking_enabled && ! empty( $ctx['billing_address_data']['address_full_norm'] ) ) {
			$existing_billing_id = $this->get_existing_address_row_id( $address_table_name, $ctx['billing_address_data'], 1 );

			if ( 0 === $existing_billing_id ) {
				$billing_address_id = $this->insert_address_row(
					$address_table_name,
					$new_blacklist_id > 0 ? $new_blacklist_id : null,
					$ctx['billing_address_data'],
					1,
					$description
				);

				if ( $billing_address_id > 0 ) {
					$created_address_ids[] = $billing_address_id;
				}
			}
		}

		if (
			$premium_active &&
			$address_blocking_enabled &&
			$shipping_blocking_enabled &&
			! empty( $ctx['shipping_address_data']['address_full_norm'] ) &&
			$ctx['shipping_address_data']['address_hash'] !== $ctx['billing_address_data']['address_hash']
		) {
			$existing_shipping_id = $this->get_existing_address_row_id( $address_table_name, $ctx['shipping_address_data'], 1 );

			if ( 0 === $existing_shipping_id ) {
				$shipping_address_id = $this->insert_address_row(
					$address_table_name,
					$new_blacklist_id > 0 ? $new_blacklist_id : null,
					$ctx['shipping_address_data'],
					1,
					$description
				);

				if ( $shipping_address_id > 0 ) {
					$created_address_ids[] = $shipping_address_id;
				}
			}
		}

		if ( ! empty( $created_main_ids ) || ! empty( $created_address_ids ) ) {
			foreach ( $created_main_ids as $main_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_blocked_ids_main', $main_id );
			}

			foreach ( $created_address_ids as $address_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_blocked_ids_address', $address_id );
			}

			$order->delete_meta_data( '_blacklist_suspect_ids_main' );
			$order->delete_meta_data( '_blacklist_suspect_ids_address' );
			$order->delete_meta_data( '_blacklist_suspect_id' );

			$note_desc = '' !== $description ? ' — ' . $description : '';
			$order->add_order_note(
				sprintf(
					esc_html__( 'Added to blocklist by %1$s. Reason: %2$s%3$s', 'wc-blacklist-manager' ),
					$shop_manager,
					$reason_label,
					$note_desc
				),
				false
			);

			$order->save();

			if ( YOGB_BM_Report::is_ready() && '0' === $dev_mode ) {
				YOGB_BM_Report::queue_report_from_order( $order, $reason_code, $description );
			}

			if ( $premium_active ) {
				$details = 'blocked_added_to_blocklist_by:' . $shop_manager;
				if ( ! empty( $user_blocked ) ) {
					$details .= ', blocked_user_attempt:' . $user_id;
				}

				$view_json = wp_json_encode(
					array(
						'reason_code' => $reason_code,
						'description' => $description,
					)
				);

				$wpdb->insert(
					$table_detection_log,
					array(
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'woo_order_' . $order_id,
						'action'    => 'block',
						'details'   => $details,
						'view'      => $view_json,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}

			$this->maybe_sync_order_device_status( $order, 'blocked', $reason_code );

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Added to blocklist successfully.', 'wc-blacklist-manager' ),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => esc_html__( 'Nothing to add to the blocklist.', 'wc-blacklist-manager' ),
			)
		);
	}

	public function handle_remove_from_blacklist() {
		check_ajax_referer( $this->remove_nonce_key, 'nonce' );

		global $wpdb;
		$table_main          = $wpdb->prefix . 'wc_blacklist';
		$table_address       = $wpdb->prefix . 'wc_blacklist_addresses';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
		$dev_mode            = get_option( 'wc_blacklist_development_mode', '0' );

		if ( ! $this->current_user_can_manage_blacklist() ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Permission denied.', 'wc-blacklist-manager' ),
				)
			);
		}

		$premium_active = $this->is_premium_active();

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( $order_id <= 0 ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Invalid order ID.', 'wc-blacklist-manager' ),
				)
			);
		}

		$revoke_reason = isset( $_POST['revoke_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['revoke_reason'] ) ) : 'rvk_other';
		$revoke_note   = isset( $_POST['revoke_note'] ) ? wp_strip_all_tags( wp_unslash( $_POST['revoke_note'] ) ) : '';

		$reason_labels = array(
			'customer_appeal'    => __( 'Customer appeal / cleared', 'wc-blacklist-manager' ),
			'merchant_error'     => __( 'Merchant error / mis-report', 'wc-blacklist-manager' ),
			'processor_decision' => __( 'Payment provider decision', 'wc-blacklist-manager' ),
			'duplicate'          => __( 'Duplicate report', 'wc-blacklist-manager' ),
			'test_data'          => __( 'Test / QA data', 'wc-blacklist-manager' ),
			'rvk_other'          => __( 'Other', 'wc-blacklist-manager' ),
		);
		$reason_label = $reason_labels[ $revoke_reason ] ?? $revoke_reason;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Order not found.', 'wc-blacklist-manager' ),
				)
			);
		}

		$blocked_main_ids    = function_exists( 'yobm_parse_meta_id_list' ) ? yobm_parse_meta_id_list( $order->get_meta( '_blacklist_blocked_ids_main', true ) ) : array();
		$blocked_address_ids = function_exists( 'yobm_parse_meta_id_list' ) ? yobm_parse_meta_id_list( $order->get_meta( '_blacklist_blocked_ids_address', true ) ) : array();
		$suspect_main_ids    = function_exists( 'yobm_parse_meta_id_list' ) ? yobm_parse_meta_id_list( $order->get_meta( '_blacklist_suspect_ids_main', true ) ) : array();
		$suspect_address_ids = function_exists( 'yobm_parse_meta_id_list' ) ? yobm_parse_meta_id_list( $order->get_meta( '_blacklist_suspect_ids_address', true ) ) : array();

		if ( empty( $blocked_main_ids ) && empty( $blocked_address_ids ) && empty( $suspect_main_ids ) && empty( $suspect_address_ids ) ) {
			$legacy_blocked = $order->get_meta( '_blacklist_blocked_id', true );
			$legacy_suspect = $order->get_meta( '_blacklist_suspect_id', true );

			if ( ! empty( $legacy_blocked ) && function_exists( 'yobm_parse_meta_id_list' ) ) {
				$blocked_main_ids = yobm_parse_meta_id_list( $legacy_blocked );
			} elseif ( ! empty( $legacy_suspect ) && function_exists( 'yobm_parse_meta_id_list' ) ) {
				$suspect_main_ids = yobm_parse_meta_id_list( $legacy_suspect );
			}
		}

		$remove_type        = '';
		$main_ids_to_delete = array();
		$addr_ids_to_delete = array();

		if ( ! empty( $blocked_main_ids ) || ! empty( $blocked_address_ids ) ) {
			$remove_type        = 'blocked';
			$main_ids_to_delete = $blocked_main_ids;
			$addr_ids_to_delete = $blocked_address_ids;
		} elseif ( ! empty( $suspect_main_ids ) || ! empty( $suspect_address_ids ) ) {
			$remove_type        = 'suspect';
			$main_ids_to_delete = $suspect_main_ids;
			$addr_ids_to_delete = $suspect_address_ids;
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'No blacklist entry found for this order.', 'wc-blacklist-manager' ),
				)
			);
		}

		$user_blocked = false;
		$user_id      = $order->get_user_id();

		if ( 'blocked' === $remove_type && $user_id && '1' === (string) get_option( 'wc_blacklist_enable_user_blocking' ) ) {
			$user = get_userdata( $user_id );

			if ( $user && ! in_array( 'administrator', (array) $user->roles, true ) ) {
				$user_blocked = update_user_meta( $user_id, 'user_blocked', '0' );
			}
		}

		$removed_main_count    = 0;
		$removed_address_count = 0;

		foreach ( $main_ids_to_delete as $entry_id ) {
			$deleted = $wpdb->delete( $table_main, array( 'id' => $entry_id ), array( '%d' ) );

			if ( false === $deleted ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							esc_html__( 'Database delete failed for main blacklist entry ID %d.', 'wc-blacklist-manager' ),
							(int) $entry_id
						),
					)
				);
			}

			$removed_main_count += (int) $deleted;
		}

		if ( $premium_active ) {
			foreach ( $addr_ids_to_delete as $entry_id ) {
				$deleted = $wpdb->delete( $table_address, array( 'id' => $entry_id ), array( '%d' ) );

				if ( false === $deleted ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								esc_html__( 'Database delete failed for address blacklist entry ID %d.', 'wc-blacklist-manager' ),
								(int) $entry_id
							),
						)
					);
				}

				$removed_address_count += (int) $deleted;
			}
		}

		$current_user = wp_get_current_user();
		$shop_manager = $current_user && $current_user->exists() ? $current_user->display_name : __( 'System', 'wc-blacklist-manager' );

		$order_note = sprintf(
			esc_html__(
				'The customer details have been removed from the blacklist by %1$s. Revoke reason: %2$s%3$s',
				'wc-blacklist-manager'
			),
			$shop_manager,
			$reason_label,
			$revoke_note ? ' – ' . $revoke_note : ''
		);

		$order->add_order_note( $order_note, false );

		$order->delete_meta_data( '_blacklist_blocked_ids_main' );
		$order->delete_meta_data( '_blacklist_blocked_ids_address' );
		$order->delete_meta_data( '_blacklist_suspect_ids_main' );
		$order->delete_meta_data( '_blacklist_suspect_ids_address' );
		$order->delete_meta_data( '_blacklist_blocked_id' );
		$order->delete_meta_data( '_blacklist_suspect_id' );
		$order->save();

		if ( $premium_active ) {
			$details = 'removed_from_blacklist_by:' . $shop_manager;

			if ( $user_blocked ) {
				$details .= ', user_attempt:' . $user_id;
			}

			$view_json = wp_json_encode(
				array(
					'reason_code'         => $revoke_reason,
					'note'                => $revoke_note ? substr( $revoke_note, 0, 255 ) : '',
					'removed_from'        => $remove_type,
					'removed_main_ids'    => array_values( array_map( 'intval', $main_ids_to_delete ) ),
					'removed_address_ids' => array_values( array_map( 'intval', $addr_ids_to_delete ) ),
					'removed_counts'      => array(
						'main'    => $removed_main_count,
						'address' => $removed_address_count,
					),
				)
			);

			$wpdb->insert(
				$table_detection_log,
				array(
					'timestamp' => current_time( 'mysql' ),
					'type'      => 'human',
					'source'    => 'woo_order_' . $order_id,
					'action'    => 'remove',
					'details'   => $details,
					'view'      => $view_json,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		if ( class_exists( 'YOGB_BM_Revoke_Report' ) && $order instanceof WC_Order && '0' === $dev_mode ) {
			YOGB_BM_Revoke_Report::queue_revoke_for_order( $order, $revoke_reason, $revoke_note );
		}

		$this->maybe_sync_order_device_status( $order, '', '' );

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Remove from the blacklist successfully.', 'wc-blacklist-manager' ),
				'type'    => $remove_type,
			)
		);
	}

	public function display_blacklist_notices( WC_Order $order ) {
		$state = $this->get_order_blacklist_state( $order );

		if ( ! empty( $state['blocked_labels'] ) ) {
			$msg = sprintf(
				__( "This order's %s in the blocklist.", 'wc-blacklist-manager' ),
				implode( ', ', $state['blocked_labels'] )
			);

			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}

		if ( ! empty( $state['suspect_labels'] ) ) {
			$msg = sprintf(
				__( "This order's %s in the suspect list.", 'wc-blacklist-manager' ),
				implode( ', ', $state['suspect_labels'] )
			);

			echo '<div class="notice notice-warning"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}
}

new WC_Blacklist_Manager_Order_Actions();

add_action(
	'admin_post_wc_blacklist_enable_production_mode',
	function() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wc-blacklist-manager' ) );
		}

		check_admin_referer( 'wc_blacklist_enable_production_mode' );

		update_option( 'wc_blacklist_development_mode', '0' );

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
);