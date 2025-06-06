<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Verifications_Verify_Phone {

	private $whitelist_table;
	private $blacklist_table;
	private $verification_code_meta_key = '_phone_verification_code';
	private $verification_time_meta_key = '_phone_verification_time';
	private $resend_count_meta_key = '_phone_verification_resend_count';
	private $resend_cooldown_seconds; 
	private $resend_limit; 
	private $verification_expiration_seconds = 300; 

	public function __construct() {
		global $wpdb;
		$this->whitelist_table = $wpdb->prefix . 'wc_whitelist';
		$this->blacklist_table = $wpdb->prefix . 'wc_blacklist';

		$verification_settings = get_option('wc_blacklist_phone_verification', [
			'resend' => 60,
			'limit'  => 5, 
		]);

		$this->resend_cooldown_seconds = isset($verification_settings['resend']) ? (int) $verification_settings['resend'] : 60;
		$this->resend_limit = isset($verification_settings['limit']) ? (int) $verification_settings['limit'] : 5;

		add_action('wp_enqueue_scripts', [$this, 'enqueue_verification_scripts']);
		add_action('woocommerce_checkout_process', [$this, 'phone_verification'], 20);
		add_action('wp_ajax_verify_phone_code', [$this, 'verify_phone_code']);
		add_action('wp_ajax_nopriv_verify_phone_code', [$this, 'verify_phone_code']);
		add_action('woocommerce_checkout_update_order_meta', [$this, 'add_verified_phone_meta_to_order'], 10, 1);
		add_action('wp_ajax_resend_phone_verification_code', [$this, 'resend_verification_code']);
		add_action('wp_ajax_nopriv_resend_phone_verification_code', [$this, 'resend_verification_code']);
		add_action('wc_blacklist_manager_cleanup_verification_code', [$this, 'cleanup_expired_code']);
		add_action('wp_ajax_check_sms_verification_status', [$this, 'yoohw_check_sms_verification_status']);
        add_action('wp_ajax_nopriv_check_sms_verification_status', [$this, 'yoohw_check_sms_verification_status']);
        add_action('yoohw_sms_verification_failed', [$this, 'handle_sms_verification_failed']);

		add_action('init', [$this, 'initialize_session'], 1);
	}

	public function enqueue_verification_scripts() {
		if (is_checkout() && get_option('wc_blacklist_phone_verification_enabled') == '1') {
			wp_enqueue_script(
				'yobm-wc-blacklist-manager-verifications-phone',
				plugins_url('/../../../js/yobm-wc-blacklist-manager-verifications-phone.js', __FILE__),
				['jquery'],
				'1.9.0', 
				true  
			);
		
			wp_localize_script('yobm-wc-blacklist-manager-verifications-phone', 'wc_blacklist_manager_verification_data', [
				'ajax_url'                  => admin_url('admin-ajax.php'),
				'resendCooldown'            => $this->resend_cooldown_seconds,
				'resendLimit'               => $this->resend_limit,
				'nonce'                     => wp_create_nonce('phone_verification_nonce'),
				'enter_code_placeholder'    => __('Enter code', 'wc-blacklist-manager'),
				'verify_button_label'       => __('Verify', 'wc-blacklist-manager'),
				'resend_in_label'           => __('Can resend in', 'wc-blacklist-manager'),
				'seconds_label'             => __('seconds', 'wc-blacklist-manager'),
				'resend_button_label'       => __('Resend code', 'wc-blacklist-manager'),
				'enter_code_alert'          => __('Please enter the verification code.', 'wc-blacklist-manager'),
				'code_resent_message'       => __('A new code has been sent to your phone.', 'wc-blacklist-manager'),
				'code_resend_failed_message' => __('Failed to resend the code. Please try again.', 'wc-blacklist-manager'),
				'resend_limit_reached_message' => __('You have reached the resend limit. Please contact support.', 'wc-blacklist-manager'),
				'verification_failed_message' => __('Failed to send verification code. Please check your phone number and try again. If the issue persists, contact customer support.', 'wc-blacklist-manager'),
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

	public function phone_verification() {
		$phone_verified = false;
	
		if (is_checkout() && get_option('wc_blacklist_phone_verification_enabled') == '1') {    
			$user_id = get_current_user_id();
		
			// Get the selected country code from the hidden field
			$billing_dial_code = isset($_POST['billing_dial_code']) ? sanitize_text_field($_POST['billing_dial_code']) : '';

			// Get the billing phone from the checkout form and store it in the session
			if (!empty($_POST['billing_phone'])) {
				$phone = sanitize_text_field($_POST['billing_phone']);
				$phone = ltrim($phone, '0');

				// Prepend country code if available
				if (!empty($billing_dial_code)) {
					$phone = preg_replace('/[^0-9]/', '', $phone);
					$phone = $billing_dial_code . $phone;
				} else {
					$phone = $this->normalize_phone_number_with_country_code( $phone );
				}

				WC()->session->set('billing_phone', $phone);
			} else {
				$phone = WC()->session->get('billing_phone', '');
			}

			// If phone is empty, skip verification
			if (empty($phone)) {
				wc_add_notice(__('Please enter your phone number for verification.', 'wc-blacklist-manager'), 'error');
				return;
			}
	
			// Check resend count
			$resend_count = $user_id === 0 
				? WC()->session->get($this->resend_count_meta_key, 0) 
				: (get_user_meta($user_id, $this->resend_count_meta_key, true) ?: 0);
	
			if ($resend_count >= $this->resend_limit) {
				wc_add_notice(__('You have reached the phone verification limit. Please contact support.', 'wc-blacklist-manager'), 'error');
				return;
			}
	
			$verification_action = get_option('wc_blacklist_phone_verification_action');

			error_log("Phone: $phone");
	
			// Verify based on the selected action
			if ($verification_action === 'all') {
				if (!$this->is_phone_in_whitelist($phone)) {
					$this->send_verification_code($phone);
					// Only add the notice if there are no existing error notices
					if (empty(wc_get_notices('error'))) {
						wc_add_notice('<span class="yobm-phone-verification-error">' . __('Please verify your phone number before proceeding with the checkout.', 'wc-blacklist-manager') . '</span>', 'error');
					}

					if ($user_id === 0) {
						$resend_count = WC()->session->get($this->resend_count_meta_key, 0);
						$resend_count++;
						WC()->session->set($this->resend_count_meta_key, $resend_count);
					} else {
						$resend_count = get_user_meta($user_id, $this->resend_count_meta_key, true) ?: 0;
						$resend_count++;
						update_user_meta($user_id, $this->resend_count_meta_key, $resend_count);
					}					
				} else {
					$phone_verified = true;
				}
			}
	
			if ($verification_action === 'suspect') {
				if ($this->is_phone_in_blacklist($phone)) {
					$this->send_verification_code($phone);
					if (empty(wc_get_notices('error'))) {
						wc_add_notice('<span class="yobm-phone-verification-error">' . __('Please verify your phone number before proceeding with the checkout.', 'wc-blacklist-manager') . '</span>', 'error');
					}

					if ($user_id === 0) {
						$resend_count = WC()->session->get($this->resend_count_meta_key, 0);
						$resend_count++;
						WC()->session->set($this->resend_count_meta_key, $resend_count);
					} else {
						$resend_count = get_user_meta($user_id, $this->resend_count_meta_key, true) ?: 0;
						$resend_count++;
						update_user_meta($user_id, $this->resend_count_meta_key, $resend_count);
					}					
				}
			}
	
			// Store the verification status in session or user meta
			if ($user_id === 0) {
				WC()->session->set('_phone_verified', $phone_verified ? 1 : 0);
			} else {
				update_user_meta($user_id, '_phone_verified', $phone_verified ? 1 : 0);
			}
		}
	}
	
	private function is_phone_in_whitelist($phone) {
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT 1 FROM $this->whitelist_table WHERE TRIM(LEADING '0' FROM phone) = %s AND verified_phone = 1 LIMIT 1", 
			$phone
		);
		$result = $wpdb->get_var($query);
		return !empty($result);	
	}

	private function is_phone_in_blacklist($phone) {
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT 1 FROM $this->blacklist_table WHERE TRIM(LEADING '0' FROM phone_number) = %s AND is_blocked = 0 LIMIT 1", 
			$phone
		);
		$result = $wpdb->get_var($query);
		return !empty($result);
	}

	private function send_verification_code($phone) {
		$verification_settings = get_option('wc_blacklist_phone_verification', [
			'code_length' => 4
		]);
	
		$code_length = max(4, min(10, (int) $verification_settings['code_length']));
		$verification_code = wp_rand(pow(10, $code_length - 1), pow(10, $code_length) - 1);
		$timestamp = time();
	
		$user_id = get_current_user_id();
		if ($user_id === 0) {
			if (WC()->session) {
				WC()->session->set($this->verification_code_meta_key, $verification_code);
				WC()->session->set($this->verification_time_meta_key, $timestamp);
				WC()->session->set('billing_phone', $phone);
				WC()->session->save_data(); 
			}
		} else {
			update_user_meta($user_id, $this->verification_code_meta_key, $verification_code);
			update_user_meta($user_id, $this->verification_time_meta_key, $timestamp);
		}
	
		wp_schedule_single_event($timestamp + $this->verification_expiration_seconds, 'wc_blacklist_manager_cleanup_verification_code', [$user_id, $phone]);
	
		$service = get_option( 'yoohw_sms_service', 'yo_credits' );
		if ( $service === 'yo_credits' ) {
			$this->send_verification_sms($phone, $verification_code);
		} elseif ( $service === 'twilio' ) {
			WC_Blacklist_Manager_Premium_Verifications_Service::send_verification_sms_twilio($phone, $verification_code);
		} elseif ($service === 'textmagic') {
			WC_Blacklist_Manager_Premium_Verifications_Service::send_verification_sms_textmagic($phone, $verification_code);
		}
	}

	private function send_verification_sms( $phone, $verification_code ) {
		$verification_settings = get_option( 'wc_blacklist_phone_verification', array() );
		$sms_key = get_option( 'yoohw_phone_verification_sms_key', '' );
		$message_template = isset( $verification_settings['message'] ) ? $verification_settings['message'] : '{site_name}: Your verification code is {code}';
	
		$message = str_replace(
			array( '{site_name}', '{code}' ),
			array( get_bloginfo( 'name' ), $verification_code ),
			$message_template
		);
	
		$phone = $this->normalize_phone_number_with_country_code( $phone );

		error_log("Generated verification code: $verification_code for phone: $phone");
	
		$data = array(
			'sms_key'  => $sms_key,
			'domain'   => home_url(),
			'phone'    => $phone,
			'message'  => $message, 
		);
	
		$response = wp_remote_post( 'https://bmc.yoohw.com/wp-json/sms/v1/send-sms/', array(
			'body'    => json_encode( $data ),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'timeout'  => 15,
		));
	
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message(); // Get the exact error message
			$this->send_admin_notification_on_sms_failure( $response, $phone, $verification_code );
			do_action('yoohw_sms_verification_failed', $phone, $verification_code);
		} else {
			$response_body = wp_remote_retrieve_body( $response );
			$response_data = json_decode( $response_body, true );

			if ( isset( $response_data['status'] ) && $response_data['status'] === 'error' ) {
				$this->send_admin_notification_on_sms_failure( $response, $phone, $verification_code );
				do_action('yoohw_sms_verification_failed', $phone, $verification_code);
			}
		}
	}    

	private function normalize_phone_number_with_country_code($phone) {
		if (substr($phone, 0, 1) === '+') {
			return $phone;
		}
	
		// Check if billing country is provided in checkout form
		if (!empty($_POST['billing_country'])) {
			$billing_country = sanitize_text_field($_POST['billing_country']);
			WC()->session->set('billing_country', $billing_country);
		} else {
			// Get the billing country from the session if not set in checkout form
			$billing_country = WC()->session->get('billing_country', '');
		}
		
		$country_code = $this->get_country_code_from_file($billing_country);
	
		if ($country_code) {
			return '+' . $country_code . $phone;
		} else {
			return '+' . $phone; // Return the phone with just '+' if the country code is not found
		}
	}
	
	private function get_country_code_from_file( $billing_country ) {
		$file_path = plugin_dir_path( __FILE__ ) . 'data/phone_country_codes.conf';

		if (file_exists($file_path)) {
			$file_content = file_get_contents($file_path);

			$lines = explode("\n", $file_content);
			foreach ($lines as $line) {
				if (strpos($line, ':') !== false) {
					list($country, $code) = explode(':', $line);
					if (trim($country) === $billing_country) {
						return trim($code);
					}
				}
			}
		}
		return null;
	}

	public function verify_phone_code() {
		check_ajax_referer('phone_verification_nonce', 'security');

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

			if ( $user_id > 0 ) {
				update_user_meta( $user_id, 'phone_verification', 1 );
			} else {
				WC()->session->set( 'wc_phone_verified', true );
			}

			// Get and sanitize the selected country code
			$billing_dial_code = isset($_POST['billing_dial_code']) ? sanitize_text_field($_POST['billing_dial_code']) : '';

			// Get and sanitize the phone number
			$billing_phone = isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '';
			$billing_phone = ltrim($billing_phone, '0');

			// Prepend country code if it exists
			if (!empty($billing_dial_code)) {
				$billing_phone = preg_replace('/[^0-9]/', '', $billing_phone);
				$billing_phone = $billing_dial_code . $billing_phone;
			} else {
				$billing_phone = $this->normalize_phone_number_with_country_code( $billing_phone );
			}

			$verification_action = get_option('wc_blacklist_phone_verification_action');

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
				'phone'          => $billing_phone,
				'verified_phone' => 1,
			];

			$this->add_billing_details_to_whitelist($billing_details);

			if ($verification_action === 'suspect') {
				$this->remove_phone_number_from_blacklist($billing_details['phone']);
			}

			$settings_instance = new WC_Blacklist_Manager_Settings();
			$premium_active = $settings_instance->is_premium_active();

			global $wpdb;
			$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
			
			$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
			update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

			if ($premium_active) {
				$timestamp = current_time('mysql');
				$type      = 'human';
				$source    = 'woo_checkout';
				$action    = 'verify';
				$details   = 'verified_phone_attempt: ' . $billing_phone;
				
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

			wp_send_json_success(['message' => __('Your phone number has been successfully verified!', 'wc-blacklist-manager')]);
		} else {
			wp_send_json_error(['message' => __('Invalid code. Please try again.', 'wc-blacklist-manager')]);
		}
	}

	private function add_billing_details_to_whitelist($billing_details) {
		global $wpdb;

		$email = $billing_details['email'];
		$phone = $billing_details['phone'];

		if (empty($phone)) {
			return;
		}

		$existing_email_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->whitelist_table WHERE email = %s", $email));
	
		if ($existing_email_entry) {
			unset($billing_details['email']);
			unset($billing_details['verified_email']);
		}

		$existing_phone_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->whitelist_table WHERE phone = %s", $phone));

		if ($existing_phone_entry) {
			$wpdb->update(
				$this->whitelist_table,
				$billing_details,
				['phone' => $phone]
			);
		} else {
			$wpdb->insert($this->whitelist_table, $billing_details);
		}
	}

	private function remove_phone_number_from_blacklist($phone) {
		global $wpdb;
	
		$blacklist_table = $wpdb->prefix . 'wc_blacklist';
	
		// Build a query that updates rows where the trimmed phone matches $phone.
		$query = $wpdb->prepare(
			"UPDATE {$blacklist_table} 
			 SET phone_number = NULL 
			 WHERE is_blocked = 0 
			   AND TRIM(LEADING '0' FROM phone_number) = %s",
			$phone
		);
	
		$result = $wpdb->query($query);
	
		if ($result !== false) {
			error_log("Phone {$phone} removed from blacklist at " . current_time('mysql'));
		}
	}
	
	public function add_verified_phone_meta_to_order($order_id) {
		$order = wc_get_order($order_id);
	
		if (!$order) {
			return;
		}
	
		$user_id = get_current_user_id();
		$phone_verified = false;
	
		if ($user_id === 0) {
			$phone_verified = WC()->session->get('_phone_verified', 0);
		} else {
			$phone_verified = get_user_meta($user_id, '_phone_verified', true);
		}
	
		if ($phone_verified) {
			$order->update_meta_data('_verified_phone', 1); 
			$order->save();
	
			if ($user_id === 0) {
				WC()->session->__unset('_phone_verified');
			} else {
				delete_user_meta($user_id, '_phone_verified');
			}
		} else {

		}
	}
	
	public function resend_verification_code() {
		check_ajax_referer('phone_verification_nonce', 'security');

		$user_id = get_current_user_id();
		$email = '';

		if ($user_id === 0) {
			$resend_count = WC()->session->get($this->resend_count_meta_key, 0);
		} else {
			$resend_count = get_user_meta($user_id, $this->resend_count_meta_key, true) ?: 0;
		}

		// Check if the resend limit has been reached
		if ($resend_count >= $this->resend_limit) {
			wp_send_json_error(['message' => __('You have reached the resend limit. Please contact support.', 'wc-blacklist-manager')]);
			return;
		}

		$phone = WC()->session->get('billing_phone');

		if (empty($phone)) {
			wp_send_json_error(['message' => __('Unable to resend the verification code. Phone number not found.', 'wc-blacklist-manager')]);
			return;
		}

		$this->cleanup_expired_code($user_id, $phone);
		$this->send_verification_code($phone);

		if ($user_id === 0) {
			WC()->session->set($this->resend_count_meta_key, ++$resend_count);
		} else {
			update_user_meta($user_id, $this->resend_count_meta_key, ++$resend_count);
		}

		wp_send_json_success();
	}

	public function cleanup_expired_code($user_id, $phone = '') {
		if ($user_id === 0) {
			$this->initialize_session();
			if (WC()->session) {
				WC()->session->__unset($this->verification_code_meta_key);
				WC()->session->__unset($this->verification_time_meta_key);
				WC()->session->__unset($this->resend_count_meta_key); 
			}
		} else {
			delete_user_meta($user_id, $this->verification_code_meta_key);
			delete_user_meta($user_id, $this->verification_time_meta_key);
			delete_user_meta($user_id, $this->resend_count_meta_key);
		}
	}

	private function send_admin_notification_on_sms_failure( $response, $phone, $verification_code ) {
		$admin_notification_sms_failure = get_option( 'wc_blacklist_phone_verification_failed_email', '0' );

		if ( $admin_notification_sms_failure !== '1' ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		$additional_emails_option = get_option( 'wc_blacklist_additional_emails', '' );
		$additional_emails = ! empty( $additional_emails_option ) ? array_map( 'trim', explode( ',', $additional_emails_option ) ) : array();
	
		$recipients = array_merge( array( $admin_email ), $additional_emails );
		$recipients = implode( ',', $recipients ); // Convert to comma-separated string
	
		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );
	
		$error_message = isset( $response_data['message'] ) ? $response_data['message'] : 'Unknown error occurred while sending SMS.';
	
		$template_path = trailingslashit( plugin_dir_path( __FILE__ ) ) . '../emails/templates/sms-failed.html';
	
		if ( file_exists( $template_path ) ) {
			$html_template = file_get_contents( $template_path );
	
			// Replace placeholders in the template
			$email_body = str_replace(
				array( '{{phone_number}}', '{{error_message}}' ),
				array( esc_html( $phone ), esc_html( $error_message ) ),
				$html_template
			);
		} else {
			// Fallback plain text email if template is missing
			$email_body = sprintf(
				"An error occurred while sending the verification SMS.\n\nPhone: %s\nError Message: %s",
				esc_html( $phone ),
				esc_html( $error_message )
			);
		}
	
		$subject = 'SMS Verification Failed Notification';
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	
		wp_mail( $recipients, $subject, $email_body, $headers );
	}
	
	public function yoohw_check_sms_verification_status() {
		if (!check_ajax_referer('phone_verification_nonce', 'security', false)) {
			wp_send_json_error(['message' => 'Security check failed.']);
		}

		$failed_sms = get_transient('yoohw_sms_verification_failed');
		
		if ($failed_sms) {
			delete_transient('yoohw_sms_verification_failed'); // Reset the failure flag
			wp_send_json_success(['failed' => true]);
		} else {
			wp_send_json_success(['failed' => false]);
		}
	}

	public function handle_sms_verification_failed() {
		set_transient('yoohw_sms_verification_failed', true, 60); // Store for 60 seconds
	}
}

new WC_Blacklist_Manager_Verifications_Verify_Phone();
