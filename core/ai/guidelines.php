<?php
/**
 * Clean Room CMS - Content Guidelines
 *
 * Structured editorial standards that shape how content is written by both
 * humans and AI agents. Stored in options, exposed to AI via system prompts
 * and the MCP adapter.
 *
 * Sections:
 *   - site:       Goals, personality, target audience
 *   - copy:       Tone, voice, vocabulary, style rules
 *   - images:     Visual style preferences
 *   - blocks:     Per-block-type content rules
 *   - additional: Anything else
 */

/**
 * Get all content guidelines.
 */
function cr_get_content_guidelines(): array {
    $defaults = [
        'site'       => '',
        'copy'       => '',
        'images'     => '',
        'blocks'     => '',
        'additional' => '',
    ];

    $stored = get_option('cr_content_guidelines', []);
    if (!is_array($stored)) $stored = [];

    return array_merge($defaults, $stored);
}

/**
 * Update a specific guidelines section.
 */
function cr_update_content_guidelines(string $section, string $content): bool {
    $allowed = ['site', 'copy', 'images', 'blocks', 'additional'];
    if (!in_array($section, $allowed, true)) return false;

    $guidelines = cr_get_content_guidelines();
    $guidelines[$section] = $content;

    return update_option('cr_content_guidelines', $guidelines, 'no');
}

/**
 * Update all guidelines at once.
 */
function cr_set_content_guidelines(array $guidelines): bool {
    $allowed = ['site', 'copy', 'images', 'blocks', 'additional'];
    $clean = [];
    foreach ($allowed as $section) {
        $clean[$section] = $guidelines[$section] ?? '';
    }
    return update_option('cr_content_guidelines', $clean, 'no');
}

/**
 * Get guidelines formatted as a system prompt for AI.
 */
function cr_guidelines_as_system_prompt(): string {
    $guidelines = cr_get_content_guidelines();
    $parts = [];

    if (!empty($guidelines['site'])) {
        $parts[] = "## Site Context\n{$guidelines['site']}";
    }
    if (!empty($guidelines['copy'])) {
        $parts[] = "## Writing Style\n{$guidelines['copy']}";
    }
    if (!empty($guidelines['images'])) {
        $parts[] = "## Image Guidelines\n{$guidelines['images']}";
    }
    if (!empty($guidelines['blocks'])) {
        $parts[] = "## Block Rules\n{$guidelines['blocks']}";
    }
    if (!empty($guidelines['additional'])) {
        $parts[] = "## Additional Rules\n{$guidelines['additional']}";
    }

    if (empty($parts)) return '';

    return "You are acting on behalf of this website. Follow these content guidelines strictly:\n\n" . implode("\n\n", $parts);
}

/**
 * Get guidelines as structured data (for MCP/API).
 */
function cr_guidelines_as_structured(): array {
    $guidelines = cr_get_content_guidelines();
    $result = [];

    foreach ($guidelines as $section => $content) {
        if (!empty($content)) {
            $result[] = [
                'section'     => $section,
                'content'     => $content,
                'description' => match ($section) {
                    'site'       => 'Site goals, personality, and target audience',
                    'copy'       => 'Tone, voice, vocabulary, and style rules',
                    'images'     => 'Visual style preferences and constraints',
                    'blocks'     => 'Per-block-type content rules',
                    'additional' => 'Additional editorial guidelines',
                    default      => $section,
                },
            ];
        }
    }

    return $result;
}

// Register guidelines as an ability
add_action('cr_register_abilities', function () {
    register_ability('get_content_guidelines', [
        'description'  => 'Get the editorial content guidelines for this site.',
        'category'     => 'site',
        'permission'   => '',
        'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'guidelines' => ['type' => 'array'],
            ],
        ],
        'callback' => function (): array {
            return ['guidelines' => cr_guidelines_as_structured()];
        },
    ]);

    register_ability('update_content_guidelines', [
        'description'  => 'Update a section of the content guidelines.',
        'category'     => 'site',
        'permission'   => 'manage_options',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'section' => ['type' => 'string', 'enum' => ['site', 'copy', 'images', 'blocks', 'additional']],
                'content' => ['type' => 'string'],
            ],
            'required' => ['section', 'content'],
        ],
        'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']]],
        'callback' => function (array $input): array {
            $result = cr_update_content_guidelines($input['section'], $input['content']);
            return ['success' => $result];
        },
    ]);
});
