<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Suspected_Email {
	public function __construct() {
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'schedule_check_and_notify_any' ], 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'schedule_check_and_notify_any' ], 10, 1 );

		add_action( 'wc_blacklist_check_and_notify', [ $this, 'check_order_and_notify' ], 10, 1 );
	}

	/**
	 * Queue the check/notify into Action Scheduler (works for BOTH classic + blocks).
	 *
	 * @param int|\WC_Order $arg1 Order ID (classic) or WC_Order (blocks)
	 */
	public function schedule_check_and_notify_any( $arg1, $maybe_posted = null, $maybe_order = null ) {
		// Determine order ID from either flow
		if ( $arg1 instanceof \WC_Order ) {
			$order_id = $arg1->get_id();              // Blocks: passes WC_Order
		} elseif ( is_numeric( $arg1 ) ) {
			$order_id = (int) $arg1;                  // Classic: first param is order_id
		} elseif ( $maybe_order instanceof \WC_Order ) {
			$order_id = $maybe_order->get_id();       // Classic: 3rd param WC_Order (if you add accepted args)
		} else {
			return;
		}

		// Ensure Action Scheduler is available, or fall back to direct
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			if ( defined( 'WC_ABSPATH' ) && file_exists( WC_ABSPATH . 'packages/action-scheduler/action-scheduler.php' ) ) {
				require_once WC_ABSPATH . 'packages/action-scheduler/action-scheduler.php';
			}
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'wc_blacklist_check_and_notify', [ 'order_id' => $order_id ] );
		} else {
			$this->check_order_and_notify( $order_id );
		}
	}

	public function check_order_and_notify($order_id) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		global $wpdb;
		$table_name         = $wpdb->prefix . 'wc_blacklist';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

		$order = wc_get_order($order_id);
		if ( ! $order ) {
			return;
		}

		// BILLING name
		$first_name   = sanitize_text_field($order->get_billing_first_name());
		$last_name    = sanitize_text_field($order->get_billing_last_name());
		$customer_name = trim($first_name . ' ' . $last_name);

		// SHIPPING name
		$shipping_first_name = sanitize_text_field($order->get_shipping_first_name());
		$shipping_last_name  = sanitize_text_field($order->get_shipping_last_name());
		$shipping_full_name  = trim($shipping_first_name . ' ' . $shipping_last_name);

		$phone       = sanitize_text_field($order->get_billing_phone());
		$shipping_phone       = sanitize_text_field($order->get_shipping_phone());
		$email       = sanitize_email($order->get_billing_email());
		$user_ip     = sanitize_text_field($order->get_customer_ip_address());
		$order_edit_url = admin_url('post.php?post=' . absint($order_id) . '&action=edit');

		$address_1 = sanitize_text_field($order->get_billing_address_1());
		$address_2 = sanitize_text_field($order->get_billing_address_2());
		$city      = sanitize_text_field($order->get_billing_city());
		$state     = sanitize_text_field($order->get_billing_state());
		$postcode  = sanitize_text_field($order->get_billing_postcode());
		$country   = sanitize_text_field($order->get_billing_country());
		$address_parts     = array_filter([$address_1, $address_2, $city, $state, $postcode, $country]);
		$customer_address  = implode(', ', $address_parts);

		$shipping_address_1 = sanitize_text_field($order->get_shipping_address_1());
		$shipping_address_2 = sanitize_text_field($order->get_shipping_address_2());
		$shipping_city      = sanitize_text_field($order->get_shipping_city());
		$shipping_state     = sanitize_text_field($order->get_shipping_state());
		$shipping_postcode  = sanitize_text_field($order->get_shipping_postcode());
		$shipping_country   = sanitize_text_field($order->get_shipping_country());
		$shipping_address_parts = array_filter([$shipping_address_1, $shipping_address_2, $shipping_city, $shipping_state, $shipping_postcode, $shipping_country]);
		$shipping_address       = implode(', ', $shipping_address_parts);

		$send_email = false;
		$reasons    = [];
		$view_data  = [
			'ip_address' => $user_ip,
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'shipping_first_name' => $shipping_first_name,
			'shipping_last_name'  => $shipping_last_name,
			'phone'      => $phone,
		];
		if ( ! empty( $shipping_phone ) ) {
			$view_data['shipping_phone'] = $shipping_phone;
		}
		$view_data += [
			'email'     => $email,
			'billing'   => $customer_address,
			'shipping'  => $shipping_address,
		];

		// Phone
		$phones_to_check = [];
		if ( ! empty( $phone ) ) {
			$phones_to_check['billing'] = $phone;
		}
		if ( ! empty( $shipping_phone ) && $shipping_phone !== $phone ) {
			$phones_to_check['shipping'] = $shipping_phone;
		}

		$phones_matched = []; // keep only matched phones for email + clearing

		foreach ( $phones_to_check as $label => $p ) {
			$phone_blacklisted = ! empty( $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE TRIM(LEADING '0' FROM phone_number) = %s AND is_blocked = 0 LIMIT 1",
				$p
			) ) );

			if ( $phone_blacklisted ) {
				$send_email = true;
				$phones_matched[] = $p;
				$reasons[] = ( $label === 'shipping' ? 'suspected_shipping_phone_attempt: ' : 'suspected_phone_attempt: ' ) . $p;

				$sum_block_phone = get_option('wc_blacklist_sum_block_phone', 0);
				update_option('wc_blacklist_sum_block_phone', $sum_block_phone + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);
			}
		}

		// Clear non-matched values so they won’t be emailed.
		if ( ! in_array( $phone, $phones_matched, true ) ) {
			$phone = '';
		}
		if ( ! in_array( $shipping_phone, $phones_matched, true ) ) {
			$shipping_phone = '';
		}

		// Email
		if (!empty($email) && is_email($email)) {
			if ($premium_active) {
				$normalized_email = yobmp_normalize_email( $email );

				$email_blacklisted = !empty($wpdb->get_var( $wpdb->prepare(
					"SELECT 1 FROM {$table_name}
					WHERE is_blocked = 0
					AND ( email_address = %s OR normalized_email = %s )
					LIMIT 1",
					$email,
					$normalized_email
				)));				
			} else {
				$email_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
					"SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 0 LIMIT 1",
					$email
				)));
			}
			
			if ($email_blacklisted) {
				$send_email = true;
				$reasons[]  = 'suspected_email_attempt: ' . $email;

				$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
				update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);
			} else {
				$email = '';
			}
		} else {
			$email = '';
		}

		// BILLING name
		if (!empty($customer_name) && $premium_active && get_option('wc_blacklist_customer_name_blocking_enabled') === '1') {
			$customer_name_blacklisted = !empty($wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$table_name}
					WHERE LOWER(CONCAT(first_name, ' ', last_name)) = %s
					AND is_blocked = 0
					LIMIT 1",
					$customer_name
				)
			));
			if ($customer_name_blacklisted) {
				$send_email = true;
				$reasons[]  = 'suspected_billing_name_attempt: ' . $customer_name;

				$sum_block_name = get_option('wc_blacklist_sum_block_name', 0);
				update_option('wc_blacklist_sum_block_name', $sum_block_name + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);
			} else {
				$customer_name = '';
			}
		} else {
			$customer_name = '';
		}

		// SHIPPING name (new)
		if ( ! empty( $shipping_full_name ) && $premium_active && get_option('wc_blacklist_customer_name_blocking_enabled') === '1' ) {
			$shipping_name_blacklisted = !empty($wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$table_name}
					WHERE LOWER(CONCAT(first_name, ' ', last_name)) = %s
					AND is_blocked = 0
					LIMIT 1",
					$shipping_full_name
				)
			));
			if ( $shipping_name_blacklisted ) {
				$send_email = true;
				$reasons[]  = 'suspected_shipping_name_attempt: ' . $shipping_full_name;

				$sum_block_name = get_option('wc_blacklist_sum_block_name', 0);
				update_option('wc_blacklist_sum_block_name', $sum_block_name + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				// If billing name didn't match (and was cleared), use shipping name for email context.
				if ( $customer_name === '' ) {
					$customer_name = $shipping_full_name;
				}
			}
		}

		// IP
		if (!empty($user_ip) && get_option('wc_blacklist_ip_enabled') === '1') {
			$ip_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE ip_address = %s AND is_blocked = 0 LIMIT 1",
				$user_ip
			)));
			if ($ip_blacklisted) {
				$send_email = true;
				$reasons[]  = 'suspected_ip_attempt: ' . $user_ip;

				$sum_block_ip = get_option('wc_blacklist_sum_block_ip', 0);
				update_option('wc_blacklist_sum_block_ip', $sum_block_ip + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);
			} else {
				$user_ip = '';
			}
		} else {
			$user_ip = '';
		}

		// Billing address
		if (!empty($customer_address) && $premium_active && get_option('wc_blacklist_enable_customer_address_blocking') === '1') {
			$address_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE customer_address = %s AND is_blocked = 0 LIMIT 1",
				$customer_address
			)));
			if ($address_blacklisted) {
				$send_email = true;
				$reasons[]  = 'suspected_billing_address_attempt: ' . $customer_address;

				$sum_block_address = get_option('wc_blacklist_sum_block_address', 0);
				update_option('wc_blacklist_sum_block_address', $sum_block_address + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);
			} else {
				$customer_address = '';
			}
		} else {
			$customer_address = '';
		}

		// Shipping address
		if (!empty($shipping_address) && $premium_active && get_option('wc_blacklist_enable_customer_address_blocking') === '1' && get_option('wc_blacklist_enable_shipping_address_blocking') === '1') {
			$shipping_address_blacklisted = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE customer_address = %s AND is_blocked = 0 LIMIT 1",
				$shipping_address
			)));
			if ($shipping_address_blacklisted) {
				$send_email = true;
				$reasons[]  = 'suspected_shipping_address_attempt: ' . $shipping_address;

				$sum_block_address = get_option('wc_blacklist_sum_block_address', 0);
				update_option('wc_blacklist_sum_block_address', $sum_block_address + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);
			} else {
				$shipping_address = '';
			}
		} else {
			$shipping_address = '';
		}

		// Insert one detection log row (premium)
		if ( ! empty( $reasons ) && $premium_active ) {
			$details   = implode( ', ', $reasons );
			$view_json = wp_json_encode( $view_data );

			$wpdb->insert(
				$table_detection_log,
				[
					'timestamp' => current_time( 'mysql' ),
					'type'      => 'bot',
					'source'    => 'woo_order_' . $order_id,
					'action'    => 'suspect',
					'details'   => $details,
					'view'      => $view_json,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		}

		if ($send_email) {
			// Build a single phone string containing whichever were flagged.
			$phones_to_email = array_filter( array_unique( [ $phone, $shipping_phone ] ) );
			$phone_for_email = implode( ', ', $phones_to_email );

			$email_sender = new WC_Blacklist_Manager_Email();
			$email_sender->send_email_order_suspect(
				$order_id,
				$customer_name, 
				$phone_for_email,
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
