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

		public function quota_permission( WP_REST_Request $request ) {
			$sms_key = sanitize_text_field( (string) $request->get_param( 'sms_key' ) );
			$stored  = (string) get_option( 'yoohw_phone_verification_sms_key', '' );

			if ( '' !== $stored && hash_equals( $stored, $sms_key ) ) {
				return true;
			}

			return new WP_Error(
				'rest_forbidden',
				'You are not allowed to call this endpoint.',
				[ 'status' => 403 ]
			);
		}

		public function update_sms_quota( WP_REST_Request $request ) {
			$sms_key   = sanitize_text_field( (string) $request->get_param( 'sms_key' ) );
			$new_quota = floatval( $request->get_param( 'new_quota' ) );
			$stored    = (string) get_option( 'yoohw_phone_verification_sms_key', '' );

			if ( '' === $stored || ! hash_equals( $stored, $sms_key ) ) {
				return new WP_Error(
					'rest_forbidden',
					'SMS key does not match.',
					[ 'status' => 403 ]
				);
			}

			update_option( 'yoohw_phone_verification_sms_quota', $new_quota );

			return rest_ensure_response([
				'status'    => 'success',
				'message'   => 'Quota updated successfully.',
				'new_quota' => $new_quota,
			]);
		}
	}

	new Yo_Ohw_SMS_Quota_Update();
}
