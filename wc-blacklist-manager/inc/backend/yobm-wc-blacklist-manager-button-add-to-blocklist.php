<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Button_Add_To_Blocklist {
	private $version = '1.0.0';
	private $nonce_key = 'block_ajax_nonce';

	public function __construct() {
		add_action('admin_enqueue_scripts', [$this, 'enqueue_script']);
		add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'add_button_to_order_edit']);
		add_action('wp_ajax_block_customer', [$this, 'handle_block_customer']);
	}

	public function enqueue_script() {
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

		global $pagenow;
    
		// Check if we're on the WooCommerce Edit Order Page
		$is_legacy_edit_order_page = ($pagenow === 'post.php' && isset($_GET['post']) && isset($_GET['action']) && $_GET['action'] === 'edit' && get_post_type($_GET['post']) === 'shop_order');
		$is_hpos_edit_order_page = ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['id'])); // HPOS order edit page uses 'id' parameter
	
		if (!($is_legacy_edit_order_page || $is_hpos_edit_order_page)) {
			return;
		}

		$script_url = plugin_dir_url(__FILE__) . '../../js/yobm-wc-blacklist-manager-button-add-to-blocklist.js?v=' . $this->version;
		$script_url = filter_var($script_url, FILTER_SANITIZE_URL);
		if (!filter_var($script_url, FILTER_VALIDATE_URL)) {
			wp_die('Invalid script URL');
		}

		$escaped_script_url = esc_url($script_url);
		wp_enqueue_script('block-ajax-script', $escaped_script_url, ['jquery'], null, true);

		$nonce = wp_create_nonce($this->nonce_key);
		$escaped_nonce = esc_attr($nonce);

		wp_localize_script('block-ajax-script', 'block_ajax_object', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => $escaped_nonce,
			'confirm_message' => esc_html__('Are you sure you want to block this customer?', 'wc-blacklist-manager')
		]);
	}

	public function add_button_to_order_edit($order) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		
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

		if (!$premium_active && !current_user_can('manage_options')) {
			return;
		}
		
		if (!$user_has_permission && !current_user_can('manage_options')) {
			return;
		}

		$phone = sanitize_text_field($order->get_billing_phone());
		$email = sanitize_email($order->get_billing_email());
		if ($premium_active) {
			$ip_address = get_post_meta($order->get_id(), '_customer_ip_address', true);
		} else {
			$ip_address = sanitize_text_field($order->get_customer_ip_address());
		}

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

		$show_block_button = $this->should_show_block_button($phone, $email, $ip_address, $customer_address, $shipping_address, $full_name);
		$blocked_notice = $this->generate_blocked_notice($phone, $email, $ip_address, $customer_address, $full_name);

		if ($blocked_notice) {
			echo '<div class="notice notice-error"><p>' . esc_html($blocked_notice) . '</p></div>';
		}

		if ($show_block_button) {
			echo '<div style="margin-top: 12px;" id="block_customer_container">';
			echo '<button id="block_customer" class="button red-button" title="' . esc_html__('Add to blocklist', 'wc-blacklist-manager') . '"><span class="dashicons dashicons-dismiss"></span></button>';
			echo '</div>';
		}
	}

	private function should_show_block_button($phone, $email, $ip_address, $customer_address, $shipping_address, $full_name) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
	
		$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', 0);
		$address_blocking_enabled = get_option('wc_blacklist_enable_customer_address_blocking', 0);
		$shipping_blocking_enabled = get_option('wc_blacklist_enable_shipping_address_blocking', 0);
		$customer_name_blocking_enabled = get_option('wc_blacklist_customer_name_blocking_enabled', 0);
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
	
		// Initialize flags to determine if all fields are empty
		$all_empty = true;
	
		// Check if phone exists and is blocked
		if (!empty($phone)) {
			$phone_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE phone_number = %s", $phone)) > 0;
			$phone_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE phone_number = %s AND is_blocked = 1", $phone)) > 0;
			$all_empty = false;
		} else {
			$phone_exists = true; // If phone is empty, we exclude it
			$phone_blocked = true;
		}
	
		// Check if email exists and is blocked
		if (!empty($email)) {
			$email_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE email_address = %s", $email)) > 0;
			$email_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE email_address = %s AND is_blocked = 1", $email)) > 0;
			$all_empty = false;
		} else {
			$email_exists = true; // If email is empty, we exclude it
			$email_blocked = true;
		}
	
		// Check if IP exists and is blocked
		if ($ip_blacklist_enabled && !empty($ip_address)) {
			$ip_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE ip_address = %s", $ip_address)) > 0;
			$ip_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE ip_address = %s AND is_blocked = 1", $ip_address)) > 0;
			$all_empty = false;
		} else {
			$ip_exists = true; // If IP blocking is disabled or IP is empty, we exclude it
			$ip_blocked = true;
		}
	
		// Address and Full Name logic based on premium and option settings
		if ($premium_active && $address_blocking_enabled && !empty($customer_address)) {
			$address_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE customer_address = %s", $customer_address)) > 0;
			$address_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE customer_address = %s AND is_blocked = 1", $customer_address)) > 0;
			$all_empty = false;
		} else {
			$address_exists = true; // If premium is not active, address blocking is disabled, or address is empty, we consider it non-blocked
			$address_blocked = true; // Set to true if premium is not active or address blocking is disabled
		}

		if ($premium_active && $shipping_blocking_enabled && !empty($shipping_address) && $shipping_address !== $customer_address) {
			$shipping_address_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE customer_address = %s", $shipping_address)) > 0;
			$shipping_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE customer_address = %s AND is_blocked = 1", $shipping_address)) > 0;
			$all_empty = false;
		} else {
			$shipping_address_exists = true;
			$shipping_blocked = true;
		}
	
		if ($premium_active && $customer_name_blocking_enabled && !empty($full_name)) {
			$full_name_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE CONCAT(first_name, ' ', last_name) = %s", $full_name)) > 0;
			$full_name_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE CONCAT(first_name, ' ', last_name) = %s AND is_blocked = 1", $full_name)) > 0;
			$all_empty = false;
		} else {
			$full_name_exists = true;
			$full_name_blocked = true;
		}
	
		// If all fields are empty, do not display the button
		if ($all_empty) {
			return false;
		}
	
		// Check if all fields exist and are blocked
		$all_fields_exist = $phone_exists && $email_exists && $ip_exists && $address_exists && $shipping_address_exists && $full_name_exists;
		$all_fields_blocked = $phone_blocked && $email_blocked && $ip_blocked && $address_blocked && $shipping_blocked && $full_name_blocked;
	
		// Hide the button if all fields exist and are blocked or not all fields exist
		if ($all_fields_exist && $all_fields_blocked) {
			return false;
		}
	
		if (!($phone_exists && $email_exists && $ip_exists && $address_exists && $shipping_address_exists && $full_name_exists)) {
			return false;
		}
	
		// Display the button in other cases
		return true;
	}	
	
	private function generate_blocked_notice($phone, $email, $ip_address, $customer_address, $full_name) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
	
		$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', 0);
		$address_blocking_enabled = get_option('wc_blacklist_enable_customer_address_blocking', 0);
		$customer_name_blocking_enabled = get_option('wc_blacklist_customer_name_blocking_enabled', 0);
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
	
		$blocked_fields = [];
	
		if (!empty($phone)) {
			$phone_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE phone_number = %s AND is_blocked = 1", $phone)) > 0;
			if ($phone_blocked) {
				$blocked_fields[] = __('phone', 'wc-blacklist-manager');
			}
		}
	
		if (!empty($email)) {
			$email_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE email_address = %s AND is_blocked = 1", $email)) > 0;
			if ($email_blocked) {
				$blocked_fields[] = __('email', 'wc-blacklist-manager');
			}
		}
	
		if ($ip_blacklist_enabled && !empty($ip_address)) {
			$ip_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE ip_address = %s AND is_blocked = 1", $ip_address)) > 0;
			if ($ip_blocked) {
				$blocked_fields[] = __('IP', 'wc-blacklist-manager');
			}
		}
	
		if ($address_blocking_enabled && !empty($customer_address)) {
			$address_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE customer_address = %s AND is_blocked = 1", $customer_address)) > 0;
			if ($address_blocked) {
				$blocked_fields[] = __('customer address', 'wc-blacklist-manager');
			}
		}
	
		if ($customer_name_blocking_enabled && $premium_active && !empty($full_name)) {
			$full_name_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE CONCAT(first_name, ' ', last_name) = %s AND is_blocked = 1", $full_name)) > 0;
			if ($full_name_blocked) {
				$blocked_fields[] = sprintf(__('name (%s)', 'wc-blacklist-manager'), $full_name);
			}
		}
	
		if (!empty($blocked_fields)) {
			return sprintf(__('This order\'s %s in the blocklist.', 'wc-blacklist-manager'), implode(', ', $blocked_fields));
		}
	
		return '';
	}	

	public function handle_block_customer() {
		check_ajax_referer($this->nonce_key, 'nonce');
	
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
	
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
	
		if (!current_user_can('edit_posts')) {
			wp_die(esc_html__('You do not have sufficient permissions', 'wc-blacklist-manager'));
		}
	
		$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
		if ($order_id <= 0) {
			echo esc_html__('Invalid order ID.', 'wc-blacklist-manager');
			wp_die();
		}
	
		$order = wc_get_order($order_id);
		$user_id = $order->get_user_id();
		if ($user_id) {
			$user = get_userdata($user_id);
			if ($user && in_array('administrator', $user->roles)) {
				echo esc_html__('Cannot block the administrators.', 'wc-blacklist-manager');
				wp_die();
			}
		}
	
		// Get the blacklist suspect IDs from order meta
		$blacklist_meta = $order->get_meta('_blacklist_suspect_id', true);
	
		if (!empty($blacklist_meta)) {
			// Explode the meta value to get an array of IDs (ensuring they're integers)
			$blacklist_ids = array_map('intval', explode(',', $blacklist_meta));
	
			// Loop through each ID and update the corresponding row in the table
			foreach ($blacklist_ids as $bid) {
				$wpdb->update(
					$table_name,
					['is_blocked' => 1],
					['id' => $bid]
				);

				if ($premium_active && get_option('wc_blacklist_connection_mode') === 'host') {
					$is_blocked = 1;

					$site_url = site_url();
					$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
					$sources = $clean_domain . '[' . $bid . ']';

					if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) );
					}
				}

				if ($premium_active && get_option('wc_blacklist_connection_mode') === 'sub') {
					if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_hostsite', array( $bid ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_hostsite', array( $bid ) );
					}
				}
			}
	
			echo esc_html__('The customer\'s details have been moved to the blocklist.', 'wc-blacklist-manager');
	
			// Optionally update the user meta for user blocking if enabled
			if ($user_id && get_option('wc_blacklist_enable_user_blocking') == '1') {
				update_user_meta($user_id, 'user_blocked', '1');
			}
	
			// Delete the suspect meta and add blocked meta with the previous value
			$order->delete_meta_data('_blacklist_suspect_id');
			$order->update_meta_data('_blacklist_blocked_id', $blacklist_meta);
			$order->save();
		} else {
			echo esc_html__('Customer is not in the blacklist.', 'wc-blacklist-manager');
		}
	
		wp_die();
	}	
}

new WC_Blacklist_Manager_Button_Add_To_Blocklist();
