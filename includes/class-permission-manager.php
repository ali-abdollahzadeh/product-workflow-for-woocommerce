<?php
defined('ABSPATH') || exit;

class PWF_Permission_Manager {
    public static function stage_caps() {
        return array(
            'photography' => 'complete_photography_task',
            'content'     => 'complete_content_task',
            'review'      => 'review_product',
            'social'      => 'complete_social_task',
            'programming' => 'complete_programming_task',
        );
    }

    /** Capability constants for the dual-entity model. */
    public static function task_caps() {
        return array(
            'create_request' => 'create_workflow_request',  // CEO, Secretary, Sales Manager
            'manage_tasks'   => 'manage_workflow',           // Management & Planning
            'assign_tasks'   => 'assign_tasks',              // Management & Planning
            'execute_task'   => 'execute_workflow_task',     // all workers
            'review_task'    => 'review_product',            // Management & Planning
            'executive_view' => 'view_executive_dashboard',  // CEO
        );
    }

    public static function install() {
        $common = array('read', 'view_assigned_products', 'view_workflow_history');

        // ── Legacy pipeline roles (kept for backward compatibility) ───────────────
        $legacy_roles = array(
            'pwf_factory'         => array('Factory (Secretary)', array('create_product_request', 'create_workflow_request')),
            'pwf_photographer'    => array('Photographer', array('upload_product_images', 'complete_photography_task', 'execute_workflow_task')),
            'pwf_content_manager' => array('Content Manager', array('edit_product_content', 'complete_content_task', 'execute_workflow_task')),
            'pwf_reviewer'        => array('Product Reviewer', array('review_product')),
            'pwf_social'          => array('Social Media', array('complete_social_task', 'execute_workflow_task')),
        );

        // ── New spec v2.1 roles ───────────────────────────────────────────────────
        $new_roles = array(
            'pwf_ceo'           => array('CEO / Factory Manager', array('view_executive_dashboard', 'create_workflow_request')),
            'pwf_sales_manager' => array('Sales Manager', array('create_workflow_request')),
            'pwf_management'    => array('Management & Planning', array(
                'manage_workflow', 'assign_tasks', 'review_product', 'publish_product',
                'create_product_request', 'create_workflow_request',
            )),
            'pwf_programmer'    => array('Programmer', array('complete_programming_task', 'execute_workflow_task')),
        );

        $all_roles = array_merge($legacy_roles, $new_roles);
        $all_caps  = array_merge($common, array('assign_tasks', 'manage_workflow', 'publish_product'));

        foreach ($all_roles as $slug => $definition) {
            $caps = array_merge($common, $definition[1]);
            if (!get_role($slug)) {
                add_role($slug, $definition[0], array_fill_keys($caps, true));
            }
            $role = get_role($slug);
            if ($role) {
                foreach ($caps as $cap) { $role->add_cap($cap); }
            }
            $all_caps = array_merge($all_caps, $caps);
        }

        // Do not grant broad WooCommerce editing or media-library access to workers.
        foreach (array('administrator', 'shop_manager') as $slug) {
            $role = get_role($slug);
            if ($role) {
                foreach (array_unique($all_caps) as $cap) { $role->add_cap($cap); }
            }
        }
    }

    public static function can_view($id) {
        $row = PWF_Workflow_Manager::get($id);
        if (!$row || !current_user_can('view_assigned_products')) { return false; }
        if (current_user_can('manage_workflow') || current_user_can('assign_tasks') || current_user_can('view_executive_dashboard')) { return true; }
        if ((int) $row->created_by === get_current_user_id() && current_user_can('create_product_request')) { return true; }
        foreach (PWF_Assignment_Manager::all($id) as $assignment) {
            if ((int) $assignment->user_id !== get_current_user_id()) { continue; }
            // Social-only users receive assets only at handoff, never before approval.
            if ($assignment->stage !== 'social' || in_array($row->current_status, array('ready_for_social', 'completed'), true)) { return true; }
        }
        return false;
    }

    public static function can_view_request($request_id) {
        global $wpdb;
        if (current_user_can('manage_workflow') || current_user_can('view_executive_dashboard')) { return true; }
        $req = $wpdb->get_row($wpdb->prepare("SELECT submitted_by FROM {$wpdb->prefix}pwf_requests WHERE request_id=%d", $request_id));
        if ($req && (int) $req->submitted_by === get_current_user_id()) { return true; }
        return false;
    }

    public static function can_view_task($task_id) {
        global $wpdb;
        if (current_user_can('manage_workflow') || current_user_can('view_executive_dashboard')) { return true; }
        $task = $wpdb->get_row($wpdb->prepare("SELECT assigned_to, created_by FROM {$wpdb->prefix}pwf_tasks WHERE task_id=%d", $task_id));
        if (!$task) { return false; }
        $uid = get_current_user_id();
        return ((int) $task->assigned_to === $uid || (int) $task->created_by === $uid);
    }

    public static function stage($id, $stage, $cap) {
        if (!current_user_can($cap)) { return false; }
        if (current_user_can('manage_workflow')) { return true; }
        $assignment = PWF_Assignment_Manager::get($id, $stage);
        return $assignment && (int) $assignment->user_id === get_current_user_id();
    }

    public static function boot() {
        add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
            if (!in_array($cap, array('edit_post', 'delete_post', 'read_post'), true) || empty($args[0])) { return $caps; }
            $id = (int) $args[0];
            if (get_post_type($id) !== 'product' || !PWF_Workflow_Manager::get($id)) { return $caps; }
            if ($cap !== 'read_post' && !user_can($user_id, 'manage_workflow')) { return array('do_not_allow'); }
            return $caps;
        }, 10, 4);
    }
}
