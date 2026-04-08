<?php get_header(); ?>

<?php if (have_posts()): while (have_posts()): the_post(); ?>

    <article class="post single" id="post-<?php the_ID(); ?>">
        <header class="entry-header">
            <h1 class="entry-title"><?php the_title(); ?></h1>
            <div class="entry-meta">
                <span class="posted-on"><?php echo get_the_date(); ?></span>
                <span class="posted-by"><?php the_author(); ?></span>
                <?php
                $categories = get_the_terms(get_the_ID(), 'category');
                if (!empty($categories)) {
                    $names = array_map(fn($c) => $c->name, $categories);
                    echo '<span class="posted-in">in ' . esc_html(implode(', ', $names)) . '</span>';
                }
                ?>
            </div>
        </header>
        <div class="entry-content">
            <?php the_content(); ?>
        </div>
        <?php
        $tags = get_the_terms(get_the_ID(), 'post_tag');
        if (!empty($tags)) {
            echo '<div class="entry-tags" style="margin-top:24px;color:var(--color-text-light);font-size:.9em;">';
            echo 'Tags: ';
            $tag_links = array_map(fn($t) => '<a href="' . esc_url(CR_HOME_URL . '/tag/' . $t->slug . '/') . '">' . esc_html($t->name) . '</a>', $tags);
            echo implode(', ', $tag_links);
            echo '</div>';
        }
        ?>
    </article>

    <nav class="pagination" style="margin-top: 40px;">
        <a href="<?php echo esc_url(CR_HOME_URL); ?>">&larr; Back to posts</a>
    </nav>

<?php endwhile; endif; ?>

<?php get_footer(); ?>
