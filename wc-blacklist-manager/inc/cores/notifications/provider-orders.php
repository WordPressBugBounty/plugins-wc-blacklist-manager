<?php
/** One fixed sixteen-slot claim per existing Woo order; no delivery scheduler. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Provider_Orders {
	const META = '_wc_blacklist_notification_rules_v1';
	public static function lock_name( $order_id ) {
		global $wpdb;
		return 'bm-pr1:' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $order_id ), 0, 48 );
	}
	public static function claim( $order_id, $reasons, $ready = true ) {
		$slot = WC_Blacklist_Notification_Provider::rule_slot( $order_id, $reasons );
		if ( false === $slot ) { return 'state_unavailable'; }
		$r = WC_Blacklist_Notification_Provider_Store::run( static function( $db ) use ( $order_id, $slot, $ready ) {
			global $wpdb;
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { throw new RuntimeException(); }
			$store = $order->get_data_store(); $class = $store->get_current_class_name();
			if ( 'WC_Order_Data_Store_CPT' === $class ) { $name = $wpdb->postmeta; $column = 'post_id'; $pk = 'meta_id'; }
			elseif ( 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore' === $class ) { $name = $wpdb->prefix . 'wc_orders_meta'; $column = 'order_id'; $pk = 'id'; }
			else { throw new RuntimeException(); }
			$table = $db->table( $name );
			$indexes = $db->query( "SHOW INDEX FROM {$table} WHERE Seq_in_index=1 AND Sub_part IS NULL AND Column_name='{$column}'" );
			$index = $indexes->fetch_assoc(); $indexes->free();
			if ( ! $index || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $index['Key_name'] ) ) { throw new RuntimeException(); }
			$db->lock( self::lock_name( $order_id ) );
			$sql = "SELECT {$pk},IF(OCTET_LENGTH(meta_value)<=2048,meta_value,NULL) FROM {$table} FORCE INDEX (`{$index['Key_name']}`) WHERE {$column}=" . $order_id . ' AND meta_key=' . $db::hex( self::META ) . ' LIMIT 2';
			$previous = $db->row( $sql );
			$slots = $previous ? json_decode( (string) $previous[1], true ) : array_fill( 0, 16, '' );
			if ( ! is_array( $slots ) || array_keys( $slots ) !== range( 0, 15 ) ) { throw new RuntimeException(); }
			foreach ( $slots as $s ) {
				if ( ! is_string( $s ) || ! preg_match( '/^(?:|failed:[1-3]|attempted:[a-f0-9]{32})$/D', $s ) ) { throw new RuntimeException(); }
			}
			if ( 'failed:3' === $slots[$slot] || 0 === strpos( $slots[$slot], 'attempted:' ) ) { return array( 'status' => 'duplicate' ); }
			$failures = '' === $slots[$slot] ? 0 : (int) substr( $slots[$slot], -1 );
			$slots[$slot] = $ready ? 'attempted:' . bin2hex( random_bytes( 16 ) ) : 'failed:' . ( $failures + 1 );
			$value = json_encode( $slots );
			$meta = (object) array( 'key' => self::META, 'value' => $value, 'id' => $previous ? (int) $previous[0] : 0 );
			// Woo owns HPOS backups and cache lifecycle. Confirm off-cache on the original connection.
			$db->confirm();
			$previous ? $store->update_meta( $order, $meta ) : $store->add_meta( $order, $meta );
			$confirmed = $db->row( $sql );
			if ( ! $confirmed || $value !== $confirmed[1] ) { throw new RuntimeException(); }
			$db->confirm();
			return array( 'status' => $ready ? 'claimed' : ( $failures < 2 ? 'retry' : 'duplicate' ) );
		} );
		return $r['status'];
	}
}
