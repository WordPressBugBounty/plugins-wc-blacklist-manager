<?php
/**
 * Plugin Name: Blacklist Manager
 * Plugin URI: https://wordpress.org/plugins/wc-blacklist-manager
 * Description: An anti-fraud and spam prevention plugin for WooCommerce and WordPress forms.
 * Version: 2.0.8
 * Author: YoOhw.com
 * Author URI: https://yoohw.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 6.3
 * Requires PHP: 7.0
 * Text Domain: wc-blacklist-manager
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager {

	public function __construct() {
		$yobm_plugin_data = get_file_data(__FILE__, ['Version' => 'Version'], false);
		$yobm_plugin_version = isset($yobm_plugin_data['Version']) ? $yobm_plugin_data['Version'] : '';

		define('WC_BLACKLIST_MANAGER_VERSION', $yobm_plugin_version);
		define('WC_BLACKLIST_MANAGER_PLUGIN_FILE', __FILE__);
		define('WC_BLACKLIST_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
		define('WC_BLACKLIST_MANAGER_PLUGIN_BASENAME', plugin_basename(__FILE__));

		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);

		$this->include_files();
	}

	private function include_files() {
		include_once plugin_dir_path(__FILE__) . 'inc/cores/yobm-wc-blacklist-manager-database.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/yobm-wc-blacklist-manager-notices.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/yobm-wc-blacklist-manager-backend.php';
	}

	public function add_action_links($links) {
		$settings_link = '<a href="admin.php?page=wc-blacklist-manager-settings">Settings</a>';
		array_unshift($links, $settings_link);
		return $links;
	}
}

new WC_Blacklist_Manager();
