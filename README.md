<p align="center">
  <img src="docs/public/cms-logo-safe.png" alt="CometCMS" height="52" />
</p>

<p align="center">
  A visual page builder and content CMS that runs on any shared PHP host.<br />
  No Node, no Composer, no database, no CLI, no SSH required.
</p>

---

## Upgrading to 2.0.0

Version 2.0.0 requires a fresh installation. Recreate content and website designs as needed; pre-2.0 storage and backups are unsupported. Backup inspection and restore accept only archives from version 2.0.0 or newer. Existing storage is never reset automatically. See [LEGACY.md](LEGACY.md) for the removal decisions and retained behavior.

## Why CometCMS

The entire CMS is a single PHP folder you upload to your server. That's it.

- **PHP 8.2+ is the only production dependency.** Every cheap shared hoster has it.
- **No database.** Content is stored as JSON files in `storage/`.
- **No Composer.** Nothing to install on the server.
- **No build step to deploy.** Run `make build` locally once, upload the `dist/` folder, done.
- **Visual Page Builder.** Build a server-rendered website with shared layouts, a theme, header, footer and menus. Keep design consistent while editors manage content.
- **Custom Fields and an optional headless API.** Bind structured content to your designs, or explicitly expose a content type through the API for Next.js, Astro, mobile apps or any other consumer.
- **Forms from content types.** Enable submissions on a collection, customize its messages and review incoming entries in the CMS.
- **Fine-grained access token permissions.** Scope tokens by action, content type, media category, and field. No overly broad keys.
- **Integrated backup system.** Create and restore full backups of all content, media, and settings from the admin UI.
- **Built-in localization.** Share one design across languages, translate builder content and structured fields, and publish each language with its own URLs. The API resolves content with `?locale=`.
- **Intentionally simple.** Not a framework, not extensible to infinity. Visual editing, structured content and a clean JSON API in one admin UI.

<p align="center">
  <img src="docs/screenshots/view-dashboard.png" alt="CometCMS admin dashboard" width="860" />
</p>

## Quick Start

1. Download the latest release from the Github releases.
2. Upload the contents of the .zip to your hosting account via FTP or your host's file manager.
3. Navigate to `https://yourdomain.com/admin` in your browser.
4. Configure your workspace and create your admin account on the setup screen. **Start with a website** is selected by default for a Pages collection with Landing Page, About and Hello World entries, and an Events collection with two sample events. Events demonstrate custom fields for date and time, location, event type, capacity, and registration status. The website starter enables frontend pages, sets the Landing Page entry as your homepage, and downloads Inter and Lora as normal installed fonts that you can replace or remove. If font downloads are unavailable, the website uses system fonts. Empty workspaces start without installed fonts. You can change or remove these defaults anytime, or choose **Start empty** for a blank workspace.
5. Edit your pages in the Page Builder, add custom fields as needed, and enable API exposure only for types you want to use headlessly.

No SSH. No CLI. No `composer install`. No environment variables required. Runs on the cheapest php hosting you can find.

## Public REST API

Public API endpoints must include a workspace slug: `/api/v1/workspaces/{workspace}/...`. Content types require explicit API exposure; published, unprotected entries are then public. Drafts and protected entries keep their access rules. Existing schemas retain their previous API access until reconfigured. A machine-readable OpenAPI contract is available at `docs/public/api/openapi.yaml` and is served in the docs as `/api/openapi.yaml`.

### Health

```
GET /api/v1/workspaces/{workspace}/health
```

### Content

```
GET /api/v1/workspaces/{workspace}/content-types
GET /api/v1/workspaces/{workspace}/content/{collection}
GET /api/v1/workspaces/{workspace}/content/{collection}/{identifier}
POST /api/v1/workspaces/{workspace}/content/{collection}
PUT /api/v1/workspaces/{workspace}/content/{collection}/{identifier}
DELETE /api/v1/workspaces/{workspace}/content/{collection}/{identifier}
```

Entry payloads use `id` for a stable opaque identifier and `slug` for the URL-safe slug. `{identifier}` may be either value.

Every content type is a collection. One-off content such as a start page is an entry, read like any other, e.g. `GET /api/v1/workspaces/{workspace}/content/pages/landing-page`.

Public reads return only `published` entries, or `scheduled` entries whose `published_at` is in the past. `draft`, `protected`, `archived`, and soft-deleted entries are hidden unless an authenticated token is used.

New content types have API exposure disabled. Enable **Expose custom fields through the API** to allow external reads. Enabling API exposure uses public visibility. Existing legacy private schemas require a token with `content.read` permission until reconfigured.

### Media

```
GET /api/v1/workspaces/{workspace}/media          # list uploaded files (with optional ?category=... and pagination)
GET /media/{workspace}/{filename}
```

### Authentication

Pass an access token in the `Authorization` header:

```
Authorization: Bearer ctcms_...
```

Public reads work without a token and only return public content. Tokens use the same permission grant format as roles, so access can be limited by action, content type, entry, media category, and field. Tokens are created in the **Access Tokens** section of the admin. Trash, backup/restore, settings, users, tokens, and webhook management are admin-only features under `/admin/api`.

## Embedded MCP

CometCMS includes a no-dependency Streamable HTTP MCP endpoint:

```http
POST /mcp/v1/workspaces/{workspace}
Authorization: Bearer ctcms_...
Content-Type: application/json
```

The embedded MCP uses the same access tokens and permission grants as the REST API. It exposes content type, content entry, and media management tools directly from PHP, so hosted installs do not need Node, Composer, SSH, or the standalone MCP package. Media uploads are intentionally omitted from the embedded MCP; use the admin UI or REST API upload endpoint for files.

## Website

Each workspace can also publish its content as a website, rendered by PHP on the same server. Enable **Page Builder** in a content type's settings and choose its page URLs (e.g. `/blog/{slug}`). Choose a homepage under **Website → Website settings → General** and design one layout per content type in the visual builder: sections, columns, field bindings, entry lists, menus and more, styled by a shared theme. The website is online while it has a homepage or at least one content type with the Page Builder enabled. Header, footer and menus are edited the same way. Private content types and unpublished entries are never shown; authorized editors can preview drafts.

Pages are cached, set no cookies, and include canonical URLs, `hreflang`, `/sitemap.xml` and `/robots.txt`. JavaScript is included only for interactive elements, including gallery viewers, internal link modals and custom blocks that bring their own script, each allowed individually by the Content-Security-Policy. Markdown is rendered with the bundled [Parsedown](https://parsedown.org) (MIT). See the [website guide](docs/guide/website.md). Developers can add their own page-builder elements as [extensions](docs/guide/extensions.md): ZIPs of reusable blocks and optional content types, components, templates and theme presets. Blocks render on the server; seeded content belongs to the site after installation. See [Comet Essentials](examples/extensions/comet-essentials), the builder extension with ready-to-use blocks including Maps, Slideshow and Stats.

### Filtering, Sorting, and Pagination

All list endpoints support:

| Parameter                 | Example                                 | Description                                |
| ------------------------- | --------------------------------------- | ------------------------------------------ |
| `limit`                   | `?limit=20`                             | Number of results (omit for all)           |
| `offset`                  | `?offset=40`                            | Pagination offset                          |
| `sort`                    | `?sort=-published_at`                   | Sort field; prefix with `-` for descending |
| `q`                       | `?q=hello`                              | Full-text search across text fields        |
| `locale`                  | `?locale=de`                            | Resolve localized fields for that locale   |
| `filter[field]`           | `?filter[is_promo_material]=true`       | Exact match                                |
| `filter[field][in]`       | `?filter[category][in]=news,tech`       | One of several values                      |
| `filter[field][ne]`       | `?filter[category][ne]=draft`           | Not equal                                  |
| `filter[field][gt]`       | `?filter[price][gt]=10`                 | Greater than                               |
| `filter[field][gte]`      | `?filter[published_at][gte]=2025-01-01` | Greater than or equal                      |
| `filter[field][lt]`       | `?filter[price][lt]=100`                | Less than                                  |
| `filter[field][lte]`      | `?filter[published_at][lte]=2025-12-31` | Less than or equal                         |
| `filter[field][contains]` | `?filter[title][contains]=launch`       | Case-insensitive substring match           |

Use `filter[...]` for all field queries. Boolean fields accept `true`/`false` (also `1`/`0`). Select fields can be matched by their stored option value; when a field stores an array, filters match if any item in the array matches. Public API media fields are always arrays of absolute media URLs, including single-file media fields. Sorting is type-aware for numeric values and ISO-style dates.

All content types expose the workspace `locales` and `default_locale` as read-only schema values. Configure languages once in workspace settings. Add `?locale={code}` to list or single-entry reads to resolve translated `title` and field values before search, filters, sorting, and relation expansion are applied. Without `locale`, responses resolve content in the workspace default language.

```http
GET /api/v1/workspaces/{workspace}/content/blogpost?filter[is_promo_material]=true
GET /api/v1/workspaces/{workspace}/content/blogpost?filter[category]=launch
GET /api/v1/workspaces/{workspace}/content/blogpost?filter[id]=7K4p9xQ2mR
```

### Response envelope

Successful JSON responses are wrapped in `data`; list responses and secondary information use `meta`. Errors use an `error` object with a stable `code` and human-readable `message`.

```json
{
  "data": [],
  "meta": {
    "limit": 20,
    "offset": 0,
    "total": 42
  }
}
```

### Relation expansion

Relation fields can be expanded with `?include=field1,field2` (one level deep):

```
GET /api/v1/workspaces/{workspace}/content/posts/my-post?include=author,categories
```

## Webhooks

Configure outbound webhook URLs in the admin under **Webhooks**. CometCMS sends a signed `POST` request whenever content changes - useful for triggering SSG builds.

Each webhook fires on the events you select: `content.published`, `content.updated`, `content.deleted`, and more. Payloads are signed with HMAC-SHA256 via the `X-CometCMS-Signature` header.

See the [webhook documentation](docs/guide/webhooks.md) for the full payload format and signature verification examples.

## Updates

The admin sidebar version and **Updates** navigation item open `/admin/update`. Update checks read GitHub release metadata from `updates.repository_url` in `config/config.php`, which defaults to `https://github.com/GetCometCMS/CometCMS`, and then try any configured `updates.fallback_repository_urls` if the primary release endpoint is unavailable. Installable releases should include a built ZIP asset matching `updates.release_asset_pattern` and a `.sha256` checksum asset matching `updates.checksum_asset_pattern`.

CometCMS supports public GitHub releases for update checks and downloads. The update flow downloads and verifies a release ZIP first, then installs the staged package in a separate step.

Installation replaces release-owned application files and folders while preserving the configured `updates.preserved_paths`; by default that keeps `storage/` for content, media, users, revisions, trash and sessions, plus `config/config.php` for local self-hosting settings. Whole release-owned folders such as `app/` and `admin/` are replaced, so old files removed from a release do not linger.

## Admin UI

| URL                           | Description            |
| ----------------------------- | ---------------------- |
| `/admin`                      | Dashboard              |
| `/admin/content/{collection}` | Content entries        |
| `/admin/media`                | Media library          |
| `/admin/users`                | Users                  |
| `/admin/connect/access-tokens` | Access tokens          |
| `/admin/backups`              | Backup and restore     |
| `/admin/connect/webhooks`             | Outbound webhooks      |
| `/admin/page-builder`         | Website design, templates, menus and fonts |
| `/admin/website/general`      | Homepage, languages and website settings |
| `/admin/website/workspaces`   | Workspace management |
| `/admin/update`               | GitHub release updates |

### Roles

| Role     | Permissions                                  |
| -------- | -------------------------------------------- |
| `admin`  | Built-in full-access role; cannot be deleted |
| `editor` | Built-in content and media editing role      |
| `viewer` | Built-in read-oriented role                  |

Roles can be customized or created in **Administration → Users & roles → Roles**.

### Screenshots

| Dashboard                                                                          | Content types                                                                                  |
| ---------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| <img src="docs/screenshots/view-dashboard.png" alt="Dashboard view" width="420" /> | <img src="docs/screenshots/view-content-types.png" alt="Content types overview" width="420" /> |

| Media library                                                                      | Role permissions                                                                                             |
| ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| <img src="docs/screenshots/view-media.png" alt="Media library view" width="420" /> | <img src="docs/screenshots/view-user-role-permissions.png" alt="User role permissions editor" width="420" /> |

## Storage layout

All data lives in `cms/storage/` (not web-accessible):

```
storage/
  workspaces/{workspace}/content/{collection}/{slug}.json
  workspaces/{workspace}/content-types/{name}.json
  workspaces/{workspace}/media/
  workspaces/{workspace}/media-meta/
  workspaces/{workspace}/revisions/content/
  workspaces/{workspace}/revisions/site/ - layout, header/footer and design history
  workspaces/{workspace}/trash/
  workspaces/{workspace}/site/       - website settings, theme, menus, header/footer, layouts
  users/{id}.json              - user accounts
  api-tokens/{id}.json               - access token records and hashes
  roles/{name}.json                  - customized role definitions
  backups/                           - saved backup ZIP files
  workspaces/{workspace}/cache/api/  - public API response cache
  updates/                           - downloaded update packages
  settings.json                      - runtime settings (webhooks etc.)
  logs/comet.log
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for local development setup, building, testing, code style, and pull request guidelines.

## License

Apache License 2.0 - see [LICENSE](LICENSE).
