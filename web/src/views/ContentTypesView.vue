<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-slate-900">{{ t('contentTypes.title') }}</h1>
      <router-link to="/content-types/new" class="btn-primary">
        {{ t('contentTypes.new') }}
      </router-link>
    </div>

    <LoadingSpinner v-if="loading" />

    <div v-else-if="types.length === 0" class="card p-8 text-center text-slate-500 text-sm">
      {{ t('contentTypes.empty') }}
      <router-link to="/content-types/new" class="text-theme-600 hover:underline ml-1">{{ t('contentTypes.createOne')
      }}</router-link>.
    </div>

    <div v-else class="card overflow-hidden">
      <table class="w-full">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-200">
            <th class="w-10 px-4 py-3"></th>
            <th class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3">{{
              t('contentTypes.label') }}</th>
            <th class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3">{{
              t('contentTypes.model') }}</th>
            <th class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3">{{
              t('contentTypes.name') }}</th>
            <th class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3">{{
              t('contentTypes.icon') }}</th>
            <th class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3">{{
              t('contentTypes.fields') }}</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <TransitionGroup tag="tbody" name="ct-sort" class="divide-y divide-slate-100">
          <tr v-for="type in types" :key="type.name" class="hover:bg-slate-50 cursor-pointer transition-colors" :class="{
            'opacity-50': draggedName === type.name,
            'bg-theme-50': dropTargetName === type.name && draggedName !== type.name,
          }" @click="openType(type.name)" @dragover.prevent="dropTargetName = type.name"
            @dragleave="clearDropTarget(type.name)" @drop.prevent="handleDrop(type.name)">
            <td class="px-4 py-3 text-slate-400">
              <button type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50"
                :title="t('contentTypes.reorder')" :aria-label="t('contentTypes.reorder')" draggable="true"
                :disabled="savingOrder" @click.stop @dragstart.stop="handleDragStart(type.name, $event)"
                @dragend="handleDragEnd">
                <Icon icon="mdi:drag-vertical" class="w-5 h-5" />
              </button>
            </td>
            <td class="px-4 py-3 text-sm font-medium text-slate-900 hover:text-theme-600 hover:underline">{{ type.label
            }}</td>
            <td class="px-4 py-3 text-sm text-slate-600">
              <span :class="modelPillClass(type)">
                {{ modelLabel(type) }}
              </span>
            </td>
            <td class="px-4 py-3 text-sm text-slate-600">{{ type.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-600">
              <div class="flex items-center gap-2">
                <Icon :icon="type.icon || defaultIcon" class="w-4 h-4 text-slate-500" />
              </div>
            </td>
            <td class="px-4 py-3 text-sm text-slate-600">{{ Object.keys(type.fields ?? {}).length }}</td>
            <td class="px-4 py-3 text-right">
              <div class="flex items-center justify-end gap-2" @click.stop>
                <router-link :to="contentLink(type)" class="btn-secondary text-xs py-1 px-3">
                  {{ type.singleton ? t('contentTypes.open') : t('contentTypes.browse') }}
                </router-link>
                <router-link :to="`/content-types/${type.name}/edit`" class="btn-secondary text-xs py-1 px-3">
                  {{ t('contentTypes.edit') }}
                </router-link>
              </div>
            </td>
          </tr>
        </TransitionGroup>
      </table>
    </div>
  </div>
</template>

<script setup>
import LoadingSpinner from '../components/LoadingSpinner.vue'
import { ref, onBeforeUnmount, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import { useRouter } from 'vue-router'
import { api } from '../api/index.js'
import { contentTypesAdminEndpoint } from '../composables/apiEndpoint.js'
import { useApiEndpointStore } from '../stores/apiEndpoint.js'
import { useContentTypesStore } from '../stores/contentTypes.js'
import { useToastStore } from '../stores/toast.js'
import { useI18n } from '../i18n/index.js'

const loading = ref(true)
const types = ref([])
const defaultIcon = 'mdi:file-document-outline'
const router = useRouter()
const apiEndpointStore = useApiEndpointStore()
const typesStore = useContentTypesStore()
const toast = useToastStore()
const { t } = useI18n()
const apiEndpointOwner = 'content-types-list'
const draggedName = ref('')
const dropTargetName = ref('')
const savingOrder = ref(false)
let clickAfterDrag = false

onMounted(async () => {
  apiEndpointStore.setEndpoint(
    {
      label: 'Content types',
      url: contentTypesAdminEndpoint(),
    },
    apiEndpointOwner,
  )

  try {
    const res = await api.contentTypes.list()
    types.value = res.data ?? []
    typesStore.setList(types.value)
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  apiEndpointStore.clearEndpoint(apiEndpointOwner)
})

function openType(name) {
  if (clickAfterDrag) {
    clickAfterDrag = false
    return
  }

  router.push(`/content-types/${name}/edit`)
}

function handleDragStart(name, event) {
  draggedName.value = name
  clickAfterDrag = true
  event.dataTransfer.effectAllowed = 'move'
  event.dataTransfer.setData('text/plain', name)
}

function handleDragEnd() {
  draggedName.value = ''
  dropTargetName.value = ''
  window.setTimeout(() => { clickAfterDrag = false }, 0)
}

function clearDropTarget(name) {
  if (dropTargetName.value === name) {
    dropTargetName.value = ''
  }
}

function modelLabel(type) {
  return type.singleton ? t('contentTypes.single') : t('contentTypes.collection')
}

function modelPillClass(type) {
  return [
    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset',
    type.singleton
      ? 'bg-violet-50 text-violet-700 ring-violet-200'
      : 'bg-slate-100 text-slate-700 ring-slate-200',
  ]
}

function contentLink(type) {
  return `/content/${type.name}`
}

async function handleDrop(targetName) {
  const fromName = draggedName.value
  draggedName.value = ''
  dropTargetName.value = ''

  if (!fromName || fromName === targetName || savingOrder.value) return

  const previous = [...types.value]
  const reordered = moveType(types.value, fromName, targetName)
  if (reordered === null) return

  types.value = reordered
  typesStore.setList(reordered)
  savingOrder.value = true

  try {
    const res = await api.contentTypes.reorder(reordered.map((type) => type.name))
    types.value = res.data ?? reordered
    typesStore.setList(types.value)
    toast.success(t('contentTypes.orderSaved'))
  } catch (err) {
    types.value = previous
    typesStore.setList(previous)
    toast.error(err.message ?? t('contentTypes.orderSaveFailed'))
  } finally {
    savingOrder.value = false
  }
}

function moveType(items, fromName, targetName) {
  const fromIndex = items.findIndex((type) => type.name === fromName)
  const targetIndex = items.findIndex((type) => type.name === targetName)

  if (fromIndex < 0 || targetIndex < 0) return null

  const next = [...items]
  const [moved] = next.splice(fromIndex, 1)
  const targetAfterRemoval = next.findIndex((type) => type.name === targetName)
  const insertIndex = fromIndex < targetIndex ? targetAfterRemoval + 1 : targetAfterRemoval
  next.splice(insertIndex, 0, moved)

  return next
}
</script>

<style scoped>
.ct-sort-move {
  transition: transform 0.25s ease;
}
</style>
