<?php

if (!defined('ABSPATH')) {
	exit;
}

if ( ! function_exists( 'yobm_norm_str' ) ) {
	function yobm_norm_str( $v ) {
		$v = is_string( $v ) ? $v : '';
		$v = trim( $v );
		$v = preg_replace( '/\s+/u', ' ', $v );

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $v, 'UTF-8' );
		}

		return strtolower( $v );
	}
}

if ( ! function_exists( 'yobm_normalize_email' ) ) {
	/**
	 * Normalize an email for consistent matching.
	 * - Lowercases, trims
	 * - IDN domain -> ASCII
	 * - Strips plus-tags for major providers
	 * - Gmail: removes dots in local-part, folds googlemail.com -> gmail.com
	 * Returns normalized email or '' if invalid.
	 */
	function yobm_normalize_email( $email ) {
		$email = is_string( $email ) ? trim( $email ) : '';

		if ( '' === $email ) {
			return '';
		}

		// Fast fail.
		if ( ! is_email( $email ) ) {
			return '';
		}

		// Split local@domain safely.
		$parts = explode( '@', $email, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}

		$local  = strtolower( $parts[0] );
		$domain = strtolower( $parts[1] );

		// Convert IDN domain to ASCII/Punycode if possible.
		if ( function_exists( 'idn_to_ascii' ) ) {
			if ( defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
				$ascii = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			} else {
				$ascii = idn_to_ascii( $domain );
			}

			if ( $ascii ) {
				$domain = $ascii;
			}
		}

		// Provider-aware normalization.
		$gmail_like = array(
			'gmail.com',
			'googlemail.com',
		);

		$plus_providers = array(
			'gmail.com',
			'googlemail.com',
			'outlook.com',
			'hotmail.com',
			'live.com',
			'yahoo.com',
			'icloud.com',
			'me.com',
			'proton.me',
			'fastmail.com',
		);

		if ( in_array( $domain, $gmail_like, true ) ) {
			// Fold googlemail.com to gmail.com.
			$domain = 'gmail.com';

			// Gmail ignores dots and supports + aliases.
			$local = str_replace( '.', '', $local );
			$local = preg_replace( '/\+.*$/', '', $local );
		} elseif ( in_array( $domain, $plus_providers, true ) ) {
			// Strip +tag for known providers that support it.
			$local = preg_replace( '/\+.*$/', '', $local );
		}

		$normalized = $local . '@' . $domain;

		return is_email( $normalized ) ? $normalized : '';
	}
}

if ( ! function_exists( 'yobm_normalize_phone' ) ) {
	/**
	 * Normalize a phone number into canonical digits-only format.
	 *
	 * Examples:
	 * +1 234-858-489   => 1234858489
	 * 001 234-858-489  => 1234858489
	 * 234-858-489 + +1 => 1234858489
	 *
	 * @param string $phone     Raw phone number.
	 * @param string $dial_code Optional dial code like +1, +84.
	 * @return string
	 */
	function yobm_normalize_phone( $phone, $dial_code = '' ) {
		$phone     = sanitize_text_field( wp_unslash( $phone ) );
		$dial_code = sanitize_text_field( wp_unslash( $dial_code ) );

		if ( '' === $phone ) {
			return '';
		}

		$phone = trim( $phone );

		// Remove common separators.
		$phone = str_replace(
			array( ' ', '-', '.', '(', ')', '/', '\\' ),
			'',
			$phone
		);

		// Convert 00 international prefix to +.
		if ( 0 === strpos( $phone, '00' ) ) {
			$phone = '+' . substr( $phone, 2 );
		}

		$has_plus = ( 0 === strpos( $phone, '+' ) );

		// Keep only digits.
		$digits = preg_replace( '/\D+/', '', $phone );

		if ( '' === $digits ) {
			return '';
		}

		$dial_digits = preg_replace( '/\D+/', '', $dial_code );

		// Full international format already entered.
		if ( $has_plus ) {
			$digits = ltrim( $digits, '0' );

			if ( strlen( $digits ) < 8 || strlen( $digits ) > 15 ) {
				return '';
			}

			return $digits;
		}

		// Local number.
		$digits = ltrim( $digits, '0' );

		if ( '' !== $dial_digits ) {
			$digits = $dial_digits . $digits;
		}

		if ( strlen( $digits ) < 8 || strlen( $digits ) > 15 ) {
			return '';
		}

		return $digits;
	}
}

if ( ! function_exists( 'yobm_get_country_dial_code' ) ) {
	/**
	 * Get WooCommerce country calling code as +X format.
	 *
	 * @param string $country Country code, eg US, VN.
	 * @return string
	 */
	function yobm_get_country_dial_code( $country ) {
		$country = sanitize_text_field( wp_unslash( $country ) );

		if ( '' === $country || ! class_exists( 'WooCommerce' ) || ! WC()->countries ) {
			return '';
		}

		$calling_codes = WC()->countries->get_country_calling_code( $country );

		if ( empty( $calling_codes ) ) {
			return '';
		}

		$first_code = is_array( $calling_codes ) ? reset( $calling_codes ) : $calling_codes;
		$first_code = preg_replace( '/\D+/', '', (string) $first_code );

		return '' !== $first_code ? '+' . $first_code : '';
	}
}