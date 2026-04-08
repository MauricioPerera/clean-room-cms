<?php
/**
 * Clean Room CMS - Content Builder
 *
 * Database-driven content type, taxonomy, and meta field management.
 * Define custom content structures from the admin UI or API.
 * Auto-registers types/taxonomies on every request.
 */

// Built-in types that cannot be deleted or overridden
define('CR_BUILTIN_TYPES', ['post', 'page', 'attachment', 'revision', 'nav_menu_item']);
define('CR_BUILTIN_TAXONOMIES', ['category', 'post_tag']);

// =============================================
// Schema installation
// =============================================

function cr_content_builder_install(): void {
    $db = cr_db();
    $tables = [
        $db->prefix . 'content_types',
        $db->prefix . 'content_taxonomies',
        $db->prefix . 'meta_fields',
    ];

    // Check if tables exist
    foreach ($tables as $table) {
        $exists = $db->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$exists) {
            // Load schema and create missing tables
            $schema = file_get_contents(CR_BASE_PATH . '/install/schema.sql');
            $schema = str_replace('{prefix}', $db->prefix, $schema);
            $schema = preg_replace('/^--.*$/m', '', $schema);

            $statements = array_filter(
                array_map('trim', explode(';', $schema)),
                fn($s) => !empty($s) && strlen($s) > 5 && stripos($s, $table) !== false
            );

            foreach ($statements as $sql) {
                $db->query($sql);
            }
        }
    }
}

// =============================================
// Content Types CRUD
// =============================================

function cr_save_content_type(array $data): int|false {
    $db = cr_db();
    $table = $db->prefix . 'content_types';

    $name = cr_sanitize_title($data['name'] ?? '');
    if (empty($name) || strlen($name) > 20) return false;
    if (in_array($name, CR_BUILTIN_TYPES)) return false;

    $row = [
        'name'                => $name,
        'label'               => trim($data['label'] ?? ucfirst($name)),
        'label_singular'      => trim($data['label_singular'] ?? ''),
        'description'         => trim($data['description'] ?? ''),
        'icon'                => trim($data['icon'] ?? ''),
        'public'              => (int) ($data['public'] ?? 1),
        'hierarchical'        => (int) ($data['hierarchical'] ?? 0),
        'show_in_rest'        => (int) ($data['show_in_rest'] ?? 1),
        'rest_base'           => trim($data['rest_base'] ?? '') ?: $name . 's',
        'has_archive'         => (int) ($data['has_archive'] ?? 1),
        'supports'            => json_encode($data['supports'] ?? ['title', 'editor']),
        'exclude_from_search' => (int) ($data['exclude_from_search'] ?? 0),
        'menu_position'       => (int) ($data['menu_position'] ?? 25),
        'status'              => $data['status'] ?? 'active',
    ];

    // Check if exists (update vs insert)
    $existing = $db->get_var($db->prepare("SELECT id FROM `{$table}` WHERE name = %s", $name));

    if ($existing) {
        $db->update($table, $row, ['id' => (int) $existing]);
        return (int) $existing;
    }

    $row['created_at'] = gmdate('Y-m-d H:i:s');
    return $db->insert($table, $row);
}

function cr_delete_content_type(string $name): bool {
    if (in_array($name, CR_BUILTIN_TYPES)) return false;

    $db = cr_db();
    $result = $db->delete($db->prefix . 'content_types', ['name' => $name]);

    // Also delete associated meta fields
    $db->query($db->prepare("DELETE FROM `{$db->prefix}meta_fields` WHERE post_type = %s", $name));

    // Unlink taxonomies
    // (don't delete taxonomies, just remove this type from their post_types)
    $taxes = cr_get_content_taxonomies();
    foreach ($taxes as $tax) {
        $types = json_decode($tax->post_types, true) ?: [];
        if (in_array($name, $types)) {
            $types = array_values(array_diff($types, [$name]));
            $db->update($db->prefix . 'content_taxonomies', [
                'post_types' => json_encode($types),
            ], ['id' => $tax->id]);
        }
    }

    return $result > 0;
}

function cr_get_content_types(): array {
    $db = cr_db();
    return $db->get_results("SELECT * FROM `{$db->prefix}content_types` ORDER BY menu_position ASC, label ASC");
}

function cr_get_content_type(string $name): ?object {
    $db = cr_db();
    return $db->get_row($db->prepare("SELECT * FROM `{$db->prefix}content_types` WHERE name = %s", $name));
}

/**
 * Load DB content types and register them with the CMS.
 */
function cr_load_db_content_types(): void {
    $types = cr_get_content_types();
    foreach ($types as $type) {
        if ($type->status !== 'active') continue;
        if (in_array($type->name, CR_BUILTIN_TYPES)) continue;

        $supports = json_decode($type->supports, true) ?: ['title', 'editor'];

        register_post_type($type->name, [
            'label'               => $type->label,
            'description'         => $type->description ?? '',
            'public'              => (bool) $type->public,
            'hierarchical'        => (bool) $type->hierarchical,
            'show_in_rest'        => (bool) $type->show_in_rest,
            'rest_base'           => $type->rest_base ?: $type->name . 's',
            'has_archive'         => (bool) $type->has_archive,
            'supports'            => $supports,
            'exclude_from_search' => (bool) $type->exclude_from_search,
            'show_ui'             => (bool) $type->public,
            'show_in_menu'        => (bool) $type->public,
            'menu_position'       => (int) $type->menu_position,
            'menu_icon'           => $type->icon ?: null,
        ]);
    }
}

// =============================================
// Content Taxonomies CRUD
// =============================================

function cr_save_content_taxonomy(array $data): int|false {
    $db = cr_db();
    $table = $db->prefix . 'content_taxonomies';

    $name = cr_sanitize_title($data['name'] ?? '');
    if (empty($name) || strlen($name) > 32) return false;
    if (in_array($name, CR_BUILTIN_TAXONOMIES)) return false;

    $post_types = $data['post_types'] ?? [];
    if (is_string($post_types)) $post_types = array_filter(array_map('trim', explode(',', $post_types)));

    $row = [
        'name'            => $name,
        'label'           => trim($data['label'] ?? ucfirst($name)),
        'label_singular'  => trim($data['label_singular'] ?? ''),
        'description'     => trim($data['description'] ?? ''),
        'hierarchical'    => (int) ($data['hierarchical'] ?? 0),
        'public'          => (int) ($data['public'] ?? 1),
        'show_in_rest'    => (int) ($data['show_in_rest'] ?? 1),
        'rest_base'       => trim($data['rest_base'] ?? '') ?: $name,
        'post_types'      => json_encode($post_types),
        'status'          => $data['status'] ?? 'active',
    ];

    $existing = $db->get_var($db->prepare("SELECT id FROM `{$table}` WHERE name = %s", $name));

    if ($existing) {
        $db->update($table, $row, ['id' => (int) $existing]);
        return (int) $existing;
    }

    $row['created_at'] = gmdate('Y-m-d H:i:s');
    return $db->insert($table, $row);
}

function cr_delete_content_taxonomy(string $name): bool {
    if (in_array($name, CR_BUILTIN_TAXONOMIES)) return false;

    $db = cr_db();
    return $db->delete($db->prefix . 'content_taxonomies', ['name' => $name]) > 0;
}

function cr_get_content_taxonomies(): array {
    $db = cr_db();
    return $db->get_results("SELECT * FROM `{$db->prefix}content_taxonomies` ORDER BY label ASC");
}

function cr_get_content_taxonomy(string $name): ?object {
    $db = cr_db();
    return $db->get_row($db->prepare("SELECT * FROM `{$db->prefix}content_taxonomies` WHERE name = %s", $name));
}

/**
 * Load DB taxonomies and register them with the CMS.
 */
function cr_load_db_taxonomies(): void {
    $taxonomies = cr_get_content_taxonomies();
    foreach ($taxonomies as $tax) {
        if ($tax->status !== 'active') continue;
        if (in_array($tax->name, CR_BUILTIN_TAXONOMIES)) continue;

        $post_types = json_decode($tax->post_types, true) ?: [];

        register_taxonomy($tax->name, $post_types, [
            'label'        => $tax->label,
            'description'  => $tax->description ?? '',
            'hierarchical' => (bool) $tax->hierarchical,
            'public'       => (bool) $tax->public,
            'show_in_rest' => (bool) $tax->show_in_rest,
            'rest_base'    => $tax->rest_base ?: $tax->name,
        ]);
    }
}

// =============================================
// Meta Fields CRUD
// =============================================

function cr_save_meta_field(array $data): int|false {
    $db = cr_db();
    $table = $db->prefix . 'meta_fields';

    $name = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($data['name'] ?? '')));
    if (empty($name)) return false;

    $row = [
        'name'          => $name,
        'label'         => trim($data['label'] ?? ucfirst(str_replace('_', ' ', $name))),
        'description'   => trim($data['description'] ?? ''),
        'object_type'   => $data['object_type'] ?? 'post',
        'post_type'     => $data['post_type'] ?? '',
        'field_type'    => $data['field_type'] ?? 'text',
        'options'       => json_encode($data['options'] ?? []),
        'default_value' => $data['default_value'] ?? '',
        'placeholder'   => $data['placeholder'] ?? '',
        'required'      => (int) ($data['required'] ?? 0),
        'validation'    => json_encode($data['validation'] ?? []),
        'position'      => (int) ($data['position'] ?? 0),
        'group_name'    => trim($data['group_name'] ?? 'Custom Fields') ?: 'Custom Fields',
        'show_in_rest'  => (int) ($data['show_in_rest'] ?? 1),
        'show_in_list'  => (int) ($data['show_in_list'] ?? 0),
        'searchable'    => (int) ($data['searchable'] ?? 0),
        'status'        => $data['status'] ?? 'active',
    ];

    $id = (int) ($data['id'] ?? 0);

    if ($id) {
        $db->update($table, $row, ['id' => $id]);
        return $id;
    }

    $row['created_at'] = gmdate('Y-m-d H:i:s');
    return $db->insert($table, $row);
}

function cr_delete_meta_field(int $id): bool {
    $db = cr_db();
    return $db->delete($db->prefix . 'meta_fields', ['id' => $id]) > 0;
}

function cr_get_meta_fields(string $post_type = '', string $object_type = 'post'): array {
    $db = cr_db();
    $table = $db->prefix . 'meta_fields';

    $sql = $db->prepare("SELECT * FROM `{$table}` WHERE object_type = %s AND status = 'active'", $object_type);

    if (!empty($post_type)) {
        $sql .= $db->prepare(" AND (post_type = %s OR post_type = '')", $post_type);
    }

    $sql .= " ORDER BY `group_name` ASC, `position` ASC, `label` ASC";

    return $db->get_results($sql);
}

function cr_get_meta_field(int $id): ?object {
    $db = cr_db();
    return $db->get_row($db->prepare("SELECT * FROM `{$db->prefix}meta_fields` WHERE id = %d", $id));
}

// =============================================
// Field Types Registry
// =============================================

function cr_get_field_types(): array {
    return [
        'text'     => ['label' => 'Text',          'input' => 'text'],
        'textarea' => ['label' => 'Textarea',       'input' => 'textarea'],
        'number'   => ['label' => 'Number',         'input' => 'number'],
        'email'    => ['label' => 'Email',           'input' => 'email'],
        'url'      => ['label' => 'URL',             'input' => 'url'],
        'tel'      => ['label' => 'Phone',           'input' => 'tel'],
        'date'     => ['label' => 'Date',            'input' => 'date'],
        'datetime' => ['label' => 'Date & Time',     'input' => 'datetime-local'],
        'time'     => ['label' => 'Time',            'input' => 'time'],
        'select'   => ['label' => 'Select Dropdown', 'input' => 'select'],
        'radio'    => ['label' => 'Radio Buttons',   'input' => 'radio'],
        'checkbox' => ['label' => 'Checkbox',        'input' => 'checkbox'],
        'color'    => ['label' => 'Color Picker',    'input' => 'color'],
        'range'    => ['label' => 'Range Slider',    'input' => 'range'],
        'image'    => ['label' => 'Image URL',       'input' => 'url'],
        'wysiwyg'  => ['label' => 'Rich Text',       'input' => 'textarea'],
    ];
}

// =============================================
// Meta Field Rendering
// =============================================

function cr_render_meta_field(array $field, mixed $value = null): string {
    $name = 'meta_' . esc_attr($field['name']);
    $id = 'field_' . esc_attr($field['name']);
    $label = esc_html($field['label']);
    $required = $field['required'] ? ' required' : '';
    $req_badge = $field['required'] ? ' <span style="color:#d63638">*</span>' : '';
    $placeholder = esc_attr($field['placeholder'] ?? '');
    $description = $field['description'] ? '<p class="field-desc">' . esc_html($field['description']) . '</p>' : '';

    if ($value === null) $value = $field['default_value'] ?? '';

    $options = is_string($field['options'] ?? '') ? json_decode($field['options'], true) : ($field['options'] ?? []);

    $html = '<div class="form-group meta-field">';
    $html .= '<label for="' . $id . '">' . $label . $req_badge . '</label>';

    switch ($field['field_type']) {
        case 'textarea':
        case 'wysiwyg':
            $rows = $field['field_type'] === 'wysiwyg' ? 12 : 4;
            $html .= '<textarea id="' . $id . '" name="' . $name . '" rows="' . $rows . '" class="input-full" placeholder="' . $placeholder . '"' . $required . '>' . esc_html($value) . '</textarea>';
            break;

        case 'select':
            $html .= '<select id="' . $id . '" name="' . $name . '"' . $required . '>';
            $html .= '<option value="">-- Select --</option>';
            foreach ($options as $opt) {
                $opt_val = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                $opt_label = is_array($opt) ? ($opt['label'] ?? $opt_val) : $opt;
                $selected = ($value == $opt_val) ? ' selected' : '';
                $html .= '<option value="' . esc_attr($opt_val) . '"' . $selected . '>' . esc_html($opt_label) . '</option>';
            }
            $html .= '</select>';
            break;

        case 'radio':
            foreach ($options as $opt) {
                $opt_val = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                $opt_label = is_array($opt) ? ($opt['label'] ?? $opt_val) : $opt;
                $checked = ($value == $opt_val) ? ' checked' : '';
                $html .= '<label class="checkbox-label"><input type="radio" name="' . $name . '" value="' . esc_attr($opt_val) . '"' . $checked . '> ' . esc_html($opt_label) . '</label>';
            }
            break;

        case 'checkbox':
            $checked = $value ? ' checked' : '';
            $html .= '<label class="checkbox-label"><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . $checked . '> ' . esc_html($field['description'] ?: 'Enable') . '</label>';
            $description = '';
            break;

        default:
            $type = cr_get_field_types()[$field['field_type']]['input'] ?? 'text';
            $extra = '';
            $validation = is_string($field['validation'] ?? '') ? json_decode($field['validation'], true) : ($field['validation'] ?? []);
            if (isset($validation['min'])) $extra .= ' min="' . esc_attr($validation['min']) . '"';
            if (isset($validation['max'])) $extra .= ' max="' . esc_attr($validation['max']) . '"';
            if (isset($validation['step'])) $extra .= ' step="' . esc_attr($validation['step']) . '"';
            if (isset($validation['pattern'])) $extra .= ' pattern="' . esc_attr($validation['pattern']) . '"';

            $html .= '<input type="' . $type . '" id="' . $id . '" name="' . $name . '" value="' . esc_attr($value) . '" class="input-full" placeholder="' . $placeholder . '"' . $required . $extra . '>';
            break;
    }

    $html .= $description;
    $html .= '</div>';

    return $html;
}

/**
 * Render all meta fields for a post type in the editor form.
 */
function cr_render_meta_fields_form(string $post_type, int $post_id = 0): string {
    $fields = cr_get_meta_fields($post_type);
    if (empty($fields)) return '';

    // Group fields
    $groups = [];
    foreach ($fields as $field) {
        $group = $field->group_name ?: 'Custom Fields';
        $groups[$group][] = $field;
    }

    $html = '';
    foreach ($groups as $group_name => $group_fields) {
        $html .= '<div class="meta-group">';
        $html .= '<h3 class="meta-group-title">' . esc_html($group_name) . '</h3>';

        foreach ($group_fields as $field) {
            $value = null;
            if ($post_id) {
                $value = get_post_meta($post_id, $field->name, true);
            }

            $html .= cr_render_meta_field((array) $field, $value);
        }

        $html .= '</div>';
    }

    return $html;
}

/**
 * Save meta field values from $_POST for a post.
 */
function cr_save_meta_fields_from_post(int $post_id, string $post_type): void {
    $fields = cr_get_meta_fields($post_type);

    foreach ($fields as $field) {
        $key = 'meta_' . $field->name;

        if ($field->field_type === 'checkbox') {
            $value = isset($_POST[$key]) ? '1' : '0';
        } else {
            $value = $_POST[$key] ?? '';
        }

        // Validate
        $error = cr_validate_meta_field((array) $field, $value);
        if ($error) continue;

        update_post_meta($post_id, $field->name, $value);
    }
}

/**
 * Validate a meta field value.
 */
function cr_validate_meta_field(array $field, mixed $value): ?string {
    if ($field['required'] && empty($value) && $value !== '0') {
        return "Field '{$field['label']}' is required.";
    }

    $validation = is_string($field['validation'] ?? '') ? json_decode($field['validation'], true) : ($field['validation'] ?? []);

    if (isset($validation['min']) && $validation['min'] !== '' && is_numeric($value) && $value < $validation['min']) {
        return "Field '{$field['label']}' must be at least {$validation['min']}.";
    }
    if (isset($validation['max']) && $validation['max'] !== '' && is_numeric($value) && $value > $validation['max']) {
        return "Field '{$field['label']}' must be at most {$validation['max']}.";
    }
    if (!empty($validation['pattern']) && !preg_match('/' . $validation['pattern'] . '/', $value)) {
        return "Field '{$field['label']}' has an invalid format.";
    }

    if ($field['field_type'] === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return "Field '{$field['label']}' must be a valid email address.";
    }
    if ($field['field_type'] === 'url' && !empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
        return "Field '{$field['label']}' must be a valid URL.";
    }

    return null;
}

/**
 * Get all registered post types (built-in + DB) suitable for dropdowns.
 */
function cr_get_all_post_types_for_select(): array {
    $builtin = [
        'post' => 'Posts',
        'page' => 'Pages',
    ];

    $custom = [];
    foreach (cr_get_content_types() as $type) {
        if ($type->status === 'active') {
            $custom[$type->name] = $type->label;
        }
    }

    return array_merge($builtin, $custom);
}
