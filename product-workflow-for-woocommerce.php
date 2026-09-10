<?php
/**
 * Plugin Name: Product Workflow for WooCommerce
 * Description: Dual-entity Request & Task workflow engine — intake, QA loop, Telegram alerts, and WooCommerce publishing.
 * Version: 2.3.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: Ali Abdollahzadeh
 * Author URI: https://aliabdollahzadeh.dev/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: product-workflow-for-woocommerce
 * Domain Path: /languages
 */
defined('ABSPATH') || exit;
define('PWF_VERSION', '2.3.0');
define('PWF_DIR', plugin_dir_path(__FILE__));
define('PWF_URL', plugin_dir_url(__FILE__));

// Declare WooCommerce HPOS compatibility.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('init', function () {
    load_plugin_textdomain('product-workflow-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

require_once PWF_DIR . 'includes/class-i18n.php';
PWF_I18n::boot();
foreach (array('permission', 'history', 'assignment', 'notification', 'workflow', 'request', 'task') as $module) {
    require_once PWF_DIR . 'includes/class-' . $module . '-manager.php';
}

function pwf_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $collate = $wpdb->get_charset_collate();

    // ── Legacy product pipeline tables (kept for backward compatibility) ────────
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

    // ── New dual-entity tables (v2.0.0) ─────────────────────────────────────────
    // Requests: high-level intake from CEO / Factory Secretary / Sales Manager
    dbDelta("CREATE TABLE {$wpdb->prefix}pwf_requests (
        request_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL DEFAULT '',
        description text NOT NULL,
        request_type varchar(60) NOT NULL DEFAULT 'general',
        priority varchar(20) NOT NULL DEFAULT 'normal',
        status varchar(40) NOT NULL DEFAULT 'submitted',
        product_id bigint(20) unsigned DEFAULT NULL,
        attachments longtext DEFAULT NULL,
        submitted_by bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (request_id),
        KEY status (status),
        KEY submitted_by (submitted_by),
        KEY product_id (product_id)
    ) ENGINE=InnoDB $collate;");

    // Tasks: granular execution units (can be standalone or linked to a request + optional product)
    dbDelta("CREATE TABLE {$wpdb->prefix}pwf_tasks (
        task_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        request_id bigint(20) unsigned DEFAULT NULL,
        product_id bigint(20) unsigned DEFAULT NULL,
        task_type varchar(60) NOT NULL DEFAULT 'custom',
        title varchar(255) NOT NULL DEFAULT '',
        description text NOT NULL,
        assigned_to bigint(20) unsigned DEFAULT NULL,
        priority varchar(20) NOT NULL DEFAULT 'normal',
        status varchar(40) NOT NULL DEFAULT 'assigned',
        due_date date DEFAULT NULL,
        deliverable_url varchar(2048) NOT NULL DEFAULT '',
        deliverable_note text NOT NULL,
        attachments longtext DEFAULT NULL,
        deliverable_files longtext DEFAULT NULL,
        self_closing tinyint(1) NOT NULL DEFAULT 0,
        created_by bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (task_id),
        KEY request_id (request_id),
        KEY product_id (product_id),
        KEY assigned_to (assigned_to),
        KEY status (status),
        KEY due_date (due_date)
    ) ENGINE=InnoDB $collate;");

    // Task audit log (separate from legacy pwf_history)
    dbDelta("CREATE TABLE {$wpdb->prefix}pwf_task_history (
        log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        task_id bigint(20) unsigned NOT NULL,
        event varchar(60) NOT NULL,
        previous_status varchar(40) NOT NULL DEFAULT '',
        new_status varchar(40) NOT NULL DEFAULT '',
        changed_by bigint(20) unsigned NOT NULL,
        changed_at datetime NOT NULL,
        note text NOT NULL,
        PRIMARY KEY  (log_id),
        KEY task_log (task_id,log_id)
    ) ENGINE=InnoDB $collate;");

    // Request audit log
    dbDelta("CREATE TABLE {$wpdb->prefix}pwf_request_history (
        log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        request_id bigint(20) unsigned NOT NULL,
        event varchar(60) NOT NULL,
        previous_status varchar(40) NOT NULL DEFAULT '',
        new_status varchar(40) NOT NULL DEFAULT '',
        changed_by bigint(20) unsigned NOT NULL,
        changed_at datetime NOT NULL,
        note text NOT NULL,
        PRIMARY KEY  (log_id),
        KEY request_log (request_id,log_id)
    ) ENGINE=InnoDB $collate;");

    // Runtime column migration check for existing installations
    $task_tbl = $wpdb->prefix . 'pwf_tasks';
    $cols = $wpdb->get_col("DESC `{$task_tbl}`");
    if (!empty($cols) && is_array($cols)) {
        if (!in_array('attachments', $cols, true)) {
            $wpdb->query("ALTER TABLE `{$task_tbl}` ADD `attachments` LONGTEXT DEFAULT NULL");
        }
        if (!in_array('deliverable_files', $cols, true)) {
            $wpdb->query("ALTER TABLE `{$task_tbl}` ADD `deliverable_files` LONGTEXT DEFAULT NULL");
        }
    }

    $req_tbl = $wpdb->prefix . 'pwf_requests';
    $r_cols = $wpdb->get_col("DESC `{$req_tbl}`");
    if (!empty($r_cols) && is_array($r_cols)) {
        if (!in_array('attachments', $r_cols, true)) {
            $wpdb->query("ALTER TABLE `{$req_tbl}` ADD `attachments` LONGTEXT DEFAULT NULL");
        }
    }

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
    require_once PWF_DIR . 'admin/requests-page.php';
    require_once PWF_DIR . 'admin/task-detail-page.php';
    require_once PWF_DIR . 'admin/help-page.php';
    require_once PWF_DIR . 'integrations/class-telegram.php';
    require_once PWF_DIR . 'api/class-rest-api.php';
    PWF_Admin::boot();
    PWF_Settings::boot();
    PWF_Telegram::boot();
    PWF_REST_API::boot();
    PWF_Help_Page::boot();
    // Schedule deadline warning cron if not already scheduled
    if (!wp_next_scheduled('pwf_deadline_warnings')) {
        wp_schedule_event(time(), 'hourly', 'pwf_deadline_warnings');
    }
    add_action('pwf_deadline_warnings', 'pwf_send_deadline_warnings');
});

/**
 * Sends 24h and 6h deadline warning notifications for active tasks.
 */
function pwf_send_deadline_warnings() {
    global $wpdb;
    $now = current_time('mysql', true);
    $warning_windows = array(
        '24h' => array('min' => 23, 'max' => 25),
        '6h'  => array('min' => 5,  'max' => 7),
    );
    foreach ($warning_windows as $label => $window) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, u.display_name FROM {$wpdb->prefix}pwf_tasks t
             LEFT JOIN {$wpdb->users} u ON u.ID = t.assigned_to
             WHERE t.status IN ('assigned','in_progress','needs_revision')
               AND t.due_date IS NOT NULL
               AND TIMESTAMPDIFF(HOUR, %s, CONCAT(t.due_date, ' 23:59:59')) BETWEEN %d AND %d",
            $now, $window['min'], $window['max']
        ));
        foreach ((array) $rows as $task) {
            if (empty($task->assigned_to)) { continue; }
            $chat_id = get_user_meta($task->assigned_to, 'pwf_telegram_chat_id', true);
            if ($chat_id && PWF_Telegram::is_enabled()) {
                $msg = sprintf(
                    pwf_t("⏰ <b>Deadline Warning (%s)</b>\nTask: %s\nDue: %s\nStatus: %s"),
                    strtoupper($label),
                    $task->title,
                    $task->due_date,
                    $task->status
                );
                PWF_Telegram::send_message($chat_id, $msg);
            }
        }
    }
}

