<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-slate-900">
        {{ t("workspaces.title") }}
      </h1>
      <button
        v-if="auth.can('workspaces.manage')"
        @click="showCreateForm = !showCreateForm"
        class="btn-primary"
      >
        {{ t("workspaces.new") }}
      </button>
    </div>

    <!-- Default workspace notice -->
    <div class="card p-4 mb-6 flex items-start gap-3 text-sm text-slate-600">
      <Icon
        icon="mdi:information-outline"
        class="w-4 h-4 mt-0.5 shrink-0 text-slate-400"
      />
      <p>{{ t("workspaces.defaultNotice") }}</p>
    </div>

    <!-- Create form -->
    <div v-if="showCreateForm" class="card p-6 mb-6">
      <h2 class="text-sm font-semibold text-slate-700 mb-4">
        {{ t("workspaces.createTitle") }}
      </h2>
      <form
        @submit.prevent="handleCreate"
        class="flex flex-col gap-4 sm:flex-row sm:items-end"
      >
        <div class="flex-1">
          <label class="form-label">{{ t("workspaces.label") }}</label>
          <input
            v-model="createLabel"
            type="text"
            required
            :placeholder="t('workspaces.labelPlaceholder')"
            class="form-input w-full rounded-lg border-slate-300 text-sm"
          />
        </div>
        <div class="flex-1">
          <label class="form-label"
            >{{ t("workspaces.slug") }}
            <span class="text-slate-400 font-normal text-xs">{{
              t("workspaces.slugHint")
            }}</span></label
          >
          <input
            v-model="createSlug"
            type="text"
            :placeholder="t('workspaces.slugPlaceholder')"
            class="form-input w-full rounded-lg border-slate-300 text-sm font-mono"
            @input="onCreateSlugInput"
          />
        </div>
        <div class="flex gap-2 shrink-0">
          <button type="submit" :disabled="creating" class="btn-primary">
            {{ creating ? t("common.saving") : t("workspaces.create") }}
          </button>
          <button
            type="button"
            @click="showCreateForm = false"
            class="btn-secondary"
          >
            {{ t("common.cancel") }}
          </button>
        </div>
      </form>
      <p v-if="createError" class="mt-3 text-sm text-red-600">
        {{ createError }}
      </p>
    </div>

    <LoadingSpinner v-if="loading" />

    <template v-else>
      <div
        v-if="workspaces.length === 0"
        class="card p-6 text-sm text-slate-400"
      >
        {{ t("workspaces.empty") }}
      </div>

      <div v-else class="space-y-3">
        <div
          v-for="workspace in workspaces"
          :key="workspace.slug"
          class="card p-5"
        >
          <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
              <AvatarUpload
                :src="
                  workspace.has_icon
                    ? `/admin/api/workspaces/${workspace.slug}/icon?v=${iconVersion[workspace.slug] ?? 0}`
                    : null
                "
                :fallback="workspaceInitials(workspace.label)"
                size="sm"
                shape="rounded"
                hide-description
                :upload-title="t('workspaces.uploadIcon')"
                :remove-label="t('workspaces.removeIcon')"
                @upload="(file) => handleIconUpload(workspace.slug, file)"
                @delete="handleIconDelete(workspace.slug)"
              />
              <div class="min-w-0">
                <template v-if="editingSlug === workspace.slug">
                  <input
                    v-model="editForm.label"
                    type="text"
                    class="form-input rounded-lg border-slate-300 text-sm w-48"
                    @keydown.enter.prevent="handleUpdate(workspace.slug)"
                    @keydown.escape="cancelEdit"
                    ref="editInput"
                  />
                </template>
                <template v-else>
                  <span class="font-medium text-slate-800">{{
                    workspace.label
                  }}</span>
                  <span
                    v-if="workspace.slug === activeWorkspace"
                    class="ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-theme-100 text-theme-700"
                  >
                    {{ t("workspaces.active") }}
                  </span>
                  <span
                    v-if="workspace.default"
                    class="ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-700"
                  >
                    {{ t("workspaces.default") }}
                  </span>
                </template>
                <div class="text-xs text-slate-400 font-mono mt-0.5">
                  {{ workspace.slug }}
                </div>
              </div>
            </div>

            <div class="flex items-center gap-2 shrink-0">
              <template v-if="editingSlug === workspace.slug">
                <button
                  @click="handleUpdate(workspace.slug)"
                  :disabled="saving"
                  class="btn-primary text-xs"
                >
                  {{ saving ? t("common.saving") : t("workspaces.save") }}
                </button>
                <button @click="cancelEdit" class="btn-secondary text-xs">
                  {{ t("common.cancel") }}
                </button>
              </template>
              <template v-else>
                <button
                  v-if="auth.can('workspaces.manage')"
                  @click="startEdit(workspace)"
                  class="btn-secondary text-xs"
                >
                  {{ t("workspaces.rename") }}
                </button>
                <button
                  v-if="auth.can('workspaces.manage') && !workspace.default"
                  @click="handleSetDefault(workspace.slug)"
                  :disabled="settingDefault === workspace.slug"
                  class="btn-secondary text-xs"
                >
                  {{ t("workspaces.setAsDefault") }}
                </button>
                <button
                  v-if="auth.can('workspaces.manage') && workspace.has_icon"
                  @click="handleIconDelete(workspace.slug)"
                  class="text-xs text-slate-500 hover:text-slate-700 transition-colors"
                >
                  {{ t("workspaces.removeIcon") }}
                </button>
                <button
                  v-if="
                    auth.can('workspaces.manage') &&
                    canArchiveWorkspace(workspace)
                  "
                  @click="confirmArchive(workspace)"
                  class="text-xs text-red-500 hover:text-red-700 transition-colors"
                >
                  {{ t("workspaces.archive") }}
                </button>
              </template>
            </div>
          </div>
          <p
            v-if="editError && editingSlug === workspace.slug"
            class="mt-2 text-sm text-red-600"
          >
            {{ editError }}
          </p>
        </div>
      </div>
    </template>

    <ConfirmModal
      v-model="showArchiveModal"
      :title="t('workspaces.archiveTitle')"
      :message="
        t('workspaces.archiveMessage', { label: pendingArchive?.label ?? '' })
      "
      :confirm-label="t('workspaces.archiveConfirm')"
      :loading="archiving"
      @confirm="doArchive"
    />
  </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import { Icon } from "@iconify/vue";
import AvatarUpload from "../components/AvatarUpload.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import LoadingSpinner from "../components/LoadingSpinner.vue";
import { api, getActiveWorkspace, setDefaultWorkspace } from "../api/index.js";
import { useAuthStore } from "../stores/auth.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import { buildAdminApiUrl } from "../composables/apiEndpoint.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";
import { useAutoSlug } from "../composables/useAutoSlug.js";

const toast = useToastStore();
const auth = useAuthStore();
const { t } = useI18n();
const apiEndpointStore = useApiEndpointStore();
const apiEndpointOwner = "workspaces";
const workspaceSyncEvent = "cometcms:workspaces-updated";

const loading = ref(true);
const workspaces = ref([]);
const activeWorkspace = ref(getActiveWorkspace());

// Create
const showCreateForm = ref(false);
const creating = ref(false);
const createError = ref("");
const createLabel = ref("");
const {
  slug: createSlug,
  onSlugInput: onCreateSlugInput,
  reset: resetCreateSlug,
} = useAutoSlug(createLabel);

// Edit
const editingSlug = ref(null);
const saving = ref(false);
const editError = ref("");
const editForm = ref({ label: "" });
const editInput = ref(null);

// Archive
const showArchiveModal = ref(false);
const archiving = ref(false);
const pendingArchive = ref(null);

// Set default
const settingDefault = ref(null);

// Icon
const iconVersion = ref({});

function canArchiveWorkspace(workspace) {
  return workspaces.value.length > 1 && !workspace.archived;
}

function workspaceInitials(label) {
  return String(label ?? "")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0].toUpperCase())
    .join("");
}

async function load() {
  loading.value = true;
  try {
    const res = await api.workspaces.list();
    workspaces.value = res.data ?? [];
    const defaultWs = workspaces.value.find((w) => w.default);
    if (defaultWs) {
      setDefaultWorkspace(defaultWs.slug);
    }
  } finally {
    loading.value = false;
  }
}

async function handleCreate() {
  createError.value = "";
  creating.value = true;
  try {
    await api.workspaces.create({
      label: createLabel.value,
      slug: createSlug.value || undefined,
    });
    toast.success(t("workspaces.created"));
    createLabel.value = "";
    resetCreateSlug();
    showCreateForm.value = false;
    await load();
    window.dispatchEvent(new CustomEvent(workspaceSyncEvent));
  } catch (err) {
    createError.value =
      err?.data?.error?.message ?? t("workspaces.createFailed");
  } finally {
    creating.value = false;
  }
}

function startEdit(workspace) {
  editingSlug.value = workspace.slug;
  editForm.value = { label: workspace.label };
  editError.value = "";
  nextTick(() => {
    if (editInput.value) {
      const el = Array.isArray(editInput.value)
        ? editInput.value[0]
        : editInput.value;
      el?.focus();
    }
  });
}

function cancelEdit() {
  editingSlug.value = null;
  editError.value = "";
}

async function handleUpdate(slug) {
  editError.value = "";
  saving.value = true;
  try {
    await api.workspaces.update(slug, { label: editForm.value.label });
    toast.success(t("workspaces.updated"));
    editingSlug.value = null;
    await load();
    window.dispatchEvent(new CustomEvent(workspaceSyncEvent));
  } catch (err) {
    editError.value = err?.data?.error?.message ?? t("workspaces.updateFailed");
  } finally {
    saving.value = false;
  }
}

function confirmArchive(workspace) {
  pendingArchive.value = workspace;
  showArchiveModal.value = true;
}

async function handleIconUpload(slug, file) {
  const formData = new FormData();
  formData.append("file", file);
  try {
    await api.workspaces.uploadIcon(slug, formData);
    iconVersion.value = { ...iconVersion.value, [slug]: Date.now() };
    const ws = workspaces.value.find((w) => w.slug === slug);
    if (ws) ws.has_icon = true;
    toast.success(t("workspaces.iconUpdated"));
    window.dispatchEvent(new CustomEvent(workspaceSyncEvent));
  } catch (err) {
    toast.error(err?.data?.error?.message ?? t("workspaces.iconUploadFailed"));
  }
}

async function handleIconDelete(slug) {
  try {
    await api.workspaces.deleteIcon(slug);
    iconVersion.value = { ...iconVersion.value, [slug]: Date.now() };
    const ws = workspaces.value.find((w) => w.slug === slug);
    if (ws) ws.has_icon = false;
    toast.success(t("workspaces.iconRemoved"));
    window.dispatchEvent(new CustomEvent(workspaceSyncEvent));
  } catch (err) {
    toast.error(err?.data?.error?.message ?? t("workspaces.iconRemoveFailed"));
  }
}

async function handleSetDefault(slug) {
  settingDefault.value = slug;
  try {
    await api.workspaces.setDefault(slug);
    toast.success(t("workspaces.setAsDefaultSuccess"));
    await load();
    window.dispatchEvent(new CustomEvent(workspaceSyncEvent));
  } catch (err) {
    toast.error(
      err?.data?.error?.message ?? t("workspaces.setAsDefaultFailed"),
    );
  } finally {
    settingDefault.value = null;
  }
}

async function doArchive() {
  archiving.value = true;
  try {
    await api.workspaces.archive(pendingArchive.value.slug);
    toast.success(t("workspaces.archived"));
    showArchiveModal.value = false;
    pendingArchive.value = null;
    await load();
    window.dispatchEvent(new CustomEvent(workspaceSyncEvent));
  } catch (err) {
    toast.error(err?.data?.error?.message ?? t("workspaces.archiveFailed"));
    showArchiveModal.value = false;
  } finally {
    archiving.value = false;
  }
}

onMounted(() => {
  apiEndpointStore.setEndpoint(
    { label: "Workspaces", url: buildAdminApiUrl("/workspaces") },
    apiEndpointOwner,
  );
  load();
});

onBeforeUnmount(() => {
  apiEndpointStore.clearEndpoint(apiEndpointOwner);
});
</script>
