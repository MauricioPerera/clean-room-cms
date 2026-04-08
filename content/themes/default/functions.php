<?php
/**
 * Clean Room Default Theme - Functions
 */

// Theme setup
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption']);
});

// Enqueue styles
add_action('cr_head', function () {
    cr_enqueue_style('cleanroom-default', cr_get_theme_url() . '/style.css', [], CR_VERSION);
});

// Simple auto-paragraph filter
add_filter('the_content', function (string $content): string {
    // Don't double-wrap if already has block-level HTML
    if (preg_match('/<(div|p|h[1-6]|ul|ol|table|blockquote|pre|figure)/i', $content)) {
        return $content;
    }
    $paragraphs = preg_split('/\n\s*\n/', $content);
    $paragraphs = array_filter(array_map('trim', $paragraphs));
    return implode("\n", array_map(fn($p) => "<p>{$p}</p>", $paragraphs));
}, 10);
