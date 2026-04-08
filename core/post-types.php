<?php
/**
 * Clean Room CMS - Post Types System
 *
 * Registration and management of post types (post, page, attachment, custom).
 * CRUD operations for posts of any type.
 */

// Global post type registry
$cr_post_types = [];

/**
 * Register a new post type.
 */
function register_post_type(string $post_type, array $args = []): bool {
    global $cr_post_types;

    $defaults = [
        'label'               => $post_type,
        'labels'              => [],
        'description'         => '',
        'public'              => false,
        'hierarchical'        => false,
        'exclude_from_search' => null,
        'publicly_queryable'  => null,
        'show_ui'             => null,
        'show_in_menu'        => null,
        'show_in_nav_menus'   => null,
        'show_in_admin_bar'   => null,
        'show_in_rest'        => false,
        'rest_base'           => $post_type . 's',
        'menu_position'       => null,
        'menu_icon'           => null,
        'capability_type'     => 'post',
        'capabilities'        => [],
        'map_meta_cap'        => false,
        'supports'            => ['title', 'editor'],
        'taxonomies'          => [],
        'has_archive'         => false,
        'rewrite'             => true,
        'query_var'           => true,
        'can_export'          => true,
        'delete_with_user'    => null,
    ];

    $args = array_merge($defaults, $args);

    // Derive defaults from 'public'
    if ($args['publicly_queryable'] === null) $args['publicly_queryable'] = $args['public'];
    if ($args['show_ui'] === null) $args['show_ui'] = $args['public'];
    if ($args['show_in_menu'] === null) $args['show_in_menu'] = $args['show_ui'];
    if ($args['show_in_nav_menus'] === null) $args['show_in_nav_menus'] = $args['public'];
    if ($args['show_in_admin_bar'] === null) $args['show_in_admin_bar'] = $args['show_in_menu'];
    if ($args['exclude_from_search'] === null) $args['exclude_from_search'] = !$args['public'];

    $args['name'] = $post_type;

    $cr_post_types[$post_type] = (object) $args;

    do_action('registered_post_type', $post_type, $args);

    return true;
}

/**
 * Get a registered post type object.
 */
function get_post_type_object(string $post_type): ?object {
    global $cr_post_types;
    return $cr_post_types[$post_type] ?? null;
}

/**
 * Get all registered post types.
 */
function get_post_types(array $args = [], string $output = 'names'): array {
    global $cr_post_types;

    $results = [];
    foreach ($cr_post_types as $name => $type) {
        $match = true;
        foreach ($args as $key => $value) {
            if (!isset($type->$key) || $type->$key !== $value) {
                $match = false;
                break;
            }
        }
        if ($match) {
            $results[$name] = $output === 'objects' ? $type : $name;
        }
    }

    return $results;
}

/**
 * Check if a post type exists.
 */
function post_type_exists(string $post_type): bool {
    global $cr_post_types;
    return isset($cr_post_types[$post_type]);
}

/**
 * Register built-in post types.
 */
function cr_register_default_post_types(): void {
    register_post_type('post', [
        'label'              => 'Posts',
        'public'             => true,
        'hierarchical'       => false,
        'show_in_rest'       => true,
        'rest_base'          => 'posts',
        'supports'           => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments', 'revisions'],
        'taxonomies'         => ['category', 'post_tag'],
        'has_archive'        => true,
        'rewrite'            => ['slug' => '%year%/%monthnum%/%day%/%postname%'],
    ]);

    register_post_type('page', [
        'label'              => 'Pages',
        'public'             => true,
        'hierarchical'       => true,
        'show_in_rest'       => true,
        'rest_base'          => 'pages',
        'supports'           => ['title', 'editor', 'author', 'thumbnail', 'page-attributes', 'revisions'],
        'has_archive'        => false,
        'rewrite'            => ['slug' => '%pagename%'],
    ]);

    register_post_type('attachment', [
        'label'              => 'Media',
        'public'             => true,
        'hierarchical'       => false,
        'show_in_rest'       => true,
        'rest_base'          => 'media',
        'supports'           => ['title', 'author', 'comments'],
        'show_ui'            => true,
        'show_in_menu'       => true,
    ]);

    register_post_type('revision', [
        'label'              => 'Revisions',
        'public'             => false,
        'hierarchical'       => false,
        'supports'           => [],
    ]);

    register_post_type('nav_menu_item', [
        'label'              => 'Navigation Menu Items',
        'public'             => false,
        'hierarchical'       => false,
        'supports'           => [],
    ]);
}

// -- Post CRUD --

/**
 * Insert a new post into the database.
 */
function cr_insert_post(array $postarr, bool $fire_after_hooks = true): int|false {
    $db = cr_db();
    $table = $db->prefix . 'posts';

    $defaults = [
        'post_author'           => 0,
        'post_date'             => current_time('mysql'),
        'post_date_gmt'         => current_time('mysql', true),
        'post_content'          => '',
        'post_title'            => '',
        'post_excerpt'          => '',
        'post_status'           => 'draft',
        'comment_status'        => 'open',
        'ping_status'           => 'open',
        'post_password'         => '',
        'post_name'             => '',
        'to_ping'               => '',
        'pinged'                => '',
        'post_modified'         => current_time('mysql'),
        'post_modified_gmt'     => current_time('mysql', true),
        'post_content_filtered' => '',
        'post_parent'           => 0,
        'guid'                  => '',
        'menu_order'            => 0,
        'post_type'             => 'post',
        'post_mime_type'        => '',
        'comment_count'         => 0,
    ];

    $data = array_merge($defaults, $postarr);

    // Generate slug if empty
    if (empty($data['post_name']) && !empty($data['post_title'])) {
        $data['post_name'] = cr_sanitize_title($data['post_title']);
    }

    // Ensure unique slug
    $data['post_name'] = cr_unique_post_slug($data['post_name'], $data['post_type'], $data['post_parent']);

    $data = apply_filters('cr_insert_post_data', $data, $postarr);

    // Remove ID if present (auto-increment)
    unset($data['ID']);

    // Handle meta input separately
    $meta_input = $data['meta_input'] ?? [];
    unset($data['meta_input']);
    $tax_input = $data['tax_input'] ?? [];
    unset($data['tax_input']);

    $post_id = $db->insert($table, $data);

    if ($post_id === false) {
        return false;
    }

    // Generate GUID if empty
    if (empty($data['guid'])) {
        $db->update($table, ['guid' => CR_SITE_URL . '/?p=' . $post_id], ['ID' => $post_id]);
    }

    // Insert meta data
    foreach ($meta_input as $key => $value) {
        add_post_meta($post_id, $key, $value);
    }

    // Set taxonomy terms
    foreach ($tax_input as $taxonomy => $terms) {
        cr_set_post_terms($post_id, $terms, $taxonomy);
    }

    if ($fire_after_hooks) {
        do_action('cr_insert_post', $post_id, get_post($post_id), false);
        do_action("save_post_{$data['post_type']}", $post_id, get_post($post_id), false);
        do_action('save_post', $post_id, get_post($post_id), false);
    }

    return $post_id;
}

/**
 * Update an existing post.
 */
function cr_update_post(array $postarr): int|false {
    if (empty($postarr['ID'])) {
        return false;
    }

    $db = cr_db();
    $table = $db->prefix . 'posts';
    $post_id = (int) $postarr['ID'];

    $existing = get_post($post_id);
    if (!$existing) {
        return false;
    }

    // Merge with existing data
    $data = array_merge((array) $existing, $postarr);
    $data['post_modified'] = current_time('mysql');
    $data['post_modified_gmt'] = current_time('mysql', true);

    // Ensure unique slug if changed
    if (isset($postarr['post_name']) && $postarr['post_name'] !== $existing->post_name) {
        $data['post_name'] = cr_unique_post_slug($data['post_name'], $data['post_type'], $data['post_parent'], $post_id);
    }

    $data = apply_filters('cr_update_post_data', $data, $postarr);

    $meta_input = $data['meta_input'] ?? [];
    unset($data['meta_input']);
    $tax_input = $data['tax_input'] ?? [];
    unset($data['tax_input']);
    unset($data['ID']);

    $result = $db->update($table, $data, ['ID' => $post_id]);

    if ($result === false) {
        return false;
    }

    foreach ($meta_input as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }

    foreach ($tax_input as $taxonomy => $terms) {
        cr_set_post_terms($post_id, $terms, $taxonomy);
    }

    do_action('cr_update_post', $post_id, get_post($post_id));
    do_action("save_post_{$existing->post_type}", $post_id, get_post($post_id), true);
    do_action('save_post', $post_id, get_post($post_id), true);

    return $post_id;
}

/**
 * Trash or delete a post.
 */
function cr_delete_post(int $post_id, bool $force_delete = false): bool {
    $post = get_post($post_id);
    if (!$post) return false;

    $db = cr_db();
    $table = $db->prefix . 'posts';

    if (!$force_delete && $post->post_status !== 'trash') {
        // Move to trash
        $db->update($table, ['post_status' => 'trash'], ['ID' => $post_id]);
        do_action('trashed_post', $post_id);
        return true;
    }

    do_action('before_delete_post', $post_id, $post);

    // Delete meta
    $meta_table = $db->prefix . 'postmeta';
    $db->query($db->prepare("DELETE FROM `{$meta_table}` WHERE post_id = %d", $post_id));

    // Delete term relationships
    $rel_table = $db->prefix . 'term_relationships';
    $db->query($db->prepare("DELETE FROM `{$rel_table}` WHERE object_id = %d", $post_id));

    // Delete the post
    $db->delete($table, ['ID' => $post_id]);

    do_action('deleted_post', $post_id, $post);

    return true;
}

/**
 * Retrieve a post by ID.
 */
function get_post(int $post_id): ?object {
    $db = cr_db();
    $table = $db->prefix . 'posts';

    $post = $db->get_row(
        $db->prepare("SELECT * FROM `{$table}` WHERE ID = %d LIMIT 1", $post_id)
    );

    if ($post) {
        $post = apply_filters('cr_get_post', $post);
    }

    return $post;
}

/**
 * Get posts matching criteria.
 */
function get_posts(array $args = []): array {
    $query = new CR_Query($args);
    return $query->posts;
}

// -- Utility functions --

function cr_sanitize_title(string $title): string {
    $slug = mb_strtolower($title);
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug ?: 'untitled';
}

function cr_unique_post_slug(string $slug, string $post_type, int $post_parent = 0, int $post_id = 0): string {
    $db = cr_db();
    $table = $db->prefix . 'posts';

    if (empty($slug)) {
        $slug = 'untitled';
    }

    $original = $slug;
    $suffix = 2;

    while (true) {
        $sql = $db->prepare(
            "SELECT ID FROM `{$table}` WHERE post_name = %s AND post_type = %s AND ID != %d LIMIT 1",
            $slug, $post_type, $post_id
        );

        $existing = $db->get_var($sql);
        if (!$existing) break;

        $slug = $original . '-' . $suffix;
        $suffix++;
    }

    return $slug;
}

function current_time(string $type = 'mysql', bool $gmt = false): string {
    $timestamp = $gmt ? time() : time() + (int) (get_option('gmt_offset', 0) * 3600);

    return match ($type) {
        'mysql' => gmdate('Y-m-d H:i:s', $timestamp),
        'timestamp' => (string) $timestamp,
        default => gmdate('Y-m-d H:i:s', $timestamp),
    };
}

function get_post_status(int|object $post): string|false {
    if (is_int($post)) {
        $post = get_post($post);
    }
    return $post ? $post->post_status : false;
}

function get_post_type(int|object $post): string|false {
    if (is_int($post)) {
        $post = get_post($post);
    }
    return $post ? $post->post_type : false;
}
