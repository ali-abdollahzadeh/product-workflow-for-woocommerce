<?php
defined('ABSPATH') || exit;

function pwf_product_page() {
    $id = absint($_GET['product_id'] ?? 0);
    if (!PWF_Permission_Manager::can_view($id)) { wp_die(pwf_t('You do not have access to this product.'), '', array('response' => 403)); }
    $row = PWF_Workflow_Manager::get($id);
    $product = wc_get_product($id);
    if (!$product) { wp_die(pwf_t('Product not found.')); }

    $status_key = $row->current_status;
    $status_label = PWF_Workflow_Manager::states()[$status_key] ?? $status_key;
    $status_class = 'pwf-status-' . sanitize_html_class($status_key);

    echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';

    // Modern Hero Banner for Product Details
    echo '<div class="pwf-hero">';
    echo '<div class="pwf-hero-title">';
    echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-products"></span></div>';
    echo '<div>';
    echo '<h1>' . esc_html($product->get_name()) . ' <span style="font-size:16px; font-weight:400; opacity:0.85;">(#' . $id . ')</span></h1>';
    echo '<div style="display:flex; gap:10px; align-items:center; margin-top:6px; flex-wrap:wrap;">';
    echo '<span class="pwf-status ' . esc_attr($status_class) . '" style="color:#ffffff; background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3);">' . esc_html($status_label) . '</span>';
    echo '<span style="font-size:13px; color:#e0e7ff;"><b>SKU:</b> ' . esc_html($product->get_sku() ?: pwf_t('Not set')) . '</span>';
    echo '<span style="font-size:13px; color:#e0e7ff;">&bull;</span>';
    echo '<span style="font-size:13px; color:#e0e7ff;"><b>WooCommerce:</b> ' . esc_html(PWF_I18n::publication($product->get_status())) . '</span>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '<div class="pwf-hero-actions">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=pwf')) . '" class="button pwf-btn-subtle">&larr; ' . esc_html(pwf_t('Open workflow dashboard')) . '</a>';
    if ($product->get_status() === 'publish') {
        echo '<a href="' . esc_url(get_permalink($id)) . '" target="_blank" class="button pwf-btn-primary">' . esc_html(pwf_t('View published product')) . '</a>';
    }
    echo '</div>';
    echo '</div>';

    PWF_Admin::notice();

    // Visual Workflow Pipeline Stepper
    $pipeline = array(
        'new_product'             => pwf_t('New Product'),
        'waiting_photography'     => PWF_I18n::stage('photography'),
        'waiting_content'         => PWF_I18n::stage('content'),
        'waiting_review'          => PWF_I18n::stage('review'),
        'approved'                => pwf_t('Approved'),
        'published'               => pwf_t('Published'),
    );

    $pipeline_order = array_keys($pipeline);
    $current_idx = array_search($status_key, $pipeline_order, true);
    if ($current_idx === false) {
        if ($status_key === 'photography_completed') { $current_idx = 1; }
        elseif ($status_key === 'content_completed') { $current_idx = 2; }
        elseif ($status_key === 'ready_for_social' || $status_key === 'completed') { $current_idx = 5; }
        else { $current_idx = 0; }
    }

    echo '<div class="pwf-stepper">';
    $step_num = 1;
    $total_steps = count($pipeline);
    foreach ($pipeline as $st_key => $st_name) {
        $st_idx = array_search($st_key, $pipeline_order, true);
        $is_completed = $st_idx < $current_idx;
        $is_active = $st_idx === $current_idx;
        $step_class = $is_active ? 'is-active' : ($is_completed ? 'is-completed' : '');

        echo '<div class="pwf-step ' . esc_attr($step_class) . '">';
        echo '<div class="pwf-step-circle">';
        echo $is_completed ? '✓' : $step_num;
        echo '</div>';
        echo '<span>' . esc_html($st_name) . '</span>';
        echo '</div>';

        if ($step_num < $total_steps) {
            echo '<div class="pwf-step-divider ' . ($is_completed ? 'is-completed' : '') . '"></div>';
        }
        $step_num++;
    }
    echo '</div>';

    // 2-Column Grid Layout
    echo '<div class="pwf-grid">';

    // LEFT COLUMN: Info, Images, History
    echo '<div>';

    // Section 1: Product Information
    echo '<section class="pwf-card">';
    echo '<h2><span class="dashicons dashicons-edit" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Product information')) . '</h2>';

    if (PWF_Workflow_Manager::can_edit($id, 'content')) {
        PWF_Admin::form_start('content', $id);
        foreach (array('name' => pwf_t('Name'), 'sku' => pwf_t('SKU'), 'regular_price' => pwf_t('Regular price')) as $field => $label) {
            echo '<p><label><strong>' . esc_html($label) . '</strong><br><input class="regular-text" style="width:100%; margin-top:4px;" name="' . esc_attr($field) . '" value="' . esc_attr($product->{'get_' . $field}()) . '"></label></p>';
        }
        foreach (array('description' => pwf_t('Description'), 'short_description' => pwf_t('Short description')) as $field => $label) {
            echo '<p><label><strong>' . esc_html($label) . '</strong><br><textarea class="large-text" rows="5" style="width:100%; margin-top:4px;" name="' . esc_attr($field) . '">' . esc_textarea($product->{'get_' . $field}()) . '</textarea></label></p>';
        }
        foreach (array('category_ids' => array('product_cat', pwf_t('Categories')), 'tag_ids' => array('product_tag', pwf_t('Tags'))) as $field => $definition) {
            $terms = get_terms(array('taxonomy' => $definition[0], 'hide_empty' => false));
            echo '<p><label><strong>' . esc_html($definition[1]) . '</strong><br><select name="' . esc_attr($field) . '[]" multiple style="width:100%; min-height:80px; margin-top:4px;">';
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    echo '<option value="' . absint($term->term_id) . '" ' . selected(in_array($term->term_id, $product->{'get_' . $field}(), true), true, false) . '>' . esc_html($term->name) . '</option>';
                }
            }
            echo '</select></label></p>';
        }

        $attributes = array();
        $has_global = false;
        foreach ($product->get_attributes() as $attribute) {
            if ($attribute->is_taxonomy()) { $has_global = true; continue; }
            $attributes[] = $attribute->get_name() . ': ' . implode(' | ', $attribute->get_options());
        }
        if (!$has_global) {
            echo '<p><label><strong>' . esc_html(pwf_t('Attributes (one per line, e.g. Color: Red | Blue)')) . '</strong><br><textarea class="large-text" rows="4" style="width:100%; margin-top:4px;" name="attributes_text">' . esc_textarea(implode("\n", $attributes)) . '</textarea></label></p>';
        } else {
            echo '<p class="description">' . esc_html(pwf_t('Global attributes are managed in the WooCommerce editor.')) . '</p>';
        }

        echo '<p><label><strong>' . esc_html(pwf_t('Stock status')) . '</strong><br><select name="stock_status" style="margin-top:4px;">';
        foreach (array('instock' => pwf_t('In stock'), 'outofstock' => pwf_t('Out of stock'), 'onbackorder' => pwf_t('On backorder')) as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($product->get_stock_status(), $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';

        foreach (array('seo_title' => pwf_t('SEO title'), 'seo_description' => pwf_t('SEO description')) as $field => $label) {
            echo '<p><label><strong>' . esc_html($label) . '</strong><br><input class="large-text" style="width:100%; margin-top:4px;" name="' . esc_attr($field) . '" value="' . esc_attr($product->get_meta('_pwf_' . $field)) . '"></label></p>';
        }
        echo '<p class="description" style="color:var(--pwf-slate-500);">' . esc_html(pwf_t('SEO fields are preparation notes stored with this product. Connect your SEO plugin to use them on the storefront.')) . '</p>';

        echo '<p style="margin-top:16px;">';
        submit_button(pwf_t('Save content'), 'primary', 'submit', false);
        echo '</p>';
        echo '</form>';
    } else {
        echo '<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; background:var(--pwf-slate-50); padding:16px; border-radius:var(--pwf-radius-sm); border:1px solid var(--pwf-slate-200);">';
        echo '<div><strong>' . esc_html(pwf_t('Price:')) . '</strong> ' . ($product->get_regular_price() === '' ? '<span style="color:var(--pwf-slate-400);">' . esc_html(pwf_t('Not set')) . '</span>' : wp_kses_post(wc_price($product->get_regular_price()))) . '</div>';
        echo '<div><strong>' . esc_html(pwf_t('Stock status')) . ':</strong> ' . esc_html($product->get_stock_status() ?: pwf_t('In stock')) . '</div>';
        echo '</div>';

        $carton_qty = get_post_meta($id, '_pwf_carton_qty', true);
        $cbm_val = get_post_meta($id, '_pwf_cbm', true);
        if ($carton_qty || $cbm_val) {
            echo '<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; background:var(--pwf-slate-50); padding:16px; border-radius:var(--pwf-radius-sm); border:1px solid var(--pwf-slate-200);">';
            if ($carton_qty) {
                echo '<div><strong>' . esc_html(pwf_t('Carton quantity:')) . '</strong> ' . esc_html($carton_qty) . '</div>';
            }
            if ($cbm_val) {
                echo '<div><strong>' . esc_html(pwf_t('CBM:')) . '</strong> ' . esc_html($cbm_val) . '</div>';
            }
            echo '</div>';
        }

        if ($product->get_description()) {
            echo '<div style="margin-top:16px;"><strong>' . esc_html(pwf_t('Description')) . ':</strong>';
            echo '<div style="margin-top:6px; color:var(--pwf-slate-700); line-height:1.6;">' . wp_kses_post(wpautop($product->get_description())) . '</div></div>';
        }
        if ($product->get_short_description()) {
            echo '<div style="margin-top:16px;"><strong>' . esc_html(pwf_t('Short description')) . ':</strong>';
            echo '<div style="margin-top:6px; color:var(--pwf-slate-700); line-height:1.6;">' . wp_kses_post(wpautop($product->get_short_description())) . '</div></div>';
        }
    }
    echo '</section>';

    // Section 2: Product Images & Media
    echo '<section class="pwf-card">';
    echo '<h2><span class="dashicons dashicons-camera" style="color:#9333ea;"></span> ' . esc_html(pwf_t('Product images and reference material')) . '</h2>';

    $can_images = PWF_Workflow_Manager::can_edit($id, 'images');
    $social_view = !current_user_can('manage_workflow') && !current_user_can('assign_tasks') && !PWF_Permission_Manager::stage($id, 'photography', 'upload_product_images') && !PWF_Permission_Manager::stage($id, 'content', 'edit_product_content') && !PWF_Permission_Manager::stage($id, 'review', 'review_product') && (int) $row->created_by !== get_current_user_id();
    $images = $social_view ? array() : get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $id, 'post_mime_type' => 'image', 'numberposts' => -1, 'fields' => 'ids'));
    $images = array_filter(array_unique(array_merge($images, array($product->get_image_id()), $product->get_gallery_image_ids())));

    if ($can_images) { PWF_Admin::form_start('images', $id); }
    echo '<div class="pwf-images">';
    foreach ($images as $image_id) {
        $is_main = (int) $product->get_image_id() === (int) $image_id;
        echo '<div class="pwf-image">';
        echo '<a href="' . esc_url(wp_get_attachment_url($image_id)) . '" target="_blank">' . wp_kses_post(wp_get_attachment_image($image_id, 'thumbnail')) . '</a>';
        if ($can_images) {
            echo '<label style="margin-top:8px; display:block;"><input type="radio" name="main_image" value="' . absint($image_id) . '" ' . checked($is_main, true, false) . '> ' . esc_html(pwf_t('Main image')) . '</label>';
            echo '<label style="margin-top:4px; display:block;"><input type="checkbox" name="gallery[]" value="' . absint($image_id) . '" ' . checked(in_array($image_id, $product->get_gallery_image_ids(), true), true, false) . '> ' . esc_html(pwf_t('Gallery')) . '</label>';
        }
        echo '</div>';
    }
    if (!$images) {
        echo '<p style="color:var(--pwf-slate-400); font-style:italic;">' . esc_html(pwf_t('No images uploaded yet.')) . '</p>';
    }
    echo '</div>';

    if ($can_images && !empty($images)) {
        echo '<div style="margin-top:16px;">';
        submit_button(pwf_t('Save image selection'), 'primary', 'submit', false);
        echo '</div>';
        echo '</form>';
    }

    if ($can_images || ($row->current_status === 'new_product' && (int) $row->created_by === get_current_user_id() && current_user_can('create_product_request'))) {
        echo '<div style="margin-top:20px; border-top:1px dashed var(--pwf-slate-200); padding-top:16px;">';
        PWF_Admin::form_start('upload', $id, true);
        echo '<label><strong>' . esc_html(pwf_t('Upload JPEG, PNG or WebP')) . '</strong><br>';
        echo '<div style="display:flex; gap:10px; align-items:center; margin-top:8px; flex-wrap:wrap;">';
        echo '<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required>';
        submit_button(pwf_t('Upload image'), 'secondary', 'submit', false);
        echo '</div></label>';
        echo '</form>';
        echo '</div>';
    }
    echo '</section>';

    // Section 3: Workflow History
    if (current_user_can('view_workflow_history')) {
        $history_page = max(1, absint($_GET['history_page'] ?? 1));
        echo '<section class="pwf-card">';
        echo '<h2><span class="dashicons dashicons-backup" style="color:var(--pwf-slate-600);"></span> ' . esc_html(pwf_t('Workflow history')) . '</h2>';
        echo '<div class="pwf-table-container">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:30%;">' . esc_html(pwf_t('Time / person')) . '</th>';
        echo '<th style="width:30%;">' . esc_html(pwf_t('Event')) . '</th>';
        echo '<th style="width:40%;">' . esc_html(pwf_t('Note')) . '</th>';
        echo '</tr></thead><tbody>';

        $history = PWF_History_Manager::recent($id, $history_page);
        foreach ($history as $entry) {
            $user = get_userdata($entry->changed_by);
            echo '<tr>';
            echo '<td>' . esc_html(get_date_from_gmt($entry->changed_at) . ' · ' . ($user ? $user->display_name : pwf_t('System / deleted user'))) . '</td>';
            echo '<td><strong>' . esc_html(PWF_I18n::event($entry->event)) . '</strong><br><span style="font-size:11px; color:var(--pwf-slate-500);">' . esc_html(($entry->previous_status ? (PWF_Workflow_Manager::states()[$entry->previous_status] ?? $entry->previous_status) . ' → ' : '') . (PWF_Workflow_Manager::states()[$entry->new_status] ?? $entry->new_status)) . '</span></td>';
            echo '<td>' . esc_html(PWF_I18n::history_note($entry)) . '</td>';
            echo '</tr>';
        }
        if (!$history) {
            echo '<tr><td colspan="3" style="text-align:center; padding:20px; color:var(--pwf-slate-400);">&mdash;</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>'; // table container

        if ($history_page > 1 || count($history) === 50) {
            echo '<div style="margin-top:12px; display:flex; gap:10px;">';
            if ($history_page > 1) { echo '<a href="' . esc_url(add_query_arg('history_page', $history_page - 1, PWF_Admin::url($id))) . '" class="button button-secondary">&larr; ' . esc_html(pwf_t('Newer history')) . '</a>'; }
            if (count($history) === 50) { echo '<a href="' . esc_url(add_query_arg('history_page', $history_page + 1, PWF_Admin::url($id))) . '" class="button button-secondary">' . esc_html(pwf_t('Older history')) . ' &rarr;</a>'; }
            echo '</div>';
        }
        echo '</section>';
    }

    echo '</div>'; // left col

    // RIGHT COLUMN: Available Actions & Stage Assignments
    echo '<aside>';

    // Card 1: Available Actions
    echo '<section class="pwf-card" style="border-left:4px solid var(--pwf-primary);">';
    echo '<h2><span class="dashicons dashicons-controls-forward" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Available actions')) . '</h2>';
    $count = 0;
    foreach (PWF_Workflow_Manager::transitions()[$row->current_status] as $to => $edge) {
        if (!PWF_Workflow_Manager::allowed($id, $row->current_status, $to)) { continue; }
        $count++;
        PWF_Admin::form_start('transition', $id);
        echo '<input type="hidden" name="expected" value="' . esc_attr($row->current_status) . '">';
        echo '<input type="hidden" name="to" value="' . esc_attr($to) . '">';
        $rejection = $row->current_status === 'waiting_review' && in_array($to, array('waiting_photography', 'waiting_content'), true);
        echo '<p><label><strong>' . ($rejection ? pwf_t('Rejection reason (required)') : pwf_t('Note (optional)')) . '</strong><br>';
        echo '<textarea name="note" rows="2" style="width:100%; margin-top:4px;" ' . ($rejection ? 'required' : '') . '></textarea></label></p>';

        $btn_text = $to === 'published' ? pwf_t('Publish WooCommerce product') : sprintf(pwf_t('Move to %s'), PWF_Workflow_Manager::states()[$to]);
        submit_button($btn_text, $rejection ? 'secondary' : 'primary', 'submit', true, array('style' => 'width:100%;'));
        echo '</form>';
    }
    if (!$count) {
        echo '<p style="color:var(--pwf-slate-400); font-style:italic;">' . esc_html(pwf_t('No actions available for you at this stage.')) . '</p>';
    }
    echo '</section>';

    // Card 2: Assignments
    echo '<section class="pwf-card">';
    echo '<h2><span class="dashicons dashicons-admin-users" style="color:var(--pwf-secondary);"></span> ' . esc_html(pwf_t('Assignments')) . '</h2>';

    $stage_icons = array(
        'photography' => '📸',
        'content'     => '📝',
        'review'      => '🔍',
        'social'      => '📢',
    );

    foreach (PWF_Permission_Manager::stage_caps() as $stage => $cap) {
        $assignment = PWF_Assignment_Manager::get($id, $stage);
        $user = $assignment ? get_userdata($assignment->user_id) : null;
        $icon = $stage_icons[$stage] ?? '🎯';

        echo '<div style="margin-bottom:16px; padding:12px; background:var(--pwf-slate-50); border:1px solid var(--pwf-slate-200); border-radius:var(--pwf-radius-sm);">';
        echo '<div style="display:flex; justify-content:space-between; align-items:center;">';
        echo '<strong>' . esc_html($icon . ' ' . PWF_I18n::stage($stage)) . '</strong>';
        if ($assignment && $assignment->completed_at) {
            echo '<span class="pwf-badge pwf-badge-success">✓ ' . esc_html(pwf_t('Completed')) . '</span>';
        } elseif ($assignment) {
            echo '<span class="pwf-badge pwf-badge-role">' . esc_html(pwf_t('Active')) . '</span>';
        } else {
            echo '<span class="pwf-badge pwf-badge-default">' . esc_html(pwf_t('Unassigned')) . '</span>';
        }
        echo '</div>';

        echo '<div style="margin-top:6px; font-size:13px; color:var(--pwf-slate-700);">';
        echo '<b>' . esc_html(pwf_t('Assign user')) . ':</b> ' . esc_html($user ? $user->display_name : pwf_t('Unassigned'));
        if ($assignment && $assignment->due_date) {
            echo '<br><b>' . esc_html(pwf_t('Due date (optional)')) . ':</b> ' . esc_html($assignment->due_date);
        }
        echo '</div>';

        if (current_user_can('assign_tasks') && $row->current_status !== 'completed') {
            echo '<details style="margin-top:8px;"><summary style="font-size:12px; color:var(--pwf-primary); cursor:pointer;">' . esc_html(sprintf(pwf_t('Save %s assignment'), PWF_I18n::stage($stage))) . '</summary>';
            PWF_Admin::form_start('assign', $id);
            echo '<input type="hidden" name="stage" value="' . esc_attr($stage) . '">';
            echo '<p style="margin:8px 0 4px;"><select name="user_id" style="width:100%;" required>';
            echo '<option value="">' . esc_html(pwf_t('Choose user')) . '</option>';
            $users = get_users(array('capability' => $cap, 'orderby' => 'display_name'));
            foreach ($users as $candidate) {
                echo '<option value="' . absint($candidate->ID) . '" ' . selected($assignment ? $assignment->user_id : 0, $candidate->ID, false) . '>' . esc_html($candidate->display_name) . '</option>';
            }
            echo '</select></p>';
            echo '<p style="margin:4px 0 8px;"><input type="date" name="due_date" style="width:100%;" value="' . esc_attr($assignment ? $assignment->due_date : '') . '"></p>';
            submit_button(sprintf(pwf_t('Save %s assignment'), PWF_I18n::stage($stage)), 'secondary', 'submit', false, array('style' => 'width:100%;'));
            echo '</form></details>';
        }
        echo '</div>';
    }
    echo '</section>';

    echo '</aside>';
    echo '</div>'; // grid
    echo '</div>'; // wrap
}

function pwf_product_metabox($post) {
    echo '<div' . PWF_I18n::attributes() . '>';
    $row = PWF_Workflow_Manager::get($post->ID);
    if ($row && PWF_Permission_Manager::can_view($post->ID)) {
        $status_label = PWF_Workflow_Manager::states()[$row->current_status] ?? $row->current_status;
        echo '<p><strong>' . esc_html(pwf_t('Stage')) . ':</strong> ' . esc_html($status_label) . '</p>';
        foreach (PWF_Assignment_Manager::all($post->ID) as $a) {
            $user = get_userdata($a->user_id);
            echo '<p style="font-size:12px; margin:4px 0;"><b>' . esc_html(PWF_I18n::stage($a->stage)) . ':</b> ' . esc_html($user ? $user->display_name : pwf_t('Deleted user')) . '</p>';
        }
        echo '<p style="margin-top:10px;"><a class="button button-primary" style="width:100%; text-align:center;" href="' . esc_url(PWF_Admin::url($post->ID)) . '">' . esc_html(pwf_t('Open workflow actions')) . '</a></p>';
    } elseif (!$row && current_user_can('manage_workflow')) {
        echo '<p>' . esc_html(pwf_t('Draft products can be enrolled from the workflow dashboard using the product ID.')) . '</p>';
        echo '<p><a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=pwf')) . '">' . esc_html(pwf_t('Open workflow dashboard')) . '</a></p>';
    }
    echo '</div>';
}
