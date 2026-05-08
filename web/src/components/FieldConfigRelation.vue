<template>
  <div class="grid grid-cols-2 gap-3">
    <div>
      <label class="text-xs text-slate-500 block mb-1">Target post type</label>
      <SearchableSelect
        :options="contentTypes.map((t) => ({ value: t.name, label: t.label }))"
        v-model="field.target"
        placeholder="Choose post type"
      />
    </div>

    <label class="inline-flex items-end gap-2 h-full pb-2">
      <input
        type="checkbox"
        v-model="field.multiple"
        class="form-checkbox rounded border-slate-300 text-theme-600"
      />
      <span class="text-sm text-slate-600">Allow multiple</span>
    </label>
  </div>
</template>

<script setup>
import SearchableSelect from "./SearchableSelect.vue";

defineProps({
  field: { type: Object, required: true },
  contentTypes: { type: Array, default: () => [] },
});
</script>
