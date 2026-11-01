import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import GroupedList from './GroupedList.vue'
import { createSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { t } from '../../../i18n/index.js'

vi.mock('@iconify/vue', () => ({ Icon: { render: () => null } }))

const render = (component, props, slots) => renderToString(createSSRApp({ setup() {
  createSiteBuilder({ t })
  return () => h(component, props, slots)
} }))
const headings = (html) => [...html.matchAll(/<h5[^>]*>\s*([^<]*?)\s*<\/h5>/g)].map((match) => match[1])

describe('grouped list', () => {
  it('heads each non-empty group with a sticky label', async () => {
    const html = await render(GroupedList, { groups: [
      { key: 'shared', label: 'All types', items: [{ key: 'title' }] },
      { key: 'posts', label: 'Posts', items: [] },
      { key: 'pages', label: 'Pages', items: [{ key: 'intro' }] },
    ] }, { default: ({ item }) => h('button', item.key) })
    expect(headings(html)).toEqual(['All types', 'Pages'])
    expect(html).toContain('sticky')
    expect(html).toContain('<button>intro</button>')
  })

  it('shows a lone group without a heading, and the empty slot without items', async () => {
    const one = await render(GroupedList, { groups: [{ key: 'all', label: 'Posts', items: [{ key: 'a' }] }] }, { default: ({ item }) => h('i', item.key) })
    expect(headings(one)).toEqual([])
    const none = await render(GroupedList, { groups: [{ key: 'all', label: '', items: [] }] }, { empty: () => h('p', 'Nothing') })
    expect(none).toContain('<p>Nothing</p>')
  })
})
