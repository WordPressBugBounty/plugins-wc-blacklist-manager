<?php
/**
 * Plugin Name: Blacklist Manager
 * Plugin URI: https://wordpress.org/plugins/wc-blacklist-manager
 * Description: Easily helps site owners to avoid unwanted customers and users.
 * Version: 2.0.2
 * Author: YoOhw.com
 * Author URI: https://yoohw.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 5.2
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

		add_action('init', [$this, 'load_textdomain']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);

		$this->include_files();
	}

	public function load_textdomain() {
		load_plugin_textdomain('wc-blacklist-manager', false, basename(dirname(__FILE__)) . '/languages/');
	}

	public function enqueue_assets($hook_suffix) {
		$style_ver = '1.2.0';
		$script_ver = '1.0.4';

		wp_enqueue_style(
			'wc-blacklist-style', 
			plugin_dir_url(__FILE__) . 'css/yobm-wc-blacklist-manager-style.css', 
			[], 
			$style_ver
		);

		$plugin_pages = [
			'toplevel_page_wc-blacklist-manager',
			'wc-blacklist-manager_page_wc-blacklist-manager-notifications',
			'wc-blacklist-manager_page_wc-blacklist-manager-settings'
		];

		if (in_array($hook_suffix, $plugin_pages)) {
			wp_enqueue_script(
				'wc-blacklist-script', 
				plugin_dir_url(__FILE__) . 'js/yobm-wc-blacklist-manager-dashboard.js', 
				['jquery'], 
				$script_ver, 
				true
			);

			$inline_script = 'var messageTimeout = 3000;';
			wp_add_inline_script('wc-blacklist-script', $inline_script);
		}
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
