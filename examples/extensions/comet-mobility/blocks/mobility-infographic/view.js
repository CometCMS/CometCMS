// Each instance owns its controls and listeners, including in builder previews.
export default function mount(element, props, ctx = {}) {
  const root = element.querySelector('.vi-mobility');
  if (!root) return () => {};
  const controller = new AbortController();
  const options = { signal: controller.signal };
  const buttons = [...root.querySelectorAll('[data-mode]')];
  const panels = [...root.querySelectorAll('[data-panel]')];
  const controls = root.querySelector('[data-controls]');
  const details = root.querySelector('[data-details]');
  const close = root.querySelector('[data-close]');
  const status = root.querySelector('[data-status]');
  const instanceId = globalThis.crypto?.randomUUID?.() || `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  const prefix = `vi-mobility-${instanceId}`;
  let pinned = null;
  let transient = null;
  let closedByPointer = false;
  let restoringFocus = false;
  let pointerAnchor = null;
  let dismissTimer;

  function cancelDismiss() {
    clearTimeout(dismissTimer);
  }

  function rememberPointer(event, button) {
    if (event.pointerType === 'touch') return;
    const bounds = button.getBoundingClientRect();
    pointerAnchor = { mode: button.dataset.mode, x: (event.clientX - bounds.left) / bounds.width, y: (event.clientY - bounds.top) / bounds.height };
  }

  function positionPopover() {
    const active = pinned || transient;
    const button = buttons.find((item) => item.dataset.mode === active);
    if (!button || details.hidden) return;
    const bounds = button.getBoundingClientRect();
    const pointer = pointerAnchor?.mode === active ? pointerAnchor : null;
    const x = bounds.left + bounds.width * (pointer?.x ?? .5);
    const y = bounds.top + bounds.height * (pointer?.y ?? .75);
    const viewport = element.ownerDocument.defaultView;
    const margin = 12;
    const gap = 16;
    const card = details.getBoundingClientRect();
    const left = Math.max(margin, Math.min(x + gap, viewport.innerWidth - card.width - margin));
    const below = y + gap;
    const top = below + card.height <= viewport.innerHeight - margin
      ? below
      : Math.max(margin, Math.min(y - gap - card.height, viewport.innerHeight - card.height - margin));
    details.style.left = `${left}px`;
    details.style.top = `${top}px`;
  }

  function render(announce = false) {
    const active = pinned || transient;
    for (const button of buttons) {
      const selected = button.dataset.mode === active;
      button.setAttribute('aria-pressed', String(selected));
      button.setAttribute('aria-expanded', String(selected));
    }
    for (const panel of panels) panel.hidden = panel.dataset.panel !== active;
    close.hidden = !active;
    details.hidden = !active;
    if (active) {
      if (details.showPopover && !details.matches(':popover-open')) details.showPopover();
      positionPopover();
    } else if (details.hidePopover && details.matches(':popover-open')) details.hidePopover();
    if (announce) status.textContent = panels.find((panel) => panel.dataset.panel === active)?.textContent.trim() || '';
  }

  buttons.forEach((button, index) => {
    const mode = button.dataset.mode;
    button.id = `${prefix}-button-${index}`;
    const panel = panels.find((item) => item.dataset.panel === mode);
    panel.id = `${prefix}-panel-${index}`;
    button.setAttribute('aria-controls', panel.id);
    panel.setAttribute('aria-labelledby', button.id);
    button.addEventListener('pointerenter', (event) => {
      cancelDismiss();
      if (event.pointerType === 'touch' || closedByPointer) return;
      if (!pinned) rememberPointer(event, button);
      transient = mode;
      render();
    }, options);
    button.addEventListener('pointermove', (event) => {
      if (event.pointerType === 'touch' || pinned || closedByPointer) return;
      rememberPointer(event, button);
      positionPopover();
    }, options);
    button.addEventListener('pointerdown', (event) => {
      if (event.pointerType === 'touch') pointerAnchor = null;
      else rememberPointer(event, button);
    }, options);
    button.addEventListener('pointerleave', () => {
      closedByPointer = false;
    }, options);
    button.addEventListener('focus', () => {
      if (restoringFocus) return;
      closedByPointer = false;
      if (button.matches(':focus-visible')) pointerAnchor = null;
      transient = mode;
      render(true);
    }, options);
    button.addEventListener('blur', (event) => {
      if (root.contains(event.relatedTarget)) return;
      transient = null;
      render();
    }, options);
    button.addEventListener('click', () => {
      pinned = pinned === mode ? null : mode;
      transient = null;
      closedByPointer = !pinned;
      render(true);
    }, options);
    button.addEventListener('keydown', (event) => {
      const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
      if (step) {
        event.preventDefault();
        buttons[(index + step + buttons.length) % buttons.length].focus();
      }
    }, options);
  });

  function clear(restoreFocus = false) {
    cancelDismiss();
    const active = pinned || transient;
    pinned = null;
    transient = null;
    closedByPointer = root.matches(':hover');
    if (restoreFocus) {
      restoringFocus = true;
      buttons.find((button) => button.dataset.mode === active)?.focus({ preventScroll: true });
      restoringFocus = false;
    }
    render();
    status.textContent = '';
  }
  close.addEventListener('click', () => clear(true), options);
  root.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && (pinned || transient)) {
      event.preventDefault();
      event.stopPropagation();
      clear(close === document.activeElement);
    }
  }, options);
  element.ownerDocument.addEventListener('click', (event) => {
    if (!root.contains(event.target)) clear();
  }, options);
  root.addEventListener('pointerleave', () => {
    closedByPointer = false;
    if (root.contains(document.activeElement) && document.activeElement.matches(':focus-visible')) return;
    cancelDismiss();
    dismissTimer = setTimeout(() => {
      transient = null;
      render();
    }, 160);
  }, options);
  root.addEventListener('pointerenter', cancelDismiss, options);
  details.addEventListener('pointerenter', cancelDismiss, options);
  const viewport = element.ownerDocument.defaultView;
  viewport.addEventListener('resize', positionPopover, options);
  viewport.addEventListener('scroll', positionPopover, { ...options, capture: true, passive: true });

  const initial = buttons.find((button) => button.dataset.mode === props.initial_mode);
  pinned = initial?.dataset.mode || null;
  root.classList.add('vi-mobility--interactive');
  controls.hidden = false;
  details.setAttribute('role', 'region');
  details.setAttribute('aria-label', props.diagram_label);
  details.setAttribute('popover', 'manual');
  render();
  if (ctx.signal?.aborted) controller.abort();
  else ctx.signal?.addEventListener('abort', () => controller.abort(), options);

  return () => {
    cancelDismiss();
    controller.abort();
    if (details.hidePopover && details.matches(':popover-open')) details.hidePopover();
    root.classList.remove('vi-mobility--interactive');
    controls.hidden = true;
    close.hidden = true;
    status.textContent = '';
    details.removeAttribute('role');
    details.removeAttribute('aria-label');
    details.removeAttribute('popover');
    details.hidden = false;
    details.style.removeProperty('left');
    details.style.removeProperty('top');
    for (const panel of panels) {
      panel.hidden = false;
      panel.removeAttribute('id');
      panel.removeAttribute('aria-labelledby');
    }
    for (const button of buttons) {
      for (const attribute of ['id', 'aria-controls', 'aria-pressed', 'aria-expanded']) button.removeAttribute(attribute);
    }
  };
}
