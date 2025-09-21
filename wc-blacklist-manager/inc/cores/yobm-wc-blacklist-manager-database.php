<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_DB {
	private $blacklist_table_name;
	private $whitelist_table_name;
	private $detection_log_table_name;
	private $version;

	public function __construct() {
		global $wpdb;
		$this->blacklist_table_name = $wpdb->prefix . 'wc_blacklist';
		$this->whitelist_table_name = $wpdb->prefix . 'wc_whitelist';
		$this->detection_log_table_name = $wpdb->prefix . 'wc_blacklist_detection_log';
		$this->version = WC_BLACKLIST_MANAGER_VERSION;

		register_activation_hook(WC_BLACKLIST_MANAGER_PLUGIN_FILE, [$this, 'activate']);
		add_action('admin_init', [$this, 'check_version']);
	}

    public function activate() {
        $this->update_db();
        $this->set_first_install_date();
        $this->install_default_options();
        $this->install_count_options();
        $this->create_trigger();
        $this->create_delete_trigger();
		WC_Blacklist_Manager_Push_Subscription::push_subscription();
    }

    public function check_version() {
        if ( get_option('wc_blacklist_manager_version') != $this->version ) {
            $this->update_db();
            $this->install_default_options();
            $this->install_count_options();
            $this->create_trigger();
            $this->create_delete_trigger();
			WC_Blacklist_Manager_Push_Subscription::push_subscription();
        }
    }	

	public function update_db() {
		global $wpdb;
		$installed_ver = get_option('wc_blacklist_manager_version', '1.0.0');

		if (version_compare($installed_ver, $this->version, '<')) {
			$charset_collate = $wpdb->get_charset_collate();

			$blacklist_sql = "CREATE TABLE $this->blacklist_table_name (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				first_name varchar(255) DEFAULT '' NOT NULL,
				last_name varchar(255) DEFAULT '' NOT NULL,
				phone_number varchar(255) DEFAULT '' NOT NULL,
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
				PRIMARY KEY  (id)
			) $charset_collate;";

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

			$detection_log_sql = "CREATE TABLE $this->detection_log_table_name (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				`timestamp` datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				type varchar(255) NOT NULL,
				source varchar(255) NOT NULL,
				action varchar(255) NOT NULL,
				details text NOT NULL,
				view text NOT NULL,
				PRIMARY KEY (id)
			) $charset_collate;";

			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($blacklist_sql);
			dbDelta($whitelist_sql);
			dbDelta($detection_log_sql);

			$this->backfill_normalized_emails( $this->blacklist_table_name, 500 );

			// Update the version in the database so the update isn't run again.
			update_option('wc_blacklist_manager_version', $this->version);
		}
	}

	public function install_default_options() {
		$default_email_from_name = get_bloginfo('name');
		if ( false === get_option( 'wc_blacklist_sender_name' ) ) {
			add_option( 'wc_blacklist_sender_name', $default_email_from_name );
		}
        
		$default_email_from_address = get_option('admin_email');
		if ( false === get_option( 'wc_blacklist_sender_address' ) ) {
			add_option( 'wc_blacklist_sender_address', $default_email_from_address );
		}

		$default_email_recipient = get_option('admin_email');
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
	}

	public function install_count_options() {
		global $wpdb;

		if ( false === get_option('wc_blacklist_sum_name') ) {
			$name_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->blacklist_table_name} WHERE `first_name` != '' AND `first_name` IS NOT NULL" );
			add_option('wc_blacklist_sum_name', $name_count);
		} else {
			$name_count = get_option('wc_blacklist_sum_name');
		}
		if ( false === get_option('wc_blacklist_sum_phone') ) {
			$phone_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->blacklist_table_name} WHERE `phone_number` != '' AND `first_name` IS NOT NULL" );
			add_option('wc_blacklist_sum_phone', $phone_count);
		} else {
			$phone_count = get_option('wc_blacklist_sum_phone');
		}
		if ( false === get_option('wc_blacklist_sum_email') ) {
			$email_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->blacklist_table_name} WHERE `email_address` != '' AND `first_name` IS NOT NULL" );
			add_option('wc_blacklist_sum_email', $email_count);
		} else {
			$email_count = get_option('wc_blacklist_sum_email');
		}
		if ( false === get_option('wc_blacklist_sum_ip') ) {
			$ip_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->blacklist_table_name} WHERE `ip_address` != '' AND `first_name` IS NOT NULL" );
			add_option('wc_blacklist_sum_ip', $ip_count);
		} else {
			$ip_count = get_option('wc_blacklist_sum_ip');
		}
		if ( false === get_option('wc_blacklist_sum_address') ) {
			$address_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->blacklist_table_name} WHERE `customer_address` != '' AND `first_name` IS NOT NULL" );
			add_option('wc_blacklist_sum_address', $address_count);
		} else {
			$address_count = get_option('wc_blacklist_sum_address');
		}
		if ( false === get_option('wc_blacklist_sum_domain') ) {
			$domain_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->blacklist_table_name} WHERE `domain` != '' AND `first_name` IS NOT NULL" );
			add_option('wc_blacklist_sum_domain', $domain_count);
		} else {
			$domain_count = get_option('wc_blacklist_sum_domain');
		}

		// Create the total sum option if it doesn't exist.
		if ( false === get_option('wc_blacklist_sum_total') ) {
			$total = $name_count + $phone_count + $email_count + $ip_count + $address_count + $domain_count;
			
			add_option('wc_blacklist_sum_total', $total);
		}
	}

    /**
     * Create an AFTER INSERT trigger on the blacklist table.
     * This trigger updates the option values for each field, and then updates the total counter by summing the valid fields.
     */
    public function create_trigger() {
        global $wpdb;

        $trigger_name = 'after_insert_blacklist_row';
        // Drop any existing trigger with the same name.
        $wpdb->query("DROP TRIGGER IF EXISTS {$trigger_name}");

        // Create the combined insert trigger.
        $trigger_sql = "
            CREATE TRIGGER {$trigger_name}
            AFTER INSERT ON {$this->blacklist_table_name}
            FOR EACH ROW
            BEGIN
                -- Update individual counters:
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
                -- Update the total counter by adding the sum of valid fields:
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
        $wpdb->query($trigger_sql);
    }

    /**
     * Create an AFTER DELETE trigger on the blacklist table.
     * This trigger decrements the appropriate counters for each field
     * and subtracts the sum from the total counter.
     */
    public function create_delete_trigger() {
        global $wpdb;

        $trigger_name = 'after_delete_blacklist_row';
        // Drop any existing trigger with the same name.
        $wpdb->query("DROP TRIGGER IF EXISTS {$trigger_name}");

        // Create the combined delete trigger.
        $trigger_sql = "
            CREATE TRIGGER {$trigger_name}
            AFTER DELETE ON {$this->blacklist_table_name}
            FOR EACH ROW
            BEGIN
                -- Update individual counters:
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
                -- Update the total counter by subtracting the sum of valid fields from the deleted row:
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
        $wpdb->query($trigger_sql);
    }

	private function set_first_install_date() {
		if ( false === get_option('wc_blacklist_manager_first_install_date') ) {
			$utc_time = gmdate('Y-m-d H:i:s');
			add_option('wc_blacklist_manager_first_install_date', $utc_time);
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
		if (!$premium_active) {
			return;
		}
		
		// Require Premium >= 2.1.7
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$premium_file = 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php';

		// If the premium plugin file is missing or we can't read version, do nothing.
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $premium_file ) ) {
			return 0;
		}

		$all_plugins = get_plugins();
		$current_version = isset( $all_plugins[ $premium_file ]['Version'] ) ? $all_plugins[ $premium_file ]['Version'] : '';

		if ( empty( $current_version ) || version_compare( $current_version, '2.1.7', '<' ) ) {
			return 0;
		}
		
		global $wpdb;

		// Run-once flag (keep if you already added it)
		if ( get_option( 'wc_blacklist_backfill_done' ) ) {
			return 0;
		}

		// Fresh-install grace window (keep if you already added it)
		$first_install_raw = get_option( 'wc_blacklist_manager_first_install_date', '' );
		if ( ! empty( $first_install_raw ) ) {
			$ts = strtotime( $first_install_raw );
			if ( $ts !== false ) {
				$grace = (int) apply_filters( 'wc_blacklist_backfill_grace_period', DAY_IN_SECONDS );
				if ( $grace > 0 && ( time() - $ts ) < $grace ) {
					return 0;
				}
			}
		}

		// Resolve table name safely
		if ( empty( $table_name ) ) {
			if ( ! empty( $this->blacklist_table_name ) ) {
				$table_name = $this->blacklist_table_name;
			} else {
				$table_name = $wpdb->prefix . 'wc_blacklist';
			}
		}

		// Verify table exists
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) );
		if ( $exists !== $table_name ) {
			error_log( "[WC Blacklist] backfill_normalized_emails: table not found: {$table_name}" );
			return 0;
		}

		// Concurrency lock
		$lock_key = 'wc_blacklist_backfill_lock';
		if ( get_transient( $lock_key ) ) {
			return 0;
		}
		set_transient( $lock_key, 1, 10 * MINUTE_IN_SECONDS );

		$updated = 0;

		try {
			$limit = max( 1, absint( $limit ) );

			// Fetch rows missing normalized_email
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
				// Nothing to do — mark as done and exit
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
				if ( $did !== false ) {
					$updated++;
				}
			}

			// If you prefer to mark done only when no rows remain:
			$remaining = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$table_name}`
				WHERE (normalized_email IS NULL OR normalized_email = '')
				AND email_address <> ''"
			);
			if ( $remaining === 0 ) {
				update_option( 'wc_blacklist_backfill_done', current_time( 'mysql' ), false );
			}

		} finally {
			delete_transient( $lock_key );
		}

		return $updated;
	}

	public function is_premium_active() {
		include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		$is_plugin_active = is_plugin_active('wc-blacklist-manager-premium/wc-blacklist-manager-premium.php');
		$is_license_activated = (get_option('wc_blacklist_manager_premium_license_status') === 'activated');

		return $is_plugin_active && $is_license_activated;
	}
}

new WC_Blacklist_Manager_DB();
