<?php
defined('ABSPATH') || exit;

class PWF_Workflow_Manager {
    private static $writing = false;

    public static function states() {
        return apply_filters('pwf_states', array(
            'new_product' => pwf_t('New Product'), 'waiting_photography' => pwf_t('Waiting for Photography'),
            'photography_completed' => pwf_t('Photography Completed'), 'waiting_content' => pwf_t('Waiting for Content'),
            'content_completed' => pwf_t('Content Completed'), 'waiting_review' => pwf_t('Waiting for Review'),
            'approved' => pwf_t('Approved'), 'published' => pwf_t('Published'),
            'ready_for_social' => pwf_t('Ready for Social'), 'completed' => pwf_t('Completed'),
        ));
    }

    public static function transitions() {
        // Each edge declares a capability and (where relevant) an assignment scope.
        return apply_filters('pwf_transitions', array(
            'new_product' => array('waiting_photography' => array('assign_tasks', '')),
            'waiting_photography' => array('photography_completed' => array('complete_photography_task', 'photography')),
            'photography_completed' => array('waiting_content' => array('assign_tasks', '')),
            'waiting_content' => array('content_completed' => array('complete_content_task', 'content')),
            'content_completed' => array('waiting_review' => array('assign_tasks', '')),
            'waiting_review' => array('waiting_photography' => array('review_product', 'review'), 'waiting_content' => array('review_product', 'review'), 'approved' => array('review_product', 'review')),
            'approved' => array('published' => array('publish_product', ''), 'waiting_review' => array('review_product', 'review')),
            'published' => array('ready_for_social' => array('publish_product', '')),
            'ready_for_social' => array('completed' => array('complete_social_task', 'social')),
            'completed' => array(),
        ));
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pwf_workflows WHERE product_id=%d", $id));
    }

    public static function allowed($id, $from, $to) {
        $edge = self::transitions()[$from][$to] ?? null;
        if (!$edge) { return false; }
        return $edge[1] ? PWF_Permission_Manager::stage($id, $edge[1], $edge[0]) : current_user_can($edge[0]);
    }

    public static function mutate($id, $callback) {
        global $wpdb;
        $id = absint($id);
        $lock = 'pwf_' . md5($wpdb->prefix . ':' . $id);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            return new WP_Error('busy', pwf_t('This product is being updated. Please retry.'), array('status' => 409));
        }
        $event = null;
        try {
            if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException(pwf_t('Could not start a database transaction.')); }
            $row = self::get($id);
            if (!$row || get_post_type($id) !== 'product' || get_post_status($id) === 'trash') { throw new RuntimeException(pwf_t('Workflow product not found.')); }
            self::$writing = true;
            $event = $callback($row);
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException(pwf_t('Could not commit changes.')); }
            $result = self::get($id);
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            clean_post_cache($id);
            wc_delete_product_transients($id);
            $result = new WP_Error('workflow_error', $error->getMessage(), array('status' => 400));
        } finally {
            self::$writing = false;
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        if (!is_wp_error($result) && is_array($event)) { PWF_Notification_Manager::dispatch($id, $event); }
        return $result;
    }

    public static function initialize($id) {
        global $wpdb;
        if (self::get($id)) { return true; }
        if (get_post_type($id) !== 'product' || get_post_status($id) !== 'draft') { return new WP_Error('invalid_product', pwf_t('Only draft products can enter the workflow.')); }
        $wpdb->query('START TRANSACTION');
        try {
            $now = current_time('mysql', true);
            if (!$wpdb->insert($wpdb->prefix . 'pwf_workflows', array('product_id' => $id, 'current_status' => 'new_product', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now))) {
                throw new RuntimeException(pwf_t('Workflow could not be initialized.'));
            }
            PWF_History_Manager::record($id, 'created', '', 'new_product', 'Draft product entered the workflow.');
            $wpdb->query('COMMIT');
            return true;
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('initialization_failed', $error->getMessage());
        }
    }

    public static function create($data) {
        if (!current_user_can('create_product_request')) { return new WP_Error('forbidden', pwf_t('You cannot create products.'), array('status' => 403)); }
        $name = sanitize_text_field($data['name'] ?? '');
        if (!$name) { return new WP_Error('name_required', pwf_t('Enter a product name.'), array('status' => 400)); }
        $id = 0;
        try {
            $product = new WC_Product_Simple();
            $product->set_name($name);
            $product->set_sku(sanitize_text_field($data['sku'] ?? ''));
            $product->set_description(wp_kses_post($data['description'] ?? ''));
            $product->set_status('draft');
            $id = $product->save();

            // Assign category if provided
            if (!empty($data['category_id']) && function_exists('wp_set_object_terms')) {
                wp_set_object_terms($id, (int) $data['category_id'], 'product_cat');
            } elseif (!empty($data['category_name']) && function_exists('wp_set_object_terms')) {
                wp_set_object_terms($id, sanitize_text_field($data['category_name']), 'product_cat');
            }

            // Assign carton_qty and cbm if provided
            if (!empty($data['carton_qty']) && function_exists('update_post_meta')) {
                $c_val = sanitize_text_field($data['carton_qty']);
                update_post_meta($id, '_pwf_carton_qty', $c_val);
                update_post_meta($id, 'carton_qty', $c_val);
            }
            if (!empty($data['cbm']) && function_exists('update_post_meta')) {
                $cbm_val = sanitize_text_field($data['cbm']);
                update_post_meta($id, '_pwf_cbm', $cbm_val);
                update_post_meta($id, 'cbm', $cbm_val);
            }

            // Process initial product reference files or photos
            if (!empty($_FILES['product_attachments'])) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';

                $files = $_FILES['product_attachments'];
                $saved_files = array();
                $first_image_id = 0;

                if (isset($files['name']) && is_array($files['name'])) {
                    $count = count($files['name']);
                    for ($i = 0; $i < $count; $i++) {
                        if (empty($files['name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) {
                            continue;
                        }
                        $file_arr = array(
                            'name'     => sanitize_file_name($files['name'][$i]),
                            'type'     => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i],
                            'error'    => $files['error'][$i],
                            'size'     => $files['size'][$i],
                        );
                        $upload = wp_handle_upload($file_arr, array('test_form' => false));
                        if (!empty($upload['url']) && empty($upload['error'])) {
                            $is_img = preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $upload['url']);
                            $attachment_id = wp_insert_attachment(array(
                                'guid'           => $upload['url'],
                                'post_mime_type' => $upload['type'] ?? '',
                                'post_title'     => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
                                'post_content'   => '',
                                'post_status'    => 'inherit',
                            ), $upload['file'], $id);

                            if ($attachment_id && !is_wp_error($attachment_id)) {
                                wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
                                if ($is_img && !$first_image_id) {
                                    $first_image_id = $attachment_id;
                                }
                            }

                            $saved_files[] = array(
                                'name'          => basename($upload['file']),
                                'url'           => $upload['url'],
                                'size'          => $files['size'][$i],
                                'is_image'      => $is_img ? 1 : 0,
                                'attachment_id' => $attachment_id ?: 0,
                            );
                        }
                    }
                }

                if ($first_image_id) {
                    $product->set_image_id($first_image_id);
                    $product->save();
                }
                if (!empty($saved_files)) {
                    update_post_meta($id, '_pwf_sample_files', $saved_files);
                }
            }

            $initialized = self::initialize($id);
            if (is_wp_error($initialized)) { throw new RuntimeException($initialized->get_error_message()); }
            $row = self::get($id);
            if (empty($data['silent_notification'])) {
                PWF_Notification_Manager::dispatch($id, array('event' => 'created', 'user_id' => get_current_user_id()));
            }
            return $row;
        } catch (Throwable $error) {
            if ($id) { wp_delete_post($id, true); }
            return new WP_Error('create_failed', $error->getMessage(), array('status' => 400));
        }
    }

    public static function readiness($product) {
        $missing = array();
        if (!$product->get_name()) { $missing[] = pwf_t('Name'); }
        if (!$product->get_sku()) { $missing[] = pwf_t('SKU'); }
        if (trim(wp_strip_all_tags($product->get_description())) === '') { $missing[] = pwf_t('Description'); }
        if ($product->get_regular_price() === '') { $missing[] = pwf_t('Regular price'); }
        if (!$product->get_category_ids()) { $missing[] = pwf_t('Category'); }
        if (!$product->get_image_id() || !wp_attachment_is_image($product->get_image_id())) { $missing[] = pwf_t('Main image'); }
        return apply_filters('pwf_required_product_fields_missing', $missing, $product);
    }

    public static function transition($id, $to, $expected, $note = '') {
        if (!isset(self::states()[$to])) { return new WP_Error('invalid_state', pwf_t('Unknown workflow stage.'), array('status' => 400)); }
        return self::mutate($id, function ($row) use ($id, $to, $expected, $note) {
            global $wpdb;
            $from = $row->current_status;
            if ($from !== $expected) { throw new RuntimeException(pwf_t('The workflow changed. Refresh before trying again.')); }
            if (!self::allowed($id, $from, $to)) { throw new RuntimeException(pwf_t('You cannot perform this transition.')); }
            $note = sanitize_textarea_field($note);
            if ($from === 'waiting_review' && in_array($to, array('waiting_photography', 'waiting_content'), true) && trim($note) === '') {
                throw new RuntimeException(pwf_t('A review rejection requires a reason.'));
            }
            $product = wc_get_product($id);
            if (!$product) { throw new RuntimeException(pwf_t('Product not found.')); }
            $start_stage = apply_filters('pwf_queue_stages', array('waiting_photography' => 'photography', 'waiting_content' => 'content', 'waiting_review' => 'review', 'ready_for_social' => 'social'));
            if (isset($start_stage[$to])) {
                $stage = $start_stage[$to];
                $assignment = PWF_Assignment_Manager::get($id, $stage);
                if (!$assignment || !user_can($assignment->user_id, PWF_Permission_Manager::stage_caps()[$stage])) { throw new RuntimeException(sprintf(pwf_t('Assign an eligible %s user first.'), PWF_I18n::stage($stage))); }
                if ($wpdb->update($wpdb->prefix . 'pwf_assignments', array('completed_at' => null), array('product_id' => $id, 'stage' => $stage)) === false) { throw new RuntimeException(pwf_t('Could not reopen assignment.')); }
            }
            if ($to === 'photography_completed' && (!$product->get_image_id() || !wp_attachment_is_image($product->get_image_id()))) { throw new RuntimeException(pwf_t('Upload and select a main product image first.')); }
            if (in_array($to, array('content_completed', 'approved', 'published'), true)) {
                $missing = self::readiness($product);
                if ($missing) { throw new RuntimeException(sprintf(pwf_t('Complete the following before continuing: %s.'), implode(', ', $missing))); }
            }
            if ($to === 'published') {
                $product->set_status('publish');
                $product->save();
                if (get_post_status($id) !== 'publish') { throw new RuntimeException(pwf_t('WooCommerce publication failed.')); }
            }
            if (in_array($to, array('ready_for_social', 'completed'), true) && $product->get_status() !== 'publish') { throw new RuntimeException(pwf_t('The product must be published before social handoff.')); }
            $complete = apply_filters('pwf_completion_stages', array('photography_completed' => 'photography', 'content_completed' => 'content', 'approved' => 'review', 'completed' => 'social'));
            if (isset($complete[$to]) && $wpdb->update($wpdb->prefix . 'pwf_assignments', array('completed_at' => current_time('mysql', true)), array('product_id' => $id, 'stage' => $complete[$to])) === false) { throw new RuntimeException(pwf_t('Could not complete assignment.')); }
            if ($wpdb->update($wpdb->prefix . 'pwf_workflows', array('current_status' => $to, 'updated_at' => current_time('mysql', true)), array('product_id' => $id, 'current_status' => $from)) !== 1) { throw new RuntimeException(pwf_t('Workflow changed; please retry.')); }
            PWF_History_Manager::record($id, 'transition', $from, $to, $note);
            return array('event' => 'status_changed', 'from' => $from, 'to' => $to, 'note' => $note, 'actor' => get_current_user_id());
        });
    }

    public static function can_edit($id, $kind) {
        $row = self::get($id);
        if (!$row) { return false; }
        if ($kind === 'images') {
            return $row->current_status === 'waiting_photography' && PWF_Permission_Manager::stage($id, 'photography', 'upload_product_images');
        }
        return $row->current_status === 'waiting_content' && PWF_Permission_Manager::stage($id, 'content', 'edit_product_content');
    }

    public static function save_content($id, $data) {
        return self::mutate($id, function ($row) use ($id, $data) {
            if (!self::can_edit($id, 'content')) { throw new RuntimeException(pwf_t('Content editing is restricted to the assigned content stage.')); }
            $product = wc_get_product($id);
            if (!$product || !$product->is_type('simple')) { throw new RuntimeException(pwf_t('The workflow editor supports simple products. Use the WooCommerce editor for other product types.')); }
            // Explicit allowlist: no status, ownership, arbitrary metadata, or image changes.
            foreach (array('name', 'sku', 'description', 'short_description') as $field) {
                if (isset($data[$field])) {
                    $value = in_array($field, array('description', 'short_description'), true) ? wp_kses_post($data[$field]) : sanitize_text_field($data[$field]);
                    if ($field === 'name' && !$value) { throw new RuntimeException(pwf_t('Product name cannot be empty.')); }
                    $product->{'set_' . $field}($value);
                }
            }
            if (isset($data['regular_price'])) {
                $price = wc_format_decimal($data['regular_price']);
                if ($price === '' || !is_numeric($price) || (float) $price < 0) { throw new RuntimeException(pwf_t('Enter a non-negative price.')); }
                $product->set_regular_price($price);
            }
            foreach (array('category_ids' => 'product_cat', 'tag_ids' => 'product_tag') as $field => $taxonomy) {
                if (!isset($data[$field])) { continue; }
                $ids = array_filter(array_map('absint', (array) $data[$field]));
                foreach ($ids as $term_id) { if (!term_exists($term_id, $taxonomy)) { throw new RuntimeException(pwf_t('Unknown category or tag.')); } }
                $product->{'set_' . $field}(array_values($ids));
            }
            if (isset($data['stock_status'])) {
                if (!in_array($data['stock_status'], array('instock', 'outofstock', 'onbackorder'), true)) { throw new RuntimeException(pwf_t('Invalid stock status.')); }
                $product->set_stock_status($data['stock_status']);
            }
            if (isset($data['attributes'])) {
                $attributes = array();
                foreach ((array) $data['attributes'] as $name => $values) {
                    $name = sanitize_text_field($name);
                    if (!$name) { continue; }
                    $attribute = new WC_Product_Attribute();
                    $attribute->set_name($name);
                    $attribute->set_options(array_values(array_filter(array_map('sanitize_text_field', (array) $values))));
                    $attribute->set_visible(true);
                    $attributes[] = $attribute;
                }
                $product->set_attributes($attributes);
            }
            foreach (array('seo_title', 'seo_description') as $field) {
                if (isset($data[$field])) { $product->update_meta_data('_pwf_' . $field, sanitize_text_field($data[$field])); }
            }
            foreach (array('carton_qty', 'cbm') as $field) {
                if (isset($data[$field])) { $product->update_meta_data('_pwf_' . $field, sanitize_text_field($data[$field])); }
            }
            $product->save();
            PWF_History_Manager::record($id, 'content_saved', $row->current_status, $row->current_status, 'Product content updated.');
            return array('event' => 'content_saved');
        });
    }

    public static function save_images($id, $main, $gallery) {
        return self::mutate($id, function ($row) use ($id, $main, $gallery) {
            if (!self::can_edit($id, 'images')) { throw new RuntimeException(pwf_t('Image editing is restricted to the assigned photography stage.')); }
            $product = wc_get_product($id);
            $existing = array_merge(array($product->get_image_id()), $product->get_gallery_image_ids());
            $gallery = array_values(array_diff(array_unique(array_map('absint', (array) $gallery)), array(0, absint($main))));
            foreach (array_merge(array(absint($main)), $gallery) as $attachment) {
                if (!$attachment) { continue; }
                if (!wp_attachment_is_image($attachment) || ((int) wp_get_post_parent_id($attachment) !== (int) $id && !in_array($attachment, $existing, true))) { throw new RuntimeException(pwf_t('Choose image attachments belonging to this product.')); }
            }
            $product->set_image_id(absint($main));
            $product->set_gallery_image_ids($gallery);
            $product->save();
            PWF_History_Manager::record($id, 'images_saved', $row->current_status, $row->current_status, 'Main image and gallery updated.');
            return array('event' => 'images_saved');
        });
    }

    public static function upload($id) {
        // Do not grant upload_files: this endpoint checks product and stage ownership.
        $uploaded = 0;
        $result = self::mutate($id, function ($row) use ($id, &$uploaded) {
            if (!self::can_edit($id, 'images') && !($row->current_status === 'new_product' && (int) $row->created_by === get_current_user_id() && current_user_can('create_product_request'))) {
                throw new RuntimeException(pwf_t('You cannot upload to this product at this stage.'));
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            if (empty($_FILES['image']['tmp_name']) || !empty($_FILES['image']['error'])) { throw new RuntimeException(pwf_t('Choose a valid image file.')); }
            $uploaded = media_handle_upload('image', $id, array(), array('test_form' => false, 'mimes' => array('jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp')));
            if (is_wp_error($uploaded)) { throw new RuntimeException($uploaded->get_error_message()); }
            if (!wp_attachment_is_image($uploaded)) { throw new RuntimeException(pwf_t('Only images are accepted.')); }
            PWF_History_Manager::record($id, 'image_uploaded', $row->current_status, $row->current_status, 'Image attachment #' . $uploaded . ' uploaded.');
            return array('event' => 'image_uploaded', 'attachment_id' => $uploaded);
        });
        if (is_wp_error($result) && is_int($uploaded) && $uploaded > 0) { wp_delete_attachment($uploaded, true); }
        return $result;
    }

    public static function boot() {
        // Invalidate approval before native WooCommerce writes. If auditing fails,
        // reject the write rather than leave approved status on altered content.
        add_action('woocommerce_before_product_object_save', function ($product) {
            if (self::$writing || !$product->get_id()) { return; }
            $id = $product->get_id();
            $row = self::get($id);
            if (!$row || $row->current_status !== 'approved') { return; }
            $result = self::mutate($id, function ($fresh) use ($id) {
                global $wpdb;
                if ($fresh->current_status !== 'approved') { throw new RuntimeException(pwf_t('Workflow changed; refresh the product before editing.')); }
                if ($wpdb->update($wpdb->prefix . 'pwf_workflows', array('current_status' => 'waiting_review', 'updated_at' => current_time('mysql', true)), array('product_id' => $id)) === false || $wpdb->update($wpdb->prefix . 'pwf_assignments', array('completed_at' => null), array('product_id' => $id, 'stage' => 'review')) === false) { throw new RuntimeException(pwf_t('Could not reset review before editing.')); }
                PWF_History_Manager::record($id, 'approval_reset', 'approved', 'waiting_review', 'Native WooCommerce edit requested; approval reset before saving.');
                return array('event' => 'status_changed', 'from' => 'approved', 'to' => 'waiting_review');
            });
            if (is_wp_error($result)) { throw new WC_Data_Exception('pwf_review_reset_failed', $result->get_error_message()); }
        });
        // All publication for tracked products must pass the workflow review gate.
        add_filter('wp_insert_post_data', function ($data, $postarr) {
            if (self::$writing || $data['post_type'] !== 'product' || empty($postarr['ID'])) { return $data; }
            $row = self::get($postarr['ID']);
            if (!$row) { return $data; }
            $old = get_post_status($postarr['ID']);
            if (in_array($data['post_status'], array('publish', 'future'), true) && $old !== $data['post_status']) { $data['post_status'] = $old; }
            // Native withdrawal/trash is permitted, but withdraw the internal
            // handoff first so unpublished assets cannot remain completed.
            if ($old === 'publish' && $data['post_status'] !== 'publish' && in_array($row->current_status, array('published', 'ready_for_social', 'completed'), true)) {
                $id = (int) $postarr['ID'];
                $result = self::mutate($id, function ($fresh) use ($id) {
                    global $wpdb;
                    if ($wpdb->update($wpdb->prefix . 'pwf_workflows', array('current_status' => 'waiting_review', 'updated_at' => current_time('mysql', true)), array('product_id' => $id)) === false || $wpdb->update($wpdb->prefix . 'pwf_assignments', array('completed_at' => null), array('product_id' => $id, 'stage' => 'review')) === false) { throw new RuntimeException(pwf_t('Could not reopen review.')); }
                    PWF_History_Manager::record($id, 'publication_withdrawn', $fresh->current_status, 'waiting_review', 'Native unpublication or trash requested; social handoff withdrawn.');
                    return array('event' => 'status_changed', 'from' => $fresh->current_status, 'to' => 'waiting_review');
                });
                if (is_wp_error($result)) { $data['post_status'] = $old; }
            }
            return $data;
        }, 10, 2);
        add_action('woocommerce_update_product', function ($id) {
            if (self::$writing) { return; }
            $row = self::get($id);
            if (!$row) { return; }
            self::mutate($id, function ($fresh) use ($id) {
                global $wpdb;
                $to = $fresh->current_status === 'approved' ? 'waiting_review' : $fresh->current_status;
                if ($to !== $fresh->current_status) {
                    if ($wpdb->update($wpdb->prefix . 'pwf_workflows', array('current_status' => $to, 'updated_at' => current_time('mysql', true)), array('product_id' => $id)) === false) { throw new RuntimeException(pwf_t('Could not reset review.')); }
                    $wpdb->update($wpdb->prefix . 'pwf_assignments', array('completed_at' => null), array('product_id' => $id, 'stage' => 'review'));
                }
                PWF_History_Manager::record($id, 'woocommerce_edit', $fresh->current_status, $to, 'Product edited outside workflow form.' . ($to !== $fresh->current_status ? ' Approval reset; review again.' : ''));
                return array('event' => 'product_edited');
            });
        });
        // Cleanup workflow, assignments, and history when a product is permanently deleted.
        add_action('before_delete_post', function ($post_id) {
            if (get_post_type($post_id) !== 'product') { return; }
            global $wpdb;
            if ($wpdb) {
                if (method_exists($wpdb, 'delete')) {
                    $wpdb->delete($wpdb->prefix . 'pwf_workflows', array('product_id' => $post_id));
                    $wpdb->delete($wpdb->prefix . 'pwf_assignments', array('product_id' => $post_id));
                    $wpdb->delete($wpdb->prefix . 'pwf_history', array('product_id' => $post_id));
                } else {
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}pwf_workflows WHERE product_id=%d", $post_id));
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}pwf_assignments WHERE product_id=%d", $post_id));
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}pwf_history WHERE product_id=%d", $post_id));
                }
            }
        });
        // When a product is moved to trash, complete active assignments so they do not linger.
        add_action('trashed_post', function ($post_id) {
            if (get_post_type($post_id) !== 'product') { return; }
            global $wpdb;
            if ($wpdb) {
                $wpdb->update($wpdb->prefix . 'pwf_assignments', array('completed_at' => current_time('mysql', true)), array('product_id' => $post_id));
            }
        });
    }
}
