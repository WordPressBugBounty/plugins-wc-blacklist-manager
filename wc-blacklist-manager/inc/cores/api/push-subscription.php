<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Blacklist_Manager_Push_Subscription {
    /** REST endpoint of your central subscriptions service */
    const SUBSCRIBE_API_ENDPOINT = 'https://express.yoohw.com/wp-json/yo-pr/v1/email-subscriptions';

    /** Option name to store last run timestamp */
    const OPTION_LAST_PUSH = 'wc_blacklist_manager_last_push_subscription';

    /** How often we allow a push (7 days) */
    const PUSH_INTERVAL   = WEEK_IN_SECONDS;

    /**
     * Decide whether to fire a subscription push.
     */
    public static function maybe_push_subscription() {

        $last = (int) get_option( self::OPTION_LAST_PUSH, 0 );
        $now  = time();

        // Avoid hammering: only once per 7 days.
        if ( $last && ( $now - $last ) < self::PUSH_INTERVAL ) {
            return;
        }

        // Record timestamp before firing to avoid loops even if the call fails.
        update_option( self::OPTION_LAST_PUSH, $now );

        self::push_subscription();
    }

    /**
     * Gather site info and POST to Yo Email Subscriptions.
     */
    public static function push_subscription() {
        // 1) Collect and dedupe emails
        $emails = [];

        // site admin email
        $emails[] = get_option( 'admin_email' );

        // extra recipients
        if ( $rec = get_option( 'wc_blacklist_email_recipient', '' ) ) {
            $emails = array_merge( $emails, array_map( 'trim', explode( ',', $rec ) ) );
        }
        if ( $rec2 = get_option( 'woocommerce_new_order_recipient', '' ) ) {
            $emails = array_merge( $emails, array_map( 'trim', explode( ',', $rec2 ) ) );
        }

        // sanitize & keep only valid, unique
        $emails = array_filter( array_map( 'sanitize_email', $emails ), 'is_email' );
        $emails = array_unique( $emails );

        if ( empty( $emails ) ) {
            return; // nothing to send, skip HTTP call
        }

        $email_param = implode( ',', $emails );

        // 2) Source = bare domain
        $host = parse_url( home_url(), PHP_URL_HOST );
        $host = preg_replace( '#^www\.#i', '', $host );

        // 3) Product code
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
        if ( is_plugin_active( 'blacklist-manager-premium-for-forms/blacklist-manager-premium-for-forms.php' ) ) {
            $product_code = 'bmpff';
        } elseif ( is_plugin_active( 'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' ) ) {
            $product_code = 'bmp';
        } else {
            $product_code = 'bm';
        }
		$products = [ $product_code ];

        // 4) Country
        $country = get_option( 'woocommerce_default_country', '' );

        // 5) Fire the request
        $args = [
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
            ],
            'body'     => wp_json_encode( [
                'email'    => $email_param,
                'source'   => $host,
                'products' => $products,
                'country'  => $country,
            ] ),
            'timeout'  => 3,          // don’t let it hang too long
            'blocking' => false,      // <– critical: don’t block page load
        ];

        wp_remote_post( self::SUBSCRIBE_API_ENDPOINT, $args );

        // Optional: only log in debug mode, to avoid noisy logs on 400s
        /*
        if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'WC Blacklist Manager subscription error: ' . $response->get_error_message() );
        }
        */

    }
}
