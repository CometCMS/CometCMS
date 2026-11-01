<template>
  <div ref="rootRef" class="page-switcher relative min-w-0" @focusout="onFocusout">
    <button
      ref="triggerRef"
      type="button"
      class="page-switcher-trigger flex w-max max-w-full items-center gap-1 rounded-md px-1 text-left transition-colors"
      :title="t('site.builder.switchPage')"
      aria-haspopup="menu"
      :aria-expanded="open"
      :aria-controls="open ? menuId : undefined"
      @click="open ? close() : openMenu()"
      @keydown.down.prevent="openMenu()"
      @keydown.esc.stop.prevent="close(true)"
    >
      <span class="truncate text-sm font-semibold leading-tight">{{ heading }}</span>
      <Icon icon="mdi:chevron-down" class="h-4 w-4 shrink-0 opacity-70 transition-transform" :class="{ 'rotate-180': open }" aria-hidden="true" />
    </button>

    <div
      v-if="open"
      :id="menuId"
      tabindex="-1"
      class="builder-switcher-menu absolute left-0 top-full z-50 mt-1 w-80 overflow-hidden rounded-lg focus:outline-none border border-slate-200 bg-white text-sm shadow-lg"
      @keydown="onMenuKeydown"
    >
      <div class="border-b border-slate-100 p-2">
        <input
          ref="filterRef"
          v-model="filter"
          type="search"
          :placeholder="t('site.builder.filterPages')"
          :aria-label="t('site.builder.filterPages')"
          class="form-input w-full rounded-lg border-slate-300 py-1 text-xs"
        />
      </div>
      <div ref="menuRef" role="menu" tabindex="-1" :aria-label="t('site.builder.switchPage')" class="max-h-[60vh] overflow-y-auto py-1">
        <div v-for="group in groups" :key="group.key" role="group" :aria-labelledby="`${menuId}-${group.key}`">
          <p :id="`${menuId}-${group.key}`" class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ group.label }}</p>
          <div v-for="item in group.items" :key="item.key" role="none">
            <div class="flex" role="none">
              <button
                type="button"
                role="menuitem"
                tabindex="-1"
                :aria-current="item.current ? 'page' : undefined"
                class="flex min-w-0 flex-1 items-center gap-2 px-3 py-1.5 text-left hover:bg-slate-50 focus:bg-slate-50 focus:outline-none"
                :class="[item.current ? 'font-medium text-theme-700' : 'text-slate-700', { 'py-1 pl-8 text-[13px]': item.depth === 1 }]"
                :title="item.title"
                @click="go(item)"
                @keydown.right.prevent="item.entryType && expandEntries(item.entryType, true)"
              >
                <Icon :icon="item.icon" class="shrink-0" :class="[item.current ? 'text-theme-600' : 'text-slate-400', item.depth === 1 ? 'h-3.5 w-3.5' : 'h-4 w-4']" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                <span v-if="item.detail" class="min-w-0 max-w-[55%] shrink truncate text-xs font-normal text-slate-400">{{ item.detail }}</span>
                <span v-if="item.isHomepage" class="shrink-0 rounded bg-slate-100 px-1.5 py-px text-[10px] font-medium text-slate-500">{{ t("site.overview.homepage") }}</span>
                <Icon v-if="item.current" icon="mdi:check" class="h-4 w-4 shrink-0 text-theme-600" aria-hidden="true" />
              </button>
              <button
                v-if="item.entryType"
                type="button"
                role="menuitem"
                tabindex="-1"
                :data-entry-toggle="item.entryType.name"
                :aria-label="t('site.builder.browseEntries', { type: group.label })"
                :aria-expanded="expandedType === item.entryType.name"
                :aria-controls="expandedType === item.entryType.name ? `${menuId}-entries-${item.entryType.name}` : undefined"
                class="shrink-0 px-2 text-slate-400 hover:bg-slate-50 hover:text-slate-700 focus:bg-slate-50 focus:outline-none"
                @click="expandedType === item.entryType.name ? collapseEntries() : expandEntries(item.entryType)"
                @keydown.right.prevent="expandEntries(item.entryType, true)"
              >
                <Icon icon="mdi:chevron-right" class="h-4 w-4 transition-transform" :class="{ 'rotate-90': expandedType === item.entryType.name }" aria-hidden="true" />
              </button>
            </div>
            <div
              v-if="item.entryType && expandedType === item.entryType.name"
              :id="`${menuId}-entries-${item.entryType.name}`"
              role="group"
              :aria-label="t('site.builder.browseEntries', { type: group.label })"
              :aria-busy="entryLists[item.entryType.name]?.loading"
              @keydown.left.stop.prevent="collapseEntries(true)"
            >
              <button
                v-for="entry in entryLists[item.entryType.name]?.items ?? []"
                :key="entry.key"
                type="button"
                role="menuitem"
                tabindex="-1"
                :aria-current="entry.current ? 'page' : undefined"
                class="flex w-full items-center gap-2 py-1 pl-8 pr-3 text-left text-[13px] hover:bg-slate-50 focus:bg-slate-50 focus:outline-none"
                :class="entry.current ? 'font-medium text-theme-700' : 'text-slate-700'"
                @click="go(entry)"
              >
                <Icon icon="mdi:file-document-edit-outline" class="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate">{{ entry.label }}</span>
                <Icon v-if="entry.current" icon="mdi:check" class="h-4 w-4 shrink-0 text-theme-600" aria-hidden="true" />
              </button>
              <p v-if="entryLists[item.entryType.name]?.loading" role="status" class="py-2 pl-8 pr-3 text-xs text-slate-500">{{ t('common.loading') }}</p>
              <template v-else-if="entryLists[item.entryType.name]?.error">
                <p role="alert" class="py-1 pl-8 pr-3 text-xs text-red-600">{{ t('site.link.loadFailed') }}</p>
                <button type="button" role="menuitem" tabindex="-1" data-entry-action class="w-full py-1 pl-8 pr-3 text-left text-xs text-theme-700 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none" @click="loadTypeEntries(item.entryType)">{{ t('common.retry') }}</button>
              </template>
              <button
                v-else-if="entryLists[item.entryType.name]?.more"
                type="button"
                role="menuitem"
                tabindex="-1"
                data-entry-action
                class="w-full py-1 pl-8 pr-3 text-left text-xs text-theme-700 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none"
                @click="loadTypeEntries(item.entryType)"
              >{{ t('site.link.loadMore', { shown: entryLists[item.entryType.name].items.length, total: entryLists[item.entryType.name].total }) }}</button>
              <p v-else-if="!entryLists[item.entryType.name]?.items.length" role="status" class="py-2 pl-8 pr-3 text-xs text-slate-500">{{ t('contentList.noEntries') }}</p>
            </div>
          </div>
        </div>
        <p v-if="searching && !entryResults.length" role="status" class="px-3 py-2 text-xs text-slate-500">{{ t("site.builder.searchingEntries") }}</p>
        <p v-else-if="!groups.length" role="status" class="px-3 py-2 text-xs text-slate-500">{{ t("site.builder.noPages") }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Icon } from "@iconify/vue";
import { componentIcon } from "./kindIcons.js";
import { api } from "../../../api/index.js";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { DEFAULT_TEMPLATE, FRAME_DEFAULT, frameLabel, templateList, templateName, usesTemplates } from "../../../composables/templates.js";
import { hasListPage } from "../../../composables/siteLinks.js";
import { useSiteStore } from "../../../stores/site.js";
import { useI18n } from "../../../i18n/index.js";

/** The builder title, opening a menu of every page, region and component to edit instead, and a search for entries. */
const props = defineProps({
  heading: { type: String, required: true },
  target: { type: String, required: true },
  name: { type: String, required: true },
});

const REGION_ICONS = { header: "mdi:page-layout-header", footer: "mdi:page-layout-footer" };
const ENTRIES_PER_TYPE = 5;
const ENTRY_PAGE_SIZE = 50;
const SPECIAL_PAGE_ICONS = { not_found: "mdi:file-question-outline", maintenance: "mdi:tools", modal: "mdi:dock-window", search_results: "mdi:text-search" };

const route = useRoute();
const router = useRouter();
const builder = useSiteBuilder();
const site = useSiteStore();
const { t } = useI18n();
const rootRef = ref(null);
const triggerRef = ref(null);
const menuRef = ref(null);
const filterRef = ref(null);
const open = ref(false);
const filter = ref("");
const menuId = `page-switcher-${useId()}`;
const entryResults = ref([]);
const searching = ref(false);
const expandedType = ref("");
const entryRecords = ref({});
const entryLists = computed(() => Object.fromEntries(Object.entries(entryRecords.value).map(([name, state]) => {
  const type = builder.pageTypes.value.find((type) => type.name === name);
  return [name, { ...state, items: type ? state.entries.map((entry) => entryItem(type, entry)) : [] }];
})));
const typeAreas = ref({});

/**
 * A content type's pages: each page kind and, once it has more than the
 * default one, its templates in order. A template opens its own content; its
 * header and footer are edited under "Site-wide", in Components or from the
 * canvas, so the row only names the parts that differ from the site's.
 */
function typeItems(type) {
  const editing = ["layout", "page"].includes(props.target) && props.name === type.name;
  const variants = hasListPage(builder.settings.value, type.name) ? ["detail", "index"] : ["detail"];
  return variants.flatMap((variant) => {
    const path = `/page-builder/builder/layout/${type.name}/${variant}`;
    const components = builder.components.value;
    const templates = site.draft?.templates ?? site.data?.templates;
    const shown = usesTemplates(templates, type.name, variant) ? templateList(templates, type.name, variant) : [];
    const editingVariant = editing && (props.target === "page" ? variant === "detail" : route.params.variant === variant);
    const editingTemplate = editingVariant && shown.some((template) => template.id === route.query.template);
    return [
      {
        key: `layout:${type.name}:${variant}`,
        label: t(`site.variant.${variant}`),
        icon: variant === "index" ? "mdi:view-grid-outline" : type.icon || "mdi:file-multiple-outline",
        path,
        current: props.target !== "page" && editingVariant && !editingTemplate,
        entryType: variant === "detail" && hasEntryAreas(type) ? type : null,
        isHomepage: builder.settings.value?.homepage?.type === type.name && (variant === "index") === !builder.settings.value?.homepage?.entry,
      },
      ...shown.map((template) => {
        const parts = [frameLabel(t, template.header, "header", components), frameLabel(t, template.footer, "footer", components)];
        const distinct = [template.header !== FRAME_DEFAULT && parts[0], template.footer !== FRAME_DEFAULT && parts[1]].filter(Boolean);
        return {
          key: `template:${type.name}:${variant}:${template.id}`,
          label: templateName(t, template),
          detail: distinct.join(" · "),
          title: parts.join(" · "),
          icon: "mdi:page-layout-header-footer",
          depth: 1,
          path,
          query: { template: template.id },
          current: props.target !== "page" && editingVariant && route.query.template === template.id,
        };
      }),
    ];
  });
}

const allGroups = computed(() => [
  ...builder.pageTypes.value.map((type) => ({
    key: `type:${type.name}`,
    label: type.label ?? type.name,
    items: typeItems(type),
  })),
  {
    key: "site",
    label: t("site.builder.siteWide"),
    items: [
      ...Object.keys(site.data?.regions ?? REGION_ICONS).map((region) => [region, REGION_ICONS[region] ?? "mdi:page-layout-body"]),
      ...Object.keys(site.data?.special_pages ?? SPECIAL_PAGE_ICONS).map((page) => [page, SPECIAL_PAGE_ICONS[page] ?? "mdi:file-outline"]),
    ].map(([region, icon]) => ({
      key: `region:${region}`,
      label: t(`site.region.${region}`),
      icon,
      path: `/page-builder/builder/region/${region}`,
      current: props.target === "region" && props.name === region,
    })),
  },
  {
    key: "components",
    label: t("site.builder.components"),
    items: [...builder.components.value]
      .sort((a, b) => a.name.localeCompare(b.name))
      .map((component) => ({
        key: `component:${component.id}`,
        label: component.name,
        icon: componentIcon(component),
        path: `/page-builder/builder/component/${component.id}`,
        current: props.target === "component" && props.name === component.id,
      })),
  },
]);

const groups = computed(() => {
  const terms = filter.value.trim().toLowerCase();
  return [
    ...allGroups.value
      // Rows no longer repeat their type's name, so a match on the heading keeps the whole group.
      .map((group) => ({ ...group, items: !terms || group.label.toLowerCase().includes(terms) ? group.items : group.items.filter((item) => `${item.label} ${item.detail ?? ""}`.toLowerCase().includes(terms)) })),
    { key: "entries", label: t("site.builder.entries"), items: entryResults.value },
  ].filter((group) => group.items.length);
});

/**
 * An entry opens its own page content when its type has a page field, and
 * otherwise the type's entry template previewing it.
 */
function entryItem(type, entry) {
  const hasPage = Object.values(type.fields ?? {}).some((config) => config?.type === "page");
  return {
    key: `entry:${type.name}:${entry.id}`,
    label: entry.title || entry.slug || entry.id,
    detail: type.label ?? type.name,
    icon: type.icon || "mdi:file-document-outline",
    path: hasPage ? `/page-builder/builder/page/${type.name}/${entry.id}` : `/page-builder/builder/layout/${type.name}/detail`,
    query: hasPage ? {} : { entry: entry.id },
    current: hasPage ? props.target === "page" && props.name === type.name && route.params.variant === entry.id : false,
  };
}

/**
 * Only templates with a placed content area offer entry design links. The
 * open template answers from the canvas, unsaved changes included, so
 * removing its area hides the links before it is saved.
 */
function hasEntryAreas(type) {
  const editing = props.target === "layout" && props.name === type.name && route.params.variant === "detail";
  if (editing && builder.placedPageFields().length) return true;
  const open = editing ? (typeof route.query.template === "string" && route.query.template) || DEFAULT_TEMPLATE : null;
  return (typeAreas.value[type.name] ?? []).some((area) => area.templates.some((id) => id !== open));
}

async function loadAreas() {
  const types = builder.pageTypes.value.filter((type) => Object.values(type.fields ?? {}).some((field) => field?.type === "page"));
  const results = await Promise.allSettled(types.map((type) => api.site.areas(type.name)));
  typeAreas.value = Object.fromEntries(results.flatMap((result, i) => result.status === "fulfilled" ? [[types[i].name, result.value.data ?? []]] : []));
}

async function expandEntries(type, focusFirst = false) {
  expandedType.value = type.name;
  if (!entryRecords.value[type.name]) await loadTypeEntries(type);
  await nextTick();
  if (focusFirst && expandedType.value === type.name) {
    document.getElementById(`${menuId}-entries-${type.name}`)?.querySelector('[role="menuitem"]')?.focus();
  }
}

function collapseEntries(restoreFocus = false) {
  const name = expandedType.value;
  expandedType.value = "";
  if (restoreFocus) [...(menuRef.value?.querySelectorAll('[data-entry-toggle]') ?? [])].find((button) => button.dataset.entryToggle === name)?.focus();
}

async function loadTypeEntries(type) {
  entryRecords.value[type.name] ??= { entries: [], total: 0, more: false, loading: false, error: false };
  const state = entryRecords.value[type.name];
  if (state.loading) return;
  // Loading replaces the action button. Keep focus in the menu while it does.
  const restoreFocus = document.activeElement?.hasAttribute('data-entry-action');
  const offset = state.entries.length;
  if (restoreFocus) menuRef.value?.focus();
  state.loading = true;
  state.error = false;
  try {
    const response = await api.content.list(type.name, { limit: ENTRY_PAGE_SIZE, offset: state.entries.length });
    const entries = response.data ?? [];
    state.entries.push(...entries);
    state.total = response.meta?.total ?? state.entries.length;
    state.more = entries.length > 0 && state.entries.length < state.total;
  } catch {
    state.error = true;
  } finally {
    state.loading = false;
    await nextTick();
    if (restoreFocus && open.value && expandedType.value === type.name && document.activeElement === menuRef.value) {
      const items = document.getElementById(`${menuId}-entries-${type.name}`)?.querySelectorAll('[role="menuitem"]');
      (items?.[offset] ?? items?.[0] ?? menuRef.value)?.focus();
    }
  }
}

// Entries are searched across the page types, debounced and guarded against out-of-order responses.
let searchTimer = null;
let searchGeneration = 0;

watch(filter, (value) => {
  collapseEntries();
  clearTimeout(searchTimer);
  const needle = value.trim();
  const generation = ++searchGeneration;
  if (needle.length < 2) {
    entryResults.value = [];
    searching.value = false;
    return;
  }
  searching.value = true;
  searchTimer = setTimeout(() => searchEntries(needle, generation), 200);
});

async function searchEntries(needle, generation) {
  const types = builder.pageTypes.value;
  const responses = await Promise.allSettled(types.map((type) => api.content.list(type.name, { q: needle, limit: ENTRIES_PER_TYPE, offset: 0 })));
  if (generation !== searchGeneration) return;
  entryResults.value = responses.flatMap((response, i) => (response.status === "fulfilled" ? (response.value.data ?? []).map((entry) => entryItem(types[i], entry)) : []));
  searching.value = false;
}

function menuItems() {
  return [...(menuRef.value?.querySelectorAll('[role="menuitem"]') ?? [])];
}

async function openMenu() {
  filter.value = "";
  open.value = true;
  loadAreas();
  await nextTick();
  filterRef.value?.focus();
}

function close(restoreFocus = false) {
  open.value = false;
  collapseEntries();
  if (restoreFocus) triggerRef.value?.focus();
}

function go(item) {
  close();
  if (item.current) return;
  // Keep where the builder was opened from, so "back" still leads there, and the previewed entry.
  const back = typeof route.query.back === "string" ? route.query.back : "";
  const entry = item.path.startsWith("/page-builder/builder/layout/") && typeof route.query.entry === "string" ? route.query.entry : "";
  router.push({ path: item.path, query: { ...(entry ? { entry } : {}), ...(item.query ?? {}), ...(back ? { back } : {}) } });
}

function onMenuKeydown(event) {
  if (event.key === "Escape") {
    event.preventDefault();
    event.stopPropagation();
    if (expandedType.value) collapseEntries(true);
    else close(true);
    return;
  }
  if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
  const inFilter = event.target === filterRef.value;
  if (inFilter && ["Home", "End"].includes(event.key)) return;
  event.preventDefault();
  event.stopPropagation();
  const items = menuItems();
  if (!items.length) return;
  const current = items.indexOf(document.activeElement);
  if (inFilter || current === -1) {
    items[event.key === "ArrowUp" || event.key === "End" ? items.length - 1 : 0].focus();
    return;
  }
  if (event.key === "ArrowUp" && current === 0 && filterRef.value) {
    filterRef.value.focus();
    return;
  }
  const index = event.key === "Home" ? 0
    : event.key === "End" ? items.length - 1
    : (current + (event.key === "ArrowDown" ? 1 : -1) + items.length) % items.length;
  items[index].focus();
}

function onOutsidePointerdown(event) {
  if (!rootRef.value?.contains(event.target)) close();
}

// Also catches clicks into the canvas, which the outside-pointerdown listener cannot see.
function onFocusout(event) {
  if (!rootRef.value?.contains(event.relatedTarget)) close();
}

onMounted(() => document.addEventListener("pointerdown", onOutsidePointerdown));
onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", onOutsidePointerdown);
  clearTimeout(searchTimer);
});
</script>

<style scoped>
.page-switcher-trigger {
  color: rgb(var(--builder-toolbar-text, var(--color-slate-900)));
}
.page-switcher-trigger:hover,
.page-switcher-trigger[aria-expanded="true"] {
  background: var(--builder-toolbar-hover, rgb(var(--color-slate-100)));
}
</style>
