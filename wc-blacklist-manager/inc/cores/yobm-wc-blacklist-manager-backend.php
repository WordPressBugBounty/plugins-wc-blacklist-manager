<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Backend {
	public function __construct() {
		$this->includes();

		$allowed_countries_option = get_option('woocommerce_allowed_countries', 'all');
		$specific_countries = get_option('woocommerce_specific_allowed_countries', []);
		$skip_country_code = ($allowed_countries_option === 'specific' && count($specific_countries) === 1);

		include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		$premium_active = (is_plugin_active('wc-blacklist-manager-premium/wc-blacklist-manager-premium.php') && get_option('wc_blacklist_manager_premium_license_status') === 'activated');
		
		if ($skip_country_code || !$premium_active) {
			return;
		}

		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
	}

	public function enqueue_scripts( $hook ) {
		if ( $hook !== 'toplevel_page_wc-blacklist-manager' ) {
			return;
		}

		wp_enqueue_script( 'yobm-dashboard-form', plugin_dir_url( __FILE__ ) . '../../js/yobm-wc-blacklist-manager-dashboard-phone-dial-code.js', array(), '1.0.0', true );

		$country_code = 'us';

		$ip       = get_real_customer_ip();
		$response = wp_remote_get( 'http://ip-api.com/json/' . $ip );
	
		if ( ! is_wp_error( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			if ( ! empty( $data['countryCode'] ) ) {
				$country_code = strtolower( $data['countryCode'] );
			}
		}

		$allowed_countries_option = get_option('woocommerce_allowed_countries', 'all');
		$excluded_countries = [];
		$specific_countries = [];
	
		if ($allowed_countries_option === 'all_except') {
			// Get the excluded countries list
			$excluded_countries = get_option('woocommerce_all_except_countries', []);
		} elseif ($allowed_countries_option === 'specific') {
			// Get the specific allowed countries list
			$specific_countries = get_option('woocommerce_specific_allowed_countries', []);
		}

        wp_localize_script( 'yobm-dashboard-form', 'yobmDashboardForm', array(
            'initial_country'      => $country_code,
			'excluded_countries'   => $excluded_countries,
			'specific_countries'   => $specific_countries,
        ) );
	}

	private function includes() {
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-button-add-to-suspects.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-button-add-to-blocklist.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-dashboard.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-verifications.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-activity.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-notifications.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yobm-wc-blacklist-manager-settings.php';
		include_once plugin_dir_path(__FILE__) . '/api/sms/yobm-wc-blacklist-manager-update-sms-quota.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yoohw-menu-dashboard.php';
		include_once plugin_dir_path(__FILE__) . '../backend/actions/sub/yobm-wc-blacklist-manager-function-helper.php';
	}
}

new WC_Blacklist_Manager_Backend();
