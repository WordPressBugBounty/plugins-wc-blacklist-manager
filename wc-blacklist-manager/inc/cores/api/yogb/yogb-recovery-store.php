<?php
defined( 'ABSPATH' ) || exit;

/** Storage seam for known legacy writers, not a fence against arbitrary SQL. */
final class YOGB_BM_Recovery_Store {
 const MARKER = 'yogb_bm_successor_connection_v2';
 const CONTRACT = 'bounded_legacy_writes_v2';
 const MAX_ROWS = 1000;
 const MAX_BYTES = 16777216;
 public static function raw( string $name ): ?string {
  global $wpdb;
  $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
  return is_string( $value ) ? $value : null;
 }
 private static $present = null;
 private static $namespace = '';
 public static function refresh(): void { global $wpdb; self::$namespace = $wpdb->options; self::$present = null !== self::raw( self::MARKER ); }
 public static function active(): bool {
  global $wpdb;
  if ( null === self::$present || self::$namespace !== $wpdb->options ) { self::refresh(); }
  return self::$present;
 }
 public static function valid(): bool {
  $raw = self::raw( self::MARKER ); $marker = null !== $raw ? maybe_unserialize( $raw ) : [];
  return is_array( $marker ) && 2 === ( $marker['version'] ?? 0 ) && self::CONTRACT === ( $marker['contract'] ?? '' )
   && preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $marker['server_epoch'] ?? '' ) )
   && (int) ( $marker['reporter'] ?? 0 ) > 0 && (int) ( $marker['verified_at'] ?? 0 ) > 0
   && preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $marker['attempt'] ?? '' ) );
 }
 public static function epoch(): string {
  if ( ! self::active() || ! self::valid() ) { return ''; }
  $marker = maybe_unserialize( self::raw( self::MARKER ) );
  return (string) ( $marker['server_epoch'] ?? '' );
 }
 public static function names(): array {
  return [ 'yogb_bm_api_key', 'yogb_bm_api_secret', 'yogb_bm_reporter_id', 'yogb_bm_auth_recovery_v1',
   'yogb_bm_tier', 'yogb_bm_tier_version', 'yogb_bm_tier_updated_at', 'yogb_bm_tier_last_event_id',
   'yogb_bm_tier_last_source', 'yogb_bm_plan_summary', 'yogb_bm_reporter_status', 'yogb_bm_contribution_summary',
   'yogb_bm_tier_event_cache', 'yogb_bm_server_capabilities', 'yogb_bm_verified_capability_snapshot_v1',
   'yogb_bm_subscription_activation_status', 'yogb_bm_usage_snapshot_v1' ];
 }
 public static function option_name( string $name ): string {
  return in_array( $name, self::names(), true ) && self::active() ? 'yogb_successor_v2_' . $name : $name;
 }
 public static function update_option( string $name, $value, $autoload = false ): bool {
  $target = self::option_name( $name );
  // Ordinary registration must not replace the recovered historical tuple.
  if ( $target !== $name && in_array( $name, [ 'yogb_bm_api_key', 'yogb_bm_api_secret', 'yogb_bm_reporter_id' ], true ) ) { return false; }
  return update_option( $target, $value, $autoload );
 }
 public static function delete_option( string $name ): bool { return delete_option( self::option_name( $name ) ); }
 public static function init(): void {
  foreach ( self::names() as $name ) {
   add_filter( 'pre_option_' . $name, static function( $pre, $option, $default ) use ( $name ) {
    $target = self::option_name( $name );
    if ( $target === $name ) { return $pre; }
    $raw = self::raw( $target );
    // Missing successor storage never falls back to old credentials or tier.
    if ( null === $raw || ! self::valid() ) { return false === $default ? '' : $default; }
    return 'yogb_bm_api_secret' === $name ? YOGB_BM_Secret_Store::decrypt_from_storage( $raw ) : maybe_unserialize( $raw );
   }, PHP_INT_MAX, 3 );
  }
 }
 public static function legacy_table(): string { global $wpdb; return $wpdb->prefix . 'wc_blacklist_gbl_outbox'; }
 public static function queue(): string { return self::legacy_table() . '_v2'; }
 public static function archive(): string { return self::legacy_table() . '_retained_v2'; }
 /** DDL must finish before the final recovery transaction. */
 public static function prepare_schema(): bool {
  global $wpdb;
  if ( ! YOGB_BM_Outbox::reconnect_schema_ready() ) { return false; }
  $queue = self::queue(); $archive = self::archive(); $legacy = self::legacy_table();
  if ( false === $wpdb->query( "CREATE TABLE IF NOT EXISTS {$queue} LIKE {$legacy}" )
   || false === $wpdb->query( "CREATE TABLE IF NOT EXISTS {$archive} (id bigint unsigned NOT NULL PRIMARY KEY, row_bytes longblob NOT NULL, row_sha char(64) NOT NULL, order_id bigint unsigned NOT NULL, event_key varchar(64) NOT NULL, KEY order_id(order_id), UNIQUE KEY event_key(event_key)) ENGINE=InnoDB" ) ) { return false; }
  foreach ( [ $legacy, $queue, $archive, $wpdb->options ] as $table ) {
   if ( 'InnoDB' !== $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ) ) { return false; }
  }
  return true;
 }
 /** Inside a connection-pinned SERIALIZABLE transaction before publication. */
 public static function snapshot(): array {
  global $wpdb;
  $archive = self::archive(); $queue = self::queue(); $legacy = self::legacy_table(); $repeat = self::active();
  if ( ! $repeat && ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$archive}" ) || (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue}" ) ) ) { throw new RuntimeException( 'reconnect_snapshot_conflict' ); }
  $source = $repeat ? $queue : $legacy;
  $rows = $wpdb->get_results( 'SELECT * FROM ' . $source . ' ORDER BY id LIMIT ' . ( self::MAX_ROWS + 1 ) . ' FOR UPDATE', ARRAY_A );
  if ( ! is_array( $rows ) || '' !== $wpdb->last_error || count( $rows ) > self::MAX_ROWS ) { throw new RuntimeException( 'reconnect_snapshot_limit' ); }
  $bytes = 0; $maximum = 0;
  foreach ( $rows as $row ) {
   $raw = serialize( $row ); $bytes += strlen( $raw ); $id = (int) $row['id']; $maximum = max( $maximum, $id );
   if ( $bytes > self::MAX_BYTES ) { throw new RuntimeException( 'reconnect_snapshot_limit' ); }
   $retained = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$archive} WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
   if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'reconnect_snapshot_readback_failed' ); }
   if ( is_array( $retained ) ) {
    $original = maybe_unserialize( $retained['row_bytes'] );
    if ( ! $repeat || ! is_array( $original ) || ! hash_equals( $retained['row_sha'], hash( 'sha256', $retained['row_bytes'] ) )
     || (int) $retained['order_id'] !== (int) $row['order_id'] || $retained['event_key'] !== $row['event_key'] ) { throw new RuntimeException( 'reconnect_snapshot_conflict' ); }
    foreach ( [ 'id', 'payload_json', 'headers_json', 'event_key', 'event_type', 'route', 'order_id' ] as $key ) {
     if ( ! array_key_exists( $key, $original ) || (string) $original[$key] !== (string) $row[$key] ) { throw new RuntimeException( 'reconnect_snapshot_conflict' ); }
    }
   } elseif ( 1 !== $wpdb->insert( $archive, [ 'id' => $id, 'row_bytes' => $raw, 'row_sha' => hash( 'sha256', $raw ), 'order_id' => (int) $row['order_id'], 'event_key' => $row['event_key'] ] )
    || $raw !== $wpdb->get_var( $wpdb->prepare( "SELECT row_bytes FROM {$archive} WHERE id=%d", $id ) ) ) { throw new RuntimeException( 'reconnect_snapshot_readback_failed' ); }
   // A legacy response may already have committed remotely, and its receipt may
   // have expired. Attempts/status cannot prove non-delivery: retain for support
   // reconciliation rather than replay any historical copy automatically.
   $row['status'] = 'quarantined'; $row['last_error'] = 'historical_delivery_unconfirmed'; $row['next_attempt_at'] = null;
   $row['claim_token'] = '';
   if ( $repeat ? false === $wpdb->update( $queue, $row, [ 'id' => $id ] ) : 1 !== $wpdb->insert( $queue, $row ) ) { throw new RuntimeException( 'reconnect_snapshot_copy_failed' ); }
   $copy = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue} WHERE id=%d", $id ), ARRAY_A );
   if ( ! is_array( $copy ) || 'quarantined' !== $copy['status'] || '' !== $copy['claim_token']
    || null !== $copy['next_attempt_at'] || 'historical_delivery_unconfirmed' !== $copy['last_error'] ) { throw new RuntimeException( 'reconnect_snapshot_readback_failed' ); }
   foreach ( [ 'payload_json', 'headers_json', 'event_key', 'event_type', 'route', 'order_id' ] as $key ) {
    if ( ! is_array( $copy ) || (string) $copy[$key] !== (string) $row[$key] ) { throw new RuntimeException( 'reconnect_snapshot_readback_failed' ); }
   }
  }
  return [ 'rows' => count( $rows ), 'bytes' => $bytes, 'legacy_max_id' => $maximum ];
 }
 /** Current-code provenance only; absence is not proof of an old writer. */
 public static function annotate_order( $order ): void {
  if ( ! self::active() || ! self::valid() ) { return; }
  $marker = maybe_unserialize( self::raw( self::MARKER ) );
  $order->update_meta_data( '_yogb_recovery_generation_v2', [ 'attempt' => $marker['attempt'], 'observed_at' => time(), 'status' => $order->get_status() ] );
 }
 public static function attention( $order ): void {
  if ( ! self::active() || ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) { return; }
  $marker = maybe_unserialize( self::raw( self::MARKER ) );
  $provenance = $order->get_meta( '_yogb_recovery_generation_v2', true );
  echo '<p>' . esc_html__( 'Recovery verified at (UTC):', 'wc-blacklist-manager' ) . ' ' . esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $marker['verified_at'] ?? 0 ) ) ) . '</p>';
  if ( is_array( $provenance ) && ( $provenance['attempt'] ?? '' ) === ( $marker['attempt'] ?? '' ) && ( $provenance['status'] ?? '' ) !== $order->get_status() ) {
   echo '<p>' . esc_html__( 'Order status differs from its last upgraded Global projection. Review later merchant, gateway and legacy-worker activity; this is an attention signal, not attribution.', 'wc-blacklist-manager' ) . '</p>';
  }
  echo '<p>' . esc_html__( 'Recovered Global connection: an older in-flight worker may still have changed this order. Review status, notes and payment history before taking action; recovery does not reverse merchant or payment decisions.', 'wc-blacklist-manager' ) . '</p>';
 }
}
YOGB_BM_Recovery_Store::init();
add_action( 'woocommerce_admin_order_data_after_order_details', [ 'YOGB_BM_Recovery_Store', 'attention' ] );
