<?php
/**
 * Clean Room CMS - Test Runner
 *
 * Executes the full test battery: unit tests, integration tests, and API tests.
 * Usage: php tests/run.php
 */

$start_time = microtime(true);

echo "\033[1;33m";
echo "╔═══════════════════════════════════════════════╗\n";
echo "║   Clean Room CMS - Full Test Battery          ║\n";
echo "╚═══════════════════════════════════════════════╝\n";
echo "\033[0m\n";

// Load bootstrap
require_once __DIR__ . '/bootstrap.php';

// Load core
echo "\033[1mLoading core...\033[0m\n";
test_load_core();
echo "\033[32m  Core loaded.\033[0m\n";

// ==========================================
// PHASE 1: Unit Tests (no database needed)
// ==========================================
echo "\n\033[1;33m--- PHASE 1: Unit Tests ---\033[0m\n";

require_once __DIR__ . '/Unit/HooksTest.php';
require_once __DIR__ . '/Unit/SerializationTest.php';
require_once __DIR__ . '/Unit/ShortcodesTest.php';
require_once __DIR__ . '/Unit/EscapingTest.php';
require_once __DIR__ . '/Unit/SanitizeTest.php';
require_once __DIR__ . '/Unit/RouterTest.php';

test_hooks();
test_serialization();
test_shortcodes();
test_escaping();
test_sanitize();
test_router();

$unit_passed = TestCase::$passed;
$unit_failed = TestCase::$failed;
$unit_total = TestCase::$total;

// ==========================================
// PHASE 2: Integration Tests (need database)
// ==========================================
echo "\n\033[1;33m--- PHASE 2: Integration Tests ---\033[0m\n";
echo "\033[1mSetting up test database...\033[0m\n";

$db_ok = test_setup_database();

if ($db_ok) {
    echo "\033[32m  Database ready.\033[0m\n";
    echo "\033[1mSeeding test data...\033[0m\n";
    test_seed_data();
    echo "\033[32m  Data seeded.\033[0m\n";

    // Load autoloaded options for integration tests
    cr_load_autoloaded_options();

    require_once __DIR__ . '/Integration/DatabaseTest.php';
    require_once __DIR__ . '/Integration/OptionsTest.php';
    require_once __DIR__ . '/Integration/MetaTest.php';
    require_once __DIR__ . '/Integration/PostTypesTest.php';
    require_once __DIR__ . '/Integration/TaxonomiesTest.php';
    require_once __DIR__ . '/Integration/QueryTest.php';
    require_once __DIR__ . '/Integration/UserTest.php';
    require_once __DIR__ . '/Integration/TemplateTest.php';

    test_database();
    test_options();
    test_meta();
    test_post_types();
    test_taxonomies();
    test_query();
    test_user();
    test_template();

    // ==========================================
    // PHASE 2b: Enhancement Tests (new features)
    // ==========================================
    echo "\n\033[1;33m--- PHASE 2b: Enhancement Tests ---\033[0m\n";

    require_once __DIR__ . '/Integration/SandboxTest.php';
    require_once __DIR__ . '/Integration/JsonMetaTest.php';
    require_once __DIR__ . '/Integration/CacheTest.php';
    require_once __DIR__ . '/Integration/SecurityTest.php';
    require_once __DIR__ . '/Integration/QueueTest.php';

    test_sandbox();
    test_json_meta();
    test_cache();
    test_security();
    test_queue();

    // ==========================================
    // PHASE 2c: AI Feature Tests
    // ==========================================
    echo "\n\033[1;33m--- PHASE 2c: AI Feature Tests ---\033[0m\n";

    require_once __DIR__ . '/Integration/AIClientTest.php';
    require_once __DIR__ . '/Integration/AbilitiesTest.php';
    require_once __DIR__ . '/Integration/GuidelinesTest.php';
    require_once __DIR__ . '/Integration/MCPTest.php';

    test_ai_client();
    test_abilities();
    test_guidelines();
    test_mcp();

    require_once __DIR__ . '/Integration/VectorsTest.php';
    test_vectors();

    // ==========================================
    // PHASE 3: API Tests (need database + REST API)
    // ==========================================
    echo "\n\033[1;33m--- PHASE 3: API Tests ---\033[0m\n";

    require_once __DIR__ . '/API/RestApiTest.php';
    test_rest_api();

    // Cleanup
    echo "\n\033[1mCleaning up test database...\033[0m\n";
    test_teardown_database();
    echo "\033[32m  Cleanup done.\033[0m\n";
} else {
    echo "\033[31m  Skipping integration and API tests (database unavailable).\033[0m\n";
    echo "\033[31m  Make sure MySQL/MariaDB is running!\033[0m\n";
}

// ==========================================
// Summary
// ==========================================
$elapsed = round(microtime(true) - $start_time, 2);
$_test_total_suites = 0;

// Count suites
$suites = ['Hooks System', 'Serialization Helpers', 'Shortcodes System', 'Escaping Functions',
           'Sanitize Functions', 'URL Router'];
$_test_total_suites = count($suites);
if ($db_ok) {
    $db_suites = ['Database Layer', 'Options API', 'Meta API', 'Post Types System',
                  'Taxonomy System', 'Query Engine', 'User System', 'Template Engine',
                  'Plugin Sandbox', 'JSON Meta System', 'LRU Cache + Namespaced Options',
                  'Security System', 'Async Queue System',
                  'AI Client SDK', 'Abilities API', 'Content Guidelines', 'MCP Adapter', 'Vector Search Integration',
                  'REST API'];
    $_test_total_suites += count($db_suites);
}

$GLOBALS['_test_total_suites'] = $_test_total_suites;

echo "\n\033[1mTime: {$elapsed}s\033[0m\n";
TestCase::summary();

// Exit code
exit(TestCase::$failed > 0 ? 1 : 0);
