<template>
  <Teleport to="body">
    <Transition name="palette">
      <div
        v-if="open"
        class="fixed inset-0 z-[70] flex items-start justify-center bg-ink-950/40 px-4 pt-[12vh] backdrop-blur-[2px]"
        @mousedown.self="close"
      >
        <div
          ref="dialogRef"
          role="dialog"
          aria-modal="true"
          :aria-label="t('app.search.title')"
          tabindex="-1"
          class="palette-panel w-full max-w-xl overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl"
        >
          <div class="flex items-center gap-3 border-b border-slate-200 px-4">
            <Icon icon="mdi:magnify" class="h-5 w-5 shrink-0 text-slate-400" aria-hidden="true" />
            <input
              ref="inputRef"
              v-model="query"
              type="text"
              role="combobox"
              aria-autocomplete="list"
              :aria-expanded="results.length > 0"
              aria-controls="command-palette-list"
              :aria-activedescendant="activeId"
              :placeholder="t('app.search.placeholder')"
              class="h-14 w-full border-0 bg-transparent p-0 text-[15px] text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0"
              @keydown.down.prevent="move(1)"
              @keydown.up.prevent="move(-1)"
              @keydown.enter.prevent="choose(results[activeIndex])"
            />
            <Icon
              v-if="searching"
              icon="mdi:loading"
              class="h-4 w-4 shrink-0 animate-spin text-slate-400"
              aria-hidden="true"
            />
            <span class="kbd shrink-0">Esc</span>
          </div>

          <div
            id="command-palette-list"
            ref="listRef"
            role="listbox"
            class="max-h-[min(60vh,28rem)] overflow-y-auto p-2"
          >
            <template v-for="section in sections" :key="section.key">
              <div class="px-2.5 pb-1 pt-2.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-400">
                {{ section.label }}
              </div>
              <button
                v-for="item in section.items"
                :id="`palette-${item.index}`"
                :key="item.id"
                type="button"
                role="option"
                :aria-selected="item.index === activeIndex"
                :data-index="item.index"
                class="flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-left text-sm transition-colors"
                :class="item.index === activeIndex ? 'bg-theme-50 text-theme-800' : 'text-slate-700'"
                @mousemove="activeIndex = item.index"
                @click="choose(item)"
              >
                <span
                  class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md ring-1 ring-inset"
                  :class="item.index === activeIndex
                    ? 'bg-white text-theme-600 ring-theme-200'
                    : 'bg-slate-50 text-slate-500 ring-slate-200'"
                  aria-hidden="true"
                >
                  <Icon :icon="item.icon" class="h-4 w-4" />
                </span>
                <span class="min-w-0 flex-1 truncate font-medium">{{ item.label }}</span>
                <span v-if="item.hint" class="shrink-0 truncate text-xs text-slate-400">{{ item.hint }}</span>
                <Icon
                  v-if="item.index === activeIndex"
                  icon="mdi:keyboard-return"
                  class="h-4 w-4 shrink-0 text-theme-500"
                  aria-hidden="true"
                />
              </button>
            </template>

            <div v-if="results.length === 0" class="px-4 py-10 text-center text-sm text-slate-500">
              {{ searching ? t("app.search.searching") : t("app.search.noResults", { query }) }}
            </div>
          </div>

          <div class="flex items-center gap-4 border-t border-slate-200 bg-slate-50/70 px-4 py-2.5 text-xs text-slate-500">
            <span class="flex items-center gap-1.5"><span class="kbd">↑</span><span class="kbd">↓</span>{{ t("app.search.navigate") }}</span>
            <span class="flex items-center gap-1.5"><span class="kbd">↵</span>{{ t("app.search.select") }}</span>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { Icon } from "@iconify/vue";
import { api } from "../api/index.js";
import { useAuthStore } from "../stores/auth.js";
import { useI18n } from "../i18n/index.js";
import { useDialogFocus } from "../composables/useDialogFocus.js";
import { contentTypeIcon, useNavigation } from "../composables/useNavigation.js";

const props = defineProps({
  open: { type: Boolean, default: false },
});
const emit = defineEmits(["update:open"]);

const router = useRouter();
const auth = useAuthStore();
const { t } = useI18n();
const { groups, contentTypes } = useNavigation();

const query = ref("");
const activeIndex = ref(0);
const entryResults = ref([]);
const searching = ref(false);
const dialogRef = ref(null);
const inputRef = ref(null);
const listRef = ref(null);
const openRef = computed(() => props.open);

function close() {
  emit("update:open", false);
}

useDialogFocus({ open: openRef, container: dialogRef, close });

const MAX_ENTRY_TYPES = 8;
const ENTRIES_PER_TYPE = 4;

function normalize(value) {
  return String(value ?? "")
    .toLocaleLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "");
}

function matches(item, needle) {
  if (!needle) return true;
  return normalize(`${item.label} ${item.hint ?? ""} ${item.keywords ?? ""}`).includes(needle);
}

const navigationItems = computed(() =>
  groups.value.flatMap((group) =>
    group.items.flatMap((item) => [
      {
        id: `nav:${item.key}`,
        label: item.label,
        hint: group.label ?? "",
        keywords: item.description,
        icon: item.icon,
        to: item.to,
      },
      ...(item.children ?? []).map((child) => ({
        id: `nav:${child.key}`,
        label: child.label,
        hint: item.label,
        icon: item.icon,
        to: child.to,
      })),
    ]),
  ),
);

const createItems = computed(() => {
  const items = contentTypes.value
    .filter((type) => auth.can("content.create", `content:${type.name}:*`))
    .map((type) => ({
      id: `new:${type.name}`,
      label: t("app.search.newEntry", { type: type.label }),
      icon: "mdi:plus",
      to: `/content/${type.name}/new`,
      keywords: t("app.search.createKeywords"),
    }));
  if (auth.can("schema.create")) {
    items.push({
      id: "new:type",
      label: t("app.search.newContentType"),
      icon: "mdi:shape-plus-outline",
      to: "/content-types/new",
      keywords: t("app.search.createKeywords"),
    });
  }
  return items;
});

const accountItems = computed(() => [
  { id: "account:profile", label: t("app.account.profile"), icon: "mdi:account-circle-outline", to: "/profile" },
  { id: "account:version", label: t("app.account.version"), icon: "mdi:information-outline", to: "/update" },
]);

const sections = computed(() => {
  const needle = normalize(query.value.trim());
  const raw = [
    { key: "nav", label: t("app.search.groupNavigate"), items: navigationItems.value.filter((i) => matches(i, needle)) },
    { key: "entries", label: t("app.search.groupEntries"), items: needle ? entryResults.value : [] },
    { key: "create", label: t("app.search.groupCreate"), items: createItems.value.filter((i) => matches(i, needle)) },
    { key: "account", label: t("app.search.groupAccount"), items: accountItems.value.filter((i) => matches(i, needle)) },
  ];
  let index = 0;
  return raw
    .filter((section) => section.items.length > 0)
    .map((section) => ({
      ...section,
      items: section.items.map((item) => ({ ...item, index: index++ })),
    }));
});

const results = computed(() => sections.value.flatMap((section) => section.items));
const activeId = computed(() =>
  results.value[activeIndex.value] ? `palette-${activeIndex.value}` : undefined,
);

function move(delta) {
  const count = results.value.length;
  if (count === 0) return;
  activeIndex.value = (activeIndex.value + delta + count) % count;
  nextTick(() => {
    listRef.value
      ?.querySelector(`[data-index="${activeIndex.value}"]`)
      ?.scrollIntoView({ block: "nearest" });
  });
}

function choose(item) {
  if (!item) return;
  close();
  router.push(item.to);
}

// Entry search across the readable collections, debounced and guarded
// against out-of-order responses.
let searchTimer = null;
let searchGeneration = 0;

watch(query, (value) => {
  activeIndex.value = 0;
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
  const types = contentTypes.value.slice(0, MAX_ENTRY_TYPES);
  const responses = await Promise.allSettled(
    types.map((type) => api.content.list(type.name, { q: needle, limit: ENTRIES_PER_TYPE, offset: 0 })),
  );
  if (generation !== searchGeneration) return;
  entryResults.value = responses.flatMap((response, i) => {
    if (response.status !== "fulfilled") return [];
    const type = types[i];
    return (response.value.data ?? []).map((entry) => ({
      id: `entry:${type.name}:${entry.uid || entry.id}`,
      label: entry.title || entry.slug || entry.id,
      hint: type.label,
      icon: contentTypeIcon(type),
      to: `/content/${type.name}/${entry.uid || entry.id}`,
    }));
  });
  searching.value = false;
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    query.value = "";
    entryResults.value = [];
    activeIndex.value = 0;
    nextTick(() => inputRef.value?.focus());
  },
);
</script>

<style scoped>
.palette-enter-active,
.palette-leave-active {
  transition: opacity 0.15s ease;
}

.palette-enter-active .palette-panel,
.palette-leave-active .palette-panel {
  transition: transform 0.15s ease, opacity 0.15s ease;
}

.palette-enter-from,
.palette-leave-to {
  opacity: 0;
}

.palette-enter-from .palette-panel,
.palette-leave-to .palette-panel {
  opacity: 0;
  transform: translateY(-6px) scale(0.98);
}
</style>
