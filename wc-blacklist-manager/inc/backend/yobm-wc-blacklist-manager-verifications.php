<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Verifications {
	private $verfications_advanced;

	private $default_email_subject;
	private $default_email_heading;
	private $default_email_message;
	private $default_sms_message;

	public function __construct() {
		add_action('init', [$this, 'set_verifications_strings']);
		add_action('admin_menu', [$this, 'add_verifications_submenu']);
		add_action('wp_ajax_generate_sms_key', [$this, 'handle_generate_sms_key']);
		add_action('admin_post_refresh_merging', [$this, 'wc_blacklist_refresh_merging']);

		$this->includes();
	}

	public function set_verifications_strings() {
		$this->default_email_subject = __('Verify your email address', 'wc-blacklist-manager');
		$this->default_email_heading = __('Verify your email address', 'wc-blacklist-manager');
		$this->default_email_message = __('Hi there,<br><br>To complete your checkout process, please verify your email address by entering the following code:<br><br><strong>{code}</strong><br><br>If you did not request this, please ignore this email.<br><br>Thank you.', 'wc-blacklist-manager');
		$this->default_sms_message = __('{site_name}: Your verification code is {code}', 'wc-blacklist-manager');
	}

	public function add_verifications_submenu() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		$user_has_permission = false;
		if ($premium_active) {
			$allowed_roles = get_option('wc_blacklist_settings_permission', []);
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
				__('Verifications', 'wc-blacklist-manager'),
				__('Verifications', 'wc-blacklist-manager'),
				'read',
				'wc-blacklist-manager-verifications',
				[$this, 'verifications_page_content']
			);
		}
	}

	public function verifications_page_content() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'verify';
		?>
		<div class="wrap">
			<?php if (!$premium_active): ?>
				<p><?php esc_html_e('Please support us by', 'wc-blacklist-manager'); ?> <a href="https://wordpress.org/support/plugin/wc-blacklist-manager/reviews/#new-post" target="_blank"><?php esc_html_e('leaving a review', 'wc-blacklist-manager'); ?></a> <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> <?php esc_html_e('to keep updating & improving.', 'wc-blacklist-manager'); ?></p>
			<?php endif; ?>

			<h1>
				<?php echo esc_html__('Verifications', 'wc-blacklist-manager'); ?>
				<?php if (get_option('yoohw_settings_disable_menu') != 1): ?>
					<a href="https://yoohw.com/docs/category/woocommerce-blacklist-manager/verifications/" target="_blank" class="button button-secondary" style="display: inline-flex; align-items: center;"><span class="dashicons dashicons-editor-help"></span> Documents</a>
				<?php endif; ?>
				<?php if (!$premium_active): ?>
					<a href="https://yoohw.com/contact-us/" target="_blank" class="button button-secondary"><?php esc_html_e('Support / Suggestion', 'wc-blacklist-manager'); ?></a>
				<?php endif; ?>
				<?php if ($premium_active && get_option('yoohw_settings_disable_menu') != 1): ?>
					<a href="https://yoohw.com/support/" target="_blank" class="button button-secondary">Premium support</a>
				<?php endif; ?>
			</h1>

			<h2 class="nav-tab-wrapper">
				<a href="?page=wc-blacklist-manager-verifications&tab=verify" class="nav-tab <?php echo $active_tab == 'verify' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Verify', 'wc-blacklist-manager-premium'); ?></a>
				<a href="?page=wc-blacklist-manager-verifications&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Advanced', 'wc-blacklist-manager-premium'); ?></a>
			</h2>

			<form method="post" enctype="multipart/form-data" action="">
				<?php
				wp_nonce_field('wc_blacklist_settings_action', 'wc_blacklist_settings_nonce');

				if ($active_tab == 'verify') {
					$this->render_verifications_settings();
				} elseif ($active_tab == 'advanced') {
					$this->render_verifications_advanced();
				}
				?>
			</form>
		</div>
		<?php
	}

	public function render_verifications_settings() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		$allowed_countries_option = get_option('woocommerce_allowed_countries', 'all');
		$specific_countries = get_option('woocommerce_specific_allowed_countries', []);
		$skip_country_code = ($allowed_countries_option === 'specific' && count($specific_countries) === 1);
		$message = $this->handle_form_submission();
		$data = $this->get_verifications_settings();
		$data['message'] = $message;
		$template_path = plugin_dir_path(__FILE__) . 'views/yobm-wc-blacklist-manager-verifications-form.php';

		if (file_exists($template_path)) {
			include $template_path;
		} else {
			echo '<div class="error"><p>Failed to load the settings template.</p></div>';
		}
	}

	public function render_verifications_advanced() {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
		$template_path = plugin_dir_path(__FILE__) . 'views/yobm-wc-blacklist-manager-verifications-advanced.php';

		if (file_exists($template_path)) {
			include $template_path;
		} else {
			echo '<div class="error"><p>Failed to load the settings template.</p></div>';
		}
	}

	private function handle_form_submission() {
		if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wc_blacklist_verifications_nonce'])) {
			// Unslash and sanitize the nonce field
			$nonce = sanitize_text_field(wp_unslash($_POST['wc_blacklist_verifications_nonce']));
			
			// Verify nonce
			if (wp_verify_nonce($nonce, 'wc_blacklist_verifications_action')) {
				// Sanitize the 'message' field if it is present
				$message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
	
				// Store the sanitized 'message' field in the settings
				$this->save_settings($message);
	
				// Display success or error message
				if (!get_settings_errors('wc_blacklist_verifications_settings')) {
					add_settings_error('wc_blacklist_verifications_settings', 'settings_saved', __('Changes saved successfully.', 'wc-blacklist-manager'), 'updated');
				}
	
				// Return an empty string as the message will be handled by settings_errors()
				return '';
			}
		}
	
		return '';
	}
	
	private function get_verifications_settings() {
		// Get the combined phone verification settings
		$phone_verification_settings = get_option('wc_blacklist_phone_verification', [
			'code_length' => 4,
			'resend' => 180,
			'limit' => 5,
			'message' => $this->default_sms_message,
		]);

		return [
			'email_verification_enabled' => get_option('wc_blacklist_email_verification_enabled', '0'),
			'email_verification_action' => get_option('wc_blacklist_email_verification_action', 'all'),
			'email_verification_real_time_validate' => get_option('wc_blacklist_email_verification_real_time_validate', '0'),
			'phone_verification_enabled' => get_option('wc_blacklist_phone_verification_enabled', '0'),
			'phone_verification_action' => get_option('wc_blacklist_phone_verification_action', 'all'),
			'phone_verification_sms_key' => get_option('yoohw_phone_verification_sms_key', ''),
			'phone_verification_code_length' => $phone_verification_settings['code_length'],
			'phone_verification_resend' => $phone_verification_settings['resend'],
			'phone_verification_limit' => $phone_verification_settings['limit'],
			'phone_verification_message' => !empty($phone_verification_settings['message']) ? $phone_verification_settings['message'] : $this->default_sms_message,
			'phone_verification_failed_email' => get_option('wc_blacklist_phone_verification_failed_email', '0'),
			'phone_verification_real_time_validate' => get_option('wc_blacklist_phone_verification_real_time_validate', '0'),
			'name_verification_auto_capitalization' => get_option('wc_blacklist_name_verification_auto_capitalization', '0'),
			'name_verification_real_time_validate' => get_option('wc_blacklist_name_verification_real_time_validate', '0'),
			'phone_verification_country_code_disabled' => get_option('wc_blacklist_phone_verification_country_code_disabled', '0'),
		];
	}

	private function save_settings() {
		$email_verification_enabled = isset($_POST['email_verification_enabled']) ? '1' : '0';
		$email_verification_action = isset($_POST['email_verification_action']) 
			? sanitize_text_field(wp_unslash($_POST['email_verification_action'])) 
			: 'all';
		$email_verification_real_time_validate = isset($_POST['email_verification_real_time_validate']) ? '1' : '0';

		$phone_verification_enabled = isset($_POST['phone_verification_enabled']) ? '1' : '0';
		$phone_verification_action = isset($_POST['phone_verification_action']) 
			? sanitize_text_field(wp_unslash($_POST['phone_verification_action'])) 
			: 'all';
		$message = isset($_POST['message']) 
			? sanitize_text_field(trim(wp_unslash($_POST['message']))) 
			: '';
	
		// Check if the message contains the required {code} placeholder
		if (strpos($message, '{code}') === false) {
			// Display an error notice if {code} is missing
			add_settings_error('wc_blacklist_verifications_settings', 'invalid_message', __('The message must contain the {code} placeholder.', 'wc-blacklist-manager'), 'error');
			return; // Stop saving if the validation fails
		}
	
		// Apply wp_kses_post() to allow only safe HTML if needed
		$message = !empty($message) ? wp_kses_post($message) : $this->default_sms_message;
	
		// Combine the settings into a single array
		$phone_verification_settings = [
			'code_length' => isset($_POST['code_length']) ? intval(wp_unslash($_POST['code_length'])) : 4,
			'resend' => isset($_POST['resend']) ? intval(wp_unslash($_POST['resend'])) : 180,
			'limit' => isset($_POST['limit']) ? intval(wp_unslash($_POST['limit'])) : 5,
			'message' => $message,
		];
		$phone_verification_failed_email = isset($_POST['phone_verification_failed_email']) ? '1' : '0';
		$phone_verification_real_time_validate = isset($_POST['phone_verification_real_time_validate']) ? '1' : '0';
		$name_verification_auto_capitalization = isset($_POST['name_verification_auto_capitalization']) ? '1' : '0';
		$name_verification_real_time_validate = isset($_POST['name_verification_real_time_validate']) ? '1' : '0';
		$phone_verification_country_code_disabled = isset($_POST['phone_verification_country_code_disabled']) ? '1' : '0';
	
		// Save Email Verification Settings
		update_option('wc_blacklist_email_verification_enabled', $email_verification_enabled);
		update_option('wc_blacklist_email_verification_action', $email_verification_action);
		update_option('wc_blacklist_email_verification_real_time_validate', $email_verification_real_time_validate);
	
		// Save Phone Verification Settings
		update_option('wc_blacklist_phone_verification_enabled', $phone_verification_enabled);
		update_option('wc_blacklist_phone_verification_action', $phone_verification_action);
		update_option('wc_blacklist_phone_verification', $phone_verification_settings);
		update_option('wc_blacklist_phone_verification_failed_email', $phone_verification_failed_email);
		update_option('wc_blacklist_phone_verification_real_time_validate', $phone_verification_real_time_validate);
		update_option('wc_blacklist_name_verification_auto_capitalization', $name_verification_auto_capitalization);
		update_option('wc_blacklist_name_verification_real_time_validate', $name_verification_real_time_validate);
		update_option('wc_blacklist_phone_verification_country_code_disabled', $phone_verification_country_code_disabled);
	
		// Display success message
		add_settings_error('wc_blacklist_verifications_settings', 'settings_saved', __('Settings saved successfully.', 'wc-blacklist-manager'), 'updated');
	}
	  
	public function handle_generate_sms_key() {
		// Verify nonce before processing
		if (
			!isset($_POST['security']) ||
			!wp_verify_nonce(
				sanitize_text_field(wp_unslash($_POST['security'])),
				'generate_sms_key_nonce'
			)
		) {
			wp_send_json_error([
				'message' => __('Security check failed.', 'wc-blacklist-manager')
			]);
		}
	
		// Unslash and sanitize the sms_key
		$sms_key = isset($_POST['sms_key']) ? sanitize_text_field(wp_unslash($_POST['sms_key'])) : '';
	
		if (empty($sms_key)) {
			wp_send_json_error([
				'message' => __('Invalid or empty key provided.', 'wc-blacklist-manager')
			]);
		}
	
		// Prepare data for API call
		$domain     = get_site_url();
		$site_email = get_option('admin_email');
	
		$api_url = 'https://bmc.yoohw.com/wp-json/sms/v1/sms_key_generate/';
		$body    = array(
			'sms_key'    => $sms_key,
			'domain'     => $domain,
			'site_email' => $site_email,
		);
	
		$response = wp_remote_post($api_url, array(
			'method'  => 'POST',
			'body'    => wp_json_encode($body),
			'headers' => array('Content-Type' => 'application/json'),
		));
	
		// Check for WP errors in API call
		if (is_wp_error($response)) {
			wp_send_json_error([
				'message' => __('API call failed: ', 'wc-blacklist-manager') . $response->get_error_message()
			]);
		}
	
		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		$data          = json_decode($response_body, true);
	
		// Ensure response is a success
		if ($response_code !== 200 || !isset($data['status']) || $data['status'] !== 'success') {
			wp_send_json_error([
				'message' => __('API error: ', 'wc-blacklist-manager') . (isset($data['message']) ? $data['message'] : __('Unknown error', 'wc-blacklist-manager'))
			]);
		}
	
		// Only update the option if the API call was successful
		$updated = update_option('yoohw_phone_verification_sms_key', $sms_key);
		if ($updated) {
			wp_send_json_success([
				'message' => __('Key generated and saved successfully.', 'wc-blacklist-manager')
			]);
		} else {
			wp_send_json_error([
				'message' => __('Failed to save the generated key. Please try again.', 'wc-blacklist-manager')
			]);
		}
	}
	
	public function wc_blacklist_refresh_merging() {
		// Check for required capabilities (optional, based on your requirements)
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'wc-blacklist-manager'));
		}
	
		// Delete the option
		delete_option('wc_blacklist_whitelist_merged_success');
	
		// Redirect back to the referring page
		$referrer = wp_get_referer();
		if ($referrer) {
			wp_safe_redirect($referrer);
		} else {
			wp_safe_redirect(admin_url());
		}
		exit;
	}
	
	private function includes() {
		include_once plugin_dir_path(__FILE__) . '/actions/yobm-wc-blacklist-manager-verifications-email.php';
		include_once plugin_dir_path(__FILE__) . '/actions/yobm-wc-blacklist-manager-verifications-phone.php';
	}
}

new WC_Blacklist_Manager_Verifications();

add_action('admin_enqueue_scripts', function ($hook) {
	if (isset($_GET['page']) && $_GET['page'] === 'wc-blacklist-manager-verifications') {
		wp_enqueue_script('thickbox');
		wp_enqueue_style('thickbox');
	}
});
