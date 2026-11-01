/** Shared native drag protocol for elements inserted from builder panels. */
export function startPaletteDrag(builder, event, create) {
  if (builder.translating.value) {
    event.preventDefault();
    return;
  }
  builder.drag.value = { source: 'palette', create };
  event.dataTransfer.effectAllowed = 'copy';
  event.dataTransfer.setData('text/plain', 'cometcms-node');
}

export function endPaletteDrag(builder) {
  const drag = builder.drag.value;
  // Let the canvas finish its drop first; also clear cancelled drags.
  setTimeout(() => {
    if (builder.drag.value === drag) builder.drag.value = null;
  }, 0);
}
