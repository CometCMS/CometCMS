<template>
  <section class="card col-span-1 self-start overflow-visible p-4">
    <div class="mb-2 flex items-center justify-between gap-3">
      <h2 class="text-base font-bold text-slate-950">
        {{ t("media.categories") }}
      </h2>
      <button
        v-if="canManage"
        type="button"
        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-theme-300 hover:text-theme-700"
        :title="t('media.addCategory')"
        :aria-label="t('media.addCategory')"
        @click="() => startCategory()"
      >
        <Icon icon="mdi:plus" class="h-5 w-5" />
      </button>
    </div>



    <label class="relative mb-2 block">
      <span class="sr-only">{{ t("media.searchCategories") }}</span>
      <Icon
        icon="mdi:magnify"
        class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
      />
      <input
        v-model="categorySearch"
        type="search"
        :placeholder="t('media.searchCategoriesPlaceholder')"
        class="form-input h-9 w-full rounded-lg border-slate-200 bg-white pl-9 text-sm placeholder:text-slate-400 focus:border-theme-400 focus:ring-theme-400"
      />
    </label>

    <div class="space-y-0.5">
      <!-- All categories -->
      <button
        type="button"
        class="group flex h-10 w-full items-center gap-3 rounded-lg px-3 text-left transition-colors"
        :class="categoryButtonClass(null)"
        @click="selectCategory(null)"
        @dragover.prevent="onCategoryDragOver(null)"
        @dragleave="onCategoryDragLeave(null)"
        @drop.prevent="onCategoryDrop(null)"
      >
        <Icon icon="mdi:image-multiple-outline" class="h-5 w-5 shrink-0" />
        <span class="min-w-0 flex-1 truncate text-sm font-semibold">
          {{ t("media.allCategories") }}
        </span>
        <span class="text-xs font-medium text-theme-600">{{ allCategoryCount }}</span>
      </button>

      <!-- Uncategorized -->
      <button
        type="button"
        class="group flex h-10 w-full items-center gap-3 rounded-lg px-3 text-left transition-colors"
        :class="categoryButtonClass('')"
        @click="selectCategory('')"
        @dragover.prevent="onCategoryDragOver('')"
        @dragleave="onCategoryDragLeave('')"
        @drop.prevent="onCategoryDrop('')"
      >
        <Icon icon="mdi:folder-off-outline" class="h-5 w-5 shrink-0" />
        <span class="min-w-0 flex-1 truncate text-sm font-medium">
          {{ t("media.noCategory") }}
        </span>
        <span class="text-xs font-medium text-slate-500">{{ uncategorizedCount }}</span>
      </button>

      <div class="my-1 border-t border-slate-100" />

      <!-- Category tree items -->
      <div
        v-for="category in visibleCategoryTree"
        :key="category.path"
        class="group relative"
        data-category-menu-root
      >
        <!-- Rename form (inline edit) -->
        <form
          v-if="editingCategory === category.path"
          class="flex flex-col gap-2 rounded-lg border border-theme-200 bg-theme-50/60 px-3 py-3"
          @submit.prevent="renameCategory"
        >
          <input
            v-model="editingCategoryName"
            type="text"
            class="form-input w-full min-w-0 rounded-lg border-theme-200 text-sm"
            :placeholder="t('media.categoryName')"
            @keydown.esc="cancelRenameCategory"
          />
          <div class="grid w-full gap-2">
            <button
              type="submit"
              class="btn-primary w-full justify-center px-3 py-2 text-sm"
              :title="t('media.saveCategory')"
              :disabled="categoryWorking"
            >
              <Icon icon="mdi:check" class="h-4 w-4" />
              {{ t("media.save") }}
            </button>
            <button
              type="button"
              class="btn-secondary w-full justify-center px-3 py-2 text-sm"
              :title="t('media.cancel')"
              :disabled="categoryWorking"
              @click="cancelRenameCategory"
            >
              <Icon icon="mdi:close" class="h-4 w-4" />
              {{ t("media.cancel") }}
            </button>
          </div>
        </form>

        <!-- Category button -->
        <button
          v-else
          type="button"
          class="group flex h-10 w-full items-center gap-2 rounded-lg px-2 pr-9 text-left transition-colors"
          :class="categoryButtonClass(category.path)"
          :style="
            category.depth > 0
              ? { paddingLeft: `${category.depth * 0.9 + 0.5}rem` }
              : {}
          "
          @click="selectCategory(category.path)"
          @dragover.prevent="onCategoryDragOver(category.path)"
          @dragleave="onCategoryDragLeave(category.path)"
          @drop.prevent="onCategoryDrop(category.path)"
        >
          <!-- Fixed-width slot keeps folders aligned while allowing tree expansion. -->
          <span
            v-if="category.hasChildren"
            class="flex h-5 w-3 shrink-0 cursor-pointer items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-600"
            @click.stop="toggleCollapse(category.path)"
          >
            <Icon
              :icon="
                collapsedCategories.has(category.path)
                  ? 'mdi:chevron-right'
                  : 'mdi:chevron-down'
              "
              class="h-3.5 w-3.5"
            />
          </span>
          <span v-else class="h-5 w-3 shrink-0" />
          <Icon icon="mdi:folder-outline" class="h-5 w-5 shrink-0 text-theme-600" />
          <span class="min-w-0 flex-1 truncate text-sm font-medium">{{
            category.label
          }}</span>
          <span class="text-xs font-medium text-slate-500">{{ category.count }}</span>
        </button>

        <!-- Dots menu trigger -->
        <button
          v-if="canManage && editingCategory !== category.path"
          type="button"
          class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 transition hover:bg-white hover:text-slate-700 sm:opacity-0 sm:group-hover:opacity-100 focus:opacity-100"
          :title="t('media.categoryActions')"
          :aria-label="t('media.categoryActions')"
          @click.stop="toggleCategoryMenu(category.path)"
        >
          <Icon icon="mdi:dots-vertical" class="h-5 w-5" />
        </button>

        <!-- Dots menu dropdown -->
        <div
          v-if="categoryMenu === category.path"
          class="absolute right-2 top-12 z-20 w-44 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg"
        >
          <button
            type="button"
            class="flex w-full items-center gap-2 px-3 py-2 text-left text-slate-700 hover:bg-slate-50"
            @click.stop="startSubCategory(category.path)"
          >
            <Icon icon="mdi:subdirectory-arrow-right" class="h-4 w-4" />
            {{ t("media.addSubcategory") }}
          </button>
          <button
            type="button"
            class="flex w-full items-center gap-2 px-3 py-2 text-left text-slate-700 hover:bg-slate-50"
            @click.stop="startRenameCategory(category.path)"
          >
            <Icon icon="mdi:pencil-outline" class="h-4 w-4" />
            {{ t("media.rename") }}
          </button>
          <button
            type="button"
            class="flex w-full items-center gap-2 px-3 py-2 text-left text-red-600 hover:bg-red-50"
            @click.stop="confirmCategoryDelete(category.path)"
          >
            <Icon icon="mdi:trash-can-outline" class="h-4 w-4" />
            {{ t("media.delete") }}
          </button>
        </div>
      </div>

      <!-- New category form -->
      <form
        v-if="addingCategory"
        class="flex min-h-14 flex-col gap-2 rounded-lg border border-theme-200 bg-theme-50/60 px-3 py-3"
        @submit.prevent="createCategory"
      >
        <p
          v-if="newCategoryParent"
          class="truncate text-xs font-medium text-theme-700"
        >
          {{ t("media.inCategory", { category: newCategoryParent }) }}
        </p>
        <input
          v-model="newCategory"
          ref="newCategoryInput"
          type="text"
          class="form-input w-full min-w-0 rounded-lg border-theme-200 text-sm"
          :placeholder="t('media.categoryName')"
          @keydown.esc="cancelCategory"
        />
        <div class="grid w-full gap-2">
          <button
            type="submit"
            class="btn-primary w-full justify-center px-3 py-2 text-sm"
            :title="t('media.addCategory')"
            :disabled="categoryWorking"
          >
            <Icon icon="mdi:check" class="h-4 w-4" />
            {{ t("media.save") }}
          </button>
          <button
            type="button"
            class="btn-secondary w-full justify-center px-3 py-2 text-sm"
            :title="t('media.cancel')"
            :disabled="categoryWorking"
            @click="cancelCategory"
          >
            <Icon icon="mdi:close" class="h-4 w-4" />
            {{ t("media.cancel") }}
          </button>
        </div>
      </form>

    </div>

    <p
      v-if="categorySearch.trim() && visibleCategoryTree.length === 0"
      class="px-3 py-4 text-center text-sm text-slate-400"
    >
      {{ t("media.noCategoriesFound") }}
    </p>
  </section>

  <!-- Delete confirmation modal -->
  <ConfirmModal
    v-model="showCategoryDeleteModal"
    :title="t('media.deleteCategoryTitle')"
    :message="t('media.deleteCategoryMessage', { category: categoryDeleteTarget })"
    :confirm-label="t('media.deleteCategory')"
    :loading="categoryWorking"
    @confirm="executeCategoryDelete"
  />
</template>

<script setup>
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from "vue";
import { Icon } from "@iconify/vue";
import ConfirmModal from "./ConfirmModal.vue";
import { api } from "../api/index.js";
import { useI18n } from "../i18n/index.js";
import { useToastStore } from "../stores/toast.js";

const props = defineProps({
  categories: { type: Array, default: () => [] },
  statsFiles: { type: Array, default: () => [] },
  stats: { type: Object, default: null },
  modelValue: { type: String, default: null },
  draggedFile: { type: Object, default: null },
  selectedCount: { type: Number, default: 0 },
  selectedNames: { type: Array, default: () => [] },
  // Whether the user may create, rename and delete categories.
  canManage: { type: Boolean, default: true },
});

const emit = defineEmits([
  "update:modelValue",
  "categoriesUpdated",
  "categoryRenamed",
  "categoryDeleted",
  "fileDrop",
]);

const toast = useToastStore();
const { t } = useI18n();

// ---- Internal state ----
const newCategoryInput = ref(null);
const categorySearch = ref("");
const addingCategory = ref(false);
const newCategory = ref("");
const newCategoryParent = ref("");
const editingCategory = ref(null);
const editingCategoryName = ref("");
const categoryMenu = ref(null);
const showCategoryDeleteModal = ref(false);
const categoryDeleteTarget = ref("");
const categoryWorking = ref(false);
const dragOverCategory = ref(undefined);
const collapsedCategories = ref(new Set());
const seenParentCategories = ref(new Set());

// ---- Utilities ----
function categoryParts(category) {
  return String(category)
    .split("/")
    .map((part) => part.trim())
    .filter(Boolean);
}

function categoryLabel(category) {
  const parts = categoryParts(category);
  return parts[parts.length - 1] ?? String(category);
}

function categoryParent(category) {
  const parts = categoryParts(category);
  parts.pop();
  return parts.join(" / ");
}

function joinCategory(parent, name) {
  return [...categoryParts(parent), ...categoryParts(name)].join(" / ");
}

function categoryMatchesPath(category, categoryPath) {
  return (
    category === categoryPath ||
    String(category).startsWith(`${categoryPath} / `)
  );
}

// ---- Computed ----
const allCategoryCount = computed(() => props.stats?.total ?? props.statsFiles.length);
const uncategorizedCount = computed(
  () =>
    props.stats?.uncategorized ??
    props.statsFiles.filter((file) => !file.category || file.category === "")
      .length,
);

const categoryTree = computed(() =>
  props.categories.map((category) => {
    const parts = categoryParts(category);
    const label = parts[parts.length - 1] ?? category;
    const depth = Math.max(0, parts.length - 1);
    const count =
      props.stats?.categories?.[category] ??
      props.statsFiles.filter((file) =>
        categoryMatchesPath(file.category ?? "", category),
      ).length;
    const hasChildren = props.categories.some(
      (c) =>
        c !== category &&
        String(c).startsWith(`${category} / `) &&
        categoryParts(c).length === parts.length + 1,
    );

    return {
      path: category,
      label,
      depth,
      count,
      hasChildren,
    };
  }),
);

const visibleCategoryTree = computed(() => {
  const query = categorySearch.value.trim().toLocaleLowerCase();
  let searchMatches = null;

  if (query) {
    searchMatches = new Set();
    for (const category of categoryTree.value) {
      if (!category.path.toLocaleLowerCase().includes(query)) continue;
      const parts = categoryParts(category.path);
      for (let i = 1; i <= parts.length; i++) {
        searchMatches.add(parts.slice(0, i).join(" / "));
      }
    }
  }

  return categoryTree.value.filter((category) => {
    if (searchMatches && !searchMatches.has(category.path)) return false;

    const parts = categoryParts(category.path);
    if (query) return true;

    for (let i = 1; i < parts.length; i++) {
      const ancestor = parts.slice(0, i).join(" / ");
      if (collapsedCategories.value.has(ancestor)) return false;
    }
    return true;
  });
});

function toggleCollapse(categoryPath) {
  if (collapsedCategories.value.has(categoryPath)) {
    collapsedCategories.value.delete(categoryPath);
  } else {
    collapsedCategories.value.add(categoryPath);
  }
  // trigger reactivity
  collapsedCategories.value = new Set(collapsedCategories.value);
}

function expandCategoryPath(categoryPath) {
  if (!categoryPath) return;

  const expanded = new Set(collapsedCategories.value);
  const parts = categoryParts(categoryPath);
  for (let i = 1; i <= parts.length; i++) {
    expanded.delete(parts.slice(0, i).join(" / "));
  }
  collapsedCategories.value = expanded;
}

function selectCategory(categoryPath) {
  expandCategoryPath(categoryPath);
  emit("update:modelValue", categoryPath);
}

// ---- Drag & Drop ----
function categoryButtonClass(category) {
  if (dragOverCategory.value === category) {
    return "border-theme-400 bg-theme-100 text-theme-800 ring-2 ring-theme-300";
  }

  return props.modelValue === category
    ? "bg-theme-100 text-theme-700"
    : "text-slate-700 hover:bg-slate-50 hover:text-theme-700";
}

function onCategoryDragOver(category) {
  if (!props.draggedFile) return;
  dragOverCategory.value = category;
}

function onCategoryDragLeave(category) {
  if (dragOverCategory.value === category) {
    dragOverCategory.value = undefined;
  }
}

function onCategoryDrop(category) {
  const file = props.draggedFile;
  dragOverCategory.value = undefined;

  if (!file) return;

  emit("fileDrop", { file, targetCategory: category ?? "" });
}

// ---- Category menu ----
function toggleCategoryMenu(category) {
  categoryMenu.value = categoryMenu.value === category ? null : category;
}

function closeCategoryMenu(event) {
  if (categoryMenu.value === null) return;
  const target = event.target;
  if (target instanceof Element && target.closest("[data-category-menu-root]"))
    return;
  categoryMenu.value = null;
}

function onCategoryMenuKeydown(event) {
  if (event.key === "Escape") categoryMenu.value = null;
}

// ---- Add category ----
async function startCategory(parent = "") {
  addingCategory.value = true;
  newCategory.value = "";
  newCategoryParent.value = parent;
  categoryMenu.value = null;
  editingCategory.value = null;
  editingCategoryName.value = "";
  await nextTick();
  newCategoryInput.value?.focus();
}

async function startSubCategory(parent) {
  await startCategory(parent);
}

function cancelCategory() {
  addingCategory.value = false;
  newCategory.value = "";
  newCategoryParent.value = "";
}

async function createCategory() {
  const name = newCategory.value.trim();
  if (!name) return;

  categoryWorking.value = true;
  try {
    const res = await api.media.createCategory(name, newCategoryParent.value);
    const cats =
      res.meta?.categories ?? res.data?.categories ?? props.categories;
    const created =
      res.data?.name ?? joinCategory(newCategoryParent.value, name);
    emit("categoriesUpdated", cats);
    const selected =
      cats.find((c) => c.toLowerCase() === created.toLowerCase()) ?? created;
    selectCategory(selected);
    cancelCategory();
  } catch (err) {
    toast.error(err.message);
  } finally {
    categoryWorking.value = false;
  }
}

// ---- Rename category ----
function startRenameCategory(category) {
  editingCategory.value = category;
  editingCategoryName.value = categoryLabel(category);
  categoryMenu.value = null;
  addingCategory.value = false;
  newCategoryParent.value = "";
}

function cancelRenameCategory() {
  editingCategory.value = null;
  editingCategoryName.value = "";
}

async function renameCategory() {
  const oldName = editingCategory.value;
  const newName = joinCategory(
    categoryParent(oldName),
    editingCategoryName.value.trim(),
  );
  if (!oldName || !newName) return;

  categoryWorking.value = true;
  try {
    const res = await api.media.renameCategory(oldName, newName);
    const renamed = res.data?.name ?? newName;
    const cats = res.meta?.categories ?? props.categories;
    emit("categoriesUpdated", cats);
    emit("categoryRenamed", { from: oldName, to: renamed });
    if (props.modelValue && categoryMatchesPath(props.modelValue, oldName)) {
      selectCategory(props.modelValue.replace(oldName, renamed));
    }
    cancelRenameCategory();
    toast.success(t("media.categoryRenamed"));
  } catch (err) {
    toast.error(err.message);
  } finally {
    categoryWorking.value = false;
  }
}

// ---- Delete category ----
function confirmCategoryDelete(category) {
  categoryDeleteTarget.value = category;
  categoryMenu.value = null;
  showCategoryDeleteModal.value = true;
}

async function executeCategoryDelete() {
  const category = categoryDeleteTarget.value;
  if (!category) return;

  categoryWorking.value = true;
  try {
    const res = await api.media.deleteCategory(category);
    const cats =
      res.meta?.categories ??
      props.categories.filter((item) => !categoryMatchesPath(item, category));
    emit("categoriesUpdated", cats);
    emit("categoryDeleted", category);
    showCategoryDeleteModal.value = false;
    toast.success(t("media.categoryDeleted"));
  } catch (err) {
    toast.error(err.message);
  } finally {
    categoryWorking.value = false;
    categoryDeleteTarget.value = "";
  }
}

// ---- Lifecycle ----
watch(
  categoryTree,
  (tree) => {
    const parentPaths = new Set(
      tree
        .filter((category) => category.hasChildren)
        .map((category) => category.path),
    );
    const nextCollapsed = new Set();
    const nextSeenParents = new Set();

    for (const path of collapsedCategories.value) {
      if (parentPaths.has(path)) nextCollapsed.add(path);
    }

    for (const path of parentPaths) {
      nextSeenParents.add(path);
      if (!seenParentCategories.value.has(path)) {
        nextCollapsed.add(path);
      }
    }

    if (props.modelValue) {
      const parts = categoryParts(props.modelValue);
      for (let i = 1; i <= parts.length; i++) {
        nextCollapsed.delete(parts.slice(0, i).join(" / "));
      }
    }

    collapsedCategories.value = nextCollapsed;
    seenParentCategories.value = nextSeenParents;
  },
  { immediate: true },
);

watch(
  () => props.modelValue,
  (value) => {
    expandCategoryPath(value);
  },
  { immediate: true },
);

onMounted(() => {
  document.addEventListener("pointerdown", closeCategoryMenu);
  document.addEventListener("keydown", onCategoryMenuKeydown);
});

onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", closeCategoryMenu);
  document.removeEventListener("keydown", onCategoryMenuKeydown);
});
</script>
