<?php
/**
 * Clean Room CMS - Declarative Template Engine
 *
 * Templates are defined as JSON block trees. Each block type has a
 * registered renderer. The engine resolves blocks recursively and
 * outputs HTML. No PHP knowledge needed to create themes.
 *
 * A theme = a set of named templates (header, footer, single, index, etc.)
 * stored as JSON in the cr_templates table.
 */

// Block type registry
$cr_block_types = [];

// =============================================
// Block Type Registration
// =============================================

function cr_register_block_type(string $type, array $args): void {
    global $cr_block_types;
    $cr_block_types[$type] = array_merge([
        'label'         => ucfirst(str_replace('-', ' ', $type)),
        'category'      => 'general',
        'config_schema' => [],
        'supports_children' => false,
        'render'        => null,
    ], $args);
}

function cr_get_block_types(): array {
    global $cr_block_types;
    return $cr_block_types;
}

function cr_get_block_type(string $type): ?array {
    global $cr_block_types;
    return $cr_block_types[$type] ?? null;
}

// =============================================
// Template CRUD
// =============================================

function cr_install_templates_table(): void {
    $db = cr_db();
    $table = $db->prefix . 'templates';
    if (!$db->get_var("SHOW TABLES LIKE '{$table}'")) {
        $schema = file_get_contents(CR_BASE_PATH . '/install/schema.sql');
        $schema = str_replace('{prefix}', $db->prefix, $schema);
        $schema = preg_replace('/^--.*$/m', '', $schema);
        foreach (array_filter(array_map('trim', explode(';', $schema)), fn($s) => strlen($s) > 5 && stripos($s, 'templates') !== false && stripos($s, 'CREATE TABLE') !== false) as $sql) {
            if (stripos($sql, $table) !== false) $db->query($sql);
        }
    }
}

function cr_save_block_template(array $data): int|false {
    $db = cr_db();
    $table = $db->prefix . 'templates';

    $name = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($data['name'] ?? '')));
    if (empty($name)) return false;

    $blocks = $data['blocks'] ?? [];
    if (is_array($blocks)) $blocks = json_encode($blocks, JSON_UNESCAPED_UNICODE);

    $row = [
        'name'        => $name,
        'label'       => trim($data['label'] ?? ucfirst(str_replace('-', ' ', $name))),
        'description' => trim($data['description'] ?? ''),
        'blocks'      => $blocks,
        'css'         => $data['css'] ?? '',
        'status'      => $data['status'] ?? 'active',
    ];

    $existing = $db->get_var($db->prepare("SELECT id FROM `{$table}` WHERE name = %s", $name));
    if ($existing) {
        $db->update($table, $row, ['id' => (int) $existing]);
        return (int) $existing;
    }

    $row['created_at'] = gmdate('Y-m-d H:i:s');
    return $db->insert($table, $row);
}

function cr_get_block_template(string $name): ?object {
    $db = cr_db();
    return $db->get_row($db->prepare("SELECT * FROM `{$db->prefix}templates` WHERE name = %s AND status = 'active'", $name));
}

function cr_get_all_block_templates(): array {
    $db = cr_db();
    $table = $db->prefix . 'templates';
    if (!$db->get_var("SHOW TABLES LIKE '{$table}'")) return [];
    return $db->get_results("SELECT * FROM `{$table}` ORDER BY name ASC");
}

function cr_delete_block_template(string $name): bool {
    $db = cr_db();
    return $db->delete($db->prefix . 'templates', ['name' => $name]) > 0;
}

// =============================================
// Rendering Engine
// =============================================

function cr_render_block_template(string $template_name): string {
    $template = cr_get_block_template($template_name);
    if (!$template) return '';

    $blocks = json_decode($template->blocks, true);
    if (!is_array($blocks)) return '';

    // Ensure the main query post is set (equivalent of the_post() in PHP templates)
    global $cr_query, $cr_post;
    if ($cr_query && $cr_query->post_count > 0 && $cr_post === null) {
        $cr_query->the_post();
    }

    $context = cr_build_template_context();
    $html = cr_render_blocks($blocks, $context);

    // Inject template CSS
    $css = $template->css ?? '';
    if ($css) {
        $html = '<style>' . strip_tags($css) . '</style>' . "\n" . $html;
    }

    return $html;
}

function cr_render_blocks(array $blocks, array $context): string {
    $html = '';
    foreach ($blocks as $block) {
        if (!is_array($block)) continue;
        $html .= cr_render_single_block($block, $context);
    }
    return $html;
}

function cr_render_single_block(array $block, array $context): string {
    $type = $block['type'] ?? '';
    $config = $block['config'] ?? [];
    $children = $block['children'] ?? [];

    // Merge block-level config shortcuts
    foreach ($block as $k => $v) {
        if (!in_array($k, ['type', 'config', 'children'])) {
            $config[$k] = $v;
        }
    }

    // Check conditional visibility
    if (isset($config['condition'])) {
        if (!cr_evaluate_block_condition($config['condition'], $context)) {
            return '';
        }
    }

    $bt = cr_get_block_type($type);

    if ($bt && is_callable($bt['render'])) {
        // Render children first if block supports them
        $children_html = '';
        if (!empty($children) && $bt['supports_children']) {
            $children_html = cr_render_blocks($children, $context);
        }
        $config['_children_html'] = $children_html;
        $config['_context'] = $context;

        return call_user_func($bt['render'], $config, $context);
    }

    // Unknown block type — render children if any
    if (!empty($children)) {
        return cr_render_blocks($children, $context);
    }

    return "<!-- unknown block: {$type} -->";
}

function cr_build_template_context(): array {
    global $cr_query, $cr_post;

    return [
        'query'     => $cr_query,
        'post'      => $cr_post,
        'site_name' => get_option('blogname', ''),
        'site_desc' => get_option('blogdescription', ''),
        'site_url'  => CR_HOME_URL,
        'theme_url' => cr_get_theme_url(),
        'year'      => date('Y'),
        'is_home'   => $cr_query ? $cr_query->is_home : false,
        'is_single' => $cr_query ? $cr_query->is_single : false,
        'is_page'   => $cr_query ? $cr_query->is_page : false,
        'is_archive' => $cr_query ? $cr_query->is_archive : false,
        'is_search' => $cr_query ? $cr_query->is_search : false,
        'is_404'    => $cr_query ? $cr_query->is_404 : false,
    ];
}

function cr_interpolate_vars(string $text, array $context): string {
    $vars = [
        '{{site_name}}'  => esc_html($context['site_name'] ?? ''),
        '{{site_desc}}'  => esc_html($context['site_desc'] ?? ''),
        '{{site_url}}'   => esc_url($context['site_url'] ?? ''),
        '{{theme_url}}'  => esc_url($context['theme_url'] ?? ''),
        '{{year}}'       => $context['year'] ?? date('Y'),
    ];

    if ($context['post'] ?? null) {
        $p = $context['post'];
        $vars['{{post_title}}'] = esc_html($p->post_title ?? '');
        $vars['{{post_date}}']  = date(get_option('date_format', 'F j, Y'), strtotime($p->post_date ?? 'now'));
        $vars['{{post_author}}'] = esc_html(get_the_author($p));
        $vars['{{post_url}}']   = esc_url(get_the_permalink($p));
    }

    return str_replace(array_keys($vars), array_values($vars), $text);
}

function cr_evaluate_block_condition(string $condition, array $context): bool {
    return match ($condition) {
        'is_home'    => $context['is_home'] ?? false,
        'is_single'  => $context['is_single'] ?? false,
        'is_page'    => $context['is_page'] ?? false,
        'is_archive' => $context['is_archive'] ?? false,
        'is_search'  => $context['is_search'] ?? false,
        'is_404'     => $context['is_404'] ?? false,
        'logged_in'  => is_user_logged_in(),
        default      => true,
    };
}

// =============================================
// Register All Block Types
// =============================================

function cr_register_core_block_types(): void {

    // -- SITE BLOCKS --

    cr_register_block_type('site-header', [
        'label' => 'Site Header', 'category' => 'site',
        'config_schema' => ['show_nav' => true, 'show_search' => false, 'class' => ''],
        'render' => function (array $c, array $ctx): string {
            $class = $c['class'] ?? 'site-header';
            $name = cr_interpolate_vars($c['logo_text'] ?? '{{site_name}}', $ctx);
            $desc = esc_html($ctx['site_desc'] ?? '');
            $url = esc_url($ctx['site_url']);

            $nav = '';
            if ($c['show_nav'] ?? true) {
                $nav = '<nav class="main-nav"><ul><li><a href="' . $url . '">Home</a></li></ul></nav>';
            }

            return '<header class="' . esc_attr($class) . '"><div class="container"><div class="site-branding"><div class="site-title"><a href="' . $url . '">' . $name . '</a></div><div class="site-description">' . $desc . '</div></div>' . $nav . '</div></header>';
        },
    ]);

    cr_register_block_type('site-footer', [
        'label' => 'Site Footer', 'category' => 'site',
        'config_schema' => ['copyright' => '© {{year}} {{site_name}}', 'class' => ''],
        'render' => function (array $c, array $ctx): string {
            $text = cr_interpolate_vars($c['copyright'] ?? '© {{year}} {{site_name}}. Powered by Clean Room CMS.', $ctx);
            $class = $c['class'] ?? 'site-footer';
            return '<footer class="' . esc_attr($class) . '"><div class="container"><p>' . $text . '</p></div></footer>';
        },
    ]);

    cr_register_block_type('site-nav', [
        'label' => 'Navigation', 'category' => 'site',
        'render' => function (array $c, array $ctx): string {
            $url = esc_url($ctx['site_url']);
            return '<nav class="main-nav"><ul><li><a href="' . $url . '">Home</a></li></ul></nav>';
        },
    ]);

    // -- CONTENT BLOCKS --

    cr_register_block_type('post-title', [
        'label' => 'Post Title', 'category' => 'content',
        'config_schema' => ['tag' => 'h1', 'link' => false, 'class' => 'entry-title'],
        'render' => function (array $c, array $ctx): string {
            $tag = $c['tag'] ?? 'h1';
            $class = $c['class'] ?? 'entry-title';
            $title = esc_html(get_the_title($ctx['post'] ?? null));
            $cls = $class ? ' class="' . esc_attr($class) . '"' : '';

            if ($c['link'] ?? false) {
                $url = esc_url(get_the_permalink($ctx['post'] ?? null));
                return "<{$tag}{$cls}><a href=\"{$url}\">{$title}</a></{$tag}>";
            }
            return "<{$tag}{$cls}>{$title}</{$tag}>";
        },
    ]);

    cr_register_block_type('post-content', [
        'label' => 'Post Content', 'category' => 'content',
        'render' => function (array $c, array $ctx): string {
            $post = $ctx['post'] ?? null;
            if (!$post) return '';
            $content = apply_filters('the_content', $post->post_content ?? '');
            $class = $c['class'] ?? 'entry-content';
            return '<div class="' . esc_attr($class) . '">' . $content . '</div>';
        },
    ]);

    cr_register_block_type('post-excerpt', [
        'label' => 'Post Excerpt', 'category' => 'content',
        'render' => function (array $c, array $ctx): string {
            return '<div class="entry-excerpt">' . apply_filters('the_excerpt', get_the_excerpt($ctx['post'] ?? null)) . '</div>';
        },
    ]);

    cr_register_block_type('post-meta', [
        'label' => 'Post Meta', 'category' => 'content',
        'config_schema' => ['show_date' => true, 'show_author' => true, 'show_categories' => true, 'date_format' => ''],
        'render' => function (array $c, array $ctx): string {
            $post = $ctx['post'] ?? null;
            if (!$post) return '';
            $parts = [];
            if ($c['show_date'] ?? true) {
                $fmt = $c['date_format'] ?: get_option('date_format', 'F j, Y');
                $parts[] = '<span class="posted-on">' . date($fmt, strtotime($post->post_date)) . '</span>';
            }
            if ($c['show_author'] ?? true) {
                $parts[] = '<span class="posted-by">' . esc_html(get_the_author($post)) . '</span>';
            }
            if ($c['show_categories'] ?? true) {
                $cats = get_the_terms((int) $post->ID, 'category');
                if ($cats) {
                    $links = array_map(fn($cat) => '<a href="' . esc_url(CR_HOME_URL . '/category/' . $cat->slug . '/') . '">' . esc_html($cat->name) . '</a>', $cats);
                    $parts[] = '<span class="posted-in">' . implode(', ', $links) . '</span>';
                }
            }
            return '<div class="entry-meta">' . implode(' ', $parts) . '</div>';
        },
    ]);

    cr_register_block_type('post-tags', [
        'label' => 'Post Tags', 'category' => 'content',
        'render' => function (array $c, array $ctx): string {
            $post = $ctx['post'] ?? null;
            if (!$post) return '';
            $tags = get_the_terms((int) $post->ID, 'post_tag');
            if (!$tags) return '';
            $links = array_map(fn($t) => '<a href="' . esc_url(CR_HOME_URL . '/tag/' . $t->slug . '/') . '">' . esc_html($t->name) . '</a>', $tags);
            return '<div class="entry-tags">' . implode(', ', $links) . '</div>';
        },
    ]);

    cr_register_block_type('post-thumbnail', [
        'label' => 'Post Thumbnail', 'category' => 'content',
        'render' => function (array $c, array $ctx): string {
            $post = $ctx['post'] ?? null;
            if (!$post) return '';
            $url = get_post_meta((int) $post->ID, '_thumbnail_url', true);
            if (!$url) return '';
            return '<div class="post-thumbnail"><img src="' . esc_url($url) . '" alt="' . esc_attr($post->post_title) . '"></div>';
        },
    ]);

    cr_register_block_type('post-navigation', [
        'label' => 'Post Navigation', 'category' => 'content',
        'render' => function (array $c, array $ctx): string {
            $prev = $c['prev_label'] ?? '&larr; Back';
            $url = esc_url($ctx['site_url']);
            return '<nav class="pagination"><a href="' . $url . '">' . $prev . '</a></nav>';
        },
    ]);

    // -- LOOP BLOCKS --

    cr_register_block_type('post-loop', [
        'label' => 'Post Loop', 'category' => 'loop',
        'supports_children' => true,
        'config_schema' => ['no_posts_text' => 'No posts found.'],
        'render' => function (array $c, array $ctx): string {
            global $cr_query, $cr_post;
            if (!$cr_query || !$cr_query->post_count) {
                return '<p>' . esc_html($c['no_posts_text'] ?? 'No posts found.') . '</p>';
            }

            $html = '';
            while ($cr_query->have_posts()) {
                $cr_query->the_post();
                $loop_ctx = array_merge($ctx, ['post' => $cr_post]);

                if (!empty($c['_children_html'])) {
                    // Re-render children with new post context
                    $children = $c['_children'] ?? [];
                    // Children are not directly available here, render card fallback
                }

                // Default card rendering
                $html .= '<article class="post" id="post-' . get_the_ID() . '">';
                $html .= cr_render_single_block(['type' => 'post-title', 'config' => ['tag' => 'h2', 'link' => true]], $loop_ctx);
                $html .= cr_render_single_block(['type' => 'post-meta'], $loop_ctx);
                $html .= cr_render_single_block(['type' => 'post-excerpt'], $loop_ctx);
                $html .= '<a href="' . esc_url(get_the_permalink()) . '" class="read-more">Read more &rarr;</a>';
                $html .= '</article>';
            }
            $cr_query->rewind_posts();
            return $html;
        },
    ]);

    cr_register_block_type('post-card', [
        'label' => 'Post Card', 'category' => 'loop',
        'render' => function (array $c, array $ctx): string {
            $post = $ctx['post'] ?? null;
            if (!$post) return '';
            return '<article class="post-card"><h3><a href="' . esc_url(get_the_permalink($post)) . '">' . esc_html($post->post_title) . '</a></h3><p>' . esc_html(get_the_excerpt($post)) . '</p></article>';
        },
    ]);

    cr_register_block_type('pagination', [
        'label' => 'Pagination', 'category' => 'loop',
        'render' => function (array $c, array $ctx): string {
            global $cr_query;
            if (!$cr_query || $cr_query->max_num_pages <= 1) return '';
            $paged = $cr_query->query_vars['paged'] ?? 1;
            $html = '<nav class="pagination">';
            if ($paged > 1) $html .= '<a href="' . esc_url(CR_HOME_URL . '/page/' . ($paged - 1) . '/') . '">&larr; Newer</a>';
            $html .= ' <span>Page ' . $paged . ' of ' . $cr_query->max_num_pages . '</span> ';
            if ($paged < $cr_query->max_num_pages) $html .= '<a href="' . esc_url(CR_HOME_URL . '/page/' . ($paged + 1) . '/') . '">Older &rarr;</a>';
            $html .= '</nav>';
            return $html;
        },
    ]);

    // -- LAYOUT BLOCKS --

    cr_register_block_type('container', [
        'label' => 'Container', 'category' => 'layout',
        'supports_children' => true,
        'config_schema' => ['max_width' => '960px', 'class' => 'container'],
        'render' => function (array $c, array $ctx): string {
            $class = $c['class'] ?? 'container';
            $style = ($c['max_width'] ?? '') ? ' style="max-width:' . esc_attr($c['max_width']) . ';margin:0 auto;padding:0 20px"' : '';
            return '<div class="' . esc_attr($class) . '"' . $style . '>' . ($c['_children_html'] ?? '') . '</div>';
        },
    ]);

    cr_register_block_type('columns', [
        'label' => 'Columns', 'category' => 'layout',
        'supports_children' => true,
        'config_schema' => ['count' => 2, 'gap' => '20px'],
        'render' => function (array $c, array $ctx): string {
            $gap = $c['gap'] ?? '20px';
            return '<div style="display:flex;gap:' . esc_attr($gap) . '">' . ($c['_children_html'] ?? '') . '</div>';
        },
    ]);

    cr_register_block_type('column', [
        'label' => 'Column', 'category' => 'layout',
        'supports_children' => true,
        'render' => function (array $c, array $ctx): string {
            $flex = $c['flex'] ?? '1';
            return '<div style="flex:' . esc_attr($flex) . '">' . ($c['_children_html'] ?? '') . '</div>';
        },
    ]);

    cr_register_block_type('section', [
        'label' => 'Section', 'category' => 'layout',
        'supports_children' => true,
        'config_schema' => ['background' => '', 'padding' => '40px 0', 'class' => 'content-area'],
        'render' => function (array $c, array $ctx): string {
            $class = $c['class'] ?? 'content-area';
            $style = '';
            if ($c['background'] ?? '') $style .= 'background:' . esc_attr($c['background']) . ';';
            if ($c['padding'] ?? '') $style .= 'padding:' . esc_attr($c['padding']) . ';';
            $style_attr = $style ? ' style="' . $style . '"' : '';
            return '<section class="' . esc_attr($class) . '"' . $style_attr . '>' . ($c['_children_html'] ?? '') . '</section>';
        },
    ]);

    cr_register_block_type('spacer', [
        'label' => 'Spacer', 'category' => 'layout',
        'config_schema' => ['height' => '40px'],
        'render' => function (array $c, array $ctx): string {
            return '<div style="height:' . esc_attr($c['height'] ?? '40px') . '"></div>';
        },
    ]);

    // -- DYNAMIC BLOCKS --

    cr_register_block_type('search-form', [
        'label' => 'Search Form', 'category' => 'dynamic',
        'render' => function (array $c, array $ctx): string {
            return '<form class="search-form" action="' . esc_url($ctx['site_url']) . '/" method="get"><input type="search" name="s" placeholder="Search..."><button type="submit">Search</button></form>';
        },
    ]);

    cr_register_block_type('breadcrumb', [
        'label' => 'Breadcrumb', 'category' => 'dynamic',
        'render' => function (array $c, array $ctx): string {
            $parts = ['<a href="' . esc_url($ctx['site_url']) . '">Home</a>'];
            $post = $ctx['post'] ?? null;
            if ($post) $parts[] = esc_html($post->post_title);
            return '<nav class="breadcrumb">' . implode(' &rsaquo; ', $parts) . '</nav>';
        },
    ]);

    cr_register_block_type('recent-posts', [
        'label' => 'Recent Posts', 'category' => 'dynamic',
        'config_schema' => ['count' => 5, 'title' => 'Recent Posts'],
        'render' => function (array $c, array $ctx): string {
            $count = (int) ($c['count'] ?? 5);
            $title = $c['title'] ?? 'Recent Posts';
            $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $count]);
            $html = '<div class="widget"><h3 class="widget-title">' . esc_html($title) . '</h3><ul>';
            foreach ($posts as $p) {
                $html .= '<li><a href="' . esc_url(get_permalink($p)) . '">' . esc_html($p->post_title) . '</a></li>';
            }
            $html .= '</ul></div>';
            return $html;
        },
    ]);

    cr_register_block_type('taxonomy-list', [
        'label' => 'Taxonomy List', 'category' => 'dynamic',
        'config_schema' => ['taxonomy' => 'category', 'title' => ''],
        'render' => function (array $c, array $ctx): string {
            $tax = $c['taxonomy'] ?? 'category';
            $title = $c['title'] ?? ucfirst($tax);
            $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => true]);
            $html = '<div class="widget"><h3 class="widget-title">' . esc_html($title) . '</h3><ul>';
            foreach ($terms as $t) {
                $html .= '<li><a href="' . esc_url(CR_HOME_URL . '/' . $tax . '/' . $t->slug . '/') . '">' . esc_html($t->name) . ' (' . $t->count . ')</a></li>';
            }
            $html .= '</ul></div>';
            return $html;
        },
    ]);

    cr_register_block_type('custom-html', [
        'label' => 'Custom HTML', 'category' => 'dynamic',
        'config_schema' => ['html' => ''],
        'render' => function (array $c, array $ctx): string {
            return cr_interpolate_vars($c['html'] ?? '', $ctx);
        },
    ]);

    // -- UTILITY BLOCKS --

    cr_register_block_type('conditional', [
        'label' => 'Conditional', 'category' => 'utility',
        'supports_children' => true,
        'config_schema' => ['condition' => 'is_home'],
        'render' => function (array $c, array $ctx): string {
            $cond = $c['condition'] ?? '';
            if (!cr_evaluate_block_condition($cond, $ctx)) return '';
            return $c['_children_html'] ?? '';
        },
    ]);

    cr_register_block_type('html-wrapper', [
        'label' => 'HTML Document', 'category' => 'utility',
        'supports_children' => true,
        'render' => function (array $c, array $ctx): string {
            $lang = get_option('cr_locale', 'en-US');
            $title = esc_html($ctx['site_name']);
            if ($ctx['post'] ?? null) {
                $title = esc_html($ctx['post']->post_title) . ' — ' . $title;
            }
            $theme_css = esc_url(cr_get_theme_url() . '/style.css');

            $head = '<!DOCTYPE html><html lang="' . esc_attr($lang) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . $title . '</title><link rel="stylesheet" href="' . $theme_css . '">';

            // Fire cr_head for enqueued assets
            ob_start();
            cr_head();
            $head .= ob_get_clean();
            $head .= '</head><body>';

            $foot = '';
            ob_start();
            cr_footer();
            $foot .= ob_get_clean();
            $foot .= '</body></html>';

            return $head . ($c['_children_html'] ?? '') . $foot;
        },
    ]);
}

// =============================================
// Theme Import/Export
// =============================================

function cr_export_theme_json(): array {
    $templates = cr_get_all_block_templates();
    $export = [
        'name'        => get_option('blogname', 'My Theme'),
        'version'     => '1.0.0',
        'description' => 'Exported from Clean Room CMS',
        'templates'   => [],
        'css'         => '',
    ];

    foreach ($templates as $t) {
        $export['templates'][$t->name] = [
            'label'       => $t->label,
            'description' => $t->description ?? '',
            'blocks'      => json_decode($t->blocks, true),
            'css'         => $t->css ?? '',
        ];
    }

    return $export;
}

function cr_import_theme_json(array $data): int {
    $imported = 0;
    $templates = $data['templates'] ?? [];

    foreach ($templates as $name => $tpl) {
        $result = cr_save_block_template([
            'name'        => $name,
            'label'       => $tpl['label'] ?? ucfirst($name),
            'description' => $tpl['description'] ?? '',
            'blocks'      => $tpl['blocks'] ?? [],
            'css'         => $tpl['css'] ?? '',
        ]);
        if ($result) $imported++;
    }

    return $imported;
}
