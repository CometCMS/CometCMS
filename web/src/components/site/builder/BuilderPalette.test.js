import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createPinia } from 'pinia'
import { RouterLink, createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'
import BuilderPalette from './BuilderPalette.vue'
import { createSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { t } from '../../../i18n/index.js'

vi.mock('../../ConfirmModal.vue', () => ({ default: { render: () => null } }))
vi.mock('../ComponentNameModal.vue', () => ({ default: { render: () => null } }))
vi.mock('@iconify/vue', () => ({ Icon: {
  props: ['icon'], setup: (props) => () => h('span', { 'data-icon': props.icon }),
} }))

async function renderPanel() {
  const app = createSSRApp({ setup() {
    const builder = createSiteBuilder({ t })
    builder.spec.value = { elements: ['text', 'html', 'accordion', 'icon', 'component'], kinds: {} }
    builder.components.value = [
      { id: 'shared', name: 'Shared promo', icon: 'mdi:star-outline', root: 'section', type: '' },
      { id: 'typed', name: 'Article teaser', root: 'row', type: 'posts' },
      { id: 'self', name: 'Current component', root: 'section', type: '' },
    ]
    builder.componentId.value = 'self'
    builder.currentAvailable.value = false
    return () => h(BuilderPalette, { withFields: false })
  } })
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
  await router.push('/page-builder/builder/region/header')
  app.use(router)
  app.component('router-link', RouterLink)
  app.use(createPinia())
  return renderToString(app)
}

describe('components in the Add panel', () => {
  it('lists saved components and disables only self-reference, whatever the type', async () => {
    const html = await renderPanel()
    expect(html).toContain('Shared promo')
    expect(html).toContain('Article teaser')
    expect(html).toContain('Current component')
    expect(html.match(/draggable="false"/g)).toHaveLength(1)
    expect(html).toContain(t('site.component.unavailable'))
    expect(html).toContain('Manage components')
    expect(html).toContain('data-icon="mdi:star-outline"')
    expect(html).toContain('data-icon="mdi:puzzle-outline"')
  })

  it('keeps ordinary elements available without a generic component element or management buttons', async () => {
    const html = await renderPanel()
    expect(html).toContain('Components')
    expect(html).not.toContain('>Component<')
    expect(html).not.toContain('Rename:')
    expect(html).not.toContain('Delete:')
    expect(html).toContain('Text')
    expect(html).toContain('HTML block')
    expect(html).toContain('data-icon="mdi:code-tags"')
    expect(html).toContain('Accordion')
    expect(html).toContain('Icons')
  })
})
