<?php

function test_options(): void {
    TestCase::suite('Options API');
    test_reset_globals();

    // add_option creates new option
    $result = add_option('test_opt', 'value1');
    TestCase::assertTrue($result, 'add_option creates new option');

    // get_option returns value
    $val = get_option('test_opt');
    TestCase::assertEqual('value1', $val, 'get_option returns stored value');

    // add_option does not overwrite existing
    $result = add_option('test_opt', 'value2');
    TestCase::assertFalse($result, 'add_option does not overwrite existing');
    TestCase::assertEqual('value1', get_option('test_opt'), 'Original value preserved');

    // get_option returns default if not exists
    $val = get_option('nonexistent_opt', 'my_default');
    TestCase::assertEqual('my_default', $val, 'get_option returns default if not exists');

    // update_option updates value
    $result = update_option('test_opt', 'updated_value');
    TestCase::assertTrue($result, 'update_option returns true');
    TestCase::assertEqual('updated_value', get_option('test_opt'), 'update_option updates value');

    // update_option creates if not exists
    $result = update_option('new_opt_via_update', 'created');
    TestCase::assertTrue($result, 'update_option creates if not exists');
    TestCase::assertEqual('created', get_option('new_opt_via_update'), 'Value created via update_option');

    // delete_option removes option
    $result = delete_option('test_opt');
    TestCase::assertTrue($result, 'delete_option returns true');
    TestCase::assertFalse(get_option('test_opt', false), 'Deleted option returns default');

    // Options with serialized array
    $arr = ['key1' => 'val1', 'key2' => [1, 2, 3]];
    add_option('array_opt', $arr);
    $retrieved = get_option('array_opt');
    TestCase::assertIsArray($retrieved, 'Serialized array option returns array');
    TestCase::assertEqual('val1', $retrieved['key1'], 'Array option preserves values');
    TestCase::assertEqual([1, 2, 3], $retrieved['key2'], 'Array option preserves nested array');

    // Options with serialized object
    $obj = (object) ['foo' => 'bar', 'num' => 42];
    add_option('obj_opt', $obj);
    $retrieved = get_option('obj_opt');
    TestCase::assertIsObject($retrieved, 'Serialized object option returns object');
    TestCase::assertEqual('bar', $retrieved->foo, 'Object option preserves values');

    // Autoload loads options into cache
    global $cr_options_cache, $cr_options_loaded;
    $cr_options_cache = [];
    $cr_options_loaded = false;
    cr_load_autoloaded_options();
    TestCase::assertNotEmpty($cr_options_cache, 'Autoload populates cache');
    TestCase::assertTrue(array_key_exists('blogname', $cr_options_cache), 'Autoload includes blogname');

    // Filter pre_option_ intercepts read
    test_reset_globals();
    add_filter('pre_option_intercepted', function () { return 'intercepted_value'; });
    $val = get_option('intercepted');
    TestCase::assertEqual('intercepted_value', $val, 'pre_option_ filter intercepts read');

    // Filter pre_update_option_ intercepts write
    test_reset_globals();
    add_filter('pre_update_option_modified_opt', function ($value) { return $value . '_modified'; });
    add_option('modified_opt', 'original');
    // Reset cache to force re-read
    global $cr_options_cache;
    unset($cr_options_cache['modified_opt']);
    update_option('modified_opt', 'new');
    $val = get_option('modified_opt');
    TestCase::assertEqual('new_modified', $val, 'pre_update_option_ filter intercepts write');

    // Cleanup
    delete_option('new_opt_via_update');
    delete_option('array_opt');
    delete_option('obj_opt');
    delete_option('modified_opt');
}
