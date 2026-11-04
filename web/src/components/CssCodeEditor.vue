<template>
  <div class="css-code-editor relative min-h-0 overflow-hidden rounded-lg border border-slate-300 bg-white" :class="{ 'is-disabled': disabled }">
    <textarea
      v-if="loadFailed"
      class="h-full w-full resize-none border-0 bg-transparent p-3 font-mono text-xs leading-relaxed focus:ring-0"
      :value="modelValue"
      :disabled="disabled"
      :placeholder="placeholder"
      :aria-label="label"
      spellcheck="false"
      @input="emit('update:modelValue', $event.target.value)"
    ></textarea>
    <div v-else ref="hostRef" class="h-full"></div>
    <div v-if="!ready && !loadFailed" class="absolute inset-0 flex items-center justify-center"><LoadingSpinner /></div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import LoadingSpinner from "./LoadingSpinner.vue";

/**
 * CSS source editor with syntax highlighting, completion and bracket matching.
 * CodeMirror is only loaded once an editor is shown.
 */
const props = defineProps({
  modelValue: { type: String, default: "" },
  disabled: { type: Boolean, default: false },
  placeholder: { type: String, default: "" },
  label: { type: String, default: "" },
  /** The text is the inside of a rule: declarations and nested rules, as in the builder's class sections. */
  nested: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue"]);

const hostRef = ref(null);
const ready = ref(false);
const loadFailed = ref(false);
let view = null;
let editable = null;
let editableExtension = null;

onMounted(async () => {
  try {
    const [{ basicSetup }, { css, cssCompletionSource, cssLanguage }, { Compartment, EditorState }, { EditorView, keymap, placeholder }, { indentWithTab }] = await Promise.all([
      import("codemirror"),
      import("@codemirror/lang-css"),
      import("@codemirror/state"),
      import("@codemirror/view"),
      import("@codemirror/commands"),
    ]);
    if (!hostRef.value) return;

    // Nested text is parsed as a declaration list, so completion offers properties, not selectors.
    const language = props.nested
      ? [cssLanguage.configure({ top: "Styles" }), cssLanguage.data.of({ autocomplete: cssCompletionSource })]
      : css();
    editable = new Compartment();
    editableExtension = (disabled) => [EditorView.editable.of(!disabled), EditorState.readOnly.of(disabled)];
    view = new EditorView({
      parent: hostRef.value,
      state: EditorState.create({
        doc: props.modelValue ?? "",
        extensions: [
          basicSetup,
          language,
          keymap.of([indentWithTab]),
          placeholder(props.placeholder),
          editable.of(editableExtension(props.disabled)),
          EditorView.lineWrapping,
          EditorView.contentAttributes.of({ "aria-label": props.label, spellcheck: "false" }),
          EditorView.updateListener.of((update) => {
            if (update.docChanged) emit("update:modelValue", update.state.doc.toString());
          }),
        ],
      }),
    });
    ready.value = true;
  } catch (error) {
    view?.destroy();
    view = null;
    if (!hostRef.value) return;
    loadFailed.value = true;
    console.warn('CSS editor could not load; using plain text editing.', error);
  }
});

// Outside changes, such as the builder's undo, replace the editor's text.
watch(() => props.modelValue, (value) => {
  const next = value ?? "";
  if (!view || view.state.doc.toString() === next) return;
  view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: next } });
});

watch(() => props.disabled, (disabled) => {
  view?.dispatch({ effects: editable.reconfigure(editableExtension(disabled)) });
});

/** Append a top-level rule, keeping picks outside any unfinished editor selection. */
function addRule(selector) {
  if (props.disabled) return;
  const source = view ? view.state.doc.toString() : props.modelValue;
  const gap = source.trim() ? (source.endsWith("\n\n") ? "" : source.endsWith("\n") ? "\n" : "\n\n") : "";
  const insert = `${gap}${selector} {\n  \n}\n`;
  if (!view) {
    emit("update:modelValue", source + insert);
    return;
  }
  const anchor = source.length + gap.length + selector.length + 5;
  view.dispatch({ changes: { from: source.length, insert }, selection: { anchor }, scrollIntoView: true });
  view.focus();
}

defineExpose({ addRule });

onBeforeUnmount(() => {
  view?.destroy();
  view = null;
});
</script>

<style scoped>
.css-code-editor:focus-within {
  border-color: rgb(var(--color-theme-500, 99 102 241));
  box-shadow: 0 0 0 1px rgb(var(--color-theme-500, 99 102 241));
}
.css-code-editor.is-disabled {
  background: rgb(var(--color-slate-50));
}
.css-code-editor :deep(.cm-editor) {
  height: 100%;
  font-size: 12px;
}
.css-code-editor :deep(.cm-editor.cm-focused) {
  outline: none;
}
.css-code-editor :deep(.cm-scroller) {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  line-height: 1.6;
}
.css-code-editor :deep(.cm-gutters) {
  border-right: 1px solid rgb(var(--color-slate-200));
  background: rgb(var(--color-slate-50));
  color: rgb(var(--color-slate-400));
}
.css-code-editor :deep(.cm-activeLine),
.css-code-editor :deep(.cm-activeLineGutter) {
  background: rgb(var(--color-slate-100) / 0.7);
}
.css-code-editor :deep(.cm-placeholder) {
  color: rgb(var(--color-slate-400));
}
.css-code-editor :deep(.cm-cursor) {
  border-left-color: currentColor;
}
/* CodeMirror's tooltips are light-only; follow the app theme instead. */
.css-code-editor :deep(.cm-tooltip) {
  border: 1px solid rgb(var(--color-slate-200));
  border-radius: 0.375rem;
  background: rgb(var(--color-raised, 255 255 255));
  color: rgb(var(--color-slate-800));
  box-shadow: 0 8px 24px -8px rgb(0 0 0 / 0.35);
  overflow: hidden;
}
.css-code-editor :deep(.cm-tooltip-autocomplete > ul) {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
}
.css-code-editor :deep(.cm-tooltip-autocomplete > ul > li) {
  padding: 1px 6px;
  color: rgb(var(--color-slate-800));
}
.css-code-editor :deep(.cm-tooltip-autocomplete > ul > li[aria-selected]) {
  background: rgb(var(--color-theme-600));
  color: #fff;
}
.css-code-editor :deep(.cm-completionMatchedText) {
  text-decoration: none;
  font-weight: 700;
  color: rgb(var(--color-theme-600));
}
.css-code-editor :deep(.cm-tooltip-autocomplete > ul > li[aria-selected] .cm-completionMatchedText) {
  color: inherit;
}
.css-code-editor :deep(.cm-completionIcon),
.css-code-editor :deep(.cm-completionDetail) {
  color: rgb(var(--color-slate-500));
  opacity: 1;
}
.css-code-editor :deep(.cm-tooltip-autocomplete > ul > li[aria-selected] .cm-completionIcon),
.css-code-editor :deep(.cm-tooltip-autocomplete > ul > li[aria-selected] .cm-completionDetail) {
  color: inherit;
}
.css-code-editor :deep(.cm-completionInfo) {
  border: 1px solid rgb(var(--color-slate-200));
  background: rgb(var(--color-raised, 255 255 255));
  color: rgb(var(--color-slate-700));
}
</style>
