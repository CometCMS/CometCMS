<template>
  <div ref="container" class="relative" @focusout="onFocusOut" @keydown.esc.stop.prevent="close">
    <button
      :id="id"
      ref="trigger"
      type="button"
      aria-haspopup="dialog"
      :aria-expanded="open"
      :aria-controls="`${id}-palette`"
      :aria-label="`${label}: ${selectedLabel}`"
      class="flex w-full items-center gap-2 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-left text-xs text-slate-700 hover:border-theme-400"
      @click="open = !open"
    >
      <span v-if="selected" class="h-5 w-5 shrink-0 rounded-full ring-1 ring-black/15" :style="{ backgroundColor: selected.swatch }"></span>
      <Icon v-else icon="mdi:circle-off-outline" class="h-5 w-5 shrink-0 text-slate-400" />
      <span class="min-w-0 flex-1 truncate">{{ selectedLabel }}</span>
      <Icon icon="mdi:chevron-up" class="h-4 w-4 text-slate-400" />
    </button>
    <div
      v-if="open"
      :id="`${id}-palette`"
      role="dialog"
      :aria-label="label"
      class="absolute bottom-full left-0 z-50 mb-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white p-3 shadow-lg"
    >
      <button v-if="allowEmpty" type="button" class="mb-3 flex w-full items-center gap-2 rounded px-1 py-1 text-left text-xs text-slate-600 hover:bg-slate-50" :aria-pressed="!modelValue" @click="select('')">
        <Icon icon="mdi:circle-off-outline" class="h-4 w-4" />
        {{ emptyLabel || t('site.option.default') }}
      </button>
      <section v-for="group in groups" :key="group.label" class="mb-3 last:mb-0">
        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ group.label }}</p>
        <div class="grid grid-cols-6 gap-2">
          <button
            v-for="option in group.options"
            :key="option.value"
            type="button"
            :aria-label="`${option.label} (${option.swatch})`"
            :aria-pressed="modelValue === option.value"
            :title="`${option.label} · ${option.swatch}`"
            class="flex aspect-square items-center justify-center rounded-md border border-black/15 shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-theme-600"
            :class="{ 'ring-2 ring-theme-600 ring-offset-2': modelValue === option.value }"
            :style="{ backgroundColor: option.swatch }"
            @click="select(option.value)"
          >
            <Icon v-if="modelValue === option.value" icon="mdi:check" class="h-4 w-4 rounded-full bg-white text-slate-900 shadow-sm" />
          </button>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Icon } from "@iconify/vue";
import { useI18n } from "../../../i18n/index.js";

const props = defineProps({
  id: { type: String, required: true },
  label: { type: String, required: true },
  modelValue: { type: String, default: "" },
  options: { type: Array, default: () => [] },
  allowEmpty: { type: Boolean, default: true },
  emptyLabel: { type: String, default: "" },
});
const emit = defineEmits(["update:modelValue"]);
const { t } = useI18n();
const container = ref(null);
const trigger = ref(null);
const open = ref(false);
const selected = computed(() => props.options.find((option) => option.value === props.modelValue));
const selectedLabel = computed(() => selected.value?.label ?? (props.modelValue ? `${props.modelValue} (${t('site.builder.missing')})` : props.emptyLabel || t('site.option.default')));
const groups = computed(() => [
  { label: t("site.theme.colors"), options: props.options.filter((option) => !option.custom) },
  { label: t("site.theme.customColors"), options: props.options.filter((option) => option.custom) },
].filter((group) => group.options.length));

function close() {
  open.value = false;
  trigger.value?.focus();
}

function select(value) {
  emit("update:modelValue", value);
  close();
}

function onFocusOut(event) {
  if (!container.value?.contains(event.relatedTarget)) open.value = false;
}

function onPointerDown(event) {
  if (!container.value?.contains(event.target)) open.value = false;
}

onMounted(() => document.addEventListener("pointerdown", onPointerDown));
onBeforeUnmount(() => document.removeEventListener("pointerdown", onPointerDown));
</script>
