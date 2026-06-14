<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Notices {

	public function __construct() {
		add_action('admin_notices', [$this, 'display_notices']);
		add_action('wp_ajax_dismiss_first_time_notice', [$this, 'dismiss_first_time_notice']);
		add_action('wp_ajax_dismiss_ads_notice', [$this, 'dismiss_ads_notice']);
		add_action('wp_ajax_dismiss_gbd_limit_notice', [ $this, 'dismiss_gbd_limit_notice'] );
		add_action('admin_enqueue_scripts', [$this, 'enqueue_inline_scripts']);
		$this->includes();
	}
	
	public function display_notices() {
		$this->first_time_notice();
		$this->bm_ads_notice();
		$this->premium_update_notice();
		$this->gbd_limit_notice();
	}

	private function is_blacklist_manager_admin_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( $page && 0 === strpos( $page, 'wc-blacklist-manager' ) ) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! empty( $screen->id ) && false !== strpos( $screen->id, 'wc-blacklist-manager' ) ) {
			return true;
		}

		return false;
	}

	private function installed_for_days() {
		$install_date = get_option( 'wc_blacklist_manager_first_install_date', '' );

		if ( empty( $install_date ) ) {
			return 0;
		}

		$installed_time = strtotime( $install_date );
		if ( false === $installed_time ) {
			return 0;
		}

		return (int) floor( ( time() - $installed_time ) / DAY_IN_SECONDS );
	}

	private function recently_showed_action_upsell( $user_id ) {
		$last_shown = get_user_meta( $user_id, 'wc_blacklist_manager_action_upsell_last_shown', true );

		if ( ! is_array( $last_shown ) || empty( $last_shown ) ) {
			return false;
		}

		$latest = max( array_map( 'absint', $last_shown ) );
		return ( time() - $latest ) < ( 7 * DAY_IN_SECONDS );
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

	public function first_time_notice() {
		if ( ! $this->is_blacklist_manager_admin_page() ) {
			return;
		}

		$user_id = get_current_user_id();
	  
		// Check if user is administrator and notice hasn't been dismissed
		if (current_user_can('manage_options') && get_user_meta($user_id, 'wc_blacklist_manager_first_time_notice_dismissed', true) !== 'yes') {
		    echo '<div class="notice notice-info yobm-first-time is-dismissible">
				  <p><strong>' . esc_html__( 'Blacklist Manager is ready to configure.', 'wc-blacklist-manager' ) . '</strong> ' . sprintf(
					/* translators: 1: settings link, 2: docs link */
					esc_html__( 'Review the %1$s and %2$s before enabling blocking rules on a live store.', 'wc-blacklist-manager' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-blacklist-manager-settings' ) ) . '">' . esc_html__( 'settings', 'wc-blacklist-manager' ) . '</a>',
					'<a href="https://docs.yoohw.com/category/blacklist-manager/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'documentation', 'wc-blacklist-manager' ) . '</a>'
				  ) . '</p>
				  <p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissFirstTimeNotice(); return false;">' . esc_html__( 'Got it', 'wc-blacklist-manager' ) . '</a></p>
			  </div>';
		}
	}

	public function bm_ads_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

		if ( ! $this->is_blacklist_manager_admin_page() ) {
			return;
		}

		if ( $this->installed_for_days() < 7 ) {
			return;
		}
		
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, 'wc_blacklist_manager_first_time_notice_dismissed', true ) !== 'yes' ) {
			return;
		}

		if ( $this->recently_showed_action_upsell( $user_id ) ) {
			return;
		}

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
					<p><strong>' . esc_html__( 'Need deeper fraud review?', 'wc-blacklist-manager' ) . '</strong> ' . esc_html__( 'Premium adds risk scoring, payment intelligence, automation, multi-store sync, and activity logs when manual review is no longer enough.', 'wc-blacklist-manager' ) . '</p>
					<p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissAdsNotice(); return false;" class="button button-secondary">' . esc_html__( 'Dismiss', 'wc-blacklist-manager' ) . '</a> <a href="https://yoohw.com/product/blacklist-manager-premium/" class="button button-primary" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Explore Premium Protection', 'wc-blacklist-manager' ) . '</a></p>
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

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active    = $settings_instance->is_premium_active();

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

		$message = esc_html__( 'Your monthly Global Blacklist Decisions checks are used up. New orders will not be screened against the shared fraud network until the quota resets or your plan is upgraded.', 'wc-blacklist-manager' );

		if ( $premium_active ) {
			$message .= '<br>' . wp_kses_post( __( 'Premium users can get <b>up to 50% off</b> when upgrading to a higher Global Blacklist plan.', 'wc-blacklist-manager' ) );
		}

		$disable_btn = '';

		if ( $premium_active ) {
			$disable_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=disable_global_blacklist' ),
				'disable_global_blacklist'
			);

			$disable_btn = sprintf(
				'<a href="%1$s" class="button" onclick="return confirm(\'This will stop Global Blacklist checks for all new orders. Continue?\');">%2$s</a>',
				esc_url( $disable_url ),
				esc_html__( 'Disable Global Blacklist', 'wc-blacklist-manager-premium' )
			);
		}

		printf(
			'<div class="notice notice-warning is-dismissible yobm-gbd-limit">
				<p><strong>%1$s</strong></p>
				<p>%2$s</p>
				<p>
					<a href="#" class="button button-secondary" onclick="WC_Blacklist_Manager_Admin_Notice.dismissGBDLimitNotice(); return false;">%5$s</a>
					%6$s
					<a href="%3$s" class="button button-primary" target="_blank">%4$s</a>
				</p>
			</div>',
			esc_html__( 'Global Blacklist Decisions monthly limit reached', 'wc-blacklist-manager' ),
			wp_kses_post( $message ),
			esc_url( $upgrade_url ),
			esc_html__( 'Upgrade plan', 'wc-blacklist-manager' ),
			esc_html__( 'Dismiss', 'wc-blacklist-manager' ),
			$disable_btn
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

		echo '<div class="notice notice-error yobm-premium-download">';
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
		$nonce_first_time = wp_create_nonce('dismiss_first_time_notice_nonce');
		$nonce_ads_notice = wp_create_nonce('dismiss_ads_notice_nonce');
		$nonce_gbd_limit = wp_create_nonce( 'dismiss_gbd_limit_notice_nonce' );

		$script = "
			var WC_Blacklist_Manager_Admin_Notice = {
				dismissFirstTimeNotice: function() {
					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'dismiss_first_time_notice',
							security: '{$nonce_first_time}'
						},
						success: function() {
							jQuery('.notice.yobm-first-time').hide();
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
							jQuery('.notice.yobm-ads').hide();
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

			jQuery(function($) {
				$(document).on('click', '.notice.yobm-first-time .notice-dismiss', function() {
					WC_Blacklist_Manager_Admin_Notice.dismissFirstTimeNotice();
				});

				$(document).on('click', '.notice.yobm-ads .notice-dismiss', function() {
					WC_Blacklist_Manager_Admin_Notice.dismissAdsNotice();
				});

				$(document).on('click', '.notice.yobm-gbd-limit .notice-dismiss', function() {
					WC_Blacklist_Manager_Admin_Notice.dismissGBDLimitNotice();
				});
			});
		";

		wp_add_inline_script('jquery', $script);
	}
		
		public function dismiss_first_time_notice() {
			check_ajax_referer('dismiss_first_time_notice_nonce', 'security');

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-blacklist-manager' ) ), 403 );
			}

			$user_id = get_current_user_id();
			update_user_meta($user_id, 'wc_blacklist_manager_first_time_notice_dismissed', 'yes');
			wp_send_json_success();
		}

		public function dismiss_ads_notice() {
			check_ajax_referer('dismiss_ads_notice_nonce', 'security');

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-blacklist-manager' ) ), 403 );
			}

			$user_id = get_current_user_id();
			$current_time = current_time( 'timestamp' );

	        update_user_meta( $user_id, 'blacklist_manager_premium_ads_time', $current_time );
			wp_send_json_success();
		}

		public function dismiss_gbd_limit_notice() {
			check_ajax_referer( 'dismiss_gbd_limit_notice_nonce', 'security' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-blacklist-manager' ) ), 403 );
			}

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
