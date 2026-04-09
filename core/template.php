<?php
/**
 * Clean Room CMS - Template Hierarchy Engine
 *
 * Determines which template file to load based on the current query.
 * Implements the full WordPress template hierarchy from public documentation.
 */

/**
 * Resolve the template file to load based on the current query state.
 */
function cr_resolve_template(): string {
    global $cr_query;

    $templates = [];

    if ($cr_query->is_404) {
        $templates[] = '404.php';
    } elseif ($cr_query->is_search) {
        $templates[] = 'search.php';
    } elseif ($cr_query->is_front_page) {
        $templates[] = 'front-page.php';
        $show = get_option('show_on_front', 'posts');
        if ($show === 'page') {
            $templates[] = 'page.php';
        } else {
            $templates[] = 'home.php';
        }
    } elseif ($cr_query->is_home) {
        $templates[] = 'home.php';
    } elseif ($cr_query->is_attachment) {
        if ($cr_query->post) {
            $mime = $cr_query->post->post_mime_type;
            $type = explode('/', $mime)[0] ?? '';
            if ($type) $templates[] = "{$type}.php";
        }
        $templates[] = 'attachment.php';
        $templates[] = 'single-attachment.php';
        $templates[] = 'single.php';
    } elseif ($cr_query->is_single) {
        $post = $cr_query->post;
        if ($post) {
            $post_type = $post->post_type;
            $templates[] = "single-{$post_type}-{$post->post_name}.php";
            $templates[] = "single-{$post_type}.php";
        }
        $templates[] = 'single.php';
        $templates[] = 'singular.php';
    } elseif ($cr_query->is_page) {
        $post = $cr_query->post;
        if ($post) {
            // Check for custom page template
            $custom_template = get_post_meta((int) $post->ID, '_cr_page_template', true);
            if ($custom_template && $custom_template !== 'default') {
                $templates[] = $custom_template;
            }
            $templates[] = "page-{$post->post_name}.php";
            $templates[] = "page-{$post->ID}.php";
        }
        $templates[] = 'page.php';
        $templates[] = 'singular.php';
    } elseif ($cr_query->is_category) {
        if ($cr_query->queried_object) {
            $cat = $cr_query->queried_object;
            $templates[] = "category-{$cat->slug}.php";
            $templates[] = "category-{$cat->term_id}.php";
        }
        $templates[] = 'category.php';
        $templates[] = 'archive.php';
    } elseif ($cr_query->is_tag) {
        if ($cr_query->queried_object) {
            $tag = $cr_query->queried_object;
            $templates[] = "tag-{$tag->slug}.php";
            $templates[] = "tag-{$tag->term_id}.php";
        }
        $templates[] = 'tag.php';
        $templates[] = 'archive.php';
    } elseif ($cr_query->is_tax) {
        $templates[] = 'taxonomy.php';
        $templates[] = 'archive.php';
    } elseif ($cr_query->is_author) {
        if ($cr_query->queried_object) {
            $author = $cr_query->queried_object;
            $templates[] = "author-{$author->user_nicename}.php";
            $templates[] = "author-{$author->ID}.php";
        }
        $templates[] = 'author.php';
        $templates[] = 'archive.php';
    } elseif ($cr_query->is_date) {
        $templates[] = 'date.php';
        $templates[] = 'archive.php';
    } elseif ($cr_query->is_post_type_archive) {
        $post_type = $cr_query->query_vars['post_type'] ?? 'post';
        $templates[] = "archive-{$post_type}.php";
        $templates[] = 'archive.php';
    } elseif ($cr_query->is_archive) {
        $templates[] = 'archive.php';
    }

    // Ultimate fallback
    $templates[] = 'index.php';

    // Apply filter so themes/plugins can modify
    $templates = apply_filters('template_hierarchy', $templates);

    return cr_locate_template($templates);
}

/**
 * Find the first template file that exists in the active theme.
 */
function cr_locate_template(array $template_names): string {
    $theme_dir = cr_get_theme_directory();

    foreach ($template_names as $template) {
        // Prevent path traversal - only allow filename characters
        $template = basename($template);
        if (!preg_match('/^[a-zA-Z0-9_-]+\.php$/', $template)) {
            continue;
        }

        $path = $theme_dir . '/' . $template;
        // Verify resolved path is within theme directory
        $real = realpath($path);
        if ($real && str_starts_with($real, realpath($theme_dir)) && file_exists($path)) {
            return $path;
        }
    }

    // Absolute fallback
    return $theme_dir . '/index.php';
}

/**
 * Get the active theme's directory path.
 */
function cr_get_theme_directory(): string {
    $theme = get_option('stylesheet', 'default');
    return CR_THEME_PATH . '/' . $theme;
}

/**
 * Get the active theme's URL.
 */
function cr_get_theme_url(): string {
    $theme = get_option('stylesheet', 'default');
    return CR_THEME_URL . '/' . $theme;
}

/**
 * Load the resolved template.
 */
function cr_load_template(string $template_path): void {
    global $cr_query, $cr_post;

    // Check for block-based template override FIRST (following hierarchy)
    if (function_exists('cr_get_block_template')) {
        global $cr_query, $cr_post;
        $template_name = basename($template_path, '.php');

        // Build hierarchy of block template names to check (most specific first)
        $block_candidates = [];

        if ($cr_query && $cr_post) {
            $post_type = $cr_post->post_type ?? 'post';
            $post_slug = $cr_post->post_name ?? '';

            if ($cr_query->is_page && $post_slug) {
                $block_candidates[] = 'page-' . $post_slug;    // page-about
            }
            if ($cr_query->is_single || $cr_query->is_page) {
                $block_candidates[] = 'single-' . $post_type;  // single-product
            }
        }
        if ($cr_query && $cr_query->is_post_type_archive) {
            $pt = $cr_query->query_vars['post_type'] ?? 'post';
            $block_candidates[] = 'archive-' . $pt;             // archive-product
        }
        $block_candidates[] = $template_name;                    // single, page, archive, index

        foreach ($block_candidates as $candidate) {
            $block_tpl = cr_get_block_template($candidate);
            if ($block_tpl && $block_tpl->status === 'active') {
                do_action('template_redirect');
                echo cr_render_block_template($candidate);
                return;
            }
        }
    }

    // Fall back to PHP file template
    $template_path = apply_filters('template_include', $template_path);

    if (file_exists($template_path)) {
        do_action('template_redirect');
        include $template_path;
    } else {
        http_response_code(500);
        echo 'Template not found: ' . esc_html(basename($template_path));
    }
}

// -- Template part loaders --

function get_header(string $name = ''): void {
    do_action('get_header', $name);

    $templates = [];
    if ($name) $templates[] = "header-{$name}.php";
    $templates[] = 'header.php';

    $file = cr_locate_template($templates);
    if (file_exists($file)) {
        include $file;
    }
}

function get_footer(string $name = ''): void {
    do_action('get_footer', $name);

    $templates = [];
    if ($name) $templates[] = "footer-{$name}.php";
    $templates[] = 'footer.php';

    $file = cr_locate_template($templates);
    if (file_exists($file)) {
        include $file;
    }
}

function get_sidebar(string $name = ''): void {
    do_action('get_sidebar', $name);

    $templates = [];
    if ($name) $templates[] = "sidebar-{$name}.php";
    $templates[] = 'sidebar.php';

    $file = cr_locate_template($templates);
    if (file_exists($file)) {
        include $file;
    }
}

function get_template_part(string $slug, string $name = '', array $args = []): void {
    do_action("get_template_part_{$slug}", $slug, $name, $args);

    $templates = [];
    if ($name) $templates[] = "{$slug}-{$name}.php";
    $templates[] = "{$slug}.php";

    $file = cr_locate_template($templates);
    if (file_exists($file)) {
        // Make $args available to the template
        extract($args, EXTR_SKIP);
        include $file;
    }
}

// -- Asset enqueueing --

$cr_enqueued_styles = [];
$cr_enqueued_scripts = [];

function cr_enqueue_style(string $handle, string $src = '', array $deps = [], string $ver = '', string $media = 'all'): void {
    global $cr_enqueued_styles;
    $cr_enqueued_styles[$handle] = [
        'src'   => $src,
        'deps'  => $deps,
        'ver'   => $ver,
        'media' => $media,
    ];
}

function cr_enqueue_script(string $handle, string $src = '', array $deps = [], string $ver = '', bool $in_footer = false): void {
    global $cr_enqueued_scripts;
    $cr_enqueued_scripts[$handle] = [
        'src'       => $src,
        'deps'      => $deps,
        'ver'       => $ver,
        'in_footer' => $in_footer,
    ];
}

function cr_head(): void {
    global $cr_enqueued_styles, $cr_enqueued_scripts;

    // Output styles
    foreach ($cr_enqueued_styles as $handle => $style) {
        if ($style['src']) {
            $ver = $style['ver'] ? "?ver={$style['ver']}" : '';
            echo '<link rel="stylesheet" id="' . esc_attr($handle) . '-css" href="' . esc_url($style['src']) . $ver . '" media="' . esc_attr($style['media']) . '">' . "\n";
        }
    }

    // Output head scripts
    foreach ($cr_enqueued_scripts as $handle => $script) {
        if ($script['src'] && !$script['in_footer']) {
            $ver = $script['ver'] ? "?ver={$script['ver']}" : '';
            echo '<script id="' . esc_attr($handle) . '-js" src="' . esc_url($script['src']) . $ver . '"></script>' . "\n";
        }
    }

    do_action('cr_head');
}

function cr_footer(): void {
    global $cr_enqueued_scripts;

    // Output footer scripts
    foreach ($cr_enqueued_scripts as $handle => $script) {
        if ($script['src'] && $script['in_footer']) {
            $ver = $script['ver'] ? "?ver={$script['ver']}" : '';
            echo '<script id="' . esc_attr($handle) . '-js" src="' . esc_url($script['src']) . $ver . '"></script>' . "\n";
        }
    }

    do_action('cr_footer');
}

// -- Theme support --

$cr_theme_support = [];

function add_theme_support(string $feature, mixed ...$args): void {
    global $cr_theme_support;
    $cr_theme_support[$feature] = empty($args) ? true : $args;
}

function current_theme_supports(string $feature): bool {
    global $cr_theme_support;
    return isset($cr_theme_support[$feature]);
}

// -- Bloginfo --

function bloginfo(string $show = 'name'): void {
    echo get_bloginfo($show);
}

function get_bloginfo(string $show = 'name'): string {
    return match ($show) {
        'name'        => get_option('blogname', 'Clean Room CMS'),
        'description' => get_option('blogdescription', 'Just another Clean Room site'),
        'url', 'home' => CR_HOME_URL,
        'siteurl', 'wpurl' => CR_SITE_URL,
        'admin_email' => get_option('admin_email', ''),
        'charset'     => 'UTF-8',
        'language'    => get_option('cr_locale', 'en-US'),
        'version'     => '1.0.0',
        'stylesheet_url' => cr_get_theme_url() . '/style.css',
        'stylesheet_directory' => cr_get_theme_url(),
        'template_directory' => cr_get_theme_url(),
        'template_url' => cr_get_theme_url(),
        default       => '',
    };
}

function language_attributes(): void {
    $lang = get_option('cr_locale', 'en-US');
    echo 'lang="' . esc_attr($lang) . '"';
}

function body_class(string|array $extra = ''): void {
    $classes = [];

    if (is_home()) $classes[] = 'home';
    if (is_front_page()) $classes[] = 'front-page';
    if (is_single()) $classes[] = 'single';
    if (is_page()) $classes[] = 'page';
    if (is_archive()) $classes[] = 'archive';
    if (is_search()) $classes[] = 'search';
    if (is_404()) $classes[] = 'error404';
    if (is_user_logged_in()) $classes[] = 'logged-in';
    if (is_admin()) $classes[] = 'admin-bar';

    if (is_string($extra) && !empty($extra)) {
        $classes[] = $extra;
    } elseif (is_array($extra)) {
        $classes = array_merge($classes, $extra);
    }

    $classes = apply_filters('body_class', $classes);

    echo 'class="' . esc_attr(implode(' ', $classes)) . '"';
}
