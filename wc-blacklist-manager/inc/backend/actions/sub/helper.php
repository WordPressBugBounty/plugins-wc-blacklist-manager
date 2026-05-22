<?php

if (!defined('ABSPATH')) {
	exit;
}

// Prevent registration notice
function wc_blacklist_add_registration_notice($errors) {
    $error_code = 'registration_blocked'; // Common error code for blocking
    // Check if the error with this code already exists
    if (!$errors->get_error_message($error_code)) {
        $registration_notice = get_option('wc_blacklist_registration_notice', __('You have been blocked from registering. Think it is a mistake? Contact the administrator.', 'wc-blacklist-manager'));
        $errors->add($error_code, $registration_notice);
    }
}

if ( ! function_exists( 'wc_blacklist_manager_clear_match_cache' ) ) {
	function wc_blacklist_manager_clear_match_cache( $row ) {
		if ( is_object( $row ) ) {
			$row = get_object_vars( $row );
		}

		if ( ! is_array( $row ) ) {
			return;
		}

		$ip = isset( $row['ip_address'] ) ? sanitize_text_field( (string) $row['ip_address'] ) : '';
		if ( '' !== $ip ) {
			wp_cache_delete( 'banned_ip_' . md5( $ip ), 'wc_blacklist' );
			wp_cache_delete( 'suspect_ip_' . md5( $ip ), 'wc_blacklist' );
		}

		$domain = isset( $row['domain'] ) ? strtolower( trim( sanitize_text_field( (string) $row['domain'] ) ) ) : '';
		if ( '' !== $domain ) {
			wp_cache_delete( 'banned_domain_' . md5( $domain ), 'wc_blacklist' );
		}
	}
}

if ( ! function_exists( 'wc_blacklist_manager_user_can_manage_area' ) ) {
	function wc_blacklist_manager_user_can_manage_area( $role_option, $require_premium = false ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( $require_premium ) {
			if ( ! class_exists( 'WC_Blacklist_Manager_Settings' ) ) {
				return false;
			}

			$settings_instance = new WC_Blacklist_Manager_Settings();
			if ( ! $settings_instance->is_premium_active() ) {
				return false;
			}
		}

		$allowed_roles = get_option( $role_option, array() );
		if ( ! is_array( $allowed_roles ) || empty( $allowed_roles ) ) {
			return false;
		}

		foreach ( $allowed_roles as $role ) {
			if ( current_user_can( $role ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wc_blacklist_manager_debug_log' ) ) {
	function wc_blacklist_manager_debug_log( $context, $message, $data = array() ) {
		$enabled = (bool) get_option( 'wc_blacklist_debug_logging', false );
		$enabled = (bool) apply_filters( 'wc_blacklist_debug_logging_enabled', $enabled, $context, $message, $data );

		if ( ! $enabled || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$payload = array(
			'context' => sanitize_key( (string) $context ),
			'message' => sanitize_text_field( (string) $message ),
		);

		if ( is_array( $data ) && ! empty( $data ) ) {
			$payload['data'] = array();

			foreach ( $data as $key => $value ) {
				if ( is_scalar( $value ) || null === $value ) {
					$payload['data'][ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
				}
			}
		}

		error_log( '[wc-blacklist-manager] ' . wp_json_encode( $payload ) );
	}
}
