<?php
/** Background-only fixed-slot cooldown. The database is the sole authority. */
defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Notification_State {
	const OPTION = 'wc_blacklist_notification_windows_v1';
	private $unavailable = false;

	public static function lock_name( $database, $table ) {
		return 'bm-n1:' . substr( hash( 'sha256', $database . '|' . $table ), 0, 50 );
	}

	public function claim( array $descriptor ) {
		if ( $this->unavailable || ! WC_Blacklist_Notification_Policy::background() ) { return 'state_unavailable'; }
		$c = $descriptor['cooldown'] ?? null;
		if ( ! is_array( $c ) || ! isset( $c['slot'], $c['seconds'] ) || ! is_int( $c['slot'] ) || $c['slot'] < 0 || $c['slot'] > 31 || ! is_int( $c['seconds'] ) || $c['seconds'] < 900 || $c['seconds'] > 86400 ) { return 'state_unavailable'; }
		global $wpdb;
		$conn = null;
		$status = 'state_unavailable';
		// mysqli on older PHP may raise warnings containing connection details.
		set_error_handler( static function() { return true; } );
		try {
			// Do not change the ambient WordPress connection/transaction or its settings.
			// Separate bounded connection: timeout/uncertain commit can never permit mail.
			if ( ! class_exists( 'mysqli' ) || ! defined( 'MYSQLI_OPT_READ_TIMEOUT' ) || ! isset( $wpdb->options ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $wpdb->options ) || ! method_exists( $wpdb, 'parse_db_host' ) ) { return $status; }
			$host = $wpdb->parse_db_host( DB_HOST );
			if ( ! is_array( $host ) || 4 !== count( $host ) ) { return $status; }
			list( $hostname, $port, $socket, $ipv6 ) = $host;
			if ( 'localhost' === $hostname && ! $socket ) { $socket = ini_get( 'mysqli.default_socket' ); }
			if ( ! $socket && ! filter_var( $hostname, FILTER_VALIDATE_IP ) ) { return $status; }
			if ( $ipv6 ) { $hostname = '[' . $hostname . ']'; }
			$conn = mysqli_init();
			if ( ! $conn || ! $conn->options( MYSQLI_OPT_CONNECT_TIMEOUT, 1 ) || ! $conn->options( MYSQLI_OPT_READ_TIMEOUT, 1 ) ) { return $status; }
			// Remote WordPress installations may require SSL or a database drop-in.
			// Unsupported connection customizations are refused, never silently downgraded.
			if ( ( defined( 'MYSQL_CLIENT_FLAGS' ) && MYSQL_CLIENT_FLAGS ) || ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/db.php' ) ) ) { return $status; }
			if ( ! $conn->real_connect( $hostname, DB_USER, DB_PASSWORD, DB_NAME, $port ?: 0, $socket ?: null ) ) { return $status; }
			if ( ! $conn->set_charset( 'utf8mb4' ) || ! $conn->query( 'SET SESSION innodb_lock_wait_timeout=1, lock_wait_timeout=1' ) ) { return $status; }
			$table = $wpdb->options;
			$table_hex = '0x' . bin2hex( $table );
			$engine = $this->row( $conn, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $table_hex );
			if ( ! $engine || 'InnoDB' !== $engine[0] ) { return $status; }
			$index = $this->row( $conn, "SHOW INDEX FROM `{$table}` WHERE Key_name='option_name'" );
			if ( ! $index || '0' !== (string) $index[1] || 'option_name' !== $index[4] || null !== $index[7] ) { return $status; }
			$lock = '0x' . bin2hex( self::lock_name( DB_NAME, $table ) );
			$held = $this->row( $conn, 'SELECT GET_LOCK(' . $lock . ',0)' );
			if ( ! $held || '1' !== (string) $held[0] ) { return $status; }
			$key = '0x' . bin2hex( self::OPTION );
			// Never transfer an oversized option from the server or use cached options.
			$row = $this->row( $conn, "SELECT IF(OCTET_LENGTH(option_value)<=16384,option_value,NULL) FROM `{$table}` WHERE option_name={$key}" );
			$state = null === $row ? array() : json_decode( (string) $row[0], true );
			$now = time();
			if ( ! $this->valid( $state, $now ) ) { return $status; }
			$slot = $c['slot'];
			if ( isset( $state[ $slot ] ) && $state[ $slot ]['until'] > $now ) { $status = 'cooldown'; return $status; }
			$state[ $slot ] = array( 'descriptor' => hash( 'sha256', $descriptor['id'] ), 'until' => $now + $c['seconds'], 'claim' => bin2hex( random_bytes( 16 ) ) );
			$value = json_encode( $state );
			if ( false === $value || strlen( $value ) > 16384 ) { return $status; }
			$value = '0x' . bin2hex( $value );
			$sql = null === $row
				? "INSERT INTO `{$table}` (option_name,option_value,autoload) VALUES ({$key},{$value},'no')"
				: "UPDATE `{$table}` SET option_value={$value},autoload='no' WHERE option_name={$key}";
			if ( ! $conn->query( $sql ) || 1 !== $conn->affected_rows ) { return $status; }
			$released = $this->row( $conn, 'SELECT RELEASE_LOCK(' . $lock . ')' );
			if ( ! $released || '1' !== (string) $released[0] ) { return $status; }
			$status = 'claimed';
		} catch ( Throwable $error ) {
			// Neither SQL errors, credentials nor exception messages enter result/logs.
			$status = 'state_unavailable';
		} finally {
			if ( $conn instanceof mysqli ) {
				try { $conn->close(); } catch ( Throwable $error ) { $status = 'state_unavailable'; }
			}
			restore_error_handler();
			if ( 'state_unavailable' === $status ) { $this->unavailable = true; }
		}
		return $status;
	}

	private function row( $conn, $sql ) {
		$result = $conn->query( $sql );
		if ( false === $result ) { throw new RuntimeException( 'state_unavailable' ); }
		if ( $result->num_rows > 1 ) { $result->free(); throw new RuntimeException( 'state_unavailable' ); }
		$row = $result->fetch_row();
		$result->free();
		return $row;
	}

	private function valid( $state, $now ) {
		if ( ! is_array( $state ) || count( $state ) > 32 ) { return false; }
		foreach ( $state as $slot => $entry ) {
			if ( ! is_int( $slot ) || $slot < 0 || $slot > 31 || ! is_array( $entry ) || count( $entry ) !== 3
				|| ! isset( $entry['descriptor'], $entry['until'], $entry['claim'] ) || ! is_int( $entry['until'] ) || $entry['until'] < 0 || $entry['until'] > $now + 86400
				|| ! is_string( $entry['descriptor'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $entry['descriptor'] )
				|| ! is_string( $entry['claim'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $entry['claim'] ) ) { return false; }
		}
		return true;
	}
}
