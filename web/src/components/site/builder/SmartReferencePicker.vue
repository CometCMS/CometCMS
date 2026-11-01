<template>
  <div role="dialog" :aria-label="t('site.binding.choose')" class="space-y-3 rounded-lg border border-slate-200 bg-white p-3 shadow-lg" @keydown.esc.stop.prevent="$emit('close')">
    <div class="flex items-center justify-between gap-2">
      <strong class="text-xs text-slate-700">{{ t('site.binding.choose') }}</strong>
      <button type="button" class="rounded p-1 text-slate-500 hover:bg-slate-100" :aria-label="t('common.close')" @click="$emit('close')"><Icon icon="mdi:close" class="h-4 w-4" /></button>
    </div>
    <label class="block text-xs text-slate-600">
      {{ t('site.binding.source') }}
      <select ref="sourceSelect" :aria-label="t('site.binding.source')" v-model="source" class="form-select mt-1 w-full rounded-lg border-slate-300 text-xs" @change="resetSource">
        <option v-if="context.currentAvailable" value="current">{{ context.collection ? t('site.binding.cardEntry') : t('site.binding.current') }}</option>
        <option v-if="owner" value="item">{{ t('site.binding.item') }}</option>
        <option value="entry">{{ t('site.binding.entry') }}</option>
        <option value="site">{{ t('site.binding.site') }}</option>
      </select>
    </label>
    <label v-if="source === 'entry'" class="block text-xs text-slate-600">
      {{ t('site.binding.contentType') }}
      <select :aria-label="t('site.binding.contentType')" v-model="collection" class="form-select mt-1 w-full rounded-lg border-slate-300 text-xs" @change="entry = ''; path = []">
        <option value="">{{ t('site.builder.chooseType') }}</option>
        <option v-for="type in availableTypes" :key="type.name" :value="type.name">{{ type.label || type.name }}</option>
      </select>
    </label>
    <ContentLinkPicker v-if="source === 'entry' && collection" :collection="collection" :model-value="entry" :context="node.id" :locale="builder.locale.value"
      @select="chooseEntry" />
    <p v-if="missingUid" role="status" class="text-xs text-amber-700">{{ t('site.binding.missingUid') }}</p>
    <template v-if="source !== 'entry' || entry">
      <button v-if="path.length" type="button" class="inline-flex items-center gap-1 text-xs text-theme-700 hover:underline" @click="path = path.slice(0, -1)"><Icon icon="mdi:arrow-left" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ path.join(' › ') }}</button>
      <label class="sr-only" :for="searchId">{{ t('select.search') }}</label>
      <input :id="searchId" v-model="search" type="search" class="form-input w-full rounded-lg border-slate-300 text-xs" :placeholder="t('select.search')" />
      <GroupedList class="max-h-48 overflow-auto" role="group" :aria-label="t('site.binding.choose')" :groups="groups" :item-key="(field) => field.key">
        <template #default="{ item: field }">
          <div class="flex gap-1">
            <button v-if="compatibleField(field.config, target)" type="button" class="min-w-0 flex-1 rounded px-2 py-1.5 text-left text-xs hover:bg-theme-50" :aria-pressed="chosen?.path.join('.') === [...path, field.key].join('.')" :class="chosen?.path.join('.') === [...path, field.key].join('.') ? 'bg-theme-50 text-theme-700' : 'text-slate-700'" @click="choose(field)">
              {{ field.label }} <span class="ml-1 text-[10px] text-slate-400">{{ field.config.type }}</span>
            </button>
            <button v-if="canFollow(field.config)" type="button" class="flex flex-1 items-center justify-between rounded px-2 py-1.5 text-left text-xs text-slate-700 hover:bg-theme-50" :aria-label="`${t('site.binding.follow')} ${field.label}`" @click="path = [...path, field.key]; search = ''">{{ field.label }} <Icon icon="mdi:chevron-right" class="h-4 w-4" /></button>
          </div>
        </template>
        <template #empty><p class="text-xs text-slate-400">{{ t('select.noResults') }}</p></template>
      </GroupedList>
    </template>
    <template v-if="chosen">
      <p class="break-words text-xs font-medium text-theme-700">{{ smartSummary(chosen) }}</p>
      <template v-if="['text', 'html'].includes(target)">
        <label class="block text-xs text-slate-600">{{ t('site.binding.dateFormat') }}
          <select :aria-label="t('site.binding.dateFormat')" v-model="dateFormat" class="form-select mt-1 w-full rounded-lg border-slate-300 text-xs"><option value="">{{ t('site.option.auto') }}</option><option v-for="format in DATE_FORMATS" :key="format" :value="format">{{ t(`site.option.${format}`) }}</option></select>
        </label>
        <label class="block text-xs text-slate-600">{{ t('site.binding.truncate') }}<input v-model.number="truncate" type="number" min="0" max="500" class="form-input mt-1 w-full rounded-lg border-slate-300 text-xs" /></label>
      </template>
      <label v-if="target !== 'condition'" class="block text-xs text-slate-600">{{ t('site.binding.fallback') }}<input v-model="fallback" type="text" maxlength="2000" class="form-input mt-1 w-full rounded-lg border-slate-300 text-xs" /></label>
      <button type="button" class="btn-primary btn-sm w-full" @click="apply">{{ t('site.binding.insert') }}</button>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, useId, watch } from 'vue'
import { Icon } from '@iconify/vue'
import ContentLinkPicker from '../ContentLinkPicker.vue'
import { useSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { resolveLocalized } from '../../../composables/localizedValues.js'
import { useI18n } from '../../../i18n/index.js'
import { compatibleField, DATE_FORMATS, fieldGroups, smartSummary } from '../../../composables/smartValues.js'
import GroupedList from './GroupedList.vue'
const props = defineProps({ node: { type: Object, required: true }, target: { type: String, default: 'text' }, initial: { type: Object, default: null } })
const emit = defineEmits(['select', 'close'])
const builder = useSiteBuilder()
const { t } = useI18n()
const owner = computed(() => builder.isTemplateField(props.node) ? null : builder.templateOwner(props.node.id))
const context = computed(() => builder.entryContext(props.node.id))
const source = ref(props.initial?.source ?? (owner.value ? 'item' : context.value.currentAvailable ? 'current' : 'entry'))
const collection = ref(props.initial?.collection ?? '')
const entry = ref(props.initial?.entry ?? '')
const missingUid = ref(false)
const path = ref(props.initial?.path.slice(0, -1) ?? [])
const chosen = ref(props.initial)
const filters = props.initial?.filters ?? []
const fallback = ref(filters.find((filter) => filter.name === 'fallback')?.value ?? '')
const dateFormat = ref(filters.find((filter) => filter.name === 'date')?.value ?? '')
const truncate = ref(filters.find((filter) => filter.name === 'truncate')?.value ?? 0)
const search = ref('')
const sourceSelect = ref(null)
onMounted(() => sourceSelect.value?.focus())
const searchId = `reference-search-${useId()}`
const availableTypes = computed(() => builder.types.value.filter((type) => type.visibility !== 'private'))
watch([source, collection, entry], () => { chosen.value = null; });
const typeByName = (name) => builder.types.value.find((type) => type.name === name && type.visibility !== 'private')
const canFollow = (config) => config.type === 'relation' && !config.multiple && path.value.length < 5 && !!typeByName(config.target)
const shownFields = computed(() => {
  let schema = source.value === 'current' ? context.value.type : typeByName(collection.value)
  let fields = source.value === 'item' ? builder.subfieldsOf(owner.value).map((field) => ({ key: field.key, label: field.label, config: field.config })) : null
  if (source.value === 'site') fields = ['title', 'tagline', 'year'].map((key) => ({ key, label: t(`site.binding.site.${key}`), config: { type: 'text' } }))
  for (const key of path.value) {
    const config = fields?.find((field) => field.key === key)?.config ?? schema?.fields?.[key]
    schema = typeByName(config?.target)
    fields = null
  }
  if (!fields) fields = schema ? Object.entries({ ...schema.fields, title: { type: 'text', label: t('site.systemField.title') }, url: { type: 'url', label: t('site.binding.url') }, published_at: { type: 'datetime' }, created_at: { type: 'datetime' }, updated_at: { type: 'datetime' } }).map(([key, config]) => ({ key, label: resolveLocalized(config.label, builder.locale.value, builder.settings.value?.default_locale || 'en') || key, config })) : []
  const needle = search.value.toLowerCase()
  return fields.filter((field) => (compatibleField(field.config, props.target) || canFollow(field.config)) && `${field.label} ${field.key}`.toLowerCase().includes(needle))
})
/** In a card of several types or a component: the fields every type has, then each type's own. */
const groups = computed(() => fieldGroups(shownFields.value, source.value === 'current' && !path.value.length ? context.value.type : null, t('site.builder.sharedField')))
function chooseEntry(value) { missingUid.value = !value.uid; entry.value = value.uid || '' }
function resetSource() { missingUid.value = false; path.value = []; collection.value = ''; entry.value = ''; chosen.value = null; search.value = '' }
function choose(field) {
  chosen.value = { type: 'reference', source: source.value, path: [...path.value, field.key], filters: [], ...(source.value === 'entry' ? { collection: collection.value, entry: entry.value } : {}) }
}
function apply() {
  const filters = []
  if (fallback.value && props.target !== 'condition') filters.push({ name: 'fallback', value: fallback.value })
  if (['text', 'html'].includes(props.target)) {
    if (dateFormat.value) filters.push({ name: 'date', value: dateFormat.value })
    if (truncate.value > 0) filters.push({ name: 'truncate', value: Math.min(500, Math.max(1, Math.round(truncate.value))) })
  }
  emit('select', { ...chosen.value, filters })
}
</script>
