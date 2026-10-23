<?php
/** Privileged, read-only reporting-readiness view. Variables are supplied by the owning controller. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<details id="yobm-database-readiness" class="yobm-schema-page yobm-schema-panel"<?php echo isset( $_GET['section'] ) && 'database-readiness' === $_GET['section'] ? ' open' : ''; ?>>
	<summary class="yobm-schema-panel-heading"><?php esc_html_e( 'View remediation instructions', 'wc-blacklist-manager' ); ?></summary>
	<div class="yobm-schema-panel-body">
		<p><?php
		if ( $manual ) {
			esc_html_e( 'Online repair cannot complete on this database. Scheduling again will not resolve this limitation. Ask your administrator or hosting provider to repair the affected indexes during approved maintenance.', 'wc-blacklist-manager' );
		} elseif ( $unknown ) {
			esc_html_e( 'Physical metadata is unavailable. Do not assume indexes are missing or that repair is needed. Ask hosting to check metadata access and verify the exact indexes first.', 'wc-blacklist-manager' );
		} else {
			echo esc_html( self::get_status()['message'] );
		}
		?></p>
		<?php if ( 'pending' === $context['status'] && false !== $context['scheduled'] && $context['scheduled'] < time() - self::LOCK_TTL ) : ?>
			<p><?php esc_html_e( 'The scheduled repair is overdue. Ask your administrator to check WordPress cron; scheduling again will not create a duplicate job.', 'wc-blacklist-manager' ); ?></p>
		<?php endif; ?>
		<details open><summary><?php esc_html_e( 'Site-local reporting indexes', 'wc-blacklist-manager' ); ?></summary>
			<div class="yobm-schema-table-scroll"><table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'Table / index', 'wc-blacklist-manager' ); ?></th><th scope="col"><?php esc_html_e( 'Expected columns', 'wc-blacklist-manager' ); ?></th><th scope="col"><?php esc_html_e( 'Engine', 'wc-blacklist-manager' ); ?></th><th scope="col"><?php esc_html_e( 'Physical status', 'wc-blacklist-manager' ); ?></th></tr></thead>
			<tbody><?php foreach ( $rows as $row ) : ?><tr><td><code><?php echo esc_html( $row['table'] ); ?></code><span class="yobm-schema-index"><?php echo esc_html( $row['index'] ); ?></span></td><td><code><?php echo esc_html( $row['columns'] ); ?></code></td><td><?php echo esc_html( $row['engine'] ?: __( 'Unavailable', 'wc-blacklist-manager' ) ); ?></td><td><?php echo esc_html( $status_labels[ $row['status'] ] ); ?></td></tr><?php endforeach; ?></tbody></table></div>
			<?php if ( ! empty( $context['state']['error_message'] ) ) : ?><p class="description"><?php echo esc_html( $context['state']['error_message'] ); ?></p><?php endif; ?>
		</details>
		<?php if ( ! $context['ready'] && 'newer_than_code' !== $context['status'] ) : ?>
		<details open><summary><?php esc_html_e( 'Hosting maintenance instructions', 'wc-blacklist-manager' ); ?></summary>
			<?php if ( $manual ) : ?>
				<p class="yobm-schema-caution"><?php esc_html_e( 'Blocking repair may interrupt purchases. Hosting must verify a restorable backup, database and temporary-space capacity, required permissions and an approved low-traffic maintenance window with traffic controls.', 'wc-blacklist-manager' ); ?></p>
				<ol class="yobm-schema-steps">
					<li><strong><?php esc_html_e( 'Inspect the correct WordPress site.', 'wc-blacklist-manager' ); ?></strong><code>wp blacklist-manager schema status</code><span><?php esc_html_e( 'This command refreshes stored readiness diagnostics.', 'wc-blacklist-manager' ); ?></span></li>
					<li><strong><?php esc_html_e( 'Repair one index during authorized CLI maintenance.', 'wc-blacklist-manager' ); ?></strong><code>wp blacklist-manager schema repair --allow-blocking</code><span><?php esc_html_e( 'At most one index is repaired per invocation. This page does not execute this command.', 'wc-blacklist-manager' ); ?></span></li>
					<li><strong><?php esc_html_e( 'Re-inspect before each next step.', 'wc-blacklist-manager' ); ?></strong><span><?php esc_html_e( 'Verify the exact index columns. Authorize another step separately only if still required. On an error, timeout or uncertain completion, stop and ask hosting to inspect the database session before retrying. Never use a blind retry loop.', 'wc-blacklist-manager' ); ?></span></li>
					<li><strong><?php esc_html_e( 'Confirm physical readiness.', 'wc-blacklist-manager' ); ?></strong><span><?php esc_html_e( 'After every required index is physically verified, reload Admin. The readiness notice disappears once the database is ready.', 'wc-blacklist-manager' ); ?></span></li>
				</ol>
			<?php elseif ( $unknown ) : ?><p><?php esc_html_e( 'Ask hosting to verify metadata permissions and physical indexes first. Do not begin repair based on an unknown result.', 'wc-blacklist-manager' ); ?></p>
			<?php else : ?><p><?php esc_html_e( 'Schedule queues an online-only repair. If the database cannot support it, maintenance guidance will be available here. No blocking fallback runs automatically.', 'wc-blacklist-manager' ); ?></p><?php endif; ?>
			<?php if ( $manual || $unknown ) : ?>
				<div class="yobm-schema-actions"><button type="button" class="button button-primary yobm-schema-copy" data-copied="<?php esc_attr_e( 'Hosting instructions copied.', 'wc-blacklist-manager' ); ?>" data-fallback="<?php esc_attr_e( 'Message selected. Use your browser copy command.', 'wc-blacklist-manager' ); ?>"><?php esc_html_e( 'Copy instructions for hosting', 'wc-blacklist-manager' ); ?></button><span class="yobm-schema-copy-feedback" role="status"><?php esc_html_e( 'No WP-CLI access? Send this message to your hosting provider.', 'wc-blacklist-manager' ); ?></span></div>
				<details class="yobm-schema-packet-details"><summary><?php esc_html_e( 'Preview the hosting message', 'wc-blacklist-manager' ); ?></summary><label class="screen-reader-text" for="yobm-schema-packet"><?php esc_html_e( 'Hosting support message', 'wc-blacklist-manager' ); ?></label><textarea id="yobm-schema-packet" readonly rows="12" spellcheck="false"><?php echo esc_textarea( $packet ); ?></textarea></details>
			<?php endif; ?>
		</details>
		<?php endif; ?>
	</div>
</details>
