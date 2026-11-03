import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { computed, effectScope, nextTick, ref } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '../stores/auth.js'
import { useContentTypesStore } from '../stores/contentTypes.js'
import { useSiteStore } from '../stores/site.js'
import { useNavigation, pathMatches } from './useNavigation.js'
import { useNavigationSections } from './useNavigationSections.js'

let scope
let auth
let navigation

function grant(...actions) {
  auth.user = { capabilities: { permissions: [{ effect: 'allow', actions, resources: ['*'] }] } }
}

function item(key) {
  return navigation.groups.value.flatMap((group) => group.items).find((entry) => entry.key === key)
}

beforeEach(() => {
  setActivePinia(createPinia())
  scope = effectScope()
  scope.run(() => {
    auth = useAuthStore()
    grant('*')
    useSiteStore().pageBuilderActive = true
    navigation = useNavigation()
  })
})

afterEach(() => scope.stop())

describe('admin navigation', () => {
  it('exposes all design tools while keeping content directly accessible', () => {
    useContentTypesStore().list = [{ name: 'pages', label: 'Pages' }]
    expect(item('page-builder').children.map((entry) => entry.to)).toEqual([
      '/page-builder/design', '/page-builder/templates', '/page-builder/menus', '/page-builder/components', '/page-builder/fonts',
    ])
    expect(item('content:pages').to).toBe('/content/pages')
    expect(item('content:pages').children).toBeUndefined()
    expect(item('media').to).toBe('/media')
    useSiteStore().pageBuilderActive = false
    expect(item('page-builder')).toBeUndefined()
    expect(item('website-settings')).toBeDefined()
  })

  it('mirrors website settings tabs and highlights forms and extensions', () => {
    const site = useSiteStore()
    expect(item('website-settings').children.map((entry) => entry.to)).toEqual([
      '/website/general', '/website/seo', '/website/extensions',
    ])
    site.data = { ...site.data, types: [{ name: 'contact', allow_submissions: true }] }
    const settings = item('website-settings')
    expect(settings.children.map((entry) => entry.to)).toEqual([
      '/website/general', '/website/seo', '/website/forms', '/website/extensions',
    ])
    for (const path of ['/website/forms', '/website/extensions']) {
      expect(navigation.isActive(settings, path)).toBe(true)
      expect(navigation.isActive(settings.children.find((entry) => entry.to === path), path)).toBe(true)
    }
    site.data.types[0].allow_submissions = false
    expect(item('website-settings').children.some((entry) => entry.key === 'website-forms')).toBe(false)
  })

  it('hides unavailable setup options and simplifies a section with one permitted destination', () => {
    grant('content.read', 'users.read')
    expect(item('content-types')).toBeUndefined()
    expect(item('workspaces')).toBeUndefined()
    expect(item('backups')).toBeUndefined()
    expect(item('updates')).toBeUndefined()
    expect(item('users-list').to).toBe('/users')
    expect(item('users-list').children).toBeUndefined()
    expect(item('connect').children.map((entry) => entry.key)).toEqual(['connect-api', 'connect-mcp'])
    expect(navigation.groups.value.find((group) => group.key === 'website')).toBeUndefined()
  })

  it('highlights only the current destination, including default tabs and entry editors', () => {
    const settings = item('website-settings')
    const [general, seo] = settings.children
    expect(navigation.isActive(general, '/website')).toBe(true)
    expect(navigation.isActive(general, '/website/seo')).toBe(false)
    expect(navigation.isActive(seo, '/website/seo')).toBe(true)
    expect(navigation.isActive(settings, '/website/workspaces')).toBe(false)
    expect(navigation.isActive(item('page-builder').children[0], '/page-builder')).toBe(true)
    expect(navigation.isActive(item('connect').children[0], '/connect')).toBe(true)
    expect(navigation.isActive(item('page-builder'), '/page-builder/builder/region/header')).toBe(true)
    expect(pathMatches('/content/pages/hello/edit', '/content/pages')).toBe(true)
    expect(pathMatches('/content/pages-archive', '/content/pages')).toBe(false)
  })

  it('respects content permissions without losing schema order or trash highlighting', () => {
    useContentTypesStore().list = [
      { name: 'private', label: 'Private' },
      { name: 'pages', label: 'Pages' },
      { name: 'posts', label: 'Posts' },
    ]
    auth.user = { capabilities: { permissions: [
      { effect: 'allow', actions: ['content.read'], resources: ['content:pages:*', 'content:posts:*'] },
    ] } }
    expect(navigation.contentTypes.value.map((entry) => entry.name)).toEqual(['pages', 'posts'])
    expect(navigation.isActive(item('content:posts'), '/trash/posts')).toBe(true)
    expect(item('content:private')).toBeUndefined()
  })
})

describe('navigation disclosure', () => {
  it('allows inspecting and closing sections without changing the route', async () => {
    const path = ref('/dashboard')
    const sections = scope.run(() => useNavigationSections(navigation.groups, path, navigation.isActive))
    const design = item('page-builder')
    const settings = item('website-settings')
    sections.toggle(design)
    sections.toggle(settings)
    expect(sections.isExpanded(design)).toBe(true)
    expect(sections.isExpanded(settings)).toBe(true)
    expect(path.value).toBe('/dashboard')
    sections.toggle(design)
    expect(sections.isExpanded(design)).toBe(false)
    path.value = '/page-builder/fonts'
    await nextTick()
    expect(sections.isExpanded(design)).toBe(true)
    sections.toggle(design)
    expect(sections.isExpanded(design)).toBe(false)
    path.value = '/page-builder/menus'
    await nextTick()
    expect(sections.isExpanded(design)).toBe(true)
  })

  it('opens a deep-linked section and clears expansion when it becomes unavailable', async () => {
    const path = computed(() => '/page-builder/fonts')
    const sections = scope.run(() => useNavigationSections(navigation.groups, path, navigation.isActive))
    const design = item('page-builder')
    expect(sections.isExpanded(design)).toBe(true)
    grant('content.read')
    await nextTick()
    expect(sections.isExpanded(design)).toBe(false)
  })
})
