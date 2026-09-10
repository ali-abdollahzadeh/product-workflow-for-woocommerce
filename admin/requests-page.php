<?php
defined('ABSPATH') || exit;

/**
 * Requests admin page: intake form for CEO/Secretary/Sales Manager,
 * and management view to review + convert requests to tasks.
 */
class PWF_Requests_Page {

    public static function boot() {
        add_action('admin_post_pwf_request_action', array(__CLASS__, 'handle'));
    }

    /** Admin-post handler for all request operations. */
    public static function handle() {
        if (!current_user_can('create_workflow_request') && !current_user_can('manage_workflow')) {
            wp_die(pwf_t('Access denied.'), '', array('response' => 403));
        }
        $data = wp_unslash($_POST);
        $op   = sanitize_key($data['operation'] ?? '');
        $rid  = absint($data['request_id'] ?? 0);
        check_admin_referer('pwf_request_' . $op . '_' . $rid);

        switch ($op) {
            case 'create':
                $result = PWF_Request_Manager::create($data);
                if (!is_wp_error($result)) {
                    $rid = $result->request_id;
                    self::notice(false, pwf_t('Request submitted successfully.'));
                } else {
                    self::notice(true, $result->get_error_message());
                }
                wp_safe_redirect(admin_url('admin.php?page=pwf-requests'));
                exit;

            case 'status':
                if (!current_user_can('manage_workflow')) { wp_die(pwf_t('Access denied.'), '', array('response' => 403)); }
                if (!PWF_Permission_Manager::can_view_request($rid)) { wp_die(pwf_t('Access denied.'), '', array('response' => 403)); }
                $result = PWF_Request_Manager::update_status($rid, sanitize_key($data['new_status'] ?? ''), $data['note'] ?? '');
                if (is_wp_error($result)) {
                    self::notice(true, $result->get_error_message());
                } else {
                    self::notice(false, pwf_t('Request status updated.'));
                }
                wp_safe_redirect(admin_url('admin.php?page=pwf-request&request_id=' . $rid));
                exit;

            case 'create_task':
                if (!current_user_can('assign_tasks')) { wp_die(pwf_t('Access denied.'), '', array('response' => 403)); }
                $data['request_id'] = $rid;
                $result = PWF_Task_Manager::create($data);
                if (is_wp_error($result)) {
                    self::notice(true, $result->get_error_message());
                    wp_safe_redirect(admin_url('admin.php?page=pwf-request&request_id=' . $rid));
                } else {
                    self::notice(false, pwf_t('Task created from request.'));
                    wp_safe_redirect(admin_url('admin.php?page=pwf-task&task_id=' . $result->task_id));
                }
                exit;

            default:
                wp_safe_redirect(admin_url('admin.php?page=pwf-requests'));
                exit;
        }
    }

    private static function notice($is_error, $text) {
        set_transient('pwf_req_notice_' . get_current_user_id(), array('error' => $is_error, 'text' => $text), 60);
    }

    private static function show_notice() {
        $key    = 'pwf_req_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if ($notice) {
            delete_transient($key);
            echo '<div class="notice ' . ($notice['error'] ? 'notice-error' : 'notice-success') . ' pwf-notice"><p>' . esc_html($notice['text']) . '</p></div>';
        }
    }

    /** Requests list + intake form. */
    public static function render() {
        if (!current_user_can('create_workflow_request') && !current_user_can('manage_workflow')) {
            wp_die(pwf_t('Access denied.'));
        }
        echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';

        // Hero
        echo '<div class="pwf-hero">';
        echo '<div class="pwf-hero-title">';
        echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-portfolio"></span></div>';
        echo '<div><h1>' . esc_html(pwf_t('Requests')) . '</h1>';
        echo '<p>' . esc_html(pwf_t('Submit and track requests for tasks. Management reviews and converts them to actionable tasks.')) . '</p></div>';
        echo '</div>';
        echo '<div class="pwf-hero-actions">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf')) . '" class="button pwf-btn-subtle">&larr; ' . esc_html(pwf_t('Dashboard')) . '</a>';
        echo '</div></div>';

        self::show_notice();

        // Difference explainer banner
        echo '<div class="pwf-guide-banner" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 22px; margin-bottom:22px; box-shadow:0 1px 3px rgba(0,0,0,0.03);">';
        echo '<div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">';
        echo '<span style="font-size:20px;">💡</span>';
        echo '<strong style="font-size:14px; color:#0f172a;">' . esc_html(pwf_t('Workflow Guide: Understanding Requests, Tasks & Products')) . '</strong>';
        echo '</div>';
        echo '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px;">';
        echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; border-right:4px solid #4f46e5;">';
        echo '<strong style="color:#4f46e5; font-size:13px;">📥 ' . esc_html(pwf_t('Request (Intake)')) . '</strong>';
        echo '<p style="margin:4px 0 0; font-size:12px; color:#64748b; line-height:1.5;">' . esc_html(pwf_t('Initial proposal or demand from factory/sales with sample photos and reference files. Management reviews it and converts it into specific Tasks.')) . '</p>';
        echo '</div>';
        echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; border-right:4px solid #0284c7;">';
        echo '<strong style="color:#0284c7; font-size:13px;">📋 ' . esc_html(pwf_t('Task (Execution)')) . '</strong>';
        echo '<p style="margin:4px 0 0; font-size:12px; color:#64748b; line-height:1.5;">' . esc_html(pwf_t('Assigned to a specific person (photographer, writer, programmer) with a clear deadline, attached guidelines, and a deliverable submission flow.')) . '</p>';
        echo '</div>';
        echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; border-right:4px solid #10b981;">';
        echo '<strong style="color:#10b981; font-size:13px;">📦 ' . esc_html(pwf_t('Product Pipeline')) . '</strong>';
        echo '<p style="margin:4px 0 0; font-size:12px; color:#64748b; line-height:1.5;">' . esc_html(pwf_t('The end-to-end WooCommerce catalog item tracked from production intake, photo shoot, copywriting, QA approval, to live site publishing.')) . '</p>';
        echo '</div>';
        echo '</div></div>';

        // Intake form
        if (current_user_can('create_workflow_request') || current_user_can('create_product_request')) {
            echo '<div class="pwf-card pwf-request-create-card" style="margin-bottom:24px; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 2px 8px rgba(0,0,0,0.04); background:#fff; padding:22px 24px;">';
            echo '<div style="display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #f1f5f9; padding-bottom:14px; margin-bottom:18px;">';
            echo '<div style="display:flex; align-items:center; gap:10px;">';
            echo '<div style="width:36px; height:36px; border-radius:8px; background:#eef2ff; color:#4f46e5; display:flex; align-items:center; justify-content:center;"><span class="dashicons dashicons-plus-alt2" style="font-size:20px; width:20px; height:20px;"></span></div>';
            echo '<div><h3 style="margin:0; font-size:16px; font-weight:700; color:#0f172a;">' . esc_html(pwf_t('Submit a new request')) . '</h3>';
            echo '<p style="margin:2px 0 0; font-size:12px; color:#64748b;">' . esc_html(pwf_t('Submit requests for factory production, photography, content, or system improvements.')) . '</p></div>';
            echo '</div></div>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
            wp_nonce_field('pwf_request_create_0');
            echo '<input type="hidden" name="action" value="pwf_request_action">';
            echo '<input type="hidden" name="operation" value="create">';
            echo '<input type="hidden" name="request_id" value="0">';

            echo '<div style="display:grid; grid-template-columns:minmax(0, 2fr) minmax(0, 1fr); gap:16px; margin-bottom:16px;">';
            echo '<div>';
            echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Title')) . ' <span style="color:#ef4444;">*</span></label>';
            echo '<input class="regular-text" name="title" required maxlength="255" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;" placeholder="' . esc_attr(pwf_t('E.g. Photography of new crystal bowl series')) . '">';
            echo '</div>';

            echo '<div>';
            echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Request Type')) . '</label>';
            echo '<select name="request_type" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;">';
            foreach (PWF_Request_Manager::types() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '</div>';
            echo '</div>';

            echo '<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">';
            echo '<div>';
            echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Priority')) . '</label>';
            echo '<select name="priority" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;">';
            foreach (PWF_Request_Manager::priorities() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($key, 'normal', false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '</div>';

            echo '<div>';
            echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Linked Product ID (optional)')) . '</label>';
            echo '<input type="number" min="1" name="product_id" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;" placeholder="—">';
            echo '</div>';
            echo '</div>';

            echo '<div style="margin-bottom:16px;">';
            echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Description / Notes')) . '</label>';
            echo '<textarea name="description" rows="3" class="large-text" style="width:100%; border-radius:8px; border:1.5px solid #cbd5e1; padding:10px 12px; font-size:13px;" placeholder="' . esc_attr(pwf_t('Explain the goal, details, and initial requirements of this request...')) . '"></textarea>';
            echo '</div>';

            // Attachment upload for requests
            echo '<div class="pwf-attachment-upload-box" style="background:#f8fafc; border:1.5px dashed #cbd5e1; border-radius:12px; padding:18px; margin:16px 0;">';
            echo '<label style="display:block; cursor:pointer;">';
            echo '<strong style="display:flex; align-items:center; gap:8px; font-size:14px; color:#1e293b;">';
            echo '<span class="dashicons dashicons-paperclip" style="color:var(--pwf-primary);"></span> ';
            echo esc_html(pwf_t('Attach Photos & Files (Optional)'));
            echo '</strong>';
            echo '<p style="margin:4px 0 10px; font-size:12px; color:#64748b;">' . esc_html(pwf_t('Send reference photos, factory samples, PDFs, or ZIP files to help management evaluate and plan.')) . '</p>';
            echo '<input type="file" name="request_attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar" style="font-size:13px; width:100%;">';
            echo '</label>';
            echo '</div>';

            echo '<div style="display:flex; justify-content:flex-start;">';
            submit_button(pwf_t('Submit Request'), 'primary', 'submit', false, array('style' => 'height:40px; padding:0 24px; border-radius:8px; font-weight:600; font-size:13px; background:#4f46e5; border-color:#4f46e5;'));
            echo '</div>';
            echo '</form></div>';
        }

        // Requests table
        $filters = array(
            'status'   => sanitize_key(wp_unslash($_GET['status'] ?? '')),
            'search'   => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'page'     => max(1, absint($_GET['paged'] ?? 1)),
        );
        $result = PWF_Request_Manager::list($filters);
        $rows   = $result['rows'];
        $total  = $result['total'];

        // Filter bar
        echo '<div class="pwf-filters-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 18px; margin-bottom:18px;">';
        echo '<form method="get" class="pwf-filters" style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin:0;">';
        echo '<input type="hidden" name="page" value="pwf-requests">';
        echo '<div class="pwf-filter-item" style="display:flex; flex-direction:column; gap:4px;">';
        echo '<label style="font-size:12px; font-weight:700; color:#475569;">' . esc_html(pwf_t('Status')) . '</label>';
        echo '<select name="status" style="width:180px; height:38px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:13px;">';
        echo '<option value="">' . esc_html(pwf_t('All statuses')) . '</option>';
        foreach (PWF_Request_Manager::statuses() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($filters['status'], $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="pwf-filter-item pwf-filter-search" style="display:flex; flex-direction:column; gap:4px;">';
        echo '<label style="font-size:12px; font-weight:700; color:#475569;">' . esc_html(pwf_t('Search')) . '</label>';
        echo '<input type="text" name="s" value="' . esc_attr($filters['search']) . '" placeholder="' . esc_attr(pwf_t('Request title or details…')) . '" style="width:260px; height:38px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:13px;"></div>';
        echo '<div class="pwf-filter-item pwf-filter-btn"><button class="button button-primary pwf-btn-filter" style="height:38px; padding:0 20px; border-radius:8px; font-weight:600; font-size:13px; background:#4f46e5; border-color:#4f46e5; color:#fff;">' . esc_html(pwf_t('Filter')) . '</button></div>';
        if ($filters['status'] || $filters['search']) {
            echo '<div class="pwf-filter-item pwf-filter-btn"><a href="' . esc_url(admin_url('admin.php?page=pwf-requests')) . '" class="button button-link pwf-btn-clear" style="height:38px; display:inline-flex; align-items:center; gap:4px; font-size:13px;"><span class="dashicons dashicons-dismiss"></span> ' . esc_html(pwf_t('Clear filters')) . '</a></div>';
        }
        echo '</form></div>';

        // Table
        echo '<div class="pwf-table-container"><table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html(pwf_t('ID / Title')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Type')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Priority')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Status')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Submitted By')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Date')) . '</th>';
        echo '<th style="text-align:center;">' . esc_html(pwf_t('Action')) . '</th>';
        echo '</tr></thead><tbody>';

        $priority_colors = array('urgent' => 'pwf-badge-admin', 'high' => 'pwf-badge-error', 'normal' => 'pwf-badge-role', 'low' => 'pwf-badge-default');
        $status_colors   = array('submitted' => 'pwf-status-waiting_photography', 'in_review' => 'pwf-status-waiting_review', 'planned' => 'pwf-status-waiting_content', 'rejected' => 'pwf-status-cancelled', 'completed' => 'pwf-status-completed');

        foreach ($rows as $req) {
            $type_label   = PWF_Request_Manager::types()[$req->request_type]  ?? $req->request_type;
            $prio_label   = PWF_Request_Manager::priorities()[$req->priority] ?? $req->priority;
            $status_label = PWF_Request_Manager::statuses()[$req->status]     ?? $req->status;
            $submitter    = get_userdata($req->submitted_by);
            $detail_url   = admin_url('admin.php?page=pwf-request&request_id=' . $req->request_id);

            echo '<tr>';
            echo '<td><strong>#' . absint($req->request_id) . ' ' . esc_html($req->title) . '</strong></td>';
            echo '<td>' . esc_html($type_label) . '</td>';
            echo '<td><span class="pwf-badge ' . esc_attr($priority_colors[$req->priority] ?? 'pwf-badge-default') . '">' . esc_html($prio_label) . '</span></td>';
            echo '<td><span class="pwf-status ' . esc_attr($status_colors[$req->status] ?? '') . '">' . esc_html($status_label) . '</span></td>';
            echo '<td>' . esc_html($submitter ? $submitter->display_name : '—') . '</td>';
            echo '<td style="font-size:12px; color:var(--pwf-slate-500);">' . esc_html(get_date_from_gmt($req->created_at, 'Y-m-d')) . '</td>';
            echo '<td style="text-align:center;"><a class="button button-secondary" href="' . esc_url($detail_url) . '">' . esc_html(pwf_t('View')) . '</a></td>';
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="7" style="text-align:center; padding:30px; color:var(--pwf-slate-400);">' . esc_html(pwf_t('No requests found.')) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        // Pagination
        echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; font-size:13px;">';
        echo '<span>' . esc_html(sprintf(pwf_t('Requests: %d'), $total)) . '</span>';
        echo wp_kses_post((string) paginate_links(array(
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'current'   => $filters['page'],
            'total'     => (int) ceil($total / 20),
            'prev_text' => pwf_t('Previous'),
            'next_text' => pwf_t('Next'),
        )));
        echo '</div>';
        echo '</div>'; // wrap
    }

    /** Single request detail: view, status transitions, and create-task form. */
    public static function render_detail() {
        $rid = absint($_GET['request_id'] ?? 0);
        if (!$rid || !PWF_Permission_Manager::can_view_request($rid)) {
            wp_die(pwf_t('Access denied.'), '', array('response' => 403));
        }
        $req = PWF_Request_Manager::get($rid);
        if (!$req) { wp_die(pwf_t('Request not found.')); }

        $status_label = PWF_Request_Manager::statuses()[$req->status] ?? $req->status;
        $type_label   = PWF_Request_Manager::types()[$req->request_type] ?? $req->request_type;
        $prio_label   = PWF_Request_Manager::priorities()[$req->priority] ?? $req->priority;
        $submitter    = get_userdata($req->submitted_by);
        $linked_tasks = PWF_Request_Manager::tasks($rid);

        echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';
        echo '<div class="pwf-hero">';
        echo '<div class="pwf-hero-title">';
        echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-portfolio"></span></div>';
        echo '<div><h1>' . esc_html($req->title) . ' <span style="font-size:14px; font-weight:400; opacity:0.8;">#' . absint($rid) . '</span></h1>';
        echo '<div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:6px;">';
        echo '<span class="pwf-badge pwf-badge-role">' . esc_html($type_label) . '</span>';
        echo '<span class="pwf-badge pwf-badge-admin">' . esc_html($prio_label) . '</span>';
        echo '<span style="font-size:13px; color:#e0e7ff;">' . esc_html($status_label) . '</span>';
        echo '</div></div></div>';
        echo '<div class="pwf-hero-actions">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf-requests')) . '" class="button pwf-btn-subtle">&larr; ' . esc_html(pwf_t('All Requests')) . '</a>';
        echo '</div></div>';

        self::show_notice();

        echo '<div class="pwf-grid">';

        // ── LEFT: Details + linked tasks + history ───────────────────────────────
        echo '<div>';

        // Request info card
        echo '<section class="pwf-card" style="margin-bottom:20px;">';
        echo '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">';
        echo '<h2 style="margin:0; font-size:15px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;"><span class="dashicons dashicons-info" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Request Overview & Metadata')) . '</h2>';
        echo '<span style="font-size:11px; background:#eef2ff; color:#4f46e5; padding:3px 8px; border-radius:6px; font-weight:600;">📥 ' . esc_html(pwf_t('Intake Request')) . '</span>';
        echo '</div>';

        echo '<div class="pwf-meta-grid">';

        echo '<div class="pwf-meta-item">';
        echo '<span class="pwf-meta-label">👤 ' . esc_html(pwf_t('Submitted by')) . '</span>';
        echo '<span class="pwf-meta-val">' . esc_html($submitter ? $submitter->display_name : '—') . '</span>';
        echo '</div>';

        echo '<div class="pwf-meta-item">';
        echo '<span class="pwf-meta-label">📅 ' . esc_html(pwf_t('Submitted on')) . '</span>';
        echo '<span class="pwf-meta-val">' . esc_html(get_date_from_gmt($req->created_at)) . '</span>';
        echo '</div>';

        echo '<div class="pwf-meta-item">';
        echo '<span class="pwf-meta-label">🏷️ ' . esc_html(pwf_t('Request Type')) . '</span>';
        echo '<span class="pwf-meta-val">' . esc_html($type_label) . '</span>';
        echo '</div>';

        $prio_cls = array('urgent' => 'pwf-badge-admin', 'high' => 'pwf-badge-error', 'normal' => 'pwf-badge-role', 'low' => 'pwf-badge-default');
        echo '<div class="pwf-meta-item">';
        echo '<span class="pwf-meta-label">⚡ ' . esc_html(pwf_t('Priority')) . '</span>';
        echo '<span class="pwf-meta-val"><span class="pwf-badge ' . esc_attr($prio_cls[$req->priority] ?? 'pwf-badge-default') . '">' . esc_html($prio_label) . '</span></span>';
        echo '</div>';

        echo '</div>'; // pwf-meta-grid

        // Linked Product Card
        if ($req->product_id) {
            $p = wc_get_product($req->product_id);
            echo '<div class="pwf-product-preview-card">';
            $img_id = $p ? $p->get_image_id() : 0;
            $thumb_src = $img_id ? wp_get_attachment_image_url($img_id, 'thumbnail') : '';
            if ($thumb_src) {
                echo '<img src="' . esc_url($thumb_src) . '" class="pwf-product-preview-thumb" alt="' . esc_attr($p ? $p->get_name() : '') . '">';
            } else {
                echo '<div class="pwf-product-preview-thumb" style="background:#eef2ff; color:#4f46e5; display:flex; align-items:center; justify-content:center; font-size:24px;">📦</div>';
            }
            echo '<div class="pwf-product-preview-info">';
            echo '<h4 class="pwf-product-preview-title">' . esc_html($p ? $p->get_name() : ('#' . $req->product_id)) . '</h4>';
            echo '<div class="pwf-product-preview-meta">';
            echo '<span><strong>' . esc_html(pwf_t('SKU:')) . '</strong> ' . esc_html(($p && $p->get_sku()) ? $p->get_sku() : '—') . '</span>';
            if ($p && $p->get_categories()) {
                echo '<span>· <strong>' . esc_html(pwf_t('Category:')) . '</strong> ' . wp_strip_all_tags($p->get_categories()) . '</span>';
            }
            echo '</div></div>';
            echo '<a href="' . esc_url(PWF_Admin::url($req->product_id)) . '" class="button pwf-btn-primary" style="height:36px; padding:0 16px; border-radius:8px; font-size:12px; display:inline-flex; align-items:center; gap:6px;"><span class="dashicons dashicons-external"></span> ' . esc_html(pwf_t('Product Pipeline')) . '</a>';
            echo '</div>';
        }

        // Description
        if ($req->description) {
            echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; margin-top:14px;">';
            echo '<strong style="display:flex; align-items:center; gap:6px; font-size:13px; color:#334155; margin-bottom:6px;"><span class="dashicons dashicons-editor-alignleft" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Request Description & Details:')) . '</strong>';
            echo '<div style="font-size:13px; color:#1e293b; line-height:1.6;">' . wp_kses_post(wpautop($req->description)) . '</div>';
            echo '</div>';
        }

        // Attachments
        $attachments = !empty($req->attachments) ? json_decode($req->attachments, true) : array();
        if (!empty($attachments) && is_array($attachments)) {
            echo '<div style="margin-top:20px; border-top:1px solid #f1f5f9; padding-top:16px;">';
            echo '<strong style="display:flex; align-items:center; gap:6px; font-size:13px; color:#334155; margin-bottom:10px;"><span class="dashicons dashicons-paperclip" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Reference Attachments & Photos')) . ' (' . count($attachments) . '):</strong>';
            echo '<div class="pwf-attachment-grid" style="display:flex; flex-wrap:wrap; gap:12px;">';
            foreach ($attachments as $att) {
                $is_img = !empty($att['is_image']) || preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $att['url']);
                $fsize  = !empty($att['size']) ? size_format($att['size']) : '';
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

        // Linked tasks
        echo '<section class="pwf-card">';
        echo '<h2><span class="dashicons dashicons-list-view" style="color:var(--pwf-secondary);"></span> ' . esc_html(pwf_t('Linked Tasks')) . '</h2>';
        if ($linked_tasks) {
            echo '<div class="pwf-table-container"><table class="widefat striped">';
            echo '<thead><tr><th>' . esc_html(pwf_t('Task')) . '</th><th>' . esc_html(pwf_t('Type')) . '</th><th>' . esc_html(pwf_t('Priority')) . '</th><th>' . esc_html(pwf_t('Status')) . '</th><th>' . esc_html(pwf_t('Due')) . '</th><th></th></tr></thead><tbody>';
            foreach ($linked_tasks as $task) {
                $t_url = admin_url('admin.php?page=pwf-task&task_id=' . $task->task_id);
                echo '<tr>';
                echo '<td><strong>#' . absint($task->task_id) . ' ' . esc_html($task->title) . '</strong></td>';
                echo '<td>' . esc_html(PWF_Task_Manager::types()[$task->task_type]['label'] ?? $task->task_type) . '</td>';
                echo '<td>' . esc_html(PWF_Task_Manager::priorities()[$task->priority] ?? $task->priority) . '</td>';
                echo '<td>' . esc_html(PWF_Task_Manager::statuses()[$task->status] ?? $task->status) . '</td>';
                echo '<td>' . esc_html($task->due_date ?: '—') . '</td>';
                echo '<td><a class="button button-secondary" href="' . esc_url($t_url) . '">' . esc_html(pwf_t('Open')) . '</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p style="color:var(--pwf-slate-400); font-style:italic;">' . esc_html(pwf_t('No tasks created from this request yet.')) . '</p>';
        }
        echo '</section>';

        // History
        $history = PWF_Request_Manager::history($rid);
        echo '<section class="pwf-card">';
        echo '<h2><span class="dashicons dashicons-backup" style="color:var(--pwf-slate-600);"></span> ' . esc_html(pwf_t('Request History')) . '</h2>';
        echo '<div class="pwf-table-container"><table class="widefat striped">';
        echo '<thead><tr><th>' . esc_html(pwf_t('Time / Person')) . '</th><th>' . esc_html(pwf_t('Event')) . '</th><th>' . esc_html(pwf_t('Note')) . '</th></tr></thead><tbody>';
        foreach ($history as $entry) {
            $actor = get_userdata($entry->changed_by);
            echo '<tr>';
            echo '<td style="font-size:12px;">' . esc_html(get_date_from_gmt($entry->changed_at) . ' · ' . ($actor ? $actor->display_name : pwf_t('System'))) . '</td>';
            echo '<td><strong>' . esc_html($entry->event) . '</strong><br><span style="font-size:11px; color:var(--pwf-slate-500);">' . esc_html(($entry->previous_status ? $entry->previous_status . ' → ' : '') . $entry->new_status) . '</span></td>';
            echo '<td>' . esc_html($entry->note) . '</td>';
            echo '</tr>';
        }
        if (!$history) {
            echo '<tr><td colspan="3" style="text-align:center; padding:20px; color:var(--pwf-slate-400);">&mdash;</td></tr>';
        }
        echo '</tbody></table></div></section>';
        echo '</div>'; // left col

        // ── RIGHT: Status transitions + create task ───────────────────────────────
        echo '<aside>';

        // Status transition
        if (current_user_can('manage_workflow')) {
            echo '<section class="pwf-card" style="border-left:4px solid var(--pwf-primary);">';
            echo '<h2><span class="dashicons dashicons-controls-forward" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Update Status')) . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('pwf_request_status_' . $rid);
            echo '<input type="hidden" name="action" value="pwf_request_action">';
            echo '<input type="hidden" name="operation" value="status">';
            echo '<input type="hidden" name="request_id" value="' . absint($rid) . '">';
            echo '<p><label><strong>' . esc_html(pwf_t('New Status')) . '</strong><br>';
            echo '<select name="new_status" style="width:100%; margin-top:4px;">';
            foreach (PWF_Request_Manager::statuses() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($req->status, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><label><strong>' . esc_html(pwf_t('Note (required for rejection)')) . '</strong><br>';
            echo '<textarea name="note" rows="3" style="width:100%; margin-top:4px;"></textarea></label></p>';
            submit_button(pwf_t('Update Request Status'), 'primary', 'submit', true, array('style' => 'width:100%;'));
            echo '</form></section>';

            // Create task from request
            if (in_array($req->status, array('submitted', 'in_review', 'planned'), true)) {
                echo '<section class="pwf-card">';
                echo '<h2><span class="dashicons dashicons-plus-alt2" style="color:var(--pwf-secondary);"></span> ' . esc_html(pwf_t('Create Task from Request')) . '</h2>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('pwf_request_create_task_' . $rid);
                echo '<input type="hidden" name="action" value="pwf_request_action">';
                echo '<input type="hidden" name="operation" value="create_task">';
                echo '<input type="hidden" name="request_id" value="' . absint($rid) . '">';
                if ($req->product_id) {
                    echo '<input type="hidden" name="product_id" value="' . absint($req->product_id) . '">';
                }

                echo '<p><label><strong>' . esc_html(pwf_t('Task Title')) . '</strong><br>';
                echo '<input class="regular-text" name="title" value="' . esc_attr($req->title) . '" required style="width:100%; margin-top:4px;"></label></p>';

                echo '<p><label><strong>' . esc_html(pwf_t('Task Type')) . '</strong><br>';
                echo '<select name="task_type" style="width:100%; margin-top:4px;">';
                foreach (PWF_Task_Manager::types() as $key => $def) {
                    $selected = ($key === $req->request_type) ? 'selected' : '';
                    echo '<option value="' . esc_attr($key) . '" ' . $selected . '>' . esc_html($def['label']) . '</option>';
                }
                echo '</select></label></p>';

                echo '<p><label><strong>' . esc_html(pwf_t('Assign to')) . '</strong><br>';
                echo '<select name="assigned_to" style="width:100%; margin-top:4px;">';
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
                $users = get_users(array('orderby' => 'display_name', 'number' => 200));
                foreach ($users as $u) {
                    $role_label = '';
                    if (!empty($u->roles)) {
                        $r = $u->roles[0];
                        $role_label = $role_map[$r] ?? ucfirst(str_replace('pwf_', '', $r));
                    }
                    $label = $u->display_name . ($role_label ? ' (' . $role_label . ')' : '');
                    echo '<option value="' . absint($u->ID) . '">' . esc_html($label) . '</option>';
                }
                echo '</select></label></p>';

                echo '<p><label><strong>' . esc_html(pwf_t('Priority')) . '</strong><br>';
                echo '<select name="priority" style="width:100%; margin-top:4px;">';
                foreach (PWF_Task_Manager::priorities() as $key => $label) {
                    echo '<option value="' . esc_attr($key) . '" ' . selected($req->priority, $key, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select></label></p>';

                echo '<p><label><strong>' . esc_html(pwf_t('Due Date')) . ' <span style="color:var(--pwf-danger);">*</span></strong><br>';
                echo '<input type="date" name="due_date" required style="width:100%; margin-top:4px;"></label></p>';

                echo '<p><label><strong>' . esc_html(pwf_t('Description')) . '</strong><br>';
                echo '<textarea name="description" rows="3" style="width:100%; margin-top:4px;">' . esc_textarea($req->description) . '</textarea></label></p>';

                echo '<p><label><input type="checkbox" name="self_closing" value="1"> ' . esc_html(pwf_t('Self-closing (auto-approve on submission)')) . '</label></p>';

                submit_button(pwf_t('Create Task'), 'primary', 'submit', true, array('style' => 'width:100%;'));
                echo '</form></section>';
            }
        }

        echo '</aside>';
        echo '</div>'; // grid
        echo '</div>'; // wrap
    }
}

function pwf_requests_page() { PWF_Requests_Page::render(); }
