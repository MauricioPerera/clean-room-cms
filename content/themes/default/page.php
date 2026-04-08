<?php get_header(); ?>

<?php if (have_posts()): while (have_posts()): the_post(); ?>

    <article class="post page" id="page-<?php the_ID(); ?>">
        <header class="entry-header">
            <h1 class="entry-title"><?php the_title(); ?></h1>
        </header>
        <div class="entry-content">
            <?php the_content(); ?>
        </div>
    </article>

<?php endwhile; endif; ?>

<?php get_footer(); ?>
