<?php
defined('ABSPATH') || exit;

class PWF_Permission_Manager {
    public static function stage_caps() {
        return array('photography' => 'complete_photography_task', 'content' => 'complete_content_task', 'review' => 'review_product', 'social' => 'complete_social_task');
    }

    public static function install() {
        $common = array('read', 'view_assigned_products', 'view_workflow_history');
        $roles = array(
            'pwf_factory' => array('Factory', array('create_product_request')),
            'pwf_photographer' => array('Photographer', array('upload_product_images', 'complete_photography_task')),
            'pwf_content_manager' => array('Content Manager', array('edit_product_content', 'complete_content_task')),
            'pwf_reviewer' => array('Product Reviewer', array('review_product')),
            'pwf_social' => array('Social Media', array('complete_social_task')),
        );
        $all = array_merge($common, array('assign_tasks', 'manage_workflow', 'publish_product'));
        foreach ($roles as $slug => $definition) {
            $caps = array_merge($common, $definition[1]);
            add_role($slug, $definition[0], array_fill_keys($caps, true));
            $role = get_role($slug);
            if ($role) {
                foreach ($caps as $cap) { $role->add_cap($cap); }
            }
            $all = array_merge($all, $caps);
        }
        // Do not grant broad WooCommerce editing or media-library access to workers.
        foreach (array('administrator', 'shop_manager') as $slug) {
            $role = get_role($slug);
            if ($role) {
                foreach (array_unique($all) as $cap) { $role->add_cap($cap); }
            }
        }
    }

    public static function can_view($id) {
        $row = PWF_Workflow_Manager::get($id);
        if (!$row || !current_user_can('view_assigned_products')) { return false; }
        if (current_user_can('manage_workflow') || current_user_can('assign_tasks')) { return true; }
        if ((int) $row->created_by === get_current_user_id() && current_user_can('create_product_request')) { return true; }
        foreach (PWF_Assignment_Manager::all($id) as $assignment) {
            if ((int) $assignment->user_id !== get_current_user_id()) { continue; }
            // Social-only users receive assets only at handoff, never before approval.
            if ($assignment->stage !== 'social' || in_array($row->current_status, array('ready_for_social', 'completed'), true)) { return true; }
        }
        return false;
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
