<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Button_Add_To_Blacklist {
	private $version = '1.0.0';
	private $nonce_key = 'blacklist_ajax_nonce';

	public function __construct() {
		add_action('admin_enqueue_scripts', [$this, 'enqueue_script']);
		add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'add_button_to_order_edit']);
		add_action('wp_ajax_add_to_blacklist', [$this, 'handle_add_to_blacklist']);
		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'display_blacklist_warning']);
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
	
		$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', 0);
		$address_blocking_enabled = get_option('wc_blacklist_enable_customer_address_blocking', 0);
		$shipping_blocking_enabled = get_option('wc_blacklist_enable_shipping_address_blocking', 0);
		$customer_name_blocking_enabled = get_option('wc_blacklist_customer_name_blocking_enabled', 0);
	
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
	
		// Check if phone exists, exclude if empty
		if (!empty($phone)) {
			$phone_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE phone_number = %s", $phone)) > 0;
		} else {
			$phone_exists = true;
		}
	
		// Check if email exists, exclude if empty
		if (!empty($email)) {
			$email_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE email_address = %s", $email)) > 0;
		} else {
			$email_exists = true;
		}
	
		// Check if IP exists (only if IP blocking is enabled), exclude if empty
		if ($ip_blacklist_enabled && !empty($ip_address)) {
			$ip_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE ip_address = %s", $ip_address)) > 0;
		} else {
			$ip_exists = true;
		}
	
		// Check if customer address exists (only if premium is active and address blocking is enabled), exclude if empty
		if ($premium_active && $address_blocking_enabled && !empty($customer_address)) {
			$address_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE customer_address = %s", $customer_address)) > 0;
		} else {
			$address_exists = true;
		}

		if ($premium_active && $shipping_blocking_enabled && !empty($shipping_address) && $shipping_address !== $customer_address) {
			$shipping_address_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE customer_address = %s", $shipping_address)) > 0;
		} else {
			$shipping_address_exists = true;
		}
	
		// Check if customer name exists (only if premium is active and name blocking is enabled), exclude if empty
		if ($premium_active && $customer_name_blocking_enabled && !empty($full_name)) {
			$full_name_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE CONCAT(first_name, ' ', last_name) = %s", $full_name)) > 0;
		} else {
			$full_name_exists = true;
		}
	
		// Hide the button if all non-empty fields exist in the blacklist
		if ($phone_exists && $email_exists && $ip_exists && $address_exists && $shipping_address_exists && $full_name_exists) {
			return;
		}
	
		// Display the button if at least one non-empty field does not exist in the blacklist
		echo '<div style="margin-top: 12px;" id="add_to_blacklist_container">';
		echo '<button id="add_to_blacklist" class="button button-secondary icon-button" title="' . esc_html__('Add to the suspects list', 'wc-blacklist-manager') . '"><span class="dashicons dashicons-flag"></span></button>';
		echo '</div>';
	}	

	public function handle_add_to_blacklist() {
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
		if ($premium_active) {
			$ip_address = get_post_meta($order->get_id(), '_customer_ip_address', true);
		} else {
			$ip_address = sanitize_text_field($order->get_customer_ip_address());
		}
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
			'sources' => $source,
			'is_blocked' => 0,
			'date_added' => current_time('mysql', 1)
		];
	
		$insert_data = [];
	
		// Check and prepare phone number
		if (!empty($phone)) {
			$phone_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE phone_number = %s", $phone));
			if ($phone_exists == 0) {
				$insert_data['phone_number'] = $phone;
			}
		}
	
		// Check and prepare email address
		if (!empty($email)) {
			$email_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE email_address = %s", $email));
			if ($email_exists == 0) {
				$insert_data['email_address'] = $email;
			}
		}
	
		// Check and prepare IP address
		if ($ip_blacklist_enabled && !empty($ip_address)) {
			$ip_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE ip_address = %s", $ip_address));
			if ($ip_exists == 0) {
				$insert_data['ip_address'] = $ip_address;
			}
		}
	
		// Check and prepare customer address if premium is active
		if ($premium_active && $address_blocking_enabled && !empty($customer_address)) {
			$address_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE customer_address = %s", $customer_address));
			if ($address_exists == 0) {
				$insert_data['customer_address'] = $customer_address;
			}
		}

    // Add shipping address to a new row
    if ($premium_active && $shipping_blocking_enabled && !empty($shipping_address) && $shipping_address !== $customer_address) {
        $shipping_address_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE customer_address = %s", $shipping_address));
        if ($shipping_address_exists == 0) {
            $shipping_data = [
                'sources' => sprintf(__('Order ID: %d | Shipping', 'wc-blacklist-manager'), $order_id),
                'customer_address' => $shipping_address,
                'is_blocked' => 0,
                'date_added' => current_time('mysql', 1)
            ];
            $wpdb->insert($table_name, $shipping_data);
        }
    }

		// Check and prepare customer name if premium is active
		if ($premium_active && $customer_name_blocking_enabled) {
			if (!empty($first_name) && !empty($last_name)) {
				$full_name = $first_name . ', ' . $last_name;
				$full_name_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE CONCAT(first_name, ', ', last_name) = %s", $full_name));
				if ($full_name_exists == 0) {
					$insert_data['first_name'] = $first_name;
						$insert_data['last_name'] = $last_name;
				}
			}
		}
	
		// Merge contact data with insert data
		$insert_data = array_merge($contact_data, $insert_data);
	
		// Insert a new row if there are fields to insert
		if (!empty($insert_data)) {
			$wpdb->insert($table_name, $insert_data);
			echo esc_html__('Customer has been added to the Suspects list.', 'wc-blacklist-manager');
		} else {
			echo esc_html__('No new information to add to the Suspects list.', 'wc-blacklist-manager');
		}
	
		wp_die();
	}	

	public function display_blacklist_warning($order) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', 0);
		$address_blocking_enabled = get_option('wc_blacklist_enable_customer_address_blocking', 0);
		$customer_name_blocking_enabled = get_option('wc_blacklist_customer_name_blocking_enabled', 0);
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

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

		$suspect_list = [];

		if (!empty($phone)) {
			$phone_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE phone_number = %s AND is_blocked = 0", $phone));
			if ($phone_exists > 0) {
				$suspect_list[] = 'phone';
			}
		}

		if (!empty($email)) {
			$email_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE email_address = %s AND is_blocked = 0", $email));
			if ($email_exists > 0) {
				$suspect_list[] = 'email';
			}
		}

		if ($ip_blacklist_enabled && !empty($ip_address)) {
			$ip_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE ip_address = %s AND is_blocked = 0", $ip_address));
			if ($ip_exists > 0) {
				$suspect_list[] = 'IP';
			}
		}

		if ($address_blocking_enabled && !empty($customer_address)) {
			$address_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE customer_address = %s AND is_blocked = 0", $customer_address));
			if ($address_exists > 0) {
				$suspect_list[] = 'address';
			}
		}

		if ($customer_name_blocking_enabled && $premium_active) {
			if (!empty($first_name) && !empty($last_name)) {
				$full_name = $first_name . ', ' . $last_name;
				$full_name_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE CONCAT(first_name, ', ', last_name) = %s AND is_blocked = 0", $full_name));
				if ($full_name_exists > 0) {
					$suspect_list[] = 'name (' . $full_name . ')';
				}
			}
		}

		if (!empty($suspect_list)) {
			$notice_message = 'This order ' . implode(', ', $suspect_list) . ' is in the suspect list.';
			echo '<div class="notice notice-warning"><p>' . esc_html($notice_message) . '</p></div>';
		}
	}
}

new WC_Blacklist_Manager_Button_Add_To_Blacklist();
