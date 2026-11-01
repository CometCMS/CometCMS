import { describe, expect, it } from 'vitest'
import { needsPreviewReload, withPreviewStyles } from './previewDocument.js'

describe('scripted preview updates', () => {
  const plain = '<html><head></head><body>Preview</body></html>'
  const scripted = '<html><body><div data-comet-block="example/map"></div><script type="module">import mount from "/map.js";</script></body></html>'

  it('reloads when adding a script, so its module executes and its CSP takes effect', () => {
    expect(needsPreviewReload({ querySelector: () => null }, scripted)).toBe(true)
  })

  it('reloads when updating or removing scripted blocks to discard listeners and timers', () => {
    const doc = { querySelector: () => ({}) }
    expect(needsPreviewReload(doc, scripted)).toBe(true)
    expect(needsPreviewReload(doc, plain)).toBe(true)
  })

  it('keeps ordinary preview updates in place', () => {
    expect(needsPreviewReload({ querySelector: () => null }, plain)).toBe(false)
  })
})

describe('preview document styles', () => {
  it('includes editor outlines in the head before any builder nodes are parsed', () => {
    const html = '<!doctype html><html><head><style>body{color:red}</style></head><body><div data-node="row"></div></body></html>'
    const css = '[data-node]{outline:1px solid transparent;transition:outline-color .12s}'
    const result = withPreviewStyles(html, css)
    expect(result).toContain(`<style>body{color:red}</style><style data-preview-styles>${css}</style></head>`)
    expect(result.indexOf(css)).toBeLessThan(result.indexOf('<body>'))
    expect(result).toContain('<div data-node="row"></div>')
  })

  it('preserves ordinary previews and empty documents', () => {
    const html = '<html><head></head><body>Preview</body></html>'
    expect(withPreviewStyles(html, '')).toBe(html)
    expect(withPreviewStyles('', 'body{color:red}')).toBe('')
  })

  it('handles uppercase head tags and literal CSS replacement characters', () => {
    expect(withPreviewStyles('<HEAD></HEAD><BODY></BODY>', '.label::before{content:"$&"}'))
      .toContain('<style data-preview-styles>.label::before{content:"$&"}</style></head><BODY>')
  })
})
