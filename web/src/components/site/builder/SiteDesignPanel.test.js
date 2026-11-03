import { afterEach, describe, expect, it, vi } from 'vitest'
import * as Vue from 'vue'
import { createRenderer, nextTick, ssrContextKey } from 'vue'
import { readFileSync } from 'node:fs'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'
import { createPinia } from 'pinia'
import SiteDesignPanel from './SiteDesignPanel.vue'
import { api } from '../../../api/index.js'
import { useSiteStore } from '../../../stores/site.js'

vi.mock('../../../api/index.js', () => ({
  api: { site: { updateThemePreset: vi.fn(), createThemePreset: vi.fn(), deleteThemePreset: vi.fn() } },
  getActiveWorkspace: () => 'test',
}))
vi.mock('@iconify/vue', () => ({ Icon: { render: () => null } }))
vi.mock('./BuilderHelp.vue', () => ({ default: { render: () => null } }))
vi.mock('../../LoadingSpinner.vue', () => ({ default: { render: () => null } }))
vi.mock('../RangeRow.vue', () => ({ default: { render: () => null } }))
vi.mock('../SegmentedControl.vue', () => ({ default: { render: () => null } }))
vi.mock('../DeviceToggle.vue', () => ({ default: { render: () => null } }))
vi.mock('./SpacingTokenSelect.vue', () => ({ default: { render: () => null } }))
vi.mock('../ThemeHeadingsSettings.vue', () => ({ default: { render: () => null } }))
vi.mock('../TokenInput.vue', () => ({ default: { render: () => null } }))
vi.mock('../FontPicker.vue', () => ({ default: { render: () => null } }))
vi.mock('../FontManager.vue', () => ({ default: { render: () => null } }))
vi.mock('./ThemeShadowsSettings.vue', () => ({ default: { render: () => null } }))
vi.mock('./ThemeMotionSettings.vue', () => ({ default: { render: () => null } }))
vi.mock('./ThemeLinkSettings.vue', () => ({ default: { render: () => null } }))
vi.mock('./BuilderGroup.vue', async () => {
  const { h } = await import('vue')
  return { default: { setup: (props, { slots }) => () => h('group', {}, slots.default?.()) } }
})
vi.mock('../../AppDialog.vue', async () => {
  const { h } = await import('vue')
  return { default: { props: ['modelValue', 'title'], emits: ['update:modelValue'], setup: (props, { slots, emit }) => () => props.modelValue
    ? h('app-dialog', { title: props.title }, [slots.default?.(), slots.footer?.({ close: () => emit('update:modelValue', false) })])
    : null } }
})
vi.mock('../../ConfirmModal.vue', async () => {
  const { h } = await import('vue')
  return { default: { props: ['modelValue', 'title'], emits: ['update:modelValue', 'confirm'], setup: (props, { emit }) => () => props.modelValue
    ? h('confirm-modal', { title: props.title, onConfirm: () => emit('confirm') })
    : null } }
})
vi.mock('../ComponentNameModal.vue', async () => {
  const { h } = await import('vue')
  return { default: { props: ['modelValue'], emits: ['update:modelValue', 'confirm'], setup: (props, { emit }) => () => props.modelValue
    ? h('name-modal', { onConfirm: (name) => emit('confirm', name) })
    : null } }
})

const node = (tag = '', text = '') => ({ tag, text, props: {}, children: [], parent: null, addEventListener() {}, removeEventListener() {} })
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
// Compiled for the custom renderer, as in SiteGeneralPanel.test.js.
const { descriptor } = parse(readFileSync(new URL('./SiteDesignPanel.vue', import.meta.url), 'utf8'))
const { bindings } = compileScript(descriptor, { id: 'design-test' })
const { code } = compileTemplate({ source: descriptor.template.content, filename: 'SiteDesignPanel.vue', id: 'design-test', compilerOptions: { bindingMetadata: bindings, mode: 'function' } })
const render = new Function('Vue', code)(Vue)

const text = (el) => el.text + el.children.map(text).join('')
const find = (el, predicate) => predicate(el) ? el : el.children.map((child) => find(child, predicate)).find(Boolean)
const button = (root, label) => find(root, (el) => el.tag === 'button' && text(el).includes(label))
const colors = { primary: '#2563eb', secondary: '#0f172a', accent: '#f59e0b', background: '#ffffff', surface: '#f1f5f9', text: '#0f172a', muted: '#64748b', border: '#e2e8f0' }
const clean = { mode: 'light', colors, dark_colors: colors, custom_colors: {}, shadows: {}, fonts: { body: 'system', heading: 'system' }, radius: 'md', radii: { sm: 4, md: 8, lg: 14 }, space: { xs: 4, sm: 8, md: 16, lg: 32, xl: 56, '2xl': 96 }, motion: { hover: 200 } }
const warm = { ...clean, colors: { ...colors, primary: '#c2410c' }, radius: 'lg' }
const mine = { ...clean, radius: 'none' }

let app
afterEach(() => { app?.unmount(); vi.clearAllMocks() })

function mount(theme) {
  const pinia = createPinia()
  const site = useSiteStore(pinia)
  site.data = {
    settings: { locales: ['en'], default_locale: 'en' },
    menus: [],
    theme: { ...JSON.parse(JSON.stringify(theme)), custom_css: 'body{}' },
    theme_defaults: clean,
    builtin_themes: [{ id: 'clean', theme: clean }, { id: 'warm', theme: warm }],
    theme_presets: [{ id: 'mine', name: 'Mine', extension: '', theme: mine }],
  }
  site.resetDraft()
  const root = node()
  app = renderer.createApp({ ...SiteDesignPanel, render })
  app.provide(ssrContextKey, {})
  app.use(pinia).mount(root)
  return { root, site }
}

describe('design panel themes', () => {
  it('switches right away while the active theme is unchanged', async () => {
    const { root, site } = mount({ ...clean, active_theme: 'builtin:clean' })
    expect(text(root)).toContain('Clean')
    expect(text(root)).not.toContain('Modified')
    button(root, 'Warm').props.onClick()
    await nextTick()
    expect(find(root, (el) => el.tag === 'app-dialog')).toBeUndefined()
    expect(site.draft.theme).toMatchObject({ active_theme: 'builtin:warm', radius: 'lg', custom_css: 'body{}' })
  })

  it('warns before discarding changes and switches only once confirmed', async () => {
    const { root, site } = mount({ ...clean, active_theme: 'builtin:clean', radius: 'sm' })
    expect(text(root)).toContain('Modified')
    button(root, 'Warm').props.onClick()
    await nextTick()
    const dialog = find(root, (el) => el.tag === 'app-dialog')
    expect(dialog.props.title).toBe('Switch to “Warm”?')
    expect(site.draft.theme.active_theme).toBe('builtin:clean')
    button(dialog, 'Discard and switch').props.onClick()
    await nextTick()
    expect(site.draft.theme).toMatchObject({ active_theme: 'builtin:warm', radius: 'lg' })
    expect(find(root, (el) => el.tag === 'app-dialog')).toBeUndefined()
  })

  it('saves changes into the active site theme before switching', async () => {
    const { root, site } = mount({ ...mine, active_theme: 'preset:mine', radius: 'sm' })
    api.site.updateThemePreset.mockImplementation(async (id, theme) => ({ data: { id, name: 'Mine', extension: '', theme: { ...mine, radius: theme.radius } } }))
    button(root, 'Warm').props.onClick()
    await nextTick()
    button(find(root, (el) => el.tag === 'app-dialog'), 'Save to “Mine” and switch').props.onClick()
    await vi.waitFor(() => expect(site.draft.theme.active_theme).toBe('builtin:warm'))
    expect(api.site.updateThemePreset).toHaveBeenCalledWith('mine', expect.objectContaining({ radius: 'sm' }))
    expect(site.data.theme_presets[0].theme.radius).toBe('sm')
  })

  it('saves an unlinked design as a new theme before switching', async () => {
    const { root, site } = mount({ ...clean, active_theme: 'preset:deleted' })
    api.site.createThemePreset.mockImplementation(async (name, theme) => ({ data: { id: 'kept', name, extension: '', theme } }))
    expect(text(root)).toContain('Custom design')
    button(root, 'Warm').props.onClick()
    await nextTick()
    button(find(root, (el) => el.tag === 'app-dialog'), 'Save as new theme…').props.onClick()
    await nextTick()
    find(root, (el) => el.tag === 'name-modal').props.onConfirm('Kept')
    await vi.waitFor(() => expect(site.draft.theme.active_theme).toBe('builtin:warm'))
    expect(site.data.theme_presets.map((preset) => preset.id)).toEqual(['kept', 'mine'])
  })

  it('resets the active theme to its definition after confirmation', async () => {
    const { root, site } = mount({ ...warm, active_theme: 'builtin:warm', radius: 'sm' })
    button(root, 'Reset').props.onClick()
    await nextTick()
    const confirm = find(root, (el) => el.tag === 'confirm-modal' && el.props.title === 'Reset “Warm”?')
    confirm.props.onConfirm()
    await nextTick()
    expect(site.draft.theme).toMatchObject({ active_theme: 'builtin:warm', radius: 'lg', custom_css: 'body{}' })
    expect(text(root)).not.toContain('Modified')
  })
})
