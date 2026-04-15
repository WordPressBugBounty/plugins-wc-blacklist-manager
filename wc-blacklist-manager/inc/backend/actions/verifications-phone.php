<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_Verifications_Verify_Phone {

	private $whitelist_table;
	private $blacklist_table;

	private $session_state_key = 'wc_blacklist_phone_verification_state';

	private $resend_cooldown_seconds;
	private $resend_limit;
	private $verification_expiration_seconds = 300;

	private $blocks_extension_namespace = 'wc-blacklist-manager-phone-verification';

	public function __construct() {
		if ( '1' !== get_option( 'wc_blacklist_phone_verification_enabled' ) ) {
			return;
		}

		global $wpdb;

		$this->whitelist_table = $wpdb->prefix . 'wc_whitelist';
		$this->blacklist_table = $wpdb->prefix . 'wc_blacklist';

		$verification_settings         = get_option(
			'wc_blacklist_phone_verification',
			array(
				'resend' => 60,
				'limit'  => 5,
			)
		);
		$this->resend_cooldown_seconds = isset( $verification_settings['resend'] ) ? absint( $verification_settings['resend'] ) : 60;
		$this->resend_limit            = isset( $verification_settings['limit'] ) ? absint( $verification_settings['limit'] ) : 5;

		add_action( 'init', array( $this, 'initialize_session' ), 1 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_verification_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_blocks_verification_scripts' ) );

		add_action( 'woocommerce_checkout_process', array( $this, 'phone_verification' ), 20 );

		add_action( 'wp_ajax_verify_phone_code', array( $this, 'verify_phone_code' ) );
		add_action( 'wp_ajax_nopriv_verify_phone_code', array( $this, 'verify_phone_code' ) );

		add_action( 'wp_ajax_resend_phone_verification_code', array( $this, 'resend_verification_code' ) );
		add_action( 'wp_ajax_nopriv_resend_phone_verification_code', array( $this, 'resend_verification_code' ) );

		add_action( 'wp_ajax_send_phone_verification_code_blocks', array( $this, 'send_verification_code_blocks' ) );
		add_action( 'wp_ajax_nopriv_send_phone_verification_code_blocks', array( $this, 'send_verification_code_blocks' ) );

		add_action( 'wp_ajax_check_sms_verification_status', array( $this, 'yoohw_check_sms_verification_status' ) );
		add_action( 'wp_ajax_nopriv_check_sms_verification_status', array( $this, 'yoohw_check_sms_verification_status' ) );
		add_action( 'yoohw_sms_verification_failed', array( $this, 'handle_sms_verification_failed' ) );

		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'add_verified_phone_meta_to_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'add_verified_phone_meta_to_order' ), 10, 1 );

		add_action( 'wc_blacklist_manager_cleanup_verification_code', array( $this, 'cleanup_expired_code' ), 10, 2 );

		add_filter( 'rest_authentication_errors', array( $this, 'validate_blocks_checkout_request' ), 20 );
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
			'verifications-phone',
			plugins_url( '/../../../js/verifications-phone.js', __FILE__ ),
			array( 'jquery' ),
			'2.2.1',
			true
		);

		wp_localize_script(
			'verifications-phone',
			'wc_blacklist_manager_phone_verification_data',
			array(
				'ajax_url'                      => admin_url( 'admin-ajax.php' ),
				'resendCooldown'               => $this->resend_cooldown_seconds,
				'resendLimit'                  => $this->resend_limit,
				'nonce'                        => wp_create_nonce( 'phone_verification_nonce' ),
				'enter_code_placeholder'       => __( 'Enter code', 'wc-blacklist-manager' ),
				'verify_button_label'          => __( 'Verify', 'wc-blacklist-manager' ),
				'resend_in_label'              => __( 'Can resend in', 'wc-blacklist-manager' ),
				'seconds_label'                => __( 'seconds', 'wc-blacklist-manager' ),
				'resend_button_label'          => __( 'Resend code', 'wc-blacklist-manager' ),
				'enter_code_alert'             => __( 'Please enter the verification code.', 'wc-blacklist-manager' ),
				'code_resent_message'          => __( 'A new code has been sent to your phone.', 'wc-blacklist-manager' ),
				'code_resend_failed_message'   => __( 'Failed to resend the code. Please try again.', 'wc-blacklist-manager' ),
				'resend_limit_reached_message' => __( 'You have reached the resend limit. Please contact support.', 'wc-blacklist-manager' ),
				'verification_failed_message'  => $this->get_sms_send_failed_message(),
			)
		);
	}

	public function enqueue_blocks_verification_scripts() {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'verifications-phone-blocks',
			plugins_url( '/../../../js/verifications-phone-blocks.js', __FILE__ ),
			array( 'jquery', 'wp-data' ),
			'2.2.2',
			true
		);

		wp_localize_script(
			'verifications-phone-blocks',
			'wc_blacklist_manager_phone_blocks_verification_data',
			array(
				'ajax_url'                      => admin_url( 'admin-ajax.php' ),
				'nonce'                        => wp_create_nonce( 'phone_verification_nonce' ),
				'namespace'                    => $this->blocks_extension_namespace,
				'resendCooldown'               => $this->resend_cooldown_seconds,
				'resendLimit'                  => $this->resend_limit,
				'enter_code_placeholder'       => __( 'Enter code', 'wc-blacklist-manager' ),
				'verify_button_label'          => __( 'Verify', 'wc-blacklist-manager' ),
				'resend_button_label'          => __( 'Resend code', 'wc-blacklist-manager' ),
				'resend_in_label'              => __( 'Can resend in', 'wc-blacklist-manager' ),
				'seconds_label'                => __( 'seconds', 'wc-blacklist-manager' ),
				'enter_code_alert'             => __( 'Please enter the verification code.', 'wc-blacklist-manager' ),
				'verify_required_message'      => $this->get_verification_required_message(),
				'code_sent_message'            => __( 'A verification code has been sent to your phone.', 'wc-blacklist-manager' ),
				'code_resent_message'          => __( 'A new code has been sent to your phone.', 'wc-blacklist-manager' ),
				'code_resend_failed_message'   => __( 'Failed to resend the code. Please try again.', 'wc-blacklist-manager' ),
				'resend_limit_reached_message' => __( 'You have reached the resend limit. Please contact support.', 'wc-blacklist-manager' ),
				'verification_success_message' => __( 'Your phone number has been successfully verified!', 'wc-blacklist-manager' ),
				'verification_failed_message'  => $this->get_sms_send_failed_message(),
			)
		);
	}

	public function phone_verification() {
		if ( ! class_exists( 'WooCommerce' ) || ! is_checkout() ) {
			return;
		}

		$phone = $this->get_canonical_phone_from_request();

		if ( empty( $phone ) ) {
			wc_add_notice( __( 'Please enter your phone number for verification.', 'wc-blacklist-manager' ), 'error' );
			return;
		}

		if ( ! $this->requires_phone_verification( $phone ) ) {
			$this->clear_verification_state_if_phone_mismatch( $phone );
			return;
		}

		if ( $this->is_phone_verified_for_checkout( $phone ) ) {
			return;
		}

		$state = $this->get_verification_state();

		if (
			empty( $state ) ||
			empty( $state['phone'] ) ||
			$state['phone'] !== $phone ||
			empty( $state['code'] ) ||
			empty( $state['sent_at'] ) ||
			$this->is_state_expired( $state )
		) {
			$send_result = $this->send_verification_code( $phone );

			if ( is_wp_error( $send_result ) ) {
				wc_add_notice( $send_result->get_error_message(), 'error' );
				return;
			}
		}

		if ( empty( wc_get_notices( 'error' ) ) ) {
			wc_add_notice(
				'<span class="yobm-phone-verification-error">' . esc_html( $this->get_verification_required_message() ) . '</span>',
				'error'
			);
		}
	}

	private function get_verification_required_message() {
		return __( 'Please verify your phone number before proceeding with the checkout.', 'wc-blacklist-manager' );
	}

	private function get_sms_send_failed_message() {
		return __( 'To complete the checkout, we need to verify your phone number, but we were unable to send the verification code. This may be because the phone number is incorrect or it\'s a landline, which can\'t receive text messages. Please check the number and try again. If the problem persists, contact customer support for help.', 'wc-blacklist-manager' );
	}

	private function requires_phone_verification( $phone ) {
		if ( empty( $phone ) ) {
			return false;
		}

		$verification_action = get_option( 'wc_blacklist_phone_verification_action' );

		if ( 'all' === $verification_action ) {
			return ! $this->is_phone_in_whitelist( $phone );
		}

		if ( 'suspect' === $verification_action ) {
			return $this->is_phone_in_blacklist( $phone );
		}

		return false;
	}

	private function get_phone_request_data() {
		$billing_phone      = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
		$billing_dial_code  = isset( $_POST['billing_dial_code'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_dial_code'] ) ) : '';
		$billing_country    = isset( $_POST['billing_country'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) : '';

		$shipping_phone     = isset( $_POST['shipping_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_phone'] ) ) : '';
		$shipping_dial_code = isset( $_POST['shipping_dial_code'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_dial_code'] ) ) : '';
		$shipping_country   = isset( $_POST['shipping_country'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_country'] ) ) : '';

		$phone = $billing_phone;
		if ( '' === $phone && '' !== $shipping_phone ) {
			$phone = $shipping_phone;
		}

		$dial_code = $billing_dial_code;
		if ( '' === $dial_code && '' !== $shipping_dial_code ) {
			$dial_code = $shipping_dial_code;
		}

		$country = $billing_country;
		if ( '' === $country && '' !== $shipping_country ) {
			$country = $shipping_country;
		}

		return array(
			'phone'     => $phone,
			'dial_code' => $dial_code,
			'country'   => $country,
		);
	}

	private function get_canonical_phone_from_request() {
		$data = $this->get_phone_request_data();

		return $this->build_canonical_phone(
			$data['phone'],
			$data['dial_code'],
			$data['country']
		);
	}

	private function build_canonical_phone( $raw_phone, $billing_dial_code = '', $billing_country = '' ) {
		$raw_phone         = sanitize_text_field( $raw_phone );
		$billing_dial_code = sanitize_text_field( $billing_dial_code );
		$billing_country   = sanitize_text_field( $billing_country );

		if ( '' === $billing_dial_code && '' !== $billing_country && function_exists( 'yobm_get_country_dial_code' ) ) {
			$country_dial = yobm_get_country_dial_code( $billing_country );
			if ( ! empty( $country_dial ) ) {
				$billing_dial_code = '+' . preg_replace( '/\D+/', '', (string) $country_dial );
			}
		}

		if ( function_exists( 'yobm_normalize_phone' ) ) {
			return yobm_normalize_phone( $raw_phone, $billing_dial_code );
		}

		$digits = preg_replace( '/\D+/', '', (string) $raw_phone );
		$digits = ltrim( $digits, '0' );

		if ( '' === $digits ) {
			return '';
		}

		$dial_digits = preg_replace( '/\D+/', '', (string) $billing_dial_code );

		if ( '' !== $dial_digits && 0 !== strpos( $digits, $dial_digits ) ) {
			$digits = $dial_digits . $digits;
		}

		return $digits;
	}

	private function format_phone_for_sms( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( empty( $digits ) ) {
			return '';
		}
		return '+' . $digits;
	}

	private function is_phone_in_whitelist( $phone ) {
		global $wpdb;

		if ( empty( $phone ) ) {
			return false;
		}

		$normalized_phone = function_exists( 'yobm_normalize_phone' )
			? yobm_normalize_phone( $phone )
			: preg_replace( '/\D+/', '', (string) $phone );

		if ( empty( $normalized_phone ) ) {
			return false;
		}

		$query = $wpdb->prepare(
			"SELECT 1 FROM {$this->whitelist_table} WHERE phone = %s AND verified_phone = 1 LIMIT 1",
			$normalized_phone
		);

		return (bool) $wpdb->get_var( $query );
	}

	private function is_phone_in_blacklist( $phone ) {
		global $wpdb;

		if ( empty( $phone ) ) {
			return false;
		}

		$normalized_phone = function_exists( 'yobm_normalize_phone' )
			? yobm_normalize_phone( $phone )
			: preg_replace( '/\D+/', '', (string) $phone );

		if ( empty( $normalized_phone ) ) {
			return false;
		}

		$query = $wpdb->prepare(
			"SELECT 1 FROM {$this->blacklist_table} WHERE normalized_phone = %s AND is_blocked = 0 LIMIT 1",
			$normalized_phone
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

	private function clear_verification_state_if_phone_mismatch( $phone ) {
		$state = $this->get_verification_state();

		if ( ! empty( $state['phone'] ) && $state['phone'] !== $phone ) {
			$this->clear_verification_state();
		}
	}

	private function is_state_expired( $state ) {
		if ( empty( $state['sent_at'] ) ) {
			return true;
		}

		return ( time() - absint( $state['sent_at'] ) ) > $this->verification_expiration_seconds;
	}

	private function is_phone_verified_for_checkout( $phone ) {
		$state = $this->get_verification_state();

		if ( empty( $state ) || empty( $state['verified'] ) || empty( $state['phone'] ) ) {
			return false;
		}

		if ( $state['phone'] !== $phone ) {
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

	private function maybe_schedule_cleanup_event( $timestamp, $phone ) {
		$args = array( get_current_user_id(), $phone );

		if ( ! wp_next_scheduled( 'wc_blacklist_manager_cleanup_verification_code', $args ) ) {
			wp_schedule_single_event(
				$timestamp + $this->verification_expiration_seconds,
				'wc_blacklist_manager_cleanup_verification_code',
				$args
			);
		}
	}

	private function send_verification_code( $phone, $force = false ) {
		$state = $this->get_verification_state();

		if (
			! $force &&
			! empty( $state ) &&
			! empty( $state['phone'] ) &&
			$state['phone'] === $phone &&
			! $this->is_state_expired( $state ) &&
			! empty( $state['code'] )
		) {
			return true;
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		$verification_settings = get_option(
			'wc_blacklist_phone_verification',
			array(
				'code_length' => 4,
			)
		);

		$code_length       = max( 4, min( 10, (int) $verification_settings['code_length'] ) );
		$verification_code = (string) wp_rand( pow( 10, $code_length - 1 ), pow( 10, $code_length ) - 1 );
		$timestamp         = time();

		$resend_count = ! empty( $state['resend_count'] ) ? absint( $state['resend_count'] ) : 0;

		$new_state = array(
			'phone'               => $phone,
			'code'                => $verification_code,
			'sent_at'             => $timestamp,
			'verified'            => false,
			'verified_phone'      => '',
			'resend_available_at' => $timestamp + $this->resend_cooldown_seconds,
			'resend_count'        => $resend_count,
		);

		$this->set_verification_state( $new_state );
		$this->maybe_schedule_cleanup_event( $timestamp, $phone );

		$service = get_option( 'yoohw_sms_service', 'yo_credits' );
		$result  = true;

		if ( 'yo_credits' === $service ) {
			$result = $this->send_verification_sms( $phone, $verification_code );
		} elseif ( 'twilio' === $service && $premium_active ) {
			$result = WC_Blacklist_Manager_Premium_Verifications_Service::send_verification_sms_twilio( $this->format_phone_for_sms( $phone ), $verification_code );
			if ( is_wp_error( $result ) ) {
			} elseif ( false === $result || null === $result ) {
				$result = new WP_Error( 'sms_send_failed', $this->get_sms_send_failed_message() );
			}
		} elseif ( 'textmagic' === $service && $premium_active ) {
			$result = WC_Blacklist_Manager_Premium_Verifications_Service::send_verification_sms_textmagic( $this->format_phone_for_sms( $phone ), $verification_code );
			if ( is_wp_error( $result ) ) {
			} elseif ( false === $result || null === $result ) {
				$result = new WP_Error( 'sms_send_failed', $this->get_sms_send_failed_message() );
			}
		}

		if ( is_wp_error( $result ) ) {
			$this->clear_verification_state();
			return $result;
		}

		return true;
	}

	private function send_verification_sms( $phone, $verification_code ) {
		$verification_settings = get_option( 'wc_blacklist_phone_verification', array() );
		$sms_key               = get_option( 'yoohw_phone_verification_sms_key', '' );
		$message_template      = isset( $verification_settings['message'] ) ? $verification_settings['message'] : '{site_name}: Your verification code is {code}';

		$message = str_replace(
			array( '{site_name}', '{code}' ),
			array( get_bloginfo( 'name' ), $verification_code ),
			$message_template
		);

		$outbound_phone = $this->format_phone_for_sms( $phone );

		$data = array(
			'sms_key' => $sms_key,
			'domain'  => home_url(),
			'phone'   => $outbound_phone,
			'message' => $message,
		);

		$response = wp_remote_post(
			'https://bmc.yoohw.com/wp-json/sms/v1/send-sms/',
			array(
				'body'    => wp_json_encode( $data ),
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->send_admin_notification_on_sms_failure( $response, $phone, $verification_code );
			do_action( 'yoohw_sms_verification_failed', $phone, $verification_code );

			return new WP_Error( 'sms_send_failed', $this->get_sms_send_failed_message() );
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->send_admin_notification_on_sms_failure( $response, $phone, $verification_code );
			do_action( 'yoohw_sms_verification_failed', $phone, $verification_code );

			$message = $this->get_sms_send_failed_message();

			if ( is_array( $response_data ) && ! empty( $response_data['message'] ) ) {
				$message = $response_data['message'];
			}

			return new WP_Error( 'sms_send_failed', $message );
		}

		if ( ! is_array( $response_data ) ) {
			$this->send_admin_notification_on_sms_failure( $response, $phone, $verification_code );
			do_action( 'yoohw_sms_verification_failed', $phone, $verification_code );

			return new WP_Error( 'sms_send_failed', $this->get_sms_send_failed_message() );
		}

		if (
			( isset( $response_data['status'] ) && in_array( strtolower( (string) $response_data['status'] ), array( 'error', 'failed', 'failure' ), true ) ) ||
			( isset( $response_data['success'] ) && false === $response_data['success'] ) ||
			( isset( $response_data['ok'] ) && false === $response_data['ok'] ) ||
			( isset( $response_data['sent'] ) && false === $response_data['sent'] )
		) {
			$this->send_admin_notification_on_sms_failure( $response, $phone, $verification_code );
			do_action( 'yoohw_sms_verification_failed', $phone, $verification_code );

			$message = ! empty( $response_data['message'] ) ? $response_data['message'] : $this->get_sms_send_failed_message();

			return new WP_Error( 'sms_send_failed', $message );
		}

		return true;
	}

	public function verify_phone_code() {
		check_ajax_referer( 'phone_verification_nonce', 'security' );

		$ip_address     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$attempts       = (int) get_transient( 'verify_phone_attempts_' . md5( $ip_address ) );
		$submitted_code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		$request_phone_data = $this->get_phone_request_data();
		$submitted_phone    = $this->build_canonical_phone(
			$request_phone_data['phone'],
			$request_phone_data['dial_code'],
			$request_phone_data['country']
		);
		$billing_country = $request_phone_data['country'];

		if ( $attempts >= 5 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many attempts. Please try again later.', 'wc-blacklist-manager' ),
				)
			);
		}

		set_transient( 'verify_phone_attempts_' . md5( $ip_address ), $attempts + 1, HOUR_IN_SECONDS );

		if ( empty( $submitted_code ) || empty( $submitted_phone ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Missing verification data. Please try again.', 'wc-blacklist-manager' ),
				)
			);
		}

		$state = $this->get_verification_state();

		if ( empty( $state ) || empty( $state['phone'] ) || empty( $state['code'] ) || empty( $state['sent_at'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No verification code was found. Please request a new one.', 'wc-blacklist-manager' ),
				)
			);
		}

		if ( $state['phone'] !== $submitted_phone ) {
			$this->clear_verification_state();

			wp_send_json_error(
				array(
					'message' => __( 'The phone number has changed. Please request a new verification code.', 'wc-blacklist-manager' ),
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

		$billing_details = array(
			'first_name'     => isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '',
			'last_name'      => isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '',
			'address_1'      => isset( $_POST['billing_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ) ) : '',
			'address_2'      => isset( $_POST['billing_address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ) ) : '',
			'city'           => isset( $_POST['billing_city'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) ) : '',
			'state'          => isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '',
			'postcode'       => isset( $_POST['billing_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ) ) : '',
			'country'        => $billing_country,
			'email'          => isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '',
			'phone'          => $submitted_phone,
			'verified_phone' => 1,
		);

		$this->add_billing_details_to_whitelist( $billing_details );

		if ( 'suspect' === get_option( 'wc_blacklist_phone_verification_action' ) ) {
			$this->mark_phone_as_verified_in_blacklist( $submitted_phone );
		}

		$state['verified']       = true;
		$state['verified_phone'] = $submitted_phone;
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
					'details'   => 'verified_phone_attempt: ' . $submitted_phone,
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Your phone number has been successfully verified!', 'wc-blacklist-manager' ),
			)
		);
	}

	private function add_billing_details_to_whitelist( $billing_details ) {
		global $wpdb;

		$phone = isset( $billing_details['phone'] ) ? $billing_details['phone'] : '';
		$email = isset( $billing_details['email'] ) ? $billing_details['email'] : '';

		if ( empty( $phone ) ) {
			return;
		}

		$normalized_phone = function_exists( 'yobm_normalize_phone' )
			? yobm_normalize_phone( $phone )
			: preg_replace( '/\D+/', '', (string) $phone );

		if ( empty( $normalized_phone ) ) {
			return;
		}

		$billing_details['phone'] = $normalized_phone;

		if ( ! empty( $email ) && is_email( $email ) ) {
			$normalized_email = function_exists( 'yobm_normalize_email' )
				? yobm_normalize_email( $email )
				: sanitize_email( $email );

			if ( ! empty( $normalized_email ) ) {
				$billing_details['email'] = $normalized_email;
			}
		}

		$existing_email_entry = ! empty( $billing_details['email'] )
			? $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->whitelist_table} WHERE email = %s",
					$billing_details['email']
				)
			)
			: null;

		if ( $existing_email_entry ) {
			unset( $billing_details['email'] );
			unset( $billing_details['verified_email'] );
		}

		$existing_phone_entry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->whitelist_table} WHERE phone = %s",
				$normalized_phone
			)
		);

		if ( $existing_phone_entry ) {
			$wpdb->update(
				$this->whitelist_table,
				$billing_details,
				array( 'phone' => $normalized_phone )
			);
		} else {
			$wpdb->insert( $this->whitelist_table, $billing_details );
		}
	}

	private function mark_phone_as_verified_in_blacklist( $phone ) {
		global $wpdb;

		if ( empty( $phone ) ) {
			return;
		}

		$normalized_phone = function_exists( 'yobm_normalize_phone' )
			? yobm_normalize_phone( $phone )
			: preg_replace( '/\D+/', '', (string) $phone );

		if ( empty( $normalized_phone ) ) {
			return;
		}

		$updated = $wpdb->update(
			$this->blacklist_table,
			array(
				'is_blocked' => 2,
			),
			array(
				'normalized_phone' => $normalized_phone,
				'is_blocked'       => 0,
			),
			array( '%d' ),
			array( '%s', '%d' )
		);
	}

	public function add_verified_phone_meta_to_order( $order_or_id ) {
		$order = is_numeric( $order_or_id ) ? wc_get_order( $order_or_id ) : $order_or_id;

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$order_phone = $this->build_canonical_phone(
			$order->get_billing_phone(),
			'',
			$order->get_billing_country()
		);

		if ( empty( $order_phone ) ) {
			return;
		}

		if ( $this->is_phone_verified_for_checkout( $order_phone ) ) {
			$order->update_meta_data( '_verified_phone', 1 );
			$order->save();

			$this->clear_verification_state();
		}
	}

	public function resend_verification_code() {
		check_ajax_referer( 'phone_verification_nonce', 'security' );

		$submitted_phone = $this->get_canonical_phone_from_request();
		$state           = $this->get_verification_state();

		if ( empty( $submitted_phone ) && ! empty( $state['phone'] ) ) {
			$submitted_phone = $state['phone'];
		}

		if ( empty( $submitted_phone ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unable to resend the verification code. Phone number not found.', 'wc-blacklist-manager' ),
				)
			);
		}

		if ( ! empty( $state['phone'] ) && $state['phone'] !== $submitted_phone ) {
			$this->clear_verification_state();
			$state = array();
		}

		$resend_count = ! empty( $state['resend_count'] ) ? absint( $state['resend_count'] ) : 0;

		if ( $resend_count >= $this->resend_limit ) {
			wp_send_json_error(
				array(
					'message' => __( 'You have reached the resend limit. Please contact support.', 'wc-blacklist-manager' ),
				)
			);
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

		$send_result = $this->send_verification_code( $submitted_phone, true );

		if ( is_wp_error( $send_result ) ) {
			wp_send_json_error(
				array(
					'message' => $send_result->get_error_message(),
					'failed'  => true,
				)
			);
		}

		$updated_state                 = $this->get_verification_state();
		$updated_state['resend_count'] = $resend_count + 1;
		$this->set_verification_state( $updated_state );

		wp_send_json_success(
			array(
				'message' => __( 'A new code has been sent to your phone.', 'wc-blacklist-manager' ),
			)
		);
	}

	public function send_verification_code_blocks() {
		check_ajax_referer( 'phone_verification_nonce', 'security' );

		$request_phone_data = $this->get_phone_request_data();
		$phone              = $this->build_canonical_phone(
			$request_phone_data['phone'],
			$request_phone_data['dial_code'],
			$request_phone_data['country']
		);

		if ( empty( $phone ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Phone number not found.', 'wc-blacklist-manager' ),
				)
			);
		}

		if ( ! $this->requires_phone_verification( $phone ) ) {
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
			empty( $state['phone'] ) ||
			$state['phone'] !== $phone ||
			empty( $state['code'] ) ||
			empty( $state['sent_at'] ) ||
			$this->is_state_expired( $state )
		) {
			$send_result = $this->send_verification_code( $phone, true );

			if ( is_wp_error( $send_result ) ) {
				wp_send_json_error(
					array(
						'message' => $send_result->get_error_message(),
						'failed'  => true,
					)
				);
			}
		}

		wp_send_json_success(
			array(
				'required' => true,
				'message'  => __( 'A verification code has been sent to your phone.', 'wc-blacklist-manager' ),
			)
		);
	}

	public function cleanup_expired_code( $user_id, $phone = '' ) {
		$state = $this->get_verification_state();

		if ( empty( $state ) ) {
			return;
		}

		if ( ! empty( $phone ) && ! empty( $state['phone'] ) && $state['phone'] !== $phone ) {
			return;
		}

		if ( ! empty( $state['verified'] ) ) {
			return;
		}

		$this->clear_verification_state();
	}

	private function send_admin_notification_on_sms_failure( $response, $phone, $verification_code ) {
		$admin_notification_sms_failure = get_option( 'wc_blacklist_phone_verification_failed_email', '0' );

		if ( '1' !== $admin_notification_sms_failure ) {
			return;
		}

		$admin_email              = get_option( 'admin_email' );
		$additional_emails_option = get_option( 'wc_blacklist_additional_emails', '' );
		$additional_emails        = ! empty( $additional_emails_option ) ? array_map( 'trim', explode( ',', $additional_emails_option ) ) : array();

		$recipients = array_merge( array( $admin_email ), $additional_emails );
		$recipients = implode( ',', $recipients );

		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		$error_message = isset( $response_data['message'] ) ? $response_data['message'] : 'Unknown error occurred while sending SMS.';

		$template_path = trailingslashit( plugin_dir_path( __FILE__ ) ) . '../emails/templates/sms-failed.html';

		if ( file_exists( $template_path ) ) {
			$html_template = file_get_contents( $template_path );

			$email_body = str_replace(
				array( '{{phone_number}}', '{{error_message}}' ),
				array( esc_html( $phone ), esc_html( $error_message ) ),
				$html_template
			);
		} else {
			$email_body = sprintf(
				'An error occurred while sending the verification SMS.<br><br>Phone: %s<br>Error Message: %s',
				esc_html( $phone ),
				esc_html( $error_message )
			);
		}

		wp_mail(
			$recipients,
			'SMS Verification Failed Notification',
			$email_body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	public function yoohw_check_sms_verification_status() {
		if ( ! check_ajax_referer( 'phone_verification_nonce', 'security', false ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		}

		$failed_sms = get_transient( 'yoohw_sms_verification_failed' );

		if ( $failed_sms ) {
			delete_transient( 'yoohw_sms_verification_failed' );
			wp_send_json_success( array( 'failed' => true ) );
		} else {
			wp_send_json_success( array( 'failed' => false ) );
		}
	}

	public function handle_sms_verification_failed() {
		set_transient( 'yoohw_sms_verification_failed', true, 60 );
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

		$billing_phone   = '';
		$billing_country = '';

		if ( ! empty( $request_body['billing_address']['phone'] ) ) {
			$billing_phone = sanitize_text_field( $request_body['billing_address']['phone'] );
		} elseif ( ! empty( $request_body['shipping_address']['phone'] ) ) {
			$billing_phone = sanitize_text_field( $request_body['shipping_address']['phone'] );
		}

		if ( ! empty( $request_body['billing_address']['country'] ) ) {
			$billing_country = sanitize_text_field( $request_body['billing_address']['country'] );
		} elseif ( ! empty( $request_body['shipping_address']['country'] ) ) {
			$billing_country = sanitize_text_field( $request_body['shipping_address']['country'] );
		}

		$canonical_phone = $this->build_canonical_phone( $billing_phone, '', $billing_country );

		if ( empty( $canonical_phone ) ) {
			return $result;
		}

		if ( ! $this->requires_phone_verification( $canonical_phone ) ) {
			return $result;
		}

		$state = $this->get_verification_state();

		if (
			empty( $state ) ||
			empty( $state['phone'] ) ||
			$state['phone'] !== $canonical_phone ||
			empty( $state['sent_at'] ) ||
			$this->is_state_expired( $state )
		) {
			$send_result = $this->send_verification_code( $canonical_phone, true );

			if ( is_wp_error( $send_result ) ) {
				return new WP_Error(
					'yobm_phone_verification_send_failed',
					$send_result->get_error_message(),
					array( 'status' => 403 )
				);
			}
		}

		$extensions = isset( $request_body['extensions'] ) && is_array( $request_body['extensions'] )
			? $request_body['extensions']
			: array();

		$extension_data = isset( $extensions[ $this->blocks_extension_namespace ] ) && is_array( $extensions[ $this->blocks_extension_namespace ] )
			? $extensions[ $this->blocks_extension_namespace ]
			: array();

		$client_verified = ! empty( $extension_data['verified'] );
		$client_phone    = ! empty( $extension_data['phone'] ) ? preg_replace( '/\D+/', '', $extension_data['phone'] ) : '';

		if ( ! $client_verified || empty( $client_phone ) || $client_phone !== $canonical_phone ) {
			return new WP_Error(
				'yobm_phone_verification_required',
				$this->get_verification_required_message(),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->is_phone_verified_for_checkout( $canonical_phone ) ) {
			return new WP_Error(
				'yobm_phone_verification_required',
				$this->get_verification_required_message(),
				array( 'status' => 403 )
			);
		}

		return $result;
	}
}

new WC_Blacklist_Manager_Verifications_Verify_Phone();