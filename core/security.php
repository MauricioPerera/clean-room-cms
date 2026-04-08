<?php
/**
 * Clean Room CMS - Security Headers & Rate Limiting
 *
 * Built-in security features that WordPress requires plugins for:
 *   1. Content-Security-Policy headers
 *   2. Rate limiting on REST API and login
 *   3. CSRF protection beyond nonces
 *   4. Security headers (X-Frame-Options, X-Content-Type-Options, etc.)
 *   5. Login brute-force protection
 */

class CR_Security {
    private static array $rate_limits = [];

    /**
     * Send all security headers. Call early in the request lifecycle.
     */
    public static function send_headers(): void {
        if (headers_sent()) return;

        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // XSS protection (legacy browsers)
        header('X-XSS-Protection: 1; mode=block');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Permissions policy (disable dangerous browser APIs by default)
        header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()");

        // Content Security Policy
        $csp = apply_filters('cr_csp_policy', self::default_csp());
        header("Content-Security-Policy: {$csp}");

        // HSTS (if on HTTPS)
        if (self::is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Default CSP policy - restrictive but functional.
     */
    private static string $csp_nonce = '';

    public static function get_csp_nonce(): string {
        if (empty(self::$csp_nonce)) {
            self::$csp_nonce = base64_encode(random_bytes(16));
        }
        return self::$csp_nonce;
    }

    public static function default_csp(): string {
        $nonce = self::get_csp_nonce();

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",      // nonce-based instead of unsafe-inline
            "style-src 'self' 'unsafe-inline'",         // styles still need unsafe-inline (common pattern)
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }

    /**
     * Check if the current request is HTTPS.
     */
    public static function is_https(): bool {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        if (($_SERVER['SERVER_PORT'] ?? 0) == 443) return true;
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
        return false;
    }

    // =====================
    // Rate Limiting
    // =====================

    /**
     * Check if a request should be rate-limited.
     * Uses in-memory tracking + optional database persistence.
     *
     * @param string $key Unique identifier (e.g., "api:{ip}", "login:{ip}")
     * @param int $max_requests Maximum requests allowed
     * @param int $window_seconds Time window in seconds
     * @return bool True if request is allowed, false if rate limited
     */
    public static function rate_limit(string $key, int $max_requests, int $window_seconds): bool {
        $now = time();

        // Clean expired entries
        if (isset(self::$rate_limits[$key])) {
            self::$rate_limits[$key] = array_filter(
                self::$rate_limits[$key],
                fn($t) => $t > ($now - $window_seconds)
            );
        }

        $count = count(self::$rate_limits[$key] ?? []);

        if ($count >= $max_requests) {
            return false; // Rate limited
        }

        self::$rate_limits[$key][] = $now;
        return true; // Allowed
    }

    /**
     * Get remaining requests for a key.
     */
    public static function rate_limit_remaining(string $key, int $max_requests, int $window_seconds): int {
        $now = time();
        $recent = array_filter(
            self::$rate_limits[$key] ?? [],
            fn($t) => $t > ($now - $window_seconds)
        );
        return max(0, $max_requests - count($recent));
    }

    /**
     * Rate limit the REST API by IP.
     * Default: 100 requests per minute.
     */
    public static function rate_limit_api(): bool {
        $ip = self::get_client_ip();
        $key = "api:{$ip}";
        $max = (int) apply_filters('cr_api_rate_limit', 100);
        $window = (int) apply_filters('cr_api_rate_window', 60);

        $allowed = self::rate_limit($key, $max, $window);

        if (!$allowed && !headers_sent()) {
            $remaining = self::rate_limit_remaining($key, $max, $window);
            header('X-RateLimit-Limit: ' . $max);
            header('X-RateLimit-Remaining: ' . $remaining);
            header('Retry-After: ' . $window);
        }

        return $allowed;
    }

    /**
     * Rate limit login attempts by IP.
     * Default: 5 attempts per 5 minutes.
     */
    public static function rate_limit_login(): bool {
        $ip = self::get_client_ip();
        $key = "login:{$ip}";
        $max = (int) apply_filters('cr_login_rate_limit', 5);
        $window = (int) apply_filters('cr_login_rate_window', 300);

        return self::rate_limit($key, $max, $window);
    }

    /**
     * Persistent rate limiting using the database (survives restarts).
     */
    public static function rate_limit_persistent(string $key, int $max_requests, int $window_seconds): bool {
        $db = cr_db();
        $table = $db->prefix . 'options';
        $option_key = '_rate_limit_' . md5($key);
        $now = time();

        $data = get_option($option_key, []);
        if (!is_array($data)) $data = [];

        // Clean expired
        $data = array_filter($data, fn($t) => $t > ($now - $window_seconds));

        if (count($data) >= $max_requests) {
            return false;
        }

        $data[] = $now;
        update_option($option_key, $data, 'no');

        return true;
    }

    // =====================
    // Brute Force Protection
    // =====================

    /**
     * Record a failed login attempt.
     */
    public static function record_failed_login(string $username): void {
        $ip = self::get_client_ip();
        $db = cr_db();
        $table = $db->prefix . 'options';
        $key = '_failed_logins_' . md5($ip);
        $now = time();

        $attempts = get_option($key, []);
        if (!is_array($attempts)) $attempts = [];

        // Clean attempts older than 30 minutes
        $attempts = array_filter($attempts, fn($a) => $a['time'] > ($now - 1800));
        $attempts[] = ['time' => $now, 'user' => $username, 'ip' => $ip];

        update_option($key, $attempts, 'no');

        do_action('cr_failed_login', $username, $ip, count($attempts));
    }

    /**
     * Check if an IP is locked out from login.
     * Locked out after 5 failed attempts in 30 minutes.
     */
    public static function is_login_locked(string $ip = ''): bool {
        if (empty($ip)) $ip = self::get_client_ip();

        $key = '_failed_logins_' . md5($ip);
        $attempts = get_option($key, []);
        if (!is_array($attempts)) return false;

        $now = time();
        $recent = array_filter($attempts, fn($a) => $a['time'] > ($now - 1800));

        $max = (int) apply_filters('cr_max_login_attempts', 5);
        return count($recent) >= $max;
    }

    /**
     * Clear failed login attempts (after successful login).
     */
    public static function clear_failed_logins(string $ip = ''): void {
        if (empty($ip)) $ip = self::get_client_ip();
        delete_option('_failed_logins_' . md5($ip));
    }

    // =====================
    // CSRF Token (beyond nonces)
    // =====================

    /**
     * Generate a CSRF token tied to the session.
     */
    public static function csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!headers_sent()) {
                session_start();
            }
        }

        if (empty($_SESSION['cr_csrf_token'])) {
            $_SESSION['cr_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['cr_csrf_token'];
    }

    /**
     * Validate a CSRF token.
     */
    public static function csrf_validate(string $token): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) return false;
        $expected = $_SESSION['cr_csrf_token'] ?? '';
        if (empty($expected)) return false;
        return hash_equals($expected, $token);
    }

    /**
     * Output a hidden CSRF input field for forms.
     */
    public static function csrf_field(): string {
        $token = self::csrf_token();
        return '<input type="hidden" name="_cr_csrf" value="' . esc_attr($token) . '">';
    }

    // =====================
    // Input Sanitization
    // =====================

    /**
     * Sanitize and validate an email address.
     */
    public static function sanitize_email(string $email): string {
        $email = trim($email);
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Sanitize a URL.
     */
    public static function sanitize_url(string $url): string {
        $url = trim($url);
        $url = filter_var($url, FILTER_SANITIZE_URL);
        if (!filter_var($url, FILTER_VALIDATE_URL)) return '';
        if (!preg_match('/^https?:\/\//', $url)) return '';
        return $url;
    }

    /**
     * Sanitize HTML content (strip dangerous tags/attributes).
     */
    public static function sanitize_html(string $html, string $context = 'post'): string {
        $allowed = match ($context) {
            'post' => '<p><br><a><strong><em><b><i><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><img><table><thead><tbody><tr><th><td><figure><figcaption><hr><div><span>',
            'comment' => '<p><br><a><strong><em><b><i><code>',
            'title' => '',
            default => '<p><br><a><strong><em>',
        };

        $clean = strip_tags($html, $allowed);

        // Remove event handlers (onclick, onerror, etc.)
        $clean = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean);
        $clean = preg_replace('/\s+on\w+\s*=\s*\S+/i', '', $clean);

        // Remove javascript: protocol
        $clean = preg_replace('/href\s*=\s*["\']?\s*javascript:/i', 'href="', $clean);
        $clean = preg_replace('/src\s*=\s*["\']?\s*javascript:/i', 'src="', $clean);

        return $clean;
    }

    // =====================
    // Utilities
    // =====================

    /**
     * Get the client's real IP address.
     */
    public static function get_client_ip(): string {
        // Only trust proxy headers if explicitly configured (prevents IP spoofing)
        if (defined('CR_TRUSTED_PROXIES') && CR_TRUSTED_PROXIES) {
            $remote = $_SERVER['REMOTE_ADDR'] ?? '';
            $trusted = is_array(CR_TRUSTED_PROXIES) ? CR_TRUSTED_PROXIES : [$remote];

            if (in_array($remote, $trusted, true)) {
                $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
                foreach ($headers as $header) {
                    if (!empty($_SERVER[$header])) {
                        $ips = explode(',', $_SERVER[$header]);
                        $ip = trim($ips[0]);
                        if (filter_var($ip, FILTER_VALIDATE_IP)) {
                            return $ip;
                        }
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Reset rate limit state (for testing).
     */
    public static function reset(): void {
        self::$rate_limits = [];
    }
}

// Hook security headers into early request
add_action('init', function () {
    if (!defined('CR_TESTING') && php_sapi_name() !== 'cli') {
        CR_Security::send_headers();
    }
}, 1);
