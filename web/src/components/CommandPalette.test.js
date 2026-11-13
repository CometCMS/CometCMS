import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { createRenderer, nextTick, ssrContextKey } from 'vue'
import CommandPalette from './CommandPalette.vue'

const { list, push } = vi.hoisted(() => ({ list: vi.fn(), push: vi.fn() }))
vi.mock('../api/index.js', () => ({ api: { content: { list } } }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))
vi.mock('../stores/auth.js', () => ({ useAuthStore: () => ({ can: () => false }) }))
vi.mock('../composables/useDialogFocus.js', () => ({ useDialogFocus: () => {} }))
vi.mock('../composables/useNavigation.js', async () => {
  const { ref } = await import('vue')
  return {
    contentTypeIcon: () => 'mdi:file-document-outline',
    useNavigation: () => ({
      groups: ref([]), contentTypes: ref([{ name: 'pages', label: 'Pages' }]),
    }),
  }
})

const renderer = createRenderer({
  createComment: () => ({}), insert() {}, remove() {}, parentNode() {}, nextSibling() {},
})
let app
let state

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers()
  app = renderer.createApp({
    setup(_, context) {
      state = CommandPalette.setup({ open: false }, context)
      return () => null
    },
  })
  app.provide(ssrContextKey, {})
  app.mount({})
})

afterEach(() => {
  app.unmount()
  vi.useRealTimers()
})

it.each([
  { id: 'old-storage-slug', uid: 'stable-page-id', title: 'About us', slug: 'about-us' },
  { id: 'legacy-page-id', title: 'About us', slug: 'about-us' },
])('opens search results using the stable entry identifier with an ID fallback: $id', async (entry) => {
  list.mockResolvedValue({ data: [entry] })
  state.query.value = 'About'
  await nextTick()
  await vi.advanceTimersByTimeAsync(200)

  expect(list).toHaveBeenCalledWith('pages', { q: 'About', limit: 4, offset: 0 })
  const result = state.results.value.find((item) => item.id.startsWith('entry:'))
  expect(result.label).toBe('About us')
  state.choose(result)
  expect(push).toHaveBeenCalledWith(`/content/pages/${entry.uid || entry.id}`)
})
