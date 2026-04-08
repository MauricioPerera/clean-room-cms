<?php get_header(); ?>

<header class="archive-header">
    <h1 class="archive-title">Search results for: <strong><?php echo esc_html($cr_query->query_vars['s'] ?? ''); ?></strong></h1>
</header>

<?php if (have_posts()): ?>
    <?php while (have_posts()): the_post(); ?>
        <article class="post" id="post-<?php the_ID(); ?>">
            <header class="entry-header">
                <h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                <div class="entry-meta">
                    <span class="posted-on"><?php echo get_the_date(); ?></span>
                </div>
            </header>
            <div class="entry-content">
                <?php the_excerpt(); ?>
            </div>
        </article>
    <?php endwhile; ?>
<?php else: ?>
    <div class="no-posts">
        <h2>No results found</h2>
        <p>No posts matched your search. Try different keywords.</p>
        <form class="search-form" action="<?php echo esc_url(CR_HOME_URL); ?>/" method="get" style="margin-top:20px;max-width:400px;">
            <input type="search" name="s" placeholder="Search..." value="<?php echo esc_attr($cr_query->query_vars['s'] ?? ''); ?>">
            <button type="submit">Search</button>
        </form>
    </div>
<?php endif; ?>

<?php get_footer(); ?>
