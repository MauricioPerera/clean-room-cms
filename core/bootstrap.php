<?php
/**
 * Clean Room CMS - Bootstrap
 *
 * Loads all core components in the correct order and runs the initialization sequence.
 */

// Error reporting
if (defined('CR_DEBUG') && CR_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', CR_DEBUG_DISPLAY ? '1' : '0');
    if (defined('CR_DEBUG_LOG') && CR_DEBUG_LOG) {
        ini_set('log_errors', '1');
        ini_set('error_log', CR_BASE_PATH . '/content/debug.log');
    }
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Version
define('CR_VERSION', '1.0.0');

// 1. Hook system (must be first - everything depends on it)
require_once CR_CORE_PATH . '/hooks.php';

// 2. Database layer
require_once CR_CORE_PATH . '/database.php';

// 3. Options API (needs DB + hooks)
require_once CR_CORE_PATH . '/options.php';

// 4. Meta API
require_once CR_CORE_PATH . '/meta.php';

// 5. User system (needs DB + options + meta)
require_once CR_CORE_PATH . '/user.php';

// 6. Post types (needs DB + hooks + meta)
require_once CR_CORE_PATH . '/post-types.php';

// 7. Taxonomies (needs DB + hooks)
require_once CR_CORE_PATH . '/taxonomies.php';

// 8. Query engine (needs post types + taxonomies)
require_once CR_CORE_PATH . '/query.php';

// 9. Rewrite rules
require_once CR_CORE_PATH . '/rewrite.php';

// 10. Router (needs rewrite)
require_once CR_CORE_PATH . '/router.php';

// 11. Template engine (needs query + options)
require_once CR_CORE_PATH . '/template.php';

// 12. Shortcodes
require_once CR_CORE_PATH . '/shortcodes.php';

// 13. Object Cache (LRU + namespaced options)
require_once CR_CORE_PATH . '/cache.php';

// 14. Plugin Sandbox
require_once CR_CORE_PATH . '/sandbox.php';

// 15. Security (headers, rate limiting, CSRF)
require_once CR_CORE_PATH . '/security.php';

// 16. JSON Meta (modern alternative to EAV postmeta)
require_once CR_CORE_PATH . '/jsonmeta.php';

// 17. Async Queue System
require_once CR_CORE_PATH . '/queue.php';

// 18. Vendor autoloader
$vendor_autoload = CR_BASE_PATH . '/vendor/autoload.php';
if (file_exists($vendor_autoload)) {
    require_once $vendor_autoload;
}

// 19. Content Builder (DB-driven content types, taxonomies, meta fields)
require_once CR_CORE_PATH . '/content-builder.php';

// 20. AI Subsystem
require_once CR_CORE_PATH . '/ai/client.php';
require_once CR_CORE_PATH . '/ai/abilities.php';
require_once CR_CORE_PATH . '/ai/guidelines.php';
require_once CR_CORE_PATH . '/ai/mcp.php';
require_once CR_CORE_PATH . '/ai/vectors.php';

/**
 * Initialize the CMS.
 */
function cr_bootstrap(): void {
    // Connect to database
    cr_db()->connect();

    // Check if installed
    if (!cr_is_installed()) {
        cr_redirect_to_installer();
        return;
    }

    // Load autoloaded options
    cr_load_autoloaded_options();

    // Register default roles
    cr_register_default_roles();

    // Load must-use plugins
    cr_load_mu_plugins();

    do_action('muplugins_loaded');

    // Load active plugins with sandbox enforcement
    cr_load_plugins_sandboxed();

    do_action('plugins_loaded');

    // Register default post types and taxonomies
    do_action('setup_theme');

    cr_register_default_post_types();
    cr_register_default_taxonomies();

    // Load theme functions.php
    cr_load_theme_functions();

    do_action('after_setup_theme');

    // Init hook - plugins register CPTs, taxonomies, etc. here
    do_action('init');

    // Initialize current user from auth cookie
    cr_init_current_user();

    // Install content builder tables (if missing)
    cr_content_builder_install();

    // Load DB-defined content types and taxonomies
    cr_load_db_content_types();
    cr_load_db_taxonomies();

    // Initialize AI connectors from settings
    cr_ai_init_connectors();

    // Register core abilities
    cr_register_core_abilities();

    // Register MCP routes
    cr_register_mcp_routes();

    do_action('wp_loaded');
}

function cr_is_installed(): bool {
    try {
        $db = cr_db();
        $result = $db->get_var("SELECT COUNT(*) FROM `{$db->prefix}options` WHERE option_name = 'siteurl'");
        return (int) $result > 0;
    } catch (Exception $e) {
        return false;
    }
}

function cr_redirect_to_installer(): void {
    $installer_path = CR_BASE_PATH . '/install/installer.php';
    if (file_exists($installer_path)) {
        require_once $installer_path;
        exit;
    }
    die('Clean Room CMS is not installed. Please run the installer.');
}

function cr_load_mu_plugins(): void {
    $mu_dir = CR_PLUGIN_PATH . '/mu';
    if (!is_dir($mu_dir)) return;

    $files = glob($mu_dir . '/*.php');
    foreach ($files as $file) {
        require_once $file;
    }
}

function cr_load_plugins(): void {
    $active_plugins = get_option('active_plugins', []);
    if (!is_array($active_plugins)) return;

    foreach ($active_plugins as $plugin_file) {
        $path = CR_PLUGIN_PATH . '/' . $plugin_file;
        if (file_exists($path)) {
            require_once $path;
        }
    }
}

function cr_load_theme_functions(): void {
    $theme_dir = cr_get_theme_directory();
    $functions_file = $theme_dir . '/functions.php';

    if (file_exists($functions_file)) {
        require_once $functions_file;
    }
}
