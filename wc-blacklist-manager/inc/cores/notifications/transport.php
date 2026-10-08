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
		return true === wp_mail( $delivery['to'], $message['subject'], $html ? $message['html'] : $message['text'], $headers );
	}
}
