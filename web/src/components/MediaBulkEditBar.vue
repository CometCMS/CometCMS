<template>
  <div
    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
  >
    <!-- Left: selection count + select-all -->
    <label
      class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 shrink-0"
    >
      <input
        type="checkbox"
        class="form-checkbox rounded border-slate-300 text-theme-600"
        :checked="allPageSelected"
        :disabled="pageCount === 0"
        @change="emit('toggle-page-selection', $event.target.checked)"
      />
      <span>{{ t("bulk.selected", { count: selectedCount }) }}</span>
    </label>

    <!-- Right: bulk actions -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center flex-wrap">
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
      </template>

      <!-- Divider (visible when field actions present) -->
      <span
        v-if="selectedField"
        class="hidden sm:inline text-slate-300 select-none"
        >|</span
      >

      <!-- Delete + Clear -->
      <button
        type="button"
        class="btn-danger text-sm disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="selectedCount === 0 || applying"
        @click="emit('delete-selected')"
      >
        <Icon icon="mdi:delete" class="w-4 h-4" />
      </button>
      <button
        type="button"
        class="btn-secondary text-sm disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="applying"
        @click="emit('clear-selection')"
      >
        <Icon icon="mdi:close" class="w-4 h-4" />
      </button>
    </div>
  </div>
</template>

<script setup>
import { Icon } from "@iconify/vue";
import { ref, computed, watch } from "vue";
import { useI18n } from "../i18n/index.js";

const props = defineProps({
  categories: { type: Array, default: () => [] },
  selectedCount: { type: Number, default: 0 },
  allPageSelected: { type: Boolean, default: false },
  pageCount: { type: Number, default: 0 },
  applying: { type: Boolean, default: false },
});

const emit = defineEmits([
  "apply",
  "delete-selected",
  "clear-selection",
  "toggle-page-selection",
]);
const { t } = useI18n();

const selectedKey = ref("");
const fieldValue = ref("");

// ── Field definitions (extend here for future fields) ─────────────────────────

const fields = computed(() => [
  { key: "category", label: t("media.category"), kind: "category" },
  { key: "visibility", label: t("media.visibility"), kind: "visibility" },
]);

const selectedField = computed(
  () => fields.value.find((f) => f.key === selectedKey.value) ?? null,
);

function defaultValueFor(key) {
  if (key === "category") return "";
  if (key === "visibility") return "public";
  return null;
}

// ── Apply ────────────────────────────────────────────────────────────────────

const canApply = computed(() => {
  if (!selectedField.value) return false;
  if (props.selectedCount === 0) return false;
  if (selectedField.value.kind === "category") return true; // empty = "No category" is valid
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
