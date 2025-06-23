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

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
	
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
	
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
				"SELECT 1 FROM `{$table_name}` WHERE domain = %s LIMIT 1",
				$email_domain
			));
			wp_cache_set($cache_key, $is_domain_banned, 'wc_blacklist', HOUR_IN_SECONDS);
		}

		$domain_value = ( $is_domain_banned > 0 ) ? $email_domain : '';
	
		if ($is_domain_banned > 0) {
			$this->add_checkout_notice();

			$sum_block_domain = get_option('wc_blacklist_sum_block_domain', 0);
			update_option('wc_blacklist_sum_block_domain', $sum_block_domain + 1);
			$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
			update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

			if ($premium_active) {
				$reason_domain = 'blocked_domain_attempt: ' . $domain_value;
				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block('', '', '', '', '', '', '', $reason_domain);
			}
		}

		WC_Blacklist_Manager_Email::send_email_order_block('', '', '', $domain_value);
	}	

	public function prevent_domain_registration($errors, $sanitized_user_login, $user_email) {
		return $this->handle_domain_registration($errors, $user_email);
	}

	public function prevent_domain_registration_woocommerce($errors, $username, $email) {
		return $this->handle_domain_registration($errors, $email);
	}

	private function handle_domain_registration($errors, $email) {
		if (get_option('wc_blacklist_domain_enabled', 0) && get_option('wc_blacklist_domain_registration', 0)) {

			$settings_instance = new WC_Blacklist_Manager_Settings();
			$premium_active = $settings_instance->is_premium_active();
			
			global $wpdb;
			$table_name = $wpdb->prefix . 'wc_blacklist';
			$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

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
					"SELECT 1 FROM `{$table_name}` WHERE domain = %s LIMIT 1",
					$email_domain
				));
				wp_cache_set($cache_key, $is_domain_banned, 'wc_blacklist', HOUR_IN_SECONDS);
			}

			$domain_value = ( $is_domain_banned > 0 ) ? $email_domain : '';

			if ($is_domain_banned > 0) {
				wc_blacklist_add_registration_notice($errors);

				$sum_block_domain = get_option('wc_blacklist_sum_block_domain', 0);
				update_option('wc_blacklist_sum_block_domain', $sum_block_domain + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$reason_domain = 'blocked_domain_attempt: ' . $domain_value;
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block('', '', '', '', '', $reason_domain);
				}
			}

			WC_Blacklist_Manager_Email::send_email_registration_block('', '', '', $domain_value);
		}

		return $errors;
	}
}

new WC_Blacklist_Manager_Domain_Blocking_Actions();
