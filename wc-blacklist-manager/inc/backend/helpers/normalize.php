<?php

if (!defined('ABSPATH')) {
	exit;
}

if ( ! function_exists( 'yobm_norm_str' ) ) {
	function yobm_norm_str( $v ) {
		$v = is_string( $v ) ? $v : '';
		$v = trim( $v );

		if ( '' === $v ) {
			return '';
		}

		if ( function_exists( 'remove_accents' ) ) {
			$v = remove_accents( $v );
		}

		$v = str_replace(
			array(
				"\xC2\xA0", // non-breaking space
				"\xE2\x80\x93", // en dash
				"\xE2\x80\x94", // em dash
				"\xE2\x80\x98", // left single quote
				"\xE2\x80\x99", // right single quote
				"\xE2\x80\x9C", // left double quote
				"\xE2\x80\x9D", // right double quote
			),
			' ',
			$v
		);

		$v = preg_replace( '/\s+/u', ' ', $v );

		if ( function_exists( 'mb_strtolower' ) ) {
			$v = mb_strtolower( $v, 'UTF-8' );
		} else {
			$v = strtolower( $v );
		}

		return trim( $v );
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

if ( ! function_exists( 'yobm_extract_house_number_norm' ) ) {
	function yobm_extract_house_number_norm( $address_line_norm ) {
		$address_line_norm = yobm_normalize_address_tokens( $address_line_norm );

		if ( '' === $address_line_norm ) {
			return '';
		}

		/*
		 * Examples:
		 * 12 king st      => 12
		 * 12a king st     => 12a
		 * 12-a king st    => 12a
		 * 12 a king st    => 12a
		 */
		if ( preg_match( '/^(\d+)\s*[- ]?\s*([a-z])?\b/u', $address_line_norm, $m ) ) {
			$number = isset( $m[1] ) ? $m[1] : '';
			$suffix = isset( $m[2] ) ? $m[2] : '';

			return trim( $number . $suffix );
		}

		return '';
	}
}

if ( ! function_exists( 'yobm_strip_house_number_from_line' ) ) {
	function yobm_strip_house_number_from_line( $address_line_norm ) {
		$address_line_norm = yobm_normalize_address_tokens( $address_line_norm );

		if ( '' === $address_line_norm ) {
			return '';
		}

		$line = preg_replace( '/^(\d+)\s*[- ]?\s*([a-z])?\b\s*/u', '', $address_line_norm );
		$line = preg_replace( '/\s+/u', ' ', (string) $line );
		$line = trim( $line );

		return $line;
	}
}

if ( ! function_exists( 'yobm_build_street_name_norm' ) ) {
	function yobm_build_street_name_norm( $address_line_norm ) {
		$core = yobm_build_address_core_norm( $address_line_norm );

		if ( '' === $core ) {
			return '';
		}

		return yobm_strip_house_number_from_line( $core );
	}
}

if ( ! function_exists( 'yobm_build_address_premise_norm' ) ) {
	function yobm_build_address_premise_norm( $address_line_norm, $city_norm = '', $state_code = '', $postcode_norm = '', $country_code = '' ) {
		$house_number_norm = yobm_extract_house_number_norm( $address_line_norm );
		$street_name_norm  = yobm_build_street_name_norm( $address_line_norm );

		$parts = array_filter(
			array(
				$house_number_norm,
				$street_name_norm,
				$city_norm,
				$state_code,
				'' !== $postcode_norm ? strtolower( $postcode_norm ) : '',
				'' !== $country_code ? strtolower( $country_code ) : '',
			),
			'strlen'
		);

		return trim( implode( ' ', $parts ) );
	}
}

if ( ! function_exists( 'yobm_normalize_postcode' ) ) {
	/**
	 * Normalize a postcode / ZIP for matching.
	 * - Trims
	 * - Uppercases
	 * - Removes non-alphanumeric characters
	 *
	 * Examples:
	 * "SW1A 1AA" => "SW1A1AA"
	 * "700 000"  => "700000"
	 *
	 * @param string $postcode Raw postcode.
	 * @return string
	 */
	function yobm_normalize_postcode( $postcode ) {
		$postcode = is_string( $postcode ) ? trim( $postcode ) : '';

		if ( '' === $postcode ) {
			return '';
		}

		$postcode = yobm_norm_str( $postcode );
		$postcode = preg_replace( '/[^a-z0-9]/', '', $postcode );
		$postcode = strtoupper( $postcode );

		return $postcode;
	}
}

if ( ! function_exists( 'yobm_normalize_address_tokens' ) ) {
	function yobm_normalize_address_tokens( $value ) {
		$value = yobm_norm_str( $value );

		if ( '' === $value ) {
			return '';
		}

		$value = str_replace(
			array( ',', '.', ';', ':', '#', '/', '\\', '-', '_', '(', ')', '[', ']', '{', '}' ),
			' ',
			$value
		);

		$value = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $value );
		$value = preg_replace( '/\b(\d+)\s*[- ]\s*([a-z])\b/u', '$1$2', $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$replacements = array(
			// directions
			'/\bnorth\b/u' => 'n',
			'/\bsouth\b/u' => 's',
			'/\beast\b/u'  => 'e',
			'/\bwest\b/u'  => 'w',
			'/\bnortheast\b/u' => 'ne',
			'/\bnorthwest\b/u' => 'nw',
			'/\bsoutheast\b/u' => 'se',
			'/\bsouthwest\b/u' => 'sw',

			// street suffixes
			'/\bstreet\b/u'    => 'st',
			'/\broad\b/u'      => 'rd',
			'/\bavenue\b/u'    => 'ave',
			'/\bboulevard\b/u' => 'blvd',
			'/\bdrive\b/u'     => 'dr',
			'/\blane\b/u'      => 'ln',
			'/\bcourt\b/u'     => 'ct',
			'/\bplace\b/u'     => 'pl',
			'/\bterrace\b/u'   => 'ter',
			'/\bparkway\b/u'   => 'pkwy',
			'/\bhighway\b/u'   => 'hwy',

			// unit markers normalized, not removed here
			'/\bapartment\b/u' => 'apt',
			'/\bsuite\b/u'     => 'ste',
			'/\bunit\b/u'      => 'unit',
			'/\bflat\b/u'      => 'flat',
			'/\broom\b/u'      => 'rm',
			'/\bfloor\b/u'     => 'fl',
			'/\bbuilding\b/u'  => 'bldg',
		);

		$value = preg_replace( array_keys( $replacements ), array_values( $replacements ), $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = trim( $value );

		return $value;
	}
}

if ( ! function_exists( 'yobm_normalize_address_line' ) ) {
	/**
	 * Normalize a single address line for matching.
	 * - Lowercases
	 * - Trims
	 * - Collapses spaces
	 * - Removes most punctuation noise
	 *
	 * @param string $value Raw address line.
	 * @return string
	 */
	function yobm_normalize_address_line( $value ) {
		$value = is_string( $value ) ? $value : '';

		if ( '' === trim( $value ) ) {
			return '';
		}

		return yobm_normalize_address_tokens( $value );
	}
}

if ( ! function_exists( 'yobm_build_address_display' ) ) {
	/**
	 * Build a readable address display string from parts.
	 *
	 * @param array $parts Address parts.
	 * @return string
	 */
	function yobm_build_address_display( $parts ) {
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$display_parts = array();

		foreach ( $parts as $part ) {
			$part = is_string( $part ) ? trim( $part ) : '';

			if ( '' !== $part ) {
				$display_parts[] = $part;
			}
		}

		return implode( ', ', $display_parts );
	}
}

if ( ! function_exists( 'yobm_build_address_core_norm' ) ) {
	/**
	 * Build a simplified address core by removing common unit/apartment markers.
	 *
	 * Examples:
	 * - "12 king street apt 5" => "12 king street"
	 * - "flat 3 50 doctor strange" => "50 doctor strange"
	 *
	 * @param string $address_line_norm Normalized address line.
	 * @return string
	 */
	function yobm_build_address_core_norm( $address_line_norm ) {
		$address_line_norm = yobm_normalize_address_tokens( $address_line_norm );

		if ( '' === $address_line_norm ) {
			return '';
		}

		$patterns = array(
			'/\b(?:apt|unit|ste|flat|rm|fl|block|bldg|tower)\s+[a-z0-9\-]+\b$/u',
			'/^\b(?:apt|unit|ste|flat|rm|fl|block|bldg|tower)\s+[a-z0-9\-]+\b\s*/u',
			'/\s+#\s*[a-z0-9\-]+\b$/u',
		);

		$core = preg_replace( $patterns, ' ', $address_line_norm );
		$core = preg_replace( '/\s+/u', ' ', (string) $core );
		$core = trim( $core );

		return $core;
	}
}

if ( ! function_exists( 'yobm_normalize_address_parts' ) ) {
	/**
	 * Normalize address parts into a reusable structured payload.
	 *
	 * Returns:
	 * - country_code
	 * - state_code
	 * - city_norm
	 * - postcode_norm
	 * - address_line_norm
	 * - address_full_norm
	 * - address_hash
	 * - address_display
	 *
	 * @param array $parts {
	 *     Optional. Raw address parts.
	 *
	 *     @type string $address_1 Address line 1.
	 *     @type string $address_2 Address line 2.
	 *     @type string $city      City.
	 *     @type string $state     State / province.
	 *     @type string $postcode  Postcode / ZIP.
	 *     @type string $country   Country code.
	 * }
	 * @return array
	 */
	function yobm_normalize_address_parts( $parts ) {
		$parts = is_array( $parts ) ? $parts : array();

		$address_1 = isset( $parts['address_1'] ) ? sanitize_text_field( wp_unslash( $parts['address_1'] ) ) : '';
		$address_2 = isset( $parts['address_2'] ) ? sanitize_text_field( wp_unslash( $parts['address_2'] ) ) : '';
		$city      = isset( $parts['city'] ) ? sanitize_text_field( wp_unslash( $parts['city'] ) ) : '';
		$state     = isset( $parts['state'] ) ? sanitize_text_field( wp_unslash( $parts['state'] ) ) : '';
		$postcode  = isset( $parts['postcode'] ) ? sanitize_text_field( wp_unslash( $parts['postcode'] ) ) : '';
		$country   = isset( $parts['country'] ) ? sanitize_text_field( wp_unslash( $parts['country'] ) ) : '';

		$country_code  = strtoupper( trim( (string) $country ) );
		$state_code    = yobm_norm_str( $state );
		$city_norm     = yobm_norm_str( $city );
		$postcode_norm = yobm_normalize_postcode( $postcode );

		$address_1_norm = yobm_normalize_address_line( $address_1 );
		$address_2_norm = yobm_normalize_address_line( $address_2 );

		$address_line_norm = trim(
			implode(
				' ',
				array_filter(
					array(
						$address_1_norm,
						$address_2_norm,
					),
					'strlen'
				)
			)
		);

		$address_core_norm = yobm_build_address_core_norm( $address_line_norm );

		$house_number_norm    = yobm_extract_house_number_norm( $address_line_norm );
		$street_name_norm     = yobm_build_street_name_norm( $address_line_norm );
		$address_premise_norm = yobm_build_address_premise_norm(
			$address_line_norm,
			$city_norm,
			$state_code,
			$postcode_norm,
			$country_code
		);
		$address_premise_hash = '' !== $address_premise_norm ? md5( $address_premise_norm ) : '';

		$full_parts = array_filter(
			array(
				$address_line_norm,
				$city_norm,
				$state_code,
				'' !== $postcode_norm ? strtolower( $postcode_norm ) : '',
				'' !== $country_code ? strtolower( $country_code ) : '',
			),
			'strlen'
		);

		$core_parts = array_filter(
			array(
				$address_core_norm,
				$city_norm,
				$state_code,
				'' !== $postcode_norm ? strtolower( $postcode_norm ) : '',
				'' !== $country_code ? strtolower( $country_code ) : '',
			),
			'strlen'
		);

		$line_postcode_parts = array_filter(
			array(
				$address_line_norm,
				'' !== $postcode_norm ? strtolower( $postcode_norm ) : '',
				'' !== $country_code ? strtolower( $country_code ) : '',
			),
			'strlen'
		);

		$address_full_norm          = trim( implode( ' ', $full_parts ) );
		$address_hash               = '' !== $address_full_norm ? md5( $address_full_norm ) : '';
		$address_core_full_norm     = trim( implode( ' ', $core_parts ) );
		$address_core_hash          = '' !== $address_core_full_norm ? md5( $address_core_full_norm ) : '';
		$address_line_postcode_norm = trim( implode( ' ', $line_postcode_parts ) );
		$address_line_postcode_hash = '' !== $address_line_postcode_norm ? md5( $address_line_postcode_norm ) : '';

		$address_display = yobm_build_address_display(
			array(
				$address_1,
				$address_2,
				$city,
				$state,
				$postcode,
				$country_code,
			)
		);

		return array(
			'country_code'               => $country_code,
			'state_code'                 => $state_code,
			'city_norm'                  => $city_norm,
			'postcode_norm'              => $postcode_norm,
			'address_line_norm'          => $address_line_norm,
			'address_core_norm'          => $address_core_norm,
			'house_number_norm'          => $house_number_norm,
			'street_name_norm'           => $street_name_norm,
			'address_premise_norm'       => $address_premise_norm,
			'address_premise_hash'       => $address_premise_hash,
			'address_full_norm'          => $address_full_norm,
			'address_hash'               => $address_hash,
			'address_core_hash'          => $address_core_hash,
			'address_line_postcode_hash' => $address_line_postcode_hash,
			'address_display'            => $address_display,
		);
	}
}

if ( ! function_exists( 'yobm_parse_meta_id_list' ) ) {
	function yobm_parse_meta_id_list( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$ids = array_map( 'trim', explode( ',', $value ) );
		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );

		return array_values( array_unique( $ids ) );
	}
}

if ( ! function_exists( 'yobm_store_meta_id_list' ) ) {
	function yobm_store_meta_id_list( $ids ) {
		$ids = is_array( $ids ) ? $ids : array();
		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );
		$ids = array_values( array_unique( $ids ) );

		return implode( ',', $ids );
	}
}

if ( ! function_exists( 'yobm_add_order_blacklist_meta_id' ) ) {
	function yobm_add_order_blacklist_meta_id( \WC_Order $order, $meta_key, $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return;
		}

		$current = $order->get_meta( $meta_key, true );
		$ids     = yobm_parse_meta_id_list( $current );
		$ids[]   = $id;
		$ids     = array_values( array_unique( array_map( 'absint', $ids ) ) );

		$order->update_meta_data( $meta_key, yobm_store_meta_id_list( $ids ) );
	}
}
