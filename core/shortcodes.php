<?php
/**
 * Clean Room CMS - Shortcode System
 *
 * Allows [shortcode] syntax in post content, processed before output.
 */

$cr_shortcode_tags = [];

function add_shortcode(string $tag, callable $callback): void {
    global $cr_shortcode_tags;
    $cr_shortcode_tags[$tag] = $callback;
}

function remove_shortcode(string $tag): void {
    global $cr_shortcode_tags;
    unset($cr_shortcode_tags[$tag]);
}

function shortcode_exists(string $tag): bool {
    global $cr_shortcode_tags;
    return isset($cr_shortcode_tags[$tag]);
}

function do_shortcode(string $content): string {
    global $cr_shortcode_tags;

    if (empty($cr_shortcode_tags)) {
        return $content;
    }

    $tags = array_keys($cr_shortcode_tags);
    $tagnames = implode('|', array_map('preg_quote', $tags));

    $pattern = '/\[(' . $tagnames . ')(\s[^\]]*?)?\](?:(.+?)\[\/\1\])?/s';

    return preg_replace_callback($pattern, 'cr_do_shortcode_tag', $content);
}

function cr_do_shortcode_tag(array $matches): string {
    global $cr_shortcode_tags;

    $tag = $matches[1];
    $attr_string = $matches[2] ?? '';
    $content = $matches[3] ?? null;

    if (!isset($cr_shortcode_tags[$tag])) {
        return $matches[0];
    }

    $attr = shortcode_parse_atts(trim($attr_string));

    return (string) call_user_func($cr_shortcode_tags[$tag], $attr, $content, $tag);
}

function shortcode_parse_atts(string $text): array {
    $atts = [];

    if (empty($text)) {
        return $atts;
    }

    $pattern = '/(\w+)\s*=\s*"([^"]*)"(?:\s|$)|(\w+)\s*=\s*\'([^\']*)\'(?:\s|$)|(\w+)\s*=\s*(\S+)(?:\s|$)/';

    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            if (!empty($m[1])) {
                $atts[strtolower($m[1])] = $m[2];
            } elseif (!empty($m[3])) {
                $atts[strtolower($m[3])] = $m[4];
            } elseif (!empty($m[5])) {
                $atts[strtolower($m[5])] = $m[6];
            }
        }
    }

    return $atts;
}

function shortcode_atts(array $defaults, array $atts, string $shortcode = ''): array {
    $result = $defaults;

    foreach ($atts as $key => $value) {
        if (array_key_exists($key, $result)) {
            $result[$key] = $value;
        }
    }

    if ($shortcode) {
        $result = apply_filters("shortcode_atts_{$shortcode}", $result, $defaults, $atts);
    }

    return $result;
}

// Register shortcode processing on content output
add_filter('the_content', 'do_shortcode', 11);
