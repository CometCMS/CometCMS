<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">
          {{ t("updates.title") }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
          {{ t("updates.description") }}
        </p>
      </div>
      <button
        v-if="auth.can('updates.check')"
        type="button"
        class="btn-primary"
        :disabled="checking || !status?.enabled"
        @click="checkForUpdates"
      >
        {{ checking ? t("updates.checking") : t("updates.check") }}
      </button>
    </div>

    <div class="space-y-6">
      <div class="card p-6">
        <div v-if="loading" class="text-sm text-slate-500">
          {{ t("updates.loading") }}
        </div>

        <div v-else-if="status" class="space-y-6">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <div
                class="text-xs font-semibold uppercase tracking-wider text-slate-400"
              >
                {{ t("updates.currentVersion") }}
              </div>
              <div class="mt-1 text-2xl font-bold text-slate-900">
                v{{ status.current_version }}
              </div>
            </div>
            <div>
              <div
                class="text-xs font-semibold uppercase tracking-wider text-slate-400"
              >
                {{ t("updates.latestRelease") }}
              </div>
              <div
                class="mt-1 text-2xl font-bold"
                :class="status.latest ? 'text-slate-900' : 'text-slate-400'"
              >
                {{
                  status.latest
                    ? `v${status.latest.version}`
                    : t("updates.notChecked")
                }}
              </div>
            </div>
          </div>

          <div
            class="rounded-lg border px-4 py-3 text-sm"
            :class="
              status.update_available
                ? 'border-blue-200 bg-blue-50 text-blue-800'
                : 'border-slate-200 bg-slate-50 text-slate-600'
            "
          >
            {{ status.message || t("updates.ready") }}
          </div>

          <div v-if="status.latest" class="border-t border-slate-100 pt-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 class="text-base font-semibold text-slate-800">
                  {{ status.latest.name }}
                </h2>
                <p
                  v-if="status.latest.published_at"
                  class="text-sm text-slate-500"
                >
                  {{
                    t("updates.published", {
                      date: formatDate(status.latest.published_at),
                    })
                  }}
                </p>
              </div>
              <a
                :href="status.latest.url"
                target="_blank"
                rel="noreferrer"
                class="btn-secondary"
              >
                {{ t("updates.openRelease") }}
              </a>
              <button
                v-if="auth.can('updates.download') && status.update_available"
                type="button"
                class="btn-primary"
                :disabled="downloading || installing || !status.latest.asset"
                @click="
                  stagedUpdate ? (showInstallModal = true) : downloadUpdate()
                "
              >
                {{ updateButtonLabel }}
              </button>
            </div>

            <div
              v-if="status.latest.asset"
              class="mt-4 rounded-lg bg-slate-50 border border-slate-200 p-4"
            >
              <div class="text-sm font-medium text-slate-700">
                {{ status.latest.asset.name }}
              </div>
              <div class="mt-1 text-xs text-slate-500">
                {{ formatBytes(status.latest.asset.size) }}
              </div>
              <div
                v-if="status.latest.checksum_asset"
                class="mt-2 text-xs text-green-700"
              >
                {{
                  t("updates.checksumAvailable", {
                    name: status.latest.checksum_asset.name,
                  })
                }}
              </div>
              <div v-else class="mt-2 text-xs text-amber-700">
                {{ t("updates.noChecksum") }}
              </div>
            </div>

            <div
              v-else-if="status.update_available"
              class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
              {{ t("updates.noZip") }}
            </div>
          </div>

          <div
            v-if="!auth.can('updates.check')"
            class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
          >
            {{ t("updates.noPermission") }}
          </div>

          <div
            v-if="stagedUpdate"
            class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
          >
            <div class="font-medium">
              {{
                t("updates.downloadedVerified", {
                  version: stagedUpdate.version,
                })
              }}
            </div>
            <div class="mt-1">
              {{
                t("updates.downloadedAt", {
                  date: formatDate(stagedUpdate.downloaded_at),
                })
              }}
            </div>
            <code class="mt-2 block break-all text-xs">{{
              stagedUpdate.checksum_sha256
            }}</code>
          </div>
        </div>

        <div v-else class="text-sm text-red-600">
          {{ t("updates.loadFailed") }}
        </div>
      </div>

      <div class="card p-6">
        <h2 class="text-sm font-semibold text-slate-700 mb-3">
          {{ t("updates.preservedContent") }}
        </h2>
        <ul class="space-y-2 text-sm text-slate-600">
          <li
            v-for="path in preservedPaths"
            :key="path"
            class="flex items-center gap-2"
          >
            <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
            <code class="text-xs bg-slate-100 px-1.5 py-0.5 rounded">{{
              path
            }}</code>
          </li>
        </ul>
      </div>
    </div>

    <ConfirmModal
      v-model="showInstallModal"
      :title="t('updates.installTitle')"
      :message="t('updates.installMessage')"
      :confirm-label="t('updates.install')"
      :loading-label="t('updates.installing')"
      :loading="installing"
      variant="warning"
      @confirm="installUpdate"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import { api } from "../api/index.js";
import { useAuthStore } from "../stores/auth.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";

const auth = useAuthStore();
const toast = useToastStore();
const { t } = useI18n();

const loading = ref(true);
const checking = ref(false);
const downloading = ref(false);
const installing = ref(false);
const showInstallModal = ref(false);
const status = ref(null);

const preservedPaths = computed(() =>
  status.value?.preserved_paths?.length
    ? status.value.preserved_paths
    : ["storage"],
);
const stagedUpdate = computed(() => status.value?.staged_update ?? null);
const updateButtonLabel = computed(() => {
  if (stagedUpdate.value)
    return installing.value
      ? t("updates.installing")
      : t("updates.installStaged");
  return downloading.value ? t("updates.downloading") : t("updates.download");
});

onMounted(loadStatus);

async function loadStatus() {
  loading.value = true;
  try {
    const res = await api.update.status();
    status.value = res.data;
  } catch (err) {
    toast.error(err.message ?? t("updates.loadFailed"));
  } finally {
    loading.value = false;
  }
}

async function checkForUpdates() {
  checking.value = true;
  try {
    const res = await api.update.check();
    status.value = res.data;
    if (res.data.update_available) {
      toast.success(t("updates.newAvailable"));
    } else {
      toast.success(t("updates.upToDate"));
    }
  } catch (err) {
    toast.error(err.message ?? t("updates.checkFailed"));
  } finally {
    checking.value = false;
  }
}

async function downloadUpdate() {
  downloading.value = true;
  try {
    const res = await api.update.download();
    status.value = {
      ...status.value,
      staged_update: res.data,
    };
    toast.success(t("updates.downloadedToast", { version: res.data.version }));
  } catch (err) {
    toast.error(err.message ?? t("updates.downloadFailed"));
  } finally {
    downloading.value = false;
  }
}

async function installUpdate() {
  if (installing.value) return;

  installing.value = true;
  try {
    const res = await api.update.install(stagedUpdate.value?.id ?? null);
    showInstallModal.value = false;
    toast.success(
      t("updates.installedToast", { version: res.data.installed_version }),
    );
    window.setTimeout(() => {
      window.location.reload();
    }, 500);
  } catch (err) {
    toast.error(err.message ?? t("updates.installFailed"));
    installing.value = false;
  }
}

function formatDate(value) {
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}

function formatBytes(bytes) {
  if (!bytes) return t("updates.unknownSize");

  const units = ["B", "KB", "MB", "GB"];
  let size = bytes;
  let unit = 0;

  while (size >= 1024 && unit < units.length - 1) {
    size = size / 1024;
    unit++;
  }

  return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}
</script>
