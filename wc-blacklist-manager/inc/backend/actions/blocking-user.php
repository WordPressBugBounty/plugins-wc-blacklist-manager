<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_User_Blocking {
	private $authentication_denial_recorded = false;

	private function is_premium_active() {
		return function_exists( 'wc_blacklist_manager_is_premium_available' )
			&& wc_blacklist_manager_is_premium_available();
	}

	private function build_user_activity_view( $user_id, $action ) {
		$target_user = get_userdata( $user_id );
		$actor       = wp_get_current_user();
		$request_ip  = $this->get_request_ip();

		return array(
			'user_id'    => (int) $user_id,
			'ip_address' => $request_ip,
			'ip_hash'    => '' !== $request_ip ? $this->hash_value( $request_ip ) : '',
			'action'     => sanitize_key( (string) $action ),
			'user'       => array(
				'id'    => (int) $user_id,
				'login' => $target_user ? (string) $target_user->user_login : '',
				'email' => $target_user ? sanitize_email( $target_user->user_email ) : '',
				'roles' => $target_user ? array_values( (array) $target_user->roles ) : array(),
			),
			'actor'      => array(
				'id'           => $actor ? (int) $actor->ID : 0,
				'display_name' => $actor ? (string) $actor->display_name : '',
				'login'        => $actor ? (string) $actor->user_login : '',
			),
			'request'    => array(
				'ip'      => $request_ip,
				'ip_hash' => '' !== $request_ip ? $this->hash_value( $request_ip ) : '',
				'method'  => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				'uri'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			),
		);
	}

	private function get_request_ip() {
		if ( function_exists( 'get_real_customer_ip' ) ) {
			$ip = (string) get_real_customer_ip();
		} else {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		}

		return sanitize_text_field( $ip );
	}

	private function hash_value( $value ) {
		return hash_hmac( 'sha256', (string) $value, wp_salt( 'auth' ) );
	}

	public function __construct() {
		$yoaa_premium_active = in_array('wc-advanced-accounts-premium/wc-advanced-accounts-premium.php', apply_filters('active_plugins', get_option('active_plugins')));
		$license_status = (get_option('wc_advanced_accounts_premium_license_status') === 'activated');
		
		if ($yoaa_premium_active && $license_status) {
			return;
		}

		if (get_option('wc_blacklist_enable_user_blocking') !== '1') {
			return;
		}

		add_filter( 'authenticate', array( $this, 'deny_blocked_user_authentication' ), 100, 1 );
		add_action( 'wp_authenticate_application_password_errors', array( $this, 'deny_blocked_user_application_password' ), 100, 2 );
		add_action('init', [$this, 'check_and_force_logout_blocked_user']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_blocked_user_script']);
		add_action('edit_user_profile', [$this, 'show_user_blocked_status']);
		add_action('edit_user_profile_update', [$this, 'update_user_blocked_status']);
		add_action('admin_head', [$this, 'add_blocked_user_row_class']);
		add_action('wp_ajax_check_user_blocked_status', [$this, 'check_user_blocked_status']);
	}

	private function is_blocked_user( $user ) {
		return $user instanceof WP_User
			&& '1' === (string) get_user_meta( $user->ID, 'user_blocked', true );
	}

	private function blocked_user_error() {
		$message = get_option( 'wc_blacklist_blocked_user_notice', __( 'Your account has been blocked. Think it is a mistake? Contact the administrator.', 'wc-blacklist-manager' ) );

		return new WP_Error(
			'wc_blacklist_user_blocked',
			(string) $message,
			array( 'status' => 403 )
		);
	}

	private function record_authentication_denial( $user_id, $channel ) {
		if ( $this->authentication_denial_recorded ) {
			return;
		}

		$this->authentication_denial_recorded = true;
		$sum_block_total                      = get_option( 'wc_blacklist_sum_block_total', 0 );
		update_option( 'wc_blacklist_sum_block_total', $sum_block_total + 1 );

		$channels = array(
			'browser_password',
			'xmlrpc_password',
			'xmlrpc_application_password',
			'rest_application_password',
		);
		$channel  = is_string( $channel ) ? sanitize_key( $channel ) : '';

		if ( ! in_array( $channel, $channels, true ) ) {
			return;
		}

		$premium_consumer_active = defined( 'WC_BLACKLIST_MANAGER_PREMIUM_BLOCKED_AUTH_OBSERVABILITY_CONTRACT_VERSION' )
			&& 1 === (int) WC_BLACKLIST_MANAGER_PREMIUM_BLOCKED_AUTH_OBSERVABILITY_CONTRACT_VERSION;

		do_action(
			'wc_blacklist_manager_blocked_authentication_denied_v1',
			array(
				'user_id' => (int) $user_id,
				'channel' => $channel,
			)
		);

		if ( 'browser_password' !== $channel || $premium_consumer_active || ! $this->is_premium_active() ) {
			return;
		}

		global $wpdb;
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
		$view_json          = wp_json_encode( $this->build_user_activity_view( $user_id, 'blocked_login' ) );

		$wpdb->insert(
			$table_detection_log,
			array(
				'timestamp' => current_time( 'mysql' ),
				'type'      => 'bot',
				'source'    => 'login',
				'action'    => 'block',
				'details'   => 'blocked_user_attempt: ' . $user_id,
				'view'      => is_string( $view_json ) ? $view_json : '',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private function resolved_user_authentication_channel() {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc_password';
		}

		if ( $this->is_machine_request() || 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return '';
		}

		$script_name = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) : '';
		if ( isset( $_POST['log'], $_POST['pwd'] ) && 'wp-login.php' === basename( $script_name ) ) {
			return 'browser_password';
		}

		$woocommerce_nonce = isset( $_POST['woocommerce-login-nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['woocommerce-login-nonce'] ) )
			: '';
		if (
			isset( $_POST['username'], $_POST['password'], $_POST['login'] )
			&& '' !== $woocommerce_nonce
			&& function_exists( 'wp_verify_nonce' )
			&& wp_verify_nonce( $woocommerce_nonce, 'woocommerce-login' )
		) {
			return 'browser_password';
		}

		return '';
	}

	private function application_password_authentication_channel() {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc_application_password';
		}

		if ( $this->is_rest_request() ) {
			return 'rest_application_password';
		}

		return '';
	}

	public function deny_blocked_user_authentication( $user ) {
		if ( ! $this->is_blocked_user( $user ) ) {
			return $user;
		}

		$this->record_authentication_denial( $user->ID, $this->resolved_user_authentication_channel() );

		return $this->blocked_user_error();
	}

	public function deny_blocked_user_application_password( $error, $user ) {
		if ( ! $error instanceof WP_Error || ! $this->is_blocked_user( $user ) ) {
			return;
		}

		$this->record_authentication_denial( $user->ID, $this->application_password_authentication_channel() );
		$blocked_error = $this->blocked_user_error();
		$error->add(
			$blocked_error->get_error_code(),
			$blocked_error->get_error_message(),
			$blocked_error->get_error_data()
		);
	}

	private function is_rest_request() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( isset( $_GET['rest_route'] ) ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$rest_prefix  = function_exists( 'rest_get_url_prefix' ) ? trim( rest_get_url_prefix(), '/' ) : 'wp-json';

		return is_string( $request_path )
			&& '' !== $rest_prefix
			&& false !== strpos( trailingslashit( $request_path ), '/' . $rest_prefix . '/' );
	}

	private function is_machine_request() {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}

		return $this->is_rest_request();
	}

	public function check_and_force_logout_blocked_user() {
		if (is_user_logged_in()) {
			$user_id = get_current_user_id();
			$is_blocked = get_user_meta($user_id, 'user_blocked', true);

			if ($is_blocked == '1') {
				wp_logout();

				if ( $this->is_machine_request() ) {
					return;
				}

				$this->set_blocked_user_cookie();
				$this->set_user_blocked_notice();
				wp_redirect(wc_get_page_permalink('myaccount'));
				exit();
			}
		}
	}

	public function enqueue_blocked_user_script() {
		if (isset($_COOKIE['user_blocked']) && $_COOKIE['user_blocked'] == '1') {
			setcookie('user_blocked', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN); // Delete the cookie
			$this->set_user_blocked_notice();
		}
	}

	private function set_blocked_user_cookie() {
		setcookie('user_blocked', '1', time() + 3600, COOKIEPATH, COOKIE_DOMAIN);
	}

	private function set_user_blocked_notice() {
		$message = get_option('wc_blacklist_blocked_user_notice', __('Your account has been blocked. Think it is a mistake? Contact the administrator.', 'wc-blacklist-manager'));
		wc_add_notice($message, 'error');
	}

	public function show_user_blocked_status($user) {
		if (current_user_can('edit_user', $user->ID)) {
			$is_blocked = get_user_meta($user->ID, 'user_blocked', true);
			$premium_active = $this->is_premium_active();
			?>
			<h2><?php esc_html_e('Blocking management', 'wc-blacklist-manager'); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="user_blocked"><?php esc_html_e('User blocking', 'wc-blacklist-manager'); ?></label></th>
					<td>
						<?php if ($is_blocked == '1'): ?>
							<input
								type="submit"
								name="unblock_user"
								value="<?php esc_html_e('Unblock this user', 'wc-blacklist-manager'); ?>"
								class="button button-secondary"
								onclick="
								window.onbeforeunload = null;
								jQuery(window).off('beforeunload');
								"
							/>
						<?php else: ?>
							<?php if ($premium_active): ?>
								<input
									type="submit"
									name="block_user"
									value="<?php esc_html_e('Block this user', 'wc-blacklist-manager'); ?>"
									class="button red-button"
									onclick="
										window.onbeforeunload = null;
										jQuery(window).off('beforeunload');
									"
								/>
							<?php else: ?>
								<span><?php esc_html_e('No', 'wc-blacklist-manager'); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php
		}
	}

	public function update_user_blocked_status( $user_id ) {
		global $wpdb;

		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
		$table_blacklist     = $wpdb->prefix . 'wc_blacklist';

		$premium_active = $this->is_premium_active();

		if ( current_user_can( 'edit_user', $user_id ) ) {
			if ( isset( $_POST['block_user'] ) && ! $premium_active ) {
				wp_die(
					esc_html( function_exists( 'wc_blacklist_manager_premium_denied_message' ) ? wc_blacklist_manager_premium_denied_message() : __( 'A valid Blacklist Manager Premium license is required to use this feature.', 'wc-blacklist-manager' ) ),
					esc_html__( 'Premium license required', 'wc-blacklist-manager' ),
					array( 'response' => 403 )
				);
			}

			$user = get_userdata( $user_id );

			if ( $user && in_array( 'administrator', $user->roles, true ) ) {
				add_action(
					'admin_notices',
					function() use ( $user ) {
						echo '<div class="error notice"><p>' .
							sprintf(
								esc_html__( 'Cannot block the administrator %s.', 'wc-blacklist-manager' ),
								esc_html( $user->user_login )
							) .
						'</p></div>';
					}
				);
				return;
			}

			$current_user = wp_get_current_user();
			$shop_manager = $current_user->display_name;

			$action  = '';
			$details = '';

			if ( isset( $_POST['unblock_user'] ) ) {
				update_user_meta( $user_id, 'user_blocked', '0' );

				$action  = 'unblock';
				$details = 'by:' . $shop_manager;
			} elseif ( isset( $_POST['block_user'] ) ) {
				update_user_meta( $user_id, 'user_blocked', '1' );

				$action  = 'block';
				$details = 'by:' . $shop_manager;

				$user_email = '';
				if ( $user && ! empty( $user->user_email ) ) {
					$user_email = sanitize_email( $user->user_email );
				}

				if ( ! empty( $user_email ) && is_email( $user_email ) ) {
					$normalized_email = yobm_normalize_email( $user_email );

					$email_exists = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id
							FROM {$table_blacklist}
							WHERE email_address = %s
							OR ( %s <> '' AND normalized_email = %s )
							LIMIT 1",
							$user_email,
							$normalized_email,
							$normalized_email
						)
					);

					if ( empty( $email_exists ) ) {
						$insert_data = array(
							'email_address' => $user_email,
							'is_blocked'    => 1,
						);

						$insert_format = array(
							'%s',
							'%d',
						);

						// Only add normalized_email if your table supports it.
						$column_exists = $wpdb->get_var(
							$wpdb->prepare(
								"SHOW COLUMNS FROM {$table_blacklist} LIKE %s",
								'normalized_email'
							)
						);

						if ( $column_exists ) {
							$insert_data['normalized_email'] = $normalized_email;
							$insert_format[]                 = '%s';
						}

						$wpdb->insert(
							$table_blacklist,
							$insert_data,
							$insert_format
						);
					}
				}
			}

			if ( $premium_active && '' !== $action ) {
				$view_json = wp_json_encode( $this->build_user_activity_view( $user_id, $action ) );

				$wpdb->insert(
					$table_detection_log,
					array(
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'user_' . $user_id,
						'action'    => $action,
						'details'   => $details,
						'view'      => is_string( $view_json ) ? $view_json : '',
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}
		}
	}

	public function add_blocked_user_row_class() {
		global $pagenow;
		if ( 'users.php' !== $pagenow ) {
			return;
		}

		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}

		$nonce = wp_create_nonce( 'wc_blacklist_check_user_blocked_status' );
		?>
		<script>
			jQuery(document).ready(function($) {
				$('table.users tr').each(function() {
					var userID = $(this).find('input[name="users[]"]').val();
					if (userID) {
						$.ajax({
								url: ajaxurl,
								method: 'POST',
								data: {
									action: 'check_user_blocked_status',
									user_id: userID,
									nonce: '<?php echo esc_js( $nonce ); ?>'
								},
								success: function(response) {
									if (response === '1') {
									$('tr#user-' + userID).addClass('user-blocked-row');
								}
							}
						});
					}
				});
			});
		</script>
		<?php
	}

	public function check_user_blocked_status() {
		check_ajax_referer( 'wc_blacklist_check_user_blocked_status', 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;

		if ( ! $user_id || ! current_user_can( 'list_users' ) ) {
			wp_die( '0', 403 );
		}

		if (get_user_meta($user_id, 'user_blocked', true) == '1') {
			echo '1';
		} else {
			echo '0';
		}
		wp_die();
	}
}

// Instantiate the class
new WC_Blacklist_Manager_User_Blocking();
