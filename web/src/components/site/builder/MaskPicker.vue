<template>
  <!-- Each mask drawn as a solid shape over the hatched box it crops away from. -->
  <div class="grid grid-cols-4 gap-1.5" role="radiogroup" :aria-label="label">
    <button
      v-for="option in options"
      :key="option"
      type="button"
      role="radio"
      :aria-checked="modelValue === option"
      :title="t(`site.mask.${option}`)"
      class="flex min-w-0 flex-col items-center gap-1 rounded-lg border p-1.5 transition-colors"
      :class="modelValue === option ? 'border-theme-300 bg-theme-50 text-theme-800 ring-1 ring-theme-300' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50'"
      @click="emit('update:modelValue', option)"
    >
      <svg
        :viewBox="`0 0 ${PREVIEW_WIDTH} ${PREVIEW_HEIGHT}`"
        class="w-full aspect-[4/3] overflow-hidden rounded border border-slate-300 bg-white text-slate-900"
        aria-hidden="true"
        focusable="false"
      >
        <defs>
          <pattern :id="`${uid}-hatch`" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
            <line x1="0" y1="0" x2="0" y2="6" stroke="currentColor" stroke-width="1.2" />
          </pattern>
        </defs>

        <rect :width="PREVIEW_WIDTH" :height="PREVIEW_HEIGHT" :fill="`url(#${uid}-hatch)`" opacity="0.3" />
        <rect v-if="option === 'none'" :width="PREVIEW_WIDTH" :height="PREVIEW_HEIGHT" fill="currentColor" opacity="0.85" />
        <svg
          v-else
          :width="PREVIEW_WIDTH"
          :height="PREVIEW_HEIGHT"
          :viewBox="`0 0 ${mask.width} ${mask.height}`"
          :preserveAspectRatio="fit === 'contain' ? 'xMidYMid meet' : 'none'"
        >
          <path :d="mask.paths[option] ?? ''" :transform="maskTransform" fill="currentColor" opacity="0.85" />
        </svg>
      </svg>
      <span class="w-full truncate text-center text-[10px] font-medium">{{ t(`site.mask.${option}`) }}</span>
    </button>
  </div>
</template>

<script setup>
import { computed, useId } from "vue";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useI18n } from "../../../i18n/index.js";

/** A landscape box, so stretching and fitting look different. */
const PREVIEW_WIDTH = 80;
const PREVIEW_HEIGHT = 60;

const props = defineProps({
  options: { type: Array, required: true },
  modelValue: { type: String, default: "none" },
  label: { type: String, default: "" },
  /** The mask's other options, so its previews show it as it will look. */
  fit: { type: String, default: "stretch" },
  flipX: { type: Boolean, default: false },
  flipY: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);
const builder = useSiteBuilder();
const { t } = useI18n();
const uid = useId();

const mask = computed(() => builder.spec.value?.shapes?.mask ?? { width: 100, height: 100, paths: {} });

/** Mirrored the way the site does it (see Shapes::maskSvg()). */
const maskTransform = computed(() => {
  if (!props.flipX && !props.flipY) return undefined;
  const { width, height } = mask.value;
  return `matrix(${props.flipX ? -1 : 1} 0 0 ${props.flipY ? -1 : 1} ${props.flipX ? width : 0} ${props.flipY ? height : 0})`;
});
</script>
