<?php
defined('ABSPATH') || exit;

function pwf_my_tasks() {
    if (!current_user_can('view_assigned_products')) { wp_die(pwf_t('Access denied.')); }
    echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';
    
    // Modern Hero Banner
    echo '<div class="pwf-hero">';
    echo '<div class="pwf-hero-title">';
    echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-id-alt"></span></div>';
    echo '<div>';
    echo '<h1>' . esc_html(pwf_t('My Tasks & Assigned Work')) . '</h1>';
    echo '<p>' . esc_html(pwf_t('View and execute all tasks assigned to you, track deadlines, submit deliverables, and manage product workflow assignments.')) . '</p>';
    echo '</div></div>';
    echo '<div class="pwf-hero-actions">';
    if (current_user_can('create_workflow_request')) {
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf-requests')) . '" class="button pwf-btn-subtle"><span class="dashicons dashicons-portfolio"></span> ' . esc_html(pwf_t('Submit Request')) . '</a>';
    }
    if (current_user_can('create_product_request')) {
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf-new')) . '" class="button pwf-btn-primary"><span class="dashicons dashicons-plus-alt2"></span> ' . esc_html(pwf_t('New Product')) . '</a>';
    }
    echo '</div></div>';

    PWF_Admin::notice();

    // ── Workflow Difference Guide Banner ──────────────────────────────────────────
    echo '<div class="pwf-guide-banner" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 22px; margin-bottom:22px; box-shadow:0 1px 3px rgba(0,0,0,0.03);">';
    echo '<div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">';
    echo '<span style="font-size:20px;">💡</span>';
    echo '<strong style="font-size:14px; color:#0f172a;">' . esc_html(pwf_t('Workflow Guide: Understanding Requests, Tasks & Products')) . '</strong>';
    echo '</div>';
    echo '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px;">';
    echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; border-right:4px solid #0284c7;">';
    echo '<strong style="color:#0284c7; font-size:13px;">📋 ' . esc_html(pwf_t('Task (Execution)')) . '</strong>';
    echo '<p style="margin:4px 0 0; font-size:12px; color:#64748b; line-height:1.5;">' . esc_html(pwf_t('Specific assignments given to you with attached guideline photos, instructions, deadline, and a submission button to hand over work.')) . '</p>';
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

    // ── Section 1: User granular execution tasks (pwf_tasks) ──────────────────────
    $user_id = get_current_user_id();
    $task_result = PWF_Task_Manager::list(array(
        'assigned_to' => $user_id,
        'per_page'    => 50,
    ));
    $my_tasks = $task_result['rows'];

    // Stats
    $c_assigned    = 0;
    $c_in_progress = 0;
    $c_revision    = 0;
    $c_overdue     = 0;
    $today_ymd     = current_time('Y-m-d');

    foreach ($my_tasks as $t) {
        if ($t->status === 'assigned') { $c_assigned++; }
        elseif ($t->status === 'in_progress') { $c_in_progress++; }
        elseif ($t->status === 'needs_revision') { $c_revision++; }

        if ($t->due_date && $t->due_date < $today_ymd && !in_array($t->status, array('approved', 'cancelled'), true)) {
            $c_overdue++;
        }
    }

    // KPI row
    echo '<div class="pwf-stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:24px;">';
    echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-shadow:0 1px 3px rgba(15,23,42,0.04);"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:12px; color:#64748b; margin:0 0 4px 0; font-weight:600;">' . esc_html(pwf_t('New Tasks')) . '</h4><div class="pwf-stat-count" style="font-size:26px; font-weight:800; color:#0f172a; line-height:1;">' . esc_html($c_assigned) . '</div><span class="pwf-stat-subtext" style="display:block; font-size:11px; color:#94a3b8; margin-top:4px;">' . esc_html(pwf_t('Ready to start')) . '</span></div><div class="pwf-stat-icon total" style="width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; background:#eef2ff; color:#4f46e5;">📋</div></div>';
    echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-shadow:0 1px 3px rgba(15,23,42,0.04);"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:12px; color:#64748b; margin:0 0 4px 0; font-weight:600;">' . esc_html(pwf_t('In Progress')) . '</h4><div class="pwf-stat-count" style="font-size:26px; font-weight:800; color:#0f172a; line-height:1;">' . esc_html($c_in_progress) . '</div><span class="pwf-stat-subtext" style="display:block; font-size:11px; color:#94a3b8; margin-top:4px;">' . esc_html(pwf_t('Currently working on')) . '</span></div><div class="pwf-stat-icon photo" style="width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; background:#f0fdf4; color:#16a34a;">⚙️</div></div>';
    echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-shadow:0 1px 3px rgba(15,23,42,0.04);"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:12px; color:#64748b; margin:0 0 4px 0; font-weight:600;">' . esc_html(pwf_t('Needs Revision')) . '</h4><div class="pwf-stat-count" style="font-size:26px; font-weight:800; color:#ef4444; line-height:1;">' . esc_html($c_revision) . '</div><span class="pwf-stat-subtext" style="display:block; font-size:11px; color:#ef4444; margin-top:4px;">' . esc_html(pwf_t('Management feedback')) . '</span></div><div class="pwf-stat-icon content" style="width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; background:#fef2f2; color:#ef4444;">🔴</div></div>';
    echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-shadow:0 1px 3px rgba(15,23,42,0.04);"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:12px; color:#64748b; margin:0 0 4px 0; font-weight:600;">' . esc_html(pwf_t('Overdue')) . '</h4><div class="pwf-stat-count" style="font-size:26px; font-weight:800; color:' . ($c_overdue > 0 ? '#ef4444' : '#64748b') . '; line-height:1;">' . esc_html($c_overdue) . '</div><span class="pwf-stat-subtext" style="display:block; font-size:11px; color:#94a3b8; margin-top:4px;">' . esc_html(pwf_t('Passed due date')) . '</span></div><div class="pwf-stat-icon review" style="width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; background:#fff1f2; color:#e11d48;">⏰</div></div>';
    echo '</div>';

    // Active tasks table
    $active_tasks = array_filter($my_tasks, function ($t) {
        return in_array($t->status, array('assigned', 'in_progress', 'needs_revision', 'on_hold'), true);
    });

    echo '<div class="pwf-card" style="margin-bottom:28px; background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 2px 8px rgba(0,0,0,0.04); padding:22px 24px;">';
    echo '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">';
    echo '<h3 style="margin:0; font-size:16px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;">';
    echo '<span class="dashicons dashicons-clipboard" style="color:var(--pwf-primary);"></span> ';
    echo esc_html(pwf_t('My Active Execution Tasks'));
    echo ' <span style="font-size:12px; background:#eef2ff; color:#4f46e5; padding:2px 8px; border-radius:9999px; font-weight:700;">' . count($active_tasks) . '</span>';
    echo '</h3>';
    echo '</div>';

    if (!empty($active_tasks)) {
        echo '<div class="pwf-table-container"><table class="widefat striped" style="border:none;">';
        echo '<thead><tr>';
        echo '<th style="padding:12px 14px;">' . esc_html(pwf_t('Task')) . '</th>';
        echo '<th style="padding:12px 14px;">' . esc_html(pwf_t('Type')) . '</th>';
        echo '<th style="padding:12px 14px;">' . esc_html(pwf_t('Priority')) . '</th>';
        echo '<th style="padding:12px 14px;">' . esc_html(pwf_t('Status')) . '</th>';
        echo '<th style="padding:12px 14px;">' . esc_html(pwf_t('Attachments')) . '</th>';
        echo '<th style="padding:12px 14px;">' . esc_html(pwf_t('Due Date')) . '</th>';
        echo '<th style="padding:12px 14px; text-align:center;">' . esc_html(pwf_t('Action')) . '</th>';
        echo '</tr></thead><tbody>';

        $prio_colors = array('urgent' => 'pwf-badge-admin', 'high' => 'pwf-badge-error', 'normal' => 'pwf-badge-role', 'low' => 'pwf-badge-default');
        $status_icons = array('assigned' => '📋', 'in_progress' => '⚙️', 'needs_revision' => '🔴', 'on_hold' => '⏸');

        foreach ($active_tasks as $task) {
            $t_url   = admin_url('admin.php?page=pwf-task&task_id=' . $task->task_id);
            $overdue = ($task->due_date && $task->due_date < $today_ymd && $task->status !== 'approved');
            $type_label = PWF_Task_Manager::types()[$task->task_type]['label'] ?? $task->task_type;
            $prio_label = PWF_Task_Manager::priorities()[$task->priority]      ?? $task->priority;
            $st_label   = PWF_Task_Manager::statuses()[$task->status]          ?? $task->status;
            $st_icon    = $status_icons[$task->status] ?? '📌';

            $task_atts = !empty($task->attachments) ? json_decode($task->attachments, true) : array();
            $att_count = is_array($task_atts) ? count($task_atts) : 0;

            echo '<tr' . ($task->status === 'needs_revision' ? ' style="background:rgba(239,68,68,0.04);"' : '') . '>';
            echo '<td style="padding:14px;">';
            echo '<a href="' . esc_url($t_url) . '" style="font-weight:700; color:#0f172a; text-decoration:none; font-size:14px; display:block;">';
            echo esc_html($st_icon . ' #' . $task->task_id . ' ' . $task->title);
            echo '</a>';
            if ($task->status === 'needs_revision') {
                global $wpdb;
                $last_note = $wpdb->get_var($wpdb->prepare(
                    "SELECT note FROM {$wpdb->prefix}pwf_task_history WHERE task_id=%d AND event='revision_requested' ORDER BY log_id DESC LIMIT 1",
                    $task->task_id
                ));
                if ($last_note) {
                    echo '<div style="margin-top:4px; padding:4px 8px; background:#fef2f2; border-radius:6px; color:#dc2626; font-size:12px; border:1px solid #fecaca;">';
                    echo '<strong>' . esc_html(pwf_t('Correction feedback:')) . '</strong> ' . esc_html($last_note);
                    echo '</div>';
                }
            }
            echo '</td>';

            echo '<td style="padding:14px; font-size:13px; color:#475569;">' . esc_html($type_label) . '</td>';
            echo '<td style="padding:14px;"><span class="pwf-badge ' . esc_attr($prio_colors[$task->priority] ?? 'pwf-badge-default') . '">' . esc_html($prio_label) . '</span></td>';
            echo '<td style="padding:14px;"><span style="font-weight:600; font-size:12px; color:#334155;">' . esc_html($st_label) . '</span></td>';

            // Attachments cell
            echo '<td style="padding:14px;">';
            if ($att_count > 0) {
                echo '<span style="display:inline-flex; align-items:center; gap:4px; background:#f1f5f9; color:#475569; padding:2px 8px; border-radius:6px; font-size:12px; font-weight:600;">';
                echo '<span class="dashicons dashicons-paperclip" style="font-size:14px; width:14px; height:14px;"></span> ' . $att_count . ' ' . esc_html(pwf_t('files'));
                echo '</span>';
            } else {
                echo '<span style="color:#cbd5e1;">—</span>';
            }
            echo '</td>';

            // Due date cell
            echo '<td style="padding:14px;" class="' . ($overdue ? 'pwf-overdue' : '') . '">';
            if ($task->due_date) {
                $days_left = (int) round((strtotime($task->due_date) - time()) / 86400);
                echo '<span style="font-size:13px; font-weight:600;">' . esc_html($task->due_date) . '</span>';
                if ($overdue) {
                    echo ' <span style="display:block; color:#ef4444; font-size:11px; font-weight:700;">' . esc_html(sprintf(pwf_t('%d days overdue'), abs($days_left))) . ' ⚠</span>';
                } elseif ($days_left <= 1) {
                    echo ' <span style="display:block; color:#d97706; font-size:11px; font-weight:700;">' . esc_html(pwf_t('Today / Tomorrow')) . '</span>';
                }
            } else {
                echo '<span style="color:#94a3b8;">—</span>';
            }
            echo '</td>';

            // Action button
            echo '<td style="padding:14px; text-align:center;">';
            if ($task->status === 'assigned') {
                echo '<a class="button pwf-btn-filter" style="height:34px; padding:0 14px; font-size:12px;" href="' . esc_url($t_url) . '">' . esc_html(pwf_t('▶ Start')) . '</a>';
            } elseif (in_array($task->status, array('in_progress', 'needs_revision'), true)) {
                echo '<a class="button pwf-btn-filter" style="height:34px; padding:0 14px; font-size:12px; background:#10b981; border-color:#10b981;" href="' . esc_url($t_url) . '">' . esc_html(pwf_t('📤 Submit Deliverables')) . '</a>';
            } else {
                echo '<a class="button button-secondary" style="height:34px; line-height:32px; padding:0 14px; font-size:12px;" href="' . esc_url($t_url) . '">' . esc_html(pwf_t('View Details')) . '</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<div style="text-align:center; padding:36px 20px; color:#64748b;">';
        echo '<div style="font-size:36px; margin-bottom:8px;">🎉</div>';
        echo '<p style="font-size:14px; font-weight:600; margin:0;">' . esc_html(pwf_t('No active tasks currently assigned to you.')) . '</p>';
        echo '<p style="font-size:12px; color:#94a3b8; margin:4px 0 0;">' . esc_html(pwf_t('When management assigns a photography, content, or technical task to you, it will appear here.')) . '</p>';
        echo '</div>';
    }
    echo '</div>'; // card

    // ── Section 2: Pipeline Product Tasks (Legacy assignments) ───────────────────
    echo '<div class="pwf-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 2px 8px rgba(0,0,0,0.04); padding:22px 24px;">';
    echo '<h3 style="margin:0 0 16px 0; font-size:16px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;">';
    echo '<span class="dashicons dashicons-products" style="color:#059669;"></span> ';
    echo esc_html(pwf_t('Product Pipeline Stages Assigned to You'));
    echo '</h3>';
    PWF_Admin::table(true);
    echo '</div>';

    echo '</div>'; // wrap
}
