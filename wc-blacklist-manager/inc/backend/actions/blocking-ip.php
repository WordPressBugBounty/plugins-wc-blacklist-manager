<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_IP_Prevention {

	public function __construct() {
		add_action( 'woocommerce_checkout_process', array( $this, 'check_customer_ip_against_blacklist' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'check_customer_ip_against_blacklist_blocks' ), 10, 2 );
		add_filter( 'registration_errors', array( $this, 'prevent_blocked_ip_registration' ), 10, 3 );
		add_filter( 'woocommerce_registration_errors', array( $this, 'prevent_blocked_ip_registration_woocommerce' ), 10, 3 );
		add_filter( 'preprocess_comment', array( $this, 'prevent_comment' ), 10, 1 );

		// Raw WooCommerce REST API orders.
		add_filter( 'rest_dispatch_request', array( $this, 'prevent_wc_rest_api_orders' ), 10, 4 );
	}

	/**
	 * ---------------------------------------------------------------------
	 * Shared helpers.
	 * ---------------------------------------------------------------------
	 */

	private function is_woocommerce_ready() {
		return class_exists( 'WooCommerce' );
	}

	private function is_ip_prevention_enabled() {
		return (bool) get_option( 'wc_blacklist_ip_enabled', 0 );
	}

	private function is_premium_active() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		return $settings_instance->is_premium_active();
	}

	private function get_blacklist_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_blacklist';
	}

	private function get_checkout_notice() {
		return get_option(
			'wc_blacklist_checkout_notice',
			__( 'Sorry! You are no longer allowed to shop with us. If you think it is a mistake, please contact support.', 'wc-blacklist-manager' )
		);
	}

	private function increment_option_counter( $option_name, $step = 1 ) {
		$current = (int) get_option( $option_name, 0 );
		update_option( $option_name, $current + (int) $step );
	}

	private function increment_block_ip_counters() {
		$this->increment_option_counter( 'wc_blacklist_sum_block_ip' );
		$this->increment_option_counter( 'wc_blacklist_sum_block_total' );
	}

	private function get_user_ip() {
		$user_ip = get_real_customer_ip();
		return ! empty( $user_ip ) ? (string) $user_ip : '';
	}

	private function get_ip_cache_key( $prefix, $ip ) {
		return $prefix . md5( $ip );
	}

	private function get_ip_match_result( $user_ip ) {
		global $wpdb;

		$table_name = $this->get_blacklist_table_name();

		if ( '' === $user_ip ) {
			return array(
				'is_blocked'   => false,
				'is_suspected' => false,
				'ip_value'     => '',
			);
		}

		$blocked_cache_key = $this->get_ip_cache_key( 'banned_ip_', $user_ip );
		$is_blocked        = wp_cache_get( $blocked_cache_key, 'wc_blacklist' );

		if ( false === $is_blocked ) {
			$is_blocked = ! empty(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1
						FROM {$table_name}
						WHERE ip_address = %s
						AND is_blocked = 1
						LIMIT 1",
						$user_ip
					)
				)
			);
			wp_cache_set( $blocked_cache_key, $is_blocked, 'wc_blacklist', HOUR_IN_SECONDS );
		}

		$suspect_cache_key = $this->get_ip_cache_key( 'suspect_ip_', $user_ip );
		$is_suspected      = wp_cache_get( $suspect_cache_key, 'wc_blacklist' );

		if ( false === $is_suspected ) {
			$is_suspected = ! empty(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1
						FROM {$table_name}
						WHERE ip_address = %s
						AND is_blocked = 0
						LIMIT 1",
						$user_ip
					)
				)
			);
			wp_cache_set( $suspect_cache_key, $is_suspected, 'wc_blacklist', HOUR_IN_SECONDS );
		}

		return array(
			'is_blocked'   => (bool) $is_blocked,
			'is_suspected' => (bool) $is_suspected,
			'ip_value'     => ( $is_blocked || $is_suspected ) ? $user_ip : '',
		);
	}

	private function log_ip_checkout_block_reason( $premium_active, $ip_value ) {
		if ( $premium_active && '' !== $ip_value ) {
			$reason_ip = 'blocked_ip_attempt: ' . $ip_value;
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( '', '', '', $reason_ip );
		}
	}

	private function log_ip_registration_block_reason( $premium_active, $ip_value ) {
		if ( $premium_active && '' !== $ip_value ) {
			$reason_ip = 'blocked_ip_attempt: ' . $ip_value;
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block( '', '', '', $reason_ip );
		}
	}

	private function log_ip_registration_suspect_reason( $premium_active, $ip_value ) {
		if ( $premium_active && '' !== $ip_value ) {
			$reason_ip = 'suspected_ip_attempt: ' . $ip_value;
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_suspect( '', '', '', $reason_ip );
		}
	}

	private function log_ip_comment_block_reason( $premium_active, $ip_value ) {
		if ( $premium_active && '' !== $ip_value ) {
			$reason_ip = 'blocked_ip_attempt: ' . $ip_value;
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_block( '', '', '', $reason_ip );
		}
	}

	private function log_ip_comment_suspect_reason( $premium_active, $ip_value ) {
		if ( $premium_active && '' !== $ip_value ) {
			$reason_ip = 'suspected_ip_attempt: ' . $ip_value;
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_suspect( '', '', '', $reason_ip );
		}
	}

	private function maybe_email_order_block_for_ip( $ip_value ) {
		if ( '' !== $ip_value ) {
			WC_Blacklist_Manager_Email::send_email_order_block( '', '', $ip_value );
		}
	}

	private function evaluate_ip_for_order_context( $user_ip, $premium_active, $send_email_notification = true ) {
		$match = $this->get_ip_match_result( $user_ip );

		if ( ! $match['is_blocked'] ) {
			return array(
				'is_blocked' => false,
				'ip_value'   => '',
				'reason'     => '',
			);
		}

		$this->increment_block_ip_counters();
		$this->log_ip_checkout_block_reason( $premium_active, $match['ip_value'] );

		if ( $send_email_notification ) {
			$this->maybe_email_order_block_for_ip( $match['ip_value'] );
		}

		return array(
			'is_blocked' => true,
			'ip_value'   => $match['ip_value'],
			'reason'     => 'blocked_ip_attempt: ' . $match['ip_value'],
		);
	}

	/**
	 * ---------------------------------------------------------------------
	 * Classic checkout.
	 * ---------------------------------------------------------------------
	 */

	public function check_customer_ip_against_blacklist() {
		if ( ! $this->is_woocommerce_ready() || ! $this->is_ip_prevention_enabled() || get_option( 'wc_blacklist_ip_action', 'none' ) !== 'prevent' ) {
			return;
		}

		$premium_active = $this->is_premium_active();
		$user_ip        = $this->get_user_ip();

		if ( '' === $user_ip ) {
			return;
		}

		$evaluation = $this->evaluate_ip_for_order_context( $user_ip, $premium_active, true );

		if ( ! $evaluation['is_blocked'] ) {
			return;
		}

		$checkout_notice = $this->get_checkout_notice();

		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) {
			throw new \Exception( esc_html( $checkout_notice ) );
		}

		if ( wp_doing_ajax() ) {
			wp_send_json(
				array(
					'result'   => 'failure',
					'messages' => wc_print_notices( true ),
					'refresh'  => true,
					'reload'   => false,
				)
			);
		}
	}

	/**
	 * ---------------------------------------------------------------------
	 * Blocks checkout.
	 * ---------------------------------------------------------------------
	 */

	public function check_customer_ip_against_blacklist_blocks( \WC_Order $order, $request ) {
		if ( ! $this->is_woocommerce_ready()
			|| ! $this->is_ip_prevention_enabled()
			|| 'prevent' !== get_option( 'wc_blacklist_ip_action', 'none' )
			|| ! $order instanceof \WC_Order
		) {
			return;
		}

		$premium_active = $this->is_premium_active();
		$user_ip        = $this->get_user_ip();

		if ( '' === $user_ip ) {
			return;
		}

		$evaluation = $this->evaluate_ip_for_order_context( $user_ip, $premium_active, true );

		if ( ! $evaluation['is_blocked'] ) {
			return;
		}

		throw new \Exception( esc_html( $this->get_checkout_notice() ) );
	}

	/**
	 * ---------------------------------------------------------------------
	 * Raw WooCommerce REST API orders.
	 * Covers:
	 * - POST /wc/v3/orders
	 * - PUT/PATCH /wc/v3/orders/{id}
	 * - POST /wc/v3/orders/batch
	 * ---------------------------------------------------------------------
	 */

	public function prevent_wc_rest_api_orders( $dispatch_result, $request, $route, $handler ) {
		if ( (int) get_option( 'wc_blacklist_enable_woo_rest_api', 0 ) !== 1 ) {
			return $dispatch_result;
		}

		if ( ! $this->is_woocommerce_ready() ) {
			return $dispatch_result;
		}

		if ( ! $this->is_ip_prevention_enabled() ) {
			return $dispatch_result;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $dispatch_result;
		}

		if ( ! $this->is_wc_rest_orders_request( $request ) ) {
			return $dispatch_result;
		}

		if ( 'prevent' !== get_option( 'wc_blacklist_ip_action', 'none' ) ) {
			return $dispatch_result;
		}

		$premium_active = $this->is_premium_active();

		// Batch endpoint: /wc/v3/orders/batch
		if ( preg_match( '#^/wc/v\d+/orders/batch$#', (string) $request->get_route() ) ) {
			$batch_groups = array(
				'create' => $request->get_param( 'create' ),
				'update' => $request->get_param( 'update' ),
			);

			foreach ( $batch_groups as $group_key => $group_items ) {
				if ( empty( $group_items ) || ! is_array( $group_items ) ) {
					continue;
				}

				foreach ( $group_items as $index => $payload ) {
					$payload     = is_array( $payload ) ? $payload : array();
					$evaluation  = $this->evaluate_rest_order_payload( $payload, $premium_active );

					if ( ! empty( $evaluation['is_blocked'] ) ) {
						return new WP_Error(
							'wc_blacklist_ip_blocked',
							$this->get_checkout_notice(),
							array(
								'status'  => 400,
								'details' => array(
									'group'   => $group_key,
									'index'   => $index,
									'reasons' => isset( $evaluation['reasons'] ) ? $evaluation['reasons'] : array(),
								),
							)
						);
					}
				}
			}

			return $dispatch_result;
		}

		// Single order endpoint.
		$payload    = $request->get_json_params();
		$payload    = is_array( $payload ) ? $payload : array();
		$evaluation = $this->evaluate_rest_order_payload( $payload, $premium_active );

		if ( ! empty( $evaluation['is_blocked'] ) ) {
			return new WP_Error(
				'wc_blacklist_ip_blocked',
				$this->get_checkout_notice(),
				array(
					'status'  => 400,
					'details' => array(
						'reasons' => isset( $evaluation['reasons'] ) ? $evaluation['reasons'] : array(),
					),
				)
			);
		}

		return $dispatch_result;
	}

	private function is_wc_rest_orders_request( WP_REST_Request $request ) {
		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );

		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return false;
		}

		if ( preg_match( '#^/wc/v\d+/orders(?:/\d+|/batch)?$#', $route ) ) {
			return true;
		}

		return false;
	}

	private function evaluate_rest_order_payload( array $payload, $premium_active = false ) {
		$user_ip = $this->get_user_ip();

		if ( '' === $user_ip ) {
			return array(
				'is_blocked' => false,
				'reasons'    => array(),
			);
		}

		$evaluation = $this->evaluate_ip_for_order_context( $user_ip, $premium_active, true );

		if ( ! $evaluation['is_blocked'] ) {
			return array(
				'is_blocked' => false,
				'reasons'    => array(),
			);
		}

		return array(
			'is_blocked' => true,
			'reasons'    => array( $evaluation['reason'] ),
		);
	}

	/**
	 * ---------------------------------------------------------------------
	 * Registration.
	 * ---------------------------------------------------------------------
	 */

	public function prevent_blocked_ip_registration( $errors, $sanitized_user_login, $user_email ) {
		return $this->handle_blocked_ip_registration( $errors );
	}

	public function prevent_blocked_ip_registration_woocommerce( $errors, $username, $email ) {
		if ( ! $this->is_woocommerce_ready() ) {
			return $errors;
		}

		return $this->handle_blocked_ip_registration( $errors );
	}

	private function handle_blocked_ip_registration( $errors ) {
		if ( ! $this->is_ip_prevention_enabled() || ! get_option( 'wc_blacklist_block_ip_registration', 0 ) ) {
			return $errors;
		}

		$premium_active = $this->is_premium_active();
		$user_ip        = $this->get_user_ip();

		if ( '' === $user_ip ) {
			$errors->add( 'ip_error', __( 'Error retrieving IP address.', 'wc-blacklist-manager' ) );
			return $errors;
		}

		$match    = $this->get_ip_match_result( $user_ip );
		$ip_value = $match['ip_value'];

		if ( $match['is_blocked'] ) {
			wc_blacklist_add_registration_notice( $errors );
			$this->increment_block_ip_counters();
			WC_Blacklist_Manager_Email::send_email_registration_block( '', '', $ip_value );
			$this->log_ip_registration_block_reason( $premium_active, $ip_value );
		} elseif ( $match['is_suspected'] ) {
			$this->increment_block_ip_counters();
			WC_Blacklist_Manager_Email::send_email_registration_suspect( '', '', $ip_value );
			$this->log_ip_registration_suspect_reason( $premium_active, $ip_value );
		}

		return $errors;
	}

	/**
	 * ---------------------------------------------------------------------
	 * Comments / reviews.
	 * ---------------------------------------------------------------------
	 */

	public function prevent_comment( $commentdata ) {
		$premium_active = $this->is_premium_active();

		if ( ! $premium_active || ! $this->is_ip_prevention_enabled() || get_option( 'wc_blacklist_block_ip_comment', 0 ) !== '1' ) {
			return $commentdata;
		}

		$user_ip = $this->get_user_ip();

		if ( '' === $user_ip ) {
			return $commentdata;
		}

		$match    = $this->get_ip_match_result( $user_ip );
		$ip_value = $match['ip_value'];

		if ( $match['is_blocked'] ) {
			$this->increment_block_ip_counters();
			WC_Blacklist_Manager_Email::send_email_comment_block( '', $user_ip );
			$this->log_ip_comment_block_reason( $premium_active, $ip_value );

			$notice_template = get_option(
				'wc_blacklist_comment_notice',
				__( 'Sorry! You are no longer allowed to submit a comment on our site. If you think it is a mistake, please contact support.', 'wc-blacklist-manager' )
			);

			$notice = wp_kses_post( $notice_template );

			wp_die(
				esc_html( $notice ),
				esc_html__( 'Comment Blocked', 'wc-blacklist-manager' ),
				array( 'response' => 403 )
			);
		} elseif ( $match['is_suspected'] ) {
			$this->increment_block_ip_counters();
			WC_Blacklist_Manager_Email::send_email_comment_suspect( '', $user_ip );
			$this->log_ip_comment_suspect_reason( $premium_active, $ip_value );
		}

		return $commentdata;
	}
}

new WC_Blacklist_Manager_IP_Prevention();