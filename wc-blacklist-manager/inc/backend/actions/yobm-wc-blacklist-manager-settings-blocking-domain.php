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
		add_filter('preprocess_comment', [$this, 'prevent_comment'], 10, 1);
	}

	public function check_customer_email_domain_against_blacklist() {
		$domain_blocking_enabled = get_option('wc_blacklist_domain_enabled', 0);
		if (! class_exists( 'WooCommerce' ) || !$domain_blocking_enabled) {
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
	
		$tld_hit      = '';
		if ($premium_active) {
			// Check TLD against TLD blacklisted
			$blocked_tlds = get_option( 'wc_blacklist_domain_top_level', [] );
			if ( is_string( $blocked_tlds ) ) {
				$blocked_tlds = array_filter( array_map( 'trim', explode( ',', $blocked_tlds ) ) );
			}
			// Normalize TLD list
			$normalized_tlds = [];
			foreach ( (array) $blocked_tlds as $tld ) {
				$t = strtolower( trim( $tld ) );
				if ( $t !== '' ) {
					if ( $t[0] !== '.' ) { $t = '.' . $t; }
					// keep only .[a-z0-9-]
					if ( preg_match( '/^\.[a-z0-9][a-z0-9\-\.]*$/', $t ) ) {
						$normalized_tlds[$t] = true;
					}
				}
			}
			$blocked_tlds = array_keys( $normalized_tlds );

			// Build suffix candidates from the domain: .uk, .co.uk, .example.co.uk (up to 3 labels, practical)
			if ( ! empty( $blocked_tlds ) && strpos( $email_domain, '.' ) !== false ) {
				$labels = array_reverse( explode( '.', $email_domain ) );
				$candidates = [];
				// 1 label
				if ( isset( $labels[0] ) ) { $candidates[] = '.' . $labels[0]; }
				// 2 labels
				if ( isset( $labels[1] ) ) { $candidates[] = '.' . $labels[1] . '.' . $labels[0]; }
				// 3 labels
				if ( isset( $labels[2] ) ) { $candidates[] = '.' . $labels[2] . '.' . $labels[1] . '.' . $labels[0]; }

				foreach ( $candidates as $cand ) {
					if ( in_array( $cand, $blocked_tlds, true ) ) {
						$tld_hit = $cand;
						break;
					}
				}
			}
		}
		
		if ($is_domain_banned > 0 || $tld_hit !== '') {
			$this->add_checkout_notice();

			$sum_block_domain = get_option('wc_blacklist_sum_block_domain', 0);
			update_option('wc_blacklist_sum_block_domain', $sum_block_domain + 1);
			$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
			update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

			if ($premium_active) {
				if ( $domain_value !== '' ) {
					$reasons = 'blocked_domain_attempt: ' . $domain_value;
				}
				if ( $tld_hit !== '' ) {
					$reasons = 'blocked_tld_attempt: ' . $tld_hit;
				}
				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block('', '', '', '', '', '', '', $reasons);
			}
		}

		WC_Blacklist_Manager_Email::send_email_order_block('', '', '', $domain_value);
	}	

	public function prevent_domain_registration($errors, $sanitized_user_login, $user_email) {
		return $this->handle_domain_registration($errors, $user_email);
	}

	public function prevent_domain_registration_woocommerce($errors, $username, $email) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
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

			$tld_hit      = '';
			if ($premium_active) {
				// Check TLD against TLD blacklisted
				$blocked_tlds = get_option( 'wc_blacklist_domain_top_level', [] );
				if ( is_string( $blocked_tlds ) ) {
					$blocked_tlds = array_filter( array_map( 'trim', explode( ',', $blocked_tlds ) ) );
				}
				// Normalize TLD list
				$normalized_tlds = [];
				foreach ( (array) $blocked_tlds as $tld ) {
					$t = strtolower( trim( $tld ) );
					if ( $t !== '' ) {
						if ( $t[0] !== '.' ) { $t = '.' . $t; }
						// keep only .[a-z0-9-]
						if ( preg_match( '/^\.[a-z0-9][a-z0-9\-\.]*$/', $t ) ) {
							$normalized_tlds[$t] = true;
						}
					}
				}
				$blocked_tlds = array_keys( $normalized_tlds );

				// Build suffix candidates from the domain: .uk, .co.uk, .example.co.uk (up to 3 labels, practical)
				if ( ! empty( $blocked_tlds ) && strpos( $email_domain, '.' ) !== false ) {
					$labels = array_reverse( explode( '.', $email_domain ) );
					$candidates = [];
					// 1 label
					if ( isset( $labels[0] ) ) { $candidates[] = '.' . $labels[0]; }
					// 2 labels
					if ( isset( $labels[1] ) ) { $candidates[] = '.' . $labels[1] . '.' . $labels[0]; }
					// 3 labels
					if ( isset( $labels[2] ) ) { $candidates[] = '.' . $labels[2] . '.' . $labels[1] . '.' . $labels[0]; }

					foreach ( $candidates as $cand ) {
						if ( in_array( $cand, $blocked_tlds, true ) ) {
							$tld_hit = $cand;
							break;
						}
					}
				}
			}

			if ($is_domain_banned > 0 || $tld_hit !== '') {
				wc_blacklist_add_registration_notice($errors);

				$sum_block_domain = get_option('wc_blacklist_sum_block_domain', 0);
				update_option('wc_blacklist_sum_block_domain', $sum_block_domain + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					if ( $domain_value !== '' ) {
						$reasons = 'blocked_domain_attempt: ' . $domain_value;
					}
					if ( $tld_hit !== '' ) {
						$reasons = 'blocked_tld_attempt: ' . $tld_hit;
					}
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block('', '', '', '', '', $reasons);
				}
			}

			WC_Blacklist_Manager_Email::send_email_registration_block('', '', '', $domain_value);
		}

		return $errors;
	}

	public function prevent_comment( $commentdata ) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		if ( ! $premium_active
			|| get_option( 'wc_blacklist_domain_enabled', 0 ) !== '1'
			|| get_option( 'wc_blacklist_domain_comment', 0 ) !== '1'
		) {
			return $commentdata;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		$author_email = isset( $commentdata['comment_author_email'] )
			? trim( $commentdata['comment_author_email'] )
			: '';

		// If no email, let other validations handle it.
		if ( empty( $author_email ) || ! is_email( $author_email ) ) {
			return $commentdata;
		}

		// Extract domain (e.g., example.co.uk)
		$email_domain = substr( strrchr( $author_email, '@' ), 1 );
		if ( empty( $email_domain ) ) {
			return $commentdata;
		}
		$email_domain = strtolower( trim( $email_domain ) );

		// 1) Check exact domain in DB (blocked entries only)
		$is_blocked_domain = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1
				FROM {$table_name}
				WHERE domain = %s
				AND is_blocked = 1
				LIMIT 1",
				$email_domain
			)
		);

		// 2) Check Top-level Domains option
		$blocked_tlds = get_option( 'wc_blacklist_domain_top_level', [] );
		if ( is_string( $blocked_tlds ) ) {
			$blocked_tlds = array_filter( array_map( 'trim', explode( ',', $blocked_tlds ) ) );
		}
		// Normalize TLDs to ".tld" / ".co.uk"…
		$normalized = [];
		foreach ( (array) $blocked_tlds as $tld ) {
			$t = strtolower( trim( $tld ) );
			if ( $t !== '' ) {
				if ( $t[0] !== '.' ) { $t = '.' . $t; }
				// allow dot + alnum + hyphen + dots (for multi-label like .co.uk)
				if ( preg_match( '/^\.[a-z0-9][a-z0-9\-\.]*$/', $t ) ) {
					$normalized[ $t ] = true;
				}
			}
		}
		$blocked_tlds = array_keys( $normalized );

		// Build suffix candidates from the email domain: .uk, .co.uk, .example.co.uk
		$tld_hit = '';
		if ( ! empty( $blocked_tlds ) && strpos( $email_domain, '.' ) !== false ) {
			$labels = array_reverse( explode( '.', $email_domain ) ); // ['uk','co','example', ...]
			$candidates = [];
			// 1 label
			if ( isset( $labels[0] ) ) { $candidates[] = '.' . $labels[0]; }
			// 2 labels
			if ( isset( $labels[1] ) ) { $candidates[] = '.' . $labels[1] . '.' . $labels[0]; }
			// 3 labels (practical upper bound)
			if ( isset( $labels[2] ) ) { $candidates[] = '.' . $labels[2] . '.' . $labels[1] . '.' . $labels[0]; }

			foreach ( $candidates as $cand ) {
				if ( in_array( $cand, $blocked_tlds, true ) ) {
					$tld_hit = $cand;
					break;
				}
			}
		}

		// If either exact domain is blocked OR TLD matches, block the comment
		if ( $is_blocked_domain || $tld_hit !== '' ) {
			// Counters
			$sum_block_domain = get_option( 'wc_blacklist_sum_block_domain', 0 );
			update_option( 'wc_blacklist_sum_block_domain', $sum_block_domain + 1 );
			$sum_block_total = get_option( 'wc_blacklist_sum_block_total', 0 );
			update_option( 'wc_blacklist_sum_block_total', $sum_block_total + 1 );

			// Email notify (reuse your signature; send domain or tld hit)
			WC_Blacklist_Manager_Email::send_email_comment_block( '', '', $is_blocked_domain ? $email_domain : $tld_hit );

			// Activity log
			if ( $is_blocked_domain ) { $reasons = 'blocked_domain_attempt: ' . $email_domain; }
			if ( $tld_hit !== '' )   { $reasons = 'blocked_tld_attempt: ' . $tld_hit; }
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_block( '', '', '', '', '', implode( ', ', $reasons ) );

			// User-facing message
			$notice_template = get_option(
				'wc_blacklist_comment_notice',
				__( 'Sorry! You are no longer allowed to submit a comment on our site. If you think it is a mistake, please contact support.', 'wc-blacklist-manager' )
			);
			$notice = sprintf( wp_kses_post( $notice_template ) );

			wp_die(
				$notice,
				__( 'Comment Blocked', 'wc-blacklist-manager' ),
				[ 'response' => 403 ]
			);
		}

		return $commentdata;
	}
}

new WC_Blacklist_Manager_Domain_Blocking_Actions();
