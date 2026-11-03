<template>
  <div>
    <PageHeader
      :title="contentTypeSchema?.label || collection"
      :icon="contentTypeIcon(contentTypeSchema)"
      :description="col.loading ? '' : `${totalEntries} ${entryLabel(totalEntries)}`"
    >
      <template #actions>
        <nav v-if="canEditTemplates" class="flex flex-wrap items-center gap-1.5"
          :aria-label="t('site.collection.templates')">

          <SplitButton
            v-if="hasListPage"
            variant="secondary"
            :menu-label="t('site.collection.templates')"
            :items="templateMenuItems"
            @click="editCollectionTemplate('index')"
            @select="editCollectionTemplate($event.key)"
          >
            <Icon icon="mdi:palette-outline" class="h-4 w-4" />
            {{ t('site.collection.designIndex') }}
          </SplitButton>
          <!-- Without a list page, the entry page is the only template. -->
          <button
            v-else
            type="button"
            class="btn-secondary"
            :title="t('site.collection.editTemplate', { template: t('site.variant.detail') })"
            @click="editCollectionTemplate('detail')"
          >
            <Icon icon="mdi:palette-outline" class="h-4 w-4" />
            {{ t('site.collection.designDetail') }}
          </button>
        </nav>

        <router-link
          v-if="trashCount > 0 && (canContent('content.restore', collection) || canContent('content.delete', collection))"
          :to="`/trash/${collection}`" class="btn-secondary">
          <Icon icon="mdi:trash-can-outline" class="h-4 w-4" />
          {{ t("contentList.trash") }}
          <span
            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-slate-100 px-1.5 text-xs font-medium text-slate-600">{{
              trashCount }}</span>
        </router-link>

        <!-- Vertical separator -->
        <div v-if="canEditTemplates && canContent('content.create', collection)"
          class="mx-1 h-6 w-px shrink-0 bg-slate-200" aria-hidden="true"></div>

        <router-link v-if="canContent('content.create', collection)" :to="`/content/${collection}/new`"
          class="btn-primary">
          <Icon icon="mdi:plus" class="h-4 w-4" />
          {{ t("contentList.newEntry") }}
        </router-link>
      </template>
    </PageHeader>

    <LoadingSpinner v-if="col.loading" />

    <template v-else>
      <!-- Selection toolbar -->
      <Transition v-bind="ht">
        <div v-if="selectedIds.size > 0" class="card p-3 mb-3 border-theme-200 bg-theme-50">
          <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div class="flex items-center gap-3 shrink-0">
              <span class="text-sm font-semibold text-theme-700">
                {{
                  t("contentList.selected", {
                    count: selectAllPages ? totalEntries : selectedIds.size,
                    itemLabel: entryLabel(
                      selectAllPages ? totalEntries : selectedIds.size,
                    ),
                  })
                }}
              </span>
              <button v-if="!selectAllPages && totalEntries > sortedEntries.length" type="button"
                class="text-xs text-theme-600 hover:text-theme-700 font-medium underline-offset-2 underline"
                @click="triggerSelectAllPages">
                {{ t("contentList.selectAll", { count: totalEntries }) }}
              </button>
              <button type="button" class="text-xs text-slate-500 hover:text-slate-700 font-medium"
                @click="deselectAll">
                {{ t("contentList.clear") }}
              </button>
            </div>
            <div class="w-px h-5 bg-theme-200 shrink-0 hidden sm:block"></div>
            <BulkEditBar v-if="canContent('content.update', collection)" :content-type="contentTypeSchema"
              :users="users" :selected-count="selectAllPages ? totalEntries : selectedIds.size" :applying="bulkApplying"
              class="flex-1 min-w-0" @apply="applyBulkEdit" />
            <div class="w-px h-5 bg-theme-200 shrink-0 hidden sm:block"></div>
            <button v-if="canContent('content.create', collection)" type="button"
              :disabled="bulkDeleting || bulkDuplicating" :title="t('contentList.duplicateSelected')"
              :aria-label="t('contentList.duplicateSelected')"
              class="btn-secondary py-1.5 px-3 text-sm disabled:opacity-40 whitespace-nowrap shrink-0 inline-flex items-center gap-1.5"
              @click="duplicateSelected">
              <Icon icon="mdi:content-copy" class="w-4 h-4" />
            </button>
            <div class="w-px h-5 bg-theme-200 shrink-0 hidden sm:block"></div>
            <button v-if="canContent('content.delete', collection)" type="button" :disabled="bulkDeleting"
              :title="t('contentList.moveToTrash')" :aria-label="t('contentList.moveToTrash')"
              class="btn-danger-subtle whitespace-nowrap shrink-0 px-3 py-1.5" @click="confirmBulkDelete">
              <Icon icon="mdi:trash-can-outline" class="w-4 h-4" />
            </button>
          </div>

        </div>
      </Transition>

      <ConfirmModal v-model="showBulkDeleteModal" :title="t('contentList.moveToTrash')" :message="t('contentList.moveMessage', {
        count: selectAllPages ? totalEntries : selectedIds.size,
        itemLabel: entryLabel(
          selectAllPages ? totalEntries : selectedIds.size,
        ),
      })
        " :confirm-label="t('contentList.moveToTrash')" variant="danger" :loading="bulkDeleting"
        @confirm="applyBulkDelete" />

      <div class="card">
        <!-- Toolbar -->
        <div class="border-b border-slate-200 p-3">
      <div class="flex items-center gap-2 flex-wrap">
        <div class="min-w-[240px] flex-1">
          <label for="content-search" class="sr-only">{{
            t("contentList.search")
          }}</label>
          <div class="relative">
            <Icon icon="mdi:magnify"
              class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input id="content-search" v-model="filters.q" type="text" :placeholder="t('contentList.searchPlaceholder')"
              class="form-input w-full rounded-lg border-slate-300 pl-9 text-sm" @keydown.enter="applyFilters" />
          </div>
        </div>

        <button v-if="hasActiveFilters" type="button" @click="clearFilters" class="btn-secondary">
          <Icon icon="mdi:close" class="h-4 w-4" />
          {{ t("contentList.clear") }}
        </button>

        <!-- Columns picker -->
        <div class="relative" ref="colPickerRef">
          <button type="button" @click="colPickerOpen = !colPickerOpen" class="btn-secondary flex items-center gap-1.5">
            <Icon icon="mdi:table-column" class="h-4 w-4" />
            {{ t("contentList.columns") }}
            <Icon icon="mdi:chevron-down" class="h-3.5 w-3.5 transition-transform duration-150"
              :class="colPickerOpen ? 'rotate-180' : ''" />
          </button>
          <div v-if="colPickerOpen"
            class="absolute right-0 top-full mt-1 z-50 max-h-80 w-64 overflow-y-auto bg-white border border-slate-200 rounded-xl shadow-xl p-1">
            <label v-for="column in tableColumns" :key="column.key"
              class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 hover:bg-slate-50 cursor-pointer text-sm text-slate-700 select-none"
              :class="visibleColumns[column.key] && displayedColumns.length === 1
                ? 'opacity-40 cursor-not-allowed'
                : ''
                ">
              <input type="checkbox" class="rounded border-slate-300 text-theme-600 cursor-pointer"
                :checked="visibleColumns[column.key]" :disabled="visibleColumns[column.key] && displayedColumns.length === 1
                  " @change="toggleColumn(column.key)" />
              {{ column.label }}
            </label>
            <template v-if="postTypeFieldColumns.length > 0">
              <div class="border-t border-slate-100 my-1"></div>
              <label v-for="column in postTypeFieldColumns" :key="column.key"
                class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 hover:bg-slate-50 cursor-pointer text-sm text-slate-700 select-none"
                :class="visibleColumns[column.key] && displayedColumns.length === 1
                  ? 'opacity-40 cursor-not-allowed'
                  : ''
                  ">
                <input type="checkbox" class="rounded border-slate-300 text-theme-600 cursor-pointer"
                  :checked="visibleColumns[column.key]" :disabled="visibleColumns[column.key] && displayedColumns.length === 1
                    " @change="toggleColumn(column.key)" />
                <span class="min-w-0 flex-1 truncate">{{ column.label }}</span>
                <span class="shrink-0 text-[10px] uppercase tracking-wide text-slate-400">{{ column.type }}</span>
              </label>
            </template>
            <div class="border-t border-slate-100 mt-1 pt-1 px-3 pb-1">
              <button type="button" class="text-xs text-theme-600 hover:text-theme-700 font-medium"
                @click="resetColumns">
                {{ t("contentList.resetColumns") }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

        <div class="overflow-x-auto">
          <table class="data-table min-w-full">
            <thead>
              <tr class="group/thead">
                <th v-if="canSelectEntries" class="w-10 !px-3">
                  <input type="checkbox" :aria-label="t('contentList.selectPage')"
                    class="rounded border-slate-300 text-theme-600 cursor-pointer transition-opacity" :class="selectedIds.size > 0
                      ? 'opacity-100'
                      : 'sm:opacity-0 sm:group-hover/thead:opacity-100 focus-visible:opacity-100'
                      " :checked="allPageSelected" :indeterminate.prop="someSelected && !allPageSelected"
                    @change="toggleAllPage" />
                </th>
                <th v-for="column in displayedColumns" :key="column.key" :class="[
                  'select-none whitespace-nowrap',
                  column.sortKey && !treeMode
                    ? 'cursor-pointer hover:text-slate-700'
                    : 'cursor-default',
                ]" @click="column.sortKey && !treeMode ? setSort(column.sortKey) : undefined">
                  <span class="inline-flex items-center gap-1">
                    {{ column.label }}
                    <template v-if="column.sortKey && !treeMode">
                      <Icon v-if="sortKey === column.sortKey" :icon="sortDir === 'asc' ? 'mdi:arrow-up' : 'mdi:arrow-down'
                        " class="w-3.5 h-3.5" />
                      <Icon v-else icon="mdi:unfold-more-horizontal" class="w-3.5 h-3.5 opacity-30" />
                    </template>
                  </span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="col.entries.length === 0" class="hover:!bg-transparent">
                <td :colspan="displayedColumns.length + (canSelectEntries ? 1 : 0)">
                  <EmptyState
                    :icon="hasActiveFilters ? 'mdi:magnify-close' : contentTypeIcon(contentTypeSchema)"
                    :title="hasActiveFilters ? t('contentList.noMatches') : t('contentList.noEntries')"
                    :description="hasActiveFilters ? '' : t('contentList.emptyHint')"
                  >
                    <button v-if="hasActiveFilters" type="button" class="btn-secondary" @click="clearFilters">
                      {{ t("contentList.clear") }}
                    </button>
                    <router-link v-else-if="canContent('content.create', collection)"
                      :to="`/content/${collection}/new`" class="btn-primary">
                      <Icon icon="mdi:plus" class="h-4 w-4" />
                      {{ t("contentList.newEntry") }}
                    </router-link>
                  </EmptyState>
                </td>
              </tr>
              <tr v-for="({ entry, depth, hasChildren, path }, rowIndex) in displayRows" :key="entry.id" class="cursor-pointer group" :class="[
                selectedIds.has(entry.id) ? '!bg-theme-50 hover:!bg-theme-100' : '',
                dropZone?.rowKey === entryKey(entry) ? `tree-drop-${dropZone.visual}` : '',
                dragKey === entryKey(entry) ? 'opacity-50' : '',
              ]" @click="router.push(`/content/${collection}/${entry.uid || entry.id}`)"
                @dragover="treeDragOver($event, rowIndex)" @dragleave="treeDragLeave($event, entry)" @drop.prevent="treeDrop">
                <td v-if="canSelectEntries" class="w-10 !px-3" @click.stop>
                  <input type="checkbox"
                    :aria-label="t('contentList.selectEntry', { title: entry.title || entry.slug })"
                    class="rounded border-slate-300 text-theme-600 cursor-pointer transition-opacity" :class="selectedIds.size > 0
                      ? 'opacity-100'
                      : 'sm:opacity-0 sm:group-hover:opacity-100 focus-visible:opacity-100'
                      " :checked="selectedIds.has(entry.id)" @change="toggleSelection(entry.id)" />
                </td>
                <td v-for="column in displayedColumns" :key="column.key"
                  :class="[cellClass(column), treeMode && column.key === 'title' ? 'tree-title-cell' : '']"
                  :style="treeMode && column.key === 'title' ? { '--tree-drop-indent': `${treeIndent(dropZone?.rowKey === entryKey(entry) ? dropZone.depth : depth)}px` } : undefined">
                  <ContentListCell :column="column" :entry="entry" :users="users"
                    :saving="savingCell === cellSaveKey(entry.id, column)" @save="handleInlineSave">
                    <template v-if="column.kind === 'core'">
                      <template v-if="column.key === 'title'">
                        <div class="flex items-center gap-1.5">
                          <template v-if="treeMode">
                            <button v-if="canMoveEntries" type="button" draggable="true"
                              class="-ml-2 shrink-0 cursor-grab rounded p-0.5 text-slate-300 opacity-0 hover:bg-slate-100 hover:text-slate-500 focus-visible:opacity-100 active:cursor-grabbing group-hover:opacity-100"
                              :aria-label="t('contentList.dragEntry', { title: entry.title || entry.slug })"
                              :title="t('contentList.dragEntry', { title: entry.title || entry.slug })"
                              @click.stop @dragstart.stop="treeDragStart($event, entry)" @dragend="treeDragEnd">
                              <Icon icon="mdi:drag-vertical" class="h-4 w-4" />
                            </button>
                            <span v-for="level in depth" :key="level" class="tree-guide"
                              :class="{ 'tree-guide-branch': level === depth }" aria-hidden="true"></span>
                            <button v-if="hasChildren" type="button"
                              class="shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                              :aria-expanded="!collapsed.has(entryKey(entry))"
                              :aria-label="t(collapsed.has(entryKey(entry)) ? 'contentList.expand' : 'contentList.collapse', { title: entry.title || entry.slug })"
                              @click.stop="toggleCollapsed(entry)">
                              <Icon icon="mdi:chevron-right" class="h-4 w-4 transition-transform duration-150"
                                :class="collapsed.has(entryKey(entry)) ? '' : 'rotate-90'" />
                            </button>
                            <span v-else-if="treeHasBranches" class="w-5 shrink-0" aria-hidden="true"></span>
                          </template>
                          <span class="min-w-0">
                            <span class="inline-flex items-center gap-1.5">
                              {{ entry.title ?? entry.id }}
                              <Icon v-if="homepageEntry && [entry.uid, entry.id, entry.slug].includes(homepageEntry)"
                                icon="mdi:home" class="h-4 w-4 shrink-0 text-theme-600"
                                role="img" :aria-label="t('site.overview.homepage')"
                                :title="t('site.overview.homepage')" />
                            </span>
                            <span v-if="treeMode" class="block truncate text-xs font-normal text-slate-400">/{{ path }}</span>
                          </span>
                          <router-link v-if="treeMode && canContent('content.create', collection)"
                            :to="{ path: `/content/${collection}/new`, query: { parent: entryKey(entry) } }"
                            class="ml-auto shrink-0 rounded p-1 text-slate-400 opacity-0 hover:bg-slate-100 hover:text-theme-600 focus-visible:opacity-100 group-hover:opacity-100"
                            :aria-label="t('contentList.addChild', { title: entry.title || entry.slug })"
                            :title="t('contentList.addChild', { title: entry.title || entry.slug })"
                            @click.stop>
                            <Icon icon="mdi:plus" class="h-4 w-4" />
                          </router-link>
                        </div>
                      </template>
                      <template v-else-if="column.key === 'status'">
                        <span :class="statusPillClass(effectiveStatus(entry))">
                          {{ formatStatus(effectiveStatus(entry)) }}
                        </span>
                      </template>
                      <template v-else-if="column.key === 'locales'">
                        <div v-if="entryLocaleBadges(entry).length > 0" class="flex items-center gap-1 flex-wrap">
                          <span v-for="loc in entryLocaleBadges(entry)" :key="loc"
                            class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium bg-slate-100 text-slate-600 ring-1 ring-slate-200 ring-inset">
                            <span v-if="localeFlagCountry(loc)"
                              :class="['fi', `fi-${localeFlagCountry(loc)}`, 'shrink-0 rounded-sm']"
                              aria-hidden="true"
                              :title="localeName(loc)" />
                          </span>
                        </div>
                        <span v-else class="text-slate-400">—</span>
                      </template>
                      <template v-else-if="column.key === 'author'">
                        <template v-if="userMap[entry.author_id]">
                          <div class="flex items-center gap-2">
                            <div
                              class="w-6 h-6 rounded-full overflow-hidden bg-theme-600 flex items-center justify-center text-white text-xs font-semibold select-none shrink-0">
                              <img v-if="userMap[entry.author_id].has_avatar"
                                :src="`${ADMIN_API_BASE}/users/${entry.author_id}/avatar`"
                                class="w-full h-full object-cover" :alt="userMap[entry.author_id].username" />
                              <span v-else>{{
                                userMap[
                                  entry.author_id
                                ].username?.[0]?.toUpperCase()
                              }}</span>
                            </div>
                            <span class="text-slate-700">{{
                              userMap[entry.author_id].username
                            }}</span>
                          </div>
                        </template>
                        <span v-else class="text-slate-400">{{
                          entry.author_id ?? "—"
                        }}</span>
                      </template>
                      <template v-else-if="column.key === 'created'">
                        {{ formatDate(entry.created_at) }}
                      </template>
                      <template v-else-if="column.key === 'updated'">
                        {{ formatDate(entry.updated_at) }}
                      </template>
                      <template v-else-if="column.key === 'scheduled'">
                        {{ formatDate(entry.published_at) }}
                      </template>
                    </template>

                    <template v-else>
                      <template v-if="column.type === 'media'">
                        <div v-if="
                          mediaValuesFor(entry, column.fieldKey).length > 0
                        " class="flex items-center gap-1.5">
                          <div v-for="file in mediaValuesFor(
                            entry,
                            column.fieldKey,
                          ).slice(0, 3)" :key="file"
                            class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-slate-100"
                            :title="file">
                            <img v-if="isImage(file)" :src="mediaThumbUrl(file)" :alt="file"
                              class="h-full w-full object-cover" />
                            <Icon v-else v-bind="getFileIcon(file)" class="h-5 w-5" />
                          </div>
                          <span v-if="
                            mediaValuesFor(entry, column.fieldKey).length > 3
                          " class="text-xs font-semibold text-slate-500">
                            +{{
                              mediaValuesFor(entry, column.fieldKey).length - 3
                            }}
                          </span>
                        </div>
                        <span v-else class="text-slate-400">—</span>
                      </template>
                      <template v-else-if="column.type === 'boolean'">
                        <span v-if="
                          fieldValue(entry, column.fieldKey) === null ||
                          fieldValue(entry, column.fieldKey) === undefined ||
                          fieldValue(entry, column.fieldKey) === ''
                        " class="text-slate-400">—</span>
                        <span v-else :class="booleanPillClass(fieldValue(entry, column.fieldKey))
                          ">
                          {{
                            formatBoolean(fieldValue(entry, column.fieldKey))
                          }}
                        </span>
                      </template>
                      <template v-else-if="
                        column.type === 'number' || column.type === 'range'
                      ">
                        <span :title="formatNumberField(
                          fieldValue(entry, column.fieldKey),
                          column.config,
                          locale,
                        )
                          ">
                          {{
                            formatNumberField(
                              fieldValue(entry, column.fieldKey),
                              column.config,
                              locale,
                            )
                          }}
                        </span>
                      </template>
                      <template v-else-if="
                        column.type === 'date' || column.type === 'datetime'
                      ">
                        {{
                          formatFieldDate(
                            fieldValue(entry, column.fieldKey),
                            column.type,
                          )
                        }}
                      </template>
                      <template v-else-if="column.type === 'select'">
                        <span class="block max-w-72 truncate" :title="formatSelectField(
                          fieldValue(entry, column.fieldKey),
                          column.config,
                          false,
                        )
                          ">
                          {{
                            formatSelectField(
                              fieldValue(entry, column.fieldKey),
                              column.config,
                            )
                          }}
                        </span>
                      </template>
                      <template v-else>
                        <span class="block max-w-72 truncate" :title="fieldTextValue(
                          fieldValue(entry, column.fieldKey),
                          false,
                        )
                          ">
                          {{
                            fieldTextValue(fieldValue(entry, column.fieldKey))
                          }}
                        </span>
                      </template>
                    </template>
                  </ContentListCell>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

      <!-- Pagination -->
      <div v-if="!treeMode" class="flex flex-wrap gap-2 items-center justify-between border-t border-slate-200 px-4 py-2.5 text-sm text-slate-500">
        <span>
          {{ t("contentList.showingPrefix") }} {{ pageStart }}-
          <InlinePageSizeSelect v-model="pageSize" :display-value="pageEnd" :options="pageSizeOptions"
            :aria-label="t('contentList.rowsPerPage')" @change="changePageSize" />
          {{ t("contentList.showingOf") }} {{ totalEntries }}
          {{ entryLabel(totalEntries) }}
        </span>
        <div class="flex gap-2">
          <button type="button" :disabled="!canPageBackward" @click="changePage(-1)"
            class="btn-secondary btn-sm">
            {{ t("contentList.prev") }}
          </button>
          <button type="button" :disabled="!canPageForward" @click="changePage(1)"
            class="btn-secondary btn-sm">
            {{ t("contentList.next") }}
          </button>
        </div>
      </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ADMIN_API_BASE } from "../basePath.js";
import LoadingSpinner from "../components/LoadingSpinner.vue";
import BulkEditBar from "../components/BulkEditBar.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import ContentListCell from "../components/ContentListCell.vue";
import InlinePageSizeSelect from "../components/InlinePageSizeSelect.vue";
import SplitButton from "../components/SplitButton.vue";
import PageHeader from "../components/PageHeader.vue";
import EmptyState from "../components/EmptyState.vue";
import { contentTypeIcon } from "../composables/useNavigation.js";
import { Icon } from "@iconify/vue";
import { ref, computed, onMounted, onBeforeUnmount, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useHeightTransition } from "../composables/useHeightTransition.js";

const ht = useHeightTransition();
import { useContentStore } from "../stores/content.js";
import { useToastStore } from "../stores/toast.js";
import { useAuthStore } from "../stores/auth.js";
import { usePermissions } from "../composables/usePermissions.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import { api, getActiveWorkspace } from "../api/index.js";
import { contentCollectionEndpoint } from "../composables/apiEndpoint.js";
import { resolveLocalizedTree } from "../composables/localizedValues.js";
import { useI18n } from "../i18n/index.js";
import {
  localeFlagCountry,
  localeName,
} from "../composables/localeOptions.js";
import {
  apiSortKey,
  booleanPillClass,
  boolValue,
  effectiveStatus,
  fieldColumnKey,
  fieldKeyFromColumnKey,
  fieldLabel,
  fieldTextValue as formatFieldTextValue,
  fieldValue,
  formatNumberField,
  formatSelectField,
  isFieldSortKey,
  mediaValuesFor,
  normalizeFieldSortValue,
  orderLocales,
  statusPillClass,
} from "../composables/contentDisplay.js";
import { applyMove, dropPlacement, entryKey, gapTarget, isCurrentPlace, treeRows } from "../composables/entryTree.js";
import {
  getFileIcon,
  isImageFile,
  mediaThumbUrl as buildMediaThumbUrl,
} from "../composables/mediaUtils.js";

const toast = useToastStore();
const auth = useAuthStore();
const { canContent } = usePermissions();
// Selection only makes sense when at least one bulk action is available.
const canSelectEntries = computed(() =>
  ["content.update", "content.create", "content.delete"].some((action) =>
    canContent(action, collection.value),
  ),
);
const apiEndpointStore = useApiEndpointStore();
const { locale, t } = useI18n();
const apiEndpointOwner = "content-list";
const users = ref([]);
const userMap = computed(() =>
  Object.fromEntries(users.value.map((u) => [u.id, u])),
);

// ── Content type schema (for BulkEditBar field list) ─────────────────────────
const contentTypeSchema = ref(null);
const websiteStatus = ref(null);
// Status resolves homepage settings stored by either slug or stable ID.
const homepageEntry = computed(() => {
  const path = websiteStatus.value?.homepage_editor;
  if (!path) return null;
  const homepage = router.resolve(path);
  return homepage.params.target === 'layout'
    && homepage.params.name === collection.value
    && homepage.params.variant === 'detail'
    ? homepage.query.entry || null
    : null;
});
const COLLECTION_TEMPLATES = [
  { variant: "index", icon: "mdi:view-grid-outline" },
  { variant: "detail", icon: "mdi:file-document-outline" },
];
const templateMenuItems = computed(() => COLLECTION_TEMPLATES.slice(1).map((template) => ({
  key: template.variant,
  label: t(`site.variant.${template.variant}`),
  icon: template.icon,
  title: t('site.collection.editTemplate', { template: t(`site.variant.${template.variant}`) }),
})));

function editCollectionTemplate(variant) {
  router.push({
    path: `/page-builder/builder/layout/${collection.value}/${variant}`,
    query: { back: route.fullPath },
  });
}

const hasListPage = computed(() => !!websiteStatus.value?.types?.[collection.value]?.index_enabled);

const canEditTemplates = computed(() =>
  contentTypeSchema.value
  && contentTypeSchema.value.visibility !== "private"
  && websiteStatus.value?.can_manage
  && websiteStatus.value.types?.[collection.value]?.routed,
);

async function loadWebsiteStatus() {
  websiteStatus.value = null;
  const workspace = getActiveWorkspace();
  try {
    const res = await api.site.status();
    if (workspace === getActiveWorkspace()) websiteStatus.value = res.data;
  } catch {
    // Website controls are optional; content remains editable if this request fails.
  }
}

async function loadContentType() {
  contentTypeSchema.value = null;

  try {
    const res = await api.contentTypes.get(collection.value);
    contentTypeSchema.value = resolveLocalizedTree(res.data, res.data.default_locale, res.data.default_locale);

    // Auto-show locales column when the content type has locales configured
    if (
      (res.data?.locales ?? []).length > 0 &&
      !localStorage.getItem(`comet:columns:${collection.value}`)
    ) {
      visibleColumns.value = { ...visibleColumns.value, locales: true };
    }
  } catch {
    contentTypeSchema.value = null;
  }
}

// ── Bulk selection state ──────────────────────────────────────────────────────
const selectedIds = ref(new Set());
const selectAllPages = ref(false);
const bulkApplying = ref(false);
const bulkDeleting = ref(false);
const showBulkDeleteModal = ref(false);

const pageEntryIds = computed(() => displayRows.value.map((row) => row.entry.id));
const allPageSelected = computed(
  () =>
    pageEntryIds.value.length > 0 &&
    pageEntryIds.value.every((id) => selectedIds.value.has(id)),
);
const someSelected = computed(() =>
  pageEntryIds.value.some((id) => selectedIds.value.has(id)),
);

function toggleSelection(id) {
  const next = new Set(selectedIds.value);
  if (next.has(id)) {
    next.delete(id);
  } else {
    next.add(id);
  }
  selectedIds.value = next;
  selectAllPages.value = false;
}

function toggleAllPage() {
  const next = new Set(selectedIds.value);
  if (allPageSelected.value) {
    pageEntryIds.value.forEach((id) => next.delete(id));
    selectAllPages.value = false;
  } else {
    pageEntryIds.value.forEach((id) => next.add(id));
  }
  selectedIds.value = next;
}

function triggerSelectAllPages() {
  const next = new Set(selectedIds.value);
  pageEntryIds.value.forEach((id) => next.add(id));
  selectedIds.value = next;
  selectAllPages.value = true;
}

function deselectAll() {
  selectedIds.value = new Set();
  selectAllPages.value = false;
}

async function applyBulkEdit({ field, value }) {
  if (bulkApplying.value) return;
  bulkApplying.value = true;

  try {
    let ids;
    if (selectAllPages.value) {
      // Fetch all matching IDs across pages
      const query = filters.value.q.trim();
      const params = { limit: 10000, offset: 0 };
      if (query) params.q = query;
      const res = await api.content.list(collection.value, params);
      ids = (res.data ?? []).map((e) => e.id);
    } else {
      ids = [...selectedIds.value];
    }

    const result = await api.content.bulkUpdate(collection.value, ids, {
      [field]: value,
    });
    const { updated, failed } = result.data;

    if (failed > 0) {
      toast.error(
        t("contentList.bulkUpdatePartial", {
          updated,
          failed,
          itemLabel: entryLabel(failed),
        }),
      );
    } else {
      toast.success(
        t("contentList.bulkUpdated", {
          updated,
          itemLabel: entryLabel(updated),
        }),
      );
    }

    deselectAll();
  } catch (err) {
    toast.error(err.message ?? t("contentList.bulkUpdateFailed"));
    return;
  } finally {
    bulkApplying.value = false;
  }

  try {
    await load();
  } catch (err) {
    console.warn(
      "Bulk update succeeded, but refreshing the content list failed.",
      err,
    );
  }
}

function confirmBulkDelete() {
  if (selectedIds.value.size === 0 && !selectAllPages.value) return;
  showBulkDeleteModal.value = true;
}

async function applyBulkDelete() {
  if (bulkDeleting.value) return;
  bulkDeleting.value = true;

  try {
    let ids;
    if (selectAllPages.value) {
      const query = filters.value.q.trim();
      const params = { limit: 10000, offset: 0 };
      if (query) params.q = query;
      const res = await api.content.list(collection.value, params);
      ids = (res.data ?? []).map((e) => e.id);
    } else {
      ids = [...selectedIds.value];
    }

    const result = await api.content.bulkDelete(collection.value, ids);
    const { deleted, failed } = result.data;

    if (failed > 0) {
      toast.error(
        t("contentList.bulkDeletePartial", {
          deleted,
          failed,
          itemLabel: entryLabel(failed),
        }),
      );
    } else {
      toast.success(
        t("contentList.bulkDeleted", {
          deleted,
          itemLabel: entryLabel(deleted),
        }),
      );
    }

    showBulkDeleteModal.value = false;
    deselectAll();
    await load();
    await loadTrashCount();
  } catch (err) {
    toast.error(err.message ?? t("contentList.bulkDeleteFailed"));
  } finally {
    bulkDeleting.value = false;
  }
}

const tableColumns = computed(() => [
  {
    key: "title",
    label: t("contentList.title"),
    sortKey: "title",
    kind: "core",
  },
  {
    key: "status",
    label: t("contentList.status"),
    sortKey: "status",
    kind: "core",
  },
  {
    key: "locales",
    label: t("contentList.locales"),
    sortKey: null,
    kind: "core",
  },
  {
    key: "author",
    label: t("contentList.createdBy"),
    sortKey: "author",
    kind: "core",
  },
  {
    key: "created",
    label: t("contentList.created"),
    sortKey: "created_at",
    kind: "core",
  },
  {
    key: "updated",
    label: t("contentList.updated"),
    sortKey: "updated_at",
    kind: "core",
  },
  {
    key: "scheduled",
    label: t("contentList.publishDate"),
    sortKey: "published_at",
    kind: "core",
  },
]);
const defaultVisibleColumns = {
  title: true,
  status: true,
  locales: false,
  author: true,
  created: true,
  updated: true,
  scheduled: false,
};
const builtInFieldColumns = new Set(["title"]);

const postTypeFieldColumns = computed(() => {
  const fields = contentTypeSchema.value?.fields ?? {};

  return Object.entries(fields)
    .filter(([key]) => !builtInFieldColumns.has(key))
    .map(([key, config]) => ({
      key: fieldColumnKey(key),
      fieldKey: key,
      label: fieldLabel(key, config),
      sortKey: fieldColumnKey(key),
      kind: "field",
      type: config?.type ?? "text",
      config: config ?? {},
    }));
});
const allColumns = computed(() => [
  ...tableColumns.value,
  ...postTypeFieldColumns.value,
]);

function loadColumnPrefs(coll) {
  try {
    const raw = localStorage.getItem(`comet:columns:${coll}`);
    if (raw) return { ...defaultVisibleColumns, ...JSON.parse(raw) };
  } catch {
    // Ignore malformed local preferences and fall back to defaults.
  }
  return { ...defaultVisibleColumns };
}

function saveColumnPrefs(coll, prefs) {
  try {
    localStorage.setItem(`comet:columns:${coll}`, JSON.stringify(prefs));
  } catch {
    // Column preferences are non-critical UI state.
  }
}

const colPickerOpen = ref(false);
const colPickerRef = ref(null);
const visibleColumns = ref({ ...defaultVisibleColumns });
const displayedColumns = computed(() =>
  allColumns.value.filter((column) => visibleColumns.value[column.key]),
);

const sortKey = ref("created_at");
const sortDir = ref("desc");

function resetSort() {
  sortKey.value = "created_at";
  sortDir.value = "desc";
}

function defaultSortDir(key) {
  return ["updated_at", "created_at", "published_at"].includes(key)
    ? "desc"
    : "asc";
}

function setSort(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
  } else {
    sortKey.value = key;
    sortDir.value = defaultSortDir(key);
  }
  pageOffset.value = 0;
  load();
}

function toggleColumn(key) {
  const nextValue = !visibleColumns.value[key];
  if (!nextValue && displayedColumns.value.length === 1) return;

  visibleColumns.value = { ...visibleColumns.value, [key]: nextValue };
  saveColumnPrefs(collection.value, visibleColumns.value);
}

function resetColumns() {
  visibleColumns.value = { ...defaultVisibleColumns };
  saveColumnPrefs(collection.value, visibleColumns.value);
}

const sortedEntries = computed(() => {
  const entries = [...(col.value.entries ?? [])];
  const dir = sortDir.value === "asc" ? 1 : -1;
  return entries.sort((a, b) => {
    let av, bv;
    if (sortKey.value === "title") {
      av = (a.title ?? a.id ?? "").toLowerCase();
      bv = (b.title ?? b.id ?? "").toLowerCase();
    } else if (sortKey.value === "status") {
      av = a.status ?? "";
      bv = b.status ?? "";
    } else if (sortKey.value === "author") {
      av = (
        userMap.value[a.author_id]?.username ??
        a.author_id ??
        ""
      ).toLowerCase();
      bv = (
        userMap.value[b.author_id]?.username ??
        b.author_id ??
        ""
      ).toLowerCase();
    } else if (sortKey.value === "created_at") {
      av = a.created_at ?? "";
      bv = b.created_at ?? "";
    } else if (sortKey.value === "updated_at") {
      av = a.updated_at ?? "";
      bv = b.updated_at ?? "";
    } else if (sortKey.value === "published_at") {
      av = a.published_at ?? "";
      bv = b.published_at ?? "";
    } else if (isFieldSortKey(sortKey.value)) {
      const field = fieldKeyFromColumnKey(sortKey.value);
      av = normalizeFieldSortValue(fieldValue(a, field));
      bv = normalizeFieldSortValue(fieldValue(b, field));
    } else {
      av = a.updated_at ?? a.created_at ?? "";
      bv = b.updated_at ?? b.created_at ?? "";
    }
    if (av < bv) return -1 * dir;
    if (av > bv) return 1 * dir;
    return 0;
  });
});

const route = useRoute();
const router = useRouter();
const collection = computed(() => route.params.collection);

// ── Page hierarchy ────────────────────────────────────────────────────────────
// Hierarchical types list all pages as a tree; a search shows matches flat.
const treeMode = computed(() => !!contentTypeSchema.value?.hierarchical && !hasActiveFilters.value);
const canMoveEntries = computed(() => canContent("content.update", collection.value));
const collapsed = ref(new Set());
const displayRows = computed(() => treeMode.value
  ? treeRows(col.value.entries ?? [], collapsed.value)
  : sortedEntries.value.map((entry) => ({ entry, depth: 0, hasChildren: false, path: entry.slug ?? "" })));
// Leaves line up with branches only when some entry can expand.
const treeHasBranches = computed(() => displayRows.value.some((row) => row.hasChildren));
const dragKey = ref("");
// { key, zone }: the row and where the dragged page would go.
const dropZone = ref(null);
// Expands a collapsed page after hovering over its middle for a moment.
let expandTimer = null;

function toggleCollapsed(entry) {
  const next = new Set(collapsed.value);
  const key = entryKey(entry);
  if (next.has(key)) next.delete(key);
  else next.add(key);
  collapsed.value = next;
}

/** Left offset of the title of a row at `depth`: drag handle plus one guide per level. */
const treeIndent = (depth) => depth * 26 + 38;

function treeDragStart(event, entry) {
  dragKey.value = entryKey(entry);
  event.dataTransfer.effectAllowed = "move";
  event.dataTransfer.setData("text/plain", dragKey.value);
  // Drag the whole row rather than just the handle.
  const row = event.currentTarget.closest("tr");
  if (row) event.dataTransfer.setDragImage(row, 24, row.offsetHeight / 2);
}

function treeDragEnd() {
  dragKey.value = "";
  dropZone.value = null;
  clearTimeout(expandTimer);
  expandTimer = null;
}

function draggedEntry() {
  return (col.value.entries ?? []).find((entry) => entryKey(entry) === dragKey.value) ?? null;
}

/** The move request for dropping on `target`, or null when not allowed or a no-op. */
function placementFor(target, zone) {
  const dragged = draggedEntry();
  if (!dragged) return null;
  const entries = col.value.entries ?? [];
  const placement = dropPlacement(entries, dragged, target, zone);
  return placement && !isCurrentPlace(entries, dragged, placement) ? placement : null;
}

/**
 * Where the pointer would drop: the middle of a row nests into it, its top and
 * bottom edges are the gaps around it, where the horizontal position picks the
 * nesting level.
 */
function dropAt(event, rowIndex) {
  const rows = displayRows.value;
  const { entry, depth } = rows[rowIndex];
  const rect = event.currentTarget.getBoundingClientRect();
  const ratio = (event.clientY - rect.top) / rect.height;
  if (ratio >= 0.25 && ratio <= 0.75) return { rowKey: entryKey(entry), visual: "inside", depth: depth + 1, key: entryKey(entry), zone: "inside" };
  const visual = ratio < 0.25 ? "before" : "after";
  const cell = event.currentTarget.querySelector(".tree-title-cell");
  const pointerDepth = cell ? (event.clientX - cell.getBoundingClientRect().left - treeIndent(0)) / 26 : depth;
  const gap = gapTarget(rows, visual === "before" ? rowIndex - 1 : rowIndex, pointerDepth);
  if (!gap) return null;
  return { rowKey: entryKey(entry), visual, depth: gap.depth, key: entryKey(gap.target), zone: gap.zone };
}

function treeDragOver(event, rowIndex) {
  if (!dragKey.value) return;
  const drop = dropAt(event, rowIndex);
  const target = drop && (col.value.entries ?? []).find((entry) => entryKey(entry) === drop.key);
  const valid = !!target && placementFor(target, drop.zone) !== null;
  const next = valid ? drop : null;
  const current = dropZone.value;
  if (next?.rowKey !== current?.rowKey || next?.visual !== current?.visual || next?.key !== current?.key || next?.depth !== current?.depth) {
    dropZone.value = next;
    clearTimeout(expandTimer);
    expandTimer = null;
    if (next?.zone === "inside" && collapsed.value.has(next.key)) {
      expandTimer = setTimeout(() => {
        if (dropZone.value?.key === next.key && dropZone.value.zone === "inside") toggleCollapsed(target);
      }, 700);
    }
  }
  event.dataTransfer.dropEffect = valid ? "move" : "none";
  if (valid) event.preventDefault();
}

function treeDragLeave(event, target) {
  if (!event.currentTarget.contains(event.relatedTarget) && dropZone.value?.rowKey === entryKey(target)) dropZone.value = null;
}

async function treeDrop() {
  const dragged = draggedEntry();
  const zone = dropZone.value;
  const target = zone && (col.value.entries ?? []).find((entry) => entryKey(entry) === zone.key);
  const placement = target ? placementFor(target, zone.zone) : null;
  treeDragEnd();
  if (!dragged || !placement) return;
  if (placement.parent) collapsed.value = new Set([...collapsed.value].filter((key) => key !== placement.parent));
  // Show the new order right away; the reload below confirms it.
  col.value.entries = applyMove(col.value.entries ?? [], entryKey(dragged), placement);
  try {
    await api.content.move(collection.value, entryKey(dragged), placement);
  } catch (err) {
    toast.error(err.message ?? t("contentList.moveFailed"));
  }
  await load();
}

const savingCell = ref(null);

function cellSaveKey(entryId, column) {
  return `${entryId}:${column.fieldKey ?? column.key}`;
}

async function handleInlineSave({ entryId, fieldKey, value }) {
  const cellKey = `${entryId}:${fieldKey}`;
  if (savingCell.value === cellKey) return;

  savingCell.value = cellKey;

  try {
    const entry = col.value.entries.find((entry) => entry.id === entryId);
    const res = await api.content.update(collection.value, entry?.uid || entryId, {
      [fieldKey]: value, locale: entry?.locale, revision: entry?.revision,
    });
    const index = col.value.entries.findIndex((entry) => entry.id === entryId);
    if (index !== -1 && res?.data) {
      col.value.entries.splice(index, 1, res.data);
    }
  } catch (err) {
    toast.error(err.message ?? t("contentList.inlineSaveFailed"));
  } finally {
    savingCell.value = null;
  }
}

const store = useContentStore();
const col = computed(
  () =>
    store.cache[collection.value] ?? {
      entries: [],
      meta: { total: 0, limit: 30, offset: 0 },
      loading: true,
    },
);

const filters = ref({ q: "" });
const trashCount = ref(0);
const pageSizeOptions = [20, 30, 40, 50, 100, 200, 300];
const pageSize = ref(30);
const pageOffset = ref(0);
const hasActiveFilters = computed(() => filters.value.q.trim() !== "");
const contentTypeLocales = computed(
  () => contentTypeSchema.value?.locales ?? [],
);
const defaultLocale = computed(
  () =>
    contentTypeSchema.value?.default_locale ??
    contentTypeLocales.value[0] ??
    "",
);
const orderedContentTypeLocales = computed(() =>
  orderLocales(contentTypeLocales.value, defaultLocale.value),
);
const apiEndpointUrl = computed(() =>
  contentCollectionEndpoint({
    collection: collection.value,
    limit: pageSize.value,
    offset: pageOffset.value,
    sortKey: apiSortKey(sortKey.value),
    sortDir: sortDir.value,
    q: filters.value.q.trim(),
    locale: defaultLocale.value,
  }),
);
const totalEntries = computed(() => col.value.meta.total ?? 0);
const currentOffset = computed(() => col.value.meta.offset ?? pageOffset.value);
const currentLimit = computed(() => col.value.meta.limit ?? pageSize.value);
const pageStart = computed(() =>
  totalEntries.value === 0 ? 0 : currentOffset.value + 1,
);
const pageEnd = computed(() =>
  Math.min(currentOffset.value + currentLimit.value, totalEntries.value),
);
const canPageBackward = computed(() => currentOffset.value > 0);
const canPageForward = computed(
  () => currentOffset.value + currentLimit.value < totalEntries.value,
);

function formatStatus(status) {
  if (!status) return t("contentList.unknownStatus");
  const key = `status.${status}`;
  const label = t(key);
  return label === key ? status : label;
}

function entryLabel(count) {
  return t(Number(count) === 1 ? "contentList.entry" : "contentList.entries");
}

function entryLocaleBadges(entry) {
  if (!entry?.publication || contentTypeLocales.value.length === 0) return [];
  return orderedContentTypeLocales.value.filter((loc) =>
    Object.prototype.hasOwnProperty.call(entry.publication, loc),
  );
}

async function loadTrashCount() {
  try {
    const res = await api.trash.list(collection.value);
    trashCount.value = (res.data ?? []).length;
  } catch {
    trashCount.value = 0;
  }
}

async function load() {
  // A tree needs every page: children may sit anywhere in a paged list.
  const params = treeMode.value
    ? { limit: 10000, offset: 0 }
    : {
      limit: pageSize.value,
      offset: pageOffset.value,
      sort: apiSortKey(sortKey.value),
      order: sortDir.value,
    };
  const query = filters.value.q.trim();
  if (query) params.q = query;
  // Pass default_locale so the backend resolves translated titles (avoids showing
  // stale root-level title when the content type's default locale was changed).
  if (defaultLocale.value) params.locale = defaultLocale.value;
  await store.fetchList(collection.value, params);

  const total = col.value.meta.total ?? 0;
  if (!treeMode.value && total > 0 && pageOffset.value >= total) {
    pageOffset.value =
      Math.floor((total - 1) / pageSize.value) * pageSize.value;
    await store.fetchList(collection.value, {
      ...params,
      offset: pageOffset.value,
    });
  }
}

function applyFilters() {
  clearTimeout(searchTimer);
  searchedQuery = filters.value.q.trim();
  pageOffset.value = 0;
  load();
}

// Search as you type; Enter still searches right away.
let searchTimer;
let searchedQuery = "";
watch(() => filters.value.q, (q) => {
  clearTimeout(searchTimer);
  if (q.trim() === searchedQuery) return;
  searchTimer = setTimeout(applyFilters, 300);
});
onBeforeUnmount(() => clearTimeout(searchTimer));

function clearFilters() {
  filters.value = { q: "" };
  applyFilters();
}

function changePageSize() {
  pageOffset.value = 0;
  load();
}

function changePage(direction) {
  pageOffset.value = Math.max(
    0,
    currentOffset.value + direction * currentLimit.value,
  );
  load();
}

function formatDate(iso) {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function cellClass(column) {
  const base = "align-middle";
  if (column.key === "title") return `${base} font-medium text-slate-900`;
  if (["created", "updated", "scheduled"].includes(column.key))
    return `${base} text-slate-500 whitespace-nowrap`;
  if (
    column.kind === "field" &&
    (column.type === "number" || column.type === "range")
  )
    return `${base} text-slate-700 tabular-nums`;
  return `${base} text-slate-700`;
}

function isImage(value) {
  return isImageFile(value);
}

function mediaThumbUrl(value) {
  return buildMediaThumbUrl(getActiveWorkspace(), value);
}

function formatBoolean(value) {
  return boolValue(value) ? t("contentList.true") : t("contentList.false");
}

function formatFieldDate(value, type) {
  if (!value) return "—";
  if (type === "date" && /^\d{4}-\d{2}-\d{2}$/.test(String(value))) {
    return new Date(`${value}T00:00:00`).toLocaleDateString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  }

  return formatDate(value);
}

function fieldTextValue(value, trimmed = true) {
  return formatFieldTextValue(value, formatBoolean, trimmed);
}

async function loadUsers() {
  // Names are a nicety; users without users.read see account ids instead.
  if (!auth.can("users.read")) return;
  try {
    const res = await api.users.list();
    users.value = res.data;
  } catch {
    users.value = [];
  }
}

function onColPickerClickOutside(e) {
  if (colPickerRef.value && !colPickerRef.value.contains(e.target)) {
    colPickerOpen.value = false;
  }
}

onMounted(async () => {
  visibleColumns.value = loadColumnPrefs(collection.value);
  loadWebsiteStatus();
  // Load schema first so default_locale is available when load() sends ?locale=
  await loadContentType();
  load();
  loadTrashCount();
  loadUsers();
  document.addEventListener("mousedown", onColPickerClickOutside);
});
onBeforeUnmount(() => {
  document.removeEventListener("mousedown", onColPickerClickOutside);
  apiEndpointStore.clearEndpoint(apiEndpointOwner);
});

watch(
  [apiEndpointUrl, () => contentTypeSchema.value?.api_enabled],
  ([url, enabled]) => {
    if (!enabled) {
      apiEndpointStore.clearEndpoint(apiEndpointOwner);
      return;
    }
    apiEndpointStore.setEndpoint(
      {
        label: "Collection",
        url,
      },
      apiEndpointOwner,
    );
  },
  { immediate: true },
);

const bulkDuplicating = ref(false);

async function duplicateSelected() {
  if (bulkDuplicating.value) return;
  const ids = selectAllPages.value ? null : [...selectedIds.value];
  if (ids !== null && ids.length === 0) return;
  bulkDuplicating.value = true;
  try {
    let entryIds = ids;
    if (entryIds === null) {
      const query = filters.value.q.trim();
      const params = { limit: 10000, offset: 0 };
      if (query) params.q = query;
      const res = await api.content.list(collection.value, params);
      entryIds = (res.data ?? []).map((e) => e.id);
    }
    let count = 0;
    for (const id of entryIds) {
      try {
        await api.content.duplicate(collection.value, id);
        count++;
      } catch {
        /* skip */
      }
    }
    toast.success(
      t("contentList.duplicated", { count, itemLabel: entryLabel(count) }),
    );
    deselectAll();
    await load();
  } catch (err) {
    toast.error(err.message ?? t("contentList.duplicateFailed"));
  } finally {
    bulkDuplicating.value = false;
  }
}
watch(
  () => route.params.collection,
  async () => {
    loadWebsiteStatus();
    visibleColumns.value = loadColumnPrefs(collection.value);
    pageOffset.value = 0;
    resetSort();
    deselectAll();
    collapsed.value = new Set();
    await loadContentType();
    load();
    loadTrashCount();
  },
);
</script>
