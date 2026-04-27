<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_Email_Domain_Prevention {

	public function __construct() {
		add_action( 'woocommerce_checkout_process', array( $this, 'check_customer_email_domain_against_blacklist' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'check_customer_email_domain_against_blacklist_blocks' ), 10, 2 );
		add_filter( 'registration_errors', array( $this, 'prevent_domain_registration' ), 10, 3 );
		add_filter( 'woocommerce_registration_errors', array( $this, 'prevent_domain_registration_woocommerce' ), 10, 3 );
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

	private function is_domain_prevention_enabled() {
		return (bool) get_option( 'wc_blacklist_domain_enabled', 0 );
	}

	private function get_domain_action() {
		return get_option( 'wc_blacklist_domain_action', 'none' );
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

	private function increment_block_domain_counters() {
		$this->increment_option_counter( 'wc_blacklist_sum_block_domain' );
		$this->increment_option_counter( 'wc_blacklist_sum_block_total' );
	}

	private function sanitize_email_if_valid( $email ) {
		$email = sanitize_email( (string) $email );

		if ( ! empty( $email ) && is_email( $email ) ) {
			return $email;
		}

		return '';
	}

	private function extract_email_domain( $email ) {
		$email = $this->sanitize_email_if_valid( $email );

		if ( '' === $email || false === strpos( $email, '@' ) ) {
			return '';
		}

		$domain = substr( strrchr( $email, '@' ), 1 );
		$domain = strtolower( trim( (string) $domain ) );

		return '' !== $domain ? $domain : '';
	}

	private function get_normalized_blocked_tlds() {
		$blocked_tlds = get_option( 'wc_blacklist_domain_top_level', array() );

		if ( is_string( $blocked_tlds ) ) {
			$blocked_tlds = array_filter( array_map( 'trim', explode( ',', $blocked_tlds ) ) );
		}

		$normalized = array();

		foreach ( (array) $blocked_tlds as $tld ) {
			$t = strtolower( trim( (string) $tld ) );

			if ( '' === $t ) {
				continue;
			}

			if ( '.' !== $t[0] ) {
				$t = '.' . $t;
			}

			if ( preg_match( '/^\.[a-z0-9][a-z0-9\-\.]*$/', $t ) ) {
				$normalized[ $t ] = true;
			}
		}

		return array_keys( $normalized );
	}

	private function get_tld_hit_for_domain( $email_domain, $premium_active ) {
		if ( ! $premium_active || '' === $email_domain || false === strpos( $email_domain, '.' ) ) {
			return '';
		}

		$blocked_tlds = $this->get_normalized_blocked_tlds();

		if ( empty( $blocked_tlds ) ) {
			return '';
		}

		$labels     = array_reverse( explode( '.', strtolower( $email_domain ) ) );
		$candidates = array();

		if ( isset( $labels[0] ) ) {
			$candidates[] = '.' . $labels[0];
		}

		if ( isset( $labels[1] ) ) {
			$candidates[] = '.' . $labels[1] . '.' . $labels[0];
		}

		if ( isset( $labels[2] ) ) {
			$candidates[] = '.' . $labels[2] . '.' . $labels[1] . '.' . $labels[0];
		}

		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $blocked_tlds, true ) ) {
				return $candidate;
			}
		}

		return '';
	}

	private function is_exact_domain_blocked( $email_domain ) {
		global $wpdb;

		if ( '' === $email_domain ) {
			return false;
		}

		$table_name = $this->get_blacklist_table_name();
		$cache_key  = 'banned_domain_' . md5( $email_domain );
		$is_blocked = wp_cache_get( $cache_key, 'wc_blacklist' );

		if ( false === $is_blocked ) {
			$is_blocked = ! empty(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1
						FROM {$table_name}
						WHERE domain = %s
						LIMIT 1",
						$email_domain
					)
				)
			);

			wp_cache_set( $cache_key, $is_blocked, 'wc_blacklist', HOUR_IN_SECONDS );
		}

		return (bool) $is_blocked;
	}

	private function get_domain_match_result( $email_domain, $premium_active ) {
		$is_domain_blocked = $this->is_exact_domain_blocked( $email_domain );
		$tld_hit           = $this->get_tld_hit_for_domain( $email_domain, $premium_active );

		return array(
			'is_blocked'         => ( $is_domain_blocked || '' !== $tld_hit ),
			'is_exact_blocked'   => $is_domain_blocked,
			'domain_value'       => $is_domain_blocked ? $email_domain : '',
			'tld_hit'            => $tld_hit,
			'block_display_value'=> '' !== $tld_hit ? $tld_hit : $email_domain,
		);
	}

	private function get_domain_reason( $match ) {
		if ( ! empty( $match['tld_hit'] ) ) {
			return 'blocked_tld_attempt: ' . $match['tld_hit'];
		}

		if ( ! empty( $match['domain_value'] ) ) {
			return 'blocked_domain_attempt: ' . $match['domain_value'];
		}

		return '';
	}

	private function log_checkout_block_reason( $premium_active, $reason ) {
		if ( $premium_active && '' !== $reason ) {
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block( '', '', '', '', '', '', '', $reason );
		}
	}

	private function log_registration_block_reason( $premium_active, $reason ) {
		if ( $premium_active && '' !== $reason ) {
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block( '', '', '', '', '', $reason );
		}
	}

	private function log_comment_block_reason( $premium_active, $reason ) {
		if ( $premium_active && '' !== $reason ) {
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_block( '', '', '', '', '', $reason );
		}
	}

	private function email_order_block_for_domain( $value ) {
		if ( '' !== $value ) {
			WC_Blacklist_Manager_Email::send_email_order_block( '', '', '', $value );
		}
	}

	private function email_registration_block_for_domain( $value ) {
		if ( '' !== $value ) {
			WC_Blacklist_Manager_Email::send_email_registration_block( '', '', '', $value );
		}
	}

	private function email_comment_block_for_domain( $value ) {
		if ( '' !== $value ) {
			WC_Blacklist_Manager_Email::send_email_comment_block( '', '', $value );
		}
	}

	private function evaluate_domain_for_order_context( $email_domain, $premium_active, $send_email_notification = true ) {
		$match = $this->get_domain_match_result( $email_domain, $premium_active );

		if ( ! $match['is_blocked'] ) {
			return array(
				'is_blocked' => false,
				'match'      => $match,
				'reason'     => '',
			);
		}

		$this->increment_block_domain_counters();

		$reason = $this->get_domain_reason( $match );
		$this->log_checkout_block_reason( $premium_active, $reason );

		if ( $send_email_notification ) {
			$this->email_order_block_for_domain( $match['block_display_value'] );
		}

		return array(
			'is_blocked' => true,
			'match'      => $match,
			'reason'     => $reason,
		);
	}

	/**
	 * ---------------------------------------------------------------------
	 * Classic checkout.
	 * ---------------------------------------------------------------------
	 */

	public function check_customer_email_domain_against_blacklist() {
		if ( ! $this->is_woocommerce_ready() || ! $this->is_domain_prevention_enabled() ) {
			return;
		}

		if ( 'prevent' !== $this->get_domain_action() ) {
			return;
		}

		$premium_active = $this->is_premium_active();
		$billing_email  = isset( $_POST['billing_email'] ) ? $this->sanitize_email_if_valid( wp_unslash( $_POST['billing_email'] ) ) : '';

		if ( '' === $billing_email ) {
			return;
		}

		if ( ! is_email( $billing_email ) ) {
			wc_add_notice( __( 'Invalid email address provided.', 'wc-blacklist-manager' ), 'error' );
			return;
		}

		$email_domain = $this->extract_email_domain( $billing_email );

		if ( '' === $email_domain ) {
			wc_add_notice( __( 'Invalid email domain.', 'wc-blacklist-manager' ), 'error' );
			return;
		}

		$evaluation = $this->evaluate_domain_for_order_context( $email_domain, $premium_active, true );

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

	public function check_customer_email_domain_against_blacklist_blocks( \WC_Order $order, $request ) {
		if ( ! $this->is_woocommerce_ready() || ! ( $order instanceof \WC_Order ) ) {
			return;
		}

		if ( ! $this->is_domain_prevention_enabled() ) {
			return;
		}

		if ( 'prevent' !== $this->get_domain_action() ) {
			return;
		}

		$premium_active = $this->is_premium_active();
		$billing_email  = $this->sanitize_email_if_valid( (string) $order->get_billing_email() );

		if ( '' === $billing_email && $request && is_object( $request ) && method_exists( $request, 'get_params' ) ) {
			$params = $request->get_params();

			if ( isset( $params['billing_address'] ) && is_array( $params['billing_address'] ) && isset( $params['billing_address']['email'] ) ) {
				$billing_email = $this->sanitize_email_if_valid( (string) $params['billing_address']['email'] );
			} elseif ( isset( $params['billing_email'] ) ) {
				$billing_email = $this->sanitize_email_if_valid( (string) $params['billing_email'] );
			}
		}

		if ( '' === $billing_email || ! is_email( $billing_email ) ) {
			return;
		}

		$email_domain = $this->extract_email_domain( $billing_email );

		if ( '' === $email_domain ) {
			return;
		}

		$evaluation = $this->evaluate_domain_for_order_context( $email_domain, $premium_active, true );

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

		if ( ! $this->is_woocommerce_ready() || ! $this->is_domain_prevention_enabled() ) {
			return $dispatch_result;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $dispatch_result;
		}

		if ( 'prevent' !== $this->get_domain_action() ) {
			return $dispatch_result;
		}

		if ( ! $this->is_wc_rest_orders_request( $request ) ) {
			return $dispatch_result;
		}

		$premium_active = $this->is_premium_active();
		$route          = (string) $request->get_route();

		if ( preg_match( '#^/wc/v\d+/orders/batch$#', $route ) ) {
			$batch_groups = array(
				'create' => $request->get_param( 'create' ),
				'update' => $request->get_param( 'update' ),
			);

			foreach ( $batch_groups as $group_key => $group_items ) {
				if ( empty( $group_items ) || ! is_array( $group_items ) ) {
					continue;
				}

				foreach ( $group_items as $index => $payload ) {
					$payload = is_array( $payload ) ? $payload : array();

					$billing       = isset( $payload['billing'] ) && is_array( $payload['billing'] ) ? $payload['billing'] : array();
					$billing_email = isset( $billing['email'] ) ? $this->sanitize_email_if_valid( $billing['email'] ) : '';
					$email_domain  = $this->extract_email_domain( $billing_email );

					if ( '' === $email_domain ) {
						continue;
					}

					$evaluation = $this->evaluate_domain_for_order_context( $email_domain, $premium_active, true );

					if ( ! empty( $evaluation['is_blocked'] ) ) {
						return new WP_Error(
							'wc_blacklist_blocked_domain',
							$this->get_checkout_notice(),
							array(
								'status'  => 400,
								'details' => array(
									'group'  => $group_key,
									'index'  => $index,
									'reason' => $evaluation['reason'],
								),
							)
						);
					}
				}
			}

			return $dispatch_result;
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			$payload = $request->get_params();
		}

		$payload       = is_array( $payload ) ? $payload : array();
		$billing       = isset( $payload['billing'] ) && is_array( $payload['billing'] ) ? $payload['billing'] : array();
		$billing_email = isset( $billing['email'] ) ? $this->sanitize_email_if_valid( $billing['email'] ) : '';
		$email_domain  = $this->extract_email_domain( $billing_email );

		if ( '' === $email_domain ) {
			return $dispatch_result;
		}

		$evaluation = $this->evaluate_domain_for_order_context( $email_domain, $premium_active, true );

		if ( ! empty( $evaluation['is_blocked'] ) ) {
			return new WP_Error(
				'wc_blacklist_blocked_domain',
				$this->get_checkout_notice(),
				array(
					'status' => 400,
					'reason' => $evaluation['reason'],
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

	/**
	 * ---------------------------------------------------------------------
	 * Registration.
	 * ---------------------------------------------------------------------
	 */

	public function prevent_domain_registration( $errors, $sanitized_user_login, $user_email ) {
		return $this->handle_domain_registration( $errors, $user_email );
	}

	public function prevent_domain_registration_woocommerce( $errors, $username, $email ) {
		if ( ! $this->is_woocommerce_ready() ) {
			return $errors;
		}

		return $this->handle_domain_registration( $errors, $email );
	}

	private function handle_domain_registration( $errors, $email ) {
		if ( ! $this->is_domain_prevention_enabled() || ! get_option( 'wc_blacklist_domain_registration', 0 ) ) {
			return $errors;
		}

		$premium_active = $this->is_premium_active();
		$email          = $this->sanitize_email_if_valid( $email );

		if ( '' === $email ) {
			$errors->add( 'invalid_email', __( 'Invalid email address.', 'wc-blacklist-manager' ) );
			return $errors;
		}

		$email_domain = $this->extract_email_domain( $email );

		if ( '' === $email_domain ) {
			$errors->add( 'invalid_email_domain', __( 'Invalid email domain provided.', 'wc-blacklist-manager' ) );
			return $errors;
		}

		$match = $this->get_domain_match_result( $email_domain, $premium_active );

		if ( $match['is_blocked'] ) {
			wc_blacklist_add_registration_notice( $errors );
			$this->increment_block_domain_counters();

			$reason = $this->get_domain_reason( $match );
			$this->log_registration_block_reason( $premium_active, $reason );
			$this->email_registration_block_for_domain( $match['block_display_value'] );
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

		if ( ! $premium_active
			|| ! $this->is_domain_prevention_enabled()
			|| get_option( 'wc_blacklist_domain_comment', 0 ) !== '1'
		) {
			return $commentdata;
		}

		$author_email = isset( $commentdata['comment_author_email'] )
			? trim( $commentdata['comment_author_email'] )
			: '';

		$author_email = $this->sanitize_email_if_valid( $author_email );

		if ( '' === $author_email || ! is_email( $author_email ) ) {
			return $commentdata;
		}

		$email_domain = $this->extract_email_domain( $author_email );

		if ( '' === $email_domain ) {
			return $commentdata;
		}

		$match = $this->get_domain_match_result( $email_domain, $premium_active );

		if ( ! $match['is_blocked'] ) {
			return $commentdata;
		}

		$this->increment_block_domain_counters();

		$reason = $this->get_domain_reason( $match );
		$this->log_comment_block_reason( $premium_active, $reason );
		$this->email_comment_block_for_domain( $match['block_display_value'] );

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
	}
}

new WC_Blacklist_Manager_Email_Domain_Prevention();