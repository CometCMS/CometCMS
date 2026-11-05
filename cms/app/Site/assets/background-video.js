/**
 * Background videos play muted in a loop while they are on screen. Visitors
 * who prefer reduced motion see the first frame; offscreen videos pause.
 */
export function initBackgroundVideos(doc) {
  const win = doc.defaultView;
  const videos = [...doc.querySelectorAll('video[data-background-video]')];
  if (videos.length === 0) return;
  const reduce = win.matchMedia('(prefers-reduced-motion: reduce)');
  const visible = new Set();
  const update = (video) => {
    if (!reduce.matches && visible.has(video)) video.play().catch(() => {});
    else video.pause();
  };
  // The layer is watched rather than the video: a fixed video always covers the viewport.
  const observer = new win.IntersectionObserver((entries) => {
    for (const entry of entries) {
      const video = entry.target.querySelector('video[data-background-video]');
      if (entry.isIntersecting) visible.add(video);
      else visible.delete(video);
      update(video);
    }
  }, { rootMargin: '100px 0px' });
  for (const video of videos) {
    video.muted = true;
    observer.observe(video.parentElement);
  }
  reduce.addEventListener('change', () => videos.forEach(update));
}
