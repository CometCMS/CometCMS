import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, getActiveWorkspace, setActiveWorkspace, syncWorkspaceFromUser } from '../api/index.js'
import { applyTheme, DEFAULT_THEME } from '../theme.js'
import { DEFAULT_ADMIN_LOCALE, setLocale } from '../i18n/index.js'
import { allowsPermission } from './permissions.js'

export const useAuthStore = defineStore('auth', () => {
  const user    = ref(null)
  const loading = ref(true)
  const notSetUp = ref(false)

  const isAuthenticated = computed(() => user.value !== null)

  function can(action, resource = null) {
    const grants = user.value?.capabilities?.permissions ?? []
    if (allowsPermission(grants, action, resource)) return true
    if (resource !== null) {
      return allowsPermission(grants, action, `workspace:${getActiveWorkspace()}:${resource}`)
    }
    return false
  }

  function adoptUser(data) {
    user.value = data
    syncWorkspaceFromUser(data, allowsPermission(data?.capabilities?.permissions ?? [], 'workspaces.read', null))
    applyTheme(user.value?.theme, user.value?.color_mode)
    setLocale(user.value?.language)
    notSetUp.value = false
  }

  let initPromise = null

  async function init() {
    if (initPromise) return initPromise

    initPromise = (async () => {
      try {
        const res  = await api.me()
        adoptUser(res.data)
      } catch (err) {
        if (err.status === 503) notSetUp.value = true
        user.value = null
        applyTheme(DEFAULT_THEME)
        setLocale(DEFAULT_ADMIN_LOCALE, { persist: false })
      } finally {
        loading.value = false
      }
    })()

    return initPromise
  }

  async function refresh() {
    const res = await api.me()
    adoptUser(res.data)
    return user.value
  }

  async function login(username, password, remember = false) {
    const res  = await api.login(username, password, remember)
    adoptUser(res.data)
    // A new sign-in starts in the configured default, regardless of the
    // workspace remembered from a previous session in this browser.
    if (res.data?.default_workspace) setActiveWorkspace(res.data.default_workspace)
  }

  async function logout() {
    try {
      await api.logout()
    } finally {
      user.value    = null
      initPromise   = null  // allow re-init after next login
      loading.value = false
      applyTheme(DEFAULT_THEME)
      setLocale(DEFAULT_ADMIN_LOCALE, { persist: false })
    }
  }

  /** Forget a session the server no longer accepts, without calling logout. */
  function expire() {
    user.value = null
    initPromise = null
  }

  async function setup(username, password, workspaceName, workspaceSlug, starterTemplate = 'website', languages) {
    const res  = await api.setup(username, password, workspaceName, workspaceSlug, starterTemplate, languages)
    adoptUser(res.data)
    const ws = res.meta?.workspace
    if (ws) setActiveWorkspace(ws)
  }

  return {
    user, loading, notSetUp,
    isAuthenticated,
    can, init, refresh, login, logout, setup, expire,
  }
})
