<?php

function test_template(): void {
    TestCase::suite('Template Engine');
    test_reset_globals();

    // cr_get_theme_directory returns correct path
    $dir = cr_get_theme_directory();
    TestCase::assertContains('content/themes/default', $dir, 'cr_get_theme_directory returns correct path');
    TestCase::assertTrue(is_dir($dir), 'Theme directory exists');

    // cr_get_theme_url returns correct URL
    $url = cr_get_theme_url();
    TestCase::assertContains('content/themes/default', $url, 'cr_get_theme_url returns correct URL');
    TestCase::assertContains('http', $url, 'Theme URL starts with http');

    // cr_locate_template finds existing template
    $path = cr_locate_template(['single.php']);
    TestCase::assertContains('single.php', $path, 'cr_locate_template finds single.php');
    TestCase::assertTrue(file_exists($path), 'Located template file exists');

    // cr_locate_template falls back to index.php
    $path = cr_locate_template(['nonexistent.php']);
    TestCase::assertContains('index.php', $path, 'cr_locate_template falls back to index.php');

    // Multiple template candidates - picks first existing
    $path = cr_locate_template(['nonexistent.php', 'also-missing.php', 'page.php']);
    TestCase::assertContains('page.php', $path, 'cr_locate_template picks first existing from list');

    // add_theme_support
    add_theme_support('post-thumbnails');
    TestCase::assertTrue(current_theme_supports('post-thumbnails'), 'add_theme_support registers feature');

    // current_theme_supports false for unsupported
    TestCase::assertFalse(current_theme_supports('custom-header'), 'current_theme_supports false for unsupported');

    // get_bloginfo name
    TestCase::assertEqual('Test Site', get_bloginfo('name'), 'get_bloginfo name returns site name');

    // get_bloginfo description
    TestCase::assertEqual('A test site', get_bloginfo('description'), 'get_bloginfo description returns tagline');

    // get_bloginfo url
    TestCase::assertEqual(CR_HOME_URL, get_bloginfo('url'), 'get_bloginfo url returns home URL');

    // cr_enqueue_style
    cr_enqueue_style('test-style', 'http://example.com/style.css', [], '1.0');
    global $cr_enqueued_styles;
    TestCase::assertTrue(isset($cr_enqueued_styles['test-style']), 'cr_enqueue_style registers style');
    TestCase::assertEqual('http://example.com/style.css', $cr_enqueued_styles['test-style']['src'], 'Enqueued style has correct src');

    // cr_enqueue_script
    cr_enqueue_script('test-script', 'http://example.com/app.js', [], '1.0', true);
    global $cr_enqueued_scripts;
    TestCase::assertTrue(isset($cr_enqueued_scripts['test-script']), 'cr_enqueue_script registers script');
    TestCase::assertTrue($cr_enqueued_scripts['test-script']['in_footer'], 'Script marked for footer');

    // body_class generates CSS classes
    cr_register_default_post_types();
    global $cr_query, $cr_options_cache;
    $cr_options_cache['show_on_front'] = 'posts';
    $cr_options_cache['page_on_front'] = '0';
    $cr_query = new CR_Query([]);
    ob_start();
    body_class();
    $output = ob_get_clean();
    TestCase::assertContains('class="', $output, 'body_class outputs class attribute');
    TestCase::assertContains('home', $output, 'body_class includes home class');
}
