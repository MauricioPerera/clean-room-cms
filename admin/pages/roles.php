<?php
/**
 * Clean Room CMS - Role Management + User Profile Fields
 */

// Built-in roles that can be edited but not deleted
define('CR_PROTECTED_ROLES', ['administrator', 'editor', 'author', 'contributor', 'subscriber']);

// All known capabilities for the UI
function cr_get_all_capabilities(): array {
    return [
        'Content' => [
            'read' => 'Read content',
            'edit_posts' => 'Edit own posts',
            'edit_others_posts' => 'Edit others posts',
            'edit_published_posts' => 'Edit published posts',
            'publish_posts' => 'Publish posts',
            'delete_posts' => 'Delete own posts',
            'delete_others_posts' => 'Delete others posts',
            'delete_published_posts' => 'Delete published posts',
            'edit_private_posts' => 'Edit private posts',
            'read_private_posts' => 'Read private posts',
        ],
        'Pages' => [
            'edit_pages' => 'Edit pages',
            'edit_others_pages' => 'Edit others pages',
            'publish_pages' => 'Publish pages',
            'delete_pages' => 'Delete pages',
            'delete_others_pages' => 'Delete others pages',
            'edit_private_pages' => 'Edit private pages',
        ],
        'Media & Files' => [
            'upload_files' => 'Upload files',
            'unfiltered_upload' => 'Upload any file type',
        ],
        'Users' => [
            'list_users' => 'List users',
            'create_users' => 'Create users',
            'edit_users' => 'Edit users',
            'delete_users' => 'Delete users',
            'promote_users' => 'Change user roles',
        ],
        'Taxonomies' => [
            'manage_categories' => 'Manage categories and taxonomies',
        ],
        'Comments' => [
            'moderate_comments' => 'Moderate comments',
        ],
        'Appearance' => [
            'switch_themes' => 'Switch themes',
            'edit_themes' => 'Edit themes',
            'edit_theme_options' => 'Edit theme options',
            'delete_themes' => 'Delete themes',
            'install_themes' => 'Install themes',
            'update_themes' => 'Update themes',
        ],
        'Plugins' => [
            'activate_plugins' => 'Activate plugins',
            'edit_plugins' => 'Edit plugins',
            'install_plugins' => 'Install plugins',
            'update_plugins' => 'Update plugins',
            'delete_plugins' => 'Delete plugins',
        ],
        'System' => [
            'manage_options' => 'Manage site settings',
            'edit_dashboard' => 'Edit dashboard',
            'import' => 'Import content',
            'export' => 'Export content',
            'update_core' => 'Update system',
            'unfiltered_html' => 'Write unfiltered HTML',
        ],
    ];
}

// =============================================
// Role CRUD (DB-backed)
// =============================================

function cr_install_roles_table(): void {
    $db = cr_db();
    $table = $db->prefix . 'roles';
    if (!$db->get_var("SHOW TABLES LIKE '{$table}'")) {
        $schema = file_get_contents(CR_BASE_PATH . '/install/schema.sql');
        $schema = str_replace('{prefix}', $db->prefix, $schema);
        $schema = preg_replace('/^--.*$/m', '', $schema);
        foreach (array_filter(array_map('trim', explode(';', $schema)), fn($s) => strlen($s) > 5 && stripos($s, $table) !== false) as $sql) {
            $db->query($sql);
        }
    }
}

function cr_save_role_to_db(array $data): int|false {
    $db = cr_db();
    $table = $db->prefix . 'roles';

    $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($data['slug'] ?? '')));
    if (empty($slug)) return false;

    $caps = $data['capabilities'] ?? [];
    if (is_string($caps)) $caps = json_decode($caps, true) ?: [];

    $row = [
        'slug'         => $slug,
        'name'         => trim($data['name'] ?? ucfirst($slug)),
        'capabilities' => json_encode($caps),
        'description'  => trim($data['description'] ?? ''),
        'is_default'   => (int) ($data['is_default'] ?? 0),
    ];

    $existing = $db->get_var($db->prepare("SELECT id FROM `{$table}` WHERE slug = %s", $slug));

    if ($existing) {
        $db->update($table, $row, ['id' => (int) $existing]);
        return (int) $existing;
    }

    $row['created_at'] = gmdate('Y-m-d H:i:s');
    return $db->insert($table, $row);
}

function cr_delete_role_from_db(string $slug): bool {
    if (in_array($slug, CR_PROTECTED_ROLES)) return false;
    $db = cr_db();
    return $db->delete($db->prefix . 'roles', ['slug' => $slug]) > 0;
}

function cr_get_db_roles(): array {
    $db = cr_db();
    $table = $db->prefix . 'roles';
    if (!$db->get_var("SHOW TABLES LIKE '{$table}'")) return [];
    return $db->get_results("SELECT * FROM `{$table}` ORDER BY name ASC");
}

function cr_get_db_role(string $slug): ?object {
    $db = cr_db();
    return $db->get_row($db->prepare("SELECT * FROM `{$db->prefix}roles` WHERE slug = %s", $slug));
}

/**
 * Load DB roles into the global $cr_roles registry.
 */
function cr_load_db_roles(): void {
    global $cr_roles;
    $db_roles = cr_get_db_roles();
    foreach ($db_roles as $r) {
        $caps = json_decode($r->capabilities, true) ?: [];
        $cr_roles[$r->slug] = [
            'name' => $r->name,
            'capabilities' => $caps,
        ];
    }
}

// =============================================
// Roles Admin UI
// =============================================

function cr_admin_roles_list(): void {
    global $cr_roles;
    $db_roles = cr_get_db_roles();
    $db_slugs = array_map(fn($r) => $r->slug, $db_roles);
    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header">
        <h1>Roles</h1>
        <a href="?page=role-edit" class="btn btn-primary">Add New</a>
    </div>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">Role saved.</div><?php endif; ?>
    <?php if ($msg === 'deleted'): ?><div class="admin-notice success">Role deleted.</div><?php endif; ?>

    <table class="admin-table">
        <thead><tr><th>Role</th><th>Slug</th><th>Capabilities</th><th>Source</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($cr_roles as $slug => $role):
            $cap_count = count(array_filter($role['capabilities']));
            $is_builtin = in_array($slug, CR_PROTECTED_ROLES);
            $in_db = in_array($slug, $db_slugs);
        ?>
            <tr>
                <td><strong><a href="?page=role-edit&slug=<?php echo esc_attr($slug); ?>"><?php echo esc_html($role['name']); ?></a></strong></td>
                <td><code><?php echo esc_html($slug); ?></code></td>
                <td><?php echo $cap_count; ?> capabilities</td>
                <td>
                    <?php if ($is_builtin && !$in_db): ?>
                        <span class="status-badge status-publish">Built-in</span>
                    <?php elseif ($is_builtin && $in_db): ?>
                        <span class="status-badge status-publish">Built-in</span> <span class="status-badge status-draft">Customized</span>
                    <?php else: ?>
                        <span class="status-badge status-draft">Custom</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="?page=role-edit&slug=<?php echo esc_attr($slug); ?>">Edit</a>
                    <?php if (!$is_builtin): ?>
                        <a href="?page=roles&action=delete&slug=<?php echo esc_attr($slug); ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="text-danger" onclick="return confirm('Delete this role? Users with this role will need a new role assigned.')">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php
}

function cr_admin_role_edit(): void {
    $slug = $_GET['slug'] ?? '';
    global $cr_roles;
    $role = isset($cr_roles[$slug]) ? $cr_roles[$slug] : null;
    $db_role = $slug ? cr_get_db_role($slug) : null;
    $is_new = empty($slug);
    $is_builtin = in_array($slug, CR_PROTECTED_ROLES);
    $current_caps = $role ? $role['capabilities'] : [];
    $all_caps = cr_get_all_capabilities();
?>
    <div class="admin-header">
        <h1><?php echo $is_new ? 'New Role' : 'Edit Role: ' . esc_html($role['name'] ?? $slug); ?></h1>
    </div>

    <form method="post" action="?page=role-edit<?php echo $slug ? '&slug=' . esc_attr($slug) : ''; ?>">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="_action" value="save_role">

        <div class="form-row">
            <div class="form-group" style="flex:1">
                <label for="role_slug">Slug</label>
                <input type="text" id="role_slug" name="slug" value="<?php echo esc_attr($slug); ?>" class="input-full" placeholder="moderator" pattern="[a-z0-9_-]+" required <?php echo !$is_new ? 'readonly' : ''; ?>>
            </div>
            <div class="form-group" style="flex:1">
                <label for="role_name">Display Name</label>
                <input type="text" id="role_name" name="name" value="<?php echo esc_attr($role['name'] ?? ''); ?>" class="input-full" placeholder="Moderator" required>
            </div>
        </div>

        <div class="form-group">
            <label for="role_desc">Description</label>
            <input type="text" id="role_desc" name="description" value="<?php echo esc_attr($db_role->description ?? ''); ?>" class="input-full" placeholder="What this role is for">
        </div>

        <div class="form-group">
            <label>Capabilities</label>
            <?php foreach ($all_caps as $group_name => $caps): ?>
            <div class="meta-group" style="margin-bottom:12px">
                <h4 style="font-size:.85em;color:var(--color-text-light);margin-bottom:8px"><?php echo esc_html($group_name); ?></h4>
                <div class="checkbox-list" style="max-height:none">
                    <?php foreach ($caps as $cap => $label): ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="capabilities[]" value="<?php echo esc_attr($cap); ?>" <?php echo !empty($current_caps[$cap]) ? 'checked' : ''; ?>>
                            <?php echo esc_html($label); ?> <code style="font-size:.75em">(<?php echo esc_html($cap); ?>)</code>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?php echo $is_new ? 'Create Role' : 'Update Role'; ?></button>
            <a href="?page=roles" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
<?php
}

function cr_admin_save_role(): void {
    $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['slug'] ?? '')));
    $name = trim($_POST['name'] ?? '');
    $caps_list = $_POST['capabilities'] ?? [];
    $description = trim($_POST['description'] ?? '');

    if (empty($slug) || empty($name)) {
        header('Location: ' . CR_SITE_URL . '/admin/?page=roles&msg=error');
        exit;
    }

    // Build capabilities map
    $caps = [];
    foreach ($caps_list as $cap) {
        $caps[$cap] = true;
    }

    // Save to DB
    cr_save_role_to_db([
        'slug' => $slug,
        'name' => $name,
        'capabilities' => $caps,
        'description' => $description,
    ]);

    // Update in-memory
    global $cr_roles;
    $cr_roles[$slug] = ['name' => $name, 'capabilities' => $caps];

    header('Location: ' . CR_SITE_URL . '/admin/?page=roles&msg=saved');
    exit;
}

// =============================================
// User Profile Fields (meta fields scoped to users/roles)
// =============================================

/**
 * Get profile fields for a specific role (or all roles).
 * Uses the existing meta_fields table with object_type = 'user'.
 * post_type field is repurposed as "role" filter.
 */
function cr_get_profile_fields(string $role = ''): array {
    return cr_get_meta_fields($role, 'user');
}

/**
 * Render profile fields in user edit form.
 */
function cr_render_profile_fields(string $role, int $user_id = 0): string {
    $fields = cr_get_profile_fields($role);
    if (empty($fields)) return '';

    $groups = [];
    foreach ($fields as $f) {
        $g = $f->group_name ?: 'Profile';
        $groups[$g][] = $f;
    }

    $html = '';
    foreach ($groups as $group_name => $group_fields) {
        $html .= '<div class="meta-group">';
        $html .= '<h3 class="meta-group-title">' . esc_html($group_name) . '</h3>';
        foreach ($group_fields as $field) {
            $value = $user_id ? get_user_meta($user_id, $field->name, true) : null;
            $html .= cr_render_meta_field((array) $field, $value);
        }
        $html .= '</div>';
    }

    return $html;
}

/**
 * Save profile fields from $_POST for a user.
 */
function cr_save_profile_fields(int $user_id, string $role): void {
    $fields = cr_get_profile_fields($role);
    foreach ($fields as $field) {
        $key = 'meta_' . $field->name;
        if ($field->field_type === 'checkbox') {
            $value = isset($_POST[$key]) ? '1' : '0';
        } else {
            $value = $_POST[$key] ?? '';
        }
        $error = cr_validate_meta_field((array) $field, $value);
        if ($error) continue;
        update_user_meta($user_id, $field->name, $value);
    }
}
