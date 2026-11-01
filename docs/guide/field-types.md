# Field Types

Each field in a content type has a `type` that controls how it is edited in the admin panel and how its value is stored.

Supported fields can include a `default` option. Defaults pre-fill new entries in the admin editor and are also applied when entries are created through the API with that field omitted. Defaults are supported for `text`, `textarea`, `markdown`, `html`, `number`, `range`, `boolean`, `select`, `date`, `datetime`, `json`, and `color` fields.

## Text fields

### `text`

A single-line text input.

| Option     | Description                                        |
| ---------- | -------------------------------------------------- |
| `required` | Whether the field must have a value before saving. |
| `default`  | Initial value used for new entries when omitted.    |

---

### `textarea`

A multi-line plain text area.

Supports `default`.

---

### `markdown`

A rich Markdown editor with a live preview pane. The value is stored as a Markdown string.

Supports `default`.

---

### `html`

A rich HTML editor with visual and raw HTML modes. The value is stored as a sanitized HTML string. Unsupported tags and unsafe attributes such as scripts, inline event handlers, inline styles, and `javascript:` URLs are removed when content is saved.

Supports `default`.

---

### `slug`

A URL-safe identifier (lowercase, hyphens). Can optionally be auto-generated from another field.

| Option   | Description                                                            |
| -------- | ---------------------------------------------------------------------- |
| `source` | The `key` of another field to generate the slug from (e.g. `"title"`). |

---

## Numeric fields

### `number`

A numeric input. Stores the value as a number.

Supports `default`.

---

### `range`

A slider input with configurable min/max/step and display precision.

| Option             | Description                                                   |
| ------------------ | ------------------------------------------------------------- |
| `min`              | Minimum value (default `0`).                                  |
| `max`              | Maximum value (default `100`).                                |
| `step`             | Step increment for slider selection (default `1`).            |
| `default`          | Initial slider value for new entries.                         |
| `display_decimals` | Output precision: `0`, `1`, `2`, `3`, or `full` (default `0`). |

---

## Boolean

### `boolean`

A toggle (true/false). Stored as a JSON boolean.

Supports `default`.

---

## Date & time

### `date`

A date picker. Stores an ISO 8601 date string (`YYYY-MM-DD`).

Supports `default`.

---

### `datetime`

A date + time picker. Stores a full ISO 8601 datetime string.

Supports `default`.

---

## Selection

### `select`

A dropdown with predefined options.

| Option     | Description                                               |
| ---------- | --------------------------------------------------------- |
| `options`  | A map of stored values to labels, or a list of strings.     |
| `multiple` | Allow selecting more than one value. Multi-select values are stored as arrays. |
| `default`  | Initial option value, or array of option values for multi-select. |

Example field definition:

```json
{
  "type": "select",
  "label": "Event type",
  "options": { "meetup": "Meetup", "workshop": "Workshop" }
}
```

---

## Media

### `media`

A media picker that selects one or more files from the [Media Library](./media).

| Option     | Description                                         |
| ---------- | --------------------------------------------------- |
| `multiple` | Allow selecting more than one file in the admin UI. |

Media values are stored as an array of filenames. For single-select media fields, the admin UI limits selection to one item. Public API responses return media fields as arrays of absolute media URLs.

---

## Relational

### `relation`

Links an entry to one or more entries in another (or the same) collection.

| Option       | Description                                             |
| ------------ | ------------------------------------------------------- |
| `target`     | The name of the target content type (e.g. `"authors"`). |
| `multiple`   | Allow selecting multiple related entries.               |

Stores the `id` of the referenced entry. Multi-relation fields store an array of ids.

---

## Structured

### `json`

A raw JSON editor. Useful for storing arbitrary structured data. The value is stored as-is in the entry JSON.

Supports `default`.

---

### `repeater`

An ordered list of rows, each containing the subfields defined in `subfields`. Editors can add, reorder, and remove rows. Values are stored as an array of objects.

```json
{
  "type": "repeater",
  "label": "Highlights",
  "subfields": [
    { "key": "heading", "type": "text", "label": "Heading", "required": true },
    { "key": "description", "type": "textarea", "label": "Description" }
  ]
}
```

### `page`

An entry's own sections designed in the Page Builder. Add a **Content area** to the shared entry layout to create the field and provide default content. **Design in page builder** in the entry editor creates an entry-specific design. An empty value follows the layout default. The structure is shared across languages; visitor-facing text can be translated in the builder. See [Content areas of single entries](./website#content-areas-of-single-entries).

## Color

### `color`

A color picker. Stores a hex color string such as `#ff0000`.

Supports `default`.
