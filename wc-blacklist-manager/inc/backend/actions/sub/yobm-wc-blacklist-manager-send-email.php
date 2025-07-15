<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Email {
	public function send_email_order_suspect($order_id, $customer_name, $phone, $email, $user_ip, $customer_address, $shipping_address, $order_edit_url) {
		if ( 'yes' !== get_option( 'wc_blacklist_email_notification', 'no' ) ) {
			return;
		}
		// Retrieve sender and recipient settings.
		$sender_name    = get_option( 'wc_blacklist_sender_name' );
		$sender_address = get_option( 'wc_blacklist_sender_address' );
		$recipient      = get_option( 'wc_blacklist_email_recipient' );
		$footer_text    = get_option( 'wc_blacklist_email_footer_text' );
		
		$subject = __( 'Suspected order placement detected', 'wc-blacklist-manager' );

		// Build the email content using HTML formatting.
		$content = __( 'A order placement was attempted with suspicious data:', 'wc-blacklist-manager' ) . '<br><br>';
		if ( ! empty( $customer_name ) ) {
			$content .= '• ' . sprintf(
				__( 'Suspected name: %s', 'wc-blacklist-manager' ),
				$customer_name
			) . '<br>';
		}
		if ( ! empty( $phone ) ) {
			$content .= '• ' . sprintf(
				__( 'Suspected phone: %s', 'wc-blacklist-manager' ),
				$phone
			) . '<br>';
		}
		if ( ! empty( $email ) ) {
			$content .= '• ' . sprintf(
				__( 'Suspected email: %s', 'wc-blacklist-manager' ),
				$email
			) . '<br>';
		}
		if ( ! empty( $user_ip ) ) {
			$content .= '• ' . sprintf(
				__( 'Suspected IP address: %s', 'wc-blacklist-manager' ),
				$user_ip
			) . '<br>';
		}
		if ( ! empty( $customer_address ) ) {
			$content .= '• ' . sprintf(
				__( 'Suspected billing address: %s', 'wc-blacklist-manager' ),
				$customer_address
			) . '<br>';
		}
		if ( ! empty( $shipping_address ) ) {
			$content .= '• ' . sprintf(
				__( 'Suspected shipping address: %s', 'wc-blacklist-manager' ),
				$shipping_address
			) . '<br>';
		}
		
		// Load the HTML email template.
		$template_path = plugin_dir_path( __FILE__ ) . '../../emails/templates/order.html';
		if ( file_exists( $template_path ) ) {
			$template = file_get_contents( $template_path );
	
			// Replace template placeholders.
			$heading = sprintf( __( 'Suspicious order: #%s', 'wc-blacklist-manager' ), $order_id );
			$view_button_text = __( 'View order', 'wc-blacklist-manager' );
			$message = str_replace(
				array( '{{heading}}', '{{content}}', '{{edit_order_url}}', '{{view_button_text}}', '{{footer}}' ),
				array( $heading, $content, $order_edit_url, $view_button_text, $footer_text ),
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
		$proxy_vpn = ''
		) {
		if ( 'yes' !== get_option( 'wc_blacklist_email_blocking_notification', 'no' ) ) {
			return;
		}
		
        // Update our static storage with non-empty values.
        if (!empty($phone)) {
            self::$block_data['phone'] = $phone;
        }
        if (!empty($email)) {
            self::$block_data['email'] = $email;
        }
        if (!empty($user_ip)) {
            self::$block_data['user_ip'] = $user_ip;
        }
		if (!empty($domain)) {
            self::$block_data['domain'] = $domain;
        }
		if (!empty($customer_name)) {
            self::$block_data['customer_name'] = $customer_name;
        }
		if (!empty($billing)) {
            self::$block_data['billing'] = $billing;
        }
		if (!empty($shipping)) {
            self::$block_data['shipping'] = $shipping;
        }
		if (!empty($disposable_phone)) {
            self::$block_data['disposable_phone'] = $disposable_phone;
        }
		if (!empty($disposable_email)) {
            self::$block_data['disposable_email'] = $disposable_email;
        }
		if (!empty($proxy_vpn)) {
            self::$block_data['proxy_vpn'] = $proxy_vpn;
        }

        // Schedule sending the email once per request.
        if (!self::$email_scheduled) {
            add_action('shutdown', array(__CLASS__, 'send_merged_email'));
            self::$email_scheduled = true;
        }
    }

    /**
     * Build and send the merged email.
     */
    public static function send_merged_email() {
		// Check if all suspect values are empty.
		$all_empty = true;
		foreach (self::$block_data as $value) {
			if (!empty($value)) {
				$all_empty = false;
				break;
			}
		}
		if ($all_empty) {
			return;
		}
		
		// Retrieve sender and recipient settings.
		$sender_name    = get_option( 'wc_blacklist_sender_name' );
		$sender_address = get_option( 'wc_blacklist_sender_address' );
		$recipient      = get_option( 'wc_blacklist_email_recipient' );
		$footer_text    = get_option( 'wc_blacklist_email_footer_text' );
		
		$subject = __( 'An order placement has been blocked', 'wc-blacklist-manager' );

        // Build email content based on merged suspect data.
        $content = __( 'A customer tried to place an order with blocked data:', 'wc-blacklist-manager' ) . '<br><br>';
		if (!empty(self::$block_data['customer_name'])) {
            $content .= '• ' . sprintf(__('Blocked name: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['customer_name'])) . '<br>';
        }
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
		if (!empty(self::$block_data['billing'])) {
            $content .= '• ' . sprintf(__('Blocked billing address: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['billing'])) . '<br>';
        }
		if (!empty(self::$block_data['shipping'])) {
            $content .= '• ' . sprintf(__('Blocked shipping address: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['shipping'])) . '<br>';
        }
		if (!empty(self::$block_data['disposable_phone'])) {
            $content .= '• ' . sprintf(__('Disposable phone: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['disposable_phone'])) . '<br>';
        }
		if (!empty(self::$block_data['disposable_email'])) {
            $content .= '• ' . sprintf(__('Disposable email: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['disposable_email'])) . '<br>';
        }
		if (!empty(self::$block_data['proxy_vpn'])) {
            $content .= '• ' . sprintf(__('Proxy or VPN: %s', 'wc-blacklist-manager'), esc_html(self::$block_data['proxy_vpn'])) . '<br>';
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
			$heading = __( 'Order was blocked!', 'wc-blacklist-manager' );
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
	// RESGITRATION EMAIL
	//
	public static function send_email_registration_suspect( $phone = '', $email = '', $user_ip = '' ) {
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

	public static function send_email_registration_block( $phone = '', $email = '', $user_ip = '', $domain = '', $disposable_email = '', $proxy_vpn = '' ) {
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
		  && empty( $proxy_vpn ) ) {
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
	
		// Schedule sending the email once per request.
		if ( ! self::$email_scheduled ) {
			add_action( 'shutdown', [ __CLASS__, 'send_merged_email_block_registration' ] );
			self::$email_scheduled = true;
		}
	}	

	public static function send_merged_email_block_registration() {
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

	public static function send_email_comment_block( $email = '', $user_ip = '', $domain = '', $disposable_email = '', $proxy_vpn = '' ) {
		$settings_instance = new WC_Blacklist_Manager_Settings();
		$premium_active = $settings_instance->is_premium_active();

		if ( !$premium_active || 'yes' !== get_option( 'wc_blacklist_email_comment_block', 'no' ) ) {
			return;
		}

		if ( empty( $email ) 
		  && empty( $user_ip ) 
		  && empty( $domain ) 
		  && empty( $disposable_email )
		  && empty( $proxy_vpn ) ) {
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
	
		// Schedule sending the email once per request.
		if ( ! self::$email_scheduled ) {
			add_action( 'shutdown', [ __CLASS__, 'send_merged_email_block_comment' ] );
			self::$email_scheduled = true;
		}
	}	

	public static function send_merged_email_block_comment() {
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