<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

// Explicit uninstall permanently removes only this plugin's known custom tables.
// Schema changes and direct queries are required by the WordPress uninstall hook.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

// Always remove the credential. Keep mappings by default so reinstalling never
// silently creates a second product catalogue.
delete_option('vilahub_api_secret');
delete_option('vilahub_oauth_tokens');
delete_option('vilahub_oauth_refresh_lock');
$vilahub_settings = (array) get_option('vilahub_settings', array());
$vilahub_settings['automatic'] = false;
$vilahub_settings['paused'] = true;
update_option('vilahub_settings', $vilahub_settings, false);
if (defined('VILAHUB_DELETE_DATA_ON_UNINSTALL') && VILAHUB_DELETE_DATA_ON_UNINSTALL === true) {
    global $wpdb;
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vilahub_sync');
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vilahub_publications');
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vilahub_orders');
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vilahub_order_records');
    foreach (array('vilahub_settings', 'vilahub_site_id', 'vilahub_site_origin', 'vilahub_db_version', 'vilahub_http_next', 'vilahub_batch', 'vilahub_last_batch', 'vilahub_order_accounts', 'vilahub_order_stock_authority', 'vilahub_order_migration_error') as $vilahub_option) { delete_option($vilahub_option); }
}
