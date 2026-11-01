import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const mocks = vi.hoisted(() => ({ workspace: 'default' }))
vi.mock('../api/index.js', () => ({ getActiveWorkspace: () => mocks.workspace, api: { fonts: { list: vi.fn() }, workspaces: { update: vi.fn() }, site: { status: vi.fn(), get: vi.fn(), saveSettings: vi.fn(), saveFormSettings: vi.fn(), saveTheme: vi.fn(), saveMenus: vi.fn() } } }))
import { useSiteStore } from './site.js'
import { api } from '../api/index.js'

const deferred = () => {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
const siteData = (workspace) => ({ workspace, settings: { title: workspace, locales: ['en'], default_locale: 'en' }, theme: {}, menus: [] })

describe('workspace loads', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mocks.workspace = 'default'
  })

  it('ignores an old workspace response after the new workspace finishes loading', async () => {
    const old = deferred()
    api.site.get.mockReturnValueOnce(old.promise).mockResolvedValueOnce({ data: siteData('second') })
    const site = useSiteStore()
    const first = site.load()
    mocks.workspace = 'second'
    await site.load()
    site.draft.settings.title = 'Pending second workspace title'
    old.resolve({ data: siteData('default') })
    await first
    expect(site.data.workspace).toBe('second')
    expect(site.draft.settings.title).toBe('Pending second workspace title')
    expect((await site.load()).workspace).toBe('second')
    expect(api.site.get).toHaveBeenCalledTimes(2)
  })

  it('clears old data while switching and shares the new pending request', async () => {
    api.site.get.mockResolvedValueOnce({ data: siteData('default') })
    const site = useSiteStore()
    await site.load()
    const pending = deferred()
    api.site.get.mockReturnValueOnce(pending.promise)
    mocks.workspace = 'second'
    const next = site.load()
    const shared = site.load()
    expect(site.data).toBeNull()
    expect(site.draft).toBeNull()
    expect(site.loading).toBe(true)
    expect(api.site.get).toHaveBeenCalledTimes(2)
    pending.resolve({ data: siteData('second') })
    expect((await next).workspace).toBe('second')
    expect((await shared).workspace).toBe('second')
  })

  it('ignores stale errors without clearing the active loading state or request', async () => {
    const old = deferred(), current = deferred()
    api.site.get.mockReturnValueOnce(old.promise).mockReturnValueOnce(current.promise)
    const site = useSiteStore()
    const first = site.load()
    mocks.workspace = 'second'
    const next = site.load()
    const failed = expect(first).rejects.toThrow('Old workspace failed')
    old.reject(new Error('Old workspace failed'))
    await failed
    expect(site.error).toBe('')
    expect(site.loading).toBe(true)
    const shared = site.load()
    expect(api.site.get).toHaveBeenCalledTimes(2)
    current.resolve({ data: siteData('second') })
    await Promise.all([next, shared])
    expect(site.loading).toBe(false)
  })

  it('invalidates pending requests even when reloading the same workspace', async () => {
    const old = deferred()
    api.site.get.mockReturnValueOnce(old.promise).mockResolvedValueOnce({ data: siteData('replacement') })
    const site = useSiteStore()
    const first = site.load()
    site.invalidate()
    await site.load()
    old.resolve({ data: siteData('old') })
    await first
    expect(site.data.workspace).toBe('replacement')
    expect(site.draft.settings.title).toBe('replacement')
  })
})

describe('website CAPTCHA setup', () => {
  beforeEach(() => { setActivePinia(createPinia()); vi.clearAllMocks() })

  it('saves credential edits with the website and clears the secret from the draft after success', async () => {
    const site = useSiteStore()
    site.data = { ...siteData('default'), form_settings: { providers: { turnstile: { mode: 'server', site_key: '', secret_key: '' } }, revision: '' } }
    site.resetDraft()
    Object.assign(site.draft.form_settings.providers.turnstile, { mode: 'workspace', site_key: 'public', secret_key: 'new-secret' })
    expect(site.dirtyParts).toEqual(['form_settings'])
    const saved = { providers: { turnstile: { mode: 'workspace', site_key: 'public', secret_key: '', secret_configured: true } }, revision: 'saved' }
    api.site.saveFormSettings.mockResolvedValue({ data: saved })
    await site.save({ parts: ['languages', 'settings', 'form_settings'] })
    expect(api.site.saveFormSettings.mock.calls[0][0].providers.turnstile.secret_key).toBe('new-secret')
    expect(site.draft.form_settings).toEqual(saved)
    expect(JSON.stringify(site.data)).not.toContain('new-secret')
    expect(site.dirtyParts).toEqual([])
  })

  it('keeps pending credentials and validation errors when saving fails', async () => {
    const site = useSiteStore()
    site.data = { ...siteData('default'), form_settings: { providers: { recaptcha_v3: { min_score: 0.5, secret_key: '' } }, revision: 'r1' } }
    site.resetDraft()
    site.draft.form_settings.providers.recaptcha_v3.min_score = 2
    const fields = { 'providers.recaptcha_v3.min_score': 'Choose a score from 0 to 1.' }
    api.site.saveFormSettings.mockRejectedValue(Object.assign(new Error('Invalid settings'), { fields }))
    await expect(site.save({ parts: ['form_settings'] })).rejects.toThrow('Invalid settings')
    expect(site.fieldErrors).toEqual(fields)
    expect(site.draft.form_settings.providers.recaptcha_v3.min_score).toBe(2)
    expect(site.data.form_settings.providers.recaptcha_v3.min_score).toBe(0.5)
  })
})

describe('website revision restoration', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('applies a restored theme and preserves pending edits to settings and menus', () => {
    const site = useSiteStore()
    site.data = { settings: { title: 'Saved', locales: ['en'], default_locale: 'en' }, theme: { base_size: 17 }, menus: [] }
    site.resetDraft()
    site.draft.settings.title = 'Pending settings'
    site.draft.menus.push({ name: 'pending-menu' })
    site.draft.theme.base_size = 20
    const restored = { base_size: 15, custom_css: '.old {}' }

    site.applyRestoredTheme(restored)

    expect(site.data.theme).toEqual(restored)
    expect(site.draft.theme).toEqual(restored)
    expect(site.draft.settings.title).toBe('Pending settings')
    expect(site.draft.menus).toEqual([{ name: 'pending-menu' }])
    expect(site.dirtyParts).toEqual(['settings', 'menus'])
    site.draft.theme.base_size = 16
    expect(site.data.theme.base_size).toBe(15)
    expect(restored.base_size).toBe(15)
  })
})


describe('menu saves', () => {
  beforeEach(() => { setActivePinia(createPinia()); vi.clearAllMocks() })

  it('keeps incomplete menu drafts and reports errors before saving other dirty parts', async () => {
    const site = useSiteStore()
    site.data = { settings: { title: 'Saved', locales: ['en'], default_locale: 'en' }, theme: {}, menus: [] }
    site.resetDraft()
    site.draft.settings.title = 'Pending'
    site.draft.menus = [{ name: 'main', items: [{ id: 'bad', label: 'Custom', link: { type: 'url', url: '' }, children: [] }] }]
    await expect(site.save()).rejects.toThrow()
    expect(site.fieldErrors['menus.0.items.0']).toBeTruthy()
    expect(api.site.saveSettings).not.toHaveBeenCalled()
    expect(api.site.saveMenus).not.toHaveBeenCalled()
    expect(site.draft.menus[0].items[0].id).toBe('bad')
    expect(site.saving).toBe(false)
  })

  it('saves nested menus with their IDs and resets the dirty flag after success', async () => {
    const site = useSiteStore()
    site.data = { settings: { locales: ['en'], default_locale: 'en' }, theme: {}, menus: [], menus_revision: 'm1' }
    site.resetDraft()
    const menus = [{ name: 'main', items: [{ id: 'parent', label: 'Group', link: { type: 'none' }, children: [{ id: 'child', label: 'Contact', link: { type: 'anchor', anchor: 'contact' }, children: [] }] }] }]
    site.draft.menus = menus
    api.site.saveMenus.mockResolvedValue({ data: menus, meta: { revision: 'm2' } })
    await site.save()
    expect(api.site.saveMenus).toHaveBeenCalledWith(menus, 'm1')
    expect(site.data.menus_revision).toBe('m2')
    expect(site.isDirty).toBe(false)
    expect(site.draft.menus[0].items[0].children[0].id).toBe('child')
    expect(site.draft.menus).not.toBe(site.data.menus)
  })
})

describe('design edits from the builder', () => {
  beforeEach(() => { setActivePinia(createPinia()); vi.clearAllMocks() })

  it('saves only the requested parts and keeps other pending edits', async () => {
    const site = useSiteStore()
    site.data = { settings: { title: 'Saved', locales: ['en'], default_locale: 'en' }, theme: { base_size: 17 }, menus: [] }
    site.resetDraft()
    site.draft.settings.title = 'Pending'
    site.draft.theme.base_size = 20
    api.site.saveTheme.mockResolvedValue({ data: { base_size: 20 } })
    await site.save({ parts: ['theme'] })
    expect(api.site.saveTheme).toHaveBeenCalledWith({ base_size: 20 })
    expect(api.site.saveSettings).not.toHaveBeenCalled()
    expect(site.dirtyParts).toEqual(['settings'])
    expect(site.draft.settings.title).toBe('Pending')
  })

  it('saves workspace languages before the settings validated against them', async () => {
    const site = useSiteStore()
    site.data = { workspace_label: 'Main', settings: { title: 'Saved', locales: ['en'], default_locale: 'en' }, theme: {}, menus: [] }
    site.resetDraft()
    site.draft.languages.locales.push('de')
    site.draft.settings.title = 'Pending'
    const order = []
    api.workspaces.update.mockImplementation(async () => { order.push('languages'); return { data: { languages: { locales: ['en', 'de'], default_locale: 'en' } } } })
    api.site.saveSettings.mockImplementation(async (settings) => { order.push('settings'); return { data: settings } })
    await site.save({ parts: ['languages', 'settings'] })
    expect(order).toEqual(['languages', 'settings'])
    expect(api.workspaces.update).toHaveBeenCalledWith('default', { label: 'Main', languages: { locales: ['en', 'de'], default_locale: 'en' } })
    expect(api.site.saveSettings.mock.calls[0][0].locales).toEqual(['en', 'de'])
    expect(site.dirtyParts).toEqual([])
  })

  it('discards pending design edits only', () => {
    const site = useSiteStore()
    site.data = { settings: { title: 'Saved', locales: ['en'], default_locale: 'en' }, theme: { base_size: 17 }, menus: [] }
    site.resetDraft()
    site.draft.settings.title = 'Pending'
    site.draft.theme.base_size = 20
    site.discardTheme()
    expect(site.draft.theme).toEqual({ base_size: 17 })
    expect(site.draft.theme).not.toBe(site.data.theme)
    expect(site.dirtyParts).toEqual(['settings'])
  })
})


describe('font metadata refresh', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mocks.workspace = 'default'
  })
  it('preserves unsaved settings, theme and menu drafts', async () => {
    const site = useSiteStore()
    api.site.get.mockResolvedValue({ data: { ...siteData('default'), theme: { fonts: { body: 'system' } }, menus: [] } })
    await site.load()
    site.draft.theme.fonts.body = 'fontsource-lora'
    site.draft.settings.title = 'Unsaved'
    api.fonts.list.mockResolvedValue({ data: { fonts: ['system', 'fontsource-lora'], font_version: 'new' } })
    await site.refreshFonts()
    expect(site.data.font_version).toBe('new')
    expect(site.draft.theme.fonts.body).toBe('fontsource-lora')
    expect(site.draft.settings.title).toBe('Unsaved')
    expect(site.isDirty).toBe(true)
  })

  it('ignores a font response from an earlier workspace', async () => {
    const site = useSiteStore()
    api.site.get.mockResolvedValue({ data: siteData('default') })
    await site.load()
    const old = deferred()
    api.fonts.list.mockReturnValue(old.promise)
    const pending = site.refreshFonts()
    mocks.workspace = 'second'
    api.site.get.mockResolvedValue({ data: { ...siteData('second'), font_version: 'second' } })
    await site.load()
    old.resolve({ data: { fonts: ['old'], font_version: 'old' } })
    await pending
    expect(site.data.font_version).toBe('second')
    expect(site.data.workspace).toBe('second')
  })
})

describe('routing settings saves', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mocks.workspace = 'default'
  })

  it.each([false, true])('keeps the draft revision current after saving a route (pending settings: %s)', async (pending) => {
    const settings = { title: 'Saved', revision: 'first', updated_at: 'before', enabled: false, locales: ['en', 'de'], default_locale: 'en', routes: { pages: { enabled: false, pattern: '/pages/{slug}' } } }
    const initial = { ...siteData('default'), settings }
    api.site.get.mockResolvedValue({ data: initial })
    const site = useSiteStore()
    await site.load()
    if (pending) site.draft.settings.title = 'Pending title'
    const saved = { ...settings, revision: 'second', updated_at: 'after', enabled: true, routes: { pages: { ...settings.routes.pages, enabled: true } } }
    api.site.saveSettings.mockResolvedValue({ data: saved })
    api.site.get.mockResolvedValue({ data: { ...initial, settings: saved } })

    await site.saveRoute('pages', { enabled: true })

    expect(site.draft.settings.revision).toBe('second')
    expect(site.draft.settings.updated_at).toBe('after')
    expect(site.draft.settings.enabled).toBe(true)
    expect(site.draft.settings.title).toBe(pending ? 'Pending title' : 'Saved')
    expect(site.isDirty).toBe(pending)
    // The next settings save must use the revision returned by the route save.
    site.draft.settings.description = 'An edit from builder settings'
    api.site.saveSettings.mockImplementation(async (input) => {
      expect(input.revision).toBe('second')
      return { data: { ...input, revision: 'third' } }
    })
    await site.save({ parts: ['settings'] })
    expect(site.draft.settings.revision).toBe('third')
    expect(site.isDirty).toBe(false)
  })
})

describe('homepage preview status', () => {
  it('ignores a response for a workspace that is no longer active and clears links on invalidation', async () => {
    setActivePinia(createPinia())
    mocks.workspace = 'default'
    const site = useSiteStore()
    api.site.status.mockResolvedValueOnce({ data: { homepage_preview: '/admin/site-preview?workspace=default' } })
    await site.loadStatus()
    expect(site.homepagePreview).toBe('/admin/site-preview?workspace=default')
    const old = deferred()
    api.site.status.mockReturnValueOnce(old.promise)
    const request = site.loadStatus()
    mocks.workspace = 'second'
    site.invalidate()
    old.resolve({ data: { homepage_preview: '/wrong-workspace' } })
    await request
    expect(site.homepagePreview).toBeNull()
    api.site.status.mockResolvedValueOnce({ data: {} })
    await site.loadStatus()
    expect(site.homepagePreview).toBeNull()
  })
})


describe('extension content refresh', () => {
  beforeEach(() => { setActivePinia(createPinia()); vi.clearAllMocks(); mocks.workspace = 'default' })

  it('makes seeded types and presets available while keeping unsaved designer edits', async () => {
    api.site.get.mockResolvedValueOnce({ data: siteData('default') })
    const site = useSiteStore()
    await site.load()
    site.draft.theme = { colors: { primary: '#123456' } }
    api.site.get.mockResolvedValueOnce({ data: { ...siteData('default'), types: [{ name: 'project', label: 'Projects' }], theme_presets: [{ id: 'portfolio-dusk', name: 'Portfolio dusk' }], templates: { project: { detail: [{ id: 'default' }] } } } })
    await site.refreshExtensions()
    expect(site.data.types[0].name).toBe('project')
    expect(site.data.theme_presets[0].name).toBe('Portfolio dusk')
    expect(site.draft.templates.project.detail[0].id).toBe('default')
    expect(site.draft.theme.colors.primary).toBe('#123456')
    expect(site.dirtyParts).toEqual(['theme'])
  })

  it('ignores an extension refresh that returns after switching workspace', async () => {
    api.site.get.mockResolvedValueOnce({ data: siteData('default') })
    const site = useSiteStore()
    await site.load()
    const pending = deferred()
    api.site.get.mockReturnValueOnce(pending.promise).mockResolvedValueOnce({ data: siteData('second') })
    const refresh = site.refreshExtensions()
    mocks.workspace = 'second'
    await site.load()
    pending.resolve({ data: siteData('default') })
    await refresh
    expect(site.data.workspace).toBe('second')
  })
})
