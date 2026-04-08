<?php

function test_rest_api(): void {
    TestCase::suite('REST API');
    test_reset_globals();
    cr_register_default_post_types();
    cr_register_default_taxonomies();
    cr_register_default_roles();

    // Set current user as admin for write operations
    global $cr_current_user;
    $cr_current_user = get_userdata(1);

    require_once CR_BASE_PATH . '/api/rest-api.php';
    $api = new CR_REST_API();

    // GET /cr/v1/posts
    $result = $api->get_posts(['post_type' => 'post', 'status' => 'publish']);
    TestCase::assertIsArray($result, 'GET posts returns array');
    TestCase::assertGreaterThan(0, count($result), 'GET posts returns results');

    // Verify post structure
    $first = $result[0];
    TestCase::assertTrue(isset($first['id']), 'Post has id field');
    TestCase::assertTrue(isset($first['title']), 'Post has title field');
    TestCase::assertTrue(isset($first['content']), 'Post has content field');
    TestCase::assertTrue(isset($first['slug']), 'Post has slug field');
    TestCase::assertTrue(isset($first['status']), 'Post has status field');

    // GET /cr/v1/posts/{id}
    $post = $api->get_post(['id' => $first['id']]);
    TestCase::assertIsArray($post, 'GET single post returns array');
    TestCase::assertEqual($first['id'], $post['id'], 'Single post has correct ID');

    // GET /cr/v1/pages
    $pages = $api->get_posts(['post_type' => 'page', 'status' => 'publish']);
    TestCase::assertIsArray($pages, 'GET pages returns array');
    $all_pages = true;
    foreach ($pages as $p) { if ($p['type'] !== 'page') $all_pages = false; }
    TestCase::assertTrue($all_pages || empty($pages), 'GET pages returns only pages');

    // GET categories
    $cats = $api->get_categories([]);
    TestCase::assertIsArray($cats, 'GET categories returns array');
    if (!empty($cats)) {
        TestCase::assertTrue(isset($cats[0]['id']), 'Category has id field');
        TestCase::assertTrue(isset($cats[0]['name']), 'Category has name field');
        TestCase::assertTrue(isset($cats[0]['slug']), 'Category has slug field');
    }

    // GET tags
    $tags = $api->get_tags([]);
    TestCase::assertIsArray($tags, 'GET tags returns array');

    // Search
    $search = $api->search(['search' => 'test', 'type' => ['post', 'page']]);
    TestCase::assertIsArray($search, 'Search returns array');

    // GET settings (requires admin)
    $settings = $api->get_settings([]);
    TestCase::assertIsArray($settings, 'GET settings returns array');
    TestCase::assertTrue(isset($settings['title']), 'Settings has title');
    TestCase::assertEqual('Test Site', $settings['title'], 'Settings title matches');

    // POST /cr/v1/posts - create post
    $new = $api->create_post([
        'title' => 'API Created Post',
        'content' => 'Created via REST API',
        'status' => 'publish',
    ]);
    TestCase::assertIsArray($new, 'POST create post returns array');
    TestCase::assertTrue(isset($new['id']), 'Created post has id');
    TestCase::assertEqual('API Created Post', $new['title']['rendered'], 'Created post has correct title');
    $created_id = $new['id'];

    // PUT /cr/v1/posts/{id} - update post
    $updated = $api->update_post([
        'id' => $created_id,
        'title' => 'Updated API Post',
    ]);
    TestCase::assertEqual('Updated API Post', $updated['title']['rendered'], 'PUT updates post title');

    // DELETE /cr/v1/posts/{id}
    $deleted = $api->delete_post_endpoint(['id' => $created_id, 'force' => true]);
    TestCase::assertTrue($deleted['deleted'] ?? false, 'DELETE returns deleted=true');

    // POST /cr/v1/categories - create category
    $cat = $api->create_category(['name' => 'API Category']);
    TestCase::assertIsArray($cat, 'POST create category returns array');
    TestCase::assertEqual('API Category', $cat['name'], 'Created category has correct name');

    // POST /cr/v1/tags - create tag
    $tag = $api->create_tag(['name' => 'api-tag']);
    TestCase::assertIsArray($tag, 'POST create tag returns array');
    TestCase::assertEqual('api-tag', $tag['name'], 'Created tag has correct name');

    // _fields parameter filters response
    $_GET['_fields'] = 'id,title';
    $filtered = $api->get_post(['id' => $first['id']]);
    TestCase::assertTrue(isset($filtered['id']), '_fields: id present');
    TestCase::assertTrue(isset($filtered['title']), '_fields: title present');
    TestCase::assertFalse(isset($filtered['content']), '_fields: content excluded');
    TestCase::assertFalse(isset($filtered['slug']), '_fields: slug excluded');
    unset($_GET['_fields']);

    // 404 for invalid post ID
    $not_found = $api->get_post(['id' => 999999]);
    TestCase::assertTrue(isset($not_found['code']), '404 returns error code for invalid ID');

    // Cleanup
    cr_delete_term($cat['id'], 'category');
    cr_delete_term($tag['id'], 'post_tag');
    $cr_current_user = null;
}
