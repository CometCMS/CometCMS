/** Icons for layout node kinds in the builder palette and outline. */
export const KIND_ICONS = {
  section: 'mdi:view-agenda-outline',
  row: 'mdi:view-column-outline',
  column: 'mdi:view-split-vertical',
  field: 'mdi:form-textbox',
  heading: 'mdi:format-header-pound',
  text: 'mdi:text',
  html: 'mdi:code-tags',
  video: 'mdi:play-box-outline',
  image: 'mdi:image-outline',
  gallery: 'mdi:image-multiple-outline',
  button: 'mdi:gesture-tap-button',
  accordion: 'mdi:chevron-down',
  table: 'mdi:table',
  tabs: 'mdi:tab',
  tab: 'mdi:tab',
  icon: 'mdi:star-outline',
  spacer: 'mdi:arrow-expand-vertical',
  divider: 'mdi:minus',
  collection: 'mdi:view-grid-outline',
  form: 'mdi:form-select',
  menu: 'mdi:menu',
  search: 'mdi:magnify',
  site_title: 'mdi:home-variant-outline',
  language_switcher: 'mdi:translate',
  theme_toggle: 'mdi:theme-light-dark',
  component: 'mdi:puzzle-outline',
  // Content areas share one icon, whoever fills them.
  page_content: 'mdi:tray-arrow-down',
  component_area: 'mdi:tray-arrow-down',
  area_content: 'mdi:tray-arrow-down',
  modal: 'mdi:dock-window',
  site_content: 'mdi:application-import',
  modal_title: 'mdi:format-title',
  modal_close: 'mdi:close-box-outline',
}

/** Column sets arranged underneath each other read as rows. */
export const STACKED_ICONS = {
  row: 'mdi:view-agenda-outline',
  column: 'mdi:view-split-horizontal',
}

export function componentIcon(component) {
  return component?.icon || KIND_ICONS.component
}

/** A kind's icon; block packages bring their own, as an Iconify name or an image. */
export function kindIcon(kind, spec = null) {
  return KIND_ICONS[kind] || spec?.kinds?.[kind]?.icon || (kind?.startsWith('block:') ? 'mdi:cube-outline' : 'mdi:shape-outline')
}

export function nodeIcon(node, stacked = false, component = null, spec = null) {
  if (node?.kind === 'component') return componentIcon(component)
  return (stacked && STACKED_ICONS[node?.kind]) || kindIcon(node?.kind, spec)
}
