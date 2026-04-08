<?php

function test_vectors(): void {
    TestCase::suite('Vector Search Integration');
    test_reset_globals();
    cr_register_default_post_types();
    cr_register_default_taxonomies();
    cr_register_default_roles();

    global $cr_current_user;
    $cr_current_user = (object) ['ID' => 1];
    update_user_meta(1, cr_db()->prefix . 'capabilities', ['administrator' => true]);

    // Use a temp directory for vector storage
    $temp_dir = sys_get_temp_dir() . '/cr_vectors_test_' . uniqid();
    mkdir($temp_dir, 0755, true);

    // -- VectorStore direct usage --

    $store = new \PHPVectorStore\VectorStore($temp_dir, 8); // 8 dimensions for speed

    // Store vectors
    $store->set('test', 'doc1', [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8], ['title' => 'First']);
    $store->set('test', 'doc2', [0.8, 0.7, 0.6, 0.5, 0.4, 0.3, 0.2, 0.1], ['title' => 'Second']);
    $store->set('test', 'doc3', [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.9], ['title' => 'Third']);
    $store->flush();

    TestCase::assertEqual(3, $store->count('test'), 'VectorStore: 3 vectors stored');
    TestCase::assertTrue($store->has('test', 'doc1'), 'VectorStore: has doc1');

    // Search - doc3 should be most similar to doc1
    $results = $store->search('test', [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8], 3);
    TestCase::assertGreaterThan(0, count($results), 'VectorStore: search returns results');
    TestCase::assertEqual('doc1', $results[0]['id'], 'VectorStore: best match is doc1 (itself)');
    TestCase::assertEqual('doc3', $results[1]['id'], 'VectorStore: second match is doc3 (similar)');

    // Metadata preserved
    TestCase::assertEqual('First', $results[0]['metadata']['title'], 'VectorStore: metadata preserved');

    // Get single vector
    $doc = $store->get('test', 'doc1');
    TestCase::assertNotNull($doc, 'VectorStore: get returns document');
    TestCase::assertEqual('doc1', $doc['id'], 'VectorStore: get has correct ID');
    TestCase::assertNotEmpty($doc['vector'], 'VectorStore: get has vector');
    TestCase::assertEqual('First', $doc['metadata']['title'], 'VectorStore: get has metadata');

    // Remove
    $removed = $store->remove('test', 'doc2');
    TestCase::assertTrue($removed, 'VectorStore: remove returns true');
    TestCase::assertEqual(2, $store->count('test'), 'VectorStore: count after remove');
    TestCase::assertFalse($store->has('test', 'doc2'), 'VectorStore: doc2 gone');

    // Collections
    $store->set('other', 'x', [1,0,0,0,0,0,0,0], ['name' => 'X']);
    $store->flush();
    $collections = $store->collections();
    TestCase::assertTrue(in_array('test', $collections), 'VectorStore: collections includes test');
    TestCase::assertTrue(in_array('other', $collections), 'VectorStore: collections includes other');

    // Stats
    $stats = $store->stats();
    TestCase::assertGreaterThan(0, $stats['total_vectors'], 'VectorStore: stats has vectors');

    // IDs
    $ids = $store->ids('test');
    TestCase::assertCount(2, $ids, 'VectorStore: ids returns 2');
    TestCase::assertTrue(in_array('doc1', $ids), 'VectorStore: ids includes doc1');

    // -- BM25 full-text search --

    $bm25 = new \PHPVectorStore\BM25\Index();
    $bm25->addDocument('articles', 'a1', 'PHP is a popular server-side scripting language');
    $bm25->addDocument('articles', 'a2', 'JavaScript runs in the browser and on the server');
    $bm25->addDocument('articles', 'a3', 'PHP and JavaScript are both used for web development');

    TestCase::assertEqual(3, $bm25->count('articles'), 'BM25: 3 documents indexed');

    $results = $bm25->search('articles', 'PHP scripting', 3);
    TestCase::assertGreaterThan(0, count($results), 'BM25: search returns results');

    // PHP-focused docs should rank higher
    $top_ids = array_map(fn($r) => $r['id'], $results);
    TestCase::assertTrue(in_array('a1', $top_ids), 'BM25: PHP doc found');

    // -- HybridSearch --

    $hybrid_store = new \PHPVectorStore\VectorStore($temp_dir . '/hybrid', 4);
    $hybrid_bm25 = new \PHPVectorStore\BM25\Index();

    // Add docs with both vectors and text
    $hybrid_store->set('docs', 'd1', [1, 0, 0, 0], ['title' => 'PHP Guide']);
    $hybrid_store->set('docs', 'd2', [0, 1, 0, 0], ['title' => 'JS Guide']);
    $hybrid_store->set('docs', 'd3', [0.9, 0.1, 0, 0], ['title' => 'PHP Advanced']);
    $hybrid_store->flush();

    $hybrid_bm25->addDocument('docs', 'd1', 'PHP programming language server side');
    $hybrid_bm25->addDocument('docs', 'd2', 'JavaScript browser scripting');
    $hybrid_bm25->addDocument('docs', 'd3', 'Advanced PHP techniques and patterns');

    $hybrid = new \PHPVectorStore\HybridSearch($hybrid_store, $hybrid_bm25, \PHPVectorStore\HybridMode::RRF);
    $results = $hybrid->search('docs', [1, 0, 0, 0], 'PHP programming', 3);
    TestCase::assertGreaterThan(0, count($results), 'HybridSearch: returns results');

    $top = $results[0];
    TestCase::assertTrue($top['id'] === 'd1' || $top['id'] === 'd3', 'HybridSearch: PHP doc ranks top');

    // -- CR_Vectors integration --

    $vectors = new CR_Vectors();
    TestCase::assertInstanceOf(CR_Vectors::class, $vectors, 'CR_Vectors instantiates');

    // Stats (empty initially)
    $stats = $vectors->stats();
    TestCase::assertIsArray($stats, 'CR_Vectors: stats returns array');
    TestCase::assertIsInt($stats['dimensions'], 'CR_Vectors: stats has dimensions');
    TestCase::assertNotEmpty($stats['storage_path'], 'CR_Vectors: stats has storage_path');

    // Register vector abilities (need to re-register the hook since globals were reset)
    // The vector abilities are registered via add_action('cr_register_abilities', ...)
    // which fires inside cr_register_core_abilities(). But test_reset_globals cleared hooks.
    // So we need to re-require vectors.php hooks or register manually.

    // Re-attach the vector abilities hook
    add_action('cr_register_abilities', function () {
        register_ability('semantic_search', [
            'description' => 'Semantic search', 'category' => 'search', 'permission' => 'read',
            'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            'output_schema' => ['type' => 'object', 'properties' => ['results' => ['type' => 'array']]],
            'callback' => fn($i) => ['results' => [], 'count' => 0],
        ]);
        register_ability('find_similar_posts', [
            'description' => 'Find similar posts', 'category' => 'search', 'permission' => 'read',
            'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']],
            'output_schema' => ['type' => 'object', 'properties' => ['similar' => ['type' => 'array']]],
            'callback' => fn($i) => ['similar' => []],
        ]);
        register_ability('vector_stats', [
            'description' => 'Vector stats', 'category' => 'site', 'permission' => 'manage_options',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            'output_schema' => ['type' => 'object', 'properties' => ['dimensions' => ['type' => 'integer']]],
            'callback' => fn() => cr_vectors()->stats(),
        ]);
    });

    cr_register_core_abilities();

    TestCase::assertTrue(ability_exists('semantic_search'), 'Ability semantic_search registered');
    TestCase::assertTrue(ability_exists('find_similar_posts'), 'Ability find_similar_posts registered');
    TestCase::assertTrue(ability_exists('vector_stats'), 'Ability vector_stats registered');

    // Execute vector_stats ability
    $result = execute_ability('vector_stats', []);
    TestCase::assertIsInt($result['dimensions'], 'vector_stats returns dimensions');

    // -- Cleanup --
    $store->drop('test');
    $store->drop('other');
    $hybrid_store->drop('docs');

    // Remove temp directories
    $cleanup = function(string $dir) use (&$cleanup) {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $dir . '/' . $f;
            is_dir($path) ? $cleanup($path) : unlink($path);
        }
        rmdir($dir);
    };
    $cleanup($temp_dir);

    $cr_current_user = null;
    CR_Abilities::reset();
    $vectors->reset();
}
