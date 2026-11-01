<template>
  <span class="wireframe-tree" aria-hidden="true">
    <span
      v-for="(node, index) in nodes"
      :key="index"
      class="wireframe-node"
      :class="[`wireframe-node--${node.kind}`, { 'is-horizontal': node.horizontal, 'is-vertical': node.vertical, 'has-background-image': node.backgroundImage }]"
      :style="{ '--preview-span': node.span || 1, '--preview-columns': node.columns || 1 }"
      :data-preview-kind="node.kind"
    >
      <template v-if="['image', 'video', 'gallery'].includes(node.kind) || node.backgroundImage">
        <span class="wireframe-media">
          <svg viewBox="0 0 160 90" fill="currentColor" focusable="false">
            <path v-if="node.kind === 'video'" d="m63 20 42 25-42 25Z" />
            <template v-else>
              <circle cx="111" cy="22" r="11" />
              <path d="M24 66 57 32a7 7 0 0 1 10 0l24 25 11-11a7 7 0 0 1 10 0l22 24H27a3 3 0 0 1-3-4Z" />
            </template>
          </svg>
        </span>
      </template>
      <span v-if="['heading', 'line', 'button', 'search', 'divider', 'accordion'].includes(node.kind)" class="wireframe-bar"></span>
      <span v-else-if="['text', 'html', 'table'].includes(node.kind)" class="wireframe-text"><span></span><span></span><span></span></span>
      <span v-else-if="['menu', 'language_switcher'].includes(node.kind)" class="wireframe-links"><span></span><span></span><span></span></span>
      <span v-else-if="['icon', 'theme_toggle'].includes(node.kind)" class="wireframe-icon"></span>
      <span v-else-if="node.kind === 'site_title'" class="wireframe-brand">
        <span v-if="node.logo" class="wireframe-icon"></span><span v-if="node.name" class="wireframe-bar"></span>
      </span>
      <span v-else-if="node.kind === 'form'" class="wireframe-form"><span></span><span></span><span></span></span>
      <TemplatePreviewNodes v-if="node.children?.length" :nodes="node.children" />
    </span>
  </span>
</template>

<script setup>
defineProps({ nodes: { type: Array, default: () => [] } });
</script>

<style scoped>
.wireframe-tree { display: flex; flex-direction: column; gap: 10px; width: 100%; min-width: 0; }
.wireframe-node { display: block; min-width: 0; }
.wireframe-node--section + .wireframe-node--section { margin-top: 8px; }
.wireframe-node--row > .wireframe-tree,
.is-horizontal > .wireframe-tree { flex-direction: row; align-items: center; gap: 12px; }
.is-horizontal > .wireframe-tree > .wireframe-node { flex: var(--preview-span) 1 0; }
.wireframe-node--collection > .wireframe-tree { display: grid; grid-template-columns: repeat(var(--preview-columns), minmax(0, 1fr)); gap: 12px; }
.wireframe-node--collection .wireframe-node--column { padding: 8px; border: 1px solid currentColor; border-radius: 5px; }
.wireframe-media { display: flex; justify-content: center; height: 68px; border-radius: 6px; background: currentColor; overflow: hidden; }
.wireframe-media svg { width: 112px; max-width: 100%; color: rgb(var(--color-slate-400) / 0.5); }
.wireframe-node--gallery .wireframe-media { background: repeating-linear-gradient(90deg, currentColor 0, currentColor 30%, transparent 30%, transparent 35%); }
.wireframe-bar, .wireframe-text > span, .wireframe-links > span, .wireframe-form > span { display: block; height: 7px; border-radius: 4px; background: currentColor; }
.wireframe-node--heading .wireframe-bar { height: 12px; width: 72%; }
.wireframe-node--line .wireframe-bar { width: 66%; }
.wireframe-text { display: grid; gap: 6px; }
.wireframe-text > span:nth-child(2) { width: 92%; }
.wireframe-text > span:nth-child(3) { width: 74%; }
.wireframe-node--button .wireframe-bar { width: 56px; max-width: 100%; height: 18px; }
.wireframe-node--search .wireframe-bar { height: 20px; border: 1px solid currentColor; background: transparent; }
.wireframe-node--divider .wireframe-bar { height: 1px; }
.wireframe-node--spacer { height: 12px; }
.wireframe-links { display: flex; gap: 8px; }
.wireframe-links > span { width: 28px; max-width: 28%; }
.is-vertical .wireframe-links { flex-direction: column; }
.wireframe-icon { display: block; flex-shrink: 0; width: 18px; height: 18px; border-radius: 50%; background: currentColor; }
.wireframe-brand { display: flex; align-items: center; gap: 8px; }
.wireframe-brand .wireframe-bar { width: 70px; max-width: 70%; height: 10px; }
.wireframe-form { display: grid; gap: 7px; }
.wireframe-form > span { height: 18px; border: 1px solid currentColor; background: transparent; }
.wireframe-form > span:last-child { width: 40%; background: currentColor; }
.has-background-image { position: relative; padding: 8px; border-radius: 6px; background: rgb(var(--color-slate-400) / 0.1); }
.has-background-image > .wireframe-media { height: 40px; margin-bottom: 8px; }
</style>
