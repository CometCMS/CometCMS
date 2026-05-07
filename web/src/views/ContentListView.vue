<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-slate-900 capitalize">
        {{ collection }}
      </h1>
      <div class="flex items-center gap-2">
        <router-link
          v-if="trashCount > 0"
          :to="`/trash/${collection}`"
          class="btn-secondary"
        >
          {{ t("contentList.trash") }}
          <span
            class="ml-1 inline-flex items-center justify-center rounded-full bg-slate-300 text-slate-700 text-xs w-5 h-5"
            >{{ trashCount }}</span
          >
        </router-link>
        <router-link :to="`/content/${collection}/new`" class="btn-primary">
          {{ t("contentList.newEntry") }}
        </router-link>
      </div>
    </div>

    <!-- Filters -->
    <div class="card p-4 mb-4">
      <div class="flex items-end gap-3 flex-wrap">
        <div class="min-w-[240px] flex-1">
          <label class="form-label text-xs">{{
            t("contentList.search")
          }}</label>
          <div class="relative">
            <Icon
              icon="mdi:magnify"
              class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
            />
            <input
              v-model="filters.q"
              type="text"
              :placeholder="t('contentList.searchPlaceholder')"
              class="form-input w-full rounded-lg border-slate-300 pl-9 text-sm"
              @keydown.enter="applyFilters"
            />
          </div>
        </div>

        <div class="w-36">
          <label class="form-label text-xs">{{
            t("contentList.rowsPerPage")
          }}</label>
          <select
            v-model.number="pageSize"
            class="form-select w-full rounded-lg border-slate-300 text-sm"
            @change="changePageSize"
          >
            <option
              v-for="option in pageSizeOptions"
              :key="option"
              :value="option"
            >
              {{ option }}
            </option>
          </select>
        </div>
        <button type="button" @click="applyFilters" class="btn-secondary">
          <Icon icon="mdi:filter-outline" class="h-4 w-4" />
          {{ t("contentList.apply") }}
        </button>
        <button
          v-if="hasActiveFilters"
          type="button"
          @click="clearFilters"
          class="btn-secondary"
        >
          <Icon icon="mdi:close" class="h-4 w-4" />
          {{ t("contentList.clear") }}
        </button>

        <!-- Columns picker -->
        <div class="relative" ref="colPickerRef">
          <button
            type="button"
            @click="colPickerOpen = !colPickerOpen"
            class="btn-secondary flex items-center gap-1.5"
          >
            <Icon icon="mdi:table-column" class="h-4 w-4" />
            {{ t("contentList.columns") }}
            <Icon
              icon="mdi:chevron-down"
              class="h-3.5 w-3.5 transition-transform duration-150"
              :class="colPickerOpen ? 'rotate-180' : ''"
            />
          </button>
          <div
            v-if="colPickerOpen"
            class="absolute right-0 top-full mt-1 z-50 max-h-80 w-64 overflow-y-auto bg-white border border-slate-200 rounded-lg shadow-lg py-1"
          >
            <label
              v-for="column in tableColumns"
              :key="column.key"
              class="flex items-center gap-2.5 px-3 py-2 hover:bg-slate-50 cursor-pointer text-sm text-slate-700 select-none"
              :class="
                visibleColumns[column.key] && displayedColumns.length === 1
                  ? 'opacity-40 cursor-not-allowed'
                  : ''
              "
            >
              <input
                type="checkbox"
                class="rounded border-slate-300 text-theme-600 cursor-pointer"
                :checked="visibleColumns[column.key]"
                :disabled="
                  visibleColumns[column.key] && displayedColumns.length === 1
                "
                @change="toggleColumn(column.key)"
              />
              {{ column.label }}
            </label>
            <template v-if="postTypeFieldColumns.length > 0">
              <div class="border-t border-slate-100 my-1"></div>
              <label
                v-for="column in postTypeFieldColumns"
                :key="column.key"
                class="flex items-center gap-2.5 px-3 py-2 hover:bg-slate-50 cursor-pointer text-sm text-slate-700 select-none"
                :class="
                  visibleColumns[column.key] && displayedColumns.length === 1
                    ? 'opacity-40 cursor-not-allowed'
                    : ''
                "
              >
                <input
                  type="checkbox"
                  class="rounded border-slate-300 text-theme-600 cursor-pointer"
                  :checked="visibleColumns[column.key]"
                  :disabled="
                    visibleColumns[column.key] && displayedColumns.length === 1
                  "
                  @change="toggleColumn(column.key)"
                />
                <span class="min-w-0 flex-1 truncate">{{ column.label }}</span>
                <span
                  class="shrink-0 text-[10px] uppercase tracking-wide text-slate-400"
                  >{{ column.type }}</span
                >
              </label>
            </template>
            <div class="border-t border-slate-100 mt-1 pt-1 px-3 pb-1">
              <button
                type="button"
                class="text-xs text-theme-600 hover:text-theme-700 font-medium"
                @click="resetColumns"
              >
                {{ t("contentList.resetColumns") }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <LoadingSpinner v-if="col.loading" />

    <template v-else>
      <!-- Selection toolbar -->
      <Transition v-bind="ht">
        <div
          v-if="selectedIds.size > 0"
          class="card p-3 mb-3 border-theme-200 bg-theme-50"
        >
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
              <button
                v-if="!selectAllPages && totalEntries > sortedEntries.length"
                type="button"
                class="text-xs text-theme-600 hover:text-theme-700 font-medium underline-offset-2 underline"
                @click="triggerSelectAllPages"
              >
                {{ t("contentList.selectAll", { count: totalEntries }) }}
              </button>
              <button
                type="button"
                class="text-xs text-slate-500 hover:text-slate-700 font-medium"
                @click="deselectAll"
              >
                {{ t("contentList.clear") }}
              </button>
            </div>
            <div class="w-px h-5 bg-theme-200 shrink-0 hidden sm:block"></div>
            <BulkEditBar
              :content-type="contentTypeSchema"
              :users="users"
              :selected-count="selectAllPages ? totalEntries : selectedIds.size"
              :applying="bulkApplying"
              class="flex-1 min-w-0"
              @apply="applyBulkEdit"
            />
            <div class="w-px h-5 bg-theme-200 shrink-0 hidden sm:block"></div>
            <button
              type="button"
              :disabled="bulkDeleting || bulkDuplicating"
              :title="t('contentList.duplicateSelected')"
              class="btn-secondary py-1.5 px-3 text-sm disabled:opacity-40 whitespace-nowrap shrink-0 inline-flex items-center gap-1.5"
              @click="duplicateSelected"
            >
              <Icon icon="mdi:content-copy" class="w-4 h-4" />
            </button>
            <div class="w-px h-5 bg-theme-200 shrink-0 hidden sm:block"></div>
            <button
              type="button"
              :disabled="bulkDeleting"
              :title="t('contentList.moveToTrash')"
              class="btn-secondary py-1.5 px-3 text-sm text-red-600 hover:text-red-700 hover:bg-red-50 border-red-200 disabled:opacity-40 whitespace-nowrap shrink-0 inline-flex items-center gap-1.5"
              @click="confirmBulkDelete"
            >
              <Icon icon="mdi:trash-can-outline" class="w-4 h-4" />
            </button>
          </div>
          <p
            v-if="selectAllPages"
            class="mt-1.5 text-xs text-theme-600 font-medium"
          >
            {{ t("contentList.allPagesTrash", { count: totalEntries }) }}
          </p>
        </div>
      </Transition>

      <ConfirmModal
        v-model="showBulkDeleteModal"
        :title="t('contentList.moveToTrash')"
        :message="
          t('contentList.moveMessage', {
            count: selectAllPages ? totalEntries : selectedIds.size,
            itemLabel: entryLabel(
              selectAllPages ? totalEntries : selectedIds.size,
            ),
          })
        "
        :confirm-label="t('contentList.moveToTrash')"
        variant="danger"
        :loading="bulkDeleting"
        @confirm="applyBulkDelete"
      />

      <div class="card overflow-hidden mb-3">
        <div class="overflow-x-auto">
          <table class="min-w-full">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200 group/thead">
                <th class="w-10 px-3 py-3">
                  <input
                    type="checkbox"
                    class="rounded border-slate-300 text-theme-600 cursor-pointer transition-opacity"
                    :class="
                      selectedIds.size > 0
                        ? 'opacity-100'
                        : 'opacity-0 group-hover/thead:opacity-100'
                    "
                    :checked="allPageSelected"
                    :indeterminate.prop="someSelected && !allPageSelected"
                    @change="toggleAllPage"
                  />
                </th>
                <th
                  v-for="column in displayedColumns"
                  :key="column.key"
                  :class="[
                    'text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3 select-none',
                    column.sortKey
                      ? 'cursor-pointer hover:text-slate-700'
                      : 'cursor-default',
                  ]"
                  @click="column.sortKey ? setSort(column.sortKey) : undefined"
                >
                  <span class="inline-flex items-center gap-1">
                    {{ column.label }}
                    <template v-if="column.sortKey">
                      <Icon
                        v-if="sortKey === column.sortKey"
                        :icon="
                          sortDir === 'asc' ? 'mdi:arrow-up' : 'mdi:arrow-down'
                        "
                        class="w-3.5 h-3.5"
                      />
                      <Icon
                        v-else
                        icon="mdi:unfold-more-horizontal"
                        class="w-3.5 h-3.5 opacity-30"
                      />
                    </template>
                  </span>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="col.entries.length === 0">
                <td
                  :colspan="displayedColumns.length + 1"
                  class="px-4 py-8 text-center text-slate-500 text-sm"
                >
                  {{ t("contentList.noEntries") }}
                  <router-link
                    :to="`/content/${collection}/new`"
                    class="text-theme-600 hover:underline ml-1"
                    >{{ t("contentList.createOne") }}</router-link
                  >.
                </td>
              </tr>
              <tr
                v-for="entry in sortedEntries"
                :key="entry.id"
                class="cursor-pointer transition-colors group"
                :class="
                  selectedIds.has(entry.id)
                    ? 'bg-theme-50 hover:bg-theme-100'
                    : 'hover:bg-slate-50'
                "
                @click="router.push(`/content/${collection}/${entry.id}`)"
              >
                <td class="w-10 px-3 py-3" @click.stop>
                  <input
                    type="checkbox"
                    class="rounded border-slate-300 text-theme-600 cursor-pointer transition-opacity"
                    :class="
                      selectedIds.size > 0
                        ? 'opacity-100'
                        : 'opacity-0 group-hover:opacity-100'
                    "
                    :checked="selectedIds.has(entry.id)"
                    @change="toggleSelection(entry.id)"
                  />
                </td>
                <td
                  v-for="column in displayedColumns"
                  :key="column.key"
                  :class="cellClass(column)"
                >
                  <ContentListCell
                    :column="column"
                    :entry="entry"
                    :users="users"
                    :saving="savingCell === cellSaveKey(entry.id, column)"
                    @save="handleInlineSave"
                  >
                    <template v-if="column.kind === 'core'">
                      <template v-if="column.key === 'title'">
                        {{ entry.title ?? entry.id }}
                      </template>
                      <template v-else-if="column.key === 'status'">
                        <span :class="statusPillClass(effectiveStatus(entry))">
                          {{ formatStatus(effectiveStatus(entry)) }}
                        </span>
                      </template>
                      <template v-else-if="column.key === 'locales'">
                        <div
                          v-if="entryLocaleBadges(entry).length > 0"
                          class="flex items-center gap-1 flex-wrap"
                        >
                          <span
                            v-for="loc in entryLocaleBadges(entry)"
                            :key="loc"
                            class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-mono font-medium bg-slate-100 text-slate-600 ring-1 ring-slate-200 ring-inset"
                          >
                            {{ loc
                            }}<span
                              v-if="loc === contentTypeSchema?.default_locale"
                              class="ml-1 text-[10px] uppercase text-slate-400"
                            >
                              <Icon icon="mdi:home" class="w-3 h-3" />
                            </span>
                          </span>
                        </div>
                        <span v-else class="text-slate-400">—</span>
                      </template>
                      <template v-else-if="column.key === 'author'">
                        <template v-if="userMap[entry.author_id]">
                          <div class="flex items-center gap-2">
                            <div
                              class="w-6 h-6 rounded-full overflow-hidden bg-theme-600 flex items-center justify-center text-white text-xs font-semibold select-none shrink-0"
                            >
                              <img
                                v-if="userMap[entry.author_id].has_avatar"
                                :src="`/admin/api/users/${entry.author_id}/avatar`"
                                class="w-full h-full object-cover"
                                :alt="userMap[entry.author_id].username"
                              />
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
                        <div
                          v-if="
                            mediaValuesFor(entry, column.fieldKey).length > 0
                          "
                          class="flex items-center gap-1.5"
                        >
                          <div
                            v-for="file in mediaValuesFor(
                              entry,
                              column.fieldKey,
                            ).slice(0, 3)"
                            :key="file"
                            class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-slate-100"
                            :title="file"
                          >
                            <img
                              v-if="isImage(file)"
                              :src="mediaThumbUrl(file)"
                              :alt="file"
                              class="h-full w-full object-cover"
                            />
                            <Icon
                              v-else
                              v-bind="getFileIcon(file)"
                              class="h-5 w-5"
                            />
                          </div>
                          <span
                            v-if="
                              mediaValuesFor(entry, column.fieldKey).length > 3
                            "
                            class="text-xs font-semibold text-slate-500"
                          >
                            +{{
                              mediaValuesFor(entry, column.fieldKey).length - 3
                            }}
                          </span>
                        </div>
                        <span v-else class="text-slate-400">—</span>
                      </template>
                      <template v-else-if="column.type === 'boolean'">
                        <span
                          v-if="
                            fieldValue(entry, column.fieldKey) === null ||
                            fieldValue(entry, column.fieldKey) === undefined ||
                            fieldValue(entry, column.fieldKey) === ''
                          "
                          class="text-slate-400"
                          >—</span
                        >
                        <span
                          v-else
                          :class="
                            booleanPillClass(fieldValue(entry, column.fieldKey))
                          "
                        >
                          {{
                            formatBoolean(fieldValue(entry, column.fieldKey))
                          }}
                        </span>
                      </template>
                      <template
                        v-else-if="
                          column.type === 'number' || column.type === 'range'
                        "
                      >
                        <span
                          :title="
                            formatNumberField(
                              fieldValue(entry, column.fieldKey),
                              column.config,
                            )
                          "
                        >
                          {{
                            formatNumberField(
                              fieldValue(entry, column.fieldKey),
                              column.config,
                            )
                          }}
                        </span>
                      </template>
                      <template
                        v-else-if="
                          column.type === 'date' || column.type === 'datetime'
                        "
                      >
                        {{
                          formatFieldDate(
                            fieldValue(entry, column.fieldKey),
                            column.type,
                          )
                        }}
                      </template>
                      <template v-else-if="column.type === 'select'">
                        <span
                          class="block max-w-72 truncate"
                          :title="
                            formatSelectField(
                              fieldValue(entry, column.fieldKey),
                              column.config,
                              false,
                            )
                          "
                        >
                          {{
                            formatSelectField(
                              fieldValue(entry, column.fieldKey),
                              column.config,
                            )
                          }}
                        </span>
                      </template>
                      <template v-else>
                        <span
                          class="block max-w-72 truncate"
                          :title="
                            fieldTextValue(
                              fieldValue(entry, column.fieldKey),
                              false,
                            )
                          "
                        >
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
      </div>

      <!-- Pagination -->
      <div class="flex items-center justify-between text-sm text-slate-500">
        <span>{{
          t("contentList.showing", {
            start: pageStart,
            end: pageEnd,
            total: totalEntries,
          })
        }}</span>
        <div class="flex gap-2">
          <button
            :disabled="!canPageBackward"
            @click="changePage(-1)"
            class="btn-secondary py-1 px-3 disabled:opacity-40"
          >
            {{ t("contentList.prev") }}
          </button>
          <button
            :disabled="!canPageForward"
            @click="changePage(1)"
            class="btn-secondary py-1 px-3 disabled:opacity-40"
          >
            {{ t("contentList.next") }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import LoadingSpinner from "../components/LoadingSpinner.vue";
import BulkEditBar from "../components/BulkEditBar.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import ContentListCell from "../components/ContentListCell.vue";
import { Icon } from "@iconify/vue";
import { ref, computed, onMounted, onBeforeUnmount, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useHeightTransition } from "../composables/useHeightTransition.js";

const ht = useHeightTransition();
import { useContentStore } from "../stores/content.js";
import { useToastStore } from "../stores/toast.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import { api } from "../api/index.js";
import { contentCollectionEndpoint } from "../composables/apiEndpoint.js";
import { useI18n } from "../i18n/index.js";

const toast = useToastStore();
const apiEndpointStore = useApiEndpointStore();
const { t } = useI18n();
const apiEndpointOwner = "content-list";
const users = ref([]);
const userMap = computed(() =>
  Object.fromEntries(users.value.map((u) => [u.id, u])),
);

// ── Content type schema (for BulkEditBar field list) ─────────────────────────
const contentTypeSchema = ref(null);

async function loadContentType() {
  contentTypeSchema.value = null;

  try {
    const res = await api.contentTypes.get(collection.value);
    contentTypeSchema.value = res.data;

    if (res.data?.singleton) {
      return;
    }

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

const pageEntryIds = computed(() => sortedEntries.value.map((e) => e.id));
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

const savingCell = ref(null);

function cellSaveKey(entryId, column) {
  return `${entryId}:${column.fieldKey ?? column.key}`;
}

async function handleInlineSave({ entryId, fieldKey, value }) {
  const cellKey = `${entryId}:${fieldKey}`;
  if (savingCell.value === cellKey) return;

  savingCell.value = cellKey;

  try {
    const res = await api.content.update(collection.value, entryId, {
      [fieldKey]: value,
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
const pageSizeOptions = [10, 20, 30, 40, 50, 100];
const pageSize = ref(30);
const pageOffset = ref(0);
const hasActiveFilters = computed(() => filters.value.q.trim() !== "");
const isSingleton = computed(() => !!contentTypeSchema.value?.singleton);
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

const statusPillClasses = {
  draft: "bg-slate-100 text-slate-700 ring-slate-200",
  published: "bg-emerald-100 text-emerald-700 ring-emerald-200",
  scheduled: "bg-sky-100 text-sky-700 ring-sky-200",
  protected: "bg-violet-100 text-violet-700 ring-violet-200",
  archived: "bg-amber-100 text-amber-700 ring-amber-200",
};

function effectiveStatus(entry) {
  if (
    entry.status === "published" &&
    entry.published_at &&
    new Date(entry.published_at) > new Date()
  ) {
    return "scheduled";
  }
  return entry.status;
}

function statusPillClass(status) {
  return [
    "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset",
    statusPillClasses[status] ?? "bg-slate-100 text-slate-600 ring-slate-200",
  ];
}

function formatStatus(status) {
  return status ?? t("contentList.unknownStatus");
}

function entryLabel(count) {
  return t(Number(count) === 1 ? "contentList.entry" : "contentList.entries");
}

function entryLocaleBadges(entry) {
  if (!entry?.translations || contentTypeLocales.value.length === 0) return [];
  return orderedContentTypeLocales.value.filter((loc) =>
    Object.prototype.hasOwnProperty.call(entry.translations, loc),
  );
}

function orderLocales(locales, defaultLocale) {
  const unique = [...new Set(locales)];
  if (!defaultLocale || !unique.includes(defaultLocale)) return unique;
  return [defaultLocale, ...unique.filter((loc) => loc !== defaultLocale)];
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
  if (isSingleton.value) return;

  const params = {
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
  if (total > 0 && pageOffset.value >= total) {
    pageOffset.value =
      Math.floor((total - 1) / pageSize.value) * pageSize.value;
    await store.fetchList(collection.value, {
      ...params,
      offset: pageOffset.value,
    });
  }
}

function applyFilters() {
  pageOffset.value = 0;
  load();
}

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

function fieldColumnKey(key) {
  return `field:${key}`;
}

function isFieldSortKey(key) {
  return typeof key === "string" && key.startsWith("field:");
}

function fieldKeyFromColumnKey(key) {
  return String(key).slice("field:".length);
}

function apiSortKey(key) {
  return isFieldSortKey(key) ? fieldKeyFromColumnKey(key) : key;
}

function fieldLabel(key, config) {
  if (config?.label) return String(config.label);
  return humanizeKey(key);
}

function humanizeKey(key) {
  return String(key)
    .replace(/[_-]+/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function fieldValue(entry, key) {
  if (entry && Object.prototype.hasOwnProperty.call(entry, key))
    return entry[key];
  if (entry?.data && Object.prototype.hasOwnProperty.call(entry.data, key))
    return entry.data[key];
  return null;
}

function normalizeFieldSortValue(value) {
  if (value === null || value === undefined) return "";
  if (typeof value === "number") return value;
  if (typeof value === "boolean") return value ? 1 : 0;
  if (Array.isArray(value))
    return value.map((item) => normalizeFieldSortValue(item)).join(" ");
  if (typeof value === "object") return JSON.stringify(value);
  const number = Number(value);
  return Number.isFinite(number) && String(value).trim() !== ""
    ? number
    : String(value).toLowerCase();
}

function cellClass(column) {
  const base = "px-4 py-3 text-sm align-middle";
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

const imageExts = new Set(["jpg", "jpeg", "png", "gif", "webp", "svg", "avif"]);

function isImage(value) {
  const ext = String(value).split(".").pop()?.toLowerCase() ?? "";
  return imageExts.has(ext);
}

function mediaThumbUrl(value) {
  return `/media-thumbs/${encodeURIComponent(String(value))}`;
}

function mediaValuesFor(entry, key) {
  const value = fieldValue(entry, key);
  if (Array.isArray(value)) {
    return value.map((item) => String(item ?? "").trim()).filter(Boolean);
  }

  const single = String(value ?? "").trim();
  return single ? [single] : [];
}

function getFileIcon(name) {
  const ext = String(name).split(".").pop()?.toLowerCase() ?? "";
  if (ext === "pdf") return { icon: "mdi:file-pdf-box", class: "text-red-500" };
  if (["doc", "docx", "odt"].includes(ext))
    return { icon: "mdi:file-word-box", class: "text-blue-600" };
  if (["xls", "xlsx", "ods", "csv"].includes(ext))
    return { icon: "mdi:file-excel-box", class: "text-green-600" };
  if (["ppt", "pptx", "odp"].includes(ext))
    return { icon: "mdi:file-powerpoint-box", class: "text-orange-500" };
  if (["zip", "rar", "7z", "tar", "gz", "bz2"].includes(ext))
    return { icon: "mdi:zip-box", class: "text-yellow-600" };
  if (["mp3", "wav", "ogg", "m4a", "aac", "flac"].includes(ext))
    return { icon: "mdi:file-music-outline", class: "text-purple-500" };
  if (["txt", "md", "rtf"].includes(ext))
    return { icon: "mdi:file-document-outline", class: "text-slate-500" };
  return { icon: "mdi:file-outline", class: "text-slate-400" };
}

function booleanPillClass(value) {
  return [
    "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset",
    boolValue(value)
      ? "bg-emerald-50 text-emerald-700 ring-emerald-200"
      : "bg-slate-100 text-slate-600 ring-slate-200",
  ];
}

function formatBoolean(value) {
  return boolValue(value) ? t("contentList.true") : t("contentList.false");
}

function boolValue(value) {
  if (typeof value === "string")
    return ["true", "1", "yes", "on"].includes(value.toLowerCase());
  return !!value;
}

function formatNumberField(value, config = null) {
  if (value === null || value === undefined || value === "") return "—";
  const number = Number(value);
  if (!Number.isFinite(number)) return String(value);

  const decimals = config?.display_decimals;
  if (decimals === "full") return String(value);

  const fixedDigits =
    decimals === null || decimals === undefined || decimals === ""
      ? 0
      : Number(decimals);
  return Number.isInteger(fixedDigits) && fixedDigits >= 0 && fixedDigits <= 3
    ? new Intl.NumberFormat(undefined, {
        minimumFractionDigits: fixedDigits,
        maximumFractionDigits: fixedDigits,
      }).format(number)
    : new Intl.NumberFormat().format(number);
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

function selectOptionMap(config = {}) {
  const options = config?.options;
  if (options && typeof options === "object" && !Array.isArray(options)) {
    return Object.fromEntries(
      Object.entries(options).map(([key, label]) => [String(key), String(label)]),
    );
  }

  return Object.fromEntries(
    (options ?? []).map((option) => [String(option), String(option)]),
  );
}

function formatSelectField(value, config = {}, trimmed = true) {
  if (value === null || value === undefined || value === "") return "—";

  const labels = selectOptionMap(config);
  const labelFor = (item) => {
    const key = String(item ?? "").trim();
    return key ? labels[key] ?? key : "";
  };

  const text = Array.isArray(value)
    ? value.map(labelFor).filter(Boolean).join(", ")
    : labelFor(value);

  if (!text) return "—";
  return trimmed ? trimText(text) : text;
}

function fieldTextValue(value, trimmed = true) {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "boolean") return formatBoolean(value);
  if (Array.isArray(value)) {
    const text = value
      .map((item) => fieldTextValue(item, false))
      .filter((item) => item !== "—")
      .join(", ");
    return trimmed ? trimText(text) : text;
  }
  if (typeof value === "object") {
    return Array.isArray(value) ? "[…]" : "{…}";
  }

  const text = String(value).replace(/\s+/g, " ").trim();
  return trimmed ? trimText(text) : text;
}

function trimText(value, max = 80) {
  const text = String(value ?? "");
  return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

async function loadUsers() {
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
  apiEndpointUrl,
  (url) => {
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
    visibleColumns.value = loadColumnPrefs(collection.value);
    pageOffset.value = 0;
    resetSort();
    deselectAll();
    await loadContentType();
    load();
    loadTrashCount();
  },
);
</script>
