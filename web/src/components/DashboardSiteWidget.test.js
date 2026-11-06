import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
import DashboardSiteWidget from './DashboardSiteWidget.vue'

const status = {
  can_edit_layouts: true,
  enabled: true,
  types: { pages: { pages_enabled: true } },
  homepage_editor: null,
}

async function renderWidget(value) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }],
  })
  await router.push('/dashboard')
  const app = createSSRApp(DashboardSiteWidget, { status: value })
  app.use(router)
  return renderToString(app)
}

describe('dashboard website widget', () => {
  it('shows homepage setup guidance when pages are enabled without a homepage', async () => {
    const html = await renderWidget(status)
    expect(html).toContain('page-builder-cta')
    expect(html).toContain('Page Builder')
    expect(html).toContain('No homepage is set up yet.')
    expect(html).toContain('href="/website/general#homepage"')
    expect(html).toContain('Choose homepage')
  })

  it.each([
    '/page-builder/builder/layout/landing-page/detail',
    '/page-builder/builder/layout/pages/detail?entry=welcome',
    '/page-builder/builder/layout/events/index',
  ])('opens the homepage editor %s directly', async (path) => {
    const html = await renderWidget({ ...status, homepage_editor: path })
    expect(html).toContain(`href="${path}"`)
    expect(html).toContain('page-builder-cta')
    expect(html).toContain('Open Page Builder')
    expect(html).not.toContain('Choose homepage')
  })

  it('also supports a homepage without a separate enabled type URL', async () => {
    const html = await renderWidget({ ...status, types: { home: { pages_enabled: false } }, homepage_editor: '/page-builder/builder/layout/home/detail' })
    expect(html).toContain('Open Page Builder')
  })

  it('stays hidden for headless workspaces, unavailable status, and users without site management permission', async () => {
    for (const value of [null, { ...status, enabled: false, types: {} }, { ...status, can_edit_layouts: false }]) {
      const html = await renderWidget(value)
      expect(html).not.toContain('page-builder-cta')
      expect(html).not.toContain('Open Page Builder')
      expect(html).not.toContain('Choose homepage')
    }
  })
})
