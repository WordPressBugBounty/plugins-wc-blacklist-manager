<?php
defined( 'ABSPATH' ) || exit;

/** Explicit administrator recovery; initially unavailable without server-owned cutover approval. */
final class YOGB_BM_Reconnect {
 const OPTION = 'yogb_bm_reconnect_v1';
 public static function init(): void {
  add_action( 'yogb_bm_reconnect_inventory', [ __CLASS__, 'inventory' ] );
  add_action( 'admin_post_yogb_reconnect', [ __CLASS__, 'handle' ] );
 }
 private static function raw( string $name ): ?string {
  global $wpdb;
  $v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
  return is_string( $v ) ? $v : null;
 }
 private static function cache_clear(): void {
  YOGB_BM_Recovery_Store::refresh();
  $names = [ self::OPTION, YOGB_BM_Registrar::OPT_API_KEY, YOGB_BM_Registrar::OPT_API_SECRET, YOGB_BM_Registrar::OPT_REPORTER_ID, YOGB_BM_Registrar::OPT_AUTH_RECOVERY, 'alloptions', 'notoptions', 'cron', 'wc_blacklist_enable_global_blacklist', 'yogb_bm_server_capabilities', 'yogb_bm_subscription_activation_status' ];
  foreach ( [ 'YOGB_BM_Tier_Webhook', 'YOGB_BM_Report_V2' ] as $class ) {
   if ( ! class_exists( $class ) ) { continue; }
   foreach ( ( new ReflectionClass( $class ) )->getConstants() as $key => $value ) {
    if ( false !== strpos( $key, 'OPTION' ) && is_string( $value ) ) { $names[] = $value; }
   }
  }
  $names = array_merge( $names, array_map( [ 'YOGB_BM_Recovery_Store', 'option_name' ], $names ) );
  foreach ( array_unique( $names ) as $name ) {
   wp_cache_delete( $name, 'options' );
  }
 }
 public static function state(): array {
  $raw = self::raw( self::OPTION );
  $s = null !== $raw ? maybe_unserialize( $raw ) : [];
  return is_array( $s ) ? $s : [];
 }
 private static function artifact(): string {
  $root = dirname( __DIR__, 4 );
  $files = [];
  foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/inc', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
   if ( $file->isLink() ) { return ''; }
   if ( $file->isFile() && in_array( $file->getExtension(), [ 'php', 'js', 'css' ], true ) ) {
    $files[ substr( $file->getPathname(), strlen( $root ) + 1 ) ] = hash_file( 'sha256', $file->getPathname() );
   }
  }
  foreach ( glob( $root . '/*.php' ) as $file ) { if ( is_link( $file ) ) { return ''; } $files[ basename( $file ) ] = hash_file( 'sha256', $file ); }
  if ( in_array( false, $files, true ) ) { return ''; }
  ksort( $files, SORT_STRING );
  return hash( 'sha256', wp_json_encode( $files, JSON_UNESCAPED_SLASHES ) );
 }
 private static function base(): string {
  $root = defined( 'YOGB_BM_SERVER_BASE' ) ? rtrim( (string) YOGB_BM_SERVER_BASE, '/' ) : YOGB_BM_Registrar::SERVER_BASE;
  if ( false === strpos( $root, '/wp-json/' ) ) { $root .= '/wp-json/yoohw-gbl'; }
  return $root;
 }
 private static function request( string $route, array $payload ): array {
  $url = self::base() . $route;
  if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) { return [ 'error' => 'https_required' ]; }
  $res = wp_safe_remote_post( $url, [ 'timeout' => 8, 'limit_response_size' => 32768, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true,
   'headers' => [ 'Content-Type' => 'application/json', 'Cache-Control' => 'no-store' ], 'body' => wp_json_encode( $payload ) ] );
  if ( is_wp_error( $res ) ) { return [ 'error' => 'reconnect_transport_failed' ]; }
  $body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
  if ( ! is_array( $body ) || 'existing_only' !== ( $body['mode'] ?? '' ) || 2 !== ( $body['reconnect_version'] ?? 0 ) || YOGB_BM_Recovery_Store::CONTRACT !== ( $body['recovery_contract'] ?? '' ) ) {
   return [ 'error' => 'reconnect_mode_not_acknowledged' ];
  }
  $body['http_code'] = (int) wp_remote_retrieve_response_code( $res );
  return $body;
 }
 private static function credential_base(): array {
  $result = [];
  foreach ( [ YOGB_BM_Registrar::OPT_API_KEY, YOGB_BM_Registrar::OPT_API_SECRET, YOGB_BM_Registrar::OPT_REPORTER_ID, 'home', 'siteurl' ] as $name ) {
   $raw = self::raw( YOGB_BM_Recovery_Store::option_name( $name ) );
   $result[ $name ] = hash( 'sha256', ( null === $raw ? 'missing' : 'present:' . $raw ) );
  }
  return $result;
 }
 private static function begin( string $activation ): array {
  $s = self::state();
  if ( isset( $s['expires_at'] ) && (int) $s['expires_at'] > time() && in_array( $s['status'] ?? '', [ 'pending', 'owner_required' ], true ) ) {
   return [ 'error' => 'reconnect_already_pending' ];
  }
  $ready = YOGB_BM_Reconnect_Guard::run( null, static function() {
   global $wpdb;
   $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->options ) );
   return 'InnoDB' === $engine && YOGB_BM_Recovery_Store::prepare_schema();
  } );
  if ( true !== $ready ) { return [ 'error' => 'reconnect_schema_not_ready' ]; }
  $nonce = bin2hex( random_bytes( 32 ) );
  $private = YOGB_BM_Secret_Store::encrypt_strict( $nonce );
  $artifact = self::artifact();
  if ( '' === $private || '' === $artifact ) { return [ 'error' => 'reconnect_encryption_unavailable' ]; }
  $site_url = rtrim( home_url(), '/' );
  $before = self::credential_base();
  $auth = YOGB_BM_Registrar::auth_recovery_state();
  if ( ! empty( $auth['credential_commit'] ) && (int) ( $auth['credential_commit']['expires_at'] ?? 0 ) > time() ) { return [ 'error' => 'credential_commit_in_progress' ]; }
  $reply = self::request( '/public/v1/register/start', [
   'mode' => 'existing_only', 'reconnect_version' => 2, 'recovery_contract' => YOGB_BM_Recovery_Store::CONTRACT, 'requester_nonce' => $nonce, 'core_artifact' => $artifact,
   'site_url' => $site_url, 'site_domain' => strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
   'owner_email' => (string) get_option( 'admin_email', '' ), 'activation_key' => $activation,
  ] );
  unset( $activation, $nonce );
  if ( 201 !== ( $reply['http_code'] ?? 0 ) || ! preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $reply['challenge_id'] ?? '' ) )
   || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $reply['challenge_token'] ?? '' ) )
   || (int) ( $reply['reporter_id'] ?? 0 ) <= 0 || (int) ( $reply['expires_at'] ?? 0 ) <= time()
   || (int) $reply['expires_at'] > time() + 600 ) {
   return [ 'error' => sanitize_key( (string) ( $reply['error'] ?? 'reconnect_start_failed' ) ) ];
  }
  $result = YOGB_BM_Reconnect_Guard::run( null, static function() use ( $reply, $private, $artifact, $before, $auth, $site_url ) {
   $current = YOGB_BM_Registrar::auth_recovery_state();
   if ( $before !== self::credential_base() || $auth['authority_token'] !== $current['authority_token'] ) {
    return [ 'error' => 'reconnect_authority_changed' ];
   }
   $local_id = (int) self::raw( YOGB_BM_Recovery_Store::option_name( YOGB_BM_Registrar::OPT_REPORTER_ID ) );
   if ( $local_id > 0 && $local_id !== (int) $reply['reporter_id'] ) { return [ 'error' => 'reporter_mismatch' ]; }
   foreach ( [ (int) ( $current['expected_reporter_id'] ?? 0 ), (int) ( $current['credential_commit']['candidate_reporter_id'] ?? 0 ) ] as $expected ) {
    if ( $expected > 0 && $expected !== (int) $reply['reporter_id'] ) { return [ 'error' => 'reporter_mismatch' ]; }
   }
   if ( ! empty( $current['credential_commit'] ) ) {
    $lease = json_decode( (string) self::raw( YOGB_BM_Registrar::OPT_AUTH_RECOVERY_LOCK ), true );
    if ( (int) $current['credential_commit']['expires_at'] > time() || (int) ( $lease['expires_at'] ?? 0 ) > time() ) { return [ 'error' => 'credential_commit_in_progress' ]; }
    // Only after server-acknowledged cutover; raw credentials remain untouched.
    // New credential component writers use this same short native guard.
    unset( $current['credential_commit'] );
   }
   $current['state'] = 'manual_intervention'; $current['source'] = 'reconnect_pending';
   $current['expected_reporter_id'] = (int) $reply['reporter_id']; $current['next_attempt_at'] = 0;
   if ( ! YOGB_BM_Registrar::reconnect_write_state( $current ) ) { return [ 'error' => 'reconnect_state_conflict' ]; }
   $fresh = YOGB_BM_Registrar::auth_recovery_state();
   $state = [
    'status' => ! empty( $reply['owner_verification_required'] ) ? 'owner_required' : 'pending',
    'attempt' => wp_generate_uuid4(), 'challenge' => $reply['challenge_id'], 'reporter' => (int) $reply['reporter_id'],
    'private' => $private, 'artifact' => $artifact, 'base' => $before, 'authority_token' => $fresh['authority_token'],
    'site_url' => $site_url, 'expires_at' => (int) $reply['expires_at'],
   ];
   update_option( self::OPTION, $state, false );
   self::cache_clear();
   if ( self::state() !== $state ) { return [ 'error' => 'reconnect_state_readback_failed' ]; }
   set_transient( YOGB_BM_Registrar::TRANSIENT_PREFIX . $reply['challenge_id'], $reply['challenge_token'], max( 1, $state['expires_at'] - time() ) );
   YOGB_BM_Outbox::pause_for_auth();
   return [ 'status' => $state['status'] ];
  } );
  return is_array( $result ) ? $result : [ 'error' => 'reconnect_storage_unavailable' ];
 }
 private static function tier_proof( array $candidate ): array {
  $key = (string) $candidate['api_key']; $secret = (string) $candidate['api_secret']; $ts = (string) time(); $epoch = (string) $candidate['recovery_epoch'];
  $res = wp_safe_remote_get( self::base() . '/v1/client/tier', [
   'timeout' => 8, 'limit_response_size' => 32768, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true,
   'headers' => [ 'X-API-Key' => $key, 'X-Request-Timestamp' => $ts, 'X-YOGB-Recovery-Epoch' => $epoch, 'X-Signature' => base64_encode( hash_hmac( 'sha256', $key . "\n" . $ts . "\n" . $epoch, $secret, true ) ) ],
  ] );
  if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) { return []; }
  $raw = (string) wp_remote_retrieve_body( $res ); $stamp = (string) wp_remote_retrieve_header( $res, 'x-yogb-timestamp' );
  $signature = (string) wp_remote_retrieve_header( $res, 'x-yogb-signature' );
  $body = json_decode( $raw, true );
  if ( ! ctype_digit( $stamp ) || abs( time() - (int) $stamp ) > 300 || ! is_array( $body )
   || (int) ( $body['ts'] ?? 0 ) !== (int) $stamp || (int) ( $body['reporter_id'] ?? 0 ) !== (int) $candidate['reporter_id']
   || 'active' !== ( $body['reporter_status'] ?? '' )
   || strtolower( (string) ( $body['site_domain'] ?? '' ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
   || ! hash_equals( base64_encode( hash_hmac( 'sha256', $raw . "\n" . $stamp, $secret, true ) ), $signature ) ) { return []; }
  return $body;
 }
 private static function verify(): array {
  $state = self::state();
  if ( ! isset( $state['expires_at'], $state['private'], $state['artifact'] ) || (int) $state['expires_at'] <= time()
   || ( $state['site_url'] ?? '' ) !== rtrim( home_url(), '/' ) || ! hash_equals( $state['artifact'], self::artifact() ) ) { return [ 'error' => 'reconnect_attempt_expired' ]; }
  $nonce = YOGB_BM_Secret_Store::decrypt_from_storage( $state['private'] );
  if ( ! preg_match( '/^[a-f0-9]{64}$/D', $nonce ) ) { return [ 'error' => 'reconnect_attempt_unreadable' ]; }
  $candidate = self::request( '/public/v1/register/verify', [
   'mode' => 'existing_only', 'reconnect_version' => 2, 'recovery_contract' => YOGB_BM_Recovery_Store::CONTRACT, 'challenge_id' => $state['challenge'], 'requester_nonce' => $nonce,
  ] );
  unset( $nonce );
  if ( 200 !== ( $candidate['http_code'] ?? 0 ) || (int) ( $candidate['reporter_id'] ?? 0 ) !== (int) $state['reporter']
   || ( $candidate['site_url'] ?? '' ) !== $state['site_url'] || ( $candidate['core_artifact'] ?? '' ) !== $state['artifact']
   || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $candidate['global_artifact'] ?? '' ) )
   || (int) ( $candidate['eligibility_expires_at'] ?? 0 ) <= time()
   || ! preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $candidate['recovery_epoch'] ?? '' ) )
   || '' === (string) ( $candidate['api_key'] ?? '' ) || '' === (string) ( $candidate['api_secret'] ?? '' ) ) {
   return [ 'error' => sanitize_key( (string) ( $candidate['error'] ?? 'reconnect_verification_failed' ) ) ];
  }
  $cipher = YOGB_BM_Secret_Store::encrypt_strict( (string) $candidate['api_secret'] );
  $tier = self::tier_proof( $candidate );
  if ( '' === $cipher || ! $tier ) { return [ 'error' => 'reconnect_authenticated_proof_failed' ]; }
  $result = YOGB_BM_Reconnect_Guard::run( null, static function() use ( $state, $candidate, $cipher, $tier ) {
   global $wpdb;
   $auth = YOGB_BM_Registrar::auth_recovery_state();
   if ( ! hash_equals( $state['artifact'], self::artifact() ) || $auth['authority_token'] !== $state['authority_token'] || self::state() !== $state || self::credential_base() !== $state['base'] ) { return [ 'error' => 'reconnect_authority_changed' ]; }
   // The options table must support rollback, and reconnect never starts a nested transaction.
   $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->options ) );
   if ( 'InnoDB' !== $engine || ! YOGB_BM_Outbox::reconnect_schema_ready() ) { return [ 'error' => 'reconnect_schema_not_ready' ]; }
   if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) || false === $wpdb->query( 'START TRANSACTION' ) ) { return [ 'error' => 'reconnect_storage_unavailable' ]; }
   try {
    $names = [ YOGB_BM_Registrar::OPT_API_KEY, YOGB_BM_Registrar::OPT_API_SECRET, YOGB_BM_Registrar::OPT_REPORTER_ID, YOGB_BM_Registrar::OPT_AUTH_RECOVERY, self::OPTION, 'home', 'siteurl' ];
    foreach ( $names as $name ) { $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", $name ) ); }
    if ( ! hash_equals( $state['artifact'], self::artifact() ) || self::credential_base() !== $state['base'] || self::state() !== $state ) { throw new RuntimeException(); }
    $archive = YOGB_BM_Recovery_Store::snapshot();
    foreach ( [ YOGB_BM_Registrar::OPT_API_KEY => $candidate['api_key'], YOGB_BM_Registrar::OPT_API_SECRET => $cipher, YOGB_BM_Registrar::OPT_REPORTER_ID => (string) $candidate['reporter_id'] ] as $name => $value ) {
     $name = 'yogb_successor_v2_' . $name;
     $ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options}(option_name,option_value,autoload) VALUES(%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'", $name, $value ) );
     if ( false === $ok || self::raw( $name ) !== (string) $value ) { throw new RuntimeException(); }
    }
    $marker = [ 'version' => 2, 'contract' => YOGB_BM_Recovery_Store::CONTRACT, 'reporter' => (int) $candidate['reporter_id'], 'server_epoch' => $candidate['recovery_epoch'], 'attempt' => $state['attempt'], 'verified_at' => time(), 'archive' => $archive ?? ( maybe_unserialize( YOGB_BM_Recovery_Store::raw( YOGB_BM_Recovery_Store::MARKER ) )['archive'] ?? [] ) ];
    $raw_marker = maybe_serialize( $marker );
    if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options}(option_name,option_value,autoload) VALUES(%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", YOGB_BM_Recovery_Store::MARKER, $raw_marker ) ) || $raw_marker !== YOGB_BM_Recovery_Store::raw( YOGB_BM_Recovery_Store::MARKER ) ) { throw new RuntimeException(); }
    self::cache_clear();
    $auth = YOGB_BM_Registrar::auth_recovery_state();
    if ( ! hash_equals( (string) $candidate['api_secret'], YOGB_BM_Secret_Store::decrypt_from_storage( (string) self::raw( YOGB_BM_Recovery_Store::option_name( YOGB_BM_Registrar::OPT_API_SECRET ) ) ) ) ) { throw new RuntimeException(); }
    $auth['state'] = 'recovering'; $auth['source'] = 'reconnect_authenticated'; $auth['expected_reporter_id'] = (int) $candidate['reporter_id'];
    $auth['credential_fingerprint'] = YOGB_BM_Registrar::credential_fingerprint( $candidate['api_key'], $candidate['api_secret'] );
    if ( ! YOGB_BM_Registrar::reconnect_write_state( $auth ) ) { throw new RuntimeException(); }
    $snapshot = YOGB_BM_Registrar::committed_credential_snapshot( true );
    $applied = YOGB_BM_Tier_Webhook::apply_tier_payload( $tier, [ 'source' => 'pull', 'bind_verified_auth_control' => true, 'credential_snapshot' => $snapshot, 'credential_fingerprint' => $snapshot['credential_fingerprint'] ?? '' ] );
    if ( empty( $applied['ok'] ) || 'stale_version_ignored' === ( $applied['status'] ?? '' ) || 'healthy' !== YOGB_BM_Registrar::auth_recovery_state()['state'] ) { throw new RuntimeException(); }
    $connected = [ 'status' => 'connected', 'reporter' => (int) $candidate['reporter_id'], 'verified_at' => time(), 'inventory_cursor' => 0, 'inventory_limit' => YOGB_BM_Outbox::inventory_limit(), 'eligibility_expires_at' => (int) $candidate['eligibility_expires_at'] ];
    update_option( self::OPTION, $connected, false ); self::cache_clear();
    if ( self::state() !== $connected || false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException(); }
    delete_transient( YOGB_BM_Registrar::TRANSIENT_PREFIX . $state['challenge'] );
    self::inventory();
    YOGB_BM_Outbox::resume_after_auth();
    return [ 'status' => 'connected' ];
   } catch ( Throwable $error ) {
    try { $wpdb->query( 'ROLLBACK' ); } catch ( Throwable $ignored ) {}
    self::cache_clear();
    return [ 'error' => 'reconnect_commit_failed' ];
   }
  } );
  unset( $candidate );
  return is_array( $result ) ? $result : [ 'error' => 'reconnect_storage_unavailable' ];
 }
 public static function inventory(): void {
  YOGB_BM_Reconnect_Guard::run( null, static function() {
   $state = self::state();
   if ( 'connected' !== ( $state['status'] ?? '' ) ) { return; }
   $cursor = (int) ( $state['inventory_cursor'] ?? 0 ); $limit = (int) ( $state['inventory_limit'] ?? 0 );
   if ( $cursor >= $limit ) { return; }
   $next = YOGB_BM_Outbox::inventory_batch( $cursor, $limit );
   $state['inventory_cursor'] = $next;
   update_option( self::OPTION, $state, false ); self::cache_clear();
   if ( $next < $limit && ! wp_next_scheduled( 'yogb_bm_reconnect_inventory' ) ) {
    wp_schedule_single_event( time() + 10, 'yogb_bm_reconnect_inventory' );
   }
  } );
 }

 public static function handle(): void {
  if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Not authorized.', 'wc-blacklist-manager' ), '', [ 'response' => 403 ] ); }
  check_admin_referer( 'yogb_reconnect', 'yogb_reconnect_nonce' );
  if ( '1' !== (string) ( $_POST['yogb_reconnect_confirm'] ?? '' ) ) { wp_die( esc_html__( 'Please confirm reconnect.', 'wc-blacklist-manager' ), '', [ 'response' => 422 ] ); }
  $state = self::state();
  if ( YOGB_BM_Registrar::authenticated_requests_allowed() && ! in_array( $state['status'] ?? '', [ 'pending', 'owner_required' ], true ) ) { wp_die( esc_html__( 'This connection is already active.', 'wc-blacklist-manager' ), '', [ 'response' => 409 ] ); }
  $pending = in_array( $state['status'] ?? '', [ 'pending', 'owner_required' ], true ) && (int) ( $state['expires_at'] ?? 0 ) > time();
  $result = $pending ? self::verify() : self::begin( sanitize_text_field( wp_unslash( $_POST['yogb_reconnect_activation_key'] ?? '' ) ) );
  set_transient( 'yogb_reconnect_feedback_' . get_current_user_id(), isset( $result['error'] ) ? $result['error'] : ( $result['status'] ?? 'reconnect_failed' ), 60 );
  wp_safe_redirect( admin_url( 'admin.php?page=wc-blacklist-manager-settings&tab=global_blacklist' ) ); exit;
 }
 public static function render(): void {
  if ( ! current_user_can( 'manage_options' ) ) { return; }
  $state = self::state();
  if ( ! $state && YOGB_BM_Registrar::authenticated_requests_allowed() ) { return; }
  $labels = [
   'pending' => __( 'Reconnect pending', 'wc-blacklist-manager' ),
   'owner_required' => __( 'Owner verification required', 'wc-blacklist-manager' ),
   'connected' => __( 'Connected', 'wc-blacklist-manager' ),
  ];
  if ( in_array( $state['status'] ?? '', [ 'pending', 'owner_required' ], true ) && (int) ( $state['expires_at'] ?? 0 ) <= time() ) { $state['status'] = 'expired'; }
  $status = $labels[ $state['status'] ?? '' ] ?? __( 'Needs support / Upgrade verification required', 'wc-blacklist-manager' );
  if ( 'connected' === ( $state['status'] ?? '' ) ) {
   if ( ! YOGB_BM_Registrar::authenticated_requests_allowed() ) { $status = __( 'Needs support', 'wc-blacklist-manager' ); }
   elseif ( (int) ( $state['inventory_cursor'] ?? 0 ) < (int) ( $state['inventory_limit'] ?? 0 ) || YOGB_BM_Outbox::historical_queue_count() > 0 ) { $status = __( 'Connected — historical queue needs attention', 'wc-blacklist-manager' ); }
  }
  $feedback = get_transient( 'yogb_reconnect_feedback_' . get_current_user_id() );
  $open = in_array( $state['status'] ?? '', [ 'pending', 'owner_required' ], true ) || ( is_string( $feedback ) && '' !== $feedback );
  echo '<details class="yogb-gbl-activation yogb-gbl-reconnect"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Reconnect an existing site', 'wc-blacklist-manager' ) . '</summary><div class="yogb-gbl-activation__body">';
  echo '<div class="yogb-gbl-inline-warning" role="status"><strong>' . esc_html( $status ) . '</strong></div>';
  if ( ! empty( $state['reporter'] ) ) { echo '<p>' . esc_html__( 'Expected existing reporter ID:', 'wc-blacklist-manager' ) . ' <code>' . (int) $state['reporter'] . '</code></p>'; }
  if ( YOGB_BM_Registrar::is_auth_paused() || ! YOGB_BM_Report::is_ready() || in_array( $state['status'] ?? '', [ 'pending', 'owner_required' ], true ) ) {
   echo '<p>' . esc_html__( 'Recovery requires verified upgrade eligibility and historical reporter ownership. Old encrypted queue data is retained and may need support.', 'wc-blacklist-manager' ) . '</p>';
   if ( isset( $state['challenge'] ) ) { echo '<p>' . esc_html__( 'Support challenge reference:', 'wc-blacklist-manager' ) . ' ' . esc_html( $state['challenge'] ) . '</p>'; }
   wp_nonce_field( 'yogb_reconnect', 'yogb_reconnect_nonce' );
   echo '<label class="yogb-gbl-reconnect__key" for="yogb_reconnect_activation_key">' . esc_html__( 'Existing subscription activation key (optional)', 'wc-blacklist-manager' ) . '</label>';
   echo '<input type="password" id="yogb_reconnect_activation_key" name="yogb_reconnect_activation_key" autocomplete="off" placeholder="' . esc_attr__( 'Existing subscription activation key (optional)', 'wc-blacklist-manager' ) . '">';
   echo '<label class="yogb-gbl-reconnect__confirm"><input type="checkbox" name="yogb_reconnect_confirm" value="1"> <span>' . esc_html__( 'Confirm recovery of the existing reporter without deleting retained credentials or queue data.', 'wc-blacklist-manager' ) . '</span></label>';
   echo '<div class="yogb-gbl-actions"><button type="submit" class="button button-primary" name="action" value="yogb_reconnect" formmethod="post" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . esc_html__( 'Reconnect Global Blacklist', 'wc-blacklist-manager' ) . '</button></div>';
  }
  if ( YOGB_BM_Recovery_Store::active() ) {
   echo '<p>' . esc_html__( 'Older in-flight workers may still change orders after recovery. Review affected order status, notes and payment history; retained historical queue bytes are kept separately and unreadable records require support.', 'wc-blacklist-manager' ) . '</p>';
  }
  if ( is_string( $feedback ) && '' !== $feedback ) {
   $messages = [
    'upgrade_verification_required' => __( 'Needs support: the host upgrade must be independently verified before recovery is available.', 'wc-blacklist-manager' ),
    'owner_verification_required' => __( 'Owner verification required: use an active subscription key for this reporter or ask support to verify historical ownership.', 'wc-blacklist-manager' ),
    'pending' => __( 'Reconnect pending. Continue to verify the existing connection.', 'wc-blacklist-manager' ),
    'owner_required' => __( 'Owner verification required. Give support the challenge reference shown above.', 'wc-blacklist-manager' ),
    'connected' => __( 'The existing connection has been authenticated.', 'wc-blacklist-manager' ),
    'reconnect_attempt_expired' => __( 'The recovery attempt expired. Start a new attempt.', 'wc-blacklist-manager' ),
    'reconnect_already_pending' => __( 'A recovery attempt is already pending.', 'wc-blacklist-manager' ),
   ];
   echo '<p role="status">' . esc_html( $messages[ $feedback ] ?? __( 'Needs support: recovery could not be completed. Retained connection and queue data have not been discarded.', 'wc-blacklist-manager' ) ) . '</p>';
  }
  echo '</div></details>';
 }
}
YOGB_BM_Reconnect::init();
