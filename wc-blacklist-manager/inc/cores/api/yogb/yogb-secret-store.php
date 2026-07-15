<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Transparent encryption for the Global Blacklist API secret option. */
final class YOGB_BM_Secret_Store {
	const OPTION = 'yogb_bm_api_secret';
	const PREFIX = 'yogb_enc1:';

	public static function init() : void {
		add_filter( 'pre_update_option_' . self::OPTION, [ __CLASS__, 'encrypt_for_storage' ], 10, 2 );
		add_filter( 'pre_add_option_' . self::OPTION, [ __CLASS__, 'encrypt_for_storage' ] );
		add_filter( 'option_' . self::OPTION, [ __CLASS__, 'decrypt_from_storage' ] );
		add_action( 'admin_init', [ __CLASS__, 'migrate_plaintext_option' ], 1 );
	}

	public static function encrypt_for_storage( $value, $old_value = '' ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value || 0 === strpos( $value, self::PREFIX ) ) {
			return $value;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}
		try {
			$key = self::key();
			$iv  = random_bytes( 12 );
			$tag = '';
			$raw = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $raw ? $value : self::PREFIX . base64_encode( $iv . $tag . $raw );
		} catch ( Throwable $e ) {
			return $value;
		}
	}

	public static function decrypt_from_storage( $value ) : string {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return $value;
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$plain = openssl_decrypt(
			substr( $raw, 28 ),
			'aes-256-gcm',
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, 12 ),
			substr( $raw, 12, 16 )
		);
		return is_string( $plain ) ? $plain : '';
	}

	public static function migrate_plaintext_option() : void {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::OPTION ) );
		if ( ! is_string( $raw ) || '' === $raw || 0 === strpos( $raw, self::PREFIX ) ) {
			return;
		}
		$encrypted = self::encrypt_for_storage( $raw );
		if ( $encrypted !== $raw ) {
			$wpdb->update( $wpdb->options, [ 'option_value' => $encrypted ], [ 'option_name' => self::OPTION ], [ '%s' ], [ '%s' ] );
			wp_cache_delete( self::OPTION, 'options' );
		}
	}

	private static function key() : string {
		if ( function_exists( 'wp_salt' ) ) {
			$material = wp_salt( 'auth' );
		} else {
			// Plugins load before pluggable.php defines wp_salt(). AUTH_KEY and
			// AUTH_SALT are the exact default material used by wp_salt('auth').
			$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
		}
		return hash( 'sha256', $material . '|yogb-api-secret-v1', true );
	}
}

YOGB_BM_Secret_Store::init();
