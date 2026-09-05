<?php
defined('ABSPATH') || exit;

/**
 * PWF_Telegram_Session
 *
 * Microservice managing conversation session states and multi-step wizard workflows.
 */
class PWF_Telegram_Session {
    public static function get($chat_id) {
        $data = get_transient('pwf_tg_session_' . $chat_id);
        return is_array($data) ? $data : array('step' => 'idle', 'data' => array());
    }

    public static function set($chat_id, $step, $data = array(), $ttl = 3600) {
        set_transient('pwf_tg_session_' . $chat_id, array('step' => $step, 'data' => $data), $ttl);
    }

    public static function clear($chat_id) {
        delete_transient('pwf_tg_session_' . $chat_id);
    }
}
