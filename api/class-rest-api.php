<?php
defined('ABSPATH') || exit;

class PWF_REST_API {
    public static function boot() {
        add_action('rest_api_init', function () {
            register_rest_route('product-workflow/v1', '/products', array(
                'methods' => 'POST',
                'permission_callback' => function () { return current_user_can('create_product_request') && current_user_can('view_assigned_products'); },
                'callback' => function ($request) { return PWF_Workflow_Manager::create($request->get_params()); },
            ));
            register_rest_route('product-workflow/v1', '/products/(?P<id>\d+)', array(
                'methods' => 'GET', 'permission_callback' => array(__CLASS__, 'can_view'),
                'callback' => function ($request) {
                    $id = absint($request['id']);
                    $result = array('workflow' => PWF_Workflow_Manager::get($id), 'assignments' => PWF_Assignment_Manager::all($id));
                    if (current_user_can('view_workflow_history')) { $result['history'] = PWF_History_Manager::recent($id, max(1, absint($request['history_page']))); }
                    return $result;
                },
            ));
            foreach (array('transition', 'assignment', 'content', 'images') as $operation) {
                register_rest_route('product-workflow/v1', '/products/(?P<id>\d+)/' . $operation, array(
                    'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'can_view'),
                    'callback' => function ($request) use ($operation) {
                        $id = absint($request['id']);
                        switch ($operation) {
                            case 'transition': return PWF_Workflow_Manager::transition($id, sanitize_key($request['to'] ?? ''), sanitize_key($request['expected'] ?? ''), sanitize_textarea_field($request['note'] ?? ''));
                            case 'assignment': return PWF_Assignment_Manager::assign($id, sanitize_key($request['stage'] ?? ''), absint($request['user_id']), sanitize_text_field($request['due_date'] ?? ''));
                            case 'content': return PWF_Workflow_Manager::save_content($id, $request->get_params());
                            case 'images': return PWF_Workflow_Manager::save_images($id, absint($request['main_image']), (array) $request['gallery']);
                        }
                    },
                ));
            }
            register_rest_route('product-workflow/v1', '/telegram/webhook', array(
                'methods'             => 'POST',
                'permission_callback' => '__return_true',
                'callback'            => function ($request) {
                    return PWF_Telegram::handle_webhook($request);
                },
            ));
        });
    }

    public static function can_view($request) {
        return PWF_Permission_Manager::can_view(absint($request['id'])) ? true : new WP_Error('rest_forbidden', pwf_t('You cannot access this workflow.'), array('status' => 403));
    }
}
