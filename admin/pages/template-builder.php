<?php
/**
 * Clean Room CMS - Template Builder Admin Page
 *
 * Visual block-based template editor. Users compose templates
 * by clicking blocks from a palette. Structure is displayed as
 * a nested tree that can be reordered and configured.
 */

function cr_admin_template_builder(): void {
    if (!current_user_can('edit_theme_options')) {
        echo '<p>Access denied.</p>';
        return;
    }

    $template_name = $_GET['template'] ?? '';
    $msg = $_GET['msg'] ?? '';
    $all_templates = cr_get_all_block_templates();
    $current = $template_name ? cr_get_block_template($template_name) : null;
    $current_blocks = $current ? (json_decode($current->blocks, true) ?: []) : [];
    $block_types = cr_get_block_types();

    // Group block types by category
    $categories = [];
    foreach ($block_types as $type => $bt) {
        $cat = $bt['category'] ?? 'general';
        $categories[$cat][$type] = $bt;
    }
?>
    <div class="admin-header">
        <h1>Template Builder<?php echo $current ? ': ' . esc_html($current->label) : ''; ?></h1>
        <div style="display:flex;gap:8px">
            <?php if ($current): ?>
                <a href="<?php echo esc_url(CR_SITE_URL); ?>/" target="_blank" class="btn btn-secondary">Preview Site</a>
            <?php endif; ?>
            <a href="?page=template-builder&action=export" class="btn btn-secondary">Export Theme JSON</a>
        </div>
    </div>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">Template saved.</div><?php endif; ?>
    <?php if ($msg === 'imported'): ?><div class="admin-notice success">Theme imported.</div><?php endif; ?>
    <?php if ($msg === 'deleted'): ?><div class="admin-notice success">Template deleted.</div><?php endif; ?>

    <!-- Template tabs -->
    <div class="template-tabs">
        <?php
        $standard = ['index' => 'Home', 'single' => 'Single Post', 'page' => 'Page', 'archive' => 'Archive', 'search' => 'Search', '404' => '404'];
        foreach ($standard as $name => $label):
            $exists = false;
            foreach ($all_templates as $t) { if ($t->name === $name) { $exists = true; break; } }
            $active = ($template_name === $name) ? ' active' : '';
        ?>
            <a href="?page=template-builder&template=<?php echo $name; ?>" class="template-tab<?php echo $active; ?><?php echo $exists ? ' has-template' : ''; ?>">
                <?php echo $label; ?>
                <?php echo $exists ? '<span class="tab-dot"></span>' : ''; ?>
            </a>
        <?php endforeach; ?>
        <?php foreach ($all_templates as $t):
            if (isset($standard[$t->name])) continue;
        ?>
            <a href="?page=template-builder&template=<?php echo esc_attr($t->name); ?>" class="template-tab<?php echo $template_name === $t->name ? ' active' : ''; ?> has-template">
                <?php echo esc_html($t->label); ?><span class="tab-dot"></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($template_name)): ?>
        <div class="admin-section" style="margin-top:16px">
            <h2>Welcome to the Template Builder</h2>
            <p>Select a template above to start editing, or import a theme JSON file.</p>
            <form method="post" enctype="multipart/form-data" action="?page=template-builder" style="margin-top:16px">
                <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
                <input type="hidden" name="_action" value="import_theme">
                <label class="btn btn-primary" style="cursor:pointer">Import Theme JSON <input type="file" name="theme_json" accept=".json" style="display:none" onchange="this.form.submit()"></label>
            </form>
        </div>
    <?php return; endif; ?>

    <!-- Builder layout -->
    <div class="builder-layout">
        <!-- Left: Block palette -->
        <div class="builder-palette">
            <h3>Blocks</h3>
            <?php foreach ($categories as $cat_name => $types): ?>
                <div class="palette-category">
                    <div class="palette-cat-label"><?php echo esc_html(ucfirst($cat_name)); ?></div>
                    <?php foreach ($types as $type => $bt): ?>
                        <button type="button" class="palette-block" data-type="<?php echo esc_attr($type); ?>" data-label="<?php echo esc_attr($bt['label']); ?>" data-children="<?php echo $bt['supports_children'] ? '1' : '0'; ?>" data-config="<?php echo esc_attr(json_encode($bt['config_schema'] ?? [])); ?>">
                            <?php echo esc_html($bt['label']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Center: Template structure -->
        <div class="builder-structure">
            <form method="post" action="?page=template-builder&template=<?php echo esc_attr($template_name); ?>" id="template-form">
                <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
                <input type="hidden" name="_action" value="save_template">
                <input type="hidden" name="template_name" value="<?php echo esc_attr($template_name); ?>">
                <input type="hidden" name="template_label" value="<?php echo esc_attr($current->label ?? ucfirst($template_name)); ?>">
                <input type="hidden" id="blocks_json" name="blocks_json" value="<?php echo esc_attr(json_encode($current_blocks)); ?>">

                <div class="structure-header">
                    <h3>Structure</h3>
                    <div>
                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                        <?php if ($current): ?>
                            <a href="?page=template-builder&template=<?php echo esc_attr($template_name); ?>&action=delete&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="btn btn-sm text-danger" onclick="return confirm('Delete this template? Will fall back to PHP file.')">Delete</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="block-tree" class="block-tree">
                    <!-- Populated by JS from blocks_json -->
                </div>

                <div class="structure-footer">
                    <p class="field-desc">Click a block on the left to add it. Click a block in the tree to configure it. Drag to reorder.</p>
                </div>
            </form>
        </div>

        <!-- Right: Block config -->
        <div class="builder-config" id="block-config">
            <h3>Configuration</h3>
            <p class="field-desc">Select a block to edit its settings.</p>
        </div>
    </div>

    <script src="<?php echo esc_url(CR_SITE_URL); ?>/admin/assets/js/template-builder.js"></script>
<?php
}

function cr_admin_save_template_handler(): void {
    if (!current_user_can('edit_theme_options')) return;

    $name = $_POST['template_name'] ?? '';
    $label = $_POST['template_label'] ?? '';
    $blocks_json = $_POST['blocks_json'] ?? '[]';
    $css = $_POST['template_css'] ?? '';

    if (empty($name)) return;

    $blocks = json_decode($blocks_json, true);
    if (!is_array($blocks)) $blocks = [];

    cr_save_block_template([
        'name'   => $name,
        'label'  => $label ?: ucfirst($name),
        'blocks' => $blocks,
        'css'    => $css,
    ]);

    header('Location: ' . CR_SITE_URL . '/admin/?page=template-builder&template=' . urlencode($name) . '&msg=saved');
    exit;
}

function cr_admin_import_theme_handler(): void {
    if (!current_user_can('edit_theme_options')) return;
    if (empty($_FILES['theme_json']['tmp_name'])) return;

    $json = file_get_contents($_FILES['theme_json']['tmp_name']);
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['templates'])) {
        header('Location: ' . CR_SITE_URL . '/admin/?page=template-builder&msg=error');
        exit;
    }

    $count = cr_import_theme_json($data);

    header('Location: ' . CR_SITE_URL . '/admin/?page=template-builder&msg=imported');
    exit;
}

function cr_admin_export_theme(): void {
    if (!current_user_can('edit_theme_options')) return;

    $data = cr_export_theme_json();
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="theme-export-' . date('Y-m-d') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
