// CSRF token is seeded from the PHP-rendered meta tag on first load,
// then kept up-to-date from the X-CSRF-Token response header.
let csrfToken = (typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.content : null) ?? ''
let activeWorkspace = (typeof localStorage !== 'undefined' ? localStorage.getItem('cometcms.workspace') : null) || ''
let defaultWorkspace = ''

export function getActiveWorkspace() {
  return activeWorkspace || defaultWorkspace || 'default'
}

export function setActiveWorkspace(workspace) {
  activeWorkspace = workspace || defaultWorkspace || 'default'
  localStorage.setItem('cometcms.workspace', activeWorkspace)
}

export function getDefaultWorkspace() {
  return defaultWorkspace || activeWorkspace || 'default'
}

export function setDefaultWorkspace(workspace) {
  defaultWorkspace = workspace || activeWorkspace || 'default'

  if (!activeWorkspace) {
    setActiveWorkspace(defaultWorkspace)
  }
}

async function request(method, path, body = null) {
  const headers = { 'X-Requested-With': 'XMLHttpRequest' }
  headers['X-Comet-Workspace'] = getActiveWorkspace()

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

  const res = await fetch(`/admin/api${path}`, {
    method,
    headers,
    body: finalBody,
    credentials: 'same-origin',
  })

  // Always keep our CSRF token in sync with what the server returns.
  const newToken = res.headers.get('X-CSRF-Token')
  if (newToken) csrfToken = newToken

  if (res.status === 204) return null

  let json
  try {
    json = await res.json()
  } catch {
    const err = new Error(`Server error (HTTP ${res.status})`)
    err.code   = 'server_error'
    err.status = res.status
    throw err
  }

  if (!res.ok) {
    const err = new Error(json.error?.message ?? 'Request failed')
    err.code    = json.error?.code ?? 'unknown'
    err.fields  = json.error?.fields ?? {}
    err.status  = res.status
    err.retryAfter = json.error?.retry_after ?? null
    throw err
  }

  return json
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
  a.href = `/admin/api${path}${workspaceQuery}`
  a.style.display = 'none'
  document.body.appendChild(a)
  a.click()
  a.remove()
}

export const api = {
  // Auth
  me:     ()                  => request('GET',    '/me'),
  login:  (username, password)=> request('POST',   '/login',  { username, password }),
  logout: ()                  => request('POST',   '/logout'),
  setup:  (username, password, workspace, workspaceSlug)=> request('POST',   '/setup',  { username, password, workspace, workspace_slug: workspaceSlug }),
  appInfo: ()                  => request('GET',    '/app'),
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
    upload: (formData) => request('POST', '/media', formData),
    createCategory: (name, parent = '') => request('POST', '/media/categories', parent ? { name, parent } : { name }),
    renameCategory: (oldName, name) => request('PUT', `/media/categories/${encodeURIComponent(oldName)}`, { name }),
    deleteCategory: (name) => request('DELETE', `/media/categories/${encodeURIComponent(name)}`),
    updateCategory: (name, category) => request('PUT', `/media/${encodeURIComponent(name)}/category`, { category }),
    rename: (oldName, name) => request('PUT', `/media/${encodeURIComponent(oldName)}/rename`, { name }),
    updateMeta: (name, alt, title) => request('PUT', `/media/${encodeURIComponent(name)}/meta`, { alt, title }),
    updateVisibility: (name, visibility) => request('PUT', `/media/${encodeURIComponent(name)}/visibility`, { visibility }),
    bulkUpdateVisibility: (files, visibility) => request('PUT', '/media/bulk-visibility', { files, visibility }),
    bulkUpdateCategory: (files, category) => request('PUT', '/media/bulk-category', { files, category }),
    delete: (name) => request('DELETE', `/media/${encodeURIComponent(name)}`),
    bulkDelete: (files) => request('POST', '/media/bulk-delete', { files }),
    regenerateThumbnails: (files = []) => request('POST', '/media/thumbnails/regenerate', files.length > 0 ? { files } : {}),
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
    upload:   (formData) => request('POST', '/backups/upload', formData),
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

  // Profile (current user)
  profile: {
    update:       (data)     => request('PUT',    '/profile', data),
    uploadAvatar: (formData) => request('POST',   '/profile/avatar', formData),
    deleteAvatar: ()         => request('DELETE', '/profile/avatar'),
  },
}
