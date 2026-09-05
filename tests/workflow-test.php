<?php
/** Isolated behavioral regression tests. Run: php tests/workflow-test.php */
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
define('PWF_DIR', dirname(__DIR__) . '/');
function get_user_meta($id, $key, $single = false) { return ''; }
function get_user_locale() { return 'en_US'; }
require_once PWF_DIR . 'includes/class-i18n.php';
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });

class WP_Error {
    public function __construct(public $code, public $message, public $data = array()) {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return sanitize_text_field($value); }
function wp_kses_post($value) { return strip_tags((string) $value, '<p><strong><em>'); }
function wp_strip_all_tags($value) { return strip_tags($value); }
function current_time($format, $gmt = false) { return $format === 'Y-m-d' ? '2026-09-05' : '2026-09-05 12:00:00'; }
function get_current_user_id() { return $GLOBALS['actor']; }
function user_can($id, $cap) { return in_array($cap, $GLOBALS['caps'][$id] ?? array(), true); }
function current_user_can($cap) { return user_can(get_current_user_id(), $cap); }
function get_post_type($id) { return isset($GLOBALS['products'][$id]) ? 'product' : false; }
function get_post_status($id) { return $GLOBALS['products'][$id]->status ?? false; }
function wc_get_product($id) { return isset($GLOBALS['products'][$id]) ? clone $GLOBALS['products'][$id] : false; }
function wp_attachment_is_image($id) { return isset($GLOBALS['images'][$id]); }
function wp_get_post_parent_id($id) { return $GLOBALS['images'][$id] ?? 0; }
function wc_format_decimal($value) { return is_numeric($value) ? (string) $value : ''; }
function term_exists($id, $taxonomy) { return in_array($id, array(1, 2, 3), true); }
function clean_post_cache($id) {}
function wc_delete_product_transients($id) {}
function wp_delete_post($id, $force) { unset($GLOBALS['products'][$id]); }
function add_filter($name, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$name][] = $callback; }
function add_action($name, $callback, $priority = 10, $args = 1) { add_filter($name, $callback, $priority, $args); }
function apply_filters($name, $value, ...$args) {
    foreach ($GLOBALS['hooks'][$name] ?? array() as $callback) { $value = $callback($value, ...$args); }
    return $value;
}
function do_action($name, ...$args) { foreach ($GLOBALS['hooks'][$name] ?? array() as $callback) { $callback(...$args); } }

class WC_Product_Simple {
    public $id = 0;
    public $status = 'draft';
    public $fields = array('name' => '', 'sku' => '', 'description' => '', 'short_description' => '', 'regular_price' => '', 'category_ids' => array(), 'tag_ids' => array(), 'image_id' => 0, 'gallery_image_ids' => array(), 'attributes' => array());
    public function __call($name, $args) {
        $key = substr($name, 4);
        if (str_starts_with($name, 'set_')) { $this->fields[$key] = $args[0]; return; }
        if (str_starts_with($name, 'get_')) { return $this->fields[$key] ?? ''; }
        throw new RuntimeException('Unknown product call ' . $name);
    }
    public function is_type($type) { return $type === 'simple'; }
    public function set_status($status) { $this->status = $status; }
    public function get_status() { return $this->status; }
    public function update_meta_data($key, $value) { $this->fields[$key] = $value; }
    public function save() {
        if (!$this->id) { $this->id = count($GLOBALS['products']) + 100; }
        $GLOBALS['products'][$this->id] = clone $this;
        return $this->id;
    }
}
class WC_Product_Attribute {
    public function set_name($v) {}
    public function set_options($v) {}
    public function set_visible($v) {}
}

class FakeDB {
    public $prefix = 'wp_';
    public $tables = array('wp_pwf_workflows' => array(), 'wp_pwf_assignments' => array(), 'wp_pwf_history' => array());
    public $fail_history = false;
    public $locked = false;
    private $snapshot;
    public function prepare($sql, ...$args) { return array($sql, $args); }
    public function query($sql) {
        if ($sql === 'START TRANSACTION') { $this->snapshot = serialize(array($this->tables, $GLOBALS['products'])); }
        if ($sql === 'ROLLBACK') { list($this->tables, $GLOBALS['products']) = unserialize($this->snapshot); }
        return 1;
    }
    public function get_var($query) { return str_contains($query[0], 'GET_LOCK') && $this->locked ? 0 : 1; }
    public function get_row($query) { return $this->get_results($query)[0] ?? null; }
    public function get_results($query) {
        list($sql, $args) = $query;
        preg_match('/FROM (\w+)/', $sql, $matches);
        $rows = array_filter($this->tables[$matches[1]], function ($row) use ($sql, $args) {
            return (int) $row['product_id'] === (int) $args[0] && (!str_contains($sql, 'AND stage=') || $row['stage'] === $args[1]);
        });
        return array_map(fn($row) => (object) $row, array_values($rows));
    }
    public function insert($table, $data) {
        if ($table === 'wp_pwf_history' && $this->fail_history) { return false; }
        if ($table === 'wp_pwf_workflows' && array_filter($this->tables[$table], fn($r) => $r['product_id'] === $data['product_id'])) { return false; }
        if ($table === 'wp_pwf_assignments') { $data['assignment_id'] = count($this->tables[$table]) + 1; }
        if ($table === 'wp_pwf_history') { $data['history_id'] = count($this->tables[$table]) + 1; }
        $this->tables[$table][] = $data;
        return 1;
    }
    public function update($table, $data, $where) {
        $count = 0;
        foreach ($this->tables[$table] as &$row) {
            $match = true;
            foreach ($where as $key => $value) { if ($row[$key] != $value) { $match = false; } }
            if ($match) { $row = array_merge($row, $data); $count++; }
        }
        return $count;
    }
}

$GLOBALS['products'] = array();
$GLOBALS['hooks'] = array();
$GLOBALS['images'] = array();
$GLOBALS['actor'] = 1;
$common = array('view_assigned_products', 'view_workflow_history');
$GLOBALS['caps'] = array(
    1 => array_merge($common, array('manage_workflow', 'assign_tasks', 'create_product_request', 'upload_product_images', 'complete_photography_task', 'edit_product_content', 'complete_content_task', 'review_product', 'publish_product', 'complete_social_task')),
    2 => array_merge($common, array('create_product_request')),
    3 => array_merge($common, array('upload_product_images', 'complete_photography_task')),
    4 => array_merge($common, array('edit_product_content', 'complete_content_task')),
    5 => array_merge($common, array('review_product')),
    6 => array_merge($common, array('complete_social_task')),
    7 => array_merge($common, array('upload_product_images', 'complete_photography_task')),
);
$wpdb = new FakeDB();
foreach (array('permission', 'history', 'assignment', 'notification', 'workflow') as $module) { require __DIR__ . '/../includes/class-' . $module . '-manager.php'; }
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    $checks++;
    echo "PASS: $label\n";
}
function actor($id) { $GLOBALS['actor'] = $id; }
function move_to($id, $target, $note = '') { return PWF_Workflow_Manager::transition($id, $target, PWF_Workflow_Manager::get($id)->current_status, $note); }

actor(3);
check(is_wp_error(PWF_Workflow_Manager::create(array('name' => 'Denied'))), 'photographer cannot create products');
actor(2);
check(is_wp_error(PWF_Workflow_Manager::create(array('name' => ''))), 'empty product name rejected');
$row = PWF_Workflow_Manager::create(array('name' => 'Test product', 'sku' => 'TEST-01'));
check(!is_wp_error($row) && $row->current_status === 'new_product', 'factory creates initialized draft');
$id = $row->product_id;
check(get_post_status($id) === 'draft', 'WooCommerce product remains draft');
check(PWF_Permission_Manager::can_view($id), 'factory can view own product');
check(is_wp_error(move_to($id, 'waiting_photography')), 'factory cannot advance manager queue');
check(is_wp_error(PWF_Assignment_Manager::assign($id, 'photography', 3)), 'factory cannot assign tasks');
actor(1);
check(is_wp_error(move_to($id, 'approved')), 'stage skipping rejected');
check(is_wp_error(move_to($id, 'waiting_photography')), 'queue requires an assignee');
check(is_wp_error(PWF_Assignment_Manager::assign($id, 'photography', 4)), 'wrong-capability assignee rejected');
check(is_wp_error(PWF_Assignment_Manager::assign($id, 'photography', 3, '2026-02-31')), 'invalid due date rejected');
foreach (array('photography' => 3, 'content' => 4, 'review' => 5, 'social' => 6) as $stage => $user) {
    check(!is_wp_error(PWF_Assignment_Manager::assign($id, $stage, $user, '2026-09-06')), $stage . ' assignment saved');
}
actor(6);
check(!PWF_Permission_Manager::can_view($id), 'social cannot view before handoff');
actor(1);
check(!is_wp_error(move_to($id, 'waiting_photography')), 'manager starts photography');
actor(7);
check(!PWF_Permission_Manager::can_view($id), 'unassigned photographer cannot view');
check(is_wp_error(move_to($id, 'photography_completed')), 'unassigned photographer cannot complete');
check(is_wp_error(PWF_Workflow_Manager::save_images($id, 200, array())), 'unassigned photographer cannot change images');
actor(3);
check(is_wp_error(move_to($id, 'photography_completed')), 'photography requires main image');
$GLOBALS['images'][200] = $id;
$GLOBALS['images'][201] = $id + 1;
check(is_wp_error(PWF_Workflow_Manager::save_images($id, 201, array())), 'foreign product attachment rejected');
check(!is_wp_error(PWF_Workflow_Manager::save_images($id, 200, array(200))), 'assigned photographer saves own image');
check(!is_wp_error(move_to($id, 'photography_completed')), 'photographer completes stage');
check(PWF_Assignment_Manager::get($id, 'photography')->completed_at !== null, 'photography completion timestamp recorded');
check(is_wp_error(PWF_Workflow_Manager::save_images($id, 200, array())), 'images locked after photography');
actor(1);
check(!is_wp_error(move_to($id, 'waiting_content')), 'manager starts content');
actor(4);
check(is_wp_error(move_to($id, 'content_completed')), 'incomplete content cannot finish');
check(!is_wp_error(PWF_Workflow_Manager::save_content($id, array('description' => '<p>Details</p>', 'regular_price' => '0', 'category_ids' => array(1), 'status' => 'publish', 'image_id' => 201, 'unknown_meta' => 'bad'))), 'valid content including zero price saves');
check(get_post_status($id) === 'draft' && wc_get_product($id)->get_image_id() === 200, 'content allowlist blocks status and image changes');
check(is_wp_error(PWF_Workflow_Manager::save_content($id, array('regular_price' => '-1'))), 'negative price rejected');
check(is_wp_error(PWF_Workflow_Manager::save_content($id, array('category_ids' => array(999)))), 'unknown taxonomy term rejected');
check(!is_wp_error(move_to($id, 'content_completed')), 'content worker completes stage');
actor(1);
check(!is_wp_error(move_to($id, 'waiting_review')), 'manager submits review');
actor(5);
check(is_wp_error(move_to($id, 'waiting_content')), 'review rejection requires reason');
check(!is_wp_error(move_to($id, 'waiting_content', 'Correct the description')), 'reviewer returns content with reason');
check(PWF_Assignment_Manager::get($id, 'content')->completed_at === null, 'rejection reopens assigned task');
actor(4);
check(!is_wp_error(move_to($id, 'content_completed')), 'returned task completes again');
actor(1);
check(!is_wp_error(move_to($id, 'waiting_review')), 'returned product resubmitted');
actor(5);
check(!is_wp_error(move_to($id, 'approved')), 'reviewer approves complete product');
check(is_wp_error(move_to($id, 'published')), 'reviewer cannot publish without capability');
actor(1);
$wpdb->fail_history = true;
check(is_wp_error(move_to($id, 'published')), 'audit failure rejects transition');
check(PWF_Workflow_Manager::get($id)->current_status === 'approved' && get_post_status($id) === 'draft', 'transaction rollback restores state and product after audit failure');
$wpdb->fail_history = false;
$wpdb->locked = true;
check(is_wp_error(move_to($id, 'published')), 'busy product lock rejects mutation');
$wpdb->locked = false;
check(is_wp_error(PWF_Workflow_Manager::transition($id, 'published', 'waiting_review')), 'stale expected state rejected');
PWF_Workflow_Manager::boot();
PWF_Permission_Manager::boot();
$native = apply_filters('wp_insert_post_data', array('post_type' => 'product', 'post_status' => 'publish'), array('ID' => $id));
check($native['post_status'] === 'draft', 'native publish bypass blocked');
$native = apply_filters('wp_insert_post_data', array('post_type' => 'product', 'post_status' => 'future'), array('ID' => $id));
check($native['post_status'] === 'draft', 'native scheduled publish bypass blocked');
do_action('woocommerce_update_product', $id);
check(PWF_Workflow_Manager::get($id)->current_status === 'waiting_review', 'native WooCommerce edit invalidates approval');
actor(5);
check(!is_wp_error(move_to($id, 'approved')), 'reviewer reapproves edited product');
actor(1);
check(!is_wp_error(move_to($id, 'published')), 'publisher publishes');
check(get_post_status($id) === 'publish', 'publication updates WooCommerce');
check(PWF_Workflow_Manager::get($id)->current_status === 'published', 'publication is not internal completion');
check(!is_wp_error(move_to($id, 'ready_for_social')), 'manual social handoff');
actor(6);
check(PWF_Permission_Manager::can_view($id), 'social sees product after handoff');
check(is_wp_error(PWF_Workflow_Manager::save_content($id, array('name' => 'No'))), 'social cannot edit content');
check(!is_wp_error(move_to($id, 'completed')), 'social completes workflow');
check(get_post_status($id) === 'publish', 'workflow completion preserves publication');
check(is_wp_error(move_to($id, 'new_product')), 'completed workflow cannot restart arbitrarily');
check(apply_filters('map_meta_cap', array('edit_products'), 'edit_post', 3, array($id)) === array('do_not_allow'), 'native product edit denied for worker');
check(apply_filters('map_meta_cap', array('edit_products'), 'edit_post', 1, array($id)) === array('edit_products'), 'manager retains native edit capabilities');
actor(1);
check(is_wp_error(PWF_Assignment_Manager::assign($id, 'social', 6)), 'completed workflow cannot be reassigned');
check(count(PWF_History_Manager::recent($id)) >= 18, 'workflow and assignment events recorded');
$GLOBALS['hooks']['pwf_workflow_event'][] = function () { throw new RuntimeException('Notification offline'); };
PWF_Notification_Manager::dispatch($id, array('event' => 'test'));
check(true, 'notification exception isolated');
echo "\n$checks behavioral checks passed.\n";
