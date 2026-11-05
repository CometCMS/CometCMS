# Water Ripple

An interactive section background: a lattice of dots that ripples, tears and flares where visitors move the pointer or tap. It works on sections, rows and columns and keeps all their layout options, so you can put any rows, columns and elements on top of it.

## Install and use

Upload `examples/extensions/comet-water-ripple.zip` in **Website Settings → Extensions** and allow its script. Then, in the builder:

- drag **Water Ripple** (or **Drifting Ripple**) from **Effect sections** in the elements palette onto the page. You get a section with a dark surface, light text and the effect behind it; or
- select any section, row or column and pick **Water Ripple** under **Style → Background → Background effect**.

The surface color is the section's own **Background** color, so the usual Style controls change it. Content stays clickable: clicks on links, buttons and form fields belong to them, not to the water.

## Controls

| Control | What it does |
| --- | --- |
| Dot color / Highlight color | Theme colors, including custom colors. They follow the active palette and the light/dark toggle. *None* uses the text color. |
| Reaction strength | 0–100. How strongly ripples, tears and flares show. Lower it behind long text. |
| Ambient drift | 0–100. Broad dithered bands that roll across the lattice without any input. At 0 a settled lattice stops animating. |
| Reacts to | Pointer movement and taps, taps and clicks only, or nothing. |
| Dot spacing | Fine, medium or coarse. Large areas use wider spacing automatically to stay fast. |

## Performance and accessibility

The lattice is drawn on a canvas in the background layer, which ignores the pointer and is hidden from assistive technology. It stops animating when it settles, while it is scrolled out of view and while the tab is hidden. Visitors who prefer reduced motion see a still lattice; forced-colors mode hides it.

The script is `backgrounds/water-ripple/view.js`, one self-contained module with no dependencies, mounted with `mount(layer, props, ctx)`. It also exports `createRippleSurface(cols, rows)`, the wave simulation.

## Validate and package

```sh
npm run extensions -- validate examples/extensions/comet-water-ripple
npm run extensions -- pack examples/extensions/comet-water-ripple examples/extensions/comet-water-ripple.zip
```
