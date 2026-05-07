<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4" @click.self="$emit('close')">
    <div class="relative flex max-h-[88vh] w-full max-w-7xl flex-col overflow-hidden rounded-lg bg-white shadow-xl"
      @dragenter.prevent="onDragEnter" @dragleave="onDragLeave" @dragover.prevent @drop.prevent="onContentDrop">
      <!-- Drop overlay -->
      <Transition name="drop-overlay">
        <div v-if="isDraggingFile"
          class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-theme-400 bg-theme-50/90 backdrop-blur-sm pointer-events-none">
          <Icon icon="mdi:cloud-upload-outline" class="h-10 w-10 text-theme-500" />
          <p class="text-sm font-medium text-theme-700">Drop to upload</p>
        </div>
      </Transition>
      <div class="flex items-center gap-3 border-b border-slate-200 px-5 py-4">
        <h2 class="text-sm font-semibold text-slate-800">Select media</h2>
        <button type="button" class="ml-auto flex items-center gap-1.5 btn-secondary text-xs px-2.5 py-1.5"
          :disabled="uploading" @click="fileInput.click()">
          <Icon icon="mdi:upload" class="h-4 w-4" />
          {{ uploading ? 'Uploading...' : 'Upload' }}
        </button>
        <button type="button" class="text-slate-400 hover:text-slate-700" title="Close" @click="$emit('close')">
          <Icon icon="mdi:close" class="h-5 w-5" />
        </button>
      </div>

      <input ref="fileInput" type="file" class="hidden" multiple @change="onFileChange" />

      <div class="min-h-0 flex-1 overflow-y-auto p-5">
        <div class="grid min-h-0 gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
          <MediaCategorySidebar v-model="selectedCategory" class="w-full" :categories="categories" :stats-files="statsFiles"
            :dragged-file="draggedFile" :selected-count="draftSelected.length" :selected-names="draftSelected"
            @categories-updated="onCategoriesUpdated" @category-renamed="onCategoryRenamed"
            @category-deleted="onCategoryDeleted" @file-drop="onCategoryFileDrop" />

          <section class="min-w-0">
            <p v-if="uploadError" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-600">{{ uploadError }}</p>
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
              <label class="relative block flex-1">
                <span class="sr-only">Search media</span>
                <Icon icon="mdi:magnify"
                  class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="media-picker-search" v-model="search" type="search" placeholder="Search media..."
                  class="form-input w-full rounded-lg border-slate-300 pl-9 text-sm" />
              </label>
              <select v-model="mediaType" class="form-select rounded-lg border-slate-300 text-sm lg:w-44">
                <option v-for="option in mediaTypeOptions" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
              <div class="flex items-center gap-2">
                <button type="button" class="rounded-lg border p-2 transition-colors"
                  :class="viewMode === 'grid' ? 'border-theme-300 bg-theme-50 text-theme-700' : 'border-slate-200 bg-white text-slate-500 hover:text-slate-800'"
                  title="Grid view" @click="viewMode = 'grid'">
                  <Icon icon="mdi:view-grid-outline" class="h-5 w-5" />
                </button>
                <button type="button" class="rounded-lg border p-2 transition-colors"
                  :class="viewMode === 'list' ? 'border-theme-300 bg-theme-50 text-theme-700' : 'border-slate-200 bg-white text-slate-500 hover:text-slate-800'"
                  title="List view" @click="viewMode = 'list'">
                  <Icon icon="mdi:view-list-outline" class="h-5 w-5" />
                </button>
              </div>
            </div>

            <div v-if="loading" class="py-8 text-center text-sm text-slate-500">Loading...</div>
            <div v-else-if="filteredFiles.length === 0" class="py-8 text-center text-sm text-slate-500">
              No media found.
            </div>
            <div v-else-if="viewMode === 'grid'"
              class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
              <button v-for="file in filteredFiles" :key="file.name" type="button"
                class="group cursor-grab rounded-lg border bg-white p-2 text-left transition-all hover:border-theme-400 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-theme-500"
                :class="[isSelected(file.name) ? 'border-theme-500 bg-theme-50' : 'border-slate-200', draggedFile?.name === file.name ? 'opacity-50' : '']"
                draggable="true" @click="choose(file)" @dragstart="onFileDragStart(file, $event)" @dragend="onFileDragEnd">
                <span
                  class="relative mb-2 flex aspect-square items-center justify-center overflow-hidden rounded-md bg-slate-100">
                  <img v-if="isImage(file.name)" :src="mediaPreviewUrl(file)" class="h-full w-full object-cover" :alt="file.name" />
                  <Icon v-else v-bind="getFileIcon(file.name)" class="h-8 w-8" />
                  <span v-if="multiple && isSelected(file.name)"
                    class="absolute right-2 top-2 flex h-5 w-5 items-center justify-center rounded-full bg-theme-600 text-white">
                    <Icon icon="mdi:check" class="h-3 w-3" />
                  </span>
                </span>
                <span class="block truncate text-xs font-medium text-slate-700" :title="file.name">{{ file.name
                }}</span>
                <span class="block text-xs text-slate-400">{{ formatBytes(file.size) }}</span>
              </button>
            </div>
            <div v-else class="overflow-hidden rounded-lg border border-slate-200">
              <button v-for="file in filteredFiles" :key="file.name" type="button"
                class="grid w-full cursor-grab gap-3 border-b border-slate-100 bg-white p-3 text-left last:border-b-0 hover:bg-slate-50 sm:grid-cols-[3.5rem_minmax(0,1fr)_7rem_5rem] sm:items-center"
                :class="[isSelected(file.name) ? 'bg-theme-50 ring-2 ring-inset ring-theme-400' : '', draggedFile?.name === file.name ? 'opacity-50' : '']"
                draggable="true" @click="choose(file)" @dragstart="onFileDragStart(file, $event)" @dragend="onFileDragEnd">
                <span class="flex h-14 w-14 items-center justify-center overflow-hidden rounded-lg bg-slate-100">
                  <img v-if="isImage(file.name)" :src="mediaPreviewUrl(file)" class="h-full w-full object-cover" :alt="file.name" />
                  <Icon v-else v-bind="getFileIcon(file.name)" class="h-7 w-7" />
                </span>
                <span class="min-w-0">
                  <span class="block truncate text-sm font-medium text-slate-800">{{ file.name }}</span>
                  <span class="mt-1 block text-xs text-slate-500">{{ file.category || 'No category' }}</span>
                </span>
                <span class="text-sm text-slate-500">{{ fileTypeLabel(file) }}</span>
                <span class="text-sm text-slate-500">{{ formatBytes(file.size) }}</span>
              </button>
            </div>
          </section>
        </div>
      </div>

      <div v-if="multiple" class="flex items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
        <span class="text-sm text-slate-500">{{ draftSelected.length }} selected</span>
        <div class="flex items-center gap-2">
          <button type="button" class="btn-secondary" @click="$emit('close')">Cancel</button>
          <button type="button" class="btn-primary" @click="confirmSelection">Use selected</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { api } from '../api/index.js'
import MediaCategorySidebar from './MediaCategorySidebar.vue'

const props = defineProps({
  selected: { type: [String, Array], default: '' },
  multiple: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'select'])

const files = ref([])
const statsFiles = ref([])
const loading = ref(true)
const search = ref('')
const draftSelected = ref(selectedNames())
const categories = ref([])
const selectedCategory = ref(null)
const mediaType = ref('all')
const viewMode = ref('grid')
const uploading = ref(false)
const uploadError = ref('')
const fileInput = ref(null)
let dragEnterCount = 0
let loadTimer = null
let loadRequestId = 0
const isDraggingFile = ref(false)
const draggedFile = ref(null)

const imageExts = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'])

const mediaTypeOptions = [
  { value: 'all', label: 'All types' },
  { value: 'images', label: 'Images' },
  { value: 'video', label: 'Videos' },
  { value: 'audio', label: 'Audio' },
  { value: 'documents', label: 'Documents' },
  { value: 'archives', label: 'Archives' },
  { value: 'other', label: 'Other' },
]

const filteredFiles = computed(() => files.value)

function getFileIcon(name) {
  const ext = fileExtension(name)
  if (ext === 'pdf') return { icon: 'mdi:file-pdf-box', class: 'text-red-500' }
  if (['doc', 'docx', 'odt'].includes(ext)) return { icon: 'mdi:file-word-box', class: 'text-blue-600' }
  if (['xls', 'xlsx', 'ods', 'csv'].includes(ext)) return { icon: 'mdi:file-excel-box', class: 'text-green-600' }
  if (['ppt', 'pptx', 'odp'].includes(ext)) return { icon: 'mdi:file-powerpoint-box', class: 'text-orange-500' }
  if (['zip', 'rar', '7z', 'tar', 'gz', 'bz2'].includes(ext)) return { icon: 'mdi:zip-box', class: 'text-yellow-600' }
  if (['mp4', 'webm', 'mov', 'm4v', 'avi', 'mkv', 'mpeg', 'mpg', 'ogv', '3gp', '3g2'].includes(ext)) return { icon: 'mdi:file-video-outline', class: 'text-pink-500' }
  if (['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'].includes(ext)) return { icon: 'mdi:file-music-outline', class: 'text-purple-500' }
  if (['txt', 'md', 'rtf'].includes(ext)) return { icon: 'mdi:file-document-outline', class: 'text-slate-500' }
  return { icon: 'mdi:file-outline', class: 'text-slate-400' }
}

function fileExtension(name) {
  return String(name).split('.').pop()?.toLowerCase() ?? ''
}

function categoryMatchesPath(category, categoryPath) {
  return category === categoryPath || String(category).startsWith(`${categoryPath} / `)
}

function fileTypeLabel(file) {
  const ext = fileExtension(file.name)
  return ext === '' ? 'File' : ext.toUpperCase()
}

function isImage(name) {
  return imageExts.has(fileExtension(name))
}

function mediaPreviewUrl(file) {
  return file.thumb_url || file.url
}

function formatBytes(bytes) {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1048576).toFixed(1) + ' MB'
}

async function load() {
  const requestId = ++loadRequestId
  loading.value = true

  const params = { sort: 'newest' }
  const query = search.value.trim()
  if (query !== '') params.q = query
  if (selectedCategory.value !== null) params.category = selectedCategory.value
  if (mediaType.value !== 'all') params.type = mediaType.value

  try {
    const res = await api.media.list(params)
    if (requestId !== loadRequestId) return
    files.value = res.data ?? []
    categories.value = res.meta?.categories ?? res.categories ?? categories.value
  } finally {
    if (requestId === loadRequestId) {
      loading.value = false
    }
  }
}

async function loadStats() {
  try {
    const res = await api.media.list({ sort: 'newest' })
    statsFiles.value = res.data ?? []
    categories.value = res.meta?.categories ?? res.categories ?? categories.value
  } catch {
    statsFiles.value = []
  }
}

function scheduleLoad() {
  if (loadTimer) {
    clearTimeout(loadTimer)
  }

  loadTimer = setTimeout(() => {
    load()
  }, 200)
}

async function uploadFiles(fileList) {
  if (!fileList?.length) return
  uploadError.value = ''
  uploading.value = true
  const fd = new FormData()
  for (const file of fileList) {
    fd.append('media[]', file)
  }
  if (selectedCategory.value !== null) {
    fd.append('category', selectedCategory.value)
  }
  try {
    const res = await api.media.upload(fd)
    categories.value = res.meta?.categories ?? res.categories ?? categories.value
    await Promise.all([loadStats(), load()])
  } catch (err) {
    uploadError.value = err.message
  } finally {
    uploading.value = false
  }
}

function onFileChange(e) {
  const selected = Array.from(e.target.files ?? [])
  uploadFiles(selected)
  fileInput.value.value = ''
}

function isOsFileDrop(e) {
  const dataTransfer = e.dataTransfer
  if (!dataTransfer?.types?.includes('Files')) return false
  if ((dataTransfer.files?.length ?? 0) === 0) return false

  const types = Array.from(dataTransfer.types)
  if (types.some((type) => type.startsWith('text/'))) return false

  const items = Array.from(dataTransfer.items ?? [])
  return items.length === 0 || items.some((item) => item.kind === 'file')
}

function onDragEnter(e) {
  if (draggedFile.value) return
  if (!isOsFileDrop(e)) return
  dragEnterCount++
  isDraggingFile.value = true
}

function onDragLeave() {
  if (draggedFile.value) return
  dragEnterCount = Math.max(0, dragEnterCount - 1)
  if (dragEnterCount === 0) isDraggingFile.value = false
}

function onContentDrop(e) {
  if (draggedFile.value) return
  dragEnterCount = 0
  isDraggingFile.value = false
  if (!isOsFileDrop(e)) return
  const dropped = Array.from(e.dataTransfer?.files ?? [])
  uploadFiles(dropped)
}

function onFileDragStart(file, event) {
  draggedFile.value = file
  event.dataTransfer.effectAllowed = 'move'
  event.dataTransfer.setData('text/plain', file.name)
}

function onFileDragEnd() {
  draggedFile.value = null
}

function selectedNames() {
  return Array.isArray(props.selected)
    ? props.selected.map((name) => String(name ?? '').trim()).filter(Boolean)
    : []
}

function isSelected(name) {
  return draftSelected.value.includes(name)
}

function choose(file) {
  if (!props.multiple) {
    emit('select', file.name)
    return
  }

  if (isSelected(file.name)) {
    draftSelected.value = draftSelected.value.filter((name) => name !== file.name)
  } else {
    draftSelected.value = [...draftSelected.value, file.name]
  }
}

function confirmSelection() {
  emit('select', [...draftSelected.value])
}

watch(() => props.selected, () => {
  draftSelected.value = selectedNames()
}, { deep: true })

function onCategoriesUpdated(cats) {
  categories.value = cats
  loadStats()
  load()
}

function onCategoryRenamed({ from, to }) {
  files.value = files.value.map((file) => {
    if (!categoryMatchesPath(file.category ?? '', from)) return file
    return {
      ...file,
      category: file.category === from ? to : file.category.replace(from, to),
    }
  })

  if (selectedCategory.value === from || categoryMatchesPath(selectedCategory.value ?? '', from)) {
    selectedCategory.value = selectedCategory.value === from
      ? to
      : selectedCategory.value.replace(from, to)
  }

  loadStats()
  load()
}

function onCategoryDeleted(category) {
  files.value = files.value.map((file) => (
    categoryMatchesPath(file.category ?? '', category) ? { ...file, category: '' } : file
  ))

  if (selectedCategory.value === category || categoryMatchesPath(selectedCategory.value ?? '', category)) {
    selectedCategory.value = null
  }

  loadStats()
  load()
}

async function onCategoryFileDrop({ file, targetCategory }) {
  draggedFile.value = null
  uploadError.value = ''

  const names = props.multiple && draftSelected.value.length > 0
    ? [...new Set([...draftSelected.value, file.name])]
    : [file.name]

  if (names.length === 1 && (file.category ?? '') === targetCategory) return

  try {
    const res = names.length > 1
      ? await api.media.bulkUpdateCategory(names, targetCategory)
      : await api.media.updateCategory(file.name, targetCategory)
    categories.value = res.meta?.categories ?? res.categories ?? categories.value
    await Promise.all([loadStats(), load()])
  } catch (err) {
    uploadError.value = err.message
  }
}

watch([search, selectedCategory, mediaType], () => {
  scheduleLoad()
})

onMounted(() => {
  loadStats()
  load()
})

onBeforeUnmount(() => {
  if (loadTimer) {
    clearTimeout(loadTimer)
  }
})
</script>

<style scoped>
.drop-overlay-enter-active,
.drop-overlay-leave-active {
  transition: opacity 0.15s ease;
}

.drop-overlay-enter-from,
.drop-overlay-leave-to {
  opacity: 0;
}
</style>
