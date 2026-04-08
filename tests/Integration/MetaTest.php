<?php

function test_meta(): void {
    TestCase::suite('Meta API');

    // -- Post Meta --
    $mid = add_post_meta(1, 'test_key', 'test_value');
    TestCase::assertNotEqual(false, $mid, 'add_post_meta creates meta');

    $val = get_post_meta(1, 'test_key', true);
    TestCase::assertEqual('test_value', $val, 'get_post_meta returns value (single=true)');

    $vals = get_post_meta(1, 'test_key', false);
    TestCase::assertIsArray($vals, 'get_post_meta returns array (single=false)');
    TestCase::assertNotEmpty($vals, 'get_post_meta array is not empty');

    update_post_meta(1, 'test_key', 'updated_value');
    TestCase::assertEqual('updated_value', get_post_meta(1, 'test_key', true), 'update_post_meta updates value');

    delete_post_meta(1, 'test_key');
    TestCase::assertEqual('', get_post_meta(1, 'test_key', true), 'delete_post_meta removes meta');

    // add_post_meta with unique=true
    add_post_meta(1, 'unique_key', 'first', true);
    $result = add_post_meta(1, 'unique_key', 'second', true);
    TestCase::assertFalse($result, 'add_post_meta unique=true prevents duplicate');
    delete_post_meta(1, 'unique_key');

    // Meta with serialized array
    $arr = ['nested' => [1, 2, 3]];
    add_post_meta(1, 'array_meta', $arr);
    $retrieved = get_post_meta(1, 'array_meta', true);
    TestCase::assertIsArray($retrieved, 'Serialized array meta returns array');
    TestCase::assertEqual([1, 2, 3], $retrieved['nested'], 'Array meta preserves nested data');
    delete_post_meta(1, 'array_meta');

    // get_post_meta without key returns all meta
    add_post_meta(1, 'key_a', 'val_a');
    add_post_meta(1, 'key_b', 'val_b');
    $all = get_post_meta(1);
    TestCase::assertIsArray($all, 'get_post_meta without key returns array');
    TestCase::assertTrue(isset($all['key_a']), 'All meta contains key_a');
    TestCase::assertTrue(isset($all['key_b']), 'All meta contains key_b');
    delete_post_meta(1, 'key_a');
    delete_post_meta(1, 'key_b');

    // -- User Meta --
    $mid = add_user_meta(1, 'user_test', 'user_val');
    TestCase::assertNotEqual(false, $mid, 'add_user_meta creates meta');

    $val = get_user_meta(1, 'user_test', true);
    TestCase::assertEqual('user_val', $val, 'get_user_meta returns value');

    update_user_meta(1, 'user_test', 'updated_user');
    TestCase::assertEqual('updated_user', get_user_meta(1, 'user_test', true), 'update_user_meta updates');

    delete_user_meta(1, 'user_test');
    TestCase::assertEqual('', get_user_meta(1, 'user_test', true), 'delete_user_meta removes meta');

    // -- Term Meta --
    $mid = add_term_meta(1, 'term_test', 'term_val');
    TestCase::assertNotEqual(false, $mid, 'add_term_meta creates meta');

    $val = get_term_meta(1, 'term_test', true);
    TestCase::assertEqual('term_val', $val, 'get_term_meta returns value');
    delete_term_meta(1, 'term_test');

    // -- Comment Meta --
    $mid = add_comment_meta(1, 'comment_test', 'comment_val');
    TestCase::assertNotEqual(false, $mid, 'add_comment_meta creates meta');

    $val = get_comment_meta(1, 'comment_test', true);
    TestCase::assertEqual('comment_val', $val, 'get_comment_meta returns value');
    delete_comment_meta(1, 'comment_test');
}
