<template>
  <div class="space-y-6">
    <PageHeader class="!mb-0" :title="t('media.title')" :description="t('media.description')">
      <template #actions>
        <button
          v-if="canManageSizes"
          type="button"
          class="btn-secondary shrink-0"
          @click="sizesOpen = true"
        >
          <Icon icon="mdi:image-size-select-large" class="h-4 w-4" />
          {{ t("media.sizes.title") }}
        </button>
        <button
          v-if="canUpload"
          type="button"
          class="btn-primary shrink-0"
          @click="fileInput.click()"
        >
          <Icon icon="mdi:upload" class="h-4 w-4" />
          {{ t("media.addFiles") }}
        </button>
      </template>
    </PageHeader>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 lg:grid-cols-4">
      <MediaCategorySidebar
        v-model="selectedCategory"
        :categories="categories"
        :stats-files="statsFiles"
        :stats="mediaStats"
        :dragged-file="draggedFile"
        :selected-count="selectedCount"
        :selected-names="selectedNames"
        :can-manage="canAnyMedia('media.update')"
        @categories-updated="onCategoriesUpdated"
        @category-renamed="onCategoryRenamed"
        @category-deleted="onCategoryDeleted"
        @file-drop="onCategoryFileDrop"
      />

      <section
        class="card relative min-w-0 p-5 col-span-2 lg:col-span-3"
        @dragenter.prevent="onContentDragEnter"
        @dragleave="onContentDragLeave"
        @dragover.prevent
        @drop.prevent="onContentDrop"
      >
        <div class="mb-5 flex flex-col gap-4">
          <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
          >
            <div>
              <h2 class="card-title">
                {{ t("media.filesTitle") }}
              </h2>
              <p class="mt-1 text-xs text-slate-500">
                {{ selectedCategoryLabel }} · {{ totalFiles }}
                {{ mediaFileLabel(totalFiles) }}
              </p>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto">
              <button
                type="button"
                class="rounded-lg border p-2 transition-colors"
                :class="
                  viewMode === 'grid'
                    ? 'border-theme-300 bg-theme-50 text-theme-700'
                    : 'border-slate-200 bg-white text-slate-500 hover:text-slate-800'
                "
                :title="t('media.gridView')"
                @click="viewMode = 'grid'"
              >
                <Icon icon="mdi:view-grid-outline" class="h-5 w-5" />
              </button>
              <button
                type="button"
                class="rounded-lg border p-2 transition-colors"
                :class="
                  viewMode === 'list'
                    ? 'border-theme-300 bg-theme-50 text-theme-700'
                    : 'border-slate-200 bg-white text-slate-500 hover:text-slate-800'
                "
                :title="t('media.listView')"
                @click="viewMode = 'list'"
              >
                <Icon icon="mdi:view-list-outline" class="h-5 w-5" />
              </button>
            </div>
          </div>

          <div class="flex flex-col gap-3 sm:flex-row">
            <label class="relative block flex-1">
              <span class="sr-only">{{ t("media.search") }}</span>
              <Icon
                icon="mdi:magnify"
                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
              />
              <input
                id="media-search"
                v-model="search"
                type="search"
                :placeholder="t('media.searchPlaceholder')"
                class="form-input w-full rounded-lg border-slate-300 pl-9 text-sm sm:min-w-72"
              />
            </label>
            <div ref="sortFilterMenuRef" class="relative sm:ml-auto">
              <button
                type="button"
                class="btn-secondary w-full justify-center sm:w-auto"
                aria-haspopup="menu"
                :aria-expanded="sortFilterMenuOpen"
                @click="sortFilterMenuOpen = !sortFilterMenuOpen"
              >
                <Icon icon="mdi:filter-variant" class="h-4 w-4" />
                {{ t("media.filterAndSort") }}
                <span
                  v-if="activeFilterCount > 0"
                  class="flex h-5 min-w-5 items-center justify-center rounded-full bg-theme-100 px-1.5 text-[11px] font-semibold text-theme-700"
                >{{ activeFilterCount }}</span>
                <Icon icon="mdi:chevron-down" class="h-4 w-4" />
              </button>

              <div
                v-if="sortFilterMenuOpen"
                role="menu"
                class="absolute right-0 z-30 mt-2 max-h-[70vh] w-72 overflow-y-auto rounded-xl border border-slate-200 bg-white p-2 shadow-xl"
              >
                <div class="px-2 pb-1 pt-1 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                  {{ t("media.filter") }}
                </div>
                <p class="px-2 py-1 text-xs font-semibold text-slate-600">{{ t("media.type") }}</p>
                <button
                  v-for="option in mediaTypeOptions"
                  :key="`type-${option.value}`"
                  type="button"
                  class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-slate-50"
                  :class="mediaType === option.value ? 'text-theme-700' : 'text-slate-700'"
                  @click="mediaType = option.value"
                >
                  <Icon
                    :icon="mediaType === option.value ? 'mdi:radiobox-marked' : 'mdi:radiobox-blank'"
                    class="h-4 w-4 shrink-0"
                  />
                  {{ option.label }}
                </button>

                <p class="mt-2 px-2 py-1 text-xs font-semibold text-slate-600">{{ t("media.filterUsage") }}</p>
                <button
                  v-for="option in usageFilterOptions"
                  :key="`usage-${option.value}`"
                  type="button"
                  class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-slate-50"
                  :class="usageFilter === option.value ? 'text-theme-700' : 'text-slate-700'"
                  @click="usageFilter = option.value"
                >
                  <Icon
                    :icon="usageFilter === option.value ? 'mdi:radiobox-marked' : 'mdi:radiobox-blank'"
                    class="h-4 w-4 shrink-0"
                  />
                  {{ option.label }}
                </button>

                <div class="my-2 border-t border-slate-100" />
                <div class="px-2 pb-1 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                  {{ t("media.sort") }}
                </div>
                <button
                  v-for="option in sortOptions"
                  :key="`sort-${option.value}`"
                  type="button"
                  class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-slate-50"
                  :class="sortOrder === option.value ? 'text-theme-700' : 'text-slate-700'"
                  @click="sortOrder = option.value"
                >
                  <Icon
                    :icon="sortOrder === option.value ? 'mdi:radiobox-marked' : 'mdi:radiobox-blank'"
                    class="h-4 w-4 shrink-0"
                  />
                  {{ option.label }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <input
          ref="fileInput"
          type="file"
          class="hidden"
          multiple
          @change="onFileChange"
        />

        <div
          v-if="uploadError"
          class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700"
        >
          {{ uploadError }}
        </div>

        <div v-if="uploading" class="mb-4 rounded-lg border border-theme-200 bg-theme-50 p-3">
          <div class="mb-2 flex items-center justify-between gap-3 text-xs font-medium text-theme-700">
            <span>{{ uploadProgress >= 100 ? t("media.processing") : t("media.uploading") }}</span>
            <span>{{ uploadProgress }}%</span>
          </div>
          <div
            class="h-2 overflow-hidden rounded-full bg-theme-100"
            role="progressbar"
            :aria-label="t('media.uploadProgress')"
            :aria-valuenow="uploadProgress"
            aria-valuemin="0"
            aria-valuemax="100"
          >
            <div
              class="h-full rounded-full bg-theme-600 transition-[width] duration-150"
              :style="{ width: `${uploadProgress}%` }"
            />
          </div>
        </div>

        <Transition v-bind="ht">
          <div
            v-if="selectedCount > 0"
            class="mb-4 rounded-lg border border-theme-200 bg-theme-50/60 px-3 py-3 shadow-sm"
          >
            <MediaBulkEditBar
              :categories="categoryTree"
              :selected-count="selectedCount"
              :all-results-selected="allResultsSelected"
              :page-count="files.length"
              :applying="bulkWorking || regeneratingThumbs || selectingAllMedia"
              @apply="onBulkApply"
              @delete-selected="confirmBulkDelete"
              @clear-selection="clearSelection"
              @select-all="selectAllMatchingMedia"
            />
          </div>
        </Transition>

        <div
          v-if="!loading && files.length === 0 && !canUpload"
          class="mb-6 rounded-lg border border-dashed border-slate-300 p-8 text-center"
        >
          <Icon class="mx-auto mb-2 h-8 w-8 text-slate-400" icon="mdi:image-off-outline" />
          <p class="text-sm text-slate-600">{{ t("media.noFilesYet") }}</p>
        </div>
        <div
          v-else-if="!loading && files.length === 0"
          role="button"
          tabindex="0"
          class="mb-6 cursor-pointer rounded-lg border-2 border-dashed p-8 text-center transition-colors"
          :class="
            isDraggingFile
              ? 'border-theme-400 bg-theme-50'
              : 'border-slate-300 hover:border-theme-400'
          "
          @click="fileInput.click()"
          @keydown.enter.prevent="fileInput.click()"
          @keydown.space.prevent="fileInput.click()"
        >
          <Icon
            class="mx-auto mb-2 h-8 w-8 transition-colors"
            :class="isDraggingFile ? 'text-theme-500' : 'text-slate-400'"
            icon="mdi:cloud-upload-outline"
          />
          <p class="text-sm text-slate-600">
            <span class="font-medium text-theme-600">{{
              t("media.clickUpload")
            }}</span>
            {{ t("media.dragDrop") }}
          </p>
          <p class="mt-1 text-xs text-slate-400">
            {{ uploading ? t("media.uploading") : uploadHint }}
          </p>
          <p
            v-if="search.trim() !== '' || mediaType !== 'all'"
            class="mt-2 text-xs text-slate-400"
          >
            {{ t("media.hiddenByFilters") }}
          </p>
        </div>

        <Transition name="drop-overlay">
          <div
            v-if="isDraggingFile && files.length > 0"
            class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-theme-400 bg-theme-50/80 backdrop-blur-sm pointer-events-none"
          >
            <Icon
              icon="mdi:cloud-upload-outline"
              class="h-10 w-10 text-theme-500"
            />
            <p class="text-sm font-medium text-theme-700">
              {{ t("media.dropUpload") }}
            </p>
          </div>
        </Transition>

        <LoadingSpinner v-if="loading" />

        <template v-else-if="files.length > 0">
          <div
            class="mb-4 flex flex-col gap-3 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between"
          >
            <p>
              {{ t("media.showingPrefix") }} {{ pageStart }} -
              <InlinePageSizeSelect
                v-model="pageSize"
                :display-value="pageEnd"
                :options="pageSizeOptions"
                :aria-label="t('media.rowsPerPage')"
                @change="changePageSize"
              />
              {{ t("media.showingOf") }} {{ totalFiles }}
            </p>
            <div class="flex items-center gap-2">
              <button
                type="button"
                class="btn-secondary px-3 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="currentPage === 1"
                @click="currentPage--"
              >
                {{ t("media.previous") }}
              </button>
              <span class="min-w-20 text-center">{{
                t("media.pageOf", { page: currentPage, total: totalPages })
              }}</span>
              <button
                type="button"
                class="btn-secondary px-3 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="currentPage === totalPages"
                @click="currentPage++"
              >
                {{ t("media.next") }}
              </button>
            </div>
          </div>

          <div
            v-if="viewMode === 'grid'"
            class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5"
          >
            <article
              v-for="(file, fileIndex) in files"
              :key="file.name"
              class="group relative cursor-grab rounded-lg border border-slate-200 bg-white p-2 transition-all hover:border-theme-300 hover:shadow-sm"
              :class="{
                'opacity-50': draggedFile?.name === file.name,
                'ring-2 ring-theme-500': isSelected(file),
              }"
              draggable="true"
              @dragstart="onFileDragStart(file, $event)"
              @dragend="onFileDragEnd"
            >
              <label
                class="absolute left-3 top-3 z-10 flex h-7 w-7 items-center justify-center rounded-md bg-white/90 shadow-sm transition-opacity"
                :class="
                  selectionMode
                    ? 'opacity-100'
                    : 'pointer-events-none opacity-0 group-hover:pointer-events-auto group-hover:opacity-100 focus-within:pointer-events-auto focus-within:opacity-100'
                "
              >
                <span class="sr-only">{{
                  t("media.selectFile", { name: file.name })
                }}</span>
                <input
                  v-if="canSelectFiles"
                  type="checkbox"
                  class="form-checkbox rounded border-slate-300 text-theme-600"
                  :checked="isSelected(file)"
                  @click.stop="onCheckboxClick(file, fileIndex, $event)"
                />
              </label>

              <button
                v-if="canMedia('media.delete', file.category)"
                type="button"
                class="absolute right-2 top-2 z-10 flex h-6 w-6 items-center justify-center rounded-md bg-white/90 text-slate-400 shadow-sm transition-all hover:text-red-500 sm:opacity-0 sm:group-hover:opacity-100 focus-visible:opacity-100"
                :title="t('media.delete')"
                :aria-label="t('media.deleteNamed', { name: file.name })"
                @click.stop="confirmDelete(file)"
              >
                <Icon icon="mdi:trash-can-outline" class="h-3.5 w-3.5" />
              </button>

              <button
                type="button"
                class="mb-2 flex aspect-square w-full items-center justify-center overflow-hidden rounded-lg bg-slate-100 relative"
                @click.stop="openDetail(file)"
              >
                <img
                  v-if="isImage(file.name)"
                  :src="mediaPreviewUrl(file)"
                  class="h-full w-full object-cover"
                  :alt="file.name"
                />
                <Icon v-else v-bind="getFileIcon(file.name)" class="h-9 w-9" />
                <span
                  class="absolute inset-0 flex items-center justify-center bg-ink-950/0 transition-colors group-hover:bg-ink-950/10"
                >
                  <Icon
                    icon="mdi:eye-outline"
                    class="h-6 w-6 text-white opacity-0 drop-shadow transition-opacity group-hover:opacity-90"
                  />
                </span>
              </button>

              <p
                class="truncate text-xs font-medium text-slate-700"
                :title="file.name"
              >
                {{ file.name }}
              </p>
              <p class="text-xs text-slate-400 flex items-center gap-1">
                <Icon
                  v-if="file.visibility === 'private'"
                  icon="mdi:lock-outline"
                  class="h-3 w-3 shrink-0 text-amber-500"
                  :title="t('media.private')"
                />
                {{ formatBytes(file.size) }}
              </p>
            </article>
          </div>

          <div
            v-else
            class="overflow-hidden rounded-lg border border-slate-200"
          >
            <div
              v-for="(file, fileIndex) in files"
              :key="file.name"
              class="group grid gap-3 border-b border-slate-100 bg-white p-3 last:border-b-0 sm:grid-cols-[1.25rem_4rem_minmax(0,1fr)_3.5rem_5.5rem_2.5rem] sm:items-center"
              :class="{
                'opacity-50': draggedFile?.name === file.name,
                'ring-2 ring-inset ring-theme-500': isSelected(file),
              }"
              draggable="true"
              @dragstart="onFileDragStart(file, $event)"
              @dragend="onFileDragEnd"
            >
              <label class="flex items-center">
                <span class="sr-only">{{
                  t("media.selectFile", { name: file.name })
                }}</span>
                <input
                  v-if="canSelectFiles"
                  type="checkbox"
                  class="form-checkbox rounded border-slate-300 text-theme-600"
                  :checked="isSelected(file)"
                  @click.stop="onCheckboxClick(file, fileIndex, $event)"
                />
              </label>
              <button
                type="button"
                class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg bg-slate-100"
                @click.stop="openDetail(file)"
              >
                <img
                  v-if="isImage(file.name)"
                  :src="mediaPreviewUrl(file)"
                  class="h-full w-full object-cover"
                  :alt="file.name"
                />
                <Icon v-else v-bind="getFileIcon(file.name)" class="h-8 w-8" />
              </button>
              <div class="min-w-0">
                <button
                  type="button"
                  class="line-clamp-2 w-full text-left text-sm font-medium text-slate-800 [overflow-wrap:anywhere] hover:text-theme-700"
                  :title="file.name"
                  @click="openDetail(file)"
                >
                  {{ file.name }}
                </button>
                <p class="mt-1 text-xs text-slate-500 flex items-center gap-1">
                  <Icon
                    v-if="file.visibility === 'private'"
                    icon="mdi:lock-outline"
                    class="h-3 w-3 shrink-0 text-amber-500"
                    :title="t('media.private')"
                  />
                  {{ file.category || t("media.noCategory") }}
                </p>
              </div>
              <span
                class="truncate text-sm text-slate-500"
                :title="fileTypeLabel(file)"
              >{{ fileTypeLabel(file) }}</span>
              <span class="whitespace-nowrap text-sm tabular-nums text-slate-500 sm:text-right">{{
                formatBytes(file.size)
              }}</span>
              <div class="flex items-center gap-2 sm:justify-end">
                <button
                  v-if="canMedia('media.delete', file.category)"
                  type="button"
                  class="btn-danger-subtle btn-sm"
                  :title="t('media.delete')"
                  :aria-label="t('media.deleteNamed', { name: file.name })"
                  @click="confirmDelete(file)"
                >
                  <Icon icon="mdi:trash-can-outline" class="h-4 w-4" />
                </button>
              </div>
            </div>
          </div>

          <div
            v-if="totalPages > 1"
            class="mt-5 flex flex-col gap-3 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between"
          >
            <p>
              {{
                t("media.showing", {
                  start: pageStart,
                  end: pageEnd,
                  total: totalFiles,
                })
              }}
            </p>
            <div class="flex items-center gap-2">
              <button
                type="button"
                class="btn-secondary px-3 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="currentPage === 1"
                @click="currentPage--"
              >
                {{ t("media.previous") }}
              </button>
              <span class="min-w-20 text-center">{{
                t("media.pageOf", { page: currentPage, total: totalPages })
              }}</span>
              <button
                type="button"
                class="btn-secondary px-3 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="currentPage === totalPages"
                @click="currentPage++"
              >
                {{ t("media.next") }}
              </button>
            </div>
          </div>
        </template>
      </section>
    </div>

    <!-- Media detail panel -->
    <MediaSizesPanel v-if="canManageSizes" v-model="sizesOpen" @regenerated="load" />

    <SlidePanel
      v-model="showDetail"
      :title="detailFile?.name ?? ''"
      :subtitle="detailFile ? formatBytes(detailFile.size) : ''"
      width="28rem"
    >
      <div v-if="detailFile" class="p-6 space-y-6">
        <!-- Preview -->
        <a
          :href="origin + detailFile.url"
          target="_blank"
          rel="noopener"
          :aria-label="t('media.openInNewTab')"
          :title="t('media.openInNewTab')"
          class="group relative rounded-xl overflow-hidden bg-slate-100 flex items-center justify-center focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-theme-500 focus-visible:ring-offset-2"
        >
          <img
            v-if="isImage(detailFile.name)"
            :src="detailFile.url"
            class="max-w-full max-h-80 object-contain"
            :alt="detailFile.name"
          />
          <div
            v-else
            class="py-16 flex flex-col items-center gap-2 text-slate-400"
          >
            <Icon v-bind="getFileIcon(detailFile.name)" class="w-12 h-12" />
            <span class="text-sm">{{ t("media.noPreview") }}</span>
          </div>
          <span
            class="pointer-events-none absolute inset-0 flex items-center justify-center bg-theme-900/50 text-white opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
            aria-hidden="true"
          >
            <Icon icon="mdi:magnify-plus-outline" class="h-10 w-10" />
          </span>
        </a>

        <!-- Metadata -->
        <dl class="space-y-3 text-sm">
          <div>
            <dt
              class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-1"
            >
              {{ t("media.fileName") }}
            </dt>
            <dd v-if="renamingFile" class="space-y-2">
              <input
                v-model="renameFileName"
                type="text"
                class="form-input w-full rounded-lg border-slate-300 text-sm"
                @keydown.enter.prevent="executeRenameFile"
                @keydown.esc="cancelRenameFile"
              />
              <div class="grid gap-2 sm:grid-cols-2">
                <button
                  type="button"
                  class="btn-primary justify-center text-sm"
                  :disabled="renamingFileWorking"
                  @click="executeRenameFile"
                >
                  <Icon icon="mdi:check" class="h-4 w-4" />
                  {{ t("media.save") }}
                </button>
                <button
                  type="button"
                  class="btn-secondary justify-center text-sm"
                  :disabled="renamingFileWorking"
                  @click="cancelRenameFile"
                >
                  <Icon icon="mdi:close" class="h-4 w-4" />
                  {{ t("media.cancel") }}
                </button>
              </div>
            </dd>
            <dd v-else class="flex items-start justify-between gap-3">
              <button
                type="button"
                class="min-w-0 break-all text-left text-slate-800 hover:text-theme-700"
                :title="t('media.doubleClickRename')"
                @dblclick="canEditDetail && startRenameFile(detailFile)"
              >
                {{ detailFile.name }}
              </button>
              <button
                v-if="canEditDetail"
                type="button"
                class="btn-secondary shrink-0 px-2 py-1 text-xs"
                :title="t('media.renameFile')"
                @click="startRenameFile(detailFile)"
              >
                <Icon icon="mdi:pencil-outline" class="h-4 w-4" />
                {{ t("media.rename") }}
              </button>
            </dd>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <dt
                class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-1"
              >
                {{ t("media.size") }}
              </dt>
              <dd class="text-slate-800">{{ formatBytes(detailFile.size) }}</dd>
            </div>
            <div>
              <dt
                class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-1"
              >
                {{ t("media.type") }}
              </dt>
              <dd class="text-slate-800">
                {{ detailFile.mime ?? fileTypeLabel(detailFile) }}
              </dd>
            </div>
          </div>
          <div v-if="imageDimensions(detailFile)">
            <dt
              class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-1"
            >
              {{ t("media.dimensions") }}
            </dt>
            <dd class="text-slate-800">{{ imageDimensions(detailFile) }}</dd>
          </div>
          <div v-if="detailFile.created_at">
            <dt
              class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-1"
            >
              {{ t("media.uploaded") }}
            </dt>
            <dd class="text-slate-800">
              {{ formatDate(detailFile.created_at) }}
            </dd>
          </div>
          <div v-if="detailFile.uploaded_at || detailFile.uploaded_by">
            <dt
              class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-1"
            >
              {{ t("media.uploadedBy") }}
            </dt>
            <dd>
              <template v-if="userMap[detailFile.uploaded_by]">
                <div class="flex items-center gap-2">
                  <div
                    class="w-6 h-6 rounded-full overflow-hidden bg-theme-600 flex items-center justify-center text-white text-xs font-semibold select-none shrink-0"
                  >
                    <img
                      v-if="userMap[detailFile.uploaded_by].has_avatar"
                      :src="`${ADMIN_API_BASE}/users/${detailFile.uploaded_by}/avatar`"
                      class="w-full h-full object-cover"
                      :alt="userMap[detailFile.uploaded_by].username"
                    />
                    <span v-else>{{
                      userMap[
                        detailFile.uploaded_by
                      ].username?.[0]?.toUpperCase()
                    }}</span>
                  </div>
                  <span class="text-sm text-slate-800">{{
                    userMap[detailFile.uploaded_by].username
                  }}</span>
                  <span
                    v-if="detailFile.uploaded_at"
                    class="text-xs text-slate-400"
                    >{{ formatDate(detailFile.uploaded_at) }}</span
                  >
                </div>
              </template>
              <span v-else class="text-slate-500 text-sm">{{
                detailFile.uploaded_by ?? "—"
              }}</span>
            </dd>
          </div>
        </dl>

        <!-- Used in -->
        <div>
          <label
            class="text-xs font-medium text-slate-400 uppercase tracking-wider block mb-2"
            >{{ t("media.usedIn") }}</label
          >
          <p
            v-if="detailFileUsages.length === 0"
            class="text-sm text-slate-400"
          >
            {{ t("media.notUsed") }}
          </p>
          <ul v-else class="space-y-1.5">
            <li
              v-for="ref in detailFileUsages"
              :key="`${ref.collection}:${ref.id}`"
              class="flex items-start justify-between gap-2 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-sm"
            >
              <span class="min-w-0">
                <RouterLink
                  :to="`/content/${ref.collection}/${ref.id}`"
                  class="block truncate font-medium text-slate-800 hover:text-theme-700"
                  >{{ ref.title }}</RouterLink
                >
                <span class="text-xs text-slate-400">{{ ref.collection }}</span>
              </span>
              <Icon
                icon="mdi:arrow-top-right"
                class="mt-0.5 h-4 w-4 shrink-0 text-slate-300"
              />
            </li>
          </ul>
        </div>

        <!-- Category -->
        <div>
          <label
            for="media-detail-category"
            class="text-xs font-medium text-slate-400 uppercase tracking-wider block mb-1"
            >{{ t("media.category") }}</label
          >
          <select
            id="media-detail-category"
            :disabled="!canEditDetail"
            :value="detailFile.category ?? ''"
            class="form-select w-full rounded-lg border-slate-300 text-sm"
            @change="onDetailCategoryChange($event.target.value)"
          >
            <option value="">{{ t("media.noCategory") }}</option>
            <option
              v-for="cat in categoryTree"
              :key="cat.path"
              :value="cat.path"
            >
              {{ cat.optionLabel }}
            </option>
          </select>
        </div>

        <!-- Visibility -->
        <div>
          <label
            for="media-detail-visibility"
            class="text-xs font-medium text-slate-400 uppercase tracking-wider block mb-1"
            >{{ t("media.visibility") }}</label
          >
          <select
            id="media-detail-visibility"
            :disabled="!canEditDetail"
            :value="detailFile.visibility ?? 'public'"
            class="form-select w-full rounded-lg border-slate-300 text-sm"
            @change="onDetailVisibilityChange($event.target.value)"
          >
            <option value="public">{{ t("media.public") }}</option>
            <option value="private">
              {{ t("media.privateRequiresToken") }}
            </option>
          </select>
        </div>

        <!-- Alt / Title -->
        <div class="space-y-3">
          <div>
            <label
              :for="`detail-alt-${detailFile.name}`"
              class="text-xs font-medium text-slate-400 uppercase tracking-wider block mb-1"
              >{{ t("media.altText") }}</label
            >
            <LocalizedInput
              :id="`detail-alt-${detailFile.name}`"
              v-model="detailAlt"
              compact
              :readonly="!canEditDetail"

              class="w-full"
              :placeholder="t('media.altPlaceholder')"
              @focusout="saveMetaIfChanged"
            />
          </div>
          <div>
            <label
              :for="`detail-title-${detailFile.name}`"
              class="text-xs font-medium text-slate-400 uppercase tracking-wider block mb-1"
              >{{ t("media.titleField") }}</label
            >
            <LocalizedInput
              :id="`detail-title-${detailFile.name}`"
              v-model="detailTitle"
              compact
              :readonly="!canEditDetail"

              class="w-full"
              :placeholder="t('media.titlePlaceholder')"
              @focusout="saveMetaIfChanged"
            />
          </div>
          <div v-if="savingMeta" class="text-xs text-slate-400">
            {{ t("media.saving") }}
          </div>
        </div>

        <!-- URL -->
        <div>
          <label
            class="text-xs font-medium text-slate-400 uppercase tracking-wider block mb-1"
            >{{ t("media.url") }}</label
          >
          <div class="flex gap-2">
            <input
              :value="origin + detailFile.url"
              readonly
              class="form-input min-w-0 flex-1 rounded-lg border-slate-300 text-sm bg-slate-50 text-slate-600"
            />
            <button
              type="button"
              class="btn-secondary shrink-0"
              @click="copyUrl(origin + detailFile.url)"
            >
              {{ t("media.copy") }}
            </button>
          </div>
        </div>

        <!-- Delete -->
        <div v-if="canMedia('media.delete', detailFile.category)" class="pt-2 border-t border-slate-100">
          <button
            type="button"
            class="btn-danger-subtle w-full justify-center"
            @click="confirmDelete(detailFile)"
          >
            {{ t("media.deleteFile") }}
          </button>
        </div>
      </div>
    </SlidePanel>

    <!-- Delete confirm modal -->
    <ConfirmModal
      v-model="showDeleteModal"
      :title="t('media.deleteFileTitle')"
      :message="t('media.deleteFileMessage', { name: deleteTarget?.name })"
      :confirm-label="t('media.delete')"
      :loading="deleting"
      @confirm="executeDelete"
    />

    <ConfirmModal
      v-model="showBulkDeleteModal"
      :title="t('media.deleteSelectedTitle')"
      :message="
        t('media.deleteSelectedMessage', {
          count: selectedCount,
          itemLabel: mediaFileLabel(selectedCount),
        })
      "
      :confirm-label="t('media.deleteSelected')"
      :loading="bulkWorking"
      @confirm="executeBulkDelete"
    />
  </div>
</template>

<script setup>
import PageHeader from "../components/PageHeader.vue";
import { ADMIN_API_BASE } from "../basePath.js";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import { Icon } from "@iconify/vue";
import SlidePanel from "../components/SlidePanel.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import LoadingSpinner from "../components/LoadingSpinner.vue";
import InlinePageSizeSelect from "../components/InlinePageSizeSelect.vue";
import MediaCategorySidebar from "../components/MediaCategorySidebar.vue";
import MediaBulkEditBar from "../components/MediaBulkEditBar.vue";
import { api } from "../api/index.js";
import { useToastStore } from "../stores/toast.js";
import { useAuthStore } from "../stores/auth.js";
import { usePermissions } from "../composables/usePermissions.js";
import MediaSizesPanel from "../components/MediaSizesPanel.vue";
import { regenerateInBatches } from "../composables/useMediaSizes.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import { useHeightTransition } from "../composables/useHeightTransition.js";
import {
  mediaDetailEndpoint,
  mediaListEndpoint,
} from "../composables/apiEndpoint.js";
import LocalizedInput from "../components/LocalizedInput.vue";
import { useI18n } from "../i18n/index.js";
import {
  categoryLabel,
  categoryMatchesPath,
  categoryTreeOptions,
  fileTypeLabel as mediaFileTypeLabel,
  formatBytes,
  getFileIcon,
  isImageFile,
} from "../composables/mediaUtils.js";

const ht = useHeightTransition();

const toast = useToastStore();
const auth = useAuthStore();
const { canMedia, canAnyMedia } = usePermissions();
const canUpload = computed(() => canAnyMedia("media.upload"));
const canManageSizes = computed(() => auth.can("site.manage"));
const sizesOpen = ref(false);
const canSelectFiles = computed(() => canAnyMedia("media.update") || canAnyMedia("media.delete"));
const canEditDetail = computed(() =>
  detailFile.value ? canMedia("media.update", detailFile.value.category) : false,
);
const apiEndpointStore = useApiEndpointStore();
const { t } = useI18n();
const apiEndpointOwner = "media";
const files = ref([]);
const loading = ref(true);
const uploading = ref(false);
const uploadProgress = ref(0);
const regeneratingThumbs = ref(false);
const uploadError = ref("");
const fileInput = ref(null);
const search = ref("");
const categories = ref([]);
const selectedCategory = ref(null);
const draggedFile = ref(null);
const isDraggingFile = ref(false);
const selectedMedia = ref(new Set());
const lastSelectedIndex = ref(-1);
const statsFiles = ref([]);
const mediaStats = ref(null);
const mediaType = ref("all");
const sortOrder = ref("newest");
const sortFilterMenuOpen = ref(false);
const sortFilterMenuRef = ref(null);
const viewMode = ref(
  typeof localStorage !== "undefined" &&
    localStorage.getItem("mediaViewMode") === "list"
    ? "list"
    : "grid",
);

const mediaTypeOptions = computed(() => [
  { value: "all", label: t("media.allTypes") },
  { value: "images", label: t("media.images") },
  { value: "video", label: t("media.videos") },
  { value: "audio", label: t("media.audio") },
  { value: "documents", label: t("media.documents") },
  { value: "archives", label: t("media.archives") },
  { value: "other", label: t("media.other") },
]);

const usageFilterOptions = computed(() => [
  { value: "all", label: t("media.allFiles") },
  { value: "unused", label: t("media.unusedOnly") },
  { value: "public", label: t("media.publicOnly") },
  { value: "private", label: t("media.privateOnly") },
]);

const sortOptions = computed(() => [
  { value: "newest", label: t("media.newestFirst") },
  { value: "oldest", label: t("media.oldestFirst") },
  { value: "name", label: t("media.nameAZ") },
  { value: "size", label: t("media.largestFirst") },
]);
const users = ref([]);
const userMap = computed(() =>
  Object.fromEntries(users.value.map((u) => [u.id, u])),
);

async function loadUsers() {
  // Names are a nicety; users without users.read see account ids instead.
  if (!auth.can("users.read")) return;
  try {
    users.value = (await api.users.list()).data ?? [];
  } catch {
    /* non-admin, ignore */
  }
}

async function loadUsages() {
  if (usagesLoaded || usagesRequest) return usagesRequest;

  usagesRequest = api.media.usages();
  try {
    const res = await usagesRequest;
    usages.value = res.data ?? {};
    usagesLoaded = true;
  } catch {
    usages.value = {};
  } finally {
    usagesRequest = null;
  }
}
const bulkWorking = ref(false);
const selectingAllMedia = ref(false);
const showBulkDeleteModal = ref(false);
const currentPage = ref(1);
const totalFiles = ref(0);
let dragEnterCount = 0;
let loadTimer = null;

const showDetail = ref(false);
const detailFile = ref(null);
const origin = window.location.origin;
const showDeleteModal = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);
const renamingFile = ref(false);
const renameFileName = ref("");
const renamingFileWorking = ref(false);
const usages = ref({});
let usagesLoaded = false;
let usagesRequest = null;
const usageFilter = ref("all");
const activeFilterCount = computed(
  () =>
    Number(mediaType.value !== "all") + Number(usageFilter.value !== "all"),
);
const detailAlt = ref("");
const detailTitle = ref("");
const savingMeta = ref(false);

function fileTypeLabel(file) {
  return mediaFileTypeLabel(file, t);
}

const selectedCategoryLabel = computed(() => {
  if (selectedCategory.value === null) return t("media.allCategories");
  if (selectedCategory.value === "") return t("media.noCategory");
  return categoryLabel(selectedCategory.value);
});
const detailFileUsages = computed(() =>
  detailFile.value ? (usages.value[detailFile.value.name] ?? []) : [],
);
const uploadHint = computed(() =>
  selectedCategory.value === null
    ? t("media.anyAllowedType")
    : t("media.uploadsTo", { category: selectedCategory.value }),
);
const selectedNames = computed(() => Array.from(selectedMedia.value));
const selectedCount = computed(() => selectedMedia.value.size);
const selectionMode = computed(() => selectedCount.value > 0);

// categoryTree is used only for the bulk-category <select> options in the toolbar
const categoryTree = computed(() => categoryTreeOptions(categories.value));
const pageSizeOptions = [20, 30, 40, 50, 100, 200, 300];
const pageSize = ref(20);
const totalPages = computed(() =>
  Math.max(1, Math.ceil(totalFiles.value / pageSize.value)),
);
const pageStart = computed(() =>
  totalFiles.value === 0 ? 0 : (currentPage.value - 1) * pageSize.value + 1,
);
const pageEnd = computed(() =>
  Math.min(currentPage.value * pageSize.value, totalFiles.value),
);
const apiEndpointUrl = computed(() => {
  if (showDetail.value && detailFile.value?.name) {
    return mediaDetailEndpoint(detailFile.value.name);
  }

  return mediaListEndpoint({
    limit: pageSize.value,
    offset: (currentPage.value - 1) * pageSize.value,
    q: search.value.trim(),
    category: selectedCategory.value,
  });
});
const allResultsSelected = computed(
  () => totalFiles.value > 0 && selectedCount.value >= totalFiles.value,
);

function isImage(name) {
  return isImageFile(name);
}

function mediaPreviewUrl(file) {
  return file.thumb_url || file.url;
}

function mediaFileLabel(count) {
  return t(Number(count) === 1 ? "media.file" : "media.files");
}

function imageDimensions(file) {
  const width = Number(file?.width ?? 0);
  const height = Number(file?.height ?? 0);

  if (width <= 0 || height <= 0) return "";

  return `${width} x ${height}px`;
}

async function load() {
  loading.value = true;
  try {
    const isUnusedFilter = usageFilter.value === "unused";
    const isVisibilityFilter =
      usageFilter.value === "public" || usageFilter.value === "private";
    const params = { sort: sortOrder.value };

    params.limit = pageSize.value;
    params.offset = (currentPage.value - 1) * pageSize.value;

    const query = search.value.trim();
    if (query !== "") params.q = query;
    if (selectedCategory.value !== null)
      params.category = selectedCategory.value;
    if (mediaType.value !== "all") params.type = mediaType.value;
    if (isVisibilityFilter) params.visibility = usageFilter.value;
    if (isUnusedFilter) params.usage = "unused";

    const res = await api.media.list(params);
    categories.value = res.meta?.categories ?? res.categories ?? [];
    mediaStats.value = res.meta?.stats ?? mediaStats.value;

    const total = res.meta?.total ?? res.data.length;
    const totalPagesFromResponse = Math.max(
      1,
      Math.ceil(total / pageSize.value),
    );
    if (total > 0 && currentPage.value > totalPagesFromResponse) {
      totalFiles.value = total;
      currentPage.value = totalPagesFromResponse;
      return;
    }
    files.value = res.data;
    totalFiles.value = total;
  } finally {
    loading.value = false;
  }
}

async function loadStats() {
  const res = await api.media.list({ limit: 1 });
  mediaStats.value = res.meta?.stats ?? mediaStats.value;
  categories.value = res.meta?.categories ?? categories.value;
}

function scheduleLoad(resetPage = false) {
  if (loadTimer) {
    clearTimeout(loadTimer);
  }

  if (resetPage) {
    clearSelection();
  }

  if (resetPage && currentPage.value !== 1) {
    currentPage.value = 1;
    return;
  }

  loadTimer = setTimeout(() => {
    load();
  }, 200);
}

function changePageSize() {
  clearSelection();
  lastSelectedIndex.value = -1;

  if (currentPage.value !== 1) {
    currentPage.value = 1;
    return;
  }

  scheduleLoad();
}

async function uploadFiles(fileList) {
  if (!Array.isArray(fileList) || fileList.length === 0) return;

  uploadError.value = "";
  uploading.value = true;
  uploadProgress.value = 0;
  const fd = new FormData();
  for (const file of fileList) {
    fd.append("media[]", file);
  }
  if (selectedCategory.value !== null) {
    fd.append("category", selectedCategory.value);
  }

  try {
    const res = await api.media.upload(fd, (progress) => {
      uploadProgress.value = Math.min(100, Math.round(progress * 100));
    });
    categories.value =
      res.meta?.categories ?? res.categories ?? categories.value;
    const uploadedCount = Array.isArray(res.data) ? res.data.length : 0;
    toast.success(
      t("media.uploadedToast", {
        count: uploadedCount,
        itemLabel: mediaFileLabel(uploadedCount),
      }),
    );
    await loadStats();
    await load();
  } catch (err) {
    uploadError.value = err.message;
  } finally {
    uploading.value = false;
    uploadProgress.value = 0;
  }
}

async function regenerateThumbnails(fileNames = []) {
  const names = Array.from(new Set(fileNames.filter(Boolean)));
  regeneratingThumbs.value = true;

  try {
    const { generated, failed } = await regenerateInBatches(names);

    if (failed > 0) {
      toast.error(t("media.regenerateThumbsFailed", { count: failed }));
    } else {
      toast.success(t("media.regenerateThumbsDone", { count: generated }));
    }

    if (names.length > 0) clearSelection();
    await load();
  } catch (err) {
    toast.error(err.message ?? t("media.regenerateThumbsError"));
  } finally {
    regeneratingThumbs.value = false;
  }
}

function onFileChange(e) {
  const selectedFiles = Array.from(e.target.files ?? []);
  uploadFiles(selectedFiles);
  fileInput.value.value = "";
}

function onDrop(e) {
  const dropped = Array.from(e.dataTransfer.files ?? []);
  uploadFiles(dropped);
}

// OS file drag tracking — use a counter to avoid flickering on dragenter/dragleave
// across child elements. Only activates for real file drags, not internal card drags.
function onContentDragEnter(e) {
  if (draggedFile.value || !canUpload.value) return; // internal card drag — ignore
  if (!e.dataTransfer?.types?.includes("Files")) return;
  dragEnterCount++;
  isDraggingFile.value = true;
}

function onContentDragLeave() {
  if (draggedFile.value) return;
  dragEnterCount = Math.max(0, dragEnterCount - 1);
  if (dragEnterCount === 0) isDraggingFile.value = false;
}

function onContentDrop(e) {
  if (draggedFile.value || !canUpload.value) return; // let the card drag handlers take over
  dragEnterCount = 0;
  isDraggingFile.value = false;
  onDrop(e);
}

function openDetail(file) {
  detailFile.value = file;
  renamingFile.value = false;
  renameFileName.value = "";
  detailAlt.value = file.alt ?? "";
  detailTitle.value = file.title ?? "";
  showDetail.value = true;
  loadUsages();
}

async function saveMetaIfChanged() {
  const file = detailFile.value;
  if (!file) return;

  const alt = detailAlt.value;
  const title = detailTitle.value;

  if (JSON.stringify(alt) === JSON.stringify(file.alt ?? "") && JSON.stringify(title) === JSON.stringify(file.title ?? "")) return;

  savingMeta.value = true;
  try {
    const res = await api.media.updateMeta(file.name, alt, title, file.revision);
    const updated = res.data;
    files.value = files.value.map((item) =>
      item.name === file.name ? updated : item,
    );
    detailFile.value = updated;
    if (JSON.stringify(detailAlt.value) === JSON.stringify(alt)) detailAlt.value = updated.alt ?? "";
    if (JSON.stringify(detailTitle.value) === JSON.stringify(title)) detailTitle.value = updated.title ?? "";
  } catch (err) {
    toast.error(err.message);
  } finally {
    savingMeta.value = false;
  }
}

function startRenameFile(file) {
  renamingFile.value = true;
  renameFileName.value = file.name;
}

function cancelRenameFile() {
  renamingFile.value = false;
  renameFileName.value = "";
}

async function executeRenameFile() {
  const file = detailFile.value;
  const name = renameFileName.value.trim();

  if (!file || !name) return;

  renamingFileWorking.value = true;
  try {
    const res = await api.media.rename(file.name, name);
    const renamed = res.data;
    files.value = files.value.map((item) =>
      item.name === file.name ? renamed : item,
    );

    if (selectedMedia.value.has(file.name)) {
      const next = new Set(selectedMedia.value);
      next.delete(file.name);
      next.add(renamed.name);
      selectedMedia.value = next;
    }

    detailFile.value = renamed;
    cancelRenameFile();
    detailAlt.value = renamed.alt ?? "";
    detailTitle.value = renamed.title ?? "";
    toast.success(t("media.renamed"));
    await loadStats();
    await load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    renamingFileWorking.value = false;
  }
}

function confirmDelete(file) {
  deleteTarget.value = file;
  showDeleteModal.value = true;
}

async function executeDelete() {
  const file = deleteTarget.value;
  deleting.value = true;
  try {
    await api.media.delete(file.name);
    toast.success(t("media.deleted"));
    files.value = files.value.filter((f) => f.name !== file.name);
    selectedMedia.value = new Set(
      selectedNames.value.filter((name) => name !== file.name),
    );
    if (detailFile.value?.name === file.name) showDetail.value = false;
    showDeleteModal.value = false;
    await loadStats();
    await load();
  } catch (err) {
    toast.error(err.message);
    showDeleteModal.value = false;
  } finally {
    deleting.value = false;
    deleteTarget.value = null;
  }
}

function isSelected(file) {
  return selectedMedia.value.has(file.name);
}

function toggleSelection(file, selected) {
  const next = new Set(selectedMedia.value);

  if (selected) {
    next.add(file.name);
  } else {
    next.delete(file.name);
  }

  selectedMedia.value = next;
}

async function selectAllMatchingMedia() {
  selectingAllMedia.value = true;

  try {
    const params = { sort: sortOrder.value };
    const query = search.value.trim();

    if (query !== "") params.q = query;
    if (selectedCategory.value !== null)
      params.category = selectedCategory.value;
    if (mediaType.value !== "all") params.type = mediaType.value;
    if (usageFilter.value === "public" || usageFilter.value === "private") {
      params.visibility = usageFilter.value;
    }
    if (usageFilter.value === "unused") params.usage = "unused";

    const res = await api.media.list(params);
    const matchingNames = (res.data ?? [])
      .map((file) => file.name)
      .filter(Boolean);

    selectedMedia.value = new Set(matchingNames);
    lastSelectedIndex.value = -1;
  } catch (err) {
    toast.error(err.message);
  } finally {
    selectingAllMedia.value = false;
  }
}

function clearSelection() {
  selectedMedia.value = new Set();
  lastSelectedIndex.value = -1;
}

function onCheckboxClick(file, fileIndex, event) {
  const willBeChecked = !isSelected(file);
  if (
    event.shiftKey &&
    lastSelectedIndex.value >= 0 &&
    lastSelectedIndex.value !== fileIndex
  ) {
    const min = Math.min(lastSelectedIndex.value, fileIndex);
    const max = Math.max(lastSelectedIndex.value, fileIndex);
    const next = new Set(selectedMedia.value);
    for (let i = min; i <= max; i++) {
      if (files.value[i]) {
        if (willBeChecked) {
          next.add(files.value[i].name);
        } else {
          next.delete(files.value[i].name);
        }
      }
    }
    selectedMedia.value = next;
  } else {
    toggleSelection(file, willBeChecked);
    lastSelectedIndex.value = fileIndex;
  }
}

async function onBulkApply({ field, value }) {
  if (field === "category") {
    await updateFilesCategory(selectedNames.value, value, true);
  } else if (field === "visibility") {
    await updateFilesVisibility(selectedNames.value, value, true);
  } else if (field === "regenerate-thumbnails") {
    await regenerateThumbnails(selectedNames.value);
  }
}

async function updateFilesCategory(
  fileNames,
  category,
  clearAfterUpdate = false,
) {
  const names = Array.from(new Set(fileNames.filter(Boolean)));
  if (names.length === 0) return;

  bulkWorking.value = true;
  try {
    const res = await api.media.bulkUpdateCategory(names, category);
    const updatedByName = new Map(
      (res.data ?? []).map((file) => [file.name, file]),
    );
    categories.value =
      res.meta?.categories ?? res.categories ?? categories.value;
    files.value = files.value.map(
      (file) => updatedByName.get(file.name) ?? file,
    );
    if (detailFile.value) {
      detailFile.value =
        files.value.find((file) => file.name === detailFile.value?.name) ??
        detailFile.value;
    }
    toast.success(
      t("media.updatedToast", {
        count: updatedByName.size,
        itemLabel: mediaFileLabel(updatedByName.size),
      }),
    );
    if (clearAfterUpdate) clearSelection();
    await loadStats();
    await load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    bulkWorking.value = false;
  }
}

function confirmBulkDelete() {
  if (selectedCount.value === 0) return;
  showBulkDeleteModal.value = true;
}

async function executeBulkDelete() {
  if (selectedCount.value === 0) return;

  bulkWorking.value = true;
  try {
    const res = await api.media.bulkDelete(selectedNames.value);
    const deleted = new Set(res.data?.deleted ?? selectedNames.value);
    files.value = files.value.filter((file) => !deleted.has(file.name));
    selectedMedia.value = new Set(
      selectedNames.value.filter((name) => !deleted.has(name)),
    );
    if (detailFile.value && deleted.has(detailFile.value.name))
      showDetail.value = false;
    showBulkDeleteModal.value = false;
    toast.success(
      t("media.deletedToast", {
        count: deleted.size,
        itemLabel: mediaFileLabel(deleted.size),
      }),
    );
    await loadStats();
    await load();
  } catch (err) {
    toast.error(err.message);
    showBulkDeleteModal.value = false;
  } finally {
    bulkWorking.value = false;
  }
}

async function onDetailCategoryChange(category) {
  if (!detailFile.value) return;
  await updateFileCategory(detailFile.value, category);
  // keep detailFile in sync
  detailFile.value =
    files.value.find((f) => f.name === detailFile.value?.name) ??
    detailFile.value;
}

async function onDetailVisibilityChange(visibility) {
  if (!detailFile.value) return;
  await updateFileVisibility(detailFile.value, visibility);
  detailFile.value =
    files.value.find((f) => f.name === detailFile.value?.name) ??
    detailFile.value;
}

async function updateFileCategory(file, category) {
  try {
    const res = await api.media.updateCategory(file.name, category);
    categories.value =
      res.meta?.categories ?? res.categories ?? categories.value;
    files.value = files.value.map((item) =>
      item.name === file.name ? res.data : item,
    );
    await loadStats();
    await load();
  } catch (err) {
    toast.error(err.message);
  }
}

async function updateFileVisibility(file, visibility) {
  try {
    const res = await api.media.updateVisibility(file.name, visibility);
    files.value = files.value.map((item) =>
      item.name === file.name ? res.data : item,
    );
    await loadStats();
    await load();
  } catch (err) {
    toast.error(err.message);
  }
}

async function updateFilesVisibility(
  fileNames,
  visibility,
  clearAfterUpdate = false,
) {
  const names = Array.from(new Set(fileNames.filter(Boolean)));
  if (names.length === 0) return;

  bulkWorking.value = true;
  try {
    const res = await api.media.bulkUpdateVisibility(names, visibility);
    const updatedByName = new Map(
      (res.data ?? []).map((file) => [file.name, file]),
    );
    files.value = files.value.map(
      (file) => updatedByName.get(file.name) ?? file,
    );
    if (detailFile.value) {
      detailFile.value =
        files.value.find((file) => file.name === detailFile.value?.name) ??
        detailFile.value;
    }
    toast.success(
      t("media.updatedToast", {
        count: updatedByName.size,
        itemLabel: mediaFileLabel(updatedByName.size),
      }),
    );
    if (clearAfterUpdate) clearSelection();
    await loadStats();
    await load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    bulkWorking.value = false;
  }
}

function onFileDragStart(file, event) {
  draggedFile.value = file;
  event.dataTransfer.effectAllowed = "move";
  event.dataTransfer.setData("text/plain", file.name);
}

function onFileDragEnd() {
  draggedFile.value = null;
}

// ---- Sidebar event handlers ----
function onCategoriesUpdated(cats) {
  categories.value = cats;
  loadStats();
  load();
}

function onCategoryRenamed({ from, to }) {
  if (
    selectedCategory.value === from ||
    categoryMatchesPath(selectedCategory.value ?? "", from)
  ) {
    selectedCategory.value =
      selectedCategory.value === from
        ? to
        : selectedCategory.value.replace(from, to);
  }
}

function onCategoryDeleted(category) {
  if (
    selectedCategory.value === category ||
    categoryMatchesPath(selectedCategory.value ?? "", category)
  ) {
    selectedCategory.value = null;
  }
}

async function onCategoryFileDrop({ file, targetCategory }) {
  draggedFile.value = null;
  if (selectedCount.value > 0) {
    await updateFilesCategory(
      [...selectedNames.value, file.name],
      targetCategory,
      true,
    );
    return;
  }
  if ((file.category ?? "") === targetCategory) return;
  await updateFileCategory(file, targetCategory);
}

function copyUrl(url) {
  navigator.clipboard.writeText(url);
  toast.success(t("media.urlCopied"));
}

function formatDate(iso) {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" });
}

function closeSortFilterMenu(event) {
  if (
    sortFilterMenuRef.value &&
    !sortFilterMenuRef.value.contains(event.target)
  ) {
    sortFilterMenuOpen.value = false;
  }
}

onMounted(() => {
  load();
  loadUsers();
  document.addEventListener("pointerdown", closeSortFilterMenu);
});

onBeforeUnmount(() => {
  if (loadTimer) clearTimeout(loadTimer);
  document.removeEventListener("pointerdown", closeSortFilterMenu);
  apiEndpointStore.clearEndpoint(apiEndpointOwner);
});

watch(
  apiEndpointUrl,
  (url) => {
    apiEndpointStore.setEndpoint(
      {
        label: "Media",
        url,
      },
      apiEndpointOwner,
    );
  },
  { immediate: true },
);

watch([search, selectedCategory, mediaType, sortOrder, usageFilter], () => {
  scheduleLoad(true);
});

watch(currentPage, () => {
  lastSelectedIndex.value = -1;
  scheduleLoad();
});

watch(viewMode, (mode) => {
  localStorage.setItem("mediaViewMode", mode);
});
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
