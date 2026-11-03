// Runs once for every Stats block on a published page. `element` is the block's root
// element, `props` holds its values, the same ones the template received, and `ctx`
// the page context. Returning a cleanup lets the builder remount the block in place.
export default function mount(element, props, ctx = {}) {
  const numbers = [...element.querySelectorAll('[data-value]')];
  const reduced = ctx.reducedMotion ?? window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const frames = new Map();
  let observer = null;
  if (!reduced && props.duration > 0) {
    observer = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        observer.unobserve(entry.target);
        countUp(entry.target, Number(entry.target.dataset.value), props.duration, frames);
      }
    });
    numbers.forEach((number) => observer.observe(number));
  }
  element.dataset.mounted = 'true';
  return () => {
    observer?.disconnect();
    frames.forEach((frame) => cancelAnimationFrame(frame));
  };
}

function countUp(node, target, duration, frames) {
  const start = performance.now();
  const step = (now) => {
    const progress = Math.min(1, (now - start) / duration);
    // Ease out, so the count slows down as it reaches the final number.
    node.textContent = String(Math.round(target * (1 - (1 - progress) ** 3)));
    if (progress < 1) frames.set(node, requestAnimationFrame(step));
    else frames.delete(node);
  };
  frames.set(node, requestAnimationFrame(step));
}
