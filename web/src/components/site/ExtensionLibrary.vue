<template>
  <section class="card space-y-4 p-6" :aria-busy="busy">
    <div>
      <h2 class="card-title">{{ t("site.extensions.title") }}</h2>
      <p class="card-description">{{ t("site.extensions.description") }}</p>
    </div>
    <div v-if="error" role="alert" class="space-y-2 rounded-lg bg-red-50 p-3 text-sm text-red-700">
      <p>{{ error.message }}</p>
      <ul v-if="error.details.length" class="list-disc space-y-1 pl-5 font-mono text-xs">
        <li v-for="(issue, index) in error.details" :key="index">{{ formatIssue(issue) }}</li>
      </ul>
    </div>
    <p v-if="success" role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ success }}</p>
    <div v-if="data?.can_install" class="space-y-2">
      <h3 id="extension-upload-label" class="text-sm font-medium text-slate-700">{{ t("site.extensions.upload") }}</h3>
      <div
        id="extension-drop-zone"
        role="group"
        aria-labelledby="extension-upload-label"
        :aria-disabled="busy"
        class="flex flex-col items-center gap-3 rounded-xl border-2 border-dashed px-6 py-8 text-center transition-colors"
        :class="busy ? 'border-slate-200 bg-slate-50 opacity-60' : dragDepth ? 'border-theme-400 bg-theme-50' : 'border-slate-300 hover:border-slate-400'"
        @dragenter.prevent="dragEnter"
        @dragover.prevent="dragOver"
        @dragleave.prevent="dragDepth = Math.max(0, dragDepth - 1)"
        @drop.prevent.stop="drop"
      >
        <Icon icon="mdi:extension-variant-closed" class="h-9 w-9 text-slate-400" aria-hidden="true" />
        <p class="text-sm text-slate-600">{{ t("site.extensions.dropHint") }}</p>
        <label class="btn-secondary relative focus-within:ring-2 focus-within:ring-theme-500" :class="busy ? 'cursor-not-allowed' : 'cursor-pointer'">
          {{ t("site.extensions.chooseFile") }}
          <input id="extension-upload" type="file" accept=".zip,application/zip" :disabled="busy" class="sr-only" @change="choose" />
        </label>
        <div v-if="progress !== null" class="h-1.5 w-full max-w-xs overflow-hidden rounded-full bg-slate-200" role="progressbar" :aria-valuenow="Math.round(progress * 100)" aria-valuemin="0" aria-valuemax="100">
          <div class="h-full bg-theme-500 transition-[width]" :style="{ width: `${Math.round(progress * 100)}%` }"></div>
        </div>
      </div>
      <p class="text-xs text-slate-500">{{ t("site.extensions.uploadHint") }}</p>
    </div>
    <p v-else-if="data" class="text-sm text-slate-500">{{ t("site.extensions.adminOnly") }}</p>
    <p v-if="data?.extensions.length" class="text-xs text-slate-500">{{ t("site.extensions.disableHint") }}</p>
    <ul v-if="data?.extensions.length" class="divide-y divide-slate-100">
      <li v-for="pkg in data.extensions" :key="pkg.name" class="flex items-start justify-between gap-4 py-3">
        <div class="flex min-w-0 flex-1 items-start gap-4 transition-opacity" :class="{ 'opacity-60 grayscale': pkg.enabled === false }">
          <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-50 ring-1 ring-inset ring-slate-200">
            <img v-if="pkg.icon" :src="pkg.icon" alt="" width="48" height="48" decoding="async" class="h-full w-full object-contain p-1" />
            <Icon v-else icon="mdi:extension-variant-closed" class="h-7 w-7 text-slate-400" aria-hidden="true" />
          </span>
          <div class="min-w-0 space-y-1">
            <p class="font-medium text-slate-800">
              {{ pkg.title }} <span class="text-xs text-slate-500">{{ pkg.version }}</span>
              <span class="ml-2 rounded px-1.5 py-0.5 text-xs font-medium" :class="pkg.enabled === false ? 'bg-slate-100 text-slate-600' : 'bg-emerald-50 text-emerald-700'">{{ t(pkg.enabled === false ? 'site.extensions.disabled' : 'site.extensions.enabled') }}</span>
              <span v-if="pkg.scripts" class="ml-1 inline-flex items-center gap-1 rounded bg-amber-100 px-1.5 py-0.5 align-middle text-[10px] font-semibold text-amber-800" :title="t('site.extensions.scriptsBadgeHint')">
                <Icon icon="mdi:language-javascript" class="h-3 w-3" aria-hidden="true" />{{ t("site.extensions.scriptsBadge") }}
              </span>
            </p>
            <p class="text-xs text-slate-500">{{ [pkg.name, pkg.author, pkg.license].filter(Boolean).join(" · ") }}</p>
            <p v-if="pkg.requires?.length" class="text-xs text-slate-500">{{ t('site.extensions.requires') }} {{ pkg.requires.join(', ') }}</p>
            <p v-if="pkg.description" class="text-sm text-slate-600">{{ pkg.description }}</p>
            <p class="text-xs text-slate-500">{{ t('site.extensions.counts', { blocks: pkg.counts?.blocks ?? pkg.blocks.length, types: pkg.counts?.content_types ?? 0, components: pkg.counts?.components ?? 0, templates: pkg.counts?.templates ?? 0, presets: pkg.counts?.presets ?? 0 }) }}</p>
            <ul class="flex flex-wrap gap-1.5 pt-1" :aria-label="t('site.extensions.blocksIn', { name: pkg.title })">
              <li v-for="block in pkg.blocks" :key="block.name" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-700" :title="block.description || block.name">
                <KindIcon :icon="kindIcon(`block:${block.name}`, { kinds: { [`block:${block.name}`]: block } })" class="h-3.5 w-3.5 shrink-0 text-slate-500" />
                {{ block.title }}
                <Icon v-if="block.script" icon="mdi:language-javascript" class="h-3.5 w-3.5 text-amber-700" :aria-label="t('site.extensions.scriptsBadge')" />
              </li>
            </ul>
          </div>
        </div>
        <div v-if="data.can_install" class="flex shrink-0 flex-wrap items-center justify-end gap-2">
          <button type="button" class="btn-secondary btn-sm" :disabled="busy" :aria-label="t(pkg.enabled === false ? 'site.extensions.enableLabel' : 'site.extensions.disableLabel', { name: pkg.title })" @click="toggleEnabled(pkg)">
            <Icon :icon="pkg.enabled === false ? 'mdi:play-circle-outline' : 'mdi:pause-circle-outline'" class="h-4 w-4" aria-hidden="true" />
            {{ t(pkg.enabled === false ? 'site.extensions.enable' : 'site.extensions.disable') }}
          </button>
          <button type="button" class="btn-secondary btn-sm shrink-0 text-red-600" :disabled="busy" :aria-label="t('site.extensions.deleteLabel', { name: pkg.title })" @click="previewRemove(pkg)">
            <Icon icon="mdi:trash-can-outline" class="h-4 w-4" aria-hidden="true" />
            {{ t("common.delete") }}
          </button>
        </div>
      </li>
    </ul>
    <p v-else-if="data" class="text-sm text-slate-500">{{ t("site.extensions.empty") }}</p>
    <div v-if="data?.errors.length" class="space-y-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
      <p>{{ t("site.extensions.loadErrors") }}</p>
      <ul class="list-disc space-y-1 pl-5 font-mono text-xs">
        <li v-for="(issue, index) in data.errors" :key="index">{{ issue.extension ? `${issue.extension}: ` : "" }}{{ formatIssue(issue) }}</li>
      </ul>
      <div v-if="data.can_install" class="flex flex-wrap gap-2">
        <button v-for="name in brokenExtensions" :key="name" type="button" class="btn-secondary btn-sm" :disabled="busy" :aria-label="t('site.extensions.deleteLabel', { name })" @click="previewRemove({ name })">
          {{ t('common.delete') }} {{ name }}
        </button>
      </div>
    </div>
    <ConfirmModal
      :model-value="!!deleteTarget"
      :title="t('site.extensions.deleteTitle')"
      :message="t('site.extensions.deleteMessage', { name: deleteTarget?.title ?? '' })"
      :loading="busy"
      @update:model-value="(open) => { if (!open) deleteTarget = null }"
      @confirm="remove"
    >
      <div v-if="deleteTarget?.seeded?.length" class="mt-3 max-h-72 space-y-3 overflow-y-auto text-sm">
        <p>{{ t('site.extensions.removeSeedsHint') }}</p>
        <label v-for="item in deleteTarget.seeded" :key="seedKey(item)" class="block space-y-1">
          <span class="flex items-start gap-2">
            <input v-model="removeChoices" type="checkbox" :value="seedKey(item)" :disabled="busy" class="mt-1" />
            <span>{{ item.name }} <span class="text-xs text-slate-500">({{ t(`site.extensions.kind.${item.kind}`) }})</span></span>
          </span>
          <span v-if="removeChoices.includes(seedKey(item)) && (item.modified || item.entry_count)" class="block text-xs text-amber-800" role="status">
            <span v-if="item.modified">{{ t('site.extensions.modifiedWarning') }} </span>
            <span v-if="item.entry_count">{{ t('site.extensions.entriesWarning', { count: item.entry_count }) }}</span>
          </span>
        </label>
      </div>
    </ConfirmModal>
    <ConfirmModal
      :model-value="!!pendingReview"
      variant="warning"
      :title="t('site.extensions.reviewTitle')"
      :message="t('site.extensions.reviewMessage', pendingReview ?? {})"
      :confirm-label="t(pendingReview?.scripts.length ? 'site.extensions.scriptsConfirm' : 'site.extensions.seedsConfirm')"
      :loading-label="t('site.extensions.upload')"
      :loading="busy"
      @update:model-value="(open) => { if (!open) pendingReview = null }"
      @confirm="confirmReview"
    >
      <div class="mt-3 max-h-[60vh] space-y-4 overflow-y-auto text-sm">
        <section v-if="pendingReview?.changes.length" class="space-y-2">
          <h4 class="font-semibold">{{ t('site.extensions.changesTitle') }}</h4>
          <p>{{ t('site.extensions.changesMessage', pendingReview) }}</p>
          <ul class="list-disc space-y-1 pl-5">
            <li v-for="change in pendingReview.changes" :key="change">{{ change }}</li>
          </ul>
        </section>
        <section v-if="pendingReview?.scripts.length" class="space-y-2 rounded-lg bg-amber-50 p-3 text-amber-900">
          <h4 class="font-semibold">{{ t('site.extensions.scriptsTitle') }}</h4>
          <p>{{ t('site.extensions.scriptsMessage', pendingReview) }}</p>
          <ul class="list-disc space-y-1 pl-5">
            <li v-for="block in pendingReview.scripts" :key="block">{{ block }}</li>
          </ul>
        </section>
        <section v-if="seedGroups.length" class="space-y-2">
          <h4 class="font-semibold">{{ t('site.extensions.seedsTitle') }}</h4>
          <p>{{ t('site.extensions.seedsMessage') }}</p>
          <section v-for="group in seedGroups" :key="group.kind">
            <h4 class="text-sm font-semibold">{{ t(`site.extensions.kind.${group.kind}`) }}</h4>
            <table class="w-full text-left text-xs">
              <thead><tr><th scope="col" class="py-1">{{ t('common.name') }}</th><th scope="col">{{ t('site.extensions.seedStatus') }}</th><th scope="col">{{ t('site.extensions.seedAction') }}</th></tr></thead>
              <tbody>
                <tr v-for="item in group.items" :key="seedKey(item)">
                  <td class="py-2 pr-2">{{ item.name }}</td>
                  <td class="pr-2"><span class="rounded bg-slate-100 px-1.5 py-0.5">{{ t(item.exists ? 'site.extensions.exists' : 'site.extensions.new') }}</span></td>
                  <td>
                    <select v-if="item.exists" v-model="seedChoices[seedKey(item)]" :aria-label="t('site.extensions.choiceFor', { name: item.name })" :disabled="busy" class="form-input py-1 text-xs">
                      <option value="skip">{{ t('site.extensions.skip') }}</option>
                      <option value="overwrite">{{ t('site.extensions.overwrite') }}</option>
                    </select>
                    <span v-else>{{ t('site.extensions.create') }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </section>
        </section>
      </div>
    </ConfirmModal>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Icon } from "@iconify/vue";
import ConfirmModal from "../ConfirmModal.vue";
import KindIcon from "./builder/KindIcon.vue";
import { kindIcon } from "./builder/kindIcons.js";
import { api, getActiveWorkspace } from "../../api/index.js";
import { extensionUploadError } from "../../composables/extensions.js";
import { useSiteStore } from "../../stores/site.js";
import { useContentTypesStore } from "../../stores/contentTypes.js";
import { useI18n } from "../../i18n/index.js";

const { t } = useI18n();
const site = useSiteStore();
const types = useContentTypesStore();
const data = ref(null);
const busy = ref(false);
const error = ref(null);
const success = ref("");
const progress = ref(null);
const dragDepth = ref(0);
const deleteTarget = ref(null);
const pendingReview = ref(null);
const seedChoices = ref({});
const removeChoices = ref([]);
const seedKey = (item) => `${item.kind}/${item.id}`;
const seedGroups = computed(() => ['content_types', 'components', 'templates', 'presets'].map((kind) => ({ kind, items: (pendingReview.value?.plan ?? []).filter((item) => item.kind === kind) })).filter((group) => group.items.length));
const brokenExtensions = computed(() => [...new Set((data.value?.errors ?? []).map((issue) => issue.extension))]
  .filter((name) => name && !(data.value?.extensions ?? []).some((pkg) => pkg.name === name)));
let generation = 0;

const formatIssue = (issue) => `${issue.file}${issue.line ? `:${issue.line}` : ""}${issue.file ? ": " : ""}${issue.message}`;
const fail = (err) => ({ message: err.message, details: Array.isArray(err.details) ? err.details : [] });

async function load() {
  const current = ++generation;
  data.value = null;
  error.value = null;
  success.value = "";
  dragDepth.value = 0;
  deleteTarget.value = null;
  pendingReview.value = null;
  busy.value = true;
  try {
    const result = await api.extensions.list();
    if (current === generation) data.value = result.data;
  } catch (err) {
    if (current === generation) error.value = fail(err);
  } finally {
    if (current === generation) busy.value = false;
  }
}

function choose(event) {
  const files = Array.from(event.target.files ?? []);
  event.target.value = "";
  upload(files);
}

function dragEnter(event) {
  if (!busy.value && Array.from(event.dataTransfer?.types ?? []).includes("Files")) dragDepth.value++;
}

function dragOver(event) {
  if (event.dataTransfer) event.dataTransfer.dropEffect = busy.value ? "none" : "copy";
}

function drop(event) {
  dragDepth.value = 0;
  upload(Array.from(event.dataTransfer?.files ?? []));
}

/** Review all install decisions together; the server revalidates the ZIP on confirmation. */
async function upload(files, consent = { acceptChanges: false, allowScripts: false }) {
  if (busy.value || !data.value?.can_install || !files.length) return;
  const problem = extensionUploadError(files);
  if (problem) {
    error.value = { message: t(problem), details: [] };
    success.value = "";
    return;
  }
  const current = generation;
  const workspace = getActiveWorkspace();
  busy.value = true;
  error.value = null;
  success.value = "";
  progress.value = 0;
  try {
    const { data: installed } = await api.extensions.install(files[0], { ...consent, onProgress: (value) => { progress.value = value; } });
    if (current !== generation || workspace !== getActiveWorkspace()) return;
    pendingReview.value = null;
    types.invalidate();
    await Promise.all([site.refreshExtensions(), types.fetch()]);
    const result = await api.extensions.list();
    if (current === generation) {
      data.value = result.data;
      success.value = installed.previous_version
        ? t("site.extensions.updated", { name: installed.title, from: installed.previous_version, to: installed.version })
        : t("site.extensions.installed", { name: installed.title });
    }
  } catch (err) {
    if (current !== generation) return;
    pendingReview.value = null;
    if (err.code === "extension_review" && err.details) {
      pendingReview.value = { ...err.details, file: files[0] };
      seedChoices.value = Object.fromEntries(err.details.plan.map((item) => [seedKey(item), item.exists ? 'skip' : 'create']));
    } else {
      error.value = fail(err);
    }
  } finally {
    if (current === generation) {
      busy.value = false;
      progress.value = null;
    }
  }
}

function confirmReview() {
  const pending = pendingReview.value;
  if (pending) upload([pending.file], {
    acceptChanges: pending.changes.length > 0,
    allowScripts: pending.scripts.length > 0,
    seeds: { ...seedChoices.value },
  });
}

async function toggleEnabled(pkg) {
  if (busy.value || !data.value?.can_install) return;
  const current = generation;
  const workspace = getActiveWorkspace();
  const enabled = pkg.enabled === false;
  busy.value = true;
  error.value = null;
  success.value = "";
  try {
    await api.extensions.setEnabled(pkg.name, enabled);
    if (current !== generation || workspace !== getActiveWorkspace()) return;
    pkg.enabled = enabled;
    types.invalidate({ clear: true });
    await Promise.all([site.refreshExtensions(), types.fetch()]);
    if (current === generation) {
      success.value = t(enabled ? "site.extensions.enabledSuccess" : "site.extensions.disabledSuccess", { name: pkg.title });
    }
  } catch (err) {
    if (current === generation) error.value = fail(err);
  } finally {
    if (current === generation) busy.value = false;
  }
}

async function previewRemove(pkg) {
  if (busy.value) return;
  const current = generation;
  const workspace = getActiveWorkspace();
  busy.value = true;
  error.value = null;
  try {
    const result = await api.extensions.get(pkg.name);
    if (current !== generation || workspace !== getActiveWorkspace()) return;
    removeChoices.value = [];
    deleteTarget.value = result.data;
  } catch (err) {
    if (current === generation) error.value = fail(err);
  } finally {
    if (current === generation) busy.value = false;
  }
}

async function remove() {
  if (busy.value || !deleteTarget.value || !data.value?.can_install) return;
  const current = generation;
  const workspace = getActiveWorkspace();
  busy.value = true;
  error.value = null;
  success.value = "";
  try {
    await api.extensions.delete(deleteTarget.value.name, removeChoices.value);
    if (current !== generation || workspace !== getActiveWorkspace()) return;
    deleteTarget.value = null;
    types.invalidate();
    await Promise.all([site.refreshExtensions(), types.fetch()]);
    const result = await api.extensions.list();
    if (current === generation) {
      data.value = result.data;
      success.value = t("site.extensions.deleted");
    }
  } catch (err) {
    if (current === generation) {
      deleteTarget.value = null;
      error.value = fail(err);
    }
  } finally {
    if (current === generation) busy.value = false;
  }
}

onMounted(() => {
  load();
  window.addEventListener("cometcms:workspace-changed", load);
});
onBeforeUnmount(() => {
  generation++;
  window.removeEventListener("cometcms:workspace-changed", load);
});
</script>
