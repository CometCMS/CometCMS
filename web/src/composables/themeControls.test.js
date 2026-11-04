import { describe, expect, it } from 'vitest'
import { contrastColor, cornerPx, densityFactor } from './themeControls.js'

describe('theme control helpers', () => {
  it('picks the same text color as Theme::contrastColor', () => {
    expect(contrastColor('#2563eb')).toBe('#ffffff')
    expect(contrastColor('#f59e0b')).toBe('#0f172a')
    expect(contrastColor('#ffffff')).toBe('#0f172a')
    expect(contrastColor('nope')).toBe('#ffffff')
  })

  it('resolves corners and densities like the stylesheet', () => {
    const theme = { radius: 'lg', radii: { sm: 4, md: 8, lg: 14 } }
    expect(cornerPx(theme, '')).toBe('14px')
    expect(cornerPx(theme, 'sm')).toBe('4px')
    expect(cornerPx(theme, 'none')).toBe('0px')
    expect(cornerPx({ ...theme, radius: 'full' }, '')).toBe('9999px')
    expect(densityFactor('relaxed')).toBe(1.25)
    expect(densityFactor('bogus')).toBe(1)
  })
})
