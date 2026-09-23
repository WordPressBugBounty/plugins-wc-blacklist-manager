<?php
/** Read-only resolution of complete administrator-owned delivery settings. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Recipients {
	public static function resolve( $settings = array() ) {
		$name = $settings['wc_blacklist_sender_name'] ?? get_option( 'wc_blacklist_sender_name', get_bloginfo( 'name' ) );
		$from = $settings['wc_blacklist_sender_address'] ?? get_option( 'wc_blacklist_sender_address', get_option( 'admin_email' ) );
		$raw  = $settings['wc_blacklist_email_recipient'] ?? get_option( 'wc_blacklist_email_recipient', get_option( 'admin_email' ) );
		if ( ! is_string( $name ) || ! is_string( $from ) || ! is_string( $raw ) || preg_match( '/[\r\n<>]/', $name ) || preg_match( '/[\r\n]/', $from . $raw ) || ! self::address( trim( $from ) ) ) { return false; }
		// Linear scan: quoted mailbox display names may contain commas. Configuration
		// is administrator-owned; never silently truncate its recipient audience.
		$parts = array(); $start = 0; $quoted = false;
		for ( $i = 0, $length = strlen( $raw ); $i < $length; ++$i ) {
			if ( '\\' === $raw[ $i ] ) { return false; }
			if ( '"' === $raw[ $i ] ) { $quoted = ! $quoted; }
			if ( ',' === $raw[ $i ] && ! $quoted ) { $parts[] = substr( $raw, $start, $i - $start ); $start = $i + 1; }
		}
		if ( $quoted ) { return false; }
		$parts[] = substr( $raw, $start );
		$addresses = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( preg_match( '/^(?:"[^"\\\\<>]+"|[^"<>]+)\s*<([^<>]+)>$/D', $part, $match ) ) { $part = trim( $match[1] ); }
			if ( ! self::address( $part ) ) { return false; }
			$addresses[ strtolower( $part ) ] = $part;
		}
		if ( ! $addresses ) { return false; }
		ksort( $addresses );
		return array( 'to' => array_values( $addresses ), 'from' => trim( $from ), 'name' => sanitize_text_field( $name ) );
	}

	private static function address( $value ) {
		return strlen( $value ) <= 254 && false !== is_email( $value ) && ! preg_match( '/[\s<>]/', $value );
	}
}
