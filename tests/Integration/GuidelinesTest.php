<?php

function test_guidelines(): void {
    TestCase::suite('Content Guidelines');

    // Default guidelines are empty
    $g = cr_get_content_guidelines();
    TestCase::assertIsArray($g, 'get_content_guidelines returns array');
    TestCase::assertTrue(isset($g['site']), 'Has site section');
    TestCase::assertTrue(isset($g['copy']), 'Has copy section');
    TestCase::assertTrue(isset($g['images']), 'Has images section');
    TestCase::assertTrue(isset($g['blocks']), 'Has blocks section');
    TestCase::assertTrue(isset($g['additional']), 'Has additional section');

    // Update a section
    $result = cr_update_content_guidelines('copy', 'Write in a friendly, professional tone. Use short sentences.');
    TestCase::assertTrue($result, 'cr_update_content_guidelines returns true');

    $g = cr_get_content_guidelines();
    TestCase::assertContains('friendly', $g['copy'], 'Updated copy section saved');

    // Update multiple sections
    cr_update_content_guidelines('site', 'Tech blog for developers. Target: mid-level engineers.');
    cr_update_content_guidelines('images', 'Prefer screenshots and diagrams. No stock photos.');

    // Invalid section rejected
    $result = cr_update_content_guidelines('invalid_section', 'Should fail');
    TestCase::assertFalse($result, 'Invalid section rejected');

    // Set all at once
    $result = cr_set_content_guidelines([
        'site'       => 'New site description',
        'copy'       => 'New tone rules',
        'images'     => 'New image rules',
        'blocks'     => 'Paragraphs max 3 sentences',
        'additional' => 'Always cite sources',
    ]);
    TestCase::assertTrue($result, 'cr_set_content_guidelines works');

    $g = cr_get_content_guidelines();
    TestCase::assertEqual('New site description', $g['site'], 'Bulk set: site correct');
    TestCase::assertEqual('New tone rules', $g['copy'], 'Bulk set: copy correct');
    TestCase::assertEqual('Paragraphs max 3 sentences', $g['blocks'], 'Bulk set: blocks correct');

    // As system prompt
    $prompt = cr_guidelines_as_system_prompt();
    TestCase::assertIsString($prompt, 'System prompt is string');
    TestCase::assertContains('content guidelines', $prompt, 'System prompt has header');
    TestCase::assertContains('New site description', $prompt, 'System prompt includes site section');
    TestCase::assertContains('New tone rules', $prompt, 'System prompt includes copy section');
    TestCase::assertContains('Writing Style', $prompt, 'System prompt has section headers');

    // As structured data (for API/MCP)
    $structured = cr_guidelines_as_structured();
    TestCase::assertIsArray($structured, 'Structured guidelines is array');
    TestCase::assertGreaterThan(0, count($structured), 'Structured has sections');
    $first = $structured[0];
    TestCase::assertTrue(isset($first['section']), 'Structured has section key');
    TestCase::assertTrue(isset($first['content']), 'Structured has content key');
    TestCase::assertTrue(isset($first['description']), 'Structured has description key');

    // Empty guidelines produce empty prompt
    cr_set_content_guidelines(['site' => '', 'copy' => '', 'images' => '', 'blocks' => '', 'additional' => '']);
    $prompt = cr_guidelines_as_system_prompt();
    TestCase::assertEqual('', $prompt, 'Empty guidelines produce empty prompt');

    // Cleanup
    delete_option('cr_content_guidelines');
}
