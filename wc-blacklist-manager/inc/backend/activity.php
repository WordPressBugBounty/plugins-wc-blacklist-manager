<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Activity_Log {
    private $hook_suffix = '';
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_activity_log_submenu']);
    }

    public function add_activity_log_submenu() {
        $settings_instance = new WC_Blacklist_Manager_Settings();
        $premium_active = $settings_instance->is_premium_active();

        $user_has_permission = false;
        if ($premium_active) {
            $allowed_roles = get_option('wc_blacklist_settings_permission', []);
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
            $this->hook_suffix = add_submenu_page(
				'wc-blacklist-manager',
				__('Activity logs', 'wc-blacklist-manager'),
				__('Activity logs', 'wc-blacklist-manager'),
				'read',
				'wc-blacklist-manager-activity-logs',
				[$this, 'activity_log_page_content']
			);

            add_action( 'admin_print_styles-' . $this->hook_suffix, [$this, 'print_activity_log_badge_styles'] );
        }
    }

	public function print_activity_log_badge_styles() {
		// Same badges used in the demo; now applied to the live table too.
		echo '<style>
			.bm-status-block   { background:#d63638; color:#fff; padding:2px 8px; border-radius:12px; font-size:12px; display:inline-block; line-height:1.6; }
			.bm-status-suspect { background:#ffb900; color:#222; padding:2px 8px; border-radius:12px; font-size:12px; display:inline-block; line-height:1.6; }
			.bm-status-verify  { background:#00a32a; color:#fff; padding:2px 8px; border-radius:12px; font-size:12px; display:inline-block; line-height:1.6; }
			/* table niceties */
			.wp-list-table.widefat.fixed tr td { vertical-align: middle; }
			@media (prefers-color-scheme: dark) {
				.bm-status-suspect { color:#111; }
			}
		</style>';
	}

    public function activity_log_page_content() {
        $settings_instance = new WC_Blacklist_Manager_Settings();
        $premium_active = $settings_instance->is_premium_active();
        $woocommerce_active = class_exists( 'WooCommerce' );
		$unlock_url = $woocommerce_active
			? 'https://yoohw.com/product/blacklist-manager-premium/'
			: 'https://yoohw.com/product/blacklist-manager-premium-for-forms/';
            
        $message = $this->handle_form_submission();

        require_once plugin_dir_path( __FILE__ ) . 'views/table/activity-log.php';
        $template_path = plugin_dir_path(__FILE__) . 'views/activity-log.php';

        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<div class="error"><p>Failed to load the settings template.</p></div>';
        }
    }

    private function handle_form_submission() {
        // Run only on POST + valid nonce
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            return;
        }

        if ( ! isset( $_POST['bulk_detection_log_nonce'] ) || ! wp_verify_nonce( $_POST['bulk_detection_log_nonce'], 'bulk_detection_log_delete' ) ) {
            return;
        }

        // WP_List_Table uses 'action' (top) and 'action2' (bottom)
        $action = '';
        if ( isset( $_POST['action'] ) && '-1' !== $_POST['action'] ) {
            $action = sanitize_text_field( wp_unslash( $_POST['action'] ) );
        } elseif ( isset( $_POST['action2'] ) && '-1' !== $_POST['action2'] ) {
            $action = sanitize_text_field( wp_unslash( $_POST['action2'] ) );
        }

        // Only handle delete; ignore anything else
        if ( 'delete' !== $action ) {
            return;
        }

        // Ensure there are selected IDs
        if ( empty( $_POST['bulk_ids'] ) || ! is_array( $_POST['bulk_ids'] ) ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'No items selected.', 'wc-blacklist-manager' ) . '</p></div>';
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_blacklist_detection_log';

        // Sanitize to integers and bail if empty after sanitization
        $bulk_ids = array_map( 'intval', (array) $_POST['bulk_ids'] );
        $bulk_ids = array_filter( $bulk_ids );
        if ( empty( $bulk_ids ) ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'No valid items selected.', 'wc-blacklist-manager' ) . '</p></div>';
            return;
        }

        // Build a safe IN() list
        $ids_placeholders = implode( ',', array_fill( 0, count( $bulk_ids ), '%d' ) );
        $sql              = "DELETE FROM {$table_name} WHERE id IN ($ids_placeholders)";

        $prepared = $wpdb->prepare( $sql, $bulk_ids );
        $result   = $wpdb->query( $prepared );

        if ( false !== $result ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Selected detection log entries have been deleted.', 'wc-blacklist-manager' ) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'There was an error deleting the selected entries.', 'wc-blacklist-manager' ) . '</p></div>';
        }
    }
}

new WC_Blacklist_Manager_Activity_Log();
