import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'

const routes = [
  { path: '/',              redirect: '/dashboard' },
  { path: '/login',         component: () => import('../views/LoginView.vue') },
  { path: '/setup',         component: () => import('../views/SetupView.vue') },
  {
    path: '/dashboard',
    component: () => import('../views/DashboardView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/content-types',
    component: () => import('../views/ContentTypesView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/content-types/new',
    component: () => import('../views/ContentTypeEditView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/content-types/:name/edit',
    component: () => import('../views/ContentTypeEditView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/content/:collection',
    component: () => import('../views/ContentRouteView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/content/:collection/new',
    component: () => import('../views/ContentEditView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/content/:collection/:id',
    component: () => import('../views/ContentEditView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/trash/:collection',
    component: () => import('../views/TrashView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/media',
    component: () => import('../views/MediaView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/users',
    component: () => import('../views/UsersView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/api-tokens',
    component: () => import('../views/ApiTokensView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/roles',
    component: () => import('../views/RolesView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/backups',
    component: () => import('../views/BackupRestoreView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/webhooks',
    component: () => import('../views/WebhooksView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/update',
    component: () => import('../views/UpdateView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/profile',
    component: () => import('../views/ProfileView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/api-explorer',
    component: () => import('../views/ApiExplorerView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/workspaces',
    component: () => import('../views/WorkspacesView.vue'),
    meta: { requiresAuth: true },
  },
]

const router = createRouter({
  history: createWebHistory('/admin'),
  routes,
})

let authInitialized = false

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!authInitialized) {
    await auth.init()
    authInitialized = true
  }

  if (auth.notSetUp && to.path !== '/setup') {
    return '/setup'
  }

  if (!auth.notSetUp && !auth.isAuthenticated && to.meta.requiresAuth) {
    return '/login'
  }

  if (auth.isAuthenticated && (to.path === '/login' || to.path === '/setup')) {
    return '/dashboard'
  }
})

export default router
