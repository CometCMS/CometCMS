/** Shared choices of the design panel's typography and control settings, matching Theme.php. */

export const WEIGHTS = [100, 200, 300, 400, 500, 600, 700, 800, 900]

export const TRANSFORMS = [
  { value: '', label: '—' },
  { value: 'uppercase', label: 'AB' },
  { value: 'lowercase', label: 'ab' },
  { value: 'capitalize', label: 'Ab' },
]

/** Theme::DENSITIES: padding factors of buttons and form fields. */
const DENSITY_FACTORS = { compact: 0.8, normal: 1, relaxed: 1.25 }
export const DENSITIES = Object.keys(DENSITY_FACTORS)

export function densityFactor(value) {
  return DENSITY_FACTORS[value] ?? 1
}

/** Corner choices of a control: the theme's default corner, then fixed ones. */
export function cornerOptions(t) {
  return [
    { value: '', label: t('site.theme.cornerAuto'), title: t('site.theme.defaultCorner') },
    { value: 'none', label: '0' },
    { value: 'sm', label: 'S' },
    { value: 'md', label: 'M' },
    { value: 'lg', label: 'L' },
    { value: 'full', label: t('site.theme.round') },
  ]
}

/** A corner choice in px for previews; '' resolves the theme's default corner. */
export function cornerPx(theme, radius) {
  const value = radius || theme.radius || 'md'
  if (value === 'none') return '0px'
  if (value === 'full') return '9999px'
  return `${theme.radii?.[value] ?? 8}px`
}

/** Black or white text for a background, as Theme::contrastColor picks it. */
export function contrastColor(hex) {
  const match = /^#([0-9a-f]{6})$/i.exec(hex ?? '')
  if (!match) return '#ffffff'
  const channel = (i) => {
    const c = parseInt(match[1].slice(i, i + 2), 16) / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  }
  const luminance = 0.2126 * channel(0) + 0.7152 * channel(2) + 0.0722 * channel(4)
  return luminance > 0.4 ? '#0f172a' : '#ffffff'
}
