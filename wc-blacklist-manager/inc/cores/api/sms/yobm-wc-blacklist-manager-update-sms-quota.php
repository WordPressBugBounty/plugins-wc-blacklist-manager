<?php

if (!defined('ABSPATH')) {
	exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'yoohw-sms/v1', '/update-sms-quota', array(
        'methods'  => 'POST',
        'callback' => 'update_sms_quota',
        'permission_callback' => '__return_true',  // You can add permission checks if needed
    ));
});

/**
 * Callback function to update the SMS quota if the sms_key matches.
 */
function update_sms_quota( WP_REST_Request $request ) {
    // Get the SMS key and new quota from the request
    $sms_key  = sanitize_text_field( $request->get_param( 'sms_key' ) );
    $new_quota = floatval( $request->get_param( 'new_quota' ) );

    // Get the stored phone verification SMS key
    $stored_sms_key = get_option( 'yoohw_phone_verification_sms_key' );

    // Check if the received sms_key matches the stored one
    if ( $sms_key === $stored_sms_key ) {
        // Update the site option with the new quota
        update_option( 'wc_blacklist_phone_verification_sms_quota', $new_quota );

        // Respond with a success message
        return rest_ensure_response( array(
            'status'  => 'success',
            'message' => 'Quota updated successfully.',
            'sms_key' => $sms_key,
            'new_quota' => $new_quota,
        ));
    } else {
        // Respond with an error message if the sms_key does not match
        return rest_ensure_response( array(
            'status'  => 'error',
            'message' => 'SMS key does not match.',
        ));
    }
}
