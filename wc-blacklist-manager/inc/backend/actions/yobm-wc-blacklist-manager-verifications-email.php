<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Verifications_Verify_Email {

	private $whitelist_table;
	private $blacklist_table;
	private $verification_code_meta_key = '_email_verification_code';
	private $verification_time_meta_key = '_email_verification_time';
	private $resend_cooldown_seconds = 60;
	private $verification_expiration_seconds = 300; 

	public function __construct() {
		global $wpdb;
		$this->whitelist_table = $wpdb->prefix . 'wc_whitelist';
		$this->blacklist_table = $wpdb->prefix . 'wc_blacklist';

		add_action('wp_enqueue_scripts', [$this, 'enqueue_verification_scripts']);
		add_action('woocommerce_checkout_process', [$this, 'email_verification'], 20);
		add_action('wp_ajax_verify_email_code', [$this, 'verify_email_code']);
		add_action('wp_ajax_nopriv_verify_email_code', [$this, 'verify_email_code']);
		add_action('woocommerce_checkout_update_order_meta', [$this, 'add_verified_email_meta_to_order'], 10, 1);
		add_action('wp_ajax_resend_verification_code', [$this, 'resend_verification_code']);
		add_action('wp_ajax_nopriv_resend_verification_code', [$this, 'resend_verification_code']);
		add_action('wc_blacklist_manager_cleanup_verification_code', [$this, 'cleanup_expired_code']);

		add_action('init', [$this, 'initialize_session'], 1);
	}

	public function enqueue_verification_scripts() {
		if (is_checkout() && get_option('wc_blacklist_email_verification_enabled') == '1') {
			wp_enqueue_script(
				'yobm-wc-blacklist-manager-verifications-email',
				plugins_url('/../../../js/yobm-wc-blacklist-manager-verifications-email.js', __FILE__),
				['jquery'],
				'1.4',
				true 
			);
	
			wp_localize_script('yobm-wc-blacklist-manager-verifications-email', 'wc_blacklist_manager_verification_data', [
				'ajax_url'                  => admin_url('admin-ajax.php'),
				'resendCooldown'            => $this->resend_cooldown_seconds,
				'nonce'                     => wp_create_nonce('email_verification_nonce'),
				'enter_code_placeholder'    => __('Enter code', 'wc-blacklist-manager'),
				'verify_button_label'       => __('Verify', 'wc-blacklist-manager'),
				'resend_in_label'           => __('Can resend in', 'wc-blacklist-manager'),
				'seconds_label'             => __('seconds', 'wc-blacklist-manager'),
				'resend_button_label'       => __('Resend code', 'wc-blacklist-manager'),
				'enter_code_alert'          => __('Please enter the verification code.', 'wc-blacklist-manager'),
				'code_resent_message'       => __('A new code has been sent to your email.', 'wc-blacklist-manager'),
				'code_resend_failed_message' => __('Failed to resend the code. Please try again.', 'wc-blacklist-manager'),
			]);
		}
	}
	
	public function initialize_session() {
		if (class_exists('WC_Session') && WC()->session) {
			if (!WC()->session->has_session()) {
				WC()->session->set_customer_session_cookie(true);
			}
		}
	}

	public function email_verification() {
		if (is_checkout() && get_option('wc_blacklist_email_verification_enabled') == '1') {
			// Get the billing email from the checkout form (sanitize the input)
			$email = isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : '';
	
			// If email is empty, skip verification
			if (empty($email)) {
				wc_add_notice(__('Please enter your email address for verification.', 'wc-blacklist-manager'), 'error');
				return;
			}
	
			$verification_action = get_option('wc_blacklist_email_verification_action');
	
			// Verify based on the selected action
			if ($verification_action === 'all' && !$this->is_email_in_whitelist($email)) {
				$this->send_verification_code($email);
				wc_add_notice('<span class="email-verification-error">' . __('Please verify your email before proceeding with the checkout.', 'wc-blacklist-manager') . '</span>', 'error');
			}
	
			if ($verification_action === 'suspect' && $this->is_email_in_blacklist($email)) {
				$this->send_verification_code($email);
				wc_add_notice('<span class="email-verification-error">' . __('Please verify your email before proceeding with the checkout.', 'wc-blacklist-manager') . '</span>', 'error');
			}
		}
	}		
	
	private function is_email_in_whitelist($email) {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT * FROM $this->whitelist_table WHERE email = %s AND verified_email = 1", 
			$email
		);

		$result = $wpdb->get_row($query);

		return $result ? true : false;
	}

	private function is_email_in_blacklist($email) {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT * FROM $this->blacklist_table WHERE email_address = %s AND is_blocked = 0", 
			$email
		);

		$result = $wpdb->get_row($query);

		return $result ? true : false;
	}	

	private function send_verification_code($email) {
		$verification_code = wp_rand(100000, 999999);
		$timestamp = time();
	
		$user_id = get_current_user_id();
		if ($user_id === 0) {
			if (WC()->session) {
				WC()->session->set($this->verification_code_meta_key, $verification_code);
				WC()->session->set($this->verification_time_meta_key, $timestamp);
				WC()->session->set('billing_email', $email);
				WC()->session->save_data();
			}
		} else {
			update_user_meta($user_id, $this->verification_code_meta_key, $verification_code);
			update_user_meta($user_id, $this->verification_time_meta_key, $timestamp);
		}
	
		wp_schedule_single_event($timestamp + $this->verification_expiration_seconds, 'wc_blacklist_manager_cleanup_verification_code', [$user_id, $email]);
	
		$this->send_verification_email($email, $verification_code);
	}

	private function send_verification_email($email, $verification_code) {
		$mailer = WC()->mailer();
	
		$subject = __('Verify your email address', 'wc-blacklist-manager');
		$heading = __('Verify your email address', 'wc-blacklist-manager');
	
		$message = sprintf(
			/* translators: %s is the verification code */
			__('Hi there,<br><br>To complete your checkout process, please verify your email address by entering the following code:<br><br><strong>%s</strong><br><br>If you did not request this, please ignore this email.<br><br>Thank you.', 'wc-blacklist-manager'),
			esc_html($verification_code)
		);
	
		$wrapped_message = $mailer->wrap_message($heading, $message);
	
		$email_instance = new WC_Email();
	
		$styled_message = $email_instance->style_inline($wrapped_message);
	
		$mailer->send(
			$email,
			$subject,
			$styled_message,
			'Content-Type: text/html; charset=UTF-8'
		);
	}	

	public function verify_email_code() {
		check_ajax_referer('email_verification_nonce', 'security');
		
		$submitted_code = isset($_POST['code']) ? sanitize_text_field(trim($_POST['code'])) : '';
		$user_id = get_current_user_id();
		
		if ($user_id === 0) {
			$stored_code = WC()->session->get($this->verification_code_meta_key);
			$stored_time = WC()->session->get($this->verification_time_meta_key);
		} else {
			$stored_code = get_user_meta($user_id, $this->verification_code_meta_key, true);
			$stored_time = get_user_meta($user_id, $this->verification_time_meta_key, true);
		}

		if (time() - $stored_time > $this->verification_expiration_seconds) {
			$this->cleanup_expired_code($user_id, '');
			wp_send_json_error(['message' => __('Code expired. Please request a new one.', 'wc-blacklist-manager')]);
			return;
		}

		if ($submitted_code == $stored_code) {
			$this->cleanup_expired_code($user_id, '');

			$verification_action = get_option('wc_blacklist_email_verification_action');

			$billing_details = [
				'first_name'     => sanitize_text_field($_POST['billing_first_name'] ?? ''),
				'last_name'      => sanitize_text_field($_POST['billing_last_name'] ?? ''),
				'address_1'      => sanitize_text_field($_POST['billing_address_1'] ?? ''),
				'address_2'      => sanitize_text_field($_POST['billing_address_2'] ?? ''),
				'city'           => sanitize_text_field($_POST['billing_city'] ?? ''),
				'state'          => sanitize_text_field($_POST['billing_state'] ?? ''),
				'postcode'       => sanitize_text_field($_POST['billing_postcode'] ?? ''),
				'country'        => sanitize_text_field($_POST['billing_country'] ?? ''),
				'email'          => sanitize_email($_POST['billing_email'] ?? ''),
				'verified_email' => 1,
				'phone'          => sanitize_text_field($_POST['billing_phone'] ?? ''),
			];

			$this->add_billing_details_to_whitelist($billing_details);

			if ($verification_action === 'suspect') {
				$this->remove_email_address_from_blacklist($billing_details['email']);
			}

			wp_send_json_success(['message' => __('Your email has been successfully verified!', 'wc-blacklist-manager')]);
		} else {
			wp_send_json_error(['message' => __('Invalid code. Please try again.', 'wc-blacklist-manager')]);
		}
	}

	private function add_billing_details_to_whitelist($billing_details) {
		global $wpdb;
		
		
		$email = $billing_details['email'];
		$phone = $billing_details['phone'];
		
		if (empty($email)) {
			return;
		}
		
		// Check if the email already exists in the whitelist
		$existing_email_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->whitelist_table WHERE email = %s", $email));
		
		if ($existing_email_entry) {
			// Update existing entry
			$wpdb->update(
				$this->whitelist_table,
				$billing_details,
				['email' => $email]
			);
		} else {
			// Insert new entry
			$wpdb->insert($this->whitelist_table, $billing_details);
		}
	}	

	public function add_verified_email_meta_to_order($order_id) {
		$order = wc_get_order($order_id); 
	
		if (!$order) {
			return;
		}
	
		$user_id = get_current_user_id();
		$email_verified = false;
	
		if ($user_id === 0) {
			$email_verified = WC()->session->get('_email_verified', 0);
		} else {
			$email_verified = get_user_meta($user_id, '_email_verified', true); 
		}
	
		if ($email_verified) {
			$order->update_meta_data('_verified_email', 1);
			$order->save();
	
			if ($user_id === 0) {
				WC()->session->__unset('_email_verified');
			} else {
				delete_user_meta($user_id, '_email_verified');
			}
		} else {

		}
	}	
	
	private function remove_email_address_from_blacklist($email) {
		global $wpdb;
	
		$blacklist_table = $wpdb->prefix . 'wc_blacklist';
	
		$wpdb->update(
			$blacklist_table,
			['email_address' => ''],
			['email_address' => $email]
		);
	}
	
	public function resend_verification_code() {
		check_ajax_referer('email_verification_nonce', 'security');
		
		$user_id = get_current_user_id();
		$email = '';
	
		if ($user_id === 0) {
			$email = WC()->session->get('billing_email');
		} else {
			$email = wp_get_current_user()->user_email;
		}
	
		if (empty($email)) {
			wp_send_json_error(['message' => __('Unable to resend the verification code. Email not found.', 'wc-blacklist-manager')]);
			return;
		}
	
		$this->cleanup_expired_code($user_id, $email);
		$this->send_verification_code($email);
		wp_send_json_success();
	}
	
	public function cleanup_expired_code($user_id, $email = '') {
		if ($user_id === 0) {
			$this->initialize_session();
			if (WC()->session) {
				WC()->session->__unset($this->verification_code_meta_key);
				WC()->session->__unset($this->verification_time_meta_key);
			}
		} else {
			delete_user_meta($user_id, $this->verification_code_meta_key);
			delete_user_meta($user_id, $this->verification_time_meta_key);
		}
	}
}

new WC_Blacklist_Manager_Verifications_Verify_Email();
