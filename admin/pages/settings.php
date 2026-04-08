<?php
/**
 * Clean Room CMS - Expanded Settings Page
 */

function cr_admin_settings_full(): void {
    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header"><h1>Settings</h1></div>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">Settings saved.</div><?php endif; ?>

    <form method="post" action="?page=settings">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">

        <div class="admin-section">
            <h2>General</h2>
            <div class="form-group">
                <label>Site Title</label>
                <input type="text" name="blogname" value="<?php echo esc_attr(get_option('blogname')); ?>" class="input-full">
            </div>
            <div class="form-group">
                <label>Tagline</label>
                <input type="text" name="blogdescription" value="<?php echo esc_attr(get_option('blogdescription')); ?>" class="input-full">
            </div>
            <div class="form-group">
                <label>Admin Email</label>
                <input type="email" name="admin_email" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="input-full">
            </div>
        </div>

        <div class="admin-section">
            <h2>Reading</h2>
            <div class="form-group">
                <label>Homepage displays</label>
                <select name="show_on_front">
                    <option value="posts" <?php echo get_option('show_on_front', 'posts') === 'posts' ? 'selected' : ''; ?>>Latest posts</option>
                    <option value="page" <?php echo get_option('show_on_front') === 'page' ? 'selected' : ''; ?>>Static page</option>
                </select>
            </div>
            <div class="form-group">
                <label>Posts per page</label>
                <input type="number" name="posts_per_page" value="<?php echo (int) get_option('posts_per_page', 10); ?>" min="1" max="100" style="width:100px">
            </div>
            <div class="form-group">
                <label>Homepage (if static page)</label>
                <select name="page_on_front">
                    <option value="0">— Select —</option>
                    <?php
                    $pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'nopaging' => true]);
                    foreach ($pages as $p):
                    ?>
                        <option value="<?php echo $p->ID; ?>" <?php echo (int) get_option('page_on_front') === (int) $p->ID ? 'selected' : ''; ?>><?php echo esc_html($p->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="admin-section">
            <h2>Date & Time</h2>
            <div class="form-row">
                <div class="form-group" style="flex:1">
                    <label>Date Format</label>
                    <input type="text" name="date_format" value="<?php echo esc_attr(get_option('date_format', 'F j, Y')); ?>" class="input-full" placeholder="F j, Y">
                    <p class="field-desc">PHP date format. Preview: <?php echo date(get_option('date_format', 'F j, Y')); ?></p>
                </div>
                <div class="form-group" style="flex:1">
                    <label>Time Format</label>
                    <input type="text" name="time_format" value="<?php echo esc_attr(get_option('time_format', 'g:i a')); ?>" class="input-full" placeholder="g:i a">
                    <p class="field-desc">Preview: <?php echo date(get_option('time_format', 'g:i a')); ?></p>
                </div>
            </div>
            <div class="form-group">
                <label>GMT Offset (hours)</label>
                <input type="number" name="gmt_offset" value="<?php echo esc_attr(get_option('gmt_offset', '0')); ?>" step="0.5" min="-12" max="14" style="width:100px">
            </div>
        </div>

        <div class="admin-section">
            <h2>Permalinks</h2>
            <div class="form-group">
                <label>Permalink Structure</label>
                <input type="text" name="permalink_structure" value="<?php echo esc_attr(get_option('permalink_structure', '/%postname%/')); ?>" class="input-full">
                <p class="field-desc">Tags: %year%, %monthnum%, %day%, %postname%, %category%, %author%</p>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>
<?php
}

function cr_admin_save_settings_full(): void {
    $fields = [
        'blogname', 'blogdescription', 'admin_email',
        'posts_per_page', 'show_on_front', 'page_on_front',
        'date_format', 'time_format', 'gmt_offset',
        'permalink_structure',
    ];

    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            update_option($field, sanitize_text_field($_POST[$field]));
        }
    }

    header('Location: ' . CR_SITE_URL . '/admin/?page=settings&msg=saved');
    exit;
}
