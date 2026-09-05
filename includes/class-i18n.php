<?php
defined('ABSPATH') || exit;

/** Plugin-only language preferences; product content and WordPress locale stay intact. */
class PWF_I18n {
    private static $catalogs = array();

    public static function languages() {
        return array(
            'auto'  => 'WordPress Default',
            'en_US' => 'English',
            'fa_IR' => 'فارسی',
            'it_IT' => 'Italiano',
        );
    }

    public static function locale() {
        $saved = get_user_meta(get_current_user_id(), 'pwf_language', true);
        if (is_string($saved) && $saved !== 'auto' && isset(self::languages()[$saved])) { return $saved; }
        $default = function_exists('get_option') ? get_option('pwf_default_language') : null;
        if (is_string($default) && $default !== 'auto' && isset(self::languages()[$default])) { return $default; }
        $locale = function_exists('get_user_locale') ? get_user_locale() : (function_exists('get_locale') ? get_locale() : 'en_US');
        if (strpos($locale, 'fa') === 0) { return 'fa_IR'; }
        if (strpos($locale, 'it') === 0) { return 'it_IT'; }
        return 'en_US';
    }

    public static function translate($text) {
        $locale = self::locale();
        if (!isset(self::$catalogs[$locale])) {
            $path = PWF_DIR . 'languages/' . $locale . '.json';
            self::$catalogs[$locale] = is_readable($path) ? (json_decode(file_get_contents($path), true) ?: array()) : array();
        }
        if (isset(self::$catalogs[$locale][$text])) {
            return self::$catalogs[$locale][$text];
        }
        // Native WordPress gettext domain fallback
        if (function_exists('get_translations_for_domain')) {
            $translations = get_translations_for_domain('product-workflow');
            $translation = $translations->translate($text);
            if (!empty($translation) && $translation !== $text) {
                return $translation;
            }
        }
        return $text;
    }

    public static function attributes() {
        $locale = self::locale();
        return ' lang="' . esc_attr(str_replace('_', '-', $locale)) . '" dir="' . ($locale === 'fa_IR' ? 'rtl' : 'ltr') . '"';
    }

    public static function selector() {
        echo '<form class="pwf-language" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . self::attributes() . '>';
        wp_nonce_field('pwf_language');
        echo '<input type="hidden" name="action" value="pwf_language"><label>' . esc_html(pwf_t('Interface language')) . ' <select name="language">';
        foreach (self::languages() as $locale => $label) {
            echo '<option value="' . esc_attr($locale) . '" ' . selected(self::locale(), $locale, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> <button class="button">' . esc_html(pwf_t('Apply language')) . '</button></form>';
    }

    public static function stage($stage) {
        $labels = array('photography' => 'Photography', 'content' => 'Content', 'review' => 'Review', 'social' => 'Social');
        return pwf_t($labels[$stage] ?? $stage);
    }

    public static function event($event) {
        $labels = array('created' => 'Workflow created', 'assignment' => 'Task assigned', 'transition' => 'Stage changed', 'content_saved' => 'Content saved', 'images_saved' => 'Images saved', 'image_uploaded' => 'Image uploaded', 'approval_reset' => 'Approval reset', 'publication_withdrawn' => 'Publication withdrawn', 'woocommerce_edit' => 'WooCommerce edit');
        return pwf_t($labels[$event] ?? $event);
    }

    public static function publication($status) {
        $labels = array('draft' => 'Draft', 'publish' => 'Published', 'pending' => 'Pending', 'private' => 'Private', 'trash' => 'Trash', 'future' => 'Scheduled');
        return pwf_t($labels[$status] ?? $status);
    }

    public static function history_note($entry) {
        // Human-authored review notes are never translated or rewritten.
        if ($entry->event === 'transition') { return $entry->note; }
        if ($entry->event === 'assignment' && preg_match('/^(\w+) assigned to user #(\d+) \(previous: #(\d+)\)\. Due: (.+)\.$/D', $entry->note, $parts)) {
            return sprintf(pwf_t('%s assigned to user #%d (previous: #%d). Due: %s.'), self::stage($parts[1]), $parts[2], $parts[3], $parts[4] === 'none' ? pwf_t('None') : $parts[4]);
        }
        if ($entry->event === 'image_uploaded' && preg_match('/^Image attachment #(\d+) uploaded\.$/D', $entry->note, $parts)) {
            return sprintf(pwf_t('Image attachment #%d uploaded.'), $parts[1]);
        }
        return pwf_t($entry->note);
    }

    public static function boot() {
        add_action('admin_post_pwf_language', function () {
            if (!current_user_can('view_assigned_products')) { wp_die(esc_html(pwf_t('Access denied.')), '', array('response' => 403)); }
            check_admin_referer('pwf_language');
            $locale = sanitize_text_field(wp_unslash($_POST['language'] ?? ''));
            if (!isset(self::languages()[$locale])) { wp_die(esc_html(pwf_t('Unsupported language.')), '', array('response' => 400)); }
            update_user_meta(get_current_user_id(), 'pwf_language', $locale);
            wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=pwf'));
            exit;
        });
        add_filter('gettext_with_context', function ($translated, $original, $context, $domain) {
            return $context === 'User role' && $domain === 'default' && in_array($original, array('Factory', 'Photographer', 'Content Manager', 'Product Reviewer', 'Social Media'), true) ? pwf_t($original) : $translated;
        }, 10, 4);
    }
}

function pwf_t($text) {
    return PWF_I18n::translate($text);
}
