<!-- Visual drag-and-drop field editor for content types -->
<template>
  <div class="space-y-3">
    <!-- Built-in fields: shown locked so the list reflects everything the entry editor will contain. -->
    <div class="space-y-2">
      <div
        v-for="builtin in builtinFields"
        :key="builtin.key"
        class="flex items-center gap-3 rounded-lg border border-dashed border-slate-200 px-3 py-2"
        :title="t('fieldBuilder.builtinTooltip')"
      >
        <span class="shrink-0 p-1 text-slate-400">
          <Icon icon="mdi:lock-outline" class="w-4 h-4" />
        </span>
        <span class="w-4 shrink-0" aria-hidden="true"></span>
        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100">
          <Icon :icon="fieldTypeIcon(builtin.type)" class="h-4 w-4 text-slate-500" />
        </span>
        <div class="min-w-0">
          <div class="truncate text-sm font-medium text-slate-600">{{ t(builtin.labelKey) }}</div>
          <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
            <code class="font-mono bg-slate-100 px-1 py-0.5 rounded">{{ builtin.key }}</code>
            <span class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5">
              <Icon :icon="fieldTypeIcon(builtin.type)" class="h-3 w-3" />
              {{ fieldTypeLabel(builtin.type) }}
            </span>
            <span class="inline-flex rounded bg-slate-100 px-1.5 py-0.5">{{ t("fieldBuilder.builtin") }}</span>
          </div>
        </div>
      </div>
    </div>

    <TransitionGroup tag="div" name="field-sort" class="space-y-3">
      <div
        v-for="(field, index) in fields"
        :key="field._id"
        class="group/field select-none"
        :class="[
          field._collapsed
            ? 'card cursor-grab p-3'
            : 'rounded-lg border border-theme-400 bg-white shadow-sm ring-1 ring-theme-300',
          { 'ring-2 ring-theme-400': dragIndex === index },
        ]"
        draggable="true"
        @dragstart="onDragStart(index, $event)"
        @dragover.prevent="onDragOver(index, $event)"
        @dragend="onDragEnd"
      >
        <div class="flex items-center gap-3" :class="{ 'p-3': !field._collapsed }">
          <button
            type="button"
            @click.stop="toggleField(field)"
            class="shrink-0 text-slate-400 hover:text-theme-600 transition-colors p-1 rounded"
            :title="t(field._collapsed ? 'fieldBuilder.expand' : 'fieldBuilder.collapse')"
            :aria-label="t(field._collapsed ? 'fieldBuilder.expand' : 'fieldBuilder.collapse')"
            :aria-expanded="!field._collapsed"
          >
            <Icon
              icon="mdi:chevron-down"
              class="w-4 h-4 transition-transform"
              :class="{ '-rotate-90': field._collapsed }"
            />
          </button>

          <Icon
            icon="mdi:drag-vertical"
            class="w-4 h-4 text-slate-400 shrink-0"
          />
          <span
            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-theme-100"
          >
            <Icon
              :icon="fieldTypeIcon(field.type)"
              class="h-4 w-4 text-theme-600"
            />
          </span>

          <button
            type="button"
            class="flex-1 min-w-0 flex items-center gap-3 text-left"
            :tabindex="field._collapsed ? 0 : -1"
            @click.stop="onHeaderClick(field)"
          >
            <div class="min-w-0">
              <div class="truncate text-sm font-medium text-slate-800">
                {{ labelText(field.label) || field.key || t("fieldBuilder.untitled") }}
              </div>
              <div
                class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500"
              >
                <code class="font-mono bg-slate-100 px-1 py-0.5 rounded">{{
                  field.key || "field_key"
                }}</code>
                <span
                  class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5"
                  :title="field.type"
                >
                  <Icon :icon="fieldTypeIcon(field.type)" class="h-3 w-3" />
                  {{ fieldTypeLabel(field.type) }}
                </span>
                <span
                  v-if="isMultiple(field)"
                  class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5"
                  :title="t('fieldBuilder.multipleTooltip')"
                >
                  <Icon icon="mdi:layers-outline" class="h-3 w-3" />
                  {{ t("fieldBuilder.multiple") }}
                </span>
                <span
                  v-if="field.required"
                  class="inline-flex rounded bg-amber-50 px-1.5 py-0.5 text-amber-700"
                  >{{ t("fieldBuilder.required") }}</span
                >
                <span
                  v-if="field.localized === false"
                  class="inline-flex items-center gap-1 rounded bg-sky-50 px-1.5 py-0.5 text-sky-700"
                  :title="t('field.universalHint')"
                >
                  <Icon icon="mdi:web" class="h-3 w-3" />
                  {{ t("fieldBuilder.allLanguages") }}
                </span>
                <span
                  v-if="fieldLayoutWidth(field) !== 'full'"
                  class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-slate-600"
                  :title="fieldLayoutLabel(field)"
                >
                  <span
                    class="flex h-3 w-6 overflow-hidden rounded-sm bg-slate-300"
                  >
                    <span
                      class="h-full rounded-sm"
                      :style="fieldLayoutIndicatorStyle(field)"
                    ></span>
                  </span>
                  {{ fieldLayoutShortLabel(field) }}
                </span>
                <span
                  v-if="fieldErrors[index]"
                  class="inline-flex rounded bg-red-50 px-1.5 py-0.5 text-red-700"
                  >{{ fieldErrors[index] }}</span
                >
              </div>
            </div>
          </button>

          <!-- Collapsed: actions appear on hover/focus. Expanded: always visible. -->
          <div
            class="shrink-0 flex items-center gap-1 transition-opacity"
            :class="
              field._collapsed
                ? 'opacity-0 group-hover/field:opacity-100 focus-within:opacity-100'
                : ''
            "
          >
            <button
              type="button"
              @click="duplicateField(index)"
              class="text-slate-400 hover:text-theme-600 transition-colors p-1 rounded"
              :title="t('fieldBuilder.duplicate')"
              :aria-label="t('fieldBuilder.duplicate')"
            >
              <Icon icon="mdi:content-copy" class="w-4 h-4" />
            </button>
            <button
              type="button"
              @click="removeField(index)"
              class="text-slate-400 hover:text-red-500 transition-colors p-1 rounded"
              :title="t('fieldBuilder.remove')"
              :aria-label="t('fieldBuilder.remove')"
            >
              <Icon icon="mdi:close" class="w-4 h-4" />
            </button>
          </div>
        </div>

        <div v-if="!field._collapsed" class="space-y-4 border-t border-slate-200 px-5 pb-5 pt-4">
          <!-- Basics -->
          <div class="grid gap-4 lg:grid-cols-3">
            <div>
              <label :for="`field-${index}-label`" class="text-xs text-slate-500 block mb-1">{{ t("fieldBuilder.label") }}</label>
              <LocalizedInput
                :id="`field-${index}-label`"
                v-model="field.label"
                :placeholder="t('fieldBuilder.labelPlaceholder')"
                class="w-full"
                @update:model-value="onLabelInput(field)"
              />
            </div>

            <div>
              <label :for="`field-${index}-key`" class="text-xs text-slate-500 block mb-1">
                {{ t("fieldBuilder.key") }}
                <span class="text-slate-400 font-normal">{{ t("fieldBuilder.keyHint") }}</span>
              </label>
              <div class="relative">
                <input
                  :id="`field-${index}-key`"
                  v-model="field.key"
                  type="text"
                  placeholder="field_name"
                  class="form-input w-full rounded-lg border-slate-300 text-sm"
                  :class="{
                    'border-red-400':
                      !isValidKey(field.key) || fieldErrors[index],
                    'border-amber-400': isKeyRenamed(field),
                    'bg-slate-50 pr-24 text-slate-500': isKeyLocked(field),
                  }"
                  :readonly="isKeyLocked(field)"
                  :title="isKeyLocked(field) ? t('fieldBuilder.keyLocked') : undefined"
                  @blur="normalizeFieldKey(field, index)"
                  @input="onKeyInput(field)"
                />
                <button
                  v-if="isKeyLocked(field)"
                  type="button"
                  class="absolute right-1.5 top-1/2 -translate-y-1/2 inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-slate-500 transition-colors hover:bg-white hover:text-theme-600"
                  :title="t('fieldBuilder.keyLocked')"
                  @click="unlockKey(field, index)"
                >
                  <Icon icon="mdi:lock-outline" class="h-3.5 w-3.5" />
                  {{ t("fieldBuilder.keyUnlock") }}
                </button>
              </div>
              <p v-if="fieldErrors[index]" class="mt-1 text-xs text-red-600">
                {{ fieldErrors[index] }}
              </p>
              <p
                v-else-if="isKeyRenamed(field)"
                class="mt-1 text-xs text-amber-600"
              >
                <Icon
                  icon="mdi:alert-outline"
                  class="w-3 h-3 inline-block align-middle mr-0.5"
                />
                {{ t("fieldBuilder.renamedFrom") }}
                <code class="font-mono bg-amber-50 px-0.5 rounded">{{
                  field._originalKey
                }}</code>
                {{ t("fieldBuilder.renamedMigrate") }}
              </p>
            </div>

            <div>
              <label class="text-xs text-slate-500 block mb-1">{{ t("fieldBuilder.type") }}</label>
              <SearchableSelect
                :model-value="field.type"
                :aria-label="t('fieldBuilder.type')"
                :options="allowedTypeOptions(field)"
                :clearable="false"
                :searchable="true"
                @update:model-value="setFieldType(field, $event)"
              />
            </div>
          </div>

          <div class="grid gap-4 lg:grid-cols-2">
            <!-- Behavior -->
            <section class="space-y-5 rounded-lg border border-slate-200 p-4">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                {{ t("fieldBuilder.behavior") }}
              </h3>

              <p v-if="field.type === 'page'" class="text-sm text-slate-500">{{ t("fieldBuilder.pageHint") }}</p>
              <template v-else>
                <!-- Page content is optional: without it an entry shows its layout's default content. -->
                <div class="flex items-start justify-between gap-4">
                  <div>
                    <p :id="`field-${index}-required`" class="text-sm font-medium text-slate-700">
                      {{ t("fieldBuilder.required") }}
                    </p>
                    <p class="mt-0.5 text-sm text-slate-500">{{ t("fieldBuilder.requiredBody") }}</p>
                  </div>
                  <ToggleSwitch
                    v-model="field.required"
                    :aria-labelledby="`field-${index}-required`"
                  />
                </div>

                <div class="flex items-start justify-between gap-4">
                  <div>
                    <p :id="`field-${index}-universal`" class="text-sm font-medium text-slate-700">
                      {{ t("field.universalHint") }}
                    </p>
                    <p class="mt-0.5 text-sm text-slate-500">{{ t("fieldBuilder.universalBody") }}</p>
                  </div>
                  <ToggleSwitch
                    :model-value="field.localized === false"
                    :aria-labelledby="`field-${index}-universal`"
                    @update:model-value="setFieldUniversal(field, $event)"
                  />
                </div>
              </template>

              <div v-if="supportsFieldDefault(field)">
                <div class="flex items-start justify-between gap-4">
                  <div>
                    <p :id="`field-${index}-default`" class="text-sm font-medium text-slate-700">
                      {{ t("fieldBuilder.default") }}
                    </p>
                    <p class="mt-0.5 text-sm text-slate-500">{{ t("fieldBuilder.defaultBody") }}</p>
                  </div>
                  <ToggleSwitch
                    v-if="field.type !== 'range'"
                    :model-value="hasFieldDefault(field)"
                    :aria-labelledby="`field-${index}-default`"
                    @update:model-value="toggleFieldDefault(field, $event)"
                  />
                </div>

                <div v-if="hasFieldDefault(field)" class="mt-3">
                  <textarea
                    v-if="['textarea', 'markdown', 'html'].includes(field.type)"
                    :value="field.default ?? ''"
                    rows="3"
                    class="form-textarea w-full rounded-lg border-slate-300 text-sm"
                    @input="setFieldDefault(field, $event.target.value)"
                  />

                  <input
                    v-else-if="['text', 'date', 'datetime'].includes(field.type)"
                    :type="
                      field.type === 'date'
                        ? 'date'
                        : field.type === 'datetime'
                          ? 'datetime-local'
                          : 'text'
                    "
                    :value="field.default ?? ''"
                    class="form-input w-full rounded-lg border-slate-300 text-sm"
                    @input="setFieldDefault(field, $event.target.value)"
                  />

                  <input
                    v-else-if="field.type === 'number' || field.type === 'range'"
                    v-model.number="field.default"
                    type="number"
                    step="any"
                    class="form-input w-full rounded-lg border-slate-300 text-sm"
                    @input="normalizeFieldDefault(field)"
                  />

                  <label
                    v-else-if="field.type === 'boolean'"
                    class="inline-flex items-center gap-2 h-[38px]"
                  >
                    <input
                      type="checkbox"
                      :checked="field.default === true"
                      class="form-checkbox rounded border-slate-300 text-theme-600"
                      @change="setFieldDefault(field, $event.target.checked)"
                    />
                    <span class="text-sm text-slate-600">{{ t("fieldBuilder.enabled") }}</span>
                  </label>

                  <SearchableSelect
                    v-else-if="field.type === 'select' && field.multiple"
                    :model-value="selectDefaultValues(field)"
                    :options="selectDefaultOptions(field)"
                    :multiple="true"
                    :placeholder="t('fieldBuilder.selectDefaults')"
                    @update:model-value="setFieldDefault(field, $event)"
                  />

                  <select
                    v-else-if="field.type === 'select'"
                    :value="selectDefaultSingleValue(field)"
                    class="form-select w-full rounded-lg border-slate-300 text-sm"
                    @change="setFieldDefault(field, $event.target.value)"
                  >
                    <option value="">{{ t("fieldValue.select") }}</option>
                    <option
                      v-for="opt in parseSelectOptions(field._optionsText)"
                      :key="opt.key"
                      :value="opt.key"
                    >
                      {{ opt.label }}
                    </option>
                  </select>

                  <textarea
                    v-else-if="field.type === 'json'"
                    :value="field._defaultJsonText"
                    rows="4"
                    class="form-textarea w-full rounded-lg border-slate-300 text-sm font-mono"
                    @input="setJsonFieldDefault(field, $event.target.value)"
                  />

                  <div
                    v-else-if="field.type === 'color'"
                    class="flex items-center gap-2"
                  >
                    <input
                      type="color"
                      :value="validColorDefault(field.default)"
                      class="h-9 w-12 rounded-lg border border-slate-300 bg-white p-1"
                      @input="setFieldDefault(field, $event.target.value)"
                    />
                    <input
                      :value="field.default ?? ''"
                      type="text"
                      maxlength="7"
                      class="form-input w-32 rounded-lg border-slate-300 text-sm font-mono"
                      @input="setFieldDefault(field, $event.target.value)"
                    />
                  </div>
                </div>
              </div>

              <div>
                <p class="text-sm font-medium text-slate-700">{{ t("fieldBuilder.editorWidth") }}</p>
                <p class="mt-0.5 mb-2 text-sm text-slate-500">{{ t("fieldBuilder.editorWidthHint") }}</p>
                <div
                  class="grid grid-cols-4 gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1"
                >
                  <button
                    v-for="option in layoutWidthOptions"
                    :key="option.value"
                    type="button"
                    class="group/layout flex min-w-0 flex-col gap-1 rounded-md px-2 py-1.5 text-xs font-medium transition"
                    :class="
                      fieldLayoutWidth(field) === option.value
                        ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200'
                        : 'text-slate-500 hover:bg-white/70 hover:text-slate-800'
                    "
                    :title="fieldLayoutOptionLabel(option)"
                    :aria-pressed="fieldLayoutWidth(field) === option.value"
                    @click="setFieldLayoutWidth(field, option.value)"
                  >
                    <span class="flex h-5 overflow-hidden rounded bg-slate-200">
                      <span
                        class="h-full rounded bg-slate-400 transition-colors group-hover/layout:bg-slate-500"
                        :class="
                          fieldLayoutWidth(field) === option.value
                            ? 'bg-theme-500 group-hover/layout:bg-theme-500'
                            : ''
                        "
                        :style="{ width: option.percent }"
                      ></span>
                    </span>
                    <span>{{ fieldLayoutOptionShortLabel(option) }}</span>
                  </button>
                </div>
              </div>
            </section>

            <div class="space-y-4">
              <!-- Type-specific options -->
              <section
                v-if="hasTypeOptions(field)"
                class="space-y-3 rounded-lg border border-slate-200 p-4"
              >
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  {{ t("fieldBuilder.typeOptions", { type: fieldTypeLabel(field.type) }) }}
                </h3>
                <FieldConfigSelect
                  v-if="field.type === 'select'"
                  :field="field"
                />
                <FieldConfigRelation
                  v-if="field.type === 'relation'"
                  :field="field"
                  :content-types="contentTypes"
                />
                <FieldConfigRange
                  v-if="field.type === 'range'"
                  :field="field"
                />
                <FieldConfigMedia
                  v-if="field.type === 'media'"
                  :field="field"
                />
                <FieldConfigRepeater
                  v-if="field.type === 'repeater'"
                  :field="field"
                  :content-types="contentTypes"
                />
                <FieldConfigColor
                  v-if="field.type === 'color'"
                  :field="field"
                />
              </section>

              <!-- Help text -->
              <section class="space-y-2 rounded-lg border border-slate-200 p-4">
                <label
                  :for="`field-${index}-description`"
                  class="block text-xs font-semibold uppercase tracking-wide text-slate-500"
                >
                  {{ t("fieldBuilder.helpText") }}
                </label>
                <p class="text-sm text-slate-500">{{ t("fieldBuilder.helpTextBody") }}</p>
                <LocalizedInput multiline
                  :id="`field-${index}-description`"
                  v-model="field.description"
                  rows="2"
                  :placeholder="t('fieldBuilder.descriptionPlaceholder')"
                  class="w-full"
                />
              </section>
            </div>
          </div>
        </div>
      </div>
    </TransitionGroup>
    <button
      type="button"
      @click="addField"
      class="w-full py-2.5 rounded-xl border-2 border-dashed border-slate-300 text-sm text-slate-500 hover:border-theme-400 hover:text-theme-600 transition-colors"
    >
      + {{ t("fieldBuilder.addField") }}
    </button>
  </div>
</template>

<script setup>
import { computed, nextTick, ref, watch } from "vue";
import { Icon } from "@iconify/vue";
import SearchableSelect from "./SearchableSelect.vue";
import ToggleSwitch from "./ToggleSwitch.vue";
import FieldConfigSelect from "./FieldConfigSelect.vue";
import FieldConfigRelation from "./FieldConfigRelation.vue";
import FieldConfigRange from "./FieldConfigRange.vue";
import FieldConfigMedia from "./FieldConfigMedia.vue";
import FieldConfigRepeater from "./FieldConfigRepeater.vue";
import FieldConfigColor from "./FieldConfigColor.vue";
import { useFieldTypeMeta } from "../composables/useFieldTypeMeta.js";
import {
  normalizeKey,
  optionsToText,
  parseSelectOptions,
  serializeSelectOptions,
  uniqueKey,
  rangeDefaults,
} from "../composables/fieldBuilderUtils.js";
import {
  emptyConfiguredDefault,
  hasConfiguredDefault,
  supportsConfiguredDefault,
} from "../composables/fieldDefaults.js";
import {
  formatJsonDefaultText,
  layoutRowColors,
  layoutWidthOptions,
  normalizeExternalDefault,
  normalizeExternalLayout,
  normalizeFieldDefault,
  normalizeLayoutWidth,
  selectDefaultOptions,
  selectDefaultSingleValue,
  selectDefaultValues,
  setFieldLayoutWidth,
  setJsonFieldDefault,
  validColorDefault,
} from "../composables/fieldSchemaUtils.js";
import LocalizedInput from "./LocalizedInput.vue";
import { isLocalized, resolveLocalized } from "../composables/localizedValues.js";
import { useLanguages } from "../composables/useLanguages.js";
import { useI18n } from "../i18n/index.js";

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  fieldTypes: { type: Array, default: () => [] },
  contentTypes: { type: Array, default: () => [] },
  fieldErrors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["update:modelValue"]);
const { fieldTypeIcon, fieldTypeLabel } = useFieldTypeMeta();
const { t } = useI18n();
const languages = useLanguages();
const labelText = (value) => String(resolveLocalized(value, languages.defaultLocale.value, languages.defaultLocale.value) ?? '').trim();


const fieldTypeOptions = computed(() => props.fieldTypes.map(fieldTypeOption));

// Every content type has these; shown read-only above the custom fields.
const builtinFields = [
  { key: "title", type: "text", labelKey: "fieldBuilder.builtinTitle" },
  { key: "slug", type: "slug", labelKey: "fieldBuilder.builtinSlug" },
];

const MULTIPLE_TYPES = ["media", "relation", "select"];
const TYPES_WITH_OPTIONS = ["select", "relation", "range", "media", "repeater", "color"];
let uid = 0;

// Work on an internal list that includes a stable _id for list rendering.
const fields = ref(toInternal(props.modelValue));
let lastExternalJson = JSON.stringify(toExternal(fields.value));

const layoutRowByFieldId = computed(() => {
  const rows = new Map();
  let row = 0;
  let used = 0;

  for (const field of fields.value) {
    const units = fieldLayoutUnits(field);

    if (units === 12) {
      rows.set(field._id, row);
      row += 1;
      used = 0;
      continue;
    }

    if (used > 0 && used + units > 12) {
      row += 1;
      used = 0;
    }

    rows.set(field._id, row);
    used += units;

    if (used >= 12) {
      row += 1;
      used = 0;
    }
  }

  return rows;
});

watch(
  () => props.modelValue,
  (val) => {
    if (JSON.stringify(toExternal(fields.value)) !== JSON.stringify(val)) {
      fields.value = toInternal(val);
    }
    lastExternalJson = JSON.stringify(toExternal(fields.value));
  },
);

watch(
  () => props.fieldErrors,
  (errors) => {
    for (const index of Object.keys(errors ?? {})) {
      if (fields.value[index]) fields.value[index]._collapsed = false;
    }
  },
  { deep: true },
);

watch(
  fields,
  () => {
    const external = toExternal(fields.value);
    const externalJson = JSON.stringify(external);

    if (externalJson === lastExternalJson) return;

    lastExternalJson = externalJson;
    emit("update:modelValue", external);
  },
  { deep: true },
);

function toInternal(arr) {
  return (arr ?? []).map((f) => {
    const originalKey = f._originalKey ?? f.key ?? "";

    return {
      ...f,
      label: f.label ?? "",
      target: f.target ?? f.collection ?? "",
      _optionsText: optionsToText(f.options, languages.defaultLocale.value),
      _defaultJsonText: formatJsonDefaultText(f),
      ...(f.type === "range" ? rangeDefaults(f) : {}),
      subfields: Array.isArray(f.subfields)
        ? f.subfields.map(toInternalNestedField)
        : [],
      _originalKey: originalKey,
      _originalType: f._originalType ?? f.type ?? "text",
      _collapsed: f._collapsed ?? originalKey !== "",
      _id: uid++,
    };
  });
}

function toExternal(arr) {
  return arr.map(
    ({
      _id,
      _collapsed,
      _keyUnlocked,
      collection,
      multiple,
      _optionsText,
      _defaultJsonText,
      subfields,
      ...rest
    }) => {
      const field = { ...rest };
      if (!isLocalized(field.label) && !labelText(field.label)) delete field.label;
      if (!isLocalized(field.description) && !labelText(field.description)) delete field.description;
      if (field.localized !== false) delete field.localized;
      normalizeExternalLayout(field);

      if (field.type !== "relation") {
        delete field.target;
      }

      if (!["media", "relation", "select"].includes(field.type)) {
        delete field.multiple;
      }

      if (multiple && ["media", "relation", "select"].includes(field.type)) {
        field.multiple = true;
      }

      if (field.type === "select") {
        field.options = serializeSelectOptions(
          parseSelectOptions(_optionsText), field.options, languages.defaultLocale.value,
        );
      } else {
        delete field.options;
      }

      if (field.type === "range") {
        Object.assign(field, rangeDefaults(field));
      } else {
        delete field.min;
        delete field.step;
        delete field.max;
        delete field.display_decimals;
      }

      normalizeExternalDefault(field, _defaultJsonText);

      if (field.type === "repeater") {
        field.subfields = (subfields ?? []).map(toExternalNestedField);
      } else {
        delete field.subfields;
      }

      return field;
    },
  );
}

function fieldTypeOption(type) {
  return {
    value: type,
    label: fieldTypeLabel(type),
    icon: fieldTypeIcon(type),
  };
}

function toInternalNestedField(field) {
  return {
    ...field,
    target: field.target ?? field.collection ?? "",
    _optionsText: optionsToText(field.options, languages.defaultLocale.value),
    _defaultJsonText: formatJsonDefaultText(field),
    ...(field.type === "range" ? rangeDefaults(field) : {}),
    _id: uid++,
  };
}

function toExternalNestedField({
  _id: _sid,
  collection,
  multiple,
  _optionsText,
  _defaultJsonText,
  subfields,
  ...rest
}) {
  const field = { ...rest };
  if (!isLocalized(field.label) && !labelText(field.label)) delete field.label;
  if (!isLocalized(field.description) && !labelText(field.description)) delete field.description;
  if (field.localized !== false) delete field.localized;
  normalizeExternalLayout(field);

  if (field.type !== "relation") {
    delete field.target;
  }

  if (!["media", "relation", "select"].includes(field.type)) {
    delete field.multiple;
  }

  if (multiple && ["media", "relation", "select"].includes(field.type)) {
    field.multiple = true;
  }

  if (field.type === "select") {
    field.options = serializeSelectOptions(parseSelectOptions(_optionsText), field.options, languages.defaultLocale.value);
  } else {
    delete field.options;
  }

  if (field.type === "range") {
    Object.assign(field, rangeDefaults(field));
  } else {
    delete field.min;
    delete field.step;
    delete field.max;
    delete field.display_decimals;
  }

  normalizeExternalDefault(field, _defaultJsonText);
  delete field.subfields;

  return field;
}

function supportsFieldDefault(field) {
  return supportsConfiguredDefault(field);
}

function hasFieldDefault(field) {
  return hasConfiguredDefault(field);
}

function toggleFieldDefault(field, enabled) {
  if (!enabled) {
    delete field.default;
    delete field._defaultJsonText;
    return;
  }

  field.default = emptyConfiguredDefault(field.type, field);
  field._defaultJsonText = formatJsonDefaultText(field);
}

function setFieldDefault(field, value) {
  field.default = value;
  normalizeFieldDefault(field);
}

function setFieldUniversal(field, enabled) {
  if (enabled) {
    field.localized = false;
  } else {
    delete field.localized;
  }
}

function fieldLayoutWidth(field) {
  return normalizeLayoutWidth(field?.layout?.width);
}

function fieldLayoutOption(field) {
  const width = fieldLayoutWidth(field);
  return (
    layoutWidthOptions.find((option) => option.value === width) ??
    layoutWidthOptions[layoutWidthOptions.length - 1]
  );
}

function fieldLayoutPercent(field) {
  return fieldLayoutOption(field).percent;
}

function fieldLayoutUnits(field) {
  return fieldLayoutOption(field).units;
}

function fieldLayoutIndicatorStyle(field) {
  const row = layoutRowByFieldId.value.get(field?._id) ?? 0;

  return {
    width: fieldLayoutPercent(field),
    backgroundColor: layoutRowColors[row % layoutRowColors.length],
  };
}

function fieldLayoutLabel(field) {
  return t("fieldBuilder.editorWidthTooltip", {
    width: fieldLayoutOptionLabel(fieldLayoutOption(field)),
  });
}

function fieldLayoutShortLabel(field) {
  return fieldLayoutOptionShortLabel(fieldLayoutOption(field));
}

function fieldLayoutOptionLabel(option) {
  return t(option.labelKey);
}

function fieldLayoutOptionShortLabel(option) {
  return option.shortLabelKey ? t(option.shortLabelKey) : option.shortLabel;
}

function addField() {
  fields.value.push({
    _id: uid++,
    key: "",
    label: "",
    type: "text",
    required: false,
    subfields: [],
    _originalKey: "",
    _originalType: "text",
    _collapsed: false,
    _keyTouched: false,
  });
}

function toggleField(field) {
  field._collapsed = !field._collapsed;
}

function onHeaderClick(field) {
  if (suppressNextClick.value) return;
  field._collapsed = !field._collapsed;
}

function isMultiple(field) {
  return Boolean(field.multiple) && MULTIPLE_TYPES.includes(field.type);
}

function hasTypeOptions(field) {
  return TYPES_WITH_OPTIONS.includes(field.type);
}

function expandAllFields() {
  for (const field of fields.value) {
    field._collapsed = false;
  }
}

function collapseAllFields() {
  for (const field of fields.value) {
    field._collapsed = true;
  }
}

defineExpose({
  expandAllFields,
  collapseAllFields,
});

function onLabelInput(field) {
  // For brand-new fields (never saved), auto-derive the key from the label,
  // but only while the user hasn't manually edited the key field yet.
  if (field._originalKey === "" && !field._keyTouched) {
    field.key = normalizeKey(labelText(field.label));
  }
}

function onKeyInput(field) {
  field._keyTouched = true;
}

function isKeyRenamed(field) {
  return field._originalKey !== "" && field.key !== field._originalKey;
}

// Saved keys hold existing entry data, so renaming takes an explicit unlock.
function isKeyLocked(field) {
  return field._originalKey !== "" && !field._keyUnlocked && !isKeyRenamed(field);
}

async function unlockKey(field, index) {
  field._keyUnlocked = true;
  await nextTick();
  document.getElementById(`field-${index}-key`)?.focus();
}

// Type groups whose members are interchangeable without data loss.
const losslessTypeGroups = [
  new Set(["text", "textarea", "markdown", "html"]),
  new Set(["number", "range"]),
];

function allowedTypeOptions(field) {
  if (field._originalKey === "") return fieldTypeOptions.value; // new field — show all
  const group = losslessTypeGroups.find((g) => g.has(field._originalType));
  // Return all options, disabling those outside the compatible group (or all others if locked)
  return fieldTypeOptions.value.map((opt) => ({
    ...opt,
    disabled: group ? !group.has(opt.value) : opt.value !== field._originalType,
  }));
}

function onFieldTypeChange(field) {
  if (field.type === "range") {
    normalizeRange(field);
  }

  if (!supportsFieldDefault(field)) {
    delete field.default;
    delete field._defaultJsonText;
  } else if (hasFieldDefault(field)) {
    normalizeFieldDefault(field);
    field._defaultJsonText = formatJsonDefaultText(field);
  }
}

function setFieldType(field, type) {
  field.type = type;
  onFieldTypeChange(field);
}

function removeField(index) {
  fields.value.splice(index, 1);
}

function duplicateField(index) {
  const source = fields.value[index];
  if (!source) return;

  const clone = JSON.parse(JSON.stringify(source));
  clone._id = uid++;
  clone._originalKey = ""; // duplicate is a new field; no rename concern
  clone._originalType = clone.type; // type starts fresh from the cloned type
  clone._keyTouched = false; // key can still be auto-derived after duplication
  clone._collapsed = false;

  if (Array.isArray(clone.subfields)) {
    clone.subfields = clone.subfields.map((sub) => ({ ...sub, _id: uid++ }));
  }

  const baseKey = normalizeKey(clone.key || "field") || "field";
  const reserved = new Set(["title", "slug", "body"]);
  const used = new Set(
    fields.value.map((item) => String(item.key ?? "")).filter(Boolean),
  );
  clone.key = uniqueKey(baseKey, used, reserved);

  fields.value.splice(index + 1, 0, clone);
}

function isValidKey(key) {
  return key === "" || /^[a-z0-9_]+$/.test(key);
}

function normalizeFieldKey(field, index) {
  const base = normalizeKey(field.key);

  if (base === "") {
    field.key = "";
    return;
  }

  const reserved = new Set(["title", "slug", "body"]);
  const used = new Set(
    fields.value
      .filter((_, i) => i !== index)
      .map((item) => String(item.key ?? ""))
      .filter(Boolean),
  );

  field.key = uniqueKey(base, used, reserved);
}

function normalizeRange(field) {
  Object.assign(field, rangeDefaults(field));
}

// ── Drag-and-drop ─────────────────────────────────────────────────────────────
const dragIndex = ref(null);
const suppressNextClick = ref(false);

function onDragStart(index, e) {
  dragIndex.value = index;
  suppressNextClick.value = true;
  e.dataTransfer.effectAllowed = "move";
}

function onDragOver(index, event) {
  if (dragIndex.value === null || dragIndex.value === index) return;

  // Only reorder once the cursor crosses the midpoint of the target card.
  // This prevents the oscillation caused by rapid back-and-forth swaps.
  const rect = event.currentTarget.getBoundingClientRect();
  const midY = rect.top + rect.height / 2;
  if (dragIndex.value < index && event.clientY < midY) return;
  if (dragIndex.value > index && event.clientY > midY) return;

  const moved = fields.value.splice(dragIndex.value, 1)[0];
  fields.value.splice(index, 0, moved);
  dragIndex.value = index;
}

function onDragEnd() {
  dragIndex.value = null;
  setTimeout(() => {
    suppressNextClick.value = false;
  }, 0);
}
</script>

<style scoped>
.field-sort-move {
  transition: transform 0.2s ease;
}
</style>
