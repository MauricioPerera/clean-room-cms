<?php
/**
 * Clean Room CMS - MCP Adapter (Model Context Protocol)
 *
 * Exposes WordPress abilities via MCP so external AI assistants
 * (Claude, ChatGPT, etc.) can discover and invoke site capabilities.
 *
 * MCP Spec: https://spec.modelcontextprotocol.io/
 *
 * Endpoints:
 *   GET  /mcp/         → Server info + capabilities
 *   GET  /mcp/tools    → List available tools (abilities)
 *   POST /mcp/execute  → Execute a tool (ability)
 *   GET  /mcp/resources → List resources (content guidelines, site info)
 *   GET  /mcp/prompts  → List available prompt templates
 */

class CR_MCP_Adapter {
    private string $version = '2024-11-05';

    /**
     * Handle an MCP request.
     */
    public function handle(string $path, string $method): void {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }

        // Auth check - MCP requires authentication
        $this->authenticate();

        $path = trim($path, '/');

        try {
            $result = match (true) {
                $path === '' || $path === 'mcp'
                    => $this->server_info(),
                $path === 'tools' && $method === 'GET'
                    => $this->list_tools(),
                $path === 'execute' && $method === 'POST'
                    => $this->execute_tool(),
                $path === 'resources' && $method === 'GET'
                    => $this->list_resources(),
                ($method === 'GET' && str_starts_with($path, 'resources/'))
                    => $this->read_resource(substr($path, 10)),
                $path === 'prompts' && $method === 'GET'
                    => $this->list_prompts(),
                default
                    => ['error' => ['code' => -32601, 'message' => 'Method not found']],
            };
        } catch (\Throwable $e) {
            $result = ['error' => ['code' => -32603, 'message' => $e->getMessage()]];
        }

        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Server capabilities and info.
     */
    private function server_info(): array {
        return [
            'protocolVersion' => $this->version,
            'serverInfo' => [
                'name'    => get_option('blogname', 'Clean Room CMS'),
                'version' => defined('CR_VERSION') ? CR_VERSION : '1.0.0',
            ],
            'capabilities' => [
                'tools'     => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts'   => ['listChanged' => false],
            ],
        ];
    }

    /**
     * List all available tools (from Abilities API).
     */
    private function list_tools(): array {
        $abilities = CR_Abilities::get_all();
        $tools = [];

        foreach ($abilities as $name => $ability) {
            // Skip abilities that require permissions the current user doesn't have
            if (!empty($ability['permission']) && !current_user_can($ability['permission'])) {
                continue;
            }

            $tools[] = [
                'name'        => $name,
                'description' => $ability['description'],
                'inputSchema' => $ability['input_schema'],
            ];
        }

        return ['tools' => $tools];
    }

    /**
     * Execute a tool (ability).
     */
    private function execute_tool(): array {
        $raw = file_get_contents('php://input');
        $request = json_decode($raw, true);

        if (!$request || empty($request['name'])) {
            return ['error' => ['code' => -32602, 'message' => 'Missing tool name']];
        }

        $name = $request['name'];
        $args = $request['arguments'] ?? [];

        if (!CR_Abilities::exists($name)) {
            return ['error' => ['code' => -32602, 'message' => "Unknown tool: {$name}"]];
        }

        $result = CR_Abilities::execute($name, $args);

        if (isset($result['error'])) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => json_encode($result)],
                ],
                'isError' => true,
            ];
        }

        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)],
            ],
        ];
    }

    /**
     * List available resources.
     */
    private function list_resources(): array {
        $resources = [
            [
                'uri'         => 'site://guidelines',
                'name'        => 'Content Guidelines',
                'description' => 'Editorial standards for content creation on this site',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'site://info',
                'name'        => 'Site Information',
                'description' => 'Basic information about this website',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'site://posts/recent',
                'name'        => 'Recent Posts',
                'description' => 'Most recent published posts',
                'mimeType'    => 'application/json',
            ],
        ];

        return ['resources' => $resources];
    }

    /**
     * Read a specific resource.
     */
    private function read_resource(string $uri): array {
        $content = match ($uri) {
            'guidelines' => json_encode(cr_guidelines_as_structured(), JSON_UNESCAPED_UNICODE),
            'info' => json_encode([
                'name'        => get_option('blogname', ''),
                'description' => get_option('blogdescription', ''),
                'url'         => CR_HOME_URL,
                'version'     => defined('CR_VERSION') ? CR_VERSION : '1.0.0',
            ]),
            'posts/recent' => json_encode($this->get_recent_posts()),
            default => null,
        };

        if ($content === null) {
            return ['error' => ['code' => -32602, 'message' => "Unknown resource: {$uri}"]];
        }

        return [
            'contents' => [
                [
                    'uri'      => "site://{$uri}",
                    'mimeType' => 'application/json',
                    'text'     => $content,
                ],
            ],
        ];
    }

    /**
     * List available prompt templates.
     */
    private function list_prompts(): array {
        $prompts = [
            [
                'name'        => 'write_post',
                'description' => 'Write a blog post following the site content guidelines',
                'arguments'   => [
                    ['name' => 'topic', 'description' => 'The topic to write about', 'required' => true],
                    ['name' => 'tone', 'description' => 'Override tone (optional)', 'required' => false],
                ],
            ],
            [
                'name'        => 'summarize',
                'description' => 'Summarize an existing post',
                'arguments'   => [
                    ['name' => 'post_id', 'description' => 'ID of the post to summarize', 'required' => true],
                ],
            ],
            [
                'name'        => 'seo_optimize',
                'description' => 'Suggest SEO improvements for a post',
                'arguments'   => [
                    ['name' => 'post_id', 'description' => 'ID of the post to optimize', 'required' => true],
                ],
            ],
        ];

        return ['prompts' => $prompts];
    }

    private function get_recent_posts(): array {
        $q = new CR_Query([
            'post_type' => 'post', 'post_status' => 'publish',
            'posts_per_page' => 10, 'orderby' => 'date', 'order' => 'DESC',
        ]);
        return array_map(fn($p) => [
            'id' => (int) $p->ID, 'title' => $p->post_title,
            'date' => $p->post_date, 'url' => get_permalink($p),
        ], $q->posts);
    }

    /**
     * Authenticate the MCP request (Application Passwords or API key).
     */
    private function authenticate(): void {
        // Basic Auth
        if (!empty($_SERVER['PHP_AUTH_USER'])) {
            $user_id = cr_authenticate($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ?? '');
            if ($user_id) {
                global $cr_current_user;
                $cr_current_user = get_userdata($user_id);
                return;
            }
        }

        // Bearer token (stored in options)
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
            $token = $m[1];
            $stored = get_option('cr_mcp_api_key', '');
            if (!empty($stored) && hash_equals($stored, $token)) {
                global $cr_current_user;
                $cr_current_user = get_userdata(1); // API key = admin
                return;
            }
        }

        // No auth = limited access (public abilities only)
    }
}

/**
 * Register MCP routes in the REST API.
 */
function cr_register_mcp_routes(): void {
    add_action('cr_handle_mcp', function (string $path) {
        $adapter = new CR_MCP_Adapter();
        $adapter->handle($path, $_SERVER['REQUEST_METHOD']);
    });
}
