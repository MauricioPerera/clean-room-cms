# Clean Room CMS

Modern content management system built from scratch using [clean-room design](https://en.wikipedia.org/wiki/Clean-room_design) methodology. Every line is original. Zero external dependencies.

```
79 PHP files · 21,802 lines · 721 tests · 27 suites · 0 dependencies · PHP 8.2+
```

---

## Why

Most CMS platforms carry decades of technical debt, full-trust plugin models, and architectures that predate modern PHP. Clean Room CMS starts from zero with:

- **Content Builder** — define custom types, taxonomies, and meta fields from the admin UI or API, no code needed
- **ACF-style fields** — field groups with location rules, conditional logic (9 operators, AND/OR), repeater fields
- **Plugin sandboxing** — 17 granular permissions per plugin, declared in `manifest.json`, admin-approved
- **AI-native** — provider-agnostic AI client, Abilities API, Content Guidelines, MCP server, vector search + RAG
- **Security built-in** — CSP nonces, rate limiting, brute force protection, HMAC cookies, CSRF nonces on all actions
- **721 tests** — every public function verified, 27 suites, 100% pass rate, 2 security audits passed

---

## Quick Start

```bash
git clone https://github.com/MauricioPerera/clean-room-cms.git
cd clean-room-cms

mysql -u root -e "CREATE DATABASE cleanroom"
cp config-sample.php config.php
# Edit config.php with your credentials

php -S localhost:8080 index.php
# Open http://localhost:8080 → installer runs automatically
```

Admin panel: `http://localhost:8080/admin/`

---

## Architecture

```
core/                          19 modules
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
  content-builder.php          DB-driven content types, taxonomies, field groups, meta fields
  bootstrap.php                Load sequence

core/ai/                       5 modules
  client.php                   AI SDK (OpenAI, Anthropic, Ollama)
  abilities.php                Capability registry + JSON Schema
  guidelines.php               Editorial standards for AI agents
  mcp.php                      Model Context Protocol server
  vectors.php                  Semantic search + RAG (php-vector-store)

api/rest-api.php               RESTful API with dynamic routes
admin/                         Full admin panel (20+ pages)
content/themes/default/        Default theme (10 templates)
install/                       Schema (16 tables) + web installer
tests/                         721 assertions, 27 suites
```

---

## Admin Panel

Full management UI for every feature. No code needed for day-to-day operations.

```
Dashboard
──────────
Posts · Pages · Media · [Custom Types]
──────────
Categories · Tags · Comments
──────────
Content Types · Taxonomies · Field Groups · Meta Fields
──────────
Users · Roles · Plugins · Themes
──────────
AI Settings · Guidelines · Vector Search
──────────
API Docs · Queue · Security · Settings
```

---

## Content Builder

Define custom content structures entirely from the UI or API:

### Custom Content Types
```
/admin/?page=content-types
```
Create "Products", "Events", "Portfolios" — with icon, supports (title, editor, thumbnail), REST API exposure, search visibility, archive pages. Auto-appears in sidebar and gets REST endpoints.

### Custom Taxonomies
```
/admin/?page=content-taxonomies
```
Create "Brands", "Genres" — hierarchical or flat, linked to any post type(s). Automatically shows as checkboxes or comma input in the post editor.

### Field Groups (ACF-style)
```
/admin/?page=field-groups
```
Group meta fields into collapsible panels with **location rules** (show only on specific post types).

### Meta Fields
```
/admin/?page=meta-fields
```
16 field types: text, textarea, number, email, url, date, datetime, time, select, radio, checkbox, color, range, tel, image, wysiwyg, **repeater**.

**Conditional Logic** per field:
```
Show "Weight" only when "Product Type" equals "physical"
Show "Download URL" only when "Product Type" equals "digital"
```
9 operators: `==`, `!=`, `>`, `<`, `>=`, `<=`, `contains`, `empty`, `not_empty`. AND/OR relation. Real-time JS toggle + server-side validation.

**Repeater Fields** — add N rows of sub-fields:
```
Features [Add Feature]
┌─────────────────────────────────┐
│ 1  Title: Fast     Icon: ⚡  [×]│
│ 2  Title: Secure   Icon: 🔒  [×]│
└─────────────────────────────────┘
```

### Roles & Profile Fields

Create custom roles with granular capabilities from `/admin/?page=roles`:

```
Role: Vendor
Capabilities: read, edit_posts, upload_files
```

Then create meta fields scoped to that role (object_type = `user`, post_type = `vendor`):

| Field | Type | Description |
|---|---|---|
| `company_name` | text | Company name |
| `phone` | tel | Contact phone |
| `tax_id` | text | Tax identifier |

When a user is assigned the "Vendor" role, these profile fields appear automatically in their edit form. Built-in roles (administrator, editor, author, contributor, subscriber) can be customized but not deleted.

### Via API
```bash
# Create content type
curl -u admin:pass -X POST /api/cr/v1/content-types \
  -d '{"name":"product","label":"Products"}'

# Create meta field
curl -u admin:pass -X POST /api/cr/v1/meta-fields \
  -d '{"name":"price","label":"Price","field_type":"number","post_type":"product"}'

# Auto-generated CRUD for products
curl /api/cr/v1/products
```

---

## AI System

### Provider-Agnostic Client
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

Configure providers from `/admin/?page=ai-settings` — API keys, default model, MCP token.

### Abilities API
```php
register_ability('translate', [
    'description' => 'Translate a post',
    'permission'  => 'edit_posts',
    'input_schema' => [...],
    'callback' => fn($input) => ['translated' => '...'],
]);
```
AI models discover and invoke abilities via function calling or MCP.

### Content Guidelines
Edit from `/admin/?page=guidelines` — 5 sections (site, copy, images, blocks, additional). Auto-injected into AI prompts via `->with_guidelines()`.

### Vector Search + RAG
Configure from `/admin/?page=vector-settings`. Uses [php-vector-store](https://github.com/MauricioPerera/php-vector-store) for:
```php
cr_vectors()->search('posts', 'How to deploy PHP apps?');  // Semantic search
cr_vectors()->find_similar($post_id);                       // Related posts
cr_vectors()->ask('What topics does this site cover?');     // RAG: search + AI answer
```

### MCP Server
```
GET  /mcp/           Server info
GET  /mcp/tools      Available tools (from Abilities API)
POST /mcp/execute    Invoke a tool
GET  /mcp/resources  Site guidelines, info, recent posts
GET  /mcp/prompts    Prompt templates
```

---

## Security

Built-in on every request:
- Content-Security-Policy with nonce-based scripts
- X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy
- Strict-Transport-Security (HTTPS)
- CSRF nonces on all destructive actions (GET and POST)
- Rate limiting: API (100 req/min) + login (5 attempts/5 min)
- Brute force lockout with configurable thresholds
- HMAC-SHA256 signed auth cookies
- Plugin sandbox with 17 granular permissions
- Input sanitization by context (post/comment/title)
- Server-side MIME validation on file uploads
- Two security audits: 34 issues found and fixed

Configure from `/admin/?page=security`.

---

## REST API

Base: `/api/cr/v1/`

| Endpoint | Methods | Auth |
|---|---|---|
| `/posts` | GET, POST | POST |
| `/posts/{id}` | GET, PUT, DELETE | Write |
| `/pages` | GET, POST | POST |
| `/categories`, `/tags` | GET, POST | POST |
| `/users`, `/users/me` | GET | Yes |
| `/search?search=keyword` | GET | No |
| `/settings` | GET, POST | Yes |
| `/content-types` | GET, POST | Admin |
| `/content-types/{name}` | PUT, DELETE | Admin |
| `/meta-fields` | GET, POST | Admin |
| `/meta-fields/{id}` | PUT, DELETE | Admin |
| `/{custom-type}` | GET, POST, PUT, DELETE | Auto-generated |
| `/{custom-taxonomy}` | GET, POST | Auto-generated |

Headers: `X-CR-Total`, `X-CR-TotalPages`. Auth: HTTP Basic or `X-CR-Nonce`. Rate limited. CORS configurable.

---

## Testing

```bash
php tests/run.php
```

Creates isolated `cleanroom_test` database, seeds data, runs all suites, drops database.

| Category | Suites | Assertions |
|---|---|---|
| Unit (no DB) | 6 | 83 |
| Integration | 20 | 604 |
| API | 1 | 34 |
| **Total** | **27** | **721** |

---

## Requirements

- PHP 8.2+
- MySQL 8.0+ or MariaDB 10.4+
- No Composer, no npm, no external packages

---

## Documentation

Full API reference and system documentation: **[DOCS.md](DOCS.md)**

---

## License

All code is original. Built using clean-room design methodology.
