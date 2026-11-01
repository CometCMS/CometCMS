<template>
  <div ref="rootRef" class="template-switcher relative flex min-w-0 rounded-md" :class="{ 'is-active': active }" @focusout="onFocusout">
    <button
      type="button"
      class="template-primary flex min-w-0 max-w-[16rem] items-center gap-1.5 rounded-l-md px-2.5 py-1 text-xs font-medium transition-colors"
      :aria-pressed="active"
      :aria-current="active ? 'page' : undefined"
      :title="shownTitle"
      :disabled="disabled"
      @click="emit('navigate')"
      @keydown.down.prevent="openMenu()"
    >
      <Icon icon="mdi:page-layout-header-footer" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
      <span class="truncate">{{ shownName }}</span>
    </button>
    <button
      ref="triggerRef"
      type="button"
      class="template-trigger flex items-center rounded-r-md px-1.5 py-1 transition-colors"
      :title="t('site.templates.switch')"
      :aria-label="t('site.templates.switch')"
      :disabled="disabled"
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
      class="builder-switcher-menu absolute left-0 top-full z-50 mt-1 w-80 overflow-hidden rounded-lg border border-slate-200 bg-white text-sm text-slate-700 shadow-lg"
      @keydown="onMenuKeydown"
    >
      <div class="border-b border-slate-100 px-3 py-2">
        <p class="font-semibold text-slate-900">{{ t("site.templates.shown", { name: shownName }) }}</p>
        <p class="text-xs text-slate-500">{{ reason }}</p>
      </div>
      <div ref="menuRef" role="menu" :aria-label="t('site.templates.title')" class="max-h-[60vh] overflow-y-auto py-1">
        <p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ t("site.templates.editContentOf") }}</p>
        <button
          v-for="template in templates"
          :key="template.id"
          type="button"
          role="menuitemradio"
          tabindex="-1"
          :aria-checked="shown?.id === template.id"
          class="menu-row"
          @click="choose(() => emit('open', template.id))"
        >
          <Icon icon="mdi:page-layout-header-footer" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
          <span class="min-w-0 flex-1">
            <span class="flex items-center gap-1.5">
              <span class="truncate" :class="{ 'font-medium text-theme-700': shown?.id === template.id }">{{ templateName(t, template) }}</span>
              <span
                v-if="shown?.live === template.id"
                class="shrink-0 rounded bg-emerald-50 px-1.5 py-px text-[10px] font-medium text-emerald-700"
                :title="t('site.templates.liveHint')"
              >{{ t("site.templates.liveTag") }}</span>
            </span>
          </span>
          <Icon v-if="shown?.id === template.id" icon="mdi:check" class="h-4 w-4 shrink-0 text-theme-600" aria-hidden="true" />
        </button>
        <button type="button" role="menuitem" tabindex="-1" class="menu-row" @click="choose(() => emit('new'))">
          <Icon icon="mdi:plus" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
          <span class="min-w-0 flex-1 truncate">{{ t("site.templates.newFromShown", { name: shownName }) }}</span>
        </button>

        <router-link
          :to="{ path: '/page-builder/templates', query: { type, variant, template: shown?.id ?? 'default' } }"
          role="menuitem"
          tabindex="-1"
          class="menu-row mt-1 border-t border-slate-100"
        >
          <Icon icon="mdi:pencil-outline" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
          <span class="min-w-0 flex-1 truncate">{{ t("site.templates.editTemplates") }}</span>
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from "vue";
import { Icon } from "@iconify/vue";
import { reasonLabel, templateName } from "../../../composables/templates.js";
import { useI18n } from "../../../i18n/index.js";

/**
 * The template side of the builder's scope switch: names the template around
 * the canvas and edits it (`navigate`), while its menu opens another
 * template's content (the one the entry uses is tagged "Using") or starts a
 * new template as a copy.
 */
const props = defineProps({
  /** The canvas edits this template (not only the entry). */
  active: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  /**
   * The template the preview showed: `{ id, name, header, footer,
   * conditions, reason, live }`; `live` is the id of the one the page uses on the website.
   */
  shown: { type: Object, default: null },
  /** The page kind's templates (draft), in order. */
  templates: { type: Array, default: () => [] },
  type: { type: String, required: true },
  variant: { type: String, required: true },
  /** The content type's schema, to name fields in conditions. */
  schema: { type: Object, default: null },
  components: { type: Array, default: () => [] },
});
const emit = defineEmits(["navigate", "open", "new"]);

const { t } = useI18n();
const rootRef = ref(null);
const triggerRef = ref(null);
const menuRef = ref(null);
const open = ref(false);
const menuId = `template-menu-${useId()}`;

const shownName = computed(() => props.shown ? templateName(t, props.shown) : t("site.templates.defaultName"));
const reason = computed(() => props.shown ? reasonLabel(t, props.shown.reason, props.shown, props.schema) : "");
const shownTitle = computed(() => [t("site.templates.shown", { name: shownName.value }), reason.value].filter(Boolean).join(" · "));

function choose(action) {
  close(true);
  action();
}

function menuItems() {
  return [...(menuRef.value?.querySelectorAll('[role^="menuitem"]') ?? [])];
}

async function openMenu() {
  open.value = true;
  await nextTick();
  const items = menuItems();
  (items.find((item) => item.getAttribute("aria-checked") === "true") ?? items[0])?.focus();
}

function close(restoreFocus = false) {
  open.value = false;
  if (restoreFocus) triggerRef.value?.focus();
}

function onMenuKeydown(event) {
  if (event.key === "Escape") {
    event.preventDefault();
    event.stopPropagation();
    close(true);
    return;
  }
  if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
  event.preventDefault();
  const items = menuItems();
  if (!items.length) return;
  const current = items.indexOf(document.activeElement);
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
</script>

<style scoped>
/* Shared styling with the entry breadcrumb. */
.template-switcher {
  color: rgb(var(--builder-toolbar-text) / 0.8);
}
.template-switcher.is-active {
  background: var(--builder-toolbar-selected);
  color: var(--builder-toolbar-selected-text);
  box-shadow: 0 1px 2px rgb(0 0 0 / 0.1);
}
.template-primary,
.template-trigger {
  color: inherit;
  background: transparent;
}
.template-trigger {
  border-left: 1px solid color-mix(in srgb, currentColor 25%, transparent);
}
.template-primary:hover:not(:disabled),
.template-trigger:hover:not(:disabled),
.template-trigger[aria-expanded="true"] {
  background: var(--builder-toolbar-hover);
}
.is-active .template-primary:hover:not(:disabled),
.is-active .template-trigger:hover:not(:disabled),
.is-active .template-trigger[aria-expanded="true"] {
  background: rgb(0 0 0 / 0.06);
}
.template-primary:disabled,
.template-trigger:disabled {
  opacity: 0.5;
}
.menu-row {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.5rem;
  padding: 0.375rem 0.75rem;
  text-align: left;
}
.menu-row:hover,
.menu-row:focus {
  background: rgb(var(--color-slate-50));
  outline: none;
}
</style>
