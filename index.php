<?php
/**
 * Clean Room CMS - Front Controller
 *
 * All requests are routed through this file.
 * Loads the CMS, parses the request, and renders the appropriate template.
 */

// Load configuration and bootstrap the CMS
require_once __DIR__ . '/wp-config.php';

// Initialize the system
cr_bootstrap();

// Parse the incoming request
$router = new CR_Router();
$query_vars = $router->parse_request();

// Handle admin requests
if (!empty($query_vars['_admin'])) {
    require_once CR_ADMIN_PATH . '/index.php';
    exit;
}

// Handle MCP requests
if (!empty($query_vars['_mcp'])) {
    do_action('cr_handle_mcp', $query_vars['_mcp_path'] ?? '');
    exit;
}

// Handle REST API requests
if (!empty($query_vars['_rest_api'])) {
    if (file_exists(CR_BASE_PATH . '/api/rest-api.php')) {
        require_once CR_BASE_PATH . '/api/rest-api.php';
        $rest = new CR_REST_API();
        $rest->serve($query_vars['_rest_path'] ?? '');
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['code' => 'rest_not_found', 'message' => 'REST API not available']);
    }
    exit;
}

// Handle 404 from router
if (!empty($query_vars['_404'])) {
    $query_vars = ['post_type' => 'post', 'post_status' => 'publish'];
    // Will result in is_404 = true if no posts match
}

// Clean internal vars
unset($query_vars['_admin'], $query_vars['_rest_api'], $query_vars['_rest_path'], $query_vars['_404'], $query_vars['_page_path']);

// Execute the main query
$main_query = new CR_Query($query_vars);
cr_set_main_query($main_query);

// Fire wp action
do_action('wp', $main_query);

// Resolve and load the template
$template = cr_resolve_template();
cr_load_template($template);

// Shutdown
do_action('shutdown');
