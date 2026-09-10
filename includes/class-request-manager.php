<?php
defined('ABSPATH') || exit;

/**
 * PWF_Request_Manager — handles high-level Requests submitted by CEO, Factory Secretary, or Sales Manager.
 *
 * Lifecycle: submitted → in_review → planned → completed  (or rejected → archived/reopened)
 */
class PWF_Request_Manager {

    /** All valid request statuses. */
    public static function statuses() {
        return apply_filters('pwf_request_statuses', array(
            'submitted' => pwf_t('Submitted'),
            'in_review' => pwf_t('In Review'),
            'planned'   => pwf_t('Planned / Converted'),
            'rejected'  => pwf_t('Rejected'),
            'completed' => pwf_t('Completed'),
        ));
    }

    /** All valid request types. */
    public static function types() {
        return apply_filters('pwf_request_types', array(
            'new_product'        => pwf_t('New Product Registration'),
            'production_followup'=> pwf_t('Production Batch Follow-up'),
            'photography'        => pwf_t('Product Photography'),
            'content'            => pwf_t('Content Preparation'),
            'publishing'         => pwf_t('Website Product Publishing'),
            'social_media'       => pwf_t('Social Media & Telegram Post'),
            'print_file'         => pwf_t('Print File Preparation'),
            'programming'        => pwf_t('Programming & App Development'),
            'general'            => pwf_t('Custom / General Request'),
        ));
    }

    /** Valid priority levels (shared with tasks). */
    public static function priorities() {
        return array(
            'urgent' => pwf_t('Urgent'),
            'high'   => pwf_t('High'),
            'normal' => pwf_t('Normal'),
            'low'    => pwf_t('Low'),
        );
    }

    /**
     * Create a new request.
     *
     * @param array $data  title, description, request_type, priority, product_id (optional)
     * @return object|WP_Error  The inserted row or error.
     */
    public static function create($data) {
        if (!current_user_can('create_workflow_request') && !current_user_can('create_product_request')) {
            return new WP_Error('forbidden', pwf_t('You do not have permission to submit requests.'), array('status' => 403));
        }
        $title = sanitize_text_field($data['title'] ?? '');
        if (!$title) {
            return new WP_Error('title_required', pwf_t('Request title is required.'), array('status' => 400));
        }
        $description = wp_kses_post($data['description'] ?? '');
        $type        = sanitize_key($data['request_type'] ?? 'general');
        if (!isset(self::types()[$type])) { $type = 'general'; }
        $priority    = sanitize_key($data['priority'] ?? 'normal');
        if (!isset(self::priorities()[$priority])) { $priority = 'normal'; }
        $product_id  = !empty($data['product_id']) ? absint($data['product_id']) : null;

        // Process attachments (photos, PDFs, docs, ZIPs)
        $uploaded_attachments = class_exists('PWF_Task_Manager') ? PWF_Task_Manager::upload_files('request_attachments') : array();
        $attachments_json     = !empty($uploaded_attachments) ? wp_json_encode($uploaded_attachments) : null;

        global $wpdb;
        $now = current_time('mysql', true);
        $ok  = $wpdb->insert($wpdb->prefix . 'pwf_requests', array(
            'title'        => $title,
            'description'  => $description,
            'request_type' => $type,
            'priority'     => $priority,
            'status'       => 'submitted',
            'product_id'   => $product_id,
            'attachments'  => $attachments_json,
            'submitted_by' => get_current_user_id(),
            'created_at'   => $now,
            'updated_at'   => $now,
        ));
        if (!$ok) {
            return new WP_Error('db_error', pwf_t('Request could not be saved. Please retry.'), array('status' => 500));
        }
        $request_id = (int) $wpdb->insert_id;
        self::log($request_id, 'created', '', 'submitted', pwf_t('Request submitted.'));

        // Notify management channel via Telegram
        if (PWF_Telegram::is_enabled()) {
            $submitter = get_userdata(get_current_user_id());
            $admin_url = admin_url('admin.php?page=pwf-request&request_id=' . $request_id);
            $type_label = self::types()[$type] ?? $type;
            $prio_label = self::priorities()[$priority] ?? $priority;
            $att_count  = count($uploaded_attachments);

            $msg = "📥 <b>" . esc_html(pwf_t('New Request Submitted')) . "</b>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━\n";
            $msg .= "📌 <b>#" . $request_id . ":</b> " . esc_html($title) . "\n";
            $msg .= "👤 <b>" . esc_html(pwf_t('Submitted by')) . ":</b> " . esc_html($submitter ? $submitter->display_name : '—') . "\n";
            $msg .= "🎯 <b>" . esc_html(pwf_t('Type')) . ":</b> " . esc_html($type_label) . "\n";
            $msg .= "⚡ <b>" . esc_html(pwf_t('Priority')) . ":</b> " . esc_html($prio_label) . "\n";
            if ($description) {
                $desc_short = wp_strip_all_tags($description);
                if (mb_strlen($desc_short) > 160) { $desc_short = mb_substr($desc_short, 0, 160) . '...'; }
                $msg .= "📝 <b>" . esc_html(pwf_t('Description')) . ":</b> " . esc_html($desc_short) . "\n";
            }
            if ($att_count > 0) {
                $msg .= "📎 <b>" . sprintf(esc_html(pwf_t('Attachments (%d files/photos)')), $att_count) . ":</b>\n";
                foreach (array_slice($uploaded_attachments, 0, 3) as $f) {
                    $msg .= "  • <a href=\"" . esc_url($f['url']) . "\">" . esc_html($f['name']) . "</a>\n";
                }
                if ($att_count > 3) {
                    $msg .= "  • ... (" . ($att_count - 3) . " " . esc_html(pwf_t('more')) . ")\n";
                }
            }
            $msg .= "━━━━━━━━━━━━━━━━━━\n";
            $msg .= "🔍 <a href=\"" . esc_url($admin_url) . "\">" . esc_html(pwf_t('Review in Admin')) . "</a>";

            $mgmt_chat = get_option('pwf_telegram_default_chat_id', '');
            if ($mgmt_chat) { PWF_Telegram::send_message($mgmt_chat, $msg); }
        }

        return self::get($request_id);
    }

    /**
     * Get a single request row.
     */
    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pwf_requests WHERE request_id=%d", $id
        ));
    }

    /**
     * List requests with optional filters.
     *
     * @param array $args  status, submitted_by, type, priority, search, page, per_page
     * @return array  ['rows' => [], 'total' => int]
     */
    public static function list($args = array()) {
        global $wpdb;
        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, (int) ($args['per_page'] ?? 20));
        $where    = '1=1';
        $params   = array();

        if (!empty($args['status']) && isset(self::statuses()[$args['status']])) {
            $where   .= ' AND r.status=%s';
            $params[] = $args['status'];
        }
        if (!empty($args['submitted_by'])) {
            $where   .= ' AND r.submitted_by=%d';
            $params[] = absint($args['submitted_by']);
        }
        if (!empty($args['type']) && isset(self::types()[$args['type']])) {
            $where   .= ' AND r.request_type=%s';
            $params[] = $args['type'];
        }
        if (!empty($args['priority']) && isset(self::priorities()[$args['priority']])) {
            $where   .= ' AND r.priority=%s';
            $params[] = $args['priority'];
        }
        if (!empty($args['search'])) {
            $like     = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $where   .= ' AND r.title LIKE %s';
            $params[] = $like;
        }
        // Non-management users see only their own requests
        if (!current_user_can('manage_workflow') && !current_user_can('view_executive_dashboard')) {
            $where   .= ' AND r.submitted_by=%d';
            $params[] = get_current_user_id();
        }

        $base_sql = "FROM {$wpdb->prefix}pwf_requests r WHERE $where";
        $total    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $base_sql", ...$params));
        $offset   = ($page - 1) * $per_page;
        $rows     = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name AS submitter_name
             $base_sql
             ORDER BY r.created_at DESC
             LIMIT %d OFFSET %d",
            ...[...$params, $per_page, $offset]
        ));
        return array('rows' => $rows ?: array(), 'total' => $total);
    }

    /**
     * Transition the status of a request.
     *
     * @param int    $id          Request ID
     * @param string $new_status  Target status key
     * @param string $note        Optional note (required for 'rejected')
     * @return object|WP_Error
     */
    public static function update_status($id, $new_status, $note = '') {
        if (!current_user_can('manage_workflow')) {
            return new WP_Error('forbidden', pwf_t('Only Management & Planning can update request status.'), array('status' => 403));
        }
        if (!isset(self::statuses()[$new_status])) {
            return new WP_Error('invalid_status', pwf_t('Unknown request status.'), array('status' => 400));
        }
        $req = self::get($id);
        if (!$req) {
            return new WP_Error('not_found', pwf_t('Request not found.'), array('status' => 404));
        }
        $note = sanitize_textarea_field($note);
        if ($new_status === 'rejected' && trim($note) === '') {
            return new WP_Error('note_required', pwf_t('A rejection requires a written reason.'), array('status' => 400));
        }
        global $wpdb;
        $ok = $wpdb->update(
            $wpdb->prefix . 'pwf_requests',
            array('status' => $new_status, 'updated_at' => current_time('mysql', true)),
            array('request_id' => $id)
        );
        if ($ok === false) {
            return new WP_Error('db_error', pwf_t('Status could not be updated. Please retry.'));
        }
        self::log($id, 'status_change', $req->status, $new_status, $note);

        // Notify submitter via Telegram
        if (PWF_Telegram::is_enabled()) {
            $chat_id = get_user_meta($req->submitted_by, 'pwf_telegram_chat_id', true);
            if ($chat_id) {
                $msg = sprintf(
                    pwf_t("🔔 <b>Request Update</b>\n#%d: %s\nNew status: %s%s"),
                    $id, $req->title,
                    self::statuses()[$new_status] ?? $new_status,
                    $note ? "\nNote: $note" : ''
                );
                PWF_Telegram::send_message($chat_id, $msg);
            }
        }

        return self::get($id);
    }

    /**
     * Get audit log entries for a request.
     */
    public static function history($id, $page = 1) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pwf_request_history WHERE request_id=%d ORDER BY log_id DESC LIMIT 50 OFFSET %d",
            $id, (max(1, $page) - 1) * 50
        ));
    }

    /**
     * Write an immutable audit log entry.
     */
    public static function log($id, $event, $from, $to, $note = '') {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'pwf_request_history', array(
            'request_id'      => $id,
            'event'           => $event,
            'previous_status' => $from,
            'new_status'      => $to,
            'changed_by'      => get_current_user_id(),
            'changed_at'      => current_time('mysql', true),
            'note'            => sanitize_textarea_field($note),
        ));
        if (!$ok) {
            throw new RuntimeException(pwf_t('The audit record could not be saved. Please retry.'));
        }
    }

    /**
     * Get linked tasks for a request.
     */
    public static function tasks($request_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pwf_tasks WHERE request_id=%d ORDER BY task_id",
            $request_id
        ));
    }

    /**
     * Auto-complete request if all its child tasks are approved.
     */
    public static function maybe_complete($request_id) {
        global $wpdb;
        $req = self::get($request_id);
        if (!$req || $req->status === 'completed') { return; }
        $open = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pwf_tasks
             WHERE request_id=%d AND status NOT IN ('approved','cancelled')",
            $request_id
        ));
        if ($open === 0) {
            $wpdb->update(
                $wpdb->prefix . 'pwf_requests',
                array('status' => 'completed', 'updated_at' => current_time('mysql', true)),
                array('request_id' => $request_id)
            );
            self::log($request_id, 'auto_completed', $req->status, 'completed', pwf_t('All child tasks approved. Request auto-completed.'));
        }
    }
}

