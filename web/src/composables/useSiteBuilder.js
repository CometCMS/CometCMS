import { resolveLocalized, setLocalized, removeLocalized, localizedState, resolveLocalizedTree } from './localizedValues.js'
import { hasInvalidValues, isSmartValue, reference, referenceConfig, smartSummary } from './smartValues.js'
import { normalizeKey, uniqueKey } from './fieldBuilderUtils.js'
import { useBuilderClipboard } from './builderClipboard.js'
import { extensionText } from './extensionText.js'
import { useI18n } from '../i18n/index.js'
import { computed, inject, provide, ref, shallowRef } from 'vue'
import {
  AREA_CONTENT,
  COMPONENT,
  COMPONENT_AREA,
  MODAL,
  PAGE_CONTENT,
  SITE_CONTENT,
  allowedChildren,
  ancestors,
  applyColumnPreset,
  clone,
  cloneWithNewIds,
  editableNodes,
  columnLayoutOf,
  contains,
  createNode,
  detachedCopy,
  duplicateNode,
  effectiveSpan,
  fillAllAreas,
  findNode,
  insertNode,
  instanceNode,
  isInsideTemplate,
  isStructure,
  locate,
  placementKind,
  removeNode,
  resolveDrop,
  sectionPreset,
  shiftNode,
  stackColumns,
  templateCollection,
  usesCurrentEntry,
  walk,
  withOverrides,
} from './layoutTree.js'

const KEY = Symbol('site-builder')
const HISTORY_LIMIT = 100
const HOVER_ANIMATION_KEYS = ['animation', 'animation_duration', 'animation_delay']

/** The content of each element that can become a field of its own: the prop and the type of field it becomes. */
const CONTENT_PROPS = {
  heading: { key: 'text', field: 'text' },
  text: { key: 'html', field: 'html' },
  button: { key: 'label', field: 'text' },
  image: { key: 'media', field: 'media' },
}
/** Field keys every content type has or keeps free. */
const RESERVED_FIELDS = new Set(['title', 'slug', 'body', 'url', 'published_at', 'created_at', 'updated_at'])
/** Longest literal a reference can fall back to (see SmartValue.php). */
const MAX_FALLBACK = 2000

/**
 * Editing state of the website builder: the layout tree, selection,
 * undo/redo history, drag and drop and the field context nodes bind to.
 * The view creates it with createSiteBuilder(); child components read it
 * with useSiteBuilder().
 *
 * `componentApi` connects reusable components: create({ name, nodes, type })
 * stores a new component, load(id) returns one with its nodes, rename(id, name)
 * returns its saved summary, and remove(id) deletes it, detaches saved instances
 * and returns its last definition.
 *
 * `templateApi` connects collection cards: load(type) returns the standard
 * card of a content type ({ nodes }), which a collection can take over.
 *
 * `fieldApi` connects content types: can(type) tells whether the user may
 * change a content type, create({ type, key, config }) adds a field to it
 * and update({ type, key, config }) changes one; both return the type's saved schema.
 *
 * `linked` ({ get(), set(value) }) is state outside the tree that shares its
 * undo history, such as the site design; report its edits with linkedChanged().
 */
export function createSiteBuilder({ t, componentApi = {}, templateApi = {}, fieldApi = {}, linked = null, clipboardStorage }) {
  const nodes = ref([])
  const serializedNodes = computed(() => JSON.stringify(nodes.value))
  const spec = ref(null)
  const type = ref(null)
  const types = ref([])
  const menus = ref([])
  /** Submission-enabled content types ({ id, name, type, fields }) available to form elements. */
  const forms = ref([])
  const settings = ref(null)
  /** Types with editable website pages; all types remain available for data bindings. */
  const pageTypes = computed(() => types.value.filter((item) => item.visibility !== 'private'
    && (settings.value?.routes?.[item.name]?.enabled || settings.value?.homepage?.type === item.name)))
  const currentAvailable = ref(true)
  /** Reusable components ({ id, name, root, type, areas }) available in the Add panel. */
  const components = ref([])
  /** Editing a component (its id and root kind) or the modal template: the tree holds just that one root node. */
  const componentId = ref(null)
  const rootKind = ref(null)
  /** Node waiting for a name before it becomes a component: { id }. */
  const componentDialog = ref(null)
  /** Element waiting for a name before its content becomes a field: { id }. */
  const fieldDialog = ref(null)
  /** Why a new page content element could not get a field of its own. */
  const pageFieldError = ref('')
  const locale = ref('')
  const defaultLocale = computed(() => settings.value?.default_locale || 'en')
  // The default language edits the shared design; every other language only translates its texts.
  const translating = computed(() => !!locale.value && locale.value !== defaultLocale.value)
  const hasSyntaxErrors = computed(() => hasInvalidValues(nodes.value))
  const selectedId = ref(null)
  /** Table cell picked on the canvas: { id, row, column }; the inspector edits it. */
  const selectedCell = ref(null)
  const hoverId = ref(null)
  /** One-shot CSS canvas picker: { rootId, className, onPick }. */
  const cssPicker = shallowRef(null)
  /** Measured by the canvas: { id, width, device } of the selected row when it sits in a column (it switches by its own width). */
  const nestedRow = shallowRef(null)
  /** Previewed device; on tablet and mobile, edits of options that allow it only apply there (node.responsive). */
  const device = ref('desktop')
  /** Edited state: normal, or hover/focus values of the options that allow them (node.states). */
  const styleState = ref('normal')
  const drag = shallowRef(null)
  /** Copied node and copied style, for paste; shared by the workspace's builders (see useBuilderClipboard()). */
  const sharedClipboard = useBuilderClipboard({ storage: clipboardStorage })
  const clipboard = sharedClipboard.node
  const styleClipboard = sharedClipboard.style
  /** Open context menu: { id, x, y } in viewport coordinates. */
  const contextMenu = ref(null)
  const savedSnapshot = ref('[]')
  const past = ref([])
  const future = ref([])
  let lastCoalesce = ''
  let lastChange = 0
  /** JSON of the linked state as the history last saw it. */
  let linkedSnapshot = readLinked()

  const selected = computed(() => (selectedId.value ? findNode(nodes.value, selectedId.value) : null))
  const isDirty = computed(() => serializedNodes.value !== savedSnapshot.value)
  const canUndo = computed(() => past.value.length > 0)
  const canRedo = computed(() => future.value.length > 0)

  function load(tree) {
    nodes.value = withAreas(tree)
    savedSnapshot.value = serializedNodes.value
    past.value = []
    future.value = []
    linkedSnapshot = readLinked()
    if (selectedId.value && !findNode(nodes.value, selectedId.value)) selectedId.value = null
  }

  function loadRevision(tree) {
    mutate(() => { nodes.value = withAreas(tree) })
    keepSelection()
  }

  /** A copy of `tree` whose instances have content for each area of their component, also areas added since it was saved. */
  function withAreas(tree) {
    const copy = clone(tree ?? [])
    fillAllAreas(spec.value, copy, components.value)
    return copy
  }

  function markSaved(tree) {
    nodes.value = clone(tree ?? [])
    savedSnapshot.value = serializedNodes.value
    if (selectedId.value && !findNode(nodes.value, selectedId.value)) selectedId.value = null
  }

  /** Apply a change with an undo step; edits with the same `coalesce` key in quick succession share one. */
  function mutate(change, { coalesce = '' } = {}) {
    record(coalesce, linkedSnapshot)
    return change(nodes.value)
  }

  /**
   * Turn an edit of the linked state into an undo step; edits in quick
   * succession share one. With `record: false` the new state just becomes
   * the baseline, e.g. after it was saved or reloaded.
   */
  function linkedChanged({ record: recordStep = true } = {}) {
    const current = readLinked()
    if (current === linkedSnapshot) return
    const previous = linkedSnapshot
    linkedSnapshot = current
    // The first state that arrives is a load, not an edit.
    if (recordStep && previous !== 'null') record('linked', previous)
  }

  /**
   * Make the current linked state the one every undo and redo step keeps,
   * e.g. after a restored version replaced it: undoing a layout edit must not
   * bring back the state it replaced. Steps that only changed it drop out.
   */
  function rebaseLinked() {
    linkedSnapshot = readLinked()
    const keepChanges = (steps, from) => {
      const kept = []
      let next = from
      for (let index = steps.length - 1; index >= 0; index--) {
        if (steps[index].nodes === next) continue
        next = steps[index].nodes
        kept.unshift({ nodes: next, linked: linkedSnapshot })
      }
      return kept
    }
    const current = serializedNodes.value
    past.value = keepChanges(past.value, current)
    future.value = keepChanges(future.value, current)
    lastCoalesce = ''
  }

  function record(coalesce, linkedState) {
    const now = Date.now()
    if (!coalesce || coalesce !== lastCoalesce || now - lastChange > 1000) {
      past.value.push({ nodes: serializedNodes.value, linked: linkedState })
      if (past.value.length > HISTORY_LIMIT) past.value.shift()
      future.value = []
    }
    lastCoalesce = coalesce
    lastChange = now
  }

  function readLinked() {
    return linked ? JSON.stringify(linked.get() ?? null) : 'null'
  }

  function currentStep() {
    return { nodes: serializedNodes.value, linked: linkedSnapshot }
  }

  function restore(step) {
    nodes.value = JSON.parse(step.nodes)
    if (linked && step.linked !== 'null' && step.linked !== linkedSnapshot) {
      // Set the snapshot first so the change this causes is not recorded again.
      linkedSnapshot = step.linked
      linked.set(JSON.parse(step.linked))
    }
    lastCoalesce = ''
    keepSelection()
  }

  function undo() {
    if (!past.value.length) return
    future.value.push(currentStep())
    restore(past.value.pop())
  }

  function redo() {
    if (!future.value.length) return
    past.value.push(currentStep())
    restore(future.value.pop())
  }

  function keepSelection() {
    if (selectedId.value && !findNode(nodes.value, selectedId.value)) selectedId.value = null
  }

  function select(id) {
    if (selectedCell.value?.id !== id) selectedCell.value = null
    selectedId.value = id
  }

  // ─── Field context ─────────────────────────────────────────────────────

  /**
   * The entry that "current" means at `id`: inside a collection's card it is
   * the card's entry (of the collection's type), elsewhere the entry the
   * edited layout shows.
   */
  function entryContext(id) {
    const collection = id ? templateCollection(nodes.value, id) : null
    if (collection) return { type: ['search', 'types'].includes(collection.props?.list_source) ? mixedCardType(collection) : types.value.find((item) => item.name === collection.props?.type) ?? null, currentAvailable: true, collection }
    // A component fits every type, so it offers the fields of all of them.
    if (componentId.value) return { type: componentType.value, currentAvailable: true, collection: null }
    return { type: type.value, currentAvailable: currentAvailable.value, collection: null }
  }

  const componentType = computed(() => combinedType(pageTypes.value.length ? pageTypes.value : types.value.filter((item) => item.visibility !== 'private'), t('site.builder.anyType')) ?? type.value)

  /**
   * What the card of a list of several types can show: one type as it is,
   * several as one type with all their fields. field_types names the types
   * that have each field; an entry of another type leaves that field empty.
   * A search list without chosen types searches every type with pages.
   */
  function mixedCardType(collection) {
    const chosen = collection.props?.collections ?? []
    const search = collection.props?.list_source === 'search'
    const searched = search && !chosen.length ? pageTypes.value : (search ? pageTypes.value : types.value).filter((item) => chosen.includes(item.name))
    return combinedType(searched, t(search ? 'site.builder.searchResultsType' : 'site.builder.mixedType'))
  }

  /**
   * Several types as one: all their fields, with field_types naming the types
   * that have each field and type_labels the types in order. One type stays
   * as it is; none gives null.
   */
  function combinedType(list, label) {
    if (!list.length) return null
    if (list.length === 1) return list[0]
    const fields = {}
    const fieldTypes = {}
    const typeLabels = list.map((item) => resolveLocalized(item.label, locale.value || defaultLocale.value, defaultLocale.value) || item.name)
    list.forEach((item, index) => {
      for (const [key, config] of Object.entries(item.fields ?? {})) {
        fields[key] ??= config
        ;(fieldTypes[key] ??= []).push(typeLabels[index])
      }
    })
    return { name: '', label, fields, field_types: fieldTypes, type_labels: typeLabels, type_count: list.length }
  }

  /** Whether every type a card of several types shows has the field `key` (always, for one type). */
  function sharedField(schema, key) {
    return !schema?.field_types || (schema.field_types[key]?.length ?? schema.type_count) === schema.type_count
  }

  function typeFields(id = null) {
    const schema = entryContext(id).type
    const fields = schema?.fields ?? {}
    const slugField = schema?.slug_field ?? 'slug'
    const list = [
      { key: 'title', label: t('site.systemField.title'), type: 'text', config: { type: 'text' }, system: true },
      { key: 'published_at', label: t('site.systemField.published_at'), type: 'datetime', config: { type: 'datetime' }, system: true },
      { key: 'updated_at', label: t('site.systemField.updated_at'), type: 'datetime', config: { type: 'datetime' }, system: true },
    ]

    for (const [key, config] of Object.entries(fields)) {
      // Page content is placed with its own element, not bound like a field.
      if (key === 'title' || key === 'slug' || key === slugField || !config || config.type === 'page') continue
      list.push({ key, label: resolveLocalized(config.label, locale.value || defaultLocale.value, defaultLocale.value) || key, type: config.type ?? 'text', config, types: schema.field_types?.[key] ?? null, shared: sharedField(schema, key) })
    }

    // Fields every listed type has suit every card, so they come first.
    return schema?.field_types ? list.sort((a, b) => Number(b.system || b.shared) - Number(a.system || a.shared)) : list
  }

  /** Page fields of the edited type: where each entry's own page content is stored. */
  function pageFields() {
    return Object.entries(type.value?.fields ?? {})
      .filter(([, config]) => config?.type === 'page')
      .map(([key, config]) => ({ key, label: resolveLocalized(config.label, locale.value || defaultLocale.value, defaultLocale.value) || key }))
  }

  /** The repeater field node whose item template contains `id`, if any. */
  function templateOwner(id) {
    const chain = ancestors(nodes.value, id)
    for (let index = chain.length - 1; index >= 0; index -= 1) {
      if (chain[index].kind === 'field') return chain[index]
    }
    return null
  }

  /** Subfields of a repeater field node, offered inside its item template. */
  function subfieldsOf(repeaterNode) {
    return (fieldConfig(repeaterNode)?.subfields ?? [])
      .filter((subfield) => subfield?.key)
      .map((subfield) => ({ key: subfield.key, label: resolveLocalized(subfield.label, locale.value || defaultLocale.value, defaultLocale.value) || subfield.key, type: subfield.type ?? 'text', config: subfield }))
  }

  /** Config of the field a field node is bound to (resolving item templates). */
  function fieldConfig(node) {
    if (!node || node.kind !== 'field') return null
    const owner = templateOwner(node.id)
    return referenceConfig(node.props?.source, {
      type: entryContext(node.id).type,
      types: types.value,
      itemFields: owner ? subfieldsOf(owner) : [],
    })
  }

  function isTemplateField(node) {
    return node?.kind === 'field' && !isInsideTemplate(nodes.value, node.id) && fieldConfig(node)?.type === 'repeater'
  }

  const dropOptions = { isTemplateField }

  /** Drop containers: structure, tabs, item templates, content areas, the modal panel and lists without a card yet. */
  function isDropContainer(node) {
    return !!node && (isStructure(node.kind) || ['tabs', 'tab', PAGE_CONTENT, AREA_CONTENT, MODAL].includes(node.kind) || isTemplateField(node) || (node.kind === 'collection' && !node.children?.length)
      || !!spec.value?.kinds?.[node.kind]?.slot)
  }

  /**
   * The single root of a component's or the modal template's tree stays
   * where it is, and so does an instance's content for an area of its component.
   */
  function isLockedRoot(id) {
    if (rootKind.value && nodes.value[0]?.id === id) return true
    const node = id ? findNode(nodes.value, id) : null
    return node?.kind === AREA_CONTENT && !isRemovedArea(node)
  }

  /** The component an instance's area content belongs to. */
  function areaComponent(node) {
    return node?.kind === AREA_CONTENT ? componentOf(locate(nodes.value, node.id)?.parent) : null
  }

  /** The area of the component that area content fills: { id, name }, or null when the component no longer has it. */
  function areaOf(node) {
    return areaComponent(node)?.areas?.find((area) => area.id === node.props?.area) ?? null
  }

  /** Content for an area its component no longer has: kept, not shown, and free to delete. */
  function isRemovedArea(node) {
    return !!areaComponent(node) && !areaOf(node)
  }

  /** What an instance shows in each area of its component, in order, then content of removed areas: [{ area, content, removed }]. */
  function instanceAreas(instance) {
    if (instance?.kind !== COMPONENT) return []
    const contents = (instance.children ?? []).filter((child) => child.kind === AREA_CONTENT)
    const areas = componentOf(instance)?.areas ?? []
    return [
      ...areas.map((area) => ({ area, content: contents.find((child) => child.props?.area === area.id) ?? null, removed: false })),
      ...contents.filter((child) => !areas.some((area) => area.id === child.props?.area)).map((content) => ({ area: null, content, removed: true })),
    ]
  }

  /**
   * Whether a resolved drop may happen: nothing joins a locked root, and
   * area content stays with its instance. Page content, content areas and
   * the modal's site content go neither into page content, area content,
   * cards or item templates nor twice into one tree (page content: per field).
   */
  function placeable(result) {
    if (!result || (rootKind.value && result.parentId === null) || result.node.kind === AREA_CONTENT) return false
    const places = []
    const slots = []
    let areas = false
    walk([result.node], (node) => {
      if (node.kind === PAGE_CONTENT) places.push(node)
      if (node.kind === SITE_CONTENT) slots.push(node)
      if (node.kind === COMPONENT_AREA) areas = true
    })
    if (!places.length && !slots.length && !areas) return true
    const parent = result.parentId ? findNode(nodes.value, result.parentId) : null
    const around = parent ? [...ancestors(nodes.value, parent.id), parent] : []
    if (around.some((node) => [PAGE_CONTENT, AREA_CONTENT, 'collection', 'field'].includes(node.kind))) return false
    if (slots.length > 1 || (slots.length && findKind(SITE_CONTENT, slots[0].id))) return false
    return places.every((place) => !place.props?.field || !findPlace(place.props.field, place.id))
  }

  /** A node of `kind` other than the one with id `except`. */
  function findKind(kind, except) {
    let found = null
    walk(nodes.value, (node) => {
      if (node.kind === kind && node.id !== except) found = node
      return !found
    })
    return found
  }

  /** The page content element showing `field`, other than the one with id `except`. */
  function findPlace(field, except = null) {
    let found = null
    walk(nodes.value, (node) => {
      if (node.kind === PAGE_CONTENT && node.props?.field === field && node.id !== except) found = node
      return !found
    })
    return found
  }

  /**
   * A new page content element shows the first page field without a place.
   * When every page field has one, the type gets another, if the user may change it.
   */
  async function assignPageField(id) {
    const node = findNode(nodes.value, id)
    if (node?.kind !== PAGE_CONTENT || node.props.field) return
    const free = freePageFields(id)[0]
    if (free) {
      node.props.field = free.key
      return
    }
    const key = await createPageField()
    const placed = key && findNode(nodes.value, id)
    if (placed) placed.props.field = key
  }

  /** Page fields that a page content element shows, in canvas order: the areas an entry can design. */
  function placedPageFields() {
    const fields = pageFields()
    const placed = []
    walk(nodes.value, (node) => {
      const field = node.kind === PAGE_CONTENT && fields.find((item) => item.key === node.props?.field)
      if (field && !placed.includes(field)) placed.push(field)
      return true
    })
    return placed
  }

  /** Page fields page content element `id` may show: those no other element shows. */
  function freePageFields(id) {
    return pageFields().filter((field) => !findPlace(field.key, id))
  }

  function canAddPageField() {
    return !!type.value && !!fieldApi.create && (fieldApi.can?.(type.value.name) ?? true)
  }

  /** Give page content element `id` a field of its own: an unplaced one, or a new one. */
  async function setUpPageArea(id) {
    const key = freePageFields(id)[0]?.key ?? await createPageField()
    if (key && findNode(nodes.value, id)) updateValue(id, 'props', 'field', key)
  }

  /** Let page content element `id` show area `key`, or a new area when `key` is empty. */
  async function choosePageArea(id, key) {
    const field = key || await createPageField()
    if (field && findNode(nodes.value, id)) updateValue(id, 'props', 'field', field)
  }

  /** Rename page field `key` in the edited language (an area's name for entries). */
  async function renamePageField(key, label) {
    const config = type.value?.fields?.[key]
    const name = label.trim()
    if (config?.type !== 'page' || !name || !fieldApi.update || !canAddPageField()) return
    const current = config.label
    const localized = (typeof current === 'object' && current !== null) || (!!locale.value && locale.value !== defaultLocale.value)
    const next = { ...config, label: localized ? setLocalized(current, locale.value, name, defaultLocale.value) : name }
    const schema = await fieldApi.update({ type: type.value.name, key, config: next })
    type.value = { ...type.value, fields: schema?.fields ?? { ...type.value.fields, [key]: next } }
    types.value = types.value.map((item) => (item.name === type.value.name ? { ...item, fields: type.value.fields } : item))
  }

  /** A new page field of the edited type; its key, or '' when the user may not add one. */
  async function createPageField() {
    if (!canAddPageField()) return ''
    const existing = pageFields()
    const fields = type.value.fields ?? {}
    const key = uniqueKey('page_content', new Set(Object.keys(fields)), RESERVED_FIELDS)
    // Further areas are numbered, so entries can tell them apart.
    const label = existing.length ? `${t('site.page.fieldLabel')} ${existing.length + 1}` : t('site.page.fieldLabel')
    const schema = await fieldApi.create({ type: type.value.name, key, config: { type: 'page', label } })
    type.value = { ...type.value, fields: schema?.fields ?? { ...fields, [key]: { type: 'page', label } } }
    types.value = types.value.map((item) => (item.name === type.value.name ? { ...item, fields: type.value.fields } : item))
    return key
  }

  // ─── Editing ───────────────────────────────────────────────────────────

  function isDefaultStyle(key, value) {
    const fallback = spec.value?.style?.[key]?.default ?? ''
    return Array.isArray(value) ? value.length === 0 : value === fallback
  }

  function updateValue(id, group, key, value) {
    mutate((tree) => {
      const node = findNode(tree, id)
      if (!node) return
      const def = group === 'style' ? spec.value?.style?.[key] : spec.value?.kinds?.[node.kind]?.props?.[key]
      if (translating.value && !def?.localized) return
      const next = def?.localized ? setLocalized(node[group]?.[key], locale.value || defaultLocale.value, value, defaultLocale.value) : value
      node[group] = { ...(node[group] ?? {}), [key]: next }
      // Like the server, style keeps only values that differ from their default.
      if (group === 'style' && isDefaultStyle(key, value)) {
        delete node.style[key]
      }
    }, { coalesce: `${id}:${group}:${key}` })
  }

  // ─── Device and state overrides ──────────────────────────────────────

  /** Where an edit of the key goes in the current view: { group: 'responsive'|'states', variant } or null for the normal value. */
  function overrideTarget(node, group, key) {
    const kind = spec.value?.kinds?.[node?.kind]
    if (!kind) return null
    if (styleState.value !== 'normal') {
      return (kind.states?.[group]?.includes(key) || kind.states?.variants?.[styleState.value]?.[group]?.includes(key))
        ? { group: 'states', variant: styleState.value } : null
    }
    if (device.value !== 'desktop' && kind.responsive?.[group]?.includes(key)) return { group: 'responsive', variant: device.value }
    return null
  }

  /** Whether the key has a value of its own in the current state (hover/focus edits only show those keys). */
  function editableInState(node, group, key) {
    return styleState.value === 'normal' || !!overrideTarget(node, group, key)
  }

  /** The value the current device or state shows: mobile follows tablet, which follows the normal value. */
  function viewValue(node, group, key) {
    const target = overrideTarget(node, group, key)
    if (target?.group === 'states') return node.states?.[target.variant]?.[group]?.[key] ?? ''
    if (target?.group === 'responsive') {
      const devices = target.variant === 'mobile' ? ['mobile', 'tablet'] : ['tablet']
      for (const name of devices) {
        const value = node.responsive?.[name]?.[group]?.[key]
        if (value !== undefined) return value
      }
    }
    return node[group]?.[key]
  }

  /** Devices and states where the key differs from its normal value, e.g. { devices: ['mobile'], states: [] }. */
  function overridesOf(node, group, key) {
    const has = (map, variant) => map?.[variant]?.[group]?.[key] !== undefined
    return {
      devices: ['tablet', 'mobile'].filter((name) => has(node?.responsive, name)),
      states: ['hover', 'focus'].filter((name) => has(node?.states, name)),
    }
  }

  /** Edit as the inspector does: on tablet/mobile or in a state, overridable keys change only there. */
  function editValue(id, group, key, value) {
    const node = findNode(nodes.value, id)
    const target = node ? overrideTarget(node, group, key) : null
    if (node && !editableInState(node, group, key)) return
    if (!target) return updateValue(id, group, key, value)
    if (translating.value) return
    mutate((tree) => {
      const current = findNode(tree, id)
      if (current) setOverride(current, target.group, target.variant, group, key, value)
    }, { coalesce: `${id}:${target.group}:${target.variant}:${group}:${key}` })
  }

  /** Several edits as one step, e.g. both offsets of a moved element; each goes where editValue() would put it. */
  function editValues(id, group, values) {
    if (translating.value) return
    mutate((tree) => {
      const node = findNode(tree, id)
      if (!node) return
      for (const [key, value] of Object.entries(values)) {
        if (!editableInState(node, group, key)) continue
        const target = overrideTarget(node, group, key)
        if (target) {
          setOverride(node, target.group, target.variant, group, key, value)
          continue
        }
        node[group] = { ...(node[group] ?? {}), [key]: value }
        if (group === 'style' && isDefaultStyle(key, value)) delete node.style[key]
      }
    })
  }

  function resetOverride(id, overrideGroup, variant, group, key) {
    if (translating.value) return
    mutate((tree) => {
      const node = findNode(tree, id)
      if (node) setOverride(node, overrideGroup, variant, group, key, '')
    })
  }

  /** An empty value removes the override, like the server keeps only set values. */
  function setOverride(node, overrideGroup, variant, group, key, value) {
    const overrides = clone(node[overrideGroup] ?? {})
    const values = { ...(overrides[variant]?.[group] ?? {}) }
    if (value === '' || value === null || value === undefined) delete values[key]
    else values[key] = value
    if (overrideGroup === 'states' && variant === 'hover' && group === 'style' && key === 'animation' && (!value || value === 'none')) {
      for (const animationKey of HOVER_ANIMATION_KEYS) delete values[animationKey]
    }
    overrides[variant] = { ...(overrides[variant] ?? {}), [group]: values }
    if (!Object.keys(values).length) delete overrides[variant][group]
    if (!Object.keys(overrides[variant]).length) delete overrides[variant]
    if (Object.keys(overrides).length) node[overrideGroup] = overrides
    else delete node[overrideGroup]
  }

  /** Editor-only name; clearing it restores the automatic label. */
  function resetTranslation(id, group, key) {
    if (locale.value === defaultLocale.value) return
    mutate((tree) => {
      const node = findNode(tree, id)
      if (node) node[group][key] = removeLocalized(node[group][key], locale.value)
    })
  }

  function valueFor(node, group, key) { return resolveLocalized(node[group]?.[key], locale.value || defaultLocale.value, defaultLocale.value) }
  function translationState(node, group, key) { return localizedState(node[group]?.[key], locale.value || defaultLocale.value, defaultLocale.value) }

  function renameNode(id, value) {
    if (translating.value) return false
    const node = findNode(nodes.value, id)
    // Area content is named after its component's area.
    if (!node || node.kind === AREA_CONTENT || typeof value !== 'string') return
    const name = Array.from(value.replace(/[\r\n]/g, ' ')).slice(0, spec.value?.max_node_name_length ?? 120).join('').trim()
    if ((node.name ?? '') === name) return
    mutate(() => {
      if (name) node.name = name
      else delete node.name
    })
  }

  /** Resolve insertion near a target, including the active tab and locked root. */
  function insertion(node, targetId) {
    // Without a selection, a locked root takes the node in.
    let current = targetId ? findNode(nodes.value, targetId) : rootKind.value ? nodes.value[0] ?? null : null
    if (current?.kind === 'tabs' && node.kind !== 'tab' && current.children.length) {
      current = current.children[Math.min(current.children.length, Math.max(1, current.props.initial_tab || 1)) - 1]
    }
    const inside = isDropContainer(current)
    return resolveDrop(spec.value, nodes.value, node, current ? current.id : null, inside ? 'inside' : 'after', dropOptions)
  }

  /** Insert a new node near `targetId` (default: the selection), or at the end. */
  function insert(node, targetId = selectedId.value) {
    if (translating.value) return false
    const result = insertion(node, targetId)
    if (!placeable(result)) return false
    mutate((tree) => insertNode(tree, result.parentId, result.index, result.node))
    selectNew(node, result.node)
    return true
  }

  /** Select a new node, or what was placed of it when a preset section went in as just its row. */
  function selectNew(node, inserted) {
    const target = findNode([inserted], node.id) ? node : inserted
    selectedId.value = target.id
    placed(target)
  }

  /** Give newly placed page content elements their fields (see assignPageField()). */
  function placed(node) {
    walk([node], (item) => {
      if (item.kind === PAGE_CONTENT) assignPageField(item.id).catch((error) => pageFieldError.value = error.message)
    })
  }

  /** Complete a drag: insert a palette node or move an existing one. */
  function dropAt(targetId, position) {
    if (translating.value) return false
    const current = drag.value
    drag.value = null
    if (!current) return false

    if (current.source === 'palette') {
      const node = current.create()
      const result = resolveDrop(spec.value, nodes.value, node, targetId, position, dropOptions)
      if (!placeable(result)) return false
      mutate((tree) => insertNode(tree, result.parentId, result.index, result.node))
      selectNew(node, result.node)
      return true
    }

    const node = findNode(nodes.value, current.id)
    if (!node || isLockedRoot(node.id) || targetId === current.id || (targetId && contains(node, targetId))) return false

    const before = serializedNodes.value
    let moved = false
    mutate((tree) => {
      removeNode(tree, current.id)
      const result = resolveDrop(spec.value, tree, node, targetId, position, dropOptions)
      if (placeable(result)) {
        insertNode(tree, result.parentId, result.index, result.node)
        moved = true
      }
    })

    if (!moved) {
      nodes.value = JSON.parse(before)
      past.value.pop()
    }

    selectedId.value = current.id
    return moved
  }

  function remove(id = selectedId.value) {
    if (translating.value) return false
    if (!id || isLockedRoot(id)) return
    const where = locate(nodes.value, id)
    mutate((tree) => removeNode(tree, id))
    if (selectedId.value === id || !findNode(nodes.value, selectedId.value)) {
      const sibling = where?.list[Math.min(where.index, where.list.length - 1)]
      selectedId.value = sibling?.id ?? where?.parent?.id ?? null
    }
  }

  function duplicate(id = selectedId.value) {
    if (translating.value) return false
    if (!id || isLockedRoot(id) || isCard(findNode(nodes.value, id)) || holdsPageContent(findNode(nodes.value, id)) || findNode(nodes.value, id)?.kind === AREA_CONTENT) return
    let copy = null
    mutate((tree) => {
      copy = duplicateNode(tree, id)
    })
    if (copy) selectedId.value = copy.id
  }

  function shift(id, delta) {
    if (translating.value || isLockedRoot(id)) return false
    mutate((tree) => shiftNode(tree, id, delta))
  }

  function selectParent() {
    const where = selectedId.value ? locate(nodes.value, selectedId.value) : null
    if (where?.parent) selectedId.value = where.parent.id
  }

  /** The column row a section, row or column belongs to (a section's first row). */
  function columnRowFor(node) {
    if (!node) return null
    if (node.kind === 'row') return node
    if (node.kind === 'section') return node.children.find((child) => child.kind === 'row') ?? null
    if (node.kind === 'column' || (node.kind === COMPONENT && node.props?.root === 'column')) {
      const parent = locate(nodes.value, node.id)?.parent
      return parent?.kind === 'row' ? parent : null
    }
    return null
  }

  /** Rearrange the columns around `id` into `spans`, or stack them with 'stacked'. */
  function setColumns(id, layout) {
    if (translating.value) return false
    mutate((tree) => {
      const node = findNode(tree, id)
      if (!node) return
      let row = columnRowFor(node)
      if (!row && node.kind === 'section') {
        row = createNode(spec.value, 'row')
        node.children.push(row)
      }
      if (!row) return
      if (layout === 'stacked') stackColumns(row)
      else applyColumnPreset(spec.value, row, layout)
    })
    keepSelection()
  }

  /** Add one more column to the set around `id`, keeping the columns equal. */
  function addColumn(id) {
    const row = columnRowFor(findNode(nodes.value, id))
    const count = (row?.children.length ?? 0) + 1
    setColumns(id, Array.from({ length: count }, () => Math.max(1, Math.floor(12 / count))))
  }

  /** Insert an empty one-column section after the section that contains `id`. */
  function addSectionAfter(id) {
    if (translating.value) return false
    if (rootKind.value) return
    const section = id ? [...ancestors(nodes.value, id), findNode(nodes.value, id)].find((node) => node?.kind === 'section') : null
    const node = sectionPreset(spec.value, [12])
    const index = section ? locate(nodes.value, section.id).index + 1 : nodes.value.length
    mutate((tree) => insertNode(tree, null, index, node))
    selectedId.value = node.id
  }

  function copy(id = selectedId.value) {
    const node = id ? findNode(nodes.value, id) : null
    if (node) sharedClipboard.copy(clone(node))
  }

  /** Paste the copied node after `id` (or inside it when it is a container). */
  function paste(id = selectedId.value) {
    if (translating.value) return false
    sharedClipboard.refresh()
    if (!clipboard.value) return false
    const copy = cloneWithNewIds(clipboard.value)
    if (walk([copy], (item) => item.kind !== PAGE_CONTENT)) return insert(copy, id)
    // Copy the design, not the entry's field association. Also handles saved clipboard copies.
    const unwrap = (items) => items.flatMap((item) => {
      item.children = unwrap(item.children ?? [])
      return item.kind === PAGE_CONTENT ? item.children : [item]
    })
    const copies = unwrap([copy])
    if (!copies.length) return false
    // Unwrapping must not leave sections inside columns (which a save would discard).
    if (copies.some((node) => !walk([node], (item) => item.children.every((child) =>
      allowedChildren(spec.value, item.kind).includes(placementKind(child)))))) return false

    const first = insertion(copies[0], id)
    if (!placeable(first)) return false
    const parent = first.parentId ? findNode(nodes.value, first.parentId) : null
    const results = [first]
    for (const node of copies.slice(1)) {
      const result = resolveDrop(spec.value, nodes.value, node, parent?.id ?? null, 'inside', dropOptions)
      if (!result || result.parentId !== first.parentId || !placeable(result)) return false
      results.push(result)
    }
    mutate((tree) => {
      for (const [offset, result] of results.entries()) insertNode(tree, first.parentId, first.index + offset, result.node)
    })
    selectedId.value = copies[0].id
    return true
  }

  /** Apply the copied style, keeping only what the target kind supports. */
  function pasteStyle(id = selectedId.value) {
    if (translating.value) return false
    sharedClipboard.refresh()
    if (!styleClipboard.value || !id) return
    mutate((tree) => {
      const node = findNode(tree, id)
      if (!node) return
      const allowed = spec.value?.kinds?.[node.kind]?.style ?? []
      node.style = Object.fromEntries(Object.entries(styleClipboard.value).filter(([key]) => allowed.includes(key)))
    })
  }

  function openContextMenu(id, x, y) {
    sharedClipboard.refresh()
    if (id) selectedId.value = id
    contextMenu.value = { id: id ?? selectedId.value, x, y }
  }

  function closeContextMenu() {
    contextMenu.value = null
  }

  function make(kind, props = {}, style = {}, children = []) {
    if (kind === 'divider') {
      style = { padding_top: 'md', padding_bottom: 'md', text_color: 'muted', ...style }
    }
    if (kind === 'tabs' && children.length === 0) {
      children = [1, 2].map((index) => make('tab', { title: t('site.tabs.tabTitle', { number: index }) }))
    }
    const node = createNode(spec.value, kind, props, style, children)
    if (kind === 'row' && children.length === 0) {
      applyColumnPreset(spec.value, node, [12])
    }
    return node
  }

  // ─── Components ────────────────────────────────────────────────────────

  function componentOf(node) {
    return node?.kind === COMPONENT ? components.value.find((item) => item.id === node.props?.component) ?? null : null
  }

  function canInsertComponent(component) {
    // Components fit every type; a field the shown entry lacks just stays empty.
    return component.id !== componentId.value
  }

  async function renameComponent(id, name) {
    if (translating.value) return false
    const updated = await componentApi.rename(id, name)
    components.value = components.value.map((item) => (item.id === id ? { ...item, name: updated.name } : item))
    return updated
  }

  /** Detach deleted instances in the draft, clipboard and every undo/redo state. */
  async function deleteComponent(id) {
    if (translating.value) return false
    const component = await componentApi.remove(id)
    // Reuse ids across snapshots so deleting alone does not make a saved layout dirty.
    const copies = new Map()
    function detachInstances(tree) {
      return tree.map((node) => {
        if (node.kind === COMPONENT && node.props?.component === id) {
          const key = JSON.stringify(node)
          if (!copies.has(key)) copies.set(key, detachedCopy(node, component.nodes[0]))
          // What the instance put into its areas may hold more instances of the component.
          const copy = clone(copies.get(key))
          return { ...copy, children: detachInstances(copy.children ?? []) }
        }
        return { ...node, children: detachInstances(node.children ?? []) }
      })
    }
    const detachSnapshot = (snapshot) => JSON.stringify(detachInstances(JSON.parse(snapshot)))
    nodes.value = detachInstances(nodes.value)
    savedSnapshot.value = detachSnapshot(savedSnapshot.value)
    past.value = past.value.map((step) => ({ ...step, nodes: detachSnapshot(step.nodes) }))
    future.value = future.value.map((step) => ({ ...step, nodes: detachSnapshot(step.nodes) }))
    if (clipboard.value) clipboard.value = detachInstances([clipboard.value])[0]
    components.value = components.value.filter((item) => item.id !== id)
    drag.value = null
    keepSelection()
    return component
  }

  /** Content slots and a component's content areas stay in their own document rather than becoming reusable components. */
  function canConvert(node) {
    let area = false
    if (node) walk([node], (item) => !(area = item.kind === COMPONENT_AREA))
    return !translating.value && !!node && isStructure(node.kind) && !isLockedRoot(node.id) && !holdsPageContent(node) && !area && !!componentApi.create
  }

  /** Whether `node` is or contains a page content place or the modal's site content slot. */
  function holdsPageContent(node) {
    let found = false
    if (node) walk([node], (item) => !(found = [PAGE_CONTENT, SITE_CONTENT].includes(item.kind)))
    return found
  }

  /** Swap the node `id` for an instance of `component`, as one undo step. */
  function replaceWithInstance(id, component) {
    if (translating.value) return false
    let instance = null
    mutate((tree) => {
      const where = locate(tree, id)
      if (!where) return
      const original = where.list[where.index]
      const props = component.root === 'column' ? { span: clone(original.props?.span ?? {}) } : {}
      instance = instanceNode(spec.value, component, props)
      if (original.name) instance.name = original.name
      where.list.splice(where.index, 1, instance)
    })
    if (instance) selectedId.value = instance.id
    return instance
  }

  /**
   * Store the node `id` (with everything in it) as a new component and put an
   * instance in its place. Components showing the page entry's fields belong
   * to the type being edited.
   */
  async function convertToComponent(id, name) {
    const node = findNode(nodes.value, id)
    if (!canConvert(node)) return null
    const source = clone(node)
    const component = await componentApi.create({ name, nodes: [source], type: usesCurrentEntry([source]) ? entryContext(id).type?.name ?? '' : '' })
    const { nodes: _tree, ...summary } = component
    components.value = [...components.value.filter((item) => item.id !== component.id), summary]
      .sort((a, b) => a.name.localeCompare(b.name))
    replaceWithInstance(id, component)
    return component
  }

  // ─── Content ───────────────────────────────────────────────────────────

  /** The literal content of an element, which can become a field. */
  function literalContent(node) {
    const content = CONTENT_PROPS[node?.kind]
    const value = content ? valueFor(node, 'props', content.key) : null
    if (!content || isSmartValue(value) || typeof (value ?? '') !== 'string') return null
    return { ...content, value: value ?? '' }
  }

  /** Whether the element's content can move into a new field of the entry it shows. */
  function canMakeField(node) {
    return !translating.value && !!literalContent(node) && !!type.value && currentAvailable.value && !!fieldApi.create
      && (fieldApi.can?.(type.value.name) ?? true) && !isInsideTemplate(nodes.value, node.id) && !entryContext(node.id).collection
  }

  /**
   * Add a field named `label` to the content type and bind the element to it.
   * Entries without a value keep showing the current content, and new
   * entries start with it.
   */
  async function convertToField(id, label) {
    if (translating.value) return false
    const node = findNode(nodes.value, id)
    if (!canMakeField(node)) return null
    const { key: prop, field, value } = literalContent(node)
    const fields = type.value.fields ?? {}
    const key = uniqueKey(normalizeKey(label) || node.kind, new Set(Object.keys(fields)), RESERVED_FIELDS)
    const config = { type: field, label, required: false }
    if (field !== 'media' && value !== '') config.default = value
    // Text follows the type's languages; an image is usually the same in all of them.
    if (field === 'media') config.localized = false
    const schema = await fieldApi.create({ type: type.value.name, key, config })
    type.value = { ...type.value, fields: schema?.fields ?? { ...fields, [key]: config } }
    types.value = types.value.map((item) => (item.name === type.value.name ? { ...item, fields: type.value.fields } : item))

    const binding = reference(key)
    if (value !== '' && value.length <= MAX_FALLBACK) binding.filters.push({ name: 'fallback', value })
    updateValue(id, 'props', prop, binding)
    return key
  }

  // ─── Collection cards ──────────────────────────────────────────────────

  /** Give the collection `id` a card of its own, starting from its type's standard card. */
  async function customizeCard(id) {
    if (translating.value) return false
    const node = findNode(nodes.value, id)
    if (node?.kind !== 'collection' || node.children?.length) return null
    const mixed = ['search', 'types'].includes(node.props?.list_source)
    // A list of one type starts from the card its entries showed until now.
    const collections = node.props?.collections ?? []
    const single = mixed ? (collections.length === 1 ? collections[0] : '') : node.props?.type
    if (!mixed && (!single || !templateApi.load)) return null
    const standard = single && templateApi.load ? (await templateApi.load(single))?.nodes?.[0] : null
    // Entries of several types start from fields they all have.
    const card = standard ?? (mixed ? spec.value?.search_card?.[0] : null)
    if (!card) return null
    const copy = cloneWithNewIds(card)
    let placed = false
    mutate((tree) => {
      const target = findNode(tree, id)
      if (target?.kind !== 'collection' || target.children?.length) return
      target.children = [copy]
      placed = true
    })
    if (!placed) return null
    selectedId.value = copy.id
    return copy
  }

  /** Go back to the type's standard card. */
  function resetCard(id) {
    if (translating.value) return false
    mutate((tree) => {
      const node = findNode(tree, id)
      if (node?.kind === 'collection') node.children = []
    })
    selectedId.value = id
    keepSelection()
  }

  function isCard(node) {
    return !!node && locate(nodes.value, node.id)?.parent?.kind === 'collection'
  }

  // ─── Editable props ──────────────────────────────────────────────────────

  /** Saved trees of the components whose instances were selected: id => root node. */
  const componentTrees = ref({})

  async function loadComponentTree(id) {
    if (!id || componentTrees.value[id] !== undefined || !componentApi.load) return
    componentTrees.value = { ...componentTrees.value, [id]: null }
    const component = await componentApi.load(id)
    componentTrees.value = { ...componentTrees.value, [id]: component?.nodes?.[0] ?? null }
  }

  /** Whether a prop of the edited component's node may be set by each placement. */
  function isEditable(node, key) {
    return !!node?.editable?.includes(key)
  }

  /** Let each placement set the prop, or fix it again (editing a component only). */
  function toggleEditable(id, key) {
    if (translating.value || !componentId.value) return
    mutate((tree) => {
      const node = findNode(tree, id)
      if (!node || node.kind === COMPONENT) return
      const editable = node.editable?.includes(key) ? node.editable.filter((item) => item !== key) : [...(node.editable ?? []), key]
      if (editable.length) node.editable = editable
      else delete node.editable
    })
  }

  /** The component's nodes an instance may set props of, as the instance shows them: [{ node, keys }]. */
  function instanceFields(instance) {
    const root = componentTrees.value[instance?.props?.component]
    if (!root) return []
    return editableNodes([withOverrides(root, instance.overrides)])
      .map((node) => ({ node, keys: node.editable.filter((key) => spec.value?.kinds?.[node.kind]?.props?.[key]) }))
      .filter((item) => item.keys.length)
  }

  function hasInstanceValue(instance, nodeId, key) {
    return instance?.overrides?.[nodeId]?.[key] !== undefined
  }

  /** Set the instance's own value; a translation starts from the component's texts. */
  function setInstanceValue(instanceId, nodeId, key, value) {
    mutate((tree) => {
      const instance = findNode(tree, instanceId)
      const root = componentTrees.value[instance?.props?.component]
      const inner = root ? findNode([root], nodeId) : null
      if (instance?.kind !== COMPONENT || !inner?.editable?.includes(key)) return
      const def = spec.value?.kinds?.[inner.kind]?.props?.[key]
      if (translating.value && !def?.localized) return
      const overrides = clone(instance.overrides ?? {})
      const current = overrides[nodeId]?.[key] ?? inner.props?.[key]
      const next = def?.localized ? setLocalized(clone(current), locale.value || defaultLocale.value, value, defaultLocale.value) : value
      overrides[nodeId] = { ...(overrides[nodeId] ?? {}), [key]: next }
      instance.overrides = overrides
    }, { coalesce: `${instanceId}:${nodeId}:${key}` })
  }

  /** Go back to the component's value: in a translation only that language, otherwise in every language. */
  function resetInstanceValue(instanceId, nodeId, key, { translation = false } = {}) {
    mutate((tree) => {
      const instance = findNode(tree, instanceId)
      if (!hasInstanceValue(instance, nodeId, key)) return
      const overrides = clone(instance.overrides)
      if (translation) overrides[nodeId][key] = removeLocalized(overrides[nodeId][key], locale.value)
      else delete overrides[nodeId][key]
      if (!Object.keys(overrides[nodeId]).length) delete overrides[nodeId]
      if (Object.keys(overrides).length) instance.overrides = overrides
      else delete instance.overrides
    })
  }

  /** Open a component in its own builder (the view decides how). */
  function editComponent(id) {
    componentApi.edit?.(id)
  }

  /** Replace an instance with an editable copy of its component, with what it shows in each content area in place. */
  async function detach(id) {
    if (translating.value) return false
    const node = findNode(nodes.value, id)
    if (node?.kind !== COMPONENT || !componentApi.load) return false
    const component = await componentApi.load(node.props.component)
    const root = component?.nodes?.[0]
    if (!root) return false
    const copy = detachedCopy(node, root)
    let replaced = false
    mutate((tree) => {
      const where = locate(tree, id)
      if (!where) return
      where.list.splice(where.index, 1, copy)
      replaced = true
    })
    if (replaced) selectedId.value = copy.id
    return replaced
  }

  // ─── Labels ────────────────────────────────────────────────────────────

  const adminLocale = useI18n().locale

  function kindLabel(kind) {
    const own = spec.value?.kinds?.[kind]
    if (own?.extension) return extensionText({ title: own.label, l10n: own.l10n }, 'title', adminLocale.value)
    return own?.label ?? (kind.startsWith('block:') ? kind.slice(6) : t(`site.kind.${kind}`))
  }

  /**
   * Whether a column set (or a column inside one) is arranged underneath
   * each other on desktops — then it reads as rows rather than columns.
   */
  function isStacked(node) {
    if (!node) return false
    if (node.kind === 'row') return columnLayoutOf(node).stacked
    if (node.kind === 'column') {
      const parent = locate(nodes.value, node.id)?.parent
      return parent?.kind === 'row' && columnLayoutOf(parent).stacked
    }
    return false
  }

  /** Name of a node as the user sees it: column sets follow their arrangement. */
  function nodeLabel(node) {
    if (!node) return ''
    if (node.name) return node.name
    if (node.kind === 'tab') return valueFor(node, 'props', 'title') || kindLabel('tab')
    if (node.kind === COMPONENT) return componentOf(node)?.name ?? t('site.component.missing')
    if (node.kind === AREA_CONTENT) return areaOf(node)?.name || kindLabel(COMPONENT_AREA)
    if (isCard(node)) return t('site.kind.card')
    if (isStacked(node)) return t(`site.kind.${node.kind}Stacked`)
    return kindLabel(node.kind)
  }

  function nodeSummary(node) {
    const props = resolveLocalizedTree(node.props ?? {}, locale.value || defaultLocale.value, defaultLocale.value)
    switch (node.kind) {
      case 'field': {
        return smartSummary(props.source) || t('site.builder.noField')
      }
      case 'heading':
        return smartSummary(props.text)
      case 'button':
        return smartSummary(props.label)
      case 'accordion':
        return props.items ? t('site.accordion.count', { count: props.items.length }) : smartSummary(props.title)
      case 'tabs':
        return t('site.tabs.count', { count: node.children?.length ?? 0 })
      case 'table': {
        const rows = Array.isArray(props.rows) ? props.rows : []
        return t('site.table.size', { rows: rows.length, columns: rows[0]?.length ?? 0 })
      }
      case 'icon':
        return props.icon
      case 'collection':
        if (props.list_source === 'search') return t('site.option.list_source.search')
        if (props.list_source === 'types') return (props.collections ?? []).map((name) => types.value.find((item) => item.name === name)?.label ?? name).join(', ') || t('site.builder.noType')
        return types.value.find((item) => item.name === props.type)?.label ?? t('site.builder.noType')
      case 'menu':
        return menus.value.find((item) => item.name === props.menu)?.label ?? props.menu
      case 'form': {
        const form = forms.value.find((item) => item.id === props.form)
        return form ? (types.value.find((item) => item.name === form.type)?.label ?? form.type) : t('site.forms.none')
      }
      case 'search': {
        const names = (props.collections ?? []).map((name) => types.value.find((item) => item.name === name)?.label ?? name)
        return names.length ? names.join(', ') : t('site.search.allTypes')
      }
      case 'video':
        return smartSummary(props.media) || props.video_url || t('site.placeholder.chooseVideo')
      case 'image':
        return smartSummary(props.media) || t('site.builder.noImage')
      case 'gallery':
        return Array.isArray(props.media) ? t('site.builder.galleryCount', { count: props.media.length }) : smartSummary(props.media)
      case 'text':
        return smartSummary(props.html)
      case 'column': {
        const span = effectiveSpan(props.span, 'desktop')
        return span === 12 ? t('site.prop.full_width') : `${span}/12`
      }
      case 'row': {
        const layout = columnLayoutOf(node)
        return layout.stacked ? t('site.builder.stacked') : layout.spans.join(' + ')
      }
      case 'section':
        return t(`site.option.${props.width ?? 'contained'}`)
      case COMPONENT:
        return t('site.component.instanceOf', { kind: kindLabel(props.root) })
      case PAGE_CONTENT:
        return pageFields().find((field) => field.key === props.field)?.label ?? t('site.page.noField')
      case COMPONENT_AREA:
        return t('site.area.filledByPlacement')
      case AREA_CONTENT:
        return isRemovedArea(node) ? t('site.area.removed') : t('site.area.filledHere')
      default:
        return ''
    }
  }

  const api = {
    nodes, spec, type, types, pageTypes, sharedField, combinedType, menus, forms, settings, selectedId, selectedCell, selected, hoverId, cssPicker, nestedRow, drag,
    isDirty, canUndo, canRedo, savedSnapshot,
    load, loadRevision, markSaved, mutate, linkedChanged, rebaseLinked, undo, redo, select, selectParent,
    currentAvailable, locale, translating, defaultLocale, valueFor, translationState, resetTranslation, hasSyntaxErrors,
    subfieldsOf, templateOwner, fieldConfig, isTemplateField, isDropContainer, typeFields, entryContext,
    customizeCard, resetCard, isCard,
    updateValue, renameNode, insert,
    device, styleState, overrideTarget, editableInState, viewValue, overridesOf, editValue, editValues, resetOverride, dropAt, remove, duplicate, shift, make, columnRowFor, setColumns,
    kindLabel, nodeLabel, isStacked, nodeSummary,
    clipboard, styleClipboard, contextMenu, copy, paste, pasteStyle,
    addColumn, addSectionAfter, openContextMenu, closeContextMenu,
    components, componentId, rootKind, componentDialog, isLockedRoot, componentOf, areaOf, isRemovedArea, instanceAreas, canConvert, canInsertComponent, deleteComponent, renameComponent,
    /** Whether components can be opened in their own builder (editing them is layout work). */
    canEditComponents: !!componentApi.edit,
    replaceWithInstance, convertToComponent, detach, editComponent,
    componentTrees, loadComponentTree, isEditable, toggleEditable, instanceFields, hasInstanceValue, setInstanceValue, resetInstanceValue,
    fieldDialog, canMakeField, convertToField,
    pageFields, placedPageFields, freePageFields, canAddPageField, setUpPageArea, choosePageArea, renamePageField, pageFieldError, holdsPageContent,
  }

  provide(KEY, api)
  return api
}

export function useSiteBuilder() {
  return inject(KEY)
}

/** The builder when there is one, e.g. for panels the design page shares. */
export function useOptionalSiteBuilder() {
  return inject(KEY, null)
}
