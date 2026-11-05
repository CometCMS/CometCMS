/**
 * Water Ripple: a reactive dot lattice behind a section, row or column.
 *
 * Ripples expose horizontal tears, broken data columns and delayed flares.
 * `intensity` (0–100) scales the reaction so it can sit quietly behind content.
 * `drift` (0–100) rolls broad dithered bands across the lattice as its only
 * ambient motion. Without drift, a settled field holds its cached lattice and
 * stops animating; drift repaints at a reduced frame rate. Reduced motion holds
 * one still frame; hidden documents and layers scrolled out of view pause.
 *
 * The page mounts this on the background layer: mount(layer, props, ctx).
 * Colors arrive as theme values ({ _: "accent", css: "var(--c-accent)" }) and
 * are resolved again whenever the theme switches between light and dark.
 */

// ─── Ripple surface ──────────────────────────────────────────────────────────

const DAMPING = 0.968;
const DRAG_DECAY = 0.88;
const REST = 0.0015;
const MAX_DRAG = 1.5;

/**
 * A height field on a grid of cells, simulated with the two-buffer wave
 * scheme: each step a cell takes the mean of its neighbours minus its own
 * previous height, damped, so ripples spread one cell per step and fade.
 * dragX and dragY hold a decaying sideways push from pointer strokes.
 */
export function createRippleSurface(cols, rows) {
  const size = cols * rows;
  let current = new Float32Array(size);
  let previous = new Float32Array(size);
  const dragX = new Float32Array(size);
  const dragY = new Float32Array(size);

  /** Adds a soft round stamp of `amount` around (x, y), in cell units. */
  function splat(field, x, y, radius, amount) {
    const reach = Math.ceil(radius * 2);
    const left = Math.max(1, Math.floor(x - reach));
    const right = Math.min(cols - 2, Math.ceil(x + reach));
    const top = Math.max(1, Math.floor(y - reach));
    const bottom = Math.min(rows - 2, Math.ceil(y + reach));
    const spread = radius * radius;
    for (let r = top; r <= bottom; r++) {
      for (let c = left; c <= right; c++) {
        const distance = (c - x) ** 2 + (r - y) ** 2;
        if (distance > reach * reach) continue;
        field[r * cols + c] += amount * Math.exp(-distance / spread);
      }
    }
  }

  function clampDrag(field) {
    for (let i = 0; i < size; i++) {
      if (field[i] > MAX_DRAG) field[i] = MAX_DRAG;
      else if (field[i] < -MAX_DRAG) field[i] = -MAX_DRAG;
    }
  }

  return {
    cols,
    rows,
    dragX,
    dragY,
    /** The current heights; the buffer changes each step, so read it per frame. */
    get height() {
      return current;
    },
    /** A pointer passing over (x, y), moving by (moveX, moveY) cells. */
    press(x, y, moveX, moveY) {
      splat(current, x, y, 1.4, -0.16);
      splat(dragX, x, y, 1.8, moveX * 0.45);
      splat(dragY, x, y, 1.8, moveY * 0.45);
    },
    /** A tap at (x, y): one strong drop. */
    drop(x, y) {
      splat(current, x, y, 2.2, -1.1);
    },
    /** Advances one step; false once the surface has settled. */
    step() {
      let energy = 0;
      for (let r = 1; r < rows - 1; r++) {
        for (let c = 1; c < cols - 1; c++) {
          const i = r * cols + c;
          const next = ((current[i - 1] + current[i + 1] + current[i - cols] + current[i + cols]) * 0.5 - previous[i]) * DAMPING;
          previous[i] = next;
          const magnitude = next < 0 ? -next : next;
          if (magnitude > energy) energy = magnitude;
        }
      }
      [current, previous] = [previous, current];
      clampDrag(dragX);
      clampDrag(dragY);
      for (let i = 0; i < size; i++) {
        dragX[i] *= DRAG_DECAY;
        dragY[i] *= DRAG_DECAY;
        const push = Math.abs(dragX[i]) + Math.abs(dragY[i]);
        if (push > energy) energy = push;
      }
      return energy > REST;
    },
    reset() {
      current.fill(0);
      previous.fill(0);
      dragX.fill(0);
      dragY.fill(0);
    },
  };
}

// ─── Lattice ─────────────────────────────────────────────────────────────────

const CELL = { fine: 7, medium: 9, coarse: 13 };
const MAX_CELLS = 30000;
const ALPHA_LEVELS = 16;
// Only the very strongest fragments reach the paler highlight tint.
const HIGHLIGHT_LEVELS = 3;
const DOT_SIZE = 1.5;
// Pixels of dot shift per unit of surface slope.
const REFRACTION = 12;
const MAX_PULSES = 8;
const ECHO_INTERVAL_MS = 120;
// Each band re-rolls its tears on its own beat so the wall never jumps in lockstep.
const GLITCH_MS = 110;
// At low intensity the lattice dims less than the reaction, so it stays legible as texture.
const REST_FLOOR = 0.55;
// Drift patches span a couple hundred pixels and slowly morph as they wander.
const DRIFT_SCALE = 1 / 180;
// Noise units per millisecond.
const DRIFT_SPEED = 1 / 2500;
// Steepens the patch edges so the dither reads as shapes, not a flat wash.
const DRIFT_CONTRAST = 2.6;
// Dots outside a band fade to this share and dots inside lift by this much.
const DRIFT_FLOOR = 0.25;
const DRIFT_LIFT = 2.2;
// Drift changes slowly, so a settled field repaints at this rate.
const IDLE_FRAME_MS = 1000 / 30;
const STEP_MS = 1000 / 60;
const MAX_STEPS_PER_FRAME = 3;
// Clicks on controls belong to the control, not the water.
const INTERACTIVE = 'a, button, input, textarea, select, label, summary, [contenteditable], [role="button"], [role="link"], [role="menuitem"], [role="option"], [role="tab"], [role="switch"], [role="checkbox"]';

function noise(a, b = 0, c = 0) {
  const h = Math.sin(a * 127.1 + b * 311.7 + c * 74.7) * 43758.5453;
  return h - Math.floor(h);
}

function hash2(x, y) {
  let h = (Math.imul(x, 374761393) + Math.imul(y, 668265263)) | 0;
  h = Math.imul(h ^ (h >>> 13), 1274126177);
  return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
}

/** Smoothly interpolated 0–1 value noise; unlike sines it never repeats. */
function valueNoise(x, y) {
  const xi = Math.floor(x);
  const yi = Math.floor(y);
  const fx = x - xi;
  const fy = y - yi;
  const u = fx * fx * (3 - 2 * fx);
  const v = fy * fy * (3 - 2 * fy);
  const top = hash2(xi, yi) + (hash2(xi + 1, yi) - hash2(xi, yi)) * u;
  const bottom = hash2(xi, yi + 1) + (hash2(xi + 1, yi + 1) - hash2(xi, yi + 1)) * u;
  return top + (bottom - top) * v;
}

/** Smooth 0–1 patch field: layered noise drifting in different directions, warped by a slower layer. */
function bandAt(a, b, s) {
  const warp = valueNoise(a * 0.45 + s * 0.3, b * 0.45 - s * 0.2 + 11) - 0.5;
  const n = 0.55 * valueNoise(a + warp * 2.2 + s, b - warp * 1.4 - s * 0.35)
    + 0.3 * valueNoise(a * 2.1 - s * 0.8 + 17, b * 2.1 + s * 0.55 + 31)
    + 0.15 * valueNoise(a * 4.3 + s * 1.3 + 53, b * 4.3 - s * 0.4 - 71);
  return Math.min(1, Math.max(0, (n - 0.5) * DRIFT_CONTRAST + 0.5));
}

function bucketOf(alpha) {
  return Math.max(0, Math.min(ALPHA_LEVELS - 1, Math.floor(alpha * ALPHA_LEVELS)));
}

function emptyBuckets() {
  return Array.from({ length: ALPHA_LEVELS }, () => new Path2D());
}

/** A prop's 0–100 value as a 0–1 share. */
function share(value, fallback) {
  const number = Number(value);
  return Number.isFinite(number) ? Math.min(1, Math.max(0, number / 100)) : fallback;
}

/** A theme color as the canvas can paint it; without one, the text color around the layer. */
function resolveColor(element, color) {
  const css = color && typeof color === "object" ? color.css : "";
  const previous = element.style.color;
  element.style.color = css && css !== "initial" ? css : "currentColor";
  const resolved = getComputedStyle(element).color;
  element.style.color = previous;
  return resolved;
}

// ─── Mount ───────────────────────────────────────────────────────────────────

export default function mount(element, props = {}, ctx = {}) {
  if (typeof document === "undefined" || typeof Path2D === "undefined") return () => {};
  const canvas = document.createElement("canvas");
  canvas.className = "cometcms-ripple__canvas";
  element.append(canvas);
  const context = canvas.getContext("2d");
  if (!context) {
    canvas.remove();
    return () => {};
  }

  const intensity = share(props.intensity, 1);
  const drift = share(props.drift, 0);
  const interaction = ["pointer", "taps", "none"].includes(props.interaction) ? props.interaction : "pointer";
  const minCell = CELL[props.density] ?? CELL.medium;

  let lattice = null;
  let latticeContext = null;
  let latticeLevel = Number.NaN;
  let surface = null;
  let surfaceMoving = false;
  let width = 0;
  let height = 0;
  let cell = minCell;
  // Per-dot resting brightness: speckle plus the odd brighter data column.
  let restAlpha = new Float32Array(0);
  let activity = new Float32Array(0);
  // Per-dot resting brightness for the current frame, drift included.
  let restFrame = new Float32Array(0);
  // Per-dot dither threshold. Random rather than an ordered matrix so no tile repeats.
  let ditherAt = new Float32Array(0);
  let baseColor = "";
  let highlightColor = "";
  let frame = 0;
  let running = false;
  let lastFrame = 0;
  let lastPaint = -Infinity;
  let backlog = 0;
  let lastEcho = -Infinity;
  let visible = true;
  let pulses = [];
  const pointer = { x: 0, y: 0, inside: false };
  const motionQuery = window.matchMedia?.("(prefers-reduced-motion: reduce)") ?? null;
  const schemeQuery = window.matchMedia?.("(prefers-color-scheme: dark)") ?? null;
  let reducedMotion = motionQuery?.matches ?? !!ctx.reducedMotion;

  function paused() {
    return reducedMotion || document.hidden || !visible;
  }

  function resolveColors() {
    baseColor = resolveColor(element, props.color);
    highlightColor = resolveColor(element, props.highlight);
    latticeLevel = Number.NaN;
  }

  function seedLattice(cols, rows) {
    restAlpha = new Float32Array(cols * rows);
    ditherAt = new Float32Array(cols * rows);
    for (let i = 0; i < ditherAt.length; i++) ditherAt[i] = Math.random();
    for (let c = 0; c < cols; c++) {
      const column = noise(c) < 0.1 ? 0.08 : 0;
      for (let r = 0; r < rows; r++) {
        restAlpha[r * cols + c] = 0.12 + 0.08 * Math.random() ** 3 + column;
      }
    }
  }

  function addPulse(x, y, now) {
    if (pulses.length >= MAX_PULSES) return;
    pulses.push({
      x,
      y,
      start: now + 150 + Math.random() * 250,
      duration: 950,
      // Flares follow the stroke that caused them, so they fade with intensity like the water does.
      strength: 0.7 * intensity * intensity,
      seed: Math.random() * 1000,
    });
  }

  function echo(x, y) {
    const now = performance.now();
    if (now - lastEcho < ECHO_INTERVAL_MS) return;
    lastEcho = now;
    addPulse(x + (Math.random() - 0.5) * 4, y, now);
  }

  function paintActivity(now) {
    activity.fill(0);
    const { cols, rows } = surface;
    for (const pulse of pulses) {
      const age = (now - pulse.start) / pulse.duration;
      if (age < 0) continue;
      const envelope = Math.sin(age * Math.PI) ** 2 * pulse.strength;
      // Neighboring columns carry a packet upward with slightly different delays.
      for (let c = Math.max(0, Math.floor(pulse.x - 5)); c <= Math.min(cols - 1, pulse.x + 5); c++) {
        const column = noise(c + pulse.seed);
        const spread = Math.max(0, 1 - Math.abs(c - pulse.x) / 5);
        const head = pulse.y + 5 - age * 13 + column * 3;
        for (let r = Math.max(0, Math.floor(head - 4)); r <= Math.min(rows - 1, head + 7); r++) {
          const packet = Math.max(0, 1 - Math.abs(r - head) / 4);
          const cluster = Math.max(0, 1 - Math.abs(r - pulse.y) / 7) * 0.25;
          const i = r * cols + c;
          activity[i] = Math.min(1, activity[i] + envelope * spread * (packet + cluster) * (0.5 + column));
        }
      }
    }
  }

  // Batch by alpha level: one fill per level instead of one per dot.
  function fillBuckets(target, paths) {
    for (let bucket = 0; bucket < ALPHA_LEVELS; bucket++) {
      target.globalAlpha = (bucket + 0.5) / ALPHA_LEVELS;
      target.fillStyle = bucket >= ALPHA_LEVELS - HIGHLIGHT_LEVELS ? highlightColor : baseColor;
      target.fill(paths[bucket]);
    }
    target.globalAlpha = 1;
  }

  function restScale() {
    return REST_FLOOR + (1 - REST_FLOOR) * intensity;
  }

  /** Fills restFrame with each dot's resting brightness, dithered by the drift bands. */
  function paintRest(now) {
    const { cols, rows } = surface;
    const scale = restScale();
    if (drift === 0) {
      for (let i = 0; i < restFrame.length; i++) restFrame[i] = restAlpha[i] * scale;
      return;
    }
    const s = (reducedMotion ? 0 : now) * DRIFT_SPEED;
    for (let r = 0; r < rows; r++) {
      const b = r * cell * DRIFT_SCALE;
      for (let c = 0; c < cols; c++) {
        const i = r * cols + c;
        const on = bandAt(c * cell * DRIFT_SCALE, b, s) > ditherAt[i];
        const dither = on ? DRIFT_LIFT : DRIFT_FLOOR;
        restFrame[i] = restAlpha[i] * scale * (1 + drift * (dither - 1));
      }
    }
  }

  function drawResting(now) {
    const { cols, rows } = surface;
    const offset = cell / 2;
    // Drifting bands change every frame, so the lattice is rebuilt instead of cached.
    if (drift > 0) {
      const paths = emptyBuckets();
      paintRest(now);
      for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
          paths[bucketOf(restFrame[r * cols + c])].rect(c * cell + offset - DOT_SIZE / 2, r * cell + offset - DOT_SIZE / 2, DOT_SIZE, DOT_SIZE);
        }
      }
      fillBuckets(context, paths);
      return;
    }
    if (latticeLevel !== intensity && latticeContext) {
      const scale = restScale();
      const paths = emptyBuckets();
      for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
          paths[bucketOf(restAlpha[r * cols + c] * scale)].rect(c * cell + offset - DOT_SIZE / 2, r * cell + offset - DOT_SIZE / 2, DOT_SIZE, DOT_SIZE);
        }
      }
      latticeContext.clearRect(0, 0, width, height);
      fillBuckets(latticeContext, paths);
      latticeLevel = intensity;
    }
    if (lattice) context.drawImage(lattice, 0, 0, width, height);
  }

  function drawDisturbed(now) {
    paintActivity(now);
    paintRest(now);

    const { cols, rows, height: water, dragX, dragY } = surface;
    const offset = cell / 2;
    // Paint the reaction over the resting lattice at full strength.
    const dots = emptyBuckets();
    const glows = emptyBuckets();
    const motion = 0.3 + 0.7 * intensity;

    for (let r = 0; r < rows; r++) {
      // Two-row bands break into short, coherent strips rather than per-dot noise.
      const band = Math.floor(r / 2);
      const glitchFrame = Math.floor(now / GLITCH_MS + noise(band, 9));
      for (let c = 0; c < cols; c++) {
        const i = r * cols + c;
        const left = c > 0 ? water[i - 1] : water[i];
        const right = c < cols - 1 ? water[i + 1] : water[i];
        const up = r > 0 ? water[i - cols] : water[i];
        const down = r < rows - 1 ? water[i + cols] : water[i];
        const slopeX = right - left;
        const slopeY = down - up;

        const drag = Math.abs(dragX[i]) + Math.abs(dragY[i]);
        const energy = Math.min(1, (Math.abs(slopeX) + Math.abs(slopeY)) * 3 + Math.abs(water[i]) * 0.8 + drag * 0.35);
        const corruption = Math.min(1, energy * intensity + activity[i]);
        const fragment = noise(band, Math.floor(c / 7), glitchFrame);
        const broken = corruption > 0.12 && fragment > 0.76;
        if (broken && noise(c, r) > 0.55) continue;
        // Torn strips slip by whole cells, left or right, so they stay on the lattice.
        const slip = broken ? Math.round((fragment > 0.88 ? 1 : -1) * (1 + noise(band, glitchFrame) * 3) * corruption) * cell : 0;
        const column = noise(c, 3);
        const bar = corruption > 0.16 && column > 0.62;
        const glow = Math.min(1, corruption * (bar ? 0.85 : 0.5));

        // Quantized displacement lets the wall lose alignment, then lock back in.
        const x = c * cell + offset + Math.round(((slopeX * REFRACTION + dragX[i] * cell) * motion) / 2) * 2 + slip - DOT_SIZE / 2;
        const y = r * cell + offset + Math.round(((slopeY * REFRACTION + dragY[i] * cell) * motion) / 2) * 2 - DOT_SIZE / 2;
        dots[bucketOf(restFrame[i])].rect(x, y, DOT_SIZE, DOT_SIZE);
        if (glow < 0.02) continue;
        const dotHeight = bar ? DOT_SIZE + corruption * cell * (0.5 + column) : DOT_SIZE;
        glows[bucketOf(glow)].rect(x, y, DOT_SIZE, dotHeight);
      }
    }

    fillBuckets(context, dots);
    fillBuckets(context, glows);
  }

  function draw(now = performance.now()) {
    if (!surface) return;
    pulses = pulses.filter((pulse) => now < pulse.start + pulse.duration);
    context.clearRect(0, 0, width, height);
    if (surfaceMoving || pulses.length > 0) drawDisturbed(now);
    else drawResting(now);
  }

  function tick(now) {
    if (!surface) return;
    // The simulation runs on a fixed 60 Hz step so ripples travel at the same speed on any display.
    backlog = Math.min(backlog + now - lastFrame, STEP_MS * MAX_STEPS_PER_FRAME);
    lastFrame = now;
    while (backlog >= STEP_MS) {
      if (surfaceMoving) {
        surfaceMoving = surface.step();
        // Settle exactly to rest so the cached lattice stays clean.
        if (!surfaceMoving) surface.reset();
      }
      backlog -= STEP_MS;
    }
    // Only drift moves a settled field, and it needs far fewer frames.
    const settled = !surfaceMoving && pulses.length === 0;
    if (!settled || drift === 0 || now - lastPaint >= IDLE_FRAME_MS) {
      draw(now);
      lastPaint = now;
    }
    if (settled && drift === 0) {
      running = false;
      return;
    }
    frame = requestAnimationFrame(tick);
  }

  function wake() {
    if (running || paused() || !surface) return;
    running = true;
    lastFrame = performance.now();
    lastPaint = -Infinity;
    backlog = STEP_MS;
    frame = requestAnimationFrame(tick);
  }

  function stop() {
    cancelAnimationFrame(frame);
    running = false;
    surfaceMoving = false;
    pulses = [];
    lastEcho = -Infinity;
    pointer.inside = false;
    surface?.reset();
  }

  function resize() {
    const rect = canvas.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) return;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    width = rect.width;
    height = rect.height;
    canvas.width = Math.round(width * dpr);
    canvas.height = Math.round(height * dpr);
    context.setTransform(dpr, 0, 0, dpr, 0, 0);
    lattice ??= document.createElement("canvas");
    lattice.width = canvas.width;
    lattice.height = canvas.height;
    latticeContext = lattice.getContext("2d");
    latticeContext?.setTransform(dpr, 0, 0, dpr, 0, 0);
    cell = Math.max(minCell, Math.sqrt((width * height) / MAX_CELLS));
    const cols = Math.ceil(width / cell);
    const rows = Math.ceil(height / cell);
    surface = createRippleSurface(cols, rows);
    surfaceMoving = false;
    pulses = [];
    activity = new Float32Array(cols * rows);
    restFrame = new Float32Array(cols * rows);
    seedLattice(cols, rows);
    resolveColors();
    pointer.inside = false;
    draw();
    wake();
  }

  /** Where an event falls on the canvas, or null outside it. */
  function locate(event) {
    const rect = canvas.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const y = event.clientY - rect.top;
    return { x, y, inside: x >= 0 && y >= 0 && x <= rect.width && y <= rect.height };
  }

  function onPointerMove(event) {
    if (!surface || paused() || interaction !== "pointer") return;
    const { x, y, inside } = locate(event);
    const distance = Math.hypot(x - pointer.x, y - pointer.y);
    if (inside && pointer.inside && distance > 0) {
      // Stamp at most a cell apart so fast strokes leave a continuous wake.
      const stamps = Math.min(48, Math.ceil(distance / cell));
      const moveX = (x - pointer.x) / cell / stamps;
      const moveY = (y - pointer.y) / cell / stamps;
      for (let k = 1; k <= stamps; k++) {
        const t = k / stamps;
        surface.press((pointer.x + (x - pointer.x) * t) / cell - 0.5, (pointer.y + (y - pointer.y) * t) / cell - 0.5, moveX, moveY);
      }
      surfaceMoving = true;
      echo(x / cell - 0.5, y / cell - 0.5);
      wake();
    }
    // Entering starts a fresh stroke instead of dragging in from wherever the cursor left.
    pointer.x = x;
    pointer.y = y;
    pointer.inside = inside;
  }

  function onPointerDown(event) {
    if (!surface || paused() || interaction === "none" || event.button !== 0) return;
    if (event.target instanceof Element && event.target.closest(INTERACTIVE)) return;
    const { x, y, inside } = locate(event);
    if (!inside) return;
    surface.drop(x / cell - 0.5, y / cell - 0.5);
    surfaceMoving = true;
    echo(x / cell - 0.5, y / cell - 0.5);
    wake();
  }

  function onPointerLeave() {
    pointer.inside = false;
  }

  function onActivityChange() {
    reducedMotion = motionQuery?.matches ?? false;
    stop();
    draw();
    wake();
  }

  function onThemeChange() {
    resolveColors();
    draw();
  }

  const controller = new AbortController();
  const options = { signal: controller.signal };
  const resizeObserver = typeof ResizeObserver !== "undefined" ? new ResizeObserver(resize) : null;
  resizeObserver?.observe(canvas);
  // Out of view, the lattice stops; it picks up again when it scrolls back in.
  const visibility = typeof IntersectionObserver !== "undefined"
    ? new IntersectionObserver(([entry]) => {
      visible = entry.isIntersecting;
      if (visible) wake();
      else stop();
    })
    : null;
  visibility?.observe(element);
  // The site's light/dark toggle and the system preference both change the theme colors.
  const themeObserver = typeof MutationObserver !== "undefined" ? new MutationObserver(onThemeChange) : null;
  themeObserver?.observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme", "class"] });
  motionQuery?.addEventListener("change", onActivityChange, options);
  schemeQuery?.addEventListener("change", onThemeChange, options);
  document.addEventListener("visibilitychange", onActivityChange, options);
  window.addEventListener("pointermove", onPointerMove, { passive: true, signal: controller.signal });
  window.addEventListener("pointerdown", onPointerDown, { passive: true, signal: controller.signal });
  document.documentElement.addEventListener("pointerleave", onPointerLeave, options);

  resize();

  const cleanup = () => {
    stop();
    controller.abort();
    resizeObserver?.disconnect();
    visibility?.disconnect();
    themeObserver?.disconnect();
    canvas.remove();
    lattice = null;
    latticeContext = null;
    surface = null;
  };
  ctx.signal?.addEventListener("abort", cleanup, { once: true });

  return cleanup;
}
