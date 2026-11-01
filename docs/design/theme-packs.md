# Theme packs and website design

Status: multi-preset packs are implemented; theme-owned CSS and the theme selection model below are proposed.

## Current scope

Website Design edits the site's active design: tokens, custom CSS, reusable components, regions, templates and layouts. A named preset captures only the tokens, including custom colors and shadow definitions. Applying it preserves the site's custom CSS and does not replace page structures or element styles.

Extensions can now seed several presets through `presets/<id>.json`, alongside the legacy single `theme.json`. Comet ThemePack provides Neo-Brutalism, Industrial, Material-inspired and Orbital. Each imported preset has independent update and uninstall tracking. The pack retains the former Neo-Brutalism extension's internal name for upgrades.

A preset is enough for a coordinated palette and typography, but it cannot define glass surfaces, backdrop blur, decorative backgrounds or global button treatments. Today those require element styles or site custom CSS. The root extension `style.css` serves custom blocks and is included when their blocks render; it does not establish a selectable site theme.

## Recommended product model

Keep **Website Design** as the editor for the whole website. Add a **Themes** selection area inside it, with the current design controls serving as customization controls. A theme pack is an extension containing several selectable themes. Saved presets remain named snapshots of a customized design.

A selectable theme should contain:

- A stable id, display name, description and preview metadata.
- Design tokens, including coordinated light and dark palettes.
- An optional theme stylesheet and local assets.
- Optional matching starter components that users can import explicitly.

Applying a theme changes its tokens and stylesheet together. It should preview before saving and report those changes. Existing layouts and content stay in place; importing starter structures is a separate choice. Neo-Brutalism's outlined components demonstrate why a token switch alone cannot completely restyle every existing element.

Start with the four existing designs. Add **Glass** when theme CSS can supply translucent surfaces and backdrop effects, with opaque fallbacks. Orbital can later gain starfields and orbital decoration through the same capability. Those effects are not part of the current token presets.

## Theme stylesheet ownership

Keep theme CSS separate from administrator-written site overrides. Reusing `custom_css` for both would either erase local work on a switch or leave rules from the previous theme active.

The intended stylesheet order is the base element styles, generated tokens, the active theme stylesheet, then site overrides. Extension block styles should remain scoped to their blocks. Theme CSS should use stable public classes and token variables for both color modes, with low-specificity selectors so normal builder controls remain effective.

Import a theme's stylesheet and assets into site-owned theme storage. Switching themes replaces the active theme CSS reference; it must not append rules to the site's override stylesheet. Keeping imported themes on uninstall must keep the assets they need. Disabling an extension should hide its theme choices while preserving the already applied design, matching current preset behavior.

A complete saved design snapshot must include theme identity, tokens, theme CSS and required asset references. Revisions, previews, backups and cache versions must account for that bundle. A saved preset could capture a theme selection plus customized tokens, while site overrides remain site-owned.

Keep the existing administrator-only rule for writing custom CSS. Installing theme CSS and choosing an already installed theme need explicit permission semantics, enforced on the server. A designer's token-only save must not become a route for submitting arbitrary CSS.

## Suggested implementation sequence

1. Multi-preset extension packages and distinct preset tracking — implemented with Comet ThemePack.
2. A theme bundle schema and site-owned storage for its stylesheet and assets, with previews, revisions and cache handling.
3. Theme selection and customization in Website Design, with accurate preview metadata and switch behavior.
4. CSS-enhanced Glass and Orbital variants, and optional starter components per theme.

This keeps current presets and `theme.json` extensions compatible while giving richer themes a defined scope.
