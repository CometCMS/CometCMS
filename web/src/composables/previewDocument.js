/** Include editor styles before the document paints, avoiding late outline transitions. */
export function withPreviewStyles(html, css) {
  if (!html || !css) return html;
  return html.replace(/<\/head\s*>/i, () => `<style data-preview-styles>${css}</style></head>`);
}

/** Elements the editor adds to the preview that an update leaves in place, such as overlays. */
export const PREVIEW_KEEP = "data-preview-keep";

/** Scripted blocks need a fresh realm so their DOM, listeners and timers reset together. */
export function needsPreviewReload(doc, html) {
  return !!doc.querySelector('[data-comet-block]')
    || html.includes('<script type="module">import mount from ');
}

/**
 * Update a loaded preview document to a newly rendered one without reloading it.
 * Unchanged elements stay, so the page neither blanks nor loses its scroll
 * position, and images keep their decoded pixels. Scripted block previews
 * reload instead: imported script elements do not execute during a DOM patch.
 */
export function patchDocument(doc, html) {
  const next = new DOMParser().parseFromString(html, "text/html");
  syncAttributes(doc.documentElement, next.documentElement);
  morphChildren(doc.head, next.head);
  syncAttributes(doc.body, next.body);
  morphChildren(doc.body, next.body);
}

function kept(node) {
  return node.nodeType === 1 && node.hasAttribute(PREVIEW_KEEP);
}

/** Builder nodes are matched by id, so moving one keeps its element. */
function keyOf(node) {
  return node.nodeType === 1 ? node.getAttribute("data-node") : null;
}

function sameKind(node, target) {
  return node.nodeType === target.nodeType && node.nodeName === target.nodeName;
}

function syncAttributes(el, target) {
  for (const { name } of Array.from(el.attributes)) {
    if (!target.hasAttribute(name) && name !== PREVIEW_KEEP) el.removeAttribute(name);
  }
  for (const { name, value } of Array.from(target.attributes)) {
    if (el.getAttribute(name) !== value) el.setAttribute(name, value);
  }
}

function morph(node, target) {
  if (node.nodeType !== 1) {
    if (node.nodeValue !== target.nodeValue) node.nodeValue = target.nodeValue;
    return;
  }
  syncAttributes(node, target);
  morphChildren(node, target);
}

function morphChildren(parent, next) {
  const keyed = new Map();
  for (const child of parent.childNodes) {
    const key = keyOf(child);
    if (key) keyed.set(key, child);
  }

  let cursor = parent.firstChild;
  const skipKept = () => {
    while (cursor && kept(cursor)) cursor = cursor.nextSibling;
  };

  for (const target of Array.from(next.childNodes)) {
    skipKept();
    const key = keyOf(target);
    let match = null;
    if (key) {
      match = keyed.get(key) ?? null;
      keyed.delete(key);
    } else if (cursor && !keyOf(cursor) && sameKind(cursor, target)) {
      match = cursor;
    }
    if (match && !sameKind(match, target)) match = null;

    if (!match) {
      parent.insertBefore(parent.ownerDocument.importNode(target, true), cursor);
      continue;
    }
    if (match === cursor) cursor = cursor.nextSibling;
    else parent.insertBefore(match, cursor);
    morph(match, target);
  }

  while (cursor) {
    const following = cursor.nextSibling;
    if (!kept(cursor)) cursor.remove();
    cursor = following;
  }
}
