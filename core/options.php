<?php
/**
 * Clean Room CMS - Options API
 *
 * Key-value store for site-wide settings, stored in the options table.
 * Options with autoload='yes' are cached on every page load.
 */

// In-memory cache for autoloaded options
$cr_options_cache = [];
$cr_options_loaded = false;

/**
 * Load all autoloaded options into the cache.
 */
function cr_load_autoloaded_options(): void {
    global $cr_options_cache, $cr_options_loaded;

    if ($cr_options_loaded) {
        return;
    }

    $db = cr_db();
    $table = $db->prefix . 'options';
    $results = $db->get_results("SELECT option_name, option_value FROM `{$table}` WHERE autoload = 'yes'");

    foreach ($results as $row) {
        $cr_options_cache[$row->option_name] = maybe_unserialize($row->option_value);
    }

    $cr_options_loaded = true;
}

/**
 * Retrieve an option value by name.
 */
function get_option(string $name, mixed $default = false): mixed {
    global $cr_options_cache;

    $pre = apply_filters("pre_option_{$name}", false, $name, $default);
    if ($pre !== false) {
        return $pre;
    }

    // Check cache first
    if (array_key_exists($name, $cr_options_cache)) {
        $value = $cr_options_cache[$name];
    } else {
        $db = cr_db();
        $table = $db->prefix . 'options';
        $row = $db->get_row(
            $db->prepare("SELECT option_value FROM `{$table}` WHERE option_name = %s LIMIT 1", $name)
        );

        if ($row === null) {
            return apply_filters("default_option_{$name}", $default, $name);
        }

        $value = maybe_unserialize($row->option_value);
        $cr_options_cache[$name] = $value;
    }

    return apply_filters("option_{$name}", $value, $name);
}

/**
 * Update an option. Creates it if it doesn't exist.
 */
function update_option(string $name, mixed $value, string|bool $autoload = null): bool {
    global $cr_options_cache;

    $value = apply_filters("pre_update_option_{$name}", $value, $name);

    $old_value = get_option($name);

    if ($old_value === $value) {
        return false;
    }

    $serialized = maybe_serialize($value);
    $db = cr_db();
    $table = $db->prefix . 'options';

    $row = $db->get_row(
        $db->prepare("SELECT option_id FROM `{$table}` WHERE option_name = %s LIMIT 1", $name)
    );

    if ($row === null) {
        return add_option($name, $value, $autoload === null ? 'yes' : ($autoload ? 'yes' : 'no'));
    }

    $data = ['option_value' => $serialized];
    if ($autoload !== null) {
        $data['autoload'] = ($autoload === true || $autoload === 'yes') ? 'yes' : 'no';
    }

    $result = $db->update($table, $data, ['option_name' => $name]);

    if ($result !== false) {
        $cr_options_cache[$name] = $value;
        do_action("update_option_{$name}", $old_value, $value, $name);
        do_action('updated_option', $name, $old_value, $value);
        return true;
    }

    return false;
}

/**
 * Add a new option. Does nothing if the option already exists.
 */
function add_option(string $name, mixed $value = '', string $autoload = 'yes'): bool {
    global $cr_options_cache;

    $db = cr_db();
    $table = $db->prefix . 'options';

    $exists = $db->get_var(
        $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE option_name = %s", $name)
    );

    if ((int) $exists > 0) {
        return false;
    }

    $serialized = maybe_serialize($value);

    $result = $db->insert($table, [
        'option_name'  => $name,
        'option_value' => $serialized,
        'autoload'     => $autoload,
    ]);

    if ($result !== false) {
        $cr_options_cache[$name] = $value;
        do_action("add_option_{$name}", $name, $value);
        do_action('added_option', $name, $value);
        return true;
    }

    return false;
}

/**
 * Delete an option by name.
 */
function delete_option(string $name): bool {
    global $cr_options_cache;

    $db = cr_db();
    $table = $db->prefix . 'options';

    do_action("delete_option_{$name}", $name);

    $result = $db->delete($table, ['option_name' => $name]);

    if ($result !== false && $result > 0) {
        unset($cr_options_cache[$name]);
        do_action("deleted_option_{$name}", $name);
        return true;
    }

    return false;
}

// -- Serialization helpers --

function maybe_serialize(mixed $value): string {
    if (is_array($value) || is_object($value)) {
        return serialize($value);
    }
    if (is_serialized($value)) {
        return serialize($value);
    }
    return (string) $value;
}

function maybe_unserialize(string $value): mixed {
    if (is_serialized($value)) {
        // Prevent object injection attacks - only allow arrays and scalars
        return @unserialize($value, ['allowed_classes' => false]);
    }
    return $value;
}

function is_serialized(mixed $data): bool {
    if (!is_string($data)) {
        return false;
    }
    $data = trim($data);
    if ($data === 'N;') {
        return true;
    }
    if (strlen($data) < 4) {
        return false;
    }
    if ($data[1] !== ':') {
        return false;
    }
    $lastc = substr($data, -1);
    if ($lastc !== ';' && $lastc !== '}') {
        return false;
    }
    $token = $data[0];
    return match ($token) {
        's' => str_ends_with(substr($data, -2, 1), '"') || str_ends_with(substr($data, -1), '"'),
        'a', 'O', 'E' => (bool) preg_match('/^[aOE]:\d+:/', $data),
        'b', 'i', 'd' => (bool) preg_match("/^{$token}:[^;]*;$/", $data),
        default => false,
    };
}
