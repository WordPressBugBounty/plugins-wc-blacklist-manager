<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_Verifications_Verify_Email {
	private $whitelist_table;
	private $blacklist_table;

	private $session_state_key = 'wc_blacklist_email_verification_state';

	private $resend_cooldown_seconds;
	private $verification_expiration_seconds = 300;

	private $default_email_subject;
	private $default_email_heading;
	private $default_email_message;

	private $blocks_extension_namespace = 'wc-blacklist-manager-email-verification';

	public function __construct() {

		if ( '1' !== get_option( 'wc_blacklist_email_verification_enabled' ) ) {
			return;
		}

		global $wpdb;

		$this->whitelist_table = $wpdb->prefix . 'wc_whitelist';
		$this->blacklist_table = $wpdb->prefix . 'wc_blacklist';

		$email_settings                 = get_option( 'wc_blacklist_email_verification', array() );
		$this->resend_cooldown_seconds = isset( $email_settings['resend'] ) ? absint( $email_settings['resend'] ) : 180;

		add_action( 'init', array( $this, 'set_verifications_strings' ) );
		add_action( 'init', array( $this, 'initialize_session' ), 1 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_verification_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_blocks_verification_scripts' ) );

		add_action( 'woocommerce_checkout_process', array( $this, 'email_verification' ), 20 );

		add_action( 'wp_ajax_verify_email_code', array( $this, 'verify_email_code' ) );
		add_action( 'wp_ajax_nopriv_verify_email_code', array( $this, 'verify_email_code' ) );

		add_action( 'wp_ajax_resend_verification_code', array( $this, 'resend_verification_code' ) );
		add_action( 'wp_ajax_nopriv_resend_verification_code', array( $this, 'resend_verification_code' ) );

		add_action( 'wp_ajax_send_verification_code_blocks', array( $this, 'send_verification_code_blocks' ) );
		add_action( 'wp_ajax_nopriv_send_verification_code_blocks', array( $this, 'send_verification_code_blocks' ) );

		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'add_verified_email_meta_to_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'add_verified_email_meta_to_order' ), 10, 1 );

		add_action( 'wc_blacklist_manager_cleanup_verification_code', array( $this, 'cleanup_expired_code' ), 10, 2 );

		add_filter( 'rest_authentication_errors', array( $this, 'validate_blocks_checkout_request' ), 20 );
	}

	public function set_verifications_strings() {
		$this->default_email_subject = __( 'Verify your email address on {site_name}', 'wc-blacklist-manager' );
		$this->default_email_heading = __( 'Verify your email address', 'wc-blacklist-manager' );
		$this->default_email_message = __( 'Hi {first_name} {last_name},<br><br>To complete your checkout process, please verify your email address by entering the following code:<br><br><strong>{code}</strong><br><br>If you did not request this, please ignore this email.<br><br>Thank you.', 'wc-blacklist-manager' );
	}

	public function initialize_session() {
		if ( class_exists( 'WC_Session' ) && WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	public function enqueue_verification_scripts() {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'verifications-email',
			plugins_url( '/../../../js/verifications-email.js', __FILE__ ),
			array( 'jquery' ),
			'2.2.0',
			true
		);

		wp_localize_script(
			'verifications-email',
			'wc_blacklist_manager_verification_data',
			array(
				'ajax_url'                  => admin_url( 'admin-ajax.php' ),
				'resendCooldown'           => $this->resend_cooldown_seconds,
				'nonce'                     => wp_create_nonce( 'email_verification_nonce' ),
				'enter_code_placeholder'    => __( 'Enter code', 'wc-blacklist-manager' ),
				'verify_button_label'       => __( 'Verify', 'wc-blacklist-manager' ),
				'resend_in_label'           => __( 'Can resend in', 'wc-blacklist-manager' ),
				'seconds_label'             => __( 'seconds', 'wc-blacklist-manager' ),
				'resend_button_label'       => __( 'Resend code', 'wc-blacklist-manager' ),
				'enter_code_alert'          => __( 'Please enter the verification code.', 'wc-blacklist-manager' ),
				'code_resent_message'       => __( 'A new code has been sent to your email.', 'wc-blacklist-manager' ),
				'code_resend_failed_message'=> __( 'Failed to resend the code. Please try again.', 'wc-blacklist-manager' ),
			)
		);
	}

	public function enqueue_blocks_verification_scripts() {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'verifications-email-blocks',
			plugins_url( '/../../../js/verifications-email-blocks.js', __FILE__ ),
			array( 'jquery', 'wp-data' ),
			'2.2.2',
			true
		);

		wp_localize_script(
			'verifications-email-blocks',
			'wc_blacklist_manager_blocks_verification_data',
			array(
				'ajax_url'                     => admin_url( 'admin-ajax.php' ),
				'nonce'                        => wp_create_nonce( 'email_verification_nonce' ),
				'namespace'                    => $this->blocks_extension_namespace,
				'resendCooldown'               => $this->resend_cooldown_seconds,
				'enter_code_placeholder'       => __( 'Enter code', 'wc-blacklist-manager' ),
				'verify_button_label'          => __( 'Verify', 'wc-blacklist-manager' ),
				'resend_button_label'          => __( 'Resend code', 'wc-blacklist-manager' ),
				'resend_in_label'              => __( 'Can resend in', 'wc-blacklist-manager' ),
				'seconds_label'                => __( 'seconds', 'wc-blacklist-manager' ),
				'enter_code_alert'             => __( 'Please enter the verification code.', 'wc-blacklist-manager' ),
				'verify_required_message'      => $this->get_verification_required_message(),
				'code_sent_message'            => __( 'A verification code has been sent to your email.', 'wc-blacklist-manager' ),
				'code_resent_message'          => __( 'A new code has been sent to your email.', 'wc-blacklist-manager' ),
				'code_resend_failed_message'   => __( 'Failed to resend the code. Please try again.', 'wc-blacklist-manager' ),
				'verification_success_message' => __( 'Your email has been successfully verified!', 'wc-blacklist-manager' ),
			)
		);
	}

	public function email_verification() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( ! is_checkout() ) {
			return;
		}

		$email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';

		if ( empty( $email ) ) {
			return;
		}

		if ( ! $this->requires_email_verification( $email ) ) {
			$this->clear_verification_state_if_email_mismatch( $email );
			return;
		}

		if ( $this->is_email_verified_for_checkout( $email ) ) {
			return;
		}

		$state = $this->get_verification_state();

		if (
			empty( $state ) ||
			empty( $state['email'] ) ||
			$state['email'] !== $email ||
			empty( $state['code'] ) ||
			empty( $state['sent_at'] ) ||
			$this->is_state_expired( $state )
		) {
			$this->send_verification_code( $email );
		}

		if ( empty( wc_get_notices( 'error' ) ) ) {
			wc_add_notice(
				'<span class="yobm-email-verification-error">' . esc_html( $this->get_verification_required_message() ) . '</span>',
				'error'
			);
		}
	}

	private function get_verification_required_message() {
		return __( 'Please verify your email before placing the order.', 'wc-blacklist-manager' );
	}

	private function requires_email_verification( $email ) {
		if ( empty( $email ) ) {
			return false;
		}

		$verification_action = get_option( 'wc_blacklist_email_verification_action' );

		if ( 'all' === $verification_action ) {
			return ! $this->is_email_in_whitelist( $email );
		}

		if ( 'suspect' === $verification_action ) {
			return $this->is_email_in_blacklist( $email );
		}

		return false;
	}

	private function is_email_in_whitelist( $email ) {
		global $wpdb;

		if ( empty( $email ) || ! is_email( $email ) ) {
			return false;
		}

		$normalized_email = function_exists( 'yobm_normalize_email' )
			? yobm_normalize_email( $email )
			: sanitize_email( $email );

		if ( empty( $normalized_email ) ) {
			return false;
		}

		$query = $wpdb->prepare(
			"SELECT 1 FROM {$this->whitelist_table} WHERE email = %s AND verified_email = 1 LIMIT 1",
			$normalized_email
		);

		return (bool) $wpdb->get_var( $query );
	}

	private function is_email_in_blacklist( $email ) {
		global $wpdb;

		if ( empty( $email ) || ! is_email( $email ) ) {
			return false;
		}

		$normalized_email = function_exists( 'yobm_normalize_email' )
			? yobm_normalize_email( $email )
			: sanitize_email( $email );

		if ( empty( $normalized_email ) ) {
			return false;
		}

		$query = $wpdb->prepare(
			"SELECT 1 FROM {$this->blacklist_table} WHERE normalized_email = %s AND is_blocked = 0 LIMIT 1",
			$normalized_email
		);

		return (bool) $wpdb->get_var( $query );
	}

	private function get_storage_context() {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			return array(
				'type'    => 'user',
				'user_id' => $user_id,
			);
		}

		return array(
			'type'    => 'session',
			'user_id' => 0,
		);
	}

	private function get_verification_state() {
		$context = $this->get_storage_context();

		if ( 'user' === $context['type'] ) {
			$state = get_user_meta( $context['user_id'], $this->session_state_key, true );
		} else {
			$this->initialize_session();
			$state = WC()->session ? WC()->session->get( $this->session_state_key ) : array();
		}

		return is_array( $state ) ? $state : array();
	}

	private function set_verification_state( $state ) {
		$context = $this->get_storage_context();

		if ( 'user' === $context['type'] ) {
			update_user_meta( $context['user_id'], $this->session_state_key, $state );
		} else {
			$this->initialize_session();

			if ( WC()->session ) {
				WC()->session->set( $this->session_state_key, $state );
				WC()->session->save_data();
			}
		}
	}

	private function clear_verification_state() {
		$context = $this->get_storage_context();

		if ( 'user' === $context['type'] ) {
			delete_user_meta( $context['user_id'], $this->session_state_key );
		} else {
			$this->initialize_session();

			if ( WC()->session ) {
				WC()->session->__unset( $this->session_state_key );
				WC()->session->save_data();
			}
		}
	}

	private function clear_verification_state_if_email_mismatch( $email ) {
		$state = $this->get_verification_state();

		if ( ! empty( $state['email'] ) && $state['email'] !== $email ) {
			$this->clear_verification_state();
		}
	}

	private function is_state_expired( $state ) {
		if ( empty( $state['sent_at'] ) ) {
			return true;
		}

		return ( time() - absint( $state['sent_at'] ) ) > $this->verification_expiration_seconds;
	}

	private function is_email_verified_for_checkout( $email ) {
		$state = $this->get_verification_state();

		if ( empty( $state ) || empty( $state['verified'] ) || empty( $state['email'] ) ) {
			return false;
		}

		if ( $state['email'] !== $email ) {
			return false;
		}

		if ( $this->is_state_expired( $state ) ) {
			$this->clear_verification_state();
			return false;
		}

		return true;
	}

	private function can_resend_code( $state ) {
		if ( empty( $state['resend_available_at'] ) ) {
			return true;
		}

		return time() >= absint( $state['resend_available_at'] );
	}

	private function maybe_schedule_cleanup_event( $timestamp, $email ) {
		$args = array( get_current_user_id(), $email );

		if ( ! wp_next_scheduled( 'wc_blacklist_manager_cleanup_verification_code', $args ) ) {
			wp_schedule_single_event(
				$timestamp + $this->verification_expiration_seconds,
				'wc_blacklist_manager_cleanup_verification_code',
				$args
			);
		}
	}

	private function send_verification_code( $email, $force = false ) {
		$state = $this->get_verification_state();

		if (
			! $force &&
			! empty( $state ) &&
			! empty( $state['email'] ) &&
			$state['email'] === $email &&
			! $this->is_state_expired( $state ) &&
			! empty( $state['code'] )
		) {
			return;
		}

		$verification_code = (string) wp_rand( 100000, 999999 );
		$timestamp         = time();

		$new_state = array(
			'email'               => $email,
			'code'                => $verification_code,
			'sent_at'             => $timestamp,
			'verified'            => false,
			'verified_email'      => '',
			'resend_available_at' => $timestamp + $this->resend_cooldown_seconds,
		);

		$this->set_verification_state( $new_state );
		$this->maybe_schedule_cleanup_event( $timestamp, $email );
		$this->send_verification_email( $email, $verification_code );
	}

	private function send_verification_email( $email, $verification_code ) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		$email_settings = get_option( 'wc_blacklist_email_verification', array() );

		$first_name = isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '';
		$last_name  = isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '';

		$mailer = WC()->mailer();

		if ( $premium_active ) {
			$subject  = isset( $email_settings['subject'] ) ? $email_settings['subject'] : $this->default_email_subject;
			$heading  = isset( $email_settings['heading'] ) ? $email_settings['heading'] : $this->default_email_heading;
			$template = isset( $email_settings['message'] ) ? $email_settings['message'] : $this->default_email_message;

			$search  = array( '{site_name}', '{code}', '{first_name}', '{last_name}' );
			$replace = array(
				get_bloginfo( 'name' ),
				esc_html( $verification_code ),
				esc_html( $first_name ),
				esc_html( $last_name ),
			);

			$subject = str_replace( $search, $replace, $subject );
			$heading = str_replace( $search, $replace, $heading );
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
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	public function verify_email_code() {
		check_ajax_referer( 'email_verification_nonce', 'security' );

		$ip_address      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$attempts        = (int) get_transient( 'verify_email_attempts_' . md5( $ip_address ) );
		$submitted_code  = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$submitted_email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';

		if ( $attempts >= 5 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many attempts. Please try again later.', 'wc-blacklist-manager' ),
				)
			);
		}

		set_transient( 'verify_email_attempts_' . md5( $ip_address ), $attempts + 1, HOUR_IN_SECONDS );

		if ( empty( $submitted_code ) || empty( $submitted_email ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Missing verification data. Please try again.', 'wc-blacklist-manager' ),
				)
			);
		}

		$state = $this->get_verification_state();

		if ( empty( $state ) || empty( $state['email'] ) || empty( $state['code'] ) || empty( $state['sent_at'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No verification code was found. Please request a new one.', 'wc-blacklist-manager' ),
				)
			);
		}

		if ( $state['email'] !== $submitted_email ) {
			$this->clear_verification_state();

			wp_send_json_error(
				array(
					'message' => __( 'The email address has changed. Please request a new verification code.', 'wc-blacklist-manager' ),
				)
			);
		}

		if ( $this->is_state_expired( $state ) ) {
			$this->clear_verification_state();

			wp_send_json_error(
				array(
					'message' => __( 'Code expired. Please request a new one.', 'wc-blacklist-manager' ),
				)
			);
		}

		if ( $submitted_code !== (string) $state['code'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid code. Please try again.', 'wc-blacklist-manager' ),
				)
			);
		}

		$billing_dial_code = isset( $_POST['billing_dial_code'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_dial_code'] ) ) : '';
		$billing_phone     = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';

		$billing_phone = preg_replace( '/[^0-9]/', '', $billing_phone );
		$billing_phone = ltrim( $billing_phone, '0' );

		if ( ! empty( $billing_dial_code ) ) {
			$billing_phone = $billing_dial_code . $billing_phone;
		}

		$billing_details = array(
			'first_name'     => isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '',
			'last_name'      => isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '',
			'address_1'      => isset( $_POST['billing_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ) ) : '',
			'address_2'      => isset( $_POST['billing_address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ) ) : '',
			'city'           => isset( $_POST['billing_city'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) ) : '',
			'state'          => isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '',
			'postcode'       => isset( $_POST['billing_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ) ) : '',
			'country'        => isset( $_POST['billing_country'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) : '',
			'email'          => $submitted_email,
			'verified_email' => 1,
			'phone'          => $billing_phone,
		);

		$this->add_billing_details_to_whitelist( $billing_details );

		if ( 'suspect' === get_option( 'wc_blacklist_email_verification_action' ) ) {
			$this->mark_email_as_verified_in_blacklist( $submitted_email );
		}

		$state['verified']       = true;
		$state['verified_email'] = $submitted_email;
		$state['code']           = '';
		$this->set_verification_state( $state );

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		if ( $premium_active ) {
			global $wpdb;

			$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

			$wpdb->insert(
				$table_detection_log,
				array(
					'timestamp' => current_time( 'mysql' ),
					'type'      => 'human',
					'source'    => 'woo_checkout',
					'action'    => 'verify',
					'details'   => 'verified_email_attempt: ' . $submitted_email,
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Your email has been successfully verified!', 'wc-blacklist-manager' ),
			)
		);
	}

	private function add_billing_details_to_whitelist( $billing_details ) {
		global $wpdb;

		$email = isset( $billing_details['email'] ) ? $billing_details['email'] : '';

		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		$normalized_email = function_exists( 'yobm_normalize_email' )
			? yobm_normalize_email( $email )
			: sanitize_email( $email );

		if ( empty( $normalized_email ) ) {
			return;
		}

		$billing_details['email'] = $normalized_email;

		$existing_email_entry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->whitelist_table} WHERE email = %s",
				$normalized_email
			)
		);

		if ( $existing_email_entry ) {
			$wpdb->update(
				$this->whitelist_table,
				$billing_details,
				array( 'email' => $normalized_email )
			);
		} else {
			$wpdb->insert( $this->whitelist_table, $billing_details );
		}
	}

	public function add_verified_email_meta_to_order( $order_or_id ) {
		$order = is_numeric( $order_or_id ) ? wc_get_order( $order_or_id ) : $order_or_id;

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$order_email = $order->get_billing_email();

		if ( empty( $order_email ) ) {
			return;
		}

		if ( $this->is_email_verified_for_checkout( $order_email ) ) {
			$order->update_meta_data( '_verified_email', 1 );
			$order->save();

			$this->clear_verification_state();
		}
	}

	private function mark_email_as_verified_in_blacklist( $email ) {
		global $wpdb;

		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		$normalized_email = function_exists( 'yobm_normalize_email' )
			? yobm_normalize_email( $email )
			: sanitize_email( $email );

		if ( empty( $normalized_email ) ) {
			return;
		}

		$updated = $wpdb->update(
			$this->blacklist_table,
			array(
				'is_blocked' => 2,
			),
			array(
				'normalized_email' => $normalized_email,
				'is_blocked'       => 0,
			),
			array( '%d' ),
			array( '%s', '%d' )
		);
	}

	public function resend_verification_code() {
		check_ajax_referer( 'email_verification_nonce', 'security' );

		$submitted_email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';
		$state           = $this->get_verification_state();

		if ( empty( $submitted_email ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unable to resend the verification code. Email not found.', 'wc-blacklist-manager' ),
				)
			);
		}

		if ( ! empty( $state['email'] ) && $state['email'] !== $submitted_email ) {
			$this->clear_verification_state();
			$state = array();
		}

		if ( ! empty( $state ) && ! $this->can_resend_code( $state ) ) {
			$remaining = max( 1, absint( $state['resend_available_at'] ) - time() );

			wp_send_json_error(
				array(
					'message'   => sprintf(
						/* translators: %d: seconds remaining */
						__( 'Please wait %d seconds before requesting a new code.', 'wc-blacklist-manager' ),
						$remaining
					),
					'remaining' => $remaining,
				)
			);
		}

		$this->send_verification_code( $submitted_email, true );

		wp_send_json_success(
			array(
				'message' => __( 'A new code has been sent to your email.', 'wc-blacklist-manager' ),
			)
		);
	}

	public function send_verification_code_blocks() {
		check_ajax_referer( 'email_verification_nonce', 'security' );

		$email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';

		if ( empty( $email ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Email not found.', 'wc-blacklist-manager' ),
				)
			);
		}

		if ( ! $this->requires_email_verification( $email ) ) {
			wp_send_json_success(
				array(
					'required' => false,
					'message'  => '',
				)
			);
		}

		$state = $this->get_verification_state();

		if (
			empty( $state ) ||
			empty( $state['email'] ) ||
			$state['email'] !== $email ||
			empty( $state['code'] ) ||
			empty( $state['sent_at'] ) ||
			$this->is_state_expired( $state )
		) {
			$this->send_verification_code( $email, true );
		}

		wp_send_json_success(
			array(
				'required' => true,
				'message'  => __( 'A verification code has been sent to your email.', 'wc-blacklist-manager' ),
			)
		);
	}

	public function cleanup_expired_code( $user_id, $email = '' ) {
		$state = $this->get_verification_state();

		if ( empty( $state ) ) {
			return;
		}

		if ( ! empty( $email ) && ! empty( $state['email'] ) && $state['email'] !== $email ) {
			return;
		}

		if ( ! empty( $state['verified'] ) ) {
			return;
		}

		$this->clear_verification_state();
	}

	public function validate_blocks_checkout_request( $result ) {
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return $result;
		}

		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';

		if ( ! is_string( $route ) || ! preg_match( '#/wc/store(?:/v\d+)?/checkout#', $route ) ) {
			return $result;
		}

		$request_body = json_decode( \WP_REST_Server::get_raw_data(), true );

		if ( ! is_array( $request_body ) ) {
			return $result;
		}

		$billing_email = '';

		if ( ! empty( $request_body['billing_address']['email'] ) ) {
			$billing_email = sanitize_email( $request_body['billing_address']['email'] );
		} elseif ( ! empty( $request_body['email'] ) ) {
			$billing_email = sanitize_email( $request_body['email'] );
		}

		if ( empty( $billing_email ) ) {
			return $result;
		}

		if ( ! $this->requires_email_verification( $billing_email ) ) {
			return $result;
		}

		$extensions = isset( $request_body['extensions'] ) && is_array( $request_body['extensions'] )
			? $request_body['extensions']
			: array();

		$extension_data = isset( $extensions[ $this->blocks_extension_namespace ] ) && is_array( $extensions[ $this->blocks_extension_namespace ] )
			? $extensions[ $this->blocks_extension_namespace ]
			: array();

		$client_verified = ! empty( $extension_data['verified'] );
		$client_email    = ! empty( $extension_data['email'] ) ? sanitize_email( $extension_data['email'] ) : '';

		if ( ! $client_verified || empty( $client_email ) || $client_email !== $billing_email ) {
			return new WP_Error(
				'yobm_email_verification_required',
				$this->get_verification_required_message(),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->is_email_verified_for_checkout( $billing_email ) ) {
			return new WP_Error(
				'yobm_email_verification_required',
				$this->get_verification_required_message(),
				array( 'status' => 403 )
			);
		}

		return $result;
	}
}

new WC_Blacklist_Manager_Verifications_Verify_Email();