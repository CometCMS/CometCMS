import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('../i18n/index.js', () => ({ t: (key) => key }))

afterEach(() => {
  vi.unstubAllGlobals()
  vi.resetModules()
})

describe('entry preview URLs', () => {
  it.each(['', '/cms'])('keeps the admin prefix under installation %j', async (base) => {
    vi.stubGlobal('document', {
      querySelector: (selector) => selector === 'meta[name="comet-base"]' ? { content: base } : null,
    })
    const { api, setActiveWorkspace } = await import('./index.js')
    setActiveWorkspace('verkehrsinitiative-at')

    expect(api.site.entryPreviewUrl('startseite', 'startseite', 'de')).toBe(
      `${base}/admin/site-preview/startseite/startseite?workspace=verkehrsinitiative-at&locale=de`,
    )
    expect(api.site.indexPreviewUrl('blog', 'de')).toBe(`${base}/admin/site-preview/blog?workspace=verkehrsinitiative-at&locale=de`)
    expect(api.site.homePreviewUrl()).toBe(`${base}/admin/site-preview?workspace=verkehrsinitiative-at`)
  })
})

describe('block package requests', () => {
  it('deletes a package from the active workspace', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, headers: new Headers(), json: async () => ({ data: { name: 'cometcms/essentials' } }) })
    vi.stubGlobal('fetch', fetch)
    const { api, setActiveWorkspace } = await import('./index.js')
    setActiveWorkspace('my-website')
    await api.blocks.delete('cometcms/essentials')
    const [url, options] = fetch.mock.calls[0]
    expect(url).toContain('/site/blocks/cometcms/essentials')
    expect(options.method).toBe('DELETE')
    expect(options.headers['X-Comet-Workspace']).toBe('my-website')
  })

  it('uploads one ZIP with the active workspace and exposes validation details on failure', async () => {
    const sent = []
    class FakeXhr {
      constructor() { this.headers = {}; this.listeners = {}; this.upload = { addEventListener: () => {} } }
      open(method, url) { this.method = method; this.url = url }
      setRequestHeader(name, value) { this.headers[name] = value }
      getResponseHeader() { return null }
      addEventListener(name, callback) { this.listeners[name] = callback }
      send(body) {
        sent.push(this)
        this.body = body
        this.status = 422
        this.responseText = JSON.stringify({ error: { code: 'invalid_block_package', message: 'Invalid', details: [{ file: 'blocks/faq/template.html', line: 3, message: 'Unknown prop' }] } })
        this.listeners.load()
      }
    }
    vi.stubGlobal('XMLHttpRequest', FakeXhr)
    const { api, setActiveWorkspace } = await import('./index.js')
    setActiveWorkspace('my-website')
    const error = await api.blocks.install(new File(['zip'], 'comet-essentials.zip'), { acceptChanges: true, allowScripts: true }).catch((err) => err)
    expect(sent[0].method).toBe('POST')
    expect(sent[0].url).toContain('/site/blocks')
    expect(sent[0].headers['X-Comet-Workspace']).toBe('my-website')
    expect(sent[0].body.get('package').name).toBe('comet-essentials.zip')
    expect(sent[0].body.get('accept_changes')).toBe('1')
    expect(sent[0].body.get('allow_scripts')).toBe('1')
    expect(error.code).toBe('invalid_block_package')
    expect(error.details).toEqual([{ file: 'blocks/faq/template.html', line: 3, message: 'Unknown prop' }])
  })
})

describe('new workspace font installation requests', () => {
  it('targets the new workspace without changing the workspace open in the admin', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, headers: new Headers(), json: async () => ({ data: {} }) })
    vi.stubGlobal('fetch', fetch)
    const { api, getActiveWorkspace, setActiveWorkspace } = await import('./index.js')
    setActiveWorkspace('current-website')
    await api.site.get('new-website')
    await api.fonts.install({ id: 'inter' }, 'new-website')
    await api.fonts.step('job', 0, 'new-website')
    await api.site.saveTheme({ fonts: {} }, 'new-website')
    expect(fetch).toHaveBeenCalledTimes(4)
    for (const [, options] of fetch.mock.calls) {
      expect(options.headers['X-Comet-Workspace']).toBe('new-website')
    }
    expect(getActiveWorkspace()).toBe('current-website')
  })
})
