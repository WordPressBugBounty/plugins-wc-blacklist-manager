<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Verifications {
    private $default_sms_message;

    public function __construct() {
        $this->default_sms_message = __('{site_name}: Your verification code is {code}', 'wc-blacklist-manager');
        add_action('admin_menu', [$this, 'add_verifications_submenu']);
		add_action('wp_ajax_generate_sms_key', [$this, 'handle_generate_sms_key']);

        $this->includes();
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
                [$this, 'render_verifications_settings']
            );
        }
    }

    public function render_verifications_settings() {
        $settings_instance = new WC_Blacklist_Manager_Settings();
        $premium_active = $settings_instance->is_premium_active();
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
            'phone_verification_enabled' => get_option('wc_blacklist_phone_verification_enabled', '0'),
            'phone_verification_action' => get_option('wc_blacklist_phone_verification_action', 'all'),
            'phone_verification_sms_key' => get_option('yoohw_phone_verification_sms_key', ''),
            'phone_verification_code_length' => $phone_verification_settings['code_length'],
            'phone_verification_resend' => $phone_verification_settings['resend'],
            'phone_verification_limit' => $phone_verification_settings['limit'],
            'phone_verification_message' => !empty($phone_verification_settings['message']) ? $phone_verification_settings['message'] : $this->default_sms_message,
        ];
    }

    private function save_settings() {
        // Email Verification Settings
        $email_verification_enabled = isset($_POST['email_verification_enabled']) ? '1' : '0';
        
        // Unslash and sanitize the email verification action
        $email_verification_action = isset($_POST['email_verification_action']) 
            ? sanitize_text_field(wp_unslash($_POST['email_verification_action'])) 
            : 'all';
    
        // Phone Verification Settings
        $phone_verification_enabled = isset($_POST['phone_verification_enabled']) ? '1' : '0';
        
        // Unslash and sanitize the phone verification action
        $phone_verification_action = isset($_POST['phone_verification_action']) 
            ? sanitize_text_field(wp_unslash($_POST['phone_verification_action'])) 
            : 'all';
    
        // Sanitize and trim the message field
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
    
        // Save Email Verification Settings
        update_option('wc_blacklist_email_verification_enabled', $email_verification_enabled);
        update_option('wc_blacklist_email_verification_action', $email_verification_action);
    
        // Save Phone Verification Settings
        update_option('wc_blacklist_phone_verification_enabled', $phone_verification_enabled);
        update_option('wc_blacklist_phone_verification_action', $phone_verification_action);
        update_option('wc_blacklist_phone_verification', $phone_verification_settings); // Save the combined settings
    
        // Display success message
        add_settings_error('wc_blacklist_verifications_settings', 'settings_saved', __('Settings saved successfully.', 'wc-blacklist-manager'), 'updated');
    }
      
    public function handle_generate_sms_key() {
        // Verify nonce before processing
        if (!isset($_POST['security']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'])), 'generate_sms_key_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'wc-blacklist-manager')]);
        }
    
        // Unslash and sanitize the sms_key
        $sms_key = isset($_POST['sms_key']) ? sanitize_text_field(wp_unslash($_POST['sms_key'])) : '';
    
        if (empty($sms_key)) {
            wp_send_json_error(['message' => __('Invalid or empty key provided.', 'wc-blacklist-manager')]);
        }
    
        // Save the generated key to the option
        $updated = update_option('yoohw_phone_verification_sms_key', $sms_key);
    
        if ($updated) {
            // Proceed with other processing
            $domain = get_site_url();
            $site_email = get_option('admin_email');
    
            // Prepare and send data via API
            $api_url = 'https://bmc.yoohw.com/wp-json/sms/v1/sms_key_generate/';
            $body = array(
                'sms_key'   => $sms_key,
                'domain'    => $domain,
                'site_email' => $site_email,
            );
    
            $response = wp_remote_post($api_url, array(
                'method'    => 'POST',
                'body'      => wp_json_encode($body),
                'headers'   => array('Content-Type' => 'application/json'),
            ));
    
            // Send a success response
            wp_send_json_success(['message' => __('Key generated and saved successfully.', 'wc-blacklist-manager')]);
        } else {
            wp_send_json_error(['message' => __('Failed to save the generated key. Please try again.', 'wc-blacklist-manager')]);
        }
    }
    
    private function includes() {
        include_once plugin_dir_path(__FILE__) . '/actions/yobm-wc-blacklist-manager-verifications-email.php';
        include_once plugin_dir_path(__FILE__) . '/actions/yobm-wc-blacklist-manager-verifications-phone.php';
    }
}

new WC_Blacklist_Manager_Verifications();
