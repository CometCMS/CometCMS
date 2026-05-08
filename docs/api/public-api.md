# Public API

The CometCMS public API is the stable HTTP API for external frontends, static site generators, mobile apps, and integration scripts.

Public reads work without authentication and return only public content. Send an API token when you need drafts, protected content, or write access:

```http
Authorization: Bearer YOUR_TOKEN_HERE
```

See [API Tokens](../guide/api-tokens) for how to create tokens and assign permission grants.

## Base URL

```text
https://yourdomain.com/api/v1
```

All Public API endpoints require a workspace segment in the URL:

```text
https://yourdomain.com/api/v1/workspaces/{workspace}
```

Requests to unscoped `/api/v1/...` content, content-type, and media routes are rejected with `workspace_required`.

For example, `GET /api/v1/workspaces/site-a/content/posts` reads posts from the `site-a` workspace. Direct media URLs for scoped responses use `/media/{workspace}/{filename}` and `/media-thumbs/{workspace}/{filename}`.

## Response shape

Successful JSON responses are always wrapped in `data`. List responses and secondary response metadata use `meta`.

```json
{
  "data": [],
  "meta": {
    "total": 0,
    "limit": 20,
    "offset": 0,
    "sort": "created_at",
    "order": "desc"
  }
}
```

Errors use a single `error` object:

```json
{
  "error": {
    "code": "not_found",
    "message": "Content entry not found."
  }
}
```

Unknown content types, collections, and entries return `404` with the same error shape.

## OpenAPI

The machine-readable public API contract is available as [`openapi.yaml`](/api/openapi.yaml).

## Health

### `GET /api/v1/workspaces/{workspace}/health`

Returns a health check response.

The response includes runtime extension capabilities that affect key features:

- `data.extensions.gd`: thumbnail-generation support (GD image functions available)
- `data.extensions.zip`: backup archive support (`ZipArchive`/zip extension available)

## Content types

### `GET /api/v1/workspaces/{workspace}/content-types`

Returns all content type schemas.

### `GET /api/v1/workspaces/{workspace}/content-types/{collection}`

Returns one content type schema.

### `POST /api/v1/workspaces/{workspace}/content-types`

Creates a new content type schema.

**Required permission:** `schema.create` on `schema:{name}`

Request body fields: `name` (required), `label`, `icon`, `singleton`, `fields`, `locales`, `default_locale`.

### `PUT /api/v1/workspaces/{workspace}/content-types/{collection}`

Updates an existing content type schema.

**Required permission:** `schema.update` on `schema:{collection}`

Existing entries are not modified — new fields will be absent until entries are re-saved.

Setting `singleton: true` makes the content type a single page. Single pages allow at most one active entry and use the content type name as their fixed slug.

### `DELETE /api/v1/workspaces/{workspace}/content-types/{collection}`

Permanently deletes a content type and all its entries. This action is irreversible.

**Required permission:** `schema.delete` on `schema:{collection}`

## Content entries

### `GET /api/v1/workspaces/{workspace}/content/{collection}`

Returns entries in a collection.

For single page content types, fetch the fixed entry with `GET /api/v1/workspaces/{workspace}/content/{collection}/{collection}` instead of using the list endpoint.

Without a token, only `published` entries and `scheduled` entries whose `published_at` is in the past are returned. With a token that has `content.read` on the collection, drafts and protected entries are included.

**Query parameters:**

| Parameter                 | Description                                                                 |
| ------------------------- | --------------------------------------------------------------------------- |
| `limit`                   | Maximum number of entries to return; omit it to return all matching entries |
| `offset`                  | Offset for pagination                                                       |
| `sort`                    | Sort field; prefix with `-` for descending, e.g. `?sort=-published_at`      |
| `q`                       | Full-text search across text fields                                         |
| `include`                 | Comma-separated relation fields to expand one level                         |
| `locale`                  | Locale code for localized content types, e.g. `?locale=de`                  |
| `filter[field]`           | Exact match, e.g. `?filter[is_promo_material]=true`                         |
| `filter[field][in]`       | One of several values                                                       |
| `filter[field][ne]`       | Not equal                                                                   |
| `filter[field][gt]`       | Greater than                                                                |
| `filter[field][gte]`      | Greater than or equal                                                       |
| `filter[field][lt]`       | Less than                                                                   |
| `filter[field][lte]`      | Less than or equal                                                          |
| `filter[field][contains]` | Case-insensitive substring match                                            |

Boolean fields accept `true`/`false` (also `1`/`0`). Select fields, including multi-select fields, match against the stored option value. When a stored field value is an array, filters match if any item in the array matches the requested value. Media fields are always returned as arrays of absolute media URLs, including single-file media fields. Multi-select and multi-relation fields are returned as arrays; single select and relation fields are returned as one value or `null`. The stable entry identifier is filterable as `id`; the URL slug is filterable as `slug`.

For localized content types, `locale` resolves translated `title` and field values before search, filters, sorting, and relation expansion. Without `locale`, entries use the content type's default-locale fallback copy. Unsupported locale codes are ignored.

Sorting is type-aware for numeric values and ISO-style dates, then falls back to case-insensitive string sorting.

```http
GET /api/v1/workspaces/site-a/content/blogpost?filter[is_promo_material]=true
GET /api/v1/workspaces/site-a/content/blogpost?filter[category]=launch
GET /api/v1/workspaces/site-a/content/blogpost?filter[id]=7K4p9xQ2mR
GET /api/v1/workspaces/site-a/content/pages?locale=de
```

### `GET /api/v1/workspaces/{workspace}/content/{collection}/{identifier}`

Returns one entry. `{identifier}` may be either the entry slug or the stable opaque `id` returned in the payload. Add `?locale={code}` to resolve localized field values.

Public reads return only public entries. Reading drafts, protected entries, or entries hidden by status requires `content.read` on `content:{collection}:{identifier}`.

### Entry payload

```json
{
  "id": "7K4p9xQ2mR",
  "slug": "how-to-cook-pasta",
  "type": "blogpost",
  "status": "published",
  "title": "How to cook pasta",
  "published_at": "2026-05-03T12:00:00Z",
  "created_at": "2026-05-01T09:30:00Z",
  "updated_at": "2026-05-03T12:00:00Z",
  "author_id": "admin",
  "updated_by": "admin",
  "data": {
    "is_promo_material": true,
    "category": "launch",
    "hero_image": ["https://yourdomain.com/media/hero.png"]
  }
}
```

`id` is stable and opaque. `slug` is the human-readable URL key and may change.

### `POST /api/v1/workspaces/{workspace}/content/{collection}`

Creates a new entry.

**Required permission:** `content.create` on `content:{collection}:*`

**Body:** JSON object with field values.

Creating an entry with `status: "published"` also requires `content.publish` on the entry.

For localized content types, include `locale` in the body to create that locale variant. The default locale is used when `locale` is omitted.

When a content type field defines a supported `default`, omitted values are created with that default before validation and normalization.

For single page content types, creation is allowed only while no active entry exists. The entry slug is forced to the content type name.

### `PUT /api/v1/workspaces/{workspace}/content/{collection}/{identifier}`

Updates an existing entry by slug or stable ID.

**Required permission:** `content.update` on `content:{collection}:{identifier}`

Updating an entry to `status: "published"` also requires `content.publish` on the entry.

For localized content types, include `locale` in the body to update that locale variant. Slug, status, author, and publish date remain shared by the entry.

### `DELETE /api/v1/workspaces/{workspace}/content/{collection}/{identifier}`

Soft-deletes an entry by slug or stable ID.

**Required permission:** `content.delete` on `content:{collection}:{identifier}`

```json
{
  "data": {
    "ok": true
  }
}
```

## Media

### `GET /api/v1/workspaces/{workspace}/media`

Returns uploaded media files. Without authentication, only **public** files are returned. With a token that has `media.read`, all files (including private ones) are returned.

**Query parameters:**

| Parameter  | Description                                                             |
| ---------- | ----------------------------------------------------------------------- |
| `q`        | Search by filename                                                      |
| `category` | Filter by media category                                                |
| `limit`    | Maximum number of files to return; omit it to return all matching files |
| `offset`   | Offset for pagination                                                   |

Available categories are returned in `meta.categories`. Nested categories are represented as paths such as `Brand / Logos`; filtering by a parent category also includes files assigned to its subcategories.

Each file object includes the following fields:

| Field         | Description                                          |
| ------------- | ---------------------------------------------------- |
| `filename`    | File name                                            |
| `url`         | Absolute URL to the file                             |
| `size`        | File size in bytes                                   |
| `mime`        | MIME type                                            |
| `category`    | Assigned category path, or empty string              |
| `width`       | Image width in pixels, or `null`                     |
| `height`      | Image height in pixels, or `null`                    |
| `alt`         | Alt text for the file (empty string if unset)        |
| `title`       | Title / tooltip for the file (empty string if unset) |
| `visibility`  | `"public"` or `"private"`                            |
| `uploaded_at` | ISO 8601 upload timestamp, or `null`                 |
| `uploaded_by` | User ID of the uploader, or `null`                   |

### `POST /api/v1/workspaces/{workspace}/media`

Uploads one or more media files as multipart `media[]` parts.

**Required permission:** `media.upload` on `media:*`, or `media:category:{category}` when assigning a category

Optional form field: `category`. Use a nested path such as `Brand / Logos` to assign a subcategory.

### `PUT /api/v1/workspaces/{workspace}/media/{filename}/meta`

Updates the `alt` text and `title` of a media file. Send empty strings to clear them.

**Required permission:** `media.update` on `media:*`

**Body:** `{ "alt": "A red apple on a white background", "title": "Product photo" }`

### `PUT /api/v1/workspaces/{workspace}/media/{filename}/visibility`

Sets the visibility of a media file to `"public"` (default) or `"private"`.

Private files are excluded from unauthenticated `GET /api/v1/workspaces/{workspace}/media` responses and return `401` when fetched directly via `GET /media/{workspace}/{filename}` without a valid token with `media.read` permission.

**Required permission:** `media.update` on `media:*`

**Body:** `{ "visibility": "private" }`

### `PUT /api/v1/workspaces/{workspace}/media/bulk-visibility`

Sets the visibility of multiple media files in one request.

**Required permission:** `media.update` on `media:*`

**Body:** `{ "files": ["photo.jpg", "doc.pdf"], "visibility": "private" }`

### `POST /api/v1/workspaces/{workspace}/media/categories`

Creates a media category. Send a path in `name` or provide `parent` to create a subcategory.

**Required permission:** `media.update` on `media:*`

**Body:** `{ "name": "Logos", "parent": "Brand" }`

```json
{
  "data": {
    "name": "Brand / Logos"
  },
  "meta": {
    "categories": ["Brand", "Brand / Logos"]
  }
}
```

### `PUT /api/v1/workspaces/{workspace}/media/categories/{category}`

Renames a media category and updates all files assigned to it or its subcategories.

**Required permission:** `media.update` on `media:category:{category}`

**Body:** `{ "name": "New category name" }`

### `DELETE /api/v1/workspaces/{workspace}/media/categories/{category}`

Deletes a media category and its subcategories. Files are not deleted; they are moved to no category.

**Required permission:** `media.update` on `media:category:{category}`

### `PUT /api/v1/workspaces/{workspace}/media/{filename}/category`

Assigns a media file to a category. Send an empty category to clear it.

**Required permission:** `media.update` on `media:{filename}`

**Body:** `{ "category": "Brand / Logos" }`

### `DELETE /api/v1/workspaces/{workspace}/media/{filename}`

Deletes a media file.

**Required permission:** `media.delete` on `media:{filename}`

### `GET /media/{workspace}/{filename}`

Serves a media file directly. Returns `401` if the file's visibility is `"private"` and no valid bearer token with `media.read` on `media:{filename}` is provided.

## Admin-Only Operations

Trash, backup/restore, settings, users, tokens, and webhook configuration are intentionally not part of the public token API. Use the session-authenticated `/admin/api` endpoints from the admin UI for operational tasks.
