<?php
defined('ABSPATH') || exit;

class PWF_Settings {
    public static function boot() {
        add_action('admin_post_pwf_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_pwf_update_user_role', array(__CLASS__, 'update_user_role'));
    }

    public static function save_settings() {
        if (!current_user_can('manage_workflow')) {
            wp_die(pwf_t('Access denied.'), '', array('response' => 403));
        }
        check_admin_referer('pwf_save_settings');

        $lang = sanitize_text_field(wp_unslash($_POST['pwf_default_language'] ?? 'en_US'));
        if (isset(PWF_I18n::languages()[$lang])) {
            update_option('pwf_default_language', $lang);
            // Synchronize current user's personal interface preference as well
            update_user_meta(get_current_user_id(), 'pwf_language', $lang);
        }

        $enabled = !empty($_POST['pwf_telegram_enabled']) ? '1' : '0';
        update_option('pwf_telegram_enabled', $enabled);

        $token = sanitize_text_field(wp_unslash($_POST['pwf_telegram_bot_token'] ?? ''));
        update_option('pwf_telegram_bot_token', $token);

        $default_chat = sanitize_text_field(wp_unslash($_POST['pwf_telegram_default_chat_id'] ?? ''));
        update_option('pwf_telegram_default_chat_id', $default_chat);

        $events = array_map('sanitize_key', (array) ($_POST['pwf_telegram_events'] ?? array()));
        $valid_events = array('task_assigned', 'status_changed', 'created', 'content_saved', 'images_saved', 'image_uploaded');
        $filtered_events = array_values(array_intersect($events, $valid_events));
        update_option('pwf_telegram_events', $filtered_events);

        set_transient('pwf_notice_' . get_current_user_id(), array('error' => false, 'text' => pwf_t('Settings saved successfully.')), 60);
        wp_safe_redirect(admin_url('admin.php?page=pwf-settings'));
        exit;
    }

    public static function apply_user_role($user_id, $role, $telegram_chat_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('user_not_found', pwf_t('User not found.'));
        }

        $valid_roles = array('pwf_factory', 'pwf_photographer', 'pwf_content_manager', 'pwf_reviewer', 'pwf_social');
        if (in_array($role, $valid_roles, true)) {
            if ($user_id === get_current_user_id() && in_array('administrator', (array) $user->roles, true)) {
                // Do not remove administrator role from self
                $user->add_role($role);
            } else {
                $user->set_role($role);
            }
        } elseif ($role === 'remove_workflow') {
            foreach ($valid_roles as $vr) {
                $user->remove_role($vr);
            }
        }

        update_user_meta($user_id, 'pwf_telegram_chat_id', $telegram_chat_id);
        return true;
    }

    public static function update_user_role() {
        if (!current_user_can('manage_workflow') || (!current_user_can('promote_users') && !current_user_can('manage_options'))) {
            wp_die(pwf_t('Access denied.'), '', array('response' => 403));
        }
        check_admin_referer('pwf_update_user_role');

        $user_id = absint($_POST['target_user_id'] ?? 0);
        $role = sanitize_key($_POST['workflow_role'] ?? '');
        $telegram_chat_id = sanitize_text_field(wp_unslash($_POST['telegram_chat_id'] ?? ''));

        $res = self::apply_user_role($user_id, $role, $telegram_chat_id);
        if (is_wp_error($res)) {
            set_transient('pwf_notice_' . get_current_user_id(), array('error' => true, 'text' => $res->get_error_message()), 60);
        } else {
            set_transient('pwf_notice_' . get_current_user_id(), array('error' => false, 'text' => pwf_t('User role and Telegram settings updated successfully.')), 60);
        }

        wp_safe_redirect(admin_url('admin.php?page=pwf-settings#users'));
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_workflow')) {
            wp_die(pwf_t('Access denied.'));
        }

        global $wpdb;
        $current_lang = PWF_I18n::locale();
        $default_lang = get_option('pwf_default_language', 'en_US');
        $telegram_enabled = PWF_Telegram::is_enabled();
        $telegram_token = PWF_Telegram::get_token();
        $telegram_chat = PWF_Telegram::get_default_chat_id();
        $telegram_events = PWF_Telegram::get_events();

        $bot_info = null;
        $bot_error = null;
        if (!empty($telegram_token)) {
            $check = PWF_Telegram::get_me($telegram_token);
            if (is_wp_error($check)) {
                $bot_error = $check->get_error_message();
            } else {
                $bot_info = $check;
            }
        }

        // Fetch users
        $users = get_users(array('number' => 100, 'orderby' => 'display_name', 'order' => 'ASC'));
        $role_names = array(
            'pwf_factory'         => pwf_t('Factory'),
            'pwf_photographer'    => pwf_t('Photographer'),
            'pwf_content_manager' => pwf_t('Content Manager'),
            'pwf_reviewer'        => pwf_t('Product Reviewer'),
            'pwf_social'          => pwf_t('Social Media'),
            'administrator'       => pwf_t('Administrator'),
            'shop_manager'        => pwf_t('Shop Manager'),
        );

        // Fetch active assignment counts per user
        $counts_raw = $wpdb->get_results("SELECT user_id, COUNT(*) as active_tasks FROM {$wpdb->prefix}pwf_assignments WHERE completed_at IS NULL GROUP BY user_id");
        $user_task_counts = array();
        foreach ((array) $counts_raw as $cr) {
            $user_task_counts[$cr->user_id] = (int) $cr->active_tasks;
        }

        echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';

        // Hero Banner
        echo '<div class="pwf-hero">';
        echo '<div class="pwf-hero-title">';
        echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-admin-settings"></span></div>';
        echo '<div>';
        echo '<h1>' . esc_html(pwf_t('Workflow Settings')) . '</h1>';
        echo '<p>' . esc_html(pwf_t('Configure system languages, Telegram notifications, and team roles.')) . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<div class="pwf-hero-actions">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf')) . '" class="button pwf-btn-subtle">&larr; ' . esc_html(pwf_t('Open workflow dashboard')) . '</a>';
        echo '</div>';
        echo '</div>';

        PWF_Admin::notice();

        // Modern Navigation Tabs
        echo '<div class="pwf-settings-nav">';
        echo '<a href="#languages" class="active"><span class="dashicons dashicons-translation"></span> ' . esc_html(pwf_t('Languages')) . '</a>';
        echo '<a href="#telegram"><span class="dashicons dashicons-format-chat"></span> ' . esc_html(pwf_t('Telegram API')) . '</a>';
        echo '<a href="#users"><span class="dashicons dashicons-groups"></span> ' . esc_html(pwf_t('Team & Roles')) . '</a>';
        echo '</div>';

        // MAIN SETTINGS FORM
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pwf_save_settings');
        echo '<input type="hidden" name="action" value="pwf_save_settings">';

        // SECTION 1: LANGUAGES
        echo '<div id="languages" class="pwf-card">';
        echo '<h2><span class="dashicons dashicons-translation" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Languages')) . '</h2>';
        echo '<p style="color:var(--pwf-slate-600);">' . esc_html(pwf_t('Choose the default workflow language for all team members. Individual users can still switch their preferred interface language.')) . '</p>';

        $lang_meta = array(
            'en_US' => array('name' => 'English', 'flag' => '🇺🇸', 'dir' => 'LTR', 'desc' => 'English (United States)'),
            'fa_IR' => array('name' => 'فارسی', 'flag' => '🇮🇷', 'dir' => 'RTL', 'desc' => 'Persian (Iran)'),
            'it_IT' => array('name' => 'Italiano', 'flag' => '🇮🇹', 'dir' => 'LTR', 'desc' => 'Italian (Italy)'),
        );

        echo '<div class="pwf-lang-cards">';
        foreach ($lang_meta as $code => $info) {
            $is_active = ($current_lang === $code || $default_lang === $code);
            echo '<label class="pwf-lang-card ' . ($is_active ? 'is-active' : '') . '">';
            echo '<input type="radio" name="pwf_default_language" value="' . esc_attr($code) . '" ' . checked($default_lang, $code, false) . ' class="pwf-lang-radio">';
            echo '<div class="pwf-lang-flag">' . esc_html($info['flag']) . '</div>';
            echo '<div class="pwf-lang-info">';
            echo '<h4>' . esc_html($info['name']) . '</h4>';
            echo '<span>' . esc_html($info['desc']) . '</span>';
            echo '</div>';
            echo '<span class="pwf-lang-badge">' . esc_html($info['dir']) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var c=document.querySelectorAll(".pwf-lang-card");c.forEach(function(el){el.addEventListener("click",function(){c.forEach(function(x){x.classList.remove("is-active");});el.classList.add("is-active");var r=el.querySelector("input[type=radio]");if(r)r.checked=true;});});});</script>';
        echo '</div>'; // languages card

        // SECTION 2: TELEGRAM API
        echo '<div id="telegram" class="pwf-card">';
        echo '<h2><span class="dashicons dashicons-format-chat" style="color:var(--pwf-secondary);"></span> ' . esc_html(pwf_t('Telegram Bot API')) . '</h2>';
        echo '<p style="color:var(--pwf-slate-600);">' . esc_html(pwf_t('Enable automated Telegram alerts for workflow transitions, assignments, and product reviews.')) . '</p>';

        if ($bot_info) {
            echo '<div style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:var(--pwf-success-light); border:1px solid var(--pwf-success-border); border-radius:var(--pwf-radius-sm); margin-bottom:20px;">';
            echo '<span class="pwf-pulse-dot"></span>';
            echo '<div><strong style="color:var(--pwf-success-hover);">' . esc_html(pwf_t('Connected')) . ':</strong> @' . esc_html($bot_info['username'] ?? '') . ' (' . esc_html($bot_info['first_name'] ?? '') . ')</div>';
            echo '</div>';
        } elseif ($bot_error) {
            echo '<div style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:var(--pwf-danger-light); border:1px solid var(--pwf-danger-border); border-radius:var(--pwf-radius-sm); margin-bottom:20px;">';
            echo '<span style="color:var(--pwf-danger); font-size:18px;">✕</span>';
            echo '<div><strong style="color:var(--pwf-danger);">' . esc_html(pwf_t('Error')) . ':</strong> ' . esc_html($bot_error) . '</div>';
            echo '</div>';
        }

        echo '<table class="form-table" style="margin-top:0;"><tbody>';
        echo '<tr>';
        echo '<th scope="row">' . esc_html(pwf_t('Telegram Notifications')) . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="pwf_telegram_enabled" value="1" ' . checked($telegram_enabled, true, false) . '> <strong>' . esc_html(pwf_t('Enable Telegram notifications')) . '</strong></label>';
        echo '</td></tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="pwf_telegram_bot_token">' . esc_html(pwf_t('Bot Token')) . '</label></th>';
        echo '<td>';
        echo '<input type="password" id="pwf_telegram_bot_token" name="pwf_telegram_bot_token" value="' . esc_attr($telegram_token) . '" class="regular-text" style="width:100%; max-width:480px;" autocomplete="off"> ';
        echo '<p class="description">' . esc_html(pwf_t('Obtained from @BotFather on Telegram (e.g., 123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ).')) . '</p>';
        echo '</td></tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="pwf_telegram_default_chat_id">' . esc_html(pwf_t('Default Chat / Channel ID')) . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="pwf_telegram_default_chat_id" name="pwf_telegram_default_chat_id" value="' . esc_attr($telegram_chat) . '" class="regular-text" style="width:100%; max-width:480px;"> ';
        echo '<p class="description">' . esc_html(pwf_t('Group, channel, or manager Telegram Chat ID where global workflow updates will be broadcast.')) . '<br>' . esc_html(pwf_t('Important: For private users, Telegram requires a numerical Chat ID (e.g. 123456789). Telegram usernames (@username) or profile links (t.me/...) only work for public channels. To find your numerical Chat ID, message @userinfobot on Telegram, or open your bot and send /start.')) . '</p>';
        echo '</td></tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html(pwf_t('Notification Events')) . '</th>';
        echo '<td><fieldset>';
        $event_options = array(
            'task_assigned'  => pwf_t('Task assigned to a team member'),
            'status_changed' => pwf_t('Workflow stage / status changed'),
            'created'        => pwf_t('New product workflow created'),
            'content_saved'  => pwf_t('Product content updated'),
            'images_saved'   => pwf_t('Product images updated'),
        );
        foreach ($event_options as $ev_key => $ev_label) {
            $is_checked = in_array($ev_key, $telegram_events, true);
            echo '<label style="display:block; margin-bottom: 8px;"><input type="checkbox" name="pwf_telegram_events[]" value="' . esc_attr($ev_key) . '" ' . checked($is_checked, true, false) . '> ' . esc_html($ev_label) . '</label>';
        }
        echo '</fieldset></td></tr>';
        echo '</tbody></table>';

        echo '<div style="margin-top:20px;">';
        submit_button(pwf_t('Save Settings'), 'primary', 'submit', false);
        echo '</div>';
        echo '</form>'; // end main settings form

        // Test Connection Box
        echo '<div class="pwf-test-box" style="margin-top: 24px; background: var(--pwf-slate-50); border: 1px solid var(--pwf-slate-200); border-radius: var(--pwf-radius-sm); padding: 18px;">';
        echo '<h3 style="margin:0 0 8px 0; font-size:15px;"><span class="dashicons dashicons-email-alt" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Test Telegram Connection')) . '</h3>';
        echo '<p style="margin:0 0 12px 0; font-size:13px; color:var(--pwf-slate-600);">' . esc_html(pwf_t('Send an immediate test alert to verify your Bot Token and Chat ID.')) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
        wp_nonce_field('pwf_test_telegram');
        echo '<input type="hidden" name="action" value="pwf_test_telegram">';
        echo '<label>' . esc_html(pwf_t('Target Chat ID')) . ': <input type="text" name="test_chat_id" value="' . esc_attr($telegram_chat) . '" placeholder="' . esc_attr(pwf_t('Chat ID')) . '"></label>';
        submit_button(pwf_t('Send Test Message'), 'secondary', 'submit', false);
        echo '</form>';

        $recent_chats = !empty($telegram_token) ? PWF_Telegram::get_recent_chats() : array();
        if (!empty($recent_chats)) {
            echo '<div style="margin-top:16px; padding:12px; background:#ffffff; border:1px solid var(--pwf-slate-200); border-radius:var(--pwf-radius-sm);">';
            echo '<strong>' . esc_html(pwf_t('Recent chats detected from bot')) . ':</strong>';
            echo '<ul style="margin:6px 0 0 16px;">';
            foreach ($recent_chats as $rc) {
                $u_label = $rc['username'] ? ('@' . $rc['username'] . ' (' . $rc['title'] . ')') : $rc['title'];
                echo '<li>' . esc_html($u_label) . ' &rarr; <code>' . esc_html($rc['id']) . '</code></li>';
            }
            echo '</ul>';
            echo '</div>';
        }
        echo '</div>'; // test box

        // Webhook Management Box
        $webhook_url = PWF_Telegram::get_webhook_url();
        $webhook_info = !empty($telegram_token) ? PWF_Telegram::get_webhook_info() : array();
        $is_webhook_set = !empty($webhook_info['url']);

        echo '<div class="pwf-webhook-box" style="margin-top: 24px; background: #ffffff; border: 1px solid var(--pwf-slate-200); border-radius: var(--pwf-radius-sm); padding: 18px;">';
        echo '<h3 style="margin:0 0 8px 0; font-size:15px;"><span class="dashicons dashicons-rest-api" style="color:var(--pwf-secondary);"></span> ' . esc_html(pwf_t('Interactive Telegram Bot (Add Products & Manage Workflow from Telegram)')) . '</h3>';
        echo '<p style="margin:0 0 14px 0; font-size:13px; color:var(--pwf-slate-600);">' . esc_html(pwf_t('Allow team members to create new products and upload photos directly inside Telegram without accessing the WordPress dashboard.')) . '</p>';

        echo '<table class="form-table" style="margin-top:0;"><tbody>';
        echo '<tr><th scope="row" style="width:200px;">' . esc_html(pwf_t('Webhook URL')) . '</th>';
        echo '<td><code>' . esc_html($webhook_url) . '</code>';
        echo '<p class="description">' . esc_html(pwf_t('Telegram sends user messages and photos to this secure HTTPS REST API endpoint.')) . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html(pwf_t('Webhook Status')) . '</th>';
        echo '<td>';
        if ($is_webhook_set) {
            echo '<span class="pwf-badge pwf-badge-success">✓ ' . esc_html(pwf_t('Active')) . ' (' . esc_html($webhook_info['url']) . ')</span>';
            if (!empty($webhook_info['last_error_message'])) {
                echo '<br><span class="pwf-badge pwf-badge-error" style="margin-top:6px;">' . esc_html(sprintf(pwf_t('Last webhook error: %s'), $webhook_info['last_error_message'])) . '</span>';
            }
        } else {
            echo '<span class="pwf-badge pwf-badge-default">' . esc_html(pwf_t('Inactive / Not registered')) . '</span>';
        }
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<div style="display:flex; gap:10px; margin-top:16px; flex-wrap:wrap;">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pwf_set_telegram_webhook');
        echo '<input type="hidden" name="action" value="pwf_set_telegram_webhook">';
        submit_button(pwf_t('Enable Webhook (Activate Bot Commands)'), 'primary', 'submit', false);
        echo '</form>';

        if ($is_webhook_set) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('pwf_delete_telegram_webhook');
            echo '<input type="hidden" name="action" value="pwf_delete_telegram_webhook">';
            submit_button(pwf_t('Delete Webhook'), 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</div>'; // flex
        echo '</div>'; // webhook box
        echo '</div>'; // telegram card

        // SECTION 3: TEAM & ROLES
        echo '<div id="users" class="pwf-card">';
        echo '<h2><span class="dashicons dashicons-groups" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Team & Role Management')) . '</h2>';
        echo '<p style="color:var(--pwf-slate-600);">' . esc_html(pwf_t('Check your team members, assign their workflow roles, and configure their personal Telegram Chat IDs for direct task alerts.')) . '</p>';

        echo '<div class="pwf-grid" style="grid-template-columns: minmax(0, 2fr) minmax(320px, 1fr);">';

        // Users Table Column
        echo '<div>';
        echo '<h3 style="margin:0 0 12px 0;">' . esc_html(pwf_t('Team Members')) . '</h3>';
        echo '<div class="pwf-table-container">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html(pwf_t('User')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Workflow Role')) . '</th>';
        echo '<th style="text-align:center;">' . esc_html(pwf_t('Active Tasks')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Telegram Chat ID')) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($users as $u) {
            $user_roles = (array) $u->roles;
            $workflow_role_label = pwf_t('None');
            $role_badge_class = 'pwf-badge-default';

            foreach ($user_roles as $ur) {
                if (isset($role_names[$ur])) {
                    $workflow_role_label = $role_names[$ur];
                    if (in_array($ur, array('administrator', 'shop_manager'), true)) {
                        $role_badge_class = 'pwf-badge-admin';
                    } else {
                        $role_badge_class = 'pwf-badge-role';
                    }
                    break;
                }
            }

            $t_chat = get_user_meta($u->ID, 'pwf_telegram_chat_id', true);
            $task_count = $user_task_counts[$u->ID] ?? 0;

            echo '<tr>';
            echo '<td>';
            echo '<strong>' . esc_html($u->display_name) . '</strong><br>';
            echo '<span class="description">@' . esc_html($u->user_login) . ' &bull; ' . esc_html($u->user_email) . '</span>';
            echo '</td>';
            echo '<td><span class="pwf-badge ' . esc_attr($role_badge_class) . '">' . esc_html($workflow_role_label) . '</span></td>';
            echo '<td style="text-align:center;">' . ($task_count > 0 ? '<span class="pwf-badge pwf-badge-admin">' . esc_html($task_count) . '</span>' : '<span style="color:var(--pwf-slate-400);">0</span>') . '</td>';
            echo '<td>' . ($t_chat ? '<code>' . esc_html($t_chat) . '</code>' : '<span class="description">—</span>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>'; // table container
        echo '</div>'; // col 1

        // Assign Role & Telegram Form Column
        echo '<div>';
        echo '<h3 style="margin:0 0 12px 0;">' . esc_html(pwf_t('Assign Role & Telegram ID')) . '</h3>';
        echo '<div style="background:var(--pwf-slate-50); border:1px solid var(--pwf-slate-200); padding:20px; border-radius:var(--pwf-radius-md);">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pwf_update_user_role');
        echo '<input type="hidden" name="action" value="pwf_update_user_role">';

        echo '<p><label><strong>' . esc_html(pwf_t('Select User')) . ':</strong><br>';
        echo '<select name="target_user_id" style="width:100%; margin-top:6px;" required>';
        echo '<option value="">— ' . esc_html(pwf_t('Choose user')) . ' —</option>';
        foreach ($users as $u) {
            echo '<option value="' . esc_attr($u->ID) . '">' . esc_html($u->display_name) . ' (@' . esc_html($u->user_login) . ')</option>';
        }
        echo '</select></label></p>';

        echo '<p><label><strong>' . esc_html(pwf_t('Workflow Role')) . ':</strong><br>';
        echo '<select name="workflow_role" style="width:100%; margin-top:6px;" required>';
        echo '<option value="pwf_factory">' . esc_html(pwf_t('Factory')) . '</option>';
        echo '<option value="pwf_photographer">' . esc_html(pwf_t('Photographer')) . '</option>';
        echo '<option value="pwf_content_manager">' . esc_html(pwf_t('Content Manager')) . '</option>';
        echo '<option value="pwf_reviewer">' . esc_html(pwf_t('Product Reviewer')) . '</option>';
        echo '<option value="pwf_social">' . esc_html(pwf_t('Social Media')) . '</option>';
        echo '<option value="remove_workflow">' . esc_html(pwf_t('Remove Workflow Role')) . '</option>';
        echo '</select></label></p>';

        echo '<p><label><strong>' . esc_html(pwf_t('Personal Telegram Chat ID')) . ':</strong><br>';
        echo '<input type="text" name="telegram_chat_id" style="width:100%; margin-top:6px;" placeholder="' . esc_attr(pwf_t('e.g. 123456789')) . '">';
        echo '<span class="description" style="margin-top:6px; display:block;">' . esc_html(pwf_t('Task notifications for this user will be delivered to this Telegram Chat ID.')) . '<br>' . esc_html(pwf_t('Important: For private users, Telegram requires a numerical Chat ID (e.g. 123456789). Telegram usernames (@username) or profile links (t.me/...) only work for public channels. To find your numerical Chat ID, message @userinfobot on Telegram, or open your bot and send /start.')) . '</span>';
        echo '</label></p>';

        echo '<p style="margin-top:16px;">';
        submit_button(pwf_t('Update Role & Telegram'), 'primary', 'submit', true, array('style' => 'width:100%;'));
        echo '</p>';
        echo '</form>';
        echo '</div>'; // form box
        echo '</div>'; // col 2

        echo '</div>'; // grid
        echo '</div>'; // users card

        echo '</div>'; // wrap
    }
}

function pwf_settings_page() {
    PWF_Settings::render();
}
