<?php
/** Bounded existing-connection scope for internal provider persistence. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_Provider_Store {

	private static $busy = false;
	private $connection;
	private $session;
	private $lock;
	private $deadline;

	public static function run( $callback ) {
		global $wpdb;
		if ( self::$busy ) { return array( 'status' => 'state_unavailable' ); }
		self::$busy = true;
		$db = new self();
		$result = array( 'status' => 'state_unavailable' );
		$errors = null; $report = null; $guard = null;
		set_error_handler( static function() { return true; } );
		try {
			if ( get_class( $wpdb ) !== 'wpdb' || ! $wpdb->dbh instanceof mysqli
				|| ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/db.php' ) ) ) { throw new RuntimeException(); }
			$errors = $wpdb->suppress_errors( true );
			$db->connection = $wpdb->dbh;
			$report = ( new mysqli_driver() )->report_mode;
			// Throw before wpdb can reconnect/replay a Woo metadata mutation after lock loss.
			mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
			$guard = static function( $query ) use ( $db ) { $db->checkpoint(); return $query; };
			add_filter( 'query', $guard, PHP_INT_MAX );
			$db->deadline = microtime( true ) + 1.1;
			$session = $db->row( 'SELECT @@autocommit,@@innodb_lock_wait_timeout,@@lock_wait_timeout,CONNECTION_ID(),VERSION()' );
			if ( '1' !== (string) $session[0] || ! $db->autocommitted() ) { throw new RuntimeException(); }
			$version = preg_replace( '/^5\.5\.5-/', '', $session[4] );
			if ( version_compare( $version, false !== stripos( $version, 'MariaDB' ) ? '10.0.2' : '5.7.5', '<' ) ) { throw new RuntimeException(); }
			$db->session = $session;
			$db->query( 'SET SESSION innodb_lock_wait_timeout=1,lock_wait_timeout=1' );
			$result = $callback( $db );
		} catch ( Throwable $error ) { $result = array( 'status' => 'state_unavailable' ); }
		finally {
			$db->deadline = null;
			try {
				if ( $db->lock && '1' !== (string) $db->row( 'SELECT RELEASE_LOCK(' . self::hex( $db->lock ) . ')' )[0] ) { throw new RuntimeException(); }
			} catch ( Throwable $error ) { $result = array( 'status' => 'state_unavailable' ); }
			try {
				if ( $db->session ) { $db->query( 'SET SESSION innodb_lock_wait_timeout=' . (int) $db->session[1] . ',lock_wait_timeout=' . (int) $db->session[2] ); }
			} catch ( Throwable $error ) { $result = array( 'status' => 'state_unavailable' ); }
			if ( $guard ) { remove_filter( 'query', $guard, PHP_INT_MAX ); }
			if ( null !== $report ) { mysqli_report( $report ); }
			if ( null !== $errors ) { $wpdb->suppress_errors( $errors ); }
			restore_error_handler();
			self::$busy = false;
		}
		return $result;
	}

	public static function hex( $value ) { return '0x' . bin2hex( $value ); }
	public function checkpoint() {
		global $wpdb;
		if ( $wpdb->dbh !== $this->connection || ( null !== $this->deadline && microtime( true ) > $this->deadline ) ) { throw new RuntimeException(); }
	}
	public function query( $sql ) {
		if ( null !== $this->deadline && microtime( true ) > $this->deadline ) { throw new RuntimeException(); }
		$r = $this->connection->query( $sql );
		if ( false === $r ) { throw new RuntimeException(); }
		return $r;
	}
	public function row( $sql ) {
		$r = $this->query( $sql );
		if ( $r->num_rows > 1 ) { $r->free(); throw new RuntimeException(); }
		$row = $r->fetch_row(); $r->free(); return $row;
	}
	public function table( $table ) {
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) { throw new RuntimeException(); }
		$r = $this->row( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . self::hex( $table ) );
		if ( ! $r || 'InnoDB' !== $r[0] ) { throw new RuntimeException(); }
		return '`' . $table . '`';
	}
	public function lock( $name ) {
		if ( $this->lock || '1' !== (string) $this->row( 'SELECT GET_LOCK(' . self::hex( $name ) . ',0)' )[0] ) { throw new RuntimeException(); }
		$this->lock = $name;
	}
	public function confirm() {
		global $wpdb;
		$r = $this->row( 'SELECT CONNECTION_ID(),IS_USED_LOCK(' . self::hex( $this->lock ) . '),@@autocommit' );
		if ( $wpdb->dbh !== $this->connection || $r[0] !== $this->session[3] || $r[1] !== $this->session[3]
			|| '1' !== (string) $r[2] || ! $this->autocommitted() ) { throw new RuntimeException(); }
	}
	private function autocommitted() {
		$name = 'bm_p_' . bin2hex( random_bytes( 12 ) );
		$this->query( 'SAVEPOINT ' . $name );
		try { return false === $this->connection->query( 'RELEASE SAVEPOINT ' . $name ) && 1305 === $this->connection->errno; }
		catch ( mysqli_sql_exception $e ) { return 1305 === $e->getCode(); }
	}
}
