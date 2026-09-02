<?php
/**
 * Activity Log Template
 *
 * This file displays the detection log entries.
 *
 * @package WC_Blacklist_Manager
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'premium-preview-helpers.php';

global $wpdb;
$table_name = $wpdb->prefix . 'wc_blacklist_detection_log';
$logs_per_page = 20;

// Get current page (ensure it's at least 1).
$current_page = isset($_GET['paged']) ? max( 1, intval($_GET['paged']) ) : 1;
$offset = ($current_page - 1) * $logs_per_page;

// Get total number of items.
$total_items = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

// Calculate the total pages.
$total_pages = ceil( $total_items / $logs_per_page );

// Retrieve log entries for the current page.
$logs = $wpdb->get_results(
    $wpdb->prepare( 
        "SELECT * FROM $table_name ORDER BY `timestamp` DESC LIMIT %d OFFSET %d",
        $logs_per_page,
        $offset
    )
);
?>

<div class="wrap">
	<h1>
		<?php echo esc_html__('Activity logs', 'wc-blacklist-manager'); ?>
		<?php if (get_option('yoohw_settings_disable_menu') != 1): ?>
			<a href="https://docs.yoohw.com/category/blacklist-manager/" target="_blank" class="button button-secondary yoohw-docs-btn" style="display: inline-flex;"><span class="dashicons dashicons-editor-help"></span> <?php echo esc_html__('Docs', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>
		<?php if (!$premium_active): ?>
			<a href="https://yoohw.com/contact-us/" target="_blank" class="button button-secondary"><?php echo esc_html__('Support', 'wc-blacklist-manager'); ?></a>
		<?php endif; ?>
		<?php if ($premium_active && get_option('yoohw_settings_disable_menu') != 1): ?>
			<a href="https://yoohw.com/support/" target="_blank" class="button button-secondary"><?php echo esc_html__('Support', 'wc-blacklist-manager'); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-blacklist-manager-settings&tab=tools#activity_log_retention' ) ); ?>" class="activity_logs_tool_settings_url">
				<?php esc_html_e( 'Tool settings', 'wc-blacklist-manager' ); ?>
			</a>
		<?php endif; ?>
	</h1>
    
	<?php if ( $premium_active ): ?>
		<form method="post">
			<?php wp_nonce_field( 'bulk_detection_log_delete', 'bulk_detection_log_nonce' ); ?>

			<?php
			// Build & render the List Table (this automatically prints bulk selectors + pagination top/bottom)
			$list_table = new WC_Blacklist_Manager_Activity_Log_Table();
			$list_table->prepare_items();
			$list_table->display();
			?>
		</form>

		<script>
		document.addEventListener('DOMContentLoaded', function () {
			if (!document.getElementById('bm-view-styles')) {
				const style = document.createElement('style');
				style.id = 'bm-view-styles';
				style.textContent = `
					#bm-view-overlay {
						position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000;
					}
					#bm-view-popup {
						position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%);
						background: #fff; padding: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);
						max-width: 960px; width: 72vw; max-height: 84vh; overflow: auto; border-radius: 8px; z-index: 10001;
					}
					#bm-view-popup h2 {
						margin: 0 0 14px; font-size: 18px; line-height: 1.3;
					}
					#bm-view-popup .bm-modal-grid {
						display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:12px;
					}
					#bm-view-popup .bm-modal-section {
						border:1px solid #dcdcde; border-radius:8px; padding:12px; background:#fff;
					}
					#bm-view-popup .bm-modal-section h3 {
						margin:0 0 10px; font-size:13px; text-transform:uppercase; color:#50575e;
					}
					#bm-view-popup .bm-kv {
						display:grid; grid-template-columns: minmax(116px, 34%) minmax(0, 1fr); gap:8px; margin:6px 0;
					}
					#bm-view-popup .bm-kv dt {
						color:#646970; font-weight:600;
					}
					#bm-view-popup .bm-kv dd {
						margin:0; word-break:break-word;
					}
					#bm-view-popup .bm-modal-section-wide {
						grid-column: 1 / -1;
					}
					#bm-view-popup .bm-value-list {
						display:flex; flex-wrap:wrap; gap:5px;
					}
					#bm-view-popup .bm-pill {
						display:inline-flex; align-items:center; max-width:100%;
						padding:2px 7px; border:1px solid #dcdcde; border-radius:12px;
						background:#f6f7f7; color:#1d2327; font-size:12px; line-height:1.5;
					}
					#bm-view-popup .bm-mini-list {
						display:grid; gap:8px;
					}
					#bm-view-popup .bm-mini-card {
						border:1px solid #e2e4e7; border-radius:6px; padding:8px; background:#f6f7f7;
					}
					#bm-view-popup .bm-mini-card-title {
						margin:0 0 6px; font-weight:600; color:#1d2327;
					}
					#bm-view-popup .bm-nested {
						display:grid; grid-template-columns:minmax(110px, 28%) minmax(0, 1fr); gap:5px 8px; margin:0;
					}
					#bm-view-popup .bm-nested dt {
						color:#646970; font-weight:600;
					}
					#bm-view-popup .bm-nested dd {
						margin:0; word-break:break-word;
					}
					#bm-view-popup .bm-score {
						display:flex; align-items:center; gap:8px; flex-wrap:wrap;
					}
					#bm-view-popup .bm-score strong {
						font-size:16px;
					}
					#bm-view-popup .bm-raw {
						margin-top:12px;
					}
					#bm-view-popup .bm-raw summary {
						cursor:pointer; font-weight:600;
					}
					#bm-view-popup pre {
						white-space: pre-wrap; word-break: break-word; margin: 10px 0 0; max-height: 320px; overflow:auto; background:#f6f7f7; border:1px solid #dcdcde; padding:12px; border-radius:6px;
					}
					#bm-view-popup .bm-close {
						margin-top: 14px; padding: 8px 16px; background: #2271b1; color: #fff; border: none;
						border-radius: 6px; cursor: pointer;
					}
					@media (max-width: 782px) {
						#bm-view-popup {
							width: 92vw; padding: 16px;
							left: 50%; top: 50%; transform: translate(-50%, -50%);
						}
						#bm-view-popup .bm-modal-grid { grid-template-columns: 1fr; }
						#bm-view-popup .bm-kv { grid-template-columns: 1fr; gap:2px; }
						#bm-view-popup .bm-modal-section-wide { grid-column:auto; }
						#bm-view-popup .bm-nested { grid-template-columns: 1fr; gap:2px; }
						#bm-view-popup pre { font-size: 13px; line-height: 1.4; }
						#bm-view-popup .bm-close { width: 100%; }
					}
				`;
				document.head.appendChild(style);
			}

			function humanize(value) {
				return String(value || '')
					.replace(/[_-]+/g, ' ')
					.replace(/\s+/g, ' ')
					.trim()
					.replace(/\b\w/g, function (m) { return m.toUpperCase(); });
			}

			function isEmpty(value) {
				if (value === null || value === undefined || value === '') return true;
				if (Array.isArray(value)) return value.length === 0;
				if (typeof value === 'object') return Object.keys(value).length === 0;
				return false;
			}

			function stringify(value) {
				if (isEmpty(value)) return '';
				if (typeof value === 'boolean') return value ? 'Yes' : 'No';
				if (Array.isArray(value)) return value.map(stringify).filter(Boolean).join(', ');
				if (typeof value === 'object') return JSON.stringify(value);
				return String(value);
			}

			function parseJson(raw) {
				if (!raw) return null;
				try {
					const parsed = JSON.parse(raw);
					return parsed && typeof parsed === 'object' ? parsed : null;
				} catch (e) {
					return null;
				}
			}

			function makePills(values, shouldHumanize) {
				const list = document.createElement('div');
				list.className = 'bm-value-list';

				values.map(stringify).filter(Boolean).slice(0, 12).forEach(function (value) {
					const pill = document.createElement('span');
					pill.className = 'bm-pill';
					pill.textContent = shouldHumanize ? humanize(value) : value;
					list.appendChild(pill);
				});

				return list.children.length ? list : null;
			}

			function renderNestedObject(object) {
				const dl = document.createElement('dl');
				dl.className = 'bm-nested';

				Object.keys(object || {}).slice(0, 12).forEach(function (key) {
					const value = object[key];
					if (isEmpty(value)) return;

					const dt = document.createElement('dt');
					dt.textContent = humanize(key);
					const dd = document.createElement('dd');

					const rendered = renderValue(value, key);
					if (rendered) {
						dd.appendChild(rendered);
					} else {
						dd.textContent = stringify(value);
					}

					dl.appendChild(dt);
					dl.appendChild(dd);
				});

				return dl.children.length ? dl : null;
			}

			function renderCard(value, title) {
				const card = document.createElement('div');
				card.className = 'bm-mini-card';

				if (title) {
					const heading = document.createElement('div');
					heading.className = 'bm-mini-card-title';
					heading.textContent = humanize(title);
					card.appendChild(heading);
				}

				if (value && typeof value === 'object' && !Array.isArray(value)) {
					const nested = renderNestedObject(value);
					if (nested) card.appendChild(nested);
				} else {
					card.appendChild(document.createTextNode(stringify(value)));
				}

				return card.children.length || card.textContent ? card : null;
			}

			function renderObjectList(object) {
				const keys = Object.keys(object || {}).filter(function (key) { return !isEmpty(object[key]); });
				if (!keys.length) return null;

				const list = document.createElement('div');
				list.className = 'bm-mini-list';

				keys.slice(0, 12).forEach(function (key) {
					const value = object[key];
					if (value && typeof value === 'object') {
						const card = renderCard(value, key);
						if (card) list.appendChild(card);
					} else {
						const card = renderCard({ value: value }, key);
						if (card) list.appendChild(card);
					}
				});

				return list.children.length ? list : null;
			}

			function renderSignals(signals) {
				if (!Array.isArray(signals) || !signals.length) return null;

				const list = document.createElement('div');
				list.className = 'bm-mini-list';

				signals.slice(0, 10).forEach(function (signal) {
					if (!signal || typeof signal !== 'object') return;

					const card = document.createElement('div');
					card.className = 'bm-mini-card';

					const title = document.createElement('div');
					title.className = 'bm-mini-card-title';
					const source = humanize(signal.source || 'Signal');
					const points = signal.points !== undefined ? ' - ' + signal.points + ' pts' : '';
					const enabled = signal.enabled === false ? ' (off)' : '';
					title.textContent = source + points + enabled;
					card.appendChild(title);

					const rows = [
						{ label: 'Reasons', value: signal.reasons },
						{ label: 'Detail', value: signal.detail && signal.detail.reason ? humanize(signal.detail.reason) : '' }
					];
					addRows(card, rows);

					list.appendChild(card);
				});

				return list.children.length ? list : null;
			}

			function renderValue(value, label) {
				if (isEmpty(value)) return null;

				if ('Signals' === label) {
					return renderSignals(value);
				}

				if ('Reasons' === label) {
					return Array.isArray(value) ? makePills(value, true) : renderValue(humanize(value), '');
				}

				if ('Score' === label && value !== '') {
					const score = document.createElement('span');
					score.className = 'bm-score';
					const strong = document.createElement('strong');
					strong.textContent = stringify(value);
					score.appendChild(strong);
					return score;
				}

				if (Array.isArray(value)) {
					const scalar = value.every(function (item) { return !item || typeof item !== 'object'; });
					if (scalar) return makePills(value, false);

					const list = document.createElement('div');
					list.className = 'bm-mini-list';
					value.slice(0, 12).forEach(function (item, index) {
						const card = renderCard(item, 'Item ' + (index + 1));
						if (card) list.appendChild(card);
					});
					return list.children.length ? list : null;
				}

				if (value && typeof value === 'object') {
					const hasNested = Object.keys(value).some(function (key) {
						return value[key] && typeof value[key] === 'object';
					});
					return hasNested ? renderObjectList(value) : renderNestedObject(value);
				}

				const span = document.createElement('span');
				span.textContent = stringify(value);
				return span;
			}

			function addRows(section, rows) {
				const dl = document.createElement('dl');
				dl.className = 'bm-kv';

				rows.forEach(function (row) {
					if (isEmpty(row.value)) return;

					const dt = document.createElement('dt');
					dt.textContent = row.label;
					const dd = document.createElement('dd');
					const rendered = renderValue(row.value, row.label);
					if (rendered) {
						dd.appendChild(rendered);
					} else {
						dd.textContent = stringify(row.value);
					}
					dl.appendChild(dt);
					dl.appendChild(dd);
				});

				if (dl.children.length) {
					section.appendChild(dl);
				}
			}

			function makeSection(title, rows, wide) {
				const section = document.createElement('section');
				section.className = 'bm-modal-section';
				if (wide) section.classList.add('bm-modal-section-wide');

				const heading = document.createElement('h3');
				heading.textContent = title;
				section.appendChild(heading);
				addRows(section, rows);

				return section.children.length > 1 ? section : null;
			}

			function objectRows(object, keys) {
				if (!object || typeof object !== 'object') return [];
				return keys.map(function (key) {
					return { label: humanize(key), value: object[key] };
				});
			}

			function buildContent(log, view) {
				const wrap = document.createElement('div');
				const title = document.createElement('h2');
				title.textContent = '<?php echo esc_js( __( 'Activity log details', 'wc-blacklist-manager' ) ); ?>';
				wrap.appendChild(title);

				const grid = document.createElement('div');
				grid.className = 'bm-modal-grid';

				const overviewRows = [
					{ label: 'Log ID', value: log.id },
					{ label: 'Timestamp', value: log.timestamp },
					{ label: 'Type', value: humanize(log.type) },
					{ label: 'Source', value: humanize(log.source) },
					{ label: 'Action', value: humanize(log.action) },
					{ label: 'Details', value: log.details }
				];
				const overview = makeSection('Overview', overviewRows);
				if (overview) grid.appendChild(overview);

				if (view) {
					const customerRows = [
						{ label: 'IP address', value: view.ip_address },
						{ label: 'IP hash', value: view.ip_hash },
						{ label: 'First name', value: view.first_name },
						{ label: 'Last name', value: view.last_name },
						{ label: 'Email', value: view.email },
						{ label: 'Normalized email', value: view.normalized_email },
						{ label: 'Phone', value: view.phone },
						{ label: 'Shipping phone', value: view.shipping_phone }
					].concat(objectRows(view.identity, ['user_id', 'is_logged_in', 'ip_prefix', 'ip_hash', 'wc_session_prefix']));
					const customer = makeSection('Customer & Identity', customerRows);
					if (customer) grid.appendChild(customer);

					const orderRows = [
						{ label: 'Billing', value: view.billing },
						{ label: 'Shipping', value: view.shipping },
						{ label: 'Cart subtotal', value: view.cart_subtotal },
						{ label: 'Cart total', value: view.cart_total },
						{ label: 'Currency', value: view.currency },
						{ label: 'Payment method', value: view.payment_method },
						{ label: 'Coupons', value: view.coupons },
						{ label: 'Fees', value: view.fees },
						{ label: 'Shipping method', value: view.cart_shipping && view.cart_shipping.method },
						{ label: 'Shipping fee', value: view.cart_shipping && view.cart_shipping.fee },
						{ label: 'Tax', value: view.cart_tax },
						{ label: 'Cart items', value: view.cart_items }
					];
					const order = makeSection('Order & Payment', orderRows, !!view.cart_items || !!view.fees);
					if (order) grid.appendChild(order);

					const riskRows = [
						{ label: 'Score', value: view.score },
						{ label: 'Raw score', value: view.raw_score },
						{ label: 'Threshold', value: view.threshold },
						{ label: 'Thresholds', value: view.thresholds },
						{ label: 'Band', value: humanize(view.band) },
						{ label: 'Would block', value: view.would_block },
						{ label: 'Shadow mode', value: view.shadow_mode },
						{ label: 'Trust adjustment', value: view.trust_adjustment },
						{ label: 'Reasons', value: view.reasons },
						{ label: 'Summary', value: view.explanation && view.explanation.summary },
						{ label: 'Evidence', value: view.evidence && view.evidence.summary },
						{ label: 'Trust factors', value: view.trust && view.trust.factors },
						{ label: 'Signals', value: view.signals }
					].concat(objectRows(view.risk, ['score', 'threshold', 'band', 'reasons']));
					const risk = makeSection('Risk & Signals', riskRows, true);
					if (risk) grid.appendChild(risk);

					const challengeRows = [
						{ label: 'Event', value: humanize(view.event) },
						{ label: 'Provider', value: humanize(view.provider) },
						{ label: 'Integration', value: humanize(view.integration || view.source) },
						{ label: 'Reason', value: humanize(view.reason) },
						{ label: 'Surface', value: humanize(view.surface) },
						{ label: 'Challenge', value: view.challenge },
						{ label: 'Captcha', value: view.captcha },
						{ label: 'Payment flow', value: view.payment_flow },
						{ label: 'PayPal', value: view.paypal },
						{ label: 'Script', value: view.script }
					];
					const challenge = makeSection('Challenge & CAPTCHA', challengeRows, !!view.captcha || !!view.payment_flow || !!view.paypal);
					if (challenge) grid.appendChild(challenge);

					const requestRows = objectRows(view.request, ['ip', 'ip_hash', 'method', 'uri', 'path', 'route', 'user_agent', 'referer', 'origin', 'sec_fetch_site', 'payment_method']);
					const request = makeSection('Request', requestRows, true);
					if (request) grid.appendChild(request);
				}

				wrap.appendChild(grid);

				const raw = document.createElement('details');
				raw.className = 'bm-raw';
				const summary = document.createElement('summary');
				summary.textContent = 'Raw data';
				const pre = document.createElement('pre');
				pre.textContent = view ? JSON.stringify(view, null, 2) : (log.view || log.details || '');
				raw.appendChild(summary);
				raw.appendChild(pre);
				wrap.appendChild(raw);

				return wrap;
			}

			function readLog(button) {
				const raw = button.getAttribute('data-log') || '';
				const parsed = parseJson(raw);
				if (parsed) return parsed;

				return {
					view: button.getAttribute('data-view') || '',
					details: '',
					source: '',
					action: '',
					type: '',
					timestamp: ''
				};
			}

			function openPopup(button) {
				const log = readLog(button);
				const view = parseJson(log.view || '');

				const overlay = document.createElement('div');
				overlay.id = 'bm-view-overlay';

				const popup = document.createElement('div');
				popup.id = 'bm-view-popup';
				popup.setAttribute('role', 'dialog');
				popup.setAttribute('aria-modal', 'true');
				popup.setAttribute('aria-label', '<?php echo esc_js( __( 'Activity log details', 'wc-blacklist-manager' ) ); ?>');
				popup.setAttribute('tabindex', '-1');

				const closeBtn = document.createElement('button');
				closeBtn.type = 'button';
				closeBtn.className = 'bm-close';
				closeBtn.textContent = '<?php echo esc_js( __( 'Close', 'wc-blacklist-manager' ) ); ?>';

				function removePopup() {
					document.body.classList.remove('bm-no-scroll');
					window.removeEventListener('keydown', onKeyDown);
					if (popup.parentNode) popup.parentNode.removeChild(popup);
					if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
					if (openPopup.lastTrigger && openPopup.lastTrigger.focus) {
						openPopup.lastTrigger.focus();
					}
				}

				function onKeyDown(e) {
					if (e.key === 'Escape') {
						e.preventDefault();
						removePopup();
					}
				}

				closeBtn.addEventListener('click', removePopup);
				overlay.addEventListener('click', removePopup);
				window.addEventListener('keydown', onKeyDown);

				popup.appendChild(buildContent(log, view));
				popup.appendChild(closeBtn);
				document.body.appendChild(overlay);
				document.body.appendChild(popup);

				document.body.classList.add('bm-no-scroll');

				popup.scrollTop = 0;
				try {
					popup.focus({ preventScroll: true });
				} catch (e) {
					popup.focus();
					popup.scrollTop = 0;
				}
				window.requestAnimationFrame(function () {
					popup.scrollTop = 0;
				});
			}

			if (!document.getElementById('bm-no-scroll-style')) {
				const lockStyle = document.createElement('style');
				lockStyle.id = 'bm-no-scroll-style';
				lockStyle.textContent = 'body.bm-no-scroll { overflow: hidden !important; }';
				document.head.appendChild(lockStyle);
			}

			document.querySelectorAll('.show-view-data').forEach(function (btn) {
				btn.addEventListener('click', function () {
					openPopup.lastTrigger = this;
					openPopup(this);
				});
			});
		});
		</script>

	<?php else : ?>
		<?php
		wc_blacklist_manager_render_premium_preview_banner(
			array(
				'title'       => __( 'Activity logs', 'wc-blacklist-manager' ),
				'description' => __( 'Review what was blocked, suspected, verified, or removed so investigations are based on a clear event history.', 'wc-blacklist-manager' ),
				'unlock_url'  => $unlock_url,
				'context'     => 'activity',
				'icon'        => 'dashicons-list-view',
				'candidate_id' => 'premium.passive.activity.banner',
			)
		);

		wc_blacklist_manager_render_premium_preview_cards(
			array(
				array(
					'icon'        => 'dashicons-search',
					'title'       => __( 'Investigation timeline', 'wc-blacklist-manager' ),
					'description' => __( 'See the source, action, timestamp, and details behind detection events.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-filter',
					'title'       => __( 'Human and bot context', 'wc-blacklist-manager' ),
					'description' => __( 'Separate checkout, access, login, form, and verification events during review.', 'wc-blacklist-manager' ),
				),
				array(
					'icon'        => 'dashicons-backup',
					'title'       => __( 'Retention tools', 'wc-blacklist-manager' ),
					'description' => __( 'Keep logs useful by cleaning old records by age or amount from Premium tools.', 'wc-blacklist-manager' ),
				),
			),
			array( 'columns' => 3 )
		);
		?>
		<p><a href="https://docs.yoohw.com/use-activity-logs-to-review-blocked-attempts/" target="_blank"><?php esc_html_e( 'Learn how activity logs help review blocked attempts.', 'wc-blacklist-manager' ); ?></a></p>
	<?php endif; ?>
</div>
