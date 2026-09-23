<?php
/** Explicit text representation of the same safe view. */
defined( 'ABSPATH' ) || exit;
echo "BLACKLIST MANAGER\n\n" . $view['heading'] . "\n" . strtoupper( $view['severity'] ) . "\n\n";
echo implode( "\n", $view['lines'] ) . "\n\n";
if ( $view['url'] ) { echo ( $view['action_label'] ?? __( 'View order', 'wc-blacklist-manager' ) ) . ': ' . $view['url'] . "\n\n"; }
echo html_entity_decode( wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $view['footer'] ) ), ENT_QUOTES, 'UTF-8' ) . "\n";
