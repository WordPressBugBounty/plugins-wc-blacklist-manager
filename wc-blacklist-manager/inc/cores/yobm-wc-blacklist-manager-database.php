<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_DB {

	private $blacklist_table_name;
	private $whitelist_table_name;
	private $version;

	public function __construct() {
		global $wpdb;
		$this->blacklist_table_name = $wpdb->prefix . 'wc_blacklist';
		$this->whitelist_table_name = $wpdb->prefix . 'wc_whitelist';
		$this->version = WC_BLACKLIST_MANAGER_VERSION;

		register_activation_hook(WC_BLACKLIST_MANAGER_PLUGIN_FILE, [$this, 'activate']);

		add_action('admin_init', [$this, 'check_version']);
	}

	public function update_db() {
		global $wpdb;
		$installed_ver = get_option('wc_blacklist_manager_version', '1.0.0');

		if (version_compare($installed_ver, $this->version, '<')) {
			$charset_collate = $wpdb->get_charset_collate();

			// Create Blacklist Table
			$blacklist_sql = "CREATE TABLE $this->blacklist_table_name (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				first_name varchar(255) DEFAULT '' NOT NULL,
				last_name varchar(255) DEFAULT '' NOT NULL,
				phone_number varchar(255) DEFAULT '' NOT NULL,
				email_address varchar(255) DEFAULT '' NOT NULL,
				ip_address varchar(255) DEFAULT '' NOT NULL,
				domain varchar(255) DEFAULT '' NOT NULL,
				customer_address text DEFAULT '' NOT NULL,
				date_added datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				sources text DEFAULT '' NOT NULL,
				is_blocked boolean NOT NULL DEFAULT FALSE,
				PRIMARY KEY  (id)
			) $charset_collate;";

			// Create Whitelist Table (brand new)
			$whitelist_sql = "CREATE TABLE $this->whitelist_table_name (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				first_name varchar(255) DEFAULT '' NOT NULL,
				last_name varchar(255) DEFAULT '' NOT NULL,
				address_1 varchar(255) DEFAULT '' NOT NULL,
				address_2 varchar(255) DEFAULT '' NOT NULL,
				city varchar(255) DEFAULT '' NOT NULL,
				state varchar(255) DEFAULT '' NOT NULL,
				postcode varchar(255) DEFAULT '' NOT NULL,
				country varchar(255) DEFAULT '' NOT NULL,
				email varchar(255) DEFAULT '' NOT NULL,
				verified_email boolean NOT NULL DEFAULT FALSE,
				phone varchar(255) DEFAULT '' NOT NULL,
				verified_phone boolean NOT NULL DEFAULT FALSE,
				date_added datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				PRIMARY KEY  (id)
			) $charset_collate;";

			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($blacklist_sql);
			dbDelta($whitelist_sql);

			// Update the version in the database
			update_option('wc_blacklist_manager_version', $this->version);
		}
	}

	public function activate() {
		$this->update_db();
		$this->set_first_install_date();
	}

	public function check_version() {
		if (get_option('wc_blacklist_manager_version') != $this->version) {
			$this->update_db();
		}
	}

	private function set_first_install_date() {
		if (false === get_option('wc_blacklist_manager_first_install_date')) {
			$utc_time = gmdate('Y-m-d H:i:s');
			add_option('wc_blacklist_manager_first_install_date', $utc_time);
		}
	}
}

new WC_Blacklist_Manager_DB();
