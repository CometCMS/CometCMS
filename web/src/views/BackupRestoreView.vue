<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-slate-900">{{ t("backup.title") }}</h1>
      <div class="flex items-center gap-2">
        <button
          type="button"
          class="btn-secondary"
          :disabled="loadingBackups"
          @click="loadBackups"
        >
          <Icon icon="mdi:refresh" class="w-4 h-4" />
          {{ t("backup.refresh") }}
        </button>
        <button
          type="button"
          class="btn-secondary"
          :class="
            activePanel === 'upload' ? 'ring-2 ring-slate-300 bg-slate-50' : ''
          "
          @click="togglePanel('upload')"
        >
          <Icon icon="mdi:upload" class="w-4 h-4" />
          {{ t("backup.upload") }}
        </button>
        <button
          type="button"
          class="btn-primary"
          :class="
            activePanel === 'create'
              ? 'ring-2 ring-theme-400 ring-offset-1'
              : ''
          "
          @click="togglePanel('create')"
        >
          <Icon icon="mdi:archive-plus-outline" class="w-4 h-4" />
          {{ t("backup.newBackup") }}
        </button>
      </div>
    </div>

    <!-- Collapsible: Create Backup -->
    <div v-if="activePanel === 'create'" class="card p-6 mb-6">
      <div class="flex items-start justify-between gap-4 mb-5">
        <div>
          <h2 class="text-base font-semibold text-slate-800 mb-0.5">
            {{ t("backup.newBackupTitle") }}
          </h2>
          <p class="text-sm text-slate-500">
            {{ t("backup.newBackupDescription") }}
          </p>
        </div>
        <button
          type="button"
          class="p-1 text-slate-400 hover:text-slate-600"
          @click="activePanel = null"
        >
          <Icon icon="mdi:close" class="w-5 h-5" />
        </button>
      </div>
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 mb-5">
        <label
          v-for="part in partOptions"
          :key="part.value"
          class="flex items-start gap-2.5 rounded-lg border px-3 py-3 cursor-pointer transition-colors"
          :class="
            backupParts[part.value]
              ? 'border-theme-300 bg-theme-50/50'
              : 'border-slate-200 hover:border-theme-300 hover:bg-theme-50/40'
          "
        >
          <input
            type="checkbox"
            v-model="backupParts[part.value]"
            class="mt-0.5 form-checkbox rounded border-slate-300 text-theme-600 flex-none"
          />
          <span class="min-w-0">
            <span class="block text-sm font-medium text-slate-700">{{
              partLabel(part)
            }}</span>
            <span class="block text-xs text-slate-500 mt-0.5">{{
              partDescription(part)
            }}</span>
          </span>
        </label>
      </div>
      <div
        class="flex items-center gap-4 flex-wrap border-t border-slate-100 pt-4"
      >
        <label class="inline-flex items-center gap-2 cursor-pointer">
          <input
            type="checkbox"
            v-model="downloadAfterCreate"
            class="form-checkbox rounded border-slate-300 text-theme-600"
          />
          <span class="text-sm text-slate-600">{{
            t("backup.downloadAfterCreate")
          }}</span>
        </label>
        <button
          type="button"
          :disabled="creatingBackup"
          class="btn-primary"
          @click="createBackup"
        >
          <Icon icon="mdi:archive-arrow-down-outline" class="w-4 h-4" />
          {{ creatingBackup ? t("backup.creating") : t("backup.createBackup") }}
        </button>
        <p v-if="backupError" class="w-full text-sm text-red-600">
          {{ backupError }}
        </p>
      </div>
    </div>

    <!-- Collapsible: Upload -->
    <div v-if="activePanel === 'upload'" class="card p-6 mb-6">
      <div class="flex items-start justify-between gap-4 mb-5">
        <div>
          <h2 class="text-base font-semibold text-slate-800 mb-0.5">
            {{ t("backup.uploadTitle") }}
          </h2>
          <p class="text-sm text-slate-500">
            {{ t("backup.uploadDescription") }}
          </p>
        </div>
        <button
          type="button"
          class="p-1 text-slate-400 hover:text-slate-600"
          @click="activePanel = null"
        >
          <Icon icon="mdi:close" class="w-5 h-5" />
        </button>
      </div>
      <form
        class="flex items-center gap-4 flex-wrap"
        @submit.prevent="uploadBackup"
      >
        <label
          class="flex flex-1 min-w-[240px] items-center gap-3 rounded-xl border-2 border-dashed border-slate-200 px-5 py-4 cursor-pointer transition-colors hover:border-theme-300 hover:bg-theme-50/30"
          @dragover.prevent
          @drop.prevent="onFileDrop"
        >
          <Icon
            icon="mdi:archive-upload-outline"
            class="w-6 h-6 text-slate-400 flex-none"
          />
          <span class="text-sm text-slate-600 min-w-0 truncate">
            {{ selectedFile ? selectedFile.name : t("backup.dropZip") }}
          </span>
          <input
            ref="uploadInput"
            type="file"
            accept=".zip"
            class="sr-only"
            @change="onFileChange"
          />
        </label>
        <button
          type="submit"
          :disabled="uploadingBackup || !selectedFile"
          class="btn-primary flex-none"
        >
          <Icon icon="mdi:upload" class="w-4 h-4" />
          {{
            uploadingBackup ? t("backup.uploading") : t("backup.uploadInspect")
          }}
        </button>
        <p v-if="uploadError" class="w-full text-sm text-red-600">
          {{ uploadError }}
        </p>
        <!-- Upload progress bar -->
        <div v-if="uploadingBackup" class="w-full mt-1">
          <div
            class="flex items-center justify-between text-xs text-slate-500 mb-1"
          >
            <span>{{ t("backup.uploading") }}</span>
            <span>{{ uploadProgress }}%</span>
          </div>
          <div class="h-2 rounded-full bg-slate-200 overflow-hidden">
            <div
              class="h-full bg-theme-500 transition-all duration-150"
              :style="{ width: uploadProgress + '%' }"
            />
          </div>
        </div>
      </form>
    </div>

    <!-- File explorer: Backup list + Restore Preview -->
    <div class="card overflow-hidden flex" style="min-height: 520px">
      <!-- Left: backup list -->
      <div class="w-72 flex-none flex flex-col border-r border-slate-200">
        <div
          class="px-4 py-3 border-b border-slate-100 bg-slate-50/60 flex items-center justify-between"
        >
          <span
            class="text-xs font-semibold uppercase tracking-wide text-slate-500"
            >{{ t("backup.savedBackups") }}</span
          >
          <span
            class="text-xs font-medium text-slate-400 bg-white border border-slate-200 rounded-full px-2 py-0.5"
            >{{ backups.length }}</span
          >
        </div>
        <div class="flex-1 overflow-y-auto">
          <div
            v-if="loadingBackups"
            class="flex items-center justify-center gap-2 py-10 text-sm text-slate-400"
          >
            <Icon icon="mdi:loading" class="w-4 h-4 animate-spin" />
            {{ t("backup.loading") }}
          </div>
          <div
            v-else-if="backups.length === 0"
            class="flex flex-col items-center justify-center gap-2 py-12 px-6 text-center"
          >
            <Icon
              icon="mdi:archive-off-outline"
              class="w-8 h-8 text-slate-300"
            />
            <p class="text-sm text-slate-400">{{ t("backup.noBackups") }}</p>
            <p class="text-xs text-slate-400">
              {{ t("backup.noBackupsHint") }}
            </p>
          </div>
          <div v-else class="divide-y divide-slate-100">
            <div
              v-for="backup in backups"
              :key="backup.name"
              class="group flex items-center gap-3 px-4 py-3 cursor-pointer transition-colors border-l-2"
              :class="
                selectedBackup?.name === backup.name
                  ? 'bg-theme-50 border-l-theme-500'
                  : 'hover:bg-slate-50 border-l-transparent'
              "
              @click="inspectBackup(backup)"
            >
              <Icon
                :icon="backup.is_protected ? 'mdi:star' : 'mdi:archive-outline'"
                class="w-4 h-4 flex-none"
                :class="
                  backup.is_protected ? 'text-amber-400' : 'text-slate-400'
                "
                :title="backup.is_protected ? t('backup.protected') : undefined"
              />
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-slate-800">
                  {{ backup.name }}
                </p>
                <p class="text-xs text-slate-500">
                  {{ formatDate(backup.created_at) }} ·
                  {{ formatBytes(backup.size) }}
                </p>
                <p
                  v-if="backup.note"
                  class="truncate text-xs text-amber-700 mt-0.5"
                >
                  {{ backup.note }}
                </p>
              </div>
              <div
                class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity"
                @click.stop
              >
                <button
                  type="button"
                  class="p-1 rounded hover:bg-slate-200 text-slate-500"
                  :title="t('backup.download')"
                  @click="downloadBackup(backup)"
                >
                  <Icon icon="mdi:download" class="w-3.5 h-3.5" />
                </button>
                <button
                  type="button"
                  class="p-1 rounded text-red-500 disabled:text-slate-300 disabled:cursor-not-allowed"
                  :class="backup.is_protected ? '' : 'hover:bg-red-100'"
                  :disabled="backup.is_protected"
                  :title="
                    backup.is_protected
                      ? t('backup.protectedDeleteHint')
                      : t('backup.delete')
                  "
                  @click="deleteBackup(backup)"
                >
                  <Icon icon="mdi:trash-can-outline" class="w-3.5 h-3.5" />
                </button>
              </div>
            </div>
          </div>
        </div>
        <p v-if="listError" class="px-4 pb-3 text-xs text-red-600">
          {{ listError }}
        </p>
      </div>

      <!-- Right: restore preview -->
      <div class="flex-1 min-w-0 flex flex-col overflow-y-auto">
        <!-- Empty state -->
        <div
          v-if="!inspection"
          class="flex flex-col items-center justify-center gap-3 flex-1 text-center px-8 py-12"
        >
          <Icon
            icon="mdi:archive-eye-outline"
            class="w-10 h-10 text-slate-300"
          />
          <p class="text-sm font-medium text-slate-400">
            {{ t("backup.noSelection") }}
          </p>
          <p class="text-xs text-slate-400 max-w-xs">
            {{ t("backup.noSelectionHint") }}
          </p>
        </div>

        <!-- Inspection -->
        <template v-else>
          <div
            class="flex items-center justify-between gap-3 px-6 py-4 border-b border-slate-100 bg-slate-50/40"
          >
            <div class="min-w-0 flex-1">
              <h2 class="text-sm font-semibold text-slate-800 truncate">
                {{ selectedBackup?.name }}
              </h2>
              <p class="text-xs text-slate-500 mt-0.5">
                {{ formatDate(inspection.manifest?.created_at) }}
              </p>
              <form
                class="mt-2 flex items-center gap-2"
                @submit.prevent="saveBackupNote"
              >
                <label for="backup-note" class="sr-only">{{
                  t("backup.note")
                }}</label>
                <input
                  id="backup-note"
                  v-model="noteDraft"
                  type="text"
                  maxlength="500"
                  class="form-input h-8 w-full rounded-md border-slate-300 text-xs focus:ring-theme-500 focus:border-theme-500"
                  :placeholder="t('backup.notePlaceholder')"
                />
                <button
                  type="submit"
                  class="btn-secondary h-8 px-3 py-0 text-xs whitespace-nowrap"
                  :disabled="savingNote"
                >
                  <Icon icon="mdi:content-save-outline" class="w-3.5 h-3.5" />
                  {{
                    savingNote ? t("backup.savingNote") : t("backup.saveNote")
                  }}
                </button>
              </form>
              <p class="text-[11px] text-slate-500 mt-1">
                {{
                  selectedBackup?.is_protected
                    ? t("backup.noteProtectedHint")
                    : t("backup.noteHint")
                }}
              </p>
              <p v-if="noteError" class="mt-1 text-xs text-red-600">
                {{ noteError }}
              </p>
            </div>
          </div>
          <div class="p-6 space-y-5">
            <div>
              <p class="form-label">{{ t("backup.includedData") }}</p>
              <div class="flex flex-wrap gap-1.5">
                <span
                  v-for="item in countItems"
                  :key="item.label"
                  class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 text-xs text-slate-700"
                >
                  <span class="font-semibold text-slate-900">{{
                    item.value
                  }}</span>
                  <span>{{ item.label }}</span>
                </span>
              </div>
            </div>

            <div v-if="inspection.content_types?.length">
              <p class="form-label">{{ t("backup.contentTypes") }}</p>
              <div class="flex flex-wrap gap-1.5">
                <span
                  v-for="type in inspection.content_types"
                  :key="type"
                  class="badge bg-slate-100 text-slate-700"
                  >{{ type }}</span
                >
              </div>
            </div>

            <div>
              <label class="form-label">{{ t("backup.whatToRestore") }}</label>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <label
                  v-for="part in partOptions"
                  :key="part.value"
                  class="flex items-start gap-3 rounded-lg border px-3 py-2 transition-colors"
                  :class="restoreOptionClass(part.value)"
                >
                  <input
                    type="checkbox"
                    v-model="restoreParts[part.value]"
                    :disabled="!isAvailable(part.value)"
                    class="mt-0.5 form-checkbox rounded border-slate-300 text-theme-600 disabled:opacity-50"
                  />
                  <span class="min-w-0">
                    <span class="block text-sm font-medium text-slate-700">
                      {{ partLabel(part) }}
                      <span class="text-slate-400 font-normal"
                        >({{ partCount(part.value) }})</span
                      >
                    </span>
                    <span class="block text-xs text-slate-500">{{
                      partDescription(part)
                    }}</span>
                  </span>
                </label>
              </div>
              <p
                v-if="
                  restoreParts.users &&
                  !inspection.manifest?.includes_password_hashes
                "
                class="mt-2 text-xs text-amber-700"
              >
                {{ t("backup.noPasswordHashes") }}
              </p>
            </div>

            <div class="flex justify-between">
              <label class="inline-flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  v-model="overwrite"
                  class="form-checkbox rounded border-slate-300 text-theme-600"
                />
                <span class="text-sm text-slate-600">{{
                  t("backup.overwrite")
                }}</span>
              </label>

              <button
                type="button"
                :disabled="restoringBackup"
                class="btn-danger"
                @click="restoreBackup"
              >
                <Icon icon="mdi:backup-restore" class="w-4 h-4" />
                {{
                  restoringBackup
                    ? t("backup.restoring")
                    : t("backup.restoreSelected")
                }}
              </button>
            </div>

            <div
              v-if="restoreSummary"
              class="rounded-lg border border-green-200 bg-green-50 p-3"
            >
              <p class="text-sm font-medium text-green-800">
                {{ t("backup.restoreComplete") }}
              </p>
              <dl
                class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-green-900"
              >
                <template v-for="row in summaryRows" :key="row.label">
                  <dt>{{ row.label }}</dt>
                  <dd class="text-right font-medium">{{ row.value }}</dd>
                </template>
              </dl>
              <ul
                v-if="restoreSummary.errors?.length"
                class="mt-2 space-y-1 text-xs text-amber-800"
              >
                <li v-for="error in restoreSummary.errors" :key="error">
                  {{ error }}
                </li>
              </ul>
            </div>
            <p v-if="restoreError" class="text-sm text-red-600">
              {{ restoreError }}
            </p>
          </div>
        </template>
      </div>
    </div>

    <ConfirmModal
      v-model="showDeleteModal"
      :title="t('backup.deleteTitle')"
      :message="t('backup.deleteMessage', { name: pendingDeleteBackup?.name })"
      :confirm-label="t('backup.deleteConfirm')"
      :loading="deletingBackup"
      @confirm="confirmDeleteBackup"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import { Icon } from "@iconify/vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import { api } from "../api/index.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";

const toast = useToastStore();
const { t } = useI18n();
const backups = ref([]);
const selectedBackup = ref(null);
const inspection = ref(null);
const restoreSummary = ref(null);
const uploadInput = ref(null);
const selectedFile = ref(null);
const activePanel = ref(null);
const loadingBackups = ref(false);
const creatingBackup = ref(false);
const uploadingBackup = ref(false);
const uploadProgress = ref(0);
const restoringBackup = ref(false);
const savingNote = ref(false);
const downloadAfterCreate = ref(true);
const overwrite = ref(false);
const listError = ref("");
const backupError = ref("");
const uploadError = ref("");
const restoreError = ref("");
const noteError = ref("");
const noteDraft = ref("");

const partOptions = [
  {
    value: "content_types",
    labelKey: "backup.contentTypes",
    descriptionKey: "backup.partContentTypesDescription",
  },
  {
    value: "content",
    labelKey: "backup.typeEntries",
    descriptionKey: "backup.partContentDescription",
  },
  {
    value: "media",
    labelKey: "backup.media",
    descriptionKey: "backup.partMediaDescription",
  },
  {
    value: "webhooks",
    labelKey: "backup.webhooks",
    descriptionKey: "backup.partWebhooksDescription",
  },
  {
    value: "users",
    labelKey: "backup.users",
    descriptionKey: "backup.partUsersDescription",
  },
  {
    value: "api_tokens",
    labelKey: "backup.apiTokens",
    descriptionKey: "backup.partApiTokensDescription",
  },
];

const backupParts = reactive({
  content_types: true,
  content: true,
  media: false,
  webhooks: true,
  users: false,
  api_tokens: false,
});
const restoreParts = reactive({
  content_types: false,
  content: false,
  media: false,
  webhooks: false,
  users: false,
  api_tokens: false,
});

const selectedBackupParts = computed(() => selectedParts(backupParts));
const selectedRestoreParts = computed(() => selectedParts(restoreParts));

const countItems = computed(() => {
  const counts = inspection.value?.counts ?? {};
  return [
    { label: t("backup.contentTypes"), value: counts.content_types ?? 0 },
    { label: t("backup.entries"), value: counts.content ?? 0 },
    { label: t("backup.revisions"), value: counts.revisions ?? 0 },
    { label: t("backup.media"), value: counts.media ?? 0 },
    { label: t("backup.mediaMeta"), value: counts.media_meta ?? 0 },
    { label: t("backup.roles"), value: counts.roles ?? 0 },
    { label: t("backup.users"), value: counts.users ?? 0 },
    { label: t("backup.tokens"), value: counts.tokens ?? 0 },
    { label: t("backup.webhooks"), value: counts.webhooks ?? 0 },
  ];
});

const summaryRows = computed(() => {
  if (!restoreSummary.value) return [];

  return [
    [t("backup.contentTypes"), "restored_content_types"],
    [t("backup.entries"), "restored_content"],
    [t("backup.revisions"), "restored_revisions"],
    [t("backup.mediaFiles"), "restored_media"],
    [t("backup.mediaMetadata"), "restored_media_meta"],
    [t("backup.roles"), "restored_roles"],
    [t("backup.users"), "restored_users"],
    [t("backup.tokens"), "restored_tokens"],
    [t("backup.webhooks"), "restored_webhooks"],
    [t("backup.skipped"), "skipped"],
  ].map(([label, key]) => ({ label, value: restoreSummary.value[key] ?? 0 }));
});

onMounted(loadBackups);

function togglePanel(panel) {
  activePanel.value = activePanel.value === panel ? null : panel;
}

function onFileChange(e) {
  selectedFile.value = e.target.files?.[0] ?? null;
}

function onFileDrop(e) {
  selectedFile.value = e.dataTransfer.files?.[0] ?? null;
}

function selectedParts(source) {
  return partOptions
    .filter((part) => source[part.value])
    .map((part) => part.value);
}

function partLabel(part) {
  return t(part.labelKey);
}

function partDescription(part) {
  return t(part.descriptionKey);
}

async function loadBackups() {
  loadingBackups.value = true;
  listError.value = "";
  try {
    const res = await api.backups.list();
    backups.value = res.data.backups ?? [];
    if (selectedBackup.value) {
      const current = backups.value.find(
        (backup) => backup.name === selectedBackup.value.name,
      );
      if (current) selectedBackup.value = current;
    }
  } catch (err) {
    listError.value = err.message;
  } finally {
    loadingBackups.value = false;
  }
}

async function createBackup() {
  backupError.value = "";
  restoreSummary.value = null;
  if (selectedBackupParts.value.length === 0) {
    backupError.value = t("backup.selectInclude");
    return;
  }

  creatingBackup.value = true;
  try {
    const res = await api.backups.create(selectedBackupParts.value);
    const backup = res.data.backup;
    toast.success(t("backup.created"));
    activePanel.value = null;
    await loadBackups();
    await inspectBackup(backup);
    if (downloadAfterCreate.value) await api.backups.download(backup.name);
  } catch (err) {
    backupError.value = err.message;
  } finally {
    creatingBackup.value = false;
  }
}

async function uploadBackup() {
  uploadError.value = "";
  restoreSummary.value = null;
  const file = selectedFile.value;
  if (!file) return;

  uploadingBackup.value = true;
  uploadProgress.value = 0;
  const fd = new FormData();
  fd.append("backup", file);
  try {
    const res = await api.backups.upload(fd, (p) => {
      uploadProgress.value = Math.round(p * 100);
    });
    selectedBackup.value = res.data.backup;
    setInspection(res.data.inspection);
    toast.success(t("backup.uploaded"));
    selectedFile.value = null;
    if (uploadInput.value) uploadInput.value.value = "";
    activePanel.value = null;
    await loadBackups();
  } catch (err) {
    uploadError.value = err.message;
  } finally {
    uploadingBackup.value = false;
    uploadProgress.value = 0;
  }
}

async function inspectBackup(backup) {
  restoreError.value = "";
  noteError.value = "";
  restoreSummary.value = null;
  selectedBackup.value = backup;
  noteDraft.value = backup.note ?? "";
  try {
    const res = await api.backups.inspect(backup.name);
    setInspection(res.data.inspection);
  } catch (err) {
    restoreError.value = err.message;
  }
}

function setInspection(nextInspection) {
  inspection.value = nextInspection;
  for (const part of partOptions) {
    restoreParts[part.value] = (nextInspection.default_parts ?? []).includes(
      part.value,
    );
  }
}

async function saveBackupNote() {
  noteError.value = "";
  const backup = selectedBackup.value;
  if (!backup) return;

  savingNote.value = true;
  try {
    const res = await api.backups.note(backup.name, noteDraft.value);
    const updated = res.data.backup;
    selectedBackup.value = updated;
    noteDraft.value = updated.note ?? "";
    backups.value = backups.value.map((item) =>
      item.name === updated.name ? updated : item,
    );
    toast.success(t("backup.noteSaved"));
  } catch (err) {
    noteError.value = err.message;
  } finally {
    savingNote.value = false;
  }
}

async function restoreBackup() {
  restoreError.value = "";
  restoreSummary.value = null;
  if (!selectedBackup.value) return;
  if (selectedRestoreParts.value.length === 0) {
    restoreError.value = t("backup.selectRestore");
    return;
  }

  restoringBackup.value = true;
  try {
    const res = await api.backups.restore(selectedBackup.value.name, {
      parts: selectedRestoreParts.value,
      overwrite: overwrite.value,
    });
    restoreSummary.value = res.data.summary;
    toast.success(t("backup.restoreComplete"));
  } catch (err) {
    restoreError.value = err.message;
  } finally {
    restoringBackup.value = false;
  }
}

async function downloadBackup(backup) {
  await api.backups.download(backup.name);
}

async function deleteBackup(backup) {
  if (backup.is_protected) return;
  pendingDeleteBackup.value = backup;
  showDeleteModal.value = true;
}

const showDeleteModal = ref(false);
const pendingDeleteBackup = ref(null);
const deletingBackup = ref(false);

async function confirmDeleteBackup() {
  const backup = pendingDeleteBackup.value;
  if (!backup) return;
  deletingBackup.value = true;
  listError.value = "";
  try {
    await api.backups.delete(backup.name);
    showDeleteModal.value = false;
    pendingDeleteBackup.value = null;
    if (selectedBackup.value?.name === backup.name) {
      selectedBackup.value = null;
      inspection.value = null;
      restoreSummary.value = null;
    }
    await loadBackups();
  } catch (err) {
    listError.value = err.message;
  } finally {
    deletingBackup.value = false;
  }
}

function isAvailable(part) {
  return (inspection.value?.available_parts ?? []).includes(part);
}

function partCount(part) {
  const counts = inspection.value?.counts ?? {};
  if (part === "content_types") return counts.content_types ?? 0;
  if (part === "content")
    return (counts.content ?? 0) + (counts.revisions ?? 0);
  if (part === "media") return (counts.media ?? 0) + (counts.media_meta ?? 0);
  if (part === "users")
    return (counts.users ?? 0) + (counts.roles ?? 0) + (counts.tokens ?? 0);
  if (part === "webhooks") return counts.webhooks ?? 0;
  return 0;
}

function restoreOptionClass(part) {
  if (!isAvailable(part))
    return "border-slate-200 bg-slate-50 opacity-60 cursor-not-allowed";
  return restoreParts[part]
    ? "border-theme-300 bg-theme-50/50 cursor-pointer"
    : "border-slate-200 cursor-pointer hover:border-theme-300 hover:bg-theme-50/40";
}

function formatBytes(bytes) {
  const value = Number(bytes ?? 0);
  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
  return `${(value / 1024 / 1024).toFixed(1)} MB`;
}

function formatDate(value) {
  if (!value) return t("backup.unknownDate");
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}
</script>
