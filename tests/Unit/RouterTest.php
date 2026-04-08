<?php

function test_router(): void {
    TestCase::suite('URL Router');
    test_reset_globals();

    // Need hooks for router
    // Re-register the rewrite action
    add_action('generate_rewrite_rules', 'cr_apply_extra_rewrite_rules');

    // Test ?p=123
    $_SERVER['REQUEST_URI'] = '/?p=123';
    $router = new CR_Router();
    $vars = $router->parse_request();
    TestCase::assertEqual(123, $vars['p'], 'parse_request detects ?p=123');

    // Test ?page_id=5
    $_SERVER['REQUEST_URI'] = '/?page_id=5';
    $router = new CR_Router();
    $vars = $router->parse_request();
    TestCase::assertEqual(5, $vars['page_id'], 'parse_request detects ?page_id=5');

    // Test ?s=search
    $_SERVER['REQUEST_URI'] = '/?s=hello';
    $router = new CR_Router();
    $vars = $router->parse_request();
    TestCase::assertEqual('hello', $vars['s'], 'parse_request detects ?s=search');

    // Test /admin path
    $_SERVER['REQUEST_URI'] = '/admin/posts';
    $router = new CR_Router();
    $vars = $router->parse_request();
    TestCase::assertTrue($vars['_admin'] ?? false, 'parse_request routes /admin to _admin');

    // Test /wp-json path
    $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
    $router = new CR_Router();
    $vars = $router->parse_request();
    TestCase::assertTrue($vars['_rest_api'] ?? false, 'parse_request routes /wp-json to _rest_api');

    // Test /category/slug/
    $_SERVER['REQUEST_URI'] = '/category/news/';
    $router = new CR_Router();
    $vars = $router->parse_request();
    TestCase::assertEqual('news', $vars['category_name'] ?? '', 'parse_request routes /category/slug/');

    // Test /tag/slug/
    $_SERVER['REQUEST_URI'] = '/tag/php/';
    $router = new CR_Router();
    $vars = $router->parse_request();
    TestCase::assertEqual('php', $vars['tag'] ?? '', 'parse_request routes /tag/slug/');

    // Test /author/name/
    $_SERVER['REQUEST_URI'] = '/author/john/';
    $router = new CR_Router();
    $vars = $router->parse_request();
    TestCase::assertEqual('john', $vars['author_name'] ?? '', 'parse_request routes /author/name/');

    // Test date-based post URL
    $_SERVER['REQUEST_URI'] = '/2024/01/15/my-post/';
    $router = new CR_Router();
    $vars = $router->parse_request();
    TestCase::assertEqual('2024', $vars['year'] ?? '', 'parse_request routes year from date URL');
    TestCase::assertEqual('01', $vars['monthnum'] ?? '', 'parse_request routes month from date URL');
    TestCase::assertEqual('15', $vars['day'] ?? '', 'parse_request routes day from date URL');
    TestCase::assertEqual('my-post', $vars['name'] ?? '', 'parse_request routes slug from date URL');

    // Reset
    $_SERVER['REQUEST_URI'] = '/';
}
