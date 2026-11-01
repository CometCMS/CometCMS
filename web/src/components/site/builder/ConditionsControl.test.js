import { readFileSync } from 'node:fs'
import * as Vue from 'vue'
import { createRenderer, h, nextTick, reactive, ssrContextKey } from 'vue'
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ConditionsControl from './ConditionsControl.vue'
import { createSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { t } from '../../../i18n/index.js'

vi.mock('@iconify/vue', () => ({ Icon: { render: () => null } }))
vi.mock('./OptionBar.vue', () => ({ default: {
  props: ['modelValue', 'options'], emits: ['update:modelValue'],
  setup: (props, { emit }) => () => h('bar', { role: 'radiogroup' }, props.options.map((option) => h('button', { role: 'radio', 'aria-label': option.title, onClick: () => emit('update:modelValue', option.value) }))),
} }))
// The picker selects whichever field the test names on its pick hook.
let nextField = null
vi.mock('./SmartReferencePicker.vue', () => ({ default: {
  props: ['target', 'initial'], emits: ['select', 'close'],
  setup: (props, { emit }) => () => h('picker', { 'data-target': props.target, onPick: () => emit('select', nextField) }),
} }))

const node = (tag = '') => ({ tag, props: {}, children: [], parent: null, addEventListener() {} })
const renderer = createRenderer({
  createElement: node, createText: () => node(), createComment: () => node(),
  setText() {}, setElementText() {},
  patchProp: (el, key, _prev, value) => { el.props[key] = value },
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

const { descriptor } = parse(readFileSync(new URL('./ConditionsControl.vue', import.meta.url), 'utf8'))
const { bindings } = compileScript(descriptor, { id: 'conditions-test' })
const { code } = compileTemplate({ source: descriptor.template.content, filename: 'ConditionsControl.vue', id: 'conditions-test', compilerOptions: { bindingMetadata: bindings, mode: 'function' } })
const render = new Function('Vue', code)(Vue)
const Control = { ...ConditionsControl, render }

const BLOG = { name: 'blog', fields: { featured: { type: 'boolean' }, category: { type: 'select', options: { news: 'News', tips: 'Tips' } }, rating: { type: 'number' } } }
const ref = (field) => ({ type: 'reference', source: 'current', path: [field], filters: [] })

let app
let root
const all = (el, predicate) => [...(predicate(el) ? [el] : []), ...el.children.flatMap((child) => all(child, predicate))]
const selects = () => all(root, (el) => el.tag === 'select')
const optionValues = (select) => all(select, (el) => el.tag === 'option').map((el) => el.props.value)
const button = (label) => all(root, (el) => el.tag === 'button' && (el.props['aria-label'] === label || el.props.title === label))[0]
const addButton = () => all(root, (el) => el.tag === 'button' && el.props.class?.includes('border-dashed'))[0]
const picker = () => all(root, (el) => el.tag === 'picker')[0]
function mount(value = []) {
  const state = reactive({ value })
  app = renderer.createApp({ setup() {
    const builder = createSiteBuilder({ t })
    builder.type.value = BLOG
    builder.types.value = [BLOG]
    return () => h(Control, { id: 'conditions', node: { id: 'heading', kind: 'heading', props: {}, style: {} }, modelValue: state.value, 'onUpdate:modelValue': (next) => { state.value = next } })
  } })
  app.provide(ssrContextKey, {})
  root = node()
  app.mount(root)
  return state
}
afterEach(() => app?.unmount())

async function add(field) {
  nextField = ref(field)
  addButton().props.onClick()
  await nextTick()
  expect(picker().props['data-target']).toBe('condition')
  picker().props.onPick()
  await nextTick()
}

describe('element conditions control', () => {
  it('adds conditions with ops and values that fit each field', async () => {
    const state = mount()
    await add('featured')
    expect(state.value).toEqual({ match: 'all', conditions: [{ field: ref('featured'), op: 'equals', value: true }] })
    // A boolean compares with yes/no, never "contains".
    expect(optionValues(selects()[0])).toEqual(['equals', 'not_equals', 'empty', 'not_empty'])
    expect(optionValues(selects()[1])).toEqual(['true', 'false'])

    await add('category')
    expect(state.value.conditions[1]).toEqual({ field: ref('category'), op: 'equals', value: 'news' })
    await nextTick()
    expect(all(root, (el) => el.props.role === 'radiogroup')).toHaveLength(1)
  })

  it('offers all/any once there are two conditions', () => {
    const state = mount({ match: 'all', conditions: [{ field: ref('featured'), op: 'empty', value: null }, { field: ref('rating'), op: 'empty', value: null }] })
    const any = all(root, (el) => el.props.role === 'radio' && el.props['aria-label'] === t('site.condition.match.any'))[0]
    any.props.onClick()
    expect(state.value.match).toBe('any')
    expect(state.value.conditions).toHaveLength(2)
  })

  it('drops the value for empty checks and the whole key with the last condition', async () => {
    const state = mount({ match: 'any', conditions: [{ field: ref('rating'), op: 'equals', value: 3 }] })
    selects()[0].props.onChange({ target: { value: 'empty' } })
    await nextTick()
    expect(state.value).toEqual({ match: 'any', conditions: [{ field: ref('rating'), op: 'empty', value: null }] })
    selects()[0].props.onChange({ target: { value: 'not_equals' } })
    await nextTick()
    expect(state.value.conditions[0]).toEqual({ field: ref('rating'), op: 'not_equals', value: '' })
    all(root, (el) => el.tag === 'input')[0].props.onChange({ target: { value: '4' } })
    await nextTick()
    expect(state.value.conditions[0].value).toBe(4)

    button(t('site.condition.remove')).props.onClick()
    await nextTick()
    expect(state.value).toEqual([])
  })

  it('refits the op and value when the field changes', async () => {
    const state = mount({ match: 'all', conditions: [{ field: ref('category'), op: 'contains', value: 'ti' }] })
    nextField = ref('featured')
    button(t('site.condition.changeField')).props.onClick()
    await nextTick()
    picker().props.onPick()
    await nextTick()
    expect(state.value.conditions[0]).toEqual({ field: ref('featured'), op: 'equals', value: true })
  })
})
