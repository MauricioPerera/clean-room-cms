<?php

function test_mcp(): void {
    TestCase::suite('MCP Adapter');
    test_reset_globals();
    CR_Abilities::reset();
    cr_register_default_post_types();
    cr_register_default_roles();
    cr_register_core_abilities();

    // Set admin user
    global $cr_current_user;
    $cr_current_user = (object) ['ID' => 1];
    update_user_meta(1, cr_db()->prefix . 'capabilities', ['administrator' => true]);

    // Set content guidelines for testing
    cr_set_content_guidelines([
        'site' => 'Test site for developers',
        'copy' => 'Write technically but clearly',
    ]);

    $adapter = new CR_MCP_Adapter();

    // Helper to capture MCP output cleanly (strip PHP warnings from output)
    $mcp_call = function(string $path, string $method) use ($adapter): ?array {
        ob_start();
        $adapter->handle($path, $method);
        $raw = ob_get_clean();
        // Find the first { and extract everything from there (skip warning text)
        $start = strpos($raw, '{');
        if ($start !== false) {
            $json_part = substr($raw, $start);
            return json_decode($json_part, true);
        }
        return json_decode($raw, true);
    };

    // Server info
    $info = $mcp_call('', 'GET');
    TestCase::assertIsArray($info, 'Server info returns JSON');
    TestCase::assertTrue(isset($info['protocolVersion']), 'Has protocolVersion');
    TestCase::assertTrue(isset($info['serverInfo']), 'Has serverInfo');
    TestCase::assertEqual('Test Site', $info['serverInfo']['name'] ?? '', 'Server name correct');
    TestCase::assertTrue(isset($info['capabilities']['tools']), 'Has tools capability');
    TestCase::assertTrue(isset($info['capabilities']['resources']), 'Has resources capability');
    TestCase::assertTrue(isset($info['capabilities']['prompts']), 'Has prompts capability');

    // List tools
    $data = $mcp_call('tools', 'GET');
    TestCase::assertTrue(isset($data['tools']), 'Tools response has tools key');
    TestCase::assertGreaterThan(0, count($data['tools'] ?? []), 'Has registered tools');

    $tool_names = array_map(fn($t) => $t['name'], $data['tools']);
    TestCase::assertTrue(in_array('get_post', $tool_names), 'Tools include get_post');
    TestCase::assertTrue(in_array('search_content', $tool_names), 'Tools include search_content');
    TestCase::assertTrue(in_array('get_site_info', $tool_names), 'Tools include get_site_info');

    // Verify tool structure
    $tool = $data['tools'][0] ?? [];
    TestCase::assertTrue(isset($tool['name']), 'Tool has name');
    TestCase::assertTrue(isset($tool['description']), 'Tool has description');
    TestCase::assertTrue(isset($tool['inputSchema']), 'Tool has inputSchema');

    // Execute tool via Abilities directly (can't mock php://input easily)
    $result = CR_Abilities::execute('get_site_info', []);
    TestCase::assertEqual('Test Site', $result['name'], 'Tool execution returns site name');

    $result = CR_Abilities::execute('get_post', ['post_id' => 1]);
    TestCase::assertEqual('Test Post', $result['title'], 'Tool execution: get_post works');

    // List resources
    $data = $mcp_call('resources', 'GET');
    TestCase::assertTrue(isset($data['resources']), 'Resources response has resources key');
    TestCase::assertGreaterThan(0, count($data['resources'] ?? []), 'Has resources');

    $uris = array_map(fn($r) => $r['uri'], $data['resources'] ?? []);
    TestCase::assertTrue(in_array('site://guidelines', $uris), 'Resources include guidelines');
    TestCase::assertTrue(in_array('site://info', $uris), 'Resources include site info');

    // Read resource: guidelines
    $data = $mcp_call('resources/guidelines', 'GET');
    TestCase::assertTrue(isset($data['contents']), 'Resource response has contents');
    $text = $data['contents'][0]['text'] ?? '';
    $parsed = json_decode($text, true);
    TestCase::assertIsArray($parsed, 'Guidelines resource is valid JSON');
    TestCase::assertGreaterThan(0, count($parsed), 'Guidelines have sections');

    // Read resource: info
    $data = $mcp_call('resources/info', 'GET');
    $text = $data['contents'][0]['text'] ?? '';
    $parsed = json_decode($text, true);
    TestCase::assertEqual('Test Site', $parsed['name'] ?? '', 'Info resource has site name');

    // List prompts
    $data = $mcp_call('prompts', 'GET');
    TestCase::assertTrue(isset($data['prompts']), 'Prompts response has prompts key');
    TestCase::assertGreaterThan(0, count($data['prompts'] ?? []), 'Has prompt templates');

    $prompt_names = array_map(fn($p) => $p['name'], $data['prompts'] ?? []);
    TestCase::assertTrue(in_array('write_post', $prompt_names), 'Prompts include write_post');
    TestCase::assertTrue(in_array('summarize', $prompt_names), 'Prompts include summarize');

    $prompt = $data['prompts'][0] ?? [];
    TestCase::assertTrue(isset($prompt['name']), 'Prompt has name');
    TestCase::assertTrue(isset($prompt['description']), 'Prompt has description');
    TestCase::assertTrue(isset($prompt['arguments']), 'Prompt has arguments');

    // Unknown route returns error
    $data = $mcp_call('nonexistent', 'GET');
    TestCase::assertTrue(isset($data['error']), 'Unknown route returns error');

    // Cleanup
    $cr_current_user = null;
    delete_option('cr_content_guidelines');
    CR_Abilities::reset();
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
