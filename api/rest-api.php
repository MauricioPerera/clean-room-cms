<?php
/**
 * Clean Room CMS - REST API
 *
 * Compatible with WordPress REST API endpoint structure (wp/v2 namespace).
 * Routes: posts, pages, categories, tags, users, comments, media, settings, search.
 */

class CR_REST_API {
    private array $routes = [];
    private string $namespace = 'wp/v2';

    public function __construct() {
        $this->register_core_routes();
        do_action('rest_api_init', $this);
    }

    public function register_route(string $namespace, string $route, array $args): void {
        $full_route = '/' . trim($namespace, '/') . '/' . trim($route, '/');
        $this->routes[$full_route] = $args;
    }

    public function serve(string $path): void {
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');

        // CORS - restrict to same origin by default, configurable via filter
        $allowed_origins = apply_filters('cr_cors_allowed_origins', [CR_SITE_URL]);
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $allowed_origins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-WP-Nonce');

        // Rate limiting
        if (!CR_Security::rate_limit_api()) {
            http_response_code(429);
            echo json_encode(['code' => 'rate_limited', 'message' => 'Too many requests.']);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        // Strip prefix
        $path = '/' . preg_replace('#^(wp-json|api)/?#', '', $path);
        if ($path === '/') $path = '';

        $method = strtoupper($_SERVER['REQUEST_METHOD']);

        // Index endpoint
        if (empty($path) || $path === '/') {
            echo json_encode($this->get_index(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        // Match route
        $matched = $this->match_route($path, $method);

        if (!$matched) {
            http_response_code(404);
            echo json_encode(['code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.']);
            return;
        }

        // Auth check
        $this->authenticate();

        // Permission check
        if (isset($matched['permission_callback'])) {
            if (!call_user_func($matched['permission_callback'])) {
                http_response_code(403);
                echo json_encode(['code' => 'rest_forbidden', 'message' => 'Sorry, you are not allowed to do that.']);
                return;
            }
        }

        // Get params
        $params = array_merge($_GET, $matched['url_params'] ?? []);
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $content_type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
            if (str_contains($content_type, 'application/json')) {
                $body = json_decode(file_get_contents('php://input'), true);
                if (is_array($body)) {
                    $params = array_merge($params, $body);
                }
            } elseif (str_contains($content_type, 'application/x-www-form-urlencoded') || str_contains($content_type, 'multipart/form-data')) {
                $params = array_merge($params, $_POST);
            } else {
                // Try JSON as fallback for API clients that don't set Content-Type
                $raw = file_get_contents('php://input');
                $body = json_decode($raw, true);
                if (is_array($body)) {
                    $params = array_merge($params, $body);
                }
            }
        }

        // Execute callback
        try {
            $result = call_user_func($matched['callback'], $params);
            if (is_array($result) || is_object($result)) {
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['code' => 'rest_error', 'message' => $e->getMessage()]);
        }
    }

    private function match_route(string $path, string $method): ?array {
        foreach ($this->routes as $route_pattern => $handlers) {
            // Convert route pattern to regex
            $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route_pattern);
            $regex = '#^' . $regex . '/?$#';

            if (preg_match($regex, $path, $matches)) {
                $url_params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Find handler for method
                foreach ($handlers as $handler) {
                    $methods = (array) ($handler['methods'] ?? ['GET']);
                    if (in_array($method, $methods)) {
                        $handler['url_params'] = $url_params;
                        return $handler;
                    }
                }
            }
        }
        return null;
    }

    private function authenticate(): void {
        // Application password (Basic Auth)
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $user_id = cr_authenticate($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ?? '');
            if ($user_id) {
                global $cr_current_user;
                $cr_current_user = get_userdata($user_id);
            }
        }

        // Nonce-based auth (for same-origin requests)
        $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? $_GET['_wpnonce'] ?? '';
        if ($nonce && cr_verify_nonce($nonce, 'wp_rest')) {
            // User already set via cookie
        }
    }

    private function get_index(): array {
        return [
            'name'           => get_option('blogname', 'Clean Room CMS'),
            'description'    => get_option('blogdescription', ''),
            'url'            => CR_HOME_URL,
            'home'           => CR_HOME_URL,
            'gmt_offset'     => get_option('gmt_offset', '0'),
            'timezone_string' => get_option('timezone_string', ''),
            'namespaces'     => [$this->namespace],
            'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => CR_SITE_URL . '/admin/']]],
        ];
    }

    private function register_core_routes(): void {
        $ns = $this->namespace;

        // -- Posts --
        $this->register_route($ns, '/posts', [
            ['methods' => 'GET', 'callback' => [$this, 'get_posts']],
            ['methods' => 'POST', 'callback' => [$this, 'create_post'], 'permission_callback' => fn() => current_user_can('publish_posts')],
        ]);
        $this->register_route($ns, '/posts/{id}', [
            ['methods' => 'GET', 'callback' => [$this, 'get_post']],
            ['methods' => ['PUT', 'PATCH'], 'callback' => [$this, 'update_post'], 'permission_callback' => fn() => current_user_can('edit_posts')],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete_post_endpoint'], 'permission_callback' => fn() => current_user_can('delete_posts')],
        ]);

        // -- Pages --
        $this->register_route($ns, '/pages', [
            ['methods' => 'GET', 'callback' => fn($p) => $this->get_posts(array_merge($p, ['post_type' => 'page']))],
            ['methods' => 'POST', 'callback' => fn($p) => $this->create_post(array_merge($p, ['post_type' => 'page'])), 'permission_callback' => fn() => current_user_can('publish_pages')],
        ]);
        $this->register_route($ns, '/pages/{id}', [
            ['methods' => 'GET', 'callback' => [$this, 'get_post']],
            ['methods' => ['PUT', 'PATCH'], 'callback' => [$this, 'update_post'], 'permission_callback' => fn() => current_user_can('edit_pages')],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete_post_endpoint'], 'permission_callback' => fn() => current_user_can('delete_pages')],
        ]);

        // -- Categories --
        $this->register_route($ns, '/categories', [
            ['methods' => 'GET', 'callback' => [$this, 'get_categories']],
            ['methods' => 'POST', 'callback' => [$this, 'create_category'], 'permission_callback' => fn() => current_user_can('manage_categories')],
        ]);

        // -- Tags --
        $this->register_route($ns, '/tags', [
            ['methods' => 'GET', 'callback' => [$this, 'get_tags']],
            ['methods' => 'POST', 'callback' => [$this, 'create_tag'], 'permission_callback' => fn() => current_user_can('manage_categories')],
        ]);

        // -- Users --
        $this->register_route($ns, '/users', [
            ['methods' => 'GET', 'callback' => [$this, 'get_users'], 'permission_callback' => fn() => current_user_can('list_users')],
        ]);
        $this->register_route($ns, '/users/me', [
            ['methods' => 'GET', 'callback' => [$this, 'get_current_user_endpoint']],
        ]);

        // -- Search --
        $this->register_route($ns, '/search', [
            ['methods' => 'GET', 'callback' => [$this, 'search']],
        ]);

        // -- Settings --
        $this->register_route($ns, '/settings', [
            ['methods' => 'GET', 'callback' => [$this, 'get_settings'], 'permission_callback' => fn() => current_user_can('manage_options')],
            ['methods' => 'POST', 'callback' => [$this, 'update_settings'], 'permission_callback' => fn() => current_user_can('manage_options')],
        ]);
    }

    // -- Callbacks --

    public function get_posts(array $params): array {
        $query_args = [
            'post_type'      => $params['post_type'] ?? 'post',
            'post_status'    => $params['status'] ?? 'publish',
            'posts_per_page' => min((int) ($params['per_page'] ?? 10), 100),
            'paged'          => (int) ($params['page'] ?? 1),
            'orderby'        => $params['orderby'] ?? 'date',
            'order'          => $params['order'] ?? 'DESC',
        ];

        if (!empty($params['search'])) $query_args['s'] = $params['search'];
        if (!empty($params['author'])) $query_args['author'] = (int) $params['author'];
        if (!empty($params['categories'])) $query_args['cat'] = (int) $params['categories'];

        $query = new CR_Query($query_args);

        // Set pagination headers
        header('X-WP-Total: ' . $query->found_posts);
        header('X-WP-TotalPages: ' . $query->max_num_pages);

        return array_map([$this, 'prepare_post'], $query->posts);
    }

    public function get_post(array $params): array|object {
        $post = get_post((int) $params['id']);
        if (!$post) {
            http_response_code(404);
            return ['code' => 'rest_post_invalid_id', 'message' => 'Invalid post ID.'];
        }
        return $this->prepare_post($post);
    }

    public function create_post(array $params): array|object {
        $data = [
            'post_title'   => $params['title'] ?? '',
            'post_content' => $params['content'] ?? '',
            'post_excerpt' => $params['excerpt'] ?? '',
            'post_status'  => $params['status'] ?? 'draft',
            'post_type'    => $params['post_type'] ?? 'post',
            'post_author'  => get_current_user_id(),
        ];

        if (isset($params['slug'])) $data['post_name'] = $params['slug'];

        $id = cr_insert_post($data);
        if (!$id) {
            http_response_code(500);
            return ['code' => 'rest_post_failed', 'message' => 'Could not create post.'];
        }

        http_response_code(201);
        return $this->prepare_post(get_post($id));
    }

    public function update_post(array $params): array|object {
        $post_id = (int) $params['id'];
        $post = get_post($post_id);
        if (!$post) {
            http_response_code(404);
            return ['code' => 'rest_post_invalid_id', 'message' => 'Invalid post ID.'];
        }

        $data = ['ID' => $post_id];
        if (isset($params['title'])) $data['post_title'] = $params['title'];
        if (isset($params['content'])) $data['post_content'] = $params['content'];
        if (isset($params['excerpt'])) $data['post_excerpt'] = $params['excerpt'];
        if (isset($params['status'])) $data['post_status'] = $params['status'];
        if (isset($params['slug'])) $data['post_name'] = $params['slug'];

        cr_update_post($data);
        return $this->prepare_post(get_post($post_id));
    }

    public function delete_post_endpoint(array $params): array|object {
        $post_id = (int) $params['id'];
        $force = !empty($params['force']);

        $post = get_post($post_id);
        if (!$post) {
            http_response_code(404);
            return ['code' => 'rest_post_invalid_id', 'message' => 'Invalid post ID.'];
        }

        $prepared = $this->prepare_post($post);
        cr_delete_post($post_id, $force);

        return ['deleted' => true, 'previous' => $prepared];
    }

    public function get_categories(array $params): array {
        return $this->get_terms_endpoint('category', $params);
    }

    public function get_tags(array $params): array {
        return $this->get_terms_endpoint('post_tag', $params);
    }

    public function create_category(array $params): array|object {
        return $this->create_term_endpoint('category', $params);
    }

    public function create_tag(array $params): array|object {
        return $this->create_term_endpoint('post_tag', $params);
    }

    private function get_terms_endpoint(string $taxonomy, array $params): array {
        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => !empty($params['hide_empty']),
            'orderby'    => $params['orderby'] ?? 'name',
            'order'      => $params['order'] ?? 'ASC',
            'number'     => min((int) ($params['per_page'] ?? 10), 100),
            'search'     => $params['search'] ?? '',
        ];

        $terms = get_terms($args);
        return array_map(fn($t) => [
            'id'          => (int) $t->term_id,
            'count'       => (int) $t->count,
            'description' => $t->description,
            'name'        => $t->name,
            'slug'        => $t->slug,
            'taxonomy'    => $t->taxonomy,
            'parent'      => (int) $t->parent,
        ], $terms);
    }

    private function create_term_endpoint(string $taxonomy, array $params): array|object {
        $name = $params['name'] ?? '';
        if (empty($name)) {
            http_response_code(400);
            return ['code' => 'rest_term_name_required', 'message' => 'Term name is required.'];
        }

        $result = cr_insert_term($name, $taxonomy, [
            'slug'        => $params['slug'] ?? '',
            'description' => $params['description'] ?? '',
            'parent'      => (int) ($params['parent'] ?? 0),
        ]);

        if (!$result) {
            http_response_code(500);
            return ['code' => 'rest_term_failed', 'message' => 'Could not create term.'];
        }

        http_response_code(201);
        $term = get_term($result['term_id'], $taxonomy);
        return [
            'id'          => (int) $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'parent'      => (int) $term->parent,
            'count'       => (int) $term->count,
        ];
    }

    public function get_users(array $params): array {
        $db = cr_db();
        $per_page = min((int) ($params['per_page'] ?? 10), 100);
        $page = max(1, (int) ($params['page'] ?? 1));
        $offset = ($page - 1) * $per_page;

        $users = $db->get_results("SELECT ID, user_login, user_nicename, user_email, user_url, display_name, user_registered FROM `{$db->prefix}users` ORDER BY user_registered DESC LIMIT {$per_page} OFFSET {$offset}");

        return array_map(fn($u) => [
            'id'              => (int) $u->ID,
            'name'            => $u->display_name,
            'slug'            => $u->user_nicename,
            'link'            => CR_HOME_URL . '/author/' . $u->user_nicename . '/',
            'registered_date' => $u->user_registered,
        ], $users);
    }

    public function get_current_user_endpoint(array $params): array|object {
        if (!is_user_logged_in()) {
            http_response_code(401);
            return ['code' => 'rest_not_logged_in', 'message' => 'You are not currently logged in.'];
        }

        $user = cr_get_current_user();
        return [
            'id'    => (int) $user->ID,
            'name'  => $user->display_name,
            'slug'  => $user->user_nicename,
            'email' => $user->user_email,
            'url'   => $user->user_url,
        ];
    }

    public function search(array $params): array {
        $search = $params['search'] ?? '';
        if (empty($search)) {
            return [];
        }

        $query = new CR_Query([
            'post_type'      => $params['type'] ?? ['post', 'page'],
            'post_status'    => 'publish',
            's'              => $search,
            'posts_per_page' => min((int) ($params['per_page'] ?? 10), 100),
        ]);

        return array_map(fn($p) => [
            'id'    => (int) $p->ID,
            'title' => $p->post_title,
            'url'   => get_permalink($p),
            'type'  => $p->post_type,
        ], $query->posts);
    }

    public function get_settings(array $params): array {
        return [
            'title'       => get_option('blogname'),
            'description' => get_option('blogdescription'),
            'url'         => get_option('siteurl'),
            'email'       => get_option('admin_email'),
            'timezone'    => get_option('timezone_string'),
            'date_format' => get_option('date_format'),
            'time_format' => get_option('time_format'),
            'posts_per_page' => (int) get_option('posts_per_page'),
        ];
    }

    public function update_settings(array $params): array {
        $map = [
            'title'       => 'blogname',
            'description' => 'blogdescription',
            'email'       => 'admin_email',
            'timezone'    => 'timezone_string',
            'date_format' => 'date_format',
            'time_format' => 'time_format',
            'posts_per_page' => 'posts_per_page',
        ];

        foreach ($map as $param_key => $option_key) {
            if (isset($params[$param_key])) {
                update_option($option_key, $params[$param_key]);
            }
        }

        return $this->get_settings([]);
    }

    private function prepare_post(object $post): array {
        $fields = $_GET['_fields'] ?? '';

        $data = [
            'id'            => (int) $post->ID,
            'date'          => $post->post_date,
            'date_gmt'      => $post->post_date_gmt,
            'modified'      => $post->post_modified,
            'modified_gmt'  => $post->post_modified_gmt,
            'slug'          => $post->post_name,
            'status'        => $post->post_status,
            'type'          => $post->post_type,
            'link'          => get_permalink($post),
            'title'         => ['rendered' => $post->post_title],
            'content'       => ['rendered' => apply_filters('the_content', $post->post_content)],
            'excerpt'       => ['rendered' => $post->post_excerpt ?: get_the_excerpt($post)],
            'author'        => (int) $post->post_author,
            'parent'        => (int) $post->post_parent,
            'menu_order'    => (int) $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status'   => $post->ping_status,
        ];

        // Add categories and tags for posts
        if ($post->post_type === 'post') {
            $cats = get_the_terms((int) $post->ID, 'category');
            $data['categories'] = array_map(fn($c) => (int) $c->term_id, $cats);

            $tags = get_the_terms((int) $post->ID, 'post_tag');
            $data['tags'] = array_map(fn($t) => (int) $t->term_id, $tags);
        }

        // Field filtering
        if ($fields) {
            $requested = array_map('trim', explode(',', $fields));
            $data = array_intersect_key($data, array_flip($requested));
        }

        return $data;
    }
}

// Public registration function for plugins
function register_rest_route(string $namespace, string $route, array $args): void {
    add_action('rest_api_init', function (CR_REST_API $api) use ($namespace, $route, $args) {
        $api->register_route($namespace, $route, [$args]);
    });
}
