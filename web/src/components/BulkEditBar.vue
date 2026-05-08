<template>
  <div class="flex items-center gap-3 flex-wrap">
    <!-- Field selector (Dropdown 1) -->
    <div class="flex items-center gap-2 min-w-0">
      <label
        class="text-sm text-slate-600 whitespace-nowrap font-medium shrink-0"
        >{{ t("bulk.setField") }}</label
      >
      <select
        v-model="selectedKey"
        class="form-select rounded-lg border-slate-300 text-sm min-w-[160px]"
        @change="fieldValue = null"
      >
        <option value="">{{ t("bulk.chooseField") }}</option>
        <optgroup :label="t('bulk.systemFields')">
          <option v-for="f in systemFields" :key="f.key" :value="f.key">
            {{ f.label }}
          </option>
        </optgroup>
        <optgroup
          v-if="customFields.length > 0"
          :label="t('bulk.contentFields')"
        >
          <option v-for="f in customFields" :key="f.key" :value="f.key">
            {{ f.label }}
          </option>
        </optgroup>
      </select>
    </div>

    <!-- Value selector (Selector 2) — dynamic based on selected field -->
    <div v-if="selectedField" class="flex items-center gap-2 min-w-0 flex-1">
      <label
        class="text-sm text-slate-600 whitespace-nowrap font-medium shrink-0"
        >{{ t("bulk.to") }}</label
      >

      <FieldValueInput
        v-model="fieldValue"
        :field="selectedField"
        :users="users"
        input-class="min-w-[140px] flex-1 max-w-xs"
      />
    </div>

    <!-- Apply button -->
    <button
      v-if="selectedField"
      type="button"
      :disabled="!canApply || applying"
      class="btn-primary py-1.5 px-4 disabled:opacity-40 whitespace-nowrap shrink-0 inline-flex items-center gap-2"
      @click="apply"
    >
      <svg
        v-if="applying"
        class="animate-spin h-3.5 w-3.5"
        viewBox="0 0 24 24"
        fill="none"
      >
        <circle
          class="opacity-25"
          cx="12"
          cy="12"
          r="10"
          stroke="currentColor"
          stroke-width="4"
        />
        <path
          class="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8v8H4z"
        />
      </svg>
      {{
        applying
          ? t("bulk.applying")
          : t("bulk.applyEntries", {
              count: selectedCount,
              itemLabel: t(
                selectedCount === 1
                  ? "contentList.entry"
                  : "contentList.entries",
              ),
            })
      }}
    </button>
  </div>
</template>

<script setup>
import { ref, computed } from "vue";
import FieldValueInput from "./FieldValueInput.vue";
import { useI18n } from "../i18n/index.js";

const props = defineProps({
  contentType: { type: Object, default: null },
  users: { type: Array, default: () => [] },
  selectedCount: { type: Number, default: 0 },
  applying: { type: Boolean, default: false },
});

const emit = defineEmits(["apply"]);
const { t } = useI18n();

const selectedKey = ref("");
const fieldValue = ref(null);

// ── Field option lists ────────────────────────────────────────────────────────

const systemFields = computed(() => [
  { key: "status", label: t("contentEdit.status"), kind: "status" },
  { key: "author_id", label: t("contentEdit.author"), kind: "author" },
  {
    key: "published_at",
    label: t("contentEdit.publishedAt"),
    kind: "datetime",
  },
  {
    key: "scheduled_at",
    label: t("contentEdit.scheduledFor"),
    kind: "datetime",
  },
]);

// Field types that have a sensible inline bulk-edit control
const SUPPORTED_TYPES = new Set([
  "text",
  "slug",
  "textarea",
  "number",
  "range",
  "boolean",
  "date",
  "datetime",
  "select",
]);

const customFields = computed(() => {
  const fields = props.contentType?.fields ?? {};
  return Object.entries(fields)
    .filter(([, cfg]) => SUPPORTED_TYPES.has(cfg?.type))
    .map(([key, cfg]) => ({
      key,
      label: key.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase()),
      kind: cfg.type,
      config: cfg,
    }));
});

const allFields = computed(() => [
  ...systemFields.value,
  ...customFields.value,
]);

const selectedField = computed(
  () => allFields.value.find((f) => f.key === selectedKey.value) ?? null,
);

// ── Apply logic ───────────────────────────────────────────────────────────────

const canApply = computed(() => !!selectedField.value);

function apply() {
  if (!canApply.value) return;
  emit("apply", { field: selectedKey.value, value: fieldValue.value });
}
</script>
