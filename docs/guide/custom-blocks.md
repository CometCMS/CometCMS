# Custom blocks

<div v-pre>

**Block packages** add your own elements to the page builder. A package is a ZIP of plain files: a manifest, one folder per block with an HTML template and CSS, optional assets, and optional JavaScript. CometCMS validates and compiles packages at installation, and every block then renders on the server, identically in the builder preview and on the published website. A block's script adds behavior on the website and in previews.

Blocks behave like built-in elements. They appear in the elements palette with their own icon, get an inspector generated from their props, accept the standard element styles, and work in columns, tabs, reusable components, collection cards and header/footer layouts. Their content is translatable, text, rich text, images and links can be bound to entry fields, and a block can hold other elements in a slot.

## Quick start

The official **Comet Essentials** builder extension is developed in `examples/blocks/comet-essentials/`:

```text
comet-essentials/
  comet-package.json        # required: name, version, title
  style.css                 # optional: CSS shared by all blocks of the package
  icon.svg                  # optional: palette icon for blocks without their own
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
    faq/…                   # a list of questions with rich-text answers
    feature/
      icon.svg              # optional: this block's palette icon
    maps/…                  # coordinates, a zoom slider and OpenStreetMap tiles
    pricing/…               # a list of features
    slideshow/…             # image slides, captions, links and optional autoplay
    testimonial/…           # quote, attribution, portrait and optional star rating
    stats/
      view.js               # optional: JavaScript for the website and previews
```

1. Check the package: `npm run blocks -- validate examples/blocks/comet-essentials`
2. Pack it: `npm run blocks -- pack examples/blocks/comet-essentials` (writes `comet-essentials-1.5.0.zip`)
3. As an administrator, open **Page Builder → Custom blocks** and drop the ZIP on the upload area.
4. Open a layout. **Before / after**, **Callout**, **Card**, **FAQ**, **Feature**, **Pricing plan**, **Maps**, **Slideshow**, **Stats** and **Testimonial** are in the elements palette.

**Before / after** compares two media images with editable labels, an initial split from 0 to 100%, an optional caption and landscape (4:3), wide (16:9), square or portrait (3:4) crops. Use similarly framed images for the closest match. The published comparison supports dragging with a mouse or touch and keyboard navigation with arrow keys and Home/End after tabbing to the divider. A visually hidden, labeled native range control provides keyboard and screen-reader support, with a visible focus ring on the divider. Images use media-library alt text. Pages without JavaScript show both images side by side; missing or failed images do not enable the slider.

**Testimonial** displays a translatable quote and attribution (name, role and company), an optional media portrait and an optional one-to-five-star rating. Choose left or centered alignment. The rating has a translatable accessible label; main text and surface styling respect the standard Style controls. It needs no JavaScript.

**Maps** takes coordinates in `latitude, longitude` order, such as `52.520008, 13.404954`, and an initial zoom level from 1 (world) to 19 (street). The name or address is a display label; it is not geocoded. Use decimal degrees with a dot as the decimal separator. Latitude is limited to ±85.05112878 (the Web Mercator map extent), longitude to ±180. The published map shows a pin, zoom buttons and a link to the full map. It loads visible [OpenStreetMap tiles](https://operations.osmfoundation.org/policies/tiles/) when scrolled into view, with browser caching and attribution; it needs no API key or external JavaScript library. Pages without JavaScript show the name, coordinates and a placeholder. Invalid coordinates and tile failures show an editable message. For high-traffic deployments, use a suitable tile provider under its terms.

**Slideshow** accepts up to 12 slides with a media image, title, caption and optional link. Edit the slides in the inspector; image alt text comes from the media library. On the published website, use the arrows, numbered selectors or Left/Right and Home/End keys to change slides. Optional autoplay uses a configurable interval and a play/pause button, pauses while hovered or the page is hidden, and stops after focus or manual navigation. Reduced motion disables automatic playback. All button labels and slide content are translatable. Without JavaScript, the slides stay readable in a horizontally scrollable list.

Zipping the folder yourself works too. A single top-level folder inside the ZIP is unwrapped, and `.DS_Store`, `__MACOSX/` and other dotfiles are ignored. The CLI runs the same validation as the installer, without a running CMS. It needs only PHP with the zip extension.

## comet-package.json

```json
{
  "api_version": 1,
  "name": "cometcms/essentials",
  "title": "Comet Essentials",
  "description": "Essential builder blocks that work with any theme.",
  "version": "1.5.0",
  "author": "CometCMS",
  "license": "MIT"
}
```

| Field | Rules |
| --- | --- |
| `api_version` | Required. Always `1`. |
| `name` | Required. `vendor/package`, each part starting with a lowercase letter, up to 40 lowercase letters, digits or hyphens. The vendors `comet` and `core` are reserved. Uploading a package with an installed name updates it. |
| `title` | Required. Up to 120 bytes. |
| `version` | Required. Semantic version such as `1.0.0` or `1.1.0-beta.1`. |
| `description`, `author`, `license`, `homepage` | Optional text. `homepage` is an http(s) URL. |

Other fields are rejected, so a typo cannot go unnoticed.

## Blocks and block.json

Every folder `blocks/<name>/` is one block. Its name combines the package vendor and the folder name: `blocks/callout` in `cometcms/essentials` is the block **`cometcms/callout`**, stored in layouts as the kind `block:cometcms/callout`. Block folder names follow the same rules as name parts. A block name belongs to one package; installing another package that defines it is rejected.

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

**Icon.** Give a block its palette icon in one of three ways, in this order of precedence:

1. An `icon.svg` or `icon.png` (up to 64 KiB) in the block folder.
2. An `"icon"` in `block.json`: any [Iconify](https://icon-sets.iconify.design/) name, such as `"mdi:tag-outline"`.
3. An `icon.svg` or `icon.png` at the package root, shared by blocks without their own.

A block cannot have both an icon name and an icon file. Icon files are shown as a mask in the current text color, like the built-in icons, so only their shape matters: draw them on a transparent background. Blocks without any icon show a cube.

### Prop types

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
| `color` | none; the default is a theme color or `""` | `""` | Theme color picker, including the workspace's custom colors | The color key, such as `primary` |
| `media` | none; no default | no image | Media library; can be bound to fields | The image URL; `{{name.alt}}` is its alternative text |
| `list` | `fields` (required, 1–16 item fields of any other type), `max` 1–100 (20) | `[]` | Items to add, reorder and remove | Repeated by `{{#name}}…{{/name}}` |

For example, `"zoom": { "type": "int", "label": "Zoom level", "min": 1, "max": 19, "default": 13, "control": "slider" }` creates a labeled slider with integer steps and an editable numeric value. `control` accepts `"slider"` or `"number"` on integer props only, including list item fields. It changes the editor control; stored values and bounds validation stay the same.

Defaults are checked against their types. Theme colors are `primary`, `secondary`, `accent`, `background`, `surface`, `text`, `muted` and `border`. List item defaults may leave out fields, which then use the field's default.

Text, rich text, URL, link, media and list props are translated per language. A list is translated as a whole, so translations can add or remove items. Values are validated whenever layouts are saved and loaded. Values of props a package no longer declares are dropped the next time the layout is saved.

## template.html

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

### Slots

A block whose template contains `<slot></slot>` holds child elements: editors drop headings, text, buttons, images, other blocks and so on into it, on the canvas or in the layer outline, and they appear where the slot is. Inside a repeater item, a slot accepts the elements allowed there. The slot must be empty, stand inside a `<div>` or another block container, and not be inside a section. A block has at most one slot. When its package is missing, a block keeps its child elements for a reinstall.

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

### Bindings

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

### Allowed HTML

Templates are parsed at installation and rejected, with file and line, when they contain anything outside this list. Nothing is silently removed.

- **Elements:** `a abbr address article aside b bdi blockquote br button caption cite code col colgroup dd del details dfn div dl dt em figcaption figure footer h1–h6 header hgroup hr i img ins kbd li mark nav ol p picture pre q s samp search section small source span strong sub summary sup table tbody td tfoot th thead time tr u ul var video wbr`
- **Inline SVG:** `svg g defs path circle ellipse rect line polyline polygon linearGradient radialGradient stop`, with geometry and presentation attributes such as `viewBox`, `d`, `fill`, `stroke`, `stroke-width` and `transform`.
- **On every element:** `class id title role lang dir hidden inert popover translate tabindex`, plus any `aria-*` and `data-*` attribute.
- **Element attributes:** for example `a[href target rel hreflang]`, `img[src srcset sizes alt width height loading decoding fetchpriority]`, `video[src poster controls autoplay muted loop playsinline preload]`, `details[open name]`, `button[type popovertarget popovertargetaction disabled]`, `td/th[colspan rowspan headers]`, `time[datetime]`.

Not allowed: `script`, `style`, `link`, `iframe`, forms and form controls, `on*` event handlers, and `style` attributes other than color custom properties. Buttons must be `type="button"`. A `<p>` cannot contain block elements such as `<section>` or `<div>`, because browsers would close the paragraph early. Links with a `target` automatically get `rel="noopener noreferrer"`.

**URLs.** `href`, `src` and `poster` take a literal URL, an `assets/…` path, or exactly one `url`, `media` or `link` prop: `src="{{photo}}"`. `link` props only fit `<a href>`. A `string` prop cannot be a URL, and URLs cannot be assembled from several parts. Literal URLs must be absolute (`https://…`), start with `/`, `#` or `?`, or use `mailto:` or `tel:`. A bare relative path like `logo.png` is rejected, because it would resolve against each page's address. `srcset` accepts literal URLs and `assets/…` paths only.

**Markup is normalized.** The stored template is the parsed HTML written back out, so `<li>` without closing tags, self-closing SVG elements and similar shorthand are fine.

### Interactivity without JavaScript

Before reaching for a script, check whether HTML and CSS already do the job. They work in the builder preview too, and need no extra trust:

- **Accordions:** `<details>`/`<summary>`. Items with the same `name` close each other (see the `faq` block, which repeats them for a list of questions).
- **Menus, popovers and tooltips:** `<button type="button" popovertarget="x">` with `<div id="x" popover>`.
- **Carousels:** CSS scroll snapping on a horizontally scrolling list.
- **State-dependent styling:** `:has()`, `:target`, `details[open]`, and scroll-driven animations.

Use a prop for `id` values, or include one in the template, when a block can appear several times on a page and its elements reference each other.

## JavaScript

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

## CSS and assets

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

A page includes the CSS of the packages and blocks it renders, once each, in a `<style data-comet-blocks>` element after the theme. The package-level `style.css` comes first; use it for shared custom properties or `@font-face`. CSS cannot contain `<`; write `\3C` inside strings.

Files under `assets/` (in up to three levels of subfolders) can be `svg png jpg jpeg gif webp avif woff2 mp4 webm`, at most 8 MiB each. Their content must match the extension. Reference them as `assets/…` from templates and as `url(assets/…)` from any CSS file in the package. CometCMS rewrites these to content-hashed URLs under `/_site/<workspace>/blocks/`, served with long-lived immutable caching. Other relative URLs in CSS are rejected, for the same reason as in templates.

## Install, update and delete

| Action | How | Effect |
| --- | --- | --- |
| Install | Upload a ZIP in **Page Builder → Custom blocks**, or copy the package folder to `cms/storage/workspaces/<workspace>/site/blocks/<vendor>/<package>/` (`storage/…` in a production build) | The package is validated completely before anything is written. A rejected upload changes nothing. |
| Update | Upload a package with the same name | Placed blocks change immediately. If the update removes a block, removes a prop, changes a prop's type or drops an enum option, the upload lists these changes and asks for confirmation first. |
| Delete | **Delete** beside the package, or remove its folder | Placed blocks keep their settings in the layouts. The builder shows them as missing blocks, and the website omits them until the package is installed again. |

Packages belong to one workspace and are independent of its theme. Workspace backups include them. A workspace holds up to 64 packages and 256 blocks; a package holds up to 64 blocks, 500 files and 25 MiB.

Copied folders take effect on the next request; there is no registration step. CometCMS keeps a compiled index of all packages and rebuilds it whenever a package file's size, modification time or inode changes. Packages in the folder that fail validation are listed with their errors in **Custom blocks**, without affecting the other packages. The folder name must match the package `name`. Reload an open builder after installing or updating a package.

Keep your packages in version control. Versions are informational: there is no dependency resolution and no rollback beyond reinstalling an older ZIP.

## Errors

Validation reports every problem at once, as `file:line: message`, in the upload panel, the API response and the CLI:

```text
blocks/faq/template.html:6: {{answer}}: answer is rich text: output its HTML with {{{answer}}}.
blocks/feature/style.css:1: The relative URL "images/bg.png" would resolve against each page's address. Reference package files as url(assets/…).
```

For coding agents, the loop is: edit the package, run `npm run blocks -- validate <folder>`, and fix what it reports.

## Trust and permissions

Installing, updating and deleting packages requires an administrator with the workspace's `site.manage` permission, and a valid CSRF token. Editors who can edit layouts can place installed blocks.

| Capability | Policy |
| --- | --- |
| Data | Blocks see only their own validated props. No content queries, media library, sessions or environment. |
| JavaScript | **Trusted, and confirmed separately.** A `view.js` runs on the website and in previews with the website's origin. Because the admin shares that origin, a script runs with the permissions of any administrator or editor who is signed in while visiting the site. Installing or updating a package with scripts therefore needs an explicit confirmation, and such packages are marked "JavaScript" in **Custom blocks**. Packages copied into the folder directly are trusted like the rest of the server's files. |
| Server code | None. No PHP, build steps, hooks or package managers. Other JavaScript files are rejected. |
| Markup | Allow-listed at installation; values are escaped, and rich text is sanitized with the CMS's HTML allow-list. |
| CSS | **Trusted, like custom theme CSS.** It is not sandboxed or scoped, so it can restyle the whole website and load external fonts and images. Prefixing selectors is a convention, not a security boundary. Only install packages from authors you trust. |
| Assets | Served with `Content-Security-Policy: sandbox` and `nosniff`, so an SVG opened directly cannot run scripts. |

Package CSS and scripts load in the preview document, including the builder iframe. Installed scripts are trusted code: the iframe shares the admin origin and is not a security boundary. Preview policies allow only the installed block loaders and their exact module URLs.

## API

| Request | Purpose |
| --- | --- |
| `GET /admin/api/site/blocks` | Installed packages with their blocks, folder packages that failed to load (`errors`), and `can_install`. Requires `site.manage`. |
| `POST /admin/api/site/blocks` | Multipart upload with the ZIP in `package`. Returns 201 with `name`, `version`, `previous_version`, `blocks` and `changes`. Invalid packages return 422 `invalid_block_package` with `details`: a list of `{file, line, message}`. Breaking updates return 409 `block_package_changes` with `details.changes`; send `accept_changes=1` to proceed. Packages with scripts return 409 `block_package_scripts` with `details.blocks`; send `allow_scripts=1` to install them. A successful response lists the blocks with scripts in `scripts`. |
| `DELETE /admin/api/site/blocks/{vendor}/{package}` | Deletes the package. Returns 404 `not_found` when it is not installed. |

The workspace header and CSRF handling are those of the other admin endpoints.

## Not yet supported

- **Shared modules** between the scripts of several blocks; bundle shared code into each `view.js`.
- **Nested lists**, binding a whole list to a repeater field, and several named slots.

</div>
