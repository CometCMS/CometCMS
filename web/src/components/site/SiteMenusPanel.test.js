import { createSSRApp, h, ref } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SiteMenusPanel from './SiteMenusPanel.vue'
import { api } from '../../api/index.js'
import { useSiteStore } from '../../stores/site.js'

vi.mock('../../api/index.js', () => ({
  api: { content: { get: vi.fn(), list: vi.fn() } },
  getActiveWorkspace: () => 'menus-test',
}))
vi.mock('../../composables/useLanguages.js', () => ({
  useLanguages: () => ({ locales: ref(['en']), defaultLocale: ref('en'), activeLocale: ref('en') }),
  hasLanguageSwitcher: () => false,
  updateWorkspaceLanguages: vi.fn(),
}))
vi.mock('../../composables/useDialogFocus.js', () => ({ useDialogFocus: vi.fn() }))
vi.mock('@iconify/vue', () => ({ Icon: { render: () => null }, addIcon: vi.fn() }))

beforeEach(() => {
  vi.clearAllMocks()
  api.content.get.mockResolvedValue({ data: { uid: 'about', title: 'About' } })
})

async function renderMenus(link, defaultLocale = 'en', label = 'Home') {
  const pinia = createPinia()
  const site = useSiteStore(pinia)
  const item = { id: 'home', label, link, children: [] }
  site.data = {
    workspace: 'menus-test', types: [],
    settings: { default_locale: defaultLocale, locales: [defaultLocale] },
    theme: {}, menus: [{ name: 'main', label: 'Main', items: [item] }],
  }
  site.resetDraft()
  const before = JSON.stringify(site.draft.menus)
  const app = createSSRApp({ render: () => h(SiteMenusPanel) })
  app.use(pinia)
  const html = await renderToString(app)
  expect(JSON.stringify(site.draft.menus)).toBe(before)
  return html
}

describe('menus page destinations', () => {
  it('uses the destination default in the inspector heading without filling in display text', async () => {
    const html = await renderMenus({ type: 'url', url: '/about' }, 'en', '')
    expect(html).toMatch(/<h2[^>]*>\/about<\/h2>/)
    expect(html).toMatch(/<input[^>]*id="menu-item-home"[^>]*value=""/)
  })

  it('keeps custom display text ahead of the destination default', async () => {
    const html = await renderMenus({ type: 'url', url: '/about' }, 'en', 'Our team')
    expect(html).toMatch(/<h2[^>]*>Our team<\/h2>/)
  })

  it('renders the inspector before workspace languages finish loading', async () => {
    // The workspace is German, but LocalizedInput initially uses English.
    const html = await renderMenus({ type: 'localized', values: { de: { type: 'none' } } }, 'de')
    expect(html).toContain('role="tree"')
    expect(html).toContain('Choose another destination')
    expect(html).toContain('Choose a destination.')
    expect(api.content.get).not.toHaveBeenCalled()
  })

  it('keeps the tree and inspector usable when the default locale has no destination', async () => {
    const html = await renderMenus({ type: 'localized', values: { de: { type: 'none' } } })
    expect(html).toContain('data-menu-row="home"')
    expect(html).toContain('Choose a destination.')
    expect(html).toContain('Choose another destination')
    expect(api.content.get).not.toHaveBeenCalled()
  })

  it('preserves existing destinations and their options', async () => {
    const html = await renderMenus({ type: 'url', url: '/about', new_tab: true })
    expect(html).toContain('/about')
    expect(html).toMatch(/<input type="checkbox"[^>]* checked>/)
    expect(html).toContain('Choose another destination')
  })
})
