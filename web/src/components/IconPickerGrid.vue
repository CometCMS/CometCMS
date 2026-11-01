<template>
  <div class="relative" ref="containerRef" @keydown.esc="onEscape">
    <!-- Trigger -->
    <button
      :id="id"
      type="button"
      :aria-expanded="isOpen"
      :aria-label="label || undefined"
      :title="label || undefined"
      :disabled="disabled"
      ref="triggerRef"
      @click="toggle"
      :class="compact
        ? 'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-theme-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-theme-500 disabled:cursor-wait disabled:opacity-50'
        : 'form-input w-full text-left flex items-center gap-2 rounded-lg border-slate-300 text-sm cursor-pointer'"
    >
      <Icon
        :icon="modelValue || placeholder"
        class="shrink-0"
        :class="compact ? 'h-6 w-6' : ['h-5 w-5', modelValue ? 'text-slate-600' : 'text-slate-300']"
      />
      <span
        v-if="!compact"
        class="flex-1 truncate"
        :class="modelValue ? 'text-slate-700' : 'text-slate-400'"
      >
        {{ modelValue ? iconLabel(modelValue) : emptyLabel || iconLabel(placeholder) }}
      </span>
      <Icon
        v-if="!compact"
        icon="mdi:chevron-down"
        class="w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150"
        :class="isOpen ? 'rotate-180' : ''"
      />
    </button>

    <!-- Dropdown panel -->
    <div
      v-if="isOpen"
      class="absolute z-50 left-0 mt-1 w-80 bg-white border border-slate-200 rounded-lg shadow-xl overflow-hidden"
      :class="compact ? 'max-w-[calc(100vw-4rem)]' : 'max-w-full'"
    >
      <!-- Search -->
      <div class="p-2 border-b border-slate-100">
        <input
          ref="searchInputRef"
          v-model="query"
          type="text"
          :placeholder="t('iconPicker.search')"
          :aria-label="t('iconPicker.search')"
          class="form-input w-full rounded-lg border-slate-300 text-sm"
        />
      </div>

      <!-- Icon grid -->
      <div class="p-2 max-h-64 overflow-y-auto">
        <div
          v-if="filteredIcons.length === 0"
          class="py-6 text-center text-sm text-slate-400"
        >
          {{ t("iconPicker.noResults") }}
        </div>
        <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(2rem,1fr))] gap-0.5">
          <button
            v-for="icon in filteredIcons"
            :key="icon"
            type="button"
            :title="iconLabel(icon)"
            :aria-label="iconLabel(icon)"
            :aria-pressed="icon === modelValue"
            @click="select(icon)"
            class="w-8 h-8 flex items-center justify-center rounded transition-colors"
            :class="
              icon === modelValue
                ? 'bg-theme-100 text-theme-700 ring-1 ring-theme-400'
                : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800'
            "
          >
            <Icon :icon="icon" class="w-5 h-5" />
          </button>
        </div>
      </div>

      <!-- Manual / custom icon input -->
      <div v-if="allowCustom" class="p-2 border-t border-slate-100">
        <input
          :value="modelValue"
          type="text"
          :placeholder="t('iconPicker.custom')"
          :aria-label="t('iconPicker.customLabel')"
          class="form-input w-full rounded-lg border-slate-300 text-xs"
          @input="emit('update:modelValue', $event.target.value)"
          @keydown.enter.prevent="close(true)"
        />
      </div>
      <div v-if="resetLabel && modelValue" class="border-t border-slate-100 p-2">
        <button type="button" class="btn-secondary btn-sm w-full justify-center" @click="select('')">{{ resetLabel }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import { Icon } from "@iconify/vue";
import { useI18n } from "../i18n/index.js";

const { t } = useI18n();

const props = defineProps({
  id: { type: String, default: undefined },
  modelValue: { type: String, default: "" },
  placeholder: { type: String, default: "mdi:file-document-outline" },
  icons: { type: Array, default: null },
  allowCustom: { type: Boolean, default: true },
  /** Shown instead of the placeholder's name while no icon is chosen. */
  emptyLabel: { type: String, default: "" },
  /** Icon-only trigger, suitable for a library row. */
  compact: { type: Boolean, default: false },
  label: { type: String, default: "" },
  disabled: { type: Boolean, default: false },
  resetLabel: { type: String, default: "" },
});

const emit = defineEmits(["update:modelValue"]);

// ── icon catalog ─────────────────────────────────────────────────────────────

const ALL_ICONS = [
  // Documents & writing
  "mdi:file-document-outline",
  "mdi:file-text-outline",
  "mdi:text-box-outline",
  "mdi:note-text-outline",
  "mdi:newspaper-variant-outline",
  "mdi:book-open-page-variant-outline",
  "mdi:book-outline",
  "mdi:bookshelf",
  "mdi:script-text-outline",
  "mdi:pencil-outline",
  "mdi:format-list-bulleted",
  "mdi:format-list-numbered",
  "mdi:format-quote-open",
  "mdi:typewriter",
  "mdi:fountain-pen-tip",
  // Media
  "mdi:image-outline",
  "mdi:image-multiple-outline",
  "mdi:video-outline",
  "mdi:music-note-outline",
  "mdi:camera-outline",
  "mdi:headphones",
  "mdi:microphone-outline",
  "mdi:podcast",
  "mdi:youtube",
  "mdi:play-circle-outline",
  "mdi:television-play",
  // Commerce & products
  "mdi:cart-outline",
  "mdi:store-outline",
  "mdi:tag-outline",
  "mdi:tag-multiple-outline",
  "mdi:currency-usd",
  "mdi:barcode-scan",
  "mdi:gift-outline",
  "mdi:truck-outline",
  "mdi:receipt-outline",
  "mdi:wallet-outline",
  "mdi:credit-card-outline",
  // People & organisation
  "mdi:account-outline",
  "mdi:account-group-outline",
  "mdi:account-circle-outline",
  "mdi:account-box-outline",
  "mdi:contacts-outline",
  "mdi:office-building-outline",
  "mdi:briefcase-outline",
  "mdi:domain",
  "mdi:sitemap-outline",
  // Calendar & time
  "mdi:calendar-outline",
  "mdi:calendar-month-outline",
  "mdi:clock-outline",
  "mdi:alarm",
  "mdi:timer-outline",
  "mdi:history",
  // Location & travel
  "mdi:map-marker-outline",
  "mdi:compass-outline",
  "mdi:map-outline",
  "mdi:earth",
  "mdi:airplane",
  "mdi:navigation-outline",
  // Technology
  "mdi:code-tags",
  "mdi:api",
  "mdi:database-outline",
  "mdi:server-outline",
  "mdi:cloud-outline",
  "mdi:wifi",
  "mdi:shield-outline",
  "mdi:bug-outline",
  "mdi:memory",
  // Communication
  "mdi:email-outline",
  "mdi:chat-outline",
  "mdi:phone-outline",
  "mdi:comment-outline",
  "mdi:message-outline",
  "mdi:forum-outline",
  "mdi:bullhorn-outline",
  // Misc / UI
  "mdi:star-outline",
  "mdi:heart-outline",
  "mdi:bookmark-outline",
  "mdi:link-variant",
  "mdi:cog-outline",
  "mdi:bell-outline",
  "mdi:home-outline",
  "mdi:magnify",
  "mdi:information-outline",
  "mdi:help-circle-outline",
  "mdi:flag-outline",
  "mdi:trophy-outline",
  "mdi:lightning-bolt-outline",
  "mdi:fire",
  "mdi:chart-bar",
  "mdi:chart-line",
  "mdi:palette-outline",
  "mdi:brush-outline",
  "mdi:puzzle-outline",
  "mdi:tools",
  "mdi:folder-outline",
  "mdi:folder-multiple-outline",
  "mdi:layers-outline",
  "mdi:view-grid-outline",
  "mdi:table-large",
];

// ── state ─────────────────────────────────────────────────────────────────────

const containerRef = ref(null);
const triggerRef = ref(null);
const searchInputRef = ref(null);
const isOpen = ref(false);
const query = ref("");

function iconLabel(icon) {
  return icon
    .replace(/^[^:]+:/, "")
    .replace(/[-_]+/g, " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

const filteredIcons = computed(() => {
  const q = query.value.trim().toLowerCase();
  const icons = props.icons ?? ALL_ICONS;
  if (!q) return icons;
  return icons.filter((icon) =>
    icon.toLowerCase().includes(q) || iconLabel(icon).toLowerCase().includes(q),
  );
});

// ── open / close ──────────────────────────────────────────────────────────────

function toggle() {
  isOpen.value ? close() : open();
}

async function open() {
  if (props.disabled) return;
  isOpen.value = true;
  query.value = "";
  await nextTick();
  searchInputRef.value?.focus();
}

function close(restoreFocus = false) {
  isOpen.value = false;
  query.value = "";
  if (restoreFocus) triggerRef.value?.focus();
}

function onEscape(event) {
  if (!isOpen.value) return;
  event.stopPropagation();
  event.preventDefault();
  close(true);
}

function select(icon) {
  emit("update:modelValue", icon);
  close(true);
}

// ── click-outside ─────────────────────────────────────────────────────────────

function onClickOutside(e) {
  if (containerRef.value && !containerRef.value.contains(e.target)) {
    close();
  }
}

onMounted(() => document.addEventListener("mousedown", onClickOutside));
onBeforeUnmount(() =>
  document.removeEventListener("mousedown", onClickOutside),
);
</script>
