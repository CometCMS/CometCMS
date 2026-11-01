// The wire format shared with Site/SmartValue.php. No expression evaluation.
import { conditionOps } from './templates.js'

export const DATE_FORMATS = ['date', 'long', 'datetime', 'time']
const KEY = '[A-Za-z0-9_-]+'
const PATH = `(${KEY}(?:\\.${KEY})*)`
const ROOT = new RegExp(`^(current|item|site)\\.${PATH}$`)
const ENTRY = new RegExp(`^entry\\.(${KEY})\\["(${KEY})"\\]\\.${PATH}$`)
export const isReference = (value) => value?.type === 'reference'
export const isSmartValue = (value) => ['reference', 'template', 'invalid'].includes(value?.type)
export const reference = (key, source = 'current') => ({ type: 'reference', source, path: [key], filters: [] })

function splitFilters(text) {
  const parts = []
  let start = 0, quoted = false, escaped = false
  for (let i = 0; i < text.length; i++) {
    const char = text[i]
    if (escaped) { escaped = false; continue }
    if (char === '\\' && quoted) { escaped = true; continue }
    if (char === '"') quoted = !quoted
    if (char === '|' && !quoted) { parts.push(text.slice(start, i).trim()); start = i + 1 }
  }
  if (quoted) throw new Error('syntax')
  parts.push(text.slice(start).trim())
  return parts
}

export function parseReference(text) {
  const [root, ...operations] = splitFilters(text.trim())
  let match, value
  if ((match = ROOT.exec(root))) value = { type: 'reference', source: match[1], path: match[2].split('.'), filters: [] }
  else if ((match = ENTRY.exec(root))) value = { type: 'reference', source: 'entry', collection: match[1], entry: match[2], path: match[3].split('.'), filters: [] }
  else throw new Error('syntax')
  if (value.path.length > 6 || value.path.some((key) => key.length > 80) || operations.length > 10) throw new Error('syntax')
  for (const operation of operations) {
    const filter = /^(fallback|date|truncate)\s*:\s*(.+)$/.exec(operation)
    if (!filter) throw new Error('syntax')
    let arg
    try { arg = JSON.parse(filter[2]) } catch { throw new Error('syntax') }
    if (filter[1] === 'fallback' && (typeof arg !== 'string' || arg.length > 2000)) throw new Error('syntax')
    if (filter[1] === 'date' && !DATE_FORMATS.includes(arg)) throw new Error('syntax')
    if (filter[1] === 'truncate' && (!Number.isInteger(arg) || arg < 1 || arg > 500)) throw new Error('syntax')
    value.filters.push({ name: filter[1], value: arg })
  }
  return value
}

export function referenceSyntax(value) {
  let root = value.source
  if (value.source === 'entry') root += `.${value.collection}[${JSON.stringify(value.entry)}]`
  return `${root}.${value.path.join('.')}${(value.filters ?? []).map((filter) => ` | ${filter.name}:${JSON.stringify(filter.value)}`).join('')}`
}

const escapeLiteral = (text) => String(text).replace(/\\/g, '\\\\').replace(/\{\{/g, '\\{{')
export function serializeSmartValue(value) {
  if (value?.type === 'invalid') return value.text
  if (isReference(value)) return `{{ ${referenceSyntax(value)} }}`
  if (value?.type === 'template') return value.parts.map((part) => typeof part === 'string' ? escapeLiteral(part) : serializeSmartValue(part)).join('')
  return escapeLiteral(value ?? '')
}

export function parseSmartValue(text, target = 'text') {
  const parts = []
  let literal = ''
  for (let i = 0; i < text.length;) {
    if (text[i] === '\\' && (text[i + 1] === '\\' || text.slice(i + 1, i + 3) === '{{')) {
      if (text[i + 1] === '\\') { literal += '\\'; i += 2 }
      else { literal += '{{'; i += 3 }
      continue
    }
    if (text.slice(i, i + 2) !== '{{') { literal += text[i++]; continue }
    if (literal) { parts.push(literal); literal = '' }
    let end = i + 2, quoted = false, escaped = false
    for (; end < text.length; end++) {
      const char = text[end]
      if (escaped) { escaped = false; continue }
      if (char === '\\' && quoted) { escaped = true; continue }
      if (char === '"') quoted = !quoted
      if (!quoted && text.slice(end, end + 2) === '}}') break
    }
    if (end >= text.length) throw new Error('syntax')
    parts.push(parseReference(text.slice(i + 2, end)))
    i = end + 2
  }
  if (literal) parts.push(literal)
  if (!parts.some(isReference)) return parts.join('')
  if (target === 'html') {
    const markup = parts.map((part) => typeof part === 'string' ? part : 'SMARTREFERENCE').join('')
    if ((markup.match(/<[^>]*>/gs) ?? []).some((tag) => tag.includes('SMARTREFERENCE'))) throw new Error('syntax')
  }
  if (parts.length === 1 && isReference(parts[0])) {
    if (['media', 'media_list', 'link'].includes(target) && parts[0].filters.some((filter) => filter.name !== 'fallback')) throw new Error('syntax')
    return parts[0]
  }
  if (!['text', 'html'].includes(target)) throw new Error('syntax')
  return { type: 'template', parts }
}

export function referenceConfig(ref, { type, types = [], itemFields = [] }) {
  if (!isReference(ref)) return null
  if (ref.source === 'site') return ref.path.length === 1 && ['title', 'tagline', 'year'].includes(ref.path[0]) ? { type: 'text' } : null
  let schema = ref.source === 'entry' ? types.find((item) => item.name === ref.collection && item.visibility !== 'private') : type
  let fields = ref.source === 'item' ? Object.fromEntries(itemFields.map((field) => [field.key, field.config ?? field])) : schema?.fields ?? {}
  let entryContext = ref.source !== 'item'
  for (let i = 0; i < ref.path.length; i++) {
    const key = ref.path[i]
    const last = i === ref.path.length - 1
    const config = (Object.hasOwn(fields, key) ? fields[key] : null) ?? (entryContext && ['title', 'slug', 'url', 'published_at', 'created_at', 'updated_at'].includes(key)
      ? { type: key === 'url' ? 'url' : ['title', 'slug'].includes(key) ? 'text' : 'datetime' } : null)
    if (!config) return null
    if (last) return config
    if (config.type !== 'relation' || config.multiple) return null
    schema = types.find((item) => item.name === config.target && item.visibility !== 'private')
    if (!schema) return null
    fields = schema.fields ?? {}
    entryContext = true
  }
  return null
}

export function compatibleField(config, target) {
  if (!config || typeof config.type !== 'string') return false
  if (target === 'value') return true
  // Element conditions (style visible_if) compare a field with a value.
  if (target === 'condition') return conditionOps(config.type).length > 0
  if (target === 'media') return config.type === 'media' && !config.multiple
  if (target === 'media_list') return config.type === 'media'
  if (target === 'link') return ['text', 'textarea', 'slug', 'url'].includes(config.type) && !config.multiple
  return !['media', 'relation', 'repeater', 'json'].includes(config.type) && !config.multiple
}

export function smartSummary(value) {
  if (isReference(value)) return [value.source, ...(value.source === 'entry' ? [value.collection] : []), ...value.path].join(' › ')
  if (value?.type === 'template') return value.parts.map((part) => typeof part === 'string' ? part : `[${smartSummary(part)}]`).join('').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 100)
  return typeof value === 'string' ? value.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 100) : ''
}

export function fieldShortcut(field, make, { source = 'current', heading = 'h2' } = {}) {
  const ref = reference(field.key, source)
  if (field.key === 'title') return make('heading', { text: ref, level: heading })
  if (field.type === 'media' && !field.config?.multiple) return make('image', { media: ref, ratio: '16:9' })
  if (field.type === 'media') return make('gallery', { media: ref })
  if (compatibleField({ type: field.type, ...field.config }, 'text') && !['range', 'boolean', 'select', 'color'].includes(field.type)) return make('text', { html: ref })
  const node = make('field', { source: ref, display: field.type === 'relation' ? 'cards' : field.type === 'repeater' ? 'items' : '', ...(field.type === 'relation' ? { columns: 3 } : {}) })
  if (field.type === 'repeater') node.children = (field.config?.subfields ?? []).filter((sub) => sub?.key).map((sub, index) => {
    const child = fieldShortcut({ key: sub.key, type: sub.type ?? 'text', config: sub }, make, { source: 'item', heading: 'h4' })
    if (index === 0 && sub.type === 'text') { child.kind = 'heading'; child.props = { ...make('heading').props, text: reference(sub.key, 'item'), level: 'h4' } }
    child.style = { margin_bottom: 'xs' }
    return child
  })
  return node
}

export function hasInvalidValues(value) {
  if (!value || typeof value !== 'object') return false
  return value.type === 'invalid' || Object.values(value).some(hasInvalidValues)
}

/**
 * Fields of a type that combines several (see combinedType in useSiteBuilder),
 * grouped for pickers: those every type has first, then each type's own.
 * A plain type gives one group without a label.
 */
export function fieldGroups(fields, schema, sharedLabel) {
  if (!schema?.field_types || !schema.type_labels) return [{ key: 'all', label: '', items: fields }]
  const everywhere = (field) => (schema.field_types[field.key]?.length ?? schema.type_count) === schema.type_count
  return [
    { key: 'shared', label: sharedLabel, items: fields.filter(everywhere) },
    ...schema.type_labels.map((label) => ({
      key: `type:${label}`,
      label,
      items: fields.filter((field) => !everywhere(field) && schema.field_types[field.key]?.includes(label)),
    })),
  ]
}
