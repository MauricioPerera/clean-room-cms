<?php get_header(); ?>

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
        global $cr_query;
        if ($cr_query->max_num_pages > 1) {
            $paged = $cr_query->query_vars['paged'] ?? 1;
            if ($paged > 1) {
                echo '<a href="' . esc_url(CR_HOME_URL . '/page/' . ($paged - 1) . '/') . '">&larr; Newer posts</a>';
            } else {
                echo '<span></span>';
            }
            if ($paged < $cr_query->max_num_pages) {
                echo '<a href="' . esc_url(CR_HOME_URL . '/page/' . ($paged + 1) . '/') . '">Older posts &rarr;</a>';
            }
        }
        ?>
    </nav>

<?php else: ?>
    <div class="no-posts">
        <h2>No posts found</h2>
        <p>There are no posts to display.</p>
    </div>
<?php endif; ?>

<?php get_footer(); ?>
