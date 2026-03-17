<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'helper/yoohw-debug-blob.php';
require_once plugin_dir_path( __FILE__ ) . 'helper/yoohw-debug-log-email.php';
require_once plugin_dir_path( __FILE__ ) . 'helper/yoohw-http-debug.php';

if ( ! class_exists( 'WC_Blacklist_Manager_Validator' ) ) {
	class WC_Blacklist_Manager_Validator {

		/** Endpoints: Origin (US) first, then Bridge */
		private $endpoints = [
			'us'     => 'https://yoohw.com/wp-json/yo_ohw/v4/activate_license/',
			'bridge' => 'https://api2.yoohw.com/wp-json/yoohw-bridge/v1/activate_license_v4/',
		];

		/** Secrets */
		private $origin_api_key = 'yoOhw1Lf2DfrpXBcShC0AdUskKEivE5B1oNPQs8kmiiKG5wG40Dhgm79g7yj4yXJ';
		private $bridge_key     = 'Zf9uE1x2P7qL0kD8mR6cV5wB3yH4tN9sA2jF7pQ1gU0oX6rM5vL';

		/** Circuit breaker transients */
		private $cb_flag_key   = 'yoohw_bmp_origin_cb_open';
		private $cb_fail_count = 'yoohw_bmp_origin_fail_count';

		/** Premium plugin main file */
		private $premium_plugin_file = 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php';

		public function __construct() {
			add_action( 'admin_init', [ $this, 'register_settings' ] );

			YoOhw_License_Runtime::maybe_migrate_legacy_state(
				[
					'license_key_option' => 'wc_blacklist_manager_premium_license_key',
					'status_option'      => 'wc_blacklist_manager_premium_license_status',
					'state_option'       => 'wc_blacklist_manager_premium_license_state',
					'product_id'         => '44',
				]
			);
		}

		public function register_settings() {
			register_setting(
				'wc_blacklist_manager_premium_options',
				'wc_blacklist_manager_premium_license_key',
				[ $this, 'validate_license_key' ]
			);
		}

		public static function is_premium_active() {
			return YoOhw_License_Runtime::is_active(
				[
					'license_key_option' => 'wc_blacklist_manager_premium_license_key',
					'status_option'      => 'wc_blacklist_manager_premium_license_status',
					'state_option'       => 'wc_blacklist_manager_premium_license_state',
					'product_id'         => '44',
				]
			);
		}

		public function validate_license_key( $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$plugin_id    = '44';
			$yoohw_logger = (int) get_option( 'yoohw_settings_logger', 0 );
			$input        = is_string( $input ) ? trim( $input ) : '';

			// Remove license.
			if ( isset( $_POST['remove_license'] ) ) {
				$this->remove_license();
				return '';
			}

			if ( '' === $input ) {
				$this->add_settings_error_once(
					'wc_blacklist_manager_license_required',
					'License key is required.',
					'error'
				);
				return $input;
			}

			$domain = YoOhw_License_Runtime::normalize_domain( home_url() );

			if ( $yoohw_logger ) {
				YoOhw_HTTP_Debug::begin(
					'license_activation',
					$this->endpoints['us'],
					[
						'license_key' => $input,
						'domain'      => $domain,
						'product_id'  => $plugin_id,
					]
				);
			}

			$response = $this->send_api_request( $input, $domain, $plugin_id );

			if ( $yoohw_logger ) {
				YoOhw_HTTP_Debug::end( $response );
			}

			if ( is_wp_error( $response ) ) {
				if ( $yoohw_logger ) {
					YoOhw_Debug_Log_Email::sending( $response, $input, $domain, $plugin_id, YoOhw_HTTP_Debug::export() );
				}

				YoOhw_License_Runtime::mark_grace(
					[
						'state_option'  => 'wc_blacklist_manager_premium_license_state',
						'status_option' => 'wc_blacklist_manager_premium_license_status',
						'product_id'    => '44',
						'error_code'    => 'activation_transport_error',
						'server_code'   => '',
					]
				);

				$this->add_settings_error_once(
					'wc_blacklist_manager_api_fail',
					sprintf( 'Could not reach license server: %s', $response->get_error_message() ),
					'error'
				);
				return $input;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			$data = json_decode( $body );

			if ( 200 !== $code || ! isset( $data->message ) ) {
				if ( $yoohw_logger ) {
					YoOhw_Debug_Log_Email::sending( $response, $input, $domain, $plugin_id, YoOhw_HTTP_Debug::export() );
				}

				YoOhw_License_Runtime::mark_grace(
					[
						'state_option'  => 'wc_blacklist_manager_premium_license_state',
						'status_option' => 'wc_blacklist_manager_premium_license_status',
						'product_id'    => '44',
						'error_code'    => 'activation_unexpected_response',
						'server_code'   => '',
					]
				);

				$this->add_settings_error_once(
					'wc_blacklist_manager_api_fail',
					sprintf( 'Unexpected response from license server (HTTP %1$d): %2$s', $code, wp_html_excerpt( $body, 100 ) ),
					'error'
				);
				return $input;
			}

			if ( isset( $data->raw ) && 'OK' === $data->message ) {
				YoOhw_License_Runtime::mark_grace(
					[
						'state_option'  => 'wc_blacklist_manager_premium_license_state',
						'status_option' => 'wc_blacklist_manager_premium_license_status',
						'product_id'    => '44',
						'error_code'    => 'activation_raw_response',
						'server_code'   => '',
					]
				);

				$this->add_settings_error_once(
					'wc_blacklist_manager_api_fail',
					sprintf( 'Unexpected response from license server (HTTP %1$d): %2$s', $code, wp_html_excerpt( (string) $data->raw, 120 ) ),
					'error'
				);
				return $input;
			}

			if ( 'License activated successfully.' === $data->message ) {
				$expires_at = 0;

				if ( ! empty( $data->expired ) ) {
					$expired_value = sanitize_text_field( $data->expired );
					update_option( 'wc_blacklist_manager_premium_license_expired', $expired_value );

					$parsed_expires_at = strtotime( $expired_value . ' UTC' );
					if ( false !== $parsed_expires_at ) {
						$expires_at = (int) $parsed_expires_at;
					}
				}

				YoOhw_License_Runtime::mark_activated(
					[
						'state_option'  => 'wc_blacklist_manager_premium_license_state',
						'status_option' => 'wc_blacklist_manager_premium_license_status',
						'product_id'    => '44',
						'domain'        => $domain,
						'expires_at'    => $expires_at,
						'server_code'   => 'valid',
					]
				);

				$auto_result = $this->maybe_auto_install_activate_premium( $data );

				$this->add_settings_error_once(
					'wc_blacklist_manager_license_activated',
					esc_html( $data->message ),
					'updated'
				);

				if ( $auto_result && ! empty( $auto_result['message'] ) && ! empty( $auto_result['type'] ) ) {
					$this->add_settings_error_once(
						$auto_result['transient'],
						$auto_result['message'],
						$auto_result['type']
					);
				}
			} else {
				$server_code = isset( $data->code ) ? sanitize_text_field( (string) $data->code ) : 'activation_rejected';
				$new_status  = 'invalid';

				if ( 'license_expired' === $server_code ) {
					$new_status = 'expired';
				}

				YoOhw_License_Runtime::mark_inactive(
					[
						'state_option'  => 'wc_blacklist_manager_premium_license_state',
						'status_option' => 'wc_blacklist_manager_premium_license_status',
						'product_id'    => '44',
						'status'        => $new_status,
						'error_code'    => 'activation_rejected',
						'server_code'   => $server_code,
					]
				);

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
			delete_option( 'wc_blacklist_manager_premium_license_expired' );

			YoOhw_License_Runtime::mark_inactive(
				[
					'state_option'  => 'wc_blacklist_manager_premium_license_state',
					'status_option' => 'wc_blacklist_manager_premium_license_status',
					'product_id'    => '44',
					'status'        => 'deactivated',
					'error_code'    => 'removed_by_user',
					'server_code'   => 'removed_by_user',
				]
			);

			$this->add_settings_error_once(
				'wc_blacklist_manager_premium_license_key_removed',
				'License removed successfully.',
				'updated'
			);
		}

		private function maybe_auto_install_activate_premium( $data ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
				return null;
			}

			$is_active    = is_plugin_active( $this->premium_plugin_file );
			$is_installed = $this->is_plugin_installed( $this->premium_plugin_file );

			if ( $is_active ) {
				return null;
			}

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

		private function send_api_request( $license_key, $domain, $plugin_id ) {
			$payload = [
				'license_key' => $license_key,
				'domain'      => $domain,
				'product_id'  => $plugin_id,
			];

			$post_json = function( $url, array $headers, array $body, $timeout ) {
				return wp_remote_post(
					$url,
					[
						'headers' => array_merge(
							[
								'Content-Type' => 'application/json',
								'Accept'       => 'application/json',
								'X-Plugin'     => 'blacklist-manager-premium',
							],
							$headers
						),
						'body'    => wp_json_encode( $body ),
						'timeout' => $timeout,
					]
				);
			};

			$should_failover = function( $resp ) {
				if ( is_wp_error( $resp ) ) {
					return true;
				}

				$code = (int) wp_remote_retrieve_response_code( $resp );
				return ( $code >= 500 );
			};

			$cb_open    = (bool) get_transient( $this->cb_flag_key );
			$fail_count = (int) get_transient( $this->cb_fail_count );

			if ( $cb_open ) {
				return $post_json(
					$this->endpoints['bridge'],
					[ 'X-BRIDGE-KEY' => $this->bridge_key ],
					$payload,
					20
				);
			}

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

			$bridge_resp = $post_json(
				$this->endpoints['bridge'],
				[ 'X-BRIDGE-KEY' => $this->bridge_key ],
				$payload,
				20
			);

			if ( is_wp_error( $origin_resp ) || (int) wp_remote_retrieve_response_code( $origin_resp ) >= 500 ) {
				++$fail_count;
				set_transient( $this->cb_fail_count, $fail_count, 30 * MINUTE_IN_SECONDS );

				if ( $fail_count >= 2 ) {
					set_transient( $this->cb_flag_key, 1, 10 * MINUTE_IN_SECONDS );
				}
			} else {
				delete_transient( $this->cb_fail_count );
			}

			return $bridge_resp;
		}

		private function add_settings_error_once( $code, $message, $type = 'error' ) {
			settings_errors( 'wc_blacklist_manager_premium_license_key' );

			add_settings_error(
				'wc_blacklist_manager_premium_license_key',
				$code,
				$message,
				$type
			);
		}

		private function is_plugin_installed( $plugin_file ) {
			$plugin_path = WP_PLUGIN_DIR . '/' . ltrim( $plugin_file, '/' );
			return file_exists( $plugin_path );
		}

		private function is_allowed_download_url( $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! is_string( $host ) || '' === $host ) {
				return false;
			}

			$host = strtolower( $host );

			$allowed_hosts = [
				'yoohw.com',
				'www.yoohw.com',
				'api2.yoohw.com',
				'dl.dropboxusercontent.com',
				'www.dropbox.com',
				'dropbox.com',
			];

			return in_array( $host, $allowed_hosts, true );
		}

		private function install_plugin_from_url( $download_url ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

			$temp_file = download_url( $download_url );

			if ( is_wp_error( $temp_file ) ) {
				return $temp_file;
			}

			$upgrader = new Plugin_Upgrader(
				new Automatic_Upgrader_Skin(
					[
						'title'  => 'Installing Plugin',
						'url'    => admin_url( 'admin.php?page=yoohw-license-manager' ),
						'nonce'  => 'install-plugin_' . sanitize_key( basename( $download_url ) ),
						'plugin' => '',
						'api'    => null,
					]
				)
			);

			$result = $upgrader->install( $download_url );

			if ( is_wp_error( $result ) ) {
				@unlink( $temp_file );
				return $result;
			}

			if ( ! $result ) {
				@unlink( $temp_file );
				return new WP_Error( 'plugin_install_failed', 'Plugin installation failed.' );
			}

			@unlink( $temp_file );

			return true;
		}
	}

	new WC_Blacklist_Manager_Validator();
}