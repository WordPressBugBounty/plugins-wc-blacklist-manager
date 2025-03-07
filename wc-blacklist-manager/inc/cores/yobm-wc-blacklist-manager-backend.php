<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Backend {
	public function __construct() {
		$this->includes();
	}

	private function includes() {
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-button-add-to-suspects.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-button-add-to-blocklist.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-dashboard.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-verifications.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-notifications.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-settings.php';
		include_once plugin_dir_path(__FILE__) . '/api/sms/yobm-wc-blacklist-manager-update-sms-quota.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yoohw-menu-dashboard.php';
	}
}

new WC_Blacklist_Manager_Backend();
