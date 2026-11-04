<template>
  <div class="space-y-4">
    <p class="text-xs text-slate-500">{{ t("site.theme.headingsHint") }}</p>

    <div class="flex w-full rounded-lg border border-slate-200 bg-slate-50 p-0.5" role="radiogroup" :aria-label="t('site.theme.headingLevel')">
      <button
        v-for="option in TABS"
        :key="option"
        type="button"
        role="radio"
        :aria-checked="level === option"
        :title="option === 'all' ? t('site.theme.headingsAll') : isCustomized(option) ? `${option.toUpperCase()} · ${t('site.theme.headingCustomized')}` : option.toUpperCase()"
        class="relative flex min-w-0 flex-1 items-center justify-center rounded-md px-2 py-1 text-xs font-semibold transition-colors"
        :class="level === option ? 'bg-white text-theme-700 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:text-slate-900'"
        @click="level = option"
      >
        {{ option === "all" ? t("site.theme.headingsAllShort") : option.toUpperCase() }}
        <span v-if="option !== 'all' && isCustomized(option)" class="absolute right-1 top-1 h-1.5 w-1.5 rounded-full bg-theme-500" aria-hidden="true"></span>
      </button>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white px-3 py-2">
      <p class="truncate text-slate-900" :style="specimenStyle">{{ t("site.theme.headingSample") }}</p>
      <p class="mt-1 font-mono text-[10px] text-slate-400">{{ summary }}</p>
    </div>

    <div v-if="level === 'all'" class="grid grid-cols-2 gap-3">
      <div>
        <label class="form-label" :for="`${id}-all-font`">{{ t("site.theme.headingLevelFont") }}</label>
        <FontPicker :id="`${id}-all-font`" v-model="theme.fonts.heading" />
      </div>
      <div>
        <label class="form-label" :for="`${id}-all-weight`">{{ t("site.theme.headingLevelWeight") }}</label>
        <select :id="`${id}-all-weight`" v-model.number="theme.heading_weight" class="form-select w-full rounded-lg border-slate-300 text-sm">
          <option v-for="weight in [400, 500, 600, 700, 800, 900]" :key="weight" :value="weight">{{ weight }}</option>
        </select>
      </div>
      <div class="col-span-2">
        <label class="form-label" :for="`${id}-all-scale`">{{ t("site.theme.typeScale") }}</label>
        <select :id="`${id}-all-scale`" v-model="theme.type_scale" class="form-select w-full rounded-lg border-slate-300 text-sm">
          <option value="1.125">{{ t("site.theme.scale.subtle") }}</option>
          <option value="1.2">{{ t("site.theme.scale.balanced") }}</option>
          <option value="1.25">{{ t("site.theme.scale.classic") }}</option>
          <option value="1.333">{{ t("site.theme.scale.bold") }}</option>
        </select>
      </div>
      <label v-for="field in BASE_FIELDS" :key="field.key" class="block">
        <span class="form-label">{{ t(field.label) }}</span>
        <span class="flex items-center rounded-lg border border-slate-300 bg-white focus-within:border-theme-500 focus-within:ring-1 focus-within:ring-theme-500">
          <input
            type="number"
            :min="field.min"
            :max="field.max"
            :step="field.step"
            :value="theme[field.key]"
            class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm focus:ring-0"
            @input="setBase(field, $event.target.value)"
            @change="setBase(field, $event.target.value, true)"
          />
          <span v-if="field.unit" class="pr-2 text-xs text-slate-400">{{ field.unit }}</span>
        </span>
      </label>
      <div>
        <span class="form-label">{{ t("site.theme.textTransform") }}</span>
        <SegmentedControl
          v-model="theme.heading_transform"
          :options="TRANSFORMS.map(({ value, label }) => ({ value, label, title: t(`site.theme.transform.${value || 'none'}`) }))"
        />
      </div>
    </div>

    <div v-else class="grid grid-cols-2 gap-3">
      <div>
        <label class="form-label" :for="`${id}-font`">{{ t("site.theme.headingLevelFont") }}</label>
        <FontPicker :id="`${id}-font`" v-model="heading.font" allow-empty :empty-label="t('site.theme.inherit', { value: fontLabel(theme.fonts.heading) })" />
      </div>
      <div>
        <label class="form-label" :for="`${id}-weight`">{{ t("site.theme.headingLevelWeight") }}</label>
        <select
          :id="`${id}-weight`"
          :value="heading.weight ?? ''"
          class="form-select w-full rounded-lg border-slate-300 text-sm"
          @change="heading.weight = $event.target.value === '' ? null : Number($event.target.value)"
        >
          <option value="">{{ t("site.theme.inherit", { value: theme.heading_weight }) }}</option>
          <option v-for="weight in WEIGHTS" :key="weight" :value="weight">{{ weight }}</option>
        </select>
      </div>
      <label v-for="field in NUMBER_FIELDS" :key="field.key" class="block">
        <span class="form-label">{{ t(field.label) }}</span>
        <span class="flex items-center rounded-lg border border-slate-300 bg-white focus-within:border-theme-500 focus-within:ring-1 focus-within:ring-theme-500">
          <input
            type="number"
            :min="field.min"
            :max="field.max"
            :step="field.step"
            :value="heading[field.key] ?? ''"
            :placeholder="String(inherited[field.key])"
            class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm focus:ring-0"
            @input="setNumber(field, $event.target.value)"
            @change="setNumber(field, $event.target.value, true)"
          />
          <span v-if="field.unit" class="pr-2 text-xs text-slate-400">{{ field.unit }}</span>
        </span>
      </label>
      <div>
        <span class="form-label">{{ t("site.theme.textTransform") }}</span>
        <SegmentedControl
          v-model="heading.transform"
          :options="TRANSFORMS.map(({ value, label }) => ({ value, label, title: t(`site.theme.transform.${value || 'none'}`) }))"
        />
      </div>
    </div>

    <div v-if="level !== 'all'" class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-xs text-slate-500">{{ t("site.theme.headingMobileHint") }}</p>
      <button v-if="isCustomized(level)" type="button" class="btn-secondary btn-sm shrink-0" @click="resetLevel">
        <Icon icon="mdi:restore" class="h-4 w-4" />
        {{ t("site.theme.resetHeading", { level: level.toUpperCase() }) }}
      </button>
    </div>
  </div>
</template>

<script setup>
/** Per-level heading overrides of the site design (the title comes from the surrounding group). */
import { computed, ref, useId } from "vue";
import { Icon } from "@iconify/vue";
import SegmentedControl from "./SegmentedControl.vue";
import FontPicker from "./FontPicker.vue";
import { useSiteStore } from "../../stores/site.js";
import { useI18n } from "../../i18n/index.js";
import { TRANSFORMS, WEIGHTS } from "../../composables/themeControls.js";

/** Type scale step of each level, as in Theme::HEADING_LEVELS. */
const LEVELS = ["h1", "h2", "h3", "h4", "h5", "h6"];
/** "all" edits the shared heading settings every level inherits. */
const TABS = ["all", ...LEVELS];
const SCALE_STEPS = { h1: 5, h2: 4, h3: 3, h4: 2, h5: 1, h6: 0 };
/** Shared values on the "all" tab, stored as top-level theme keys. */
const BASE_FIELDS = [
  { key: "heading_line_height", label: "site.theme.lineHeight", min: 0.8, max: 2.2, step: 0.05, unit: "", fallback: 1.2 },
  { key: "heading_letter_spacing", label: "site.theme.letterSpacing", min: -0.1, max: 0.5, step: 0.01, unit: "em", fallback: -0.01 },
];
const NUMBER_FIELDS = [
  { key: "size", label: "site.theme.headingLevelSize", min: 10, max: 160, step: 1, unit: "px" },
  { key: "line_height", label: "site.theme.lineHeight", min: 0.8, max: 2.2, step: 0.05, unit: "" },
  { key: "letter_spacing", label: "site.theme.letterSpacing", min: -0.1, max: 0.5, step: 0.01, unit: "em" },
];

const site = useSiteStore();
const { t } = useI18n();
const id = `theme-heading-${useId()}`;
const level = ref("all");
const theme = computed(() => site.draft.theme);
const fontLabel = (id) => site.data.font_families?.find((font) => font.id === id)?.name ?? t(`site.font.${id}`);
/** One level's values when nothing is overridden (Theme::headingDefaults()). */
const defaults = computed(() => site.data.theme_defaults.headings.h1);
/** The "all" tab previews H2 with no overrides. */
const heading = computed(() => (level.value === "all" ? defaults.value : theme.value.headings[level.value]));

const inherited = computed(() => ({
  size: Math.round(theme.value.base_size * Number(theme.value.type_scale) ** SCALE_STEPS[level.value === "all" ? "h2" : level.value]),
  line_height: theme.value.heading_line_height ?? 1.2,
  letter_spacing: theme.value.heading_letter_spacing ?? -0.01,
}));

const effective = computed(() => ({
  font: heading.value.font || theme.value.fonts.heading,
  size: heading.value.size ?? inherited.value.size,
  weight: heading.value.weight ?? theme.value.heading_weight,
  line_height: heading.value.line_height ?? inherited.value.line_height,
  letter_spacing: heading.value.letter_spacing ?? inherited.value.letter_spacing,
  transform: heading.value.transform || theme.value.heading_transform || "none",
}));

// Capped so very large sizes still show a readable sample; the summary has the real value.
const specimenStyle = computed(() => ({
  fontFamily: site.data.font_stacks[effective.value.font] ?? "inherit",
  fontSize: `${Math.min(effective.value.size, 56)}px`,
  fontWeight: effective.value.weight,
  lineHeight: effective.value.line_height,
  letterSpacing: `${effective.value.letter_spacing}em`,
  textTransform: effective.value.transform,
}));

const summary = computed(() =>
  [`${effective.value.size}px`, effective.value.weight, `${t("site.theme.lineHeight")} ${effective.value.line_height}`, `${effective.value.letter_spacing}em`].join(" · "),
);

function isCustomized(name) {
  const values = theme.value.headings[name];
  return Object.keys(defaults.value).some((key) => values[key] !== defaults.value[key]);
}

/** Empty inputs inherit; values are clamped once the input is committed. */
function setNumber(field, raw, commit = false) {
  if (raw === "") {
    heading.value[field.key] = null;
    return;
  }
  const value = Number(raw);
  if (!Number.isFinite(value)) return;
  heading.value[field.key] = commit ? Math.min(field.max, Math.max(field.min, value)) : value;
}

function setBase(field, raw, commit = false) {
  const value = raw === "" ? field.fallback : Number(raw);
  if (!Number.isFinite(value)) return;
  theme.value[field.key] = commit ? Math.min(field.max, Math.max(field.min, value)) : value;
}

function resetLevel() {
  theme.value.headings[level.value] = { ...defaults.value };
}
</script>
