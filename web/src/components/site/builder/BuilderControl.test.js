import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createPinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import { getIcon } from '@iconify/vue'
import BuilderControl from './BuilderControl.vue'
import { createSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { SITE_ICONS } from '../../../composables/siteIcons.js'
import { t } from '../../../i18n/index.js'

async function renderControl(name, def, value, overrides = {}) {
  const { styleState = 'normal', ...controlProps } = overrides
  const app = createSSRApp({ setup() {
    const builder = createSiteBuilder({ t })
    builder.styleState.value = styleState
    return () => h(BuilderControl, { node: { id: 'icon1', kind: 'icon', props: {}, style: {} }, name, def, value, ...controlProps })
  } })
  app.use(createPinia())
  return renderToString(app)
}

describe('builder HTML source control', () => {
  it('edits markup as escaped source without inserting it into the admin page', async () => {
    const source = '<style>.promo { color: red; }</style>\n</textarea><section class="promo">Hello</section>'
    const html = await renderControl('html', { type: 'raw_html', max: 50000, default: '' }, source, {
      node: { id: 'html1', kind: 'html', props: { html: source }, style: {} },
    })
    expect(html).toContain('<textarea id="builder-html1-props-html"')
    expect(html).toContain('HTML source')
    expect(html).toContain('maxlength="50000"')
    expect(html).toContain('spellcheck="false"')
    expect(html).toContain('&lt;style&gt;.promo { color: red; }&lt;/style&gt;\n&lt;/textarea&gt;')
    expect(html).not.toContain('<style>')
    expect(html).not.toContain('<section')
    expect(html).not.toContain('tiptap')
  })
})

describe('builder icon controls', () => {
  it('uses the existing picker with a label and a locally available selected icon', async () => {
    const html = await renderControl('icon', { type: 'icon', default: 'mdi:star-outline', options: SITE_ICONS }, 'mdi:heart-outline')
    expect(html).toContain('<button id="builder-icon1-props-icon"')
    expect(html).toContain('for="builder-icon1-props-icon"')
    expect(html).toContain('>Heart Outline</span>')
    expect(html).not.toContain('<select')
    expect(getIcon('mdi:heart-outline')?.body).toContain('<path')
  })

  it('offers exact pixel sizes and an accessible label rather than a field label toggle', async () => {
    const size = await renderControl('size', { type: 'int', min: 12, max: 240, unit: 'px', default: 48 }, 48)
    expect(size).toContain('step="1"')
    expect(size).toContain('value="48"')
    const label = await renderControl('label', { type: 'string', max: 120, default: '' }, '')
    expect(label).toContain('Accessible label')
    expect(label).not.toContain('Show field label')
  })

  it('offers labeled background shapes and names the foreground color for icons', async () => {
    const shape = await renderControl('shape', { type: 'enum', options: ['none', 'circle', 'square', 'squircle'], default: 'none' }, 'circle')
    for (const label of ['Background shape', 'None', 'Circle', 'Square', 'Squircle']) expect(shape).toContain(label)
    expect(shape).toContain('aria-checked="true" aria-label="Circle"')
    const color = await renderControl('text_color', { type: 'color', options: ['', 'primary', 'inverse'], default: '' }, 'primary', { group: 'style' })
    expect(color).toContain('Icon color')
  })
})

describe('extension accent colors', () => {
  it('labels an empty extension color as None and keeps style color defaults', async () => {
    const def = { type: 'color', default: 'primary', options: ['', 'primary'] }
    const extension = await renderControl('accent', def, '', {
      node: { id: 'card1', kind: 'block:cometcms/card', props: { accent: '' }, style: {} },
    })
    expect(extension).toContain('>None</span>')
    const style = await renderControl('background', def, '', { group: 'style' })
    expect(style).toContain('>Default</span>')
  })
})

describe('builder gradient controls', () => {
  it('uses the shared gradient editor for backgrounds and text', async () => {
    for (const name of ['background_gradient', 'text_gradient']) {
      const html = await renderControl(name, { type: 'gradient', options: ['primary', 'accent'], default: [] }, { type: 'linear', from: 'primary', to: 'accent', angle: 135 }, { group: 'style' })
      expect(html).toContain(`id="builder-icon1-style-${name}"`)
      expect(html).toContain('linear-gradient(135deg,')
      expect(html).toContain('Start color')
      expect(html).toContain('End color')
    }
  })
})

describe('builder hover effects', () => {
  it('describes movement for hover and entrances for other triggers', async () => {
    const def = { type: 'enum', options: ['none', 'fade', 'fade-left', 'zoom-in', 'blur'] }
    const hover = await renderControl('animation', def, 'fade-left', {
      group: 'style', styleState: 'hover', node: { id: 'text1', kind: 'text', style: { animation: 'fade-up', animation_trigger: 'load' } },
    })
    expect(hover).toContain('Hover effect')
    expect(hover).toContain('Move left')
    expect(hover).toContain('Dim slightly')
    expect(hover).toContain('Blur slightly')
    expect(hover).not.toContain('Fade in from the right')

    const scroll = await renderControl('animation', def, 'fade-left', { group: 'style' })
    expect(scroll).toContain('Entrance')
    expect(scroll).toContain('Fade in from the right')
  })
})

describe('builder link destinations', () => {
  it('uses the shared destination selector and dynamic content picker for fields and galleries', async () => {
    for (const kind of ['field', 'gallery']) {
      const html = await renderControl('link', { type: 'link', binding: 'link', default: { type: 'none' } }, { type: 'current' }, {
        node: { id: 'linked', kind, props: {}, style: {} },
      })
      expect(html).toContain('Insert dynamic content')
      expect(html).toContain('This entry')
      expect(html).toContain('Open in a new tab')
      expect(html).not.toContain('Link to the entry')
      expect(html).not.toContain('role="switch"')
    }
  })
})

describe('builder search targets', () => {
  const types = [
    { name: 'blog', label: 'Blog', fields: { slug: { type: 'slug' }, body: { type: 'markdown', label: 'Body' }, cover: { type: 'media' } } },
    { name: 'docs', label: 'Docs', fields: { body: { type: 'html', label: 'Content' }, summary: { type: 'textarea', label: 'Summary' } } },
    { name: 'drafts', label: 'Drafts', fields: {} },
    { name: 'notes', label: 'Notes', visibility: 'private', fields: {} },
  ]

  async function renderSearchControl(name, type, value, props = {}) {
    const app = createSSRApp({ setup() {
      const builder = createSiteBuilder({ t })
      builder.types.value = types
      builder.settings.value = { routes: { blog: { enabled: true }, docs: { enabled: true }, notes: { enabled: true } } }
      return () => h(BuilderControl, { node: { id: 'search1', kind: 'search', props, style: {} }, name, def: { type, default: [] }, value })
    } })
    app.use(createPinia())
    return renderToString(app)
  }

  it('offers public content types with pages and says that none checked searches all of them', async () => {
    const html = await renderSearchControl('collections', 'content_types', [])
    expect(html).toContain('Search in')
    expect(html).toContain('All page types')
    expect(html).toContain('Blog')
    expect(html).toContain('Docs')
    expect(html).not.toContain('Drafts')
    expect(html).not.toContain('Notes')
    expect(html.match(/type="checkbox"/g)).toHaveLength(2)
  })

  it('keeps a chosen type without pages visible so it can be unchecked', async () => {
    const html = await renderSearchControl('collections', 'content_types', ['drafts'])
    expect(html).toContain('Drafts')
    expect(html).toContain('no pages')
    expect(html).not.toContain('All page types')
  })

  it('lists the title and text fields of the searched types under the types that have them', async () => {
    const all = await renderSearchControl('fields', 'search_fields', [])
    expect(all).toContain('All text fields')
    expect(all).toContain('Title')
    expect(all).toContain('Body')
    expect(all).toContain('Summary')
    // Fields every type has come first; Summary sits under Docs, its only type.
    expect(all).toMatch(/<h5[^>]*>\s*All types\s*<\/h5>[\s\S]*Title[\s\S]*Body[\s\S]*<h5[^>]*>\s*Docs\s*<\/h5>[\s\S]*Summary/)
    expect(all).not.toContain('cover')
    expect(all).not.toContain('>slug<')

    const blogOnly = await renderSearchControl('fields', 'search_fields', ['body'], { collections: ['blog'] })
    expect(blogOnly).not.toContain('Summary')
    expect(blogOnly).not.toContain('All text fields')
    expect(blogOnly).toMatch(/checked[^>]*>\s*<span[^>]*>Body</)
  })
})

describe('builder lists of several types', () => {
  const types = [
    { name: 'blog', label: 'Blog', fields: { category: { type: 'select', label: 'Category', options: { news: 'News' } }, rating: { type: 'number', label: 'Rating' } } },
    { name: 'events', label: 'Events', fields: { category: { type: 'select', label: 'Category', options: { talk: 'Talk' } }, starts: { type: 'date', label: 'Starts' } } },
  ]

  async function renderListControl(name, def, value, props = {}) {
    const app = createSSRApp({ setup() {
      const builder = createSiteBuilder({ t })
      builder.types.value = types
      return () => h(BuilderControl, { node: { id: 'list1', kind: 'collection', props: { list_source: 'types', collections: ['blog', 'events'], ...props }, style: {} }, name, def, value })
    } })
    app.use(createPinia())
    return renderToString(app)
  }

  it('offers visitor sorting by the fields of every listed type, under sticky type headings', async () => {
    const html = await renderListControl('sort_choices', { type: 'sort_fields', default: [] }, ['starts', 'gone'])
    expect(html).toMatch(/<h5[^>]*>\s*All types\s*<\/h5>[\s\S]*Title[\s\S]*Category[\s\S]*<h5[^>]*>\s*Blog\s*<\/h5>[\s\S]*Rating[\s\S]*<h5[^>]*>\s*Events\s*<\/h5>[\s\S]*Starts/)
    expect(html).toMatch(/<h5[^>]*>\s*No longer available\s*<\/h5>[\s\S]*gone/)
    expect(html).toContain('sticky')
  })

  it('groups the fields a filter can be added for, and keeps the list\'s own order to dates', async () => {
    const filters = await renderListControl('filters', { type: 'list_filters', default: [] }, [])
    expect(filters).toMatch(/<optgroup label="All types"[^>]*>[\s\S]*Category[\s\S]*<optgroup label="Blog"[^>]*>[\s\S]*Rating[\s\S]*<optgroup label="Events"[^>]*>[\s\S]*Starts/)
    const order = await renderListControl('sort_field', { type: 'sort_field', default: 'published_at' }, 'published_at')
    expect(order).not.toContain('Rating')
    expect(order).not.toContain('Category')
  })
})

describe('builder section minimum height', () => {
  const def = { type: 'enum', options: ['', 'quarter', 'half', 'three-quarter', 'screen'], default: '' }

  it('shows five labeled radio presets and preserves the saved selection', async () => {
    for (const value of def.options) {
      const html = await renderControl('min_height', def, value, {
        node: { id: 'section1', kind: 'section', props: {}, style: {} },
      })
      expect(html.match(/type="radio"/g)).toHaveLength(5)
      expect(html.match(/ checked/g)).toHaveLength(1)
      expect(html).toContain(`value="${value}" checked`)
      for (const label of ['Auto', '25%', '50%', '75%', '100%']) expect(html).toContain(`>${label}</span>`)
      expect(html).toContain('Percentage of screen height. Content can make the section taller.')
      expect(html).not.toContain('<select')
    }
  })
})

describe('builder list filters', () => {
  const blog = { name: 'blog', label: 'Blog', fields: {
    category: { type: 'select', label: 'Category' },
    featured: { type: 'boolean', label: 'Featured' },
    price: { type: 'number', label: 'Price' },
    cover: { type: 'media', label: 'Cover' },
  } }

  async function renderListControl(name, type, value) {
    const app = createSSRApp({ setup() {
      const builder = createSiteBuilder({ t })
      builder.types.value = [blog]
      return () => h(BuilderControl, { node: { id: 'list1', kind: 'collection', props: { type: 'blog' }, style: {} }, name, def: { type, default: [] }, value })
    } })
    app.use(createPinia())
    return renderToString(app)
  }

  it('shows each filter with the control its field type gives visitors and offers the remaining fields', async () => {
    const html = await renderListControl('filters', 'list_filters', [{ field: 'category', label: '', multiple: true }, { field: 'gone' }])
    expect(html).toContain('Filters for visitors')
    expect(html).toContain('Category')
    expect(html).toContain('Choices')
    expect(html).toContain('Several')
    expect(html).toContain('field missing')
    expect(html).toContain('Featured · Switch')
    expect(html).toContain('Price · From–to')
    expect(html).toContain('Title · Search')
    expect(html).not.toContain('Cover')
  })

  it('lists sortable fields and says when visitors get no sorting', async () => {
    const html = await renderListControl('sort_choices', 'sort_fields', [])
    expect(html).toContain('None: the list keeps its order')
    expect(html).toContain('Published')
    expect(html).toContain('Price')
    expect(html).not.toContain('Cover')
  })
})

describe('builder video controls', () => {
  it('explains supported links, provider UI limits and muted autoplay', async () => {
    for (const [name, type, value, expected] of [
      ['video_url', 'string', '', 'HTTPS MP4/WebM/OGV'],
      ['controls', 'bool', true, 'Provider branding may remain visible'],
      ['autoplay', 'bool', false, 'Automatically mutes audio'],
    ]) {
      const html = await renderControl(name, { type, default: value }, value, {
        node: { id: 'video1', kind: 'video', props: {}, style: {} },
      })
      expect(html).toContain(expected)
      expect(html).not.toContain('site.video.')
    }
  })
})

describe('builder shadow controls', () => {
  it('routes box and text shadows to the compact editor with palette colors', async () => {
    for (const name of ['shadow', 'text_shadow']) {
      const html = await renderControl(name, { type: 'shadow', text: name === 'text_shadow', options: ['primary', 'accent'], default: '' }, 'hard', { group: 'style' })
      expect(html).toContain(`id="builder-icon1-style-${name}"`)
      // No saved theme here, so the box shadow's preset is unknown.
      expect(html).toContain(name === 'shadow' ? 'hard (missing)' : '>Block</option>')
      expect(html).toContain('Customize')
      expect(html).toContain(name === 'shadow' ? 'box-shadow:6px 6px 0px 0px' : 'text-shadow:3px 3px 0px')
    }
  })
})

describe('builder bound controls', () => {
  it('render one editor, not a second plain input below it', async () => {
    const text = await renderControl('text', { type: 'string', max: 300, default: '', binding: 'text' }, 'Portfolio', { node: { id: 'h1', kind: 'heading', props: { text: 'Portfolio' } } })
    expect(text.match(/Portfolio/g)?.length ?? 0).toBeLessThanOrEqual(1)
    expect(text).not.toContain('id="builder-h1-props-text"')
    const link = await renderControl('link', { type: 'link', default: { type: 'none' }, binding: 'link' }, { type: 'none' }, { node: { id: 'h1', kind: 'heading', props: {} } })
    expect(link).not.toContain('id="builder-h1-props-link"')
  })
})

describe('block integer sliders', () => {
  it('renders labeled sliders with an exact numeric value, including zero and small ranges', async () => {
    for (const value of [0, 2]) {
      const html = await renderControl('zoom', { type: 'int', label: 'Zoom level', control: 'slider', min: 0, max: 3, default: 1 }, value, {
        node: { id: 'map1', kind: 'block:cometcms/maps', props: {}, style: {} },
      })
      expect(html).toContain('for="builder-map1-props-zoom"')
      expect(html).toContain('id="builder-map1-props-zoom" type="range"')
      expect(html).toContain('type="number"')
      expect(html).toContain('aria-label="Zoom level"')
      expect(html.match(new RegExp(`value="${value}"`, 'g'))).toHaveLength(2)
      expect(html).toContain('min="0" max="3" step="1"')
    }
  })
})
