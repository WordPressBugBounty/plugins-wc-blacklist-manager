<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Hook into Contact Form 7 before sending mail to check all email and tel fields.
 *
 * This function retrieves the form’s structure and posted data, then collects
 * all inputs of type 'email' and 'tel' (or similar), sanitizes their values,
 * and triggers the 'blacklist_form_submit' action.
 *
 * @param WPCF7_ContactForm $contact_form The CF7 form object.
 */

class WC_Blacklist_Manager_Contact_Form_7 {
    /**
     * Flag to indicate a blacklist violation.
     *
     * @var bool
     */
    private $wc_blacklist_blocked = false;
    private $wc_blacklist_blocked_proxy_vpn = false;

    // Arrays to store the details of blocked data.
    private $blocked_emails = array();
    private $blocked_domains = array();
    private $blocked_phones = array();
    private $blocked_ip = array();

    public function __construct() {
        // Attach CF7 field validation filters.
        add_filter( "wpcf7_validate_email*", [ $this, 'cf7_blacklist_validate_email' ], 20, 2 );
        add_filter( "wpcf7_validate_email",  [ $this, 'cf7_blacklist_validate_email' ], 20, 2 );
        add_filter( "wpcf7_validate_tel*",   [ $this, 'cf7_blacklist_validate_tel' ], 20, 2 );
        add_filter( "wpcf7_validate_tel",    [ $this, 'cf7_blacklist_validate_tel' ], 20, 2 );

        if ( get_option( 'wc_blacklist_ip_enabled' ) == '1' ) {
            add_filter( "wpcf7_skip_mail", [ $this, 'cf7_blacklist_validate_ip' ], 20, 2 );
            add_filter( "wpcf7_skip_mail", [ $this, 'cf7_blacklist_validate_proxy_vpn' ], 20, 2 );
        }

        // Override the AJAX JSON response.
        add_filter( 'wpcf7_feedback_response', [ $this, 'cf7_blacklist_override_response' ], 10, 2 );
    }

    /**
     * Validate email fields against the blacklist.
     */
    public function cf7_blacklist_validate_email( $result, $tag ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_blacklist';
        $table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

        $settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
        
        $name  = $tag->name;
        $value = isset( $_POST[ $name ] ) ? trim( $_POST[ $name ] ) : '';
        
        if ( empty( $value ) ) {
            return $result;
        }
        
        if ( is_email( $value ) ) {
            if ( get_option( 'wc_blacklist_form_blocking_enabled' ) == '1' ) {
                // Check for a blocked email.
                $is_blacklisted_email = ! empty( $wpdb->get_var( 
                    $wpdb->prepare(
                        "SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 1 LIMIT 1",
                        $value
                    )
                ) );
                
                if ( $is_blacklisted_email ) {
                    $sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
                    update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
                    $sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
                    update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

                    if ($premium_active) {
                        $timestamp = current_time('mysql');
                        $type      = 'human';
                        $source    = 'cf7_submit';
                        $action    = 'block';
                        $details   = 'blocked_email_attempt: ' . $value;
                        
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

                    $this->wc_blacklist_blocked = true;
                    $this->blocked_emails[] = $value;
                    $result->invalidate( $tag, "" );
                    return $result;
                }
            }
            
            if ( get_option( 'wc_blacklist_domain_form' ) == '1' ) {
                // Extract the domain and check if the domain is blocked.
                $domain = substr( strrchr( $value, "@" ), 1 );
                if ( ! empty( $domain ) ) {
                    $is_blacklisted_domain = ! empty( $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT 1 FROM {$table_name} WHERE domain = %s AND is_blocked = 1 LIMIT 1",
                            $domain
                        )
                    ) );
                    
                    if ( $is_blacklisted_domain ) {
                        $sum_block_domain = get_option('wc_blacklist_sum_block_domain', 0);
                        update_option('wc_blacklist_sum_block_domain', $sum_block_domain + 1);
                        $sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
                        update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

                        if ($premium_active) {
                            $timestamp = current_time('mysql');
                            $type      = 'human';
                            $source    = 'cf7_submit';
                            $action    = 'block';
                            $details   = 'blocked_domain_attempt: ' . $domain;
                            
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

                        $this->wc_blacklist_blocked = true;
                        $this->blocked_domains[] = $domain;
                        $result->invalidate( $tag, "" );
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Validate telephone fields against the blacklist.
     */
    public function cf7_blacklist_validate_tel( $result, $tag ) {
        if ( get_option( 'wc_blacklist_form_blocking_enabled' ) != '1' ) {
            return $result;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_blacklist';
        $table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

        $settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();
        
        $name  = $tag->name;
        $value = isset( $_POST[ $name ] ) ? trim( $_POST[ $name ] ) : '';
        
        if ( empty( $value ) ) {
            return $result;
        }
        
        $is_blacklisted = ! empty( $wpdb->get_var( 
            $wpdb->prepare(
                "SELECT 1 FROM {$table_name} WHERE TRIM(LEADING '0' FROM phone_number) = %s AND is_blocked = 1 LIMIT 1",
                $value
            )
        ) );
        
        if ( $is_blacklisted ) {
            $sum_block_phone = get_option('wc_blacklist_sum_block_phone', 0);
            update_option('wc_blacklist_sum_block_phone', $sum_block_phone + 1);
            $sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
            update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

            if ($premium_active) {
                $timestamp = current_time('mysql');
                $type      = 'human';
                $source    = 'cf7_submit';
                $action    = 'block';
                $details   = 'blocked_phone_attempt: ' . $value;
                
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

            $this->wc_blacklist_blocked = true;
            $this->blocked_phones[] = $value;
            $result->invalidate( $tag, "" );
        }
        
        return $result;
    }

    /**
     * Validate the visitor’s IP address against the blacklist.
     */
    public function cf7_blacklist_validate_ip( $spam, $submission ) {
        $settings_instance = new WC_Blacklist_Manager_Settings();
        $premium_active = $settings_instance->is_premium_active();

        if (!$premium_active) {
            return;
        }

        if ( get_option( 'wc_blacklist_block_ip_form' ) != '1' ) {
            return $spam;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_blacklist';
        $table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';
        
        $ip_address = get_real_customer_ip();
        if ( empty( $ip_address ) ) {
            return $spam;
        }
        
        $is_blacklisted = ! empty( $wpdb->get_var( 
            $wpdb->prepare(
                "SELECT 1 FROM {$table_name} WHERE ip_address = %s AND is_blocked = 1 LIMIT 1",
                $ip_address
            )
        ) );
        
        if ( $is_blacklisted ) {
            $sum_block_ip = get_option('wc_blacklist_sum_block_ip', 0);
            update_option('wc_blacklist_sum_block_ip', $sum_block_ip + 1);
            $sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
            update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

            if ($premium_active) {
                $timestamp = current_time('mysql');
                $type      = 'human';
                $source    = 'cf7_submit';
                $action    = 'block';
                $details   = 'blocked_ip_attempt: ' . $ip_address;
                
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
            
            $this->wc_blacklist_blocked = true;
            $this->blocked_ip[] = $ip_address;
            return true; // This marks the submission as spam.
        }
        
        return $spam;
    }

    /**
     * Validate if the visitor is using a Proxy or VPN.
     */
    public function cf7_blacklist_validate_proxy_vpn( $spam, $submission ) {
        $settings_instance = new WC_Blacklist_Manager_Settings();
        $premium_active = $settings_instance->is_premium_active();

        if (!$premium_active) {
            return;
        }
        
        if ( get_option( 'wc_blacklist_block_proxy_vpn_form' ) != '1' ) {
            return $spam;
        }

        global $wpdb;
        $table_detection_log = $wpdb->prefix . 'wc_blacklist_detection_log';

        $ip_address = get_real_customer_ip();
        if ( empty( $ip_address ) ) {
            return $spam;
        }
                
        $url = 'http://ip-api.com/json/' . $ip_address . '?fields=status,message,proxy';
        $response = wp_remote_get( $url, array( 'timeout' => 5 ) );
        if ( is_wp_error( $response ) ) {
            return $spam;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( ! is_array( $data ) ) {
            return $spam;
        }
        
        if ( isset( $data['status'] ) && $data['status'] === 'success' ) {
            if ( ! empty( $data['proxy'] ) && $data['proxy'] == true ) {
                $sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
                update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

                if ($premium_active) {
                    $timestamp = current_time('mysql');
                    $type      = 'human';
                    $source    = 'cf7_submit';
                    $action    = 'block';
                    $details   = 'blocked_proxy_vpn_attempt: ' . $ip_address;
                    
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
                
                $this->wc_blacklist_blocked_proxy_vpn = true;
                return true; // Mark as spam.
            }
        }
        
        return $spam;
    }

    /**
     * Override the CF7 AJAX JSON response when a blacklist violation is detected.
     */
    public function cf7_blacklist_override_response( $response, $result ) {
        $settings_instance = new WC_Blacklist_Manager_Settings();
        $premium_active = $settings_instance->is_premium_active();

        if ( $this->wc_blacklist_blocked || $this->wc_blacklist_blocked_proxy_vpn ) {

            if ($premium_active && get_option('wc_blacklist_email_form_block') == 'yes') {
                // Trigger the blocked submission email.
                $email_sender = new WC_Blacklist_Manager_Premium_Form_Email();
                $email_sender->send_email_form_block(
                    $this->blocked_emails,
                    $this->blocked_domains,
                    $this->blocked_phones,
                    $this->blocked_ip,
                    $this->wc_blacklist_blocked_proxy_vpn
                );
            }

            // Prepare the custom response message.
            if ( $this->wc_blacklist_blocked ) {
                $custom_message = get_option(
                    'wc_blacklist_form_notice',
                    __( 'Sorry! You are no longer allowed to submit a form. If you think it is a mistake, please contact support.', 'wc-blacklist-manager' )
                );
                $response['message'] = $custom_message;
                $response['status']  = 'mail_failed';
            } elseif ( $this->wc_blacklist_blocked_proxy_vpn ) {
                $custom_message = get_option(
                    'wc_blacklist_vpn_proxy_form_notice',
                    __( 'Submission from VPNs or Proxies is not allowed. Please disable your VPN or Proxy and try again.', 'wc-blacklist-manager' )
                );
                $response['message'] = $custom_message;
                $response['status']  = 'mail_failed';
            }

            // Reset properties for subsequent submissions.
            $this->wc_blacklist_blocked = false;
            $this->wc_blacklist_blocked_proxy_vpn = false;
            $this->blocked_emails = array();
            $this->blocked_domains = array();
            $this->blocked_phones = array();
            $this->blocked_ip = array();
        }
        return $response;
    }
}

new WC_Blacklist_Manager_Contact_Form_7();
