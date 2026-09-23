<?php
/** The only wp_mail call in the new platform. No global mail hooks. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Transport {
	public function send( array $delivery, array $message ) {
		$html = '' !== $message['html'];
		$headers = array(
			'Content-Type: ' . ( $html ? 'text/html' : 'text/plain' ) . '; charset=UTF-8',
			'From: "' . addcslashes( $delivery['name'], '"\\' ) . '" <' . $delivery['from'] . '>',
		);
		if ( ! $html ) { return true === wp_mail( $delivery['to'], $message['subject'], $message['text'], $headers ); }
		$file = dirname( __DIR__, 2 ) . '/backend/emails/assets/dark-sentinel-logo.png';
		if ( is_readable( $file ) && self::native_embeds_available() ) {
			return true === wp_mail( $delivery['to'], $message['subject'], $message['html'], $headers, array(), array( 'wc-blacklist-dark-sentinel-logo' => $file ) );
		}
		$token = hash( 'sha256', serialize( array( $delivery['to'], $message['subject'], $message['html'] ) ) );
		$headers[] = 'X-WC-Blacklist-Notification-CID: ' . $token;
		$hook = static function( $mailer ) use ( $file, $message, $token ) {
			$headers = method_exists( $mailer, 'getCustomHeaders' ) ? $mailer->getCustomHeaders() : array();
			$bound = false; foreach ( $headers as $header ) { if ( isset( $header[0], $header[1] ) && 'X-WC-Blacklist-Notification-CID' === $header[0] && hash_equals( $token, trim( $header[1] ) ) ) { $bound = true; break; } }
			if ( $bound && is_readable( $file ) && false !== strpos( (string) $mailer->Body, 'cid:wc-blacklist-dark-sentinel-logo' ) ) { try { $mailer->addEmbeddedImage( $file, 'wc-blacklist-dark-sentinel-logo', 'dark-sentinel-logo.png', 'base64', 'image/png' ); } catch ( Throwable $ignored ) {} }
		};
		add_action( 'phpmailer_init', $hook );
		try { return true === wp_mail( $delivery['to'], $message['subject'], $message['html'], $headers ); } finally { remove_action( 'phpmailer_init', $hook ); }
	}
	private static function native_embeds_available() { try { return 6 <= ( new ReflectionFunction( 'wp_mail' ) )->getNumberOfParameters(); } catch ( ReflectionException $ignored ) { return false; } }
}
