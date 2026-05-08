# API Tokens

API tokens let external applications (static site generators, deployment scripts, mobile apps, etc.) access the [Public API](../api/public-api) without a user session.

## Creating a token

1. Go to **API-Tokens**.
2. Click **New token**.
3. Give the token a name (e.g. `Deploy script`), optional description, and permission grants.
4. Click **Create token**.
5. **Copy the token immediately** — it is only shown once.

## Permissions

Tokens use the same permission grant format as roles. A token starts with no implicit role access; it can only do what its grants allow.

```json
[
  {
    "effect": "allow",
    "actions": ["content.read", "content.update"],
    "resources": ["content:pages:homepage"],
    "fields": ["hero_title", "hero_image"]
  }
]
```

Each grant has:

| Property     | Description                                                                     |
| ------------ | ------------------------------------------------------------------------------- |
| `effect`     | `allow` or `deny`. A matching `deny` overrides matching allows.                 |
| `actions`    | One or more action names. Use `*` only for full administrative access.          |
| `resources`  | One or more resource patterns. `*` wildcards are supported.                     |
| `fields`     | Optional content field allow-list for create/update operations.                 |
| `conditions` | Optional restrictions. Supported conditions are `own`, `status`, and `locales`. |

Common content actions are `content.read`, `content.create`, `content.update`, `content.publish`, `content.delete`, `content.restore`, `content.revisions.read`, and `content.revisions.restore`.

Schema actions are `schema.read`, `schema.create`, `schema.update`, and `schema.delete`.

Media actions are `media.read`, `media.upload`, `media.update`, and `media.delete`.

Admin UI actions include `dashboard.read`, `activity.read`, `profile.read`, `profile.update`, `users.read`, `users.create`, `users.update`, `users.delete`, `tokens.read`, `tokens.create`, `tokens.revoke`, `roles.read`, `roles.create`, `roles.update`, `roles.delete`, `backups.read`, `backups.create`, `backups.restore`, `backups.delete`, `webhooks.manage`, `updates.read`, `updates.check`, `updates.download`, and `updates.install`.

Common resources include `content:*`, `content:posts:*`, `content:pages:homepage`, `schema:*`, `schema:posts`, `media:*`, and `media:category:brand-assets`. Workspace-specific grants prefix those same resources with `workspace:{workspace}:`, for example `workspace:site-a:content:posts:*`.

Resource formats:

| Area      | Format                                 | Examples                                                       |
| --------- | -------------------------------------- | -------------------------------------------------------------- |
| Content   | `content:{collection}:{entry}`         | `content:posts:*`, `content:pages:homepage`                    |
| Schema    | `schema:{content-type}`                | `schema:*`, `schema:posts`                                     |
| Media     | `media:*`, `media:{file}`, or category | `media:*`, `media:hero.jpg`, `media:category:Brand / Logos`    |
| Workspace | `workspace:{workspace}:{resource}`     | `workspace:site-a:content:posts:*`, `workspace:site-a:media:*` |
| Users     | `users:{id}`, `tokens:{id}`, or roles  | `users:*`, `tokens:*`, `roles:*`                               |
| System    | Named system resource                  | `dashboard:*`, `activity:*`, `backups:*`                       |

Condition examples:

```json
[
  {
    "effect": "allow",
    "actions": ["content.update"],
    "resources": ["content:posts:*"],
    "fields": ["title", "summary", "body"],
    "conditions": {
      "own": true,
      "status": ["draft", "protected"],
      "locales": ["en", "de"]
    }
  }
]
```

Trash, backup/restore, settings, users, and token management are not exposed through the public token API.

## Using a token

Pass the token as a **Bearer token** in the `Authorization` header:

```http
GET /api/v1/workspaces/site-a/content/posts
Authorization: Bearer YOUR_TOKEN_HERE
```

## Revoking a token

Click **Revoke** next to the token in the API-Tokens page. Revoked tokens cannot be used and cannot be un-revoked.
