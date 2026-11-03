export default function mount(element, props) {
  const root = element.querySelector('.cometcms-logo-bar');
  const track = root.querySelector('.cometcms-logo-bar__track');
  const group = track.firstElementChild;
  const items = [...group.children];
  const number = (value, fallback, min, max) => Math.max(min, Math.min(max, Number(value) || fallback));
  root.style.setProperty('--logo-height', `${number(props.height, 64, 24, 160)}px`);
  root.style.setProperty('--logo-gap', `${number(props.gap, 48, 8, 160)}px`);
  root.dataset.direction = props.direction === 'right' ? 'right' : 'left';
  root.toggleAttribute('data-pause-hover', !!props.pause_on_hover);

  function copy(node) {
    const clone = node.cloneNode(true);
    clone.setAttribute('aria-hidden', 'true');
    clone.setAttribute('inert', '');
    clone.setAttribute('data-logo-copy', '');
    return clone;
  }

  function resize() {
    root.removeAttribute('data-running');
    track.querySelectorAll('[data-logo-copy]').forEach((node) => node.remove());
    if (!items.length || !root.clientWidth) return;
    // Fill one whole viewport before duplicating the group for a seamless loop.
    const width = group.getBoundingClientRect().width;
    if (!width) return;
    const repeats = Math.ceil(root.clientWidth / width);
    for (let index = 1; index < repeats; index++) items.forEach((item) => group.append(copy(item)));
    track.append(copy(group));
    root.style.setProperty('--logo-duration', `${group.getBoundingClientRect().width / number(props.speed, 40, 10, 200)}s`);
    root.setAttribute('data-running', '');
  }

  // Reveal focused links even when they would otherwise be outside the viewport.
  root.addEventListener('focusin', (event) => {
    const item = event.target.closest('.cometcms-logo-bar__item');
    if (item) root.scrollLeft = Math.max(0, item.offsetLeft - root.offsetLeft);
  });
  root.addEventListener('focusout', (event) => {
    if (!root.contains(event.relatedTarget)) root.scrollLeft = 0;
  });
  new ResizeObserver(resize).observe(root);
  resize();
  element.dataset.mounted = 'true';
}
