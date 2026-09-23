<?php
/** Internal v1 notification descriptors shared by Core and later Premium migrations. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Registry {
	private $items = array();
	private $frozen = false;

	public function freeze() { $this->frozen = true; }

	public function register( $item ) {
		if ( $this->frozen || ! is_array( $item ) || count( $this->items ) >= 32 ) {
			return false;
		}
		$required = array( 'id', 'version', 'entitlement', 'option', 'subject', 'heading', 'severity', 'fields', 'reasons', 'cooldown' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $item ) ) { return false; }
		}
		if ( ! is_string( $item['id'] ) || ! preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/D', $item['id'] ) || isset( $this->items[ $item['id'] ] )
			|| 1 !== $item['version'] || ! in_array( $item['entitlement'], array( 'free', 'premium' ), true )
			|| ! in_array( $item['severity'], array( 'info', 'warning', 'critical' ), true )
			|| ! in_array( $item['option'], array( 'wc_blacklist_email_notification', 'wc_blacklist_email_blocking_notification', 'wc_blacklist_email_register_suspect', 'wc_blacklist_email_register_block', 'wc_blacklist_email_comment_suspect', 'wc_blacklist_email_comment_block', 'wc_blacklist_email_form_suspect', 'wc_blacklist_email_form_block', 'wc_blacklist_email_global_connection', 'wc_blacklist_email_global_usage', 'wc_blacklist_email_weekly_security_digest', 'wc_blacklist_email_weekly_manual_blacklist_digest' ), true ) ) {
			return false;
		}
		if ( ! in_array( $item['option'], array( 'wc_blacklist_email_notification', 'wc_blacklist_email_blocking_notification', 'wc_blacklist_email_global_connection', 'wc_blacklist_email_global_usage' ), true ) && 'premium' !== $item['entitlement'] ) { return false; }
		if ( in_array( $item['option'], array( 'wc_blacklist_email_global_connection', 'wc_blacklist_email_global_usage' ), true ) && ! in_array( $item, array_merge( WC_Blacklist_Notification_Global_Events::connection(), WC_Blacklist_Notification_Global_Events::usage() ), true ) ) { return false; }
		foreach ( array( 'subject', 'heading' ) as $key ) {
			if ( ! is_string( $item[ $key ] ) || '' === trim( $item[ $key ] ) || strlen( $item[ $key ] ) > 800 || preg_match( '/[\r\n<>]/', $item[ $key ] ) ) { return false; }
		}
		if ( ! is_array( $item['fields'] ) || count( $item['fields'] ) > 8 || array_values( array_unique( $item['fields'], SORT_REGULAR ) ) !== $item['fields'] ) { return false; }
		foreach ( $item['fields'] as $field ) {
			if ( ! in_array( $field, array( 'count', 'timestamp', 'order_id', 'email', 'phone', 'ip', 'reasons', 'sample_id', 'usage_used', 'usage_limit', 'usage_cycle_end', 'week_start', 'week_end', 'week_timezone', 'protection_records', 'suspicious_records', 'manual_block_records', 'manual_suspect_records', 'manual_remove_records' ), true ) ) { return false; }
		}
		$weekly = class_exists( 'WC_Blacklist_Notification_Digest' ) && WC_Blacklist_Notification_Digest::descriptor( $item );
		$audit = class_exists( 'WC_Blacklist_Notification_Audit_Digest' ) && WC_Blacklist_Notification_Audit_Digest::descriptor( $item );
		if ( ( in_array( $item['id'], array( 'premium.digest.weekly_security', 'premium.digest.weekly_manual_blacklist' ), true )
			|| in_array( $item['option'], array( 'wc_blacklist_email_weekly_security_digest', 'wc_blacklist_email_weekly_manual_blacklist_digest' ), true )
			|| in_array( $item['action'] ?? '', array( 'security_activity', 'manual_blacklist_activity' ), true )
			|| array_intersect( $item['fields'], array( 'week_start', 'week_end', 'week_timezone', 'protection_records', 'suspicious_records', 'manual_block_records', 'manual_suspect_records', 'manual_remove_records' ) ) ) && ! $weekly && ! $audit ) { return false; }
		$usage = in_array( $item['id'], array( 'premium.global.usage_75', 'premium.global.usage_90', 'premium.global.usage_limit_reached', 'premium.global.usage_cycle_reset' ), true );
		if ( ( array_intersect( $item['fields'], array( 'usage_used', 'usage_limit', 'usage_cycle_end' ) ) || 'global_usage' === ( $item['action'] ?? '' ) || 'wc_blacklist_email_global_usage' === $item['option'] )
			&& ( ! $usage || 'wc_blacklist_email_global_usage' !== $item['option'] || 'free' !== $item['entitlement'] || 'global_usage' !== ( $item['action'] ?? '' )
				|| $item['fields'] !== array( 'timestamp', 'reasons', 'usage_used', 'usage_limit', 'usage_cycle_end' ) ) ) { return false; }
		if ( ! is_array( $item['reasons'] ) || count( $item['reasons'] ) > 16 ) { return false; }
		foreach ( $item['reasons'] as $key => $label ) {
			if ( ! is_string( $key ) || ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $key ) && ! ( 'premium.global.connection_restored' === $item['id'] && 'authenticated_connection_restored' === $key && 'wc_blacklist_email_global_connection' === $item['option'] && 'free' === $item['entitlement'] ) ) || ! is_string( $label ) || strlen( $label ) > 200 || preg_match( '/[\r\n<>]/', $label ) ) { return false; }
		}
		if ( null !== $item['cooldown'] ) {
			$c = $item['cooldown'];
			if ( ! is_array( $c ) || ! isset( $c['slot'], $c['seconds'] ) || ! is_int( $c['slot'] ) || $c['slot'] < 0 || $c['slot'] > 31 || ! is_int( $c['seconds'] ) || $c['seconds'] < 900 || $c['seconds'] > 86400 ) { return false; }
			foreach ( $this->items as $existing ) {
				if ( null !== $existing['cooldown'] && $existing['cooldown']['slot'] === $c['slot'] ) { return false; }
			}
		}
		if ( array_key_exists( 'action', $item ) ) {
			if ( ! in_array( $item['action'], array( 'global_connection', 'global_usage', 'security_activity', 'manual_blacklist_activity' ), true ) || in_array( 'order_id', $item['fields'], true ) ) { return false; }
			$required[] = 'action';
		}
		$this->items[ $item['id'] ] = array_intersect_key( $item, array_flip( $required ) );
		return true;
	}

	public function get( $id ) {
		return is_string( $id ) && isset( $this->items[ $id ] ) ? $this->items[ $id ] : null;
	}
}
