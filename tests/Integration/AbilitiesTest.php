<?php

function test_abilities(): void {
    TestCase::suite('Abilities API');
    CR_Abilities::reset();
    test_reset_globals();
    cr_register_default_post_types();
    cr_register_default_roles();

    // Set admin user for permission checks
    global $cr_current_user, $cr_roles;
    $cr_current_user = (object) ['ID' => 1];
    update_user_meta(1, cr_db()->prefix . 'capabilities', ['administrator' => true]);

    // Register a simple ability
    $result = register_ability('greet', [
        'description'  => 'Greet someone by name',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'greeting' => ['type' => 'string'],
            ],
        ],
        'callback' => function (array $input): array {
            return ['greeting' => 'Hello, ' . $input['name'] . '!'];
        },
        'category' => 'utility',
    ]);
    TestCase::assertTrue($result, 'register_ability returns true');

    // ability_exists
    TestCase::assertTrue(ability_exists('greet'), 'ability_exists returns true');
    TestCase::assertFalse(ability_exists('nonexistent'), 'ability_exists returns false for missing');

    // Get ability definition
    $ability = CR_Abilities::get('greet');
    TestCase::assertNotNull($ability, 'get returns ability');
    TestCase::assertEqual('Greet someone by name', $ability['description'], 'Description correct');
    TestCase::assertEqual('utility', $ability['category'], 'Category correct');

    // Execute ability
    $result = execute_ability('greet', ['name' => 'World']);
    TestCase::assertEqual('Hello, World!', $result['greeting'], 'execute_ability returns correct output');

    // Execute with missing required field
    $result = execute_ability('greet', []);
    TestCase::assertEqual('ability_invalid_input', $result['error'], 'Missing required field returns error');
    TestCase::assertContains('name', $result['message'], 'Error mentions missing field');

    // Execute nonexistent ability
    $result = execute_ability('nonexistent', []);
    TestCase::assertEqual('ability_not_found', $result['error'], 'Nonexistent ability returns error');

    // Register ability with type validation
    register_ability('typed', [
        'description' => 'Type-validated ability',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'integer'],
                'label' => ['type' => 'string'],
                'active' => ['type' => 'boolean'],
            ],
            'required' => ['count'],
        ],
        'output_schema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
        'callback' => fn($input) => ['ok' => true],
    ]);

    // Valid types
    $result = execute_ability('typed', ['count' => 5, 'label' => 'test', 'active' => true]);
    TestCase::assertTrue($result['ok'], 'Valid types pass validation');

    // Invalid type
    $result = execute_ability('typed', ['count' => 'not_a_number']);
    TestCase::assertEqual('ability_invalid_input', $result['error'], 'Wrong type caught');
    TestCase::assertContains('count', $result['message'], 'Error mentions field name');

    // Enum validation
    register_ability('enum_test', [
        'description' => 'Enum test',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending']],
            ],
            'required' => ['status'],
        ],
        'output_schema' => ['type' => 'object', 'properties' => new \stdClass()],
        'callback' => fn($input) => ['status' => $input['status']],
    ]);

    $result = execute_ability('enum_test', ['status' => 'publish']);
    TestCase::assertEqual('publish', $result['status'], 'Valid enum passes');

    $result = execute_ability('enum_test', ['status' => 'invalid']);
    TestCase::assertEqual('ability_invalid_input', $result['error'], 'Invalid enum caught');

    // Permission-gated ability
    register_ability('admin_only', [
        'description' => 'Admin-only ability',
        'permission'  => 'manage_options',
        'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
        'output_schema' => ['type' => 'object', 'properties' => ['secret' => ['type' => 'string']]],
        'callback' => fn() => ['secret' => 'admin_data'],
    ]);

    $result = execute_ability('admin_only', []);
    TestCase::assertEqual('admin_data', $result['secret'], 'Admin can execute admin-only ability');

    // Get all abilities
    $all = CR_Abilities::get_all();
    TestCase::assertGreaterThan(3, count($all), 'get_all returns multiple abilities');

    // Get by category
    $utils = CR_Abilities::get_all('utility');
    TestCase::assertTrue(isset($utils['greet']), 'get_all filters by category');

    // Get categories
    $cats = CR_Abilities::get_categories();
    TestCase::assertTrue(in_array('utility', $cats), 'get_categories includes utility');

    // As tool declarations (for AI function calling)
    $tools = cr_get_abilities_as_tools();
    TestCase::assertIsArray($tools, 'as_tool_declarations returns array');
    TestCase::assertGreaterThan(0, count($tools), 'Tool declarations not empty');

    $first_tool = $tools[0];
    TestCase::assertTrue(isset($first_tool['name']), 'Tool has name');
    TestCase::assertTrue(isset($first_tool['description']), 'Tool has description');
    TestCase::assertTrue(isset($first_tool['input_schema']), 'Tool has input_schema');

    // Handle tool calls (simulate AI response)
    $tool_calls = [
        ['id' => 'tc_1', 'name' => 'greet', 'args' => ['name' => 'Claude']],
    ];
    $results = CR_Abilities::handle_tool_calls($tool_calls);
    TestCase::assertCount(1, $results, 'handle_tool_calls returns 1 result');
    TestCase::assertEqual('tc_1', $results[0]['tool_call_id'], 'Result has correct tool_call_id');
    TestCase::assertEqual('Hello, Claude!', $results[0]['output']['greeting'], 'Tool call executed correctly');

    // Unregister
    $result = CR_Abilities::unregister('greet');
    TestCase::assertTrue($result, 'unregister returns true');
    TestCase::assertFalse(ability_exists('greet'), 'Unregistered ability no longer exists');

    // Callback that throws
    register_ability('throws', [
        'description' => 'This throws',
        'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
        'output_schema' => ['type' => 'object', 'properties' => new \stdClass()],
        'callback' => fn() => throw new \RuntimeException('Intentional error'),
    ]);
    $result = execute_ability('throws', []);
    TestCase::assertEqual('ability_execution_error', $result['error'], 'Exception caught and returned as error');
    TestCase::assertContains('Intentional', $result['message'], 'Error message preserved');

    // Register core abilities
    CR_Abilities::reset();
    cr_register_core_abilities();
    TestCase::assertTrue(ability_exists('get_post'), 'Core ability get_post registered');
    TestCase::assertTrue(ability_exists('create_post'), 'Core ability create_post registered');
    TestCase::assertTrue(ability_exists('search_content'), 'Core ability search_content registered');
    TestCase::assertTrue(ability_exists('get_site_info'), 'Core ability get_site_info registered');
    TestCase::assertTrue(ability_exists('generate_excerpt'), 'Core ability generate_excerpt registered');

    // Execute core ability
    $result = execute_ability('get_site_info', []);
    TestCase::assertEqual('Test Site', $result['name'], 'get_site_info returns site name');

    $result = execute_ability('get_post', ['post_id' => 1]);
    TestCase::assertEqual('Test Post', $result['title'], 'get_post returns post title');

    $result = execute_ability('search_content', ['query' => 'test']);
    TestCase::assertGreaterThan(0, $result['total'], 'search_content finds results');

    $cr_current_user = null;
    CR_Abilities::reset();
}
