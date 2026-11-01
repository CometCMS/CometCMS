<template>
  <!-- The padding leaves room for the frame's ring, which the clipping would otherwise cut off at the edges. -->
  <div ref="containerRef" class="relative h-full w-full overflow-hidden p-px">
    <div
      class="mx-auto origin-top-left overflow-hidden rounded-xl bg-[#fff] shadow-sm ring-1 ring-slate-200"
      :class="{ 'transition-[width] duration-200': device !== 'full' }"
      :style="frameBoxStyle"
    >
      <iframe
        ref="iframeRef"
        class="block border-0 bg-[#fff]"
        :style="iframeStyle"
        :title="title"
        :srcdoc="srcdoc"
        @load="onLoad"
      ></iframe>
    </div>

    <div
      v-if="loading"
      class="pointer-events-none absolute right-3 top-3 flex items-center gap-2 rounded-full bg-ink-900/80 px-3 py-1 text-xs font-medium text-white"
    >
      <span class="h-2 w-2 animate-pulse rounded-full bg-theme-400"></span>
      {{ t("site.preview.updating") }}
    </div>

    <div
      v-if="error"
      class="absolute inset-x-3 bottom-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200"
    >
      {{ error }}
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { api } from "../../api/index.js";
import { useI18n } from "../../i18n/index.js";
import { needsPreviewReload, patchDocument, withPreviewStyles } from "../../composables/previewDocument.js";
import { initGalleryLightbox } from "../../../../cms/app/Site/assets/gallery.js";
import { initTabs } from "../../../../cms/app/Site/assets/tabs.js";
import { initMenus } from "../../../../cms/app/Site/assets/menus.js";

const DEVICE_WIDTHS = { desktop: 1280, tablet: 820, mobile: 390 };

const props = defineProps({
  /** Body for POST /site/preview; null renders nothing. */
  body: { type: Object, default: null },
  device: { type: String, default: "desktop" },
  delay: { type: Number, default: 250 },
  title: { type: String, default: "Website preview" },
  /** Let the parent handle link clicks (the builder selects nodes instead). */
  interactive: { type: Boolean, default: false },
  /** Trusted editor CSS included before the iframe's first paint. */
  headCss: { type: String, default: "" },
});

/** ready: a new document loaded; updated: the loaded one was patched in place; rendered: the response data (html and what else the preview reports). */
const emit = defineEmits(["ready", "updated", "rendered"]);
const { t } = useI18n();
const containerRef = ref(null);
const iframeRef = ref(null);
const srcdoc = ref("");
const loading = ref(false);
const error = ref("");
const containerWidth = ref(0);
const containerHeight = ref(0);
let timer = null;
let sequence = 0;
let pendingScroll = 0;
/** Whether the iframe holds a loaded document that renders can patch. */
let documentLoaded = false;
let renderedHtml = "";
let resizeObserver = null;

const deviceWidth = computed(() => DEVICE_WIDTHS[props.device] ?? DEVICE_WIDTHS.desktop);
const scale = computed(() =>
  props.device !== "full" && containerWidth.value > 0 ? Math.min(1, containerWidth.value / deviceWidth.value) : 1,
);
const iframeStyle = computed(() => ({
  width: props.device === "full" ? "100%" : `${deviceWidth.value}px`,
  height: `${Math.max(320, containerHeight.value / scale.value)}px`,
  transform: scale.value < 1 ? `scale(${scale.value})` : undefined,
  transformOrigin: "top left",
}));
const frameBoxStyle = computed(() => ({
  width: props.device === "full" ? "100%" : `${deviceWidth.value * scale.value}px`,
  height: `${Math.max(320, containerHeight.value)}px`,
}));

/**
 * The frame is scaled down to fit; editor overlays inside it (labels, grips)
 * divide their sizes by this so they stay readable at any zoom.
 */
function applyScale() {
  iframeRef.value?.contentDocument?.documentElement?.style.setProperty("--cometcms-ui-scale", String(1 / scale.value));
}

watch(scale, applyScale);

async function render() {
  if (!props.body) return;
  const body = props.body;
  const current = ++sequence;
  loading.value = true;

  try {
    const res = await api.site.preview(body);
    if (current !== sequence) return;
    const html = withPreviewStyles(res.data?.html ?? "", props.headCss);
    const doc = documentLoaded ? iframeRef.value?.contentDocument : null;
    if (doc?.body && html === renderedHtml) {
      applyScale();
      emit("updated", doc);
    } else if (doc?.body && html && !needsPreviewReload(doc, html)) {
      // Reloading the iframe would blank it for a frame on every edit.
      patchDocument(doc, html);
      initTabs(doc);
      applyScale();
      emit("updated", doc);
    } else {
      pendingScroll = iframeRef.value?.contentWindow?.scrollY ?? 0;
      documentLoaded = false;
      srcdoc.value = html;
    }
    renderedHtml = html;
    error.value = "";
    emit("rendered", res.data, body);
  } catch (err) {
    if (current === sequence) error.value = err.message;
  } finally {
    if (current === sequence) loading.value = false;
  }
}

function schedule(immediate = false) {
  clearTimeout(timer);
  timer = setTimeout(render, immediate ? 0 : props.delay);
}

function onLoad() {
  const doc = iframeRef.value?.contentDocument;
  if (!doc || !srcdoc.value) return;
  documentLoaded = true;
  applyScale();
  initGalleryLightbox(doc);
  initTabs(doc);
  initMenus(doc);

  if (pendingScroll > 0) {
    iframeRef.value.contentWindow.scrollTo(0, pendingScroll);
  }

  if (!props.interactive) {
    // Previews are for looking: following links would leave the preview.
    doc.addEventListener("click", (event) => {
      if (event.target.closest?.('[data-gallery-image]') && doc.defaultView?.HTMLDialogElement?.prototype.showModal) return;
      if (event.target.closest?.("a, label, input")) event.preventDefault();
    }, true);
  }

  emit("ready", doc);
}

watch(() => props.body, () => schedule(), { deep: true });

onMounted(() => {
  resizeObserver = new ResizeObserver(([entry]) => {
    containerWidth.value = entry.contentRect.width;
    containerHeight.value = entry.contentRect.height;
  });
  if (containerRef.value) resizeObserver.observe(containerRef.value);
  schedule(true);
});

onBeforeUnmount(() => {
  clearTimeout(timer);
  resizeObserver?.disconnect();
});

defineExpose({
  refresh: () => schedule(true),
  document: () => iframeRef.value?.contentDocument ?? null,
  window: () => iframeRef.value?.contentWindow ?? null,
});
</script>
