import { ADMIN_API_BASE, ADMIN_BASE } from '../basePath.js'
import { t } from '../i18n/index.js'
import { withPreviewReturn } from '../previewNavigation.js'

// CSRF token is seeded from the PHP-rendered meta tag on first load,
// then kept up-to-date from the X-CSRF-Token response header.
const browserStorage = typeof localStorage !== 'undefined' && typeof localStorage.getItem === 'function'
  ? localStorage
  : null
let csrfToken = (typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.content : null) ?? ''
let activeWorkspace = browserStorage?.getItem('cometcms.workspace') || ''
let defaultWorkspace = ''
const inFlightReads = new Map()
const REQUEST_TIMEOUT_MS = 15000
const RETRYABLE_STATUSES = new Set([408, 429, 500, 502, 503, 504])

// '' means "not known yet": requests then omit the workspace header and the
// server applies its configured default instead of guessing a slug.
export function getActiveWorkspace() {
  return activeWorkspace || defaultWorkspace || ''
}

/** A draft preview page of the website in the active workspace, opened in a new tab. */
function sitePreviewUrl(path, locale = '') {
  const params = new URLSearchParams({ workspace: getActiveWorkspace() })
  if (locale) params.set('locale', locale)
  return withPreviewReturn(`${ADMIN_BASE}/site-preview${path}?${params}`)
}

export function setActiveWorkspace(workspace) {
  activeWorkspace = workspace || defaultWorkspace || ''
  if (activeWorkspace) browserStorage?.setItem('cometcms.workspace', activeWorkspace)
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('cometcms:workspace-changed', {
      detail: { workspace: activeWorkspace },
    }))
  }
}

export function getDefaultWorkspace() {
  return defaultWorkspace || activeWorkspace || ''
}

export function setDefaultWorkspace(workspace) {
  defaultWorkspace = workspace || activeWorkspace || ''

  if (!activeWorkspace && defaultWorkspace) {
    setActiveWorkspace(defaultWorkspace)
  }
}

/**
 * Adopt the workspace the server reports for the signed-in user. Users who may
 * not choose workspaces always work in the default one, which also discards a
 * workspace remembered from someone else's session in this browser.
 */
export function syncWorkspaceFromUser(user, canChooseWorkspace) {
  const serverDefault = user?.default_workspace
  if (!serverDefault) return

  defaultWorkspace = serverDefault
  if (!canChooseWorkspace || !activeWorkspace) {
    activeWorkspace = serverDefault
    browserStorage?.setItem('cometcms.workspace', activeWorkspace)
  }
}

// Message for responses that are not CometCMS JSON, e.g. from the web server or a proxy.
function statusMessage(status) {
  if (status === 413) return t('apiError.tooLarge')
  if (status === 502 || status === 503 || status === 504) return t('apiError.unavailable')
  return t('apiError.server', { status })
}

// Requests that fail because the admin session ended (idle timeout, password
// change elsewhere) let the app send the user back to the sign-in screen.
function announceExpiredSession(status, json, path) {
  if (status !== 401 || json?.error?.code !== 'unauthenticated' || path === '/me' || path === '/login') return
  if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent('cometcms:session-expired'))
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms))
}

function retryDelay(attempt, res = null) {
  const retryAfter = res?.headers?.get('Retry-After')
  const retryAfterSeconds = retryAfter ? Number(retryAfter) : 0

  if (Number.isFinite(retryAfterSeconds) && retryAfterSeconds > 0) {
    return Math.min(retryAfterSeconds * 1000, 5000)
  }

  return 300 * attempt
}

function isRetryableNetworkError(err) {
  return err?.name === 'AbortError' || err instanceof TypeError
}

async function fetchWithTimeout(url, options, timeoutMs = REQUEST_TIMEOUT_MS) {
  const controller = new AbortController()
  const timer = globalThis.setTimeout(() => controller.abort(), timeoutMs)

  try {
    return await fetch(url, { ...options, signal: controller.signal })
  } finally {
    globalThis.clearTimeout(timer)
  }
}

async function request(method, path, body = null, workspace = getActiveWorkspace()) {
  const normalizedMethod = method.toUpperCase()
  const readKey = normalizedMethod === 'GET' && body === null
    ? `${workspace} ${normalizedMethod} ${path}`
    : null

  if (readKey && inFlightReads.has(readKey)) {
    return inFlightReads.get(readKey)
  }

  const promise = requestOnce(normalizedMethod, path, body, workspace)

  if (readKey) {
    inFlightReads.set(readKey, promise)
    promise.then(
      () => inFlightReads.delete(readKey),
      () => inFlightReads.delete(readKey),
    )
  }

  return promise
}

async function requestOnce(method, path, body = null, workspace = getActiveWorkspace()) {
  const headers = { 'X-Requested-With': 'XMLHttpRequest' }
  if (workspace) headers['X-Comet-Workspace'] = workspace

  if (csrfToken) {
    headers['X-CSRF-Token'] = csrfToken
  }

  let finalBody = null

  if (body !== null) {
    if (body instanceof FormData) {
      // Let the browser set Content-Type with the correct boundary.
      finalBody = body
    } else {
      headers['Content-Type'] = 'application/json'
      finalBody = JSON.stringify(body)
    }
  }

  const maxAttempts = method === 'GET' ? 3 : 1
  let res

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    try {
      res = await fetchWithTimeout(`${ADMIN_API_BASE}${path}`, {
        method,
        headers,
        body: finalBody,
        credentials: 'same-origin',
      }, path.startsWith('/site/fonts') ? 60000 : REQUEST_TIMEOUT_MS)

      if (attempt < maxAttempts && RETRYABLE_STATUSES.has(res.status)) {
        await sleep(retryDelay(attempt, res))
        continue
      }

      break
    } catch (err) {
      if (attempt >= maxAttempts || !isRetryableNetworkError(err)) {
        const error = new Error(err?.name === 'AbortError' ? t('apiError.timeout') : t('apiError.network'))
        error.code = err?.name === 'AbortError' ? 'request_timeout' : 'network_error'
        throw error
      }

      await sleep(retryDelay(attempt))
    }
  }

  if (!res) {
    const error = new Error(t('apiError.network'))
    error.code = 'network_error'
    throw error
  }

  // Always keep our CSRF token in sync with what the server returns.
  const newToken = res.headers.get('X-CSRF-Token')
  if (newToken) csrfToken = newToken

  if (res.status === 204) return null

  let json
  try {
    json = await res.json()
  } catch {
    const err = new Error(statusMessage(res.status))
    err.code   = 'server_error'
    err.status = res.status
    throw err
  }

  if (!res.ok) {
    announceExpiredSession(res.status, json, path)
    const err = new Error(json.error?.message ?? t('apiError.failed'))
    err.code    = json.error?.code ?? 'unknown'
    err.fields  = json.error?.fields ?? {}
    err.details = json.error?.details ?? null
    err.status  = res.status
    err.retryAfter = json.error?.retry_after ?? null
    throw err
  }

  return json
}

function requestWithProgress(method, path, formData, onProgress, timeoutMs = 0) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open(method, `${ADMIN_API_BASE}${path}`)
    // Uploads can legitimately take many minutes. A zero timeout lets the web
    // server enforce its configured request limits instead of aborting in the UI.
    xhr.timeout = timeoutMs
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest')
    if (getActiveWorkspace()) xhr.setRequestHeader('X-Comet-Workspace', getActiveWorkspace())
    if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken)

    if (onProgress) {
      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) onProgress(e.loaded / e.total)
      })
    }

    xhr.addEventListener('load', () => {
      const newToken = xhr.getResponseHeader('X-CSRF-Token')
      if (newToken) csrfToken = newToken

      if (xhr.status === 204) return resolve(null)

      let json
      try {
        json = JSON.parse(xhr.responseText)
      } catch {
        const err = new Error(statusMessage(xhr.status))
        err.code = 'server_error'
        err.status = xhr.status
        return reject(err)
      }

      if (xhr.status >= 400) {
        announceExpiredSession(xhr.status, json, path)
        const err = new Error(json.error?.message ?? t('apiError.failed'))
        err.code = json.error?.code ?? 'unknown'
        err.fields = json.error?.fields ?? {}
        err.details = json.error?.details ?? null
        err.status = xhr.status
        err.retryAfter = json.error?.retry_after ?? null
        return reject(err)
      }

      resolve(json)
    })

    xhr.addEventListener('error', () => reject(new Error(t('apiError.network'))))
    xhr.addEventListener('abort', () => reject(new Error(t('apiError.uploadAborted'))))
    xhr.addEventListener('timeout', () => {
      const err = new Error(t('apiError.timeout'))
      err.code = 'request_timeout'
      reject(err)
    })

    xhr.send(formData)
  })
}

function withQuery(path, params = {}) {
  const query = new URLSearchParams(params).toString()
  return query ? `${path}?${query}` : path
}

// Trigger a browser-native download. This avoids buffering the full ZIP in JS
// memory before the save dialog appears.
function downloadViaNavigation(path) {
  const workspace = getActiveWorkspace()
  const separator = path.includes('?') ? '&' : '?'
  const workspaceQuery = workspace ? `${separator}workspace=${encodeURIComponent(workspace)}` : ''
  const a = document.createElement('a')
  a.href = `${ADMIN_API_BASE}${path}${workspaceQuery}`
  a.style.display = 'none'
  document.body.appendChild(a)
  a.click()
  a.remove()
}

export const api = {
  // Auth
  me:     ()                  => request('GET',    '/me'),
  login:  (username, password, remember = false)=> request('POST', '/login', { username, password, remember }),
  logout: ()                  => request('POST',   '/logout'),
  setup:  (username, password, workspace, workspaceSlug, starterTemplate = 'website', languages = { locales: ['en'], default_locale: 'en' })=> request('POST', '/setup', { username, password, workspace, workspace_slug: workspaceSlug, starter_template: starterTemplate, languages }),
  appInfo: ()                  => request('GET',    '/app'),
  languages: ()                => request('GET', '/languages'),
  workspaces: {
    list:       () => request('GET', '/workspaces'),
    create:     (data) => request('POST', '/workspaces', data),
    update:     (slug, data) => request('PUT', `/workspaces/${slug}`, data),
    archive:    (slug) => request('DELETE', `/workspaces/${slug}`),
    setDefault: (slug) => request('POST', `/workspaces/${slug}/default`),
    uploadIcon: (slug, formData) => request('POST', `/workspaces/${slug}/icon`, formData),
    deleteIcon: (slug) => request('DELETE', `/workspaces/${slug}/icon`),
  },

  // Dashboard
  dashboard: () => request('GET', '/dashboard'),

  // Activity log
  activity: (params = {}) => request('GET', withQuery('/activity', params)),

  // Updates
  update: {
    status:   () => request('GET', '/update'),
    check:    () => request('POST', '/update/check'),
    download: () => request('POST', '/update/download'),
    install:  (stageId = null) => request('POST', '/update/install', stageId ? { stage_id: stageId } : {}),
  },

  // Content types
  contentTypes: {
    list:   ()           => request('GET',    '/content-types'),
    get:    (name)       => request('GET',    `/content-types/${name}`),
    create: (data)       => request('POST',   '/content-types', data),
    update: (name, data) => request('PUT',    `/content-types/${name}`, data),
    delete: (name)       => request('DELETE', `/content-types/${name}`),
    reorder: (names)     => request('PATCH',  '/content-types/order', { names }),
  },

  // Content
  content: {
    list:        (col, params = {})          => request('GET',    withQuery(`/content/${col}`, params)),
    get:         (col, id)                   => request('GET',    `/content/${col}/${id}`),
    create:      (col, data, params = {})    => request('POST',   withQuery(`/content/${col}`, params), data),
    update:      (col, id, data, params = {})=> request('PUT',    withQuery(`/content/${col}/${id}`, params), data),
    bulkUpdate:  (col, ids, data)            => request('PATCH',  `/content/${col}/bulk`, { ids, data }),
    bulkDelete:  (col, ids)                  => request('DELETE', `/content/${col}/bulk`, { ids }),
    delete:      (col, id)                   => request('DELETE', `/content/${col}/${id}`),
    revisions:   (col, id)                   => request('GET',    `/content/${col}/${id}/revisions`),
    restoreRevision: (col, id, revisionId)   => request('POST',   `/content/${col}/${id}/revisions/${revisionId}/restore`),
    duplicate:       (col, id)               => request('POST',   `/content/${col}/${id}/duplicate`),
    move:            (col, id, placement)    => request('POST',   `/content/${col}/${id}/move`, placement),
    deleteTranslation: (col, id, locale)     => request('DELETE', `/content/${col}/${id}/translations/${locale}`),
  },

  // Trash
  trash: {
    list:    (col)       => request('GET',    `/trash/${col}`),
    restore: (col, id)   => request('POST',   `/trash/${col}/${id}/restore`),
    purge:   (col, id)   => request('DELETE', `/trash/${col}/${id}`),
    empty:   (col)       => request('DELETE', `/trash/${col}`),
  },

  // Media
  media: {
    list:   (params = {}) => request('GET', withQuery('/media', params)),
    upload: (formData, onProgress) => requestWithProgress('POST', '/media', formData, onProgress),
    createCategory: (name, parent = '') => request('POST', '/media/categories', parent ? { name, parent } : { name }),
    renameCategory: (oldName, name) => request('PUT', `/media/categories/${encodeURIComponent(oldName)}`, { name }),
    deleteCategory: (name) => request('DELETE', `/media/categories/${encodeURIComponent(name)}`),
    updateCategory: (name, category) => request('PUT', `/media/${encodeURIComponent(name)}/category`, { category }),
    rename: (oldName, name) => request('PUT', `/media/${encodeURIComponent(oldName)}/rename`, { name }),
    updateMeta: (name, alt, title, revision) => request('PUT', `/media/${encodeURIComponent(name)}/meta`, { alt, title, revision }),
    updateVisibility: (name, visibility) => request('PUT', `/media/${encodeURIComponent(name)}/visibility`, { visibility }),
    bulkUpdateVisibility: (files, visibility) => request('PUT', '/media/bulk-visibility', { files, visibility }),
    bulkUpdateCategory: (files, category) => request('PUT', '/media/bulk-category', { files, category }),
    delete: (name) => request('DELETE', `/media/${encodeURIComponent(name)}`),
    bulkDelete: (files) => request('POST', '/media/bulk-delete', { files }),
    regenerateSizes: (files = []) => request('POST', '/media/thumbnails/regenerate', files.length > 0 ? { files } : {}),
    sizes: () => request('GET', '/media/sizes'),
    sizeFiles: () => request('GET', '/media/sizes/files'),
    saveSizes: (sizes) => request('PUT', '/media/sizes', sizes),
    usages: () => request('GET', '/media/usages'),
  },

  // Users
  users: {
    list:        ()                  => request('GET',    '/users'),
    get:         (id)                => request('GET',    `/users/${id}`),
    create:      (data)              => request('POST',   '/users', data),
    update:      (id, data)          => request('PUT',    `/users/${id}`, data),
    delete:      (id)                => request('DELETE', `/users/${id}`),
  },

  // API tokens
  tokens: {
    list:   ()         => request('GET',    '/tokens'),
    create: (data)     => request('POST',   '/tokens', data),
    delete: (tokenId)  => request('DELETE', `/tokens/${tokenId}`),
  },

  // Roles
  roles: {
    list:   ()          => request('GET',    '/roles'),
    create: (data)      => request('POST',   '/roles', data),
    update: (id, data)  => request('PUT',    `/roles/${id}`, data),
    delete: (id)        => request('DELETE', `/roles/${id}`),
  },

  // Backup / Restore
  backups: {
    list:     () => request('GET', '/backups'),
    create:   (parts) => request('POST', '/backups', { parts }),
    upload:   (formData, onProgress) => requestWithProgress('POST', '/backups/upload', formData, onProgress),
    inspect:  (name) => request('GET', `/backups/${encodeURIComponent(name)}/inspect`),
    restore:  (name, data) => request('POST', `/backups/${encodeURIComponent(name)}/restore`, data),
    note:     (name, note) => request('PUT', `/backups/${encodeURIComponent(name)}/note`, { note }),
    download: (name) => downloadViaNavigation(`/backups/${encodeURIComponent(name)}/download`),
    delete:   (name) => request('DELETE', `/backups/${encodeURIComponent(name)}`),
  },

  // Webhooks
  webhooks: {
    get:    ()        => request('GET', '/webhooks'),
    update: (data)    => request('PUT', '/webhooks', data),
    run:    (webhook) => request('POST', '/webhooks/run', { webhook }),
  },

  // Website
  site: {
    get:          (workspace)            => request('GET',    '/site', null, workspace),
    status:       ()                     => request('GET',    '/site/status'),
    links:        (type, id, params = {}) => request('GET',   withQuery(`/site/links/${type}/${encodeURIComponent(id)}`, params)),
    saveSettings: (data)                 => request('PUT',    '/site/settings', data),
    saveFormSettings: (data)             => request('PUT',    '/site/form-settings', data),
    saveTheme:    (data, workspace)      => request('PUT',    '/site/theme', data, workspace),
    createThemePreset: (name, theme) => request('POST', '/site/theme/presets', { name, theme }),
    deleteThemePreset: (id) => request('DELETE', `/site/theme/presets/${encodeURIComponent(id)}`),
    themeRevisions: () => request('GET', '/site/theme/revisions'),
    themeRevision: (id) => request('GET', `/site/theme/revisions/${encodeURIComponent(id)}`),
    restoreThemeRevision: (id) => request('POST', `/site/theme/revisions/${encodeURIComponent(id)}/restore`),
    saveMenus:    (menus, revision)      => request('PUT',    '/site/menus', { menus, revision }),
    /** Templates of some page kinds: `{ type: { variant: [template, …] } }`; page kinds left out stay. */
    saveTemplates: (templates, revision) => request('PUT',    '/site/templates', { templates, revision }),
    /** A new template of a page kind, starting as a copy of `from` (default: the default template); saved right away. */
    createTemplate: (type, variant, name, from = '', parts = {}) => request('POST', `/site/templates/${type}/${variant}`, { name, from, parts }),
    cardTemplate: (type) => request('GET', `/site/card-template/${type}`),
    /** A template's content area; without `template`, the entry's (or the default) template. */
    layout:       (type, variant, entry = '', template = '') => request('GET', withQuery(`/site/layouts/${type}/${variant}`, { ...(entry ? { entry } : {}), ...(template ? { template } : {}) })),
    saveLayout:   (type, variant, nodes, revision, template = 'default') => request('PUT',    `/site/layouts/${type}/${variant}`, { nodes, revision, template }),
    resetLayout:  (type, variant, template = 'default') => request('DELETE', withQuery(`/site/layouts/${type}/${variant}`, { template })),
    layoutRevisions: (type, variant, template = 'default') => request('GET', withQuery(`/site/layouts/${type}/${variant}/revisions`, { template })),
    layoutRevision: (type, variant, id, template = 'default') => request('GET', withQuery(`/site/layouts/${type}/${variant}/revisions/${encodeURIComponent(id)}`, { template })),
    restoreLayoutRevision: (type, variant, id, template = 'default') => request('POST', withQuery(`/site/layouts/${type}/${variant}/revisions/${encodeURIComponent(id)}/restore`, { template })),
    /** An entry's own page content ({ field } picks the page field; the type's first by default). */
    page:         (type, id, field = '') => request('GET', withQuery(`/site/pages/${type}/${encodeURIComponent(id)}`, field ? { field } : {})),
    areas:        (type)                 => request('GET',    `/site/areas/${type}`),
    deleteArea:   (type, key)            => request('DELETE', `/site/areas/${type}/${encodeURIComponent(key)}`),
    savePage:     (type, id, field, nodes, revision) => request('PUT', `/site/pages/${type}/${encodeURIComponent(id)}`, { field, nodes, revision }),
    resetPage:    (type, id, field, revision) => request('DELETE', `/site/pages/${type}/${encodeURIComponent(id)}`, { field, revision }),
    pageRevisions: (type, id, field) => request('GET', withQuery(`/site/pages/${type}/${encodeURIComponent(id)}/revisions`, { field })),
    pageRevision: (type, id, field, revision) => request('GET', withQuery(`/site/pages/${type}/${encodeURIComponent(id)}/revisions/${encodeURIComponent(revision)}`, { field })),
    region:       (region)               => request('GET',    `/site/regions/${region}`),
    saveRegion:   (region, nodes, revision)        => request('PUT',    `/site/regions/${region}`, { nodes, revision }),
    resetRegion:  (region)               => request('DELETE', `/site/regions/${region}`),
    regionRevisions: (region) => request('GET', `/site/regions/${region}/revisions`),
    regionRevision: (region, id) => request('GET', `/site/regions/${region}/revisions/${encodeURIComponent(id)}`),
    restoreRegionRevision: (region, id) => request('POST', `/site/regions/${region}/revisions/${encodeURIComponent(id)}/restore`),
    components:   ()                     => request('GET',    '/site/components'),
    component:    (id, entry = '')       => request('GET',    withQuery(`/site/components/${encodeURIComponent(id)}`, entry ? { entry } : {})),
    createComponent: (data)              => request('POST',   '/site/components', data),
    saveComponent: (id, nodes, revision)           => request('PUT',    `/site/components/${encodeURIComponent(id)}`, { nodes, revision }),
    renameComponent: (id, name)          => request('PATCH',  `/site/components/${encodeURIComponent(id)}`, { name }),
    setComponentIcon: (id, icon)         => request('PATCH',  `/site/components/${encodeURIComponent(id)}`, { icon }),
    deleteComponent: (id)                => request('DELETE', `/site/components/${encodeURIComponent(id)}`),
    componentRevisions: (id) => request('GET', `/site/components/${encodeURIComponent(id)}/revisions`),
    componentRevision: (id, revision) => request('GET', `/site/components/${encodeURIComponent(id)}/revisions/${encodeURIComponent(revision)}`),
    restoreComponentRevision: (id, revision) => request('POST', `/site/components/${encodeURIComponent(id)}/revisions/${encodeURIComponent(revision)}/restore`),
    forms:        ()                     => request('GET',    '/site/forms'),
    createPreview: (body)               => request('POST', '/site/preview-snapshots', body),
    preview:      (body)                 => request('POST',   '/site/preview', body),
    /** Full-page draft preview of an entry, opened in a new tab. */
    entryPreviewUrl: (type, id, locale = '') => sitePreviewUrl(`/${encodeURIComponent(type)}/${encodeURIComponent(id)}`, locale),
    /** A type's index page with its saved layout. */
    indexPreviewUrl: (type, locale = '') => sitePreviewUrl(`/${encodeURIComponent(type)}`, locale),
    /** The homepage with the saved header and footer. */
    homePreviewUrl: (locale = '') => sitePreviewUrl('', locale),
  },

  extensions: {
    setEnabled: (name, enabled) => request('PUT', `/site/extensions/${name.split('/').map(encodeURIComponent).join('/')}`, { enabled }),
    list: () => request('GET', '/site/extensions'),
    get: (name) => request('GET', `/site/extensions/${name.split('/').map(encodeURIComponent).join('/')}`),
    delete: (name, remove = []) => request('DELETE', `/site/extensions/${name.split('/').map(encodeURIComponent).join('/')}`, { remove }),
    /**
     * Install or update an extension ZIP. A 409 extension_review contains changes,
     * scripts and seed choices together; resubmit the ZIP with the reviewed decisions.
     */
    install: (file, { acceptChanges = false, allowScripts = false, seeds, onProgress } = {}) => {
      const body = new FormData()
      body.append('extension', file, file.name)
      if (acceptChanges) body.append('accept_changes', '1')
      if (allowScripts) body.append('allow_scripts', '1')
      if (seeds !== undefined) body.append('seeds', JSON.stringify(seeds))
      return requestWithProgress('POST', '/site/extensions', body, onProgress)
    },
  },

  fonts: {
    list: () => request('GET', '/site/fonts'),
    catalogue: (query = {}) => request('GET', withQuery('/site/fonts/catalogue', query)),
    details: (id) => request('GET', `/site/fonts/catalogue/${encodeURIComponent(id)}`),
    preview: (id) => request('POST', `/site/fonts/catalogue/${encodeURIComponent(id)}/preview`),
    jobs: () => request('GET', '/site/fonts/jobs'),
    install: (body, workspace) => request('POST', '/site/fonts/install', body, workspace),
    step: (id, cursor, workspace) => request('POST', `/site/fonts/jobs/${encodeURIComponent(id)}/step`, { cursor }, workspace),
    cancel: (id) => request('DELETE', `/site/fonts/jobs/${encodeURIComponent(id)}`),
    upload: (body) => request('POST', '/site/fonts/upload', body),
    remove: (id) => request('DELETE', `/site/fonts/${encodeURIComponent(id)}`),
  },

  // Profile (current user)
  profile: {
    update:       (data)     => request('PUT',    '/profile', data),
    uploadAvatar: (formData) => request('POST',   '/profile/avatar', formData),
    deleteAvatar: ()         => request('DELETE', '/profile/avatar'),
  },
}
