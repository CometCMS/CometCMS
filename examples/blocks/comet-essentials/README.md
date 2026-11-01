# Comet Essentials

The CometCMS builder extension: Before / after, Callout, Card, FAQ, Feature, Maps, Pricing plan, Slideshow, Stats and Testimonial. Package ID: `cometcms/essentials`.

Validate and package from the repository root:

```sh
npm run blocks -- validate examples/blocks/comet-essentials
npm run blocks -- pack examples/blocks/comet-essentials examples/blocks/comet-essentials.zip
```

Install the ZIP in **Page Builder → Custom blocks**. Before / after, Maps, Slideshow and Stats include JavaScript, which an administrator must allow during installation.

The Style controls apply to each block wrapper. Main surfaces use overridable background defaults, text inherits the wrapper color, and link defaults yield to the Link color control. Internal accents and map viewports retain their own colors. Reinstall the package ZIP to update an existing installation.

Before / after compares two media images with matching crops, editable labels, four aspect ratios, an initial split and an optional caption. On published pages, drag across the image or Tab to the divider and use the arrow keys or Home/End. The native range control is visually hidden; keyboard focus is shown on the divider. Both images remain visible side by side without JavaScript or if an image fails to load. Image descriptions come from media alt text.

Testimonial displays a quote, name, role and company, with an optional portrait and one-to-five-star rating. Choose left or centered alignment. Quotes and attribution are translatable and bindable; ratings include an editable accessible label. No JavaScript is needed.

Maps accepts a location as decimal `latitude, longitude`, a display name or address, and a zoom slider (1–19). Coordinates locate the pin; the display name is not a search query. The published map uses OpenStreetMap raster tiles, shows attribution, and loads only the visible area. No API key is required. Builder and full-page previews run the block scripts, including the interactive map. See the [OpenStreetMap tile usage policy](https://operations.osmfoundation.org/policies/tiles/) when choosing a provider for production traffic.

This replaces the development example `acme/marketing`. Its `block:acme/*` kinds have been renamed to `block:cometcms/*`; existing layouts using the old example need to be updated before removing that package. Comet Essentials does not rewrite saved layouts.
