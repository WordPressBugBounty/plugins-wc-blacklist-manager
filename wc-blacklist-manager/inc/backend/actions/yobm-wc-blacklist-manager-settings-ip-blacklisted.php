<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_IP_Blacklisted {
	use Blacklist_Notice_Trait;
	
	public function __construct() {
		add_action('woocommerce_checkout_process', [$this, 'check_customer_ip_against_blacklist']);
		add_filter('registration_errors', [$this, 'prevent_blocked_ip_registration'], 10, 3);
		add_filter('woocommerce_registration_errors', [$this, 'prevent_blocked_ip_registration_woocommerce'], 10, 3);
	}

	private function get_the_user_ip() {
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			// Cloudflare connecting IP
			$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
		} elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			// Client IP
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// X-Forwarded-For header
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			// Remote address
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return sanitize_text_field($ip);
	}	

	public function check_customer_ip_against_blacklist() {
		if (!get_option('wc_blacklist_ip_enabled', 0) || get_option('wc_blacklist_ip_action', 'none') !== 'prevent') {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$user_ip = $this->get_the_user_ip();

		if (empty($user_ip)) {
			return;
		}

		$cache_key = 'banned_ip_' . md5($user_ip);
		$is_banned = wp_cache_get($cache_key, 'wc_blacklist');

		if (false === $is_banned) {
			$is_banned = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table_name}` WHERE ip_address = %s AND is_blocked = 1",
				$user_ip
			));
			wp_cache_set($cache_key, $is_banned, 'wc_blacklist', HOUR_IN_SECONDS);
		}

		if ($is_banned > 0) {
			$this->add_checkout_notice();
			$this->send_admin_email('order', '', '', $user_ip);
		}
	}

	public function prevent_blocked_ip_registration($errors, $sanitized_user_login, $user_email) {
		return $this->handle_blocked_ip_registration($errors);
	}

	public function prevent_blocked_ip_registration_woocommerce($errors, $username, $email) {
		return $this->handle_blocked_ip_registration($errors);
	}

	private function handle_blocked_ip_registration($errors) {
		if (get_option('wc_blacklist_ip_enabled', 0) && get_option('wc_blacklist_block_ip_registration', 0)) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'wc_blacklist';
			$user_ip = $this->get_the_user_ip();

			if (empty($user_ip)) {
				$errors->add('ip_error', __('Error retrieving IP address.', 'wc-blacklist-manager'));
				return $errors;
			}

			$cache_key = 'blocked_ip_registration_' . md5($user_ip);
			$ip_banned = wp_cache_get($cache_key, 'wc_blacklist');

			if (false === $ip_banned) {
				$ip_banned = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table_name}` WHERE ip_address = %s AND is_blocked = 1",
					$user_ip
				));
				wp_cache_set($cache_key, $ip_banned, 'wc_blacklist', HOUR_IN_SECONDS);
			}

			if ($ip_banned > 0) {
				$registration_notice = get_option('wc_blacklist_registration_notice', __('You have been blocked from registering. Think it is a mistake? Contact the administrator.', 'wc-blacklist-manager'));
				$errors->add('ip_blocked', $registration_notice);
				$this->send_admin_email('registration', '', '', $user_ip);
			}
		}

		return $errors;
	}

	private function send_admin_email($type, $phone, $email, $ip) {
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
			if (!empty($ip)) {
				$suspicious_order_content .= __('IP:', 'wc-blacklist-manager') . " $ip<br>";
			}

			$template_content = str_replace(
				['{{order_message}}', '{{order_detection_content}}'],
				[$message, $suspicious_order_content],
				$template_content
			);
		} elseif ($type === 'registration') {
			$message = __('A blocked user attempted to register an account.', 'wc-blacklist-manager');
			$suspicious_order_content = __('Prevention details:', 'wc-blacklist-manager') . "<br>";
			if (!empty($ip)) {
				$suspicious_order_content .= __('IP:', 'wc-blacklist-manager') . " $ip<br>";
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

new WC_Blacklist_Manager_IP_Blacklisted();
