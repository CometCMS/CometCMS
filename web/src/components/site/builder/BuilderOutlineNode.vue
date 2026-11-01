<template>
  <li role="treeitem" :aria-expanded="hasChildren ? expanded : undefined" :aria-selected="isSelected">
    <div
      ref="rowRef"
      class="group relative flex h-8 cursor-pointer select-none items-center gap-1.5 rounded-md pr-1 text-xs transition-colors"
      :class="[
        isSelected ? 'bg-theme-600 text-white' : isHovered ? 'bg-theme-50 text-slate-900' : 'text-slate-700 hover:bg-slate-100',
        dropPosition === 'inside' ? 'ring-2 ring-inset ring-theme-500' : '',
        accent && !isSelected ? `outline-accent outline-accent-${accent}` : '',
      ]"
      :style="{ paddingLeft: `${depth * 14 + 4}px` }"
      :draggable="!renaming && !isAreaContent"
      @click="builder.select(node.id)"
      @mouseenter="builder.hoverId.value = node.id"
      @mouseleave="builder.hoverId.value = null"
      @dragstart.stop="onDragStart"
      @dragend="onDragEnd"
      @dragover.prevent.stop="onDragOver"
      @dragleave="dropPosition = ''"
      @drop.prevent.stop="onDrop"
      @contextmenu.prevent="builder.openContextMenu(node.id, $event.clientX, $event.clientY)"
    >
      <span
        v-for="(continues, level) in ancestorGuides"
        v-show="level > 0 && continues"
        :key="level"
        aria-hidden="true"
        class="outline-guide outline-guide-continuation"
        :class="{ 'outline-guide-selected': isSelected }"
        :style="{ left: `${level * 14}px` }"
      ></span>
      <span
        v-if="depth > 0"
        aria-hidden="true"
        class="outline-guide outline-guide-branch"
        :class="{ 'outline-guide-last': last, 'outline-guide-selected': isSelected }"
        :style="{ left: `${depth * 14}px` }"
      ></span>
      <span v-if="dropPosition === 'before'" class="pointer-events-none absolute inset-x-0 -top-px h-0.5 rounded bg-theme-500"></span>
      <span v-if="dropPosition === 'after'" class="pointer-events-none absolute inset-x-0 -bottom-px h-0.5 rounded bg-theme-500"></span>

      <button
        v-if="hasChildren"
        type="button"
        class="flex h-5 w-5 shrink-0 items-center justify-center rounded opacity-70 hover:opacity-100"
        :aria-label="expanded ? t('site.builder.collapse') : t('site.builder.expand')"
        @click.stop="expanded = !expanded"
      >
        <Icon icon="mdi:chevron-right" class="h-4 w-4 transition-transform" :class="{ 'rotate-90': expanded }" />
      </button>
      <span v-else class="w-5 shrink-0"></span>

      <Icon :icon="icon" class="outline-icon h-4 w-4 shrink-0" :class="isSelected ? 'text-white/80' : 'text-slate-400'" />
      <input
        v-if="renaming"
        ref="nameInput"
        v-model="draftName"
        type="text"
        class="min-w-0 flex-1 rounded border border-theme-400 bg-white px-1 py-0.5 text-xs text-slate-900 outline-none focus:ring-1 focus:ring-theme-500"
        :maxlength="builder.spec.value?.max_node_name_length ?? 120"
        :aria-label="t('site.builder.nodeName')"
        :placeholder="builder.nodeLabel({ ...node, name: '' })"
        @click.stop
        @dblclick.stop
        @keydown="onNameKeydown"
        @blur="finishRename"
        @dragstart.prevent.stop
      />
      <template v-else>
        <span class="min-w-0 truncate font-medium" :title="builder.nodeLabel(node)" @dblclick.stop="!isAreaContent && startRename()">{{ builder.nodeLabel(node) }}</span>
        <span class="min-w-0 flex-1 truncate" :class="isSelected ? 'text-white/70' : 'text-slate-400'">{{ builder.nodeSummary(node) }}</span>
      </template>

      <span v-if="!renaming" class="hidden shrink-0 items-center group-hover:flex group-focus-within:flex" :class="{ '!flex': isSelected }">
        <template v-if="!isAreaContent">
          <button type="button" class="outline-action" :title="t('site.builder.rename')" :aria-label="t('site.builder.rename')" @click.stop="startRename">
            <Icon icon="mdi:pencil-outline" class="h-3.5 w-3.5" />
          </button>
          <button type="button" class="outline-action" :disabled="first" :title="t('site.builder.moveUp')" @click.stop="builder.shift(node.id, -1)">
            <Icon icon="mdi:arrow-up" class="h-3.5 w-3.5" />
          </button>
          <button type="button" class="outline-action" :disabled="last" :title="t('site.builder.moveDown')" @click.stop="builder.shift(node.id, 1)">
            <Icon icon="mdi:arrow-down" class="h-3.5 w-3.5" />
          </button>
        </template>
        <button type="button" class="outline-action" :title="t('site.menu.more')" @click.stop="openMenu">
          <Icon icon="mdi:dots-horizontal" class="h-3.5 w-3.5" />
        </button>
      </span>
    </div>

    <ul v-if="hasChildren && expanded" role="group">
      <BuilderOutlineNode
        v-for="(child, index) in node.children"
        :key="child.id"
        :node="child"
        :depth="depth + 1"
        :ancestor-guides="[...ancestorGuides, !last]"
        :first="index === 0"
        :last="index === node.children.length - 1"
      />
    </ul>
  </li>
</template>

<script setup>
import { computed, nextTick, ref, watch } from "vue";
import { Icon } from "@iconify/vue";
import { nodeIcon } from "./kindIcons.js";
import { contains, findNode } from "../../../composables/layoutTree.js";
import { useFieldTypeMeta } from "../../../composables/useFieldTypeMeta.js";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useI18n } from "../../../i18n/index.js";

const props = defineProps({
  node: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  ancestorGuides: { type: Array, default: () => [] },
  first: { type: Boolean, default: false },
  last: { type: Boolean, default: false },
});

const builder = useSiteBuilder();
const { t } = useI18n();
const { fieldTypeIcon } = useFieldTypeMeta();
const expanded = ref(true);
const dropPosition = ref("");
const rowRef = ref(null);
const renaming = ref(false);
const draftName = ref("");
const nameInput = ref(null);

const hasChildren = computed(() => (props.node.children?.length ?? 0) > 0);
const isSelected = computed(() => builder.selectedId.value === props.node.id);
const isHovered = computed(() => builder.hoverId.value === props.node.id);
const isContainer = computed(() => builder.isDropContainer(props.node));
/** An instance's content for one area: named by its component and kept in place. */
const isAreaContent = computed(() => props.node.kind === "area_content");
// Content areas and component instances stand out, in the same colors the canvas marks them with.
const accent = computed(() => {
  if (["page_content", "site_content", "component_area", "area_content"].includes(props.node.kind)) return "entry";
  return props.node.kind === "component" ? "component" : "";
});
const icon = computed(() => {
  if (props.node.kind === "field") {
    const config = builder.fieldConfig(props.node);
    return config ? fieldTypeIcon(config.type) : "mdi:alert-outline";
  }
  return nodeIcon(props.node, builder.isStacked(props.node), builder.componentOf(props.node));
});

// Reveal the selection when it is chosen on the canvas.
watch(
  () => builder.selectedId.value,
  async (id) => {
    if (!id) return;
    if (id !== props.node.id && contains(props.node, id)) expanded.value = true;
    if (id === props.node.id) {
      await nextTick();
      rowRef.value?.scrollIntoView({ block: "nearest" });
    }
  },
  { immediate: true },
);

function openMenu(event) {
  const rect = event.currentTarget.getBoundingClientRect();
  builder.openContextMenu(props.node.id, rect.left, rect.bottom + 4);
}

async function startRename() {
  builder.select(props.node.id);
  draftName.value = props.node.name ?? "";
  renaming.value = true;
  await nextTick();
  nameInput.value?.focus();
  nameInput.value?.select();
}

function finishRename() {
  if (!renaming.value) return;
  renaming.value = false;
  builder.renameNode(props.node.id, draftName.value);
}

function onNameKeydown(event) {
  if (event.isComposing) return;
  if (event.key === "Enter") {
    event.preventDefault();
    event.stopPropagation();
    finishRename();
  } else if (event.key === "Escape") {
    event.preventDefault();
    event.stopPropagation();
    renaming.value = false;
  } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
    finishRename();
  }
}

function onDragStart(event) {
  builder.drag.value = { source: "move", id: props.node.id };
  event.dataTransfer.effectAllowed = "move";
  event.dataTransfer.setData("text/plain", props.node.id);
}

function onDragEnd() {
  dropPosition.value = "";
  setTimeout(() => {
    builder.drag.value = null;
  }, 0);
}

function onDragOver(event) {
  const drag = builder.drag.value;
  if (!drag || (drag.source === "move" && (drag.id === props.node.id || contains(findNode(builder.nodes.value, drag.id) ?? { children: [] }, props.node.id)))) {
    dropPosition.value = "";
    return;
  }

  const rect = event.currentTarget.getBoundingClientRect();
  const ratio = (event.clientY - rect.top) / rect.height;
  dropPosition.value = isContainer.value
    ? ratio < 0.3 ? "before" : ratio > 0.7 ? "after" : "inside"
    : ratio < 0.5 ? "before" : "after";
}

function onDrop() {
  const position = dropPosition.value;
  dropPosition.value = "";
  if (position) builder.dropAt(props.node.id, position);
}
</script>

<style scoped>
.outline-guide {
  position: absolute;
  top: 0;
  bottom: 0;
  border-left: 1px solid rgb(var(--color-slate-300));
  opacity: var(--outline-guide-opacity, 0);
  transition: opacity 200ms ease;
  pointer-events: none;
}
@media (prefers-reduced-motion: reduce) {
  .outline-guide {
    transition: none;
  }
}
.outline-guide-selected {
  border-color: rgb(255 255 255 / 0.4);
}
.outline-guide-branch::after {
  content: "";
  position: absolute;
  top: 16px;
  left: 0;
  width: 6px;
  border-top: 1px solid rgb(var(--color-slate-300));
}
.outline-guide-selected::after {
  border-color: rgb(255 255 255 / 0.4);
}
.outline-guide-last {
  bottom: auto;
  height: 16px;
}
.outline-accent-entry {
  --outline-accent: 33 127 152;
}
.outline-accent-component {
  --outline-accent: 134 80 177;
}
:global(:root[data-theme="dark"]) .outline-accent-entry {
  --outline-accent: 94 190 214;
}
:global(:root[data-theme="dark"]) .outline-accent-component {
  --outline-accent: 190 150 230;
}
.outline-accent {
  background: rgb(var(--outline-accent) / 0.08);
  box-shadow: inset 2px 0 0 rgb(var(--outline-accent) / 0.7);
  color: rgb(var(--outline-accent));
}
.outline-accent:hover {
  background: rgb(var(--outline-accent) / 0.15);
}
.outline-accent .outline-icon {
  color: rgb(var(--outline-accent));
}
.outline-action {
  display: inline-flex;
  height: 1.375rem;
  width: 1.375rem;
  align-items: center;
  justify-content: center;
  border-radius: 0.25rem;
  opacity: 0.75;
}
.outline-action:hover:not(:disabled) {
  opacity: 1;
  background: rgb(var(--color-slate-900) / 0.08);
}
.outline-action:disabled {
  opacity: 0.25;
}
</style>
