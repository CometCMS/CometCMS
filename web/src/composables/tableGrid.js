/**
 * Table cells as rows of inline HTML (or bindings). Every helper returns a new
 * rectangular grid and leaves its input alone; limits match LayoutSchema.
 */
export const MAX_ROWS = 300
export const MAX_COLUMNS = 30

const clamp = (value, min, max) => Math.max(min, Math.min(max, value))
const width = (rows) => rows[0]?.length ?? 0
const copy = (cell) => (cell && typeof cell === 'object' ? JSON.parse(JSON.stringify(cell)) : cell)

/** The grid with exactly rowCount × columnCount cells; new cells are empty. */
export function resize(rows, rowCount, columnCount) {
  const r = clamp(rowCount, 1, MAX_ROWS)
  const c = clamp(columnCount, 1, MAX_COLUMNS)
  return Array.from({ length: r }, (_, i) => Array.from({ length: c }, (_, j) => rows[i]?.[j] ?? ''))
}

export function insertRow(rows, index) {
  if (rows.length >= MAX_ROWS) return rows
  const next = rows.slice()
  next.splice(clamp(index, 0, rows.length), 0, Array(Math.max(1, width(rows))).fill(''))
  return next
}

export function insertColumn(rows, index) {
  if (width(rows) >= MAX_COLUMNS) return rows
  return rows.map((row) => {
    const next = row.slice()
    next.splice(clamp(index, 0, row.length), 0, '')
    return next
  })
}

export function duplicateRow(rows, index) {
  if (rows.length >= MAX_ROWS) return rows
  const next = rows.slice()
  next.splice(index + 1, 0, rows[index].map(copy))
  return next
}

export function duplicateColumn(rows, index) {
  if (width(rows) >= MAX_COLUMNS) return rows
  return rows.map((row) => {
    const next = row.slice()
    next.splice(index + 1, 0, copy(row[index]))
    return next
  })
}

/** A table keeps at least one row and one column. */
export function removeRow(rows, index) {
  return rows.length > 1 ? rows.filter((_, i) => i !== index) : rows
}

export function removeColumn(rows, index) {
  return width(rows) > 1 ? rows.map((row) => row.filter((_, i) => i !== index)) : rows
}

export function moveRow(rows, from, to) {
  if (to < 0 || to >= rows.length || from === to) return rows
  const next = rows.slice()
  next.splice(to, 0, next.splice(from, 1)[0])
  return next
}

export function moveColumn(rows, from, to) {
  if (to < 0 || to >= width(rows) || from === to) return rows
  return rows.map((row) => {
    const next = row.slice()
    next.splice(to, 0, next.splice(from, 1)[0])
    return next
  })
}

export function setCell(rows, row, column, value) {
  return rows.map((cells, i) => (i === row ? cells.map((cell, j) => (j === column ? value : cell)) : cells))
}

/** Spreadsheet-style name of a cell: A1, B3, AA12. */
export function cellName(row, column) {
  let letters = ''
  for (let n = column + 1; n > 0; n = Math.floor((n - 1) / 26)) letters = String.fromCharCode(65 + ((n - 1) % 26)) + letters
  return `${letters}${row + 1}`
}

export function columnName(column) {
  return cellName(0, column).replace(/\d+$/, '')
}

const escapeHtml = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

/**
 * Cells of text copied from a spreadsheet: tab-separated columns, one row per
 * line, and quoted cells that may hold tabs, line breaks and doubled quotes.
 * Cell text becomes inline HTML, its line breaks <br>.
 */
export function parseTsv(text) {
  const rows = []
  let row = []
  let cell = ''
  let quoted = false
  const source = String(text ?? '').replace(/\r\n?/g, '\n').replace(/\n$/, '')
  for (let i = 0; i < source.length; i++) {
    const char = source[i]
    if (quoted) {
      if (char === '"' && source[i + 1] === '"') { cell += '"'; i++ }
      else if (char === '"') quoted = false
      else cell += char
    } else if (char === '"' && cell === '') quoted = true
    else if (char === '\t') { row.push(cell); cell = '' }
    else if (char === '\n') { row.push(cell); rows.push(row); row = []; cell = '' }
    else cell += char
  }
  row.push(cell)
  rows.push(row)
  const columns = Math.min(MAX_COLUMNS, Math.max(...rows.map((cells) => cells.length)))
  return rows.slice(0, MAX_ROWS).map((cells) => Array.from({ length: columns }, (_, j) => escapeHtml((cells[j] ?? '').trim()).replace(/\n/g, '<br>')))
}

/** Whether pasted text spans several cells, rather than being text for one cell. */
export function isMultiCell(text) {
  return /\t|\n./.test(String(text ?? '').replace(/\r\n?/g, '\n').trim())
}

/** Pasted cells written over the grid from (row, column), growing it as needed. */
export function pasteAt(rows, row, column, cells) {
  const rowCount = Math.max(rows.length, row + cells.length)
  const columnCount = Math.max(width(rows), column + width(cells))
  // resize() returns fresh rows, so they can be filled in place.
  const next = resize(rows, rowCount, columnCount)
  cells.forEach((cellsInRow, i) => cellsInRow.forEach((value, j) => {
    if (row + i < next.length && column + j < next[0].length) next[row + i][column + j] = value
  }))
  return next
}
