<?php
/** Unit test for Telegram integration and Settings functionality */
if (PHP_SAPI !== 'cli') { exit; }

define('ABSPATH', dirname(__DIR__) . '/');
define('PWF_DIR', dirname(__DIR__) . '/');

$GLOBALS['options'] = array();
$GLOBALS['user_meta'] = array();
$GLOBALS['users'] = array();
$GLOBALS['http_requests'] = array();
$GLOBALS['hooks'] = array();

function get_option($key, $default = false) {
    return $GLOBALS['options'][$key] ?? $default;
}
function update_option($key, $value) {
    $GLOBALS['options'][$key] = $value;
    return true;
}
function get_user_meta($user_id, $key, $single = false) {
    return $GLOBALS['user_meta'][$user_id][$key] ?? '';
}
function update_user_meta($user_id, $key, $value) {
    $GLOBALS['user_meta'][$user_id][$key] = $value;
    return true;
}
function get_user_locale() { return 'en_US'; }
function get_current_user_id() { return 1; }
function current_user_can($cap) { return true; }
function current_time($format, $gmt = false) { return '2026-09-05 12:00:00'; }
function get_bloginfo($show = '') { return 'Test Site'; }
function sanitize_text_field($val) { return trim(strip_tags((string) $val)); }
function sanitize_key($val) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $val)); }
function absint($val) { return abs((int) $val); }
function esc_html($val) { return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8'); }
function esc_url($val) { return filter_var($val, FILTER_SANITIZE_URL); }
function admin_url($path = '') { return 'https://example.com/wp-admin/' . $path; }
function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['hooks'][$tag][] = $callback;
}
function do_action($tag, ...$args) {
    foreach ($GLOBALS['hooks'][$tag] ?? array() as $cb) {
        $cb(...$args);
    }
}
function apply_filters($tag, $value, ...$args) { return $value; }
function check_admin_referer($action = -1, $query_arg = '_wpnonce') { return 1; }
function get_rest_url($blog_id = null, $path = '') { return 'https://example.com/wp-json/' . ltrim($path, '/'); }
function wp_set_current_user($id) { $GLOBALS['actor'] = $id; }
$GLOBALS['transients'] = array();
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $expiration = 0) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); return true; }
function get_users($args = array()) {
    $matched = array();
    foreach ($GLOBALS['users'] as $u) {
        if (!empty($args['role']) && !in_array($args['role'], $u->roles, true)) {
            continue;
        }
        if (!empty($args['meta_key']) && !empty($args['meta_value'])) {
            if (get_user_meta($u->ID, $args['meta_key']) != $args['meta_value']) {
                continue;
            }
        } elseif (!empty($args['meta_key'])) {
            if (empty(get_user_meta($u->ID, $args['meta_key']))) {
                continue;
            }
        }
        $matched[] = $u;
    }
    return $matched;
}
function user_can($user, $cap) {
    $uid = is_object($user) ? $user->ID : $user;
    $u = $GLOBALS['users'][$uid] ?? null;
    if (!$u) return false;
    if (in_array('administrator', $u->roles, true)) return true;
    if (in_array('pwf_factory', $u->roles, true) && in_array($cap, array('create_product_request', 'view_assigned_products'), true)) return true;
    if (in_array('pwf_photographer', $u->roles, true) && in_array($cap, array('upload_product_images', 'view_assigned_products'), true)) return true;
    if (in_array('pwf_content_manager', $u->roles, true) && in_array($cap, array('edit_product_content', 'complete_content_task', 'view_assigned_products'), true)) return true;
    if (in_array('pwf_reviewer', $u->roles, true) && in_array($cap, array('review_product', 'view_assigned_products'), true)) return true;
    return false;
}
function wp_kses_post($val) { return $val; }
function sanitize_textarea_field($val) { return trim(strip_tags((string) $val)); }
function sanitize_file_name($val) { return $val; }
function wp_generate_password($len = 12, $special = true) { return 'pass'; }
function wp_upload_bits($name, $deprecated, $bits) { return array('file' => '/tmp/' . $name, 'url' => 'https://example.com/' . $name, 'error' => false); }
function wp_insert_attachment($attachment, $filename, $parent = 0) { return 100; }
function wp_safe_redirect($location, $status = 302) { return true; }
function wp_die($message = '', $title = '', $args = array()) { throw new RuntimeException($message); }
function wp_nonce_field($action = -1, $name = "_wpnonce", $referer = true, $echo = true) { return ''; }
function wp_unslash($val) { return $val; }
function selected($selected, $current = true, $echo = true) { return $selected == $current ? 'selected="selected"' : ''; }
function checked($checked, $current = true, $echo = true) { return $checked == $current ? 'checked="checked"' : ''; }
function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null) { return ''; }

class WP_Error {
    public function __construct(public $code, public $message, public $data = array()) {}
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($val) { return $val instanceof WP_Error; }

function wp_remote_get($url, $args = array()) {
    $GLOBALS['http_requests'][] = array('method' => 'GET', 'url' => $url, 'args' => $args);
    if (str_contains($url, 'bad_token')) {
        return array('response' => array('code' => 401), 'body' => json_encode(array('ok' => false, 'description' => 'Unauthorized')));
    }
    if (str_contains($url, 'getFile')) {
        return array('response' => array('code' => 200), 'body' => json_encode(array('ok' => true, 'result' => array('file_path' => 'photos/test.jpg'))));
    }
    if (str_contains($url, 'photos/test.jpg')) {
        return array('response' => array('code' => 200), 'body' => 'DUMMY_IMAGE_BINARY');
    }
    return array('response' => array('code' => 200), 'body' => json_encode(array('ok' => true, 'result' => array('id' => 999, 'username' => 'TestBot', 'first_name' => 'Test Bot'))));
}

function wp_remote_post($url, $args = array()) {
    $GLOBALS['http_requests'][] = array('method' => 'POST', 'url' => $url, 'args' => $args);
    if (str_contains($url, 'bad_token')) {
        return array('response' => array('code' => 401), 'body' => json_encode(array('ok' => false, 'description' => 'Unauthorized')));
    }
    return array('response' => array('code' => 200), 'body' => json_encode(array('ok' => true, 'result' => array('message_id' => 123))));
}

function wp_remote_retrieve_response_code($response) {
    return $response['response']['code'] ?? 0;
}
function wp_remote_retrieve_body($response) {
    return $response['body'] ?? '';
}

class TestUser {
    public $ID;
    public $roles = array();
    public $display_name;
    public $user_login;
    public function __construct($id, $roles, $name, $login) {
        $this->ID = $id;
        $this->roles = $roles;
        $this->display_name = $name;
        $this->user_login = $login;
    }
    public function set_role($role) { $this->roles = array($role); }
    public function add_role($role) { if (!in_array($role, $this->roles, true)) { $this->roles[] = $role; } }
    public function remove_role($role) { $this->roles = array_diff($this->roles, array($role)); }
}

function get_userdata($user_id) {
    return $GLOBALS['users'][$user_id] ?? null;
}

$GLOBALS['terms'] = array(
    10 => (object) array('term_id' => 10, 'name' => 'Footwear', 'slug' => 'footwear'),
    20 => (object) array('term_id' => 20, 'name' => 'Leather Goods', 'slug' => 'leather-goods'),
);
function get_terms($args = array()) {
    return array_values($GLOBALS['terms']);
}
function get_term($id, $taxonomy = '') {
    return $GLOBALS['terms'][$id] ?? null;
}
function get_term_by($field, $value, $taxonomy = '') {
    foreach ($GLOBALS['terms'] as $term) {
        if ($field === 'name' && strcasecmp($term->name, $value) === 0) return $term;
        if ($field === 'slug' && strcasecmp($term->slug, $value) === 0) return $term;
    }
    return null;
}
function wp_insert_term($term, $taxonomy = '', $args = array()) {
    $id = count($GLOBALS['terms']) + 10;
    $obj = (object) array('term_id' => $id, 'name' => $term, 'slug' => strtolower($term));
    $GLOBALS['terms'][$id] = $obj;
    return array('term_id' => $id, 'term_taxonomy_id' => $id);
}
function wp_set_object_terms($object_id, $terms, $taxonomy = '', $append = false) {
    return (array) $terms;
}
function sanitize_title($title) {
    return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '-', $title));
}
$GLOBALS['post_meta'] = array();
function get_post_meta($post_id, $key = '', $single = false) {
    return $GLOBALS['post_meta'][$post_id][$key] ?? '';
}
function update_post_meta($post_id, $key, $value) {
    $GLOBALS['post_meta'][$post_id][$key] = $value;
    return true;
}
$GLOBALS['post_statuses'] = array();
function get_post_type($id) { return 'product'; }
function get_post_status($id) {
    return $GLOBALS['post_statuses'][$id] ?? 'draft';
}
function wp_delete_post($id, $force = false) { return true; }
if (!class_exists('PWF_History_Manager')) {
    class PWF_History_Manager { public static function record(...$args) { return 1; } }
}
if (!class_exists('PWF_Notification_Manager')) {
    class PWF_Notification_Manager { public static function dispatch(...$args) {} }
}

class WC_Product_Attribute {
    public $name = '';
    public $options = array();
    public $visible = true;
    public function set_name($n) { $this->name = $n; }
    public function set_options($o) { $this->options = $o; }
    public function set_visible($v) { $this->visible = $v; }
    public function set_position($p) {}
    public function set_variation($v) {}
}

class TestProduct {
    public $image_id = 100;
    public $gallery = array();
    public $fields = array('regular_price' => '', 'price' => '', 'description' => 'Test description');

    public function get_name() { return 'Silk Scarf'; }
    public function get_sku() { return 'SCARF-01'; }
    public function get_category_ids() { return array(10); }
    public function get_attribute($name) { return ''; }
    public function get_attributes() { return array(); }
    public function set_attributes($attr) {}
    public function get_image_id() { return $this->image_id; }
    public function set_image_id($id) { $this->image_id = $id; }
    public function get_gallery_image_ids() { return $this->gallery; }
    public function set_gallery_image_ids($g) { $this->gallery = $g; }
    public function get_regular_price() { return $this->fields['regular_price'] ?? ''; }
    public function set_regular_price($p) { $this->fields['regular_price'] = $p; }
    public function set_price($p) { $this->fields['price'] = $p; }
    public function get_description() { return $this->fields['description'] ?? ''; }
    public function set_description($d) { $this->fields['description'] = $d; }
    public function set_status($s) {}
    public function save() { return 50; }
}

class WC_Product_Simple extends TestProduct {
    public function set_name($n) {}
    public function set_sku($s) {}
    public function set_description($d) {}
}

class FakeTelegramDB {
    public $prefix = 'wp_';
    public $workflows = array(
        50 => array('product_id' => 50, 'current_status' => 'waiting_photography', 'created_by' => 1)
    );
    public $assignments = array();

    public function prepare($sql, ...$args) {
        return array($sql, $args);
    }

    public function get_results($query) {
        $sql = is_array($query) ? $query[0] : $query;
        $args = is_array($query) ? $query[1] : array();

        if (str_contains($sql, 'pwf_assignments a JOIN wp_pwf_workflows')) {
            $uid = (int) ($args[0] ?? 0);
            $res = array();
            foreach ($this->assignments as $a) {
                if ((int) $a['user_id'] === $uid && empty($a['completed_at'])) {
                    $pid = $a['product_id'];
                    $wf = $this->workflows[$pid] ?? null;
                    $obj = (object) $a;
                    $obj->current_status = $wf ? $wf['current_status'] : 'new_product';
                    $res[] = $obj;
                }
            }
            return $res;
        }

        if (str_contains($sql, 'pwf_workflows') && str_contains($sql, "current_status IN ('new_product', 'waiting_photography')")) {
            $res = array();
            foreach ($this->workflows as $w) {
                if (in_array($w['current_status'], array('new_product', 'waiting_photography'), true)) {
                    $res[] = (object) $w;
                }
            }
            return $res;
        }

        if (str_contains($sql, 'pwf_workflows') && str_contains($sql, "current_status = 'waiting_content'")) {
            $res = array();
            foreach ($this->workflows as $w) {
                if ($w['current_status'] === 'waiting_content') {
                    $res[] = (object) $w;
                }
            }
            return $res;
        }

        return array();
    }

    public function get_row($query) {
        $sql = is_array($query) ? $query[0] : $query;
        $args = is_array($query) ? $query[1] : array();

        if (str_contains($sql, 'pwf_workflows') && str_contains($sql, 'product_id=%d')) {
            $pid = (int) ($args[0] ?? 0);
            return isset($this->workflows[$pid]) ? (object) $this->workflows[$pid] : null;
        }

        if (str_contains($sql, 'pwf_assignments') && str_contains($sql, 'product_id=%d')) {
            $pid = (int) ($args[0] ?? 0);
            $stage = $args[1] ?? '';
            foreach ($this->assignments as $a) {
                if ($a['product_id'] == $pid && (!$stage || $a['stage'] == $stage)) {
                    return (object) $a;
                }
            }
            return null;
        }

        return null;
    }

    public function insert($table, $data) {
        if (str_contains($table, 'pwf_workflows')) {
            $this->workflows[$data['product_id']] = $data;
            return 1;
        }
        if (str_contains($table, 'pwf_assignments')) {
            $data['assignment_id'] = count($this->assignments) + 1;
            $this->assignments[] = $data;
            return 1;
        }
        return 1;
    }

    public function update($table, $data, $where) {
        if (str_contains($table, 'pwf_workflows')) {
            $pid = $where['product_id'] ?? 0;
            if (isset($this->workflows[$pid])) {
                $this->workflows[$pid] = array_merge($this->workflows[$pid], $data);
            }
            return 1;
        }
        if (str_contains($table, 'pwf_assignments')) {
            foreach ($this->assignments as $k => $a) {
                $match = true;
                foreach ($where as $wk => $wv) {
                    if (($a[$wk] ?? null) != $wv) { $match = false; break; }
                }
                if ($match) {
                    $this->assignments[$k] = array_merge($this->assignments[$k], $data);
                }
            }
            return 1;
        }
        return 1;
    }

    public function delete($table, $where) {
        if (str_contains($table, 'pwf_workflows')) {
            $pid = $where['product_id'] ?? 0;
            unset($this->workflows[$pid]);
            return 1;
        }
        if (str_contains($table, 'pwf_assignments')) {
            foreach ($this->assignments as $k => $a) {
                $match = true;
                foreach ($where as $wk => $wv) {
                    if (($a[$wk] ?? null) != $wv) { $match = false; break; }
                }
                if ($match) {
                    unset($this->assignments[$k]);
                }
            }
            return 1;
        }
        return 1;
    }
}
$GLOBALS['wpdb'] = new FakeTelegramDB();

function wc_get_product($id) {
    if (isset($GLOBALS['post_statuses'][$id]) && $GLOBALS['post_statuses'][$id] === false) {
        return false;
    }
    static $inst;
    if (!$inst) $inst = new TestProduct();
    return $inst;
}

require_once PWF_DIR . 'includes/class-i18n.php';
require_once PWF_DIR . 'includes/class-workflow-manager.php';
require_once PWF_DIR . 'integrations/class-telegram.php';
require_once PWF_DIR . 'admin/settings-page.php';

// Setup Mock Users
$GLOBALS['users'][1] = new TestUser(1, array('administrator'), 'Admin User', 'admin');
$GLOBALS['users'][2] = new TestUser(2, array('pwf_photographer'), 'Jane Photo', 'jane');
$GLOBALS['users'][3] = new TestUser(3, array('subscriber'), 'Bob Worker', 'bob');
$GLOBALS['users'][5] = new TestUser(5, array('pwf_content_manager'), 'Carl Content', 'carl');

// User 2 has Telegram chat ID
update_user_meta(2, 'pwf_telegram_chat_id', '987654321');
// User 5 has Telegram chat ID
update_user_meta(5, 'pwf_telegram_chat_id', '44332211');

$tests = 0;
function assert_test($cond, $msg) {
    global $tests;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    $tests++;
    echo "PASS: $msg\n";
}

// TEST 1: Default configuration state
assert_test(!PWF_Telegram::is_enabled(), 'Telegram disabled by default');
assert_test(PWF_Telegram::get_token() === '', 'Empty token by default');
assert_test(!PWF_Telegram::is_configured(), 'Not configured initially');

// TEST 2: Validation of empty token on get_me
$res = PWF_Telegram::get_me();
assert_test(is_wp_error($res) && $res->code === 'no_token', 'get_me fails without token');

// TEST 3: Validation of bad token on get_me
$res = PWF_Telegram::get_me('bad_token');
assert_test(is_wp_error($res) && $res->code === 'telegram_error', 'get_me fails with bad token');

// TEST 4: Successful get_me
$res = PWF_Telegram::get_me('valid_token');
assert_test(!is_wp_error($res) && $res['username'] === 'TestBot', 'get_me succeeds with valid token');

// TEST 5: Enable Telegram & configure token and default chat
update_option('pwf_telegram_enabled', '1');
update_option('pwf_telegram_bot_token', '123456:ABC-DEF');
update_option('pwf_telegram_default_chat_id', '-100123456789');
assert_test(PWF_Telegram::is_configured(), 'Telegram is configured after options set');

// TEST 6: send_message checks
$GLOBALS['http_requests'] = array();
$send_res = PWF_Telegram::send_message('-100123456789', 'Hello Telegram');
assert_test($send_res === true, 'send_message succeeds');
assert_test(count($GLOBALS['http_requests']) === 1, 'HTTP POST was made');
assert_test($GLOBALS['http_requests'][0]['args']['body']['chat_id'] === '-100123456789', 'Correct chat ID passed to API');

// TEST 7: send_test
$GLOBALS['http_requests'] = array();
$test_msg_res = PWF_Telegram::send_test('-100123456789');
assert_test($test_msg_res === true, 'send_test succeeds');
assert_test(str_contains($GLOBALS['http_requests'][0]['args']['body']['text'], 'Product Workflow Telegram Test'), 'Test message body formatted properly');

// TEST 8: Workflow Event - task_assigned triggers both assignee personal chat and default chat
$GLOBALS['http_requests'] = array();
PWF_Telegram::handle_event('task_assigned', 50, array('stage' => 'photography', 'user_id' => 2));
assert_test(count($GLOBALS['http_requests']) === 2, 'task_assigned sent 2 messages (personal + default)');
$recipients = array($GLOBALS['http_requests'][0]['args']['body']['chat_id'], $GLOBALS['http_requests'][1]['args']['body']['chat_id']);
assert_test(in_array('987654321', $recipients, true), 'Personal chat ID received task alert');
assert_test(in_array('-100123456789', $recipients, true), 'Default chat ID received task alert');
assert_test(str_contains($GLOBALS['http_requests'][0]['args']['body']['text'], 'Silk Scarf'), 'Message includes product title');

// TEST 9: Workflow Event - status_changed
$GLOBALS['http_requests'] = array();
PWF_Telegram::handle_event('status_changed', 50, array('from' => 'waiting_photography', 'to' => 'photography_completed', 'actor' => 2, 'note' => 'All shots uploaded'));
assert_test(count($GLOBALS['http_requests']) === 1, 'status_changed sent 1 message to default chat');
assert_test(str_contains($GLOBALS['http_requests'][0]['args']['body']['text'], 'All shots uploaded'), 'Message includes status note');

// TEST 9B: Photographer Helper & Event Notification on Product Creation
$photographers = PWF_Telegram::get_photographers();
assert_test(count($photographers) === 1 && $photographers[0]->ID === 2, 'get_photographers finds active photographer');

$GLOBALS['http_requests'] = array();
PWF_Telegram::handle_event('created', 50, array('user_id' => 1));
assert_test(count($GLOBALS['http_requests']) === 2, 'created sent 2 messages (default channel + photographer personal)');
$created_recipients = array($GLOBALS['http_requests'][0]['args']['body']['chat_id'], $GLOBALS['http_requests'][1]['args']['body']['chat_id']);
assert_test(in_array('-100123456789', $created_recipients, true), 'Default chat received created broadcast');
assert_test(in_array('987654321', $created_recipients, true), 'Photographer personal chat received new product alert');

// Check photographer message content
$photo_msg_body = '';
foreach ($GLOBALS['http_requests'] as $req) {
    if ($req['args']['body']['chat_id'] === '987654321') {
        $photo_msg_body = $req['args']['body']['text'];
        break;
    }
}
assert_test(str_contains($photo_msg_body, 'اطلاعیه عکاسی محصول جدید'), 'Photographer message has photography alert header');
assert_test(str_contains($photo_msg_body, 'عکاس گرامی'), 'Photographer message addresses photographer');

// TEST 9C: Status transition to waiting_photography alerts photographer
$GLOBALS['http_requests'] = array();
PWF_Telegram::handle_event('status_changed', 50, array('from' => 'new_product', 'to' => 'waiting_photography', 'actor' => 1));
assert_test(count($GLOBALS['http_requests']) === 2, 'waiting_photography transition sent 2 messages');
$trans_recipients = array($GLOBALS['http_requests'][0]['args']['body']['chat_id'], $GLOBALS['http_requests'][1]['args']['body']['chat_id']);
assert_test(in_array('987654321', $trans_recipients, true), 'Photographer alerted on waiting_photography transition');

// TEST 10: Role assignment logic
$bob = $GLOBALS['users'][3];
assert_test(in_array('subscriber', $bob->roles, true), 'Bob is initially subscriber');
$res = PWF_Settings::apply_user_role(3, 'pwf_content_manager', '1122334455');
assert_test($res === true, 'apply_user_role returns true');
assert_test(in_array('pwf_content_manager', $bob->roles, true), 'Bob was assigned pwf_content_manager role');
assert_test(get_user_meta(3, 'pwf_telegram_chat_id', true) === '1122334455', 'Bob telegram chat ID was saved in user meta');

// TEST 11: Admin user self-role update does not strip administrator
$admin = $GLOBALS['users'][1];
$res = PWF_Settings::apply_user_role(1, 'pwf_reviewer', '99887766');
assert_test($res === true, 'apply_user_role returns true for admin');
assert_test(in_array('administrator', $admin->roles, true), 'Admin retained administrator role');
assert_test(in_array('pwf_reviewer', $admin->roles, true), 'Admin gained pwf_reviewer capability role');
assert_test(get_user_meta(1, 'pwf_telegram_chat_id', true) === '99887766', 'Admin telegram chat ID updated');

class MockRESTRequest {
    public function __construct(public $params = array(), public $headers = array()) {
        if (!isset($this->headers['x_telegram_bot_api_secret_token']) && !isset($this->headers['x-telegram-bot-api-secret-token'])) {
            $secret = PWF_Telegram_Client::get_webhook_secret();
            $this->headers['x_telegram_bot_api_secret_token'] = $secret;
        }
    }
    public function get_json_params() { return $this->params; }
    public function get_body() { return json_encode($this->params); }
    public function get_header($name) { return $this->headers[strtolower($name)] ?? ''; }
}

// TEST 12: Webhook URL generation & set_webhook with secret_token
assert_test(PWF_Telegram::get_webhook_url() === 'https://example.com/wp-json/product-workflow/v1/telegram/webhook', 'Webhook URL matches expected route');
$set_res = PWF_Telegram::set_webhook();
assert_test($set_res === true, 'set_webhook succeeds');
assert_test(!empty($GLOBALS['http_requests']), 'setWebhook sent HTTP request');
$last_req = end($GLOBALS['http_requests']);
assert_test(!empty($last_req['args']['body']['secret_token']), 'setWebhook included secret_token parameter');
assert_test($last_req['args']['body']['secret_token'] === PWF_Telegram_Client::get_webhook_secret(), 'secret_token matches stored webhook secret');

// Test webhook authentication defenses:
$unauth_req = new MockRESTRequest(array('message' => array('text' => '/start')), array('x_telegram_bot_api_secret_token' => ''));
$perm_unauth = PWF_Telegram::verify_webhook_permission($unauth_req);
assert_test(is_wp_error($perm_unauth) && $perm_unauth->get_error_data()['status'] === 403, 'Webhook request without secret token is rejected with 403');

$forged_req = new MockRESTRequest(array('message' => array('text' => '/start')), array('x_telegram_bot_api_secret_token' => 'forged_fake_token'));
$perm_forged = PWF_Telegram::verify_webhook_permission($forged_req);
assert_test(is_wp_error($perm_forged) && $perm_forged->get_error_data()['status'] === 403, 'Webhook request with forged secret token is rejected with 403');

$valid_req = new MockRESTRequest(array('message' => array('text' => '/start')));
$perm_valid = PWF_Telegram::verify_webhook_permission($valid_req);
assert_test($perm_valid === true, 'Webhook request with valid secret token succeeds');

// Setup Factory user for interactive bot testing
$GLOBALS['users'][4] = new TestUser(4, array('pwf_factory'), 'Factory User', 'factory');
$GLOBALS['users'][4]->roles = array('pwf_factory');
update_user_meta(4, 'pwf_telegram_chat_id', '55443322');

// TEST 13: Webhook unlinked user receiving /start
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 999999),
        'from' => array('id' => 999999),
        'text' => '/start',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(count($GLOBALS['http_requests']) === 1, 'Unlinked user received response');
assert_test(str_contains($GLOBALS['http_requests'][0]['args']['body']['text'], 'Your Telegram ID is:'), 'Informs unlinked user of their Telegram ID');

// TEST 14: Webhook Factory user sending /start
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 55443322),
        'from' => array('id' => 55443322),
        'text' => '/start',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(count($GLOBALS['http_requests']) === 1, 'Factory user received greeting');
assert_test(str_contains($GLOBALS['http_requests'][0]['args']['body']['text'], 'Factory User'), 'Greets user by name');

// TEST 15: Webhook Factory user starting /new
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 55443322),
        'from' => array('id' => 55443322),
        'text' => '/new',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(PWF_Telegram::get_session('55443322')['step'] === 'new_name', 'Session step transitioned to new_name');

// TEST 16: Sending product name
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 55443322),
        'from' => array('id' => 55443322),
        'text' => 'Handmade Leather Boots',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(PWF_Telegram::get_session('55443322')['step'] === 'new_cat', 'Session step transitioned to new_cat');
assert_test(PWF_Telegram::get_session('55443322')['data']['name'] === 'Handmade Leather Boots', 'Name saved in session');

// TEST 17: Category keyboard generation
$cat_kb = PWF_Telegram::category_keyboard();
assert_test(is_array($cat_kb) && count($cat_kb) >= 2, 'Category keyboard generated with options');
assert_test(str_contains($cat_kb[0][0], 'Footwear'), 'Category keyboard includes Footwear');

// TEST 18: Sending Category
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 55443322),
        'from' => array('id' => 55443322),
        'text' => '📁 Footwear',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(PWF_Telegram::get_session('55443322')['step'] === 'new_sku', 'Session step transitioned to new_sku');
assert_test(PWF_Telegram::get_session('55443322')['data']['category_name'] === 'Footwear', 'Category name saved in session');
assert_test(PWF_Telegram::get_session('55443322')['data']['category_id'] === 10, 'Category ID saved in session');

// TEST 19: Sending SKU
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 55443322),
        'from' => array('id' => 55443322),
        'text' => 'BOOTS-42',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(PWF_Telegram::get_session('55443322')['step'] === 'new_carton', 'Session step transitioned to new_carton');
assert_test(PWF_Telegram::get_session('55443322')['data']['sku'] === 'BOOTS-42', 'SKU saved in session');

// TEST 20: Sending Carton Quantity (تعداد در کارتن)
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 55443322),
        'from' => array('id' => 55443322),
        'text' => '24',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(PWF_Telegram::get_session('55443322')['step'] === 'new_cbm', 'Session step transitioned to new_cbm');
assert_test(PWF_Telegram::get_session('55443322')['data']['carton_qty'] === '24', 'Carton qty saved in session');

// TEST 21: Sending CBM
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 55443322),
        'from' => array('id' => 55443322),
        'text' => '0.045',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(PWF_Telegram::get_session('55443322')['step'] === 'new_desc', 'Session step transitioned to new_desc');
assert_test(PWF_Telegram::get_session('55443322')['data']['cbm'] === '0.045', 'CBM saved in session');

// TEST 21B: Factory sending description in new_desc -> product created silently without channel broadcast
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 55443322),
        'from' => array('id' => 55443322),
        'text' => 'High quality winter boots',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(PWF_Telegram::get_session('55443322')['step'] === 'new_photos', 'Session transitioned to new_photos');
$channel_broadcasts = array_filter($GLOBALS['http_requests'], fn($r) => ($r['args']['body']['chat_id'] ?? '') === '-100123456789');
assert_test(count($channel_broadcasts) === 0, 'No channel broadcast was made before /done');

// TEST 21C: Factory sending reference photo in new_photos
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 55443322),
        'from' => array('id' => 55443322),
        'photo' => array(
            array('file_id' => 'sample_file_123', 'width' => 100, 'height' => 100),
            array('file_id' => 'sample_file_best', 'width' => 800, 'height' => 800),
        ),
    ),
));
PWF_Telegram::handle_webhook($req);
$sess = PWF_Telegram::get_session('55443322');
assert_test(in_array('sample_file_best', $sess['data']['photo_file_ids'] ?? array(), true), 'Reference photo file_id saved in session');

// TEST 21D: Factory sending /done -> channel broadcast and reference photos dispatched to photographer
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 55443322),
        'from' => array('id' => 55443322),
        'text' => '/done',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(PWF_Telegram::get_session('55443322')['step'] === 'idle', 'Session cleared after /done');
$channel_broadcasts = array_filter($GLOBALS['http_requests'], fn($r) => ($r['args']['body']['chat_id'] ?? '') === '-100123456789');
assert_test(count($channel_broadcasts) >= 1, 'Channel received broadcast after /done');

// Verify photographer received reference photo via send_photo
$photo_sent_to_photographer = false;
foreach ($GLOBALS['http_requests'] as $r) {
    if (($r['args']['body']['chat_id'] ?? '') === '987654321' && str_contains($r['url'], '/sendPhoto')) {
        $photo_sent_to_photographer = true;
        break;
    }
}
assert_test($photo_sent_to_photographer === true, 'Photographer received reference photo via sendPhoto after /done');

// TEST 22: Persian formatting of channel notification message
$fa_msg = PWF_Telegram::format_persian_channel_message('created', 50, array('user_id' => 1));
assert_test(str_contains($fa_msg, 'گزارش فرآیند محصول'), 'Channel message contains Persian header');
assert_test(str_contains($fa_msg, 'محصول جدید به سیستم اضافه شد'), 'Channel message contains Persian event label');
assert_test(str_contains($fa_msg, 'Silk Scarf'), 'Channel message contains product name');
assert_test(str_contains($fa_msg, 'دسته‌بندی'), 'Channel message contains category field');

// TEST 23: send_photo API method
$GLOBALS['http_requests'] = array();
$photo_res = PWF_Telegram::send_photo('-100123456789', 'AgACAgIAAxkBAAI_test', 'Sample Caption');
assert_test($photo_res === true, 'send_photo succeeds');
assert_test(count($GLOBALS['http_requests']) === 1, 'send_photo made HTTP POST');
assert_test(str_contains($GLOBALS['http_requests'][0]['url'], '/sendPhoto'), 'send_photo URL is correct');
assert_test($GLOBALS['http_requests'][0]['args']['body']['photo'] === 'AgACAgIAAxkBAAI_test', 'Correct file_id sent in photo body');

// TEST 24: get_content_managers helper
$cms = PWF_Telegram::get_content_managers();
$cm_ids = array_map(fn($u) => $u->ID, $cms);
assert_test(count($cms) >= 1 && in_array(5, $cm_ids, true), 'get_content_managers finds active content manager');

// TEST 25: Photographer checks /tasks and sees available photography task
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 987654321),
        'from' => array('id' => 987654321),
        'text' => '/tasks',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(count($GLOBALS['http_requests']) === 1, 'Photographer received /tasks response');
assert_test(str_contains($GLOBALS['http_requests'][0]['args']['body']['text'], '/photo_50'), 'Task response includes /photo_50 command');

// TEST 26: Photographer initiates /photo_50 and uploads photo
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 987654321),
        'from' => array('id' => 987654321),
        'text' => '/photo_50',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(PWF_Telegram::get_session('987654321')['step'] === 'photographer_upload', 'Session transitioned to photographer_upload');

// Photographer sends /done to complete photography
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 987654321),
        'from' => array('id' => 987654321),
        'text' => '/done',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(count($GLOBALS['http_requests']) >= 2, 'Photographer /done triggered completion and notification');
assert_test($GLOBALS['wpdb']->workflows[50]['current_status'] === 'waiting_content', 'Product status advanced to waiting_content');

// Verify Content Manager (User 5, chat 44332211) received direct task notification
$cm_received = false;
foreach ($GLOBALS['http_requests'] as $hr) {
    if ($hr['args']['body']['chat_id'] === '44332211') {
        $cm_received = true;
        assert_test(str_contains($hr['args']['body']['text'], 'تخصیص وظیفه جدید به مدیر محتوا'), 'Content manager received task notification');
        break;
    }
}
assert_test($cm_received === true, 'Content Manager received Telegram message on waiting_content');

// TEST 27: Content Manager managing product via Telegram (/content_50, /desc_50, /price_50, /finish_50)
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 44332211),
        'from' => array('id' => 44332211),
        'text' => '/content_50',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(str_contains($GLOBALS['http_requests'][0]['args']['body']['text'], 'مدیریت محتوای محصول #50'), 'Content Manager sees product overview');

// Content Manager sets description
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 44332211),
        'from' => array('id' => 44332211),
        'text' => '/desc_50 Beautiful silk scarf with handcrafted patterns',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(str_contains($GLOBALS['http_requests'][0]['args']['body']['text'], 'توضیحات محصول #50 بروزرسانی شد'), 'Description update confirmed');

// Content Manager completes content
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 44332211),
        'from' => array('id' => 44332211),
        'text' => '/finish_50',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test($GLOBALS['wpdb']->workflows[50]['current_status'] === 'waiting_review', 'Status advanced to waiting_review');

// TEST 28: Trashed and deleted products excluded from /tasks
$GLOBALS['wpdb']->workflows[60] = array('product_id' => 60, 'current_status' => 'waiting_photography', 'created_by' => 1);
$GLOBALS['post_statuses'][60] = 'trash'; // In WordPress trash!

$GLOBALS['wpdb']->workflows[70] = array('product_id' => 70, 'current_status' => 'waiting_photography', 'created_by' => 1);
$GLOBALS['post_statuses'][70] = false; // Permanently deleted in WordPress!

$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 987654321),
        'from' => array('id' => 987654321),
        'text' => '/tasks',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test(count($GLOBALS['http_requests']) >= 1, 'Photographer received /tasks response');
$tasks_body = $GLOBALS['http_requests'][0]['args']['body']['text'];
assert_test(!str_contains($tasks_body, '#60'), 'Trashed product #60 is excluded from photographer tasks');
assert_test(!str_contains($tasks_body, '#70'), 'Deleted product #70 is excluded from photographer tasks');
assert_test(!isset($GLOBALS['wpdb']->workflows[70]), 'Deleted product #70 orphan workflow was cleaned up');

// TEST 29: Photographer completion tick button via "✅ تکمیل عکاسی #80"
$GLOBALS['wpdb']->workflows[80] = array('product_id' => 80, 'current_status' => 'waiting_photography', 'created_by' => 1);
$GLOBALS['wpdb']->assignments[] = array(
    'assignment_id' => 80,
    'product_id'    => 80,
    'stage'         => 'photography',
    'user_id'       => 2,
    'assigned_at'   => '2026-09-05 12:00:00',
    'completed_at'  => null,
);
$GLOBALS['post_statuses'][80] = 'draft';

// Check /tasks includes both photo and done_photo buttons
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 987654321),
        'from' => array('id' => 987654321),
        'text' => '/tasks',
    ),
));
PWF_Telegram::handle_webhook($req);
$t_resp = $GLOBALS['http_requests'][0]['args']['body'];
assert_test(str_contains($t_resp['text'], '/done_photo_80'), '/tasks offers /done_photo_80 command');
$kb_flat = json_encode($t_resp['reply_markup'] ?? array(), JSON_UNESCAPED_UNICODE);
assert_test(str_contains($kb_flat, '✅ تکمیل عکاسی #80'), '/tasks keyboard includes ✅ تکمیل عکاسی #80 button');

// Photographer taps "✅ تکمیل عکاسی #80"
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 987654321),
        'from' => array('id' => 987654321),
        'text' => '✅ تکمیل عکاسی #80',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test($GLOBALS['wpdb']->workflows[80]['current_status'] === 'waiting_content', 'Product 80 advanced to waiting_content via completion tick');
$assignment_completed = false;
foreach ($GLOBALS['wpdb']->assignments as $asgn) {
    if ($asgn['product_id'] == 80 && $asgn['stage'] === 'photography' && !empty($asgn['completed_at'])) {
        $assignment_completed = true;
        break;
    }
}
assert_test($assignment_completed === true, 'Photography assignment for #80 was marked completed');

// Photographer checks /tasks again; product 80 must be cleared
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 987654321),
        'from' => array('id' => 987654321),
        'text' => '/tasks',
    ),
));
PWF_Telegram::handle_webhook($req);
$tasks_after_done = $GLOBALS['http_requests'][0]['args']['body']['text'];
assert_test(!str_contains($tasks_after_done, '#80'), 'Product #80 cleared from photographer tasks after completion tick');

// TEST 30: Photographer completion via button "✅ تیک تکمیل و اتمام عکاسی" in upload session
$GLOBALS['wpdb']->workflows[90] = array('product_id' => 90, 'current_status' => 'waiting_photography', 'created_by' => 1);
$GLOBALS['post_statuses'][90] = 'draft';

// Enter upload session
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 987654321),
        'from' => array('id' => 987654321),
        'text' => '/photo_90',
    ),
));
PWF_Telegram::handle_webhook($req);
$sess_kb = json_encode($GLOBALS['http_requests'][0]['args']['body']['reply_markup'] ?? array(), JSON_UNESCAPED_UNICODE);
assert_test(str_contains($sess_kb, '✅ تیک تکمیل و اتمام عکاسی'), 'Upload session provides ✅ تیک تکمیل و اتمام عکاسی button');

// Photographer presses "✅ تیک تکمیل و اتمام عکاسی"
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 987654321),
        'from' => array('id' => 987654321),
        'text' => '✅ تیک تکمیل و اتمام عکاسی',
    ),
));
PWF_Telegram::handle_webhook($req);
assert_test($GLOBALS['wpdb']->workflows[90]['current_status'] === 'waiting_content', 'Product 90 advanced to waiting_content via Persian tick button');
assert_test(PWF_Telegram::get_session('987654321')['step'] === 'idle', 'Upload session cleared after Persian tick button');

// TEST 31: Bottom buttons optimized for each user role
// 1. Photographer sending /start
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 987654321),
        'from' => array('id' => 987654321),
        'text' => '/start',
    ),
));
PWF_Telegram::handle_webhook($req);
$photo_start_kb = $GLOBALS['http_requests'][0]['args']['body']['reply_markup'];
assert_test(!str_contains($photo_start_kb, 'New Product') && !str_contains($photo_start_kb, 'محصول جدید'), 'Photographer keyboard hides New Product button');
assert_test(str_contains($photo_start_kb, 'وظایف عکاسی من'), 'Photographer keyboard has tailored photography tasks button');

// 2. Content Manager sending /start
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 44332211),
        'from' => array('id' => 44332211),
        'text' => '/start',
    ),
));
PWF_Telegram::handle_webhook($req);
$cm_start_kb = $GLOBALS['http_requests'][0]['args']['body']['reply_markup'];
assert_test(!str_contains($cm_start_kb, 'New Product') && !str_contains($cm_start_kb, 'محصول جدید'), 'Content Manager keyboard hides New Product button');
assert_test(str_contains($cm_start_kb, 'وظایف تولید محتوا'), 'Content Manager keyboard has tailored content tasks button');

// 3. Factory User sending /start
$GLOBALS['http_requests'] = array();
$req = new MockRESTRequest(array(
    'message' => array(
        'chat' => array('id' => 55443322),
        'from' => array('id' => 55443322),
        'text' => '/start',
    ),
));
PWF_Telegram::handle_webhook($req);
$factory_start_kb = $GLOBALS['http_requests'][0]['args']['body']['reply_markup'];
assert_test(str_contains($factory_start_kb, 'New Product') || str_contains($factory_start_kb, 'محصول جدید'), 'Factory User keyboard includes New Product button');

echo "\nAll $tests Telegram and Settings unit tests passed successfully!\n";

