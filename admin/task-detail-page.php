<?php
defined('ABSPATH') || exit;

/**
 * Task detail page: worker execution, deliverable submission, and management QA review.
 */
class PWF_Task_Page {

    public static function boot() {
        add_action('admin_post_pwf_task_action', array(__CLASS__, 'handle'));
    }

    public static function handle() {
        $data = wp_unslash($_POST);
        $op   = sanitize_key($data['operation'] ?? '');
        $tid  = absint($data['task_id'] ?? 0);
        check_admin_referer('pwf_task_' . $op . '_' . $tid);

        if (!PWF_Permission_Manager::can_view_task($tid) && $op !== 'create_standalone') {
            wp_die(pwf_t('Access denied.'), '', array('response' => 403));
        }

        switch ($op) {
            case 'create_standalone':
                if (!current_user_can('assign_tasks')) { wp_die(pwf_t('Access denied.')); }
                $result = PWF_Task_Manager::create($data);
                if (is_wp_error($result)) {
                    self::notice(true, $result->get_error_message());
                    wp_safe_redirect(admin_url('admin.php?page=pwf-new-task'));
                } else {
                    self::notice(false, pwf_t('Task created successfully.'));
                    wp_safe_redirect(admin_url('admin.php?page=pwf-task&task_id=' . $result->task_id));
                }
                exit;

            case 'reassign':
                if (!current_user_can('assign_tasks')) { wp_die(pwf_t('Access denied.')); }
                $result = PWF_Task_Manager::reassign(
                    $tid,
                    absint($data['assigned_to'] ?? 0),
                    $data['note'] ?? '',
                    !empty($data['due_date']) ? sanitize_text_field($data['due_date']) : null,
                    !empty($data['priority']) ? sanitize_key($data['priority']) : null
                );
                break;

            case 'start':
                $result = PWF_Task_Manager::start($tid);
                break;

            case 'submit':
                $result = PWF_Task_Manager::submit($tid, $data['deliverable_url'] ?? '', $data['deliverable_note'] ?? '');
                break;

            case 'approve':
                if (!current_user_can('manage_workflow')) { wp_die(pwf_t('Access denied.')); }
                $result = PWF_Task_Manager::approve($tid, $data['note'] ?? '');
                break;

            case 'revision':
                if (!current_user_can('manage_workflow')) { wp_die(pwf_t('Access denied.')); }
                $result = PWF_Task_Manager::request_revision($tid, $data['note'] ?? '');
                break;

            case 'hold':
                $result = PWF_Task_Manager::hold($tid, $data['note'] ?? '');
                break;

            case 'resume':
                $result = PWF_Task_Manager::resume($tid);
                break;

            case 'cancel':
                if (!current_user_can('manage_workflow')) { wp_die(pwf_t('Access denied.')); }
                $result = PWF_Task_Manager::cancel($tid, $data['note'] ?? '');
                break;

            default:
                $result = new WP_Error('invalid_op', pwf_t('Unknown action.'));
        }

        if (isset($result)) {
            if (is_wp_error($result)) {
                self::notice(true, $result->get_error_message());
            } else {
                self::notice(false, pwf_t('Saved successfully.'));
            }
        }
        wp_safe_redirect($tid ? admin_url('admin.php?page=pwf-task&task_id=' . $tid) : admin_url('admin.php?page=pwf'));
        exit;
    }

    private static function notice($is_error, $text) {
        set_transient('pwf_task_notice_' . get_current_user_id(), array('error' => $is_error, 'text' => $text), 60);
    }

    private static function show_notice() {
        $key    = 'pwf_task_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if ($notice) {
            delete_transient($key);
            echo '<div class="notice ' . ($notice['error'] ? 'notice-error' : 'notice-success') . ' pwf-notice"><p>' . esc_html($notice['text']) . '</p></div>';
        }
    }

    /** Create standalone task page (Management only). */
    public static function render_create() {
        if (!current_user_can('assign_tasks')) { wp_die(pwf_t('Access denied.')); }

        $prefill_product_id = absint($_GET['product_id'] ?? 0);
        $prefill_request_id = absint($_GET['request_id'] ?? 0);
        $prefill_task_type  = sanitize_key($_GET['task_type'] ?? '');
        $prefill_title      = sanitize_text_field($_GET['title'] ?? '');

        $product = $prefill_product_id ? wc_get_product($prefill_product_id) : null;
        $request = $prefill_request_id ? PWF_Request_Manager::get($prefill_request_id) : null;

        echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';
        echo '<div class="pwf-hero">';
        echo '<div class="pwf-hero-title">';
        echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-plus-alt2"></span></div>';
        echo '<div><h1>' . esc_html(pwf_t('Create New Task')) . '</h1>';
        echo '<p>' . esc_html(pwf_t('Assign actionable work directly to a team member (photographer, writer, programmer) with clear deadline and guidelines.')) . '</p></div>';
        echo '</div>';
        echo '<div class="pwf-hero-actions">';
        if ($prefill_product_id) {
            echo '<a href="' . esc_url(PWF_Admin::url($prefill_product_id)) . '" class="button pwf-btn-subtle">&larr; ' . esc_html(pwf_t('Back to Product')) . '</a>';
        } else {
            echo '<a href="' . esc_url(admin_url('admin.php?page=pwf')) . '" class="button pwf-btn-subtle">&larr; ' . esc_html(pwf_t('Dashboard')) . '</a>';
        }
        echo '</div></div>';

        self::show_notice();

        // Difference explainer banner
        echo '<div class="pwf-guide-banner" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 22px; margin-bottom:22px; box-shadow:0 1px 3px rgba(0,0,0,0.03);">';
        echo '<div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">';
        echo '<span style="font-size:20px;">💡</span>';
        echo '<strong style="font-size:14px; color:#0f172a;">' . esc_html(pwf_t('Workflow Guide: Understanding Requests, Tasks & Products')) . '</strong>';
        echo '</div>';
        echo '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px;">';
        echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; border-right:4px solid #0284c7;">';
        echo '<strong style="color:#0284c7; font-size:13px;">📋 ' . esc_html(pwf_t('Task (Execution)')) . '</strong>';
        echo '<p style="margin:4px 0 0; font-size:12px; color:#64748b; line-height:1.5;">' . esc_html(pwf_t('Assigned to a specific person (photographer, writer, programmer) with a clear deadline, attached guidelines, and a deliverable submission flow.')) . '</p>';
        echo '</div>';
        echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; border-right:4px solid #4f46e5;">';
        echo '<strong style="color:#4f46e5; font-size:13px;">📥 ' . esc_html(pwf_t('Request (Intake)')) . '</strong>';
        echo '<p style="margin:4px 0 0; font-size:12px; color:#64748b; line-height:1.5;">' . esc_html(pwf_t('Initial proposal or demand from factory/sales with sample photos and reference files. Management reviews it and converts it into specific Tasks.')) . '</p>';
        echo '</div>';
        echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; border-right:4px solid #10b981;">';
        echo '<strong style="color:#10b981; font-size:13px;">📦 ' . esc_html(pwf_t('Product Pipeline')) . '</strong>';
        echo '<p style="margin:4px 0 0; font-size:12px; color:#64748b; line-height:1.5;">' . esc_html(pwf_t('The end-to-end WooCommerce catalog item tracked from production intake, photo shoot, copywriting, QA approval, to live site publishing.')) . '</p>';
        echo '</div>';
        echo '</div></div>';

        if ($product) {
            echo '<div class="notice notice-info pwf-notice" style="max-width:860px; margin:16px auto;"><p>';
            echo '<strong>' . esc_html(pwf_t('Linked Product:')) . '</strong> ' . esc_html($product->get_name()) . ' (#' . $prefill_product_id . ' · SKU: ' . esc_html($product->get_sku() ?: '—') . ')';
            echo '</p></div>';
        }

        if ($request) {
            echo '<div class="notice notice-info pwf-notice" style="max-width:860px; margin:16px auto;"><p>';
            echo '<strong>' . esc_html(pwf_t('Linked Request:')) . '</strong> #' . $prefill_request_id . ' ' . esc_html($request->title);
            echo '</p></div>';
        }

        echo '<div class="pwf-card pwf-task-create-card" style="max-width:860px; margin:0 auto; background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 2px 8px rgba(0,0,0,0.04); padding:24px 28px;">';
        echo '<div style="display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #f1f5f9; padding-bottom:14px; margin-bottom:20px;">';
        echo '<div style="display:flex; align-items:center; gap:10px;">';
        echo '<div style="width:38px; height:38px; border-radius:8px; background:#eff6ff; color:#0284c7; display:flex; align-items:center; justify-content:center; font-size:20px;">📋</div>';
        echo '<div><h3 style="margin:0; font-size:16px; font-weight:700; color:#0f172a;">' . esc_html(pwf_t('Task Assignment Specifications')) . '</h3>';
        echo '<p style="margin:2px 0 0; font-size:12px; color:#64748b;">' . esc_html(pwf_t('Define task title, assignee, deadline, guidelines, and reference files.')) . '</p></div>';
        echo '</div></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        wp_nonce_field('pwf_task_create_standalone_0');
        echo '<input type="hidden" name="action" value="pwf_task_action">';
        echo '<input type="hidden" name="operation" value="create_standalone">';
        echo '<input type="hidden" name="task_id" value="0">';
        if ($prefill_request_id) {
            echo '<input type="hidden" name="request_id" value="' . absint($prefill_request_id) . '">';
        }

        echo '<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">';

        echo '<div style="grid-column:1/-1;">';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Task Title')) . ' <span style="color:#ef4444;">*</span></label>';
        echo '<input class="regular-text" name="title" value="' . esc_attr($prefill_title) . '" required style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;" maxlength="255" placeholder="' . esc_attr(pwf_t('E.g. Studio shoot for 5 crystal angles')) . '">';
        echo '</div>';

        echo '<div>';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Task Type')) . '</label>';
        echo '<select name="task_type" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;">';
        foreach (PWF_Task_Manager::types() as $key => $def) {
            $sel = ($prefill_task_type && $key === $prefill_task_type) ? 'selected' : '';
            echo '<option value="' . esc_attr($key) . '" ' . $sel . '>' . esc_html($def['label']) . '</option>';
        }
        echo '</select></div>';

        echo '<div>';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Priority')) . ' <span style="color:#ef4444;">*</span></label>';
        echo '<select name="priority" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;">';
        foreach (PWF_Task_Manager::priorities() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($key, 'normal', false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';

        echo '<div>';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Assign to')) . '</label>';
        echo '<select name="assigned_to" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;">';
        echo '<option value="">' . esc_html(pwf_t('Unassigned')) . '</option>';
        $role_map = array(
            'pwf_photographer'  => pwf_t('Photographer'),
            'pwf_programmer'    => pwf_t('Programmer'),
            'pwf_digital_admin' => pwf_t('Digital Admin'),
            'pwf_secretary'     => pwf_t('Factory Secretary'),
            'pwf_sales_manager' => pwf_t('Sales Manager'),
            'pwf_management'    => pwf_t('Management & Planning'),
            'pwf_ceo'           => pwf_t('CEO'),
            'administrator'     => pwf_t('Administrator'),
        );
        foreach (get_users(array('orderby' => 'display_name', 'number' => 200)) as $u) {
            $role_label = '';
            if (!empty($u->roles)) {
                $r = $u->roles[0];
                $role_label = $role_map[$r] ?? ucfirst(str_replace('pwf_', '', $r));
            }
            $label = $u->display_name . ($role_label ? ' (' . $role_label . ')' : '');
            echo '<option value="' . absint($u->ID) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></div>';

        echo '<div>';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Due Date')) . ' <span style="color:#ef4444;">*</span></label>';
        echo '<input type="date" name="due_date" required style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;"></div>';

        echo '<div style="grid-column:1/-1;">';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Linked Product ID (optional)')) . '</label>';
        echo '<input type="number" min="1" name="product_id" value="' . ($prefill_product_id ? absint($prefill_product_id) : '') . '" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;" placeholder="—"></div>';

        echo '</div>'; // grid

        echo '<div style="margin-bottom:16px;">';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Description & Guidelines')) . '</label>';
        echo '<textarea name="description" rows="4" class="large-text" style="width:100%; border-radius:8px; border:1.5px solid #cbd5e1; padding:10px 12px; font-size:13px;" placeholder="' . esc_attr(pwf_t('Explain the requirements, guidelines, dimensions, or specific instructions for the assignee...')) . '"></textarea></div>';

        // File and Photo Attachments section
        echo '<div class="pwf-attachment-upload-box" style="background:#f8fafc; border:1.5px dashed #cbd5e1; border-radius:12px; padding:18px; margin:16px 0;">';
        echo '<label style="display:block; cursor:pointer;">';
        echo '<strong style="display:flex; align-items:center; gap:8px; font-size:14px; color:#1e293b;">';
        echo '<span class="dashicons dashicons-paperclip" style="color:var(--pwf-primary);"></span> ';
        echo esc_html(pwf_t('Attach Photos & Files (Optional)'));
        echo '</strong>';
        echo '<p style="margin:4px 0 10px; font-size:12px; color:#64748b;">' . esc_html(pwf_t('Send reference photos, shot angles, sample guides, PDFs, or ZIP archives to the photographer or worker.')) . '</p>';
        echo '<input type="file" name="task_attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar" style="font-size:13px; width:100%;">';
        echo '</label>';
        echo '</div>';

        echo '<div style="margin:14px 0; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px;">';
        echo '<label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; color:#334155;">';
        echo '<input type="checkbox" name="self_closing" value="1" style="margin:0;"> ';
        echo esc_html(pwf_t('Self-closing — auto-approve when worker submits (skip QA for routine tasks)'));
        echo '</label></div>';

        echo '<div style="display:flex; align-items:center; gap:12px; margin-top:24px; border-top:1px solid #f1f5f9; padding-top:18px;">';
        submit_button(pwf_t('Create Task'), 'primary', 'submit', false, array('style' => 'height:40px; padding:0 24px; border-radius:8px; font-weight:600; font-size:13px; background:#4f46e5; border-color:#4f46e5;'));
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf')) . '" class="button button-secondary" style="height:40px; display:inline-flex; align-items:center; padding:0 20px; border-radius:8px; font-size:13px;">' . esc_html(pwf_t('Cancel')) . '</a>';
        echo '</div>';
        echo '</form></div></div>';
    }

    /** Task detail view. */
    public static function render() {
        $tid = absint($_GET['task_id'] ?? 0);
        if (!$tid || !PWF_Permission_Manager::can_view_task($tid)) {
            wp_die(pwf_t('You do not have access to this task.'), '', array('response' => 403));
        }
        $task = PWF_Task_Manager::get($tid);
        if (!$task) { wp_die(pwf_t('Task not found.')); }

        $status_label = PWF_Task_Manager::statuses()[$task->status]         ?? $task->status;
        $type_label   = PWF_Task_Manager::types()[$task->task_type]['label'] ?? $task->task_type;
        $prio_label   = PWF_Task_Manager::priorities()[$task->priority]      ?? $task->priority;
        $assignee     = $task->assigned_to ? get_userdata($task->assigned_to) : null;
        $uid          = get_current_user_id();
        $is_assignee  = $task->assigned_to && (int) $task->assigned_to === $uid;
        $is_mgmt      = current_user_can('manage_workflow');

        $status_class_map = array(
            'assigned'       => 'pwf-status-waiting_photography',
            'in_progress'    => 'pwf-status-waiting_content',
            'on_hold'        => 'pwf-status-on_hold',
            'submitted'      => 'pwf-status-waiting_review',
            'needs_revision' => 'pwf-status-needs_revision',
            'approved'       => 'pwf-status-completed',
            'cancelled'      => 'pwf-status-cancelled',
        );
        $status_class = $status_class_map[$task->status] ?? '';

        echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';
        echo '<div class="pwf-hero">';
        echo '<div class="pwf-hero-title">';
        echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-clipboard"></span></div>';
        echo '<div>';
        echo '<h1>' . esc_html($task->title) . ' <span style="font-size:14px; font-weight:400; opacity:0.8;">#' . absint($tid) . '</span></h1>';
        echo '<div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:6px;">';
        echo '<span class="pwf-status ' . esc_attr($status_class) . '" style="color:#fff; background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3);">' . esc_html($status_label) . '</span>';
        echo '<span style="font-size:13px; color:#e0e7ff;">' . esc_html($type_label) . '</span>';
        echo '<span style="font-size:13px; color:#e0e7ff;">· ' . esc_html($prio_label) . '</span>';
        if ($task->due_date) {
            $overdue = ($task->status !== 'approved' && $task->due_date < current_time('Y-m-d'));
            $due_style = $overdue ? 'color:#fca5a5;' : 'color:#a5f3fc;';
            echo '<span style="font-size:13px; ' . $due_style . '">· ' . esc_html(pwf_t('Due:')) . ' ' . esc_html($task->due_date) . ($overdue ? ' ⚠' : '') . '</span>';
        }
        echo '</div></div></div>';
        echo '<div class="pwf-hero-actions">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf-tasks')) . '" class="button pwf-btn-subtle">&larr; ' . esc_html(pwf_t('My Tasks')) . '</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf')) . '" class="button pwf-btn-subtle">' . esc_html(pwf_t('Dashboard')) . '</a>';
        if ($task->request_id) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=pwf-request&request_id=' . $task->request_id)) . '" class="button pwf-btn-subtle">' . esc_html(pwf_t('View Request')) . '</a>';
        }
        echo '</div></div>';

        self::show_notice();

        echo '<div class="pwf-grid">';

        // ── LEFT: Task details + attachments + deliverables + history ────────────
        echo '<div>';

        // Metadata Card
        echo '<section class="pwf-card" style="margin-bottom:20px;">';
        echo '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">';
        echo '<h2 style="margin:0; font-size:15px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;"><span class="dashicons dashicons-info" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Task Overview & Metadata')) . '</h2>';
        echo '<span style="font-size:11px; background:#eff6ff; color:#0284c7; padding:3px 8px; border-radius:6px; font-weight:600;">📋 ' . esc_html(pwf_t('Execution Task')) . '</span>';
        echo '</div>';

        echo '<div class="pwf-meta-grid">';

        // Assignee
        $role_map = array(
            'pwf_photographer'  => pwf_t('Photographer'),
            'pwf_programmer'    => pwf_t('Programmer'),
            'pwf_digital_admin' => pwf_t('Digital Admin'),
            'pwf_secretary'     => pwf_t('Factory Secretary'),
            'pwf_sales_manager' => pwf_t('Sales Manager'),
            'pwf_management'    => pwf_t('Management & Planning'),
            'pwf_ceo'           => pwf_t('CEO'),
            'administrator'     => pwf_t('Administrator'),
        );
        $role_name = '';
        if ($assignee && !empty($assignee->roles)) {
            $r = $assignee->roles[0];
            $role_name = $role_map[$r] ?? ucfirst(str_replace('pwf_', '', $r));
        }

        echo '<div class="pwf-meta-item">';
        echo '<span class="pwf-meta-label">👤 ' . esc_html(pwf_t('Assigned to')) . '</span>';
        echo '<span class="pwf-meta-val">' . esc_html($assignee ? $assignee->display_name : pwf_t('Unassigned'));
        if ($role_name) { echo ' <span style="font-size:11px; font-weight:normal; color:#64748b;">(' . esc_html($role_name) . ')</span>'; }
        echo '</span></div>';

        // Due Date
        $overdue = ($task->status !== 'approved' && $task->due_date && $task->due_date < current_time('Y-m-d'));
        echo '<div class="pwf-meta-item" style="' . ($overdue ? 'background:#fef2f2; border-color:#fecaca;' : '') . '">';
        echo '<span class="pwf-meta-label" style="' . ($overdue ? 'color:#dc2626;' : '') . '">📅 ' . esc_html(pwf_t('Due Date')) . '</span>';
        echo '<span class="pwf-meta-val" style="' . ($overdue ? 'color:#dc2626;' : '') . '">' . esc_html($task->due_date ?: '—') . ($overdue ? ' ⚠ ' . esc_html(pwf_t('(Overdue)')) : '') . '</span>';
        echo '</div>';

        // Task Type
        echo '<div class="pwf-meta-item">';
        echo '<span class="pwf-meta-label">🏷️ ' . esc_html(pwf_t('Task Type')) . '</span>';
        echo '<span class="pwf-meta-val">' . esc_html($type_label) . '</span>';
        echo '</div>';

        // Priority
        $prio_cls = array('urgent' => 'pwf-badge-admin', 'high' => 'pwf-badge-error', 'normal' => 'pwf-badge-role', 'low' => 'pwf-badge-default');
        echo '<div class="pwf-meta-item">';
        echo '<span class="pwf-meta-label">⚡ ' . esc_html(pwf_t('Priority')) . '</span>';
        echo '<span class="pwf-meta-val"><span class="pwf-badge ' . esc_attr($prio_cls[$task->priority] ?? 'pwf-badge-default') . '">' . esc_html($prio_label) . '</span></span>';
        echo '</div>';

        echo '</div>'; // pwf-meta-grid

        // Linked Product Card
        if ($task->product_id) {
            $p = wc_get_product($task->product_id);
            echo '<div class="pwf-product-preview-card">';
            $img_id = $p ? $p->get_image_id() : 0;
            $thumb_src = $img_id ? wp_get_attachment_image_url($img_id, 'thumbnail') : '';
            if ($thumb_src) {
                echo '<img src="' . esc_url($thumb_src) . '" class="pwf-product-preview-thumb" alt="' . esc_attr($p ? $p->get_name() : '') . '">';
            } else {
                echo '<div class="pwf-product-preview-thumb" style="background:#eef2ff; color:#4f46e5; display:flex; align-items:center; justify-content:center; font-size:24px;">📦</div>';
            }
            echo '<div class="pwf-product-preview-info">';
            echo '<h4 class="pwf-product-preview-title">' . esc_html($p ? $p->get_name() : ('#' . $task->product_id)) . '</h4>';
            echo '<div class="pwf-product-preview-meta">';
            echo '<span><strong>' . esc_html(pwf_t('SKU:')) . '</strong> ' . esc_html(($p && $p->get_sku()) ? $p->get_sku() : '—') . '</span>';
            if ($p && $p->get_categories()) {
                echo '<span>· <strong>' . esc_html(pwf_t('Category:')) . '</strong> ' . wp_strip_all_tags($p->get_categories()) . '</span>';
            }
            echo '</div></div>';
            echo '<a href="' . esc_url(PWF_Admin::url($task->product_id)) . '" class="button pwf-btn-primary" style="height:36px; padding:0 16px; border-radius:8px; font-size:12px; display:inline-flex; align-items:center; gap:6px;"><span class="dashicons dashicons-external"></span> ' . esc_html(pwf_t('Product Pipeline')) . '</a>';
            echo '</div>';
        }

        // Linked Request Card
        if ($task->request_id) {
            $req = PWF_Request_Manager::get($task->request_id);
            if ($req) {
                echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:12px;">';
                echo '<div style="display:flex; align-items:center; gap:8px;">';
                echo '<span style="font-size:18px;">📥</span>';
                echo '<div><strong style="font-size:13px; color:#1e293b;">' . esc_html(pwf_t('Parent Request:')) . ' #' . absint($req->request_id) . ' ' . esc_html($req->title) . '</strong>';
                echo '<div style="font-size:11px; color:#64748b;">' . esc_html(pwf_t('Status:')) . ' ' . esc_html(PWF_Request_Manager::statuses()[$req->status] ?? $req->status) . '</div></div>';
                echo '</div>';
                echo '<a href="' . esc_url(admin_url('admin.php?page=pwf-request&request_id=' . $req->request_id)) . '" class="button button-secondary" style="height:32px; font-size:12px; border-radius:6px;">' . esc_html(pwf_t('View Request')) . '</a>';
                echo '</div>';
            }
        }

        // Task Description / Guidelines
        if ($task->description) {
            echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; margin-top:14px;">';
            echo '<strong style="display:flex; align-items:center; gap:6px; font-size:13px; color:#334155; margin-bottom:6px;"><span class="dashicons dashicons-editor-alignleft" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Guidelines & Instructions:')) . '</strong>';
            echo '<div style="font-size:13px; color:#1e293b; line-height:1.6;">' . wp_kses_post(wpautop($task->description)) . '</div>';
            echo '</div>';
        }

        // Attachments (Reference material sent by management/assigner)
        $attachments = !empty($task->attachments) ? json_decode($task->attachments, true) : array();
        if (!empty($attachments) && is_array($attachments)) {
            echo '<div style="margin-top:20px; border-top:1px solid #f1f5f9; padding-top:16px;">';
            echo '<strong style="display:flex; align-items:center; gap:6px; font-size:13px; color:#334155; margin-bottom:10px;"><span class="dashicons dashicons-paperclip" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Reference Attachments & Guides')) . ' (' . count($attachments) . '):</strong>';
            echo '<div class="pwf-attachment-grid" style="display:flex; flex-wrap:wrap; gap:12px;">';
            foreach ($attachments as $att) {
                $is_img = !empty($att['is_image']) || preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $att['url']);
                $fsize = !empty($att['size']) ? size_format($att['size']) : '';
                if ($is_img) {
                    echo '<div class="pwf-attachment-thumb" style="border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; width:110px; text-align:center; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.05);">';
                    echo '<a href="' . esc_url($att['url']) . '" target="_blank" title="' . esc_attr($att['name']) . '">';
                    echo '<img src="' . esc_url($att['url']) . '" style="width:110px; height:85px; object-fit:cover; display:block;">';
                    echo '</a>';
                    echo '<div style="padding:4px; font-size:10px; color:#475569; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' . esc_attr($att['name']) . '">' . esc_html($att['name']) . '</div>';
                    echo '</div>';
                } else {
                    echo '<div class="pwf-attachment-file" style="border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; background:#fff; display:inline-flex; align-items:center; gap:10px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">';
                    echo '<span class="dashicons dashicons-media-document" style="color:var(--pwf-primary); font-size:20px; width:20px; height:20px;"></span>';
                    echo '<div><a href="' . esc_url($att['url']) . '" target="_blank" style="font-weight:600; font-size:12px; text-decoration:none; color:#1e293b;">' . esc_html($att['name']) . '</a>';
                    if ($fsize) { echo '<div style="font-size:10px; color:#64748b;">' . esc_html($fsize) . '</div>'; }
                    echo '</div></div>';
                }
            }
            echo '</div></div>';
        }

        echo '</section>';

        // Deliverables Section (Submissions from worker)
        $deliv_files = !empty($task->deliverable_files) ? json_decode($task->deliverable_files, true) : array();
        if ($task->deliverable_url || $task->deliverable_note || !empty($deliv_files)) {
            echo '<section class="pwf-card" style="border-left:4px solid var(--pwf-success); margin-bottom:20px;">';
            echo '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">';
            echo '<h2 style="margin:0; font-size:15px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;"><span class="dashicons dashicons-saved" style="color:var(--pwf-success);"></span> ' . esc_html(pwf_t('Submitted Deliverables')) . '</h2>';
            echo '<span style="font-size:11px; background:#ecfdf5; color:#059669; padding:3px 8px; border-radius:6px; font-weight:600;">✓ ' . esc_html(pwf_t('Delivered')) . '</span>';
            echo '</div>';

            if ($task->deliverable_url) {
                echo '<div style="margin-bottom:14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">';
                echo '<a href="' . esc_url($task->deliverable_url) . '" target="_blank" class="button button-primary" style="height:36px; padding:0 18px; border-radius:8px; display:inline-flex; align-items:center; gap:6px; background:#059669; border-color:#059669;"><span class="dashicons dashicons-external"></span> ' . esc_html(pwf_t('Open Deliverable Link')) . '</a>';
                echo '<code style="font-size:12px; background:#f1f5f9; padding:6px 12px; border-radius:6px; border:1px solid #e2e8f0; color:#334155;">' . esc_html($task->deliverable_url) . '</code>';
                echo '</div>';
            }

            if (!empty($deliv_files) && is_array($deliv_files)) {
                echo '<div style="margin-top:14px;"><strong style="font-size:12px; color:#334155; display:block; margin-bottom:8px;">' . esc_html(pwf_t('Delivered Files:')) . ' (' . count($deliv_files) . ')</strong>';
                echo '<div style="display:flex; flex-wrap:wrap; gap:12px;">';
                foreach ($deliv_files as $df) {
                    $is_img = !empty($df['is_image']) || preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $df['url']);
                    $dfsize = !empty($df['size']) ? size_format($df['size']) : '';
                    if ($is_img) {
                        echo '<div style="border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; width:120px; text-align:center; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.05);">';
                        echo '<a href="' . esc_url($df['url']) . '" target="_blank">';
                        echo '<img src="' . esc_url($df['url']) . '" style="width:120px; height:90px; object-fit:cover; display:block;">';
                        echo '</a>';
                        echo '<div style="padding:4px; font-size:10px; color:#475569; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' . esc_attr($df['name']) . '">' . esc_html($df['name']) . '</div>';
                        echo '</div>';
                    } else {
                        echo '<div style="border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; background:#fff; display:inline-flex; align-items:center; gap:10px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">';
                        echo '<span class="dashicons dashicons-paperclip" style="color:var(--pwf-success); font-size:20px; width:20px; height:20px;"></span>';
                        echo '<div><a href="' . esc_url($df['url']) . '" target="_blank" style="font-weight:600; font-size:12px; text-decoration:none; color:#1e293b;">' . esc_html($df['name']) . '</a>';
                        if ($dfsize) { echo '<div style="font-size:10px; color:#64748b;">' . esc_html($dfsize) . '</div>'; }
                        echo '</div></div>';
                    }
                }
                echo '</div></div>';
            }

            if ($task->deliverable_note) {
                echo '<div style="margin-top:14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">';
                echo '<strong style="font-size:12px; color:#64748b; display:block; margin-bottom:4px;">' . esc_html(pwf_t('Worker Notes / Completion Summary:')) . '</strong>';
                echo '<div style="font-size:13px; color:#1e293b;">' . wp_kses_post(wpautop($task->deliverable_note)) . '</div>';
                echo '</div>';
            }
            echo '</section>';
        }

        // History
        $history = PWF_Task_Manager::history($tid);
        echo '<section class="pwf-card">';
        echo '<h2><span class="dashicons dashicons-backup" style="color:var(--pwf-slate-600);"></span> ' . esc_html(pwf_t('Task History')) . '</h2>';
        echo '<div class="pwf-table-container"><table class="widefat striped">';
        echo '<thead><tr><th>' . esc_html(pwf_t('Time / Person')) . '</th><th>' . esc_html(pwf_t('Event')) . '</th><th>' . esc_html(pwf_t('Note')) . '</th></tr></thead><tbody>';
        foreach ($history as $entry) {
            $actor = get_userdata($entry->changed_by);
            echo '<tr>';
            echo '<td style="font-size:12px;">' . esc_html(get_date_from_gmt($entry->changed_at) . ' · ' . ($actor ? $actor->display_name : pwf_t('System'))) . '</td>';
            echo '<td><strong>' . esc_html($entry->event) . '</strong><br><span style="font-size:11px; color:var(--pwf-slate-500);">';
            echo esc_html(($entry->previous_status ? $entry->previous_status . ' → ' : '') . $entry->new_status);
            echo '</span></td>';
            echo '<td>' . esc_html($entry->note) . '</td>';
            echo '</tr>';
        }
        if (!$history) {
            echo '<tr><td colspan="3" style="text-align:center; padding:20px; color:var(--pwf-slate-400);">&mdash;</td></tr>';
        }
        echo '</tbody></table></div></section>';
        echo '</div>'; // left col

        // ── RIGHT: Action panel ───────────────────────────────────────────────────
        echo '<aside>';

        echo '<section class="pwf-card" style="border-left:4px solid var(--pwf-primary); margin-bottom:20px;">';
        echo '<h2><span class="dashicons dashicons-controls-forward" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Available Actions')) . '</h2>';
        $action_rendered = false;

        // Worker: Start
        if ($is_assignee && $task->status === 'assigned') {
            $action_rendered = true;
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:14px;">';
            wp_nonce_field('pwf_task_start_' . $tid);
            echo '<input type="hidden" name="action" value="pwf_task_action">';
            echo '<input type="hidden" name="operation" value="start">';
            echo '<input type="hidden" name="task_id" value="' . absint($tid) . '">';
            echo '<button type="submit" class="button button-primary" style="width:100%; height:44px; border-radius:8px; font-weight:700; font-size:14px; background:#4f46e5; border-color:#4f46e5; display:flex; align-items:center; justify-content:center; gap:8px;">▶ ' . esc_html(pwf_t('Start Task')) . '</button>';
            echo '</form>';
        }

        // Worker: Submit deliverables
        if ($is_assignee && in_array($task->status, array('in_progress', 'needs_revision'), true)) {
            $action_rendered = true;
            echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:16px;">';
            echo '<h4 style="margin:0 0 12px 0; font-size:13px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:6px;"><span class="dashicons dashicons-cloud-upload" style="color:var(--pwf-success);"></span> ' . esc_html(pwf_t('Submit Deliverables')) . '</h4>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
            wp_nonce_field('pwf_task_submit_' . $tid);
            echo '<input type="hidden" name="action" value="pwf_task_action">';
            echo '<input type="hidden" name="operation" value="submit">';
            echo '<input type="hidden" name="task_id" value="' . absint($tid) . '">';
            echo '<div style="margin-bottom:12px;"><label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">' . esc_html(pwf_t('Deliverable URL (Drive, Git, Figma…)')) . '</label>';
            echo '<input type="url" name="deliverable_url" value="' . esc_attr($task->deliverable_url) . '" style="width:100%; height:38px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 10px; font-size:13px;" placeholder="https://…"></div>';
            echo '<div style="margin-bottom:12px;"><label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">' . esc_html(pwf_t('Upload Deliverable Files / Photos (Optional)')) . '</label>';
            echo '<input type="file" name="deliverable_files[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar" style="width:100%; font-size:12px;"></div>';
            echo '<div style="margin-bottom:14px;"><label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">' . esc_html(pwf_t('Notes / completion summary')) . '</label>';
            echo '<textarea name="deliverable_note" rows="3" style="width:100%; border-radius:8px; border:1.5px solid #cbd5e1; padding:8px 10px; font-size:13px;" placeholder="' . esc_attr(pwf_t('Briefly describe what was completed or include instructions for review...')) . '">' . esc_textarea($task->deliverable_note) . '</textarea></div>';
            echo '<button type="submit" class="button button-primary" style="width:100%; height:42px; border-radius:8px; font-weight:700; font-size:13px; background:#059669; border-color:#059669; display:flex; align-items:center; justify-content:center; gap:8px;">📤 ' . esc_html(pwf_t('Submit for Review')) . '</button>';
            echo '</form></div>';
        }

        // Worker/Mgmt: Resume from hold
        if (($is_assignee || $is_mgmt) && $task->status === 'on_hold') {
            $action_rendered = true;
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:14px;">';
            wp_nonce_field('pwf_task_resume_' . $tid);
            echo '<input type="hidden" name="action" value="pwf_task_action">';
            echo '<input type="hidden" name="operation" value="resume">';
            echo '<input type="hidden" name="task_id" value="' . absint($tid) . '">';
            echo '<button type="submit" class="button button-primary" style="width:100%; height:40px; border-radius:8px; font-weight:700; font-size:13px; background:#4f46e5; border-color:#4f46e5;">▶ ' . esc_html(pwf_t('Resume Task')) . '</button>';
            echo '</form>';
        }

        // Worker/Mgmt: Put on hold
        if (($is_assignee || $is_mgmt) && in_array($task->status, array('assigned', 'in_progress'), true)) {
            $action_rendered = true;
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:14px;">';
            wp_nonce_field('pwf_task_hold_' . $tid);
            echo '<input type="hidden" name="action" value="pwf_task_action">';
            echo '<input type="hidden" name="operation" value="hold">';
            echo '<input type="hidden" name="task_id" value="' . absint($tid) . '">';
            echo '<div style="margin-bottom:8px;"><label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">' . esc_html(pwf_t('Hold reason (optional)')) . '</label>';
            echo '<input type="text" name="note" style="width:100%; height:36px; border-radius:6px; border:1.5px solid #cbd5e1; padding:0 10px; font-size:12px;" placeholder="' . esc_attr(pwf_t('e.g. Waiting for factory sample')) . '"></div>';
            echo '<button type="submit" class="button button-secondary" style="width:100%; height:36px; border-radius:6px; font-size:12px;">⏸ ' . esc_html(pwf_t('Put on Hold')) . '</button>';
            echo '</form>';
        }

        // Management: Approve
        if ($is_mgmt && $task->status === 'submitted') {
            $action_rendered = true;
            echo '<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px; margin-bottom:16px;">';
            echo '<h4 style="margin:0 0 10px 0; font-size:13px; font-weight:700; color:#166534; display:flex; align-items:center; gap:6px;">✅ ' . esc_html(pwf_t('Approve Deliverables')) . '</h4>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('pwf_task_approve_' . $tid);
            echo '<input type="hidden" name="action" value="pwf_task_action">';
            echo '<input type="hidden" name="operation" value="approve">';
            echo '<input type="hidden" name="task_id" value="' . absint($tid) . '">';
            echo '<div style="margin-bottom:10px;"><label style="display:block; font-size:12px; color:#166534; margin-bottom:4px;">' . esc_html(pwf_t('Approval note (optional)')) . '</label>';
            echo '<input type="text" name="note" style="width:100%; height:36px; border-radius:6px; border:1.5px solid #86efac; padding:0 10px; font-size:12px;" placeholder="' . esc_attr(pwf_t('e.g. Great quality, approved.')) . '"></div>';
            echo '<button type="submit" class="button button-primary" style="width:100%; height:40px; border-radius:8px; font-weight:700; font-size:13px; background:#16a34a; border-color:#16a34a;">' . esc_html(pwf_t('Approve & Complete')) . '</button>';
            echo '</form></div>';

            // Request revision
            echo '<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:14px; margin-bottom:16px;">';
            echo '<h4 style="margin:0 0 10px 0; font-size:13px; font-weight:700; color:#991b1b; display:flex; align-items:center; gap:6px;">🔴 ' . esc_html(pwf_t('Request Revision')) . '</h4>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('pwf_task_revision_' . $tid);
            echo '<input type="hidden" name="action" value="pwf_task_action">';
            echo '<input type="hidden" name="operation" value="revision">';
            echo '<input type="hidden" name="task_id" value="' . absint($tid) . '">';
            echo '<div style="margin-bottom:10px;"><label style="display:block; font-size:12px; color:#991b1b; margin-bottom:4px;"><strong>' . esc_html(pwf_t('Correction note')) . ' <span style="color:#ef4444;">*</span></strong></label>';
            echo '<textarea name="note" rows="3" style="width:100%; border-radius:6px; border:1.5px solid #fca5a5; padding:8px 10px; font-size:12px;" required placeholder="' . esc_attr(pwf_t('Describe exactly what needs to be corrected...')) . '"></textarea></div>';
            echo '<button type="submit" class="button button-secondary" style="width:100%; height:38px; border-radius:8px; font-weight:700; font-size:12px; color:#dc2626; border-color:#fca5a5;">' . esc_html(pwf_t('Request Revision')) . '</button>';
            echo '</form></div>';
        }

        // Management: Cancel
        if ($is_mgmt && !in_array($task->status, array('approved', 'cancelled'), true)) {
            $action_rendered = true;
            echo '<div style="border-top:1px solid #f1f5f9; padding-top:14px; margin-top:14px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(pwf_t('Are you sure you want to cancel this task?')) . '\');">';
            wp_nonce_field('pwf_task_cancel_' . $tid);
            echo '<input type="hidden" name="action" value="pwf_task_action">';
            echo '<input type="hidden" name="operation" value="cancel">';
            echo '<input type="hidden" name="task_id" value="' . absint($tid) . '">';
            echo '<div style="margin-bottom:8px;"><label style="display:block; font-size:11px; color:#64748b; margin-bottom:3px;">' . esc_html(pwf_t('Cancellation reason')) . '</label>';
            echo '<input type="text" name="note" style="width:100%; height:34px; border-radius:6px; border:1.5px solid #cbd5e1; padding:0 8px; font-size:12px;"></div>';
            echo '<button type="submit" class="button" style="width:100%; height:34px; border-radius:6px; font-size:12px; color:#dc2626; border-color:#fecaca;">✕ ' . esc_html(pwf_t('Cancel Task')) . '</button>';
            echo '</form></div>';
        }

        if (!$action_rendered) {
            echo '<p style="color:var(--pwf-slate-400); font-style:italic; font-size:13px; margin:0;">' . esc_html(pwf_t('No actions available for you at this stage.')) . '</p>';
        }
        echo '</section>';

        // Management: reassign / assign
        if ($is_mgmt && !in_array($task->status, array('approved', 'cancelled'), true)) {
            $is_already_assigned = !empty($task->assigned_to);
            $card_title = $is_already_assigned ? pwf_t('Reassign Task') : pwf_t('Assign Task');
            echo '<section class="pwf-card" style="margin-bottom:20px;">';
            echo '<h2><span class="dashicons dashicons-admin-users" style="color:var(--pwf-secondary);"></span> ' . esc_html($card_title) . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('pwf_task_reassign_' . $tid);
            echo '<input type="hidden" name="action" value="pwf_task_action">';
            echo '<input type="hidden" name="operation" value="reassign">';
            echo '<input type="hidden" name="task_id" value="' . absint($tid) . '">';

            echo '<div style="margin-bottom:12px;"><label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">' . esc_html(pwf_t('Assign to')) . ' <span style="color:#ef4444;">*</span></label>';
            echo '<select name="assigned_to" required style="width:100%; height:38px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 10px; font-size:13px;">';
            echo '<option value="">' . esc_html(pwf_t('Choose user…')) . '</option>';
            $role_map = array(
                'pwf_photographer'  => pwf_t('Photographer'),
                'pwf_programmer'    => pwf_t('Programmer'),
                'pwf_digital_admin' => pwf_t('Digital Admin'),
                'pwf_secretary'     => pwf_t('Factory Secretary'),
                'pwf_sales_manager' => pwf_t('Sales Manager'),
                'pwf_management'    => pwf_t('Management & Planning'),
                'pwf_ceo'           => pwf_t('CEO'),
                'administrator'     => pwf_t('Administrator'),
            );
            $users = get_users(array('orderby' => 'display_name', 'number' => 200));
            foreach ($users as $u) {
                $role_label = '';
                if (!empty($u->roles)) {
                    $r = $u->roles[0];
                    $role_label = $role_map[$r] ?? ucfirst(str_replace('pwf_', '', $r));
                }
                $label = $u->display_name . ($role_label ? ' (' . $role_label . ')' : '');
                echo '<option value="' . absint($u->ID) . '" ' . selected((int) $task->assigned_to, (int) $u->ID, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></div>';

            echo '<div style="margin-bottom:12px;"><label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">' . esc_html(pwf_t('Due Date')) . '</label>';
            echo '<input type="date" name="due_date" value="' . esc_attr($task->due_date ?: '') . '" style="width:100%; height:38px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 10px; font-size:13px;"></div>';

            echo '<div style="margin-bottom:12px;"><label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">' . esc_html(pwf_t('Priority')) . '</label>';
            echo '<select name="priority" style="width:100%; height:38px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 10px; font-size:13px;">';
            foreach (PWF_Task_Manager::priorities() as $key => $plabel) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($task->priority, $key, false) . '>' . esc_html($plabel) . '</option>';
            }
            echo '</select></div>';

            echo '<div style="margin-bottom:14px;"><label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">' . esc_html(pwf_t('Reassignment Note / Instructions')) . '</label>';
            echo '<textarea name="note" rows="2" style="width:100%; border-radius:8px; border:1.5px solid #cbd5e1; padding:8px 10px; font-size:12px;" placeholder="' . esc_attr(pwf_t('Reason or instructions for the new assignee…')) . '"></textarea></div>';

            $btn_text = $is_already_assigned ? pwf_t('Save Reassignment') : pwf_t('Assign Task');
            echo '<button type="submit" class="button button-secondary" style="width:100%; height:40px; border-radius:8px; font-weight:700; font-size:13px;">' . esc_html($btn_text) . '</button>';
            echo '</form>';
            echo '</section>';
        }

        echo '</aside>';
        echo '</div>'; // grid
        echo '</div>'; // wrap
    }
}

function pwf_task_detail_page() { PWF_Task_Page::render(); }
