<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Button_Add_To_Blacklist {
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

		$show_suspect_button = $this->should_show_suspect_button($phone, $email, $ip_address);
		$show_block_button = $this->should_show_block_button($phone, $email, $ip_address);

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

	public function add_button_to_order_edit($order) {
		if ( method_exists( $order, 'get_type' ) && $order->get_type() === 'shop_subscription' ) {
			return;
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if ($premium_active || !current_user_can('manage_options')) {
			return;
		}
		
		$phone = sanitize_text_field($order->get_billing_phone());
		$email = sanitize_email($order->get_billing_email());
		$ip_address = sanitize_text_field($order->get_customer_ip_address());

		$show_suspect_button = $this->should_show_suspect_button($phone, $email, $ip_address);
		$show_block_button = $this->should_show_block_button($phone, $email, $ip_address);
		$show_remove_button_suspect = $order->get_meta('_blacklist_suspect_id', true);
		$show_remove_button_block = $order->get_meta('_blacklist_blocked_id', true);

		echo '<div style="margin-top: 12px;" class="bm_order_actions">';
		echo '<h3>' . esc_html__('Blacklist actions', 'wc-blacklist-manager') . '</h3>';
		echo '<p>';
		if ($show_suspect_button) {
			echo '<button id="add_to_blacklist" class="button button-secondary icon-button" title="' . esc_html__('Add to the suspects list', 'wc-blacklist-manager') . '"><span class="dashicons dashicons-flag" style="margin-right: 3px;"></span> ' . esc_html__('Suspect', 'wc-blacklist-manager') . '</button>';
		}
		if ($show_block_button) {
			echo '<button id="block_customer" class="button red-button" title="' . esc_html__('Add to blocklist', 'wc-blacklist-manager') . '"><span class="dashicons dashicons-dismiss" style="margin-right: 3px;"></span> ' . esc_html__('Block', 'wc-blacklist-manager') . '</button>';
		} elseif (!$show_block_button && !$show_remove_button_block) {
			echo '<span style="color:#b32d2e;">' . esc_html__('This customer is already blocked.', 'wc-blacklist-manager') . '</span>';
		}
		if ($show_remove_button_suspect || $show_remove_button_block) {
			echo '<button class="button button-secondary icon-button" title="' . esc_html__('Remove', 'wc-blacklist-manager') . '" disabled><span class="dashicons dashicons-remove" style="margin-right: 3px;"></span> ' . esc_html__('Remove', 'wc-blacklist-manager') . '</button>';
		}
		echo '</p>';
		echo '<p class="bm_description"><a href="https://yoohw.com/product/blacklist-manager-premium/" target="_blank" title="Upgrade Premium"><span class="yoohw_unlock">' . 'Unlock' . '</span></a> to power up the blacklist actions.' . '</p>';
		echo '</div>';

		// Simple inline modal (hidden by default)
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

	private function should_show_block_button($phone, $email, $ip_address) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
	
		$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', 0);
	
		// Check if phone exists and is blocked
		if (!empty($phone)) {
			$phone_blocked = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE phone_number = %s AND is_blocked = 1 LIMIT 1", $phone));
		} else {
			$phone_blocked = true;
		}
	
		// Check if email exists and is blocked
		if (!empty($email)) {
			$email_blocked = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 1 LIMIT 1", $email));
		} else {
			$email_blocked = true;
		}
	
		// Check if IP exists and is blocked
		if ($ip_blacklist_enabled && !empty($ip_address)) {
			$ip_blocked = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE ip_address = %s AND is_blocked = 1 LIMIT 1", $ip_address));
		} else {
			$ip_blocked = true;
		}
	
		// Check if all fields exist and are blocked
		$all_fields_blocked = $phone_blocked && $email_blocked && $ip_blocked;
	
		// Hide the button if all fields exist and are blocked or not all fields exist
		if ($all_fields_blocked) {
			return false;
		}
	
		// Display the button in other cases
		return true;
	}

	private function should_show_suspect_button($phone, $email, $ip_address) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
	
		$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', 0);
	
		// Check if phone exists and is blocked
		if (!empty($phone)) {
			$phone_exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE phone_number = %s LIMIT 1", $phone));
		} else {
			$phone_exists = true; // If phone is empty, we exclude it
		}
	
		// Check if email exists and is blocked
		if (!empty($email)) {
			$email_exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE email_address = %s LIMIT 1", $email));
		} else {
			$email_exists = true; // If email is empty, we exclude it
		}
	
		// Check if IP exists and is blocked
		if ($ip_blacklist_enabled && !empty($ip_address)) {
			$ip_exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE ip_address = %s LIMIT 1", $ip_address));
		} else {
			$ip_exists = true; // If IP blocking is disabled or IP is empty, we exclude it
		}
	
		// Check if all fields exist and are blocked
		$all_fields_exist = $phone_exists && $email_exists && $ip_exists;
	
		// Hide the button if all fields exist and are blocked or not all fields exist
		if ($all_fields_exist) {
			return false;
		}
	
		// Display the button in other cases
		return true;
	}	

	public function handle_add_to_suspects() {
		check_ajax_referer($this->suspect_nonce_key, 'nonce');
	
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
	
		$allowed_roles = get_option('wc_blacklist_dashboard_permission', []);
		$user_has_permission = false;
		if (is_array($allowed_roles) && !empty($allowed_roles)) {
			foreach ($allowed_roles as $role) {
				if (current_user_can($role)) {
					$user_has_permission = true;
					break;
				}
			}
		}
	
		if (!$user_has_permission && !current_user_can('manage_options')) {
			return;
		}
	
		$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
		if ($order_id <= 0) {
			echo esc_html__('Invalid order ID.', 'wc-blacklist-manager');
			wp_die();
		}
	
		$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', 0);
		$address_blocking_enabled = get_option('wc_blacklist_enable_customer_address_blocking', 0);
		$shipping_blocking_enabled = get_option('wc_blacklist_enable_shipping_address_blocking', 0);
		$customer_name_blocking_enabled = get_option('wc_blacklist_customer_name_blocking_enabled', 0);
	
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
	
		$order = wc_get_order($order_id);
		$phone = sanitize_text_field($order->get_billing_phone());
		$email = sanitize_email($order->get_billing_email());
		$ip_address = sanitize_text_field($order->get_customer_ip_address());

		$source = sprintf(__('Order ID: %d', 'wc-blacklist-manager'), $order_id);
	
		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
	
		$address_1 = sanitize_text_field($order->get_billing_address_1());
		$address_2 = sanitize_text_field($order->get_billing_address_2());
		$city = sanitize_text_field($order->get_billing_city());
		$state = sanitize_text_field($order->get_billing_state());
		$postcode = sanitize_text_field($order->get_billing_postcode());
		$country = sanitize_text_field($order->get_billing_country());
		$address_parts = array_filter([$address_1, $address_2, $city, $state, $postcode, $country]);
		$customer_address = implode(', ', $address_parts);
	
		$shipping_address_1 = sanitize_text_field($order->get_shipping_address_1());
		$shipping_address_2 = sanitize_text_field($order->get_shipping_address_2());
		$shipping_city = sanitize_text_field($order->get_shipping_city());
		$shipping_state = sanitize_text_field($order->get_shipping_state());
		$shipping_postcode = sanitize_text_field($order->get_shipping_postcode());
		$shipping_country = sanitize_text_field($order->get_shipping_country());
		$shipping_address_parts = array_filter([$shipping_address_1, $shipping_address_2, $shipping_city, $shipping_state, $shipping_postcode, $shipping_country]);
		$shipping_address = implode(', ', $shipping_address_parts);
	
		// Initialize contact data
		$contact_data = [
			'sources'    => $source,
			'is_blocked' => 0,
			'order_id'   => $order_id,
			'date_added' => current_time('mysql', 1)
		];
	
		$insert_data = [];
	
		// Insert phone number if not empty
		if (!empty($phone)) {
			$insert_data['phone_number'] = $phone;
		}
	
		// Insert email address if not empty
		if (!empty($email)) {
			$insert_data['email_address'] = $email;
			if ($premium_active) {
				$normalized_email = yobmp_normalize_email( $email );
				$insert_data['normalized_email'] = $normalized_email;
			}
		}
	
		// Insert IP address if enabled and not empty
		if ($ip_blacklist_enabled && !empty($ip_address)) {
			$insert_data['ip_address'] = $ip_address;
		}
	
		// Insert customer address if premium is active, address blocking enabled, and not empty
		if ($premium_active && $address_blocking_enabled && !empty($customer_address)) {
			$insert_data['customer_address'] = $customer_address;
		}

		// Insert customer name if premium is active, customer name blocking enabled, and both first and last name are not empty
		if ($premium_active && $customer_name_blocking_enabled) {
			if (!empty($first_name) && !empty($last_name)) {
				$insert_data['first_name'] = $first_name;
				$insert_data['last_name']  = $last_name;
			}
		}		
	
		// Merge contact data with the insert data
		$insert_data = array_merge($contact_data, $insert_data);
	
		// Insert a new row if there are fields to insert
		if (!empty($insert_data)) {
			$wpdb->insert($table_name, $insert_data);
			$new_blacklist_id = $wpdb->insert_id;

			if ($premium_active && get_option('wc_blacklist_connection_mode') === 'host') {
				$customer_domain = '';
				$is_blocked = 0;
				
				$site_url = site_url();
				$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
				$sources = $clean_domain . '[' . $new_blacklist_id . ']';

				if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, $customer_address, $first_name, $last_name ) ) ) {
					wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_subsite', array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, $customer_address, $first_name, $last_name ) );
				}
			}

			if ($premium_active && get_option('wc_blacklist_connection_mode') === 'sub') {
				if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) ) ) {
					wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) );
				}
			}
	
			$current_meta = $order->get_meta('_blacklist_suspect_id', true);
			if (!empty($current_meta)) {
				$new_meta = $current_meta . ',' . $new_blacklist_id;
			} else {
				$new_meta = $new_blacklist_id;
			}
	
			$order->delete_meta_data('_blacklist_blocked_id');
			$order->update_meta_data('_blacklist_suspect_id', $new_meta);

			$current_user = wp_get_current_user();
			$shop_manager = $current_user->display_name;

			$order->add_order_note(
				sprintf(
					esc_html__('Added to the suspect list by %s.', 'wc-blacklist-manager'),
					$shop_manager
				),
				false
			);

			$order->save();
	
			if ( $premium_active ) {
				$details = 'suspected_added_to_suspects_list_by:' . $shop_manager;
				$view_json = '';

				$wpdb->insert(
					$table_detection_log,
					[
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'woo_order_' . $order_id,
						'action'    => 'suspect',
						'details'   => $details,
						'view'      => $view_json,
					],
					[ '%s', '%s', '%s', '%s', '%s', '%s' ]
				);
			}
			
			echo esc_html__('Added to suspects list successfully.', 'wc-blacklist-manager');
		} else {
			echo esc_html__('Nothing to add to the suspects list.', 'wc-blacklist-manager');
		}

		// Add shipping address to a new row if premium is active, shipping blocking enabled, not empty, and different from customer address
		if ($premium_active && $address_blocking_enabled && $shipping_blocking_enabled && !empty($shipping_address) && $shipping_address !== $customer_address) {
			$shipping_data = [
				'sources'          => sprintf(__('Order ID: %d | Shipping', 'wc-blacklist-manager'), $order_id),
				'customer_address' => $shipping_address,
				'is_blocked'       => 0,
				'order_id'         => $order_id,
				'date_added'       => current_time('mysql')
			];
			$wpdb->insert($table_name, $shipping_data);
			$shipping_blacklist_id = $wpdb->insert_id;

			if (get_option('wc_blacklist_connection_mode') === 'host') {
				$phone_holder = '';
				$email_holder = '';
				$ip_address_holder = '';
				$customer_domain_holder = '';
				$first_name_holder = '';
				$last_name_holder = '';
				$is_blocked_holder = 0;
				
				$site_url_holder = site_url();
				$clean_domain_holder = preg_replace( '/^https?:\/\//', '', $site_url_holder );
				$sources_holder = $clean_domain_holder . '[' . $shipping_blacklist_id . ']';

				if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $phone_holder, $email_holder, $ip_address_holder, $customer_domain_holder, $is_blocked_holder, $sources_holder, $shipping_address, $first_name_holder, $last_name_holder ) ) ) {
					wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_subsite', array( $phone_holder, $email_holder, $ip_address_holder, $customer_domain_holder, $is_blocked_holder, $sources_holder, $shipping_address, $first_name_holder, $last_name_holder ) );
				}
			}

			if (get_option('wc_blacklist_connection_mode') === 'sub') {
				if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $shipping_blacklist_id ) ) ) {
					wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $shipping_blacklist_id ) );
				}
			}
	
			$current_meta = $order->get_meta('_blacklist_suspect_id', true);
			if (!empty($current_meta)) {
				$new_meta = $current_meta . ',' . $shipping_blacklist_id;
			} else {
				$new_meta = $shipping_blacklist_id;
			}
	
			$order->update_meta_data('_blacklist_suspect_id', $new_meta);
			$order->save();
		}		
	
		wp_die();
	}

	public function handle_add_to_blocklist() {
		check_ajax_referer($this->block_nonce_key, 'nonce');
	
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		// --- Read + sanitize reason/description ---
		$allowed_reasons = [ 'stolen_card', 'chargeback', 'fraud_network', 'spam', 'policy_abuse', 'other' ];
		$reason_code_raw = isset($_POST['reason_code']) ? wp_unslash($_POST['reason_code']) : '';
		$reason_code     = sanitize_key($reason_code_raw);
		if ( ! in_array($reason_code, $allowed_reasons, true) ) {
			return;
		}
		$description = isset($_POST['description']) ? sanitize_textarea_field( wp_unslash($_POST['description']) ) : '';

		// Map to human label for notes/logs
		$reason_labels = [
			'stolen_card'   => __('Stolen card', 'wc-blacklist-manager'),
			'chargeback'    => __('Chargeback', 'wc-blacklist-manager'),
			'fraud_network' => __('Fraud network', 'wc-blacklist-manager'),
			'spam'          => __('Spam', 'wc-blacklist-manager'),
			'policy_abuse'  => __('Policy abuse', 'wc-blacklist-manager'),
			'other'         => __('Other', 'wc-blacklist-manager'),
		];
		$reason_label = isset($reason_labels[$reason_code]) ? $reason_labels[$reason_code] : $reason_code;
	
		if ( $reason_code === 'other' && $description === '' ) {
			wp_die( esc_html__('Please provide a description for “Other”.', 'wc-blacklist-manager') );
		}

		$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
		if ($order_id <= 0) {
			echo esc_html__('Invalid order ID.', 'wc-blacklist-manager');
			wp_die();
		}
	
		$order = wc_get_order($order_id);
	
		// Get the blacklist suspect IDs from order meta
		$blacklist_meta = $order->get_meta('_blacklist_suspect_id', true);

		$current_user = wp_get_current_user();
		$shop_manager = $current_user && $current_user->exists() ? $current_user->display_name : __('System', 'wc-blacklist-manager');
	
		if (!empty($blacklist_meta)) {
			// Explode the meta value to get an array of IDs (ensuring they're integers)
			$blacklist_ids = array_map('intval', explode(',', $blacklist_meta));
	
			// Loop through each ID and update the corresponding row in the table
			foreach ($blacklist_ids as $bid) {
				$wpdb->update(
					$table_name,
					[
						'is_blocked'   => 1,
						'reason_code'  => $reason_code,
						'description'  => $description,
					],
					[ 'id' => $bid ],
					[ '%d', '%s', '%s' ],
					[ '%d' ]
				);

				if ($premium_active && get_option('wc_blacklist_connection_mode') === 'host') {
					$is_blocked = 1;

					$site_url = site_url();
					$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
					$sources = $clean_domain . '[' . $bid . ']';

					if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) );
					}
				}

				if ($premium_active && get_option('wc_blacklist_connection_mode') === 'sub') {
					if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_hostsite', array( $bid ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_hostsite', array( $bid ) );
					}
				}
			}
	
			echo esc_html__('Moved to the blocklist successfully.', 'wc-blacklist-manager');
	
			// Optionally update the user meta for user blocking if enabled
			$user_id = $order->get_user_id();
			if ($user_id && get_option('wc_blacklist_enable_user_blocking') == '1') {
				$user = get_userdata($user_id);
			
				if ($user && !in_array('administrator', (array) $user->roles)) {
					update_user_meta($user_id, 'user_blocked', '1');
				}
			}
	
			// Delete the suspect meta and add blocked meta with the previous value
			$order->delete_meta_data('_blacklist_suspect_id');
			$order->update_meta_data('_blacklist_blocked_id', $blacklist_meta);

			$note_desc = $description !== '' ? ' — ' . $description : '';
			$order->add_order_note(
				sprintf(
					/* translators: 1: manager name, 2: reason label, 3: optional description */
					esc_html__('Moved to blocklist by %1$s. Reason: %2$s%3$s', 'wc-blacklist-manager'),
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
				$view_json = wp_json_encode( [
					'reason_code' => $reason_code,
					'description' => $description,
				] );

				$wpdb->insert(
					$table_detection_log,
					[
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'woo_order_' . $order_id,
						'action'    => 'block',
						'details'   => $details,
						'view'      => $view_json,
					],
					[ '%s', '%s', '%s', '%s', '%s', '%s' ]
				);
			}
		} else {
			$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', 0);
			$address_blocking_enabled = get_option('wc_blacklist_enable_customer_address_blocking', 0);
			$shipping_blocking_enabled = get_option('wc_blacklist_enable_shipping_address_blocking', 0);
			$customer_name_blocking_enabled = get_option('wc_blacklist_customer_name_blocking_enabled', 0);
		
			$phone = sanitize_text_field($order->get_billing_phone());
			$email = sanitize_email($order->get_billing_email());
			$ip_address = sanitize_text_field($order->get_customer_ip_address());

			$source = sprintf(__('Order ID: %d', 'wc-blacklist-manager'), $order_id);
		
			$first_name = sanitize_text_field($order->get_billing_first_name());
			$last_name = sanitize_text_field($order->get_billing_last_name());
		
			$address_1 = sanitize_text_field($order->get_billing_address_1());
			$address_2 = sanitize_text_field($order->get_billing_address_2());
			$city = sanitize_text_field($order->get_billing_city());
			$state = sanitize_text_field($order->get_billing_state());
			$postcode = sanitize_text_field($order->get_billing_postcode());
			$country = sanitize_text_field($order->get_billing_country());
			$address_parts = array_filter([$address_1, $address_2, $city, $state, $postcode, $country]);
			$customer_address = implode(', ', $address_parts);
		
			$shipping_address_1 = sanitize_text_field($order->get_shipping_address_1());
			$shipping_address_2 = sanitize_text_field($order->get_shipping_address_2());
			$shipping_city = sanitize_text_field($order->get_shipping_city());
			$shipping_state = sanitize_text_field($order->get_shipping_state());
			$shipping_postcode = sanitize_text_field($order->get_shipping_postcode());
			$shipping_country = sanitize_text_field($order->get_shipping_country());
			$shipping_address_parts = array_filter([$shipping_address_1, $shipping_address_2, $shipping_city, $shipping_state, $shipping_postcode, $shipping_country]);
			$shipping_address = implode(', ', $shipping_address_parts);
		
			// Initialize contact data
			$contact_data = [
				'sources'    => $source,
				'is_blocked' => 1,
				'order_id'   => $order_id,
				'reason_code'  => $reason_code,
				'description'  => $description,
				'date_added' => current_time('mysql', 1)
			];
		
			$insert_data = [];
		
			// Insert phone number if not empty
			if (!empty($phone)) {
				$insert_data['phone_number'] = $phone;
			}
		
			// Insert email address if not empty
			if (!empty($email)) {
				$insert_data['email_address'] = $email;
				if ($premium_active) {
					$normalized_email = yobmp_normalize_email( $email );
					$insert_data['normalized_email'] = $normalized_email;
				}
			}
		
			// Insert IP address if enabled and not empty
			if ($ip_blacklist_enabled && !empty($ip_address)) {
				$insert_data['ip_address'] = $ip_address;
			}
		
			// Insert customer address if premium is active, address blocking enabled, and not empty
			if ($premium_active && $address_blocking_enabled && !empty($customer_address)) {
				$insert_data['customer_address'] = $customer_address;
			}

			// Insert customer name if premium is active, customer name blocking enabled, and both first and last name are not empty
			if ($premium_active && $customer_name_blocking_enabled) {
				if (!empty($first_name) && !empty($last_name)) {
					$insert_data['first_name'] = $first_name;
					$insert_data['last_name']  = $last_name;
				}
			}		
		
			// Merge contact data with the insert data
			$insert_data = array_merge($contact_data, $insert_data);
		
			// Insert a new row if there are fields to insert
			if (!empty($insert_data)) {
				$wpdb->insert($table_name, $insert_data);
				$new_blacklist_id = $wpdb->insert_id;

				// Optionally update the user meta for user blocking if enabled
				$user_id = $order->get_user_id();
				$user_blocked = false;
				if ($user_id && get_option('wc_blacklist_enable_user_blocking') == '1') {
					$user = get_userdata($user_id);
				
					if ($user && !in_array('administrator', (array) $user->roles)) {
						$user_blocked = update_user_meta($user_id, 'user_blocked', '1');
					}
				}
				
				if ($premium_active && get_option('wc_blacklist_connection_mode') === 'host') {
					$customer_domain = '';
					$is_blocked = 1;
					
					$site_url = site_url();
					$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
					$sources = $clean_domain . '[' . $new_blacklist_id . ']';

					if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, $customer_address, $first_name, $last_name ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_subsite', array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, $customer_address, $first_name, $last_name ) );
					}
				}

				if ($premium_active && get_option('wc_blacklist_connection_mode') === 'sub') {
					if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $new_blacklist_id ) );
					}
				}
		
				$current_meta = $order->get_meta('_blacklist_blocked_id', true);
				if (!empty($current_meta)) {
					$new_meta = $current_meta . ',' . $new_blacklist_id;
				} else {
					$new_meta = $new_blacklist_id;
				}
		
				$order->update_meta_data('_blacklist_blocked_id', $new_meta);

				$note_desc = $description !== '' ? ' — ' . $description : '';
				$order->add_order_note(
					sprintf(
						/* translators: 1: manager name, 2: reason label, 3: optional description */
						esc_html__('Added to blocklist by %1$s. Reason: %2$s%3$s', 'wc-blacklist-manager'),
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
					$current_user = wp_get_current_user();
					$shop_manager = $current_user->display_name;

					$details = 'blocked_added_to_blocklist_by:' . $shop_manager;
					if ( $user_blocked ) {
						$details .= ', blocked_user_attempt:' . $user_id;
					}

					$view_json = wp_json_encode([
						'reason_code' => $reason_code,
						'description' => $description,
					]);

					$wpdb->insert(
						$table_detection_log,
						[
							'timestamp' => current_time( 'mysql' ),
							'type'      => 'human',
							'source'    => 'woo_order_' . $order_id,
							'action'    => 'block',
							'details'   => $details,
							'view'      => $view_json,
						],
						[ '%s', '%s', '%s', '%s', '%s', '%s' ]
					);
				}
				
				echo esc_html__('Added to blocklist successfully.', 'wc-blacklist-manager');
			} else {
				echo esc_html__('Nothing to add to the blocklist.', 'wc-blacklist-manager');
			}

			// Add shipping address to a new row if premium is active, shipping blocking enabled, not empty, and different from customer address
			if ($premium_active && $address_blocking_enabled && $shipping_blocking_enabled && !empty($shipping_address) && $shipping_address !== $customer_address) {
				$shipping_data = [
					'sources'          => sprintf(__('Order ID: %d | Shipping', 'wc-blacklist-manager'), $order_id),
					'customer_address' => $shipping_address,
					'is_blocked'       => 1,
					'order_id'         => $order_id,
					'date_added'       => current_time('mysql')
				];
				$wpdb->insert($table_name, $shipping_data);
				$shipping_blacklist_id = $wpdb->insert_id;

				if (get_option('wc_blacklist_connection_mode') === 'host') {
					$phone_holder = '';
					$email_holder = '';
					$ip_address_holder = '';
					$customer_domain_holder = '';
					$first_name_holder = '';
					$last_name_holder = '';
					$is_blocked_holder = 1;
					
					$site_url_holder = site_url();
					$clean_domain_holder = preg_replace( '/^https?:\/\//', '', $site_url_holder );
					$sources_holder = $clean_domain_holder . '[' . $shipping_blacklist_id . ']';

					if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $phone_holder, $email_holder, $ip_address_holder, $customer_domain_holder, $is_blocked_holder, $sources_holder, $shipping_address, $first_name_holder, $last_name_holder ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_subsite', array( $phone_holder, $email_holder, $ip_address_holder, $customer_domain_holder, $is_blocked_holder, $sources_holder, $shipping_address, $first_name_holder, $last_name_holder ) );
					}
				}

				if (get_option('wc_blacklist_connection_mode') === 'sub') {
					if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_hostsite', array( $shipping_blacklist_id ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_hostsite', array( $shipping_blacklist_id ) );
					}
				}
		
				$current_meta = $order->get_meta('_blacklist_blocked_id', true);
				if (!empty($current_meta)) {
					$new_meta = $current_meta . ',' . $shipping_blacklist_id;
				} else {
					$new_meta = $shipping_blacklist_id;
				}
		
				$order->update_meta_data('_blacklist_blocked_id', $new_meta);
				$order->save();
			}
		}
	
		wp_die();
	}
	
	public function display_blacklist_notices( WC_Order $order ) {		
		global $wpdb;
		$table = $wpdb->prefix . 'wc_blacklist';

		// Feature flags
		$ip_enabled        = get_option( 'wc_blacklist_ip_enabled', 0 );
		$address_enabled   = get_option( 'wc_blacklist_enable_customer_address_blocking', 0 );
		$name_enabled      = get_option( 'wc_blacklist_customer_name_blocking_enabled', 0 );
		$premium_active    = ( new WC_Blacklist_Manager_Settings() )->is_premium_active();

		// Gather customer data
		$phone    = sanitize_text_field( $order->get_billing_phone() );
		$email    = sanitize_email( $order->get_billing_email() );
		if ( $premium_active ) {
			$ip = get_post_meta( $order->get_id(), '_customer_ip_address', true );
			if ( empty( $ip ) ) {
				$ip = sanitize_text_field( $order->get_customer_ip_address() );
			}
		} else {
			$ip = sanitize_text_field( $order->get_customer_ip_address() );
		}
		$address  = implode( ', ', array_filter([
					sanitize_text_field( $order->get_billing_address_1() ),
					sanitize_text_field( $order->get_billing_address_2() ),
					sanitize_text_field( $order->get_billing_city() ),
					sanitize_text_field( $order->get_billing_state() ),
					sanitize_text_field( $order->get_billing_postcode() ),
					sanitize_text_field( $order->get_billing_country() ),
					]) );
		$full_name = trim( sanitize_text_field( $order->get_billing_first_name() ) . ' ' . sanitize_text_field( $order->get_billing_last_name() ) );

		$blocked = [];
		$suspect = [];

		// Helper to check one field
		$check_field = function( $column, $value, $label ) use ( $wpdb, $table, &$blocked, &$suspect ) {
			// block check
			$is_blocked = (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE {$column} = %s AND is_blocked = 1 LIMIT 1",
				$value
			) );
			if ( $is_blocked ) {
				$blocked[] = $label;
			} else {
				// suspect check
				$is_suspect = (bool) $wpdb->get_var( $wpdb->prepare(
					"SELECT 1 FROM {$table} WHERE {$column} = %s AND is_blocked = 0 LIMIT 1",
					$value
				) );
				if ( $is_suspect ) {
					$suspect[] = $label;
				}
			}
		};

		// Full name (premium only)
		if ( $name_enabled && $premium_active && $full_name ) {
			// use CONCAT(first_name,' ',last_name) for lookup
			// with label including the actual name
			$label = sprintf( __( 'customer name (%s)', 'wc-blacklist-manager' ), $full_name );
			$check_field( "CONCAT(first_name,' ',last_name)", $full_name, $label );
		}

		// Phone
		if ( $phone ) {
			$check_field( 'phone_number', $phone, __( 'phone', 'wc-blacklist-manager' ) );
		}

		// Email
		if ( $email ) {
			$check_field( 'email_address', $email, __( 'email', 'wc-blacklist-manager' ) );
		}

		// IP
		if ( $ip_enabled && $ip ) {
			$check_field( 'ip_address', $ip, __( 'IP', 'wc-blacklist-manager' ) );
		}

		// Address
		if ( $address_enabled && $premium_active && $address ) {
			$check_field( 'customer_address', $address, __( 'address', 'wc-blacklist-manager' ) );
		}

		// Display blocked notice first (as an error)
		if ( ! empty( $blocked ) ) {
			$msg = sprintf(
				/* translators: %s = comma-separated list of blocked fields */
				__( "This order's %s in the blocklist.", 'wc-blacklist-manager' ),
				implode( ', ', $blocked )
			);
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// Then display suspect warning for any remaining fields
		if ( ! empty( $suspect ) ) {
			$msg = sprintf(
				/* translators: %s = comma-separated list of suspect fields */
				__( "This order's %s in the suspect list.", 'wc-blacklist-manager' ),
				implode( ', ', $suspect )
			);
			echo '<div class="notice notice-warning"><p>' . esc_html( $msg ) . '</p></div>';
		}	
	}
}

new WC_Blacklist_Manager_Button_Add_To_Blacklist();
