<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private, read-only summary of retained Global decision references.
 *
 * This is an administrator presentation helper, not a public extension API.
 */
final class YOGB_BM_Global_Value_Summary {
	const ANALYSIS_CAP = 2000;
	const INDEX_NAME   = 'outcome_timestamp';
	const VIEW_SCHEMA  = 'yogb_gbl_decision_v2';

	private static $cached_summary = null;

	/** Return the request-local administrator summary. */
	public static function get_summary() : array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::empty_summary( 'unauthorized', self::calendar_context() );
		}

		if ( is_array( self::$cached_summary ) ) {
			return self::$cached_summary;
		}

		$context = self::calendar_context();
		$source  = self::read_indexed_rows( $context );
		if ( empty( $source['available'] ) ) {
			self::$cached_summary = self::empty_summary( (string) ( $source['reason'] ?? 'unavailable' ), $context );
			return self::$cached_summary;
		}

		self::$cached_summary = self::summarize_rows( $source['rows'], $context, ! empty( $source['capped'] ) );
		return self::$cached_summary;
	}

	/** Render only bounded aggregate evidence inside the shared Global card. */
	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$summary = self::get_summary();
		echo '<div class="yogb-gbl-meta yogb-gbl-value-summary"><span><strong>' . esc_html__( 'Retained Global decisions', 'wc-blacklist-manager' ) . '</strong></span>';
		if ( empty( $summary['available'] ) ) {
			echo '<span>' . esc_html__( 'Unavailable until the bounded activity-log source is ready.', 'wc-blacklist-manager' ) . '</span></div>';
			return;
		}

		foreach ( array( 7, 30 ) as $window ) {
			$block     = self::format_count( (int) $summary['block'][ $window ], ! empty( $summary['capped'] ) );
			$challenge = self::format_count( (int) $summary['challenge'][ $window ], ! empty( $summary['capped'] ) );
			echo '<span><strong>' . esc_html( 7 === $window ? __( 'Last 7 site days', 'wc-blacklist-manager' ) : __( 'Last 30 site days', 'wc-blacklist-manager' ) ) . ':</strong> ';
			/* translators: 1: retained block decision count, 2: retained challenge decision count. */
			echo esc_html( sprintf( __( 'Block decisions: %1$s · Challenge decisions: %2$s', 'wc-blacklist-manager' ), $block, $challenge ) ) . '</span>';
		}
		echo '<span>' . esc_html__( 'Counts are retained decision references, not confirmed outcomes or enforced orders.', 'wc-blacklist-manager' ) . '</span>';
		if ( ! empty( $summary['capped'] ) ) {
			/* translators: %s: maximum number of newest Global activity rows analyzed. */
			echo '<span>' . esc_html( sprintf( __( 'Partial result: only the newest %s Global activity rows were analyzed.', 'wc-blacklist-manager' ), number_format_i18n( self::effective_analysis_cap() ) ) ) . '</span>';
		}
		echo '</div>';
	}

	/** Pure site-calendar boundaries: today plus the preceding 6/29 dates. */
	public static function calendar_context( $timestamp = null ) : array {
		$timestamp = null === $timestamp ? time() : (int) $timestamp;
		$today     = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->setTime( 0, 0, 0 );
		$end       = $today->modify( '+1 day' );

		return array(
			'cutoff_7'  => $today->modify( '-6 days' )->format( 'Y-m-d H:i:s' ),
			'cutoff_30' => $today->modify( '-29 days' )->format( 'Y-m-d H:i:s' ),
			'end'       => $end->format( 'Y-m-d H:i:s' ),
		);
	}

	private static function read_indexed_rows( array $context ) : array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return array( 'available' => false, 'reason' => 'source_unavailable', 'rows' => array(), 'capped' => false );
		}
		$table           = $wpdb->prefix . 'wc_blacklist_detection_log';
		$previous_errors = $wpdb->suppress_errors( true );
		$index_rows      = $wpdb->get_results(
			$wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", self::INDEX_NAME ),
			ARRAY_A
		);
		if (
			! is_array( $index_rows )
			|| 1 !== count( $index_rows )
			|| self::INDEX_NAME !== (string) ( $index_rows[0]['Key_name'] ?? '' )
			|| 1 !== (int) ( $index_rows[0]['Seq_in_index'] ?? 0 )
			|| 'timestamp' !== (string) ( $index_rows[0]['Column_name'] ?? '' )
		) {
			$wpdb->suppress_errors( $previous_errors );
			return array( 'available' => false, 'reason' => 'missing_index', 'rows' => array(), 'capped' => false );
		}
		if (
			! class_exists( 'WC_Blacklist_Manager_Schema_Readiness' )
			|| ! WC_Blacklist_Manager_Schema_Readiness::is_ready()
			|| ! WC_Blacklist_Manager_Schema_Readiness::index_matches( 'global_outcome' )
		) {
			$wpdb->suppress_errors( $previous_errors );
			return array( 'available' => false, 'reason' => 'schema_not_ready', 'rows' => array(), 'capped' => false );
		}

		$cap  = self::effective_analysis_cap();
		$like = $wpdb->esc_like( 'global_blacklist_decision:' ) . '%';
		$sql  = $wpdb->prepare(
			"SELECT `timestamp`,type,action,details,view FROM `{$table}` FORCE INDEX (outcome_timestamp) WHERE `timestamp` >= %s AND `timestamp` < %s AND type = %s AND details LIKE %s ORDER BY `timestamp` DESC LIMIT %d",
			$context['cutoff_30'],
			$context['end'],
			'bot',
			$like,
			$cap + 1
		);
		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		$error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $previous_errors );

		if ( ! is_array( $rows ) || '' !== $error ) {
			return array( 'available' => false, 'reason' => 'query_failed', 'rows' => array(), 'capped' => false );
		}

		return array(
			'available' => true,
			'reason'    => '',
			'rows'      => array_slice( $rows, 0, $cap ),
			'capped'    => count( $rows ) > $cap,
		);
	}

	private static function summarize_rows( array $rows, array $context, bool $capped ) : array {
		$references = array();
		$conflicts  = array();

		foreach ( $rows as $row ) {
			$classified = self::classify_row( is_array( $row ) ? $row : array() );
			$reference  = (string) ( $classified['reference'] ?? '' );
			if ( '' === $reference ) {
				continue;
			}
			if ( empty( $classified['valid'] ) ) {
				$conflicts[ $reference ] = true;
				continue;
			}

			$decision  = (string) $classified['decision'];
			$timestamp = (string) $classified['timestamp'];
			if ( isset( $references[ $reference ] ) && $decision !== $references[ $reference ]['decision'] ) {
				$conflicts[ $reference ] = true;
				continue;
			}
			if ( ! isset( $references[ $reference ] ) || $timestamp > $references[ $reference ]['timestamp'] ) {
				$references[ $reference ] = array( 'decision' => $decision, 'timestamp' => $timestamp );
			}
		}

		$result              = self::empty_summary( '', $context );
		$result['available'] = true;
		$result['capped']    = $capped;
		foreach ( $references as $reference => $record ) {
			if ( isset( $conflicts[ $reference ] ) ) {
				continue;
			}
			$decision = $record['decision'];
			if ( $record['timestamp'] >= $context['cutoff_30'] && $record['timestamp'] < $context['end'] ) {
				$result[ $decision ][30]++;
			}
			if ( $record['timestamp'] >= $context['cutoff_7'] && $record['timestamp'] < $context['end'] ) {
				$result[ $decision ][7]++;
			}
		}
		if ( $capped ) {
			/*
			 * Any unseen in-window row may conflict with a visible reference. With
			 * no safe way to prove which visible references are complete, zero is
			 * the only guaranteed lower bound that preserves conflict exclusion.
			 */
			$result['block']     = array( 7 => 0, 30 => 0 );
			$result['challenge'] = array( 7 => 0, 30 => 0 );
		}

		return $result;
	}

	private static function classify_row( array $row ) : array {
		$view      = isset( $row['view'] ) && is_string( $row['view'] ) ? json_decode( $row['view'], true ) : null;
		$reference = is_array( $view ) && isset( $view['decision_ref'] ) && is_string( $view['decision_ref'] )
			? $view['decision_ref']
			: '';
		if ( ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/', $reference ) ) {
			return array( 'valid' => false, 'reference' => '' );
		}

		$decision  = isset( $view['decision'] ) && is_string( $view['decision'] ) ? $view['decision'] : '';
		$details   = isset( $row['details'] ) && is_string( $row['details'] ) ? $row['details'] : '';
		$action    = isset( $row['action'] ) && is_string( $row['action'] ) ? $row['action'] : '';
		$type      = isset( $row['type'] ) && is_string( $row['type'] ) ? $row['type'] : '';
		$schema    = isset( $view['schema'] ) && is_string( $view['schema'] ) ? $view['schema'] : '';
		$timestamp = isset( $row['timestamp'] ) && is_string( $row['timestamp'] ) ? $row['timestamp'] : '';
		$valid     = 'bot' === $type
			&& in_array( $decision, array( 'block', 'challenge' ), true )
			&& $decision === $action
			&& self::VIEW_SCHEMA === $schema
			&& self::is_valid_mysql_datetime( $timestamp )
			&& 0 === strpos( $details, 'global_blacklist_decision:' . $decision )
			&& preg_match( '/^global_blacklist_decision:' . preg_quote( $decision, '/' ) . '(?:\s|$)/', $details );

		return array(
			'valid'     => (bool) $valid,
			'reference' => $reference,
			'decision'  => $decision,
			'timestamp' => $timestamp,
		);
	}

	private static function is_valid_mysql_datetime( string $timestamp ) : bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp ) ) {
			return false;
		}

		$datetime = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $timestamp, wp_timezone() );
		$errors   = DateTimeImmutable::getLastErrors();
		return $datetime instanceof DateTimeImmutable
			&& ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) )
			&& $timestamp === $datetime->format( 'Y-m-d H:i:s' );
	}

	private static function empty_summary( string $reason, array $context ) : array {
		return array(
			'available' => false,
			'reason'    => sanitize_key( $reason ),
			'block'     => array( 7 => 0, 30 => 0 ),
			'challenge' => array( 7 => 0, 30 => 0 ),
			'capped'    => false,
			'cutoff_7'  => (string) $context['cutoff_7'],
			'cutoff_30' => (string) $context['cutoff_30'],
			'end'       => (string) $context['end'],
		);
	}

	private static function format_count( int $count, bool $capped ) : string {
		$count = max( 0, $count );
		if ( $capped ) {
			/* translators: %s: lower-bound retained decision count. */
			return sprintf( __( 'at least %s', 'wc-blacklist-manager' ), number_format_i18n( $count ) );
		}
		return number_format_i18n( $count );
	}

	private static function effective_analysis_cap() : int {
		if (
			defined( 'WP_CLI' ) && WP_CLI
			&& defined( 'WC_BLACKLIST_MANAGER_GLOBAL_VALUE_TEST_CAP' )
			&& WC_BLACKLIST_MANAGER_GLOBAL_VALUE_TEST_CAP > 0
		) {
			return min( self::ANALYSIS_CAP, (int) WC_BLACKLIST_MANAGER_GLOBAL_VALUE_TEST_CAP );
		}
		return self::ANALYSIS_CAP;
	}
}
