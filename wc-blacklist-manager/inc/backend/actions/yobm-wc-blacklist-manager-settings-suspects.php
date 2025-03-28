<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Suspected_Email {
	public function __construct() {
		add_action('woocommerce_new_order', [$this, 'check_order_and_notify']);
	}

	public function check_order_and_notify($order_id) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
	
		$order = wc_get_order($order_id);
		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
		$phone = sanitize_text_field($order->get_billing_phone());
		$email = sanitize_email($order->get_billing_email());
		$user_ip = sanitize_text_field($order->get_customer_ip_address());
		$order_edit_url = admin_url('post.php?post=' . absint($order_id) . '&action=edit');
	
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
	
		$send_email = false;
	
		// Check for blacklisted phone number
		if (!empty($phone)) {
			$phone_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE TRIM(LEADING '0' FROM phone_number) = %s AND is_blocked = 0 LIMIT 1",
				$phone
			)));
			if ($phone_blacklisted) {
				$send_email = true;
			}
		}

		// Check for blacklisted email address
		if (!empty($email) && is_email($email)) {
			$email_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 0 LIMIT 1",
				$email
			)));
			if ($email_blacklisted) {
				$send_email = true;
			}
		}

		// Check for blacklisted IP address
		if (!empty($user_ip)) {
			$ip_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE ip_address = %s AND is_blocked = 0 LIMIT 1",
				$user_ip
			)));
			if ($ip_blacklisted) {
				$send_email = true;
			}
		}

		// Check for blacklisted customer address
		if (!empty($customer_address)) {
			$address_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE customer_address = %s AND is_blocked = 0 LIMIT 1",
				$customer_address
			)));
			if ($address_blacklisted) {
				$send_email = true;
			}
		}

		// Check for blacklisted shipping address
		if (!empty($shipping_address)) {
			$shipping_address_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE customer_address = %s AND is_blocked = 0 LIMIT 1",
				$shipping_address
			)));
			if ($shipping_address_blacklisted) {
				$send_email = true;
			}
		}

		if ($send_email && get_option('wc_blacklist_email_notification', 'no') === 'yes') {
			$this->send_notification_email($order_id, $first_name, $last_name, $phone, $email, $user_ip, $customer_address, $shipping_address, $order_edit_url);
		}
	}
	
	private function send_notification_email($order_id, $first_name, $last_name, $phone, $email, $user_ip, $customer_address, $shipping_address, $order_edit_url) {
		$subject_template = get_option('wc_blacklist_email_subject', __('WARNING: Order {order_id} from blacklisted customer!', 'wc-blacklist-manager'));
	
		// Define the path to the HTML template
		$template_path = plugin_dir_path(__FILE__) . '../emails/templates/yobm-wc-blacklist-manager-email-template-suspicious-order-alert.html';
	
		// Check if the template file exists
		if (!file_exists($template_path)) {
			return; // Template file not found, exit the function
		}
	
		// Load the HTML template from the file
		$html_template = file_get_contents($template_path);
	
		// Get the suspicious order content from the options or use the default message
		$default_message = 'The customer {first_name} {last_name} with the {phone} {email} has placed a suspicious order.';
		$suspicious_order_content = get_option('wc_blacklist_email_message', $default_message);
		if (empty($suspicious_order_content)) {
			$suspicious_order_content = $default_message;
		}
	
		$content_replacements = [
			'{first_name}' => esc_html($first_name),
			'{last_name}' => esc_html($last_name),
			'{phone}' => esc_html($phone),
			'{email}' => esc_html($email),
			'{order_id}' => esc_html($order_id),
			'{user_ip}' => esc_html($user_ip),
			'{billing_address}' => esc_html($customer_address),
			'{shipping_address}' => esc_html($shipping_address),
		];
	
		$suspicious_order_content = strtr($suspicious_order_content, $content_replacements);
	
		$replacements = [
			'{{order_id}}' => esc_html($order_id),
			'{{suspicious_order_content}}' => wpautop(esc_html($suspicious_order_content)),
			'{{edit_order_url}}' => esc_url($order_edit_url)
		];
	
		$subject = strtr($subject_template, $content_replacements);
		$message = strtr($html_template, $replacements);
	
		$additional_emails = get_option('wc_blacklist_additional_emails', '');
		$emails = array_filter(array_map('trim', explode(',', $additional_emails)), 'is_email');
		$emails[] = get_option('admin_email');
		$emails = array_unique($emails);
	
		add_filter('wp_mail_content_type', function() { return 'text/html'; });
	
		foreach ($emails as $recipient_email) {
			wp_mail($recipient_email, $subject, $message);
		}
	
		remove_filter('wp_mail_content_type', function() { return 'text/html'; });
	}    
}

new WC_Blacklist_Manager_Suspected_Email();
