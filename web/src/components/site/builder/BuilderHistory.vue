<template>
  <div class="space-y-5 p-3">
    <div class="flex gap-2 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-500">
      <Icon icon="mdi:information-outline" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
      <p>{{ t('site.history.hint') }}</p>
    </div>
    <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <div v-if="loading" class="flex justify-center p-6"><LoadingSpinner /></div>
    <div v-else-if="!revisions.length && !error" class="flex flex-col items-center gap-3 px-3 py-8 text-center">
      <span class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400">
        <Icon icon="mdi:history" class="h-5 w-5" aria-hidden="true" />
      </span>
      <p class="max-w-64 text-xs leading-relaxed text-slate-500">{{ t('site.history.empty') }}</p>
    </div>
    <section v-for="group in revisionGroups" v-else :key="group.date" class="space-y-3">
      <h3 class="flex items-center gap-2 text-xs font-medium text-slate-500">
        <span>{{ group.date }}</span>
        <span class="h-px flex-1 bg-slate-100" aria-hidden="true" />
      </h3>
      <ol class="ml-1.5 space-y-2.5 border-l border-slate-200 pl-4">
        <li v-for="revision in group.revisions" :key="revision.id" class="relative">
          <span class="absolute -left-[21px] top-4 h-2 w-2 rounded-full border-2 border-slate-300 bg-white ring-4 ring-white" aria-hidden="true" />
          <article class="overflow-hidden rounded-xl border border-slate-200 bg-white transition-colors hover:border-slate-300 focus-within:border-theme-400">
            <div class="space-y-1.5 p-3">
              <div class="flex min-w-0 items-center justify-between gap-3">
                <time :datetime="revision.created_at" :title="formatDate(revision.created_at)" class="shrink-0 text-sm font-semibold tabular-nums text-slate-800">{{ formatTime(revision.created_at) }}</time>
                <span v-if="revision.created_by_name || revision.created_by" class="flex min-w-0 items-center gap-1 text-xs text-slate-500" :title="revision.created_by_name || revision.created_by">
                  <Icon icon="mdi:account-outline" class="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden="true" />
                  <span class="truncate">{{ revision.created_by_name || revision.created_by }}</span>
                </span>
              </div>
              <p class="text-xs leading-relaxed text-slate-500">{{ t(`site.history.event.${revision.event}`) }}</p>
              <p v-if="revision.customized === false" class="inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">{{ t('site.history.defaultLayout') }}</p>
            </div>
            <button
              type="button"
              class="flex w-full items-center justify-center gap-1.5 border-t border-slate-100 bg-slate-50/60 px-3 py-2 text-xs font-medium text-theme-600 transition-colors hover:bg-theme-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-theme-500 disabled:cursor-not-allowed disabled:text-slate-400 disabled:hover:bg-slate-50/60"
              :disabled="busy || loadingRevision"
              :aria-label="`${t('site.history.load')}: ${formatDate(revision.created_at)}`"
              @click="loadRevision(revision)"
            >
              <Icon icon="mdi:backup-restore" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
              {{ t('site.history.load') }}
            </button>
          </article>
        </li>
      </ol>
    </section>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Icon } from "@iconify/vue";
import LoadingSpinner from "../../LoadingSpinner.vue";
import { useToastStore } from "../../../stores/toast.js";
import { useI18n } from "../../../i18n/index.js";

const props = defineProps({
  fetchRevisions: { type: Function, required: true },
  fetchRevision: { type: Function, required: true },
  busy: { type: Boolean, default: false },
});
const emit = defineEmits(["loaded"]);
const { t, locale } = useI18n();
const toast = useToastStore();
const loading = ref(false);
const loadingRevision = ref(false);
const revisions = ref([]);
const error = ref("");
let sequence = 0;

const revisionGroups = computed(() => {
  const groups = new Map();
  for (const revision of revisions.value) {
    const date = new Date(revision.created_at).toLocaleDateString(locale.value, { day: "numeric", month: "long", year: "numeric" });
    if (!groups.has(date)) groups.set(date, { date, revisions: [] });
    groups.get(date).revisions.push(revision);
  }
  return [...groups.values()];
});

function formatDate(value) {
  return new Date(value).toLocaleString(locale.value, { dateStyle: "medium", timeStyle: "medium" });
}

function formatTime(value) {
  return new Date(value).toLocaleTimeString(locale.value, { timeStyle: "medium" });
}

async function load() {
  loading.value = true;
  error.value = "";
  revisions.value = [];
  loadingRevision.value = false;
  const request = ++sequence;
  try {
    const result = await props.fetchRevisions();
    if (request === sequence) revisions.value = result.data ?? [];
  } catch (err) {
    if (request === sequence) error.value = err.message;
  } finally {
    if (request === sequence) loading.value = false;
  }
}

async function loadRevision(revision) {
  if (loadingRevision.value || props.busy) return;
  const request = ++sequence;
  loadingRevision.value = true;
  error.value = "";
  try {
    const result = await props.fetchRevision(revision.id);
    if (request !== sequence || props.busy) return;
    emit("loaded", result.data, revision);
    toast.success(t("site.history.loaded"));
  } catch (err) {
    if (request === sequence) error.value = err.message;
  } finally {
    if (request === sequence) loadingRevision.value = false;
  }
}

watch(() => props.fetchRevisions, load, { immediate: true });
defineExpose({ reload: load });

onMounted(() => window.addEventListener("cometcms:workspace-changed", load));
onBeforeUnmount(() => {
  sequence++;
  window.removeEventListener("cometcms:workspace-changed", load);
});
</script>
