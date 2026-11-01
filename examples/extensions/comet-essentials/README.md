# Comet Essentials

The CometCMS builder extension: Before / after, Callout, Card, Logo bar, Feature, Maps, Pricing plan, Slideshow, Stats and Testimonial. Extension ID: `cometcms/essentials`.

Validate and extension from the repository root:

```sh
npm run extensions -- validate examples/extensions/comet-essentials
npm run extensions -- pack examples/extensions/comet-essentials examples/extensions/comet-essentials.zip
```

Install the ZIP in **Website Settings → Extensions**. Before / after, Logo bar, Maps, Slideshow and Stats include JavaScript, which an administrator must allow during installation.

The Style controls apply to each block wrapper. Main surfaces use overridable background defaults, text inherits the wrapper color, and link defaults yield to the Link color control. Internal accents and map viewports retain their own colors. Reinstall the extension ZIP to update an existing installation.

Card's **Accent color → None** removes its colored top border and lets its link inherit the text color. Feature uses the shared searchable site icon picker. Updating an older Feature definition asks for confirmation because its icon property changes from an enum to an icon; saved values outside the new catalog fall back to the check icon. Empty Before / after labels omit their label boxes.

Before / after compares two media images with matching crops, editable labels, four aspect ratios, an initial split and an optional caption. On published pages, drag across the image or Tab to the divider and use the arrow keys or Home/End. The native range control is visually hidden; keyboard focus is shown on the divider. Both images remain visible side by side without JavaScript or if an image fails to load. Image descriptions come from media alt text.

Testimonial displays a quote, name, role and company, with an optional portrait and one-to-five-star rating. Choose left or centered alignment. Quotes and attribution are translatable and bindable; ratings include an editable accessible label. No JavaScript is needed.

Maps accepts a location as decimal `latitude, longitude`, a display name or address, and a zoom slider (1–19). Coordinates locate the pin; the display name is not a search query. The published map uses OpenStreetMap raster tiles, shows attribution, and loads only the visible area. No API key is required. Builder and full-page previews run the block scripts, including the interactive map. See the [OpenStreetMap tile usage policy](https://operations.osmfoundation.org/policies/tiles/) when choosing a provider for production traffic.

Logo bar scrolls logos infinitely to the left or right, with configurable speed, logo height and spacing. Entries accept media and optional links. Pause on hover is optional; keyboard focus always stops motion and reveals the focused link. Reduced-motion preferences show a static, wrapping row. Without JavaScript the original row remains visible.

Slideshow overlays titles, captions, links and navigation on the slide images, with a dark gradient for readability. Slides without images use the block background. Long captions expand the slide, and slide selectors scroll horizontally on narrow screens. Autoplay has a **Pause on hover** toggle, enabled by default; leaving the slideshow resumes rotation. Keyboard focus and manual navigation stop autoplay for that instance, and reduced-motion preferences suppress it. Slide changes are announced to screen readers without a visible counter or play/pause button.

The root `icon.png` is the full-color icon shown in the Extensions list. It is a transparent 192×192 PNG below 64 KiB.
