<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/checkout-verification-block-tree.php';

/**
 * Core-owned checkout verification coordinator contract v1.
 *
 * Channels are intentionally duck-typed so a newer Premium can retain its
 * Phase 1 fallback when it is loaded with an older Core that has no contract.
 */
final class WC_Blacklist_Manager_Checkout_Verification_Coordinator {

	const AJAX_ACTION      = 'wc_blacklist_checkout_verification';
	const NONCE_ACTION     = 'wc_blacklist_checkout_verification_nonce';
	const BLOCKS_NAMESPACE = 'wc-blacklist-manager-checkout-verification';
	const SETTING_OPTION   = 'wc_blacklist_checkout_verification_interface';

	private static $instance;
	private $channels = array();
	private $blocks_gate_hook = '';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_filter( 'render_block_data', array( $this, 'place_verification_block' ), 10, 3 );
		add_action( 'init', array( $this, 'register_verification_block' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic_checkout' ), 20 );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'persist_order_evidence' ), 10, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'cleanup_order_proof' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'cleanup_order_proof' ), 10, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'cleanup_order_proof' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'cleanup_order_proof' ), 10, 1 );
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_classic_root' ), 5 );
		add_filter( 'render_block_woocommerce/checkout', array( $this, 'render_blocks_root' ), 10, 2 );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_blocks_integration' ) );
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this, 'enqueue_blocks_component_registration' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'plugins_loaded', array( $this, 'register_blocks_gate' ), 100 );
	}

	public function place_verification_block( $parsed_block, $source_block, $parent_block ) {
		if ( is_admin() || null !== $parent_block || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $parsed_block;
		}

		return WC_Blacklist_Manager_Checkout_Verification_Block_Tree::transform( $parsed_block );
	}

	public function register_verification_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			WC_Blacklist_Manager_Checkout_Verification_Block_Tree::BLOCK_NAME,
			array(
				'parent'          => array( 'woocommerce/checkout-fields-block' ),
				'attributes'      => array(
					'placement' => array( 'type' => 'string' ),
					'profile'   => array( 'type' => 'string' ),
				),
				'render_callback' => array( $this, 'render_verification_block_placeholder' ),
			)
		);
	}

	public function render_verification_block_placeholder( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$data       = array(
			'data-block-name'       => WC_Blacklist_Manager_Checkout_Verification_Block_Tree::BLOCK_NAME,
			'data-yobm-placement'   => isset( $attributes['placement'] ) ? sanitize_key( $attributes['placement'] ) : '',
			'data-yobm-profile'     => isset( $attributes['profile'] ) ? sanitize_key( $attributes['profile'] ) : '',
		);

		$html = '<div';
		foreach ( $data as $name => $value ) {
			if ( '' !== $value ) {
				$html .= sprintf( ' %s="%s"', $name, esc_attr( $value ) );
			}
		}
		return $html . '></div>';
	}

	public static function sanitize_interface( $value ) {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'inline', 'popup_modal' ), true ) ? $value : 'inline';
	}

	public static function get_interface() {
		return self::sanitize_interface( get_option( self::SETTING_OPTION, 'inline' ) );
	}

	public function register_channel( $channel ) {
		$required_methods = array(
			'checkout_verification_channel_id',
			'checkout_verification_priority',
			'checkout_verification_state',
			'checkout_verification_issue',
			'checkout_verification_verify',
			'checkout_verification_resend',
		);

		if ( ! is_object( $channel ) ) {
			return new WP_Error( 'yobm_verification_invalid_channel', __( 'Invalid verification channel.', 'wc-blacklist-manager' ) );
		}

		foreach ( $required_methods as $method ) {
			if ( ! is_callable( array( $channel, $method ) ) ) {
				return new WP_Error( 'yobm_verification_invalid_channel', __( 'Invalid verification channel.', 'wc-blacklist-manager' ) );
			}
		}

		$channel_id = sanitize_key( (string) $channel->checkout_verification_channel_id() );
		if ( '' === $channel_id || isset( $this->channels[ $channel_id ] ) ) {
			return new WP_Error( 'yobm_verification_duplicate_channel', __( 'The verification channel is already registered.', 'wc-blacklist-manager' ) );
		}

		$this->channels[ $channel_id ] = $channel;
		return true;
	}

	public function registered_channel_ids() {
		return array_keys( $this->ordered_channels() );
	}

	private function ordered_channels() {
		$channels = $this->channels;
		uasort(
			$channels,
			function ( $left, $right ) {
				$left_priority  = absint( $left->checkout_verification_priority() );
				$right_priority = absint( $right->checkout_verification_priority() );

				if ( $left_priority === $right_priority ) {
					return strcmp( $left->checkout_verification_channel_id(), $right->checkout_verification_channel_id() );
				}

				return $left_priority < $right_priority ? -1 : 1;
			}
		);

		return $channels;
	}

	public function project_state( array $context ) {
		$projected      = array();
		$active_channel = '';
		$earlier_ready  = true;

		foreach ( $this->ordered_channels() as $channel_id => $channel ) {
			$state = $channel->checkout_verification_state( $context );
			$state = is_array( $state ) ? $state : array();

			$required = ! empty( $state['required'] );
			$verified = $required && ! empty( $state['verified'] );
			$locked   = $required && ! $verified && ! $earlier_ready;

			if ( $required && ! $verified && ! $locked && '' === $active_channel ) {
				$active_channel = $channel_id;
			}

			if ( $required && ! $verified ) {
				$earlier_ready = false;
			}

			$projected[] = array(
				'id'                  => $channel_id,
				'label'               => isset( $state['label'] ) ? sanitize_text_field( $state['label'] ) : ucfirst( $channel_id ),
				'required'            => $required,
				'verified'            => $verified,
				'locked'              => $locked,
				'status'              => isset( $state['status'] ) ? sanitize_key( $state['status'] ) : ( $verified ? 'verified' : 'required' ),
				'masked_destination'  => isset( $state['masked_destination'] ) ? sanitize_text_field( $state['masked_destination'] ) : '',
				'resend_available_at' => isset( $state['resend_available_at'] ) ? absint( $state['resend_available_at'] ) : 0,
				'message'             => isset( $state['message'] ) ? sanitize_text_field( $state['message'] ) : '',
				'state_revision'      => isset( $state['state_revision'] ) ? absint( $state['state_revision'] ) : 0,
				'generation'          => isset( $state['generation'] ) ? absint( $state['generation'] ) : 0,
				'challenge_id'        => isset( $state['challenge_id'] ) ? sanitize_text_field( $state['challenge_id'] ) : '',
				'identity_token'      => isset( $state['identity_token'] ) ? sanitize_text_field( $state['identity_token'] ) : '',
				'retry_after'         => isset( $state['retry_after'] ) ? absint( $state['retry_after'] ) : 0,
			);
		}

		$required_channels = array_values(
			array_filter(
				$projected,
				function ( $channel ) {
					return $channel['required'];
				}
			)
		);
		$current_step      = 0;
		foreach ( $required_channels as $index => $channel ) {
			if ( $channel['id'] === $active_channel ) {
				$current_step = $index + 1;
				break;
			}
		}
		if ( '' === $active_channel && ! empty( $required_channels ) ) {
			$current_step = count( $required_channels );
		}

		return array(
			'contract_version' => WC_BLACKLIST_MANAGER_CHECKOUT_VERIFICATION_CONTRACT_VERSION,
			'render_mode'      => self::get_interface(),
			'required'         => ! empty( $required_channels ),
			'ready'            => '' === $active_channel,
			'active_channel'   => $active_channel,
			'current_step'     => $current_step,
			'total_steps'       => count( $required_channels ),
			'required_progress' => array_map(
				function ( $channel ) use ( $active_channel ) {
					return array(
						'id'       => $channel['id'],
						'label'    => $channel['label'],
						'verified' => $channel['verified'],
						'active'   => $channel['id'] === $active_channel,
					);
				},
				$required_channels
			),
			'channels'         => $projected,
		);
	}

	public function execute_operation( $operation, $channel_id, array $context, $code = '' ) {
		$operation  = sanitize_key( (string) $operation );
		$channel_id = sanitize_key( (string) $channel_id );

		if ( 'status' === $operation ) {
			return array( 'state' => $this->project_state( $context ) );
		}

		if ( ! in_array( $operation, array( 'issue', 'verify', 'resend' ), true ) ) {
			return new WP_Error( 'yobm_verification_invalid_operation', __( 'Invalid verification operation.', 'wc-blacklist-manager' ) );
		}

		$channels = $this->ordered_channels();
		if ( ! isset( $channels[ $channel_id ] ) ) {
			return new WP_Error( 'yobm_verification_unavailable_channel', __( 'Verification channel is unavailable.', 'wc-blacklist-manager' ) );
		}

		$before = $this->project_state( $context );
		if ( $channel_id !== $before['active_channel'] ) {
			return new WP_Error( 'yobm_verification_locked_channel', __( 'Complete the active verification step first.', 'wc-blacklist-manager' ) );
		}

		$method = 'checkout_verification_' . $operation;
		$result = 'verify' === $operation
			? $channels[ $channel_id ]->{$method}( $context, sanitize_text_field( (string) $code ) )
			: $channels[ $channel_id ]->{$method}( $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$message = is_array( $result ) && isset( $result['message'] ) ? sanitize_text_field( $result['message'] ) : '';
		return array(
			'message' => $message,
			'state'   => $this->project_state( $context ),
		);
	}

	public function handle_ajax() {
		check_ajax_referer( self::NONCE_ACTION, 'security' );

		$operation  = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : 'status';
		$channel_id = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		$code       = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$context    = $this->context_from_request();
		$result     = $this->execute_operation( $operation, $channel_id, $context, $code );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
					'details' => $result->get_error_data(),
					'state'   => $this->project_state( $context ),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}

	public function context_from_request() {
		$context = array();
		$keys    = array(
			'billing_email', 'billing_phone', 'billing_dial_code', 'billing_country',
			'billing_first_name', 'billing_last_name', 'billing_address_1', 'billing_address_2',
			'billing_city', 'billing_state', 'billing_postcode', 'shipping_phone',
			'shipping_dial_code', 'shipping_country',
		);

		foreach ( $keys as $key ) {
			if ( isset( $_POST[ $key ] ) && ! is_array( $_POST[ $key ] ) ) {
				$value           = wp_unslash( $_POST[ $key ] );
				$context[ $key ] = 'billing_email' === $key ? sanitize_email( $value ) : sanitize_text_field( $value );
			}
		}

		if ( isset( $_POST['context'] ) && ! is_array( $_POST['context'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['context'] ), true );
			if ( is_array( $decoded ) ) {
				foreach ( $keys as $key ) {
					if ( isset( $decoded[ $key ] ) && is_scalar( $decoded[ $key ] ) ) {
						$context[ $key ] = 'billing_email' === $key ? sanitize_email( $decoded[ $key ] ) : sanitize_text_field( $decoded[ $key ] );
					}
				}
			}
		}

		$control_keys = array(
			'request_id'           => '_yobm_request_id',
			'expected_revision'     => '_yobm_expected_revision',
			'expected_generation'   => '_yobm_expected_generation',
			'expected_challenge_id' => '_yobm_expected_challenge_id',
		);
		foreach ( $control_keys as $request_key => $context_key ) {
			if ( isset( $_POST[ $request_key ] ) && ! is_array( $_POST[ $request_key ] ) ) {
				$value                   = sanitize_text_field( wp_unslash( $_POST[ $request_key ] ) );
				$context[ $context_key ] = $value;
			}
		}

		return $context;
	}

	private function order_value( $order, $getter, $meta_key = '' ) {
		if ( is_callable( array( $order, $getter ) ) ) {
			$value = $order->{$getter}();
			if ( '' !== trim( (string) $value ) || '' === $meta_key ) {
				return $value;
			}
		}
		if ( '' !== $meta_key && is_callable( array( $order, 'get_meta' ) ) ) {
			return $order->get_meta( $meta_key, true );
		}
		return '';
	}

	private function context_from_order( $order ) {
		if ( ! is_object( $order ) ) {
			return array();
		}

		return array(
			'billing_email'       => sanitize_email( $this->order_value( $order, 'get_billing_email' ) ),
			'billing_phone'       => sanitize_text_field( $this->order_value( $order, 'get_billing_phone' ) ),
			'billing_dial_code'   => sanitize_text_field( $this->order_value( $order, 'get_billing_dial_code', '_billing_dial_code' ) ),
			'billing_country'     => sanitize_text_field( $this->order_value( $order, 'get_billing_country' ) ),
			'billing_first_name'  => sanitize_text_field( $this->order_value( $order, 'get_billing_first_name' ) ),
			'billing_last_name'   => sanitize_text_field( $this->order_value( $order, 'get_billing_last_name' ) ),
			'billing_address_1'   => sanitize_text_field( $this->order_value( $order, 'get_billing_address_1' ) ),
			'billing_address_2'   => sanitize_text_field( $this->order_value( $order, 'get_billing_address_2' ) ),
			'billing_city'        => sanitize_text_field( $this->order_value( $order, 'get_billing_city' ) ),
			'billing_state'       => sanitize_text_field( $this->order_value( $order, 'get_billing_state' ) ),
			'billing_postcode'    => sanitize_text_field( $this->order_value( $order, 'get_billing_postcode' ) ),
			'shipping_phone'      => sanitize_text_field( $this->order_value( $order, 'get_shipping_phone', '_shipping_phone' ) ),
			'shipping_dial_code'  => sanitize_text_field( $this->order_value( $order, 'get_shipping_dial_code', '_shipping_dial_code' ) ),
			'shipping_country'    => sanitize_text_field( $this->order_value( $order, 'get_shipping_country' ) ),
		);
	}

	public function validate_classic_checkout() {
		$state = $this->project_state( $this->context_from_request() );
		if ( ! $state['ready'] ) {
			wc_add_notice( __( 'Complete checkout verification before placing the order.', 'wc-blacklist-manager' ), 'error' );
		}
	}

	public function register_blocks_gate() {
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '7.2', '>=' ) ) {
			$this->blocks_gate_hook = 'woocommerce_store_api_checkout_update_order_meta';
		} else {
			$this->blocks_gate_hook = 'woocommerce_blocks_checkout_update_order_meta';
		}

		add_action( $this->blocks_gate_hook, array( $this, 'validate_blocks_checkout' ), 5, 1 );
	}

	public function get_blocks_gate_hook() {
		return $this->blocks_gate_hook;
	}

	public function validate_blocks_checkout( $order ) {
		$state = $this->project_state( $this->context_from_order( $order ) );
		if ( $state['ready'] ) {
			$this->persist_order_evidence( $order );
			return;
		}

		$message = __( 'Complete checkout verification before placing the order.', 'wc-blacklist-manager' );
		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'yobm_checkout_verification_required', $message, 403 );
		}

		throw new Exception( $message );
	}

	public function persist_order_evidence( $order_or_id ) {
		$order = is_numeric( $order_or_id ) && function_exists( 'wc_get_order' ) ? wc_get_order( $order_or_id ) : $order_or_id;
		if ( ! is_object( $order ) || ! $this->project_state( $this->context_from_order( $order ) )['ready'] ) {
			return;
		}
		foreach ( $this->ordered_channels() as $channel ) {
			if ( is_callable( array( $channel, 'checkout_verification_persist_order_evidence' ) ) ) {
				$channel->checkout_verification_persist_order_evidence( $order );
			}
		}
	}

	public function cleanup_order_proof( $order_or_id ) {
		$order = is_numeric( $order_or_id ) && function_exists( 'wc_get_order' ) ? wc_get_order( $order_or_id ) : $order_or_id;
		if ( ! is_object( $order ) ) {
			return;
		}
		foreach ( $this->ordered_channels() as $channel ) {
			if ( is_callable( array( $channel, 'checkout_verification_cleanup_order_proof' ) ) ) {
				$channel->checkout_verification_cleanup_order_proof( $order );
			}
		}
	}

	private function root_markup( $context, $host ) {
		$mode = self::get_interface();
		return sprintf(
			'<div id="yobm-checkout-verification-%1$s-%2$s" class="yobm-checkout-verification-root" data-yobm-context="%1$s" data-yobm-host="%2$s" data-yobm-mode="%3$s"></div>',
			esc_attr( $context ),
			esc_attr( $host ),
			esc_attr( $mode )
		);
	}

	public function render_classic_root() {
		echo wp_kses_post( $this->root_markup( 'classic', 'classic' ) );
	}

	public function render_blocks_root( $block_content, $block ) {
		$root       = $this->root_markup( 'blocks', 'fallback' );
		$block_name = is_array( $block ) && isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		if ( 'woocommerce/checkout' === $block_name ) {
			return $root . str_replace( $root, '', $block_content );
		}

		if ( false !== strpos( $block_content, 'yobm-checkout-verification-root' ) ) {
			return $block_content;
		}

		return $root . $block_content;
	}

	public function register_blocks_integration( $integration_registry ) {
		if ( ! is_object( $integration_registry ) || ! is_callable( array( $integration_registry, 'register' ) ) ) {
			return;
		}

		$integration_file = __DIR__ . '/checkout-verification-blocks.php';
		if ( ! interface_exists( '\\Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface' ) || ! file_exists( $integration_file ) ) {
			return;
		}

		require_once $integration_file;
		if ( class_exists( 'WC_Blacklist_Manager_Checkout_Verification_Blocks_Integration' ) ) {
			$integration_registry->register( new WC_Blacklist_Manager_Checkout_Verification_Blocks_Integration() );
		}
	}

	public function enqueue_blocks_component_registration() {
		$integration_file = __DIR__ . '/checkout-verification-blocks.php';
		if ( ! interface_exists( '\\Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface' ) || ! file_exists( $integration_file ) ) {
			return;
		}

		require_once $integration_file;
		if ( class_exists( 'WC_Blacklist_Manager_Checkout_Verification_Blocks_Integration' ) ) {
			WC_Blacklist_Manager_Checkout_Verification_Blocks_Integration::register_script();
			wp_enqueue_script( WC_Blacklist_Manager_Checkout_Verification_Blocks_Integration::HANDLE );
		}
	}

	public function enqueue_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_style(
			'wc-blacklist-checkout-verification',
			plugins_url( '../../css/checkout-verification.css', __FILE__ ),
			array(),
			WC_BLACKLIST_MANAGER_VERSION
		);
		wp_enqueue_script(
			'wc-blacklist-checkout-verification-view',
			plugins_url( '../../js/checkout-verification-view.js', __FILE__ ),
			array(),
			WC_BLACKLIST_MANAGER_VERSION,
			true
		);
		wp_enqueue_script(
			'wc-blacklist-checkout-verification',
			plugins_url( '../../js/checkout-verification.js', __FILE__ ),
			array( 'jquery', 'wp-data', 'wc-blacklist-checkout-verification-view' ),
			WC_BLACKLIST_MANAGER_VERSION,
			true
		);
		wp_localize_script(
			'wc-blacklist-checkout-verification',
			'wcBlacklistCheckoutVerification',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => self::AJAX_ACTION,
				'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
				'namespace'  => self::BLOCKS_NAMESPACE,
				'mode'       => self::get_interface(),
				'labels'     => array(
					'title'       => __( 'Checkout verification', 'wc-blacklist-manager' ),
					'open'        => __( 'Verify checkout details', 'wc-blacklist-manager' ),
					'close'       => __( 'Close verification dialog', 'wc-blacklist-manager' ),
					'verify'      => __( 'Verify', 'wc-blacklist-manager' ),
					'resend'      => __( 'Resend code', 'wc-blacklist-manager' ),
					'resendIn'    => __( 'Resend available in %d seconds.', 'wc-blacklist-manager' ),
					'enterCode'   => __( 'Enter verification code', 'wc-blacklist-manager' ),
					'codeLabel'   => __( 'Verification code', 'wc-blacklist-manager' ),
					'step'        => __( 'Step %1$d of %2$d', 'wc-blacklist-manager' ),
					'complete'    => __( 'Verification complete.', 'wc-blacklist-manager' ),
					'locked'      => __( 'Complete the previous verification step first.', 'wc-blacklist-manager' ),
					'working'     => __( 'Please wait…', 'wc-blacklist-manager' ),
				),
			)
		);
	}
}

function wc_blacklist_manager_register_checkout_verification_channel( $channel ) {
	return WC_Blacklist_Manager_Checkout_Verification_Coordinator::instance()->register_channel( $channel );
}

function wc_blacklist_manager_checkout_verification_coordinator() {
	return WC_Blacklist_Manager_Checkout_Verification_Coordinator::instance();
}

WC_Blacklist_Manager_Checkout_Verification_Coordinator::instance();
