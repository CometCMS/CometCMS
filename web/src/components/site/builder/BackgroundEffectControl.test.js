import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import BackgroundEffectControl from './BackgroundEffectControl.vue'
import { createSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { t } from '../../../i18n/index.js'

vi.mock('@iconify/vue', () => ({ Icon: { props: ['icon'], setup: (props) => () => h('span', { 'data-icon': props.icon }) } }))
vi.mock('./BuilderControl.vue', () => ({
  default: {
    props: ['name', 'node', 'value', 'variants'],
    setup: (props) => () => h('div', { 'data-control': props.name, 'data-kind': props.node.kind, 'data-value': JSON.stringify(props.value), 'data-variants': String(props.variants) }),
  },
}))

const AURORA = 'block:acme/aurora'
const kinds = {
  [AURORA]: {
    extension: true, background: true, label: 'Aurora', description: 'Northern lights', script: true, l10n: { de: { title: 'Polarlicht', description: 'Nordlichter' } },
    props: {
      tone: { type: 'color', default: 'primary', group: 'Colors' },
      speed: { type: 'int', default: 40, group: 'Motion' },
      pulse: { type: 'bool', default: false, group: 'Motion' },
      pulse_rate: { type: 'int', default: 5, group: 'Motion', visible_when: { pulse: [true] } },
      legacy: { type: 'int', default: 1, deprecated: '' },
    },
  },
  'block:acme/glow': { extension: true, background: true, label: 'Glow', props: {} },
  'block:acme/retired': { extension: true, background: true, label: 'Retired', deprecated: '', props: {} },
}

async function render(value) {
  const app = createSSRApp({ setup() {
    const builder = createSiteBuilder({ t })
    builder.spec.value = { backgrounds: Object.keys(kinds), kinds }
    return () => h(BackgroundEffectControl, { node: { id: 'sec', kind: 'section', props: {}, style: {} }, value })
  } })
  return renderToString(app)
}

const controls = (html) => [...html.matchAll(/data-control="([^"]+)"/g)].map((match) => match[1])

describe('background effect control', () => {
  it('offers installed backgrounds without deprecated ones and no props while none is chosen', async () => {
    const html = await render([])
    expect(html).toContain(t('site.style.background_fx'))
    expect(html).toMatch(/<option value=""[^>]*>None<\/option>/)
    expect(html).toContain('>Aurora<')
    expect(html).toContain('>Glow<')
    expect(html).not.toContain('Retired')
    expect(controls(html)).toEqual([])
  })

  it("shows the chosen background's props by group, as their own kind and without device states", async () => {
    const html = await render({ kind: AURORA, props: { tone: 'accent', speed: 12, pulse: false } })
    expect(html).toContain('Northern lights')
    expect(html).toContain('data-icon="mdi:language-javascript"')
    expect(html).toContain('>Colors<')
    expect(html).toContain('>Motion<')
    // Hidden by visible_when, and deprecated props stay hidden.
    expect(controls(html)).toEqual(['tone', 'speed', 'pulse'])
    expect(html).toContain(`data-kind="${AURORA}"`)
    expect(html).toContain('data-value="&quot;accent&quot;"')
    expect(html).not.toContain('data-variants="true"')
  })

  it('reveals dependent props and fills in defaults for values not saved yet', async () => {
    const html = await render({ kind: AURORA, props: { pulse: true } })
    expect(controls(html)).toEqual(['tone', 'speed', 'pulse', 'pulse_rate'])
    expect(html).toContain('data-value="40"')
  })

  it('keeps a background whose extension is unavailable selectable as it is, without props', async () => {
    const html = await render({ kind: 'block:gone/ripple', props: { level: 3 } })
    expect(html).toContain(t('site.backgroundFx.missing', { name: 'gone/ripple' }))
    expect(controls(html)).toEqual([])
  })

  it('still lists a deprecated background while it is chosen', async () => {
    const html = await render({ kind: 'block:acme/retired', props: {} })
    expect(html).toContain('>Retired<')
    expect(html).toContain(t('site.extensions.deprecatedBlock'))
  })
})
