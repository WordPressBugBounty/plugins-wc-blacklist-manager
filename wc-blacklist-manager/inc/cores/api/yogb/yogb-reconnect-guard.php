<?php
defined( 'ABSPATH' ) || exit;
/** Short, connection-pinned mutation scope shared by reconnect and response writers. */
final class YOGB_BM_Reconnect_Guard {
 private static $connection;
 private static $lock;
 private static $depth = 0;
 public static function context( array $snapshot ): array {
  return [
   'ok' => ! empty( $snapshot['ok'] ), 'authority_token' => (string) ( $snapshot['authority_token'] ?? '' ),
   'allow_recovery' => ! empty( $snapshot['allow_recovery'] ),
   'credential_fingerprint' => (string) ( $snapshot['credential_fingerprint'] ?? '' ),
   'reporter_id' => (int) ( $snapshot['reporter_id'] ?? 0 ), 'recovery_epoch' => (string) ( $snapshot['recovery_epoch'] ?? '' ),
  ];
 }
 public static function current( array $context ): bool {
  if ( empty( $context['ok'] ) || empty( $context['authority_token'] ) || ! YOGB_BM_Registrar::credential_snapshot_is_current( $context ) ) { return false; }
  if ( (string) ( $context['recovery_epoch'] ?? '' ) !== YOGB_BM_Recovery_Store::epoch() ) { return false; }
  $fp = (string) ( $context['credential_fingerprint'] ?? '' );
  global $wpdb;
  $raw = [];
  foreach ( [ YOGB_BM_Registrar::OPT_API_KEY, YOGB_BM_Registrar::OPT_API_SECRET, YOGB_BM_Registrar::OPT_REPORTER_ID ] as $name ) {
   $raw[] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", YOGB_BM_Recovery_Store::option_name( $name ) ) );
  }
  $secret = YOGB_BM_Secret_Store::decrypt_from_storage( $raw[1] );
  if ( '' === $raw[0] || '' === $secret || (int) $raw[2] <= 0 ) { return false; }
  if ( ! empty( $context['reporter_id'] ) && (int) $context['reporter_id'] !== (int) $raw[2] ) { return false; }
  return '' !== $fp && hash_equals( $fp, hash( 'sha256', $raw[0] . "\0" . $secret ) );
 }
 public static function checkpoint(): void {
  global $wpdb;
  if ( $wpdb->dbh !== self::$connection || ! self::$connection instanceof mysqli ) { throw new RuntimeException( 'reconnect_connection_lost' ); }
  $r = self::$connection->query( "SELECT CONNECTION_ID(),IS_USED_LOCK('" . self::$lock . "')" );
  $row = $r->fetch_row(); $r->free();
  if ( ! $row || (string) $row[0] !== (string) $row[1] ) { throw new RuntimeException( 'reconnect_lock_lost' ); }
 }
 /** Never pass a callback containing HTTP. Errors return a bounded unavailable result. */
 public static function run( ?array $context, callable $callback ) {
  global $wpdb;
  if ( self::$depth > 0 ) {
   self::checkpoint();
   YOGB_BM_Recovery_Store::refresh();
   if ( null !== $context && ! self::current( $context ) ) { return [ 'ok' => false, 'error' => 'credential_epoch_changed' ]; }
   return $callback();
  }
  if ( get_class( $wpdb ) !== 'wpdb' || ! $wpdb->dbh instanceof mysqli
   || ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/db.php' ) ) ) {
   return [ 'ok' => false, 'error' => 'reconnect_storage_unavailable' ];
  }
  $report = ( new mysqli_driver() )->report_mode;
  $filter = null; $owned = false;
  try {
   // Throw before wpdb can reconnect/replay a write after named-lock loss.
   mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
   self::$connection = $wpdb->dbh;
   $r = self::$connection->query( 'SELECT @@SESSION.autocommit' ); $row = $r->fetch_row(); $r->free();
   if ( ! $row || '1' !== (string) $row[0] ) { throw new RuntimeException( 'reconnect_borrowed_transaction' ); }
   // MySQL rejects SET TRANSACTION in an existing transaction without committing it.
   // SHOW supports the legacy tx_isolation alias without unknown-variable errors.
   $r = self::$connection->query( "SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation','tx_isolation')" );
   $isolation = ''; $seen = 0;
   while ( $row = $r->fetch_row() ) {
    $value = str_replace( '-', ' ', (string) ( $row[1] ?? '' ) );
    if ( $seen > 0 && $isolation !== $value ) { $r->free(); throw new \RuntimeException( 'reconnect_isolation_ambiguous' ); }
    $isolation = $value; $seen++;
   }
   $r->free();
   if ( $seen < 1 || $seen > 2 ) { throw new \RuntimeException( 'reconnect_isolation_unavailable' ); }
   if ( ! in_array( $isolation, [ 'READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE' ], true ) ) { throw new RuntimeException(); }
   self::$connection->query( 'SET TRANSACTION ISOLATION LEVEL ' . $isolation );
   self::$lock = 'yogb_reconnect_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->options ), 0, 40 );
   $r = self::$connection->query( "SELECT GET_LOCK('" . self::$lock . "',0)" );
   $row = $r->fetch_row(); $r->free();
   if ( ! $row || '1' !== (string) $row[0] ) { return [ 'ok' => false, 'error' => 'reconnect_busy' ]; }
   $owned = true; self::$depth = 1;
   self::checkpoint();
   YOGB_BM_Recovery_Store::refresh();
   $filter = static function( $query ) { self::checkpoint(); return $query; };
   add_filter( 'query', $filter, PHP_INT_MAX );
   if ( null !== $context && ! self::current( $context ) ) { return [ 'ok' => false, 'error' => 'credential_epoch_changed' ]; }
   return $callback();
  } catch ( Throwable $error ) {
   return [ 'ok' => false, 'error' => 'credential_epoch_changed' === $error->getMessage() ? 'credential_epoch_changed' : 'reconnect_storage_unavailable' ];
  } finally {
   if ( $filter ) { remove_filter( 'query', $filter, PHP_INT_MAX ); }
   try { if ( $owned ) { self::$connection->query( "SELECT RELEASE_LOCK('" . self::$lock . "')" ); } } catch ( Throwable $ignored ) {}
   self::$depth = 0; self::$connection = null; self::$lock = null;
   mysqli_report( $report );
  }
 }
}
