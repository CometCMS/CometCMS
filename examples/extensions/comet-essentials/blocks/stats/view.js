// Runs once for every Stats block on a published page. `element` is the block's root
// element and `props` holds its values, the same ones the template received.
export default function mount(element, props) {
  const numbers = [...element.querySelectorAll('[data-value]')];
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduced && props.duration > 0) {
    const observer = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        observer.unobserve(entry.target);
        countUp(entry.target, Number(entry.target.dataset.value), props.duration);
      }
    });
    numbers.forEach((number) => observer.observe(number));
  }
  element.dataset.mounted = 'true';
}

function countUp(node, target, duration) {
  const start = performance.now();
  const step = (now) => {
    const progress = Math.min(1, (now - start) / duration);
    // Ease out, so the count slows down as it reaches the final number.
    node.textContent = String(Math.round(target * (1 - (1 - progress) ** 3)));
    if (progress < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}
