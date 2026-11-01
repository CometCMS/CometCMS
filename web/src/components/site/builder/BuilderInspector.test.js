import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import BuilderInspector from './BuilderInspector.vue'
import { createSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { t } from '../../../i18n/index.js'

vi.mock('./BuilderControl.vue', () => ({
  default: { props: ['name'], setup: (props) => () => h('div', { 'data-control': props.name }) },
}))

async function elementControls(kind, keys, props) {
  const app = createSSRApp({ setup() {
    const builder = createSiteBuilder({ t })
    builder.spec.value = { kinds: { [kind]: { props: Object.fromEntries(keys.map((key) => [key, {}])) } } }
    builder.load([{ id: 'element', kind, props, style: {}, children: [] }])
    builder.select('element')
    return () => h(BuilderInspector)
  } })
  const html = await renderToString(app)
  return [...html.matchAll(/data-control="([^"]+)"/g)].map((match) => match[1])
}

const menuControls = (props) => elementControls('menu', [
  'menu', 'always_button', 'orientation', 'collapse_mobile', 'panel', 'panel_side', 'panel_background', 'panel_text_color',
], { collapse_mobile: true, panel: 'dropdown', ...props })

describe('template part inspector', () => {
  it.each(['header', 'footer'])('shows an edit CTA and shared template explanation for the %s', async (templatePart) => {
    const app = createSSRApp({ setup() {
      createSiteBuilder({ t })
      return () => h(BuilderInspector, { templatePart }, { empty: () => 'Page overview' })
    } })
    const html = await renderToString(app)
    expect(html).toContain(t('site.builder.templatePart'))
    expect(html).toContain(t('site.builder.templatePartHint'))
    expect(html).toContain(t('site.builder.editTemplatePart', { name: t(`site.region.${templatePart}`) }))
    expect(html).not.toContain('Page overview')
    expect(html).not.toContain('data-control=')
    expect(html).not.toContain(t('site.frame.assign'))
  })

  it('assigns the header of a layout and edits the assigned component', async () => {
    const frame = { value: 'blog-header', components: [{ id: 'blog-header', name: 'Blog header' }], scope: 'Blog · Entry design' }
    const app = createSSRApp({ setup() {
      createSiteBuilder({ t })
      return () => h(BuilderInspector, { templatePart: 'header', frame })
    } })
    const html = await renderToString(app)
    expect(html).toContain(t('site.frame.assign'))
    expect(html).toContain(t('site.frame.default.header'))
    expect(html).toContain(t('site.frame.none.header'))
    expect(html).toMatch(/<select id="builder-frame-assign"[^>]* value="blog-header"/)
    expect(html).toMatch(/<option value="blog-header"[^>]*>Blog header<\/option>/)
    expect(html).toContain(t('site.frame.scope', { scope: frame.scope }))
    expect(html).toContain(t('site.frame.editComponent', { name: 'Blog header' }))
  })

  it('offers no edit button for a removed footer', async () => {
    const app = createSSRApp({ setup() {
      createSiteBuilder({ t })
      return () => h(BuilderInspector, { templatePart: 'footer', frame: { value: 'none', components: [], scope: 'Blog · List page' } })
    } })
    const html = await renderToString(app)
    expect(html).toContain(t('site.frame.noneHint.footer'))
    expect(html).toContain(t('site.frame.noComponents'))
    expect(html).not.toContain(t('site.builder.editTemplatePart', { name: t('site.region.footer') }))
  })
})

describe('gallery masonry controls', () => {
  it('offers navigation only with the modal enabled and restores entry links when disabled', async () => {
    const keys = ['media', 'lightbox', 'lightbox_navigation', 'link', 'masonry']
    const enabled = await elementControls('gallery', keys, { media: [], lightbox: true })
    expect(enabled).toEqual(expect.arrayContaining(['lightbox', 'lightbox_navigation', 'masonry']))
    expect(enabled).not.toContain('link')
    const disabled = await elementControls('gallery', keys, { media: [], lightbox: false })
    expect(disabled).toContain('link')
    expect(disabled).not.toContain('lightbox_navigation')
  })
  it('offers the masonry toggle and hides crop controls only while it is enabled', async () => {
    const keys = ['media', 'masonry', 'ratio', 'fit', 'position', 'columns', 'gap', 'captions']
    const props = { media: [], ratio: '4:3' }
    const masonry = await elementControls('gallery', keys, { ...props, masonry: true })
    expect(masonry).toEqual(expect.arrayContaining(['media', 'masonry', 'columns', 'gap', 'captions']))
    for (const key of ['ratio', 'fit', 'position']) expect(masonry).not.toContain(key)
    const grid = await elementControls('gallery', keys, { ...props, masonry: false })
    expect(grid).toEqual(expect.arrayContaining(['masonry', 'ratio', 'fit', 'position']))
  })
})

describe('menu panel controls', () => {
  it('offers position only for side panels and colors for both panel types', async () => {
    expect(await menuControls({ panel: 'drawer' })).toEqual(expect.arrayContaining(['panel_side', 'panel_background', 'panel_text_color']))
    const fullscreen = await menuControls({ panel: 'fullscreen', always_button: true })
    expect(fullscreen).toEqual(expect.arrayContaining(['panel', 'panel_background', 'panel_text_color']))
    expect(fullscreen).not.toContain('panel_side')
    expect(fullscreen).not.toContain('orientation')
    expect(fullscreen).not.toContain('collapse_mobile')
  })

  it('offers dropdown colors wherever a menu opens a dropdown', async () => {
    const dropdown = await menuControls({})
    expect(dropdown).toEqual(expect.arrayContaining(['panel', 'panel_background', 'panel_text_color']))
    expect(dropdown).not.toContain('panel_side')
    // Horizontal submenus still drop down without a menu button.
    const inline = await menuControls({ panel: 'drawer', collapse_mobile: false })
    expect(inline).toEqual(expect.arrayContaining(['panel_background', 'panel_text_color']))
    for (const key of ['panel', 'panel_side']) expect(inline).not.toContain(key)
  })

  it('hides panel settings for vertical menus without a button', async () => {
    const vertical = await menuControls({ orientation: 'vertical', collapse_mobile: false })
    for (const key of ['panel', 'panel_side', 'panel_background', 'panel_text_color']) expect(vertical).not.toContain(key)
  })
})

describe('entry list inspector', () => {
  const keys = ['list_source', 'type', 'collections', 'search_fields', 'sort_field', 'sort_order', 'filter_field', 'filter_value', 'filters', 'sort_choices']

  async function listControls(props) {
    const app = createSSRApp({ setup() {
      const builder = createSiteBuilder({ t })
      const spec = Object.fromEntries(keys.map((key) => [key, {}]))
      spec.list_source = { options: ['type', 'types', 'search'] }
      builder.spec.value = { kinds: { collection: { props: spec } } }
      builder.load([{ id: 'element', kind: 'collection', props, style: {}, children: [] }])
      builder.select('element')
      return () => h(BuilderInspector, { searchPage: true })
    } })
    const html = await renderToString(app)
    return [...html.matchAll(/data-control="([^"]+)"/g)].map((match) => match[1])
  }

  it('offers visitor filters and sorting for lists of several types once types are chosen', async () => {
    expect(await listControls({ list_source: 'types', collections: ['blog', 'events'] })).toEqual(
      ['list_source', 'collections', 'sort_field', 'sort_order', 'filters', 'sort_choices'],
    )
    expect(await listControls({ list_source: 'types', collections: [] })).not.toContain('filters')
  })

  it('keeps them away from search results', async () => {
    expect(await listControls({ list_source: 'search' })).not.toContain('filters')
  })
})
