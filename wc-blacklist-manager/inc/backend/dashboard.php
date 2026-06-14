<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Blacklist_Manager_Dashboard {
	private $wpdb;
	private $table_name;
	private $device_table_name;
	private $device_links_table_name;
	private $address_table_name;
	private $date_format;
	private $time_format;
	private $items_per_page = 20;
	private $message = '';
	private $premium_active = null;

	public function __construct() {
		global $wpdb;
		$this->wpdb                    = $wpdb;
		$this->table_name              = $this->wpdb->prefix . 'wc_blacklist';
		$this->address_table_name      = $this->wpdb->prefix . 'wc_blacklist_addresses';
		$this->device_table_name       = $this->wpdb->prefix . 'wc_blacklist_devices';
		$this->device_links_table_name = $this->wpdb->prefix . 'wc_blacklist_device_links';
		$this->date_format             = get_option( 'date_format' );
		$this->time_format             = get_option( 'time_format' );
	}

	public function init_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menus' ) );
		add_action( 'admin_post_enable_global_blacklist', array( $this, 'handle_enable_global_blacklist' ) );
		add_action( 'admin_post_handle_bulk_action', array( $this, 'handle_bulk_action_callback' ) );
		add_action( 'admin_post_handle_bulk_action_address', array( $this, 'handle_bulk_action_address_callback' ) );
		add_action( 'admin_post_add_ip_address_action', array( $this, 'handle_add_ip_address' ) );
		add_action( 'admin_post_add_address_action', array( $this, 'handle_add_address' ) );
		add_action( 'admin_post_add_domain_action', array( $this, 'handle_add_domain' ) );
		add_action( 'admin_post_add_suspect_action', array( $this, 'handle_form_submission' ) );
		add_action( 'wp_ajax_wc_blacklist_get_device_details', array( $this, 'ajax_get_device_details' ) );
	}

	private function current_user_can_manage_dashboard( $require_premium = false ) {
		return function_exists( 'wc_blacklist_manager_user_can_manage_area' )
			? wc_blacklist_manager_user_can_manage_area( 'wc_blacklist_dashboard_permission', $require_premium )
			: current_user_can( 'manage_options' );
	}

	private function is_premium_active() {
		if ( null !== $this->premium_active ) {
			return $this->premium_active;
		}

		$this->premium_active = function_exists( 'wc_blacklist_manager_is_premium_available' )
			? (bool) wc_blacklist_manager_is_premium_available()
			: false;

		return $this->premium_active;
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

	private function require_premium_feature( $context = 'admin' ) {
		if ( $this->is_premium_active() ) {
			return true;
		}

		$message = function_exists( 'wc_blacklist_manager_premium_denied_message' )
			? wc_blacklist_manager_premium_denied_message()
			: __( 'A valid Blacklist Manager Premium license is required to use this feature.', 'wc-blacklist-manager' );

		if ( 'ajax' === $context ) {
			wp_send_json_error(
				array(
					'message' => $message,
				),
				403
			);
		}

		wp_die(
			esc_html( $message ),
			esc_html__( 'Premium license required', 'wc-blacklist-manager' ),
			array( 'response' => 403 )
		);
	}

	private function get_connection_source( $id, $record_type = 'main' ) {
		$site_url     = site_url();
		$clean_domain = preg_replace( '/^https?:\/\//', '', $site_url );
		$id           = absint( $id );

		if ( 'address' === $record_type ) {
			return $clean_domain . '[address:' . $id . ']';
		}

		return $clean_domain . '[' . $id . ']';
	}

	private function schedule_connection_event( $hook, array $args ) {
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + 5, $hook, $args );
		}
	}

	private function dispatch_dashboard_row_event( $event, $id, array $row = array(), $record_type = 'main' ) {
		$id = absint( $id );

		do_action( "wc_blacklist_manager_dashboard_row_{$event}", $id, $row, $record_type );
		do_action( 'wc_blacklist_manager_dashboard_row_changed', $event, $id, $row, $record_type );
	}

	private function schedule_connection_create( $id, array $row = array(), $record_type = 'main' ) {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return;
		}

		$this->dispatch_dashboard_row_event( 'created', $id, $row, $record_type );

		if ( ! $this->is_premium_active() ) {
			return;
		}

		$mode = get_option( 'wc_blacklist_connection_mode' );

		if ( 'host' === $mode ) {
			$args = array(
				isset( $row['phone_number'] ) ? (string) $row['phone_number'] : '',
				isset( $row['email_address'] ) ? (string) $row['email_address'] : '',
				isset( $row['ip_address'] ) ? (string) $row['ip_address'] : '',
				isset( $row['domain'] ) ? (string) $row['domain'] : '',
				isset( $row['is_blocked'] ) ? (int) $row['is_blocked'] : 0,
				$this->get_connection_source( $id, $record_type ),
				isset( $row['customer_address'] ) ? (string) $row['customer_address'] : ( isset( $row['address_display'] ) ? (string) $row['address_display'] : '' ),
				isset( $row['first_name'] ) ? (string) $row['first_name'] : '',
				isset( $row['last_name'] ) ? (string) $row['last_name'] : '',
			);

			$this->schedule_connection_event( 'wc_blacklist_connection_send_to_subsite', $args );
			return;
		}

		if ( 'sub' === $mode && 'main' === $record_type ) {
			$this->schedule_connection_event( 'wc_blacklist_connection_send_to_hostsite', array( $id ) );
		}
	}

	private function schedule_connection_update( $id, array $row = array(), $record_type = 'main' ) {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return;
		}

		$this->dispatch_dashboard_row_event( 'updated', $id, $row, $record_type );

		if ( ! $this->is_premium_active() ) {
			return;
		}

		$mode = get_option( 'wc_blacklist_connection_mode' );

		if ( 'host' === $mode ) {
			$args = array(
				isset( $row['is_blocked'] ) ? (int) $row['is_blocked'] : 0,
				$this->get_connection_source( $id, $record_type ),
			);

			$this->schedule_connection_event( 'wc_blacklist_connection_update_to_subsite', $args );
			return;
		}

		if ( 'sub' === $mode && 'main' === $record_type ) {
			$this->schedule_connection_event( 'wc_blacklist_connection_update_to_hostsite', array( $id ) );
		}
	}

	private function schedule_connection_delete( $id, array $row = array(), $record_type = 'main' ) {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return;
		}

		$this->dispatch_dashboard_row_event( 'deleted', $id, $row, $record_type );

		if ( ! $this->is_premium_active() || 'host' !== get_option( 'wc_blacklist_connection_mode' ) ) {
			return;
		}

		$this->schedule_connection_event(
			'wc_blacklist_connection_remove_to_subsite',
			array( $this->get_connection_source( $id, $record_type ) )
		);
	}

	private function record_remove_audit( $id, array $row = array(), $record_type = 'main' ) {
		if ( ! $this->is_premium_active() ) {
			return;
		}

		$id                  = absint( $id );
		$table_detection_log = $this->wpdb->prefix . 'wc_blacklist_detection_log';
		$current_user        = wp_get_current_user();
		$shop_manager        = $current_user ? $current_user->display_name : '';
		$details             = 'removed_from_blacklist_by:' . $shop_manager;

		if ( 'address' === $record_type ) {
			$fields = array(
				'id',
				'match_type',
				'is_blocked',
				'country_code',
				'state_code',
				'city_norm',
				'postcode_norm',
				'address_line_norm',
				'address_full_norm',
				'address_hash',
				'address_display',
				'notes',
				'date_added',
			);

			$source = 'address_entry_id_' . $id;
		} elseif ( ! empty( $row['order_id'] ) ) {
			$fields = array();
			$source = 'woo_order_' . absint( $row['order_id'] );
		} else {
			$fields = array(
				'id',
				'phone_number',
				'email_address',
				'ip_address',
				'domain',
				'is_blocked',
				'sources',
				'customer_address',
				'first_name',
				'last_name',
				'date_added',
			);

			$source = 'entry_id_' . $id;
		}

		$view_data = array();
		foreach ( $fields as $field ) {
			if ( isset( $row[ $field ] ) && '' !== $row[ $field ] ) {
				$view_data[ $field ] = $row[ $field ];
			}
		}

		$this->wpdb->insert(
			$table_detection_log,
			array(
				'timestamp' => current_time( 'mysql' ),
				'type'      => 'human',
				'source'    => $source,
				'action'    => 'remove',
				'details'   => $details,
				'view'      => empty( $view_data ) ? '' : wp_json_encode( $view_data ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private function delete_linked_address_rows_for_blacklist( $blacklist_id ) {
		$blacklist_id = absint( $blacklist_id );
		if ( $blacklist_id <= 0 ) {
			return;
		}

		$address_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT *
				FROM {$this->address_table_name}
				WHERE blacklist_id = %d",
				$blacklist_id
			),
			ARRAY_A
		);

		$this->wpdb->delete(
			$this->address_table_name,
			array(
				'blacklist_id' => $blacklist_id,
			),
			array(
				'%d',
			)
		);

		foreach ( $address_rows as $address_row ) {
			$address_id = isset( $address_row['id'] ) ? absint( $address_row['id'] ) : 0;
			if ( $address_id <= 0 ) {
				continue;
			}

			$this->record_remove_audit( $address_id, $address_row, 'address' );
			$this->schedule_connection_delete( $address_id, $address_row, 'address' );
		}
	}

	public function add_admin_menus() {
		if ( $this->current_user_can_manage_dashboard( true ) ) {
			add_menu_page(
				__( 'Blacklist Management', 'wc-blacklist-manager' ),
				__( 'Blacklist Manager', 'wc-blacklist-manager' ),
				'read',
				'wc-blacklist-manager',
				array( $this, 'display_dashboard' ),
				'dashicons-table-col-delete',
				998
			);

			add_submenu_page(
				'wc-blacklist-manager',
				__( 'Dashboard', 'wc-blacklist-manager' ),
				__( 'Dashboard', 'wc-blacklist-manager' ),
				'read',
				'wc-blacklist-manager',
				array( $this, 'display_dashboard' )
			);
		}
	}

	public function display_dashboard() {
		if ( ! $this->current_user_can_manage_dashboard() ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-blacklist-manager' ) );
		}

		$premium_active = $this->is_premium_active();

		$woocommerce_active = class_exists( 'WooCommerce' );
		$unlock_url         = 'https://yoohw.com/product/blacklist-manager-premium/';

		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( $action && $id ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

			$nonce_action = '';
			if ( 'block' === $action ) {
				$nonce_action = 'block_action';
			} elseif ( 'delete' === $action ) {
				$nonce_action = 'delete_action';
			} elseif ( 'delete_address' === $action ) {
				$nonce_action = 'delete_address_action';
			}

			if ( $nonce_action && wp_verify_nonce( $nonce, $nonce_action ) ) {
				if ( 'block' === $action ) {
					$this->handle_block_action( $id );
				} elseif ( 'delete' === $action ) {
					$this->handle_delete_action( $id );
				} elseif ( 'delete_address' === $action ) {
					$this->handle_delete_address_action( $id );
				}
			} elseif ( $nonce_action ) {
				wp_die( esc_html__( 'Security check failed. Please try again.', 'wc-blacklist-manager' ) );
			}
		}

		$this->handle_messages();

		$search_query = $this->handle_search();
		$entries      = $this->fetch_entries_by_search_words( $search_query );
		$message      = $this->handle_form_submission();

		$blacklisted_data = $this->handle_pagination(
			'blacklisted',
			"is_blocked = 0 AND ((phone_number != '' AND phone_number IS NOT NULL) OR (email_address != '' AND email_address IS NOT NULL) OR (first_name != '' AND first_name IS NOT NULL) OR (last_name != '' AND last_name IS NOT NULL))"
		);

		$blocked_data = $this->handle_pagination(
			'blocked',
			"is_blocked = 1 AND ((phone_number != '' AND phone_number IS NOT NULL) OR (email_address != '' AND email_address IS NOT NULL) OR (first_name != '' AND first_name IS NOT NULL) OR (last_name != '' AND last_name IS NOT NULL))"
		);

		$device_data = $this->handle_device_pagination();

		$ip_banned_data = $this->handle_pagination(
			'ip_banned',
			"ip_address IS NOT NULL AND ip_address <> ''"
		);

		$domain_blocking_data = $this->handle_pagination(
			'domain_blocking',
			"domain IS NOT NULL AND domain <> ''"
		);

		$address_blocking_data = $this->handle_address_pagination();

		$device_blacklist_enabled           = get_option( 'wc_blacklist_enable_device_identity', false );
		$ip_blacklist_enabled               = get_option( 'wc_blacklist_ip_enabled', false );
		$domain_blocking_enabled            = get_option( 'wc_blacklist_domain_enabled', false );
		$customer_address_blocking_enabled  = get_option( 'wc_blacklist_enable_customer_address_blocking', false );

		$current_page_blacklisted = $blacklisted_data['current_page'];
		$total_items_blacklisted  = $blacklisted_data['total_items'];
		$total_pages_blacklisted  = $blacklisted_data['total_pages'];
		$blacklisted_entries      = $blacklisted_data['entries'];

		$current_page_blocked = $blocked_data['current_page'];
		$total_items_blocked  = $blocked_data['total_items'];
		$total_pages_blocked  = $blocked_data['total_pages'];
		$blocked_entries      = $blocked_data['entries'];

		$current_page_device = $device_data['current_page'];
		$total_items_device  = $device_data['total_items'];
		$total_pages_device  = $device_data['total_pages'];
		$device_entries      = $device_data['entries'];

		$current_page_ip_banned = $ip_banned_data['current_page'];
		$total_items_ip_banned  = $ip_banned_data['total_items'];
		$total_pages_ip_banned  = $ip_banned_data['total_pages'];
		$ip_banned_entries      = $ip_banned_data['entries'];

		$current_page_domain_blocking = $domain_blocking_data['current_page'];
		$total_items_domain_blocking  = $domain_blocking_data['total_items'];
		$total_pages_domain_blocking  = $domain_blocking_data['total_pages'];
		$domain_blocking_entries      = $domain_blocking_data['entries'];

		$current_page_address_blocking = $address_blocking_data['current_page'];
		$total_items_address_blocking  = $address_blocking_data['total_items'];
		$total_pages_address_blocking  = $address_blocking_data['total_pages'];
		$address_blocking_entries      = $address_blocking_data['entries'];

		$show_name_col    = ( $premium_active && get_option( 'wc_blacklist_customer_name_blocking_enabled', '0' ) === '1' );
		$show_reason_col  = $premium_active;
		$colspan_blocklist = 6 + ( $show_name_col ? 1 : 0 ) + ( $show_reason_col ? 1 : 0 );
		$colspan_ip       = 6 + ( $show_reason_col ? 1 : 0 );

		$reason_map = array(
			'stolen_card'   => __( 'Stolen card', 'wc-blacklist-manager' ),
			'chargeback'    => __( 'Chargeback', 'wc-blacklist-manager' ),
			'fraud_network' => __( 'Fraud network', 'wc-blacklist-manager' ),
			'spam'          => __( 'Spam', 'wc-blacklist-manager' ),
			'policy_abuse'  => __( 'Policy abuse', 'wc-blacklist-manager' ),
			'other'         => __( 'Other', 'wc-blacklist-manager' ),
		);

		include 'views/dashboard-form.php';

		unset( $_SESSION['wc_blacklist_manager_messages'] );
	}

	public function handle_enable_global_blacklist() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'wc-blacklist-manager' ) );
		}

		check_admin_referer( 'enable_global_blacklist' );

		update_option( 'wc_blacklist_enable_global_blacklist', '1' );

		$redirect = wp_get_referer();

		if ( ! $redirect ) {
			$redirect = admin_url();
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	private function handle_search() {
		$search_query = '';

		if ( isset( $_GET['blacklist_search_nonce'], $_GET['blacklist_search'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET['blacklist_search_nonce'] ) );

			if ( wp_verify_nonce( $nonce, 'blacklist_search_action' ) ) {
				$search_query = trim(
					sanitize_text_field(
						wp_unslash( $_GET['blacklist_search'] )
					)
				);
			}
		}

		return $search_query;
	}

	private function fetch_entries_by_search_words( $search_query ) {
		$search_words = array_filter( array_map( 'sanitize_text_field', explode( ' ', $search_query ) ) );

		if ( empty( $search_words ) ) {
			$sql = "SELECT * FROM {$this->table_name}";
		} else {
			$like_clauses = array_map(
				function( $word ) {
					$like = '%' . $this->wpdb->esc_like( $word ) . '%';

					return $this->wpdb->prepare(
						"(phone_number LIKE %s
						OR email_address LIKE %s
						OR ip_address LIKE %s
						OR domain LIKE %s
						OR customer_address LIKE %s
						OR first_name LIKE %s
						OR last_name LIKE %s)",
						$like,
						$like,
						$like,
						$like,
						$like,
						$like,
						$like
					);
				},
				$search_words
			);

			$sql = "SELECT * FROM {$this->table_name} WHERE " . implode( ' OR ', $like_clauses );
		}

		return $this->wpdb->get_results( $sql );
	}

	public function handle_form_submission() {
		if ( ! $this->current_user_can_manage_dashboard() ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-blacklist-manager' ) );
		}

		if ( isset( $_POST['submit'] ) && check_admin_referer( 'add_suspect_action_nonce', 'add_suspect_action_nonce_field' ) ) {
			$new_first_name    = isset( $_POST['new_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_first_name'] ) ) : '';
			$new_last_name     = isset( $_POST['new_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_last_name'] ) ) : '';
			$new_phone_number  = isset( $_POST['new_phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['new_phone_number'] ) ) : '';
			$new_email_address = isset( $_POST['new_email_address'] ) ? sanitize_email( $this->get_request_value( $_POST['new_email_address'] ) ) : '';
			$status            = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'suspect';

			$is_blocked = ( 'blocked' === $status ) ? 1 : 0;

			$redirect_base = wp_get_referer();
			if ( ! $redirect_base ) {
				$redirect_base = admin_url();
			}

			if (
				'' === $new_first_name &&
				'' === $new_last_name &&
				'' === $new_phone_number &&
				'' === $new_email_address
			) {
				$message = esc_html__( 'There is nothing to add.', 'wc-blacklist-manager' );

				$redirect_url = add_query_arg(
					array(
						'add_suspect_message' => $message,
						'status'              => $status,
					),
					$redirect_base
				);

				wp_safe_redirect( esc_url_raw( $redirect_url ) );
				exit;
			}

			$normalized_phone = '';
			if ( ! empty( $new_phone_number ) ) {
				$normalized_phone = yobm_normalize_phone( $new_phone_number );
			}

			$normalized_email = '';
			if ( ! empty( $new_email_address ) && is_email( $new_email_address ) ) {
				$normalized_email = yobm_normalize_email( $new_email_address );
			}

			$data = array(
				'first_name'       => $new_first_name,
				'last_name'        => $new_last_name,
				'phone_number'     => $new_phone_number,
				'normalized_phone' => $normalized_phone,
				'email_address'    => $new_email_address,
				'normalized_email' => $normalized_email,
				'date_added'       => current_time( 'mysql' ),
				'sources'          => 'manual',
				'is_blocked'       => (int) $is_blocked,
			);

			$this->wpdb->insert( $this->table_name, $data );
			$new_insert_id = (int) $this->wpdb->insert_id;

			if ( $new_insert_id > 0 ) {
				$this->schedule_connection_create( $new_insert_id, $data, 'main' );

				if ( function_exists( 'wc_blacklist_manager_record_action_upsell_event' ) ) {
					wc_blacklist_manager_record_action_upsell_event( 'manual_entry' );

					if ( $is_blocked ) {
						wc_blacklist_manager_record_action_upsell_event( 'manual_block' );
					}
				}
			}

			$message = esc_html__( 'Entry has been added to the suspected list.', 'wc-blacklist-manager' );

			$redirect_url = add_query_arg(
				array(
					'add_suspect_message' => $message,
					'status'              => $status,
				),
				$redirect_base
			);

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}
	}

	private function handle_block_action( $id ) {
		$id    = absint( $id );
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'block_action' ) ) {
			$message = esc_html__( 'Security check failed. Please try again.', 'wc-blacklist-manager' );
		} else {
			global $wpdb;

				$entry = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$this->table_name} WHERE id = %d",
						$id
					)
				);

			if ( $entry && ! empty( $entry->sources ) ) {
				$pattern = '/Order ID: (\d+)/';
				if ( preg_match( $pattern, $entry->sources, $matches ) ) {
					$order_id = absint( $matches[1] );
					$order    = wc_get_order( $order_id );

					if ( $order ) {
						$user_id = absint( $order->get_user_id() );
						if ( $user_id ) {
							update_user_meta( $user_id, 'user_blocked', '1' );
						}
					}
				}
			}

				$wpdb->update(
					$this->table_name,
					array( 'is_blocked' => 1 ),
					array( 'id' => $id ),
					array( '%d' ),
					array( '%d' )
				);

				if ( function_exists( 'wc_blacklist_manager_clear_match_cache' ) ) {
					wc_blacklist_manager_clear_match_cache( $entry );
				}

			if ( $entry ) {
				$entry->is_blocked = 1;
				$this->schedule_connection_update( $id, (array) $entry, 'main' );

				if ( function_exists( 'wc_blacklist_manager_record_action_upsell_event' ) ) {
					wc_blacklist_manager_record_action_upsell_event( 'manual_block' );
				}
			}

			$message = esc_html__( 'Entry moved to blocked list successfully.', 'wc-blacklist-manager' );
		}

		$redirect_base = wp_get_referer();
		if ( ! $redirect_base ) {
			$redirect_base = admin_url();
		}

		$redirect_url = add_query_arg(
			array(
				'message' => $message,
			),
			$redirect_base
		);

		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	private function handle_delete_action( $id ) {
		$id = absint( $id );

		$nonce = isset( $_GET['_wpnonce'] )
			? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) )
			: '';

		$redirect_base = wp_get_referer();
		if ( ! $redirect_base ) {
			$redirect_base = admin_url();
		}

		if ( ! wp_verify_nonce( $nonce, 'delete_action' ) ) {
			$message      = esc_html__( 'Security check failed. Please try again.', 'wc-blacklist-manager' );
			$redirect_url = add_query_arg( array( 'delete_message' => $message ), $redirect_base );

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id, phone_number, email_address, ip_address, domain,
						is_blocked, sources, customer_address,
						first_name, last_name, date_added, order_id
				FROM {$this->table_name}
				WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		$order_id = ! empty( $row['order_id'] ) ? absint( $row['order_id'] ) : 0;

			$this->wpdb->delete( $this->table_name, array( 'id' => $id ), array( '%d' ) );

			if ( function_exists( 'wc_blacklist_manager_clear_match_cache' ) ) {
				wc_blacklist_manager_clear_match_cache( $row );
			}

		$this->delete_linked_address_rows_for_blacklist( $id );

		$this->record_remove_audit( $id, is_array( $row ) ? $row : array(), 'main' );
		$this->schedule_connection_delete( $id, is_array( $row ) ? $row : array(), 'main' );

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete_meta_data( '_blacklist_blocked_ids_main' );
				$order->delete_meta_data( '_blacklist_blocked_ids_address' );
				$order->delete_meta_data( '_blacklist_suspect_ids_main' );
				$order->delete_meta_data( '_blacklist_suspect_ids_address' );

				// Legacy cleanup.
				$order->delete_meta_data( '_blacklist_blocked_id' );
				$order->delete_meta_data( '_blacklist_suspect_id' );
				$order->save();
			}
		}

		$message      = esc_html__( 'Entry removed successfully.', 'wc-blacklist-manager' );
		$redirect_url = add_query_arg( array( 'delete_message' => $message ), $redirect_base );

		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	private function handle_delete_address_action( $id ) {
		$this->require_premium_feature();

		$id = absint( $id );

		$nonce = isset( $_GET['_wpnonce'] )
			? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) )
			: '';

		$redirect_base = wp_get_referer();
		if ( ! $redirect_base ) {
			$redirect_base = admin_url();
		}

		if ( ! wp_verify_nonce( $nonce, 'delete_address_action' ) ) {
			$message      = esc_html__( 'Security check failed. Please try again.', 'wc-blacklist-manager' );
			$redirect_url = add_query_arg(
				array(
					'add_address_message' => $message,
					'tab'                 => 'customer-address',
				),
				$redirect_base
			);

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT *
				FROM {$this->address_table_name}
				WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			$message      = esc_html__( 'Address entry not found.', 'wc-blacklist-manager' );
			$redirect_url = add_query_arg(
				array(
					'add_address_message' => $message,
					'tab'                 => 'customer-address',
				),
				$redirect_base
			);

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		$this->wpdb->delete(
			$this->address_table_name,
			array(
				'id' => $id,
			),
			array(
				'%d',
			)
		);

		$this->record_remove_audit( $id, $row, 'address' );
		$this->schedule_connection_delete( $id, $row, 'address' );

		$message      = esc_html__( 'Address entry removed successfully.', 'wc-blacklist-manager' );
		$redirect_url = add_query_arg(
			array(
				'add_address_message' => $message,
				'tab'                 => 'customer-address',
			),
			$redirect_base
		);

		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	private function handle_messages() {
		$messages = array();

		if ( isset( $_GET['add_ip_message'] ) ) {
			$messages[] = sanitize_text_field( urldecode( wp_unslash( $_GET['add_ip_message'] ) ) );
		}

		if ( isset( $_GET['add_address_message'] ) ) {
			$messages[] = sanitize_text_field( urldecode( wp_unslash( $_GET['add_address_message'] ) ) );
		}

		if ( isset( $_GET['add_domain_message'] ) ) {
			$messages[] = sanitize_text_field( urldecode( wp_unslash( $_GET['add_domain_message'] ) ) );
		}

		if ( isset( $_GET['add_suspect_message'] ) ) {
			$messages[] = sanitize_text_field( urldecode( wp_unslash( $_GET['add_suspect_message'] ) ) );
		}

		if ( isset( $_GET['delete_message'] ) ) {
			$messages[] = sanitize_text_field( urldecode( wp_unslash( $_GET['delete_message'] ) ) );
		}

		if ( isset( $_GET['message'] ) ) {
			$messages[] = sanitize_text_field( urldecode( wp_unslash( $_GET['message'] ) ) );
		}

		if ( ! empty( $messages ) ) {
			$_SESSION['wc_blacklist_manager_messages'] = implode( ' ', $messages );
		}

		$this->message = isset( $_SESSION['wc_blacklist_manager_messages'] ) ? $_SESSION['wc_blacklist_manager_messages'] : '';
	}

	private function clear_message() {
		$this->message = array();
	}

	private function build_query( $search_words ) {
		$where_parts = array();

		if ( ! empty( $search_words ) ) {
			foreach ( $search_words as $word ) {
				$word_like      = '%' . $this->wpdb->esc_like( $word ) . '%';
				$where_parts[] = $this->wpdb->prepare(
					"(phone_number LIKE %s OR email_address LIKE %s OR ip_address LIKE %s OR domain LIKE %s)",
					$word_like,
					$word_like,
					$word_like,
					$word_like
				);
			}
		}

		return ! empty( $where_parts ) ? implode( ' OR ', $where_parts ) : '1=1';
	}

	private function fetch_paginated_entries( $table_name, $where_clause, $order_by, $items_per_page, $offset ) {
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$order_by} DESC LIMIT %d OFFSET %d",
			$items_per_page,
			$offset
		);

		return $this->wpdb->get_results( $query );
	}

	private function fetch_total_count( $table_name, $where_clause ) {
		$query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
		return (int) $this->wpdb->get_var( $query );
	}

	private function handle_pagination( $type, $base_where_clause ) {
		$current_page = isset( $_GET[ 'paged_' . $type ] ) ? max( 1, intval( wp_unslash( $_GET[ 'paged_' . $type ] ) ) ) : 1;
		$where_clause = $this->build_where_clause( $base_where_clause );
		$total_items  = $this->fetch_total_count( $this->table_name, $where_clause );
		$total_pages  = (int) ceil( $total_items / $this->items_per_page );
		$offset       = ( $current_page - 1 ) * $this->items_per_page;
		$entries      = $this->fetch_paginated_entries( $this->table_name, $where_clause, 'date_added', $this->items_per_page, $offset );

		return array(
			'current_page' => $current_page,
			'total_items'  => $total_items,
			'total_pages'  => $total_pages,
			'entries'      => $entries,
		);
	}

	private function handle_device_pagination() {
		$current_page = isset( $_GET['paged_device'] ) ? max( 1, intval( wp_unslash( $_GET['paged_device'] ) ) ) : 1;

		$search_query = $this->handle_search();
		$search_terms = array_filter( array_map( 'sanitize_text_field', explode( ' ', $search_query ) ) );

		$where_clause = '1=1';

		if ( ! empty( $search_terms ) ) {
			$where_parts = array();

			foreach ( $search_terms as $term ) {
				$like = '%' . $this->wpdb->esc_like( $term ) . '%';

				$where_parts[] = $this->wpdb->prepare(
					"(device_id LIKE %s
					OR last_ip_address LIKE %s
					OR block_reason LIKE %s)",
					$like,
					$like,
					$like
				);
			}

			if ( ! empty( $where_parts ) ) {
				$where_clause .= ' AND (' . implode( ' OR ', $where_parts ) . ')';
			}
		}

		$total_items = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->device_table_name} WHERE {$where_clause}"
		);

		$total_pages = (int) ceil( $total_items / $this->items_per_page );
		$offset      = ( $current_page - 1 ) * $this->items_per_page;

		$query = $this->wpdb->prepare(
			"SELECT *
			FROM {$this->device_table_name}
			WHERE {$where_clause}
			ORDER BY last_seen DESC
			LIMIT %d OFFSET %d",
			$this->items_per_page,
			$offset
		);

		$entries = $this->wpdb->get_results( $query );

		return array(
			'current_page' => $current_page,
			'total_items'  => $total_items,
			'total_pages'  => $total_pages,
			'entries'      => $entries,
		);
	}

	private function normalize_device_record_for_response( $device ) {
		if ( ! is_array( $device ) ) {
			return array();
		}

		$device['order_count']        = isset( $device['order_count'] ) ? (int) $device['order_count'] : 0;
		$device['user_count']         = isset( $device['user_count'] ) ? (int) $device['user_count'] : 0;
		$device['email_count']        = isset( $device['email_count'] ) ? (int) $device['email_count'] : 0;
		$device['phone_count']        = isset( $device['phone_count'] ) ? (int) $device['phone_count'] : 0;
		$device['ip_count']           = isset( $device['ip_count'] ) ? (int) $device['ip_count'] : 0;
		$device['last_order_id']      = isset( $device['last_order_id'] ) ? (int) $device['last_order_id'] : 0;
		$device['is_blocked']         = isset( $device['is_blocked'] ) ? (int) $device['is_blocked'] : 0;
		$device['last_payload_valid'] = isset( $device['last_payload_valid'] ) ? (int) $device['last_payload_valid'] : 1;

		$validation_reasons = array();

		if ( ! empty( $device['last_validation_reasons'] ) ) {
			$decoded = json_decode( (string) $device['last_validation_reasons'], true );
			if ( is_array( $decoded ) ) {
				$validation_reasons = array_values(
					array_unique(
						array_filter(
							array_map( 'sanitize_text_field', $decoded )
						)
					)
				);
			}
		}

		$device['last_validation_reasons_list'] = $validation_reasons;

		return $device;
	}

	private function build_where_clause( $base_clause ) {
		$where_parts           = $this->prepare_search_terms();
		$additional_conditions = ! empty( $where_parts ) ? implode( ' OR ', $where_parts ) : '';

		return ! empty( $additional_conditions ) ? "{$base_clause} AND ({$additional_conditions})" : $base_clause;
	}

	private function prepare_search_terms() {
		$search_query = $this->handle_search();
		$search_terms = array_filter( array_map( 'sanitize_text_field', explode( ' ', $search_query ) ) );

		if ( empty( $search_terms ) ) {
			return array();
		}

		$like_clauses = array_map(
			function( $term ) {
				$like = '%' . $this->wpdb->esc_like( $term ) . '%';

				return $this->wpdb->prepare(
					"(phone_number LIKE %s OR email_address LIKE %s OR ip_address LIKE %s OR domain LIKE %s)",
					$like,
					$like,
					$like,
					$like
				);
			},
			$search_terms
		);

		return $like_clauses;
	}

	private function handle_address_pagination() {
		$current_page = isset( $_GET['paged_address_blocking'] ) ? max( 1, intval( wp_unslash( $_GET['paged_address_blocking'] ) ) ) : 1;
		$where_clause = $this->build_address_where_clause( '1=1' );
		$total_items  = $this->fetch_total_count( $this->address_table_name, $where_clause );
		$total_pages  = (int) ceil( $total_items / $this->items_per_page );
		$offset       = ( $current_page - 1 ) * $this->items_per_page;

		$query = $this->wpdb->prepare(
			"SELECT *
			FROM {$this->address_table_name}
			WHERE {$where_clause}
			ORDER BY date_added DESC
			LIMIT %d OFFSET %d",
			$this->items_per_page,
			$offset
		);

		$entries = $this->wpdb->get_results( $query );

		return array(
			'current_page' => $current_page,
			'total_items'  => $total_items,
			'total_pages'  => $total_pages,
			'entries'      => $entries,
		);
	}

	private function build_address_where_clause( $base_clause ) {
		$where_parts           = $this->prepare_address_search_terms();
		$additional_conditions = ! empty( $where_parts ) ? implode( ' OR ', $where_parts ) : '';

		return ! empty( $additional_conditions ) ? "{$base_clause} AND ({$additional_conditions})" : $base_clause;
	}

	private function prepare_address_search_terms() {
		$search_query = $this->handle_search();
		$search_terms = array_filter( array_map( 'sanitize_text_field', explode( ' ', $search_query ) ) );

		if ( empty( $search_terms ) ) {
			return array();
		}

		$like_clauses = array_map(
			function( $term ) {
				$like = '%' . $this->wpdb->esc_like( $term ) . '%';

				return $this->wpdb->prepare(
					"(match_type LIKE %s
					OR country_code LIKE %s
					OR state_code LIKE %s
					OR city_norm LIKE %s
					OR postcode_norm LIKE %s
					OR address_line_norm LIKE %s
					OR address_full_norm LIKE %s
					OR address_display LIKE %s
					OR notes LIKE %s)",
					$like,
					$like,
					$like,
					$like,
					$like,
					$like,
					$like,
					$like,
					$like
				);
			},
			$search_terms
		);

		return $like_clauses;
	}

	public function handle_bulk_action_callback() {
		if ( ! $this->current_user_can_manage_dashboard() ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-blacklist-manager' ) );
		}

		check_admin_referer( 'yobm_nonce_action', 'yobm_nonce_field' );

		if (
			isset( $_POST['bulk_action'] ) &&
			'delete' === sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) &&
			! empty( $_POST['entry_ids'] )
		) {
			$entry_ids = array_map( 'absint', (array) wp_unslash( $_POST['entry_ids'] ) );
			$entry_ids = array_filter( $entry_ids, static function( $id ) {
				return $id > 0;
			} );

			foreach ( $entry_ids as $id ) {
				$row = $this->wpdb->get_row(
					$this->wpdb->prepare(
						"SELECT id, phone_number, email_address, ip_address, domain,
								is_blocked, sources, customer_address,
								first_name, last_name, date_added, order_id
						FROM {$this->table_name}
						WHERE id = %d",
						$id
					),
					ARRAY_A
				);

				$order_id = ! empty( $row['order_id'] ) ? absint( $row['order_id'] ) : 0;

					$this->wpdb->delete( $this->table_name, array( 'id' => $id ), array( '%d' ) );

					if ( function_exists( 'wc_blacklist_manager_clear_match_cache' ) ) {
						wc_blacklist_manager_clear_match_cache( $row );
					}

				$this->delete_linked_address_rows_for_blacklist( $id );

				$this->record_remove_audit( $id, is_array( $row ) ? $row : array(), 'main' );
				$this->schedule_connection_delete( $id, is_array( $row ) ? $row : array(), 'main' );

				if ( $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order ) {
						$order->delete_meta_data( '_blacklist_blocked_ids_main' );
						$order->delete_meta_data( '_blacklist_blocked_ids_address' );
						$order->delete_meta_data( '_blacklist_suspect_ids_main' );
						$order->delete_meta_data( '_blacklist_suspect_ids_address' );

						// Legacy cleanup.
						$order->delete_meta_data( '_blacklist_blocked_id' );
						$order->delete_meta_data( '_blacklist_suspect_id' );
						$order->save();
					}
				}
			}

			if ( function_exists( 'wc_blacklist_manager_record_action_upsell_event' ) ) {
				wc_blacklist_manager_record_action_upsell_event( 'bulk_cleanup', count( $entry_ids ) );
			}
		}

		$redirect_base = wp_get_referer();
		if ( ! $redirect_base ) {
			$redirect_base = admin_url();
		}

		wp_safe_redirect( esc_url_raw( $redirect_base ) );
		exit;
	}

	public function handle_add_ip_address() {
		if ( ! $this->current_user_can_manage_dashboard() ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-blacklist-manager' ) );
		}

		check_admin_referer( 'add_ip_address_nonce_action', 'add_ip_address_nonce_field' );

		$redirect_base = wp_get_referer();
		if ( ! $redirect_base ) {
			$redirect_base = admin_url();
		}

		$raw_ips      = isset( $_POST['ip-addresses'] ) ? (string) wp_unslash( $_POST['ip-addresses'] ) : '';
		$ip_addresses = explode( "\n", trim( $raw_ips ) );

		if ( empty( $raw_ips ) || empty( $ip_addresses ) ) {
			$message      = esc_html__( 'No IP addresses were added. Please provide valid IP addresses.', 'wc-blacklist-manager' );
			$redirect_url = add_query_arg( array( 'add_ip_message' => $message ), $redirect_base );

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		if ( count( $ip_addresses ) > 50 ) {
			$message      = esc_html__( 'Submission failed: You can only add up to 50 IP addresses at a time.', 'wc-blacklist-manager' );
			$redirect_url = add_query_arg( array( 'add_ip_message' => $message ), $redirect_base );

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		$ip_addresses_added = 0;

		foreach ( $ip_addresses as $ip_address ) {
			$ip_address = sanitize_text_field( $ip_address );

			if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
				$exists = (int) $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT COUNT(*) FROM {$this->table_name} WHERE ip_address = %s",
						$ip_address
					)
				);

				if ( 0 === $exists ) {
						$insert_data = array(
							'ip_address' => $ip_address,
							'date_added' => current_time( 'mysql', 1 ),
							'is_blocked' => 1,
							'sources'    => 'manual',
						);

						$inserted = $this->wpdb->insert(
							$this->table_name,
							$insert_data,
							array( '%s', '%s', '%d', '%s' )
						);

						if ( false === $inserted ) {
							continue;
						}

						$new_insert_id = (int) $this->wpdb->insert_id;

						if ( function_exists( 'wc_blacklist_manager_clear_match_cache' ) ) {
							wc_blacklist_manager_clear_match_cache( array( 'ip_address' => $ip_address ) );
						}

						$this->schedule_connection_create( $new_insert_id, $insert_data, 'main' );

						$ip_addresses_added++;
				}
			}
		}

		$message = sprintf(
			_n( 'One IP address added.', '%s IP addresses added.', $ip_addresses_added, 'wc-blacklist-manager' ),
			number_format_i18n( $ip_addresses_added )
		);

		if ( $ip_addresses_added > 0 && function_exists( 'wc_blacklist_manager_record_action_upsell_event' ) ) {
			wc_blacklist_manager_record_action_upsell_event( 'ip_manual_add', $ip_addresses_added );
		}

		$redirect_url = add_query_arg( array( 'add_ip_message' => $message ), $redirect_base );
		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	public function handle_add_address() {
		if ( ! $this->current_user_can_manage_dashboard() ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-blacklist-manager' ) );
		}

		$this->require_premium_feature();

		check_admin_referer( 'add_address_nonce_action', 'add_address_nonce_field' );

		$redirect_base = wp_get_referer();
		if ( ! $redirect_base ) {
			$redirect_base = admin_url();
		}

		$address_1 = isset( $_POST['address_1_input'] ) ? sanitize_text_field( wp_unslash( $_POST['address_1_input'] ) ) : '';
		$address_2 = isset( $_POST['address_2_input'] ) ? sanitize_text_field( wp_unslash( $_POST['address_2_input'] ) ) : '';
		$city      = isset( $_POST['city_input'] ) ? sanitize_text_field( wp_unslash( $_POST['city_input'] ) ) : '';
		$state     = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
		$state     = $state ? $state : ( isset( $_POST['state_input'] ) ? sanitize_text_field( wp_unslash( $_POST['state_input'] ) ) : '' );
		$postcode  = isset( $_POST['postcode_input'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode_input'] ) ) : '';
		$country   = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';

		$normalized = yobm_normalize_address_parts(
			array(
				'address_1' => $address_1,
				'address_2' => $address_2,
				'city'      => $city,
				'state'     => $state,
				'postcode'  => $postcode,
				'country'   => $country,
			)
		);

		$has_street_or_city = ( '' !== trim( $address_1 ) || '' !== trim( $address_2 ) || '' !== trim( $city ) );
		$has_postcode       = '' !== $normalized['postcode_norm'];
		$has_state          = '' !== $normalized['state_code'];
		$has_country        = '' !== $normalized['country_code'];

		$match_type = '';

		if ( $has_street_or_city ) {
			$match_type = 'address';
		} elseif ( $has_postcode && $has_country ) {
			$match_type = 'postcode';
		} elseif ( $has_state && $has_country ) {
			$match_type = 'state';
		}

		if ( '' === $match_type ) {
			$message = esc_html__( 'Please provide either a full address, or a postcode with country, or a state with country.', 'wc-blacklist-manager' );

			$redirect_url = add_query_arg(
				array(
					'add_address_message' => $message,
				),
				$redirect_base
			);

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		if ( 'address' === $match_type && '' === $normalized['address_full_norm'] ) {
			$message = esc_html__( 'No valid address provided. Please fill in at least one address field.', 'wc-blacklist-manager' );

			$redirect_url = add_query_arg(
				array(
					'add_address_message' => $message,
				),
				$redirect_base
			);

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		$existing_id = 0;
		$existing_sql = '';

		if ( 'address' === $match_type ) {
			$existing_sql = $this->wpdb->prepare(
				"SELECT id
				FROM {$this->address_table_name}
				WHERE match_type = %s
				AND address_hash = %s
				LIMIT 1",
				'address',
				$normalized['address_hash']
			);

			$existing_id = (int) $this->wpdb->get_var( $existing_sql );
		} elseif ( 'postcode' === $match_type ) {
			$existing_sql = $this->wpdb->prepare(
				"SELECT id
				FROM {$this->address_table_name}
				WHERE match_type = %s
				AND country_code = %s
				AND postcode_norm = %s
				LIMIT 1",
				'postcode',
				$normalized['country_code'],
				$normalized['postcode_norm']
			);

			$existing_id = (int) $this->wpdb->get_var( $existing_sql );
		} elseif ( 'state' === $match_type ) {
			$existing_sql = $this->wpdb->prepare(
				"SELECT id
				FROM {$this->address_table_name}
				WHERE match_type = %s
				AND country_code = %s
				AND state_code = %s
				LIMIT 1",
				'state',
				$normalized['country_code'],
				$normalized['state_code']
			);

			$existing_id = (int) $this->wpdb->get_var( $existing_sql );
		}

		if ( $existing_id > 0 ) {
			$message = esc_html__( 'This address rule already exists in the blacklist.', 'wc-blacklist-manager' );

			$redirect_url = add_query_arg(
				array(
					'add_address_message' => $message,
				),
				$redirect_base
			);

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		$address_display            = '';
		$address_hash               = '';
		$address_full               = '';
		$address_line               = '';
		$address_core_norm          = '';
		$address_core_hash          = '';
		$address_line_postcode_hash = '';
		$city_norm                  = '';
		$postcode_norm              = '';
		$state_code                 = '';
		$country_code               = '';

		if ( 'address' === $match_type ) {
			$address_display            = $normalized['address_display'];
			$address_hash               = $normalized['address_hash'];
			$address_full               = $normalized['address_full_norm'];
			$address_line               = $normalized['address_line_norm'];
			$address_core_norm          = $normalized['address_core_norm'];
			$address_core_hash          = $normalized['address_core_hash'];
			$address_line_postcode_hash = $normalized['address_line_postcode_hash'];
			$city_norm                  = $normalized['city_norm'];
			$postcode_norm              = $normalized['postcode_norm'];
			$state_code                 = $normalized['state_code'];
			$country_code               = $normalized['country_code'];
		} elseif ( 'postcode' === $match_type ) {
			$address_display = yobm_build_address_display(
				array(
					$postcode,
					$country,
				)
			);

			$postcode_norm = $normalized['postcode_norm'];
			$country_code  = $normalized['country_code'];
		} elseif ( 'state' === $match_type ) {
			$address_display = yobm_build_address_display(
				array(
					$state,
					$country,
				)
			);

			$state_code   = $normalized['state_code'];
			$country_code = $normalized['country_code'];
		}

		$insert_data = array(
			'match_type'                 => $match_type,
			'is_blocked'                 => 1,
			'country_code'               => $country_code,
			'state_code'                 => $state_code,
			'city_norm'                  => $city_norm,
			'postcode_norm'              => $postcode_norm,
			'address_line_norm'          => $address_line,
			'address_core_norm'          => $address_core_norm,
			'address_full_norm'          => $address_full,
			'address_core_hash'          => $address_core_hash,
			'address_line_postcode_hash' => $address_line_postcode_hash,
			'address_hash'               => $address_hash,
			'address_display'            => $address_display,
			'notes'                      => '',
			'date_added'                 => current_time( 'mysql', 1 ),
		);

		$insert_format = array(
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
		);

		$inserted = $this->wpdb->insert(
			$this->address_table_name,
			$insert_data,
			$insert_format
		);

		if ( false === $inserted ) {
			$message = esc_html__( 'Failed to add the address rule. Please check debug.log for details.', 'wc-blacklist-manager' );

			$redirect_url = add_query_arg(
				array(
					'add_address_message' => $message,
				),
				$redirect_base
			);

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		$new_insert_id = absint( $this->wpdb->insert_id );

		$sync_row               = $insert_data;
		$sync_row['id']         = $new_insert_id;
		$sync_row['is_blocked'] = 1;
		$this->schedule_connection_create( $new_insert_id, $sync_row, 'address' );

		if ( 'address' === $match_type ) {
			$message = esc_html__( 'Address added successfully.', 'wc-blacklist-manager' );
		} elseif ( 'postcode' === $match_type ) {
			$message = esc_html__( 'Postcode rule added successfully.', 'wc-blacklist-manager' );
		} else {
			$message = esc_html__( 'State rule added successfully.', 'wc-blacklist-manager' );
		}

		$redirect_url = add_query_arg(
			array(
				'add_address_message' => $message,
			),
			$redirect_base
		);

		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	public function handle_add_domain() {
		if ( ! $this->current_user_can_manage_dashboard() ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-blacklist-manager' ) );
		}

		if ( ! isset( $_POST['add_domain_nonce_field'] ) || ! check_admin_referer( 'add_domain_nonce_action', 'add_domain_nonce_field' ) ) {
			wp_die( esc_html__( 'Sorry, your nonce did not verify.', 'wc-blacklist-manager' ) );
		}

		$redirect_base = wp_get_referer();
		if ( ! $redirect_base ) {
			$redirect_base = admin_url();
		}

		$raw_domains = isset( $_POST['domains'] ) ? (string) wp_unslash( $_POST['domains'] ) : '';
		$domains     = explode( "\n", trim( $raw_domains ) );

		if ( empty( trim( $raw_domains ) ) ) {
			$message      = esc_html__( 'No domains were provided.', 'wc-blacklist-manager' );
			$redirect_url = add_query_arg( array( 'add_domain_message' => $message ), $redirect_base );

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		if ( count( $domains ) > 50 ) {
			$message      = esc_html__( 'Submission failed: You can only add up to 50 domains at a time.', 'wc-blacklist-manager' );
			$redirect_url = add_query_arg( array( 'add_domain_message' => $message ), $redirect_base );

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		$domains_added   = 0;
		$invalid_domains = array();

		foreach ( $domains as $domain ) {
				$domain = strtolower( sanitize_text_field( $domain ) );

			if ( ! empty( $domain ) && preg_match( '/^([a-zA-Z0-9]+(-[a-zA-Z0-9]+)*\.)+[a-zA-Z]{2,}$/', $domain ) ) {
				$exists = (int) $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT COUNT(*) FROM {$this->table_name} WHERE domain = %s",
						$domain
					)
				);

				if ( 0 === $exists ) {
						$insert_data = array(
							'domain'     => $domain,
							'date_added' => current_time( 'mysql', 1 ),
							'is_blocked' => 1,
							'sources'    => 'manual',
						);

						$inserted = $this->wpdb->insert(
							$this->table_name,
							$insert_data,
							array( '%s', '%s', '%d', '%s' )
						);

						if ( false === $inserted ) {
							continue;
						}

						$new_insert_id = (int) $this->wpdb->insert_id;

						if ( function_exists( 'wc_blacklist_manager_clear_match_cache' ) ) {
							wc_blacklist_manager_clear_match_cache( array( 'domain' => $domain ) );
						}

						$this->schedule_connection_create( $new_insert_id, $insert_data, 'main' );

						$domains_added++;
					}
			} else {
				$invalid_domains[] = $domain;
			}
		}

		$message = '';

		if ( $domains_added > 0 ) {
			$message .= sprintf(
				_n( 'One domain added.', '%s domains added.', $domains_added, 'wc-blacklist-manager' ),
				number_format_i18n( $domains_added )
			);
		}

		if ( ! empty( $invalid_domains ) ) {
			$invalid_message = sprintf(
				_n(
					'One domain was not added because it is not in the right format.',
					'%s domains were not added because they are not in the right format.',
					count( $invalid_domains ),
					'wc-blacklist-manager'
				),
				number_format_i18n( count( $invalid_domains ) )
			);

			$message .= $message ? ' ' . $invalid_message : $invalid_message;
		}

		if ( '' === $message ) {
			$message = esc_html__( 'No new domains were added.', 'wc-blacklist-manager' );
		}

		if ( $domains_added > 0 && function_exists( 'wc_blacklist_manager_record_action_upsell_event' ) ) {
			wc_blacklist_manager_record_action_upsell_event( 'domain_manual_add', $domains_added );
		}

		$redirect_url = add_query_arg( array( 'add_domain_message' => $message ), $redirect_base );
		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	public function handle_bulk_action_address_callback() {
		if ( ! $this->current_user_can_manage_dashboard() ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-blacklist-manager' ) );
		}

		$this->require_premium_feature();

		check_admin_referer( 'yobm_nonce_action', 'yobm_nonce_field' );

		if (
			isset( $_POST['bulk_action'] ) &&
			'delete' === sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) &&
			! empty( $_POST['entry_ids'] )
		) {
			$entry_ids = array_map( 'absint', (array) wp_unslash( $_POST['entry_ids'] ) );
			$entry_ids = array_filter(
				$entry_ids,
				static function( $id ) {
					return $id > 0;
				}
			);
			foreach ( $entry_ids as $id ) {
				$address_row = $this->wpdb->get_row(
					$this->wpdb->prepare(
						"SELECT *
						FROM {$this->address_table_name}
						WHERE id = %d",
						$id
					),
					ARRAY_A
				);

				if ( empty( $address_row ) ) {
					continue;
				}

				$this->wpdb->delete(
					$this->address_table_name,
					array( 'id' => $id ),
					array( '%d' )
				);

				$this->record_remove_audit( $id, $address_row, 'address' );
				$this->schedule_connection_delete( $id, $address_row, 'address' );
			}
		}

		$redirect_base = wp_get_referer();
		if ( ! $redirect_base ) {
			$redirect_base = admin_url();
		}

		wp_safe_redirect( esc_url_raw( $redirect_base ) );
		exit;
	}

	private function get_device_links_grouped( $device_id ) {
		$device_id = sanitize_text_field( (string) $device_id );

		if ( '' === $device_id ) {
			return array(
				'emails' => array(),
				'phones' => array(),
				'ips'    => array(),
				'users'  => array(),
				'orders' => array(),
			);
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT identity_type, identity_value
				FROM {$this->device_links_table_name}
				WHERE device_id = %s
				ORDER BY last_seen DESC",
				$device_id
			),
			ARRAY_A
		);

		$grouped = array(
			'emails' => array(),
			'phones' => array(),
			'ips'    => array(),
			'users'  => array(),
			'orders' => array(),
		);

		foreach ( $rows as $row ) {
			$type  = isset( $row['identity_type'] ) ? (string) $row['identity_type'] : '';
			$value = isset( $row['identity_value'] ) ? (string) $row['identity_value'] : '';

			if ( '' === $type || '' === $value ) {
				continue;
			}

			if ( 'email' === $type ) {
				$grouped['emails'][] = $value;
			} elseif ( 'phone' === $type ) {
				$grouped['phones'][] = $value;
			} elseif ( 'ip' === $type ) {
				$grouped['ips'][] = $value;
			} elseif ( 'user' === $type ) {
				$grouped['users'][] = $value;
			} elseif ( 'order' === $type ) {
				$grouped['orders'][] = $value;
			}
		}

		return $grouped;
	}	

	public function ajax_get_device_details() {
		if ( ! $this->current_user_can_manage_dashboard( true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to access this data.', 'wc-blacklist-manager' ),
				),
				403
			);
		}

		$this->require_premium_feature( 'ajax' );

		check_ajax_referer( 'wc_blacklist_device_details_nonce', 'nonce' );

		$device_id = isset( $_POST['device_id'] ) ? sanitize_text_field( wp_unslash( $_POST['device_id'] ) ) : '';

		if ( '' === $device_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'Missing device ID.', 'wc-blacklist-manager' ),
				),
				400
			);
		}

		$device = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT *
				FROM {$this->device_table_name}
				WHERE device_id = %s
				LIMIT 1",
				$device_id
			),
			ARRAY_A
		);

		if ( empty( $device ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Device not found.', 'wc-blacklist-manager' ),
				),
				404
			);
		}

		$device = $this->normalize_device_record_for_response( $device );
		$links  = $this->get_device_links_grouped( $device_id );

		wp_send_json_success(
			array(
				'device' => $device,
				'links'  => $links,
			)
		);
	}
}

$blacklist_manager = new WC_Blacklist_Manager_Dashboard();
$blacklist_manager->init_hooks();

class WC_Blacklist_Manager_Address_Selection {

	public static function enqueue_scripts( $hook ) {
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'wc-blacklist-manager' !== $current_page ) {
			return;
		}

		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_script( 'woocommerce_admin' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'select2' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'woocommerce_admin' );
	}

	public static function initialize_selectWoo() {
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'wc-blacklist-manager' !== $current_page ) {
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

add_action( 'admin_enqueue_scripts', array( 'WC_Blacklist_Manager_Address_Selection', 'enqueue_scripts' ) );
add_action( 'admin_footer', array( 'WC_Blacklist_Manager_Address_Selection', 'initialize_selectWoo' ) );
