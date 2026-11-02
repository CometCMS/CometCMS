/**
 * Selectable website themes. A theme is a complete set of design tokens:
 * built in, saved by a user, or seeded by an extension (see
 * SiteRepository::themePresets()). The site's design records the active one
 * as `active_theme` ("builtin:{id}" or "preset:{id}"); edits customize it
 * until another theme is applied or the active one is reset.
 */

export const DEFAULT_THEME = 'builtin:clean'

const clone = (value) => JSON.parse(JSON.stringify(value))

/**
 * Every theme the design panel offers. Site themes are editable in place;
 * extension themes change only through their extension's updates.
 *
 * @returns {Array<{ref: string, id: string, source: 'builtin'|'preset', name: string, extension: string, extensionTitle: string, editable: boolean, theme: object}>}
 */
export function themeList(data, t) {
  const presets = (data?.theme_presets ?? []).map((preset) => ({
    ref: `preset:${preset.id}`,
    id: preset.id,
    source: 'preset',
    name: preset.name,
    extension: preset.extension ?? '',
    extensionTitle: preset.extension_title || preset.extension || '',
    editable: !preset.extension,
    theme: preset.theme,
  }))
  const builtIn = (data?.builtin_themes ?? []).map((theme) => ({
    ref: `builtin:${theme.id}`,
    id: theme.id,
    source: 'builtin',
    name: t(`site.theme.preset.${theme.id}`),
    extension: '',
    extensionTitle: '',
    editable: false,
    theme: theme.theme,
  }))
  return [...presets, ...builtIn]
}

/**
 * The design that applying `definition` as `ref` makes of `current`. Custom
 * CSS is site-owned and stays. Custom colors and shadows the current design
 * adds stay too, so elements using them keep working; the theme's own ones
 * are reset. Key order follows `current` so an unchanged design compares equal.
 */
export function applyTheme(definition, current, ref) {
  const next = clone(definition)
  const merged = {
    ...next,
    custom_colors: { ...(current.custom_colors ?? {}), ...(next.custom_colors ?? {}) },
    shadows: { ...(current.shadows ?? {}), ...(next.shadows ?? {}) },
    custom_css: current.custom_css ?? '',
    active_theme: ref,
  }
  if ('updated_at' in current) merged.updated_at = current.updated_at
  const ordered = {}
  for (const key of Object.keys(current)) if (key in merged) ordered[key] = merged[key]
  return Object.assign(ordered, merged)
}

/**
 * Whether `current` holds changes that applying another theme, or resetting
 * the active one, would discard. Without a definition (its theme was
 * deleted) the whole design counts as changed.
 */
export function themeModified(current, definition) {
  if (!current) return false
  if (!definition) return true
  return !sameDesign(current, applyTheme(definition, current, current.active_theme))
}

/**
 * Deep equality regardless of key order; empty values (null, '', missing) all
 * inherit and match. PHP sends empty maps such as custom_colors as [], so an
 * empty list equals an empty object.
 */
export function sameDesign(a, b) {
  const empty = (value) => value === null || value === undefined || value === ''
  if (empty(a) && empty(b)) return true
  if (Array.isArray(a) && a.length === 0) a = {}
  if (Array.isArray(b) && b.length === 0) b = {}
  if (Array.isArray(a) || Array.isArray(b)) {
    return Array.isArray(a) && Array.isArray(b) && a.length === b.length && a.every((value, index) => sameDesign(value, b[index]))
  }
  if (a && b && typeof a === 'object' && typeof b === 'object') {
    const keys = new Set([...Object.keys(a), ...Object.keys(b)])
    for (const key of keys) if (!sameDesign(a[key], b[key])) return false
    return true
  }
  return a === b
}

/** Colors for a theme's preview strip, from the palette its mode shows first. */
export function themeSwatches(theme, defaults) {
  const key = theme?.mode === 'dark' ? 'dark_colors' : 'colors'
  const colors = { ...(defaults?.[key] ?? {}), ...(theme?.[key] ?? {}) }
  return [colors.background, colors.surface, colors.primary, colors.accent, colors.text]
}
