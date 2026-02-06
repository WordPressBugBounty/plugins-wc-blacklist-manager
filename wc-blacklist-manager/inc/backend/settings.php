<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Settings {
	public function __construct() {
		if (!$this->is_premium_active()) {
			add_action('admin_menu', [$this, 'add_settings_page']);
		}
		$this->includes();
	}

	public function add_settings_page() {
		$premium_active = $this->is_premium_active();

		if (!$premium_active && current_user_can('manage_options')) {
			add_submenu_page(
				'wc-blacklist-manager',
				__('Settings', 'wc-blacklist-manager'),
				__('Settings', 'wc-blacklist-manager'),
				'manage_options',
				'wc-blacklist-manager-settings',
				[$this, 'render_settings_page']
			);
		}
	}

	public function render_settings_page() {
		$this->handle_post_submission();
		$settings = $this->get_settings();
		$premium_active = $this->is_premium_active();
		$woocommerce_active = class_exists( 'WooCommerce' );
		$form_active = (class_exists( 'WPCF7' ) || class_exists( 'GFCommon' ) || class_exists( 'WPForms\WPForms' ));

		$unlock_url = $woocommerce_active
			? 'https://yoohw.com/product/blacklist-manager-premium/'
			: 'https://yoohw.com/product/blacklist-manager-premium-for-forms/';
		
		// Include the view file for settings form
		$template_path = plugin_dir_path(__FILE__) . 'views/settings-form.php';
		if (file_exists($template_path)) {
			include $template_path;
		} else {
			echo '<div class="error"><p>Failed to load the settings template.</p></div>';
		}
	}

	private function get_settings() {
		// Retrieve roles and settings from the database
		$roles = $this->get_user_roles();

		return [
			'blacklist_action' => get_option('wc_blacklist_action', 'none'),
			'block_user_registration' => get_option('wc_blacklist_block_user_registration', 0),
			'order_delay' => max(0, get_option('wc_blacklist_order_delay', 0)),
			'comment_blocking_enabled' => get_option('wc_blacklist_comment_blocking_enabled', 0),
			'form_blocking_enabled' => get_option('wc_blacklist_form_blocking_enabled', 0),
			'ip_blacklist_enabled' => get_option('wc_blacklist_ip_enabled', '0'),
			'ip_blacklist_action' => get_option('wc_blacklist_ip_action', 'none'),
			'block_ip_registration' => get_option('wc_blacklist_block_ip_registration', 0),
			'domain_blocking_enabled' => get_option('wc_blacklist_domain_enabled', 0),
			'domain_blocking_action' => get_option('wc_blacklist_domain_action', 'none'),
			'domain_registration' => get_option('wc_blacklist_domain_registration', 0),
			'enable_user_blocking' => get_option('wc_blacklist_enable_user_blocking', 0),
			'enable_global_blacklist' => get_option('wc_blacklist_enable_global_blacklist', 0),
			'global_blacklist_decision_mode' => get_option('wc_blacklist_global_blacklist_decision_mode', 'light'),
			'roles' => $roles, // Add the roles to the settings array
			'selected_dashboard_roles' => get_option('wc_blacklist_dashboard_permission', []),
		];
	}

	private function get_user_roles() {
		// Retrieve all user roles from WordPress
		global $wp_roles;
		$roles = $wp_roles->roles;

		// Exclude admin, subscriber, and customer roles
		$excluded_roles = ['administrator', 'subscriber', 'customer'];
		$filtered_roles = array_filter($roles, function($role_key) use ($excluded_roles) {
			return !in_array($role_key, $excluded_roles);
		}, ARRAY_FILTER_USE_KEY);

		return $filtered_roles;
	}

	private function handle_post_submission() {
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('wc_blacklist_settings_action', 'wc_blacklist_settings_nonce')) {
			update_option('wc_blacklist_action', $_POST['blacklist_action'] ?? 'none');
			update_option('wc_blacklist_block_user_registration', isset($_POST['block_user_registration']) ? 1 : 0);
			$order_delay = intval( wp_unslash( $_POST['order_delay'] ?? 0 ) );
			update_option( 'wc_blacklist_order_delay', max( 0, $order_delay ) );
			update_option('wc_blacklist_comment_blocking_enabled', isset($_POST['comment_blocking_enabled']) ? 1 : 0);
			update_option('wc_blacklist_form_blocking_enabled', isset($_POST['form_blocking_enabled']) ? 1 : 0);
			update_option('wc_blacklist_ip_enabled', sanitize_text_field($_POST['ip_blacklist_enabled'] ?? '0'));
			update_option('wc_blacklist_ip_action', sanitize_text_field($_POST['ip_blacklist_action'] ?? 'none'));
			update_option('wc_blacklist_block_ip_registration', isset($_POST['block_ip_registration']) ? 1 : 0);
			update_option('wc_blacklist_domain_enabled', isset($_POST['domain_blocking_enabled']) ? 1 : 0);
			update_option('wc_blacklist_domain_action', sanitize_text_field($_POST['domain_blocking_action'] ?? 'none'));
			update_option('wc_blacklist_domain_registration', isset($_POST['domain_registration']) ? 1 : 0);
			update_option('wc_blacklist_enable_user_blocking', isset($_POST['enable_user_blocking']) ? 1 : 0);
			update_option('wc_blacklist_enable_global_blacklist', isset($_POST['enable_global_blacklist']) ? 1 : 0);
			update_option('wc_blacklist_global_blacklist_decision_mode', sanitize_text_field($_POST['global_blacklist_decision_mode'] ?? 'light'));

			echo '<div class="updated notice is-dismissible"><p>' . esc_html__('Settings saved.', 'wc-blacklist-manager') . '</p></div>';
		}
	}

	private function includes() {
		include_once plugin_dir_path(__FILE__) . '/actions/settings-suspects.php';
		include_once plugin_dir_path(__FILE__) . '/actions/settings-blocklist.php';
		include_once plugin_dir_path(__FILE__) . '/actions/settings-blocking-ip.php';
		include_once plugin_dir_path(__FILE__) . '/actions/settings-blocking-domain.php';
		include_once plugin_dir_path(__FILE__) . '/actions/settings-blocking-user.php';
		include_once plugin_dir_path(__FILE__) . '/actions/form/contact-form-7.php';
		include_once plugin_dir_path(__FILE__) . '/actions/form/gravity-forms.php';
		include_once plugin_dir_path(__FILE__) . '/actions/form/wp-forms.php';
		include_once plugin_dir_path(__FILE__) . '/actions/sub/send-email.php';
		include_once plugin_dir_path(__FILE__) . '/actions/yogb-check-orders.php';
	}

	public function is_premium_active() {
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		$main_active = is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' )
			&& get_option( 'wc_blacklist_manager_premium_license_status' ) === 'activated';

		$forms_active = is_plugin_active( 'blacklist-manager-premium-for-forms/blacklist-manager-premium-for-forms.php' )
			&& get_option( 'blacklist_manager_premium_for_forms_license_status' ) === 'activated';

		return $main_active || $forms_active;
	}
}

new WC_Blacklist_Manager_Settings();
