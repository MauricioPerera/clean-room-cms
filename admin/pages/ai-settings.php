<?php
/**
 * Clean Room CMS - AI Settings + Content Guidelines + Vector Search
 */

function cr_admin_ai_settings(): void {
    $connectors = get_option('cr_ai_connectors', []);
    if (!is_array($connectors)) $connectors = [];
    $default_provider = get_option('cr_ai_default_provider', '');
    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header"><h1>AI Settings</h1></div>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">AI settings saved.</div><?php endif; ?>

    <form method="post" action="?page=ai-settings">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="_action" value="save_ai_settings">

        <div class="admin-section">
            <h2>Default Provider</h2>
            <select name="default_provider">
                <option value="">None</option>
                <option value="openai" <?php echo $default_provider === 'openai' ? 'selected' : ''; ?>>OpenAI</option>
                <option value="anthropic" <?php echo $default_provider === 'anthropic' ? 'selected' : ''; ?>>Anthropic</option>
                <option value="ollama" <?php echo $default_provider === 'ollama' ? 'selected' : ''; ?>>Ollama (Local)</option>
            </select>
        </div>

        <div class="admin-section">
            <h2>OpenAI</h2>
            <div class="form-group">
                <label>API Key</label>
                <input type="password" name="openai_api_key" value="<?php echo esc_attr($connectors['openai']['api_key'] ?? ''); ?>" class="input-full" placeholder="sk-...">
            </div>
            <label class="checkbox-label"><input type="checkbox" name="openai_enabled" value="1" <?php echo ($connectors['openai']['enabled'] ?? false) ? 'checked' : ''; ?>> Enabled</label>
        </div>

        <div class="admin-section">
            <h2>Anthropic</h2>
            <div class="form-group">
                <label>API Key</label>
                <input type="password" name="anthropic_api_key" value="<?php echo esc_attr($connectors['anthropic']['api_key'] ?? ''); ?>" class="input-full" placeholder="sk-ant-...">
            </div>
            <label class="checkbox-label"><input type="checkbox" name="anthropic_enabled" value="1" <?php echo ($connectors['anthropic']['enabled'] ?? false) ? 'checked' : ''; ?>> Enabled</label>
        </div>

        <div class="admin-section">
            <h2>Ollama (Local)</h2>
            <div class="form-group">
                <label>Base URL</label>
                <input type="url" name="ollama_base_url" value="<?php echo esc_attr($connectors['ollama']['base_url'] ?? 'http://localhost:11434'); ?>" class="input-full">
            </div>
            <label class="checkbox-label"><input type="checkbox" name="ollama_enabled" value="1" <?php echo ($connectors['ollama']['enabled'] ?? false) ? 'checked' : ''; ?>> Enabled</label>
        </div>

        <div class="admin-section">
            <h2>MCP Server</h2>
            <div class="form-group">
                <label>MCP API Key (for external AI assistants)</label>
                <input type="text" name="mcp_api_key" value="<?php echo esc_attr(get_option('cr_mcp_api_key', '')); ?>" class="input-full" placeholder="Leave empty to disable Bearer auth">
            </div>
            <p class="field-desc">MCP endpoint: <code><?php echo esc_html(CR_SITE_URL); ?>/mcp/</code></p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save AI Settings</button>
        </div>
    </form>
<?php
}

function cr_admin_save_ai_settings(): void {
    $connectors = [
        'openai' => [
            'enabled'  => isset($_POST['openai_enabled']),
            'api_key'  => $_POST['openai_api_key'] ?? '',
        ],
        'anthropic' => [
            'enabled'  => isset($_POST['anthropic_enabled']),
            'api_key'  => $_POST['anthropic_api_key'] ?? '',
        ],
        'ollama' => [
            'enabled'  => isset($_POST['ollama_enabled']),
            'base_url' => $_POST['ollama_base_url'] ?? 'http://localhost:11434',
        ],
    ];

    update_option('cr_ai_connectors', $connectors, 'no');
    update_option('cr_ai_default_provider', $_POST['default_provider'] ?? '', 'no');
    update_option('cr_mcp_api_key', $_POST['mcp_api_key'] ?? '', 'no');

    header('Location: ' . CR_SITE_URL . '/admin/?page=ai-settings&msg=saved');
    exit;
}

// =============================================
// Content Guidelines Editor
// =============================================

function cr_admin_guidelines(): void {
    $guidelines = cr_get_content_guidelines();
    $msg = $_GET['msg'] ?? '';
?>
    <div class="admin-header"><h1>Content Guidelines</h1></div>
    <p style="margin-bottom:16px;color:var(--color-text-light)">Define editorial standards that AI agents will follow when creating or editing content on your site.</p>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">Guidelines saved.</div><?php endif; ?>

    <form method="post" action="?page=guidelines">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="_action" value="save_guidelines">

        <?php
        $sections = [
            'site'       => ['label' => 'Site', 'desc' => 'Goals, personality, and target audience', 'placeholder' => "Developer-focused tech blog.\nTarget: mid-senior engineers.\nGoal: educate and inform."],
            'copy'       => ['label' => 'Copy / Voice', 'desc' => 'Tone, voice, vocabulary, and style rules', 'placeholder' => "Tone: technical but approachable.\nUse active voice.\nNo jargon without explanation."],
            'images'     => ['label' => 'Images', 'desc' => 'Visual style preferences and constraints', 'placeholder' => "Prefer diagrams and screenshots.\nNo stock photos.\nAlt text always required."],
            'blocks'     => ['label' => 'Blocks / Structure', 'desc' => 'Per-block-type content rules', 'placeholder' => "Paragraphs: max 3 sentences.\nAlways use code blocks for examples.\nHeadings: sentence case."],
            'additional' => ['label' => 'Additional', 'desc' => 'Anything else', 'placeholder' => "Always cite sources.\nInclude TL;DR at the top of long posts."],
        ];

        foreach ($sections as $key => $sec):
        ?>
        <div class="admin-section">
            <h2><?php echo $sec['label']; ?></h2>
            <p class="field-desc"><?php echo $sec['desc']; ?></p>
            <textarea name="guideline_<?php echo $key; ?>" rows="5" class="input-full" placeholder="<?php echo esc_attr($sec['placeholder']); ?>"><?php echo esc_html($guidelines[$key] ?? ''); ?></textarea>
        </div>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Guidelines</button>
        </div>
    </form>
<?php
}

function cr_admin_save_guidelines(): void {
    $data = [];
    foreach (['site', 'copy', 'images', 'blocks', 'additional'] as $key) {
        $data[$key] = trim($_POST['guideline_' . $key] ?? '');
    }
    cr_set_content_guidelines($data);
    header('Location: ' . CR_SITE_URL . '/admin/?page=guidelines&msg=saved');
    exit;
}

// =============================================
// Vector Search Settings
// =============================================

function cr_admin_vector_settings(): void {
    $msg = $_GET['msg'] ?? '';
    $auto_index = get_option('cr_vector_auto_index', false);
    $embed_provider = get_option('cr_vector_embed_provider', 'openai');
    $embed_model = get_option('cr_vector_embed_model', 'text-embedding-3-small');
    $dimensions = get_option('cr_vector_dimensions', 1536);

    $stats = ['total_vectors' => 0, 'collections' => []];
    try { $stats = cr_vectors()->stats(); } catch (\Throwable $e) {}
?>
    <div class="admin-header"><h1>Vector Search</h1></div>

    <?php if ($msg === 'saved'): ?><div class="admin-notice success">Settings saved.</div><?php endif; ?>
    <?php if ($msg === 'reindexed'): ?><div class="admin-notice success">Reindex complete.</div><?php endif; ?>

    <div class="dashboard-cards" style="margin-bottom:24px">
        <div class="card"><div class="card-number"><?php echo (int) $stats['total_vectors']; ?></div><div class="card-label">Indexed Vectors</div></div>
        <?php foreach ($stats['collections'] ?? [] as $col => $cs): ?>
        <div class="card"><div class="card-number"><?php echo (int) ($cs['vectors'] ?? 0); ?></div><div class="card-label"><?php echo esc_html($col); ?></div></div>
        <?php endforeach; ?>
    </div>

    <form method="post" action="?page=vector-settings">
        <input type="hidden" name="_cr_nonce" value="<?php echo cr_create_nonce('admin_action'); ?>">
        <input type="hidden" name="_action" value="save_vector_settings">

        <div class="admin-section">
            <h2>Embedding Configuration</h2>
            <div class="form-row">
                <div class="form-group" style="flex:1">
                    <label>Provider</label>
                    <select name="embed_provider">
                        <option value="openai" <?php echo $embed_provider === 'openai' ? 'selected' : ''; ?>>OpenAI</option>
                        <option value="ollama" <?php echo $embed_provider === 'ollama' ? 'selected' : ''; ?>>Ollama (Local)</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1">
                    <label>Model</label>
                    <input type="text" name="embed_model" value="<?php echo esc_attr($embed_model); ?>" class="input-full">
                </div>
                <div class="form-group" style="flex:1">
                    <label>Dimensions</label>
                    <input type="number" name="dimensions" value="<?php echo (int) $dimensions; ?>" min="64" max="4096" style="width:100px">
                </div>
            </div>
        </div>

        <div class="admin-section">
            <h2>Auto-Indexing</h2>
            <label class="checkbox-label"><input type="checkbox" name="auto_index" value="1" <?php echo $auto_index ? 'checked' : ''; ?>> Automatically index posts on create/update</label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <a href="?page=vector-settings&action=reindex&_nonce=<?php echo cr_create_nonce('admin_action'); ?>" class="btn btn-secondary" onclick="return confirm('Reindex all published posts? This may take a while.')">Reindex All Posts</a>
        </div>
    </form>
<?php
}

function cr_admin_save_vector_settings(): void {
    update_option('cr_vector_embed_provider', $_POST['embed_provider'] ?? 'openai', 'no');
    update_option('cr_vector_embed_model', $_POST['embed_model'] ?? 'text-embedding-3-small', 'no');
    update_option('cr_vector_dimensions', (int) ($_POST['dimensions'] ?? 1536), 'no');
    update_option('cr_vector_auto_index', isset($_POST['auto_index']) ? 1 : 0, 'no');
    header('Location: ' . CR_SITE_URL . '/admin/?page=vector-settings&msg=saved');
    exit;
}
