<?php
defined('ABSPATH') || exit;

/**
 * PWF_Task_Manager — manages granular execution Tasks per the spec's 9 task types.
 *
 * Lifecycle:
 *   assigned → in_progress → submitted → approved (or needs_revision → in_progress)
 *   Any active task can be → on_hold or → cancelled
 */
class PWF_Task_Manager {

    /** Valid task statuses. */
    public static function statuses() {
        return apply_filters('pwf_task_statuses', array(
            'assigned'       => pwf_t('Assigned'),
            'in_progress'    => pwf_t('In Progress'),
            'on_hold'        => pwf_t('On Hold'),
            'submitted'      => pwf_t('Submitted'),
            'needs_revision' => pwf_t('Needs Revision'),
            'approved'       => pwf_t('Approved / Completed'),
            'cancelled'      => pwf_t('Cancelled'),
        ));
    }

    /** All 9 spec task types mapped to default assignee capability. */
    public static function types() {
        return apply_filters('pwf_task_types', array(
            'new_product'         => array('label' => pwf_t('New Product Registration'),      'cap' => 'create_product_request'),
            'production_followup' => array('label' => pwf_t('Production Batch Follow-up'),    'cap' => 'create_product_request'),
            'photography'         => array('label' => pwf_t('Product Photography'),            'cap' => 'complete_photography_task'),
            'content'             => array('label' => pwf_t('Content Preparation'),            'cap' => 'complete_content_task'),
            'publishing'          => array('label' => pwf_t('Website Product Publishing'),    'cap' => 'complete_content_task'),
            'social_media'        => array('label' => pwf_t('Social Media & Telegram Post'), 'cap' => 'complete_social_task'),
            'print_file'          => array('label' => pwf_t('Print File Preparation'),        'cap' => 'complete_photography_task'),
            'programming'         => array('label' => pwf_t('Programming & App Development'), 'cap' => 'complete_programming_task'),
            'custom'              => array('label' => pwf_t('Custom Management Task'),        'cap' => 'execute_workflow_task'),
        ));
    }

    /** Priority levels (shared with Requests). */
    public static function priorities() {
        return PWF_Request_Manager::priorities();
    }

    /**
     * Create a task (Management & Planning only).
     *
     * @param array $data  title, description, task_type, assigned_to, priority, due_date,
     *                     request_id (optional), product_id (optional), self_closing (optional)
     * @return object|WP_Error
     */
    public static function create($data) {
        if (!current_user_can('assign_tasks')) {
            return new WP_Error('forbidden', pwf_t('Only Management & Planning can create tasks.'), array('status' => 403));
        }
        $title = sanitize_text_field($data['title'] ?? '');
        if (!$title) {
            return new WP_Error('title_required', pwf_t('Task title is required.'), array('status' => 400));
        }
        $type = sanitize_key($data['task_type'] ?? 'custom');
        if (!isset(self::types()[$type])) { $type = 'custom'; }

        $priority = sanitize_key($data['priority'] ?? 'normal');
        if (!isset(self::priorities()[$priority])) { $priority = 'normal'; }

        $due_date = sanitize_text_field($data['due_date'] ?? '');
        if ($due_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due_date)) {
            return new WP_Error('invalid_date', pwf_t('Due date must be YYYY-MM-DD.'), array('status' => 400));
        }
        if (!$due_date) {
            return new WP_Error('due_date_required', pwf_t('A due date is required for all tasks.'), array('status' => 400));
        }

        $assigned_to = absint($data['assigned_to'] ?? 0) ?: null;
        $request_id  = !empty($data['request_id']) ? absint($data['request_id']) : null;
        $product_id  = !empty($data['product_id']) ? absint($data['product_id']) : null;
        $description = wp_kses_post($data['description'] ?? '');
        $self_closing = !empty($data['self_closing']) ? 1 : 0;

        // Process attachments (photos, PDFs, docs, ZIPs)
        $uploaded_attachments = self::upload_files('task_attachments');
        $attachments_json     = !empty($uploaded_attachments) ? wp_json_encode($uploaded_attachments) : null;

        global $wpdb;
        $now = current_time('mysql', true);
        $ok  = $wpdb->insert($wpdb->prefix . 'pwf_tasks', array(
            'request_id'        => $request_id,
            'product_id'        => $product_id,
            'task_type'         => $type,
            'title'             => $title,
            'description'       => $description,
            'assigned_to'       => $assigned_to,
            'priority'          => $priority,
            'status'            => 'assigned',
            'due_date'          => $due_date ?: null,
            'deliverable_url'   => '',
            'deliverable_note'  => '',
            'attachments'       => $attachments_json,
            'deliverable_files' => null,
            'self_closing'      => $self_closing,
            'created_by'        => get_current_user_id(),
            'created_at'        => $now,
            'updated_at'        => $now,
        ));
        if (!$ok) {
            return new WP_Error('db_error', pwf_t('Task could not be saved. Please retry.'), array('status' => 500));
        }
        $task_id = (int) $wpdb->insert_id;
        self::log($task_id, 'created', '', 'assigned', pwf_t('Task created and assigned.'));

        // Update linked request to "planned" if still submitted/in_review
        if ($request_id) {
            $req = PWF_Request_Manager::get($request_id);
            if ($req && in_array($req->status, array('submitted', 'in_review'), true)) {
                $wpdb->update(
                    $wpdb->prefix . 'pwf_requests',
                    array('status' => 'planned', 'updated_at' => $now),
                    array('request_id' => $request_id)
                );
                PWF_Request_Manager::log($request_id, 'planned', $req->status, 'planned', pwf_t('Converted to task(s).'));
            }
        }

        // Notify assignee via Telegram
        if ($assigned_to && PWF_Telegram::is_enabled()) {
            $chat_id = get_user_meta($assigned_to, 'pwf_telegram_chat_id', true);
            if ($chat_id) {
                $admin_url = admin_url('admin.php?page=pwf-task&task_id=' . $task_id);
                $type_label = self::types()[$type]['label'] ?? $type;
                $prio_label = self::priorities()[$priority] ?? $priority;
                $att_count  = count($uploaded_attachments);
                
                $msg = "📋 <b>" . esc_html(pwf_t('New Task Assigned to You')) . "</b>\n";
                $msg .= "━━━━━━━━━━━━━━━━━━\n";
                $msg .= "📌 <b>#" . $task_id . ":</b> " . esc_html($title) . "\n";
                $msg .= "🎯 <b>" . esc_html(pwf_t('Type')) . ":</b> " . esc_html($type_label) . "\n";
                $msg .= "⚡ <b>" . esc_html(pwf_t('Priority')) . ":</b> " . esc_html($prio_label) . "\n";
                $msg .= "📅 <b>" . esc_html(pwf_t('Due Date')) . ":</b> " . esc_html($due_date) . "\n";
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
                $msg .= "🔗 <a href=\"" . esc_url($admin_url) . "\">" . esc_html(pwf_t('Open Task in Admin')) . "</a>";

                PWF_Telegram::send_message($chat_id, $msg);
            }
        }

        return self::get($task_id);
    }

    /**
     * Get a single task row.
     */
    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pwf_tasks WHERE task_id=%d", $id
        ));
    }

    /**
     * Assign or reassign a task to a user.
     *
     * @param int         $id          Task ID
     * @param int         $assigned_to New assignee user ID (0 to unassign)
     * @param string      $note        Reassignment reason or instructions
     * @param string|null $due_date    Optional new due date (YYYY-MM-DD)
     * @param string|null $priority    Optional new priority (urgent, high, normal, low)
     * @return object|WP_Error
     */
    public static function reassign($id, $assigned_to, $note = '', $due_date = null, $priority = null) {
        if (!current_user_can('assign_tasks')) {
            return new WP_Error('forbidden', pwf_t('Only Management & Planning can assign or reassign tasks.'), array('status' => 403));
        }
        $task = self::get($id);
        if (!$task) {
            return new WP_Error('not_found', pwf_t('Task not found.'), array('status' => 404));
        }
        if (in_array($task->status, array('approved', 'cancelled'), true)) {
            return new WP_Error('invalid_transition', pwf_t('Completed or cancelled tasks cannot be reassigned.'), array('status' => 400));
        }

        $assigned_to = absint($assigned_to);
        $new_user = null;
        if ($assigned_to > 0) {
            $new_user = get_userdata($assigned_to);
            if (!$new_user) {
                return new WP_Error('invalid_assignee', pwf_t('Selected user does not exist.'), array('status' => 400));
            }
        }

        global $wpdb;
        $now = current_time('mysql', true);
        $update_data = array(
            'assigned_to' => $assigned_to ?: null,
            'updated_at'  => $now,
        );

        if ($due_date !== null && $due_date !== '') {
            $due_date = sanitize_text_field($due_date);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due_date)) {
                return new WP_Error('invalid_date', pwf_t('Due date must be YYYY-MM-DD.'), array('status' => 400));
            }
            $update_data['due_date'] = $due_date;
        }

        if ($priority !== null && $priority !== '' && isset(self::priorities()[$priority])) {
            $update_data['priority'] = sanitize_key($priority);
        }

        $old_assignee_id = (int) $task->assigned_to;
        $old_user = $old_assignee_id ? get_userdata($old_assignee_id) : null;
        $old_name = $old_user ? $old_user->display_name : pwf_t('Unassigned');
        $new_name = $new_user ? $new_user->display_name : pwf_t('Unassigned');

        $ok = $wpdb->update($wpdb->prefix . 'pwf_tasks', $update_data, array('task_id' => $id));
        if ($ok === false) {
            return new WP_Error('db_error', pwf_t('Task assignment could not be updated.'));
        }

        // Prepare audit log note
        $event = $old_assignee_id ? 'reassigned' : 'assigned';
        $log_note = sprintf(
            pwf_t('Assigned to %s (previous: %s).'),
            $new_name,
            $old_name
        );
        if ($note) {
            $log_note .= ' ' . sanitize_textarea_field($note);
        }
        if (isset($update_data['due_date']) && $update_data['due_date'] !== $task->due_date) {
            $log_note .= ' ' . sprintf(pwf_t('Due date set to %s.'), $update_data['due_date']);
        }
        if (isset($update_data['priority']) && $update_data['priority'] !== $task->priority) {
            $log_note .= ' ' . sprintf(pwf_t('Priority set to %s.'), self::priorities()[$update_data['priority']] ?? $update_data['priority']);
        }

        self::log($id, $event, $task->status, $task->status, $log_note);

        // Notify new assignee via Telegram
        if ($assigned_to > 0 && $assigned_to !== $old_assignee_id && PWF_Telegram::is_enabled()) {
            $chat_id = get_user_meta($assigned_to, 'pwf_telegram_chat_id', true);
            if ($chat_id) {
                $admin_url = admin_url('admin.php?page=pwf-task&task_id=' . $id);
                $cur_priority = $update_data['priority'] ?? $task->priority;
                $cur_due = $update_data['due_date'] ?? ($task->due_date ?: '—');
                $msg = sprintf(
                    pwf_t("📋 <b>Task Assigned to You</b>\n#%d: %s\nType: %s\nPriority: %s | Due: %s%s\n🔗 %s"),
                    $id,
                    $task->title,
                    self::types()[$task->task_type]['label'] ?? $task->task_type,
                    self::priorities()[$cur_priority] ?? $cur_priority,
                    $cur_due,
                    $note ? "\nNote: " . esc_html($note) : '',
                    $admin_url
                );
                PWF_Telegram::send_message($chat_id, $msg);
            }
        }

        // Notify old assignee if reassigned away
        if ($old_assignee_id > 0 && $old_assignee_id !== $assigned_to && PWF_Telegram::is_enabled()) {
            $old_chat_id = get_user_meta($old_assignee_id, 'pwf_telegram_chat_id', true);
            if ($old_chat_id) {
                $msg = sprintf(
                    pwf_t("ℹ️ <b>Task Reassigned</b>\n#%d: %s has been reassigned to %s."),
                    $id,
                    $task->title,
                    $new_name
                );
                PWF_Telegram::send_message($old_chat_id, $msg);
            }
        }

        return self::get($id);
    }

    /**
     * Alias for reassign().
     */
    public static function assign($id, $assigned_to, $note = '', $due_date = null, $priority = null) {
        return self::reassign($id, $assigned_to, $note, $due_date, $priority);
    }

    /**
     * List tasks with optional filters.
     *
     * @param array $args  status, assigned_to, task_type, priority, request_id, product_id, search, page, per_page
     * @return array  ['rows' => [], 'total' => int]
     */
    public static function list($args = array()) {
        global $wpdb;
        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, (int) ($args['per_page'] ?? 20));
        $where    = '1=1';
        $params   = array();

        if (!empty($args['status']) && isset(self::statuses()[$args['status']])) {
            $where .= ' AND t.status=%s'; $params[] = $args['status'];
        }
        if (!empty($args['assigned_to'])) {
            $where .= ' AND t.assigned_to=%d'; $params[] = absint($args['assigned_to']);
        }
        if (!empty($args['task_type']) && isset(self::types()[$args['task_type']])) {
            $where .= ' AND t.task_type=%s'; $params[] = $args['task_type'];
        }
        if (!empty($args['priority']) && isset(self::priorities()[$args['priority']])) {
            $where .= ' AND t.priority=%s'; $params[] = $args['priority'];
        }
        if (!empty($args['request_id'])) {
            $where .= ' AND t.request_id=%d'; $params[] = absint($args['request_id']);
        }
        if (!empty($args['product_id'])) {
            $where .= ' AND t.product_id=%d'; $params[] = absint($args['product_id']);
        }
        if (!empty($args['search'])) {
            $like   = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $where .= ' AND t.title LIKE %s'; $params[] = $like;
        }
        // Workers see only their own tasks
        if (!current_user_can('manage_workflow') && !current_user_can('view_executive_dashboard')) {
            $where .= ' AND t.assigned_to=%d'; $params[] = get_current_user_id();
        }

        $base = "FROM {$wpdb->prefix}pwf_tasks t LEFT JOIN {$wpdb->users} u ON u.ID = t.assigned_to WHERE $where";
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $base", ...$params));
        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, u.display_name AS assignee_name $base ORDER BY
             FIELD(t.priority,'urgent','high','normal','low'), t.due_date ASC, t.task_id ASC
             LIMIT %d OFFSET %d",
            ...[...$params, $per_page, $offset]
        ));
        return array('rows' => $rows ?: array(), 'total' => $total);
    }

    /**
     * Worker marks a task as In Progress.
     */
    public static function start($id) {
        $task = self::get($id);
        if (!$task) { return new WP_Error('not_found', pwf_t('Task not found.'), array('status' => 404)); }
        if ((int) $task->assigned_to !== get_current_user_id() && !current_user_can('manage_workflow')) {
            return new WP_Error('forbidden', pwf_t('You are not assigned to this task.'), array('status' => 403));
        }
        if ($task->status !== 'assigned') {
            return new WP_Error('invalid_transition', pwf_t('Only assigned tasks can be started.'), array('status' => 400));
        }
        return self::_set_status($task, 'in_progress', 'started', pwf_t('Worker started the task.'));
    }

    /**
     * Worker submits deliverables (with optional file/photo uploads and links).
     *
     * @param int    $id                Task ID
     * @param string $deliverable_url   External URL (Drive, Git, Figma…) or empty
     * @param string $deliverable_note  Explanatory notes
     */
    public static function submit($id, $deliverable_url = '', $deliverable_note = '') {
        $task = self::get($id);
        if (!$task) { return new WP_Error('not_found', pwf_t('Task not found.'), array('status' => 404)); }
        if ((int) $task->assigned_to !== get_current_user_id() && !current_user_can('manage_workflow')) {
            return new WP_Error('forbidden', pwf_t('You are not assigned to this task.'), array('status' => 403));
        }
        if (!in_array($task->status, array('in_progress', 'needs_revision'), true)) {
            return new WP_Error('invalid_transition', pwf_t('Only in-progress or revision-pending tasks can be submitted.'), array('status' => 400));
        }

        // Upload deliverable files/photos if provided
        $uploaded_deliv = self::upload_files('deliverable_files');
        $deliv_files_json = null;
        if (!empty($uploaded_deliv)) {
            $existing_deliv = !empty($task->deliverable_files) ? json_decode($task->deliverable_files, true) : array();
            $all_deliv = array_merge(is_array($existing_deliv) ? $existing_deliv : array(), $uploaded_deliv);
            $deliv_files_json = wp_json_encode($all_deliv);
        } else {
            $deliv_files_json = $task->deliverable_files;
        }

        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->update($wpdb->prefix . 'pwf_tasks', array(
            'status'            => $task->self_closing ? 'approved' : 'submitted',
            'deliverable_url'   => esc_url_raw($deliverable_url),
            'deliverable_note'  => sanitize_textarea_field($deliverable_note),
            'deliverable_files' => $deliv_files_json,
            'updated_at'        => $now,
        ), array('task_id' => $id));

        $new_status = $task->self_closing ? 'approved' : 'submitted';
        self::log($id, 'submitted', $task->status, $new_status, pwf_t('Deliverables submitted.'));

        $updated_task = self::get($id);
        if ($task->self_closing) {
            // Auto-approved: notify management and check request completion
            self::_notify_management($updated_task, pwf_t('Task auto-approved (self-closing).'));
            if ($task->request_id) { PWF_Request_Manager::maybe_complete($task->request_id); }
        } else {
            // Notify management to review
            self::_notify_management($updated_task, pwf_t('Deliverables submitted and awaiting QA review.'));
        }

        return $updated_task;
    }

    /**
     * Management approves submitted deliverables.
     */
    public static function approve($id, $note = '') {
        if (!current_user_can('manage_workflow')) {
            return new WP_Error('forbidden', pwf_t('Only Management & Planning can approve tasks.'), array('status' => 403));
        }
        $task = self::get($id);
        if (!$task) { return new WP_Error('not_found', pwf_t('Task not found.'), array('status' => 404)); }
        if ($task->status !== 'submitted') {
            return new WP_Error('invalid_transition', pwf_t('Only submitted tasks can be approved.'), array('status' => 400));
        }

        $result = self::_set_status($task, 'approved', 'approved', sanitize_textarea_field($note));
        if (is_wp_error($result)) { return $result; }

        // Auto-publish WooCommerce product if publishing task
        if ($task->task_type === 'publishing' && $task->product_id) {
            $product = wc_get_product($task->product_id);
            if ($product && $product->get_status() === 'draft') {
                $product->set_status('publish');
                $product->save();
            }
        }

        // Notify worker
        if ($task->assigned_to) {
            $chat_id = get_user_meta($task->assigned_to, 'pwf_telegram_chat_id', true);
            if ($chat_id && PWF_Telegram::is_enabled()) {
                $msg = sprintf(pwf_t("✅ <b>Task Approved</b>\n#%d: %s\n%s"), $task->task_id, $task->title, $note ? "Note: $note" : '');
                PWF_Telegram::send_message($chat_id, $msg);
            }
        }

        // Check if parent request can be auto-completed
        if ($task->request_id) { PWF_Request_Manager::maybe_complete($task->request_id); }

        return $result;
    }

    /**
     * Management requests revision — mandatory note required.
     */
    public static function request_revision($id, $note) {
        if (!current_user_can('manage_workflow')) {
            return new WP_Error('forbidden', pwf_t('Only Management & Planning can request revisions.'), array('status' => 403));
        }
        $note = sanitize_textarea_field($note);
        if (trim($note) === '') {
            return new WP_Error('note_required', pwf_t('A written correction note is required when requesting revision.'), array('status' => 400));
        }
        $task = self::get($id);
        if (!$task) { return new WP_Error('not_found', pwf_t('Task not found.'), array('status' => 404)); }
        if ($task->status !== 'submitted') {
            return new WP_Error('invalid_transition', pwf_t('Only submitted tasks can be returned for revision.'), array('status' => 400));
        }

        $result = self::_set_status($task, 'needs_revision', 'revision_requested', $note);
        if (is_wp_error($result)) { return $result; }

        // Notify assignee immediately
        if ($task->assigned_to) {
            $chat_id = get_user_meta($task->assigned_to, 'pwf_telegram_chat_id', true);
            if ($chat_id && PWF_Telegram::is_enabled()) {
                $msg = sprintf(pwf_t("🔴 <b>Revision Required</b>\n#%d: %s\nFeedback: %s"), $task->task_id, $task->title, $note);
                PWF_Telegram::send_message($chat_id, $msg);
            }
        }
        return $result;
    }

    /**
     * Put a task on hold (external dependency).
     */
    public static function hold($id, $note = '') {
        if (!current_user_can('manage_workflow') && !self::_is_assignee($id)) {
            return new WP_Error('forbidden', pwf_t('Access denied.'), array('status' => 403));
        }
        $task = self::get($id);
        if (!$task) { return new WP_Error('not_found', pwf_t('Task not found.'), array('status' => 404)); }
        if (!in_array($task->status, array('assigned', 'in_progress'), true)) {
            return new WP_Error('invalid_transition', pwf_t('Only assigned or in-progress tasks can be put on hold.'), array('status' => 400));
        }
        return self::_set_status($task, 'on_hold', 'on_hold', sanitize_textarea_field($note));
    }

    /**
     * Cancel a task with a reason.
     */
    public static function cancel($id, $note = '') {
        if (!current_user_can('manage_workflow')) {
            return new WP_Error('forbidden', pwf_t('Only Management & Planning can cancel tasks.'), array('status' => 403));
        }
        $task = self::get($id);
        if (!$task) { return new WP_Error('not_found', pwf_t('Task not found.'), array('status' => 404)); }
        if (in_array($task->status, array('approved', 'cancelled'), true)) {
            return new WP_Error('invalid_transition', pwf_t('Approved or already-cancelled tasks cannot be cancelled.'), array('status' => 400));
        }
        return self::_set_status($task, 'cancelled', 'cancelled', sanitize_textarea_field($note));
    }

    /**
     * Resume a task from on_hold → in_progress.
     */
    public static function resume($id) {
        if (!current_user_can('manage_workflow') && !self::_is_assignee($id)) {
            return new WP_Error('forbidden', pwf_t('Access denied.'), array('status' => 403));
        }
        $task = self::get($id);
        if (!$task) { return new WP_Error('not_found', pwf_t('Task not found.'), array('status' => 404)); }
        if ($task->status !== 'on_hold') {
            return new WP_Error('invalid_transition', pwf_t('Only on-hold tasks can be resumed.'), array('status' => 400));
        }
        return self::_set_status($task, 'in_progress', 'resumed', pwf_t('Task resumed.'));
    }

    /**
     * Get the immutable audit log for a task.
     */
    public static function history($id, $page = 1) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, u.display_name FROM {$wpdb->prefix}pwf_task_history h
             LEFT JOIN {$wpdb->users} u ON u.ID = h.changed_by
             WHERE h.task_id=%d ORDER BY h.log_id DESC LIMIT 50 OFFSET %d",
            $id, (max(1, $page) - 1) * 50
        ));
    }

    // ── Internal helpers ──────────────────────────────────────────────────────────

    private static function _set_status($task, $new_status, $event, $note = '') {
        global $wpdb;
        $ok = $wpdb->update($wpdb->prefix . 'pwf_tasks', array(
            'status'     => $new_status,
            'updated_at' => current_time('mysql', true),
        ), array('task_id' => $task->task_id));
        if ($ok === false) {
            return new WP_Error('db_error', pwf_t('Status could not be updated. Please retry.'));
        }
        self::log($task->task_id, $event, $task->status, $new_status, $note);
        return self::get($task->task_id);
    }

    private static function _is_assignee($task_id) {
        $task = self::get($task_id);
        return $task && (int) $task->assigned_to === get_current_user_id();
    }

    private static function _notify_management($task, $note = '') {
        if (!PWF_Telegram::is_enabled()) { return; }
        $chat = get_option('pwf_telegram_default_chat_id', '');
        if (!$chat) { return; }
        $submitter = get_userdata($task->assigned_to);
        $submitter_name = $submitter ? $submitter->display_name : ('#' . $task->assigned_to);
        $type_label = self::types()[$task->task_type]['label'] ?? $task->task_type;
        $admin_url  = admin_url('admin.php?page=pwf-task&task_id=' . $task->task_id);

        $deliv_files = !empty($task->deliverable_files) ? json_decode($task->deliverable_files, true) : array();
        $file_count = is_array($deliv_files) ? count($deliv_files) : 0;

        $msg = "📤 <b>" . esc_html(pwf_t('Task Submitted for QA Review')) . "</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━\n";
        $msg .= "📌 <b>#" . $task->task_id . ":</b> " . esc_html($task->title) . "\n";
        $msg .= "👤 <b>" . esc_html(pwf_t('Submitted by')) . ":</b> " . esc_html($submitter_name) . "\n";
        $msg .= "🎯 <b>" . esc_html(pwf_t('Type')) . ":</b> " . esc_html($type_label) . "\n";
        if (!empty($task->deliverable_url)) {
            $msg .= "🔗 <b>" . esc_html(pwf_t('Deliverables Link')) . ":</b> <a href=\"" . esc_url($task->deliverable_url) . "\">" . esc_html($task->deliverable_url) . "</a>\n";
        }
        if ($file_count > 0) {
            $msg .= "📎 <b>" . sprintf(esc_html(pwf_t('Delivered Files (%d files/photos)')), $file_count) . ":</b>\n";
            foreach (array_slice($deliv_files, 0, 3) as $df) {
                $msg .= "  • <a href=\"" . esc_url($df['url']) . "\">" . esc_html($df['name']) . "</a>\n";
            }
            if ($file_count > 3) {
                $msg .= "  • ... (" . ($file_count - 3) . " " . esc_html(pwf_t('more')) . ")\n";
            }
        }
        if (!empty($task->deliverable_note)) {
            $msg .= "💬 <b>" . esc_html(pwf_t('Worker Note')) . ":</b> " . esc_html($task->deliverable_note) . "\n";
        }
        if ($note) {
            $msg .= "ℹ️ " . esc_html($note) . "\n";
        }
        $msg .= "━━━━━━━━━━━━━━━━━━\n";
        $msg .= "🔍 <a href=\"" . esc_url($admin_url) . "\">" . esc_html(pwf_t('Review & Approve in Admin')) . "</a>";

        PWF_Telegram::send_message($chat, $msg);
    }

    /**
     * Upload handler for task attachments and deliverables.
     *
     * @param string $key  $_FILES key
     * @return array       Array of uploaded file descriptor records
     */
    public static function upload_files($key) {
        if (empty($_FILES[$key]) || empty($_FILES[$key]['name'])) {
            return array();
        }
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $files = $_FILES[$key];
        $uploaded = array();
        $upload_overrides = array('test_form' => false);

        $names  = is_array($files['name'])     ? $files['name']     : array($files['name']);
        $types  = is_array($files['type'])     ? $files['type']     : array($files['type']);
        $tmps   = is_array($files['tmp_name']) ? $files['tmp_name'] : array($files['tmp_name']);
        $errors = is_array($files['error'])    ? $files['error']    : array($files['error']);
        $sizes  = is_array($files['size'])     ? $files['size']     : array($files['size']);

        $allowed_exts = array('jpg', 'jpeg', 'jpe', 'png', 'webp', 'gif', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'txt');

        for ($i = 0; $i < count($names); $i++) {
            if (empty($names[$i]) || $errors[$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts, true)) {
                continue;
            }

            $single_file = array(
                'name'     => sanitize_file_name($names[$i]),
                'type'     => $types[$i],
                'tmp_name' => $tmps[$i],
                'error'    => $errors[$i],
                'size'     => $sizes[$i],
            );
            $movefile = wp_handle_upload($single_file, $upload_overrides);
            if ($movefile && !isset($movefile['error'])) {
                $is_img = (strpos($movefile['type'], 'image/') === 0) || in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true);
                $uploaded[] = array(
                    'name'        => sanitize_text_field($names[$i]),
                    'url'         => esc_url_raw($movefile['url']),
                    'file'        => $movefile['file'],
                    'type'        => $movefile['type'],
                    'size'        => $sizes[$i],
                    'is_image'    => $is_img,
                    'uploaded_at' => current_time('mysql', true),
                );
            }
        }
        return $uploaded;
    }

    /**
     * Write an immutable audit log entry for a task.
     */
    public static function log($id, $event, $from, $to, $note = '') {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'pwf_task_history', array(
            'task_id'         => $id,
            'event'           => $event,
            'previous_status' => $from,
            'new_status'      => $to,
            'changed_by'      => get_current_user_id(),
            'changed_at'      => current_time('mysql', true),
            'note'            => sanitize_textarea_field($note),
        ));
        if (!$ok) {
            throw new RuntimeException(pwf_t('The task audit record could not be saved. Please retry.'));
        }
    }
}

