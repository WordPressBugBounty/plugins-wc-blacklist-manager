<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Notices {

	public function __construct() {
		add_action('admin_notices', [$this, 'display_notices']);
		add_action('wp_ajax_dismiss_wc_blacklist_manager_notice', [$this, 'dismiss_notice']);
		add_action('wp_ajax_never_show_wc_blacklist_manager_notice', [$this, 'never_show_notice']);
		add_action('wp_ajax_dismiss_first_time_notice', [$this, 'dismiss_first_time_notice']);
		add_action('wp_ajax_dismiss_verification_notice', [$this, 'dismiss_verification_notice']);
	}
	
	public function display_notices() {
		$this->admin_notice();
		$this->first_time_notice();
		$this->verification_notice(); // New verification notice
	}

	public function admin_notice() {
		$user_id = get_current_user_id();
		$activation_time = get_user_meta($user_id, 'wc_blacklist_manager_activation_time', true);
		$current_time = current_time('timestamp');
	
		// If the user has opted to never see the notice again, don't show it.
		if (get_user_meta($user_id, 'wc_blacklist_manager_never_show_again', true) === 'yes') {
			return;
		}
	
		// If this is the first activation, set the activation time.
		if (!$activation_time) {
			update_user_meta($user_id, 'wc_blacklist_manager_activation_time', $current_time);
			return;
		}
	
		// Calculate how many days have passed since activation.
		$time_since_activation = $current_time - $activation_time;
		$days_since_activation = floor($time_since_activation / DAY_IN_SECONDS);
	
		// Show the notice if at least 1 day has passed and the notice has not been dismissed.
		if ($days_since_activation >= 1 && get_user_meta($user_id, 'wc_blacklist_manager_notice_dismissed', true) !== 'yes') {
			echo '<div class="notice notice-info is-dismissible">
					<p>Thank you for using WooCommerce Blacklist Manager! Please support us by <a href="https://wordpress.org/plugins/wc-blacklist-manager/#reviews" target="_blank">leaving a review</a> <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> to keep updating & improving.</p>
					<p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissForever()">Never show this again</a></p>
				  </div>';
			add_action('admin_footer', [$this, 'wc_blacklist_manager_admin_footer_scripts']);
		}
	}
	
	public function wc_blacklist_manager_admin_footer_scripts() {
		$nonce_dismiss = wp_create_nonce('dismiss_wc_blacklist_manager_notice_nonce');
		$nonce_never_show = wp_create_nonce('never_show_wc_blacklist_manager_notice_nonce');
		$nonce_first_time = wp_create_nonce('dismiss_first_time_notice_nonce');
		$nonce_verification = wp_create_nonce('dismiss_verification_notice_nonce'); // Nonce for verification notice
		?>
		<script type="text/javascript">
			var WC_Blacklist_Manager_Admin_Notice = {
				dismissForever() {
					jQuery.ajax({
						url: ajaxurl,
						type: "POST",
						data: {
							action: "never_show_wc_blacklist_manager_notice",
							security: "<?php echo esc_js($nonce_never_show); ?>" // Pass specific nonce for this action
						},
						success: function(response) {
							jQuery(".notice.notice-info").hide();
						}
					});
				},
				dismissFirstTimeNotice() {
					jQuery.ajax({
						url: ajaxurl,
						type: "POST",
						data: {
							action: "dismiss_first_time_notice",
							security: "<?php echo esc_js($nonce_first_time); ?>" // Pass specific nonce for this action
						},
						success: function(response) {
							jQuery(".notice.notice-info").hide();
						}
					});
				},
				dismissVerificationNotice() {
					jQuery.ajax({
						url: ajaxurl,
						type: "POST",
						data: {
							action: "dismiss_verification_notice",
							security: "<?php echo esc_js($nonce_verification); ?>" // Pass specific nonce for verification notice
						},
						success: function(response) {
							jQuery(".notice.notice-info").hide();
						}
					});
				}
			};
			jQuery(document).on("click", ".notice.is-dismissible", function(){
				jQuery.ajax({
					url: ajaxurl,
					type: "POST",
					data: {
						action: "dismiss_wc_blacklist_manager_notice",
						security: "<?php echo esc_js($nonce_dismiss); ?>" // Pass specific nonce for dismiss action
					}
				});
			});
		</script>
		<?php		
	}
	
	public function first_time_notice() {
		$user_id = get_current_user_id();
		$first_time_notice_dismissed = get_user_meta($user_id, 'wc_blacklist_manager_first_time_notice_dismissed', true);

		if ($first_time_notice_dismissed !== 'yes') {
			$activation_time = get_option('wc_blacklist_manager_activation_time', false);
			if (!$activation_time) {
				update_option('wc_blacklist_manager_activation_time', current_time('timestamp'));
				echo '<div class="notice notice-info is-dismissible">
					<p>Thank you for installing WooCommerce Blacklist Manager! Please <a href="' . esc_url(admin_url('admin.php?page=wc-blacklist-manager-settings')) . '">visit the Settings page</a> to configure the plugin.</p>
					<p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissFirstTimeNotice()">Dismiss</a></p>
				</div>';
				add_action('admin_footer', [$this, 'wc_blacklist_manager_admin_footer_scripts']);
			}
		}
	}
	
	// New verification notice
	public function verification_notice() {
		$user_id = get_current_user_id();
		$verification_notice_dismissed = get_user_meta($user_id, 'wc_blacklist_manager_verification_notice_dismissed', true);

		// Only show if the notice hasn't been dismissed
		if ($verification_notice_dismissed !== 'yes') {
			echo '<div class="notice notice-info is-dismissible">
					<p>Check out the new "Verifications" feature! Request the customer to verify email addresses and phone numbers during checkout. <a href="' . esc_url(admin_url('admin.php?page=wc-blacklist-manager-verifications')) . '">See here</a>.</p>
					<p><a href="#" onclick="WC_Blacklist_Manager_Admin_Notice.dismissVerificationNotice()">Dismiss</a></p>
				  </div>';
			add_action('admin_footer', [$this, 'wc_blacklist_manager_admin_footer_scripts']);
		}
	}

	public function dismiss_notice() {
		check_ajax_referer('dismiss_wc_blacklist_manager_notice_nonce', 'security');
		$user_id = get_current_user_id();
		update_user_meta($user_id, 'wc_blacklist_manager_notice_dismissed', 'yes');
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

	// New AJAX handler for dismissing verification notice
	public function dismiss_verification_notice() {
		check_ajax_referer('dismiss_verification_notice_nonce', 'security');
		$user_id = get_current_user_id();
		update_user_meta($user_id, 'wc_blacklist_manager_verification_notice_dismissed', 'yes');
	}
}

new WC_Blacklist_Manager_Notices();
