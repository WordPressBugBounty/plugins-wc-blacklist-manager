<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Temporary old-Premium/new-Core compatibility bridge.
 *
 * This class is loaded only by WC_Blacklist_Manager_Phone_Verification_Boundary
 * after the legacy Premium, license, provider, and capability predicates pass.
 */
class WC_Blacklist_Manager_Verifications_Verify_Phone {

	private $whitelist_table;
	private $blacklist_table;

	private $session_state_key = 'wc_blacklist_phone_verification_state';

	private $resend_cooldown_seconds;
	private $resend_limit;
	private $verification_expiration_seconds = 300;
	private $max_verification_attempts = 5;

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

		$coordinator_registered = function_exists( 'wc_blacklist_manager_register_checkout_verification_channel' );
		if ( $coordinator_registered ) {
			wc_blacklist_manager_register_checkout_verification_channel( $this );
		}

		if ( ! $coordinator_registered ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_verification_scripts' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_blocks_verification_scripts' ) );
			add_action( 'woocommerce_checkout_process', array( $this, 'phone_verification' ), 20 );
			add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'add_verified_phone_meta_to_order' ), 10, 1 );
			add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'add_verified_phone_meta_to_order' ), 10, 1 );
		}

		add_action( 'wp_ajax_verify_phone_code', array( $this, 'verify_phone_code' ) );
		add_action( 'wp_ajax_nopriv_verify_phone_code', array( $this, 'verify_phone_code' ) );

		add_action( 'wp_ajax_resend_phone_verification_code', array( $this, 'resend_verification_code' ) );
		add_action( 'wp_ajax_nopriv_resend_phone_verification_code', array( $this, 'resend_verification_code' ) );

		add_action( 'wp_ajax_send_phone_verification_code_blocks', array( $this, 'send_verification_code_blocks' ) );
		add_action( 'wp_ajax_nopriv_send_phone_verification_code_blocks', array( $this, 'send_verification_code_blocks' ) );


		add_action( 'wc_blacklist_manager_cleanup_verification_code', array( $this, 'cleanup_expired_code' ), 10, 2 );

		if ( ! $coordinator_registered ) {
			add_filter( 'rest_authentication_errors', array( $this, 'validate_blocks_checkout_request' ), 20 );
		}
		$this->debug_log( 'hooks_registered' );
	}

	private function get_request_value( $value ) {
		$value = wp_unslash( $value );

		if ( is_array( $value ) ) {
			$parts = array();

			array_walk_recursive(
				$value,
				function ( $item ) use ( &$parts ) {
					if ( is_scalar( $item ) || ( is_object( $item ) && method_exists( $item, '__toString' ) ) ) {
						$item = trim( (string) $item );

						if ( '' !== $item ) {
							$parts[] = $item;
						}
					}
				}
			);

			return trim( implode( ' ', $parts ) );
		}

		if ( is_scalar( $value ) || ( is_object( $value ) && method_exists( $value, '__toString' ) ) ) {
			return trim( (string) $value );
		}

		return '';
	}

	public function checkout_verification_channel_id() {
		return 'phone';
	}

	public function checkout_verification_priority() {
		return 20;
	}

	private function context_value( array $context, $key ) {
		return isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ? trim( (string) $context[ $key ] ) : '';
	}

	private function uses_otp_state_contract() {
		return defined( 'WC_BLACKLIST_MANAGER_OTP_STATE_CONTRACT_VERSION' )
			&& WC_BLACKLIST_MANAGER_OTP_STATE_CONTRACT_VERSION >= 1
			&& function_exists( 'wc_blacklist_manager_otp_state' );
	}

	private function otp_operation_args( array $context ) {
		return array(
			'request_id'           => $this->context_value( $context, '_yobm_request_id' ),
			'expected_revision'     => $this->context_value( $context, '_yobm_expected_revision' ),
			'expected_generation'   => $this->context_value( $context, '_yobm_expected_generation' ),
			'expected_challenge_id' => $this->context_value( $context, '_yobm_expected_challenge_id' ),
		);
	}

	private function maybe_migrate_legacy_state( $phone ) {
		$service = wc_blacklist_manager_otp_state();
		if ( ! $service->is_ready() ) {
			return;
		}
		$legacy = $this->get_verification_state();
		if ( empty( $legacy ) ) {
			return;
		}
		if ( empty( $legacy['phone'] ) || ! hash_equals( (string) $legacy['phone'], (string) $phone ) ) {
			$this->clear_verification_state();
			return;
		}
		$result = $service->import_legacy( 'phone', $phone, $legacy );
		if ( in_array( $service->legacy_import_disposition( $result ), array( 'persisted', 'terminal' ), true ) ) {
			$this->clear_verification_state();
		}
	}

	private function otp_projection( $phone ) {
		$service = wc_blacklist_manager_otp_state();
		if ( ! $service->is_ready() ) {
			$legacy = $this->get_verification_state();
			$valid  = ! empty( $legacy['verified'] ) && ! empty( $legacy['phone'] )
				&& hash_equals( (string) $legacy['phone'], (string) $phone ) && ! $this->is_state_expired( $legacy );
			return array( 'verified' => $valid, 'pending' => false, 'status' => $valid ? 'verified' : 'unavailable', 'revision' => 0, 'generation' => 0, 'challenge_id' => '', 'identity_token' => '', 'resend_available_at' => 0, 'retry_after' => 0, 'proof_id' => '' );
		}
		$this->maybe_migrate_legacy_state( $phone );
		return $service->project( 'phone', $phone );
	}

	private function transport_outcome( $result ) {
		if ( true === $result ) {
			return 'success';
		}
		if ( ! is_wp_error( $result ) ) {
			return 'uncertain';
		}

		$data = $result->get_error_data();
		if ( is_array( $data ) && array_key_exists( 'delivery_ambiguous', $data ) ) {
			return ! empty( $data['delivery_ambiguous'] ) ? 'uncertain' : 'failed';
		}

		$definitive = array( 'yobmp_sms_inactive_license', 'yobmp_sms_provider_mismatch', 'yobmp_sms_invalid_phone', 'yobmp_sms_missing_credentials', 'yobmp_sms_api_error' );
		return in_array( $result->get_error_code(), $definitive, true ) ? 'failed' : 'uncertain';
	}

	private function send_v2_verification_code( $phone, $resend, array $context ) {
		$provider = (string) get_option( 'yoohw_sms_service', '' );
		if ( ! WC_Blacklist_Manager_Phone_Verification_Boundary::is_supported_provider( $provider ) ) {
			return new WP_Error( 'sms_provider_unconfigured', $this->get_sms_send_failed_message() );
		}
		$premium_active = function_exists( 'wc_blacklist_manager_is_premium_available' ) && wc_blacklist_manager_is_premium_available();
		if ( ! $premium_active || ! class_exists( 'WC_Blacklist_Manager_Premium_Verifications_Service' ) ) {
			return new WP_Error( 'sms_service_unavailable', $this->get_sms_send_failed_message() );
		}
		$settings      = get_option( 'wc_blacklist_phone_verification', array( 'code_length' => 6 ) );
		$resend_limit  = max( 1, min( 10, (int) $this->resend_limit ) );
		$default_limit = max( 5, $resend_limit + 1 );
		$args = array_merge(
			$this->otp_operation_args( $context ),
			array(
				'resend'             => (bool) $resend,
				'code_length'        => isset( $settings['code_length'] ) ? (int) $settings['code_length'] : 6,
				'cooldown'           => max( 30, min( 3600, (int) $this->resend_cooldown_seconds ) ),
				'resend_limit'        => $resend_limit,
				'identity_rate_limit' => max( 1, (int) apply_filters( 'wc_blacklist_phone_verification_send_limit', $default_limit, $phone ) ),
				'ip_rate_limit'       => 20,
			)
		);
		$service     = wc_blacklist_manager_otp_state();
		$reservation = $service->reserve_dispatch( 'phone', $phone, $args );
		if ( is_wp_error( $reservation ) ) {
			return $reservation;
		}
		if ( empty( $reservation['dispatch'] ) ) {
			if ( ! empty( $reservation['idempotent'] ) && in_array( isset( $reservation['operation_result'] ) ? $reservation['operation_result'] : '', array( 'failed', 'uncertain' ), true ) ) {
				return new WP_Error( 'yobm_phone_verification_delivery_' . $reservation['operation_result'], $this->get_sms_send_failed_message() );
			}
			return true;
		}
		$result = 'twilio' === $provider
			? WC_Blacklist_Manager_Premium_Verifications_Service::send_verification_sms_twilio( $this->format_phone_for_sms( $phone ), $reservation['code'] )
			: WC_Blacklist_Manager_Premium_Verifications_Service::send_verification_sms_textmagic( $this->format_phone_for_sms( $phone ), $reservation['code'] );
		$outcome = $this->transport_outcome( $result );
		$service->finalize_dispatch( $reservation, $outcome );
		return true === $result ? true : ( is_wp_error( $result ) ? $result : new WP_Error( 'sms_send_failed', $this->get_sms_send_failed_message() ) );
	}

	private function resolve_phone_components( $billing_phone, $billing_dial_code, $billing_country, $shipping_phone, $shipping_dial_code, $shipping_country ) {
		return array(
			'phone'     => '' !== trim( (string) $billing_phone ) ? $billing_phone : $shipping_phone,
			'dial_code' => '' !== trim( (string) $billing_dial_code ) ? $billing_dial_code : $shipping_dial_code,
			'country'   => '' !== trim( (string) $billing_country ) ? $billing_country : $shipping_country,
		);
	}

	private function canonical_phone_from_context( array $context ) {
		$components = $this->resolve_phone_components(
			$this->context_value( $context, 'billing_phone' ),
			$this->context_value( $context, 'billing_dial_code' ),
			$this->context_value( $context, 'billing_country' ),
			$this->context_value( $context, 'shipping_phone' ),
			$this->context_value( $context, 'shipping_dial_code' ),
			$this->context_value( $context, 'shipping_country' )
		);

		return $this->build_canonical_phone( $components['phone'], $components['dial_code'], $components['country'] );
	}

	private function mask_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		return '' === $digits ? '' : '+' . str_repeat( '*', max( 4, strlen( $digits ) - 4 ) ) . substr( $digits, -4 );
	}

	public function checkout_verification_state( array $context ) {
		$phone = $this->canonical_phone_from_context( $context );
		if ( '' === $phone ) {
			return array( 'label' => __( 'Phone', 'wc-blacklist-manager' ), 'required' => false, 'verified' => false, 'status' => 'not_required', 'masked_destination' => '', 'message' => '' );
		}

		$required = $this->requires_phone_verification( $phone );
		if ( $this->uses_otp_state_contract() ) {
			$otp      = $this->otp_projection( $phone );
			$verified = $required && is_array( $otp ) && ! empty( $otp['verified'] );
			$pending  = $required && is_array( $otp ) && ! empty( $otp['pending'] );
			return array(
				'label'               => __( 'Phone', 'wc-blacklist-manager' ),
				'required'            => $required,
				'verified'            => $verified,
				'status'              => $verified ? 'verified' : ( $pending ? ( 'sent' === $otp['status'] ? 'challenge_sent' : $otp['status'] ) : ( $required ? ( isset( $otp['status'] ) ? $otp['status'] : 'required' ) : 'not_required' ) ),
				'masked_destination'  => $this->mask_phone( $phone ),
				'resend_available_at' => isset( $otp['resend_available_at'] ) ? absint( $otp['resend_available_at'] ) : 0,
				'message'             => $required ? $this->get_verification_required_message() : '',
				'state_revision'      => isset( $otp['revision'] ) ? absint( $otp['revision'] ) : 0,
				'generation'          => isset( $otp['generation'] ) ? absint( $otp['generation'] ) : 0,
				'challenge_id'        => isset( $otp['challenge_id'] ) ? $otp['challenge_id'] : '',
				'identity_token'      => isset( $otp['identity_token'] ) ? $otp['identity_token'] : '',
				'retry_after'         => isset( $otp['retry_after'] ) ? absint( $otp['retry_after'] ) : 0,
			);
		}

		$this->clear_verification_state_if_phone_mismatch( $phone );
		$verified = $required && $this->is_phone_verified_for_checkout( $phone );
		$state    = $this->get_verification_state();
		$pending  = $required && ! $verified && ! empty( $state['code'] ) && ! $this->is_state_expired( $state );

		return array(
			'label'               => __( 'Phone', 'wc-blacklist-manager' ),
			'required'            => $required,
			'verified'            => $verified,
			'status'              => $verified ? 'verified' : ( $pending ? 'challenge_sent' : ( $required ? 'required' : 'not_required' ) ),
			'masked_destination'  => $this->mask_phone( $phone ),
			'resend_available_at' => isset( $state['resend_available_at'] ) ? absint( $state['resend_available_at'] ) : 0,
			'message'             => $required ? $this->get_verification_required_message() : '',
		);
	}

	public function checkout_verification_issue( array $context ) {
		$phone = $this->canonical_phone_from_context( $context );
		if ( '' === $phone || ! $this->requires_phone_verification( $phone ) ) {
			return new WP_Error( 'yobm_phone_verification_not_required', __( 'Phone verification is not required.', 'wc-blacklist-manager' ) );
		}
		$result = $this->send_verification_code( $phone, false, $context );
		return is_wp_error( $result ) ? $result : array( 'message' => __( 'A verification code has been sent to your phone.', 'wc-blacklist-manager' ) );
	}

	public function checkout_verification_resend( array $context ) {
		$phone = $this->canonical_phone_from_context( $context );
		if ( '' === $phone ) {
			return new WP_Error( 'yobm_phone_verification_missing_phone', __( 'Unable to resend the verification code. Phone number not found.', 'wc-blacklist-manager' ) );
		}
		if ( $this->uses_otp_state_contract() ) {
			$result = $this->send_v2_verification_code( $phone, true, $context );
			return is_wp_error( $result ) ? $result : array( 'message' => __( 'A new code has been sent to your phone.', 'wc-blacklist-manager' ) );
		}

		$state = $this->get_verification_state();
		if ( '' === $phone ) {
			return new WP_Error( 'yobm_phone_verification_missing_phone', __( 'Unable to resend the verification code. Phone number not found.', 'wc-blacklist-manager' ) );
		}
		if ( ! empty( $state['phone'] ) && $state['phone'] !== $phone ) {
			$this->clear_verification_state();
			$state = array();
		}

		$resend_count = ! empty( $state['resend_count'] ) ? absint( $state['resend_count'] ) : 0;
		if ( $resend_count >= $this->resend_limit ) {
			return new WP_Error( 'yobm_phone_verification_resend_limited', __( 'You have reached the resend limit. Please contact support.', 'wc-blacklist-manager' ) );
		}
		if ( ! empty( $state ) && ! $this->can_resend_code( $state ) ) {
			$remaining = max( 1, absint( $state['resend_available_at'] ) - time() );
			return new WP_Error( 'yobm_phone_verification_resend_cooldown', sprintf( __( 'Please wait %d seconds before requesting a new code.', 'wc-blacklist-manager' ), $remaining ) );
		}

		$result = $this->send_verification_code( $phone, true );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'yobm_phone_verification_send_failed', $this->get_sms_send_failed_message() );
		}
		$updated_state                 = $this->get_verification_state();
		$updated_state['resend_count'] = $resend_count + 1;
		$this->set_verification_state( $updated_state );
		return array( 'message' => __( 'A new code has been sent to your phone.', 'wc-blacklist-manager' ) );
	}

	public function checkout_verification_verify( array $context, $submitted_code ) {
		if ( $this->uses_otp_state_contract() ) {
			$submitted_phone = $this->canonical_phone_from_context( $context );
			$result = wc_blacklist_manager_otp_state()->verify( 'phone', $submitted_phone, $submitted_code, $this->otp_operation_args( $context ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! empty( $result['transitioned'] ) ) {
				$this->record_successful_phone_transition( $context, $submitted_phone, $result );
			}
			return array( 'message' => __( 'Your phone number has been successfully verified!', 'wc-blacklist-manager' ) );
		}

		$ip_address      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$attempts        = (int) get_transient( 'verify_phone_attempts_' . md5( $ip_address ) );
		$submitted_code  = sanitize_text_field( (string) $submitted_code );
		$submitted_phone = $this->canonical_phone_from_context( $context );

		if ( $attempts >= 5 ) {
			return new WP_Error( 'yobm_phone_verification_attempt_limited', __( 'Too many attempts. Please try again later.', 'wc-blacklist-manager' ) );
		}
		set_transient( 'verify_phone_attempts_' . md5( $ip_address ), $attempts + 1, HOUR_IN_SECONDS );
		if ( '' === $submitted_code || '' === $submitted_phone ) {
			return new WP_Error( 'yobm_phone_verification_missing_data', __( 'Missing verification data. Please try again.', 'wc-blacklist-manager' ) );
		}
		$state = $this->get_verification_state();
		if ( empty( $state['phone'] ) || empty( $state['code'] ) || empty( $state['sent_at'] ) ) {
			return new WP_Error( 'yobm_phone_verification_missing_challenge', __( 'No verification code was found. Please request a new one.', 'wc-blacklist-manager' ) );
		}
		if ( $state['phone'] !== $submitted_phone ) {
			$this->clear_verification_state();
			return new WP_Error( 'yobm_phone_verification_identity_changed', __( 'The phone number has changed. Please request a new verification code.', 'wc-blacklist-manager' ) );
		}
		if ( $this->is_state_expired( $state ) ) {
			$this->clear_verification_state();
			return new WP_Error( 'yobm_phone_verification_expired', __( 'Code expired. Please request a new one.', 'wc-blacklist-manager' ) );
		}

		$state_attempts = isset( $state['verify_attempts'] ) ? absint( $state['verify_attempts'] ) : 0;
		if ( $state_attempts >= $this->max_verification_attempts ) {
			$this->clear_verification_state();
			return new WP_Error( 'yobm_phone_verification_attempt_limited', __( 'Too many failed attempts. Please request a new verification code.', 'wc-blacklist-manager' ) );
		}
		if ( ! preg_match( '/^\d{6,10}$/', $submitted_code ) || ! hash_equals( (string) $state['code'], $submitted_code ) ) {
			$state['verify_attempts'] = ++$state_attempts;
			if ( $state_attempts >= $this->max_verification_attempts ) {
				$this->clear_verification_state();
				return new WP_Error( 'yobm_phone_verification_attempt_limited', __( 'Too many failed attempts. Please request a new verification code.', 'wc-blacklist-manager' ) );
			}
			$this->set_verification_state( $state );
			return new WP_Error( 'yobm_phone_verification_invalid_code', __( 'Invalid code. Please try again.', 'wc-blacklist-manager' ) );
		}

		$billing_details = array(
			'phone'          => $submitted_phone,
			'verified_phone' => 1,
		);
		$this->add_billing_details_to_whitelist( $billing_details );
		if ( 'suspect' === get_option( 'wc_blacklist_phone_verification_action' ) ) {
			$this->mark_phone_as_verified_in_blacklist( $submitted_phone );
		}
		$state['verified']        = true;
		$state['verified_phone']  = $submitted_phone;
		$state['code']            = '';
		$state['verify_attempts'] = 0;
		$this->set_verification_state( $state );
		return array( 'message' => __( 'Your phone number has been successfully verified!', 'wc-blacklist-manager' ) );
	}

	private function record_successful_phone_transition( array $context, $submitted_phone, array $result ) {
		$suspect_resolution = 'suspect' === get_option( 'wc_blacklist_phone_verification_action' )
			&& $this->mark_phone_as_verified_in_blacklist( $submitted_phone );
		$proof_id    = isset( $result['proof_id'] ) ? (string) $result['proof_id'] : '';
		$verified_at = isset( $result['state']['proof_verified_at'] ) ? absint( $result['state']['proof_verified_at'] ) : time();
		if ( function_exists( 'wc_blacklist_manager_evidence_trust_record_otp' ) ) {
			wc_blacklist_manager_evidence_trust_record_otp( 'phone', $submitted_phone, $proof_id, $verified_at, $suspect_resolution );
		}
		$billing_details = array(
			'phone'          => $submitted_phone,
			'verified_phone' => 1,
		);
		$this->add_billing_details_to_whitelist( $billing_details );
		if ( class_exists( 'WC_Blacklist_Manager_Premium_Activity_Logs_Insert' ) ) {
			global $wpdb;
			$correlation = function_exists( 'wc_blacklist_manager_evidence_trust' )
				? wc_blacklist_manager_evidence_trust()->activity_correlation( 'phone', $submitted_phone )
				: '';
			$view_json = wp_json_encode(
				array(
					'evidence_version' => 1,
					'category'         => 'verification_audit',
					'source'           => 'otp_transition',
					'channel'          => 'phone',
					'correlation'      => $correlation,
				)
			);
			$wpdb->insert(
				$wpdb->prefix . 'wc_blacklist_detection_log',
				array(
					'timestamp' => current_time( 'mysql' ),
					'type'      => 'human',
					'source'    => 'woo_checkout',
					'action'    => 'verify',
					'details'   => 'verified_phone_attempt: v1:' . ( '' !== $correlation ? $correlation : 'unavailable' ),
					'view'      => is_string( $view_json ) ? $view_json : '',
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	public function initialize_session() {
		if ( function_exists( 'WC' ) && class_exists( 'WC_Session' ) && WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	private function is_blocks_checkout_context() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! function_exists( 'has_block' ) ) {
			return false;
		}

		$post = get_post();
		if ( $post instanceof WP_Post && has_block( 'woocommerce/checkout', $post ) ) {
			return true;
		}

		if ( function_exists( 'wc_get_page_id' ) ) {
			$checkout_page_id = wc_get_page_id( 'checkout' );
			if ( $checkout_page_id > 0 && has_block( 'woocommerce/checkout', $checkout_page_id ) ) {
				return true;
			}
		}

		return false;
	}

	public function enqueue_verification_scripts() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( $this->is_blocks_checkout_context() ) {
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
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( ! $this->is_blocks_checkout_context() ) {
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
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$phone = $this->get_canonical_phone_from_request();

		if ( empty( $phone ) ) {
			$this->debug_log( 'classic_missing_phone' );
			wc_add_notice( __( 'Please enter your phone number for verification.', 'wc-blacklist-manager' ), 'error' );
			return;
		}

		if ( ! $this->requires_phone_verification( $phone ) ) {
			$this->clear_verification_state_if_phone_mismatch( $phone );
			$this->debug_log( 'classic_not_required', $phone );
			return;
		}

		if ( $this->is_phone_verified_for_checkout( $phone ) ) {
			$this->debug_log( 'classic_already_verified', $phone );
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
				$this->debug_log( 'classic_send_failed', $phone, array( 'error' => $send_result->get_error_code() ) );
				wc_add_notice( $send_result->get_error_message(), 'error' );
				return;
			}

			$this->debug_log( 'classic_code_sent', $phone );
		}

		if ( empty( wc_get_notices( 'error' ) ) ) {
			wc_add_notice(
				'<span class="yobm-phone-verification-error">' . esc_html( $this->get_verification_required_message() ) . '</span>',
				'error'
			);
			$this->debug_log( 'classic_required_notice_added', $phone );
		}
	}

	private function get_verification_required_message() {
		return __( 'Please verify your phone number before proceeding with the checkout.', 'wc-blacklist-manager' );
	}

	private function get_sms_send_failed_message() {
		return __( 'To complete the checkout, we need to verify your phone number, but we were unable to send the verification code. This may be because the phone number is incorrect or it\'s a landline, which can\'t receive text messages. Please check the number and try again. If the problem persists, contact customer support for help.', 'wc-blacklist-manager' );
	}

	private function debug_log( $message, $phone = '', $data = array() ) {
		if ( ! function_exists( 'wc_blacklist_manager_debug_log' ) ) {
			return;
		}

		if ( '' !== $phone ) {
			$data['phone_hash'] = md5( (string) $phone );
		}

		wc_blacklist_manager_debug_log( 'phone_verification', $message, $data );
	}

	private function requires_phone_verification( $phone ) {
		if ( empty( $phone ) ) {
			return false;
		}

		$verification_action = get_option( 'wc_blacklist_phone_verification_action' );

		if ( 'all' === $verification_action ) {
			if ( function_exists( 'wc_blacklist_manager_evidence_trust_resolve_policy' ) ) {
				$policy = wc_blacklist_manager_evidence_trust_resolve_policy( 'phone', $phone, 'repeat_verification' );
				return empty( $policy['exempt'] );
			}
			return ! $this->is_phone_in_whitelist( $phone );
		}

		if ( 'suspect' === $verification_action ) {
			$active_suspect = $this->is_phone_in_blacklist( $phone );
			if ( ! $active_suspect || ! function_exists( 'wc_blacklist_manager_evidence_trust_resolve_policy' ) ) {
				return $active_suspect;
			}
			$policy = wc_blacklist_manager_evidence_trust_resolve_policy( 'phone', $phone, 'suspect_resolution' );
			return empty( $policy['exempt'] );
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

		return $this->resolve_phone_components(
			$billing_phone,
			$billing_dial_code,
			$billing_country,
			$shipping_phone,
			$shipping_dial_code,
			$shipping_country
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
		if ( $this->uses_otp_state_contract() ) {
			$projection = $this->otp_projection( $phone );
			return is_array( $projection ) && ! empty( $projection['verified'] );
		}

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

	private function get_request_ip_for_rate_limit() {
		if ( function_exists( 'get_real_customer_ip' ) ) {
			$ip = get_real_customer_ip();
			if ( '' !== $ip ) {
				return $ip;
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	private function check_send_rate_limit( $phone ) {
		$default_limit = max( 5, absint( $this->resend_limit ) + 1 );
		$limit         = (int) apply_filters( 'wc_blacklist_phone_verification_send_limit', $default_limit, $phone );
		$limit         = max( 1, $limit );
		$key           = 'yobm_phone_verification_send_' . md5( (string) $phone . '|' . $this->get_request_ip_for_rate_limit() );
		$count         = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'yobm_phone_verification_rate_limited',
				__( 'Too many verification code requests. Please wait before trying again.', 'wc-blacklist-manager' )
			);
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
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

	private function send_verification_code( $phone, $force = false, array $context = array() ) {
		if ( $this->uses_otp_state_contract() ) {
			return $this->send_v2_verification_code( $phone, $force, $context );
		}

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

		$rate_limit = $this->check_send_rate_limit( $phone );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$provider = (string) get_option( 'yoohw_sms_service', '' );
		if ( ! WC_Blacklist_Manager_Phone_Verification_Boundary::is_supported_provider( $provider ) ) {
			$this->clear_verification_state();
			return new WP_Error( 'sms_provider_unconfigured', $this->get_sms_send_failed_message() );
		}

		$premium_active = function_exists( 'wc_blacklist_manager_is_premium_available' )
			&& wc_blacklist_manager_is_premium_available();

		if ( ! $premium_active || ! class_exists( 'WC_Blacklist_Manager_Premium_Verifications_Service' ) ) {
			$this->clear_verification_state();
			return new WP_Error( 'sms_service_unavailable', $this->get_sms_send_failed_message() );
		}

		$verification_settings = get_option(
			'wc_blacklist_phone_verification',
			array(
				'code_length' => 6,
			)
		);

		$code_length       = max( 6, min( 10, (int) $verification_settings['code_length'] ) );
		$verification_code = (string) wp_rand( pow( 10, $code_length - 1 ), pow( 10, $code_length ) - 1 );
		$timestamp         = time();
		$resend_count      = ! empty( $state['resend_count'] ) ? absint( $state['resend_count'] ) : 0;

		$new_state = array(
			'phone'               => $phone,
			'code'                => $verification_code,
			'sent_at'             => $timestamp,
			'verified'            => false,
			'verified_phone'      => '',
			'verify_attempts'     => 0,
			'resend_available_at' => $timestamp + $this->resend_cooldown_seconds,
			'resend_count'        => $resend_count,
		);

		$this->set_verification_state( $new_state );

		if ( 'twilio' === $provider ) {
			$result = WC_Blacklist_Manager_Premium_Verifications_Service::send_verification_sms_twilio( $this->format_phone_for_sms( $phone ), $verification_code );
		} elseif ( 'textmagic' === $provider ) {
			$result = WC_Blacklist_Manager_Premium_Verifications_Service::send_verification_sms_textmagic( $this->format_phone_for_sms( $phone ), $verification_code );
		} else {
			$result = false;
		}

		if ( true !== $result ) {
			$this->clear_verification_state();

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new WP_Error( 'sms_send_failed', $this->get_sms_send_failed_message() );
		}

		$this->maybe_schedule_cleanup_event( $timestamp, $phone );
		return true;
	}

	public function verify_phone_code() {
		check_ajax_referer( 'phone_verification_nonce', 'security' );
		if ( function_exists( 'wc_blacklist_manager_checkout_verification_coordinator' ) ) {
			$context = wc_blacklist_manager_checkout_verification_coordinator()->context_from_request();
			$code    = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
			$result  = wc_blacklist_manager_checkout_verification_coordinator()->execute_operation( 'verify', 'phone', $context, $code );
			$this->send_legacy_json_result( $result );
			return;
		}

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

		$state_attempts = isset( $state['verify_attempts'] ) ? absint( $state['verify_attempts'] ) : 0;

		if ( $state_attempts >= $this->max_verification_attempts ) {
			$this->clear_verification_state();

			wp_send_json_error(
				array(
					'message' => __( 'Too many failed attempts. Please request a new verification code.', 'wc-blacklist-manager' ),
				)
			);
		}

		if ( ! preg_match( '/^\d{6,10}$/', $submitted_code ) || ! hash_equals( (string) $state['code'], (string) $submitted_code ) ) {
			$state_attempts++;
			$state['verify_attempts'] = $state_attempts;

			if ( $state_attempts >= $this->max_verification_attempts ) {
				$this->clear_verification_state();

				wp_send_json_error(
					array(
						'message' => __( 'Too many failed attempts. Please request a new verification code.', 'wc-blacklist-manager' ),
					)
				);
			}

			$this->set_verification_state( $state );

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
			'email'          => isset( $_POST['billing_email'] ) ? sanitize_email( $this->get_request_value( $_POST['billing_email'] ) ) : '',
			'phone'          => $submitted_phone,
			'verified_phone' => 1,
		);

		$this->add_billing_details_to_whitelist( $billing_details );

		if ( 'suspect' === get_option( 'wc_blacklist_phone_verification_action' ) ) {
			$this->mark_phone_as_verified_in_blacklist( $submitted_phone );
		}

		$state['verified']        = true;
		$state['verified_phone']  = $submitted_phone;
		$state['code']            = '';
		$state['verify_attempts'] = 0;
		$this->set_verification_state( $state );

		$this->record_phone_activity( $billing_details, $submitted_phone );

		wp_send_json_success(
			array(
				'message' => __( 'Your phone number has been successfully verified!', 'wc-blacklist-manager' ),
			)
		);
	}

	private function add_billing_details_to_whitelist( $billing_details ) {
		global $wpdb;

		$phone = isset( $billing_details['phone'] ) ? $billing_details['phone'] : '';
		if ( empty( $phone ) ) {
			return;
		}

		$normalized_phone = function_exists( 'yobm_normalize_phone' )
			? yobm_normalize_phone( $phone )
			: preg_replace( '/\D+/', '', (string) $phone );

		if ( empty( $normalized_phone ) ) {
			return;
		}

		$existing_phone_entry = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->whitelist_table} WHERE phone = %s LIMIT 1",
				$normalized_phone
			)
		);

		if ( $existing_phone_entry ) {
			$wpdb->update(
				$this->whitelist_table,
				array( 'verified_phone' => 1 ),
				array( 'phone' => $normalized_phone )
			);
		} else {
			$wpdb->insert(
				$this->whitelist_table,
				array( 'phone' => $normalized_phone, 'verified_phone' => 1 ),
				array( '%s', '%d' )
			);
		}
	}

	private function mark_phone_as_verified_in_blacklist( $phone ) {
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

		return false !== $updated && 0 < $updated;
	}

	public function add_verified_phone_meta_to_order( $order_or_id ) {
		$this->persist_verified_phone_meta_to_order( $order_or_id, true );
	}

	private function order_phone_value( $order, $getter, $meta_key = '' ) {
		if ( is_callable( array( $order, $getter ) ) ) {
			$value = $order->{$getter}();
			if ( '' !== trim( (string) $value ) || '' === $meta_key ) {
				return $value;
			}
		}
		return '' !== $meta_key && is_callable( array( $order, 'get_meta' ) ) ? $order->get_meta( $meta_key, true ) : '';
	}

	private function canonical_phone_from_order( $order ) {
		$components = $this->resolve_phone_components(
			$this->order_phone_value( $order, 'get_billing_phone' ),
			$this->order_phone_value( $order, 'get_billing_dial_code', '_billing_dial_code' ),
			$this->order_phone_value( $order, 'get_billing_country' ),
			$this->order_phone_value( $order, 'get_shipping_phone', '_shipping_phone' ),
			$this->order_phone_value( $order, 'get_shipping_dial_code', '_shipping_dial_code' ),
			$this->order_phone_value( $order, 'get_shipping_country' )
		);

		return $this->build_canonical_phone( $components['phone'], $components['dial_code'], $components['country'] );
	}

	private function persist_verified_phone_meta_to_order( $order_or_id, $clear_state ) {
		$order = is_numeric( $order_or_id ) ? wc_get_order( $order_or_id ) : $order_or_id;

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$order_phone = $this->canonical_phone_from_order( $order );

		if ( empty( $order_phone ) ) {
			return;
		}

		if ( $this->is_phone_verified_for_checkout( $order_phone ) ) {
			$order->update_meta_data( '_verified_phone', 1 );
			if ( $this->uses_otp_state_contract() ) {
				$projection = $this->otp_projection( $order_phone );
				if ( is_array( $projection ) && ! empty( $projection['proof_id'] ) ) {
					$order->update_meta_data( '_wc_blacklist_manager_phone_proof_id', $projection['proof_id'] );
					if ( function_exists( 'wc_blacklist_manager_evidence_trust_order_companion' ) ) {
						$companion = wc_blacklist_manager_evidence_trust_order_companion(
							'phone',
							$order_phone,
							$projection['proof_id'],
							isset( $projection['proof_verified_at'] ) ? $projection['proof_verified_at'] : 0
						);
						if ( ! empty( $companion ) ) {
							$order->update_meta_data( '_wc_blacklist_manager_phone_evidence_v1', $companion );
						}
					}
				}
			}
			$order->save();

			if ( $clear_state && ! $this->uses_otp_state_contract() ) {
				$this->clear_verification_state();
			}
		}
	}

	public function checkout_verification_persist_order_evidence( $order ) {
		$this->persist_verified_phone_meta_to_order( $order, false );
	}

	public function checkout_verification_cleanup_order_proof( $order ) {
		if (
			! is_object( $order )
			|| ! is_callable( array( $order, 'get_meta' ) )
			|| ! $order->get_meta( '_verified_phone', true )
		) {
			return;
		}
		$order_phone = $this->canonical_phone_from_order( $order );
		if ( $this->uses_otp_state_contract() ) {
			$proof_id = (string) $order->get_meta( '_wc_blacklist_manager_phone_proof_id', true );
			if ( '' !== $order_phone && '' !== $proof_id ) {
				wc_blacklist_manager_otp_state()->cleanup_proof( 'phone', $order_phone, $proof_id );
			}
			return;
		}

		if ( '' !== $order_phone && $this->is_phone_verified_for_checkout( $order_phone ) ) {
			$this->clear_verification_state();
		}
	}

	public function resend_verification_code() {
		check_ajax_referer( 'phone_verification_nonce', 'security' );
		if ( function_exists( 'wc_blacklist_manager_checkout_verification_coordinator' ) ) {
			$context = wc_blacklist_manager_checkout_verification_coordinator()->context_from_request();
			$result  = wc_blacklist_manager_checkout_verification_coordinator()->execute_operation( 'resend', 'phone', $context );
			$this->send_legacy_json_result( $result );
			return;
		}

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
					),
					429
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
		if ( function_exists( 'wc_blacklist_manager_checkout_verification_coordinator' ) ) {
			$context = wc_blacklist_manager_checkout_verification_coordinator()->context_from_request();
			$result  = wc_blacklist_manager_checkout_verification_coordinator()->execute_operation( 'issue', 'phone', $context );
			$this->send_legacy_json_result( $result );
			return;
		}

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
					),
					429
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

	private function send_legacy_json_result( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'  => isset( $result['message'] ) ? $result['message'] : '',
				'required' => isset( $result['state']['required'] ) ? (bool) $result['state']['required'] : true,
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

	private function build_activity_log_view( array $billing_details, string $verified_type, string $verified_value ) : array {
		$request_ip = $this->get_request_ip();

		return array(
			'email'          => isset( $billing_details['email'] ) ? sanitize_email( $billing_details['email'] ) : '',
			'phone'          => isset( $billing_details['phone'] ) ? sanitize_text_field( (string) $billing_details['phone'] ) : '',
			'ip_address'     => $request_ip,
			'ip_hash'        => '' !== $request_ip ? $this->hash_value( $request_ip ) : '',
			'verified_type'  => sanitize_key( $verified_type ),
			'verified_value' => sanitize_text_field( $verified_value ),
			'billing'        => $billing_details,
			'request'        => array(
				'ip'      => $request_ip,
				'ip_hash' => '' !== $request_ip ? $this->hash_value( $request_ip ) : '',
				'method'  => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				'uri'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			),
		);
	}

	private function get_request_ip() : string {
		if ( function_exists( 'get_real_customer_ip' ) ) {
			$ip = (string) get_real_customer_ip();
		} else {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		}

		return sanitize_text_field( $ip );
	}

	private function hash_value( string $value ) : string {
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}
}
