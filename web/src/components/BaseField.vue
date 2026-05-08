<template>
  <div class="group/field">
    <div class="form-label flex items-center gap-2" :title="name">
      <span
        class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-theme-100 shrink-0"
      >
        <Icon
          :icon="fieldTypeIcon(config.type)"
          class="w-3.5 h-3.5 text-theme-600"
        />
      </span>
      <span>{{ config.label || name }}</span>
      <span v-if="config.required" class="text-red-500 ml-0.5">*</span>
      <span
        v-if="config.localized === false"
        class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-sky-50 text-sky-700 ring-1 ring-sky-100"
        title="Same value for all languages"
        aria-label="Same value for all languages"
      >
        <Icon icon="mdi:web" class="h-3.5 w-3.5" />
      </span>
    </div>
    <p v-if="config.description" class="text-xs text-slate-500 mb-1.5">
      {{ config.description }}
    </p>
    <fieldset
      :disabled="readonly"
      :class="readonly ? 'pointer-events-none opacity-60' : ''"
    >
      <slot />
    </fieldset>
    <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
  </div>
</template>

<script setup>
import { Icon } from "@iconify/vue";
import { useFieldTypeMeta } from "../composables/useFieldTypeMeta.js";

defineProps({
  name: { type: String, required: true },
  config: { type: Object, required: true },
  error: { type: String, default: "" },
  readonly: { type: Boolean, default: false },
});

const { fieldTypeIcon } = useFieldTypeMeta();
</script>
