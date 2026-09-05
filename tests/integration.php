<?php
/** Run only against a disposable database named pwf_test. Usage: php tests/integration.php /path/to/wp-load.php */
if (PHP_SAPI !== 'cli' || empty($argv[1])) { exit("CLI only. Supply a disposable WordPress wp-load.php path.\n"); }
require $argv[1];
if (DB_NAME !== 'pwf_test') { exit("Refusing to run outside the disposable pwf_test database.\n"); }
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once PWF_DIR . 'admin/dashboard.php';
require_once PWF_DIR . 'admin/user-tasks.php';
require_once PWF_DIR . 'admin/product-workflow-page.php';

$checks = 0;
function verify($condition, $label, $result = null) {
    global $checks;
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $label . (is_wp_error($result) ? ' — ' . $result->get_error_message() : '') . "\n");
        exit(1);
    }
    $checks++;
    echo 'PASS: ' . $label . "\n";
}
function advance($id, $to, $note = '') { return PWF_Workflow_Manager::transition($id, $to, PWF_Workflow_Manager::get($id)->current_status, $note); }
function success($result, $label) { verify(!is_wp_error($result), $label, $result); return $result; }

pwf_install();
pwf_install();
global $wpdb;
foreach (array('workflows', 'assignments', 'history') as $table) {
    $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $wpdb->prefix . 'pwf_' . $table));
    verify($status && $status->Engine === 'InnoDB', $table . ' schema exists and uses InnoDB');
}
$stamp = substr(md5(microtime(true)), 0, 8);
$users = array();
foreach (array('manager' => 'administrator', 'factory' => 'pwf_factory', 'photo' => 'pwf_photographer', 'other_photo' => 'pwf_photographer', 'content' => 'pwf_content_manager', 'review' => 'pwf_reviewer', 'social' => 'pwf_social') as $name => $role) {
    $users[$name] = success(wp_insert_user(array('user_login' => 'pwf_' . $name . '_' . $stamp, 'user_pass' => wp_generate_password(), 'role' => $role)), $name . ' user created');
    update_user_meta($users[$name], 'locale', 'en_US');
}
wp_set_current_user($users['factory']);
$row = success(PWF_Workflow_Manager::create(array('name' => 'Integration product ' . $stamp, 'sku' => 'PWF-' . $stamp)), 'factory creates real WooCommerce draft');
$id = (int) $row->product_id;
verify(get_post_status($id) === 'draft', 'real product status is draft');
verify(PWF_Permission_Manager::can_view($id), 'factory sees own request');
verify(!current_user_can('edit_post', $id), 'factory cannot enter broad native editing');
verify(is_wp_error(advance($id, 'waiting_photography')), 'factory cannot advance queue');
wp_set_current_user($users['manager']);
foreach (array('photography' => 'photo', 'content' => 'content', 'review' => 'review', 'social' => 'social') as $stage => $name) {
    success(PWF_Assignment_Manager::assign($id, $stage, $users[$name], '2026-09-10'), 'assign ' . $stage);
}
success(advance($id, 'waiting_photography'), 'start photography');
wp_set_current_user($users['other_photo']);
verify(!PWF_Permission_Manager::can_view($id), 'unassigned product remains hidden');
verify(is_wp_error(PWF_Workflow_Manager::save_images($id, 0, array())), 'unassigned image mutation rejected');
wp_set_current_user($users['photo']);
verify(is_wp_error(advance($id, 'photography_completed')), 'image required before completion');
$image = imagecreatetruecolor(32, 32);
imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 170));
ob_start(); imagepng($image); $bytes = ob_get_clean();
$upload = wp_upload_bits('pwf-' . $stamp . '.png', null, $bytes);
verify(!$upload['error'], 'fixture image written to uploads');
$attachment = success(wp_insert_attachment(array('post_mime_type' => 'image/png', 'post_title' => 'Workflow test image', 'post_status' => 'inherit'), $upload['file'], $id, true), 'real attachment created');
wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $upload['file']));
success(PWF_Workflow_Manager::save_images($id, $attachment, array()), 'main image assigned through WooCommerce CRUD');
success(advance($id, 'photography_completed'), 'complete photography');
wp_set_current_user($users['manager']);
success(advance($id, 'waiting_content'), 'start content');
wp_set_current_user($users['content']);
$term = wp_insert_term('Workflow test ' . $stamp, 'product_cat');
verify(!is_wp_error($term), 'category created');
success(PWF_Workflow_Manager::save_content($id, array('description' => '<p>Approved description</p>', 'regular_price' => '12.50', 'category_ids' => array($term['term_id']), 'attributes' => array('Color' => array('Blue')), 'seo_title' => 'Test SEO', 'status' => 'publish')), 'content and attributes saved');
$product = wc_get_product($id);
verify($product->get_regular_price() === '12.50' && $product->get_status() === 'draft' && count($product->get_attributes()) === 1, 'CRUD persisted allowlisted values only');
success(advance($id, 'content_completed'), 'complete content');
wp_set_current_user($users['manager']);
success(advance($id, 'waiting_review'), 'submit review');
wp_set_current_user($users['review']);
verify(is_wp_error(advance($id, 'waiting_content')), 'rejection without reason blocked');
success(advance($id, 'waiting_content', 'Improve copy'), 'review return accepted');
verify(PWF_Assignment_Manager::get($id, 'content')->completed_at === null, 'returned task reopened in database');
wp_set_current_user($users['content']);
success(advance($id, 'content_completed'), 'returned content completes');
wp_set_current_user($users['manager']);
success(advance($id, 'waiting_review'), 'resubmit review');
wp_set_current_user($users['review']);
success(advance($id, 'approved'), 'approve product');
verify(is_wp_error(advance($id, 'published')), 'reviewer publication blocked');
wp_set_current_user($users['manager']);
wp_update_post(array('ID' => $id, 'post_status' => 'publish'));
verify(get_post_status($id) === 'draft', 'native publication cannot bypass workflow');
$product = wc_get_product($id);
$product->set_name('Revised integration product ' . $stamp);
$product->save();
verify(PWF_Workflow_Manager::get($id)->current_status === 'waiting_review', 'native CRUD edit resets approval');
wp_set_current_user($users['review']);
success(advance($id, 'approved'), 'reapprove native edit');
wp_set_current_user($users['manager']);
// Force only the audit INSERT to fail; verify genuine InnoDB rollback, including WooCommerce writes.
$fail_audit = function ($query) use ($wpdb) {
    if (str_starts_with($query, 'INSERT INTO `' . $wpdb->prefix . 'pwf_history`')) { return 'INSERT INTO pwf_intentionally_missing_table (id) VALUES (1)'; }
    return $query;
};
add_filter('query', $fail_audit);
$wpdb->suppress_errors(true);
$failed = advance($id, 'published');
remove_filter('query', $fail_audit);
$wpdb->suppress_errors(false);
verify(is_wp_error($failed), 'audit insert failure aborts publication');
verify(PWF_Workflow_Manager::get($id)->current_status === 'approved' && get_post_status($id) === 'draft', 'real database rollback restores product and workflow');
success(advance($id, 'published'), 'publish with real WooCommerce');
verify(get_post_status($id) === 'publish', 'published product persisted');
wp_set_current_user($users['social']);
verify(!PWF_Permission_Manager::can_view($id), 'social remains hidden until explicit handoff');
wp_set_current_user($users['manager']);
success(advance($id, 'ready_for_social'), 'manual social handoff');
wp_set_current_user($users['social']);
verify(PWF_Permission_Manager::can_view($id), 'social access opens at handoff');
$_GET = array('product_id' => $id);
ob_start(); pwf_product_page(); $html = ob_get_clean();
verify(str_contains($html, 'Product images') && !str_contains($html, 'name="regular_price"') && !str_contains($html, 'name="image"'), 'social detail renders read-only assets');
ob_start(); pwf_my_tasks(); $tasks = ob_get_clean();
verify(str_contains($tasks, 'Revised integration product'), 'My Tasks SQL includes active social task');
success(advance($id, 'completed'), 'social completes workflow');
ob_start(); pwf_my_tasks(); $tasks = ob_get_clean();
verify(!str_contains($tasks, 'Revised integration product'), 'completed task leaves active queue');
// REST executes actual registered route permission callbacks and service checks.
wp_set_current_user(0);
$request = new WP_REST_Request('GET', '/product-workflow/v1/products/' . $id);
$response = rest_do_request($request);
verify($response->get_status() === 403, 'anonymous REST access rejected');
wp_set_current_user($users['other_photo']);
$response = rest_do_request(new WP_REST_Request('GET', '/product-workflow/v1/products/' . $id));
verify($response->get_status() === 403, 'unassigned REST access rejected');
wp_set_current_user($users['social']);
$response = rest_do_request(new WP_REST_Request('GET', '/product-workflow/v1/products/' . $id));
verify($response->get_status() === 200, 'assigned REST read succeeds');
$request = new WP_REST_Request('POST', '/product-workflow/v1/products/' . $id . '/content');
$request->set_param('name', 'Unauthorized');
verify(rest_do_request($request)->get_status() >= 400, 'social REST content mutation rejected');
wp_set_current_user($users['manager']);
ob_start(); PWF_Admin::dashboard(); $dashboard = ob_get_clean();
verify(str_contains($dashboard, 'Revised integration product'), 'dashboard filters and table render with real database');
verify(count(PWF_History_Manager::recent($id)) >= 18, 'real audit trail persists');
wp_update_post(array('ID' => $id, 'post_status' => 'draft'));
verify(get_post_status($id) === 'draft' && PWF_Workflow_Manager::get($id)->current_status === 'waiting_review', 'native withdrawal reopens review and unpublishes');
wp_set_current_user($users['social']);
verify(!PWF_Permission_Manager::can_view($id), 'native withdrawal revokes social handoff access');
wp_set_current_user($users['manager']);
foreach (array('fa_IR' => array('گردش کار محصول', 'محصول جدید', 'rtl'), 'it_IT' => array('Flusso di lavoro prodotti', 'Nuovo prodotto', 'ltr'), 'en_US' => array('Product Workflow', 'New Product', 'ltr')) as $locale => $expected) {
    update_user_meta($users['manager'], 'pwf_language', $locale);
    verify(pwf_t('Product Workflow') === $expected[0], $locale . ': translated heading');
    verify(PWF_Workflow_Manager::states()['new_product'] === $expected[1], $locale . ': translated state label');
    verify(str_contains(PWF_I18n::attributes(), 'dir="' . $expected[2] . '"'), $locale . ': correct reading direction');
    ob_start(); PWF_Admin::create_page(); $localized = ob_get_clean();
    verify(str_contains($localized, $expected[1]) && str_contains($localized, 'name="language"'), $locale . ': rendered form and language selector');
    $empty = PWF_Workflow_Manager::create(array('name' => ''));
    verify(is_wp_error($empty) && $empty->get_error_message() === pwf_t('Enter a product name.'), $locale . ': localized validation');
    verify(translate_user_role('Photographer') === pwf_t('Photographer'), $locale . ': native role label localized');
    $note = (object) array('event' => 'transition', 'note' => 'Keep this user note unchanged — یادداشت');
    verify(PWF_I18n::history_note($note) === $note->note, $locale . ': user-authored notes preserved');
}
update_user_meta($users['manager'], 'pwf_language', 'fa_IR');
wp_set_current_user($users['social']);
verify(PWF_I18n::locale() === 'en_US', 'language preferences are isolated per user');
wp_set_current_user($users['manager']);
update_user_meta($users['manager'], 'pwf_language', '../../invalid');
verify(PWF_I18n::locale() === 'en_US', 'invalid saved locale safely falls back');
delete_user_meta($users['manager'], 'pwf_language');
update_user_meta($users['manager'], 'locale', 'fa_IR');
verify(PWF_I18n::locale() === 'fa_IR', 'default language follows Persian WordPress user preference');
update_user_meta($users['manager'], 'locale', 'en_US');
echo "\n$checks WordPress/WooCommerce integration checks passed. Product ID: $id\n";
