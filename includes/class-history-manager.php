<?php
defined('ABSPATH') || exit;

class PWF_History_Manager {
    public static function record($id, $event, $from, $to, $note = '') {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'pwf_history', array(
            'product_id' => $id, 'event' => $event, 'previous_status' => $from,
            'new_status' => $to, 'changed_by' => get_current_user_id(),
            'changed_at' => current_time('mysql', true), 'note' => sanitize_textarea_field($note),
        ));
        if (!$ok) { throw new RuntimeException(pwf_t('The audit record could not be saved. Please retry.')); }
    }

    public static function recent($id, $page = 1) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pwf_history WHERE product_id=%d ORDER BY history_id DESC LIMIT 50 OFFSET %d",
            $id, (max(1, $page) - 1) * 50
        ));
    }
}
