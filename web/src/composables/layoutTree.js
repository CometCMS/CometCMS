// Pure helpers for website layout trees.
//
// A layout is a list of nodes shaped { id, kind, props, style, children }:
// section > row > column > element. Columns may hold rows of their own (up to
// spec.max_row_depth deep), and a collection holds one column: the card it
// repeats for each entry. The schema ("spec") comes from the server
// (LayoutSchema::spec()) and decides which kinds may contain which. All
// mutating helpers change the tree in place; the builder snapshots the tree
// for undo/redo.
//
// A reusable component is placed as a node { kind: 'component', props:
// { component, root, span } } that goes wherever a node of its root kind goes.
// Its children are what it shows in the component's content areas: one
// { kind: 'area_content', props: { area } } per area, holding elements.

export const ROOT = 'root'

/** Structure kind that directly wraps a node of the given kind. */
const WRAPPER = { section: null, row: 'section', column: 'row' }

/** Fresh preset sections: dropped where no section fits, only their row is placed (see resolveDrop()). */
const ADAPTIVE_SECTIONS = new WeakSet()

export function newId() {
  const bytes = new Uint8Array(5)
  globalThis.crypto.getRandomValues(bytes)
  return 'n' + Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

export function clone(value) {
  return JSON.parse(JSON.stringify(value))
}

export function defaultProps(spec, kind) {
  const props = {}
  for (const [key, def] of Object.entries(spec?.kinds?.[kind]?.props ?? {})) {
    props[key] = clone(def.default)
  }
  return props
}

export function createNode(spec, kind, props = {}, style = {}, children = []) {
  return {
    id: newId(),
    kind,
    props: { ...defaultProps(spec, kind), ...clone(props) },
    style: clone(style),
    children,
  }
}

export function isStructure(kind) {
  return kind === 'section' || kind === 'row' || kind === 'column'
}

export const COMPONENT = 'component'

/** Where an entry's own page content goes in a detail layout (see LayoutSchema::PAGE_CONTENT). */
export const PAGE_CONTENT = 'page_content'

/** Where each placement of a component adds its own elements, and what one placement put there (see LayoutSchema::COMPONENT_AREA). */
export const COMPONENT_AREA = 'component_area'
export const AREA_CONTENT = 'area_content'

/** The modal template's panel, and where the page a modal link opens appears in it (see LayoutSchema::MODAL). */
export const MODAL = 'modal'
export const SITE_CONTENT = 'site_content'

/** The kind a node is placed as: a component instance counts as its root kind. */
export function placementKind(node) {
  if (node?.kind !== COMPONENT) return node?.kind
  return isStructure(node.props?.root) ? node.props.root : ''
}

/** Whether a node is a column for its row: a column or a column component. */
export function isColumnLike(node) {
  return placementKind(node) === 'column'
}

/** A new instance of a component ({ id, root, areas }), with its content areas still empty. */
export function instanceNode(spec, component, props = {}) {
  const instance = createNode(spec, COMPONENT, { ...props, component: component.id, root: component.root })
  fillAreas(spec, instance, component)
  return instance
}

/**
 * Give an instance empty content for the areas of `component` ({ areas })
 * it has none for yet. The ids follow from the instance and the area, so
 * filling the same tree again gives the same ids. Returns whether it added any.
 */
export function fillAreas(spec, instance, component) {
  const filled = new Set((instance.children ?? []).map((child) => child.props?.area))
  const missing = (component?.areas ?? []).filter((area) => !filled.has(area.id))
  if (!missing.length) return false
  instance.children = [
    ...(instance.children ?? []),
    ...missing.map((area) => ({ ...createNode(spec, AREA_CONTENT, { area: area.id }), id: `${instance.id}-${area.id}`.slice(0, 80) })),
  ]
  return true
}

/** Fill the content areas of every instance in a tree (see fillAreas()). */
export function fillAllAreas(spec, nodes, components) {
  let changed = false
  walk(nodes, (node) => {
    if (node.kind === COMPONENT) changed = fillAreas(spec, node, components.find((item) => item.id === node.props?.component)) || changed
  })
  return changed
}

/**
 * An instance as an ordinary copy of its component's tree `root`: with the
 * instance's own values, and what it put into each content area in place of
 * the area. The component's nodes get new ids; the area content keeps its own.
 * The copy keeps the instance's id, name and column width (see LayoutSchema::detachInstance).
 */
export function detachedCopy(instance, root) {
  const fills = new Map()
  for (const child of instance.children ?? []) {
    if (child.kind === AREA_CONTENT && !fills.has(child.props?.area)) fills.set(child.props?.area, clone(child.children ?? []))
  }
  const copyNodes = (nodes) => nodes.flatMap((node) => {
    if (node.kind === COMPONENT_AREA) return fills.get(node.id) ?? []
    const { editable: _editable, ...copy } = node
    return [{ ...copy, id: newId(), children: copyNodes(node.children ?? []) }]
  })
  const [copy] = copyNodes([withOverrides(root, instance.overrides)])
  copy.id = instance.id
  if (instance.name) copy.name = instance.name
  if (copy.kind === 'column') copy.props = { ...copy.props, span: clone(instance.props?.span ?? copy.props.span) }
  return copy
}

/**
 * A copy of a component's tree as an instance shows it: the instance's own
 * values replace those of the props the component marks editable (see
 * LayoutSchema::applyOverrides).
 */
export function withOverrides(node, overrides) {
  const copy = clone(node)
  if (!overrides) return copy
  walk([copy], (item) => {
    const values = clone(overrides[item.id] ?? {})
    for (const key of item.kind === COMPONENT ? [] : item.editable ?? []) {
      if (values[key] !== undefined) item.props = { ...item.props, [key]: clone(values[key]) }
    }
  })
  return copy
}

/** Nodes of a component's tree with props each placement may set, in order. */
export function editableNodes(nodes) {
  const list = []
  walk(nodes, (node) => {
    if (node.kind !== COMPONENT && node.editable?.length) list.push(node)
  })
  return list
}

/** Whether a tree binds values of the entry the page shows ("current" references). */
export function usesCurrentEntry(nodes) {
  const scan = (value) => {
    if (!value || typeof value !== 'object') return false
    if (value.type === 'reference' && value.source === 'current') return true
    return Object.values(value).some(scan)
  }
  return nodes.some((node) => scan(node.props) || scan(node.style) || usesCurrentEntry(node.children ?? []))
}

/** Kinds that may be placed inside a parent of the given kind. */
export function allowedChildren(spec, parentKind) {
  if (parentKind === ROOT) return spec?.root ?? ['section']
  return spec?.kinds?.[parentKind]?.children ?? []
}

/**
 * Whether `parent` (a node, or null for the root) may hold a node of `kind`.
 * Field nodes only hold an item template when `isTemplateField(parent)` says
 * so (repeaters), and item templates do not nest.
 */
export function canContain(spec, parent, kind, { isTemplateField = () => false, insideTemplate = false } = {}) {
  if (parent === null) return allowedChildren(spec, ROOT).includes(kind)
  if (parent.kind === 'field') {
    return !insideTemplate && isTemplateField(parent) && (spec?.item_elements ?? []).includes(kind)
  }
  // A collection repeats a single card.
  if (parent.kind === 'collection' && (parent.children?.length ?? 0) > 0) return false
  return allowedChildren(spec, parent.kind).includes(kind)
}

/** How many rows a node at `parentId` would sit in (the parent itself included). */
export function rowDepth(nodes, parentId) {
  if (parentId === null) return 0
  const parent = findNode(nodes, parentId)
  const chain = [...ancestors(nodes, parentId), parent]
  // Page content starts its own sections, so rows around it don't count.
  // An instance's area content sits in at least one of its section's rows.
  return chain.slice(chain.findLastIndex((node) => node?.kind === PAGE_CONTENT) + 1)
    .filter((node, index, list) => placementKind(node) === 'row' || (node?.kind === AREA_CONTENT && list[index - 1]?.props?.root === 'section')).length
}

/** The collection whose card contains `id`, if any. */
export function templateCollection(nodes, id) {
  return ancestors(nodes, id).findLast((node) => node.kind === 'collection') ?? null
}

export function walk(nodes, visit, parent = null, depth = 0) {
  for (const [index, node] of nodes.entries()) {
    if (visit(node, parent, index, depth) === false) return false
    if (Array.isArray(node.children) && node.children.length > 0) {
      if (walk(node.children, visit, node, depth + 1) === false) return false
    }
  }
  return true
}

export function findNode(nodes, id) {
  let found = null
  walk(nodes, (node) => {
    if (node.id === id) {
      found = node
      return false
    }
    return true
  })
  return found
}

/** { parent, list, index } of the node with `id`, or null. parent is null at the root. */
export function locate(nodes, id, parent = null) {
  for (const [index, node] of nodes.entries()) {
    if (node.id === id) return { parent, list: nodes, index }
    if (Array.isArray(node.children)) {
      const found = locate(node.children, id, node)
      if (found) return found
    }
  }
  return null
}

/** Ancestors of `id` from the root down (excluding the node itself). */
export function ancestors(nodes, id) {
  const path = []
  const search = (list) => {
    for (const node of list) {
      if (node.id === id) return true
      path.push(node)
      if (Array.isArray(node.children) && search(node.children)) return true
      path.pop()
    }
    return false
  }
  return search(nodes) ? path : []
}

export function isInsideTemplate(nodes, id) {
  return ancestors(nodes, id).some((node) => node.kind === 'field')
}

export function contains(node, id) {
  return findNode(node.children ?? [], id) !== null
}

export function countNodes(nodes) {
  let count = 0
  walk(nodes, () => {
    count += 1
  })
  return count
}

/** Deep copy of a node with fresh ids throughout. */
export function cloneWithNewIds(node) {
  const copy = clone(node)
  walk([copy], (item) => {
    item.id = newId()
  })
  return copy
}

/**
 * Wrap `node` in structure nodes until a parent of `parentKind` accepts it,
 * e.g. a heading dropped on the root becomes section > row > column > heading.
 * Returns null when no wrapping helps (a section cannot go into a column).
 */
export function wrapFor(spec, parentKind, node) {
  // Individual tabs only belong to a Tabs element, never to an implicit column.
  if (node.kind === 'tab' && parentKind !== 'tabs') return null
  let current = node
  for (let i = 0; i < 4; i += 1) {
    const kind = placementKind(current)
    if (allowedChildren(spec, parentKind).includes(kind)) return current
    const wrapper = isStructure(kind) ? WRAPPER[kind] : 'column'
    if (!wrapper) return null
    if (parentKind !== ROOT && parentKind !== PAGE_CONTENT && !isStructure(parentKind)) return null
    // Never wrap upwards past the parent's own level.
    if (structureLevel(wrapper) <= structureLevel(parentKind)) return null
    current = createNode(spec, wrapper, {}, {}, [current])
  }
  return allowedChildren(spec, parentKind).includes(placementKind(current)) ? current : null
}

function structureLevel(kind) {
  return { root: 0, [PAGE_CONTENT]: 0, section: 1, row: 2, column: 3 }[kind] ?? 4
}

/**
 * Turn a drop on `targetId` at `position` (before | after | inside) into a
 * concrete { parentId, index, node } for inserting `node`, climbing up to
 * ancestors when the direct target cannot hold it. `targetId` null means the
 * end of the layout.
 */
export function resolveDrop(spec, nodes, node, targetId, position, options = {}) {
  const tryPlace = (parent, index) => {
    const insideTemplate = parent ? parent.kind === 'field' || isInsideTemplate(nodes, parent.id) : false
    if (parent && parent.kind === 'field') {
      return canContain(spec, parent, placementKind(node), { ...options, insideTemplate: isInsideTemplate(nodes, parent.id) })
        ? { parentId: parent.id, index, node }
        : null
    }
    // A preset section dropped into a column (or any non-section spot) becomes just its row of columns.
    const candidate = ADAPTIVE_SECTIONS.has(node) && !allowedChildren(spec, parent ? parent.kind : ROOT).includes('section') ? node.children[0] : node
    if (insideTemplate && !(spec?.item_elements ?? []).includes(placementKind(candidate))) return null
    const placed = wrapFor(spec, parent ? parent.kind : ROOT, candidate)
    if (placed && parent && placementKind(placed) === 'row' && rowDepth(nodes, parent.id) > (spec?.max_row_depth ?? 0)) return null
    return placed ? { parentId: parent ? parent.id : null, index, node: placed } : null
  }

  if (targetId === null) {
    return tryPlace(null, nodes.length)
  }

  let currentId = targetId
  let currentPosition = position

  for (let guard = 0; guard < 8 && currentId !== null; guard += 1) {
    const where = locate(nodes, currentId)
    if (!where) return null
    const target = where.list[where.index]

    if (currentPosition === 'inside') {
      const result = tryPlace(target, target.children?.length ?? 0)
      if (result) return result
      currentPosition = 'after'
      continue
    }

    const index = currentPosition === 'before' ? where.index : where.index + 1
    const result = tryPlace(where.parent, index)
    if (result) return result

    // Climb: drop before/after the parent instead.
    currentId = where.parent ? where.parent.id : null
  }

  return currentId === null ? tryPlace(null, currentPosition === 'before' ? 0 : nodes.length) : null
}

export function insertNode(nodes, parentId, index, node) {
  const list = parentId === null ? nodes : findNode(nodes, parentId)?.children
  if (!list) return false
  list.splice(Math.max(0, Math.min(index, list.length)), 0, node)
  return true
}

export function removeNode(nodes, id) {
  const where = locate(nodes, id)
  if (!where) return null
  return where.list.splice(where.index, 1)[0]
}

/** Move `id` to `parentId` at `index` (index in the list before removal). */
export function moveNode(nodes, id, parentId, index) {
  const where = locate(nodes, id)
  if (!where) return false
  const node = where.list[where.index]
  if (parentId !== null && (parentId === id || contains(node, parentId))) return false

  const targetList = parentId === null ? nodes : findNode(nodes, parentId)?.children
  if (!targetList) return false

  let targetIndex = index
  if (targetList === where.list && where.index < index) targetIndex -= 1
  where.list.splice(where.index, 1)
  targetList.splice(Math.max(0, Math.min(targetIndex, targetList.length)), 0, node)
  return true
}

/** Move a node by one position among its siblings. */
export function shiftNode(nodes, id, delta) {
  const where = locate(nodes, id)
  if (!where) return false
  const next = where.index + delta
  if (next < 0 || next >= where.list.length) return false
  const [node] = where.list.splice(where.index, 1)
  where.list.splice(next, 0, node)
  return true
}

export function duplicateNode(nodes, id) {
  const where = locate(nodes, id)
  if (!where) return null
  const copy = cloneWithNewIds(where.list[where.index])
  where.list.splice(where.index + 1, 0, copy)
  return copy
}

/**
 * Responsive spans for column `span` (of 12) in a layout of `count` columns:
 * phones always stack, two columns sit side by side from tablets on, three
 * or more show two per line on tablets and the full layout on desktops.
 */
export function presetSpan(span, count) {
  if (count <= 1 || span >= 12) return { mobile: 12, tablet: 0, desktop: 0 }
  if (count === 2) return { mobile: 12, tablet: span, desktop: 0 }
  return { mobile: 12, tablet: 6, desktop: span }
}

/** Section > row > columns preset with the given column spans (desktop). */
export function sectionPreset(spec, spans) {
  const columns = spans.map((span) => createNode(spec, 'column', { span: presetSpan(span, spans.length) }))
  const section = createNode(spec, 'section', {}, {}, [createNode(spec, 'row', {}, {}, columns)])
  ADAPTIVE_SECTIONS.add(section)
  return section
}

/**
 * Rearrange a row into columns with the given desktop spans. Content is kept:
 * surplus columns hand their elements to the last remaining column. Surplus
 * column components are just removed; their content lives in the component.
 */
export function applyColumnPreset(spec, row, spans) {
  const columns = (row.children ?? []).filter(isColumnLike)
  while (columns.length < spans.length) columns.push(createNode(spec, 'column'))

  if (columns.length > spans.length) {
    const moved = columns.splice(spans.length).flatMap((column) => (column.kind === 'column' ? column.children ?? [] : []))
    if (moved.length) {
      let target = columns.findLast((column) => column.kind === 'column')
      if (!target) {
        target = createNode(spec, 'column')
        columns[columns.length - 1] = target
      }
      target.children.push(...moved)
    }
  }

  columns.forEach((column, index) => {
    column.props = { ...column.props, span: presetSpan(spans[index], spans.length) }
  })
  row.children = columns
  return row
}

/** Put a row's columns underneath each other on every device. */
export function stackColumns(row) {
  for (const column of row.children ?? []) {
    column.props = { ...column.props, span: { mobile: 12, tablet: 0, desktop: 0 } }
  }
  return row
}

/** Desktop spans of a row's columns and whether they are stacked. */
export function columnLayoutOf(row) {
  const spans = (row?.children ?? []).map((column) => effectiveSpan(column.props?.span, 'desktop'))
  return { spans, stacked: spans.length > 1 && spans.every((span) => span === 12) }
}

/** Column spans on a device, applying the mobile > tablet > desktop fallback. */
export function effectiveSpan(span, device) {
  const mobile = span?.mobile || 12
  const tablet = span?.tablet || mobile
  const desktop = span?.desktop || tablet
  return device === 'desktop' ? desktop : device === 'tablet' ? tablet : mobile
}
