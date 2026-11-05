/**
 * Readable lines for the changes a history entry lists (see the server's
 * RevisionChanges): what a save added, removed, edited, restyled or moved.
 */

const LOOKS = {
  added: { icon: 'mdi:plus-circle-outline', tone: 'text-emerald-600' },
  removed: { icon: 'mdi:minus-circle-outline', tone: 'text-red-500' },
  edited: { icon: 'mdi:pencil-outline', tone: 'text-sky-600' },
  styled: { icon: 'mdi:palette-outline', tone: 'text-violet-500' },
  moved: { icon: 'mdi:swap-vertical', tone: 'text-slate-400' },
  field: { icon: 'mdi:form-textbox', tone: 'text-sky-600' },
  customized: { icon: 'mdi:pencil-plus-outline', tone: 'text-emerald-600' },
  reset: { icon: 'mdi:restore', tone: 'text-amber-600' },
  theme: { icon: 'mdi:palette-swatch-outline', tone: 'text-violet-500' },
}

/** Changes shown before the list folds. */
export const VISIBLE_CHANGES = 3

/**
 * @param {{action: string, kind?: string, label?: string, group?: string}} change
 * @param {{t: Function, kindLabel: (kind: string) => string}} labels
 * @returns {{key: string, icon: string, tone: string, subject: string, label: string, action: string}}
 */
export function describeChange(change, { t, kindLabel }) {
  const look = LOOKS[change.action] ?? LOOKS.edited
  const base = { icon: look.icon, tone: look.tone, label: change.label ?? '' }
  switch (change.action) {
    case 'theme':
      return { ...base, key: `theme:${change.group}`, subject: t(`site.history.group.${change.group}`), label: '', action: t('site.history.change.edited') }
    case 'field':
      return { ...base, key: `field:${change.label}`, subject: t('site.history.change.fieldSubject'), action: t('site.history.change.edited') }
    case 'customized':
    case 'reset':
      return { ...base, key: change.action, subject: t(`site.history.change.${change.action}`), label: '', action: '' }
    default:
      return { ...base, key: `${change.action}:${change.kind}:${change.label}`, subject: change.kind ? kindLabel(change.kind) : '', action: t(`site.history.change.${change.action}`) }
  }
}

/**
 * The lines for one revision, or null when the server did not compare it.
 *
 * @returns {null | {lines: Array, hidden: number, total: number}}
 */
export function describeChanges(changes, labels, expanded = false) {
  if (!changes || !Array.isArray(changes.items)) return null
  const all = changes.items.map((change) => describeChange(change, labels))
  const lines = expanded ? all : all.slice(0, VISIBLE_CHANGES)
  return { lines, hidden: Math.max(0, (changes.total ?? all.length) - lines.length), total: changes.total ?? all.length }
}
