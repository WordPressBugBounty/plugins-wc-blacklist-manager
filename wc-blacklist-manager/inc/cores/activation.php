<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'helper/yoohw-debug-blob.php';
require_once plugin_dir_path( __FILE__ ) . 'helper/yoohw-debug-log-email.php';
require_once plugin_dir_path( __FILE__ ) . 'helper/yoohw-http-debug.php';

if (!class_exists('WC_Blacklist_Manager_Validator')) {
	class WC_Blacklist_Manager_Validator {

		/** Endpoints: Origin (US) first, then Bridge */
		private $endpoints = [
			// UPDATED: v4 activation endpoint
			'us'     => 'https://yoohw.com/wp-json/yo_ohw/v4/activate_license/',
			// Bridge can stay as-is (if it proxies v4, it will return download_url; if not, activation still works)
			'bridge' => 'https://api2.yoohw.com/wp-json/yoohw-bridge/v1/activate_license_v4/',
		];

		/** Secrets */
		private $origin_api_key = 'yoOhw1Lf2DfrpXBcShC0AdUskKEivE5B1oNPQs8kmiiKG5wG40Dhgm79g7yj4yXJ'; // sent to origin
		private $bridge_key     = 'Zf9uE1x2P7qL0kD8mR6cV5wB3yH4tN9sA2jF7pQ1gU0oX6rM5vL'; // sent to bridge

		/** Circuit breaker transients */
		private $cb_flag_key   = 'yoohw_bmp_origin_cb_open';
		private $cb_fail_count = 'yoohw_bmp_origin_fail_count';

		/** Premium plugin main file */
		private $premium_plugin_file = 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php';

		public function __construct() {
			add_action( 'admin_init', [ $this, 'register_settings' ] );
		}

		public function register_settings() {
			register_setting(
				'wc_blacklist_manager_premium_options',
				'wc_blacklist_manager_premium_license_key',
				[ $this, 'validate_license_key' ]
			);
		}

		public function validate_license_key( $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$plugin_id    = '44';
			$yoohw_logger = (int) get_option( 'yoohw_settings_logger', 0 );

			// 1) Handle "Remove License" click
			if ( isset( $_POST['remove_license'] ) ) {
				$this->remove_license();
				return '';
			}

			// 2) Empty key
			if ( empty( $input ) ) {
				$this->add_settings_error_once(
					'wc_blacklist_manager_license_required',
					'License key is required.',
					'error'
				);
				return $input;
			}

			// 3) Call remote API
			$domain = parse_url( home_url(), PHP_URL_HOST );

			if ( $yoohw_logger ) {
				YoOhw_HTTP_Debug::begin( 'license_activation', $this->endpoints['us'], [
					'license_key' => $input,
					'domain'      => $domain,
					'product_id'  => $plugin_id,
				] );
			}

			$response = $this->send_api_request( $input, $domain, $plugin_id );

			if ( $yoohw_logger ) {
				YoOhw_HTTP_Debug::end( $response );
			}

			// 4) Network / WP_Error
			if ( is_wp_error( $response ) ) {
				if ( $yoohw_logger ) {
					YoOhw_Debug_Log_Email::sending( $response, $input, $domain, $plugin_id, YoOhw_HTTP_Debug::export() );
				}

				$this->add_settings_error_once(
					'wc_blacklist_manager_api_fail',
					sprintf( 'Could not reach license server: %s', $response->get_error_message() ),
					'error'
				);
				return $input;
			}

			// 5) HTTP‐status + JSON parsing
			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body );

			if ( 200 !== (int) $code || ! isset( $data->message ) ) {
				if ( $yoohw_logger ) {
					YoOhw_Debug_Log_Email::sending( $response, $input, $domain, $plugin_id, YoOhw_HTTP_Debug::export() );
				}
				$this->add_settings_error_once(
					'wc_blacklist_manager_api_fail',
					sprintf( 'Unexpected response from license server (HTTP %1$d): %2$s', (int) $code, wp_html_excerpt( $body, 100 ) ),
					'error'
				);
				return $input;
			}

			if ( isset($data->raw) && $data->message === 'OK' ) {
				$this->add_settings_error_once(
					'wc_blacklist_manager_api_fail',
					sprintf('Unexpected response from license server (HTTP %1$d): %2$s', (int)$code, wp_html_excerpt((string)$data->raw, 120)),
					'error'
				);
				return $input;
			}

			// 6) Handle success / fail
			if ( $data->message === 'License activated successfully.' ) {

				update_option( 'wc_blacklist_manager_premium_license_status', 'activated' );

				if ( ! empty( $data->expired ) ) {
					update_option( 'wc_blacklist_manager_premium_license_expired', sanitize_text_field( $data->expired ) );
				}

				// NEW: if premium plugin not installed/active, auto install/activate using v4 download_url
				$auto_result = $this->maybe_auto_install_activate_premium( $data );

				// Always show activation message
				$this->add_settings_error_once(
					'wc_blacklist_manager_license_activated',
					esc_html( $data->message ),
					'updated'
				);

				// Also show auto-install result if any (success or actionable error)
				if ( $auto_result && ! empty( $auto_result['message'] ) && ! empty( $auto_result['type'] ) ) {
					$this->add_settings_error_once(
						$auto_result['transient'],
						$auto_result['message'],
						$auto_result['type']
					);
				}

			} else {
				$this->add_settings_error_once(
					'wc_blacklist_manager_license_invalid',
					esc_html( $data->message ),
					'error'
				);
			}

			return $input;
		}

		private function remove_license() {
			delete_option( 'wc_blacklist_manager_premium_license_key' );
			delete_option( 'wc_blacklist_manager_premium_license_status' );
			delete_option( 'wc_blacklist_manager_premium_license_expired' );

			$this->add_settings_error_once(
				'wc_blacklist_manager_premium_license_key_removed',
				'License removed successfully.',
				'updated'
			);
		}

		/**
		 * Auto-install + activate premium plugin if needed.
		 * Expects v4 response fields: download_url OR download_unavailable.
		 */
		private function maybe_auto_install_activate_premium( $data ) {

			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			// Only admins should trigger install/activate operations
			if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
				return null; // silent: license activation should still succeed
			}

			$is_active    = is_plugin_active( $this->premium_plugin_file );
			$is_installed = $this->is_plugin_installed( $this->premium_plugin_file );

			if ( $is_active ) {
				return null; // nothing to do
			}

			// If installed but inactive => just activate
			if ( $is_installed && ! $is_active ) {
				$act = activate_plugin( $this->premium_plugin_file );
				if ( is_wp_error( $act ) ) {
					return [
						'transient' => 'yoohw_bmp_auto_activate_failed',
						'message'   => 'License activated, but could not activate Premium plugin: ' . $act->get_error_message(),
						'type'      => 'error',
					];
				}
				return [
					'transient' => 'yoohw_bmp_auto_activate_ok',
					'message'   => 'Premium plugin activated successfully.',
					'type'      => 'updated',
				];
			}

			// Not installed => need download_url
			$download_url = ( isset( $data->download_url ) && is_string( $data->download_url ) ) ? $data->download_url : '';

			if ( empty( $download_url ) ) {
				$reason = '';
				if ( isset( $data->download_reason ) && is_string( $data->download_reason ) ) {
					$reason = ' (' . $data->download_reason . ')';
				}
				return [
					'transient' => 'yoohw_bmp_download_missing',
					'message'   => 'License activated, but Premium auto-install is unavailable: missing download URL' . $reason . '.',
					'type'      => 'error',
				];
			}

			if ( ! $this->is_allowed_download_url( $download_url ) ) {
				return [
					'transient' => 'yoohw_bmp_download_blocked',
					'message'   => 'License activated, but Premium auto-install blocked: untrusted download URL host.',
					'type'      => 'error',
				];
			}

			$install = $this->install_plugin_from_url( $download_url );

			if ( is_wp_error( $install ) ) {
				return [
					'transient' => 'yoohw_bmp_install_failed',
					'message'   => 'License activated, but Premium install failed: ' . $install->get_error_message(),
					'type'      => 'error',
				];
			}

			// After install, activate
			$act = activate_plugin( $this->premium_plugin_file );
			if ( is_wp_error( $act ) ) {
				return [
					'transient' => 'yoohw_bmp_activate_failed',
					'message'   => 'Premium installed, but activation failed: ' . $act->get_error_message(),
					'type'      => 'error',
				];
			}

			return [
				'transient' => 'yoohw_bmp_install_activate_ok',
				'message'   => 'Premium plugin installed and activated successfully.',
				'type'      => 'updated',
			];
		}

		private function is_plugin_installed( $plugin_file ) {
			// Fast path
			if ( file_exists( WP_PLUGIN_DIR . '/' . ltrim( $plugin_file, '/' ) ) ) {
				return true;
			}

			// Full scan (covers uncommon setups)
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = get_plugins();
			return isset( $plugins[ $plugin_file ] );
		}

		private function install_plugin_from_url( $download_url ) {
			// WordPress upgrader stack
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			// Ensure FS credentials are not prompted
			$creds = request_filesystem_credentials( admin_url(), '', false, false, null );
			if ( false === $creds ) {
				return new WP_Error( 'yoohw_fs_creds', 'Filesystem credentials are required to install the plugin.' );
			}
			if ( ! WP_Filesystem( $creds ) ) {
				return new WP_Error( 'yoohw_fs_init', 'Could not initialize filesystem.' );
			}

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );

			// Install ZIP from URL
			$result = $upgrader->install( $download_url );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! $result ) {
				// Automatic_Upgrader_Skin stores errors in $skin->result sometimes, but keep it simple.
				return new WP_Error( 'yoohw_install_failed', 'Plugin installation failed (unknown error).' );
			}

			return true;
		}

		/**
		 * Origin-first with short timeout + circuit breaker + bridge failover.
		 */
		private function send_api_request( $license_key, $domain, $plugin_id ) {
			$payload = [
				'license_key' => $license_key,
				'domain'      => preg_replace( '/^www\./i', '', (string) $domain ),
				'product_id'  => $plugin_id,
			];

			$cb_open    = (bool) get_transient( $this->cb_flag_key );
			$fail_count = (int) get_transient( $this->cb_fail_count );

			// Helper: POST JSON
			$post_json = function( $url, array $headers, array $body, $timeout ) {
				return wp_remote_post( $url, [
					'headers' => array_merge( [
						'Content-Type' => 'application/json',
						'X-Plugin'     => 'blacklist-manager-premium',
					], $headers ),
					'body'    => wp_json_encode( $body ),
					'timeout' => $timeout,
				] );
			};

			// Helper: decide failover
			$should_failover = function( $resp ) {
				if ( is_wp_error( $resp ) ) return true;
				$code = (int) wp_remote_retrieve_response_code( $resp );
				if ( $code >= 500 ) return true;
				// Do NOT failover on 4xx — treat as definitive response.
				return false;
			};

			// If breaker is OPEN, skip origin and go straight to bridge
			if ( $cb_open ) {
				return $post_json(
					$this->endpoints['bridge'],
					[ 'X-BRIDGE-KEY' => $this->bridge_key ],
					$payload,
					25
				);
			}

			/* 1) Try ORIGIN (US) with short timeout (8s) */
			$origin_resp = $post_json(
				$this->endpoints['us'],
				[ 'X-API-KEY' => $this->origin_api_key ],
				$payload,
				8
			);

			if ( ! $should_failover( $origin_resp ) ) {
				delete_transient( $this->cb_fail_count );
				delete_transient( $this->cb_flag_key );
				return $origin_resp;
			}

			/* 2) Fall back to BRIDGE (25s timeout) */
			$bridge_resp = $post_json(
				$this->endpoints['bridge'],
				[ 'X-BRIDGE-KEY' => $this->bridge_key ],
				$payload,
				25
			);

			// Update breaker state based on ORIGIN result only
			if ( is_wp_error( $origin_resp ) || (int) wp_remote_retrieve_response_code( $origin_resp ) >= 500 ) {
				$fail_count++;
				set_transient( $this->cb_fail_count, $fail_count, 30 * MINUTE_IN_SECONDS );
				if ( $fail_count >= 2 ) {
					set_transient( $this->cb_flag_key, 1, 10 * MINUTE_IN_SECONDS );
				}
			} else {
				delete_transient( $this->cb_fail_count );
			}

			return $bridge_resp;
		}

		private function add_settings_error_once( $transient, $message, $type ) {
			if ( get_transient( $transient ) === false ) {
				set_transient( $transient, true, 10 );
				add_settings_error(
					'wc_blacklist_manager_premium_license_key',
					'wc_blacklist_manager_premium_license_key_error',
					$message,
					$type
				);
			}
		}

		private function is_allowed_download_url($url) {
			$u = wp_parse_url((string)$url);
			if (empty($u['scheme']) || strtolower($u['scheme']) !== 'https') return false;
			if (empty($u['host'])) return false;

			$host = strtolower($u['host']);

			// allow yoohw.com + subdomains, and api.bk.yoohw.com (bridge)
			if (preg_match('#(^|\.)yoohw\.com$#', $host)) return true;
			if ($host === 'api.bk.yoohw.com') return true;

			return false;
		}
	}
}

// Instantiate the class
new WC_Blacklist_Manager_Validator();
