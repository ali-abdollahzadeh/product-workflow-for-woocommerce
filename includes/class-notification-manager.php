<?php
defined('ABSPATH') || exit;

class PWF_Notification_Manager {
    public static function dispatch($id, $event) {
        // Invoked only after commit. An integration outage cannot undo workflow work.
        try {
            do_action('pwf_workflow_event', $event['event'] ?? 'updated', $id, $event);
        } catch (Throwable $error) {
            error_log('Product Workflow notification listener failed for product ' . (int) $id);
        }
    }
}
