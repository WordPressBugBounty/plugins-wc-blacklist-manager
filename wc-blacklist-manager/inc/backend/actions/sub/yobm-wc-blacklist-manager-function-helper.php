<?php

if (!defined('ABSPATH')) {
	exit;
}

// Additional functions here

// Prevent registration notice
function wc_blacklist_add_registration_notice($errors) {
    $error_code = 'registration_blocked'; // Common error code for blocking
    // Check if the error with this code already exists
    if (!$errors->get_error_message($error_code)) {
        $registration_notice = get_option('wc_blacklist_registration_notice', __('You have been blocked from registering. Think it is a mistake? Contact the administrator.', 'wc-blacklist-manager'));
        $errors->add($error_code, $registration_notice);
    }
}