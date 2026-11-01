import { readFileSync } from 'node:fs'
import * as Vue from 'vue'
import { createRenderer, h, nextTick, reactive, ssrContextKey } from 'vue'
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc'
import { afterEach, describe, expect, it, vi } from 'vitest'
import TableControl from './TableControl.vue'
import { createSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { t } from '../../../i18n/index.js'

vi.mock('@iconify/vue', () => ({ Icon: { render: () => null } }))
vi.mock('./SmartValueEditor.vue', () => ({ default: {
  props: ['modelValue', 'target'], emits: ['update:modelValue'],
  setup: (props, { emit }) => () => h('input', { 'data-target': props.target, value: props.modelValue, onInput: (event) => emit('update:modelValue', event.target.value) }),
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

const { descriptor } = parse(readFileSync(new URL('./TableControl.vue', import.meta.url), 'utf8'))
const { bindings } = compileScript(descriptor, { id: 'table-control-test' })
const { code } = compileTemplate({ source: descriptor.template.content, filename: 'TableControl.vue', id: 'table-control-test', compilerOptions: { bindingMetadata: bindings, mode: 'function' } })
const render = new Function('Vue', code)(Vue)
const Control = { ...TableControl, render }
let app
let root
const all = (el, predicate) => [...(predicate(el) ? [el] : []), ...el.children.flatMap((child) => all(child, predicate))]
const buttons = (label) => all(root, (el) => el.tag === 'button' && el.props['aria-label']?.startsWith(label))
function mount(rows) {
  const state = reactive({ rows })
  let builder
  app = renderer.createApp({ setup() {
    builder = createSiteBuilder({ t })
    return () => h(Control, { id: 'cells', node: { id: 'table1', kind: 'table', props: { header_row: true } }, modelValue: state.rows, 'onUpdate:modelValue': (value) => { state.rows = value } })
  } })
  app.provide(ssrContextKey, {})
  root = node()
  app.mount(root)
  return { state, builder }
}
afterEach(() => app?.unmount())

describe('table controls', () => {
  it('changes the size with steppers and the size picker', async () => {
    const { state } = mount([['a', 'b'], ['c', 'd']])
    buttons('Add row')[0].props.onClick()
    await nextTick()
    buttons('Remove last column')[0].props.onClick()
    await nextTick()
    expect(state.rows).toEqual([['a'], ['c'], ['']])
    buttons('Choose table size')[0].props.onClick()
    await nextTick()
    buttons('2 rows × 3 columns')[0].props.onClick()
    await nextTick()
    expect(state.rows).toEqual([['a', '', ''], ['c', '', '']])
  })

  it('edits the picked cell and acts on its row and column, following the cell', async () => {
    const { state, builder } = mount([['Plan', 'Price'], ['Basic', '9']])
    expect(all(root, (el) => el.tag === 'input')).toHaveLength(0)
    buttons('B2')[0].props.onClick()
    await nextTick()
    expect(builder.selectedCell.value).toEqual({ id: 'table1', row: 1, column: 1 })
    const editor = all(root, (el) => el.tag === 'input')[0]
    expect(editor.props.value).toBe('9')
    editor.props.onInput({ target: { value: '9 €' } })
    await nextTick()
    expect(state.rows[1]).toEqual(['Basic', '9 €'])
    buttons('Insert row above')[0].props.onClick()
    await nextTick()
    expect(state.rows).toEqual([['Plan', 'Price'], ['', ''], ['Basic', '9 €']])
    expect(builder.selectedCell.value).toMatchObject({ row: 2, column: 1 })
    buttons('Move left')[0].props.onClick()
    await nextTick()
    expect(state.rows[2]).toEqual(['9 €', 'Basic'])
    buttons('Delete row')[0].props.onClick()
    await nextTick()
    expect(state.rows).toEqual([['Price', 'Plan'], ['', '']])
  })

  it('replaces the table with cells pasted from a spreadsheet', async () => {
    const { state } = mount([['a']])
    const textarea = all(root, (el) => el.tag === 'textarea')[0]
    textarea.props.onInput({ target: { value: 'Plan\tPrice\nBasic\t9 €' } })
    await nextTick()
    all(root, (el) => el.tag === 'button' && el.props.class?.includes('btn-secondary'))[0].props.onClick()
    await nextTick()
    expect(state.rows).toEqual([['Plan', 'Price'], ['Basic', '9 €']])
  })

  it('locks rows and columns while translating but keeps cells editable', async () => {
    const { builder } = mount([['a', 'b']])
    builder.selectedCell.value = { id: 'table1', row: 0, column: 0 }
    builder.locale.value = 'de'
    await nextTick()
    expect(buttons('Add row')[0].props.disabled).toBe(true)
    expect(buttons('Insert row above')[0].props.disabled).toBe(true)
    expect(all(root, (el) => el.tag === 'input')).toHaveLength(1)
  })
})
