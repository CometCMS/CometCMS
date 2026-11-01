# Comet Portfolio

A project case-study starter. Install and enable Comet Essentials first, then upload this ZIP in **Website Settings → Extensions**. Its manifest declares `"requires": ["cometcms/essentials"]`; installation and enabling check that dependency. Disable Portfolio before disabling Essentials, and uninstall Portfolio before uninstalling Essentials.

The extension copies a `project` content type, a `portfolio-hero` component, the project detail template and its layout, and an optional **Portfolio dusk** theme preset. Installation leaves the active theme alone; choose the preset in the designer when wanted.

```sh
npm run extensions -- validate examples/extensions/comet-portfolio
npm run extensions -- pack examples/extensions/comet-portfolio examples/extensions/comet-portfolio.zip
```

## Project fields

Only the title and slug are required. Optional fields disappear from the public template when empty.

| Fields | Type | Template usage |
| --- | --- | --- |
| `title`, `summary` | Text, textarea | Page heading and an Essentials Callout in the hero |
| `client`, `role` | Text | Labeled project details |
| `project_type` | Select | Project-type badge in the hero |
| `services` | Multiple select | Service badges |
| `launched_on` | Date | Formatted launch date |
| `duration_weeks` | Number | Duration in the details column |
| `featured` | Boolean | Featured/archive badge |
| `completion` | Range, 0–100 | Completion meter |
| `website` | Text containing an HTTPS URL | Linked project website |
| `cover_image` | Single media | Hero image with a 16:9 crop |
| `gallery` | Multiple media | Three-column gallery |
| `overview`, `challenge`, `solution`, `outcome` | Markdown | Case-study narrative in the main column |
| `accent_color` | Color | Project color swatch |
| `results` | Repeater | Three-column metrics, each with `value`, `label` and `detail` |
| `testimonial`, `testimonial_author` | Textarea, text | Client quote and attribution |
| `related_projects` | Multiple relation to Projects | Related-project cards |

The template binds to the current entry. Each results item uses the repeater's `item` source. Media descriptions come from the media library. The hero and detail layout remain editable in Design & Layout after import. Enable public pages for Projects in Website Settings to publish their URLs.

Example content to enter in a project (media filenames must come from your own library):

```json
{
  "title": "Studio website redesign",
  "slug": "studio-website",
  "summary": "A clearer, faster home for an independent studio.",
  "client": "North Studio",
  "role": "Design and development",
  "project_type": "website",
  "services": ["strategy", "design", "development"],
  "launched_on": "2026-10-01",
  "duration_weeks": 8,
  "featured": true,
  "completion": 100,
  "website": "https://example.com",
  "overview": "## A focused digital presence\nA refreshed identity and an accessible website.",
  "challenge": "Make the studio's growing body of work easier to explore.",
  "solution": "A project-led structure, reusable layouts and a simpler content workflow.",
  "outcome": "A faster site that the team can maintain independently.",
  "accent_color": "#6366f1",
  "results": [
    {"value": "+35%", "label": "More enquiries", "detail": "In the first three months."},
    {"value": "2×", "label": "Faster updates", "detail": "With reusable project templates."}
  ],
  "testimonial": "We can finally keep our work up to date ourselves.",
  "testimonial_author": "Alex, studio director"
}
```

## Updating and removing

Version 1.1.0 expands the initial schema. When upgrading, review and choose **Overwrite** for Projects, Portfolio hero and the project detail template to import the new fields and bindings. Back up any custom schema or layout changes first: overwrite replaces those definitions. Existing project entries remain stored. Skipping an item keeps the site's current version.

Disabling Portfolio hides its imported type and entries and preserves their files for re-enabling. On uninstall, the checkboxes start unchecked so you can keep imported content; explicitly selecting Projects also deletes its entries. Essentials remains an independent extension.

The root `icon.png` is the full-color icon shown in the Extensions list. It is a transparent 192×192 PNG below 64 KiB.
