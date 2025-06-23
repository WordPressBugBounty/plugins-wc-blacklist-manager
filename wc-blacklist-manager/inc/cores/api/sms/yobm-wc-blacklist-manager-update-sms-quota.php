<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('Yo_Ohw_SMS_Quota_Update')) {
	class Yo_Ohw_SMS_Quota_Update {
		public function __construct() {
			add_action( 'rest_api_init', [ $this, 'register_api_routes' ] );
		}

		public function register_api_routes() {
			register_rest_route( 'yoohw-sms/v1', '/update-sms-quota', [
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_sms_quota' ],
				'permission_callback' => [ $this, 'quota_permission' ],
			] );
		}

		/**
		 * Allow if either:
		 *  - The posted sms_key matches the stored secret (old clients)
		 *  - OR the Origin header is your bmc.yoohw.com dashboard (new clients)
		 */
		public function quota_permission( WP_REST_Request $request ) {
			// 1) secret-key check
			$sms_key = sanitize_text_field( $request->get_param( 'sms_key' ) );
			$stored  = get_option( 'yoohw_phone_verification_sms_key', '' );
			if ( $sms_key === $stored ) {
				return true;
			}

			// 2) dashboard-origin check
			$origin = $request->get_header( 'origin' );
			if ( $origin === 'https://bmc.yoohw.com' ) {
				return true;
			}

			return new WP_Error(
				'rest_forbidden',
				'You are not allowed to call this endpoint.',
				[ 'status' => 403 ]
			);
		}

		public function update_sms_quota( WP_REST_Request $request ) {
			$sms_key   = sanitize_text_field( $request->get_param( 'sms_key' ) );
			$new_quota = floatval( $request->get_param( 'new_quota' ) );
			$stored    = get_option( 'yoohw_phone_verification_sms_key' );

			if ( $sms_key !== $stored ) {
				return rest_ensure_response([
					'status'  => 'error',
					'message' => 'SMS key does not match.',
				]);
			}

			update_option( 'yoohw_phone_verification_sms_quota', $new_quota );

			return rest_ensure_response([
				'status'    => 'success',
				'message'   => 'Quota updated successfully.',
				'sms_key'   => $sms_key,
				'new_quota' => $new_quota,
			]);
		}
	}

	new Yo_Ohw_SMS_Quota_Update();
}
