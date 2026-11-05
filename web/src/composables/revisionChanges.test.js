import { describe, expect, it } from 'vitest'
import { describeChange, describeChanges, VISIBLE_CHANGES } from './revisionChanges.js'

const labels = {
  t: (key, params) => (params ? `${key}(${JSON.stringify(params)})` : key),
  kindLabel: (kind) => `kind:${kind}`,
}

describe('revision change lines', () => {
  it('names the element, what it says and what happened to it', () => {
    expect(describeChange({ action: 'edited', kind: 'heading', label: 'Welcome' }, labels)).toMatchObject({
      subject: 'kind:heading', label: 'Welcome', action: 'site.history.change.edited', icon: 'mdi:pencil-outline',
    })
    expect(describeChange({ action: 'added', kind: 'section', label: '' }, labels)).toMatchObject({ subject: 'kind:section', label: '', action: 'site.history.change.added' })
  })

  it('reads theme groups, entry fields and page resets as their own lines', () => {
    expect(describeChange({ action: 'theme', group: 'colors' }, labels)).toMatchObject({ subject: 'site.history.group.colors', label: '', action: 'site.history.change.edited' })
    expect(describeChange({ action: 'field', kind: 'field', label: 'Title' }, labels)).toMatchObject({ subject: 'site.history.change.fieldSubject', label: 'Title' })
    expect(describeChange({ action: 'reset', kind: '', label: '' }, labels)).toMatchObject({ subject: 'site.history.change.reset', action: '' })
  })

  it('folds long lists and counts what the server left out', () => {
    const items = Array.from({ length: 5 }, (_, index) => ({ action: 'styled', kind: 'button', label: `B${index}` }))
    const folded = describeChanges({ items, total: 20 }, labels)
    expect(folded.lines).toHaveLength(VISIBLE_CHANGES)
    expect(folded.hidden).toBe(20 - VISIBLE_CHANGES)
    const open = describeChanges({ items, total: 20 }, labels, true)
    expect(open.lines).toHaveLength(5)
    expect(open.hidden).toBe(15)
    expect(describeChanges({ items: [], total: 0 }, labels)).toEqual({ lines: [], hidden: 0, total: 0 })
    expect(describeChanges(null, labels)).toBeNull()
  })
})
