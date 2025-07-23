<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Blocklisted_Actions {
	public function __construct() {
		add_action('woocommerce_checkout_process', [$this, 'prevent_order']);
		add_filter('registration_errors', [$this, 'prevent_blocked_email_registration'], 10, 3);
		add_filter('woocommerce_registration_errors', [$this, 'prevent_blocked_email_registration_woocommerce'], 10, 3);
		add_action('woocommerce_order_status_changed', [$this, 'schedule_order_cancellation'], 10, 4);
		add_action('wc_blacklist_delayed_order_cancel', [$this, 'delayed_order_cancel']);
		add_filter('preprocess_comment', [$this, 'prevent_comment'], 10, 1);
	}

	public function prevent_order() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		
		// Get the selected country code if it exists.
		$billing_dial_code = isset($_POST['billing_dial_code']) ? sanitize_text_field(wp_unslash($_POST['billing_dial_code'])) : '';
		
		// Get the billing phone, sanitize it, remove non-digits, and trim leading zeros.
		$billing_phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
		if ( strpos( $billing_phone, '+' ) !== 0 ) {
			$billing_phone = preg_replace( '/[^0-9]/', '', $billing_phone );
			$billing_phone = ltrim( $billing_phone, '0' );

			if ( ! empty( $billing_dial_code ) && ! empty( $billing_phone ) ) {
				$billing_phone = $billing_dial_code . $billing_phone;
			}
		}
		
		$billing_email = isset($_POST['billing_email']) ? sanitize_email(wp_unslash($_POST['billing_email'])) : '';

		if ($premium_active) {
			$user_ip = $this->get_the_user_ip();
			$billing_first_name = isset($_POST['billing_first_name']) ? sanitize_text_field(wp_unslash($_POST['billing_first_name'])) : '';
			$billing_last_name = isset($_POST['billing_last_name']) ? sanitize_text_field(wp_unslash($_POST['billing_last_name'])) : '';
			
			// Retrieve and sanitize billing address
			$billing_address_1 = isset($_POST['billing_address_1']) ? sanitize_text_field(wp_unslash($_POST['billing_address_1'])) : '';
			$billing_address_2 = isset($_POST['billing_address_2']) ? sanitize_text_field(wp_unslash($_POST['billing_address_2'])) : '';
			$billing_city = isset($_POST['billing_city']) ? sanitize_text_field(wp_unslash($_POST['billing_city'])) : '';
			$billing_state = isset($_POST['billing_state']) ? sanitize_text_field(wp_unslash($_POST['billing_state'])) : '';
			$billing_postcode = isset($_POST['billing_postcode']) ? sanitize_text_field(wp_unslash($_POST['billing_postcode'])) : '';
			$billing_country = isset($_POST['billing_country']) ? sanitize_text_field(wp_unslash($_POST['billing_country'])) : '';
		
			$billing_address_parts = array_filter([$billing_address_1, $billing_address_2, $billing_city, $billing_state, $billing_postcode, $billing_country]);
			$billing_address = implode(', ', $billing_address_parts);
		
			// Retrieve and sanitize shipping address
			$shipping_address_1 = isset($_POST['shipping_address_1']) ? sanitize_text_field(wp_unslash($_POST['shipping_address_1'])) : '';
			$shipping_address_2 = isset($_POST['shipping_address_2']) ? sanitize_text_field(wp_unslash($_POST['shipping_address_2'])) : '';
			$shipping_city = isset($_POST['shipping_city']) ? sanitize_text_field(wp_unslash($_POST['shipping_city'])) : '';
			$shipping_state = isset($_POST['shipping_state']) ? sanitize_text_field(wp_unslash($_POST['shipping_state'])) : '';
			$shipping_postcode = isset($_POST['shipping_postcode']) ? sanitize_text_field(wp_unslash($_POST['shipping_postcode'])) : '';
			$shipping_country = isset($_POST['shipping_country']) ? sanitize_text_field(wp_unslash($_POST['shipping_country'])) : '';
		
			$shipping_address_parts = array_filter([$shipping_address_1, $shipping_address_2, $shipping_city, $shipping_state, $shipping_postcode, $shipping_country]);
			$shipping_address = implode(', ', $shipping_address_parts);

			// Pull cart items (product_id => quantity)
			$items = [];
			if ( WC()->cart && ! WC()->cart->is_empty() ) {
				foreach ( WC()->cart->get_cart() as $cart_item ) {
					$product_id  = $cart_item['product_id'];
					$quantity    = intval( $cart_item['quantity'] );
					// Unit price as set on the product (before per-item discounts)
					$unit_price  = floatval( $cart_item['data']->get_price() );
					// Line total (quantity * unit_price, after discounts)
					$line_total  = floatval( $cart_item['line_total'] );

					$items[ $product_id ] = [
						'quantity'   => $quantity,
						'unit_price' => $unit_price,
						'line_total' => $line_total,
					];
				}
			}

			$fees = [];
			foreach ( WC()->cart->get_fees() as $fee_item ) {
				$fees[] = [
					'name'   => $fee_item->name,
					'amount' => (float) $fee_item->amount,
				];
			}

			// Other totals & methods
			$subtotal         = WC()->cart->get_subtotal();             // raw subtotal
			$discount_total   = WC()->cart->get_discount_total();       // raw discount
			$shipping_total   = WC()->cart->get_shipping_total();       // raw shipping fee
			$total            = WC()->cart->total;                     // raw order total
			$chosen_methods   = WC()->session->get( 'chosen_shipping_methods', [] );
			$shipping_method  = ! empty( $chosen_methods ) 
								? $chosen_methods[0] 
								: '';
			// Cart tax totals
			$cart_contents_tax = WC()->cart->get_cart_contents_tax();
			$shipping_tax      = WC()->cart->get_shipping_tax();
			$tax_total         = $cart_contents_tax + $shipping_tax;

			$payment_method = '';
			if ( isset( $_POST['payment_method'] ) ) {
				$payment_method = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
			} elseif ( WC()->session ) {
				$payment_method = WC()->session->get( 'chosen_payment_method', '' );
			}
								
			$view_data = [
				'ip_address' => $user_ip,
				'first_name' => $billing_first_name,
				'last_name'  => $billing_last_name,
				'phone'      => $billing_phone,
				'email'      => $billing_email,
				'billing'    => $billing_address,
				'shipping'   => $shipping_address,
			];
			$view_data['cart_items']       = $items;
			$view_data['fees'] = $fees;
			$view_data['cart_subtotal']    = $subtotal;
			$view_data['cart_contents_tax'] = $cart_contents_tax;
			$view_data['coupons'] = WC()->cart->get_applied_coupons();
			$view_data['cart_discount']    = $discount_total;
			$view_data['cart_shipping']    = [
				'method' => $shipping_method,
				'fee'    => $shipping_total,
				'shipping_tax' => $shipping_tax,
			];
			$view_data['cart_tax']         = $tax_total;
			$view_data['cart_total']       = $total;
			$view_data['payment_method']   = $payment_method;
			$view_data['currency'] = get_woocommerce_currency();
			$view_json = wp_json_encode( $view_data );

			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block($view_json);
		}
		
		$blacklist_action = get_option('wc_blacklist_action', 'none');
		$checkout_notice  = get_option(
			'wc_blacklist_checkout_notice',
			__('Sorry! You are no longer allowed to shop with us. If you think it is a mistake, please contact support.', 'wc-blacklist-manager')
		);
	
		// Check if the phone is blocked.
		$is_phone_blocked = false;
		if ( ! empty( $billing_phone ) ) {
			$result_phone = $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE TRIM(LEADING '0' FROM phone_number) = %s AND is_blocked = 1 LIMIT 1",
				$billing_phone
			) );
			$is_phone_blocked = ! empty( $result_phone );
			
			// If the phone is not blocked, clear the variable.
			if ( $is_phone_blocked ) {
				$sum_block_phone = get_option('wc_blacklist_sum_block_phone', 0);
				update_option('wc_blacklist_sum_block_phone', $sum_block_phone + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$reason_phone = 'blocked_phone_attempt: ' . $billing_phone;
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block('', $reason_phone);
				}
			} else {
				$billing_phone = '';
			}
		}
	
		// Check if the email is blocked.
		$is_email_blocked = false;
		if ( ! empty( $billing_email ) && is_email( $billing_email ) ) {
			$result_email = $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 1 LIMIT 1",
				$billing_email
			) );
			$is_email_blocked = ! empty( $result_email );
			
			// If the email is not blocked, clear the variable.
			if ( $is_email_blocked ) {
				$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
				update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$reason_email = 'blocked_email_attempt: ' . $billing_email;
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::checkout_block('', '', $reason_email);
				}
			} else {
				$billing_email = '';
			}
		}
	
		// If either the phone or email is blocked and the blacklist action is set to "prevent"
		if ( ( $is_phone_blocked || $is_email_blocked ) && $blacklist_action === 'prevent' ) {
			wc_add_notice( $checkout_notice, 'error' );

			// Trigger the email with only the blocked values
			WC_Blacklist_Manager_Email::send_email_order_block( $billing_phone, $billing_email );
		}
	}
	
	public function prevent_blocked_email_registration($errors, $sanitized_user_login, $user_email) {
		return $this->handle_registration_block($errors, $sanitized_user_login, $user_email);
	}

	public function prevent_blocked_email_registration_woocommerce($errors, $username, $email) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		return $this->handle_registration_block($errors, $username, $email);
	}

	private function handle_registration_block($errors, $username, $email) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		global $wpdb;
		if (get_option('wc_blacklist_block_user_registration', 0)) {
			$table_name = $wpdb->prefix . 'wc_blacklist';
			$email_blocked = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 1 LIMIT 1",
				$email
			)));
			$email_suspected = !empty($wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 0 LIMIT 1",
				$email
			)));

			if ($premium_active) {
				$user_ip = $this->get_the_user_ip();
				$phone = '';

				if ( (is_plugin_active( 'wc-advanced-accounts/wc-advanced-accounts.php' ) || is_plugin_active( 'wc-advanced-accounts-premium/wc-advanced-accounts-premium.php' ) )
				&& get_option( 'yoaa_wc_enable_phone_number_account' ) === 'yes' ) {
					if ( preg_match( '/^\d+-\d+$/', $username ) ) {
						$phone = '+' . str_replace( '-', '', $username );
					} else {
						$phone = preg_replace( '/^0/', '', $username );
					}
				}

				$view_data = [
					'ip_address' => $user_ip,
					'email'      => $email,
				];
				if ( ! empty( $phone ) ) {
					$view_data['phone'] = $phone;
				}
				$view_json = wp_json_encode( $view_data );

				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block($view_json);
				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_suspect($view_json);
			}

			if ($email_blocked) {
				$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
				update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				WC_Blacklist_Manager_Email::send_email_registration_block( '', $email );

				if ($premium_active) {
					$reason_email = 'blocked_email_attempt: ' . $email;
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block('', '', $reason_email);
				}
				
				wc_blacklist_add_registration_notice($errors);
			} elseif ($email_suspected) {
				$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
				update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				WC_Blacklist_Manager_Email::send_email_registration_suspect( '', $email );

				if ($premium_active) {
					$reason_email = 'suspected_email_attempt: ' . $email;
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_suspect('', '', $reason_email);
				}
			} else {
				$email = '';
			}

			if ( (is_plugin_active( 'wc-advanced-accounts/wc-advanced-accounts.php' ) || is_plugin_active( 'wc-advanced-accounts-premium/wc-advanced-accounts-premium.php' ) )
				&& get_option( 'yoaa_wc_enable_phone_number_account' ) === 'yes' ) {

				// 1) normalize $phone from $username:
				if ( preg_match( '/^\d+-\d+$/', $username ) ) {
					$phone = '+' . str_replace( '-', '', $username );
				} else {
					$phone = preg_replace( '/^0/', '', $username );
				}

				// 2) check block / suspect in DB (strip leading zeros from stored value)
				$phone_blocked   = ! empty( $wpdb->get_var( $wpdb->prepare(
					"SELECT 1 
					FROM {$table_name} 
					WHERE TRIM(LEADING '0' FROM phone_number) = %s 
						AND is_blocked = 1 
					LIMIT 1",
					$phone
				) ) );

				$phone_suspected = ! empty( $wpdb->get_var( $wpdb->prepare(
					"SELECT 1 
					FROM {$table_name} 
					WHERE TRIM(LEADING '0' FROM phone_number) = %s 
						AND is_blocked = 0 
					LIMIT 1",
					$phone
				) ) );

				if ( $phone_blocked ) {
					$sum_phone = get_option( 'wc_blacklist_sum_block_phone', 0 );
					update_option( 'wc_blacklist_sum_block_phone', $sum_phone + 1 );
					$sum_total = get_option( 'wc_blacklist_sum_block_total', 0 );
					update_option( 'wc_blacklist_sum_block_total', $sum_total + 1 );

					// notify user by email
					WC_Blacklist_Manager_Email::send_email_registration_block( $phone );

					if ( $premium_active ) {
						$reason_phone = 'blocked_phone_attempt: ' . $phone;
						WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_block('', $reason_phone);
					}

					wc_blacklist_add_registration_notice( $errors );

				} elseif ( $phone_suspected ) {
					$sum_phone = get_option( 'wc_blacklist_sum_block_phone', 0 );
					update_option( 'wc_blacklist_sum_block_phone', $sum_phone + 1 );
					$sum_total = get_option( 'wc_blacklist_sum_block_total', 0 );
					update_option( 'wc_blacklist_sum_block_total', $sum_total + 1 );

					// send suspect notification
					WC_Blacklist_Manager_Email::send_email_registration_suspect( $phone );

					if ( $premium_active ) {
						$reason_phone = 'suspected_phone_attempt: ' . $phone;
						WC_Blacklist_Manager_Premium_Activity_Logs_Insert::register_suspect('', '', $reason_phone);
					}
				}
			}
		}
		return $errors;
	}

	public function schedule_order_cancellation($order_id, $old_status, $new_status, $order) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		
		if (!in_array($new_status, array('on-hold', 'processing', 'completed'))) {
			return;
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
		$blacklist_action = get_option('wc_blacklist_action', 'none');

		if ($blacklist_action !== 'cancel') {
			return;
		}

		$billing_phone = sanitize_text_field($order->get_billing_phone());
		$billing_phone = ltrim($billing_phone, '0');
		
		$billing_email = sanitize_email($order->get_billing_email());

		$is_blocked = false;
		if (!empty($billing_phone)) {
			$result = $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE TRIM(LEADING '0' FROM phone_number) = %s AND is_blocked = 1 LIMIT 1",
				$billing_phone
			) );
			$is_blocked = !empty($result);

			if ($is_blocked) {
				$sum_block_phone = get_option('wc_blacklist_sum_block_phone', 0);
				update_option('wc_blacklist_sum_block_phone', $sum_block_phone + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$timestamp = current_time('mysql');
					$type      = 'bot';
					$source    = 'woo_order_' . $order_id;
					$action    = 'cancel';
					$details   = 'blocked_phone_attempt: ' . $billing_phone;
					
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
			}
		}
		
		if (!empty($billing_email) && is_email($billing_email)) {
			$result = $wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 1 LIMIT 1",
				$billing_email
			));
			$is_blocked |= !empty($result);

			if ($is_blocked) {
				$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
				update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				if ($premium_active) {
					$timestamp = current_time('mysql');
					$type      = 'bot';
					$source    = 'woo_order_' . $order_id;
					$action    = 'cancel';
					$details   = 'blocked_email_attempt: ' . $billing_email;
					
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
			}
		}

		if ($is_blocked) {
			$order_delay = max(0, intval(get_option('wc_blacklist_order_delay', 0)));
			if ($order_delay > 0) {
				wp_schedule_single_event(time() + ($order_delay * 60), 'wc_blacklist_delayed_order_cancel', [$order_id]);
			} else {
				$order->update_status('cancelled', __('Order cancelled due to blacklist match.', 'wc-blacklist-manager'));
			}
		}
	}

	public function delayed_order_cancel($order_id) {
		$order = wc_get_order($order_id);
		if ($order && !$order->has_status('cancelled')) {
			$order->update_status('cancelled', __('Order cancelled due to blocklist match.', 'wc-blacklist-manager'));
		}
	}

	public function prevent_comment( $commentdata ) {
		if ( get_option( 'wc_blacklist_comment_blocking_enabled', 0 ) !== '1' ) {
			return $commentdata;
		}
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		$author_email = isset( $commentdata['comment_author_email'] )
			? trim( $commentdata['comment_author_email'] )
			: '';

		if ( ! empty( $author_email ) && is_email( $author_email ) ) {
			// Check for a blocked email
			$is_blocked = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE email_address = %s
						AND is_blocked     = 1
					LIMIT 1",
					$author_email
				)
			);

			$is_suspected = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE email_address = %s
						AND is_blocked     = 0
					LIMIT 1",
					$author_email
				)
			);

			$user_ip = $this->get_the_user_ip();
			
			$view_data = [
				'ip_address' => $user_ip,
				'email'      => $author_email,
			];
			$view_json = wp_json_encode( $view_data );

			$source = 'comment';
			if ( ! empty( $commentdata['comment_type'] ) && 'review' === $commentdata['comment_type'] ) {
				$source = 'woo_review';
			}
			
			if ( $is_blocked ) {
				$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
				update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				WC_Blacklist_Manager_Email::send_email_comment_block( $author_email );

				if ($premium_active) {
					$reason_email = 'blocked_email_attempt: ' . $author_email;
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_block($view_json, $source, $reason_email);
				}

				// Get your custom notice; allow a %s placeholder for the email
				$notice_template = get_option(
					'wc_blacklist_comment_notice',
					__('Sorry! You are no longer allowed to submit a comment on our site. If you think it is a mistake, please contact support.', 'wc-blacklist-manager')
				);

				// Inject the email into the notice
				$notice = sprintf( wp_kses_post( $notice_template ) );

				wp_die(
					$notice,
					__( 'Comment Blocked', 'wc-blacklist-manager' ),
					[ 'response' => 403 ]
				);
			} elseif ( $is_suspected ) {
				$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
				update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
				$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
				update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

				WC_Blacklist_Manager_Email::send_email_comment_suspect( $author_email );

				if ($premium_active) {
					$reason_email = 'suspected_email_attempt: ' . $author_email;
					WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_suspect($view_json, $source, $reason_email);
				}
			}
		}

		return $commentdata;
	}

	private function get_the_user_ip() {
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			// Cloudflare connecting IP
			$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
		} elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			// Client IP
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// X-Forwarded-For header
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			// Remote address
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return sanitize_text_field($ip);
	}
}

new WC_Blacklist_Manager_Blocklisted_Actions();
