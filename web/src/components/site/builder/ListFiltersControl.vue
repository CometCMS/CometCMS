<template>
  <div :id="id" class="space-y-1.5" role="group" :aria-label="label">
    <div
      v-for="(row, index) in rows"
      :key="row.field"
      class="rounded-lg border border-slate-200 bg-white p-2"
      :class="{ 'border-amber-300 bg-amber-50': !fieldFor(row.field) }"
    >
      <div class="flex min-w-0 items-center gap-1.5">
        <Icon :icon="KIND_ICONS[fieldFor(row.field)?.kind] ?? 'mdi:alert-outline'" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
        <span class="min-w-0 flex-1 truncate text-xs font-medium text-slate-800">{{ fieldFor(row.field)?.label ?? row.field }}</span>
        <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">
          {{ fieldFor(row.field) ? t(`site.listFilter.kind.${fieldFor(row.field).kind}`) : t("site.listFilter.missing") }}
        </span>
        <button type="button" class="row-action" :disabled="index === 0" :title="t('site.listFilter.up')" :aria-label="t('site.listFilter.up')" @click="move(index, -1)">
          <Icon icon="mdi:chevron-up" class="h-4 w-4" />
        </button>
        <button type="button" class="row-action" :disabled="index === rows.length - 1" :title="t('site.listFilter.down')" :aria-label="t('site.listFilter.down')" @click="move(index, 1)">
          <Icon icon="mdi:chevron-down" class="h-4 w-4" />
        </button>
        <button type="button" class="row-action hover:!text-red-600" :title="t('site.listFilter.remove')" :aria-label="t('site.listFilter.remove')" @click="remove(index)">
          <Icon icon="mdi:close" class="h-4 w-4" />
        </button>
      </div>
      <div v-if="fieldFor(row.field)" class="mt-1.5 flex items-center gap-2">
        <input
          type="text"
          class="form-input min-w-0 flex-1 rounded-md border-slate-300 py-1 text-xs"
          maxlength="80"
          :value="row.label"
          :placeholder="fieldFor(row.field).label"
          :aria-label="t('site.listFilter.label')"
          @change="update(index, { label: $event.target.value.trim() })"
        />
        <label v-if="fieldFor(row.field).kind === 'options'" class="flex shrink-0 cursor-pointer items-center gap-1 text-[11px] text-slate-600" :title="t('site.listFilter.multipleHint')">
          <input type="checkbox" class="form-checkbox h-3.5 w-3.5 rounded border-slate-300 text-theme-600" :checked="row.multiple" @change="update(index, { multiple: $event.target.checked })" />
          {{ t("site.listFilter.multiple") }}
        </label>
      </div>
    </div>

    <select
      v-if="rows.length < MAX_FILTERS && available.length"
      class="form-select w-full rounded-lg border-dashed border-slate-300 py-1.5 text-xs text-slate-600"
      :aria-label="t('site.listFilter.add')"
      value=""
      @change="add($event)"
    >
      <option value="" disabled>{{ t("site.listFilter.add") }}</option>
      <!-- A list of several types: fields every type has, then each type's own. -->
      <template v-if="availableGroups.length > 1">
        <optgroup v-for="group in availableGroups" :key="group.key" :label="group.label">
          <option v-for="field in group.items" :key="field.value" :value="field.value">{{ field.label }} · {{ t(`site.listFilter.kind.${field.kind}`) }}</option>
        </optgroup>
      </template>
      <template v-else>
        <option v-for="field in available" :key="field.value" :value="field.value">{{ field.label }} · {{ t(`site.listFilter.kind.${field.kind}`) }}</option>
      </template>
    </select>
    <p v-else-if="!fields.length" class="text-xs text-slate-500">{{ t("site.listFilter.noFields") }}</p>
  </div>
</template>

<script setup>
import { computed } from "vue";
import { Icon } from "@iconify/vue";
import { useI18n } from "../../../i18n/index.js";
import { filterFields } from "./listFilters.js";
import { fieldGroups } from "../../../composables/smartValues.js";

/** Same bound as ListFilters::MAX_FILTERS. */
const MAX_FILTERS = 8;
const KIND_ICONS = {
  toggle: "mdi:toggle-switch-outline",
  options: "mdi:tag-multiple-outline",
  search: "mdi:magnify",
  range: "mdi:arrow-expand-horizontal",
};

const props = defineProps({
  id: { type: String, required: true },
  label: { type: String, default: "" },
  modelValue: { type: Array, default: () => [] },
  /** The content type the list shows. */
  type: { type: Object, default: null },
});
const emit = defineEmits(["update:modelValue"]);
const { t } = useI18n();

const rows = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []).map((row) => ({ label: "", multiple: false, ...row })));
const fields = computed(() => filterFields(props.type, t));
const available = computed(() => fields.value.filter((field) => !rows.value.some((row) => row.field === field.value)));
const availableGroups = computed(() => fieldGroups(available.value.map((field) => ({ ...field, key: field.value })), props.type, t("site.builder.sharedField"))
  .filter((group) => group.items.length));

function fieldFor(key) {
  return fields.value.find((field) => field.value === key) ?? null;
}

function add(event) {
  const field = event.target.value;
  event.target.value = "";
  if (field) emit("update:modelValue", [...rows.value, { field, label: "", multiple: false }]);
}

function update(index, patch) {
  emit("update:modelValue", rows.value.map((row, i) => (i === index ? { ...row, ...patch } : row)));
}

function remove(index) {
  emit("update:modelValue", rows.value.filter((_, i) => i !== index));
}

function move(index, step) {
  const next = [...rows.value];
  [next[index], next[index + step]] = [next[index + step], next[index]];
  emit("update:modelValue", next);
}
</script>

<style scoped>
.row-action {
  display: inline-flex;
  height: 1.5rem;
  width: 1.5rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 0.375rem;
  color: rgb(var(--color-slate-400));
}
.row-action:hover:not(:disabled) {
  background: rgb(var(--color-slate-100));
  color: rgb(var(--color-slate-900));
}
.row-action:disabled {
  opacity: 0.3;
}
</style>
