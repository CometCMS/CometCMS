<template>
  <label class="inline-flex items-center gap-2">
    <input
      type="checkbox"
      v-model="field.multiple"
      class="form-checkbox rounded border-slate-300 text-theme-600"
    />
    <span class="text-sm text-slate-600">Allow multiple</span>
  </label>
</template>

<script setup>
defineProps({
  field: { type: Object, required: true },
});
</script>
