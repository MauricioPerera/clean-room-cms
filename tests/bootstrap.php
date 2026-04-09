<?php
/**
 * Clean Room CMS - Test Bootstrap
 *
 * Sets up test environment: defines constants, loads core, creates test DB.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Prevent headers already sent errors in test environment
ob_start();

// Simulate minimal $_SERVER for router tests
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Test database config
define('DB_NAME', 'cleanroom_test');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_ci');

// Keys
define('AUTH_KEY',         'test-auth-key-not-for-production');
define('SECURE_AUTH_KEY',  'test-secure-auth-key');
define('LOGGED_IN_KEY',    'test-logged-in-key');
define('NONCE_KEY',        'test-nonce-key');
define('AUTH_SALT',        'test-auth-salt');
define('SECURE_AUTH_SALT', 'test-secure-auth-salt');
define('LOGGED_IN_SALT',   'test-logged-in-salt');
define('NONCE_SALT',       'test-nonce-salt');

// Paths
define('CR_DEBUG', true);
define('CR_DEBUG_LOG', false);
define('CR_DEBUG_DISPLAY', false);
define('CR_SITE_URL', 'http://localhost:8080');
define('CR_HOME_URL', 'http://localhost:8080');
define('CR_BASE_PATH', dirname(__DIR__));
define('CR_CORE_PATH', CR_BASE_PATH . '/core');
define('CR_CONTENT_PATH', CR_BASE_PATH . '/content');
define('CR_ADMIN_PATH', CR_BASE_PATH . '/admin');
define('CR_PLUGIN_PATH', CR_CONTENT_PATH . '/plugins');
define('CR_THEME_PATH', CR_CONTENT_PATH . '/themes');
define('CR_UPLOAD_PATH', CR_CONTENT_PATH . '/uploads');
define('CR_CONTENT_URL', CR_SITE_URL . '/content');
define('CR_PLUGIN_URL', CR_CONTENT_URL . '/plugins');
define('CR_THEME_URL', CR_CONTENT_URL . '/themes');
define('CR_UPLOAD_URL', CR_CONTENT_URL . '/uploads');

$table_prefix = 'cr_';

// Load test framework
require_once __DIR__ . '/TestCase.php';

/**
 * Load only the core files needed (no bootstrap to avoid auto-init).
 */
function test_load_core(): void {
    require_once CR_CORE_PATH . '/hooks.php';
    require_once CR_CORE_PATH . '/database.php';
    require_once CR_CORE_PATH . '/options.php';
    require_once CR_CORE_PATH . '/meta.php';
    require_once CR_CORE_PATH . '/user.php';
    require_once CR_CORE_PATH . '/post-types.php';
    require_once CR_CORE_PATH . '/taxonomies.php';
    require_once CR_CORE_PATH . '/query.php';
    require_once CR_CORE_PATH . '/rewrite.php';
    require_once CR_CORE_PATH . '/router.php';
    require_once CR_CORE_PATH . '/template.php';
    require_once CR_CORE_PATH . '/shortcodes.php';
    require_once CR_CORE_PATH . '/cache.php';
    require_once CR_CORE_PATH . '/sandbox.php';
    require_once CR_CORE_PATH . '/security.php';
    require_once CR_CORE_PATH . '/jsonmeta.php';
    require_once CR_CORE_PATH . '/queue.php';
    require_once CR_CORE_PATH . '/ai/client.php';
    require_once CR_CORE_PATH . '/ai/abilities.php';
    require_once CR_CORE_PATH . '/ai/guidelines.php';
    require_once CR_CORE_PATH . '/ai/mcp.php';

    // Vendor autoloader
    $vendor = CR_BASE_PATH . '/vendor/autoload.php';
    if (file_exists($vendor)) {
        require_once $vendor;
    }

    require_once CR_CORE_PATH . '/template-engine.php';
    require_once CR_CORE_PATH . '/content-builder.php';
    require_once CR_CORE_PATH . '/ai/vectors.php';
}

/**
 * Create the test database and all tables.
 */
function test_setup_database(): bool {
    try {
        // Connect without database to create it
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("DROP DATABASE IF EXISTS `" . DB_NAME . "`");
        $pdo->exec("CREATE DATABASE `" . DB_NAME . "` CHARACTER SET " . DB_CHARSET . " COLLATE " . DB_COLLATE);
        $pdo = null;

        // Connect to the test database
        cr_db()->connect();

        // Disable strict mode for 0000-00-00 defaults
        cr_db()->query("SET SESSION sql_mode = ''");

        // Load and execute schema
        $schema = file_get_contents(CR_BASE_PATH . '/install/schema.sql');
        $schema = str_replace('{prefix}', cr_db()->prefix, $schema);

        // Remove SQL comments
        $schema = preg_replace('/^--.*$/m', '', $schema);

        $statements = array_filter(
            array_map('trim', explode(';', $schema)),
            fn($s) => !empty($s) && strlen($s) > 5
        );

        foreach ($statements as $sql) {
            cr_db()->query($sql);
        }

        return true;
    } catch (Exception $e) {
        echo "\033[31mDatabase setup failed: " . $e->getMessage() . "\033[0m\n";
        return false;
    }
}

/**
 * Seed test data: default options, admin user, sample post, category.
 */
function test_seed_data(): void {
    $db = cr_db();

    // Default options
    $options = [
        'siteurl' => CR_SITE_URL, 'home' => CR_HOME_URL,
        'blogname' => 'Test Site', 'blogdescription' => 'A test site',
        'admin_email' => 'admin@test.com', 'posts_per_page' => '10',
        'show_on_front' => 'posts', 'page_on_front' => '0',
        'stylesheet' => 'default', 'template' => 'default',
        'gmt_offset' => '0', 'cr_locale' => 'en-US',
        'active_plugins' => serialize([]),
        'date_format' => 'F j, Y', 'time_format' => 'g:i a',
        'permalink_structure' => '/%postname%/',
    ];
    foreach ($options as $name => $value) {
        $db->insert($db->prefix . 'options', [
            'option_name' => $name, 'option_value' => $value, 'autoload' => 'yes',
        ]);
    }

    // Admin user (password: test123)
    $hash = password_hash('test123', PASSWORD_BCRYPT);
    $db->insert($db->prefix . 'users', [
        'user_login' => 'admin', 'user_pass' => $hash,
        'user_nicename' => 'admin', 'user_email' => 'admin@test.com',
        'user_url' => '', 'user_registered' => gmdate('Y-m-d H:i:s'),
        'user_activation_key' => '', 'user_status' => 0, 'display_name' => 'Admin',
    ]);
    $db->insert($db->prefix . 'usermeta', [
        'user_id' => 1, 'meta_key' => $db->prefix . 'capabilities',
        'meta_value' => serialize(['administrator' => true]),
    ]);
    $db->insert($db->prefix . 'usermeta', [
        'user_id' => 1, 'meta_key' => $db->prefix . 'user_level', 'meta_value' => '10',
    ]);

    // Default category
    $db->insert($db->prefix . 'terms', ['name' => 'Uncategorized', 'slug' => 'uncategorized', 'term_group' => 0]);
    $db->insert($db->prefix . 'term_taxonomy', [
        'term_id' => 1, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1,
    ]);

    // Sample post
    $now = gmdate('Y-m-d H:i:s');
    $db->insert($db->prefix . 'posts', [
        'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now,
        'post_content' => 'This is test post content with some words for searching.',
        'post_title' => 'Test Post', 'post_excerpt' => 'Test excerpt',
        'post_status' => 'publish', 'comment_status' => 'open', 'ping_status' => 'open',
        'post_password' => '', 'post_name' => 'test-post', 'to_ping' => '', 'pinged' => '',
        'post_modified' => $now, 'post_modified_gmt' => $now, 'post_content_filtered' => '',
        'post_parent' => 0, 'guid' => CR_SITE_URL . '/?p=1', 'menu_order' => 0,
        'post_type' => 'post', 'post_mime_type' => '', 'comment_count' => 0,
    ]);
    $db->insert($db->prefix . 'term_relationships', [
        'object_id' => 1, 'term_taxonomy_id' => 1, 'term_order' => 0,
    ]);

    // Sample page
    $db->insert($db->prefix . 'posts', [
        'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now,
        'post_content' => 'This is a test page.', 'post_title' => 'Test Page',
        'post_excerpt' => '', 'post_status' => 'publish', 'comment_status' => 'closed',
        'ping_status' => 'closed', 'post_password' => '', 'post_name' => 'test-page',
        'to_ping' => '', 'pinged' => '', 'post_modified' => $now, 'post_modified_gmt' => $now,
        'post_content_filtered' => '', 'post_parent' => 0,
        'guid' => CR_SITE_URL . '/?page_id=2', 'menu_order' => 0,
        'post_type' => 'page', 'post_mime_type' => '', 'comment_count' => 0,
    ]);

    // Sample comment
    $db->insert($db->prefix . 'comments', [
        'comment_post_ID' => 1, 'comment_author' => 'Tester',
        'comment_author_email' => 'tester@test.com', 'comment_author_url' => '',
        'comment_author_IP' => '127.0.0.1', 'comment_date' => $now,
        'comment_date_gmt' => $now, 'comment_content' => 'A test comment.',
        'comment_karma' => 0, 'comment_approved' => '1', 'comment_agent' => 'Test',
        'comment_type' => 'comment', 'comment_parent' => 0, 'user_id' => 0,
    ]);
}

/**
 * Clean up: drop test database.
 */
function test_teardown_database(): void {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
            DB_USER, DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("DROP DATABASE IF EXISTS `" . DB_NAME . "`");
    } catch (Exception $e) {
        // Ignore cleanup errors
    }
}

/**
 * Reset global state between test suites.
 */
function test_reset_globals(): void {
    global $cr_filters, $cr_actions_run, $cr_current_filter;
    global $cr_post_types, $cr_taxonomies, $cr_roles;
    global $cr_shortcode_tags, $cr_options_cache, $cr_options_loaded;
    global $cr_enqueued_styles, $cr_enqueued_scripts, $cr_theme_support;
    global $cr_query, $cr_post, $cr_current_user;
    global $cr_extra_rewrite_rules;

    $cr_filters = [];
    $cr_actions_run = [];
    $cr_current_filter = [];
    $cr_post_types = [];
    $cr_taxonomies = [];
    $cr_shortcode_tags = [];
    $cr_options_cache = [];
    $cr_options_loaded = false;
    $cr_enqueued_styles = [];
    $cr_enqueued_scripts = [];
    $cr_theme_support = [];
    $cr_query = null;
    $cr_post = null;
    $cr_current_user = null;
    $cr_extra_rewrite_rules = [];
}
