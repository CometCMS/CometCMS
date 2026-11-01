import { newId, locate, findNode, contains, ancestors } from './layoutTree.js'

export const DEFAULT_MENU_LIMITS = { max_depth: 5, max_siblings: 60, max_menus: 20 }

export function createMenuItem() {
  return { id: newId(), label: '', link: { type: 'home', new_tab: false }, icon: '', children: [] }
}

export function itemCount(items) {
  return items.reduce((total, item) => total + 1 + itemCount(item.children ?? []), 0)
}

export function subtreeDepth(item) {
  return 1 + Math.max(0, ...(item.children ?? []).map(subtreeDepth))
}

/** Return the destination list and insertion offset without changing the tree. */
export function menuDrop(items, id, targetId, position, limits = DEFAULT_MENU_LIMITS) {
  const source = locate(items, id)
  const target = targetId ? locate(items, targetId) : null
  if (!source || (targetId && !target) || !['before', 'after', 'inside'].includes(position)) return null
  const item = source.list[source.index]
  if (target && (id === targetId || contains(item, targetId))) return null
  const parent = position === 'inside' ? (target ? target.list[target.index] : null) : target?.parent
  if (!target && position !== 'inside') return null
  const list = parent ? parent.children : items
  if (list !== source.list && list.length >= limits.max_siblings) return null
  const depth = parent ? ancestors(items, parent.id).length + 2 : 1
  if (depth + subtreeDepth(item) - 1 > limits.max_depth) return null
  let index = position === 'inside' ? list.length : target.index + (position === 'after' ? 1 : 0)
  if (list === source.list && source.index < index) index--
  return { source, list, index }
}

export function moveMenuItem(items, id, targetId, position, limits = DEFAULT_MENU_LIMITS) {
  const drop = menuDrop(items, id, targetId, position, limits)
  if (!drop) return false
  const [item] = drop.source.list.splice(drop.source.index, 1)
  drop.list.splice(drop.index, 0, item)
  return true
}

export function menuMoveTarget(items, id, action) {
  const found = locate(items, id)
  if (!found) return null
  const { list, index, parent } = found
  if (action === 'up' && index > 0) return [list[index - 1].id, 'before']
  if (action === 'down' && index < list.length - 1) return [list[index + 1].id, 'after']
  if (action === 'indent' && index > 0) return [list[index - 1].id, 'inside']
  if (action === 'outdent' && parent) return [parent.id, 'after']
  return null
}

export { findNode, locate, ancestors }
