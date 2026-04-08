<?php

function test_field_groups(): void {
    TestCase::suite('Field Groups + Conditions + Repeaters');
    test_reset_globals();
    cr_register_default_post_types();
    cr_register_default_roles();

    global $cr_current_user;
    $cr_current_user = (object) ['ID' => 1];
    update_user_meta(1, cr_db()->prefix . 'capabilities', ['administrator' => true]);

    cr_content_builder_install();
    cr_install_field_groups_table();

    // Create a custom content type for testing
    cr_save_content_type(['name' => 'product', 'label' => 'Products']);
    cr_load_db_content_types();

    // ===== FIELD GROUPS =====

    $gid = cr_save_field_group([
        'name'  => 'product-details',
        'label' => 'Product Details',
        'description' => 'Core product information',
        'position' => 1,
        'location_rules' => [
            ['param' => 'post_type', 'operator' => '==', 'value' => 'product'],
        ],
    ]);
    TestCase::assertGreaterThan(0, $gid, 'cr_save_field_group creates group');

    $group = cr_get_field_group($gid);
    TestCase::assertNotNull($group, 'cr_get_field_group returns group');
    TestCase::assertEqual('Product Details', $group->label, 'Group has correct label');

    // Location matching
    TestCase::assertTrue(cr_field_group_matches($group, 'product'), 'Group matches product type');
    TestCase::assertFalse(cr_field_group_matches($group, 'post'), 'Group does not match post type');

    // Group with no rules matches everything
    $gid2 = cr_save_field_group(['name' => 'universal', 'label' => 'Universal']);
    $g2 = cr_get_field_group($gid2);
    TestCase::assertTrue(cr_field_group_matches($g2, 'product'), 'No-rules group matches product');
    TestCase::assertTrue(cr_field_group_matches($g2, 'post'), 'No-rules group matches post');

    // Get groups filtered by post type
    $product_groups = cr_get_field_groups('product');
    TestCase::assertGreaterThan(0, count($product_groups), 'product has matching groups');

    $post_groups = cr_get_field_groups('post');
    $has_product_group = false;
    foreach ($post_groups as $pg) {
        if ($pg->name === 'product-details') $has_product_group = true;
    }
    TestCase::assertFalse($has_product_group, 'product-details not in post groups');

    // Get all groups
    $all = cr_get_all_field_groups();
    TestCase::assertGreaterThan(1, count($all), 'get_all_field_groups returns multiple');

    // Update group
    cr_save_field_group(['id' => $gid, 'name' => 'product-details', 'label' => 'Product Info']);
    $group = cr_get_field_group($gid);
    TestCase::assertEqual('Product Info', $group->label, 'Group updated');

    // ===== FIELDS WITH GROUP_ID =====

    // Create field assigned to group
    $fid = cr_save_meta_field([
        'name' => 'product_type', 'label' => 'Product Type',
        'field_type' => 'select', 'post_type' => 'product',
        'options' => [['value' => 'physical', 'label' => 'Physical'], ['value' => 'digital', 'label' => 'Digital']],
    ]);
    // Assign to group
    cr_db()->update(cr_db()->prefix . 'meta_fields', ['group_id' => $gid], ['id' => $fid]);

    $fid2 = cr_save_meta_field([
        'name' => 'weight', 'label' => 'Weight (kg)', 'field_type' => 'number', 'post_type' => 'product',
    ]);
    cr_db()->update(cr_db()->prefix . 'meta_fields', ['group_id' => $gid], ['id' => $fid2]);

    // ===== CONDITIONAL LOGIC =====

    // Set conditional: weight visible only when product_type == 'physical'
    $conditions = ['relation' => 'and', 'rules' => [
        ['field' => 'product_type', 'operator' => '==', 'value' => 'physical'],
    ]];
    cr_db()->update(cr_db()->prefix . 'meta_fields', [
        'conditional_logic' => json_encode($conditions),
    ], ['id' => $fid2]);

    // Evaluate conditions: physical → weight visible
    $values_physical = ['product_type' => 'physical', 'weight' => '5'];
    TestCase::assertTrue(cr_evaluate_conditions($conditions, $values_physical), 'Condition met: physical → weight visible');

    // Digital → weight hidden
    $values_digital = ['product_type' => 'digital', 'weight' => ''];
    TestCase::assertFalse(cr_evaluate_conditions($conditions, $values_digital), 'Condition not met: digital → weight hidden');

    // OR relation
    $or_conditions = ['relation' => 'or', 'rules' => [
        ['field' => 'product_type', 'operator' => '==', 'value' => 'physical'],
        ['field' => 'product_type', 'operator' => '==', 'value' => 'bundle'],
    ]];
    TestCase::assertTrue(cr_evaluate_conditions($or_conditions, ['product_type' => 'physical']), 'OR: physical matches');
    TestCase::assertTrue(cr_evaluate_conditions($or_conditions, ['product_type' => 'bundle']), 'OR: bundle matches');
    TestCase::assertFalse(cr_evaluate_conditions($or_conditions, ['product_type' => 'digital']), 'OR: digital no match');

    // Operators
    TestCase::assertTrue(cr_evaluate_conditions(
        ['relation' => 'and', 'rules' => [['field' => 'price', 'operator' => '>', 'value' => '10']]],
        ['price' => '25']
    ), 'Operator > works');
    TestCase::assertTrue(cr_evaluate_conditions(
        ['relation' => 'and', 'rules' => [['field' => 'name', 'operator' => 'contains', 'value' => 'Pro']]],
        ['name' => 'Widget Pro']
    ), 'Operator contains works');
    TestCase::assertTrue(cr_evaluate_conditions(
        ['relation' => 'and', 'rules' => [['field' => 'notes', 'operator' => 'empty', 'value' => '']]],
        ['notes' => '']
    ), 'Operator empty works');
    TestCase::assertTrue(cr_evaluate_conditions(
        ['relation' => 'and', 'rules' => [['field' => 'notes', 'operator' => 'not_empty', 'value' => '']]],
        ['notes' => 'has content']
    ), 'Operator not_empty works');

    // Empty conditions = always visible
    TestCase::assertTrue(cr_evaluate_conditions([], ['anything' => 'value']), 'Empty conditions always true');
    TestCase::assertTrue(cr_evaluate_conditions(['rules' => []], []), 'Empty rules always true');

    // JS data export
    $fields = cr_get_meta_fields('product');
    $js_data = cr_conditions_to_js_data($fields);
    TestCase::assertIsArray($js_data, 'conditions_to_js_data returns array');

    // ===== REPEATER FIELDS =====

    $rep_id = cr_save_meta_field([
        'name' => 'features', 'label' => 'Features',
        'field_type' => 'repeater', 'post_type' => 'product',
        'options' => [
            'sub_fields' => [
                ['name' => 'title', 'label' => 'Title', 'field_type' => 'text'],
                ['name' => 'desc', 'label' => 'Description', 'field_type' => 'textarea'],
                ['name' => 'icon', 'label' => 'Icon', 'field_type' => 'text'],
            ],
            'min_rows' => 1,
            'max_rows' => 10,
            'button_label' => 'Add Feature',
        ],
    ]);
    TestCase::assertGreaterThan(0, $rep_id, 'Repeater field created');

    // Render repeater
    $rows = [
        ['title' => 'Fast', 'desc' => 'Blazing speed', 'icon' => '⚡'],
        ['title' => 'Secure', 'desc' => 'Built-in security', 'icon' => '🔒'],
    ];
    $html = cr_render_repeater_field([
        'name' => 'features', 'label' => 'Features', 'required' => 0,
        'description' => 'Product features',
        'options' => json_encode([
            'sub_fields' => [
                ['name' => 'title', 'label' => 'Title', 'field_type' => 'text'],
                ['name' => 'desc', 'label' => 'Description', 'field_type' => 'textarea'],
            ],
            'min_rows' => 0, 'max_rows' => 10, 'button_label' => 'Add Feature',
        ]),
    ], $rows);
    TestCase::assertContains('repeater-row', $html, 'Repeater renders rows');
    TestCase::assertContains('Fast', $html, 'Repeater includes row data');
    TestCase::assertContains('Secure', $html, 'Repeater includes second row');
    TestCase::assertContains('Add Feature', $html, 'Repeater has add button');
    TestCase::assertContains('template', $html, 'Repeater has JS template');

    // Validate repeater
    $error = cr_validate_repeater(['label' => 'Features', 'required' => 1, 'options' => json_encode(['min_rows' => 1])], []);
    TestCase::assertNotNull($error, 'Required repeater with 0 rows fails');

    $error = cr_validate_repeater(['label' => 'Features', 'required' => 0, 'options' => json_encode(['min_rows' => 2])], [['title' => 'one']]);
    TestCase::assertNotNull($error, 'Below min_rows fails');

    $error = cr_validate_repeater(['label' => 'Features', 'required' => 0, 'options' => json_encode(['max_rows' => 1])], [['a' => '1'], ['a' => '2']]);
    TestCase::assertNotNull($error, 'Above max_rows fails');

    $error = cr_validate_repeater(['label' => 'Features', 'required' => 0, 'options' => json_encode([])], $rows);
    TestCase::assertNull($error, 'Valid repeater passes');

    // ===== V2 RENDERING =====

    // Create a post to test rendering
    $post_id = cr_insert_post([
        'post_title' => 'Test Product', 'post_type' => 'product',
        'post_status' => 'publish', 'post_author' => 1, 'post_content' => 'Test',
    ]);
    update_post_meta($post_id, 'product_type', 'physical');
    update_post_meta($post_id, 'weight', '2.5');
    update_post_meta($post_id, 'features', json_encode($rows));

    $form_html = cr_render_meta_fields_form_v2('product', $post_id);
    TestCase::assertNotEmpty($form_html, 'v2 form renders');
    TestCase::assertContains('Product Info', $form_html, 'v2 form shows group label');
    TestCase::assertContains('data-field-name', $form_html, 'v2 form has field-name data attrs');
    TestCase::assertContains('data-conditions', $form_html, 'v2 form has conditions data attrs');
    TestCase::assertContains('repeater-row', $form_html, 'v2 form renders repeater');
    TestCase::assertContains('data-cr-conditions', $form_html, 'v2 form injects JS conditions data');

    // ===== SAVE V2 =====

    // Simulate POST data
    $_POST['meta_product_type'] = 'digital';
    $_POST['meta_weight'] = '0';
    $_POST['meta_features'] = [
        ['title' => 'Instant', 'desc' => 'Download immediately', 'icon' => '📥'],
    ];

    cr_save_meta_fields_from_post_v2($post_id, 'product');

    // product_type should be saved
    TestCase::assertEqual('digital', get_post_meta($post_id, 'product_type', true), 'v2 saves product_type');

    // weight should NOT be saved (conditional hides it when digital)
    // Actually the weight had a condition: only show when product_type == physical
    // Since we set product_type = digital, weight is hidden and should not be overwritten
    // The v2 save skips hidden fields
    TestCase::assertEqual('2.5', get_post_meta($post_id, 'weight', true), 'v2 preserves hidden field value');

    // repeater should be saved as JSON
    $saved_features = get_post_meta($post_id, 'features', true);
    $decoded = json_decode($saved_features, true);
    TestCase::assertIsArray($decoded, 'Repeater saved as JSON array');
    TestCase::assertEqual('Instant', $decoded[0]['title'], 'Repeater row data correct');

    // ===== DELETE GROUP =====

    cr_delete_field_group($gid);
    TestCase::assertNull(cr_get_field_group($gid), 'Group deleted');
    // Fields should be unlinked (group_id = 0) but still exist
    $field = cr_get_meta_field($fid);
    TestCase::assertNotNull($field, 'Field still exists after group delete');

    // Cleanup
    unset($_POST['meta_product_type'], $_POST['meta_weight'], $_POST['meta_features']);
    cr_delete_field_group($gid2);
    cr_delete_content_type('product');
    cr_delete_post($post_id, true);
    $cr_current_user = null;
}
