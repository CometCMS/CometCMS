# Comet ThemePack

Four coordinated website design presets, with light and dark palettes and local font stacks. The pack expands the former Comet Neo-Brutalism extension.

Upload `examples/extensions/comet-theme-pack.zip` in **Website Settings → Extensions** and review the eight items to import. Choose a preset in the designer and save the theme to apply it. Installation adds presets and components without changing the current design.

## Designs

| Preset | Character |
| --- | --- |
| **Neo-Brutalism** | Yellow, violet and mint; heavy headings, square corners and crisp offset shadows. |
| **Industrial** | Concrete surfaces, burnt orange and steel; uppercase monospace headings and compact hard shadows. |
| **Material-inspired** | Tonal blue surfaces, generous rounded corners and soft elevation, set in the bundled Inter font. Uses built-in elements; no Material UI dependency. |
| **Orbital** | A space-inspired dark default, violet, cyan and pink accents, geometric headings and glow shadows. Includes a light palette. |

Presets control colors, typography, spacing, corners, shadows and motion timing. Element styles remain editable in the builder. A preset does not replace layouts, add backgrounds or change the site's custom CSS. These are token-based designs; glass effects and other theme-specific CSS need a separate theme stylesheet capability, proposed in [the theme direction](../../../docs/design/theme-packs.md).

## Bundled font

The pack ships **Inter** (Latin, variable weights 100–900, normal and italic) under the SIL Open Font License. Installing adds it to the site's font library like any other font, and the Material-inspired preset uses it for body text and headings. The files are served from the site itself; no external font service is contacted.

An extension bundles a font with a `fonts/<id>.json` file whose faces point at WOFF2 files under `assets/`:

```json
{
  "name": "Inter",
  "category": "sans-serif",
  "license": "…full license text…",
  "faces": [
    { "file": "assets/fonts/inter-latin-normal.woff2", "weight": [100, 900], "style": "normal", "subset": "latin", "unicode_range": "U+0000-00FF,…" }
  ]
}
```

Presets in the same extension select it by its file name (`"body": "inter"`). Disabling the extension leaves the font available, so sites that use it keep rendering. On uninstall, a font that a theme still uses stays in the library.

## Components

The component library gains three sections for any content type:

- **Neo-Brutalism hero** — a headline, introduction and button beside a bordered statement panel.
- **Neo-Brutalism feature cards** — three numbered cards that stack on phones.
- **Neo-Brutalism call to action** — a violet panel with an oversized heading and a yellow button.

These sections use built-in elements and follow the active color tokens, including dark mode. Their thick outlines and button shadows are set on the components themselves, so switching presets keeps that structure and styling. Replace the sample copy and set button destinations before publishing; buttons start without destinations.

To give another element the Neo-Brutalism look, choose all four border sides, width **3**, color **Border**, radius **None** and shadow **M** or **Neo offset**.

There are no custom blocks, scripts, global CSS overrides, content types, page templates, externally hosted fonts or extension dependencies.

## Updates and ownership

Version **2.0.0** renames the extension from `cometcms/neo-brutalism`, its name while it held a single design, to `cometcms/theme-pack`. CometCMS treats a new name as a different extension, so this ZIP no longer updates a Neo-Brutalism installation. To switch, uninstall **Neo-Brutalism** and keep its imported presets and components when asked, then install the ThemePack. Its review lists the kept items as existing ones; choose **Overwrite** to let the ThemePack track and update them, or **Skip** to keep them as independent site content. The bundled Inter font is installed again under the new extension.

Neo-Brutalism stays in the legacy `theme.json`; additional designs live in `presets/`.

Updates refresh unedited imported items by default. Edited items are kept unless you choose **Overwrite** in the review. Each preset is tracked independently, including after renaming it in the site. Imported presets and components can be kept when uninstalling and continue to work without the extension. An applied preset keeps its values when the extension is disabled or removed.

## Validate and package

```sh
npm run extensions -- validate examples/extensions/comet-theme-pack
npm run extensions -- pack examples/extensions/comet-theme-pack examples/extensions/comet-theme-pack.zip
```
