<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_User_Blocking {
	public function __construct() {
		$yoaa_premium_active = in_array('wc-advanced-accounts-premium/wc-advanced-accounts-premium.php', apply_filters('active_plugins', get_option('active_plugins')));
		$license_status = (get_option('wc_advanced_accounts_premium_license_status') === 'activated');
		
		if ($yoaa_premium_active && $license_status) {
			return;
		}

		if (get_option('wc_blacklist_enable_user_blocking') !== '1') {
			return;
		}

		add_action('wp_login', [$this, 'force_logout_blocked_user'], 10, 2);
		add_action('init', [$this, 'check_and_force_logout_blocked_user']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_blocked_user_script']);
		add_action('edit_user_profile', [$this, 'show_user_blocked_status']);
		add_action('edit_user_profile_update', [$this, 'update_user_blocked_status']);
		add_action('admin_head', [$this, 'add_blocked_user_row_class']);
		add_action('wp_ajax_check_user_blocked_status', [$this, 'check_user_blocked_status']);
	}

	public function force_logout_blocked_user($user_login, $user) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		global $wpdb;
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
		
		$is_blocked = get_user_meta($user->ID, 'user_blocked', true);

		if ($is_blocked == '1') {
			wp_logout();
			$this->set_blocked_user_cookie();
			$this->set_user_blocked_notice();
			wp_redirect(wc_get_page_permalink('myaccount'));

			$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
			update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

			if ($premium_active) {
				$timestamp = current_time('mysql');
				$type      = 'bot';
				$source    = 'login';
				$action    = 'block';
				$details   = 'blocked_user_attempt: ' . $user->ID;
				
				$wpdb->insert(
					$table_detection_log,
					array(
						'timestamp' => $timestamp,
						'type'      => $type,
						'source'    => $source,
						'action'    => $action,
						'details'   => $details,
					)
				);
			}
			
			exit();
		}
	}

	public function check_and_force_logout_blocked_user() {
		if (is_user_logged_in()) {
			$user_id = get_current_user_id();
			$is_blocked = get_user_meta($user_id, 'user_blocked', true);

			if ($is_blocked == '1') {
				wp_logout();
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
			$settings_instance = new WC_Blacklist_Manager_Settings();
			$premium_active = $settings_instance->is_premium_active();
			?>
			<h3><?php _e('Blocking management', 'wc-blacklist-manager'); ?></h3>
			<table class="form-table">
				<tr>
					<th><label for="user_blocked"><?php _e('User blocking', 'wc-blacklist-manager'); ?></label></th>
					<td>
						<?php if ($is_blocked == '1'): ?>
							<input
								type="submit"
								name="unblock_user"
								value="<?php _e('Unblock this user', 'wc-blacklist-manager'); ?>"
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
									value="<?php _e('Block this user', 'wc-blacklist-manager'); ?>"
									class="button red-button"
									onclick="
										window.onbeforeunload = null;
										jQuery(window).off('beforeunload');
									"
								/>
							<?php else: ?>
								<span><?php _e('No', 'wc-blacklist-manager'); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php
		}
	}

	public function update_user_blocked_status($user_id) {
		global $wpdb;
		$table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if (current_user_can('edit_user', $user_id)) {
			$user = get_userdata($user_id);
			if ($user && in_array('administrator', $user->roles)) {
				add_action('admin_notices', function() use ($user) {
					echo '<div class="error notice"><p>' . sprintf(__('Cannot block the administrator %s.', 'wc-blacklist-manager'), esc_html($user->user_login)) . '</p></div>';
				});
				return;
			}

			$current_user = wp_get_current_user();
			$shop_manager = $current_user->display_name;
			
			$action = '';
			$details = '';
			if (isset($_POST['unblock_user'])) {
				update_user_meta($user_id, 'user_blocked', '0');

				$action = 'unblock';
				$details = 'by:' . $shop_manager;
			} elseif (isset($_POST['block_user'])) {
				update_user_meta($user_id, 'user_blocked', '1');

				$action = 'block';
				$details = 'by:' . $shop_manager;
			}

			if ( $premium_active ) {
				$view_json = '';

				$wpdb->insert(
					$table_detection_log,
					[
						'timestamp' => current_time( 'mysql' ),
						'type'      => 'human',
						'source'    => 'user_' . $user_id,
						'action'    => $action,
						'details'   => $details,
						'view'      => $view_json,
					],
					[ '%s', '%s', '%s', '%s', '%s', '%s' ]
				);
			}
		}
	}

	public function add_blocked_user_row_class() {
		global $pagenow;
		if ( 'users.php' !== $pagenow ) {
			return;
		}
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
								user_id: userID
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
		$user_id = intval($_POST['user_id']);
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
