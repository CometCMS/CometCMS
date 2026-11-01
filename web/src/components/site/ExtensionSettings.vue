<template>
  <form class="space-y-3 rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200" @submit.prevent="$emit('save', { ...values })">
    <template v-for="key in visibleKeys" :key="key">
      <div class="space-y-1">
        <label v-if="specs[key].type !== 'bool'" :for="`${idPrefix}-${key}`" class="block text-xs font-medium text-slate-700">{{ text(specs[key], "label") }}</label>
        <select v-if="specs[key].type === 'enum'" :id="`${idPrefix}-${key}`" v-model="values[key]" :disabled="disabled" class="form-select w-full rounded-lg border-slate-300 py-1.5 text-sm">
          <option v-for="option in specs[key].options" :key="option" :value="option">{{ optionText(specs[key], option, locale) }}</option>
        </select>
        <select v-else-if="specs[key].type === 'color'" :id="`${idPrefix}-${key}`" v-model="values[key]" :disabled="disabled" class="form-select w-full rounded-lg border-slate-300 py-1.5 text-sm">
          <option value="">{{ t("site.option.none") }}</option>
          <option v-for="color in COLORS" :key="color" :value="color">{{ color }}</option>
        </select>
        <label v-else-if="specs[key].type === 'bool'" class="flex items-center gap-2 text-sm text-slate-700">
          <input :id="`${idPrefix}-${key}`" v-model="values[key]" type="checkbox" :disabled="disabled" class="form-checkbox rounded border-slate-300 text-theme-600" />
          {{ text(specs[key], "label") }}
        </label>
        <input v-else-if="specs[key].type === 'int'" :id="`${idPrefix}-${key}`" v-model.number="values[key]" type="number" :min="specs[key].min" :max="specs[key].max" :disabled="disabled" class="form-input w-full rounded-lg border-slate-300 py-1.5 text-sm" />
        <textarea v-else-if="specs[key].type === 'text'" :id="`${idPrefix}-${key}`" v-model="values[key]" rows="3" :maxlength="specs[key].max" :placeholder="text(specs[key], 'placeholder')" :disabled="disabled" class="form-textarea w-full rounded-lg border-slate-300 text-sm"></textarea>
        <input v-else :id="`${idPrefix}-${key}`" v-model="values[key]" :type="specs[key].type === 'url' ? 'url' : 'text'" :maxlength="specs[key].max" :placeholder="text(specs[key], 'placeholder')" :disabled="disabled" class="form-input w-full rounded-lg border-slate-300 py-1.5 text-sm" :class="{ 'font-mono': specs[key].type === 'url' }" />
        <p v-if="specs[key].help" class="text-xs text-slate-500">{{ text(specs[key], "help") }}</p>
      </div>
    </template>
    <div class="flex justify-end">
      <button type="submit" class="btn-primary btn-sm" :disabled="disabled">{{ t("site.extensions.saveSettings") }}</button>
    </div>
  </form>
</template>

<script setup>
import { computed, reactive, useId, watch } from "vue";
import { conditionsHold, extensionText, optionText } from "../../composables/extensionText.js";
import { useI18n } from "../../i18n/index.js";

/** Theme colors a color setting offers; workspaces may add their own in the designer. */
const COLORS = ["primary", "secondary", "accent", "background", "surface", "text", "muted", "border"];

const props = defineProps({
  /** The extension's setting specs, as compiled from comet-extension.json. */
  specs: { type: Object, required: true },
  modelValue: { type: Object, default: () => ({}) },
  disabled: { type: Boolean, default: false },
});
defineEmits(["save"]);
const { t, locale } = useI18n();
const idPrefix = `extension-setting-${useId()}`;
const values = reactive({});
watch(() => props.modelValue, (current) => Object.assign(values, structuredClone({ ...current })), { immediate: true, deep: true });
const text = (spec, field) => extensionText(spec, field, locale.value);
const visibleKeys = computed(() => Object.keys(props.specs).filter((key) => props.specs[key].deprecated === undefined && conditionsHold(props.specs[key], values)));
</script>
