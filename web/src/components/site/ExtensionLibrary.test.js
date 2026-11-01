import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as Vue from 'vue'
import { createRenderer, nextTick, ssrContextKey } from 'vue'
import { readFileSync } from 'node:fs'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'
import ExtensionLibrary from './ExtensionLibrary.vue'
import { api } from '../../api/index.js'

vi.mock('../../api/index.js', () => ({ getActiveWorkspace: () => 'default', api: { extensions: { list: vi.fn(), setEnabled: vi.fn(), install: vi.fn(), get: vi.fn(), delete: vi.fn() } } }))
vi.mock('../../stores/site.js', () => ({ useSiteStore: () => ({ refreshExtensions: vi.fn().mockResolvedValue() }) }))
vi.mock('../../stores/contentTypes.js', () => ({ useContentTypesStore: () => ({ invalidate: vi.fn(), fetch: vi.fn().mockResolvedValue() }) }))
vi.mock('@iconify/vue', () => ({ Icon: { render: () => null } }))
vi.mock('./builder/KindIcon.vue', () => ({ default: { render: () => null } }))
vi.mock('../ConfirmModal.vue', async () => {
  const { h } = await import('vue')
  return { default: { props: ['modelValue', 'title', 'message', 'confirmLabel', 'loading'], emits: ['confirm', 'update:modelValue'], setup: (props, { slots, emit }) => () => props.modelValue ? h('dialog', { title: props.title }, [h('p', props.message), slots.default?.(), h('button', { onClick: () => emit('confirm') }, 'Confirm')]) : null } }
})
const node = (tag = '', text = '') => ({ tag, text, props: {}, children: [], parent: null, addEventListener() {}, removeEventListener() {}, get options() { return this.children.filter((child) => child.tag === 'option') }, get value() { return this.props.value }, set value(value) { this.props.value = value } })
const renderer = createRenderer({
  createElement: (tag) => node(tag), createText: (text) => node('', text), createComment: () => node(),
  setText: (el, text) => { el.text = text }, setElementText: (el, text) => { el.text = text; el.children = [] },
  patchProp: (el, key, prev, value) => { el.props[key] = value },
  insert(child, parent, anchor = null) { if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1); child.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1; parent.children.splice(index < 0 ? parent.children.length : index, 0, child) },
  remove(child) { child.parent?.children.splice(child.parent.children.indexOf(child), 1); child.parent = null },
  parentNode: (child) => child.parent, nextSibling: (child) => child.parent?.children[child.parent.children.indexOf(child) + 1] ?? null,
})
const { descriptor } = parse(readFileSync(new URL('./ExtensionLibrary.vue', import.meta.url), 'utf8'))
const { bindings } = compileScript(descriptor, { id: 'extensions-test' })
const { code } = compileTemplate({ source: descriptor.template.content, filename: 'ExtensionLibrary.vue', id: 'extensions-test', compilerOptions: { bindingMetadata: bindings, mode: 'function' } })
const render = new Function('Vue', code)(Vue)
const findAll = (el, predicate) => [...(predicate(el) ? [el] : []), ...el.children.flatMap((child) => findAll(child, predicate))]
const find = (el, predicate) => findAll(el, predicate)[0]
const text = (el) => el.text + el.children.map(text).join(' ')
const pkg = { name: 'test/portfolio', title: 'Portfolio', version: '1.0.0', blocks: [], counts: { content_types: 1, components: 1 } }
let app
async function mount() {
  const root = node()
  app = renderer.createApp({ ...ExtensionLibrary, render })
  app.provide(ssrContextKey, {})
  app.mount(root)
  await vi.waitFor(() => expect(api.extensions.list).toHaveBeenCalled())
  await nextTick()
  return root
}
beforeEach(() => {
  vi.clearAllMocks()
  vi.stubGlobal('window', { addEventListener() {}, removeEventListener() {} })
  api.extensions.list.mockResolvedValue({ data: { can_install: true, extensions: [{ ...pkg, enabled: true }], errors: [] } })
})
afterEach(() => { app?.unmount(); vi.unstubAllGlobals() })

describe('extension confirmations', () => {
  it('shows extension artwork in full color and supports extensions without an icon', async () => {
    const icon = 'data:image/png;base64,cG5n'
    api.extensions.list.mockResolvedValue({ data: { can_install: true, extensions: [{ ...pkg, icon }, { ...pkg, name: 'test/other', title: 'Other' }], errors: [] } })
    const root = await mount()
    const images = findAll(root, (el) => el.tag === 'img')
    expect(images).toHaveLength(1)
    expect(images[0].props.src).toBe(icon)
    expect(images[0].props.alt).toBe('')
    expect(images[0].props.class).toContain('object-contain')
  })

  it('disables and re-enables an extension and reports failed changes without changing its status', async () => {
    api.extensions.setEnabled.mockResolvedValue({ data: { name: pkg.name, enabled: false } })
    const root = await mount()
    find(root, (el) => el.props['aria-label'] === 'Disable extension: Portfolio').props.onClick()
    await vi.waitFor(() => expect(api.extensions.setEnabled).toHaveBeenCalledWith(pkg.name, false))
    await vi.waitFor(() => expect(text(root)).toContain('Portfolio disabled.'))
    expect(find(root, (el) => el.props['aria-label'] === 'Enable extension: Portfolio')).toBeTruthy()
    api.extensions.setEnabled.mockRejectedValueOnce({ message: 'Unable to update' })
    find(root, (el) => el.props['aria-label'] === 'Enable extension: Portfolio').props.onClick()
    await vi.waitFor(() => expect(text(root)).toContain('Unable to update'))
    expect(find(root, (el) => el.props['aria-label'] === 'Enable extension: Portfolio')).toBeTruthy()
    find(root, (el) => el.props['aria-label'] === 'Enable extension: Portfolio').props.onClick()
    await vi.waitFor(() => expect(text(root)).toContain('Portfolio enabled.'))
    expect(api.extensions.setEnabled).toHaveBeenLastCalledWith(pkg.name, true)
  })

  it('hides extension status controls from users who cannot install extensions', async () => {
    api.extensions.list.mockResolvedValue({ data: { can_install: false, extensions: [{ ...pkg, enabled: false }], errors: [] } })
    const root = await mount()
    expect(text(root)).toContain('Disabled')
    expect(find(root, (el) => el.props['aria-label'] === 'Enable extension: Portfolio')).toBeUndefined()
  })

  it('reviews breaking changes scripts and seed choices together and confirms in one retry', async () => {
    api.extensions.install.mockRejectedValueOnce({ code: 'extension_review', details: {
      extension: pkg.name, installed_version: '1.0.0', version: '2.0.0',
      changes: ['test/card removes a prop.'], scripts: ['test/card'],
      plan: [{ kind: 'content_types', id: 'project', name: 'Projects', exists: false }, { kind: 'components', id: 'hero', name: 'Hero', exists: true }],
    } }).mockResolvedValueOnce({ data: pkg })
    const root = await mount()
    const file = new File(['zip'], 'portfolio.zip')
    find(root, (el) => el.props.id === 'extension-upload').props.onChange({ target: { files: [file], value: 'selected' } })
    await vi.waitFor(() => expect(find(root, (el) => el.tag === 'dialog')?.props.title).toBe('Review extension'))
    expect(text(root)).toContain('test/card removes a prop.')
    expect(text(root)).toContain('Allow JavaScript?')
    expect(text(root)).toContain('Brings along')
    expect(api.extensions.install).toHaveBeenCalledTimes(1)
    const select = find(root, (el) => el.tag === 'select')
    select.props['onUpdate:modelValue']('overwrite')
    find(root, (el) => el.tag === 'dialog').children.at(-1).props.onClick()
    await vi.waitFor(() => expect(api.extensions.install).toHaveBeenCalledTimes(2))
    expect(api.extensions.install.mock.calls[1][1]).toMatchObject({ acceptChanges: true, allowScripts: true, seeds: { 'content_types/project': 'create', 'components/hero': 'overwrite' } })
  })

  it('offers deletion for broken extensions without an inventory row', async () => {
    api.extensions.list.mockResolvedValue({ data: { can_install: true, extensions: [], errors: [
      { extension: 'test/broken', file: 'comet-extension.json', line: null, message: 'Invalid manifest.' },
      { extension: 'test/broken', file: '', line: null, message: 'Another issue.' },
    ] } })
    api.extensions.get.mockResolvedValue({ data: { name: 'test/broken', title: 'test/broken', seeded: [] } })
    const root = await mount()
    const buttons = findAll(root, (el) => el.props['aria-label'] === 'Delete extension: test/broken')
    expect(buttons).toHaveLength(1)
    buttons[0].props.onClick()
    await vi.waitFor(() => expect(api.extensions.get).toHaveBeenCalledWith('test/broken'))
    await vi.waitFor(() => expect(find(root, (el) => el.tag === 'dialog')).toBeTruthy())
  })

  it('leaves uninstall items unchecked and warns only after selecting edited content with entries', async () => {
    api.extensions.get.mockResolvedValue({ data: { ...pkg, seeded: [{ kind: 'content_types', id: 'project', name: 'Projects', modified: true, entry_count: 3 }, { kind: 'components', id: 'hero', name: 'Hero', modified: false }] } })
    api.extensions.delete.mockResolvedValue({ data: { name: pkg.name } })
    const root = await mount()
    find(root, (el) => el.props['aria-label'] === 'Delete extension: Portfolio').props.onClick()
    await vi.waitFor(() => expect(find(root, (el) => el.tag === 'dialog')).toBeTruthy())
    const checkboxes = findAll(root, (el) => el.tag === 'input' && el.props.type === 'checkbox')
    expect(checkboxes).toHaveLength(2)
    expect(checkboxes.every((el) => !el.checked)).toBe(true)
    expect(text(root)).not.toContain('Contains 3 entries')
    checkboxes[0].props['onUpdate:modelValue'](['content_types/project'])
    await nextTick()
    expect(text(root)).toContain('Edited since installation')
    expect(text(root)).toContain('Contains 3 entries')
    find(root, (el) => el.tag === 'dialog').children.at(-1).props.onClick()
    await vi.waitFor(() => expect(api.extensions.delete).toHaveBeenCalledWith(pkg.name, ['content_types/project']))
  })
})
