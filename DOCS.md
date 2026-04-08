# Clean Room CMS

A modern content management system built from scratch using clean-room methodology.
Every line of code is original. No external dependencies. PHP 8.2+, MySQL/MariaDB.

---

## Quick Start

```bash
# 1. Create database
mysql -u root -e "CREATE DATABASE cleanroom"

# 2. Configure
# Edit config.php with your DB credentials

# 3. Run
php -S localhost:8080 index.php

# 4. Install
# Navigate to http://localhost:8080 - installer runs automatically

# 5. Admin panel
# http://localhost:8080/admin/

# 6. Run tests
php tests/run.php
```

---

## Architecture

```
clean room/
  index.php                     Front controller - all requests route here
  config.php                 Database credentials, paths, constants
  worker.php                    Background queue worker (cron/supervisor)
  .htaccess                     Apache URL rewriting

  core/                         Framework core (18 modules)
    bootstrap.php               Load sequence, initialization
    hooks.php                   Event system (actions & filters)
    database.php                PDO abstraction with prepared statements
    options.php                 Key-value site settings with autoload
    meta.php                    Entity metadata (EAV pattern, backwards-compatible)
    post-types.php              Content type registry + CRUD
    taxonomies.php              Classification system (categories, tags, custom)
    query.php                   SQL query builder + The Loop + conditional tags
    router.php                  URL parsing into query variables
    rewrite.php                 Custom URL rewrite rules
    template.php                Template hierarchy engine + asset management
    shortcodes.php              [shortcode] syntax processor
    user.php                    Authentication, roles, capabilities, sessions
    cache.php                   LRU object cache + namespaced plugin options
    sandbox.php                 Granular plugin permission system
    security.php                CSP headers, rate limiting, brute force protection
    jsonmeta.php                JSON column metadata (modern alternative to EAV)
    queue.php                   Async job queue with retry + dead letter

  core/ai/                      AI subsystem (4 modules)
    client.php                  Provider-agnostic AI SDK (OpenAI, Anthropic, Ollama)
    abilities.php               Capability registry with JSON Schema validation
    guidelines.php              Editorial content standards for AI agents
    mcp.php                     Model Context Protocol adapter

  api/
    rest-api.php                RESTful API (posts, pages, categories, tags, users, search, settings)

  admin/
    index.php                   Admin panel (dashboard, CRUD, settings, login)
    assets/css/admin.css        Admin styles

  content/
    themes/default/             Default theme (10 template files)
    plugins/                    Plugin directory
    uploads/                    Media uploads

  install/
    schema.sql                  Database schema (11 tables)
    installer.php               Web-based installation wizard

  tests/                        Test suite (585 tests across 24 suites)
    run.php                     Test runner
    TestCase.php                Assertion library
    bootstrap.php               Test environment setup
    Unit/                       6 unit test files (no database needed)
    Integration/                17 integration test files
    API/                        1 API test file
```

---

## Metrics

| Metric | Value |
|--------|-------|
| Core PHP files | 22 |
| Lines of code (core) | 6,721 |
| Lines of code (tests) | 2,899 |
| Lines of code (total) | 11,301 |
| Test suites | 24 |
| Test assertions | 585 |
| Pass rate | 100% |
| External dependencies | 0 |
| Minimum PHP version | 8.2 |

---

## Core Systems

### 1. Hook System (`core/hooks.php`)

Event-driven architecture. All extensibility flows through hooks.

**Actions** execute side effects at specific points:
```php
// Register
add_action('after_post_save', function(int $post_id, object $post) {
    log("Post {$post_id} saved");
}, priority: 10, accepted_args: 2);

// Trigger
do_action('after_post_save', $post_id, $post);
```

**Filters** transform data through a callback chain:
```php
// Register
add_filter('the_content', function(string $content): string {
    return $content . '<p>Appended text</p>';
});

// Apply
$content = apply_filters('the_content', $raw_content);
```

**Execution order**: Lower priority number runs first. Same priority preserves insertion order.

**Introspection**:
```php
has_filter('hook_name', $callback);   // Check if registered
did_action('hook_name');              // Count of times fired
doing_action('hook_name');            // True during execution
current_filter();                     // Name of current hook
remove_filter('hook_name', $callback, $priority);
```

---

### 2. Database Layer (`core/database.php`)

PDO-based abstraction with prepared statements and CRUD helpers.

```php
$db = cr_db();

// Prepared statements (sprintf-style: %s = string, %d = int, %f = float)
$sql = $db->prepare("SELECT * FROM `{$db->prefix}posts` WHERE ID = %d AND status = %s", 42, 'publish');

// CRUD
$id    = $db->insert('cr_posts', ['post_title' => 'Hello', 'post_status' => 'publish']);
$rows  = $db->update('cr_posts', ['post_title' => 'Updated'], ['ID' => $id]);
$count = $db->delete('cr_posts', ['ID' => $id]);

// Queries
$row     = $db->get_row("SELECT * FROM cr_posts WHERE ID = 1");        // Single object
$results = $db->get_results("SELECT * FROM cr_posts LIMIT 10");        // Array of objects
$value   = $db->get_var("SELECT COUNT(*) FROM cr_posts");              // Single value
$column  = $db->get_col("SELECT post_title FROM cr_posts LIMIT 5");    // Array of values

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
// Register custom type
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

**EAV pattern** (traditional, one row per key):
```php
add_post_meta($id, 'price', 29.99);
$price = get_post_meta($id, 'price', single: true);
update_post_meta($id, 'price', 39.99);
delete_post_meta($id, 'price');

// Also: get_user_meta, add_term_meta, get_comment_meta, etc.
```

**JSON column** (modern, one row per object - `core/jsonmeta.php`):
```php
// Set entire metadata document
cr_post_json_set($id, [
    'seo'    => ['title' => 'Custom Title', 'description' => 'Meta desc'],
    'price'  => 29.99,
    'tags'   => ['featured', 'sale'],
]);

// Read by dot path
$title = cr_post_json_get($id, 'seo.title');           // 'Custom Title'
$all   = cr_post_json_get($id);                         // Full array

// Atomic path update (no full read-write cycle)
cr_post_json_set($id, 'seo.title', 'New Title');

// Remove a path
cr_post_json_remove($id, 'seo.description');

// Query by value
$ids = cr_json_meta_query('post', 'price', 20, '>');    // Posts with price > 20

// Bulk fetch (avoids N+1)
$bulk = cr_json_meta_get_bulk('post', [1, 2, 3, 4, 5]);
```

---

### 4. Query Engine (`core/query.php`)

```php
// Direct query
$query = new CR_Query([
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 10,
    'paged'          => 2,
    'orderby'        => 'date',
    'order'          => 'DESC',
    's'              => 'search term',          // Full-text search
    'cat'            => 5,                       // Category ID
    'tag'            => 'php',                   // Tag slug
    'author'         => 1,                       // Author ID
    'year'           => 2026,                    // Date filter
    'meta_key'       => 'featured',             // Meta filter
    'meta_value'     => '1',
    'post__in'       => [10, 20, 30],           // Specific IDs
]);

echo $query->found_posts;    // Total matching
echo $query->max_num_pages;  // Total pages

// The Loop (template pattern)
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
is_home();          is_front_page();
is_single();        is_page();         is_singular('product');
is_archive();       is_category();     is_tag();
is_author();        is_date();         is_search();
is_404();
```

---

### 5. URL Routing (`core/router.php` + `core/rewrite.php`)

Built-in route patterns:

| URL Pattern | Query Variables |
|---|---|
| `/` | Home page |
| `/?p=123` | Post by ID |
| `/?page_id=5` | Page by ID |
| `/?s=keyword` | Search |
| `/category/news/` | Category archive |
| `/tag/php/` | Tag archive |
| `/author/john/` | Author archive |
| `/2026/04/07/post-slug/` | Date-based post |
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

The engine walks a cascade of template files, loading the first one that exists:

| Request | Template cascade |
|---|---|
| Single post | `single-{type}-{slug}.php` > `single-{type}.php` > `single.php` > `singular.php` > `index.php` |
| Page | `page-{slug}.php` > `page-{id}.php` > `page.php` > `singular.php` > `index.php` |
| Category | `category-{slug}.php` > `category-{id}.php` > `category.php` > `archive.php` > `index.php` |
| Search | `search.php` > `index.php` |
| 404 | `404.php` > `index.php` |
| Home | `front-page.php` > `home.php` > `index.php` |

Template partials:
```php
get_header('custom');                          // header-custom.php or header.php
get_footer();                                  // footer.php
get_sidebar();                                 // sidebar.php
get_template_part('parts/card', 'featured');   // parts/card-featured.php
```

Asset management:
```php
cr_enqueue_style('main', '/css/main.css', [], '1.0');
cr_enqueue_script('app', '/js/app.js', [], '1.0', in_footer: true);
```

Theme info:
```php
bloginfo('name');              // Site title
bloginfo('description');       // Tagline
bloginfo('url');               // Home URL
cr_get_theme_url();            // Active theme URL
body_class('custom-class');    // CSS class string
```

---

### 7. User System (`core/user.php`)

```php
// Create user
$user_id = cr_create_user('johndoe', 'securepass', 'john@example.com', [
    'display_name' => 'John Doe',
    'role'         => 'editor',
]);

// Authenticate
$user_id = cr_authenticate('johndoe', 'securepass');  // Returns ID or false
cr_set_auth_cookie($user_id);                          // HMAC-signed cookie
cr_clear_auth_cookie();                                // Logout

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
current_user_can('manage_options');
user_can($user_id, 'publish_posts');

// Nonces (CSRF protection)
$nonce = cr_create_nonce('delete_post_42');
$valid = cr_verify_nonce($nonce, 'delete_post_42');
```

Built-in roles: `administrator`, `editor`, `author`, `contributor`, `subscriber`.

```php
add_role('moderator', 'Moderator', ['moderate_comments' => true, 'read' => true]);
```

---

### 8. REST API (`api/rest-api.php`)

Base URL: `/api/cr/v1/`

| Endpoint | Methods | Auth Required |
|---|---|---|
| `/posts` | GET, POST | POST |
| `/posts/{id}` | GET, PUT, PATCH, DELETE | Write ops |
| `/pages` | GET, POST | POST |
| `/pages/{id}` | GET, PUT, PATCH, DELETE | Write ops |
| `/categories` | GET, POST | POST |
| `/tags` | GET, POST | POST |
| `/users` | GET | Yes |
| `/users/me` | GET | Yes |
| `/search?search=keyword` | GET | No |
| `/settings` | GET, POST | Yes |

**Query parameters**: `per_page`, `page`, `search`, `orderby`, `order`, `status`, `author`, `_fields`

**Response headers**: `X-CR-Total`, `X-CR-TotalPages`

**Authentication**: HTTP Basic Auth (username:password) or `X-CR-Nonce` header.

**Rate limiting**: 100 requests/minute per IP (configurable via `cr_api_rate_limit` filter).

Register custom endpoints:
```php
register_rest_route('myplugin/v1', '/items', [
    'methods'             => 'GET',
    'callback'            => fn($params) => ['items' => []],
    'permission_callback' => fn() => current_user_can('read'),
]);
```

---

## Security Features (`core/security.php`)

### Headers (automatic on every request)
- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `Content-Security-Policy` with nonce-based script policy
- `Strict-Transport-Security` (on HTTPS)

### Rate Limiting
```php
// Generic rate limiter
$allowed = CR_Security::rate_limit('key:identifier', max: 100, window: 60);

// Built-in limiters
CR_Security::rate_limit_api();     // 100 req/min per IP
CR_Security::rate_limit_login();   // 5 attempts/5 min per IP
```

### Brute Force Protection
Automatic on login: 5 failed attempts in 30 minutes locks the IP.
```php
CR_Security::is_login_locked($ip);
CR_Security::clear_failed_logins($ip);
```

### Input Sanitization
```php
CR_Security::sanitize_email('  user@example.com  ');  // 'user@example.com'
CR_Security::sanitize_url('javascript:alert(1)');      // ''
CR_Security::sanitize_html($dirty, 'post');            // Strips <script>, onclick=, javascript:
CR_Security::sanitize_html($dirty, 'comment');         // More restrictive
CR_Security::sanitize_html($dirty, 'title');           // Strips ALL tags
```

### CSRF
```php
echo CR_Security::csrf_field();                        // Hidden input with token
CR_Security::csrf_validate($_POST['_cr_csrf']);         // Verify
```

---

## Plugin Sandbox (`core/sandbox.php`)

Plugins must declare required permissions in `manifest.json`. Admin must approve them.

### Plugin manifest.json
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

### Available permissions
| Permission | Scope |
|---|---|
| `db:read` | Read from any database table |
| `db:write` | Write to any database table |
| `db:own` | Read/write only plugin's own tables |
| `options:read` | Read site options |
| `options:write` | Write site options |
| `users:read` | Read user data |
| `users:write` | Modify user data |
| `files:read` | Read files from disk |
| `files:write` | Write files (uploads, cache) |
| `http:outbound` | Make external HTTP requests |
| `hooks:core` | Register hooks on core actions/filters |
| `admin:pages` | Add admin menu pages |
| `rest:endpoints` | Register REST API endpoints |
| `cron:schedule` | Schedule async tasks |
| `content:filter` | Filter post content |
| `exec:shell` | Execute shell commands (DANGEROUS) |

### Admin API
```php
CR_Sandbox::grant_permissions('seo-toolkit', ['options:read', 'hooks:core']);
CR_Sandbox::revoke_permissions('seo-toolkit');
CR_Sandbox::get_violations();    // Security audit log
```

### Inside plugin code
```php
// Sandbox auto-enforces. If plugin tries unauthorized action:
CR_Sandbox::enforce('http:outbound');  // Throws CR_Sandbox_Exception if not granted
```

---

## Object Cache (`core/cache.php`)

In-memory LRU cache with TTL support, groups, and eviction.

```php
$cache = cr_cache();

$cache->set('group', 'key', $value, ttl: 300);    // 5 minute TTL
$value = $cache->get('group', 'key', $default);
$cache->delete('group', 'key');
$cache->flush_group('group');
$cache->flush_all();
$cache->exists('group', 'key');

// Stats
$stats = $cache->stats();
// ['hits' => 150, 'misses' => 23, 'sets' => 80, 'evictions' => 5, 'hit_rate' => 86.7]
```

### Namespaced Plugin Options
Plugins get isolated, prefixed options that can't collide:
```php
cr_plugin_option_set('my-plugin', 'version', '2.0');
cr_plugin_option_get('my-plugin', 'version');        // '2.0'
cr_plugin_option_delete('my-plugin', 'version');
cr_plugin_option_cleanup('my-plugin');               // Delete ALL plugin options (uninstall)
```

### Cached queries
```php
$results = cr_cached_query("SELECT * FROM cr_posts LIMIT 10", ttl: 300);
cr_invalidate_query_cache();  // After writes
```

---

## Async Queue (`core/queue.php`)

Database-backed job queue with priorities, retry with exponential backoff, and dead letter queue.

```php
// One-time job
cr_queue_push('send_email', ['to' => 'user@example.com', 'subject' => 'Hello']);

// With options
cr_queue_push('process_image', ['id' => 42], [
    'priority'     => 1,        // Lower = higher priority
    'group'        => 'media',
    'delay'        => 300,      // Run 5 minutes from now
    'max_attempts' => 5,
]);

// Recurring job
cr_schedule_event('cleanup_expired', 'daily');
cr_schedule_event('sync_inventory', 'hourly');
cr_unschedule_event('sync_inventory');

// Job handlers (via hooks)
add_action('send_email', function(string $to, string $subject) {
    mail($to, $subject, 'Body...');
});

// Stats
CR_Queue::stats();
// ['pending' => 12, 'running' => 2, 'completed' => 150, 'dead' => 3]

// Dead letter queue inspection
$dead_jobs = CR_Queue::get_dead_letter();
CR_Queue::retry_job($job_id);
```

### Worker
```bash
# Process one batch (for crontab):
* * * * * php /path/to/worker.php

# Continuous daemon (for supervisor):
php worker.php --daemon

# Custom batch size:
php worker.php --batch=20
```

Retry strategy: exponential backoff (30s, 60s, 120s, 240s... max 1 hour).
After `max_attempts`, job moves to dead letter queue.

---

## AI Subsystem

### AI Client (`core/ai/client.php`)

Provider-agnostic SDK. Connect any LLM through a unified interface.

```php
// Configure (stored in options, loaded on init)
update_option('cr_ai_connectors', [
    'openai' => ['enabled' => true, 'api_key' => 'sk-...'],
    'anthropic' => ['enabled' => true, 'api_key' => 'sk-ant-...'],
    'ollama' => ['enabled' => true, 'base_url' => 'http://localhost:11434'],
]);

// Fluent prompt builder
$response = cr_ai()
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a helpful content editor.')
    ->user('Summarize this article: ...')
    ->temperature(0.3)
    ->max_tokens(500)
    ->with_guidelines()              // Auto-inject site content guidelines
    ->send();

if ($response->success) {
    echo $response->content;         // AI response text
    echo $response->model;           // Model that responded
    echo $response->usage;           // Token usage
}

// With tool calling (function calling)
$response = cr_ai()
    ->system('You can search the site and create posts.')
    ->user('Find recent posts about PHP and create a summary post.')
    ->tools(cr_get_abilities_as_tools())
    ->send();

if ($response->has_tool_calls()) {
    $results = CR_Abilities::handle_tool_calls($response->tool_calls);
    // Feed results back to AI for next turn...
}
```

Supported providers:
| Provider | Class | Models |
|---|---|---|
| OpenAI | `CR_AI_Connector_OpenAI` | gpt-4o, gpt-4o-mini, o1, o3-mini |
| Anthropic | `CR_AI_Connector_Anthropic` | claude-opus-4-6, claude-sonnet-4-6 |
| Ollama | `CR_AI_Connector_Ollama` | llama3, mistral, codellama, phi3 |

Sandbox enforcement: if called from a plugin context, requires `http:outbound` permission.

---

### Abilities API (`core/ai/abilities.php`)

Registry of callable site capabilities with JSON Schema validation.

```php
// Register an ability
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
        $post = get_post($input['post_id']);
        // ... translation logic ...
        return ['translated_title' => '...', 'translated_content' => '...'];
    },
]);

// Execute with validation
$result = execute_ability('translate_post', ['post_id' => 1, 'language' => 'es']);
// Input validated against schema, permission checked, output validated

// Project as AI tool declarations
$tools = cr_get_abilities_as_tools();
// Returns array compatible with OpenAI/Anthropic function calling format

// Handle tool calls from AI response
$results = CR_Abilities::handle_tool_calls($response->tool_calls);
```

Built-in abilities: `get_post`, `create_post`, `search_content`, `get_site_info`, `generate_excerpt`, `get_content_guidelines`, `update_content_guidelines`.

---

### Content Guidelines (`core/ai/guidelines.php`)

Define editorial standards that AI agents follow automatically.

```php
// Set guidelines
cr_update_content_guidelines('site', 'Developer-focused tech blog. Target: mid-senior engineers.');
cr_update_content_guidelines('copy', 'Tone: technical but approachable. No jargon without explanation. Use active voice.');
cr_update_content_guidelines('images', 'Prefer diagrams and code screenshots. No stock photos.');
cr_update_content_guidelines('blocks', 'Paragraphs max 3 sentences. Use code blocks for all examples.');

// Bulk set
cr_set_content_guidelines([
    'site'       => '...',
    'copy'       => '...',
    'images'     => '...',
    'blocks'     => '...',
    'additional' => '...',
]);

// Auto-inject into AI prompts
$response = cr_ai()
    ->with_guidelines()   // Adds guidelines as system prompt
    ->user('Write a post about PHP 8.2 features')
    ->send();

// Access programmatically
$prompt = cr_guidelines_as_system_prompt();     // Formatted for AI
$data   = cr_guidelines_as_structured();         // For API/MCP
```

---

### MCP Adapter (`core/ai/mcp.php`)

Exposes site capabilities via [Model Context Protocol](https://spec.modelcontextprotocol.io/) so external AI assistants can discover and use them.

Base URL: `/mcp/`

| Endpoint | Method | Returns |
|---|---|---|
| `/mcp/` | GET | Server info + capabilities |
| `/mcp/tools` | GET | All available tools (from Abilities API) |
| `/mcp/execute` | POST | Execute a tool by name |
| `/mcp/resources` | GET | List available resources |
| `/mcp/resources/{uri}` | GET | Read a specific resource |
| `/mcp/prompts` | GET | List prompt templates |

**Resources** exposed:
- `site://guidelines` - Editorial content standards
- `site://info` - Basic site information
- `site://posts/recent` - Recent published posts

**Prompt templates**:
- `write_post` - Write a blog post following guidelines
- `summarize` - Summarize an existing post
- `seo_optimize` - Suggest SEO improvements

**Authentication**: HTTP Basic Auth or Bearer token (`cr_mcp_api_key` option).

---

## Database Schema

11 tables with configurable prefix (default: `cr_`):

| Table | Purpose | Key columns |
|---|---|---|
| `cr_posts` | All content (posts, pages, CPTs, revisions) | ID, post_type, post_status, post_author |
| `cr_postmeta` | Post metadata (key-value) | post_id, meta_key, meta_value |
| `cr_users` | User accounts | ID, user_login, user_pass (bcrypt) |
| `cr_usermeta` | User metadata (roles, settings) | user_id, meta_key, meta_value |
| `cr_terms` | Taxonomy terms | term_id, name, slug |
| `cr_term_taxonomy` | Term-taxonomy relation + hierarchy | term_id, taxonomy, parent, count |
| `cr_term_relationships` | Object-term assignments | object_id, term_taxonomy_id |
| `cr_termmeta` | Term metadata | term_id, meta_key, meta_value |
| `cr_comments` | Comments | comment_post_ID, comment_content |
| `cr_commentmeta` | Comment metadata | comment_id, meta_key, meta_value |
| `cr_options` | Site settings (key-value with autoload) | option_name, option_value, autoload |
| `cr_json_meta` | JSON metadata (modern) | object_type, object_id, meta (JSON) |
| `cr_queue` | Async job queue | hook, args, status, scheduled_at |

---

## Testing

```bash
php tests/run.php
```

### Suite breakdown

| Suite | Tests | Category |
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
| User System | 30 | Integration |
| Template Engine | 18 | Integration |
| Plugin Sandbox | 27 | Integration |
| JSON Meta System | 30 | Integration |
| LRU Cache | 25 | Integration |
| Security System | 31 | Integration |
| Async Queue | 24 | Integration |
| AI Client SDK | 30 | Integration |
| Abilities API | 38 | Integration |
| Content Guidelines | 22 | Integration |
| MCP Adapter | 31 | Integration |
| REST API | 32 | API |
| **Total** | **585** | |

Test environment: creates isolated `cleanroom_test` database, seeds data, runs tests, drops database. Zero side effects.

---

## License

All code is original. Clean-room implementation.
