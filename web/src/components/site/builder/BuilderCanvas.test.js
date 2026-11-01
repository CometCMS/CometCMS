import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import BuilderCanvas from './BuilderCanvas.vue'
import { createSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { PREVIEW_KEEP } from '../../../composables/previewDocument.js'
import { t } from '../../../i18n/index.js'

const preview = vi.hoisted(() => ({ document: null, emit: null }))
vi.mock('../SitePreviewFrame.vue', () => ({ default: {
  setup(_props, { emit }) {
    preview.emit = emit
    emit('ready', preview.document)
    return () => null
  },
} }))

function eventTarget() {
  const listeners = {}
  return {
    addEventListener: (name, listener) => { listeners[name] = listener },
    dispatch: (name, event = {}) => listeners[name]?.(event),
  }
}

function part(name, editable = false) {
  return {
    editable,
    children: [],
    classList: { contains: (value) => value === `c-${name}` },
    getBoundingClientRect: () => ({ left: 0, top: name === 'header' ? 0 : 600, right: 820, bottom: name === 'header' ? 72 : 760, width: 820, height: name === 'header' ? 72 : 160 }),
  }
}

async function canvas(props = {}) {
  const overlays = []
  const selectTemplatePart = vi.fn()
  const editField = vi.fn()
  let builder
  const parts = { header: part('header'), footer: part('footer') }
  const doc = {
    ...eventTarget(),
    documentElement: eventTarget(),
    defaultView: eventTarget(),
    body: { append: (...elements) => { overlays.splice(0, overlays.length, ...elements) } },
    createElement: () => ({ ...eventTarget(), style: {}, attributes: {}, children: [],
      setAttribute(name, value) { this.attributes[name] = value },
      append(child) { this.children.push(child) },
    }),
    querySelectorAll: () => [],
    getSelection: () => ({ toString: () => props.selection ?? '', selectAllChildren() {}, collapseToEnd() {} }),
    querySelector: (selector) => Object.entries(parts).find(([name, value]) => selector === `.c-${name}:not([data-drop-root])` && !value.editable)?.[1] ?? null,
  }
  preview.document = doc
  const app = createSSRApp({ setup() {
    builder = createSiteBuilder({ t })
    return () => h(BuilderCanvas, { ...props, onSelectTemplatePart: selectTemplatePart, onEditField: editField })
  } })
  await renderToString(app)
  const highlight = overlays.find((element) => element.className === 'cometcms-template-highlight')
  const target = (name) => ({
    closest: (selector) => {
      if (selector === '[data-node]' && name === 'page') return { dataset: { node: 'section1' } }
      return selector === '.c-region:not([data-drop-root])' && !parts[name]?.editable ? parts[name] : null
    },
  })
  const hover = (name) => doc.dispatch('mouseover', { target: target(name) })
  const click = (name) => {
    const event = { target: target(name), preventDefault: vi.fn(), stopPropagation: vi.fn() }
    doc.dispatch('click', event)
    return event
  }
  const badge = overlays.find((element) => element.className === 'cometcms-badge')
  return { doc, parts, highlight, badge, hover, click, selectTemplatePart, editField, builder, overlays }
}

describe('builder CSS canvas picker', () => {
  async function picker(scoped = true) {
    const result = await canvas()
    result.builder.nodes.value = [{ id: 'section1', kind: 'section', children: [] }]
    result.builder.selectedId.value = 'section1'
    const onPick = vi.fn()
    result.builder.cssPicker.value = { rootId: scoped ? 'section1' : null, className: scoped ? 'hero' : null, onPick }
    const root = { dataset: { node: 'section1' }, contains: (target) => target === child || target === root }
    const child = {
      classList: ['c-button', 'c-preview-hover'], localName: 'a', isConnected: true,
      closest: (selector) => selector === '[data-node]' ? root : null,
      getBoundingClientRect: () => ({ left: 10, top: 20, width: 100, height: 40 }),
    }
    root.closest = child.closest
    root.classList = ['c-section', 'hero']
    root.isConnected = true
    root.getBoundingClientRect = child.getBoundingClientRect
    vi.spyOn(result.doc, 'querySelector').mockImplementation((selector) => selector === '[data-node="section1"]' ? root : null)
    const dispatch = (name, target = child, extra = {}) => {
      const event = { target, preventDefault: vi.fn(), stopPropagation: vi.fn(), ...extra }
      result.doc.dispatch(name, event)
      return event
    }
    return { ...result, root, child, onPick, dispatch, cssHighlight: result.overlays.find((el) => el.className === 'cometcms-css-highlight') }
  }

  it('previews a scoped descendant selector and picks once without changing selection', async () => {
    vi.stubGlobal('CSS', { escape: (value) => value })
    try {
      const { builder, dispatch, cssHighlight, onPick, doc } = await picker()
      dispatch('mouseover')
      expect(cssHighlight.hidden).toBe(false)
      expect(cssHighlight.children[0].textContent).toBe('.hero .c-button')
      expect(cssHighlight.style).toMatchObject({ left: '10px', top: '20px', width: '100px', height: '40px' })
      doc.documentElement.dispatch('mouseleave')
      expect(cssHighlight.hidden).toBe(true)
      const event = dispatch('click')
      expect(onPick).toHaveBeenCalledExactlyOnceWith('.c-button')
      expect(builder.cssPicker.value).toBeNull()
      expect(builder.selectedId.value).toBe('section1')
      expect(event.preventDefault).toHaveBeenCalled()
      expect(event.stopPropagation).toHaveBeenCalled()
    } finally { vi.unstubAllGlobals() }
  })

  it('rejects targets outside the selected element and keeps picking active', async () => {
    vi.stubGlobal('CSS', { escape: (value) => value })
    try {
      const { builder, dispatch, cssHighlight, onPick } = await picker()
      const outside = { closest: () => null }
      dispatch('mouseover', outside)
      dispatch('click', outside)
      expect(cssHighlight.hidden).toBe(true)
      expect(onPick).not.toHaveBeenCalled()
      expect(builder.cssPicker.value).not.toBeNull()
      expect(builder.selectedId.value).toBe('section1')
    } finally { vi.unstubAllGlobals() }
  })

  it('uses & for the scoped root and supports classless descendants', async () => {
    vi.stubGlobal('CSS', { escape: (value) => value })
    try {
      const first = await picker()
      first.dispatch('click', first.root)
      expect(first.onPick).toHaveBeenCalledWith('&')
      const second = await picker()
      second.child.classList = []
      second.child.localName = 'span'
      second.dispatch('click')
      expect(second.onPick).toHaveBeenCalledWith('span')
    } finally { vi.unstubAllGlobals() }
  })

  it('picks across the editable canvas in whole stylesheet mode and cancels with Escape', async () => {
    vi.stubGlobal('CSS', { escape: (value) => value })
    try {
      const first = await picker(false)
      first.dispatch('click')
      expect(first.onPick).toHaveBeenCalledWith('.c-button')
      const second = await picker(false)
      second.dispatch('mouseover')
      second.dispatch('keydown', second.child, { key: 'Escape' })
      expect(second.builder.cssPicker.value).toBeNull()
      expect(second.cssHighlight.hidden).toBe(true)
      expect(second.onPick).not.toHaveBeenCalled()
    } finally { vi.unstubAllGlobals() }
  })
})

describe('builder canvas dragging', () => {
  it.each(['section', 'row', 'column', 'heading'])('moves the selected %s from its label', async (kind) => {
    const { doc, badge, builder } = await canvas()
    const id = `${kind}1`
    const node = { dataset: { node: id } }
    builder.selectedId.value = id
    vi.spyOn(doc, 'querySelector').mockImplementation((selector) => selector === `[data-node="${id}"]` ? node : null)
    const label = badge.children[0]
    label.closest = (selector) => selector === '.cometcms-badge-label' ? label : null
    const dataTransfer = { setData: vi.fn() }
    vi.stubGlobal('CSS', { escape: (value) => value })
    try {
      expect(label.attributes.draggable).toBe('true')
      doc.dispatch('dragstart', { target: label, dataTransfer })
      expect(builder.drag.value).toEqual({ source: 'move', id })
      expect(dataTransfer.effectAllowed).toBe('move')
      expect(dataTransfer.setData).toHaveBeenCalledWith('text/plain', id)
    } finally {
      vi.unstubAllGlobals()
    }
  })

  it('keeps the menu out of dragging and still supports dragging nodes directly', async () => {
    const { doc, badge, builder } = await canvas()
    const menu = badge.children[1]
    menu.closest = () => null
    const dataTransfer = { setData: vi.fn() }
    expect(menu.attributes.draggable).toBe('false')
    doc.dispatch('dragstart', { target: menu, dataTransfer })
    expect(builder.drag.value).toBeNull()
    expect(dataTransfer.setData).not.toHaveBeenCalled()

    const node = { dataset: { node: 'heading1' } }
    node.closest = (selector) => selector === '[data-node]' ? node : null
    doc.dispatch('dragstart', { target: node, dataTransfer })
    expect(builder.drag.value).toEqual({ source: 'move', id: 'heading1' })
    expect(dataTransfer.setData).toHaveBeenCalledWith('text/plain', 'heading1')
  })
})

describe('builder shared template hover', () => {
  it('shows a labeled overlay for header and footer descendants, then clears it over page content', async () => {
    const { highlight, hover } = await canvas()
    expect(highlight.hidden).toBe(true)
    hover('header')
    expect(highlight.hidden).toBe(false)
    expect(highlight.children[0].textContent).toBe('Header · Layout part')
    expect(highlight.style).toMatchObject({ left: '0px', top: '0px', width: '820px', height: '72px' })
    hover('footer')
    expect(highlight.children[0].textContent).toBe('Footer · Layout part')
    expect(highlight.style.top).toBe('600px')
    hover(null)
    expect(highlight.hidden).toBe(true)
  })

  it('preserves the overlay on preview updates and hides it when the pointer leaves the frame', async () => {
    const { doc, highlight, hover } = await canvas()
    hover('header')
    expect(highlight.attributes).toHaveProperty(PREVIEW_KEEP)
    preview.emit('updated', doc)
    expect(highlight.hidden).toBe(false)
    doc.documentElement.dispatch('mouseleave')
    expect(highlight.hidden).toBe(true)
  })

  it('leaves the currently edited region to the regular node hover controls', async () => {
    const { parts, highlight, hover } = await canvas()
    parts.header.editable = true
    hover('header')
    expect(highlight.hidden).toBe(true)
  })

  it('selects the template part on a single click and clears it when selecting page content', async () => {
    const { click, selectTemplatePart, builder } = await canvas()
    for (const name of ['header', 'footer']) {
      const event = click(name)
      expect(selectTemplatePart).toHaveBeenLastCalledWith(name)
      expect(builder.selectedId.value).toBeNull()
      expect(event.preventDefault).toHaveBeenCalledOnce()
      expect(event.stopPropagation).toHaveBeenCalledOnce()
    }
    click('page')
    expect(selectTemplatePart).toHaveBeenLastCalledWith(null)
    expect(builder.selectedId.value).toBe('section1')
    click(null)
    expect(selectTemplatePart).toHaveBeenLastCalledWith(null)
    expect(builder.selectedId.value).toBeNull()
  })

  it('keeps the selected part highlighted when the pointer moves into the sidebar', async () => {
    const { doc, highlight, hover } = await canvas({ selectedTemplatePart: 'footer' })
    expect(highlight.hidden).toBe(false)
    expect(highlight.style.outlineStyle).toBe('solid')
    hover('header')
    expect(highlight.children[0].textContent).toBe('Header · Layout part')
    expect(highlight.style.outlineStyle).toBe('dashed')
    doc.documentElement.dispatch('mouseleave')
    expect(highlight.hidden).toBe(false)
    expect(highlight.children[0].textContent).toBe('Footer · Layout part')
    expect(highlight.style.outlineStyle).toBe('solid')
  })

  it('does not select the region already being edited as a shared template part', async () => {
    const { parts, click, selectTemplatePart } = await canvas()
    parts.header.editable = true
    click('header')
    expect(selectTemplatePart).toHaveBeenLastCalledWith(null)
  })
})

describe('builder smart tag double-click', () => {
  const ref = (key, source = 'current', path = [key]) => ({ type: 'reference', source, path, filters: [] })

  async function doubleClick(text, selection) {
    const result = await canvas({ selection })
    result.builder.spec.value = { kinds: { heading: { props: { text: { binding: 'text' }, level: {} } } } }
    result.builder.nodes.value = [{ id: 'heading1', kind: 'heading', props: { text }, style: {}, children: [] }]
    const event = { target: { closest: (selector) => selector === '[data-node]' ? { dataset: { node: 'heading1' }, querySelector: () => null } : null }, preventDefault: vi.fn() }
    result.doc.dispatch('dblclick', event)
    return { ...result, event }
  }

  it('asks to edit the fields of the previewed entry that the text shows', async () => {
    const text = { type: 'template', parts: ['Meet ', ref('name'), ' from ', ref('city'), ref('title', 'site'), ref('author', 'current', ['author', 'title'])] }
    const { editField, event } = await doubleClick(text, 'Berlin')
    expect(editField).toHaveBeenCalledWith({ fields: ['name', 'city'], text: 'Berlin' })
    expect(event.preventDefault).toHaveBeenCalledOnce()
  })

  it('leaves text without tags of the previewed entry alone', async () => {
    const { editField } = await doubleClick(ref('title', 'site'))
    expect(editField).not.toHaveBeenCalled()
  })
})

describe('builder table cell editing', () => {
  /** A rendered table: cells that edit like the canvas expects, inside the table node. */
  function table(rows) {
    const node = { dataset: { node: 'table1', kind: 'table' }, setAttribute() {}, parentElement: null }
    const element = { querySelector: (selector) => cells.flat().find((cell) => selector === `[data-cell="${cell.dataset.cell}"]`) ?? null }
    const cells = rows.map((row, r) => row.map((html, c) => {
      const listeners = {}
      const cell = {
        dataset: { cell: `${r}:${c}` }, innerHTML: html, isConnected: true, listeners,
        addEventListener(name, listener, { signal } = {}) { listeners[name] = listener; signal?.addEventListener('abort', () => delete listeners[name]) },
        removeAttribute() {}, focus() {}, blur() {},
        contains: (target) => target === cell,
        closest: (selector) => ({ '[data-cell]': cell, '[data-node]': node, table: element })[selector] ?? null,
      }
      return cell
    }))
    node.closest = (selector) => (selector === '[data-node]' ? node : null)
    return { node, cells }
  }

  async function editing(rows) {
    const result = await canvas()
    result.builder.spec.value = { kinds: { table: { props: { rows: { type: 'table_rows', localized: true } } } } }
    result.builder.nodes.value = [{ id: 'table1', kind: 'table', props: { rows }, style: {}, children: [] }]
    const { cells } = table(rows)
    const key = (cell, key, extra = {}) => cell.listeners.keydown?.({ key, preventDefault: vi.fn(), stopPropagation: vi.fn(), ...extra })
    const open = (cell) => result.doc.dispatch('dblclick', { target: cell, preventDefault: vi.fn() })
    const rowsNow = () => result.builder.valueFor(result.builder.nodes.value[0], 'props', 'rows')
    return { ...result, cells, key, open, rowsNow }
  }

  it('types through cells with Tab and saves them all once with Enter', async () => {
    const { cells, key, open, rowsNow, builder } = await editing([['Plan', 'Price'], ['Basic', '9']])
    open(cells[0][1])
    expect(builder.selectedCell.value).toEqual({ id: 'table1', row: 0, column: 1 })
    cells[0][1].innerHTML = 'Price<br>'
    key(cells[0][1], 'Tab')
    // Nothing is saved while moving between cells.
    expect(rowsNow()[0][1]).toBe('Price')
    expect(builder.selectedCell.value).toMatchObject({ row: 1, column: 0 })
    cells[1][0].innerHTML = '<strong>Basic</strong>'
    key(cells[1][0], 'Tab', { shiftKey: true })
    key(cells[0][1], 'Tab')
    cells[1][0].innerHTML = 'Starter'
    key(cells[1][0], 'Enter')
    expect(rowsNow()).toEqual([['Plan', 'Price'], ['Starter', '9']])
  })

  it('undoes only the edited cell with Escape and leaves bound cells to the inspector', async () => {
    const binding = { type: 'reference', source: 'site', path: ['title'], filters: [] }
    const { cells, key, open, rowsNow } = await editing([['a', 'b'], [binding, 'd']])
    open(cells[0][0])
    cells[0][0].innerHTML = 'A'
    key(cells[0][0], 'Tab')
    cells[0][1].innerHTML = 'changed'
    key(cells[0][1], 'Escape')
    expect(cells[0][1].innerHTML).toBe('b')
    expect(rowsNow()).toEqual([['A', 'b'], [binding, 'd']])
    open(cells[1][0])
    expect(cells[1][0].listeners.keydown).toBeUndefined()
  })

  it('fills the table from the edited cell when cells are pasted from a spreadsheet', async () => {
    const { cells, open, rowsNow } = await editing([['Plan', 'Price'], ['', '']])
    open(cells[1][0])
    const paste = { preventDefault: vi.fn(), clipboardData: { getData: () => 'Basic\t9 €\nPro\t19 €\t<yearly>' } }
    cells[1][0].listeners.paste(paste)
    expect(paste.preventDefault).toHaveBeenCalled()
    expect(rowsNow()).toEqual([['Plan', 'Price', ''], ['Basic', '9 €', ''], ['Pro', '19 €', '&lt;yearly&gt;']])
  })
})
