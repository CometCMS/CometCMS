<template>
  <div class="space-y-4">
    <p class="text-xs text-slate-500">{{ t('site.theme.fieldsHint') }}</p>
    <div class="space-y-1.5 rounded-lg border border-slate-200 p-4 text-sm" :style="sampleStyle" aria-hidden="true">
      <span class="field-label block">{{ t('site.theme.fieldSampleLabel') }}<span class="field-required">*</span></span>
      <span class="field-sample block" :class="{ 'field-focus': focused }" @mouseenter="focused = true" @mouseleave="focused = false">{{ t('site.theme.fieldSampleValue') }}</span>
    </div>
    <div>
      <span class="form-label">{{ t('site.theme.fieldStyle') }}</span>
      <SegmentedControl v-model="fields.style" :options="STYLES.map((value) => ({ value, label: t(`site.theme.fieldStyle.${value}`) }))" />
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <span class="form-label">{{ t('site.theme.borderColor') }}</span>
        <ThemeColorPicker id="design-field-border" v-model="fields.border_color" :label="t('site.theme.borderColor')" :options="colors" :allow-empty="false" />
      </div>
      <div>
        <span class="form-label">{{ t('site.theme.fieldFocusColor') }}</span>
        <ThemeColorPicker id="design-field-focus" v-model="fields.focus_color" :label="t('site.theme.fieldFocusColor')" :options="colors" :allow-empty="false" />
      </div>
    </div>
    <RangeRow v-model="fields.border_width" :label="t('site.theme.borderWidth')" :min="0" :max="4" :step="1" unit="px" />
    <p v-if="fields.style === 'underlined' && fields.border_width === 0" class="-mt-2 text-xs text-slate-500">{{ t('site.theme.fieldUnderlineMin') }}</p>
    <div v-if="fields.style !== 'underlined'">
      <span class="form-label">{{ t('site.theme.corner') }}</span>
      <SegmentedControl v-model="fields.radius" :options="cornerOptions" />
    </div>
    <div>
      <span class="form-label">{{ t('site.theme.fieldPadding') }}</span>
      <SegmentedControl v-model="fields.padding" :options="DENSITIES.map((value) => ({ value, label: t(`site.theme.spacingOption.${value}`) }))" />
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="form-label" for="design-field-label-weight">{{ t('site.theme.fieldLabelWeight') }}</label>
        <select id="design-field-label-weight" v-model.number="fields.label_weight" class="form-select w-full rounded-lg border-slate-300 text-sm">
          <option v-for="weight in WEIGHTS" :key="weight" :value="weight">{{ weight }}</option>
        </select>
      </div>
      <div>
        <span class="form-label">{{ t('site.theme.fieldLabelTransform') }}</span>
        <SegmentedControl v-model="fields.label_transform" :options="TRANSFORMS.map(({ value, label }) => ({ value, label, title: t(`site.theme.transform.${value || 'none'}`) }))" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import RangeRow from '../RangeRow.vue';
import SegmentedControl from '../SegmentedControl.vue';
import ThemeColorPicker from './ThemeColorPicker.vue';
import { useI18n } from '../../../i18n/index.js';
import { DENSITIES, TRANSFORMS, WEIGHTS, cornerOptions as corners, cornerPx, densityFactor } from '../../../composables/themeControls.js';

/** Form fields of forms, search and list filters (Theme::fields); the sample mirrors the public stylesheet. Hover it to see focus. */
const props = defineProps({
  theme: { type: Object, required: true },
  colors: { type: Array, default: () => [] },
});
const { t } = useI18n();
const STYLES = ['outlined', 'filled', 'underlined'];
const fields = computed(() => props.theme.fields);
const cornerOptions = computed(() => corners(t));
const focused = ref(false);
const swatch = (value) => props.colors.find((option) => option.value === value)?.swatch;
const sampleStyle = computed(() => {
  const f = fields.value;
  const underlined = f.style === 'underlined';
  const pad = densityFactor(f.padding);
  return {
    backgroundColor: swatch('background'),
    color: swatch('text'),
    '--sample-bg': f.style === 'filled' ? swatch('surface') : underlined ? 'transparent' : swatch('background'),
    '--sample-border-width': underlined ? `0 0 ${Math.max(1, f.border_width)}px` : `${f.border_width}px`,
    '--sample-border-color': swatch(f.border_color) ?? '#e2e8f0',
    '--sample-focus': swatch(f.focus_color) ?? '#2563eb',
    '--sample-radius': underlined ? '0px' : cornerPx(props.theme, f.radius),
    '--sample-pad': `${0.6 * pad}em ${underlined ? 0 : 0.75 * pad}em`,
    '--sample-label-weight': f.label_weight,
    '--sample-label-transform': f.label_transform || 'none',
    '--sample-focus-ring': underlined ? 'none' : '2px solid var(--sample-focus)',
    '--sample-focus-line': underlined ? '0 1px 0 var(--sample-focus)' : 'none',
  };
});
</script>

<style scoped>
.field-label { font-size: 0.8125rem; font-weight: var(--sample-label-weight); text-transform: var(--sample-label-transform); }
.field-required { margin-left: 0.2em; color: var(--sample-focus); }
.field-sample {
  line-height: 1.4; background: var(--sample-bg); border: 0 solid var(--sample-border-color); border-width: var(--sample-border-width);
  border-radius: var(--sample-radius); padding: var(--sample-pad); cursor: text;
}
.field-focus { border-color: var(--sample-focus); outline: var(--sample-focus-ring); outline-offset: 1px; box-shadow: var(--sample-focus-line); }
</style>
