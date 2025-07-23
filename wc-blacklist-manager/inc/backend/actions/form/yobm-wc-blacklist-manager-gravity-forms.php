<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Gravity Forms Blacklist Integration Class.
 *
 * Checks submitted values against your blacklist (IP, proxy/VPN, email, and phone)
 * and, if any violation is detected, invalidates the submission and triggers an email notification.
 */
class WC_Blacklist_Manager_Gravity_Forms {
    private $blacklist_violation = false;
    private $proxy_violation = false;
    private $blocked_emails = array();
    private $blocked_domains = array ();
    private $blocked_phones = array();
    private $blocked_ip = array();

    public function __construct() {
        // Hook into Gravity Forms validation.
        add_filter('gform_validation', array($this, 'gf_blacklist_validation'));
        // Override the global validation message.
        add_filter('gform_validation_message', array($this, 'gf_blacklist_validation_message'), 10, 2);
    }

    /**
     * Gravity Forms validation callback.
     *
     * Loops through submitted values to check:
     * - The visitor’s IP address (including Proxy/VPN lookup)
     * - Email addresses (exact match and email domain)
     * - Phone numbers
     *
     * If any check indicates a blacklisted value, sets flags, records the blocked values,
     * clears individual field errors, marks the form as invalid, and triggers an email notification.
     *
     * @param array $validation_result The current validation result.
     * @return array Modified validation result.
     */
    public function gf_blacklist_validation($validation_result) {
        $form = $validation_result['form'];

        if ( ! empty( $form['disable_blacklist'] ) ) {
            return $validation_result;
        }
                
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_blacklist';
        $found = false;

        $settings_instance = new WC_Blacklist_Manager_Settings();
        $premium_active = $settings_instance->is_premium_active();

        // --- IP ADDRESS & PROXY/VPN CHECK ---
        if ($premium_active && get_option('wc_blacklist_ip_enabled') == '1') {
            // Retrieve visitor IP.
            $ip_address = get_real_customer_ip();
            
            if (!empty($ip_address)) {
                // Check the IP blacklist if enabled.
                if (get_option('wc_blacklist_block_ip_form') == '1') {
                    $ip_blacklisted = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT 1 FROM {$table_name} WHERE ip_address = %s AND is_blocked = 1 LIMIT 1",
                            $ip_address
                        )
                    );
                    if (!empty($ip_blacklisted)) {
                        $sum_block_ip = get_option('wc_blacklist_sum_block_ip', 0);
                        update_option('wc_blacklist_sum_block_ip', $sum_block_ip + 1);
                        $sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
                        update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);
                        
                        if ($premium_active) {
                            $reason_user_ip   = 'blocked_ip_attempt: ' . $ip_address;
                            WC_Blacklist_Manager_Premium_Activity_Logs_Insert::gravity_block('', '', '', $reason_user_ip);
                        }

                        $found = true;
                        $this->blocked_ip[] = $ip_address;
                    }
                }
                // Check for Proxy/VPN if not already found and enabled.
                if (get_option('wc_blacklist_block_proxy_vpn_form') == '1') {
                    $url = 'http://ip-api.com/json/' . $ip_address . '?fields=status,message,proxy';
                    $response = wp_remote_get($url, array('timeout' => 5));
                    if (!is_wp_error($response)) {
                        $body = wp_remote_retrieve_body($response);
                        $data = json_decode($body, true);
                        if (isset($data['status']) && $data['status'] === 'success' && !empty($data['proxy']) && $data['proxy'] == true) {
                            $sum_block_ip = get_option('wc_blacklist_sum_block_ip', 0);
                            update_option('wc_blacklist_sum_block_ip', $sum_block_ip + 1);
                            $sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
                            update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

                            if ($premium_active) {
                                $reason_proxy_vpn   = 'blocked_proxy_vpn_attempt: ' . $ip_address;
                                WC_Blacklist_Manager_Premium_Activity_Logs_Insert::gravity_block('', '', '', '', '', '', $reason_proxy_vpn);
                            }

                            $found = true;
                            $this->proxy_violation = true;
                        }
                    }
                }
            }
        }
        
        // --- EMAIL & PHONE FIELD CHECKS ---
        // Loop through each field in the form.
        foreach ($form['fields'] as $field) {
            // Target fields of type "email" and "phone".
            if ($field->type == 'email' || $field->type == 'phone') {
                $field_id = $field->id;
                $input_name = 'input_' . $field_id;
                $value = rgpost($input_name);

                if (!empty($value)) {
                    // For email fields:
                    if ($field->type == 'email' && is_email($value)) {
                        if (get_option('wc_blacklist_form_blocking_enabled') == '1') {
                            // Check if the email address is blacklisted.
                            $email_blacklist = $wpdb->get_var(
                                $wpdb->prepare(
                                    "SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 1 LIMIT 1",
                                    $value
                                )
                            );
                            if (!empty($email_blacklist)) {
                                $sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
                                update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
                                $sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
                                update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

                                if ($premium_active) {
                                    $reason_email   = 'blocked_email_attempt: ' . $value;
                                    WC_Blacklist_Manager_Premium_Activity_Logs_Insert::gravity_block('', '', $reason_email);
                                }
                                
                                $found = true;
                                $this->blocked_emails[] = $value;
                            }
                        }
                        if (get_option('wc_blacklist_domain_form') == '1') {
                            // Extract the domain from the email.
                            $domain = substr(strrchr($value, "@"), 1);
                            if (!empty($domain)) {
                                $domain_blacklist = $wpdb->get_var(
                                    $wpdb->prepare(
                                        "SELECT 1 FROM {$table_name} WHERE domain = %s AND is_blocked = 1 LIMIT 1",
                                        $domain
                                    )
                                );
                                if (!empty($domain_blacklist)) {
                                    $sum_block_domain = get_option('wc_blacklist_sum_block_domain', 0);
                                    update_option('wc_blacklist_sum_block_domain', $sum_block_domain + 1);
                                    $sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
                                    update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

                                    if ($premium_active) {
                                        $reason_domain   = 'blocked_domain_attempt: ' . $domain;
                                        WC_Blacklist_Manager_Premium_Activity_Logs_Insert::gravity_block('', '', '', '', '', $reason_domain);
                                    }
                                    
                                    $found = true;
                                    $this->blocked_domains[] = $domain;
                                }
                            }
                        }

                        $email_value = $value;
                    }
                    // For phone fields:
                    if ($field->type == 'phone' && get_option('wc_blacklist_form_blocking_enabled') == '1') {
                        $raw_phone = $value;
                        $digits_only = preg_replace( '/\D+/', '', $raw_phone );
                        $clean_phone = ltrim( $digits_only, '0' );

                        $phone_blacklist = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT 1 FROM {$table_name} WHERE TRIM(LEADING '0' FROM phone_number) = %s AND is_blocked = 1 LIMIT 1",
                                $clean_phone
                            )
                        );
                        if (!empty($phone_blacklist)) {
                            $sum_block_phone = get_option('wc_blacklist_sum_block_phone', 0);
                            update_option('wc_blacklist_sum_block_phone', $sum_block_phone + 1);
                            $sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
                            update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

                            if ($premium_active) {
                                $reason_phone   = 'blocked_phone_attempt: ' . $value;
                                WC_Blacklist_Manager_Premium_Activity_Logs_Insert::gravity_block('', $reason_phone);
                            }
                            
                            $found = true;
                            $this->blocked_phones[] = $value;
                        }

                        $phone_value = $value;
                    }
                }
            }
        }

        // If any blacklist violation was detected, mark the form as invalid.
        if ($found) {
            $this->blacklist_violation = true;

			$view_data = [
				'ip_address' => $ip_address,
				'email'      => $email_value,
				'phone'      => $phone_value,
			];
			$view_json = wp_json_encode( $view_data );
            WC_Blacklist_Manager_Premium_Activity_Logs_Insert::gravity_block($view_json);

            // Clear individual field errors.
            if (isset($validation_result['form']['fields'])) {
                foreach ($validation_result['form']['fields'] as &$field) {
                    $field->failedValidation = false;
                    $field->validationMessage = '';
                }
            }
            // Mark the overall form as invalid.
            $validation_result['is_valid'] = false;

            if ($premium_active && get_option('wc_blacklist_email_form_block') == 'yes') {
                // Trigger the email for the blocked submission.
                $email_sender = new WC_Blacklist_Manager_Premium_Form_Email();
                $email_sender->send_email_form_block(
                    $this->blocked_emails,
                    $this->blocked_domains,
                    $this->blocked_phones,
                    $this->blocked_ip,
                    $this->proxy_violation
                );
            }
        }
        
        return $validation_result;
    }

    /**
     * Override the Gravity Forms global validation message.
     *
     * If a blacklist violation was detected, this filter returns a custom message.
     * If the violation is due to proxy/VPN use, a specific message is shown.
     *
     * @param string $message The default validation message.
     * @param array  $form    The form array.
     * @return string The custom validation message if a violation exists; otherwise, the default message.
     */
    public function gf_blacklist_validation_message($message, $form) {
        if ($this->blacklist_violation) {
            // Use a custom message for proxy/VPN violations if applicable.
            if ($this->proxy_violation) {
                $custom_message = get_option(
                    'wc_blacklist_vpn_proxy_form_notice',
                    __('Submission from VPNs or Proxies is not allowed. Please disable your VPN or Proxy and try again.', 'wc-blacklist-manager')
                );
            } else {
                $custom_message = get_option(
                    'wc_blacklist_form_notice',
                    __('Sorry! You are no longer allowed to submit a form. If you think it is a mistake, please contact support.', 'wc-blacklist-manager')
                );
            }
            // Wrap the custom message in the desired HTML markup.
            $html_message = '<h2 class="gform_submission_error hide_summary"><span class="gform-icon gform-icon--circle-error"></span>' . $custom_message . '</h2>';
            // Reset violation flags for future submissions.
            $this->blacklist_violation = false;
            $this->proxy_violation = false;
            return $html_message;
        }
        return $message;
    }
}

// Instantiate the Gravity Forms Blacklist Manager.
new WC_Blacklist_Manager_Gravity_Forms();
