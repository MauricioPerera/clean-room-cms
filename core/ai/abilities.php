<?php
/**
 * Clean Room CMS - Abilities API
 *
 * Central registry for site capabilities. Each ability is:
 *   - Human-readable (name + description)
 *   - Machine-readable (JSON Schema for input/output)
 *   - Callable (callback function)
 *   - Projectable to AI tool declarations (OpenAI, Anthropic, MCP)
 *
 * Plugins register what they CAN do. AI models discover and invoke them.
 *
 * register_ability('summarize_post', [
 *     'description'   => 'Generate a summary of a post',
 *     'input_schema'  => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']],
 *     'output_schema' => ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']]],
 *     'callback'      => function($input) { return ['summary' => '...']; },
 *     'category'      => 'content',
 *     'permission'    => 'edit_posts',
 * ]);
 */

class CR_Abilities {
    private static array $abilities = [];

    /**
     * Register an ability.
     */
    public static function register(string $name, array $args): bool {
        if (empty($args['callback']) || !is_callable($args['callback'])) {
            return false;
        }

        $defaults = [
            'name'          => $name,
            'description'   => '',
            'input_schema'  => ['type' => 'object', 'properties' => new \stdClass()],
            'output_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            'callback'      => null,
            'category'      => 'general',
            'permission'    => '',
            'is_async'      => false,
        ];

        self::$abilities[$name] = array_merge($defaults, $args);
        self::$abilities[$name]['name'] = $name;

        do_action('cr_ability_registered', $name, self::$abilities[$name]);
        return true;
    }

    /**
     * Unregister an ability.
     */
    public static function unregister(string $name): bool {
        if (!isset(self::$abilities[$name])) return false;
        unset(self::$abilities[$name]);
        return true;
    }

    /**
     * Check if an ability exists.
     */
    public static function exists(string $name): bool {
        return isset(self::$abilities[$name]);
    }

    /**
     * Get an ability definition.
     */
    public static function get(string $name): ?array {
        return self::$abilities[$name] ?? null;
    }

    /**
     * Get all registered abilities.
     */
    public static function get_all(string $category = ''): array {
        if (empty($category)) return self::$abilities;

        return array_filter(self::$abilities, fn($a) => $a['category'] === $category);
    }

    /**
     * Get ability categories.
     */
    public static function get_categories(): array {
        $cats = [];
        foreach (self::$abilities as $ability) {
            $cats[$ability['category']] = true;
        }
        return array_keys($cats);
    }

    /**
     * Execute an ability with input validation.
     */
    public static function execute(string $name, array $input = []): array {
        $ability = self::$abilities[$name] ?? null;

        if (!$ability) {
            return ['error' => 'ability_not_found', 'message' => "Ability '{$name}' not registered."];
        }

        // Permission check
        if (!empty($ability['permission']) && !current_user_can($ability['permission'])) {
            return ['error' => 'ability_forbidden', 'message' => "Insufficient permissions for ability '{$name}'."];
        }

        // Validate input against schema
        $input_error = self::validate_schema($input, $ability['input_schema'], 'input');
        if ($input_error) {
            return ['error' => 'ability_invalid_input', 'message' => $input_error];
        }

        // Execute
        try {
            $output = call_user_func($ability['callback'], $input);

            if (!is_array($output)) {
                $output = ['result' => $output];
            }

            // Validate output against schema
            $output_error = self::validate_schema($output, $ability['output_schema'], 'output');
            if ($output_error) {
                return ['error' => 'ability_invalid_output', 'message' => $output_error];
            }

            do_action('cr_ability_executed', $name, $input, $output);

            return $output;

        } catch (\Throwable $e) {
            return ['error' => 'ability_execution_error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Validate data against a JSON Schema (simplified validation).
     */
    public static function validate_schema(array $data, array $schema, string $context): ?string {
        $type = $schema['type'] ?? 'object';

        if ($type !== 'object') return null;

        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        // Check required fields
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                return "Missing required {$context} field: '{$field}'";
            }
        }

        // Type-check properties
        if (is_array($properties)) {
            foreach ($properties as $field => $prop_schema) {
                if (!array_key_exists($field, $data)) continue;

                $expected_type = $prop_schema['type'] ?? null;
                if ($expected_type === null) continue;

                $value = $data[$field];
                $valid = match ($expected_type) {
                    'string'  => is_string($value),
                    'integer' => is_int($value),
                    'number'  => is_int($value) || is_float($value),
                    'boolean' => is_bool($value),
                    'array'   => is_array($value) && array_is_list($value),
                    'object'  => is_array($value) && !array_is_list($value),
                    default   => true,
                };

                if (!$valid) {
                    return "Invalid type for {$context} field '{$field}': expected {$expected_type}";
                }

                // Enum check
                if (isset($prop_schema['enum']) && !in_array($value, $prop_schema['enum'], true)) {
                    $allowed = implode(', ', $prop_schema['enum']);
                    return "Invalid value for {$context} field '{$field}': must be one of [{$allowed}]";
                }
            }
        }

        return null; // Valid
    }

    /**
     * Project abilities as AI tool declarations (for function calling).
     * Compatible with OpenAI and Anthropic tool_use format.
     */
    public static function as_tool_declarations(string $category = ''): array {
        $abilities = self::get_all($category);
        $tools = [];

        foreach ($abilities as $name => $ability) {
            $tools[] = [
                'name'         => $name,
                'description'  => $ability['description'],
                'input_schema' => $ability['input_schema'],
            ];
        }

        return $tools;
    }

    /**
     * Process tool calls from an AI response - execute matching abilities.
     */
    public static function handle_tool_calls(array $tool_calls): array {
        $results = [];

        foreach ($tool_calls as $call) {
            $name = $call['name'] ?? '';
            $args = $call['args'] ?? [];
            $id = $call['id'] ?? '';

            $output = self::execute($name, $args);

            $results[] = [
                'tool_call_id' => $id,
                'name'         => $name,
                'output'       => $output,
            ];
        }

        return $results;
    }

    /**
     * Reset (for testing).
     */
    public static function reset(): void {
        self::$abilities = [];
    }
}

// -- Global functions --

function register_ability(string $name, array $args): bool {
    return CR_Abilities::register($name, $args);
}

function execute_ability(string $name, array $input = []): array {
    return CR_Abilities::execute($name, $input);
}

function ability_exists(string $name): bool {
    return CR_Abilities::exists($name);
}

function cr_get_abilities_as_tools(string $category = ''): array {
    return CR_Abilities::as_tool_declarations($category);
}

// -- Register core abilities --

function cr_register_core_abilities(): void {
    register_ability('get_post', [
        'description'  => 'Retrieve a post by ID including title, content, and metadata.',
        'category'     => 'content',
        'permission'   => 'read',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'post_id' => ['type' => 'integer', 'description' => 'The post ID'],
            ],
            'required' => ['post_id'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'id'      => ['type' => 'integer'],
                'title'   => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'status'  => ['type' => 'string'],
                'author'  => ['type' => 'string'],
                'date'    => ['type' => 'string'],
            ],
        ],
        'callback' => function (array $input): array {
            $post = get_post((int) $input['post_id']);
            if (!$post) return ['error' => 'not_found', 'message' => 'Post not found'];
            return [
                'id'      => (int) $post->ID,
                'title'   => $post->post_title,
                'content' => $post->post_content,
                'status'  => $post->post_status,
                'author'  => get_the_author($post),
                'date'    => $post->post_date,
            ];
        },
    ]);

    register_ability('create_post', [
        'description'  => 'Create a new post or page.',
        'category'     => 'content',
        'permission'   => 'publish_posts',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'title'     => ['type' => 'string', 'description' => 'Post title'],
                'content'   => ['type' => 'string', 'description' => 'Post content (HTML)'],
                'status'    => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending'], 'description' => 'Post status'],
                'post_type' => ['type' => 'string', 'enum' => ['post', 'page'], 'description' => 'Content type'],
            ],
            'required' => ['title', 'content'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'id'    => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'slug'  => ['type' => 'string'],
                'url'   => ['type' => 'string'],
            ],
        ],
        'callback' => function (array $input): array {
            $id = cr_insert_post([
                'post_title'   => $input['title'],
                'post_content' => CR_Security::sanitize_html($input['content'], 'post'),
                'post_status'  => $input['status'] ?? 'draft',
                'post_type'    => $input['post_type'] ?? 'post',
                'post_author'  => get_current_user_id(),
            ]);
            if (!$id) return ['error' => 'create_failed', 'message' => 'Failed to create post'];
            $post = get_post($id);
            return ['id' => $id, 'title' => $post->post_title, 'slug' => $post->post_name, 'url' => get_permalink($id)];
        },
    ]);

    register_ability('search_content', [
        'description'  => 'Search posts and pages by keyword.',
        'category'     => 'content',
        'permission'   => 'read',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'query'    => ['type' => 'string', 'description' => 'Search keywords'],
                'per_page' => ['type' => 'integer', 'description' => 'Results per page (max 20)'],
            ],
            'required' => ['query'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'results' => ['type' => 'array'],
                'total'   => ['type' => 'integer'],
            ],
        ],
        'callback' => function (array $input): array {
            $q = new CR_Query([
                'post_type'      => ['post', 'page'],
                'post_status'    => 'publish',
                's'              => $input['query'],
                'posts_per_page' => min((int) ($input['per_page'] ?? 10), 20),
            ]);
            $results = array_map(fn($p) => [
                'id' => (int) $p->ID, 'title' => $p->post_title, 'type' => $p->post_type,
                'excerpt' => mb_substr(strip_tags($p->post_content), 0, 200),
                'url' => get_permalink($p),
            ], $q->posts);
            return ['results' => $results, 'total' => $q->found_posts];
        },
    ]);

    register_ability('get_site_info', [
        'description'  => 'Get basic site information (name, description, URL).',
        'category'     => 'site',
        'permission'   => '',
        'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'name'        => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'url'         => ['type' => 'string'],
            ],
        ],
        'callback' => function (): array {
            return [
                'name'        => get_option('blogname', ''),
                'description' => get_option('blogdescription', ''),
                'url'         => CR_HOME_URL,
            ];
        },
    ]);

    register_ability('generate_excerpt', [
        'description'  => 'Generate or regenerate the excerpt for a post.',
        'category'     => 'content',
        'permission'   => 'edit_posts',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'post_id'    => ['type' => 'integer'],
                'max_length' => ['type' => 'integer', 'description' => 'Maximum words'],
            ],
            'required' => ['post_id'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => ['excerpt' => ['type' => 'string']],
        ],
        'callback' => function (array $input): array {
            $post = get_post((int) $input['post_id']);
            if (!$post) return ['error' => 'not_found'];
            $max = (int) ($input['max_length'] ?? 55);
            $text = strip_tags($post->post_content);
            $words = explode(' ', $text);
            $excerpt = implode(' ', array_slice($words, 0, $max));
            if (count($words) > $max) $excerpt .= '...';
            return ['excerpt' => $excerpt];
        },
    ]);

    do_action('cr_register_abilities');
}
