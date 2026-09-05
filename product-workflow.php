<?php
/**
 * Plugin Name: Product Workflow for WooCommerce
 * Description: Assigned photography, content, review and social tasks with an auditable product workflow.
 * Version: 1.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: Ali Abdollahzadeh
 * Author URI: https://aliabdollahzadeh.dev/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: product-workflow
 * Domain Path: /languages
 */
defined('ABSPATH') || exit;
define('PWF_VERSION', '1.2.0');
define('PWF_DIR', plugin_dir_path(__FILE__));
define('PWF_URL', plugin_dir_url(__FILE__));

// Declare WooCommerce HPOS compatibility.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('init', function () {
    load_plugin_textdomain('product-workflow', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

require_once PWF_DIR . 'includes/class-i18n.php';
PWF_I18n::boot();
foreach (array('permission', 'history', 'assignment', 'notification', 'workflow') as $module) {
    require_once PWF_DIR . 'includes/class-' . $module . '-manager.php';
}

function pwf_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $collate = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$wpdb->prefix}pwf_workflows (
        product_id bigint(20) unsigned NOT NULL,
        current_status varchar(40) NOT NULL DEFAULT 'new_product',
        created_by bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (product_id),
        KEY current_status (current_status)
    ) ENGINE=InnoDB $collate;");
    dbDelta("CREATE TABLE {$wpdb->prefix}pwf_assignments (
        assignment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        product_id bigint(20) unsigned NOT NULL,
        stage varchar(30) NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        assigned_at datetime NOT NULL,
        due_date date DEFAULT NULL,
        completed_at datetime DEFAULT NULL,
        PRIMARY KEY  (assignment_id),
        UNIQUE KEY product_stage (product_id,stage),
        KEY user_tasks (user_id,completed_at)
    ) ENGINE=InnoDB $collate;");
    dbDelta("CREATE TABLE {$wpdb->prefix}pwf_history (
        history_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        product_id bigint(20) unsigned NOT NULL,
        event varchar(40) NOT NULL,
        previous_status varchar(40) NOT NULL,
        new_status varchar(40) NOT NULL,
        changed_by bigint(20) unsigned NOT NULL,
        changed_at datetime NOT NULL,
        note text NOT NULL,
        PRIMARY KEY  (history_id),
        KEY product_history (product_id,history_id)
    ) ENGINE=InnoDB $collate;");
    PWF_Permission_Manager::install();
    update_option('pwf_version', PWF_VERSION);
}
register_activation_hook(__FILE__, 'pwf_install');
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo ('<div class="notice notice-error"><p>' . esc_html(pwf_t('Product Workflow requires WooCommerce to be active.')) . '</p></div>');
        });
        return;
    }
    if (get_option('pwf_version') !== PWF_VERSION) {
        pwf_install();
    }
    PWF_Permission_Manager::boot();
    PWF_Workflow_Manager::boot();
    require_once PWF_DIR . 'admin/dashboard.php';
    require_once PWF_DIR . 'admin/user-tasks.php';
    require_once PWF_DIR . 'admin/product-workflow-page.php';
    require_once PWF_DIR . 'admin/settings-page.php';
    require_once PWF_DIR . 'integrations/class-telegram.php';
    require_once PWF_DIR . 'api/class-rest-api.php';
    PWF_Admin::boot();
    PWF_Settings::boot();
    PWF_Telegram::boot();
    PWF_REST_API::boot();
});
