<?php

function test_taxonomies(): void {
    TestCase::suite('Taxonomy System');
    test_reset_globals();
    cr_register_default_post_types();

    // register_taxonomy
    $result = register_taxonomy('genre', 'book', ['label' => 'Genres', 'hierarchical' => true]);
    TestCase::assertTrue($result, 'register_taxonomy returns true');

    // get_taxonomy
    $tax = get_taxonomy('genre');
    TestCase::assertNotNull($tax, 'get_taxonomy returns object');
    TestCase::assertEqual('Genres', $tax->label, 'Taxonomy has correct label');

    // taxonomy_exists
    TestCase::assertTrue(taxonomy_exists('genre'), 'taxonomy_exists returns true');
    TestCase::assertFalse(taxonomy_exists('nope'), 'taxonomy_exists returns false for unregistered');

    // get_taxonomies
    register_taxonomy('mood', 'post', ['label' => 'Moods']);
    $all = get_taxonomies();
    TestCase::assertTrue(in_array('genre', $all), 'get_taxonomies includes genre');
    TestCase::assertTrue(in_array('mood', $all), 'get_taxonomies includes mood');

    // get_object_taxonomies
    cr_register_default_taxonomies();
    $post_taxes = get_object_taxonomies('post');
    TestCase::assertTrue(in_array('category', $post_taxes), 'get_object_taxonomies includes category for post');
    TestCase::assertTrue(in_array('post_tag', $post_taxes), 'get_object_taxonomies includes post_tag for post');

    // cr_register_default_taxonomies
    TestCase::assertTrue(taxonomy_exists('category'), 'Default category taxonomy registered');
    TestCase::assertTrue(taxonomy_exists('post_tag'), 'Default post_tag taxonomy registered');

    // cr_insert_term creates term
    $result = cr_insert_term('Technology', 'category');
    TestCase::assertIsArray($result, 'cr_insert_term returns array');
    TestCase::assertTrue(isset($result['term_id']), 'Result has term_id');
    $tech_id = $result['term_id'];

    // cr_insert_term generates unique slug
    $result2 = cr_insert_term('Technology', 'category');
    $term2 = get_term($result2['term_id'], 'category');
    TestCase::assertNotEqual('technology', $term2->slug, 'Duplicate term gets unique slug');

    // cr_update_term modifies term
    $updated = cr_update_term($tech_id, 'category', ['name' => 'Tech']);
    TestCase::assertTrue($updated, 'cr_update_term returns true');
    $term = get_term($tech_id, 'category');
    TestCase::assertEqual('Tech', $term->name, 'Term name updated');

    // get_term returns term by ID
    $term = get_term($tech_id, 'category');
    TestCase::assertNotNull($term, 'get_term returns term');
    TestCase::assertEqual($tech_id, (int) $term->term_id, 'get_term returns correct term');

    // get_term_by slug
    $term = get_term_by('slug', 'technology', 'category');
    TestCase::assertNotNull($term, 'get_term_by slug finds term');

    // get_term_by name
    $term = get_term_by('name', 'Tech', 'category');
    TestCase::assertNotNull($term, 'get_term_by name finds term');

    // get_terms returns filtered list
    $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
    TestCase::assertIsArray($terms, 'get_terms returns array');
    TestCase::assertGreaterThan(0, count($terms), 'get_terms returns results');

    // cr_set_post_terms assigns terms to post
    $post_id = cr_insert_post(['post_title' => 'Taxonomy Test', 'post_status' => 'publish', 'post_type' => 'post', 'post_author' => 1]);
    cr_set_post_terms($post_id, [$tech_id], 'category');
    $assigned = get_the_terms($post_id, 'category');
    TestCase::assertNotEmpty($assigned, 'cr_set_post_terms assigns terms');
    $found = false;
    foreach ($assigned as $t) {
        if ((int) $t->term_id === $tech_id) $found = true;
    }
    TestCase::assertTrue($found, 'Correct term assigned to post');

    // get_the_terms returns terms of the post
    $post_terms = get_the_terms($post_id, 'category');
    TestCase::assertIsArray($post_terms, 'get_the_terms returns array');
    TestCase::assertGreaterThan(0, count($post_terms), 'get_the_terms returns results');

    // cr_delete_term
    $deleted = cr_delete_term($result2['term_id'], 'category');
    TestCase::assertTrue($deleted, 'cr_delete_term returns true');
    $term = get_term($result2['term_id'], 'category');
    TestCase::assertNull($term, 'Deleted term is gone');

    // Cleanup
    cr_delete_post($post_id, true);
    cr_delete_term($tech_id, 'category');
}
