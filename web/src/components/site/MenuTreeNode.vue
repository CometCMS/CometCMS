<template>
  <li :data-menu-row="item.id" role="treeitem" :aria-level="depth" :aria-selected="selectedId === item.id"
      :aria-label="label || t('site.menus.untitled')"
      :aria-expanded="item.children.length ? expanded : undefined" :tabindex="selectedId === item.id ? 0 : -1"
      class="rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-theme-500"
      @focus.self="$emit('select', item.id)" @keydown="onKey">
    <div
      class="group relative flex min-w-0 cursor-pointer items-center gap-1 rounded-lg border px-2 py-2 outline-none focus-visible:ring-2 focus-visible:ring-theme-500"
      :class="selectedId === item.id ? 'border-theme-200 bg-theme-50' : 'border-transparent hover:bg-slate-50'"
      @click="$emit('select', item.id, true)"
      @dragover.stop="dragOver" @dragleave="leaveDrop" @drop.prevent.stop="drop">
      <span v-if="dropPosition === 'before' || dropPosition === 'after'" class="pointer-events-none absolute inset-x-0 h-0.5 bg-theme-500" :class="dropPosition === 'before' ? '-top-px' : '-bottom-px'"></span>
      <span v-if="dropPosition === 'inside'" class="pointer-events-none absolute inset-0 rounded-lg ring-2 ring-theme-500"></span>
      <button type="button" draggable="true" class="shrink-0 cursor-grab rounded p-1 text-slate-400 hover:bg-slate-100 active:cursor-grabbing"
        :aria-label="t('site.menus.dragItem', { name: label || t('site.menus.untitled') })"
        :title="t('site.menus.dragItem', { name: label || t('site.menus.untitled') })"
        @click.stop @dragstart.stop="startDrag" @dragend="$emit('drag', '')">
        <Icon icon="mdi:drag-vertical" class="h-4 w-4" />
      </button>
      <button v-if="item.children.length" type="button" class="shrink-0 cursor-pointer rounded p-1 text-slate-500 hover:bg-slate-100"
        :aria-expanded="expanded" :aria-label="t(expanded ? 'site.builder.collapse' : 'site.builder.expand')" @click.stop="$emit('toggle', item.id)">
        <Icon icon="mdi:chevron-right" class="h-4 w-4 transition-transform" :class="{ 'rotate-90': expanded }" />
      </button>
      <span v-else class="w-6 shrink-0"></span>
      <Icon :icon="item.icon || (link.type === 'none' ? 'mdi:folder-outline' : 'mdi:link')" class="h-4 w-4 shrink-0" :class="item.icon ? 'text-slate-600' : 'text-slate-400'" />
      <div class="min-w-0 flex-1 px-1">
        <div class="flex items-center gap-2">
          <span class="truncate text-sm font-medium text-slate-800">{{ label || t('site.menus.untitled') }}</span>
          <span v-if="item.children.length" class="shrink-0 rounded bg-slate-100 px-1.5 text-xs text-slate-500" :title="t('site.menus.descendants')">{{ itemCount(item.children) }}</span>
          <Icon v-if="linkIssue(link, label)" icon="mdi:alert-circle-outline" class="h-4 w-4 shrink-0 text-amber-600" :title="t(`site.link.${linkIssue(link, label)}`)" />
        </div>
        <MenuLinkSummary explain-unavailable :link="link" :types="types" :settings="settings" :context="item.id" @default-label="setDefaultLabel" />
      </div>
      <details class="relative shrink-0" @click.stop @keydown.esc.stop.prevent="$event.currentTarget.open = false; $event.currentTarget.querySelector('summary').focus()" @focusout="closeActions">
        <summary class="flex h-8 w-8 cursor-pointer list-none items-center justify-center rounded text-slate-500 hover:bg-slate-100 [&::-webkit-details-marker]:hidden" :aria-label="t('site.menus.itemActions', { name: label || t('site.menus.untitled') })">
          <Icon icon="mdi:dots-horizontal" class="h-5 w-5" />
        </summary>
        <div class="absolute right-0 top-full z-20 w-60 rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
          <button v-for="action in actions" :key="action.value" type="button" :disabled="!action.enabled" class="flex w-full items-center gap-2 rounded px-3 py-2 text-left text-xs hover:bg-slate-50 disabled:opacity-40" :class="action.value === 'delete' ? 'text-red-700' : 'text-slate-700'" @click="runAction($event, action.value)">
            <Icon :icon="action.icon" class="h-4 w-4 shrink-0" />{{ action.label }}
          </button>
        </div>
      </details>
      <!-- Feedback stays outside document flow and leaves the row content visible. -->
      <span v-if="dropPosition === 'inside'" :data-menu-drop-child="item.id"
        class="pointer-events-none absolute -bottom-2 right-3 z-10 inline-flex max-w-[calc(100%-1.5rem)] items-center gap-1 rounded border border-theme-200 bg-theme-50 px-2 py-0.5 text-xs font-medium text-theme-700">
        <Icon icon="mdi:subdirectory-arrow-right" class="h-3.5 w-3.5 shrink-0" />
        <span class="truncate">{{ t('site.menus.dropChild') }}</span>
      </span>
    </div>
    <ul v-if="item.children.length && expanded" role="group" class="ml-6 space-y-1 border-l border-slate-200 pl-3 pt-1">
      <MenuTreeNode v-for="child in item.children" :key="child.id" :item="child" :depth="depth + 1"
        :selected-id="selectedId" :expanded-ids="expandedIds" :types="types" :settings="settings" :items="items" :limits="limits" :drag-id="dragId"
        @action="(id, action) => $emit('action', id, action)" @select="(id, edit) => $emit('select', id, edit)" @toggle="$emit('toggle', $event)" @navigate="(id, key) => $emit('navigate', id, key)"
        @drag="$emit('drag', $event)" @move="(id, target, position) => $emit('move', id, target, position)"
        @default-label="(id, value) => $emit('default-label', id, value)" />
    </ul>
  </li>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import MenuLinkSummary from './MenuLinkSummary.vue'
import { itemCount, menuDrop, menuMoveTarget } from '../../composables/menuTree.js'
import { linkIssue } from '../../composables/siteLinks.js'
// Registers the site's local icons, which items can show in their row.
import '../../composables/siteIcons.js'
import { resolveLocalized } from '../../composables/localizedValues.js'
import { useI18n } from '../../i18n/index.js'
const props = defineProps({
  item: { type: Object, required: true }, items: { type: Array, required: true }, depth: { type: Number, default: 1 },
  selectedId: { type: String, default: '' }, expandedIds: { type: Set, required: true },
  types: { type: Array, required: true }, settings: { type: Object, required: true }, limits: { type: Object, required: true }, dragId: { type: String, default: '' },
})
const emit = defineEmits(['select', 'toggle', 'navigate', 'drag', 'move', 'action', 'default-label'])
const { t } = useI18n()
const link = computed(() => resolveLocalized(props.item.link, props.settings.default_locale, props.settings.default_locale) ?? {})
const defaultLabel = ref('')
const label = computed(() => resolveLocalized(props.item.label, props.settings.default_locale, props.settings.default_locale) || defaultLabel.value)
function setDefaultLabel(value) {
  defaultLabel.value = value
  emit('default-label', props.item.id, value)
}
const expanded = computed(() => props.expandedIds.has(props.item.id))
const dropPosition = ref('')
watch(() => props.dragId, () => { dropPosition.value = '' })
const actions = computed(() => [
  { value: 'add', icon: 'mdi:subdirectory-arrow-right', label: t('site.menus.addChild'), enabled: props.depth < props.limits.max_depth && props.item.children.length < props.limits.max_siblings },
  ...[
    ['up', 'mdi:arrow-up', 'site.builder.moveUp'],
    ['down', 'mdi:arrow-down', 'site.builder.moveDown'],
    ['indent', 'mdi:format-indent-increase', 'site.menus.indent'],
    ['outdent', 'mdi:format-indent-decrease', 'site.menus.outdent'],
  ].map(([value, icon, key]) => {
    const target = menuMoveTarget(props.items, props.item.id, value)
    return { value, icon, label: t(key), enabled: !!target && !!menuDrop(props.items, props.item.id, ...target, props.limits) }
  }),
  { value: 'delete', icon: 'mdi:trash-can-outline', label: t('common.delete'), enabled: true },
])
function closeActions(event) { if (!event.currentTarget.contains(event.relatedTarget)) event.currentTarget.open = false }
function runAction(event, action) {
  const details = event.currentTarget.closest('details')
  details.open = false
  details.querySelector('summary').focus()
  emit('action', props.item.id, action)
}
function startDrag(event) {
  emit('drag', props.item.id)
  event.dataTransfer.effectAllowed = 'move'
  event.dataTransfer.setData('text/plain', props.item.id)
}
function dragOver(event) {
  if (!props.dragId) return
  const rect = event.currentTarget.getBoundingClientRect()
  const ratio = (event.clientY - rect.top) / rect.height
  const position = ratio < 0.25 ? 'before' : ratio > 0.75 ? 'after' : 'inside'
  const valid = menuDrop(props.items, props.dragId, props.item.id, position, props.limits)
  dropPosition.value = valid ? position : ''
  event.dataTransfer.dropEffect = valid ? 'move' : 'none'
  if (valid) event.preventDefault()
}
function leaveDrop(event) {
  if (!event.currentTarget.contains(event.relatedTarget)) dropPosition.value = ''
}
function drop() {
  if (dropPosition.value) emit('move', props.dragId, props.item.id, dropPosition.value)
  dropPosition.value = ''
  emit('drag', '')
}
function onKey(event) {
  if (event.target === event.currentTarget && event.key === 'Enter') { event.preventDefault(); emit('select', props.item.id, true); return }
  if (event.target !== event.currentTarget || !['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
  event.preventDefault()
  emit('navigate', props.item.id, event.key)
}
</script>
