<template>
  <div ref="container" class="relative" @keydown.esc.stop.prevent="close()" @focusout="onFocusOut">
    <button
      ref="trigger" type="button" role="combobox" aria-haspopup="listbox"
      :aria-expanded="open" :aria-controls="listId" :aria-label="t('site.link.entry')"
      class="form-input flex w-full items-center justify-between gap-2 rounded-lg border-slate-300 text-left text-sm"
      @click="open ? close() : show()" @keydown.down.prevent="show()" @keydown.up.prevent="show()"
    >
      <span class="truncate">{{ selectedTitle || modelValue || t('site.link.chooseEntry') }}</span>
      <Icon icon="mdi:chevron-down" class="h-4 w-4 shrink-0 text-slate-400" />
    </button>
    <div v-if="selected" class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
      <span class="break-all">{{ selected.slug }}</span>
      <span :class="statusPillClass(effectiveStatus(selected))">{{ t(`status.${effectiveStatus(selected)}`) }}</span>
    </div>
    <p v-if="selected && !isLive" class="mt-1 text-xs text-amber-700">{{ t('site.link.notLive') }}</p>
    <div v-if="selectedError" class="mt-1 text-xs text-amber-700" role="status">
      {{ t(selectedError.status === 403 ? 'site.link.noPermission' : 'site.link.unavailable') }}
      <button type="button" class="underline" @click="retrySelected">{{ t('site.link.retry') }}</button>
    </div>
    <div v-if="open" class="absolute z-50 mt-1 w-full min-w-0 rounded-lg border border-slate-200 bg-white shadow-lg">
      <div class="border-b border-slate-100 p-2">
        <input ref="search" v-model="query" type="search" role="combobox" aria-autocomplete="list"
          :aria-expanded="true" :aria-controls="listId" :aria-activedescendant="active >= 0 ? `${listId}-${active}` : undefined"
          :placeholder="t('select.search')" :aria-label="t('select.searchOptions')"
          class="form-input w-full rounded-lg border-slate-300 text-sm"
          @keydown.down.prevent="step(1)" @keydown.up.prevent="step(-1)"
          @keydown.enter.prevent="choose(entries[active])" />
      </div>
      <ul :id="listId" role="listbox" :aria-label="t('site.link.entry')" :aria-busy="loading" class="max-h-64 overflow-auto p-1">
        <li v-for="(entry, index) in entries" :id="`${listId}-${index}`" :key="identifierFor(entry)"
          role="option" :aria-selected="matches(entry)"
          class="cursor-pointer rounded-md px-2 py-2 text-sm"
          :class="index === active ? 'bg-theme-50 text-theme-800' : 'text-slate-700 hover:bg-slate-50'"
          @mousedown.prevent @click="choose(entry)" @mousemove="active = index">
          <div class="flex items-start justify-between gap-2">
            <span class="min-w-0 break-words font-medium">{{ entryTitle(entry, locale) }}</span>
            <Icon v-if="matches(entry)" icon="mdi:check" class="h-4 w-4 shrink-0" />
          </div>
        </li>
      </ul>
      <p v-if="loading" class="px-3 py-2 text-xs text-slate-500" role="status">{{ t('common.loading') }}</p>
      <div v-else-if="error" class="px-3 py-2 text-xs text-red-700" role="alert">
        {{ t(error.status === 403 ? 'site.link.noPermission' : 'site.link.loadFailed') }}
        <button type="button" class="underline" @click="retry">{{ t('site.link.retry') }}</button>
      </div>
      <p v-else-if="!entries.length" class="px-3 py-3 text-xs text-slate-500" role="status">{{ t('select.noResults') }}</p>
      <button v-if="!loading && !error && entries.length < total" type="button"
        class="w-full border-t border-slate-100 px-3 py-2 text-sm text-theme-700 hover:bg-theme-50" @click="loadMore">
        {{ t('site.link.loadMore', { shown: entries.length, total }) }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, toRef, useId, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from '../../i18n/index.js'
import { useLinkEntries } from '../../composables/useLinkEntries.js'
import { entryTitle } from '../../composables/siteLinks.js'
import { effectiveStatus, statusPillClass } from '../../composables/contentDisplay.js'

const props = defineProps({
  modelValue: { type: String, default: '' }, collection: { type: String, required: true },
  locale: { type: String, default: '' }, context: { type: String, default: '' },
  onlyIdentifier: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'select', 'resolved'])
const { t } = useI18n()
const { entries, selected, selectedTitle, total, query, loading, error, selectedError, loadMore, retry, retrySelected, identifierFor } = useLinkEntries({
  collection: toRef(props, 'collection'), identifier: toRef(props, 'modelValue'), locale: toRef(props, 'locale'), context: toRef(props, 'context'),
  onlyIdentifier: toRef(props, 'onlyIdentifier'),
})
const isLive = computed(() => selected.value && effectiveStatus(selected.value) === 'published')
const open = ref(false)
const container = ref(null)
const trigger = ref(null)
const search = ref(null)
const active = ref(-1)
const listId = `${useId()}-entries`
watch(selected, (entry) => { if (entry) emit('resolved', entry) })
watch(entries, () => { active.value = entries.value.length ? 0 : -1 })
const matches = (entry) => [entry.uid, entry.slug, entry.id].filter(Boolean).includes(props.modelValue)
async function show() {
  open.value = true
  active.value = entries.value.findIndex(matches)
  if (active.value < 0 && entries.value.length) active.value = 0
  await nextTick()
  search.value?.focus()
}
function close(restore = true) {
  open.value = false
  if (restore) trigger.value?.focus()
}
async function step(delta) {
  active.value = Math.max(0, Math.min(entries.value.length - 1, active.value + delta))
  await nextTick()
  container.value?.querySelector(`[id="${listId}-${active.value}"]`)?.scrollIntoView({ block: 'nearest' })
}
function choose(entry) {
  if (!entry) return
  emit('update:modelValue', identifierFor(entry))
  emit('select', entry)
  close()
}
function onFocusOut(event) {
  if (!container.value?.contains(event.relatedTarget)) close(false)
}
function outside(event) {
  if (!container.value?.contains(event.target)) close(false)
}
onMounted(() => document.addEventListener('pointerdown', outside))
onBeforeUnmount(() => document.removeEventListener('pointerdown', outside))
</script>
