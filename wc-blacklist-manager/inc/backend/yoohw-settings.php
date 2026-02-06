<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('YoOhw_Settings')) {
    
    class YoOhw_Settings {

        public function __construct() {
            global $yo_ohw_settings_submenu_added;
            $license_status = get_option('wc_blacklist_manager_premium_license_status');
            if (!isset($yo_ohw_settings_submenu_added) && $license_status === 'activated') {
                add_action('admin_menu', [$this, 'add_settings_yoohw_submenu']);
                $yo_ohw_settings_submenu_added = true;
            }
            $this->check_license_status();
        }

        public function check_license_status() {
            $license_status = get_option('wc_blacklist_manager_premium_license_status');
            if ($license_status === 'deactivated' || $license_status === false) {
                update_option('yoohw_settings_disable_menu', '0');
            }
        }

        public function add_settings_yoohw_submenu() {
            $parent_slug = 'yo-ohw';
            $page_title = 'Settings';
            $menu_title = 'Settings';
            $capability = 'manage_options';
            $menu_slug = 'yoohw-settings';
            $function = [$this, 'settings_page'];
        
            add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function);
        }

        public function settings_page() {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
            do_action('yoohw_settings_content');
            echo '</div>';
        }
    }

    // Instantiate the class
    new YoOhw_Settings();
}

if (!class_exists('YoOhw_Settings_Content')) {

    class YoOhw_Settings_Content {

        public function __construct() {
            add_action('yoohw_settings_content', [$this, 'yoohw_studio_settings_content']);
            add_action('admin_init', [$this, 'yoohw_settings_init']);
        }

        public function yoohw_studio_settings_content() {
            ?>
            <div class="wrap">
                <?php settings_errors(); ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('yoohw_settings_options');
                    do_settings_sections('yoohw_settings');
                    submit_button();
                    ?>
                </form>
            </div>
            <?php
        }

        public function yoohw_settings_init() {
            $license_status = get_option('wc_blacklist_manager_premium_license_status');

            include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
            $main_active = is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' )
                && get_option( 'wc_blacklist_manager_premium_license_status' ) === 'activated';
            $forms_active = is_plugin_active( 'blacklist-manager-premium-for-forms/blacklist-manager-premium-for-forms.php' )
                && get_option( 'blacklist_manager_premium_for_forms_license_status' ) === 'activated';

            // Register options
			register_setting(
				'yoohw_settings_options',
				'yoohw_settings_logger',
				[ $this, 'validate_checkbox' ]
			);

			// Sections
			add_settings_section(
				'yoohw_settings_section_logs',
				'Logs settings',
				function () {
					echo '<p>Configure logging for YoOhw plugins.</p>';
				},
				'yoohw_settings'
			);

			// Fields
			add_settings_field(
				'yoohw_settings_logger_field',
				'Logger',
				[ $this, 'logger_field_callback' ],
				'yoohw_settings',
				'yoohw_settings_section_logs'
			);

            if ( $license_status === 'activated' && ($main_active || $forms_active) ) {
				register_setting(
					'yoohw_settings_options',
					'yoohw_settings_disable_menu',
					[ $this, 'validate_checkbox' ]
				);

				add_settings_section(
					'yoohw_settings_section_whitelabel',
					'White label',
					[ $this, 'section_callback' ],
					'yoohw_settings'
				);

				add_settings_field(
					'yoohw_settings_disable_menu_field',
					'Disable brand',
					[ $this, 'disable_menu_field_callback' ],
					'yoohw_settings',
					'yoohw_settings_section_whitelabel'
				);
			}
        }

        public function logger_field_callback() {
			$setting = get_option('yoohw_settings_logger');

			// Always use uploads/yoohw-debug/
			$uploads = wp_upload_dir();
			$log_dir_path = trailingslashit($uploads['basedir']) . 'yoohw-debug/';

			// Ensure the directory exists (and protect it a bit)
			if ( ! is_dir( $log_dir_path ) ) {
				wp_mkdir_p( $log_dir_path );
				// Drop a simple index.html to discourage browsing
				$index_file = trailingslashit( $log_dir_path ) . 'index.html';
				if ( ! file_exists( $index_file ) ) {
					@file_put_contents( $index_file, "<!-- Silence is golden. -->" );
				}
				// Optional: .htaccess to deny listing on Apache
				$htaccess_file = trailingslashit( $log_dir_path ) . '.htaccess';
				if ( ! file_exists( $htaccess_file ) ) {
					@file_put_contents( $htaccess_file, "Options -Indexes\n" );
				}
			}
			?>
			<input type="checkbox" id="yoohw_settings_logger" name="yoohw_settings_logger" value="1" <?php checked('1', $setting); ?> />
			<label for="yoohw_settings_logger">Enable logging</label>
			<p class="description" style="margin-top:6px;">
				<?php
				printf(
					'Log files are stored in this directory: %s',
					'<code>' . esc_html( $log_dir_path ) . '</code>'
				);
				?>
			</p>
			<?php
		}

        public function section_callback() {
            echo '<p>' . esc_html__('If you disable the menu then the Licenses tab will be moved to Wordpress Settings.', 'wc-blacklist-manager') . '</p>';
        }

        public function disable_menu_field_callback() {
            $setting = get_option('yoohw_settings_disable_menu');
            ?>
            <input type="checkbox" name="yoohw_settings_disable_menu" value="1" <?php checked(1, $setting, true); ?>>
            <span><?php esc_html_e('Hide the YoOhw Studio information (White label)', 'wc-blacklist-manager'); ?></span>
            <p class="description"><?php esc_html_e('Enabling this option will hide everything about us. Ex: Menu, documents, support buttons, etc.', 'wc-blacklist-manager'); ?></p>
            <?php
        }

        public function validate_checkbox($input) {
            return isset($input) ? '1' : '0';
        }
    }

    new YoOhw_Settings_Content();
}

?>