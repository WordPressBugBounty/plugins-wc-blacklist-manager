<?php

if (!defined('ABSPATH')) {
	exit;
}

if ( ! function_exists( 'yoohw_store_debug_blob' ) ) {
	/**
	 * Store a debug blob in the most reliable place available and return where it went.
	 * Tries, in order: Uploads dir, wp-content/yoohw-debug, plugin dir, Options table.
	 */
	function yoohw_store_debug_blob( string $basename, string $content ) : array {
		$stored = [
			'path'   => '',
			'where'  => '',
			'option' => '',
		];

		// 1) Try uploads
		$upload_dir = wp_upload_dir();
		if ( ! empty($upload_dir['basedir']) && is_writable( $upload_dir['basedir'] ) ) {
			$dir = trailingslashit( $upload_dir['basedir'] ) . 'yoohw-debug';
			if ( ! is_dir($dir) ) @wp_mkdir_p($dir);
			if ( is_dir($dir) && is_writable($dir) ) {
				$file = $dir . '/' . $basename;
				if ( @file_put_contents($file, $content) !== false ) {
					$stored['path']  = $file;
					$stored['where'] = 'uploads';
					return $stored;
				}
			}
		}

		// 2) Try /wp-content/yoohw-debug
		$wp_content = WP_CONTENT_DIR;
		if ( is_writable( $wp_content ) ) {
			$dir = trailingslashit( $wp_content ) . 'yoohw-debug';
			if ( ! is_dir($dir) ) @wp_mkdir_p($dir);
			if ( is_dir($dir) && is_writable($dir) ) {
				$file = $dir . '/' . $basename;
				if ( @file_put_contents($file, $content) !== false ) {
					$stored['path']  = $file;
					$stored['where'] = 'wp-content';
					return $stored;
				}
			}
		}

		// 3) Try plugin folder (current file’s directory)
		$plug_dir = plugin_dir_path( __FILE__ );
		if ( is_writable( $plug_dir ) ) {
			$file = trailingslashit($plug_dir) . $basename;
			if ( @file_put_contents($file, $content) !== false ) {
				$stored['path']  = $file;
				$stored['where'] = 'plugin';
				return $stored;
			}
		}

		// 4) Last resort: Options table
		$opt_key = 'yoohw_debug_' . md5( $basename . microtime(true) );
		update_option( $opt_key, $content, false ); // not autoloaded
		$stored['option'] = $opt_key;
		$stored['where']  = 'option';
		return $stored;
	}
}