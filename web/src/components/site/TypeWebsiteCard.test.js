import { createSSRApp, ref } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import TypeWebsiteCard from './TypeWebsiteCard.vue'

vi.mock('../../composables/useLanguages.js', () => ({
  useLanguages: () => ({ activeLocale: ref('de'), defaultLocale: ref('en') }),
}))
vi.mock('@iconify/vue', () => ({ Icon: { render: () => null } }))

const localized = (values) => ({ type: 'localized', values, sources: [] })
async function renderFields(fields) {
  const app = createSSRApp(TypeWebsiteCard, {
    section: 'seo',
    modelValue: { enabled: true, seo_description_field: 'intro', seo_image_field: 'image' },
    status: { can_manage: true },
    fields,
  })
  app.component('RouterLink', { render: () => null })
  return renderToString(app)
}

describe('content type SEO field dropdowns', () => {
  it('renders translated labels and keeps field keys as option values', async () => {
    const html = await renderFields([
      { key: 'intro', type: 'textarea', label: localized({ en: 'Introduction', de: 'Einleitung' }) },
      { key: 'image', type: 'media', label: localized({ en: 'Image', de: 'Vorschaubild' }) },
    ])
    expect(html).toContain('<option value="intro">Einleitung</option>')
    expect(html).toContain('<option value="image">Vorschaubild</option>')
    expect(html).not.toContain('&quot;localized&quot;')
  })

  it('falls back to default language labels or field keys and supports plain labels', async () => {
    const html = await renderFields([
      { key: 'intro', type: 'text', label: localized({ en: 'Introduction' }) },
      { key: 'image', type: 'media', label: localized({ en: 'Image' }) },
      { key: 'body', type: 'html', label: localized({}) },
      { key: 'thumbnail', type: 'media', label: localized({ de: '' }) },
      { key: 'summary', type: 'markdown', label: 'Summary' },
      { key: 'cover', type: 'media' },
    ])
    expect(html).toContain('<option value="intro">Introduction</option>')
    expect(html).toContain('<option value="image">Image</option>')
    expect(html).toContain('<option value="body">body</option>')
    expect(html).toContain('<option value="thumbnail">thumbnail</option>')
    expect(html).toContain('<option value="summary">Summary</option>')
    expect(html).toContain('<option value="cover">cover</option>')
  })
})
