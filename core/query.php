<?php
/**
 * Clean Room CMS - Query Engine
 *
 * Parses query variables and constructs SQL to fetch posts.
 * Implements "The Loop" pattern for iterating over results.
 */

class CR_Query {
    public array $query_vars = [];
    public array $posts = [];
    public ?object $post = null;
    public int $post_count = 0;
    public int $found_posts = 0;
    public int $max_num_pages = 0;
    public int $current_post = -1;
    public bool $in_the_loop = false;

    // Conditional flags
    public bool $is_single = false;
    public bool $is_page = false;
    public bool $is_archive = false;
    public bool $is_category = false;
    public bool $is_tag = false;
    public bool $is_tax = false;
    public bool $is_author = false;
    public bool $is_date = false;
    public bool $is_year = false;
    public bool $is_month = false;
    public bool $is_day = false;
    public bool $is_search = false;
    public bool $is_home = false;
    public bool $is_front_page = false;
    public bool $is_404 = false;
    public bool $is_singular = false;
    public bool $is_post_type_archive = false;
    public bool $is_attachment = false;

    public ?object $queried_object = null;
    public ?int $queried_object_id = null;

    public function __construct(array $query = null) {
        if ($query !== null) {
            $this->query($query);
        }
    }

    public function query(array $query): array {
        $this->parse_query($query);
        return $this->get_posts();
    }

    public function parse_query(array $query): void {
        $defaults = [
            'p'              => 0,
            'page_id'        => 0,
            'name'           => '',
            'pagename'       => '',
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => (int) get_option('posts_per_page', 10),
            'paged'          => 1,
            'offset'         => 0,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'author'         => '',
            'author_name'    => '',
            'cat'            => '',
            'category_name'  => '',
            'tag'            => '',
            'tag_id'         => 0,
            'tax_query'      => [],
            'meta_query'     => [],
            'meta_key'       => '',
            'meta_value'     => '',
            's'              => '',
            'year'           => 0,
            'monthnum'       => 0,
            'day'            => 0,
            'post_parent'    => '',
            'post__in'       => [],
            'post__not_in'   => [],
            'nopaging'       => false,
            'no_found_rows'  => false,
            'fields'         => '',
        ];

        $this->query_vars = array_merge($defaults, $query);
        $qv = &$this->query_vars;

        // Reset conditionals
        $this->is_single = $this->is_page = $this->is_archive = $this->is_category = false;
        $this->is_tag = $this->is_tax = $this->is_author = $this->is_date = false;
        $this->is_year = $this->is_month = $this->is_day = $this->is_search = false;
        $this->is_home = $this->is_front_page = $this->is_404 = $this->is_singular = false;
        $this->is_post_type_archive = $this->is_attachment = false;

        // Determine conditionals
        if (!empty($qv['p']) || !empty($qv['name'])) {
            if ($qv['post_type'] === 'page') {
                $this->is_page = true;
            } else {
                $this->is_single = true;
            }
            $this->is_singular = true;
        }

        if (!empty($qv['page_id']) || !empty($qv['pagename'])) {
            $this->is_page = true;
            $this->is_singular = true;
            $qv['post_type'] = 'page';
        }

        if (!empty($qv['s'])) $this->is_search = true;
        if (!empty($qv['cat']) || !empty($qv['category_name'])) { $this->is_category = true; $this->is_archive = true; }
        if (!empty($qv['tag']) || !empty($qv['tag_id'])) { $this->is_tag = true; $this->is_archive = true; }
        if (!empty($qv['tax_query'])) { $this->is_tax = true; $this->is_archive = true; }
        if (!empty($qv['author']) || !empty($qv['author_name'])) { $this->is_author = true; $this->is_archive = true; }
        if (!empty($qv['year'])) { $this->is_date = true; $this->is_year = true; $this->is_archive = true; }
        if (!empty($qv['monthnum'])) { $this->is_date = true; $this->is_month = true; $this->is_archive = true; }
        if (!empty($qv['day'])) { $this->is_date = true; $this->is_day = true; $this->is_archive = true; }

        // Home / front page
        if (!$this->is_singular && !$this->is_archive && !$this->is_search) {
            $show_on_front = get_option('show_on_front', 'posts');
            if ($show_on_front === 'page') {
                $page_on_front = (int) get_option('page_on_front', 0);
                if ($page_on_front > 0) {
                    $this->is_front_page = true;
                    $this->is_page = true;
                    $this->is_singular = true;
                    $qv['page_id'] = $page_on_front;
                    $qv['post_type'] = 'page';
                } else {
                    $this->is_home = true;
                }
            } else {
                $this->is_home = true;
                $this->is_front_page = true;
            }
        }
    }

    public function get_posts(): array {
        $db = cr_db();
        $table = $db->prefix . 'posts';
        $qv = $this->query_vars;

        $where = ["1=1"];
        $join = "";
        $groupby = "";

        // Post type
        $post_types = (array) $qv['post_type'];
        if (count($post_types) === 1) {
            $where[] = $db->prepare("p.post_type = %s", $post_types[0]);
        } else {
            $in = implode("','", array_map([$db, 'escape'], $post_types));
            $where[] = "p.post_type IN ('{$in}')";
        }

        // Post status
        $statuses = (array) $qv['post_status'];
        if (count($statuses) === 1) {
            $where[] = $db->prepare("p.post_status = %s", $statuses[0]);
        } else {
            $in = implode("','", array_map([$db, 'escape'], $statuses));
            $where[] = "p.post_status IN ('{$in}')";
        }

        // Single post by ID
        if (!empty($qv['p'])) {
            $where[] = $db->prepare("p.ID = %d", $qv['p']);
        }
        if (!empty($qv['page_id'])) {
            $where[] = $db->prepare("p.ID = %d", $qv['page_id']);
        }

        // Single post by slug
        if (!empty($qv['name'])) {
            $where[] = $db->prepare("p.post_name = %s", $qv['name']);
        }
        if (!empty($qv['pagename'])) {
            $where[] = $db->prepare("p.post_name = %s", $qv['pagename']);
        }

        // Author
        if (!empty($qv['author'])) {
            $where[] = $db->prepare("p.post_author = %d", $qv['author']);
        }
        if (!empty($qv['author_name'])) {
            $join .= " INNER JOIN `{$db->prefix}users` u ON p.post_author = u.ID";
            $where[] = $db->prepare("u.user_nicename = %s", $qv['author_name']);
        }

        // Category
        if (!empty($qv['cat'])) {
            $join .= " INNER JOIN `{$db->prefix}term_relationships` tr ON p.ID = tr.object_id";
            $join .= " INNER JOIN `{$db->prefix}term_taxonomy` tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $where[] = "tt.taxonomy = 'category'";
            $where[] = $db->prepare("tt.term_id = %d", $qv['cat']);
            $groupby = "GROUP BY p.ID";
        }

        if (!empty($qv['category_name'])) {
            if (strpos($join, 'term_relationships') === false) {
                $join .= " INNER JOIN `{$db->prefix}term_relationships` tr ON p.ID = tr.object_id";
                $join .= " INNER JOIN `{$db->prefix}term_taxonomy` tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            }
            $join .= " INNER JOIN `{$db->prefix}terms` t ON tt.term_id = t.term_id";
            $where[] = "tt.taxonomy = 'category'";
            $where[] = $db->prepare("t.slug = %s", $qv['category_name']);
            $groupby = "GROUP BY p.ID";
        }

        // Tag
        if (!empty($qv['tag'])) {
            if (strpos($join, 'term_relationships') === false) {
                $join .= " INNER JOIN `{$db->prefix}term_relationships` trt ON p.ID = trt.object_id";
                $join .= " INNER JOIN `{$db->prefix}term_taxonomy` ttt ON trt.term_taxonomy_id = ttt.term_taxonomy_id";
                $join .= " INNER JOIN `{$db->prefix}terms` tg ON ttt.term_id = tg.term_id";
            }
            $where[] = "ttt.taxonomy = 'post_tag'";
            $where[] = $db->prepare("tg.slug = %s", $qv['tag']);
            $groupby = "GROUP BY p.ID";
        }

        // Search - escape LIKE wildcards then use prepare()
        if (!empty($qv['s'])) {
            $search_escaped = str_replace(['%', '_'], ['\\%', '\\_'], $qv['s']);
            $like = '%' . $search_escaped . '%';
            $where[] = $db->prepare(
                "(p.post_title LIKE %s OR p.post_content LIKE %s OR p.post_excerpt LIKE %s)",
                $like, $like, $like
            );
        }

        // Date
        if (!empty($qv['year']))     $where[] = "YEAR(p.post_date) = " . intval($qv['year']);
        if (!empty($qv['monthnum'])) $where[] = "MONTH(p.post_date) = " . intval($qv['monthnum']);
        if (!empty($qv['day']))      $where[] = "DAY(p.post_date) = " . intval($qv['day']);

        // Post parent
        if ($qv['post_parent'] !== '') {
            $where[] = $db->prepare("p.post_parent = %d", $qv['post_parent']);
        }

        // Post__in / post__not_in
        if (!empty($qv['post__in'])) {
            $ids = implode(',', array_map('intval', $qv['post__in']));
            $where[] = "p.ID IN ({$ids})";
        }
        if (!empty($qv['post__not_in'])) {
            $ids = implode(',', array_map('intval', $qv['post__not_in']));
            $where[] = "p.ID NOT IN ({$ids})";
        }

        // Meta query (simple key/value)
        if (!empty($qv['meta_key'])) {
            $join .= " INNER JOIN `{$db->prefix}postmeta` pm ON p.ID = pm.post_id";
            $where[] = $db->prepare("pm.meta_key = %s", $qv['meta_key']);
            if (!empty($qv['meta_value'])) {
                $where[] = $db->prepare("pm.meta_value = %s", $qv['meta_value']);
            }
            $groupby = "GROUP BY p.ID";
        }

        // Allow plugins to modify
        $where = apply_filters('posts_where', $where, $this);
        $join = apply_filters('posts_join', $join, $this);

        // Order
        $orderby = match ($qv['orderby']) {
            'title'      => 'p.post_title',
            'name'       => 'p.post_name',
            'author'     => 'p.post_author',
            'modified'   => 'p.post_modified',
            'ID', 'id'   => 'p.ID',
            'menu_order' => 'p.menu_order',
            'rand'       => 'RAND()',
            'comment_count' => 'p.comment_count',
            default      => 'p.post_date',
        };
        $order = strtoupper($qv['order']) === 'ASC' ? 'ASC' : 'DESC';
        $orderby = apply_filters('posts_orderby', "{$orderby} {$order}", $this);

        // Build SQL
        $where_clause = implode(' AND ', $where);

        // Found rows
        $found_rows = '';
        if (!$qv['no_found_rows'] && !$qv['nopaging']) {
            $found_rows = 'SQL_CALC_FOUND_ROWS';
        }

        // Select fields
        $select_fields = $qv['fields'] === 'ids' ? 'p.ID' : 'p.*';

        $sql = "SELECT {$found_rows} {$select_fields} FROM `{$table}` p {$join} WHERE {$where_clause} {$groupby} ORDER BY {$orderby}";

        // Pagination
        if (!$qv['nopaging']) {
            $per_page = (int) $qv['posts_per_page'];
            if ($per_page < 1) $per_page = 10;

            $offset = $qv['offset'];
            if (empty($offset) && $qv['paged'] > 1) {
                $offset = ($qv['paged'] - 1) * $per_page;
            }

            $sql .= " LIMIT {$per_page}";
            if ($offset > 0) {
                $sql .= " OFFSET " . intval($offset);
            }
        }

        $sql = apply_filters('posts_request', $sql, $this);

        // Execute
        $this->posts = $db->get_results($sql);
        $this->post_count = count($this->posts);

        // Found rows for pagination
        if (!$qv['no_found_rows'] && !$qv['nopaging']) {
            $this->found_posts = (int) $db->get_var("SELECT FOUND_ROWS()");
            $per_page = (int) $qv['posts_per_page'];
            $this->max_num_pages = $per_page > 0 ? (int) ceil($this->found_posts / $per_page) : 0;
        } else {
            $this->found_posts = $this->post_count;
        }

        // Set first post
        if ($this->post_count > 0) {
            $this->post = $this->posts[0];
        }

        // 404 check
        if ($this->is_singular && $this->post_count === 0) {
            $this->is_404 = true;
        }

        return $this->posts;
    }

    // -- The Loop methods --

    public function have_posts(): bool {
        if ($this->current_post + 1 < $this->post_count) {
            return true;
        }

        if ($this->in_the_loop) {
            do_action('loop_end', $this);
            $this->rewind_posts();
        }
        $this->in_the_loop = false;
        return false;
    }

    public function the_post(): void {
        global $cr_post;

        if (!$this->in_the_loop) {
            do_action('loop_start', $this);
        }

        $this->in_the_loop = true;
        $this->current_post++;
        $this->post = $this->posts[$this->current_post];
        $cr_post = $this->post;

        do_action('the_post', $this->post, $this);
    }

    public function rewind_posts(): void {
        $this->current_post = -1;
        if ($this->post_count > 0) {
            $this->post = $this->posts[0];
        }
    }
}

// -- Global query and template tags --

$cr_query = null;
$cr_post = null;

function cr_set_main_query(CR_Query $query): void {
    global $cr_query;
    $cr_query = $query;
}

function have_posts(): bool {
    global $cr_query;
    return $cr_query ? $cr_query->have_posts() : false;
}

function the_post(): void {
    global $cr_query;
    if ($cr_query) $cr_query->the_post();
}

function in_the_loop(): bool {
    global $cr_query;
    return $cr_query ? $cr_query->in_the_loop : false;
}

// Template tags
function the_ID(): void {
    global $cr_post;
    echo $cr_post ? (int) $cr_post->ID : '';
}

function get_the_ID(): int {
    global $cr_post;
    return $cr_post ? (int) $cr_post->ID : 0;
}

function the_title(string $before = '', string $after = '', bool $echo = true): ?string {
    $title = get_the_title();
    $output = $before . $title . $after;
    if ($echo) { echo $output; return null; }
    return $output;
}

function get_the_title(int|object|null $post = null): string {
    global $cr_post;
    if ($post === null) $post = $cr_post;
    if (is_int($post)) $post = get_post($post);
    if (!$post) return '';
    return apply_filters('the_title', $post->post_title, $post->ID);
}

function the_content(string $more_link_text = null): void {
    $content = get_the_content($more_link_text);
    $content = apply_filters('the_content', $content);
    echo $content;
}

function get_the_content(string $more_link_text = null): string {
    global $cr_post;
    if (!$cr_post) return '';
    return $cr_post->post_content;
}

function the_excerpt(): void {
    echo apply_filters('the_excerpt', get_the_excerpt());
}

function get_the_excerpt(int|object|null $post = null): string {
    global $cr_post;
    if ($post === null) $post = $cr_post;
    if (is_int($post)) $post = get_post($post);
    if (!$post) return '';

    if (!empty($post->post_excerpt)) {
        return $post->post_excerpt;
    }

    // Auto-generate from content
    $text = strip_tags($post->post_content);
    $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
    $words = explode(' ', $text);
    $excerpt_length = (int) apply_filters('excerpt_length', 55);
    if (count($words) > $excerpt_length) {
        $words = array_slice($words, 0, $excerpt_length);
        $text = implode(' ', $words) . apply_filters('excerpt_more', ' [&hellip;]');
    } else {
        $text = implode(' ', $words);
    }

    return $text;
}

function the_permalink(): void {
    echo esc_url(get_the_permalink());
}

function get_the_permalink(int|object|null $post = null): string {
    global $cr_post;
    if ($post === null) $post = $cr_post;
    if (is_int($post)) $post = get_post($post);
    if (!$post) return '';

    $permalink = apply_filters('post_link', CR_SITE_URL . '/?p=' . $post->ID, $post);
    return $permalink;
}

function get_permalink(int|object|null $post = null): string {
    return get_the_permalink($post);
}

function the_date(string $format = 'F j, Y', string $before = '', string $after = '', bool $echo = true): ?string {
    global $cr_post;
    if (!$cr_post) return null;

    $date = date($format, strtotime($cr_post->post_date));
    $output = $before . $date . $after;

    if ($echo) { echo $output; return null; }
    return $output;
}

function get_the_date(string $format = 'F j, Y', int|object|null $post = null): string {
    global $cr_post;
    if ($post === null) $post = $cr_post;
    if (is_int($post)) $post = get_post($post);
    if (!$post) return '';

    return date($format, strtotime($post->post_date));
}

function the_author(): void {
    echo get_the_author();
}

function get_the_author(int|object|null $post = null): string {
    global $cr_post;
    if ($post === null) $post = $cr_post;
    if (is_int($post)) $post = get_post($post);
    if (!$post) return '';

    $db = cr_db();
    $user = $db->get_row($db->prepare(
        "SELECT display_name FROM `{$db->prefix}users` WHERE ID = %d",
        $post->post_author
    ));

    return $user ? $user->display_name : '';
}

// Conditional tags (global query)
function is_single(): bool { global $cr_query; return $cr_query ? $cr_query->is_single : false; }
function is_page(): bool { global $cr_query; return $cr_query ? $cr_query->is_page : false; }
function is_singular(string|array $post_types = ''): bool {
    global $cr_query;
    if (!$cr_query) return false;
    if (!$cr_query->is_singular) return false;
    if (empty($post_types)) return true;
    $types = (array) $post_types;
    return in_array(get_post_type($cr_query->post), $types, true);
}
function is_archive(): bool { global $cr_query; return $cr_query ? $cr_query->is_archive : false; }
function is_category(): bool { global $cr_query; return $cr_query ? $cr_query->is_category : false; }
function is_tag(): bool { global $cr_query; return $cr_query ? $cr_query->is_tag : false; }
function is_tax(): bool { global $cr_query; return $cr_query ? $cr_query->is_tax : false; }
function is_author(): bool { global $cr_query; return $cr_query ? $cr_query->is_author : false; }
function is_date(): bool { global $cr_query; return $cr_query ? $cr_query->is_date : false; }
function is_year(): bool { global $cr_query; return $cr_query ? $cr_query->is_year : false; }
function is_month(): bool { global $cr_query; return $cr_query ? $cr_query->is_month : false; }
function is_day(): bool { global $cr_query; return $cr_query ? $cr_query->is_day : false; }
function is_search(): bool { global $cr_query; return $cr_query ? $cr_query->is_search : false; }
function is_home(): bool { global $cr_query; return $cr_query ? $cr_query->is_home : false; }
function is_front_page(): bool { global $cr_query; return $cr_query ? $cr_query->is_front_page : false; }
function is_404(): bool { global $cr_query; return $cr_query ? $cr_query->is_404 : false; }
function is_post_type_archive(): bool { global $cr_query; return $cr_query ? $cr_query->is_post_type_archive : false; }
function is_attachment(): bool { global $cr_query; return $cr_query ? $cr_query->is_attachment : false; }

// Escaping helper
function esc_url(string $url): string {
    $url = trim($url);
    if (empty($url)) return '';
    $url = filter_var($url, FILTER_SANITIZE_URL);
    if (!preg_match('/^https?:\/\//i', $url) && !str_starts_with($url, '/') && !str_starts_with($url, '#')) {
        $url = 'http://' . $url;
    }
    return $url;
}

function esc_html(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
