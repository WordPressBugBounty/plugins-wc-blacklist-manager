<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Dashboard {
	private $wpdb;
	private $table_name;
	private $date_format;
	private $time_format;
	private $items_per_page = 20;
	private $message = '';

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->table_name = $this->wpdb->prefix . 'wc_blacklist';
		$this->date_format = get_option('date_format');
		$this->time_format = get_option('time_format');
	}

	public function init_hooks() {
		add_action('admin_menu', array($this, 'add_admin_menus'));
		add_action('admin_post_handle_bulk_action', array($this, 'handle_bulk_action_callback'));
		add_action('admin_post_handle_bulk_action_address', array($this, 'handle_bulk_action_address_callback'));
		add_action('admin_post_add_ip_address_action', array($this, 'handle_add_ip_address'));
		add_action('admin_post_add_address_action', array($this, 'handle_add_address'));
		add_action('admin_post_add_domain_action', array($this, 'handle_add_domain'));
		add_action('admin_post_add_suspect_action', array($this, 'handle_form_submission'));
	}

	public function add_admin_menus() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		
		$user_has_permission = false;
			if ($premium_active) {
			$allowed_roles = get_option('wc_blacklist_dashboard_permission', []);
			if (is_array($allowed_roles) && !empty($allowed_roles)) {
				foreach ($allowed_roles as $role) {
					if (current_user_can($role)) {
						$user_has_permission = true;
						break;
					}
				}
			}
		}
		
		if (($premium_active && $user_has_permission) || current_user_can('manage_options')) {
			add_menu_page(
				__('Blacklist Management', 'wc-blacklist-manager'),
				__('Blacklist Manager', 'wc-blacklist-manager'),
				'read',
				'wc-blacklist-manager',
				array($this, 'display_dashboard'),
				'dashicons-table-col-delete',
				998
			);
		
			add_submenu_page(
				'wc-blacklist-manager',
				__('Dashboard', 'wc-blacklist-manager'),
				__('Dashboard', 'wc-blacklist-manager'),
				'read',
				'wc-blacklist-manager',
				array($this, 'display_dashboard')
			);
		}
	}
	
	public function display_dashboard() {
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
			wp_die(__('You do not have sufficient permissions to access this page.', 'wc-blacklist-manager'));
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		
		$woocommerce_active = class_exists( 'WooCommerce' );
		$unlock_url = $woocommerce_active
			? 'https://yoohw.com/product/woocommerce-blacklist-manager-premium/'
			: 'https://yoohw.com/product/blacklist-manager-premium-for-forms/';
	
		$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
		$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
	
		if ($action === 'block' && $id) {
			$this->handle_block_action($id);
		} elseif ($action === 'delete' && $id) {
			$this->handle_delete_action($id);
		}
	
		$this->handle_messages();
	
		$search_query = $this->handle_search();
		$entries = $this->fetch_entries_by_search_words($search_query);
		$message = $this->handle_form_submission();
	
		$blacklisted_data = $this->handle_pagination('blacklisted', "is_blocked = 0 AND ((phone_number != '' AND phone_number IS NOT NULL) OR (email_address != '' AND email_address IS NOT NULL) OR (first_name != '' AND first_name IS NOT NULL) OR (last_name != '' AND last_name IS NOT NULL))");
		$blocked_data = $this->handle_pagination('blocked', "is_blocked = TRUE AND ((phone_number != '' AND phone_number IS NOT NULL) OR (email_address != '' AND email_address IS NOT NULL) OR (first_name != '' AND first_name IS NOT NULL) OR (last_name != '' AND last_name IS NOT NULL))");
		$ip_banned_data = $this->handle_pagination('ip_banned', "ip_address IS NOT NULL AND ip_address <> ''");
		$domain_blocking_data = $this->handle_pagination('domain_blocking', "domain IS NOT NULL AND domain <> ''");
		$address_blocking_data = $this->handle_pagination('address_blocking', "customer_address IS NOT NULL AND customer_address <> ''");
	
		$ip_blacklist_enabled = get_option('wc_blacklist_ip_enabled', false);
		$domain_blocking_enabled = get_option('wc_blacklist_domain_enabled', false);

		$customer_address_blocking_enabled = get_option('wc_blacklist_enable_customer_address_blocking', false);
	
		$current_page_blacklisted = $blacklisted_data['current_page'];
		$total_items_blacklisted = $blacklisted_data['total_items'];
		$total_pages_blacklisted = $blacklisted_data['total_pages'];
		$blacklisted_entries = $blacklisted_data['entries'];
	
		$current_page_blocked = $blocked_data['current_page'];
		$total_items_blocked = $blocked_data['total_items'];
		$total_pages_blocked = $blocked_data['total_pages'];
		$blocked_entries = $blocked_data['entries'];
	
		$current_page_ip_banned = $ip_banned_data['current_page'];
		$total_items_ip_banned = $ip_banned_data['total_items'];
		$total_pages_ip_banned = $ip_banned_data['total_pages'];
		$ip_banned_entries = $ip_banned_data['entries'];
	
		$current_page_domain_blocking = $domain_blocking_data['current_page'];
		$total_items_domain_blocking = $domain_blocking_data['total_items'];
		$total_pages_domain_blocking = $domain_blocking_data['total_pages'];
		$domain_blocking_entries = $domain_blocking_data['entries'];
	
		$current_page_address_blocking = $address_blocking_data['current_page'];
		$total_items_address_blocking = $address_blocking_data['total_items'];
		$total_pages_address_blocking = $address_blocking_data['total_pages'];
		$address_blocking_entries = $address_blocking_data['entries'];
	
		include 'views/yobm-wc-blacklist-manager-dashboard-form.php';

		// Clear the message after displaying it
		unset($_SESSION['wc_blacklist_manager_messages']);
	}

	private function handle_search() {
		$search_query = '';
		if (isset($_GET['blacklist_search_nonce'], $_GET['blacklist_search']) && wp_verify_nonce($_GET['blacklist_search_nonce'], 'blacklist_search_action')) {
			$search_query = trim(sanitize_text_field($_GET['blacklist_search']));
		}
		return $search_query;
	}
	
	private function fetch_entries_by_search_words($search_query) {
		$search_words = array_filter(array_map('sanitize_text_field', explode(' ', $search_query)));
	
		if (empty($search_words)) {
			$sql = "SELECT * FROM {$this->table_name}";
		} else {
			$like_clauses = array_map(function($word) {
				return "(phone_number LIKE '%" . esc_sql($this->wpdb->esc_like($word)) . "%' 
						 OR email_address LIKE '%" . esc_sql($this->wpdb->esc_like($word)) . "%' 
						 OR ip_address LIKE '%" . esc_sql($this->wpdb->esc_like($word)) . "%' 
						 OR domain LIKE '%" . esc_sql($this->wpdb->esc_like($word)) . "%' 
						 OR customer_address LIKE '%" . esc_sql($this->wpdb->esc_like($word)) . "%'
						 OR first_name LIKE '%" . esc_sql($this->wpdb->esc_like($word)) . "%'
						 OR last_name LIKE '%" . esc_sql($this->wpdb->esc_like($word)) . "%')";
			}, $search_words);
	
			$sql = "SELECT * FROM {$this->table_name} WHERE " . implode(' OR ', $like_clauses);
		}
		return $this->wpdb->get_results($sql);
	}	
	
	public function handle_form_submission() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

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
			wp_die(__('You do not have sufficient permissions to access this page.', 'wc-blacklist-manager'));
		}
	
		if (isset($_POST['submit']) && check_admin_referer('add_suspect_action_nonce', 'add_suspect_action_nonce_field')) {
			$new_first_name = isset($_POST['new_first_name']) ? sanitize_text_field($_POST['new_first_name']) : '';
			$new_last_name = isset($_POST['new_last_name']) ? sanitize_text_field($_POST['new_last_name']) : '';
			$new_phone_number = isset($_POST['new_phone_number']) ? sanitize_text_field($_POST['new_phone_number']) : '';
			$new_email_address = isset($_POST['new_email_address']) ? sanitize_email($_POST['new_email_address']) : '';
			$status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'suspect';
	
			$is_blocked = ($status === 'blocked') ? 1 : 0;
	
			if (!empty($new_first_name) && !empty($new_last_name) && empty($new_phone_number) && empty($new_email_address)) {
				// First name and last name only
				$entry_exists = $this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$this->table_name} WHERE first_name = %s AND last_name = %s", $new_first_name, $new_last_name));
	
				if (!$entry_exists) {
					$this->wpdb->insert($this->table_name, [
						'first_name' => $new_first_name,
						'last_name' => $new_last_name,
						'date_added' => current_time('mysql'),
						'sources' => 'manual',
						'is_blocked' => $is_blocked
					]);
					$message = __("Customer name has been added to the suspected list.", "wc-blacklist-manager");
				} else {
					$this->wpdb->update($this->table_name, ['is_blocked' => $is_blocked], ['id' => $entry_exists]);
					$message = __("Customer name already exists in the suspected list. Status updated.", "wc-blacklist-manager");
				}
			} elseif (!empty($new_phone_number) && empty($new_email_address)) {
				// Phone number only
				$entry_exists = $this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$this->table_name} WHERE phone_number = %s", $new_phone_number));
	
				if (!$entry_exists) {
					$this->wpdb->insert($this->table_name, [
						'first_name' => $new_first_name,
						'last_name' => $new_last_name,
						'phone_number' => $new_phone_number,
						'date_added' => current_time('mysql'),
						'sources' => 'manual',
						'is_blocked' => $is_blocked
					]);
					$message = __("Phone number has been added to the suspected list.", "wc-blacklist-manager");
				} else {
					$this->wpdb->update($this->table_name, ['is_blocked' => $is_blocked], ['id' => $entry_exists]);
					$message = __("Phone number already exists in the suspected list. Status updated.", "wc-blacklist-manager");
				}
			} elseif (empty($new_phone_number) && !empty($new_email_address)) {
				// Email address only
				$entry_exists = $this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$this->table_name} WHERE email_address = %s", $new_email_address));
	
				if (!$entry_exists) {
					$this->wpdb->insert($this->table_name, [
						'first_name' => $new_first_name,
						'last_name' => $new_last_name,
						'email_address' => $new_email_address,
						'date_added' => current_time('mysql'),
						'sources' => 'manual',
						'is_blocked' => $is_blocked
					]);
					$message = __("Email address has been added to the suspected list.", "wc-blacklist-manager");
				} else {
					$this->wpdb->update($this->table_name, ['is_blocked' => $is_blocked], ['id' => $entry_exists]);
					$message = __("Email address already exists in the suspected list. Status updated.", "wc-blacklist-manager");
				}
			} elseif (!empty($new_phone_number) && !empty($new_email_address)) {
				// Both phone number and email address
				$sql = $this->wpdb->prepare(
					"SELECT id, phone_number, email_address FROM {$this->table_name} WHERE phone_number = %s OR email_address = %s",
					$new_phone_number, $new_email_address
				);
				$results = $this->wpdb->get_results($sql);
	
				$phone_exists = $email_exists = false;
				foreach ($results as $result) {
					if ($result->phone_number === $new_phone_number) {
						$phone_exists = $result;
					}
					if ($result->email_address === $new_email_address) {
						$email_exists = $result;
					}
				}
	
				if ($phone_exists && $email_exists) {
					if (isset($phone_exists->email_address) && isset($email_exists->phone_number)) {
						$message = __("Phone number and email address already exist in the suspected list.", "wc-blacklist-manager");
					} elseif (isset($phone_exists->email_address)) {
						$this->wpdb->update($this->table_name, ['phone_number' => $new_phone_number, 'is_blocked' => $is_blocked], ['id' => $email_exists->id]);
						$this->wpdb->delete($this->table_name, ['id' => $phone_exists->id]);
						$message = __("Phone number and email address have been merged into one row in the suspected list.", "wc-blacklist-manager");
					} elseif (isset($email_exists->phone_number)) {
						$this->wpdb->update($this->table_name, ['email_address' => $new_email_address, 'is_blocked' => $is_blocked], ['id' => $phone_exists->id]);
						$this->wpdb->delete($this->table_name, ['id' => $email_exists->id]);
						$message = __("Phone number and email address have been merged into one row in the suspected list.", "wc-blacklist-manager");
					} else {
						$this->wpdb->update($this->table_name, [
							'first_name' => $new_first_name,
							'last_name' => $new_last_name,
							'phone_number' => $new_phone_number,
							'email_address' => $new_email_address,
							'sources' => 'manual',
							'is_blocked' => $is_blocked
						], ['id' => $phone_exists->id]);
						$message = __("Phone number and email address have been merged into one row in the suspected list.", "wc-blacklist-manager");
					}
				} else {
					$this->wpdb->insert($this->table_name, [
						'first_name' => $new_first_name,
						'last_name' => $new_last_name,
						'phone_number' => $new_phone_number,
						'email_address' => $new_email_address,
						'date_added' => current_time('mysql'),
						'sources' => 'manual',
						'is_blocked' => $is_blocked
					]);
					$message = __("Phone number and email address have been added to the suspected list.", "wc-blacklist-manager");
				}
			} elseif (!empty($new_first_name) || !empty($new_last_name)) {
				$message = __("Both First name and Last name are required.", "wc-blacklist-manager");
			} else {
				$message = __("There is nothing to add.", "wc-blacklist-manager");
			}

			$new_insert_id = $this->wpdb->insert_id;

			if ( ! empty( $new_first_name ) || ! empty( $new_last_name ) || ! empty( $new_phone_number ) || ! empty( $new_email_address ) ) {
				if ($premium_active && get_option('wc_blacklist_connection_mode') === 'host') {
					$ip_address = '';
					$customer_domain = '';
					$customer_address = '';
					
					$site_url = site_url();
					$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
					$sources = $clean_domain . '[' . $new_insert_id . ']';
		
					if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $new_phone_number, $new_email_address, $ip_address, $customer_domain, $is_blocked, $sources, $customer_address, $new_first_name, $new_last_name ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_subsite', array( $new_phone_number, $new_email_address, $ip_address, $customer_domain, $is_blocked, $sources, $customer_address, $new_first_name, $new_last_name ) );
					}
				}
			}
	
			$message = esc_html($message);
			wp_redirect(add_query_arg(['add_suspect_message' => urlencode($message), 'status' => urlencode($status)], wp_get_referer()));
			exit;
		}
	}
	
	private function process_form_data($new_phone_number, $new_email_address) {
		$sql = $this->wpdb->prepare(
			"SELECT id, phone_number, email_address FROM {$this->table_name} WHERE phone_number = %s OR email_address = %s",
			$new_phone_number, $new_email_address
		);
		$results = $this->wpdb->get_results($sql);

		$phone_exists = $email_exists = false;
		foreach ($results as $result) {
			if ($result->phone_number === $new_phone_number) {
				$phone_exists = true;
			}
			if ($result->email_address === $new_email_address) {
				$email_exists = true;
			}
		}

		if ($phone_exists && $email_exists) {
			return __("Both Phone and Email already exist in blacklist.", "wc-blacklist-manager");
		} elseif ($phone_exists || $email_exists) {
			return __("Either Phone or Email exists in the blacklist.", "wc-blacklist-manager");
		} else {
			return __("New Phone or Email added to the blacklist.", "wc-blacklist-manager");
		}
	}

	private function handle_block_action($id) {
		$nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
	
		if (!wp_verify_nonce($nonce, 'block_action')) {
			$message = __('Security check failed. Please try again.', 'wc-blacklist-manager');
		} else {
			global $wpdb;
	
			$entry = $wpdb->get_row($wpdb->prepare("SELECT sources FROM {$this->table_name} WHERE id = %d", $id));
			if ($entry && !empty($entry->sources)) {
				$pattern = '/Order ID: (\d+)/';
				if (preg_match($pattern, $entry->sources, $matches)) {
					$order_id = intval($matches[1]);
					$order = wc_get_order($order_id);
					if ($order) {
						$user_id = $order->get_user_id();
						if ($user_id) {
							update_user_meta($user_id, 'user_blocked', '1');
						}
					}
				}
			}
	
			$wpdb->update($this->table_name, ['is_blocked' => true], ['id' => $id]);

			if ($premium_active && get_option('wc_blacklist_connection_mode') === 'host') {
				$is_blocked = 1;

				$site_url = site_url();
				$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
				$sources = $clean_domain . '[' . $id . ']';

				if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) ) ) {
					wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_subsite', array( $is_blocked, $sources ) );
				}
			}

			if ($premium_active && get_option('wc_blacklist_connection_mode') === 'sub') {
				if ( ! wp_next_scheduled( 'wc_blacklist_connection_update_to_hostsite', array( $id ) ) ) {
					wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_update_to_hostsite', array( $id ) );
				}
			}

			$message = __("Entry moved to blocked list successfully.", "wc-blacklist-manager");
		}
	
		wp_redirect(add_query_arg('message', urlencode($message), wp_get_referer()));
		exit;
	}
	
	private function handle_delete_action( $id ) {
		// 1) security
		$nonce = isset( $_GET['_wpnonce'] )
			? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'delete_action' ) ) {
			$message = __( 'Security check failed. Please try again.', 'wc-blacklist-manager' );
			wp_redirect( add_query_arg( 'delete_message', urlencode( $message ), wp_get_referer() ) );
			exit;
		}

		// 2) premium detection & globals
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();
		global $wpdb;
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

		// 3) fetch the row *before* deletion
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, phone_number, email_address, ip_address, domain,
						is_blocked, sources, customer_address,
						first_name, last_name, date_added, order_id
				FROM {$this->table_name}
				WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		$order_id = ! empty( $row['order_id'] ) ? intval( $row['order_id'] ) : 0;

		// 4) delete from main table
		$wpdb->delete( $this->table_name, [ 'id' => $id ], [ '%d' ] );

		if ( $premium_active ) {
			// prepare common log fields
			$current_user = wp_get_current_user();
			$shop_manager = $current_user->display_name;
			$details      = 'removed_from_blacklist_by:' . $shop_manager;

			// 5) branch on order_id presence
			if ( ! empty( $row['order_id'] ) ) {
				$source    = 'woo_order_' . intval( $row['order_id'] );
				$view_json = '';
			} else {
				$source = 'entry_id_' . intval( $id );
				// collect only non-empty fields for view
				$fields    = [
					'id','phone_number','email_address','ip_address',
					'domain','is_blocked','sources','customer_address',
					'first_name','last_name','date_added'
				];
				$view_data = [];
				foreach ( $fields as $f ) {
					if ( isset( $row[ $f ] ) && '' !== $row[ $f ] ) {
						$view_data[ $f ] = $row[ $f ];
					}
				}
				$view_json = wp_json_encode( $view_data );
			}

			// 6) insert into detection log
			$wpdb->insert(
				$table_detection_log,
				[
					'timestamp' => current_time( 'mysql' ),
					'type'      => 'human',
					'source'    => $source,
					'action'    => 'remove',
					'details'   => $details,
					'view'      => $view_json,
				],
				[ '%s','%s','%s','%s','%s','%s' ]
			);

			// 7) host‐mode removal scheduling
			if ( get_option( 'wc_blacklist_connection_mode' ) === 'host' ) {
				$site_url     = site_url();
				$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
				$sources      = $clean_domain . '[' . $id . ']';

				if ( ! wp_next_scheduled( 'wc_blacklist_connection_remove_to_subsite', [ $sources ] ) ) {
					wp_schedule_single_event(
						time() + 5,
						'wc_blacklist_connection_remove_to_subsite',
						[ $sources ]
					);
				}
			}
		}

        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->delete_meta_data( '_blacklist_blocked_id' );
                $order->delete_meta_data( '_blacklist_suspect_id' );
                $order->save();
            }
        }

		// 8) redirect back with success
		$message = __( 'Entry removed successfully.', 'wc-blacklist-manager' );
		wp_redirect( add_query_arg( 'delete_message', urlencode( $message ), wp_get_referer() ) );
		exit;
	}

    private function handle_messages() {
        $messages = [];

        if (isset($_GET['add_ip_message'])) {
            $messages[] = sanitize_text_field(urldecode($_GET['add_ip_message']));
        }

        if (isset($_GET['add_address_message'])) {
            $messages[] = sanitize_text_field(urldecode($_GET['add_address_message']));
        }

        if (isset($_GET['add_domain_message'])) {
            $messages[] = sanitize_text_field(urldecode($_GET['add_domain_message']));
        }

        if (isset($_GET['add_suspect_message'])) {
            $messages[] = sanitize_text_field(urldecode($_GET['add_suspect_message']));
        }

        if (isset($_GET['delete_message'])) {
            $messages[] = sanitize_text_field(urldecode($_GET['delete_message']));
        }

        if (isset($_GET['message'])) {
            $messages[] = sanitize_text_field(urldecode($_GET['message']));
        }

        if (!empty($messages)) {
            $_SESSION['wc_blacklist_manager_messages'] = implode(' ', $messages);
        }

        $this->message = isset($_SESSION['wc_blacklist_manager_messages']) ? $_SESSION['wc_blacklist_manager_messages'] : '';
    }

	private function clear_message() {
		$this->message = [];
	}

	private function build_query($search_words) {
		$where_parts = [];

		if (!empty($search_words)) {
			foreach ($search_words as $word) {
				$word_like = '%' . $this->wpdb->esc_like($word) . '%';
				$where_parts[] = $this->wpdb->prepare("(phone_number LIKE %s OR email_address LIKE %s OR ip_address LIKE %s OR domain LIKE %s)", $word_like, $word_like, $word_like, $word_like);
			}
		}

		return !empty($where_parts) ? implode(' OR ', $where_parts) : '1=1';
	}

	private function fetch_paginated_entries($table_name, $where_clause, $order_by, $items_per_page, $offset) {
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$order_by} DESC LIMIT %d OFFSET %d",
			$items_per_page,
			$offset
		);
		return $this->wpdb->get_results($query);
	}

	private function fetch_total_count($table_name, $where_clause) {
		$query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
		return $this->wpdb->get_var($query);
	}

	private function handle_pagination($type, $base_where_clause) {
		$current_page = isset($_GET['paged_' . $type]) ? max(1, intval($_GET['paged_' . $type])) : 1;
		$where_clause = $this->build_where_clause($base_where_clause);
		$total_items = $this->fetch_total_count($this->table_name, $where_clause);
		$total_pages = ceil($total_items / $this->items_per_page);
		$offset = ($current_page - 1) * $this->items_per_page;
		$entries = $this->fetch_paginated_entries($this->table_name, $where_clause, 'date_added', $this->items_per_page, $offset);
	
		return [
			'current_page' => $current_page,
			'total_items' => $total_items,
			'total_pages' => $total_pages,
			'entries' => $entries
		];
	}

	private function build_where_clause($base_clause) {
		$where_parts = $this->prepare_search_terms();
		$additional_conditions = !empty($where_parts) ? implode(' OR ', $where_parts) : '';
		return !empty($additional_conditions) ? "{$base_clause} AND ({$additional_conditions})" : $base_clause;
	}
	
	private function prepare_search_terms() {
		$search_query = $this->handle_search();
		$search_terms = array_filter(array_map('sanitize_text_field', explode(' ', $search_query)));
	
		if (empty($search_terms)) {
			return [];
		}
	
		$like_clauses = array_map(function($term) {
			return $this->wpdb->prepare("(phone_number LIKE %s OR email_address LIKE %s OR ip_address LIKE %s OR domain LIKE %s)", "%{$term}%", "%{$term}%", "%{$term}%", "%{$term}%");
		}, $search_terms);
	
		return $like_clauses;
	}
	
	public function handle_bulk_action_callback() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

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
			wp_die(__('You do not have sufficient permissions to access this page.', 'wc-blacklist-manager'));
		}

		check_admin_referer('yobm_nonce_action', 'yobm_nonce_field');

		if (isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete' && !empty($_POST['entry_ids'])) {
			// sanitize & filter
			$entry_ids = array_map( 'intval', (array) $_POST['entry_ids'] );
			$entry_ids = array_filter( $entry_ids, fn( $id ) => $id > 0 );

			global $wpdb;
			$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

			foreach ( $entry_ids as $id ) {
				// 1) fetch entire row before deletion
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, phone_number, email_address, ip_address, domain,
								is_blocked, sources, customer_address,
								first_name, last_name, date_added, order_id
						FROM {$this->table_name}
						WHERE id = %d",
						$id
					),
					ARRAY_A
				);
				$order_id = ! empty( $row['order_id'] ) ? intval( $row['order_id'] ) : 0;

				// 2) delete from main blacklist table
				$this->wpdb->delete( $this->table_name, [ 'id' => $id ], [ '%d' ] );

				if ( $premium_active ) {
					// 3) prepare common log fields
					$current_user = wp_get_current_user();
					$shop_manager = $current_user->display_name;
					$details      = 'removed_from_blacklist_by:' . $shop_manager;

					// 4) branch on order_id presence
					if ( ! empty( $row['order_id'] ) ) {
						$source    = 'woo_order_' . intval( $row['order_id'] );
						$view_json = '';
					} else {
						$source = 'entry_id_' . $id;
						// pick only non-empty columns for the view payload
						$fields    = [
							'id','phone_number','email_address','ip_address',
							'domain','is_blocked','sources','customer_address',
							'first_name','last_name','date_added'
						];
						$view_data = [];
						foreach ( $fields as $f ) {
							if ( isset( $row[ $f ] ) && $row[ $f ] !== '' ) {
								$view_data[ $f ] = $row[ $f ];
							}
						}
						$view_json = wp_json_encode( $view_data );
					}

					// 5) insert into detection log
					$wpdb->insert(
						$table_detection_log,
						[
							'timestamp' => current_time( 'mysql' ),
							'type'      => 'human',
							'source'    => $source,
							'action'    => 'remove',
							'details'   => $details,
							'view'      => $view_json,
						],
						[ '%s', '%s', '%s', '%s', '%s', '%s' ]
					);

					// 6) schedule host-mode removal if configured
					if ( get_option( 'wc_blacklist_connection_mode' ) === 'host' ) {
						$site_url     = site_url();
						$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
						$sources      = $clean_domain . '[' . $id . ']';

						if ( ! wp_next_scheduled( 'wc_blacklist_connection_remove_to_subsite', [ $sources ] ) ) {
							wp_schedule_single_event(
								time() + 5,
								'wc_blacklist_connection_remove_to_subsite',
								[ $sources ]
							);
						}
					}
				}

				if ( $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order ) {
						$order->delete_meta_data( '_blacklist_blocked_id' );
						$order->delete_meta_data( '_blacklist_suspect_id' );
						$order->save();
					}
				}
			}
		}

		wp_redirect($_SERVER['HTTP_REFERER']);
		exit;
	}

	public function handle_add_ip_address() {
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
			wp_die(__('You do not have sufficient permissions to access this page.', 'wc-blacklist-manager'));
		}

		check_admin_referer('add_ip_address_nonce_action', 'add_ip_address_nonce_field');

		$ip_addresses = explode("\n", trim($_POST['ip-addresses'] ?? ''));
		if (empty($ip_addresses)) {
			wp_redirect(add_query_arg('add_ip_message', urlencode(__('No IP addresses were added. Please provide valid IP addresses.', 'wc-blacklist-manager')), wp_get_referer()));
			exit;
		}

		if (count($ip_addresses) > 50) {
			wp_redirect(add_query_arg('add_ip_message', urlencode(__('Submission failed: You can only add up to 50 IP addresses at a time.', 'wc-blacklist-manager')), wp_get_referer()));
			exit;
		}

		$ip_addresses_added = 0;
		foreach ($ip_addresses as $ip_address) {
			$ip_address = sanitize_text_field($ip_address);
			if (filter_var($ip_address, FILTER_VALIDATE_IP)) {
				if ($this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE ip_address = %s", $ip_address)) == 0) {
					$this->wpdb->insert($this->table_name, [
						'ip_address' => $ip_address,
						'date_added' => current_time('mysql', 1),
						'is_blocked' => true,
						'sources' => 'manual'
					]);
					$ip_addresses_added++;
				}
			}
		}

		$message = sprintf(_n('One IP address added.', '%s IP addresses added.', $ip_addresses_added, 'wc-blacklist-manager'), $ip_addresses_added);
		wp_redirect(add_query_arg('add_ip_message', urlencode($message), wp_get_referer()));
		exit;
	}

	public function handle_add_address() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

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
			wp_die(__('You do not have sufficient permissions to access this page.', 'wc-blacklist-manager'));
		}
	
		check_admin_referer('add_address_nonce_action', 'add_address_nonce_field');
	
		$address_1 = sanitize_text_field($_POST['address_1_input'] ?? '');
		$address_2 = sanitize_text_field($_POST['address_2_input'] ?? '');
		$city = sanitize_text_field($_POST['city_input'] ?? '');
		$state = sanitize_text_field($_POST['state'] ?? $_POST['state_input'] ?? '');
		$postcode = sanitize_text_field($_POST['postcode_input'] ?? '');
		$country = sanitize_text_field($_POST['country'] ?? '');
	
		// Combine the address parts into a single string, removing empty parts and trimming commas
		$address_parts = array_filter([$address_1, $address_2, $city, $state, $postcode, $country]);
		$customer_address = implode(', ', $address_parts);
	
		if (empty($customer_address)) {
			wp_redirect(add_query_arg('add_address_message', urlencode(__('No address provided. Please fill in at least one field.', 'wc-blacklist-manager')), wp_get_referer()));
			exit;
		}
	
		// Insert into database
		$this->wpdb->insert($this->table_name, [
			'customer_address' => $customer_address,
			'date_added' => current_time('mysql', 1),
			'is_blocked' => true,
			'sources' => 'manual'
		]);

		$new_insert_id = $this->wpdb->insert_id;

		if ($premium_active && get_option('wc_blacklist_connection_mode') === 'host') {
			$phone = '';
			$email = '';
			$ip_address = '';
			$customer_domain = '';
			$first_name = '';
			$last_name = '';
			$is_blocked = 1;
			
			$site_url = site_url();
			$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
			$sources = $clean_domain . '[' . $new_insert_id . ']';

			if ( ! wp_next_scheduled( 'wc_blacklist_connection_send_to_subsite', array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, $customer_address, $first_name, $last_name ) ) ) {
				wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_send_to_subsite', array( $phone, $email, $ip_address, $customer_domain, $is_blocked, $sources, $customer_address, $first_name, $last_name ) );
			}
		}
	
		$message = __('Address added successfully.', 'wc-blacklist-manager');
		wp_redirect(add_query_arg('add_address_message', urlencode($message), wp_get_referer()));
		exit;
	}

	public function handle_add_domain() {
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
			wp_die(__('You do not have sufficient permissions to access this page.', 'wc-blacklist-manager'));
		}

		if (!isset($_POST['add_domain_nonce_field']) || !check_admin_referer('add_domain_nonce_action', 'add_domain_nonce_field')) {
			wp_die(esc_html__('Sorry, your nonce did not verify.', 'wc-blacklist-manager'));
		}

		$raw_domains = $_POST['domains'] ?? '';
		$domains = explode("\n", trim($raw_domains));

		if (empty(trim($raw_domains))) {
			wp_redirect(add_query_arg('add_domain_message', urlencode(__('No domains were provided.', 'wc-blacklist-manager')), wp_get_referer()));
			exit;
		}

		if (count($domains) > 50) {
			wp_redirect(add_query_arg('add_domain_message', urlencode(__('Submission failed: You can only add up to 50 domains at a time.', 'wc-blacklist-manager')), wp_get_referer()));
			exit;
		}

		$domains_added = 0;
		$invalid_domains = [];

		foreach ($domains as $domain) {
			$domain = sanitize_text_field($domain);
			if (!empty($domain) && preg_match('/^([a-zA-Z0-9]+(-[a-zA-Z0-9]+)*\.)+[a-zA-Z]{2,}$/', $domain)) {
				if (!$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE domain = %s", $domain))) {
					$this->wpdb->insert($this->table_name, [
						'domain' => $domain,
						'date_added' => current_time('mysql', 1),
						'is_blocked' => true,
						'sources' => 'manual'
					]);
					$domains_added++;
				}
			} else {
				$invalid_domains[] = $domain;
			}
		}

		$message = '';
		if ($domains_added > 0) {
			$message .= sprintf(_n('One domain added.', '%s domains added.', $domains_added, 'wc-blacklist-manager'), $domains_added);
		}
		if (!empty($invalid_domains)) {
			$invalid_message = sprintf(_n('One domain was not added because it is not in the right format.', '%s domains were not added because they are not in the right format.', count($invalid_domains), 'wc-blacklist-manager'), count($invalid_domains));
			$message .= $message ? ' ' . $invalid_message : $invalid_message;
		}
		if (empty($message)) {
			$message = __('No new domains were added.', 'wc-blacklist-manager');
		}

		wp_redirect(add_query_arg('add_domain_message', urlencode($message), wp_get_referer()));
		exit;
	}

	public function handle_bulk_action_address_callback() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

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
			wp_die(__('You do not have sufficient permissions to access this page.', 'wc-blacklist-manager'));
		}

		check_admin_referer('yobm_nonce_action', 'yobm_nonce_field');

		if (isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete' && !empty($_POST['entry_ids'])) {
			$entry_ids = $_POST['entry_ids'];
			array_walk($entry_ids, function(&$id) {
				$id = intval($id);
			});

			$entry_ids = array_filter($entry_ids, function($id) {
				return is_int($id) && $id > 0;
			});

			foreach ($entry_ids as $id) {
				$this->wpdb->delete($this->table_name, ['id' => $id], ['%d']);

				if ($premium_active && get_option('wc_blacklist_connection_mode') === 'host') {
					$site_url = site_url();
					$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
					$sources = $clean_domain . '[' . $id . ']';
	
					if ( ! wp_next_scheduled( 'wc_blacklist_connection_remove_to_subsite', array( $sources ) ) ) {
						wp_schedule_single_event( time() + 5, 'wc_blacklist_connection_remove_to_subsite', array( $sources ) );
					}
				}
			}
		}

		wp_redirect($_SERVER['HTTP_REFERER']);
		exit;
	}
}

$blacklist_manager = new WC_Blacklist_Manager_Dashboard();
$blacklist_manager->init_hooks();

class WC_Blacklist_Manager_Address_Selection {

    public static function enqueue_scripts($hook) {
        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

        if ($current_page !== 'wc-blacklist-manager') {
            return;
        }

        wp_enqueue_script('selectWoo');
        wp_enqueue_script('woocommerce_admin');
        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('select2');
        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_style('woocommerce_admin');
    }

    public static function initialize_selectWoo() {
        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

        if ($current_page !== 'wc-blacklist-manager') {
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.wc-enhanced-select').selectWoo();
            });
        </script>
        <?php
    }
}

// Hook into the admin_enqueue_scripts action to load the scripts and styles
add_action('admin_enqueue_scripts', array('WC_Blacklist_Manager_Address_Selection', 'enqueue_scripts'));

// Hook into the admin_footer action to initialize selectWoo
add_action('admin_footer', array('WC_Blacklist_Manager_Address_Selection', 'initialize_selectWoo'));
