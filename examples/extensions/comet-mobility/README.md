# Mobility Infographic

The six interlocking transport rings from [Verkehrsinitiative Gnigl + Langwied](https://verkehrsinitiative.at/#infografik), packaged as a reusable CometCMS extension. The original SVG is bundled unchanged; no external image, script or API requests are needed.

## Install and use

Upload `examples/extensions/comet-mobility.zip` in **Website Settings → Extensions**. The installation review lists the block's JavaScript; enable it to use the interactions. Open or reload the builder and add **Mobility Infographic** (**Mobilitäts-Infografik** in German) from **Infographics / Infografiken**. It works in any normal column. Its stored kind is `block:verkehrsinitiative/mobility-infographic`.

The block starts with the original graphic and caption. Hover a circle to preview its explanation in a popover beneath the mouse. It follows pointer movement until clicked to keep it open. Select it again, use the close button, click outside or press Escape to dismiss it. Tab reaches each mode, arrow keys move between modes, and Enter or Space selects one. Touch and keyboard selection anchor the popover to the selected symbol. Popovers stay within the viewport, flip above the anchor when needed and scroll for long explanations. They appear above surrounding content without changing the graphic's height. Multiple instances work independently.

Titles, category labels, six explanations, caption, accessible graphic description and close label are editable and translatable through normal builder props. **Initially selected mode** can open one explanation by default. **Inner spacing** uses the theme's spacing scale. The normal **Style** controls set the surrounding surface, text color, corners, borders and other styles. The illustration keeps its original colors. Use headings and introductory text as ordinary elements outside this block.

Without JavaScript, all six explanations are readable below the illustration. A cleanup function restores that fallback on unmount and removes all event listeners, allowing builder preview updates in place.

## Validate and package

```sh
npm run extensions -- validate examples/extensions/comet-mobility
npm run extensions -- pack examples/extensions/comet-mobility examples/extensions/comet-mobility.zip
node tests/browser/mobility-infographic.mjs
```

The browser check uses PHP, Playwright and Chrome/Chromium (`CHROME_BIN` overrides its path). It installs into temporary test storage, renders through the real CMS renderer and loads the extension with the CMS script runtime. It checks desktop/mobile, all six modes, keyboard and touch use, multiple instances, the no-JavaScript fallback and preview cleanup/remounting.

## Source artwork and content

Artwork: `https://verkehrsinitiative.at/illustrations/infographic.svg`. Default explanations: the original site's infographic section, captured on 2026-11-04. These are editable source-site statements, including its numerical comparisons; the illustration's circle sizes are artwork and are not calculated from those numbers. Preserve the source artwork's ownership and permissions when distributing it separately. No license for third-party artwork is asserted by this package.
