import { createSSRApp, h, ref } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createPinia } from 'pinia'
import { afterEach, describe, expect, it, vi } from 'vitest'
import BuilderEntryFields from './BuilderEntryFields.vue'
import BaseField from '../../BaseField.vue'
import { createSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { startPaletteDrag } from '../../../composables/builderPaletteDrag.js'
import { fieldShortcut, reference } from '../../../composables/smartValues.js'
import { t } from '../../../i18n/index.js'

const controls = vi.hoisted(() => ({ fields: {}, inputs: {} }))
vi.mock('../../BaseField.vue', async (original) => {
  const actual = await original()
  return { ...actual, default: {
    props: ['name', 'config', 'readonly', 'draggableLabel', 'dragHint', 'onLabelDragstart', 'onLabelDragend'],
    setup(props, { slots }) {
      controls.fields[props.name] = props
      return () => h(actual.default, props, slots)
    },
  } }
})
vi.mock('../../FieldInput.vue', () => ({ default: {
  props: ['name', 'modelValue', 'readonly', 'onUpdate:modelValue'],
  setup(props) { controls.inputs[props.name] = props; return () => h('input', { value: props.modelValue, readonly: props.readonly }) },
} }))
vi.mock('../../TranslationInfo.vue', () => ({ default: { render: () => null } }))
vi.mock('@iconify/vue', () => ({ Icon: {
  props: ['icon'], setup: (props) => () => h('span', { 'data-icon': props.icon }),
} }))
vi.mock('../../ConfirmModal.vue', () => ({ default: { render: () => null } }))
vi.mock('../ComponentNameModal.vue', () => ({ default: { render: () => null } }))

const fields = {
  title: { type: 'text' }, intro: { type: 'textarea', description: 'Description' },
  photo: { type: 'media' }, gallery: { type: 'media', multiple: true },
  category: { type: 'select' }, steps: { type: 'repeater', subfields: [{ key: 'detail', type: 'text' }] },
}

async function panel({ busy = false, readonly = false, translating = false } = {}) {
  controls.fields = {}
  controls.inputs = {}
  let builder
  const state = {
    record: ref({ entry: { title: 'Example', values: {} } }),
    fields: ref(fields), draft: ref({ errors: {} }), readonly: ref(readonly),
    activeLocale: ref('en'), defaultLocale: ref('en'),
    fieldReadonly: () => readonly, valueFor: () => 'Content', update: vi.fn(),
  }
  const app = createSSRApp({ setup() {
    builder = createSiteBuilder({ t })
    builder.locale.value = translating ? 'de' : 'en'
    builder.spec.value = { root: ['section'], kinds: {
      section: { props: {}, children: ['row'] }, row: { props: {}, children: ['column'] },
      column: { props: {}, children: ['text', 'heading', 'image', 'gallery', 'field'] },
      text: { props: {}, children: [] }, heading: { props: {}, children: [] },
      image: { props: {}, children: [] }, gallery: { props: {}, children: [] }, field: { props: {}, children: ['text'] },
    } }
    builder.load([builder.make('section', {}, {}, [builder.make('row', {}, {}, [builder.make('column')])])])
    return () => h(BuilderEntryFields, { state, busy })
  } })
  app.use(createPinia())
  const html = await renderToString(app)
  return { builder, state, html, column: builder.nodes.value[0].children[0].children[0] }
}
const dragEvent = () => ({ preventDefault: vi.fn(), dataTransfer: { setData: vi.fn() } })
afterEach(() => vi.useRealTimers())

describe('dragging entry fields into the builder', () => {
  it.each(Object.entries(fields))('creates the same dynamic element as the Add panel for %s', async (name, config) => {
    const { builder } = await panel()
    const event = dragEvent()
    controls.fields[name].onLabelDragstart(event)
    expect(builder.drag.value.source).toBe('palette')
    expect(event.dataTransfer.effectAllowed).toBe('copy')
    expect(event.dataTransfer.setData).toHaveBeenCalledWith('text/plain', 'cometcms-node')
    const actual = builder.drag.value.create()
    const expected = fieldShortcut({ key: name, type: config.type, config }, builder.make)
    expect(actual.kind).toBe(expected.kind)
    expect(actual.props).toEqual(expected.props)
    expect(actual.children.map((child) => child.props)).toEqual(expected.children.map((child) => child.props))
  })

  it('drops and selects a bound element with one undo step', async () => {
    const { builder, column } = await panel()
    const before = JSON.stringify(builder.nodes.value)
    controls.fields.photo.onLabelDragstart(dragEvent())
    expect(builder.dropAt(column.id, 'inside')).toBe(true)
    expect(builder.selected.value).toMatchObject({ kind: 'image', props: { media: reference('photo') } })
    expect(builder.drag.value).toBeNull()
    builder.undo()
    expect(JSON.stringify(builder.nodes.value)).toBe(before)
  })

  it('leaves the layout unchanged for an invalid drop', async () => {
    const { builder } = await panel()
    const before = JSON.stringify(builder.nodes.value)
    controls.fields.intro.onLabelDragstart(dragEvent())
    expect(builder.dropAt('missing-node', 'inside')).toBe(false)
    expect(JSON.stringify(builder.nodes.value)).toBe(before)
    expect(builder.canUndo.value).toBe(false)
  })

  it('clears cancelled dragging without clearing a newer drag', async () => {
    vi.useFakeTimers()
    const { builder } = await panel()
    controls.fields.intro.onLabelDragstart(dragEvent())
    controls.fields.intro.onLabelDragend()
    vi.runAllTimers()
    expect(builder.drag.value).toBeNull()
    controls.fields.intro.onLabelDragstart(dragEvent())
    controls.fields.intro.onLabelDragend()
    controls.fields.photo.onLabelDragstart(dragEvent())
    const newer = builder.drag.value
    vi.runAllTimers()
    expect(builder.drag.value).toBe(newer)
  })

  it('allows read-only values to be dragged and preserves the value update handler', async () => {
    const { builder, state, html } = await panel({ readonly: true })
    expect(html.match(/draggable="true"/g)).toHaveLength(Object.keys(fields).length)
    expect(html.match(/<circle /g)).toHaveLength(Object.keys(fields).length * 6)
    expect(html).toContain(t('site.builder.entryFieldDragHint'))
    expect(controls.fields.intro.readonly).toBe(true)
    controls.fields.intro.onLabelDragstart(dragEvent())
    expect(builder.drag.value).not.toBeNull()
    controls.inputs.intro['onUpdate:modelValue']('Edited')
    expect(state.update).toHaveBeenCalledWith('intro', 'Edited')
  })

  it.each([{ busy: true }, { translating: true }])('prevents structural dragging when %j', async (options) => {
    const { builder, html } = await panel(options)
    expect(controls.fields.intro.draggableLabel).toBe(false)
    expect(html).not.toContain('draggable="true"')
    const event = dragEvent()
    controls.fields.intro.onLabelDragstart(event)
    expect(event.preventDefault).toHaveBeenCalled()
    expect(builder.drag.value).toBeNull()
    if (options.translating) {
      startPaletteDrag(builder, event, () => builder.make('text'))
      expect(builder.drag.value).toBeNull()
    }
  })

  it('keeps ordinary BaseField labels non-draggable by default', async () => {
    const app = createSSRApp({ render: () => h(BaseField, { name: 'intro', config: fields.intro }, () => h('input')) })
    app.use(createPinia())
    const html = await renderToString(app)
    expect(html).not.toContain('draggable=')
    expect(html).not.toContain('<circle')
    expect(html).toContain('title="intro"')
  })
})
