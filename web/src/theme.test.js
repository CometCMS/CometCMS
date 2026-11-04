import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

let dataset
let query

beforeEach(() => {
  dataset = {}
  query = { matches: false, listeners: [], addEventListener(_, fn) { this.listeners.push(fn) } }
  vi.stubGlobal('document', { documentElement: { dataset }, createElement: () => ({}) })
  vi.stubGlobal('window', { matchMedia: () => query })
})

afterEach(() => { vi.unstubAllGlobals(); vi.resetModules() })

describe('admin theme', () => {
  it('combines any color with light or dark mode', async () => {
    const { applyTheme, logoForTheme, resolvedMode } = await import('./theme.js')

    applyTheme('green', 'dark')
    expect(dataset).toEqual({ theme: 'green', mode: 'dark' })
    expect(resolvedMode.value).toBe('dark')

    applyTheme('orange', 'light')
    expect(logoForTheme('orange')).toBe('img/dark-text/cms-logo-orange.png')
    applyTheme('orange', 'dark')
    expect(logoForTheme('orange')).toBe('img/white-text/cms-logo-orange.png')
  })

  it('reads the former dark theme as dark mode with the default color', async () => {
    const { applyTheme } = await import('./theme.js')

    applyTheme('dark')
    expect(dataset).toEqual({ theme: 'blue', mode: 'dark' })
  })

  it('follows the system preference in auto mode', async () => {
    const { applyTheme, resolvedMode } = await import('./theme.js')

    applyTheme('red', 'auto')
    expect(dataset.mode).toBe('light')

    query.matches = true
    query.listeners.forEach((fn) => fn())
    expect(dataset.mode).toBe('dark')
    expect(resolvedMode.value).toBe('dark')

    applyTheme('red', 'light')
    query.matches = false
    query.listeners.forEach((fn) => fn())
    query.matches = true
    query.listeners.forEach((fn) => fn())
    expect(dataset.mode).toBe('light')
  })
})
