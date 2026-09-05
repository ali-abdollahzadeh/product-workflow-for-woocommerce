<?php
defined('ABSPATH') || exit;

function pwf_my_tasks() {
    if (!current_user_can('view_assigned_products')) { wp_die(pwf_t('Access denied.')); }
    echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';
    
    // Modern Hero Banner for My Tasks
    echo '<div class="pwf-hero">';
    echo '<div class="pwf-hero-title">';
    echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-id-alt"></span></div>';
    echo '<div>';
    echo '<h1>' . esc_html(pwf_t('My Tasks')) . '</h1>';
    echo '<p>' . esc_html(pwf_t('Your assigned work that is ready for action, plus new product requests you created.')) . '</p>';
    echo '</div>';
    echo '</div>';
    echo '<div class="pwf-hero-actions">';
    if (current_user_can('create_product_request')) {
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf-new')) . '" class="button pwf-btn-primary"><span class="dashicons dashicons-plus-alt2"></span> ' . esc_html(pwf_t('New Product')) . '</a>';
    }
    echo '</div>';
    echo '</div>';

    PWF_Admin::notice();
    PWF_Admin::table(true);
    echo '</div>';
}
