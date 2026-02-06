<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('YoOhw_Debug_Log_Email')) {

	class YoOhw_Debug_Log_Email {
		
		public static function sending( $response, $license_key, $domain, $plugin_id, array $debug = [] ) {
			$status_code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code( $response );
			$body        = is_wp_error($response) ? '' : wp_remote_retrieve_body( $response );

			$site    = home_url();
			$subject = '[YoOhw] License Activation Debug - HTTP ' . $status_code . ' - ' . parse_url($site, PHP_URL_HOST);
			$to      = 'dev@yoohw.co';

			$debug_text = class_exists('YoOhw_HTTP_Debug')
				? YoOhw_HTTP_Debug::as_text([ 'raw_status' => $status_code ])
				: '';

			$message = "Site: {$site}\n";
			$message .= "Product ID: {$plugin_id}\n";
			$message .= "Domain: {$domain}\n";
			$message .= "HTTP Status: {$status_code}\n";
			$message .= "License Key: " . ( substr($license_key,0,6) . '…' . substr($license_key,-4) ) . "\n\n";

			if ( is_wp_error( $response ) ) {
				$message .= "== WP_Error ==\n";
				$message .= "Code: " . $response->get_error_code() . "\n";
				$message .= "Message: " . $response->get_error_message() . "\n";
				$edata = $response->get_error_data();
				if ($edata) $message .= "Data: " . print_r($edata, true) . "\n";
				$message .= "\n";
			} else {
				$headers_arr = wp_remote_retrieve_headers($response);
				$message .= "== Response Headers ==\n" . print_r($headers_arr, true) . "\n";
				$message .= "== Body (excerpt) ==\n" . wp_html_excerpt($body, 1500) . "\n\n";
			}

			if ($debug_text) {
				$message .= "================ DEBUG REPORT ================\n";
				$message .= $debug_text . "\n";
			}
			$message .= "\n== Raw Response ==\n" . print_r( $response, true );

			// (A) ALWAYS store first
			$basename = 'license-debug-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false) . '.log';
			$saved    = function_exists('yoohw_store_debug_blob')
				? yoohw_store_debug_blob( $basename, $message )
				: ['where' => 'unknown', 'path' => '', 'option' => ''];

			// (B) Try email — align From/Return-Path with the sending site domain
			$host = preg_replace('/^www\./i', '', parse_url( home_url(), PHP_URL_HOST ));
			$from_email = 'noreply@' . $host;                    // e.g. noreply@playground.yoohw.com
			$from_name  = 'YoOhw Debug (' . $host . ')';

			// set From for this send only
			$from_filter = function() use ($from_email){ return $from_email; };
			$name_filter = function() use ($from_name){  return $from_name;  };
			add_filter('wp_mail_from', $from_filter);
			add_filter('wp_mail_from_name', $name_filter);

			// set envelope sender/Return-Path (improves DMARC)
			$rt_handler = function($phpmailer) use ($from_email){
				$phpmailer->Sender = $from_email;
			};
			add_action('phpmailer_init', $rt_handler);

			// Build headers (do NOT include a From header here; filters above handle it)
			$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
			// TODO: set a real test inbox you can check
			$headers[] = 'Bcc: yoohw15@gmail.com';

			$to   = 'dev@yoohw.co';
			$sent = wp_mail($to, $subject, $message, $headers);

			// clean up (important so you don't affect other emails)
			remove_filter('wp_mail_from', $from_filter);
			remove_filter('wp_mail_from_name', $name_filter);
			remove_action('phpmailer_init', $rt_handler);

			// (C) Surface where it went in wp-admin so you can’t miss it
			$notice = 'YoOhw debug ';
			$notice .= $sent ? 'email sent.' : 'email failed.';
			if ( ! empty($saved['path']) ) {
				$notice .= ' Copy saved at: ' . esc_html($saved['path']);
			} elseif ( ! empty($saved['option']) ) {
				$notice .= ' Stored in option: ' . esc_html($saved['option']) . ' (use get_option to view).';
			} else {
				$notice .= ' Could not save debug file.';
			}
			add_action('admin_notices', function() use ($notice){
				echo '<div class="notice notice-warning"><p>' . esc_html($notice) . '</p></div>';
			});
		}
	}
}