<?php
/**
 * Product Workflow for WooCommerce Uninstall Handler
 *
 * Fired when the plugin is deleted via the WordPress Admin Plugins screen.
 *
 * @package Product_Workflow
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Only clean up database tables and options if the site admin explicitly opted in.
// By default, store product data and audit history are safely preserved.
if (get_option('pwf_delete_data_on_uninstall', false)) {
    global $wpdb;

    // Drop custom workflow tables.
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}pwf_history");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}pwf_assignments");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}pwf_workflows");

    // Clean up plugin options.
    delete_option('pwf_version');
    delete_option('pwf_default_language');
    delete_option('pwf_telegram_enabled');
    delete_option('pwf_telegram_bot_token');
    delete_option('pwf_telegram_default_chat_id');
    delete_option('pwf_telegram_webhook_secret');
    delete_option('pwf_telegram_events');
    delete_option('pwf_delete_data_on_uninstall');
}
