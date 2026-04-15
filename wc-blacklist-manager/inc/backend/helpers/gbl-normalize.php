<?php

if (!defined('ABSPATH')) {
	exit;
}

if ( ! function_exists( 'yobm_gbl_normalize_email' ) ) {
	/**
	 * GBL-only canonical email contract.
	 * Safer than reusing legacy local blacklist normalization.
	 *
	 * Rules:
	 * - trim + lowercase
	 * - IDN domain -> ASCII
	 * - googlemail.com -> gmail.com
	 * - Gmail only: strip +tag and dots from local-part
	 * - non-Gmail providers: keep local-part unchanged
	 */
	function yobm_gbl_normalize_email( $email ) {
		$email = is_string( $email ) ? trim( $email ) : '';

		if ( '' === $email ) {
			return '';
		}

		$email = yobm_norm_str( $email );

		$parts = explode( '@', $email, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}

		$local  = (string) $parts[0];
		$domain = (string) $parts[1];

		if ( '' === $local || '' === $domain ) {
			return '';
		}

		if ( function_exists( 'idn_to_ascii' ) ) {
			if ( defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
				$ascii = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			} else {
				$ascii = idn_to_ascii( $domain );
			}

			if ( is_string( $ascii ) && '' !== $ascii ) {
				$domain = strtolower( $ascii );
			}
		}

		if ( 'googlemail.com' === $domain ) {
			$domain = 'gmail.com';
		}

		if ( 'gmail.com' === $domain ) {
			$local = preg_replace( '/\+.*$/', '', $local );
			$local = str_replace( '.', '', $local );
		}

		$normalized = $local . '@' . $domain;

		return is_email( $normalized ) ? $normalized : '';
	}
}

if ( ! function_exists( 'yobm_gbl_normalize_domain' ) ) {
	function yobm_gbl_normalize_domain( $domain ) {
		$domain = is_string( $domain ) ? trim( $domain ) : '';

		if ( '' === $domain ) {
			return '';
		}

		$domain = yobm_norm_str( $domain );
		$domain = preg_replace( '/^www\./', '', $domain );
		$domain = trim( (string) $domain, ". \t\n\r\0\x0B" );

		if ( '' === $domain ) {
			return '';
		}

		if ( function_exists( 'idn_to_ascii' ) ) {
			if ( defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
				$ascii = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			} else {
				$ascii = idn_to_ascii( $domain );
			}

			if ( is_string( $ascii ) && '' !== $ascii ) {
				$domain = strtolower( $ascii );
			}
		}

		if ( false === strpos( $domain, '.' ) ) {
			return '';
		}

		return $domain;
	}
}

if ( ! function_exists( 'yobm_gbl_normalize_phone' ) ) {
	/**
	 * GBL-only canonical phone contract.
	 * Returns digits-only. Caller should prepend '+' on the wire.
	 */
	function yobm_gbl_normalize_phone( $phone, $dial_code = '' ) {
		$phone     = sanitize_text_field( wp_unslash( $phone ) );
		$dial_code = sanitize_text_field( wp_unslash( $dial_code ) );

		if ( '' === $phone ) {
			return '';
		}

		$phone = trim( $phone );

		// Keep only digits and '+'.
		$phone = preg_replace( '/[^\d+]+/u', '', $phone );
		if ( '' === $phone ) {
			return '';
		}

		// Convert 00 international prefix to +.
		if ( 0 === strpos( $phone, '00' ) ) {
			$phone = '+' . substr( $phone, 2 );
		}

		$has_plus = ( '' !== $phone && '+' === $phone[0] );

		// Remove stray + after the first char.
		if ( $has_plus ) {
			$phone = '+' . preg_replace( '/\+/', '', substr( $phone, 1 ) );
		} else {
			$phone = preg_replace( '/\+/', '', $phone );
		}

		$dial_digits = preg_replace( '/\D+/', '', $dial_code );

		if ( $has_plus ) {
			$digits = preg_replace( '/\D+/', '', substr( $phone, 1 ) );
			$digits = ltrim( $digits, '0' );

			if ( strlen( $digits ) < 8 || strlen( $digits ) > 15 ) {
				return '';
			}

			return $digits;
		}

		$digits = preg_replace( '/\D+/', '', $phone );
		$digits = ltrim( $digits, '0' );

		if ( '' === $digits ) {
			return '';
		}

		if ( '' !== $dial_digits ) {
			$digits = ltrim( $dial_digits, '0' ) . $digits;
		}

		if ( strlen( $digits ) < 8 || strlen( $digits ) > 15 ) {
			return '';
		}

		return $digits;
	}
}

if ( ! function_exists( 'yobm_gbl_normalize_ip' ) ) {
	/**
	 * GBL-only canonical IP text.
	 * validate -> inet_pton -> inet_ntop
	 */
	function yobm_gbl_normalize_ip( $ip ) {
		$ip = is_string( $ip ) ? trim( $ip ) : '';

		if ( '' === $ip ) {
			return '';
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$packed = @inet_pton( $ip );
		if ( false === $packed || null === $packed ) {
			return '';
		}

		$canon = @inet_ntop( $packed );
		if ( false === $canon || ! is_string( $canon ) || '' === $canon ) {
			return '';
		}

		return $canon;
	}
}