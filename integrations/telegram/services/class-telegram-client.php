<?php
defined('ABSPATH') || exit;

/**
 * PWF_Telegram_Client
 *
 * Microservice handling low-level Telegram Bot API HTTP transport,
 * chat resolution, webhook administration, and messaging.
 */
class PWF_Telegram_Client {
    const API_BASE = 'https://api.telegram.org/bot';
    const FILE_BASE = 'https://api.telegram.org/file/bot';

    public static function is_enabled() {
        return get_option('pwf_telegram_enabled', '0') === '1';
    }

    public static function get_token() {
        return trim((string) get_option('pwf_telegram_bot_token', ''));
    }

    public static function get_default_chat_id() {
        return trim((string) get_option('pwf_telegram_default_chat_id', ''));
    }

    public static function get_events() {
        $events = get_option('pwf_telegram_events', null);
        if ($events === null) {
            return array('task_assigned', 'status_changed', 'created', 'content_saved', 'images_saved', 'image_uploaded');
        }
        return is_array($events) ? $events : array();
    }

    public static function is_configured() {
        return self::is_enabled() && self::get_token() !== '';
    }

    public static function get_me($token = null) {
        $bot_token = $token ?: self::get_token();
        if (empty($bot_token)) {
            return new WP_Error('no_token', pwf_t('Telegram bot token is not configured.'));
        }

        $url = self::API_BASE . $bot_token . '/getMe';
        $response = wp_remote_get($url, array('timeout' => 10, 'sslverify' => true));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['ok'])) {
            $desc = $body['description'] ?? pwf_t('Failed to connect to Telegram API.');
            return new WP_Error('telegram_error', $desc);
        }

        return $body['result'] ?? array();
    }

    public static function get_webhook_url() {
        return get_rest_url(null, 'product-workflow/v1/telegram/webhook');
    }

    public static function set_webhook($token = null, $url = null) {
        $bot_token = $token ?: self::get_token();
        if (empty($bot_token)) {
            return new WP_Error('no_token', pwf_t('Telegram bot token is not configured.'));
        }
        $endpoint = self::API_BASE . $bot_token . '/setWebhook';
        $webhook_url = $url ?: self::get_webhook_url();
        $response = wp_remote_post($endpoint, array(
            'timeout'   => 15,
            'sslverify' => true,
            'body'      => array(
                'url'                  => $webhook_url,
                'drop_pending_updates' => false,
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['ok'])) {
            return new WP_Error('webhook_failed', $body['description'] ?? pwf_t('Failed to set Telegram webhook.'));
        }
        return true;
    }

    public static function delete_webhook($token = null) {
        $bot_token = $token ?: self::get_token();
        if (empty($bot_token)) {
            return new WP_Error('no_token', pwf_t('Telegram bot token is not configured.'));
        }
        $endpoint = self::API_BASE . $bot_token . '/deleteWebhook';
        $response = wp_remote_post($endpoint, array('timeout' => 15, 'sslverify' => true));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['ok'])) {
            return new WP_Error('webhook_failed', $body['description'] ?? pwf_t('Failed to delete Telegram webhook.'));
        }
        return true;
    }

    public static function get_webhook_info($token = null) {
        $bot_token = $token ?: self::get_token();
        if (empty($bot_token)) {
            return array();
        }
        $url = self::API_BASE . $bot_token . '/getWebhookInfo';
        $response = wp_remote_get($url, array('timeout' => 10, 'sslverify' => true));
        if (is_wp_error($response)) {
            return array();
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['result'] ?? array();
    }

    public static function get_recent_chats($token = null) {
        $bot_token = $token ?: self::get_token();
        if (empty($bot_token)) {
            return array();
        }
        $url = self::API_BASE . $bot_token . '/getUpdates?limit=50';
        $response = wp_remote_get($url, array('timeout' => 10, 'sslverify' => true));
        if (is_wp_error($response)) {
            return array();
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['ok']) || empty($body['result'])) {
            return array();
        }
        $chats = array();
        foreach ($body['result'] as $update) {
            $msg = $update['message'] ?? $update['channel_post'] ?? $update['my_chat_member'] ?? null;
            if (!$msg || empty($msg['chat']['id'])) {
                continue;
            }
            $chat = $msg['chat'];
            $id = (string) $chat['id'];
            $type = $chat['type'] ?? 'private';
            $title = $chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
            $username = $chat['username'] ?? '';
            $chats[$id] = array(
                'id'       => $id,
                'title'    => $title ?: $id,
                'username' => $username,
                'type'     => $type,
            );
        }
        return array_values($chats);
    }

    public static function resolve_chat_id($chat_id) {
        $clean = trim((string) $chat_id);
        if (empty($clean)) {
            return '';
        }
        if (preg_match('/(?:https?:\/\/)?t\.me\/([a-zA-Z0-9_]+)/i', $clean, $m)) {
            $clean = $m[1];
        }
        if (preg_match('/^-?\d+$/', $clean)) {
            return $clean;
        }

        $username = ltrim($clean, '@');
        $recent = self::get_recent_chats();
        foreach ($recent as $rc) {
            if (!empty($rc['username']) && strcasecmp($rc['username'], $username) === 0) {
                return $rc['id'];
            }
        }

        return str_starts_with($clean, '@') ? $clean : ('@' . $clean);
    }

    public static function send_message($chat_id, $text, $keyboard = null, $parse_mode = 'HTML', $token = null) {
        $bot_token = $token ?: self::get_token();
        if (empty($bot_token)) {
            return new WP_Error('no_token', pwf_t('Telegram bot token is not configured.'));
        }
        if (empty($chat_id)) {
            return new WP_Error('no_chat_id', pwf_t('Chat ID is required.'));
        }

        $target_chat = self::resolve_chat_id($chat_id);
        $url = self::API_BASE . $bot_token . '/sendMessage';
        $body = array(
            'chat_id'                  => $target_chat,
            'text'                     => $text,
            'parse_mode'               => $parse_mode,
            'disable_web_page_preview' => true,
        );

        if (is_array($keyboard)) {
            $body['reply_markup'] = json_encode(array(
                'keyboard'          => $keyboard,
                'resize_keyboard'   => true,
                'one_time_keyboard' => false,
            ), JSON_UNESCAPED_UNICODE);
        } elseif ($keyboard === 'remove') {
            $body['reply_markup'] = json_encode(array('remove_keyboard' => true));
        }

        $response = wp_remote_post($url, array(
            'timeout'   => 10,
            'sslverify' => true,
            'body'      => $body,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_res = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body_res['ok'])) {
            $desc = $body_res['description'] ?? pwf_t('Telegram message could not be sent.');
            if (stripos($desc, 'chat not found') !== false) {
                $desc .= ' — ' . pwf_t('For private users, please enter your numerical Chat ID (e.g. 123456789), not your username or profile link. You must also send /start to your bot first. You can find your Chat ID by messaging @userinfobot on Telegram.');
            }
            return new WP_Error('telegram_send_failed', $desc);
        }

        return true;
    }

    public static function send_photo($chat_id, $photo, $caption = '', $parse_mode = 'HTML', $token = null) {
        $bot_token = $token ?: self::get_token();
        if (empty($bot_token)) {
            return new WP_Error('no_token', pwf_t('Telegram bot token is not configured.'));
        }
        if (empty($chat_id)) {
            return new WP_Error('no_chat_id', pwf_t('Chat ID is required.'));
        }

        $target_chat = self::resolve_chat_id($chat_id);
        $url = self::API_BASE . $bot_token . '/sendPhoto';
        $body = array(
            'chat_id'    => $target_chat,
            'photo'      => $photo,
            'caption'    => $caption,
            'parse_mode' => $parse_mode,
        );

        $response = wp_remote_post($url, array(
            'timeout'   => 15,
            'sslverify' => true,
            'body'      => $body,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_res = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body_res['ok'])) {
            $desc = $body_res['description'] ?? pwf_t('Telegram photo could not be sent.');
            return new WP_Error('telegram_send_photo_failed', $desc);
        }

        return true;
    }

    public static function send_test($chat_id) {
        $site = get_bloginfo('name');
        $time = current_time('mysql');
        $msg = "<b>Product Workflow Telegram Test</b>\n\n"
             . "Site: " . esc_html($site) . "\n"
             . "Timestamp: " . esc_html($time) . "\n"
             . "Status: Telegram integration is working properly!";
        return self::send_message($chat_id, $msg);
    }
}
