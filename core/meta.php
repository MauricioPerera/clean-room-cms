<?php
/**
 * Clean Room CMS - Meta API
 *
 * Generic metadata system for posts, users, terms, and comments.
 * Each meta type has its own table with (meta_id, object_id, meta_key, meta_value).
 */

/**
 * Add metadata for an object.
 */
function add_metadata(string $meta_type, int $object_id, string $meta_key, mixed $meta_value, bool $unique = false): int|false {
    $db = cr_db();
    $table = _get_meta_table($meta_type);
    if (!$table) return false;

    $column = _get_meta_column($meta_type);

    $meta_key = apply_filters("sanitize_{$meta_type}_meta_{$meta_key}", $meta_key, $meta_type);
    $meta_value = apply_filters("sanitize_{$meta_type}_meta_{$meta_key}_value", $meta_value, $meta_key, $meta_type);

    if ($unique) {
        $count = $db->get_var(
            $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = %d AND `meta_key` = %s", $object_id, $meta_key)
        );
        if ((int) $count > 0) {
            return false;
        }
    }

    $serialized = maybe_serialize($meta_value);

    $result = $db->insert($table, [
        $column      => $object_id,
        'meta_key'   => $meta_key,
        'meta_value' => $serialized,
    ]);

    if ($result !== false) {
        do_action("added_{$meta_type}_meta", $result, $object_id, $meta_key, $meta_value);
        return $result;
    }

    return false;
}

/**
 * Update metadata for an object.
 */
function update_metadata(string $meta_type, int $object_id, string $meta_key, mixed $meta_value, mixed $prev_value = ''): int|bool {
    $db = cr_db();
    $table = _get_meta_table($meta_type);
    if (!$table) return false;

    $column = _get_meta_column($meta_type);
    $serialized = maybe_serialize($meta_value);

    $where = [
        $column    => $object_id,
        'meta_key' => $meta_key,
    ];

    if ($prev_value !== '') {
        $where['meta_value'] = maybe_serialize($prev_value);
    }

    // Check if meta exists
    $id_column = _get_meta_id_column($meta_type);
    $where_sql = $db->prepare("SELECT `{$id_column}` FROM `{$table}` WHERE `{$column}` = %d AND `meta_key` = %s", $object_id, $meta_key);
    if ($prev_value !== '') {
        $where_sql .= $db->prepare(" AND `meta_value` = %s", maybe_serialize($prev_value));
    }
    $where_sql .= " LIMIT 1";

    $meta_id = $db->get_var($where_sql);

    if ($meta_id === null) {
        return add_metadata($meta_type, $object_id, $meta_key, $meta_value);
    }

    $data = ['meta_value' => $serialized];
    $result = $db->update($table, $data, [$id_column => (int) $meta_id]);

    if ($result !== false) {
        do_action("updated_{$meta_type}_meta", (int) $meta_id, $object_id, $meta_key, $meta_value);
        return true;
    }

    return false;
}

/**
 * Delete metadata for an object.
 */
function delete_metadata(string $meta_type, int $object_id, string $meta_key, mixed $meta_value = '', bool $delete_all = false): bool {
    $db = cr_db();
    $table = _get_meta_table($meta_type);
    if (!$table) return false;

    $column = _get_meta_column($meta_type);

    $sql = "DELETE FROM `{$table}` WHERE `meta_key` = " . "'" . $db->escape($meta_key) . "'";

    if (!$delete_all) {
        $sql .= $db->prepare(" AND `{$column}` = %d", $object_id);
    }

    if ($meta_value !== '') {
        $sql .= $db->prepare(" AND `meta_value` = %s", maybe_serialize($meta_value));
    }

    $result = $db->query($sql);

    if ($result !== false) {
        do_action("deleted_{$meta_type}_meta", $object_id, $meta_key, $meta_value);
        return true;
    }

    return false;
}

/**
 * Get metadata for an object.
 */
function get_metadata(string $meta_type, int $object_id, string $meta_key = '', bool $single = false): mixed {
    $db = cr_db();
    $table = _get_meta_table($meta_type);
    if (!$table) return false;

    $column = _get_meta_column($meta_type);

    if (empty($meta_key)) {
        $results = $db->get_results(
            $db->prepare("SELECT meta_key, meta_value FROM `{$table}` WHERE `{$column}` = %d", $object_id)
        );
        $meta = [];
        foreach ($results as $row) {
            $meta[$row->meta_key][] = maybe_unserialize($row->meta_value);
        }
        return $meta;
    }

    $results = $db->get_col(
        $db->prepare("SELECT meta_value FROM `{$table}` WHERE `{$column}` = %d AND `meta_key` = %s", $object_id, $meta_key)
    );

    if (empty($results)) {
        return $single ? '' : [];
    }

    if ($single) {
        return maybe_unserialize($results[0]);
    }

    return array_map('maybe_unserialize', $results);
}

// -- Post meta convenience functions --

function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed {
    return get_metadata('post', $post_id, $key, $single);
}

function add_post_meta(int $post_id, string $key, mixed $value, bool $unique = false): int|false {
    return add_metadata('post', $post_id, $key, $value, $unique);
}

function update_post_meta(int $post_id, string $key, mixed $value, mixed $prev = ''): int|bool {
    return update_metadata('post', $post_id, $key, $value, $prev);
}

function delete_post_meta(int $post_id, string $key, mixed $value = ''): bool {
    return delete_metadata('post', $post_id, $key, $value);
}

// -- User meta convenience functions --

function get_user_meta(int $user_id, string $key = '', bool $single = false): mixed {
    return get_metadata('user', $user_id, $key, $single);
}

function add_user_meta(int $user_id, string $key, mixed $value, bool $unique = false): int|false {
    return add_metadata('user', $user_id, $key, $value, $unique);
}

function update_user_meta(int $user_id, string $key, mixed $value, mixed $prev = ''): int|bool {
    return update_metadata('user', $user_id, $key, $value, $prev);
}

function delete_user_meta(int $user_id, string $key, mixed $value = ''): bool {
    return delete_metadata('user', $user_id, $key, $value);
}

// -- Term meta convenience functions --

function get_term_meta(int $term_id, string $key = '', bool $single = false): mixed {
    return get_metadata('term', $term_id, $key, $single);
}

function add_term_meta(int $term_id, string $key, mixed $value, bool $unique = false): int|false {
    return add_metadata('term', $term_id, $key, $value, $unique);
}

function update_term_meta(int $term_id, string $key, mixed $value, mixed $prev = ''): int|bool {
    return update_metadata('term', $term_id, $key, $value, $prev);
}

function delete_term_meta(int $term_id, string $key, mixed $value = ''): bool {
    return delete_metadata('term', $term_id, $key, $value);
}

// -- Comment meta convenience functions --

function get_comment_meta(int $comment_id, string $key = '', bool $single = false): mixed {
    return get_metadata('comment', $comment_id, $key, $single);
}

function add_comment_meta(int $comment_id, string $key, mixed $value, bool $unique = false): int|false {
    return add_metadata('comment', $comment_id, $key, $value, $unique);
}

function update_comment_meta(int $comment_id, string $key, mixed $value, mixed $prev = ''): int|bool {
    return update_metadata('comment', $comment_id, $key, $value, $prev);
}

function delete_comment_meta(int $comment_id, string $key, mixed $value = ''): bool {
    return delete_metadata('comment', $comment_id, $key, $value);
}

// -- Internal helpers --

function _get_meta_table(string $meta_type): string|false {
    $db = cr_db();
    return match ($meta_type) {
        'post'    => $db->prefix . 'postmeta',
        'user'    => $db->prefix . 'usermeta',
        'term'    => $db->prefix . 'termmeta',
        'comment' => $db->prefix . 'commentmeta',
        default   => false,
    };
}

function _get_meta_id_column(string $meta_type): string {
    return match ($meta_type) {
        'user' => 'umeta_id',
        default => 'meta_id',
    };
}

function _get_meta_column(string $meta_type): string {
    return match ($meta_type) {
        'user' => 'user_id',
        'comment' => 'comment_id',
        default => "{$meta_type}_id",
    };
}
