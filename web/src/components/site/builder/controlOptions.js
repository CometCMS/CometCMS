// How builder options are presented: icon bars for directions and edges,
// compact size scales. Anything not covered here falls
// back to a segmented control or a select.

/** "Use the default" — shared by every bar that has an empty option. */
export const DEFAULT_ICON = 'mdi:circle-off-outline'

const ICONS = {
  'style.align': { left: 'mdi:format-align-left', center: 'mdi:format-align-center', right: 'mdi:format-align-right' },
  'props.vertical_align': { start: 'mdi:format-vertical-align-top', center: 'mdi:format-vertical-align-center', end: 'mdi:format-vertical-align-bottom' },
  'props.align': { stretch: 'mdi:arrow-expand-vertical', start: 'mdi:align-vertical-top', center: 'mdi:align-vertical-center', end: 'mdi:align-vertical-bottom' },
  'props.justify': { start: 'mdi:align-horizontal-left', center: 'mdi:align-horizontal-center', end: 'mdi:align-horizontal-right', between: 'mdi:arrow-left-right' },
  'props.direction': { column: 'mdi:arrow-down', row: 'mdi:arrow-right' },
  'props.content_justify': { start: 'mdi:align-horizontal-left', center: 'mdi:align-horizontal-center', end: 'mdi:align-horizontal-right', between: 'mdi:arrow-left-right' },
  'props.content_align': { start: 'mdi:align-vertical-top', center: 'mdi:align-vertical-center', end: 'mdi:align-vertical-bottom', stretch: 'mdi:arrow-expand-vertical' },
  'style.border': { left: 'mdi:border-left-variant', top: 'mdi:border-top-variant', right: 'mdi:border-right-variant', bottom: 'mdi:border-bottom-variant' },
  'style.radius': { none: 'mdi:square-outline', theme: 'mdi:square-rounded-outline', full: 'mdi:circle-outline' },
  'style.max_width': { narrow: 'mdi:arrow-collapse-horizontal', prose: 'mdi:text-long' },
  'props.orientation': { horizontal: 'mdi:dots-horizontal', vertical: 'mdi:dots-vertical' },
  'props.sort_order': { desc: 'mdi:sort-descending', asc: 'mdi:sort-ascending' },
  'props.fit': { cover: 'mdi:crop', contain: 'mdi:fit-to-screen-outline' },
  'props.shape': { none: DEFAULT_ICON, circle: 'mdi:circle-outline', square: 'mdi:square-outline', squircle: 'mdi:square-rounded-outline' },
  'style.animation': {
    none: DEFAULT_ICON,
    fade: 'mdi:circle-opacity',
    'fade-up': 'mdi:arrow-up',
    'fade-down': 'mdi:arrow-down',
    'fade-left': 'mdi:arrow-left',
    'fade-right': 'mdi:arrow-right',
    'zoom-in': 'mdi:magnify-plus-outline',
    'zoom-out': 'mdi:magnify-minus-outline',
    blur: 'mdi:blur',
  },
  'style.animation_trigger': { scroll: 'mdi:gesture-swipe-up', load: 'mdi:play-outline', hover: 'mdi:cursor-default-outline' },
  'style.animation_duration': { fast: 'mdi:speedometer', normal: 'mdi:speedometer-medium', slow: 'mdi:speedometer-slow' },
  'style.position': { static: 'mdi:format-align-justify', absolute: 'mdi:pin-outline', fixed: 'mdi:pin' },
  'style.overlay': { none: 'mdi:image-outline', light: 'mdi:brightness-5', dark: 'mdi:brightness-3' },
}

/** Size scales read best as short labels: – 0 XS S M L XL 2XL. */
const SCALE = { '': '–', none: '0', xs: 'XS', sm: 'S', md: 'M', lg: 'L', xl: 'XL', '2xl': '2XL' }

const WEIGHTS = { normal: 400, medium: 500, semibold: 600, bold: 700 }

/** Options whose values are theme spacing tokens. */
const SPACE_KEYS = new Set(['style.padding_top', 'style.padding_right', 'style.padding_bottom', 'style.padding_left', 'style.margin_top', 'style.margin_right', 'style.margin_bottom', 'style.margin_left', 'props.spacing'])

/** The theme value behind a token option, e.g. "16px", for tooltips. */
function tokenHint(key, value, kind, theme) {
  if (!theme) return ''
  const isSpace = SPACE_KEYS.has(key) || (key === 'props.size' && kind === 'spacer')
  if (isSpace && theme.space?.[value] !== undefined) return `${theme.space[value]}px`
  if (key !== 'style.radius') return ''
  if (theme.radii?.[value] !== undefined) return `${theme.radii[value]}px`
  if (value === 'theme') {
    if (theme.radius === 'none') return '0px'
    return theme.radii?.[theme.radius] !== undefined ? `${theme.radii[theme.radius]}px` : ''
  }
  return ''
}

export const DEVICE_ICONS = { mobile: 'mdi:cellphone', tablet: 'mdi:tablet', desktop: 'mdi:monitor' }
export const STATE_ICONS = { hover: 'mdi:cursor-default-click-outline', focus: 'mdi:keyboard-outline' }

/**
 * Bar options for an enum, or null when a bar would not fit.
 * `title(value)` returns the translated name of an option.
 */
export function barOptions(group, name, values, { title, theme = null, kind = '' }) {
  const key = `${group}.${name}`
  const describe = (value) => ({
    value,
    title: [title(value), tokenHint(key, value, kind, theme)].filter(Boolean).join(' · '),
  })

  // Icons where they say more than a word; size tokens keep their short label.
  if (ICONS[key] && values.every((value) => value === '' || ICONS[key][value] || value in SCALE)) {
    return values.map((value) => {
      if (value === '') return { ...describe(value), icon: DEFAULT_ICON }
      return ICONS[key][value] ? { ...describe(value), icon: ICONS[key][value] } : { ...describe(value), label: SCALE[value] }
    })
  }

  if (values.length <= 8 && values.every((value) => value in SCALE)) {
    return values.map((value) => ({ ...describe(value), label: SCALE[value] }))
  }

  if (values.every((value) => /^h[1-6]$/.test(value))) {
    return values.map((value) => ({ ...describe(value), label: value.toUpperCase() }))
  }

  // Each line style drawn as a short sample of itself.
  if (name === 'border_style') {
    return values.map((value) => ({
      ...describe(value),
      label: '',
      style: { display: 'inline-block', width: '1.25rem', borderTop: `${value === 'double' ? 4 : 2}px ${value} currentColor` },
    }))
  }

  if (name === 'font_weight') {
    return values.map((value) => (value === ''
      ? { ...describe(value), icon: DEFAULT_ICON }
      : { ...describe(value), label: 'Aa', style: { fontWeight: WEIGHTS[value] } }))
  }

  return null
}

/** Small integer ranges (e.g. 1–6 columns, 0–6 delay steps) as a bar of numbers. */
export function numberBar(min, max) {
  if (max - min > 6) return null
  return Array.from({ length: max - min + 1 }, (_, index) => {
    const value = min + index
    return { value, title: String(value), label: String(value) }
  })
}
