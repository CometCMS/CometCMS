<template>
  <section class="card p-5 flex flex-col gap-4">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="text-sm font-semibold text-slate-900">
        {{ t("activity.title") }}
      </h2>

      <div class="flex flex-wrap items-center gap-2">
        <!-- Workspace filter -->
        <select
          v-if="workspaces.length > 1"
          v-model="workspaceFilter"
          class="rounded-lg border border-slate-200 bg-white py-1.5 pl-3 pr-8 text-xs text-slate-700 shadow-sm focus:border-theme-400 focus:outline-none focus:ring-1 focus:ring-theme-300"
          @change="reset"
        >
          <option value="">{{ t("activity.allWorkspaces") }}</option>
          <option v-for="ws in workspaces" :key="ws.slug" :value="ws.slug">
            {{ ws.label || ws.slug }}
          </option>
        </select>

        <!-- Type filter -->
        <select
          v-model="typeFilter"
          class="rounded-lg border border-slate-200 bg-white py-1.5 pl-3 pr-8 text-xs text-slate-700 shadow-sm focus:border-theme-400 focus:outline-none focus:ring-1 focus:ring-theme-300"
          @change="reset"
        >
          <option value="">{{ t("activity.allTypes") }}</option>
          <option value="content">{{ t("activity.content") }}</option>
          <option value="schema">{{ t("activity.schema") }}</option>
          <option value="media">{{ t("media.title") }}</option>
          <option value="user">{{ t("activity.usersTokens") }}</option>
          <option value="auth">{{ t("activity.auth") }}</option>
          <option value="system">{{ t("activity.system") }}</option>
        </select>

        <!-- Level filter -->
        <select
          v-model="levelFilter"
          class="rounded-lg border border-slate-200 bg-white py-1.5 pl-3 pr-8 text-xs text-slate-700 shadow-sm focus:border-theme-400 focus:outline-none focus:ring-1 focus:ring-theme-300"
          @change="reset"
        >
          <option value="">{{ t("activity.allLevels") }}</option>
          <option value="info">{{ t("activity.info") }}</option>
          <option value="warning">{{ t("activity.warning") }}</option>
          <option value="error">{{ t("activity.error") }}</option>
        </select>

        <!-- Refresh -->
        <button
          type="button"
          :title="t('activity.refresh')"
          class="rounded-lg border border-slate-200 bg-white p-1.5 text-slate-500 shadow-sm hover:bg-slate-50 hover:text-slate-700 transition-colors"
          :class="{ 'opacity-50 cursor-not-allowed': loading }"
          :disabled="loading"
          @click="load"
        >
          <Icon
            icon="mdi:refresh"
            class="h-4 w-4"
            :class="{ 'animate-spin': loading }"
          />
        </button>
      </div>
    </div>

    <!-- List -->
    <div
      v-if="loading && items.length === 0"
      class="py-6 text-center text-sm text-slate-400"
    >
      {{ t("activity.loading") }}
    </div>
    <div
      v-else-if="!loading && items.length === 0"
      class="py-6 text-center text-sm text-slate-400"
    >
      {{ t("activity.empty") }}
    </div>
    <ul v-else class="divide-y divide-slate-100">
      <li
        v-for="(item, i) in items"
        :key="i"
        class="flex items-start gap-3 py-2.5 text-sm"
      >
        <span
          class="mt-1 h-2 w-2 shrink-0 rounded-full"
          :class="dotClass(item)"
        />
        <span class="min-w-0 flex-1">
          <span class="font-medium text-slate-800">{{ label(item) }}</span>
          <span
            v-if="sub(item)"
            class="ml-1.5 text-slate-500 truncate text-xs"
            >{{ sub(item) }}</span
          >
        </span>
        <time
          class="shrink-0 text-xs text-slate-400 tabular-nums whitespace-nowrap"
          :title="item.time"
          >{{ relativeTime(item.time) }}</time
        >
      </li>
    </ul>

    <!-- Pagination -->
    <div
      v-if="meta.total > 0"
      class="flex items-center justify-between gap-3 pt-1"
    >
      <span class="text-xs text-slate-500">
        {{ meta.offset + 1 }}–{{
          Math.min(meta.offset + meta.limit, meta.total)
        }}
        {{ t("activity.of") }} {{ meta.total }}
      </span>
      <div class="flex gap-1.5">
        <button
          type="button"
          class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 shadow-sm hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          :disabled="meta.offset === 0 || loading"
          @click="prev"
        >
          <Icon icon="mdi:chevron-left" class="h-3.5 w-3.5" />
        </button>
        <button
          type="button"
          class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 shadow-sm hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          :disabled="meta.offset + meta.limit >= meta.total || loading"
          @click="next"
        >
          <Icon icon="mdi:chevron-right" class="h-3.5 w-3.5" />
        </button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, onMounted } from "vue";
import { Icon } from "@iconify/vue";
import { api } from "../api/index.js";
import { useI18n } from "../i18n/index.js";

const props = defineProps({
  /** How many items per page */
  limit: { type: Number, default: 10 },
});

const loading = ref(false);
const items = ref([]);
const typeFilter = ref("");
const levelFilter = ref("");
const workspaceFilter = ref("");
const workspaces = ref([]);
const meta = ref({ total: 0, limit: props.limit, offset: 0 });
const { t } = useI18n();

async function load() {
  loading.value = true;
  try {
    const params = { limit: meta.value.limit, offset: meta.value.offset };
    if (typeFilter.value) params.type = typeFilter.value;
    if (levelFilter.value) params.level = levelFilter.value;
    if (workspaceFilter.value) params.workspace = workspaceFilter.value;
    const res = await api.activity(params);
    items.value = res.data ?? [];
    meta.value = { ...meta.value, ...res.meta };
  } catch {
    // silently ignore
  } finally {
    loading.value = false;
  }
}

function reset() {
  meta.value.offset = 0;
  load();
}

function prev() {
  meta.value.offset = Math.max(0, meta.value.offset - meta.value.limit);
  load();
}

function next() {
  meta.value.offset += meta.value.limit;
  load();
}

// ── display helpers ──────────────────────────────────────────────────────────

const EVENT_LABELS = {
  "content.created": "activity.contentCreated",
  "content.updated": "activity.contentUpdated",
  "content.published": "activity.contentPublished",
  "content.unpublished": "activity.contentUnpublished",
  "content.deleted": "activity.contentDeleted",
  "content.restored": "activity.contentRestored",
  "content_type.created": "activity.contentTypeCreated",
  "content_type.updated": "activity.contentTypeUpdated",
  "content_type.deleted": "activity.contentTypeDeleted",
  "media.uploaded": "activity.mediaUploaded",
  "media.deleted": "activity.mediaDeleted",
  "media.bulk_deleted": "activity.mediaBulkDeleted",
  "user.created": "activity.userCreated",
  "user.updated": "activity.userUpdated",
  "user.deleted": "activity.userDeleted",
  "role.created": "activity.roleCreated",
  "role.updated": "activity.roleUpdated",
  "role.deleted": "activity.roleDeleted",
  "token.created": "activity.tokenCreated",
  "token.deleted": "activity.tokenDeleted",
  login: "activity.login",
  logout: "activity.logout",
  "failed login": "activity.failedLogin",
  "backup created": "activity.backupCreated",
  "backup restored": "activity.backupRestored",
  "backup uploaded": "activity.backupUploaded",
  "update downloaded": "activity.updateDownloaded",
  "update installed": "activity.updateInstalled",
  "webhook failed": "activity.webhookFailed",
  "content purged": "activity.contentPurged",
};

function label(item) {
  return EVENT_LABELS[item.message]
    ? t(EVENT_LABELS[item.message])
    : item.message;
}

function sub(item) {
  const ctx = item.context ?? {};
  if (ctx.type && ctx.slug) return `${ctx.type} / ${ctx.slug}`;
  if (ctx.name) return ctx.name;
  if (ctx.file) return ctx.file;
  if (ctx.role) return ctx.role;
  if (ctx.username) return ctx.username;
  if (ctx.count != null) return t("activity.files", { count: ctx.count });
  if (ctx.url) return ctx.url;
  return null;
}

function dotClass(item) {
  const msg = item.message ?? "";
  if (item.level === "error") return "bg-red-500";
  if (item.level === "warning" || msg === "failed login") return "bg-amber-400";
  if (
    msg.endsWith(".deleted") ||
    msg.endsWith(".bulk_deleted") ||
    msg === "content purged"
  )
    return "bg-red-400";
  if (
    msg.endsWith(".created") ||
    msg.endsWith(".uploaded") ||
    msg.endsWith(".restored") ||
    msg.endsWith(".published")
  )
    return "bg-emerald-400";
  return "bg-blue-400";
}

function relativeTime(iso) {
  if (!iso) return "";
  const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (diff < 60) return t("activity.secondsAgo", { count: diff });
  if (diff < 3600)
    return t("activity.minutesAgo", { count: Math.floor(diff / 60) });
  if (diff < 86400)
    return t("activity.hoursAgo", { count: Math.floor(diff / 3600) });
  return t("activity.daysAgo", { count: Math.floor(diff / 86400) });
}

onMounted(async () => {
  try {
    const res = await api.workspaces.list();
    workspaces.value = res.data ?? [];
  } catch {
    // silently ignore – workspace filter simply won't appear
  }
  load();
});
</script>
