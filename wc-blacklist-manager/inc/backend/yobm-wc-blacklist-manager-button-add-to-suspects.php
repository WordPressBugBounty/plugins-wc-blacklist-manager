<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Button_Add_To_Blacklist {
	private $version = '1.1';
	private $nonce_key = 'blacklist_ajax_nonce';

	public function __construct() {
		add_action('admin_enqueue_scripts', [$this, 'enqueue_script']);
		add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'add_button_to_order_edit']);
		add_action('wp_ajax_add_to_blacklist', [$this, 'handle_add_to_blacklist']);
		add_action('woocommerce_admin_order_data_after_payment_info', [$this, 'display_blacklist_notices']);
	}

	public function enqueue_script() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if (!$premium_active && !current_user_can('manage_options')) {
			return;
		}

		global $pagenow;
    
		// Check if we're on the WooCommerce Edit Order Page
		$is_legacy_edit_order_page = ($pagenow === 'post.php' && isset($_GET['post']) && isset($_GET['action']) && $_GET['action'] === 'edit' && get_post_type($_GET['post']) === 'shop_order');
		$is_hpos_edit_order_page = ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['id'])); // HPOS order edit page uses 'id' parameter
	
		if (!($is_legacy_edit_order_page || $is_hpos_edit_order_page)) {
			return;
		}

		$script_url = plugin_dir_url(__FILE__) . '../../js/yobm-wc-blacklist-manager-button-add-to-blacklist.js?v=' . $this->version;
		$script_url = filter_var($script_url, FILTER_SANITIZE_URL);
		if (!filter_var($script_url, FILTER_VALIDATE_URL)) {
			wp_die('Invalid script URL');
		}

		$escaped_script_url = esc_url($script_url);
		wp_enqueue_script('blacklist-ajax-script', $escaped_script_url, ['jquery'], null, true);

		$nonce = wp_create_nonce($this->nonce_key);
		$escaped_nonce = esc_attr($nonce);

		wp_localize_script('blacklist-ajax-script', 'blacklist_ajax_object', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => $escaped_nonce,
			'confirm_message' => esc_html__('Are you sure you want to add this to the suspects list?', 'wc-blacklist-manager')
		]);
	}

	public function add_button_to_order_edit($order) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if ($premium_active || !current_user_can('manage_options')) {
			return;
		}
		
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
	
		$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', 0);
		$address_blocking_enabled = get_option('wc_blacklist_enable_customer_address_blocking', 0);
		$shipping_blocking_enabled = get_option('wc_blacklist_enable_shipping_address_blocking', 0);
		$customer_name_blocking_enabled = get_option('wc_blacklist_customer_name_blocking_enabled', 0);
	
		$phone = sanitize_text_field($order->get_billing_phone());
		$email = sanitize_email($order->get_billing_email());
		$ip_address = sanitize_text_field($order->get_customer_ip_address());
	
		$address_1 = sanitize_text_field($order->get_billing_address_1());
		$address_2 = sanitize_text_field($order->get_billing_address_2());
		$city = sanitize_text_field($order->get_billing_city());
		$state = sanitize_text_field($order->get_billing_state());
		$postcode = sanitize_text_field($order->get_billing_postcode());
		$country = sanitize_text_field($order->get_billing_country());
		$address_parts = array_filter([$address_1, $address_2, $city, $state, $postcode, $country]);
		$customer_address = implode(', ', $address_parts);

		$shipping_address_1 = sanitize_text_field($order->get_shipping_address_1());
		$shipping_address_2 = sanitize_text_field($order->get_shipping_address_2());
		$shipping_city = sanitize_text_field($order->get_shipping_city());
		$shipping_state = sanitize_text_field($order->get_shipping_state());
		$shipping_postcode = sanitize_text_field($order->get_shipping_postcode());
		$shipping_country = sanitize_text_field($order->get_shipping_country());
		$shipping_address_parts = array_filter([$shipping_address_1, $shipping_address_2, $shipping_city, $shipping_state, $shipping_postcode, $shipping_country]);
		$shipping_address = implode(', ', $shipping_address_parts);
	
		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
		$full_name = $first_name . ' ' . $last_name;
	
		// Check if phone exists, exclude if empty
		if (!empty($phone)) {
			$phone_exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE phone_number = %s LIMIT 1", $phone));
		} else {
			$phone_exists = true;
		}
	
		// Check if email exists, exclude if empty
		if (!empty($email)) {
			$email_exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE email_address = %s LIMIT 1", $email));
		} else {
			$email_exists = true;
		}
	
		// Check if IP exists (only if IP blocking is enabled), exclude if empty
		if ($ip_blacklist_enabled && !empty($ip_address)) {
			$ip_exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE ip_address = %s LIMIT 1", $ip_address));
		} else {
			$ip_exists = true;
		}
	
		// Check if customer address exists (only if premium is active and address blocking is enabled), exclude if empty
		if ($premium_active && $address_blocking_enabled && !empty($customer_address)) {
			$address_exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE customer_address = %s LIMIT 1", $customer_address));
		} else {
			$address_exists = true;
		}

		if ($premium_active && $shipping_blocking_enabled && !empty($shipping_address) && $shipping_address !== $customer_address) {
			$shipping_address_exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE customer_address = %s LIMIT 1", $shipping_address));
		} else {
			$shipping_address_exists = true;
		}
	
		// Check if customer name exists (only if premium is active and name blocking is enabled), exclude if empty
		if ($premium_active && $customer_name_blocking_enabled && !empty($full_name)) {
			$full_name_exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE CONCAT(first_name, ' ', last_name) = %s LIMIT 1", $full_name));
		} else {
			$full_name_exists = true;
		}
	
		// Hide the button if all non-empty fields exist in the blacklist
		if ($phone_exists && $email_exists && $ip_exists && $address_exists && $shipping_address_exists && $full_name_exists) {
			return;
		}
	
		// Display the button if at least one non-empty field does not exist in the blacklist
		echo '<div style="margin-top: 12px;" class="bm_order_actions">';
		echo '<h3>' . esc_html__('Blacklist actions', 'wc-blacklist-manager') . '</h3>';
		echo '<p>';
		echo '<button id="add_to_blacklist" class="button button-secondary icon-button" title="' . esc_html__('Add to the suspects list', 'wc-blacklist-manager') . '"><span class="dashicons dashicons-flag" style="margin-right: 3px;"></span> ' . esc_html__('Suspect', 'wc-blacklist-manager') . '</button>';
		echo '<button class="button red-button" title="' . esc_html__('Add to blocklist', 'wc-blacklist-manager') . '" disabled><span class="dashicons dashicons-dismiss" style="margin-right: 3px;"></span> ' . esc_html__('Block', 'wc-blacklist-manager') . '</button>';
		echo '</p>';
		echo '<p class="bm_description"><a href="https://yoohw.com/product/woocommerce-blacklist-manager-premium/" target="_blank" title="Upgrade Premium"><span class="yoohw_unlock">' . 'Unlock ' . '</span></a> to power up the blacklist actions.' . '</p>';
		echo '</div>';
	}	

	public function handle_add_to_blacklist() {
		check_ajax_referer($this->nonce_key, 'nonce');
	
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
	
		$allowed_roles = get_option('wc_blacklist_dashboard_permission', []);
		$user_has_permission = false;
		if (is_array($allowed_roles) && !empty($allowed_roles)) {
			foreach ($allowed_roles as $role) {
				if (current_user_can($role)) {
					$user_has_permission = true;
					break;
				}
			}
		}
	
		if (!$user_has_permission && !current_user_can('manage_options')) {
			return;
		}
	
		$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
		if ($order_id <= 0) {
			echo esc_html__('Invalid order ID.', 'wc-blacklist-manager');
			wp_die();
		}
	
		$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', 0);
		$address_blocking_enabled = get_option('wc_blacklist_enable_customer_address_blocking', 0);
		$shipping_blocking_enabled = get_option('wc_blacklist_enable_shipping_address_blocking', 0);
		$customer_name_blocking_enabled = get_option('wc_blacklist_customer_name_blocking_enabled', 0);
	
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
	
		$order = wc_get_order($order_id);
		$phone = sanitize_text_field($order->get_billing_phone());
		$email = sanitize_email($order->get_billing_email());
		$ip_address = sanitize_text_field($order->get_customer_ip_address());

		$source = sprintf(__('Order ID: %d', 'wc-blacklist-manager'), $order_id);
	
		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
	
		$address_1 = sanitize_text_field($order->get_billing_address_1());
		$address_2 = sanitize_text_field($order->get_billing_address_2());
		$city = sanitize_text_field($order->get_billing_city());
		$state = sanitize_text_field($order->get_billing_state());
		$postcode = sanitize_text_field($order->get_billing_postcode());
		$country = sanitize_text_field($order->get_billing_country());
		$address_parts = array_filter([$address_1, $address_2, $city, $state, $postcode, $country]);
		$customer_address = implode(', ', $address_parts);
	
		$shipping_address_1 = sanitize_text_field($order->get_shipping_address_1());
		$shipping_address_2 = sanitize_text_field($order->get_shipping_address_2());
		$shipping_city = sanitize_text_field($order->get_shipping_city());
		$shipping_state = sanitize_text_field($order->get_shipping_state());
		$shipping_postcode = sanitize_text_field($order->get_shipping_postcode());
		$shipping_country = sanitize_text_field($order->get_shipping_country());
		$shipping_address_parts = array_filter([$shipping_address_1, $shipping_address_2, $shipping_city, $shipping_state, $shipping_postcode, $shipping_country]);
		$shipping_address = implode(', ', $shipping_address_parts);
	
		// Initialize contact data
		$contact_data = [
			'sources'    => $source,
			'is_blocked' => 0,
			'order_id'   => $order_id,
			'date_added' => current_time('mysql', 1)
		];
	
		$insert_data = [];
	
		// Insert phone number if not empty
		if (!empty($phone)) {
			$insert_data['phone_number'] = $phone;
		}
	
		// Insert email address if not empty
		if (!empty($email)) {
			$insert_data['email_address'] = $email;
		}
	
		// Insert IP address if enabled and not empty
		if ($ip_blacklist_enabled && !empty($ip_address)) {
			$insert_data['ip_address'] = $ip_address;
		}
	
		// Insert customer address if premium is active, address blocking enabled, and not empty
		if ($premium_active && $address_blocking_enabled && !empty($customer_address)) {
			$insert_data['customer_address'] = $customer_address;
		}

		// Insert customer name if premium is active, customer name blocking enabled, and both first and last name are not empty
		if ($premium_active && $customer_name_blocking_enabled) {
			if (!empty($first_name) && !empty($last_name)) {
				$insert_data['first_name'] = $first_name;
				$insert_data['last_name']  = $last_name;
			}
		}		
	
		// Merge contact data with the insert data
		$insert_data = array_merge($contact_data, $insert_data);
	
		// Insert a new row if there are fields to insert
		if (!empty($insert_data)) {
			$wpdb->insert($table_name, $insert_data);
			$new_blacklist_id = $wpdb->insert_id;

			if ($premium_active && get_option('wc_blacklist_connection_mode') === 'host') {
				$customer_domain = '';
				$is_blocked = 0;
				
				$site_url = site_url();
				$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
				$sources = $clean_domain . '[' . $new_blacklist_id . ']';

				if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, $customer_address, $first_name, $last_name ) ) ) {
					wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_subsite', array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, $customer_address, $first_name, $last_name ) );
				}
			}

			if ($premium_active && get_option('wc_blacklist_connection_mode') === 'sub') {
				if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) ) ) {
					wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) );
				}
			}
	
			$current_meta = $order->get_meta('_blacklist_suspect_id', true);
			if (!empty($current_meta)) {
				$new_meta = $current_meta . ',' . $new_blacklist_id;
			} else {
				$new_meta = $new_blacklist_id;
			}
	
			$order->delete_meta_data('_blacklist_blocked_id');
			$order->update_meta_data('_blacklist_suspect_id', $new_meta);
			$order->save();
	
			if ( $premium_active ) {
				$current_user = wp_get_current_user();
				$shop_manager = $current_user->display_name;

				$details = 'suspected_added_to_suspects_list_by:' . $shop_manager;
				$view_json = '';

				$wpdb->insert(
					$table_detection_log,
					[
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'woo_order_' . $order_id,
						'action'    => 'suspect',
						'details'   => $details,
						'view'      => $view_json,
					],
					[ '%s', '%s', '%s', '%s', '%s', '%s' ]
				);

				$order->add_order_note(
					sprintf(
						esc_html__('The customer details have been added to the suspects list by %s.', 'wc-blacklist-manager'),
						$shop_manager
					),
					false
				);
			}
			
			echo esc_html__('Added to suspects list successfully.', 'wc-blacklist-manager');
		} else {
			echo esc_html__('Nothing to add to the suspects list.', 'wc-blacklist-manager');
		}

		// Add shipping address to a new row if premium is active, shipping blocking enabled, not empty, and different from customer address
		if ($premium_active && $address_blocking_enabled && $shipping_blocking_enabled && !empty($shipping_address) && $shipping_address !== $customer_address) {
			$shipping_data = [
				'sources'          => sprintf(__('Order ID: %d | Shipping', 'wc-blacklist-manager'), $order_id),
				'customer_address' => $shipping_address,
				'is_blocked'       => 0,
				'order_id'         => $order_id,
				'date_added'       => current_time('mysql')
			];
			$wpdb->insert($table_name, $shipping_data);
			$shipping_blacklist_id = $wpdb->insert_id;

			if (get_option('wc_blacklist_connection_mode') === 'host') {
				$phone_holder = '';
				$email_holder = '';
				$ip_address_holder = '';
				$customer_domain_holder = '';
				$first_name_holder = '';
				$last_name_holder = '';
				$is_blocked_holder = 0;
				
				$site_url_holder = site_url();
				$clean_domain_holder = preg_replace( '/^https?:\/\//', '', $site_url_holder );
				$sources_holder = $clean_domain_holder . '[' . $shipping_blacklist_id . ']';

				if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $phone_holder, $email_holder, $ip_address_holder, $customer_domain_holder, $is_blocked_holder, $sources_holder, $shipping_address, $first_name_holder, $last_name_holder ) ) ) {
					wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_subsite', array( $phone_holder, $email_holder, $ip_address_holder, $customer_domain_holder, $is_blocked_holder, $sources_holder, $shipping_address, $first_name_holder, $last_name_holder ) );
				}
			}

			if (get_option('wc_blacklist_connection_mode') === 'sub') {
				if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $shipping_blacklist_id ) ) ) {
					wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $shipping_blacklist_id ) );
				}
			}
	
			$current_meta = $order->get_meta('_blacklist_suspect_id', true);
			if (!empty($current_meta)) {
				$new_meta = $current_meta . ',' . $shipping_blacklist_id;
			} else {
				$new_meta = $shipping_blacklist_id;
			}
	
			$order->update_meta_data('_blacklist_suspect_id', $new_meta);
			$order->save();
		}		
	
		wp_die();
	}
	
	public function display_blacklist_notices( WC_Order $order ) {		
		global $wpdb;
		$table = $wpdb->prefix . 'wc_blacklist';

		// Feature flags
		$ip_enabled        = get_option( 'wc_blacklist_ip_enabled', 0 );
		$address_enabled   = get_option( 'wc_blacklist_enable_customer_address_blocking', 0 );
		$name_enabled      = get_option( 'wc_blacklist_customer_name_blocking_enabled', 0 );
		$premium_active    = ( new WC_Blacklist_Manager_Settings() )->is_premium_active();

		// Gather customer data
		$phone    = sanitize_text_field( $order->get_billing_phone() );
		$email    = sanitize_email( $order->get_billing_email() );
		if ( $premium_active ) {
			$ip = get_post_meta( $order->get_id(), '_customer_ip_address', true );
			if ( empty( $ip ) ) {
				$ip = sanitize_text_field( $order->get_customer_ip_address() );
			}
		} else {
			$ip = sanitize_text_field( $order->get_customer_ip_address() );
		}
		$address  = implode( ', ', array_filter([
					sanitize_text_field( $order->get_billing_address_1() ),
					sanitize_text_field( $order->get_billing_address_2() ),
					sanitize_text_field( $order->get_billing_city() ),
					sanitize_text_field( $order->get_billing_state() ),
					sanitize_text_field( $order->get_billing_postcode() ),
					sanitize_text_field( $order->get_billing_country() ),
					]) );
		$full_name = trim( sanitize_text_field( $order->get_billing_first_name() ) . ' ' . sanitize_text_field( $order->get_billing_last_name() ) );

		$blocked = [];
		$suspect = [];

		// Helper to check one field
		$check_field = function( $column, $value, $label ) use ( $wpdb, $table, &$blocked, &$suspect ) {
			// block check
			$is_blocked = (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE {$column} = %s AND is_blocked = 1 LIMIT 1",
				$value
			) );
			if ( $is_blocked ) {
				$blocked[] = $label;
			} else {
				// suspect check
				$is_suspect = (bool) $wpdb->get_var( $wpdb->prepare(
					"SELECT 1 FROM {$table} WHERE {$column} = %s AND is_blocked = 0 LIMIT 1",
					$value
				) );
				if ( $is_suspect ) {
					$suspect[] = $label;
				}
			}
		};

		// Full name (premium only)
		if ( $name_enabled && $premium_active && $full_name ) {
			// use CONCAT(first_name,' ',last_name) for lookup
			// with label including the actual name
			$label = sprintf( __( 'customer name (%s)', 'wc-blacklist-manager' ), $full_name );
			$check_field( "CONCAT(first_name,' ',last_name)", $full_name, $label );
		}

		// Phone
		if ( $phone ) {
			$check_field( 'phone_number', $phone, __( 'phone', 'wc-blacklist-manager' ) );
		}

		// Email
		if ( $email ) {
			$check_field( 'email_address', $email, __( 'email', 'wc-blacklist-manager' ) );
		}

		// IP
		if ( $ip_enabled && $ip ) {
			$check_field( 'ip_address', $ip, __( 'IP', 'wc-blacklist-manager' ) );
		}

		// Address
		if ( $address_enabled && $premium_active && $address ) {
			$check_field( 'customer_address', $address, __( 'address', 'wc-blacklist-manager' ) );
		}

		// Display blocked notice first (as an error)
		if ( ! empty( $blocked ) ) {
			$msg = sprintf(
				/* translators: %s = comma-separated list of blocked fields */
				__( "This order's %s in the blocklist.", 'wc-blacklist-manager' ),
				implode( ', ', $blocked )
			);
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// Then display suspect warning for any remaining fields
		if ( ! empty( $suspect ) ) {
			$msg = sprintf(
				/* translators: %s = comma-separated list of suspect fields */
				__( "This order's %s in the suspect list.", 'wc-blacklist-manager' ),
				implode( ', ', $suspect )
			);
			echo '<div class="notice notice-warning"><p>' . esc_html( $msg ) . '</p></div>';
		}	
	}
}

new WC_Blacklist_Manager_Button_Add_To_Blacklist();
