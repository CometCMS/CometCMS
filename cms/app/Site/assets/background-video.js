/**
 * Background videos play muted in a loop while they are on screen. Offscreen
 * videos pause, and videos a device hides (see site.css) never load. Visitors
 * who prefer reduced motion see the poster, or else the first frame.
 */
export function initBackgroundVideos(doc) {
  const win = doc.defaultView;
  const videos = [...doc.querySelectorAll('video[data-background-video]')];
  if (videos.length === 0) return;
  const reduce = win.matchMedia('(prefers-reduced-motion: reduce)');
  const visible = new Set();
  const update = (video) => {
    if (!visible.has(video) || win.getComputedStyle(video).display === 'none') {
      video.pause();
    } else if (reduce.matches) {
      video.pause();
      if (!video.poster && video.preload === 'none') video.preload = 'metadata';
    } else {
      video.play().catch(() => {});
    }
  };
  // The layer is watched rather than the video: a fixed video always covers the viewport.
  const observer = new win.IntersectionObserver((entries) => {
    for (const entry of entries) {
      for (const video of entry.target.querySelectorAll('video[data-background-video]')) {
        if (entry.isIntersecting) visible.add(video);
        else visible.delete(video);
        update(video);
      }
    }
  }, { rootMargin: '100px 0px' });
  const layers = new Set();
  for (const video of videos) {
    video.muted = true;
    layers.add(video.parentElement);
  }
  layers.forEach((layer) => observer.observe(layer));
  const updateAll = () => videos.forEach(update);
  reduce.addEventListener('change', updateAll);
  // Tablet and mobile widths, as in site.css: another device may show another video.
  for (const query of ['(max-width: 1023px)', '(max-width: 767px)']) win.matchMedia(query).addEventListener('change', updateAll);
}
