<template>
  <!-- Edit mode -->
  <div
    v-if="editing"
    class="flex items-center gap-1.5 min-w-[160px]"
    @click.stop
    @dblclick.stop
    @keydown.esc.stop="cancel"
  >
    <template v-if="isMediaField">
      <slot />
      <MediaPickerModal
        :selected="mediaDraft"
        :multiple="mediaMultiple"
        @select="commitMedia"
        @close="cancel"
      />
    </template>
    <template v-else>
      <FieldValueInput
        ref="inputRef"
        :field="fieldMeta"
        :model-value="draft"
        :users="users"
        class="flex-1 min-w-0"
        @update:model-value="draft = $event"
        @keydown.enter.prevent="commit"
      />
      <button
        type="button"
        :disabled="saving"
        class="inline-flex items-center justify-center w-6 h-6 rounded text-emerald-600 hover:bg-emerald-50 disabled:opacity-40 shrink-0"
        @click.stop="commit"
      >
        <Icon v-if="saving" icon="mdi:loading" class="w-4 h-4 animate-spin" />
        <Icon v-else icon="mdi:check" class="w-4 h-4" />
      </button>
      <button
        type="button"
        class="inline-flex items-center justify-center w-6 h-6 rounded text-slate-400 hover:bg-slate-100 hover:text-slate-600 shrink-0"
        @click.stop="cancel"
      >
        <Icon icon="mdi:close" class="w-4 h-4" />
      </button>
    </template>
  </div>

  <div
    v-else-if="isEditable"
    class="group/editable cursor-default"
    @click.stop
    @dblclick.stop="startEdit"
  >
    <div
      class="-mx-1.5 -my-1 px-1.5 py-1 rounded group-hover/editable:ring-1 group-hover/editable:ring-theme-300 group-hover/editable:bg-theme-50/40 transition-all"
    >
      <slot />
    </div>
  </div>

  <div v-else>
    <slot />
  </div>
</template>

<script setup>
import { ref, computed, nextTick } from "vue";
import { Icon } from "@iconify/vue";
import FieldValueInput from "./FieldValueInput.vue";
import MediaPickerModal from "./MediaPickerModal.vue";

const props = defineProps({
  column: { type: Object, required: true },
  entry: { type: Object, required: true },
  users: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
});

const emit = defineEmits(["save"]);

const EDITABLE_CORE_KEYS = new Set(["status"]);

const EDITABLE_FIELD_TYPES = new Set([
  "text",
  "select",
  "number",
  "range",
  "date",
  "datetime",
  "boolean",
  "color",
  "media",
]);

const columnDescriptor = computed(() => {
  const { column } = props;

  if (column.kind === "core") {
    if (!EDITABLE_CORE_KEYS.has(column.key)) {
      return { editable: false };
    }
    return {
      editable: true,
      fieldMeta: { kind: column.key },
      fieldKey: column.key,
      getValue: (entry) => entry[column.key] ?? "",
    };
  }

  if (column.kind === "field" && EDITABLE_FIELD_TYPES.has(column.type)) {
    return {
      editable: true,
      fieldMeta: { kind: column.type, config: column.config },
      fieldKey: column.fieldKey,
      getValue: (entry) => {
        const key = column.fieldKey;
        if (Object.prototype.hasOwnProperty.call(entry, key)) return entry[key];
        if (Object.prototype.hasOwnProperty.call(entry.data ?? {}, key))
          return entry.data[key];
        return null;
      },
    };
  }

  return { editable: false };
});

const isEditable = computed(() => columnDescriptor.value.editable);
const fieldMeta = computed(() => columnDescriptor.value.fieldMeta ?? null);
const currentValue = computed(
  () => columnDescriptor.value.getValue?.(props.entry) ?? null,
);
const isMediaField = computed(
  () => props.column.kind === "field" && props.column.type === "media",
);
const mediaMultiple = computed(() => !!props.column.config?.multiple);
const mediaDraft = computed(() => normalizeMediaValues(draft.value));

const editing = ref(false);
const draft = ref(null);
const inputRef = ref(null);

function startEdit() {
  if (!isEditable.value || props.saving) return;
  draft.value = currentValue.value;
  editing.value = true;
  if (isMediaField.value) return;
  nextTick(() => {
    const el = inputRef.value?.$el ?? inputRef.value;
    const input =
      el?.querySelector?.("input, select, textarea") ??
      (el?.tagName ? el : null);
    input?.focus();
  });
}

function cancel() {
  editing.value = false;
}

function commit() {
  if (!editing.value || props.saving) return;
  editing.value = false;
  emit("save", {
    entryId: props.entry.id,
    fieldKey: columnDescriptor.value.fieldKey,
    value: draft.value,
  });
}

function commitMedia(value) {
  if (!editing.value || props.saving) return;
  editing.value = false;
  emit("save", {
    entryId: props.entry.id,
    fieldKey: columnDescriptor.value.fieldKey,
    value: normalizeMediaValues(value),
  });
}

function normalizeMediaValues(value) {
  const source = Array.isArray(value) ? value : splitMediaValue(value);
  return Array.from(new Set(source.map(extractMediaFilename).filter(Boolean)));
}

function splitMediaValue(value) {
  if (value === null || value === undefined) return [];
  const raw = String(value).trim();
  if (raw === "") return [];

  if (raw.startsWith("[") && raw.endsWith("]")) {
    try {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) return parsed;
    } catch {
      // Fall through to comma-separated parsing.
    }
  }

  return raw.split(",").map((item) => item.trim());
}

function extractMediaFilename(value) {
  let raw =
    value && typeof value === "object"
      ? String(value.name ?? value.filename ?? value.url ?? "")
      : String(value ?? "");

  raw = raw.trim();
  if (raw === "") return "";

  try {
    raw = decodeURIComponent(raw);
  } catch {
    // Keep raw value if it is not URI encoded.
  }

  const normalizedPath = raw.split(/[?#]/, 1)[0].replace(/\\+/g, "/");
  const parts = normalizedPath.split("/").filter(Boolean);

  return parts.length > 0 ? parts[parts.length - 1] : "";
}
</script>
