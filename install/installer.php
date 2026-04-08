<?php
/**
 * Clean Room CMS - Installation Wizard
 *
 * Creates database tables, default content, and admin user.
 */

// Handle form submission
$step = $_GET['step'] ?? '1';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '2') {
    $site_title  = trim($_POST['site_title'] ?? 'My Site');
    $admin_user  = trim($_POST['admin_user'] ?? 'admin');
    $admin_pass  = $_POST['admin_pass'] ?? '';
    $admin_email = trim($_POST['admin_email'] ?? '');

    if (empty($admin_user) || empty($admin_pass) || empty($admin_email)) {
        $error = 'All fields are required.';
    } else {
        try {
            $db = cr_db();

            // Create tables
            $schema = file_get_contents(__DIR__ . '/schema.sql');
            $schema = str_replace('{prefix}', $db->prefix, $schema);

            // Split into individual statements
            $statements = array_filter(
                array_map('trim', explode(';', $schema)),
                fn($s) => !empty($s) && !str_starts_with($s, '--')
            );

            foreach ($statements as $sql) {
                $db->query($sql);
                if ($db->last_error) {
                    throw new Exception("SQL Error: {$db->last_error}");
                }
            }

            // Insert default options
            $default_options = [
                'siteurl'          => CR_SITE_URL,
                'home'             => CR_HOME_URL,
                'blogname'         => $site_title,
                'blogdescription'  => 'Just another Clean Room site',
                'admin_email'      => $admin_email,
                'posts_per_page'   => '10',
                'date_format'      => 'F j, Y',
                'time_format'      => 'g:i a',
                'show_on_front'    => 'posts',
                'page_on_front'    => '0',
                'page_for_posts'   => '0',
                'permalink_structure' => '/%postname%/',
                'active_plugins'   => serialize([]),
                'stylesheet'       => 'default',
                'template'         => 'default',
                'gmt_offset'       => '0',
                'cr_locale'        => 'en-US',
                'default_role'     => 'subscriber',
                'comment_moderation' => '0',
                'comments_per_page'  => '50',
            ];

            foreach ($default_options as $name => $value) {
                $db->insert($db->prefix . 'options', [
                    'option_name'  => $name,
                    'option_value' => $value,
                    'autoload'     => 'yes',
                ]);
            }

            // Create admin user
            $hash = password_hash($admin_pass, PASSWORD_BCRYPT);
            $admin_id = $db->insert($db->prefix . 'users', [
                'user_login'      => $admin_user,
                'user_pass'       => $hash,
                'user_nicename'   => strtolower($admin_user),
                'user_email'      => $admin_email,
                'user_url'        => '',
                'user_registered' => gmdate('Y-m-d H:i:s'),
                'user_activation_key' => '',
                'user_status'     => 0,
                'display_name'    => $admin_user,
            ]);

            // Set admin role
            $db->insert($db->prefix . 'usermeta', [
                'user_id'    => $admin_id,
                'meta_key'   => $db->prefix . 'capabilities',
                'meta_value' => serialize(['administrator' => true]),
            ]);
            $db->insert($db->prefix . 'usermeta', [
                'user_id'    => $admin_id,
                'meta_key'   => $db->prefix . 'user_level',
                'meta_value' => '10',
            ]);

            // Create default category
            $cat_id = $db->insert($db->prefix . 'terms', [
                'name' => 'Uncategorized',
                'slug' => 'uncategorized',
                'term_group' => 0,
            ]);
            $db->insert($db->prefix . 'term_taxonomy', [
                'term_id'     => $cat_id,
                'taxonomy'    => 'category',
                'description' => '',
                'parent'      => 0,
                'count'       => 1,
            ]);

            // Create sample post
            $now = gmdate('Y-m-d H:i:s');
            $post_id = $db->insert($db->prefix . 'posts', [
                'post_author'           => $admin_id,
                'post_date'             => $now,
                'post_date_gmt'         => $now,
                'post_content'          => 'Welcome to Clean Room CMS. This is your first post. Edit or delete it, then start writing!',
                'post_title'            => 'Hello World',
                'post_excerpt'          => '',
                'post_status'           => 'publish',
                'comment_status'        => 'open',
                'ping_status'           => 'open',
                'post_password'         => '',
                'post_name'             => 'hello-world',
                'to_ping'               => '',
                'pinged'                => '',
                'post_modified'         => $now,
                'post_modified_gmt'     => $now,
                'post_content_filtered' => '',
                'post_parent'           => 0,
                'guid'                  => CR_SITE_URL . '/?p=1',
                'menu_order'            => 0,
                'post_type'             => 'post',
                'post_mime_type'        => '',
                'comment_count'         => 0,
            ]);

            // Assign category to post
            $db->insert($db->prefix . 'term_relationships', [
                'object_id'        => $post_id,
                'term_taxonomy_id' => 1,
                'term_order'       => 0,
            ]);

            // Create sample page
            $db->insert($db->prefix . 'posts', [
                'post_author'           => $admin_id,
                'post_date'             => $now,
                'post_date_gmt'         => $now,
                'post_content'          => 'This is a sample page. It is different from a post because it stays in one place and will show up in your site navigation.',
                'post_title'            => 'Sample Page',
                'post_excerpt'          => '',
                'post_status'           => 'publish',
                'comment_status'        => 'closed',
                'ping_status'           => 'closed',
                'post_password'         => '',
                'post_name'             => 'sample-page',
                'to_ping'               => '',
                'pinged'                => '',
                'post_modified'         => $now,
                'post_modified_gmt'     => $now,
                'post_content_filtered' => '',
                'post_parent'           => 0,
                'guid'                  => CR_SITE_URL . '/?page_id=2',
                'menu_order'            => 0,
                'post_type'             => 'page',
                'post_mime_type'        => '',
                'comment_count'         => 0,
            ]);

            $message = 'Installation complete!';
            $step = '3';

        } catch (Exception $e) {
            $error = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clean Room CMS - Installation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f0f1; color: #1d2327; line-height: 1.6; }
        .installer { max-width: 580px; margin: 60px auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        h1 { font-size: 1.6em; margin-bottom: 24px; color: #1d2327; }
        h1 span { color: #2271b1; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: .9em; }
        input[type="text"], input[type="password"], input[type="email"] {
            width: 100%; padding: 10px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 1em;
        }
        input:focus { border-color: #2271b1; outline: none; box-shadow: 0 0 0 1px #2271b1; }
        .btn { display: inline-block; padding: 10px 24px; background: #2271b1; color: #fff; border: none; border-radius: 4px; font-size: 1em; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #135e96; }
        .error { background: #fcf0f1; border-left: 4px solid #d63638; padding: 12px 16px; margin-bottom: 20px; border-radius: 0 4px 4px 0; }
        .success { background: #edf7ed; border-left: 4px solid #00a32a; padding: 12px 16px; margin-bottom: 20px; border-radius: 0 4px 4px 0; }
        .info { background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px; border-radius: 0 4px 4px 0; }
        p { margin-bottom: 12px; }
        code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: .9em; }
    </style>
</head>
<body>
<div class="installer">

<?php if ($step === '1'): ?>
    <h1><span>Clean Room</span> CMS Installation</h1>
    <div class="info">
        <p>Before proceeding, make sure you have:</p>
        <p>1. Created a MySQL database named <code><?= esc_html(DB_NAME) ?></code></p>
        <p>2. Configured <code>wp-config.php</code> with your database credentials</p>
    </div>

    <?php
    // Test DB connection
    try {
        cr_db()->connect();
        echo '<div class="success"><p>Database connection successful!</p></div>';
    } catch (Exception $e) {
        echo '<div class="error"><p>Database connection failed: ' . esc_html($e->getMessage()) . '</p><p>Please check your <code>wp-config.php</code> settings.</p></div>';
    }
    ?>

    <a href="?step=2" class="btn">Continue to Setup</a>

<?php elseif ($step === '2'): ?>
    <h1>Site Configuration</h1>

    <?php if ($error): ?>
        <div class="error"><p><?= esc_html($error) ?></p></div>
    <?php endif; ?>

    <form method="post" action="?step=2">
        <div class="form-group">
            <label for="site_title">Site Title</label>
            <input type="text" id="site_title" name="site_title" value="<?= esc_attr($_POST['site_title'] ?? 'My Site') ?>" required>
        </div>

        <div class="form-group">
            <label for="admin_user">Admin Username</label>
            <input type="text" id="admin_user" name="admin_user" value="<?= esc_attr($_POST['admin_user'] ?? 'admin') ?>" required>
        </div>

        <div class="form-group">
            <label for="admin_pass">Admin Password</label>
            <input type="password" id="admin_pass" name="admin_pass" required>
        </div>

        <div class="form-group">
            <label for="admin_email">Admin Email</label>
            <input type="email" id="admin_email" name="admin_email" value="<?= esc_attr($_POST['admin_email'] ?? '') ?>" required>
        </div>

        <button type="submit" class="btn">Install Clean Room CMS</button>
    </form>

<?php elseif ($step === '3'): ?>
    <h1>Installation Complete!</h1>
    <div class="success"><p>Clean Room CMS has been installed successfully.</p></div>
    <p><a href="<?= esc_url(CR_SITE_URL) ?>" class="btn">View Your Site</a></p>
    <p style="margin-top: 12px;"><a href="<?= esc_url(CR_SITE_URL) ?>/admin/" class="btn" style="background: #1d2327;">Go to Admin</a></p>
<?php endif; ?>

</div>
</body>
</html>
