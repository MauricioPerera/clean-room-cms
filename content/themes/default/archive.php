<?php get_header(); ?>

<header class="archive-header">
    <h1 class="archive-title">
        <?php
        if (is_category()) {
            echo 'Category: <strong>' . esc_html($cr_query->queried_object->name ?? '') . '</strong>';
        } elseif (is_tag()) {
            echo 'Tag: <strong>' . esc_html($cr_query->queried_object->name ?? '') . '</strong>';
        } elseif (is_author()) {
            echo 'Author: <strong>' . esc_html($cr_query->queried_object->display_name ?? '') . '</strong>';
        } elseif (is_date()) {
            $parts = [];
            if (!empty($cr_query->query_vars['year'])) $parts[] = $cr_query->query_vars['year'];
            if (!empty($cr_query->query_vars['monthnum'])) $parts[] = date('F', mktime(0, 0, 0, $cr_query->query_vars['monthnum'], 1));
            if (!empty($cr_query->query_vars['day'])) $parts[] = $cr_query->query_vars['day'];
            echo 'Archives: <strong>' . esc_html(implode(' ', $parts)) . '</strong>';
        } else {
            echo 'Archives';
        }
        ?>
    </h1>
</header>

<?php if (have_posts()): ?>
    <?php while (have_posts()): the_post(); ?>
        <article class="post" id="post-<?php the_ID(); ?>">
            <header class="entry-header">
                <h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                <div class="entry-meta">
                    <span class="posted-on"><?php echo get_the_date(); ?></span>
                    <span class="posted-by"><?php the_author(); ?></span>
                </div>
            </header>
            <div class="entry-content">
                <?php the_excerpt(); ?>
            </div>
            <a href="<?php the_permalink(); ?>" class="read-more">Read more &rarr;</a>
        </article>
    <?php endwhile; ?>

    <nav class="pagination">
        <?php
        $paged = $cr_query->query_vars['paged'] ?? 1;
        if ($paged > 1) echo '<a href="?paged=' . ($paged - 1) . '">&larr; Newer</a>';
        else echo '<span></span>';
        if ($paged < $cr_query->max_num_pages) echo '<a href="?paged=' . ($paged + 1) . '">Older &rarr;</a>';
        ?>
    </nav>
<?php else: ?>
    <p>No posts found in this archive.</p>
<?php endif; ?>

<?php get_footer(); ?>
