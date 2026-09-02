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
	private $max_verification_attempts = 5;

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

		$coordinator_registered = function_exists( 'wc_blacklist_manager_register_checkout_verification_channel' );
		if ( $coordinator_registered ) {
			wc_blacklist_manager_register_checkout_verification_channel( $this );
		}

		if ( ! $coordinator_registered ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_verification_scripts' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_blocks_verification_scripts' ) );
			add_action( 'woocommerce_checkout_process', array( $this, 'email_verification' ), 20 );
			add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'add_verified_email_meta_to_order' ), 10, 1 );
			add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'add_verified_email_meta_to_order' ), 10, 1 );
		}

		add_action( 'wp_ajax_verify_email_code', array( $this, 'verify_email_code' ) );
		add_action( 'wp_ajax_nopriv_verify_email_code', array( $this, 'verify_email_code' ) );

		add_action( 'wp_ajax_resend_verification_code', array( $this, 'resend_verification_code' ) );
		add_action( 'wp_ajax_nopriv_resend_verification_code', array( $this, 'resend_verification_code' ) );

		add_action( 'wp_ajax_send_verification_code_blocks', array( $this, 'send_verification_code_blocks' ) );
		add_action( 'wp_ajax_nopriv_send_verification_code_blocks', array( $this, 'send_verification_code_blocks' ) );

		add_action( 'wc_blacklist_manager_cleanup_verification_code', array( $this, 'cleanup_expired_code' ), 10, 2 );

		if ( ! $coordinator_registered ) {
			add_filter( 'rest_authentication_errors', array( $this, 'validate_blocks_checkout_request' ), 20 );
			add_filter( 'rest_request_before_callbacks', array( $this, 'validate_blocks_checkout_rest_request' ), 20, 3 );
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
		return 'email';
	}

	public function checkout_verification_priority() {
		return 10;
	}

	private function context_value( array $context, $key ) {
		if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
			return trim( (string) $context[ $key ] );
		}

		return '';
	}

	private function uses_otp_state_contract() {
		return defined( 'WC_BLACKLIST_MANAGER_OTP_STATE_CONTRACT_VERSION' )
			&& WC_BLACKLIST_MANAGER_OTP_STATE_CONTRACT_VERSION >= 1
			&& function_exists( 'wc_blacklist_manager_otp_state' );
	}

	private function otp_operation_args( array $context ) {
		return array(
			'request_id'            => $this->context_value( $context, '_yobm_request_id' ),
			'expected_revision'      => $this->context_value( $context, '_yobm_expected_revision' ),
			'expected_generation'    => $this->context_value( $context, '_yobm_expected_generation' ),
			'expected_challenge_id'  => $this->context_value( $context, '_yobm_expected_challenge_id' ),
		);
	}

	private function maybe_migrate_legacy_state( $email ) {
		$service = wc_blacklist_manager_otp_state();
		if ( ! $service->is_ready() ) {
			return;
		}
		$legacy = $this->get_verification_state();
		if ( empty( $legacy ) ) {
			return;
		}
		if ( empty( $legacy['email'] ) || ! hash_equals( (string) $legacy['email'], (string) $email ) ) {
			$this->clear_verification_state();
			return;
		}
		$result = $service->import_legacy( 'email', $email, $legacy );
		if ( in_array( $service->legacy_import_disposition( $result ), array( 'persisted', 'terminal' ), true ) ) {
			$this->clear_verification_state();
		}
	}

	private function otp_projection( $email ) {
		$service = wc_blacklist_manager_otp_state();
		if ( ! $service->is_ready() ) {
			$legacy = $this->get_verification_state();
			$valid  = ! empty( $legacy['verified'] ) && ! empty( $legacy['email'] )
				&& hash_equals( (string) $legacy['email'], (string) $email ) && ! $this->is_state_expired( $legacy );
			return array( 'verified' => $valid, 'pending' => false, 'status' => $valid ? 'verified' : 'unavailable', 'revision' => 0, 'generation' => 0, 'challenge_id' => '', 'identity_token' => '', 'resend_available_at' => 0, 'retry_after' => 0, 'proof_id' => '' );
		}
		$this->maybe_migrate_legacy_state( $email );
		return $service->project( 'email', $email );
	}

	private function send_v2_verification_code( $email, $resend, array $context ) {
		$service = wc_blacklist_manager_otp_state();
		$args    = array_merge(
			$this->otp_operation_args( $context ),
			array(
				'resend'              => (bool) $resend,
				'code_length'         => 6,
				'cooldown'            => max( 30, min( 3600, (int) $this->resend_cooldown_seconds ) ),
				'identity_rate_limit'  => max( 1, (int) apply_filters( 'wc_blacklist_email_verification_send_limit', 5, $email ) ),
				'ip_rate_limit'        => 20,
			)
		);
		$reservation = $service->reserve_dispatch( 'email', $email, $args );
		if ( is_wp_error( $reservation ) ) {
			return $reservation;
		}
		if ( empty( $reservation['dispatch'] ) ) {
			if ( ! empty( $reservation['idempotent'] ) && in_array( isset( $reservation['operation_result'] ) ? $reservation['operation_result'] : '', array( 'failed', 'uncertain' ), true ) ) {
				return new WP_Error( 'yobm_email_verification_delivery_' . $reservation['operation_result'], __( 'Unable to confirm delivery of the verification code. Please use Resend after the cooldown.', 'wc-blacklist-manager' ) );
			}
			return true;
		}
		$result = $this->send_verification_email( $email, $reservation['code'], $context );
		$outcome = 'success';
		if ( is_wp_error( $result ) ) {
			$data    = $result->get_error_data();
			$outcome = is_array( $data ) && array_key_exists( 'delivery_ambiguous', $data ) && false === $data['delivery_ambiguous'] ? 'failed' : 'uncertain';
		} elseif ( true !== $result ) {
			$outcome = 'uncertain';
		}
		$service->finalize_dispatch( $reservation, $outcome );
		return $result;
	}

	private function canonical_email_from_context( array $context ) {
		return sanitize_email( $this->context_value( $context, 'billing_email' ) );
	}

	private function mask_email( $email ) {
		$parts = explode( '@', (string) $email, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}

		$local = $parts[0];
		return substr( $local, 0, 1 ) . str_repeat( '*', max( 2, strlen( $local ) - 1 ) ) . '@' . $parts[1];
	}

	public function checkout_verification_state( array $context ) {
		$email = $this->canonical_email_from_context( $context );
		if ( '' === $email ) {
			return array(
				'label'              => __( 'Email', 'wc-blacklist-manager' ),
				'required'           => false,
				'verified'           => false,
				'status'             => 'not_required',
				'masked_destination' => '',
				'message'            => '',
			);
		}

		$required = $this->requires_email_verification( $email );
		if ( $this->uses_otp_state_contract() ) {
			$otp      = $this->otp_projection( $email );
			$verified = $required && is_array( $otp ) && ! empty( $otp['verified'] );
			$pending  = $required && is_array( $otp ) && ! empty( $otp['pending'] );
			return array(
				'label'               => __( 'Email', 'wc-blacklist-manager' ),
				'required'            => $required,
				'verified'            => $verified,
				'status'              => $verified ? 'verified' : ( $pending ? ( 'sent' === $otp['status'] ? 'challenge_sent' : $otp['status'] ) : ( $required ? ( isset( $otp['status'] ) ? $otp['status'] : 'required' ) : 'not_required' ) ),
				'masked_destination'  => $this->mask_email( $email ),
				'resend_available_at' => isset( $otp['resend_available_at'] ) ? absint( $otp['resend_available_at'] ) : 0,
				'message'             => $required ? $this->get_verification_required_message() : '',
				'state_revision'      => isset( $otp['revision'] ) ? absint( $otp['revision'] ) : 0,
				'generation'          => isset( $otp['generation'] ) ? absint( $otp['generation'] ) : 0,
				'challenge_id'        => isset( $otp['challenge_id'] ) ? $otp['challenge_id'] : '',
				'identity_token'      => isset( $otp['identity_token'] ) ? $otp['identity_token'] : '',
				'retry_after'         => isset( $otp['retry_after'] ) ? absint( $otp['retry_after'] ) : 0,
			);
		}

		$this->clear_verification_state_if_email_mismatch( $email );
		$verified = $required && $this->is_email_verified_for_checkout( $email );
		$state    = $this->get_verification_state();
		$pending  = $required && ! $verified && ! empty( $state['code'] ) && ! $this->is_state_expired( $state );

		return array(
			'label'               => __( 'Email', 'wc-blacklist-manager' ),
			'required'            => $required,
			'verified'            => $verified,
			'status'              => $verified ? 'verified' : ( $pending ? 'challenge_sent' : ( $required ? 'required' : 'not_required' ) ),
			'masked_destination'  => $this->mask_email( $email ),
			'resend_available_at' => isset( $state['resend_available_at'] ) ? absint( $state['resend_available_at'] ) : 0,
			'message'             => $required ? $this->get_verification_required_message() : '',
		);
	}

	public function checkout_verification_issue( array $context ) {
		$email = $this->canonical_email_from_context( $context );
		if ( '' === $email || ! $this->requires_email_verification( $email ) ) {
			return new WP_Error( 'yobm_email_verification_not_required', __( 'Email verification is not required.', 'wc-blacklist-manager' ) );
		}

		$result = $this->send_verification_code( $email, false, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'message' => __( 'A verification code has been sent to your email.', 'wc-blacklist-manager' ) );
	}

	public function checkout_verification_resend( array $context ) {
		$email = $this->canonical_email_from_context( $context );
		if ( $this->uses_otp_state_contract() ) {
			if ( '' === $email ) {
				return new WP_Error( 'yobm_email_verification_missing_email', __( 'Unable to resend the verification code. Email not found.', 'wc-blacklist-manager' ) );
			}
			$result = $this->send_v2_verification_code( $email, true, $context );
			return is_wp_error( $result ) ? $result : array( 'message' => __( 'A new code has been sent to your email.', 'wc-blacklist-manager' ) );
		}
		$state = $this->get_verification_state();

		if ( '' === $email ) {
			return new WP_Error( 'yobm_email_verification_missing_email', __( 'Unable to resend the verification code. Email not found.', 'wc-blacklist-manager' ) );
		}

		if ( ! empty( $state['email'] ) && $state['email'] !== $email ) {
			$this->clear_verification_state();
			$state = array();
		}

		if ( ! empty( $state ) && ! $this->can_resend_code( $state ) ) {
			$remaining = max( 1, absint( $state['resend_available_at'] ) - time() );
			return new WP_Error(
				'yobm_email_verification_resend_cooldown',
				sprintf(
					/* translators: %d: seconds remaining */
					__( 'Please wait %d seconds before requesting a new code.', 'wc-blacklist-manager' ),
					$remaining
				)
			);
		}

		$result = $this->send_verification_code( $email, true, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'message' => __( 'A new code has been sent to your email.', 'wc-blacklist-manager' ) );
	}

	public function checkout_verification_verify( array $context, $submitted_code ) {
		$submitted_email = $this->canonical_email_from_context( $context );
		if ( $this->uses_otp_state_contract() ) {
			$result = wc_blacklist_manager_otp_state()->verify( 'email', $submitted_email, $submitted_code, $this->otp_operation_args( $context ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! empty( $result['transitioned'] ) ) {
				$this->record_successful_email_transition( $context, $submitted_email, $result );
			}
			return array( 'message' => __( 'Your email has been successfully verified!', 'wc-blacklist-manager' ) );
		}

		$ip_address      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$attempts        = (int) get_transient( 'verify_email_attempts_' . md5( $ip_address ) );
		$submitted_code  = sanitize_text_field( (string) $submitted_code );

		if ( $attempts >= 5 ) {
			return new WP_Error( 'yobm_email_verification_attempt_limited', __( 'Too many attempts. Please try again later.', 'wc-blacklist-manager' ) );
		}
		set_transient( 'verify_email_attempts_' . md5( $ip_address ), $attempts + 1, HOUR_IN_SECONDS );

		if ( '' === $submitted_code || '' === $submitted_email ) {
			return new WP_Error( 'yobm_email_verification_missing_data', __( 'Missing verification data. Please try again.', 'wc-blacklist-manager' ) );
		}

		$state = $this->get_verification_state();
		if ( empty( $state['email'] ) || empty( $state['code'] ) || empty( $state['sent_at'] ) ) {
			return new WP_Error( 'yobm_email_verification_missing_challenge', __( 'No verification code was found. Please request a new one.', 'wc-blacklist-manager' ) );
		}
		if ( $state['email'] !== $submitted_email ) {
			$this->clear_verification_state();
			return new WP_Error( 'yobm_email_verification_identity_changed', __( 'The email address has changed. Please request a new verification code.', 'wc-blacklist-manager' ) );
		}
		if ( $this->is_state_expired( $state ) ) {
			$this->clear_verification_state();
			return new WP_Error( 'yobm_email_verification_expired', __( 'Code expired. Please request a new one.', 'wc-blacklist-manager' ) );
		}

		$state_attempts = isset( $state['verify_attempts'] ) ? absint( $state['verify_attempts'] ) : 0;
		if ( $state_attempts >= $this->max_verification_attempts ) {
			$this->clear_verification_state();
			return new WP_Error( 'yobm_email_verification_attempt_limited', __( 'Too many failed attempts. Please request a new verification code.', 'wc-blacklist-manager' ) );
		}
		if ( ! preg_match( '/^\d{6}$/', $submitted_code ) || ! hash_equals( (string) $state['code'], $submitted_code ) ) {
			$state['verify_attempts'] = ++$state_attempts;
			if ( $state_attempts >= $this->max_verification_attempts ) {
				$this->clear_verification_state();
				return new WP_Error( 'yobm_email_verification_attempt_limited', __( 'Too many failed attempts. Please request a new verification code.', 'wc-blacklist-manager' ) );
			}
			$this->set_verification_state( $state );
			return new WP_Error( 'yobm_email_verification_invalid_code', __( 'Invalid code. Please try again.', 'wc-blacklist-manager' ) );
		}

		$billing_details = array(
			'email'          => $submitted_email,
			'verified_email' => 1,
		);
		$this->add_billing_details_to_whitelist( $billing_details );
		if ( 'suspect' === get_option( 'wc_blacklist_email_verification_action' ) ) {
			$this->mark_email_as_verified_in_blacklist( $submitted_email );
		}

		$state['verified']        = true;
		$state['verified_email']  = $submitted_email;
		$state['code']            = '';
		$state['verify_attempts'] = 0;
		$this->set_verification_state( $state );
		$this->record_email_activity( $billing_details, $submitted_email );

		return array( 'message' => __( 'Your email has been successfully verified!', 'wc-blacklist-manager' ) );
	}

	private function record_successful_email_transition( array $context, $submitted_email, array $result ) {
		$suspect_resolution = 'suspect' === get_option( 'wc_blacklist_email_verification_action' )
			&& $this->mark_email_as_verified_in_blacklist( $submitted_email );
		$proof_id    = isset( $result['proof_id'] ) ? (string) $result['proof_id'] : '';
		$verified_at = isset( $result['state']['proof_verified_at'] ) ? absint( $result['state']['proof_verified_at'] ) : time();
		if ( function_exists( 'wc_blacklist_manager_evidence_trust_record_otp' ) ) {
			wc_blacklist_manager_evidence_trust_record_otp( 'email', $submitted_email, $proof_id, $verified_at, $suspect_resolution );
		}
		$billing_details = array(
			'email'          => $submitted_email,
			'verified_email' => 1,
		);
		$this->add_billing_details_to_whitelist( $billing_details );
		$this->record_email_activity( $billing_details, $submitted_email );
	}

	private function record_email_activity( array $billing_details, $submitted_email ) {
		$premium_active = function_exists( 'wc_blacklist_manager_is_premium_available' )
			&& wc_blacklist_manager_is_premium_available();
		if ( ! $premium_active || ! class_exists( 'WC_Blacklist_Manager_Premium_Activity_Logs_Insert' ) ) {
			return;
		}

		global $wpdb;
		$correlation = function_exists( 'wc_blacklist_manager_evidence_trust' )
			? wc_blacklist_manager_evidence_trust()->activity_correlation( 'email', $submitted_email )
			: '';
		$view_json = wp_json_encode(
			array(
				'evidence_version' => 1,
				'category'         => 'verification_audit',
				'source'           => 'otp_transition',
				'channel'          => 'email',
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
				'details'   => 'verified_email_attempt: v1:' . ( '' !== $correlation ? $correlation : 'unavailable' ),
				'view'      => is_string( $view_json ) ? $view_json : '',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function set_verifications_strings() {
		$this->default_email_subject = __( 'Verify your email address on {site_name}', 'wc-blacklist-manager' );
		$this->default_email_heading = __( 'Verify your email address', 'wc-blacklist-manager' );
		$this->default_email_message = __( 'Hi {first_name} {last_name},<br><br>To complete your checkout process, please verify your email address by entering the following code:<br><br><strong>{code}</strong><br><br>If you did not request this, please ignore this email.<br><br>Thank you.', 'wc-blacklist-manager' );
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
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( ! $this->is_blocks_checkout_context() ) {
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

		$email = isset( $_POST['billing_email'] ) ? sanitize_email( $this->get_request_value( $_POST['billing_email'] ) ) : '';

		if ( empty( $email ) ) {
			$this->debug_log( 'classic_missing_email' );
			return;
		}

		if ( ! $this->requires_email_verification( $email ) ) {
			$this->clear_verification_state_if_email_mismatch( $email );
			$this->debug_log( 'classic_not_required', $email );
			return;
		}

		if ( $this->is_email_verified_for_checkout( $email ) ) {
			$this->debug_log( 'classic_already_verified', $email );
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
			$send_result = $this->send_verification_code( $email );

			if ( is_wp_error( $send_result ) ) {
				$this->debug_log( 'classic_send_failed', $email, array( 'error' => $send_result->get_error_code() ) );
				wc_add_notice( $send_result->get_error_message(), 'error' );
				return;
			}

			$this->debug_log( 'classic_code_sent', $email );
		}

		if ( empty( wc_get_notices( 'error' ) ) ) {
			wc_add_notice(
				'<span class="yobm-email-verification-error">' . esc_html( $this->get_verification_required_message() ) . '</span>',
				'error'
			);
			$this->debug_log( 'classic_required_notice_added', $email );
		}
	}

	private function get_verification_required_message() {
		return __( 'Please verify your email before placing the order.', 'wc-blacklist-manager' );
	}

	private function debug_log( $message, $email = '', $data = array() ) {
		if ( ! function_exists( 'wc_blacklist_manager_debug_log' ) ) {
			return;
		}

		if ( '' !== $email ) {
			$data['email_hash'] = md5( strtolower( (string) $email ) );
		}

		wc_blacklist_manager_debug_log( 'email_verification', $message, $data );
	}

	private function requires_email_verification( $email ) {
		if ( empty( $email ) ) {
			return false;
		}

		$verification_action = get_option( 'wc_blacklist_email_verification_action' );

		if ( 'all' === $verification_action ) {
			if ( function_exists( 'wc_blacklist_manager_evidence_trust_resolve_policy' ) ) {
				$policy = wc_blacklist_manager_evidence_trust_resolve_policy( 'email', $email, 'repeat_verification' );
				return empty( $policy['exempt'] );
			}
			return ! $this->is_email_in_whitelist( $email );
		}

		if ( 'suspect' === $verification_action ) {
			$active_suspect = $this->is_email_in_blacklist( $email );
			if ( ! $active_suspect || ! function_exists( 'wc_blacklist_manager_evidence_trust_resolve_policy' ) ) {
				return $active_suspect;
			}
			$policy = wc_blacklist_manager_evidence_trust_resolve_policy( 'email', $email, 'suspect_resolution' );
			return empty( $policy['exempt'] );
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
		if ( $this->uses_otp_state_contract() ) {
			$projection = $this->otp_projection( $email );
			return is_array( $projection ) && ! empty( $projection['verified'] );
		}

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

	private function get_request_ip_for_rate_limit() {
		if ( function_exists( 'get_real_customer_ip' ) ) {
			$ip = get_real_customer_ip();
			if ( '' !== $ip ) {
				return $ip;
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	private function check_send_rate_limit( $email ) {
		$limit = (int) apply_filters( 'wc_blacklist_email_verification_send_limit', 5, $email );
		$limit = max( 1, $limit );
		$key   = 'yobm_email_verification_send_' . md5( strtolower( (string) $email ) . '|' . $this->get_request_ip_for_rate_limit() );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'yobm_email_verification_rate_limited',
				__( 'Too many verification code requests. Please wait before trying again.', 'wc-blacklist-manager' )
			);
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
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

	private function send_verification_code( $email, $force = false, array $context = array() ) {
		if ( $this->uses_otp_state_contract() ) {
			return $this->send_v2_verification_code( $email, $force, $context );
		}

		$state = $this->get_verification_state();

		if (
			! $force &&
			! empty( $state ) &&
			! empty( $state['email'] ) &&
			$state['email'] === $email &&
			! $this->is_state_expired( $state ) &&
			! empty( $state['code'] )
		) {
			return true;
		}

		$rate_limit = $this->check_send_rate_limit( $email );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$verification_code = (string) wp_rand( 100000, 999999 );
		$timestamp         = time();

		$new_state = array(
			'email'               => $email,
			'code'                => $verification_code,
			'sent_at'             => $timestamp,
			'verified'            => false,
			'verified_email'      => '',
			'verify_attempts'     => 0,
			'resend_available_at' => $timestamp + $this->resend_cooldown_seconds,
		);

		$this->set_verification_state( $new_state );
		$this->maybe_schedule_cleanup_event( $timestamp, $email );
		$send_result = $this->send_verification_email( $email, $verification_code, $context );

		if ( is_wp_error( $send_result ) ) {
			$this->clear_verification_state();
			return $send_result;
		}

		return true;
	}

	private function send_verification_email( $email, $verification_code, array $context = array() ) {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return new WP_Error(
				'yobm_email_verification_mailer_unavailable',
				__( 'Unable to send the verification code. Please try again later.', 'wc-blacklist-manager' ),
				array( 'delivery_ambiguous' => false )
			);
		}

		$premium_active = function_exists( 'wc_blacklist_manager_is_premium_available' )
			&& wc_blacklist_manager_is_premium_available();

		$email_settings = get_option( 'wc_blacklist_email_verification', array() );

		$first_name = $this->context_value( $context, 'billing_first_name' );
		$last_name  = $this->context_value( $context, 'billing_last_name' );
		if ( '' === $first_name && isset( $_POST['billing_first_name'] ) ) {
			$first_name = sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) );
		}
		if ( '' === $last_name && isset( $_POST['billing_last_name'] ) ) {
			$last_name = sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) );
		}

		$mailer = WC()->mailer();

		if ( ! $mailer ) {
			return new WP_Error(
				'yobm_email_verification_mailer_unavailable',
				__( 'Unable to send the verification code. Please try again later.', 'wc-blacklist-manager' ),
				array( 'delivery_ambiguous' => false )
			);
		}

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

		$sent = $mailer->send(
			$email,
			$subject,
			$styled,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		if ( ! $sent ) {
			return new WP_Error(
				'yobm_email_verification_send_failed',
				__( 'Unable to send the verification code. Please try again later.', 'wc-blacklist-manager' ),
				array( 'delivery_ambiguous' => true )
			);
		}

		return true;
	}

	public function verify_email_code() {
		check_ajax_referer( 'email_verification_nonce', 'security' );
		$context = wc_blacklist_manager_checkout_verification_coordinator()->context_from_request();
		$code    = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$result  = wc_blacklist_manager_checkout_verification_coordinator()->execute_operation( 'verify', 'email', $context, $code );
		$this->send_legacy_json_result( $result );
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

		$existing_email_entry = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->whitelist_table} WHERE email = %s LIMIT 1",
				$normalized_email
			)
		);

		if ( $existing_email_entry ) {
			$wpdb->update(
				$this->whitelist_table,
				array( 'verified_email' => 1 ),
				array( 'email' => $normalized_email )
			);
		} else {
			$wpdb->insert(
				$this->whitelist_table,
				array( 'email' => $normalized_email, 'verified_email' => 1 ),
				array( '%s', '%d' )
			);
		}
	}

	public function add_verified_email_meta_to_order( $order_or_id ) {
		$this->persist_verified_email_meta_to_order( $order_or_id, true );
	}

	private function persist_verified_email_meta_to_order( $order_or_id, $clear_state ) {
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
			if ( $this->uses_otp_state_contract() ) {
				$projection = $this->otp_projection( $order_email );
				if ( is_array( $projection ) && ! empty( $projection['proof_id'] ) ) {
					$order->update_meta_data( '_wc_blacklist_manager_email_proof_id', $projection['proof_id'] );
					if ( function_exists( 'wc_blacklist_manager_evidence_trust_order_companion' ) ) {
						$companion = wc_blacklist_manager_evidence_trust_order_companion(
							'email',
							$order_email,
							$projection['proof_id'],
							isset( $projection['proof_verified_at'] ) ? $projection['proof_verified_at'] : 0
						);
						if ( ! empty( $companion ) ) {
							$order->update_meta_data( '_wc_blacklist_manager_email_evidence_v1', $companion );
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
		$this->persist_verified_email_meta_to_order( $order, false );
	}

	public function checkout_verification_cleanup_order_proof( $order ) {
		if (
			! is_object( $order )
			|| ! is_callable( array( $order, 'get_meta' ) )
			|| ! $order->get_meta( '_verified_email', true )
		) {
			return;
		}
		$email = is_callable( array( $order, 'get_billing_email' ) ) ? sanitize_email( $order->get_billing_email() ) : '';
		if ( $this->uses_otp_state_contract() ) {
			$proof_id = (string) $order->get_meta( '_wc_blacklist_manager_email_proof_id', true );
			if ( '' !== $email && '' !== $proof_id ) {
				wc_blacklist_manager_otp_state()->cleanup_proof( 'email', $email, $proof_id );
			}
			return;
		}
		if ( '' !== $email && $this->is_email_verified_for_checkout( $email ) ) {
			$this->clear_verification_state();
		}
	}

	private function mark_email_as_verified_in_blacklist( $email ) {
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

		return false !== $updated && 0 < $updated;
	}

	public function resend_verification_code() {
		check_ajax_referer( 'email_verification_nonce', 'security' );
		$context = wc_blacklist_manager_checkout_verification_coordinator()->context_from_request();
		$result  = wc_blacklist_manager_checkout_verification_coordinator()->execute_operation( 'resend', 'email', $context );
		$this->send_legacy_json_result( $result );
	}

	public function send_verification_code_blocks() {
		check_ajax_referer( 'email_verification_nonce', 'security' );
		$context = wc_blacklist_manager_checkout_verification_coordinator()->context_from_request();
		$result  = wc_blacklist_manager_checkout_verification_coordinator()->execute_operation( 'issue', 'email', $context );
		$this->send_legacy_json_result( $result );
	}

	private function send_legacy_json_result( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$data = array(
			'message'  => isset( $result['message'] ) ? $result['message'] : '',
			'required' => isset( $result['state']['required'] ) ? (bool) $result['state']['required'] : true,
		);
		wp_send_json_success( $data );
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

	private function is_blocks_checkout_route( $route ) {
		return is_string( $route ) && (bool) preg_match( '#/wc/store(?:/v\d+)?/checkout#', $route );
	}

	private function validate_blocks_checkout_payload( $result, $request_body ) {
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
			$this->debug_log( 'blocks_missing_email' );
			return $result;
		}

		if ( ! $this->requires_email_verification( $billing_email ) ) {
			$this->debug_log( 'blocks_not_required', $billing_email );
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
			$this->debug_log( 'blocks_client_not_verified', $billing_email );
			return new WP_Error(
				'yobm_email_verification_required',
				$this->get_verification_required_message(),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->is_email_verified_for_checkout( $billing_email ) ) {
			$this->debug_log( 'blocks_server_not_verified', $billing_email );
			return new WP_Error(
				'yobm_email_verification_required',
				$this->get_verification_required_message(),
				array( 'status' => 403 )
			);
		}

		return $result;
	}

	public function validate_blocks_checkout_rest_request( $response, $handler, $request ) {
		if ( is_wp_error( $response ) ) {
			return $response;
	}

		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		if ( 'POST' !== $request->get_method() ) {
			return $response;
		}

		if ( ! $this->is_blocks_checkout_route( $request->get_route() ) ) {
			return $response;
		}

		return $this->validate_blocks_checkout_payload( $response, $request->get_json_params() );
	}

	public function validate_blocks_checkout_request( $result ) {
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return $result;
		}

		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';

		if ( ! $this->is_blocks_checkout_route( $route ) ) {
			return $result;
		}

		$request_body = json_decode( \WP_REST_Server::get_raw_data(), true );

		return $this->validate_blocks_checkout_payload( $result, $request_body );
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

new WC_Blacklist_Manager_Verifications_Verify_Email();
