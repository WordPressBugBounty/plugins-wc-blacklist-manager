<?php
/** Dark Sentinel renders only a normalized projection and trusted descriptor. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Renderer {
	public function render( array $descriptor, array $projection ) {
		$lines = array();
		$evidence = array();
		$timestamp = '';
		$audit = 'manual_blacklist_activity' === ( $descriptor['action'] ?? '' );
		$usage = 'global_usage' === ( $descriptor['action'] ?? '' );
		$secondary = '';
		$labels = array( 'count' => __( 'Count', 'wc-blacklist-manager' ), 'timestamp' => __( 'Time', 'wc-blacklist-manager' ), 'email' => __( 'Email hint', 'wc-blacklist-manager' ), 'phone' => __( 'Phone hint', 'wc-blacklist-manager' ), 'ip' => __( 'IP network', 'wc-blacklist-manager' ) );
		foreach ( $labels as $key => $label ) {
			if ( isset( $projection[ $key ] ) ) {
				$value = 'timestamp' === $key ? self::display_time( $projection[ $key ] ) : $projection[ $key ];
				$line = $label . ': ' . $value;
				$lines[] = $line;
				if ( 'timestamp' === $key ) { $timestamp = $line; } else { $evidence[] = $line; }
			}
		}
		if ( $usage ) {
			if ( ! isset( $projection['usage_used'], $projection['usage_limit'], $projection['usage_cycle_end'] ) || $projection['usage_limit'] <= 0 ) { return false; }
			$fact = sprintf( __( '%1$s of %2$s checks used', 'wc-blacklist-manager' ), $projection['usage_used'], $projection['usage_limit'] );
			$end = __( 'Cycle ends: ', 'wc-blacklist-manager' ) . self::display_time( $projection['usage_cycle_end'] );
			$lines[] = $fact; $lines[] = $end; $evidence[] = $fact; $evidence[] = $end;
			if ( class_exists( 'WC_Blacklist_Notification_Usage' ) && 'global_plan_options' === WC_Blacklist_Notification_Usage::plan_action( $descriptor['id'] )
				&& class_exists( 'WC_Blacklist_Manager_Commercial_Router' ) ) {
				$secondary = WC_Blacklist_Manager_Commercial_Router::global_plans_url();
				$lines[] = __( 'View plan options', 'wc-blacklist-manager' ) . ': ' . $secondary;
			}
		}
		if ( $audit || 'security_activity' === ( $descriptor['action'] ?? '' ) ) {
			if ( false === WC_Blacklist_Notification_Context::project( $descriptor, $projection ) ) { return false; }
			$zone = new DateTimeZone( $projection['week_timezone'] );
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$start = ( new DateTimeImmutable( '@' . $projection['week_start'] ) )->setTimezone( $zone )->format( $format );
			$end = ( new DateTimeImmutable( '@' . $projection['week_end'] ) )->setTimezone( $zone )->format( $format );
			$period = sprintf( __( 'Records stamped from %1$s (inclusive) to %2$s (exclusive), %3$s.', 'wc-blacklist-manager' ), $start, $end, $projection['week_timezone'] );
			$lines[] = $period; $evidence[] = $period;
			$totals = $audit
				? array( 'manual_block_records' => __( 'Retained manual block records', 'wc-blacklist-manager' ), 'manual_suspect_records' => __( 'Retained manual suspect records', 'wc-blacklist-manager' ), 'manual_remove_records' => __( 'Retained manual removal records', 'wc-blacklist-manager' ) )
				: array( 'protection_records' => __( 'Retained protection records', 'wc-blacklist-manager' ), 'suspicious_records' => __( 'Retained suspicious-activity records', 'wc-blacklist-manager' ) );
			foreach ( $totals as $key => $label ) {
				$fact = $label . ': ' . $projection[$key]; $lines[] = $fact; $evidence[] = $fact;
			}
		}
		foreach ( $projection['reasons'] ?? array() as $reason ) { $lines[] = $descriptor['reasons'][ $reason ]; $evidence[] = $descriptor['reasons'][ $reason ]; }
		$url = '';
		if ( isset( $projection['order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $projection['order_id'] );
			if ( $order && is_callable( array( $order, 'get_edit_order_url' ) ) ) {
				$candidate = $order->get_edit_order_url();
				// Reconstruct a known admin route; do not trust query fragments/filters.
				$path = wp_parse_url( $candidate, PHP_URL_PATH );
				$base = wp_parse_url( admin_url(), PHP_URL_PATH );
				$url = $path === $base . 'admin.php'
					? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $projection['order_id'] )
					: admin_url( 'post.php?post=' . $projection['order_id'] . '&action=edit' );
			}
		}
		$action_label = __( 'View order', 'wc-blacklist-manager' );
		if ( 'global_connection' === ( $descriptor['action'] ?? '' ) ) {
			$url = admin_url( 'admin.php?page=wc-blacklist-manager-settings#global_blacklist' );
			$action_label = __( 'Review connection', 'wc-blacklist-manager' );
		}
		if ( $usage ) { $url = admin_url( 'admin.php?page=wc-blacklist-manager-settings#global_blacklist' ); $action_label = __( 'Review usage', 'wc-blacklist-manager' ); }
		if ( 'security_activity' === ( $descriptor['action'] ?? '' ) ) {
			$url = admin_url( 'admin.php?page=wc-blacklist-manager-activity-logs' ); $action_label = __( 'Review security activity', 'wc-blacklist-manager' );
		}
		if ( $audit ) { $url = admin_url( 'admin.php?page=wc-blacklist-manager-activity-logs' ); $action_label = __( 'Review manual activity', 'wc-blacklist-manager' ); }
		$footer = __( 'This is an automated message. Please do not reply.', 'wc-blacklist-manager' ) . '<br>Blacklist Manager by <a href="https://yoohw.com">YoOhw Studio</a>';
		if ( WC_Blacklist_Notification_Policy::premium() ) {
			$custom = get_option( 'wc_blacklist_email_footer_text', $footer );
			if ( is_string( $custom ) && '0' !== $custom ) { $footer = $custom; }
		}
		$footer = wp_kses( $footer, array( 'br' => array(), 'strong' => array(), 'em' => array(), 'a' => array( 'href' => true ) ), array( 'https', 'http' ) );
		$view = array( 'heading' => $descriptor['heading'], 'severity' => $descriptor['severity'], 'lines' => $lines, 'evidence' => $evidence, 'timestamp' => $timestamp, 'url' => $url, 'action_label' => $action_label, 'footer' => $footer );
		$root = dirname( __DIR__, 2 ) . '/backend/emails/templates/';
		$text = $this->template( $root . 'dark-sentinel-text.php', $view );
		if ( ! is_string( $text ) || '' === trim( $text ) ) { return false; }
		$html = $this->template( $root . 'dark-sentinel.php', $view );
		if ( $usage && $html ) {
			// Extend only the canonical template's named components. No template fork.
			$percent = min( 100, (int) floor( min( $projection['usage_used'], $projection['usage_limit'] ) / $projection['usage_limit'] * 100 ) );
			$bar = '<div role="presentation" style="margin-top:18px;height:10px;background:#343640;border-radius:8px;overflow:hidden"><div style="height:10px;background:#ff3b3b;width:' . $percent . '%"></div></div>';
			$html = preg_replace_callback( '/(<td class="ds-evidence"[^>]*>)(.*?)(<\/td>)/s', static function( $m ) use ( $bar ) { return $m[1] . $m[2] . $bar . $m[3]; }, $html, 1 );
			if ( $secondary ) {
				$button = ' <a href="' . esc_url( $secondary ) . '" style="display:inline-block;box-sizing:border-box;max-width:100%;margin-top:12px;padding:14px 20px;background:#282a32;border:1px solid #41444f;border-radius:10px;color:#f6f7f9;font-size:18px;line-height:24px;font-weight:700;text-decoration:none">' . esc_html__( 'View plan options', 'wc-blacklist-manager' ) . '</a>';
				$html = preg_replace_callback( '/(<p class="ds-action"[^>]*>)(.*?)(<\/p>)/s', static function( $m ) use ( $button ) { return $m[1] . $m[2] . $button . $m[3]; }, $html, 1 );
			}
		}
		return array( 'subject' => $descriptor['subject'], 'text' => $text, 'html' => $html ?: '' );
	}

	private static function display_time( $timestamp ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		if ( function_exists( 'wp_date' ) && function_exists( 'wp_timezone' ) ) { return wp_date( $format, (int) $timestamp, wp_timezone() ); }
		return gmdate( $format, (int) $timestamp ); // Test-only fallback; WordPress supplies the site timezone path.
	}

	private function template( $file, array $view ) {
		if ( ! is_file( $file ) ) { return false; }
		$level = ob_get_level();
		ob_start();
		try {
			include $file;
			return ob_get_contents();
		} finally {
			while ( ob_get_level() > $level ) { ob_end_clean(); }
		}
	}
}
