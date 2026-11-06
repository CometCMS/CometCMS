import { describe, expect, it } from 'vitest'
import { allowsPermission } from './permissions.js'

describe('auth permissions', () => {
  it('allows matching action and resource grants', () => {
    const grants = [
      { effect: 'allow', actions: ['content.*'], resources: ['content:posts:*'] },
    ]

    expect(allowsPermission(grants, 'content.update', 'content:posts:welcome')).toBe(true)
    expect(allowsPermission(grants, 'media.update', 'media:hero.jpg')).toBe(false)
  })

  it('lets deny grants override broader allows', () => {
    const grants = [
      { effect: 'allow', actions: ['content.*'], resources: ['content:*'] },
      { effect: 'deny', actions: ['content.delete'], resources: ['content:posts:locked'] },
    ]

    expect(allowsPermission(grants, 'content.update', 'content:posts:locked')).toBe(true)
    expect(allowsPermission(grants, 'content.delete', 'content:posts:locked')).toBe(false)
  })

  it('allows global resource checks when no resource is requested', () => {
    const grants = [
      { effect: 'allow', actions: ['dashboard.read'], resources: ['*'] },
    ]

    expect(allowsPermission(grants, 'dashboard.read')).toBe(true)
    expect(allowsPermission(grants, 'roles.delete')).toBe(false)
  })
})

describe('website permissions', () => {
  it('lets managing the website imply page content and layout editing, unless denied', () => {
    const manage = [{ effect: 'allow', actions: ['site.manage'], resources: ['*'] }]
    expect(allowsPermission(manage, 'site.pages')).toBe(true)
    expect(allowsPermission(manage, 'site.layouts')).toBe(true)

    const pages = [{ effect: 'allow', actions: ['site.pages'], resources: ['*'] }]
    expect(allowsPermission(pages, 'site.pages')).toBe(true)
    expect(allowsPermission(pages, 'site.layouts')).toBe(false)
    expect(allowsPermission(pages, 'site.manage')).toBe(false)

    const denied = [...manage, { effect: 'deny', actions: ['site.layouts'], resources: ['*'] }]
    expect(allowsPermission(denied, 'site.layouts')).toBe(false)
    expect(allowsPermission(denied, 'site.pages')).toBe(true)
  })
})
