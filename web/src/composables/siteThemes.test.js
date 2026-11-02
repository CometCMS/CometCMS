import { describe, expect, it } from 'vitest'
import { applyTheme, sameDesign, themeList, themeModified } from './siteThemes.js'

const warm = {
  mode: 'light',
  colors: { primary: '#c2410c', background: '#fffbf5' },
  radius: 'lg',
  custom_colors: { brand: { label: 'Brand', color: '#111111', dark_color: '#eeeeee' } },
  shadows: { hard: { x: 6 } },
  headings: { h1: { size: null } },
}
const site = {
  active_theme: 'builtin:clean',
  mode: 'dark',
  colors: { primary: '#2563eb', background: '#ffffff' },
  radius: 'md',
  custom_colors: { brand: { label: 'Mine', color: '#222222', dark_color: '#dddddd' }, extra: { label: 'Extra', color: '#333333', dark_color: '#cccccc' } },
  shadows: { soft: { x: 1 } },
  headings: { h1: { size: null } },
  custom_css: 'body{}',
  updated_at: '2026-11-02T10:00:00Z',
}

describe('site themes', () => {
  it('lists site, extension and built-in themes with what can be edited', () => {
    const list = themeList({
      theme_presets: [{ id: 'mine', name: 'Mine', extension: '', theme: {} }, { id: 'dusk', name: 'Dusk', extension: 'acme/pack', theme: {} }],
      builtin_themes: [{ id: 'clean', theme: {} }],
    }, (key) => key)
    expect(list.map(({ ref, name, editable, extension }) => ({ ref, name, editable, extension }))).toEqual([
      { ref: 'preset:mine', name: 'Mine', editable: true, extension: '' },
      { ref: 'preset:dusk', name: 'Dusk', editable: false, extension: 'acme/pack' },
      { ref: 'builtin:clean', name: 'site.theme.preset.clean', editable: false, extension: '' },
    ])
  })

  it('applies a theme as defined, keeping custom CSS and additions elements may use', () => {
    const next = applyTheme(warm, site, 'builtin:warm')
    expect(next).toEqual({
      ...warm,
      active_theme: 'builtin:warm',
      custom_colors: { brand: warm.custom_colors.brand, extra: site.custom_colors.extra },
      shadows: { soft: { x: 1 }, hard: { x: 6 } },
      custom_css: 'body{}',
      updated_at: site.updated_at,
    })
    expect(Object.keys(next).slice(0, 3)).toEqual(['active_theme', 'mode', 'colors'])
    expect(next.colors).not.toBe(warm.colors)
  })

  it('reports only changes that switching or resetting would discard', () => {
    const applied = applyTheme(warm, site, 'builtin:warm')
    expect(themeModified(applied, warm)).toBe(false)
    expect(themeModified({ ...applied, custom_colors: { ...applied.custom_colors, added: { label: 'New', color: '#000000', dark_color: '#ffffff' } } }, warm)).toBe(false)
    expect(themeModified({ ...applied, custom_css: 'p{}' }, warm)).toBe(false)
    expect(themeModified({ ...applied, radius: 'sm' }, warm)).toBe(true)
    expect(themeModified({ ...applied, custom_colors: { ...applied.custom_colors, brand: { ...warm.custom_colors.brand, color: '#999999' } } }, warm)).toBe(true)
    expect(themeModified(applied, null)).toBe(true)
    // As the server returns it after saving: empty maps arrive as lists.
    const empty = { ...warm, custom_colors: [], shadows: [] }
    expect(themeModified({ ...applyTheme(empty, { ...site, custom_colors: [], shadows: [] }, 'builtin:warm'), custom_colors: [], shadows: [] }, empty)).toBe(false)
  })

  it('compares designs regardless of key order and empty value spelling', () => {
    expect(sameDesign({ a: 1, b: { c: null } }, { b: { c: '' }, a: 1 })).toBe(true)
    expect(sameDesign({ a: [1, 2] }, { a: [2, 1] })).toBe(false)
    expect(sameDesign({ a: 0 }, { a: '' })).toBe(false)
    expect(sameDesign({ custom_colors: [] }, { custom_colors: {} })).toBe(true)
    expect(sameDesign({ a: [] }, { a: { b: 1 } })).toBe(false)
  })
})
