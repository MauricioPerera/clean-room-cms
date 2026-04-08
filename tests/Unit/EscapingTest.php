<?php

function test_escaping(): void {
    TestCase::suite('Escaping Functions');

    // esc_html escapes < > & "
    $result = esc_html('<script>alert("xss")</script>');
    TestCase::assertContains('&lt;', $result, 'esc_html escapes <');
    TestCase::assertContains('&gt;', $result, 'esc_html escapes >');
    TestCase::assertContains('&quot;', $result, 'esc_html escapes "');

    // esc_html handles empty string
    TestCase::assertEqual('', esc_html(''), 'esc_html handles empty string');

    // esc_attr escapes quotes
    $result = esc_attr('value with "quotes" & <tags>');
    TestCase::assertContains('&quot;', $result, 'esc_attr escapes double quotes');
    TestCase::assertContains('&amp;', $result, 'esc_attr escapes &');

    // esc_url allows http/https
    TestCase::assertEqual('https://example.com', esc_url('https://example.com'), 'esc_url allows https');
    TestCase::assertEqual('http://example.com', esc_url('http://example.com'), 'esc_url allows http');

    // esc_url handles empty string
    TestCase::assertEqual('', esc_url(''), 'esc_url handles empty string');

    // esc_url preserves relative path
    TestCase::assertEqual('/foo/bar', esc_url('/foo/bar'), 'esc_url preserves relative path');

    // esc_url preserves fragment
    TestCase::assertEqual('#section', esc_url('#section'), 'esc_url preserves fragment');
}
