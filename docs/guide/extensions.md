# Extensions

<div v-pre>

Extensions bring reusable blocks and optional starter content to a workspace. An extension is a ZIP with `comet-extension.json`; everything else is optional. Blocks stay installed with the extension. Content types, components, template lists with their layouts, theme presets and fonts are copied into normal site storage once, then belong to your site.

One install preview shows breaking changes, blocks with JavaScript, and every site item being copied. Confirming it submits these decisions together. Existing items default to **Skip**; choose **Overwrite** per item when you want to replace them. Reinstalling or updating an extension asks again. Installing a theme preset adds it to the designer without applying it.

## Quick start

Create a working extension with one block, then check and preview it:

```sh
npm run extensions -- new my-extension acme/my-extension --block hello
npm run extensions -- validate my-extension
npm run extensions -- render my-extension hello
```

The new files reference the [JSON schemas](#tools-for-editors-and-agents) in `schemas/`, so editors complete and check them while you type. Pack the folder with `npm run extensions -- pack my-extension` and upload the ZIP as described below.

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
    vertical-text/…         # rotated or stacked headings
    stats/
      view.js               # optional: JavaScript for the website and previews
```

1. Check the extension: `npm run extensions -- validate examples/extensions/comet-essentials`
2. Pack it: `npm run extensions -- pack examples/extensions/comet-essentials` (writes `cometcms-essentials-1.8.1.zip`, from the extension name and version)
3. As an administrator, open **Website Settings → Extensions** and drop the ZIP on the upload area.
4. Open a layout. **Before / after**, **Callout**, **Card**, **Logo bar**, **Feature**, **Pricing plan**, **Maps**, **Slideshow**, **Stats**, **Testimonial** and **Vertical Text** are in the elements palette.

`examples/extensions/comet-essentials/README.md` describes how each Essentials block behaves. [Example extensions](#example-extensions) lists the other examples and the parts of the API each one shows.

Zipping the folder yourself works too. A single top-level folder inside the ZIP is unwrapped, and `.DS_Store`, `__MACOSX/` and other dotfiles are ignored. The CLI runs the compiler checks without a running CMS; full seed schema validation happens during installation. It needs only PHP with the zip extension.

## comet-extension.json

```json
{
  "api_version": 1,
  "name": "cometcms/essentials",
  "title": "Comet Essentials",
  "description": "Essential builder blocks that work with any theme.",
  "version": "1.8.1",
  "author": "CometCMS",
  "license": "MIT",
  "cms": ">=2.0",
  "settings": {
    "tile_url": { "type": "url", "label": "Map tile server", "default": "https://tile.openstreetmap.org/{z}/{x}/{y}.png" }
  }
}
```

| Field | Rules |
| --- | --- |
| `api_version` | Required. Always `1`. |
| `name` | Required. `vendor/extension`, each part starting with a lowercase letter, up to 40 lowercase letters, digits or hyphens. The vendors `comet` and `core` are reserved. Uploading an extension with an installed name updates it; a different name is a different extension, so choose it once. |
| `title` | Required. Up to 120 bytes. |
| `version` | Required. Semantic version such as `1.0.0` or `1.1.0-beta.1`. |
| `description`, `author`, `license`, `homepage` | Optional text. `homepage` is an http(s) URL. |
| `requires` | Optional object of up to 32 extension names with [version constraints](#dependencies), such as `{"cometcms/essentials": "^1.7"}`. |
| `cms` | Optional version constraint for the CometCMS release, such as `">=2.0"`. Installation and enabling are refused on other releases. |
| `settings` | Optional object of up to 32 workspace-wide [settings](#settings). |
| `permissions` | Optional [network permissions](#network-permissions) for block scripts: `{"connect": ["https://api.example.com"]}`. |
| `shared_props` | Optional object of up to 32 [shared props](#shared-props) that blocks reuse with `{"use": "name"}`. |
| `$schema` | Optional path or URL of the [JSON schema](#tools-for-editors-and-agents), for editors. Ignored by CometCMS. `block.json` and translation files accept it too. |

Other fields are rejected, so a typo cannot go unnoticed.

## Blocks

### block.json

Every folder `blocks/<name>/` is one block. Its name combines the extension vendor and the folder name: `blocks/callout` in `cometcms/essentials` is the block **`cometcms/callout`**, stored in layouts as the kind `block:cometcms/callout`. Block folder names follow the same rules as name parts. Block names are vendor-wide: two extensions from the same vendor must use different block folder names. A block name belongs to one extension; installing another extension that defines it is rejected. Treat block kinds, prop names and enum options as persistent storage keys. To rename one, declare a [migration](#migrations).

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

| Optional field | Meaning |
| --- | --- |
| `category` | Up to 40 bytes. Blocks with a category get their own palette group under that title; others are listed with the elements. |
| `keywords` | Up to 12 search words of up to 40 bytes each, matched by the palette search. |
| `variations` | Up to 16 named starting values, each listed as its own palette entry. See below. |
| `deprecated` | `true` or a note of up to 300 bytes. The block leaves the palette; placed instances keep working, and the inspector shows the note. |
| `aliases` | Earlier names of this block. See [Migrations](#migrations). |
| `allowed_children` | For blocks with a `<slot>`: the only elements editors may drop into it, as built-in element names (`heading`, `text`, `button`, …) and block names (`cometcms/card`). Other children are dropped when a layout is saved. |
| `$schema` | Ignored; see [Tools for editors and agents](#tools-for-editors-and-agents). |

#### Palette categories, keywords and variations

```json
"category": "Live data",
"keywords": ["forecast", "temperature"],
"variations": [
  { "name": "compact", "title": "Weather badge", "description": "A single line for headers.", "icon": "mdi:thermometer", "props": { "layout": "compact", "show_wind": false } }
]
```

A variation is not a separate block: inserting it places the block with these prop values instead of the defaults. Its `name` is a lowercase key that translations refer to; `title` is required, while `description` and an Iconify `icon` are optional. The values are checked like defaults.

#### Inspector options

Props may also have:

| Field | Applies to | Meaning |
| --- | --- | --- |
| `group` | top-level props and settings | Up to 40 bytes. Props with the same group are shown together in a collapsible section after the ungrouped ones. |
| `help` | all | Up to 300 bytes, shown below the control. |
| `placeholder` | `string`, `text`, `url` | Up to 120 bytes, shown in the empty field. |
| `visible_when` | all | Up to 4 conditions on sibling props, all of which must hold: `{"show_link": true}`, `{"tone": ["info", "warning"]}`. Enum and integer props compare values; other props compare `true` (has a value) or `false` (is empty). Inside a list item, conditions refer to fields of the same item. Hidden props keep their values and still render. |
| `option_labels` | `enum` | Labels editors see instead of stored values: `{"info": "Information"}`. |
| `deprecated` | all | `true` or a note. The prop is hidden in the inspector but still rendered. |

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
| `link` | none; the default is a URL | no link | Shared page, entry, anchor or URL picker with new-tab and page-modal options; can be bound to fields | The link's address |
| `bool` | none | `false` | Switch | Truthy or falsy |
| `int` | `min`, `max` within ±1,000,000; optional `control: "slider"` | 0, clamped | Number, or a slider with an exact number field | The number |
| `enum` | `options` (required): 1–50 values of letters, digits, `-` and `_` | the first option | Select | The option |
| `icon` | none; the default is a bundled site icon name or `""` | no icon | Searchable site icon picker | `{{name}}` renders a decorative SVG in element content; in attributes it prints the icon name |
| `color` | none; the default is a theme color or `""` | `""` | Theme color picker, including the workspace's custom colors | The color key, such as `primary`; its CSS in style properties |
| `space` | optional `option_aliases`; the default is `""`, `none`, `xs`, `sm`, `md`, `lg`, `xl` or `2xl` | `""` | The Style panel's spacing scale, with the theme's sizes | The token; `var(--space-md)` in style properties, `0px` for `none` |
| `radius` | optional `option_aliases`; the default is `""`, `none`, `theme`, `sm`, `md`, `lg` or `full` | `""` | The Style panel's corner choices | The token; `var(--radius-md)` in style properties, `var(--radius-box)` for `theme`, `9999px` for `full` |
| `shadow` | none; the default is `""`, `none`, `sm`, `md` or `lg` | `""` | The Style panel's shadow picker: the S–L scale, the theme's shadow presets and custom shadows | The token, or `custom`; its `box-shadow` value in style properties |
| `media` | none; no default | no image | Media library; can be bound to fields | The image URL; `{{name.alt}}` is its alternative text |
| `menu` | none; default may be a menu name | `""` | Select from the site's menus, or None | `{{{name}}}` renders localized navigation with nested disclosures, icons and active-page states |
| `list` | `fields` (required, 1–16 item fields of any other type), `max` 1–100 (20) | `[]` | Items to add, reorder and remove | Repeated by `{{#name}}…{{/name}}` |

For example, `"zoom": { "type": "int", "label": "Zoom level", "min": 1, "max": 19, "default": 13, "control": "slider" }` creates a labeled slider with integer steps and an editable numeric value. `control` accepts `"slider"` or `"number"` on integer props only, including list item fields. It changes the editor control; stored values and bounds validation stay the same.

Defaults are checked against their types. Theme colors are `primary`, `secondary`, `accent`, `background`, `surface`, `text`, `muted` and `border`. List item defaults may leave out fields, which then use the field's default.

Text, rich text, URL, link, media and list props are translated per language. A list is translated as a whole, so translations can add or remove items. Values are validated whenever layouts are saved and loaded. Values of props an extension no longer declares are dropped the next time the layout is saved. Literal string, text and rich-text props, including list fields, contribute to search and automatic descriptions of entry page content; bound content is indexed through its source fields.

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

Elsewhere inside a start tag, tags may only appear in quoted attribute values: `class="cometcms-callout cometcms-callout--{{tone}}"`. Sections can nest 16 deep. Each section must open and close inside the same element, or inside the same attribute value, so every combination of values produces well-formed HTML. Raw output is only for `richtext` and `menu` props; there are no partials and no delimiter changes, and the only dotted names are `.url` and `.alt` on media props. List iteration cannot be nested, including reusing an enclosing list inside itself. Scalar conditions inside lists are supported.

**Links.** Declare a `link` prop to use the builder's shared destination picker, then put it in an `<a href="{{cta}}">`. Keep the visible label in a separate text prop. When the editor chooses "open in new tab", CometCMS adds `target="_blank" rel="noopener noreferrer"` itself, unless the template sets its own `target`. For internal page destinations, "open in modal" adds `data-link-modal` and `aria-haspopup="dialog"` and uses the site's modal template. These options also work on links inside list items. A plain `url` prop only provides a URL input.

**Theme values.** `color`, `space`, `radius` and `shadow` props store a theme token and offer the same choices as the builder's Style panel, so blocks follow the design system instead of inventing their own scales such as compact/comfortable/spacious. In text and class names they print the token, which fits theme classes such as `c-bg-{{tint}}` or your own: `{{#corners=full}}…{{/corners}}` compares space and radius props like enums. In the `style` attribute they become the CSS value the token stands for: `style="--cometcms-tint: {{tint}}; --cometcms-gap: {{gap}}; --cometcms-radius: {{corners}}"` becomes `--cometcms-tint: var(--c-accent); --cometcms-gap: var(--space-md); --cometcms-radius: var(--radius-box)`, which follows the theme, its edits and dark mode. An empty value becomes `initial`, so `var(--cometcms-gap, 1rem)` uses the fallback. The `style` attribute accepts only custom properties set from these props.

```css
.c-block-cometcms__panel .cometcms-panel {
  padding: var(--cometcms-gap, 1rem);
  border-radius: var(--cometcms-radius, 0);
  box-shadow: var(--cometcms-shadow, none);
}
```

Use theme values for a single spacing, corner or shadow choice. Keep an `enum` when one option sets several related values at once, such as the gap, padding and font size of a density setting.

**Menus.** Declare `"navigation": { "type": "menu", "label": "Menu", "default": "main" }` and place `<div>{{{navigation}}}</div>` in the template. The saved value references a site-owned menu; its labels and destinations follow the current language and subsequent menu edits. The renderer supplies a labeled `<nav class="c-menu c-menu-vertical c-menu-active-bold" data-menu>` with the shared `.c-menu-list`, `.c-menu-label`, `.c-submenu` and native disclosure markup. Scope styles to your own wrapper to create custom navigation layouts. Submenus expand in place by default, and repeated instances have independent disclosure groups. Menu props are not translated or bound separately, and also work as list item fields. Empty or missing menus render no navigation publicly and show a placeholder in the builder. Like rich text, menu HTML requires triple braces inside a block container; it cannot appear in attributes or paragraphs. Sections such as `{{#navigation}}` and `{{^navigation}}` test whether navigation was rendered.

#### Shared props

When several blocks offer the same control, declare it once in `comet-extension.json` and reuse it:

```json
"shared_props": {
  "accent": { "type": "color", "label": "Accent color", "default": "primary", "group": "Colors" },
  "corners": { "type": "radius", "label": "Corners", "default": "md", "group": "Shape" }
}
```

```json
"props": {
  "accent": { "use": "accent" },
  "corners": { "use": "corners", "label": "Card corners", "default": "lg" },
  "show_more": { "type": "bool", "label": "Show more" }
}
```

A prop with `use` starts from the shared definition; any other field given here replaces the shared one, except `type`. The prop name in the block is free, and the same shared prop can be used several times or in list item fields (where its `group` is left out). Shared props cannot use other shared props or have `visible_when`, because conditions depend on the block's other props; add `visible_when` where the prop is used. Each block still stores its own values, and the inspector and MCP see an ordinary prop with an extra `use` field. A translation of a shared prop under `shared_props` in `i18n/<locale>.json` applies wherever a top-level prop uses it, except for texts the block rewords itself; a block's own translation entry can still reword it.

Changing a shared prop changes every block that uses it, and the install review reports breaking changes per block.

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
    {{#show_link}}{{#link}}<a class="cometcms-callout__link" href="{{link}}">{{link_label}}</a>{{/link}}{{/show_link}}
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
// blocks/weather/view.js
export default function mount(element, props, ctx) {
  // element: the block's root element, with the class c-block-cometcms__weather
  // props: the block's values, as the template received them
  // ctx: { settings, locale, preview, reducedMotion, signal }
  const timer = setInterval(update, ctx.settings.refresh_minutes * 60000);
  fetch(url, { signal: ctx.signal });
  return () => clearInterval(timer); // cleanup
}
```

- **Context.** `ctx.settings` holds the extension's [settings](#settings) in the same shapes as props. `ctx.locale` is the page language, for `Intl` formatting. `ctx.preview` is `true` in the builder and in draft previews, for example to skip polling. `ctx.reducedMotion` reports the visitor's preference. `ctx.signal` is an `AbortSignal` that aborts when the instance is unmounted; pass it to `fetch` and `addEventListener(…, { signal })`.
- **Cleanup.** Return a function that stops what `signal` does not cover, such as timers, observers and animation frames. When every scripted instance in the builder preview has returned a cleanup function, edits update the preview in place: instances are unmounted, the markup is updated, and they are mounted again with their new props. Without a cleanup, the preview reloads after each edit instead. Return `() => {}` when there is nothing to clean up.

- **Values.** `props` holds each prop by name: text as strings, `int` as numbers, `bool` as booleans, lists as arrays of item objects, and media, links and colors as objects whose `_` is the URL, the address or the color key (media also have `alt`, links `new_tab` and `open_modal`, colors `css`). Bound and translated props are already resolved.
- **Website and previews.** Scripts run in the builder iframe and in full-page draft previews with the resolved block props. Blocks should still provide readable HTML when JavaScript is unavailable (progressive enhancement).
- **Self-contained.** `view.js` must export its mount function as the default export and cannot `import` anything. Both `export default function mount(…) { … }` and bundled `export { mount as default }` are accepted. The browser parses and executes the module; installer checks do not replace testing it in a preview. Bundle dependencies into the file, for example `esbuild src/stats.js --bundle --format=esm --outfile=blocks/stats/view.js`. It is limited to 128 KiB and cannot contain `</script`.
- **Errors.** An exception in one instance is logged to the console and does not stop the others.

The script is served as a content-hashed module. Each page that contains the block gets one small loader, which imports the module and calls it for each instance with `data-comet-props`. The website's Content-Security-Policy allows exactly these loaders, by hash, and exactly these module URLs; it never allows the whole site, so other files on the server, such as uploaded media, still cannot run as scripts. Scripts may use `fetch`, but only to the website itself and to the origins their extension declares in [`permissions`](#network-permissions).

### Settings

Settings are values an administrator sets once per workspace, under **Website Settings → Extensions → Settings**, such as a map tile server or units. They are declared in `comet-extension.json` like props, with the types `string`, `text`, `url`, `bool`, `int`, `enum` and `color` and the same `label`, `default`, limits, `group`, `help`, `placeholder`, `visible_when` and `option_labels`:

```json
"settings": {
  "units": { "type": "enum", "label": "Units", "options": ["metric", "imperial"], "option_labels": { "metric": "Metric (°C)", "imperial": "Imperial (°F)" } },
  "credit": { "type": "bool", "label": "Credit Open-Meteo", "default": true }
}
```

Templates of all the extension's blocks read them with an `@`: `{{@units}}`, `{{#@credit}}…{{/@credit}}`, `{{#@units=imperial}}…{{/@units}}`, `href="{{@attribution_url}}"`. Scripts receive them as `ctx.settings`. Values are validated like props when saved; unset settings use their defaults. Settings appear in published pages, so never use them for secrets such as private API keys. Changing them refreshes cached pages. They are stored in `site/extensions-settings.json`, kept across updates and removed with the extension.

### Network permissions

The website's Content-Security-Policy allows `fetch` only to the site itself. An extension whose scripts need an API declares its origins:

```json
"permissions": { "connect": ["https://api.open-meteo.com", "https://*.example.com"] }
```

Origins are lowercase `https://` hosts, optionally with a `*.` subdomain wildcard and a port, without a path; at most 16. Pages that render one of the extension's scripted blocks add them to `connect-src`; other pages do not. Permissions require at least one `view.js`, and the install review lists them next to the scripts, so administrators confirm both together. Images, media and fonts from `https:` are already allowed.

### Translations

Editor texts can be translated in `i18n/<locale>.json`, named after a lowercase language code such as `de` or `pt-br`. The admin uses the file for its own language, falling back to the base language (`de` for `de-at`), then to the texts in the manifests:

```json
{
  "title": "Comet Wetter",
  "settings": { "units": { "label": "Einheiten", "options": { "metric": "Metrisch" } } },
  "blocks": {
    "weather": {
      "title": "Wetter", "description": "…", "category": "Live-Daten", "keywords": ["wetter"],
      "variations": { "compact": { "title": "Wetter-Badge" } },
      "props": {
        "layout": { "label": "Layout", "group": "Darstellung", "options": { "card": "Karte" } },
        "rows": { "label": "Zeilen", "fields": { "label": { "label": "Beschriftung" } } }
      }
    }
  }
}
```

Every key is optional. A `shared_props` object translates [shared props](#shared-props) like props. Props accept `label`, `help`, `placeholder`, `group`, enum `options` and list `fields`. Blocks, props, fields, options, settings and variations that do not exist are reported as errors, like typos elsewhere. Translations only change what editors see; translating content for visitors uses the site's languages, as before.

### Migrations

Renaming a block, prop or option would orphan saved values. Declare the earlier names instead; saved layouts are read under the new names and stored that way the next time they are saved:

```json
{
  "aliases": ["cometcms/forecast"],
  "props": {
    "location": { "type": "string", "label": "Coordinates", "renamed_from": ["coordinates"] },
    "layout": { "type": "enum", "label": "Layout", "options": ["card", "compact"], "option_aliases": { "wide": "card" } }
  }
}
```

- `aliases` (block.json): up to 8 earlier names of the block, from the same vendor. Placed `block:cometcms/forecast` instances become `block:cometcms/weather`. An installed block with that name takes precedence.
- `renamed_from` (props and list fields): up to 8 earlier names. A saved value under an earlier name is used when the prop itself has none.
- `option_aliases` (enum, space and radius props): earlier values mapped to current options. Earlier values cannot still be options. With aliases for all of its options, an enum can become a `space` or `radius` prop without a breaking change: `"corners": {"type": "radius", "label": "Corners", "option_aliases": {"round": "full", "square": "none"}}`. Update CSS that relied on the old option classes.

Updates that only rename this way are not breaking changes, so the install review does not ask about them. Keep migrations for as long as older layouts may exist.

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
presets/<id>.json             # {name, theme}; multiple design presets
theme.json                    # legacy single preset, also {name, theme}
fonts/<name>.json             # {name, category, license, faces}; a font family from assets/
```

Seed JSON files are limited to 1 MiB each. File names must be safe site names; component ids follow the builder's key rules. A content type's `name`, if present, must match its filename. The compiler checks paths, JSON and sizes. Installation also validates schemas against the current site, the content types and components being seeded, and installed blocks plus this extension's blocks. Validation finishes before any extension or seed files are written. Skipping a dependency that does not exist rejects the install.

Fonts are copied first, then content types, components with their declared ids, template lists and layouts, and finally the theme presets. A template seed replaces one type/variant's list as a unit; its `layouts` object contains node lists keyed by template id, including `default`. For example:

```json
{
  "templates": [
    {"id": "default", "name": "Default", "conditions": [], "header": "portfolio-hero", "footer": "default"}
  ],
  "layouts": {"default": []}
}
```

A component contains one structure root (`section`, `row` or `column`) in `nodes`, its `name`, `root`, and a `type` (empty for a component shared between types). Theme presets use a display `name` and a normal theme object. An extension can ship several under `presets/`; the legacy root `theme.json` can coexist with them. Preset names must produce distinct site ids. On first import, presets match existing designs by name; subsequent updates track each source file separately, including presets renamed in the site. Keep preset file paths stable between versions. Custom CSS is excluded from presets and stays unchanged when applying one.

A font seed bundles a family with the extension, so presets can use it without an external service:

```json
{
  "name": "Inter",
  "category": "sans-serif",
  "license": "SIL Open Font License 1.1 …",
  "faces": [
    { "file": "assets/fonts/inter-latin-normal.woff2", "weight": [100, 900], "style": "normal", "subset": "latin", "unicode_range": "U+0000-00FF" }
  ]
}
```

`category` is `serif`, `sans-serif`, `monospace`, `cursive` or `fantasy`; `license` is text of up to 128 KiB. Each of 1 to 64 faces names a `.woff2` file under `assets/`, a weight range, `normal` or `italic`, and an optional subset and Unicode range. The file name may not be a built-in font stack such as `system`. Presets of the same extension refer to the family by its file name, `"fonts": {"body": "inter"}`; installation turns that into the site's font id, which depends on the extension name and file name.

The ledger at `site/extensions-seeded.json` records each installed seed's kind, id, SHA-256 hash and installation time, grouped by extension name. Hashes describe the final stored JSON, including all layouts for a template seed. Skipping an item keeps its original ledger record. Creating or explicitly overwriting an item transfers its tracking to the installing extension; an older importer can no longer hide it on disable or offer it for deletion. Updates refresh seeds by default only when this extension seeded them and their hash still matches, meaning nobody edited them since; the review shows them as *From earlier version*. Edited items (*Edited since install*) and existing items the extension does not track are kept unless you choose overwrite. Seeds a newer version no longer ships stay in the site and remain tracked for uninstall. Uninstalling while keeping an item drops that tracking and leaves it independent of extension status.

On uninstall, the dialog lists seeded items. Items unchanged since installation are checked, so by default they are removed with the extension; items edited since installation and types containing entries start unchecked and display warnings when selected. Removing a content type also removes its entries and its site layouts, just as deleting the type in the admin does. Unchecked items remain normal site content. Blocks and assets are always removed, and the extension's ledger entry is dropped. Presets are tracked by their site id, so renaming one does not detach it from the uninstall preview.

A failed seed write restores the previous extension directory and the seeded destinations changed during that attempt. The index cache is `cache/extensions.json` within the workspace.

## Example extensions

The extensions in `examples/extensions/` are working references, each packed next to its folder as a ZIP. Copy the parts you need.

| Extension | Shows |
| --- | --- |
| **Comet Essentials** (`cometcms/essentials`) | Eleven blocks: media, link, list, rich-text, icon and color props, a slot (Card), [scripts](#javascript), [settings](#settings), [variations](#palette-categories-keywords-and-variations), grouped and conditional [inspector options](#inspector-options) and German [translations](#translations). |
| **Comet Weather** (`cometcms/weather`) | Everything beyond plain blocks in one block: settings, [network permissions](#network-permissions), translations, the script lifecycle, a palette category and variation, inspector groups and conditions, and [migrations](#migrations). It shows the current weather from the free Open-Meteo service. |
| **Menu Variations** (`cometcms/menu-variations`) | Six navigation blocks in a **Navigation** palette category, built on `menu` props. They reuse [shared props](#shared-props) for the menu, colors, corners and spacing, and `radius` [theme values](#template-html) for corners, migrated from an earlier enum with `option_aliases`. |
| **Fancy BG Containers** (`cometcms/fancy-bg-containers`) | Four decorative containers with a slot and CSS-only motion that respects reduced motion. Apart from the background style, all their controls are shared props. Place one in a single-column full-width section to use it as a section background. |
| **Comet Portfolio** (`cometcms/portfolio`) | Seeded site content: a content type, a component, a template list and a preset, plus a [dependency](#dependencies) on Essentials. Install Essentials first. The project schema and field-bound case-study template demonstrate media, Markdown, selects, dates, numbers, booleans, a range meter, a color swatch, result repeaters and related-project cards. Upload it again to try the per-item conflict choices. |
| **Comet ThemePack** (`cometcms/theme-pack`) | Four design presets (Neo-Brutalism, Industrial, Material-inspired and Orbital) with a bundled [font](#seeded-site-content) and three editable Neo-Brutalism section components. Choose a preset in the designer and save to apply it. No blocks or scripts. |

Version 2.0 of the ThemePack renamed it from `cometcms/neo-brutalism`, its name while it held one design. A renamed extension does not update the old one: uninstall Neo-Brutalism and keep its items, then install the ThemePack and choose **Overwrite** for the kept items it should track.

## Install, update and delete

| Action | How | Effect |
| --- | --- | --- |
| Install | Upload a ZIP in **Website Settings → Extensions**, or copy the extension folder to `cms/storage/workspaces/<workspace>/site/extensions/<vendor>/<extension>/` (`storage/…` in a production build) | The extension is validated completely before anything is written. A rejected upload changes nothing. |
| Update | Upload an extension with the same name | Placed blocks change immediately. If the update removes a block, slot or prop, changes a prop's type, drops an enum option, narrows an integer range or reduces a text, URL or list limit, the preview lists these changes and asks for confirmation first. |
| Delete | **Delete** beside the extension, or remove its folder | Placed blocks keep their settings in the layouts. The builder shows them as missing blocks, and the website omits them until the extension is installed again. |

Use **Enable** or **Disable** in **Website Settings → Extensions** to change an extension’s status. Extensions are enabled when installed. Disabling removes their blocks from the builder palette and stops rendering their blocks, styles and scripts on the website. Everything the extension seeded is hidden too: imported content types and their entries disappear from admin navigation, entry lists, the API and public pages; imported components disappear from the component library and picker, and their placements stop rendering on the website (the builder shows them as unavailable); imported design presets disappear from the preset list. A theme already applied from a preset keeps its values. Their files, saved block values, nested content, routes and homepage settings remain stored. Re-enabling restores the blocks, imported types with their entries, components and presets; updating a disabled extension keeps it disabled. Status is stored per workspace in `site/extensions-state.json` and included in workspace backups. Reload an open builder after changing status.

Extensions belong to one workspace and are independent of its theme. Workspace backups include them. A workspace holds up to 64 extensions and 256 blocks; an extension holds up to 64 blocks, 500 files and 25 MiB.

Copied folders register their blocks on the next request. They do not seed site content; upload the ZIP through the admin to preview and copy seeds. CometCMS keeps a compiled index of all extensions and rebuilds it whenever an extension file's size, modification time or inode changes. Extensions in the folder that fail validation are listed with their errors and a **Delete** action in **Extensions**, without affecting the other extensions. The folder name must match the extension `name`. Reload an open builder after installing or updating an extension.

Keep your extensions in version control. Versions are compared only by [dependency constraints](#dependencies) and the `cms` field; there are no automatic downloads, and rolling back means reinstalling an older ZIP.

## Dependencies

An optional `requires` object in `comet-extension.json` names extensions that must be installed and enabled in the same workspace, each with a version constraint:

```json
"requires": { "cometcms/essentials": "^1.7" }
```

Constraints follow npm and Composer: `*` (any version), `1.4.2`, partial versions such as `1.4` (any 1.4.x), `^1.7` (1.7.0 up to, not including, 2.0.0; `^0.3` stays below 0.4), `~1.2.3` (below 1.3.0), comparisons such as `>=1.2 <2` separated by spaces, and alternatives joined by `||`. A pre-release such as `2.0.0-beta.1` sorts before `2.0.0`.

Comet Portfolio declares this requirement. Missing, disabled or non-matching dependencies block installation and enabling with an explanation naming the required extension and version. An update that a dependent extension's constraint no longer accepts is refused as well. Dependencies are listed in the Extensions inventory. Self-dependencies, invalid names, invalid constraints and dependency cycles are rejected. The object holds at most 32 names; automatic installation is not supported.

Disable dependent extensions first before disabling a requirement. Uninstall all dependent extensions, including disabled ones, before uninstalling a requirement. When folders are deployed directly, dependency failures suspend dependent extensions and their imported content types until their requirements are available again. Folder traversal order does not affect dependency resolution.

## Errors

Validation reports every problem at once, as `file:line: message`, in the upload panel, the API response and the CLI:

```text
blocks/testimonial/template.html:6: {{quote}}: quote is rich text: output its HTML with {{{quote}}}.
blocks/feature/style.css:1: The relative URL "images/bg.png" would resolve against each page's address. Reference extension files as url(assets/…).
```

## Tools for editors and agents

| Command | Does |
| --- | --- |
| `npm run extensions -- new <folder> [vendor/name] [--block <name>]` | Creates a valid extension with one block that uses a shared prop and theme values. Never overwrites a non-empty folder. |
| `npm run extensions -- validate <folder-or-zip> [--json]` | Runs every compiler check. With `--json`, prints `{"ok": true, "name", "version", "blocks", "scripts", "seeds"}` or `{"ok": false, "errors": [{file, line, message}]}`; the exit code is 0 or 1 either way. |
| `npm run extensions -- render <folder-or-zip> <block> [props.json] [--json]` | Prints a block's HTML with its defaults, or with the prop values in `props.json`. Theme values become their CSS, and media, links and menus get placeholders, so you can check markup and classes without a site. Scripts do not run. |
| `npm run extensions -- pack <folder> [output.zip]` | Validates, then writes the ZIP. |

The loop for coding agents: edit the extension, run `validate --json`, fix what it reports, and `render` the blocks you changed.

**JSON schemas.** `schemas/comet-extension.schema.json`, `schemas/block.schema.json` and `schemas/i18n.schema.json` (JSON Schema draft-07) describe the manifest, `block.json` and translation files. Reference them with a `$schema` path, as the examples and `new` do, so editors complete fields and flag mistakes while you type. The compiler remains the authority: it also checks templates, CSS, assets, defaults against limits, and how files refer to each other.

**MCP.** Agents with builder access call `list_extensions` to see the installed extensions and what each block is for, then `get_builder_schema` with a block's kind for its props. Space and radius props appear there as enums of the theme tokens. Installing extensions and changing their settings stay in the admin, because scripts need an administrator's confirmation.

## Trust and permissions

Installing, updating and deleting extensions requires an administrator with the workspace's `site.manage` permission, and a valid CSRF token. Editors who can edit layouts can place installed blocks.

| Capability | Policy |
| --- | --- |
| Data | Blocks see only their own validated props and their extension's settings. No content queries, media library, sessions or environment. |
| Network | Pages allow `fetch` to the site, plus the `connect` origins the extensions of their scripted blocks declare. Administrators confirm them with the scripts. |
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
| `POST /admin/api/site/extensions` | Multipart upload with the ZIP in `extension`. Returns 201 with `name`, `version`, `previous_version`, `blocks` and `changes`. Invalid extensions return 422 `invalid_extension` with `details`: a list of `{file, line, message}`. When confirmation is needed, returns 409 `extension_review` with `details`: `{extension, installed_version, version, changes, scripts, permissions, plan}`. `changes` lists destructive block changes, `scripts` names every block with JavaScript, `permissions` the network origins those scripts may reach, and `plan` lists `{kind, id, name, exists, tracked, modified, default}` for each seed; `default` is the action applied when `seeds` omits the item. Review them together, then resubmit the ZIP with `accept_changes=1` for breaking changes, `allow_scripts=1` to trust scripts, and `seeds` as a JSON object, for example `{"content_types/project":"create","components/portfolio-hero":"skip","templates/project/detail":"overwrite","presets/portfolio-dusk":"create"}`. New items default to create, unedited items this extension seeded to overwrite, and other existing items to skip. The server revalidates everything before writing; no uploaded ZIP is retained between requests. A successful response lists the blocks with scripts in `scripts`. |
| `GET /admin/api/site/extensions/{vendor}/{name}` | Extension metadata plus `seeded`: `{kind, id, name, modified, entry_count?}`. Content types include their entry count. |
| `PUT /admin/api/site/extensions/{vendor}/{name}` | Set `{ "enabled": true }` or `{ "enabled": false }`, or save settings with `{ "settings": {"units": "imperial"} }`. Requires an administrator, `site.manage` and CSRF. Returns the extension name and enabled status, or the normalized `settings`. The extension list includes each extension's `settings` specs and current `settings_values`. |
| `DELETE /admin/api/site/extensions/{vendor}/{name}` | JSON body `{"remove":["components/portfolio-hero"]}` chooses seeded items to delete. Blocks and assets are always removed; unchecked items stay. Returns 404 `not_found` when it is not installed. |

The workspace header and CSRF handling are those of the other admin endpoints.

## Not yet supported

- **Shared modules** between the scripts of several blocks; bundle shared code into each `view.js`.
- **Value transforms** in migrations, such as splitting one prop into two or converting types; renames and option aliases are declarative only.
- **Secret settings** and server-side requests; settings are public, and scripts call APIs from the visitor's browser.
- **Nested lists**, binding a whole list to a repeater field, and several named slots.

</div>
