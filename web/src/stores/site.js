import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { api, getActiveWorkspace } from '../api/index.js'
import { isSiteLive, menuErrors } from '../composables/siteLinks.js'
import { DEFAULT_MENU_LIMITS } from '../composables/menuTree.js'
import { updateWorkspaceLanguages } from '../composables/useLanguages.js'
import { t } from '../i18n/index.js'
import { templateList, withTemplateList } from '../composables/templates.js'

const clone = (value) => JSON.parse(JSON.stringify(value))
/** The workspace languages that the settings carry along (they are saved on the workspace). */
const languagesOf = (settings) => ({ locales: clone(settings.locales), default_locale: settings.default_locale })

/**
 * Website configuration of the active workspace. `data` is what the server
 * last returned; `draft` holds the editable copies of settings, workspace
 * languages, theme, menus and templates (which header and footer each page
 * kind's pages show, and when; a template's content is saved in the builder).
 */
export const useSiteStore = defineStore('site', () => {
  const data = ref(null)
  const draft = ref(null)
  const loading = ref(false)
  const error = ref('')
  const saving = ref(false)
  const fieldErrors = ref({})
  /**
   * Whether the Page Builder is in use (saved state): enabled for at least one
   * content type, or the website is live through its homepage.
   */
  const pageBuilderActive = ref(false)
  const homepagePreview = ref(null)
  let workspace = ''
  let statusWorkspace = ''
  let inFlight = null
  let requestGeneration = 0

  const dirtyParts = computed(() => {
    if (!data.value || !draft.value) return []
    return ['languages', 'settings', 'form_settings', 'theme', 'menus', 'templates'].filter((part) => {
      const saved = part === 'languages' ? languagesOf(data.value.settings) : ['templates', 'form_settings'].includes(part) ? data.value[part] ?? {} : data.value[part]
      return JSON.stringify(draft.value[part]) !== JSON.stringify(saved)
    })
  })
  const isDirty = computed(() => dirtyParts.value.length > 0)
  /** Whether the draft settings publish anything; the saved state is `data.settings.enabled`. */
  const live = computed(() => !!(draft.value && data.value) && isSiteLive(draft.value.settings, data.value.types))

  function syncPageBuilderActive() {
    const settings = data.value?.settings
    pageBuilderActive.value = !!settings?.enabled || Object.values(settings?.routes ?? {}).some((route) => route?.enabled)
  }

  /** Refresh `pageBuilderActive` from the lightweight status, without loading the full site. */
  async function loadStatus() {
    const active = getActiveWorkspace()
    if (active !== statusWorkspace) homepagePreview.value = null
    statusWorkspace = active
    const res = await api.site.status()
    if (active !== getActiveWorkspace()) return
    homepagePreview.value = res.data?.homepage_preview ?? null
    pageBuilderActive.value = !!res.data?.enabled || Object.values(res.data?.types ?? {}).some((type) => type.pages_enabled)
  }

  // Editor specimens use the same local faces as the published website.
  watch(() => data.value?.font_css ?? '', (css) => {
    if (typeof document === 'undefined' || !document.head?.appendChild) return
    let style = document.getElementById('cometcms-site-fonts')
    if (!style) {
      style = document.createElement('style')
      style.id = 'cometcms-site-fonts'
      document.head.appendChild(style)
    }
    style.textContent = css
  })

  function applyFonts(fonts) {
    if (!data.value) return
    for (const key of ['fonts', 'font_stacks', 'font_families', 'font_css', 'font_version']) {
      if (key in fonts) data.value[key] = fonts[key]
    }
  }

  async function refreshFonts() {
    const active = getActiveWorkspace()
    const generation = requestGeneration
    const response = await api.fonts.list()
    if (active === getActiveWorkspace() && generation === requestGeneration) applyFonts(response.data)
  }

  /** Refresh seeded types, templates and presets while keeping pending edits in the designer. */
  async function refreshExtensions() {
    const active = getActiveWorkspace()
    const generation = requestGeneration
    const response = await api.site.get()
    if (active !== getActiveWorkspace() || generation !== requestGeneration) return
    const pending = Object.fromEntries(dirtyParts.value.map((part) => [part, clone(draft.value[part])]))
    data.value = response.data
    workspace = active
    syncPageBuilderActive()
    resetDraft()
    Object.assign(draft.value, pending)
  }

  function resetDraft() {
    draft.value = data.value
      ? { settings: clone(data.value.settings), languages: languagesOf(data.value.settings), form_settings: clone(data.value.form_settings ?? {}), theme: clone(data.value.theme), menus: clone(data.value.menus), templates: clone(data.value.templates ?? {}) }
      : null
    fieldErrors.value = {}
  }

  async function load({ force = false } = {}) {
    const active = getActiveWorkspace()
    if (workspace !== active) invalidate()
    if (!force && data.value && workspace === active) return data.value
    if (inFlight && workspace === active) return inFlight

    workspace = active
    loading.value = true
    error.value = ''
    const generation = ++requestGeneration
    const current = () => generation === requestGeneration && active === getActiveWorkspace()
    const request = api.site.get()
      .then((res) => {
        if (!current()) return data.value
        data.value = res.data
        syncPageBuilderActive()
        resetDraft()
        return data.value
      })
      .catch((err) => {
        if (current()) error.value = err.message
        throw err
      })
      .finally(() => {
        if (inFlight === request) {
          loading.value = false
          inFlight = null
        }
      })
    inFlight = request

    return inFlight
  }

  /**
   * Save the changed parts of the draft (all of them, or only `parts`); field
   * errors are kept for display.
   */
  async function save({ parts = null } = {}) {
    if (!draft.value) return
    const pending = dirtyParts.value.filter((part) => !parts || parts.includes(part))
    saving.value = true
    fieldErrors.value = {}

    try {
      if (pending.includes('menus')) {
        const fields = menuErrors(draft.value.menus, t, { ...DEFAULT_MENU_LIMITS, ...data.value.menu_limits }, draft.value.settings.default_locale)
        if (Object.keys(fields).length) {
          throw Object.assign(new Error(t('site.saveErrors')), { fields })
        }
      }
      // Languages first: the settings are validated against the workspace's languages.
      for (const part of pending) {
        if (part === 'languages') {
          const slug = getActiveWorkspace()
          const languages = (await api.workspaces.update(slug, { label: data.value.workspace_label, languages: draft.value.languages })).data.languages
          updateWorkspaceLanguages(slug, languages)
          for (const target of [data.value.settings, draft.value.settings]) Object.assign(target, clone(languages))
          draft.value.languages = languagesOf(data.value.settings)
          continue
        }
        if (part === 'settings') {
          data.value.settings = (await api.site.saveSettings(draft.value.settings)).data
          syncPageBuilderActive()
        } else if (part === 'form_settings') {
          data.value.form_settings = (await api.site.saveFormSettings(draft.value.form_settings)).data
        } else if (part === 'theme') {
          data.value.theme = (await api.site.saveTheme(draft.value.theme)).data
        } else if (part === 'templates') {
          const response = await api.site.saveTemplates(draft.value.templates, data.value.templates_revision)
          data.value.templates = response.data
          data.value.templates_revision = response.meta?.revision
        } else {
          const response = await api.site.saveMenus(draft.value.menus, data.value.menus_revision)
          data.value.menus = response.data
          data.value.menus_revision = response.meta?.revision
        }
        draft.value[part] = clone(data.value[part])
      }
    } catch (err) {
      fieldErrors.value = err.fields ?? {}
      throw err
    } finally {
      saving.value = false
    }
  }

  /**
   * Save the website settings of one content type (from its editor). Other
   * pending edits on the Website screen stay untouched.
   */
  async function saveRoute(name, route) {
    await load()
    const settings = clone(data.value.settings)
    settings.routes = { ...settings.routes, [name]: { ...(settings.routes?.[name] ?? {}), ...route } }
    const saved = (await api.site.saveSettings(settings)).data
    data.value.settings = saved
    syncPageBuilderActive()
    if (draft.value) {
      draft.value.settings.routes[name] = clone(saved.routes[name])
      for (const key of ['revision', 'updated_at', 'enabled', 'locales', 'default_locale']) {
        draft.value.settings[key] = clone(saved[key])
      }
    }
    // New types bring new layouts and routes; refresh what the Website screen lists.
    if (!isDirty.value) await load({ force: true })
    return saved.routes[name]
  }

  /** Mark a layout or region as customized/default after the builder saved it. */
  function markCustomized(target, name, variant, customized) {
    if (!data.value) return
    if (target === 'region') {
      if (data.value.special_pages && name in data.value.special_pages) data.value.special_pages[name] = customized
      else data.value.regions[name] = customized
    } else if (data.value.layouts?.[name]) {
      data.value.layouts[name][variant] = customized
    }
  }

  /**
   * A new template, saved right away with a copy of `from`'s content (see
   * api.site.createTemplate). The draft gets it before its default template
   * and keeps its other unsaved template changes.
   */
  async function createTemplate(type, variant, name, from = '', parts = {}) {
    const response = await api.site.createTemplate(type, variant, name, from, parts)
    data.value.templates = response.meta.templates
    data.value.templates_revision = response.meta.revision
    if (draft.value) {
      const list = templateList(draft.value.templates, type, variant)
      draft.value.templates = withTemplateList(draft.value.templates, type, variant, [...list.slice(0, -1), response.data, list[list.length - 1]])
    }
    return response.data
  }

  /** Keep the design as currently edited (saved or not) under a name. */
  async function createThemePreset(name) {
    const response = await api.site.createThemePreset(name, draft.value.theme)
    data.value.theme_presets = [...(data.value.theme_presets ?? []), response.data].sort((a, b) => a.name.localeCompare(b.name))
    return response.data
  }

  async function deleteThemePreset(id) {
    await api.site.deleteThemePreset(id)
    data.value.theme_presets = (data.value.theme_presets ?? []).filter((preset) => preset.id !== id)
  }

  /** Restoring the design must preserve pending settings and menu edits. */
  function applyRestoredTheme(theme) {
    if (!data.value || !draft.value) return;
    data.value.theme = clone(theme);
    draft.value.theme = clone(theme);
  }

  /** Drop pending design edits, keeping pending settings and menu edits. */
  function discardTheme() {
    if (!data.value || !draft.value) return
    draft.value.theme = clone(data.value.theme)
  }

  function invalidate() {
    requestGeneration += 1
    inFlight = null
    loading.value = false
    error.value = ''
    fieldErrors.value = {}
    data.value = null
    pageBuilderActive.value = false
    homepagePreview.value = null
    draft.value = null
    workspace = ''
    statusWorkspace = ''
  }

  return { data, draft, loading, error, saving, fieldErrors, dirtyParts, isDirty, live, pageBuilderActive, homepagePreview, load, loadStatus, applyFonts, refreshFonts, refreshExtensions, save, saveRoute, resetDraft, markCustomized, createTemplate, createThemePreset, deleteThemePreset, applyRestoredTheme, discardTheme, invalidate }
})
