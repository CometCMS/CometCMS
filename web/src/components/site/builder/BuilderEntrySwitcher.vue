<template>
  <div ref="rootRef" class="entry-switcher relative flex min-w-0 rounded-md" :class="{ 'is-active': active }" @focusout="onFocusout">
    <button
      type="button"
      class="entry-primary flex min-w-0 max-w-[16rem] items-center gap-1.5 rounded-l-md px-2.5 py-1 text-xs font-medium transition-colors"
      :class="{ 'rounded-r-md': !entries.length }"
      :aria-pressed="navigable ? active : undefined"
      :aria-current="active && navigable ? 'page' : undefined"
      :aria-haspopup="!navigable ? 'menu' : undefined"
      :aria-expanded="!navigable ? open : undefined"
      :aria-controls="!navigable && open ? menuId : undefined"
      :title="unavailable || title || label"
      :disabled="disabled"
      :aria-disabled="unavailable ? 'true' : undefined"
      @click="unavailable ? undefined : navigable ? emit('navigate') : open ? close() : openMenu()"
      @keydown.down.prevent="openMenu()"
    >
      <Icon :icon="icon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
      <span class="truncate">{{ label }}</span>
    </button>
    <button
      v-if="entries.length"
      ref="triggerRef"
      type="button"
      class="entry-trigger flex items-center rounded-r-md px-1.5 py-1 transition-colors"
      :title="t('site.builder.switchEntry')"
      :aria-label="t('site.builder.switchEntry')"
      :disabled="disabled || !entries.length"
      aria-haspopup="menu"
      :aria-expanded="open"
      :aria-controls="open ? menuId : undefined"
      @click="open ? close() : openMenu()"
      @keydown.down.prevent="openMenu()"
      @keydown.esc.stop.prevent="close(true)"
    >
      <Icon icon="mdi:chevron-down" class="h-4 w-4 shrink-0 opacity-70 transition-transform" :class="{ 'rotate-180': open }" aria-hidden="true" />
    </button>

    <div
      v-if="open"
      :id="menuId"
      tabindex="-1"
      class="builder-switcher-menu absolute left-0 top-full z-50 mt-1 w-72 overflow-hidden rounded-lg focus:outline-none border border-slate-200 bg-white text-sm text-slate-700 shadow-lg"
      @keydown="onMenuKeydown"
    >
      <div v-if="showFilter" class="border-b border-slate-100 p-2">
        <input
          ref="filterRef"
          v-model="filter"
          type="search"
          :placeholder="t('site.builder.filterEntries')"
          :aria-label="t('site.builder.filterEntries')"
          class="form-input w-full rounded-lg border-slate-300 py-1 text-xs"
        />
      </div>
      <div ref="menuRef" role="menu" tabindex="-1" :aria-label="t('site.builder.switchEntry')" class="max-h-[60vh] overflow-y-auto py-1">
        <!-- Entries of several types (a component's samples) sit under their type. -->
        <GroupedList :groups="groups" :item-key="(entry) => `${entry.type ?? ''}:${entry.id}`" list-class="">
          <template #default="{ item: entry }">
            <button
              type="button"
              role="menuitemradio"
              tabindex="-1"
              :aria-checked="entry.id === modelValue"
              class="entry-menu-item flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50 focus:bg-slate-50 focus:outline-none"
              :class="[
                entry.id === modelValue ? 'font-medium text-theme-700' : 'text-slate-700',
                { 'uses-template': templateId && entry.template === templateId },
              ]"
              :title="templateId && entry.template === templateId ? t('site.builder.entryUsesTemplate') : undefined"
              @click="select(entry)"
            >
              <span class="min-w-0 flex-1 truncate">{{ entry.title || entry.id }}</span>
              <span v-if="templateId && entry.template === templateId" class="sr-only">{{ t('site.builder.entryUsesTemplate') }}</span>
              <span v-if="entry.status !== 'published'" class="shrink-0 text-[11px] text-slate-500">{{ t(`status.${entry.status}`) }}</span>
              <Icon v-if="entry.id === modelValue" icon="mdi:check" class="h-4 w-4 shrink-0 text-theme-600" aria-hidden="true" />
            </button>
          </template>
        </GroupedList>
        <p v-if="!filteredEntries.length" role="status" class="px-3 py-2 text-xs text-slate-500">{{ t("site.builder.noEntries") }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from "vue";
import { Icon } from "@iconify/vue";
import GroupedList from "./GroupedList.vue";
import { useI18n } from "../../../i18n/index.js";

const props = defineProps({
  label: { type: String, required: true },
  title: { type: String, default: "" },
  icon: { type: String, default: "mdi:file-document-edit-outline" },
  modelValue: { type: String, default: "" },
  entries: { type: Array, default: () => [] },
  /** Labels of the entries' types, by name, for entries of several types. */
  typeLabels: { type: Object, default: () => ({}) },
  templateId: { type: String, default: "" },
  active: { type: Boolean, default: false },
  navigable: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
  /**
   * Why the entry cannot be navigated to: the button stays visible but greyed
   * out and says why, while the menu still picks the entry.
   */
  unavailable: { type: String, default: "" },
});
const emit = defineEmits(["navigate", "update:modelValue"]);
const { t } = useI18n();
const rootRef = ref(null);
const triggerRef = ref(null);
const menuRef = ref(null);
const filterRef = ref(null);
const open = ref(false);
const filter = ref("");
const menuId = `entry-switcher-${useId()}`;
const showFilter = computed(() => props.entries.length > 8);
const filteredEntries = computed(() => {
  const term = filter.value.trim().toLowerCase();
  return props.entries.filter((entry) => !term || `${entry.title} ${entry.id} ${props.typeLabels[entry.type] ?? ""}`.toLowerCase().includes(term));
});
const groups = computed(() => {
  const byType = new Map();
  for (const entry of filteredEntries.value) {
    const type = entry.type ?? "";
    if (!byType.has(type)) byType.set(type, { key: type || "entries", label: props.typeLabels[type] ?? type, items: [] });
    byType.get(type).items.push(entry);
  }
  return [...byType.values()];
});

function menuItems() {
  return [...(menuRef.value?.querySelectorAll('[role="menuitemradio"]') ?? [])];
}

async function openMenu() {
  if (props.disabled || !props.entries.length) return;
  filter.value = "";
  open.value = true;
  await nextTick();
  if (filterRef.value) {
    filterRef.value.focus();
    return;
  }
  const items = menuItems();
  (items.find((item) => item.getAttribute("aria-checked") === "true") ?? items[0] ?? menuRef.value)?.focus();
}

function close(restoreFocus = false) {
  open.value = false;
  if (restoreFocus) triggerRef.value?.focus();
}

function select(entry) {
  close(true);
  if (entry.id !== props.modelValue) emit("update:modelValue", entry.id);
}

function onMenuKeydown(event) {
  if (event.key === "Escape") {
    event.preventDefault();
    event.stopPropagation();
    close(true);
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
onBeforeUnmount(() => document.removeEventListener("pointerdown", onOutsidePointerdown));

watch(() => props.disabled, (disabled) => { if (disabled) close(); });
</script>

<style scoped>
.entry-menu-item.uses-template {
  background: rgb(var(--color-theme-50));
  box-shadow: inset 2px 0 0 rgb(var(--color-theme-400));
}
.entry-menu-item.uses-template:hover,
.entry-menu-item.uses-template:focus {
  background: rgb(var(--color-theme-100));
}
.entry-switcher {
  color: rgb(var(--builder-toolbar-text) / 0.8);
}
.entry-switcher.is-active {
  background: var(--builder-toolbar-selected);
  color: var(--builder-toolbar-selected-text);
  box-shadow: 0 1px 2px rgb(0 0 0 / 0.1);
}
.entry-primary,
.entry-trigger {
  color: inherit;
  background: transparent;
}
.entry-trigger {
  border-left: 1px solid currentColor;
  border-left-color: color-mix(in srgb, currentColor 25%, transparent);
}
.entry-primary:hover:not(:disabled),
.entry-trigger:hover:not(:disabled),
.entry-trigger[aria-expanded="true"] {
  background: var(--builder-toolbar-hover);
}
.is-active .entry-primary:hover:not(:disabled),
.is-active .entry-trigger:hover:not(:disabled),
.is-active .entry-trigger[aria-expanded="true"] {
  background: rgb(0 0 0 / 0.06);
}
.entry-primary:disabled,
.entry-trigger:disabled,
.entry-primary[aria-disabled="true"] {
  opacity: 0.5;
}
.entry-primary[aria-disabled="true"] {
  cursor: not-allowed;
}
</style>
