<?php
defined('ABSPATH') || exit;

/**
 * PWF_Telegram_Product
 *
 * Microservice handling WooCommerce product creation, taxonomy assignments,
 * custom attributes (Carton Quantity, CBM), description and pricing updates.
 */
class PWF_Telegram_Product {
    public static function resolve_category_input($text) {
        $cat_input = preg_replace('/^📁\s*/u', '', $text);
        $term = function_exists('get_term_by') ? get_term_by('name', $cat_input, 'product_cat') : null;
        if (!$term && function_exists('get_term_by')) {
            $term = get_term_by('slug', sanitize_title($cat_input), 'product_cat');
        }
        if ($term && !is_wp_error($term)) {
            return array('id' => (int) $term->term_id, 'name' => $term->name);
        }

        if (function_exists('wp_insert_term')) {
            $inserted = wp_insert_term($cat_input, 'product_cat');
            if (!is_wp_error($inserted) && !empty($inserted['term_id'])) {
                return array('id' => (int) $inserted['term_id'], 'name' => $cat_input);
            }
        }

        return array('id' => 0, 'name' => $cat_input);
    }

    public static function create_from_session_data(array $sess_data, $user_id) {
        if (function_exists('wp_set_current_user') && $user_id) {
            wp_set_current_user($user_id);
        }

        $res = PWF_Workflow_Manager::create(array(
            'name'                => $sess_data['name'],
            'sku'                 => $sess_data['sku'] ?? '',
            'description'         => $sess_data['description'] ?? '',
            'category_id'         => $sess_data['category_id'] ?? 0,
            'category_name'       => $sess_data['category_name'] ?? '',
            'carton_qty'          => $sess_data['carton_qty'] ?? '',
            'cbm'                 => $sess_data['cbm'] ?? '',
            'silent_notification' => true,
        ));

        if (is_wp_error($res)) {
            return $res;
        }

        $product_id = (int) $res->product_id;

        // Assign WooCommerce Category
        if (!empty($sess_data['category_id'])) {
            if (function_exists('wp_set_object_terms')) {
                wp_set_object_terms($product_id, (int) $sess_data['category_id'], 'product_cat');
            }
        } elseif (!empty($sess_data['category_name'])) {
            if (function_exists('wp_set_object_terms')) {
                wp_set_object_terms($product_id, sanitize_text_field($sess_data['category_name']), 'product_cat');
            }
        }

        // Assign WooCommerce Attributes: تعداد در کارتن and CBM
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if ($product) {
            $attributes = class_exists('WC_Product_Attribute') && method_exists($product, 'get_attributes') ? ((array) $product->get_attributes()) : array();
            $pos = 0;

            if (!empty($sess_data['carton_qty'])) {
                $carton_val = sanitize_text_field($sess_data['carton_qty']);
                if (function_exists('update_post_meta')) {
                    update_post_meta($product_id, '_pwf_carton_qty', $carton_val);
                    update_post_meta($product_id, 'carton_qty', $carton_val);
                }
                if (class_exists('WC_Product_Attribute')) {
                    $attr_carton = new WC_Product_Attribute();
                    $attr_carton->set_name('تعداد در کارتن');
                    $attr_carton->set_options(array($carton_val));
                    if (method_exists($attr_carton, 'set_position')) {
                        $attr_carton->set_position($pos++);
                    }
                    $attr_carton->set_visible(true);
                    if (method_exists($attr_carton, 'set_variation')) {
                        $attr_carton->set_variation(false);
                    }
                    $attributes['carton_qty'] = $attr_carton;
                }
            }

            if (!empty($sess_data['cbm'])) {
                $cbm_val = sanitize_text_field($sess_data['cbm']);
                if (function_exists('update_post_meta')) {
                    update_post_meta($product_id, '_pwf_cbm', $cbm_val);
                    update_post_meta($product_id, 'cbm', $cbm_val);
                }
                if (class_exists('WC_Product_Attribute')) {
                    $attr_cbm = new WC_Product_Attribute();
                    $attr_cbm->set_name('CBM');
                    $attr_cbm->set_options(array($cbm_val));
                    if (method_exists($attr_cbm, 'set_position')) {
                        $attr_cbm->set_position($pos++);
                    }
                    $attr_cbm->set_visible(true);
                    if (method_exists($attr_cbm, 'set_variation')) {
                        $attr_cbm->set_variation(false);
                    }
                    $attributes['cbm'] = $attr_cbm;
                }
            }

            if (!empty($attributes) && method_exists($product, 'set_attributes')) {
                $product->set_attributes($attributes);
                $product->save();
            }
        }

        return $product_id;
    }

    public static function update_description($product_id, $text) {
        $prod = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if ($prod && method_exists($prod, 'set_description')) {
            $prod->set_description(wp_kses_post($text));
            $prod->save();
            return true;
        }
        return false;
    }

    public static function update_price($product_id, $price) {
        $prod = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if ($prod && method_exists($prod, 'set_regular_price')) {
            $prod->set_regular_price($price);
            if (method_exists($prod, 'set_price')) {
                $prod->set_price($price);
            }
            $prod->save();
            return true;
        }
        return false;
    }
}
