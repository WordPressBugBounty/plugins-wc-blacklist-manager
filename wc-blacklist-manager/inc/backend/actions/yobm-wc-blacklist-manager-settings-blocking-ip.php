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
		add_filter('preprocess_comment', [$this, 'prevent_comment'], 10, 1);
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
		if ( ! get_option('wc_blacklist_ip_enabled', 0) || get_option('wc_blacklist_ip_action', 'none') !== 'prevent' ) {
			return;
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
	
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$user_ip = $this->get_the_user_ip();
	
		if ( empty( $user_ip ) ) {
			return;
		}
	
		$cache_key = 'banned_ip_' . md5( $user_ip );
		$is_banned = wp_cache_get( $cache_key, 'wc_blacklist' );
	
		if ( false === $is_banned ) {
			$is_banned = ! empty( $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM `{$table_name}` WHERE ip_address = %s AND is_blocked = 1 LIMIT 1",
				$user_ip
			) ) );
			wp_cache_set( $cache_key, $is_banned, 'wc_blacklist', HOUR_IN_SECONDS );
		}
	
		// If the IP is banned, use the IP; otherwise, set it to an empty string.
		$ip_value = ( $is_banned > 0 ) ? $user_ip : '';
	
		if ( $is_banned > 0 ) {
			$this->add_checkout_notice();

			$sum_block_ip = get_option('wc_blacklist_sum_block_ip', 0);
			update_option('wc_blacklist_sum_block_ip', $sum_block_ip + 1);
			$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
			update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

			if ($premium_active) {
				$reason_ip = 'blocked_ip_attempt: ' . $ip_value;
				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block('', '', '', $reason_ip);
			}
		}
	
		WC_Blacklist_Manager_Email::send_email_order_block('', '', $ip_value);
	}	

	public function prevent_blocked_ip_registration($errors, $sanitized_user_login, $user_email) {
		return $this->handle_blocked_ip_registration($errors);
	}

	public function prevent_blocked_ip_registration_woocommerce($errors, $username, $email) {
		return $this->handle_blocked_ip_registration($errors);
	}

	private function handle_blocked_ip_registration($errors) {
		if (get_option('wc_blacklist_ip_enabled', 0) && get_option('wc_blacklist_block_ip_registration', 0)) {
			$settings_instance = new WC_Blacklist_Manager_Settings();
			$premium_active = $settings_instance->is_premium_active();

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
				$ip_banned = !empty($wpdb->get_var($wpdb->prepare(
					"SELECT 1 FROM `{$table_name}` WHERE ip_address = %s AND is_blocked = 1 LIMIT 1",
					$user_ip
				)));
				wp_cache_set($cache_key, $ip_banned, 'wc_blacklist', HOUR_IN_SECONDS);
			}

			$ip_suspect = ! empty( $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM `{$table_name}` WHERE ip_address = %s AND is_blocked = 0 LIMIT 1",
				$user_ip
			) ) );

			$ip_value = $ip_banned || $ip_suspect ? $user_ip : '';

			if ($ip_banned) {
				wc_blacklist_add_registration_notice($errors);

				$sum_block_ip = get_option('wc_blacklist_sum_block_ip', 0);
				update_option('wc_blacklist_sum_block_ip', $sum_block_ip + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				WC_Blacklist_Manager_Email::send_email_registration_block('', '', $ip_value);

				if ($premium_active) {
					$reason_ip = 'blocked_ip_attempt: ' . $ip_value;
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block('', '', '', $reason_ip);
				}
			} elseif ( $ip_suspect ) {
				$sum_block_ip = get_option('wc_blacklist_sum_block_ip', 0);
				update_option('wc_blacklist_sum_block_ip', $sum_block_ip + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				WC_Blacklist_Manager_Email::send_email_registration_suspect('', '', $ip_value);

				if ($premium_active) {
					$reason_ip = 'suspected_ip_attempt: ' . $ip_value;
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_suspect('', '', '', $reason_ip);
				}
			}
		}

		return $errors;
	}

	public function prevent_comment( $commentdata ) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		
		if ( !$premium_active || get_option( 'wc_blacklist_ip_enabled', 0 ) !== '1' || get_option( 'wc_blacklist_block_ip_comment', 0 ) !== '1' ) {
			return $commentdata;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		$user_ip = $this->get_the_user_ip();

		if ( ! empty( $user_ip ) ) {
			$is_blocked = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE ip_address = %s
						AND is_blocked     = 1
					LIMIT 1",
					$user_ip
				)
			);

			$is_suspected = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE ip_address = %s
						AND is_blocked     = 0
					LIMIT 1",
					$user_ip
				)
			);
			
			if ( $is_blocked ) {
				$sum_block_ip = get_option('wc_blacklist_sum_block_ip', 0);
				update_option('wc_blacklist_sum_block_ip', $sum_block_ip + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				WC_Blacklist_Manager_Email::send_email_comment_block( '', $user_ip );

				$reason_ip = 'blocked_ip_attempt: ' . $user_ip;
				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_block('', '', '', $reason_ip);

				$notice_template = get_option(
					'wc_blacklist_comment_notice',
					__('Sorry! You are no longer allowed to submit a comment on our site. If you think it is a mistake, please contact support.', 'wc-blacklist-manager')
				);

				$notice = sprintf( wp_kses_post( $notice_template ) );

				wp_die(
					$notice,
					__( 'Comment Blocked', 'wc-blacklist-manager' ),
					[ 'response' => 403 ]
				);
			} elseif ( $is_suspected ) {
				$sum_block_ip = get_option('wc_blacklist_sum_block_ip', 0);
				update_option('wc_blacklist_sum_block_ip', $sum_block_ip + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				WC_Blacklist_Manager_Email::send_email_comment_suspect( '', $user_ip );

				$reason_ip = 'suspected_ip_attempt: ' . $user_ip;
				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_suspect('', '', '', $reason_ip);
			}
		}

		return $commentdata;
	}
}

new WC_Blacklist_Manager_IP_Blacklisted();
