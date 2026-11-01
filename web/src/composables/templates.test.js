import { describe, expect, it } from 'vitest'
import {
  DEFAULT_TEMPLATE, FRAME_NONE, conditionFields, conditionLabel, conditionOps, conditionValueOptions, defaultTemplate, frameComponents,
  initialConditionValue, parseConditionValue,
  frameLabel, reasonLabel, templateById, templateList, usesTemplates, withTemplate, withTemplateMoved, withoutTemplate,
} from './templates.js'

const t = (key, params = {}) => Object.entries(params).reduce((text, [name, value]) => text.replace(`{${name}}`, value), key)
/** Shows a condition's parts, or the key of texts without them. */
const words = (key, params) => (params ? `${params.field}=${params.value}` : key)
const tips = { id: 'tips', name: 'Tips', conditions: [{ field: 'category', op: 'equals', value: 'tips' }], header: FRAME_NONE, footer: 'default' }

describe('templates', () => {
  it('starts every page kind with the default template', () => {
    expect(templateList({}, 'blog', 'detail')).toEqual([defaultTemplate()])
    expect(templateList({ blog: { detail: [tips, defaultTemplate()] } }, 'blog', 'detail')).toEqual([tips, defaultTemplate()])
    expect(templateById({}, 'blog', 'detail', 'missing').id).toBe(DEFAULT_TEMPLATE)
  })

  it('changes templates without touching the original or the key order', () => {
    const original = { blog: { detail: [tips, defaultTemplate()] } }
    const changed = withTemplate(original, 'blog', 'detail', 'tips', { header: 'default' })
    expect(Object.keys(changed.blog.detail[0])).toEqual(Object.keys(tips))
    expect(changed.blog.detail[0].header).toBe('default')
    expect(original.blog.detail[0].header).toBe(FRAME_NONE)
  })

  it('moves and removes templates while the default stays last', () => {
    let templates = { blog: { detail: [tips, { ...tips, id: 'tips-2' }, defaultTemplate()] } }
    templates = withTemplateMoved(templates, 'blog', 'detail', 'tips-2', 0)
    expect(templateList(templates, 'blog', 'detail').map((template) => template.id)).toEqual(['tips-2', 'tips', 'default'])
    expect(templateList(withTemplateMoved(templates, 'blog', 'detail', 'tips', 9), 'blog', 'detail').at(-1).id).toBe('default')
    expect(withoutTemplate(templates, 'blog', 'detail', 'default')).toBe(templates)
    expect(templateList(withoutTemplate(templates, 'blog', 'detail', 'tips'), 'blog', 'detail').map((template) => template.id)).toEqual(['tips-2', 'default'])
  })

  it('offers the comparisons each field type supports, like the server', () => {
    expect(conditionOps('boolean')).toEqual(['equals', 'not_equals', 'empty', 'not_empty'])
    expect(conditionOps('select')).toEqual(['equals', 'not_equals', 'contains', 'empty', 'not_empty'])
    expect(conditionOps('media')).toEqual(['empty', 'not_empty'])
    expect(conditionOps('repeater')).toEqual([])
    expect(conditionOps('entry')).toEqual(['equals'])
    expect(conditionFields({ fields: { title: { type: 'text', label: 'Title' }, steps: { type: 'repeater' } } }).map((field) => field.key)).toEqual(['uid', 'title', 'slug'])
  })

  it('types condition values by their field', () => {
    const select = { type: 'select', options: { news: 'News', tips: 'Tips' } }
    expect(conditionValueOptions({ type: 'boolean' }, t).map((option) => option.value)).toEqual(['true', 'false'])
    expect(conditionValueOptions(select, t).map((option) => option.value)).toEqual(['news', 'tips'])
    expect(conditionValueOptions({ type: 'text' }, t)).toBeNull()
    expect(conditionValueOptions(null, t)).toBeNull()
    expect(parseConditionValue({ type: 'boolean' }, 'false')).toBe(false)
    expect(parseConditionValue({ type: 'number' }, '4.5')).toBe(4.5)
    expect(parseConditionValue({ type: 'number' }, 'x')).toBe('x')
    expect(initialConditionValue({ type: 'boolean' }, t)).toBe(true)
    expect(initialConditionValue(select, t)).toBe('news')
    expect(initialConditionValue({ type: 'text' }, t)).toBe('')
  })

  it('resolves localized condition field labels and falls back to field keys', () => {
    const type = { default_locale: 'de', fields: {
      body: { type: 'html', label: { type: 'localized', values: { de: 'Beitragsinhalt', en: 'Post content' }, sources: [] } },
      title: { type: 'text' },
      missing: { type: 'text', label: { type: 'localized', values: { en: 'Missing translation' } } },
    } }
    expect(conditionFields(type).map(({ label }) => label)).toEqual(['Entry', 'Beitragsinhalt', 'title', 'missing', 'Slug'])
    const condition = { field: 'body', op: 'contains', value: 'Hello' }
    expect(conditionLabel(words, condition, type)).toBe('Beitragsinhalt=Hello')
    expect(conditionFields({ ...type, default_locale: 'en' })[1].label).toBe('Post content')
    expect(conditionFields({ ...type, default_locale: undefined, locales: ['de'] })[1].label).toBe('Beitragsinhalt')
    expect(conditionLabel(words, { ...condition, field: 'missing' }, type)).toBe('missing=Hello')
    expect(reasonLabel((key, params) => params.conditions ?? words(key, params), 'conditions', { conditions: [condition] }, type)).toBe('Beitragsinhalt=Hello')
  })

  it('names selected entries without exposing UIDs when records are unavailable', () => {
    const condition = { field: 'uid', op: 'equals', value: ['stable-a', 'stable-b'] }
    const translate = (key, params) => key === 'site.templates.entry' ? 'Eintrag'
      : key === 'site.templates.selectedEntries' ? `Ausgewählte Einträge (${params.count})` : words(key, params)
    expect(conditionFields({}, translate)[0]).toEqual({ key: 'uid', label: 'Eintrag', type: 'entry' })
    expect(conditionLabel(translate, condition, {})).toBe('Eintrag=Ausgewählte Einträge (2)')
    expect(conditionLabel(translate, condition, {}, [{ uid: 'stable-a', title: 'First' }, { uid: 'stable-b', title: 'Renamed' }])).toBe('Eintrag=First, Renamed')
    expect(conditionLabel(translate, { ...condition, value: [] }, {})).toBe('Eintrag=Ausgewählte Einträge (0)')
  })

  it('describes parts, conditions and reasons in words', () => {
    const type = { fields: { category: { type: 'select', label: 'Category', options: { tips: 'Tips & tricks' } }, featured: { type: 'boolean', label: 'Featured' } } }
    expect(conditionLabel(t, tips.conditions[0], type)).toBe('site.templates.op.equals')
    expect(conditionLabel(words, tips.conditions[0], type)).toBe('Category=Tips & tricks')
    expect(conditionLabel(words, { field: 'featured', op: 'equals', value: true }, type)).toBe('Featured=site.templates.true')
    expect(reasonLabel((key, params) => (params?.conditions !== undefined ? `${key}:${params.conditions}` : key), 'conditions', tips, type)).toBe('site.templates.reason.conditions:site.templates.op.equals')
    expect(frameLabel(t, 'promo', 'footer', [{ id: 'promo', name: 'Promo' }])).toBe('Promo')
    expect(frameLabel(t, FRAME_NONE, 'header', [])).toBe('site.frame.none.header')
    expect(frameComponents([{ id: 'a', root: 'section', type: '' }, { id: 'b', root: 'column', type: '' }, { id: 'c', root: 'section', type: 'about' }]).map((component) => component.id)).toEqual(['a', 'c'])
  })
})

describe('usesTemplates', () => {
  it('stays false while a page kind has only its default template', () => {
    expect(usesTemplates({}, 'blog', 'detail')).toBe(false)
    expect(usesTemplates({ blog: { detail: [defaultTemplate()] } }, 'blog', 'detail')).toBe(false)
    expect(usesTemplates({ blog: { detail: [tips, defaultTemplate()] } }, 'blog', 'detail')).toBe(true)
  })
})
