<?php
/**
 * Clean Room CMS - User Management Pages
 */

function cr_admin_users_list(): void {
    $db = cr_db();
    $users = $db->get_results("SELECT * FROM `{$db->prefix}users` ORDER BY user_registered DESC");
    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header">
        <h1>Users</h1>
        <a href="?page=user-edit" class="btn btn-primary">Add New</a>
    </div>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">User saved.</div><?php endif; ?>
    <?php if ($msg === 'deleted'): ?><div class="admin-notice success">User deleted.</div><?php endif; ?>

    <table class="admin-table">
        <thead><tr><th>Username</th><th>Display Name</th><th>Email</th><th>Role</th><th>Registered</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u):
            $caps = get_user_meta((int) $u->ID, $db->prefix . 'capabilities', true);
            $role = is_array($caps) ? array_key_first($caps) : 'subscriber';
        ?>
            <tr>
                <td><strong><a href="?page=user-edit&id=<?php echo $u->ID; ?>"><?php echo esc_html($u->user_login); ?></a></strong></td>
                <td><?php echo esc_html($u->display_name); ?></td>
                <td><?php echo esc_html($u->user_email); ?></td>
                <td><span class="status-badge status-publish"><?php echo esc_html(ucfirst($role)); ?></span></td>
                <td><?php echo date('Y-m-d', strtotime($u->user_registered)); ?></td>
                <td>
                    <a href="?page=user-edit&id=<?php echo $u->ID; ?>">Edit</a>
                    <?php if ((int) $u->ID !== get_current_user_id()): ?>
                        <a href="?page=users&action=delete&id=<?php echo $u->ID; ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="text-danger" onclick="return confirm('Delete this user?')">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php
}

function cr_admin_user_edit(): void {
    $id = (int) ($_GET['id'] ?? 0);
    $user = $id ? get_userdata($id) : null;
    $db = cr_db();
    $caps = $user ? (get_user_meta($id, $db->prefix . 'capabilities', true) ?: []) : [];
    $current_role = is_array($caps) ? array_key_first($caps) : 'subscriber';
    global $cr_roles;
?>
    <div class="admin-header">
        <h1><?php echo $user ? 'Edit User' : 'New User'; ?></h1>
    </div>

    <form method="post" action="?page=user-edit<?php echo $user ? '&id=' . $user->ID : ''; ?>">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="_action" value="save_user">
        <?php if ($user): ?><input type="hidden" name="user_id" value="<?php echo $user->ID; ?>"><?php endif; ?>

        <div class="form-row">
            <div class="form-group" style="flex:1">
                <label for="user_login">Username</label>
                <input type="text" id="user_login" name="user_login" value="<?php echo esc_attr($user->user_login ?? ''); ?>" class="input-full" required <?php echo $user ? 'readonly' : ''; ?>>
            </div>
            <div class="form-group" style="flex:1">
                <label for="display_name">Display Name</label>
                <input type="text" id="display_name" name="display_name" value="<?php echo esc_attr($user->display_name ?? ''); ?>" class="input-full">
            </div>
        </div>

        <div class="form-group">
            <label for="user_email">Email</label>
            <input type="email" id="user_email" name="user_email" value="<?php echo esc_attr($user->user_email ?? ''); ?>" class="input-full" required>
        </div>

        <div class="form-group">
            <label for="user_pass"><?php echo $user ? 'New Password (leave empty to keep current)' : 'Password'; ?></label>
            <input type="password" id="user_pass" name="user_pass" class="input-full" <?php echo $user ? '' : 'required'; ?>>
        </div>

        <div class="form-group">
            <label for="role">Role</label>
            <select id="role" name="role">
                <?php foreach ($cr_roles as $slug => $r): ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php echo $current_role === $slug ? 'selected' : ''; ?>><?php echo esc_html($r['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="user_url">Website</label>
            <input type="url" id="user_url" name="user_url" value="<?php echo esc_attr($user->user_url ?? ''); ?>" class="input-full">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?php echo $user ? 'Update User' : 'Create User'; ?></button>
            <a href="?page=users" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
<?php
}

function cr_admin_save_user(): void {
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $db = cr_db();

    if ($user_id) {
        $data = ['display_name' => $_POST['display_name'] ?? '', 'user_email' => $_POST['user_email'] ?? '', 'user_url' => $_POST['user_url'] ?? ''];
        $db->update($db->prefix . 'users', $data, ['ID' => $user_id]);

        if (!empty($_POST['user_pass'])) {
            $db->update($db->prefix . 'users', ['user_pass' => password_hash($_POST['user_pass'], PASSWORD_BCRYPT)], ['ID' => $user_id]);
        }

        $role = $_POST['role'] ?? 'subscriber';
        update_user_meta($user_id, $db->prefix . 'capabilities', [$role => true]);
        update_user_meta($user_id, $db->prefix . 'user_level', cr_role_to_level($role));
    } else {
        $user_id = cr_create_user(
            $_POST['user_login'] ?? '', $_POST['user_pass'] ?? '', $_POST['user_email'] ?? '',
            ['display_name' => $_POST['display_name'] ?? '', 'role' => $_POST['role'] ?? 'subscriber', 'user_url' => $_POST['user_url'] ?? '']
        );
        if (!$user_id) {
            header('Location: ' . CR_SITE_URL . '/admin/?page=users&msg=error');
            exit;
        }
    }

    header('Location: ' . CR_SITE_URL . '/admin/?page=users&msg=saved');
    exit;
}
