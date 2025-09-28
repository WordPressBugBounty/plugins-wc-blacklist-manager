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

            $required_version = '2.1.8';

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
		if (!$premium_active && $woocommerce_active && current_user_can('administrator') && get_user_meta($user_id, 'wc_blacklist_manager_ads_notice_dismissed_8925', true) !== 'yes') {
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
		update_user_meta($user_id, 'wc_blacklist_manager_ads_notice_dismissed_8925', 'yes');
		update_user_meta($user_id, 'wc_blacklist_manager_for_forms_ads_notice_dismissed_7825', 'yes');
		update_user_meta($user_id, 'wc_blacklist_manager_ads_notice_dismissed_7325_pro', 'yes');
	}
}

new WC_Blacklist_Manager_Notices();


class WC_Blacklist_Manager_Alert {

	const UMETA_DISMISS           = 'yobm_notice_suggest_enable_captcha';
	const OPTION_LICENSE_STATUS   = 'wc_blacklist_manager_premium_license_status';
	const OPTION_FAIL_STREAK      = 'yobm_failed_orders_streak';
	const OPTION_FAIL_STREAK_TIME = 'yobm_failed_orders_streak_times';
	const FAIL_THRESHOLD          = 4;

	public function __construct() {
		add_action( 'woocommerce_order_status_changed', [ $this, 'track_order_streak' ], 10, 4 );
		add_action( 'admin_notices', [ $this, 'display_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'conditionally_enqueue_inline_scripts' ] );
		add_action( 'wp_ajax_notice_suggest_enable_captcha', [ $this, 'never_show_notice' ] );
	}

	/* ========== Order Tracking ========== */

	public function track_order_streak( $order_id, $old_status, $new_status, $order ) {
		$streak = (int) get_option( self::OPTION_FAIL_STREAK, 0 );
		$times  = json_decode( (string) get_option( self::OPTION_FAIL_STREAK_TIME, '[]' ), true );
		$times  = is_array( $times ) ? $times : [];

		if ( 'failed' === $new_status ) {
			$streak++;
			$times[] = time();
		} elseif ( in_array( $new_status, [ 'processing', 'completed' ], true ) ) {
			$streak = 0;
			$times  = [];
		}

		update_option( self::OPTION_FAIL_STREAK, $streak, false );
		update_option( self::OPTION_FAIL_STREAK_TIME, wp_json_encode( array_slice( $times, -10 ) ), false );
	}

	/* ========== Notices ========== */

	public function display_notices() {
		$this->notice_suggest_enable_captcha();
	}

	public function notice_suggest_enable_captcha() {
		if ( ! current_user_can( 'administrator' ) ) {
			return;
		}

		// Hide for Premium users whose license is activated.
		if ( $this->premium_is_activated() ) {
			return;
		}

		$user_id = get_current_user_id();
		$snooze_until = (int) get_user_meta( $user_id, self::UMETA_DISMISS, true );
		if ( $snooze_until && time() < $snooze_until ) {
			return; // still snoozed
		}

		$streak = (int) get_option( self::OPTION_FAIL_STREAK, 0 );
		if ( $streak < self::FAIL_THRESHOLD ) {
			return;
		}

		$times      = json_decode( (string) get_option( self::OPTION_FAIL_STREAK_TIME, '[]' ), true );
		$times      = is_array( $times ) ? $times : [];
		$first_time = ! empty( $times ) ? reset( $times ) : 0;
		$last_time  = ! empty( $times ) ? end( $times )   : 0;

		$window_str = '';
		if ( $first_time && $last_time ) {
			$window_str = sprintf(
				/* translators: 1: first time, 2: last time */
				__( 'between %1$s and %2$s', 'wc-blacklist-manager' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $first_time ) ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_time ) )
			);
		}

		$premium_url = esc_url( $this->get_premium_buy_url() );

		$headline   = esc_html__( '⚠️ Unusual spike of failed payments detected', 'wc-blacklist-manager' );
		$details    = sprintf(
			esc_html__( '%d failed orders in a row %s.', 'wc-blacklist-manager' ),
			$streak,
			$window_str ? esc_html( $window_str ) : ''
		);
		$para_text  = esc_html__( 'Bots may be testing stolen cards (CVC/CVV).', 'wc-blacklist-manager' ) . ' '
			. esc_html__( 'Blacklist Manager Premium blocks card-testing attacks, reduces failed payments, and automatically flags risky activity—keeping your checkout and gateway clean.', 'wc-blacklist-manager' ) . ' '
			. esc_html__( 'Upgrade now to turn on advanced bot-defense and fraud automation.', 'wc-blacklist-manager' );
		$cta_label  = esc_html__( 'Upgrade to Premium — protect checkout', 'wc-blacklist-manager' );
		$premium_url = esc_url( $premium_url ); // ensure sanitized

		echo '<div class="notice notice-error yobmp-notice-captcha is-dismissible" style="border-left-color:#d63638;">'
			. '<p style="margin-top:8px;margin-bottom:8px;">'
				. '<strong>' . $headline . ':</strong> ' . $details
			. '</p>'
			. '<p style="margin:0 0 10px;">' . $para_text . '</p>'
			. '<p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 12px;">'
				. '<a class="button button-primary" target="_blank" rel="noopener" href="' . $premium_url . '">' . $cta_label . '</a> '
				. '<a href="#" onclick="YOBM_Admin_Notice.doItLater();return false;">' . esc_html__( 'I’ll do it later', 'wc-blacklist-manager' ) . '</a>'
			. '</p>'
		. '</div>';
	}

	public function conditionally_enqueue_inline_scripts( $hook ) {
		if ( ! current_user_can( 'administrator' ) ) {
			return;
		}
		if ( $this->premium_is_activated() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, self::UMETA_DISMISS, true ) === 'yes' ) {
			return;
		}

		$streak = (int) get_option( self::OPTION_FAIL_STREAK, 0 );
		if ( $streak < self::FAIL_THRESHOLD ) {
			return;
		}

		$nonce = wp_create_nonce( 'yobm_notice_suggest_enable_captcha' );

		$script = "
			window.YOBM_Admin_Notice = {
				doItLater: function() {
					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'notice_suggest_enable_captcha',
							security: '{$nonce}'
						},
						complete: function() {
							jQuery('.notice.yobmp-notice-captcha').slideUp(150);
						}
					});
				}
			};
		";
		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', $script );
	}

	/* ========== AJAX dismiss ========== */

	public function never_show_notice() {
		check_ajax_referer( 'yobm_notice_suggest_enable_captcha', 'security' );
		$user_id = get_current_user_id();
		update_user_meta( $user_id, self::UMETA_DISMISS, time() + DAY_IN_SECONDS );
		wp_die();
	}

	/* ========== Helpers ========== */

	private function premium_is_activated() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$premium_active = is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' );
		$license_ok     = ( get_option( self::OPTION_LICENSE_STATUS ) === 'activated' );
		return ( $premium_active && $license_ok );
	}

	private function get_premium_buy_url() {
		// Update to your pricing/checkout URL.
		return 'https://yoohw.com/product/woocommerce-blacklist-manager-premium/';
	}
}

add_action( 'plugins_loaded', function () {
	// Boot only if WooCommerce is active/loaded
	if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) {
		new WC_Blacklist_Manager_Alert();
	}
});