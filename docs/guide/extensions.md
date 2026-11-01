# Extensions

<div v-pre>

Extensions bring reusable blocks and optional starter content to a workspace. An extension is a ZIP with `comet-extension.json`; everything else is optional. Blocks stay installed with the extension. Content types, components, template lists with their layouts, and a theme preset are copied into normal site storage once, then belong to your site.

The install preview lists every item being copied. Existing items default to **Skip**; choose **Overwrite** per item when you want to replace them. Reinstalling or updating an extension asks again. Installing a theme preset adds it to the designer without applying it.

## Quick start

The official **Comet Essentials** builder extension is developed in `examples/extensions/comet-essentials/`:

```text
comet-essentials/
  comet-extension.json        # required: name, version, title
  style.css                 # optional: CSS shared by all blocks of the extension
  icon.png                  # optional: full-color icon in the Extensions list
  icon.svg                  # optional: palette fallback for blocks without their own
  README.md                 # optional: README.md, LICENSE(.md/.txt), CHANGELOG.md are kept but ignored
  assets/
    check.svg               # optional: images, fonts and videos
    chevron.svg
  blocks/
    before-after/…           # two media images, draggable comparison and range control
    callout/
      block.json            # required: title, description, icon, props
      template.html         # required: the markup
      style.css             # optional
    card/…                  # media, color and link props, and a slot
    feature/
      icon.svg              # optional: this block's palette icon
    logo-bar/…              # scrolling logos with links
    maps/…                  # coordinates, a zoom slider and OpenStreetMap tiles
    pricing/…               # a list of features
    slideshow/…             # image slides, captions, links and optional autoplay
    testimonial/…           # quote, attribution, portrait and optional star rating
    stats/
      view.js               # optional: JavaScript for the website and previews
```

1. Check the extension: `npm run extensions -- validate examples/extensions/comet-essentials`
2. Pack it: `npm run extensions -- pack examples/extensions/comet-essentials` (writes `comet-essentials-1.6.0.zip`)
3. As an administrator, open **Website Settings → Extensions** and drop the ZIP on the upload area.
4. Open a layout. **Before / after**, **Callout**, **Card**, **Logo bar**, **Feature**, **Pricing plan**, **Maps**, **Slideshow**, **Stats** and **Testimonial** are in the elements palette.

**Before / after** compares two media images with editable labels, an initial split from 0 to 100%, an optional caption and landscape (4:3), wide (16:9), square or portrait (3:4) crops. Use similarly framed images for the closest match. The published comparison supports dragging with a mouse or touch and keyboard navigation with arrow keys and Home/End after tabbing to the divider. A visually hidden, labeled native range control provides keyboard and screen-reader support, with a visible focus ring on the divider. Images use media-library alt text. Pages without JavaScript show both images side by side; missing or failed images do not enable the slider.

**Testimonial** displays a translatable quote and attribution (name, role and company), an optional media portrait and an optional one-to-five-star rating. Choose left or centered alignment. The rating has a translatable accessible label; main text and surface styling respect the standard Style controls. It needs no JavaScript.

**Maps** takes coordinates in `latitude, longitude` order, such as `52.520008, 13.404954`, and an initial zoom level from 1 (world) to 19 (street). The name or address is a display label; it is not geocoded. Use decimal degrees with a dot as the decimal separator. Latitude is limited to ±85.05112878 (the Web Mercator map extent), longitude to ±180. The published map shows a pin, zoom buttons and a link to the full map. It loads visible [OpenStreetMap tiles](https://operations.osmfoundation.org/policies/tiles/) when scrolled into view, with browser caching and attribution; it needs no API key or external JavaScript library. Pages without JavaScript show the name, coordinates and a placeholder. Invalid coordinates and tile failures show an editable message. For high-traffic deployments, use a suitable tile provider under its terms.

**Slideshow** accepts up to 12 slides with a media image, title, caption and optional link. Edit the slides in the inspector; image alt text comes from the media library. On the published website, use the arrows, numbered selectors or Left/Right and Home/End keys to change slides. Optional autoplay uses a configurable interval and a play/pause button, pauses while hovered or the page is hidden, and stops after focus or manual navigation. Reduced motion disables automatic playback. All button labels and slide content are translatable. Without JavaScript, the slides stay readable in a horizontally scrollable list.

Zipping the folder yourself works too. A single top-level folder inside the ZIP is unwrapped, and `.DS_Store`, `__MACOSX/` and other dotfiles are ignored. The CLI runs the compiler checks without a running CMS; full seed schema validation happens during installation. It needs only PHP with the zip extension.

## comet-extension.json

```json
{
  "api_version": 1,
  "name": "cometcms/essentials",
  "title": "Comet Essentials",
  "description": "Essential builder blocks that work with any theme.",
  "version": "1.6.0",
  "author": "CometCMS",
  "license": "MIT"
}
```

| Field | Rules |
| --- | --- |
| `api_version` | Required. Always `1`. |
| `name` | Required. `vendor/extension`, each part starting with a lowercase letter, up to 40 lowercase letters, digits or hyphens. The vendors `comet` and `core` are reserved. Uploading an extension with an installed name updates it. |
| `title` | Required. Up to 120 bytes. |
| `version` | Required. Semantic version such as `1.0.0` or `1.1.0-beta.1`. |
| `description`, `author`, `license`, `homepage` | Optional text. `homepage` is an http(s) URL. |
| `requires` | Optional list of up to 32 unique extension names that must be installed and enabled. See [Dependencies](#dependencies). |

Other fields are rejected, so a typo cannot go unnoticed.

## Blocks

### block.json

Every folder `blocks/<name>/` is one block. Its name combines the extension vendor and the folder name: `blocks/callout` in `cometcms/essentials` is the block **`cometcms/callout`**, stored in layouts as the kind `block:cometcms/callout`. Block folder names follow the same rules as name parts. A block name belongs to one extension; installing another extension that defines it is rejected.

```json
{
  "title": "Pricing plan",
  "description": "A plan with its price, features and a call to action.",
  "icon": "mdi:tag-outline",
  "props": {
    "name": { "type": "string", "label": "Plan", "max": 80, "default": "Pro" },
    "highlight": { "type": "bool", "label": "Highlight this plan" },
    "features": {
      "type": "list", "label": "Features", "max": 20,
      "fields": {
        "text": { "type": "string", "label": "Feature", "max": 200 },
        "included": { "type": "bool", "label": "Included", "default": true }
      },
      "default": [{ "text": "Unlimited pages" }, { "text": "Priority support", "included": false }]
    },
    "cta": { "type": "link", "label": "Button link", "default": "#signup" },
    "note": { "type": "text", "label": "Small print", "max": 500 }
  }
}
```

`title` (up to 120 bytes) is the palette label, and the optional `description` (up to 500 bytes) its hint. Do not add `name`; the folder decides it. A block has at most 32 props. Prop names start with a lowercase letter and use lowercase letters, digits and underscores.

**Extension icon.** Put an `icon.png` or `icon.svg` (up to 64 KiB) at the extension root. The Extensions list displays it in full color, or a generic extension symbol when absent. If both files are present, the list uses `icon.png` and blocks without their own icons use `icon.svg`. The example Essentials and Portfolio extensions include transparent 192×192 PNG icons. No manifest field is required. Disabled extensions gray out their icon along with their details.

**Icon.** Give a block its palette icon in one of three ways, in this order of precedence:

1. An `icon.svg` or `icon.png` (up to 64 KiB) in the block folder.
2. An `"icon"` in `block.json`: any [Iconify](https://icon-sets.iconify.design/) name, such as `"mdi:tag-outline"`.
3. An `icon.svg` or `icon.png` at the extension root, shared by blocks without their own.

A block cannot have both an icon name and an icon file. Icon files are shown as a mask in the current text color, like the built-in icons, so only their shape matters: draw them on a transparent background. Blocks without any icon show a cube.

#### Prop types

Every prop needs `type` and `label` (up to 120 bytes); `default` is optional.

| Type | Settings | Default if omitted | Editor | In templates |
| --- | --- | --- | --- | --- |
| `string` | `max` 1–5000 (500) | `""` | Single-line text; can be bound to fields | Escaped text |
| `text` | `max` 1–20000 (2000) | `""` | Multi-line text area | Escaped text; line breaks become `<br>` in element content |
| `richtext` | none | `""` | Rich-text editor; can be bound to fields | HTML, written `{{{name}}}` |
| `url` | `max` 1–5000 (2000) | `""` | URL field | A relative, `http(s)`, `mailto` or `tel` URL |
| `link` | none; the default is a URL | no link | Page, entry, anchor or URL picker with "new tab"; can be bound to fields | The link's address |
| `bool` | none | `false` | Switch | Truthy or falsy |
| `int` | `min`, `max` within ±1,000,000; optional `control: "slider"` | 0, clamped | Number, or a slider with an exact number field | The number |
| `enum` | `options` (required): 1–50 values of letters, digits, `-` and `_` | the first option | Select | The option |
| `icon` | none; the default is a bundled site icon name or `""` | no icon | Searchable site icon picker | `{{name}}` renders a decorative SVG in element content; in attributes it prints the icon name |
| `color` | none; the default is a theme color or `""` | `""` | Theme color picker, including the workspace's custom colors | The color key, such as `primary` |
| `media` | none; no default | no image | Media library; can be bound to fields | The image URL; `{{name.alt}}` is its alternative text |
| `list` | `fields` (required, 1–16 item fields of any other type), `max` 1–100 (20) | `[]` | Items to add, reorder and remove | Repeated by `{{#name}}…{{/name}}` |

For example, `"zoom": { "type": "int", "label": "Zoom level", "min": 1, "max": 19, "default": 13, "control": "slider" }` creates a labeled slider with integer steps and an editable numeric value. `control` accepts `"slider"` or `"number"` on integer props only, including list item fields. It changes the editor control; stored values and bounds validation stay the same.

Defaults are checked against their types. Theme colors are `primary`, `secondary`, `accent`, `background`, `surface`, `text`, `muted` and `border`. List item defaults may leave out fields, which then use the field's default.

Text, rich text, URL, link, media and list props are translated per language. A list is translated as a whole, so translations can add or remove items. Values are validated whenever layouts are saved and loaded. Values of props an extension no longer declares are dropped the next time the layout is saved.

### template.html

Templates are HTML with Mustache tags. All values are HTML-escaped.

| Tag | Meaning |
| --- | --- |
| `{{title}}` | The prop's value, escaped. For media it is the image URL; `{{photo.alt}}` is its alternative text. |
| `{{{body}}}` | A `richtext` prop's HTML, sanitized. It must be the content of a `<div>` or another block container, not a `<p>` or an attribute. |
| `{{#show_link}}…{{/show_link}}` | Rendered when the prop is truthy. Empty strings, `false`, `0`, `"0"`, a missing image and an empty link are falsy. |
| `{{#features}}…{{/features}}` | Repeated for each item of a list. Inside, item fields are available by name, and the block's other props stay available too. `{{^features}}` renders when the list is empty. |
| `{{^show_link}}…{{/show_link}}` | Rendered when the prop is falsy. |
| `{{#tone=success}}…{{/tone}}` | Rendered when an enum prop has this option. `{{^tone=success}}` is the opposite. The closing tag may repeat the option. |
| `<details {{#open}}open{{/open}}>` | Inside a start tag, a section toggles one boolean attribute: `open`, `hidden`, `inert`, `disabled`, `controls`, `autoplay`, `muted`, `loop`, `playsinline` or `reversed`. |
| `{{! comment }}` | Removed. |

Elsewhere inside a start tag, tags may only appear in quoted attribute values: `class="cometcms-callout cometcms-callout--{{tone}}"`. Sections can nest 16 deep. Each section must open and close inside the same element, or inside the same attribute value, so every combination of values produces well-formed HTML. Raw output is only for `richtext` props; there are no partials and no delimiter changes, and the only dotted names are `.url` and `.alt` on media props. Lists cannot be nested.

**Links.** Put a `link` prop in an `<a href="{{cta}}">`. When the editor chooses "open in new tab", CometCMS adds `target="_blank" rel="noopener noreferrer"` itself, unless the template sets its own `target`.

**Colors.** A `color` prop prints its theme key, which fits theme classes such as `c-bg-{{tint}}` or your own class names. To use it in your CSS, set a custom property: `style="--cometcms-tint: {{tint}}"` becomes `--cometcms-tint: var(--c-accent)`, which follows the theme and dark mode. An empty color becomes `initial`, so `var(--cometcms-tint, fallback)` uses the fallback. The `style` attribute accepts nothing else.

#### Slots

A block whose template contains `<slot></slot>` holds child elements: editors drop headings, text, buttons, images, other blocks and so on into it, on the canvas or in the layer outline, and they appear where the slot is. Inside a repeater item, a slot accepts the elements allowed there. The slot must be empty, stand inside a `<div>` or another block container, and not be inside a section. A block has at most one slot. When its extension is missing, a block keeps its child elements for a reinstall.

```html
<article class="cometcms-card" style="--cometcms-card-accent: {{accent}}">
  {{#image}}<img class="cometcms-card__image" src="{{image}}" alt="{{image.alt}}" loading="lazy">{{/image}}
  <div class="cometcms-card__body">
    <h3 class="cometcms-card__title">{{title}}</h3>
    <slot></slot>
    {{#link}}<a class="cometcms-card__link" href="{{link}}">{{link_label}}</a>{{/link}}
  </div>
</article>
```

#### Bindings

`string`, `richtext`, `media` and `link` props can be bound to content in the inspector, like the text of a built-in heading: the current entry's fields, fields of a specific entry, the current item in collection cards and repeaters, and site values such as its title. A bound card in a collection shows each entry's own title, image and page link.

The callout template uses most of this:

```html
{{! The wrapper element gets the class c-block-cometcms__callout automatically. }}
<aside class="cometcms-callout cometcms-callout--{{tone}}" role="note">
  {{#tone=success}}<img class="cometcms-callout__icon" src="assets/check.svg" alt="" width="24" height="24">{{/tone=success}}
  <div>
    <h3 class="cometcms-callout__title">{{title}}</h3>
    <p>{{message}}</p>
    {{#show_link}}{{#url}}<a class="cometcms-callout__link" href="{{url}}">{{link_label}}</a>{{/url}}{{/show_link}}
  </div>
</aside>
```

#### Allowed HTML

Templates are parsed at installation and rejected, with file and line, when they contain anything outside this list. Nothing is silently removed.

- **Elements:** `a abbr address article aside b bdi blockquote br button caption cite code col colgroup dd del details dfn div dl dt em figcaption figure footer h1–h6 header hgroup hr i img ins kbd li mark nav ol p picture pre q s samp search section small source span strong sub summary sup table tbody td tfoot th thead time tr u ul var video wbr`
- **Inline SVG:** `svg g defs path circle ellipse rect line polyline polygon linearGradient radialGradient stop`, with geometry and presentation attributes such as `viewBox`, `d`, `fill`, `stroke`, `stroke-width` and `transform`.
- **On every element:** `class id title role lang dir hidden inert popover translate tabindex`, plus any `aria-*` and `data-*` attribute.
- **Element attributes:** for example `a[href target rel hreflang]`, `img[src srcset sizes alt width height loading decoding fetchpriority]`, `video[src poster controls autoplay muted loop playsinline preload]`, `details[open name]`, `button[type popovertarget popovertargetaction disabled]`, `td/th[colspan rowspan headers]`, `time[datetime]`.

Not allowed: `script`, `style`, `link`, `iframe`, forms and form controls, `on*` event handlers, and `style` attributes other than color custom properties. Buttons must be `type="button"`. A `<p>` cannot contain block elements such as `<section>` or `<div>`, because browsers would close the paragraph early. Links with a `target` automatically get `rel="noopener noreferrer"`.

**URLs.** `href`, `src` and `poster` take a literal URL, an `assets/…` path, or exactly one `url`, `media` or `link` prop: `src="{{photo}}"`. `link` props only fit `<a href>`. A `string` prop cannot be a URL, and URLs cannot be assembled from several parts. Literal URLs must be absolute (`https://…`), start with `/`, `#` or `?`, or use `mailto:` or `tel:`. A bare relative path like `logo.png` is rejected, because it would resolve against each page's address. `srcset` accepts literal URLs and `assets/…` paths only.

**Markup is normalized.** The stored template is the parsed HTML written back out, so `<li>` without closing tags, self-closing SVG elements and similar shorthand are fine.

#### Interactivity without JavaScript

Before reaching for a script, check whether HTML and CSS already do the job. They work in the builder preview too, and need no extra trust:

- **Accordions:** `<details>`/`<summary>`. Items with the same `name` close each other.
- **Menus, popovers and tooltips:** `<button type="button" popovertarget="x">` with `<div id="x" popover>`.
- **Carousels:** CSS scroll snapping on a horizontally scrolling list.
- **State-dependent styling:** `:has()`, `:target`, `details[open]`, and scroll-driven animations.

Use a prop for `id` values, or include one in the template, when a block can appear several times on a page and its elements reference each other.

### JavaScript

A block can have a `view.js`: one ES module whose default export runs for every instance of the block on the website and in previews.

```js
// blocks/stats/view.js
export default function mount(element, props) {
  // element: the block's root element, with the class c-block-cometcms__stats
  // props: the block's values, as the template received them
  for (const number of element.querySelectorAll('[data-value]')) {
    // … animate number up to Number(number.dataset.value) over props.duration ms
  }
  element.dataset.mounted = 'true';
}
```

- **Values.** `props` holds each prop by name: text as strings, `int` as numbers, `bool` as booleans, lists as arrays of item objects, and media, links and colors as objects whose `_` is the URL, the address or the color key (media also have `alt`, links `new_tab`, colors `css`). Bound and translated props are already resolved.
- **Website and previews.** Scripts run in the builder iframe and in full-page draft previews with the resolved block props. After edits, previews containing scripted blocks reload to reset generated markup, listeners and timers while preserving scroll position. Blocks should still provide readable HTML when JavaScript is unavailable (progressive enhancement).
- **Self-contained.** `view.js` must `export default` its mount function and cannot `import` anything. Bundle dependencies into the file, for example `esbuild src/stats.js --bundle --format=esm --outfile=blocks/stats/view.js`. It is limited to 128 KiB and cannot contain `</script`.
- **Errors.** An exception in one instance is logged to the console and does not stop the others.

The script is served as a content-hashed module. Each page that contains the block gets one small loader, which imports the module and calls it for each instance with `data-comet-props`. The website's Content-Security-Policy allows exactly these loaders, by hash, and exactly these module URLs; it never allows the whole site, so other files on the server, such as uploaded media, still cannot run as scripts. Scripts may use `fetch`, but only to the website itself and to the sources the policy allows.

### CSS and assets

Each placed block is wrapped in an element with the class `c-block-<vendor>__<block>`, for example `.c-block-cometcms__callout`, alongside the standard element classes. Prefix your selectors with it. Use theme variables with fallbacks, so the block adapts to any theme:

```css
.c-block-cometcms__callout .cometcms-callout {
  padding: var(--space-lg, 1.5rem);
  border-inline-start: 4px solid var(--c-primary, #2563eb);
}

/* The builder's background controls override this default on the wrapper. */
:where(.c-block-cometcms__callout) {
  background-color: var(--c-background, #fff);
}
```

The **Style** controls already apply to the generated wrapper, including device and hover/focus overrides; no binding API is needed. Let text inherit its color, and keep the main content surface transparent so the wrapper's background colors, gradients and images stay visible. Put default backgrounds on the wrapper using `:where()` so the builder's utility classes take precedence. Setting `color: var(--c-text)` or an opaque background on an inner element hides those choices. For a default link color, use `.c-block-cometcms__callout :where(.cometcms-callout__link) { color: var(--c-primary); }` so the **Link color** control can override it. Internal accents, buttons and map viewports can still have their own colors.

A page includes the CSS of the extensions and blocks it renders, once each, in a `<style data-comet-blocks>` element after the theme. The extension-level `style.css` comes first; use it for shared custom properties or `@font-face`. CSS cannot contain `<`; write `\3C` inside strings.

Files under `assets/` (in up to three levels of subfolders) can be `svg png jpg jpeg gif webp avif woff2 mp4 webm`, at most 8 MiB each. Their content must match the extension. Reference them as `assets/…` from templates and as `url(assets/…)` from any CSS file in the extension. CometCMS rewrites these to content-hashed URLs under `/_site/<workspace>/blocks/`, served with long-lived immutable caching. Other relative URLs in CSS are rejected, for the same reason as in templates.

## Seeded site content

```text
comet-extension.json          # the only required file
blocks/<name>/…               # optional reusable blocks
assets/…                      # optional block assets
content-types/<name>.json     # ContentTypeRepository schema
components/<id>.json          # {name, root, type, nodes}
templates/<type>/<variant>.json
                              # {templates: [...], layouts: {area: nodes}}
theme.json                    # {name, theme}
```

Seed JSON files are limited to 1 MiB each. File names must be safe site names; component ids follow the builder's key rules. A content type's `name`, if present, must match its filename. The compiler checks paths, JSON and sizes. Installation also validates schemas against the current site, the content types and components being seeded, and installed blocks plus this extension's blocks. Validation finishes before any extension or seed files are written. Skipping a dependency that does not exist rejects the install.

Content types are copied first, then components with their declared ids, template lists and layouts, and finally the theme preset. A template seed replaces one type/variant's list as a unit; its `layouts` object contains node lists keyed by template id, including `default`. For example:

```json
{
  "templates": [
    {"id": "default", "name": "Default", "conditions": [], "header": "portfolio-hero", "footer": "default"}
  ],
  "layouts": {"default": []}
}
```

A component contains one structure root (`section`, `row` or `column`) in `nodes`, its `name`, `root`, and a `type` (empty for a component shared between types). Theme presets use a display `name` and a normal theme object; they are matched to existing presets by name.

Try **Comet Portfolio** (`examples/extensions/comet-portfolio/`): install Essentials first, then upload `examples/extensions/comet-portfolio.zip`. The preview lists **Projects**, **Portfolio hero**, **project/detail** and **Portfolio dusk**. The project schema and field-bound case-study template demonstrate media, Markdown, selects, dates, numbers, booleans, a range meter, a color swatch, result repeaters and related-project cards. After installing, Projects appears in the admin and Portfolio dusk appears in the designer. Upload it again to try the per-item conflict choices.

The ledger at `site/extensions-seeded.json` records each installed seed's kind, id, SHA-256 hash and installation time, grouped by extension name. Hashes describe the final stored JSON, including all layouts for a template seed. Skipping an item keeps its original ledger record. Updates do not synchronize site-owned data automatically.

On uninstall, the dialog lists seeded items with every checkbox unchecked. Select only the items to delete. Items edited since installation and types containing entries display warnings when selected. Removing a content type also removes its entries and its site layouts, just as deleting the type in the admin does. Unchecked items remain normal site content. Blocks and assets are always removed, and the extension's ledger entry is dropped. Presets are tracked by their site id, so renaming one does not detach it from the uninstall preview.

A failed seed write restores the previous extension directory and the seeded destinations changed during that attempt. The index cache is `cache/extensions.json` within the workspace.

## Install, update and delete

| Action | How | Effect |
| --- | --- | --- |
| Install | Upload a ZIP in **Website Settings → Extensions**, or copy the extension folder to `cms/storage/workspaces/<workspace>/site/extensions/<vendor>/<extension>/` (`storage/…` in a production build) | The extension is validated completely before anything is written. A rejected upload changes nothing. |
| Update | Upload an extension with the same name | Placed blocks change immediately. If the update removes a block, removes a prop, changes a prop's type or drops an enum option, the upload lists these changes and asks for confirmation first. |
| Delete | **Delete** beside the extension, or remove its folder | Placed blocks keep their settings in the layouts. The builder shows them as missing blocks, and the website omits them until the extension is installed again. |

Use **Enable** or **Disable** in **Website Settings → Extensions** to change an extension’s status. Extensions are enabled when installed. Disabling removes their blocks from the builder palette and stops rendering their blocks, styles and scripts on the website. Imported content types and their entries are also hidden from admin navigation, entry lists, the API and public pages while disabled. Their files, saved block values, nested content, routes and homepage settings remain stored. Re-enabling restores the blocks and imported types with their entries; updating a disabled extension keeps it disabled. Status is stored per workspace in `site/extensions-state.json` and included in workspace backups. Reload an open builder after changing status.

Extensions belong to one workspace and are independent of its theme. Workspace backups include them. A workspace holds up to 64 extensions and 256 blocks; an extension holds up to 64 blocks, 500 files and 25 MiB.

Copied folders register their blocks on the next request. They do not seed site content; upload the ZIP through the admin to preview and copy seeds. CometCMS keeps a compiled index of all extensions and rebuilds it whenever an extension file's size, modification time or inode changes. Extensions in the folder that fail validation are listed with their errors in **Extensions**, without affecting the other extensions. The folder name must match the extension `name`. Reload an open builder after installing or updating an extension.

Keep your extensions in version control. Versions are informational: dependencies are checked by extension name, without version constraints, automatic downloads or rollback beyond reinstalling an older ZIP.

## Dependencies

An optional `requires` list in `comet-extension.json` names extensions that must be installed and enabled in the same workspace:

```json
"requires": ["cometcms/essentials"]
```

Comet Portfolio declares this requirement. Missing or disabled dependencies block installation and enabling with an explanation naming the required extension. Dependencies are listed in the Extensions inventory. Self-dependencies, duplicate names, invalid names and dependency cycles are rejected. The list holds at most 32 names; version constraints and automatic installation are not supported.

Disable dependent extensions first before disabling a requirement. Uninstall all dependent extensions, including disabled ones, before uninstalling a requirement. When folders are deployed directly, dependency failures suspend dependent extensions and their imported content types until their requirements are available again. Folder traversal order does not affect dependency resolution.

## Errors

Validation reports every problem at once, as `file:line: message`, in the upload panel, the API response and the CLI:

```text
blocks/testimonial/template.html:6: {{quote}}: quote is rich text: output its HTML with {{{quote}}}.
blocks/feature/style.css:1: The relative URL "images/bg.png" would resolve against each page's address. Reference extension files as url(assets/…).
```

For coding agents, the loop is: edit the extension, run `npm run extensions -- validate <folder>`, and fix what it reports.

## Trust and permissions

Installing, updating and deleting extensions requires an administrator with the workspace's `site.manage` permission, and a valid CSRF token. Editors who can edit layouts can place installed blocks.

| Capability | Policy |
| --- | --- |
| Data | Blocks see only their own validated props. No content queries, media library, sessions or environment. |
| JavaScript | **Trusted, and confirmed separately.** A `view.js` runs on the website and in previews with the website's origin. Because the admin shares that origin, a script runs with the permissions of any administrator or editor who is signed in while visiting the site. Installing or updating an extension with scripts therefore needs an explicit confirmation, and such extensions are marked "JavaScript" in **Extensions**. Extensions copied into the folder directly are trusted like the rest of the server's files. |
| Server code | None. No PHP, build steps, hooks or extension managers. Other JavaScript files are rejected. |
| Markup | Allow-listed at installation; values are escaped, and rich text is sanitized with the CMS's HTML allow-list. |
| CSS | **Trusted, like custom theme CSS.** It is not sandboxed or scoped, so it can restyle the whole website and load external fonts and images. Prefixing selectors is a convention, not a security boundary. Only install extensions from authors you trust. |
| Assets | Served with `Content-Security-Policy: sandbox` and `nosniff`, so an SVG opened directly cannot run scripts. |

Extension CSS and scripts load in the preview document, including the builder iframe. Installed scripts are trusted code: the iframe shares the admin origin and is not a security boundary. Preview policies allow only the installed block loaders and their exact module URLs.

## API

| Request | Purpose |
| --- | --- |
| `GET /admin/api/site/extensions` | Installed extensions with their blocks, folder extensions that failed to load (`errors`), and `can_install`. Requires `site.manage`. |
| `POST /admin/api/site/extensions` | Multipart upload with the ZIP in `extension`. Returns 201 with `name`, `version`, `previous_version`, `blocks` and `changes`. Invalid extensions return 422 `invalid_extension` with `details`: a list of `{file, line, message}`. Breaking updates return 409 `extension_changes` with `details.changes`; send `accept_changes=1` to proceed. Extensions with scripts return 409 `extension_scripts` with `details.blocks`; send `allow_scripts=1` to install them. Seeds return 409 `extension_seeds` with `details.plan`: `{kind, id, name, exists}` per item. Resubmit `seeds` as a JSON object, for example `{"content_types/project":"create","components/portfolio-hero":"skip","templates/project/detail":"overwrite","presets/portfolio-dusk":"create"}`. New items default to create, existing items to skip. A successful response lists the blocks with scripts in `scripts`. |
| `GET /admin/api/site/extensions/{vendor}/{name}` | Extension metadata plus `seeded`: `{kind, id, name, modified, entry_count?}`. Content types include their entry count. |
| `PUT /admin/api/site/extensions/{vendor}/{name}` | Set `{ "enabled": true }` or `{ "enabled": false }`. Requires an administrator, `site.manage` and CSRF. Returns the extension name and enabled status. |
| `DELETE /admin/api/site/extensions/{vendor}/{name}` | JSON body `{"remove":["components/portfolio-hero"]}` chooses seeded items to delete. Blocks and assets are always removed; unchecked items stay. Returns 404 `not_found` when it is not installed. |

The workspace header and CSRF handling are those of the other admin endpoints.

## Not yet supported

- **Shared modules** between the scripts of several blocks; bundle shared code into each `view.js`.
- **Nested lists**, binding a whole list to a repeater field, and several named slots.

</div>
