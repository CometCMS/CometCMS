import { describe, expect, it } from 'vitest'
import {
  ancestors,
  applyColumnPreset,
  columnLayoutOf,
  stackColumns,
  canContain,
  cloneWithNewIds,
  countNodes,
  createNode,
  detachedCopy,
  fillAllAreas,
  duplicateNode,
  effectiveSpan,
  findNode,
  insertNode,
  instanceNode,
  moveNode,
  placementKind,
  removeNode,
  resolveDrop,
  rowDepth,
  sectionPreset,
  shiftNode,
  templateCollection,
  usesCurrentEntry,
  withOverrides,
  wrapFor,
} from './layoutTree.js'

const ELEMENTS = ['field', 'heading', 'text', 'image', 'button', 'spacer', 'divider', 'collection', 'menu']
const spec = {
  root: ['section'],
  item_elements: ['field', 'heading', 'text', 'image', 'button', 'spacer', 'divider'],
  kinds: {
    section: { props: { width: { default: 'contained' } }, children: ['row'] },
    row: { props: { gap: { default: 'md' } }, children: ['column'] },
    column: { props: { span: { default: { mobile: 12, tablet: 0, desktop: 0 } } }, children: ELEMENTS },
    heading: { props: { text: { default: 'Heading' }, level: { default: 'h2' } }, children: [] },
    field: { props: { field: { default: '' } }, children: ['field', 'heading', 'text'] },
    text: { props: {}, children: [] },
    collection: { props: {}, children: [] },
    component: { props: { component: { default: '' }, root: { default: 'section' }, span: { default: { mobile: 12, tablet: 0, desktop: 0 } } }, children: [] },
  },
}

function layout() {
  return [
    { id: 's1', kind: 'section', props: {}, style: {}, children: [
      { id: 'r1', kind: 'row', props: {}, style: {}, children: [
        { id: 'c1', kind: 'column', props: {}, style: {}, children: [
          { id: 'h1', kind: 'heading', props: {}, style: {}, children: [] },
          { id: 'f1', kind: 'field', props: { field: 'steps' }, style: {}, children: [
            { id: 'sub1', kind: 'field', props: { field: 'heading' }, style: {}, children: [] },
          ] },
        ] },
        { id: 'c2', kind: 'column', props: {}, style: {}, children: [] },
      ] },
    ] },
  ]
}

const repeaters = { isTemplateField: (node) => node.props.field === 'steps' }

describe('layout tree', () => {
  it('creates nodes with schema defaults', () => {
    const node = createNode(spec, 'heading', { level: 'h1' })
    expect(node.props).toEqual({ text: 'Heading', level: 'h1' })
    expect(node.id).toMatch(/^n[0-9a-f]{10}$/)
  })

  it('finds, counts and lists ancestors', () => {
    const nodes = layout()
    expect(findNode(nodes, 'sub1').props.field).toBe('heading')
    expect(countNodes(nodes)).toBe(7)
    expect(ancestors(nodes, 'sub1').map((n) => n.id)).toEqual(['s1', 'r1', 'c1', 'f1'])
  })

  it('only lets repeater fields hold an item template', () => {
    const nodes = layout()
    expect(canContain(spec, findNode(nodes, 'f1'), 'heading', repeaters)).toBe(true)
    expect(canContain(spec, findNode(nodes, 'h1'), 'heading', repeaters)).toBe(false)
    expect(canContain(spec, findNode(nodes, 'f1'), 'collection', repeaters)).toBe(false)
    expect(canContain(spec, null, 'heading')).toBe(false)
  })

  it('wraps elements in the structure their target needs', () => {
    const wrapped = wrapFor(spec, 'root', createNode(spec, 'heading'))
    expect(wrapped.kind).toBe('section')
    expect(wrapped.children[0].kind).toBe('row')
    expect(wrapped.children[0].children[0].kind).toBe('column')
    expect(wrapped.children[0].children[0].children[0].kind).toBe('heading')
    expect(wrapFor(spec, 'column', createNode(spec, 'section'))).toBeNull()
  })

  it('resolves drops by climbing to a parent that accepts the node', () => {
    const nodes = layout()
    const section = createNode(spec, 'section')
    expect(resolveDrop(spec, nodes, section, 'h1', 'after', repeaters)).toMatchObject({ parentId: null, index: 1 })

    const heading = createNode(spec, 'heading')
    expect(resolveDrop(spec, nodes, heading, 'c2', 'inside', repeaters)).toMatchObject({ parentId: 'c2', index: 0 })
    expect(resolveDrop(spec, nodes, heading, 'h1', 'before', repeaters)).toMatchObject({ parentId: 'c1', index: 0 })
    expect(resolveDrop(spec, nodes, heading, 'f1', 'inside', repeaters)).toMatchObject({ parentId: 'f1', index: 1 })

    const collection = createNode(spec, 'collection')
    const intoTemplate = resolveDrop(spec, nodes, collection, 'sub1', 'after', repeaters)
    expect(intoTemplate).toMatchObject({ parentId: 'c1', index: 2 })

    const atEnd = resolveDrop(spec, [], heading, null, 'inside', repeaters)
    expect(atEnd.parentId).toBeNull()
    expect(atEnd.node.kind).toBe('section')
  })

  it('inserts, moves, shifts, duplicates and removes nodes', () => {
    const nodes = layout()
    expect(insertNode(nodes, 'c2', 0, createNode(spec, 'text'))).toBe(true)
    expect(findNode(nodes, 'c2').children).toHaveLength(1)

    expect(moveNode(nodes, 'h1', 'c2', 1)).toBe(true)
    expect(findNode(nodes, 'c2').children.map((n) => n.id)[1]).toBe('h1')
    expect(moveNode(nodes, 's1', 'c1', 0)).toBe(false)

    expect(shiftNode(nodes, 'h1', -1)).toBe(true)
    expect(findNode(nodes, 'c2').children[0].id).toBe('h1')

    const copy = duplicateNode(nodes, 'f1')
    expect(copy.id).not.toBe('f1')
    expect(copy.children[0].id).not.toBe('sub1')
    expect(findNode(nodes, 'c1').children.map((n) => n.kind)).toEqual(['field', 'field'])

    expect(removeNode(nodes, 'c2').id).toBe('c2')
    expect(findNode(nodes, 'h1')).toBeNull()
  })

  it('moves within the same list accounting for the removed slot', () => {
    const nodes = [createNode(spec, 'section'), createNode(spec, 'section'), createNode(spec, 'section')]
    const [a, b, c] = nodes.map((n) => n.id)
    moveNode(nodes, a, null, 3)
    expect(nodes.map((n) => n.id)).toEqual([b, c, a])
  })

  it('builds column presets and resolves responsive spans', () => {
    const preset = sectionPreset(spec, [4, 8])
    expect(preset.style).toEqual({}) // New sections inherit the global padding.
    const spans = preset.children[0].children.map((column) => column.props.span)
    expect(spans).toEqual([{ mobile: 12, tablet: 4, desktop: 0 }, { mobile: 12, tablet: 8, desktop: 0 }])
    expect(sectionPreset(spec, [4, 4, 4]).children[0].children[0].props.span).toEqual({ mobile: 12, tablet: 6, desktop: 4 })
    expect(effectiveSpan({ mobile: 12, tablet: 6, desktop: 0 }, 'desktop')).toBe(6)
    expect(effectiveSpan({ mobile: 12, tablet: 0, desktop: 4 }, 'tablet')).toBe(12)
  })

  it('drops a preset section into a column as just its row', () => {
    const column = createNode(spec, 'column')
    const nodes = [createNode(spec, 'section', {}, {}, [createNode(spec, 'row', {}, {}, [column])])]
    const preset = sectionPreset(spec, [6, 6])
    const nesting = { ...spec, max_row_depth: 1, kinds: { ...spec.kinds, column: { ...spec.kinds.column, children: [...ELEMENTS, 'row'] } } }
    const nested = resolveDrop(nesting, nodes, preset, column.id, 'inside')
    expect(nested).toMatchObject({ parentId: column.id, node: { kind: 'row' } })
    expect(nested.node.children).toHaveLength(2)
    expect(resolveDrop(spec, nodes, sectionPreset(spec, [6, 6]), null, 'after')).toMatchObject({ parentId: null, node: { kind: 'section' } })
    // Existing sections are never unwrapped.
    expect(resolveDrop(spec, nodes, createNode(spec, 'section'), column.id, 'inside')).toMatchObject({ parentId: null })
  })

  it('rearranges columns without losing content', () => {
    const nodes = layout()
    const row = findNode(nodes, 'r1')

    applyColumnPreset(spec, row, [12])
    expect(row.children.map((column) => column.id)).toEqual(['c1'])
    expect(row.children[0].children.map((node) => node.id)).toEqual(['h1', 'f1'])
    expect(columnLayoutOf(row)).toEqual({ spans: [12], stacked: false })

    applyColumnPreset(spec, row, [4, 4, 4])
    expect(row.children).toHaveLength(3)
    expect(columnLayoutOf(row)).toEqual({ spans: [4, 4, 4], stacked: false })

    stackColumns(row)
    expect(columnLayoutOf(row)).toEqual({ spans: [12, 12, 12], stacked: true })
  })

  it('clones nodes with fresh ids', () => {
    const original = layout()[0]
    const copy = cloneWithNewIds(original)
    expect(copy.id).not.toBe(original.id)
    expect(countNodes([copy])).toBe(countNodes([original]))
  })

  it('places component instances like their root kind', () => {
    const column = instanceNode(spec, { id: 'cc', root: 'column' })
    const section = instanceNode(spec, { id: 'cs', root: 'section' })
    expect(placementKind(column)).toBe('column')
    expect(placementKind({ kind: 'component', props: { root: 'heading' } })).toBe('')
    expect(column.props).toMatchObject({ component: 'cc', root: 'column', span: { mobile: 12, tablet: 0, desktop: 0 } })

    // A column component dropped on the root is wrapped in a section and a row.
    const wrapped = wrapFor(spec, 'root', column)
    expect([wrapped.kind, wrapped.children[0].kind, wrapped.children[0].children[0].id]).toEqual(['section', 'row', column.id])
    expect(wrapFor(spec, 'column', section)).toBeNull()

    const nodes = layout()
    expect(resolveDrop(spec, nodes, column, 'c1', 'after')).toMatchObject({ parentId: 'r1', index: 1 })
    expect(resolveDrop(spec, nodes, section, 'h1', 'after')).toMatchObject({ parentId: null, index: 1 })
    expect(resolveDrop(spec, nodes, instanceNode(spec, { id: 'x', root: 'column' }), 'sub1', 'after', repeaters)).toMatchObject({ parentId: 'r1' })
  })

  it('arranges column components with the columns of their row', () => {
    const nodes = layout()
    const row = findNode(nodes, 'r1')
    const shared = instanceNode(spec, { id: 'cc', root: 'column' })
    row.children.splice(1, 0, shared)

    applyColumnPreset(spec, row, [4, 4, 4])
    expect(row.children.map((column) => column.id)).toEqual(['c1', shared.id, 'c2'])
    expect(shared.props.span).toEqual({ mobile: 12, tablet: 6, desktop: 4 })

    // Surplus instances are dropped; surplus columns hand their content on.
    applyColumnPreset(spec, row, [12])
    expect(row.children.map((column) => column.id)).toEqual(['c1'])

    const onlyInstances = { id: 'r2', kind: 'row', props: {}, style: {}, children: [shared, { id: 'c9', kind: 'column', props: {}, style: {}, children: [{ id: 'h9', kind: 'heading', props: {}, style: {}, children: [] }] }] }
    applyColumnPreset(spec, onlyInstances, [12])
    expect(onlyInstances.children).toHaveLength(1)
    expect(onlyInstances.children[0].kind).toBe('column')
    expect(onlyInstances.children[0].children.map((node) => node.id)).toEqual(['h9'])
  })

  it('detects trees that show fields of the current entry', () => {
    const nodes = layout()
    expect(usesCurrentEntry(nodes)).toBe(false)
    findNode(nodes, 'h1').props.text = { type: 'template', parts: ['By ', { type: 'reference', source: 'current', path: ['author'], filters: [] }] }
    expect(usesCurrentEntry(nodes)).toBe(true)
    findNode(nodes, 'h1').props.text = { type: 'reference', source: 'site', path: ['title'], filters: [] }
    expect(usesCurrentEntry(nodes)).toBe(false)
  })

  it('nests rows in columns up to the depth limit and gives a collection one card', () => {
    const nested = {
      ...spec,
      max_row_depth: 1,
      kinds: { ...spec.kinds, column: { ...spec.kinds.column, children: [...ELEMENTS, 'row'] }, collection: { props: {}, children: ['column'] } },
    }
    const node = (id, kind, children = []) => ({ id, kind, props: {}, style: {}, children })
    const tree = [node('s', 'section', [node('r', 'row', [node('c', 'column', [
      node('r2', 'row', [node('c2', 'column', [node('list', 'collection')])]),
    ])])])]

    expect(rowDepth(tree, 'c2')).toBe(2)
    expect(resolveDrop(nested, tree, node('new', 'row'), 'c', 'inside')).toMatchObject({ parentId: 'c' })
    // Inside two rows already: the new row climbs out to the outer column instead.
    expect(resolveDrop(nested, tree, node('deep', 'row'), 'c2', 'inside')).toMatchObject({ parentId: 'c' })

    const list = findNode(tree, 'list')
    expect(canContain(nested, list, 'column')).toBe(true)
    expect(canContain(nested, list, 'heading')).toBe(false)
    list.children.push(node('card', 'column', [node('t', 'text')]))
    expect(canContain(nested, list, 'column')).toBe(false)
    expect(templateCollection(tree, 't').id).toBe('list')
    expect(templateCollection(tree, 'c2')).toBeNull()
  })
})

it('applies accordion item overrides without mutating the component', () => {
  const node = { id: 'accordion', kind: 'accordion', editable: ['items'], props: { items: [{ title: 'Base question', html: '<p>Answer</p>', open: false }] }, children: [] }
  const instance = withOverrides(node, { accordion: { items: [{ title: 'Instance question', html: '<p>Answer</p>', open: false }] } })
  expect(instance.props.items).toEqual([{ title: 'Instance question', html: '<p>Answer</p>', open: false }])
  expect(node.props.items[0].title).toBe('Base question')
  const current = withOverrides(node, { accordion: { items: [] } })
  expect(current.props.items).toEqual([])
})

it('fills content areas of instances and counts the rows they sit in', () => {
  const instance = (id, root, children = []) => ({ id, kind: 'component', props: { component: 'card', root }, style: {}, children })
  const nodes = [
    { id: 's1', kind: 'section', props: {}, style: {}, children: [instance('section', 'section'), instance('row', 'row')] },
    instance('kept', 'section', [{ id: 'own', kind: 'area_content', props: { area: 'body' }, style: {}, children: [] }]),
  ]
  const card = { id: 'card', areas: [{ id: 'body', name: 'Body' }, { id: 'aside', name: '' }] }
  expect(fillAllAreas(spec, nodes, [card])).toBe(true)
  expect(nodes[0].children[0].children.map((child) => [child.id, child.props.area])).toEqual([['section-body', 'body'], ['section-aside', 'aside']])
  expect(nodes[1].children.map((child) => child.id)).toEqual(['own', 'kept-aside'])
  expect(fillAllAreas(spec, nodes, [card])).toBe(false)

  // Area content sits in the component's own row; a row component is that row.
  expect(rowDepth(nodes, 'section-body')).toBe(1)
  expect(rowDepth(nodes, 'row-body')).toBe(1)
})

it('detaches an instance with its area content in place of each area', () => {
  const root = { id: 'col', kind: 'column', props: { span: { mobile: 12, tablet: 0, desktop: 0 } }, style: {}, children: [
    { id: 'before', kind: 'heading', props: { text: 'Shared' }, style: {}, editable: ['text'], children: [] },
    { id: 'body', kind: 'component_area', props: {}, style: {}, children: [] },
    { id: 'empty', kind: 'component_area', props: {}, style: {}, children: [] },
  ] }
  const instance = { id: 'i1', kind: 'component', name: 'Promo', props: { component: 'card', root: 'column', span: { mobile: 12, tablet: 6, desktop: 0 } }, overrides: { before: { text: 'Own' } }, children: [
    { id: 'fill', kind: 'area_content', props: { area: 'body' }, style: {}, children: [{ id: 'mine', kind: 'text', props: {}, style: {}, children: [] }] },
  ] }
  const copy = detachedCopy(instance, root)
  expect(copy).toMatchObject({ id: 'i1', kind: 'column', name: 'Promo', props: { span: { mobile: 12, tablet: 6, desktop: 0 } } })
  expect(copy.children.map((node) => node.id === 'mine' ? 'mine' : node.kind)).toEqual(['heading', 'mine'])
  expect(copy.children[0]).toMatchObject({ props: { text: 'Own' } })
  expect(copy.children[0].id).not.toBe('before')
  expect(copy.children[0].editable).toBeUndefined()
})
