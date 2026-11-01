import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import { createSiteBuilder } from './useSiteBuilder.js'
import { fieldGroups, reference } from './smartValues.js'
import { findNode, resolveDrop } from './layoutTree.js'

describe('builder tabs', () => {
  it('edits normal elements inside tabs, supports history and duplicates panel IDs safely', async () => {
    const builder = await builderFixture()
    builder.spec.value.kinds.column.children.push('tabs')
    builder.spec.value.kinds.tabs = { props: { title: { default: 'Tabs' }, initial_tab: { default: 1 } }, children: ['tab'] }
    builder.spec.value.kinds.tab = { props: { title: { default: 'Tab' } }, children: ['text', 'heading', 'row', 'tabs'] }
    const column = builder.nodes.value[0].children[0].children[0]
    const tabs = builder.make('tabs')
    expect(tabs.children).toHaveLength(2)
    expect(builder.insert(tabs, column.id)).toBe(true)
    const second = tabs.children[1]
    const text = builder.make('text', { html: reference('intro') })
    expect(builder.insert(text, second.id)).toBe(true)
    expect(builder.entryContext(text.id).type.name).toBe('posts')
    expect(findNode(builder.nodes.value, second.id).children[0].id).toBe(text.id)
    builder.updateValue(tabs.id, 'props', 'initial_tab', 2)
    const heading = builder.make('heading')
    expect(builder.insert(heading, tabs.id)).toBe(true)
    expect(findNode(builder.nodes.value, second.id).children[1].id).toBe(heading.id)
    expect(resolveDrop(builder.spec.value, builder.nodes.value, second, null, 'inside')).toBeNull()
    builder.remove(second.id)
    expect(findNode(builder.nodes.value, second.id)).toBeNull()
    builder.undo()
    expect(findNode(builder.nodes.value, second.id).children).toHaveLength(2)
    builder.duplicate(tabs.id)
    const copy = builder.selected.value
    expect(copy.kind).toBe('tabs')
    expect(copy.id).not.toBe(tabs.id)
    expect(copy.children[1].id).not.toBe(second.id)
    expect(copy.children[1].children[0].id).not.toBe(text.id)
  })
})

async function builderFixture() {
  let builder
  await renderToString(createSSRApp({ setup() { builder = createSiteBuilder({ t: (key) => key }); return () => null } }))
  builder.spec.value = { root: ['section'], item_elements: ['field', 'text', 'heading'], kinds: {
    section: { props: {}, children: ['row'] }, row: { props: {}, children: ['column'] }, column: { props: {}, children: ['text', 'heading', 'field'] },
    text: { props: { html: { default: '' } }, children: [] }, heading: { props: { text: { default: '' } }, children: [] }, field: { props: { source: { default: '' } }, children: ['text', 'heading', 'field'] },
  } }
  builder.type.value = { name: 'posts', fields: { intro: { type: 'text' } } }
  builder.types.value = [builder.type.value, { name: 'company', fields: { steps: { type: 'repeater', subfields: [{ key: 'detail', type: 'textarea' }] } } }]
  const repeater = builder.make('field', { source: { ...reference('steps', 'entry'), collection: 'company', entry: 'CompanyUid' } }, {}, [builder.make('text', { html: reference('detail', 'item') })])
  builder.load([builder.make('section', {}, {}, [builder.make('row', {}, {}, [builder.make('column', {}, {}, [builder.make('text', { html: reference('intro') }), repeater])])])])
  return builder
}

describe('builder copy and paste', () => {
  it('copies content and style together and pastes only supported styles without changing content', async () => {
    const builder = await builderFixture()
    const section = builder.nodes.value[0]
    const column = section.children[0].children[0]
    const text = column.children[0]
    section.style = { background: 'accent', padding_top: { desktop: 'lg', mobile: 'sm' } }
    builder.spec.value.kinds.text.style = ['background']

    builder.copy(section.id)
    section.style.background = 'muted'
    expect(builder.styleClipboard.value.background).toBe('accent')
    builder.pasteStyle(text.id)
    expect(findNode(builder.nodes.value, text.id)).toMatchObject({ props: text.props, style: { background: 'accent' } })
    builder.paste(section.id)
    const pasted = builder.selected.value
    expect(pasted.id).not.toBe(section.id)
    expect(pasted.children[0].children[0].children[0].props).toEqual(text.props)
    expect(pasted.style).toEqual({ background: 'accent', padding_top: { desktop: 'lg', mobile: 'sm' } })

    // Each new copy replaces the style too, including elements without styling.
    builder.copy(column.id)
    expect(builder.styleClipboard.value).toEqual({})
    builder.pasteStyle(text.id)
    expect(findNode(builder.nodes.value, text.id).style).toEqual({})
  })
})

describe('builder spacing', () => {
  it('edits individual padding edges in one undo step and resets to the default', async () => {
    const builder = await builderFixture()
    const section = builder.nodes.value[0]
    section.style = { padding_top: 'lg', padding_bottom: 'lg', padding_left: 'md', padding_right: 'xs', background: 'accent' }
    builder.load(builder.nodes.value)
    builder.updateValue(section.id, 'style', 'padding_top', 'sm')
    expect(builder.nodes.value[0].style).toEqual({ padding_top: 'sm', padding_bottom: 'lg', padding_left: 'md', padding_right: 'xs', background: 'accent' })
    builder.undo()
    expect(builder.nodes.value[0].style).toEqual(section.style)
    builder.redo()
    builder.updateValue(section.id, 'style', 'padding_left', '')
    expect(builder.nodes.value[0].style).toEqual({ padding_top: 'sm', padding_bottom: 'lg', padding_right: 'xs', background: 'accent' })
    builder.undo()
    expect(builder.nodes.value[0].style.padding_left).toBe('md')
    builder.updateValue(section.id, 'style', 'margin_bottom', 'none')
    expect(builder.nodes.value[0].style.margin_bottom).toBe('none')
    builder.updateValue(section.id, 'style', 'margin_bottom', '')
    expect(builder.nodes.value[0].style).not.toHaveProperty('margin_bottom')
  })

  it('sets linked sides in one undo step', async () => {
    const builder = await builderFixture()
    const section = builder.nodes.value[0]
    section.style = { padding_top: 'lg', padding_bottom: 'lg', padding_left: 'md', padding_right: 'md', background: 'accent' }
    builder.load(builder.nodes.value)
    builder.editValues(section.id, 'style', { padding_top: '', padding_right: '', padding_bottom: '', padding_left: '' })
    expect(builder.nodes.value[0].style).toEqual({ background: 'accent' })
    builder.undo()
    expect(builder.nodes.value[0].style).toEqual(section.style)
  })
})

describe('builder page navigation', () => {
  it('removes disabled types from navigation while retaining their layouts and data bindings', async () => {
    const builder = await builderFixture()
    builder.types.value.push({ name: 'private', visibility: 'private' }, { name: 'unused' })
    builder.settings.value = { routes: { posts: { enabled: true }, company: { enabled: false }, private: { enabled: true } } }
    const saved = JSON.stringify(builder.nodes.value)

    expect(builder.pageTypes.value.map((type) => type.name)).toEqual(['posts'])
    builder.settings.value.routes.posts.enabled = false
    expect(builder.pageTypes.value).toEqual([])
    expect(JSON.stringify(builder.nodes.value)).toBe(saved)
    expect(builder.typeFields().some((field) => field.key === 'intro')).toBe(true)
    const repeater = builder.nodes.value[0].children[0].children[0].children[1]
    expect(builder.fieldConfig(repeater).type).toBe('repeater')

    builder.settings.value.routes.company.enabled = true
    expect(builder.pageTypes.value.map((type) => type.name)).toEqual(['company'])
    builder.settings.value = null
    expect(builder.pageTypes.value).toEqual([])
  })

  it('retains a public homepage without a separate route', async () => {
    const builder = await builderFixture()
    builder.settings.value = { routes: {}, homepage: { type: 'company', entry: '' } }
    expect(builder.pageTypes.value.map((type) => type.name)).toEqual(['company'])

    builder.settings.value.homepage = { type: 'posts', entry: '' }
    expect(builder.pageTypes.value.map((type) => type.name)).toEqual(['posts'])
    builder.settings.value.homepage.entry = 'welcome'
    expect(builder.pageTypes.value.map((type) => type.name)).toEqual(['posts'])

    builder.types.value[0].visibility = 'private'
    expect(builder.pageTypes.value).toEqual([])
    builder.settings.value.homepage.type = 'deleted'
    expect(builder.pageTypes.value).toEqual([])
  })
})

describe('builder element names', () => {
  it('renames nested nodes without changing their content and restores names through undo, redo and reload', async () => {
    const builder = await builderFixture()
    const node = builder.nodes.value[0].children[0].children[0].children[0]
    const props = JSON.stringify(node.props)
    builder.select(node.id)
    builder.renameNode(node.id, '  Intro text  ')
    expect(builder.nodeLabel(builder.selected.value)).toBe('Intro text')
    expect(JSON.stringify(builder.selected.value.props)).toBe(props)
    expect(builder.isDirty.value).toBe(true)
    builder.undo()
    expect(builder.nodeLabel(builder.selected.value)).toBe('site.kind.text')
    expect(builder.isDirty.value).toBe(false)
    builder.redo()
    expect(builder.selected.value.name).toBe('Intro text')
    builder.markSaved(JSON.parse(JSON.stringify(builder.nodes.value)))
    builder.load(JSON.parse(JSON.stringify(builder.nodes.value)))
    expect(builder.selected.value.name).toBe('Intro text')
    expect(builder.isDirty.value).toBe(false)
    builder.renameNode(node.id, '  ')
    expect(builder.selected.value).not.toHaveProperty('name')
    expect(builder.nodeLabel(builder.selected.value)).toBe('site.kind.text')
    builder.undo()
    expect(builder.selected.value.name).toBe('Intro text')
  })

  it('keeps names on duplicates and pasted branches and ignores unchanged or invalid renames', async () => {
    const builder = await builderFixture()
    const section = builder.nodes.value[0]
    builder.renameNode(section.id, 'Hero')
    builder.renameNode(section.children[0].id, 'Hero columns')
    builder.duplicate(section.id)
    expect(builder.selected.value.name).toBe('Hero')
    expect(builder.selected.value.children[0].name).toBe('Hero columns')
    expect(builder.selected.value.id).not.toBe(section.id)
    builder.copy(section.id)
    builder.paste()
    expect(builder.selected.value.name).toBe('Hero')
    builder.markSaved(JSON.parse(JSON.stringify(builder.nodes.value)))
    builder.load(builder.nodes.value)
    builder.renameNode(section.id, 'Hero')
    builder.renameNode('missing', 'Nothing')
    builder.renameNode(section.id, null)
    expect(builder.canUndo.value).toBe(false)
    expect(builder.isDirty.value).toBe(false)
    builder.renameNode(section.id, 'ä'.repeat(121))
    expect(builder.nodes.value[0].name).toBe('ä'.repeat(120))
  })
})

describe('builder integration with smart values', () => {
  it('retains references through undo, redo, copy, paste, save and reload', async () => {
    const builder = await builderFixture()
    const original = builder.nodes.value[0].children[0].children[0].children[0]
    builder.select(original.id)
    builder.updateValue(original.id, 'props', 'html', { type: 'template', parts: ['Welcome ', reference('title', 'site')] })
    expect(builder.nodeSummary(builder.selected.value)).toBe('Welcome [site › title]')
    builder.undo()
    expect(builder.selected.value.props.html).toEqual(reference('intro'))
    builder.redo()
    const edited = structuredClone(JSON.parse(JSON.stringify(builder.selected.value.props)))
    builder.copy()
    expect(builder.paste()).toBe(true)
    expect(builder.selected.value.id).not.toBe(original.id)
    expect(builder.selected.value.props).toEqual(edited)
    builder.markSaved(JSON.parse(JSON.stringify(builder.nodes.value)))
    expect(builder.isDirty.value).toBe(false)
    const saved = JSON.stringify(builder.nodes.value)
    builder.load(JSON.parse(saved))
    expect(JSON.stringify(builder.nodes.value)).toBe(saved)
  })
  it('derives repeater item schemas from cross-type references and preserves them when duplicated', async () => {
    const builder = await builderFixture()
    const repeater = builder.nodes.value[0].children[0].children[0].children[1]
    expect(builder.isTemplateField(repeater)).toBe(true)
    expect(builder.subfieldsOf(repeater)[0].key).toBe('detail')
    expect(builder.templateOwner(repeater.children[0].id).id).toBe(repeater.id)
    builder.select(repeater.id)
    builder.copy()
    builder.paste()
    const copy = builder.selected.value
    expect(copy.id).not.toBe(repeater.id)
    expect(copy.children[0].id).not.toBe(repeater.children[0].id)
    expect(copy.children[0].props.html.source).toBe('item')
    expect(builder.templateOwner(copy.children[0].id).id).toBe(copy.id)
  })
  it('keeps invalid edits detectable across selection and undo history', async () => {
    const builder = await builderFixture()
    const text = builder.nodes.value[0].children[0].children[0].children[0]
    builder.updateValue(text.id, 'props', 'html', { type: 'invalid', text: '{{ incomplete' })
    builder.select(null)
    expect(builder.hasSyntaxErrors.value).toBe(true)
    builder.undo()
    expect(builder.hasSyntaxErrors.value).toBe(false)
    builder.redo()
    expect(builder.hasSyntaxErrors.value).toBe(true)
  })
})

async function componentFixture(componentApi) {
  let builder
  await renderToString(createSSRApp({ setup() { builder = createSiteBuilder({ t: (key) => key, componentApi }); return () => null } }))
  builder.spec.value = { root: ['section'], item_elements: ['text'], kinds: {
    section: { props: {}, children: ['row'] }, row: { props: {}, children: ['column'] }, column: { props: { span: { default: { mobile: 12, tablet: 0, desktop: 0 } } }, children: ['text'] },
    text: { props: { html: { default: '' } }, children: [] },
    component: { props: { component: { default: '' }, root: { default: 'section' }, span: { default: { mobile: 12, tablet: 0, desktop: 0 } } }, children: [] },
  } }
  const column = builder.make('column', { span: { mobile: 12, tablet: 6, desktop: 0 } }, {}, [builder.make('text', { html: reference('intro') })])
  builder.load([builder.make('section', {}, {}, [builder.make('row', {}, {}, [column, builder.make('column')])])])
  return { builder, column }
}

describe('builder components', () => {
  it('converts a column into an instance in one undo step and keeps its width', async () => {
    const created = []
    const { builder, column } = await componentFixture({
      create: async (body) => {
        created.push(body)
        return { id: 'cabc', name: body.name, root: 'column', type: body.type, nodes: body.nodes }
      },
    })
    builder.type.value = { name: 'posts' }

    const component = await builder.convertToComponent(column.id, 'Intro')
    expect(component.id).toBe('cabc')
    expect(created[0].type).toBe('posts')
    expect(created[0].nodes[0].id).toBe(column.id)
    const row = builder.nodes.value[0].children[0]
    expect(row.children[0]).toMatchObject({ kind: 'component', props: { component: 'cabc', root: 'column', span: { mobile: 12, tablet: 6, desktop: 0 } } })
    expect(builder.selectedId.value).toBe(row.children[0].id)
    expect(builder.components.value).toEqual([{ id: 'cabc', name: 'Intro', root: 'column', type: 'posts' }])
    expect(builder.nodeLabel(row.children[0])).toBe('Intro')

    builder.undo()
    expect(builder.nodes.value[0].children[0].children[0].id).toBe(column.id)
  })

  it('detaches an instance into a copy with fresh ids and the instance width', async () => {
    const stored = { id: 'cabc', root: 'column', nodes: [{ id: 'root', kind: 'column', props: { span: { mobile: 12, tablet: 0, desktop: 0 } }, style: {}, children: [{ id: 'inner', kind: 'text', props: { html: 'Hi' }, style: {}, children: [] }] }] }
    const { builder } = await componentFixture({ load: async () => stored })
    const instance = builder.make('component', { component: 'cabc', root: 'column', span: { mobile: 12, tablet: 4, desktop: 0 } })
    builder.insert(instance, builder.nodes.value[0].children[0].children[1].id)
    builder.renameNode(instance.id, 'Sidebar promo')
    expect(builder.nodes.value[0].children[0].children[2].id).toBe(instance.id)

    expect(await builder.detach(instance.id)).toBe(true)
    const copy = builder.nodes.value[0].children[0].children[2]
    expect(copy.kind).toBe('column')
    expect(copy.name).toBe('Sidebar promo')
    expect(copy.id).not.toBe('root')
    expect(copy.children[0].id).not.toBe('inner')
    expect(copy.children[0].props.html).toBe('Hi')
    expect(copy.props.span).toEqual({ mobile: 12, tablet: 4, desktop: 0 })
    expect(stored.nodes[0].id).toBe('root')
  })

  it('marks props editable only while editing a component', async () => {
    const { builder, column } = await componentFixture({})
    const text = column.children[0]
    builder.toggleEditable(text.id, 'html')
    expect(findNode(builder.nodes.value, text.id).editable).toBeUndefined()

    builder.componentId.value = 'cabc'
    builder.toggleEditable(text.id, 'html')
    expect(builder.isEditable(findNode(builder.nodes.value, text.id), 'html')).toBe(true)
    builder.toggleEditable(text.id, 'html')
    expect(findNode(builder.nodes.value, text.id).editable).toBeUndefined()
  })

  it('sets, translates and resets an instance\'s own values, and keeps them when detaching', async () => {
    const stored = { id: 'cabc', root: 'column', nodes: [{ id: 'root', kind: 'column', props: { span: { mobile: 12, tablet: 0, desktop: 0 } }, style: {}, children: [
      { id: 'own', kind: 'text', props: { html: 'Shared' }, style: {}, editable: ['html'], children: [] },
      { id: 'fixed', kind: 'text', props: { html: 'Fixed' }, style: {}, children: [] },
    ] }] }
    const { builder } = await componentFixture({ load: async () => stored })
    builder.spec.value.kinds.text.props.html.localized = true
    builder.components.value = [{ id: 'cabc', name: 'Promo', root: 'column' }]
    const instance = builder.make('component', { component: 'cabc', root: 'column' })
    builder.insert(instance, builder.nodes.value[0].children[0].children[1].id)

    await builder.loadComponentTree('cabc')
    const current = () => findNode(builder.nodes.value, instance.id)
    expect(builder.instanceFields(current()).map((item) => [item.node.id, item.keys])).toEqual([['own', ['html']]])

    builder.setInstanceValue(instance.id, 'own', 'html', 'Mine')
    builder.setInstanceValue(instance.id, 'fixed', 'html', 'Ignored')
    expect(builder.instanceFields(current())[0].node.props.html).toEqual({ type: 'localized', values: { en: 'Mine' } })
    builder.locale.value = 'de'
    builder.setInstanceValue(instance.id, 'own', 'html', 'Meins')
    expect(current().overrides.own.html.values).toMatchObject({ en: 'Mine', de: 'Meins' })
    builder.resetInstanceValue(instance.id, 'own', 'html', { translation: true })
    expect(current().overrides.own.html.values).toEqual({ en: 'Mine' })
    builder.locale.value = 'en'

    expect(await builder.detach(instance.id)).toBe(true)
    const copy = builder.selected.value
    expect(copy.children.map((item) => item.props.html?.values?.en ?? item.props.html)).toEqual(['Mine', 'Fixed'])
    builder.undo()
    builder.resetInstanceValue(instance.id, 'own', 'html')
    expect(current().overrides).toBeUndefined()
  })

  it('keeps the single root of a component in place', async () => {
    const { builder } = await componentFixture({ create: async () => ({}) })
    builder.rootKind.value = 'section'
    builder.componentId.value = 'cself'
    const root = builder.nodes.value[0]

    expect(builder.isLockedRoot(root.id)).toBe(true)
    expect(builder.canConvert(root)).toBe(false)
    expect(builder.canConvert(root.children[0])).toBe(true)
    builder.remove(root.id)
    builder.duplicate(root.id)
    builder.addSectionAfter(root.id)
    expect(builder.insert(builder.make('section'), root.id)).toBe(false)
    expect(builder.nodes.value).toHaveLength(1)
    expect(builder.canUndo.value).toBe(false)

    expect(builder.insert(builder.make('text'), root.children[0].children[1].id)).toBe(true)
  })

  it('inserts components of any type anywhere, except into themselves', async () => {
    const { builder } = await componentFixture({})
    const shared = { id: 'shared', type: '' }
    const typed = { id: 'typed', type: 'posts' }
    builder.components.value = [shared, typed]
    expect(builder.canInsertComponent(shared)).toBe(true)
    // A component's type only picks its preview entries.
    builder.type.value = { name: 'pages' }
    expect(builder.canInsertComponent(typed)).toBe(true)
    builder.currentAvailable.value = false
    expect(builder.canInsertComponent(typed)).toBe(true)
    builder.componentId.value = 'shared'
    expect(builder.canInsertComponent(shared)).toBe(false)
    expect(builder.components.value).toHaveLength(2)
  })

  it('offers a component the fields of every page type, grouped by type', async () => {
    const { builder } = await componentFixture({})
    builder.settings.value = { routes: { posts: { enabled: true }, pages: { enabled: true } } }
    builder.types.value = [
      { name: 'posts', label: 'Posts', fields: { body: { type: 'html' }, featured: { type: 'boolean' } } },
      { name: 'pages', label: 'Pages', fields: { body: { type: 'html' }, intro: { type: 'textarea' } } },
      { name: 'secrets', visibility: 'private', fields: { code: { type: 'text' } } },
    ]
    builder.componentId.value = 'card'
    const context = builder.entryContext(null)
    expect(context.currentAvailable).toBe(true)
    expect(Object.keys(context.type.fields)).toEqual(['body', 'featured', 'intro'])
    expect(context.type.type_labels).toEqual(['Posts', 'Pages'])
    const groups = fieldGroups(builder.typeFields(null), context.type, 'All types')
    expect(groups.map((group) => [group.label, group.items.map((field) => field.key)])).toEqual([
      ['All types', ['title', 'published_at', 'updated_at', 'body']],
      ['Posts', ['featured']],
      ['Pages', ['intro']],
    ])
  })

  it('detaches deleted components throughout the draft, undo, redo and clipboard without losing pending edits', async () => {
    const component = { id: 'shared', root: 'column', nodes: [{ id: 'root', kind: 'column', props: { span: { mobile: 12 } }, style: { background: 'primary' }, children: [{ id: 'heading', kind: 'text', props: { html: 'Shared content' }, style: {}, children: [] }] }] }
    const { builder } = await componentFixture({ remove: async () => component })
    builder.components.value = [component]
    const instance = builder.make('component', { component: 'shared', root: 'column', span: { mobile: 12, tablet: 4, desktop: 3 } })
    instance.name = 'Sidebar promo'
    const sibling = builder.make('column', {}, {}, [builder.make('text', { html: 'Before' })])
    builder.load([builder.make('section', {}, {}, [builder.make('row', {}, {}, [instance, sibling])])])
    const textId = sibling.children[0].id
    builder.copy(instance.id)
    builder.select(instance.id)
    builder.updateValue(textId, 'props', 'html', 'Pending')
    builder.mutate((tree) => { tree[0].children[0].children[1].children[0].props.html = 'Future' })
    builder.undo()

    await builder.deleteComponent('shared')
    const detached = builder.selected.value
    expect(detached).toMatchObject({ id: instance.id, kind: 'column', props: { span: { mobile: 12, tablet: 4, desktop: 3 } }, style: { background: 'primary' } })
    expect(detached.name).toBe('Sidebar promo')
    expect(detached.children[0].props.html).toBe('Shared content')
    expect(detached.children[0].id).not.toBe('heading')
    expect(builder.components.value).toEqual([])
    expect(builder.clipboard.value.kind).toBe('column')
    expect(builder.isDirty.value).toBe(true)
    expect(builder.nodes.value[0].children[0].children[1].children[0].props.html).toBe('Pending')
    builder.redo()
    expect(builder.nodes.value[0].children[0].children[1].children[0].props.html).toBe('Future')
    expect(builder.selected.value).toEqual(detached)
    builder.undo()
    builder.undo()
    expect(builder.isDirty.value).toBe(false)
    expect(builder.selected.value).toEqual(detached)
  })

  it('leaves the current state and history intact when deleting fails', async () => {
    const { builder } = await componentFixture({ remove: async () => { throw new Error('Request failed') } })
    builder.components.value = [{ id: 'shared', name: 'Shared' }]
    builder.mutate((tree) => { tree.push(builder.make('section')) })
    const before = JSON.stringify(builder.nodes.value)
    await expect(builder.deleteComponent('shared')).rejects.toThrow('Request failed')
    expect(JSON.stringify(builder.nodes.value)).toBe(before)
    expect(builder.components.value).toHaveLength(1)
    expect(builder.canUndo.value).toBe(true)
  })
})


describe('builder linked state', () => {
  it('undoes and redoes linked edits in order with tree edits', async () => {
    let builder
    const state = { theme: null }
    await renderToString(createSSRApp({ setup() {
      builder = createSiteBuilder({ t: (key) => key, linked: { get: () => state.theme, set: (theme) => { state.theme = theme } } })
      return () => null
    } }))
    builder.spec.value = { root: ['section'], kinds: { section: { props: {}, children: [] } } }
    builder.load([])

    // The first theme to arrive is the loaded one, not an edit.
    state.theme = { primary: '#000000' }
    builder.linkedChanged()
    expect(builder.canUndo.value).toBe(false)

    builder.mutate((tree) => { tree.push(builder.make('section')) })
    state.theme = { primary: '#ff0000' }
    builder.linkedChanged()

    builder.undo()
    expect(state.theme).toEqual({ primary: '#000000' })
    expect(builder.nodes.value).toHaveLength(1)
    builder.undo()
    expect(builder.nodes.value).toHaveLength(0)
    expect(builder.canUndo.value).toBe(false)
    builder.redo()
    builder.redo()
    expect(state.theme).toEqual({ primary: '#ff0000' })

    // A saved theme replaces the baseline without an undo step.
    state.theme = { primary: '#ff0000', updated_at: 'now' }
    builder.linkedChanged({ record: false })
    builder.undo()
    expect(state.theme).toEqual({ primary: '#000000' })
  })

  it('keeps a restored linked state through undo and redo of earlier tree edits', async () => {
    let builder
    const state = { theme: null }
    await renderToString(createSSRApp({ setup() {
      builder = createSiteBuilder({ t: (key) => key, linked: { get: () => state.theme, set: (theme) => { state.theme = theme } } })
      return () => null
    } }))
    builder.spec.value = { root: ['section'], kinds: { section: { props: {}, children: [] } } }
    builder.load([])
    state.theme = { primary: '#000000' }
    builder.linkedChanged()

    builder.mutate((tree) => { tree.push(builder.make('section')) })
    state.theme = { primary: '#ff0000' }
    builder.linkedChanged()
    builder.mutate((tree) => { tree.push(builder.make('section')) })
    builder.undo()

    state.theme = { primary: '#00ff00' }
    builder.rebaseLinked()

    // The theme-only step is gone; the layout steps remain and keep the restored theme.
    builder.undo()
    expect(builder.nodes.value).toHaveLength(0)
    expect(state.theme).toEqual({ primary: '#00ff00' })
    expect(builder.canUndo.value).toBe(false)
    builder.redo()
    builder.redo()
    expect(builder.nodes.value).toHaveLength(2)
    expect(state.theme).toEqual({ primary: '#00ff00' })
  })
})

async function contentFixture(fieldApi) {
  let builder
  await renderToString(createSSRApp({ setup() { builder = createSiteBuilder({ t: (key) => key, fieldApi }); return () => null } }))
  builder.spec.value = { root: ['section'], item_elements: ['heading'], kinds: {
    section: { props: {}, children: ['row'] }, row: { props: {}, children: ['column'] }, column: { props: {}, children: ['heading', 'image', 'field'] },
    heading: { props: { text: { default: '' } }, children: [] }, image: { props: { media: { default: '' } }, children: [] },
    field: { props: { source: { default: '' } }, children: ['heading'] },
  } }
  builder.type.value = { name: 'posts', label: 'Posts', fields: { title: { type: 'text' }, heading: { type: 'text' } } }
  builder.types.value = [builder.type.value, { name: 'company', fields: { steps: { type: 'repeater', subfields: [{ key: 'name', type: 'text' }] } } }]
  const heading = builder.make('heading', { text: 'Our story' })
  const image = builder.make('image', { media: 'team.jpg' })
  const bound = builder.make('heading', { text: reference('title') })
  const template = builder.make('field', { source: { ...reference('steps', 'entry'), collection: 'company', entry: 'CompanyUid' } }, {}, [builder.make('heading', { text: 'Step' })])
  builder.load([builder.make('section', {}, {}, [builder.make('row', {}, {}, [builder.make('column', {}, {}, [heading, image, bound, template])])])])
  return { builder, heading, image, bound, item: template.children[0] }
}

describe('builder custom fields', () => {
  it('offers literal content for conversion, but not bound or template content', async () => {
    const { builder, heading, image, bound, item } = await contentFixture({ create: async () => null, can: () => true })
    expect(builder.canMakeField(heading)).toBe(true)
    expect(builder.canMakeField(image)).toBe(true)
    expect(builder.canMakeField(bound)).toBe(false)
    expect(builder.canMakeField(item)).toBe(false)
    builder.currentAvailable.value = false
    expect(builder.canMakeField(heading)).toBe(false)
  })

  it('needs permission to change the content type', async () => {
    const { builder, heading } = await contentFixture({ create: async () => null, can: () => false })
    expect(builder.canMakeField(heading)).toBe(false)
  })

  it('adds a uniquely named field and binds the element with its content as fallback, in one undo step', async () => {
    const calls = []
    const { builder, heading, image } = await contentFixture({
      can: () => true,
      create: async ({ type, key, config }) => {
        calls.push({ type, key, config })
        return { name: type, fields: { ...builder.type.value.fields, [key]: config } }
      },
    })
    expect(await builder.convertToField(heading.id, 'Heading')).toBe('heading_2')
    expect(calls[0]).toEqual({ type: 'posts', key: 'heading_2', config: { type: 'text', label: 'Heading', required: false, default: 'Our story' } })
    const converted = builder.nodes.value[0].children[0].children[0].children[0]
    expect(converted.props.text).toEqual({ ...reference('heading_2'), filters: [{ name: 'fallback', value: 'Our story' }] })
    expect(builder.type.value.fields.heading_2.type).toBe('text')
    expect(builder.types.value[0].fields.heading_2).toBeTruthy()

    expect(await builder.convertToField(image.id, 'Team photo')).toBe('team_photo')
    expect(calls[1].config).toEqual({ type: 'media', label: 'Team photo', required: false, localized: false })

    builder.undo()
    builder.undo()
    expect(builder.nodes.value[0].children[0].children[0].children[0].props.text).toBe('Our story')
  })

  it('designs collection cards in place with the fields of the listed type', async () => {
    let builder
    const card = { id: 'std', kind: 'column', props: {}, style: { shadow: 'md' }, children: [{ id: 'std-t', kind: 'text', props: { html: reference('title') }, style: {}, children: [] }] }
    const templateApi = { load: async (type) => (type === 'posts' ? { nodes: [card] } : null) }
    await renderToString(createSSRApp({ setup() { builder = createSiteBuilder({ t: (key) => key, templateApi }); return () => null } }))
    builder.spec.value = { root: ['section'], item_elements: [], kinds: {
      section: { props: {}, children: ['row'] }, row: { props: {}, children: ['column'] }, column: { props: {}, children: ['text', 'collection', 'row'] },
      text: { props: { html: { default: '' } }, children: [] }, collection: { props: { type: { default: '' } }, children: ['column'] },
    } }
    builder.type.value = { name: 'pages', fields: {} }
    builder.currentAvailable.value = false
    builder.types.value = [builder.type.value, { name: 'posts', fields: { intro: { type: 'text' } } }]
    const list = builder.make('collection', { type: 'posts' })
    builder.load([builder.make('section', {}, {}, [builder.make('row', {}, {}, [builder.make('column', {}, {}, [list])])])])

    const copy = await builder.customizeCard(list.id)
    expect(copy.id).not.toBe('std')
    expect(copy.children[0].id).not.toBe('std-t')
    expect(builder.selectedId.value).toBe(copy.id)
    expect(builder.nodeLabel(copy)).toBe('site.kind.card')
    expect(builder.entryContext(copy.children[0].id)).toMatchObject({ type: { name: 'posts' }, currentAvailable: true })
    expect(builder.entryContext(list.id)).toMatchObject({ type: { name: 'pages' }, currentAvailable: false })
    expect(builder.typeFields(copy.id).map((field) => field.key)).toContain('intro')
    builder.duplicate(copy.id)
    expect(builder.nodes.value[0].children[0].children[0].children[0].children).toHaveLength(1)

    builder.resetCard(list.id)
    expect(builder.nodes.value[0].children[0].children[0].children[0].children).toEqual([])
    builder.undo()
    expect(builder.nodes.value[0].children[0].children[0].children[0].children).toHaveLength(1)
  })
})


describe('loading layout revisions', () => {
  it('keeps the saved baseline and unsaved edits, supports undo/redo, and only becomes saved explicitly', async () => {
    const builder = await builderFixture()
    const saved = builder.savedSnapshot.value
    builder.mutate((nodes) => { nodes[0].name = 'Unsaved work' })
    const draft = JSON.parse(JSON.stringify(builder.nodes.value))
    const revision = [builder.make('section')]

    builder.loadRevision(revision)
    expect(builder.nodes.value).toEqual(revision)
    expect(builder.savedSnapshot.value).toBe(saved)
    expect(builder.isDirty.value).toBe(true)
    builder.undo()
    expect(builder.nodes.value).toEqual(draft)
    builder.redo()
    expect(builder.nodes.value).toEqual(revision)
    builder.loadRevision(draft)
    expect(builder.nodes.value).toEqual(draft)
    expect(builder.isDirty.value).toBe(true)
    builder.markSaved(draft)
    expect(builder.isDirty.value).toBe(false)
  })
})

describe('builder localization', () => {
  it('keeps translated values on node identities through language changes history and duplication', async () => {
    const builder = await builderFixture()
    builder.settings.value = { default_locale: 'en', locales: ['en', 'de'] }
    builder.spec.value.kinds.heading.props.text.localized = true
    builder.locale.value = 'en'
    const column = builder.nodes.value[0].children[0].children[0]
    const heading = builder.make('heading', { text: 'English' })
    builder.insert(heading, column.id)
    builder.locale.value = 'de'
    builder.updateValue(heading.id, 'props', 'text', 'Deutsch')
    expect(builder.valueFor(findNode(builder.nodes.value, heading.id), 'props', 'text')).toBe('Deutsch')
    expect(findNode(builder.nodes.value, heading.id).props.text.values).toEqual({ en: 'English', de: 'Deutsch' })
    expect(builder.insert(builder.make('text'), column.id)).toBe(false)
    builder.remove(heading.id)
    expect(findNode(builder.nodes.value, heading.id)).not.toBeNull()
    builder.undo()
    expect(builder.valueFor(findNode(builder.nodes.value, heading.id), 'props', 'text')).toBe('English')
    builder.redo()
    expect(builder.valueFor(findNode(builder.nodes.value, heading.id), 'props', 'text')).toBe('Deutsch')
    builder.locale.value = 'en'
    expect(builder.translating.value).toBe(false)
    builder.duplicate(heading.id)
    const copy = builder.selected.value
    expect(copy.id).not.toBe(heading.id)
    expect(copy.props.text.values).toEqual({ en: 'English', de: 'Deutsch' })
    builder.locale.value = 'en'
    builder.updateValue(heading.id, 'props', 'text', 'Changed source')
    builder.locale.value = 'de'
    expect(builder.translationState(findNode(builder.nodes.value, heading.id), 'props', 'text')).toBe('review')
    expect(builder.valueFor(copy, 'props', 'text')).toBe('Deutsch')
    builder.resetTranslation(heading.id, 'props', 'text')
    expect(builder.valueFor(findNode(builder.nodes.value, heading.id), 'props', 'text')).toBe('Changed source')
  })
})

describe('builder page content', () => {
  async function pageFixture({ fields = { design: { type: 'page', label: 'Design' } }, fieldApi = {}, clipboardStorage } = {}) {
    let builder
    await renderToString(createSSRApp({ setup() { builder = createSiteBuilder({ t: (key) => key, fieldApi, clipboardStorage }); return () => null } }))
    builder.spec.value = { root: ['section', 'page_content'], kinds: {
      section: { props: {}, children: ['row'] }, row: { props: {}, children: ['column'] },
      column: { props: {}, children: ['text', 'page_content'] }, text: { props: { html: { default: '' } }, children: [] },
      page_content: { props: { field: { default: '' } }, children: ['section'] },
    } }
    builder.type.value = { name: 'pages', fields: { intro: { type: 'text' }, ...fields } }
    builder.types.value = [builder.type.value]
    builder.load([builder.make('section', {}, {}, [builder.make('row', {}, {}, [builder.make('column')])])])
    return builder
  }

  it('places page content once per field, never inside itself, and keeps it out of copies and components', async () => {
    const builder = await pageFixture()
    const place = builder.make('page_content')
    expect(builder.insert(place, null)).toBe(true)
    await Promise.resolve()
    expect(findNode(builder.nodes.value, place.id).props.field).toBe('design')
    expect(builder.typeFields().map((field) => field.key)).not.toContain('design')

    const column = builder.nodes.value[0].children[0].children[0]
    expect(builder.insert(builder.make('page_content', { field: 'design' }), column.id)).toBe(false)
    const inner = builder.make('section', {}, {}, [builder.make('row', {}, {}, [builder.make('column')])])
    expect(builder.insert(inner, place.id)).toBe(true)
    const innerColumn = inner.children[0].children[0]
    expect(builder.insert(builder.make('page_content', { field: 'other' }), innerColumn.id)).toBe(false)

    builder.select(place.id)
    builder.duplicate()
    expect(builder.nodes.value.filter((node) => node.kind === 'page_content')).toHaveLength(1)
    expect(builder.holdsPageContent(place)).toBe(true)
    expect(builder.holdsPageContent(builder.nodes.value[0])).toBe(false)
    expect(builder.isDropContainer(place)).toBe(true)
  })

  it('pastes saved entry content as ordered sections with one undo step and no new fields', async () => {
    const items = new Map()
    const clipboardStorage = { getItem: (key) => items.get(key) ?? null, setItem: (key, value) => items.set(key, value), removeItem: (key) => items.delete(key) }
    const source = await pageFixture({ clipboardStorage })
    const sections = ['First', 'Second'].map((html) => source.make('section', { width: 'full' }, { background: 'accent' }, [
      source.make('row', {}, {}, [source.make('column', {}, {}, [source.make('text', { html })])]),
    ]))
    sections[0].name = 'Hero'
    sections[0].responsive = { mobile: { style: { padding_top: 'sm' } } }
    sections[0].states = { hover: { style: { background: 'muted' } } }
    const place = source.make('page_content', {}, {}, sections)
    expect(source.insert(place, null)).toBe(true)
    await Promise.resolve()
    source.copy(place.id)

    const created = []
    const target = await pageFixture({ fields: {}, clipboardStorage, fieldApi: { can: () => true, create: async (field) => { created.push(field); return null } } })
    target.spec.value.root = ['section']
    target.spec.value.kinds.column.children = ['text']
    const before = JSON.parse(JSON.stringify(target.nodes.value))
    expect(target.clipboard.value.kind).toBe('page_content')
    expect(target.paste(null)).toBe(true)
    await Promise.resolve()
    const pasted = target.selected.value
    expect(target.nodes.value.map((node) => node.kind)).toEqual(['section', 'section', 'section'])
    expect(target.nodes.value.slice(1).map((node) => node.children[0].children[0].children[0].props.html)).toEqual(['First', 'Second'])
    expect(pasted).toMatchObject({ kind: 'section', props: sections[0].props, style: sections[0].style, name: 'Hero', responsive: sections[0].responsive, states: sections[0].states })
    expect(pasted.id).not.toBe(sections[0].id)
    expect(pasted.children[0].children[0].children[0].id).not.toBe(sections[0].children[0].children[0].children[0].id)
    expect(target.pageFields()).toEqual([])
    expect(created).toEqual([])
    expect(target.clipboard.value).toEqual(source.clipboard.value)
    expect(target.styleClipboard.value).toEqual({})
    expect(findNode(source.nodes.value, place.id).kind).toBe('page_content')
    const after = JSON.parse(JSON.stringify(target.nodes.value))
    target.undo()
    expect(target.nodes.value).toEqual(before)
    target.redo()
    expect(target.nodes.value).toEqual(after)
  })

  it('pastes entry content into an existing entry area without duplicating its field', async () => {
    const builder = await pageFixture()
    const place = builder.make('page_content', { field: 'design' }, {}, [builder.nodes.value[0]])
    builder.load([place])
    builder.copy(place.id)
    expect(builder.paste(place.id)).toBe(true)
    expect(builder.nodes.value).toHaveLength(1)
    expect(builder.nodes.value[0].children.map((node) => node.kind)).toEqual(['section', 'section'])
    expect(builder.pageFields().map((field) => field.key)).toEqual(['design'])
    builder.undo()
    expect(builder.nodes.value[0].children).toHaveLength(1)
  })

  it('does not change the page for empty areas or branches that would create invalid nested sections', async () => {
    const builder = await pageFixture()
    const section = builder.nodes.value[0]
    section.children[0].children[0].children.push(builder.make('page_content', { field: 'design' }))
    const place = section.children[0].children[0].children[0]
    const before = JSON.parse(JSON.stringify(builder.nodes.value))
    builder.copy(place.id)
    expect(builder.paste(null)).toBe(false)
    expect(builder.nodes.value).toEqual(before)
    place.children.push(builder.make('section', {}, {}, [builder.make('row', {}, {}, [builder.make('column')])]))
    builder.copy(section.id)
    const nested = JSON.parse(JSON.stringify(builder.nodes.value))
    expect(builder.paste(null)).toBe(false)
    expect(builder.nodes.value).toEqual(nested)
    expect(builder.canUndo.value).toBe(false)
  })

  it('wraps an element dropped into page content in a section', async () => {
    const builder = await pageFixture()
    const column = builder.nodes.value[0].children[0].children[0]
    const place = builder.make('page_content')
    expect(builder.insert(place, column.id)).toBe(true)

    const text = builder.make('text')
    builder.drag.value = { source: 'palette', create: () => text }
    expect(builder.dropAt(place.id, 'inside')).toBe(true)
    const section = findNode(builder.nodes.value, place.id).children[0]
    expect(section.kind).toBe('section')
    expect(section.children[0].children[0].children[0].id).toBe(text.id)
  })

  it('renames a page field as the name of its area', async () => {
    const updated = []
    const builder = await pageFixture({
      fieldApi: { can: () => true, create: async () => null, update: async ({ type, key, config }) => { updated.push({ type, key, config }); return null } },
    })
    await builder.renamePageField('design', '  Sidebar  ')
    expect(updated).toEqual([{ type: 'pages', key: 'design', config: { type: 'page', label: 'Sidebar' } }])
    expect(builder.pageFields().find((field) => field.key === 'design').label).toBe('Sidebar')

    // Other languages keep the name of the default language.
    builder.defaultLocale.value = 'en'
    builder.locale.value = 'de'
    await builder.renamePageField('design', 'Seitenleiste')
    expect(builder.type.value.fields.design.label.values).toEqual({ en: 'Sidebar', de: 'Seitenleiste' })
    await builder.renamePageField('intro', 'Not a page field')
    expect(updated).toHaveLength(2)
  })

  it('gives a type without a page field one when the page content element is placed', async () => {
    const created = []
    const builder = await pageFixture({
      fields: {},
      fieldApi: {
        can: () => true,
        create: async ({ type, key, config }) => {
          created.push({ type, key, config })
          return { fields: { ...builder.type.value.fields, [key]: config } }
        },
      },
    })
    const place = builder.make('page_content')
    expect(builder.insert(place, null)).toBe(true)
    await new Promise((resolve) => setTimeout(resolve))
    expect(created).toEqual([{ type: 'pages', key: 'page_content', config: { type: 'page', label: 'site.page.fieldLabel' } }])
    expect(findNode(builder.nodes.value, place.id).props.field).toBe('page_content')
    expect(builder.pageFields().map((field) => field.key)).toEqual(['page_content'])

    const second = builder.make('page_content')
    expect(builder.insert(second, null)).toBe(true)
    await new Promise((resolve) => setTimeout(resolve))
    expect(created[1]).toEqual({ type: 'pages', key: 'page_content_2', config: { type: 'page', label: 'site.page.fieldLabel 2' } })
    expect(findNode(builder.nodes.value, second.id).props.field).toBe('page_content_2')

    // Setting up an area without a field takes an unplaced field before adding one.
    const third = builder.make('page_content', { field: '' })
    builder.load([...builder.nodes.value, third])
    expect(builder.freePageFields(third.id)).toEqual([])
    expect(builder.freePageFields(place.id).map((field) => field.key)).toEqual(['page_content'])
    await builder.setUpPageArea(third.id)
    expect(findNode(builder.nodes.value, third.id).props.field).toBe('page_content_3')
    expect(builder.isDirty.value).toBe(true)

    expect(builder.placedPageFields().map((field) => field.key)).toEqual(['page_content', 'page_content_2', 'page_content_3'])

    // A field whose area was removed is reused by the next one.
    const fourth = builder.make('page_content', { field: '' })
    builder.load([...builder.nodes.value.filter((node) => node.id !== second.id), fourth])
    // The removed area's field stays on the type, but entries can no longer design it.
    expect(builder.placedPageFields().map((field) => field.key)).toEqual(['page_content', 'page_content_3'])
    await builder.setUpPageArea(fourth.id)
    expect(findNode(builder.nodes.value, fourth.id).props.field).toBe('page_content_2')
    expect(created).toHaveLength(3)

    // An area can switch to another free one, or to a new one.
    builder.load(builder.nodes.value.filter((node) => node.id !== third.id))
    await builder.choosePageArea(fourth.id, 'page_content_3')
    expect(findNode(builder.nodes.value, fourth.id).props.field).toBe('page_content_3')
    await builder.choosePageArea(fourth.id, '')
    expect(findNode(builder.nodes.value, fourth.id).props.field).toBe('page_content_4')
    expect(created).toHaveLength(4)
  })
})

describe('builder device and state overrides', () => {
  it('routes edits by the previewed device and state, follows larger screens and resets', async () => {
    const builder = await builderFixture()
    builder.spec.value.style = { padding_top: { default: '' }, background: { default: '' } }
    builder.spec.value.kinds.heading.style = ['padding_top', 'background']
    builder.spec.value.kinds.heading.responsive = { props: [], style: ['padding_top', 'background'] }
    builder.spec.value.kinds.heading.states = { props: [], style: ['background'] }
    const heading = builder.make('heading')
    builder.insert(heading, builder.nodes.value[0].children[0].children[0].id)
    const node = () => findNode(builder.nodes.value, heading.id)

    builder.editValue(heading.id, 'style', 'padding_top', 'lg')
    expect(node().style.padding_top).toBe('lg')
    builder.device.value = 'tablet'
    expect(builder.viewValue(node(), 'style', 'padding_top')).toBe('lg')
    builder.editValue(heading.id, 'style', 'padding_top', 'sm')
    expect(node().style.padding_top).toBe('lg')
    expect(node().responsive).toEqual({ tablet: { style: { padding_top: 'sm' } } })
    builder.device.value = 'mobile'
    // Mobile follows tablet until it has a value of its own.
    expect(builder.viewValue(node(), 'style', 'padding_top')).toBe('sm')
    expect(builder.overridesOf(node(), 'style', 'padding_top')).toEqual({ devices: ['tablet'], states: [] })

    builder.device.value = 'desktop'
    builder.styleState.value = 'hover'
    expect(builder.editableInState(node(), 'style', 'padding_top')).toBe(false)
    expect(builder.viewValue(node(), 'style', 'background')).toBe('')
    builder.editValue(heading.id, 'style', 'background', 'accent')
    expect(node().states).toEqual({ hover: { style: { background: 'accent' } } })
    expect(node().style.background).toBeUndefined()

    builder.resetOverride(heading.id, 'states', 'hover', 'style', 'background')
    expect(node().states).toBeUndefined()
    builder.resetOverride(heading.id, 'responsive', 'tablet', 'style', 'padding_top')
    expect(node().responsive).toBeUndefined()
    builder.undo()
    expect(node().responsive.tablet.style.padding_top).toBe('sm')
  })
})


describe('independent hover animations', () => {
  it('edits, resets and restores hover settings independently of entrance animations', async () => {
    const builder = await builderFixture()
    builder.spec.value.style = { animation: { default: 'none' }, animation_duration: { default: 'normal' }, animation_delay: { default: 0 } }
    builder.spec.value.kinds.heading.style = ['animation', 'animation_duration', 'animation_delay', 'animation_trigger']
    builder.spec.value.kinds.heading.states = { props: [], style: [], variants: { hover: { style: ['animation', 'animation_duration', 'animation_delay'] } } }
    const heading = builder.make('heading', {}, { animation: 'fade-up', animation_trigger: 'load', animation_duration: 'slow', animation_delay: 1 })
    builder.load([heading])
    const node = () => builder.nodes.value[0]
    const entrance = { ...node().style }
    builder.styleState.value = 'hover'
    expect(builder.viewValue(node(), 'style', 'animation')).toBe('')
    expect(builder.editableInState(node(), 'style', 'animation')).toBe(true)
    expect(builder.editableInState(node(), 'style', 'animation_trigger')).toBe(false)
    builder.editValues(heading.id, 'style', { animation: 'zoom-in', animation_duration: 'fast', animation_delay: 2 })
    expect(node().style).toEqual(entrance)
    expect(node().states.hover.style).toEqual({ animation: 'zoom-in', animation_duration: 'fast', animation_delay: 2 })
    builder.undo()
    expect(node().states).toBeUndefined()
    builder.redo()
    expect(node().states.hover.style.animation).toBe('zoom-in')
    builder.resetOverride(heading.id, 'states', 'hover', 'style', 'animation_duration')
    expect(node().states.hover.style.animation_duration).toBeUndefined()
    builder.undo()
    expect(node().states.hover.style.animation_duration).toBe('fast')
    builder.markSaved(JSON.parse(JSON.stringify(builder.nodes.value)))
    expect(builder.isDirty.value).toBe(false)
    expect(node().states.hover.style.animation_delay).toBe(2)
    builder.styleState.value = 'focus'
    expect(builder.editableInState(node(), 'style', 'animation')).toBe(false)
    builder.editValue(heading.id, 'style', 'animation', 'blur')
    builder.editValues(heading.id, 'style', { animation: 'blur' })
    expect(node().style).toEqual(entrance)
    expect(node().states.focus).toBeUndefined()
    builder.styleState.value = 'hover'
    builder.editValue(heading.id, 'style', 'animation', 'none')
    expect(node().states).toBeUndefined()
    expect(node().style).toEqual(entrance)
    builder.undo()
    expect(node().states.hover.style.animation).toBe('zoom-in')
    builder.resetOverride(heading.id, 'states', 'hover', 'style', 'animation')
    expect(node().states).toBeUndefined()
  })

})


describe('modal template', () => {
  async function modalFixture() {
    let builder
    await renderToString(createSSRApp({ setup() { builder = createSiteBuilder({ t: (key) => key }); return () => null } }))
    const inside = ['heading', 'row', 'collection', 'site_content', 'modal_close']
    builder.spec.value = { root: ['modal'], item_elements: [], kinds: {
      modal: { props: { size: { default: 'lg' } }, children: inside }, row: { props: {}, children: ['column'] }, column: { props: {}, children: inside },
      collection: { props: {}, children: ['column'] }, heading: { props: {}, children: [] }, site_content: { props: {}, children: [] }, modal_close: { props: {}, children: [] },
    } }
    builder.rootKind.value = 'modal'
    builder.load([builder.make('modal', {}, {}, [builder.make('modal_close'), builder.make('site_content')])])
    return builder
  }

  it('keeps its one panel and takes new elements into it without a selection', async () => {
    const builder = await modalFixture()
    const panel = builder.nodes.value[0]
    builder.remove(panel.id)
    builder.duplicate(panel.id)
    expect(builder.nodes.value).toHaveLength(1)
    builder.selectedId.value = null
    expect(builder.insert(builder.make('heading'))).toBe(true)
    expect(builder.nodes.value[0].children.map((node) => node.kind)).toEqual(['modal_close', 'site_content', 'heading'])
  })

  it('holds exactly one site content element, never inside a card', async () => {
    const builder = await modalFixture()
    const panel = builder.nodes.value[0]
    const slot = panel.children[1]
    expect(builder.insert(builder.make('site_content'), panel.id)).toBe(false)
    builder.duplicate(slot.id)
    expect(panel.children.filter((node) => node.kind === 'site_content')).toHaveLength(1)
    const card = builder.make('column')
    builder.insert(builder.make('collection', {}, {}, [card]), panel.id)
    builder.remove(slot.id)
    expect(builder.insert(builder.make('site_content'), card.id)).toBe(false)
    builder.undo()
    expect(findNode(builder.nodes.value, slot.id).kind).toBe('site_content')
  })
})

describe('builder search result cards', () => {
  it('offers the fields of every searched type and marks the ones all of them have', async () => {
    const builder = await builderFixture()
    builder.types.value = [
      { name: 'posts', label: 'Posts', fields: { intro: { type: 'text' }, image: { type: 'media' } } },
      { name: 'company', label: 'Company', fields: { intro: { type: 'textarea' }, steps: { type: 'text' } } },
    ]
    builder.settings.value = { routes: { posts: { enabled: true }, company: { enabled: true } } }
    const text = builder.make('text')
    const card = builder.make('column', {}, {}, [text])
    builder.nodes.value = [{ id: 'list', kind: 'collection', props: { list_source: 'search', collections: [] }, style: {}, children: [card] }]

    const context = builder.entryContext(text.id)
    expect(context.type.field_types).toEqual({ intro: ['Posts', 'Company'], image: ['Posts'], steps: ['Company'] })
    const fields = builder.typeFields(text.id).filter((field) => !field.system)
    expect(fields.map((field) => [field.key, field.shared])).toEqual([['intro', true], ['image', false], ['steps', false]])

    builder.nodes.value[0].props.collections = ['company']
    expect(builder.entryContext(text.id).type.name).toBe('company')
    expect(builder.typeFields(text.id).filter((field) => !field.system).every((field) => field.shared)).toBe(true)
  })

  it('treats lists of several types like search cards, without needing website pages', async () => {
    const builder = await builderFixture()
    builder.types.value = [
      { name: 'posts', label: 'Posts', fields: { intro: { type: 'text' } } },
      { name: 'quotes', label: 'Quotes', fields: { intro: { type: 'text' }, author: { type: 'text' } } },
    ]
    builder.settings.value = { routes: { posts: { enabled: true } } }
    const text = builder.make('text')
    const card = builder.make('column', {}, {}, [text])
    builder.nodes.value = [{ id: 'list', kind: 'collection', props: { list_source: 'types', collections: [] }, style: {}, children: [card] }]

    expect(builder.entryContext(text.id).type).toBe(null)
    builder.nodes.value[0].props.collections = ['posts', 'quotes']
    expect(builder.entryContext(text.id).type.field_types).toEqual({ intro: ['Posts', 'Quotes'], author: ['Quotes'] })
    expect(builder.nodeSummary(builder.nodes.value[0])).toBe('Posts, Quotes')
  })
})

describe('builder content areas', () => {
  /** A row component whose column holds a shared text and the content area "Body". */
  const stored = { id: 'card', root: 'row', nodes: [{ id: 'root', kind: 'row', props: {}, style: {}, children: [
    { id: 'col', kind: 'column', props: { span: { mobile: 12, tablet: 0, desktop: 0 } }, style: {}, children: [
      { id: 'shared', kind: 'text', props: { html: 'Shared' }, style: {}, editable: ['html'], children: [] },
      { id: 'body', kind: 'component_area', props: {}, style: {}, name: 'Body', children: [] },
    ] },
  ] }] }
  const summary = { id: 'card', name: 'Card', root: 'row', areas: [{ id: 'body', name: 'Body' }] }

  async function areaFixture() {
    const { builder } = await componentFixture({ load: async () => stored })
    const kinds = builder.spec.value.kinds
    kinds.section.children.push('component')
    kinds.component.children = ['area_content']
    kinds.area_content = { props: { area: { default: '' } }, children: ['text', 'row'] }
    kinds.component_area = { props: {}, children: [] }
    kinds.column.children.push('component_area')
    builder.spec.value.max_row_depth = 3
    builder.components.value = [summary]
    return builder
  }

  it('gives instances content for each area, also after loading older saves, without making them dirty', async () => {
    const builder = await areaFixture()
    const instance = builder.make('component', { component: 'card', root: 'row' })
    builder.load([builder.make('section', {}, {}, [instance])])
    const content = builder.nodes.value[0].children[0].children[0]
    expect(content).toMatchObject({ id: `${instance.id}-body`, kind: 'area_content', props: { area: 'body' }, children: [] })
    expect(builder.isDirty.value).toBe(false)
    expect(builder.nodeLabel(content)).toBe('Body')
    expect(builder.instanceAreas(builder.nodes.value[0].children[0])).toEqual([{ area: summary.areas[0], content, removed: false }])

    // Filling the same tree again gives the same ids.
    builder.load([builder.make('section', {}, {}, [{ ...instance, children: [] }])])
    expect(builder.nodes.value[0].children[0].children[0].id).toBe(content.id)
  })

  it('drops elements into an area but keeps the area with its instance', async () => {
    const builder = await areaFixture()
    builder.load([builder.make('section', {}, {}, [builder.make('component', { component: 'card', root: 'row' })])])
    const instance = builder.nodes.value[0].children[0]
    const content = instance.children[0]
    expect(builder.isDropContainer(content)).toBe(true)
    const text = builder.make('text', { html: 'Own' })
    expect(builder.insert(text, content.id)).toBe(true)
    expect(findNode(builder.nodes.value, content.id).children[0].id).toBe(text.id)

    expect(builder.isLockedRoot(content.id)).toBe(true)
    builder.remove(content.id)
    builder.duplicate(content.id)
    builder.renameNode(content.id, 'Renamed')
    builder.drag.value = { source: 'move', id: content.id }
    expect(builder.dropAt(builder.nodes.value[0].id, 'inside')).toBe(false)
    expect(builder.nodes.value[0].children[0].children).toHaveLength(1)
    expect(builder.nodes.value[0].children[0].children[0].name).toBeUndefined()

    // A component's own areas never go into an instance's area content, even into a column there.
    const row = builder.make('row', {}, {}, [builder.make('column')])
    expect(builder.insert(row, content.id)).toBe(true)
    expect(findNode(builder.nodes.value, content.id).children[1].id).toBe(row.id)
    expect(builder.insert(builder.make('component_area'), row.children[0].id)).toBe(false)
  })

  it('keeps content of removed areas deletable, and detaches with area content in place', async () => {
    const builder = await areaFixture()
    builder.load([builder.make('section', {}, {}, [builder.make('component', { component: 'card', root: 'row' })])])
    const instance = builder.nodes.value[0].children[0]
    const text = builder.make('text', { html: 'Own' })
    builder.insert(text, instance.children[0].id)
    builder.markSaved(builder.nodes.value)

    builder.components.value = [{ ...summary, areas: [] }]
    const content = builder.nodes.value[0].children[0].children[0]
    expect(builder.isRemovedArea(content)).toBe(true)
    expect(builder.nodeSummary(content)).toBe('site.area.removed')
    expect(builder.instanceAreas(builder.nodes.value[0].children[0])).toEqual([{ area: null, content, removed: true }])
    builder.remove(content.id)
    expect(builder.nodes.value[0].children[0].children).toEqual([])
    builder.undo()

    builder.components.value = [summary]
    expect(await builder.detach(instance.id)).toBe(true)
    const copy = builder.nodes.value[0].children[0]
    expect(copy).toMatchObject({ id: instance.id, kind: 'row' })
    expect(copy.children[0].children.map((node) => node.kind)).toEqual(['text', 'text'])
    expect(copy.children[0].children[1].id).toBe(text.id)
    expect(copy.children[0].children[0].id).not.toBe('shared')
    expect(copy.children[0].children[0].editable).toBeUndefined()
  })
})
