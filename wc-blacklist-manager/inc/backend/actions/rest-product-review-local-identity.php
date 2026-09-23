<?php
/**
 * Local email and domain policy for WooCommerce REST product reviews.
 *
 * @package WC_Blacklist_Manager
 */

defined( 'ABSPATH' ) || exit;

final class WC_Blacklist_Manager_REST_Product_Review_Local_Identity {

	/** Internal Core/Premium disposable-policy adapter contract. */
	public const DISPOSABLE_POLICY_ADAPTER_CONTRACT_VERSION = 1;

	/** Deliberately late so ordinary identity modifiers run first. */
	private const HOOK_PRIORITY = 999;

	/** @var array<string,mixed>|null */
	private static $disposable_policy_adapter;

	/** @var SplObjectStorage<WP_REST_Request,array<string,mixed>> */
	private $requests;

	public function __construct() {
		$this->requests = new SplObjectStorage();

		add_filter(
			'woocommerce_rest_preprocess_product_review',
			array( $this, 'preprocess_review' ),
			self::HOOK_PRIORITY,
			2
		);
		add_action(
			'woocommerce_rest_insert_product_review',
			array( $this, 'commit_suspect_after_mutation' ),
			self::HOOK_PRIORITY,
			3
		);
	}

	/**
	 * Register the single compatible Premium disposable-policy adapter.
	 *
	 * Re-registering the selected adapter ID is idempotent. A conflicting
	 * adapter cannot replace it or add another evaluation/commit path.
	 *
	 * @param int      $version  Adapter contract version.
	 * @param string   $id       Stable adapter identifier.
	 * @param callable $evaluate Side-effect-free policy evaluator.
	 * @param callable $commit   Selected-denial effect committer.
	 * @return bool
	 */
	public static function register_disposable_policy_adapter( $version, $id, $evaluate, $commit ) {
		$id = sanitize_key( (string) $id );
		if ( self::DISPOSABLE_POLICY_ADAPTER_CONTRACT_VERSION !== (int) $version
			|| '' === $id
			|| ! is_callable( $evaluate )
			|| ! is_callable( $commit ) ) {
			return false;
		}

		if ( is_array( self::$disposable_policy_adapter ) ) {
			return $id === self::$disposable_policy_adapter['id'];
		}

		self::$disposable_policy_adapter = array(
			'id'       => $id,
			'evaluate' => $evaluate,
			'commit'   => $commit,
		);

		return true;
	}

	/**
	 * Apply the opt-in local identity policy at WooCommerce's prepared-review boundary.
	 *
	 * @param mixed $prepared_review Prepared review data or an upstream error.
	 * @param mixed $request         WooCommerce REST request.
	 * @return mixed
	 */
	public function preprocess_review( $prepared_review, $request ) {
		if ( is_wp_error( $prepared_review ) || ! is_array( $prepared_review ) || ! $request instanceof WP_REST_Request ) {
			return $prepared_review;
		}

		if ( class_exists( 'WC_Blacklist_Manager_REST_Protection_Migration' ) ? ! WC_Blacklist_Manager_REST_Protection_Migration::local_identity_enabled() : 1 !== (int) get_option( 'wc_blacklist_enable_woo_rest_review_local_identity', 0 ) ) {
			return $prepared_review;
		}

		if ( $this->requests->offsetExists( $request ) ) {
			$state = $this->requests[ $request ];

			return isset( $state['error'] ) && is_wp_error( $state['error'] ) ? $state['error'] : $prepared_review;
		}

		$identity = $this->get_request_identity( $prepared_review, $request );
		if ( false === $identity ) {
			$this->requests[ $request ] = array( 'decision' => 'clear' );

			return $prepared_review;
		}

		$state = array(
			'decision'   => 'evaluating',
			'email'      => $identity['email'],
			'normalized' => $identity['normalized'],
			'operation'  => $identity['operation'],
			'committed'  => false,
		);
		$this->requests[ $request ] = $state;

		$decision          = $this->evaluate_identity( $identity );
		$state['decision'] = $decision['decision'];

		if ( 'blocked_email' === $decision['decision'] || 'blocked_domain' === $decision['decision'] ) {
			$error              = $this->get_block_error();
			$state['error']     = $error;
			$state['committed'] = true;
			$this->requests[ $request ] = $state;
			$this->commit_blocked_decision( $decision, $identity );

			return $error;
		}

		$premium = $this->evaluate_disposable_policy( $identity, $decision['decision'] );
		if ( 'INVALID' === $premium['outcome'] ) {
			$error               = $this->get_block_error();
			$state['decision']    = 'premium_disposable';
			$state['error']       = $error;
			$state['committed']   = true;
			$this->requests[ $request ] = $state;
			$this->commit_disposable_policy( $identity );

			return $error;
		}

		$this->requests[ $request ] = $state;

		return $prepared_review;
	}

	/**
	 * Commit suspect evidence only after WooCommerce has stored the review.
	 *
	 * @param mixed $review   Stored review object.
	 * @param mixed $request  WooCommerce REST request.
	 * @param mixed $creating Whether WooCommerce created the review.
	 * @return void
	 */
	public function commit_suspect_after_mutation( $review, $request, $creating ) {
		unset( $creating );

		if ( ! $request instanceof WP_REST_Request || ! $this->requests->offsetExists( $request ) ) {
			return;
		}

		$state = $this->requests[ $request ];
		if ( 'suspect_email' !== ( $state['decision'] ?? '' ) || ! empty( $state['committed'] ) ) {
			return;
		}

		$state['committed']         = true;
		$this->requests[ $request ] = $state;

		$review_id = 0;
		if ( $review instanceof WP_Comment ) {
			$review_id = (int) $review->comment_ID;
		} elseif ( is_object( $review ) && isset( $review->comment_ID ) ) {
			$review_id = (int) $review->comment_ID;
		} elseif ( is_numeric( $review ) ) {
			$review_id = (int) $review;
		}

		$stored_review = $review_id > 0 ? get_comment( $review_id ) : null;
		if ( ! $stored_review instanceof WP_Comment ) {
			return;
		}

		$stored_email = $this->canonical_email( $stored_review->comment_author_email );
		if ( '' === $stored_email || $stored_email !== $state['email'] ) {
			return;
		}

		$this->increment_email_counters();
		WC_Blacklist_Manager_Email::send_email_comment_suspect( $state['email'] );

		if ( $this->can_use_premium_activity_logs() ) {
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_suspect(
				$this->get_email_view_json( $state['email'], $state['normalized'] ),
				'woo_review',
				$this->format_email_reason( $state['email'], $state['normalized'], 'suspected_email_attempt: ' )
			);
		}
	}

	/**
	 * Resolve a valid create/update identity, or false for identity-neutral input.
	 *
	 * @param array<string,mixed> $prepared_review Prepared review data.
	 * @param WP_REST_Request     $request         REST request.
	 * @return array<string,string>|false
	 */
	private function get_request_identity( array $prepared_review, WP_REST_Request $request ) {
		$method = strtoupper( (string) $request->get_method() );
		if ( 'POST' !== $method && ! in_array( $method, array( 'PUT', 'PATCH' ), true ) ) {
			return false;
		}

		if ( in_array( $method, array( 'PUT', 'PATCH' ), true ) ) {
			$review_id = absint( $request->get_param( 'id' ) );
			if ( $review_id < 1 || ! $request->has_param( 'reviewer_email' ) ) {
				return false;
			}

			$stored_review = get_comment( $review_id );
			if ( ! $stored_review instanceof WP_Comment ) {
				return false;
			}

			$email      = $this->canonical_email( $prepared_review['comment_author_email'] ?? '' );
			$normalized = $this->normalized_email( $email );
			$stored     = $this->normalized_email( $stored_review->comment_author_email );

			if ( '' === $email || '' === $normalized || $normalized === $stored ) {
				return false;
			}

			return array(
				'email'      => $email,
				'normalized' => $normalized,
				'operation'  => 'update',
			);
		}

		$email      = $this->canonical_email( $prepared_review['comment_author_email'] ?? '' );
		$normalized = $this->normalized_email( $email );
		if ( '' === $email || '' === $normalized ) {
			return false;
		}

		return array(
			'email'      => $email,
			'normalized' => $normalized,
			'operation'  => 'create',
		);
	}

	/**
	 * Evaluate the registered Premium adapter without allowing it to own output.
	 *
	 * @param array<string,string> $identity       Canonical reviewer identity.
	 * @param string               $local_decision BM-0162 local outcome.
	 * @return array{outcome:string}
	 */
	private function evaluate_disposable_policy( array $identity, $local_decision ) {
		$fallback = array( 'outcome' => 'NOT_APPLICABLE' );
		if ( ! is_array( self::$disposable_policy_adapter ) ) {
			return $fallback;
		}

		try {
			$result = call_user_func(
				self::$disposable_policy_adapter['evaluate'],
				array(
					'version'        => self::DISPOSABLE_POLICY_ADAPTER_CONTRACT_VERSION,
					'email'          => $identity['email'],
					'normalized'     => $identity['normalized'],
					'operation'      => $identity['operation'],
					'local_decision' => (string) $local_decision,
				)
			);
		} catch ( Throwable $error ) {
			unset( $error );

			return array( 'outcome' => 'UNAVAILABLE' );
		}

		if ( ! is_array( $result )
			|| self::DISPOSABLE_POLICY_ADAPTER_CONTRACT_VERSION !== (int) ( $result['version'] ?? 0 ) ) {
			return array( 'outcome' => 'UNAVAILABLE' );
		}

		$outcome = strtoupper( (string) ( $result['outcome'] ?? '' ) );
		if ( ! in_array( $outcome, array( 'VALID', 'INVALID', 'UNAVAILABLE', 'IN_PROGRESS', 'MISCONFIGURED', 'NOT_APPLICABLE' ), true ) ) {
			$outcome = 'UNAVAILABLE';
		}

		return array( 'outcome' => $outcome );
	}

	/**
	 * Invoke the one selected adapter's effects without exposing adapter detail.
	 *
	 * @param array<string,string> $identity Canonical reviewer identity.
	 * @return void
	 */
	private function commit_disposable_policy( array $identity ) {
		if ( ! is_array( self::$disposable_policy_adapter ) ) {
			return;
		}

		try {
			call_user_func(
				self::$disposable_policy_adapter['commit'],
				array(
					'version'    => self::DISPOSABLE_POLICY_ADAPTER_CONTRACT_VERSION,
					'email'      => $identity['email'],
					'normalized' => $identity['normalized'],
					'operation'  => $identity['operation'],
					'outcome'    => 'INVALID',
				)
			);
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Evaluate email and eligible domain state without effects.
	 *
	 * @param array<string,string> $identity Canonical identity.
	 * @return array<string,mixed>
	 */
	private function evaluate_identity( array $identity ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wc_blacklist';
		$query_args = array( $identity['email'], $identity['normalized'], $identity['normalized'] );
		$blocked    = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1
				FROM {$table_name}
				WHERE is_blocked = 1
				AND (
					email_address = %s
					OR ( %s <> '' AND normalized_email = %s )
				)
				LIMIT 1",
				...$query_args
			)
		);

		$suspect = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1
				FROM {$table_name}
				WHERE is_blocked = 0
				AND (
					email_address = %s
					OR ( %s <> '' AND normalized_email = %s )
				)
				LIMIT 1",
				...$query_args
			)
		);

		if ( $blocked ) {
			return array( 'decision' => 'blocked_email' );
		}

		$domain_match = $this->get_domain_match( $identity['email'] );
		if ( $domain_match['is_blocked'] ) {
			return array(
				'decision'     => 'blocked_domain',
				'domain_value' => $domain_match['display_value'],
				'domain_reason'=> $domain_match['reason'],
			);
		}

		return array( 'decision' => $suspect ? 'suspect_email' : 'clear' );
	}

	/**
	 * Evaluate the native comment-domain gates and match behavior without effects.
	 *
	 * @param string $email Canonical email.
	 * @return array<string,mixed>
	 */
	private function get_domain_match( $email ) {
		$clear = array(
			'is_blocked'   => false,
			'display_value'=> '',
			'reason'       => '',
		);

		if ( ! $this->premium_available()
			|| ! (bool) get_option( 'wc_blacklist_domain_enabled', 0 )
			|| '1' !== (string) get_option( 'wc_blacklist_domain_comment', '0' ) ) {
			return $clear;
		}

		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return $clear;
		}

		$domain = strtolower( trim( substr( $email, $at + 1 ) ) );
		if ( '' === $domain ) {
			return $clear;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_blacklist';
		$cache_key  = 'banned_domain_' . md5( $domain );
		$exact      = wp_cache_get( $cache_key, 'wc_blacklist' );

		if ( false === $exact ) {
			$exact = ! empty(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1
						FROM {$table_name}
						WHERE domain = %s
						LIMIT 1",
						$domain
					)
				)
			);
			wp_cache_set( $cache_key, $exact, 'wc_blacklist', HOUR_IN_SECONDS );
		}

		$tld_hit = $this->get_tld_hit( $domain );
		if ( ! $exact && '' === $tld_hit ) {
			return $clear;
		}

		return array(
			'is_blocked'    => true,
			'display_value' => '' !== $tld_hit ? $tld_hit : $domain,
			'reason'        => '' !== $tld_hit ? 'blocked_tld_attempt: ' . $tld_hit : 'blocked_domain_attempt: ' . $domain,
		);
	}

	/**
	 * Return the first native one/two/three-label TLD hit.
	 *
	 * @param string $domain Email domain.
	 * @return string
	 */
	private function get_tld_hit( $domain ) {
		$configured = get_option( 'wc_blacklist_domain_top_level', array() );
		if ( is_string( $configured ) ) {
			$configured = array_filter( array_map( 'trim', explode( ',', $configured ) ) );
		}

		$blocked = array();
		foreach ( (array) $configured as $tld ) {
			$tld = strtolower( trim( (string) $tld ) );
			if ( '' === $tld ) {
				continue;
			}
			if ( '.' !== $tld[0] ) {
				$tld = '.' . $tld;
			}
			if ( preg_match( '/^\.[a-z0-9][a-z0-9\-\.]*$/', $tld ) ) {
				$blocked[ $tld ] = true;
			}
		}

		$labels     = array_reverse( explode( '.', $domain ) );
		$candidates = array();
		if ( isset( $labels[0] ) ) {
			$candidates[] = '.' . $labels[0];
		}
		if ( isset( $labels[1] ) ) {
			$candidates[] = '.' . $labels[1] . '.' . $labels[0];
		}
		if ( isset( $labels[2] ) ) {
			$candidates[] = '.' . $labels[2] . '.' . $labels[1] . '.' . $labels[0];
		}

		foreach ( $candidates as $candidate ) {
			if ( isset( $blocked[ $candidate ] ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Commit exactly one native-shaped block effect set.
	 *
	 * @param array<string,mixed>  $decision Selected decision.
	 * @param array<string,string> $identity Canonical identity.
	 * @return void
	 */
	private function commit_blocked_decision( array $decision, array $identity ) {
		if ( 'blocked_email' === $decision['decision'] ) {
			$this->increment_email_counters();
			WC_Blacklist_Manager_Email::send_email_comment_block( $identity['email'] );

			if ( $this->can_use_premium_activity_logs() ) {
				WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_block(
					$this->get_email_view_json( $identity['email'], $identity['normalized'] ),
					'woo_review',
					$this->format_email_reason( $identity['email'], $identity['normalized'] )
				);
			}

			return;
		}

		$this->increment_counter( 'wc_blacklist_sum_block_domain' );
		$this->increment_counter( 'wc_blacklist_sum_block_total' );
		WC_Blacklist_Manager_Email::send_email_comment_block( '', '', $decision['domain_value'] );

		if ( $this->can_use_premium_activity_logs() ) {
			WC_Blacklist_Manager_Premium_Activity_Logs_Insert::comment_block(
				'',
				'woo_review',
				'',
				'',
				'',
				$decision['domain_reason']
			);
		}
	}

	/** @return WP_Error */
	private function get_block_error() {
		$notice = get_option(
			'wc_blacklist_comment_notice',
			__( 'Sorry! You are no longer allowed to submit a comment on our site. If you think it is a mistake, please contact support.', 'wc-blacklist-manager' )
		);

		return new WP_Error(
			'wc_blacklist_rest_product_review_blocked',
			wp_strip_all_tags( wp_kses_post( $notice ) ),
			array( 'status' => 403 )
		);
	}

	/** @return bool */
	private function premium_available() {
		return function_exists( 'wc_blacklist_manager_is_premium_available' )
			&& wc_blacklist_manager_is_premium_available();
	}

	/** @return bool */
	private function can_use_premium_activity_logs() {
		return $this->premium_available() && class_exists( 'WC_Blacklist_Manager_Premium_Activity_Logs_Insert' );
	}

	/** @param string $option_name Counter option. */
	private function increment_counter( $option_name ) {
		update_option( $option_name, (int) get_option( $option_name, 0 ) + 1 );
	}

	/** @return void */
	private function increment_email_counters() {
		$this->increment_counter( 'wc_blacklist_sum_block_email' );
		$this->increment_counter( 'wc_blacklist_sum_block_total' );
	}

	/**
	 * @param mixed $email Candidate email.
	 * @return string
	 */
	private function canonical_email( $email ) {
		$email = sanitize_email( (string) $email );

		return '' !== $email && is_email( $email ) ? strtolower( $email ) : '';
	}

	/**
	 * @param mixed $email Candidate email.
	 * @return string
	 */
	private function normalized_email( $email ) {
		$email = $this->canonical_email( $email );

		return '' !== $email ? yobm_normalize_email( $email ) : '';
	}

	/**
	 * @param string $email      Canonical email.
	 * @param string $normalized Normalized email.
	 * @return string|false
	 */
	private function get_email_view_json( $email, $normalized ) {
		$ip_address = get_real_customer_ip();
		$view_data  = array(
			'ip_address'       => $ip_address,
			'email'            => $email,
			'normalized_email' => $normalized,
		);

		if ( '' !== $ip_address ) {
			$view_data['ip_hash'] = hash_hmac( 'sha256', $ip_address, wp_salt( 'auth' ) );
		}

		return wp_json_encode( $view_data );
	}

	/**
	 * @param string $email      Canonical email.
	 * @param string $normalized Normalized email.
	 * @param string $prefix     Reason prefix.
	 * @return string
	 */
	private function format_email_reason( $email, $normalized, $prefix = 'blocked_email_attempt: ' ) {
		$reason = $prefix . $email;
		if ( '' !== $normalized && $normalized !== $email ) {
			$reason .= ' | normalized: ' . $normalized;
		}

		return $reason;
	}
}

new WC_Blacklist_Manager_REST_Product_Review_Local_Identity();
