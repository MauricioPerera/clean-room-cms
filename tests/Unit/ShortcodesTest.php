<?php

function test_shortcodes(): void {
    TestCase::suite('Shortcodes System');
    global $cr_shortcode_tags;
    $cr_shortcode_tags = [];

    // add_shortcode registers tag
    add_shortcode('hello', function () { return 'Hello!'; });
    TestCase::assertTrue(isset($cr_shortcode_tags['hello']), 'add_shortcode registers tag');

    // shortcode_exists returns true for registered tag
    TestCase::assertTrue(shortcode_exists('hello'), 'shortcode_exists returns true for registered tag');

    // shortcode_exists returns false for unregistered tag
    TestCase::assertFalse(shortcode_exists('nonexistent'), 'shortcode_exists returns false for unregistered');

    // remove_shortcode removes tag
    add_shortcode('removeme', function () { return ''; });
    remove_shortcode('removeme');
    TestCase::assertFalse(shortcode_exists('removeme'), 'remove_shortcode removes tag');

    // do_shortcode processes [tag]
    $cr_shortcode_tags = [];
    add_shortcode('greet', function () { return 'Hi there'; });
    $result = do_shortcode('Before [greet] After');
    TestCase::assertContains('Hi there', $result, 'do_shortcode processes [tag]');

    // do_shortcode processes [tag attr="val"]
    $cr_shortcode_tags = [];
    add_shortcode('named', function ($atts) {
        return 'Name is ' . ($atts['name'] ?? 'unknown');
    });
    $result = do_shortcode('[named name="Alice"]');
    TestCase::assertContains('Name is Alice', $result, 'do_shortcode processes attributes');

    // do_shortcode processes [tag]content[/tag]
    $cr_shortcode_tags = [];
    add_shortcode('wrap', function ($atts, $content) {
        return '<b>' . $content . '</b>';
    });
    $result = do_shortcode('[wrap]Bold text[/wrap]');
    TestCase::assertEqual('<b>Bold text</b>', $result, 'do_shortcode processes enclosing shortcode');

    // do_shortcode ignores unregistered tags
    $cr_shortcode_tags = [];
    $result = do_shortcode('Text with [unknown] tag');
    TestCase::assertContains('[unknown]', $result, 'do_shortcode ignores unregistered tags');

    // shortcode_parse_atts parses double-quoted attributes
    $atts = shortcode_parse_atts('name="John" age="30"');
    TestCase::assertEqual('John', $atts['name'], 'parse_atts parses double-quoted attributes');
    TestCase::assertEqual('30', $atts['age'], 'parse_atts parses second attribute');

    // shortcode_parse_atts parses single-quoted attributes
    $atts = shortcode_parse_atts("color='red'");
    TestCase::assertEqual('red', $atts['color'], 'parse_atts parses single-quoted attributes');

    // shortcode_parse_atts parses unquoted attributes
    $atts = shortcode_parse_atts('count=5');
    TestCase::assertEqual('5', $atts['count'], 'parse_atts parses unquoted attributes');

    // shortcode_atts merges defaults with atts
    $result = shortcode_atts(
        ['color' => 'blue', 'size' => 'medium'],
        ['color' => 'red']
    );
    TestCase::assertEqual('red', $result['color'], 'shortcode_atts uses provided value');
    TestCase::assertEqual('medium', $result['size'], 'shortcode_atts uses default for missing');
}
