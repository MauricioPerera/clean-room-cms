<?php
/**
 * Clean Room CMS - Live API Documentation
 *
 * Auto-generated from registered routes, content types, taxonomies, and meta fields.
 * Updates in real-time as the user creates new content structures.
 */

function cr_admin_api_docs(): void {
    $base_url = CR_SITE_URL . '/api/cr/v1';

    // Gather all registered content types (built-in + custom)
    $builtin_types = [
        'post' => ['label' => 'Posts', 'rest_base' => 'posts', 'description' => 'Blog posts and articles'],
        'page' => ['label' => 'Pages', 'rest_base' => 'pages', 'description' => 'Static pages'],
    ];

    $custom_types = [];
    foreach (cr_get_content_types() as $ct) {
        if ($ct->status !== 'active' || !$ct->show_in_rest) continue;
        $custom_types[$ct->name] = [
            'label'     => $ct->label,
            'rest_base' => $ct->rest_base ?: $ct->name . 's',
            'description' => $ct->description ?: 'Custom content type',
        ];
    }

    // Gather taxonomies
    $builtin_taxes = [
        'category' => ['label' => 'Categories', 'rest_base' => 'categories', 'hierarchical' => true],
        'post_tag' => ['label' => 'Tags', 'rest_base' => 'tags', 'hierarchical' => false],
    ];

    $custom_taxes = [];
    foreach (cr_get_content_taxonomies() as $tax) {
        if ($tax->status !== 'active' || !$tax->show_in_rest) continue;
        $custom_taxes[$tax->name] = [
            'label'        => $tax->label,
            'rest_base'    => $tax->rest_base ?: $tax->name,
            'hierarchical' => (bool) $tax->hierarchical,
        ];
    }

    // Gather meta fields by post type
    $meta_by_type = [];
    $all_types = array_merge(array_keys($builtin_types), array_keys($custom_types));
    foreach ($all_types as $pt) {
        $fields = cr_get_meta_fields($pt);
        if (!empty($fields)) {
            $meta_by_type[$pt] = $fields;
        }
    }
    // Global meta fields (post_type = '')
    $global_fields = cr_get_meta_fields('');
?>
    <div class="admin-header">
        <h1>API Documentation</h1>
        <div style="display:flex;gap:8px">
            <span class="status-badge status-publish">Live</span>
            <span style="color:var(--color-text-light);font-size:.85em">Base URL: <code><?php echo esc_html($base_url); ?></code></span>
        </div>
    </div>

    <p style="margin-bottom:24px;color:var(--color-text-light)">
        This documentation is auto-generated from your registered content types, taxonomies, and meta fields.
        It updates automatically when you create or modify content structures.
    </p>

    <div class="api-docs">

        <!-- Authentication -->
        <div class="api-section">
            <h2>Authentication</h2>
            <div class="api-block">
                <p>All write operations require authentication. Two methods supported:</p>
                <table class="api-params-table">
                    <tr><td><strong>HTTP Basic Auth</strong></td><td>Send <code>Authorization: Basic {base64(username:password)}</code></td></tr>
                    <tr><td><strong>Nonce Header</strong></td><td>Send <code>X-CR-Nonce: {nonce}</code> for same-origin requests</td></tr>
                </table>
                <p class="api-note">Rate limited to <strong><?php echo (int) get_option('cr_api_rate_limit_val', 100); ?> requests/minute</strong> per IP.</p>
            </div>
        </div>

        <!-- Content Types -->
        <?php foreach (array_merge($builtin_types, $custom_types) as $type_name => $type):
            $base = $type['rest_base'];
            $is_custom = isset($custom_types[$type_name]);
            $fields = $meta_by_type[$type_name] ?? [];
            $type_taxes = get_object_taxonomies($type_name);
        ?>
        <div class="api-section">
            <h2>
                <?php echo esc_html($type['label']); ?>
                <?php if ($is_custom): ?><span class="api-badge custom">custom</span><?php endif; ?>
            </h2>
            <p class="api-desc"><?php echo esc_html($type['description'] ?? ''); ?></p>

            <!-- Endpoints -->
            <div class="api-endpoint">
                <span class="method get">GET</span>
                <code><?php echo esc_html("/{$base}"); ?></code>
                <span class="api-label">List all <?php echo esc_html(strtolower($type['label'])); ?></span>
            </div>
            <div class="api-endpoint-details">
                <strong>Query Parameters:</strong>
                <table class="api-params-table">
                    <tr><td><code>per_page</code></td><td>Results per page (default: 10, max: 100)</td></tr>
                    <tr><td><code>page</code></td><td>Page number</td></tr>
                    <tr><td><code>search</code></td><td>Search keyword</td></tr>
                    <tr><td><code>orderby</code></td><td><code>date</code> | <code>title</code> | <code>id</code> | <code>modified</code></td></tr>
                    <tr><td><code>order</code></td><td><code>ASC</code> | <code>DESC</code></td></tr>
                    <tr><td><code>status</code></td><td><code>publish</code> | <code>draft</code> | <code>pending</code></td></tr>
                    <tr><td><code>_fields</code></td><td>Comma-separated field list to return</td></tr>
                    <?php if (in_array('category', $type_taxes)): ?>
                    <tr><td><code>categories</code></td><td>Filter by category ID</td></tr>
                    <?php endif; ?>
                </table>
                <strong>Response Headers:</strong> <code>X-CR-Total</code>, <code>X-CR-TotalPages</code>
            </div>

            <div class="api-endpoint">
                <span class="method post">POST</span>
                <code><?php echo esc_html("/{$base}"); ?></code>
                <span class="api-label">Create <?php echo esc_html(strtolower($type['label'])); ?></span>
                <span class="api-badge auth">auth</span>
            </div>
            <div class="api-endpoint-details">
                <strong>Body (JSON):</strong>
                <table class="api-params-table">
                    <tr><td><code>title</code></td><td>string, required</td></tr>
                    <tr><td><code>content</code></td><td>string (HTML)</td></tr>
                    <tr><td><code>excerpt</code></td><td>string</td></tr>
                    <tr><td><code>status</code></td><td><code>draft</code> | <code>publish</code> | <code>pending</code></td></tr>
                    <tr><td><code>slug</code></td><td>string (URL slug, auto-generated if empty)</td></tr>
                    <?php foreach ($fields as $f):
                        if (!$f->show_in_rest) continue;
                    ?>
                    <tr><td><code><?php echo esc_html($f->name); ?></code></td><td><?php echo esc_html($f->field_type); ?><?php echo $f->required ? ', required' : ''; ?><?php echo $f->description ? ' — ' . esc_html($f->description) : ''; ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="api-endpoint">
                <span class="method get">GET</span>
                <code><?php echo esc_html("/{$base}/{id}"); ?></code>
                <span class="api-label">Get single item</span>
            </div>

            <div class="api-endpoint">
                <span class="method put">PUT</span>
                <code><?php echo esc_html("/{$base}/{id}"); ?></code>
                <span class="api-label">Update item</span>
                <span class="api-badge auth">auth</span>
            </div>

            <div class="api-endpoint">
                <span class="method delete">DELETE</span>
                <code><?php echo esc_html("/{$base}/{id}"); ?></code>
                <span class="api-label">Delete item</span>
                <span class="api-badge auth">auth</span>
            </div>

            <?php if (!empty($fields)): ?>
            <div class="api-meta-fields">
                <h4>Meta Fields</h4>
                <table class="api-params-table">
                    <tr><th>Key</th><th>Type</th><th>Required</th><th>Description</th></tr>
                    <?php foreach ($fields as $f):
                        if (!$f->show_in_rest) continue;
                        $opts = json_decode($f->options, true);
                        $type_label = $f->field_type;
                        if ($f->field_type === 'select' && !empty($opts)) {
                            $vals = array_map(fn($o) => is_array($o) ? $o['value'] : $o, $opts);
                            $type_label .= ' (' . implode(', ', $vals) . ')';
                        }
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($f->name); ?></code></td>
                        <td><?php echo esc_html($type_label); ?></td>
                        <td><?php echo $f->required ? 'Yes' : '—'; ?></td>
                        <td><?php echo esc_html($f->description ?: $f->label); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($type_taxes)): ?>
            <div class="api-meta-fields">
                <h4>Taxonomies</h4>
                <table class="api-params-table">
                    <?php foreach ($type_taxes as $tn):
                        $to = get_taxonomy($tn);
                        if (!$to) continue;
                    ?>
                    <tr><td><code><?php echo esc_html($tn); ?></code></td><td><?php echo esc_html($to->label); ?> (<?php echo $to->hierarchical ? 'hierarchical' : 'flat'; ?>)</td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Taxonomies -->
        <div class="api-section">
            <h2>Taxonomies</h2>
            <?php foreach (array_merge($builtin_taxes, $custom_taxes) as $tax_name => $tax):
                $is_custom = isset($custom_taxes[$tax_name]);
            ?>
            <div class="api-endpoint">
                <span class="method get">GET</span>
                <code>/<?php echo esc_html($tax['rest_base']); ?></code>
                <span class="api-label"><?php echo esc_html($tax['label']); ?></span>
                <?php if ($is_custom): ?><span class="api-badge custom">custom</span><?php endif; ?>
            </div>
            <div class="api-endpoint">
                <span class="method post">POST</span>
                <code>/<?php echo esc_html($tax['rest_base']); ?></code>
                <span class="api-label">Create term</span>
                <span class="api-badge auth">auth</span>
            </div>
            <div class="api-endpoint-details">
                <table class="api-params-table">
                    <tr><td><code>name</code></td><td>string, required</td></tr>
                    <tr><td><code>slug</code></td><td>string</td></tr>
                    <tr><td><code>description</code></td><td>string</td></tr>
                    <?php if ($tax['hierarchical']): ?>
                    <tr><td><code>parent</code></td><td>integer (parent term ID)</td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- System endpoints -->
        <div class="api-section">
            <h2>System</h2>

            <div class="api-endpoint">
                <span class="method get">GET</span>
                <code>/users</code>
                <span class="api-label">List users</span>
                <span class="api-badge auth">auth</span>
            </div>
            <div class="api-endpoint">
                <span class="method get">GET</span>
                <code>/users/me</code>
                <span class="api-label">Current user</span>
                <span class="api-badge auth">auth</span>
            </div>
            <div class="api-endpoint">
                <span class="method get">GET</span>
                <code>/search?search=keyword</code>
                <span class="api-label">Search all content</span>
            </div>
            <div class="api-endpoint">
                <span class="method get">GET</span>
                <code>/settings</code>
                <span class="api-label">Get site settings</span>
                <span class="api-badge auth">admin</span>
            </div>
            <div class="api-endpoint">
                <span class="method post">POST</span>
                <code>/settings</code>
                <span class="api-label">Update site settings</span>
                <span class="api-badge auth">admin</span>
            </div>
        </div>

        <!-- Content Management API -->
        <div class="api-section">
            <h2>Content Management</h2>
            <p class="api-desc">Manage content structures programmatically. All endpoints require admin authentication.</p>

            <div class="api-endpoint"><span class="method get">GET</span><code>/content-types</code><span class="api-label">List content types</span></div>
            <div class="api-endpoint"><span class="method post">POST</span><code>/content-types</code><span class="api-label">Create content type</span><span class="api-badge auth">admin</span></div>
            <div class="api-endpoint"><span class="method put">PUT</span><code>/content-types/{name}</code><span class="api-label">Update content type</span><span class="api-badge auth">admin</span></div>
            <div class="api-endpoint"><span class="method delete">DELETE</span><code>/content-types/{name}</code><span class="api-label">Delete content type</span><span class="api-badge auth">admin</span></div>

            <div class="api-endpoint"><span class="method get">GET</span><code>/meta-fields</code><span class="api-label">List meta fields</span></div>
            <div class="api-endpoint"><span class="method post">POST</span><code>/meta-fields</code><span class="api-label">Create meta field</span><span class="api-badge auth">admin</span></div>
            <div class="api-endpoint"><span class="method put">PUT</span><code>/meta-fields/{id}</code><span class="api-label">Update meta field</span><span class="api-badge auth">admin</span></div>
            <div class="api-endpoint"><span class="method delete">DELETE</span><code>/meta-fields/{id}</code><span class="api-label">Delete meta field</span><span class="api-badge auth">admin</span></div>
        </div>

        <!-- MCP -->
        <div class="api-section">
            <h2>MCP (Model Context Protocol)</h2>
            <p class="api-desc">For external AI assistants. Base URL: <code><?php echo esc_html(CR_SITE_URL); ?>/mcp/</code></p>

            <div class="api-endpoint"><span class="method get">GET</span><code>/mcp/</code><span class="api-label">Server info + capabilities</span></div>
            <div class="api-endpoint"><span class="method get">GET</span><code>/mcp/tools</code><span class="api-label">List available tools</span></div>
            <div class="api-endpoint"><span class="method post">POST</span><code>/mcp/execute</code><span class="api-label">Execute a tool</span><span class="api-badge auth">auth</span></div>
            <div class="api-endpoint"><span class="method get">GET</span><code>/mcp/resources</code><span class="api-label">List resources</span></div>
            <div class="api-endpoint"><span class="method get">GET</span><code>/mcp/prompts</code><span class="api-label">List prompt templates</span></div>
        </div>

        <!-- Response Format -->
        <div class="api-section">
            <h2>Response Format</h2>
            <div class="api-block">
                <p>All responses are JSON. Content endpoints return:</p>
<pre class="api-code">{
  "id": 1,
  "date": "2026-04-07 12:00:00",
  "slug": "hello-world",
  "status": "publish",
  "type": "post",
  "link": "http://localhost:8090/?p=1",
  "title": {"rendered": "Hello World"},
  "content": {"rendered": "&lt;p&gt;Content here&lt;/p&gt;"},
  "excerpt": {"rendered": "Excerpt text..."},
  "author": 1,
  "categories": [1],
  "tags": []
}</pre>
                <p><strong>Errors:</strong></p>
<pre class="api-code">{
  "code": "rest_post_invalid_id",
  "message": "Invalid post ID."
}</pre>
            </div>
        </div>

    </div>
<?php
}
