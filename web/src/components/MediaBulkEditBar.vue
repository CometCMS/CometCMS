<template>
  <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
    <!-- Left: count + select-all + clear -->
    <div class="flex items-center gap-3 shrink-0">
      <span class="text-sm font-semibold text-theme-700">
        {{ t("bulk.selected", { count: selectedCount }) }}
      </span>
      <button
        v-if="!allResultsSelected && pageCount > 0"
        type="button"
        class="text-xs text-theme-600 hover:text-theme-700 font-medium underline-offset-2 underline disabled:opacity-50"
        :disabled="applying"
        @click="emit('select-all')"
      >
        {{ t("bulk.selectAll") }}
      </button>
      <button
        type="button"
        class="text-xs text-slate-500 hover:text-slate-700 font-medium disabled:opacity-50"
        :disabled="applying"
        @click="emit('clear-selection')"
      >
        {{ t("bulk.clear") }}
      </button>
    </div>

    <!-- Divider -->
    <div class="w-px h-5 bg-theme-200 shrink-0 hidden sm:block"></div>

    <!-- Field selector -->
    <div class="flex items-center gap-2 min-w-0">
      <label
        class="text-sm text-slate-600 whitespace-nowrap font-medium shrink-0"
        >{{ t("bulk.setField") }}</label
      >
      <select
        v-model="selectedKey"
        class="form-select rounded-lg border-slate-300 text-sm min-w-[140px]"
        :disabled="applying"
        @change="fieldValue = defaultValueFor(selectedKey)"
      >
        <option value="">{{ t("bulk.choose") }}</option>
        <option v-for="f in fields" :key="f.key" :value="f.key">
          {{ f.label }}
        </option>
      </select>
    </div>

    <!-- Value input — dynamic based on selected field -->
    <template v-if="selectedField">
      <div class="flex items-center gap-2 min-w-0">
        <label
          class="text-sm text-slate-600 whitespace-nowrap font-medium shrink-0"
          >{{ t("bulk.to") }}</label
        >

        <!-- Category field -->
        <select
          v-if="selectedField.kind === 'category'"
          v-model="fieldValue"
          class="form-select rounded-lg border-slate-300 text-sm min-w-[160px]"
          :disabled="applying"
        >
          <option value="">{{ t("media.noCategory") }}</option>
          <option v-for="c in categories" :key="c.path" :value="c.path">
            {{ c.optionLabel }}
          </option>
        </select>

        <!-- Visibility field -->
        <select
          v-if="selectedField.kind === 'visibility'"
          v-model="fieldValue"
          class="form-select rounded-lg border-slate-300 text-sm min-w-[140px]"
          :disabled="applying"
        >
          <option value="public">{{ t("media.public") }}</option>
          <option value="private">{{ t("media.private") }}</option>
        </select>
      </div>

      <!-- Apply button -->
      <button
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
            : t("bulk.applyFiles", {
                count: selectedCount,
                itemLabel: t(
                  selectedCount === 1 ? "media.file" : "media.files",
                ),
              })
        }}
      </button>

      <!-- Divider before delete -->
      <div class="w-px h-5 bg-theme-200 shrink-0 hidden sm:block"></div>
    </template>

    <!-- Delete -->
    <button
      type="button"
      class="btn-secondary py-1.5 px-3 text-sm text-red-600 hover:text-red-700 hover:bg-red-50 border-red-200 disabled:opacity-40 whitespace-nowrap shrink-0 inline-flex items-center gap-1.5"
      :disabled="selectedCount === 0 || applying"
      @click="emit('delete-selected')"
    >
      <Icon icon="mdi:trash-can-outline" class="w-4 h-4" />
    </button>
  </div>
</template>

<script setup>
import { Icon } from "@iconify/vue";
import { ref, computed, watch } from "vue";
import { useI18n } from "../i18n/index.js";

const props = defineProps({
  categories: { type: Array, default: () => [] },
  selectedCount: { type: Number, default: 0 },
  allResultsSelected: { type: Boolean, default: false },
  pageCount: { type: Number, default: 0 },
  applying: { type: Boolean, default: false },
});

const emit = defineEmits([
  "apply",
  "delete-selected",
  "clear-selection",
  "select-all",
]);
const { t } = useI18n();

const selectedKey = ref("");
const fieldValue = ref("");

// ── Field definitions (extend here for future fields) ─────────────────────────

const fields = computed(() => [
  { key: "category", label: t("media.category"), kind: "category" },
  { key: "visibility", label: t("media.visibility"), kind: "visibility" },
  {
    key: "regenerate-thumbnails",
    label: t("media.regenerateThumbs"),
    kind: "action",
  },
]);

const selectedField = computed(
  () => fields.value.find((f) => f.key === selectedKey.value) ?? null,
);

function defaultValueFor(key) {
  if (key === "category") return "";
  if (key === "visibility") return "public";
  if (key === "regenerate-thumbnails") return true;
  return null;
}

// ── Apply ────────────────────────────────────────────────────────────────────

const canApply = computed(() => {
  if (!selectedField.value) return false;
  if (props.selectedCount === 0) return false;
  if (selectedField.value.kind === "category") return true; // empty = "No category" is valid
  if (selectedField.value.kind === "action") return true;
  if (selectedField.value.kind === "visibility")
    return fieldValue.value !== null && fieldValue.value !== "";
  return fieldValue.value !== null && fieldValue.value !== "";
});

function apply() {
  if (!canApply.value) return;
  emit("apply", { field: selectedKey.value, value: fieldValue.value });
}

// Reset category value if the selected category is removed/renamed
watch(
  () => props.categories,
  (cats) => {
    if (selectedField.value?.kind === "category" && fieldValue.value !== "") {
      const still = cats.some((c) => c.path === fieldValue.value);
      if (!still) fieldValue.value = "";
    }
  },
  { deep: true },
);
</script>
