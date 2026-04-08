<?php

function test_json_meta(): void {
    TestCase::suite('JSON Meta System');

    // Install the json_meta table
    $installed = cr_json_meta_install();
    TestCase::assertTrue($installed, 'cr_json_meta_install creates table');

    // Set full JSON meta for a post
    $data = [
        'settings' => ['featured' => true, 'color' => 'blue'],
        'seo' => ['title' => 'Custom SEO Title', 'description' => 'Meta desc'],
        'price' => 29.99,
        'tags' => ['php', 'cms', 'clean-room'],
    ];
    $result = cr_json_meta_set('post', 1, $data);
    TestCase::assertTrue($result, 'cr_json_meta_set stores JSON data');

    // Get all JSON meta
    $retrieved = cr_json_meta_get('post', 1);
    TestCase::assertIsArray($retrieved, 'cr_json_meta_get returns array');
    TestCase::assertEqual('blue', $retrieved['settings']['color'], 'Nested value preserved');
    TestCase::assertEqual(29.99, $retrieved['price'], 'Numeric value preserved');
    TestCase::assertCount(3, $retrieved['tags'], 'Array value preserved');

    // Get single value by path
    $val = cr_json_meta_get_value('post', 1, 'settings.color');
    TestCase::assertEqual('blue', $val, 'get_value returns nested value by dot path');

    $val = cr_json_meta_get_value('post', 1, 'seo.title');
    TestCase::assertEqual('Custom SEO Title', $val, 'get_value returns deep nested string');

    $val = cr_json_meta_get_value('post', 1, 'price');
    TestCase::assertEqual(29.99, (float) $val, 'get_value returns numeric value');

    // Get with default for missing path
    $val = cr_json_meta_get_value('post', 1, 'nonexistent.path', 'fallback');
    TestCase::assertEqual('fallback', $val, 'get_value returns default for missing path');

    // Update a specific path (atomic, no full read/write)
    $result = cr_json_meta_update('post', 1, 'settings.color', 'red');
    TestCase::assertTrue($result, 'cr_json_meta_update returns true');

    $val = cr_json_meta_get_value('post', 1, 'settings.color');
    TestCase::assertEqual('red', $val, 'Atomic update changed only the path');

    // Other values remain unchanged
    $val = cr_json_meta_get_value('post', 1, 'settings.featured');
    TestCase::assertNotNull($val, 'Other paths unchanged after atomic update');

    // Remove a path
    $result = cr_json_meta_remove('post', 1, 'seo.description');
    TestCase::assertTrue($result, 'cr_json_meta_remove returns true');

    $val = cr_json_meta_get_value('post', 1, 'seo.description', 'GONE');
    TestCase::assertEqual('GONE', $val, 'Removed path returns default');

    // seo.title still exists
    $val = cr_json_meta_get_value('post', 1, 'seo.title');
    TestCase::assertEqual('Custom SEO Title', $val, 'Sibling path preserved after remove');

    // Query by JSON value
    $ids = cr_json_meta_query('post', 'settings.color', 'red');
    TestCase::assertTrue(in_array(1, $ids), 'Query finds post with matching value');

    // Query with numeric comparison
    $ids = cr_json_meta_query('post', 'price', 20, '>');
    TestCase::assertTrue(in_array(1, $ids), 'Query with > operator works');

    // Set meta for another post
    cr_json_meta_set('post', 2, ['settings' => ['color' => 'green'], 'price' => 15]);

    // Bulk get
    $bulk = cr_json_meta_get_bulk('post', [1, 2, 999]);
    TestCase::assertIsArray($bulk, 'Bulk get returns array');
    TestCase::assertTrue(isset($bulk[1]), 'Bulk get includes post 1');
    TestCase::assertTrue(isset($bulk[2]), 'Bulk get includes post 2');
    TestCase::assertFalse(isset($bulk[999]), 'Bulk get skips nonexistent');

    // Convenience wrappers
    cr_post_json_set(3, ['mood' => 'happy']);
    $val = cr_post_json_get(3, 'mood');
    TestCase::assertEqual('happy', $val, 'cr_post_json_set/get convenience works');

    cr_post_json_set(3, 'mood', 'excited');
    TestCase::assertEqual('excited', cr_post_json_get(3, 'mood'), 'cr_post_json_set path update works');

    cr_post_json_remove(3, 'mood');
    TestCase::assertEqual('GONE', cr_post_json_get(3, 'mood', 'GONE'), 'cr_post_json_remove works');

    // User JSON meta
    cr_user_json_set(1, ['preferences' => ['theme' => 'dark', 'lang' => 'es']]);
    TestCase::assertEqual('dark', cr_user_json_get(1, 'preferences.theme'), 'User JSON meta works');

    // Delete all meta for an object
    $result = cr_json_meta_delete('post', 1);
    TestCase::assertTrue($result, 'cr_json_meta_delete returns true');
    $retrieved = cr_json_meta_get('post', 1);
    TestCase::assertEmpty($retrieved, 'Deleted meta returns empty');

    // Update on non-existent object creates it
    $result = cr_json_meta_update('post', 100, 'key', 'value');
    TestCase::assertTrue($result, 'Update on non-existent creates entry');
    TestCase::assertEqual('value', cr_json_meta_get_value('post', 100, 'key'), 'Created entry is readable');

    // Cleanup
    cr_json_meta_delete('post', 2);
    cr_json_meta_delete('post', 3);
    cr_json_meta_delete('post', 100);
    cr_json_meta_delete('user', 1);
}
