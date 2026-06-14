<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'premium-preview-helpers.php';

$unlock_url         = isset( $unlock_url ) ? $unlock_url : 'https://yoohw.com/product/blacklist-manager-premium/';
$woocommerce_active = ! empty( $woocommerce_active );

if ( $woocommerce_active ) {
	wc_blacklist_manager_render_premium_preview_tab(
		array(
			'tab_id'      => 'anti_bots',
			'title'       => __( 'Anti-bot protection', 'wc-blacklist-manager' ),
			'description' => __( 'Add invisible checkout protection for scripted orders, direct-to-checkout abuse, and high-speed Store API attacks without adding friction for normal shoppers.', 'wc-blacklist-manager' ),
			'unlock_url'  => $unlock_url,
			'context'     => 'anti_bots',
			'icon'        => 'dashicons-shield-alt',
			'columns'     => 4,
			'cards'       => array(
				array(
					'icon'        => 'dashicons-visibility',
					'title'       => __( 'Browser execution proof', 'wc-blacklist-manager' ),
					'description' => __( 'Confirm that checkout traffic behaves like a real browser before accepting the order flow.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-randomize',
					'title'       => __( 'Session continuity', 'wc-blacklist-manager' ),
					'description' => __( 'Flag sessions that skip normal cart and checkout steps or jump directly to order submission.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-admin-site-alt3',
					'title'       => __( 'Device and fingerprint signals', 'wc-blacklist-manager' ),
					'description' => __( 'Use device identity and browser anomalies as risk signals for repeat abuse.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-performance',
					'title'       => __( 'Checkout API rate limits', 'wc-blacklist-manager' ),
					'description' => __( 'Slow down repeated checkout and card-testing bursts before they create more failed orders.', 'wc-blacklist-manager' ),
				),
			),
		)
	);

	wc_blacklist_manager_render_premium_preview_tab(
		array(
			'tab_id'      => 'automation',
			'title'       => __( 'Premium automation', 'wc-blacklist-manager' ),
			'description' => __( 'Turn suspicious patterns into score-only signals first, then choose email alerts, suspect actions, blocklist actions, or order cancellation when you are ready.', 'wc-blacklist-manager' ),
			'unlock_url'  => $unlock_url,
			'context'     => 'automation',
			'icon'        => 'dashicons-controls-repeat',
			'cards'       => array(
				array(
					'badge'       => __( 'Safe rollout', 'wc-blacklist-manager' ),
					'icon'        => 'dashicons-chart-line',
					'title'       => __( 'Score before blocking', 'wc-blacklist-manager' ),
					'description' => __( 'Start rules as score-only so your team can review what would have happened before enabling stronger actions.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-id',
					'title'       => __( 'Repeated identity changes', 'wc-blacklist-manager' ),
					'description' => __( 'Catch customers reusing phones, emails, IPs, devices, or addresses in suspicious combinations.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-location-alt',
					'title'       => __( 'IP and location mismatch', 'wc-blacklist-manager' ),
					'description' => __( 'React when hosting IPs, VPNs, TOR, country mismatches, or IP-to-address distance look risky.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-money-alt',
					'title'       => __( 'Payment abuse actions', 'wc-blacklist-manager' ),
					'description' => __( 'Use card country, AVS, high-risk countries, and PayPal payer mismatch signals in automation.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-update',
					'title'       => __( 'Order status rules', 'wc-blacklist-manager' ),
					'description' => __( 'Automatically move customers to suspects or blocklist when order status patterns match your workflow.', 'wc-blacklist-manager' ),
				),
			),
		)
	);

	wc_blacklist_manager_render_premium_preview_tab(
		array(
			'tab_id'      => 'scoring',
			'title'       => __( 'Risk scoring', 'wc-blacklist-manager' ),
			'description' => __( 'Convert multiple fraud signals into a clear order risk score that is easier to review than isolated warnings.', 'wc-blacklist-manager' ),
			'unlock_url'  => $unlock_url,
			'context'     => 'scoring',
			'icon'        => 'dashicons-chart-bar',
			'cards'       => array(
				array(
					'icon'        => 'dashicons-dashboard',
					'title'       => __( 'Order risk metabox', 'wc-blacklist-manager' ),
					'description' => __( 'Show score, risk level, and matched reasons directly on WooCommerce orders.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-filter',
					'title'       => __( 'Weighted rule signals', 'wc-blacklist-manager' ),
					'description' => __( 'Assign weight to identity, IP, payment, device, and order pattern signals.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-warning',
					'title'       => __( 'Review thresholds', 'wc-blacklist-manager' ),
					'description' => __( 'Separate notice, suspect, and block levels so teams know which orders need attention.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-admin-tools',
					'title'       => __( 'Automation handoff', 'wc-blacklist-manager' ),
					'description' => __( 'Let scoring feed automation only after you are confident the thresholds match your store.', 'wc-blacklist-manager' ),
				),
			),
		)
	);
}

wc_blacklist_manager_render_premium_preview_tab(
	array(
		'tab_id'      => 'integrations',
		'title'       => __( 'Integrations', 'wc-blacklist-manager' ),
		'description' => __( 'Connect the services that extend verification, validation, CAPTCHA, SMS delivery, geocoding, and IP intelligence.', 'wc-blacklist-manager' ),
		'unlock_url'  => $unlock_url,
		'context'     => 'integrations',
		'icon'        => 'dashicons-admin-plugins',
		'cards'       => array(
			array(
				'icon'        => 'dashicons-shield',
				'title'       => __( 'CAPTCHA providers', 'wc-blacklist-manager' ),
				'description' => __( 'Use reCAPTCHA, Cloudflare Turnstile, or hCaptcha on protected forms when invisible checks are not enough.', 'wc-blacklist-manager' ),
			),
			array(
				'icon'        => 'dashicons-email-alt2',
				'title'       => __( 'SMS providers', 'wc-blacklist-manager' ),
				'description' => __( 'Send phone verification codes through Yo Credits, Twilio, or Textmagic depending on your operation.', 'wc-blacklist-manager' ),
			),
			array(
				'icon'        => 'dashicons-location',
				'title'       => __( 'Maps and IP intelligence', 'wc-blacklist-manager' ),
				'description' => __( 'Power address autocomplete, IP enrichment, and location mismatch checks with trusted providers.', 'wc-blacklist-manager' ),
			),
			array(
				'icon'        => 'dashicons-yes-alt',
				'title'       => __( 'Email and phone validation', 'wc-blacklist-manager' ),
				'description' => __( 'Detect invalid, disposable, or risky contact details before they become customer records.', 'wc-blacklist-manager' ),
			),
		),
	)
);

if ( $woocommerce_active ) {
	wc_blacklist_manager_render_premium_preview_tab(
		array(
			'tab_id'      => 'payments',
			'title'       => __( 'Payment intelligence', 'wc-blacklist-manager' ),
			'description' => __( 'Use gateway data to spot payment abuse earlier and apply the right restrictions only when the customer looks risky.', 'wc-blacklist-manager' ),
			'unlock_url'  => $unlock_url,
			'context'     => 'payments',
			'icon'        => 'dashicons-money-alt',
			'cards'       => array(
				array(
					'icon'        => 'dashicons-hidden',
					'title'       => __( 'Hide gateways for suspects', 'wc-blacklist-manager' ),
					'description' => __( 'Limit risky customers to safer payment methods without changing checkout for everyone else.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-admin-site-alt3',
					'title'       => __( 'Card and billing mismatch', 'wc-blacklist-manager' ),
					'description' => __( 'Flag orders when supported gateways expose a card country that does not match billing details.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-clipboard',
					'title'       => __( 'AVS and gateway signals', 'wc-blacklist-manager' ),
					'description' => __( 'Bring Stripe, PayPal, Mollie, Braintree, WooPayments, and related gateway signals into review.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-palmtree',
					'title'       => __( 'High-risk payment countries', 'wc-blacklist-manager' ),
					'description' => __( 'Use country risk presets as a starting point, then tune the list for your business.', 'wc-blacklist-manager' ),
				),
			),
		)
	);
}

wc_blacklist_manager_render_premium_preview_tab(
	array(
		'tab_id'      => 'permission',
		'title'       => __( 'Team permissions', 'wc-blacklist-manager' ),
		'description' => __( 'Give trusted staff access to the areas they manage without handing over full administrator control.', 'wc-blacklist-manager' ),
		'unlock_url'  => $unlock_url,
		'context'     => 'permission',
		'icon'        => 'dashicons-groups',
		'cards'       => array(
			array(
				'icon'        => 'dashicons-dashboard',
				'title'       => __( 'Dashboard access', 'wc-blacklist-manager' ),
				'description' => __( 'Let operations staff review and maintain blacklist entries from the dashboard.', 'wc-blacklist-manager' ),
			),
			array(
				'icon'        => 'dashicons-email',
				'title'       => __( 'Notification access', 'wc-blacklist-manager' ),
				'description' => __( 'Allow selected roles to manage alert emails and customer-facing notices.', 'wc-blacklist-manager' ),
			),
			array(
				'icon'        => 'dashicons-admin-settings',
				'title'       => __( 'Settings access', 'wc-blacklist-manager' ),
				'description' => __( 'Restrict sensitive configuration to the roles that should manage fraud controls.', 'wc-blacklist-manager' ),
			),
		),
	)
);

wc_blacklist_manager_render_premium_preview_tab(
	array(
		'tab_id'      => 'tools',
		'title'       => __( 'Premium tools', 'wc-blacklist-manager' ),
		'description' => __( 'Maintain blacklist data faster with import, export, merge, cleanup, and retention tools built for store operations.', 'wc-blacklist-manager' ),
		'unlock_url'  => $unlock_url,
		'context'     => 'tools',
		'icon'        => 'dashicons-admin-tools',
		'cards'       => array(
			array(
				'icon'        => 'dashicons-upload',
				'title'       => __( 'CSV import and export', 'wc-blacklist-manager' ),
				'description' => __( 'Move blacklist data between stores, back up local lists, and normalize contact fields during import.', 'wc-blacklist-manager' ),
			),
			array(
				'icon'        => 'dashicons-yes',
				'title'       => __( 'Verified-customer merge', 'wc-blacklist-manager' ),
				'description' => __( 'Merge completed order emails and phones into the verified list so returning customers face less friction.', 'wc-blacklist-manager' ),
			),
			array(
				'icon'        => 'dashicons-database-remove',
				'title'       => __( 'Device cleanup', 'wc-blacklist-manager' ),
				'description' => __( 'Remove old low-value device records while keeping useful abuse history available.', 'wc-blacklist-manager' ),
			),
			array(
				'icon'        => 'dashicons-backup',
				'title'       => __( 'Log retention', 'wc-blacklist-manager' ),
				'description' => __( 'Keep activity logs useful by limiting records by age or amount instead of letting tables grow forever.', 'wc-blacklist-manager' ),
			),
		),
	)
);

wc_blacklist_manager_render_premium_preview_tab(
	array(
		'tab_id'      => 'connection',
		'title'       => __( 'Multi-store blacklist sync', 'wc-blacklist-manager' ),
		'description' => __( 'Run one store on its own, or connect multiple stores so blacklist events can flow into the main store for central review.', 'wc-blacklist-manager' ),
		'unlock_url'  => $unlock_url,
		'context'     => 'connection',
		'icon'        => 'dashicons-networking',
		'cards'       => array(
			array(
				'badge'       => __( 'Default', 'wc-blacklist-manager' ),
				'icon'        => 'dashicons-store',
				'title'       => __( 'Standalone store', 'wc-blacklist-manager' ),
				'description' => __( 'Best for single-store shops. No host URL, sync key, approval queue, or remote fields are needed.', 'wc-blacklist-manager' ),
			),
			array(
				'icon'        => 'dashicons-admin-multisite',
				'title'       => __( 'Main store', 'wc-blacklist-manager' ),
				'description' => __( 'Receive blacklist events, manage connection keys, and review connected-store approvals in one place.', 'wc-blacklist-manager' ),
			),
			array(
				'icon'        => 'dashicons-migrate',
				'title'       => __( 'Connected store', 'wc-blacklist-manager' ),
				'description' => __( 'Send blacklist events to the main store using the host URL and generated connection key.', 'wc-blacklist-manager' ),
			),
		),
	)
);
