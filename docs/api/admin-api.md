# Admin API

The admin API powers the Vue SPA admin interface. It uses **PHP session authentication** (cookie-based). All endpoints require an active admin session unless otherwise noted.

> These endpoints are intended for the admin frontend. For headless content access, use the [Public API](./public-api) with an API token instead.

## Base URL

```
https://yourdomain.com/admin/api
```

---

## Auth

| Method | Path                | Description                                                              |
| ------ | ------------------- | ------------------------------------------------------------------------ |
| `POST` | `/admin/api/setup`  | First-run setup — create initial admin (only works when no users exist). |
| `GET`  | `/admin/api/me`     | Returns the currently authenticated user.                                |
| `POST` | `/admin/api/login`  | Log in with `username` + `password`.                                     |
| `POST` | `/admin/api/logout` | Log out and destroy session.                                             |

---

## Dashboard

| Method | Path                         | Description                                                              |
| ------ | ---------------------------- | ------------------------------------------------------------------------ |
| `GET`  | `/admin/api/dashboard`       | Summary stats (entry counts, recent activity).                           |
| `GET`  | `/admin/api/app`             | App version and config info.                                             |
| `GET`  | `/admin/api/activity`        | Paginated activity log. Supports `level`, `type`, `limit`, and `offset`. |
| `GET`  | `/admin/api/update`          | Current update status.                                                   |
| `POST` | `/admin/api/update/check`    | Check for updates. Requires `updates.check`.                             |
| `POST` | `/admin/api/update/download` | Download the latest update into staging. Requires `updates.download`.    |
| `POST` | `/admin/api/update/install`  | Install a staged update. Requires `updates.install`.                     |

---

## Workspaces

Workspace-scoped admin requests send `X-Comet-Workspace: {workspace}`. Content types, content, media, revisions, trash, and public API cache are isolated by that workspace.

| Method   | Path                                | Description                                                               |
| -------- | ----------------------------------- | ------------------------------------------------------------------------- |
| `GET`    | `/admin/api/workspaces`             | List active workspaces.                                                   |
| `POST`   | `/admin/api/workspaces`             | Create a workspace. Requires `workspaces.manage`.                         |
| `PUT`    | `/admin/api/workspaces/{workspace}` | Update the workspace label/archive state. Requires `workspaces.manage`.   |
| `DELETE` | `/admin/api/workspaces/{workspace}` | Archive a workspace without deleting files. Requires `workspaces.manage`. |

---

## Content types

| Method   | Path                              | Description                                  |
| -------- | --------------------------------- | -------------------------------------------- |
| `GET`    | `/admin/api/content-types`        | List all content types.                      |
| `POST`   | `/admin/api/content-types`        | Create a new content type.                   |
| `PATCH`  | `/admin/api/content-types/order`  | Reorder content types.                       |
| `GET`    | `/admin/api/content-types/{name}` | Get a single content type schema.            |
| `PUT`    | `/admin/api/content-types/{name}` | Update a content type schema.                |
| `DELETE` | `/admin/api/content-types/{name}` | Delete a content type (and all its entries). |

List and reorder responses include available field type names in `meta.field_types`.

Content type schemas include `singleton`. When `singleton` is `true`, the type represents one fixed entry instead of a repeatable collection. Reordering applies to both collections and single page types; the admin sidebar keeps their relative order within the **Collections** and **Single** sections.

---

## Content entries

| Method   | Path                                                         | Description                                                         |
| -------- | ------------------------------------------------------------ | ------------------------------------------------------------------- |
| `GET`    | `/admin/api/content/{collection}`                            | List entries, including drafts. Omit `limit` to return all matches. |
| `POST`   | `/admin/api/content/{collection}`                            | Create a new entry.                                                 |
| `GET`    | `/admin/api/content/{collection}/{id}`                       | Get a single entry.                                                 |
| `PUT`    | `/admin/api/content/{collection}/{id}`                       | Update an entry.                                                    |
| `DELETE` | `/admin/api/content/{collection}/{id}`                       | Soft-delete (move to trash).                                        |
| `PATCH`  | `/admin/api/content/{collection}/bulk`                       | Bulk update selected entries.                                       |
| `DELETE` | `/admin/api/content/{collection}/bulk`                       | Bulk soft-delete selected entries.                                  |
| `DELETE` | `/admin/api/content/{collection}/{id}/translations/{locale}` | Permanently delete one non-default locale variant.                  |

List endpoints support `q`, `limit`, `offset`, `sort`, `order`, `locale`, and canonical
`filter[...]` field filters:

```http
GET /admin/api/content/blogpost?filter[is_promo_material]=true
GET /admin/api/content/blogpost?filter[category]=launch
```

Use `filter[field][in]`, `filter[field][ne]`, `filter[field][gt]`,
`filter[field][gte]`, `filter[field][lt]`, `filter[field][lte]`, or
`filter[field][contains]` for operator filters. Sorting is type-aware for
numeric values and ISO-style dates, then falls back to case-insensitive string
sorting.

When a stored field value is an array, filters match if any item in the array
matches the requested value. Media fields are always arrays of filenames,
including single-file media fields. Multi-select and multi-relation fields are
arrays; single select and relation fields are one value or `null`.

For localized content types, send `locale` when listing entries to resolve translated values before filtering and sorting. Create and update requests can include `locale` in the JSON body to edit a specific locale variant. The default locale cannot be deleted with the translation delete endpoint.

When a content type field defines a supported `default`, omitted values are created with that default before validation and normalization.

Single page content types use the content type name as the fixed entry id/slug. For example, the admin editor and API use `/admin/api/content/start-page/start-page` for the `start-page` single page. Creating a second active entry is rejected.

### Revisions

| Method | Path                                                             | Description                      |
| ------ | ---------------------------------------------------------------- | -------------------------------- |
| `GET`  | `/admin/api/content/{collection}/{id}/revisions`                 | List all revisions for an entry. |
| `POST` | `/admin/api/content/{collection}/{id}/revisions/{revId}/restore` | Restore a specific revision.     |

---

## Trash

| Method   | Path                                         | Description                            |
| -------- | -------------------------------------------- | -------------------------------------- |
| `GET`    | `/admin/api/trash/{collection}`              | List trashed entries for a collection. |
| `POST`   | `/admin/api/trash/{collection}/{id}/restore` | Restore a trashed entry.               |
| `DELETE` | `/admin/api/trash/{collection}/{id}`         | Permanently delete a trashed entry.    |
| `DELETE` | `/admin/api/trash/{collection}`              | Empty the trash for a collection.      |

---

## Media

| Method   | Path                                     | Description                                                                                           |
| -------- | ---------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `GET`    | `/admin/api/media`                       | List media files. Supports `q`, `category`, `limit` and `offset`; omit `limit` to return all matches. |
| `POST`   | `/admin/api/media`                       | Upload one or more files (multipart `media[]`). Returns `data` as an array of uploaded media items.   |
| `POST`   | `/admin/api/media/categories`            | Create a media category.                                                                              |
| `PUT`    | `/admin/api/media/categories/{category}` | Rename a media category and update assigned files.                                                    |
| `DELETE` | `/admin/api/media/categories/{category}` | Delete a media category; assigned files move to no category.                                          |
| `PUT`    | `/admin/api/media/{filename}/category`   | Assign a file to a category.                                                                          |
| `PUT`    | `/admin/api/media/{filename}/rename`     | Rename a media file and update content media-field references.                                        |
| `PUT`    | `/admin/api/media/bulk-category`         | Assign selected files to a category.                                                                  |
| `DELETE` | `/admin/api/media/{filename}`            | Delete a media file.                                                                                  |
| `POST`   | `/admin/api/media/bulk-delete`           | Delete selected media files.                                                                          |

Media category lists are returned as `meta.categories`. Nested categories are represented as paths such as `Brand / Logos`; filtering by a parent category also includes files assigned to its subcategories. To create a subcategory, send `parent` alongside `name` to `/admin/api/media/categories`.

### Media upload

Send media uploads as `multipart/form-data` using one or more `media[]` parts.

Example response:

```json
{
  "data": [
    {
      "name": "20260503203010-a1b2c3d4-example.png",
      "filename": "20260503203010-a1b2c3d4-example.png",
      "size": 123456,
      "mime": "image/png",
      "category": "",
      "uploaded_by": "admin123",
      "uploaded_at": "2026-05-03T20:30:10Z",
      "url": "https://yourdomain.com/media/20260503203010-a1b2c3d4-example.png"
    }
  ],
  "meta": {
    "categories": ["Brand", "Brand / Logos", "Screenshots"]
  }
}
```

---

## Users

User management uses permission grants such as `users.read`, `users.create`, `users.update`, and `users.delete`.

| Method   | Path                           | Description                                          |
| -------- | ------------------------------ | ---------------------------------------------------- |
| `GET`    | `/admin/api/users`             | List all users.                                      |
| `POST`   | `/admin/api/users`             | Create a new user.                                   |
| `GET`    | `/admin/api/users/{id}`        | Get one user by ID.                                  |
| `PUT`    | `/admin/api/users/{id}`        | Update a user (display name, email, role, password). |
| `DELETE` | `/admin/api/users/{id}`        | Delete a user.                                       |
| `GET`    | `/admin/api/users/{id}/avatar` | Serve a user's avatar image.                         |

### Roles

Role management uses `roles.read`, `roles.create`, `roles.update`, and `roles.delete`. The built-in `admin` role cannot be deleted.

| Method   | Path                    | Description                      |
| -------- | ----------------------- | -------------------------------- |
| `GET`    | `/admin/api/roles`      | List roles and their grants.     |
| `POST`   | `/admin/api/roles`      | Create a role.                   |
| `PUT`    | `/admin/api/roles/{id}` | Update a role label or grants.   |
| `DELETE` | `/admin/api/roles/{id}` | Delete an unused non-admin role. |

### API Tokens

| Method   | Path                          | Description                      |
| -------- | ----------------------------- | -------------------------------- |
| `GET`    | `/admin/api/tokens`           | List application API tokens.     |
| `POST`   | `/admin/api/tokens`           | Create an application API token. |
| `DELETE` | `/admin/api/tokens/{tokenId}` | Revoke a token.                  |

---

## Profile (self)

| Method   | Path                        | Description                                                                           |
| -------- | --------------------------- | ------------------------------------------------------------------------------------- |
| `PUT`    | `/admin/api/profile`        | Update own display name, email, or password. Password changes require `old_password`. |
| `POST`   | `/admin/api/profile/avatar` | Upload own avatar (multipart, `avatar` field).                                        |
| `DELETE` | `/admin/api/profile/avatar` | Remove own avatar.                                                                    |

---

## Backup / Restore

| Method   | Path                                     | Description                                                        |
| -------- | ---------------------------------------- | ------------------------------------------------------------------ |
| `GET`    | `/admin/api/backups`                     | List saved backups and available backup parts.                     |
| `POST`   | `/admin/api/backups`                     | Create a saved backup. JSON body: optional `parts` array.          |
| `POST`   | `/admin/api/backups/upload`              | Upload a backup ZIP to storage and inspect it. Multipart `backup`. |
| `GET`    | `/admin/api/backups/{name}.zip/inspect`  | Inspect backup contents before restoring.                          |
| `POST`   | `/admin/api/backups/{name}.zip/restore`  | Restore selected parts. JSON body: `parts`, optional `overwrite`.  |
| `GET`    | `/admin/api/backups/{name}.zip/download` | Download a saved backup ZIP.                                       |
| `DELETE` | `/admin/api/backups/{name}.zip`          | Delete a saved backup ZIP.                                         |

---

## Webhooks

| Method | Path                  | Description                  |
| ------ | --------------------- | ---------------------------- |
| `GET`  | `/admin/api/webhooks` | List configured webhooks.    |
| `PUT`  | `/admin/api/webhooks` | Replace configured webhooks. |
