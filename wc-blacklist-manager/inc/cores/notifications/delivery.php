<?php
/** Resource-scoped background claim in WooCommerce's existing order metadata. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Order_Claim {
	const META = '_wc_blacklist_suspect_mail_v1';
	private static $busy = false;

	public static function lock_name( $order_id ) {
		global $wpdb;
		return 'bm-sm1:' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $order_id ), 0, 48 );
	}

	public static function claim( $order_id, $ready = true ) {
		if ( self::$busy || ! WC_Blacklist_Notification_Free_Events::worker( $order_id ) ) { return 'state_unavailable'; }
		global $wpdb;
		$conn = null; $session = null; $held = false; $status = 'state_unavailable';
		self::$busy = true;
		// Do not leak SQL, credentials or customer data through PHP warnings.
		set_error_handler( static function() { return true; } );
		try {
			if ( ! $wpdb->dbh instanceof mysqli ) { throw new RuntimeException(); }
			// Use the already authenticated connection (including hostname/TLS), never
			// a separate credential/DNS adapter. Raw reads cannot use stale object caches
			// or wpdb's reconnect/replay path. Refuse an ambient transaction.
			$conn = $wpdb->dbh;
			$session = self::row( $conn, 'SELECT @@session.autocommit,@@session.innodb_lock_wait_timeout,@@session.lock_wait_timeout,CONNECTION_ID()' );
			if ( '1' !== (string) $session[0] || ! self::autocommitted( $conn ) ) { $session = null; throw new RuntimeException(); }
			self::query( $conn, 'SET SESSION innodb_lock_wait_timeout=1, lock_wait_timeout=1' );
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { throw new RuntimeException(); }
			$store = $order->get_data_store();
			$class = $store->get_current_class_name();
			if ( 'WC_Order_Data_Store_CPT' === $class ) { $table = $wpdb->postmeta; $column = 'post_id'; $id_column = 'meta_id'; }
			elseif ( 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore' === $class ) { $table = $wpdb->prefix . 'wc_orders_meta'; $column = 'order_id'; $id_column = 'id'; }
			else { throw new RuntimeException(); }
			if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) || ! $wpdb->dbh instanceof mysqli ) { throw new RuntimeException(); }

			$engine = self::row( $conn, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=0x' . bin2hex( $table ) );
			if ( ! $engine || 'InnoDB' !== $engine[0] ) { throw new RuntimeException(); }
			$indexes = self::query( $conn, "SHOW INDEX FROM `{$table}` WHERE Seq_in_index=1 AND Sub_part IS NULL AND Column_name='" . $column . "'" );
			$index = $indexes->fetch_assoc(); $indexes->free();
			if ( ! $index || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $index['Key_name'] ) ) { throw new RuntimeException(); }
			$lock = '0x' . bin2hex( self::lock_name( $order_id ) );
			$claim = self::row( $conn, 'SELECT GET_LOCK(' . $lock . ',0)' );
			if ( ! $claim || '1' !== (string) $claim[0] ) { throw new RuntimeException(); }
			$held = true;
			$sql = "SELECT {$id_column},IF(OCTET_LENGTH(meta_value)<=64,meta_value,NULL) FROM `{$table}` FORCE INDEX (`{$index['Key_name']}`) WHERE {$column}=" . (int) $order_id . ' AND meta_key=0x' . bin2hex( self::META ) . ' LIMIT 2';
			$previous = self::row( $conn, $sql );
			if ( $previous && preg_match( '/^attempted:[a-f0-9]{32}$/D', (string) $previous[1] ) ) { $status = 'duplicate'; }
			else {
				$failures = 0;
				if ( $previous ) {
					if ( ! preg_match( '/^failed:([1-3])$/D', (string) $previous[1], $match ) ) { throw new RuntimeException(); }
					$failures = (int) $match[1];
				}
				if ( $failures >= 3 ) { $status = 'duplicate'; }
				else {
					$value = $ready ? 'attempted:' . bin2hex( random_bytes( 16 ) ) : 'failed:' . ( $failures + 1 );
					$meta = (object) array( 'key' => self::META, 'value' => $value, 'id' => $previous ? (int) $previous[0] : 0 );
					// Woo owns HPOS backfill, metadata lifecycle and cache invalidation.
					$previous ? $store->update_meta( $order, $meta ) : $store->add_meta( $order, $meta );
					$confirmed = self::row( $conn, $sql );
					$ownership = self::row( $conn, 'SELECT CONNECTION_ID(),IS_USED_LOCK(' . $lock . '),@@session.autocommit' );
					if ( $wpdb->dbh !== $conn || ! $confirmed || $value !== $confirmed[1] || $ownership[0] !== $session[3] || $ownership[1] !== $session[3] || '1' !== (string) $ownership[2] || ! self::autocommitted( $conn ) ) { throw new RuntimeException(); }
					$status = $ready ? 'claimed' : ( $failures < 2 ? 'retry' : 'duplicate' );
				}
			}
		} catch ( Throwable $error ) {
			$status = 'state_unavailable';
		} finally {
			try {
				if ( $held ) {
					$released = self::row( $conn, 'SELECT RELEASE_LOCK(' . $lock . ')' );
					if ( ! $released || '1' !== (string) $released[0] ) { $status = 'state_unavailable'; }
				}
				if ( $session ) { self::query( $conn, 'SET SESSION innodb_lock_wait_timeout=' . (int) $session[1] . ', lock_wait_timeout=' . (int) $session[2] ); }
			} catch ( Throwable $error ) { $status = 'state_unavailable'; }
			restore_error_handler();
			self::$busy = false;
		}
		// Only a confirmed, autocommitted marker with released lock permits mail.
		return $status;
	}

	private static function autocommitted( $conn ) {
		// autocommit=1 alone does not exclude START TRANSACTION. SAVEPOINT is a
		// no-op outside a transaction; RELEASE returns 1305 only in that case.
		// A random probe cannot replace a caller's savepoint, commit or roll back.
		$name = 'bm_probe_' . bin2hex( random_bytes( 12 ) );
		self::query( $conn, 'SAVEPOINT ' . $name );
		try {
			$result = $conn->query( 'RELEASE SAVEPOINT ' . $name );
			return false === $result && 1305 === $conn->errno;
		} catch ( mysqli_sql_exception $error ) { return 1305 === $error->getCode(); }
	}

	private static function query( $conn, $sql ) {
		$result = $conn->query( $sql );
		if ( false === $result ) { throw new RuntimeException(); }
		return $result;
	}

	private static function row( $conn, $sql ) {
		$result = self::query( $conn, $sql );
		if ( $result->num_rows > 1 ) { $result->free(); throw new RuntimeException(); }
		$row = $result->fetch_row(); $result->free();
		return $row;
	}
}
