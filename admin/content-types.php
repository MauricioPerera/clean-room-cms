<?php
/**
 * Clean Room CMS - Admin Content Type Builder Pages
 *
 * CRUD UI for content types, taxonomies, and meta fields.
 * Included from admin/index.php via routing.
 */

// =============================================
// Content Types
// =============================================

function cr_admin_content_types_list(): void {
    $types = cr_get_content_types();
    $db = cr_db();
    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header">
        <h1>Content Types</h1>
        <a href="?page=content-type-edit" class="btn btn-primary">Add New</a>
    </div>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">Content type saved.</div><?php endif; ?>
    <?php if ($msg === 'deleted'): ?><div class="admin-notice success">Content type deleted.</div><?php endif; ?>

    <table class="admin-table">
        <thead><tr><th>Icon</th><th>Name</th><th>Slug</th><th>Posts</th><th>REST</th><th>Search</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($types as $type):
            $count = (int) $db->get_var($db->prepare("SELECT COUNT(*) FROM `{$db->prefix}posts` WHERE post_type = %s AND post_status != 'auto-draft'", $type->name));
        ?>
            <tr>
                <td><?php echo $type->icon ? esc_html($type->icon) : '📄'; ?></td>
                <td><strong><a href="?page=content-type-edit&name=<?php echo esc_attr($type->name); ?>"><?php echo esc_html($type->label); ?></a></strong></td>
                <td><code><?php echo esc_html($type->name); ?></code></td>
                <td><?php echo $count; ?></td>
                <td><?php echo $type->show_in_rest ? '✓' : '—'; ?></td>
                <td><?php echo $type->exclude_from_search ? 'Excluded' : '✓'; ?></td>
                <td><span class="status-badge status-<?php echo $type->status === 'active' ? 'publish' : 'draft'; ?>"><?php echo esc_html($type->status); ?></span></td>
                <td>
                    <a href="?page=content-type-edit&name=<?php echo esc_attr($type->name); ?>">Edit</a>
                    <a href="?page=type-<?php echo esc_attr($type->name); ?>">View Items</a>
                    <a href="?page=content-types&action=delete&name=<?php echo esc_attr($type->name); ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="text-danger" onclick="return confirm('Delete this content type? Posts of this type will remain in the database.')">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($types)): ?>
            <tr><td colspan="8">No custom content types yet. Built-in types (Post, Page) are always available.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php
}

function cr_admin_content_type_edit(): void {
    $name = $_GET['name'] ?? '';
    $type = $name ? cr_get_content_type($name) : null;
    $supports_options = ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments', 'revisions', 'page-attributes'];
    $current_supports = $type ? (json_decode($type->supports, true) ?: []) : ['title', 'editor'];
?>
    <div class="admin-header">
        <h1><?php echo $type ? 'Edit Content Type' : 'New Content Type'; ?></h1>
    </div>

    <form method="post" action="?page=content-type-edit<?php echo $type ? '&name=' . esc_attr($type->name) : ''; ?>">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="_action" value="save_content_type">

        <div class="form-row">
            <div class="form-group" style="flex:1">
                <label for="ct_name">Slug (unique identifier)</label>
                <input type="text" id="ct_name" name="name" value="<?php echo esc_attr($type->name ?? ''); ?>" class="input-full" placeholder="product" pattern="[a-z0-9_-]+" maxlength="20" required <?php echo $type ? 'readonly' : ''; ?>>
            </div>
            <div class="form-group" style="flex:1">
                <label for="ct_icon">Icon (emoji)</label>
                <input type="text" id="ct_icon" name="icon" value="<?php echo esc_attr($type->icon ?? ''); ?>" class="input-full" placeholder="📦" maxlength="10">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group" style="flex:1">
                <label for="ct_label">Label (plural)</label>
                <input type="text" id="ct_label" name="label" value="<?php echo esc_attr($type->label ?? ''); ?>" class="input-full" placeholder="Products" required>
            </div>
            <div class="form-group" style="flex:1">
                <label for="ct_label_singular">Label (singular)</label>
                <input type="text" id="ct_label_singular" name="label_singular" value="<?php echo esc_attr($type->label_singular ?? ''); ?>" class="input-full" placeholder="Product">
            </div>
        </div>

        <div class="form-group">
            <label for="ct_description">Description</label>
            <textarea id="ct_description" name="description" rows="2" class="input-full"><?php echo esc_html($type->description ?? ''); ?></textarea>
        </div>

        <div class="form-group">
            <label>Supports</label>
            <div class="checkbox-list" style="max-height:none">
                <?php foreach ($supports_options as $s): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="supports[]" value="<?php echo $s; ?>" <?php echo in_array($s, $current_supports) ? 'checked' : ''; ?>>
                        <?php echo ucfirst(str_replace('-', ' ', $s)); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label>Options</label>
            <div class="checkbox-list" style="max-height:none">
                <label class="checkbox-label"><input type="checkbox" name="public" value="1" <?php echo ($type->public ?? 1) ? 'checked' : ''; ?>> Public</label>
                <label class="checkbox-label"><input type="checkbox" name="hierarchical" value="1" <?php echo ($type->hierarchical ?? 0) ? 'checked' : ''; ?>> Hierarchical (like pages)</label>
                <label class="checkbox-label"><input type="checkbox" name="has_archive" value="1" <?php echo ($type->has_archive ?? 1) ? 'checked' : ''; ?>> Has archive page</label>
                <label class="checkbox-label"><input type="checkbox" name="show_in_rest" value="1" <?php echo ($type->show_in_rest ?? 1) ? 'checked' : ''; ?>> Show in REST API</label>
                <label class="checkbox-label"><input type="checkbox" name="exclude_from_search" value="1" <?php echo ($type->exclude_from_search ?? 0) ? 'checked' : ''; ?>> Exclude from search results</label>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group" style="flex:1">
                <label for="ct_rest_base">REST API endpoint</label>
                <input type="text" id="ct_rest_base" name="rest_base" value="<?php echo esc_attr($type->rest_base ?? ''); ?>" class="input-full" placeholder="products (auto-generated)">
            </div>
            <div class="form-group" style="flex:1">
                <label for="ct_menu_position">Menu position</label>
                <input type="number" id="ct_menu_position" name="menu_position" value="<?php echo esc_attr($type->menu_position ?? 25); ?>" min="1" max="100" style="width:100px">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?php echo $type ? 'Update Content Type' : 'Create Content Type'; ?></button>
            <a href="?page=content-types" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
<?php
}

// =============================================
// Content Taxonomies
// =============================================

function cr_admin_content_taxonomies_list(): void {
    $taxonomies = cr_get_content_taxonomies();
    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header">
        <h1>Custom Taxonomies</h1>
        <a href="?page=content-taxonomy-edit" class="btn btn-primary">Add New</a>
    </div>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">Taxonomy saved.</div><?php endif; ?>
    <?php if ($msg === 'deleted'): ?><div class="admin-notice success">Taxonomy deleted.</div><?php endif; ?>

    <table class="admin-table">
        <thead><tr><th>Name</th><th>Slug</th><th>Type</th><th>Linked To</th><th>REST</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($taxonomies as $tax):
            $post_types = json_decode($tax->post_types, true) ?: [];
        ?>
            <tr>
                <td><strong><a href="?page=content-taxonomy-edit&name=<?php echo esc_attr($tax->name); ?>"><?php echo esc_html($tax->label); ?></a></strong></td>
                <td><code><?php echo esc_html($tax->name); ?></code></td>
                <td><?php echo $tax->hierarchical ? 'Hierarchical' : 'Flat'; ?></td>
                <td><?php echo $post_types ? esc_html(implode(', ', $post_types)) : '<em>none</em>'; ?></td>
                <td><?php echo $tax->show_in_rest ? '✓' : '—'; ?></td>
                <td><span class="status-badge status-<?php echo $tax->status === 'active' ? 'publish' : 'draft'; ?>"><?php echo esc_html($tax->status); ?></span></td>
                <td>
                    <a href="?page=content-taxonomy-edit&name=<?php echo esc_attr($tax->name); ?>">Edit</a>
                    <a href="?page=content-taxonomies&action=delete&name=<?php echo esc_attr($tax->name); ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="text-danger" onclick="return confirm('Delete this taxonomy?')">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($taxonomies)): ?>
            <tr><td colspan="7">No custom taxonomies yet. Built-in taxonomies (Category, Tag) are always available.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php
}

function cr_admin_content_taxonomy_edit(): void {
    $name = $_GET['name'] ?? '';
    $tax = $name ? cr_get_content_taxonomy($name) : null;
    $all_types = cr_get_all_post_types_for_select();
    $linked = $tax ? (json_decode($tax->post_types, true) ?: []) : [];
?>
    <div class="admin-header">
        <h1><?php echo $tax ? 'Edit Taxonomy' : 'New Taxonomy'; ?></h1>
    </div>

    <form method="post" action="?page=content-taxonomy-edit<?php echo $tax ? '&name=' . esc_attr($tax->name) : ''; ?>">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="_action" value="save_content_taxonomy">

        <div class="form-row">
            <div class="form-group" style="flex:1">
                <label for="tax_name">Slug</label>
                <input type="text" id="tax_name" name="name" value="<?php echo esc_attr($tax->name ?? ''); ?>" class="input-full" placeholder="brand" pattern="[a-z0-9_-]+" maxlength="32" required <?php echo $tax ? 'readonly' : ''; ?>>
            </div>
            <div class="form-group" style="flex:1">
                <label for="tax_label">Label (plural)</label>
                <input type="text" id="tax_label" name="label" value="<?php echo esc_attr($tax->label ?? ''); ?>" class="input-full" placeholder="Brands" required>
            </div>
        </div>

        <div class="form-group">
            <label for="tax_label_singular">Label (singular)</label>
            <input type="text" id="tax_label_singular" name="label_singular" value="<?php echo esc_attr($tax->label_singular ?? ''); ?>" class="input-full" placeholder="Brand">
        </div>

        <div class="form-group">
            <label>Linked Post Types</label>
            <div class="checkbox-list" style="max-height:none">
                <?php foreach ($all_types as $slug => $label): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($slug); ?>" <?php echo in_array($slug, $linked) ? 'checked' : ''; ?>>
                        <?php echo esc_html($label); ?> <code>(<?php echo esc_html($slug); ?>)</code>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label>Options</label>
            <div class="checkbox-list" style="max-height:none">
                <label class="checkbox-label"><input type="checkbox" name="hierarchical" value="1" <?php echo ($tax->hierarchical ?? 0) ? 'checked' : ''; ?>> Hierarchical (like categories, with parent/child)</label>
                <label class="checkbox-label"><input type="checkbox" name="public" value="1" <?php echo ($tax->public ?? 1) ? 'checked' : ''; ?>> Public</label>
                <label class="checkbox-label"><input type="checkbox" name="show_in_rest" value="1" <?php echo ($tax->show_in_rest ?? 1) ? 'checked' : ''; ?>> Show in REST API</label>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?php echo $tax ? 'Update Taxonomy' : 'Create Taxonomy'; ?></button>
            <a href="?page=content-taxonomies" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
<?php
}

// =============================================
// Meta Fields
// =============================================

function cr_admin_meta_fields_list(): void {
    $db = cr_db();
    $fields = $db->get_results("SELECT * FROM `{$db->prefix}meta_fields` ORDER BY post_type ASC, position ASC, label ASC");
    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header">
        <h1>Meta Fields</h1>
        <a href="?page=meta-field-edit" class="btn btn-primary">Add New</a>
    </div>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">Field saved.</div><?php endif; ?>
    <?php if ($msg === 'deleted'): ?><div class="admin-notice success">Field deleted.</div><?php endif; ?>

    <table class="admin-table">
        <thead><tr><th>Label</th><th>Key</th><th>Type</th><th>Post Type</th><th>Group</th><th>Required</th><th>API</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($fields as $f): ?>
            <tr>
                <td><strong><a href="?page=meta-field-edit&id=<?php echo $f->id; ?>"><?php echo esc_html($f->label); ?></a></strong></td>
                <td><code><?php echo esc_html($f->name); ?></code></td>
                <td><?php echo esc_html(cr_get_field_types()[$f->field_type]['label'] ?? $f->field_type); ?></td>
                <td><?php echo $f->post_type ? '<code>' . esc_html($f->post_type) . '</code>' : '<em>All</em>'; ?></td>
                <td><?php echo esc_html($f->group_name); ?></td>
                <td><?php echo $f->required ? '<span style="color:#d63638">Yes</span>' : '—'; ?></td>
                <td><?php echo $f->show_in_rest ? '✓' : '—'; ?></td>
                <td>
                    <a href="?page=meta-field-edit&id=<?php echo $f->id; ?>">Edit</a>
                    <a href="?page=meta-fields&action=delete&id=<?php echo $f->id; ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="text-danger" onclick="return confirm('Delete this field? Existing data in posts will not be removed.')">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($fields)): ?>
            <tr><td colspan="8">No custom meta fields defined yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php
}

function cr_admin_meta_field_edit(): void {
    $id = (int) ($_GET['id'] ?? 0);
    $field = $id ? cr_get_meta_field($id) : null;
    $all_types = cr_get_all_post_types_for_select();
    $field_types = cr_get_field_types();
    $current_options = $field ? (json_decode($field->options, true) ?: []) : [];
    $options_str = '';
    foreach ($current_options as $opt) {
        $options_str .= (is_array($opt) ? ($opt['value'] ?? '') . ':' . ($opt['label'] ?? '') : $opt) . "\n";
    }
?>
    <div class="admin-header">
        <h1><?php echo $field ? 'Edit Meta Field' : 'New Meta Field'; ?></h1>
    </div>

    <form method="post" action="?page=meta-field-edit<?php echo $field ? '&id=' . $field->id : ''; ?>">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="_action" value="save_meta_field">
        <?php if ($field): ?><input type="hidden" name="field_id" value="<?php echo $field->id; ?>"><?php endif; ?>

        <div class="form-row">
            <div class="form-group" style="flex:1">
                <label for="mf_name">Field key</label>
                <input type="text" id="mf_name" name="name" value="<?php echo esc_attr($field->name ?? ''); ?>" class="input-full" placeholder="price" pattern="[a-z0-9_]+" required>
            </div>
            <div class="form-group" style="flex:1">
                <label for="mf_label">Label</label>
                <input type="text" id="mf_label" name="label" value="<?php echo esc_attr($field->label ?? ''); ?>" class="input-full" placeholder="Price" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group" style="flex:1">
                <label for="mf_field_type">Field Type</label>
                <select id="mf_field_type" name="field_type" required>
                    <?php foreach ($field_types as $key => $ft): ?>
                        <option value="<?php echo $key; ?>" <?php echo ($field->field_type ?? 'text') === $key ? 'selected' : ''; ?>><?php echo esc_html($ft['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1">
                <label for="mf_post_type">Post Type</label>
                <select id="mf_post_type" name="post_type">
                    <option value="">All post types</option>
                    <?php foreach ($all_types as $slug => $label): ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php echo ($field->post_type ?? '') === $slug ? 'selected' : ''; ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label for="mf_description">Description / Help text</label>
            <input type="text" id="mf_description" name="description" value="<?php echo esc_attr($field->description ?? ''); ?>" class="input-full" placeholder="Shown below the field">
        </div>

        <div class="form-row">
            <div class="form-group" style="flex:1">
                <label for="mf_default">Default value</label>
                <input type="text" id="mf_default" name="default_value" value="<?php echo esc_attr($field->default_value ?? ''); ?>" class="input-full">
            </div>
            <div class="form-group" style="flex:1">
                <label for="mf_placeholder">Placeholder</label>
                <input type="text" id="mf_placeholder" name="placeholder" value="<?php echo esc_attr($field->placeholder ?? ''); ?>" class="input-full">
            </div>
        </div>

        <div class="form-group">
            <label for="mf_options">Options (for Select/Radio, one per line: value:label)</label>
            <textarea id="mf_options" name="options_text" rows="4" class="input-full" placeholder="red:Red&#10;blue:Blue&#10;green:Green"><?php echo esc_html(trim($options_str)); ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group" style="flex:1">
                <label for="mf_group">Field Group</label>
                <input type="text" id="mf_group" name="group_name" value="<?php echo esc_attr($field->group_name ?? 'Custom Fields'); ?>" class="input-full" placeholder="Custom Fields">
            </div>
            <div class="form-group" style="flex:1">
                <label for="mf_position">Position (sort order)</label>
                <input type="number" id="mf_position" name="position" value="<?php echo (int) ($field->position ?? 0); ?>" min="0" style="width:100px">
            </div>
        </div>

        <div class="form-group">
            <label>Options</label>
            <div class="checkbox-list" style="max-height:none">
                <label class="checkbox-label"><input type="checkbox" name="required" value="1" <?php echo ($field->required ?? 0) ? 'checked' : ''; ?>> Required</label>
                <label class="checkbox-label"><input type="checkbox" name="show_in_rest" value="1" <?php echo ($field->show_in_rest ?? 1) ? 'checked' : ''; ?>> Show in REST API</label>
                <label class="checkbox-label"><input type="checkbox" name="show_in_list" value="1" <?php echo ($field->show_in_list ?? 0) ? 'checked' : ''; ?>> Show as column in admin list</label>
                <label class="checkbox-label"><input type="checkbox" name="searchable" value="1" <?php echo ($field->searchable ?? 0) ? 'checked' : ''; ?>> Include in search</label>
            </div>
        </div>

        <?php
        // Field Group assignment
        $all_groups = cr_get_all_field_groups();
        $current_group_id = (int) ($field->group_id ?? 0);
        ?>
        <div class="form-group">
            <label for="mf_group_id">Field Group</label>
            <select id="mf_group_id" name="group_id">
                <option value="0">None (use group name text)</option>
                <?php foreach ($all_groups as $g): ?>
                    <option value="<?php echo $g->id; ?>" <?php echo $current_group_id === (int) $g->id ? 'selected' : ''; ?>>
                        <?php echo esc_html($g->label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($all_groups)): ?>
                <p class="field-desc">No field groups created yet. <a href="?page=field-group-edit">Create one</a></p>
            <?php endif; ?>
        </div>

        <?php
        // Conditional Logic
        $current_conditions = $field ? (is_string($field->conditional_logic ?? '') ? json_decode($field->conditional_logic, true) : []) : [];
        $cond_rules = $current_conditions['rules'] ?? [];
        $cond_relation = $current_conditions['relation'] ?? 'and';

        // Get all fields for the same post type (for condition source)
        $sibling_fields = cr_get_meta_fields($field->post_type ?? '');
        ?>
        <div class="form-group">
            <label>Conditional Logic</label>
            <p class="field-desc">Show this field only when conditions are met. Leave empty to always show.</p>
            <div style="margin-bottom:8px">
                <label class="checkbox-label" style="display:inline-flex">
                    <span>Match</span>
                    <select name="cond_relation" style="margin:0 6px;width:auto">
                        <option value="and" <?php echo $cond_relation === 'and' ? 'selected' : ''; ?>>ALL rules (AND)</option>
                        <option value="or" <?php echo $cond_relation === 'or' ? 'selected' : ''; ?>>ANY rule (OR)</option>
                    </select>
                </label>
            </div>
            <div id="cond-rules">
                <?php
                $rule_idx = 0;
                foreach ($cond_rules as $rule):
                ?>
                <div class="cond-rule" style="display:flex;gap:8px;margin-bottom:6px;align-items:center">
                    <select name="cond_field[]" style="flex:1">
                        <option value="">-- Field --</option>
                        <?php foreach ($sibling_fields as $sf):
                            if ($field && $sf->name === $field->name) continue;
                        ?>
                            <option value="<?php echo esc_attr($sf->name); ?>" <?php echo ($rule['field'] ?? '') === $sf->name ? 'selected' : ''; ?>>
                                <?php echo esc_html($sf->label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="cond_operator[]" style="width:120px">
                        <?php foreach (['==' => 'equals', '!=' => 'not equals', '>' => 'greater', '<' => 'less', 'contains' => 'contains', 'empty' => 'is empty', 'not_empty' => 'not empty'] as $op => $lbl): ?>
                            <option value="<?php echo $op; ?>" <?php echo ($rule['operator'] ?? '==') === $op ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="cond_value[]" value="<?php echo esc_attr($rule['value'] ?? ''); ?>" placeholder="Value" style="flex:1">
                </div>
                <?php
                $rule_idx++;
                endforeach;
                ?>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="
                const container = document.getElementById('cond-rules');
                const row = container.querySelector('.cond-rule');
                if (row) { const clone = row.cloneNode(true); clone.querySelectorAll('input').forEach(i=>i.value=''); container.appendChild(clone); }
                else { container.innerHTML = '<div class=\'cond-rule\' style=\'display:flex;gap:8px;margin-bottom:6px;align-items:center\'><select name=\'cond_field[]\' style=\'flex:1\'><option>-- Field --</option></select><select name=\'cond_operator[]\' style=\'width:120px\'><option value=\'==\'>equals</option><option value=\'!=\'>not equals</option></select><input name=\'cond_value[]\' placeholder=\'Value\' style=\'flex:1\'></div>'; }
            ">+ Add Rule</button>
        </div>

        <?php
        // Repeater sub-fields (only shown when field_type = repeater)
        $current_sub_fields = [];
        if ($field && $field->field_type === 'repeater') {
            $opts = is_string($field->options) ? json_decode($field->options, true) : [];
            $current_sub_fields = $opts['sub_fields'] ?? [];
        }
        ?>
        <div class="form-group" id="repeater-config" style="<?php echo ($field->field_type ?? '') !== 'repeater' ? 'display:none' : ''; ?>">
            <label>Repeater Sub-Fields</label>
            <p class="field-desc">Define the columns that each row will contain.</p>
            <div id="sub-fields-list">
                <?php foreach ($current_sub_fields as $sf): ?>
                <div style="display:flex;gap:6px;margin-bottom:4px">
                    <input name="sub_field_name[]" value="<?php echo esc_attr($sf['name'] ?? ''); ?>" placeholder="key" style="flex:1">
                    <input name="sub_field_label[]" value="<?php echo esc_attr($sf['label'] ?? ''); ?>" placeholder="Label" style="flex:1">
                    <select name="sub_field_type[]" style="width:120px">
                        <?php foreach (cr_get_field_types() as $k => $ft): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($sf['field_type'] ?? 'text') === $k ? 'selected' : ''; ?>><?php echo $ft['label']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="
                const list = document.getElementById('sub-fields-list');
                list.innerHTML += '<div style=\'display:flex;gap:6px;margin-bottom:4px\'><input name=\'sub_field_name[]\' placeholder=\'key\' style=\'flex:1\'><input name=\'sub_field_label[]\' placeholder=\'Label\' style=\'flex:1\'><select name=\'sub_field_type[]\' style=\'width:120px\'><option value=\'text\'>Text</option><option value=\'textarea\'>Textarea</option><option value=\'number\'>Number</option><option value=\'select\'>Select</option><option value=\'checkbox\'>Checkbox</option><option value=\'date\'>Date</option><option value=\'url\'>URL</option></select></div>';
            ">+ Add Sub-Field</button>
            <div style="margin-top:8px;display:flex;gap:12px">
                <label style="font-weight:400;font-size:.9em">Min rows: <input type="number" name="repeater_min_rows" value="<?php echo (int) ($opts['min_rows'] ?? 0); ?>" min="0" style="width:60px"></label>
                <label style="font-weight:400;font-size:.9em">Max rows: <input type="number" name="repeater_max_rows" value="<?php echo (int) ($opts['max_rows'] ?? 20); ?>" min="1" style="width:60px"></label>
                <label style="font-weight:400;font-size:.9em">Button: <input type="text" name="repeater_button_label" value="<?php echo esc_attr($opts['button_label'] ?? 'Add Row'); ?>" style="width:120px"></label>
            </div>
        </div>

        <script>
        document.getElementById('mf_field_type')?.addEventListener('change', function() {
            document.getElementById('repeater-config').style.display = this.value === 'repeater' ? '' : 'none';
        });
        </script>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?php echo $field ? 'Update Field' : 'Create Field'; ?></button>
            <a href="?page=meta-fields" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
<?php
}

// =============================================
// Field Groups Management
// =============================================

function cr_admin_field_groups_list(): void {
    $groups = cr_get_all_field_groups();
    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header">
        <h1>Field Groups</h1>
        <a href="?page=field-group-edit" class="btn btn-primary">Add New</a>
    </div>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">Group saved.</div><?php endif; ?>
    <?php if ($msg === 'deleted'): ?><div class="admin-notice success">Group deleted.</div><?php endif; ?>

    <table class="admin-table">
        <thead><tr><th>Label</th><th>Slug</th><th>Location Rules</th><th>Fields</th><th>Position</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($groups as $g):
            $rules = json_decode($g->location_rules, true) ?: [];
            $rule_labels = array_map(fn($r) => ($r['operator'] ?? '==') === '==' ? $r['value'] : 'NOT ' . $r['value'], $rules);
            $field_count = (int) cr_db()->get_var(cr_db()->prepare("SELECT COUNT(*) FROM `" . cr_db()->prefix . "meta_fields` WHERE group_id = %d", $g->id));
        ?>
            <tr>
                <td><strong><a href="?page=field-group-edit&id=<?php echo $g->id; ?>"><?php echo esc_html($g->label); ?></a></strong></td>
                <td><code><?php echo esc_html($g->name); ?></code></td>
                <td><?php echo $rule_labels ? esc_html(implode(', ', $rule_labels)) : '<em>All types</em>'; ?></td>
                <td><?php echo $field_count; ?></td>
                <td><?php echo (int) $g->position; ?></td>
                <td>
                    <a href="?page=field-group-edit&id=<?php echo $g->id; ?>">Edit</a>
                    <a href="?page=field-groups&action=delete&id=<?php echo $g->id; ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="text-danger" onclick="return confirm('Delete this group? Fields will be unlinked but not deleted.')">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($groups)): ?>
            <tr><td colspan="6">No field groups yet. Groups let you organize meta fields into panels with location rules.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php
}

function cr_admin_field_group_edit(): void {
    $id = (int) ($_GET['id'] ?? 0);
    $group = $id ? cr_get_field_group($id) : null;
    $all_types = cr_get_all_post_types_for_select();
    $rules = $group ? (json_decode($group->location_rules, true) ?: []) : [];
?>
    <div class="admin-header">
        <h1><?php echo $group ? 'Edit Field Group' : 'New Field Group'; ?></h1>
    </div>

    <form method="post" action="?page=field-group-edit<?php echo $group ? '&id=' . $group->id : ''; ?>">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="_action" value="save_field_group">
        <?php if ($group): ?><input type="hidden" name="group_id" value="<?php echo $group->id; ?>"><?php endif; ?>

        <div class="form-row">
            <div class="form-group" style="flex:1">
                <label for="fg_name">Slug</label>
                <input type="text" id="fg_name" name="name" value="<?php echo esc_attr($group->name ?? ''); ?>" class="input-full" placeholder="product-details" pattern="[a-z0-9_-]+" required <?php echo $group ? 'readonly' : ''; ?>>
            </div>
            <div class="form-group" style="flex:1">
                <label for="fg_label">Label</label>
                <input type="text" id="fg_label" name="label" value="<?php echo esc_attr($group->label ?? ''); ?>" class="input-full" placeholder="Product Details" required>
            </div>
        </div>

        <div class="form-group">
            <label for="fg_desc">Description</label>
            <input type="text" id="fg_desc" name="description" value="<?php echo esc_attr($group->description ?? ''); ?>" class="input-full">
        </div>

        <div class="form-group">
            <label for="fg_position">Position</label>
            <input type="number" id="fg_position" name="position" value="<?php echo (int) ($group->position ?? 0); ?>" min="0" style="width:100px">
        </div>

        <div class="form-group">
            <label>Location Rules (show this group on these post types)</label>
            <p class="field-desc">Leave empty to show on all post types. Check to restrict.</p>
            <div class="checkbox-list" style="max-height:none">
                <?php foreach ($all_types as $slug => $label):
                    $checked = false;
                    foreach ($rules as $r) { if (($r['value'] ?? '') === $slug && ($r['operator'] ?? '==') === '==') $checked = true; }
                ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="location_post_types[]" value="<?php echo esc_attr($slug); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                        <?php echo esc_html($label); ?> <code>(<?php echo $slug; ?>)</code>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?php echo $group ? 'Update Group' : 'Create Group'; ?></button>
            <a href="?page=field-groups" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
<?php
}
