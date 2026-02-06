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
	private $default_comment_notice;
	private $default_blocked_user_notice;
	private $default_form_notice;
	private $default_vpn_proxy_form_notice;
	private $default_email_notice;
	private $default_phone_notice;

	private $default_access_blocked_ip_message;
	private $default_access_blocked_ip_country_message;
	private $default_access_blocked_browser_message;

	public function __construct() {
		add_action('init', [$this, 'set_default_strings']);
		add_action('admin_menu', [$this, 'add_notification_submenu']);
	}

	public function set_default_strings() {
		$this->default_email_subject = __('WARNING: Order #{order_id} from suspected customer!', 'wc-blacklist-manager');
		$this->default_email_message = __('A customer ({first_name} {last_name}) has placed order #{order_id}. Review it carefully.', 'wc-blacklist-manager');
		$this->default_checkout_notice = __('Sorry! You are no longer allowed to shop with us. If you think it is a mistake, please contact support.', 'wc-blacklist-manager');
		$this->default_vpn_proxy_checkout_notice = __('Checkout from Proxy, VPN or TOR is not allowed. Please disable your Proxy, VPN or TOR and try again.', 'wc-blacklist-manager');
		$this->default_payment_method_notice = __('Payment method you have chosen is not available, please select another and try again.', 'wc-blacklist-manager');
		$this->default_registration_notice = __('You have been blocked from registering. Think it is a mistake? Contact the administrator.', 'wc-blacklist-manager');
		$this->default_vpn_proxy_registration_notice = __('Registrations from Proxy, VPN or TOR are not allowed. Please disable your Proxy, VPN or TOR and try again.', 'wc-blacklist-manager');
		$this->default_comment_notice = __('Sorry! You are no longer allowed to submit a comment on our site. If you think it is a mistake, please contact support.', 'wc-blacklist-manager');
		$this->default_blocked_user_notice = __('Your account has been blocked. Think it is a mistake? Contact the administrator.', 'wc-blacklist-manager');
		$this->default_form_notice = __('Sorry! You are no longer allowed to submit a form. If you think it is a mistake, please contact support.', 'wc-blacklist-manager');
		$this->default_vpn_proxy_form_notice = __('Submission from Proxy, VPN or TOR is not allowed. Please disable your Proxy, VPN or TOR and try again.', 'wc-blacklist-manager');
		$this->default_email_notice = __('The email address you entered appears to be invalid or temporary. Please check it and enter a valid email address before trying again.', 'wc-blacklist-manager');
		$this->default_phone_notice = __('The phone number you entered appears to be invalid or temporary. Please check it and enter a valid number before trying again.', 'wc-blacklist-manager');

		$this->default_access_blocked_ip_message = __('Unfortunately, your access to our website has been restricted. This may be due to security policies, policy limitations, or unusual account activity. If you believe this restriction is an error, please contact our support team for further assistance.', 'wc-blacklist-manager');
		$this->default_access_blocked_ip_country_message = __('We’re sorry, but access to this website is not available from your current location. Regional restrictions or compliance requirements may prevent us from offering our services in certain areas. If you require further information or think this restriction may apply to you in error, please reach out to our support team.', 'wc-blacklist-manager');
		$this->default_access_blocked_browser_message = __('Access to this website is currently restricted for users of your browser. To ensure the highest level of security and performance, we only support certain browsers. Please use a supported browser or update your current version to regain access.', 'wc-blacklist-manager');
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
				[$this, 'notifications_page_content']
			);
		}
	}
	
	public function notifications_page_content() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'emails';
		?>
		<div class="wrap">
			<?php if (!$premium_active): ?>
				<p>Please support us by <a href="https://wordpress.org/support/plugin/wc-blacklist-manager/reviews/#new-post" target="_blank">leaving a review</a> <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> to keep updating & improving.</p>
			<?php endif; ?>

			<h1>
				<?php echo esc_html__('Notifications', 'wc-blacklist-manager'); ?>
				<?php if (get_option('yoohw_settings_disable_menu') != 1): ?>
					<a href="https://yoohw.com/docs/category/woocommerce-blacklist-manager/notifications/" target="_blank" class="button button-secondary" style="display: inline-flex; align-items: center;"><span class="dashicons dashicons-editor-help"></span> Documents</a>
				<?php endif; ?>
				<?php if (!$premium_active): ?>
					<a href="https://yoohw.com/contact-us/" target="_blank" class="button button-secondary"><?php esc_html_e('Support / Suggestion', 'wc-blacklist-manager'); ?></a>
				<?php endif; ?>
				<?php if ($premium_active && get_option('yoohw_settings_disable_menu') != 1): ?>
					<a href="https://yoohw.com/support/" target="_blank" class="button button-secondary">Premium support</a>
				<?php endif; ?>
			</h1>

			<h2 class="nav-tab-wrapper">
				<a href="?page=wc-blacklist-manager-notifications&tab=emails" class="nav-tab <?php echo $active_tab == 'emails' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Emails', 'wc-blacklist-manager'); ?></a>
				<a href="?page=wc-blacklist-manager-notifications&tab=notices" class="nav-tab <?php echo $active_tab == 'notices' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Notices', 'wc-blacklist-manager'); ?></a>
			</h2>

			<form method="post" enctype="multipart/form-data" action="">
				<?php
				wp_nonce_field('wc_blacklist_settings_action', 'wc_blacklist_settings_nonce');

				if ($active_tab == 'emails') {
					$this->render_notification_emails();
				} elseif ($active_tab == 'notices') {
					$this->render_notifications_notices();
				}
				?>
			</form>
		</div>
		<?php
	}

	public function render_notification_emails() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		$woocommerce_active = class_exists( 'WooCommerce' );
		$unlock_url = $woocommerce_active
			? 'https://yoohw.com/product/blacklist-manager-premium/'
			: 'https://yoohw.com/product/blacklist-manager-premium-for-forms/';

		$form_active = (class_exists( 'WPCF7' ) || class_exists( 'GFCommon' ) || class_exists( 'WPForms\WPForms' ));
		$message = $this->handle_emails_form_submission();
		$data = $this->get_notification_emails_settings();
		$data['message'] = $message;
		$template_path = plugin_dir_path(__FILE__) . 'views/notifications-emails.php';
		
		if (file_exists($template_path)) {
			include $template_path;
		} else {
			echo '<div class="error"><p>Failed to load the settings template.</p></div>';
		}
	}

	public function render_notifications_notices() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		$woocommerce_active = class_exists( 'WooCommerce' );
		$unlock_url = $woocommerce_active
			? 'https://yoohw.com/product/blacklist-manager-premium/'
			: 'https://yoohw.com/product/blacklist-manager-premium-for-forms/';
			
		$form_active = (class_exists( 'WPCF7' ) || class_exists( 'GFCommon' ) || class_exists( 'WPForms\WPForms' ));
		$message = $this->handle_notices_form_submission();
		$data = $this->get_notification_notices_settings();
		$data['message'] = $message;
		$template_path = plugin_dir_path(__FILE__) . 'views/notifications-notices.php';

		if (file_exists($template_path)) {
			include $template_path;
		} else {
			echo '<div class="error"><p>Failed to load the settings template.</p></div>';
		}
	}

	private function handle_emails_form_submission() {
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wc_blacklist_email_settings_nonce']) && wp_verify_nonce($_POST['wc_blacklist_email_settings_nonce'], 'wc_blacklist_email_settings_action')) {
			$this->save_emails_settings();
			return __('Changes saved.', 'wc-blacklist-manager');
		}
		return '';
	}

	private function handle_notices_form_submission() {
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wc_blacklist_email_settings_nonce']) && wp_verify_nonce($_POST['wc_blacklist_email_settings_nonce'], 'wc_blacklist_email_settings_action')) {
			$this->save_notices_settings();
			return __('Changes saved.', 'wc-blacklist-manager');
		}
		return '';
	}

	private function get_notification_emails_settings() {
		return [
			'sender_name' => get_option('wc_blacklist_sender_name', ''),
			'sender_address' => get_option('wc_blacklist_sender_address', ''),
			'email_recipient' => get_option('wc_blacklist_email_recipient', ''),
			'email_footer_text' => get_option('wc_blacklist_email_footer_text', 'This is an automated message. Please do not reply.<br>Blacklist Manager by <a href="https://yoohw.com">YoOhw Studio</a>'),
			'email_notification_enabled' => get_option('wc_blacklist_email_notification', 'no'),
			'email_subject' => get_option('wc_blacklist_email_subject', $this->default_email_subject),
			'email_message' => get_option('wc_blacklist_email_message', $this->default_email_message),
			'email_blocking_notification_enabled' => get_option('wc_blacklist_email_blocking_notification', 'no'),
			'email_register_suspect' => get_option('wc_blacklist_email_register_suspect', 'no'),
			'email_register_block' => get_option('wc_blacklist_email_register_block', 'no'),
			'email_comment_suspect' => get_option('wc_blacklist_email_comment_suspect', 'no'),
			'email_comment_block' => get_option('wc_blacklist_email_comment_block', 'no'),
			'email_form_suspect' => get_option('wc_blacklist_email_form_suspect', 'no'),
			'email_form_block' => get_option('wc_blacklist_email_form_block', 'no'),
		];
	}

	private function save_emails_settings() {
		$sender_name = isset($_POST['wc_blacklist_sender_name']) ? sanitize_text_field($_POST['wc_blacklist_sender_name']) : '';
		$sender_address = isset($_POST['wc_blacklist_sender_address']) ? sanitize_text_field($_POST['wc_blacklist_sender_address']) : '';
		$email_recipient = isset($_POST['wc_blacklist_email_recipient']) ? sanitize_text_field($_POST['wc_blacklist_email_recipient']) : '';
		$email_footer_text = isset($_POST['wc_blacklist_email_footer_text']) ? wp_kses_post($_POST['wc_blacklist_email_footer_text']) : 'This is an automated message. Please do not reply.<br>Blacklist Manager by <a href="https://yoohw.com">YoOhw Studio</a>';
		$email_notif_enabled = isset($_POST['wc_blacklist_email_notification']) ? 'yes' : 'no';
		$email_subject = isset($_POST['wc_blacklist_email_subject']) ? sanitize_text_field($_POST['wc_blacklist_email_subject']) : '';
		$email_message = isset($_POST['wc_blacklist_email_message']) ? wp_kses_post($_POST['wc_blacklist_email_message']) : '';
		$email_blocking_notif_enabled = isset($_POST['wc_blacklist_email_blocking_notification']) ? 'yes' : 'no';
		$email_register_suspect = isset($_POST['wc_blacklist_email_register_suspect']) ? 'yes' : 'no';
		$email_register_block = isset($_POST['wc_blacklist_email_register_block']) ? 'yes' : 'no';
		$email_comment_suspect = isset($_POST['wc_blacklist_email_comment_suspect']) ? 'yes' : 'no';
		$email_comment_block = isset($_POST['wc_blacklist_email_comment_block']) ? 'yes' : 'no';
		$email_form_suspect = isset($_POST['wc_blacklist_email_form_suspect']) ? 'yes' : 'no';
		$email_form_block = isset($_POST['wc_blacklist_email_form_block']) ? 'yes' : 'no';
		
		update_option('wc_blacklist_sender_name', $sender_name);
		update_option('wc_blacklist_sender_address', $sender_address);
		update_option('wc_blacklist_email_recipient', $email_recipient);
		update_option('wc_blacklist_email_footer_text', $email_footer_text);
		update_option('wc_blacklist_email_notification', $email_notif_enabled);
		update_option('wc_blacklist_email_subject', $email_subject);
		update_option('wc_blacklist_email_message', $email_message);
		update_option('wc_blacklist_email_blocking_notification', $email_blocking_notif_enabled);
		update_option('wc_blacklist_email_register_suspect', $email_register_suspect);
		update_option('wc_blacklist_email_register_block', $email_register_block);
		update_option('wc_blacklist_email_comment_suspect', $email_comment_suspect);
		update_option('wc_blacklist_email_comment_block', $email_comment_block);
		update_option('wc_blacklist_email_form_suspect', $email_form_suspect);
		update_option('wc_blacklist_email_form_block', $email_form_block);
	}

	private function get_notification_notices_settings() {
		return [
			'checkout_notice' => get_option('wc_blacklist_checkout_notice', $this->default_checkout_notice),
			'vpn_proxy_checkout_notice' => get_option('wc_blacklist_vpn_proxy_checkout_notice', $this->default_vpn_proxy_checkout_notice),
			'payment_method_notice' => get_option('wc_blacklist_payment_method_notice', $this->default_payment_method_notice),
			'registration_notice' => get_option('wc_blacklist_registration_notice', $this->default_registration_notice),
			'vpn_proxy_registration_notice' => get_option('wc_blacklist_vpn_proxy_registration_notice', $this->default_vpn_proxy_registration_notice),
			'comment_notice' => get_option('wc_blacklist_comment_notice', $this->default_comment_notice),
			'blocked_user_notice' => get_option('wc_blacklist_blocked_user_notice', $this->default_blocked_user_notice),
			'form_notice' => get_option('wc_blacklist_form_notice', $this->default_form_notice),
			'vpn_proxy_form_notice' => get_option('wc_blacklist_vpn_proxy_form_notice', $this->default_vpn_proxy_form_notice),
			'email_notice' => get_option('wc_blacklist_email_notice', $this->default_email_notice),
			'phone_notice' => get_option('wc_blacklist_phone_notice', $this->default_phone_notice),

			'access_blocked_ip' => get_option('wc_blacklist_access_blocked_ip', $this->default_access_blocked_ip_message),
			'access_blocked_ip_country' => get_option('wc_blacklist_access_blocked_ip_country', $this->default_access_blocked_ip_country_message),
			'access_blocked_browser' => get_option('wc_blacklist_access_blocked_browser', $this->default_access_blocked_browser_message)
		];
	}

	private function save_notices_settings() {		
		$checkout_notice = isset($_POST['wc_blacklist_checkout_notice']) && !empty($_POST['wc_blacklist_checkout_notice']) ? sanitize_text_field($_POST['wc_blacklist_checkout_notice']) : $this->default_checkout_notice;
		$vpn_proxy_checkout_notice = isset($_POST['wc_blacklist_vpn_proxy_checkout_notice']) && !empty($_POST['wc_blacklist_vpn_proxy_checkout_notice']) ? sanitize_text_field($_POST['wc_blacklist_vpn_proxy_checkout_notice']) : $this->default_vpn_proxy_checkout_notice;
		$payment_method_notice = isset($_POST['wc_blacklist_payment_method_notice']) && !empty($_POST['wc_blacklist_payment_method_notice']) ? sanitize_text_field($_POST['wc_blacklist_payment_method_notice']) : $this->default_payment_method_notice;
		$registration_notice = isset($_POST['wc_blacklist_registration_notice']) && !empty($_POST['wc_blacklist_registration_notice']) ? sanitize_text_field($_POST['wc_blacklist_registration_notice']) : $this->default_registration_notice;
		$vpn_proxy_registration_notice = isset($_POST['wc_blacklist_vpn_proxy_registration_notice']) && !empty($_POST['wc_blacklist_vpn_proxy_registration_notice']) ? sanitize_text_field($_POST['wc_blacklist_vpn_proxy_registration_notice']) : $this->default_vpn_proxy_registration_notice;
		$comment_notice = isset($_POST['wc_blacklist_comment_notice']) && !empty($_POST['wc_blacklist_comment_notice']) ? sanitize_text_field($_POST['wc_blacklist_comment_notice']) : $this->default_comment_notice;
		$blocked_user_notice = isset($_POST['wc_blacklist_blocked_user_notice']) && !empty($_POST['wc_blacklist_blocked_user_notice']) ? sanitize_text_field($_POST['wc_blacklist_blocked_user_notice']) : $this->default_blocked_user_notice;
		$form_notice = isset($_POST['wc_blacklist_form_notice']) && !empty($_POST['wc_blacklist_form_notice']) ? sanitize_text_field($_POST['wc_blacklist_form_notice']) : $this->default_form_notice;
		$vpn_proxy_form_notice = isset($_POST['wc_blacklist_vpn_proxy_form_notice']) && !empty($_POST['wc_blacklist_vpn_proxy_form_notice']) ? sanitize_text_field($_POST['wc_blacklist_vpn_proxy_form_notice']) : $this->default_vpn_proxy_form_notice;
		$email_notice = isset($_POST['wc_blacklist_email_notice']) && !empty($_POST['wc_blacklist_email_notice']) ? sanitize_text_field($_POST['wc_blacklist_email_notice']) : $this->default_email_notice;
		$phone_notice = isset($_POST['wc_blacklist_phone_notice']) && !empty($_POST['wc_blacklist_phone_notice']) ? sanitize_text_field($_POST['wc_blacklist_phone_notice']) : $this->default_phone_notice;

		$access_blocked_ip = isset($_POST['wc_blacklist_access_blocked_ip']) && !empty($_POST['wc_blacklist_access_blocked_ip']) ? sanitize_text_field($_POST['wc_blacklist_access_blocked_ip']) : $this->default_access_blocked_ip_message;
		$access_blocked_ip_country = isset($_POST['wc_blacklist_access_blocked_ip_country']) && !empty($_POST['wc_blacklist_access_blocked_ip_country']) ? sanitize_text_field($_POST['wc_blacklist_access_blocked_ip_country']) : $this->default_access_blocked_ip_country_message;
		$access_blocked_browser = isset($_POST['wc_blacklist_access_blocked_browser']) && !empty($_POST['wc_blacklist_access_blocked_browser']) ? sanitize_text_field($_POST['wc_blacklist_access_blocked_browser']) : $this->default_access_blocked_browser_message;
	
		update_option('wc_blacklist_checkout_notice', $checkout_notice);
		update_option('wc_blacklist_vpn_proxy_checkout_notice', $vpn_proxy_checkout_notice);
		update_option('wc_blacklist_payment_method_notice', $payment_method_notice);
		update_option('wc_blacklist_registration_notice', $registration_notice);
		update_option('wc_blacklist_vpn_proxy_registration_notice', $vpn_proxy_registration_notice);
		update_option('wc_blacklist_comment_notice', $comment_notice);
		update_option('wc_blacklist_blocked_user_notice', $blocked_user_notice);
		update_option('wc_blacklist_form_notice', $form_notice);
		update_option('wc_blacklist_vpn_proxy_form_notice', $vpn_proxy_form_notice);
		update_option('wc_blacklist_email_notice', $email_notice);
		update_option('wc_blacklist_phone_notice', $phone_notice);

		update_option('wc_blacklist_access_blocked_ip', $access_blocked_ip);
		update_option('wc_blacklist_access_blocked_ip_country', $access_blocked_ip_country);
		update_option('wc_blacklist_access_blocked_browser', $access_blocked_browser);
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
