<?php

function test_ai_client(): void {
    TestCase::suite('AI Client SDK');
    CR_AI_Client::instance()->reset();

    // Register OpenAI connector
    $openai = new CR_AI_Connector_OpenAI('sk-test-key-for-testing');
    CR_AI_Client::instance()->register_connector($openai);
    TestCase::assertEqual('openai', $openai->get_id(), 'OpenAI connector has correct ID');
    TestCase::assertEqual('OpenAI', $openai->get_name(), 'OpenAI connector has correct name');
    TestCase::assertNotEmpty($openai->get_models(), 'OpenAI connector has models');
    TestCase::assertTrue(in_array('gpt-4o', $openai->get_models()), 'OpenAI includes gpt-4o');

    // Register Anthropic connector
    $anthropic = new CR_AI_Connector_Anthropic('sk-ant-test-key');
    CR_AI_Client::instance()->register_connector($anthropic);
    TestCase::assertEqual('anthropic', $anthropic->get_id(), 'Anthropic connector has correct ID');
    TestCase::assertTrue(in_array('claude-sonnet-4-6', $anthropic->get_models()), 'Anthropic includes claude-sonnet-4-6');

    // Register Ollama connector
    $ollama = new CR_AI_Connector_Ollama('http://localhost:11434');
    CR_AI_Client::instance()->register_connector($ollama);
    TestCase::assertEqual('ollama', $ollama->get_id(), 'Ollama connector has correct ID');

    // Get connectors
    $connectors = CR_AI_Client::instance()->get_connectors();
    TestCase::assertCount(3, $connectors, 'Three connectors registered');

    // Get specific connector
    $c = CR_AI_Client::instance()->get_connector('openai');
    TestCase::assertNotNull($c, 'get_connector returns OpenAI');
    TestCase::assertNull(CR_AI_Client::instance()->get_connector('nonexistent'), 'get_connector returns null for unknown');

    // Default provider (first registered)
    TestCase::assertEqual('openai', CR_AI_Client::instance()->get_default_provider(), 'First registered is default');

    // Change default
    CR_AI_Client::instance()->set_default_provider('anthropic');
    TestCase::assertEqual('anthropic', CR_AI_Client::instance()->get_default_provider(), 'set_default_provider works');

    // Default models
    CR_AI_Client::instance()->set_default_model('openai', 'gpt-4o-mini');
    TestCase::assertEqual('gpt-4o-mini', CR_AI_Client::instance()->get_default_model('openai'), 'set_default_model works');

    // Prompt builder - fluent interface
    $builder = cr_ai();
    TestCase::assertInstanceOf(CR_AI_Prompt_Builder::class, $builder, 'cr_ai() returns PromptBuilder');

    // Build a prompt
    $builder = cr_ai()
        ->provider('openai')
        ->model('gpt-4o')
        ->system('You are helpful.')
        ->user('Hello')
        ->temperature(0.5)
        ->max_tokens(100)
        ->json_mode(true);

    $params = $builder->get_params();
    TestCase::assertEqual('openai', $params['provider'], 'Prompt has correct provider');
    TestCase::assertEqual('gpt-4o', $params['model'], 'Prompt has correct model');
    TestCase::assertCount(2, $params['messages'], 'Prompt has 2 messages');
    TestCase::assertEqual('system', $params['messages'][0]['role'], 'First message is system');
    TestCase::assertEqual('user', $params['messages'][1]['role'], 'Second message is user');
    TestCase::assertEqual(0.5, $params['temperature'], 'Temperature set correctly');
    TestCase::assertEqual(100, $params['max_tokens'], 'Max tokens set correctly');
    TestCase::assertTrue($params['json_mode'], 'JSON mode enabled');

    // Prompt with tools
    $tools = [
        ['name' => 'get_weather', 'description' => 'Get weather', 'input_schema' => ['type' => 'object']],
    ];
    $builder = cr_ai()->tools($tools);
    $params = $builder->get_params();
    TestCase::assertCount(1, $params['tools'], 'Tools attached to prompt');
    TestCase::assertEqual('get_weather', $params['tools'][0]['name'], 'Tool name correct');

    // Multi-turn conversation
    $builder = cr_ai()
        ->system('Context')
        ->user('Question 1')
        ->assistant('Answer 1')
        ->user('Follow-up');
    $params = $builder->get_params();
    TestCase::assertCount(4, $params['messages'], 'Multi-turn has 4 messages');

    // CR_AI_Response object
    $response = new CR_AI_Response(
        success: true, content: 'Hello!', tool_calls: [],
        usage: ['prompt_tokens' => 10, 'completion_tokens' => 5],
        error: null, model: 'gpt-4o', finish_reason: 'stop', raw: [],
    );
    TestCase::assertTrue($response->success, 'Response success true');
    TestCase::assertEqual('Hello!', $response->content, 'Response content correct');
    TestCase::assertFalse($response->has_tool_calls(), 'No tool calls');
    TestCase::assertEqual('gpt-4o', $response->model, 'Response model correct');

    // Response with tool calls
    $response = new CR_AI_Response(
        success: true, content: '', tool_calls: [
            ['id' => 'tc_1', 'name' => 'get_post', 'args' => ['post_id' => 1]],
            ['id' => 'tc_2', 'name' => 'search', 'args' => ['query' => 'test']],
        ],
        usage: [], error: null, model: 'claude-sonnet-4-6', finish_reason: 'tool_use', raw: [],
    );
    TestCase::assertTrue($response->has_tool_calls(), 'Has tool calls');
    TestCase::assertCount(2, $response->tool_calls, 'Two tool calls');
    $tc = $response->get_tool_call(0);
    TestCase::assertEqual('get_post', $tc['name'], 'First tool call name correct');
    TestCase::assertEqual(1, $tc['args']['post_id'], 'First tool call args correct');

    // Error response
    $error = CR_AI_Response::from_error('API timeout');
    TestCase::assertFalse($error->success, 'Error response not success');
    TestCase::assertEqual('API timeout', $error->error, 'Error message correct');
    TestCase::assertEmpty($error->content, 'Error has no content');

    // Validate config
    $valid_openai = new CR_AI_Connector_OpenAI('sk-proj-abcdef123456789');
    TestCase::assertTrue($valid_openai->validate_config(), 'Valid OpenAI key passes validation');

    $invalid_openai = new CR_AI_Connector_OpenAI('');
    TestCase::assertFalse($invalid_openai->validate_config(), 'Empty key fails validation');

    $valid_anthropic = new CR_AI_Connector_Anthropic('sk-ant-api03-valid-key-here');
    TestCase::assertTrue($valid_anthropic->validate_config(), 'Valid Anthropic key passes validation');

    $invalid_anthropic = new CR_AI_Connector_Anthropic('wrong-prefix');
    TestCase::assertFalse($invalid_anthropic->validate_config(), 'Wrong prefix fails validation');

    CR_AI_Client::instance()->reset();
}
