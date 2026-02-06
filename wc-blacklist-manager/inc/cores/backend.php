<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Backend {
	public function __construct() {
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
		$this->includes();

		$allowed_countries_option = get_option('woocommerce_allowed_countries', 'all');
		$specific_countries = get_option('woocommerce_specific_allowed_countries', []);
		$skip_country_code = ($allowed_countries_option === 'specific' && count($specific_countries) === 1);

		include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		$premium_active = (is_plugin_active('wc-blacklist-manager-premium/wc-blacklist-manager-premium.php') && get_option('wc_blacklist_manager_premium_license_status') === 'activated');
		
		if (!is_plugin_active('wc-blacklist-manager-premium/wc-blacklist-manager-premium.php') && get_option('wc_blacklist_manager_premium_license_status') === 'activated'){
			add_action('admin_notices', ['WC_Blacklist_Manager_Notices', 'show_download_premium_notice']);
		}

		if ($skip_country_code || !$premium_active) {
			return;
		}

		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
	}

	public function enqueue_assets( $hook_suffix ) {
		$style_ver  = '1.6.0';
		$script_ver = '1.2';

		// Determine current admin screen context
		$screen     = function_exists('get_current_screen') ? get_current_screen() : null;
		$screen_id  = $screen ? $screen->id : '';
		$post_type  = $screen ? $screen->post_type : '';
		$page_param = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

		// 1) Your plugin page + any "children" views under ?page=wc-blacklist-manager
		$is_plugin_pages =
			( $page_param && strpos( $page_param, 'wc-blacklist-manager' ) === 0 ) ||
			( $screen_id  && strpos( $screen_id,  'wc-blacklist-manager' ) !== false ) ||
			( $hook_suffix && strpos( $hook_suffix, 'wc-blacklist-manager' ) !== false );

		// 2) WooCommerce Orders (both legacy posts UI and HPOS Orders screen)
		$is_wc_orders = (
			$screen_id === 'edit-shop_order' ||
			$screen_id === 'woocommerce_page_wc-orders' ||
			$post_type  === 'shop_order'
		);

		// 3) Users list + edit user + own profile
		$is_user_screens = in_array( $screen_id, array( 'users', 'user-edit', 'profile' ), true );

		$should_enqueue_style = ( $is_plugin_pages || $is_wc_orders || $is_user_screens );

		if ( $should_enqueue_style ) {
			wp_enqueue_style(
				'wc-blacklist-style',
				plugin_dir_url( __FILE__ ) . '../../css/style.css',
				array(),
				$style_ver
			);
		}

		// Keep your JS only on the plugin's own dashboard page (as before)
		$plugin_pages = array( 'toplevel_page_wc-blacklist-manager' );
		if ( in_array( $hook_suffix, $plugin_pages, true ) ) {
			wp_enqueue_script(
				'wc-blacklist-script',
				plugin_dir_url( __FILE__ ) . '../../js/dashboard.js',
				array( 'jquery' ),
				$script_ver,
				true
			);

			wp_add_inline_script( 'wc-blacklist-script', 'var messageTimeout = 3000;' );
		}
	}

	public function enqueue_scripts( $hook ) {
		if ( $hook !== 'toplevel_page_wc-blacklist-manager' ) {
			return;
		}

		wp_enqueue_script( 'yobm-dashboard-form', plugin_dir_url( __FILE__ ) . '../../js/dashboard-phone-dial-code.js', array(), '1.0.0', true );

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
		include_once plugin_dir_path(__FILE__) . '../backend/dashboard.php';
		include_once plugin_dir_path(__FILE__) . '../backend/activity.php';
		include_once plugin_dir_path(__FILE__) . '../backend/verifications.php';
		include_once plugin_dir_path(__FILE__) . '../backend/notifications.php';
		include_once plugin_dir_path(__FILE__) . '../backend/settings.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yoohw-dashboard.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yoohw-news.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yoohw-settings.php';
		include_once plugin_dir_path(__FILE__) . '../backend/yoohw-license.php';
		include_once plugin_dir_path(__FILE__) . '../backend/order-action-button.php';
		include_once plugin_dir_path(__FILE__) . '../backend/order-risk-score.php';
		include_once plugin_dir_path(__FILE__) . '../backend/actions/sub/helper.php';
		require_once plugin_dir_path(__FILE__) . '/api/yogb/yogb-register.php';
		require_once plugin_dir_path(__FILE__) . '/api/yogb/yogb-reports.php';
		require_once plugin_dir_path(__FILE__) . '/api/yogb/yogb-revoke.php';
		require_once plugin_dir_path(__FILE__) . '/api/yogb/yogb-check.php';
		require_once plugin_dir_path(__FILE__) . '/api/yogb/yogb-tier.php';
		include_once plugin_dir_path(__FILE__) . '/api/sms/sms-quota.php';
		include_once plugin_dir_path(__FILE__) . '/api/push-subscription.php';
	}
}

new WC_Blacklist_Manager_Backend();
