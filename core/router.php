<?php
/**
 * Clean Room CMS - URL Router
 *
 * Parses the request URL into query variables that the Query engine understands.
 */

class CR_Router {
    private array $rules = [];

    public function __construct() {
        $this->load_rules();
    }

    /**
     * Build default rewrite rules from registered post types and taxonomies.
     */
    public function load_rules(): void {
        // Static rules first (higher priority)
        $this->add_rule('^feed/?$', ['feed' => 'rss2']);
        $this->add_rule('^comments/feed/?$', ['feed' => 'comments-rss2']);

        // Date archives
        $this->add_rule('^(\d{4})/(\d{2})/(\d{2})/?$', ['year' => '$1', 'monthnum' => '$2', 'day' => '$3']);
        $this->add_rule('^(\d{4})/(\d{2})/?$', ['year' => '$1', 'monthnum' => '$2']);
        $this->add_rule('^(\d{4})/?$', ['year' => '$1']);

        // Category
        $this->add_rule('^category/([^/]+)/?$', ['category_name' => '$1']);
        $this->add_rule('^category/([^/]+)/page/(\d+)/?$', ['category_name' => '$1', 'paged' => '$2']);

        // Tag
        $this->add_rule('^tag/([^/]+)/?$', ['tag' => '$1']);
        $this->add_rule('^tag/([^/]+)/page/(\d+)/?$', ['tag' => '$1', 'paged' => '$2']);

        // Author
        $this->add_rule('^author/([^/]+)/?$', ['author_name' => '$1']);
        $this->add_rule('^author/([^/]+)/page/(\d+)/?$', ['author_name' => '$1', 'paged' => '$2']);

        // Search
        $this->add_rule('^search/(.+)/?$', ['s' => '$1']);

        // Pages (pagination)
        $this->add_rule('^page/(\d+)/?$', ['paged' => '$1']);

        // Single post (with date-based permalink)
        $this->add_rule('^(\d{4})/(\d{2})/(\d{2})/([^/]+)/?$', [
            'year' => '$1', 'monthnum' => '$2', 'day' => '$3', 'name' => '$4', 'post_type' => 'post'
        ]);

        // Simple post permalink: /post-slug/
        $this->add_rule('^([^/]+)/?$', ['name' => '$1']);

        // Page with parent: /parent/child/
        $this->add_rule('^(.+?)/([^/]+)/?$', ['pagename' => '$2', '_page_path' => '$0']);

        // Let plugins add rules
        do_action('generate_rewrite_rules', $this);
    }

    public function add_rule(string $regex, array $query_vars): void {
        $this->rules[] = [
            'regex' => $regex,
            'query' => $query_vars,
        ];
    }

    /**
     * Parse the current request and return query variables.
     */
    public function parse_request(): array {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Remove query string
        $path = parse_url($request_uri, PHP_URL_PATH);
        $path = trim($path, '/');

        // Handle query string parameters (e.g., ?p=123)
        $query_string_vars = [];
        $qs = parse_url($request_uri, PHP_URL_QUERY);
        if ($qs) {
            parse_str($qs, $query_string_vars);
        }

        // Direct query vars override
        if (!empty($query_string_vars['p'])) {
            return array_merge($query_string_vars, ['p' => (int) $query_string_vars['p']]);
        }
        if (!empty($query_string_vars['page_id'])) {
            return array_merge($query_string_vars, ['page_id' => (int) $query_string_vars['page_id'], 'post_type' => 'page']);
        }
        if (!empty($query_string_vars['s'])) {
            return $query_string_vars;
        }

        // Admin, API, and MCP paths
        if (str_starts_with($path, 'admin')) {
            return ['_admin' => true];
        }
        if (str_starts_with($path, 'mcp')) {
            $mcp_path = substr($path, 3);
            return ['_mcp' => true, '_mcp_path' => trim($mcp_path, '/')];
        }
        if (str_starts_with($path, 'api')) {
            return ['_rest_api' => true, '_rest_path' => $path];
        }

        // Empty path = home
        if (empty($path)) {
            return $query_string_vars;
        }

        // Match rewrite rules
        foreach ($this->rules as $rule) {
            if (preg_match('#' . $rule['regex'] . '#', $path, $matches)) {
                $vars = [];
                foreach ($rule['query'] as $key => $val) {
                    if (is_string($val) && preg_match('/^\$(\d+)$/', $val, $m)) {
                        $vars[$key] = $matches[(int) $m[1]] ?? '';
                    } else {
                        $vars[$key] = $val;
                    }
                }

                // Resolve: is this a post or a page?
                if (isset($vars['name']) && !isset($vars['post_type']) && !isset($vars['year'])) {
                    $vars = $this->resolve_slug($vars);
                }

                return array_merge($query_string_vars, $vars);
            }
        }

        return array_merge($query_string_vars, ['_404' => true]);
    }

    /**
     * When we have a slug but don't know if it's a post or page, check the DB.
     */
    private function resolve_slug(array $vars): array {
        $db = cr_db();
        $slug = $vars['name'];

        // Check pages first (they use simple slugs)
        $page = $db->get_row($db->prepare(
            "SELECT ID, post_type FROM `{$db->prefix}posts` WHERE post_name = %s AND post_status = 'publish' AND post_type = 'page' LIMIT 1",
            $slug
        ));

        if ($page) {
            return ['page_id' => (int) $page->ID, 'post_type' => 'page'];
        }

        // Check posts
        $post = $db->get_row($db->prepare(
            "SELECT ID, post_type FROM `{$db->prefix}posts` WHERE post_name = %s AND post_status = 'publish' AND post_type = 'post' LIMIT 1",
            $slug
        ));

        if ($post) {
            $vars['post_type'] = 'post';
            return $vars;
        }

        // Check custom post types
        $cpt = $db->get_row($db->prepare(
            "SELECT ID, post_type FROM `{$db->prefix}posts` WHERE post_name = %s AND post_status = 'publish' AND post_type NOT IN ('post','page','attachment','revision','nav_menu_item') LIMIT 1",
            $slug
        ));

        if ($cpt) {
            return ['p' => (int) $cpt->ID, 'post_type' => $cpt->post_type];
        }

        // Nothing found
        return ['_404' => true];
    }
}
