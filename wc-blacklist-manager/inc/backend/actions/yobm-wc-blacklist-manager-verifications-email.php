<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Verifications_Verify_Email {
	private $whitelist_table;
	private $blacklist_table;
	private $verification_code_meta_key = '_email_verification_code';
	private $verification_time_meta_key = '_email_verification_time';
	private $resend_cooldown_seconds;
	private $verification_expiration_seconds = 300; 
	private $default_email_subject;
	private $default_email_heading;
	private $default_email_message;

	public function __construct() {
		global $wpdb;
		$this->whitelist_table = $wpdb->prefix . 'wc_whitelist';
		$this->blacklist_table = $wpdb->prefix . 'wc_blacklist';

		$email_settings = get_option( 'wc_blacklist_email_verification', [] );
        $this->resend_cooldown_seconds = isset( $email_settings['resend'] )
            ? intval( $email_settings['resend'] )
            : 180;

		add_action('init', [$this, 'set_verifications_strings']);
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

	public function set_verifications_strings() {
		$this->default_email_subject = __('Verify your email address on {site_name}', 'wc-blacklist-manager');
		$this->default_email_heading = __('Verify your email address', 'wc-blacklist-manager');
		$this->default_email_message = __('Hi {first_name} {last_name},<br><br>To complete your checkout process, please verify your email address by entering the following code:<br><br><strong>{code}</strong><br><br>If you did not request this, please ignore this email.<br><br>Thank you.', 'wc-blacklist-manager');
	}

	public function enqueue_verification_scripts() {
		if (is_checkout() && get_option('wc_blacklist_email_verification_enabled') == '1') {
			wp_enqueue_script(
				'yobm-wc-blacklist-manager-verifications-email',
				plugins_url('/../../../js/yobm-wc-blacklist-manager-verifications-email.js', __FILE__),
				['jquery'],
				'2.0',
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
				return;
			}
	
			$verification_action = get_option('wc_blacklist_email_verification_action');
	
			// Verify based on the selected action
			if ($verification_action === 'all' && !$this->is_email_in_whitelist($email)) {
				$this->send_verification_code($email);
				if (empty(wc_get_notices('error'))) {
					wc_add_notice('<span class="yobm-email-verification-error">' . __('Please verify your email before proceeding with the checkout.', 'wc-blacklist-manager') . '</span>', 'error');
				}
				return;
			}
	
			if ($verification_action === 'suspect' && $this->is_email_in_blacklist($email)) {
				$this->send_verification_code($email);
				if (empty(wc_get_notices('error'))) {
					wc_add_notice('<span class="yobm-email-verification-error">' . __('Please verify your email before proceeding with the checkout.', 'wc-blacklist-manager') . '</span>', 'error');
				}
				return;
			}
		}
	}		
	
	private function is_email_in_whitelist($email) {
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT 1 FROM $this->whitelist_table WHERE email = %s AND verified_email = 1 LIMIT 1", 
			$email
		);
		$result = $wpdb->get_var($query);
		return !empty($result);
	}
	
	private function is_email_in_blacklist($email) {
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT 1 FROM $this->blacklist_table WHERE email_address = %s AND is_blocked = 0 LIMIT 1", 
			$email
		);
		$result = $wpdb->get_var($query);
		return !empty($result);
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

	private function send_verification_email( $email, $verification_code ) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		$email_settings = get_option( 'wc_blacklist_email_verification', [] );

		$first_name = isset( $_POST['billing_first_name'] )
			? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) )
			: '';
		$last_name  = isset( $_POST['billing_last_name'] )
			? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) )
			: '';

		$mailer = WC()->mailer();

		if ( $premium_active ) {

			$subject  = $email_settings['subject'] ?? $this->default_email_subject;
			$heading  = $email_settings['heading'] ?? $this->default_email_heading;

			$template = $email_settings['message'] ?? $this->default_email_message;

			$search  = [ '{site_name}', '{code}', '{first_name}', '{last_name}' ];
			$replace = [
				get_bloginfo( 'name' ),
				esc_html( $verification_code ),
				esc_html( $first_name ),
				esc_html( $last_name ),
			];

			$subject  = str_replace( $search, $replace, $subject );
			$heading  = str_replace( $search, $replace, $heading );
			$message = str_replace( $search, $replace, $template );

		} else {

			$subject = __( 'Verify your email address', 'wc-blacklist-manager' );
			$heading = __( 'Verify your email address', 'wc-blacklist-manager' );

			$message = sprintf(
				__( 'Hi there,<br><br>To complete your checkout process, please verify your email address by entering the following code:<br><br><strong>%s</strong><br><br>If you did not request this, please ignore this email.<br><br>Thank you.', 'wc-blacklist-manager' ),
				esc_html( $verification_code )
			);
		}

		$wrapped = $mailer->wrap_message( $heading, $message );
		$emailer = new WC_Email();
		$styled  = $emailer->style_inline( $wrapped );

		$mailer->send(
			$email,
			$subject,
			$styled,
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}

	public function verify_email_code() {
		$ip_address = $_SERVER['REMOTE_ADDR'];
		$attempts   = get_transient( "verify_email_attempts_{$ip_address}" );

		if ( $attempts >= 5 ) {
			wp_send_json_error( [ 'message' => __( 'Too many attempts. Please try again later.', 'wc-blacklist-manager' ) ] );
			return;
		}

		set_transient( "verify_email_attempts_{$ip_address}", $attempts + 1, HOUR_IN_SECONDS );

		check_ajax_referer( 'email_verification_nonce', 'security' );

		$submitted_code = isset( $_POST['code'] ) ? sanitize_text_field( trim( $_POST['code'] ) ) : '';
		$user_id        = get_current_user_id();

		if ( $user_id === 0 ) {
			// Guest (checkout) context: code stored in session
			$stored_code = WC()->session->get( $this->verification_code_meta_key );
			$stored_time = WC()->session->get( $this->verification_time_meta_key );
		} else {
			// Logged-in user
			$stored_code = get_user_meta( $user_id, $this->verification_code_meta_key, true );
			$stored_time = get_user_meta( $user_id, $this->verification_time_meta_key, true );
		}

		// Expiration check
		if ( time() - $stored_time > $this->verification_expiration_seconds ) {
			$this->cleanup_expired_code( $user_id, '' );
			wp_send_json_error( [ 'message' => __( 'Code expired. Please request a new one.', 'wc-blacklist-manager' ) ] );
			return;
		}

		// Match check
		if ( $submitted_code == $stored_code ) {
			// Remove stored code
			$this->cleanup_expired_code( $user_id, '' );

			// --- NEW: mark email_verified in user meta ---
			if ( $user_id > 0 ) {
				update_user_meta( $user_id, 'email_verification', 1 );
			} else {
				// If you need to handle newly created users at checkout,
				// you can flag it in session and hook into 'woocommerce_created_customer'
				WC()->session->set( 'wc_email_verified', true );
			}

			// Retrieve & sanitize billing data
			$billing_dial_code = isset( $_POST['billing_dial_code'] ) ? sanitize_text_field( $_POST['billing_dial_code'] ) : '';
			$billing_phone     = isset( $_POST['billing_phone'] )      ? sanitize_text_field( $_POST['billing_phone'] )      : '';

			$billing_phone = preg_replace( '/[^0-9]/', '', $billing_phone );
			$billing_phone = ltrim( $billing_phone, '0' );

			if ( ! empty( $billing_dial_code ) ) {
				$billing_phone = $billing_dial_code . $billing_phone;
			}

			$verification_action = get_option( 'wc_blacklist_email_verification_action' );

			$billing_details = [
				'first_name'     => sanitize_text_field( $_POST['billing_first_name'] ?? '' ),
				'last_name'      => sanitize_text_field( $_POST['billing_last_name']  ?? '' ),
				'address_1'      => sanitize_text_field( $_POST['billing_address_1']  ?? '' ),
				'address_2'      => sanitize_text_field( $_POST['billing_address_2']  ?? '' ),
				'city'           => sanitize_text_field( $_POST['billing_city']       ?? '' ),
				'state'          => sanitize_text_field( $_POST['billing_state']      ?? '' ),
				'postcode'       => sanitize_text_field( $_POST['billing_postcode']   ?? '' ),
				'country'        => sanitize_text_field( $_POST['billing_country']    ?? '' ),
				'email'          => sanitize_email(   $_POST['billing_email']      ?? '' ),
				'verified_email' => 1,
				'phone'          => $billing_phone,
			];

			$this->add_billing_details_to_whitelist( $billing_details );

			if ( $verification_action === 'suspect' ) {
				$this->remove_email_address_from_blacklist( $billing_details['email'] );
			}

			$settings_instance = new WC_Blacklist_Manager_Settings();
			$premium_active    = $settings_instance->is_premium_active();

			global $wpdb;
			$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
			$email               = sanitize_email( $_POST['billing_email'] ?? '' );
			$sum_block_total     = get_option( 'wc_blacklist_sum_block_total', 0 );
			update_option( 'wc_blacklist_sum_block_total', $sum_block_total + 1 );

			if ( $premium_active ) {
				$wpdb->insert(
					$table_detection_log,
					[
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'woo_checkout',
						'action'    => 'verify',
						'details'   => 'verified_email_attempt: ' . $email,
					]
				);
			}

			wp_send_json_success( [ 'message' => __( 'Your email has been successfully verified!', 'wc-blacklist-manager' ) ] );

		} else {
			wp_send_json_error( [ 'message' => __( 'Invalid code. Please try again.', 'wc-blacklist-manager' ) ] );
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
	
		$result = $wpdb->update(
			$blacklist_table,
			['email_address' => null],
			['email_address' => $email, 'is_blocked' => 0]
		);
	
		if ($result) {
			error_log("Email {$email} removed from blacklist at " . current_time('mysql'));
		}
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
