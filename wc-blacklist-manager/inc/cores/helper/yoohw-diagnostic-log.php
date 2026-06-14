<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'yoohw_diagnostic_logging_enabled' ) ) {
	function yoohw_diagnostic_logging_enabled() {
		return '1' === (string) get_option( 'yoohw_settings_logger', '0' );
	}
}

if ( ! function_exists( 'yoohw_diagnostic_log' ) ) {
	function yoohw_diagnostic_log( $channel, $event, array $data = array(), $level = 'info' ) {
		if ( ! yoohw_diagnostic_logging_enabled() ) {
			return false;
		}

		$dir = yoohw_diagnostic_log_dir();
		if ( '' === $dir ) {
			return false;
		}

		yoohw_diagnostic_log_cleanup( $dir );

		$payload = array(
			'time'    => gmdate( 'c' ),
			'level'   => sanitize_key( (string) $level ),
			'channel' => sanitize_key( (string) $channel ),
			'event'   => sanitize_key( (string) $event ),
			'site'    => yoohw_diagnostic_site_host(),
			'data'    => yoohw_diagnostic_mask_value( $data ),
		);

		$line = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $line ) ) {
			return false;
		}

		$file = trailingslashit( $dir ) . 'diagnostic-' . gmdate( 'Ymd' ) . '.log';

		return false !== @file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}
}

if ( ! function_exists( 'yoohw_diagnostic_log_dir' ) ) {
	function yoohw_diagnostic_log_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'yoohw-debug';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$index_file = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, '<!-- Silence is golden. -->' );
		}

		$htaccess_file    = trailingslashit( $dir ) . '.htaccess';
		$htaccess_content = "Options -Indexes\n<Files \"*.log\">\nRequire all denied\n</Files>\n";
		if ( ! file_exists( $htaccess_file ) ) {
			@file_put_contents( $htaccess_file, $htaccess_content );
		} else {
			$current_htaccess = @file_get_contents( $htaccess_file );
			if ( is_string( $current_htaccess ) && false === strpos( $current_htaccess, '<Files "*.log">' ) ) {
				@file_put_contents( $htaccess_file, rtrim( $current_htaccess ) . "\n" . $htaccess_content );
			}
		}

		return $dir;
	}
}

if ( ! function_exists( 'yoohw_diagnostic_log_cleanup' ) ) {
	function yoohw_diagnostic_log_cleanup( $dir ) {
		static $cleaned = false;

		if ( $cleaned ) {
			return;
		}

		$cleaned = true;

		$retention_days = (int) apply_filters( 'yoohw_diagnostic_log_retention_days', 14 );
		$retention_days = max( 1, $retention_days );
		$cutoff         = time() - ( $retention_days * DAY_IN_SECONDS );
		$files          = glob( trailingslashit( $dir ) . 'diagnostic-*.log' );

		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( is_file( $file ) && filemtime( $file ) && filemtime( $file ) < $cutoff ) {
				@unlink( $file );
			}
		}
	}
}

if ( ! function_exists( 'yoohw_diagnostic_log_files' ) ) {
	function yoohw_diagnostic_log_files( $limit = 7 ) {
		$dir = yoohw_diagnostic_log_dir();
		if ( '' === $dir ) {
			return array();
		}

		$files = glob( trailingslashit( $dir ) . 'diagnostic-*.log' );
		if ( ! is_array( $files ) ) {
			return array();
		}

		$files = array_values( array_filter( $files, 'is_readable' ) );
		rsort( $files, SORT_STRING );

		return array_slice( $files, 0, max( 1, (int) $limit ) );
	}
}

if ( ! function_exists( 'yoohw_diagnostic_recent_log_lines' ) ) {
	function yoohw_diagnostic_recent_log_lines( $max_lines = 200, $max_bytes = 200000 ) {
		$max_lines = max( 1, min( 1000, (int) $max_lines ) );
		$max_bytes = max( 1024, min( 1048576, (int) $max_bytes ) );
		$lines     = array();

		foreach ( yoohw_diagnostic_log_files( 7 ) as $file ) {
			$size = filesize( $file );
			if ( ! $size ) {
				continue;
			}

			$read_bytes = min( $size, $max_bytes );
			$handle     = @fopen( $file, 'rb' );
			if ( ! $handle ) {
				continue;
			}

			if ( $size > $read_bytes ) {
				fseek( $handle, -$read_bytes, SEEK_END );
			}

			$chunk = stream_get_contents( $handle );
			fclose( $handle );

			if ( ! is_string( $chunk ) || '' === $chunk ) {
				continue;
			}

			$file_lines = preg_split( '/\R/', trim( $chunk ) );
			if ( ! is_array( $file_lines ) ) {
				continue;
			}

			if ( $size > $read_bytes && count( $file_lines ) > 1 ) {
				array_shift( $file_lines );
			}

			foreach ( array_reverse( $file_lines ) as $line ) {
				$line = trim( (string) $line );
				if ( '' === $line ) {
					continue;
				}

				$lines[] = $line;
				if ( count( $lines ) >= $max_lines ) {
					break 2;
				}
			}
		}

		return array_reverse( $lines );
	}
}

if ( ! function_exists( 'yoohw_diagnostic_active_yoohw_plugins' ) ) {
	function yoohw_diagnostic_active_yoohw_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$plugins        = get_plugins();
		$yoohw_plugins  = array();

		foreach ( $active_plugins as $plugin_file ) {
			if ( empty( $plugins[ $plugin_file ] ) ) {
				continue;
			}

			$plugin = $plugins[ $plugin_file ];
			$name   = isset( $plugin['Name'] ) ? (string) $plugin['Name'] : '';
			$author = isset( $plugin['Author'] ) ? wp_strip_all_tags( (string) $plugin['Author'] ) : '';

			if ( false === stripos( $author, 'yoohw' ) && false === stripos( $name, 'yoohw' ) && false === stripos( $plugin_file, 'wc-' ) ) {
				continue;
			}

			if ( false === stripos( $author, 'yoohw' ) && false === stripos( $plugin_file, 'wc-blacklist-manager' ) && false === stripos( $plugin_file, 'wc-loyalty' ) && false === stripos( $plugin_file, 'wc-advanced' ) && false === stripos( $plugin_file, 'wc-extended-stock-status' ) ) {
				continue;
			}

			$yoohw_plugins[] = array(
				'file'    => $plugin_file,
				'name'    => $name,
				'version' => isset( $plugin['Version'] ) ? (string) $plugin['Version'] : '',
			);
		}

		return $yoohw_plugins;
	}
}

if ( ! function_exists( 'yoohw_diagnostic_support_bundle' ) ) {
	function yoohw_diagnostic_support_bundle( $max_lines = 200 ) {
		global $wp_version;

		$lines = yoohw_diagnostic_recent_log_lines( $max_lines );
		$meta  = array(
			'generated_at_utc'           => gmdate( 'c' ),
			'site'                       => yoohw_diagnostic_site_host(),
			'wp_version'                 => isset( $wp_version ) ? $wp_version : '',
			'php_version'                => PHP_VERSION,
			'woocommerce_version'        => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'diagnostic_logging_enabled' => yoohw_diagnostic_logging_enabled(),
			'log_files_found'            => count( yoohw_diagnostic_log_files( 7 ) ),
			'active_yoohw_plugins'       => yoohw_diagnostic_active_yoohw_plugins(),
		);

		$output  = "YoOhw Support Diagnostic\n";
		$output .= "Generated: " . gmdate( 'c' ) . "\n\n";
		$output .= "Environment:\n";
		$output .= wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$output .= "\n\nRecent diagnostic log lines:\n";
		$output .= $lines ? implode( "\n", $lines ) : '(No diagnostic log lines found yet.)';
		$output .= "\n";

		return $output;
	}
}

if ( ! function_exists( 'yoohw_diagnostic_ajax_support_bundle' ) ) {
	function yoohw_diagnostic_ajax_support_bundle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		check_ajax_referer( 'yoohw_diagnostic_support_bundle', 'nonce' );

		$bundle = yoohw_diagnostic_support_bundle( 200 );

		wp_send_json_success(
			array(
				'bundle'   => $bundle,
				'has_logs' => false === strpos( $bundle, '(No diagnostic log lines found yet.)' ),
			)
		);
	}
}

if ( ! function_exists( 'yoohw_diagnostic_support_tools_html' ) ) {
	function yoohw_diagnostic_support_tools_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$uid      = 'yoohw-diagnostic-support-' . wp_rand( 1000, 999999 );
		$nonce    = wp_create_nonce( 'yoohw_diagnostic_support_bundle' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<div class="yoohw-diagnostic-support-tools" style="margin-top:10px;max-width:760px;">
			<p class="description" style="margin:0 0 8px;">
				<?php esc_html_e( 'When YoOhw support asks for diagnostics, use this button to copy the latest masked diagnostic bundle and paste it into your support ticket.', 'wc-blacklist-manager' ); ?>
			</p>
			<button type="button" class="button" id="<?php echo esc_attr( $uid ); ?>-button">
				<?php esc_html_e( 'Copy support diagnostic', 'wc-blacklist-manager' ); ?>
			</button>
			<span id="<?php echo esc_attr( $uid ); ?>-status" class="description" style="margin-left:8px;"></span>
			<textarea id="<?php echo esc_attr( $uid ); ?>-text" readonly rows="8" style="display:none;width:100%;margin-top:8px;font-family:monospace;font-size:12px;"></textarea>
		</div>
		<script>
		(function() {
			var button = document.getElementById(<?php echo wp_json_encode( $uid . '-button' ); ?>);
			var status = document.getElementById(<?php echo wp_json_encode( $uid . '-status' ); ?>);
			var textarea = document.getElementById(<?php echo wp_json_encode( $uid . '-text' ); ?>);
			if (!button || !status || !textarea) {
				return;
			}

			function setStatus(message) {
				status.textContent = message;
			}

			function fallbackCopy(text) {
				textarea.value = text;
				textarea.style.display = 'block';
				textarea.focus();
				textarea.select();
				try {
					if (document.execCommand('copy')) {
						setStatus(<?php echo wp_json_encode( __( 'Copied.', 'wc-blacklist-manager' ) ); ?>);
						return;
					}
				} catch (e) {}
				setStatus(<?php echo wp_json_encode( __( 'Select the text below and copy it.', 'wc-blacklist-manager' ) ); ?>);
			}

			button.addEventListener('click', function() {
				button.disabled = true;
				setStatus(<?php echo wp_json_encode( __( 'Preparing diagnostic...', 'wc-blacklist-manager' ) ); ?>);

				var body = new URLSearchParams();
				body.set('action', 'yoohw_diagnostic_support_bundle');
				body.set('nonce', <?php echo wp_json_encode( $nonce ); ?>);

				fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString()
				}).then(function(response) {
					return response.json();
				}).then(function(json) {
					if (!json || !json.success || !json.data || !json.data.bundle) {
						throw new Error(json && json.data && json.data.message ? json.data.message : 'Unable to generate diagnostic.');
					}

					var text = json.data.bundle;
					textarea.value = text;

					if (navigator.clipboard && window.isSecureContext) {
						return navigator.clipboard.writeText(text).then(function() {
							textarea.style.display = 'block';
							setStatus(<?php echo wp_json_encode( __( 'Copied.', 'wc-blacklist-manager' ) ); ?>);
						}, function() {
							fallbackCopy(text);
						});
					}

					fallbackCopy(text);
				}).catch(function(error) {
					setStatus(error && error.message ? error.message : <?php echo wp_json_encode( __( 'Unable to generate diagnostic.', 'wc-blacklist-manager' ) ); ?>);
				}).finally(function() {
					button.disabled = false;
				});
			});
		})();
		</script>
		<?php
	}
}

if ( function_exists( 'add_action' ) && ( ! function_exists( 'has_action' ) || false === has_action( 'wp_ajax_yoohw_diagnostic_support_bundle', 'yoohw_diagnostic_ajax_support_bundle' ) ) ) {
	add_action( 'wp_ajax_yoohw_diagnostic_support_bundle', 'yoohw_diagnostic_ajax_support_bundle' );
}

if ( ! function_exists( 'yoohw_diagnostic_site_host' ) ) {
	function yoohw_diagnostic_site_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			$host = home_url();
		}

		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '/^www\./i', '', $host );

		return rtrim( $host, '.' );
	}
}

if ( ! function_exists( 'yoohw_diagnostic_mask_value' ) ) {
	function yoohw_diagnostic_mask_value( $value, $key = '' ) {
		$key = strtolower( (string) $key );

		if ( is_array( $value ) ) {
			$masked = array();
			foreach ( $value as $child_key => $child_value ) {
				$masked[ $child_key ] = yoohw_diagnostic_mask_value( $child_value, (string) $child_key );
			}
			return $masked;
		}

		if ( is_object( $value ) ) {
			return yoohw_diagnostic_mask_value( (array) $value, $key );
		}

		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		$string = (string) $value;

		if ( yoohw_diagnostic_key_is_secret( $key ) ) {
			return '[redacted]';
		}

		if ( false !== strpos( $key, 'email' ) ) {
			return yoohw_diagnostic_mask_email( $string );
		}

		if ( false !== strpos( $key, 'phone' ) ) {
			return yoohw_diagnostic_mask_phone( $string );
		}

		if ( 'ip' === $key || 'ip_address' === $key || substr( $key, -3 ) === '_ip' || false !== strpos( $key, 'ip_' ) ) {
			return yoohw_diagnostic_hash_value( $string );
		}

		if ( false !== strpos( $key, 'address' ) ) {
			return yoohw_diagnostic_hash_value( $string );
		}

		if ( strlen( $string ) > 500 ) {
			return substr( $string, 0, 500 ) . '...';
		}

		return $string;
	}
}

if ( ! function_exists( 'yoohw_diagnostic_key_is_secret' ) ) {
	function yoohw_diagnostic_key_is_secret( $key ) {
		$exact = array(
			'api_key',
			'auth_key',
			'authorization',
			'entitlement',
			'entitlement_token',
			'license_key',
			'nonce',
			'otp',
			'password',
			'secret',
			'token',
			'verification_code',
		);

		if ( in_array( $key, $exact, true ) ) {
			return true;
		}

		foreach ( array( 'secret', 'token', 'password', 'nonce', 'api_key', 'license_key' ) as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'yoohw_diagnostic_mask_email' ) ) {
	function yoohw_diagnostic_mask_email( $email ) {
		if ( false === strpos( $email, '@' ) ) {
			return yoohw_diagnostic_hash_value( $email );
		}

		list( $local, $domain ) = explode( '@', $email, 2 );
		$prefix = '' !== $local ? substr( $local, 0, 1 ) : '';

		return $prefix . '***@' . $domain;
	}
}

if ( ! function_exists( 'yoohw_diagnostic_mask_phone' ) ) {
	function yoohw_diagnostic_mask_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( '' === $digits ) {
			return '[redacted]';
		}

		return '***' . substr( $digits, -4 );
	}
}

if ( ! function_exists( 'yoohw_diagnostic_hash_value' ) ) {
	function yoohw_diagnostic_hash_value( $value ) {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'yoohw' );

		return 'hash:' . substr( hash_hmac( 'sha256', (string) $value, $salt ), 0, 12 );
	}
}
