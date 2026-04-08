# Clean Room CMS

A modern content management system built from scratch using clean-room design methodology.
Every line of code is original. Zero external dependencies. PHP 8.2+, MySQL/MariaDB.

---

## Quick Start

```bash
# 1. Create database
mysql -u root -e "CREATE DATABASE cleanroom"

# 2. Configure
cp config-sample.php config.php
# Edit config.php with your DB credentials

# 3. Run
php -S localhost:8080 index.php

# 4. Install
# Open http://localhost:8080 - the installer runs automatically

# 5. Admin panel
# http://localhost:8080/admin/

# 6. Run tests
php tests/run.php
```

---

## Project Structure

```
clean room/
  index.php                     Front controller
  config.php                    Database credentials, paths, constants
  config-sample.php             Template configuration (safe to commit)
  worker.php                    Background queue worker (cron or daemon)
  .htaccess                     Apache URL rewriting

  core/                         Framework core (18 modules)
    bootstrap.php               Load sequence and initialization
    hooks.php                   Event system (actions and filters)
    database.php                PDO abstraction with prepared statements
    options.php                 Key-value site settings with autoload
    meta.php                    Entity metadata (EAV pattern)
    post-types.php              Content type registry and CRUD
    taxonomies.php              Classification system (categories, tags, custom)
    query.php                   SQL query builder, The Loop, conditional tags
    router.php                  URL parsing to query variables
    rewrite.php                 Custom URL rewrite rules
    template.php                Template hierarchy engine and asset management
    shortcodes.php              [shortcode] syntax processor
    user.php                    Authentication, roles, capabilities, sessions
    cache.php                   LRU object cache and namespaced plugin options
    sandbox.php                 Granular plugin permission system
    security.php                CSP headers, rate limiting, brute force protection
    jsonmeta.php                JSON column metadata (modern alternative to EAV)
    queue.php                   Async job queue with retry and dead letter

  core/ai/                      AI subsystem (4 modules)
    client.php                  Provider-agnostic AI SDK (OpenAI, Anthropic, Ollama)
    abilities.php               Capability registry with JSON Schema validation
    guidelines.php              Editorial content standards for AI agents
    mcp.php                     Model Context Protocol server adapter

  api/
    rest-api.php                RESTful API for all content operations

  admin/
    index.php                   Admin panel (dashboard, CRUD, settings, login)
    assets/css/admin.css        Admin stylesheet

  content/
    themes/default/             Default theme (10 template files + stylesheet)
    plugins/                    Plugin directory
    uploads/                    Media uploads

  install/
    schema.sql                  Database schema (11 tables)
    installer.php               Web-based installation wizard

  tests/                        585 tests across 24 suites
    run.php                     Test runner (standalone, no dependencies)
    TestCase.php                Assertion library
    bootstrap.php               Test environment setup and teardown
    Unit/                       6 test files (no database required)
    Integration/                17 test files (require database)
    API/                        1 test file (REST API endpoints)
```

---

## Metrics

| Metric | Value |
|--------|-------|
| PHP files | 65 |
| Lines of code (core + AI) | 6,721 |
| Lines of code (API + admin) | 955 |
| Lines of code (tests) | 2,899 |
| Lines of code (total project) | 12,817 |
| Test suites | 24 |
| Test assertions | 585 |
| Pass rate | 100% |
| External dependencies | 0 |
| Minimum PHP version | 8.2 |
| Database | MySQL 8.0+ / MariaDB 10.4+ |

---

## Core Systems

### 1. Hook System (`core/hooks.php`)

Event-driven architecture. All extensibility flows through hooks.

**Actions** execute side effects at specific points:
```php
add_action('after_post_save', function(int $post_id, object $post) {
    log("Post {$post_id} saved");
}, priority: 10, accepted_args: 2);

do_action('after_post_save', $post_id, $post);
```

**Filters** transform data through a callback chain:
```php
add_filter('the_content', function(string $content): string {
    return $content . '<p>Appended text</p>';
});

$content = apply_filters('the_content', $raw_content);
```

**Execution order**: lower priority number runs first. Same priority preserves insertion order.

**Introspection**:
```php
has_filter('hook_name', $callback);   // Check registration
did_action('hook_name');              // Execution count
doing_action('hook_name');            // True during execution
current_filter();                     // Name of current hook
remove_filter('hook_name', $callback, $priority);
```

---

### 2. Database Layer (`core/database.php`)

PDO-based abstraction with prepared statements and CRUD helpers.

```php
$db = cr_db();

// Prepared statements (sprintf-style: %s string, %d int, %f float)
$sql = $db->prepare(
    "SELECT * FROM `{$db->prefix}posts` WHERE ID = %d AND post_status = %s",
    42, 'publish'
);

// CRUD
$id    = $db->insert('cr_posts', ['post_title' => 'Hello', 'post_status' => 'publish']);
$rows  = $db->update('cr_posts', ['post_title' => 'Updated'], ['ID' => $id]);
$count = $db->delete('cr_posts', ['ID' => $id]);

// Read operations
$row     = $db->get_row("SELECT ...");       // Single object
$results = $db->get_results("SELECT ...");   // Array of objects
$value   = $db->get_var("SELECT COUNT(*)");  // Scalar value
$column  = $db->get_col("SELECT title ...");// Array of one column

// Error handling
$db->last_error;       // Last error message
$db->last_query;       // Last SQL executed
$db->rows_affected;    // Rows affected by last write
$db->insert_id;        // Last auto-increment ID
```

---

### 3. Content Engine

#### Post Types (`core/post-types.php`)

```php
// Register custom content type
register_post_type('product', [
    'label'        => 'Products',
    'public'       => true,
    'hierarchical' => false,
    'show_in_rest' => true,
    'supports'     => ['title', 'editor', 'thumbnail'],
]);

// CRUD
$id = cr_insert_post([
    'post_title'   => 'My Product',
    'post_content' => 'Description here',
    'post_status'  => 'publish',
    'post_type'    => 'product',
    'post_author'  => get_current_user_id(),
]);

$post = get_post($id);
cr_update_post(['ID' => $id, 'post_title' => 'Updated Title']);
cr_delete_post($id);              // Move to trash
cr_delete_post($id, force: true); // Permanent delete
```

Built-in types: `post`, `page`, `attachment`, `revision`, `nav_menu_item`.

#### Taxonomies (`core/taxonomies.php`)

```php
register_taxonomy('brand', 'product', [
    'label'        => 'Brands',
    'hierarchical' => true,
    'show_in_rest' => true,
]);

$result = cr_insert_term('Nike', 'brand', ['slug' => 'nike']);
cr_set_post_terms($post_id, ['Nike', 'Adidas'], 'brand');
$terms = get_the_terms($post_id, 'brand');
$all   = get_terms(['taxonomy' => 'brand', 'hide_empty' => false]);
```

Built-in taxonomies: `category`, `post_tag`.

#### Metadata

**EAV pattern** (traditional, one row per key-value pair):
```php
add_post_meta($id, 'price', 29.99);
$price = get_post_meta($id, 'price', single: true);
update_post_meta($id, 'price', 39.99);
delete_post_meta($id, 'price');

// Same API for users, terms, comments:
// get_user_meta, add_term_meta, get_comment_meta, etc.
```

**JSON column** (modern, single row per object via `core/jsonmeta.php`):
```php
// Set full metadata document
cr_post_json_set($id, [
    'seo'   => ['title' => 'Custom Title', 'description' => 'Meta desc'],
    'price' => 29.99,
    'tags'  => ['featured', 'sale'],
]);

// Read by dot path
$title = cr_post_json_get($id, 'seo.title');        // 'Custom Title'
$all   = cr_post_json_get($id);                      // Full document

// Partial update (read-modify-write, no raw SQL)
cr_post_json_set($id, 'seo.title', 'New Title');

// Remove a key
cr_post_json_remove($id, 'seo.description');

// Query by value
$ids = cr_json_meta_query('post', 'price', 20, '>');

// Bulk fetch (avoids N+1)
$bulk = cr_json_meta_get_bulk('post', [1, 2, 3, 4, 5]);
```

---

### 4. Query Engine (`core/query.php`)

```php
$query = new CR_Query([
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 10,
    'paged'          => 2,
    'orderby'        => 'date',
    'order'          => 'DESC',
    's'              => 'search term',
    'cat'            => 5,
    'tag'            => 'php',
    'author'         => 1,
    'year'           => 2026,
    'meta_key'       => 'featured',
    'meta_value'     => '1',
    'post__in'       => [10, 20, 30],
]);

$query->found_posts;    // Total matching rows
$query->max_num_pages;  // Total pages

// The Loop
while (have_posts()) {
    the_post();
    echo get_the_title();
    echo get_the_content();
    echo get_the_date('F j, Y');
    echo get_the_author();
    echo get_the_permalink();
    echo get_the_excerpt();
}

// Conditional tags
is_home();    is_front_page();   is_single();
is_page();    is_archive();      is_category();
is_tag();     is_author();       is_date();
is_search();  is_404();          is_singular('product');
```

---

### 5. URL Routing (`core/router.php` + `core/rewrite.php`)

| URL Pattern | Resolves To |
|---|---|
| `/` | Home page |
| `/?p=123` | Post by ID |
| `/?page_id=5` | Page by ID |
| `/?s=keyword` | Search results |
| `/category/news/` | Category archive |
| `/tag/php/` | Tag archive |
| `/author/john/` | Author archive |
| `/2026/04/07/my-post/` | Date-based post |
| `/page/2/` | Pagination |
| `/admin/` | Admin panel |
| `/api/cr/v1/posts` | REST API |
| `/mcp/tools` | MCP Protocol |

Custom rules:
```php
add_rewrite_rule('^products/([^/]+)/?$', ['product_slug' => '$1']);
```

---

### 6. Template Hierarchy (`core/template.php`)

Template cascade (first existing file wins):

| Request | Cascade |
|---|---|
| Single post | `single-{type}-{slug}.php` > `single-{type}.php` > `single.php` > `singular.php` > `index.php` |
| Page | `page-{slug}.php` > `page-{id}.php` > `page.php` > `singular.php` > `index.php` |
| Category | `category-{slug}.php` > `category-{id}.php` > `category.php` > `archive.php` > `index.php` |
| Search | `search.php` > `index.php` |
| 404 | `404.php` > `index.php` |
| Home | `front-page.php` > `home.php` > `index.php` |

Template partials:
```php
get_header('custom');                          // header-custom.php
get_footer();                                  // footer.php
get_sidebar();                                 // sidebar.php
get_template_part('parts/card', 'featured');   // parts/card-featured.php
```

Assets:
```php
cr_enqueue_style('main', '/css/main.css', [], '1.0');
cr_enqueue_script('app', '/js/app.js', [], '1.0', in_footer: true);
```

Site info:
```php
bloginfo('name');           // Site title
bloginfo('description');    // Tagline
bloginfo('url');            // Home URL
cr_get_theme_url();         // Active theme URL
body_class('extra');        // CSS classes on <body>
```

---

### 7. User System (`core/user.php`)

```php
// Create
$user_id = cr_create_user('johndoe', 'securepass', 'john@example.com', [
    'display_name' => 'John Doe',
    'role'         => 'editor',
]);

// Authenticate (returns ID or false)
$user_id = cr_authenticate('johndoe', 'securepass');
cr_set_auth_cookie($user_id);   // HMAC-signed, httponly, secure flag
cr_clear_auth_cookie();          // Logout

// Current user
is_user_logged_in();
get_current_user_id();
$user = cr_get_current_user();

// Lookup
$user = get_user_by('login', 'johndoe');
$user = get_user_by('email', 'john@example.com');
$user = get_user_by('id', 42);

// Capabilities
current_user_can('edit_posts');
user_can($user_id, 'manage_options');

// Nonces (CSRF)
$nonce = cr_create_nonce('delete_post_42');       // 32-char HMAC
$valid = cr_verify_nonce($nonce, 'delete_post_42');
```

Built-in roles: `administrator`, `editor`, `author`, `contributor`, `subscriber`.

```php
add_role('moderator', 'Moderator', ['moderate_comments' => true, 'read' => true]);
```

**Password hashing**: bcrypt via `password_hash()`.
**Cookie security**: HMAC-SHA256 signed, `httponly`, `secure` flag on HTTPS, `SameSite=Lax`.

---

### 8. REST API (`api/rest-api.php`)

Base URL: `/api/cr/v1/`

| Endpoint | Methods | Auth Required |
|---|---|---|
| `/posts` | GET, POST | POST |
| `/posts/{id}` | GET, PUT, PATCH, DELETE | Write operations |
| `/pages` | GET, POST | POST |
| `/pages/{id}` | GET, PUT, PATCH, DELETE | Write operations |
| `/categories` | GET, POST | POST |
| `/tags` | GET, POST | POST |
| `/users` | GET | Yes |
| `/users/me` | GET | Yes |
| `/search?search=keyword` | GET | No |
| `/settings` | GET, POST | Yes |

**Query parameters**: `per_page`, `page`, `search`, `orderby`, `order`, `status`, `author`, `_fields`

**Response headers**: `X-CR-Total`, `X-CR-TotalPages`

**Authentication**: HTTP Basic Auth (username:password) or `X-CR-Nonce` header for same-origin requests.

**Rate limiting**: 100 requests/minute per IP (configurable via `cr_api_rate_limit` filter).

**CORS**: restricted to same origin by default (configurable via `cr_cors_allowed_origins` filter).

Register custom endpoints:
```php
register_rest_route('myplugin/v1', '/items', [
    'methods'             => 'GET',
    'callback'            => fn($params) => ['items' => []],
    'permission_callback' => fn() => current_user_can('read'),
]);
```

---

## Security (`core/security.php`)

### HTTP Headers (automatic)
```
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Content-Security-Policy: script-src 'self' 'nonce-{random}'; ...
Strict-Transport-Security: max-age=31536000 (HTTPS only)
```

### Rate Limiting
```php
CR_Security::rate_limit('key', max: 100, window: 60);   // Generic
CR_Security::rate_limit_api();                            // 100 req/min
CR_Security::rate_limit_login();                          // 5 attempts/5 min
```

### Brute Force Protection
5 failed login attempts in 30 minutes locks the IP. Automatic on admin login.
```php
CR_Security::is_login_locked($ip);
CR_Security::clear_failed_logins($ip);
```

### Input Sanitization
```php
CR_Security::sanitize_email('  user@example.com  ');    // 'user@example.com'
CR_Security::sanitize_url('javascript:alert(1)');        // ''
CR_Security::sanitize_html($html, 'post');               // Strips <script>, onclick=, javascript:
CR_Security::sanitize_html($html, 'comment');            // More restrictive
CR_Security::sanitize_html($html, 'title');              // All tags stripped
```

### CSRF Tokens
```php
echo CR_Security::csrf_field();                          // Hidden <input>
CR_Security::csrf_validate($_POST['_cr_csrf']);
```

### Trusted Proxy Configuration
IP-based rate limiting only trusts `X-Forwarded-For` when explicitly configured:
```php
// In config.php:
define('CR_TRUSTED_PROXIES', ['10.0.0.1', '10.0.0.2']);
```

---

## Plugin Sandbox (`core/sandbox.php`)

Plugins declare required permissions in `manifest.json`. The admin reviews and grants them. Unauthorized actions throw `CR_Sandbox_Exception`.

### manifest.json
```json
{
    "name": "SEO Toolkit",
    "version": "1.0.0",
    "permissions": [
        "options:read",
        "options:write",
        "hooks:core",
        "content:filter"
    ]
}
```

### Permission Types

| Permission | Scope |
|---|---|
| `db:read` | Read any database table |
| `db:write` | Write to any database table |
| `db:own` | Read/write only to plugin-prefixed tables |
| `options:read` | Read site options |
| `options:write` | Write site options |
| `users:read` | Read user data |
| `users:write` | Modify user data |
| `files:read` | Read files from disk |
| `files:write` | Write files (uploads, cache) |
| `http:outbound` | Make external HTTP requests |
| `hooks:core` | Register core hooks |
| `admin:pages` | Add admin menu pages |
| `admin:settings` | Add settings pages |
| `rest:endpoints` | Register REST API endpoints |
| `cron:schedule` | Schedule async tasks |
| `content:filter` | Filter post content |
| `exec:shell` | Execute shell commands |

### Admin API
```php
CR_Sandbox::grant_permissions('seo-toolkit', ['options:read', 'hooks:core']);
CR_Sandbox::revoke_permissions('seo-toolkit');
CR_Sandbox::get_violations();    // Audit log of denied attempts
CR_Sandbox::get_all_plugins();   // Status of all plugins + pending permissions
```

---

## Object Cache (`core/cache.php`)

In-memory LRU cache with TTL, groups, eviction tracking, and cache statistics.

```php
$cache = cr_cache();

$cache->set('group', 'key', $value, ttl: 300);   // 5 min TTL
$value = $cache->get('group', 'key', $default);
$cache->delete('group', 'key');
$cache->exists('group', 'key');
$cache->flush_group('group');
$cache->flush_all();

$stats = $cache->stats();
// ['hits' => 150, 'misses' => 23, 'sets' => 80, 'evictions' => 5, 'hit_rate' => 86.7]
```

### Namespaced Plugin Options

Isolated per-plugin storage. No collisions. Automatic cleanup on uninstall.
```php
cr_plugin_option_set('my-plugin', 'version', '2.0');
cr_plugin_option_get('my-plugin', 'version');
cr_plugin_option_delete('my-plugin', 'version');
cr_plugin_option_cleanup('my-plugin');    // Remove ALL options for this plugin
```

### Cached Queries
```php
$results = cr_cached_query("SELECT * FROM cr_posts LIMIT 10", ttl: 300);
cr_invalidate_query_cache();
```

---

## Async Queue (`core/queue.php`)

Database-backed job queue with priorities, retry, exponential backoff, and dead letter queue.

```php
// One-time job
cr_queue_push('send_email', ['to' => 'user@example.com', 'subject' => 'Hello']);

// With options
cr_queue_push('process_image', ['id' => 42], [
    'priority'     => 1,
    'group'        => 'media',
    'delay'        => 300,       // 5 minutes from now
    'max_attempts' => 5,
]);

// Recurring jobs
cr_schedule_event('cleanup_expired', 'daily');
cr_schedule_event('sync_inventory', 'hourly');
cr_unschedule_event('sync_inventory');

// Handlers (via hooks)
add_action('send_email', function(string $to, string $subject) {
    mail($to, $subject, 'Body...');
});

// Monitoring
CR_Queue::stats();
// ['pending' => 12, 'running' => 2, 'completed' => 150, 'dead' => 3]

CR_Queue::get_dead_letter();     // Inspect failed jobs
CR_Queue::retry_job($job_id);    // Re-queue a dead job
CR_Queue::cleanup(days: 7);      // Purge old completed/dead jobs
```

### Worker
```bash
# One batch per minute (crontab):
* * * * * php /path/to/worker.php

# Continuous daemon (supervisor/systemd):
php worker.php --daemon

# Custom batch size:
php worker.php --batch=20
```

Retry strategy: exponential backoff (30s, 60s, 120s, 240s... max 1 hour).
After `max_attempts` failures the job moves to the dead letter queue.
Job arguments limited to 10 KB to prevent storage abuse.

---

## AI Subsystem

### AI Client (`core/ai/client.php`)

Provider-agnostic SDK with fluent prompt builder.

```php
// Configure providers (stored in options)
update_option('cr_ai_connectors', [
    'openai'    => ['enabled' => true, 'api_key' => 'sk-...'],
    'anthropic' => ['enabled' => true, 'api_key' => 'sk-ant-...'],
    'ollama'    => ['enabled' => true, 'base_url' => 'http://localhost:11434'],
]);

// Send a prompt
$response = cr_ai()
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a helpful content editor.')
    ->user('Summarize this article: ...')
    ->temperature(0.3)
    ->max_tokens(500)
    ->with_guidelines()    // Auto-inject site editorial standards
    ->send();

if ($response->success) {
    $response->content;        // AI text
    $response->model;          // Model used
    $response->usage;          // Token counts
    $response->finish_reason;  // 'stop', 'length', 'tool_use'
}

// Tool calling (AI invokes site abilities)
$response = cr_ai()
    ->system('You manage this website.')
    ->user('Find recent PHP posts and create a summary.')
    ->tools(cr_get_abilities_as_tools())
    ->send();

if ($response->has_tool_calls()) {
    $results = CR_Abilities::handle_tool_calls($response->tool_calls);
    // Feed results back for the next turn...
}
```

| Provider | Connector Class | Example Models |
|---|---|---|
| OpenAI | `CR_AI_Connector_OpenAI` | gpt-4o, gpt-4o-mini, o1, o3-mini |
| Anthropic | `CR_AI_Connector_Anthropic` | claude-opus-4-6, claude-sonnet-4-6 |
| Ollama (local) | `CR_AI_Connector_Ollama` | llama3, mistral, codellama, phi3 |

Sandbox enforcement: calls from plugin context require `http:outbound` permission.

---

### Abilities API (`core/ai/abilities.php`)

Central registry of callable site capabilities. Each ability has:
- Human-readable name and description
- Machine-readable JSON Schema for input and output
- Permission gating
- Callable callback

```php
register_ability('translate_post', [
    'description'  => 'Translate a post to another language.',
    'category'     => 'content',
    'permission'   => 'edit_posts',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id'  => ['type' => 'integer'],
            'language' => ['type' => 'string', 'enum' => ['es', 'fr', 'de', 'ja']],
        ],
        'required' => ['post_id', 'language'],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'translated_title'   => ['type' => 'string'],
            'translated_content' => ['type' => 'string'],
        ],
    ],
    'callback' => function(array $input): array {
        // ... translation logic ...
        return ['translated_title' => '...', 'translated_content' => '...'];
    },
]);

// Execute (validates input schema, checks permission, validates output)
$result = execute_ability('translate_post', ['post_id' => 1, 'language' => 'es']);

// Project as AI function declarations
$tools = cr_get_abilities_as_tools();

// Handle tool calls from an AI response
$results = CR_Abilities::handle_tool_calls($response->tool_calls);
```

Built-in abilities: `get_post`, `create_post`, `search_content`, `get_site_info`, `generate_excerpt`, `get_content_guidelines`, `update_content_guidelines`.

---

### Content Guidelines (`core/ai/guidelines.php`)

Structured editorial standards that AI agents follow automatically.

```php
cr_update_content_guidelines('site', 'Developer-focused tech blog. Target: mid-senior engineers.');
cr_update_content_guidelines('copy', 'Technical but approachable. No jargon without explanation.');
cr_update_content_guidelines('images', 'Prefer diagrams and code screenshots. No stock photos.');
cr_update_content_guidelines('blocks', 'Paragraphs max 3 sentences. Code blocks for all examples.');
cr_update_content_guidelines('additional', 'Always cite sources. Include TL;DR at the top.');

// Auto-inject into AI prompts
$response = cr_ai()->with_guidelines()->user('Write about...')->send();

// Access programmatically
$prompt = cr_guidelines_as_system_prompt();   // Formatted text for AI
$data   = cr_guidelines_as_structured();       // Array for API/MCP
```

Sections: `site`, `copy`, `images`, `blocks`, `additional`.

---

### MCP Server (`core/ai/mcp.php`)

Exposes site capabilities via [Model Context Protocol](https://spec.modelcontextprotocol.io/) so external AI assistants can discover and invoke them as tools.

Base URL: `/mcp/`

| Endpoint | Method | Returns |
|---|---|---|
| `/mcp/` | GET | Server info and capabilities |
| `/mcp/tools` | GET | Available tools (from Abilities API) |
| `/mcp/execute` | POST | Execute a tool by name |
| `/mcp/resources` | GET | Available resources |
| `/mcp/resources/{uri}` | GET | Read a specific resource |
| `/mcp/prompts` | GET | Prompt templates |

**Resources**:
- `site://guidelines` - Editorial content standards
- `site://info` - Site name, description, URL
- `site://posts/recent` - Latest published content

**Prompt templates**:
- `write_post` - Draft a post following guidelines
- `summarize` - Summarize existing content
- `seo_optimize` - Suggest SEO improvements

**Authentication**: HTTP Basic Auth or Bearer token (stored as `cr_mcp_api_key` option).

---

## Database Schema

13 tables with configurable prefix (default `cr_`):

| Table | Purpose | Key Columns |
|---|---|---|
| `cr_posts` | All content types | ID, post_type, post_status, post_author, post_name |
| `cr_postmeta` | Post metadata (EAV) | post_id, meta_key, meta_value |
| `cr_users` | User accounts | ID, user_login, user_pass (bcrypt), user_email |
| `cr_usermeta` | User metadata | user_id, meta_key, meta_value |
| `cr_terms` | Taxonomy terms | term_id, name, slug |
| `cr_term_taxonomy` | Term-taxonomy linkage | term_id, taxonomy, parent, count |
| `cr_term_relationships` | Object-term assignments | object_id, term_taxonomy_id |
| `cr_termmeta` | Term metadata | term_id, meta_key, meta_value |
| `cr_comments` | Comments | comment_post_ID, comment_content, comment_approved |
| `cr_commentmeta` | Comment metadata | comment_id, meta_key, meta_value |
| `cr_options` | Site settings | option_name (unique), option_value, autoload |
| `cr_json_meta` | JSON metadata | object_type, object_id, meta (JSON column) |
| `cr_queue` | Async job queue | hook, args, status, priority, scheduled_at |

---

## Testing

```bash
php tests/run.php
```

Creates an isolated `cleanroom_test` database, seeds test data, runs all suites, drops the database. Zero side effects on your real data.

### Suite Breakdown

| Suite | Assertions | Category |
|---|---|---|
| Hooks System | 24 | Unit |
| Serialization Helpers | 14 | Unit |
| Shortcodes System | 14 | Unit |
| Escaping Functions | 11 | Unit |
| Sanitize Functions | 8 | Unit |
| URL Router | 12 | Unit |
| Database Layer | 22 | Integration |
| Options API | 20 | Integration |
| Meta API | 20 | Integration |
| Post Types System | 26 | Integration |
| Taxonomy System | 28 | Integration |
| Query Engine | 25 | Integration |
| User System | 34 | Integration |
| Template Engine | 19 | Integration |
| Plugin Sandbox | 27 | Integration |
| JSON Meta System | 30 | Integration |
| LRU Cache + Namespaced Options | 25 | Integration |
| Security System | 32 | Integration |
| Async Queue System | 24 | Integration |
| AI Client SDK | 40 | Integration |
| Abilities API | 39 | Integration |
| Content Guidelines | 24 | Integration |
| MCP Adapter | 33 | Integration |
| REST API | 34 | API |
| **Total** | **585** | |

---

## Configuration Reference (`config.php`)

| Constant | Purpose | Example |
|---|---|---|
| `DB_NAME` | Database name | `'cleanroom'` |
| `DB_USER` | Database user | `'root'` |
| `DB_PASSWORD` | Database password | `''` |
| `DB_HOST` | Database host | `'localhost'` |
| `DB_CHARSET` | Character set | `'utf8mb4'` |
| `CR_SITE_URL` | Full site URL | `'https://example.com'` |
| `CR_HOME_URL` | Home page URL | `'https://example.com'` |
| `CR_DEBUG` | Enable debug mode | `true` / `false` |
| `CR_DEBUG_LOG` | Log errors to file | `true` / `false` |
| `CR_TRUSTED_PROXIES` | IPs allowed to set X-Forwarded-For | `['10.0.0.1']` |
| `AUTH_KEY` | Cookie signing key | Random string |
| `NONCE_KEY` | Nonce generation key | Random string |
| `$table_prefix` | Database table prefix | `'cr_'` |

---

## License

All code is original. Built using clean-room design methodology.
