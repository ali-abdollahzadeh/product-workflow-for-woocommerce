<?php
defined('ABSPATH') || exit;

// Load Microservices
require_once dirname(__FILE__) . '/telegram/services/class-telegram-client.php';
require_once dirname(__FILE__) . '/telegram/services/class-telegram-auth.php';
require_once dirname(__FILE__) . '/telegram/services/class-telegram-session.php';
require_once dirname(__FILE__) . '/telegram/services/class-telegram-ui.php';
require_once dirname(__FILE__) . '/telegram/services/class-telegram-product.php';
require_once dirname(__FILE__) . '/telegram/services/class-telegram-task.php';
require_once dirname(__FILE__) . '/telegram/services/class-telegram-media.php';
require_once dirname(__FILE__) . '/telegram/services/class-telegram-notifier.php';

/**
 * PWF_Telegram
 *
 * Orchestrator and Facade for the Telegram integration.
 * Routes incoming webhooks to dedicated microservices and preserves
 * 100% backward compatibility for all existing public methods.
 */
class PWF_Telegram {
    const API_BASE = PWF_Telegram_Client::API_BASE;
    const FILE_BASE = PWF_Telegram_Client::FILE_BASE;

    public static function boot() {
        add_action('pwf_workflow_event', array(__CLASS__, 'handle_event'), 10, 3);
        add_action('admin_post_pwf_test_telegram', array(__CLASS__, 'handle_test'));
        add_action('admin_post_pwf_set_telegram_webhook', array(__CLASS__, 'handle_set_webhook'));
        add_action('admin_post_pwf_delete_telegram_webhook', array(__CLASS__, 'handle_delete_webhook'));
    }

    // --- CLIENT FORWARDERS ---
    public static function is_enabled() {
        return PWF_Telegram_Client::is_enabled();
    }

    public static function get_token() {
        return PWF_Telegram_Client::get_token();
    }

    public static function get_default_chat_id() {
        return PWF_Telegram_Client::get_default_chat_id();
    }

    public static function get_events() {
        return PWF_Telegram_Client::get_events();
    }

    public static function is_configured() {
        return PWF_Telegram_Client::is_configured();
    }

    public static function get_me($token = null) {
        return PWF_Telegram_Client::get_me($token);
    }

    public static function get_webhook_url() {
        return PWF_Telegram_Client::get_webhook_url();
    }

    public static function get_webhook_secret() {
        return PWF_Telegram_Client::get_webhook_secret();
    }

    public static function set_webhook($token = null, $url = null, $secret = null) {
        return PWF_Telegram_Client::set_webhook($token, $url, $secret);
    }

    public static function verify_webhook_permission($request) {
        $secret = self::get_webhook_secret();
        if (empty($secret)) {
            return new WP_Error('rest_forbidden', pwf_t('Telegram webhook secret is not configured.'), array('status' => 403));
        }

        $header_token = '';
        if (is_object($request) && method_exists($request, 'get_header')) {
            $header_token = $request->get_header('x_telegram_bot_api_secret_token') ?: $request->get_header('x-telegram-bot-api-secret-token');
        } elseif (isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'])) {
            $header_token = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']));
        }

        if (empty($header_token) || !hash_equals($secret, (string) $header_token)) {
            return new WP_Error('rest_forbidden', pwf_t('Unauthorized Telegram webhook request.'), array('status' => 403));
        }

        return true;
    }

    public static function delete_webhook($token = null) {
        return PWF_Telegram_Client::delete_webhook($token);
    }

    public static function get_webhook_info($token = null) {
        return PWF_Telegram_Client::get_webhook_info($token);
    }

    public static function get_recent_chats($token = null) {
        return PWF_Telegram_Client::get_recent_chats($token);
    }

    public static function resolve_chat_id($chat_id) {
        return PWF_Telegram_Client::resolve_chat_id($chat_id);
    }

    public static function send_message($chat_id, $text, $keyboard = null, $parse_mode = 'HTML', $token = null) {
        return PWF_Telegram_Client::send_message($chat_id, $text, $keyboard, $parse_mode, $token);
    }

    public static function send_photo($chat_id, $photo, $caption = '', $parse_mode = 'HTML', $token = null) {
        return PWF_Telegram_Client::send_photo($chat_id, $photo, $caption, $parse_mode, $token);
    }

    public static function send_test($chat_id) {
        return PWF_Telegram_Client::send_test($chat_id);
    }

    // --- AUTH FORWARDERS ---
    public static function get_user_by_telegram_id($telegram_id) {
        return PWF_Telegram_Auth::get_user_by_telegram_id($telegram_id);
    }

    public static function get_photographers() {
        return PWF_Telegram_Auth::get_photographers();
    }

    public static function get_content_managers() {
        return PWF_Telegram_Auth::get_content_managers();
    }

    // --- SESSION FORWARDERS ---
    public static function get_session($chat_id) {
        return PWF_Telegram_Session::get($chat_id);
    }

    public static function set_session($chat_id, $step, $data = array(), $ttl = 3600) {
        return PWF_Telegram_Session::set($chat_id, $step, $data, $ttl);
    }

    public static function clear_session($chat_id) {
        return PWF_Telegram_Session::clear($chat_id);
    }

    // --- UI FORWARDERS ---
    public static function category_keyboard() {
        return PWF_Telegram_UI::category_keyboard();
    }

    public static function main_menu_keyboard($user = null) {
        return PWF_Telegram_UI::main_menu_keyboard($user);
    }

    // --- MEDIA FORWARDER ---
    public static function download_and_attach_photo($file_id, $product_id, $token = null) {
        return PWF_Telegram_Media::download_and_attach_photo($file_id, $product_id, $token);
    }

    // --- NOTIFICATION FORWARDERS ---
    public static function format_persian_channel_message($event_name, $product_id, $event_data, $recipient_type = 'channel') {
        return PWF_Telegram_Notifier::format_persian_channel_message($event_name, $product_id, $event_data, $recipient_type);
    }

    public static function handle_event($event_name, $product_id, $event_data) {
        return PWF_Telegram_Notifier::handle_event($event_name, $product_id, $event_data);
    }

    // --- ADMIN POST HANDLERS ---
    public static function handle_test() {
        if (!current_user_can('manage_workflow')) {
            wp_die(pwf_t('Access denied.'), '', array('response' => 403));
        }
        check_admin_referer('pwf_test_telegram');

        $chat_id = sanitize_text_field(wp_unslash($_POST['test_chat_id'] ?? ''));
        if (empty($chat_id)) {
            $chat_id = self::get_default_chat_id();
        }

        if (empty($chat_id)) {
            set_transient('pwf_notice_' . get_current_user_id(), array('error' => true, 'text' => pwf_t('Please specify a Chat ID to test.')), 60);
            wp_safe_redirect(admin_url('admin.php?page=pwf-settings#telegram'));
            exit;
        }

        $result = self::send_test($chat_id);
        if (is_wp_error($result)) {
            set_transient('pwf_notice_' . get_current_user_id(), array('error' => true, 'text' => sprintf(pwf_t('Telegram test failed: %s'), $result->get_error_message())), 60);
        } else {
            set_transient('pwf_notice_' . get_current_user_id(), array('error' => false, 'text' => pwf_t('Test message sent successfully via Telegram.')), 60);
        }

        wp_safe_redirect(admin_url('admin.php?page=pwf-settings#telegram'));
        exit;
    }

    public static function handle_set_webhook() {
        if (!current_user_can('manage_workflow')) {
            wp_die(pwf_t('Access denied.'), '', array('response' => 403));
        }
        check_admin_referer('pwf_set_telegram_webhook');
        $res = self::set_webhook();
        if (is_wp_error($res)) {
            set_transient('pwf_notice_' . get_current_user_id(), array('error' => true, 'text' => sprintf(pwf_t('Failed to set webhook: %s'), $res->get_error_message())), 60);
        } else {
            set_transient('pwf_notice_' . get_current_user_id(), array('error' => false, 'text' => pwf_t('Telegram webhook set successfully.')), 60);
        }
        wp_safe_redirect(admin_url('admin.php?page=pwf-settings#telegram'));
        exit;
    }

    public static function handle_delete_webhook() {
        if (!current_user_can('manage_workflow')) {
            wp_die(pwf_t('Access denied.'), '', array('response' => 403));
        }
        check_admin_referer('pwf_delete_telegram_webhook');
        $res = self::delete_webhook();
        if (is_wp_error($res)) {
            set_transient('pwf_notice_' . get_current_user_id(), array('error' => true, 'text' => sprintf(pwf_t('Failed to delete webhook: %s'), $res->get_error_message())), 60);
        } else {
            set_transient('pwf_notice_' . get_current_user_id(), array('error' => false, 'text' => pwf_t('Telegram webhook removed.')), 60);
        }
        wp_safe_redirect(admin_url('admin.php?page=pwf-settings#telegram'));
        exit;
    }

    // --- WEBHOOK ROUTER & ORCHESTRATOR ---
    public static function handle_webhook($request) {
        $permission = self::verify_webhook_permission($request);
        if (is_wp_error($permission)) {
            return $permission;
        }

        $data = is_object($request) && method_exists($request, 'get_json_params') ? $request->get_json_params() : array();
        if (empty($data) || !is_array($data)) {
            $body = is_object($request) && method_exists($request, 'get_body') ? $request->get_body() : '';
            $data = json_decode($body, true) ?: array();
        }

        $message = $data['message'] ?? null;
        if (!$message) {
            return array('ok' => true);
        }

        $chat_id = (string) ($message['chat']['id'] ?? '');
        $from_id = (string) ($message['from']['id'] ?? $chat_id);
        $text = trim((string) ($message['text'] ?? ''));
        $photos = $message['photo'] ?? null;

        if (empty($chat_id)) {
            return array('ok' => true);
        }

        // Authenticate user by Telegram ID
        $wp_user = PWF_Telegram_Auth::get_user_by_telegram_id($from_id);
        PWF_Telegram_Auth::set_current_user($wp_user);
        if (!$wp_user) {
            self::send_message($chat_id, PWF_Telegram_UI::get_unlinked_user_message($from_id), 'remove');
            return array('ok' => true);
        }

        // Command intent matching
        $is_cancel = ($text === '/cancel' || strcasecmp($text, 'cancel') === 0 || $text === 'انصراف' || $text === 'لغو' || $text === pwf_t('Cancel'));
        $is_help   = ($text === '/start' || $text === '/help' || $text === 'ℹ️ ' . pwf_t('Help') || $text === 'ℹ️ راهنما' || $text === 'راهنما');
        $is_tasks  = ($text === '/tasks' || $text === '📋 ' . pwf_t('My Tasks') || $text === '📋 وظایف من' || $text === '📋 کارهای من' || $text === 'وظایف من' || $text === '📸 وظایف عکاسی من' || $text === '📝 وظایف تولید محتوا' || $text === '🔍 وظایف بررسی و تایید' || str_contains($text, 'وظایف') || str_contains($text, 'کارهای من'));
        $is_new    = ($text === '/new' || $text === '➕ ' . pwf_t('New Product') || $text === '➕ ثبت محصول جدید' || $text === '➕ محصول جدید' || $text === 'ثبت محصول جدید');
        $is_skip   = ($text === '/skip' || strcasecmp($text, 'skip') === 0 || $text === 'رد شدن' || $text === 'رد');
        $is_done   = ($text === '/done' || strcasecmp($text, 'done') === 0 || $text === 'اتمام' || $text === 'پایان' || $text === 'تکمیل' || $text === pwf_t('Finish') || $text === '✅ تیک تکمیل و اتمام عکاسی' || $text === 'تیک تکمیل' || $text === 'تکمیل عکاسی' || $text === 'تکمیل تسک' || $text === '✅ تکمیل' || $text === '✅' || preg_match('/^(?:✅\s*)?(?:تیک\s*)?تکمیل/ui', $text));

        // 1. Cancel
        if ($is_cancel) {
            self::clear_session($chat_id);
            self::send_message($chat_id, "❌ " . pwf_t('Operation cancelled.'), self::main_menu_keyboard());
            return array('ok' => true);
        }

        // 2. Start / Help
        if ($is_help) {
            self::clear_session($chat_id);
            self::send_message($chat_id, PWF_Telegram_UI::get_greeting_message($wp_user), self::main_menu_keyboard());
            return array('ok' => true);
        }

        // 3. Tasks
        if ($is_tasks) {
            $valid_tasks = PWF_Telegram_Task::get_user_tasks($wp_user);
            if (empty($valid_tasks)) {
                self::send_message($chat_id, pwf_t('You have no active pending tasks right now.'), self::main_menu_keyboard());
                return array('ok' => true);
            }

            $tasks_view = PWF_Telegram_Task::build_tasks_view($valid_tasks, $wp_user);
            self::send_message($chat_id, $tasks_view['message'], $tasks_view['keyboard']);
            return array('ok' => true);
        }

        // 4. Photographer done: /done_photo_123 or "✅ تکمیل عکاسی #123"
        if (preg_match('/^(?:\/done_photo_|\/done_|\/finish_photo_|✅\s*تکمیل\s*عکاسی\s*#?)(\d+)$/ui', $text, $dpm)) {
            $done_pid = (int) $dpm[1];
            if (!PWF_Telegram_Auth::is_photographer($wp_user) && !PWF_Telegram_Auth::is_manager($wp_user)) {
                self::send_message($chat_id, '⛔ شما دسترسی عکاسی محصول را ندارید.', self::main_menu_keyboard());
                return array('ok' => true);
            }

            $p_status = function_exists('get_post_status') ? get_post_status($done_pid) : false;
            $prod = function_exists('wc_get_product') ? wc_get_product($done_pid) : null;
            if (!$prod || !$p_status || in_array($p_status, array('trash', 'auto-draft'), true)) {
                self::send_message($chat_id, '❌ این محصول در ووکامرس وجود ندارد یا حذف شده است.', self::main_menu_keyboard());
                return array('ok' => true);
            }

            $cms = PWF_Telegram_Task::complete_photography_stage($done_pid, $wp_user);

            $cur_sess = self::get_session($chat_id);
            if (($cur_sess['step'] ?? '') === 'photographer_upload' && (int)($cur_sess['data']['product_id'] ?? 0) === $done_pid) {
                self::clear_session($chat_id);
            }

            $p_title = $prod ? $prod->get_name() : ('#' . $done_pid);
            $done_msg = "🎉 <b>تسک عکاسی محصول #{$done_pid} («" . esc_html($p_title) . "») با موفقیت تکمیل شد!</b>\n\n"
                      . "✨ این تسک از لیست وظایف فعال شما حذف گردید.\n"
                      . "📦 محصول به مرحله <b>تولید محتوا (Waiting for Content)</b> منتقل شد و برای مدیر محتوا ارسال گردید.";
            self::send_message($chat_id, $done_msg, self::main_menu_keyboard());

            // Broadcast status changed to channel
            self::handle_event('status_changed', $done_pid, array(
                'from'  => 'waiting_photography',
                'to'    => 'waiting_content',
                'actor' => $wp_user->ID,
                'note'  => 'عکس‌های نهایی محصول توسط عکاس تایید و تکمیل شد.',
            ));

            // Notify Content Managers
            foreach ($cms as $cm_user) {
                $cm_chat = get_user_meta($cm_user->ID, 'pwf_telegram_chat_id', true);
                if (!empty($cm_chat)) {
                    $cm_msg = self::format_persian_channel_message('status_changed', $done_pid, array(
                        'from'  => 'waiting_photography',
                        'to'    => 'waiting_content',
                        'actor' => $wp_user->ID,
                        'note'  => 'عکاسی به پایان رسید و تصاویر تایید شدند.',
                    ), 'content_manager');
                    self::send_message($cm_chat, $cm_msg, array(
                        array('📝 مدیریت محتوا #' . $done_pid, '✅ تکمیل محتوا #' . $done_pid),
                        array('📋 ' . pwf_t('My Tasks')),
                    ));
                }
            }
            return array('ok' => true);
        }

        // 5. Photographer initiate photo upload: /photo_123 or "📸 ارسال عکس #123"
        if (preg_match('/^(?:\/photo_|📸\s*ارسال\s*عکس\s*#?)(\d+)$/ui', $text, $pm)) {
            $photo_pid = (int) $pm[1];
            if (!PWF_Telegram_Auth::is_photographer($wp_user) && !PWF_Telegram_Auth::is_manager($wp_user)) {
                self::send_message($chat_id, '⛔ شما دسترسی عکاسی محصول را ندارید.', self::main_menu_keyboard());
                return array('ok' => true);
            }

            global $wpdb;
            if ($wpdb) {
                $workflow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pwf_workflows WHERE product_id=%d", $photo_pid));
                if (!$workflow) {
                    self::send_message($chat_id, '❌ محصول مورد نظر در فرآیند یافت نشد.', self::main_menu_keyboard());
                    return array('ok' => true);
                }

                $assignment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pwf_assignments WHERE product_id=%d AND stage='photography'", $photo_pid));
                if (!$assignment) {
                    $wpdb->insert($wpdb->prefix . 'pwf_assignments', array(
                        'product_id'  => $photo_pid,
                        'stage'       => 'photography',
                        'user_id'     => $wp_user->ID,
                        'assigned_at' => current_time('mysql', true),
                    ));
                } elseif ($assignment->user_id != $wp_user->ID) {
                    $wpdb->update($wpdb->prefix . 'pwf_assignments', array(
                        'user_id'     => $wp_user->ID,
                        'completed_at'=> null,
                    ), array('assignment_id' => $assignment->assignment_id));
                }

                if ($workflow->current_status === 'new_product') {
                    $wpdb->update($wpdb->prefix . 'pwf_workflows', array(
                        'current_status' => 'waiting_photography',
                        'updated_at'     => current_time('mysql', true),
                    ), array('product_id' => $photo_pid));
                }
            }

            self::set_session($chat_id, 'photographer_upload', array(
                'product_id'     => $photo_pid,
                'uploaded_count' => 0,
            ));

            $prod = function_exists('wc_get_product') ? wc_get_product($photo_pid) : null;
            $pname = $prod ? $prod->get_name() : ('#' . $photo_pid);

            $msg = "📸 <b>در حال ارسال عکس‌های نهایی برای محصول #{$photo_pid} (" . esc_html($pname) . ")</b>\n\n"
                 . "لطفاً عکس‌های آتلیه / نهایی محصول را ارسال فرمایید (تکی یا به صورت آلبوم).\n"
                 . "هر عکس به گالری و تصویر اصلی محصول متصل می‌شود.\n\n"
                 . "<i>پس از اتمام ارسال عکس‌ها، دکمه «✅ تیک تکمیل و اتمام عکاسی» را بزنید تا مرحله عکاسی تکمیل شده و از لیست تسک‌های شما حذف گردد.</i>";

            self::send_message($chat_id, $msg, PWF_Telegram_UI::build_photographer_upload_keyboard());
            return array('ok' => true);
        }

        // 6. Content Manager overview: /content_123 or "📝 مدیریت محتوا #123"
        if (preg_match('/^(?:\/content_|📝\s*مدیریت\s*محتوا\s*#?)(\d+)$/ui', $text, $cm_m)) {
            $c_pid = (int) $cm_m[1];
            if (!PWF_Telegram_Auth::is_content_manager($wp_user) && !PWF_Telegram_Auth::is_manager($wp_user)) {
                self::send_message($chat_id, '⛔ شما دسترسی مدیریت محتوا را ندارید.', self::main_menu_keyboard());
                return array('ok' => true);
            }

            $prod = function_exists('wc_get_product') ? wc_get_product($c_pid) : null;
            if (!$prod) {
                self::send_message($chat_id, '❌ محصول یافت نشد.', self::main_menu_keyboard());
                return array('ok' => true);
            }

            $p_title = $prod->get_name();
            $p_sku = $prod->get_sku() ?: 'تعیین نشده';
            $p_price = method_exists($prod, 'get_regular_price') ? ($prod->get_regular_price() ?: 'تعیین نشده') : 'تعیین نشده';
            $p_desc = method_exists($prod, 'get_description') ? (strip_tags($prod->get_description()) ?: 'تعیین نشده') : 'تعیین نشده';

            $desc_snippet = function_exists('mb_substr') ? mb_substr($p_desc, 0, 150) : substr($p_desc, 0, 150);
            $c_msg = "📝 <b>مدیریت محتوای محصول #{$c_pid} (" . esc_html($p_title) . ")</b>\n"
                   . "━━━━━━━━━━━━━━━━━━\n"
                   . "🏷️ <b>نام:</b> " . esc_html($p_title) . "\n"
                   . "🔖 <b>کد SKU:</b> <code>" . esc_html($p_sku) . "</code>\n"
                   . "💰 <b>قیمت:</b> " . esc_html($p_price) . "\n"
                   . "📄 <b>توضیحات:</b> " . esc_html($desc_snippet) . "\n"
                   . "━━━━━━━━━━━━━━━━━━\n"
                   . "<b>دستورات مدیریت:</b>\n"
                   . "• ثبت توضیحات: <code>/desc_{$c_pid} متن توضیحات</code>\n"
                   . "• ثبت قیمت: <code>/price_{$c_pid} 500000</code>\n"
                   . "• تایید و تکمیل محتوا: /finish_{$c_pid}\n"
                   . "🔗 <a href=\"" . esc_url(admin_url('admin.php?page=pwf-product&product_id=' . $c_pid)) . "\">مشاهده در پنل سایت</a>";

            $c_kb = array(
                array('/finish_' . $c_pid),
                array('📋 ' . pwf_t('My Tasks')),
            );

            self::send_message($chat_id, $c_msg, $c_kb);
            return array('ok' => true);
        }

        // 7. Setting description: /desc_123 <text>
        if (preg_match('/^\/desc_(\d+)\s+(.+)$/usi', $text, $dm)) {
            $d_pid = (int) $dm[1];
            $d_text = trim($dm[2]);
            if (PWF_Telegram_Product::update_description($d_pid, $d_text)) {
                self::send_message($chat_id, "✅ توضیحات محصول #{$d_pid} بروزرسانی شد.", array(array('/content_' . $d_pid), array('📋 ' . pwf_t('My Tasks'))));
            } else {
                self::send_message($chat_id, "❌ خطایی رخ داد یا محصول یافت نشد.");
            }
            return array('ok' => true);
        }

        // 8. Setting price: /price_123 <amount>
        if (preg_match('/^\/price_(\d+)\s+([\d\.]+)$/ui', $text, $prm)) {
            $pr_pid = (int) $prm[1];
            $pr_val = trim($prm[2]);
            if (PWF_Telegram_Product::update_price($pr_pid, $pr_val)) {
                self::send_message($chat_id, "✅ قیمت محصول #{$pr_pid} روی {$pr_val} تنظیم شد.", array(array('/content_' . $pr_pid), array('📋 ' . pwf_t('My Tasks'))));
            } else {
                self::send_message($chat_id, "❌ خطایی رخ داد یا محصول یافت نشد.");
            }
            return array('ok' => true);
        }

        // 9. Completing content: /finish_123 or "✅ تکمیل محتوا #123"
        if (preg_match('/^(?:\/finish_|✅\s*تکمیل\s*محتوا\s*#?)(\d+)$/ui', $text, $fnm)) {
            $f_pid = (int) $fnm[1];
            PWF_Telegram_Task::complete_content_stage($f_pid, $wp_user);

            self::send_message($chat_id, "🎉 محتوای محصول #{$f_pid} با موفقیت تایید و تکمیل شد و محصول به مرحله «در انتظار بررسی و تایید» (Review) منتقل گردید.", self::main_menu_keyboard());

            self::handle_event('status_changed', $f_pid, array(
                'from'  => 'waiting_content',
                'to'    => 'waiting_review',
                'actor' => $wp_user->ID,
                'note'  => 'تولید محتوا توسط مدیر محتوا تکمیل شد.',
            ));
            return array('ok' => true);
        }

        // 10. View Execution Task: /task_123 or "👁️ جزئیات #123"
        if (preg_match('/^(?:\/task_|👁️\s*جزئیات\s*#?)(\d+)$/ui', $text, $tm)) {
            $task_id = (int) $tm[1];
            if (!class_exists('PWF_Task_Manager')) {
                self::send_message($chat_id, '❌ سیستم وظایف در دسترس نیست.', self::main_menu_keyboard());
                return array('ok' => true);
            }
            $t = PWF_Task_Manager::get($task_id);
            if (!$t) {
                self::send_message($chat_id, '❌ وظیفه مورد نظر یافت نشد.', self::main_menu_keyboard());
                return array('ok' => true);
            }
            if ((int) $t->assigned_to !== (int) $wp_user->ID && !PWF_Telegram_Auth::is_manager($wp_user)) {
                self::send_message($chat_id, '⛔ شما به این وظیفه دسترسی ندارید.', self::main_menu_keyboard());
                return array('ok' => true);
            }

            $type_label = PWF_Task_Manager::types()[$t->task_type]['label'] ?? $t->task_type;
            $priority_label = PWF_Task_Manager::priorities()[$t->priority] ?? $t->priority;
            $status_label = PWF_Task_Manager::statuses()[$t->status] ?? $t->status;

            $msg = "📋 <b>وظیفه #{$t->task_id}: " . esc_html($t->title) . "</b>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "🏷️ <b>نوع کار:</b> " . esc_html($type_label) . "\n"
                 . "⚡ <b>اولویت:</b> " . esc_html($priority_label) . "\n"
                 . "📊 <b>وضعیت فعلی:</b> " . esc_html($status_label) . "\n"
                 . "📅 <b>مهلت سررسید:</b> " . esc_html($t->due_date ?: 'ندارد') . "\n";

            if ($t->product_id) {
                $p = wc_get_product($t->product_id);
                $p_title = $p ? $p->get_name() : ('#' . $t->product_id);
                $msg .= "📦 <b>محصول متصل:</b> " . esc_html($p_title) . " (#{$t->product_id})\n";
            }

            if (!empty($t->description)) {
                $clean_desc = strip_tags($t->description);
                $msg .= "\n📝 <b>توضیحات و راهنمای کار:</b>\n" . esc_html($clean_desc) . "\n";
            }

            $kb = array();
            if ($t->status === 'assigned') {
                $msg .= "\n<i>برای شروع این کار، روی دکمه «🚀 شروع کار» کلیک کنید.</i>";
                $kb[] = array('🚀 شروع کار #' . $t->task_id);
            } elseif (in_array($t->status, array('in_progress', 'needs_revision'), true)) {
                $msg .= "\n<i>برای تحویل خروجی (فایل، عکس یا توضیحات و لینک)، روی دکمه «📤 تحویل خروجی» بزنید.</i>";
                $kb[] = array('📤 تحویل خروجی #' . $t->task_id);
            }

            $kb[] = array('📋 ' . pwf_t('My Tasks'));
            self::send_message($chat_id, $msg, $kb);
            return array('ok' => true);
        }

        // 11. Start Task: /start_task_123 or "🚀 شروع کار #123"
        if (preg_match('/^(?:\/start_task_|🚀\s*شروع\s*کار\s*#?)(\d+)$/ui', $text, $stm)) {
            $task_id = (int) $stm[1];
            if (!class_exists('PWF_Task_Manager')) {
                self::send_message($chat_id, '❌ سیستم وظایف در دسترس نیست.', self::main_menu_keyboard());
                return array('ok' => true);
            }
            $res = PWF_Task_Manager::start($task_id);
            if (is_wp_error($res)) {
                self::send_message($chat_id, '❌ خطا: ' . $res->get_error_message(), self::main_menu_keyboard());
            } else {
                $msg = "🚀 <b>وظیفه #{$task_id} به وضعیت «در حال انجام» تغییر یافت!</b>\n\n"
                     . "پس از آماده‌سازی، با زدن دکمه «📤 تحویل خروجی» فایل‌ها، لینک یا توضیحات کار خود را ارسال کنید تا برای بازبینی مدیریت ثبت شود.";
                self::send_message($chat_id, $msg, array(
                    array('📤 تحویل خروجی #' . $task_id),
                    array('📋 ' . pwf_t('My Tasks'))
                ));
            }
            return array('ok' => true);
        }

        // 12. Submit Deliverable prompt: /submit_task_123 or "📤 تحویل خروجی #123"
        if (preg_match('/^(?:\/submit_task_|📤\s*تحویل\s*خروجی\s*#?)(\d+)$/ui', $text, $sbm)) {
            $task_id = (int) $sbm[1];
            if (!class_exists('PWF_Task_Manager')) {
                self::send_message($chat_id, '❌ سیستم وظایف در دسترس نیست.', self::main_menu_keyboard());
                return array('ok' => true);
            }
            $t = PWF_Task_Manager::get($task_id);
            if (!$t) {
                self::send_message($chat_id, '❌ وظیفه مورد نظر یافت نشد.', self::main_menu_keyboard());
                return array('ok' => true);
            }

            self::set_session($chat_id, 'task_submit', array('task_id' => $task_id));
            $msg = "📤 <b>تحویل خروجی برای وظیفه #{$task_id} («" . esc_html($t->title) . "»)</b>\n\n"
                 . "لطفاً توضیحات کار، لینک خروجی (مانند گیت‌هاب، فیگما، درایو و...) یا <b>عکس/فایل خروجی</b> خود را همینجا ارسال فرمایید:\n\n"
                 . "<i>(برای لغو /cancel را ارسال کنید)</i>";
            self::send_message($chat_id, $msg, array(array('/cancel')));
            return array('ok' => true);
        }

        // Conversation Session State Machine
        $session = self::get_session($chat_id);
        $step = $session['step'] ?? 'idle';
        $sess_data = $session['data'] ?? array();

        // STEP: Submitting deliverable for an execution task
        if ($step === 'task_submit') {
            $task_id = (int) ($sess_data['task_id'] ?? 0);
            if (!$task_id || !class_exists('PWF_Task_Manager')) {
                self::clear_session($chat_id);
                self::send_message($chat_id, '❌ جلسه منقضی شده است.', self::main_menu_keyboard());
                return array('ok' => true);
            }

            $deliverable_url = '';
            $deliverable_note = '';

            // If user sent a photo
            if (!empty($photos) && is_array($photos)) {
                $best = end($photos);
                $fid = $best['file_id'] ?? '';
                if ($fid) {
                    $dl = PWF_Telegram_Media::download_deliverable_file($fid, $task_id);
                    if ($dl) {
                        $task = PWF_Task_Manager::get($task_id);
                        $existing = !empty($task->deliverable_files) ? json_decode($task->deliverable_files, true) : array();
                        $existing[] = $dl;
                        global $wpdb;
                        $wpdb->update($wpdb->prefix . 'pwf_tasks', array('deliverable_files' => wp_json_encode($existing)), array('task_id' => $task_id));
                        $deliverable_note = 'فایل خروجی از طریق تلگرام ارسال شد.';
                    }
                }
            } elseif (!empty($text)) {
                // Check if text contains URL
                if (preg_match('/(https?:\/\/[^\s]+)/ui', $text, $url_match)) {
                    $deliverable_url = $url_match[1];
                    $deliverable_note = trim(str_replace($deliverable_url, '', $text));
                    if (empty($deliverable_note)) {
                        $deliverable_note = 'لینک خروجی از طریق تلگرام تحویل داده شد.';
                    }
                } else {
                    $deliverable_note = $text;
                }
            }

            $res = PWF_Task_Manager::submit($task_id, $deliverable_url, $deliverable_note);
            self::clear_session($chat_id);

            if (is_wp_error($res)) {
                self::send_message($chat_id, '❌ خطا در ثبت تحویل کار: ' . $res->get_error_message(), self::main_menu_keyboard());
            } else {
                $success_msg = "🎉 <b>خروجی وظیفه #{$task_id} با موفقیت ثبت شد!</b>\n\n"
                             . "وضعیت وظیفه به «تحویل‌شده» (Submitted) تغییر یافت و برای بررسی کیفی و تایید به واحد مدیریت ارسال گردید.";
                self::send_message($chat_id, $success_msg, self::main_menu_keyboard());
            }
            return array('ok' => true);
        }

        // 10. Handle /new or tapping "➕ New Product"
        if ($is_new) {
            if (!PWF_Telegram_Auth::can_create_product($wp_user)) {
                self::send_message($chat_id, pwf_t('You do not have permission to create products.'), self::main_menu_keyboard());
                return array('ok' => true);
            }
            self::set_session($chat_id, 'new_name');
            $ask = "📝 <b>" . pwf_t('Add New Product') . "</b>\n\n"
                 . pwf_t('Please enter the Product Name:') . "\n\n"
                 . "<i>(" . pwf_t('Type /cancel to abort at any time') . ")</i>";
            self::send_message($chat_id, $ask, array(array('/cancel')));
            return array('ok' => true);
        }

        // STEP 1: Product Name
        if ($step === 'new_name') {
            if (empty($text)) {
                self::send_message($chat_id, pwf_t('Product name cannot be empty. Please enter the name:'));
                return array('ok' => true);
            }
            $sess_data['name'] = $text;
            self::set_session($chat_id, 'new_cat', $sess_data);

            $ask = "✅ " . pwf_t('Product name:') . " <b>" . esc_html($text) . "</b>\n\n"
                 . "📁 لطفاً <b>دسته‌بندی محصول</b> را از گزینه‌های زیر انتخاب کرده یا نام آن را بنویسید (یا /skip در صورت نداشتن):";
            self::send_message($chat_id, $ask, self::category_keyboard());
            return array('ok' => true);
        }

        // STEP 2: Category
        if ($step === 'new_cat') {
            if ($is_skip) {
                $sess_data['category_id'] = 0;
                $sess_data['category_name'] = '';
            } else {
                $resolved = PWF_Telegram_Product::resolve_category_input($text);
                $sess_data['category_id'] = $resolved['id'];
                $sess_data['category_name'] = $resolved['name'];
            }

            self::set_session($chat_id, 'new_sku', $sess_data);
            $cat_label = !empty($sess_data['category_name']) ? $sess_data['category_name'] : pwf_t('None');
            $ask = "✅ دسته‌بندی: <b>" . esc_html($cat_label) . "</b>\n\n"
                 . pwf_t('Please enter the SKU code (or send /skip if none):');
            self::send_message($chat_id, $ask, array(array('/skip'), array('/cancel')));
            return array('ok' => true);
        }

        // STEP 3: SKU
        if ($step === 'new_sku') {
            $sess_data['sku'] = $is_skip ? '' : $text;
            self::set_session($chat_id, 'new_carton', $sess_data);

            $sku_label = !empty($sess_data['sku']) ? $sess_data['sku'] : pwf_t('None');
            $ask = "✅ " . pwf_t('SKU:') . " <b>" . esc_html($sku_label) . "</b>\n\n"
                 . "📦 لطفاً <b>تعداد در کارتن</b> را وارد فرمایید (یا /skip):";
            self::send_message($chat_id, $ask, array(array('/skip'), array('/cancel')));
            return array('ok' => true);
        }

        // STEP 4: Carton Quantity
        if ($step === 'new_carton') {
            $sess_data['carton_qty'] = $is_skip ? '' : $text;
            self::set_session($chat_id, 'new_cbm', $sess_data);

            $carton_label = !empty($sess_data['carton_qty']) ? $sess_data['carton_qty'] : pwf_t('None');
            $ask = "✅ تعداد در کارتن: <b>" . esc_html($carton_label) . "</b>\n\n"
                 . "📐 لطفاً <b>حجم کارتن (CBM)</b> را بر حسب متر مکعب وارد فرمایید (مثلاً 0.045) (یا /skip):";
            self::send_message($chat_id, $ask, array(array('/skip'), array('/cancel')));
            return array('ok' => true);
        }

        // STEP 5: CBM
        if ($step === 'new_cbm') {
            $sess_data['cbm'] = $is_skip ? '' : $text;
            self::set_session($chat_id, 'new_desc', $sess_data);

            $cbm_label = !empty($sess_data['cbm']) ? $sess_data['cbm'] : pwf_t('None');
            $ask = "✅ حجم کارتن (CBM): <b>" . esc_html($cbm_label) . "</b>\n\n"
                 . pwf_t('Please enter factory notes or initial description (or send /skip):');
            self::send_message($chat_id, $ask, array(array('/skip'), array('/cancel')));
            return array('ok' => true);
        }

        // STEP 6: Description -> Creates WooCommerce Product!
        if ($step === 'new_desc') {
            $sess_data['description'] = $is_skip ? '' : $text;
            $sess_data['photo_file_ids'] = array();

            $product_id = PWF_Telegram_Product::create_from_session_data($sess_data, $wp_user->ID);

            if (is_wp_error($product_id)) {
                self::clear_session($chat_id);
                self::send_message($chat_id, sprintf(pwf_t('Failed to create product: %s'), $product_id->get_error_message()), self::main_menu_keyboard());
                return array('ok' => true);
            }

            $sess_data['product_id'] = $product_id;
            self::set_session($chat_id, 'new_photos', $sess_data);

            $cat_disp = !empty($sess_data['category_name']) ? $sess_data['category_name'] : pwf_t('None');
            $carton_disp = !empty($sess_data['carton_qty']) ? $sess_data['carton_qty'] : pwf_t('None');
            $cbm_disp = !empty($sess_data['cbm']) ? $sess_data['cbm'] : pwf_t('None');
            $sku_disp = !empty($sess_data['sku']) ? $sess_data['sku'] : pwf_t('None');

            $success_msg = "🎉 <b>" . pwf_t('Product created successfully!') . "</b>\n\n"
                         . "🆔 <b>ID:</b> #" . $product_id . "\n"
                         . "🏷️ <b>" . pwf_t('Name') . ":</b> " . esc_html($sess_data['name']) . "\n"
                         . "📁 <b>دسته‌بندی:</b> " . esc_html($cat_disp) . "\n"
                         . "🔖 <b>" . pwf_t('SKU') . ":</b> " . esc_html($sku_disp) . "\n"
                         . "📦 <b>تعداد در کارتن:</b> " . esc_html($carton_disp) . "\n"
                         . "📐 <b>حجم CBM:</b> " . esc_html($cbm_disp) . "\n"
                         . "📊 <b>" . pwf_t('Stage') . ":</b> " . esc_html(PWF_Workflow_Manager::states()['new_product'] ?? 'New Product') . "\n\n"
                         . "📸 <b>" . pwf_t('Now please send reference photos for this product.') . "</b>\n"
                         . "<i>" . pwf_t('Send images one by one or as an album. Click /done when finished.') . "</i>";

            self::send_message($chat_id, $success_msg, array(array('/done'), array('/cancel')));
            return array('ok' => true);
        }

        // STEP: Photographer uploading final photos
        if ($step === 'photographer_upload') {
            $product_id = (int) ($sess_data['product_id'] ?? 0);
            $uploaded_count = (int) ($sess_data['uploaded_count'] ?? 0);

            if ($is_done) {
                $prod = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
                $has_image = $prod && method_exists($prod, 'get_image_id') && $prod->get_image_id();

                if ($uploaded_count === 0 && !$has_image) {
                    self::send_message($chat_id, "⚠️ لطفاً حداقل یک عکس برای محصول ارسال کنید یا در صورت تمایل /cancel را بزنید.");
                    return array('ok' => true);
                }

                $cms = PWF_Telegram_Task::complete_photography_stage($product_id, $wp_user);
                self::clear_session($chat_id);

                $done_msg = "🎉 <b>مرحله عکاسی محصول #{$product_id} با موفقیت تکمیل شد!</b>\n\n"
                          . "✨ این تسک از لیست وظایف فعال شما حذف گردید.\n"
                          . "📦 محصول به مرحله <b>تولید محتوا (Waiting for Content)</b> منتقل گردید.\n"
                          . "وظیفه جدید برای مدیر محتوا در تلگرام ارسال شد.";
                self::send_message($chat_id, $done_msg, self::main_menu_keyboard());

                self::handle_event('status_changed', $product_id, array(
                    'from'  => 'waiting_photography',
                    'to'    => 'waiting_content',
                    'actor' => $wp_user->ID,
                    'note'  => 'عکس‌های نهایی محصول توسط عکاس ارسال و تایید شد.',
                ));

                foreach ($cms as $cm_user) {
                    $cm_chat = get_user_meta($cm_user->ID, 'pwf_telegram_chat_id', true);
                    if (!empty($cm_chat)) {
                        $cm_msg = self::format_persian_channel_message('status_changed', $product_id, array(
                            'from'  => 'waiting_photography',
                            'to'    => 'waiting_content',
                            'actor' => $wp_user->ID,
                            'note'  => 'عکاسی به پایان رسید و تصاویر تایید شدند.',
                        ), 'content_manager');
                        self::send_message($cm_chat, $cm_msg, array(
                            array('📝 مدیریت محتوا #' . $product_id, '✅ تکمیل محتوا #' . $product_id),
                            array('📋 ' . pwf_t('My Tasks')),
                        ));
                    }
                }

                return array('ok' => true);
            }

            if (!empty($photos) && is_array($photos)) {
                $best_photo = end($photos);
                $file_id = $best_photo['file_id'] ?? '';
                if ($file_id) {
                    $attach_id = self::download_and_attach_photo($file_id, $product_id);
                    if ($attach_id) {
                        $sess_data['uploaded_count'] = $uploaded_count + 1;
                        self::set_session($chat_id, 'photographer_upload', $sess_data);

                        self::send_message($chat_id, "📸 تصویر شماره {$sess_data['uploaded_count']} با موفقیت ثبت شد!\nعکس‌های بعدی را ارسال کنید یا جهت اتمام و حذف تسک از لیست کارهای خود، دکمه <b>«✅ تیک تکمیل و اتمام عکاسی»</b> را بزنید.", PWF_Telegram_UI::build_photographer_upload_keyboard());
                        return array('ok' => true);
                    }
                }
            }

            self::send_message($chat_id, "لطفاً عکس‌های محصول را بفرستید، یا برای اتمام دکمه «✅ تیک تکمیل و اتمام عکاسی» یا /done را بزنید.", PWF_Telegram_UI::build_photographer_upload_keyboard());
            return array('ok' => true);
        }

        // STEP 7: Reference Photos (Company Registration)
        if ($step === 'new_photos') {
            $product_id = (int) ($sess_data['product_id'] ?? 0);

            if ($is_done) {
                self::clear_session($chat_id);

                // Broadcast created event to channel & photographers
                self::handle_event('created', $product_id, array('user_id' => $wp_user->ID));

                // Send reference photos to photographers
                $photo_ids = (array) ($sess_data['photo_file_ids'] ?? array());
                if (!empty($photo_ids)) {
                    $p_name = $sess_data['name'] ?? ('#' . $product_id);
                    PWF_Telegram_Notifier::notify_photographers_of_reference_photos($product_id, $p_name, $photo_ids);
                }

                $final_msg = "✅ <b>" . pwf_t('Product setup completed!') . "</b>\n\n"
                           . sprintf(pwf_t('Product #%d is now enrolled in the workflow queue and ready for photography assignment.'), $product_id)
                           . "\n\n📢 اطلاعیه محصول در کانال منتشر و برای عکاسان ارسال گردید.";
                self::send_message($chat_id, $final_msg, self::main_menu_keyboard());
                return array('ok' => true);
            }

            if (!empty($photos) && is_array($photos)) {
                $best_photo = end($photos);
                $file_id = $best_photo['file_id'] ?? '';
                if ($file_id) {
                    $attach_id = self::download_and_attach_photo($file_id, $product_id);
                    if ($attach_id) {
                        if (!isset($sess_data['photo_file_ids'])) {
                            $sess_data['photo_file_ids'] = array();
                        }
                        $sess_data['photo_file_ids'][] = $file_id;
                        self::set_session($chat_id, 'new_photos', $sess_data);

                        self::send_message($chat_id, "📸 " . pwf_t('Photo attached successfully! Send more photos or click /done to finish.'), array(array('/done'), array('/cancel')));
                        return array('ok' => true);
                    }
                }
            }

            self::send_message($chat_id, pwf_t('Please send photos, or click /done to finish.'), array(array('/done'), array('/cancel')));
            return array('ok' => true);
        }

        // Default response if idle
        self::send_message($chat_id, pwf_t('Choose an option from the menu:'), self::main_menu_keyboard());
        return array('ok' => true);
    }
}
