<?php
defined('ABSPATH') || exit;

class PWF_Admin {
    public static function boot() {
        add_action('admin_menu', function () {
            add_menu_page(pwf_t('Product Workflow'), pwf_t('Product Workflow'), 'view_assigned_products', 'pwf', array(__CLASS__, 'dashboard'), 'dashicons-clipboard', 56);
            add_submenu_page('pwf', pwf_t('My Tasks'), pwf_t('My Tasks'), 'view_assigned_products', 'pwf-tasks', 'pwf_my_tasks');
            add_submenu_page('pwf', pwf_t('Requests'), pwf_t('Requests'), 'create_workflow_request', 'pwf-requests', array('PWF_Requests_Page', 'render'));
            add_submenu_page('pwf', pwf_t('New Task'), pwf_t('New Task'), 'assign_tasks', 'pwf-new-task', array('PWF_Task_Page', 'render_create'));
            add_submenu_page('pwf', pwf_t('New Product'), pwf_t('New Product'), 'create_product_request', 'pwf-new', array(__CLASS__, 'create_page'));
            add_submenu_page('pwf', pwf_t('Settings'), pwf_t('Settings'), 'manage_workflow', 'pwf-settings', 'pwf_settings_page');
            $detail_hook = add_submenu_page('', pwf_t('Product Details'), pwf_t('Product Details'), 'view_assigned_products', 'pwf-product', 'pwf_product_page');
            add_action('load-' . $detail_hook, function () { global $title; $title = pwf_t('Product Workflow'); });
            add_submenu_page('', pwf_t('Task Details'), pwf_t('Task Details'), 'view_assigned_products', 'pwf-task', array('PWF_Task_Page', 'render'));
            add_submenu_page('', pwf_t('Request Details'), pwf_t('Request Details'), 'create_workflow_request', 'pwf-request', array('PWF_Requests_Page', 'render_detail'));
        });
        // Boot sub-page classes
        PWF_Requests_Page::boot();
        PWF_Task_Page::boot();
        add_filter('parent_file', function ($parent) { return isset($_GET['page']) && $_GET['page'] === 'pwf-product' ? 'pwf' : $parent; });
        add_filter('admin_title', function ($title) { return isset($_GET['page']) && $_GET['page'] === 'pwf-product' ? pwf_t('Product Workflow') . ' — ' . get_bloginfo('name') : $title; });
        add_action('admin_post_pwf_action', array(__CLASS__, 'handle'));
        add_action('add_meta_boxes_product', function () {
            add_meta_box('pwf-panel', pwf_t('Product Workflow'), 'pwf_product_metabox', 'product', 'side', 'high');
        });
        add_action('admin_enqueue_scripts', function ($hook) {
            $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
            $is_pwf = (strpos($page, 'pwf') !== false) || (is_string($hook) && strpos($hook, 'pwf') !== false) || (function_exists('get_post_type') && get_post_type() === 'product');
            if ($is_pwf) {
                wp_enqueue_style('dashicons');
                wp_enqueue_style('pwf-vazirmatn', 'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css', array(), '33.003');
                $css_file = defined('PWF_DIR') ? PWF_DIR . 'assets/admin.css' : dirname(__DIR__) . '/assets/admin.css';
                $css_ver = file_exists($css_file) ? (string) filemtime($css_file) : PWF_VERSION;
                $css_url = defined('PWF_URL') ? PWF_URL . 'assets/admin.css' : plugins_url('assets/admin.css', dirname(__DIR__) . '/product-workflow-for-woocommerce.php');
                wp_enqueue_style('pwf-admin', $css_url, array('dashicons', 'pwf-vazirmatn'), $css_ver);
            }
        });
        // WooCommerce normally redirects non-manager roles away from wp-admin.
        add_filter('woocommerce_prevent_admin_access', function ($prevent) { return current_user_can('view_assigned_products') ? false : $prevent; });
    }

    public static function url($id) {
        return add_query_arg(array('page' => 'pwf-product', 'product_id' => absint($id)), admin_url('admin.php'));
    }

    public static function notice() {
        $key = 'pwf_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if ($notice) {
            delete_transient($key);
            echo '<div class="notice ' . ($notice['error'] ? 'notice-error' : 'notice-success') . ' pwf-notice"><p>' . esc_html($notice['text']) . '</p></div>';
        }
    }

    public static function form_start($action, $id = 0, $multipart = false) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($multipart ? ' enctype="multipart/form-data"' : '') . '>';
        wp_nonce_field('pwf_' . $action . '_' . $id);
        echo '<input type="hidden" name="action" value="pwf_action"><input type="hidden" name="operation" value="' . esc_attr($action) . '"><input type="hidden" name="product_id" value="' . absint($id) . '">';
    }

    public static function handle() {
        if (!current_user_can('view_assigned_products')) { wp_die(pwf_t('Access denied.'), '', array('response' => 403)); }
        $data = wp_unslash($_POST);
        $op = sanitize_key($data['operation'] ?? '');
        $id = absint($data['product_id'] ?? 0);
        check_admin_referer('pwf_' . $op . '_' . $id);
        if ($op === 'enroll') { $id = absint($data['enroll_id'] ?? 0); }
        if ($op !== 'create' && $op !== 'enroll' && !PWF_Permission_Manager::can_view($id)) { wp_die(pwf_t('Access denied.'), '', array('response' => 403)); }
        switch ($op) {
            case 'create': $result = PWF_Workflow_Manager::create($data); if (!is_wp_error($result)) { $id = $result->product_id; } break;
            case 'enroll':
                $result = current_user_can('manage_workflow') && current_user_can('edit_post', $id) ? PWF_Workflow_Manager::initialize($id) : new WP_Error('forbidden', pwf_t('You cannot enroll this product.'));
                break;
            case 'transition': $result = PWF_Workflow_Manager::transition($id, sanitize_key($data['to'] ?? ''), sanitize_key($data['expected'] ?? ''), $data['note'] ?? ''); break;
            case 'assign': $result = PWF_Assignment_Manager::assign($id, sanitize_key($data['stage'] ?? ''), absint($data['user_id'] ?? 0), sanitize_text_field($data['due_date'] ?? '')); break;
            case 'content':
                $data['category_ids'] = array_filter(array_map('absint', (array) ($data['category_ids'] ?? array())));
                $data['tag_ids'] = array_filter(array_map('absint', (array) ($data['tag_ids'] ?? array())));
                if (isset($data['attributes_text'])) {
                    $data['attributes'] = array();
                    foreach (preg_split('/\r?\n/', $data['attributes_text']) as $line) {
                        if (strpos($line, ':') === false) { continue; }
                        list($name, $values) = explode(':', $line, 2);
                        $data['attributes'][trim($name)] = array_map('trim', explode('|', $values));
                    }
                }
                $result = PWF_Workflow_Manager::save_content($id, $data); break;
            case 'images': $result = PWF_Workflow_Manager::save_images($id, absint($data['main_image'] ?? 0), (array) ($data['gallery'] ?? array())); break;
            case 'upload': $result = PWF_Workflow_Manager::upload($id); break;
            default: $result = new WP_Error('invalid_action', pwf_t('Unknown action.'));
        }
        set_transient('pwf_notice_' . get_current_user_id(), array('error' => is_wp_error($result), 'text' => is_wp_error($result) ? $result->get_error_message() : pwf_t('Saved successfully.')), 60);
        wp_safe_redirect($id ? self::url($id) : admin_url('admin.php?page=pwf-new'));
        exit;
    }

    public static function dashboard() {
        // CEO executive view (read-only)
        if (current_user_can('view_executive_dashboard') && !current_user_can('manage_workflow')) {
            self::executive_dashboard();
            return;
        }
        if (!current_user_can('manage_workflow') && !current_user_can('assign_tasks')) { pwf_my_tasks(); return; }
        
        global $wpdb;
        $total_products = 0;
        $photo_count = 0;
        $content_count = 0;
        $review_count = 0;
        $approved_count = 0;
        // Task-model stats
        $task_assigned = 0; $task_in_progress = 0; $task_submitted = 0; $task_revision = 0; $task_approved = 0;

        if ($wpdb) {
            $total_products = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_workflows w JOIN {$wpdb->posts} p ON p.ID=w.product_id WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft')");
            $photo_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_workflows w JOIN {$wpdb->posts} p ON p.ID=w.product_id WHERE w.current_status IN ('new_product', 'waiting_photography') AND p.post_status NOT IN ('trash','auto-draft')");
            $content_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_workflows w JOIN {$wpdb->posts} p ON p.ID=w.product_id WHERE w.current_status='waiting_content' AND p.post_status NOT IN ('trash','auto-draft')");
            $review_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_workflows w JOIN {$wpdb->posts} p ON p.ID=w.product_id WHERE w.current_status='waiting_review' AND p.post_status NOT IN ('trash','auto-draft')");
            $approved_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_workflows w JOIN {$wpdb->posts} p ON p.ID=w.product_id WHERE w.current_status IN ('approved', 'published', 'completed') AND p.post_status NOT IN ('trash','auto-draft')");
            // New task model
            if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}pwf_tasks'")) {
                $task_assigned    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_tasks WHERE status='assigned'");
                $task_in_progress = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_tasks WHERE status='in_progress'");
                $task_submitted   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_tasks WHERE status='submitted'");
                $task_revision    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_tasks WHERE status='needs_revision'");
                $task_approved    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_tasks WHERE status='approved'");
            }
        }
        $task_total = $task_assigned + $task_in_progress + $task_submitted + $task_revision + $task_approved;

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'pipeline';
        if (!in_array($tab, array('pipeline', 'tasks', 'enroll'), true)) {
            $tab = 'pipeline';
        }

        echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';
        
        // Modern Hero Banner
        echo '<div class="pwf-hero">';
        echo '<div class="pwf-hero-title">';
        echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-clipboard"></span></div>';
        echo '<div>';
        echo '<h1>' . esc_html(pwf_t('Product Workflow')) . '</h1>';
        echo '<p>' . esc_html(pwf_t('Track each product from factory introduction to social handoff.')) . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<div class="pwf-hero-actions">';
        if (current_user_can('create_product_request')) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=pwf-new')) . '" class="button pwf-btn-primary"><span class="dashicons dashicons-plus-alt2"></span> ' . esc_html(pwf_t('New Product')) . '</a>';
        }
        if (current_user_can('manage_workflow')) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=pwf-settings')) . '" class="button pwf-btn-subtle"><span class="dashicons dashicons-admin-settings"></span> ' . esc_html(pwf_t('Settings')) . '</a>';
        }
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf-help')) . '" class="button pwf-btn-subtle"><span class="dashicons dashicons-editor-help"></span> ' . esc_html(pwf_t('Help')) . '</a>';
        echo '</div>';
        echo '</div>';

        self::notice();

        // Consolidated KPI Summary Stats Row
        echo '<div class="pwf-stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin:20px 0;">';
        echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-shadow:0 1px 3px rgba(15,23,42,0.04);"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:12px; color:#64748b; margin:0 0 4px 0; font-weight:600;">' . esc_html(pwf_t('Product / SKU')) . '</h4><div class="pwf-stat-count" style="font-size:26px; font-weight:800; color:#0f172a; line-height:1;">' . esc_html($total_products) . '</div><span class="pwf-stat-subtext" style="display:block; font-size:11px; color:#94a3b8; margin-top:4px;">' . esc_html(pwf_t('Active in workflow')) . '</span></div><div class="pwf-stat-icon total" style="width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; background:#eef2ff; color:#4f46e5;">📦</div></div>';
        echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-shadow:0 1px 3px rgba(15,23,42,0.04);"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:12px; color:#64748b; margin:0 0 4px 0; font-weight:600;">' . esc_html(PWF_I18n::stage('photography')) . '</h4><div class="pwf-stat-count" style="font-size:26px; font-weight:800; color:#0f172a; line-height:1;">' . esc_html($photo_count) . '</div><span class="pwf-stat-subtext" style="display:block; font-size:11px; color:#94a3b8; margin-top:4px;">' . esc_html(pwf_t('Awaiting / in photography')) . '</span></div><div class="pwf-stat-icon photo" style="width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; background:#faf5ff; color:#9333ea;">📸</div></div>';
        echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-shadow:0 1px 3px rgba(15,23,42,0.04);"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:12px; color:#64748b; margin:0 0 4px 0; font-weight:600;">' . esc_html(PWF_I18n::stage('content')) . '</h4><div class="pwf-stat-count" style="font-size:26px; font-weight:800; color:#0f172a; line-height:1;">' . esc_html($content_count) . '</div><span class="pwf-stat-subtext" style="display:block; font-size:11px; color:#94a3b8; margin-top:4px;">' . esc_html(pwf_t('Writing content & SEO')) . '</span></div><div class="pwf-stat-icon content" style="width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; background:#fffbeb; color:#d97706;">📝</div></div>';
        echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-shadow:0 1px 3px rgba(15,23,42,0.04);"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:12px; color:#64748b; margin:0 0 4px 0; font-weight:600;">' . esc_html(PWF_I18n::stage('review')) . '</h4><div class="pwf-stat-count" style="font-size:26px; font-weight:800; color:#0f172a; line-height:1;">' . esc_html($review_count) . '</div><span class="pwf-stat-subtext" style="display:block; font-size:11px; color:#94a3b8; margin-top:4px;">' . esc_html(pwf_t('Awaiting final review')) . '</span></div><div class="pwf-stat-icon review" style="width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; background:#eff6ff; color:#2563eb;">🔍</div></div>';
        echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-shadow:0 1px 3px rgba(15,23,42,0.04);"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:12px; color:#64748b; margin:0 0 4px 0; font-weight:600;">' . esc_html(pwf_t('Approved')) . '</h4><div class="pwf-stat-count" style="font-size:26px; font-weight:800; color:#0f172a; line-height:1;">' . esc_html($approved_count) . '</div><span class="pwf-stat-subtext" style="display:block; font-size:11px; color:#94a3b8; margin-top:4px;">' . esc_html(pwf_t('Ready for publish / social')) . '</span></div><div class="pwf-stat-icon done" style="width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; background:#ecfdf5; color:#059669;">✅</div></div>';
        echo '</div>';

        // Navigation Tabs Bar
        echo '<nav class="pwf-nav-tabs" style="display:flex; align-items:center; gap:8px; margin:24px 0 18px 0; border-bottom:2px solid #e2e8f0; padding-bottom:0;">';
        echo '<a href="' . esc_url(add_query_arg('tab', 'pipeline', admin_url('admin.php?page=pwf'))) . '" class="pwf-nav-tab ' . ($tab === 'pipeline' ? 'active' : '') . '" style="display:inline-flex; align-items:center; gap:8px; padding:10px 18px; font-size:13px; font-weight:600; text-decoration:none; border-bottom:3px solid ' . ($tab === 'pipeline' ? '#4f46e5' : 'transparent') . '; margin-bottom:-2px; border-radius:8px 8px 0 0; color:' . ($tab === 'pipeline' ? '#4f46e5' : '#475569') . '; background:' . ($tab === 'pipeline' ? '#ffffff' : 'transparent') . ';">';
        echo '<span class="dashicons dashicons-products"></span>';
        echo '<span>' . esc_html(pwf_t('Product Pipeline')) . '</span>';
        echo '<span class="pwf-tab-badge" style="display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 6px; border-radius:9999px; background:' . ($tab === 'pipeline' ? '#eef2ff' : '#f1f5f9') . '; color:' . ($tab === 'pipeline' ? '#4f46e5' : '#475569') . '; font-size:11px; font-weight:700;">' . esc_html($total_products) . '</span>';
        echo '</a>';

        echo '<a href="' . esc_url(add_query_arg('tab', 'tasks', admin_url('admin.php?page=pwf'))) . '" class="pwf-nav-tab ' . ($tab === 'tasks' ? 'active' : '') . '" style="display:inline-flex; align-items:center; gap:8px; padding:10px 18px; font-size:13px; font-weight:600; text-decoration:none; border-bottom:3px solid ' . ($tab === 'tasks' ? '#4f46e5' : 'transparent') . '; margin-bottom:-2px; border-radius:8px 8px 0 0; color:' . ($tab === 'tasks' ? '#4f46e5' : '#475569') . '; background:' . ($tab === 'tasks' ? '#ffffff' : 'transparent') . ';">';
        echo '<span class="dashicons dashicons-list-view"></span>';
        echo '<span>' . esc_html(pwf_t('Team Tasks')) . '</span>';
        echo '<span class="pwf-tab-badge ' . ($task_total > 0 ? 'pwf-badge-active' : '') . '" style="display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 6px; border-radius:9999px; background:' . ($tab === 'tasks' ? '#4f46e5' : '#f1f5f9') . '; color:' . ($tab === 'tasks' ? '#ffffff' : '#475569') . '; font-size:11px; font-weight:700;">' . esc_html($task_total) . '</span>';
        echo '</a>';

        if (current_user_can('manage_workflow')) {
            echo '<a href="' . esc_url(add_query_arg('tab', 'enroll', admin_url('admin.php?page=pwf'))) . '" class="pwf-nav-tab ' . ($tab === 'enroll' ? 'active' : '') . '" style="display:inline-flex; align-items:center; gap:8px; padding:10px 18px; font-size:13px; font-weight:600; text-decoration:none; border-bottom:3px solid ' . ($tab === 'enroll' ? '#4f46e5' : 'transparent') . '; margin-bottom:-2px; border-radius:8px 8px 0 0; color:' . ($tab === 'enroll' ? '#4f46e5' : '#475569') . '; background:' . ($tab === 'enroll' ? '#ffffff' : 'transparent') . ';">';
            echo '<span class="dashicons dashicons-plus-alt"></span>';
            echo '<span>' . esc_html(pwf_t('Enroll Draft')) . '</span>';
            echo '</a>';
        }
        echo '</nav>';

        // Render Active Tab Content
        if ($tab === 'pipeline') {
            self::table(false);
        } elseif ($tab === 'tasks') {
            // Task engine KPI mini-row
            echo '<div class="pwf-stats-grid pwf-task-stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin:16px 0 20px 0;">';
            echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between;"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:11px; color:#64748b; margin:0 0 3px 0;">' . esc_html(pwf_t('Assigned')) . '</h4><div class="pwf-stat-count" style="font-size:22px; font-weight:800; color:#0f172a;">' . esc_html($task_assigned) . '</div></div><div class="pwf-stat-icon total" style="font-size:20px;">🗂</div></div>';
            echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between;"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:11px; color:#64748b; margin:0 0 3px 0;">' . esc_html(pwf_t('In Progress')) . '</h4><div class="pwf-stat-count" style="font-size:22px; font-weight:800; color:#0f172a;">' . esc_html($task_in_progress) . '</div></div><div class="pwf-stat-icon photo" style="font-size:20px;">⚙️</div></div>';
            echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between;"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:11px; color:#64748b; margin:0 0 3px 0;">' . esc_html(pwf_t('Submitted')) . '</h4><div class="pwf-stat-count" style="font-size:22px; font-weight:800; color:#0f172a;">' . esc_html($task_submitted) . '</div></div><div class="pwf-stat-icon review" style="font-size:20px;">📤</div></div>';
            echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between;"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:11px; color:#ef4444; margin:0 0 3px 0;">' . esc_html(pwf_t('Needs Revision')) . '</h4><div class="pwf-stat-count" style="font-size:22px; font-weight:800; color:#ef4444;">' . esc_html($task_revision) . '</div></div><div class="pwf-stat-icon content" style="font-size:20px;">🔴</div></div>';
            echo '<div class="pwf-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between;"><div class="pwf-stat-meta" style="text-align:right;"><h4 style="font-size:11px; color:#059669; margin:0 0 3px 0;">' . esc_html(pwf_t('Approved')) . '</h4><div class="pwf-stat-count" style="font-size:22px; font-weight:800; color:#059669;">' . esc_html($task_approved) . '</div></div><div class="pwf-stat-icon done" style="font-size:20px;">✅</div></div>';
            echo '</div>';
            self::tasks_table();
        } elseif ($tab === 'enroll') {
            if (current_user_can('manage_workflow')) {
                echo '<div class="pwf-card pwf-enroll-box" style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:28px; max-width:680px; margin:20px 0;">';
                echo '<div class="pwf-enroll-header" style="display:flex; align-items:center; gap:16px; margin-bottom:20px;">';
                echo '<div class="pwf-enroll-icon" style="width:48px; height:48px; border-radius:12px; background:#eef2ff; color:#4f46e5; display:flex; align-items:center; justify-content:center; font-size:24px;"><span class="dashicons dashicons-cloud-upload"></span></div>';
                echo '<div>';
                echo '<h3 style="margin:0 0 4px 0; font-size:17px; font-weight:700; color:#0f172a; border-bottom:none; padding:0;">' . esc_html(pwf_t('Enroll an existing draft product')) . '</h3>';
                echo '<p style="margin:0; font-size:13px; color:#64748b;">' . esc_html(pwf_t('Add an existing WooCommerce draft product to workflow by entering its ID.')) . '</p>';
                echo '</div>';
                echo '</div>';

                self::form_start('enroll');
                echo '<div class="pwf-enroll-form-row" style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-bottom:16px;">';
                echo '<label class="pwf-enroll-label" style="display:flex; flex-direction:column; gap:6px; flex:1; min-width:220px;">';
                echo '<span style="font-size:13px; font-weight:600; color:#334155;">' . esc_html(pwf_t('WooCommerce draft product ID')) . '</span>';
                echo '<input type="number" min="1" name="enroll_id" required class="pwf-input-enroll" placeholder="1245" style="height:42px; border:1.5px solid #cbd5e1; border-radius:8px; padding:0 14px; font-size:14px; width:100%;">';
                echo '</label>';
                echo '<button type="submit" class="button button-primary pwf-btn-enroll" style="height:42px; padding:0 24px; border-radius:8px; background:#4f46e5; border-color:#4f46e5; font-weight:600; font-size:13px; display:inline-flex; align-items:center; gap:8px;"><span class="dashicons dashicons-saved"></span> ' . esc_html(pwf_t('Enroll draft')) . '</button>';
                echo '</div>';
                echo '<div class="pwf-enroll-tip" style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; padding:10px 14px; background:#f8fafc; border-radius:8px; border:1px dashed #cbd5e1;"><span class="dashicons dashicons-info"></span> ' . esc_html(pwf_t('Tip: You can find product IDs in WooCommerce > Products.')) . '</div>';
                echo '</form>';
                echo '</div>';
            }
        }

        echo '</div>';
    }

    /** CEO Executive read-only dashboard. */
    public static function executive_dashboard() {
        global $wpdb;
        echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';
        echo '<div class="pwf-hero">';
        echo '<div class="pwf-hero-title"><div class="pwf-hero-icon"><span class="dashicons dashicons-chart-bar"></span></div>';
        echo '<div><h1>' . esc_html(pwf_t('Executive Dashboard')) . '</h1>';
        echo '<p>' . esc_html(pwf_t('Real-time overview of all company tasks and bottlenecks. Read-only view.')) . '</p></div></div></div>';

        // KPI cards
        $open     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_tasks WHERE status NOT IN ('approved','cancelled')");
        $overdue  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_tasks WHERE status NOT IN ('approved','cancelled') AND due_date < CURDATE()");
        $review   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_tasks WHERE status='submitted'");
        $done     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_tasks WHERE status='approved'");
        $requests = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_requests WHERE status NOT IN ('completed','rejected')");

        echo '<div class="pwf-stats-grid">';
        echo '<div class="pwf-stat-card"><div class="pwf-stat-icon total">📂</div><div class="pwf-stat-meta"><h4>' . esc_html(pwf_t('Open Tasks')) . '</h4><div class="pwf-stat-count">' . esc_html($open) . '</div></div></div>';
        echo '<div class="pwf-stat-card"><div class="pwf-stat-icon" style="color:#ef4444;">⏰</div><div class="pwf-stat-meta"><h4>' . esc_html(pwf_t('Overdue')) . '</h4><div class="pwf-stat-count" style="color:#ef4444;">' . esc_html($overdue) . '</div></div></div>';
        echo '<div class="pwf-stat-card"><div class="pwf-stat-icon review">📤</div><div class="pwf-stat-meta"><h4>' . esc_html(pwf_t('Awaiting Review')) . '</h4><div class="pwf-stat-count">' . esc_html($review) . '</div></div></div>';
        echo '<div class="pwf-stat-card"><div class="pwf-stat-icon done">✅</div><div class="pwf-stat-meta"><h4>' . esc_html(pwf_t('Completed')) . '</h4><div class="pwf-stat-count">' . esc_html($done) . '</div></div></div>';
        echo '<div class="pwf-stat-card"><div class="pwf-stat-icon photo">📥</div><div class="pwf-stat-meta"><h4>' . esc_html(pwf_t('Open Requests')) . '</h4><div class="pwf-stat-count">' . esc_html($requests) . '</div></div></div>';
        echo '</div>';

        // Per-user workload
        $user_loads = $wpdb->get_results(
            "SELECT t.assigned_to, u.display_name, COUNT(*) as total,
             SUM(CASE WHEN t.due_date < CURDATE() AND t.status NOT IN ('approved','cancelled') THEN 1 ELSE 0 END) as overdue
             FROM {$wpdb->prefix}pwf_tasks t
             LEFT JOIN {$wpdb->users} u ON u.ID=t.assigned_to
             WHERE t.status NOT IN ('approved','cancelled') AND t.assigned_to IS NOT NULL
             GROUP BY t.assigned_to ORDER BY total DESC"
        );
        if ($user_loads) {
            echo '<div class="pwf-card" style="margin-top:24px;"><h2>' . esc_html(pwf_t('Team Workload')) . '</h2>';
            echo '<div class="pwf-table-container"><table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html(pwf_t('Team Member')) . '</th><th>' . esc_html(pwf_t('Active Tasks')) . '</th><th>' . esc_html(pwf_t('Overdue')) . '</th></tr></thead><tbody>';
            foreach ($user_loads as $row) {
                echo '<tr><td>' . esc_html($row->display_name ?: pwf_t('Unassigned')) . '</td>';
                echo '<td>' . esc_html($row->total) . '</td>';
                echo '<td>' . ($row->overdue > 0 ? '<span class="pwf-badge pwf-badge-error">' . esc_html($row->overdue) . '</span>' : '<span style="color:var(--pwf-slate-400);">0</span>') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div></div>';
        }
        echo '</div>';
    }

    /** Task-model table for management dashboard. */
    public static function tasks_table() {
        global $wpdb;
        $status  = sanitize_key(wp_unslash($_GET['tstatus'] ?? ''));
        $search  = sanitize_text_field(wp_unslash($_GET['ts'] ?? ''));
        $page    = max(1, absint($_GET['tpaged'] ?? 1));
        $filters = array('status' => $status, 'search' => $search, 'page' => $page, 'per_page' => 15);
        $result  = PWF_Task_Manager::list($filters);
        $rows    = $result['rows'];
        $total   = $result['total'];

        // Filter
        echo '<div class="pwf-filters-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 18px; margin-bottom:18px;">';
        echo '<form method="get" class="pwf-filters" style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin:0;">';
        echo '<input type="hidden" name="page" value="pwf">';
        echo '<input type="hidden" name="tab" value="tasks">';
        echo '<div class="pwf-filter-item" style="display:flex; flex-direction:column; gap:4px;">';
        echo '<label style="font-size:12px; font-weight:700; color:#475569;">' . esc_html(pwf_t('Status')) . '</label>';
        echo '<select name="tstatus" style="width:180px; height:38px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:13px;">';
        echo '<option value="">' . esc_html(pwf_t('All statuses')) . '</option>';
        foreach (PWF_Task_Manager::statuses() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($status, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="pwf-filter-item pwf-filter-search" style="display:flex; flex-direction:column; gap:4px;">';
        echo '<label style="font-size:12px; font-weight:700; color:#475569;">' . esc_html(pwf_t('Search')) . '</label>';
        echo '<input type="text" name="ts" value="' . esc_attr($search) . '" placeholder="' . esc_attr(pwf_t('Search in task title or notes…')) . '" style="width:260px; height:38px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:13px;"></div>';
        echo '<div class="pwf-filter-item pwf-filter-btn"><button class="button button-primary pwf-btn-filter" style="height:38px; padding:0 20px; border-radius:8px; font-weight:600; font-size:13px; background:#4f46e5; border-color:#4f46e5; color:#fff;">' . esc_html(pwf_t('Filter')) . '</button></div>';
        if ($status || $search) {
            echo '<div class="pwf-filter-item pwf-filter-btn"><a href="' . esc_url(admin_url('admin.php?page=pwf&tab=tasks')) . '" class="button button-link pwf-btn-clear" style="height:38px; display:inline-flex; align-items:center; gap:4px; font-size:13px;"><span class="dashicons dashicons-dismiss"></span> ' . esc_html(pwf_t('Clear filters')) . '</a></div>';
        }
        echo '</form></div>';

        echo '<div class="pwf-table-container"><table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html(pwf_t('Task')) . '</th><th>' . esc_html(pwf_t('Type')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Priority')) . '</th><th>' . esc_html(pwf_t('Status')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Assigned to')) . '</th><th>' . esc_html(pwf_t('Due')) . '</th>';
        echo '<th style="text-align:center;">' . esc_html(pwf_t('Action')) . '</th>';
        echo '</tr></thead><tbody>';

        $prio_cls = array('urgent' => 'pwf-badge-admin', 'high' => 'pwf-badge-error', 'normal' => 'pwf-badge-role', 'low' => 'pwf-badge-default');
        $status_class_map = array(
            'assigned'       => 'pwf-status-waiting_photography',
            'in_progress'    => 'pwf-status-waiting_content',
            'on_hold'        => 'pwf-status-on_hold',
            'submitted'      => 'pwf-status-waiting_review',
            'needs_revision' => 'pwf-status-needs_revision',
            'approved'       => 'pwf-status-completed',
            'cancelled'      => 'pwf-status-cancelled',
        );

        foreach ($rows as $task) {
            $t_url = admin_url('admin.php?page=pwf-task&task_id=' . $task->task_id);
            $overdue = ($task->due_date && $task->status !== 'approved' && $task->due_date < current_time('Y-m-d'));
            $task_atts = !empty($task->attachments) ? json_decode($task->attachments, true) : array();
            $att_count = is_array($task_atts) ? count($task_atts) : 0;
            $deliv_files = !empty($task->deliverable_files) ? json_decode($task->deliverable_files, true) : array();
            $deliv_count = is_array($deliv_files) ? count($deliv_files) : 0;

            echo '<tr>';
            echo '<td>';
            echo '<a href="' . esc_url($t_url) . '" style="font-weight:700; color:#0f172a; text-decoration:none; font-size:13px;">#' . absint($task->task_id) . ' ' . esc_html($task->title) . '</a>';
            if ($task->product_id) {
                $p = wc_get_product($task->product_id);
                $p_name = $p ? $p->get_name() : ('#' . $task->product_id);
                echo '<div style="font-size:11px; color:#64748b; margin-top:2px;">📦 ' . esc_html(pwf_t('Product:')) . ' <a href="' . esc_url(self::url($task->product_id)) . '">' . esc_html($p_name) . '</a></div>';
            }
            if ($att_count > 0 || $deliv_count > 0) {
                echo '<div style="display:flex; gap:6px; margin-top:4px;">';
                if ($att_count > 0) {
                    echo '<span style="display:inline-flex; align-items:center; gap:2px; font-size:10px; background:#f1f5f9; color:#475569; padding:2px 6px; border-radius:4px;" title="' . esc_attr(pwf_t('Reference Attachments')) . '"><span class="dashicons dashicons-paperclip" style="font-size:12px; width:12px; height:12px;"></span> ' . esc_html($att_count) . '</span>';
                }
                if ($deliv_count > 0) {
                    echo '<span style="display:inline-flex; align-items:center; gap:2px; font-size:10px; background:#ecfdf5; color:#059669; padding:2px 6px; border-radius:4px;" title="' . esc_attr(pwf_t('Delivered Files')) . '"><span class="dashicons dashicons-yes-alt" style="font-size:12px; width:12px; height:12px;"></span> ' . esc_html($deliv_count) . '</span>';
                }
                echo '</div>';
            }
            echo '</td>';
            echo '<td style="font-size:12px;">' . esc_html(PWF_Task_Manager::types()[$task->task_type]['label'] ?? $task->task_type) . '</td>';
            echo '<td><span class="pwf-badge ' . esc_attr($prio_cls[$task->priority] ?? 'pwf-badge-default') . '">' . esc_html(PWF_Task_Manager::priorities()[$task->priority] ?? $task->priority) . '</span></td>';
            echo '<td><span class="pwf-status ' . esc_attr($status_class_map[$task->status] ?? '') . '">' . esc_html(PWF_Task_Manager::statuses()[$task->status] ?? $task->status) . '</span></td>';
            echo '<td>' . esc_html($task->assignee_name ?: pwf_t('Unassigned')) . '</td>';
            echo '<td class="' . ($overdue ? 'pwf-overdue' : '') . '">' . esc_html($task->due_date ?: '—') . ($overdue ? ' ⚠' : '') . '</td>';
            echo '<td style="text-align:center;"><a class="button pwf-btn-table-action" href="' . esc_url($t_url) . '"><span class="dashicons dashicons-visibility"></span> ' . esc_html(pwf_t('Open task')) . '</a></td>';
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="7" style="text-align:center; padding:32px 20px; color:var(--pwf-slate-400);">' . esc_html(pwf_t('No tasks match these filters.')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function table($mine) {
        global $wpdb;
        $stage = sanitize_key(wp_unslash($_GET['stage'] ?? ''));
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $page = max(1, absint($_GET['paged'] ?? 1));
        $where = "p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft')";
        if ($mine) {
            $user = get_current_user_id();
            $where .= $wpdb->prepare(" AND (EXISTS (SELECT 1 FROM {$wpdb->prefix}pwf_assignments a WHERE a.product_id=w.product_id AND a.user_id=%d AND a.completed_at IS NULL AND ((a.stage='photography' AND w.current_status='waiting_photography') OR (a.stage='content' AND w.current_status='waiting_content') OR (a.stage='review' AND w.current_status='waiting_review') OR (a.stage='social' AND w.current_status='ready_for_social'))) OR (w.created_by=%d AND w.current_status='new_product'))", $user, $user);
        }
        if (isset(PWF_Workflow_Manager::states()[$stage])) { $where .= $wpdb->prepare(' AND w.current_status=%s', $stage); }
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(" AND (p.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_sku' AND pm.meta_value LIKE %s))", $like, $like);
        }
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pwf_workflows w JOIN {$wpdb->posts} p ON p.ID=w.product_id WHERE $where");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT w.* FROM {$wpdb->prefix}pwf_workflows w JOIN {$wpdb->posts} p ON p.ID=w.product_id WHERE $where ORDER BY w.updated_at DESC, w.product_id DESC LIMIT 20 OFFSET %d", ($page - 1) * 20));

        // Filters bar
        echo '<div class="pwf-filters-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 18px; margin-bottom:18px;">';
        echo '<form method="get" class="pwf-filters" style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin:0;">';
        echo '<input type="hidden" name="page" value="' . ($mine ? 'pwf-tasks' : 'pwf') . '">';
        if (!$mine) {
            echo '<input type="hidden" name="tab" value="pipeline">';
        }
        echo '<div class="pwf-filter-item" style="display:flex; flex-direction:column; gap:4px;">';
        echo '<label style="font-size:12px; font-weight:700; color:#475569;">' . esc_html(pwf_t('Stage')) . '</label>';
        echo '<select name="stage" style="width:190px; height:38px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:13px;">';
        echo '<option value="">' . esc_html(pwf_t('All stages')) . '</option>';
        foreach (PWF_Workflow_Manager::states() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($stage, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div class="pwf-filter-item pwf-filter-search" style="display:flex; flex-direction:column; gap:4px;">';
        echo '<label style="font-size:12px; font-weight:700; color:#475569;">' . esc_html(pwf_t('Product or SKU')) . '</label>';
        echo '<input type="text" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr(pwf_t('Product or SKU')) . '" style="width:260px; height:38px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:13px;">';
        echo '</div>';
        echo '<div class="pwf-filter-item pwf-filter-btn">';
        echo '<button class="button button-primary pwf-btn-filter" style="height:38px; padding:0 20px; border-radius:8px; font-weight:600; font-size:13px; background:#4f46e5; border-color:#4f46e5; color:#fff;">' . esc_html(pwf_t('Filter')) . '</button>';
        echo '</div>';
        if ($stage || $search) {
            $clear_url = $mine ? admin_url('admin.php?page=pwf-tasks') : admin_url('admin.php?page=pwf&tab=pipeline');
            echo '<div class="pwf-filter-item pwf-filter-btn">';
            echo '<a href="' . esc_url($clear_url) . '" class="button button-link pwf-btn-clear" style="height:38px; display:inline-flex; align-items:center; gap:4px; font-size:13px; color:#64748b; text-decoration:none;"><span class="dashicons dashicons-dismiss"></span> ' . esc_html(pwf_t('Clear filters')) . '</a>';
            echo '</div>';
        }
        echo '</form>';
        echo '</div>';

        // Table container
        echo '<div class="pwf-table-container">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:34%;">' . esc_html(pwf_t('Product / SKU')) . '</th>';
        echo '<th style="width:20%;">' . esc_html(pwf_t('Stage')) . '</th>';
        echo '<th style="width:32%;">' . esc_html(pwf_t('Assignments')) . '</th>';
        echo '<th style="width:14%; text-align:center;">' . esc_html(pwf_t('Action')) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            if (!PWF_Permission_Manager::can_view($row->product_id)) { continue; }
            $product = wc_get_product($row->product_id);
            if (!$product) { continue; }
            
            $status_class = 'pwf-status-' . sanitize_html_class($row->current_status);
            $status_label = PWF_Workflow_Manager::states()[$row->current_status] ?? $row->current_status;

            echo '<tr>';
            echo '<td>';
            echo '<div class="pwf-product-cell" style="display:flex; align-items:center; gap:12px;">';
            $thumb_id = $product->get_image_id();
            if ($thumb_id) {
                $img_url = wp_get_attachment_image_url($thumb_id, 'thumbnail');
                if ($img_url) {
                    echo '<img src="' . esc_url($img_url) . '" class="pwf-product-cell-img" style="width:48px; height:48px; min-width:48px; max-width:48px; object-fit:cover; border-radius:8px; border:1px solid #e2e8f0; display:block;" alt="">';
                } else {
                    echo '<div class="pwf-product-cell-placeholder" style="width:48px; height:48px; min-width:48px; max-width:48px; border-radius:8px; background:#f1f5f9; border:1px dashed #cbd5e1; display:flex; align-items:center; justify-content:center; color:#94a3b8;"><span class="dashicons dashicons-format-image"></span></div>';
                }
            } else {
                echo '<div class="pwf-product-cell-placeholder" style="width:48px; height:48px; min-width:48px; max-width:48px; border-radius:8px; background:#f1f5f9; border:1px dashed #cbd5e1; display:flex; align-items:center; justify-content:center; color:#94a3b8;"><span class="dashicons dashicons-camera"></span></div>';
            }
            echo '<div class="pwf-product-cell-meta">';
            echo '<a href="' . esc_url(self::url($row->product_id)) . '" class="pwf-product-name" style="font-size:13px; font-weight:700; color:#0f172a; text-decoration:none; line-height:1.4; display:block;">' . esc_html($product->get_name()) . '</a>';
            echo '<div class="pwf-product-sub" style="display:flex; align-items:center; gap:6px; margin-top:3px;">';
            echo '<span class="pwf-sku-badge" style="background:#f1f5f9; color:#475569; padding:2px 6px; border-radius:4px; font-weight:600; font-family:\'Vazirmatn\', monospace; font-size:11px;">#' . absint($row->product_id) . '</span>';
            if ($product->get_sku()) {
                echo '<span class="pwf-sku-text" style="color:#64748b; font-size:11px;">' . esc_html($product->get_sku()) . '</span>';
            }
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</td>';

            echo '<td><span class="pwf-status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span></td>';

            echo '<td>';
            $assignments = PWF_Assignment_Manager::all($row->product_id);
            if (empty($assignments)) {
                echo '<span class="pwf-unassigned-tag">' . esc_html(pwf_t('Unassigned')) . '</span>';
            } else {
                echo '<div class="pwf-assignments-list">';
                foreach ($assignments as $a) {
                    if ($mine && (int) $a->user_id !== get_current_user_id()) { continue; }
                    $user = get_userdata($a->user_id);
                    echo '<div class="pwf-assignee-chip">';
                    echo '<span class="pwf-stage-tag">' . esc_html(PWF_I18n::stage($a->stage)) . ':</span> ';
                    echo '<span class="pwf-user-name">' . esc_html($user ? $user->display_name : pwf_t('Deleted user')) . '</span>';
                    if ($a->due_date) {
                        $is_overdue = !$a->completed_at && $a->due_date < current_time('Y-m-d');
                        echo ' <span class="' . ($is_overdue ? 'pwf-overdue' : 'pwf-due-date') . '">' . esc_html(sprintf(pwf_t('Due %s'), $a->due_date)) . '</span>';
                    }
                    if ($a->completed_at) {
                        echo ' <span class="pwf-done-check" title="' . esc_attr(pwf_t('Completed')) . '">✓</span>';
                    }
                    echo '</div>';
                }
                echo '</div>';
            }
            echo '</td>';

            echo '<td style="text-align:center;">';
            echo '<a class="button pwf-btn-table-action" href="' . esc_url(self::url($row->product_id)) . '"><span class="dashicons dashicons-visibility"></span> ' . esc_html(pwf_t('Open task')) . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        if (!$rows) {
            echo '<tr><td colspan="4" style="text-align:center; padding:36px 20px; color:var(--pwf-slate-400);">' . esc_html(pwf_t('No products match these filters.')) . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>'; // table container

        echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; font-size:13px; color:var(--pwf-slate-600);">';
        echo '<span>' . esc_html(sprintf(pwf_t('Products: %d'), $total)) . '</span>';
        echo wp_kses_post((string) paginate_links(array('base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => (int) ceil($total / 20), 'prev_text' => pwf_t('Previous'), 'next_text' => pwf_t('Next'))));
        echo '</div>';
    }

    public static function create_page() {
        if (!current_user_can('create_product_request')) { wp_die(pwf_t('Access denied.')); }
        
        echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';
        
        // Modern Hero Banner for New Product
        echo '<div class="pwf-hero">';
        echo '<div class="pwf-hero-title">';
        echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-plus-alt2"></span></div>';
        echo '<div>';
        echo '<h1>' . esc_html(pwf_t('New Product Registration')) . '</h1>';
        echo '<p>' . esc_html(pwf_t('Enroll a new product directly into the WooCommerce production pipeline (photography → content → review → publishing).')) . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<div class="pwf-hero-actions">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf')) . '" class="button pwf-btn-subtle">&larr; ' . esc_html(pwf_t('Dashboard')) . '</a>';
        echo '</div>';
        echo '</div>';

        self::notice();

        // Difference explainer banner
        echo '<div class="pwf-guide-banner" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 22px; margin-bottom:22px; box-shadow:0 1px 3px rgba(0,0,0,0.03);">';
        echo '<div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">';
        echo '<span style="font-size:20px;">💡</span>';
        echo '<strong style="font-size:14px; color:#0f172a;">' . esc_html(pwf_t('Workflow Guide: Understanding Requests, Tasks & Products')) . '</strong>';
        echo '</div>';
        echo '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px;">';
        echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; border-right:4px solid #10b981;">';
        echo '<strong style="color:#10b981; font-size:13px;">📦 ' . esc_html(pwf_t('Product Pipeline')) . '</strong>';
        echo '<p style="margin:4px 0 0; font-size:12px; color:#64748b; line-height:1.5;">' . esc_html(pwf_t('The end-to-end WooCommerce catalog item tracked from production intake, photo shoot, copywriting, QA approval, to live site publishing.')) . '</p>';
        echo '</div>';
        echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; border-right:4px solid #4f46e5;">';
        echo '<strong style="color:#4f46e5; font-size:13px;">📥 ' . esc_html(pwf_t('Request (Intake)')) . '</strong>';
        echo '<p style="margin:4px 0 0; font-size:12px; color:#64748b; line-height:1.5;">' . esc_html(pwf_t('Initial proposal or demand from factory/sales with sample photos and reference files. Management reviews it and converts it into specific Tasks.')) . '</p>';
        echo '</div>';
        echo '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; border-right:4px solid #0284c7;">';
        echo '<strong style="color:#0284c7; font-size:13px;">📋 ' . esc_html(pwf_t('Task (Execution)')) . '</strong>';
        echo '<p style="margin:4px 0 0; font-size:12px; color:#64748b; line-height:1.5;">' . esc_html(pwf_t('Assigned to a specific person (photographer, writer, programmer) with a clear deadline, attached guidelines, and a deliverable submission flow.')) . '</p>';
        echo '</div>';
        echo '</div></div>';

        echo '<div class="pwf-card pwf-product-create-card" style="max-width:860px; margin:0 auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 2px 8px rgba(0,0,0,0.04); padding:24px 28px;">';
        echo '<div style="display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #f1f5f9; padding-bottom:14px; margin-bottom:20px;">';
        echo '<div style="display:flex; align-items:center; gap:10px;">';
        echo '<div style="width:38px; height:38px; border-radius:8px; background:#ecfdf5; color:#059669; display:flex; align-items:center; justify-content:center; font-size:20px;">📦</div>';
        echo '<div><h3 style="margin:0; font-size:16px; font-weight:700; color:#0f172a;">' . esc_html(pwf_t('Product Specifications & Initial Entry')) . '</h3>';
        echo '<p style="margin:2px 0 0; font-size:12px; color:#64748b;">' . esc_html(pwf_t('This product will be created as a Draft in WooCommerce and will enter the photography stage.')) . '</p></div>';
        echo '</div></div>';

        self::form_start('create', 0, true);
        echo '<input type="hidden" name="language" value="' . esc_attr(PWF_I18n::locale()) . '">';

        echo '<div style="display:grid; grid-template-columns:minmax(0, 2fr) minmax(0, 1fr); gap:16px; margin-bottom:16px;">';
        echo '<div>';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Product name')) . ' <span style="color:#ef4444;">*</span></label>';
        echo '<input class="regular-text" name="name" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;" required maxlength="200" placeholder="' . esc_attr(pwf_t('E.g. Crystal Fruit Bowl Model 102')) . '">';
        echo '</div>';

        echo '<div>';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('SKU')) . '</label>';
        echo '<input class="regular-text" name="sku" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;" placeholder="JB-102">';
        echo '</div>';
        echo '</div>';

        // Product category & carton specs
        echo '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:16px;">';
        echo '<div>';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Product Category')) . '</label>';
        echo '<select name="category_id" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;">';
        echo '<option value="">' . esc_html(pwf_t('Select Category (Optional)')) . '</option>';
        if (function_exists('get_terms')) {
            $cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            if (!is_wp_error($cats) && !empty($cats)) {
                foreach ($cats as $cat) {
                    echo '<option value="' . absint($cat->term_id) . '">' . esc_html($cat->name) . '</option>';
                }
            }
        }
        echo '</select>';
        echo '</div>';

        echo '<div>';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Carton Quantity')) . '</label>';
        echo '<input type="text" name="carton_qty" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;" placeholder="' . esc_attr(pwf_t('E.g. 6 pcs')) . '">';
        echo '</div>';

        echo '<div>';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Carton Volume (CBM)')) . '</label>';
        echo '<input type="text" name="cbm" style="width:100%; height:40px; border-radius:8px; border:1.5px solid #cbd5e1; padding:0 12px; font-size:13px;" placeholder="' . esc_attr(pwf_t('E.g. 0.045')) . '">';
        echo '</div>';
        echo '</div>';

        echo '<div style="margin-bottom:16px;">';
        echo '<label style="display:block; font-size:13px; font-weight:700; color:#334155; margin-bottom:6px;">' . esc_html(pwf_t('Factory notes / initial description')) . '</label>';
        echo '<textarea class="large-text" rows="3" name="description" style="width:100%; border-radius:8px; border:1.5px solid #cbd5e1; padding:10px 12px; font-size:13px;" placeholder="' . esc_attr(pwf_t('Enter factory details, dimensions, material, or specific notes for photography and content teams...')) . '"></textarea>';
        echo '</div>';

        // Initial factory sample photos and reference files upload
        echo '<div class="pwf-attachment-upload-box" style="background:#f8fafc; border:1.5px dashed #cbd5e1; border-radius:12px; padding:18px; margin:16px 0;">';
        echo '<label style="display:block; cursor:pointer;">';
        echo '<strong style="display:flex; align-items:center; gap:8px; font-size:14px; color:#1e293b;">';
        echo '<span class="dashicons dashicons-camera" style="color:var(--pwf-primary);"></span> ';
        echo esc_html(pwf_t('Factory Sample Photos & Reference Files (Optional)'));
        echo '</strong>';
        echo '<p style="margin:4px 0 10px; font-size:12px; color:#64748b;">' . esc_html(pwf_t('Upload initial factory photos, catalog pages, or technical spec sheets so the photography and content teams have immediate reference.')) . '</p>';
        echo '<input type="file" name="product_attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar" style="font-size:13px; width:100%;">';
        echo '</label>';
        echo '</div>';

        echo '<div style="display:flex; align-items:center; gap:12px; margin-top:24px; border-top:1px solid #f1f5f9; padding-top:18px;">';
        submit_button(pwf_t('Create draft product'), 'primary', 'submit', false, array('style' => 'height:40px; padding:0 24px; border-radius:8px; font-weight:600; font-size:13px; background:#4f46e5; border-color:#4f46e5;'));
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf')) . '" class="button button-secondary" style="height:40px; display:inline-flex; align-items:center; padding:0 20px; border-radius:8px; font-size:13px;">' . esc_html(pwf_t('Cancel')) . '</a>';
        echo '</div>';

        echo '</form>';
        echo '</div>'; // card
        echo '</div>'; // wrap
    }
}
