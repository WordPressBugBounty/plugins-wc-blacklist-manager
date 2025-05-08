<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Suspected_Email {
	public function __construct() {
		add_action('woocommerce_new_order', [$this, 'check_order_and_notify']);
	}

	public function check_order_and_notify($order_id) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
	
		$order = wc_get_order($order_id);

		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
		$customer_name = trim($first_name . ' ' . $last_name);

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

				$sum_block_phone = get_option('wc_blacklist_sum_block_phone', 0);
				update_option('wc_blacklist_sum_block_phone', $sum_block_phone + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$timestamp = current_time('mysql');
					$type      = 'human';
					$source    = 'woo_order_' . $order_id;
					$action    = 'suspect';
					$details   = 'suspected_phone_attempt: ' . $phone;
					
					$wpdb->insert(
						$table_detection_log,
						array(
							'timestamp' => $timestamp,
							'type'      => $type,
							'source'    => $source,
							'action'    => $action,
							'details'   => $details,
						)
					);
				}
			} else {
				$phone = '';
			}
		} else {
			$phone = '';
		}

		// Check for blacklisted email address
		if (!empty($email) && is_email($email)) {
			$email_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 0 LIMIT 1",
				$email
			)));
			if ($email_blacklisted) {
				$send_email = true;

				$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
				update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$timestamp = current_time('mysql');
					$type      = 'human';
					$source    = 'woo_order_' . $order_id;
					$action    = 'suspect';
					$details   = 'suspected_email_attempt: ' . $email;
					
					$wpdb->insert(
						$table_detection_log,
						array(
							'timestamp' => $timestamp,
							'type'      => $type,
							'source'    => $source,
							'action'    => $action,
							'details'   => $details,
						)
					);
				}
			} else {
				$email = '';
			}
		} else {
			$email = '';
		}

		// Check for blacklisted customer name (combining first and last name).
		if (!empty($customer_name) && $premium_active && get_option('wc_blacklist_customer_name_blocking_enabled') === '1') {
			$customer_name_blacklisted = !empty($wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$table_name} WHERE LOWER(CONCAT(first_name, ' ', last_name)) = %s AND is_blocked = 0 LIMIT 1",
					$customer_name
				)
			));
			if ($customer_name_blacklisted) {
				$send_email = true;

				$sum_block_name = get_option('wc_blacklist_sum_block_name', 0);
				update_option('wc_blacklist_sum_block_name', $sum_block_name + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				$timestamp = current_time('mysql');
				$type      = 'human';
				$source    = 'woo_order_' . $order_id;
				$action    = 'suspect';
				$details   = 'suspected_name_attempt: ' . $customer_name;
				
				$wpdb->insert(
					$table_detection_log,
					array(
						'timestamp' => $timestamp,
						'type'      => $type,
						'source'    => $source,
						'action'    => $action,
						'details'   => $details,
					)
				);
			} else {
				$customer_name = '';
			}
		} else {
			$customer_name = '';
		}

		// Check for blacklisted IP address
		if (!empty($user_ip) && get_option('wc_blacklist_ip_enabled') === '1') {
			$ip_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE ip_address = %s AND is_blocked = 0 LIMIT 1",
				$user_ip
			)));
			if ($ip_blacklisted) {
				$send_email = true;

				$sum_block_ip = get_option('wc_blacklist_sum_block_ip', 0);
				update_option('wc_blacklist_sum_block_ip', $sum_block_ip + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$timestamp = current_time('mysql');
					$type      = 'human';
					$source    = 'woo_order_' . $order_id;
					$action    = 'suspect';
					$details   = 'suspected_ip_attempt: ' . $user_ip;
					
					$wpdb->insert(
						$table_detection_log,
						array(
							'timestamp' => $timestamp,
							'type'      => $type,
							'source'    => $source,
							'action'    => $action,
							'details'   => $details,
						)
					);
				}
			} else {
				$user_ip = '';
			}
		} else {
			$user_ip = '';
		}

		// Check for blacklisted customer address
		if (!empty($customer_address) && $premium_active && get_option('wc_blacklist_enable_customer_address_blocking') === '1') {
			$address_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE customer_address = %s AND is_blocked = 0 LIMIT 1",
				$customer_address
			)));
			if ($address_blacklisted) {
				$send_email = true;

				$sum_block_address = get_option('wc_blacklist_sum_block_address', 0);
				update_option('wc_blacklist_sum_block_address', $sum_block_address + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				$timestamp = current_time('mysql');
				$type      = 'human';
				$source    = 'woo_order_' . $order_id;
				$action    = 'suspect';
				$details   = 'suspected_billing_address_attempt: ' . $customer_address;
				
				$wpdb->insert(
					$table_detection_log,
					array(
						'timestamp' => $timestamp,
						'type'      => $type,
						'source'    => $source,
						'action'    => $action,
						'details'   => $details,
					)
				);
			} else {
				$customer_address = '';
			}
		} else {
			$customer_address = '';
		}

		// Check for blacklisted shipping address
		if (!empty($shipping_address) && $premium_active && get_option('wc_blacklist_enable_customer_address_blocking') === '1' && get_option('wc_blacklist_enable_shipping_address_blocking') === '1') {
			$shipping_address_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE customer_address = %s AND is_blocked = 0 LIMIT 1",
				$shipping_address
			)));
			if ($shipping_address_blacklisted) {
				$send_email = true;

				$sum_block_address = get_option('wc_blacklist_sum_block_address', 0);
				update_option('wc_blacklist_sum_block_address', $sum_block_address + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				$timestamp = current_time('mysql');
				$type      = 'human';
				$source    = 'woo_order_' . $order_id;
				$action    = 'suspect';
				$details   = 'suspected_shipping_address_attempt: ' . $shipping_address;
				
				$wpdb->insert(
					$table_detection_log,
					array(
						'timestamp' => $timestamp,
						'type'      => $type,
						'source'    => $source,
						'action'    => $action,
						'details'   => $details,
					)
				);
			} else {
				$shipping_address = '';
			}
		} else {
			$shipping_address = '';
		}

		if ($send_email && get_option('wc_blacklist_email_notification', 'no') === 'yes') {
			$email_sender = new WC_Blacklist_Manager_Email();
			$email_sender->send_email_order_suspect(
				$order_id,
				$customer_name,
				$phone,
				$email,
				$user_ip,
				$customer_address,
				$shipping_address,
				$order_edit_url
			);
		}
	}
}

new WC_Blacklist_Manager_Suspected_Email();
