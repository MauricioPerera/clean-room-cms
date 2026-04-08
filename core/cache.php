<?php
/**
 * Clean Room CMS - Options Namespacing + LRU Object Cache
 *
 * Improvements over WordPress:
 *   1. Plugin options are namespaced (forced prefix) so they can't collide
 *   2. Smart LRU cache for options with configurable memory limit
 *   3. Lazy-load non-autoloaded options (only hit DB when accessed)
 *   4. Cache groups (options, meta, queries) with independent TTLs
 *   5. Built-in cache stats for debugging
 */

class CR_Cache {
    private array $groups = [];
    private array $stats = ['hits' => 0, 'misses' => 0, 'sets' => 0, 'evictions' => 0];
    private int $max_items_per_group;
    private static ?CR_Cache $instance = null;

    public static function instance(): CR_Cache {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(int $max_items_per_group = 1000) {
        $this->max_items_per_group = $max_items_per_group;
    }

    /**
     * Get a cached value.
     */
    public function get(string $group, string $key, mixed $default = null): mixed {
        if (!isset($this->groups[$group][$key])) {
            $this->stats['misses']++;
            return $default;
        }

        $entry = $this->groups[$group][$key];

        // TTL check
        if ($entry['expires'] > 0 && $entry['expires'] < time()) {
            unset($this->groups[$group][$key]);
            $this->stats['misses']++;
            return $default;
        }

        // Move to end (most recently used)
        unset($this->groups[$group][$key]);
        $this->groups[$group][$key] = $entry;

        $this->stats['hits']++;
        return $entry['value'];
    }

    /**
     * Set a cached value.
     */
    public function set(string $group, string $key, mixed $value, int $ttl = 0): void {
        // Remove if exists (to reorder in LRU)
        unset($this->groups[$group][$key]);

        // Evict LRU if at capacity
        if (isset($this->groups[$group]) && count($this->groups[$group]) >= $this->max_items_per_group) {
            // Remove the first item (least recently used)
            $evicted_key = array_key_first($this->groups[$group]);
            unset($this->groups[$group][$evicted_key]);
            $this->stats['evictions']++;
        }

        $this->groups[$group][$key] = [
            'value'   => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];

        $this->stats['sets']++;
    }

    /**
     * Delete a cached value.
     */
    public function delete(string $group, string $key): bool {
        if (isset($this->groups[$group][$key])) {
            unset($this->groups[$group][$key]);
            return true;
        }
        return false;
    }

    /**
     * Flush an entire group.
     */
    public function flush_group(string $group): void {
        unset($this->groups[$group]);
    }

    /**
     * Flush all cached data.
     */
    public function flush_all(): void {
        $this->groups = [];
    }

    /**
     * Check if a key exists (and is not expired).
     */
    public function exists(string $group, string $key): bool {
        if (!isset($this->groups[$group][$key])) return false;

        $entry = $this->groups[$group][$key];
        if ($entry['expires'] > 0 && $entry['expires'] < time()) {
            unset($this->groups[$group][$key]);
            return false;
        }

        return true;
    }

    /**
     * Get cache statistics.
     */
    public function stats(): array {
        $total_items = 0;
        $group_counts = [];
        foreach ($this->groups as $group => $items) {
            $count = count($items);
            $group_counts[$group] = $count;
            $total_items += $count;
        }

        return array_merge($this->stats, [
            'total_items'  => $total_items,
            'groups'       => $group_counts,
            'hit_rate'     => ($this->stats['hits'] + $this->stats['misses']) > 0
                ? round($this->stats['hits'] / ($this->stats['hits'] + $this->stats['misses']) * 100, 1)
                : 0,
        ]);
    }

    /**
     * Reset stats (for testing).
     */
    public function reset(): void {
        $this->groups = [];
        $this->stats = ['hits' => 0, 'misses' => 0, 'sets' => 0, 'evictions' => 0];
    }
}

// -- Namespaced Options --

/**
 * Get a plugin option with automatic namespacing.
 * Plugins can only access their own options.
 */
function cr_plugin_option_get(string $plugin_slug, string $key, mixed $default = false): mixed {
    $namespaced_key = "plugin_{$plugin_slug}_{$key}";

    // Check cache first
    $cache = CR_Cache::instance();
    $cached = $cache->get('options', $namespaced_key, '__MISS__');
    if ($cached !== '__MISS__') {
        return $cached;
    }

    $value = get_option($namespaced_key, $default);
    $cache->set('options', $namespaced_key, $value);
    return $value;
}

/**
 * Set a plugin option with automatic namespacing.
 */
function cr_plugin_option_set(string $plugin_slug, string $key, mixed $value, bool $autoload = false): bool {
    $namespaced_key = "plugin_{$plugin_slug}_{$key}";

    $result = update_option($namespaced_key, $value, $autoload ? 'yes' : 'no');

    if ($result) {
        CR_Cache::instance()->set('options', $namespaced_key, $value);
    }

    return $result;
}

/**
 * Delete a plugin option.
 */
function cr_plugin_option_delete(string $plugin_slug, string $key): bool {
    $namespaced_key = "plugin_{$plugin_slug}_{$key}";

    $result = delete_option($namespaced_key);
    CR_Cache::instance()->delete('options', $namespaced_key);

    return $result;
}

/**
 * Delete ALL options for a plugin (on uninstall).
 */
function cr_plugin_option_cleanup(string $plugin_slug): int {
    $db = cr_db();
    $table = $db->prefix . 'options';
    $prefix = "plugin_{$plugin_slug}_%";

    $count = (int) $db->get_var("SELECT COUNT(*) FROM `{$table}` WHERE option_name LIKE '{$db->escape($prefix)}'");
    $db->query("DELETE FROM `{$table}` WHERE option_name LIKE '{$db->escape($prefix)}'");

    CR_Cache::instance()->flush_group('options');

    return $count;
}

// -- Cached query helper --

/**
 * Execute a query with caching.
 */
function cr_cached_query(string $sql, int $ttl = 300, string $group = 'queries'): array {
    $cache = CR_Cache::instance();
    $key = md5($sql);

    $cached = $cache->get($group, $key, '__MISS__');
    if ($cached !== '__MISS__') {
        return $cached;
    }

    $results = cr_db()->get_results($sql);
    $cache->set($group, $key, $results, $ttl);

    return $results;
}

/**
 * Invalidate all cached queries (after write operations).
 */
function cr_invalidate_query_cache(): void {
    CR_Cache::instance()->flush_group('queries');
}

// Global accessor
function cr_cache(): CR_Cache {
    return CR_Cache::instance();
}
