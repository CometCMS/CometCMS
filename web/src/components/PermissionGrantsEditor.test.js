import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import PermissionGrantsEditor from './PermissionGrantsEditor.vue'
import { PERMISSION_ACTIONS, PERMISSION_AREAS } from '../composables/permissionLabels.js'

vi.mock('../stores/contentTypes.js', () => ({
  useContentTypesStore: () => ({ list: [], fetch: vi.fn() }),
}))

async function createEditor() {
  let editor
  const changes = []
  const app = createSSRApp(PermissionGrantsEditor, {
    modelValue: [],
    'onUpdate:modelValue': (grants) => changes.push(grants),
  })
  app.mixin({
    created() {
      if (this.$.type.__name === 'PermissionGrantsEditor') editor = this.$.setupState
    },
  })
  await renderToString(app)
  return { editor, changes }
}

describe('permission category selection', () => {
  it('includes every action, including website management, when all categories are selected', async () => {
    const { editor, changes } = await createEditor()
    for (const area of PERMISSION_AREAS) editor.toggleAreaAllGrants(area)

    const grants = changes.at(-1)
    expect(grants.flatMap((grant) => grant.actions)).toEqual(Object.values(PERMISSION_ACTIONS).flat())
    expect(grants).toContainEqual({ effect: 'allow', actions: ['site.manage', 'site.layouts', 'site.pages'], resources: ['*'] })
  })

  it('can deselect website management without removing other categories', async () => {
    const { editor, changes } = await createEditor()
    editor.toggleAreaAllGrants('media')
    editor.toggleAreaAllGrants('site')
    editor.toggleAreaAllGrants('site')

    expect(changes.at(-1)).toEqual([
      { effect: 'allow', actions: PERMISSION_ACTIONS.media, resources: ['media:*'] },
    ])
  })
})
