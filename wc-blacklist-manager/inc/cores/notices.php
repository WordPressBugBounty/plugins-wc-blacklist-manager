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
		add_action('wp_ajax_dismiss_gbd_limit_notice', [ $this, 'dismiss_gbd_limit_notice'] );
		add_action('admin_enqueue_scripts', [$this, 'enqueue_inline_scripts']);
		$this->includes();
	}
	
	public function display_notices() {
		$this->review_notice();
		$this->first_time_notice();
		$this->bm_ads_notice();
		$this->premium_update_notice();
		$this->gbd_limit_notice();
	}

    /**
     * Show an error if the Premium plugin is active but below the required version.
     */
    public function premium_update_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
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

            $required_version = '2.3.4';

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
	
		if (current_user_can('manage_options') && $days_since_activation >= 1) {
			echo '<div class="notice notice-info yobm-review is-dismissible">
					<p>Thank you for using Blacklist Manager! Please support us by <a href="https://wordpress.org/support/plugin/wc-blacklist-manager/reviews/#new-post" target="_blank">leaving a review</a> <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> to keep updating & improving.</p>
					<p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissForever()">Never show this again</a></p>
				  </div>';
		}
	}
	
	public function first_time_notice() {
		$user_id = get_current_user_id();
	  
		// Check if user is administrator and notice hasn't been dismissed
		if (current_user_can('manage_options') && get_user_meta($user_id, 'wc_blacklist_manager_first_time_notice_dismissed', true) !== 'yes') {
		    echo '<div class="notice error yobm-first-time is-dismissible">
				  <p style="color:#d63638;">Blacklist Manager is a security and guardian plugin! Kindly read our <a href="https://yoohw.com/docs/category/woocommerce-blacklist-manager/" target="_blank">Documentation</a> carefully before <a href="' . esc_url(admin_url('admin.php?page=wc-blacklist-manager-settings')) . '">visiting the Settings page</a> to configure the plugin.<br>To avoid unexpected workflows or any help needed, please reach out to our technique support team.</p>
				  <p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissFirstTimeNotice()">I understand and do not show this notice again!</a></p>
			  </div>';
		}
	}

	public function bm_ads_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
		
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		$user_id = get_current_user_id();
		$last_shown_time = get_user_meta( $user_id, 'blacklist_manager_premium_ads_time', true );
        $current_time    = current_time( 'timestamp' );
        $delay_seconds   = 30 * DAY_IN_SECONDS;

        $should_show = false;

		if ( ! $last_shown_time ) {
            // Never seen before -> show now
            $should_show = true;
        } else {
            $time_since = $current_time - (int) $last_shown_time;
            if ( $time_since >= $delay_seconds ) {
                $should_show = true;
            }
        }
	  
		if ( $should_show ) {
			// Check if user is administrator and notice hasn't been dismissed
			if (!$premium_active) {
				echo '<div class="notice notice-info yobm-ads is-dismissible">
					<p><b>🚀 Blacklist Manager Premium — Total Fraud & Spam Defence for WooCommerce</b></p>
						
					<p>
						Unlock advanced blocking (Customer name & address), disposable email/phone & VPN detection, AI-driven bot shield, risk-score automation, multi-site blacklist sync, power payment protection, and forensic activity logs — everything you need to keep scammers out and revenue in.
					</p>

					<p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissAdsNotice()" class="button-secondary">Dismiss</a> <a href="https://yoohw.com/product/blacklist-manager-premium/" class="button-primary">🌟 Unlock Premium Now</a></p>
					</div>';
			}
		}
	}

	/**
	 * Admin notice when Global Blacklist Decisions monthly limit reached (HTTP 429).
	 */
	public function gbd_limit_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only when Global Blacklist is enabled.
		$enabled = (int) get_option( 'wc_blacklist_enable_global_blacklist', 0 );
		if ( 1 !== $enabled ) {
			return;
		}

		$tier      = (string) get_option( 'yogb_bm_tier', 'free' );
		$tier      = strtolower( trim( $tier ) );
		$month_key = gmdate( 'Ym' );

		$transient_key = 'yogb_gbd_limit_reached_' . $tier . '_' . $month_key;

		$flag = get_transient( $transient_key );
		if ( empty( $flag ) ) {
			return;
		}

		// Per-user dismiss for this tier+month.
		$user_id   = get_current_user_id();
		$dismiss_k = 'yogb_gbd_limit_notice_dismissed_' . $tier . '_' . $month_key;

		if ( 'yes' === get_user_meta( $user_id, $dismiss_k, true ) ) {
			return;
		}

		$upgrade_url = 'https://yoohw.com/global-blacklist-plan/';

		printf(
			'<div class="notice notice-warning is-dismissible yobm-gbd-limit">
				<p><strong>%1$s</strong></p>
				<p>%2$s</p>
				<p>
					<a href="#" class="button button-secondary" onclick="WC_Blacklist_Manager_Admin_Notice.dismissGBDLimitNotice(); return false;">%5$s</a>
					<a href="%3$s" class="button button-primary" target="_blank">%4$s</a>
				</p>
			</div>',
			esc_html( 'Global Blacklist Decisions monthly limit reached' ),
			wp_kses_post( 'Further orders will no longer be screened against our global fraud network, <b>increasing the risk of chargebacks and payment disputes</b>. Upgrade your plan now to keep orders protected and revenue safe.' ),
			esc_url( $upgrade_url ),
			esc_html( 'Upgrade plan' ),
			esc_html( 'Dismiss' )
		);
	}

	public static function show_download_premium_notice() {
		$plugins_page = admin_url( 'plugins.php' );

		$activate_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $plugins_page ),
			esc_html__( 'activate it', 'wc-blacklist-manager' )
		);

		$message = sprintf(
			/* translators: %s: Activate premium plugin link */
			__( 'License activated, but the Premium add-on is not activated or installed on your site yet. Please %s, or login to your account on our website to download and install it.', 'wc-blacklist-manager' ),
			$activate_link
		);

		echo '<div class="error">';
		echo '<p>' . wp_kses(
			$message,
			[
				'a' => [
					'href' => [],
				],
			]
		) . '</p>';

		echo '<p><a href="' . esc_url( 'https://yoohw.com/my-account/' ) . '" class="button-primary" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Go to My account', 'wc-blacklist-manager' )
			. '</a></p>';

		echo '</div>';
	}

	public function enqueue_inline_scripts() {
		$nonce_never_show = wp_create_nonce('never_show_wc_blacklist_manager_notice_nonce');
		$nonce_first_time = wp_create_nonce('dismiss_first_time_notice_nonce');
		$nonce_ads_notice = wp_create_nonce('dismiss_ads_notice_nonce');
		$nonce_gbd_limit = wp_create_nonce( 'dismiss_gbd_limit_notice_nonce' );

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
				},
				dismissGBDLimitNotice: function() {
					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'dismiss_gbd_limit_notice',
							security: '{$nonce_gbd_limit}'
						},
						success: function() {
							jQuery('.notice.yobm-gbd-limit').hide();
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
		$current_time = current_time( 'timestamp' );

        update_user_meta( $user_id, 'blacklist_manager_premium_ads_time', $current_time );
	}

	public function dismiss_gbd_limit_notice() {
		check_ajax_referer( 'dismiss_gbd_limit_notice_nonce', 'security' );

		$tier      = (string) get_option( 'yogb_bm_tier', 'free' );
		$tier      = strtolower( trim( $tier ) );
		$month_key = gmdate( 'Ym' );

		$user_id   = get_current_user_id();
		$dismiss_k = 'yogb_gbd_limit_notice_dismissed_' . $tier . '_' . $month_key;

		update_user_meta( $user_id, $dismiss_k, 'yes' );
		wp_send_json_success();
	}

	private function includes() {
		include_once plugin_dir_path(__FILE__) . 'helper/bot-signal-analyzer.php';
		include_once plugin_dir_path(__FILE__) . 'alert.php';
	}
}

new WC_Blacklist_Manager_Notices();
