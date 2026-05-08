import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, getActiveWorkspace, setActiveWorkspace } from '../api/index.js'
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

  let initPromise = null

  async function init() {
    if (initPromise) return initPromise

    initPromise = (async () => {
      try {
        const res  = await api.me()
        user.value = res.data
        applyTheme(user.value?.theme)
        setLocale(user.value?.language)
        notSetUp.value = false
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
    user.value = res.data
    applyTheme(user.value?.theme)
    setLocale(user.value?.language)
    notSetUp.value = false
    return user.value
  }

  async function login(username, password) {
    const res  = await api.login(username, password)
    user.value = res.data
    applyTheme(user.value?.theme)
    setLocale(user.value?.language)
    notSetUp.value = false
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

  async function setup(username, password, workspaceName, workspaceSlug) {
    const res  = await api.setup(username, password, workspaceName, workspaceSlug)
    user.value = res.data
    applyTheme(user.value?.theme)
    setLocale(user.value?.language)
    notSetUp.value = false
    const ws = res.meta?.workspace
    if (ws) setActiveWorkspace(ws)
  }

  return {
    user, loading, notSetUp,
    isAuthenticated,
    can, init, refresh, login, logout, setup,
  }
})
