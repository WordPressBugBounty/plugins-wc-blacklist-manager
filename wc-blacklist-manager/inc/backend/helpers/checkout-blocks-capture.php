<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'rest_request_before_callbacks', 'yobm_capture_blocks_dial_codes_early', 10, 3 );

function yobm_capture_blocks_dial_codes_early( $response, $handler, $request ) {
	if ( ! $request instanceof \WP_REST_Request ) {
		return $response;
	}

	$route  = (string) $request->get_route();
	$method = strtoupper( (string) $request->get_method() );

	if ( 'POST' !== $method ) {
		return $response;
	}

	if ( false === strpos( $route, '/wc/store/' ) || false === strpos( $route, '/checkout' ) ) {
		return $response;
	}

	$params = $request->get_json_params();
	if ( ! is_array( $params ) ) {
		$params = array();
	}

	$billing_dial_code  = isset( $params['billing_dial_code'] ) ? sanitize_text_field( wp_unslash( $params['billing_dial_code'] ) ) : '';
	$shipping_dial_code = isset( $params['shipping_dial_code'] ) ? sanitize_text_field( wp_unslash( $params['shipping_dial_code'] ) ) : '';

	if ( function_exists( 'WC' ) && WC()->session ) {
		if ( '' !== $billing_dial_code ) {
			WC()->session->set( 'yobm_blocks_billing_dial_code', $billing_dial_code );
		}

		if ( '' !== $shipping_dial_code ) {
			WC()->session->set( 'yobm_blocks_shipping_dial_code', $shipping_dial_code );
		}
	}

	return $response;
}