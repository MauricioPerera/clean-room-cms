<?php
/**
 * Clean Room CMS - AI Client SDK
 *
 * Provider-agnostic PHP API for sending prompts to AI models.
 * Unlike WordPress 7.0's AI Client, ours enforces sandbox permissions
 * so AI connectors can only access what they're explicitly allowed to.
 *
 * Architecture:
 *   CR_AI_Client (orchestrator)
 *     → CR_AI_Connector (provider interface)
 *       → CR_AI_Connector_OpenAI
 *       → CR_AI_Connector_Anthropic
 *       → CR_AI_Connector_Ollama (local)
 *     → CR_AI_Prompt_Builder (fluent prompt construction)
 *     → CR_AI_Response (structured response)
 *
 * Usage:
 *   $response = cr_ai()
 *       ->model('gpt-4o')
 *       ->system('You are a helpful assistant.')
 *       ->user('Summarize this post: ...')
 *       ->temperature(0.7)
 *       ->tools(cr_get_abilities_as_tools())
 *       ->send();
 */

// -- Response object --

class CR_AI_Response {
    public function __construct(
        public readonly bool $success,
        public readonly string $content,
        public readonly array $tool_calls,
        public readonly array $usage,
        public readonly ?string $error,
        public readonly ?string $model,
        public readonly ?string $finish_reason,
        public readonly array $raw,
    ) {}

    public static function from_error(string $message): self {
        return new self(
            success: false, content: '', tool_calls: [], usage: [],
            error: $message, model: null, finish_reason: null, raw: [],
        );
    }

    public function has_tool_calls(): bool {
        return !empty($this->tool_calls);
    }

    public function get_tool_call(int $index = 0): ?array {
        return $this->tool_calls[$index] ?? null;
    }
}

// -- Connector interface --

interface CR_AI_Connector {
    public function get_id(): string;
    public function get_name(): string;
    public function get_models(): array;
    public function send(array $params): CR_AI_Response;
    public function validate_config(): bool;
}

// -- OpenAI Connector --

class CR_AI_Connector_OpenAI implements CR_AI_Connector {
    private string $api_key;
    private string $base_url;

    public function __construct(string $api_key, string $base_url = 'https://api.openai.com/v1') {
        $this->api_key = $api_key;
        $this->base_url = rtrim($base_url, '/');
    }

    public function get_id(): string { return 'openai'; }
    public function get_name(): string { return 'OpenAI'; }
    public function get_models(): array {
        return ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo', 'o1', 'o1-mini', 'o3-mini'];
    }

    public function send(array $params): CR_AI_Response {
        $body = [
            'model'       => $params['model'] ?? 'gpt-4o-mini',
            'messages'    => $params['messages'] ?? [],
            'temperature' => $params['temperature'] ?? 0.7,
            'max_tokens'  => $params['max_tokens'] ?? 2048,
        ];

        if (!empty($params['tools'])) {
            $body['tools'] = array_map(fn($t) => [
                'type' => 'function',
                'function' => [
                    'name'        => $t['name'],
                    'description' => $t['description'] ?? '',
                    'parameters'  => $t['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ], $params['tools']);
        }

        if (!empty($params['json_mode'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        return $this->request('/chat/completions', $body);
    }

    public function validate_config(): bool {
        return !empty($this->api_key) && strlen($this->api_key) > 10;
    }

    private function request(string $endpoint, array $body): CR_AI_Response {
        $url = $this->base_url . $endpoint;
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAuthorization: Bearer {$this->api_key}\r\n",
                'content' => $json,
                'timeout' => 120,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return CR_AI_Response::from_error('Failed to connect to OpenAI API');
        }

        $data = json_decode($response, true);
        if (!$data || isset($data['error'])) {
            return CR_AI_Response::from_error($data['error']['message'] ?? 'Unknown API error');
        }

        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $tool_calls = [];
        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                $tool_calls[] = [
                    'id'   => $tc['id'] ?? '',
                    'name' => $tc['function']['name'] ?? '',
                    'args' => json_decode($tc['function']['arguments'] ?? '{}', true),
                ];
            }
        }

        return new CR_AI_Response(
            success: true,
            content: $message['content'] ?? '',
            tool_calls: $tool_calls,
            usage: $data['usage'] ?? [],
            error: null,
            model: $data['model'] ?? $body['model'],
            finish_reason: $choice['finish_reason'] ?? null,
            raw: $data,
        );
    }
}

// -- Anthropic Connector --

class CR_AI_Connector_Anthropic implements CR_AI_Connector {
    private string $api_key;

    public function __construct(string $api_key) {
        $this->api_key = $api_key;
    }

    public function get_id(): string { return 'anthropic'; }
    public function get_name(): string { return 'Anthropic'; }
    public function get_models(): array {
        return ['claude-opus-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001'];
    }

    public function send(array $params): CR_AI_Response {
        $messages = $params['messages'] ?? [];
        $system = '';

        // Extract system message (Anthropic uses separate field)
        $filtered = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system = $msg['content'];
            } else {
                $filtered[] = $msg;
            }
        }

        $body = [
            'model'      => $params['model'] ?? 'claude-sonnet-4-6',
            'max_tokens' => $params['max_tokens'] ?? 2048,
            'messages'   => $filtered,
        ];

        if ($system) {
            $body['system'] = $system;
        }

        if (isset($params['temperature'])) {
            $body['temperature'] = $params['temperature'];
        }

        if (!empty($params['tools'])) {
            $body['tools'] = array_map(fn($t) => [
                'name'         => $t['name'],
                'description'  => $t['description'] ?? '',
                'input_schema' => $t['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ], $params['tools']);
        }

        return $this->request($body);
    }

    public function validate_config(): bool {
        return !empty($this->api_key) && str_starts_with($this->api_key, 'sk-ant-');
    }

    private function request(array $body): CR_AI_Response {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nx-api-key: {$this->api_key}\r\nanthropic-version: 2023-06-01\r\n",
                'content' => $json,
                'timeout' => 120,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents('https://api.anthropic.com/v1/messages', false, $context);
        if ($response === false) {
            return CR_AI_Response::from_error('Failed to connect to Anthropic API');
        }

        $data = json_decode($response, true);
        if (!$data || ($data['type'] ?? '') === 'error') {
            return CR_AI_Response::from_error($data['error']['message'] ?? 'Unknown API error');
        }

        $content = '';
        $tool_calls = [];
        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $tool_calls[] = [
                    'id'   => $block['id'] ?? '',
                    'name' => $block['name'] ?? '',
                    'args' => $block['input'] ?? [],
                ];
            }
        }

        return new CR_AI_Response(
            success: true,
            content: $content,
            tool_calls: $tool_calls,
            usage: $data['usage'] ?? [],
            error: null,
            model: $data['model'] ?? $body['model'],
            finish_reason: $data['stop_reason'] ?? null,
            raw: $data,
        );
    }
}

// -- Ollama Connector (local AI) --

class CR_AI_Connector_Ollama implements CR_AI_Connector {
    private string $base_url;

    public function __construct(string $base_url = 'http://localhost:11434') {
        $this->base_url = rtrim($base_url, '/');
    }

    public function get_id(): string { return 'ollama'; }
    public function get_name(): string { return 'Ollama (Local)'; }
    public function get_models(): array { return ['llama3', 'mistral', 'codellama', 'phi3']; }

    public function send(array $params): CR_AI_Response {
        $body = [
            'model'    => $params['model'] ?? 'llama3',
            'messages' => $params['messages'] ?? [],
            'stream'   => false,
            'options'  => [
                'temperature' => $params['temperature'] ?? 0.7,
                'num_predict' => $params['max_tokens'] ?? 2048,
            ],
        ];

        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 300,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($this->base_url . '/api/chat', false, $context);
        if ($response === false) {
            return CR_AI_Response::from_error('Failed to connect to Ollama (is it running?)');
        }

        $data = json_decode($response, true);
        if (!$data || isset($data['error'])) {
            return CR_AI_Response::from_error($data['error'] ?? 'Unknown Ollama error');
        }

        return new CR_AI_Response(
            success: true,
            content: $data['message']['content'] ?? '',
            tool_calls: [],
            usage: [
                'prompt_tokens' => $data['prompt_eval_count'] ?? 0,
                'completion_tokens' => $data['eval_count'] ?? 0,
            ],
            error: null,
            model: $data['model'] ?? $body['model'],
            finish_reason: $data['done'] ? 'stop' : 'length',
            raw: $data,
        );
    }

    public function validate_config(): bool {
        $response = @file_get_contents($this->base_url . '/api/tags');
        return $response !== false;
    }
}

// -- Prompt Builder --

class CR_AI_Prompt_Builder {
    private array $messages = [];
    private ?string $model = null;
    private ?string $provider = null;
    private float $temperature = 0.7;
    private int $max_tokens = 2048;
    private array $tools = [];
    private bool $json_mode = false;
    private array $guidelines = [];

    public function provider(string $provider): self {
        $this->provider = $provider;
        return $this;
    }

    public function model(string $model): self {
        $this->model = $model;
        return $this;
    }

    public function system(string $content): self {
        $this->messages[] = ['role' => 'system', 'content' => $content];
        return $this;
    }

    public function user(string $content): self {
        $this->messages[] = ['role' => 'user', 'content' => $content];
        return $this;
    }

    public function assistant(string $content): self {
        $this->messages[] = ['role' => 'assistant', 'content' => $content];
        return $this;
    }

    public function temperature(float $temp): self {
        $this->temperature = max(0, min(2, $temp));
        return $this;
    }

    public function max_tokens(int $tokens): self {
        $this->max_tokens = max(1, min(100000, $tokens));
        return $this;
    }

    public function tools(array $tools): self {
        $this->tools = $tools;
        return $this;
    }

    public function json_mode(bool $enabled = true): self {
        $this->json_mode = $enabled;
        return $this;
    }

    public function with_guidelines(): self {
        $guidelines = cr_get_content_guidelines();
        if (!empty($guidelines)) {
            $text = "Content Guidelines for this site:\n";
            foreach ($guidelines as $section => $rules) {
                if (!empty($rules)) {
                    $text .= "\n## {$section}\n{$rules}\n";
                }
            }
            // Prepend as system message
            array_unshift($this->messages, ['role' => 'system', 'content' => $text]);
        }
        return $this;
    }

    public function send(): CR_AI_Response {
        $client = CR_AI_Client::instance();

        // Resolve provider
        $provider_id = $this->provider ?? $client->get_default_provider();
        $connector = $client->get_connector($provider_id);

        if (!$connector) {
            return CR_AI_Response::from_error("No AI connector found for provider '{$provider_id}'");
        }

        // Sandbox check
        if (CR_Sandbox::current_plugin() !== null) {
            CR_Sandbox::enforce('http:outbound');
        }

        $params = [
            'model'       => $this->model ?? $client->get_default_model($provider_id),
            'messages'    => $this->messages,
            'temperature' => $this->temperature,
            'max_tokens'  => $this->max_tokens,
            'tools'       => $this->tools,
            'json_mode'   => $this->json_mode,
        ];

        $params = apply_filters('cr_ai_before_send', $params, $provider_id);

        $response = $connector->send($params);

        do_action('cr_ai_after_send', $response, $params, $provider_id);

        // Log usage
        if ($response->success && !empty($response->usage)) {
            do_action('cr_ai_usage', $provider_id, $response->model, $response->usage);
        }

        return $response;
    }

    public function get_params(): array {
        return [
            'messages'    => $this->messages,
            'model'       => $this->model,
            'provider'    => $this->provider,
            'temperature' => $this->temperature,
            'max_tokens'  => $this->max_tokens,
            'tools'       => $this->tools,
            'json_mode'   => $this->json_mode,
        ];
    }
}

// -- AI Client (singleton orchestrator) --

class CR_AI_Client {
    private array $connectors = [];
    private ?string $default_provider = null;
    private array $default_models = [];
    private static ?CR_AI_Client $instance = null;

    public static function instance(): CR_AI_Client {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_connector(CR_AI_Connector $connector): void {
        $this->connectors[$connector->get_id()] = $connector;

        if ($this->default_provider === null) {
            $this->default_provider = $connector->get_id();
        }

        do_action('cr_ai_connector_registered', $connector);
    }

    public function get_connector(string $provider_id): ?CR_AI_Connector {
        return $this->connectors[$provider_id] ?? null;
    }

    public function get_connectors(): array {
        return $this->connectors;
    }

    public function set_default_provider(string $provider_id): void {
        $this->default_provider = $provider_id;
    }

    public function get_default_provider(): ?string {
        return $this->default_provider;
    }

    public function set_default_model(string $provider_id, string $model): void {
        $this->default_models[$provider_id] = $model;
    }

    public function get_default_model(string $provider_id): string {
        return $this->default_models[$provider_id] ?? ($this->connectors[$provider_id]?->get_models()[0] ?? 'default');
    }

    public function prompt(): CR_AI_Prompt_Builder {
        return new CR_AI_Prompt_Builder();
    }

    public function reset(): void {
        $this->connectors = [];
        $this->default_provider = null;
        $this->default_models = [];
        self::$instance = null;
    }
}

// -- Global helper --

function cr_ai(): CR_AI_Prompt_Builder {
    return CR_AI_Client::instance()->prompt();
}

function cr_ai_client(): CR_AI_Client {
    return CR_AI_Client::instance();
}

/**
 * Initialize connectors from saved settings.
 */
function cr_ai_init_connectors(): void {
    $settings = get_option('cr_ai_connectors', []);
    if (!is_array($settings)) return;

    $client = CR_AI_Client::instance();

    foreach ($settings as $provider => $config) {
        if (empty($config['enabled'])) continue;

        $connector = match ($provider) {
            'openai'    => new CR_AI_Connector_OpenAI($config['api_key'] ?? '', $config['base_url'] ?? 'https://api.openai.com/v1'),
            'anthropic' => new CR_AI_Connector_Anthropic($config['api_key'] ?? ''),
            'ollama'    => new CR_AI_Connector_Ollama($config['base_url'] ?? 'http://localhost:11434'),
            default     => null,
        };

        if ($connector) {
            $client->register_connector($connector);
        }
    }

    $default = get_option('cr_ai_default_provider', '');
    if ($default && $client->get_connector($default)) {
        $client->set_default_provider($default);
    }

    do_action('cr_ai_connectors_loaded', $client);
}
