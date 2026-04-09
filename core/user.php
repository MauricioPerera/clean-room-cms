<?php
/**
 * Clean Room CMS - User System
 *
 * Authentication, sessions, roles, and capabilities.
 */

// Global current user
$cr_current_user = null;

// -- Roles & Capabilities --

$cr_roles = [];

function cr_register_default_roles(): void {
    global $cr_roles;

    $cr_roles = [
        'administrator' => [
            'name' => 'Administrator',
            'capabilities' => [
                'switch_themes' => true, 'edit_themes' => true, 'activate_plugins' => true,
                'edit_plugins' => true, 'edit_users' => true, 'edit_files' => true,
                'manage_options' => true, 'moderate_comments' => true, 'manage_categories' => true,
                'manage_links' => true, 'upload_files' => true, 'import' => true, 'unfiltered_html' => true,
                'edit_posts' => true, 'edit_others_posts' => true, 'edit_published_posts' => true,
                'publish_posts' => true, 'edit_pages' => true, 'read' => true,
                'edit_others_pages' => true, 'edit_published_pages' => true, 'publish_pages' => true,
                'delete_pages' => true, 'delete_others_pages' => true, 'delete_published_pages' => true,
                'delete_posts' => true, 'delete_others_posts' => true, 'delete_published_posts' => true,
                'delete_private_posts' => true, 'edit_private_posts' => true, 'read_private_posts' => true,
                'delete_private_pages' => true, 'edit_private_pages' => true, 'read_private_pages' => true,
                'delete_users' => true, 'create_users' => true, 'unfiltered_upload' => true,
                'edit_dashboard' => true, 'update_plugins' => true, 'delete_plugins' => true,
                'install_plugins' => true, 'update_themes' => true, 'install_themes' => true,
                'update_core' => true, 'list_users' => true, 'remove_users' => true,
                'promote_users' => true, 'edit_theme_options' => true, 'delete_themes' => true,
                'export' => true,
            ],
        ],
        'editor' => [
            'name' => 'Editor',
            'capabilities' => [
                'moderate_comments' => true, 'manage_categories' => true, 'manage_links' => true,
                'upload_files' => true, 'unfiltered_html' => true, 'edit_posts' => true,
                'edit_others_posts' => true, 'edit_published_posts' => true, 'publish_posts' => true,
                'edit_pages' => true, 'read' => true, 'edit_others_pages' => true,
                'edit_published_pages' => true, 'publish_pages' => true, 'delete_pages' => true,
                'delete_others_pages' => true, 'delete_published_pages' => true, 'delete_posts' => true,
                'delete_others_posts' => true, 'delete_published_posts' => true, 'delete_private_posts' => true,
                'edit_private_posts' => true, 'read_private_posts' => true, 'delete_private_pages' => true,
                'edit_private_pages' => true, 'read_private_pages' => true,
            ],
        ],
        'author' => [
            'name' => 'Author',
            'capabilities' => [
                'upload_files' => true, 'edit_posts' => true, 'edit_published_posts' => true,
                'publish_posts' => true, 'read' => true, 'delete_posts' => true,
                'delete_published_posts' => true,
            ],
        ],
        'contributor' => [
            'name' => 'Contributor',
            'capabilities' => [
                'edit_posts' => true, 'read' => true, 'delete_posts' => true,
            ],
        ],
        'subscriber' => [
            'name' => 'Subscriber',
            'capabilities' => [
                'read' => true,
            ],
        ],
    ];
}

function add_role(string $role, string $display_name, array $capabilities = []): void {
    global $cr_roles;
    $cr_roles[$role] = [
        'name' => $display_name,
        'capabilities' => $capabilities,
    ];
}

function get_role(string $role): ?array {
    global $cr_roles;
    return $cr_roles[$role] ?? null;
}

function remove_role(string $role): void {
    global $cr_roles;
    unset($cr_roles[$role]);
}

// -- User CRUD --

function cr_create_user(string $username, string $password, string $email, array $extra = []): int|false {
    $db = cr_db();
    $table = $db->prefix . 'users';

    // Check uniqueness
    $exists = $db->get_var($db->prepare("SELECT ID FROM `{$table}` WHERE user_login = %s OR user_email = %s LIMIT 1", $username, $email));
    if ($exists) return false;

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $nicename = cr_sanitize_title($username);

    $user_id = $db->insert($table, [
        'user_login'      => $username,
        'user_pass'        => $hash,
        'user_nicename'    => $nicename,
        'user_email'       => $email,
        'user_url'         => $extra['user_url'] ?? '',
        'user_registered'  => gmdate('Y-m-d H:i:s'),
        'user_activation_key' => '',
        'user_status'      => 0,
        'display_name'     => $extra['display_name'] ?? $username,
    ]);

    if ($user_id === false) return false;

    // Set role
    $role = $extra['role'] ?? 'subscriber';
    update_user_meta($user_id, $db->prefix . 'capabilities', [$role => true]);
    update_user_meta($user_id, $db->prefix . 'user_level', cr_role_to_level($role));

    do_action('user_register', $user_id);

    return $user_id;
}

function get_user_by(string $field, string|int $value): ?object {
    $db = cr_db();
    $table = $db->prefix . 'users';

    $col = match ($field) {
        'id', 'ID' => 'ID',
        'login'    => 'user_login',
        'email'    => 'user_email',
        'slug'     => 'user_nicename',
        default    => 'ID',
    };

    $format = is_numeric($value) ? '%d' : '%s';
    return $db->get_row($db->prepare("SELECT * FROM `{$table}` WHERE `{$col}` = {$format} LIMIT 1", $value));
}

function get_userdata(int $user_id): ?object {
    return get_user_by('id', $user_id);
}

// -- Authentication --

function cr_authenticate(string $username, string $password): int|false {
    $user = get_user_by('login', $username);
    if (!$user) {
        $user = get_user_by('email', $username);
    }
    if (!$user) return false;

    if (!password_verify($password, $user->user_pass)) {
        return false;
    }

    return (int) $user->ID;
}

function cr_set_auth_cookie(int $user_id): void {
    $token = bin2hex(random_bytes(32));
    $expiration = time() + (14 * DAY_IN_SECONDS);

    update_user_meta($user_id, 'session_token', $token);
    update_user_meta($user_id, 'session_expiration', $expiration);

    // HMAC-signed cookie to prevent tampering
    $payload = $user_id . '|' . $token . '|' . $expiration;
    $signature = hash_hmac('sha256', $payload, AUTH_KEY . SECURE_AUTH_SALT);
    $cookie_value = base64_encode($payload . '|' . $signature);

    setcookie('cr_auth', $cookie_value, [
        'expires'  => $expiration,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
}

function cr_clear_auth_cookie(): void {
    setcookie('cr_auth', '', ['expires' => time() - 3600, 'path' => '/']);
}

function cr_validate_auth_cookie(): int|false {
    if (!isset($_COOKIE['cr_auth'])) return false;

    $decoded = base64_decode($_COOKIE['cr_auth']);
    $parts = explode('|', $decoded);
    if (count($parts) !== 4) return false;

    [$user_id, $token, $expiration, $signature] = $parts;
    $user_id = (int) $user_id;

    // Verify HMAC signature first (prevents tampering)
    $payload = $user_id . '|' . $token . '|' . $expiration;
    $expected_sig = hash_hmac('sha256', $payload, AUTH_KEY . SECURE_AUTH_SALT);
    if (!hash_equals($expected_sig, $signature)) return false;

    if ((int) $expiration < time()) return false;

    $stored_token = get_user_meta($user_id, 'session_token', true);
    if (!hash_equals((string) $stored_token, $token)) return false;

    return $user_id;
}

function cr_init_current_user(): void {
    global $cr_current_user;

    $user_id = cr_validate_auth_cookie();
    if ($user_id) {
        $cr_current_user = get_userdata($user_id);
    } else {
        $cr_current_user = null;
    }
}

function is_user_logged_in(): bool {
    global $cr_current_user;
    return $cr_current_user !== null;
}

function get_current_user_id(): int {
    global $cr_current_user;
    return $cr_current_user ? (int) $cr_current_user->ID : 0;
}

function cr_get_current_user(): ?object {
    global $cr_current_user;
    return $cr_current_user;
}

// -- Capability checks --

function current_user_can(string $capability): bool {
    $user_id = get_current_user_id();
    if (!$user_id) return false;
    return user_can($user_id, $capability);
}

function user_can(int $user_id, string $capability): bool {
    global $cr_roles;

    $caps = get_user_meta($user_id, cr_db()->prefix . 'capabilities', true);
    if (!is_array($caps)) return false;

    foreach ($caps as $role => $active) {
        if ($active && isset($cr_roles[$role])) {
            if (!empty($cr_roles[$role]['capabilities'][$capability])) {
                return true;
            }
        }
    }

    return false;
}

function is_admin(): bool {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($request_uri, '/admin');
}

// -- Helpers --

// -- DB-backed roles --

function cr_install_roles_table(): void {
    $db = cr_db();
    $table = $db->prefix . 'roles';
    if (!$db->get_var("SHOW TABLES LIKE '{$table}'")) {
        $schema = file_get_contents(CR_BASE_PATH . '/install/schema.sql');
        $schema = str_replace('{prefix}', $db->prefix, $schema);
        $schema = preg_replace('/^--.*$/m', '', $schema);
        foreach (array_filter(array_map('trim', explode(';', $schema)), fn($s) => strlen($s) > 5 && stripos($s, $table) !== false) as $sql) {
            $db->query($sql);
        }
    }
}

function cr_load_db_roles(): void {
    global $cr_roles;
    $db = cr_db();
    $table = $db->prefix . 'roles';
    if (!$db->get_var("SHOW TABLES LIKE '{$table}'")) return;
    $db_roles = $db->get_results("SELECT * FROM `{$table}` ORDER BY name ASC");
    foreach ($db_roles as $r) {
        $caps = json_decode($r->capabilities, true) ?: [];
        $cr_roles[$r->slug] = ['name' => $r->name, 'capabilities' => $caps];
    }
}

function cr_role_to_level(string $role): int {
    return match ($role) {
        'administrator' => 10,
        'editor'        => 7,
        'author'        => 2,
        'contributor'   => 1,
        'subscriber'    => 0,
        default         => 0,
    };
}

// Time constants
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
if (!defined('WEEK_IN_SECONDS')) define('WEEK_IN_SECONDS', 604800);
if (!defined('MONTH_IN_SECONDS')) define('MONTH_IN_SECONDS', 2592000);
if (!defined('YEAR_IN_SECONDS')) define('YEAR_IN_SECONDS', 31536000);

// -- Nonce system --

function cr_create_nonce(string $action): string {
    $user_id = get_current_user_id();
    $token = defined('NONCE_KEY') ? NONCE_KEY : 'default-nonce-key';
    $salt = defined('NONCE_SALT') ? NONCE_SALT : 'default-nonce-salt';
    $tick = ceil(time() / (DAY_IN_SECONDS / 2));

    // Full 32-char hex HMAC (128 bits of entropy)
    return substr(hash_hmac('sha256', $tick . '|' . $action . '|' . $user_id, $token . $salt), 0, 32);
}

function cr_verify_nonce(string $nonce, string $action): bool {
    $user_id = get_current_user_id();
    $token = defined('NONCE_KEY') ? NONCE_KEY : 'default-nonce-key';
    $salt = defined('NONCE_SALT') ? NONCE_SALT : 'default-nonce-salt';
    $tick = ceil(time() / (DAY_IN_SECONDS / 2));

    // Check current and previous tick (covers 24h window)
    $expected = substr(hash_hmac('sha256', $tick . '|' . $action . '|' . $user_id, $token . $salt), 0, 32);
    if (hash_equals($expected, $nonce)) return true;

    $expected_prev = substr(hash_hmac('sha256', ($tick - 1) . '|' . $action . '|' . $user_id, $token . $salt), 0, 32);
    return hash_equals($expected_prev, $nonce);
}
