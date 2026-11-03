// Keep both images readable until they load; previews and pages without JS use the same fallback.
export default function mount(element, props) {
  const root = element.querySelector('.cometcms-compare');
  const frame = root.querySelector('[data-frame]');
  const control = root.querySelector('[data-control]');
  const divider = root.querySelector('[data-divider]');
  const label = document.createElement('label');
  const caption = document.createElement('span');
  caption.textContent = props.slider_label;
  const input = document.createElement('input');
  input.type = 'range';
  input.min = '0';
  input.max = '100';
  input.step = '1';
  input.dir = 'ltr';
  label.append(caption, input);
  control.append(label);
  const images = [...frame.querySelectorAll('img')];
  let pointer = null;

  function update(value) {
    const number = Number(value);
    const position = Number.isFinite(number) ? Math.max(0, Math.min(100, Math.round(number))) : 50;
    input.value = String(position);
    input.setAttribute('aria-valuetext', `${props.before_label} ${position}% / ${props.after_label} ${100 - position}%`);
    root.style.setProperty('--cometcms-compare-position', `${position}%`);
  }

  function ready() {
    const loaded = images.length === 2 && images.every((image) => image.complete && image.naturalWidth > 0);
    root.toggleAttribute('data-ready', loaded);
    control.hidden = divider.hidden = !loaded;
  }

  function move(event) {
    const bounds = frame.getBoundingClientRect();
    if (bounds.width > 0) update((event.clientX - bounds.left) / bounds.width * 100);
  }

  input.addEventListener('input', () => update(input.value));
  frame.addEventListener('pointerdown', (event) => {
    if (!root.hasAttribute('data-ready') || !event.isPrimary || event.button !== 0) return;
    pointer = event.pointerId;
    frame.setPointerCapture(pointer);
    input.focus({ preventScroll: true });
    move(event);
  });
  frame.addEventListener('pointermove', (event) => { if (event.pointerId === pointer) move(event); });
  frame.addEventListener('pointerup', (event) => {
    if (event.pointerId !== pointer) return;
    move(event);
    frame.releasePointerCapture(pointer);
    pointer = null;
  });
  frame.addEventListener('lostpointercapture', () => { pointer = null; });
  frame.addEventListener('pointercancel', () => { pointer = null; });
  images.forEach((image) => {
    image.draggable = false;
    image.addEventListener('load', ready);
    image.addEventListener('error', ready);
  });
  update(props.position);
  ready();
  element.dataset.mounted = 'true';
}
