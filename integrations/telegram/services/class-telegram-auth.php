<?php
defined('ABSPATH') || exit;

/**
 * PWF_Telegram_Auth
 *
 * Microservice handling Telegram user identification, WordPress account linking,
 * role resolution, and capability checks.
 */
class PWF_Telegram_Auth {
    private static $current_webhook_user = null;

    public static function get_current_user() {
        return self::$current_webhook_user;
    }

    public static function set_current_user($user) {
        self::$current_webhook_user = $user;
    }

    public static function get_user_by_telegram_id($telegram_id) {
        if (empty($telegram_id)) {
            return null;
        }
        $users = get_users(array(
            'meta_key'   => 'pwf_telegram_chat_id',
            'meta_value' => (string) $telegram_id,
            'number'     => 1,
        ));
        return !empty($users) ? $users[0] : null;
    }

    public static function get_photographers() {
        if (!function_exists('get_users')) {
            return array();
        }

        $all = array();

        // 1. Query users with role 'pwf_photographer'
        $by_role = get_users(array(
            'role' => 'pwf_photographer',
        ));
        foreach ((array) $by_role as $u) {
            if ($u && isset($u->ID)) {
                $all[$u->ID] = $u;
            }
        }

        // 2. Query users with telegram chat id set who have photographer capability
        $with_chat_id = get_users(array(
            'meta_key' => 'pwf_telegram_chat_id',
        ));
        foreach ((array) $with_chat_id as $u) {
            if (!$u || !isset($u->ID)) {
                continue;
            }
            if (isset($all[$u->ID])) {
                continue;
            }
            $roles = (array) ($u->roles ?? array());
            if (in_array('pwf_photographer', $roles, true)) {
                $all[$u->ID] = $u;
            } elseif (function_exists('user_can') && user_can($u, 'upload_product_images')) {
                $all[$u->ID] = $u;
            }
        }

        return array_values($all);
    }

    public static function get_content_managers() {
        if (!function_exists('get_users')) {
            return array();
        }

        $all = array();

        // 1. Query users with role 'pwf_content_manager'
        $by_role = get_users(array(
            'role' => 'pwf_content_manager',
        ));
        foreach ((array) $by_role as $u) {
            if ($u && isset($u->ID)) {
                $all[$u->ID] = $u;
            }
        }

        // 2. Query users with telegram chat id set who have content capability
        $with_chat_id = get_users(array(
            'meta_key' => 'pwf_telegram_chat_id',
        ));
        foreach ((array) $with_chat_id as $u) {
            if (!$u || !isset($u->ID)) {
                continue;
            }
            if (isset($all[$u->ID])) {
                continue;
            }
            $roles = (array) ($u->roles ?? array());
            if (in_array('pwf_content_manager', $roles, true)) {
                $all[$u->ID] = $u;
            } elseif (function_exists('user_can') && (user_can($u, 'edit_product_content') || user_can($u, 'complete_content_task'))) {
                $all[$u->ID] = $u;
            }
        }

        return array_values($all);
    }

    public static function can_create_product($user) {
        if (!$user || !is_object($user)) {
            return false;
        }
        $uid = $user->ID ?? 0;
        $roles = (array) ($user->roles ?? array());
        return (function_exists('user_can') && user_can($uid, 'create_product_request'))
            || (function_exists('user_can') && user_can($uid, 'manage_workflow'))
            || in_array('administrator', $roles, true);
    }

    public static function is_photographer($user) {
        if (!$user || !is_object($user)) {
            return false;
        }
        $uid = $user->ID ?? 0;
        $roles = (array) ($user->roles ?? array());
        return in_array('pwf_photographer', $roles, true)
            || (function_exists('user_can') && user_can($uid, 'upload_product_images'));
    }

    public static function is_content_manager($user) {
        if (!$user || !is_object($user)) {
            return false;
        }
        $uid = $user->ID ?? 0;
        $roles = (array) ($user->roles ?? array());
        return in_array('pwf_content_manager', $roles, true)
            || (function_exists('user_can') && user_can($uid, 'edit_product_content'));
    }

    public static function is_reviewer($user) {
        if (!$user || !is_object($user)) {
            return false;
        }
        $uid = $user->ID ?? 0;
        $roles = (array) ($user->roles ?? array());
        return in_array('pwf_reviewer', $roles, true)
            || (function_exists('user_can') && user_can($uid, 'review_product'));
    }

    public static function is_manager($user) {
        if (!$user || !is_object($user)) {
            return false;
        }
        $uid = $user->ID ?? 0;
        $roles = (array) ($user->roles ?? array());
        return (function_exists('user_can') && user_can($uid, 'manage_workflow'))
            || in_array('administrator', $roles, true);
    }
}
