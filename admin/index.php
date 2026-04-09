<?php
/**
 * Clean Room CMS - Admin Panel
 */

// Load admin page modules
require_once __DIR__ . '/content-types.php';
require_once __DIR__ . '/pages/users.php';
require_once __DIR__ . '/pages/plugins.php';
require_once __DIR__ . '/pages/ai-settings.php';
require_once __DIR__ . '/pages/queue.php';
require_once __DIR__ . '/pages/settings.php';

// Auth check
if (!is_user_logged_in()) {
    $action = $_GET['action'] ?? '';

    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Brute force protection
        if (CR_Security::is_login_locked()) {
            $login_error = 'Too many failed attempts. Please wait 30 minutes.';
        } else {
            $user_id = cr_authenticate($_POST['log'] ?? '', $_POST['pwd'] ?? '');
            if ($user_id) {
                CR_Security::clear_failed_logins();
                cr_set_auth_cookie($user_id);
                header('Location: ' . CR_SITE_URL . '/admin/');
                exit;
            }
            CR_Security::record_failed_login($_POST['log'] ?? '');
            $login_error = 'Invalid username or password.';
        }
    }

    // Show login form
    cr_admin_login_page($login_error ?? '');
    exit;
}

if (!current_user_can('read')) {
    die('Access denied.');
}

// Handle logout
if (($_GET['action'] ?? '') === 'logout') {
    cr_clear_auth_cookie();
    header('Location: ' . CR_SITE_URL . '/admin/?action=login');
    exit;
}

// Route admin pages
$page = $_GET['page'] ?? 'dashboard';
$admin_action = $_GET['action'] ?? '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cr_verify_nonce($_POST['_cr_nonce'] ?? '', 'admin_action')) {
        die('Security check failed.');
    }

    switch ($page) {
        case 'post-edit':
            cr_admin_save_post();
            break;
        case 'settings':
            cr_admin_save_settings_full();
            break;
        case 'user-edit':
            if (($_POST['_action'] ?? '') === 'save_user') { cr_admin_save_user(); }
            break;
        case 'ai-settings':
            if (($_POST['_action'] ?? '') === 'save_ai_settings') { cr_admin_save_ai_settings(); }
            break;
        case 'guidelines':
            if (($_POST['_action'] ?? '') === 'save_guidelines') { cr_admin_save_guidelines(); }
            break;
        case 'vector-settings':
            if (($_POST['_action'] ?? '') === 'save_vector_settings') { cr_admin_save_vector_settings(); }
            break;
        case 'security':
            if (($_POST['_action'] ?? '') === 'save_security') { cr_admin_save_security(); }
            break;
        case 'media':
            if (($_POST['_action'] ?? '') === 'upload_media') { cr_admin_upload_media(); }
            break;
        case 'term-edit':
            cr_admin_save_term();
            break;
        case 'field-group-edit':
            if (($_POST['_action'] ?? '') === 'save_field_group') {
                $loc_types = $_POST['location_post_types'] ?? [];
                $loc_rules = array_map(fn($t) => ['param' => 'post_type', 'operator' => '==', 'value' => $t], $loc_types);

                cr_save_field_group([
                    'id'             => (int) ($_POST['group_id'] ?? 0),
                    'name'           => $_POST['name'] ?? '',
                    'label'          => $_POST['label'] ?? '',
                    'description'    => $_POST['description'] ?? '',
                    'position'       => (int) ($_POST['position'] ?? 0),
                    'location_rules' => $loc_rules,
                ]);
                header('Location: ' . CR_SITE_URL . '/admin/?page=field-groups&msg=saved');
                exit;
            }
            break;
        case 'content-type-edit':
            if (($_POST['_action'] ?? '') === 'save_content_type') {
                cr_save_content_type([
                    'name'                => $_POST['name'] ?? '',
                    'label'               => $_POST['label'] ?? '',
                    'label_singular'      => $_POST['label_singular'] ?? '',
                    'description'         => $_POST['description'] ?? '',
                    'icon'                => $_POST['icon'] ?? '',
                    'public'              => isset($_POST['public']) ? 1 : 0,
                    'hierarchical'        => isset($_POST['hierarchical']) ? 1 : 0,
                    'show_in_rest'        => isset($_POST['show_in_rest']) ? 1 : 0,
                    'rest_base'           => $_POST['rest_base'] ?? '',
                    'has_archive'         => isset($_POST['has_archive']) ? 1 : 0,
                    'supports'            => $_POST['supports'] ?? ['title', 'editor'],
                    'exclude_from_search' => isset($_POST['exclude_from_search']) ? 1 : 0,
                    'menu_position'       => (int) ($_POST['menu_position'] ?? 25),
                ]);
                header('Location: ' . CR_SITE_URL . '/admin/?page=content-types&msg=saved');
                exit;
            }
            break;
        case 'content-taxonomy-edit':
            if (($_POST['_action'] ?? '') === 'save_content_taxonomy') {
                cr_save_content_taxonomy([
                    'name'           => $_POST['name'] ?? '',
                    'label'          => $_POST['label'] ?? '',
                    'label_singular' => $_POST['label_singular'] ?? '',
                    'hierarchical'   => isset($_POST['hierarchical']) ? 1 : 0,
                    'public'         => isset($_POST['public']) ? 1 : 0,
                    'show_in_rest'   => isset($_POST['show_in_rest']) ? 1 : 0,
                    'post_types'     => $_POST['post_types'] ?? [],
                ]);
                header('Location: ' . CR_SITE_URL . '/admin/?page=content-taxonomies&msg=saved');
                exit;
            }
            break;
        case 'meta-field-edit':
            if (($_POST['_action'] ?? '') === 'save_meta_field') {
                // Parse options from text
                $options = [];
                $lines = array_filter(array_map('trim', explode("\n", $_POST['options_text'] ?? '')));
                foreach ($lines as $line) {
                    if (str_contains($line, ':')) {
                        [$val, $lbl] = explode(':', $line, 2);
                        $options[] = ['value' => trim($val), 'label' => trim($lbl)];
                    } else {
                        $options[] = ['value' => $line, 'label' => $line];
                    }
                }

                // Build conditional logic
                $cond_fields = $_POST['cond_field'] ?? [];
                $cond_ops = $_POST['cond_operator'] ?? [];
                $cond_vals = $_POST['cond_value'] ?? [];
                $cond_rules = [];
                foreach ($cond_fields as $ci => $cf) {
                    if (!empty($cf)) {
                        $cond_rules[] = ['field' => $cf, 'operator' => $cond_ops[$ci] ?? '==', 'value' => $cond_vals[$ci] ?? ''];
                    }
                }
                $conditional_logic = !empty($cond_rules)
                    ? ['relation' => $_POST['cond_relation'] ?? 'and', 'rules' => $cond_rules]
                    : [];

                // Build repeater options if field_type is repeater
                $field_type = $_POST['field_type'] ?? 'text';
                if ($field_type === 'repeater') {
                    $sf_names = $_POST['sub_field_name'] ?? [];
                    $sf_labels = $_POST['sub_field_label'] ?? [];
                    $sf_types = $_POST['sub_field_type'] ?? [];
                    $allowed_field_types = array_keys(cr_get_field_types());
                    $sub_fields = [];
                    foreach ($sf_names as $si => $sn) {
                        if (!empty($sn)) {
                            $sf_type = $sf_types[$si] ?? 'text';
                            $sub_fields[] = [
                                'name' => preg_replace('/[^a-z0-9_]/', '', strtolower($sn)),
                                'label' => sanitize_text_field($sf_labels[$si] ?? $sn),
                                'field_type' => in_array($sf_type, $allowed_field_types) ? $sf_type : 'text',
                            ];
                        }
                    }
                    $options = [
                        'sub_fields' => $sub_fields,
                        'min_rows' => max(0, (int) ($_POST['repeater_min_rows'] ?? 0)),
                        'max_rows' => min(1000, max(1, (int) ($_POST['repeater_max_rows'] ?? 20))),
                        'button_label' => sanitize_text_field($_POST['repeater_button_label'] ?? 'Add Row'),
                    ];
                }

                $field_id = cr_save_meta_field([
                    'id'            => (int) ($_POST['field_id'] ?? 0),
                    'name'          => $_POST['name'] ?? '',
                    'label'         => $_POST['label'] ?? '',
                    'description'   => $_POST['description'] ?? '',
                    'post_type'     => $_POST['post_type'] ?? '',
                    'field_type'    => $field_type,
                    'options'       => $options,
                    'default_value' => $_POST['default_value'] ?? '',
                    'placeholder'   => $_POST['placeholder'] ?? '',
                    'required'      => isset($_POST['required']) ? 1 : 0,
                    'group_name'    => $_POST['group_name'] ?? 'Custom Fields',
                    'position'      => (int) ($_POST['position'] ?? 0),
                    'show_in_rest'  => isset($_POST['show_in_rest']) ? 1 : 0,
                    'show_in_list'  => isset($_POST['show_in_list']) ? 1 : 0,
                    'searchable'    => isset($_POST['searchable']) ? 1 : 0,
                ]);

                // Save group_id and conditional_logic (columns added via ALTER)
                if ($field_id) {
                    $db = cr_db();
                    $update_data = ['conditional_logic' => json_encode($conditional_logic)];
                    if (isset($_POST['group_id'])) {
                        $update_data['group_id'] = (int) $_POST['group_id'];
                    }
                    $db->update($db->prefix . 'meta_fields', $update_data, ['id' => $field_id]);
                }
                header('Location: ' . CR_SITE_URL . '/admin/?page=meta-fields&msg=saved');
                exit;
            }
            break;
    }
}

// CSRF nonce check for all GET-based actions
$_action_nonce_valid = cr_verify_nonce($_GET['_nonce'] ?? '', 'admin_action');

// Handle user delete
if ($admin_action === 'delete' && $page === 'users' && $_action_nonce_valid) {
    $del_id = (int) ($_GET['id'] ?? 0);
    if ($del_id && $del_id !== get_current_user_id() && current_user_can('delete_users')) {
        $db = cr_db();
        $db->delete($db->prefix . 'users', ['ID' => $del_id]);
        $db->query($db->prepare("DELETE FROM `{$db->prefix}usermeta` WHERE user_id = %d", $del_id));
        header('Location: ' . CR_SITE_URL . '/admin/?page=users&msg=deleted');
        exit;
    }
}

// Handle plugin activate/deactivate
if ($page === 'plugins' && ($admin_action === 'activate' || $admin_action === 'deactivate') && $_action_nonce_valid) {
    $plugin = $_GET['plugin'] ?? '';
    if ($plugin && current_user_can('activate_plugins')) {
        if ($admin_action === 'activate') cr_admin_activate_plugin($plugin);
        else cr_admin_deactivate_plugin($plugin);
    }
}

// Handle theme switch
if ($page === 'themes' && $admin_action === 'switch') {
    $theme = $_GET['theme'] ?? '';
    if ($theme && current_user_can('switch_themes')) { cr_admin_switch_theme($theme); }
}

// Handle comment actions
if ($page === 'comments' && in_array($admin_action, ['approve', 'spam', 'delete'])) {
    $cid = (int) ($_GET['id'] ?? 0);
    if ($cid && current_user_can('moderate_comments')) {
        $db = cr_db();
        if ($admin_action === 'approve') $db->update($db->prefix . 'comments', ['comment_approved' => '1'], ['comment_ID' => $cid]);
        elseif ($admin_action === 'spam') $db->update($db->prefix . 'comments', ['comment_approved' => 'spam'], ['comment_ID' => $cid]);
        elseif ($admin_action === 'delete') $db->delete($db->prefix . 'comments', ['comment_ID' => $cid]);
        header('Location: ' . CR_SITE_URL . '/admin/?page=comments&msg=updated');
        exit;
    }
}

// Handle media delete
if ($page === 'media' && $admin_action === 'delete') {
    $mid = (int) ($_GET['id'] ?? 0);
    if ($mid && current_user_can('upload_files')) {
        cr_delete_post($mid, true);
        header('Location: ' . CR_SITE_URL . '/admin/?page=media&msg=deleted');
        exit;
    }
}

// Handle queue actions
if ($page === 'queue' && $admin_action === 'retry') {
    $jid = (int) ($_GET['id'] ?? 0);
    if ($jid) { CR_Queue::retry_job($jid); header('Location: ' . CR_SITE_URL . '/admin/?page=queue&msg=retried'); exit; }
}
if ($page === 'queue' && $admin_action === 'cleanup') {
    CR_Queue::cleanup(7); header('Location: ' . CR_SITE_URL . '/admin/?page=queue&msg=cleaned'); exit;
}

// Handle vector reindex
if ($page === 'vector-settings' && $admin_action === 'reindex') {
    cr_vectors()->reindex_all('post');
    header('Location: ' . CR_SITE_URL . '/admin/?page=vector-settings&msg=reindexed');
    exit;
}

// Handle field group delete
if ($admin_action === 'delete' && $page === 'field-groups') {
    $del_id = (int) ($_GET['id'] ?? 0);
    if ($del_id && current_user_can('manage_options')) {
        cr_delete_field_group($del_id);
        header('Location: ' . CR_SITE_URL . '/admin/?page=field-groups&msg=deleted');
        exit;
    }
}

// Handle content builder deletes
if ($admin_action === 'delete' && $page === 'content-types') {
    $del_name = $_GET['name'] ?? '';
    if ($del_name && current_user_can('manage_options')) {
        cr_delete_content_type($del_name);
        header('Location: ' . CR_SITE_URL . '/admin/?page=content-types&msg=deleted');
        exit;
    }
}
if ($admin_action === 'delete' && $page === 'content-taxonomies') {
    $del_name = $_GET['name'] ?? '';
    if ($del_name && current_user_can('manage_options')) {
        cr_delete_content_taxonomy($del_name);
        header('Location: ' . CR_SITE_URL . '/admin/?page=content-taxonomies&msg=deleted');
        exit;
    }
}
if ($admin_action === 'delete' && $page === 'meta-fields') {
    $del_id = (int) ($_GET['id'] ?? 0);
    if ($del_id && current_user_can('manage_options')) {
        cr_delete_meta_field($del_id);
        header('Location: ' . CR_SITE_URL . '/admin/?page=meta-fields&msg=deleted');
        exit;
    }
}

// Handle term delete
if ($admin_action === 'delete' && ($page === 'categories' || $page === 'tags')) {
    $term_id = (int) ($_GET['id'] ?? 0);
    $taxonomy = $page === 'categories' ? 'category' : 'post_tag';
    if ($term_id && current_user_can('manage_categories')) {
        cr_delete_term($term_id, $taxonomy);
        header('Location: ' . CR_SITE_URL . "/admin/?page={$page}&msg=deleted");
        exit;
    }
}

// Handle delete
if ($admin_action === 'delete' && $page === 'posts') {
    $post_id = (int) ($_GET['id'] ?? 0);
    if ($post_id && current_user_can('delete_posts')) {
        cr_delete_post($post_id, true);
        header('Location: ' . CR_SITE_URL . '/admin/?page=posts&msg=deleted');
        exit;
    }
}

cr_admin_page($page);

// -- Admin functions --

function cr_admin_page(string $page): void {
    cr_admin_header($page);

    $matched = match ($page) {
        'posts'                  => fn() => cr_admin_posts_list('post'),
        'pages'                  => fn() => cr_admin_posts_list('page'),
        'post-edit'              => fn() => cr_admin_post_edit(),
        'categories'             => fn() => cr_admin_taxonomy_list('category'),
        'tags'                   => fn() => cr_admin_taxonomy_list('post_tag'),
        'term-edit'              => fn() => cr_admin_term_edit(),
        'users'                  => fn() => cr_admin_users_list(),
        'user-edit'              => fn() => cr_admin_user_edit(),
        'plugins'                => fn() => cr_admin_plugins_list(),
        'themes'                 => fn() => cr_admin_themes_list(),
        'ai-settings'            => fn() => cr_admin_ai_settings(),
        'guidelines'             => fn() => cr_admin_guidelines(),
        'vector-settings'        => fn() => cr_admin_vector_settings(),
        'queue'                  => fn() => cr_admin_queue_monitor(),
        'security'               => fn() => cr_admin_security_settings(),
        'comments'               => fn() => cr_admin_comments(),
        'media'                  => fn() => cr_admin_media(),
        'field-groups'           => fn() => cr_admin_field_groups_list(),
        'field-group-edit'       => fn() => cr_admin_field_group_edit(),
        'content-types'          => fn() => cr_admin_content_types_list(),
        'content-type-edit'      => fn() => cr_admin_content_type_edit(),
        'content-taxonomies'     => fn() => cr_admin_content_taxonomies_list(),
        'content-taxonomy-edit'  => fn() => cr_admin_content_taxonomy_edit(),
        'meta-fields'            => fn() => cr_admin_meta_fields_list(),
        'meta-field-edit'        => fn() => cr_admin_meta_field_edit(),
        'settings'               => fn() => cr_admin_settings_full(),
        'dashboard'              => fn() => cr_admin_dashboard(),
        default                  => null,
    };

    if ($matched) {
        $matched();
    } elseif (str_starts_with($page, 'type-')) {
        // Dynamic custom type list page: ?page=type-product → list products
        $cpt_name = substr($page, 5);
        if (post_type_exists($cpt_name)) {
            cr_admin_posts_list($cpt_name);
        } else {
            cr_admin_dashboard();
        }
    } else {
        cr_admin_dashboard();
    }

    cr_admin_footer();
}

function cr_admin_header(string $current_page): void {
    $user = cr_get_current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?php bloginfo('name'); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url(CR_SITE_URL); ?>/admin/assets/css/admin.css">
    <script src="<?php echo esc_url(CR_SITE_URL); ?>/admin/assets/js/admin.js" defer></script>
</head>
<body class="admin">
<div class="admin-layout">
    <aside class="admin-sidebar">
        <div class="admin-logo">
            <a href="<?php echo esc_url(CR_SITE_URL); ?>/admin/">CR</a>
        </div>
        <nav class="admin-nav">
            <a href="?page=dashboard" class="<?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
            <div class="nav-separator"></div>
            <a href="?page=posts" class="<?php echo $current_page === 'posts' ? 'active' : ''; ?>">Posts</a>
            <a href="?page=pages" class="<?php echo $current_page === 'pages' ? 'active' : ''; ?>">Pages</a>
            <a href="?page=media" class="<?php echo $current_page === 'media' ? 'active' : ''; ?>">Media</a>
            <?php
            $custom_types = cr_get_content_types();
            foreach ($custom_types as $ct):
                if ($ct->status !== 'active') continue;
                $ct_page = 'type-' . $ct->name;
                $icon = $ct->icon ?: '📄';
            ?>
            <a href="?page=<?php echo esc_attr($ct_page); ?>" class="<?php echo $current_page === $ct_page ? 'active' : ''; ?>"><?php echo esc_html($icon . ' ' . $ct->label); ?></a>
            <?php endforeach; ?>
            <div class="nav-separator"></div>
            <a href="?page=categories" class="<?php echo $current_page === 'categories' ? 'active' : ''; ?>">Categories</a>
            <a href="?page=tags" class="<?php echo $current_page === 'tags' ? 'active' : ''; ?>">Tags</a>
            <a href="?page=comments" class="<?php echo $current_page === 'comments' ? 'active' : ''; ?>">Comments</a>
            <div class="nav-separator"></div>
            <a href="?page=content-types" class="<?php echo str_starts_with($current_page, 'content-type') ? 'active' : ''; ?>">Content Types</a>
            <a href="?page=content-taxonomies" class="<?php echo str_starts_with($current_page, 'content-taxonom') ? 'active' : ''; ?>">Taxonomies</a>
            <a href="?page=field-groups" class="<?php echo str_starts_with($current_page, 'field-group') ? 'active' : ''; ?>">Field Groups</a>
            <a href="?page=meta-fields" class="<?php echo str_starts_with($current_page, 'meta-field') ? 'active' : ''; ?>">Meta Fields</a>
            <div class="nav-separator"></div>
            <a href="?page=users" class="<?php echo $current_page === 'users' || $current_page === 'user-edit' ? 'active' : ''; ?>">Users</a>
            <a href="?page=plugins" class="<?php echo $current_page === 'plugins' ? 'active' : ''; ?>">Plugins</a>
            <a href="?page=themes" class="<?php echo $current_page === 'themes' ? 'active' : ''; ?>">Themes</a>
            <div class="nav-separator"></div>
            <a href="?page=ai-settings" class="<?php echo $current_page === 'ai-settings' ? 'active' : ''; ?>">AI Settings</a>
            <a href="?page=guidelines" class="<?php echo $current_page === 'guidelines' ? 'active' : ''; ?>">Guidelines</a>
            <a href="?page=vector-settings" class="<?php echo $current_page === 'vector-settings' ? 'active' : ''; ?>">Vector Search</a>
            <div class="nav-separator"></div>
            <a href="?page=queue" class="<?php echo $current_page === 'queue' ? 'active' : ''; ?>">Queue</a>
            <a href="?page=security" class="<?php echo $current_page === 'security' ? 'active' : ''; ?>">Security</a>
            <a href="?page=settings" class="<?php echo $current_page === 'settings' ? 'active' : ''; ?>">Settings</a>
        </nav>
        <div class="admin-user">
            <span><?php echo esc_html($user->display_name); ?></span>
            <a href="?action=logout">Logout</a>
        </div>
    </aside>
    <main class="admin-main">
<?php
}

function cr_admin_footer(): void {
?>
    </main>
</div>
</body>
</html>
<?php
}

function cr_admin_dashboard(): void {
    $db = cr_db();
    $post_count = (int) $db->get_var("SELECT COUNT(*) FROM `{$db->prefix}posts` WHERE post_type='post' AND post_status='publish'");
    $page_count = (int) $db->get_var("SELECT COUNT(*) FROM `{$db->prefix}posts` WHERE post_type='page' AND post_status='publish'");
    $comment_count = (int) $db->get_var("SELECT COUNT(*) FROM `{$db->prefix}comments`");
    $user_count = (int) $db->get_var("SELECT COUNT(*) FROM `{$db->prefix}users`");
?>
    <div class="admin-header">
        <h1>Dashboard</h1>
    </div>
    <div class="dashboard-cards">
        <div class="card">
            <div class="card-number"><?php echo $post_count; ?></div>
            <div class="card-label">Posts</div>
        </div>
        <div class="card">
            <div class="card-number"><?php echo $page_count; ?></div>
            <div class="card-label">Pages</div>
        </div>
        <div class="card">
            <div class="card-number"><?php echo $comment_count; ?></div>
            <div class="card-label">Comments</div>
        </div>
        <div class="card">
            <div class="card-number"><?php echo $user_count; ?></div>
            <div class="card-label">Users</div>
        </div>
    </div>

    <div class="admin-section">
        <h2>Recent Posts</h2>
        <?php
        $recent = $db->get_results("SELECT ID, post_title, post_date, post_status FROM `{$db->prefix}posts` WHERE post_type='post' ORDER BY post_date DESC LIMIT 5");
        if ($recent): ?>
        <table class="admin-table">
            <thead><tr><th>Title</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $p): ?>
                <tr>
                    <td><a href="?page=post-edit&id=<?php echo $p->ID; ?>"><?php echo esc_html($p->post_title); ?></a></td>
                    <td><?php echo date('M j, Y', strtotime($p->post_date)); ?></td>
                    <td><span class="status-badge status-<?php echo esc_attr($p->post_status); ?>"><?php echo esc_html($p->post_status); ?></span></td>
                    <td><a href="?page=post-edit&id=<?php echo $p->ID; ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p>No posts yet. <a href="?page=post-edit&type=post">Create your first post</a></p>
        <?php endif; ?>
    </div>
<?php
}

function cr_admin_posts_list(string $post_type): void {
    $db = cr_db();
    $label = $post_type === 'page' ? 'Pages' : 'Posts';

    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $per_page = 20;
    $offset = ($paged - 1) * $per_page;

    $total = (int) $db->get_var($db->prepare(
        "SELECT COUNT(*) FROM `{$db->prefix}posts` WHERE post_type = %s AND post_status != 'auto-draft'", $post_type
    ));
    $posts = $db->get_results($db->prepare(
        "SELECT ID, post_title, post_date, post_status, post_author FROM `{$db->prefix}posts` WHERE post_type = %s AND post_status != 'auto-draft' ORDER BY post_date DESC LIMIT %d OFFSET %d",
        $post_type, $per_page, $offset
    ));

    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header">
        <h1><?php echo $label; ?></h1>
        <a href="?page=post-edit&type=<?php echo esc_attr($post_type); ?>" class="btn btn-primary">Add New</a>
    </div>

    <?php if ($msg === 'deleted'): ?>
        <div class="admin-notice success">Post deleted.</div>
    <?php elseif ($msg === 'saved'): ?>
        <div class="admin-notice success">Post saved.</div>
    <?php endif; ?>

    <table class="admin-table">
        <thead>
            <tr><th>Title</th><th>Date</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($posts as $p): ?>
            <tr>
                <td><strong><a href="?page=post-edit&id=<?php echo $p->ID; ?>"><?php echo esc_html($p->post_title ?: '(no title)'); ?></a></strong></td>
                <td><?php echo date('Y-m-d', strtotime($p->post_date)); ?></td>
                <td><span class="status-badge status-<?php echo esc_attr($p->post_status); ?>"><?php echo esc_html($p->post_status); ?></span></td>
                <td>
                    <a href="?page=post-edit&id=<?php echo $p->ID; ?>">Edit</a>
                    <a href="<?php echo esc_url(get_permalink($p->ID)); ?>" target="_blank">View</a>
                    <a href="?page=posts&action=delete&id=<?php echo $p->ID; ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="text-danger" onclick="return confirm('Delete this post?')">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($posts)): ?>
            <tr><td colspan="4">No <?php echo strtolower($label); ?> found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php
    $max_pages = ceil($total / $per_page);
    if ($max_pages > 1): ?>
        <div class="pagination" style="margin-top:16px;">
            <?php for ($i = 1; $i <= $max_pages; $i++): ?>
                <?php if ($i === $paged): ?>
                    <strong><?php echo $i; ?></strong>
                <?php else: ?>
                    <a href="?page=<?php echo $post_type === 'page' ? 'pages' : 'posts'; ?>&paged=<?php echo $i; ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php
}

function cr_admin_post_edit(): void {
    $post_id = (int) ($_GET['id'] ?? 0);
    $post_type = $_GET['type'] ?? 'post';

    $post = null;
    if ($post_id) {
        $post = get_post($post_id);
        if ($post) $post_type = $post->post_type;
    }

    $label = $post_type === 'page' ? 'Page' : 'Post';
?>
    <div class="admin-header">
        <h1><?php echo $post ? "Edit {$label}" : "New {$label}"; ?></h1>
    </div>

    <form method="post" action="?page=post-edit<?php echo $post ? '&id=' . $post->ID : ''; ?>">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type); ?>">
        <?php if ($post): ?>
            <input type="hidden" name="post_id" value="<?php echo $post->ID; ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="post_title">Title</label>
            <input type="text" id="post_title" name="post_title" value="<?php echo esc_attr($post->post_title ?? ''); ?>" class="input-full" placeholder="Enter title here" autofocus>
        </div>

        <div class="form-group">
            <label for="post_name">Slug</label>
            <input type="text" id="post_name" name="post_name" value="<?php echo esc_attr($post->post_name ?? ''); ?>" class="input-full" placeholder="auto-generated-from-title">
        </div>

        <div class="form-group">
            <label for="post_content">Content</label>
            <textarea id="post_content" name="post_content" rows="18" class="input-full"><?php echo esc_html($post->post_content ?? ''); ?></textarea>
        </div>

        <div class="form-group">
            <label for="post_excerpt">Excerpt</label>
            <textarea id="post_excerpt" name="post_excerpt" rows="3" class="input-full"><?php echo esc_html($post->post_excerpt ?? ''); ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="post_status">Status</label>
                <select id="post_status" name="post_status">
                    <?php foreach (['draft', 'publish', 'pending', 'private'] as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo ($post->post_status ?? 'draft') === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php
        // Dynamic meta fields for this post type
        $meta_html = cr_render_meta_fields_form_v2($post_type, $post ? (int) $post->ID : 0);
        if ($meta_html):
        ?>
        <div class="meta-fields-section">
            <?php echo $meta_html; ?>
        </div>
        <?php endif; ?>

        <?php
        // Dynamic taxonomy selectors from get_object_taxonomies
        $type_taxonomies = get_object_taxonomies($post_type);
        foreach ($type_taxonomies as $tax_name):
            $tax_obj = get_taxonomy($tax_name);
            if (!$tax_obj || !$tax_obj->show_ui) continue;
            $all_tax_terms = get_terms(['taxonomy' => $tax_name, 'hide_empty' => false, 'orderby' => 'name']);
            $post_tax_terms = $post ? array_map(fn($t) => (int) $t->term_id, get_the_terms((int) $post->ID, $tax_name)) : [];
        ?>
        <div class="form-group">
            <label><?php echo esc_html($tax_obj->label); ?></label>
            <?php if ($tax_obj->hierarchical): ?>
                <div class="checkbox-list">
                    <?php foreach ($all_tax_terms as $t): ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="tax_<?php echo esc_attr($tax_name); ?>[]" value="<?php echo $t->term_id; ?>"
                                <?php echo in_array((int) $t->term_id, $post_tax_terms) ? 'checked' : ''; ?>>
                            <?php echo esc_html($t->name); ?>
                        </label>
                    <?php endforeach; ?>
                    <?php if (empty($all_tax_terms)): ?>
                        <p class="text-muted">No terms yet. <a href="?page=term-edit&taxonomy=<?php echo esc_attr($tax_name); ?>">Create one</a></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php
                $tag_names = !empty($post_tax_terms) ? implode(', ', array_map(fn($tid) => get_term($tid, $tax_name)?->name ?? '', $post_tax_terms)) : '';
                ?>
                <input type="text" name="tax_<?php echo esc_attr($tax_name); ?>_flat" value="<?php echo esc_attr($tag_names); ?>" class="input-full" placeholder="Comma separated">
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php /* Legacy hardcoded categories/tags for 'post' type - keep for backwards compat */
        if ($post_type === 'post' && empty($type_taxonomies)):
            // Category checkboxes
            $all_cats = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'name']);
            $post_cats = $post ? array_map(fn($t) => (int) $t->term_id, get_the_terms((int) $post->ID, 'category')) : [];
        ?>
        <div class="form-group">
            <label>Categories</label>
            <div class="checkbox-list">
                <?php foreach ($all_cats as $cat): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="post_categories[]" value="<?php echo $cat->term_id; ?>"
                            <?php echo in_array((int) $cat->term_id, $post_cats) ? 'checked' : ''; ?>>
                        <?php echo esc_html($cat->name); ?>
                    </label>
                <?php endforeach; ?>
                <?php if (empty($all_cats)): ?>
                    <p class="text-muted">No categories yet. <a href="?page=term-edit&taxonomy=category">Create one</a></p>
                <?php endif; ?>
            </div>
        </div>

        <?php
            // Tags input
            $post_tags = $post ? get_the_terms((int) $post->ID, 'post_tag') : [];
            $tag_names = !empty($post_tags) ? implode(', ', array_map(fn($t) => $t->name, $post_tags)) : '';
        ?>
        <div class="form-group">
            <label for="post_tags">Tags</label>
            <input type="text" id="post_tags" name="post_tags" value="<?php echo esc_attr($tag_names); ?>" class="input-full" placeholder="tag1, tag2, tag3 (comma separated)">
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <?php echo $post ? "Update {$label}" : "Publish {$label}"; ?>
            </button>
            <a href="?page=<?php echo $post_type === 'page' ? 'pages' : 'posts'; ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
<?php
}

function cr_admin_save_post(): void {
    $post_id = (int) ($_POST['post_id'] ?? 0);
    $data = [
        'post_title'   => $_POST['post_title'] ?? '',
        'post_name'    => $_POST['post_name'] ?? '',
        'post_content' => $_POST['post_content'] ?? '',
        'post_excerpt' => $_POST['post_excerpt'] ?? '',
        'post_status'  => $_POST['post_status'] ?? 'draft',
        'post_type'    => $_POST['post_type'] ?? 'post',
        'post_author'  => get_current_user_id(),
    ];

    if ($post_id) {
        $data['ID'] = $post_id;
        cr_update_post($data);
    } else {
        $post_id = cr_insert_post($data);
    }

    if ($post_id) {
        // Save dynamic taxonomy assignments
        $type_taxonomies = get_object_taxonomies($data['post_type']);
        foreach ($type_taxonomies as $tax_name) {
            $tax_obj = get_taxonomy($tax_name);
            if (!$tax_obj) continue;

            if ($tax_obj->hierarchical) {
                // Checkbox-based: tax_category[], tax_brand[]
                $term_ids = $_POST['tax_' . $tax_name] ?? [];
                cr_set_post_terms($post_id, array_map('intval', $term_ids), $tax_name);
            } else {
                // Comma-separated flat tags: tax_post_tag_flat
                $flat_input = trim($_POST['tax_' . $tax_name . '_flat'] ?? '');
                if (!empty($flat_input)) {
                    $names = array_filter(array_map('trim', explode(',', $flat_input)));
                    cr_set_post_terms($post_id, $names, $tax_name);
                } else {
                    cr_set_post_terms($post_id, [], $tax_name);
                }
            }
        }

        // Legacy fallback: hardcoded post_categories/post_tags for 'post' type
        if ($data['post_type'] === 'post' && empty($type_taxonomies)) {
            $categories = $_POST['post_categories'] ?? [];
            cr_set_post_terms($post_id, array_map('intval', $categories), 'category');
            $tags_input = trim($_POST['post_tags'] ?? '');
            if (!empty($tags_input)) {
                cr_set_post_terms($post_id, array_filter(array_map('trim', explode(',', $tags_input))), 'post_tag');
            } else {
                cr_set_post_terms($post_id, [], 'post_tag');
            }
        }

        // Save custom meta fields (v2: groups + conditions + repeaters)
        cr_save_meta_fields_from_post_v2($post_id, $data['post_type']);
    }

    $list_page = $data['post_type'] === 'page' ? 'pages' : 'posts';
    header('Location: ' . CR_SITE_URL . "/admin/?page={$list_page}&msg=saved");
    exit;
}

// Settings functions moved to admin/pages/settings.php

function sanitize_text_field(string $str): string {
    return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
}

// ==========================================
// Taxonomy Management
// ==========================================

function cr_admin_taxonomy_list(string $taxonomy): void {
    $db = cr_db();
    $tax_obj = get_taxonomy($taxonomy);
    $label = $tax_obj ? $tax_obj->label : ucfirst($taxonomy);
    $page_slug = $_GET['page'] ?? $taxonomy;
    $is_hierarchical = $tax_obj ? (bool) $tax_obj->hierarchical : false;

    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);

    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header">
        <h1><?php echo $label; ?></h1>
        <a href="?page=term-edit&taxonomy=<?php echo esc_attr($taxonomy); ?>" class="btn btn-primary">Add New</a>
    </div>

    <?php if ($msg === 'deleted'): ?>
        <div class="admin-notice success">Term deleted.</div>
    <?php elseif ($msg === 'saved'): ?>
        <div class="admin-notice success">Term saved.</div>
    <?php endif; ?>

    <table class="admin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Slug</th>
                <?php if ($is_hierarchical): ?><th>Parent</th><?php endif; ?>
                <th>Count</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($terms as $term): ?>
            <tr>
                <td><strong><a href="?page=term-edit&taxonomy=<?php echo esc_attr($taxonomy); ?>&id=<?php echo $term->term_id; ?>"><?php echo esc_html($term->name); ?></a></strong></td>
                <td><code><?php echo esc_html($term->slug); ?></code></td>
                <?php if ($is_hierarchical): ?>
                    <td>
                        <?php
                        if ((int) $term->parent > 0) {
                            $parent = get_term((int) $term->parent, $taxonomy);
                            echo $parent ? esc_html($parent->name) : '-';
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                <?php endif; ?>
                <td><?php echo (int) $term->count; ?></td>
                <td>
                    <a href="?page=term-edit&taxonomy=<?php echo esc_attr($taxonomy); ?>&id=<?php echo $term->term_id; ?>">Edit</a>
                    <?php if ($term->slug !== 'uncategorized'): ?>
                        <a href="?page=<?php echo $page_slug; ?>&action=delete&id=<?php echo $term->term_id; ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="text-danger" onclick="return confirm('Delete this term?')">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($terms)): ?>
            <tr><td colspan="<?php echo $is_hierarchical ? 5 : 4; ?>">No <?php echo strtolower($label); ?> found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php
}

function cr_admin_term_edit(): void {
    $taxonomy = $_GET['taxonomy'] ?? 'category';
    $term_id = (int) ($_GET['id'] ?? 0);
    $tax_obj = get_taxonomy($taxonomy);
    $is_hierarchical = $tax_obj ? (bool) $tax_obj->hierarchical : false;
    $label = $tax_obj ? ($tax_obj->label_singular ?? $tax_obj->label ?? ucfirst($taxonomy)) : ucfirst($taxonomy);
    $page_slug = $_GET['return_page'] ?? ($taxonomy === 'category' ? 'categories' : ($taxonomy === 'post_tag' ? 'tags' : 'categories'));

    $term = null;
    if ($term_id) {
        $term = get_term($term_id, $taxonomy);
    }

    // Get all terms for parent dropdown (hierarchical only)
    $all_terms = [];
    if ($is_hierarchical) {
        $all_terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name']);
    }
?>
    <div class="admin-header">
        <h1><?php echo $term ? "Edit {$label}" : "New {$label}"; ?></h1>
    </div>

    <form method="post" action="?page=term-edit&taxonomy=<?php echo esc_attr($taxonomy); ?><?php echo $term ? '&id=' . $term->term_id : ''; ?>">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="taxonomy" value="<?php echo esc_attr($taxonomy); ?>">
        <?php if ($term): ?>
            <input type="hidden" name="term_id" value="<?php echo $term->term_id; ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="term_name">Name</label>
            <input type="text" id="term_name" name="term_name" value="<?php echo esc_attr($term->name ?? ''); ?>" class="input-full" placeholder="Term name" required autofocus>
        </div>

        <div class="form-group">
            <label for="term_slug">Slug</label>
            <input type="text" id="term_slug" name="term_slug" value="<?php echo esc_attr($term->slug ?? ''); ?>" class="input-full" placeholder="auto-generated-from-name">
        </div>

        <?php if ($is_hierarchical): ?>
        <div class="form-group">
            <label for="term_parent">Parent</label>
            <select id="term_parent" name="term_parent">
                <option value="0">None (top level)</option>
                <?php foreach ($all_terms as $t):
                    if ($term && (int) $t->term_id === $term_id) continue; // Can't be own parent
                ?>
                    <option value="<?php echo $t->term_id; ?>" <?php echo ($term && (int) $term->parent === (int) $t->term_id) ? 'selected' : ''; ?>>
                        <?php echo esc_html($t->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="term_description">Description</label>
            <textarea id="term_description" name="term_description" rows="4" class="input-full"><?php echo esc_html($term->description ?? ''); ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <?php echo $term ? "Update {$label}" : "Add {$label}"; ?>
            </button>
            <a href="?page=<?php echo $page_slug; ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
<?php
}

function cr_admin_save_term(): void {
    $taxonomy = $_POST['taxonomy'] ?? 'category';
    $term_id = (int) ($_POST['term_id'] ?? 0);
    $name = trim($_POST['term_name'] ?? '');
    $slug = trim($_POST['term_slug'] ?? '');
    $parent = (int) ($_POST['term_parent'] ?? 0);
    $description = trim($_POST['term_description'] ?? '');
    $page_slug = $taxonomy === 'category' ? 'categories' : 'tags';

    if (empty($name)) {
        header('Location: ' . CR_SITE_URL . "/admin/?page={$page_slug}&msg=error");
        exit;
    }

    if ($term_id) {
        // Update existing term
        $args = ['name' => $name, 'description' => $description];
        if (!empty($slug)) $args['slug'] = $slug;
        if ($taxonomy === 'category') $args['parent'] = $parent;
        cr_update_term($term_id, $taxonomy, $args);
    } else {
        // Create new term
        $args = ['description' => $description];
        if (!empty($slug)) $args['slug'] = $slug;
        if ($taxonomy === 'category') $args['parent'] = $parent;
        cr_insert_term($name, $taxonomy, $args);
    }

    header('Location: ' . CR_SITE_URL . "/admin/?page={$page_slug}&msg=saved");
    exit;
}

// ==========================================
// Taxonomy assignment in post editor
// ==========================================

function cr_admin_login_page(string $error = ''): void {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo esc_html(get_option('blogname', 'Clean Room CMS')); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f0f1; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-box { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); width: 360px; }
        .login-logo { text-align: center; font-size: 2em; font-weight: 700; margin-bottom: 24px; color: #1d2327; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-weight: 600; margin-bottom: 4px; font-size: .9em; }
        input { width: 100%; padding: 10px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 1em; }
        input:focus { border-color: #2271b1; outline: none; box-shadow: 0 0 0 1px #2271b1; }
        .btn { width: 100%; padding: 12px; background: #2271b1; color: #fff; border: none; border-radius: 4px; font-size: 1em; cursor: pointer; }
        .btn:hover { background: #135e96; }
        .error { background: #fcf0f1; border-left: 4px solid #d63638; padding: 10px 14px; margin-bottom: 16px; border-radius: 0 4px 4px 0; font-size: .9em; }
        .back-link { text-align: center; margin-top: 16px; }
        .back-link a { color: #2271b1; text-decoration: none; font-size: .9em; }
    </style>
</head>
<body>
<div class="login-box">
    <div class="login-logo">CR</div>
    <?php if ($error): ?><div class="error"><?php echo esc_html($error); ?></div><?php endif; ?>
    <form method="post" action="?action=login">
        <div class="form-group">
            <label for="log">Username or Email</label>
            <input type="text" id="log" name="log" required autofocus>
        </div>
        <div class="form-group">
            <label for="pwd">Password</label>
            <input type="password" id="pwd" name="pwd" required>
        </div>
        <button type="submit" class="btn">Log In</button>
    </form>
    <div class="back-link"><a href="<?php echo esc_url(CR_HOME_URL); ?>">&larr; Back to site</a></div>
</div>
</body>
</html>
<?php
}
