<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    :disabled="disabled"
    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-theme-600 disabled:cursor-not-allowed disabled:opacity-50"
    :class="modelValue ? 'bg-theme-600' : 'bg-slate-300'"
    @click="$emit('update:modelValue', !modelValue)"
  >
    <span
      class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm ring-0 transition-transform duration-200"
      :class="modelValue ? 'translate-x-6' : 'translate-x-1'"
    />
  </button>
</template>

<script setup>
defineProps({
  modelValue: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});
defineEmits(["update:modelValue"]);
</script>
