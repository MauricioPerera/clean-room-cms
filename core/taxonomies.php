<?php
/**
 * Clean Room CMS - Taxonomy System
 *
 * Registration and management of taxonomies (category, post_tag, custom).
 * CRUD operations for terms and term-post relationships.
 */

$cr_taxonomies = [];

/**
 * Register a taxonomy.
 */
function register_taxonomy(string $taxonomy, string|array $object_type, array $args = []): bool {
    global $cr_taxonomies;

    $defaults = [
        'label'              => $taxonomy,
        'labels'             => [],
        'description'        => '',
        'public'             => true,
        'publicly_queryable' => null,
        'hierarchical'       => false,
        'show_ui'            => null,
        'show_in_menu'       => null,
        'show_in_nav_menus'  => null,
        'show_in_rest'       => false,
        'rest_base'          => $taxonomy,
        'show_tagcloud'      => null,
        'show_in_quick_edit' => null,
        'show_admin_column'  => false,
        'rewrite'            => true,
        'query_var'          => $taxonomy,
        'capabilities'       => [],
    ];

    $args = array_merge($defaults, $args);

    if ($args['publicly_queryable'] === null) $args['publicly_queryable'] = $args['public'];
    if ($args['show_ui'] === null) $args['show_ui'] = $args['public'];
    if ($args['show_in_menu'] === null) $args['show_in_menu'] = $args['show_ui'];
    if ($args['show_in_nav_menus'] === null) $args['show_in_nav_menus'] = $args['public'];
    if ($args['show_tagcloud'] === null) $args['show_tagcloud'] = $args['show_ui'];
    if ($args['show_in_quick_edit'] === null) $args['show_in_quick_edit'] = $args['show_ui'];

    $args['name'] = $taxonomy;
    $args['object_type'] = (array) $object_type;

    $cr_taxonomies[$taxonomy] = (object) $args;

    do_action('registered_taxonomy', $taxonomy, $object_type, $args);

    return true;
}

function get_taxonomy(string $taxonomy): ?object {
    global $cr_taxonomies;
    return $cr_taxonomies[$taxonomy] ?? null;
}

function taxonomy_exists(string $taxonomy): bool {
    global $cr_taxonomies;
    return isset($cr_taxonomies[$taxonomy]);
}

function get_taxonomies(array $args = [], string $output = 'names'): array {
    global $cr_taxonomies;

    $results = [];
    foreach ($cr_taxonomies as $name => $tax) {
        $match = true;
        foreach ($args as $key => $value) {
            if (!isset($tax->$key) || $tax->$key !== $value) {
                $match = false;
                break;
            }
        }
        if ($match) {
            $results[$name] = $output === 'objects' ? $tax : $name;
        }
    }
    return $results;
}

function get_object_taxonomies(string $object_type, string $output = 'names'): array {
    global $cr_taxonomies;

    $results = [];
    foreach ($cr_taxonomies as $name => $tax) {
        if (in_array($object_type, $tax->object_type, true)) {
            $results[$name] = $output === 'objects' ? $tax : $name;
        }
    }
    return $results;
}

/**
 * Register built-in taxonomies.
 */
function cr_register_default_taxonomies(): void {
    register_taxonomy('category', 'post', [
        'label'        => 'Categories',
        'hierarchical' => true,
        'show_in_rest' => true,
        'rest_base'    => 'categories',
        'rewrite'      => ['slug' => 'category'],
        'query_var'    => 'category_name',
    ]);

    register_taxonomy('post_tag', 'post', [
        'label'        => 'Tags',
        'hierarchical' => false,
        'show_in_rest' => true,
        'rest_base'    => 'tags',
        'rewrite'      => ['slug' => 'tag'],
        'query_var'    => 'tag',
    ]);
}

// -- Term CRUD --

/**
 * Insert a new term.
 */
function cr_insert_term(string $term_name, string $taxonomy, array $args = []): array|false {
    $db = cr_db();

    $defaults = [
        'slug'        => '',
        'parent'      => 0,
        'description' => '',
    ];
    $args = array_merge($defaults, $args);

    if (empty($args['slug'])) {
        $args['slug'] = cr_sanitize_title($term_name);
    }

    // Ensure unique slug within taxonomy
    $args['slug'] = cr_unique_term_slug($args['slug'], $taxonomy);

    // Insert into terms table
    $term_id = $db->insert($db->prefix . 'terms', [
        'name'       => $term_name,
        'slug'       => $args['slug'],
        'term_group' => 0,
    ]);

    if ($term_id === false) return false;

    // Insert into term_taxonomy table
    $tt_id = $db->insert($db->prefix . 'term_taxonomy', [
        'term_id'     => $term_id,
        'taxonomy'    => $taxonomy,
        'description' => $args['description'],
        'parent'      => (int) $args['parent'],
        'count'       => 0,
    ]);

    if ($tt_id === false) return false;

    do_action('created_term', $term_id, $tt_id, $taxonomy);

    return ['term_id' => $term_id, 'term_taxonomy_id' => $tt_id];
}

/**
 * Update a term.
 */
function cr_update_term(int $term_id, string $taxonomy, array $args = []): bool {
    $db = cr_db();

    $term = get_term($term_id, $taxonomy);
    if (!$term) return false;

    if (isset($args['name'])) {
        $db->update($db->prefix . 'terms', ['name' => $args['name']], ['term_id' => $term_id]);
    }
    if (isset($args['slug'])) {
        $args['slug'] = cr_unique_term_slug($args['slug'], $taxonomy, $term_id);
        $db->update($db->prefix . 'terms', ['slug' => $args['slug']], ['term_id' => $term_id]);
    }

    $tt_data = [];
    if (isset($args['description'])) $tt_data['description'] = $args['description'];
    if (isset($args['parent'])) $tt_data['parent'] = (int) $args['parent'];

    if (!empty($tt_data)) {
        $db->update($db->prefix . 'term_taxonomy', $tt_data, [
            'term_id'  => $term_id,
            'taxonomy' => $taxonomy,
        ]);
    }

    do_action('edited_term', $term_id, $taxonomy);

    return true;
}

/**
 * Delete a term.
 */
function cr_delete_term(int $term_id, string $taxonomy): bool {
    $db = cr_db();

    $tt = $db->get_row(
        $db->prepare(
            "SELECT term_taxonomy_id FROM `{$db->prefix}term_taxonomy` WHERE term_id = %d AND taxonomy = %s",
            $term_id, $taxonomy
        )
    );

    if (!$tt) return false;

    // Remove relationships
    $db->delete($db->prefix . 'term_relationships', ['term_taxonomy_id' => $tt->term_taxonomy_id]);

    // Delete term_taxonomy
    $db->delete($db->prefix . 'term_taxonomy', ['term_taxonomy_id' => $tt->term_taxonomy_id]);

    // Delete termmeta
    $db->query($db->prepare("DELETE FROM `{$db->prefix}termmeta` WHERE term_id = %d", $term_id));

    // Check if term is used in other taxonomies
    $count = $db->get_var(
        $db->prepare("SELECT COUNT(*) FROM `{$db->prefix}term_taxonomy` WHERE term_id = %d", $term_id)
    );

    if ((int) $count === 0) {
        $db->delete($db->prefix . 'terms', ['term_id' => $term_id]);
    }

    do_action('delete_term', $term_id, $taxonomy);

    return true;
}

/**
 * Get a term by ID and taxonomy.
 */
function get_term(int $term_id, string $taxonomy = ''): ?object {
    $db = cr_db();

    $sql = "SELECT t.*, tt.taxonomy, tt.description, tt.parent, tt.count, tt.term_taxonomy_id
            FROM `{$db->prefix}terms` t
            INNER JOIN `{$db->prefix}term_taxonomy` tt ON t.term_id = tt.term_id
            WHERE t.term_id = " . intval($term_id);

    if ($taxonomy) {
        $sql .= " AND tt.taxonomy = '" . $db->escape($taxonomy) . "'";
    }

    $sql .= " LIMIT 1";

    return $db->get_row($sql);
}

/**
 * Get a term by slug.
 */
function get_term_by(string $field, string|int $value, string $taxonomy = ''): ?object {
    $db = cr_db();

    $sql = "SELECT t.*, tt.taxonomy, tt.description, tt.parent, tt.count, tt.term_taxonomy_id
            FROM `{$db->prefix}terms` t
            INNER JOIN `{$db->prefix}term_taxonomy` tt ON t.term_id = tt.term_id
            WHERE ";

    $sql .= match ($field) {
        'slug' => "t.slug = '" . $db->escape($value) . "'",
        'name' => "t.name = '" . $db->escape($value) . "'",
        'id', 'term_id' => "t.term_id = " . intval($value),
        'term_taxonomy_id' => "tt.term_taxonomy_id = " . intval($value),
        default => "1=0",
    };

    if ($taxonomy) {
        $sql .= " AND tt.taxonomy = '" . $db->escape($taxonomy) . "'";
    }

    $sql .= " LIMIT 1";

    return $db->get_row($sql);
}

/**
 * Get terms for a taxonomy.
 */
function get_terms(array $args = []): array {
    $db = cr_db();

    $defaults = [
        'taxonomy'   => '',
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
        'number'     => 0,
        'offset'     => 0,
        'parent'     => '',
        'search'     => '',
        'slug'       => '',
    ];

    $args = array_merge($defaults, $args);

    $sql = "SELECT t.*, tt.taxonomy, tt.description, tt.parent, tt.count, tt.term_taxonomy_id
            FROM `{$db->prefix}terms` t
            INNER JOIN `{$db->prefix}term_taxonomy` tt ON t.term_id = tt.term_id
            WHERE 1=1";

    if (!empty($args['taxonomy'])) {
        $taxonomies = (array) $args['taxonomy'];
        $in = implode("','", array_map([$db, 'escape'], $taxonomies));
        $sql .= " AND tt.taxonomy IN ('{$in}')";
    }

    if ($args['hide_empty']) {
        $sql .= " AND tt.count > 0";
    }

    if ($args['parent'] !== '') {
        $sql .= " AND tt.parent = " . intval($args['parent']);
    }

    if (!empty($args['search'])) {
        $sql .= " AND t.name LIKE '%" . $db->escape($args['search']) . "%'";
    }

    if (!empty($args['slug'])) {
        $slugs = (array) $args['slug'];
        $in = implode("','", array_map([$db, 'escape'], $slugs));
        $sql .= " AND t.slug IN ('{$in}')";
    }

    $orderby = match ($args['orderby']) {
        'id', 'term_id' => 't.term_id',
        'slug'          => 't.slug',
        'count'         => 'tt.count',
        'term_group'    => 't.term_group',
        default         => 't.name',
    };
    $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
    $sql .= " ORDER BY {$orderby} {$order}";

    if ($args['number'] > 0) {
        $sql .= " LIMIT " . intval($args['number']);
        if ($args['offset'] > 0) {
            $sql .= " OFFSET " . intval($args['offset']);
        }
    }

    return $db->get_results($sql);
}

/**
 * Set terms for a post.
 */
function cr_set_post_terms(int $post_id, array $terms, string $taxonomy, bool $append = false): bool {
    $db = cr_db();

    if (!$append) {
        // Get current term_taxonomy_ids for this post + taxonomy
        $current = $db->get_col(
            "SELECT tr.term_taxonomy_id FROM `{$db->prefix}term_relationships` tr
             INNER JOIN `{$db->prefix}term_taxonomy` tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tr.object_id = " . intval($post_id) . " AND tt.taxonomy = '" . $db->escape($taxonomy) . "'"
        );

        if (!empty($current)) {
            $ids = implode(',', array_map('intval', $current));
            $db->query("DELETE FROM `{$db->prefix}term_relationships` WHERE object_id = " . intval($post_id) . " AND term_taxonomy_id IN ({$ids})");

            // Decrease counts
            foreach ($current as $tt_id) {
                $db->query("UPDATE `{$db->prefix}term_taxonomy` SET count = GREATEST(count - 1, 0) WHERE term_taxonomy_id = " . intval($tt_id));
            }
        }
    }

    foreach ($terms as $term) {
        $term_obj = null;

        if (is_numeric($term)) {
            $term_obj = get_term((int) $term, $taxonomy);
        }

        if (!$term_obj) {
            $term_obj = get_term_by('name', (string) $term, $taxonomy);
        }

        if (!$term_obj) {
            $term_obj = get_term_by('slug', (string) $term, $taxonomy);
        }

        // Auto-create if not found
        if (!$term_obj) {
            $result = cr_insert_term((string) $term, $taxonomy);
            if ($result) {
                $term_obj = get_term($result['term_id'], $taxonomy);
            }
        }

        if ($term_obj) {
            $exists = $db->get_var(
                $db->prepare(
                    "SELECT COUNT(*) FROM `{$db->prefix}term_relationships` WHERE object_id = %d AND term_taxonomy_id = %d",
                    $post_id, $term_obj->term_taxonomy_id
                )
            );

            if ((int) $exists === 0) {
                $db->insert($db->prefix . 'term_relationships', [
                    'object_id'        => $post_id,
                    'term_taxonomy_id' => $term_obj->term_taxonomy_id,
                    'term_order'       => 0,
                ]);

                $db->query("UPDATE `{$db->prefix}term_taxonomy` SET count = count + 1 WHERE term_taxonomy_id = " . intval($term_obj->term_taxonomy_id));
            }
        }
    }

    return true;
}

/**
 * Get terms assigned to a post.
 */
function get_the_terms(int $post_id, string $taxonomy): array {
    $db = cr_db();

    return $db->get_results(
        "SELECT t.*, tt.taxonomy, tt.description, tt.parent, tt.count, tt.term_taxonomy_id
         FROM `{$db->prefix}terms` t
         INNER JOIN `{$db->prefix}term_taxonomy` tt ON t.term_id = tt.term_id
         INNER JOIN `{$db->prefix}term_relationships` tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
         WHERE tr.object_id = " . intval($post_id) . "
         AND tt.taxonomy = '" . $db->escape($taxonomy) . "'
         ORDER BY t.name ASC"
    );
}

function cr_unique_term_slug(string $slug, string $taxonomy, int $term_id = 0): string {
    $db = cr_db();
    $original = $slug;
    $suffix = 2;

    while (true) {
        $sql = "SELECT t.term_id FROM `{$db->prefix}terms` t
                INNER JOIN `{$db->prefix}term_taxonomy` tt ON t.term_id = tt.term_id
                WHERE t.slug = '" . $db->escape($slug) . "'
                AND tt.taxonomy = '" . $db->escape($taxonomy) . "'";
        if ($term_id > 0) {
            $sql .= " AND t.term_id != " . intval($term_id);
        }
        $sql .= " LIMIT 1";

        $exists = $db->get_var($sql);
        if (!$exists) break;

        $slug = $original . '-' . $suffix;
        $suffix++;
    }

    return $slug;
}
