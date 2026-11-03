<template>
  <Teleport to="body">
    <div
      v-if="menu && node"
      ref="menuRef"
      role="menu"
      tabindex="-1"
      :aria-label="builder.nodeLabel(node)"
      class="fixed z-[60] w-72 overflow-hidden rounded-xl border border-slate-200 bg-white text-sm shadow-2xl ring-1 ring-black/5 focus:outline-none"
      :style="{ left: `${position.x}px`, top: `${position.y}px` }"
      @keydown="onKeydown"
    >
      <div class="flex items-center gap-2 px-3 pb-2 pt-2.5">
        <KindIcon :icon="nodeIcon(node, builder.isStacked(node), builder.componentOf(node), builder.spec.value)" class="h-4 w-4 shrink-0 text-theme-600" />
        <span class="min-w-0 truncate font-semibold text-slate-900" :title="builder.nodeLabel(node)">{{ builder.nodeLabel(node) }}</span>
        <span class="min-w-0 flex-1 truncate text-xs text-slate-400">{{ builder.nodeSummary(node) }}</span>
      </div>

      <!-- Quick options for this kind of element -->
      <div v-if="showColumns || quick.length" class="space-y-3 border-y border-slate-100 bg-slate-50/70 px-3 py-3">
        <ColumnLayoutPicker v-if="showColumns" :node="node" compact />
        <BuilderControl
          v-for="[group, key] in quick"
          :key="`${node.id}-${group}-${key}`"
          :node="node"
          :name="key"
          :group="group"
          :def="definition(group, key)"
          :value="valueOf(group, key)"
          :devices="[spanDevice]"
          @update="(value) => builder.updateValue(node.id, group, key, value)"
        />
      </div>

      <div v-for="(section, index) in actions" :key="index" class="py-1" :class="{ 'border-t border-slate-100': index > 0 }">
        <button
          v-for="action in section"
          :key="action.key"
          type="button"
          role="menuitem"
          :disabled="action.disabled"
          class="builder-menu-action flex w-full items-center gap-2.5 px-3 py-1.5 text-left transition-colors disabled:cursor-default disabled:opacity-40"
          :class="action.danger ? 'builder-menu-action-danger text-red-600' : 'text-slate-700'"
          @click="run(action)"
        >
          <Icon :icon="action.icon" class="h-4 w-4 shrink-0" :class="action.danger ? '' : 'text-slate-400'" />
          <span class="min-w-0 flex-1 truncate">{{ action.label }}</span>
          <kbd v-if="action.shortcut" class="font-mono text-[10px] text-slate-400">{{ action.shortcut }}</kbd>
        </button>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Icon } from "@iconify/vue";
import BuilderControl from "./BuilderControl.vue";
import ColumnLayoutPicker from "./ColumnLayoutPicker.vue";
import { nodeIcon } from "./kindIcons.js";
import KindIcon from "./KindIcon.vue";
import { COMPONENT, findNode, isStructure, locate } from "../../../composables/layoutTree.js";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useI18n } from "../../../i18n/index.js";

/** Options worth one click for each kind, as [group, key]. */
const QUICK = {
  section: [["props", "width"]],
  row: [],
  column: [["props", "span"], ["props", "vertical_align"]],
  // Only column components have a width of their own.
  [COMPONENT]: [["props", "span"]],
  heading: [["props", "level"], ["style", "align"]],
  button: [["props", "variant"], ["style", "align"]],
  menu: [["props", "orientation"]],
};

const props = defineProps({
  /** Device the canvas previews ("full" is a desktop width). */
  device: { type: String, default: "desktop" },
});

const builder = useSiteBuilder();
const { t } = useI18n();
const menuRef = ref(null);
const position = ref({ x: 0, y: 0 });

const menu = computed(() => builder.contextMenu.value);
const node = computed(() => (menu.value ? findNode(builder.nodes.value, menu.value.id) : null));
const where = computed(() => (node.value ? locate(builder.nodes.value, node.value.id) : null));
// Grids pick a preset; columns set their own width.
const showColumns = computed(() => node.value?.kind === "row");
const spanDevice = computed(() => (props.device === "full" ? "desktop" : props.device));

const quick = computed(() => {
  const kind = node.value?.kind;
  if (!kind) return [];
  const allowedStyle = builder.spec.value?.kinds?.[kind]?.style ?? [];
  if (kind === COMPONENT && node.value.props?.root !== "column") return [];
  const list = [...(QUICK[kind] ?? [])];
  if (allowedStyle.includes("align") && !list.some(([group, key]) => group === "style" && key === "align")) {
    list.push(["style", "align"]);
  }
  return list.filter(([group, key]) => (group === "style" ? allowedStyle.includes(key) : key in (builder.spec.value?.kinds?.[kind]?.props ?? {})));
});

const actions = computed(() => {
  const current = node.value;
  if (!current) return [];
  const parent = where.value?.parent ?? null;
  const structure = isStructure(current.kind);
  const instance = current.kind === COMPONENT;
  const locked = builder.isLockedRoot(current.id);
  const stacked = builder.isStacked(current.kind === "section" ? builder.columnRowFor(current) : current);

  const groups = [
    [
      parent && { key: "parent", icon: "mdi:arrow-up-left", label: t("site.menu.selectParent", { name: builder.nodeLabel(parent) }), action: () => builder.select(parent.id) },
    ],
    [
      !locked && !builder.holdsPageContent(current) && { key: "duplicate", icon: "mdi:content-copy", label: t("site.builder.duplicate"), shortcut: "Ctrl+D", action: () => builder.duplicate(current.id) },
      { key: "copy", icon: "mdi:clipboard-outline", label: t("site.menu.copy"), shortcut: "Ctrl+C", action: () => builder.copy(current.id) },
      // Containers take the copy inside, everything else after itself (as insert() places it).
      {
        key: "paste",
        icon: "mdi:clipboard-arrow-down-outline",
        label: t(builder.isDropContainer(current) ? "site.menu.pasteInside" : "site.menu.paste"),
        shortcut: "Ctrl+V",
        disabled: !builder.clipboard.value || builder.translating.value,
        action: () => builder.paste(current.id),
      },
      !instance && { key: "pasteStyle", icon: "mdi:brush-variant", label: t("site.menu.pasteStyle"), disabled: !builder.styleClipboard.value, action: () => builder.pasteStyle(current.id) },
    ],
    [
      builder.canMakeField(current) && { key: "makeField", icon: "mdi:database-plus-outline", label: t("site.field.convert"), action: () => { builder.fieldDialog.value = { id: current.id }; } },
      builder.canConvert(current) && { key: "saveComponent", icon: "mdi:puzzle-plus-outline", label: t("site.component.save"), action: () => { builder.componentDialog.value = { id: current.id }; } },
      instance && builder.componentOf(current) && { key: "editComponent", icon: "mdi:puzzle-edit-outline", label: t("site.component.edit"), action: () => builder.editComponent(current.props.component) },
      instance && builder.componentOf(current) && { key: "detach", icon: "mdi:link-variant-off", label: t("site.component.detach"), action: () => builder.detach(current.id) },
    ],
    [
      structure && {
        key: "addColumn",
        icon: stacked ? "mdi:table-row-plus-after" : "mdi:table-column-plus-after",
        label: t(stacked ? "site.menu.addRow" : "site.menu.addColumn"),
        action: () => builder.addColumn(current.id),
      },
      !builder.rootKind.value && { key: "addSection", icon: "mdi:card-plus-outline", label: t("site.menu.addSection"), action: () => builder.addSectionAfter(current.id) },
    ],
    [
      !locked && { key: "delete", icon: "mdi:trash-can-outline", label: t("common.delete"), shortcut: "Del", danger: true, action: () => builder.remove(current.id) },
    ],
  ];

  return groups.map((group) => group.filter(Boolean)).filter((group) => group.length > 0);
});

function definition(group, key) {
  const kind = node.value.kind;
  return group === "style" ? builder.spec.value.style[key] : builder.spec.value.kinds[kind].props[key];
}

function valueOf(group, key) {
  const def = definition(group, key);
  return group === "style" ? (node.value.style?.[key] ?? def.default) : node.value.props?.[key];
}

function run(action) {
  if (action.disabled) return;
  builder.closeContextMenu();
  action.action();
}

/** Keep the menu inside the window. */
async function place() {
  if (!menu.value) return;
  position.value = { x: menu.value.x, y: menu.value.y };
  await nextTick();
  const element = menuRef.value;
  if (!element) return;
  const { width, height } = element.getBoundingClientRect();
  position.value = {
    x: Math.max(8, Math.min(menu.value.x, window.innerWidth - width - 8)),
    y: Math.max(8, Math.min(menu.value.y, window.innerHeight - height - 8)),
  };
  element.querySelector('[role="menuitem"]:not(:disabled)')?.focus({ preventScroll: true });
}

function onKeydown(event) {
  const items = [...(menuRef.value?.querySelectorAll('[role="menuitem"]:not(:disabled)') ?? [])];
  const current = items.indexOf(document.activeElement);

  if (event.key === "Escape") {
    event.preventDefault();
    event.stopPropagation();
    builder.closeContextMenu();
  } else if (event.key === "ArrowDown" || event.key === "ArrowUp") {
    event.preventDefault();
    const next = event.key === "ArrowDown" ? current + 1 : current - 1;
    items[(next + items.length) % items.length]?.focus();
  }
  // Keep builder shortcuts (Delete, Ctrl+Z…) from firing behind the menu.
  event.stopPropagation();
}

function onPointerDown(event) {
  if (menu.value && !menuRef.value?.contains(event.target)) builder.closeContextMenu();
}

function close() {
  builder.closeContextMenu();
}

watch(menu, place);
// The menu belongs to one node; it closes when that node disappears.
watch(node, (value) => {
  if (menu.value && !value) close();
});

onMounted(() => {
  document.addEventListener("pointerdown", onPointerDown, true);
  window.addEventListener("resize", close);
  window.addEventListener("blur", close);
});

onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", onPointerDown, true);
  window.removeEventListener("resize", close);
  window.removeEventListener("blur", close);
});
</script>

<style scoped>
.builder-menu-action:enabled:is(:hover, :focus) {
  background-color: #f8fafc;
}

.builder-menu-action-danger:enabled:is(:hover, :focus) {
  background-color: #fef2f2;
}

:root[data-theme="dark"] .builder-menu-action:enabled:is(:hover, :focus) {
  background-color: rgb(var(--color-slate-100));
}

:root[data-theme="dark"] .builder-menu-action-danger:enabled:is(:hover, :focus) {
  background-color: rgb(127 29 29 / 0.35);
}

:root[data-theme="dark"] .builder-menu-action:enabled:is(:hover, :focus) :is(svg, kbd) {
  color: inherit;
}
</style>
