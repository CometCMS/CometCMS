/**
 * v-tooltip: the builder's dark hint, shown after a short hover or on keyboard
 * focus. It renders into the body with fixed positioning, so clipping parents
 * (truncated text, scrolling bars) never cut it off, and it stays inside the
 * viewport.
 *
 * Value: a string, or { title, text, placement: "bottom" | "right" }. A
 * falsy value turns the tooltip off. A press hides it until the pointer
 * leaves, so it never covers what the click opened.
 */
const DELAY = 500;
const GAP = 8;
const MARGIN = 8;

let tip = null;
let owner = null;
let timer = null;

function element() {
  if (tip) return tip;
  tip = document.createElement("div");
  tip.id = "app-tooltip";
  tip.className = "app-tooltip";
  tip.setAttribute("role", "tooltip");
  document.body.appendChild(tip);
  return tip;
}

function options(value) {
  if (!value) return null;
  return typeof value === "string" ? { text: value, placement: "bottom" } : { placement: "bottom", ...value };
}

function render(el) {
  const tooltip = element();
  const { title, text, placement } = el._tooltip.options;
  tooltip.replaceChildren();
  if (title) {
    const strong = document.createElement("span");
    strong.className = "app-tooltip-title";
    strong.textContent = title;
    tooltip.appendChild(strong);
  }
  if (text) {
    const body = document.createElement("span");
    body.className = title ? "app-tooltip-text" : "";
    body.textContent = text;
    tooltip.appendChild(body);
  }
  tooltip.dataset.placement = placement;
  tooltip.classList.add("is-measuring");
  const anchor = el.getBoundingClientRect();
  const box = tooltip.getBoundingClientRect();
  const width = window.innerWidth;
  const height = window.innerHeight;
  let left = placement === "right" ? anchor.right + GAP : anchor.left;
  let top = placement === "right" ? anchor.top + (anchor.height - box.height) / 2 : anchor.bottom + GAP;
  // Flip above when there is no room below.
  if (placement === "bottom" && top + box.height > height - MARGIN) top = anchor.top - GAP - box.height;
  left = Math.min(Math.max(MARGIN, left), width - box.width - MARGIN);
  top = Math.min(Math.max(MARGIN, top), height - box.height - MARGIN);
  tooltip.style.left = `${left}px`;
  tooltip.style.top = `${top}px`;
  tooltip.classList.remove("is-measuring");
  tooltip.classList.add("is-visible");
  el.setAttribute("aria-describedby", tooltip.id);
}

function show(el, immediate = false) {
  clearTimeout(timer);
  if (!el._tooltip.options || el._tooltip.suppressed) return;
  owner = el;
  timer = setTimeout(() => owner === el && render(el), immediate ? 0 : DELAY);
}

function hide(el) {
  clearTimeout(timer);
  if (owner !== el) return;
  owner = null;
  tip?.classList.remove("is-visible");
  el.removeAttribute("aria-describedby");
}

export const vTooltip = {
  mounted(el, binding) {
    const state = { options: options(binding.value), suppressed: false };
    state.enter = () => show(el);
    state.leave = () => { state.suppressed = false; hide(el); };
    state.press = () => { state.suppressed = true; hide(el); };
    state.focus = () => el.matches(":focus-visible") && show(el, true);
    state.blur = () => hide(el);
    state.key = (event) => event.key === "Escape" && hide(el);
    el._tooltip = state;
    el.addEventListener("pointerenter", state.enter);
    el.addEventListener("pointerleave", state.leave);
    el.addEventListener("pointerdown", state.press);
    el.addEventListener("focus", state.focus);
    el.addEventListener("blur", state.blur);
    el.addEventListener("keydown", state.key);
  },
  updated(el, binding) {
    el._tooltip.options = options(binding.value);
    if (owner !== el) return;
    if (!el._tooltip.options) hide(el);
    else if (tip?.classList.contains("is-visible")) render(el);
  },
  beforeUnmount(el) {
    const state = el._tooltip;
    hide(el);
    el.removeEventListener("pointerenter", state.enter);
    el.removeEventListener("pointerleave", state.leave);
    el.removeEventListener("pointerdown", state.press);
    el.removeEventListener("focus", state.focus);
    el.removeEventListener("blur", state.blur);
    el.removeEventListener("keydown", state.key);
  },
};
