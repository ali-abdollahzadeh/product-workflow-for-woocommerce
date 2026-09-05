<?php
defined('ABSPATH') || exit;

/**
 * PWF_Telegram_Notifier
 *
 * Microservice handling Persian message formatting, channel broadcasting,
 * and personnel notifications (photographers, content managers, assignees).
 */
class PWF_Telegram_Notifier {
    public static function format_persian_channel_message($event_name, $product_id, $event_data, $recipient_type = 'channel') {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $product_name = $product ? $product->get_name() : sprintf('محصول #%d', $product_id);
        $sku = $product ? $product->get_sku() : '';
        $link = admin_url('admin.php?page=pwf-product&product_id=' . absint($product_id));

        // Categories
        $cat_names = array();
        if ($product && function_exists('get_term')) {
            $cat_ids = (array) $product->get_category_ids();
            foreach ($cat_ids as $cid) {
                $term = get_term($cid, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    $cat_names[] = $term->name;
                }
            }
        }
        $category_str = !empty($cat_names) ? implode('، ', $cat_names) : 'تعیین نشده';

        // Attributes (تعداد در کارتن & CBM)
        $carton_qty = '';
        $cbm = '';
        if (function_exists('get_post_meta')) {
            $carton_qty = get_post_meta($product_id, '_pwf_carton_qty', true) ?: get_post_meta($product_id, 'carton_qty', true);
            $cbm = get_post_meta($product_id, '_pwf_cbm', true) ?: get_post_meta($product_id, 'cbm', true);
        }
        if ($product) {
            if (empty($carton_qty) && method_exists($product, 'get_attribute')) {
                $carton_qty = $product->get_attribute('تعداد در کارتن');
            }
            if (empty($cbm) && method_exists($product, 'get_attribute')) {
                $cbm = $product->get_attribute('CBM') ?: $product->get_attribute('cbm');
            }
        }

        // Persian state names mapping
        $states_fa = array(
            'new_product'             => 'محصول جدید',
            'waiting_photography'     => 'در انتظار عکاسی',
            'photography_in_progress' => 'در حال عکاسی',
            'photography_completed'   => 'اتمام عکاسی',
            'waiting_content'         => 'در انتظار تولید محتوا',
            'content_in_progress'     => 'در حال تولید محتوا',
            'content_completed'       => 'اتمام تولید محتوا',
            'waiting_review'          => 'در انتظار بررسی و تایید',
            'approved'                => 'تایید شده (آماده انتشار)',
            'published'               => 'منتشر شده در سایت',
            'ready_for_social'        => 'آماده برای شبکه‌های اجتماعی',
            'completed'               => 'فرآیند تکمیل شده',
            'on_hold'                 => 'متوقف شده (Hold)',
            'rejected'                => 'رد شده / نیازمند اصلاح',
        );

        // Persian stages mapping
        $stages_fa = array(
            'photography' => 'عکاسی',
            'content'     => 'تولید محتوا',
            'review'      => 'بررسی و تایید',
            'social'      => 'شبکه‌های اجتماعی',
        );

        if ($recipient_type === 'photographer') {
            $msg = "📸 <b>[اطلاعیه عکاسی محصول جدید]</b>\n";
        } elseif ($recipient_type === 'content_manager') {
            $msg = "📝 <b>[تخصیص وظیفه جدید به مدیر محتوا]</b>\n";
        } elseif ($recipient_type === 'assignee') {
            $msg = "📋 <b>[اطلاعیه وظیفه جدید]</b>\n";
        } else {
            $msg = "📢 <b>[گزارش فرآیند محصول ووکامرس]</b>\n";
        }
        $msg .= "━━━━━━━━━━━━━━━━━━\n";
        $msg .= "📦 <b>نام محصول:</b> " . esc_html($product_name) . "\n";
        if (!empty($sku)) {
            $msg .= "🔖 <b>کد محصول (SKU):</b> <code>" . esc_html($sku) . "</code>\n";
        }
        $msg .= "📁 <b>دسته‌بندی:</b> " . esc_html($category_str) . "\n";
        if (!empty($carton_qty)) {
            $msg .= "📦 <b>تعداد در کارتن:</b> " . esc_html($carton_qty) . "\n";
        }
        if (!empty($cbm)) {
            $msg .= "📐 <b>حجم کارتن (CBM):</b> " . esc_html($cbm) . "\n";
        }
        $msg .= "━━━━━━━━━━━━━━━━━━\n";

        switch ($event_name) {
            case 'created':
                $creator_id = absint($event_data['user_id'] ?? get_current_user_id());
                $creator = function_exists('get_userdata') ? get_userdata($creator_id) : null;
                $creator_name = $creator ? $creator->display_name : ('#' . $creator_id);

                if ($recipient_type === 'photographer') {
                    $msg .= "🆕 <b>محصول جدید ثبت شد و در صف عکاسی قرار گرفت</b>\n";
                    $msg .= "👤 <b>ثبت‌کننده:</b> " . esc_html($creator_name) . "\n";
                    $msg .= "📌 <b>وضعیت فعلی:</b> در انتظار عکاسی\n";
                    $msg .= "📸 <b>عکاس گرامی:</b> لطفاً جهت هماهنگی و عکاسی از محصول اقدام فرمایید.\n";
                } else {
                    $msg .= "🆕 <b>محصول جدید به سیستم اضافه شد</b>\n";
                    $msg .= "👤 <b>ثبت‌کننده:</b> " . esc_html($creator_name) . "\n";
                    $msg .= "📌 <b>وضعیت فعلی:</b> محصول جدید (در صف گردش کار)\n";
                }
                break;

            case 'task_assigned':
                $stage = sanitize_key($event_data['stage'] ?? '');
                $user_id = absint($event_data['user_id'] ?? 0);
                $user = function_exists('get_userdata') ? get_userdata($user_id) : null;
                $user_name = $user ? $user->display_name : ('#' . $user_id);
                $stage_label = $stages_fa[$stage] ?? (function_exists('pwf_t') ? PWF_I18n::stage($stage) : $stage);
                $due_date = $event_data['due_date'] ?? '';

                if ($recipient_type === 'assignee' || $recipient_type === 'photographer') {
                    $msg .= "📋 <b>یک وظیفه جدید به شما محول شد!</b>\n";
                } else {
                    $msg .= "📋 <b>تخصیص وظیفه جدید</b>\n";
                }
                $msg .= "🎯 <b>مرحله:</b> " . esc_html($stage_label) . "\n";
                $msg .= "👤 <b>مسئول انجام:</b> " . esc_html($user_name) . "\n";
                if (!empty($due_date)) {
                    $msg .= "📅 <b>مهلت انجام:</b> " . esc_html($due_date) . "\n";
                }
                break;

            case 'status_changed':
                $from = sanitize_key($event_data['from'] ?? '');
                $to = sanitize_key($event_data['to'] ?? '');
                $actor_id = absint($event_data['actor'] ?? get_current_user_id());
                $actor = function_exists('get_userdata') ? get_userdata($actor_id) : null;
                $actor_name = $actor ? $actor->display_name : ('#' . $actor_id);

                $states = class_exists('PWF_Workflow_Manager') ? PWF_Workflow_Manager::states() : array();
                $from_label = $states_fa[$from] ?? ($states[$from] ?? $from);
                $to_label = $states_fa[$to] ?? ($states[$to] ?? $to);

                if ($recipient_type === 'photographer' && $to === 'waiting_photography') {
                    $msg .= "📸 <b>محصول آماده عکاسی شد</b>\n";
                    $msg .= "📊 " . esc_html($from_label) . " ➔ <b>" . esc_html($to_label) . "</b>\n";
                    $msg .= "📸 <b>عکاس گرامی:</b> این محصول در صف عکاسی قرار گرفت. لطفاً اقدام فرمایید.\n";
                } elseif ($recipient_type === 'content_manager' && $to === 'waiting_content') {
                    $msg .= "📝 <b>عکاسی به اتمام رسید و محصول به مرحله تولید محتوا وارد شد</b>\n";
                    $msg .= "📊 " . esc_html($from_label) . " ➔ <b>" . esc_html($to_label) . "</b>\n";
                    $msg .= "📝 <b>مدیر محتوای گرامی:</b> تصاویر محصول تایید شدند. لطفاً نسبت به نگارش توضیحات و تعیین قیمت اقدام فرمایید.\n";
                } else {
                    $msg .= "🔄 <b>تغییر مرحله / وضعیت</b>\n";
                    $msg .= "📊 " . esc_html($from_label) . " ➔ <b>" . esc_html($to_label) . "</b>\n";
                }
                if ($actor_name) {
                    $msg .= "👤 <b>توسط:</b> " . esc_html($actor_name) . "\n";
                }
                if (!empty($event_data['note'])) {
                    $msg .= "💬 <b>توضیحات / علت:</b> <i>" . esc_html($event_data['note']) . "</i>\n";
                }
                break;

            case 'content_saved':
                $actor_id = absint($event_data['user_id'] ?? get_current_user_id());
                $actor = function_exists('get_userdata') ? get_userdata($actor_id) : null;
                $actor_name = $actor ? $actor->display_name : '';
                $msg .= "📝 <b>محتوای متنی محصول ذخیره شد</b>\n";
                if ($actor_name) {
                    $msg .= "👤 <b>نویسنده:</b> " . esc_html($actor_name) . "\n";
                }
                break;

            case 'images_saved':
                $actor_id = absint($event_data['user_id'] ?? get_current_user_id());
                $actor = function_exists('get_userdata') ? get_userdata($actor_id) : null;
                $actor_name = $actor ? $actor->display_name : '';
                $msg .= "📸 <b>تصاویر محصول تایید و ذخیره شد</b>\n";
                if ($actor_name) {
                    $msg .= "👤 <b>عکاس:</b> " . esc_html($actor_name) . "\n";
                }
                break;

            case 'image_uploaded':
                $msg .= "🖼️ <b>تصویر جدید به محصول پیوست شد</b>\n";
                break;

            default:
                $msg .= "ℹ️ <b>رویداد:</b> " . esc_html($event_name) . "\n";
                break;
        }

        $msg .= "━━━━━━━━━━━━━━━━━━\n";
        $msg .= "🔗 <a href=\"" . esc_url($link) . "\">مشاهده محصول در پنل مدیریت</a>";

        return $msg;
    }

    public static function handle_event($event_name, $product_id, $event_data) {
        if (!PWF_Telegram_Client::is_configured()) {
            return;
        }

        if (!empty($event_data['silent'])) {
            return;
        }

        $enabled_events = PWF_Telegram_Client::get_events();
        if (!in_array($event_name, $enabled_events, true)) {
            return;
        }

        try {
            // 1. Channel broadcast (in Persian)
            $default_chat = PWF_Telegram_Client::get_default_chat_id();
            if (!empty($default_chat)) {
                $channel_msg = self::format_persian_channel_message($event_name, $product_id, $event_data, 'channel');
                PWF_Telegram_Client::send_message($default_chat, $channel_msg);
            }

            // 2. Direct notification to photographers when a new product is created
            if ($event_name === 'created') {
                $photographers = PWF_Telegram_Auth::get_photographers();
                foreach ($photographers as $photographer) {
                    $photo_chat_id = get_user_meta($photographer->ID, 'pwf_telegram_chat_id', true);
                    if (!empty($photo_chat_id) && (string) $photo_chat_id !== (string) $default_chat) {
                        $photo_msg = self::format_persian_channel_message($event_name, $product_id, $event_data, 'photographer');
                        PWF_Telegram_Client::send_message($photo_chat_id, $photo_msg);
                    }
                }
            }

            // 3. Direct notification when status transitions to waiting_photography or waiting_content
            if ($event_name === 'status_changed') {
                $to = sanitize_key($event_data['to'] ?? '');
                if ($to === 'waiting_photography') {
                    $photographers = PWF_Telegram_Auth::get_photographers();
                    foreach ($photographers as $photographer) {
                        $photo_chat_id = get_user_meta($photographer->ID, 'pwf_telegram_chat_id', true);
                        if (!empty($photo_chat_id) && (string) $photo_chat_id !== (string) $default_chat) {
                            $photo_msg = self::format_persian_channel_message($event_name, $product_id, $event_data, 'photographer');
                            PWF_Telegram_Client::send_message($photo_chat_id, $photo_msg);
                        }
                    }
                } elseif ($to === 'waiting_content') {
                    $content_managers = PWF_Telegram_Auth::get_content_managers();
                    foreach ($content_managers as $cm) {
                        $cm_chat_id = get_user_meta($cm->ID, 'pwf_telegram_chat_id', true);
                        if (!empty($cm_chat_id) && (string) $cm_chat_id !== (string) $default_chat) {
                            $cm_msg = self::format_persian_channel_message($event_name, $product_id, $event_data, 'content_manager');
                            PWF_Telegram_Client::send_message($cm_chat_id, $cm_msg);
                        }
                    }
                }
            }

            // 4. Personal notification to assignee if task_assigned
            if ($event_name === 'task_assigned') {
                $user_id = absint($event_data['user_id'] ?? 0);
                if ($user_id) {
                    $user_chat_id = get_user_meta($user_id, 'pwf_telegram_chat_id', true);
                    if (!empty($user_chat_id) && (string) $user_chat_id !== (string) $default_chat) {
                        $personal_msg = self::format_persian_channel_message($event_name, $product_id, $event_data, 'assignee');
                        PWF_Telegram_Client::send_message($user_chat_id, $personal_msg);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('PWF Telegram notification error: ' . $e->getMessage());
        }
    }

    public static function notify_photographers_of_reference_photos($product_id, $product_name, array $photo_ids) {
        if (empty($photo_ids)) {
            return;
        }
        $photographers = PWF_Telegram_Auth::get_photographers();
        foreach ($photographers as $photographer) {
            $p_chat = get_user_meta($photographer->ID, 'pwf_telegram_chat_id', true);
            if (!empty($p_chat)) {
                foreach ($photo_ids as $idx => $fid) {
                    $caption = sprintf("📸 نمونه عکس ارسالی شرکت برای محصول #%d (%s) [تصویر %d]", $product_id, esc_html($product_name), $idx + 1);
                    PWF_Telegram_Client::send_photo($p_chat, $fid, $caption);
                }
            }
        }
    }
}
