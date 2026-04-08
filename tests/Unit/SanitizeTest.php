<?php

function test_sanitize(): void {
    TestCase::suite('Sanitize Functions');

    // Converts to lowercase
    TestCase::assertEqual('hello-world', cr_sanitize_title('Hello World'), 'cr_sanitize_title converts to lowercase');

    // Replaces spaces with hyphens
    TestCase::assertContains('-', cr_sanitize_title('two words'), 'cr_sanitize_title replaces spaces with hyphens');

    // Removes special characters
    $result = cr_sanitize_title('Hello! @World #2024');
    TestCase::assertFalse(str_contains($result, '!'), 'cr_sanitize_title removes !');
    TestCase::assertFalse(str_contains($result, '@'), 'cr_sanitize_title removes @');
    TestCase::assertFalse(str_contains($result, '#'), 'cr_sanitize_title removes #');

    // Returns 'untitled' for empty string
    TestCase::assertEqual('untitled', cr_sanitize_title(''), 'cr_sanitize_title returns untitled for empty');

    // Handles string with only special chars
    TestCase::assertEqual('untitled', cr_sanitize_title('!@#$%'), 'cr_sanitize_title returns untitled for only special chars');

    // Collapses multiple hyphens
    $result = cr_sanitize_title('hello   world');
    TestCase::assertFalse(str_contains($result, '--'), 'cr_sanitize_title collapses multiple hyphens');
}
