# Clean Room CMS

Modern content management system built from scratch using [clean-room design](https://en.wikipedia.org/wiki/Clean-room_design) methodology. Every line is original. Zero external dependencies.

```
65 PHP files  ·  12,817 lines  ·  585 tests  ·  0 dependencies  ·  PHP 8.2+
```

---

## Why

Most CMS platforms carry decades of technical debt, full-trust plugin models, and architectures that predate modern PHP. Clean Room CMS starts from zero with:

- **Plugin sandboxing** — 17 granular permissions per plugin, declared in `manifest.json`, admin-approved
- **JSON metadata** — single-row-per-object alternative to the slow EAV (Entity-Attribute-Value) pattern
- **Async job queue** — priority-based with exponential backoff retry and dead letter queue
- **Security built-in** — CSP nonce headers, rate limiting, brute force protection, HMAC-signed cookies
- **AI-native architecture** — provider-agnostic AI client, Abilities API, Content Guidelines, MCP server
- **585 tests** — every public function verified, 24 suites, 100% pass rate

---

## Quick Start

```bash
git clone https://github.com/MauricioPerera/clean-room-cms.git
cd clean-room-cms

# Configure
mysql -u root -e "CREATE DATABASE cleanroom"
cp config-sample.php config.php
# Edit config.php with your credentials

# Run
php -S localhost:8080 index.php

# Open http://localhost:8080 → installer runs automatically
```

Admin panel: `http://localhost:8080/admin/`

---

## Architecture

```
core/                          18 modules
  hooks.php                    Actions & filters with priority
  database.php                 PDO abstraction, prepared statements
  options.php                  Key-value settings with autoload
  meta.php                     Entity metadata (EAV)
  jsonmeta.php                 JSON column metadata (modern)
  post-types.php               Content types + CRUD
  taxonomies.php               Categories, tags, custom taxonomies
  query.php                    Query builder, The Loop, conditionals
  router.php                   URL → query variable parsing
  rewrite.php                  Custom rewrite rules
  template.php                 Template hierarchy + assets
  shortcodes.php               [shortcode] processor
  user.php                     Auth, roles, capabilities, nonces
  cache.php                    LRU cache + namespaced plugin options
  sandbox.php                  Plugin permission enforcement
  security.php                 Headers, rate limiting, sanitization
  queue.php                    Async jobs with retry + dead letter
  bootstrap.php                Load sequence

core/ai/                       4 modules
  client.php                   AI SDK (OpenAI, Anthropic, Ollama)
  abilities.php                Capability registry + JSON Schema
  guidelines.php               Editorial standards for AI agents
  mcp.php                      Model Context Protocol server

api/rest-api.php               RESTful API (cr/v1 namespace)
admin/index.php                Admin panel
content/themes/default/        Default theme (10 templates)
install/                       Schema + web installer
tests/                         585 assertions, 24 suites
```

---

## Key Features

### Content Engine

```php
register_post_type('product', ['label' => 'Products', 'public' => true]);

$id = cr_insert_post(['post_title' => 'Widget', 'post_type' => 'product', 'post_status' => 'publish']);

$query = new CR_Query(['post_type' => 'product', 's' => 'widget', 'posts_per_page' => 10]);
while (have_posts()) { the_post(); echo get_the_title(); }
```

### JSON Metadata

```php
cr_post_json_set($id, ['price' => 29.99, 'specs' => ['weight' => '200g', 'color' => 'black']]);
cr_post_json_get($id, 'specs.color');                  // 'black'
cr_json_meta_query('post', 'price', 20, '>');          // [post IDs where price > 20]
```

### Plugin Sandbox

```json
// plugins/my-plugin/manifest.json
{
    "name": "My Plugin",
    "permissions": ["options:read", "hooks:core", "http:outbound"]
}
```

Unauthorized actions throw `CR_Sandbox_Exception`. Admin grants permissions explicitly.

### AI Client

```php
$response = cr_ai()
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a content editor.')
    ->user('Summarize this article...')
    ->with_guidelines()
    ->tools(cr_get_abilities_as_tools())
    ->send();
```

### Abilities API

```php
register_ability('translate', [
    'description'  => 'Translate a post',
    'permission'   => 'edit_posts',
    'input_schema' => ['type' => 'object', 'properties' => [
        'post_id'  => ['type' => 'integer'],
        'language' => ['type' => 'string', 'enum' => ['es', 'fr', 'de']],
    ], 'required' => ['post_id', 'language']],
    'callback' => fn($input) => ['translated' => '...'],
]);

// AI models can discover and call this via MCP or function calling
```

### REST API

```
GET    /api/cr/v1/posts
POST   /api/cr/v1/posts           (auth required)
GET    /api/cr/v1/posts/{id}
PUT    /api/cr/v1/posts/{id}      (auth required)
DELETE /api/cr/v1/posts/{id}      (auth required)
GET    /api/cr/v1/categories
GET    /api/cr/v1/search?search=keyword
GET    /api/cr/v1/settings        (auth required)
```

Rate limited: 100 req/min per IP. CORS restricted to same origin.

### MCP Server

External AI assistants discover site capabilities at `/mcp/`:

```
GET  /mcp/           Server info
GET  /mcp/tools      Available tools (from Abilities API)
POST /mcp/execute    Invoke a tool
GET  /mcp/resources  Site resources (guidelines, info, posts)
GET  /mcp/prompts    Prompt templates
```

### Security

Automatic on every request:
- Content-Security-Policy with nonce-based scripts
- X-Frame-Options, X-Content-Type-Options, Referrer-Policy
- Strict-Transport-Security (HTTPS)
- Rate limiting on API and login
- Brute force lockout (5 failures → 30 min IP ban)
- HMAC-signed auth cookies
- Input sanitization by context (post/comment/title)

---

## Testing

```bash
php tests/run.php
```

Creates isolated `cleanroom_test` database, seeds data, runs 24 suites, drops database.

| Category | Suites | Assertions |
|---|---|---|
| Unit (no DB) | 6 | 83 |
| Integration | 17 | 468 |
| API | 1 | 34 |
| **Total** | **24** | **585** |

---

## Requirements

- PHP 8.2+
- MySQL 8.0+ or MariaDB 10.4+
- No Composer, no npm, no external packages

---

## Documentation

Full API reference, configuration guide, and system documentation: **[DOCS.md](DOCS.md)**

---

## License

All code is original. Built using clean-room design methodology.
