import { t } from '../i18n/index.js'

/** Permission actions grouped by the admin area they belong to, in display order. */
export const PERMISSION_ACTIONS = {
  system: [
    'dashboard.read',
    'activity.read',
    'profile.read',
    'profile.update',
    'backups.read',
    'backups.create',
    'backups.restore',
    'backups.delete',
    'webhooks.manage',
    'workspaces.read',
    'workspaces.manage',
    'updates.read',
    'updates.check',
    'updates.download',
    'updates.install',
  ],
  schema: ['schema.read', 'schema.create', 'schema.update', 'schema.delete'],
  content: [
    'content.read',
    'content.create',
    'content.update',
    'content.publish',
    'content.delete',
    'content.restore',
    'content.revisions.read',
    'content.revisions.restore',
  ],
  media: ['media.read', 'media.upload', 'media.update', 'media.delete'],
  site: ['site.manage', 'site.layouts', 'site.pages'],
  users: [
    'users.read',
    'users.create',
    'users.update',
    'users.delete',
    'tokens.read',
    'tokens.create',
    'tokens.revoke',
    'roles.read',
    'roles.create',
    'roles.update',
    'roles.delete',
  ],
}

export const PERMISSION_AREAS = Object.keys(PERMISSION_ACTIONS)

const ACTION_AREA = {
  dashboard: 'system',
  activity: 'system',
  profile: 'system',
  backups: 'system',
  webhooks: 'system',
  workspaces: 'system',
  updates: 'system',
  schema: 'schema',
  content: 'content',
  media: 'media',
  site: 'site',
  users: 'users',
  tokens: 'users',
  roles: 'users',
}

export function permissionArea(action) {
  return ACTION_AREA[String(action).split('.')[0]] ?? 'system'
}

/** Human-readable, translated label for a permission action; unknown actions show as-is. */
export function permissionActionLabel(action) {
  if (action === '*') return t('perm.everything')
  const key = `perm.action.${action}`
  const label = t(key)
  return label === key ? action : label
}

export function permissionAreaLabel(area) {
  return area === 'all' ? t('perm.everything') : t(`perm.area.${area}`)
}

/** [{ value, label }] for the actions of an area, translated for the current locale. */
export function permissionActionOptions(area) {
  if (area === 'all') return [{ value: '*', label: permissionActionLabel('*') }]
  return (PERMISSION_ACTIONS[area] ?? []).map((value) => ({ value, label: permissionActionLabel(value) }))
}
