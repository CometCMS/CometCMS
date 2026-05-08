# Workspaces

Workspaces let you partition content, media, and content types within a single CometCMS installation. Each workspace is completely isolated — its entries, schemas, and uploaded files are stored separately.

Common use cases:

- Manage multiple websites or apps from one admin panel.
- Separate staging and production content without running two CMS instances.
- Give different teams their own isolated content area with scoped permission grants.

## What workspaces isolate vs share

Workspace-scoped data:

- Content entries
- Content type schemas
- Media files and media metadata
- Revisions
- Trash
- Public API cache

Shared installation-wide data:

- Users
- Roles
- API tokens
- Webhook settings
- Backup files
- Update settings and runtime app settings

This means you can keep one team directory and auth model while still separating website data by workspace.

## How workspaces map to API routes

Every workspace has a **slug** (a short URL-safe identifier). The slug determines which API path prefix is used to access that workspace's content.

### Public API routes

Public API routes are always workspace-scoped through the URL prefix:

```http
GET /api/v1/workspaces/site-a/content/posts
GET /api/v1/workspaces/site-a/content-types/posts
GET /api/v1/workspaces/site-a/media
```

Unscoped routes under `/api/v1/...` return `workspace_required`.

### Admin API workspace selection

Admin API routes select a workspace through the `X-Comet-Workspace` header. If omitted, CometCMS uses the configured default workspace.

```http
X-Comet-Workspace: site-b
```

| Route pattern                                        | Description                                     |
| ---------------------------------------------------- | ----------------------------------------------- |
| `GET /api/v1/workspaces/{slug}/content/{type}`       | List entries from a specific workspace          |
| `GET /api/v1/workspaces/{slug}/content-types/{type}` | Content type schema for a specific workspace    |
| `GET /api/v1/workspaces/{slug}/media`                | Media list for a specific workspace             |
| `GET /media/{slug}/{filename}`                       | Serve media file from a specific workspace      |
| `GET /media-thumbs/{slug}/{filename}`                | Serve a generated thumbnail from that workspace |

### Workspace header

For admin endpoints (`/admin/api/...`), clients can select a workspace per request with `X-Comet-Workspace`:

```http
X-Comet-Workspace: site-b
```

For public endpoints, use `/api/v1/workspaces/{slug}/...`.

## Default workspace

The built-in workspace is always named `default` and cannot be deleted. You can rename it and upload a custom icon.

To change which workspace is served at the root API path:

1. Open **Settings → Workspaces**.
2. Click **Set as default** next to the workspace you want to promote.

The previously default workspace is not automatically reassigned — it remains accessible via its `/workspaces/{slug}` prefix.

> **Note:** The built-in `default` workspace and the _configured_ default workspace are two separate things. The configured default can be any workspace; it just means that workspace's content is served at the root API path.

## Creating a workspace

1. Go to **Settings → Workspaces**.
2. Click **New workspace**.
3. Enter a label and (optionally) a custom slug. The slug is derived from the label automatically.
4. Click **Create**.

Slugs are immutable after creation. Choose them carefully.

## Archiving a workspace

Archived workspaces are hidden from the workspace switcher and from the API. Their data is preserved on disk.

- The **built-in default** workspace (`default` slug) cannot be archived.
- The **configured default** workspace cannot be archived until you assign a new default.

To archive: click **Archive** next to the workspace.

## Deleting a workspace

Deleting a workspace permanently removes its record. The underlying content files remain in `cms/storage/` but are no longer accessible through the API.

- The built-in `default` workspace cannot be deleted.
- The configured default workspace cannot be deleted until you set a different default.

To delete: click **Delete** next to the workspace, then type the workspace slug to confirm.

## Workspace icons

Upload an icon (JPEG, PNG, WebP, or GIF — max 10 MB) to help visually distinguish workspaces in the switcher and admin list.

Click the workspace avatar to upload a new icon. To remove an existing icon, click **Remove icon** in the action row.

## Permissions and workspace scoping

Permission grants can be scoped to a specific workspace. For example:

| Grant                                 | Effect                                                 |
| ------------------------------------- | ------------------------------------------------------ |
| `workspace:*:content:*`               | Read/write content in all workspaces                   |
| `workspace:site-b:content:*`          | Read/write content only in the `site-b` workspace      |
| `workspace:site-b:content:posts:read` | Read-only access to the `posts` collection in `site-b` |

When creating or editing an API token or user role, choose a workspace from the **Scope** dropdown in the permission grants editor to restrict a grant to a single workspace.

## Storage layout

Each workspace stores its data in workspace-scoped sub-directories:

```
cms/storage/
  content/{workspace}/          # Content entries
  content-types/{workspace}/    # Content type schemas
  media/{workspace}/            # Uploaded media files
  media-meta/{workspace}/       # Media metadata
  media-thumbs/{workspace}/     # Generated thumbnails
  revisions/{workspace}/        # Revision history
  trash/{workspace}/            # Soft-deleted entries
  workspaces/icons/             # Workspace icons
```

The default workspace uses `default` as its directory name regardless of the configured default slug.
