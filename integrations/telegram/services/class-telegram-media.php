<?php
defined('ABSPATH') || exit;

/**
 * PWF_Telegram_Media
 *
 * Microservice handling Telegram photo downloads and WordPress Media Library attachment.
 */
class PWF_Telegram_Media {
    public static function download_and_attach_photo($file_id, $product_id, $token = null) {
        $bot_token = $token ?: PWF_Telegram_Client::get_token();
        if (empty($bot_token) || empty($file_id) || empty($product_id)) {
            return false;
        }

        // Get file path from Telegram
        $info_url = PWF_Telegram_Client::API_BASE . $bot_token . '/getFile?file_id=' . urlencode($file_id);
        $res = wp_remote_get($info_url, array('timeout' => 15, 'sslverify' => true));
        if (is_wp_error($res)) {
            return false;
        }

        $info = json_decode(wp_remote_retrieve_body($res), true);
        $file_path = $info['result']['file_path'] ?? '';
        if (empty($file_path)) {
            return false;
        }

        // Download actual bytes
        $download_url = PWF_Telegram_Client::FILE_BASE . $bot_token . '/' . $file_path;
        $file_res = wp_remote_get($download_url, array('timeout' => 30, 'sslverify' => true));
        if (is_wp_error($file_res)) {
            return false;
        }

        $bytes = wp_remote_retrieve_body($file_res);
        if (empty($bytes)) {
            return false;
        }

        $filename = 'pwf-product-' . $product_id . '-' . time() . '-' . wp_generate_password(4, false) . '.jpg';
        $upload = wp_upload_bits($filename, null, $bytes);
        if (!empty($upload['error'])) {
            return false;
        }

        $attachment = array(
            'post_mime_type' => 'image/jpeg',
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attach_id = wp_insert_attachment($attachment, $upload['file'], $product_id);
        if (!$attach_id || is_wp_error($attach_id)) {
            return false;
        }

        if (file_exists(ABSPATH . 'wp-admin/includes/image.php')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if (function_exists('wp_generate_attachment_metadata')) {
            $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
            wp_update_attachment_metadata($attach_id, $attach_data);
        }

        // Assign to product
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if ($product) {
            $main_img = (int) $product->get_image_id();
            if (!$main_img) {
                $product->set_image_id($attach_id);
            } else {
                $gallery = (array) $product->get_gallery_image_ids();
                $gallery[] = $attach_id;
                $product->set_gallery_image_ids(array_unique($gallery));
            }
            $product->save();
        }

        return $attach_id;
    }
}
