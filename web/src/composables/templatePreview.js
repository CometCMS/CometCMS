import { withOverrides } from './layoutTree.js'

const CONTAINERS = new Set(['section', 'row', 'column', 'page_content', 'tabs', 'tab'])
const LEAVES = new Set(['heading', 'text', 'html', 'image', 'video', 'gallery', 'button', 'accordion', 'table', 'icon', 'spacer', 'divider', 'form', 'menu', 'search', 'site_title', 'language_switcher', 'theme_toggle'])

/** A bounded, decorative wireframe of a saved layout; never renders user HTML. */
export async function templatePreview(nodes, { loadComponent, types = [], type = '', settings = {} } = {}) {
  let remaining = 180
  async function visit(list, context, stack = [], depth = 0) {
    if (!Array.isArray(list) || depth > 12) return []
    const result = []
    for (const node of list) {
      if (!node || remaining-- <= 0) break
      if (node.style?.hide_on?.includes('desktop')) continue
      const props = node.props ?? {}
      if (node.kind === 'component') {
        const id = props.component
        if (!id || stack.includes(id) || !loadComponent) continue
        const component = await loadComponent(id)
        const root = component?.nodes?.[0]
        if (!root) continue
        const copy = withOverrides(root, node.overrides)
        if (copy.kind === 'column') copy.props = { ...copy.props, span: props.span }
        result.push(...await visit([copy], component.type || context, [...stack, id], depth + 1))
        continue
      }
      let kind = node.kind
      if (kind === 'field') {
        const reference = props.source
        const schema = types.find((item) => item.name === (reference?.collection || context))
        const field = schema?.fields?.find((item) => item.key === (reference?.path?.[0] || props.field))
        const display = props.display || ({ media: 'image', markdown: 'rich', html: 'rich', textarea: 'rich', repeater: 'items', relation: 'cards' }[field?.type] ?? 'text')
        kind = /^h[1-6]$/.test(props.tag) ? 'heading' : ['image', 'gallery'].includes(display) ? display : ['items', 'cards'].includes(display) ? 'collection' : ['rich', 'excerpt', 'code'].includes(display) ? 'text' : 'line'
      }
      if (kind === 'collection') {
        const children = await visit(node.children, props.type || context, stack, depth + 1)
        const count = Math.min(3, Math.max(1, Number(props.limit) || 3))
        result.push({ kind, columns: Math.min(3, Math.max(1, Number(props.columns) || 3)), children: Array.from({ length: count }, () => ({ kind: 'column', children })) })
        continue
      }
      if (!CONTAINERS.has(kind) && !LEAVES.has(kind) && kind !== 'line') continue
      const children = await visit(node.children, context, stack, depth + 1)
      result.push({
        kind, children,
        span: Number(props.span?.desktop) || 0,
        horizontal: kind === 'row' || (kind === 'column' && props.direction === 'row'),
        vertical: kind === 'menu' && props.orientation === 'vertical',
        logo: kind === 'site_title' && props.show_logo !== false && !!settings.logo,
        name: props.show_name !== false,
        backgroundImage: !!node.style?.background_image && (typeof node.style.background_image !== 'string' || node.style.background_image.trim() !== ''),
      })
    }
    return result
  }
  return visit(nodes, type)
}

/** Deduplicates reads for this panel's lifetime; failed reads can be retried. */
export function previewCache() {
  const requests = new Map()
  return (key, read) => {
    if (!requests.has(key)) {
      requests.set(key, Promise.resolve().then(read).catch((error) => { requests.delete(key); throw error }))
    }
    return requests.get(key)
  }
}
