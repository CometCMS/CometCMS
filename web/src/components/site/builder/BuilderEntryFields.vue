<template>
  <div ref="root" class="space-y-4 p-3">
    <div v-if="state.record.value?.loading" class="flex justify-center py-8"><LoadingSpinner /></div>
    <div v-else-if="state.record.value?.error" role="alert" class="space-y-3 text-sm text-red-600">
      <p>{{ state.record.value.error }}</p>
      <button type="button" class="btn-secondary btn-sm" @click="state.load()">{{ t("site.builder.entryFieldsRetry") }}</button>
    </div>
    <template v-else-if="state.draft.value">
      <div class="flex items-start gap-2.5 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-200">
        <Icon icon="mdi:file-document-outline" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
        <div class="min-w-0">
          <p class="break-words text-[13px] font-semibold text-slate-800">{{ state.record.value.entry.title || state.record.value.identifier }}</p>
          <p class="text-[11px] leading-snug text-slate-500">{{ t("site.builder.entryFieldsHint") }}</p>
        </div>
      </div>
      <p v-if="state.readonly.value" class="text-xs text-slate-500">{{ t("contentEdit.readOnlyDescription") }}</p>
      <p v-if="state.draft.value.error" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ state.draft.value.error }}</p>
      <fieldset :disabled="busy" class="min-w-0 space-y-4">
        <BaseField
          v-for="(config, name) in state.fields.value"
          :key="name"
          :data-field="name"
          :class="['rounded-md transition-[box-shadow,background-color] duration-700', highlighted === name && 'bg-theme-50/60 ring-1 ring-theme-200 ring-offset-2']"
          :name="name"
          :config="fieldConfig(name, config)"
          :locale="state.activeLocale.value"
          :error="state.draft.value.errors[name]"
          :readonly="state.fieldReadonly(name)"
          :draggable-label="!busy && !builder.translating.value"
          :drag-hint="t('site.builder.entryFieldDragHint')"
          compact
          @label-dragstart="startFieldDrag($event, name, config)"
          @label-dragend="endPaletteDrag(builder)"
        >
          <TranslationInfo v-if="config.localized !== false" :value="state.record.value.entry.values?.[name]" :locale="state.activeLocale.value" :default-locale="state.defaultLocale.value" :readonly="state.fieldReadonly(name)" @reset="state.reset(name)" />
          <FieldInput
            :name="name"
            :config="config"
            :locale="state.activeLocale.value"
            :model-value="state.valueFor(name)"
            :readonly="state.fieldReadonly(name)"
            @update:model-value="state.update(name, $event)"
          />
        </BaseField>
      </fieldset>
    </template>
  </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from "vue";
import { Icon } from "@iconify/vue";
import TranslationInfo from "../../TranslationInfo.vue";
import BaseField from "../../BaseField.vue";
import FieldInput from "../../FieldInput.vue";
import LoadingSpinner from "../../LoadingSpinner.vue";
import { useI18n } from "../../../i18n/index.js";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { fieldShortcut } from "../../../composables/smartValues.js";
import { startPaletteDrag, endPaletteDrag } from "../../../composables/builderPaletteDrag.js";

const props = defineProps({
  state: { type: Object, required: true },
  busy: { type: Boolean, default: false },
  /** A field to bring into view and focus, as a new `{ name }` per request. */
  reveal: { type: Object, default: null },
});
const { t } = useI18n();
const builder = useSiteBuilder();
const root = ref(null);
const highlighted = ref("");
let revealed = null;
let highlightTimer = null;

// Waits for the entry to load, so a request made while opening the panel still lands.
watch([() => props.reveal, () => props.state.draft.value], async ([request, draft]) => {
  if (!request || !draft || request === revealed) return;
  revealed = request;
  await nextTick();
  const field = root.value?.querySelector(`[data-field="${CSS.escape(request.name)}"]`);
  if (!field) return;
  field.scrollIntoView({ block: "nearest", behavior: "smooth" });
  // The value's own input before buttons such as the translation reset.
  const input = field.querySelector('input:not([type="hidden"]):not(:disabled), textarea:not(:disabled), select:not(:disabled), [contenteditable="true"]')
    ?? field.querySelector("fieldset button:not(:disabled)");
  input?.focus({ preventScroll: true });
  highlighted.value = request.name;
  clearTimeout(highlightTimer);
  highlightTimer = setTimeout(() => { highlighted.value = ""; }, 1500);
}, { immediate: true, flush: "post" });
onBeforeUnmount(() => clearTimeout(highlightTimer));

function fieldConfig(name, config) {
  return name === "title" && !config.label ? { ...config, label: t("contentEdit.title") } : config;
}

function startFieldDrag(event, name, config) {
  if (props.busy) {
    event.preventDefault();
    return;
  }
  startPaletteDrag(builder, event, () => fieldShortcut({ key: name, type: config.type ?? "text", config }, builder.make));
}
</script>
