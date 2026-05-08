<template>
  <div class="flex items-center gap-2 text-slate-400 text-sm py-2">
    <Icon icon="mdi:loading" class="animate-spin w-4 h-4 shrink-0" />
    <span>{{ label }}</span>
  </div>
</template>

<script setup>
import { Icon } from "@iconify/vue";
defineProps({
  label: { type: String, default: "Loading…" },
});
</script>
