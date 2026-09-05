<?php
defined('ABSPATH') || exit;

/**
 * PWF_Telegram_UI
 *
 * Microservice handling Telegram user interface presentation:
 * reply keyboards, role-based button filtering, and Persian UI templates.
 */
class PWF_Telegram_UI {
    public static function category_keyboard() {
        $keyboard = array();
        if (function_exists('get_terms')) {
            $terms = get_terms(array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'number'     => 30,
            ));
            if (!empty($terms) && !is_wp_error($terms)) {
                $row = array();
                foreach ($terms as $term) {
                    $row[] = '📁 ' . $term->name;
                    if (count($row) === 2) {
                        $keyboard[] = $row;
                        $row = array();
                    }
                }
                if (!empty($row)) {
                    $keyboard[] = $row;
                }
            }
        }
        $keyboard[] = array('/skip', '/cancel');
        return $keyboard;
    }

    public static function main_menu_keyboard($user = null) {
        if ($user === null) {
            $user = PWF_Telegram_Auth::get_current_user();
        } elseif (is_numeric($user)) {
            $user = function_exists('get_userdata') ? get_userdata((int) $user) : null;
        }

        $can_create = false;
        $is_photographer = false;
        $is_content_mgr = false;
        $is_reviewer = false;

        if ($user && is_object($user)) {
            $can_create = PWF_Telegram_Auth::can_create_product($user);
            $is_photographer = PWF_Telegram_Auth::is_photographer($user);
            $is_content_mgr  = PWF_Telegram_Auth::is_content_manager($user);
            $is_reviewer     = PWF_Telegram_Auth::is_reviewer($user);
        } else {
            // Default fallback if unknown user
            $can_create = true;
        }

        $tasks_label = '📋 ' . pwf_t('My Tasks');
        if ($is_photographer && !$can_create) {
            $tasks_label = '📸 وظایف عکاسی من';
        } elseif ($is_content_mgr && !$can_create) {
            $tasks_label = '📝 وظایف تولید محتوا';
        } elseif ($is_reviewer && !$can_create) {
            $tasks_label = '🔍 وظایف بررسی و تایید';
        }

        $keyboard = array();

        if ($can_create) {
            $keyboard[] = array('➕ ' . pwf_t('New Product'));
            $keyboard[] = array($tasks_label, 'ℹ️ ' . pwf_t('Help'));
        } else {
            $keyboard[] = array($tasks_label, 'ℹ️ ' . pwf_t('Help'));
        }

        return $keyboard;
    }

    public static function build_photographer_upload_keyboard() {
        return array(
            array('✅ تیک تکمیل و اتمام عکاسی'),
            array('/done', '❌ ' . pwf_t('Cancel')),
        );
    }

    public static function get_unlinked_user_message($from_id) {
        return "👋 <b>" . pwf_t('Welcome to Product Workflow!') . "</b>\n\n"
             . pwf_t('Your Telegram ID is:') . " <code>" . esc_html($from_id) . "</code>\n\n"
             . pwf_t('Your account is not linked to any WordPress user yet. Please ask your store administrator to set your Telegram Chat ID in Workflow Settings.');
    }

    public static function get_greeting_message($wp_user) {
        $can_create = PWF_Telegram_Auth::can_create_product($wp_user);
        $is_photographer = PWF_Telegram_Auth::is_photographer($wp_user);
        $is_content_mgr  = PWF_Telegram_Auth::is_content_manager($wp_user);
        $is_reviewer     = PWF_Telegram_Auth::is_reviewer($wp_user);

        if ($can_create) {
            return "👋 <b>" . sprintf(pwf_t('Hello %s!'), esc_html($wp_user->display_name)) . "</b>\n\n"
                 . pwf_t('You can add new products and manage your workflow tasks directly from Telegram.') . "\n\n"
                 . "<b>" . pwf_t('Commands:') . "</b>\n"
                 . "• /new — " . pwf_t('Create a new product request') . "\n"
                 . "• /tasks — " . pwf_t('View your active assigned tasks') . "\n"
                 . "• /cancel — " . pwf_t('Cancel the current action');
        } else {
            $role_title = 'همکار گرامی';
            if ($is_photographer) {
                $role_title = 'عکاس گرامی';
            } elseif ($is_content_mgr) {
                $role_title = 'مدیر محتوای گرامی';
            } elseif ($is_reviewer) {
                $role_title = 'بازبین و تاییدکننده گرامی';
            }

            return "👋 <b>" . sprintf(pwf_t('Hello %s!'), esc_html($wp_user->display_name)) . " ({$role_title})</b>\n\n"
                 . "شما می‌توانید وظایف محول‌شده به خود را مستقیماً از طریق این بات دریافت و مدیریت فرمایید.\n\n"
                 . "<b>دستورات در دسترس:</b>\n"
                 . "• /tasks — مشاهده لیست وظایف فعال شما\n"
                 . "• /cancel — انصراف از عملیات جاری";
        }
    }
}
