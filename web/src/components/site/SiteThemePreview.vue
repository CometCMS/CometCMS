<template>
  <section class="card" :aria-label="t('site.theme.previewTitle')">
    <div class="border-b border-slate-200 px-5 py-4">
      <h2 class="card-title">{{ t('site.theme.previewTitle') }}</h2>
      <p class="card-description">{{ t('site.theme.previewHint') }}</p>
    </div>
    <div class="h-[min(44rem,70vh)] min-h-[24rem] rounded-b-xl bg-slate-100 p-3">
      <SitePreviewFrame :body="previewBody" device="full" :title="t('site.theme.previewTitle')" :head-css="specimenCss" />
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import SitePreviewFrame from './SitePreviewFrame.vue';
import { useSiteStore } from '../../stores/site.js';
import { useI18n } from '../../i18n/index.js';
import { FIXED_SHADOWS } from '../../composables/shadows.js';

const site = useSiteStore();
const { t } = useI18n();

// Render a standalone specimen through the existing region preview. Hide the
// surrounding page and saved footer; the sample never changes a saved region.
const specimenCss = 'body > main, body > .c-footer { display: none; }';

const previewBody = computed(() => {
  if (!site.draft?.theme) return null;
  let id = 0;
  const node = (kind, props = {}, style = {}, children = []) => ({ id: `theme-preview-${++id}`, kind, props, style, children });
  const heading = (level, text) => node('heading', { level, text });
  const button = (variant) => node('button', { variant, label: t(`site.theme.previewButton.${variant}`), link: { type: 'current' } });
  const card = (color) => node('column', {}, { background: color, padding_top: 'md', padding_bottom: 'md', padding_left: 'md', padding_right: 'md', radius: 'theme', border: ['left', 'top', 'right', 'bottom'] }, [
    heading('h3', t(`site.theme.color.${color}`)),
    node('text', { html: `<p>${t('site.theme.previewCard')}</p>` }),
  ]);
  const customColors = Object.entries(site.draft.theme.custom_colors ?? {});

  return {
    target: 'region',
    region: 'header',
    locale: site.draft.settings.default_locale,
    theme: site.draft.theme,
    font_version: site.data?.font_version,
    nodes: [
      node('section', { width: 'contained' }, { padding_top: 'lg', padding_bottom: 'lg' }, [
        node('row', {}, {}, [node('column', {}, {}, [
          heading('h1', t('site.theme.previewHeading')),
          node('text', { html: `<p>${t('site.theme.previewText')}</p>` }),
          node('row', {}, {}, [node('column', { direction: 'row' }, {}, [button('primary'), button('secondary'), button('outline'), button('link')])]),
          node('divider'),
          heading('h2', t('site.theme.typography')),
          ...['h3', 'h4', 'h5', 'h6'].map((level) => heading(level, `${level.toUpperCase()} · ${t('site.theme.headingSample')}`)),
        ])]),
      ]),
      node('section', { width: 'narrow' }, { padding_top: 'lg', padding_bottom: 'lg' }, [
        node('row', {}, {}, [card('surface'), card('accent')]),
        ...(customColors.length ? [node('row', {}, {}, customColors.map(([key, color]) =>
          node('column', {}, { background: key, padding_top: 'md', padding_bottom: 'md', padding_left: 'md', padding_right: 'md', radius: 'theme' }, [heading('h3', color.label)]),
        ))] : []),
      ]),
      node('section', { width: 'narrow' }, { padding_top: 'md', padding_bottom: 'lg' }, [
        node('row', {}, {}, [node('column', {}, {}, [heading('h2', t('site.theme.shadows'))])]),
        node('row', {}, { padding_top: 'md', padding_bottom: 'md' }, FIXED_SHADOWS.map((key) =>
          node('column', { span: { mobile: 4, tablet: 4, desktop: 4 } }, { background: 'surface', padding_top: 'md', padding_bottom: 'md', padding_left: 'sm', padding_right: 'sm', radius: 'theme', shadow: key, align: 'center' }, [
            node('text', { html: `<p>${t(`site.option.${key}`)}</p>` }),
          ]),
        )),
      ]),
    ],
  };
});
</script>
