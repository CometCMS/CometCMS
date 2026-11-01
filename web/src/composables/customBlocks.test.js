import { readFileSync } from 'node:fs'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createPinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import { createSiteBuilder } from './useSiteBuilder.js'
import { findNode } from './layoutTree.js'
import BuilderPalette from '../components/site/builder/BuilderPalette.vue'
import BuilderControl from '../components/site/builder/BuilderControl.vue'
import KindIcon from '../components/site/builder/KindIcon.vue'
import { nodeIcon } from '../components/site/builder/kindIcons.js'
import { t } from '../i18n/index.js'

const manifest = JSON.parse(readFileSync(new URL('../../../examples/extensions/comet-essentials/blocks/callout/block.json', import.meta.url), 'utf8'))
// A block is named after its package vendor and folder: cometcms/essentials's blocks/callout is cometcms/callout.
const kind = 'block:cometcms/callout'
const spec = {
  root: ['section'], elements: ['heading', kind], item_elements: [kind], style: {},
  kinds: {
    section: { props: {}, children: ['row'] }, row: { props: {}, children: ['column'] },
    column: { props: {}, children: ['heading', 'field', 'tabs', kind] },
    heading: { props: { text: { default: '' } }, children: [] },
    field: { props: {}, children: [kind] }, tabs: { props: {}, children: ['tab'] },
    tab: { props: {}, children: [kind] },
    [kind]: { label: manifest.title, description: manifest.description, extension: true, props: manifest.props, style: [], children: [] },
  },
}

async function fixture(render = () => null) {
  let builder
  const app = createSSRApp({ setup() {
    builder = createSiteBuilder({ t })
    builder.spec.value = structuredClone(spec)
    return () => render(builder)
  } })
  app.use(createPinia())
  const html = await renderToString(app)
  return { builder, html }
}

describe('block package blocks in the builder', () => {
  it('exposes the installed example alongside built-ins with its own palette name and description', async () => {
    const { html } = await fixture(() => h(BuilderPalette, { withFields: false }))
    expect(html).toContain('>Callout<')
    expect(html).toContain('>Heading<')
    expect(html).toContain(manifest.description)
    expect(html).not.toContain('site.kind.block:')
  })

  it('creates defaults and wraps root insertions, supports duplication, editing and undo', async () => {
    const { builder } = await fixture()
    const block = builder.make(kind)
    expect(block.props.title).toBe(manifest.props.title.default)
    expect(builder.insert(block)).toBe(true)
    expect(builder.nodes.value[0].kind).toBe('section')
    expect(findNode(builder.nodes.value, block.id).kind).toBe(kind)
    expect(builder.nodeLabel(block)).toBe('Callout')
    builder.updateValue(block.id, 'props', 'title', 'Changed')
    expect(findNode(builder.nodes.value, block.id).props.title).toBe('Changed')
    builder.undo()
    expect(findNode(builder.nodes.value, block.id).props.title).toBe(manifest.props.title.default)
    builder.duplicate(block.id)
    const column = builder.nodes.value[0].children[0].children[0]
    expect(column.children).toHaveLength(2)
    expect(column.children[0].id).not.toBe(column.children[1].id)
  })

  it('uses manifest labels and provides an editable URL input and enum choices', async () => {
    const { html } = await fixture((builder) => h('div', Object.entries(manifest.props).map(([name, def]) => h(BuilderControl, {
      node: builder.make(kind), name, def, value: def.default,
    }))))
    for (const def of Object.values(manifest.props)) expect(html).toContain(def.label)
    expect(html).toContain('value="#details"')
    expect(html).toContain('value="Something worth sharing"')
    expect(html).toContain('success')
    expect(html).not.toContain('site.prop.url')
  })
})

describe('block package editing features', () => {
  const listDef = {
    type: 'list', label: 'Features', max: 2,
    fields: { text: { type: 'string', label: 'Feature', default: '' }, included: { type: 'bool', label: 'Included', default: true } },
  }

  it('edits list items with nested field controls and respects the item limit', async () => {
    const updates = []
    const { html } = await fixture((builder) => h(BuilderControl, {
      node: builder.make(kind), name: 'features', def: listDef,
      value: [{ text: 'Unlimited pages', included: true }],
      onUpdate: (value) => updates.push(value),
    }))
    expect(html).toContain('Unlimited pages')
    expect(html).toContain('Add item')
    expect(html).toContain('<button type="button" class="btn-secondary btn-sm w-full">')

    const full = await fixture((builder) => h(BuilderControl, {
      node: builder.make(kind), name: 'features', def: listDef, value: [{ text: 'A' }, { text: '' }],
    }))
    expect(full.html).toContain('Item 2')
    expect(full.html).toMatch(/<button[^>]*disabled[^>]*>[\s\S]*?Add item/)
  })

  it('shows multi-line text in a textarea', async () => {
    const { html } = await fixture((builder) => h(BuilderControl, {
      node: builder.make(kind), name: 'note', def: { type: 'text', label: 'Note', max: 200 }, value: 'Line one\nLine two',
    }))
    expect(html).toMatch(/<textarea[^>]*maxlength="200"[^>]*>Line one\nLine two<\/textarea>/)
  })

  it('uses package icons and lets slot blocks take dropped elements', async () => {
    const icon = 'data:image/svg+xml;base64,PHN2Zy8+'
    const slotSpec = structuredClone(spec)
    slotSpec.kinds[kind] = { ...slotSpec.kinds[kind], icon, slot: true, children: ['heading'] }
    const { builder } = await fixture()
    builder.spec.value = slotSpec
    expect(nodeIcon({ kind }, false, null, slotSpec)).toBe(icon)
    expect(nodeIcon({ kind: 'block:other/thing' })).toBe('mdi:cube-outline')
    expect(nodeIcon({ kind: 'heading' }, false, null, slotSpec)).toBe('mdi:format-header-pound')
    const block = builder.make(kind)
    expect(builder.isDropContainer(block)).toBe(true)
    builder.insert(block)
    builder.select(block.id)
    expect(builder.insert(builder.make('heading'))).toBe(true)
    expect(findNode(builder.nodes.value, block.id).children.map((child) => child.kind)).toEqual(['heading'])

    const rendered = await renderToString(createSSRApp({ render: () => h(KindIcon, { icon, class: 'h-4 w-4' }) }))
    expect(rendered).toContain('mask:url(&quot;' + icon + '&quot;) center / contain no-repeat')
    expect(rendered).toContain('h-4 w-4')
  })
})
