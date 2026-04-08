<?php

function test_content_builder(): void {
    TestCase::suite('Content Builder');
    test_reset_globals();
    cr_register_default_post_types();
    cr_register_default_taxonomies();
    cr_register_default_roles();

    global $cr_current_user;
    $cr_current_user = (object) ['ID' => 1];
    update_user_meta(1, cr_db()->prefix . 'capabilities', ['administrator' => true]);

    // Install content builder tables
    cr_content_builder_install();
    $db = cr_db();
    $exists = $db->get_var("SHOW TABLES LIKE '{$db->prefix}content_types'");
    TestCase::assertNotNull($exists, 'content_types table created');
    $exists = $db->get_var("SHOW TABLES LIKE '{$db->prefix}content_taxonomies'");
    TestCase::assertNotNull($exists, 'content_taxonomies table created');
    $exists = $db->get_var("SHOW TABLES LIKE '{$db->prefix}meta_fields'");
    TestCase::assertNotNull($exists, 'meta_fields table created');

    // ===== CONTENT TYPES =====

    // Create content type
    $id = cr_save_content_type([
        'name'        => 'product',
        'label'       => 'Products',
        'label_singular' => 'Product',
        'description' => 'Physical and digital products',
        'icon'        => '📦',
        'public'      => 1,
        'show_in_rest' => 1,
        'supports'    => ['title', 'editor', 'thumbnail'],
        'exclude_from_search' => 0,
    ]);
    TestCase::assertGreaterThan(0, $id, 'cr_save_content_type creates type');

    // Get content type
    $type = cr_get_content_type('product');
    TestCase::assertNotNull($type, 'cr_get_content_type returns type');
    TestCase::assertEqual('Products', $type->label, 'Type has correct label');
    TestCase::assertEqual('📦', $type->icon, 'Type has correct icon');
    $supports = json_decode($type->supports, true);
    TestCase::assertTrue(in_array('title', $supports), 'Supports includes title');

    // Get all types
    $types = cr_get_content_types();
    TestCase::assertGreaterThan(0, count($types), 'cr_get_content_types returns list');

    // Update content type
    $id2 = cr_save_content_type([
        'name'  => 'product',
        'label' => 'All Products',
    ]);
    TestCase::assertEqual($id, $id2, 'Update returns same ID');
    $type = cr_get_content_type('product');
    TestCase::assertEqual('All Products', $type->label, 'Type label updated');

    // Cannot create builtin type
    $result = cr_save_content_type(['name' => 'post', 'label' => 'Override']);
    TestCase::assertFalse($result, 'Cannot create builtin type');

    // Load into registry
    cr_load_db_content_types();
    TestCase::assertTrue(post_type_exists('product'), 'DB type registered with CMS');
    $pt = get_post_type_object('product');
    TestCase::assertEqual('All Products', $pt->label, 'Registered type has correct label');

    // Create second type
    cr_save_content_type(['name' => 'event', 'label' => 'Events', 'icon' => '📅']);

    // Create a product post
    $post_id = cr_insert_post([
        'post_title' => 'Widget Pro', 'post_content' => 'A great widget',
        'post_type' => 'product', 'post_status' => 'publish', 'post_author' => 1,
    ]);
    TestCase::assertGreaterThan(0, $post_id, 'Can create post of custom type');
    TestCase::assertEqual('product', get_post_type($post_id), 'Post has correct type');

    // ===== TAXONOMIES =====

    // Create custom taxonomy
    $tax_id = cr_save_content_taxonomy([
        'name'         => 'brand',
        'label'        => 'Brands',
        'hierarchical' => 1,
        'post_types'   => ['product'],
        'show_in_rest' => 1,
    ]);
    TestCase::assertGreaterThan(0, $tax_id, 'cr_save_content_taxonomy creates taxonomy');

    // Get taxonomy
    $tax = cr_get_content_taxonomy('brand');
    TestCase::assertNotNull($tax, 'cr_get_content_taxonomy returns taxonomy');
    TestCase::assertEqual('Brands', $tax->label, 'Taxonomy has correct label');
    $linked = json_decode($tax->post_types, true);
    TestCase::assertTrue(in_array('product', $linked), 'Taxonomy linked to product');

    // Cannot create builtin taxonomy
    $result = cr_save_content_taxonomy(['name' => 'category', 'label' => 'Override']);
    TestCase::assertFalse($result, 'Cannot create builtin taxonomy');

    // Load into registry
    cr_load_db_taxonomies();
    TestCase::assertTrue(taxonomy_exists('brand'), 'DB taxonomy registered with CMS');
    $obj_taxes = get_object_taxonomies('product');
    TestCase::assertTrue(in_array('brand', $obj_taxes), 'brand linked to product in registry');

    // Create term in custom taxonomy
    $term_result = cr_insert_term('Nike', 'brand');
    TestCase::assertIsArray($term_result, 'Can create term in custom taxonomy');
    cr_set_post_terms($post_id, [$term_result['term_id']], 'brand');
    $terms = get_the_terms($post_id, 'brand');
    TestCase::assertGreaterThan(0, count($terms), 'Custom taxonomy terms assigned to post');

    // ===== META FIELDS =====

    // Create meta field
    $field_id = cr_save_meta_field([
        'name'       => 'price',
        'label'      => 'Price',
        'field_type' => 'number',
        'post_type'  => 'product',
        'required'   => 1,
        'placeholder' => '0.00',
        'group_name' => 'Pricing',
        'validation' => ['min' => 0],
    ]);
    TestCase::assertGreaterThan(0, $field_id, 'cr_save_meta_field creates field');

    // Get meta field
    $field = cr_get_meta_field($field_id);
    TestCase::assertNotNull($field, 'cr_get_meta_field returns field');
    TestCase::assertEqual('Price', $field->label, 'Field has correct label');
    TestCase::assertEqual('number', $field->field_type, 'Field has correct type');
    TestCase::assertEqual('product', $field->post_type, 'Field linked to product');

    // Create more fields
    cr_save_meta_field(['name' => 'sku', 'label' => 'SKU', 'field_type' => 'text', 'post_type' => 'product', 'group_name' => 'Inventory']);
    cr_save_meta_field(['name' => 'color', 'label' => 'Color', 'field_type' => 'select', 'post_type' => 'product', 'options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']], 'group_name' => 'Details']);
    cr_save_meta_field(['name' => 'featured', 'label' => 'Featured', 'field_type' => 'checkbox', 'post_type' => 'product']);

    // Get fields for post type
    $fields = cr_get_meta_fields('product');
    TestCase::assertGreaterThan(3, count($fields), 'cr_get_meta_fields returns fields for product');

    // Render field
    $html = cr_render_meta_field(['name' => 'price', 'label' => 'Price', 'field_type' => 'number', 'required' => 1, 'placeholder' => '0.00', 'description' => 'Enter price', 'default_value' => '', 'options' => '[]', 'validation' => '{}'], 29.99);
    TestCase::assertContains('type="number"', $html, 'Field renders as number input');
    TestCase::assertContains('29.99', $html, 'Field renders with value');
    TestCase::assertContains('required', $html, 'Required field has required attr');

    // Render select field
    $html = cr_render_meta_field(['name' => 'color', 'label' => 'Color', 'field_type' => 'select', 'required' => 0, 'placeholder' => '', 'description' => '', 'default_value' => '', 'options' => json_encode([['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']]), 'validation' => '{}'], 'blue');
    TestCase::assertContains('<select', $html, 'Select field renders');
    TestCase::assertContains('selected', $html, 'Select has selected option');

    // Render checkbox field
    $html = cr_render_meta_field(['name' => 'featured', 'label' => 'Featured', 'field_type' => 'checkbox', 'required' => 0, 'placeholder' => '', 'description' => 'Mark as featured', 'default_value' => '', 'options' => '[]', 'validation' => '{}'], true);
    TestCase::assertContains('type="checkbox"', $html, 'Checkbox renders');
    TestCase::assertContains('checked', $html, 'Checkbox is checked');

    // Render full form
    $form_html = cr_render_meta_fields_form('product', $post_id);
    TestCase::assertContains('Pricing', $form_html, 'Form renders group title');
    TestCase::assertContains('meta_price', $form_html, 'Form includes price field');
    TestCase::assertContains('meta_sku', $form_html, 'Form includes sku field');

    // Validate field
    $error = cr_validate_meta_field(['label' => 'Price', 'required' => 1, 'field_type' => 'number', 'validation' => json_encode(['min' => 0])], '');
    TestCase::assertNotNull($error, 'Required empty value fails validation');

    $error = cr_validate_meta_field(['label' => 'Price', 'required' => 0, 'field_type' => 'number', 'validation' => json_encode(['min' => 0])], -5);
    TestCase::assertNotNull($error, 'Below min fails validation');

    $error = cr_validate_meta_field(['label' => 'Email', 'required' => 0, 'field_type' => 'email', 'validation' => '{}'], 'not-email');
    TestCase::assertNotNull($error, 'Invalid email fails validation');

    $error = cr_validate_meta_field(['label' => 'Price', 'required' => 0, 'field_type' => 'number', 'validation' => '{}'], 29.99);
    TestCase::assertNull($error, 'Valid value passes validation');

    // Field types registry
    $types = cr_get_field_types();
    TestCase::assertGreaterThan(10, count($types), 'Field types registry has entries');
    TestCase::assertTrue(isset($types['text']), 'Has text type');
    TestCase::assertTrue(isset($types['select']), 'Has select type');
    TestCase::assertTrue(isset($types['date']), 'Has date type');
    TestCase::assertTrue(isset($types['checkbox']), 'Has checkbox type');

    // All post types for select
    $all = cr_get_all_post_types_for_select();
    TestCase::assertTrue(isset($all['post']), 'Select includes post');
    TestCase::assertTrue(isset($all['product']), 'Select includes custom product');

    // Delete meta field
    $deleted = cr_delete_meta_field($field_id);
    TestCase::assertTrue($deleted, 'cr_delete_meta_field returns true');
    TestCase::assertNull(cr_get_meta_field($field_id), 'Deleted field is gone');

    // Delete taxonomy
    $deleted = cr_delete_content_taxonomy('brand');
    TestCase::assertTrue($deleted, 'cr_delete_content_taxonomy returns true');
    TestCase::assertNull(cr_get_content_taxonomy('brand'), 'Deleted taxonomy is gone');

    // Delete content type (also cleans up meta fields)
    $deleted = cr_delete_content_type('product');
    TestCase::assertTrue($deleted, 'cr_delete_content_type returns true');
    TestCase::assertNull(cr_get_content_type('product'), 'Deleted type is gone');

    // Cannot delete builtin
    TestCase::assertFalse(cr_delete_content_type('post'), 'Cannot delete builtin post');
    TestCase::assertFalse(cr_delete_content_taxonomy('category'), 'Cannot delete builtin category');

    // Cleanup
    cr_delete_content_type('event');
    cr_delete_post($post_id, true);
    $cr_current_user = null;
}
