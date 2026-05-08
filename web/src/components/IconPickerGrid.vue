<template>
  <div class="relative" ref="containerRef">
    <!-- Trigger -->
    <button
      type="button"
      @click="toggle"
      class="form-input w-full text-left flex items-center gap-2 rounded-lg border-slate-300 text-sm cursor-pointer"
    >
      <Icon
        :icon="modelValue || placeholder"
        class="w-5 h-5 text-slate-600 shrink-0"
      />
      <span
        class="flex-1 truncate"
        :class="modelValue ? 'text-slate-700' : 'text-slate-400'"
      >
        {{ modelValue || placeholder }}
      </span>
      <Icon
        icon="mdi:chevron-down"
        class="w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150"
        :class="isOpen ? 'rotate-180' : ''"
      />
    </button>

    <!-- Dropdown panel -->
    <div
      v-if="isOpen"
      class="absolute z-50 left-0 mt-1 w-80 bg-white border border-slate-200 rounded-lg shadow-xl overflow-hidden"
    >
      <!-- Search -->
      <div class="p-2 border-b border-slate-100">
        <input
          ref="searchInputRef"
          v-model="query"
          type="text"
          placeholder="Search icons…"
          class="form-input w-full rounded-lg border-slate-300 text-sm"
          @keydown.escape="close"
        />
      </div>

      <!-- Icon grid -->
      <div class="p-2 max-h-64 overflow-y-auto">
        <div
          v-if="filteredIcons.length === 0"
          class="py-6 text-center text-sm text-slate-400"
        >
          No icons found
        </div>
        <div v-else class="grid grid-cols-9 gap-0.5">
          <button
            v-for="icon in filteredIcons"
            :key="icon"
            type="button"
            :title="icon"
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
      <div class="p-2 border-t border-slate-100">
        <input
          :value="modelValue"
          type="text"
          placeholder="Or type any Iconify name…"
          class="form-input w-full rounded-lg border-slate-300 text-xs"
          @input="emit('update:modelValue', $event.target.value)"
          @keydown.escape="close"
          @keydown.enter.prevent="close"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import { Icon } from "@iconify/vue";

defineProps({
  modelValue: { type: String, default: "" },
  placeholder: { type: String, default: "mdi:file-document-outline" },
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
const searchInputRef = ref(null);
const isOpen = ref(false);
const query = ref("");

const filteredIcons = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return ALL_ICONS;
  return ALL_ICONS.filter((icon) => icon.toLowerCase().includes(q));
});

// ── open / close ──────────────────────────────────────────────────────────────

function toggle() {
  isOpen.value ? close() : open();
}

async function open() {
  isOpen.value = true;
  query.value = "";
  await nextTick();
  searchInputRef.value?.focus();
}

function close() {
  isOpen.value = false;
  query.value = "";
}

function select(icon) {
  emit("update:modelValue", icon);
  close();
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
