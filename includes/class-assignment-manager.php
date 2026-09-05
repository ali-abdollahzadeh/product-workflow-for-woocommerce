<?php
defined('ABSPATH') || exit;

class PWF_Assignment_Manager {
    public static function get($id, $stage) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pwf_assignments WHERE product_id=%d AND stage=%s", $id, $stage));
    }

    public static function all($id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pwf_assignments WHERE product_id=%d ORDER BY assignment_id", $id));
    }

    public static function assign($id, $stage, $user_id, $due = '') {
        if (!current_user_can('assign_tasks')) { return new WP_Error('forbidden', pwf_t('You cannot assign tasks.'), array('status' => 403)); }
        $caps = PWF_Permission_Manager::stage_caps();
        if (!isset($caps[$stage]) || !$user_id || !user_can($user_id, $caps[$stage]) || !user_can($user_id, 'view_assigned_products')) {
            return new WP_Error('invalid_assignee', pwf_t('Choose an eligible user for this stage.'), array('status' => 400));
        }
        if ($due && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) || gmdate('Y-m-d', strtotime($due . ' 00:00:00 UTC')) !== $due)) {
            return new WP_Error('invalid_date', pwf_t('Due date must be a valid YYYY-MM-DD date.'), array('status' => 400));
        }
        return PWF_Workflow_Manager::mutate($id, function ($row) use ($id, $stage, $user_id, $due) {
            global $wpdb;
            if ($row->current_status === 'completed') { throw new RuntimeException(pwf_t('Completed workflows cannot be reassigned.')); }
            $old = self::get($id, $stage);
            $data = array('product_id' => $id, 'stage' => $stage, 'user_id' => $user_id, 'assigned_at' => current_time('mysql', true), 'due_date' => $due ?: null, 'completed_at' => null);
            $ok = $old ? $wpdb->update($wpdb->prefix . 'pwf_assignments', $data, array('assignment_id' => $old->assignment_id)) : $wpdb->insert($wpdb->prefix . 'pwf_assignments', $data);
            if ($ok === false) { throw new RuntimeException(pwf_t('Assignment could not be saved.')); }
            PWF_History_Manager::record($id, 'assignment', $row->current_status, $row->current_status, sprintf('%s assigned to user #%d (previous: #%d). Due: %s.', $stage, $user_id, $old ? $old->user_id : 0, $due ?: 'none'));
            return array('event' => 'task_assigned', 'stage' => $stage, 'user_id' => $user_id, 'due_date' => $due ?: null);
        });
    }
}
