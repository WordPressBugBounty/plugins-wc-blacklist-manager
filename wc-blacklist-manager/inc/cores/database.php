<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_DB {
	private $blacklist_table_name;
	private $blacklist_addresses_table_name;
	private $whitelist_table_name;
	private $detection_log_table_name;
	private $version;

	public function __construct() {
		global $wpdb;
		$this->blacklist_table_name           = $wpdb->prefix . 'wc_blacklist';
		$this->blacklist_addresses_table_name = $wpdb->prefix . 'wc_blacklist_addresses';
		$this->whitelist_table_name           = $wpdb->prefix . 'wc_whitelist';
		$this->detection_log_table_name       = $wpdb->prefix . 'wc_blacklist_detection_log';
		$this->version                        = WC_BLACKLIST_MANAGER_VERSION;

		register_activation_hook( WC_BLACKLIST_MANAGER_PLUGIN_FILE, [ $this, 'activate' ] );
		add_action( 'admin_init', [ $this, 'check_version' ] );
	}

	public function activate() {
		$this->update_db();
		$this->set_first_install_date();
		$this->maybe_set_default_development_mode();
		$this->install_default_options();
		$this->install_count_options();
		$this->create_trigger();
		$this->create_delete_trigger();

		update_option( 'wc_blacklist_manager_version', $this->version );

		WC_Blacklist_Manager_Push_Subscription::maybe_push_subscription();
	}

	private function maybe_set_default_development_mode() {
		if ( false !== get_option( 'wc_blacklist_development_mode', false ) ) {
			return;
		}

		$first_install_date = get_option( 'wc_blacklist_manager_first_install_date', false );

		if ( false === $first_install_date ) {
			return;
		}

		$installed_timestamp = strtotime( $first_install_date );

		if ( false === $installed_timestamp ) {
			return;
		}

		$is_recent_install = ( time() - $installed_timestamp ) <= ( 7 * DAY_IN_SECONDS );

		if ( $is_recent_install ) {
			add_option( 'wc_blacklist_development_mode', '1' );
		}
	}

	public function check_version() {
		if ( get_option( 'wc_blacklist_manager_version' ) != $this->version ) {
			$this->update_db();
			$this->install_default_options();
			$this->install_count_options();
			$this->create_trigger();
			$this->create_delete_trigger();

			update_option( 'wc_blacklist_manager_version', $this->version );

			WC_Blacklist_Manager_Push_Subscription::maybe_push_subscription();
		}
	}

	public function update_db() {
		global $wpdb;

		$installed_ver = get_option( 'wc_blacklist_manager_version', '1.0.0' );

		if ( version_compare( $installed_ver, $this->version, '<' ) ) {
			$charset_collate = $wpdb->get_charset_collate();

			$blacklist_sql = "CREATE TABLE {$this->blacklist_table_name} (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				first_name varchar(255) DEFAULT '' NOT NULL,
				last_name varchar(255) DEFAULT '' NOT NULL,
				phone_number varchar(255) DEFAULT '' NOT NULL,
				normalized_phone varchar(32) DEFAULT '' NOT NULL,
				email_address varchar(255) DEFAULT '' NOT NULL,
				normalized_email varchar(320) DEFAULT '' NOT NULL,
				ip_address varchar(255) DEFAULT '' NOT NULL,
				domain varchar(255) DEFAULT '' NOT NULL,
				customer_address text NOT NULL,
				order_id int(11) DEFAULT NULL,
				reason_code varchar(100) DEFAULT '' NOT NULL,
				description text NOT NULL,
				date_added datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				sources text NOT NULL,
				is_blocked boolean NOT NULL DEFAULT FALSE,
				PRIMARY KEY  (id),
				KEY normalized_phone (normalized_phone),
				KEY normalized_email (normalized_email),
				KEY is_blocked (is_blocked)
			) {$charset_collate};";

			$blacklist_addresses_sql = "CREATE TABLE {$this->blacklist_addresses_table_name} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blacklist_id mediumint(9) unsigned DEFAULT NULL,
				match_type varchar(20) NOT NULL DEFAULT 'address',
				is_blocked tinyint(1) NOT NULL DEFAULT 1,
				country_code varchar(2) NOT NULL DEFAULT '',
				state_code varchar(100) NOT NULL DEFAULT '',
				city_norm varchar(191) NOT NULL DEFAULT '',
				postcode_norm varchar(32) NOT NULL DEFAULT '',
				address_line_norm varchar(191) NOT NULL DEFAULT '',
				address_core_norm varchar(191) NOT NULL DEFAULT '',
				address_full_norm varchar(191) NOT NULL DEFAULT '',
				address_core_hash char(32) NOT NULL DEFAULT '',
				address_line_postcode_hash char(32) NOT NULL DEFAULT '',
				address_hash char(32) NOT NULL DEFAULT '',
				address_display text NOT NULL,
				notes text NOT NULL,
				date_added datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				PRIMARY KEY  (id),
				KEY blacklist_id (blacklist_id),
				KEY is_blocked (is_blocked),
				KEY match_type (match_type),
				KEY country_code (country_code),
				KEY state_lookup (is_blocked, match_type, country_code, state_code),
				KEY postcode_lookup (is_blocked, match_type, country_code, postcode_norm),
				KEY address_hash_lookup (is_blocked, match_type, address_hash),
				KEY address_core_lookup (is_blocked, match_type, address_core_hash),
				KEY line_postcode_lookup (is_blocked, match_type, country_code, postcode_norm, address_line_postcode_hash),
				KEY address_full_lookup (is_blocked, match_type, address_full_norm)
			) {$charset_collate};";

			$whitelist_sql = "CREATE TABLE {$this->whitelist_table_name} (
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
			) {$charset_collate};";

			$detection_log_sql = "CREATE TABLE {$this->detection_log_table_name} (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				`timestamp` datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				type varchar(255) NOT NULL,
				source varchar(255) NOT NULL,
				action varchar(255) NOT NULL,
				details text NOT NULL,
				view text NOT NULL,
				PRIMARY KEY (id)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			dbDelta( $blacklist_sql );
			dbDelta( $blacklist_addresses_sql );
			dbDelta( $whitelist_sql );
			dbDelta( $detection_log_sql );

			$this->backfill_normalized_emails( $this->blacklist_table_name, 500 );
			$this->maybe_upgrade_blacklist_normalized_phone();
			$this->maybe_migrate_legacy_customer_addresses();

			update_option( 'wc_blacklist_manager_version', $this->version );
		}
	}

	public function install_default_options() {
		$default_email_from_name = get_bloginfo( 'name' );
		if ( false === get_option( 'wc_blacklist_sender_name' ) ) {
			add_option( 'wc_blacklist_sender_name', $default_email_from_name );
		}

		$default_email_from_address = get_option( 'admin_email' );
		if ( false === get_option( 'wc_blacklist_sender_address' ) ) {
			add_option( 'wc_blacklist_sender_address', $default_email_from_address );
		}

		$default_email_recipient = get_option( 'admin_email' );
		if ( false === get_option( 'wc_blacklist_email_recipient' ) ) {
			add_option( 'wc_blacklist_email_recipient', $default_email_from_address );
		}

		$default_email_footer_text = 'This is an automated message. Please do not reply.<br>Blacklist Manager by <a href="https://yoohw.com">YoOhw Studio</a>';
		if ( false === get_option( 'wc_blacklist_email_footer_text' ) ) {
			add_option( 'wc_blacklist_email_footer_text', $default_email_footer_text );
		}

		$current_footer_text = get_option( 'wc_blacklist_email_footer_text' );
		if ( '0' === $current_footer_text ) {
			update_option( 'wc_blacklist_email_footer_text', $default_email_footer_text );
		}

		if ( false === get_option( 'wc_blacklist_sum_block_name' ) ) {
			add_option( 'wc_blacklist_sum_block_name', '0' );
		}

		if ( false === get_option( 'wc_blacklist_sum_block_phone' ) ) {
			add_option( 'wc_blacklist_sum_block_phone', '0' );
		}

		if ( false === get_option( 'wc_blacklist_sum_block_email' ) ) {
			add_option( 'wc_blacklist_sum_block_email', '0' );
		}

		if ( false === get_option( 'wc_blacklist_sum_block_ip' ) ) {
			add_option( 'wc_blacklist_sum_block_ip', '0' );
		}

		if ( false === get_option( 'wc_blacklist_sum_block_address' ) ) {
			add_option( 'wc_blacklist_sum_block_address', '0' );
		}

		if ( false === get_option( 'wc_blacklist_sum_block_domain' ) ) {
			add_option( 'wc_blacklist_sum_block_domain', '0' );
		}

		if ( false === get_option( 'wc_blacklist_sum_block_total' ) ) {
			add_option( 'wc_blacklist_sum_block_total', '0' );
		}

		if ( false === get_option( 'wc_blacklist_email_notification' ) ) {
			add_option( 'wc_blacklist_email_notification', 'yes' );
		}

		if ( false === get_option( 'wc_blacklist_email_register_suspect' ) ) {
			add_option( 'wc_blacklist_email_register_suspect', 'yes' );
		}

		if ( false === get_option( 'wc_blacklist_email_form_suspect' ) ) {
			add_option( 'wc_blacklist_email_form_suspect', 'yes' );
		}

		if ( false === get_option( 'wc_blacklist_enable_global_blacklist' ) ) {
			add_option( 'wc_blacklist_enable_global_blacklist', '1' );
		}
	}

	public function install_count_options() {
		global $wpdb;

		if ( false === get_option( 'wc_blacklist_sum_name' ) ) {
			$name_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->blacklist_table_name} WHERE `first_name` != '' AND `first_name` IS NOT NULL" );
			add_option( 'wc_blacklist_sum_name', $name_count );
		} else {
			$name_count = (int) get_option( 'wc_blacklist_sum_name' );
		}

		if ( false === get_option( 'wc_blacklist_sum_phone' ) ) {
			$phone_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->blacklist_table_name} WHERE `phone_number` != '' AND `first_name` IS NOT NULL" );
			add_option( 'wc_blacklist_sum_phone', $phone_count );
		} else {
			$phone_count = (int) get_option( 'wc_blacklist_sum_phone' );
		}

		if ( false === get_option( 'wc_blacklist_sum_email' ) ) {
			$email_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->blacklist_table_name} WHERE `email_address` != '' AND `first_name` IS NOT NULL" );
			add_option( 'wc_blacklist_sum_email', $email_count );
		} else {
			$email_count = (int) get_option( 'wc_blacklist_sum_email' );
		}

		if ( false === get_option( 'wc_blacklist_sum_ip' ) ) {
			$ip_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->blacklist_table_name} WHERE `ip_address` != '' AND `first_name` IS NOT NULL" );
			add_option( 'wc_blacklist_sum_ip', $ip_count );
		} else {
			$ip_count = (int) get_option( 'wc_blacklist_sum_ip' );
		}

		if ( false === get_option( 'wc_blacklist_sum_address' ) ) {
			$legacy_address_count = (int) $wpdb->get_var(
				"SELECT COUNT(*)
				FROM {$this->blacklist_table_name}
				WHERE customer_address IS NOT NULL
				AND TRIM(customer_address) <> ''"
			);

			$new_address_count = 0;

			if ( $this->table_exists( $this->blacklist_addresses_table_name ) ) {
				$new_address_count = (int) $wpdb->get_var(
					"SELECT COUNT(*)
					FROM {$this->blacklist_addresses_table_name}
					WHERE is_blocked = 1"
				);
			}

			$address_count = max( $legacy_address_count, $new_address_count );

			add_option( 'wc_blacklist_sum_address', $address_count );
		} else {
			$address_count = (int) get_option( 'wc_blacklist_sum_address' );
		}

		if ( false === get_option( 'wc_blacklist_sum_domain' ) ) {
			$domain_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->blacklist_table_name} WHERE `domain` != '' AND `first_name` IS NOT NULL" );
			add_option( 'wc_blacklist_sum_domain', $domain_count );
		} else {
			$domain_count = (int) get_option( 'wc_blacklist_sum_domain' );
		}

		if ( false === get_option( 'wc_blacklist_sum_total' ) ) {
			$total = $name_count + $phone_count + $email_count + $ip_count + $address_count + $domain_count;
			add_option( 'wc_blacklist_sum_total', $total );
		}
	}

	/**
	 * Create an AFTER INSERT trigger on the blacklist table.
	 * This trigger updates the option values for each field, and then updates the total counter by summing the valid fields.
	 */
	public function create_trigger() {
		global $wpdb;

		$trigger_name = 'after_insert_blacklist_row';

		$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger_name}" );

		$trigger_sql = "
			CREATE TRIGGER {$trigger_name}
			AFTER INSERT ON {$this->blacklist_table_name}
			FOR EACH ROW
			BEGIN
				IF NEW.first_name IS NOT NULL AND TRIM(NEW.first_name) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = CAST(option_value AS UNSIGNED) + 1
					WHERE option_name = 'wc_blacklist_sum_name';
				END IF;

				IF NEW.phone_number IS NOT NULL AND TRIM(NEW.phone_number) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = CAST(option_value AS UNSIGNED) + 1
					WHERE option_name = 'wc_blacklist_sum_phone';
				END IF;

				IF NEW.email_address IS NOT NULL AND TRIM(NEW.email_address) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = CAST(option_value AS UNSIGNED) + 1
					WHERE option_name = 'wc_blacklist_sum_email';
				END IF;

				IF NEW.ip_address IS NOT NULL AND TRIM(NEW.ip_address) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = CAST(option_value AS UNSIGNED) + 1
					WHERE option_name = 'wc_blacklist_sum_ip';
				END IF;

				IF NEW.customer_address IS NOT NULL AND TRIM(NEW.customer_address) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = CAST(option_value AS UNSIGNED) + 1
					WHERE option_name = 'wc_blacklist_sum_address';
				END IF;

				IF NEW.domain IS NOT NULL AND TRIM(NEW.domain) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = CAST(option_value AS UNSIGNED) + 1
					WHERE option_name = 'wc_blacklist_sum_domain';
				END IF;

				UPDATE {$wpdb->options}
				SET option_value = CAST(option_value AS UNSIGNED) + (
					(CASE WHEN NEW.first_name IS NOT NULL AND TRIM(NEW.first_name) <> '' THEN 1 ELSE 0 END) +
					(CASE WHEN NEW.phone_number IS NOT NULL AND TRIM(NEW.phone_number) <> '' THEN 1 ELSE 0 END) +
					(CASE WHEN NEW.email_address IS NOT NULL AND TRIM(NEW.email_address) <> '' THEN 1 ELSE 0 END) +
					(CASE WHEN NEW.ip_address IS NOT NULL AND TRIM(NEW.ip_address) <> '' THEN 1 ELSE 0 END) +
					(CASE WHEN NEW.customer_address IS NOT NULL AND TRIM(NEW.customer_address) <> '' THEN 1 ELSE 0 END) +
					(CASE WHEN NEW.domain IS NOT NULL AND TRIM(NEW.domain) <> '' THEN 1 ELSE 0 END)
				)
				WHERE option_name = 'wc_blacklist_sum_total';
			END;
		";

		$wpdb->query( $trigger_sql );
	}

	/**
	 * Create an AFTER DELETE trigger on the blacklist table.
	 * This trigger decrements the appropriate counters for each field
	 * and subtracts the sum from the total counter.
	 */
	public function create_delete_trigger() {
		global $wpdb;

		$trigger_name = 'after_delete_blacklist_row';

		$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger_name}" );

		$trigger_sql = "
			CREATE TRIGGER {$trigger_name}
			AFTER DELETE ON {$this->blacklist_table_name}
			FOR EACH ROW
			BEGIN
				IF OLD.first_name IS NOT NULL AND TRIM(OLD.first_name) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = IF(CAST(option_value AS UNSIGNED) > 0, CAST(option_value AS UNSIGNED) - 1, 0)
					WHERE option_name = 'wc_blacklist_sum_name';
				END IF;

				IF OLD.phone_number IS NOT NULL AND TRIM(OLD.phone_number) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = IF(CAST(option_value AS UNSIGNED) > 0, CAST(option_value AS UNSIGNED) - 1, 0)
					WHERE option_name = 'wc_blacklist_sum_phone';
				END IF;

				IF OLD.email_address IS NOT NULL AND TRIM(OLD.email_address) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = IF(CAST(option_value AS UNSIGNED) > 0, CAST(option_value AS UNSIGNED) - 1, 0)
					WHERE option_name = 'wc_blacklist_sum_email';
				END IF;

				IF OLD.ip_address IS NOT NULL AND TRIM(OLD.ip_address) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = IF(CAST(option_value AS UNSIGNED) > 0, CAST(option_value AS UNSIGNED) - 1, 0)
					WHERE option_name = 'wc_blacklist_sum_ip';
				END IF;

				IF OLD.customer_address IS NOT NULL AND TRIM(OLD.customer_address) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = IF(CAST(option_value AS UNSIGNED) > 0, CAST(option_value AS UNSIGNED) - 1, 0)
					WHERE option_name = 'wc_blacklist_sum_address';
				END IF;

				IF OLD.domain IS NOT NULL AND TRIM(OLD.domain) <> '' THEN
					UPDATE {$wpdb->options}
					SET option_value = IF(CAST(option_value AS UNSIGNED) > 0, CAST(option_value AS UNSIGNED) - 1, 0)
					WHERE option_name = 'wc_blacklist_sum_domain';
				END IF;

				UPDATE {$wpdb->options}
				SET option_value = IF(
					CAST(option_value AS UNSIGNED) >= (
						(CASE WHEN OLD.first_name IS NOT NULL AND TRIM(OLD.first_name) <> '' THEN 1 ELSE 0 END) +
						(CASE WHEN OLD.phone_number IS NOT NULL AND TRIM(OLD.phone_number) <> '' THEN 1 ELSE 0 END) +
						(CASE WHEN OLD.email_address IS NOT NULL AND TRIM(OLD.email_address) <> '' THEN 1 ELSE 0 END) +
						(CASE WHEN OLD.ip_address IS NOT NULL AND TRIM(OLD.ip_address) <> '' THEN 1 ELSE 0 END) +
						(CASE WHEN OLD.customer_address IS NOT NULL AND TRIM(OLD.customer_address) <> '' THEN 1 ELSE 0 END) +
						(CASE WHEN OLD.domain IS NOT NULL AND TRIM(OLD.domain) <> '' THEN 1 ELSE 0 END)
					),
					CAST(option_value AS UNSIGNED) - (
						(CASE WHEN OLD.first_name IS NOT NULL AND TRIM(OLD.first_name) <> '' THEN 1 ELSE 0 END) +
						(CASE WHEN OLD.phone_number IS NOT NULL AND TRIM(OLD.phone_number) <> '' THEN 1 ELSE 0 END) +
						(CASE WHEN OLD.email_address IS NOT NULL AND TRIM(OLD.email_address) <> '' THEN 1 ELSE 0 END) +
						(CASE WHEN OLD.ip_address IS NOT NULL AND TRIM(OLD.ip_address) <> '' THEN 1 ELSE 0 END) +
						(CASE WHEN OLD.customer_address IS NOT NULL AND TRIM(OLD.customer_address) <> '' THEN 1 ELSE 0 END) +
						(CASE WHEN OLD.domain IS NOT NULL AND TRIM(OLD.domain) <> '' THEN 1 ELSE 0 END)
					),
					0
				)
				WHERE option_name = 'wc_blacklist_sum_total';
			END;
		";

		$wpdb->query( $trigger_sql );
	}

	private function set_first_install_date() {
		if ( false === get_option( 'wc_blacklist_manager_first_install_date' ) ) {
			$utc_time = gmdate( 'Y-m-d H:i:s' );
			add_option( 'wc_blacklist_manager_first_install_date', $utc_time );
		}
	}

	/**
	 * Backfill normalized_email for legacy rows, but:
	 * - Skip if within grace period from first install date
	 * - Run only ONCE per site (wc_blacklist_backfill_done flag)
	 * - Prevent concurrent runs via a transient lock
	 */
	private function backfill_normalized_emails( $table_name = '', $limit = 500 ) {
		$premium_active = $this->is_premium_active();
		if ( ! $premium_active ) {
			return;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$premium_file = 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php';

		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $premium_file ) ) {
			return 0;
		}

		$all_plugins     = get_plugins();
		$current_version = isset( $all_plugins[ $premium_file ]['Version'] ) ? $all_plugins[ $premium_file ]['Version'] : '';

		if ( empty( $current_version ) || version_compare( $current_version, '2.1.7', '<' ) ) {
			return 0;
		}

		global $wpdb;

		if ( get_option( 'wc_blacklist_backfill_done' ) ) {
			return 0;
		}

		$first_install_raw = get_option( 'wc_blacklist_manager_first_install_date', '' );
		if ( ! empty( $first_install_raw ) ) {
			$ts = strtotime( $first_install_raw );
			if ( false !== $ts ) {
				$grace = (int) apply_filters( 'wc_blacklist_backfill_grace_period', DAY_IN_SECONDS );
				if ( $grace > 0 && ( time() - $ts ) < $grace ) {
					return 0;
				}
			}
		}

		if ( empty( $table_name ) ) {
			if ( ! empty( $this->blacklist_table_name ) ) {
				$table_name = $this->blacklist_table_name;
			} else {
				$table_name = $wpdb->prefix . 'wc_blacklist';
			}
		}

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $exists !== $table_name ) {
			error_log( "[WC Blacklist] backfill_normalized_emails: table not found: {$table_name}" );
			return 0;
		}

		$lock_key = 'wc_blacklist_backfill_lock';
		if ( get_transient( $lock_key ) ) {
			return 0;
		}
		set_transient( $lock_key, 1, 10 * MINUTE_IN_SECONDS );

		$updated = 0;

		try {
			$limit = max( 1, absint( $limit ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, email_address
					FROM `{$table_name}`
					WHERE (normalized_email IS NULL OR normalized_email = '')
					AND email_address <> ''
					LIMIT %d",
					$limit
				)
			);

			if ( empty( $rows ) ) {
				update_option( 'wc_blacklist_backfill_done', current_time( 'mysql' ), false );
				return 0;
			}

			foreach ( $rows as $r ) {
				$norm = yobmp_normalize_email( $r->email_address );
				$did  = $wpdb->update(
					$table_name,
					[ 'normalized_email' => $norm ],
					[ 'id' => (int) $r->id ],
					[ '%s' ],
					[ '%d' ]
				);

				if ( false !== $did ) {
					$updated++;
				}
			}

			$remaining = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$table_name}`
				WHERE (normalized_email IS NULL OR normalized_email = '')
				AND email_address <> ''"
			);

			if ( 0 === $remaining ) {
				update_option( 'wc_blacklist_backfill_done', current_time( 'mysql' ), false );
			}
		} finally {
			delete_transient( $lock_key );
		}

		return $updated;
	}

	public function maybe_upgrade_blacklist_normalized_phone() {
		global $wpdb;

		$table_name = $this->blacklist_table_name;

		$migration_done = get_option( 'wc_blacklist_manager_normalized_phone_migrated', 'no' );

		if ( 'yes' === $migration_done ) {
			return;
		}

		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table_name} LIKE %s",
				'normalized_phone'
			)
		);

		if ( null === $column_exists ) {
			$wpdb->query(
				"ALTER TABLE {$table_name}
				ADD COLUMN normalized_phone varchar(32) NOT NULL DEFAULT ''
				AFTER phone_number"
			);
		}

		$index_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$table_name} WHERE Key_name = %s",
				'normalized_phone'
			)
		);

		if ( null === $index_exists ) {
			$wpdb->query(
				"ALTER TABLE {$table_name}
				ADD KEY normalized_phone (normalized_phone)"
			);
		}

		$limit              = 200;
		$last_id            = 0;
		$country_dial_cache = array();

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, order_id, phone_number
					FROM {$table_name}
					WHERE id > %d
					AND phone_number <> ''
					AND ( normalized_phone = '' OR normalized_phone IS NULL )
					ORDER BY id ASC
					LIMIT %d",
					$last_id,
					$limit
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id     = (int) $row->id;
				$order_id    = isset( $row->order_id ) ? absint( $row->order_id ) : 0;
				$raw_phone   = (string) $row->phone_number;
				$raw_phone   = sanitize_text_field( $raw_phone );
				$raw_trimmed = trim( $raw_phone );

				$normalized_phone = '';

				if ( 0 === strpos( $raw_trimmed, '+' ) || 0 === strpos( $raw_trimmed, '00' ) ) {
					$normalized_phone = yobm_normalize_phone( $raw_trimmed );
				} else {
					if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
						$order = wc_get_order( $order_id );

						if ( $order ) {
							$billing_country = strtoupper( (string) $order->get_billing_country() );

							if ( '' !== $billing_country ) {
								if ( ! isset( $country_dial_cache[ $billing_country ] ) ) {
									$country_dial_cache[ $billing_country ] = yobm_get_country_dial_code( $billing_country );
								}

								$dial_code = $country_dial_cache[ $billing_country ];

								if ( '' !== $dial_code ) {
									$normalized_phone = yobm_normalize_phone( $raw_trimmed, $dial_code );
								}
							}
						}
					}

					if ( '' === $normalized_phone ) {
						$normalized_phone = yobm_normalize_phone( $raw_trimmed );
					}
				}

				if ( ! is_string( $normalized_phone ) ) {
					$normalized_phone = '';
				}

				$wpdb->update(
					$table_name,
					array(
						'normalized_phone' => $normalized_phone,
					),
					array(
						'id' => $last_id,
					),
					array(
						'%s',
					),
					array(
						'%d',
					)
				);
			}

			if ( count( $rows ) < $limit ) {
				break;
			}
		}

		update_option( 'wc_blacklist_manager_normalized_phone_migrated', 'yes' );
	}

	private function maybe_migrate_legacy_customer_addresses() {
		global $wpdb;

		if ( ! $this->table_exists( $this->blacklist_addresses_table_name ) ) {
			return;
		}

		$done = get_option( 'wc_blacklist_address_table_migrated', 'no' );
		if ( 'yes' === $done ) {
			return;
		}

		$rows = $wpdb->get_results(
			"SELECT id, customer_address, is_blocked, date_added, description
			FROM {$this->blacklist_table_name}
			WHERE customer_address IS NOT NULL
			AND TRIM(customer_address) <> ''",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			update_option( 'wc_blacklist_address_table_migrated', 'yes' );
			return;
		}

		foreach ( $rows as $row ) {
			$display = sanitize_text_field( $row['customer_address'] );
			$display = trim( $display );

			if ( '' === $display ) {
				continue;
			}

			$date_added = ! empty( $row['date_added'] ) && '0000-00-00 00:00:00' !== $row['date_added']
				? $row['date_added']
				: current_time( 'mysql', 1 );

			$notes = isset( $row['description'] ) ? wp_strip_all_tags( $row['description'] ) : '';

			$parts = array_map( 'trim', explode( ',', $display ) );
			$parts = array_values( array_filter( $parts, 'strlen' ) );

			$match_type                 = 'address';
			$country_code               = '';
			$state_code                 = '';
			$city_norm                  = '';
			$postcode_norm              = '';
			$address_line_norm          = '';
			$address_core_norm          = '';
			$address_full_norm          = '';
			$address_core_hash          = '';
			$address_line_postcode_hash = '';
			$address_hash               = '';

			/*
			* Try to infer legacy region rules:
			* - "postcode, country" => postcode rule
			* - "state, country"    => state rule
			* Otherwise => full address rule
			*/
			if ( 2 === count( $parts ) ) {
				$first_part   = $parts[0];
				$second_part  = strtoupper( $parts[1] );
				$country_code = preg_match( '/^[A-Z]{2}$/', $second_part ) ? $second_part : '';

				if ( '' !== $country_code ) {
					$maybe_postcode = yobm_normalize_postcode( $first_part );
					$maybe_state    = yobm_norm_str( $first_part );

					/*
					* Heuristic:
					* If normalized postcode contains at least one digit,
					* treat it as postcode. Otherwise treat it as state.
					*/
					if ( '' !== $maybe_postcode && preg_match( '/\d/', $maybe_postcode ) ) {
						$match_type    = 'postcode';
						$postcode_norm = $maybe_postcode;
					} elseif ( '' !== $maybe_state ) {
						$match_type = 'state';
						$state_code = $maybe_state;
					}
				}
			}

			if ( 'address' === $match_type ) {
				$address_line_norm = yobm_normalize_address_line( $display );
				$address_core_norm = yobm_build_address_core_norm( $address_line_norm );
				$address_full_norm = yobm_norm_str( $display );

				if ( '' === $address_full_norm ) {
					continue;
				}

				$address_hash = md5( $address_full_norm );

				if ( '' !== $address_core_norm ) {
					$address_core_hash = md5( $address_core_norm );
				}

				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id
						FROM {$this->blacklist_addresses_table_name}
						WHERE blacklist_id = %d
						AND match_type = %s
						AND address_hash = %s
						LIMIT 1",
						(int) $row['id'],
						'address',
						$address_hash
					)
				);

				if ( $exists ) {
					continue;
				}
			} elseif ( 'postcode' === $match_type ) {
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id
						FROM {$this->blacklist_addresses_table_name}
						WHERE blacklist_id = %d
						AND match_type = %s
						AND country_code = %s
						AND postcode_norm = %s
						LIMIT 1",
						(int) $row['id'],
						'postcode',
						$country_code,
						$postcode_norm
					)
				);

				if ( $exists ) {
					continue;
				}
			} elseif ( 'state' === $match_type ) {
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id
						FROM {$this->blacklist_addresses_table_name}
						WHERE blacklist_id = %d
						AND match_type = %s
						AND country_code = %s
						AND state_code = %s
						LIMIT 1",
						(int) $row['id'],
						'state',
						$country_code,
						$state_code
					)
				);

				if ( $exists ) {
					continue;
				}
			}

			$wpdb->insert(
				$this->blacklist_addresses_table_name,
				array(
					'blacklist_id'               => (int) $row['id'],
					'match_type'                 => $match_type,
					'is_blocked'                 => (int) $row['is_blocked'],
					'country_code'               => $country_code,
					'state_code'                 => $state_code,
					'city_norm'                  => $city_norm,
					'postcode_norm'              => $postcode_norm,
					'address_line_norm'          => $address_line_norm,
					'address_core_norm'          => $address_core_norm,
					'address_full_norm'          => $address_full_norm,
					'address_core_hash'          => $address_core_hash,
					'address_line_postcode_hash' => $address_line_postcode_hash,
					'address_hash'               => $address_hash,
					'address_display'            => $display,
					'notes'                      => $notes,
					'date_added'                 => $date_added,
				),
				array(
					'%d',
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
				)
			);
		}

		update_option( 'wc_blacklist_address_table_migrated', 'yes' );
	}

	private function table_exists( $table_name ) {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		return $exists === $table_name;
	}

	public function is_premium_active() {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		$is_plugin_active      = is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' );
		$is_license_activated  = WC_Blacklist_Manager_Validator::is_premium_active();

		return $is_plugin_active && $is_license_activated;
	}
}

new WC_Blacklist_Manager_DB();
