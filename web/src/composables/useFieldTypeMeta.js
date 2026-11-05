import { t } from '../i18n/index.js'

const FIELD_TYPE_ICONS = {
  text: 'mdi:format-text',
  slug: 'mdi:link-variant',
  textarea: 'mdi:text-box-outline',
  markdown: 'mdi:language-markdown-outline',
  html: 'mdi:language-html5',
  number: 'mdi:numeric',
  range: 'mdi:tune-variant',
  boolean: 'mdi:toggle-switch-outline',
  date: 'mdi:calendar-outline',
  datetime: 'mdi:calendar-clock-outline',
  json: 'mdi:code-braces',
  media: 'mdi:image-outline',
  relation: 'mdi:arrow-right-circle-outline',
  repeater: 'mdi:view-list-outline',
  select: 'mdi:form-select',
  color: 'mdi:palette-outline',
  page: 'mdi:file-document-edit-outline',
}

const FALLBACK_FIELD_TYPE_ICON = 'mdi:form-textbox'

export function useFieldTypeMeta() {
  function fieldTypeIcon(type) {
    return FIELD_TYPE_ICONS[type] ?? FALLBACK_FIELD_TYPE_ICON
  }

  // Human-readable type name; unknown (e.g. extension) types fall back to their key.
  function fieldTypeLabel(type) {
    const key = `fieldType.${type}`
    const label = t(key)
    return label === key ? String(type ?? '') : label
  }

  return {
    fieldTypeIcon,
    fieldTypeLabel,
  }
}
