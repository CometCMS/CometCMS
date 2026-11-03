# CometCMS - Menu Variations

Six navigation elements that use your existing site menus. They appear in their own **Navigation** group of the elements palette:

- **Pill Navigation** — chips that wrap across lines. Styles: soft, outline, solid (current page filled with the accent) and ghost. Align start, center, end or stretch across the row.
- **Segmented Navigation** — a *track* with a raised thumb on the current page, underlined *tabs*, or a *divided* button group. Fill the width with equal segments or fit the content and align it.
- **Numbered Navigation** — an editorial index with large links on the numbers' baseline. Numbering as 01, 1., I, A or none; medium, large or display link sizes; slide or fill hover effects; optional arrows and divider lines.
- **Navigation Cards** — clickable tiles in outline, filled, elevated or stripe styles. Two, three or four desktop columns, or auto-fit; regular or tall cards; start or centered text; optional corner arrows and numbers. Menu icons appear large at the top of a card.
- **Sidebar Navigation** — a vertical list for sidebars and docs. The current page is marked with a rail indicator, a filled pill or plain accent text.
- **Inline Links** — a row of text links for footers and legal links, separated by dots, slashes, pipes, dashes or nothing. Hover underlines, colors, or fades the other links. Align start, center, end or spread across the row.

Every element offers an accent color from the theme, including custom colors, and compact, comfortable or spacious spacing. Pills, segments, cards and the sidebar also take a corner radius from the theme's scale (none, S, M, L, full or the theme's own radius), and pills, segments and cards a surface color. Version 1.1 replaced the earlier corner shapes with these theme values; saved choices carry over as round → full, rounded → M, large → L and square → none, so **rounded** and **large** now follow the theme's M and L sizes. Pills, segments and inline links can show uppercase labels. Solid and filled current-page states pick black or white text for contrast with the accent. Cards become two columns on tablets and one on small phones unless set to auto. Use the builder's normal Style controls for typography, text color, backgrounds and outer spacing.

Menu labels, translations, icons, destinations, new-tab choices and current-page indicators use the same renderer as built-in navigation. Parent links and submenu toggles remain separate; groups without a destination use their label as the toggle. Nested menus expand in place at every screen size. Links stay visible on mobile; these variations do not collapse into a hamburger button. Native disclosure controls support keyboard interaction without extension JavaScript. Theme motion preferences and forced colors are respected.

Missing, empty or unselected menus render no navigation on the published page. The builder shows a placeholder. Decorative index numbers do not replace link labels. Editor texts are available in German. The extension includes no scripts, dependencies, theme preset or starter content.

The persistent block kinds are `block:cometcms/menu-pills`, `block:cometcms/menu-segmented`, `block:cometcms/menu-index`, `block:cometcms/menu-cards`, `block:cometcms/menu-sidebar` and `block:cometcms/menu-inline`.

## Developing and packaging

```sh
npm run extensions -- validate examples/extensions/comet-menu-variations
npm run extensions -- pack examples/extensions/comet-menu-variations examples/extensions/comet-menu-variations.zip
node tests/browser/menu-variations.mjs
```

The browser check renders the real extension and theme in isolated temporary storage. It checks desktop and mobile layouts, long labels, nested disclosures, repeated menu instances, keyboard interaction, accent colors and reduced motion. It requires PHP, Playwright and Chrome/Chromium; set `CHROME_BIN` to override the browser executable.

## Shared props

The menu, accent, surface, corners, spacing and uppercase controls are declared once under `shared_props` in `comet-extension.json` and used by each block with `{"use": "corners"}`. Their German texts live once under `shared_props` in `i18n/de.json`.

## Menu properties in other extensions

The extension API now supports a `menu` property:

```json
"navigation": { "type": "menu", "label": "Menu", "default": "main" }
```

Render it inside a block container using `<div>{{{navigation}}}</div>`. CometCMS supplies an accessible, localized `<nav>` with the shared `.c-menu`, `.c-menu-list`, `.c-submenu` and disclosure classes. Scope custom styles to your block's wrapper. The saved value is a menu name, so menus stay site-owned and translated independently from the layout. A missing or empty menu is falsy on published pages. Menu properties also work in list item fields.
