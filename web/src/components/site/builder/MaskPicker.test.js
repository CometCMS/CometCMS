import { readFileSync } from 'node:fs'
import * as Vue from 'vue'
import { createRenderer, h, nextTick, reactive, ssrContextKey } from 'vue'
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc'
import { afterEach, describe, expect, it } from 'vitest'
import MaskPicker from './MaskPicker.vue'
import { createSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { t } from '../../../i18n/index.js'

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

const { descriptor } = parse(readFileSync(new URL('./MaskPicker.vue', import.meta.url), 'utf8'))
const { bindings } = compileScript(descriptor, { id: 'mask-picker-test' })
const { code } = compileTemplate({ source: descriptor.template.content, filename: 'MaskPicker.vue', id: 'mask-picker-test', compilerOptions: { bindingMetadata: bindings, mode: 'function' } })
const Picker = { ...MaskPicker, render: new Function('Vue', code)(Vue) }
const SHAPES = {
  mask: { width: 100, height: 100, paths: { circle: 'M50,0A50,50 0 1 1 50,100A50,50 0 1 1 50,0Z', heart: 'M50,100L0,0H100Z' } },
}

let app
let root
const all = (el, predicate) => [...(predicate(el) ? [el] : []), ...el.children.flatMap((child) => all(child, predicate))]
const radios = () => all(root, (el) => el.props.role === 'radio')

function mount(props) {
  const state = reactive({ value: props.modelValue })
  app = renderer.createApp({ setup() {
    const builder = createSiteBuilder({ t })
    builder.spec.value = { shapes: SHAPES }
    return () => h(Picker, { ...props, modelValue: state.value, 'onUpdate:modelValue': (value) => { state.value = value } })
  } })
  app.provide(ssrContextKey, {})
  root = node()
  app.mount(root)
  return state
}
afterEach(() => app?.unmount())

describe('mask picker', () => {
  it('offers each mask as a radio drawn as fitted and flipped', async () => {
    const state = mount({ options: ['none', 'circle', 'heart'], modelValue: 'none', label: 'Mask', fit: 'contain', flipX: true })
    expect(radios().map((el) => el.props['aria-checked'])).toEqual([true, false, false])
    radios()[2].props.onClick()
    await nextTick()
    expect(state.value).toBe('heart')
    expect(radios()[2].props['aria-checked']).toBe(true)
    const shapes = all(root, (el) => el.tag === 'path')
    // "None" draws the whole box; each shape keeps its proportions and is mirrored sideways.
    expect(shapes.map((el) => el.props.d)).toEqual([SHAPES.mask.paths.circle, SHAPES.mask.paths.heart])
    expect(shapes[1].props.transform).toBe('matrix(-1 0 0 1 100 0)')
    expect(all(root, (el) => el.props.preserveAspectRatio === 'xMidYMid meet')).toHaveLength(2)
  })

  it('stretches unflipped shapes to the box', () => {
    mount({ options: ['circle'], modelValue: 'circle' })
    expect(all(root, (el) => el.tag === 'path')[0].props.transform).toBeUndefined()
    expect(all(root, (el) => el.props.preserveAspectRatio === 'none')).toHaveLength(1)
  })
})
