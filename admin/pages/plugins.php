<?php
/**
 * Clean Room CMS - Plugin & Theme Management Pages
 */

function cr_admin_plugins_list(): void {
    $plugins = cr_scan_plugins();
    $active = get_option('active_plugins', []);
    if (!is_array($active)) $active = [];
    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header"><h1>Plugins</h1></div>

    <?php if ($msg === 'activated'): ?><div class="admin-notice success">Plugin activated.</div><?php endif; ?>
    <?php if ($msg === 'deactivated'): ?><div class="admin-notice success">Plugin deactivated.</div><?php endif; ?>

    <table class="admin-table">
        <thead><tr><th>Plugin</th><th>Description</th><th>Version</th><th>Permissions</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($plugins as $file => $info):
            $is_active = in_array($file, $active);
            $manifest = $info['manifest'] ?? null;
            $perms = $manifest ? ($manifest['permissions'] ?? []) : [];
        ?>
            <tr>
                <td><strong><?php echo esc_html($info['name'] ?? basename(dirname($file))); ?></strong></td>
                <td><?php echo esc_html($info['description'] ?? ''); ?></td>
                <td><?php echo esc_html($info['version'] ?? '—'); ?></td>
                <td><?php echo $perms ? '<code style="font-size:.75em">' . esc_html(implode(', ', $perms)) . '</code>' : '—'; ?></td>
                <td><span class="status-badge status-<?php echo $is_active ? 'publish' : 'draft'; ?>"><?php echo $is_active ? 'Active' : 'Inactive'; ?></span></td>
                <td>
                    <?php if ($is_active): ?>
                        <a href="?page=plugins&action=deactivate&plugin=<?php echo urlencode($file); ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>">Deactivate</a>
                    <?php else: ?>
                        <a href="?page=plugins&action=activate&plugin=<?php echo urlencode($file); ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>">Activate</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($plugins)): ?>
            <tr><td colspan="6">No plugins found in <code>content/plugins/</code>. Drop a plugin folder there to get started.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php
}

function cr_scan_plugins(): array {
    $plugins = [];
    $dir = CR_PLUGIN_PATH;
    if (!is_dir($dir)) return [];

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'mu') continue;
        $path = $dir . '/' . $entry;

        if (is_dir($path)) {
            $main = $path . '/' . $entry . '.php';
            if (!file_exists($main)) {
                $phps = glob($path . '/*.php');
                $main = $phps[0] ?? null;
            }
            if ($main && file_exists($main)) {
                $header = cr_parse_plugin_header($main);
                $manifest = CR_Sandbox::parse_manifest_file($path);
                $header['manifest'] = $manifest;
                $plugins[$entry . '/' . basename($main)] = $header;
            }
        } elseif (str_ends_with($entry, '.php')) {
            $header = cr_parse_plugin_header($path);
            $plugins[$entry] = $header;
        }
    }

    return $plugins;
}

function cr_parse_plugin_header(string $file): array {
    $content = file_get_contents($file, false, null, 0, 8192);
    $headers = ['name' => 'Plugin Name', 'description' => 'Description', 'version' => 'Version', 'author' => 'Author'];
    $result = [];
    foreach ($headers as $key => $label) {
        if (preg_match('/' . preg_quote($label) . ':\s*(.+)/i', $content, $m)) {
            $result[$key] = trim($m[1]);
        }
    }
    return $result;
}

function cr_admin_activate_plugin(string $file): void {
    $active = get_option('active_plugins', []);
    if (!is_array($active)) $active = [];
    if (!in_array($file, $active)) {
        $active[] = $file;
        update_option('active_plugins', $active);
    }
    header('Location: ' . CR_SITE_URL . '/admin/?page=plugins&msg=activated');
    exit;
}

function cr_admin_deactivate_plugin(string $file): void {
    $active = get_option('active_plugins', []);
    if (!is_array($active)) $active = [];
    $active = array_values(array_diff($active, [$file]));
    update_option('active_plugins', $active);
    CR_Sandbox::revoke_permissions(basename(dirname($file)));
    header('Location: ' . CR_SITE_URL . '/admin/?page=plugins&msg=deactivated');
    exit;
}

// =============================================
// Themes
// =============================================

function cr_admin_themes_list(): void {
    $themes = cr_scan_themes();
    $current = get_option('stylesheet', 'default');
    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header"><h1>Themes</h1></div>

    <?php if ($msg === 'switched'): ?><div class="admin-notice success">Theme activated.</div><?php endif; ?>

    <div class="theme-grid">
        <?php foreach ($themes as $slug => $info): $is_active = ($slug === $current); ?>
        <div class="theme-card <?php echo $is_active ? 'active' : ''; ?>">
            <div class="theme-preview"><?php echo $is_active ? '✓ Active' : ''; ?></div>
            <div class="theme-info">
                <h3><?php echo esc_html($info['name'] ?? $slug); ?></h3>
                <p><?php echo esc_html($info['description'] ?? ''); ?></p>
                <?php if ($info['version'] ?? ''): ?><small>v<?php echo esc_html($info['version']); ?></small><?php endif; ?>
            </div>
            <div class="theme-actions">
                <?php if (!$is_active): ?>
                    <a href="?page=themes&action=switch&theme=<?php echo urlencode($slug); ?>&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="btn btn-primary btn-sm">Activate</a>
                <?php else: ?>
                    <span class="status-badge status-publish">Active</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($themes)): ?>
            <p>No themes found in <code>content/themes/</code>.</p>
        <?php endif; ?>
    </div>
<?php
}

function cr_scan_themes(): array {
    $themes = [];
    $dir = CR_THEME_PATH;
    if (!is_dir($dir)) return [];

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (!is_dir($path)) continue;

        $style = $path . '/style.css';
        if (!file_exists($style)) continue;

        $content = file_get_contents($style, false, null, 0, 4096);
        $info = [];
        foreach (['Theme Name' => 'name', 'Description' => 'description', 'Version' => 'version', 'Author' => 'author'] as $header => $key) {
            if (preg_match('/' . preg_quote($header) . ':\s*(.+)/i', $content, $m)) {
                $info[$key] = trim($m[1]);
            }
        }
        $themes[$entry] = $info;
    }

    return $themes;
}

function cr_admin_switch_theme(string $slug): void {
    if (!is_dir(CR_THEME_PATH . '/' . $slug)) return;
    update_option('stylesheet', $slug);
    update_option('template', $slug);
    header('Location: ' . CR_SITE_URL . '/admin/?page=themes&msg=switched');
    exit;
}
