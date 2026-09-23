<?php
/** Standalone administrator notification, never a Woo email wrapper. */
defined( 'ABSPATH' ) || exit;
// Fixed local artwork is attached by the scoped canonical transport only.
// Preserve sanitized footer content; keep links readable without head CSS.
$html_footer = preg_replace( '/<a\b/i', '<a style="color:#a4a7af;text-decoration:underline"', $view['footer'] );
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<style>
.ds-footer a { color:#a4a7af; }
@media screen and (max-width:480px) {
 .ds-page { padding:20px 16px !important; }
 .ds-content { padding:28px 22px !important; }
 .ds-logo-cell { width:52px !important; padding-right:14px !important; }
 .ds-logo { width:52px !important; height:52px !important; }
 .ds-brand { font-size:16px !important; line-height:22px !important; }
 .ds-subtitle { font-size:14px !important; line-height:20px !important; }
 .ds-heading { font-size:28px !important; line-height:34px !important; }
 .ds-evidence { padding:18px !important; font-size:16px !important; line-height:24px !important; }
}
</style></head>
<body style="margin:0;background:#111216;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#f6f7f9">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;background:#111216"><tr><td class="ds-page" align="center" style="padding:38px 16px">
<table class="ds-card" role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:672px;box-sizing:border-box;table-layout:fixed;background:#1a1b21;border:2px solid #2e3038;border-radius:26px">
<tr><td class="ds-content" valign="top" style="padding:40px 36px 36px;overflow-wrap:anywhere;word-break:break-word">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed"><tr>
<td class="ds-logo-cell" width="70" valign="middle" style="width:70px;padding-right:22px">
<img class="ds-logo" src="cid:wc-blacklist-dark-sentinel-logo" width="70" height="70" alt="Blacklist Manager logo" style="display:block;width:70px;height:70px;border:0;color:#f6f7f9;font-size:12px">
</td><td valign="top" style="padding-top:1px">
<p class="ds-brand" style="margin:0;font-size:20px;line-height:26px;font-weight:700">BLACKLIST MANAGER</p>
<p class="ds-subtitle" style="margin:4px 0 0;color:#a4a7af;font-size:15px;line-height:22px"><?php echo esc_html__( 'Security notification', 'wc-blacklist-manager' ); ?></p>
</td></tr></table>
<div class="ds-header-divider" style="margin-top:20px;border-top:2px solid #2e3038;font-size:0;line-height:0">&nbsp;</div>
<p class="ds-eyebrow" style="margin:34px 0 12px;color:#ff3b3b;font-size:14px;line-height:20px;font-weight:700"><?php echo esc_html( strtoupper( $view['severity'] ) ); ?></p>
<h1 class="ds-heading" style="margin:0;font-size:40px;line-height:48px;font-weight:700;color:#f6f7f9"><?php echo esc_html( $view['heading'] ); ?></h1>
<?php if ( $view['evidence'] ) : ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;margin-top:24px;border:1px solid #2e3038;border-radius:16px;background:#202127"><tr><td class="ds-evidence" style="padding:24px 25px;color:#a4a7af;font-size:17px;line-height:26px">
<?php foreach ( $view['evidence'] as $index => $line ) : ?><p style="margin:<?php echo $index ? '12px' : '0'; ?> 0 0"><?php echo esc_html( $line ); ?></p><?php endforeach; ?>
</td></tr></table>
<?php endif; ?>
<?php if ( $view['url'] ) : ?><p class="ds-action" style="margin:28px 0 0"><a href="<?php echo esc_url( $view['url'] ); ?>" style="display:inline-block;box-sizing:border-box;max-width:100%;background:#ff3b3b;color:#ffffff;padding:14px 28px;border-radius:10px;font-size:18px;line-height:24px;font-weight:700;text-decoration:none"><?php echo esc_html( $view['action_label'] ?? __( 'View order', 'wc-blacklist-manager' ) ); ?></a></p><?php endif; ?>
<div class="ds-footer" style="margin-top:34px;border-top:2px solid #2e3038;padding-top:22px;color:#a4a7af;font-size:13px;line-height:20px">
<?php if ( $view['timestamp'] ) : ?><p style="margin:0 0 12px"><?php echo esc_html( $view['timestamp'] ); ?></p><?php endif; ?>
<div><?php echo $html_footer; // Sanitized by the renderer; only a fixed link style is added. ?></div>
</div>
</td></tr></table></td></tr></table></body></html>
