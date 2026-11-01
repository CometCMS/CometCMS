import { selectOptions } from './fieldBuilderUtils.js'
import { resolveLocalized } from './localizedValues.js'

/**
 * Templates assemble a page kind's pages from parts (see
 * SiteRepository::templates()): `{ type: { variant: [template, …] } }`, where
 * a template is `{ id, name, conditions, header, footer }` and has its own
 * content area, edited in the builder under the template's id. The first
 * template whose conditions match an entry is used; the last one is the
 * default and always applies. Key order follows the server, so an unchanged
 * draft compares equal to what was saved.
 */
export const FRAME_DEFAULT = 'default'
export const FRAME_NONE = 'none'
export const FRAME_REGIONS = ['header', 'footer']
export const DEFAULT_TEMPLATE = 'default'
export const MAX_TEMPLATES = 50
export const MAX_CONDITIONS = 10

export function defaultTemplate() {
  return { id: DEFAULT_TEMPLATE, name: 'Default', conditions: [], header: FRAME_DEFAULT, footer: FRAME_DEFAULT }
}

/** A page kind's templates in order, the default last. */
export function templateList(templates, type, variant) {
  const list = templates?.[type]?.[variant]
  return Array.isArray(list) && list.length ? list : [defaultTemplate()]
}

/**
 * Whether a page kind uses templates beyond the default. Until then the
 * builder and the admin bar keep their template controls out of the way (the
 * Templates tab always has them).
 */
export function usesTemplates(templates, type, variant) {
  return templateList(templates, type, variant).length > 1
}

/** A copy of `templates` with one page kind's list replaced. */
export function withTemplateList(templates, type, variant, list) {
  const next = JSON.parse(JSON.stringify(templates ?? {}))
  next[type] = { ...(next[type] ?? {}), [variant]: JSON.parse(JSON.stringify(list)) }
  return next
}

/** A copy of `templates` with some parts (or the name or conditions) of one template changed. */
export function withTemplate(templates, type, variant, id, changes) {
  return withTemplateList(templates, type, variant, templateList(templates, type, variant).map((template) => template.id === id ? { ...template, ...changes } : template))
}

/** The template a page kind's header or footer shows by default: the one the builder previews when nothing else is chosen. */
export function templateById(templates, type, variant, id) {
  const list = templateList(templates, type, variant)
  return list.find((template) => template.id === id) ?? list[list.length - 1]
}

/** Move a template (not the default, which stays last) to `index`. */
export function withTemplateMoved(templates, type, variant, id, index) {
  const list = templateList(templates, type, variant)
  const others = list.slice(0, -1)
  const from = others.findIndex((template) => template.id === id)
  if (from < 0) return templates
  const [moved] = others.splice(from, 1)
  others.splice(Math.max(0, Math.min(index, others.length)), 0, moved)
  return withTemplateList(templates, type, variant, [...others, list[list.length - 1]])
}

export function withoutTemplate(templates, type, variant, id) {
  if (id === DEFAULT_TEMPLATE) return templates
  return withTemplateList(templates, type, variant, templateList(templates, type, variant).filter((template) => template.id !== id))
}

/** Components that can stand in for a header or footer: sections, shared or of this type. */
/** Section components can be any layout's header or footer. */
export function frameComponents(components) {
  return (components ?? []).filter((component) => component.root === 'section')
}

export function templateName(t, template) {
  return template?.id === DEFAULT_TEMPLATE ? t('site.templates.defaultName') : template?.name ?? ''
}

/** What a header or footer shows, in words. */
export function frameLabel(t, value, region, components) {
  if (value === FRAME_NONE) return t(`site.frame.none.${region}`)
  if (value === FRAME_DEFAULT) return t(`site.frame.default.${region}`)
  return components?.find((component) => component.id === value)?.name ?? t('site.frame.missing')
}

/** The comparisons a condition on a field of this type can make (mirrors SiteRepository::conditionOps()). */
export function conditionOps(fieldType) {
  if (fieldType === 'entry') return ['equals']
  if (!fieldType || ['page', 'json', 'repeater'].includes(fieldType)) return []
  const ops = ['equals', 'not_equals', 'contains', 'empty', 'not_empty']
  return ops.filter((op) => {
    if (op === 'equals' || op === 'not_equals') return ['text', 'slug', 'textarea', 'number', 'range', 'boolean', 'select', 'color', 'date', 'datetime'].includes(fieldType)
    if (op === 'contains') return ['text', 'textarea', 'markdown', 'html', 'select'].includes(fieldType)
    return true
  })
}

/** Schema labels use the content type's default language, like its field editor. */
function conditionFieldLabel(type, key) {
  const locale = type?.default_locale || type?.locales?.[0] || 'en'
  return resolveLocalized(type?.fields?.[key]?.label, locale, locale) || key
}

/** Fields of a content type that conditions can test, with their field type. */
export function conditionFields(type, t = (key) => key === 'site.templates.entry' ? 'Entry' : key) {
  const fields = Object.entries(type?.fields ?? {})
    .filter(([, config]) => conditionOps(config?.type).length)
    .map(([key, config]) => ({ key, label: conditionFieldLabel(type, key), type: config?.type }))
  return [
    { key: 'uid', label: t('site.templates.entry'), type: 'entry' },
    ...fields,
    ...(fields.some((field) => field.key === 'slug') ? [] : [{ key: 'slug', label: 'Slug', type: 'slug' }]),
  ]
}

export const needsValue = (op) => !['empty', 'not_empty'].includes(op)

/** Fixed choices for a condition's value on a field: a select field's options, or yes/no. */
export function conditionValueOptions(config, t) {
  if (config?.type === 'boolean') return [{ value: 'true', label: t('site.templates.true') }, { value: 'false', label: t('site.templates.false') }]
  if (config?.type === 'select') return selectOptions(config.options)
  return null
}

/** A typed condition value from an input's text. */
export function parseConditionValue(config, value) {
  if (config?.type === 'boolean') return value === 'true'
  if (['number', 'range'].includes(config?.type) && value !== '' && Number.isFinite(Number(value))) return Number(value)
  return value
}

/** The value a new condition on a field starts with: its first fixed choice, or nothing. */
export function initialConditionValue(config, t) {
  const first = conditionValueOptions(config, t)?.[0]
  return first ? parseConditionValue(config, first.value) : ''
}

/** A condition in words, e.g. "Category = Tips". */
export function conditionLabel(t, condition, type, entries = []) {
  if (condition.field === 'uid') {
    const ids = Array.isArray(condition.value) ? condition.value : []
    const names = ids.map((uid) => entries.find((entry) => entry.uid === uid)?.title)
    const value = ids.length && names.every(Boolean) ? names.join(', ') : t('site.templates.selectedEntries', { count: ids.length })
    return t('site.templates.op.equals', { field: t('site.templates.entry'), value })
  }
  const config = type?.fields?.[condition.field]
  const field = conditionFieldLabel(type, condition.field)
  let value = condition.value
  if (config?.type === 'select') value = selectOptions(config.options).find((option) => option.value === String(value))?.label ?? value
  if (typeof value === 'boolean') value = t(value ? 'site.templates.true' : 'site.templates.false')
  return t(`site.templates.op.${condition.op}`, { field, value: value ?? '' })
}

/** Why a page uses its template, in words (see SiteRepository::resolveTemplate() and PageRenderer::areaTemplate()). */
export function reasonLabel(t, reason, template, type) {
  if (reason === 'conditions') return t('site.templates.reason.conditions', { conditions: (template?.conditions ?? []).map((condition) => conditionLabel(t, condition, type)).join(', ') })
  return t(`site.templates.reason.${reason}`)
}
