<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('Yo_Ohw_SMS_Quota_Update')) {
	class Yo_Ohw_SMS_Quota_Update {
		public function __construct() {
			add_action('rest_api_init', array($this, 'register_api_routes'));
		}

		public function register_api_routes() {
			register_rest_route('yoohw-sms/v1', '/update-sms-quota', array(
				'methods'  => 'POST',
				'callback' => array($this, 'update_sms_quota'),
				'permission_callback' => '__return_true',
			));
		}

		public function update_sms_quota(WP_REST_Request $request) {
			$sms_key  = sanitize_text_field($request->get_param('sms_key'));
			$new_quota = floatval($request->get_param('new_quota'));

			$stored_sms_key = get_option('yoohw_phone_verification_sms_key');

			if ($sms_key === $stored_sms_key) {
				update_option('yoohw_phone_verification_sms_quota', $new_quota);

				return rest_ensure_response(array(
					'status'    => 'success',
					'message'   => 'Quota updated successfully.',
					'sms_key'   => $sms_key,
					'new_quota' => $new_quota,
				));
			} else {
				return rest_ensure_response(array(
					'status'  => 'error',
					'message' => 'SMS key does not match.',
				));
			}
		}
	}

	new Yo_Ohw_SMS_Quota_Update();
}

/**
 * Update 'yoohw_phone_verification_sms_quota' from 'wc_blacklist_phone_verification_sms_quota' on plugin update,
 * only when this plugin is updated, and delete the 'wc_blacklist_phone_verification_sms_quota' option after updating.
 */
function yoohw_update_sms_quota_on_plugin_update($upgrader_object, $options) {
	if ($options['action'] === 'update' && $options['type'] === 'plugin') {
		$updated_plugins = $options['plugins'];

		if (in_array('wc-blacklist-manager/wc-blacklist-manager.php', $updated_plugins)) {
			$existing_quota = get_option('wc_blacklist_phone_verification_sms_quota', false);

			if ($existing_quota !== false) {
				update_option('yoohw_phone_verification_sms_quota', $existing_quota);
				delete_option('wc_blacklist_phone_verification_sms_quota');
			}
		}
	}
}

add_action('upgrader_process_complete', 'yoohw_update_sms_quota_on_plugin_update', 10, 2);
