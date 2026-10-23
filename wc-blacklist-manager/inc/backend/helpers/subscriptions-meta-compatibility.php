<?php
/** Keep per-order evidence out of native WCS subscription and renewal copies. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wc_blacklist_manager_filter_subscription_meta( $data, $to_object, $from_object ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	static $excluded = array(
		'_wc_blacklist_suspect_check_scheduled',
		'_wc_blacklist_suspect_check_completed',
		'_wc_bm_device_version',
		'_wc_bm_device_id',
		'_wc_bm_device_browser_id',
		'_wc_bm_device_fp_hash',
		'_wc_bm_device_confidence',
		'_wc_bm_device_flags',
		'_wc_bm_device_payload_valid',
		'_wc_bm_device_validation_reasons',
		'_wc_bm_session_id',
		'_verified_email',
		'_verified_phone',
		'_wc_blacklist_manager_email_proof_id',
		'_wc_blacklist_manager_email_evidence_v1',
		'_wc_blacklist_manager_phone_proof_id',
		'_wc_blacklist_manager_phone_evidence_v1',
		'_yobm_admin_new_order_email_sent',
		'_yogb_gbl_blocked',
		'_yogb_gbl_challenged',
		'_yogb_gbl_check_attempts',
		'_yogb_gbl_check_last_error',
		'_yogb_gbl_check_last_http_code',
		'_yogb_gbl_check_next_retry_at',
		'_yogb_gbl_check_started_at',
		'_yogb_gbl_check_status',
		'_yogb_gbl_checked',
		'_yogb_gbl_decision',
		'_yogb_gbl_decision_at',
		'_yogb_gbl_decision_reason_code',
		'_yogb_gbl_decision_ref',
		'_yogb_gbl_decision_summary',
		'_yogb_gbl_detail_available',
		'_yogb_gbl_direct_score',
		'_yogb_gbl_effective_score',
		'_yogb_gbl_linked_boost',
		'_yogb_gbl_linked_neighbors_count',
		'_yogb_gbl_match_summary_v2',
		'_yogb_gbl_matched_identities',
		'_yogb_gbl_matched_identity_nodes',
		'_yogb_gbl_primary_last_reported',
		'_yogb_gbl_primary_match_mode',
		'_yogb_gbl_primary_matched_identity_count',
		'_yogb_gbl_primary_matched_variant',
		'_yogb_gbl_primary_risk_level',
		'_yogb_gbl_primary_signal_type',
		'_yogb_gbl_raw',
		'_yogb_gbl_reason_summaries',
		'_yogb_gbl_reasons',
		'_yogb_gbl_report_summaries',
		'_yogb_gbl_response_schema',
		'_yogb_gbl_signal_summaries',
		'_yogb_gbl_storage_profile',
		'_yogb_gbl_tier',
		'_yogb_gbl_outcome_at',
		'_yogb_gbl_outcome_attempts',
		'_yogb_gbl_outcome_conclusion',
		'_yogb_gbl_outcome_decision_ref',
		'_yogb_gbl_outcome_delivery',
		'_yogb_gbl_outcome_error',
		'_yogb_gbl_outcome_event_uuid',
		'_yogb_gbl_outcome_evidence',
		'_yogb_gbl_outcome_http_code',
		'_yogb_gbl_outcome_next_at',
		'_yogb_gbl_outcome_reason',
		'_yogb_gbl_outcome_review_status',
		'_yogb_gbl_outcome_revision',
		'_yogb_gbl_outcome_schema',
		'_yogb_gbl_outcome_signature',
		'_yogb_gbl_outcome_type',
		'_yogb_gbl_outcome_user_id',
		'_yogb_evidence_hash_v1',
		'_yogb_evidence_noted',
		'_yogb_evidence_snapshot_v1',
		'_yogb_gbl_report_ids',
		'_yogb_gbl_evidence_reference_candidates',
		'_yogb_gbl_compact_cleanup_checked',
	);
	foreach ( $excluded as $key ) {
		unset( $data[ $key ] );
	}
	// This one dynamic key is bound to a UUID-v4 delivery event.
	foreach ( array_keys( $data ) as $key ) {
		if ( preg_match( '/^_yogb_gbl_outcome_delivery_state_[a-f0-9]{12}4[a-f0-9]{3}[89ab][a-f0-9]{15}$/D', (string) $key ) ) {
			unset( $data[ $key ] );
		}
	}
	return wc_blacklist_manager_filter_subscription_dispatch_meta( $data, $to_object, $from_object );
}

function wc_blacklist_manager_filter_subscription_dispatch_meta( $data, $to_object, $from_object ) {
	if ( ! is_array( $data ) ) { return $data; }
	foreach ( [ 'sent', 'coordinated', 'recover_at', 'attempt', 'attempts', 'diagnostic' ] as $suffix ) {
		unset( $data[ '_yobm_admin_new_order_email_' . $suffix ] );
	}
	return $data;
}

add_filter( 'wc_subscriptions_subscription_data', 'wc_blacklist_manager_filter_subscription_meta', 10, 3 );
add_filter( 'wc_subscriptions_renewal_order_data', 'wc_blacklist_manager_filter_subscription_meta', 10, 3 );

add_filter( 'wc_subscriptions_resubscribe_order_data', 'wc_blacklist_manager_filter_subscription_dispatch_meta', 10, 3 );
