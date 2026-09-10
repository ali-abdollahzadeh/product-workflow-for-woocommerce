<?php
defined('ABSPATH') || exit;

function pwf_product_page() {
    $id = absint($_GET['product_id'] ?? 0);
    if (!PWF_Permission_Manager::can_view($id)) {
        wp_die(pwf_t('You do not have access to this product.'), '', array('response' => 403));
    }
    $row = PWF_Workflow_Manager::get($id);
    $product = wc_get_product($id);
    if (!$product) {
        wp_die(pwf_t('Product not found.'));
    }

    $status_key = $row->current_status;
    $status_label = PWF_Workflow_Manager::states()[$status_key] ?? $status_key;
    $status_class = 'pwf-status-' . sanitize_html_class($status_key);

    echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';

    // ── 1. Hero Header Banner ─────────────────────────────────────────────
    echo '<div class="pwf-hero">';
    echo '<div class="pwf-hero-title">';
    echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-products"></span></div>';
    echo '<div>';
    echo '<h1>' . esc_html($product->get_name()) . ' <span style="font-size:16px; font-weight:400; opacity:0.85;">(#' . $id . ')</span></h1>';
    echo '<div style="display:flex; gap:10px; align-items:center; margin-top:8px; flex-wrap:wrap;">';
    echo '<span class="pwf-status ' . esc_attr($status_class) . '" style="color:#ffffff; background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3); font-weight:700;">' . esc_html($status_label) . '</span>';
    echo '<span style="font-size:13px; color:#e0e7ff;"><b>' . esc_html(pwf_t('SKU')) . ':</b> ' . esc_html($product->get_sku() ?: pwf_t('Not set')) . '</span>';
    echo '<span style="font-size:13px; color:#e0e7ff;">&bull;</span>';
    echo '<span style="font-size:13px; color:#e0e7ff;"><b>WooCommerce:</b> ' . esc_html(PWF_I18n::publication($product->get_status())) . '</span>';
    if ($product->get_regular_price() !== '') {
        echo '<span style="font-size:13px; color:#e0e7ff;">&bull;</span>';
        echo '<span style="font-size:13px; color:#e0e7ff;"><b>' . esc_html(pwf_t('Price:')) . '</b> ' . wp_kses_post(wc_price($product->get_regular_price())) . '</span>';
    }
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '<div class="pwf-hero-actions">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=pwf')) . '" class="button pwf-btn-subtle">&larr; ' . esc_html(pwf_t('Open workflow dashboard')) . '</a>';
    if ($product->get_status() === 'publish') {
        echo '<a href="' . esc_url(get_permalink($id)) . '" target="_blank" class="button pwf-btn-primary"><span class="dashicons dashicons-external" style="margin-top:3px;"></span> ' . esc_html(pwf_t('View published product')) . '</a>';
    } elseif (current_user_can('edit_post', $id)) {
        echo '<a href="' . esc_url(admin_url('post.php?post=' . $id . '&action=edit')) . '" target="_blank" class="button pwf-btn-subtle"><span class="dashicons dashicons-edit" style="margin-top:3px;"></span> ' . esc_html(pwf_t('Edit in WooCommerce')) . '</a>';
    }
    echo '</div>';
    echo '</div>';

    PWF_Admin::notice();

    // ── 2. Concept Guide Banner (Clear Distinction) ────────────────────────
    echo '<div class="pwf-concept-banner">';
    echo '<div class="pwf-concept-banner-title"><span class="dashicons dashicons-info" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Workflow and System Concepts Guide')) . '</div>';
    echo '<div class="pwf-concept-grid">';
    
    echo '<div class="pwf-concept-item">';
    echo '<div class="pwf-concept-item-title"><span class="dashicons dashicons-email-alt" style="color:#0ea5e9;"></span> ' . esc_html(pwf_t('Requests')) . '</div>';
    echo '<div class="pwf-concept-item-desc">' . esc_html(pwf_t('1. Request (Intake): Submitted by Factory or Sales as an initial proposal or need, waiting for Management review and approval.')) . '</div>';
    echo '</div>';
    
    echo '<div class="pwf-concept-item">';
    echo '<div class="pwf-concept-item-title"><span class="dashicons dashicons-clipboard" style="color:#8b5cf6;"></span> ' . esc_html(pwf_t('Tasks')) . '</div>';
    echo '<div class="pwf-concept-item-desc">' . esc_html(pwf_t('2. Task (Execution): A specific work order with deadline, priority, and deliverables assigned by Management to a team member (photographer, writer, developer).')) . '</div>';
    echo '</div>';

    echo '<div class="pwf-concept-item">';
    echo '<div class="pwf-concept-item-title"><span class="dashicons dashicons-products" style="color:#10b981;"></span> ' . esc_html(pwf_t('Product Workflow')) . '</div>';
    echo '<div class="pwf-concept-item-desc">' . esc_html(pwf_t('3. Product Workflow (Pipeline): The actual WooCommerce catalog product moving step-by-step through photography, content, review, and publishing.')) . '</div>';
    echo '</div>';

    echo '</div>';
    echo '</div>';

    // ── 3. Visual Workflow Pipeline Stepper ────────────────────────────────
    $pipeline = array(
        'new_product'         => pwf_t('New Product'),
        'waiting_photography' => PWF_I18n::stage('photography'),
        'waiting_content'     => PWF_I18n::stage('content'),
        'waiting_review'      => PWF_I18n::stage('review'),
        'approved'            => pwf_t('Approved'),
        'published'           => pwf_t('Published'),
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

    // ── 4. Main 2-Column Grid Layout ──────────────────────────────────────
    echo '<div class="pwf-grid">';

    // ── LEFT / MAIN COLUMN ────────────────────────────────────────────────
    echo '<div>';

    // Card 1: Product Catalog Information & Content Form
    echo '<section class="pwf-card" style="margin-bottom:24px;">';
    echo '<h2><span class="dashicons dashicons-edit" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Product information')) . '</h2>';

    if (PWF_Workflow_Manager::can_edit($id, 'content')) {
        PWF_Admin::form_start('content', $id);

        // Section A: Basic Catalog Information
        echo '<div class="pwf-form-section">';
        echo '<div class="pwf-form-section-title"><span class="dashicons dashicons-admin-settings" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Basic Catalog Information')) . '</div>';
        echo '<div class="pwf-form-row">';
        echo '<div class="pwf-field-group">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('Name')) . '</strong> <span style="color:var(--pwf-danger);">*</span></label>';
        echo '<input type="text" class="pwf-field-control" name="name" value="' . esc_attr($product->get_name()) . '" required>';
        echo '</div>';
        echo '<div class="pwf-field-group">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('SKU')) . '</strong></label>';
        echo '<input type="text" class="pwf-field-control" name="sku" value="' . esc_attr($product->get_sku()) . '">';
        echo '</div>';
        echo '</div>';

        echo '<div class="pwf-form-row">';
        echo '<div class="pwf-field-group">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('Regular price')) . '</strong></label>';
        echo '<input type="text" class="pwf-field-control" name="regular_price" value="' . esc_attr($product->get_regular_price()) . '" placeholder="0">';
        echo '</div>';
        echo '<div class="pwf-field-group">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('Stock status')) . '</strong></label>';
        echo '<select class="pwf-field-control" name="stock_status">';
        foreach (array('instock' => pwf_t('In stock'), 'outofstock' => pwf_t('Out of stock'), 'onbackorder' => pwf_t('On backorder')) as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($product->get_stock_status(), $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</div>';
        echo '</div>'; // .pwf-form-section

        // Section B: Packaging & Logistics
        $carton_qty = get_post_meta($id, '_pwf_carton_qty', true);
        $cbm_val = get_post_meta($id, '_pwf_cbm', true);
        echo '<div class="pwf-form-section">';
        echo '<div class="pwf-form-section-title"><span class="dashicons dashicons-archive" style="color:var(--pwf-secondary);"></span> ' . esc_html(pwf_t('Logistics & Packaging')) . '</div>';
        echo '<div class="pwf-form-row">';
        echo '<div class="pwf-field-group">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('Carton quantity:')) . '</strong></label>';
        echo '<input type="text" class="pwf-field-control" name="carton_qty" value="' . esc_attr($carton_qty) . '" placeholder="e.g. 24">';
        echo '</div>';
        echo '<div class="pwf-field-group">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('CBM:')) . '</strong></label>';
        echo '<input type="text" class="pwf-field-control" name="cbm" value="' . esc_attr($cbm_val) . '" placeholder="e.g. 0.045">';
        echo '</div>';
        echo '</div>';
        echo '</div>'; // .pwf-form-section

        // Section C: Descriptions & Content
        echo '<div class="pwf-form-section">';
        echo '<div class="pwf-form-section-title"><span class="dashicons dashicons-text-page" style="color:#8b5cf6;"></span> ' . esc_html(pwf_t('Descriptions & Content')) . '</div>';
        echo '<div class="pwf-field-group" style="margin-bottom:16px;">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('Description')) . '</strong></label>';
        echo '<textarea class="pwf-field-control" rows="6" name="description" placeholder="' . esc_attr(pwf_t('Full product description and specifications...')) . '">' . esc_textarea($product->get_description()) . '</textarea>';
        echo '</div>';
        echo '<div class="pwf-field-group">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('Short description')) . '</strong></label>';
        echo '<textarea class="pwf-field-control" rows="3" name="short_description" placeholder="' . esc_attr(pwf_t('Short summary or highlights...')) . '">' . esc_textarea($product->get_short_description()) . '</textarea>';
        echo '</div>';
        echo '</div>'; // .pwf-form-section

        // Section D: Categories & Tags (Modern Scrollable Checklists)
        echo '<div class="pwf-form-section">';
        echo '<div class="pwf-form-section-title"><span class="dashicons dashicons-category" style="color:#10b981;"></span> ' . esc_html(pwf_t('Categories & Taxonomies')) . '</div>';
        echo '<div class="pwf-form-row">';
        
        // Categories
        echo '<div class="pwf-field-group">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('Categories')) . '</strong></label>';
        echo '<div class="pwf-tax-box">';
        echo '<div class="pwf-tax-list">';
        $cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
        if (!is_wp_error($cats) && !empty($cats)) {
            $product_cat_ids = $product->get_category_ids();
            foreach ($cats as $cat) {
                $checked = in_array($cat->term_id, $product_cat_ids, true) ? 'checked' : '';
                echo '<label class="pwf-tax-item">';
                echo '<input type="checkbox" name="category_ids[]" value="' . absint($cat->term_id) . '" ' . $checked . '> ';
                echo '<span>' . esc_html($cat->name) . '</span>';
                echo '</label>';
            }
        } else {
            echo '<div style="font-size:12px; color:var(--pwf-slate-400); padding:4px;">' . esc_html(pwf_t('No categories found.')) . '</div>';
        }
        echo '</div></div>';
        echo '</div>';

        // Tags
        echo '<div class="pwf-field-group">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('Tags')) . '</strong></label>';
        echo '<div class="pwf-tax-box">';
        echo '<div class="pwf-tax-list">';
        $tags = get_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
        if (!is_wp_error($tags) && !empty($tags)) {
            $product_tag_ids = $product->get_tag_ids();
            foreach ($tags as $tag) {
                $checked = in_array($tag->term_id, $product_tag_ids, true) ? 'checked' : '';
                echo '<label class="pwf-tax-item">';
                echo '<input type="checkbox" name="tag_ids[]" value="' . absint($tag->term_id) . '" ' . $checked . '> ';
                echo '<span>' . esc_html($tag->name) . '</span>';
                echo '</label>';
            }
        } else {
            echo '<div style="font-size:12px; color:var(--pwf-slate-400); padding:4px;">' . esc_html(pwf_t('No tags found.')) . '</div>';
        }
        echo '</div></div>';
        echo '</div>';

        echo '</div>'; // .pwf-form-row
        echo '</div>'; // .pwf-form-section

        // Section E: Attributes
        $attributes = array();
        $has_global = false;
        foreach ($product->get_attributes() as $attribute) {
            if ($attribute->is_taxonomy()) { $has_global = true; continue; }
            $attributes[] = $attribute->get_name() . ': ' . implode(' | ', $attribute->get_options());
        }
        echo '<div class="pwf-form-section">';
        echo '<div class="pwf-form-section-title"><span class="dashicons dashicons-tag" style="color:#f59e0b;"></span> ' . esc_html(pwf_t('Attributes (one per line, e.g. Color: Red | Blue)')) . '</div>';
        if (!$has_global) {
            echo '<div class="pwf-field-group">';
            echo '<textarea class="pwf-field-control" rows="3" name="attributes_text" placeholder="' . esc_attr('رنگ: سفید | مشکی | نقره‌ای') . '">' . esc_textarea(implode("\n", $attributes)) . '</textarea>';
            echo '<div class="pwf-field-hint">' . esc_html(pwf_t('Attributes (one per line, e.g. Color: Red | Blue)')) . '</div>';
            echo '</div>';
        } else {
            echo '<p class="description" style="margin:0;">' . esc_html(pwf_t('Global attributes are managed in the WooCommerce editor.')) . '</p>';
        }
        echo '</div>'; // .pwf-form-section

        // Section F: SEO & Metadata
        echo '<div class="pwf-form-section">';
        echo '<div class="pwf-form-section-title"><span class="dashicons dashicons-search" style="color:#0ea5e9;"></span> ' . esc_html(pwf_t('SEO & Metadata')) . '</div>';
        echo '<div class="pwf-form-row">';
        echo '<div class="pwf-field-group">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('SEO title')) . '</strong></label>';
        echo '<input type="text" class="pwf-field-control" name="seo_title" value="' . esc_attr($product->get_meta('_pwf_seo_title')) . '">';
        echo '</div>';
        echo '<div class="pwf-field-group">';
        echo '<label class="pwf-field-label"><strong>' . esc_html(pwf_t('SEO description')) . '</strong></label>';
        echo '<input type="text" class="pwf-field-control" name="seo_description" value="' . esc_attr($product->get_meta('_pwf_seo_description')) . '">';
        echo '</div>';
        echo '</div>';
        echo '<div class="pwf-field-hint">' . esc_html(pwf_t('SEO fields are preparation notes stored with this product. Connect your SEO plugin to use them on the storefront.')) . '</div>';
        echo '</div>'; // .pwf-form-section

        // Action Submit Button
        echo '<div style="margin-top:20px; display:flex; gap:12px; align-items:center;">';
        echo '<button type="submit" class="button pwf-btn-primary" style="padding:10px 24px; font-size:14px; font-weight:700;"><span class="dashicons dashicons-saved" style="margin-top:2px;"></span> ' . esc_html(pwf_t('Save Product Details')) . '</button>';
        echo '</div>';
        echo '</form>';
    } else {
        // Read-only presentation when user cannot edit content
        echo '<div class="pwf-meta-grid" style="margin-bottom:16px;">';
        echo '<div class="pwf-meta-item"><span class="pwf-meta-label">' . esc_html(pwf_t('Price:')) . '</span><span class="pwf-meta-val">' . ($product->get_regular_price() === '' ? '<span style="color:var(--pwf-slate-400);">' . esc_html(pwf_t('Not set')) . '</span>' : wp_kses_post(wc_price($product->get_regular_price()))) . '</span></div>';
        echo '<div class="pwf-meta-item"><span class="pwf-meta-label">' . esc_html(pwf_t('Stock status')) . '</span><span class="pwf-meta-val">' . esc_html($product->get_stock_status() ?: pwf_t('In stock')) . '</span></div>';
        
        $carton_qty = get_post_meta($id, '_pwf_carton_qty', true);
        $cbm_val = get_post_meta($id, '_pwf_cbm', true);
        if ($carton_qty) {
            echo '<div class="pwf-meta-item"><span class="pwf-meta-label">' . esc_html(pwf_t('Carton quantity:')) . '</span><span class="pwf-meta-val">' . esc_html($carton_qty) . '</span></div>';
        }
        if ($cbm_val) {
            echo '<div class="pwf-meta-item"><span class="pwf-meta-label">' . esc_html(pwf_t('CBM:')) . '</span><span class="pwf-meta-val">' . esc_html($cbm_val) . '</span></div>';
        }
        echo '</div>';

        if ($product->get_description()) {
            echo '<div style="margin-top:16px; background:var(--pwf-slate-50); padding:16px; border-radius:var(--pwf-radius-sm); border:1px solid var(--pwf-slate-200);">';
            echo '<strong style="color:var(--pwf-slate-800);">' . esc_html(pwf_t('Description')) . ':</strong>';
            echo '<div style="margin-top:8px; color:var(--pwf-slate-700); line-height:1.7;">' . wp_kses_post(wpautop($product->get_description())) . '</div>';
            echo '</div>';
        }
        if ($product->get_short_description()) {
            echo '<div style="margin-top:14px; background:var(--pwf-slate-50); padding:16px; border-radius:var(--pwf-radius-sm); border:1px solid var(--pwf-slate-200);">';
            echo '<strong style="color:var(--pwf-slate-800);">' . esc_html(pwf_t('Short description')) . ':</strong>';
            echo '<div style="margin-top:8px; color:var(--pwf-slate-700); line-height:1.7;">' . wp_kses_post(wpautop($product->get_short_description())) . '</div>';
            echo '</div>';
        }

        echo '<div style="margin-top:16px; font-size:12.5px; color:var(--pwf-slate-500); background:#f1f5f9; padding:10px 14px; border-radius:var(--pwf-radius-sm);">';
        echo 'ℹ ' . esc_html(pwf_t('Product editing is restricted to the content stage or management.'));
        echo '</div>';
    }
    echo '</section>';

    // Card 2: Product Images & Reference Media
    echo '<section class="pwf-card" style="margin-bottom:24px;">';
    echo '<h2><span class="dashicons dashicons-camera" style="color:#9333ea;"></span> ' . esc_html(pwf_t('Product Images & Media')) . '</h2>';

    $can_images = PWF_Workflow_Manager::can_edit($id, 'images');
    $social_view = !current_user_can('manage_workflow') && !current_user_can('assign_tasks') && !PWF_Permission_Manager::stage($id, 'photography', 'upload_product_images') && !PWF_Permission_Manager::stage($id, 'content', 'edit_product_content') && !PWF_Permission_Manager::stage($id, 'review', 'review_product') && (int) $row->created_by !== get_current_user_id();
    $images = $social_view ? array() : get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $id, 'post_mime_type' => 'image', 'numberposts' => -1, 'fields' => 'ids'));
    $images = array_filter(array_unique(array_merge($images, array($product->get_image_id()), $product->get_gallery_image_ids())));

    if ($can_images) { PWF_Admin::form_start('images', $id); }
    echo '<div class="pwf-gallery-container">';
    foreach ($images as $image_id) {
        $is_main = (int) $product->get_image_id() === (int) $image_id;
        $in_gallery = in_array($image_id, $product->get_gallery_image_ids(), true);
        echo '<div class="pwf-gallery-item ' . ($is_main ? 'is-main' : '') . '">';
        if ($is_main) {
            echo '<span class="pwf-gallery-badge-main">★ ' . esc_html(pwf_t('Main image')) . '</span>';
        }
        echo '<a href="' . esc_url(wp_get_attachment_url($image_id)) . '" target="_blank">' . wp_kses_post(wp_get_attachment_image($image_id, 'medium')) . '</a>';
        if ($can_images) {
            echo '<div class="pwf-gallery-meta">';
            echo '<label style="cursor:pointer;"><input type="radio" name="main_image" value="' . absint($image_id) . '" ' . checked($is_main, true, false) . '> ' . esc_html(pwf_t('Main image')) . '</label>';
            echo '<label style="cursor:pointer;"><input type="checkbox" name="gallery[]" value="' . absint($image_id) . '" ' . checked($in_gallery, true, false) . '> ' . esc_html(pwf_t('Show in gallery')) . '</label>';
            echo '</div>';
        }
        echo '</div>';
    }
    if (!$images) {
        echo '<div style="grid-column: 1 / -1; padding:24px; text-align:center; background:var(--pwf-slate-50); border:1.5px dashed var(--pwf-slate-200); border-radius:var(--pwf-radius-sm); color:var(--pwf-slate-400); font-size:13px;">';
        echo '<span class="dashicons dashicons-format-image" style="font-size:32px; width:32px; height:32px; margin-bottom:8px; opacity:0.5;"></span><br>';
        echo esc_html(pwf_t('No images uploaded yet.'));
        echo '</div>';
    }
    echo '</div>';

    if ($can_images && !empty($images)) {
        echo '<div style="margin-top:16px;">';
        echo '<button type="submit" class="button pwf-btn-primary"><span class="dashicons dashicons-images-alt2" style="margin-top:2px;"></span> ' . esc_html(pwf_t('Save image selection')) . '</button>';
        echo '</div>';
        echo '</form>';
    }

    // Modern Upload Box
    if ($can_images || ($row->current_status === 'new_product' && (int) $row->created_by === get_current_user_id() && current_user_can('create_product_request'))) {
        echo '<div class="pwf-attachment-upload-box" style="margin-top:20px;">';
        PWF_Admin::form_start('upload', $id, true);
        echo '<div style="font-weight:700; font-size:13px; color:var(--pwf-slate-800); margin-bottom:8px; display:flex; align-items:center; gap:6px;">';
        echo '<span class="dashicons dashicons-upload" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Upload new image'));
        echo '</div>';
        echo '<div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">';
        echo '<input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="pwf-field-control" style="flex:1; min-width:240px;" required>';
        echo '<button type="submit" class="button pwf-btn-secondary" style="height:42px;"><span class="dashicons dashicons-cloud-upload" style="margin-top:2px;"></span> ' . esc_html(pwf_t('Upload image')) . '</button>';
        echo '</div>';
        echo '<div class="pwf-field-hint" style="margin-top:6px;">' . esc_html(pwf_t('Upload JPEG, PNG or WebP')) . '</div>';
        echo '</form>';
        echo '</div>';
    }
    echo '</section>';

    // Card 3: Linked Tasks (Execution Work Orders for this Product)
    $linked_tasks = array();
    if (class_exists('PWF_Task_Manager')) {
        $t_result = PWF_Task_Manager::list(array('product_id' => $id, 'per_page' => 50));
        $linked_tasks = $t_result['rows'] ?? array();
    }
    echo '<section class="pwf-card" style="margin-bottom:24px;">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">';
    echo '<div>';
    echo '<h2 style="margin:0;"><span class="dashicons dashicons-clipboard" style="color:#8b5cf6;"></span> ' . esc_html(pwf_t('Linked Execution Tasks')) . '</h2>';
    echo '<div style="font-size:12px; color:var(--pwf-slate-500); margin-top:4px;">' . esc_html(pwf_t('Specific work orders with deadlines and deliverables assigned to team members for this product.')) . '</div>';
    echo '</div>';
    if (current_user_can('assign_tasks')) {
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf-new-task&product_id=' . $id)) . '" class="button pwf-btn-primary" style="font-size:12.5px;"><span class="dashicons dashicons-plus-alt2" style="font-size:14px; line-height:22px;"></span> ' . esc_html(pwf_t('+ Create Task for this Product')) . '</a>';
    }
    echo '</div>';

    if ($linked_tasks) {
        echo '<div class="pwf-table-container"><table class="widefat striped" style="margin-top:10px;">';
        echo '<thead><tr><th>' . esc_html(pwf_t('Task')) . '</th><th>' . esc_html(pwf_t('Type')) . '</th><th>' . esc_html(pwf_t('Priority')) . '</th><th>' . esc_html(pwf_t('Status')) . '</th><th>' . esc_html(pwf_t('Due')) . '</th><th></th></tr></thead><tbody>';
        foreach ($linked_tasks as $task) {
            $t_url = admin_url('admin.php?page=pwf-task&task_id=' . $task->task_id);
            $p_label = PWF_Task_Manager::priorities()[$task->priority] ?? $task->priority;
            $s_label = PWF_Task_Manager::statuses()[$task->status] ?? $task->status;
            echo '<tr>';
            echo '<td><strong>#' . absint($task->task_id) . ' ' . esc_html($task->title) . '</strong></td>';
            echo '<td style="font-size:12px;">' . esc_html(PWF_Task_Manager::types()[$task->task_type]['label'] ?? $task->task_type) . '</td>';
            echo '<td><span class="pwf-badge pwf-badge-role">' . esc_html($p_label) . '</span></td>';
            echo '<td><span class="pwf-badge pwf-badge-status">' . esc_html($s_label) . '</span></td>';
            echo '<td style="font-size:12px;">' . esc_html($task->due_date ?: '—') . '</td>';
            echo '<td><a class="button button-secondary" href="' . esc_url($t_url) . '">' . esc_html(pwf_t('Open task')) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<div style="padding:16px; background:var(--pwf-slate-50); border:1px solid var(--pwf-slate-200); border-radius:var(--pwf-radius-sm); color:var(--pwf-slate-500); font-size:13px; font-style:italic;">';
        echo esc_html(pwf_t('No execution tasks linked to this product yet.'));
        echo '</div>';
    }
    echo '</section>';

    // Card 4: Workflow History
    if (current_user_can('view_workflow_history')) {
        $history_page = max(1, absint($_GET['history_page'] ?? 1));
        echo '<section class="pwf-card">';
        echo '<h2><span class="dashicons dashicons-backup" style="color:var(--pwf-slate-600);"></span> ' . esc_html(pwf_t('Workflow history')) . '</h2>';
        echo '<div class="pwf-table-container">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:28%;">' . esc_html(pwf_t('Time / person')) . '</th>';
        echo '<th style="width:30%;">' . esc_html(pwf_t('Event')) . '</th>';
        echo '<th style="width:42%;">' . esc_html(pwf_t('Note')) . '</th>';
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

    echo '</div>'; // main col


    // ── RIGHT COLUMN: SIDEBAR ─────────────────────────────────────────────
    echo '<aside>';

    // Sidebar Card 1: Available Actions for Current Stage
    echo '<section class="pwf-card" style="border-top:4px solid var(--pwf-primary); margin-bottom:24px;">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">';
    echo '<h2 style="margin:0; font-size:15px;"><span class="dashicons dashicons-controls-forward" style="color:var(--pwf-primary);"></span> ' . esc_html(pwf_t('Current stage action')) . '</h2>';
    echo '<span class="pwf-badge ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span>';
    echo '</div>';

    $count = 0;
    foreach (PWF_Workflow_Manager::transitions()[$row->current_status] as $to => $edge) {
        if (!PWF_Workflow_Manager::allowed($id, $row->current_status, $to)) { continue; }
        $count++;
        PWF_Admin::form_start('transition', $id);
        echo '<input type="hidden" name="expected" value="' . esc_attr($row->current_status) . '">';
        echo '<input type="hidden" name="to" value="' . esc_attr($to) . '">';
        $rejection = $row->current_status === 'waiting_review' && in_array($to, array('waiting_photography', 'waiting_content'), true);
        echo '<div style="margin-bottom:12px;">';
        echo '<label class="pwf-field-label"><strong>' . ($rejection ? pwf_t('Rejection reason (required)') : pwf_t('Note (optional)')) . '</strong></label>';
        echo '<textarea name="note" rows="2" class="pwf-field-control" ' . ($rejection ? 'required' : '') . ' placeholder="' . esc_attr($rejection ? pwf_t('State the reason for returning work...') : pwf_t('Add note for this transition...')) . '"></textarea>';
        echo '</div>';

        $btn_text = $to === 'published' ? pwf_t('Publish WooCommerce product') : sprintf(pwf_t('Move to %s'), PWF_Workflow_Manager::states()[$to]);
        echo '<button type="submit" class="button ' . ($rejection ? 'pwf-btn-secondary' : 'pwf-btn-primary') . '" style="width:100%; justify-content:center; padding:10px 14px; font-weight:700; font-size:13.5px;">';
        echo '<span class="dashicons ' . ($rejection ? 'dashicons-undo' : 'dashicons-arrow-left-alt') . '" style="margin-top:2px;"></span> ';
        echo esc_html($btn_text);
        echo '</button>';
        echo '</form>';
        echo '<div style="margin:12px 0;"></div>';
    }
    if (!$count) {
        echo '<div style="padding:14px; background:var(--pwf-slate-50); border:1px solid var(--pwf-slate-200); border-radius:var(--pwf-radius-sm); color:var(--pwf-slate-400); font-size:12.5px; font-style:italic; text-align:center;">';
        echo esc_html(pwf_t('No actions available for you at this stage.'));
        echo '</div>';
    }
    echo '</section>';

    // Sidebar Card 2: Product Stage Assignees (Pipeline Team)
    echo '<section class="pwf-card">';
    echo '<h2 style="margin-bottom:4px;"><span class="dashicons dashicons-admin-users" style="color:var(--pwf-secondary);"></span> ' . esc_html(pwf_t('Product Stage Assignees')) . '</h2>';
    echo '<div style="font-size:12px; color:var(--pwf-slate-500); margin-bottom:16px; line-height:1.5;">' . esc_html(pwf_t('Assign team members responsible for preparing this product at each stage (photography, content, review).')) . '</div>';

    $stage_icons = array(
        'photography' => '📸',
        'content'     => '📝',
        'review'      => '🔍',
        'social'      => '📢',
        'programming' => '💻',
    );

    foreach (PWF_Permission_Manager::stage_caps() as $stage => $cap) {
        $assignment = PWF_Assignment_Manager::get($id, $stage);
        $user = $assignment ? get_userdata($assignment->user_id) : null;
        $icon = $stage_icons[$stage] ?? '🎯';

        echo '<div class="pwf-stage-item">';
        echo '<div class="pwf-stage-header">';
        echo '<div class="pwf-stage-name">' . esc_html($icon . ' ' . PWF_I18n::stage($stage)) . '</div>';
        if ($assignment && $assignment->completed_at) {
            echo '<span class="pwf-badge pwf-badge-success">✓ ' . esc_html(pwf_t('Completed')) . '</span>';
        } elseif ($assignment) {
            echo '<span class="pwf-badge pwf-badge-role">' . esc_html(pwf_t('Active')) . '</span>';
        } else {
            echo '<span class="pwf-badge pwf-badge-default">' . esc_html(pwf_t('Unassigned')) . '</span>';
        }
        echo '</div>';

        echo '<div class="pwf-stage-details">';
        echo '<div><b>' . esc_html(pwf_t('Assign user')) . ':</b> ' . esc_html($user ? $user->display_name : pwf_t('Unassigned')) . '</div>';
        if ($assignment && $assignment->due_date) {
            echo '<div><b>' . esc_html(pwf_t('Due date (optional)')) . ':</b> ' . esc_html($assignment->due_date) . '</div>';
        }
        echo '</div>';

        if (current_user_can('assign_tasks') && $row->current_status !== 'completed') {
            echo '<details class="pwf-stage-edit">';
            echo '<summary>' . esc_html(pwf_t('Change / assign user')) . '</summary>';
            echo '<div style="margin-top:10px;">';
            PWF_Admin::form_start('assign', $id);
            echo '<input type="hidden" name="stage" value="' . esc_attr($stage) . '">';
            echo '<div class="pwf-field-group" style="margin-bottom:8px;">';
            echo '<select name="user_id" class="pwf-field-control" required>';
            echo '<option value="">' . esc_html(pwf_t('Choose user')) . '</option>';
            $users = get_users(array('capability' => $cap, 'orderby' => 'display_name'));
            foreach ($users as $candidate) {
                echo '<option value="' . absint($candidate->ID) . '" ' . selected($assignment ? $assignment->user_id : 0, $candidate->ID, false) . '>' . esc_html($candidate->display_name) . '</option>';
            }
            echo '</select>';
            echo '</div>';
            echo '<div class="pwf-field-group" style="margin-bottom:8px;">';
            echo '<input type="date" name="due_date" class="pwf-field-control" value="' . esc_attr($assignment ? $assignment->due_date : '') . '">';
            echo '</div>';
            echo '<button type="submit" class="button pwf-btn-secondary" style="width:100%; justify-content:center;">' . sprintf(esc_html(pwf_t('Save %s assignment')), esc_html(PWF_I18n::stage($stage))) . '</button>';
            echo '</form>';
            echo '</div>';
            echo '</details>';
        }
        echo '</div>'; // .pwf-stage-item
    }
    echo '</section>';

    echo '</aside>';
    echo '</div>'; // .pwf-grid
    echo '</div>'; // .wrap
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
