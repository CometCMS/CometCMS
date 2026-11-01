import { describe, expect, it } from 'vitest'
import { DEFAULT_ICON, barOptions, numberBar } from './controlOptions.js'

const title = (value) => (value === '' ? 'Default' : value)
const theme = {
  space: { xs: 4, sm: 8, md: 16, lg: 32, xl: 56, '2xl': 96 },
  radii: { sm: 4, md: 8, lg: 14 },
  radius: 'lg',
}

describe('builder option bars', () => {
  it('offers distinct icons for flow, free and fixed positioning', () => {
    const options = barOptions('style', 'position', ['', 'static', 'absolute', 'fixed'], { title })
    expect(options.map((option) => option.icon)).toEqual([DEFAULT_ICON, 'mdi:format-align-justify', 'mdi:pin-outline', 'mdi:pin'])
  })

  it('mixes icons and token labels for corners, with theme values as hints', () => {
    const options = barOptions('style', 'radius', ['', 'none', 'theme', 'sm', 'md', 'lg', 'full'], { title, theme })
    expect(options.map((option) => option.icon ?? option.label)).toEqual([
      DEFAULT_ICON, 'mdi:square-outline', 'mdi:square-rounded-outline', 'S', 'M', 'L', 'mdi:circle-outline',
    ])
    expect(options.find((option) => option.value === 'md').title).toBe('md · 8px')
    expect(options.find((option) => option.value === 'theme').title).toBe('theme · 14px')
  })

  it('shows spacing tokens for padding and spacers, not for button sizes', () => {
    const padding = barOptions('style', 'padding_top', ['', 'none', 'xs', 'sm', 'md'], { title, theme })
    expect(padding.map((option) => option.label)).toEqual(['–', '0', 'XS', 'S', 'M'])
    expect(padding[4].title).toBe('md · 16px')

    expect(barOptions('props', 'size', ['xs', 'md'], { title, theme, kind: 'spacer' })[1].title).toBe('md · 16px')
    expect(barOptions('props', 'size', ['sm', 'md', 'lg'], { title, theme, kind: 'button' })[1].title).toBe('md')
  })

  it('picks border sides by icon and previews line styles', () => {
    expect(barOptions('style', 'border', ['left', 'top', 'right', 'bottom'], { title }).map((option) => option.icon)).toEqual([
      'mdi:border-left-variant', 'mdi:border-top-variant', 'mdi:border-right-variant', 'mdi:border-bottom-variant',
    ])
    const styles = barOptions('style', 'border_style', ['solid', 'dashed', 'double'], { title })
    expect(styles.map((option) => option.style.borderTop)).toEqual(['2px solid currentColor', '2px dashed currentColor', '4px double currentColor'])
  })

  it('shows animations and their timing as icons', () => {
    const animations = barOptions('style', 'animation', ['none', 'fade', 'fade-up', 'zoom-in', 'blur'], { title })
    expect(animations.map((option) => option.icon)).toEqual([DEFAULT_ICON, 'mdi:circle-opacity', 'mdi:arrow-up', 'mdi:magnify-plus-outline', 'mdi:blur'])
    expect(barOptions('style', 'animation_duration', ['fast', 'normal', 'slow'], { title }).every((option) => option.icon)).toBe(true)
    expect(numberBar(0, 6).map((option) => option.label)).toEqual(['0', '1', '2', '3', '4', '5', '6'])
  })

  it('falls back when a bar would not fit', () => {
    expect(barOptions('props', 'tag', ['', 'p', 'h1', 'span', 'div'], { title })).toBeNull()
    expect(numberBar(1, 6)).toHaveLength(6)
    expect(numberBar(1, 100)).toBeNull()
  })
})
