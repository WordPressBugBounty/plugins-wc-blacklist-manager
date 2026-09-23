<?php
/** Internal Core/Premium notification seam v1. */
defined( 'ABSPATH' ) || exit;

foreach ( array( 'registry', 'policy', 'recipients', 'context', 'state', 'renderer', 'transport', 'delivery', 'free-events', 'blocked-state', 'blocked-events', 'blocked-worker', 'provider-store', 'provider', 'provider-samples', 'provider-orders', 'operational', 'usage', 'global-events', 'digest', 'audit-digest' ) as $wc_blacklist_notification_service ) {
	require_once __DIR__ . '/notifications/' . $wc_blacklist_notification_service . '.php';
}
unset( $wc_blacklist_notification_service );

final class WC_Blacklist_Notifications {
	const VERSION = 1;
	const PROVIDER_VERSION = 1;
	const OPERATIONAL_VERSION = 1;
	const USAGE_VERSION = 1;
	const GLOBAL_NOTIFICATIONS_VERSION = 1;
	const DIGEST_VERSION = 1;
	const AUDIT_DIGEST_VERSION = 1;
	private $registry;
	private $global_registering = false;
	private static $global_ready = false;
	public static function global_ready() { return self::$global_ready; }
	private $state;
	private $renderer;
	private $transport;
	private $pending = array();
	private $attempted = array();
	private $results = array();
	private $busy = false;
	private $buffering = false;
	private $test_attempted = false;

	public function __construct( $transport = null ) {
		$this->registry = new WC_Blacklist_Notification_Registry();
		$this->state = new WC_Blacklist_Notification_State();
		$this->renderer = new WC_Blacklist_Notification_Renderer();
		$this->transport = $transport ?: new WC_Blacklist_Notification_Transport();
		$this->global_registering = true;
		try {
			if ( $this->register_operational( WC_Blacklist_Notification_Global_Events::connection() ) && $this->register_usage( WC_Blacklist_Notification_Global_Events::usage() ) ) {
				self::$global_ready = true;
			} else {
				// Invalid translated copy disables this family without breaking Core.
				$this->registry = new WC_Blacklist_Notification_Registry();
			}
		} finally { $this->global_registering = false; }
	}

	public function register( $descriptor ) {
		if ( is_array( $descriptor ) && ( WC_Blacklist_Notification_Operational::owns( $descriptor['id'] ?? '' ) || WC_Blacklist_Notification_Usage::owns( $descriptor['id'] ?? '' ) ) && ! $this->global_registering ) { return false; }
		return $this->registry->register( $descriptor );
	}
	public function register_provider( $samples, $order, $rules ) {
		$candidate = clone $this; $candidate->registry = clone $this->registry;
		try { if ( ! WC_Blacklist_Notification_Provider::register( $candidate, $samples, $order, $rules ) ) { return false; } }
		catch ( Throwable $error ) { return false; }
		$this->registry = $candidate->registry; return true;
	}
	public function register_operational( $pair ) {
		if ( ! $this->global_registering ) { return false; }
		$candidate = clone $this; $candidate->registry = clone $this->registry;
		try { if ( ! WC_Blacklist_Notification_Operational::register( $candidate, $pair ) ) { return false; } }
		catch ( Throwable $error ) { return false; }
		$this->registry = $candidate->registry;
		return true;
	}

	/** Only the fixed operational worker can present an authoritative pending ticket. */
	public function deliver_operational( $ticket ) {
		if ( $this->busy || ! WC_Blacklist_Notification_Operational::worker( $ticket ) ) { return $this->result( '', 'state_unavailable' ); }
		$this->freeze();
		$id = 'attention' === $ticket['direction'] ? WC_Blacklist_Notification_Operational::ATTENTION : WC_Blacklist_Notification_Operational::RESTORED;
		$d = $this->registry->get( $id );
		if ( ! $d ) { return $this->result( '', 'invalid_event' ); }
		$this->busy = true;
		try {
			$policy = WC_Blacklist_Notification_Policy::check( $d );
			if ( $policy ) { return $this->result( $id, $policy ); }
			$context = WC_Blacklist_Notification_Context::project( $d, array( 'timestamp' => $ticket['at'], 'reasons' => array( $ticket['reason'] ) ) );
			$delivery = WC_Blacklist_Notification_Recipients::resolve();
			$message = false;
			if ( false !== $delivery && false !== $context ) {
				try { $message = $this->renderer->render( $d, $context ); } catch ( Throwable $e ) { $message = false; }
			}
			if ( false === $delivery || false === $message ) {
				$r = WC_Blacklist_Notification_Operational::claim( $ticket, false );
				return $this->result( $id, 'retry' === $r['status'] ? ( false === $delivery ? 'invalid_config' : 'render_failed' ) : $r['status'] );
			}
			$policy = WC_Blacklist_Notification_Policy::check( $d );
			if ( $policy ) { return $this->result( $id, $policy ); }
			$r = WC_Blacklist_Notification_Operational::claim( $ticket );
			if ( 'claimed' !== $r['status'] ) { return $this->result( $id, $r['status'] ); }
			$policy = WC_Blacklist_Notification_Policy::check( $d );
			if ( $policy ) { return $this->result( $id, $policy ); }
			if ( ! WC_Blacklist_Notification_Operational::confirm( $ticket, $r['state'] ) ) { return $this->result( $id, 'state_unavailable' ); }
			try { $sent = true === $this->transport->send( $delivery, $message ); } catch ( Throwable $error ) { $sent = false; }
			if ( $sent ) { WC_Blacklist_Notification_Operational::complete( $ticket, $r['state'] ); }
			return $this->result( $id, $sent ? 'accepted' : 'transport_failed' );
		} catch ( Throwable $error ) { return $this->result( $id, 'state_unavailable' ); }
		finally { $this->busy = false; }
	}

	public function register_usage( $items ) {
		if ( ! $this->global_registering ) { return false; }
		$candidate = clone $this; $candidate->registry = clone $this->registry;
		try { if ( ! WC_Blacklist_Notification_Usage::register( $candidate, $items ) ) { return false; } }
		catch ( Throwable $error ) { return false; }
		$this->registry = $candidate->registry;
		return true;
	}

	/** Only the fixed usage worker can present an authoritative pending ticket. */
	public function deliver_usage( $ticket ) {
		if ( $this->busy || ! WC_Blacklist_Notification_Usage::worker( $ticket ) ) { return $this->result( '', 'state_unavailable' ); }
		$this->freeze();
		$id = $ticket['state']['event'];
		$d = $this->registry->get( $id );
		if ( ! $d ) { return $this->result( '', 'invalid_event' ); }
		$this->busy = true;
		try {
			$policy = WC_Blacklist_Notification_Policy::check( $d );
			if ( $policy ) { return $this->result( $id, $policy ); }
			$context = WC_Blacklist_Notification_Context::project( $d, array( 'timestamp' => $ticket['source']['usage']['observed_at'], 'reasons' => array( WC_Blacklist_Notification_Usage::reasons()[array_search( $id, WC_Blacklist_Notification_Usage::ids(), true )] ), 'usage_used' => $ticket['source']['usage']['used'], 'usage_limit' => $ticket['source']['usage']['limit'], 'usage_cycle_end' => $ticket['source']['usage']['cycle_end'] ) );
			$delivery = WC_Blacklist_Notification_Recipients::resolve();
			$message = false;
			if ( false !== $delivery && false !== $context ) {
				try { $message = $this->renderer->render( $d, $context ); } catch ( Throwable $e ) { $message = false; }
			}
			if ( false === $delivery || false === $message ) {
				$r = WC_Blacklist_Notification_Usage::claim( $ticket, false );
				return $this->result( $id, 'retry' === $r['status'] ? ( false === $delivery ? 'invalid_config' : 'render_failed' ) : $r['status'] );
			}
			$policy = WC_Blacklist_Notification_Policy::check( $d );
			if ( $policy ) { return $this->result( $id, $policy ); }
			$r = WC_Blacklist_Notification_Usage::claim( $ticket );
			if ( 'claimed' !== $r['status'] ) { return $this->result( $id, $r['status'] ); }
			$policy = WC_Blacklist_Notification_Policy::check( $d );
			if ( $policy ) { return $this->result( $id, $policy ); }
			if ( ! WC_Blacklist_Notification_Usage::confirm( $ticket, array( 'state' => $r['state'], 'source' => $ticket['source'] ) ) ) { return $this->result( $id, 'state_unavailable' ); }
			try { $sent = true === $this->transport->send( $delivery, $message ); } catch ( Throwable $error ) { $sent = false; }
			if ( $sent ) { WC_Blacklist_Notification_Usage::complete( $ticket, array( 'state' => $r['state'], 'source' => $ticket['source'] ) ); }
			return $this->result( $id, $sent ? 'accepted' : 'transport_failed' );
		} catch ( Throwable $error ) { return $this->result( $id, 'state_unavailable' ); }
		finally { $this->busy = false; }
	}

	public function register_digest( $descriptor, $provider ) {
		$candidate = clone $this; $candidate->registry = clone $this->registry;
		try { if ( ! WC_Blacklist_Notification_Digest::register( $candidate, $descriptor, $provider ) ) { return false; } }
		catch ( Throwable $error ) { return false; }
		$this->registry = $candidate->registry; return true;
	}

	public function register_audit_digest( $descriptor, $provider ) {
		$candidate = clone $this; $candidate->registry = clone $this->registry;
		try { if ( ! WC_Blacklist_Notification_Audit_Digest::register( $candidate, $descriptor, $provider ) ) { return false; } }
		catch ( Throwable $error ) { return false; }
		$this->registry = $candidate->registry; return true;
	}

	/** The exact background ticket is the only digest delivery entry. */
	public function deliver_digest( $ticket ) { return $this->deliver_calendar_digest( $ticket, 'WC_Blacklist_Notification_Digest' ); }
	public function deliver_audit_digest( $ticket ) { return $this->deliver_calendar_digest( $ticket, 'WC_Blacklist_Notification_Audit_Digest' ); }

	private function deliver_calendar_digest( $ticket, $slot ) {
		if ( $this->busy || ! $slot::worker( $ticket ) ) { return $this->result( '', 'state_unavailable' ); }
		$this->freeze(); $id = $slot::ID; $d = $this->registry->get( $id );
		if ( ! $d ) { return $this->result( $id, 'invalid_event' ); }
		$this->busy = true;
		try {
			$policy = WC_Blacklist_Notification_Policy::check( $d );
			if ( $policy ) { return $this->result( $id, $policy ); }
			$s = $ticket['state'];
			$input = array( 'week_start' => $s['start'], 'week_end' => $s['end'], 'week_timezone' => $s['timezone'] );
			foreach ( $slot::COUNTS as $key => $field ) { $input[$field] = $s[$key]; }
			$input['reasons'] = $slot::REASONS;
			$context = WC_Blacklist_Notification_Context::project( $d, $input );
			$delivery = WC_Blacklist_Notification_Recipients::resolve(); $message = false;
			if ( false !== $context && false !== $delivery ) {
				try { $message = $this->renderer->render( $d, $context ); } catch ( Throwable $error ) { $message = false; }
			}
			if ( false === $delivery || false === $message ) {
				$slot::claim( $ticket, false );
				return $this->result( $id, false === $delivery ? 'invalid_config' : 'render_failed' );
			}
			$r = $slot::claim( $ticket );
			if ( 'claimed' !== $r['status'] ) { return $this->result( $id, $r['status'] ); }
			if ( ! $slot::confirm( $ticket, $r['state'] ) ) { return $this->result( $id, 'state_unavailable' ); }
			try { $sent = true === $this->transport->send( $delivery, $message ); } catch ( Throwable $error ) { $sent = false; }
			return $this->result( $id, $sent ? 'accepted' : 'transport_failed' );
		} catch ( Throwable $error ) { return $this->result( $id, 'state_unavailable' ); }
		finally { $this->busy = false; }
	}

	public function freeze() { $this->registry->freeze(); }
	public function results() { return $this->results; }

	public function buffer( $id, $event_key, $context ) {
		if ( $this->buffering || $this->busy ) { return $this->result( '', 'duplicate' ); }
		$this->buffering = true;
		try {
			return $this->buffer_event( $id, $event_key, $context );
		} catch ( Throwable $error ) {
			return $this->result( '', 'invalid_event' );
		} finally {
			$this->buffering = false;
		}
	}

	private function buffer_event( $id, $event_key, $context ) {
		$this->freeze();
		if ( WC_Blacklist_Notification_Operational::owns( $id ) || WC_Blacklist_Notification_Usage::owns( $id ) || WC_Blacklist_Notification_Digest::owns( $id ) || WC_Blacklist_Notification_Audit_Digest::owns( $id ) ) { return $this->result( $id, 'state_unavailable' ); }
		$d = $this->registry->get( $id );
		if ( ! $d || ! is_string( $event_key ) || '' === $event_key || strlen( $event_key ) > 128 ) { return $this->result( '', 'invalid_event' ); }
		$policy = WC_Blacklist_Notification_Policy::check( $d );
		if ( $policy ) { return $this->result( $id, $policy ); }
		$projection = WC_Blacklist_Notification_Context::project( $d, $context );
		if ( false === $projection ) { return $this->result( $id, 'invalid_event' ); }
		$key = hash_hmac( 'sha256', $id . "\0" . $event_key, wp_salt( 'nonce' ) );
		if ( isset( $this->attempted[ $key ] ) ) { return $this->result( $id, 'duplicate' ); }
		if ( ! isset( $this->pending[ $key ] ) && count( $this->pending ) + count( $this->attempted ) >= 32 ) { return $this->result( $id, 'overflow' ); }
		if ( isset( $this->pending[ $key ] ) ) {
			$projection = WC_Blacklist_Notification_Context::merge( $this->pending[ $key ]['context'], $projection );
			if ( false === $projection ) { return $this->result( $id, 'invalid_event' ); }
		}
		$this->pending[ $key ] = array( 'id' => $id, 'context' => $projection );
		return $this->result( $id, 'buffered' );
	}

	public function dispatch( $id, $event_key, $context ) {
		if ( $this->busy ) { return $this->result( '', 'duplicate' ); }
		$r = $this->buffer( $id, $event_key, $context );
		if ( 'buffered' !== $r['status'] ) { return $r; }
		$key = hash_hmac( 'sha256', $id . "\0" . $event_key, wp_salt( 'nonce' ) );
		return $this->deliver( $key );
	}

	public function flush() {
		if ( $this->busy ) { return array(); }
		$out = array();
		foreach ( array_keys( $this->pending ) as $key ) { $out[] = $this->deliver( $key ); }
		return $out;
	}

	private function deliver( $key ) {
		$event = $this->pending[ $key ];
		unset( $this->pending[ $key ] );
		$this->attempted[ $key ] = true;
		$id = $event['id'];
		$d = $this->registry->get( $id );
		// The Free event keeps its worker policy; provider events verify their own jobs.
		$suspect = WC_Blacklist_Notification_Free_Events::EVENT === $id;
		$order_id = $event['context']['order_id'] ?? 0;
		$blocked = WC_Blacklist_Notification_Blocked_Events::EVENT === $id;
		$sample_id = $event['context']['sample_id'] ?? '';
		$provider = WC_Blacklist_Notification_Provider::owns( $id );
		$resource = $provider ? $event['context'] : ( $blocked ? $sample_id : $order_id );
		if ( $provider && ! WC_Blacklist_Notification_Provider::worker( $id, $event['context'] ) ) { return $this->result( $id, 'state_unavailable' ); }
		if ( ! $provider && ! WC_Blacklist_Notification_Policy::background() && ! ( $suspect && WC_Blacklist_Notification_Free_Events::worker( $order_id ) ) ) { return $this->result( $id, 'state_unavailable' ); }
		$this->busy = true;
		try {
			$policy = WC_Blacklist_Notification_Policy::check( $d );
			if ( $policy ) { return $this->result( $id, $policy ); }
			if ( $provider ) {
				$prepared = WC_Blacklist_Notification_Provider::prepare( $d, $event['context'], $this->renderer );
				if ( 'ready' !== $prepared['status'] ) { return $this->preflight_failure( $id, $resource, $prepared['status'] ); }
				$delivery = $prepared['delivery']; $message = $prepared['message'];
			} else {
				$delivery = WC_Blacklist_Notification_Recipients::resolve();
				if ( false === $delivery ) { return $this->preflight_failure( $id, $resource, 'invalid_config' ); }
				try { $message = $this->renderer->render( $d, $event['context'] ); } catch ( Throwable $error ) { $message = false; }
				if ( false === $message ) { return $this->preflight_failure( $id, $resource, 'render_failed' ); }
			}
			if ( null !== $d['cooldown'] ) {
				$claim = $this->state->claim( $d );
				if ( 'claimed' !== $claim ) { return $this->result( $id, $claim ); }
			}
			$policy = WC_Blacklist_Notification_Policy::check( $d );
			if ( $policy ) { return $this->result( $id, $policy ); }
			if ( $suspect ) {
				$claim = WC_Blacklist_Notification_Order_Claim::claim( $order_id );
				if ( 'claimed' !== $claim ) { return $this->result( $id, $claim ); }
				$policy = WC_Blacklist_Notification_Policy::check( $d );
				if ( $policy ) { return $this->result( $id, $policy ); }
			}
			if ( $blocked ) {
				$claim = WC_Blacklist_Notification_Blocked_State::claim( $sample_id );
				if ( 'claimed' !== $claim['status'] ) { return $this->result( $id, $claim['status'] ); }
				$policy = WC_Blacklist_Notification_Policy::check( $d );
				if ( $policy ) { return $this->result( $id, $policy ); }
			}
			if ( $provider ) {
				$claim = WC_Blacklist_Notification_Provider::claim( $id, $event['context'] );
				if ( 'claimed' !== $claim ) { return $this->result( $id, $claim ); }
				$policy = WC_Blacklist_Notification_Policy::check( $d );
				if ( $policy ) { return $this->result( $id, $policy ); }
			}
			try { $sent = true === $this->transport->send( $delivery, $message ); } catch ( Throwable $error ) { $sent = false; }
			return $this->result( $id, $sent ? 'accepted' : 'transport_failed' );
		} catch ( Throwable $error ) {
			return $this->result( $id, 'state_unavailable' );
		} finally {
			$this->busy = false;
		}
	}

	private function preflight_failure( $id, $order_id, $status ) {
		if ( WC_Blacklist_Notification_Provider::owns( $id ) && in_array( $status, array( 'invalid_config', 'render_failed' ), true ) ) {
			$claim = WC_Blacklist_Notification_Provider::claim( $id, $order_id, false );
			if ( 'retry' !== $claim ) { $status = $claim; }
		}
		if ( WC_Blacklist_Notification_Free_Events::EVENT === $id ) {
			$claim = WC_Blacklist_Notification_Order_Claim::claim( $order_id, false );
			if ( 'retry' !== $claim ) { $status = $claim; }
		}
		if ( WC_Blacklist_Notification_Blocked_Events::EVENT === $id ) {
			$claim = WC_Blacklist_Notification_Blocked_State::claim( $order_id, false );
			if ( 'retry' !== $claim['status'] ) { $status = $claim['status']; }
		}
		return $this->result( $id, $status );
	}

	/** Explicit administrator diagnostic; it cannot grant ordinary dispatch permission. */
	public function send_test() {
		$id = 'core.diagnostic.test';
		$nonce = $_POST['wc_blacklist_test_email_nonce'] ?? null;
		$allowed = function_exists( 'wc_blacklist_manager_user_can_manage_area' )
			? wc_blacklist_manager_user_can_manage_area( 'wc_blacklist_notifications_permission', true )
			: current_user_can( 'manage_options' );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $allowed || ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'wc_blacklist_test_email' ) ) { return $this->result( $id, 'disabled' ); }
		if ( $this->busy || $this->test_attempted ) { return $this->result( $id, 'duplicate' ); }
		$this->busy = true; $this->test_attempted = true;
		try {
			$delivery = WC_Blacklist_Notification_Recipients::resolve();
			if ( false === $delivery ) { return $this->result( $id, 'invalid_config' ); }
			$message = $this->renderer->render( array(
				'subject' => __( 'Blacklist Manager test email', 'wc-blacklist-manager' ),
				'heading' => __( 'Your notification delivery test', 'wc-blacklist-manager' ),
				'severity' => 'info', 'reasons' => array( 'test' => __( 'This is a test email sent from your notification settings.', 'wc-blacklist-manager' ) ),
			), array( 'timestamp' => time(), 'reasons' => array( 'test' ) ) );
			if ( false === $message ) { return $this->result( $id, 'render_failed' ); }
			try { $sent = true === $this->transport->send( $delivery, $message ); } catch ( Throwable $error ) { $sent = false; }
			return $this->result( $id, $sent ? 'accepted' : 'transport_failed' );
		} catch ( Throwable $error ) { return $this->result( $id, 'render_failed' ); }
		finally { $this->busy = false; }
	}

	private function result( $id, $status ) {
		$r = array( 'version' => 1, 'event' => $id, 'status' => $status, 'timestamp' => time() );
		if ( count( $this->results ) >= 32 ) { array_shift( $this->results ); }
		$this->results[] = $r;
		// Suppression floods cannot trigger a proportional diagnostic subscriber.
		if ( in_array( $status, array( 'accepted', 'transport_failed', 'render_failed' ), true ) ) {
			try { do_action( 'wc_blacklist_manager_notification_result_v1', $r ); } catch ( Throwable $error ) { /* Diagnostics cannot change delivery. */ }
		}
		return $r;
	}
}

function wc_blacklist_manager_notifications() {
	static $service;
	if ( ! $service ) { $service = new WC_Blacklist_Notifications(); }
	return $service;
}

add_action( 'init', static function() {
	$service = wc_blacklist_manager_notifications();
	try {
		do_action( 'wc_blacklist_manager_notifications_register', $service );
	} finally {
		$service->freeze();
	}
}, 11 );
