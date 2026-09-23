<?php
/** Fixed typed projection: arbitrary maps/objects/HTML never cross this boundary. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Context {
	public static function project( array $descriptor, $input ) {
		if ( ! is_array( $input ) || count( $input ) > 16 ) { return false; }
		$out = array();
		foreach ( $descriptor['fields'] as $field ) {
			if ( ! isset( $input[ $field ] ) ) { continue; }
			$v = $input[ $field ];
			if ( in_array( $field, array( 'count', 'timestamp', 'order_id', 'usage_used', 'usage_limit', 'usage_cycle_end', 'week_start', 'week_end', 'protection_records', 'suspicious_records', 'manual_block_records', 'manual_suspect_records', 'manual_remove_records' ), true ) ) {
				if ( ! is_int( $v ) || $v < 0 || $v > 2147483647 || ( 'order_id' === $field && 0 === $v ) ) { return false; }
				$out[ $field ] = $v;
			} elseif ( 'week_timezone' === $field ) {
				if ( ! is_string( $v ) || ! preg_match( '/^[A-Za-z0-9_+:.\/-]{1,64}$/D', $v ) ) { return false; }
				try { new DateTimeZone( $v ); } catch ( Throwable $e ) { return false; }
				$out[$field] = $v;
			} elseif ( 'sample_id' === $field ) {
				if ( ! is_string( $v ) || ! preg_match( '/^[a-f0-9]{32}$/D', $v ) ) { return false; }
				$out[ $field ] = $v;
			} elseif ( 'reasons' === $field ) {
				if ( ! is_array( $v ) || count( $v ) > 16 ) { return false; }
				$out[ $field ] = array();
				foreach ( $v as $reason ) {
					if ( ! is_string( $reason ) || ! isset( $descriptor['reasons'][ $reason ] ) ) { return false; }
					$out[ $field ][ $reason ] = $reason;
				}
				$out[ $field ] = array_values( $out[ $field ] );
			} else {
				if ( ! is_string( $v ) || strlen( $v ) > 254 ) { return false; }
				$hint = self::mask( $field, $v );
				if ( '' !== $hint ) { $out[ $field ] = $hint; }
			}
		}
		if ( in_array( 'usage_limit', $descriptor['fields'], true ) && ( ! isset( $out['timestamp'], $out['reasons'], $out['usage_used'], $out['usage_limit'], $out['usage_cycle_end'] ) || $out['usage_limit'] <= 0 || $out['usage_cycle_end'] <= $out['timestamp'] ) ) { return false; }
		if ( in_array( 'week_start', $descriptor['fields'], true ) ) {
			$slot = 'WC_Blacklist_Notification_Digest';
			if ( class_exists( 'WC_Blacklist_Notification_Audit_Digest' ) && WC_Blacklist_Notification_Audit_Digest::descriptor( $descriptor ) ) { $slot = 'WC_Blacklist_Notification_Audit_Digest'; }
			if ( ! $slot::descriptor( $descriptor ) || array_keys( $out ) !== $slot::fields() || $out['reasons'] !== $slot::REASONS ) { return false; }
			$total = 0;
			foreach ( $slot::COUNTS as $field ) { $total += $out[$field]; }
			if ( $total > 10000 || $total < 1 ) { return false; }
			$week = $slot::calendar( $out['week_end'], new DateTimeZone( $out['week_timezone'] ) );
			if ( $out['week_start'] !== $week['start'] || $out['week_end'] !== $week['end'] ) { return false; }
		}
		return strlen( wp_json_encode( $out ) ) <= 4096 ? $out : false;
	}

	private static function mask( $field, $v ) {
		if ( 'email' === $field ) {
			return is_email( $v ) && strpos( $v, '@' ) > 1 ? substr( $v, 0, 1 ) . '***' : '';
		}
		if ( 'phone' === $field ) {
			if ( ! preg_match( '/^\+?[0-9 ()-]{7,30}$/D', $v ) ) { return ''; }
			$digits = preg_replace( '/\D/', '', $v );
			return strlen( $digits ) >= 7 ? '***' . substr( $digits, -2 ) : '';
		}
		if ( 'ip' === $field && filter_var( $v, FILTER_VALIDATE_IP ) ) {
			$bytes = inet_pton( $v );
			return 4 === strlen( $bytes ) ? inet_ntop( substr( $bytes, 0, 3 ) . "\0" ) . '/24' : inet_ntop( substr( $bytes, 0, 6 ) . str_repeat( "\0", 10 ) ) . '/48';
		}
		return '';
	}

	public static function merge( array $old, array $new ) {
		// A repeated key may enrich reasons, but must never mix order identities.
		if ( isset( $old['order_id'], $new['order_id'] ) && $old['order_id'] !== $new['order_id'] ) { return false; }
		if ( isset( $old['sample_id'], $new['sample_id'] ) && $old['sample_id'] !== $new['sample_id'] ) { return false; }
		if ( isset( $old['count'], $new['count'] ) ) { $new['count'] = max( $old['count'], $new['count'] ); }
		if ( isset( $old['reasons'], $new['reasons'] ) ) { $new['reasons'] = array_values( array_unique( array_merge( $old['reasons'], $new['reasons'] ) ) ); }
		return array_merge( $old, $new );
	}
}
