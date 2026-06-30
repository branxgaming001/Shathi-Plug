<?php
/**
 * Uninstall handler — cleans up all Sathi plugin data.
 *
 * @package SathiAgenticAI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ── Drop custom tables ───────────────────────────────────────────────
$tables = [
    'sathi_conversations',
    'sathi_messages',
    'sathi_memory_entries',
    'sathi_knowledge_chunks',
    'sathi_personas',
    'sathi_user_mascots',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}

// ── Delete all options ────────────────────────────────────────────────
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sathi_%'"
);

// ── Clear transients ──────────────────────────────────────────────────
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sathi_%' OR option_name LIKE '_transient_timeout_sathi_%'"
);

// ── Clean user meta ───────────────────────────────────────────────
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '%_sathi_%'" );

// ── Flush rewrite rules ───────────────────────────────────────────────
delete_option( 'rewrite_rules' );
