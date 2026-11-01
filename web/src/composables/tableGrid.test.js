import { describe, expect, it } from 'vitest'
import {
  MAX_COLUMNS, cellName, columnName, duplicateColumn, duplicateRow, insertColumn, insertRow, isMultiCell,
  moveColumn, moveRow, parseTsv, pasteAt, removeColumn, removeRow, resize, setCell,
} from './tableGrid.js'

const grid = [['a', 'b'], ['c', 'd']]

describe('table grid', () => {
  it('resizes to a rectangle, keeping cells and clamping to the limits', () => {
    expect(resize(grid, 3, 1)).toEqual([['a'], ['c'], ['']])
    expect(resize(grid, 0, 99)[0]).toHaveLength(MAX_COLUMNS)
    expect(resize(grid, 0, 99)).toHaveLength(1)
  })

  it('inserts, duplicates, moves and removes rows and columns without touching the input', () => {
    expect(insertRow(grid, 1)).toEqual([['a', 'b'], ['', ''], ['c', 'd']])
    expect(insertColumn(grid, 0)).toEqual([['', 'a', 'b'], ['', 'c', 'd']])
    expect(duplicateRow(grid, 0)).toEqual([['a', 'b'], ['a', 'b'], ['c', 'd']])
    expect(duplicateColumn(grid, 1)).toEqual([['a', 'b', 'b'], ['c', 'd', 'd']])
    expect(moveRow(grid, 0, 1)).toEqual([['c', 'd'], ['a', 'b']])
    expect(moveColumn(grid, 1, 0)).toEqual([['b', 'a'], ['d', 'c']])
    expect(moveRow(grid, 0, -1)).toBe(grid)
    expect(removeRow(grid, 0)).toEqual([['c', 'd']])
    expect(removeColumn(grid, 1)).toEqual([['a'], ['c']])
    expect(removeRow([['x']], 0)).toEqual([['x']])
    expect(setCell(grid, 1, 0, 'z')).toEqual([['a', 'b'], ['z', 'd']])
    expect(grid).toEqual([['a', 'b'], ['c', 'd']])
  })

  it('copies bindings when duplicating so edits stay separate', () => {
    const binding = { type: 'reference', source: 'site', path: ['title'], filters: [] }
    const copy = duplicateRow([[binding]], 0)
    expect(copy[1][0]).toEqual(binding)
    expect(copy[1][0]).not.toBe(binding)
  })

  it('names cells like a spreadsheet', () => {
    expect(cellName(0, 0)).toBe('A1')
    expect(cellName(2, 1)).toBe('B3')
    expect(columnName(26)).toBe('AA')
  })

  it('reads spreadsheet clipboard text with quoted cells and escapes it as HTML', () => {
    expect(parseTsv('Plan\tPrice\r\nBasic\t9 €\r\n')).toEqual([['Plan', 'Price'], ['Basic', '9 €']])
    expect(parseTsv('"Two\nlines"\t"Say ""hi"""\n<b>\t')).toEqual([['Two<br>lines', 'Say "hi"'], ['&lt;b&gt;', '']])
    expect(parseTsv('a\tb\tc\nd')).toEqual([['a', 'b', 'c'], ['d', '', '']])
  })

  it('tells several pasted cells from text for one cell', () => {
    expect(isMultiCell('a\tb')).toBe(true)
    expect(isMultiCell('a\nb')).toBe(true)
    expect(isMultiCell('just text\n')).toBe(false)
  })

  it('pastes cells from a position and grows the grid as needed', () => {
    expect(pasteAt(grid, 1, 1, [['x', 'y'], ['z', 'w']])).toEqual([['a', 'b', ''], ['c', 'x', 'y'], ['', 'z', 'w']])
    expect(grid).toEqual([['a', 'b'], ['c', 'd']])
  })
})
