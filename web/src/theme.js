import { ref } from 'vue'

export const DEFAULT_THEME = 'blue'
export const DEFAULT_MODE = 'light'

/** Accent colors. `logo` is the sidebar logo per resolved mode. */
export const THEMES = [
  { value: 'blue', label: 'Blue', logo: { light: 'white-text/cms-logo-blue.png', dark: 'white-text/cms-logo-blue.png' } },
  { value: 'green', label: 'Green', logo: { light: 'white-text/cms-logo-green.png', dark: 'white-text/cms-logo-green.png' } },
  { value: 'purple', label: 'Purple', logo: { light: 'white-text/cms-logo-purple.png', dark: 'white-text/cms-logo-purple.png' } },
  // Orange's light mode has a white sidebar, so it needs the dark-text logo.
  { value: 'orange', label: 'Orange', logo: { light: 'dark-text/cms-logo-orange.png', dark: 'white-text/cms-logo-orange.png' } },
  { value: 'red', label: 'Red', logo: { light: 'white-text/cms-logo-red.png', dark: 'white-text/cms-logo-red.png' } },
  { value: 'cyan', label: 'Cyan', logo: { light: 'white-text/cms-logo-cyan.png', dark: 'white-text/cms-logo-cyan.png' } },
]

/** Color modes; `auto` follows the operating system. */
export const MODES = ['light', 'dark', 'auto']

/** The mode actually shown ('light' or 'dark'), reactive so `auto` can update the UI. */
export const resolvedMode = ref(DEFAULT_MODE)

export function normalizeTheme(theme) {
  const value = String(theme ?? '').toLowerCase()

  return THEMES.some((item) => item.value === value) ? value : DEFAULT_THEME
}

export function normalizeMode(mode, theme = null) {
  const value = String(mode ?? '').toLowerCase()
  if (MODES.includes(value)) return value

  // Before modes existed, "dark" was a theme of its own.
  return String(theme ?? '').toLowerCase() === 'dark' ? 'dark' : DEFAULT_MODE
}

let currentMode = DEFAULT_MODE
let systemQuery = null

function prefersDark() {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false
  if (!systemQuery) {
    systemQuery = window.matchMedia('(prefers-color-scheme: dark)')
    systemQuery.addEventListener?.('change', () => {
      if (currentMode === 'auto') applyResolvedMode()
    })
  }

  return systemQuery.matches
}

function applyResolvedMode() {
  const resolved = currentMode === 'auto' ? (prefersDark() ? 'dark' : 'light') : currentMode
  resolvedMode.value = resolved
  document.documentElement.dataset.mode = resolved
}

/** Apply an accent color and a color mode to the admin UI. */
export function applyTheme(theme, mode = null) {
  document.documentElement.dataset.theme = normalizeTheme(theme)
  currentMode = normalizeMode(mode, theme)
  applyResolvedMode()
}

export function logoForTheme(theme, mode = resolvedMode.value, assetBase = '') {
  const normalized = normalizeTheme(theme)
  const config = THEMES.find((item) => item.value === normalized)

  return `${assetBase}img/${config?.logo[mode === 'dark' ? 'dark' : 'light'] ?? 'white-text/cms-logo-blue.png'}`
}
