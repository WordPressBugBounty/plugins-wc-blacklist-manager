<?php
/**
 * Plugin Name: Blacklist Manager
 * Plugin URI: https://wordpress.org/plugins/wc-blacklist-manager
 * Description: An anti-fraud and spam prevention plugin for WooCommerce and WordPress forms.
 * Version: 2.3.0
 * Author: YoOhw.com
 * Author URI: https://yoohw.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.3
 * Requires PHP: 7.4
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
		define('WC_BLACKLIST_MANAGER_SCHEMA_CONTRACT_VERSION', 1);
		define('WC_BLACKLIST_MANAGER_PHONE_CHANNEL_CONTRACT_VERSION', 1);
		define('WC_BLACKLIST_MANAGER_CHECKOUT_VERIFICATION_CONTRACT_VERSION', 1);
		define('WC_BLACKLIST_MANAGER_OTP_STATE_CONTRACT_VERSION', 1);
		define('WC_BLACKLIST_MANAGER_EVIDENCE_TRUST_CONTRACT_VERSION', 1);
		define('WC_BLACKLIST_MANAGER_CHECKOUT_VALIDATION_CONTEXT_CONTRACT_VERSION', 1);
		define('WC_BLACKLIST_MANAGER_PLUGIN_FILE', __FILE__);
		define('WC_BLACKLIST_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
		define('WC_BLACKLIST_MANAGER_PLUGIN_BASENAME', plugin_basename(__FILE__));

		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);

		$this->include_files();
	}

	private function include_files() {
		include_once plugin_dir_path(__FILE__) . 'inc/cores/api/yogb/yogb-secret-store.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/helper/yoohw-diagnostic-log.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/premium-gate.php';
		include_once plugin_dir_path(__FILE__) . 'inc/backend/helpers/commercial-router.php';
		include_once plugin_dir_path(__FILE__) . 'inc/backend/helpers/opportunity-engine.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/otp-state.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/evidence-trust.php';
		include_once plugin_dir_path(__FILE__) . 'inc/backend/checkout-validation-context.php';
		include_once plugin_dir_path(__FILE__) . 'inc/backend/checkout-verification.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/schema-readiness.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/phone-verification-boundary.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/database.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/notices.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/backend.php';
	}

	public function add_action_links($links) {
		$settings_link = '<a href="admin.php?page=wc-blacklist-manager-settings">' . esc_html__( 'Settings', 'wc-blacklist-manager' ) . '</a>';
		$action_links = array( $settings_link );
		$premium_active = function_exists( 'is_plugin_active' )
			&& is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' );

		if ( ! $premium_active && class_exists( 'WC_Blacklist_Manager_Commercial_Router' ) ) {
			$action_links[] = '<a href="' . esc_url( WC_Blacklist_Manager_Commercial_Router::premium_product_url() ) . '" target="_blank" rel="noopener noreferrer" style="font-weight: 600;">' . esc_html__( 'Upgrade Premium ↗', 'wc-blacklist-manager' ) . '</a>';
		}

		array_unshift( $links, ...$action_links );
		return $links;
	}
}

new WC_Blacklist_Manager();
