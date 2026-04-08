<?php

function test_cache(): void {
    TestCase::suite('LRU Cache + Namespaced Options');

    $cache = CR_Cache::instance();
    $cache->reset();

    // Set and get
    $cache->set('test', 'key1', 'value1');
    TestCase::assertEqual('value1', $cache->get('test', 'key1'), 'Cache set/get works');

    // Get with default for missing key
    TestCase::assertEqual('default', $cache->get('test', 'missing', 'default'), 'Cache returns default for miss');

    // Exists check
    TestCase::assertTrue($cache->exists('test', 'key1'), 'exists returns true for cached key');
    TestCase::assertFalse($cache->exists('test', 'missing'), 'exists returns false for missing key');

    // Delete
    $cache->delete('test', 'key1');
    TestCase::assertFalse($cache->exists('test', 'key1'), 'delete removes cached key');

    // TTL expiration
    $cache->set('test', 'ttl_key', 'expires_soon', 1); // 1 second TTL
    TestCase::assertEqual('expires_soon', $cache->get('test', 'ttl_key'), 'TTL key readable before expiry');
    sleep(2);
    TestCase::assertEqual('gone', $cache->get('test', 'ttl_key', 'gone'), 'TTL key expired after sleep');

    // LRU eviction
    $small_cache = new CR_Cache(3); // Max 3 items per group
    $small_cache->set('g', 'a', 1);
    $small_cache->set('g', 'b', 2);
    $small_cache->set('g', 'c', 3);
    $small_cache->set('g', 'd', 4); // Should evict 'a' (LRU)
    TestCase::assertEqual('default', $small_cache->get('g', 'a', 'default'), 'LRU evicts oldest entry');
    TestCase::assertEqual(2, $small_cache->get('g', 'b'), 'Non-evicted entries remain');
    TestCase::assertEqual(4, $small_cache->get('g', 'd'), 'New entry accessible');

    // LRU: accessing an entry refreshes it
    $small_cache->reset();
    $small_cache->set('g', 'a', 1);
    $small_cache->set('g', 'b', 2);
    $small_cache->set('g', 'c', 3);
    $small_cache->get('g', 'a'); // Touch 'a' to make it most recent
    $small_cache->set('g', 'd', 4); // Should evict 'b' (now LRU)
    TestCase::assertEqual(1, $small_cache->get('g', 'a'), 'Touched entry not evicted');
    TestCase::assertEqual('gone', $small_cache->get('g', 'b', 'gone'), 'Untouched LRU entry evicted');

    // Flush group
    $cache->reset();
    $cache->set('group1', 'a', 1);
    $cache->set('group1', 'b', 2);
    $cache->set('group2', 'c', 3);
    $cache->flush_group('group1');
    TestCase::assertFalse($cache->exists('group1', 'a'), 'flush_group clears target group');
    TestCase::assertTrue($cache->exists('group2', 'c'), 'flush_group preserves other groups');

    // Stats
    $cache->reset();
    $cache->set('s', 'x', 1);
    $cache->get('s', 'x');      // hit
    $cache->get('s', 'x');      // hit
    $cache->get('s', 'miss');   // miss
    $stats = $cache->stats();
    TestCase::assertEqual(2, $stats['hits'], 'Stats track hits');
    TestCase::assertEqual(1, $stats['misses'], 'Stats track misses');
    TestCase::assertEqual(1, $stats['sets'], 'Stats track sets');

    // -- Namespaced plugin options --
    cr_plugin_option_set('my-plugin', 'version', '2.0');
    $val = cr_plugin_option_get('my-plugin', 'version');
    TestCase::assertEqual('2.0', $val, 'Plugin namespaced option set/get works');

    // Options are namespaced in DB
    $raw = get_option('plugin_my-plugin_version');
    TestCase::assertEqual('2.0', $raw, 'Options stored with namespace prefix');

    // Other plugins can't collide
    cr_plugin_option_set('other-plugin', 'version', '1.0');
    TestCase::assertEqual('2.0', cr_plugin_option_get('my-plugin', 'version'), 'Plugin options isolated');
    TestCase::assertEqual('1.0', cr_plugin_option_get('other-plugin', 'version'), 'Other plugin has own value');

    // Delete plugin option
    cr_plugin_option_delete('my-plugin', 'version');
    TestCase::assertFalse(cr_plugin_option_get('my-plugin', 'version', false), 'Plugin option deleted');

    // Cleanup all plugin options
    cr_plugin_option_set('cleanup-test', 'a', '1');
    cr_plugin_option_set('cleanup-test', 'b', '2');
    cr_plugin_option_set('cleanup-test', 'c', '3');
    $cleaned = cr_plugin_option_cleanup('cleanup-test');
    TestCase::assertEqual(3, $cleaned, 'cleanup removes all plugin options');

    // Cached query
    $results = cr_cached_query("SELECT option_name FROM `" . cr_db()->prefix . "options` LIMIT 3");
    TestCase::assertIsArray($results, 'cr_cached_query returns results');
    // Second call should hit cache
    $results2 = cr_cached_query("SELECT option_name FROM `" . cr_db()->prefix . "options` LIMIT 3");
    TestCase::assertEqual(count($results), count($results2), 'Cached query returns same results');

    // Cleanup
    cr_plugin_option_delete('other-plugin', 'version');
    $cache->reset();
}
