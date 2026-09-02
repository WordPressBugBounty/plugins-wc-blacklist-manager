<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Notices {
	const CUSTOMER_INTELLIGENCE_NOTICE_CAMPAIGN       = '2026_07_customer_intelligence';
	const CUSTOMER_INTELLIGENCE_CAMPAIGN_OPTION       = 'yobm_ci_notice_campaign';
	const CUSTOMER_INTELLIGENCE_ELIGIBLE_AT_OPTION    = 'yobm_ci_notice_eligible_at';
	const CUSTOMER_INTELLIGENCE_DISMISSED_META_PREFIX = 'yobm_ci_notice_dismissed_';
	const CUSTOMER_INTELLIGENCE_PLUGIN_SLUG           = 'yoohw-customer-intelligence';
	const CUSTOMER_INTELLIGENCE_PLUGIN_URL            = 'https://wordpress.org/plugins/yoohw-customer-intelligence/';

	public function __construct() {
		add_action('admin_notices', [$this, 'display_notices']);
		add_action('wp_ajax_dismiss_first_time_notice', [$this, 'dismiss_first_time_notice']);
		add_action('wp_ajax_dismiss_gbd_limit_notice', [ $this, 'dismiss_gbd_limit_notice'] );
		add_action('wp_ajax_dismiss_customer_intelligence_notice', [$this, 'dismiss_customer_intelligence_notice']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_inline_scripts']);
		$this->includes();
	}
	
	public function display_notices() {
		$this->first_time_notice();
		$this->customer_intelligence_notice();
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
					esc_url( WC_Blacklist_Manager_Commercial_Router::downloads_url() ),
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
					esc_html__( 'Start by configuring and saving your local protection in %1$s. Global Blacklist is optional; %2$s can help with the details.', 'wc-blacklist-manager' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-blacklist-manager-settings' ) ) . '">' . esc_html__( 'Settings', 'wc-blacklist-manager' ) . '</a>',
					'<a href="https://docs.yoohw.com/category/blacklist-manager/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'documentation', 'wc-blacklist-manager' ) . '</a>'
				  ) . '</p>
				  <p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissFirstTimeNotice(); return false;">' . esc_html__( 'Got it', 'wc-blacklist-manager' ) . '</a></p>
			  </div>';
		}
	}

	public function customer_intelligence_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->customer_intelligence_notice_is_due() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( 'yes' === get_user_meta( $user_id, $this->customer_intelligence_dismissed_meta_key(), true ) ) {
			return;
		}

		if ( ! $this->site_supports_customer_intelligence() ) {
			return;
		}

		$plugin_state = $this->get_customer_intelligence_plugin_state();
		if ( ! empty( $plugin_state['active'] ) ) {
			return;
		}

		$action = $this->get_customer_intelligence_notice_action( $plugin_state );
		$action_classes = [ 'button', 'button-primary' ];
		if ( ! empty( $action['class'] ) ) {
			$action_classes[] = (string) $action['class'];
		}
		?>
		<div class="notice notice-info is-dismissible yobm-ci-notice">
			<p>
				<strong><?php esc_html_e( 'New: Customer Intelligence for WooCommerce', 'wc-blacklist-manager' ); ?></strong>
				<?php esc_html_e( 'Turn WooCommerce order history into customer profiles, notes, follow-up tasks, tags, segments, and order insights. It works alongside Blacklist Manager when manual customer review needs more context.', 'wc-blacklist-manager' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $action['url'] ); ?>" class="<?php echo esc_attr( implode( ' ', $action_classes ) ); ?>" <?php echo ! empty( $action['external'] ) ? 'target="_blank" rel="noopener noreferrer"' : ''; ?> <?php echo ! empty( $action['title'] ) ? 'title="' . esc_attr( $action['title'] ) . '"' : ''; ?>>
					<?php echo esc_html( $action['label'] ); ?>
				</a>
				<a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissCustomerIntelligenceNotice(); return false;" class="button button-secondary">
					<?php esc_html_e( 'Not now', 'wc-blacklist-manager' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private function customer_intelligence_notice_is_due() {
		$campaign   = (string) get_option( self::CUSTOMER_INTELLIGENCE_CAMPAIGN_OPTION, '' );
		$eligible_at = (int) get_option( self::CUSTOMER_INTELLIGENCE_ELIGIBLE_AT_OPTION, 0 );

		return self::CUSTOMER_INTELLIGENCE_NOTICE_CAMPAIGN === $campaign && $eligible_at > 0 && time() >= $eligible_at;
	}

	private function customer_intelligence_dismissed_meta_key() {
		$campaign = (string) get_option( self::CUSTOMER_INTELLIGENCE_CAMPAIGN_OPTION, self::CUSTOMER_INTELLIGENCE_NOTICE_CAMPAIGN );
		$campaign = sanitize_key( $campaign );

		return self::CUSTOMER_INTELLIGENCE_DISMISSED_META_PREFIX . $campaign;
	}

	private function site_supports_customer_intelligence() {
		global $wp_version;

		$wp_version = ! empty( $wp_version ) ? (string) $wp_version : (string) get_bloginfo( 'version' );
		if ( version_compare( $wp_version, '6.9', '<' ) ) {
			return false;
		}

		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			return false;
		}

		if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
			return false;
		}

		$wc_version = defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
		if ( '' === $wc_version && defined( 'WOOCOMMERCE_VERSION' ) ) {
			$wc_version = (string) WOOCOMMERCE_VERSION;
		}

		if ( '' === $wc_version && function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->version ) ) {
			$wc_version = (string) WC()->version;
		}

		return '' !== $wc_version && version_compare( $wc_version, '8.2', '>=' );
	}

	private function get_customer_intelligence_plugin_state() {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = '';
		$plugins     = get_plugins();

		foreach ( $plugins as $file => $data ) {
			if ( 0 === strpos( $file, self::CUSTOMER_INTELLIGENCE_PLUGIN_SLUG . '/' ) ) {
				$plugin_file = (string) $file;
				break;
			}
		}

		return [
			'installed'   => '' !== $plugin_file,
			'active'      => '' !== $plugin_file && is_plugin_active( $plugin_file ),
			'plugin_file' => $plugin_file,
		];
	}

	private function get_customer_intelligence_notice_action( array $plugin_state ) {
		if ( ! empty( $plugin_state['installed'] ) && ! empty( $plugin_state['plugin_file'] ) && current_user_can( 'activate_plugins' ) ) {
			$plugin_file = (string) $plugin_state['plugin_file'];
			$url         = wp_nonce_url(
				self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $plugin_file ) . '&plugin_status=all&paged=1&s=' ),
				'activate-plugin_' . $plugin_file
			);

			return [
				'label'    => __( 'Activate Customer Intelligence', 'wc-blacklist-manager' ),
				'url'      => $url,
				'external' => false,
			];
		}

		if ( current_user_can( 'install_plugins' ) ) {
			$url = add_query_arg(
				[
					'tab'       => 'plugin-information',
					'plugin'    => self::CUSTOMER_INTELLIGENCE_PLUGIN_SLUG,
					'TB_iframe' => 'true',
					'width'     => 600,
					'height'    => 550,
				],
				self_admin_url( 'plugin-install.php' )
			);

			return [
				'label'    => __( 'View details and install', 'wc-blacklist-manager' ),
				'url'      => $url,
				'external' => false,
				'class'    => 'thickbox open-plugin-details-modal',
				'title'    => __( 'Customer Intelligence plugin information', 'wc-blacklist-manager' ),
			];
		}

		return [
			'label'    => __( 'View plugin', 'wc-blacklist-manager' ),
			'url'      => self::CUSTOMER_INTELLIGENCE_PLUGIN_URL,
			'external' => true,
		];
	}

	private function customer_intelligence_notice_needs_plugin_information_modal() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'install_plugins' ) ) {
			return false;
		}

		if ( ! $this->customer_intelligence_notice_is_due() ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( 'yes' === get_user_meta( $user_id, $this->customer_intelligence_dismissed_meta_key(), true ) ) {
			return false;
		}

		if ( ! $this->site_supports_customer_intelligence() ) {
			return false;
		}

		$plugin_state = $this->get_customer_intelligence_plugin_state();

		return empty( $plugin_state['installed'] ) && empty( $plugin_state['active'] );
	}

	/**
	 * Admin notice when Global Blacklist Decisions monthly limit reached (HTTP 429).
	 */
	public function gbd_limit_notice() {
		$quota = class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' )
			? WC_Blacklist_Manager_Opportunity_Engine::get_global_quota_context()
			: array( 'eligible' => false );

		if (
			empty( $quota['transient_key'] ) ||
			empty( get_transient( $quota['transient_key'] ) ) ||
			'yes' === get_user_meta( get_current_user_id(), $quota['dismiss_key'], true )
		) {
			return;
		}

		$connected = class_exists( 'YOGB_BM_Report' ) && YOGB_BM_Report::is_ready();
		$plan      = class_exists( 'YOGB_BM_Tier_Webhook' ) ? YOGB_BM_Tier_Webhook::plan_summary() : array();
		$context   = WC_Blacklist_Manager_Commercial_Router::global_context(
			$connected,
			(string) ( $quota['tier'] ?? '' ),
			(string) ( $plan['status'] ?? '' ),
			(string) ( $plan['type'] ?? '' )
		);
		$action = WC_Blacklist_Manager_Commercial_Router::global_quota_action( $context );
		$paid_selected = ! class_exists( 'WC_Blacklist_Manager_Opportunity_Engine' )
			|| WC_Blacklist_Manager_Opportunity_Engine::is_selected( WC_Blacklist_Manager_Opportunity_Engine::GLOBAL_QUOTA_ID );

		$message = esc_html__( 'Your monthly Global Blacklist Decisions checks are used up. New orders will not be screened against the shared fraud network until the quota resets or your plan is upgraded.', 'wc-blacklist-manager' );
		$upgrade_button = $paid_selected
			? sprintf(
				'<a href="%1$s" class="button button-primary"%3$s>%2$s</a>',
				esc_url( $action['url'] ),
				esc_html( $action['label'] ),
				! empty( $action['external'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''
			)
			: '';

		printf(
			'<div class="notice notice-warning is-dismissible yobm-gbd-limit">
				<p><strong>%1$s</strong></p>
				<p>%2$s</p>
				<p>
					<a href="#" class="button button-secondary" onclick="WC_Blacklist_Manager_Admin_Notice.dismissGBDLimitNotice(); return false;">%3$s</a>
					%4$s
				</p>
			</div>',
			esc_html__( 'Global Blacklist Decisions monthly limit reached', 'wc-blacklist-manager' ),
			wp_kses_post( $message ),
			esc_html__( 'Dismiss', 'wc-blacklist-manager' ),
			$upgrade_button
		);
	}

	public static function show_download_premium_notice() {
		if ( ! self::current_user_can_recover_premium_addon() ) {
			return;
		}

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

		echo '<p><a href="' . esc_url( WC_Blacklist_Manager_Commercial_Router::account_url() ) . '" class="button-primary" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Go to My account', 'wc-blacklist-manager' )
			. '</a></p>';

		echo '</div>';
	}

	/**
	 * Whether the current user can perform the action required by this notice.
	 *
	 * An installed-but-inactive add-on requires plugin activation authority. A
	 * missing add-on requires plugin installation authority. Keep this separate
	 * from order-moderation and Blacklist Manager dashboard permissions.
	 */
	public static function current_user_can_recover_premium_addon() {
		$plugin_file = WP_PLUGIN_DIR . '/wc-blacklist-manager-premium/wc-blacklist-manager-premium.php';
		$capability  = is_file( $plugin_file ) ? 'activate_plugins' : 'install_plugins';

		return current_user_can( $capability );
	}

	public function enqueue_inline_scripts() {
		if ( $this->customer_intelligence_notice_needs_plugin_information_modal() ) {
			wp_enqueue_script( 'plugin-install' );
			if ( function_exists( 'add_thickbox' ) ) {
				add_thickbox();
			}
		}

		$nonce_first_time = wp_create_nonce('dismiss_first_time_notice_nonce');
		$nonce_gbd_limit = wp_create_nonce( 'dismiss_gbd_limit_notice_nonce' );
		$nonce_customer_intelligence = wp_create_nonce( 'dismiss_customer_intelligence_notice_nonce' );

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
				dismissCustomerIntelligenceNotice: function() {
					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'dismiss_customer_intelligence_notice',
							security: '{$nonce_customer_intelligence}'
						},
						success: function() {
							jQuery('.notice.yobm-ci-notice').hide();
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

				$(document).on('click', '.notice.yobm-ci-notice .notice-dismiss', function() {
					WC_Blacklist_Manager_Admin_Notice.dismissCustomerIntelligenceNotice();
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

		public function dismiss_customer_intelligence_notice() {
			check_ajax_referer( 'dismiss_customer_intelligence_notice_nonce', 'security' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-blacklist-manager' ) ), 403 );
			}

			update_user_meta( get_current_user_id(), $this->customer_intelligence_dismissed_meta_key(), 'yes' );
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
