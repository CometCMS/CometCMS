// Coordinates stay local: only the visible raster tiles are requested, with normal browser caching.
export function parseLocation(value) {
  const match = /^\s*([+-]?\d+(?:\.\d+)?)\s*,\s*([+-]?\d+(?:\.\d+)?)\s*$/.exec(String(value ?? ''));
  if (!match) return null;
  const latitude = Number(match[1]);
  const longitude = Number(match[2]);
  return Math.abs(latitude) <= 85.05112878 && Math.abs(longitude) <= 180 ? { latitude, longitude } : null;
}

// Web Mercator cannot show the poles; tile X wraps across the date line.
export function tilePosition({ latitude, longitude }, zoom) {
  const count = 2 ** zoom;
  const radians = latitude * Math.PI / 180;
  return {
    x: (longitude + 180) / 360 * count,
    y: Math.max(0, Math.min(count - Number.EPSILON, (1 - Math.asinh(Math.tan(radians)) / Math.PI) / 2 * count)),
  };
}

export default function mount(element, props) {
  const root = element.querySelector('.cometcms-map');
  const viewport = root.querySelector('[data-viewport]');
  const tiles = root.querySelector('[data-tiles]');
  const placeholder = root.querySelector('[data-placeholder]');
  const pin = root.querySelector('[data-pin]');
  const open = root.querySelector('[data-open]');
  const zoomIn = root.querySelector('[data-zoom-in]');
  const zoomOut = root.querySelector('[data-zoom-out]');
  const status = root.querySelector('[data-status]');
  const location = parseLocation(props.location);
  if (!location) {
    placeholder.textContent = props.invalid_label;
    open.hidden = true;
    element.dataset.mapState = 'invalid';
    return;
  }
  let zoom = Math.max(1, Math.min(19, Math.round(Number(props.zoom) || 13)));
  let visible = false;
  let generation = 0;
  let frame;

  function render() {
    if (!element.isConnected) { resize.disconnect(); visibility.disconnect(); return; }
    status.textContent = `${props.zoom_label} ${zoom}`;
    zoomIn.disabled = zoom === 19;
    zoomOut.disabled = zoom === 1;
    open.href = `https://www.openstreetmap.org/?mlat=${location.latitude}&mlon=${location.longitude}#map=${zoom}/${location.latitude}/${location.longitude}`;
    if (!visible) return;
    const width = viewport.clientWidth;
    const height = viewport.clientHeight;
    if (!width || !height) return;
    const center = tilePosition(location, zoom);
    const left = center.x - width / 512;
    const top = center.y - height / 512;
    const count = 2 ** zoom;
    const version = ++generation;
    let loaded = 0;
    let failed = 0;
    const images = [];
    placeholder.hidden = false;
    placeholder.textContent = props.loading_label;
    pin.hidden = true;
    element.dataset.mapState = 'loading';
    for (let y = Math.max(0, Math.floor(top)); y < Math.min(count, Math.ceil(top + height / 256)); y++) {
      for (let x = Math.floor(left); x < Math.ceil(left + width / 256); x++) {
        const image = document.createElement('img');
        image.alt = '';
        image.draggable = false;
        image.decoding = 'async';
        // Send an origin referrer, as required by the tile service, even on stricter sites.
        image.referrerPolicy = 'strict-origin-when-cross-origin';
        image.style.left = `${(x - left) * 256}px`;
        image.style.top = `${(y - top) * 256}px`;
        image.addEventListener('load', () => {
          if (version !== generation) return;
          loaded++;
          placeholder.hidden = true;
          pin.hidden = false;
          element.dataset.mapState = 'ready';
        });
        image.addEventListener('error', () => {
          if (version !== generation) return;
          image.hidden = true;
          failed++;
          if (!loaded && failed === images.length) {
            placeholder.textContent = props.error_label;
            element.dataset.mapState = 'error';
          }
        });
        image.src = `https://tile.openstreetmap.org/${zoom}/${((x % count) + count) % count}/${y}.png`;
        images.push(image);
      }
    }
    tiles.replaceChildren(...images);
  }

  function schedule() {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(render);
  }
  zoomIn.addEventListener('click', () => { zoom = Math.min(19, zoom + 1); schedule(); });
  zoomOut.addEventListener('click', () => { zoom = Math.max(1, zoom - 1); schedule(); });
  const resize = new ResizeObserver(schedule);
  const visibility = new IntersectionObserver(([entry]) => {
    visible = entry.isIntersecting;
    if (visible) schedule();
  });
  resize.observe(viewport);
  visibility.observe(viewport);
  root.querySelector('[data-controls]').hidden = false;
  element.dataset.mounted = 'true';
  render();
}
