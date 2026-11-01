<template>
  <button
    v-bind="$attrs"
    ref="trigger"
    type="button"
    role="combobox"
    aria-haspopup="listbox"
    :aria-label="`${label}: ${optionLabel(modelValue)}`"
    :aria-expanded="open"
    :aria-controls="open ? listId : undefined"
    :aria-activedescendant="open ? `${listId}-${activeIndex}` : undefined"
    :title="title ?? `${label}: ${optionLabel(modelValue)}`"
    class="flex h-7 w-9 items-center justify-center rounded-[5px] text-[11px] font-semibold hover:bg-theme-50 hover:text-theme-700 hover:ring-1 hover:ring-theme-300 focus-visible:bg-theme-50 focus-visible:text-theme-700 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-theme-300 aria-expanded:bg-theme-50 aria-expanded:text-theme-700 aria-expanded:outline-none aria-expanded:ring-1 aria-expanded:ring-theme-300"
    :class="modelValue ? 'text-slate-700' : 'text-slate-400'"
    @click="open ? close() : show()"
    @keydown="onKeydown"
    @blur="close()"
  >{{ shortLabel(modelValue) }}</button>
  <Teleport to="body">
    <div v-if="open" ref="popup" class="fixed z-[1000] overflow-y-auto overscroll-contain rounded-[10px] border border-slate-200 bg-[rgb(var(--color-surface))] p-[5px] text-slate-700 shadow-[0_12px_28px_-6px_rgb(0_0_0_/_0.18),0_3px_8px_-3px_rgb(0_0_0_/_0.1)]" :style="popupStyle">
      <p :id="`${listId}-label`" class="px-[7px] pb-2 pt-[5px] text-[10px] font-medium leading-4 text-slate-500">{{ label }}</p>
      <div :id="listId" role="listbox" :aria-labelledby="`${listId}-label`">
        <button
          v-for="(value, index) in options"
          :id="`${listId}-${index}`"
          :key="value"
          type="button"
          role="option"
          tabindex="-1"
          :aria-selected="modelValue === value"
          class="flex h-[30px] w-full items-center gap-2 rounded-[5px] px-[7px] text-left text-[11px] leading-4 aria-selected:bg-theme-50 aria-selected:text-theme-700"
          :class="{ 'bg-slate-100': activeIndex === index, 'mb-[5px]': value === '' }"
          @pointermove="activeIndex = index"
          @pointerdown.prevent
          @click="select(value)"
        >
          <span v-if="value === ''" class="flex-1">{{ emptyLabel || t('site.option.default') }}</span>
          <template v-else>
            <span class="flex-1 font-semibold">{{ shortLabel(value) }}</span>
            <span v-if="pixelValue(value) !== undefined" class="flex gap-0.5 tabular-nums text-slate-500">{{ pixelValue(value) }}<span class="text-[10px] text-slate-400">px</span></span>
          </template>
          <svg v-if="modelValue === value" viewBox="0 0 16 16" fill="none" class="h-3 w-3 shrink-0 text-theme-600" aria-hidden="true"><path d="m3 8 3 3 7-7" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" /></svg>
          <span v-else class="h-3 w-3 shrink-0" aria-hidden="true"></span>
        </button>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useSiteStore } from '../../../stores/site.js';
import { useI18n } from '../../../i18n/index.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, required: true },
  label: { type: String, required: true },
  title: { type: String, default: undefined },
  emptyLabel: { type: String, default: '' },
  emptyShortLabel: { type: String, default: '–' },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();
const site = useSiteStore();
const theme = computed(() => site.draft?.theme ?? site.data?.theme);
const labels = { none: '0', xs: 'XS', sm: 'S', md: 'M', lg: 'L', xl: 'XL', '2xl': '2XL' };
const shortLabel = (value) => value === '' ? props.emptyShortLabel : labels[value] ?? value;
const pixelValue = (value) => value === 'none' ? 0 : theme.value?.space?.[value];
const listId = `spacing-options-${useId()}`;
const open = ref(false);
const activeIndex = ref(0);
const trigger = ref(null);
const popup = ref(null);
const popupStyle = ref({});

function optionLabel(value) {
  if (value === '') return props.emptyLabel || t('site.option.default');
  if (value === 'none') return '0';
  const pixels = pixelValue(value);
  return pixels === undefined ? shortLabel(value) : `${shortLabel(value)} · ${pixels}px`;
}

function close(restoreFocus = false) {
  open.value = false;
  if (restoreFocus) trigger.value?.focus({ preventScroll: true });
}

async function show() {
  activeIndex.value = Math.max(0, props.options.indexOf(props.modelValue));
  popupStyle.value = { visibility: 'hidden', width: `${Math.min(150, window.innerWidth - 16)}px`, maxHeight: `${window.innerHeight - 16}px` };
  open.value = true;
  await nextTick();
  if (!popup.value || !open.value) return;
  const anchor = trigger.value.getBoundingClientRect();
  const panel = popup.value.getBoundingClientRect();
  const below = window.innerHeight - anchor.bottom - 14;
  const above = anchor.top - 14;
  const placeBelow = below >= panel.height || below >= above;
  const height = Math.min(panel.height, Math.max(0, placeBelow ? below : above));
  popupStyle.value = {
    width: `${panel.width}px`,
    left: `${Math.max(8, Math.min(anchor.left + anchor.width / 2 - panel.width / 2, window.innerWidth - panel.width - 8))}px`,
    top: `${placeBelow ? anchor.bottom + 6 : anchor.top - height - 6}px`,
    maxHeight: `${height}px`,
  };
  await nextTick();
  if (open.value) revealActive();
}

function select(value) {
  emit('update:modelValue', value);
  close(true);
}

function revealActive() {
  popup.value?.querySelector(`[id="${listId}-${activeIndex.value}"]`)?.scrollIntoView({ block: 'nearest' });
}

async function onKeydown(event) {
  // Keep the builder's save shortcut; navigation and deletion stay in the picker.
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') return;
  event.stopPropagation();
  if (event.altKey || event.ctrlKey || event.metaKey) return;
  if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
    event.preventDefault();
    if (!open.value) await show();
    else if (event.key === 'ArrowDown') activeIndex.value = (activeIndex.value + 1) % props.options.length;
    else if (event.key === 'ArrowUp') activeIndex.value = (activeIndex.value - 1 + props.options.length) % props.options.length;
    if (event.key === 'Home') activeIndex.value = 0;
    if (event.key === 'End') activeIndex.value = props.options.length - 1;
    await nextTick();
    revealActive();
  } else if (open.value && ['Enter', ' '].includes(event.key)) {
    event.preventDefault();
    select(props.options[activeIndex.value]);
  } else if (open.value && event.key === 'Escape') {
    event.preventDefault();
    close(true);
  } else if (event.key === 'Tab') close();
}

function onPointerDown(event) {
  if (!popup.value?.contains(event.target) && !trigger.value?.contains(event.target)) close();
}
function onScroll(event) {
  if (!popup.value?.contains(event.target)) close();
}
function onResize() { close(); }
watch(() => props.options, () => close());
onMounted(() => {
  document.addEventListener('pointerdown', onPointerDown);
  document.addEventListener('scroll', onScroll, true);
  window.addEventListener('resize', onResize);
});
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onPointerDown);
  document.removeEventListener('scroll', onScroll, true);
  window.removeEventListener('resize', onResize);
});
</script>
