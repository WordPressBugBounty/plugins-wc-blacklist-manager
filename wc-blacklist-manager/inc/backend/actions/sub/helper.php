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

/**
 * Get dial code by ISO country from file data/phone_country_codes.conf
 * Expected format per line: "US:+1" or "US:1"
 * Ignores blank lines and lines starting with '#'
 */
function yobm_get_country_code_from_file( $country_code ) {
    static $map = null;

    if ($map === null) {
        $map = [];
        $file_path = plugin_dir_path(__FILE__) . '../data/phone_country_codes.conf';
        if ( file_exists($file_path) && is_readable($file_path) ) {
            $content = file_get_contents($file_path);
            foreach (explode("\n", (string) $content) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (strpos($line, ':') === false) continue;
                list($cc, $code) = array_map('trim', explode(':', $line, 2));
                if ($cc !== '') {
                    $map[strtoupper($cc)] = ltrim($code, '+'); // store digits (no '+')
                }
            }
        }
    }

    $country_code = strtoupper(trim((string) $country_code));
    return $map[$country_code] ?? null;
}
