# MCP API

The Model Context Protocol (MCP) endpoint lets AI coding assistants (GitHub Copilot, Claude Code, Cursor, etc.) interact with CometCMS directly from the editor. It exposes content types, entries, media, and permission-scoped page layouts as **tools** that the AI can discover and call on demand.

> MCP is a server-to-server protocol designed for AI tool calling. For traditional REST consumption from frontends or scripts, use the [Public API](./public-api) or [Admin API](./admin-api) instead.

---

## Base URL

```
https://yourdomain.com/mcp/v1/workspaces/{workspace}
```

The workspace slug is always part of the URL — there is no unscoped MCP endpoint.

---

## Protocol

CometCMS implements the [MCP specification](https://spec.modelcontextprotocol.io) (protocol version `2025-06-18`) over **JSON-RPC 2.0** with **JSON over HTTP POST**.

| Aspect            | Detail                                               |
| ----------------- | ---------------------------------------------------- |
| Transport         | HTTP POST, `Content-Type: application/json`          |
| Protocol          | JSON-RPC 2.0 + MCP tool primitives                   |
| Authentication    | Optional for public reads; Bearer token for writes and private reads (see [Access Tokens](../guide/api-tokens)) |
| Auth header       | `Authorization: Bearer YOUR_TOKEN_HERE`              |
| Non-POST requests | Return `405 Method Not Allowed`                      |

### Lifecycle

1. **Initialize** — the client calls `initialize` to negotiate the protocol version and discover server capabilities.
2. **Tool discovery** — the client calls `tools/list` to fetch available tool schemas.
3. **Tool calls** — the client invokes individual tools via `tools/call`.
4. **Notifications** — `notifications/initialized` without an `id` returns HTTP 202 with no JSON-RPC response. A `ping` request with an `id` returns an empty result.

Notifications and requests without an `id` field are treated as fire-and-forget and return `202 Accepted`.

---

## Authentication

Without a Bearer token, MCP operates in read-only mode. Initialization and tool discovery are available, and `tools/list` advertises only `comet_health`, `list_content_types`, `get_content_type`, `list_entries`, `get_entry`, `list_media`, and `get_media_item`. Only public schemas and published entries from collections with API exposure enabled (`api_enabled: true`), plus public media, can be read. Entry publication dates must have arrived. Private schemas and media are omitted from listings, and relations to private collections or unpublished entries are omitted. Pagination totals and media category counts reflect public results.

Write tools and direct reads of private resources require a valid Bearer token with appropriate permission grants. Calling a write tool without authentication returns a tool error with status `401`, even though it is omitted from discovery. Invalid supplied tokens are rejected with `401`; they do not fall back to anonymous access. Authenticated MCP is a management interface: a token with the required grants can access collections with API exposure disabled. Every expanded related entry requires its own `content.read` grant for the target collection, entry, workspace, and locale; a source entry grant does not authorize its targets. REST continues to require API exposure for authenticated and anonymous reads. Tokens are created in **Access Tokens** in the admin:

```http
Authorization: Bearer ctcms_…
```

| HTTP status | Meaning                                                     |
| ----------- | ----------------------------------------------------------- |
| `401`       | Invalid token, or token required for a write or private read. |
| `403`       | Token is valid but lacks permission for the requested tool. |
| `404`       | Workspace or resource not found.                            |
| `422`       | Validation error — check parameter values and types.        |
| `500`       | Internal server error.                                      |

Error responses follow the JSON-RPC 2.0 error shape with additional context in `data`:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "error": {
    "code": -32000,
    "message": "Forbidden.",
    "data": {
      "status": 403,
      "details": { "code": "forbidden" },
      "required_permissions": ["content.read:my-workspace:content:pages:*"],
      "recovery": [
        "The token is valid but lacks permission. Update the token in Access Tokens or use a token that already has the required grant."
      ]
    }
  }
}
```

---

## Server Info

The `initialize` response advertises the server identity:

```json
{
  "protocolVersion": "2025-06-18",
  "capabilities": {
    "tools": { "listChanged": false }
  },
  "serverInfo": {
    "name": "cometcms",
    "title": "CometCMS",
    "version": "1.1.0",
    "description": "CMS content, schema, media, and permission-scoped page-builder tools."
  }
}
```

---

## Page builder

Builder tools use the **same workspace endpoint**. They are advertised only to
bearer tokens with `site.manage` on `workspace:{workspace}:site:*`; direct calls
also enforce this permission. Headless tokens do not receive builder schemas.
There is no second MCP connection to configure.

These tools edit **content areas** of a collection's templates, by
`collection` and `variant` (`detail` or `index`), and one entry's **own
content**. A template puts pages together from a header, its own content area
and a footer; the first template whose conditions match an entry is used and
the last one (`default`) applies to everything else.

- **Template scope** (optional `template`, default `default`): the template's
  content area. Changes affect every entry that uses the template.
- **Entry scope** (`entry` = id or slug, optional `area`; detail only, without
  `template`): the content one entry shows in an Entry content area
  (`page_content` node) of the template it uses. `area` is the page field key
  and defaults to the first area that template shows. An entry without content
  of its own reads as the area's default content (with stable IDs) and the
  first write gives it its own copy. Writing `sections: []` resets it to the
  default. Results name `entry`, `area`, `template` and `customized`. Reads need
  `content.read` and writes `content.update` on the entry, besides
  `site.manage`. If the entry's template has no Entry content area, the call
  fails with `409 no_entry_content_area`.

Saves take effect on published pages immediately; there is no separate design
draft. `get_layout` on a template lists `page_content` sections with their
`area`, so an agent knows where to switch to the entry scope. The entry tools
(`create_entry`, `update_entry`) refuse page fields (`422 page_field`) and
`get_entry` shows them only as a summary. Editing the header/footer
themselves, reusable component definitions, theme and menus are outside this
tool group; choosing which header and footer a template shows is not.

| Tool | Required inputs | Result |
| --- | --- | --- |
| `get_builder_schema` | none; optional `kind` (one or a list) | Without kind: kinds and their children, every style key's values (keys with equal values listed together), icon names and limits. With kind: props with defaults, the names of the style keys it takes, responsive/state keys and bindings. |
| `get_layout` | target; optional `section_ids`, `depth`, `mode` | Ordered section summaries by default; opt into scoped full or summary trees. Always includes the whole layout revision. |
| `get_section` | target + `section_id`; optional `depth`, `mode` | One subtree with default props omitted, or a bounded/summary tree, and whole layout revision. |
| `get_element` | target + `element_id` | One node's props/style, immediate child summaries, section ID and revision. |
| `set_layout` | target + `revision`, `sections` | Replace all sections; returns compact overview and new revision. |
| `update_section` | target + `revision`, `section_id`, `patch` | Patch `name`, `props`, `style`, or replace `children`; returns the section summary and revision. |
| `update_element` | target + `revision`, `element_id`, at least one of `props`, `style`, `responsive`, `states` | Merge property/style keys; returns node and revision. |
| `list_templates` | `collection`, `variant` | Templates in order and the components that can be a header or footer. |
| `set_template_parts` | `collection`, `variant`; optional `template` (default `default`), `header`, `footer` | `default`, `none` or a section component id. Returns the templates. |

Here `target` means `collection`, `variant` and optionally `template`, or
`entry` and `area`. A section is any top-level node,
including a `page_content` placeholder or a section component reference.
A component instance's children are what it shows in the component's content
areas: one `area_content` node per area, `props.area` naming the area and its
children holding elements and rows like a column. `get_element` on an instance
lists the areas as `content_areas` (`id`, `name`, `content_id`). Areas left
out of a write are added empty; an unknown or repeated area fails with `422`
and the list of the component's areas. `page_content` (Entry content areas in
templates) and component content areas are both content areas, filled by
whoever uses the design: each entry or each placement.
Patches preserve omitted keys, descendants and other sections. Supplying
`children: []` removes children. Object-valued props (including localized maps)
replace the whole property: read them first to preserve other values/locales.
Reset a prop/style by supplying its schema default. Nodes use
`{id?, kind, name?, props?, style?, children?}`; IDs are generated when omitted.
Use `get_builder_schema(kind)` for valid properties and child placement.
The style key `visible_if` shows a node only when conditions on entry fields
hold: `{"match": "all"|"any", "conditions": [{"field": {"type": "reference",
"source": "current", "path": ["featured"]}, "op": "equals", "value": true}]}`.
`op` is one of `equals`, `not_equals`, `contains`, `empty` or `not_empty`, and
`empty`/`not_empty` take no value. References take no filters. A field that
cannot be read counts as empty. Nodes whose conditions fail are left out of the
page. `[]` removes the conditions.

For smaller reads:

- `section_ids: ["hero", "footer"]` on `get_layout` selects top-level sections
  in layout order. Unknown IDs return a guiding `404`; an empty or duplicate
  selector is invalid. With no `mode` or `depth`, the result stays an overview.
- `depth` on `get_layout` or `get_section` limits the number of child levels
  below each section root. `0` includes roots only, `1` includes their immediate
  children. Omit it for all descendants. Nodes whose children were omitted
  include `child_count` and `children_truncated: true`; leaf nodes do not.
- `mode: "summary"` returns IDs, kinds, optional names and stored style values,
  including responsive/state styles, without props or content. It can be
  combined with `depth` and `section_ids`. `mode: "full"` includes nondefault
  props and all override values. On `get_layout`, supplying either `mode` or
  `depth` opts into a tree; `get_section` defaults to a full subtree.

These controls work for template and entry content. Every read retains the
**whole layout revision**, including sections outside a selector or depth limit.
Partial and summary trees are inspection results; read full content before
using it in a `children` or `set_layout` replacement. For a single known node,
`get_element` already returns only that node and immediate child summaries.

```json
{"name":"get_layout","arguments":{"collection":"pages","variant":"detail","section_ids":["hero"],"mode":"summary","depth":2}}
```

All writes **require** a revision from a read. Stale edits return `409` with
instructions to reread, reconcile, and retry; the revision check and save share
the repository file lock. Invalid edits return `422` with the failing path,
what is allowed there and how to fix it (for a misplaced node, the kinds its
parent holds and the wrappers to add, e.g. "Wrap it in row > column"; for a
value, the allowed values), without saving a repaired/truncated layout. The revision
covers the edited nodes only; for an entry, saving its other fields does not
change it. Builder success responses provide `structuredContent` plus compact
JSON text.

Builder argument validation follows the same guiding `422` contract before
layout reads or mutations: missing inputs, wrong types, unknown keys and invalid
schema constraints identify `error.details.tool`, `path`, `expected` (the local
schema), and `received_type`. When available, `example` gives a valid shape.
Messages describe allowed keys and types; recovery points to `tools/list` and
`get_builder_schema`. For example, a string in `update_section.patch` is rejected
with the expected object members (`name`, `props`, `style`, `responsive`, `states`,
`children`) and `{"style":{"padding_top":"lg"}}` as an example. A malformed
`arguments` envelope also returns a guiding `422` for every tool. These are MCP
tool results (`isError: true`, HTTP 200), like other tool execution errors.

MCP hosts may reject arguments against the advertised `inputSchema` before
sending a request. That host controls its own error formatting; CometCMS supplies
schema descriptions and patch examples for clients to show, but cannot attach a
server error to a call the host never sends.

Example workflow (substitute the revisions returned by each call):

```json
{"name":"get_layout","arguments":{"collection":"pages","variant":"detail"}}
```

```json
{"name":"set_layout","arguments":{"collection":"pages","variant":"detail","revision":"<read revision>","sections":[{"id":"hero","kind":"section","children":[{"kind":"row","children":[{"kind":"column","children":[{"id":"title","kind":"heading","props":{"text":"Welcome","level":"h1"}}]}]}]}]}}
```

```json
{"name":"update_element","arguments":{"collection":"pages","variant":"detail","revision":"<set_layout revision>","element_id":"title","props":{"text":"Hello world"}}}
```

See the [design decision and full contract](../design/mcp-page-builder.md) for
endpoint rationale, update semantics and concurrency guarantees. Saves use
existing cache invalidation and restorable layout history, attributed to the
token principal. Preview and MCP history tools remain future work.

---

## Available Tools

### `comet_health`

Check that the CometCMS instance is reachable and report runtime capabilities.

| Parameter | Type | Required |
| --------- | ---- | -------- |
| _(none)_  |      |          |

**Required permission:** none (authenticated only).

**Response fields:** `ok`, `name`, `version`, `time`, `extensions.gd` (thumbnail support), `extensions.zip` (backup archive support).

---

### `list_content_types`

List all content type schemas in the workspace.

| Parameter | Type | Required |
| --------- | ---- | -------- |
| _(none)_  |      |          |

**Required permission:** `schema.read` on `schema:*`

---

### `get_content_type`

Fetch a single content type schema by its collection name.

| Parameter    | Type   | Required |
| ------------ | ------ | -------- |
| `collection` | string | ✅       |

**Required permission:** `schema.read` on `schema:{collection}`

---

### `create_content_type`

Create a new content type.

| Parameter        | Type             | Required |
| ---------------- | ---------------- | -------- |
| `name`           | string           | ✅       |
| `label`          | string           |          |
| `icon`           | string           |          |
| `fields`         | object           |          |

**Required permission:** `schema.create` on `schema:{name}`

Field names may only contain letters, numbers, underscores, and dashes.

---

### `update_content_type`

Surgically update an existing content type. Omitted properties are **preserved** — this is not a full replacement.

| Parameter        | Type             | Required |
| ---------------- | ---------------- | -------- |
| `collection`     | string           | ✅       |
| `label`          | string           |          |
| `icon`           | string           |          |
| `fields`         | object           |          |
| `remove_fields`  | array of strings |          |
| `replace_fields` | boolean          |          |

**Required permission:** `schema.update` on `schema:{collection}`

**Important:** By default, `fields` are merged into the existing schema (new keys added, existing keys overwritten). To delete fields, use `remove_fields`. To replace the entire field map, set `"replace_fields": true`.

---

### `delete_content_type`

Permanently delete a content type and all its entries.

| Parameter    | Type   | Required |
| ------------ | ------ | -------- |
| `collection` | string | ✅       |

**Required permission:** `schema.delete` on `schema:{collection}`

---

### `list_entries`

Fetch one paginated page of entries.

| Parameter    | Type            | Required |
| ------------ | --------------- | -------- |
| `collection` | string          | ✅       |
| `q`          | string          |          |
| `sort`       | string          |          |
| `include`    | string          |          |
| `locale`     | string          |          |
| `filters`    | object          |          |
| `limit`      | integer (1–100) |          |
| `offset`     | integer         |          |

| Default  | Value         |
| -------- | ------------- |
| `sort`   | `-created_at` |
| `limit`  | `20`          |
| `offset` | `0`           |

**Required permission:** `content.read` on `content:{collection}`

Use comma-separated relation names in `include` to expand relation fields inline (e.g. `"include": "author,category"`).

---

### `get_entry`

Fetch one entry by its stable ID or slug.

| Parameter    | Type   | Required |
| ------------ | ------ | -------- |
| `collection` | string | ✅       |
| `identifier` | string | ✅       |
| `include`    | string |          |
| `locale`     | string |          |

**Required permission:** `content.read` on `content:{collection}:{identifier}`

When `locale` matches one of the workspace languages, the entry's localized field values are returned for that locale.

---

### `create_entry`

Create a content entry.

| Parameter    | Type   | Required |
| ------------ | ------ | -------- |
| `collection` | string | ✅       |
| `entry`      | object | ✅       |

**Required permission:** `content.create` on `content:{collection}`

The `entry` object should contain the field values defined in the content type schema. To publish immediately, include `"status": "published"` (requires the `content.publish` permission grant).

Page content fields (Entry content areas) are refused with `422 page_field`; edit them with the page-builder tools and `entry`.

---

### `update_entry`

Update a content entry by its stable ID or slug.

| Parameter    | Type   | Required |
| Page content fields are refused here too; use the page-builder tools with `entry`.

------------ | ------ | -------- |
| `collection` | string | ✅       |
| `identifier` | string | ✅       |
| `entry`      | object | ✅       |

**Required permission:** `content.update` on `content:{collection}:{identifier}`

Only the fields included in `entry` are modified; omitted fields keep their existing values.

---

### `delete_entry`

Soft-delete a content entry by stable ID or slug (moves it to the trash).

| Parameter    | Type   | Required |
| ------------ | ------ | -------- |
| `collection` | string | ✅       |
| `identifier` | string | ✅       |

**Required permission:** `content.delete` on `content:{collection}:{identifier}`

---

### `list_media`

Fetch a compact overview of media files with category counts and one paginated page of results. Use `get_media_item` for full metadata and URLs.

| Parameter  | Type            | Required |
| ---------- | --------------- | -------- |
| `q`        | string          |          |
| `category` | string          |          |
| `limit`    | integer (1–100) |          |
| `offset`   | integer         |          |

**Required permission:** `media.read`

---

### `get_media_item`

Fetch one media file with URLs, dimensions, visibility status, and editable metadata.

| Parameter  | Type   | Required |
| ---------- | ------ | -------- |
| `filename` | string | ✅       |

**Required permission:** `media.read` on `media:{filename}`

---

### `create_media_category`

Create a media category or subcategory.

| Parameter | Type   | Required |
| --------- | ------ | -------- |
| `name`    | string | ✅       |
| `parent`  | string |          |

**Required permission:** `media.update`

If `parent` is provided, the category is created as a child subcategory. Category names are returned in the `meta.categories` array of the response.

---

### `set_media_category`

Assign or clear the category of a media file.

| Parameter  | Type   | Required |
| ---------- | ------ | -------- |
| `filename` | string | ✅       |
| `category` | string |          |

**Required permission:** `media.update` on `media:{filename}`

Omit `category` or pass an empty string to remove the file from its current category.

---

### `delete_media`

Delete a media file.

| Parameter  | Type   | Required |
| ---------- | ------ | -------- |
| `filename` | string | ✅       |

**Required permission:** `media.delete` on `media:{filename}`

---

## Example Session

### Initialize

```json
--> {"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}

<-- {"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"cometcms","title":"CometCMS","version":"1.1.0","description":"CMS content, schema, media, and permission-scoped page-builder tools."}}}
```

### List tools

```json
--> {"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}

<-- {"jsonrpc":"2.0","id":2,"result":{"tools":[{"name":"comet_health","title":"CometCMS health","description":"Check the configured CometCMS public API health endpoint.","inputSchema":{"type":"object","properties":{},"additionalProperties":false}},{"name":"list_content_types","title":"List content types","description":"List CometCMS content type schemas.","inputSchema":{"type":"object","properties":{},"additionalProperties":false}}, ...]}}
```

### Call a tool

```json
--> {"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_content_types","arguments":{}}}

<-- {"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"{\n    \"data\": [\n        {\n            \"name\": \"pages\",\n            \"label\": \"Pages\",\n            \"icon\": \"file-text\",\n            \"fields\": {\n                \"title\": {\"type\": \"text\", \"required\": true},\n                \"body\": {\"type\": \"richtext\"}\n            }\n        }\n    ]\n}"}]}}
```

---

## Tool Discovery

AI assistants discover available tools automatically by calling `tools/list` during initialization. The server returns the full schema for each tool, including parameter names, types, descriptions, and required fields — no out-of-band configuration needed on the client side.

Each tool also declares MCP behavioral annotations:

- `readOnlyHint` identifies tools that do not modify workspace state.
- `destructiveHint` distinguishes overwriting or deleting operations from additive creates.
- `idempotentHint` identifies calls that have no additional effect when repeated with the same arguments.
- `openWorldHint` is `false` because the tools operate only on the configured CometCMS workspace and do not reach unrelated external systems.

These annotations are behavioral hints for MCP clients. Permission checks still apply to every tool call.

---

## Permission Reference

Every MCP tool maps to one or more permission grants. Below is a quick reference:

| Tool                    | Permission(s)                                     |
| ----------------------- | ------------------------------------------------- |
| `comet_health`          | _(authenticated only)_                            |
| `list_content_types`    | `schema.read`                                     |
| `get_content_type`      | `schema.read`                                     |
| `create_content_type`   | `schema.create`                                   |
| `update_content_type`   | `schema.update`                                   |
| `delete_content_type`   | `schema.delete`                                   |
| `list_entries`          | `content.read`                                    |
| `get_entry`             | `content.read`                                    |
| `create_entry`          | `content.create` (+ `content.publish` to publish) |
| `update_entry`          | `content.update` (+ `content.publish` to publish) |
| `delete_entry`          | `content.delete`                                  |
| `list_media`            | `media.read`                                      |
| `get_media_item`        | `media.read`                                      |
| `create_media_category` | `media.update`                                    |
| `set_media_category`    | `media.update`                                    |
| `delete_media`          | `media.delete`                                    |

See [Access Tokens](../guide/api-tokens) for details on creating tokens and assigning permission grants.


## Localization

Languages and their default are configured on the workspace. All content types and the website use those workspace settings directly. Content type tools do not configure languages; returned `locales` and `default_locale` are read-only workspace values. Entry reads accept `locale` and return resolved content, `locale`, and a `revision`. `get_media_item` also accepts `locale` to resolve alternative text and captions. Anonymous reads only expose published variants in that language. Slugs, publication status, and publication dates are localized; stable IDs, authors, and shared fields stay shared.

Entry writes target the `locale` in the `entry` payload and preserve other languages and omitted fields. Include `revision` to detect stale writes (conflicts report status 409), and `reset_fields` to remove localized overrides. Pass ordinary values, not complete translation maps. A missing value inherits public default-language content; explicit empty values do not inherit. Locale and field permissions apply to updates, publication, and resets.

Field labels, descriptions, and select-option labels accept `{"type":"localized","values":{"en":"Name","de":"Name"}}`. Structural schema keys and option values remain shared. The website builder, components, menus, metadata, and forms use the same value model; the MCP design-tool surface is unchanged.
