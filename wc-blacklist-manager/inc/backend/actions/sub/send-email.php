<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Email {
	public function send_email_order_suspect($order_id, $customer_name, $phone, $email, $user_ip, $customer_address, $shipping_address, $order_edit_url, $device_id) {
		// Keep the legacy signature for consumers; customer fields/URLs never enter mail.
		return WC_Blacklist_Notification_Free_Events::suspect( $order_id );
	}

    // Accumulate suspect data in one static array.
    protected static $block_data = array(
        'phone'   => '',
        'email'   => '',
        'user_ip' => '',
		'domain'  => '',
		'customer_name' => '',
		'billing' => '',
		'shipping' => '',
		'disposable_phone' => '',
		'disposable_email' => '',
    );
    // Prevent sending more than one email per request.
    protected static $email_scheduled = false;

    /**
     * Call this method to “queue” suspect values for email.
     * The method accepts:
     *   - $phone: a suspect phone (if any),
     *   - $email: a suspect email (if any),
     *   - $user_ip: a suspect IP (if any).
     *
     * Since your functions don’t pass an order ID, they all update one global set.
     *
     * @param string $phone   Suspect phone value.
     * @param string $email   Suspect email value.
     * @param string $user_ip Suspect user IP value.
     */
    public static function send_email_order_block (
		$phone = '', 
		$email = '', 
		$user_ip = '', 
		$domain = '', 
		$customer_name = '', 
		$billing = '', 
		$shipping = '', 
		$disposable_phone = '', 
		$disposable_email = '', 
		$proxy_vpn = '',
		$device = ''
		) {
        WC_Blacklist_Notification_Blocked_Events::buffer( array( $phone, $email, $user_ip, $domain, $customer_name, $billing, $shipping, $disposable_phone, $disposable_email, $proxy_vpn, $device ) );
    }

    /** Compatibility handoff only; never transport on the customer request. */
    public static function send_merged_email() {
        return WC_Blacklist_Notification_Blocked_Events::flush();
    }

	// 
	// RESGITRATION EMAIL
	//
	public static function send_email_registration_suspect( $phone = '', $email = '', $user_ip = '' ) {
		// BM-0120 provider path; only missing provider retains the frozen legacy body.
		if ( WC_Blacklist_Notification_Provider::present() ) {
			return WC_Blacklist_Notification_Provider::signal( 'registration.suspect', array_keys( array_filter( array( 'phone' => ! empty( $phone ), 'email' => ! empty( $email ), 'ip' => ! empty( $user_ip ) ) ) ) );
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if ( !$premium_active || 'yes' !== get_option( 'wc_blacklist_email_register_suspect', 'no' ) ) {
			return;
		}

		if ( empty( $phone ) 
		  && empty( $email ) 
		  && empty( $user_ip ) ) {
			return;
		}
	
		// Update our static storage with non-empty values.
		if ( ! empty( $phone ) ) {
			self::$block_data['phone'] = $phone;
		}
		if ( ! empty( $email ) ) {
			self::$block_data['email'] = $email;
		}
		if ( ! empty( $user_ip ) ) {
			self::$block_data['user_ip'] = $user_ip;
		}
	
		// Schedule sending the email once per request.
		if ( ! self::$email_scheduled ) {
			add_action( 'shutdown', [ __CLASS__, 'send_merged_email_suspect_registration' ] );
			self::$email_scheduled = true;
		}
	}	

	public static function send_merged_email_suspect_registration() {
		// BM-0120 provider path; legacy flush is exclusive to a missing provider.
		if ( WC_Blacklist_Notification_Provider::present() ) { return WC_Blacklist_Notification_Provider::flush(); }

		$sender_name    = get_option( 'wc_blacklist_sender_name' );
		$sender_address = get_option( 'wc_blacklist_sender_address' );
		$recipient      = get_option( 'wc_blacklist_email_recipient' );
		$footer_text    = get_option( 'wc_blacklist_email_footer_text' );
		
		$subject = __( 'Suspected user registration detected', 'wc-blacklist-manager' );

        $content = __( 'A visitor has registered an account with suspected data:', 'wc-blacklist-manager' ) . '<br><br>';
        if (!empty(self::$block_data['phone'])) {
            $content .= '• ' . sprintf(__('Suspected phone: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['phone'])) . '<br>';
        }
        if (!empty(self::$block_data['email'])) {
            $content .= '• ' . sprintf(__('Suspected email: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['email'])) . '<br>';
        }
        if (!empty(self::$block_data['user_ip'])) {
            $content .= '• ' . sprintf(__('Suspected IP: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['user_ip'])) . '<br>';
        }

        if (empty($content)) {
            return;
        }

        // Load the HTML email template.
		$template_path = plugin_dir_path( __FILE__ ) . '../../emails/templates/default.html';
		if ( file_exists( $template_path ) ) {
			$template = file_get_contents( $template_path );
	
			// Replace template placeholders.
			$heading = __( 'Suspicious registration!', 'wc-blacklist-manager' );
			$message = str_replace(
				array( '{{heading}}', '{{content}}', '{{footer}}' ),
				array( $heading, $content, $footer_text ),
				$template
			);
	
			// Configure email headers.
			$headers = array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $sender_name . ' <' . $sender_address . '>',
			);
	
			// Send the email.
			return wp_mail( $recipient, $subject, $message, $headers );
		}
		return false;
    }

	public static function send_email_registration_block( $phone = '', $email = '', $user_ip = '', $domain = '', $disposable_email = '', $proxy_vpn = '', $device_id = '' ) {
		// BM-0120 provider path; only missing provider retains the frozen legacy body.
		if ( WC_Blacklist_Notification_Provider::present() ) {
			return WC_Blacklist_Notification_Provider::signal( 'registration.blocked', array_keys( array_filter( array( 'phone' => ! empty( $phone ), 'email' => ! empty( $email ), 'ip' => ! empty( $user_ip ), 'domain' => ! empty( $domain ), 'disposable_email' => ! empty( $disposable_email ), 'proxy_vpn' => ! empty( $proxy_vpn ), 'device' => ! empty( $device_id ) ) ) ) );
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if ( !$premium_active || 'yes' !== get_option( 'wc_blacklist_email_register_block', 'no' ) ) {
			return;
		}

		if ( empty( $phone ) 
		  && empty( $email ) 
		  && empty( $user_ip ) 
		  && empty( $domain ) 
		  && empty( $disposable_email )
		  && empty( $proxy_vpn )
		  && empty( $device_id ) ) {
			return;
		}
	
		// Update our static storage with non-empty values.
		if ( ! empty( $phone ) ) {
			self::$block_data['phone'] = $phone;
		}
		if ( ! empty( $email ) ) {
			self::$block_data['email'] = $email;
		}
		if ( ! empty( $user_ip ) ) {
			self::$block_data['user_ip'] = $user_ip;
		}
		if ( ! empty( $domain ) ) {
			self::$block_data['domain'] = $domain;
		}
		if ( ! empty( $disposable_email ) ) {
			self::$block_data['disposable_email'] = $disposable_email;
		}
		if ( ! empty( $proxy_vpn ) ) {
			self::$block_data['proxy_vpn'] = $proxy_vpn;
		}
		if ( ! empty( $device_id ) ) {
			self::$block_data['device_id'] = $device_id;
		}
	
		// Schedule sending the email once per request.
		if ( ! self::$email_scheduled ) {
			add_action( 'shutdown', [ __CLASS__, 'send_merged_email_block_registration' ] );
			self::$email_scheduled = true;
		}
	}	

	public static function send_merged_email_block_registration() {
		// BM-0120 provider path; legacy flush is exclusive to a missing provider.
		if ( WC_Blacklist_Notification_Provider::present() ) { return WC_Blacklist_Notification_Provider::flush(); }

		// Retrieve sender and recipient settings.
		$sender_name    = get_option( 'wc_blacklist_sender_name' );
		$sender_address = get_option( 'wc_blacklist_sender_address' );
		$recipient      = get_option( 'wc_blacklist_email_recipient' );
		$footer_text    = get_option( 'wc_blacklist_email_footer_text' );
		
		$subject = __( 'An account registration has been blocked', 'wc-blacklist-manager' );

        // Build email content based on merged suspect data.
        $content = __( 'A visitor tried to register an account with blocked data:', 'wc-blacklist-manager' ) . '<br><br>';
        if (!empty(self::$block_data['phone'])) {
            $content .= '• ' . sprintf(__('Blocked phone: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['phone'])) . '<br>';
        }
        if (!empty(self::$block_data['email'])) {
            $content .= '• ' . sprintf(__('Blocked email: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['email'])) . '<br>';
        }
        if (!empty(self::$block_data['user_ip'])) {
            $content .= '• ' . sprintf(__('Blocked IP: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['user_ip'])) . '<br>';
        }
		if (!empty(self::$block_data['domain'])) {
            $content .= '• ' . sprintf(__('Blocked domain: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['domain'])) . '<br>';
        }
		if (!empty(self::$block_data['disposable_email'])) {
            $content .= '• ' . sprintf(__('Disposable email: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['disposable_email'])) . '<br>';
        }
		if (!empty(self::$block_data['proxy_vpn'])) {
            $content .= '• ' . sprintf(__('Proxy or VPN: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['proxy_vpn'])) . '<br>';
        }
		if (!empty(self::$block_data['device_id'])) {
            $content .= '• ' . sprintf(__('Device ID: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['device_id'])) . '<br>';
        }

        // If no suspect data was accumulated, don't send an email.
        if (empty($content)) {
            return;
        }

        // Load the HTML email template.
		$template_path = plugin_dir_path( __FILE__ ) . '../../emails/templates/default.html';
		if ( file_exists( $template_path ) ) {
			$template = file_get_contents( $template_path );
	
			// Replace template placeholders.
			$heading = __( 'Registration was blocked!', 'wc-blacklist-manager' );
			$message = str_replace(
				array( '{{heading}}', '{{content}}', '{{footer}}' ),
				array( $heading, $content, $footer_text ),
				$template
			);
	
			// Configure email headers.
			$headers = array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $sender_name . ' <' . $sender_address . '>',
			);
	
			// Send the email.
			return wp_mail( $recipient, $subject, $message, $headers );
		}
		return false;
    }

	// 
	// COMMENTATION EMAIL
	//
	public static function send_email_comment_suspect( $email = '', $user_ip = '' ) {
		// BM-0120 provider path; only missing provider retains the frozen legacy body.
		if ( WC_Blacklist_Notification_Provider::present() ) {
			return WC_Blacklist_Notification_Provider::signal( 'comment.suspect', array_keys( array_filter( array( 'email' => ! empty( $email ), 'ip' => ! empty( $user_ip ) ) ) ) );
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if ( !$premium_active || 'yes' !== get_option( 'wc_blacklist_email_comment_suspect', 'no' ) ) {
			return;
		}

		if ( empty( $email ) 
		  && empty( $user_ip ) ) {
			return;
		}
	
		// Update our static storage with non-empty values.
		if ( ! empty( $email ) ) {
			self::$block_data['email'] = $email;
		}
		if ( ! empty( $user_ip ) ) {
			self::$block_data['user_ip'] = $user_ip;
		}
	
		// Schedule sending the email once per request.
		if ( ! self::$email_scheduled ) {
			add_action( 'shutdown', [ __CLASS__, 'send_merged_email_suspect_comment' ] );
			self::$email_scheduled = true;
		}
	}	

	public static function send_merged_email_suspect_comment() {
		// BM-0120 provider path; legacy flush is exclusive to a missing provider.
		if ( WC_Blacklist_Notification_Provider::present() ) { return WC_Blacklist_Notification_Provider::flush(); }

		$sender_name    = get_option( 'wc_blacklist_sender_name' );
		$sender_address = get_option( 'wc_blacklist_sender_address' );
		$recipient      = get_option( 'wc_blacklist_email_recipient' );
		$footer_text    = get_option( 'wc_blacklist_email_footer_text' );
		
		$subject = __( 'Suspected user commentation detected', 'wc-blacklist-manager' );

        $content = __( 'A user has submited an comment with suspected data:', 'wc-blacklist-manager' ) . '<br><br>';
        if (!empty(self::$block_data['email'])) {
            $content .= '• ' . sprintf(__('Suspected email: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['email'])) . '<br>';
        }
        if (!empty(self::$block_data['user_ip'])) {
            $content .= '• ' . sprintf(__('Suspected IP: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['user_ip'])) . '<br>';
        }

        if (empty($content)) {
            return;
        }

        // Load the HTML email template.
		$template_path = plugin_dir_path( __FILE__ ) . '../../emails/templates/default.html';
		if ( file_exists( $template_path ) ) {
			$template = file_get_contents( $template_path );
	
			// Replace template placeholders.
			$heading = __( 'Suspicious commentation!', 'wc-blacklist-manager' );
			$message = str_replace(
				array( '{{heading}}', '{{content}}', '{{footer}}' ),
				array( $heading, $content, $footer_text ),
				$template
			);
	
			// Configure email headers.
			$headers = array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $sender_name . ' <' . $sender_address . '>',
			);
	
			// Send the email.
			return wp_mail( $recipient, $subject, $message, $headers );
		}
		return false;
    }

	public static function send_email_comment_block( $email = '', $user_ip = '', $domain = '', $disposable_email = '', $proxy_vpn = '', $device_id = '' ) {
		// BM-0120 provider path; only missing provider retains the frozen legacy body.
		if ( WC_Blacklist_Notification_Provider::present() ) {
			return WC_Blacklist_Notification_Provider::signal( 'comment.blocked', array_keys( array_filter( array( 'email' => ! empty( $email ), 'ip' => ! empty( $user_ip ), 'domain' => ! empty( $domain ), 'disposable_email' => ! empty( $disposable_email ), 'proxy_vpn' => ! empty( $proxy_vpn ), 'device' => ! empty( $device_id ) ) ) ) );
		}

		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if ( !$premium_active || 'yes' !== get_option( 'wc_blacklist_email_comment_block', 'no' ) ) {
			return;
		}

		if ( empty( $email ) 
		  && empty( $user_ip ) 
		  && empty( $domain ) 
		  && empty( $disposable_email )
		  && empty( $proxy_vpn )
		  && empty( $device_id ) ) {
			return;
		}
	
		// Update our static storage with non-empty values.
		if ( ! empty( $email ) ) {
			self::$block_data['email'] = $email;
		}
		if ( ! empty( $user_ip ) ) {
			self::$block_data['user_ip'] = $user_ip;
		}
		if ( ! empty( $domain ) ) {
			self::$block_data['domain'] = $domain;
		}
		if ( ! empty( $disposable_email ) ) {
			self::$block_data['disposable_email'] = $disposable_email;
		}
		if ( ! empty( $proxy_vpn ) ) {
			self::$block_data['proxy_vpn'] = $proxy_vpn;
		}
		if ( ! empty( $device_id ) ) {
			self::$block_data['device_id'] = $device_id;
		}
	
		// Schedule sending the email once per request.
		if ( ! self::$email_scheduled ) {
			add_action( 'shutdown', [ __CLASS__, 'send_merged_email_block_comment' ] );
			self::$email_scheduled = true;
		}
	}	

	public static function send_merged_email_block_comment() {
		// BM-0120 provider path; legacy flush is exclusive to a missing provider.
		if ( WC_Blacklist_Notification_Provider::present() ) { return WC_Blacklist_Notification_Provider::flush(); }

		// Retrieve sender and recipient settings.
		$sender_name    = get_option( 'wc_blacklist_sender_name' );
		$sender_address = get_option( 'wc_blacklist_sender_address' );
		$recipient      = get_option( 'wc_blacklist_email_recipient' );
		$footer_text    = get_option( 'wc_blacklist_email_footer_text' );
		
		$subject = __( 'A user commentation has been blocked', 'wc-blacklist-manager' );

        // Build email content based on merged suspect data.
        $content = __( 'A user tried to submit a comment with blocked data:', 'wc-blacklist-manager' ) . '<br><br>';
        if (!empty(self::$block_data['email'])) {
            $content .= '• ' . sprintf(__('Blocked email: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['email'])) . '<br>';
        }
        if (!empty(self::$block_data['user_ip'])) {
            $content .= '• ' . sprintf(__('Blocked IP: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['user_ip'])) . '<br>';
        }
		if (!empty(self::$block_data['domain'])) {
            $content .= '• ' . sprintf(__('Blocked domain: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['domain'])) . '<br>';
        }
		if (!empty(self::$block_data['disposable_email'])) {
            $content .= '• ' . sprintf(__('Disposable email: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['disposable_email'])) . '<br>';
        }
		if (!empty(self::$block_data['proxy_vpn'])) {
            $content .= '• ' . sprintf(__('Proxy or VPN: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['proxy_vpn'])) . '<br>';
        }
		if (!empty(self::$block_data['device_id'])) {
            $content .= '• ' . sprintf(__('Device ID: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['device_id'])) . '<br>';
        }

        // If no suspect data was accumulated, don't send an email.
        if (empty($content)) {
            return;
        }

        // Load the HTML email template.
		$template_path = plugin_dir_path( __FILE__ ) . '../../emails/templates/default.html';
		if ( file_exists( $template_path ) ) {
			$template = file_get_contents( $template_path );
	
			// Replace template placeholders.
			$heading = __( 'Commentation was blocked!', 'wc-blacklist-manager' );
			$message = str_replace(
				array( '{{heading}}', '{{content}}', '{{footer}}' ),
				array( $heading, $content, $footer_text ),
				$template
			);
	
			// Configure email headers.
			$headers = array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $sender_name . ' <' . $sender_address . '>',
			);
	
			// Send the email.
			return wp_mail( $recipient, $subject, $message, $headers );
		}
		return false;
    }
}