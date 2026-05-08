<!-- Visual drag-and-drop field editor for content types -->
<template>
  <div class="space-y-2">
    <TransitionGroup tag="div" name="field-sort" class="space-y-2">
      <div
        v-for="(field, index) in fields"
        :key="field._id"
        class="group/field card cursor-grab select-none"
        :class="[
          field._collapsed ? 'p-3' : 'p-4',
          { 'ring-2 ring-theme-400': dragIndex === index },
        ]"
        draggable="true"
        @dragstart="onDragStart(index, $event)"
        @dragover.prevent="onDragOver(index, $event)"
        @dragend="onDragEnd"
      >
        <div class="flex items-center gap-3">
          <button
            type="button"
            @click.stop="toggleField(field)"
            class="shrink-0 text-slate-400 hover:text-theme-600 transition-colors p-1 rounded"
            :title="field._collapsed ? 'Expand field' : 'Collapse field'"
            :aria-label="field._collapsed ? 'Expand field' : 'Collapse field'"
            :aria-expanded="!field._collapsed"
          >
            <Icon
              icon="mdi:chevron-down"
              class="w-4 h-4 transition-transform"
              :class="{ '-rotate-90': field._collapsed }"
            />
          </button>

          <!-- Drag handle -->
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
            v-if="field._collapsed"
            type="button"
            class="flex-1 min-w-0 flex items-center gap-3 text-left"
            @click.stop="expandCollapsedField(field)"
          >
            <div class="min-w-0">
              <div class="truncate text-sm font-medium text-slate-800">
                {{ field.label || field.key || "Untitled field" }}
              </div>
              <div
                class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500"
              >
                <code class="font-mono bg-slate-100 px-1 py-0.5 rounded">{{
                  field.key || "field_key"
                }}</code>
                <span
                  class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5"
                >
                  <Icon :icon="fieldTypeIcon(field.type)" class="h-3 w-3" />
                  {{ field.type }}
                </span>
                <span
                  v-if="field.required"
                  class="inline-flex rounded bg-amber-50 px-1.5 py-0.5 text-amber-700"
                  >Required</span
                >
                <span
                  v-if="field.localized === false"
                  class="inline-flex items-center gap-1 rounded bg-sky-50 px-1.5 py-0.5 text-sky-700"
                >
                  <Icon icon="mdi:web" class="h-3 w-3" />
                  Universal
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

          <!-- Label + Key + Type + Required -->
          <div v-else class="flex-1 min-w-0 grid grid-cols-4 gap-3">
            <div>
              <label class="text-xs text-slate-500 block mb-0.5">Label</label>
              <input
                v-model="field.label"
                type="text"
                placeholder="Display name"
                class="form-input w-full rounded-lg border-slate-300 text-sm"
                @input="onLabelInput(field)"
              />
            </div>

            <div>
              <label class="text-xs text-slate-500 block mb-0.5">
                Field key
                <span class="text-slate-400 font-normal">(API / storage)</span>
              </label>
              <input
                v-model="field.key"
                type="text"
                placeholder="field_name"
                class="form-input w-full rounded-lg border-slate-300 text-sm"
                :class="{
                  'border-red-400':
                    !isValidKey(field.key) || fieldErrors[index],
                  'border-amber-400': isKeyRenamed(field),
                }"
                @blur="normalizeFieldKey(field, index)"
                @input="onKeyInput(field)"
              />
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
                Was
                <code class="font-mono bg-amber-50 px-0.5 rounded">{{
                  field._originalKey
                }}</code>
                — all existing entries will be migrated on save.
              </p>
            </div>

            <div>
              <label class="text-xs text-slate-500 block mb-0.5">Type</label>
              <SearchableSelect
                :model-value="field.type"
                :options="allowedTypeOptions(field)"
                :clearable="false"
                :searchable="true"
                @update:model-value="setFieldType(field, $event)"
              />
            </div>

            <div class="flex flex-col justify-end">
              <label class="text-xs text-slate-500 block mb-0.5">&nbsp;</label>
              <label class="inline-flex items-center gap-2 h-[38px]">
                <input
                  type="checkbox"
                  v-model="field.required"
                  class="form-checkbox rounded border-slate-300 text-theme-600"
                />
                <span class="text-sm text-slate-600">Required</span>
              </label>
            </div>
          </div>

          <div
            class="shrink-0 flex items-center gap-1 opacity-0 transition-opacity group-hover/field:opacity-100 focus-within:opacity-100"
          >
            <button
              type="button"
              @click="duplicateField(index)"
              class="text-slate-400 hover:text-theme-600 transition-colors p-1 rounded"
              title="Duplicate field"
              aria-label="Duplicate field"
            >
              <Icon icon="mdi:content-copy" class="w-4 h-4" />
            </button>
            <button
              type="button"
              @click="removeField(index)"
              class="text-slate-400 hover:text-red-500 transition-colors p-1 rounded"
              title="Remove field"
              aria-label="Remove field"
            >
              <Icon icon="mdi:close" class="w-4 h-4" />
            </button>
          </div>
        </div>

        <!-- Localization behavior -->
        <div v-if="!field._collapsed" class="mt-3 pl-7">
          <label class="inline-flex items-center gap-2">
            <input
              type="checkbox"
              :checked="field.localized === false"
              class="form-checkbox rounded border-slate-300 text-theme-600"
              @change="setFieldUniversal(field, $event.target.checked)"
            />
            <span class="text-sm text-slate-600"
              >Same value for all languages</span
            >
          </label>
        </div>

        <!-- Editor layout -->
        <div v-if="!field._collapsed" class="mt-3 pl-7">
          <div
            class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
          >
            <div>
              <label class="text-xs text-slate-500 block mb-0.5">{{
                t("fieldBuilder.editorWidth")
              }}</label>
              <p class="text-xs text-slate-400">
                {{ t("fieldBuilder.editorWidthHint") }}
              </p>
            </div>

            <div
              class="grid grid-cols-4 gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1"
            >
              <button
                v-for="option in layoutWidthOptions"
                :key="option.value"
                type="button"
                class="group/layout flex min-w-16 flex-col gap-1 rounded-md px-2 py-1.5 text-xs font-medium transition"
                :class="
                  fieldLayoutWidth(field) === option.value
                    ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200'
                    : 'text-slate-500 hover:bg-white/70 hover:text-slate-800'
                "
                :title="fieldLayoutOptionLabel(option)"
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
        </div>

        <!-- Per-type extra config -->
        <FieldConfigSelect
          v-if="!field._collapsed && field.type === 'select'"
          :field="field"
          class="mt-3 pl-7"
        />
        <FieldConfigRelation
          v-if="!field._collapsed && field.type === 'relation'"
          :field="field"
          :content-types="contentTypes"
          class="mt-3 pl-7"
        />
        <FieldConfigRange
          v-if="!field._collapsed && field.type === 'range'"
          :field="field"
          class="mt-3 pl-7"
        />
        <FieldConfigMedia
          v-if="!field._collapsed && field.type === 'media'"
          :field="field"
          class="mt-3 pl-7"
        />
        <FieldConfigRepeater
          v-if="!field._collapsed && field.type === 'repeater'"
          :field="field"
          :content-types="contentTypes"
          class="mt-3 pl-7"
        />
        <FieldConfigColor
          v-if="!field._collapsed && field.type === 'color'"
          :field="field"
          class="mt-3 pl-7"
        />

        <!-- Default value (supported field types) -->
        <div
          v-if="!field._collapsed && supportsFieldDefault(field)"
          class="mt-3 pl-7 space-y-2"
        >
          <label class="inline-flex items-center gap-2">
            <input
              v-if="field.type !== 'range'"
              type="checkbox"
              :checked="hasFieldDefault(field)"
              class="form-checkbox rounded border-slate-300 text-theme-600"
              @change="toggleFieldDefault(field, $event.target.checked)"
            />
            <span class="text-xs text-slate-500">Default value</span>
          </label>

          <div v-if="hasFieldDefault(field)" class="max-w-2xl">
            <textarea
              v-if="['textarea', 'markdown'].includes(field.type)"
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
              <span class="text-sm text-slate-600">Enabled</span>
            </label>

            <SearchableSelect
              v-else-if="field.type === 'select' && field.multiple"
              :model-value="selectDefaultValues(field)"
              :options="selectDefaultOptions(field)"
              :multiple="true"
              placeholder="Select defaults…"
              @update:model-value="setFieldDefault(field, $event)"
            />

            <select
              v-else-if="field.type === 'select'"
              :value="selectDefaultSingleValue(field)"
              class="form-select w-full rounded-lg border-slate-300 text-sm"
              @change="setFieldDefault(field, $event.target.value)"
            >
              <option value="">— select —</option>
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

        <!-- Description (all field types) -->
        <div v-if="!field._collapsed" class="mt-3 pl-7">
          <label class="text-xs text-slate-500 block mb-0.5"
            >Description
            <span class="text-slate-400"
              >(optional helper text shown in the editor)</span
            ></label
          >
          <input
            v-model="field.description"
            type="text"
            placeholder="Explain what this field is for…"
            class="form-input w-full rounded-lg border-slate-300 text-sm"
          />
        </div>
      </div>
    </TransitionGroup>
    <button
      type="button"
      @click="addField"
      class="w-full py-2.5 rounded-xl border-2 border-dashed border-slate-300 text-sm text-slate-500 hover:border-theme-400 hover:text-theme-600 transition-colors"
    >
      + Add field
    </button>
  </div>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import { Icon } from "@iconify/vue";
import SearchableSelect from "./SearchableSelect.vue";
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
import { useI18n } from "../i18n/index.js";

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  fieldTypes: { type: Array, default: () => [] },
  contentTypes: { type: Array, default: () => [] },
  fieldErrors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["update:modelValue"]);
const { fieldTypeIcon } = useFieldTypeMeta();
const { t } = useI18n();

const fieldTypeOptions = computed(() => props.fieldTypes.map(fieldTypeOption));
let uid = 0;

const layoutWidthOptions = [
  {
    value: "1/3",
    labelKey: "fieldBuilder.layoutOneThird",
    shortLabel: "1/3",
    percent: "33.333%",
    units: 4,
  },
  {
    value: "1/2",
    labelKey: "fieldBuilder.layoutHalf",
    shortLabel: "1/2",
    percent: "50%",
    units: 6,
  },
  {
    value: "2/3",
    labelKey: "fieldBuilder.layoutTwoThirds",
    shortLabel: "2/3",
    percent: "66.667%",
    units: 8,
  },
  {
    value: "full",
    labelKey: "fieldBuilder.layoutFull",
    shortLabelKey: "fieldBuilder.layoutFullShort",
    percent: "100%",
    units: 12,
  },
];

const layoutRowColors = [
  "#2563eb",
  "#059669",
  "#d97706",
  "#7c3aed",
  "#dc2626",
  "#0891b2",
];

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
      _optionsText: optionsToText(f.options),
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
      collection,
      multiple,
      _optionsText,
      _defaultJsonText,
      subfields,
      ...rest
    }) => {
      const field = { ...rest };
      if (!field.label?.trim()) delete field.label;
      if (!field.description?.trim()) delete field.description;
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
          parseSelectOptions(_optionsText),
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
    label: type,
    icon: fieldTypeIcon(type),
  };
}

function toInternalNestedField(field) {
  return {
    ...field,
    target: field.target ?? field.collection ?? "",
    _optionsText: optionsToText(field.options),
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
  if (!field.label?.trim()) delete field.label;
  if (!field.description?.trim()) delete field.description;
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
    field.options = serializeSelectOptions(parseSelectOptions(_optionsText));
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

function setFieldLayoutWidth(field, width) {
  const normalized = normalizeLayoutWidth(width);

  if (normalized === "full") {
    if (field.layout && typeof field.layout === "object") {
      delete field.layout.width;
      if (Object.keys(field.layout).length === 0) delete field.layout;
    }
    return;
  }

  field.layout = {
    ...(field.layout && typeof field.layout === "object" ? field.layout : {}),
    width: normalized,
  };
}

function normalizeLayoutWidth(width) {
  const value = String(width ?? "full");
  return layoutWidthOptions.some((option) => option.value === value)
    ? value
    : "full";
}

function normalizeExternalLayout(field) {
  if (!field.layout || typeof field.layout !== "object") {
    delete field.layout;
    return;
  }

  const width = normalizeLayoutWidth(field.layout.width);
  const layout = { ...field.layout };

  if (width === "full") {
    delete layout.width;
  } else {
    layout.width = width;
  }

  if (Object.keys(layout).length === 0) {
    delete field.layout;
  } else {
    field.layout = layout;
  }
}

function setJsonFieldDefault(field, value) {
  field._defaultJsonText = value;

  try {
    field.default = JSON.parse(value);
  } catch {
    field.default = value;
  }
}

function normalizeFieldDefault(field) {
  if (!hasFieldDefault(field)) return;

  if (field.type === "range") {
    Object.assign(field, rangeDefaults(field));
    return;
  }

  if (field.type === "number") {
    const number = Number(field.default);
    field.default = Number.isFinite(number) ? number : field.default;
    return;
  }

  if (field.type === "boolean") {
    field.default = field.default === true;
    return;
  }

  if (
    field.type === "select" &&
    field.multiple &&
    !Array.isArray(field.default)
  ) {
    field.default = field.default ? [String(field.default)] : [];
  }
}

function normalizeExternalDefault(field, defaultJsonText = "") {
  if (!supportsConfiguredDefault(field)) {
    delete field.default;
    return;
  }

  if (!("default" in field)) {
    return;
  }

  if (field.type === "json") {
    try {
      field.default = JSON.parse(defaultJsonText);
    } catch {
      field.default = defaultJsonText;
    }
    return;
  }

  if (field.type === "number" || field.type === "range") {
    const number = Number(field.default);
    if (Number.isFinite(number)) field.default = number;
    return;
  }

  if (field.type === "boolean") {
    field.default = field.default === true;
    return;
  }

  if (field.type === "select") {
    if (field.multiple) {
      field.default = Array.isArray(field.default)
        ? field.default
        : field.default
          ? [String(field.default)]
          : [];
    } else if (Array.isArray(field.default)) {
      field.default = field.default[0] ?? "";
    }
  }
}

function selectDefaultOptions(field) {
  return parseSelectOptions(field._optionsText).map(({ key, label }) => ({
    value: key,
    label,
  }));
}

function selectDefaultValues(field) {
  if (Array.isArray(field.default)) return field.default;
  return field.default ? [String(field.default)] : [];
}

function selectDefaultSingleValue(field) {
  if (Array.isArray(field.default)) return field.default[0] ?? "";
  return field.default ?? "";
}

function validColorDefault(value) {
  const color = String(value ?? "");
  return /^#[0-9a-fA-F]{6}$/.test(color) ? color : "#000000";
}

function formatJsonDefaultText(field) {
  if (field?.type !== "json" || !("default" in field)) {
    return "";
  }

  return typeof field.default === "string"
    ? field.default
    : JSON.stringify(field.default, null, 2);
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

function expandCollapsedField(field) {
  if (suppressNextClick.value || !field._collapsed) return;
  field._collapsed = false;
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
    field.key = normalizeKey(field.label || "");
  }
}

function onKeyInput(field) {
  field._keyTouched = true;
}

function isKeyRenamed(field) {
  return field._originalKey !== "" && field.key !== field._originalKey;
}

// Type groups whose members are interchangeable without data loss.
const losslessTypeGroups = [
  new Set(["text", "textarea", "markdown"]),
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
