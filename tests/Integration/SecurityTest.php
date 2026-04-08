<?php

function test_security(): void {
    TestCase::suite('Security System');
    CR_Security::reset();

    // -- Rate Limiting --

    // Allow requests under limit
    TestCase::assertTrue(CR_Security::rate_limit('test:ip1', 3, 60), 'First request allowed');
    TestCase::assertTrue(CR_Security::rate_limit('test:ip1', 3, 60), 'Second request allowed');
    TestCase::assertTrue(CR_Security::rate_limit('test:ip1', 3, 60), 'Third request allowed');

    // Block when limit reached
    TestCase::assertFalse(CR_Security::rate_limit('test:ip1', 3, 60), 'Fourth request blocked (rate limited)');

    // Different keys are independent
    TestCase::assertTrue(CR_Security::rate_limit('test:ip2', 3, 60), 'Different key has own counter');

    // Remaining count
    $remaining = CR_Security::rate_limit_remaining('test:ip1', 3, 60);
    TestCase::assertEqual(0, $remaining, 'Remaining is 0 when limit reached');

    $remaining = CR_Security::rate_limit_remaining('test:ip2', 3, 60);
    TestCase::assertEqual(2, $remaining, 'Remaining is 2 after 1 request');

    // Rate limit with short window expires
    CR_Security::reset();
    CR_Security::rate_limit('expire:test', 1, 1); // 1 request per 1 second
    TestCase::assertFalse(CR_Security::rate_limit('expire:test', 1, 1), 'Blocked within window');
    sleep(2);
    TestCase::assertTrue(CR_Security::rate_limit('expire:test', 1, 1), 'Allowed after window expires');

    // -- Login brute force --
    CR_Security::reset();

    // Not locked initially
    TestCase::assertFalse(CR_Security::is_login_locked('1.2.3.4'), 'Not locked initially');

    // Record failed attempts
    $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
    for ($i = 0; $i < 5; $i++) {
        CR_Security::record_failed_login('admin');
    }
    TestCase::assertTrue(CR_Security::is_login_locked('1.2.3.4'), 'Locked after 5 failed attempts');

    // Clear failed logins
    CR_Security::clear_failed_logins('1.2.3.4');
    TestCase::assertFalse(CR_Security::is_login_locked('1.2.3.4'), 'Unlocked after clear');

    // -- Input Sanitization --

    // Email
    TestCase::assertEqual('test@example.com', CR_Security::sanitize_email('  test@example.com  '), 'sanitize_email trims and validates');
    TestCase::assertEqual('', CR_Security::sanitize_email('not-an-email'), 'sanitize_email rejects invalid');

    // URL
    TestCase::assertEqual('https://example.com', CR_Security::sanitize_url('https://example.com'), 'sanitize_url allows https');
    TestCase::assertEqual('', CR_Security::sanitize_url('javascript:alert(1)'), 'sanitize_url rejects javascript:');
    TestCase::assertEqual('', CR_Security::sanitize_url('not-a-url'), 'sanitize_url rejects invalid');

    // HTML sanitization
    $dirty = '<p>Hello</p><script>alert("xss")</script><a onclick="steal()" href="https://ok.com">link</a>';
    $clean = CR_Security::sanitize_html($dirty, 'post');
    TestCase::assertFalse(str_contains($clean, '<script>'), 'sanitize_html removes script tags');
    TestCase::assertFalse(str_contains($clean, 'onclick'), 'sanitize_html removes event handlers');
    TestCase::assertTrue(str_contains($clean, '<p>Hello</p>'), 'sanitize_html preserves allowed tags');
    TestCase::assertTrue(str_contains($clean, '<a'), 'sanitize_html preserves a tags');

    // Comment context is more restrictive
    $clean = CR_Security::sanitize_html('<p>OK</p><div>nope</div><img src="x">', 'comment');
    TestCase::assertTrue(str_contains($clean, '<p>OK</p>'), 'Comment: p allowed');
    TestCase::assertFalse(str_contains($clean, '<div>'), 'Comment: div stripped');
    TestCase::assertFalse(str_contains($clean, '<img'), 'Comment: img stripped');

    // Title context strips everything
    $clean = CR_Security::sanitize_html('<b>Title</b> <script>bad</script>', 'title');
    TestCase::assertFalse(str_contains($clean, '<'), 'Title: all tags stripped');
    TestCase::assertContains('Title', $clean, 'Title: text preserved');

    // javascript: in href
    $dirty = '<a href="javascript:alert(1)">click</a>';
    $clean = CR_Security::sanitize_html($dirty, 'post');
    TestCase::assertFalse(str_contains($clean, 'javascript:'), 'sanitize_html removes javascript: from href');

    // -- CSP --
    $csp = CR_Security::default_csp();
    TestCase::assertContains("default-src 'self'", $csp, 'CSP includes default-src self');
    TestCase::assertContains("frame-ancestors 'self'", $csp, 'CSP includes frame-ancestors');

    // -- HTTPS detection --
    $_SERVER['HTTPS'] = 'on';
    TestCase::assertTrue(CR_Security::is_https(), 'is_https detects HTTPS=on');
    $_SERVER['HTTPS'] = 'off';
    TestCase::assertFalse(CR_Security::is_https(), 'is_https detects HTTPS=off');
    unset($_SERVER['HTTPS']);

    // -- Client IP --
    $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
    $ip = CR_Security::get_client_ip();
    TestCase::assertEqual('192.168.1.1', $ip, 'get_client_ip returns REMOTE_ADDR');

    CR_Security::reset();
}
