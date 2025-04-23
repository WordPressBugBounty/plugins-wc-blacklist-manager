<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Blocklisted_Actions {
	public function __construct() {
		add_action('woocommerce_checkout_process', [$this, 'prevent_order']);
		add_filter('registration_errors', [$this, 'prevent_blocked_email_registration'], 10, 3);
		add_filter('woocommerce_registration_errors', [$this, 'prevent_blocked_email_registration_woocommerce'], 10, 3);
		add_action('woocommerce_order_status_changed', [$this, 'schedule_order_cancellation'], 10, 4);
		add_action('wc_blacklist_delayed_order_cancel', [$this, 'delayed_order_cancel']);
	}

	public function prevent_order() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
		
		// Get the selected country code if it exists.
		$billing_dial_code = isset($_POST['billing_dial_code']) ? sanitize_text_field(wp_unslash($_POST['billing_dial_code'])) : '';
		
		// Get the billing phone, sanitize it, remove non-digits, and trim leading zeros.
		$billing_phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
		$billing_phone = preg_replace('/[^0-9]/', '', $billing_phone);
		$billing_phone = ltrim($billing_phone, '0');
		
		// Prepend the country code if both dial code and phone exist.
		if ( ! empty( $billing_dial_code ) && ! empty( $billing_phone ) ) {
			$billing_phone = $billing_dial_code . $billing_phone;
		}
		
		$billing_email = isset($_POST['billing_email']) ? sanitize_email(wp_unslash($_POST['billing_email'])) : '';
		
		$blacklist_action = get_option('wc_blacklist_action', 'none');
		$checkout_notice  = get_option(
			'wc_blacklist_checkout_notice',
			__('Sorry! You are no longer allowed to shop with us. If you think it is a mistake, please contact support.', 'wc-blacklist-manager')
		);
	
		// Check if the phone is blocked.
		$is_phone_blocked = false;
		if ( ! empty( $billing_phone ) ) {
			$result_phone = $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE TRIM(LEADING '0' FROM phone_number) = %s AND is_blocked = 1 LIMIT 1",
				$billing_phone
			) );
			$is_phone_blocked = ! empty( $result_phone );
			
			// If the phone is not blocked, clear the variable.
			if ( $is_phone_blocked ) {
				$sum_block_phone = get_option('wc_blacklist_sum_block_phone', 0);
				update_option('wc_blacklist_sum_block_phone', $sum_block_phone + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$timestamp = current_time('mysql');
					$type      = 'human';
					$source    = 'woo_checkout';
					$action    = 'block';
					$details   = 'blocked_phone_attempt: ' . $billing_phone;
					
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
				$billing_phone = '';
			}
		}
	
		// Check if the email is blocked.
		$is_email_blocked = false;
		if ( ! empty( $billing_email ) && is_email( $billing_email ) ) {
			$result_email = $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 1 LIMIT 1",
				$billing_email
			) );
			$is_email_blocked = ! empty( $result_email );
			
			// If the email is not blocked, clear the variable.
			if ( $is_email_blocked ) {
				$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
				update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$timestamp = current_time('mysql');
					$type      = 'human';
					$source    = 'woo_checkout';
					$action    = 'block';
					$details   = 'blocked_email_attempt: ' . $billing_email;
					
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
				$billing_email = '';
			}
		}
	
		// If either the phone or email is blocked and the blacklist action is set to "prevent"
		if ( ( $is_phone_blocked || $is_email_blocked ) && $blacklist_action === 'prevent' ) {
			wc_add_notice( $checkout_notice, 'error' );

			// Trigger the email with only the blocked values
			WC_Blacklist_Manager_Email_Order::send_email_order_block( $billing_phone, $billing_email );
		}
	}
	
	public function prevent_blocked_email_registration($errors, $sanitized_user_login, $user_email) {
		return $this->handle_registration_block($errors, $user_email);
	}

	public function prevent_blocked_email_registration_woocommerce($errors, $username, $email) {
		return $this->handle_registration_block($errors, $email);
	}

	private function handle_registration_block($errors, $email) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		global $wpdb;
		if (get_option('wc_blacklist_block_user_registration', 0)) {
			$table_name = $wpdb->prefix . 'wc_blacklist';
			$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
			$email_blocked = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 1 LIMIT 1",
				$email
			)));

			if ($email_blocked) {
				$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
				update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$timestamp = current_time('mysql');
					$type      = 'human';
					$source    = 'register';
					$action    = 'block';
					$details   = 'blocked_email_attempt: ' . $email;
					
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
				
				wc_blacklist_add_registration_notice($errors);
			} else {
				$email = '';
			}

			WC_Blacklist_Manager_Email_Order::send_email_registration_block( '', $email );
		}
		return $errors;
	}

	public function schedule_order_cancellation($order_id, $old_status, $new_status, $order) {
		if (!in_array($new_status, array('on-hold', 'processing', 'completed'))) {
			return;
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
		$blacklist_action = get_option('wc_blacklist_action', 'none');

		if ($blacklist_action !== 'cancel') {
			return;
		}

		$billing_phone = sanitize_text_field($order->get_billing_phone());
		$billing_phone = ltrim($billing_phone, '0');
		
		$billing_email = sanitize_email($order->get_billing_email());

		$is_blocked = false;
		if (!empty($billing_phone)) {
			$result = $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE TRIM(LEADING '0' FROM phone_number) = %s AND is_blocked = 1 LIMIT 1",
				$billing_phone
			) );
			$is_blocked = !empty($result);

			if ($is_blocked) {
				$sum_block_phone = get_option('wc_blacklist_sum_block_phone', 0);
				update_option('wc_blacklist_sum_block_phone', $sum_block_phone + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$timestamp = current_time('mysql');
					$type      = 'human';
					$source    = 'woo_order_' . $order_id;
					$action    = 'cancel';
					$details   = 'blocked_phone_attempt: ' . $billing_phone;
					
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
			}
		}
		
		if (!empty($billing_email) && is_email($billing_email)) {
			$result = $wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 1 LIMIT 1",
				$billing_email
			));
			$is_blocked |= !empty($result);

			if ($is_blocked) {
				$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
				update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$timestamp = current_time('mysql');
					$type      = 'human';
					$source    = 'woo_order_' . $order_id;
					$action    = 'cancel';
					$details   = 'blocked_email_attempt: ' . $billing_email;
					
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
			}
		}

		if ($is_blocked) {
			$order_delay = max(0, intval(get_option('wc_blacklist_order_delay', 0)));
			if ($order_delay > 0) {
				wp_schedule_single_event(time() + ($order_delay * 60), 'wc_blacklist_delayed_order_cancel', [$order_id]);
			} else {
				$order->update_status('cancelled', __('Order cancelled due to blacklist match.', 'wc-blacklist-manager'));
			}
		}
	}

	public function delayed_order_cancel($order_id) {
		$order = wc_get_order($order_id);
		if ($order && !$order->has_status('cancelled')) {
			$order->update_status('cancelled', __('Order cancelled due to blocklist match.', 'wc-blacklist-manager'));
		}
	}
}

new WC_Blacklist_Manager_Blocklisted_Actions();
