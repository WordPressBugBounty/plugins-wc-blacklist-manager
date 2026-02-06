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

            $required_version = '2.3';

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
		$woocommerce_active = class_exists( 'WooCommerce' );

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
			if (!$premium_active && $woocommerce_active) {
				echo '<div class="notice notice-info yobm-ads is-dismissible">
					<p><b>🚀 Blacklist Manager Premium — Total Fraud & Spam Defence for WooCommerce</b></p>
						
					<p>
						Unlock advanced blocking (Customer name & address), disposable email/phone & VPN detection, AI-driven bot shield, risk-score automation, multi-site blacklist sync, power payment protection, and forensic activity logs — everything you need to keep scammers out and revenue in.
					</p>

					<p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissAdsNotice()" class="button-secondary">Dismiss</a> <a href="https://yoohw.com/product/blacklist-manager-premium/" class="button-primary">🌟 Unlock Premium Now</a></p>
					</div>';
			}

			if (!$premium_active && !$woocommerce_active) {
				echo '<div class="notice notice-info yobm-ads is-dismissible">
					<p>🛡️ <strong>Protect Every Form Submission — Instantly</strong></p>
					<p>Upgrade to <strong>Blacklist&nbsp;Manager&nbsp;Premium&nbsp;for Forms</strong> and unlock IP blocking, disposable-email & domain filters, automatic user blacklisting, linked up with powerful third party services, and more.</p>
					<p>
						<a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissAdsNotice()" style="margin-right:10px;">Dismiss</a>
						<a href="https://yoohw.com/product/blacklist-manager-premium-for-forms/" target="_blank" class="button button-primary">Upgrade&nbsp;Now</a>
					</p>
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
		$plugins_page = esc_url( admin_url( 'plugins.php' ) );
		$activate = sprintf(
			wp_kses(
				'<a href="%s">activate it</a>',
				[ 'a' => [ 'href' => [] ] ]
			),
			$plugins_page
		);

		echo '<div class="error">
				<p>License activated, but the Premium add-on is not activated or installed on your site yet. Please ' . $activate . ', or login to your account on our website to download and install it.</p>
				<p><a href="https://yoohw.com/my-account/" class="button-primary" target="_blank">Go to My account</a></p>
			</div>';
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
}

new WC_Blacklist_Manager_Notices();


class WC_Blacklist_Manager_Alert {

	/* ====== Options & Meta Keys ====== */
	const UMETA_DISMISS            = 'yobm_notice_suggest_enable_captcha'; // per-user snooze: UNIX timestamp until hidden
	const OPTION_LICENSE_STATUS    = 'wc_blacklist_manager_premium_license_status';
	const OPTION_FAIL_STREAK       = 'yobm_failed_orders_streak';
	const OPTION_FAIL_STREAK_TIME  = 'yobm_failed_orders_streak_times';     // json[int,...] of recent failed timestamps
	const OPTION_SPIKE_ARMED       = 'yobm_failed_spike_armed_at';          // int timestamp (site-wide) when a spike episode began

	/* ====== Behavior ====== */
	const FAIL_THRESHOLD           = 4;                  // consecutive failed orders to consider a spike
	const MAX_TIMESTAMPS_STORED    = 10;                 // how many fail times to keep (for “between A and B” window)
	const SNOOZE_SECONDS           = DAY_IN_SECONDS;     // per-user “I’ll do it later”
	const SPIKE_TTL                = WEEK_IN_SECONDS;    // 0 = never auto-expire
	const SPIKE_WINDOW_SECONDS = 15 * MINUTE_IN_SECONDS;

	public function __construct() {
		add_action( 'woocommerce_order_status_failed', [ $this, 'track_order_streak' ], 10, 1 );

		add_action( 'admin_notices', [ $this, 'display_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'conditionally_enqueue_inline_scripts' ] );

		add_action( 'wp_ajax_notice_suggest_enable_captcha', [ $this, 'handle_notice_actions' ] );
	}

	/* =========================================================
	 * Order Tracking
	 * ======================================================= */

	public function track_order_streak( $order_id ) {
		$streak = (int) get_option( self::OPTION_FAIL_STREAK, 0 );
		$times  = json_decode( (string) get_option( self::OPTION_FAIL_STREAK_TIME, '[]' ), true );
		$times  = is_array( $times ) ? $times : [];

		$now      = time();
		$streak++;
		$times[]  = $now;

		// Keep only the latest MAX_TIMESTAMPS_STORED timestamps
		$times = array_slice( $times, - self::MAX_TIMESTAMPS_STORED );

		// Count failures in the recent window
		$recent_count = $this->count_recent_failures( $times, self::SPIKE_WINDOW_SECONDS );

		// Arm ONCE when the spike episode starts (based on recent window, not just raw streak)
		if ( $recent_count >= self::FAIL_THRESHOLD && ! get_option( self::OPTION_SPIKE_ARMED, 0 ) ) {
			update_option( self::OPTION_SPIKE_ARMED, $now, false );
		}

		update_option( self::OPTION_FAIL_STREAK, $streak, false );
		update_option( self::OPTION_FAIL_STREAK_TIME, wp_json_encode( $times ), false );
	}

	private function count_recent_failures( array $timestamps, int $window_seconds ): int {
		$cutoff = time() - $window_seconds;
		$count  = 0;

		foreach ( $timestamps as $ts ) {
			$ts = (int) $ts;
			if ( $ts >= $cutoff ) {
				$count++;
			}
		}

		return $count;
	}

	/* =========================================================
	 * Notices
	 * ======================================================= */

	public function display_notices() {
		$this->notice_suggest_enable_captcha();
	}

	public function notice_suggest_enable_captcha() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Hide for Premium users whose license is activated.
		if ( $this->premium_is_activated() ) {
			return;
		}

		$user_id = get_current_user_id();

		// Respect per-user snooze
		$snooze_until = (int) get_user_meta( $user_id, self::UMETA_DISMISS, true );
		if ( $snooze_until && time() < $snooze_until ) {
			return;
		}

		$streak   = (int) get_option( self::OPTION_FAIL_STREAK, 0 );
		$armed_at = (int) get_option( self::OPTION_SPIKE_ARMED, 0 );

		$times = json_decode( (string) get_option( self::OPTION_FAIL_STREAK_TIME, '[]' ), true );
		$times = is_array( $times ) ? $times : [];

		// Failures in the recent window (e.g. last 15 minutes)
		$recent_count = $this->count_recent_failures( $times, self::SPIKE_WINDOW_SECONDS );

		// TTL cleanup
		if ( $armed_at && self::SPIKE_TTL > 0 && ( time() - $armed_at ) > self::SPIKE_TTL ) {
			delete_option( self::OPTION_SPIKE_ARMED );
			$armed_at = 0;
		}

		// Gate: show if either the recent window is hot OR spike is armed
		if ( $recent_count < self::FAIL_THRESHOLD && ! $armed_at ) {
			return;
		}

		$first_time = ! empty( $times ) ? (int) reset( $times ) : 0;
		$last_time  = ! empty( $times ) ? (int) end( $times )   : 0;

		$window_str = '';
		if ( $first_time && $last_time ) {
			$window_str = sprintf(
				/* translators: 1: first time, 2: last time */
				__( 'between %1$s and %2$s', 'wc-blacklist-manager' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $first_time ) ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_time ) )
			);
		}

		$headline    = esc_html__( '⚠️ Unusual spike of failed payments detected', 'wc-blacklist-manager' );
		$details = sprintf(
			/* translators: 1: number of failed orders, 2: time window string */
			esc_html__( '%1$d failed orders in a short period %2$s.', 'wc-blacklist-manager' ),
			max( $recent_count, self::FAIL_THRESHOLD ),
			$window_str ? esc_html( $window_str ) : ''
		);
		$para_text   = esc_html__( 'Bots may be testing stolen cards (CVC/CVV).', 'wc-blacklist-manager' ) . ' '
			. esc_html__( 'Blacklist Manager Premium blocks card-testing attacks, reduces failed payments, and automatically flags risky activity—keeping your checkout and gateway clean.', 'wc-blacklist-manager' ) . ' '
			. esc_html__( 'Upgrade now to turn on advanced bot-defense and fraud automation.', 'wc-blacklist-manager' );
		$cta_label   = esc_html__( 'Upgrade to Premium — protect checkout', 'wc-blacklist-manager' );
		$premium_url = esc_url( $this->get_premium_buy_url() );

		echo '<div class="notice notice-error yobmp-notice-captcha is-dismissible" style="border-left-color:#d63638;">'
			. '<p style="margin-top:8px;margin-bottom:8px;"><strong>' . $headline . ':</strong> ' . $details . '</p>'
			. '<p style="margin:0 0 10px;">' . $para_text . '</p>'
			. '<p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 12px;">'
				. '<a class="button button-primary" target="_blank" rel="noopener" href="' . $premium_url . '">' . $cta_label . '</a> '
				. '<a href="#" onclick="YOBM_Admin_Notice.doItLater();return false;">' . esc_html__( 'I’ll do it later', 'wc-blacklist-manager' ) . '</a>'
				. '<span aria-hidden="true">·</span> '
				. '<a href="#" onclick="YOBM_Admin_Notice.resolveSpike();return false;">' . esc_html__( 'Mark as resolved', 'wc-blacklist-manager' ) . '</a>'
			. '</p>'
		. '</div>';
	}

	public function conditionally_enqueue_inline_scripts( $hook ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $this->premium_is_activated() ) {
			return;
		}

		$user_id      = get_current_user_id();
		$snooze_until = (int) get_user_meta( $user_id, self::UMETA_DISMISS, true );
		if ( $snooze_until && time() < $snooze_until ) {
			return;
		}

		$armed_at = (int) get_option( self::OPTION_SPIKE_ARMED, 0 );
		$times    = json_decode( (string) get_option( self::OPTION_FAIL_STREAK_TIME, '[]' ), true );
		$times    = is_array( $times ) ? $times : [];

		$recent_count = $this->count_recent_failures( $times, self::SPIKE_WINDOW_SECONDS );

		// TTL cleanup
		if ( $armed_at && self::SPIKE_TTL > 0 && ( time() - $armed_at ) > self::SPIKE_TTL ) {
			delete_option( self::OPTION_SPIKE_ARMED );
			$armed_at = 0;
		}

		// Only enqueue if we would show the notice (same gate as notice_suggest_enable_captcha)
		if ( $recent_count < self::FAIL_THRESHOLD && ! $armed_at ) {
			return;
		}

		$nonce = wp_create_nonce( 'yobm_notice_suggest_enable_captcha' );

		$script = "
			window.YOBM_Admin_Notice = {
				doItLater: function() {
					jQuery.post( ajaxurl, {
						action: 'notice_suggest_enable_captcha',
						security: '{$nonce}',
						mode: 'snooze'
					}).always(function(){
						jQuery('.notice.yobmp-notice-captcha').slideUp(150);
					});
				},
				resolveSpike: function() {
					jQuery.post( ajaxurl, {
						action: 'notice_suggest_enable_captcha',
						security: '{$nonce}',
						mode: 'resolve'
					}).always(function(){
						jQuery('.notice.yobmp-notice-captcha').slideUp(150);
					});
				}
			};
		";

		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', $script, 'after' );
	}

	/* =========================================================
	 * AJAX: Snooze / Resolve
	 * ======================================================= */

	public function handle_notice_actions() {
		check_ajax_referer( 'yobm_notice_suggest_enable_captcha', 'security' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'snooze';

		if ( 'resolve' === $mode ) {
			// Clear the spike flag globally so a NEW spike can re-arm and show again.
			delete_option( self::OPTION_SPIKE_ARMED );
			wp_die();
		}

		// Default: snooze this user only (does NOT block future spikes from reappearing after snooze ends).
		update_user_meta( get_current_user_id(), self::UMETA_DISMISS, time() + self::SNOOZE_SECONDS );
		wp_die();
	}

	/* =========================================================
	 * Helpers
	 * ======================================================= */

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
		return 'https://yoohw.com/product/blacklist-manager-premium/';
	}
}

add_action( 'plugins_loaded', function () {
	// Boot only if WooCommerce is active/loaded
	if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) {
		new WC_Blacklist_Manager_Alert();
	}
});