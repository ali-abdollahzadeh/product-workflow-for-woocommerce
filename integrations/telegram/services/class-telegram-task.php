<?php
defined('ABSPATH') || exit;

/**
 * PWF_Telegram_Task
 *
 * Microservice managing active task queries, trashed/deleted product exclusion,
 * orphan database cleanup, and workflow stage completions.
 */
class PWF_Telegram_Task {
    public static function get_user_tasks($wp_user) {
        global $wpdb;
        if (!$wp_user || !$wpdb) {
            return array();
        }

        $is_photographer = PWF_Telegram_Auth::is_photographer($wp_user);
        $is_content_mgr  = PWF_Telegram_Auth::is_content_manager($wp_user);
        $is_reviewer     = PWF_Telegram_Auth::is_reviewer($wp_user);
        $is_manager      = PWF_Telegram_Auth::is_manager($wp_user);

        // 1. Direct assignments
        $assignments = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, w.current_status FROM {$wpdb->prefix}pwf_assignments a JOIN {$wpdb->prefix}pwf_workflows w ON w.product_id=a.product_id WHERE a.user_id=%d AND a.completed_at IS NULL ORDER BY a.assignment_id DESC LIMIT 10",
            $wp_user->ID
        ));

        $assigned_product_ids = array();
        foreach ($assignments as $a) {
            $assigned_product_ids[] = (int) $a->product_id;
        }

        // 2. For photographers: also include products waiting for photography that are unassigned
        $extra_tasks = array();
        if ($is_photographer || $is_manager) {
            $photo_rows = (array) $wpdb->get_results(
                "SELECT w.product_id, w.current_status FROM {$wpdb->prefix}pwf_workflows w WHERE w.current_status IN ('new_product', 'waiting_photography') ORDER BY w.product_id DESC LIMIT 10"
            );
            foreach ($photo_rows as $pr) {
                if (!in_array((int) $pr->product_id, $assigned_product_ids, true)) {
                    $extra_tasks[] = (object) array(
                        'product_id'     => $pr->product_id,
                        'stage'          => 'photography',
                        'current_status' => $pr->current_status,
                        'due_date'       => null,
                    );
                    $assigned_product_ids[] = (int) $pr->product_id;
                }
            }
        }

        // 3. For content managers: also include products waiting for content that are unassigned
        if ($is_content_mgr || $is_manager) {
            $content_rows = (array) $wpdb->get_results(
                "SELECT w.product_id, w.current_status FROM {$wpdb->prefix}pwf_workflows w WHERE w.current_status = 'waiting_content' ORDER BY w.product_id DESC LIMIT 10"
            );
            foreach ($content_rows as $cr) {
                if (!in_array((int) $cr->product_id, $assigned_product_ids, true)) {
                    $extra_tasks[] = (object) array(
                        'product_id'     => $cr->product_id,
                        'stage'          => 'content',
                        'current_status' => $cr->current_status,
                        'due_date'       => null,
                    );
                    $assigned_product_ids[] = (int) $cr->product_id;
                }
            }
        }

        $all_tasks = array_merge($assignments, $extra_tasks);

        // Filter out deleted, trashed, or stale products
        $valid_tasks = array();
        foreach ($all_tasks as $task) {
            $pid = (int) $task->product_id;
            if ($pid <= 0) {
                continue;
            }

            // Verify product exists and is not trashed or auto-draft
            $post = function_exists('get_post') ? get_post($pid) : null;
            $p_status = function_exists('get_post_status') ? get_post_status($pid) : ($post ? $post->post_status : false);
            if (!$p_status || in_array($p_status, array('trash', 'auto-draft'), true)) {
                if ($wpdb) {
                    if (!$p_status) {
                        if (method_exists($wpdb, 'delete')) {
                            $wpdb->delete($wpdb->prefix . 'pwf_workflows', array('product_id' => $pid));
                            $wpdb->delete($wpdb->prefix . 'pwf_assignments', array('product_id' => $pid));
                        } else {
                            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}pwf_workflows WHERE product_id=%d", $pid));
                            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}pwf_assignments WHERE product_id=%d", $pid));
                        }
                    } else {
                        $wpdb->update($wpdb->prefix . 'pwf_assignments', array('completed_at' => current_time('mysql', true)), array('product_id' => $pid));
                    }
                }
                continue;
            }

            $prod = function_exists('wc_get_product') ? wc_get_product($pid) : null;
            if (!$prod) {
                continue;
            }

            // Verify that task stage matches current workflow status
            if ($task->stage === 'photography' && !in_array($task->current_status, array('new_product', 'waiting_photography'), true)) {
                continue;
            }
            if ($task->stage === 'content' && $task->current_status !== 'waiting_content') {
                continue;
            }
            if ($task->stage === 'review' && $task->current_status !== 'waiting_review') {
                continue;
            }
            if ($task->stage === 'social' && $task->current_status !== 'ready_for_social') {
                continue;
            }

            $valid_tasks[] = array('task' => $task, 'product' => $prod);
        }

        // 4. Granular execution tasks from pwf_tasks table (Programmer, Social, Print, Custom)
        $has_tasks_tbl = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}pwf_tasks'");
        if ($has_tasks_tbl) {
            $exec_tasks = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT t.* FROM {$wpdb->prefix}pwf_tasks t
                 WHERE t.assigned_to = %d AND t.status IN ('assigned', 'in_progress', 'needs_revision')
                 ORDER BY FIELD(t.priority, 'urgent', 'high', 'normal', 'low'), t.due_date ASC LIMIT 10",
                $wp_user->ID
            ));

            foreach ($exec_tasks as $et) {
                $valid_tasks[] = array(
                    'is_exec_task' => true,
                    'task'         => $et,
                );
            }
        }

        return $valid_tasks;
    }

    public static function build_tasks_view(array $valid_tasks, $wp_user) {
        $is_photographer = PWF_Telegram_Auth::is_photographer($wp_user);
        $is_content_mgr  = PWF_Telegram_Auth::is_content_manager($wp_user);
        $is_manager      = PWF_Telegram_Auth::is_manager($wp_user);
        $can_create      = PWF_Telegram_Auth::can_create_product($wp_user);

        $states_fa = array(
            'new_product'             => 'محصول جدید',
            'waiting_photography'     => 'در انتظار عکاسی',
            'photography_in_progress' => 'در حال عکاسی',
            'photography_completed'   => 'اتمام عکاسی',
            'waiting_content'         => 'در انتظار تولید محتوا',
            'content_in_progress'     => 'در حال تولید محتوا',
            'content_completed'       => 'اتمام تولید محتوا',
            'waiting_review'          => 'در انتظار بررسی و تایید',
            'approved'                => 'تایید شده',
            'published'               => 'منتشر شده',
        );

        $msg = "📋 <b>" . pwf_t('Your Active Tasks:') . "</b>\n\n";
        $custom_keyboard = array();

        foreach ($valid_tasks as $item) {
            if (!empty($item['is_exec_task'])) {
                $t = $item['task'];
                $tid = (int) $t->task_id;
                $type_label = class_exists('PWF_Task_Manager') ? (PWF_Task_Manager::types()[$t->task_type]['label'] ?? $t->task_type) : $t->task_type;
                $priority_label = class_exists('PWF_Task_Manager') ? (PWF_Task_Manager::priorities()[$t->priority] ?? $t->priority) : $t->priority;
                $status_label = class_exists('PWF_Task_Manager') ? (PWF_Task_Manager::statuses()[$t->status] ?? $t->status) : $t->status;

                $msg .= "📌 <b>وظیفه #{$tid}: " . esc_html($t->title) . "</b>\n";
                $msg .= "  🏷️ نوع: " . esc_html($type_label) . "\n";
                $msg .= "  ⚡ اولویت: " . esc_html($priority_label) . " | 📊 وضعیت: " . esc_html($status_label) . "\n";
                if (!empty($t->due_date)) {
                    $msg .= "  📅 مهلت سررسید: " . esc_html($t->due_date) . "\n";
                }
                $msg .= "  👁️ مشاهده جزئیات: /task_" . $tid . "\n";
                if ($t->status === 'assigned') {
                    $msg .= "  🚀 شروع کار: /start_task_" . $tid . "\n";
                    $custom_keyboard[] = array('👁️ جزئیات #' . $tid, '🚀 شروع کار #' . $tid);
                } else {
                    $msg .= "  📤 تحویل خروجی: /submit_task_" . $tid . "\n";
                    $custom_keyboard[] = array('👁️ جزئیات #' . $tid, '📤 تحویل خروجی #' . $tid);
                }
                $msg .= "\n";
                continue;
            }

            $task = $item['task'];
            $prod = $item['product'];
            $pid = (int) $task->product_id;
            $p_title = $prod ? $prod->get_name() : sprintf(pwf_t('Product #%d'), $pid);
            $stage_label = PWF_I18n::stage($task->stage);
            $status_label = $states_fa[$task->current_status] ?? $task->current_status;

            $msg .= "• <b>" . esc_html($p_title) . " (#" . $pid . ")</b>\n";
            $msg .= "  🎯 " . pwf_t('Stage') . ": " . esc_html($stage_label) . " (" . esc_html($status_label) . ")\n";
            if (!empty($task->due_date)) {
                $msg .= "  📅 " . pwf_t('Due date') . ": " . esc_html($task->due_date) . "\n";
            }

            if ($task->stage === 'photography' && ($is_photographer || $is_manager)) {
                $msg .= "  📸 <b>ارسال عکس:</b> /photo_" . $pid . "\n";
                $msg .= "  ✅ <b>تکمیل عکاسی:</b> /done_photo_" . $pid . "\n";
                $custom_keyboard[] = array('📸 ارسال عکس #' . $pid, '✅ تکمیل عکاسی #' . $pid);
            } elseif ($task->stage === 'content' && ($is_content_mgr || $is_manager)) {
                $msg .= "  📝 <b>مدیریت محتوا:</b> /content_" . $pid . "\n";
                $msg .= "  ✅ <b>تکمیل محتوا:</b> /finish_" . $pid . "\n";
                $custom_keyboard[] = array('📝 مدیریت محتوا #' . $pid, '✅ تکمیل محتوا #' . $pid);
            }
            $msg .= "\n";
        }

        if ($can_create) {
            $custom_keyboard[] = array('➕ ' . pwf_t('New Product'), '📋 ' . pwf_t('My Tasks'));
        } else {
            $custom_keyboard[] = array('📋 ' . pwf_t('My Tasks'), 'ℹ️ ' . pwf_t('Help'));
        }

        return array('message' => $msg, 'keyboard' => $custom_keyboard);
    }

    public static function complete_photography_stage($done_pid, $wp_user) {
        global $wpdb;

        if ($wpdb) {
            $wpdb->update($wpdb->prefix . 'pwf_assignments', array(
                'completed_at' => current_time('mysql', true),
            ), array('product_id' => $done_pid, 'stage' => 'photography'));

            $wpdb->update($wpdb->prefix . 'pwf_workflows', array(
                'current_status' => 'waiting_content',
                'updated_at'     => current_time('mysql', true),
            ), array('product_id' => $done_pid));
        }

        if (class_exists('PWF_History_Manager')) {
            PWF_History_Manager::record($done_pid, 'transition', 'waiting_photography', 'waiting_content', 'مرحله عکاسی توسط عکاس در تلگرام تایید و تکمیل شد.');
        }

        // Auto-assign and notify Content Managers
        $cms = PWF_Telegram_Auth::get_content_managers();
        if (!empty($cms) && $wpdb) {
            $cm = $cms[0];
            $existing_c = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pwf_assignments WHERE product_id=%d AND stage='content'", $done_pid));
            if (!$existing_c) {
                $wpdb->insert($wpdb->prefix . 'pwf_assignments', array(
                    'product_id'  => $done_pid,
                    'stage'       => 'content',
                    'user_id'     => $cm->ID,
                    'assigned_at' => current_time('mysql', true),
                ));
            }
        }

        return $cms;
    }

    public static function complete_content_stage($f_pid, $wp_user) {
        global $wpdb;

        if ($wpdb) {
            $wpdb->update($wpdb->prefix . 'pwf_assignments', array(
                'completed_at' => current_time('mysql', true),
            ), array('product_id' => $f_pid, 'stage' => 'content'));

            $wpdb->update($wpdb->prefix . 'pwf_workflows', array(
                'current_status' => 'waiting_review',
                'updated_at'     => current_time('mysql', true),
            ), array('product_id' => $f_pid));
        }

        if (class_exists('PWF_History_Manager')) {
            PWF_History_Manager::record($f_pid, 'transition', 'waiting_content', 'waiting_review', 'محتوای متنی توسط مدیر محتوا در تلگرام تکمیل شد.');
        }

        return true;
    }
}
