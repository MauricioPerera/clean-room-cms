# Theme Development Guide

Complete reference for building themes for Clean Room CMS.

---

## Quick Start

A theme is a folder inside `content/themes/` with at minimum two files:

```
content/themes/my-theme/
  style.css           Required — theme metadata
  index.php           Required — fallback template
```

Activate from `/admin/?page=themes`.

### Minimal Theme

**style.css**
```css
/*
Theme Name: My Theme
Description: A custom theme
Version: 1.0.0
Author: Your Name
*/

body { font-family: sans-serif; }
```

**index.php**
```php
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <title><?php bloginfo('name'); ?></title>
    <?php cr_head(); ?>
</head>
<body <?php body_class(); ?>>

<?php while (have_posts()): the_post(); ?>
    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
    <p><?php the_excerpt(); ?></p>
<?php endwhile; ?>

<?php cr_footer(); ?>
</body>
</html>
```

---

## Theme Structure

A complete theme uses these files:

```
my-theme/
  style.css              Theme metadata + styles
  functions.php          Theme setup, hooks, asset loading
  index.php              Ultimate fallback template
  header.php             Site header (loaded by get_header())
  footer.php             Site footer (loaded by get_footer())
  sidebar.php            Sidebar (loaded by get_sidebar())

  single.php             Single post
  page.php               Single page
  archive.php            Archive (category, tag, author, date)
  search.php             Search results
  404.php                Not found

  single-{type}.php      Single custom post type (e.g., single-product.php)
  page-{slug}.php        Specific page by slug (e.g., page-about.php)
  category-{slug}.php    Specific category (e.g., category-news.php)
  tag-{slug}.php         Specific tag
  author-{nicename}.php  Specific author
  archive-{type}.php     Custom type archive (e.g., archive-product.php)

  front-page.php         Static front page
  home.php               Blog home page

  header-{name}.php      Named header variant
  footer-{name}.php      Named footer variant
  sidebar-{name}.php     Named sidebar variant

  parts/                 Reusable template parts
    card.php             Loaded via get_template_part('parts/card')
    card-featured.php    Loaded via get_template_part('parts/card', 'featured')
```

---

## style.css Header

```css
/*
Theme Name: My Theme
Theme URI: https://example.com/my-theme
Description: A minimal, responsive theme
Version: 1.0.0
Author: Your Name
Author URI: https://example.com
License: MIT
Text Domain: my-theme
*/
```

Only `Theme Name` is required for recognition. The admin panel reads all fields.

---

## functions.php

Loaded automatically before templates. Use it for setup, hooks, and asset loading.

```php
<?php
// Theme setup
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'gallery']);
});

// Enqueue styles and scripts
add_action('cr_head', function () {
    cr_enqueue_style('theme-main', cr_get_theme_url() . '/style.css', [], '1.0');
    cr_enqueue_style('theme-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;700');
});

add_action('cr_footer', function () {
    // Scripts loaded before </body>
});

cr_enqueue_script('theme-app', cr_get_theme_url() . '/js/app.js', [], '1.0', true);

// Custom filters
add_filter('excerpt_length', fn() => 30);
add_filter('excerpt_more', fn() => '...');

// Auto-paragraph content
add_filter('the_content', function (string $content): string {
    if (preg_match('/<(div|p|h[1-6])/i', $content)) return $content;
    $paragraphs = array_filter(array_map('trim', preg_split('/\n\s*\n/', $content)));
    return implode("\n", array_map(fn($p) => "<p>{$p}</p>", $paragraphs));
}, 10);

// Register custom shortcode
add_shortcode('button', function ($atts, $content) {
    $atts = shortcode_atts(['url' => '#', 'color' => 'blue'], $atts, 'button');
    return '<a href="' . esc_url($atts['url']) . '" class="btn btn-' . esc_attr($atts['color']) . '">' . esc_html($content) . '</a>';
});

// Register custom post type from theme
register_post_type('portfolio', [
    'label' => 'Portfolio', 'public' => true, 'supports' => ['title', 'editor', 'thumbnail'],
]);
```

---

## Template Hierarchy

When a visitor requests a page, the engine walks a cascade of template files and loads the first one that exists:

| Request | Template Cascade |
|---|---|
| **Single post** | `single-{type}-{slug}.php` → `single-{type}.php` → `single.php` → `singular.php` → `index.php` |
| **Page** | `{custom-template}.php` → `page-{slug}.php` → `page-{id}.php` → `page.php` → `singular.php` → `index.php` |
| **Category** | `category-{slug}.php` → `category-{id}.php` → `category.php` → `archive.php` → `index.php` |
| **Tag** | `tag-{slug}.php` → `tag-{id}.php` → `tag.php` → `archive.php` → `index.php` |
| **Author** | `author-{nicename}.php` → `author-{id}.php` → `author.php` → `archive.php` → `index.php` |
| **Date** | `date.php` → `archive.php` → `index.php` |
| **Post Type Archive** | `archive-{type}.php` → `archive.php` → `index.php` |
| **Search** | `search.php` → `index.php` |
| **404** | `404.php` → `index.php` |
| **Front Page** | `front-page.php` → `home.php` or `page.php` → `index.php` |
| **Home (blog)** | `home.php` → `index.php` |

Modify with the `template_hierarchy` filter:

```php
add_filter('template_hierarchy', function (array $templates): array {
    // Add a custom template to the top of the cascade
    array_unshift($templates, 'my-custom.php');
    return $templates;
});
```

---

## The Loop

The primary pattern for displaying content:

```php
<?php if (have_posts()): ?>
    <?php while (have_posts()): the_post(); ?>

        <article id="post-<?php the_ID(); ?>">
            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>

            <div class="meta">
                <span><?php echo get_the_date(); ?></span>
                <span><?php the_author(); ?></span>
            </div>

            <div class="content">
                <?php the_content(); ?>
            </div>

            <?php
            $tags = get_the_terms(get_the_ID(), 'post_tag');
            if ($tags):
            ?>
                <div class="tags">
                    <?php foreach ($tags as $tag): ?>
                        <a href="<?php echo esc_url(CR_HOME_URL . '/tag/' . $tag->slug . '/'); ?>">
                            <?php echo esc_html($tag->name); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

    <?php endwhile; ?>
<?php else: ?>
    <p>No posts found.</p>
<?php endif; ?>
```

### Custom Queries

```php
<?php
$featured = new CR_Query([
    'post_type'      => 'post',
    'posts_per_page' => 3,
    'meta_key'       => 'featured',
    'meta_value'     => '1',
    'orderby'        => 'date',
    'order'          => 'DESC',
]);

while ($featured->have_posts()):
    $featured->the_post();
    get_template_part('parts/card', 'featured');
endwhile;

// Reset to main query
$featured->rewind_posts();
?>
```

### Pagination

```php
<?php
global $cr_query;
if ($cr_query->max_num_pages > 1):
    $paged = $cr_query->query_vars['paged'] ?? 1;
?>
    <nav class="pagination">
        <?php if ($paged > 1): ?>
            <a href="<?php echo esc_url(CR_HOME_URL . '/page/' . ($paged - 1) . '/'); ?>">&larr; Newer</a>
        <?php endif; ?>
        <span>Page <?php echo $paged; ?> of <?php echo $cr_query->max_num_pages; ?></span>
        <?php if ($paged < $cr_query->max_num_pages): ?>
            <a href="<?php echo esc_url(CR_HOME_URL . '/page/' . ($paged + 1) . '/'); ?>">Older &rarr;</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
```

---

## Template Tags Reference

### Post Data

| Function | Returns | Echo |
|---|---|---|
| `get_the_ID()` | `int` Post ID | `the_ID()` |
| `get_the_title($post?)` | `string` Title | `the_title($before, $after, $echo)` |
| `get_the_content()` | `string` Content | `the_content()` |
| `get_the_excerpt($post?)` | `string` Excerpt | `the_excerpt()` |
| `get_the_permalink($post?)` | `string` URL | `the_permalink()` |
| `get_the_date($format, $post?)` | `string` Date | `the_date($format, $before, $after, $echo)` |
| `get_the_author($post?)` | `string` Author name | `the_author()` |
| `get_post($id)` | `?object` Post object | — |
| `get_post_meta($id, $key, $single)` | `mixed` Meta value | — |

### Site Info

| Function | Returns |
|---|---|
| `get_bloginfo('name')` | Site title |
| `get_bloginfo('description')` | Tagline |
| `get_bloginfo('url')` | Home URL |
| `get_bloginfo('charset')` | `UTF-8` |
| `get_bloginfo('version')` | CMS version |
| `get_bloginfo('stylesheet_url')` | Theme stylesheet URL |
| `get_bloginfo('template_directory')` | Theme directory URL |
| `cr_get_theme_url()` | Theme URL |
| `cr_get_theme_directory()` | Theme filesystem path |

### Conditional Tags

| Function | True When |
|---|---|
| `is_home()` | Blog posts listing |
| `is_front_page()` | Front page (posts or static) |
| `is_single()` | Single post |
| `is_page()` | Single page |
| `is_singular($types?)` | Any single content item |
| `is_archive()` | Any archive |
| `is_category()` | Category archive |
| `is_tag()` | Tag archive |
| `is_tax()` | Custom taxonomy archive |
| `is_author()` | Author archive |
| `is_date()` | Date archive |
| `is_year()` | Year archive |
| `is_month()` | Month archive |
| `is_day()` | Day archive |
| `is_search()` | Search results |
| `is_404()` | Not found |
| `is_post_type_archive()` | Post type archive |
| `is_user_logged_in()` | User logged in |

### Escaping

Always escape output:

```php
<?php echo esc_html($text); ?>          <!-- In HTML content -->
<?php echo esc_attr($value); ?>         <!-- In HTML attributes -->
<?php echo esc_url($url); ?>            <!-- In href/src attributes -->
```

---

## Taxonomies in Templates

```php
<?php
// Categories for current post
$categories = get_the_terms(get_the_ID(), 'category');
if ($categories):
    foreach ($categories as $cat):
?>
    <a href="<?php echo esc_url(CR_HOME_URL . '/category/' . $cat->slug . '/'); ?>">
        <?php echo esc_html($cat->name); ?>
    </a>
<?php
    endforeach;
endif;

// All tags (site-wide)
$all_tags = get_terms(['taxonomy' => 'post_tag', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 20]);
?>
```

---

## Assets

### Enqueue in functions.php

```php
// Stylesheets (output in <head> via cr_head())
cr_enqueue_style('handle', cr_get_theme_url() . '/css/main.css', [], '1.0');

// Scripts in <head>
cr_enqueue_script('handle', cr_get_theme_url() . '/js/nav.js', [], '1.0', false);

// Scripts before </body> (recommended)
cr_enqueue_script('handle', cr_get_theme_url() . '/js/app.js', [], '1.0', true);
```

### In Templates

```php
<head>
    <?php cr_head(); ?>   <!-- Outputs all enqueued styles + head scripts -->
</head>
<body>
    ...
    <?php cr_footer(); ?> <!-- Outputs all footer scripts -->
</body>
```

### Direct inclusion

```php
<link rel="stylesheet" href="<?php echo esc_url(cr_get_theme_url() . '/css/custom.css'); ?>">
<script src="<?php echo esc_url(cr_get_theme_url() . '/js/custom.js'); ?>"></script>
```

---

## Hooks for Themes

### Actions

| Hook | When | Use For |
|---|---|---|
| `after_setup_theme` | After functions.php loaded | `add_theme_support()`, register types |
| `cr_head` | Inside `<head>` | Custom meta tags, inline styles |
| `cr_footer` | Before `</body>` | Inline scripts, tracking codes |
| `get_header` | When `get_header()` called | Header-specific setup |
| `get_footer` | When `get_footer()` called | Footer-specific setup |
| `loop_start` | Entering The Loop | Before first post |
| `loop_end` | Exiting The Loop | After last post |
| `the_post` | Each post in loop | Per-post setup |
| `template_redirect` | Before template loaded | Redirects, access control |

### Filters

| Hook | Modifies | Default |
|---|---|---|
| `the_title` | Post title | Raw title |
| `the_content` | Post content | Raw content |
| `the_excerpt` | Post excerpt | Auto-generated |
| `excerpt_length` | Auto-excerpt word count | `55` |
| `excerpt_more` | Auto-excerpt ending | `' [&hellip;]'` |
| `body_class` | Body CSS classes | Auto-detected array |
| `post_link` | Post permalink | `/?p={id}` |
| `template_hierarchy` | Template file cascade | Default cascade |
| `template_include` | Final template path | Resolved path |

```php
// Customize excerpt
add_filter('excerpt_length', fn() => 25);
add_filter('excerpt_more', fn() => ' <a href="' . get_the_permalink() . '">Continue</a>');

// Add class to body
add_filter('body_class', function (array $classes): array {
    $classes[] = 'theme-my-theme';
    if (is_single()) $classes[] = 'has-sidebar';
    return $classes;
});
```

---

## Theme Support Features

Declare in `functions.php` via `add_theme_support()`:

```php
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'gallery', 'caption']);
    add_theme_support('custom-logo');
});
```

Check in templates:

```php
<?php if (current_theme_supports('post-thumbnails')): ?>
    <!-- Show featured image UI -->
<?php endif; ?>
```

---

## Complete Example: header.php

```php
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html(get_bloginfo('name')); ?><?php if (!is_front_page()) echo ' — ' . esc_html(get_the_title()); ?></title>
    <?php cr_head(); ?>
</head>
<body <?php body_class(); ?>>

<header class="site-header">
    <a href="<?php echo esc_url(CR_HOME_URL); ?>" class="site-title">
        <?php bloginfo('name'); ?>
    </a>
    <nav>
        <a href="<?php echo esc_url(CR_HOME_URL); ?>">Home</a>
        <a href="<?php echo esc_url(CR_HOME_URL); ?>/about/">About</a>
    </nav>
</header>

<main>
```

## Complete Example: footer.php

```php
</main>

<footer class="site-footer">
    <p>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?></p>
</footer>

<?php cr_footer(); ?>
</body>
</html>
```

## Complete Example: single.php

```php
<?php get_header(); ?>

<?php while (have_posts()): the_post(); ?>
    <article class="post-single">
        <h1><?php the_title(); ?></h1>

        <div class="post-meta">
            <time><?php echo get_the_date('F j, Y'); ?></time>
            by <?php the_author(); ?>
            in
            <?php
            $cats = get_the_terms(get_the_ID(), 'category');
            if ($cats) {
                echo implode(', ', array_map(fn($c) => '<a href="' . esc_url(CR_HOME_URL . '/category/' . $c->slug . '/') . '">' . esc_html($c->name) . '</a>', $cats));
            }
            ?>
        </div>

        <div class="post-content">
            <?php the_content(); ?>
        </div>

        <?php
        $tags = get_the_terms(get_the_ID(), 'post_tag');
        if ($tags):
        ?>
            <div class="post-tags">
                <?php foreach ($tags as $tag): ?>
                    <a href="<?php echo esc_url(CR_HOME_URL . '/tag/' . $tag->slug . '/'); ?>">#<?php echo esc_html($tag->name); ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
<?php endwhile; ?>

<?php get_footer(); ?>
```

---

## Query Parameters

For custom queries via `new CR_Query()`:

| Parameter | Type | Description |
|---|---|---|
| `post_type` | string\|array | `'post'`, `'page'`, `['post', 'product']` |
| `post_status` | string\|array | `'publish'`, `'draft'`, `'pending'` |
| `posts_per_page` | int | Results per page |
| `paged` | int | Page number |
| `orderby` | string | `date`, `title`, `name`, `modified`, `ID`, `menu_order`, `rand`, `comment_count` |
| `order` | string | `ASC`, `DESC` |
| `p` | int | Post by ID |
| `name` | string | Post by slug |
| `page_id` | int | Page by ID |
| `pagename` | string | Page by slug |
| `author` | int | Author ID |
| `author_name` | string | Author nicename |
| `cat` | int | Category term ID |
| `category_name` | string | Category slug |
| `tag` | string | Tag slug |
| `tag_id` | int | Tag term ID |
| `s` | string | Search keyword |
| `year` | int | Year filter |
| `monthnum` | int | Month filter |
| `day` | int | Day filter |
| `post_parent` | int | Parent post ID |
| `post__in` | array | Include specific IDs |
| `post__not_in` | array | Exclude specific IDs |
| `meta_key` | string | Filter by meta key |
| `meta_value` | string | Filter by meta value |
| `nopaging` | bool | Return all results |
| `fields` | string | `'ids'` for ID-only results |
