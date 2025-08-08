<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Notices {

	public function __construct() {
		add_action('admin_notices', [$this, 'display_notices']);
		add_action('wp_ajax_never_show_wc_blacklist_manager_notice', [$this, 'never_show_notice']);
		add_action('wp_ajax_dismiss_first_time_notice', [$this, 'dismiss_first_time_notice']);
		add_action('wp_ajax_dismiss_ads_notice', [$this, 'dismiss_ads_notice']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_inline_scripts']);
	}
	
	public function display_notices() {
		$this->review_notice();
		$this->first_time_notice();
		$this->ads_notice_advanced_accounts_plugin();
		$this->premium_update_notice(); 
	}

    /**
     * Show an error if the Premium plugin is active but below the required version.
     */
    public function premium_update_notice() {
        if ( ! current_user_can( 'administrator' ) ) {
            return;
        }

        // load WP functions for plugin checks
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_path = 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php';

        if ( is_plugin_active( $plugin_path ) ) {

            $required_version = '2.1.1';

            // get the plugin’s header data
            $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_path );
            $current = isset( $data['Version'] ) ? $data['Version'] : '1.0';

            if ( version_compare( $current, $required_version, '<' ) ) {
                /* translators: 1: current version, 2: required version */
				printf(
					'<div class="notice notice-error yobm-update">
						<p><strong>%1$s</strong><br>
						A newer version of <strong>Blacklist Manager Premium</strong> (version %2$s or higher) is required.<br>
						Please visit your <a href="%4$s">Plugins page</a> to update to the latest version. If you\'re unable to update directly from your site, you can <a href="%3$s" target="_blank">download it manually from our website</a>.</p>
					</div>',
					esc_html( sprintf( __('You’re running v%s', 'wc-blacklist-manager'), $current ) ),
					esc_html( $required_version ),
					esc_url( 'https://yoohw.com/my-account/downloads/' ),
					esc_url( admin_url( 'plugins.php' ) )
				);
            }
        }
    }	

	public function review_notice() {
		$user_id = get_current_user_id();
		$activation_time = get_user_meta($user_id, 'wc_blacklist_manager_activation_time', true);
		$current_time = current_time('timestamp');
	
		if (get_user_meta($user_id, 'wc_blacklist_manager_never_show_again', true) === 'yes') {
			return;
		}
	
		if (!$activation_time) {
			update_user_meta($user_id, 'wc_blacklist_manager_activation_time', $current_time);
			return;
		}
	
		$time_since_activation = $current_time - $activation_time;
		$days_since_activation = floor($time_since_activation / DAY_IN_SECONDS);
	
		if (current_user_can('administrator') && $days_since_activation >= 1) {
			echo '<div class="notice notice-info yobm-review is-dismissible">
					<p>Thank you for using Blacklist Manager! Please support us by <a href="https://wordpress.org/support/plugin/wc-blacklist-manager/reviews/#new-post" target="_blank">leaving a review</a> <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> to keep updating & improving.</p>
					<p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissForever()">Never show this again</a></p>
				  </div>';
		}
	}
	
	public function first_time_notice() {
		$user_id = get_current_user_id();
	  
		// Check if user is administrator and notice hasn't been dismissed
		if (current_user_can('administrator') && get_user_meta($user_id, 'wc_blacklist_manager_first_time_notice_dismissed', true) !== 'yes') {
		    echo '<div class="notice error yobm-first-time is-dismissible">
				  <p style="color:#d63638;">Blacklist Manager is a security and guardian plugin! Kindly read our <a href="https://yoohw.com/docs/category/woocommerce-blacklist-manager/" target="_blank">Documentation</a> carefully before <a href="' . esc_url(admin_url('admin.php?page=wc-blacklist-manager-settings')) . '">visiting the Settings page</a> to configure the plugin.<br>To avoid unexpected workflows or any help needed, please reach out to our technique support team.</p>
				  <p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissFirstTimeNotice()">I understand and do not show this notice again!</a></p>
			  </div>';
		}
	}

	public function ads_notice_advanced_accounts_plugin() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		$woocommerce_active = class_exists( 'WooCommerce' );

		$user_id = get_current_user_id();
	  
		// Check if user is administrator and notice hasn't been dismissed
		if (!$premium_active && $woocommerce_active && current_user_can('administrator') && get_user_meta($user_id, 'wc_blacklist_manager_ads_notice_dismissed_7825', true) !== 'yes') {
		    echo '<div class="notice notice-info yobm-ads is-dismissible">
				  <p><b>🚀 Blacklist Manager Premium — Total Fraud & Spam Defence for WooCommerce</b></p>
					
				  <p>
					Unlock advanced blocking (Customer name & address), disposable email/phone & VPN detection, AI-driven bot shield, risk-score automation, multi-site blacklist sync, and forensic activity logs — everything you need to keep scammers out and revenue in.
				  </p>

				  <p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissAdsNotice()" class="button-secondary">Dismiss</a> <a href="https://yoohw.com/product/woocommerce-blacklist-manager-premium/" class="button-primary">🌟 Unlock Premium Now</a></p>
			    </div>';
		}

		if (!$premium_active && !$woocommerce_active && current_user_can('administrator') && get_user_meta($user_id, 'wc_blacklist_manager_for_forms_ads_notice_dismissed_7825', true) !== 'yes') {
			echo '<div class="notice notice-info yobm-ads is-dismissible">
				<p>🛡️ <strong>Protect Every Form Submission — Instantly</strong></p>
				<p>Upgrade to <strong>Blacklist&nbsp;Manager&nbsp;Premium&nbsp;for Forms</strong> and unlock IP blocking, disposable-email & domain filters, automatic user blacklisting, linked up with powerful third party services, and more.</p>
				<p>
					<a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissAdsNotice()" style="margin-right:10px;">Dismiss</a>
					<a href="https://yoohw.com/product/blacklist-manager-premium-for-forms/" target="_blank" class="button button-primary">Upgrade&nbsp;Now</a>
				</p>
			</div>';
		}

		if ($premium_active && current_user_can('administrator') && get_user_meta($user_id, 'wc_blacklist_manager_ads_notice_dismissed_7325_pro', true) !== 'yes') {
			echo '<div class="notice notice-info yobm-ads is-dismissible" style="display: none;">
					<p><strong>Introducing Blacklist Connection!</strong> Easily link blacklists from multiple stores into one central system. <strong>Available now in version 1.6</strong>. Update today and boost your security! <a href="https://yoohw.com/docs/woocommerce-blacklist-manager/settings/connection/" target="_blank"><strong>Learn More</strong></a></p>
					<p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissAdsNotice()">Dismiss</a></p>
				</div>';
		}
	}

	public function enqueue_inline_scripts() {
		$nonce_never_show = wp_create_nonce('never_show_wc_blacklist_manager_notice_nonce');
		$nonce_first_time = wp_create_nonce('dismiss_first_time_notice_nonce');
		$nonce_ads_notice = wp_create_nonce('dismiss_ads_notice_nonce');

		$script = "
			var WC_Blacklist_Manager_Admin_Notice = {
				dismissForever: function() {
					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'never_show_wc_blacklist_manager_notice',
							security: '{$nonce_never_show}'
						},
						success: function() {
							jQuery('.notice.notice-info.yobm-review').hide();
						}
					});
				},
				dismissFirstTimeNotice: function() {
					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'dismiss_first_time_notice',
							security: '{$nonce_first_time}'
						},
						success: function() {
							jQuery('.notice.error.yobm-first-time').hide();
						}
					});
				},
				dismissAdsNotice: function() {
					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'dismiss_ads_notice',
							security: '{$nonce_ads_notice}'
						},
						success: function() {
							jQuery('.notice.notice-info.yobm-ads').hide();
						}
					});
				}
			};
		";

		wp_add_inline_script('jquery', $script);
	}
	
	public function never_show_notice() {
		check_ajax_referer('never_show_wc_blacklist_manager_notice_nonce', 'security');
		$user_id = get_current_user_id();
		update_user_meta($user_id, 'wc_blacklist_manager_never_show_again', 'yes');
	}
	
	public function dismiss_first_time_notice() {
		check_ajax_referer('dismiss_first_time_notice_nonce', 'security');
		$user_id = get_current_user_id();
		update_user_meta($user_id, 'wc_blacklist_manager_first_time_notice_dismissed', 'yes');
	}

	public function dismiss_ads_notice() {
		check_ajax_referer('dismiss_ads_notice_nonce', 'security');
		$user_id = get_current_user_id();
		update_user_meta($user_id, 'wc_blacklist_manager_ads_notice_dismissed_7825', 'yes');
		update_user_meta($user_id, 'wc_blacklist_manager_for_forms_ads_notice_dismissed_7825', 'yes');
		update_user_meta($user_id, 'wc_blacklist_manager_ads_notice_dismissed_7325_pro', 'yes');
	}
}

new WC_Blacklist_Manager_Notices();
