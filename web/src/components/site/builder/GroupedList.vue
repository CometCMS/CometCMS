<template>
  <div>
    <section v-for="group in shown" :key="group.key" role="group" :aria-label="group.label || undefined">
      <h5
        v-if="group.label && labelled"
        class="sticky z-10 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500"
        :class="headingClass"
      >
        {{ group.label }}
      </h5>
      <div :class="listClass">
        <template v-for="item in group.items" :key="itemKey(item)"><slot :item="item" :group="group" /></template>
      </div>
    </section>
    <slot v-if="!shown.length" name="empty" />
  </div>
</template>

<script setup>
import { computed } from "vue";

/**
 * Items under sticky group headings, e.g. fields per content type. Empty
 * groups are left out, and a lone group shows no heading.
 */
const props = defineProps({
  /** [{ key, label, items }] */
  groups: { type: Array, default: () => [] },
  itemKey: { type: Function, default: (item) => item.key },
  /** Where headings stick and their background, matching the scrolling container so items pass under them. */
  headingClass: { type: [String, Array, Object], default: "top-0 bg-white" },
  listClass: { type: [String, Array, Object], default: "space-y-1" },
});

const shown = computed(() => props.groups.filter((group) => group.items?.length));
const labelled = computed(() => shown.value.length > 1);
</script>
