<?php
/**
 * Clean Room CMS - Rewrite Rules API
 *
 * Allows plugins and themes to register custom URL rewrite rules.
 */

$cr_extra_rewrite_rules = [];

/**
 * Add a custom rewrite rule.
 */
function add_rewrite_rule(string $regex, string|array $query, string $after = 'bottom'): void {
    global $cr_extra_rewrite_rules;

    if (is_string($query)) {
        // Parse "index.php?p=$matches[1]&foo=$matches[2]" format
        $query = str_replace('index.php?', '', $query);
        parse_str($query, $parsed);
        // Replace $matches[n] with $n
        array_walk($parsed, function (&$val) {
            $val = preg_replace('/\$matches\[(\d+)\]/', '\$$1', $val);
        });
        $query = $parsed;
    }

    $cr_extra_rewrite_rules[] = [
        'regex'    => $regex,
        'query'    => $query,
        'position' => $after,
    ];
}

/**
 * Add a rewrite tag (query var placeholder).
 */
function add_rewrite_tag(string $tag, string $regex, string $query = ''): void {
    // Register the tag as a recognized query variable
    add_filter('query_vars', function (array $vars) use ($tag) {
        $clean = trim($tag, '%');
        if (!in_array($clean, $vars)) {
            $vars[] = $clean;
        }
        return $vars;
    });
}

/**
 * Flush rewrite rules (rebuild).
 */
function flush_rewrite_rules(): void {
    update_option('rewrite_rules', '');
}

/**
 * Inject extra rewrite rules into the router.
 */
function cr_apply_extra_rewrite_rules(CR_Router $router): void {
    global $cr_extra_rewrite_rules;

    foreach ($cr_extra_rewrite_rules as $rule) {
        $router->add_rule($rule['regex'], $rule['query']);
    }
}

add_action('generate_rewrite_rules', 'cr_apply_extra_rewrite_rules');
