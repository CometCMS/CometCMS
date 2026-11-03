<template>
  <!-- Package icons are images; as a mask they take the surrounding text color like the built-in icons. -->
  <span v-if="isImage" aria-hidden="true" class="inline-block bg-current" :style="maskStyle"></span>
  <Icon v-else :icon="icon" />
</template>

<script setup>
import { computed } from "vue";
import { Icon } from "@iconify/vue";

const props = defineProps({
  /** An Iconify name, or a data URI from a block package. */
  icon: { type: String, required: true },
});

const isImage = computed(() => props.icon.startsWith("data:image/"));
const maskStyle = computed(() => {
  const image = `url("${props.icon}")`;
  return { maskImage: image, WebkitMaskImage: image, mask: `${image} center / contain no-repeat`, WebkitMask: `${image} center / contain no-repeat` };
});
</script>
