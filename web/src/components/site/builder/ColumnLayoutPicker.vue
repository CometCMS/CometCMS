<template>
  <section class="space-y-2.5" :class="compact ? '' : 'rounded-xl border border-slate-200 bg-slate-50/70 p-3'">
    <div v-if="!compact" class="flex items-center justify-between gap-2">
      <h4 class="text-xs font-semibold text-slate-700">{{ title }}</h4>
    </div>

    <!-- Direction: side by side or underneath each other -->
    <div v-if="layout.spans.length > 1" class="grid grid-cols-2 gap-1.5">
      <button
        v-for="option in directions"
        :key="option.value"
        type="button"
        class="flex items-center justify-center gap-1.5 rounded-lg border px-2 py-1.5 text-xs font-medium transition-colors"
        :class="direction === option.value ? 'border-theme-400 bg-white text-theme-700 shadow-sm' : 'border-slate-200 bg-slate-50 text-slate-600 hover:border-slate-300'"
        :aria-pressed="direction === option.value"
        @click="setDirection(option.value)"
      >
        <Icon :icon="option.icon" class="h-4 w-4" />
        {{ option.label }}
      </button>
    </div>

    <!-- Column presets -->
    <div class="grid grid-cols-3 gap-1.5">
      <button
        v-for="preset in PRESETS"
        :key="preset.join('-')"
        type="button"
        class="flex flex-col items-center gap-1 rounded-lg border bg-white px-1.5 py-1.5 transition-colors"
        :class="isCurrent(preset) ? 'border-theme-500 ring-1 ring-theme-500' : 'border-slate-200 hover:border-theme-300'"
        :title="t('site.builder.columns', { count: preset.length })"
        :aria-pressed="isCurrent(preset)"
        @click="builder.setColumns(node.id, preset)"
      >
        <span class="flex h-4 w-full gap-0.5">
          <span
            v-for="(span, index) in preset"
            :key="index"
            class="rounded-sm"
            :class="isCurrent(preset) ? 'bg-theme-400' : 'bg-slate-200'"
            :style="{ flex: span }"
          ></span>
        </span>
        <span class="text-[10px] text-slate-500">{{ preset.join(" + ") }}</span>
      </button>
    </div>

    <p v-if="!compact && nested" class="text-[11px] leading-snug text-slate-500">
      {{ t("site.builder.nestedRowHint", { width: nested.width }) }}
      <strong v-if="nested.device === 'mobile' && direction === 'side'" class="font-medium text-amber-700">{{ t("site.builder.nestedRowStacked") }}</strong>
    </p>
    <p v-if="!compact" class="text-[11px] leading-snug text-slate-500">{{ t("site.builder.columnLayoutHint") }}</p>
  </section>
</template>

<script setup>
import { computed } from "vue";
import { Icon } from "@iconify/vue";
import { columnLayoutOf } from "../../../composables/layoutTree.js";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useI18n } from "../../../i18n/index.js";

const PRESETS = [[12], [6, 6], [4, 4, 4], [3, 3, 3, 3], [4, 8], [8, 4]];

const props = defineProps({
  /** A row or column; the picker edits the row it belongs to. */
  node: { type: Object, required: true },
  /** Without heading and hint, for the context menu. */
  compact: { type: Boolean, default: false },
});

const builder = useSiteBuilder();
const { t } = useI18n();

const row = computed(() => builder.columnRowFor(props.node));
const layout = computed(() => columnLayoutOf(row.value));
const title = computed(() => (row.value ? builder.nodeLabel(row.value) : t("site.builder.columnLayout")));
/** The canvas' measurement when this row sits in a column and switches by its own width. */
const nested = computed(() => (row.value && builder.nestedRow.value?.id === row.value.id ? builder.nestedRow.value : null));
const direction = computed(() => (layout.value.stacked ? "stacked" : "side"));
const directions = computed(() => [
  { value: "side", label: t("site.builder.sideBySide"), icon: "mdi:view-column-outline" },
  { value: "stacked", label: t("site.builder.stacked"), icon: "mdi:view-agenda-outline" },
]);

function isCurrent(preset) {
  return !layout.value.stacked && preset.length === layout.value.spans.length && preset.every((span, index) => span === layout.value.spans[index]);
}

/** Side by side uses equal columns for the current count. */
function setDirection(value) {
  if (value === direction.value) return;
  if (value === "stacked") {
    builder.setColumns(props.node.id, "stacked");
    return;
  }
  const count = layout.value.spans.length;
  const span = Math.max(1, Math.floor(12 / count));
  builder.setColumns(props.node.id, Array.from({ length: count }, () => span));
}
</script>
