<template>
  <section class="card space-y-4 p-6" :aria-busy="busy">
    <div>
      <h2 class="card-title">{{ t("site.blocks.title") }}</h2>
      <p class="card-description">{{ t("site.blocks.description") }}</p>
    </div>
    <div v-if="error" role="alert" class="space-y-2 rounded-lg bg-red-50 p-3 text-sm text-red-700">
      <p>{{ error.message }}</p>
      <ul v-if="error.details.length" class="list-disc space-y-1 pl-5 font-mono text-xs">
        <li v-for="(issue, index) in error.details" :key="index">{{ formatIssue(issue) }}</li>
      </ul>
    </div>
    <p v-if="success" role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ success }}</p>
    <div v-if="data?.can_install" class="space-y-2">
      <h3 id="block-upload-label" class="text-sm font-medium text-slate-700">{{ t("site.blocks.upload") }}</h3>
      <div
        id="block-drop-zone"
        role="group"
        aria-labelledby="block-upload-label"
        :aria-disabled="busy"
        class="flex flex-col items-center gap-3 rounded-xl border-2 border-dashed px-6 py-8 text-center transition-colors"
        :class="busy ? 'border-slate-200 bg-slate-50 opacity-60' : dragDepth ? 'border-theme-400 bg-theme-50' : 'border-slate-300 hover:border-slate-400'"
        @dragenter.prevent="dragEnter"
        @dragover.prevent="dragOver"
        @dragleave.prevent="dragDepth = Math.max(0, dragDepth - 1)"
        @drop.prevent.stop="drop"
      >
        <Icon icon="mdi:package-variant-closed" class="h-9 w-9 text-slate-400" aria-hidden="true" />
        <p class="text-sm text-slate-600">{{ t("site.blocks.dropHint") }}</p>
        <label class="btn-secondary relative focus-within:ring-2 focus-within:ring-theme-500" :class="busy ? 'cursor-not-allowed' : 'cursor-pointer'">
          {{ t("site.blocks.chooseFile") }}
          <input id="block-package" type="file" accept=".zip,application/zip" :disabled="busy" class="sr-only" @change="choose" />
        </label>
        <div v-if="progress !== null" class="h-1.5 w-full max-w-xs overflow-hidden rounded-full bg-slate-200" role="progressbar" :aria-valuenow="Math.round(progress * 100)" aria-valuemin="0" aria-valuemax="100">
          <div class="h-full bg-theme-500 transition-[width]" :style="{ width: `${Math.round(progress * 100)}%` }"></div>
        </div>
      </div>
      <p class="text-xs text-slate-500">{{ t("site.blocks.uploadHint") }}</p>
    </div>
    <p v-else-if="data" class="text-sm text-slate-500">{{ t("site.blocks.adminOnly") }}</p>
    <ul v-if="data?.packages.length" class="divide-y divide-slate-100">
      <li v-for="pkg in data.packages" :key="pkg.name" class="flex items-start justify-between gap-4 py-3">
        <div class="min-w-0 space-y-1">
          <p class="font-medium text-slate-800">
            {{ pkg.title }} <span class="text-xs text-slate-500">{{ pkg.version }}</span>
            <span v-if="pkg.scripts" class="ml-1 inline-flex items-center gap-1 rounded bg-amber-100 px-1.5 py-0.5 align-middle text-[10px] font-semibold text-amber-800" :title="t('site.blocks.scriptsBadgeHint')">
              <Icon icon="mdi:language-javascript" class="h-3 w-3" aria-hidden="true" />{{ t("site.blocks.scriptsBadge") }}
            </span>
          </p>
          <p class="text-xs text-slate-500">{{ [pkg.name, pkg.author, pkg.license].filter(Boolean).join(" · ") }}</p>
          <p v-if="pkg.description" class="text-sm text-slate-600">{{ pkg.description }}</p>
          <ul class="flex flex-wrap gap-1.5 pt-1" :aria-label="t('site.blocks.blocksIn', { name: pkg.title })">
            <li v-for="block in pkg.blocks" :key="block.name" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-700" :title="block.description || block.name">
              <KindIcon :icon="kindIcon(`block:${block.name}`, { kinds: { [`block:${block.name}`]: block } })" class="h-3.5 w-3.5 shrink-0 text-slate-500" />
              {{ block.title }}
              <Icon v-if="block.script" icon="mdi:language-javascript" class="h-3.5 w-3.5 text-amber-700" :aria-label="t('site.blocks.scriptsBadge')" />
            </li>
          </ul>
        </div>
        <button v-if="data.can_install" type="button" class="btn-secondary btn-sm shrink-0 text-red-600" :disabled="busy" :aria-label="t('site.blocks.deleteLabel', { name: pkg.title })" @click="deleteTarget = pkg">
          <Icon icon="mdi:trash-can-outline" class="h-4 w-4" aria-hidden="true" />
          {{ t("common.delete") }}
        </button>
      </li>
    </ul>
    <p v-else-if="data" class="text-sm text-slate-500">{{ t("site.blocks.empty") }}</p>
    <div v-if="data?.errors.length" class="space-y-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
      <p>{{ t("site.blocks.loadErrors") }}</p>
      <ul class="list-disc space-y-1 pl-5 font-mono text-xs">
        <li v-for="(issue, index) in data.errors" :key="index">{{ issue.package ? `${issue.package}: ` : "" }}{{ formatIssue(issue) }}</li>
      </ul>
    </div>
    <ConfirmModal
      :model-value="!!deleteTarget"
      :title="t('site.blocks.deleteTitle')"
      :message="t('site.blocks.deleteMessage', { name: deleteTarget?.title ?? '' })"
      :loading="busy"
      @update:model-value="(open) => { if (!open) deleteTarget = null }"
      @confirm="remove"
    />
    <ConfirmModal
      :model-value="!!pendingUpdate"
      variant="warning"
      :title="t('site.blocks.changesTitle')"
      :message="t('site.blocks.changesMessage', pendingUpdate ?? {})"
      :confirm-label="t('site.blocks.changesConfirm')"
      :loading="busy"
      @update:model-value="(open) => { if (!open) pendingUpdate = null }"
      @confirm="confirmUpdate"
    >
      <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li v-for="change in pendingUpdate?.changes ?? []" :key="change">{{ change }}</li>
      </ul>
    </ConfirmModal>
    <ConfirmModal
      :model-value="!!pendingScripts"
      variant="warning"
      :title="t('site.blocks.scriptsTitle')"
      :message="t('site.blocks.scriptsMessage', { package: pendingScripts?.package ?? '' })"
      :confirm-label="t('site.blocks.scriptsConfirm')"
      :loading="busy"
      @update:model-value="(open) => { if (!open) pendingScripts = null }"
      @confirm="confirmScripts"
    >
      <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li v-for="block in pendingScripts?.blocks ?? []" :key="block">{{ block }}</li>
      </ul>
    </ConfirmModal>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from "vue";
import { Icon } from "@iconify/vue";
import ConfirmModal from "../ConfirmModal.vue";
import KindIcon from "./builder/KindIcon.vue";
import { kindIcon } from "./builder/kindIcons.js";
import { api, getActiveWorkspace } from "../../api/index.js";
import { packageUploadError } from "../../composables/blockPackages.js";
import { useI18n } from "../../i18n/index.js";

const { t } = useI18n();
const data = ref(null);
const busy = ref(false);
const error = ref(null);
const success = ref("");
const progress = ref(null);
const dragDepth = ref(0);
const deleteTarget = ref(null);
const pendingUpdate = ref(null);
const pendingScripts = ref(null);
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
  pendingUpdate.value = null;
  pendingScripts.value = null;
  busy.value = true;
  try {
    const result = await api.blocks.list();
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

/** Consent travels with retries: confirming one question keeps the answer to the other. */
async function upload(files, consent = { acceptChanges: false, allowScripts: false }) {
  if (busy.value || !data.value?.can_install || !files.length) return;
  const problem = packageUploadError(files);
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
    const { data: installed } = await api.blocks.install(files[0], { ...consent, onProgress: (value) => { progress.value = value; } });
    if (current !== generation || workspace !== getActiveWorkspace()) return;
    pendingUpdate.value = null;
    pendingScripts.value = null;
    const result = await api.blocks.list();
    if (current === generation) {
      data.value = result.data;
      success.value = installed.previous_version
        ? t("site.blocks.updated", { name: installed.title, from: installed.previous_version, to: installed.version })
        : t("site.blocks.installed", { name: installed.title });
    }
  } catch (err) {
    if (current !== generation) return;
    pendingUpdate.value = null;
    pendingScripts.value = null;
    if (err.code === "block_package_changes" && err.details) {
      pendingUpdate.value = { ...err.details, file: files[0], consent };
    } else if (err.code === "block_package_scripts" && err.details) {
      pendingScripts.value = { ...err.details, file: files[0], consent };
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

function confirmUpdate() {
  const pending = pendingUpdate.value;
  if (pending) upload([pending.file], { ...pending.consent, acceptChanges: true });
}

function confirmScripts() {
  const pending = pendingScripts.value;
  if (pending) upload([pending.file], { ...pending.consent, allowScripts: true });
}

async function remove() {
  if (busy.value || !deleteTarget.value || !data.value?.can_install) return;
  const current = generation;
  const workspace = getActiveWorkspace();
  busy.value = true;
  error.value = null;
  success.value = "";
  try {
    await api.blocks.delete(deleteTarget.value.name);
    if (current !== generation || workspace !== getActiveWorkspace()) return;
    deleteTarget.value = null;
    const result = await api.blocks.list();
    if (current === generation) {
      data.value = result.data;
      success.value = t("site.blocks.deleted");
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
