import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as Vue from 'vue'
import { createRenderer, h, nextTick, ssrContextKey } from 'vue'
import { readFileSync } from 'node:fs'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import SiteComponentsPanel from './SiteComponentsPanel.vue'
import { api } from '../../api/index.js'
import { useSiteStore } from '../../stores/site.js'
import { useToastStore } from '../../stores/toast.js'

vi.mock('../../api/index.js', () => ({
  api: { site: { components: vi.fn(), renameComponent: vi.fn(), setComponentIcon: vi.fn(), deleteComponent: vi.fn() } },
  getActiveWorkspace: () => 'test',
}))
vi.mock('../LoadingSpinner.vue', () => ({ default: { render: () => null } }))
vi.mock('@iconify/vue', () => ({ Icon: { render: () => null }, addIcon: vi.fn() }))
vi.mock('../IconPickerGrid.vue', () => ({ default: {
  props: ['modelValue', 'placeholder', 'label', 'disabled'], emits: ['update:modelValue'],
  setup: (props, { emit }) => () => h('button', {
    'data-icon-picker': props.modelValue || props.placeholder,
    'aria-label': props.label, disabled: props.disabled,
    onClick: () => emit('update:modelValue', props.modelValue ? '' : 'mdi:star-outline'),
  }),
} }))
vi.mock('../ConfirmModal.vue', () => ({ default: {
  props: ['modelValue', 'loading', 'message'], emits: ['confirm'],
  setup: (props, { emit }) => () => props.modelValue ? h('button', { 'data-confirm-delete': true, onClick: () => emit('confirm') }, props.message) : null,
} }))
vi.mock('./ComponentNameModal.vue', () => ({ default: {
  props: ['modelValue', 'initial', 'loading'], emits: ['confirm'],
  setup: (props, { emit }) => () => props.modelValue ? h('button', { 'data-confirm-rename': true, onClick: () => emit('confirm', 'Renamed promo') }, props.initial) : null,
} }))

const node = (tag = '', text = '') => ({ tag, text, props: {}, children: [], parent: null, addEventListener() {}, removeEventListener() {}, getRootNode: () => ({ activeElement: null }) })
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
const textOf = (el) => el.text + el.children.map(textOf).join('')
const find = (el, predicate) => predicate(el) ? el : el.children.map((child) => find(child, predicate)).find(Boolean)
const fixtures = [
  { id: 'shared', name: 'Shared promo', root: 'section', type: '', usages: 3 },
  { id: 'typed', name: 'Article teaser', root: 'row', type: 'posts', usages: 1 },
]
// Vitest compiles SFCs for SSR in Node. Compile this template for the custom
// renderer so the real controls and their event handlers can be exercised.
const { descriptor } = parse(readFileSync(new URL('./SiteComponentsPanel.vue', import.meta.url), 'utf8'))
const { bindings } = compileScript(descriptor, { id: 'components-test' })
const { code } = compileTemplate({ source: descriptor.template.content, filename: 'SiteComponentsPanel.vue', id: 'components-test', compilerOptions: { bindingMetadata: bindings, mode: 'function' } })
const render = new Function('Vue', code)(Vue)
let app
let root
let pinia

async function mount() {
  pinia = createPinia()
  useSiteStore(pinia).data = { types: [{ name: 'posts', label: 'Articles' }] }
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
  await router.push('/page-builder/components')
  root = node()
  app = renderer.createApp({ ...SiteComponentsPanel, render })
  app.provide(ssrContextKey, {})
  app.use(pinia).use(router)
  app.mount(root)
  await vi.waitFor(() => expect(api.site.components).toHaveBeenCalled())
  await nextTick()
  return router
}
const button = (label) => find(root, (el) => el.tag === 'button' && textOf(el).trim().replace(/\s+:/g, ':') === label)

beforeEach(() => {
  vi.clearAllMocks()
  vi.stubGlobal('Document', class Document {})
  vi.stubGlobal('ShadowRoot', class ShadowRoot {})
  api.site.components.mockResolvedValue({ data: fixtures.map((item) => ({ ...item })) })
})
afterEach(() => { app?.unmount(); vi.unstubAllGlobals() })

describe('component settings library', () => {
  it('loads all components with usage counts and opens the component editor with a return link', async () => {
    const router = await mount()
    expect(textOf(root)).toContain('Shared promo')
    expect(textOf(root)).toContain('Placements: 3')
    // Components fit every type, so none is named.
    expect(textOf(root)).not.toContain('Articles')
    const edit = find(root, (el) => el.tag === 'a' && textOf(el).startsWith('Edit component') && textOf(el).includes('Shared promo'))
    expect(edit.props.href).toContain('/page-builder/builder/component/shared')
    await router.push(edit.props.href)
    expect(router.currentRoute.value.query.back).toBe('/page-builder/components')
  })

  it('searches by component name and kind, and shows no-match feedback', async () => {
    await mount()
    const search = find(root, (el) => el.tag === 'input')
    search.props['onUpdate:modelValue']('teaser')
    await nextTick()
    expect(textOf(root)).toContain('Article teaser')
    expect(textOf(root)).not.toContain('Shared promo')
    search.props['onUpdate:modelValue']('no such component')
    await nextTick()
    expect(find(root, (el) => el.props.role === 'status')).toBeTruthy()
  })

  it('renames immediately and preserves usage metadata', async () => {
    await mount()
    api.site.renameComponent.mockResolvedValue({ data: { ...fixtures[0], name: 'Renamed promo' } })
    button('Rename: Shared promo').props.onClick()
    await nextTick()
    const confirm = find(root, (el) => el.props['data-confirm-rename'])
    expect(textOf(confirm)).toBe('Shared promo')
    confirm.props.onClick()
    await vi.waitFor(() => expect(textOf(root)).toContain('Renamed promo'))
    expect(api.site.renameComponent).toHaveBeenCalledWith('shared', 'Renamed promo')
    expect(textOf(root)).toContain('Placements: 3')
  })

  it('saves icon selections immediately, preserves usage counts, and can restore the default', async () => {
    await mount()
    const picker = () => find(root, (el) => el.props['aria-label'] === 'Change icon for Shared promo')
    expect(picker().props['data-icon-picker']).toBe('mdi:puzzle-outline')
    api.site.setComponentIcon.mockResolvedValueOnce({ data: { ...fixtures[0], icon: 'mdi:star-outline' } })
    picker().props.onClick()
    await vi.waitFor(() => expect(picker().props['data-icon-picker']).toBe('mdi:star-outline'))
    expect(api.site.setComponentIcon).toHaveBeenCalledWith('shared', 'mdi:star-outline')
    expect(textOf(root)).toContain('Placements: 3')

    api.site.setComponentIcon.mockResolvedValueOnce({ data: { ...fixtures[0], icon: '' } })
    picker().props.onClick()
    await vi.waitFor(() => expect(picker().props['data-icon-picker']).toBe('mdi:puzzle-outline'))
    expect(api.site.setComponentIcon).toHaveBeenLastCalledWith('shared', '')
  })

  it('keeps the saved icon and reports errors when an icon update fails', async () => {
    await mount()
    api.site.setComponentIcon.mockRejectedValue(new Error('Icon update failed'))
    const picker = () => find(root, (el) => el.props['aria-label'] === 'Change icon for Shared promo')
    picker().props.onClick()
    await vi.waitFor(() => expect(useToastStore(pinia).toasts.some((toast) => toast.message === 'Icon update failed')).toBe(true))
    expect(picker().props['data-icon-picker']).toBe('mdi:puzzle-outline')
    expect(picker().props.disabled).toBe(false)
  })

  it('requires confirmation before deletion and refreshes remaining usage counts', async () => {
    await mount()
    api.site.deleteComponent.mockResolvedValue({ data: { deleted: true } })
    api.site.components.mockResolvedValue({ data: [{ ...fixtures[1], usages: 0 }] })
    button('Delete: Shared promo').props.onClick()
    await nextTick()
    expect(api.site.deleteComponent).not.toHaveBeenCalled()
    const confirm = find(root, (el) => el.props['data-confirm-delete'])
    expect(textOf(confirm)).toContain('independent, editable copies')
    confirm.props.onClick()
    await vi.waitFor(() => expect(textOf(root)).not.toContain('Shared promo'))
    expect(api.site.deleteComponent).toHaveBeenCalledWith('shared')
    expect(api.site.components).toHaveBeenCalledTimes(2)
    expect(textOf(root)).toContain('Placements: 0')
  })

  it('keeps failed mutations visible and reports the error', async () => {
    await mount()
    api.site.renameComponent.mockRejectedValue(new Error('Rename failed'))
    button('Rename: Shared promo').props.onClick()
    await nextTick()
    find(root, (el) => el.props['data-confirm-rename']).props.onClick()
    await vi.waitFor(() => expect(useToastStore(pinia).toasts.some((toast) => toast.message === 'Rename failed')).toBe(true))
    expect(textOf(root)).toContain('Shared promo')
    expect(find(root, (el) => el.props['data-confirm-rename'])).toBeTruthy()
  })

  it('shows a retry action after a load failure and an empty state after recovery', async () => {
    api.site.components.mockRejectedValueOnce(new Error('Load failed'))
    await mount()
    expect(textOf(root)).toContain('Load failed')
    api.site.components.mockResolvedValueOnce({ data: [] })
    button('Try again').props.onClick()
    await vi.waitFor(() => expect(textOf(root)).toContain('No components yet.'))
  })
})
