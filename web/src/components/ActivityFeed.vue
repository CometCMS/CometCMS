<template>
  <section class="card p-5 flex flex-col gap-4">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="card-title">
        {{ t("activity.title") }}
      </h2>

      <div class="flex flex-wrap items-center gap-2">
        <!-- Workspace filter -->
        <select
          v-if="workspaces.length > 1"
          v-model="workspaceFilter"
          class="rounded-lg border border-slate-200 bg-white py-1.5 pl-3 pr-8 text-xs text-slate-700 shadow-sm focus:border-theme-400 focus:outline-none focus:ring-1 focus:ring-theme-300"
          @change="reset"
          :aria-label="t('activity.allWorkspaces')"
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
          :aria-label="t('activity.allTypes')"
        >
          <option value="">{{ t("activity.allTypes") }}</option>
          <option value="content">{{ t("activity.content") }}</option>
          <option value="schema">{{ t("activity.schema") }}</option>
          <option value="media">{{ t("media.title") }}</option>
          <option value="user">{{ t("activity.usersTokens") }}</option>
          <option value="auth">{{ t("activity.auth") }}</option>
          <option value="site">{{ t("site.title") }}</option>
          <option value="system">{{ t("activity.system") }}</option>
        </select>

        <!-- Level filter -->
        <select
          v-model="levelFilter"
          class="rounded-lg border border-slate-200 bg-white py-1.5 pl-3 pr-8 text-xs text-slate-700 shadow-sm focus:border-theme-400 focus:outline-none focus:ring-1 focus:ring-theme-300"
          @change="reset"
          :aria-label="t('activity.allLevels')"
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
      v-else-if="errorMessage"
      class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
      role="alert"
    >
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span>{{ errorMessage }}</span>
        <button type="button" class="font-medium underline" @click="load">
          {{ t("activity.retry") }}
        </button>
      </div>
    </div>
    <div
      v-else-if="!loading && items.length === 0"
      class="py-6 text-center text-sm text-slate-400"
    >
      {{ t("activity.empty") }}
    </div>
    <ul v-else>
      <li
        v-for="(item, i) in items"
        :key="i"
        class="relative flex items-start gap-3 py-2 text-sm"
      >
        <span
          v-if="i < items.length - 1"
          class="absolute -bottom-2 left-3.5 top-9 w-px bg-slate-200"
          aria-hidden="true"
        />
        <span
          class="relative flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-1 ring-inset"
          :class="iconClass(item)"
          aria-hidden="true"
        >
          <Icon :icon="eventIcon(item)" class="h-4 w-4" />
        </span>
        <span class="min-w-0 flex-1 pt-0.5 leading-5 [overflow-wrap:anywhere]">
          <span class="font-medium text-slate-800">{{ label(item) }}</span>
          <span
            v-if="sub(item)"
            class="ml-1.5 text-xs text-slate-500"
            >{{ sub(item) }}</span
          >
        </span>
        <time
          class="shrink-0 pt-1 text-xs text-slate-400 tabular-nums whitespace-nowrap"
          :datetime="item.time"
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
          :aria-label="t('activity.previousPage')"
        >
          <Icon icon="mdi:chevron-left" class="h-3.5 w-3.5" />
        </button>
        <button
          type="button"
          class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 shadow-sm hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          :disabled="meta.offset + meta.limit >= meta.total || loading"
          @click="next"
          :aria-label="t('activity.nextPage')"
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
const errorMessage = ref("");
const meta = ref({ total: 0, limit: props.limit, offset: 0 });
const { t } = useI18n();

async function load() {
  loading.value = true;
  errorMessage.value = "";
  try {
    const params = { limit: meta.value.limit, offset: meta.value.offset };
    if (typeFilter.value) params.type = typeFilter.value;
    if (levelFilter.value) params.level = levelFilter.value;
    if (workspaceFilter.value) params.workspace = workspaceFilter.value;
    const res = await api.activity(params);
    items.value = res.data ?? [];
    meta.value = { ...meta.value, ...res.meta };
  } catch (err) {
    errorMessage.value = err?.message || t("activity.loadFailed");
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
  "token.revoked": "activity.tokenRevoked",
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
  "site.settings_updated": "activity.siteSettingsUpdated",
  "site.theme_updated": "activity.siteThemeUpdated",
  "site.theme_preset_created": "activity.siteThemePresetCreated",
  "site.theme_preset_updated": "activity.siteThemePresetUpdated",
  "site.theme_preset_deleted": "activity.siteThemePresetDeleted",
  "site.templates_updated": "activity.siteTemplatesUpdated",
  "site.menus_updated": "activity.siteMenusUpdated",
  "site.layout_updated": "activity.siteLayoutUpdated",
  "site.layout_reset": "activity.siteLayoutReset",
  "site.page_updated": "activity.sitePageUpdated",
  "site.page_reset": "activity.sitePageReset",
  "site.region_updated": "activity.siteRegionUpdated",
  "site.region_reset": "activity.siteRegionReset",
  "site.component_created": "activity.siteComponentCreated",
  "site.component_updated": "activity.siteComponentUpdated",
  "site.component_renamed": "activity.siteComponentRenamed",
  "site.component_deleted": "activity.siteComponentDeleted",
  "site.form_created": "activity.siteFormCreated",
  "site.form_updated": "activity.siteFormUpdated",
  "site.form_deleted": "activity.siteFormDeleted",
  "site.form_submitted": "activity.siteFormSubmitted",
  "site.revision_restored": "activity.siteRevisionRestored",
};

function label(item) {
  return EVENT_LABELS[item.message]
    ? t(EVENT_LABELS[item.message])
    : item.message;
}

function sub(item) {
  const ctx = item.context ?? {};
  if (ctx.type && ctx.slug) return `${ctx.type} / ${ctx.slug}`;
  if (ctx.type && ctx.variant) return `${ctx.type} · ${t(`site.variant.${ctx.variant}`)}`;
  if (ctx.region) return t(`site.region.${ctx.region}`);
  if (ctx.target === "theme") return t("activity.siteTheme");
  if (ctx.component) return ctx.component;
  if (ctx.name) return ctx.name;
  if (ctx.file) return ctx.file;
  if (ctx.role) return ctx.role;
  if (ctx.username) return ctx.username;
  if (ctx.count != null) return t("activity.files", { count: ctx.count });
  if (ctx.url) return ctx.url;
  return null;
}

const EVENT_ICONS = {
  "content.created": "mdi:file-document-plus-outline",
  "content.updated": "mdi:file-document-edit-outline",
  "content.published": "mdi:publish",
  "content.unpublished": "mdi:publish-off",
  "content.deleted": "mdi:delete-outline",
  "content.restored": "mdi:restore",
  "content_type.created": "mdi:shape-plus-outline",
  "content_type.updated": "mdi:shape-outline",
  "content_type.deleted": "mdi:delete-outline",
  "media.uploaded": "mdi:upload-outline",
  "media.deleted": "mdi:delete-outline",
  "media.bulk_deleted": "mdi:delete-sweep-outline",
  "user.created": "mdi:account-plus-outline",
  "user.updated": "mdi:account-edit-outline",
  "user.deleted": "mdi:account-remove-outline",
  "role.created": "mdi:shield-plus-outline",
  "role.updated": "mdi:shield-edit-outline",
  "role.deleted": "mdi:shield-remove-outline",
  "token.created": "mdi:key-plus",
  "token.revoked": "mdi:key-remove",
  "token.deleted": "mdi:key-remove",
  login: "mdi:login",
  logout: "mdi:logout",
  "failed login": "mdi:account-alert-outline",
  "backup created": "mdi:database-plus-outline",
  "backup restored": "mdi:database-refresh-outline",
  "backup uploaded": "mdi:database-arrow-up-outline",
  "update downloaded": "mdi:download-outline",
  "update installed": "mdi:package-variant-closed-check",
  "webhook failed": "mdi:webhook",
  "content purged": "mdi:delete-sweep-outline",
  "site.settings_updated": "mdi:cog-outline",
  "site.theme_updated": "mdi:palette-outline",
  "site.menus_updated": "mdi:menu",
  "site.layout_updated": "mdi:view-dashboard-outline",
  "site.layout_reset": "mdi:restore",
  "site.page_updated": "mdi:file-document-edit-outline",
  "site.page_reset": "mdi:restore",
  "site.region_updated": "mdi:view-grid-outline",
  "site.region_reset": "mdi:restore",
  "site.component_created": "mdi:puzzle-plus-outline",
  "site.component_updated": "mdi:puzzle-edit-outline",
  "site.component_renamed": "mdi:form-textbox",
  "site.component_deleted": "mdi:puzzle-remove-outline",
  "site.form_created": "mdi:form-select",
  "site.form_updated": "mdi:form-select",
  "site.form_deleted": "mdi:delete-outline",
  "site.form_submitted": "mdi:send-outline",
  "site.revision_restored": "mdi:history",
};

function eventIcon(item) {
  return EVENT_ICONS[item.message] ?? (
    item.level === "error" ? "mdi:alert-circle-outline" :
    item.level === "warning" ? "mdi:alert-outline" : "mdi:information-outline"
  );
}

function iconClass(item) {
  const msg = item.message ?? "";
  if (item.level === "error") return "bg-red-50 text-red-600 ring-red-200";
  if (item.level === "warning" || msg === "failed login")
    return "bg-amber-50 text-amber-600 ring-amber-200";
  if (
    msg.endsWith(".deleted") ||
    msg.endsWith(".bulk_deleted") ||
    msg === "content purged"
  )
    return "bg-red-50 text-red-600 ring-red-200";
  if (
    msg.endsWith(".created") ||
    msg.endsWith(".uploaded") ||
    msg.endsWith(".restored") ||
    msg.endsWith(".published")
  )
    return "bg-emerald-50 text-emerald-600 ring-emerald-200";
  return "bg-blue-50 text-blue-600 ring-blue-200";
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
