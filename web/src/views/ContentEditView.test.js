import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { createRenderer, nextTick, ssrContextKey } from 'vue'
import ContentEditView from './ContentEditView.vue'

const { api, route, beforeLeave, listeners } = vi.hoisted(() => ({
  api: {
    contentTypes: { get: vi.fn() },
    content: { get: vi.fn() },
    site: { links: vi.fn() },
  },
  route: { params: { collection: 'pages', id: 'stable-page-id' }, query: {} },
  beforeLeave: vi.fn(),
  listeners: {},
}))
vi.mock('../api/index.js', () => ({ api, getActiveWorkspace: () => 'test' }))
vi.mock('vue-router', () => ({
  useRoute: () => route, useRouter: () => ({ push: vi.fn() }),
  onBeforeRouteLeave: beforeLeave, onBeforeRouteUpdate: vi.fn(),
}))
vi.mock('../stores/auth.js', () => ({
  useAuthStore: () => ({ user: { id: 'author' }, can: (action) => action !== 'users.read' }),
}))
vi.mock('../stores/toast.js', () => ({ useToastStore: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('../stores/apiEndpoint.js', () => ({
  useApiEndpointStore: () => ({ setEndpoint: vi.fn(), clearEndpoint: vi.fn() }),
}))
vi.mock('../composables/useLanguages.js', async () => {
  const { ref } = await import('vue')
  return { useLanguages: () => ({ activeLocale: ref('de') }) }
})
vi.mock('@tiptap/vue-3', () => ({ useEditor: () => ({ value: null }), EditorContent: {} }))

const renderer = createRenderer({
  createComment: () => ({}), insert() {}, remove() {}, parentNode() {}, nextSibling() {},
})
let app
let state

beforeEach(() => {
  vi.clearAllMocks()
  route.params.id = 'stable-page-id'
  vi.stubGlobal('document', { addEventListener() {}, removeEventListener() {} })
  vi.stubGlobal('window', {
    location: { origin: 'http://localhost' },
    addEventListener: (event, handler) => { listeners[event] = handler },
    removeEventListener: (event) => { delete listeners[event] },
  })
  api.contentTypes.get.mockResolvedValue({ data: {
    label: 'Pages', fields: {}, locales: ['de', 'en'], default_locale: 'de',
  } })
  api.site.links.mockResolvedValue({ data: {} })
})

afterEach(() => {
  app?.unmount()
  vi.unstubAllGlobals()
})

async function mount() {
  app = renderer.createApp({
    setup(_, context) {
      state = ContentEditView.setup({}, context)
      return () => null
    },
  })
  app.provide(ssrContextKey, {})
  app.mount({})
  // Flush the schema and entry requests and the form's nextTick baseline.
  await vi.waitFor(() => expect(state.newEntryBaseline.value).not.toBeNull())
  await nextTick()
}

it('keeps a failed entry load clean and allows navigating away without an unsaved-changes prompt', async () => {
  api.content.get.mockRejectedValue(new Error('Entry not found.'))
  await mount()

  expect(api.content.get).toHaveBeenCalledWith('pages', 'stable-page-id')
  expect(state.loadError.value).toBe('Entry not found.')
  expect(state.isDirty.value).toBe(false)
  expect(beforeLeave.mock.calls[0][0]()).toBe(true)
  const event = { preventDefault: vi.fn() }
  listeners.beforeunload(event)
  expect(event.preventDefault).not.toHaveBeenCalled()
})

it('keeps a loaded entry clean and guards real edits', async () => {
  api.content.get.mockResolvedValue({ data: {
    id: 'old-storage-slug', uid: 'stable-page-id', author_id: 'author',
    values: { title: 'About us', slug: 'about-us' },
    publication: { de: { status: 'published', published_at: null } },
  } })
  await mount()

  expect(state.form.value.title).toBe('About us')
  expect(state.isDirty.value).toBe(false)
  state.form.value.title = 'Edited title'
  expect(state.isDirty.value).toBe(true)
  const event = { preventDefault: vi.fn() }
  listeners.beforeunload(event)
  expect(event.preventDefault).toHaveBeenCalledOnce()
})

it('tracks changes to a new entry after establishing its baseline', async () => {
  delete route.params.id
  await mount()

  expect(state.formLoaded.value).toBe(true)
  expect(state.isDirty.value).toBe(false)
  state.form.value.title = 'New page'
  expect(state.isDirty.value).toBe(true)
})
