# Comet Neo-Brutalism

A standalone design theme: sunny yellow, violet and mint, heavy grotesque headings, square corners, thick outlines and crisp offset shadows. Includes a coordinated dark palette and uses local font stacks, with no font downloads or dependencies.

Upload `examples/extensions/comet-neo-brutalism.zip` in **Website Settings → Extensions** and review the four items to import. Then choose **Neo-Brutalism** in the designer's theme presets and save the theme. Installing the extension adds the preset without applying it.

## Components

The component library gains three sections, available for any content type:

- **Neo-Brutalism hero** — a large headline, introduction and button beside a bordered violet statement panel.
- **Neo-Brutalism feature cards** — a section heading and three numbered cards in yellow, violet and mint. The cards stack on phones.
- **Neo-Brutalism call to action** — a violet panel with an oversized heading and a yellow button.

Everything uses built-in headings, text, buttons, rows and columns. Edit the components in the builder to replace the sample copy, choose button destinations and adjust colors, borders, spacing or shadows using the normal controls. The buttons start without destinations. There are no custom blocks, scripts, global CSS overrides, content types or page templates.

The preset controls the site's palette, typography, spacing, corners and shadow scale. Thick outlines and button shadows are set on the components themselves. New elements can use the same look: choose all four border sides, width **3**, color **Border**, radius **None** and shadow **M** or **Neo offset**. Colors follow the active theme, including dark mode.

## Validate and package

```sh
npm run extensions -- validate examples/extensions/comet-neo-brutalism
npm run extensions -- pack examples/extensions/comet-neo-brutalism examples/extensions/comet-neo-brutalism.zip
```

On updates, existing imported items default to **Skip**. Choose **Overwrite** only for items you want to replace with the packaged version. The preset and components become site-owned after import and can be kept when uninstalling the extension; they continue to work without it.
