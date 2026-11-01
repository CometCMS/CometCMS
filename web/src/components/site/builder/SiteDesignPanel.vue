<template>
  <div :class="layout === 'page' ? 'card' : 'flex h-full flex-col'">
    <div v-if="showHeader" class="relative z-10 flex shrink-0 items-center gap-2 border-b border-slate-200" :class="layout === 'page' ? 'px-6 py-4' : 'px-4 py-3'">
      <Icon icon="mdi:palette-outline" class="h-5 w-5 shrink-0 text-theme-600" />
      <div class="min-w-0 flex-1">
        <div :class="layout === 'sidebar' ? 'flex min-w-0 items-center gap-1' : ''">
          <h2 :class="layout === 'page' ? 'card-title' : 'truncate text-sm font-semibold text-slate-900'">{{ t("site.builder.designSettings") }}</h2>
          <BuilderHelp v-if="layout === 'sidebar'" :label="`${t('site.builder.designSettings')} — ${t('site.builder.help')}`" :hints="[t('site.builder.designSettingsHint')]" />
        </div>
        <p v-if="layout === 'page'" class="card-description">{{ t("site.builder.designSettingsHint") }}</p>
      </div>
      <button v-if="layout === 'sidebar'" type="button" class="panel-action" :title="t('site.builder.closeSettings')" @click="emit('close')">
        <Icon icon="mdi:close" class="h-4 w-4" />
        <span class="sr-only">{{ t("site.builder.closeSettings") }}</span>
      </button>
    </div>

    <p v-if="!theme && site.error" role="alert" class="m-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ site.error }}</p>
    <div v-else-if="!theme" class="flex flex-1 items-center justify-center"><LoadingSpinner /></div>

    <div v-else class="space-y-5" :class="layout === 'page' ? 'p-6' : 'flex-1 overflow-y-auto p-3'">
      <BuilderGroup v-model:open="expanded.presets" icon="mdi:view-dashboard-outline" :title="t('site.theme.presets')" content-class="mt-2 space-y-3">
        <div v-if="savedPresets.length" class="space-y-2">
          <h4 class="px-1 text-xs font-semibold text-slate-700">{{ t("site.theme.savedPresets") }}</h4>
          <div class="grid grid-cols-[repeat(auto-fill,minmax(6rem,1fr))] gap-2">
            <div v-for="preset in savedPresets" :key="preset.id" class="group relative">
              <button
                type="button"
                class="w-full rounded-lg border border-slate-200 p-2 text-left transition hover:border-theme-400 hover:shadow-sm"
                :title="preset.name"
                @click="applySavedPreset(preset)"
              >
                <span class="flex h-6 overflow-hidden rounded-md ring-1 ring-black/5">
                  <span v-for="(color, index) in presetSwatches(preset)" :key="index" class="flex-1" :style="{ background: color }"></span>
                </span>
                <span class="mt-1.5 block truncate pr-4 text-xs font-medium text-slate-700">{{ preset.name }}</span>
              </button>
              <button
                type="button"
                class="absolute bottom-1.5 right-1 rounded p-0.5 text-slate-400 opacity-0 transition hover:bg-red-50 hover:text-red-600 focus:opacity-100 group-hover:opacity-100"
                :aria-label="t('site.theme.deletePresetNamed', { name: preset.name })"
                :title="t('common.delete')"
                @click="deleteTarget = preset"
              >
                <Icon icon="mdi:trash-can-outline" class="h-3.5 w-3.5" />
              </button>
            </div>
          </div>
          <h4 class="px-1 pt-1 text-xs font-semibold text-slate-700">{{ t("site.theme.builtInPresets") }}</h4>
        </div>
        <div class="grid grid-cols-[repeat(auto-fill,minmax(6rem,1fr))] gap-2">
          <button
            v-for="preset in PRESETS"
            :key="preset.key"
            type="button"
            class="rounded-lg border border-slate-200 p-2 text-left transition hover:border-theme-400 hover:shadow-sm"
            @click="applyPreset(preset)"
          >
            <span class="flex h-6 overflow-hidden rounded-md ring-1 ring-black/5">
              <span v-for="color in presetSwatches(preset)" :key="color" class="flex-1" :style="{ background: color }"></span>
            </span>
            <span class="mt-1.5 block truncate text-xs font-medium text-slate-700">{{ t(`site.theme.preset.${preset.key}`) }}</span>
          </button>
        </div>
      </BuilderGroup>

      <BuilderGroup v-model:open="expanded.colors" icon="mdi:palette-outline" :title="t('site.theme.colors')" content-class="mt-2 space-y-4 px-1">
        <SegmentedControl
          v-model="theme.mode"
          :options="['light', 'dark', 'auto'].map((mode) => ({ value: mode, label: t(`site.theme.mode.${mode}`) }))"
        />

        <div v-for="palette in palettes" :key="palette.key">
          <p v-if="palettes.length > 1" class="mb-2 text-xs font-semibold text-slate-600">
            {{ t(`site.theme.palette.${palette.key}`) }}
          </p>
          <div class="grid grid-cols-[repeat(auto-fill,minmax(8rem,1fr))] gap-3">
            <label v-for="key in COLOR_KEYS" :key="key" class="flex items-center gap-2">
              <input
                type="color"
                class="h-8 w-8 shrink-0 cursor-pointer rounded-md border border-slate-300 bg-white p-0.5"
                :value="theme[palette.key][key]"
                @input="theme[palette.key][key] = $event.target.value"
              />
              <span class="min-w-0">
                <span class="block truncate text-xs font-medium text-slate-700">{{ t(`site.theme.color.${key}`) }}</span>
                <input
                  type="text"
                  class="w-full border-0 bg-transparent p-0 font-mono text-xs text-slate-500 focus:ring-0"
                  :value="theme[palette.key][key]"
                  maxlength="7"
                  @change="setHex(palette.key, key, $event.target.value)"
                />
              </span>
            </label>
          </div>
        </div>

        <div class="space-y-3 border-t border-slate-100 pt-4">
          <div class="flex items-center justify-between gap-2">
            <h4 class="text-xs font-semibold text-slate-700">{{ t("site.theme.customColors") }}</h4>
            <button type="button" class="btn-secondary btn-sm" :disabled="Object.keys(theme.custom_colors ?? {}).length >= MAX_CUSTOM_COLORS" @click="addCustomColor">
              <Icon icon="mdi:plus" class="h-4 w-4" />
              {{ t("site.theme.addColor") }}
            </button>
          </div>
          <p class="text-xs text-slate-500">{{ t("site.theme.customColorsHint") }}</p>
          <div v-for="(color, key) in theme.custom_colors" :key="key" class="space-y-2 rounded-lg border border-slate-200 p-3">
            <div class="flex items-center gap-2">
              <input v-model="color.label" type="text" maxlength="80" :aria-label="t('site.theme.colorName')" class="form-input min-w-0 flex-1 rounded-lg border-slate-300 py-1 text-xs" />
              <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="t('select.remove', { name: color.label })" @click="delete theme.custom_colors[key]">
                <Icon icon="mdi:trash-can-outline" class="h-4 w-4" />
              </button>
            </div>
            <div class="flex flex-wrap gap-3">
              <label v-for="palette in palettes" :key="palette.key" class="flex min-w-[7rem] flex-1 items-center gap-2">
                <input type="color" class="h-8 w-8 shrink-0 cursor-pointer rounded-md border border-slate-300 bg-white p-0.5" :aria-label="`${color.label} (${t(`site.theme.palette.${palette.key}`)})`" :value="color[customColorKey(palette.key)]" @input="color[customColorKey(palette.key)] = $event.target.value" />
                <span class="min-w-0">
                  <span class="block text-[10px] text-slate-500">{{ t(`site.theme.palette.${palette.key}`) }}</span>
                  <input type="text" maxlength="7" class="w-full border-0 bg-transparent p-0 font-mono text-xs text-slate-500 focus:ring-0" :aria-label="`${color.label} (${t(`site.theme.palette.${palette.key}`)})`" :value="color[customColorKey(palette.key)]" @change="setCustomHex(key, palette.key, $event.target.value)" />
                </span>
              </label>
            </div>
          </div>
        </div>
      </BuilderGroup>

      <BuilderGroup v-model:open="expanded.typography" icon="mdi:format-font" :title="t('site.theme.typography')" content-class="mt-2 space-y-4 px-1">
        <button type="button" class="btn-secondary btn-sm w-full" @click="fontManagerOpen = true"><Icon icon="mdi:format-font" class="h-4 w-4" />{{ t('site.fonts.manage') }}</button>
        <FontManager v-model="fontManagerOpen" :draft-nodes="draftNodes" />
        <div>
          <label class="form-label" for="design-font-body">{{ t("site.theme.bodyFont") }}</label>
          <FontPicker id="design-font-body" v-model="theme.fonts.body" />
        </div>
        <RangeRow v-model="theme.base_size" :label="t('site.theme.baseSize')" :min="12" :max="24" :step="1" unit="px" />
        <RangeRow v-model="theme.line_height" :label="t('site.theme.lineHeight')" :min="1.1" :max="2.2" :step="0.05" />
      </BuilderGroup>

      <BuilderGroup v-if="theme.links" v-model:open="expanded.links" icon="mdi:link-variant" :title="t('site.theme.links')" content-class="mt-2 px-1">
        <ThemeLinkSettings :links="theme.links" :colors="themeColors" :hover-ms="theme.motion?.hover ?? 200" />
      </BuilderGroup>

      <BuilderGroup v-model:open="expanded.headings" icon="mdi:format-header-pound" :title="t('site.theme.headings')" content-class="mt-2 px-1">
        <ThemeHeadingsSettings />
      </BuilderGroup>

      <BuilderGroup v-model:open="expanded.layout" icon="mdi:view-quilt-outline" :title="t('site.theme.layout')" content-class="mt-2 space-y-4 px-1">
        <RangeRow v-model="theme.container_width" :label="t('site.theme.containerWidth')" :min="720" :max="1920" :step="20" unit="px" />
        <RangeRow v-model="theme.narrow_width" :label="t('site.theme.narrowWidth')" :min="480" :max="1200" :step="20" unit="px" />
        <div class="flex items-center justify-between gap-2">
          <span class="text-xs text-slate-500">{{ t("site.theme.paddingDevice") }}</span>
          <DeviceToggle v-model="paddingDevice" />
        </div>
        <div class="relative h-60">
          <div
            v-for="kind in ['section', 'grid', 'column']"
            :key="kind"
            role="group"
            :aria-label="t(`site.theme.${kind}Padding`)"
            class="absolute rounded-md border border-slate-200"
            :class="paddingBoxClasses[kind]"
          >
            <span class="pointer-events-none absolute left-[9px] top-[7px] text-[10px] leading-[14px] text-slate-500">{{ t(`site.kind.${kind === 'grid' ? 'row' : kind}`) }}</span>
            <SpacingTokenSelect
              :key="paddingDevice"
              v-model="theme[`${kind}_padding${paddingDevice === 'desktop' ? '' : `_${paddingDevice}`}`]"
              :label="t(`site.theme.${kind}Padding`)"
              :options="paddingOptions"
              :empty-label="paddingDevice === 'desktop' ? '' : t(`site.theme.paddingInherit.${paddingDevice}`)"
              empty-short-label="="
              class="absolute right-1 top-0.5"
            />
            <div v-if="kind === 'column'" class="absolute inset-x-4 bottom-4 top-11 rounded border border-slate-200 bg-[rgb(var(--color-surface))]" aria-hidden="true"></div>
          </div>
        </div>
      </BuilderGroup>

      <BuilderGroup v-model:open="expanded.spacing" icon="mdi:arrow-expand-horizontal" :title="t('site.theme.spacingScale')" content-class="mt-2 space-y-4 px-1">
        <p class="text-xs text-slate-500">{{ t("site.theme.spacingScaleHint") }}</p>
        <SegmentedControl
          :model-value="spacingPreset"
          :options="Object.keys(SPACING_PRESETS).map((value) => ({ value, label: t(`site.theme.spacingOption.${value}`) }))"
          @update:model-value="applySpacingPreset"
        />
        <div class="space-y-2">
          <div v-for="token in SPACE_TOKENS" :key="token" class="grid grid-cols-[2.25rem_minmax(0,1fr)_4.5rem] items-center gap-2">
            <span class="text-xs font-semibold text-slate-600">{{ TOKEN_LABELS[token] }}</span>
            <div class="flex h-5 items-center">
              <span class="h-3 rounded-sm bg-theme-300 transition-all" :style="{ width: `${Math.min(100, (theme.space[token] / 160) * 100)}%`, minWidth: '2px' }"></span>
            </div>
            <TokenInput v-model="theme.space[token]" :max="400" :label="TOKEN_LABELS[token]" />
          </div>
        </div>
      </BuilderGroup>

      <BuilderGroup v-model:open="expanded.corners" icon="mdi:rounded-corner" :title="t('site.theme.corners')" content-class="mt-2 space-y-4 px-1">
        <p class="text-xs text-slate-500">{{ t("site.theme.cornersHint") }}</p>
        <div>
          <span class="form-label">{{ t("site.theme.defaultCorner") }}</span>
          <SegmentedControl
            v-model="theme.radius"
            :options="['none', 'sm', 'md', 'lg', 'full'].map((value) => ({ value, label: value === 'none' ? '0' : value === 'full' ? t('site.theme.round') : TOKEN_LABELS[value] }))"
          />
        </div>
        <div class="grid grid-cols-3 gap-2">
          <div v-for="token in RADIUS_TOKENS" :key="token" class="space-y-2 text-center">
            <div class="mx-auto h-10 w-10 border-2 border-theme-400 bg-theme-50" :style="{ borderRadius: `${theme.radii[token]}px` }"></div>
            <TokenInput v-model="theme.radii[token]" :max="64" :label="TOKEN_LABELS[token]" :prefix="TOKEN_LABELS[token]" />
          </div>
        </div>
      </BuilderGroup>

      <BuilderGroup v-model:open="expanded.shadows" icon="mdi:box-shadow" :title="t('site.theme.shadows')" content-class="mt-2 space-y-4 px-1">
        <ThemeShadowsSettings :theme="theme" :colors="themeColors" />
      </BuilderGroup>

      <BuilderGroup v-model:open="expanded.motion" icon="mdi:animation-outline" :title="t('site.theme.motion')" content-class="mt-2 space-y-4 px-1">
        <ThemeMotionSettings :motion="theme.motion" />
      </BuilderGroup>

      <div class="space-y-2">
        <button type="button" class="btn-secondary w-full justify-center" :disabled="savedPresets.length >= MAX_THEME_PRESETS" @click="saveOpen = true">
          <Icon icon="mdi:content-save-outline" class="h-4 w-4" />
          {{ t("site.theme.savePreset") }}
        </button>
        <button type="button" class="btn-secondary w-full justify-center" @click="resetTheme">
          <Icon icon="mdi:restore" class="h-4 w-4" />
          {{ t("site.theme.reset") }}
        </button>
      </div>

      <ComponentNameModal
        v-model="saveOpen"
        icon="mdi:palette-outline"
        :title="t('site.theme.savePreset')"
        :message="t('site.theme.savePresetHint')"
        :label="t('site.theme.presetName')"
        :placeholder="t('site.theme.presetNamePlaceholder')"
        :confirm-label="t('site.builder.save')"
        :loading="busy"
        @confirm="savePreset"
      />
      <ConfirmModal
        :model-value="deleteTarget !== null"
        :title="t('site.theme.deletePresetNamed', { name: deleteTarget?.name ?? '' })"
        :message="t('site.theme.deletePresetMessage')"
        :confirm-label="t('common.delete')"
        :loading="busy"
        @update:model-value="(open) => { if (!open) deleteTarget = null }"
        @confirm="removePreset"
      />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from "vue";
import { Icon } from "@iconify/vue";
import BuilderGroup from "./BuilderGroup.vue";
import BuilderHelp from "./BuilderHelp.vue";
import ConfirmModal from "../../ConfirmModal.vue";
import LoadingSpinner from "../../LoadingSpinner.vue";
import RangeRow from "../RangeRow.vue";
import SegmentedControl from "../SegmentedControl.vue";
import DeviceToggle from "../DeviceToggle.vue";
import SpacingTokenSelect from "./SpacingTokenSelect.vue";
import ThemeHeadingsSettings from "../ThemeHeadingsSettings.vue";
import TokenInput from "../TokenInput.vue";
import FontPicker from "../FontPicker.vue";
import FontManager from "../FontManager.vue";
import ComponentNameModal from "../ComponentNameModal.vue";
import ThemeShadowsSettings from "./ThemeShadowsSettings.vue";
import ThemeMotionSettings from "./ThemeMotionSettings.vue";
import ThemeLinkSettings from "./ThemeLinkSettings.vue";
import { useSiteStore } from "../../../stores/site.js";
import { useToastStore } from "../../../stores/toast.js";
import { useI18n } from "../../../i18n/index.js";
import { MAX_CUSTOM_COLORS, themeColorOptions } from "../../../composables/themeColors.js";
import { newId } from "../../../composables/layoutTree.js";

/** Global design of the website, shared by the backend settings and builder sidebar. */
defineProps({
  showHeader: { type: Boolean, default: true },
  layout: { type: String, default: "sidebar", validator: (value) => ["sidebar", "page"].includes(value) },
  draftNodes: { type: Array, default: () => [] },
});
const emit = defineEmits(["close"]);
const fontManagerOpen = ref(false);

const SPACE_TOKENS = ["xs", "sm", "md", "lg", "xl", "2xl"];
const RADIUS_TOKENS = ["sm", "md", "lg"];
const TOKEN_LABELS = { xs: "XS", sm: "S", md: "M", lg: "L", xl: "XL", "2xl": "2XL" };
const SPACING_PRESETS = { compact: 0.8, normal: 1, relaxed: 1.25 };
/** Matches SiteRepository::MAX_THEME_PRESETS. */
const MAX_THEME_PRESETS = 50;

const COLOR_KEYS = ["primary", "secondary", "accent", "background", "surface", "text", "muted", "border"];

const PRESETS = [
  { key: "clean", theme: {} },
  {
    key: "warm",
    theme: {
      colors: { primary: "#c2410c", secondary: "#292524", accent: "#0d9488", background: "#fffbf5", surface: "#f5efe6", text: "#1c1917", muted: "#78716c", border: "#e7e0d6" },
      fonts: { heading: "old-style", body: "humanist" },
      radius: "lg",
    },
  },
  {
    key: "editorial",
    theme: {
      colors: { primary: "#b91c1c", secondary: "#111827", accent: "#1d4ed8", background: "#ffffff", surface: "#f6f6f4", text: "#111111", muted: "#6b6b6b", border: "#e5e5e5" },
      fonts: { heading: "didone", body: "transitional" },
      radius: "none",
      spacingFactor: 1.25,
      type_scale: "1.333",
    },
  },
  {
    key: "forest",
    theme: {
      colors: { primary: "#15803d", secondary: "#14532d", accent: "#ca8a04", background: "#fbfdf9", surface: "#eef5ea", text: "#10200f", muted: "#5b6b58", border: "#dbe7d4" },
      fonts: { heading: "slab", body: "humanist" },
    },
  },
  {
    key: "midnight",
    theme: {
      mode: "dark",
      dark_colors: { primary: "#a78bfa", secondary: "#e9e5ff", accent: "#f472b6", background: "#0f0d1a", surface: "#1a1730", text: "#ede9fe", muted: "#a1a1c2", border: "#2a2545" },
      fonts: { heading: "geometric", body: "system" },
      radius: "lg",
      radii: { sm: 6, md: 12, lg: 20 },
    },
  },
  {
    key: "studio",
    theme: {
      mode: "auto",
      colors: { primary: "#0f172a", secondary: "#334155", accent: "#06b6d4", background: "#ffffff", surface: "#f8fafc", text: "#0f172a", muted: "#64748b", border: "#e2e8f0" },
      fonts: { heading: "neo-grotesque", body: "neo-grotesque" },
      heading_weight: 800,
      radius: "sm",
    },
  },
];

const site = useSiteStore();
const toast = useToastStore();
const { t } = useI18n();
const theme = computed(() => site.draft?.theme ?? null);
const savedPresets = computed(() => site.data?.theme_presets ?? []);
const saveOpen = ref(false);
/** Which screen size the default padding controls edit; tablet and mobile may inherit the larger one. */
const paddingDevice = ref("desktop");
const paddingOptions = computed(() => [
  ...(paddingDevice.value === "desktop" ? [] : [""]),
  "none", ...SPACE_TOKENS,
]);
const paddingBoxClasses = {
  section: "inset-0 rounded-[10px] bg-slate-100/75",
  grid: "inset-x-5 bottom-5 top-12 border-dashed border-slate-300 bg-[rgb(var(--color-surface))]",
  column: "inset-x-10 bottom-10 top-24 bg-slate-50",
};
const deleteTarget = ref(null);
const busy = ref(false);
const expanded = ref({ presets: false, colors: false, typography: false, headings: false, links: false, layout: false, spacing: false, corners: false, shadows: false, motion: false });

const palettes = computed(() => {
  if (theme.value.mode === "dark") return [{ key: "dark_colors" }];
  if (theme.value.mode === "auto") return [{ key: "colors" }, { key: "dark_colors" }];
  return [{ key: "colors" }];
});

const themeColors = computed(() => themeColorOptions(theme.value, COLOR_KEYS, (key) => t(`site.theme.color.${key}`)));

function customColorKey(palette) {
  return palette === "dark_colors" ? "dark_color" : "color";
}

function presetSwatches(preset) {
  const defaults = site.data.theme_defaults;
  const key = preset.theme.mode === "dark" ? "dark_colors" : "colors";
  const colors = { ...defaults[key], ...(preset.theme[key] ?? {}) };
  return [colors.background, colors.surface, colors.primary, colors.accent, colors.text];
}

function applyPreset(preset) {
  const defaults = JSON.parse(JSON.stringify(site.data.theme_defaults));
  const { spacingFactor = 1, ...presetTheme } = preset.theme;
  const next = {
    ...defaults,
    ...presetTheme,
    colors: { ...defaults.colors, ...(preset.theme.colors ?? {}) },
    dark_colors: { ...defaults.dark_colors, ...(preset.theme.dark_colors ?? {}) },
    fonts: { ...defaults.fonts, ...(preset.theme.fonts ?? {}) },
    radii: { ...defaults.radii, ...(preset.theme.radii ?? {}) },
    space: scaledSpace(spacingFactor),
    custom_css: theme.value.custom_css,
    custom_colors: theme.value.custom_colors ?? {},
    shadows: theme.value.shadows ?? {},
  };
  site.draft.theme = { ...theme.value, ...next };
}

/**
 * A saved design replaces the current one, except custom CSS. Custom colors
 * and shadows are merged so elements using ones made after saving keep them.
 */
function applySavedPreset(preset) {
  const saved = JSON.parse(JSON.stringify(preset.theme));
  site.draft.theme = {
    ...theme.value,
    ...saved,
    custom_colors: { ...(theme.value.custom_colors ?? {}), ...(saved.custom_colors ?? {}) },
    shadows: { ...(theme.value.shadows ?? {}), ...(saved.shadows ?? {}) },
    custom_css: theme.value.custom_css,
  };
}

async function savePreset(name) {
  busy.value = true;
  try {
    await site.createThemePreset(name);
    saveOpen.value = false;
    expanded.value.presets = true;
    toast.success(t("site.theme.presetSaved", { name }));
  } catch (err) {
    toast.error(err.message);
  } finally {
    busy.value = false;
  }
}

async function removePreset() {
  if (!deleteTarget.value) return;
  busy.value = true;
  try {
    await site.deleteThemePreset(deleteTarget.value.id);
    deleteTarget.value = null;
  } catch (err) {
    toast.error(err.message);
  } finally {
    busy.value = false;
  }
}

function scaledSpace(factor) {
  const base = site.data.theme_defaults.space;
  return Object.fromEntries(SPACE_TOKENS.map((token) => [token, Math.round(base[token] * factor)]));
}

/** The density preset the current scale matches, if any. */
const spacingPreset = computed(() =>
  Object.keys(SPACING_PRESETS).find((key) =>
    SPACE_TOKENS.every((token) => scaledSpace(SPACING_PRESETS[key])[token] === theme.value.space[token]),
  ) ?? "",
);

function applySpacingPreset(key) {
  theme.value.space = scaledSpace(SPACING_PRESETS[key]);
}

function resetTheme() {
  site.draft.theme = { ...JSON.parse(JSON.stringify(site.data.theme_defaults)), custom_css: theme.value.custom_css, custom_colors: theme.value.custom_colors ?? {}, shadows: theme.value.shadows ?? {}, updated_at: theme.value.updated_at };
}

function addCustomColor() {
  const colors = theme.value.custom_colors ?? {};
  if (Object.keys(colors).length >= MAX_CUSTOM_COLORS) return;
  const key = `custom-${newId()}`;
  theme.value.custom_colors = {
    ...colors,
    [key]: { label: t("site.theme.customColorName", { count: Object.keys(colors).length + 1 }), color: "#2563eb", dark_color: "#60a5fa" },
  };
}

function setCustomHex(key, palette, value) {
  const hex = String(value).trim().toLowerCase();
  if (/^#[0-9a-f]{6}$/.test(hex)) theme.value.custom_colors[key][customColorKey(palette)] = hex;
}

function setHex(palette, key, value) {
  const hex = String(value).trim().toLowerCase();
  if (/^#[0-9a-f]{6}$/.test(hex)) theme.value[palette][key] = hex;
}
</script>

<style scoped>
.panel-action {
  display: inline-flex;
  height: 1.75rem;
  width: 1.75rem;
  align-items: center;
  justify-content: center;
  border-radius: 0.375rem;
  color: rgb(var(--color-slate-500));
}
.panel-action:hover {
  background: rgb(var(--color-slate-100));
  color: rgb(var(--color-slate-900));
}
</style>
