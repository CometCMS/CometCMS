import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createPinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import SpacingControl from './SpacingControl.vue'
import { useSiteStore } from '../../../stores/site.js'

const keys = ['padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left']
const spec = Object.fromEntries(keys.map((key) => [key, { options: ['', 'none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'] }]))

async function render(style = {}, allowed = keys) {
  const app = createSSRApp({ setup() {
    useSiteStore().draft = { theme: { space: { xs: 4, sm: 8, md: 20, lg: 32, xl: 56, '2xl': 96 } } }
    return () => h(SpacingControl, { node: { id: 'section', style }, spec, keys: allowed })
  } })
  app.use(createPinia())
  return renderToString(app)
}

describe('spacing box', () => {
  it('offers eight accessible dropdowns with the current theme scale and an explicit zero', async () => {
    const html = await render({ margin_top: 'md', margin_bottom: 'none' })
    expect(html.match(/role="combobox"/g)).toHaveLength(8)
    for (const key of keys) expect(html).toContain(`id="builder-section-style-${key}"`)
    expect(html).toContain('M · 20px')
    expect(html).toContain('aria-label="Margin bottom: 0"')
    expect(html).toContain('aria-label="Padding top: Default"')
    expect(html.match(/aria-haspopup="listbox"/g)).toHaveLength(8)
    expect(html.match(/aria-expanded="false"/g)).toHaveLength(8)
    expect(html).not.toContain('<select')
    expect(html).not.toContain('site.style.')
  })

  it('shows individual padding edges and explicit zero without modifying the node', async () => {
    const style = { padding_top: 'lg', padding_bottom: 'lg', padding_right: 'sm', padding_left: 'none', margin_bottom: 'xl' }
    const html = await render(style)
    expect(html).toContain('title="Padding top: L · 32px"')
    expect(html).toContain('title="Padding bottom: L · 32px"')
    expect(html).toContain('title="Padding right: S · 8px"')
    expect(html).toContain('title="Padding left: 0"')
    expect(html).toContain('title="Margin bottom: XL · 56px"')
    expect(style).toEqual({ padding_top: 'lg', padding_bottom: 'lg', padding_right: 'sm', padding_left: 'none', margin_bottom: 'xl' })
  })

  it('links the sides by default so one control per group sets all four', async () => {
    const html = await render({ padding_top: 'md', padding_right: 'md', padding_bottom: 'md', padding_left: 'md' })
    expect(html.match(/role="combobox"/g)).toHaveLength(2)
    expect(html).toContain('aria-pressed="true"')
    expect(html).toContain('id="builder-section-style-margin_left"')
    expect(html).toContain('aria-label="Padding: M · 20px"')
  })

  it('starts unlinked when sides already differ, keeping every value visible', async () => {
    const html = await render({ padding_top: 'md' })
    expect(html.match(/role="combobox"/g)).toHaveLength(8)
    expect(html).toContain('aria-pressed="false"')
  })

  it('only exposes edges allowed by the schema', async () => {
    const html = await render({}, ['margin_bottom'])
    expect(html.match(/role="combobox"/g)).toHaveLength(1)
    expect(html).toContain('aria-label="Margin bottom: Default"')
  })
})
