// Each instance owns its navigation and timer.
export default function mount(element, props) {
  const root = element.querySelector('.cometcms-slideshow');
  const slides = [...root.querySelectorAll('[data-slide]')];
  const controls = root.querySelector('[data-controls]');
  const selectors = root.querySelector('[data-selectors]');
  const status = root.querySelector('[data-status]');
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const interval = Math.max(2000, Math.min(20000, Number(props.interval) || 5000));
  let current = 0;
  let rotating = !!props.autoplay && !motion.matches;
  let hovered = root.matches(':hover');
  let timer;

  const buttons = slides.map((slide, index) => {
    slide.setAttribute('aria-label', `${props.slide_label} ${index + 1} / ${slides.length}`);
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = String(index + 1);
    button.setAttribute('aria-label', `${props.slide_label} ${index + 1}`);
    button.addEventListener('click', () => navigate(index));
    selectors.append(button);
    return button;
  });

  function schedule() {
    clearTimeout(timer);
    const running = rotating && !(props.pause_on_hover && hovered) && !document.hidden && slides.length > 1;
    status.setAttribute('aria-live', running ? 'off' : 'polite');
    if (running && element.isConnected) {
      timer = setTimeout(() => show(current + 1), interval);
    }
  }

  function show(index) {
    if (!slides.length) return;
    current = (index + slides.length) % slides.length;
    slides.forEach((slide, position) => {
      slide.hidden = position !== current;
      buttons[position].setAttribute('aria-current', String(position === current));
    });
    status.textContent = `${current + 1} / ${slides.length}`;
    schedule();
  }

  function navigate(index) {
    rotating = false;
    show(index);
  }

  controls.hidden = slides.length < 2;
  root.querySelector('[data-previous]').addEventListener('click', () => navigate(current - 1));
  root.querySelector('[data-next]').addEventListener('click', () => navigate(current + 1));
  root.addEventListener('keydown', (event) => {
    if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    const index = { ArrowLeft: current - 1, ArrowRight: current + 1, Home: 0, End: slides.length - 1 }[event.key];
    if (index === undefined) return;
    event.preventDefault();
    // Move focus out of a slide before hiding it; control buttons keep their focus.
    if (event.target.closest('[data-slide]')) {
      buttons[(index + slides.length) % slides.length].focus();
    }
    navigate(index);
  });
  root.addEventListener('pointerenter', () => { hovered = true; schedule(); });
  root.addEventListener('pointerleave', () => { hovered = false; schedule(); });
  // Focus and manual navigation stop autoplay for this instance.
  root.addEventListener('focusin', () => { rotating = false; schedule(); });
  document.addEventListener('visibilitychange', schedule);
  motion.addEventListener('change', () => { if (motion.matches) rotating = false; schedule(); });
  show(0);
  element.dataset.mounted = 'true';
}
