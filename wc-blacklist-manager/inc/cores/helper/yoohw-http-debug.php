<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('YoOhw_HTTP_Debug')) {
	/**
	 * YoOhw HTTP Debug Helper
	 * Collects rich debug info around a single wp_remote_* request.
	 */
	class YoOhw_HTTP_Debug {
		private static $data = [];

		public static function begin(string $label, string $url, array $payload = []) : void {
			self::$data = [
				'label'        => $label,
				'url'          => $url,
				'started_at'   => microtime(true),
				'ended_at'     => null,
				'duration_ms'  => null,
				'payload'      => $payload,
				'dns'          => self::dns_report($url),
				'env'          => self::env_report(),
				'http'         => [
					'status'  => null,
					'headers' => [],
					'body_excerpt' => '',
				],
				'wp_error'     => null,
			];

			// Attach a one-off http_api_debug listener to capture the response.
			add_action('http_api_debug', [__CLASS__, 'capture_http_api_debug'], 10, 5);
		}

		public static function end($response) : void {
			self::$data['ended_at']    = microtime(true);
			self::$data['duration_ms'] = round( (self::$data['ended_at'] - self::$data['started_at']) * 1000 );

			// Capture WP_Error details (transport errors, including curl error 28)
			if ( is_wp_error($response) ) {
				self::$data['wp_error'] = [
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
					'data'    => $response->get_error_data(),
				];
			} else {
				$code    = wp_remote_retrieve_response_code($response);
				$headers = wp_remote_retrieve_headers($response);
				$body    = wp_remote_retrieve_body($response);

				self::$data['http']['status']       = $code;
				self::$data['http']['headers']      = is_array($headers) ? $headers : (array) $headers;
				self::$data['http']['body_excerpt'] = wp_html_excerpt($body, 1000);
			}

			// Remove listener so we don’t capture unrelated requests.
			remove_action('http_api_debug', [__CLASS__, 'capture_http_api_debug'], 10);
		}

		public static function export() : array {
			return self::$data;
		}

		public static function as_text(array $extra = []) : string {
			$d = array_merge(self::$data, $extra);

			$lines = [];
			$lines[] = "Label: " . ($d['label'] ?? '');
			$lines[] = "URL: " . ($d['url'] ?? '');
			$lines[] = "Started: " . (isset($d['started_at']) ? sprintf('%.6f', $d['started_at']) : '');
			$lines[] = "Ended:   " . (isset($d['ended_at']) ? sprintf('%.6f', $d['ended_at']) : '');
			$lines[] = "Duration: " . ($d['duration_ms'] ?? '') . " ms";

			// Env
			$lines[] = "";
			$lines[] = "== Environment ==";
			foreach ( ($d['env'] ?? []) as $k => $v ) {
				if (is_array($v)) $v = json_encode($v);
				$lines[] = "$k: $v";
			}

			// DNS
			$lines[] = "";
			$lines[] = "== DNS ==";
			foreach ( ($d['dns'] ?? []) as $k => $v ) {
				if (is_array($v)) $v = json_encode($v);
				$lines[] = "$k: $v";
			}

			// Payload
			$lines[] = "";
			$lines[] = "== Request Payload (sanitized) ==";
			$san = $d['payload'] ?? [];
			if (isset($san['license_key'])) {
				$san['license_key'] = substr($san['license_key'], 0, 6) . '…' . substr($san['license_key'], -4);
			}
			$lines[] = json_encode($san);

			// WP Error
			if ( ! empty($d['wp_error']) ) {
				$lines[] = "";
				$lines[] = "== WP Error ==";
				$lines[] = "code: " . ($d['wp_error']['code'] ?? '');
				$lines[] = "message: " . ($d['wp_error']['message'] ?? '');
				$lines[] = "data: " . json_encode($d['wp_error']['data'] ?? null);
			}

			// HTTP response
			$lines[] = "";
			$lines[] = "== HTTP Response ==";
			$lines[] = "status: " . ($d['http']['status'] ?? '');
			$lines[] = "headers: " . json_encode($d['http']['headers'] ?? []);
			$lines[] = "body excerpt:\n" . ($d['http']['body_excerpt'] ?? '');

			return implode("\n", $lines);
		}

		public static function capture_http_api_debug($response, $context, $class, $args, $url) : void {
			// We only care about our target URL.
			if (empty(self::$data) || empty(self::$data['url']) || stripos($url, self::$data['url']) !== 0) {
				return;
			}
			// We don’t overwrite here; end() already copies details. This hook is mainly here
			// if you want to capture $context/$class/$args for deeper troubleshooting:
			self::$data['transport'] = [
				'context' => $context,                // e.g., 'response'
				'class'   => is_object($class) ? get_class($class) : (string) $class,
				'args'    => [
					'timeout'     => $args['timeout'] ?? null,
					'redirection' => $args['redirection'] ?? null,
					'headers'     => $args['headers'] ?? null,
					'blocking'    => $args['blocking'] ?? null,
				],
			];
		}

		private static function dns_report(string $url) : array {
			$host = parse_url($url, PHP_URL_HOST);
			if (!$host) return [];

			$report = ['host' => $host];

			// A records (IPv4)
			$A = function_exists('gethostbynamel') ? (array) gethostbynamel($host) : [];
			$report['A'] = $A;

			// AAAA records (IPv6)
			if (function_exists('dns_get_record')) {
				$aaaa = @dns_get_record($host, DNS_AAAA);
				if (is_array($aaaa)) {
					$report['AAAA'] = array_map(
						fn($r) => $r['ipv6'] ?? '',
						array_filter($aaaa, fn($r) => isset($r['ipv6']))
					);
				}
			}

			// Quick TCP probe (non-blocking-ish): try IPv4 connect, then IPv6.
			$report['probe_v4'] = self::probe('tcp://'.$host.':443', 3);
			$report['probe_v6'] = self::probe('tcp://['.$host.']:443', 3); // bracket form; may still fail

			return $report;
		}

		private static function probe(string $endpoint, int $timeout) : string {
			$errno = 0; $errstr = '';
			$start = microtime(true);
			$fp = @stream_socket_client($endpoint, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
			$dur = round((microtime(true) - $start) * 1000);
			if ($fp) { fclose($fp); return "OK ({$dur} ms)"; }
			return "FAIL {$errno}: {$errstr} ({$dur} ms)";
		}

		private static function env_report() : array {
			global $wp_version;
			return [
				'wp_version'      => $wp_version ?? '',
				'php_version'     => PHP_VERSION,
				'curl_version'    => function_exists('curl_version') ? (curl_version()['version'] ?? '') : 'n/a',
				'ssl_library'     => function_exists('curl_version') ? (curl_version()['ssl_version'] ?? '') : (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'n/a'),
				'site'            => home_url(),
				'server_addr'     => $_SERVER['SERVER_ADDR'] ?? '',
				'http_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
			];
		}
	}
}