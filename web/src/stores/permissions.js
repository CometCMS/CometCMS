/** Narrower actions that a broader one also grants (mirrors PermissionService::IMPLIED_BY). */
export const IMPLIED_BY = {
  'site.pages': 'site.manage',
  'site.layouts': 'site.manage',
  'site.design': 'site.manage',
}

export function allowsPermission(grants = [], action, resource = null) {
  const own = allowsOwn(grants, action, resource)
  if (own !== null) return own
  return IMPLIED_BY[action] ? allowsPermission(grants, IMPLIED_BY[action], resource) : false
}

/** true/false when a grant names the action itself, null when none does. */
function allowsOwn(grants, action, resource) {
  function globMatch(pattern, value) {
    if (pattern === '*' || pattern === value) return true
    const regex = new RegExp(
      '^' + String(pattern).replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$'
    )
    return regex.test(value)
  }

  function matchesAction(grant) {
    const actions = grant.actions ?? []
    return actions.some((a) => globMatch(a, action))
  }

  function matchesResource(grant) {
    if (resource === null) return true
    const resources = grant.resources ?? []
    return resources.some((r) => globMatch(r, resource))
  }

  const matches = (grant) => matchesAction(grant) && matchesResource(grant)

  if (grants.some((grant) => grant.effect === 'deny' && matches(grant))) return false

  return grants.some((grant) => grant.effect !== 'deny' && matches(grant)) ? true : null
}
