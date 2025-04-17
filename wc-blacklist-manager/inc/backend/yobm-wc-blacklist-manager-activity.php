<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Activity_Log {
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
            add_submenu_page(
                'wc-blacklist-manager',
                __('Activity logs', 'wc-blacklist-manager'),
                __('Activity logs', 'wc-blacklist-manager'),
                'read',
                'wc-blacklist-manager-activity-logs',
                [$this, 'activity_log_page_content']
            );
        }
    }

    public function activity_log_page_content() {
        $settings_instance = new WC_Blacklist_Manager_Settings();
        $premium_active = $settings_instance->is_premium_active();
        $message = $this->handle_form_submission();
        $template_path = plugin_dir_path(__FILE__) . 'views/yobm-wc-blacklist-manager-activity-log.php';

        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<div class="error"><p>Failed to load the settings template.</p></div>';
        }
    }

    private function handle_form_submission() {
		// Check if the form has been submitted and the nonce is valid.
		if ( isset( $_POST['bulk_submit'] ) && check_admin_referer( 'bulk_detection_log_delete', 'bulk_detection_log_nonce' ) ) {
			// Get the bulk action.
			$action = isset( $_POST['action'] ) ? sanitize_text_field( $_POST['action'] ) : '';
			// Ensure there are selected IDs.
			if ( 'delete' === $action && ! empty( $_POST['bulk_ids'] ) && is_array( $_POST['bulk_ids'] ) ) {
				global $wpdb;
				$table_name = $wpdb->prefix . 'wc_blacklist_detection_log';
				// Sanitize the IDs to be safe for SQL.
				$bulk_ids = array_map( 'intval', $_POST['bulk_ids'] );
				$ids = implode( ',', $bulk_ids );
				// Execute the deletion.
				$result = $wpdb->query( "DELETE FROM $table_name WHERE id IN ($ids)" );
				
				if ( false !== $result ) {
					echo '<div class="updated"><p>' . esc_html__( 'Selected detection log entries have been deleted.', 'wc-blacklist-manager' ) . '</p></div>';
				} else {
					echo '<div class="error"><p>' . esc_html__( 'There was an error deleting the selected entries.', 'wc-blacklist-manager' ) . '</p></div>';
				}
			}
		}
	}	
}

new WC_Blacklist_Manager_Activity_Log();
