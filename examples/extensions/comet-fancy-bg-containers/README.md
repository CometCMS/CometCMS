# Fancy BG Containers

Four content wrappers with decorative backgrounds. Drop normal builder elements into a container's content area; headings, text, images, buttons and other slot blocks stay editable. The effects use CSS, with no JavaScript, image downloads or dependencies.

## Install and use

Upload `examples/extensions/comet-fancy-bg-containers.zip` in **Website Settings → Extensions**, then reload an open builder. The elements palette gains:

- **Geometric Forms** — floating outlined shapes, with mixed, rings and angular variants.
- **Soft Lights** — drifting light orbs or a breathing aurora ribbon.
- **Deep Space** — layered starfields, optionally with a nebula and planet-like ring.
- **Futuristic Grid** — a perspective floor or flat industrial grid, with a scanning glow and corner marks.

Add a container to a column and drop your content inside. For a full-width section, use a full-width section with a single column, remove its outer padding, and let the container's padding provide the inset. Containers accept the same child elements as other extension slots; structural sections, rows and columns go outside the container.

## Controls

Every container provides:

| Control | Choices |
| --- | --- |
| Background style | Variants specific to each background |
| Primary / secondary effect color | Theme colors, including custom colors; follows the active palette |
| Effect intensity | Subtle, balanced, vivid |
| Motion | Still, drift (40 seconds), gentle (24 seconds), lively (12 seconds) |
| Effect scale | Small, medium, large |
| Content padding | None, compact, comfortable, spacious; scales with screen width |
| Minimum height | Content, compact (20rem), hero (36rem), screen (100svh) |
| Vertical content alignment | Top, center, bottom |

Starfields drift at half the chosen speed. Minimum heights expand to fit longer content. Use the normal **Style** controls to change the dark surface and light text defaults, link color, corners, borders, shadows, spacing and responsive styling. Effect colors are independent of the surface and text controls. Choose the text color to suit your surface and use subtle intensity behind long text.

Only decorative layers are clipped, so content and focus outlines remain visible. Backgrounds ignore pointer events and are hidden from assistive technology. The **Still** option stops all background animations in that container; a visitor's reduced-motion preference stops them in every container, while forced-colors mode hides the decoration. Nested containers retain their own settings.

Blocks are stored as `block:cometcms/fancy-geometry`, `block:cometcms/fancy-lights`, `block:cometcms/fancy-space` and `block:cometcms/fancy-grid`. The extension imports no theme, content types or starter content.

## Validate and package

```sh
npm run extensions -- validate examples/extensions/comet-fancy-bg-containers
npm run extensions -- pack examples/extensions/comet-fancy-bg-containers examples/extensions/comet-fancy-bg-containers.zip
node tests/browser/fancy-bg-containers.mjs
```

The browser check uses PHP, Playwright and Chrome/Chromium (`CHROME_BIN` can override the executable). It renders real blocks in isolated temporary storage and checks layout, content interaction, nested settings, style overrides, and motion preferences at desktop and mobile sizes.
