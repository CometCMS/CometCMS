<template>
  <div class="builder-outline p-2">
    <ul v-if="builder.nodes.value.length" role="tree" :aria-label="t('site.builder.outline')">
      <BuilderOutlineNode
        v-for="(node, index) in builder.nodes.value"
        :key="node.id"
        :node="node"
        :depth="0"
        :first="index === 0"
        :last="index === builder.nodes.value.length - 1"
      />
    </ul>
    <p v-else class="px-2 py-6 text-center text-xs text-slate-500">{{ t("site.builder.emptyOutline") }}</p>

    <div
      v-if="builder.drag.value"
      class="mt-2 rounded-lg border-2 border-dashed px-3 py-3 text-center text-xs transition-colors"
      :class="overEnd ? 'border-theme-500 bg-theme-50 text-theme-700' : 'border-slate-300 text-slate-400'"
      @dragover.prevent="overEnd = true"
      @dragleave="overEnd = false"
      @drop.prevent="dropAtEnd"
    >
      {{ t("site.builder.dropAtEnd") }}
    </div>
  </div>
</template>

<script setup>
import { ref } from "vue";
import BuilderOutlineNode from "./BuilderOutlineNode.vue";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useI18n } from "../../../i18n/index.js";

const builder = useSiteBuilder();
const { t } = useI18n();
const overEnd = ref(false);

function dropAtEnd() {
  overEnd.value = false;
  builder.dropAt(null, "inside");
}
</script>

<style scoped>
.builder-outline {
  --outline-guide-opacity: 0;
}
.builder-outline:hover {
  --outline-guide-opacity: 1;
}
</style>
