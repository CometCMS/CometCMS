<template>
  <AppDialog :model-value="modelValue" as="form" :title="title" :subtitle="subtitle" icon="mdi:cog-outline" :busy="disabled" @update:model-value="$emit('update:modelValue', $event)" @submit="$emit('save', { ...values })">
    <div v-if="error" role="alert" class="mb-4 space-y-2 rounded-lg bg-red-50 p-3 text-sm text-red-700">
      <p>{{ error.message }}</p>
      <ul v-if="error.details?.length" class="list-disc space-y-1 pl-5 font-mono text-xs">
        <li v-for="(issue, index) in error.details" :key="index">{{ issue.message }}</li>
      </ul>
    </div>
    <div class="space-y-4">
      <template v-for="key in visibleKeys" :key="key">
        <div>
          <label v-if="specs[key].type !== 'bool'" :for="`${idPrefix}-${key}`" class="form-label">{{ text(specs[key], "label") }}</label>
          <select v-if="specs[key].type === 'enum'" :id="`${idPrefix}-${key}`" v-model="values[key]" :disabled="disabled" class="form-select w-full rounded-lg border-slate-300 text-sm">
            <option v-for="option in specs[key].options" :key="option" :value="option">{{ optionText(specs[key], option, locale) }}</option>
          </select>
          <select v-else-if="specs[key].type === 'color'" :id="`${idPrefix}-${key}`" v-model="values[key]" :disabled="disabled" class="form-select w-full rounded-lg border-slate-300 text-sm">
            <option value="">{{ t("site.option.none") }}</option>
            <option v-for="color in COLORS" :key="color" :value="color">{{ color }}</option>
          </select>
          <label v-else-if="specs[key].type === 'bool'" class="flex items-center gap-2 text-sm text-slate-700">
            <input :id="`${idPrefix}-${key}`" v-model="values[key]" type="checkbox" :disabled="disabled" class="form-checkbox rounded border-slate-300 text-theme-600" />
            {{ text(specs[key], "label") }}
          </label>
          <input v-else-if="specs[key].type === 'int'" :id="`${idPrefix}-${key}`" v-model.number="values[key]" type="number" :min="specs[key].min" :max="specs[key].max" :disabled="disabled" class="form-input w-full rounded-lg border-slate-300 text-sm" />
          <textarea v-else-if="specs[key].type === 'text'" :id="`${idPrefix}-${key}`" v-model="values[key]" rows="3" :maxlength="specs[key].max" :placeholder="text(specs[key], 'placeholder')" :disabled="disabled" class="form-textarea w-full rounded-lg border-slate-300 text-sm"></textarea>
          <input v-else :id="`${idPrefix}-${key}`" v-model="values[key]" :type="specs[key].type === 'url' ? 'url' : 'text'" :maxlength="specs[key].max" :placeholder="text(specs[key], 'placeholder')" :disabled="disabled" class="form-input w-full rounded-lg border-slate-300 text-sm" :class="{ 'font-mono': specs[key].type === 'url' }" />
          <p v-if="specs[key].help" class="form-hint">{{ text(specs[key], "help") }}</p>
        </div>
      </template>
    </div>
    <template #footer="{ close }">
      <button type="button" class="btn-secondary" :disabled="disabled" @click="close">{{ t("common.cancel") }}</button>
      <button type="submit" class="btn-primary" :disabled="disabled">{{ disabled ? t("common.saving") : t("site.extensions.saveSettings") }}</button>
    </template>
  </AppDialog>
</template>

<script setup>
import { computed, reactive, useId, watch } from "vue";
import AppDialog from "../AppDialog.vue";
import { conditionsHold, extensionText, optionText } from "../../composables/extensionText.js";
import { useI18n } from "../../i18n/index.js";

/** Theme colors a color setting offers; workspaces may add their own in the designer. */
const COLORS = ["primary", "secondary", "accent", "background", "surface", "text", "muted", "border"];

const props = defineProps({
  /** The extension's setting specs, as compiled from comet-extension.json. */
  specs: { type: Object, required: true },
  /** The stored values; the draft resets to these each time the dialog opens. */
  stored: { type: Object, default: () => ({}) },
  modelValue: { type: Boolean, required: true },
  title: { type: String, required: true },
  subtitle: { type: String, default: "" },
  /** A failed save, shown inside the dialog: { message, details }. */
  error: { type: Object, default: null },
  disabled: { type: Boolean, default: false },
});
defineEmits(["update:modelValue", "save"]);
const { t, locale } = useI18n();
const idPrefix = `extension-setting-${useId()}`;
const values = reactive({});
watch(() => props.modelValue, (open) => {
  if (!open) return;
  for (const key of Object.keys(values)) delete values[key];
  Object.assign(values, structuredClone({ ...props.stored }));
}, { immediate: true });
const text = (spec, field) => extensionText(spec, field, locale.value);
const visibleKeys = computed(() => Object.keys(props.specs).filter((key) => props.specs[key].deprecated === undefined && conditionsHold(props.specs[key], values)));
</script>
