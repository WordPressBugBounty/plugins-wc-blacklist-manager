<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class YOGB_BM_Report {
	// Server REST prefix (keep route part without /wp-json in the signature path)
	const SERVER_BASE  = 'https://globalblacklist.org';
	const REST_ROUTE   = '/yoohw-gbl/v1';
	const OPT_KEY      = 'yogb_bm_api_key';
	const OPT_SECRET   = 'yogb_bm_api_secret';

	// Async hook
	const CRON_HOOK    = 'yogb_bm_send_report';

	// Order meta key
	const META_EVIDENCE_SNAPSHOT = '_yogb_evidence_snapshot_v1';
	const META_EVIDENCE_HASH     = '_yogb_evidence_hash_v1';

	// ---- Public API --------------------------------------------------------

	/**
	 * Excluded WC statuses for reporting (hard-coded).
	 */
	private static function is_excluded_order_status( WC_Order $order ) : bool {
		$status   = (string) $order->get_status(); // without "wc-"
		$excluded = [ 'pending', 'failed', 'cancelled', 'on-hold' ];

		return in_array( $status, $excluded, true );
	}

	/** Queue reports for each identity on the order (email, phone, ip, address). */
	public static function queue_report_from_order( WC_Order $order, string $reason_code, string $description = '' ) : void {

		// Hard gate: do not report excluded (high-noise) statuses.
		if ( self::is_excluded_order_status( $order ) ) {
			return;
		}
		
		// Build a deterministic snapshot + hash
		$snapshot = self::evidence_material_for_order( $order, $reason_code, $description );
		$ev_hash  = self::compute_evidence_hash( $snapshot );

		// Save locally for auditability (meta + a single note)
		self::persist_evidence_on_order( $order, $snapshot, $ev_hash );

		// Build per-identity payloads (now including evidence_hash)
		$payloads = self::build_payloads_from_order( $order, $reason_code, $description, $ev_hash );

		foreach ( $payloads as $p ) {
			$idemp = self::make_idempotency_key_per_identity( $order, $reason_code, $p['identity'] );
			$envelope = [
				'payload'     => $p,
				'idempotency' => $idemp,
				'order_id'    => (int) $order->get_id(),
			];
			$when = time() + 3;

			$key = md5( wp_json_encode( [ $idemp, $p ] ) );
			if ( ! wp_next_scheduled( self::CRON_HOOK, [ $key ] ) ) {
				wp_schedule_single_event( $when, self::CRON_HOOK, [ $key ] );
				set_transient( 'yogb_bm_payload_' . $key, $envelope, 10 * MINUTE_IN_SECONDS );
			}
		}
	}

	/** Worker: send one payload (one identity). */
	public static function cron_send_report( string $key ) : void {
		$args = get_transient( 'yogb_bm_payload_' . $key );
		if ( ! is_array( $args ) ) return;
		delete_transient( 'yogb_bm_payload_' . $key );

		$payload = $args['payload'] ?? null;
		$idemp   = $args['idempotency'] ?? '';
		$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;

		if ( ! $payload || ! $idemp ) return;

		$res = self::post_json_signed(
			self::REST_ROUTE . '/reports',
			$payload,
			[ 'Idempotency-Key' => $idemp ]
		);

		// If success, try to store report_id on the order.
		if ( ! empty( $res['ok'] ) && $order_id > 0 ) {
			$body = json_decode( $res['body'] ?? '', true );

			if ( is_array( $body ) && ! empty( $body['report_id'] ) ) {
				$report_id = (string) $body['report_id']; // e.g. "rpt_123"

				$order = wc_get_order( $order_id );
				if ( $order ) {
					$existing = $order->get_meta( '_yogb_gbl_report_ids', true );
					if ( ! is_array( $existing ) ) {
						$existing = $existing ? [ (string) $existing ] : [];
					}

					$existing[] = $report_id;
					$existing   = array_values( array_unique( array_filter( $existing ) ) );

					$order->update_meta_data( '_yogb_gbl_report_ids', $existing );
					$order->save();
				}
			}
		}

		// Simple backoff on transient errors (5xx/429) – reschedule once
		if ( empty($res['ok']) && in_array( (int) $res['code'], [429,500,502,503,504], true ) ) {
			// re-enqueue in ~60s (idempotent so safe)
			$retry_key = md5( wp_json_encode( [ $idemp, $payload, 'retry' ] ) );
			if ( ! wp_next_scheduled( self::CRON_HOOK, [ $retry_key ] ) ) {
				set_transient( 'yogb_bm_payload_' . $retry_key, [ 'payload'=>$payload, 'idempotency'=>$idemp ], 10 * MINUTE_IN_SECONDS );
				wp_schedule_single_event( time() + 60, self::CRON_HOOK, [ $retry_key ] );
			}
		}

		// Optional: log for visibility in debug.log
		//if ( defined('WP_DEBUG') && WP_DEBUG ) {
		//	error_log( '[YOGB] report sent code=' . ($res['code'] ?? 0) . ' ok=' . (!empty($res['ok']) ? '1':'0') );
		//}
	}

	/** True if we have credentials. */
	public static function is_ready() : bool {
		return (bool) get_option( self::OPT_KEY ) && (bool) get_option( self::OPT_SECRET );
	}

	/**
	 * Build identity list (email, phone, ip, billing + shipping address, domain) from an order.
	 * Used both for reporting and for /check calls.
	 */
	public static function build_identities_from_order( WC_Order $order ) : array {
		$billing_country = sanitize_text_field( $order->get_billing_country() );

		$email_raw = (string) $order->get_billing_email();
		$email     = function_exists( 'yobm_gbl_normalize_email' )
			? yobm_gbl_normalize_email( $email_raw )
			: sanitize_email( $email_raw );

		$phone_raw = sanitize_text_field( $order->get_billing_phone() );
		$ip_raw    = sanitize_text_field( $order->get_customer_ip_address() );

		$ip = function_exists( 'yobm_gbl_normalize_ip' )
			? yobm_gbl_normalize_ip( $ip_raw )
			: trim( (string) $ip_raw );

		$billing_address = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_billing_address_1() ),
				'address_2' => sanitize_text_field( $order->get_billing_address_2() ),
				'city'      => sanitize_text_field( $order->get_billing_city() ),
				'state'     => sanitize_text_field( $order->get_billing_state() ),
				'postcode'  => sanitize_text_field( $order->get_billing_postcode() ),
				'country'   => $billing_country,
			)
		);

		$shipping_address = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_shipping_address_1() ),
				'address_2' => sanitize_text_field( $order->get_shipping_address_2() ),
				'city'      => sanitize_text_field( $order->get_shipping_city() ),
				'state'     => sanitize_text_field( $order->get_shipping_state() ),
				'postcode'  => sanitize_text_field( $order->get_shipping_postcode() ),
				'country'   => sanitize_text_field( $order->get_shipping_country() ),
			)
		);

		$bill_addr = isset( $billing_address['address_core_norm'] ) ? (string) $billing_address['address_core_norm'] : '';
		$ship_addr = isset( $shipping_address['address_core_norm'] ) ? (string) $shipping_address['address_core_norm'] : '';

		$phone = '';
		if ( '' !== $phone_raw ) {
			$dial_code        = yobm_get_country_dial_code( $billing_country );
			$normalized_phone = function_exists( 'yobm_gbl_normalize_phone' )
				? yobm_gbl_normalize_phone( $phone_raw, $dial_code )
				: yobm_normalize_phone( $phone_raw, $dial_code );

			if ( '' !== $normalized_phone ) {
				$phone = '+' . $normalized_phone;
			}
		}

		$idents = array();

		if ( '' !== $email ) {
			$idents[] = array(
				'type'  => 'email',
				'value' => $email,
			);

			$at = strrpos( $email, '@' );
			if ( false !== $at ) {
				$domain = substr( $email, $at + 1 );
				$domain = function_exists( 'yobm_gbl_normalize_domain' )
					? yobm_gbl_normalize_domain( $domain )
					: strtolower( trim( (string) $domain ) );

				if ( '' !== $domain ) {
					$idents[] = array(
						'type'  => 'domain',
						'value' => $domain,
					);
				}
			}
		}

		if ( '' !== $phone ) {
			$idents[] = array(
				'type'  => 'phone',
				'value' => $phone,
			);
		}

		if ( '' !== $ip ) {
			$idents[] = array(
				'type'  => 'ip',
				'value' => $ip,
			);
		}

		if ( '' !== $bill_addr ) {
			$idents[] = array(
				'type'  => 'address',
				'value' => $bill_addr,
			);
		}

		if ( '' !== $ship_addr && $ship_addr !== $bill_addr ) {
			$idents[] = array(
				'type'  => 'address',
				'value' => $ship_addr,
			);
		}

		return self::dedupe_identities( $idents );
	}

	private static function dedupe_identities( array $idents ) : array {
		$out = array();

		foreach ( $idents as $ident ) {
			if ( ! is_array( $ident ) ) {
				continue;
			}

			$type  = isset( $ident['type'] ) ? strtolower( trim( (string) $ident['type'] ) ) : '';
			$value = isset( $ident['value'] ) ? trim( (string) $ident['value'] ) : '';

			if ( '' === $type || '' === $value ) {
				continue;
			}

			$key = $type . '|' . $value;

			$out[ $key ] = array(
				'type'  => $type,
				'value' => $value,
			);
		}

		return array_values( $out );
	}

	private static function dedupe_related_identities( array $related ) : array {
		$out = array();

		foreach ( $related as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$type  = isset( $row['type'] ) ? strtolower( trim( (string) $row['type'] ) ) : '';
			$value = isset( $row['value'] ) ? trim( (string) $row['value'] ) : '';

			if ( '' === $type || '' === $value ) {
				continue;
			}

			$key = $type . '|' . $value;

			$out[ $key ] = array(
				'type'        => $type,
				'value'       => $value,
				'role'        => isset( $row['role'] ) ? (string) $row['role'] : 'secondary',
				'link_source' => isset( $row['link_source'] ) ? (string) $row['link_source'] : 'report',
			);
		}

		return array_values( $out );
	}

	/** Build one payload per identity using your server's schema. */
	private static function build_payloads_from_order( WC_Order $order, string $reason_code, string $description = '', ?string $evidence_hash = null ) : array {
		$payloads = array();

		$billing_country = sanitize_text_field( $order->get_billing_country() );
		$currency        = sanitize_text_field( $order->get_currency() );
		$total           = (float) $order->get_total();

		$email_raw = (string) $order->get_billing_email();
		$email     = function_exists( 'yobm_gbl_normalize_email' )
			? yobm_gbl_normalize_email( $email_raw )
			: sanitize_email( $email_raw );

		$phone_raw = sanitize_text_field( $order->get_billing_phone() );

		$ip_raw = sanitize_text_field( $order->get_customer_ip_address() );
		$ip     = function_exists( 'yobm_gbl_normalize_ip' )
			? yobm_gbl_normalize_ip( $ip_raw )
			: trim( (string) $ip_raw );

		$phone = '';
		if ( '' !== $phone_raw ) {
			$dial_code        = yobm_get_country_dial_code( $billing_country );
			$normalized_phone = function_exists( 'yobm_gbl_normalize_phone' )
				? yobm_gbl_normalize_phone( $phone_raw, $dial_code )
				: yobm_normalize_phone( $phone_raw, $dial_code );

			if ( '' !== $normalized_phone ) {
				$phone = '+' . $normalized_phone;
			}
		}

		$billing_address = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_billing_address_1() ),
				'address_2' => sanitize_text_field( $order->get_billing_address_2() ),
				'city'      => sanitize_text_field( $order->get_billing_city() ),
				'state'     => sanitize_text_field( $order->get_billing_state() ),
				'postcode'  => sanitize_text_field( $order->get_billing_postcode() ),
				'country'   => $billing_country,
			)
		);

		$shipping_address = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_shipping_address_1() ),
				'address_2' => sanitize_text_field( $order->get_shipping_address_2() ),
				'city'      => sanitize_text_field( $order->get_shipping_city() ),
				'state'     => sanitize_text_field( $order->get_shipping_state() ),
				'postcode'  => sanitize_text_field( $order->get_shipping_postcode() ),
				'country'   => sanitize_text_field( $order->get_shipping_country() ),
			)
		);

		$idents = self::build_identities_from_order( $order );

		$ttl_days = (int) get_option( 'yogb_bm_default_ttl_days', 365 );
		$ttl_days = max( 1, min( 1095, $ttl_days ) );

		// Device meta captured by the plugin-side device identity class.
		$device_id      = strtolower( trim( (string) $order->get_meta( '_wc_bm_device_id', true ) ) );
		$device_version = trim( (string) $order->get_meta( '_wc_bm_device_version', true ) );
		$browser_id     = trim( (string) $order->get_meta( '_wc_bm_device_browser_id', true ) );
		$session_id     = trim( (string) $order->get_meta( '_wc_bm_session_id', true ) );
		$fp_hash        = strtolower( trim( (string) $order->get_meta( '_wc_bm_device_fp_hash', true ) ) );
		$confidence     = sanitize_key( (string) $order->get_meta( '_wc_bm_device_confidence', true ) );
		$payload_valid  = trim( (string) $order->get_meta( '_wc_bm_device_payload_valid', true ) );

		$device_flags = $order->get_meta( '_wc_bm_device_flags', true );
		if ( ! is_array( $device_flags ) ) {
			$device_flags = $device_flags ? array( (string) $device_flags ) : array();
		}
		$device_flags = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_text_field', $device_flags )
				)
			)
		);

		$device_validation_reasons = $order->get_meta( '_wc_bm_device_validation_reasons', true );
		if ( ! is_array( $device_validation_reasons ) ) {
			$device_validation_reasons = $device_validation_reasons ? array( (string) $device_validation_reasons ) : array();
		}
		$device_validation_reasons = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_text_field', $device_validation_reasons )
				)
			)
		);

		foreach ( $idents as $ident ) {
			$ctx = array(
				'reason_code'  => $reason_code,
				'description'  => $description,
				'order_id'     => (int) $order->get_id(),
				'order_amount' => $total,
				'currency'     => $currency,
				'country'      => $billing_country,
				'email_norm'   => $email,
				'phone_norm'   => $phone,
				'ip_norm'      => $ip,

				'billing_address_norm' => array(
					'full'          => isset( $billing_address['address_full_norm'] ) ? (string) $billing_address['address_full_norm'] : '',
					'core'          => isset( $billing_address['address_core_norm'] ) ? (string) $billing_address['address_core_norm'] : '',
					'premise'       => isset( $billing_address['address_premise_norm'] ) ? (string) $billing_address['address_premise_norm'] : '',
					'postcode'      => isset( $billing_address['postcode_norm'] ) ? (string) $billing_address['postcode_norm'] : '',
					'state'         => isset( $billing_address['state_code'] ) ? (string) $billing_address['state_code'] : '',
					'country'       => isset( $billing_address['country_code'] ) ? (string) $billing_address['country_code'] : '',
					'house_number'  => isset( $billing_address['house_number_norm'] ) ? (string) $billing_address['house_number_norm'] : '',
					'street_name'   => isset( $billing_address['street_name_norm'] ) ? (string) $billing_address['street_name_norm'] : '',
					'display'       => isset( $billing_address['address_display'] ) ? (string) $billing_address['address_display'] : '',
					'hash_full'     => isset( $billing_address['address_hash'] ) ? (string) $billing_address['address_hash'] : '',
					'hash_core'     => isset( $billing_address['address_core_hash'] ) ? (string) $billing_address['address_core_hash'] : '',
					'hash_premise'  => isset( $billing_address['address_premise_hash'] ) ? (string) $billing_address['address_premise_hash'] : '',
				),

				'shipping_address_norm' => array(
					'full'          => isset( $shipping_address['address_full_norm'] ) ? (string) $shipping_address['address_full_norm'] : '',
					'core'          => isset( $shipping_address['address_core_norm'] ) ? (string) $shipping_address['address_core_norm'] : '',
					'premise'       => isset( $shipping_address['address_premise_norm'] ) ? (string) $shipping_address['address_premise_norm'] : '',
					'postcode'      => isset( $shipping_address['postcode_norm'] ) ? (string) $shipping_address['postcode_norm'] : '',
					'state'         => isset( $shipping_address['state_code'] ) ? (string) $shipping_address['state_code'] : '',
					'country'       => isset( $shipping_address['country_code'] ) ? (string) $shipping_address['country_code'] : '',
					'house_number'  => isset( $shipping_address['house_number_norm'] ) ? (string) $shipping_address['house_number_norm'] : '',
					'street_name'   => isset( $shipping_address['street_name_norm'] ) ? (string) $shipping_address['street_name_norm'] : '',
					'display'       => isset( $shipping_address['address_display'] ) ? (string) $shipping_address['address_display'] : '',
					'hash_full'     => isset( $shipping_address['address_hash'] ) ? (string) $shipping_address['address_hash'] : '',
					'hash_core'     => isset( $shipping_address['address_core_hash'] ) ? (string) $shipping_address['address_core_hash'] : '',
					'hash_premise'  => isset( $shipping_address['address_premise_hash'] ) ? (string) $shipping_address['address_premise_hash'] : '',
				),
			);

			if ( $evidence_hash ) {
				$ctx['evidence_hash'] = $evidence_hash;
			}

			// Device stays contextual except for the canonical device_id identity.
			$ctx['device'] = array(
				'version'            => $device_version !== '' ? $device_version : 'v1',
				'browser_id'         => $browser_id,
				'fingerprint_hash'   => $fp_hash,
				'session_id'         => $session_id,
				'confidence'         => $confidence,
				'flags'              => $device_flags,
				'payload_valid'      => $payload_valid,
				'validation_reasons' => $device_validation_reasons,
			);

			$related_identities = array();

			foreach ( $idents as $candidate ) {
				if (
					isset( $candidate['type'], $candidate['value'] ) &&
					$candidate['type'] === $ident['type'] &&
					$candidate['value'] === $ident['value']
				) {
					continue;
				}

				$role        = 'secondary';
				$link_source = 'report';

				if ( 'domain' === ( $candidate['type'] ?? '' ) ) {
					$role        = 'derived_domain';
					$link_source = 'derived';
				}

				$related_identities[] = array(
					'type'        => (string) $candidate['type'],
					'value'       => (string) $candidate['value'],
					'role'        => $role,
					'link_source' => $link_source,
				);
			}

			// Append the canonical device identity as a supporting identity.
			if ( '' !== $device_id && $payload_valid === 'yes' ) {
				$related_identities[] = array(
					'type'        => 'device',
					'value'       => $device_id,
					'role'        => 'secondary',
					'link_source' => 'report',
				);
			}

			$related_identities = self::dedupe_related_identities( $related_identities );

			$payloads[] = array(
				'identity'           => $ident,
				'context'            => $ctx,
				'related_identities' => $related_identities,
				'ttl_days'           => $ttl_days,
			);
		}

		return $payloads;
	}

	/**
	 * Build richer /check payload from an order.
	 *
	 * Keeps the same identities list as reporting, but also sends normalized
	 * address bags so the server can probe smarter than exact-match only.
	 */
	public static function build_check_payload_from_order( WC_Order $order ) : array {
		$billing_country = sanitize_text_field( $order->get_billing_country() );
		$email_raw = (string) $order->get_billing_email();
		$email     = function_exists( 'yobm_gbl_normalize_email' )
			? yobm_gbl_normalize_email( $email_raw )
			: sanitize_email( $email_raw );

		$phone_raw = sanitize_text_field( $order->get_billing_phone() );

		$ip_raw = sanitize_text_field( $order->get_customer_ip_address() );
		$ip     = function_exists( 'yobm_gbl_normalize_ip' )
			? yobm_gbl_normalize_ip( $ip_raw )
			: trim( (string) $ip_raw );

		$phone = '';
		if ( '' !== $phone_raw ) {
			$dial_code        = yobm_get_country_dial_code( $billing_country );
			$normalized_phone = function_exists( 'yobm_gbl_normalize_phone' )
				? yobm_gbl_normalize_phone( $phone_raw, $dial_code )
				: yobm_normalize_phone( $phone_raw, $dial_code );

			if ( '' !== $normalized_phone ) {
				$phone = '+' . $normalized_phone;
			}
		}

		$email_domain = '';
		if ( '' !== $email && false !== strpos( $email, '@' ) ) {
			$at           = strrpos( $email, '@' );
			$email_domain = function_exists( 'yobm_gbl_normalize_domain' )
				? yobm_gbl_normalize_domain( substr( $email, $at + 1 ) )
				: strtolower( trim( (string) substr( $email, $at + 1 ) ) );

			if ( '' === $email_domain || false === strpos( $email_domain, '.' ) ) {
				$email_domain = '';
			}
		}

		$billing_address = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_billing_address_1() ),
				'address_2' => sanitize_text_field( $order->get_billing_address_2() ),
				'city'      => sanitize_text_field( $order->get_billing_city() ),
				'state'     => sanitize_text_field( $order->get_billing_state() ),
				'postcode'  => sanitize_text_field( $order->get_billing_postcode() ),
				'country'   => $billing_country,
			)
		);

		$shipping_address = yobm_normalize_address_parts(
			array(
				'address_1' => sanitize_text_field( $order->get_shipping_address_1() ),
				'address_2' => sanitize_text_field( $order->get_shipping_address_2() ),
				'city'      => sanitize_text_field( $order->get_shipping_city() ),
				'state'     => sanitize_text_field( $order->get_shipping_state() ),
				'postcode'  => sanitize_text_field( $order->get_shipping_postcode() ),
				'country'   => sanitize_text_field( $order->get_shipping_country() ),
			)
		);

		$idents = self::build_identities_from_order( $order );

		return array(
			'identities' => $idents,
			'context'    => array(
				'ip'                   => $ip,
				'email'                => $email,
				'phone'                => $phone,
				'email_domain'         => $email_domain,
				'address_norm_version' => 2,

				'billing_address_norm' => array(
					'full'         => isset( $billing_address['address_full_norm'] ) ? (string) $billing_address['address_full_norm'] : '',
					'core'         => isset( $billing_address['address_core_norm'] ) ? (string) $billing_address['address_core_norm'] : '',
					'premise'      => isset( $billing_address['address_premise_norm'] ) ? (string) $billing_address['address_premise_norm'] : '',
					'postcode'     => isset( $billing_address['postcode_norm'] ) ? (string) $billing_address['postcode_norm'] : '',
					'state'        => isset( $billing_address['state_code'] ) ? (string) $billing_address['state_code'] : '',
					'country'      => isset( $billing_address['country_code'] ) ? (string) $billing_address['country_code'] : '',
					'house_number' => isset( $billing_address['house_number_norm'] ) ? (string) $billing_address['house_number_norm'] : '',
					'street_name'  => isset( $billing_address['street_name_norm'] ) ? (string) $billing_address['street_name_norm'] : '',
					'display'      => isset( $billing_address['address_display'] ) ? (string) $billing_address['address_display'] : '',
				),

				'shipping_address_norm' => array(
					'full'         => isset( $shipping_address['address_full_norm'] ) ? (string) $shipping_address['address_full_norm'] : '',
					'core'         => isset( $shipping_address['address_core_norm'] ) ? (string) $shipping_address['address_core_norm'] : '',
					'premise'      => isset( $shipping_address['address_premise_norm'] ) ? (string) $shipping_address['address_premise_norm'] : '',
					'postcode'     => isset( $shipping_address['postcode_norm'] ) ? (string) $shipping_address['postcode_norm'] : '',
					'state'        => isset( $shipping_address['state_code'] ) ? (string) $shipping_address['state_code'] : '',
					'country'      => isset( $shipping_address['country_code'] ) ? (string) $shipping_address['country_code'] : '',
					'house_number' => isset( $shipping_address['house_number_norm'] ) ? (string) $shipping_address['house_number_norm'] : '',
					'street_name'  => isset( $shipping_address['street_name_norm'] ) ? (string) $shipping_address['street_name_norm'] : '',
					'display'      => isset( $shipping_address['address_display'] ) ? (string) $shipping_address['address_display'] : '',
				),
			),
		);
	}

	/** Idempotency per identity (meets /^[A-Za-z0-9-]{8,64}$/). */
	private static function make_idempotency_key_per_identity( WC_Order $order, string $reason_code, array $identity ) : string {
		$site = site_url();
		$raw  = implode('|', [
			$order->get_id(),
			$reason_code,
			$identity['type'] ?? '',
			$identity['value'] ?? '',
			$site,
		]);
		$hex = hash( 'sha1', $raw ); // 40 hex chars
		return 'yogb-' . substr( $hex, 0, 58 ); // 'yogb-' + 58 = 63 chars (<=64), alnum+hyphen only
	}

	/** Deterministic, privacy-safe snapshot of the order at report time. */
	private static function evidence_material_for_order( WC_Order $order, string $reason_code, string $description ) : array {
		$dec = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
		$fmt = function($n) use ($dec) { return number_format( (float) $n, $dec, '.', '' ); };
		$iso = function($dt) { return $dt ? gmdate('Y-m-d\TH:i:s\Z', $dt->getTimestamp()) : null; };

		$site_url = home_url( '/' );
		$host     = wp_parse_url( $site_url, PHP_URL_HOST );
		$site_dom = is_string($host) ? strtolower($host) : '';

		// Identity fingerprints only (no raw PII)
		$email_norm = function_exists( 'yobm_gbl_normalize_email' )
			? yobm_gbl_normalize_email( (string) $order->get_billing_email() )
			: strtolower( trim( sanitize_email( $order->get_billing_email() ) ) );

		$billing_country = sanitize_text_field( $order->get_billing_country() );
		$dial_code       = yobm_get_country_dial_code( $billing_country );
		$phone_digits    = function_exists( 'yobm_gbl_normalize_phone' )
			? yobm_gbl_normalize_phone( (string) $order->get_billing_phone(), $dial_code )
			: yobm_normalize_phone( (string) $order->get_billing_phone(), $dial_code );
		$phone_e164      = '' !== $phone_digits ? '+' . $phone_digits : '';

		$ip_raw = function_exists( 'yobm_gbl_normalize_ip' )
			? yobm_gbl_normalize_ip( (string) $order->get_customer_ip_address() )
			: sanitize_text_field( $order->get_customer_ip_address() );

		$idents = [
			'email_sha256'      => $email_norm ? hash('sha256', $email_norm) : null,
			'phone_sha256'      => $phone_e164 ? hash('sha256', $phone_e164) : null,
			'ip'                => $ip_raw ?: null, // ok to include locally; only hash is sent
		];

		// Minimal item fingerprint
		$items_min = [];
		foreach ( $order->get_items() as $it ) {
			$items_min[] = [ (int) $it->get_product_id(), (int) $it->get_variation_id(), (float) $it->get_quantity() ];
		}
		sort( $items_min );
		$items_digest = hash( 'sha256', wp_json_encode( $items_min, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$snapshot = [
			'v'            => 1,
			'order_id'     => (int) $order->get_id(),
			'order_key'    => (string) $order->get_order_key(),
			'status'       => (string) $order->get_status(),
			'user_id'      => (int) $order->get_user_id(),
			'created_gmt'  => $iso( $order->get_date_created() ),
			'currency'     => (string) $order->get_currency(),
			'amounts'      => [
				'total'    => $fmt( $order->get_total() ),
				'subtotal' => $fmt( $order->get_subtotal() ),
				'discount' => $fmt( $order->get_total_discount() ),
				'shipping' => $fmt( $order->get_shipping_total() ),
				'tax'      => $fmt( $order->get_total_tax() ),
			],
			'reason_code'  => sanitize_key( $reason_code ),
			'description'  => mb_substr( (string) $description, 0, 256 ),
			'line_count'   => count( $order->get_items() ),
			'items_digest' => $items_digest,
			'idents'       => array_filter( $idents, fn($v) => !is_null($v) ),
			'billing_country'  => sanitize_text_field( $order->get_billing_country() ),
			'shipping_country' => sanitize_text_field( $order->get_shipping_country() ),
			'payment'      => array_filter([
				'gateway' => (string) $order->get_payment_method(),
				'txn_id'  => (string) $order->get_transaction_id(),
			], fn($v) => $v !== '' && !is_null($v) ),
			'env'          => array_filter([
				'site_domain' => $site_dom,
				'wp_ver'      => get_bloginfo('version'),
				'wc_ver'      => defined('WC_VERSION') ? WC_VERSION : null,
				'plugin'      => 'wc-blacklist-manager',
				'plugin_ver'  => defined('WC_BLACKLIST_MANAGER_VERSION') ? WC_BLACKLIST_MANAGER_VERSION : null,
			], fn($v) => $v !== '' && !is_null($v) ),
		];

		// Strip nulls at top level for determinism
		return array_filter( $snapshot, fn($v) => !is_null($v) );
	}

	/** Compute sha256 over canonical JSON of the snapshot. */
	private static function compute_evidence_hash( array $snapshot ) : string {
		$json = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', $json );
	}

	/** Persist snapshot + hash into order meta and add a one-time note. */
	private static function persist_evidence_on_order( WC_Order $order, array $snapshot, string $hash ) : void {
		// Read via WC CRUD (works for CPT + HPOS)
		$already_noted = (bool) $order->get_meta( '_yogb_evidence_noted', true );
		$has_snapshot  = (bool) $order->get_meta( self::META_EVIDENCE_SNAPSHOT, true );

		// Write via WC CRUD
		$order->update_meta_data( self::META_EVIDENCE_HASH, $hash );

		if ( ! $has_snapshot ) {
			$order->update_meta_data(
				self::META_EVIDENCE_SNAPSHOT,
				wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			);
		}

		if ( ! $already_noted ) {
			$order->add_order_note( 'GBL evidence hash: ' . $hash, false );
			$order->update_meta_data( '_yogb_evidence_noted', 1 );
		}

		// Persist to the correct datastore (posts or HPOS tables)
		$order->save();
	}

	// ---- HTTP (signed) -----------------------------------------------------

	/** POST JSON with HMAC headers to the server. */
	public static function post_json_signed( string $route, array $payload, array $extra_headers = [] ) : array {
		if ( ! self::is_ready() ) {
			return [
				'ok'   => false,
				'code' => 0,
				'err'  => 'not_ready',
			];
		}

		// Encode payload safely.
		$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $body ) {
			return [
				'ok'   => false,
				'code' => 0,
				'err'  => 'json_encode_failed',
			];
		}

		// Build URL: SERVER_BASE should be like 'https://globalblacklist.org/'.
		$url    = trailingslashit( self::SERVER_BASE ) . 'wp-json' . $route;
		$method = 'POST';

		$ts    = (string) time();
		$nonce = wp_generate_uuid4();

		$api_key = (string) get_option( self::OPT_KEY );
		$secret  = (string) get_option( self::OPT_SECRET );

		$canon = implode( "\n", [
			$method,
			$route,
			$ts,
			$nonce,
			hash( 'sha256', $body ),
		] );

		$sig_raw = hash_hmac( 'sha256', $canon, $secret, true );
		$sig     = base64_encode( $sig_raw );

		$headers = array_merge( [
			'Content-Type'        => 'application/json',
			'User-Agent'          => 'YOGB-BM-Client/' . ( defined( 'WC_BLACKLIST_MANAGER_VERSION' ) ? WC_BLACKLIST_MANAGER_VERSION : 'dev' ),
			'X-API-Key'           => $api_key,
			'X-Request-Timestamp' => $ts,
			'X-Request-Nonce'     => $nonce,
			'X-Signature'         => $sig,
		], $extra_headers );

		/**
		 * Decide timeout based on context:
		 * - Frontend/checkout/admin: keep this small (e.g. 3s)
		 * - Cron/background: can afford a bit longer (e.g. 12s)
		 */
		$default_timeout = 3;
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			$default_timeout = 12;
		}

		// Allow override via filter if needed.
		$timeout = (int) apply_filters( 'yogb_bm_http_timeout', $default_timeout, $route, $payload );

		$args = [
			'method'      => 'POST',
			'timeout'     => max( 1, $timeout ), // never less than 1s
			'redirection' => 3,
			'blocking'    => true,
			'headers'     => $headers,
			'body'        => $body,
			// Explicit, even though WP defaults to true.
			'sslverify'   => true,
		];

		/**
		 * Final chance to tweak args (debug proxies, etc).
		 */
		$args = apply_filters( 'yogb_bm_http_request_args', $args, $route, $payload );

		$res = wp_remote_post( $url, $args );

		if ( is_wp_error( $res ) ) {
			// Timeout / DNS / SSL / connection issues.
			return [
				'ok'   => false,
				'code' => 0,
				'err'  => $res->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$rb   = (string) wp_remote_retrieve_body( $res );

		return [
			'ok'   => $code >= 200 && $code < 300,
			'code' => $code,
			'body' => $rb,
		];
	}
}

// Register the worker
add_action( YOGB_BM_Report::CRON_HOOK, [ 'YOGB_BM_Report', 'cron_send_report' ] );
