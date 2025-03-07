<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Notifications {
	private $default_email_subject;
	private $default_email_message;
	private $default_checkout_notice;
	private $default_vpn_proxy_checkout_notice;
	private $default_payment_method_notice;
	private $default_registration_notice;
	private $default_vpn_proxy_registration_notice;
	private $default_blocked_user_notice;

	public function __construct() {
		$this->default_email_subject = __('WARNING: Order #{order_id} from suspected customer!', 'wc-blacklist-manager');
		$this->default_email_message = __('A customer ({first_name} {last_name}) has placed order #{order_id}. Review it carefully.', 'wc-blacklist-manager');
		$this->default_checkout_notice = __('Sorry! You are no longer allowed to shop with us. If you think it is a mistake, please contact support.', 'wc-blacklist-manager');
		$this->default_vpn_proxy_checkout_notice = __('Checkout from VPNs or Proxies is not allowed. Please disable your VPN or Proxy and try again.', 'wc-blacklist-manager');
		$this->default_payment_method_notice = __('Payment method you have chosen is not available, please select another and try again.', 'wc-blacklist-manager');
		$this->default_registration_notice = __('You have been blocked from registering. Think it is a mistake? Contact the administrator.', 'wc-blacklist-manager');
		$this->default_vpn_proxy_registration_notice = __('Registrations from VPNs or Proxies are not allowed. Please disable your VPN or Proxy and try again.', 'wc-blacklist-manager');
		$this->default_blocked_user_notice = __('Your account has been blocked. Think it is a mistake? Contact the administrator.', 'wc-blacklist-manager');		
		add_action('admin_menu', [$this, 'add_notification_submenu']);
	}

	public function add_notification_submenu() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		
		$user_has_permission = false;
			if ($premium_active) {
			$allowed_roles = get_option('wc_blacklist_notifications_permission', []);
			if (is_array($allowed_roles) && !empty($allowed_roles)) {
				foreach ($allowed_roles as $role) {
					if (current_user_can($role)) {
						$user_has_permission = true;
						break;
					}
				}
			}
		}
	
		if (($premium_active && $user_has_permission) || current_user_can('manage_options')) {
			add_submenu_page(
				'wc-blacklist-manager',
				__('Notifications', 'wc-blacklist-manager'),
				__('Notifications', 'wc-blacklist-manager'),
				'read',
				'wc-blacklist-manager-notifications',
				[$this, 'render_notification_settings']
			);
		}
	}	

	public function render_notification_settings() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		$message = $this->handle_form_submission();
		$data = $this->get_notification_settings();
		$data['message'] = $message;
		$template_path = plugin_dir_path(__FILE__) . 'views/yobm-wc-blacklist-manager-notifications-form.php';
		
		if (file_exists($template_path)) {
			include $template_path;
		} else {
			echo '<div class="error"><p>Failed to load the settings template.</p></div>';
		}
	}

	private function handle_form_submission() {
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wc_blacklist_email_settings_nonce']) && wp_verify_nonce($_POST['wc_blacklist_email_settings_nonce'], 'wc_blacklist_email_settings_action')) {
			$this->save_settings();
			return __('Changes saved.', 'wc-blacklist-manager');
		}
		return '';
	}

	private function get_notification_settings() {
		return [
			'email_notification_enabled' => get_option('wc_blacklist_email_notification', 'no'),
			'email_subject' => get_option('wc_blacklist_email_subject', $this->default_email_subject),
			'email_message' => get_option('wc_blacklist_email_message', $this->default_email_message),
			'additional_emails' => get_option('wc_blacklist_additional_emails', ''),
			'email_blocking_notification_enabled' => get_option('wc_blacklist_email_blocking_notification', 'no'),
			'checkout_notice' => get_option('wc_blacklist_checkout_notice', $this->default_checkout_notice),
			'vpn_proxy_checkout_notice' => get_option('wc_blacklist_vpn_proxy_checkout_notice', $this->default_vpn_proxy_checkout_notice),
			'payment_method_notice' => get_option('wc_blacklist_payment_method_notice', $this->default_payment_method_notice),
			'registration_notice' => get_option('wc_blacklist_registration_notice', $this->default_registration_notice),
			'vpn_proxy_registration_notice' => get_option('wc_blacklist_vpn_proxy_registration_notice', $this->default_vpn_proxy_registration_notice),
			'blocked_user_notice' => get_option('wc_blacklist_blocked_user_notice', $this->default_blocked_user_notice)
		];
	}

	private function save_settings() {
		$email_notif_enabled = isset($_POST['wc_blacklist_email_notification']) ? 'yes' : 'no';
		$email_subject = isset($_POST['wc_blacklist_email_subject']) ? sanitize_text_field($_POST['wc_blacklist_email_subject']) : '';
		$email_message = isset($_POST['wc_blacklist_email_message']) ? wp_kses_post($_POST['wc_blacklist_email_message']) : '';
		$additional_emails = isset($_POST['wc_blacklist_additional_emails']) ? sanitize_text_field($_POST['wc_blacklist_additional_emails']) : '';

		$email_blocking_notif_enabled = isset($_POST['wc_blacklist_email_blocking_notification']) ? 'yes' : 'no';
		
		$checkout_notice = isset($_POST['wc_blacklist_checkout_notice']) && !empty($_POST['wc_blacklist_checkout_notice']) ? sanitize_text_field($_POST['wc_blacklist_checkout_notice']) : $this->default_checkout_notice;
		$vpn_proxy_checkout_notice = isset($_POST['wc_blacklist_vpn_proxy_checkout_notice']) && !empty($_POST['wc_blacklist_vpn_proxy_checkout_notice']) ? sanitize_text_field($_POST['wc_blacklist_vpn_proxy_checkout_notice']) : $this->default_vpn_proxy_checkout_notice;
		$payment_method_notice = isset($_POST['wc_blacklist_payment_method_notice']) && !empty($_POST['wc_blacklist_payment_method_notice']) ? sanitize_text_field($_POST['wc_blacklist_payment_method_notice']) : $this->default_payment_method_notice;
		$registration_notice = isset($_POST['wc_blacklist_registration_notice']) && !empty($_POST['wc_blacklist_registration_notice']) ? sanitize_text_field($_POST['wc_blacklist_registration_notice']) : $this->default_registration_notice;
		$vpn_proxy_registration_notice = isset($_POST['wc_blacklist_vpn_proxy_registration_notice']) && !empty($_POST['wc_blacklist_vpn_proxy_registration_notice']) ? sanitize_text_field($_POST['wc_blacklist_vpn_proxy_registration_notice']) : $this->default_vpn_proxy_registration_notice;
		$blocked_user_notice = isset($_POST['wc_blacklist_blocked_user_notice']) && !empty($_POST['wc_blacklist_blocked_user_notice']) ? sanitize_text_field($_POST['wc_blacklist_blocked_user_notice']) : $this->default_blocked_user_notice;
	
		update_option('wc_blacklist_email_notification', $email_notif_enabled);
		update_option('wc_blacklist_email_subject', $email_subject);
		update_option('wc_blacklist_email_message', $email_message);
		update_option('wc_blacklist_additional_emails', $additional_emails);
		update_option('wc_blacklist_email_blocking_notification', $email_blocking_notif_enabled);
		update_option('wc_blacklist_checkout_notice', $checkout_notice);
		update_option('wc_blacklist_vpn_proxy_checkout_notice', $vpn_proxy_checkout_notice);
		update_option('wc_blacklist_payment_method_notice', $payment_method_notice);
		update_option('wc_blacklist_registration_notice', $registration_notice);
		update_option('wc_blacklist_vpn_proxy_registration_notice', $vpn_proxy_registration_notice);
		update_option('wc_blacklist_blocked_user_notice', $blocked_user_notice);
	}
}

new WC_Blacklist_Manager_Notifications();

trait Blacklist_Notice_Trait {
	protected function add_checkout_notice() {
		$checkout_notice = get_option('wc_blacklist_checkout_notice', __('Sorry! You are no longer allowed to shop with us. If you think it is a mistake, please contact support.', 'wc-blacklist-manager'));
		
		if (!$this->is_notice_added($checkout_notice)) {
			wc_add_notice($checkout_notice, 'error');
		}
	}

	private function is_notice_added($notice_text) {
		$notices = wc_get_notices('error');
		foreach ($notices as $notice) {
			if ($notice['notice'] === $notice_text) {
				return true;
			}
		}
		return false;
	}
}
