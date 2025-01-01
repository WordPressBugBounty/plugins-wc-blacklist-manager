<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Domain_Blocking_Actions {
	use Blacklist_Notice_Trait;
	
	public function __construct() {
		add_action('woocommerce_checkout_process', [$this, 'check_customer_email_domain_against_blacklist']);
		add_filter('registration_errors', [$this, 'prevent_domain_registration'], 10, 3);
		add_filter('woocommerce_registration_errors', [$this, 'prevent_domain_registration_woocommerce'], 10, 3);
	}

	public function check_customer_email_domain_against_blacklist() {
		$domain_blocking_enabled = get_option('wc_blacklist_domain_enabled', 0);
		if (!$domain_blocking_enabled) {
			return;
		}
	
		$domain_blocking_action = get_option('wc_blacklist_domain_action', 'none');
		if ($domain_blocking_action !== 'prevent') {
			return;
		}
	
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
	
		$billing_email = isset($_POST['billing_email']) ? sanitize_email(wp_unslash($_POST['billing_email'])) : '';
	
		// Allow checkout to proceed if no billing email is provided.
		if (empty($billing_email)) {
			return;
		}
	
		// Validate email format.
		if (!is_email($billing_email)) {
			wc_add_notice(__('Invalid email address provided.', 'wc-blacklist-manager'), 'error');
			return;
		}
	
		$email_domain = substr(strrchr($billing_email, "@"), 1);
		if (empty($email_domain)) {
			wc_add_notice(__('Invalid email domain.', 'wc-blacklist-manager'), 'error');
			return;
		}
	
		$cache_key = 'banned_domain_' . md5($email_domain);
		$is_domain_banned = wp_cache_get($cache_key, 'wc_blacklist');
		if (false === $is_domain_banned) {
			$is_domain_banned = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table_name}` WHERE domain = %s",
				$email_domain
			));
			wp_cache_set($cache_key, $is_domain_banned, 'wc_blacklist', HOUR_IN_SECONDS);
		}
	
		if ($is_domain_banned > 0) {
			$this->add_checkout_notice();
			$this->send_admin_email('order', '', $billing_email, null);
		}
	}	

	public function prevent_domain_registration($errors, $sanitized_user_login, $user_email) {
		return $this->handle_domain_registration($errors, $user_email);
	}

	public function prevent_domain_registration_woocommerce($errors, $username, $email) {
		return $this->handle_domain_registration($errors, $email);
	}

	private function handle_domain_registration($errors, $email) {
		if (get_option('wc_blacklist_domain_enabled', 0) && get_option('wc_blacklist_domain_registration', 0)) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'wc_blacklist';

			if (false === strpos($email, '@')) {
				$errors->add('invalid_email', __('Invalid email address.', 'wc-blacklist-manager'));
				return $errors;
			}

			$email_domain = substr(strrchr($email, "@"), 1);
			if (empty($email_domain)) {
				$errors->add('invalid_email_domain', __('Invalid email domain provided.', 'wc-blacklist-manager'));
				return $errors;
			}

			$cache_key = 'banned_domain_' . md5($email_domain);
			$is_domain_banned = wp_cache_get($cache_key, 'wc_blacklist');
			if (false === $is_domain_banned) {
				$is_domain_banned = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table_name}` WHERE domain = %s",
					$email_domain
				));
				wp_cache_set($cache_key, $is_domain_banned, 'wc_blacklist', HOUR_IN_SECONDS);
			}

			if ($is_domain_banned > 0) {
				$registration_notice = get_option('wc_blacklist_registration_notice', __('You have been blocked from registering. Think it is a mistake? Contact the administrator.', 'wc-blacklist-manager'));
				$errors->add('domain_blocked', $registration_notice);
				$this->send_admin_email('registration', '', $email, null);
			}
		}

		return $errors;
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

new WC_Blacklist_Manager_Domain_Blocking_Actions();
