<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Order_Actions {
	private $version = '2.1.1';
	private $suspect_nonce_key = 'blacklist_ajax_nonce';
	private $block_nonce_key = 'block_ajax_nonce';

	public function __construct() {
		add_action('admin_enqueue_scripts', [$this, 'enqueue_script']);
		add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'add_button_to_order_edit']);
		add_action('wp_ajax_add_to_blacklist', [$this, 'handle_add_to_suspects']);
		add_action('wp_ajax_block_customer', [$this, 'handle_add_to_blocklist']);
		add_action('woocommerce_admin_order_data_after_payment_info', [$this, 'display_blacklist_notices']);
	}

	private function get_order_address_payloads( WC_Order $order ): array {
		$billing_country = sanitize_text_field( $order->get_billing_country() );

		$billing_address_data = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_billing_address_1() ),
				'address_2' => sanitize_text_field( $order->get_billing_address_2() ),
				'city'      => sanitize_text_field( $order->get_billing_city() ),
				'state'     => sanitize_text_field( $order->get_billing_state() ),
				'postcode'  => sanitize_text_field( $order->get_billing_postcode() ),
				'country'   => $billing_country,
			)
		);

		$shipping_address_data = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_shipping_address_1() ),
				'address_2' => sanitize_text_field( $order->get_shipping_address_2() ),
				'city'      => sanitize_text_field( $order->get_shipping_city() ),
				'state'     => sanitize_text_field( $order->get_shipping_state() ),
				'postcode'  => sanitize_text_field( $order->get_shipping_postcode() ),
				'country'   => sanitize_text_field( $order->get_shipping_country() ),
			)
		);

		return array(
			'billing'  => $billing_address_data,
			'shipping' => $shipping_address_data,
		);
	}

	private function get_existing_address_row_id( string $address_table_name, array $address_data, int $is_blocked ): int {
		global $wpdb;

		if ( empty( $address_data['address_hash'] ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				FROM {$address_table_name}
				WHERE match_type = %s
				AND address_hash = %s
				AND is_blocked = %d
				LIMIT 1",
				'address',
				$address_data['address_hash'],
				$is_blocked
			)
		);
	}

	private function insert_address_row( string $address_table_name, ?int $blacklist_id, array $address_data, int $is_blocked, string $notes = '' ): int {
		global $wpdb;

		if ( empty( $address_data['address_full_norm'] ) ) {
			return 0;
		}

		$inserted = $wpdb->insert(
			$address_table_name,
			array(
				'blacklist_id'               => $blacklist_id ? $blacklist_id : null,
				'match_type'                 => 'address',
				'is_blocked'                 => $is_blocked,
				'country_code'               => isset( $address_data['country_code'] ) ? $address_data['country_code'] : '',
				'state_code'                 => isset( $address_data['state_code'] ) ? $address_data['state_code'] : '',
				'city_norm'                  => isset( $address_data['city_norm'] ) ? $address_data['city_norm'] : '',
				'postcode_norm'              => isset( $address_data['postcode_norm'] ) ? $address_data['postcode_norm'] : '',
				'address_line_norm'          => isset( $address_data['address_line_norm'] ) ? $address_data['address_line_norm'] : '',
				'address_core_norm'          => isset( $address_data['address_core_norm'] ) ? $address_data['address_core_norm'] : '',
				'address_full_norm'          => isset( $address_data['address_full_norm'] ) ? $address_data['address_full_norm'] : '',
				'address_core_hash'          => isset( $address_data['address_core_hash'] ) ? $address_data['address_core_hash'] : '',
				'address_line_postcode_hash' => isset( $address_data['address_line_postcode_hash'] ) ? $address_data['address_line_postcode_hash'] : '',
				'address_hash'               => isset( $address_data['address_hash'] ) ? $address_data['address_hash'] : '',
				'address_display'            => isset( $address_data['address_display'] ) ? $address_data['address_display'] : '',
				'notes'                      => $notes,
				'date_added'                 => current_time( 'mysql', 1 ),
			),
			array(
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	private function get_address_match_state( string $address_table_name, array $address_data ): array {
		global $wpdb;

		$result = array(
			'blocked_exact'  => false,
			'suspect_exact'  => false,
			'blocked_core'   => false,
			'suspect_core'   => false,
		);

		if ( ! empty( $address_data['address_hash'] ) ) {
			$result['blocked_exact'] = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$address_table_name}
					WHERE match_type = %s
					AND address_hash = %s
					AND is_blocked = %d
					LIMIT 1",
					'address',
					$address_data['address_hash'],
					1
				)
			);

			$result['suspect_exact'] = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$address_table_name}
					WHERE match_type = %s
					AND address_hash = %s
					AND is_blocked = %d
					LIMIT 1",
					'address',
					$address_data['address_hash'],
					0
				)
			);
		}

		if ( ! empty( $address_data['address_core_hash'] ) ) {
			$result['blocked_core'] = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$address_table_name}
					WHERE match_type IN ('address', 'address_core')
					AND address_core_hash = %s
					AND is_blocked = %d
					LIMIT 1",
					$address_data['address_core_hash'],
					1
				)
			);

			$result['suspect_core'] = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$address_table_name}
					WHERE match_type IN ('address', 'address_core')
					AND address_core_hash = %s
					AND is_blocked = %d
					LIMIT 1",
					$address_data['address_core_hash'],
					0
				)
			);
		}

		return $result;
	}

	public function enqueue_script() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if (!$premium_active && !current_user_can('manage_options')) {
			return;
		}

		global $pagenow;
    
		// Check if we're on the WooCommerce Edit Order Page
		$is_legacy_edit_order_page = ($pagenow === 'post.php' && isset($_GET['post']) && isset($_GET['action']) && $_GET['action'] === 'edit' && get_post_type($_GET['post']) === 'shop_order');
		$is_hpos_edit_order_page = ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['id'])); // HPOS order edit page uses 'id' parameter
	
		if (!($is_legacy_edit_order_page || $is_hpos_edit_order_page)) {
			return;
		}

		$order_id = 0;

		if ( $is_legacy_edit_order_page ) {
			$order_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		} elseif ( $is_hpos_edit_order_page ) {
			$order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		}

		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$phone = sanitize_text_field($order->get_billing_phone());
		$email = sanitize_email($order->get_billing_email());
		$ip_address = sanitize_text_field($order->get_customer_ip_address());

		$show_suspect_button = $this->should_show_suspect_button($order, $phone, $email, $ip_address);
		$show_block_button = $this->should_show_block_button($order, $phone, $email, $ip_address);

		if ($show_suspect_button) {
			$suspect_cript_url = plugin_dir_url(__FILE__) . '../../js/button-add-to-suspects.js?v=' . $this->version;
			$suspect_cript_url = filter_var($suspect_cript_url, FILTER_SANITIZE_URL);
			if (!filter_var($suspect_cript_url, FILTER_VALIDATE_URL)) {
				wp_die('Invalid script URL');
			}

			$escaped_suspect_cript_url = esc_url($suspect_cript_url);
			wp_enqueue_script('blacklist-ajax-script', $escaped_suspect_cript_url, ['jquery'], null, true);

			$suspect_nonce = wp_create_nonce($this->suspect_nonce_key);
			$escaped_suspect_nonce = esc_attr($suspect_nonce);

			wp_localize_script('blacklist-ajax-script', 'blacklist_ajax_object', [
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => $escaped_suspect_nonce,
				'confirm_message' => esc_html__('Are you sure you want to add this to the suspects list?', 'wc-blacklist-manager')
			]);
		}
		
		if ( $show_block_button ) {
			// Block action
			$block_script_url = plugin_dir_url( __FILE__ ) . '../../js/button-add-to-blocklist.js?v=' . $this->version;
			$block_script_url = filter_var( $block_script_url, FILTER_SANITIZE_URL );
			if ( ! filter_var( $block_script_url, FILTER_VALIDATE_URL ) ) {
				wp_die( 'Invalid script URL' );
			}

			$escaped_block_script_url = esc_url( $block_script_url );
			wp_enqueue_script( 'block-ajax-script', $escaped_block_script_url, [ 'jquery' ], null, true );

			$block_nonce = wp_create_nonce( $this->block_nonce_key );

			wp_localize_script(
				'block-ajax-script',
				'block_ajax_object',
				[
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => $block_nonce,
				]
			);

			wp_localize_script(
				'block-ajax-script',
				'block_ajax_reasons',
				[
					'reasons' => [
						'stolen_card'   => __( 'Stolen card', 'wc-blacklist-manager' ),
						'chargeback'    => __( 'Chargeback', 'wc-blacklist-manager' ),
						'fraud_network' => __( 'Fraud network', 'wc-blacklist-manager' ),
						'spam'          => __( 'Spam', 'wc-blacklist-manager' ),
						'policy_abuse'  => __( 'Policy abuse', 'wc-blacklist-manager' ),
						'other'         => __( 'Other', 'wc-blacklist-manager' ),
					],
					'descriptions' => [
						'stolen_card'   => __(
							'Payment appears to have been made using a stolen or unauthorized card/payment method.',
							'wc-blacklist-manager'
						),
						'chargeback'    => __(
							'The transaction was charged back by the bank or payment provider as fraud or cardholder dispute.',
							'wc-blacklist-manager'
						),
						'fraud_network' => __(
							'Identity is linked to multiple suspicious orders, bots, or coordinated fraud patterns across your store.',
							'wc-blacklist-manager'
						),
						'spam'          => __(
							'Orders are clearly fake, low-value, or automated (e.g. card testing, dummy data, or bulk spam).',
							'wc-blacklist-manager'
						),
						'policy_abuse'  => __(
							'Customer repeatedly abuses your store policies (refund abuse, return abuse, reselling, or similar).',
							'wc-blacklist-manager'
						),
						// Intentionally no description for "other".
					],
					'labels' => [
						'modal_title'       => __( 'Block customer', 'wc-blacklist-manager' ),
						'reason_label'      => __( 'Reason', 'wc-blacklist-manager' ),
						'select_reason'     => __( 'Select a reason...', 'wc-blacklist-manager' ),
						'description_label' => __( 'Description / internal note', 'wc-blacklist-manager' ),
						'required_reason'   => __( 'Please select a reason.', 'wc-blacklist-manager' ),
						'required_desc'     => __( 'Please enter a description for “Other”.', 'wc-blacklist-manager' ),
						'cancel'            => __( 'Cancel', 'wc-blacklist-manager' ),
						'confirm'           => __( 'Confirm block', 'wc-blacklist-manager' ),
					],
				]
			);
		}
	}

	public function add_button_to_order_edit( $order ) {
		if ( method_exists( $order, 'get_type' ) && 'shop_subscription' === $order->get_type() ) {
			return;
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		// Free-version UI only.
		if ( $premium_active || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$phone      = sanitize_text_field( $order->get_billing_phone() );
		$email      = sanitize_email( $order->get_billing_email() );
		$ip_address = sanitize_text_field( $order->get_customer_ip_address() );

		$show_suspect_button = $this->should_show_suspect_button( $order, $phone, $email, $ip_address );
		$show_block_button   = $this->should_show_block_button( $order, $phone, $email, $ip_address );

		$show_remove_button_suspect_main = $order->get_meta( '_blacklist_suspect_ids_main', true );
		$show_remove_button_block_main   = $order->get_meta( '_blacklist_blocked_ids_main', true );

		// Legacy fallback.
		$legacy_suspect = $order->get_meta( '_blacklist_suspect_id', true );
		$legacy_blocked = $order->get_meta( '_blacklist_blocked_id', true );

		$has_suspect_meta = ! empty( $show_remove_button_suspect_main ) || ! empty( $legacy_suspect );
		$has_blocked_meta = ! empty( $show_remove_button_block_main ) || ! empty( $legacy_blocked );

		echo '<div style="margin-top: 12px;" class="bm_order_actions">';
		echo '<h3>' . esc_html__( 'Blacklist actions', 'wc-blacklist-manager' ) . '</h3>';
		echo '<p>';

		if ( $show_suspect_button ) {
			echo '<button id="add_to_blacklist" class="button button-secondary icon-button" title="' . esc_attr__( 'Add to the suspects list', 'wc-blacklist-manager' ) . '"><span class="dashicons dashicons-flag" style="margin-right: 3px;"></span> ' . esc_html__( 'Suspect', 'wc-blacklist-manager' ) . '</button> ';
		}

		if ( $show_block_button ) {
			echo '<button id="block_customer" class="button red-button" title="' . esc_attr__( 'Add to blocklist', 'wc-blacklist-manager' ) . '"><span class="dashicons dashicons-dismiss" style="margin-right: 3px;"></span> ' . esc_html__( 'Block', 'wc-blacklist-manager' ) . '</button>';
		} elseif ( ! $show_block_button && ! $has_blocked_meta ) {
			echo '<span style="color:#b32d2e;">' . esc_html__( 'This customer is already blocked.', 'wc-blacklist-manager' ) . '</span>';
		}

		if ( $has_suspect_meta || $has_blocked_meta ) {
			echo ' <button class="button button-secondary icon-button" title="' . esc_attr__( 'Remove', 'wc-blacklist-manager' ) . '" disabled><span class="dashicons dashicons-remove" style="margin-right: 3px;"></span> ' . esc_html__( 'Remove', 'wc-blacklist-manager' ) . '</button>';
		}

		echo '</p>';
		echo '<p class="bm_description"><a href="https://yoohw.com/product/blacklist-manager-premium/" target="_blank" title="Upgrade Premium"><span class="yoohw_unlock">Unlock</span></a> ' . esc_html__( 'to power up the blacklist actions.', 'wc-blacklist-manager' ) . '</p>';
		echo '</div>';

		echo '
		<div class="bm-modal-backdrop" id="bmModalBackdrop"></div>
			<div class="bm-modal" id="bmModal" style="display:none;">
			<header><span id="bmModalTitle"></span></header>
			<div class="bm-body">
				<div class="bm-field">
					<label for="bm_reason" id="bmReasonLabel"></label>
					<select id="bm_reason">
						<!-- options injected by JS -->
					</select>
				</div>
				<div class="bm-field bm-reason-desc" id="bmReasonDescWrap" style="display:none;">
					<p id="bmReasonDesc"></p>
				</div>
				<div class="bm-field">
					<label for="bm_description" id="bmDescLabel"></label>
					<textarea id="bm_description" rows="4"></textarea>
				</div>
				<div class="bm-field bm-error" id="bmError" style="display:none;color:#b32d2e;"></div>
			</div>
			<footer>
				<button type="button" class="button" id="bmCancel"></button>
				<button type="button" class="button button-primary" id="bmConfirm"></button>
			</footer>
		</div>
		';
	}

	private function should_show_block_button( $order, $phone, $email, $ip_address ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		$ip_blacklist_enabled = get_option( 'wc_blacklist_ip_enabled', 0 );

		$billing_country   = sanitize_text_field( $order->get_billing_country() );
		$billing_dial_code = yobm_get_country_dial_code( $billing_country );

		$normalized_phone = '';
		if ( ! empty( $phone ) ) {
			$normalized_phone = yobm_normalize_phone( $phone, $billing_dial_code );
		}

		$normalized_email = '';
		if ( ! empty( $email ) && is_email( $email ) ) {
			$normalized_email = yobm_normalize_email( $email );
		}

		$phone_blocked = true;
		if ( ! empty( $phone ) || ! empty( $normalized_phone ) ) {
			$phone_blocked = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE is_blocked = 1
					AND (
						phone_number = %s
						OR ( %s <> '' AND normalized_phone = %s )
					)
					LIMIT 1",
					$phone,
					$normalized_phone,
					$normalized_phone
				)
			);
		}

		$email_blocked = true;
		if ( ! empty( $email ) && is_email( $email ) ) {
			$email_blocked = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE is_blocked = 1
					AND (
						email_address = %s
						OR ( %s <> '' AND normalized_email = %s )
					)
					LIMIT 1",
					$email,
					$normalized_email,
					$normalized_email
				)
			);
		}

		$ip_blocked = true;
		if ( $ip_blacklist_enabled && ! empty( $ip_address ) ) {
			$ip_blocked = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE ip_address = %s
					AND is_blocked = 1
					LIMIT 1",
					$ip_address
				)
			);
		}

		$all_fields_blocked = $phone_blocked && $email_blocked && $ip_blocked;

		return ! $all_fields_blocked;
	}

	private function should_show_suspect_button( $order, $phone, $email, $ip_address ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';

		$ip_blacklist_enabled = get_option( 'wc_blacklist_ip_enabled', 0 );

		$billing_country   = sanitize_text_field( $order->get_billing_country() );
		$billing_dial_code = yobm_get_country_dial_code( $billing_country );

		$normalized_phone = '';
		if ( ! empty( $phone ) ) {
			$normalized_phone = yobm_normalize_phone( $phone, $billing_dial_code );
		}

		$normalized_email = '';
		if ( ! empty( $email ) && is_email( $email ) ) {
			$normalized_email = yobm_normalize_email( $email );
		}

		$phone_exists = true;
		if ( ! empty( $phone ) || ! empty( $normalized_phone ) ) {
			$phone_exists = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE phone_number = %s
					OR ( %s <> '' AND normalized_phone = %s )
					LIMIT 1",
					$phone,
					$normalized_phone,
					$normalized_phone
				)
			);
		}

		$email_exists = true;
		if ( ! empty( $email ) && is_email( $email ) ) {
			$email_exists = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE email_address = %s
					OR ( %s <> '' AND normalized_email = %s )
					LIMIT 1",
					$email,
					$normalized_email,
					$normalized_email
				)
			);
		}

		$ip_exists = true;
		if ( $ip_blacklist_enabled && ! empty( $ip_address ) ) {
			$ip_exists = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$table_name}
					WHERE ip_address = %s
					LIMIT 1",
					$ip_address
				)
			);
		}

		$all_fields_exist = $phone_exists && $email_exists && $ip_exists;

		return ! $all_fields_exist;
	}

	public function handle_add_to_suspects() {
		check_ajax_referer( $this->suspect_nonce_key, 'nonce' );

		global $wpdb;
		$table_name          = $wpdb->prefix . 'wc_blacklist';
		$address_table_name  = $wpdb->prefix . 'wc_blacklist_addresses';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

		$allowed_roles       = get_option( 'wc_blacklist_dashboard_permission', array() );
		$user_has_permission = false;

		if ( is_array( $allowed_roles ) && ! empty( $allowed_roles ) ) {
			foreach ( $allowed_roles as $role ) {
				if ( current_user_can( $role ) ) {
					$user_has_permission = true;
					break;
				}
			}
		}

		if ( ! $user_has_permission && ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( $order_id <= 0 ) {
			echo esc_html__( 'Invalid order ID.', 'wc-blacklist-manager' );
			wp_die();
		}

		$ip_blacklist_enabled           = get_option( 'wc_blacklist_ip_enabled', 0 );
		$address_blocking_enabled       = get_option( 'wc_blacklist_enable_customer_address_blocking', 0 );
		$shipping_blocking_enabled      = get_option( 'wc_blacklist_enable_shipping_address_blocking', 0 );
		$customer_name_blocking_enabled = get_option( 'wc_blacklist_customer_name_blocking_enabled', 0 );

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo esc_html__( 'Invalid order.', 'wc-blacklist-manager' );
			wp_die();
		}

		$phone      = sanitize_text_field( $order->get_billing_phone() );
		$email      = sanitize_email( $order->get_billing_email() );
		$ip_address = sanitize_text_field( $order->get_customer_ip_address() );

		$normalized_email = '';
		if ( ! empty( $email ) && is_email( $email ) ) {
			$normalized_email = yobm_normalize_email( $email );
		}

		$source = sprintf( __( 'Order ID: %d', 'wc-blacklist-manager' ), $order_id );

		$first_name = sanitize_text_field( $order->get_billing_first_name() );
		$last_name  = sanitize_text_field( $order->get_billing_last_name() );

		$billing_country = sanitize_text_field( $order->get_billing_country() );

		$address_payloads      = $this->get_order_address_payloads( $order );
		$billing_address_data  = $address_payloads['billing'];
		$shipping_address_data = $address_payloads['shipping'];

		$billing_dial_code = yobm_get_country_dial_code( $billing_country );

		$normalized_phone = '';
		if ( ! empty( $phone ) ) {
			$normalized_phone = yobm_normalize_phone( $phone, $billing_dial_code );
		}

		$contact_data = array(
			'sources'    => $source,
			'is_blocked' => 0,
			'order_id'   => $order_id,
			'date_added' => current_time( 'mysql', 1 ),
		);

		$insert_data = array();

		if ( ! empty( $phone ) ) {
			$insert_data['phone_number']     = $phone;
			$insert_data['normalized_phone'] = $normalized_phone;
		}

		if ( ! empty( $email ) && is_email( $email ) ) {
			$insert_data['email_address']    = $email;
			$insert_data['normalized_email'] = $normalized_email;
		}

		if ( $ip_blacklist_enabled && ! empty( $ip_address ) ) {
			$insert_data['ip_address'] = $ip_address;
		}

		if ( $premium_active && $customer_name_blocking_enabled && ( ! empty( $first_name ) || ! empty( $last_name ) ) ) {
			$insert_data['first_name'] = $first_name;
			$insert_data['last_name']  = $last_name;
		}

		$insert_data = array_merge( $contact_data, $insert_data );

		$created_main_ids    = array();
		$created_address_ids = array();
		$new_blacklist_id    = 0;

		// Main suspect row (phone/email/ip/name).
		if ( ! empty( $insert_data ) ) {
			$wpdb->insert( $table_name, $insert_data );
			$new_blacklist_id = (int) $wpdb->insert_id;

			if ( $new_blacklist_id > 0 ) {
				$created_main_ids[] = $new_blacklist_id;

				if ( $premium_active && 'host' === get_option( 'wc_blacklist_connection_mode' ) ) {
					$customer_domain = '';
					$is_blocked      = 0;

					$site_url     = site_url();
					$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
					$sources      = $clean_domain . '[' . $new_blacklist_id . ']';

					if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, '', $first_name, $last_name ) ) ) {
						wp_schedule_single_event(
							time() + 5,
							'wc_blacklist_connection_send_to_subsite',
							array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, '', $first_name, $last_name )
						);
					}
				}

				if ( $premium_active && 'sub' === get_option( 'wc_blacklist_connection_mode' ) ) {
					if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) );
					}
				}
			}
		}

		// Billing address suspect row.
		if ( $premium_active && $address_blocking_enabled && ! empty( $billing_address_data['address_full_norm'] ) ) {
			$existing_billing_id = $this->get_existing_address_row_id( $address_table_name, $billing_address_data, 0 );

			if ( 0 === $existing_billing_id ) {
				$billing_address_id = $this->insert_address_row(
					$address_table_name,
					$new_blacklist_id > 0 ? $new_blacklist_id : null,
					$billing_address_data,
					0,
					''
				);

				if ( $billing_address_id > 0 ) {
					$created_address_ids[] = $billing_address_id;

					if ( 'host' === get_option( 'wc_blacklist_connection_mode' ) ) {
						$site_url     = site_url();
						$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
						$sources      = $clean_domain . '[address:' . $billing_address_id . ']';

						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( '', '', '', '', 0, $sources, $billing_address_data['address_display'], '', '' ) ) ) {
							wp_schedule_single_event(
								time() + 5,
								'wc_blacklist_connection_send_to_subsite',
								array( '', '', '', '', 0, $sources, $billing_address_data['address_display'], '', '' )
							);
						}
					}

					if ( 'sub' === get_option( 'wc_blacklist_connection_mode' ) ) {
						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $billing_address_id ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $billing_address_id ) );
						}
					}
				}
			}
		}

		// Shipping address suspect row.
		if (
			$premium_active &&
			$address_blocking_enabled &&
			$shipping_blocking_enabled &&
			! empty( $shipping_address_data['address_full_norm'] ) &&
			$shipping_address_data['address_hash'] !== $billing_address_data['address_hash']
		) {
			$existing_shipping_id = $this->get_existing_address_row_id( $address_table_name, $shipping_address_data, 0 );

			if ( 0 === $existing_shipping_id ) {
				$shipping_address_id = $this->insert_address_row(
					$address_table_name,
					$new_blacklist_id > 0 ? $new_blacklist_id : null,
					$shipping_address_data,
					0,
					''
				);

				if ( $shipping_address_id > 0 ) {
					$created_address_ids[] = $shipping_address_id;

					if ( 'host' === get_option( 'wc_blacklist_connection_mode' ) ) {
						$site_url     = site_url();
						$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
						$sources      = $clean_domain . '[address:' . $shipping_address_id . ']';

						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( '', '', '', '', 0, $sources, $shipping_address_data['address_display'], '', '' ) ) ) {
							wp_schedule_single_event(
								time() + 5,
								'wc_blacklist_connection_send_to_subsite',
								array( '', '', '', '', 0, $sources, $shipping_address_data['address_display'], '', '' )
							);
						}
					}

					if ( 'sub' === get_option( 'wc_blacklist_connection_mode' ) ) {
						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $shipping_address_id ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $shipping_address_id ) );
						}
					}
				}
			}
		}

		if ( ! empty( $created_main_ids ) || ! empty( $created_address_ids ) ) {
			foreach ( $created_main_ids as $main_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_suspect_ids_main', $main_id );
			}

			foreach ( $created_address_ids as $address_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_suspect_ids_address', $address_id );
			}

			// Clear blocked references when switching this order to suspect entries.
			$order->delete_meta_data( '_blacklist_blocked_ids_main' );
			$order->delete_meta_data( '_blacklist_blocked_ids_address' );

			// Legacy backward compatibility cleanup.
			$order->delete_meta_data( '_blacklist_blocked_id' );

			$current_user = wp_get_current_user();
			$shop_manager = $current_user->display_name;

			$order->add_order_note(
				sprintf(
					esc_html__( 'Added to the suspect list by %s.', 'wc-blacklist-manager' ),
					$shop_manager
				),
				false
			);

			$order->save();

			if ( $premium_active ) {
				$details   = 'suspected_added_to_suspects_list_by:' . $shop_manager;
				$view_json = '';

				$wpdb->insert(
					$table_detection_log,
					array(
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'woo_order_' . $order_id,
						'action'    => 'suspect',
						'details'   => $details,
						'view'      => $view_json,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}

			echo esc_html__( 'Added to suspects list successfully.', 'wc-blacklist-manager' );
		} else {
			echo esc_html__( 'Nothing to add to the suspects list.', 'wc-blacklist-manager' );
		}

		wp_die();
	}

	public function handle_add_to_blocklist() {
		check_ajax_referer( $this->block_nonce_key, 'nonce' );

		global $wpdb;
		$table_name          = $wpdb->prefix . 'wc_blacklist';
		$address_table_name  = $wpdb->prefix . 'wc_blacklist_addresses';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

		// --- Read + sanitize reason/description ---
		$allowed_reasons = array( 'stolen_card', 'chargeback', 'fraud_network', 'spam', 'policy_abuse', 'other' );
		$reason_code_raw = isset( $_POST['reason_code'] ) ? wp_unslash( $_POST['reason_code'] ) : '';
		$reason_code     = sanitize_key( $reason_code_raw );

		if ( ! in_array( $reason_code, $allowed_reasons, true ) ) {
			wp_die();
		}

		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		$reason_labels = array(
			'stolen_card'   => __( 'Stolen card', 'wc-blacklist-manager' ),
			'chargeback'    => __( 'Chargeback', 'wc-blacklist-manager' ),
			'fraud_network' => __( 'Fraud network', 'wc-blacklist-manager' ),
			'spam'          => __( 'Spam', 'wc-blacklist-manager' ),
			'policy_abuse'  => __( 'Policy abuse', 'wc-blacklist-manager' ),
			'other'         => __( 'Other', 'wc-blacklist-manager' ),
		);
		$reason_label = isset( $reason_labels[ $reason_code ] ) ? $reason_labels[ $reason_code ] : $reason_code;

		if ( 'other' === $reason_code && '' === $description ) {
			wp_die( esc_html__( 'Please provide a description for “Other”.', 'wc-blacklist-manager' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( $order_id <= 0 ) {
			echo esc_html__( 'Invalid order ID.', 'wc-blacklist-manager' );
			wp_die();
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo esc_html__( 'Invalid order.', 'wc-blacklist-manager' );
			wp_die();
		}

		$current_user = wp_get_current_user();
		$shop_manager = $current_user && $current_user->exists() ? $current_user->display_name : __( 'System', 'wc-blacklist-manager' );

		$suspect_main_ids    = function_exists( 'yobm_parse_meta_id_list' ) ? yobm_parse_meta_id_list( $order->get_meta( '_blacklist_suspect_ids_main', true ) ) : array();
		$suspect_address_ids = function_exists( 'yobm_parse_meta_id_list' ) ? yobm_parse_meta_id_list( $order->get_meta( '_blacklist_suspect_ids_address', true ) ) : array();

		// Legacy fallback.
		if ( empty( $suspect_main_ids ) ) {
			$legacy_suspect_ids = $order->get_meta( '_blacklist_suspect_id', true );
			if ( function_exists( 'yobm_parse_meta_id_list' ) ) {
				$suspect_main_ids = yobm_parse_meta_id_list( $legacy_suspect_ids );
			}
		}

		$moved_main_ids    = array();
		$moved_address_ids = array();

		// Existing suspect entries -> move to blocked.
		if ( ! empty( $suspect_main_ids ) || ! empty( $suspect_address_ids ) ) {

			foreach ( $suspect_main_ids as $bid ) {
				$updated = $wpdb->update(
					$table_name,
					array(
						'is_blocked'  => 1,
						'reason_code' => $reason_code,
						'description' => $description,
					),
					array( 'id' => $bid ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);

				if ( false !== $updated ) {
					$moved_main_ids[] = (int) $bid;

					if ( $premium_active && 'host' === get_option( 'wc_blacklist_connection_mode' ) ) {
						$is_blocked  = 1;
						$site_url    = site_url();
						$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
						$sources     = $clean_domain . '[' . $bid . ']';

						if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) );
						}
					}

					if ( $premium_active && 'sub' === get_option( 'wc_blacklist_connection_mode' ) ) {
						if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_hostsite', array( $bid ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_hostsite', array( $bid ) );
						}
					}
				}
			}

			foreach ( $suspect_address_ids as $aid ) {
				$updated = $wpdb->update(
					$address_table_name,
					array(
						'is_blocked' => 1,
						'notes'      => $description,
					),
					array( 'id' => $aid ),
					array( '%d', '%s' ),
					array( '%d' )
				);

				if ( false !== $updated ) {
					$moved_address_ids[] = (int) $aid;

					if ( $premium_active && 'host' === get_option( 'wc_blacklist_connection_mode' ) ) {
						$is_blocked  = 1;
						$site_url    = site_url();
						$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
						$sources     = $clean_domain . '[address:' . $aid . ']';

						if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) );
						}
					}

					if ( $premium_active && 'sub' === get_option( 'wc_blacklist_connection_mode' ) ) {
						if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_hostsite', array( $aid ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_hostsite', array( $aid ) );
						}
					}
				}
			}

			// Optionally update the user meta for user blocking if enabled.
			$user_id      = $order->get_user_id();
			$user_blocked = false;

			if ( $user_id && get_option( 'wc_blacklist_enable_user_blocking' ) == '1' ) {
				$user = get_userdata( $user_id );

				if ( $user && ! in_array( 'administrator', (array) $user->roles, true ) ) {
					$user_blocked = update_user_meta( $user_id, 'user_blocked', '1' );
				}
			}

			// Move order meta from suspect -> blocked.
			$order->delete_meta_data( '_blacklist_suspect_ids_main' );
			$order->delete_meta_data( '_blacklist_suspect_ids_address' );
			$order->delete_meta_data( '_blacklist_suspect_id' ); // legacy

			foreach ( $moved_main_ids as $main_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_blocked_ids_main', $main_id );
			}

			foreach ( $moved_address_ids as $address_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_blocked_ids_address', $address_id );
			}

			$note_desc = '' !== $description ? ' — ' . $description : '';
			$order->add_order_note(
				sprintf(
					esc_html__( 'Moved to blocklist by %1$s. Reason: %2$s%3$s', 'wc-blacklist-manager' ),
					$shop_manager,
					$reason_label,
					$note_desc
				),
				false
			);

			$order->save();

			if ( YOGB_BM_Report::is_ready() ) {
				YOGB_BM_Report::queue_report_from_order( $order, $reason_code, $description );
			}

			if ( $premium_active ) {
				$details = 'blocked_added_to_blocklist_by:' . $shop_manager;
				if ( ! empty( $user_blocked ) ) {
					$details .= ', blocked_user_attempt:' . $user_id;
				}

				$view_json = wp_json_encode(
					array(
						'reason_code' => $reason_code,
						'description' => $description,
					)
				);

				$wpdb->insert(
					$table_detection_log,
					array(
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'woo_order_' . $order_id,
						'action'    => 'block',
						'details'   => $details,
						'view'      => $view_json,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}

			echo esc_html__( 'Moved to the blocklist successfully.', 'wc-blacklist-manager' );
			wp_die();
		}

		// No suspect refs yet -> create new blocked records from the order.
		$ip_blacklist_enabled           = get_option( 'wc_blacklist_ip_enabled', 0 );
		$address_blocking_enabled       = get_option( 'wc_blacklist_enable_customer_address_blocking', 0 );
		$shipping_blocking_enabled      = get_option( 'wc_blacklist_enable_shipping_address_blocking', 0 );
		$customer_name_blocking_enabled = get_option( 'wc_blacklist_customer_name_blocking_enabled', 0 );

		$phone      = sanitize_text_field( $order->get_billing_phone() );
		$email      = sanitize_email( $order->get_billing_email() );
		$ip_address = sanitize_text_field( $order->get_customer_ip_address() );

		$normalized_email = '';
		if ( ! empty( $email ) && is_email( $email ) ) {
			$normalized_email = yobm_normalize_email( $email );
		}

		$source = sprintf( __( 'Order ID: %d', 'wc-blacklist-manager' ), $order_id );

		$first_name = sanitize_text_field( $order->get_billing_first_name() );
		$last_name  = sanitize_text_field( $order->get_billing_last_name() );

		$billing_country = sanitize_text_field( $order->get_billing_country() );

		$address_payloads      = $this->get_order_address_payloads( $order );
		$billing_address_data  = $address_payloads['billing'];
		$shipping_address_data = $address_payloads['shipping'];

		$billing_dial_code = yobm_get_country_dial_code( $billing_country );

		$normalized_phone = '';
		if ( ! empty( $phone ) ) {
			$normalized_phone = yobm_normalize_phone( $phone, $billing_dial_code );
		}

		$contact_data = array(
			'sources'     => $source,
			'is_blocked'  => 1,
			'order_id'    => $order_id,
			'reason_code' => $reason_code,
			'description' => $description,
			'date_added'  => current_time( 'mysql', 1 ),
		);

		$insert_data = array();

		if ( ! empty( $phone ) ) {
			$insert_data['phone_number']     = $phone;
			$insert_data['normalized_phone'] = $normalized_phone;
		}

		if ( ! empty( $email ) && is_email( $email ) ) {
			$insert_data['email_address']    = $email;
			$insert_data['normalized_email'] = $normalized_email;
		}

		if ( $ip_blacklist_enabled && ! empty( $ip_address ) ) {
			$insert_data['ip_address'] = $ip_address;
		}

		if ( $premium_active && $customer_name_blocking_enabled && ( ! empty( $first_name ) || ! empty( $last_name ) ) ) {
			$insert_data['first_name'] = $first_name;
			$insert_data['last_name']  = $last_name;
		}

		$insert_data = array_merge( $contact_data, $insert_data );

		$created_main_ids    = array();
		$created_address_ids = array();
		$new_blacklist_id    = 0;

		// Main blocked row.
		if ( ! empty( $insert_data ) ) {
			$wpdb->insert( $table_name, $insert_data );
			$new_blacklist_id = (int) $wpdb->insert_id;

			if ( $new_blacklist_id > 0 ) {
				$created_main_ids[] = $new_blacklist_id;
			}
		}

		// Optionally update the user meta for user blocking if enabled.
		$user_id      = $order->get_user_id();
		$user_blocked = false;

		if ( $user_id && get_option( 'wc_blacklist_enable_user_blocking' ) == '1' ) {
			$user = get_userdata( $user_id );

			if ( $user && ! in_array( 'administrator', (array) $user->roles, true ) ) {
				$user_blocked = update_user_meta( $user_id, 'user_blocked', '1' );
			}
		}

		if ( $premium_active && 'host' === get_option( 'wc_blacklist_connection_mode' ) && $new_blacklist_id > 0 ) {
			$customer_domain = '';
			$is_blocked      = 1;

			$site_url     = site_url();
			$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
			$sources      = $clean_domain . '[' . $new_blacklist_id . ']';

			if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, '', $first_name, $last_name ) ) ) {
				wp_schedule_single_event(
					time() + 5,
					'wc_blacklist_connection_send_to_subsite',
					array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, '', $first_name, $last_name )
				);
			}
		}

		if ( $premium_active && 'sub' === get_option( 'wc_blacklist_connection_mode' ) && $new_blacklist_id > 0 ) {
			if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) ) ) {
				wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) );
			}
		}

		// Billing address blocked row.
		if ( $premium_active && $address_blocking_enabled && ! empty( $billing_address_data['address_full_norm'] ) ) {
			$existing_billing_id = $this->get_existing_address_row_id( $address_table_name, $billing_address_data, 1 );

			if ( 0 === $existing_billing_id ) {
				$billing_address_id = $this->insert_address_row(
					$address_table_name,
					$new_blacklist_id > 0 ? $new_blacklist_id : null,
					$billing_address_data,
					1,
					$description
				);

				if ( $billing_address_id > 0 ) {
					$created_address_ids[] = $billing_address_id;

					if ( 'host' === get_option( 'wc_blacklist_connection_mode' ) ) {
						$site_url     = site_url();
						$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
						$sources      = $clean_domain . '[address:' . $billing_address_id . ']';

						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( '', '', '', '', 1, $sources, $billing_address_data['address_display'], '', '' ) ) ) {
							wp_schedule_single_event(
								time() + 5,
								'wc_blacklist_connection_send_to_subsite',
								array( '', '', '', '', 1, $sources, $billing_address_data['address_display'], '', '' )
							);
						}
					}

					if ( 'sub' === get_option( 'wc_blacklist_connection_mode' ) ) {
						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $billing_address_id ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $billing_address_id ) );
						}
					}
				}
			}
		}

		// Shipping address blocked row.
		if (
			$premium_active &&
			$address_blocking_enabled &&
			$shipping_blocking_enabled &&
			! empty( $shipping_address_data['address_full_norm'] ) &&
			$shipping_address_data['address_hash'] !== $billing_address_data['address_hash']
		) {
			$existing_shipping_id = $this->get_existing_address_row_id( $address_table_name, $shipping_address_data, 1 );

			if ( 0 === $existing_shipping_id ) {
				$shipping_address_id = $this->insert_address_row(
					$address_table_name,
					$new_blacklist_id > 0 ? $new_blacklist_id : null,
					$shipping_address_data,
					1,
					$description
				);

				if ( $shipping_address_id > 0 ) {
					$created_address_ids[] = $shipping_address_id;

					if ( 'host' === get_option( 'wc_blacklist_connection_mode' ) ) {
						$site_url     = site_url();
						$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
						$sources      = $clean_domain . '[address:' . $shipping_address_id . ']';

						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( '', '', '', '', 1, $sources, $shipping_address_data['address_display'], '', '' ) ) ) {
							wp_schedule_single_event(
								time() + 5,
								'wc_blacklist_connection_send_to_subsite',
								array( '', '', '', '', 1, $sources, $shipping_address_data['address_display'], '', '' )
							);
						}
					}

					if ( 'sub' === get_option( 'wc_blacklist_connection_mode' ) ) {
						if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $shipping_address_id ) ) ) {
							wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $shipping_address_id ) );
						}
					}
				}
			}
		}

		if ( ! empty( $created_main_ids ) || ! empty( $created_address_ids ) ) {
			foreach ( $created_main_ids as $main_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_blocked_ids_main', $main_id );
			}

			foreach ( $created_address_ids as $address_id ) {
				yobm_add_order_blacklist_meta_id( $order, '_blacklist_blocked_ids_address', $address_id );
			}

			// Clear suspect references when switching this order to blocked entries.
			$order->delete_meta_data( '_blacklist_suspect_ids_main' );
			$order->delete_meta_data( '_blacklist_suspect_ids_address' );
			$order->delete_meta_data( '_blacklist_suspect_id' ); // legacy

			$note_desc = '' !== $description ? ' — ' . $description : '';
			$order->add_order_note(
				sprintf(
					esc_html__( 'Added to blocklist by %1$s. Reason: %2$s%3$s', 'wc-blacklist-manager' ),
					$shop_manager,
					$reason_label,
					$note_desc
				),
				false
			);

			$order->save();

			if ( YOGB_BM_Report::is_ready() ) {
				YOGB_BM_Report::queue_report_from_order( $order, $reason_code, $description );
			}

			if ( $premium_active ) {
				$details = 'blocked_added_to_blocklist_by:' . $shop_manager;
				if ( ! empty( $user_blocked ) ) {
					$details .= ', blocked_user_attempt:' . $user_id;
				}

				$view_json = wp_json_encode(
					array(
						'reason_code' => $reason_code,
						'description' => $description,
					)
				);

				$wpdb->insert(
					$table_detection_log,
					array(
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'woo_order_' . $order_id,
						'action'    => 'block',
						'details'   => $details,
						'view'      => $view_json,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}

			echo esc_html__( 'Added to blocklist successfully.', 'wc-blacklist-manager' );
		} else {
			echo esc_html__( 'Nothing to add to the blocklist.', 'wc-blacklist-manager' );
		}

		wp_die();
	}
	
	public function display_blacklist_notices( WC_Order $order ) {
		global $wpdb;

		$table         = $wpdb->prefix . 'wc_blacklist';
		$address_table = $wpdb->prefix . 'wc_blacklist_addresses';

		$ip_enabled      = get_option( 'wc_blacklist_ip_enabled', 0 );
		$address_enabled = get_option( 'wc_blacklist_enable_customer_address_blocking', 0 );
		$name_enabled    = get_option( 'wc_blacklist_customer_name_blocking_enabled', 0 );
		$premium_active  = ( new WC_Blacklist_Manager_Settings() )->is_premium_active();

		$phone = sanitize_text_field( $order->get_billing_phone() );
		$email = sanitize_email( $order->get_billing_email() );

		if ( $premium_active ) {
			$ip = get_post_meta( $order->get_id(), '_customer_ip_address', true );
			if ( empty( $ip ) ) {
				$ip = sanitize_text_field( $order->get_customer_ip_address() );
			}
		} else {
			$ip = sanitize_text_field( $order->get_customer_ip_address() );
		}

		$first_name = sanitize_text_field( $order->get_billing_first_name() );
		$last_name  = sanitize_text_field( $order->get_billing_last_name() );
		$full_name  = trim( $first_name . ' ' . $last_name );

		$billing_country   = sanitize_text_field( $order->get_billing_country() );
		$billing_dial_code = yobm_get_country_dial_code( $billing_country );

		$normalized_phone = '';
		if ( ! empty( $phone ) ) {
			$normalized_phone = yobm_normalize_phone( $phone, $billing_dial_code );
		}

		$normalized_email = '';
		if ( ! empty( $email ) && is_email( $email ) ) {
			$normalized_email = yobm_normalize_email( $email );
		}

		$address_payloads      = $this->get_order_address_payloads( $order );
		$billing_address_data  = $address_payloads['billing'];
		$shipping_address_data = $address_payloads['shipping'];

		$blocked = array();
		$suspect = array();

		$check_main_identity = function( string $label, string $where_sql, array $params ) use ( $wpdb, $table, &$blocked, &$suspect ) {
			$blocked_sql = "SELECT 1 FROM {$table} WHERE is_blocked = 1 AND ( {$where_sql} ) LIMIT 1";
			$suspect_sql = "SELECT 1 FROM {$table} WHERE is_blocked = 0 AND ( {$where_sql} ) LIMIT 1";

			$is_blocked = (bool) $wpdb->get_var( $wpdb->prepare( $blocked_sql, $params ) );

			if ( $is_blocked ) {
				$blocked[] = $label;
				return;
			}

			$is_suspect = (bool) $wpdb->get_var( $wpdb->prepare( $suspect_sql, $params ) );

			if ( $is_suspect ) {
				$suspect[] = $label;
			}
		};

		if ( $name_enabled && $premium_active && $full_name ) {
			$check_main_identity(
				sprintf( __( 'customer name (%s)', 'wc-blacklist-manager' ), $full_name ),
				"CONCAT(first_name,' ',last_name) = %s",
				array( $full_name )
			);
		}

		if ( $phone || $normalized_phone ) {
			$check_main_identity(
				__( 'phone', 'wc-blacklist-manager' ),
				"( phone_number = %s OR ( %s <> '' AND normalized_phone = %s ) )",
				array( $phone, $normalized_phone, $normalized_phone )
			);
		}

		if ( $email && is_email( $email ) ) {
			$check_main_identity(
				__( 'email', 'wc-blacklist-manager' ),
				"( email_address = %s OR ( %s <> '' AND normalized_email = %s ) )",
				array( $email, $normalized_email, $normalized_email )
			);
		}

		if ( $ip_enabled && $ip ) {
			$check_main_identity(
				__( 'IP', 'wc-blacklist-manager' ),
				"ip_address = %s",
				array( $ip )
			);
		}

		if ( $address_enabled && $premium_active && ! empty( $billing_address_data['address_full_norm'] ) ) {
			$billing_state = $this->get_address_match_state( $address_table, $billing_address_data );

			if ( $billing_state['blocked_exact'] ) {
				$blocked[] = __( 'billing address', 'wc-blacklist-manager' );
			} elseif ( $billing_state['suspect_exact'] ) {
				$suspect[] = __( 'billing address', 'wc-blacklist-manager' );
			} elseif ( $billing_state['blocked_core'] ) {
				$blocked[] = __( 'billing address (core match)', 'wc-blacklist-manager' );
			} elseif ( $billing_state['suspect_core'] ) {
				$suspect[] = __( 'billing address (core match)', 'wc-blacklist-manager' );
			}
		}

		if (
			$address_enabled &&
			$premium_active &&
			get_option( 'wc_blacklist_enable_shipping_address_blocking', 0 ) &&
			! empty( $shipping_address_data['address_full_norm'] ) &&
			$shipping_address_data['address_hash'] !== $billing_address_data['address_hash']
		) {
			$shipping_state = $this->get_address_match_state( $address_table, $shipping_address_data );

			if ( $shipping_state['blocked_exact'] ) {
				$blocked[] = __( 'shipping address', 'wc-blacklist-manager' );
			} elseif ( $shipping_state['suspect_exact'] ) {
				$suspect[] = __( 'shipping address', 'wc-blacklist-manager' );
			} elseif ( $shipping_state['blocked_core'] ) {
				$blocked[] = __( 'shipping address (core match)', 'wc-blacklist-manager' );
			} elseif ( $shipping_state['suspect_core'] ) {
				$suspect[] = __( 'shipping address (core match)', 'wc-blacklist-manager' );
			}
		}

		$blocked = array_values( array_unique( array_filter( $blocked ) ) );
		$suspect = array_values( array_unique( array_filter( $suspect ) ) );

		if ( ! empty( $blocked ) ) {
			$msg = sprintf(
				__( "This order's %s in the blocklist.", 'wc-blacklist-manager' ),
				implode( ', ', $blocked )
			);

			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}

		if ( ! empty( $suspect ) ) {
			$msg = sprintf(
				__( "This order's %s in the suspect list.", 'wc-blacklist-manager' ),
				implode( ', ', $suspect )
			);

			echo '<div class="notice notice-warning"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}
}

new WC_Blacklist_Manager_Order_Actions();
