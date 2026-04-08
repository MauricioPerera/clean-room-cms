<?php

function test_post_types(): void {
    TestCase::suite('Post Types System');
    test_reset_globals();

    // register_post_type registers type
    $result = register_post_type('book', ['label' => 'Books', 'public' => true]);
    TestCase::assertTrue($result, 'register_post_type returns true');

    // get_post_type_object returns object
    $obj = get_post_type_object('book');
    TestCase::assertNotNull($obj, 'get_post_type_object returns object');
    TestCase::assertEqual('Books', $obj->label, 'Post type has correct label');

    // post_type_exists returns true
    TestCase::assertTrue(post_type_exists('book'), 'post_type_exists returns true for registered type');
    TestCase::assertFalse(post_type_exists('nonexistent'), 'post_type_exists returns false for unregistered');

    // get_post_types returns list
    register_post_type('movie', ['label' => 'Movies', 'public' => true]);
    $types = get_post_types(['public' => true]);
    TestCase::assertTrue(in_array('book', $types), 'get_post_types includes book');
    TestCase::assertTrue(in_array('movie', $types), 'get_post_types includes movie');

    // cr_register_default_post_types
    cr_register_default_post_types();
    TestCase::assertTrue(post_type_exists('post'), 'Default post type registered');
    TestCase::assertTrue(post_type_exists('page'), 'Default page type registered');
    TestCase::assertTrue(post_type_exists('attachment'), 'Default attachment type registered');

    // cr_insert_post creates post
    $id = cr_insert_post([
        'post_title'  => 'My Test Post',
        'post_content' => 'Content here',
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'post_author'  => 1,
    ]);
    TestCase::assertNotEqual(false, $id, 'cr_insert_post returns ID');
    TestCase::assertGreaterThan(0, $id, 'cr_insert_post ID is positive');

    // cr_insert_post generates slug automatically
    $post = get_post($id);
    TestCase::assertEqual('my-test-post', $post->post_name, 'cr_insert_post generates slug from title');

    // cr_insert_post generates unique slug on duplicate
    $id2 = cr_insert_post([
        'post_title' => 'My Test Post', 'post_content' => 'Duplicate',
        'post_status' => 'publish', 'post_type' => 'post', 'post_author' => 1,
    ]);
    $post2 = get_post($id2);
    TestCase::assertNotEqual('my-test-post', $post2->post_name, 'Duplicate slug gets suffix');
    TestCase::assertContains('my-test-post', $post2->post_name, 'Unique slug based on original');

    // get_post returns post by ID
    $post = get_post($id);
    TestCase::assertNotNull($post, 'get_post returns post');
    TestCase::assertEqual('My Test Post', $post->post_title, 'get_post returns correct title');

    // cr_update_post modifies post
    cr_update_post(['ID' => $id, 'post_title' => 'Updated Title']);
    $post = get_post($id);
    TestCase::assertEqual('Updated Title', $post->post_title, 'cr_update_post modifies title');

    // cr_delete_post moves to trash
    cr_delete_post($id);
    $post = get_post($id);
    TestCase::assertEqual('trash', $post->post_status, 'cr_delete_post moves to trash');

    // cr_delete_post force delete
    cr_delete_post($id, true);
    $post = get_post($id);
    TestCase::assertNull($post, 'cr_delete_post force removes permanently');

    // get_posts returns filtered posts
    $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish']);
    TestCase::assertIsArray($posts, 'get_posts returns array');

    // cr_sanitize_title
    TestCase::assertEqual('hello-world', cr_sanitize_title('Hello World'), 'cr_sanitize_title works');

    // cr_unique_post_slug avoids collision
    $slug = cr_unique_post_slug('test-post', 'post');
    TestCase::assertIsString($slug, 'cr_unique_post_slug returns string');

    // current_time returns mysql format
    $time = current_time('mysql');
    TestCase::assertMatchesRegex('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time, 'current_time returns mysql format');

    // get_post_status
    $test_id = cr_insert_post(['post_title' => 'Status Test', 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => 1]);
    TestCase::assertEqual('draft', get_post_status($test_id), 'get_post_status returns correct status');

    // get_post_type
    TestCase::assertEqual('post', get_post_type($test_id), 'get_post_type returns correct type');

    // Cleanup
    cr_delete_post($id2, true);
    cr_delete_post($test_id, true);
}
