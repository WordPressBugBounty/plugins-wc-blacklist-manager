<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Custom WPForms validation for Lite and Pro.
 * This hook uses wpforms_process (available in both Lite and Pro)
 * to check if a submitted email or telephone/phone value is blacklisted.
 * If a blacklisted value is found, a global form-level error message is set.
 *
 * Debug logging is included to help trace processing.
 *
 * @param array $fields    The submitted fields data.
 * @param array $entry     The entry data.
 * @param array $form_data The complete form configuration.
 * @return array           The (possibly modified) fields data.
 */

class WC_Blacklist_Manager_WP_Forms {
	private $blacklist_violation = false;
	private $proxy_violation = false;
	private $blocked_emails = array();
	private $blocked_domains = array();
	private $blocked_phones = array();
	private $blocked_ip = array();

	public function __construct() {
		add_action( 'wpforms_process', [ $this, 'wpforms_blacklist_validation_lite' ], 10, 3 );
	}

	/**
	 * WPForms blacklist validation.
	*
	* This function performs the following checks:
	* - Checks the visitor’s IP address (if enabled):
	*   - If the IP is blacklisted (option: wc_blacklist_block_ip_form) then record the violation.
	*   - Otherwise, if enabled (option: wc_blacklist_block_proxy_vpn_form), call ip‑api.com to check
	*     for proxy/VPN usage.
	* - For form fields of type "email", "tel", or "phone":
	*   - Checks if the full email address is blacklisted.
	*   - If the field is an email field and email domain checking is enabled (wc_blacklist_domain_form),
	*     extracts the domain (portion after "@") and checks if that domain is blacklisted.
	*   - Checks phone numbers against the blacklist.
	*
	* If any check results in a violation, the function collects the values, sets an error message,
	* triggers an email notification with the blocked details, and returns the fields array.
	*
	* @param array $fields    The posted field values.
	* @param array $entry     The submission entry.
	* @param array $form_data The form data.
	* @return array The (possibly modified) fields array.
	*/
	public function wpforms_blacklist_validation_lite( $fields, $entry, $form_data ) {
		// Bail if user has checked "Disable blacklist for this form"
		if ( ! empty( $form_data['settings']['disable_blacklist'] ) ) {
			return;
		}
		
		global $wpdb;
		$form_id    = isset( $form_data['id'] ) ? $form_data['id'] : 0;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$found      = false;

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		$ip_address = get_real_customer_ip();

		// --- IP ADDRESS & PROXY/VPN CHECK ---
		if ( $premium_active && get_option( 'wc_blacklist_ip_enabled' ) == '1' ) {
			if ( ! empty( $ip_address ) ) {
				// Check IP in blacklist if enabled.
				if ( get_option( 'wc_blacklist_block_ip_form' ) == '1' ) {
					$ip_blacklisted = $wpdb->get_var( $wpdb->prepare(
						"SELECT 1 FROM {$table_name} WHERE ip_address = %s AND is_blocked = 1 LIMIT 1",
						$ip_address
					) );
					if ( ! empty( $ip_blacklisted ) ) {
						$sum_block_ip = get_option('wc_blacklist_sum_block_ip', 0);
                        update_option('wc_blacklist_sum_block_ip', $sum_block_ip + 1);
                        $sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
                        update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);
                        
                        if ($premium_active) {
                            $reason_user_ip   = 'blocked_ip_attempt: ' . $ip_address;
                            WC_Blacklist_Manager_Premium_Activity_Logs_Insert::wpforms_block('', '', '', $reason_user_ip);
                        }

						$found = true;
						$this->blacklist_violation = true;
						$this->blocked_ip[] = $ip_address;
					}
				}
				// Check Proxy/VPN only if not already found and if enabled.
				if ( get_option( 'wc_blacklist_block_proxy_vpn_form' ) == '1' ) {
					$url = 'http://ip-api.com/json/' . $ip_address . '?fields=status,message,proxy';
					$response = wp_remote_get( $url, array( 'timeout' => 5 ) );
					if ( ! is_wp_error( $response ) ) {
						$body = wp_remote_retrieve_body( $response );
						$data = json_decode( $body, true );
						if ( isset( $data['status'] ) && $data['status'] === 'success' && ! empty( $data['proxy'] ) && $data['proxy'] == true ) {
							$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
                            update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

                            if ($premium_active) {
                                $reason_proxy_vpn   = 'blocked_proxy_vpn_attempt: ' . $ip_address;
                                WC_Blacklist_Manager_Premium_Activity_Logs_Insert::wpforms_block('', '', '', '', '', '', $reason_proxy_vpn);
                            }

							$found = true;
							$this->blacklist_violation = true;
							$this->proxy_violation = true;
						}
					}
				}
			}
		}

		// --- EMAIL & PHONE FIELD CHECKS ---
		if ( isset( $form_data['fields'] ) && is_array( $form_data['fields'] ) ) {
			foreach ( $form_data['fields'] as $field ) {
				if ( empty( $field['type'] ) ) {
					continue;
				}
				$field_type = strtolower( $field['type'] );
				// Process only fields of type email, tel, or phone.
				if ( in_array( $field_type, array( 'email', 'tel', 'phone' ), true ) ) {
					$field_id = $field['id'];
					if ( isset( $fields[ $field_id ]['value'] ) ) {
						$value = trim( $fields[ $field_id ]['value'] );
						if ( ! empty( $value ) ) {
							// For email fields.
							if ( 'email' === $field_type && is_email( $value ) ) {
								if ( get_option( 'wc_blacklist_form_blocking_enabled' ) == '1' ) {
									// Check full email address.
									$result = $wpdb->get_var( $wpdb->prepare(
										"SELECT 1 FROM {$table_name} WHERE email_address = %s AND is_blocked = 1 LIMIT 1",
										$value
									) );
									if ( ! empty( $result ) ) {
										$sum_block_email = get_option('wc_blacklist_sum_block_email', 0);
										update_option('wc_blacklist_sum_block_email', $sum_block_email + 1);
										$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
										update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

										if ($premium_active) {
											$reason_email   = 'blocked_email_attempt: ' . $value;
											WC_Blacklist_Manager_Premium_Activity_Logs_Insert::wpforms_block('', '', $reason_email);
										}
										
										$found = true;
										$this->blacklist_violation = true;
										$this->blocked_emails[] = $value;
									}
								}
								if ( get_option( 'wc_blacklist_domain_form' ) == '1' ) {
									// Extract the domain part (after '@') and check.
									$domain = substr( strrchr( $value, "@" ), 1 );
									if ( ! empty( $domain ) ) {
										$result_domain = $wpdb->get_var( $wpdb->prepare(
											"SELECT 1 FROM {$table_name} WHERE domain = %s AND is_blocked = 1 LIMIT 1",
											$domain
										) );
										if ( ! empty( $result_domain ) ) {
											$sum_block_domain = get_option('wc_blacklist_sum_block_domain', 0);
											update_option('wc_blacklist_sum_block_domain', $sum_block_domain + 1);
											$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
											update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

											if ($premium_active) {
												$reason_domain   = 'blocked_domain_attempt: ' . $domain;
												WC_Blacklist_Manager_Premium_Activity_Logs_Insert::wpforms_block('', '', '', '', '', $reason_domain);
											}
											
											$found = true;
											$this->blacklist_violation = true;
											$this->blocked_domains[] = $domain;
										}
									}
								}

								$email_value = $value;
							}
							// For telephone/phone fields.
							if ( in_array( $field_type, array( 'tel', 'phone' ), true ) && get_option( 'wc_blacklist_form_blocking_enabled' ) == '1' ) {
								$raw_phone = $value;
								$digits_only = preg_replace( '/\D+/', '', $raw_phone );
								$clean_phone = ltrim( $digits_only, '0' );

								$result = $wpdb->get_var( $wpdb->prepare(
									"SELECT 1 FROM {$table_name} WHERE TRIM(LEADING '0' FROM phone_number) = %s AND is_blocked = 1 LIMIT 1",
									$clean_phone
								) );
								if ( ! empty( $result ) ) {
									$sum_block_phone = get_option('wc_blacklist_sum_block_phone', 0);
									update_option('wc_blacklist_sum_block_phone', $sum_block_phone + 1);
									$sum_block_total = get_option('wc_blacklist_sum_block_total', 0);
									update_option('wc_blacklist_sum_block_total', $sum_block_total + 1);

									if ($premium_active) {
										$reason_phone   = 'blocked_phone_attempt: ' . $value;
										WC_Blacklist_Manager_Premium_Activity_Logs_Insert::wpforms_block('', $reason_phone);
									}
									
									$found = true;
									$this->blacklist_violation = true;
									$this->blocked_phones[] = $value;
								}

								$phone_value = $value;
							}
						}
					}
				}
			}
		}

		// --- SET GLOBAL ERROR & TRIGGER EMAIL IF A VIOLATION IS FOUND ---
		if ( $found ) {
			// Determine the custom error message.
			if ( $this->proxy_violation ) {
				$custom_message = get_option(
					'wc_blacklist_vpn_proxy_form_notice',
					__('Submission from VPNs or Proxies is not allowed. Please disable your VPN or Proxy and try again.', 'wc-blacklist-manager')
				);
			} else {
				$custom_message = get_option(
					'wc_blacklist_form_notice',
					__( 'Sorry! You are no longer allowed to submit a form. If you think it is a mistake, please contact support.', 'wc-blacklist-manager' )
				);
			}
			// Set a form-level error message.
			wpforms()->process->errors[ $form_id ]['header'] = $custom_message;

			$view_data = [
				'ip_address' => $ip_address,
				'email'      => $email_value,
				'phone'      => $phone_value,
			];
			$view_json = wp_json_encode( $view_data );
            WC_Blacklist_Manager_Premium_Activity_Logs_Insert::wpforms_block($view_json);			

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

		return $fields;
	}
} 

new WC_Blacklist_Manager_WP_Forms();
