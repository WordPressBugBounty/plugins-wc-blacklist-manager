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
			'confirm_message' => esc_html__('Are you sure you want to add this to the blocked list?', 'wc-blacklist-manager')
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

		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
		$full_name = $first_name . ', ' . $last_name;

		$show_block_button = $this->should_show_block_button($phone, $email, $ip_address, $customer_address, $full_name);
		$blocked_notice = $this->generate_blocked_notice($phone, $email, $ip_address, $customer_address, $full_name);

		if ($blocked_notice) {
			echo '<div class="notice notice-error"><p>' . esc_html($blocked_notice) . '</p></div>';
		}

		if ($show_block_button) {
			echo '<div style="margin-top: 12px;" id="block_customer_container">';
			echo '<button id="block_customer" class="button red-button" title="Add to Blocked List"><span class="dashicons dashicons-dismiss"></span></button>';
			echo '</div>';
		}
	}

	private function should_show_block_button($phone, $email, $ip_address, $customer_address, $full_name) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
	
		$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', 0);
		$address_blocking_enabled = get_option('wc_blacklist_enable_customer_address_blocking', 0);
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
	
		if ($premium_active && $customer_name_blocking_enabled && !empty($full_name)) {
			$full_name_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE CONCAT(first_name, ', ', last_name) = %s", $full_name)) > 0;
			$full_name_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE CONCAT(first_name, ', ', last_name) = %s AND is_blocked = 1", $full_name)) > 0;
			$all_empty = false;
		} else {
			$full_name_exists = true; // If premium is not active, name blocking is disabled, or full name is empty, we consider it non-blocked
			$full_name_blocked = true; // Set to true if premium is not active or name blocking is disabled
		}
	
		// If all fields are empty, do not display the button
		if ($all_empty) {
			return false;
		}
	
		// Check if all fields exist and are blocked
		$all_fields_exist = $phone_exists && $email_exists && $ip_exists && $address_exists && $full_name_exists;
		$all_fields_blocked = $phone_blocked && $email_blocked && $ip_blocked && $address_blocked && $full_name_blocked;
	
		// Hide the button if all fields exist and are blocked or not all fields exist
		if ($all_fields_exist && $all_fields_blocked) {
			return false;
		}
	
		if (!($phone_exists && $email_exists && $ip_exists && $address_exists && $full_name_exists)) {
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
				$blocked_fields[] = 'phone';
			}
		}

		if (!empty($email)) {
			$email_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE email_address = %s AND is_blocked = 1", $email)) > 0;
			if ($email_blocked) {
				$blocked_fields[] = 'email';
			}
		}

		if ($ip_blacklist_enabled && !empty($ip_address)) {
			$ip_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE ip_address = %s AND is_blocked = 1", $ip_address)) > 0;
			if ($ip_blocked) {
				$blocked_fields[] = 'IP';
			}
		}

		if ($address_blocking_enabled && !empty($customer_address)) {
			$address_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE customer_address = %s AND is_blocked = 1", $customer_address)) > 0;
			if ($address_blocked) {
				$blocked_fields[] = 'address';
			}
		}

		if ($customer_name_blocking_enabled && $premium_active && !empty($full_name)) {
			$full_name_blocked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE CONCAT(first_name, ', ', last_name) = %s AND is_blocked = 1", $full_name)) > 0;
			if ($full_name_blocked) {
				$blocked_fields[] = 'name (' . $full_name . ')';
			}
		}

		if (!empty($blocked_fields)) {
			return 'This order ' . implode(', ', $blocked_fields) . ' is blocked.';
		}

		return '';
	}

	public function handle_block_customer() {
		check_ajax_referer($this->nonce_key, 'nonce');

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

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
		$phone = sanitize_text_field($order->get_billing_phone());
		$email = sanitize_email($order->get_billing_email());
		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
		$full_name = $first_name . ', ' . $last_name;

		$user_id = $order->get_user_id();
		if ($user_id) {
			$user = get_userdata($user_id);
			if ($user && in_array('administrator', $user->roles)) {
				echo esc_html__('Cannot block the administrators.', 'wc-blacklist-manager');
				wp_die();
			}
		}

		$entry_exists = $this->get_customer_entry($phone, $email, $full_name);

		if ($entry_exists) {
			$wpdb->update($table_name, ['is_blocked' => 1], ['id' => $entry_exists->id]);
			echo esc_html__('Customer has been moved to the blocked list.', 'wc-blacklist-manager');

			if ($user_id) {
				if (get_option('wc_blacklist_enable_user_blocking') == '1') {
					update_user_meta($user_id, 'user_blocked', '1');
				}
			}

			if (get_option('wc_blacklist_enable_customer_address_blocking') == '1') {
				$address_1 = sanitize_text_field($order->get_billing_address_1());
				$address_2 = sanitize_text_field($order->get_billing_address_2());
				$city = sanitize_text_field($order->get_billing_city());
				$state = sanitize_text_field($order->get_billing_state());
				$postcode = sanitize_text_field($order->get_billing_postcode());
				$country = sanitize_text_field($order->get_billing_country());

				$address_parts = array_filter([$address_1, $address_2, $city, $state, $postcode, $country]);
				$customer_address = implode(', ', $address_parts);

				$address_exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE customer_address = %s", $customer_address));
				if ($address_exists) {
					$wpdb->update($table_name, ['is_blocked' => 1], ['id' => $address_exists->id]);
					echo esc_html__('Address has been moved to the blocked list.', 'wc-blacklist-manager');
				}
			}

		} else {
			echo esc_html__('Customer is not in the blacklist.', 'wc-blacklist-manager');
		}

		wp_die();
	}

	private function get_customer_entry($phone, $email, $full_name) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		if (!empty($phone)) {
			return $wpdb->get_row($wpdb->prepare(
				"SELECT * FROM `{$table_name}` WHERE phone_number = %s AND is_blocked = 0", 
				$phone
			));
		} elseif (!empty($email)) {
			return $wpdb->get_row($wpdb->prepare(
				"SELECT * FROM `{$table_name}` WHERE email_address = %s AND is_blocked = 0", 
				$email
			));
		} elseif (!empty($full_name)) {
			return $wpdb->get_row($wpdb->prepare(
				"SELECT * FROM `{$table_name}` WHERE CONCAT(first_name, ', ', last_name) = %s AND is_blocked = 0", 
				$full_name
			));
		}

		return false;
	}
}

new WC_Blacklist_Manager_Button_Add_To_Blocklist();
