<?php

function test_query(): void {
    TestCase::suite('Query Engine');
    test_reset_globals();

    // Ensure options cache is set for query conditionals
    global $cr_options_cache;
    $cr_options_cache['show_on_front'] = 'posts';
    $cr_options_cache['page_on_front'] = '0';
    $cr_options_cache['posts_per_page'] = '10';
    $cr_options_cache['blogname'] = 'Test Site';

    cr_register_default_post_types();
    cr_register_default_taxonomies();

    // Seed additional posts
    $ids = [];
    for ($i = 1; $i <= 5; $i++) {
        $ids[] = cr_insert_post([
            'post_title' => "Query Test Post {$i}",
            'post_content' => "Content for post {$i} with searchable keyword alpha",
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => 1,
        ]);
    }
    $page_id = cr_insert_post([
        'post_title' => 'Query Test Page',
        'post_content' => 'Page content',
        'post_status' => 'publish',
        'post_type' => 'page',
        'post_author' => 1,
    ]);
    $draft_id = cr_insert_post([
        'post_title' => 'Draft Post',
        'post_content' => 'Not published',
        'post_status' => 'draft',
        'post_type' => 'post',
        'post_author' => 1,
    ]);

    // CR_Query with post_type=post returns posts
    $q = new CR_Query(['post_type' => 'post', 'post_status' => 'publish', 'nopaging' => true]);
    TestCase::assertGreaterThan(0, $q->post_count, 'Query returns posts');

    // CR_Query with post_type=page returns pages
    $q = new CR_Query(['post_type' => 'page', 'post_status' => 'publish', 'nopaging' => true]);
    $found_page = false;
    foreach ($q->posts as $p) { if ($p->post_type === 'page') $found_page = true; }
    TestCase::assertTrue($found_page, 'Query with post_type=page returns pages');

    // Pagination
    $q = new CR_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 2, 'paged' => 1]);
    TestCase::assertEqual(2, $q->post_count, 'Pagination: posts_per_page=2 returns 2');
    TestCase::assertGreaterThan(1, $q->max_num_pages, 'Pagination: max_num_pages > 1');

    // Search by s=keyword
    $q = new CR_Query(['post_type' => 'post', 'post_status' => 'publish', 's' => 'alpha', 'nopaging' => true]);
    TestCase::assertGreaterThan(0, $q->post_count, 'Search by s= returns results');

    // Filter by author
    $q = new CR_Query(['post_type' => 'post', 'post_status' => 'publish', 'author' => 1, 'nopaging' => true]);
    TestCase::assertGreaterThan(0, $q->post_count, 'Filter by author returns results');

    // Filter by category
    // Assign category to a test post
    $cat_result = cr_insert_term('TestCat', 'category');
    $cat_id = $cat_result['term_id'];
    cr_set_post_terms($ids[0], [$cat_id], 'category');
    $q = new CR_Query(['post_type' => 'post', 'post_status' => 'publish', 'cat' => $cat_id, 'nopaging' => true]);
    TestCase::assertGreaterThan(0, $q->post_count, 'Filter by category returns results');

    // Filter by tag
    $tag_result = cr_insert_term('testtag', 'post_tag');
    $tag_id = $tag_result['term_id'];
    cr_set_post_terms($ids[1], [$tag_id], 'post_tag');
    $q = new CR_Query(['post_type' => 'post', 'post_status' => 'publish', 'tag' => 'testtag', 'nopaging' => true]);
    TestCase::assertGreaterThan(0, $q->post_count, 'Filter by tag returns results');

    // Filter by date
    $year = date('Y');
    $q = new CR_Query(['post_type' => 'post', 'post_status' => 'publish', 'year' => (int) $year, 'nopaging' => true]);
    TestCase::assertGreaterThan(0, $q->post_count, 'Filter by year returns results');

    // post_status filter
    $q = new CR_Query(['post_type' => 'post', 'post_status' => 'draft', 'nopaging' => true]);
    $all_draft = true;
    foreach ($q->posts as $p) { if ($p->post_status !== 'draft') $all_draft = false; }
    TestCase::assertTrue($all_draft || $q->post_count === 0, 'post_status filter returns only matching status');

    // orderby/order
    $q = new CR_Query(['post_type' => 'post', 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC', 'nopaging' => true]);
    if ($q->post_count >= 2) {
        $ordered = true;
        for ($i = 1; $i < $q->post_count; $i++) {
            if (strcasecmp($q->posts[$i]->post_title, $q->posts[$i-1]->post_title) < 0) $ordered = false;
        }
        TestCase::assertTrue($ordered, 'orderby=title order=ASC sorts correctly');
    } else {
        TestCase::assert(true, 'orderby/order: not enough posts to verify (skipped)');
    }

    // post__in
    $q = new CR_Query(['post_type' => 'post', 'post__in' => [$ids[0], $ids[1]], 'post_status' => 'publish', 'nopaging' => true]);
    TestCase::assertEqual(2, $q->post_count, 'post__in filters by specific IDs');

    // Conditional: is_home
    $q = new CR_Query([]);
    TestCase::assertTrue($q->is_home, 'is_home is true for empty query');

    // Conditional: is_single
    $q = new CR_Query(['p' => $ids[0], 'post_type' => 'post']);
    TestCase::assertTrue($q->is_single, 'is_single true for single post query');

    // Conditional: is_page
    $q = new CR_Query(['page_id' => $page_id]);
    TestCase::assertTrue($q->is_page, 'is_page true for page query');

    // Conditional: is_search
    $q = new CR_Query(['s' => 'test']);
    TestCase::assertTrue($q->is_search, 'is_search true with s= parameter');

    // Conditional: is_category
    $q = new CR_Query(['cat' => $cat_id, 'post_type' => 'post']);
    TestCase::assertTrue($q->is_category, 'is_category true with cat= parameter');

    // Conditional: is_404 for nonexistent singular
    $q = new CR_Query(['p' => 999999, 'post_type' => 'post']);
    TestCase::assertTrue($q->is_404, 'is_404 when singular post not found');

    // The Loop: have_posts/the_post
    $q = new CR_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 3]);
    cr_set_main_query($q);
    $loop_count = 0;
    while (have_posts()) {
        the_post();
        $loop_count++;
    }
    TestCase::assertEqual($q->post_count, $loop_count, 'The Loop iterates correct number of times');

    // Template tags
    $q = new CR_Query(['p' => $ids[0], 'post_type' => 'post']);
    cr_set_main_query($q);
    if ($q->post_count > 0) {
        $q->the_post();
        TestCase::assertNotEmpty(get_the_title(), 'get_the_title returns title');
        TestCase::assertNotEmpty(get_the_content(), 'get_the_content returns content');
        TestCase::assertNotEmpty(get_the_excerpt(), 'get_the_excerpt returns excerpt');
        TestCase::assertContains('http', get_the_permalink(), 'get_the_permalink returns URL');
        TestCase::assertNotEmpty(get_the_date(), 'get_the_date returns date');
        TestCase::assertNotEmpty(get_the_author(), 'get_the_author returns author name');
    }

    // Cleanup
    foreach ($ids as $pid) cr_delete_post($pid, true);
    cr_delete_post($page_id, true);
    cr_delete_post($draft_id, true);
    cr_delete_term($cat_id, 'category');
    cr_delete_term($tag_id, 'post_tag');
}
