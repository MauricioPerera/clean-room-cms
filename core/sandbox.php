<?php
/**
 * Clean Room CMS - Plugin Sandbox System
 *
 * Unlike WordPress's full-trust model where every plugin has access to everything,
 * this system enforces granular permissions per plugin. A plugin must declare
 * what capabilities it needs, and the admin must approve them.
 *
 * Capabilities:
 *   - db:read          Read from any database table
 *   - db:write         Write to any database table
 *   - db:own           Read/write only to plugin's own tables (prefixed)
 *   - files:read       Read files from disk
 *   - files:write      Write files to disk (uploads, cache)
 *   - options:read     Read site options
 *   - options:write    Write site options
 *   - users:read       Read user data
 *   - users:write      Modify user data
 *   - http:outbound    Make external HTTP requests
 *   - hooks:core       Register hooks on core actions/filters
 *   - admin:pages      Add admin menu pages
 *   - admin:settings   Add settings pages
 *   - rest:endpoints   Register REST API endpoints
 *   - cron:schedule    Schedule async tasks
 *   - content:filter   Filter post content (the_content, the_title, etc.)
 *   - exec:shell       Execute shell commands (DANGEROUS - requires explicit admin approval)
 */

class CR_Sandbox {
    private static array $plugin_permissions = [];
    private static array $plugin_manifests = [];
    private static ?string $current_plugin = null;
    private static array $violations = [];

    /**
     * Register a plugin's manifest (declared permissions).
     */
    public static function register_plugin(string $plugin_slug, array $manifest): void {
        self::$plugin_manifests[$plugin_slug] = $manifest;

        // Load granted permissions from DB
        $granted = get_option("cr_sandbox_{$plugin_slug}", []);
        if (is_array($granted) && !empty($granted)) {
            self::$plugin_permissions[$plugin_slug] = $granted;
        }
    }

    /**
     * Grant permissions to a plugin (admin action).
     */
    public static function grant_permissions(string $plugin_slug, array $permissions): void {
        $manifest = self::$plugin_manifests[$plugin_slug] ?? [];
        $requested = $manifest['permissions'] ?? [];

        // Only grant permissions that were declared in manifest
        $valid = array_intersect($permissions, $requested);
        self::$plugin_permissions[$plugin_slug] = $valid;

        update_option("cr_sandbox_{$plugin_slug}", $valid, 'no');

        do_action('cr_sandbox_permissions_granted', $plugin_slug, $valid);
    }

    /**
     * Revoke all permissions for a plugin.
     */
    public static function revoke_permissions(string $plugin_slug): void {
        unset(self::$plugin_permissions[$plugin_slug]);
        delete_option("cr_sandbox_{$plugin_slug}");
    }

    /**
     * Set the currently executing plugin context.
     */
    public static function enter_context(string $plugin_slug): void {
        self::$current_plugin = $plugin_slug;
    }

    /**
     * Clear the plugin execution context.
     */
    public static function exit_context(): void {
        self::$current_plugin = null;
    }

    /**
     * Get the currently executing plugin.
     */
    public static function current_plugin(): ?string {
        return self::$current_plugin;
    }

    /**
     * Check if the current plugin has a specific permission.
     */
    public static function can(string $permission): bool {
        // No plugin context = core code = allowed
        if (self::$current_plugin === null) {
            return true;
        }

        $plugin = self::$current_plugin;
        $granted = self::$plugin_permissions[$plugin] ?? [];

        if (in_array($permission, $granted, true)) {
            return true;
        }

        // Log violation
        self::$violations[] = [
            'plugin'     => $plugin,
            'permission' => $permission,
            'time'       => time(),
            'trace'      => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
        ];

        do_action('cr_sandbox_violation', $plugin, $permission);

        return false;
    }

    /**
     * Enforce a permission - throws exception if denied.
     */
    public static function enforce(string $permission): void {
        if (!self::can($permission)) {
            $plugin = self::$current_plugin ?? 'unknown';
            throw new CR_Sandbox_Exception(
                "Plugin '{$plugin}' attempted '{$permission}' without permission."
            );
        }
    }

    /**
     * Get manifest for a plugin.
     */
    public static function get_manifest(string $plugin_slug): array {
        return self::$plugin_manifests[$plugin_slug] ?? [];
    }

    /**
     * Get granted permissions for a plugin.
     */
    public static function get_permissions(string $plugin_slug): array {
        return self::$plugin_permissions[$plugin_slug] ?? [];
    }

    /**
     * Get all registered plugins and their permission status.
     */
    public static function get_all_plugins(): array {
        $result = [];
        foreach (self::$plugin_manifests as $slug => $manifest) {
            $result[$slug] = [
                'manifest'    => $manifest,
                'granted'     => self::$plugin_permissions[$slug] ?? [],
                'pending'     => array_diff(
                    $manifest['permissions'] ?? [],
                    self::$plugin_permissions[$slug] ?? []
                ),
            ];
        }
        return $result;
    }

    /**
     * Get recorded violations.
     */
    public static function get_violations(): array {
        return self::$violations;
    }

    /**
     * Check if a plugin has any pending (ungranted) permissions.
     */
    public static function has_pending(string $plugin_slug): bool {
        $manifest = self::$plugin_manifests[$plugin_slug] ?? [];
        $requested = $manifest['permissions'] ?? [];
        $granted = self::$plugin_permissions[$plugin_slug] ?? [];
        return !empty(array_diff($requested, $granted));
    }

    /**
     * Reset sandbox state (for testing).
     */
    public static function reset(): void {
        self::$plugin_permissions = [];
        self::$plugin_manifests = [];
        self::$current_plugin = null;
        self::$violations = [];
    }

    /**
     * Validate a plugin manifest file.
     */
    public static function parse_manifest_file(string $plugin_dir): ?array {
        $manifest_path = $plugin_dir . '/manifest.json';
        if (!file_exists($manifest_path)) {
            return null;
        }

        $json = file_get_contents($manifest_path);
        $manifest = json_decode($json, true);

        if (!is_array($manifest) || empty($manifest['name']) || !isset($manifest['permissions'])) {
            return null;
        }

        // Validate permissions against known list
        $known = self::known_permissions();
        $manifest['permissions'] = array_intersect($manifest['permissions'], $known);

        return $manifest;
    }

    /**
     * List all known permission types.
     */
    public static function known_permissions(): array {
        return [
            'db:read', 'db:write', 'db:own',
            'files:read', 'files:write',
            'options:read', 'options:write',
            'users:read', 'users:write',
            'http:outbound',
            'hooks:core',
            'admin:pages', 'admin:settings',
            'rest:endpoints',
            'cron:schedule',
            'content:filter',
            'exec:shell',
        ];
    }
}

class CR_Sandbox_Exception extends RuntimeException {}

// -- Sandboxed wrappers for core functions --

/**
 * Load plugins with sandbox enforcement.
 */
function cr_load_plugins_sandboxed(): void {
    $active_plugins = get_option('active_plugins', []);
    if (!is_array($active_plugins)) return;

    foreach ($active_plugins as $plugin_file) {
        $path = CR_PLUGIN_PATH . '/' . $plugin_file;
        if (!file_exists($path)) continue;

        $plugin_dir = dirname($path);
        $plugin_slug = basename($plugin_dir);

        // Parse manifest
        $manifest = CR_Sandbox::parse_manifest_file($plugin_dir);
        if ($manifest) {
            CR_Sandbox::register_plugin($plugin_slug, $manifest);
        }

        // Enter sandboxed context
        CR_Sandbox::enter_context($plugin_slug);

        try {
            require_once $path;
        } catch (CR_Sandbox_Exception $e) {
            error_log("Sandbox blocked plugin '{$plugin_slug}': " . $e->getMessage());
        } finally {
            CR_Sandbox::exit_context();
        }
    }
}

/**
 * Sandboxed database query - checks permission before executing.
 */
function cr_sandboxed_query(string $sql): mixed {
    $is_write = preg_match('/^\s*(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE)/i', $sql);

    if ($is_write) {
        CR_Sandbox::enforce('db:write');
    } else {
        CR_Sandbox::enforce('db:read');
    }

    return cr_db()->query($sql);
}

/**
 * Sandboxed HTTP request.
 */
function cr_http_request(string $url, array $args = []): array|false {
    CR_Sandbox::enforce('http:outbound');

    $method = strtoupper($args['method'] ?? 'GET');
    $headers = $args['headers'] ?? [];
    $body = $args['body'] ?? null;
    $timeout = $args['timeout'] ?? 30;

    $context = stream_context_create([
        'http' => [
            'method'  => $method,
            'header'  => implode("\r\n", array_map(fn($k, $v) => "{$k}: {$v}", array_keys($headers), $headers)),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return false;
    }

    $status = 200;
    if (isset($http_response_header[0])) {
        preg_match('/\d{3}/', $http_response_header[0], $m);
        $status = (int) ($m[0] ?? 200);
    }

    return [
        'status'  => $status,
        'headers' => $http_response_header ?? [],
        'body'    => $response,
    ];
}
