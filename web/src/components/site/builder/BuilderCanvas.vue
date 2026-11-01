<template>
  <SitePreviewFrame
    ref="frameRef"
    :body="body"
    :device="device"
    :head-css="BUILDER_CSS"
    :title="t('site.builder.canvas')"
    interactive
    @ready="attach"
    @updated="prepare"
    @rendered="(data, body) => emit('rendered', data, body)"
  />
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from "vue";
import SitePreviewFrame from "../SitePreviewFrame.vue";
import { PREVIEW_KEEP } from "../../../composables/previewDocument.js";
import { templatePartBounds } from "../../../composables/builderTemplateParts.js";
import { cssCanvasTarget } from "../../../composables/cssCanvasPicker.js";
import { findNode, isStructure } from "../../../composables/layoutTree.js";
import { isReference } from "../../../composables/smartValues.js";
import { MAX_ROWS, isMultiCell, parseTsv, pasteAt } from "../../../composables/tableGrid.js";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useI18n } from "../../../i18n/index.js";
import { activateTab, revealTabPanel } from "../../../../../cms/app/Site/assets/tabs.js";

const props = defineProps({
  body: { type: Object, default: null },
  device: { type: String, default: "desktop" },
  selectedTemplatePart: { type: String, default: null },
});

/** rendered: the preview's response data, e.g. the template it showed around the content, and the body it was for. */
const emit = defineEmits(["keydown", "select-template-part", "edit-field", "switch-page-area", "rendered"]);
const builder = useSiteBuilder();
const { t } = useI18n();
const frameRef = ref(null);

let doc = null;
let indicator = null;
let badge = null;
let badgeLabel = null;
let templateHighlight = null;
let templateLabel = null;
let hoveredTemplatePart = null;
let dropTargetState = null;
let selectedFromCanvas = false;
let resizeHandle = null;
let resizeLabel = null;
/** Column edge the grip sits on: { el, side }. */
let resizeEdge = null;
/** Running resize: { el, id, side, startX, start, span, unit, device }. */
let resizing = null;
let moveHandle = null;
let moveLabel = null;
/** Running move of a positioned element: { el, id, startX, startY, x, y, startOffsetX, startOffsetY, bounds, unit }. */
let moving = null;
/** Running inline text edit: { target, id, key, rich, original, abort }. */
let inlineEdit = null;
let cssHighlight = null;
let cssLabel = null;
let cssTarget = null;


const BUILDER_CSS = `
html.cometcms-css-picking,html.cometcms-css-picking *{cursor:crosshair!important;}
html.cometcms-css-picking .cometcms-badge,
html.cometcms-css-picking .cometcms-move,
html.cometcms-css-picking .cometcms-resize{display:none!important;}
.cometcms-css-highlight{
  position:fixed;
  z-index:2147483647;
  pointer-events:none;
  outline:2px dashed #2563eb;
  outline-offset:-2px;
}
.cometcms-css-highlight[hidden]{display:none;}
.cometcms-css-label{
  display:block;
  width:max-content;
  max-width:100%;
  overflow-wrap:anywhere;
  background:#dbeafe;
  color:#1e3a8a;
  padding:4px 6px;
  font:600 max(12px,calc(12px * var(--cometcms-ui-scale,1)))/1.4 monospace;
}
/* Keep empty nodes clickable at every canvas zoom. Low specificity preserves
   the larger minimum heights supplied by the page's layout classes. */
:where([data-node]){
  min-height:calc(16px * var(--cometcms-ui-scale,1));
}

[data-node]{
  --builder-color:#2563eb;
  outline:1px solid transparent;
  outline-offset:-1px;
}
/* Zero specificity: the page's own hover transitions (.c-stateful, .c-button)
   replace this, so the canvas previews them at the theme's speed. */
:where([data-node]){
  transition:outline-color .12s;
}

/* Strong structure borders stay visible on the page; labels retain softer tinted fills. */
[data-kind="section"]{
  --builder-color:#4b5fc8;
}

[data-kind="row"]{
  --builder-color:#4c8a2b;
}


[data-kind="component"]{
  --builder-color:#8650b1;
  outline:1px dashed color-mix(in srgb,var(--builder-color) 65%,transparent);
}

[data-kind="column"]{
  --builder-color:#b7653e;
}

/* Content areas: whoever uses the design (each entry, each placement of a component) fills them, so they stay marked. */
[data-kind="page_content"], [data-kind="site_content"], [data-kind="component_area"], [data-kind="area_content"]{
  --builder-color:#217f98;
  background-image:repeating-linear-gradient(-45deg,transparent 0 8px,color-mix(in srgb,var(--builder-color) 4%,transparent) 8px 16px);
  outline:2px dashed color-mix(in srgb,var(--builder-color) 75%,transparent);
  outline-offset:-2px;
}

/* The page builder of one entry: everything around its page content belongs to the layout. */
.c-view-page-edit .c-page-content[data-drop-root]{
  outline:2px dashed color-mix(in srgb,#217f98 75%,transparent);
  outline-offset:-2px;
}

/* The entry's other page content areas: a click edits that one instead. */
.c-view-page-edit [data-page-area]{
  position:relative;
  min-height:64px;
  cursor:pointer;
  outline:2px dashed color-mix(in srgb,#217f98 55%,transparent);
  outline-offset:-2px;
}

.c-view-page-edit [data-page-area=""]{
  cursor:default;
}

/* Positioned sections can paint over their content area's inset outline.
   Repeat it above the content without adding space or intercepting clicks.
   Inherit the outline so hover and selection keep their normal appearance. */
:where([data-kind="page_content"], [data-kind="site_content"], [data-kind="component_area"], [data-kind="area_content"], .c-view-page-edit .c-page-content[data-drop-root]){
  position:relative;
}

[data-kind="page_content"]::after,
[data-kind="site_content"]::after,
[data-kind="component_area"]::after,
[data-kind="area_content"]::after,
.c-view-page-edit .c-page-content[data-drop-root]::after,
.c-view-page-edit [data-page-area]::before{
  content:"";
  position:absolute;
  inset:0;
  z-index:2147483646;
  pointer-events:none;
  outline:inherit;
  outline-offset:inherit;
  border-radius:inherit;
}

.c-view-page-edit [data-page-area]::after{
  content:attr(data-page-area-label);
  position:absolute;
  inset:0;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:12px;
  background:color-mix(in srgb,#217f98 10%,rgba(255,255,255,.88));
  color:#286575;
  font:600 calc(13px * var(--cometcms-ui-scale,1))/1.4 system-ui,sans-serif;
  text-align:center;
  opacity:0;
  transition:opacity .15s;
}

.c-view-page-edit [data-page-area]:empty::after,
.c-view-page-edit [data-page-area]:hover::after{
  opacity:1;
}

/* Hidden on this device: kept selectable, but greyed out under warning stripes. */
:where(.c-builder [class*="c-hide-"]){
  position:relative;
}

.c-builder [class*="c-hide-"]::after{
  content:"";
  position:absolute;
  inset:0;
  z-index:1000;
  display:none;
  pointer-events:none;
  background:repeating-linear-gradient(-45deg,rgba(245,158,11,.3) 0 10px,transparent 10px 20px);
}

@media (max-width:767px){
  .c-builder .c-hide-mobile{filter:grayscale(1) opacity(.5)}
  .c-builder .c-hide-mobile::after{display:block}
}

@media (min-width:768px) and (max-width:1023px){
  .c-builder .c-hide-tablet{filter:grayscale(1) opacity(.5)}
  .c-builder .c-hide-tablet::after{display:block}
}

@media (min-width:1024px){
  .c-builder .c-hide-desktop{filter:grayscale(1) opacity(.5)}
  .c-builder .c-hide-desktop::after{display:block}
}

/* Conditions not met for the previewed entry: kept selectable, but greyed out and labelled. */
:where(.c-builder .c-condition-off){
  position:relative;
}

.c-builder .c-condition-off{
  filter:grayscale(1) opacity(.5);
}

.c-builder .c-condition-off::after{
  content:attr(data-condition-label);
  position:absolute;
  inset:0;
  z-index:1000;
  display:flex;
  align-items:flex-start;
  justify-content:flex-end;
  pointer-events:none;
  padding:2px 4px;
  font:600 max(10px,calc(10px * var(--cometcms-ui-scale,1)))/1.4 system-ui,sans-serif;
  color:#5b21b6;
  background:repeating-linear-gradient(-45deg,rgba(139,92,246,.25) 0 10px,transparent 10px 20px);
}

/* Hover and selection */
[data-builder-hover]{
  outline:max(2px,calc(2px * var(--cometcms-ui-scale,1))) dashed var(--builder-color)!important;
}

[data-builder-cell]{
  outline:2px solid #2563eb;
  outline-offset:-2px;
}
[data-builder-selected]{
  outline:max(3px,calc(3px * var(--cometcms-ui-scale,1))) solid var(--builder-color)!important;
  outline-offset:calc(-1 * max(3px,calc(3px * var(--cometcms-ui-scale,1))));
}

/* A separate overlay stays visible above shared template part content. */
.cometcms-template-highlight{
  --builder-color:#ac477e;
  position:fixed;
  z-index:2147483647;
  pointer-events:none;
  outline:calc(2px * var(--cometcms-ui-scale,1)) dashed var(--builder-color);
  outline-offset:calc(-2px * var(--cometcms-ui-scale,1));
}

.cometcms-template-label{
  --s:var(--cometcms-ui-scale,1);
  position:absolute;
  top:0;
  left:0;
  background:color-mix(in srgb,var(--builder-color) 42%,#fff);
  color:#54223f;
  font:600 max(12px,calc(12px * var(--s)))/1.2 system-ui,sans-serif;
  padding:calc(5px * var(--s)) calc(8px * var(--s));
  border-radius:0 0 calc(5px * var(--s)) 0;
  white-space:nowrap;
}

/* Empty drop area */
[data-drop-root]:empty{
  min-height:180px;
  display:flex;
  align-items:center;
  justify-content:center;
  margin:24px;
  border:2px dashed #cbd5e1;
  border-radius:12px;
  background-color:rgba(148,163,184,.035);
}

[data-drop-root]:empty::before{
  content:attr(data-empty-label);
  font:500 calc(13px * var(--cometcms-ui-scale,1))/1.4 system-ui,sans-serif;
  color:#64748b;
}

[data-kind="tab"]:empty{
  min-height:100px;
  border:1px dashed #cbd5e1;
}
[data-kind="tab"]:empty::before{
  content:attr(data-empty-label);
  display:block;
  padding:24px;
  color:#64748b;
  font:500 13px/1.4 system-ui,sans-serif;
}

/* Drag and drop indicators */
.cometcms-drop{
  position:absolute;
  z-index:2147483646;
  pointer-events:none;
  background:var(--builder-color,#2563eb);
  border-radius:3px;
  box-shadow:0 0 0 2px rgba(255,255,255,.85);
}

.cometcms-drop.is-box{
  background:color-mix(in srgb,var(--builder-color,#2563eb) 7%,transparent);
  border:2px dashed var(--builder-color,#2563eb);
  box-shadow:none;
  border-radius:6px;
}

/* Node badges */
.cometcms-badge{
  all:initial;
  --s:var(--cometcms-ui-scale,1);
  position:absolute;
  z-index:2147483647;
  display:inline-flex;
  align-items:stretch;
  cursor:pointer;
  transform:translateY(-100%);
  background:color-mix(in srgb,var(--builder-color,#2563eb) 42%,#fff);
  color:#253047;
  font:600 max(12px,calc(12px * var(--s)))/1.2 system-ui,sans-serif;
  letter-spacing:.01em;
  border-radius:calc(5px * var(--s)) calc(5px * var(--s)) 0 0;
  white-space:nowrap;
  box-shadow:inset 0 0 0 1px var(--builder-color),0 -1px 4px rgba(0,0,0,.16);
}

.cometcms-badge[hidden]{display:none;}

.cometcms-badge.is-inside{
  transform:none;
  border-radius:0 0 calc(5px * var(--s)) 0;
  box-shadow:inset 0 0 0 1px var(--builder-color),0 1px 4px rgba(0,0,0,.16);
}

.cometcms-badge.is-inside .cometcms-badge-menu{
  border-radius:0 0 calc(5px * var(--s)) 0;
}

.cometcms-badge-label{
  padding:calc(6px * var(--s)) calc(8px * var(--s));
  cursor:grab;
  user-select:none;
}

.cometcms-badge-menu{
  display:flex;
  align-items:center;
  justify-content:center;
  width:calc(26px * var(--s));
  border-left:1px solid rgba(37,48,71,.18);
  border-radius:0 calc(5px * var(--s)) 0 0;
  background:rgba(255,255,255,.1);
}

.cometcms-badge-menu svg{
  display:block;
  width:calc(16px * var(--s));
  height:calc(16px * var(--s));
  fill:currentColor;
}

.cometcms-badge:hover .cometcms-badge-menu{
  background:rgba(255,255,255,.25);
}

.cometcms-badge:focus-visible{
  outline:calc(2px * var(--s)) solid #fff;
  outline-offset:calc(2px * var(--s));
  box-shadow:0 0 0 calc(4px * var(--s)) var(--builder-color,#2563eb);
}

/* Column resizing: a grip on the edge under the pointer */
[data-builder-resize]{
  outline:2px solid var(--builder-color)!important;
  outline-offset:-2px;
}

.cometcms-resize{
  position:absolute;
  z-index:2147483646;
  width:14px;
  margin-left:-7px;
  cursor:col-resize;
  touch-action:none;
}

.cometcms-resize::before{
  content:"";
  position:absolute;
  top:0;
  bottom:0;
  left:50%;
  width:4px;
  transform:translateX(-50%);
  background:var(--builder-color,#2563eb);
  border-radius:2px;
}

.cometcms-resize::after{
  content:"";
  position:absolute;
  top:50%;
  left:50%;
  width:6px;
  height:28px;
  transform:translate(-50%,-50%);
  background:#fff;
  border:2px solid var(--builder-color,#2563eb);
  border-radius:4px;
  box-shadow:0 1px 3px rgba(0,0,0,.18);
}

.cometcms-resize-label{
  position:absolute;
  top:50%;
  left:50%;
  transform:translate(-50%,calc(-100% - 22px));
  background:color-mix(in srgb,var(--builder-color,#2563eb) 42%,#fff);
  color:#253047;
  font:600 11px/1 system-ui,sans-serif;
  padding:5px 7px;
  border-radius:5px;
  white-space:nowrap;
  pointer-events:none;
}

/* Inline text editing */
[data-node] [contenteditable]{
  outline:none;
  cursor:text;
}

html.cometcms-resizing,html.cometcms-resizing *{
  cursor:col-resize!important;
  user-select:none!important;
}

/* Move grip of a free element, on its top right corner */
.cometcms-move{
  all:initial;
  --s:var(--cometcms-ui-scale,1);
  position:absolute;
  z-index:2147483647;
  display:flex;
  align-items:center;
  justify-content:center;
  width:calc(26px * var(--s));
  height:calc(26px * var(--s));
  transform:translate(-100%,-100%);
  background:color-mix(in srgb,var(--builder-color,#2563eb) 42%,#fff);
  color:#253047;
  border-radius:calc(5px * var(--s)) calc(5px * var(--s)) 0 0;
  box-shadow:inset 0 0 0 1px var(--builder-color),0 -1px 4px rgba(0,0,0,.16);
  cursor:move;
  touch-action:none;
}
.cometcms-move[hidden]{display:none;}
.cometcms-move.is-inside{
  transform:translate(-100%,0);
  border-radius:0 0 0 calc(5px * var(--s));
}
.cometcms-move svg{
  width:calc(16px * var(--s));
  height:calc(16px * var(--s));
  fill:currentColor;
  pointer-events:none;
}
.cometcms-move-label{
  position:absolute;
  top:calc(100% + 6px);
  right:0;
  background:color-mix(in srgb,var(--builder-color,#2563eb) 42%,#fff);
  color:#253047;
  font:600 11px/1 system-ui,sans-serif;
  padding:5px 7px;
  border-radius:5px;
  white-space:nowrap;
  pointer-events:none;
}
.cometcms-move-label[hidden]{display:none;}
html.cometcms-moving,html.cometcms-moving *{
  cursor:move!important;
  user-select:none!important;
}
`;

/** Distance in px from a column edge within which its resize grip appears. */
const RESIZE_EDGE = 8;

/** Elements whose text is edited on the canvas: the prop and the rendered element holding it. */
const INLINE_TEXT = {
  heading: { key: "text", selector: ".c-heading" },
  text: { key: "html", selector: ".c-prose", rich: true },
  button: { key: "label", selector: ".c-button-label" },
  // Each cell is edited on its own; Tab moves through them.
  table: { key: "rows", selector: "[data-cell]", rich: true, cells: true },
};

/** Row and column of a rendered table cell. */
function cellAt(target) {
  const [row, column] = (target?.dataset?.cell ?? "").split(":").map(Number);
  return Number.isInteger(row) && Number.isInteger(column) ? { row, column } : null;
}


function attach(document) {
  doc = document;

  indicator = doc.createElement("div");
  indicator.className = "cometcms-drop";
  indicator.hidden = true;
  badge = doc.createElement("button");
  badge.type = "button";
  badge.className = "cometcms-badge";
  badge.setAttribute("aria-haspopup", "menu");
  badge.hidden = true;
  badgeLabel = doc.createElement("span");
  badgeLabel.className = "cometcms-badge-label";
  badgeLabel.setAttribute("draggable", "true");
  const badgeMenu = doc.createElement("span");
  badgeMenu.className = "cometcms-badge-menu";
  badgeMenu.setAttribute("draggable", "false");
  badgeMenu.setAttribute("aria-hidden", "true");
  badgeMenu.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>';
  badge.append(badgeLabel);
  badge.append(badgeMenu);
  templateHighlight = doc.createElement("div");
  templateHighlight.className = "cometcms-template-highlight";
  templateHighlight.hidden = true;
  templateHighlight.setAttribute("aria-hidden", "true");
  templateLabel = doc.createElement("span");
  templateLabel.className = "cometcms-template-label";
  templateHighlight.append(templateLabel);
  cssHighlight = doc.createElement("div");
  cssHighlight.className = "cometcms-css-highlight";
  cssHighlight.hidden = true;
  cssHighlight.setAttribute("aria-hidden", "true");
  cssLabel = doc.createElement("span");
  cssLabel.className = "cometcms-css-label";
  cssHighlight.append(cssLabel);
  hoveredTemplatePart = null;
  resizeHandle = doc.createElement("div");
  resizeHandle.className = "cometcms-resize";
  resizeHandle.hidden = true;
  resizeLabel = doc.createElement("span");
  resizeLabel.className = "cometcms-resize-label";
  resizeLabel.hidden = true;
  resizeHandle.append(resizeLabel);
  moveHandle = doc.createElement("div");
  moveHandle.className = "cometcms-move";
  moveHandle.hidden = true;
  moveHandle.title = t("site.builder.moveHint");
  moveHandle.setAttribute("aria-hidden", "true");
  moveHandle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13 6v5h5V7.75L22.25 12 18 16.25V13h-5v5h3.25L12 22.25 7.75 18H11v-5H6v3.25L1.75 12 6 7.75V11h5V6H7.75L12 1.75 16.25 6H13z"/></svg>';
  moveLabel = doc.createElement("span");
  moveLabel.className = "cometcms-move-label";
  moveLabel.hidden = true;
  moveHandle.append(moveLabel);
  for (const overlay of [indicator, badge, resizeHandle, moveHandle, templateHighlight, cssHighlight]) overlay.setAttribute(PREVIEW_KEEP, "");
  resizeEdge = null;
  resizing = null;
  moving = null;

  resizeHandle.addEventListener("pointerdown", onResizeStart);
  resizeHandle.addEventListener("pointermove", onResizeMove);
  resizeHandle.addEventListener("pointerup", onResizeEnd);
  resizeHandle.addEventListener("pointercancel", onResizeEnd);
  moveHandle.addEventListener("pointerdown", onMoveStart);
  moveHandle.addEventListener("pointermove", onMoveMove);
  moveHandle.addEventListener("pointerup", onMoveEnd);
  moveHandle.addEventListener("pointercancel", onMoveEnd);

  doc.addEventListener("click", onClick, true);
  doc.addEventListener("dblclick", onDoubleClick);
  doc.addEventListener("focusin", (event) => {
    if (builder.cssPicker.value) return;
    const tab = event.target.closest?.('[data-tabs] > .c-tabs-list > [role="tab"]');
    if (tab && findNode(builder.nodes.value, tab.dataset.tabKey)) builder.select(tab.dataset.tabKey);
  });
  doc.addEventListener("contextmenu", onContextMenu);
  doc.addEventListener("pointerdown", (event) => {
    if (!event.target.closest?.(".cometcms-badge")) builder.closeContextMenu();
  });
  doc.defaultView?.addEventListener("scroll", () => {
    builder.closeContextMenu();
    if (!resizing) showResizeEdge(null);
    applySelection(false);
    applyTemplatePartHover();
    paintCssTarget();
  }, { passive: true, capture: true });
  doc.addEventListener("mouseover", onMouseOver);
  doc.addEventListener("pointermove", onPointerMove);
  doc.documentElement.addEventListener("mouseleave", () => {
    showCssTarget(null);
    builder.hoverId.value = null;
    hoveredTemplatePart = null;
    applyTemplatePartHover();
    if (!resizing) showResizeEdge(null);
  });
  doc.addEventListener("dragstart", onDragStart);
  doc.addEventListener("dragover", onDragOver);
  doc.addEventListener("dragleave", onDragLeave);
  doc.addEventListener("drop", onDrop);
  doc.addEventListener("dragend", onDragEnd);
  doc.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && builder.cssPicker.value) {
      event.preventDefault();
      event.stopPropagation();
      builder.cssPicker.value = null;
      showCssTarget(null);
      return;
    }
    if (!event.target.closest?.('.c-lightbox')) emit("keydown", event);
  });
  doc.defaultView?.addEventListener("resize", () => {
    applySelection(false);
    applyTemplatePartHover();
    paintCssTarget();
  });

  prepare(document);
}

/** Editor markup for the rendered page, on load and after each in-place update. */
function prepare(document) {
  if (document !== doc) return;
  // An update replaced the element being edited; keep what was typed.
  if (inlineEdit && !inlineEdit.target.isConnected) finishInlineEdit(true);
  doc.body.append(indicator, badge, resizeHandle, moveHandle, templateHighlight, cssHighlight);
  doc.documentElement.classList?.toggle("cometcms-css-picking", !!builder.cssPicker.value);
  showCssTarget(null);

  doc.querySelectorAll('[data-drop-root], [data-kind="tab"]').forEach((element) => {
    element.setAttribute("data-empty-label", t("site.builder.dropHere"));
  });
  doc.querySelectorAll(".c-condition-off").forEach((element) => element.setAttribute("data-condition-label", t("site.condition.notMet")));
  doc.querySelectorAll("[data-page-area]").forEach((element) => {
    const label = builder.pageFields().find((field) => field.key === element.dataset.pageArea)?.label;
    element.setAttribute("data-page-area-label", label ? t("site.page.editArea", { label }) : t("site.page.areaWithoutField"));
  });
  // The page renders hints in English; show them in the admin's language.
  doc.querySelectorAll("[data-placeholder]").forEach((element) => {
    const key = `site.placeholder.${element.dataset.placeholder}`;
    let params = {};
    try {
      params = JSON.parse(element.dataset.placeholderParams || "{}");
    } catch {
      // Keep the parameters empty; the text still reads well.
    }
    const text = t(key, params);
    if (text !== key) element.textContent = text;
  });
  doc.querySelectorAll("[data-node]").forEach((element) => element.setAttribute("draggable", "true"));
  doc.querySelectorAll("img, a").forEach((element) => element.setAttribute("draggable", "false"));

  // Restore the editor state the update cleared before layout is read, so outlines don't blink.
  applySelection(false);
  applyHover();
  applyTemplatePartHover();
  applyStatePreview();
  if (!resizing) {
    const edge = resizeEdge;
    resizeEdge = null;
    showResizeEdge(edge?.el.isConnected ? edge : null);
  }
}

/** Shows the hover or focus state being edited on the selected element and its button (see StyleVariants). */
function applyStatePreview() {
  if (!doc) return;
  doc.querySelectorAll(".c-preview-hover, .c-preview-focus").forEach((el) => el.classList.remove("c-preview-hover", "c-preview-focus"));
  const state = builder.styleState.value;
  const el = state !== "normal" ? element(builder.selectedId.value) : null;
  if (!el) return;
  [el, ...el.querySelectorAll(":scope > .c-button")].forEach((target) => target.classList.add(`c-preview-${state}`));
}

function element(id) {
  return id && doc ? doc.querySelector(`[data-node="${CSS.escape(id)}"]`) : null;
}

function pickCssTarget(target) {
  const picker = builder.cssPicker.value;
  if (!picker) return null;
  const root = picker.rootId ? element(picker.rootId) : null;
  if (picker.rootId && !root) return null;
  return cssCanvasTarget(target, {
    root,
    className: picker.className,
    isEditable: (id) => !!findNode(builder.nodes.value, id),
  });
}

function showCssTarget(target) {
  cssTarget = target;
  paintCssTarget();
}

function paintCssTarget() {
  if (!cssHighlight) return;
  cssHighlight.hidden = !cssTarget || !cssTarget.element.isConnected;
  if (cssHighlight.hidden) return;
  const rect = cssTarget.element.getBoundingClientRect();
  cssLabel.textContent = cssTarget.label;
  Object.assign(cssHighlight.style, {
    left: `${rect.left}px`, top: `${rect.top}px`, width: `${rect.width}px`, height: `${rect.height}px`,
  });
}

function applySelection(scroll) {
  if (!doc) return;
  measureNestedRow();
  doc.querySelectorAll("[data-builder-selected]").forEach((el) => el.removeAttribute("data-builder-selected"));
  doc.querySelectorAll("[data-builder-cell]").forEach((el) => el.removeAttribute("data-builder-cell"));
  const el = element(builder.selectedId.value);
  const cell = builder.selectedCell?.value;
  if (el && cell?.id === el.dataset.node) el.querySelector(`[data-cell="${cell.row}:${cell.column}"]`)?.setAttribute("data-builder-cell", "");

  if (!el) {
    if (badge) badge.hidden = true;
    if (moveHandle) moveHandle.hidden = true;
    return;
  }

  el.setAttribute("data-builder-selected", "");
  revealTabPanel(el);
  const rect = el.getBoundingClientRect();
  const win = doc.defaultView;
  badgeLabel.textContent = builder.nodeLabel(findNode(builder.nodes.value, el.dataset.node));
  badge.title = t("site.menu.more");
  badge.setAttribute("aria-label", `${badgeLabel.textContent}: ${t("site.menu.more")}`);
  badge.style.setProperty("--builder-color", win.getComputedStyle(el).getPropertyValue("--builder-color"));
  badge.style.left = `${rect.left + win.scrollX}px`;
  badge.hidden = false;
  // Without room above the element, the badge hangs inside its top edge instead of being cut off.
  const top = rect.top + win.scrollY;
  const inside = top < badge.offsetHeight;
  badge.classList.toggle("is-inside", inside);
  badge.style.top = `${Math.max(0, top)}px`;
  placeMoveHandle(el, rect);

  if (scroll) el.scrollIntoView({ block: "nearest", behavior: "smooth" });
}

function applyHover() {
  if (!doc) return;
  doc.querySelectorAll("[data-builder-hover]").forEach((el) => el.removeAttribute("data-builder-hover"));
  const el = element(builder.hoverId.value);
  if (el && builder.hoverId.value !== builder.selectedId.value) el.setAttribute("data-builder-hover", "");
}

function applyTemplatePartHover() {
  if (!doc || !templateHighlight) return;
  const name = hoveredTemplatePart || props.selectedTemplatePart;
  const part = name && doc.querySelector(`.c-${name}:not([data-drop-root])`);
  const rect = part && templatePartBounds(part);
  templateHighlight.hidden = !rect;
  if (!rect) return;
  templateLabel.textContent = `${t(`site.region.${name}`)} · ${t("site.builder.templatePart")}`;
  Object.assign(templateHighlight.style, {
    left: `${rect.left}px`, top: `${rect.top}px`, width: `${rect.width}px`, height: `${rect.height}px`,
    outlineStyle: name === props.selectedTemplatePart ? "solid" : "dashed",
  });
}

/** Viewport coordinates in the admin for a point inside the (scaled) preview. */
function toViewport(x, y) {
  const iframe = doc?.defaultView?.frameElement;
  if (!iframe) return { x, y };
  const rect = iframe.getBoundingClientRect();
  const scale = iframe.offsetWidth > 0 ? rect.width / iframe.offsetWidth : 1;
  return { x: rect.left + x * scale, y: rect.top + y * scale };
}

/** Where to open the menu for a node, e.g. from the keyboard. */
function positionFor(id) {
  const el = element(id);
  if (!el) return null;
  const rect = el.getBoundingClientRect();
  return toViewport(rect.left + 12, Math.max(rect.top, 0) + 12);
}

function onContextMenu(event) {
  if (builder.cssPicker.value) { event.preventDefault(); return; }
  // The browser's own menu offers spelling and clipboard while editing text.
  if (inlineEdit?.target.contains(event.target)) return;
  const el = event.target.closest?.("[data-node]");
  if (!el) return;
  event.preventDefault();
  selectedFromCanvas = true;
  const point = toViewport(event.clientX, event.clientY);
  builder.openContextMenu(el.dataset.node, point.x, point.y);
}

function onClick(event) {
  const picker = builder.cssPicker.value;
  if (picker) {
    event.preventDefault();
    event.stopPropagation();
    const target = pickCssTarget(event.target);
    if (target) {
      builder.cssPicker.value = null;
      showCssTarget(null);
      picker.onPick(target.selector);
    }
    return;
  }
  const tab = event.target.closest?.('[data-tabs] > .c-tabs-list > [role="tab"]');
  if (tab) {
    event.preventDefault();
    event.stopPropagation();
    activateTab(tab);
    const key = tab.dataset.tabKey;
    if (findNode(builder.nodes.value, key)) builder.select(key);
    return;
  }
  if (event.target.closest?.('.c-lightbox')) return;
  // Clicks in the text being edited place the caret, without following links.
  if (inlineEdit?.target.contains(event.target)) {
    event.preventDefault();
    event.stopPropagation();
    return;
  }
  // A second click on the selected gallery previews its viewer.
  if (event.target.closest?.('[data-gallery-image]') && event.target.closest?.('[data-node]')?.dataset.node === builder.selectedId.value) return;
  // Native disclosure controls remain usable while selecting the element.
  if (!event.target.closest?.('.c-accordion-title')) event.preventDefault();
  event.stopPropagation();

  // Releasing a resize or move grip keeps the element selected.
  if (event.target.closest?.(".cometcms-resize, .cometcms-move")) return;

  // The badge on the selection opens its menu.
  if (event.target.closest?.(".cometcms-badge") && builder.selectedId.value) {
    const rect = badge.getBoundingClientRect();
    const point = toViewport(rect.left, rect.bottom + 4);
    builder.openContextMenu(builder.selectedId.value, point.x, point.y);
    return;
  }

  const area = event.target.closest?.("[data-page-area]");
  if (area) {
    // An area without a field gets one in the layout first.
    if (area.dataset.pageArea) emit("switch-page-area", area.dataset.pageArea);
    return;
  }

  const el = event.target.closest?.("[data-node]");
  const part = templatePartAt(event.target);
  selectedFromCanvas = true;
  builder.select(part ? null : el?.dataset.node ?? null);
  // A table cell picked on the canvas opens in the inspector.
  const cell = el?.dataset.kind === "table" && cellAt(event.target.closest?.("[data-cell]"));
  if (cell) builder.selectedCell.value = { id: el.dataset.node, ...cell };
  emit("select-template-part", part);
}

/**
 * Double-clicking a heading, text or button edits its text in place. Content
 * from smart tags of the previewed entry opens that entry's field instead.
 */
function onDoubleClick(event) {
  if (builder.cssPicker.value) return;
  if (inlineEdit || resizing) return;
  const el = event.target.closest?.("[data-node]");
  const node = el && findNode(builder.nodes.value, el.dataset.node);
  const fields = node ? entryFieldsShown(node) : [];
  if (fields.length) {
    event.preventDefault();
    // The word the double-click selected tells which of several tags was meant.
    emit("edit-field", { fields, text: doc.getSelection()?.toString().trim() ?? "" });
    return;
  }
  const config = node && INLINE_TEXT[node.kind];
  const target = config && (config.cells ? event.target.closest?.(config.selector) : el.querySelector(config.selector));
  if (!target || (config.cells && target.closest("[data-node]") !== el)) return;
  // Bound and templated values are edited in the Inspector.
  const def = builder.spec.value?.kinds?.[node.kind]?.props?.[config.key];
  const value = builder.valueFor(node, "props", config.key);
  if (builder.translating.value && !def?.localized) return;
  if (config.cells) {
    const cell = cellAt(target);
    if (!Array.isArray(value) || !cell || typeof value[cell.row]?.[cell.column] !== "string") return;
    event.preventDefault();
    startInlineEdit(target, node.id, config, { ...cell, rows: value.map((row) => row.slice()) });
    return;
  }
  if (typeof value !== "string") return;
  event.preventDefault();
  startInlineEdit(target, node.id, config);
}

/** Fields of the previewed entry that the node's smart tags show, like `{{ current.intro }}`. */
function entryFieldsShown(node) {
  // Cards of an entry list show their own entries as the current one.
  if (builder.entryContext(node.id).collection) return [];
  const fields = [];
  for (const [key, def] of Object.entries(builder.spec.value?.kinds?.[node.kind]?.props ?? {})) {
    if (!def.binding || def.binding === "link") continue;
    const value = builder.valueFor(node, "props", key);
    const refs = isReference(value) ? [value] : value?.type === "template" ? value.parts.filter(isReference) : [];
    for (const ref of refs) {
      if (ref.source === "current" && ref.path.length === 1 && !fields.includes(ref.path[0])) fields.push(ref.path[0]);
    }
  }
  return fields;
}

/**
 * Edits a text in place. Table cells pass `cell` ({ row, column, rows }): the
 * table's rows collect each cell's text while Tab moves between cells, and are
 * saved once editing ends, so the preview is not rendered again in between.
 */
function startInlineEdit(target, id, { key, rich = false }, cell = null) {
  const abort = new AbortController();
  inlineEdit = { target, id, key, rich, cell, original: target.innerHTML, abort };
  selectedFromCanvas = true;
  builder.select(id);
  if (cell) builder.selectedCell.value = { id, row: cell.row, column: cell.column };
  showResizeEdge(null);

  // Draggable ancestors would turn selecting text with the mouse into a drag.
  forEachNodeAround(target, (node) => node.setAttribute("draggable", "false"));
  target.contentEditable = rich ? "true" : "plaintext-only";
  target.spellcheck = true;

  // Keep the word the double-click selected; focusing would move the caret to the start.
  const selection = doc.getSelection();
  const range = selection?.rangeCount ? selection.getRangeAt(0) : null;
  target.focus({ preventScroll: true });
  if (range && target.contains(range.commonAncestorContainer)) {
    selection.removeAllRanges();
    selection.addRange(range);
  }

  const options = { signal: abort.signal };
  target.addEventListener("blur", () => finishInlineEdit(true), options);
  target.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      finishInlineEdit(false);
    } else if (cell && event.key === "Tab") {
      event.preventDefault();
      event.stopPropagation();
      moveCellEdit(event.shiftKey ? -1 : 1);
    } else if (cell && event.key === "Enter" && !event.shiftKey) {
      // Shift+Enter keeps the browser's line break within the cell.
      event.preventDefault();
      finishInlineEdit(true);
    } else if (!cell && event.key === "Enter" && (!rich || event.ctrlKey || event.metaKey)) {
      event.preventDefault();
      finishInlineEdit(true);
    } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
      // Save what was typed; the key still reaches the editor to save the page.
      finishInlineEdit(true);
    }
  }, options);
  // Formatting from other pages stays out of the text.
  target.addEventListener("paste", (event) => {
    if (!rich) return;
    event.preventDefault();
    const text = event.clipboardData?.getData("text/plain") ?? "";
    // Cells copied from a spreadsheet fill the table from this cell on.
    if (cell && isMultiCell(text)) {
      cell.rows[cell.row][cell.column] = cellHtml(target);
      cell.rows = pasteAt(cell.rows, cell.row, cell.column, parseTsv(text));
      cell.pasted = true;
      finishInlineEdit(true);
      return;
    }
    doc.execCommand("insertText", false, text);
  }, options);
}

/** A cell's edited text: the inline HTML the browser produced, without a trailing line break. */
function cellHtml(target) {
  return target.innerHTML.replace(/(<br\s*\/?>|\s|&nbsp;)+$/i, "").trim();
}

/** Tab and Shift+Tab: the next or previous cell, reading row by row; Tab past the last cell adds a row. */
function moveCellEdit(step) {
  const { target, id, cell, abort } = inlineEdit;
  const width = cell.rows[0].length;
  let index = cell.row * width + cell.column + step;
  if (index < 0) return;
  const table = target.closest("table");
  if (index >= cell.rows.length * width) {
    if (cell.rows.length >= MAX_ROWS) return;
    appendCellRow(table, cell.rows.length, width);
    cell.rows.push(Array(width).fill(""));
      }
  const row = Math.floor(index / width);
  const column = index % width;
  const next = table.querySelector(`[data-cell="${row}:${column}"]`);
  // Bound cells are edited in the Inspector; Tab passes over them.
  if (!next || typeof cell.rows[row][column] !== "string") return;
  cell.rows[cell.row][cell.column] = cellHtml(target);
  // The old cell lets go first, so its blur does not end editing.
  abort.abort();
  target.removeAttribute("contenteditable");
  target.removeAttribute("spellcheck");
  forEachNodeAround(target, (node) => node.setAttribute("draggable", "true"));
  inlineEdit = null;
  startInlineEdit(next, id, INLINE_TEXT.table, { ...cell, row, column });
  const selection = doc.getSelection();
  selection?.selectAllChildren(next);
  selection?.collapseToEnd();
}

/** A new empty row at the end of a rendered table, shaped like the last one. */
function appendCellRow(table, row, width) {
  const last = table.querySelector("tbody tr:last-child") ?? table.querySelector("tr:last-child");
  const body = table.tBodies[0] ?? table.appendChild(doc.createElement("tbody"));
  const tr = doc.createElement("tr");
  for (let column = 0; column < width; column++) {
    const model = last?.closest("tbody") ? last.children[column] : null;
    const cell = doc.createElement(model?.tagName.toLowerCase() === "th" ? "th" : "td");
    for (const name of ["scope", "data-label"]) if (model?.hasAttribute(name)) cell.setAttribute(name, model.getAttribute(name));
    cell.dataset.cell = `${row}:${column}`;
    tr.append(cell);
  }
  body.append(tr);
}

function finishInlineEdit(commit) {
  if (!inlineEdit) return;
  const { target, id, key, rich, cell, original, abort } = inlineEdit;
  inlineEdit = null;
  abort.abort();
  target.removeAttribute("contenteditable");
  target.removeAttribute("spellcheck");
  forEachNodeAround(target, (node) => node.setAttribute("draggable", "true"));
  if (target.isConnected) target.blur();

  const node = findNode(builder.nodes.value, id);
  if (cell) {
    // Escape undoes only the cell being edited; cells already left keep their text.
    if (commit && !cell.pasted) cell.rows[cell.row][cell.column] = cellHtml(target);
    else if (!commit) target.innerHTML = original;
    if (node && JSON.stringify(cell.rows) !== JSON.stringify(builder.valueFor(node, "props", key))) builder.updateValue(id, "props", key, cell.rows);
    return;
  }
  const def = node && builder.spec.value?.kinds?.[node.kind]?.props?.[key];
  const value = rich ? target.innerHTML.trim() : target.textContent.replace(/\s+/g, " ").trim().slice(0, def?.max ?? Infinity);
  // A heading or button without text would vanish from the page, so empty text is undone like Escape.
  if (!commit || !node || target.innerHTML === original || (!rich && value === "")) {
    target.innerHTML = original;
    return;
  }
  if (value !== builder.valueFor(node, "props", key)) builder.updateValue(id, "props", key, value);
}

function forEachNodeAround(target, callback) {
  for (let el = target.closest("[data-node]"); el; el = el.parentElement?.closest("[data-node]")) callback(el);
}

function templatePartAt(target) {
  const part = target.closest?.(".c-region:not([data-drop-root])");
  return part?.classList.contains("c-header") ? "header" : part?.classList.contains("c-footer") ? "footer" : null;
}

function onMouseOver(event) {
  if (builder.cssPicker.value) {
    showCssTarget(pickCssTarget(event.target));
    return;
  }
  hoveredTemplatePart = templatePartAt(event.target);
  applyTemplatePartHover();
  const el = event.target.closest?.("[data-node]");
  builder.hoverId.value = el?.dataset.node ?? null;
}

function onDragStart(event) {
  if (builder.cssPicker.value) { event.preventDefault(); return; }
  const el = event.target.closest?.(".cometcms-badge-label")
    ? element(builder.selectedId.value)
    : event.target.closest?.("[data-node]");
  if (!el) return;
  showResizeEdge(null);
  builder.drag.value = { source: "move", id: el.dataset.node };
  event.dataTransfer.effectAllowed = "move";
  event.dataTransfer.setData("text/plain", el.dataset.node);
}

/** Columns and column components sit side by side in their row. */
function isColumnElement(el) {
  return el.dataset?.kind === "column" || el.dataset?.componentRoot === "column";
}

function isHorizontalColumn(el) {
  const siblings = Array.from(el.parentElement?.children ?? []).filter((child) => child !== el && isColumnElement(child));
  const top = el.getBoundingClientRect().top;
  return siblings.some((sibling) => Math.abs(sibling.getBoundingClientRect().top - top) < 4);
}

/** The column whose left or right edge is next to the pointer, innermost first. */
function resizableEdgeAt(target, x) {
  for (let el = target?.closest?.("[data-node]"); el; el = el.parentElement?.closest("[data-node]")) {
    if (!isColumnElement(el) || !el.parentElement?.classList.contains("c-row")) continue;
    const node = findNode(builder.nodes.value, el.dataset.node);
    if (!node || builder.isCard(node)) continue;
    const rect = el.getBoundingClientRect();
    if (x - rect.left <= RESIZE_EDGE) return { el, side: "left" };
    if (rect.right - x <= RESIZE_EDGE) return { el, side: "right" };
  }
  return null;
}

function placeResizeHandle() {
  const { el, side } = resizeEdge;
  const win = doc.defaultView;
  const rect = el.getBoundingClientRect();
  const x = (side === "left" ? rect.left : rect.right) + win.scrollX;
  resizeHandle.style.setProperty("--builder-color", win.getComputedStyle(el).getPropertyValue("--builder-color"));
  Object.assign(resizeHandle.style, { left: `${x}px`, top: `${rect.top + win.scrollY}px`, height: `${rect.height}px` });
}

function showResizeEdge(edge) {
  if (!resizeHandle) return;
  if (edge?.el === resizeEdge?.el && edge?.side === resizeEdge?.side) return;
  resizeEdge?.el.removeAttribute("data-builder-resize");
  resizeEdge = edge;
  resizeHandle.hidden = !edge;
  if (!edge) return;
  edge.el.setAttribute("data-builder-resize", "");
  placeResizeHandle();
}

function onPointerMove(event) {
  if (builder.cssPicker.value) {
    showCssTarget(pickCssTarget(event.target));
    return;
  }
  if (resizing || moving || event.target === resizeHandle || moveHandle?.contains(event.target)) return;
  showResizeEdge(builder.drag.value ? null : resizableEdgeAt(event.target, event.clientX));
}

function contentWidth(el) {
  const style = doc.defaultView.getComputedStyle(el);
  return el.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0);
}

/** Computed percentage gaps remain expressions; resolve them against the row's content box. */
function columnGap(row) {
  const value = doc.defaultView.getComputedStyle(row).columnGap;
  if (value === "normal") return 0;
  if (/^[\d.]+px$/.test(value)) return parseFloat(value);
  const box = doc.createElement("div");
  const measure = doc.createElement("div");
  box.style.cssText = `all:initial;position:fixed;left:0;top:0;height:0;visibility:hidden;pointer-events:none;width:${contentWidth(row)}px`;
  measure.style.cssText = "all:initial;display:block;height:0";
  measure.style.width = value;
  box.append(measure);
  doc.body.append(box);
  const gap = measure.getBoundingClientRect().width;
  box.remove();
  return gap;
}

/**
 * The device whose span a column shows: by the screen width, or for a nested
 * row by the row's own width (see the grid in site.css).
 */
function spanDeviceOf(row) {
  if (row.classList.contains("c-row-nested")) return nestedDevice(contentWidth(row));
  const width = doc.defaultView.innerWidth;
  return width >= 1024 ? "desktop" : width >= 768 ? "tablet" : "mobile";
}

function nestedDevice(width) {
  return width >= 640 ? "desktop" : width >= 480 ? "tablet" : "mobile";
}

/** Report the selected row's own width when it is nested, for the inspector's hint. */
function measureNestedRow() {
  const row = builder.columnRowFor(builder.selected.value);
  const el = row ? element(row.id) : null;
  if (!el?.classList.contains("c-row-nested")) {
    builder.nestedRow.value = null;
    return;
  }
  const width = Math.round(contentWidth(el));
  builder.nestedRow.value = { id: row.id, width, device: nestedDevice(width) };
}

function onResizeStart(event) {
  if (!resizeEdge || event.button !== 0) return;
  event.preventDefault();
  event.stopPropagation();
  builder.closeContextMenu();

  const { el, side } = resizeEdge;
  const row = el.parentElement;
  const gap = columnGap(row);
  const positioned = ["absolute", "fixed"].includes(doc.defaultView.getComputedStyle(el).position);
  // Positioned columns use a fraction of the pin area; grid columns include the row's gap.
  const unit = positioned ? positioningBox(el).width / 12 : (contentWidth(row) + gap) / 12;
  if (unit <= 0) return;
  const start = Math.min(12, Math.max(1, Math.round((el.getBoundingClientRect().width + (positioned ? 0 : gap)) / unit)));

  resizing = { el, id: el.dataset.node, side, startX: event.clientX, start, span: start, unit, device: spanDeviceOf(row) };
  resizeHandle.setPointerCapture(event.pointerId);
  doc.documentElement.classList.add("cometcms-resizing");
  resizeLabel.textContent = t("site.builder.spanOf", { span: start });
  resizeLabel.hidden = false;

  selectedFromCanvas = true;
  builder.select(resizing.id);
}

function onResizeMove(event) {
  if (!resizing) return;
  const { el, side, startX, start, unit } = resizing;
  const distance = (event.clientX - startX) * (side === "left" ? -1 : 1);
  const span = Math.min(12, Math.max(1, start + Math.round(distance / unit)));
  if (span === resizing.span) return;

  // Preview the width right away; the saved span re-renders the page.
  resizing.span = span;
  el.style.gridColumn = `span ${span}`;
  el.style.setProperty("--c-span", String(span));
  resizeLabel.textContent = t("site.builder.spanOf", { span });
  placeResizeHandle();
  applySelection(false);
}

function onResizeEnd(event) {
  if (!resizing) return;
  const { el, id, start, span, device } = resizing;
  resizing = null;
  if (resizeHandle.hasPointerCapture(event.pointerId)) resizeHandle.releasePointerCapture(event.pointerId);
  doc.documentElement.classList.remove("cometcms-resizing");
  resizeLabel.hidden = true;

  if (span === start) {
    el.style.gridColumn = "";
    el.style.removeProperty("--c-span");
    return;
  }
  const node = findNode(builder.nodes.value, id);
  if (node) builder.updateValue(id, "props", "span", { ...(node.props?.span ?? {}), [device]: span });
}

/** Whether the node is out of the flow on the previewed device, so the move grip applies. */
function isPositioned(node) {
  return !!node && ["absolute", "fixed"].includes(builder.viewValue(node, "style", "position"));
}

/** The pin area in viewport coordinates, independent of the document's scroll position. */
function positioningBox(el) {
  if (doc.defaultView.getComputedStyle(el).position === "fixed") {
    return { left: 0, top: 0, width: doc.documentElement.clientWidth, height: doc.documentElement.clientHeight };
  }
  const parent = el.offsetParent ?? doc.body;
  const rect = parent.getBoundingClientRect();
  return { left: rect.left + parent.clientLeft, top: rect.top + parent.clientTop, width: parent.clientWidth, height: parent.clientHeight };
}

/**
 * How far the element may move each way and stay inside the area it is
 * pinned to; an element already sticking out may still move back in.
 */
function moveBounds(el) {
  const { left, top, width, height } = positioningBox(el);
  const inner = el.getBoundingClientRect();
  const right = left + width;
  const bottom = top + height;
  return {
    minX: Math.min(0, left - inner.left),
    maxX: Math.max(0, right - inner.right),
    minY: Math.min(0, top - inner.top),
    maxY: Math.max(0, bottom - inner.bottom),
  };
}

/** The move grip sits on the selected element's top right corner, inside it without room above. */
function placeMoveHandle(el, rect) {
  const node = findNode(builder.nodes.value, el.dataset.node);
  moveHandle.hidden = !isPositioned(node) || builder.translating.value || builder.styleState.value !== "normal";
  if (moveHandle.hidden) return;
  const win = doc.defaultView;
  const top = rect.top + win.scrollY;
  moveHandle.style.setProperty("--builder-color", win.getComputedStyle(el).getPropertyValue("--builder-color"));
  moveHandle.style.left = `${rect.right + win.scrollX}px`;
  moveHandle.classList.toggle("is-inside", top < moveHandle.offsetHeight);
  moveHandle.style.top = `${Math.max(0, top)}px`;
}

function onMoveStart(event) {
  const el = element(builder.selectedId.value);
  const node = el && findNode(builder.nodes.value, el.dataset.node);
  if (!isPositioned(node) || event.button !== 0) return;
  event.preventDefault();
  event.stopPropagation();
  builder.closeContextMenu();

  const offset = (key) => Number(builder.viewValue(node, "style", key)) || 0;
  const x = offset("offset_x");
  const y = offset("offset_y");
  moving = { el, id: node.id, startX: event.clientX, startY: event.clientY, x, y, startOffsetX: x, startOffsetY: y, bounds: moveBounds(el), unit: offsetUnit(el) };
  moveHandle.setPointerCapture(event.pointerId);
  doc.documentElement.classList.add("cometcms-moving");
  moveLabel.textContent = moveText(x, y);
  moveLabel.hidden = false;
}

function onMoveMove(event) {
  if (!moving) return;
  const { minX, maxX, minY, maxY } = moving.bounds;
  let dx = Math.max(minX, Math.min(maxX, event.clientX - moving.startX));
  let dy = Math.max(minY, Math.min(maxY, event.clientY - moving.startY));
  // Shift keeps the direction that moved the most.
  if (event.shiftKey) {
    if (Math.abs(dx) > Math.abs(dy)) dy = 0;
    else dx = 0;
  }
  moving.x = clampOffset(moving.startOffsetX + dx / moving.unit);
  moving.y = clampOffset(moving.startOffsetY + dy / moving.unit);

  // Preview right away; the saved offsets re-render the page (see the positioning rules in site.css).
  moving.el.style.setProperty("--c-x", String(moving.x));
  moving.el.style.setProperty("--c-y", String(moving.y));
  moveLabel.textContent = moveText(moving.x, moving.y);
  applySelection(false);
}

function onMoveEnd(event) {
  if (!moving) return;
  const { el, id, x, y, startOffsetX, startOffsetY, unit } = moving;
  moving = null;
  if (moveHandle.hasPointerCapture(event.pointerId)) moveHandle.releasePointerCapture(event.pointerId);
  doc.documentElement.classList.remove("cometcms-moving");
  moveLabel.hidden = true;

  if (x === startOffsetX && y === startOffsetY) {
    el.style.removeProperty("--c-x");
    el.style.removeProperty("--c-y");
    return;
  }
  // Measured where the preview left it, before the re-render.
  const values = nearestPin(el, unit);
  el.style.removeProperty("--c-x");
  el.style.removeProperty("--c-y");
  builder.editValues(id, "style", values);
}

/** Pixels per offset step: a tenth of a percent of the page width (see site.css). */
function offsetUnit(el) {
  const style = doc.defaultView.getComputedStyle(el);
  const width = style.position === "fixed" ? doc.defaultView.innerWidth : parseFloat(style.getPropertyValue("--c-sw")) || (el.offsetParent ?? doc.body).clientWidth;
  return width / 1000 || 1;
}

function clampOffset(value) {
  return Math.max(-1000, Math.min(1000, Math.round(value)));
}

function moveText(x, y) {
  return t("site.builder.moveBy", { x: (x / 10).toFixed(1), y: (y / 10).toFixed(1) });
}

const PIN_NAMES = [["top-left", "top", "top-right"], ["left", "center", "right"], ["bottom-left", "bottom", "bottom-right"]];

/**
 * The pin point nearest to where the element was dropped, with the offsets
 * that keep it there: an element near the right edge stays with that edge
 * as the section or viewport narrows.
 */
function nearestPin(el, unit) {
  const { left, top, width, height } = positioningBox(el);
  const rect = el.getBoundingClientRect();
  const third = (position, size) => (position < size / 3 ? 0 : position > (size * 2) / 3 ? 2 : 1);
  const column = third(rect.left + rect.width / 2 - left, width);
  const row = third(rect.top + rect.height / 2 - top, height);
  // The element and its pin area share an anchor; the offset is the rest.
  const ax = column / 2;
  const ay = row / 2;
  return {
    pin: PIN_NAMES[row][column],
    offset_x: clampOffset((rect.left - left - ax * (width - rect.width)) / unit),
    offset_y: clampOffset((rect.top - top - ay * (height - rect.height)) / unit),
  };
}

/** The node under the pointer and where a drop would go relative to it. */
function computeTarget(event) {
  const drag = builder.drag.value;
  let el = event.target.closest?.("[data-node]");

  // Never drop a node into itself.
  if (el && drag?.source === "move") {
    const dragged = element(drag.id);
    if (dragged && (dragged === el || dragged.contains(el))) return null;
  }

  if (!el) {
    const root = event.target.closest?.("[data-drop-root]");
    return root ? { id: null, position: "inside", rect: root.getBoundingClientRect(), horizontal: false } : null;
  }

  const kind = el.dataset.kind;
  // Component instances are one unit: drops go before or after them.
  const container = isStructure(kind) || builder.isDropContainer(findNode(builder.nodes.value, el.dataset.node));
  const rect = el.getBoundingClientRect();
  // Columns side by side, or elements in a column that lays them out in a row.
  const horizontal = isColumnElement(el) ? isHorizontalColumn(el) : !!el.parentElement?.classList.contains("c-flow-row");
  const ratio = horizontal ? (event.clientX - rect.left) / rect.width : (event.clientY - rect.top) / rect.height;
  const position = container
    ? ratio < 0.2 ? "before" : ratio > 0.8 ? "after" : "inside"
    : ratio < 0.5 ? "before" : "after";

  return { id: el.dataset.node, position, rect, horizontal };
}

function showIndicator(target) {
  if (!target) {
    indicator.hidden = true;
    return;
  }

  const win = doc.defaultView;
  const { rect, position, horizontal } = target;
  const el = element(target.id);
  indicator.style.setProperty("--builder-color", el ? win.getComputedStyle(el).getPropertyValue("--builder-color") : "#2563eb");
  const x = rect.left + win.scrollX;
  const y = rect.top + win.scrollY;
  indicator.classList.toggle("is-box", position === "inside");

  if (position === "inside") {
    Object.assign(indicator.style, { left: `${x}px`, top: `${y}px`, width: `${rect.width}px`, height: `${rect.height}px` });
  } else if (horizontal) {
    const left = position === "before" ? x - 2 : x + rect.width - 2;
    Object.assign(indicator.style, { left: `${left}px`, top: `${y}px`, width: "4px", height: `${rect.height}px` });
  } else {
    const top = position === "before" ? y - 2 : y + rect.height - 2;
    Object.assign(indicator.style, { left: `${x}px`, top: `${top}px`, width: `${rect.width}px`, height: "4px" });
  }

  indicator.hidden = false;
}

function onDragOver(event) {
  if (!builder.drag.value) return;
  dropTargetState = computeTarget(event);
  if (!dropTargetState) {
    showIndicator(null);
    return;
  }
  event.preventDefault();
  event.dataTransfer.dropEffect = builder.drag.value.source === "move" ? "move" : "copy";
  showIndicator(dropTargetState);
}

function onDragLeave(event) {
  if (!event.relatedTarget) showIndicator(null);
}

function onDrop(event) {
  event.preventDefault();
  showIndicator(null);
  const target = dropTargetState;
  dropTargetState = null;

  if (target) {
    builder.dropAt(target.id, target.position);
  } else {
    builder.drag.value = null;
  }
}

function onDragEnd() {
  showIndicator(null);
  dropTargetState = null;
  setTimeout(() => {
    builder.drag.value = null;
  }, 0);
}

watch(
  () => builder.selectedId.value,
  () => {
    applySelection(!selectedFromCanvas);
    applyHover();
    selectedFromCanvas = false;
  },
);
watch(() => builder.hoverId.value, applyHover);
watch(() => builder.selectedCell?.value, () => applySelection(false));
watch(() => builder.cssPicker.value, (picker) => {
  if (picker) {
    finishInlineEdit(true);
    builder.closeContextMenu();
    builder.hoverId.value = null;
    showResizeEdge(null);
    hoveredTemplatePart = null;
    applyTemplatePartHover();
  }
  doc?.documentElement.classList?.toggle("cometcms-css-picking", !!picker);
  showCssTarget(null);
});
watch([() => builder.styleState.value, () => builder.selectedId.value], applyStatePreview);
watch([() => builder.device.value, () => builder.styleState.value], () => applySelection(false));
watch(() => props.selectedTemplatePart, applyTemplatePartHover);
// Labels follow the arrangement (Column ↔ Row) before the preview re-renders.
watch(() => builder.nodeLabel(builder.selected.value), () => applySelection(false));

onBeforeUnmount(() => {
  finishInlineEdit(true);
  doc = null;
});

defineExpose({ refresh: () => frameRef.value?.refresh(), positionFor });
</script>
