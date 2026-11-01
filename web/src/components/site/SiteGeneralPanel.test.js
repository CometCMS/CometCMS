import { afterEach, describe, expect, it, vi } from 'vitest'
import * as Vue from 'vue'
import { createRenderer, nextTick, ssrContextKey } from 'vue'
import { readFileSync } from 'node:fs'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'
import { createPinia } from 'pinia'
import SiteGeneralPanel from './SiteGeneralPanel.vue'
import { api } from '../../api/index.js'
import { useSiteStore } from '../../stores/site.js'

vi.mock('../../api/index.js', () => ({
  api: { content: { list: vi.fn() }, site: { saveSettings: vi.fn() } },
  getActiveWorkspace: () => 'test',
}))
vi.mock('../../stores/auth.js', () => ({ useAuthStore: () => ({ can: () => false }) }))
vi.mock('../LocalizedInput.vue', () => ({ default: { render: () => null } }))
vi.mock('./MediaSelect.vue', () => ({ default: { render: () => null } }))
vi.mock('../SearchableSelect.vue', () => ({ default: { render: () => null } }))

const node = (tag = '', text = '') => ({ tag, text, props: {}, children: [], parent: null, addEventListener() {}, removeEventListener() {}, getRootNode: () => ({ activeElement: null }), get options() { return this.children.filter((child) => child.tag === 'option') }, get value() { return this.props.value }, set value(value) { this.props.value = value } })
const renderer = createRenderer({
  createElement: (tag) => node(tag), createText: (text) => node('', text), createComment: () => node(),
  setText: (el, text) => { el.text = text },
  setElementText: (el, text) => { el.text = text; el.children = [] },
  patchProp: (el, key, prev, value) => { el.props[key] = value },
  insert(child, parent, anchor = null) {
    if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1)
    child.parent = parent
    const index = anchor ? parent.children.indexOf(anchor) : -1
    parent.children.splice(index < 0 ? parent.children.length : index, 0, child)
  },
  remove(child) { child.parent?.children.splice(child.parent.children.indexOf(child), 1); child.parent = null },
  parentNode: (child) => child.parent,
  nextSibling: (child) => child.parent?.children[child.parent.children.indexOf(child) + 1] ?? null,
})
// Vitest compiles SFCs for SSR in Node. Compile this template for the custom
// renderer so the real controls and their event handlers can be exercised.
const { descriptor } = parse(readFileSync(new URL('./SiteGeneralPanel.vue', import.meta.url), 'utf8'))
const { bindings } = compileScript(descriptor, { id: 'general-test' })
const { code } = compileTemplate({ source: descriptor.template.content, filename: 'SiteGeneralPanel.vue', id: 'general-test', compilerOptions: { bindingMetadata: bindings, mode: 'function' } })
const render = new Function('Vue', code)(Vue)
let app
const find = (el, predicate) => predicate(el) ? el : el.children.map((child) => find(child, predicate)).find(Boolean)
afterEach(() => { app?.unmount(); vi.clearAllMocks() })

describe('homepage selection', () => {
  it('keeps a selected page matched to its option after saving and reopening', async () => {
    const pinia = createPinia()
    const site = useSiteStore(pinia)
    const settings = { homepage: { type: '', entry: '' }, locales: ['en'], default_locale: 'en', domains: [] }
    site.data = { settings, types: [{ name: 'pages', label: 'Pages' }], theme: {}, menus: [] }
    site.resetDraft()
    api.content.list.mockResolvedValue({ data: [{ uid: 'PageUid001', slug: 'welcome', id: 'welcome', title: 'Welcome' }] })
    api.site.saveSettings.mockImplementation(async (input) => ({ data: {
      ...input, homepage: { type: 'pages', entry: 'PageUid001' },
    } }))
    const mount = () => {
      const root = node()
      app = renderer.createApp({ ...SiteGeneralPanel, render })
      app.provide(ssrContextKey, {})
      app.use(pinia).mount(root)
      return root
    }
    const root = mount()
    const type = find(root, (el) => el.props.id === 'site-home-type')
    type.props['onUpdate:modelValue']('pages')
    type.props.onChange()
    await vi.waitFor(() => expect(api.content.list).toHaveBeenCalled())
    await nextTick()
    const entry = find(root, (el) => el.props.id === 'site-home-entry')
    const option = entry.children.find((el) => el.tag === 'option' && el.text === 'Welcome')
    expect(option.props.value).toBe('PageUid001')
    entry.props['onUpdate:modelValue'](option.props.value)
    await site.save({ parts: ['settings'] })
    await nextTick()
    expect(api.site.saveSettings.mock.calls[0][0].homepage.entry).toBe('PageUid001')
    expect(site.draft.settings.homepage.entry).toBe(option.props.value)
    expect(site.isDirty).toBe(false)
    app.unmount()
    const reopened = mount()
    await vi.waitFor(() => {
      const savedOption = find(reopened, (el) => el.tag === 'option' && el.props.value === site.draft.settings.homepage.entry)
      expect(savedOption?.text).toBe('Welcome')
    })
  })
})
