<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class YOGB_BM_Client {
	// Server REST prefix (keep route part without /wp-json in the signature path)
	const SERVER_BASE  = 'https://bmc.yoohw.com';
	const REST_ROUTE   = '/yoohw-gbl/v1';
	const OPT_KEY      = 'yogb_bm_api_key';
	const OPT_SECRET   = 'yogb_bm_api_secret';

	// Async hook
	const CRON_HOOK    = 'yogb_bm_send_report';

	// Order meta key
	const META_EVIDENCE_SNAPSHOT = '_yogb_evidence_snapshot_v1';
	const META_EVIDENCE_HASH     = '_yogb_evidence_hash_v1';

	// ---- Public API --------------------------------------------------------

	/** Queue reports for each identity on the order (email, phone, ip, address). */
	public static function queue_report_from_order( WC_Order $order, string $reason_code, string $description = '' ) : void {
		// Build a deterministic snapshot + hash
		$snapshot = self::evidence_material_for_order( $order, $reason_code, $description );
		$ev_hash  = self::compute_evidence_hash( $snapshot );

		// Save locally for auditability (meta + a single note)
		self::persist_evidence_on_order( $order, $snapshot, $ev_hash );

		// Build per-identity payloads (now including evidence_hash)
		$payloads = self::build_payloads_from_order( $order, $reason_code, $description, $ev_hash );

		foreach ( $payloads as $p ) {
			$idemp = self::make_idempotency_key_per_identity( $order, $reason_code, $p['identity'] );
			$envelope = [ 'payload' => $p, 'idempotency' => $idemp ];
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
		if ( ! $payload || ! $idemp ) return;

		$res = self::post_json_signed(
			self::REST_ROUTE . '/reports',
			$payload,
			[ 'Idempotency-Key' => $idemp ]
		);

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
		if ( defined('WP_DEBUG') && WP_DEBUG ) {
			error_log( '[YOGB] report sent code=' . ($res['code'] ?? 0) . ' ok=' . (!empty($res['ok']) ? '1':'0') );
		}
	}

	/** True if we have credentials. */
	public static function is_ready() : bool {
		return (bool) get_option( self::OPT_KEY ) && (bool) get_option( self::OPT_SECRET );
	}

	// ---- Builders ----------------------------------------------------------

	/** Build one payload per identity using your server's schema. */
	private static function build_payloads_from_order( WC_Order $order, string $reason_code, string $description = '', ?string $evidence_hash = null ) : array {
		$payloads = [];

		$billing_country = sanitize_text_field( $order->get_billing_country() );
		$currency        = sanitize_text_field( $order->get_currency() );
		$total           = (float) $order->get_total();
		$email           = sanitize_email( $order->get_billing_email() );
		$phone           = sanitize_text_field( $order->get_billing_phone() );
		$ip              = sanitize_text_field( $order->get_customer_ip_address() );

		$addr_parts = array_filter([
			sanitize_text_field( $order->get_billing_address_1() ),
			sanitize_text_field( $order->get_billing_address_2() ),
			sanitize_text_field( $order->get_billing_city() ),
			sanitize_text_field( $order->get_billing_state() ),
			sanitize_text_field( $order->get_billing_postcode() ),
			$billing_country,
		]);
		$bill_addr = implode( ', ', $addr_parts );

		$ship_parts = array_filter([
			sanitize_text_field( $order->get_shipping_address_1() ),
			sanitize_text_field( $order->get_shipping_address_2() ),
			sanitize_text_field( $order->get_shipping_city() ),
			sanitize_text_field( $order->get_shipping_state() ),
			sanitize_text_field( $order->get_shipping_postcode() ),
			sanitize_text_field( $order->get_shipping_country() ),
		]);
		$ship_addr = implode( ', ', $ship_parts );

		if ( $phone ) {
			// Only add prefix if it doesn't already start with '+'
			if ( substr($phone, 0, 1) !== '+' ) {
				$phone = ltrim($phone, '0');
				$country_code = yobm_get_country_code_from_file($billing_country); // e.g., "1"
				$phone = '+' . ( $country_code ? $country_code : '' ) . $phone;
			}
		}

		$idents = [];
		if ( $email )      $idents[] = [ 'type'=>'email',   'value'=>$email ];
		if ( $phone )      $idents[] = [ 'type'=>'phone',   'value'=>$phone ];
		if ( $ip )         $idents[] = [ 'type'=>'ip',      'value'=>$ip ];
		if ( $bill_addr )  $idents[] = [ 'type'=>'address', 'value'=>$bill_addr ];
		if ( $ship_addr && $ship_addr !== $bill_addr ) {
			$idents[] = [ 'type'=>'address', 'value'=>$ship_addr ];
		}

		$ttl_days = (int) get_option( 'yogb_bm_default_ttl_days', 365 );
		$ttl_days = max( 1, min( 1095, $ttl_days ) ); // cap to server limits

		foreach ( $idents as $ident ) {
			$ctx = [
				'reason_code'  => sanitize_key( $reason_code ),
				'description'  => (string) $description,
				'order_amount' => $total,
				'currency'     => $currency,
				'country'      => $billing_country,
				'ip'           => $ip,
			];
			if ( $evidence_hash ) {
				$ctx['evidence_hash'] = $evidence_hash; // ★ include the hash
			}

			$payloads[] = [
				'identity' => $ident,
				'context'  => $ctx,
				'ttl_days' => $ttl_days,
			];
		}
		return $payloads;
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
		$email_norm = strtolower( trim( sanitize_email( $order->get_billing_email() ) ) );
		$phone_e164 = preg_replace('/\D+/', '', (string) $order->get_billing_phone() );
		$ip_raw     = sanitize_text_field( $order->get_customer_ip_address() );

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
			$order->add_order_note( 'BMC evidence hash: ' . $hash, false );
			$order->update_meta_data( '_yogb_evidence_noted', 1 );
		}

		// Persist to the correct datastore (posts or HPOS tables)
		$order->save();
	}

	// ---- HTTP (signed) -----------------------------------------------------

	/** POST JSON with HMAC headers to the server. (unchanged except header name fix) */
	private static function post_json_signed( string $route, array $payload, array $extra_headers = [] ) : array {
		if ( ! self::is_ready() ) return [ 'ok'=>false, 'code'=>0, 'err'=>'not_ready' ];

		$url      = trailingslashit( self::SERVER_BASE ) . 'wp-json' . $route;
		$method   = 'POST';
		$body     = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$ts       = (string) time();
		$nonce    = wp_generate_uuid4();

		$api_key  = (string) get_option( self::OPT_KEY );
		$secret   = (string) get_option( self::OPT_SECRET );

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
			'User-Agent'          => 'YOGB-BM-Client/' . ( defined('WC_BLACKLIST_MANAGER_VERSION') ? WC_BLACKLIST_MANAGER_VERSION : 'dev' ),
			'X-API-Key'           => $api_key,
			'X-Request-Timestamp' => $ts,
			'X-Request-Nonce'     => $nonce,
			'X-Signature'         => $sig,
		], $extra_headers );

		$res  = wp_remote_post( $url, [
			'timeout' => 12,
			'headers' => $headers,
			'body'    => $body,
		] );

		if ( is_wp_error( $res ) ) {
			return [ 'ok'=>false, 'code'=>0, 'err'=>$res->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$rb   = wp_remote_retrieve_body( $res );

		return [ 'ok' => $code >= 200 && $code < 300, 'code'=>$code, 'body'=>$rb ];
	}
}

// Register the worker
add_action( YOGB_BM_Client::CRON_HOOK, [ 'YOGB_BM_Client', 'cron_send_report' ] );
