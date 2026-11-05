# Fancy Backgrounds

Four decorative backgrounds for sections, rows and columns. A background sits behind the content, so a section keeps its rows, columns, widths, heights, sticky and scroll options, and any elements in it stay editable. The effects use CSS only, with no JavaScript, image downloads or dependencies.

- **Geometric Forms**: floating outlined shapes, with mixed, rings and angular variants.
- **Soft Lights**: drifting light orbs or a breathing aurora ribbon.
- **Deep Space**: layered starfields, optionally with a nebula and planet-like ring.
- **Futuristic Grid**: a perspective floor or flat industrial grid, with a scanning glow and corner marks.

## Install and use

Upload `examples/extensions/comet-fancy-bg-containers.zip` in **Website Settings → Extensions**, then reload an open builder. Then either:

- drag one of the four backgrounds from **Effect sections** in the elements palette onto the page. You get a one-column section with a dark surface, light text and a minimum height, ready for more columns; or
- select any section, row or column and pick a background under **Style → Background → Background effect**.

The surface is the section's own **Background** color, and an image or video background shows underneath the effect. The usual Style controls change text and link colors, corners, borders, shadows, spacing and responsive styling. A background overlay, if chosen, covers the effect too.

## Controls

Under **Background effect**, every background provides:

| Control | Choices |
| --- | --- |
| Background style | Variants specific to each background |
| Primary / secondary effect color | Theme colors, including custom colors; follows the active palette |
| Effect intensity | Subtle, balanced, vivid |
| Motion | Still, drift (40 seconds), gentle (24 seconds), lively (12 seconds) |
| Effect scale | Small, medium, large |

Starfields drift at half the chosen speed. Use subtle intensity behind long text.

Only the decorative layer is clipped, so content, menus and focus outlines remain visible. Backgrounds ignore pointer events and are hidden from assistive technology. **Still** stops the animations of that background; a visitor's reduced-motion preference stops all of them, and forced-colors mode hides the decoration.

## The earlier containers

Version 1.1 offered the effects as content containers (`block:cometcms/fancy-geometry`, `fancy-lights`, `fancy-space` and `fancy-grid`), which held elements but no rows or columns. They are deprecated: the palette no longer offers them, while pages that use them keep rendering and editing as before. To move one over, add an effect section (or set the background effect on the section around the container) and move its elements into the section's column.

## Files

The backgrounds are `backgrounds/<name>/background.json` and `template.html`, stored as `block:cometcms/geometric-forms`, `soft-lights`, `deep-space` and `futuristic-grid` in a section's `style.background_fx`. Their effect controls are declared once under `shared_props` in `comet-extension.json` and shared with the containers, which also use the layout controls (padding, height, alignment). All effect CSS is in the root `style.css`. Each background's `section` field sets how its palette section starts. Editor texts are available in German.

## Validate and package

```sh
npm run extensions -- validate examples/extensions/comet-fancy-bg-containers
npm run extensions -- pack examples/extensions/comet-fancy-bg-containers examples/extensions/comet-fancy-bg-containers.zip
node tests/browser/section-backgrounds.mjs
node tests/browser/fancy-bg-containers.mjs
```

The browser checks use PHP, Playwright and Chrome/Chromium (`CHROME_BIN` can override the executable). They render real sections and containers in isolated temporary storage and check layout, rows and columns on the backgrounds, content interaction, nested settings, style overrides and motion preferences at desktop and mobile sizes.
