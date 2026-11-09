<template>
  <div>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <p class="max-w-3xl text-sm text-slate-500">{{ t("workspaces.defaultNotice") }}</p>
      <button
        v-if="auth.can('workspaces.manage')"
        type="button"
        @click="showCreateForm = !showCreateForm"
        class="btn-primary shrink-0"
      >
        <Icon icon="mdi:plus" class="h-4 w-4" />
        {{ t("workspaces.new") }}
      </button>
    </div>

    <!-- Create form -->
    <div v-if="showCreateForm" class="card p-6 mb-6">
      <h2 class="card-title mb-4">
        {{ t("workspaces.createTitle") }}
      </h2>
      <form
        @submit.prevent="handleCreate"
        class="space-y-4"
      >
        <div class="grid gap-4 sm:grid-cols-2">
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
        </div>
        <WorkspaceLanguageFields v-model="createLanguages" />
        <fieldset class="space-y-2">
          <legend class="form-label">{{ t("setup.startingPoint") }}</legend>
          <div class="grid gap-3 sm:grid-cols-2">
            <label
              v-for="option in startingPoints"
              :key="option.value"
              class="flex cursor-pointer items-start gap-3 rounded-lg border p-4"
              :class="createStarterTemplate === option.value ? 'border-theme-300 bg-theme-50' : 'border-slate-200'"
            >
              <input v-model="createStarterTemplate" type="radio" name="workspace-starter-template" :value="option.value" class="mt-0.5 text-theme-600 focus:ring-theme-500" />
              <span>
                <span class="block text-sm font-medium text-slate-800">{{ t(option.title) }}</span>
                <span class="mt-1 block text-xs text-slate-500">{{ t(option.description) }}</span>
              </span>
            </label>
          </div>
        </fieldset>
        <div class="flex justify-end gap-2">
          <button type="submit" :disabled="creating || !createLanguages.locales.length" class="btn-primary">
            {{ installingFonts ? t("site.fonts.installingDemo") : creating ? t("common.saving") : t("workspaces.create") }}
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
      <p v-if="createError" class="mt-3 text-sm text-red-600" role="alert">
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
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div
              class="flex min-w-0 items-center gap-4"
              :class="editingSlug === workspace.slug ? 'w-full max-w-xl' : ''"
            >
              <AvatarUpload
                :src="
                  workspace.has_icon
                    ? `${ADMIN_API_BASE}/workspaces/${workspace.slug}/icon?v=${iconVersion[workspace.slug] ?? 0}`
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
              <div class="min-w-0 flex-1">
                <template v-if="editingSlug === workspace.slug">
                  <label :for="`workspace-label-${workspace.slug}`" class="form-label">{{ t('workspaces.label') }}</label>
                  <input
                    :id="`workspace-label-${workspace.slug}`"
                    v-model="editForm.label"
                    type="text"
                    class="form-input w-full rounded-lg border-slate-300 text-sm"
                    @keydown.enter.prevent="handleUpdate(workspace.slug)"
                    @keydown.escape="cancelEdit"
                    ref="editInput"
                  />
                </template>
                <template v-else>
                  <div class="flex flex-wrap items-center gap-2">
                  <span class="break-words font-medium text-slate-900">{{
                    workspace.label
                  }}</span>
                  <span
                    v-if="workspace.slug === activeWorkspace"
                    class="badge badge-dot bg-theme-50 text-theme-700 ring-theme-600/20"
                  >
                    {{ t("workspaces.active") }}
                  </span>
                  <span
                    v-if="workspace.default"
                    class="badge bg-emerald-50 text-emerald-700 ring-emerald-600/20"
                  >
                    {{ t("workspaces.default") }}
                  </span>
                  </div>
                </template>
                <div class="mt-1 break-all font-mono text-xs text-slate-400">
                  {{ workspace.slug }}
                </div>
              </div>
            </div>

            <div v-if="editingSlug !== workspace.slug" class="flex flex-wrap items-center gap-2 sm:justify-end">
                <button
                  v-if="auth.can('workspaces.manage')"
                  @click="startEdit(workspace)"
                  class="btn-secondary btn-sm"
                >
                  {{ t("workspaces.rename") }}
                </button>
                <button
                  v-if="auth.can('workspaces.manage') && !workspace.default"
                  @click="handleSetDefault(workspace.slug)"
                  :disabled="settingDefault === workspace.slug"
                  class="btn-secondary btn-sm"
                >
                  {{ t("workspaces.setAsDefault") }}
                </button>
                <button
                  v-if="auth.can('workspaces.manage') && workspace.has_icon"
                  @click="handleIconDelete(workspace.slug)"
                  class="btn-secondary btn-sm"
                >
                  {{ t("workspaces.removeIcon") }}
                </button>
                <button
                  v-if="
                    auth.can('workspaces.manage') &&
                    canArchiveWorkspace(workspace)
                  "
                  @click="confirmArchive(workspace)"
                  class="btn-danger-subtle btn-sm"
                >
                  {{ t("workspaces.archive") }}
                </button>
            </div>
          </div>
          <form
            v-if="editingSlug === workspace.slug"
            class="mt-5 space-y-5 border-t border-slate-100 pt-5"
            @submit.prevent="handleUpdate(workspace.slug)"
          >
            <p v-if="editError" class="text-sm text-red-600" role="alert">{{ editError }}</p>
            <div class="flex flex-wrap justify-end gap-2">
              <button type="button" @click="cancelEdit" class="btn-secondary">
                {{ t("common.cancel") }}
              </button>
              <button type="submit" :disabled="saving" class="btn-primary">
                {{ saving ? t("common.saving") : t("workspaces.save") }}
              </button>
            </div>
          </form>
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
import { ADMIN_API_BASE } from "../basePath.js";
import { nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import { Icon } from "@iconify/vue";
import AvatarUpload from "./AvatarUpload.vue";
import ConfirmModal from "./ConfirmModal.vue";
import LoadingSpinner from "./LoadingSpinner.vue";
import { api, getActiveWorkspace, setDefaultWorkspace } from "../api/index.js";
import { useSiteStore } from "../stores/site.js";
import { useContentTypesStore } from "../stores/contentTypes.js";
import { useAuthStore } from "../stores/auth.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import { buildAdminApiUrl } from "../composables/apiEndpoint.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";
import { useAutoSlug } from "../composables/useAutoSlug.js";
import { installStarterFonts } from "../composables/starterFonts.js";
import WorkspaceLanguageFields from "./WorkspaceLanguageFields.vue";

const toast = useToastStore();
const auth = useAuthStore();
const siteStore = useSiteStore();
const contentTypesStore = useContentTypesStore();
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
const installingFonts = ref(false);
const createError = ref("");
const createLabel = ref("");
const createLanguages = ref({ locales: ['en'], default_locale: 'en' });
const createStarterTemplate = ref("website");
const startingPoints = [
  { value: "website", title: "setup.startWebsite", description: "workspaces.startWebsiteBody" },
  { value: "empty", title: "setup.startEmpty", description: "workspaces.startEmptyBody" },
];
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
  if (!createLanguages.value.locales.length) return;
  createError.value = "";
  creating.value = true;
  try {
    const res = await api.workspaces.create({
      label: createLabel.value,
      slug: createSlug.value || undefined,
      starter_template: createStarterTemplate.value,
      languages: createLanguages.value,
    });
    if (createStarterTemplate.value === "website") {
      installingFonts.value = true;
      try {
        await installStarterFonts(res.data.slug);
      } catch {
        toast.error(t("site.fonts.demoFailed"));
      }
    }
    toast.success(t("workspaces.created"));
    createLabel.value = "";
    createLanguages.value = { locales: ['en'], default_locale: 'en' };
    createStarterTemplate.value = "website";
    resetCreateSlug();
    showCreateForm.value = false;
    await load();
    window.dispatchEvent(new CustomEvent(workspaceSyncEvent));
  } catch (err) {
    createError.value =
      err?.code === "workspace_archived"
        ? t("workspaces.slugArchived")
        : err?.message || t("workspaces.createFailed");
  } finally {
    installingFonts.value = false;
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
    await api.workspaces.update(slug, editForm.value);
    // The website settings carry the workspace label along when saving languages.
    if (slug === getActiveWorkspace()) { siteStore.invalidate(); contentTypesStore.invalidate(); }
    toast.success(t("workspaces.updated"));
    editingSlug.value = null;
    await load();
    window.dispatchEvent(new CustomEvent(workspaceSyncEvent));
  } catch (err) {
    editError.value = err?.message ?? t("workspaces.updateFailed");
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
    toast.error(err?.message ?? t("workspaces.iconUploadFailed"));
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
    toast.error(err?.message ?? t("workspaces.iconRemoveFailed"));
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
      err?.message ?? t("workspaces.setAsDefaultFailed"),
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
    toast.error(err?.message ?? t("workspaces.archiveFailed"));
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
