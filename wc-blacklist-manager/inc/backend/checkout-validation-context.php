<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider-free checkout validation identity/context contract v1.
 *
 * Premium owns validation policy and networking. Core only projects sanitized
 * submitted values and the existing canonical security identities.
 */
final class WC_Blacklist_Manager_Checkout_Validation_Context {

	const CONTRACT_VERSION = 1;

	public static function from_array( array $input ) {
		$billing  = self::address_values( $input, 'billing' );
		$shipping = self::address_values( $input, 'shipping' );
		$submitted_email = isset( $billing['email'] ) ? (string) $billing['email'] : '';
		$email           = self::validation_email( $submitted_email );

		$billing  = self::phone_projection( $billing );
		$shipping = self::phone_projection( $shipping );

		$effective = array(
			'phone'     => '' !== $billing['phone'] ? $billing['phone'] : $shipping['phone'],
			'dial_code' => '' !== $billing['dial_code'] ? $billing['dial_code'] : $shipping['dial_code'],
			'country'   => '' !== $billing['country'] ? $billing['country'] : $shipping['country'],
		);
		$effective = self::phone_projection( $effective );
		$shipping_used = self::truthy( self::value( $input, 'ship_to_different_address' ) );
		if ( ! $shipping_used && isset( $input['shipping_address'] ) && is_array( $input['shipping_address'] ) ) {
			$shipping_used = '' !== $shipping['first_name'] || '' !== $shipping['last_name'] || '' !== $shipping['phone'];
		}

		return array(
			'contract_version' => self::CONTRACT_VERSION,
			'submitted_email'  => $submitted_email,
			'validation_email' => $email,
			'canonical_email'  => function_exists( 'yobm_normalize_email' ) ? (string) yobm_normalize_email( $email ) : $email,
			'billing'          => $billing,
			'shipping'         => $shipping,
			'effective_phone'  => $effective,
			'ship_to_different_address' => $shipping_used,
		);
	}

	public static function from_order( $order, $request = null ) {
		$order_values = array();

		if ( is_object( $order ) ) {
			foreach ( array( 'billing', 'shipping' ) as $prefix ) {
				foreach ( array( 'email', 'phone', 'country', 'first_name', 'last_name' ) as $field ) {
					if ( 'shipping' === $prefix && 'email' === $field ) {
						continue;
					}
					$key    = $prefix . '_' . $field;
					$getter = 'get_' . $key;
					if ( is_callable( array( $order, $getter ) ) ) {
						$order_values[ $key ] = $order->{$getter}();
					}
				}

				$dial_key = $prefix . '_dial_code';
				$getter   = 'get_' . $dial_key;
				if ( is_callable( array( $order, $getter ) ) ) {
					$order_values[ $dial_key ] = $order->{$getter}();
				} elseif ( is_callable( array( $order, 'get_meta' ) ) ) {
					$order_values[ $dial_key ] = $order->get_meta( '_' . $dial_key, true );
				}
			}
		}

		$request_values = array();
		if ( is_object( $request ) && is_callable( array( $request, 'get_params' ) ) ) {
			$request_values = $request->get_params();
			$request_values = is_array( $request_values ) ? $request_values : array();
		} elseif ( is_array( $request ) ) {
			$request_values = $request;
		}

		return self::from_array( self::merge_present( $order_values, $request_values ) );
	}

	public static function validation_email( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( preg_match( '/[\x00-\x20\x7f]/u', $value ) ) {
			return '';
		}
		$parts = explode( '@', $value, 2 );

		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return '';
		}

		$local  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $parts[0], 'UTF-8' ) : strtolower( $parts[0] );
		$domain = strtolower( $parts[1] );

		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = defined( 'INTL_IDNA_VARIANT_UTS46' )
				? idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 )
				: idn_to_ascii( $domain );
			if ( $ascii ) {
				$domain = strtolower( $ascii );
			}
		}

		$email = $local . '@' . $domain;

		return function_exists( 'is_email' ) && ! is_email( $email ) ? '' : $email;
	}

	private static function address_values( array $input, $prefix ) {
		$nested_key = $prefix . '_address';
		$nested     = isset( $input[ $nested_key ] ) && is_array( $input[ $nested_key ] ) ? $input[ $nested_key ] : array();

		return array(
			'email'      => 'billing' === $prefix ? self::sanitized( self::first_value( $input, $nested, $prefix . '_email', 'email' ) ) : '',
			'phone'      => self::sanitized( self::first_value( $input, $nested, $prefix . '_phone', 'phone' ) ),
			'dial_code'  => self::sanitized( self::first_value( $input, $nested, $prefix . '_dial_code', 'dial_code' ) ),
			'country'    => strtoupper( self::sanitized( self::first_value( $input, $nested, $prefix . '_country', 'country' ) ) ),
			'first_name' => self::sanitized( self::first_value( $input, $nested, $prefix . '_first_name', 'first_name' ) ),
			'last_name'  => self::sanitized( self::first_value( $input, $nested, $prefix . '_last_name', 'last_name' ) ),
		);
	}

	private static function phone_projection( array $values ) {
		$phone     = isset( $values['phone'] ) ? self::sanitized( $values['phone'] ) : '';
		$dial_code = isset( $values['dial_code'] ) ? self::sanitized( $values['dial_code'] ) : '';
		$country   = isset( $values['country'] ) ? strtoupper( self::sanitized( $values['country'] ) ) : '';

		if ( '' === $dial_code && '' !== $country && function_exists( 'yobm_get_country_dial_code' ) ) {
			$dial_code = (string) yobm_get_country_dial_code( $country );
		}

		$values['phone']           = $phone;
		$values['dial_code']       = $dial_code;
		$values['country']         = $country;
		$values['canonical_phone'] = '' !== $phone && function_exists( 'yobm_normalize_phone' )
			? (string) yobm_normalize_phone( $phone, $dial_code )
			: '';

		return $values;
	}

	private static function first_value( array $flat, array $nested, $flat_key, $nested_key ) {
		return array_key_exists( $nested_key, $nested )
			? self::value( $nested, $nested_key )
			: self::value( $flat, $flat_key );
	}

	private static function value( array $input, $key ) {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : '';
	}

	private static function sanitized( $value, $email = false ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return $email ? self::validation_email( $value ) : sanitize_text_field( $value );
	}

	private static function truthy( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	private static function merge_present( array $base, array $override ) {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) ) {
				$base[ $key ] = isset( $base[ $key ] ) && is_array( $base[ $key ] )
					? self::merge_present( $base[ $key ], $value )
					: $value;
			} elseif ( is_scalar( $value ) ) {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}
}

function wc_blacklist_manager_checkout_validation_context( array $input ) {
	return WC_Blacklist_Manager_Checkout_Validation_Context::from_array( $input );
}

function wc_blacklist_manager_checkout_validation_context_from_order( $order, $request = null ) {
	return WC_Blacklist_Manager_Checkout_Validation_Context::from_order( $order, $request );
}
