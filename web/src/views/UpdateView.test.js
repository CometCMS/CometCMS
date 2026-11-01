import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { createRenderer, nextTick, ssrContextKey } from 'vue'
import UpdateView from './UpdateView.vue'

const { api, toast } = vi.hoisted(() => ({
  api: { update: { status: vi.fn(), install: vi.fn() } },
  toast: { success: vi.fn(), error: vi.fn() },
}))
vi.mock('../api/index.js', () => ({ api }))
vi.mock('../stores/auth.js', () => ({ useAuthStore: () => ({ can: () => true }) }))
vi.mock('../stores/toast.js', () => ({ useToastStore: () => toast }))
vi.mock('@tiptap/vue-3', () => ({ useEditor: () => ({ value: null }), EditorContent: {} }))

const renderer = createRenderer({
  createComment: () => ({}), insert() {}, remove() {}, parentNode() {}, nextSibling() {},
})
let app
let state
let reload

beforeEach(async () => {
  vi.clearAllMocks()
  vi.useFakeTimers()
  reload = vi.fn()
  vi.stubGlobal('window', { setTimeout, location: { reload } })
  api.update.status.mockResolvedValue({ data: {
    current_version: '2.0.0',
    staged_update: { id: 'staged-release', version: '2.1.0' },
    update_available: true,
    message: 'A newer CometCMS release is available.',
  } })
  // Capture the view's state while exercising its real mount and install logic.
  app = renderer.createApp({
    setup(props, context) {
      state = UpdateView.setup(props, context)
      return () => null
    },
  })
  app.provide(ssrContextKey, {})
  app.mount({})
  await nextTick()
})

afterEach(() => {
  app?.unmount()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

it('shows the installed version and clears the staged update before reloading', async () => {
  api.update.install.mockResolvedValue({ data: { installed_version: '2.1.0' } })
  state.showInstallModal.value = true

  await state.installUpdate()

  expect(api.update.install).toHaveBeenCalledWith('staged-release')
  expect(state.status.value).toMatchObject({
    current_version: '2.1.0', staged_update: null, update_available: false,
    message: 'CometCMS is up to date.',
  })
  expect(state.showInstallModal.value).toBe(false)
  expect(reload).not.toHaveBeenCalled()
  await vi.advanceTimersByTimeAsync(500)
  expect(reload).toHaveBeenCalledOnce()
})

it('keeps the current version and staged update when installation fails', async () => {
  api.update.install.mockRejectedValue(new Error('Installation failed'))

  await state.installUpdate()

  expect(state.status.value.current_version).toBe('2.0.0')
  expect(state.stagedUpdate.value.id).toBe('staged-release')
  expect(state.installing.value).toBe(false)
  expect(toast.error).toHaveBeenCalledWith('Installation failed')
  await vi.runAllTimersAsync()
  expect(reload).not.toHaveBeenCalled()
})
