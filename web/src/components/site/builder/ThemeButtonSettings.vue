<template>
  <div class="space-y-4">
    <p class="text-xs text-slate-500">{{ t('site.theme.buttonsHint') }}</p>
    <div class="flex flex-wrap items-center justify-center gap-3 rounded-lg border border-slate-200 p-4" :style="sampleStyle" aria-hidden="true">
      <span class="button-sample button-filled" :class="`button-hover-${buttons.hover}`">{{ t('site.theme.previewButton.primary') }}</span>
      <span class="button-sample button-outline" :class="`button-hover-${buttons.hover}`">{{ t('site.theme.previewButton.outline') }}</span>
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <span class="form-label">{{ t('site.theme.buttonFont') }}</span>
        <SegmentedControl v-model="buttons.font" :options="[{ value: '', label: t('site.theme.buttonFont.body') }, { value: 'heading', label: t('site.theme.buttonFont.heading') }]" />
      </div>
      <div>
        <label class="form-label" for="design-button-weight">{{ t('site.theme.headingLevelWeight') }}</label>
        <select id="design-button-weight" v-model.number="buttons.weight" class="form-select w-full rounded-lg border-slate-300 text-sm">
          <option v-for="weight in WEIGHTS" :key="weight" :value="weight">{{ weight }}</option>
        </select>
      </div>
    </div>
    <div>
      <span class="form-label">{{ t('site.theme.textTransform') }}</span>
      <SegmentedControl v-model="buttons.transform" :options="TRANSFORMS.map(({ value, label }) => ({ value, label, title: t(`site.theme.transform.${value || 'none'}`) }))" />
    </div>
    <RangeRow v-model="buttons.letter_spacing" :label="t('site.theme.letterSpacing')" :min="-0.05" :max="0.3" :step="0.01" unit="em" />
    <div>
      <span class="form-label">{{ t('site.theme.buttonPadding') }}</span>
      <SegmentedControl v-model="buttons.padding" :options="DENSITIES.map((value) => ({ value, label: t(`site.theme.spacingOption.${value}`) }))" />
    </div>
    <RangeRow v-model="buttons.border_width" :label="t('site.theme.borderWidth')" :min="0" :max="6" :step="1" unit="px" />
    <div>
      <span class="form-label">{{ t('site.theme.corner') }}</span>
      <SegmentedControl v-model="buttons.radius" :options="cornerOptions" />
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="form-label" for="design-button-hover">{{ t('site.theme.buttonHover') }}</label>
        <select id="design-button-hover" v-model="buttons.hover" class="form-select w-full rounded-lg border-slate-300 text-sm">
          <option v-for="hover in HOVERS" :key="hover" :value="hover">{{ t(`site.theme.buttonHover.${hover}`) }}</option>
        </select>
      </div>
      <div>
        <label class="form-label" for="design-button-shadow">{{ t('site.theme.buttonShadow') }}</label>
        <select id="design-button-shadow" v-model="buttons.shadow" class="form-select w-full rounded-lg border-slate-300 text-sm">
          <option value="">{{ t('site.option.none') }}</option>
          <option v-for="key in FIXED_SHADOWS" :key="key" :value="key">{{ t(`site.option.${key}`) }}</option>
          <option v-for="(preset, key) in theme.shadows ?? {}" :key="key" :value="key">{{ shadowLabel(key, preset, t) }}</option>
        </select>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import RangeRow from '../RangeRow.vue';
import SegmentedControl from '../SegmentedControl.vue';
import { useI18n } from '../../../i18n/index.js';
import { FIXED_SHADOWS, shadowLabel, shadowPreview } from '../../../composables/shadows.js';
import { useSiteStore } from '../../../stores/site.js';
import { DENSITIES, TRANSFORMS, WEIGHTS, contrastColor, cornerOptions as corners, cornerPx, densityFactor } from '../../../composables/themeControls.js';

/** Site-wide button look (Theme::buttons); the sample mirrors the public stylesheet. */
const props = defineProps({
  theme: { type: Object, required: true },
  colors: { type: Array, default: () => [] },
});
const { t } = useI18n();
const site = useSiteStore();
const HOVERS = ['fade', 'darken', 'lift', 'none'];
const buttons = computed(() => props.theme.buttons);
const cornerOptions = computed(() => corners(t));
const swatch = (value) => props.colors.find((option) => option.value === value)?.swatch;
const sampleStyle = computed(() => {
  const b = buttons.value;
  const pad = densityFactor(b.padding);
  const shadow = b.shadow ? shadowPreview(b.shadow, false, props.colors, props.theme.shadows, props.theme.shadow_scale).boxShadow : 'none';
  const lift = b.shadow ? shadow : shadowPreview('md', false, props.colors, props.theme.shadows, props.theme.shadow_scale).boxShadow;
  return {
    backgroundColor: swatch('background'),
    '--sample-primary': swatch('primary') ?? '#2563eb',
    '--sample-on-primary': contrastColor(swatch('primary')),
    '--sample-font': (b.font === 'heading' ? site.data?.font_stacks?.[props.theme.fonts?.heading] : site.data?.font_stacks?.[props.theme.fonts?.body]) ?? 'inherit',
    '--sample-weight': b.weight,
    '--sample-transform': b.transform || 'none',
    '--sample-spacing': b.letter_spacing ? `${b.letter_spacing}em` : 'normal',
    '--sample-border': `${b.border_width}px`,
    '--sample-radius': cornerPx(props.theme, b.radius),
    '--sample-pad-y': `${0.65 * pad}em`,
    '--sample-pad-x': `${1.25 * pad}em`,
    '--sample-shadow': shadow,
    '--sample-lift': lift,
    '--sample-duration': `${props.theme.motion?.hover ?? 200}ms`,
  };
});
</script>

<style scoped>
.button-sample {
  display: inline-flex; align-items: center; cursor: default; font-size: 0.875rem; line-height: 1.2;
  font-family: var(--sample-font); font-weight: var(--sample-weight); text-transform: var(--sample-transform); letter-spacing: var(--sample-spacing);
  padding: var(--sample-pad-y) var(--sample-pad-x); border: var(--sample-border) solid transparent; border-radius: var(--sample-radius); box-shadow: var(--sample-shadow);
  transition: opacity var(--sample-duration), filter var(--sample-duration), box-shadow var(--sample-duration), transform var(--sample-duration), background-color var(--sample-duration);
}
.button-filled { background: var(--sample-primary); color: var(--sample-on-primary); }
.button-outline { border-color: var(--sample-primary); border-width: max(1px, var(--sample-border)); color: var(--sample-primary); background: transparent; }
.button-outline:hover { background: color-mix(in srgb, var(--sample-primary) 8%, transparent); }
.button-filled.button-hover-fade:hover { opacity: 0.9; }
.button-filled.button-hover-darken:hover { filter: brightness(0.9); }
.button-hover-lift:hover { box-shadow: var(--sample-lift); transform: translateY(-2px); }
@media (prefers-reduced-motion: reduce) {
  .button-sample { transition: none; }
  .button-hover-lift:hover { transform: none; }
}
</style>
