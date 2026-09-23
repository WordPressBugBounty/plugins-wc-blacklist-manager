<?php
/** Closed Core-owned Global descriptors; historical event IDs remain stable. */
defined( 'ABSPATH' ) || exit;
final class WC_Blacklist_Notification_Global_Events {
	public static function connection() {
		$pair = array();
		foreach ( array( false, true ) as $restored ) {
			$heading = $restored ? __( 'Global Blacklist connection restored', 'wc-blacklist-manager' ) : __( 'Global Blacklist connection needs attention', 'wc-blacklist-manager' );
			$pair[] = array( 'id' => $restored ? 'premium.global.connection_restored' : 'premium.global.connection_attention',
				'version' => 1, 'entitlement' => 'free', 'option' => 'wc_blacklist_email_global_connection',
				'subject' => $heading, 'heading' => $heading, 'severity' => $restored ? 'info' : 'warning',
				'fields' => array( 'timestamp', 'reasons' ), 'reasons' => $restored
					? array( 'authenticated_connection_restored' => __( 'The authenticated Global Blacklist connection was restored after an attention episode.', 'wc-blacklist-manager' ) )
					: array( 'authentication_attention' => __( 'Global Blacklist authentication requires administrator attention. Review the connection settings and diagnostics.', 'wc-blacklist-manager' ),
						'reporter_inactive' => __( 'The authenticated Global Blacklist reporter is inactive. Review the connection settings for the next action.', 'wc-blacklist-manager' ) ),
				'cooldown' => null, 'action' => 'global_connection' );
		}
		return $pair;
	}
	public static function usage() {
		$heads = array( __( 'Global Blacklist usage reached 75%', 'wc-blacklist-manager' ), __( 'Global Blacklist usage reached 90%', 'wc-blacklist-manager' ), __( 'Global Blacklist usage limit reached', 'wc-blacklist-manager' ), __( 'Global Blacklist usage cycle reset', 'wc-blacklist-manager' ) );
		$copy = array( __( 'Usage has reached the heads-up threshold for this cycle.', 'wc-blacklist-manager' ), __( 'Review current Global Blacklist usage for this cycle.', 'wc-blacklist-manager' ), __( 'The observed finite Global Blacklist check allowance has been reached. Review usage for the next action.', 'wc-blacklist-manager' ), __( 'A new Global Blacklist usage cycle has started after a previously notified threshold.', 'wc-blacklist-manager' ) );
		$ids = array( 'premium.global.usage_75', 'premium.global.usage_90', 'premium.global.usage_limit_reached', 'premium.global.usage_cycle_reset' );
		$reasons = array( 'usage_75_reached', 'usage_90_reached', 'usage_limit_reached', 'usage_cycle_reset' ); $items = array();
		foreach ( $ids as $i => $id ) {
			$items[] = array( 'id' => $id, 'version' => 1, 'entitlement' => 'free', 'option' => 'wc_blacklist_email_global_usage',
				'subject' => $heads[$i], 'heading' => $heads[$i], 'severity' => array( 'info', 'warning', 'critical', 'info' )[$i],
				'fields' => array( 'timestamp', 'reasons', 'usage_used', 'usage_limit', 'usage_cycle_end' ), 'reasons' => array( $reasons[$i] => $copy[$i] ), 'cooldown' => null, 'action' => 'global_usage' );
		} return $items;
	}
}
