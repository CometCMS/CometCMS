<template>
  <div class="relative h-56 rounded-[10px] border border-slate-200 bg-slate-100/75" role="group" :aria-label="t('site.styleGroup.spacing')">
    <span class="pointer-events-none absolute left-[9px] top-[3px] text-[10px] leading-[14px] text-slate-500">{{ t('site.spacing.margin') }}</span>
    <div class="absolute inset-x-[34px] inset-y-[42px] rounded-md border border-dashed border-slate-300 bg-[rgb(var(--color-surface))]">
      <span class="pointer-events-none absolute left-[9px] top-[7px] text-[10px] leading-[14px] text-slate-500">{{ t('site.spacing.padding') }}</span>
      <div class="absolute inset-x-[35px] inset-y-[47px] min-w-3 rounded border border-slate-200 bg-slate-50" aria-hidden="true"></div>
    </div>
    <button
      v-if="linkable"
      type="button"
      :aria-pressed="linked"
      :aria-label="t('site.spacing.link')"
      :title="t(linked ? 'site.spacing.unlink' : 'site.spacing.link')"
      class="absolute left-1/2 top-1/2 flex h-7 w-7 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-[5px] text-slate-400 hover:bg-theme-50 hover:text-theme-700 focus-visible:bg-theme-50 focus-visible:text-theme-700 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-theme-300 aria-pressed:text-theme-600"
      @click="linked = !linked"
    >
      <svg v-if="linked" viewBox="0 0 16 16" fill="none" class="h-3.5 w-3.5" aria-hidden="true"><path d="M6.5 9.5 9.5 6.5M7 4.5l1-1a2.83 2.83 0 0 1 4 4l-1 1M9 11.5l-1 1a2.83 2.83 0 0 1-4-4l1-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
      <svg v-else viewBox="0 0 16 16" fill="none" class="h-3.5 w-3.5" aria-hidden="true"><path d="M7 4.5l1-1a2.83 2.83 0 0 1 4 4l-1 1M9 11.5l-1 1a2.83 2.83 0 0 1-4-4l1-1M2.5 2.5l2 2M13.5 13.5l-2-2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>
    <SpacingTokenSelect
      v-for="control in visibleControls"
      :id="`builder-${node.id}-style-${control.key}`"
      :key="`${node.id}-${control.key}`"
      :model-value="control.value"
      :options="control.options"
      :label="control.label"
      :title="control.own ? `${control.label}: ${optionLabel(control.value)} (${t(`site.device.${builder?.device.value}`)})` : undefined"
      class="absolute -translate-x-1/2 -translate-y-1/2"
      :class="[positionClasses[control.key], { 'text-theme-700 underline decoration-dotted underline-offset-2': control.own }]"
      @update:model-value="update(control, $event)"
    />
  </div>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import SpacingTokenSelect from "./SpacingTokenSelect.vue";
import { useSiteStore } from "../../../stores/site.js";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useI18n } from "../../../i18n/index.js";

const props = defineProps({
  node: { type: Object, required: true },
  spec: { type: Object, required: true },
  keys: { type: Array, required: true },
  /** The value shown for a key, e.g. on the previewed device; defaults to the node's own. */
  valueFor: { type: Function, default: null },
  /** Whether a key has its own value on the previewed device. */
  overridden: { type: Function, default: () => false },
});
const emit = defineEmits(["update"]);
const { t } = useI18n();
const site = useSiteStore();
const builder = useSiteBuilder();
const theme = computed(() => site.draft?.theme ?? site.data?.theme);
const labels = { '': '–', none: '0', xs: 'XS', sm: 'S', md: 'M', lg: 'L', xl: 'XL', '2xl': '2XL' };
const shortLabel = (value) => labels[value] ?? value;
const positionClasses = {
  margin_top: 'left-1/2 top-[30px]',
  margin_right: 'left-[calc(100%-18px)] top-1/2',
  margin_bottom: 'left-1/2 top-[calc(100%-22px)]',
  margin_left: 'left-[18px] top-1/2',
  padding_top: 'left-1/2 top-20',
  padding_right: 'left-[calc(100%-53px)] top-1/2',
  padding_bottom: 'left-1/2 top-[calc(100%-67px)]',
  padding_left: 'left-[53px] top-1/2',
};

const controls = computed(() => ["margin", "padding"].flatMap((group) =>
  ["top", "right", "bottom", "left"].flatMap((side) => {
    const key = `${group}_${side}`;
    if (!props.keys.includes(key) || !props.spec[key]) return [];
    const value = (props.valueFor ? props.valueFor(key) : props.node.style?.[key]) || '';
    return [{ key, group, side, value, own: props.overridden(key), options: props.spec[key].options ?? [], label: t(`site.style.${key}`) }];
  }),
));

const sidesOf = (group) => controls.value.filter((control) => control.group === group);
// Linked: one control per group sets every side; it stays on the left edge.
const linkable = computed(() => ["margin", "padding"].some((group) => sidesOf(group).length > 1));
const sidesDiffer = () => ["margin", "padding"].some((group) => new Set(sidesOf(group).map((control) => control.value)).size > 1);
// On by default, unless the element already has differing sides that linking would hide.
const linked = ref(!sidesDiffer());
watch(() => props.node.id, () => { linked.value = !sidesDiffer(); });

const visibleControls = computed(() => {
  if (!linked.value) return controls.value;
  return ["margin", "padding"].flatMap((group) => {
    const sides = sidesOf(group);
    if (sides.length < 2) return sides;
    const lead = sides.find((control) => control.side === "left") ?? sides[0];
    return [{ ...lead, keys: sides.map((control) => control.key), own: sides.some((control) => control.own), label: t(`site.spacing.${group}`) }];
  });
});

function update(control, value) {
  const keys = control.keys ?? [control.key];
  emit('update', Object.fromEntries(keys.map((key) => [key, value])));
}

function optionLabel(value) {
  if (value === '') return t('site.option.default');
  if (value === 'none') return '0';
  const pixels = theme.value?.space?.[value];
  return pixels === undefined ? shortLabel(value) : `${shortLabel(value)} · ${pixels}px`;
}
</script>
