# Comet Weather

The current weather for a place, from [Open-Meteo](https://open-meteo.com/) (free, no API key). It is the reference example for the extension API features beyond plain blocks:

| Feature | Where |
| --- | --- |
| `permissions.connect` — the page CSP allows `https://api.open-meteo.com` only where the block is placed | `comet-extension.json` |
| `settings` — units, refresh interval and credit, set once under **Website Settings → Extensions** | `comet-extension.json`, read as `{{#@credit}}` and `ctx.settings` |
| `cms` — requires CometCMS 2.0 or later | `comet-extension.json` |
| `mount(element, props, ctx)` returning a cleanup, `ctx.signal`, `ctx.locale`, `ctx.preview` | `blocks/weather/view.js` |
| `category`, `keywords`, a `variations` entry (“Weather badge”) | `blocks/weather/block.json` |
| `group`, `help`, `placeholder`, `visible_when`, `option_labels` | `blocks/weather/block.json` |
| Migrations: `aliases`, `renamed_from`, `option_aliases` | `blocks/weather/block.json` |
| German editor texts | `i18n/de.json` |

The migrations illustrate a 1.x → 2.0 history: version 1 called the block `cometcms/forecast`, its coordinates prop `coordinates` and its wide layout `wide`. Layouts saved with those names keep working and are stored under the new names the next time they are saved.

Validate and pack: `npm run extensions -- validate examples/extensions/comet-weather`, then `npm run extensions -- pack examples/extensions/comet-weather`.
