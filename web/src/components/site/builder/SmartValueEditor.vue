<template>
  <div class="relative space-y-2">
    <div class="flex items-center justify-end gap-1">
      <span class="mr-auto inline-flex items-center gap-1.5 text-xs text-slate-500">{{ label }}<slot name="label-status" /></span>
      <button ref="pickerTrigger" type="button" class="rounded p-1 text-slate-400 hover:bg-theme-50 hover:text-theme-700 focus-visible:ring-2 focus-visible:ring-theme-500" :title="t('site.binding.insert')" :aria-label="t('site.binding.insert')" :aria-expanded="pickerOpen" @click="openPicker()"><Icon icon="mdi:database-arrow-right-outline" class="h-4 w-4" /></button>
      <button type="button" class="rounded px-1.5 py-1 text-[10px] font-semibold text-slate-500 hover:bg-slate-100" :aria-pressed="syntaxMode" @click="toggleSyntax">{{ syntaxMode ? t('site.binding.visual') : t('site.binding.syntax') }}</button>
      <button v-if="isSmartValue(content)" type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100" :title="t('site.binding.clear')" :aria-label="t('site.binding.clear')" @click="clear"><Icon icon="mdi:link-off" class="h-4 w-4" /></button>
    </div>
    <template v-if="syntaxMode">
      <label class="sr-only" :for="syntaxId">{{ t('site.binding.syntax') }}</label>
      <textarea :id="syntaxId" :value="raw" rows="4" class="form-textarea w-full rounded-lg border-slate-300 font-mono text-xs" :aria-invalid="!!syntaxError" @input="updateSyntax($event.target.value)"></textarea>
    </template>
    <template v-else-if="textual">
      <RichTextToolbar v-if="target === 'html'" ref="toolbar" :editor="editor" />
      <EditorContent :editor="editor" class="smart-editor rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-xs" />
    </template>
    <button v-else-if="isReference(content)" type="button" class="flex w-full items-center gap-2 rounded-lg border border-theme-200 bg-theme-50 px-2.5 py-2 text-left text-xs text-theme-800" @click="openPicker(content)"><Icon icon="mdi:database-outline" class="h-4 w-4 shrink-0" /><span class="break-words">{{ smartSummary(content) }}</span></button>
    <slot v-else name="literal"><button type="button" class="w-full rounded-lg border border-dashed border-slate-300 px-2 py-3 text-xs text-slate-500 hover:bg-slate-50" @click="openPicker()">{{ t('site.binding.choose') }}</button></slot>
    <p v-if="syntaxError" role="alert" class="text-xs text-red-600">{{ t('site.binding.invalidSyntax') }}</p>
    <p v-else-if="incompatible" role="status" class="text-xs text-amber-700">{{ t('site.binding.unavailable') }}</p>
    <SmartReferencePicker v-if="pickerOpen" :key="pickerKey" class="relative z-40" :node="node" :target="target" :initial="editing" @select="insert" @close="closePicker" />
    <label v-if="target === 'link' && modelValue?.type === 'dynamic'" class="flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" class="rounded border-slate-300 text-theme-600" :checked="modelValue.new_tab" @change="$emit('update:modelValue', { ...modelValue, new_tab: $event.target.checked })" />{{ t('site.link.newTab') }}</label>
  </div>
</template>

<script setup>
import { computed, ref, useId, watch } from 'vue'
import { EditorContent, Node, mergeAttributes, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { Icon } from '@iconify/vue'
import SmartReferencePicker from './SmartReferencePicker.vue'
import RichTextToolbar from '../../RichTextToolbar.vue'
import { richTextExtensions } from '../../../composables/richTextEditor.js'
import { useSiteBuilder } from '../../../composables/useSiteBuilder.js'
import { useI18n } from '../../../i18n/index.js'
import { compatibleField, isReference, isSmartValue, parseReference, parseSmartValue, referenceSyntax, referenceConfig, serializeSmartValue, smartSummary } from '../../../composables/smartValues.js'
const props = defineProps({ modelValue: { default: '' }, node: { type: Object, required: true }, target: { type: String, required: true }, label: { type: String, default: '' } })
const emit = defineEmits(['update:modelValue'])
const builder = useSiteBuilder()
const { t } = useI18n()
const content = computed(() => props.target === 'link' && props.modelValue?.type === 'dynamic' ? props.modelValue.value : props.modelValue)
const textual = computed(() => ['text', 'html'].includes(props.target))
const syntaxMode = ref(content.value?.type === 'invalid')
const syntaxError = ref(content.value?.type === 'invalid')
const syntaxText = (value) => isSmartValue(value) || textual.value ? serializeSmartValue(value) : '';
const raw = ref(syntaxText(content.value))
const syntaxId = `binding-syntax-${useId()}`
const pickerOpen = ref(false)
const pickerTrigger = ref(null)
const pickerKey = ref(0)
const toolbar = ref(null)
const editing = ref(null)
let editPosition = null
let insertion = null
let syncing = false
const incompatible = computed(() => {
  const refs = isReference(content.value) ? [content.value] : content.value?.parts?.filter(isReference) ?? []
  const owner = builder.templateOwner(props.node.id)
  return refs.some((value) => !compatibleField(referenceConfig(value, { type: builder.entryContext(props.node.id).type, types: builder.types.value, itemFields: owner ? builder.subfieldsOf(owner) : [] }), props.target))
})
const ReferenceNode = Node.create({
  name: 'smartReference', group: 'inline', inline: true, atom: true, selectable: true,
  addAttributes() { return { reference: { default: null, parseHTML: (element) => { try { return JSON.parse(element.getAttribute('data-smart-ref')) } catch { return null } }, renderHTML: (attrs) => ({ 'data-smart-ref': JSON.stringify(attrs.reference) }) } } },
  parseHTML() { return [{ tag: 'span[data-smart-ref]', getAttrs: (element) => { try { return { reference: parseReference(referenceSyntax(JSON.parse(element.getAttribute('data-smart-ref')))) } } catch { return false } } }] },
  renderHTML({ node, HTMLAttributes }) { return ['span', mergeAttributes(HTMLAttributes, { class: 'smart-reference', role: 'button', tabindex: '0', contenteditable: 'false', 'aria-label': `${t('site.binding.edit')} ${smartSummary(node.attrs.reference)}` }), smartSummary(node.attrs.reference)] },
})
const escapeHtml = (text) => String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
function editorHtml(value) {
  const chip = (ref) => `<span data-smart-ref="${escapeHtml(JSON.stringify(ref))}"></span>`
  const literal = (part) => props.target === 'html' ? part : escapeHtml(part).replace(/\n/g, '<br>')
  if (isReference(value)) return `<p>${chip(value)}</p>`
  if (value?.type === 'template') {
    const html = value.parts.map((part) => typeof part === 'string' ? literal(part) : chip(part)).join('')
    return props.target === 'html' ? html : `<p>${html}</p>`
  }
  return props.target === 'html' ? String(value ?? '') : `<p>${literal(String(value ?? ''))}</p>`
}
function editorValue() {
  const root = document.createElement('div')
  root.innerHTML = editor.value?.getHTML() ?? ''
  const refs = []
  const prefix = `SMART${crypto.randomUUID().replace(/-/g, '')}REF`
  root.querySelectorAll('span[data-smart-ref]').forEach((element) => {
    const reference = JSON.parse(element.getAttribute('data-smart-ref'))
    const marker = `${prefix}${refs.length}END`
    refs.push(reference)
    element.replaceWith(document.createTextNode(marker))
  })
  if (refs.length === 1 && root.children.length === 1 && root.firstElementChild.tagName === 'P' && root.firstElementChild.textContent === `${prefix}0END`) return refs[0]
  let text
  if (props.target === 'html') text = root.innerHTML
  else { root.querySelectorAll('br').forEach((br) => br.replaceWith('\n')); text = [...root.children].map((element) => element.textContent).join('\n') }
  const parts = text.split(new RegExp(`(${prefix}\\d+END)`)).filter(Boolean).map((part) => part.startsWith(prefix) ? refs[Number(part.slice(prefix.length, -3))] : part)
  return refs.length ? { type: 'template', parts } : text
}
function output(value) {
  if (props.target === 'link') emit('update:modelValue', { type: 'dynamic', value, new_tab: !!props.modelValue?.new_tab })
  else emit('update:modelValue', value)
}
const editor = useEditor({
  extensions: [...(props.target === 'html' ? richTextExtensions() : [StarterKit]), ReferenceNode], content: editorHtml(content.value?.type === 'invalid' ? '' : content.value),
  editorProps: {
    attributes: { 'aria-label': props.label || t('site.binding.content'), class: props.target === 'html' ? 'outline-none min-h-[12rem]' : 'outline-none min-h-[2rem]' },
    handleClickOn(_view, _pos, node, nodePos) {
      if (node.type.name !== 'smartReference') return false
      openPicker(node.attrs.reference, nodePos)
      return true
    },
    handleKeyDown(_view, event) {
      if (props.target !== 'html' || event.key.toLowerCase() !== 'k' || !(event.metaKey || event.ctrlKey)) return false
      event.preventDefault()
      toolbar.value?.openLink()
      return true
    },
    handleDOMEvents: { keydown(view, event) {
      if (!['Enter', ' '].includes(event.key)) return false
      const element = event.target.closest?.('[data-smart-ref]')
      if (!element) return false
      event.preventDefault()
      const pos = view.posAtDOM(element, 0)
      openPicker(JSON.parse(element.getAttribute('data-smart-ref')), pos)
      return true
    } },
  },
  onUpdate() { if (!syncing && !syntaxMode.value) output(editorValue()) },
})
watch(content, (value) => {
  raw.value = syntaxText(value)
  syntaxError.value = value?.type === 'invalid'
  if (!editor.value || syntaxMode.value || value?.type === 'invalid') return
  if (JSON.stringify(editorValue()) === JSON.stringify(value)) return
  syncing = true
  editor.value.commands.setContent(editorHtml(value), { emitUpdate: false })
  syncing = false
}, { deep: true })
function updateSyntax(text) {
  raw.value = text
  if (!text && !textual.value) { syntaxError.value = false; if (props.target === 'link') emit('update:modelValue', { type: 'none' }); else output(props.target === 'media_list' ? [] : ''); return }
  try {
    const value = parseSmartValue(text, props.target)
    if (['media', 'media_list', 'link', 'value'].includes(props.target) && !isReference(value) && text) throw new Error('syntax')
    syntaxError.value = false
    output(value)
  } catch { syntaxError.value = true; output({ type: 'invalid', text }) }
}
function toggleSyntax() {
  if (syntaxMode.value && syntaxError.value) return
  syntaxMode.value = !syntaxMode.value
  if (syntaxMode.value) raw.value = syntaxText(content.value)
  else if (textual.value) { syncing = true; editor.value?.commands.setContent(editorHtml(content.value), { emitUpdate: false }); syncing = false }
}
function openPicker(value = null, pos = null) {
  editing.value = isReference(value) ? value : !textual.value && isReference(content.value) ? content.value : null
  editPosition = pos
  insertion = editor.value ? { from: editor.value.state.selection.from, to: editor.value.state.selection.to } : null
  pickerKey.value++
  pickerOpen.value = true
}
function closePicker() { pickerOpen.value = false; if (textual.value && !syntaxMode.value) editor.value?.commands.focus(); else pickerTrigger.value?.focus() }
function insert(value) {
  if (syntaxMode.value) {
    const text = editing.value ? raw.value.replace(serializeSmartValue(editing.value), serializeSmartValue(value)) : raw.value + serializeSmartValue(value)
    updateSyntax(text)
  } else if (textual.value && editor.value) {
    if (editPosition !== null) editor.value.chain().focus().command(({ tr }) => { tr.setNodeMarkup(editPosition, undefined, { reference: value }); return true }).run()
    else editor.value.chain().focus().insertContentAt(insertion ?? editor.value.state.selection.from, { type: 'smartReference', attrs: { reference: value } }).run()
  } else output(value)
  closePicker()
}
function clear() {
  syntaxError.value = false
  syntaxMode.value = false
  if (props.target === 'link') emit('update:modelValue', { type: 'none' })
  else emit('update:modelValue', props.target === 'media_list' ? [] : props.target === 'html' ? '<p></p>' : '')
}
</script>

<style scoped>
.smart-editor :deep(.smart-reference) { display: inline-block; border-radius: 0.25rem; padding: 0.1rem 0.3rem; margin: 0 0.1rem; color: rgb(67 56 202); background: rgb(238 242 255); cursor: pointer; font-size: 0.7rem; }
.smart-editor :deep(.smart-reference:focus-visible), .smart-editor :deep(.ProseMirror-selectednode) { outline: 2px solid rgb(99 102 241); }
.smart-editor :deep(p + p) { margin-top: 0.5rem; }
.smart-editor :deep(ul) { list-style: disc; padding-left: 1rem; }
.smart-editor :deep(ol) { list-style: decimal; padding-left: 1rem; }
.smart-editor :deep(li > p) { margin: 0; }
.smart-editor :deep(:is(h1, h2, h3, h4)) { font-weight: 600; line-height: 1.25; margin: 0.75rem 0 0.25rem; }
.smart-editor :deep(:is(h1, h2, h3, h4):first-child) { margin-top: 0; }
.smart-editor :deep(h1) { font-size: 1.25rem; }
.smart-editor :deep(h2) { font-size: 1.1rem; }
.smart-editor :deep(h3) { font-size: 0.95rem; }
.smart-editor :deep(h4) { font-size: 0.85rem; }
.smart-editor :deep(:is(p, h1, h2, h3, h4, ul, ol, blockquote, pre, hr) + :is(ul, ol, blockquote, pre, hr)) { margin-top: 0.5rem; }
.smart-editor :deep(blockquote) { border-left: 3px solid rgb(var(--color-slate-400)); padding-left: 0.6rem; color: rgb(var(--color-slate-500)); }
.smart-editor :deep(code) { border-radius: 0.2rem; background: rgb(var(--color-slate-400) / 0.2); padding: 0.05rem 0.25rem; font-family: ui-monospace, monospace; font-size: 0.9em; }
.smart-editor :deep(pre) { border-radius: 0.375rem; background: rgb(15 23 42); color: rgb(226 232 240); padding: 0.5rem 0.6rem; overflow-x: auto; }
.smart-editor :deep(pre code) { background: none; padding: 0; }
.smart-editor :deep(hr) { border: 0; border-top: 1px solid rgb(var(--color-slate-400) / 0.6); margin: 0.75rem 0; }
.smart-editor :deep(mark) { background: rgb(253 224 71 / 0.6); color: inherit; border-radius: 0.15rem; padding: 0 0.1rem; }
.smart-editor :deep(a) { color: rgb(79 70 229); text-decoration: underline; text-underline-offset: 2px; }
.smart-editor :deep(.ProseMirror) { max-height: 36rem; overflow-y: auto; resize: vertical; }
:root[data-mode="dark"] .smart-editor :deep(.smart-reference) { color: rgb(199 210 254); background: rgb(99 102 241 / 0.2); }
:root[data-mode="dark"] .smart-editor :deep(a) { color: rgb(165 180 252); }
:root[data-mode="dark"] .smart-editor :deep(mark) { background: rgb(250 204 21 / 0.3); }
</style>
