<template>
  <div>
    <fieldset :disabled="builder.translating?.value && !def.localized && !embedded" class="min-w-0">
    <div v-if="!def.binding" class="mb-1 flex items-center justify-between gap-2">
      <div class="flex shrink-0 items-center gap-1.5">
        <label class="text-xs text-slate-500" :for="isSectionHeight ? `${inputId}-auto` : inputId">{{ label }}</label>
        <BuilderTranslationStatus v-if="def.localized && !embedded" :state="translationState" :source="sourceText" :show-source="isSecondaryLocale" :can-reset="isSecondaryLocale && translationState !== 'inherited'" @reset="resetTranslation" />
        <BuilderOverrideStatus v-if="variants" :node="node" :group="group" :name="name" />
        <slot name="status" />
      </div>
      <span v-if="hint" :id="`${inputId}-hint`" :title="hint" class="min-w-0 truncate text-[10px] text-slate-400">{{ hint }}</span>
    </div>

    <SmartValueEditor v-if="def.binding" :model-value="value" :node="node" :target="def.binding" :label="label" @update:model-value="emitValue">
      <template #label-status>
        <BuilderTranslationStatus v-if="def.localized && !embedded" :state="translationState" :source="sourceText" :show-source="isSecondaryLocale" :can-reset="isSecondaryLocale && translationState !== 'inherited'" @reset="resetTranslation" />
        <slot name="status" />
      </template>
      <template v-if="['media', 'media_list', 'link'].includes(def.binding)" #literal>
        <MediaSelect v-if="['media', 'media_list'].includes(def.binding)" :model-value="value" :multiple="def.binding === 'media_list'" :name="inputId" :compact="group === 'style' || def.binding === 'media_list'" @update:model-value="emitValue" />
        <LinkEditor v-else :model-value="value" :types="builder.types.value" :settings="linkSettings" :context="node.id" compact allow-modal @update:model-value="emitValue" />
      </template>
    </SmartValueEditor>

    <AccordionItemsControl v-else-if="def.type === 'accordion_items'" :id="inputId" :node="node" :model-value="value" @update:model-value="emitValue" />

    <TableControl v-else-if="def.type === 'table_rows'" :id="inputId" :node="node" :model-value="value" @update:model-value="emitValue" />

    <FontPicker v-else-if="def.type === 'font'" :id="inputId" :model-value="value || ''" allow-empty roles @update:model-value="emitValue" />

    <div v-else-if="def.type === 'icon'" class="flex items-center gap-1.5">
      <IconPickerGrid
        :id="inputId"
        class="min-w-0 flex-1"
        :model-value="value ?? def.default"
        :icons="(def.options ?? SITE_ICONS).filter(Boolean)"
        :allow-custom="false"
        :empty-label="t('site.option.none')"
        @update:model-value="emitValue"
      />
      <!-- An optional icon can be taken off again. -->
      <button
        v-if="def.options?.includes('') && value"
        type="button"
        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-900"
        :title="t('site.builder.removeIcon')"
        :aria-label="t('site.builder.removeIcon')"
        @click="emitValue('')"
      >
        <Icon icon="mdi:close" class="h-4 w-4" />
      </button>
    </div>

    <!-- Language switcher flags, each choice drawn as it will look -->
    <div v-else-if="isFlagChoice" class="grid grid-cols-3 gap-1.5" role="radiogroup" :aria-label="label">
      <button
        v-for="option in def.options"
        :key="option"
        type="button"
        role="radio"
        :aria-checked="value === option"
        class="flex min-w-0 flex-col items-center gap-1 rounded-lg border px-1 py-2 transition-colors"
        :class="value === option ? 'border-theme-300 bg-theme-50 text-theme-800 ring-1 ring-theme-300' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50'"
        @click="emitValue(option)"
      >
        <span class="flex h-5 items-center gap-1 text-xs font-semibold">
          <span v-if="option !== 'none' && flagSample.country" :class="['fi', `fi-${flagSample.country}`]" class="rounded-[2px] shadow-[0_0_0_1px_rgb(0_0_0_/_0.12)]" aria-hidden="true"></span>
          <span v-if="option !== 'only' || !flagSample.country" class="max-w-[4.5rem] truncate">{{ flagSample.text }}</span>
        </span>
        <span class="w-full truncate text-center text-[10px] font-medium">{{ t(`site.flag.${option}`) }}</span>
      </button>
    </div>

    <div v-else-if="isSectionHeight">
      <div :id="inputId" class="grid grid-cols-5 gap-1" role="group" :aria-label="label" :aria-describedby="`${inputId}-hint`">
        <label v-for="option in sectionHeightOptions" :key="option.value" class="min-w-0 cursor-pointer">
          <input
            :id="`${inputId}-${option.value || 'auto'}`"
            type="radio"
            :name="inputId"
            :value="option.value"
            :checked="(value ?? def.default) === option.value"
            class="peer sr-only"
            @change="emitValue(option.value)"
          />
          <span class="flex flex-col items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-1 py-2 text-slate-500 transition-colors hover:border-slate-300 hover:bg-slate-50 peer-checked:border-theme-300 peer-checked:bg-theme-50 peer-checked:text-theme-800 peer-checked:ring-1 peer-checked:ring-theme-300 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-theme-600">
            <span class="relative flex h-7 w-5 items-end overflow-hidden rounded-sm border border-current" aria-hidden="true">
              <span v-if="option.percent" class="w-full bg-current opacity-40" :style="{ height: `${option.percent}%` }"></span>
              <span v-else class="absolute inset-0 flex items-center justify-center text-xs">↕</span>
            </span>
            <span class="text-[10px] font-medium">{{ option.label }}</span>
          </span>
        </label>
      </div>
      <p :id="`${inputId}-hint`" class="mt-1.5 text-[10px] leading-relaxed text-slate-400">{{ t('site.builder.sectionHeightHint') }}</p>
    </div>

    <!-- enum -->
    <ThemeColorPicker
      v-else-if="def.type === 'color'"
      :id="inputId"
      :label="label"
      :model-value="value ?? ''"
      :options="colorOptions"
      :empty-label="group === 'props' && node.kind.startsWith('block:') ? t('site.option.none') : ''"
      @update:model-value="emitValue"
    />

    <ShadowEditor v-else-if="def.type === 'shadow'" :id="inputId" :model-value="value || ''" :text="def.text ?? false" :options="colorOptions" :presets="siteTheme?.shadows ?? {}" :scale="siteTheme?.shadow_scale ?? {}" @update:model-value="emitValue" />

    <GradientEditor v-else-if="def.type === 'gradient'" :id="inputId" :model-value="value ?? []" :options="colorOptions" @update:model-value="emitValue" />

    <ShapePicker
      v-else-if="def.picker === 'divider'"
      :options="def.options ?? []"
      :model-value="value ?? def.default"
      :label="label"
      :edge="def.edge"
      :flip="!!node.props?.[`${name}_flip`]"
      :invert="!!node.props?.[`${name}_invert`]"
      @update:model-value="emitValue"
    />

    <template v-else-if="def.type === 'enum' || def.type === 'display'">
      <div v-if="isPositionGrid" class="grid w-24 grid-cols-3 gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1" role="radiogroup" :aria-label="label">
        <button
          v-for="option in def.options"
          :key="option"
          type="button"
          role="radio"
          :aria-checked="value === option"
          :title="optionLabel(option)"
          class="flex h-6 items-center justify-center rounded transition-colors"
          :class="value === option ? 'bg-white shadow-sm ring-1 ring-slate-200' : 'hover:bg-white/70'"
          @click="emitValue(option)"
        >
          <span class="h-2 w-2 rounded-full" :class="value === option ? 'bg-theme-600' : 'bg-slate-300'"></span>
        </button>
      </div>
      <OptionBar v-else-if="enumBar" :model-value="enumValue" :options="enumBar" @update:model-value="emitValue" />
      <SegmentedControl
        v-else-if="enumOptions.length <= 4 && enumOptions.every((option) => option.label.length <= 9)"
        :model-value="enumValue"
        :options="enumOptions"
        @update:model-value="emitValue"
      />
      <select
        v-else
        :id="inputId"
        :value="enumValue"
        class="form-select w-full rounded-lg border-slate-300 py-1.5 text-xs"
        @change="emitValue($event.target.value)"
      >
        <option v-for="option in enumOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
      </select>
    </template>

    <ToggleSwitch v-else-if="def.type === 'bool'" :id="inputId" :model-value="!!value" @update:model-value="emitValue" />

    <select
      v-else-if="node.kind === 'tabs' && name === 'initial_tab'"
      :id="inputId"
      :value="Math.min(value || 1, node.children.length)"
      class="form-select w-full rounded-lg border-slate-300 py-1.5 text-xs"
      :disabled="!node.children.length"
      @change="emitNumber($event.target.value)"
    >
      <option v-for="(child, index) in node.children" :key="child.id" :value="index + 1">{{ builder.valueFor(child, 'props', 'title') || t('site.tabs.tabTitle', { number: index + 1 }) }}</option>
    </select>

    <div v-else-if="def.type === 'int' && def.control === 'slider'" class="flex items-center gap-2">
      <input
        :id="inputId"
        type="range"
        class="min-w-0 flex-1 accent-theme-600"
        :min="def.min"
        :max="def.max"
        step="1"
        :value="value ?? def.default"
        @input="emitNumber($event.target.value)"
      />
      <input
        type="number"
        class="form-input w-20 shrink-0 rounded-lg border-slate-300 py-1.5 text-xs"
        :aria-label="label"
        :min="def.min"
        :max="def.max"
        step="1"
        :value="value ?? def.default"
        @input="emitNumber($event.target.value)"
      />
    </div>

    <OptionBar
      v-else-if="def.type === 'int' && numberOptions"
      :model-value="value"
      :options="numberOptions"
      @update:model-value="emitValue"
    />

    <!-- A size in units: slider with an exact value beside it; 0 (empty) is automatic. -->
    <div v-else-if="def.type === 'int' && def.unit" class="flex items-center gap-2">
      <input
        type="range"
        class="min-w-0 flex-1 accent-theme-600"
        :min="def.min"
        :max="def.slider_max ?? def.max"
        :step="node.kind === 'icon' || def.scale ? 1 : SIZE_STEP"
        :value="value ?? 0"
        :aria-label="label"
        @input="emitNumber($event.target.value)"
      />
      <span class="relative w-20 shrink-0">
        <input
          :id="inputId"
          type="number"
          :min="def.min / (def.scale ?? 1)"
          :max="def.max / (def.scale ?? 1)"
          :step="1 / (def.scale ?? 1)"
          :value="value ? value / (def.scale ?? 1) : ''"
          :placeholder="def.scale ? '0' : t('site.builder.spanAuto')"
          class="form-input w-full rounded-lg border-slate-300 py-1.5 pr-7 text-xs"
          @change="emitNumber(($event.target.value || 0) * (def.scale ?? 1))"
        />
        <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-[10px] text-slate-400">{{ def.unit }}</span>
      </span>
    </div>

    <input
      v-else-if="def.type === 'int'"
      :id="inputId"
      type="number"
      :min="def.min"
      :max="def.max"
      :value="value"
      class="form-input w-full rounded-lg border-slate-300 py-1.5 text-xs"
      @input="emitNumber($event.target.value)"
    />

    <CssClassInput
      v-else-if="def.type === 'class'"
      :id="inputId"
      :model-value="value ?? ''"
      :options="customClasses"
      @update:model-value="emitValue"
    />

    <input
      v-else-if="['string', 'url'].includes(def.type) || (def.type === 'key' && !keyOptions)"
      :id="inputId"
      type="text"
      :value="value"
      :maxlength="def.max"
      :placeholder="extensionPlaceholder"
      class="form-input w-full rounded-lg border-slate-300 py-1.5 text-xs"
      :class="{ 'font-mono': def.type !== 'string' }"
      @input="emitValue($event.target.value)"
    />

    <textarea
      v-else-if="def.type === 'raw_html'"
      :id="inputId"
      :value="value ?? ''"
      :maxlength="def.max"
      rows="14"
      spellcheck="false"
      autocapitalize="off"
      autocorrect="off"
      class="form-textarea w-full resize-y rounded-lg border-slate-300 font-mono text-xs leading-relaxed"
      :aria-describedby="`${inputId}-hint`"
      @input="emitValue($event.target.value)"
    ></textarea>

    <textarea
      v-else-if="def.type === 'text'"
      :id="inputId"
      :value="value ?? ''"
      :maxlength="def.max"
      :placeholder="extensionPlaceholder"
      rows="4"
      class="form-textarea w-full rounded-lg border-slate-300 py-1.5 text-xs"
      @input="emitValue($event.target.value)"
    ></textarea>

    <BlockListControl v-else-if="def.type === 'list'" :node="node" :name="name" :def="def" :value="value ?? []" @update="emitValue" />

    <div v-else-if="def.type === 'html'" class="site-builder-richtext">
      <FieldInput :name="inputId" :config="{ type: 'html' }" :model-value="value" @update:model-value="emitValue" />
    </div>

    <MediaSelect v-else-if="def.type === 'media'" :model-value="value" :name="inputId" @update:model-value="emitValue" />

    <LinkEditor v-else-if="def.type === 'link'" :model-value="value" :types="builder.types.value" :settings="linkSettings" :context="node.id" compact allow-modal @update:model-value="emitValue" />

    <div v-else-if="def.type === 'spans'" class="space-y-1.5">
      <div v-for="device in devices" :key="device" class="flex items-center gap-1.5">
        <Icon :icon="DEVICE_ICONS[device]" class="h-4 w-4 shrink-0 text-slate-400" :title="t(`site.device.${device}`)" />
        <div class="flex h-6 flex-1 gap-px overflow-hidden rounded-md bg-slate-200 p-px" role="radiogroup" :aria-label="t(`site.device.${device}`)">
          <button
            v-for="span in 12"
            :key="span"
            type="button"
            role="radio"
            :aria-checked="value?.[device] === span"
            :title="t('site.builder.spanOf', { span })"
            class="flex-1 rounded-[3px] transition-colors"
            :class="spanCellClass(device, span)"
            @click="emitValue({ ...value, [device]: span })"
          ></button>
        </div>
        <button
          v-if="device !== 'mobile'"
          type="button"
          class="w-10 shrink-0 rounded-md px-1 py-0.5 text-[10px] font-semibold"
          :class="!value?.[device] ? 'bg-theme-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
          :title="t('site.builder.spanAutoHint')"
          @click="emitValue({ ...value, [device]: 0 })"
        >
          {{ t("site.builder.spanAuto") }}
        </button>
        <span v-else class="w-10 shrink-0 text-center text-[10px] font-semibold text-slate-500">{{ value?.mobile ?? 12 }}/12</span>
      </div>
    </div>

    <div v-else-if="def.type === 'set'" class="flex gap-1.5">
      <OptionBar
        multiple
        :danger="name === 'hide_on'"
        :model-value="value ?? []"
        :options="setOptions"
        @update:model-value="emitValue"
      />
      <button
        v-if="name === 'border'"
        type="button"
        class="flex w-9 shrink-0 items-center justify-center rounded-lg border transition-colors"
        :class="allSet ? 'border-theme-200 bg-white text-theme-700 shadow-sm' : 'border-slate-200 bg-slate-50 text-slate-500 hover:text-slate-900'"
        :aria-pressed="allSet"
        :title="t('site.option.all')"
        :aria-label="t('site.option.all')"
        @click="emitValue(allSet ? [] : [...def.options])"
      >
        <Icon icon="mdi:border-all-variant" class="h-4 w-4" />
      </button>
    </div>

    <ConditionsControl v-else-if="def.type === 'conditions'" :id="inputId" :label="label" :node="node" :model-value="value" @update:model-value="emitValue" />

    <ListFiltersControl v-else-if="def.type === 'list_filters'" :id="inputId" :label="label" :model-value="listValue" :type="collectionType" @update:model-value="emitValue" />

    <!-- Content types and fields a search looks through; none checked means all of them. -->
    <!-- Fields of several types sit under sticky type headings. -->
    <div v-else-if="isKeyList" :id="inputId" class="max-h-48 overflow-y-auto rounded-lg border border-slate-200 p-1" role="group" :aria-label="label">
      <GroupedList :groups="keyListGroups" :item-key="(option) => option.value" list-class="space-y-0.5">
        <template #default="{ item: option }">
          <label class="flex min-w-0 cursor-pointer items-center gap-2 rounded-md px-2 py-1 text-xs text-slate-700 hover:bg-slate-50">
            <input type="checkbox" class="form-checkbox h-3.5 w-3.5 shrink-0 rounded border-slate-300 text-theme-600" :checked="listValue.includes(option.value)" @change="toggleKey(option.value)" />
            <span class="min-w-0 flex-1 truncate" :class="{ 'text-slate-400 line-through': option.missing }">{{ option.label }}</span>
            <span v-if="option.meta" class="max-w-[45%] shrink-0 truncate text-[10px] text-slate-400" :title="option.meta">{{ option.meta }}</span>
          </label>
        </template>
      </GroupedList>
      <p v-if="!keyListOptions.length" class="px-2 py-1 text-xs text-slate-500">{{ t(def.type === 'content_types' ? 'site.search.noPageTypes' : def.type === 'sort_fields' ? 'site.builder.chooseType' : 'site.search.noFields') }}</p>
    </div>

    <select
      v-else
      :id="inputId"
      :value="value"
      class="form-select w-full rounded-lg border-slate-300 py-1.5 text-xs"
      @change="emitValue($event.target.value)"
    >
      <option v-for="option in selectOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
    <p v-if="def.type === 'form' && selectOptions.length === 1" class="mt-2 text-xs text-slate-500">
      {{ t("site.forms.noEligibleTypes") }}
    </p>
    <p v-if="extensionHelp" :id="`${inputId}-help`" class="mt-1 text-[11px] leading-snug text-slate-500">{{ extensionHelp }}</p>
    </fieldset>
  </div>
</template>

<script setup>
import { computed } from "vue";
import AccordionItemsControl from "./AccordionItemsControl.vue";
import TableControl from "./TableControl.vue";
import SmartValueEditor from "./SmartValueEditor.vue";
import ListFiltersControl from "./ListFiltersControl.vue";
import ConditionsControl from "./ConditionsControl.vue";
import GroupedList from "./GroupedList.vue";
import { sortFields } from "./listFilters.js";
import { fieldGroups } from "../../../composables/smartValues.js";
import BuilderTranslationStatus from "./BuilderTranslationStatus.vue";
import BuilderOverrideStatus from "./BuilderOverrideStatus.vue";
import { Icon } from "@iconify/vue";
import FieldInput from "../../FieldInput.vue";
import IconPickerGrid from "../../IconPickerGrid.vue";
import { SITE_ICONS } from "../../../composables/siteIcons.js";
import ToggleSwitch from "../../ToggleSwitch.vue";
import LinkEditor from "../LinkEditor.vue";
import { getActiveWorkspace } from "../../../api/index.js";
import MediaSelect from "../MediaSelect.vue";
import SegmentedControl from "../SegmentedControl.vue";
import OptionBar from "./OptionBar.vue";
import CssClassInput from "./CssClassInput.vue";
import ThemeColorPicker from "./ThemeColorPicker.vue";
import ShadowEditor from "./ShadowEditor.vue";
import GradientEditor from "./GradientEditor.vue";
import ShapePicker from "./ShapePicker.vue";
import { themeCssClasses } from "../../../composables/cssClasses.js";
import { themeColorOptions } from "../../../composables/themeColors.js";
import { DEVICE_ICONS, barOptions, numberBar } from "./controlOptions.js";
import { effectiveSpan } from "../../../composables/layoutTree.js";
import { localeFlagCountry } from "../../../composables/localeOptions.js";
import FontPicker from "../FontPicker.vue";
import BlockListControl from "./BlockListControl.vue";
import { resolveLocalized } from '../../../composables/localizedValues.js';
import { useSiteStore } from "../../../stores/site.js";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useI18n } from "../../../i18n/index.js";
import { extensionText, optionText } from "../../../composables/extensionText.js";

/** Slider step of sizes; the number field beside it takes any value. */
const SIZE_STEP = 10;
const SORTABLE_TYPES = ["text", "number", "range", "date", "datetime", "select", "boolean", "slug"];

const props = defineProps({
  node: { type: Object, required: true },
  name: { type: String, required: true },
  def: { type: Object, required: true },
  value: { default: null },
  group: { type: String, default: "props" },
  /** A field inside another control, such as a list item: the parent handles translation. */
  embedded: { type: Boolean, default: false },
  /** Devices a column width control shows, e.g. only the one previewed. */
  devices: { type: Array, default: () => ["desktop", "tablet", "mobile"] },
  /** Whether device and state values are shown; an instance's own values have none. */
  variants: { type: Boolean, default: true },
  /** Resets the shown translation instead of the node's own (an instance's own value). */
  onResetTranslation: { type: Function, default: null },
});

const emit = defineEmits(["update"]);
const builder = useSiteBuilder();
const site = useSiteStore();
const value = computed(() => resolveLocalized(props.value, builder.locale.value || builder.defaultLocale?.value || 'en', builder.defaultLocale?.value || 'en'));
const translationState = computed(() => builder.translationState?.(props.node, props.group, props.name) || 'shared');
function resetTranslation() {
  if (props.onResetTranslation) props.onResetTranslation();
  else builder.resetTranslation(props.node.id, props.group, props.name);
}
const isSecondaryLocale = computed(() => builder.locale.value !== builder.defaultLocale.value);
const sourceText = computed(() => { const value = resolveLocalized(props.value, builder.defaultLocale?.value || 'en', builder.defaultLocale?.value || 'en'); return typeof value === 'string' ? value.replace(/<[^>]*>/g, '') : JSON.stringify(value); });
const linkSettings = computed(() => site.data?.workspace === getActiveWorkspace() && site.draft ? site.draft.settings : builder.settings.value);
const { t, locale } = useI18n();
const inputId = computed(() => `builder-${props.node.id}-${props.group}-${props.name}`);
/** The design being edited, so new custom colors and classes are offered right away. */
const siteTheme = computed(() => site.draft?.theme ?? site.data?.theme ?? null);
const customClasses = computed(() => themeCssClasses(siteTheme.value?.custom_css ?? ""));
const isHoverAnimation = computed(() => props.group === "style" && props.name === "animation" && builder.styleState.value === "hover");
/** Extension props and settings bring their own texts, translated through i18n/<locale>.json. */
const extensionHelp = computed(() => (props.def.help ? extensionText(props.def, "help", locale.value) : ""));
const extensionPlaceholder = computed(() => (props.def.placeholder ? extensionText(props.def, "placeholder", locale.value) : undefined));
const label = computed(() => {
  if (props.def.type === "raw_html") return t("site.prop.htmlSource");
  if (props.def.label) return extensionText(props.def, "label", locale.value);
  if (props.node.kind === "accordion" && props.name === "items") return t("site.accordion.entries");
  if (props.def.type === "table_rows") return t("site.table.cells");
  if (isHoverAnimation.value) return t("site.style.hover_effect");
  if (props.name === "span" && builder.isStacked(props.node)) return t("site.prop.spanStacked");
  if (props.name === "text_color" && props.node.kind === "divider") return t("site.style.divider_color");
  if (props.name === "text_color" && props.node.kind === "icon") return t("site.style.icon_color");
  if (props.name === "collections" && props.node.kind === "collection" && props.node.props?.list_source === "types") return t("site.prop.listTypes");
  if (props.name === "label" && props.node.kind === "icon") return t("site.prop.iconLabel");
  if (props.name === "label" && props.node.kind === "theme_toggle") return t("site.prop.toggleLabel");
  if (props.name === "label" && props.node.kind === "modal_close") return t("site.prop.closeLabel");
  if (props.name === "icon_position" && props.node.kind === "button") return t("site.prop.buttonIconPosition");
  if (props.name === "media" && props.node.kind === "gallery") return t("site.prop.galleryImages");
  if (props.name === "media" && props.node.kind === "video") return t("site.prop.videoFile");
  return t(`site.${props.group === "style" ? "style" : "prop"}.${props.name}`);
});

const hint = computed(() => {
  if (props.def.type === "raw_html") return t("site.builder.htmlHint");
  if (props.node.kind === "video" && ["media", "poster", "video_url", "autoplay", "controls", "start_time", "fullscreen"].includes(props.name)) return t(`site.video.${props.name}Hint`);
  if (props.name === "class") return t("site.builder.classHint");
  if (props.node.kind === "table" && props.name === "mobile" && value.value === "stack" && props.node.props?.header_row === false) return t("site.table.stackHint");
  if (props.node.kind === "theme_toggle" && props.name === "label" && !value.value) return t("site.themeToggle.labelHint");
  if (props.node.kind === "modal_close" && props.name === "label" && !value.value) return t("site.modalClose.labelHint");
  if (props.name === "background_blur") return t("site.builder.backgroundBlurHint");
  if (props.name === "background_effect" && value.value && value.value !== "none") return t("site.builder.stickyHint");
  if (props.node.kind === "section" && props.name === "sticky" && value.value !== "none") return t("site.builder.stickyHint");
  if (props.node.kind === "section" && props.name === "overlap" && value.value) return t("site.builder.overlapHint");
  if (props.node.kind === "section" && props.name === "overlap_background") return t(value.value === "background" ? "site.builder.overlapBackgroundHint" : "site.builder.overlapTransparentHint");
  if (props.def.type === "sort_fields" && !listValue.value.length) return t("site.listFilter.noSorting");
  if (isKeyList.value && !listValue.value.length) return t(props.def.type === "content_types" ? "site.search.allTypes" : "site.search.allFields");
  return "";
});

const isSectionHeight = computed(() => props.node.kind === "section" && props.group === "props" && props.name === "min_height");
const sectionHeightOptions = computed(() => [
  { value: "", label: t("site.builder.spanAuto"), percent: 0 },
  { value: "quarter", label: "25%", percent: 25 },
  { value: "half", label: "50%", percent: 50 },
  { value: "three-quarter", label: "75%", percent: 75 },
  { value: "screen", label: "100%", percent: 100 },
]);

const isFlagChoice = computed(() => props.node.kind === "language_switcher" && props.name === "flag");

/** The site's main language as the switcher would show it, for the flag previews. */
const flagSample = computed(() => {
  const settings = builder.settings.value ?? {};
  const locale = settings.default_locale || settings.locales?.[0] || "en";
  let text = locale.toUpperCase();
  if (props.node.props?.format === "names") {
    try {
      const name = new Intl.DisplayNames([locale], { type: "language" }).of(locale);
      if (name) text = name.charAt(0).toUpperCase() + name.slice(1);
    } catch {
      // Unknown to the browser: keep the code.
    }
  }
  return { country: localeFlagCountry(locale), text };
});

// Space and radius props of blocks offer the Style panel's theme tokens, worded and drawn like it.
const themeStyle = computed(() => ({ space: "padding_top", radius: "radius" })[props.def.theme] ?? null);

function optionLabel(option) {
  if (props.group === "props" && props.node.kind.startsWith("block:") && !themeStyle.value) return optionText(props.def, option, locale.value);
  if (option === "") return t(props.group === "style" || themeStyle.value ? "site.option.default" : "site.option.auto");
  if (/^h[1-6]$/.test(option)) return option.toUpperCase();
  if (/^\d+:\d+$/.test(option)) return option;
  if (props.name === "opacity") return `${option}%`;
  // A property can word a shared option its own way.
  const name = themeStyle.value ?? props.name;
  const scoped = t(`site.option.${name}.${option}`);
  if (scoped !== `site.option.${name}.${option}`) return scoped;
  const key = `site.${isHoverAnimation.value ? "hoverEffect" : "option"}.${option}`;
  const translated = t(key);
  return translated === key ? option : translated;
}

const fieldConfig = computed(() => builder.fieldConfig(props.node));

const enumOptions = computed(() => {
  if (props.def.type === "display") {
    const displays = fieldConfig.value?.type === 'media' && fieldConfig.value?.multiple
      ? ['gallery', 'files']
      : builder.spec.value?.field_displays?.[fieldConfig.value?.type] ?? [];
    return displays.map((display) => ({ value: display, label: t(`site.display.${display}`) }));
  }
  return (props.def.options ?? []).map((option) => ({ value: option, label: optionLabel(option) }));
});

const colorOptions = computed(() => themeColorOptions(siteTheme.value, props.def.options ?? [], optionLabel));

const enumBar = computed(() =>
  props.def.type === "enum" && (themeStyle.value || !(props.group === "props" && props.node.kind.startsWith("block:")))
    ? barOptions(themeStyle.value ? "style" : props.group, themeStyle.value ?? props.name, props.def.options ?? [], {
        title: optionLabel,
        theme: siteTheme.value,
        kind: props.node.kind,
      })
    : null,
);
const isPositionGrid = computed(() => (props.def.options ?? []).length === 9 && props.def.options.includes("top-left"));
const numberOptions = computed(() => numberBar(props.def.min ?? 0, props.def.max ?? 0));
const setOptions = computed(() => {
  const options = props.def.options ?? [];
  if (props.name !== "hide_on") {
    return barOptions(props.group, props.name, options, { title: optionLabel }) ?? options.map((option) => ({ value: option, title: optionLabel(option), label: optionLabel(option) }));
  }
  return options.map((option) => ({
    value: option,
    icon: DEVICE_ICONS[option],
    title: t("site.builder.hideOnDevice", { device: t(`site.device.${option}`) }),
  }));
});
const allSet = computed(() => (props.def.options ?? []).every((option) => (value.value ?? []).includes(option)));

function spanCellClass(device, span) {
  const own = value.value?.[device] ?? 0;
  if (own > 0) return span <= own ? "bg-theme-500" : "bg-white hover:bg-theme-100";
  // Inherited from the smaller device: shown lighter.
  return span <= effectiveSpan(value.value, device) ? "bg-theme-200" : "bg-white hover:bg-theme-100";
}

// A field without a chosen display uses its type's first display.
const enumValue = computed(() =>
  props.def.type === "display" && !value.value ? (enumOptions.value[0]?.value ?? "") : value.value,
);

// A list of several types filters and sorts by the fields of all of them; search hits by none.
const collectionType = computed(() => {
  const source = props.node.props?.list_source ?? "type";
  if (source === "types") return builder.combinedType(builder.types.value.filter((type) => (props.node.props?.collections ?? []).includes(type.name)), t("site.builder.mixedType"));
  return source === "type" ? builder.types.value.find((type) => type.name === props.node.props?.type) ?? null : null;
});

function fieldsOfCollection(filter = () => true) {
  const fields = Object.entries(collectionType.value?.fields ?? {})
    .filter(([key, config]) => key !== "slug" && config && filter(config))
    .map(([key, config]) => ({ value: key, label: config.label || key }));
  return [
    { value: "title", label: t("site.systemField.title") },
    ...fields,
  ];
}

const keyOptions = computed(() => {
  if (props.name === "filter_field") {
    return [{ value: "", label: t("site.option.none") }, ...fieldsOfCollection((config) => config.type !== "json" && config.type !== "repeater")];
  }
  return null;
});

const selectOptions = computed(() => {
  switch (props.def.type) {
    case "content_type":
      return [
        { value: "", label: t("site.builder.chooseType") },
        ...builder.types.value
          .filter((type) => type.visibility !== "private")
          .map((type) => ({ value: type.name, label: type.label })),
      ];
    case "menu":
      return [
        ...(props.node.kind.startsWith("block:") ? [{ value: "", label: t("site.option.none") }] : []),
        ...builder.menus.value.map((menu) => ({ value: menu.name, label: menu.label })),
      ];
    case "form": {
      // Show one choice per content type, retaining existing placement IDs.
      const forms = new Map();
      for (const form of builder.forms.value) {
        if (!forms.has(form.type) || form.id === value.value) forms.set(form.type, form);
      }
      return [
        { value: "", label: t("site.forms.choose") },
        ...builder.types.value.filter((type) => forms.has(type.name))
          .map((type) => ({ value: forms.get(type.name).id, label: type.label })),
      ];
    }
    case "sort_field":
      return [
        { value: "published_at", label: t("site.systemField.published_at") },
        { value: "updated_at", label: t("site.systemField.updated_at") },
        { value: "created_at", label: t("site.systemField.created_at") },
        // Only dates mean the same in every type of a list of several.
        ...(props.node.props?.list_source === "types" ? [] : fieldsOfCollection((config) => SORTABLE_TYPES.includes(config.type))),
      ];
    case "page_field":
      return [
        { value: "", label: t("site.page.chooseField") },
        // A field another element already shows would take this element out of the layout.
        ...builder.freePageFields(props.node.id).map((field) => ({ value: field.key, label: field.label })),
      ];
    case "key":
      return keyOptions.value ?? [];
    default:
      return [];
  }
});

const SEARCHABLE_TYPES = ["text", "slug", "textarea", "markdown", "html", "select", "page"];
const isKeyList = computed(() => ["content_types", "search_fields", "sort_fields"].includes(props.def.type));
const listValue = computed(() => (Array.isArray(value.value) ? value.value : []));

/** Types with website pages, so every hit opens one; fields of the chosen types (or of all of them). */
const keyListOptions = computed(() => {
  const pageTypes = builder.pageTypes.value;
  const typeLabel = (name) => builder.types.value.find((type) => type.name === name)?.label || name;
  let options;
  if (props.def.type === "sort_fields") {
    options = collectionType.value ? sortFields(collectionType.value, t) : [];
  } else if (props.def.type === "content_types") {
    // A list of several types needs no pages; a search does, so every hit opens one.
    const listed = props.node.kind === "collection" && props.node.props?.list_source === "types" ? builder.types.value.filter((type) => type.visibility !== "private") : pageTypes;
    options = listed.map((type) => ({ value: type.name, label: type.label || type.name }));
  } else {
    options = [
      { value: "title", label: t("site.systemField.title") },
      ...Object.entries(searchedType.value?.fields ?? {})
        .filter(([key, config]) => key !== "slug" && SEARCHABLE_TYPES.includes(config?.type))
        .map(([key, config]) => ({ value: key, label: config.label || key })),
    ];
  }
  // Keep choices that no longer apply visible, so they can be unchecked.
  const known = new Set(options.map((option) => option.value));
  const missing = listValue.value.filter((key) => !known.has(key)).map((key) => ({
    value: key,
    label: props.def.type === "content_types" ? typeLabel(key) : key,
    meta: t(props.def.type === "content_types" ? "site.search.noPages" : props.def.type === "sort_fields" ? "site.listFilter.missing" : "site.search.notSearched"),
    missing: true,
  }));
  return [...options, ...missing];
});

/** The types a search list looks through (all with pages when none are chosen), as one. */
const searchedType = computed(() => {
  const chosen = props.node.props?.collections ?? [];
  const pageTypes = builder.pageTypes.value;
  return builder.combinedType(chosen.length ? pageTypes.filter((type) => chosen.includes(type.name)) : pageTypes, t("site.builder.searchResultsType"));
});

/** Fields every listed type has, then each type's own; choices that no longer apply last. */
const keyListGroups = computed(() => {
  const schema = props.def.type === "sort_fields" ? collectionType.value : props.def.type === "search_fields" ? searchedType.value : null;
  const options = keyListOptions.value.filter((option) => !option.missing).map((option) => ({ ...option, key: option.value }));
  return [
    ...fieldGroups(options, schema, t("site.builder.sharedField")),
    { key: "missing", label: t("site.builder.unavailableChoices"), items: keyListOptions.value.filter((option) => option.missing) },
  ];
});

function toggleKey(key) {
  const current = listValue.value;
  emitValue(current.includes(key) ? current.filter((item) => item !== key) : [...current, key]);
}

function emitValue(value) {
  emit("update", value);
}

function emitNumber(raw) {
  if (raw === "") return;
  const number = Math.round(Number(raw));
  if (Number.isFinite(number)) emit("update", Math.max(props.def.min ?? number, Math.min(props.def.max ?? number, number)));
}
</script>

<style scoped>
.site-builder-richtext :deep(.tiptap-content) {
  max-height: 18rem;
  overflow-y: auto;
}
</style>
