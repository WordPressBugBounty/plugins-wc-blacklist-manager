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
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		
		// Get the selected country code if it exists
		$selected_country_code = isset($_POST['selected_country_code']) ? sanitize_text_field(wp_unslash($_POST['selected_country_code'])) : '';

		// Get the billing phone and prepend the country code if applicable
		$billing_phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
		if (!empty($selected_country_code) && !empty($billing_phone)) {
			$billing_phone = $selected_country_code . $billing_phone;
		}

		$billing_email = isset($_POST['billing_email']) ? sanitize_email(wp_unslash($_POST['billing_email'])) : '';

		$blacklist_action = get_option('wc_blacklist_action', 'none');
		$checkout_notice = get_option('wc_blacklist_checkout_notice', __('Sorry! You are no longer allowed to shop with us. If you think it is a mistake, please contact support.', 'wc-blacklist-manager'));

		$is_blocked = false;
		if (!empty($billing_phone)) {
			$is_blocked |= (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE phone_number = %s AND is_blocked = 1",
				$billing_phone
			)) > 0;
		}
		if (!empty($billing_email) && is_email($billing_email)) {
			$is_blocked |= (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE email_address = %s AND is_blocked = 1",
				$billing_email
			)) > 0;
		}

		if ($is_blocked && $blacklist_action === 'prevent') {
			wc_add_notice($checkout_notice, 'error');
			$this->send_admin_email('order', $billing_phone, $billing_email, null);
		}
	}

	public function prevent_blocked_email_registration($errors, $sanitized_user_login, $user_email) {
		return $this->handle_registration_block($errors, $user_email);
	}

	public function prevent_blocked_email_registration_woocommerce($errors, $username, $email) {
		return $this->handle_registration_block($errors, $email);
	}

	private function handle_registration_block($errors, $email) {
		global $wpdb;
		if (get_option('wc_blacklist_block_user_registration', 0)) {
			$table_name = $wpdb->prefix . 'wc_blacklist';
			$email_blocked = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE email_address = %s AND is_blocked = 1",
				$email
			));

			if ($email_blocked) {
				$registration_notice = get_option('wc_blacklist_registration_notice', __('You have been blocked from registering. Think it is a mistake? Contact the administrator.', 'wc-blacklist-manager'));
				$errors->add('email_blocked', $registration_notice);
				$this->send_admin_email('registration', '', $email, null);
			}
		}
		return $errors;
	}

	public function schedule_order_cancellation($order_id, $old_status, $new_status, $order) {
		if (!in_array($new_status, array('on-hold', 'processing', 'completed'))) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$blacklist_action = get_option('wc_blacklist_action', 'none');

		if ($blacklist_action !== 'cancel') {
			return;
		}

		$billing_phone = sanitize_text_field($order->get_billing_phone());
		if (!empty($selected_country_code) && !empty($billing_phone)) {
			$billing_phone = $selected_country_code . $billing_phone;
		}
		
		$billing_email = sanitize_email($order->get_billing_email());

		$is_blocked = false;
		if (!empty($billing_phone)) {
			$is_blocked |= (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE phone_number = %s AND is_blocked = 1",
				$billing_phone
			)) > 0;
		}
		if (!empty($billing_email)) {
			$is_blocked |= (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE email_address = %s AND is_blocked = 1",
				$billing_email
			)) > 0;
		}

		if ($is_blocked) {
			$order_delay = max(0, intval(get_option('wc_blacklist_order_delay', 0)));
			if ($order_delay > 0) {
				wp_schedule_single_event(time() + ($order_delay * 60), 'wc_blacklist_delayed_order_cancel', [$order_id]);
			} else {
				$order->update_status('cancelled', __('Order cancelled due to blacklist match.', 'wc-blacklist-manager'));
			}
			$this->send_admin_email('order', $billing_phone, $billing_email, $order_id);
		}
	}

	public function delayed_order_cancel($order_id) {
		$order = wc_get_order($order_id);
		if ($order && !$order->has_status('cancelled')) {
			$order->update_status('cancelled', __('Order cancelled due to blacklist match.', 'wc-blacklist-manager'));
		}
	}

	private function send_admin_email($type, $phone, $email, $order_id = null) {
		if (get_option('wc_blacklist_email_blocking_notification', 'no') !== 'yes') {
			return;
		}

		$admin_email = get_option('admin_email');
		$additional_emails = get_option('wc_blacklist_additional_emails', '');
		$subject = __('Blocked User Attempt Notification', 'wc-blacklist-manager');
		
		if ($type === 'order') {
			$template_path = plugin_dir_path(__FILE__) . '../emails/templates/yobm-wc-blacklist-manager-email-template-order-detection-alert.html';
		} elseif ($type === 'registration') {
			$template_path = plugin_dir_path(__FILE__) . '../emails/templates/yobm-wc-blacklist-manager-email-template-registration-detection-alert.html';
		}
		
		$template_content = file_get_contents($template_path);

		if ($type === 'order') {
			$message = __('A blocked user attempted to place an order.', 'wc-blacklist-manager');
			$suspicious_order_content = __('Prevention details:', 'wc-blacklist-manager') . "<br>";
			if (!empty($phone)) {
				$suspicious_order_content .= __('Phone:', 'wc-blacklist-manager') . " $phone<br>";
			}
			if (!empty($email)) {
				$suspicious_order_content .= __('Email:', 'wc-blacklist-manager') . " $email<br>";
			}

			$template_content = str_replace(
				['{{order_message}}', '{{order_detection_content}}'],
				[$message, $suspicious_order_content],
				$template_content
			);
		} elseif ($type === 'registration') {
			$message = __('A blocked user attempted to register an account.', 'wc-blacklist-manager');
			$suspicious_order_content = __('Prevention details:', 'wc-blacklist-manager') . "<br>";
			if (!empty($email)) {
				$suspicious_order_content .= __('Email:', 'wc-blacklist-manager') . " $email<br>";
			}

			$template_content = str_replace(
				['{{registration_message}}', '{{registration_detection_content}}'],
				[$message, $suspicious_order_content],
				$template_content
			);
		}

		$recipients = array_merge([$admin_email], explode(',', $additional_emails));
		$recipients = array_map('trim', $recipients);
		$recipients = array_filter($recipients);

		add_filter('wp_mail_content_type', function() { return 'text/html'; });
		wp_mail($recipients, $subject, $template_content);
		remove_filter('wp_mail_content_type', function() { return 'text/html'; });
	}
}

new WC_Blacklist_Manager_Blocklisted_Actions();
