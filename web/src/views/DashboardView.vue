<template>
  <div>
    <PageHeader :title="greeting" :description="t('dashboard.subtitle', { workspace: workspaceLabel })" />

    <LoadingSpinner v-if="loading" />

    <template v-else>
      <!-- First-run guidance until the install has a content type and an entry -->
      <section v-if="showGettingStarted" class="card mb-6 p-5" aria-labelledby="getting-started-title">
        <h2 id="getting-started-title" class="card-title">
          {{ t("dashboard.start.title") }}
        </h2>
        <p class="card-description">{{ t("dashboard.start.body") }}</p>
        <ol class="mt-4 grid gap-3 md:grid-cols-3">
          <li v-for="(step, index) in gettingStartedSteps" :key="step.key">
            <component
              :is="step.to ? 'router-link' : 'div'"
              :to="step.to"
              class="flex h-full items-start gap-3 rounded-lg border p-4 transition"
              :class="step.done
                ? 'border-emerald-200 bg-emerald-50/60'
                : step.to
                  ? 'border-slate-200 hover:border-theme-300 hover:bg-theme-50/40'
                  : 'border-slate-200 opacity-70'"
            >
              <span
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                :class="step.done ? 'bg-emerald-500 text-white' : 'bg-theme-600 text-white'"
                aria-hidden="true"
              >
                <Icon v-if="step.done" icon="mdi:check" class="h-4 w-4" />
                <template v-else>{{ index + 1 }}</template>
              </span>
              <span class="min-w-0">
                <span class="block text-sm font-medium text-slate-900">
                  {{ step.title }}
                  <span v-if="step.done" class="sr-only">({{ t("dashboard.start.done") }})</span>
                </span>
                <span class="mt-0.5 block text-xs text-slate-500">{{ step.body }}</span>
              </span>
            </component>
          </li>
        </ol>
      </section>

      <!-- Key figures -->
      <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <component
          :is="item.to ? 'router-link' : item.href ? 'a' : 'div'"
          v-for="item in statusItems"
          :key="item.label"
          :to="item.to"
          :href="item.href"
          :target="item.href ? '_blank' : undefined"
          :rel="item.href ? 'noopener noreferrer' : undefined"
          class="card group flex items-start gap-4 p-5"
          :class="item.to || item.href ? 'transition hover:border-slate-300 cursor-pointer hover:shadow-sm' : ''"
        >
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-theme-50 text-theme-600 ring-1 ring-inset ring-theme-600/10">
            <Icon :icon="item.icon" class="h-5 w-5" />
          </span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm text-slate-500">{{ item.label }}</p>
            <p
              class="mt-0.5 truncate font-semibold tracking-tight text-slate-900"
              :class="item.href ? 'text-lg leading-8' : 'text-2xl'"
            >
              {{ item.value }}
            </p>
            <p class="mt-0.5 text-xs text-slate-400">{{ item.caption }}</p>
          </div>
          <Icon
            v-if="item.to || item.href"
            :icon="item.href ? 'mdi:arrow-top-right' : 'mdi:arrow-right'"
            class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-slate-500"
          />
        </component>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <!-- Recent Activity -->
        <ActivityFeed />

        <div class="min-w-0 space-y-4">
          <DashboardSiteWidget :status="websiteStatus" />

          <!-- Quick actions -->
          <section v-if="quickActions.length > 0" class="card" aria-labelledby="quick-actions-title">
            <div class="card-header">
              <h2 id="quick-actions-title" class="card-title">{{ t("dashboard.quickActions") }}</h2>
            </div>
            <ul class="p-2">
              <li v-for="action in quickActions" :key="action.key">
                <router-link
                  :to="action.to"
                  class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-slate-50 hover:text-slate-900"
                >
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 ring-1 ring-inset ring-slate-200 transition-colors group-hover:bg-theme-50 group-hover:text-theme-600 group-hover:ring-theme-200">
                    <Icon :icon="action.icon" class="h-4 w-4" />
                  </span>
                  <span class="min-w-0 flex-1 truncate font-medium">{{ action.label }}</span>
                  <Icon icon="mdi:chevron-right" class="h-4 w-4 shrink-0 text-slate-300 group-hover:text-slate-500" />
                </router-link>
              </li>
            </ul>
          </section>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import LoadingSpinner from "../components/LoadingSpinner.vue";
import ActivityFeed from "../components/ActivityFeed.vue";
import DashboardSiteWidget from "../components/DashboardSiteWidget.vue";
import PageHeader from "../components/PageHeader.vue";
import { useAuthStore } from "../stores/auth.js";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Icon } from "@iconify/vue";
import { api, getActiveWorkspace } from "../api/index.js";
import { useI18n } from "../i18n/index.js";
import { usePermissions } from "../composables/usePermissions.js";
import { useContentTypesStore } from "../stores/contentTypes.js";

const loading = ref(true);
const stats = ref({ collections: 0, entries: 0, content_types: 0 });
const appVersion = ref("");
const websiteStatus = ref(null);
const { t } = useI18n();
const { canSchema } = usePermissions();
const typesStore = useContentTypesStore();
const auth = useAuthStore();
const workspaceLabel = ref(getActiveWorkspace());

const greeting = computed(() => {
  const hour = new Date().getHours();
  const key = hour < 12 ? "dashboard.greetingMorning" : hour < 18 ? "dashboard.greetingAfternoon" : "dashboard.greetingEvening";
  return t(key, { name: auth.user?.display_name || auth.user?.username || "" });
});

const quickActions = computed(() => {
  const actions = typesStore.list
    .filter((type) => auth.can("content.create", `content:${type.name}:*`))
    .slice(0, 4)
    .map((type) => ({
      key: `type:${type.name}`,
      label: t("app.search.newEntry", { type: type.label }),
      icon: "mdi:plus",
      to: `/content/${type.name}/new`,
    }));
  actions.push({ key: "media", label: t("dashboard.uploadMedia"), icon: "mdi:upload-outline", to: "/media" });
  if (canSchema("schema.create")) {
    actions.push({ key: "type", label: t("app.search.newContentType"), icon: "mdi:shape-plus-outline", to: "/content-types/new" });
  }
  return actions;
});

const showGettingStarted = computed(
  () =>
    canSchema("schema.create") &&
    (stats.value.content_types === 0 || stats.value.entries === 0),
);
const gettingStartedSteps = computed(() => {
  const firstType = typesStore.list[0];
  return [
    {
      key: "type",
      title: t("dashboard.start.typeTitle"),
      body: t("dashboard.start.typeBody"),
      done: stats.value.content_types > 0,
      to: stats.value.content_types > 0 ? "/content-types" : "/content-types/new",
    },
    {
      key: "entry",
      title: t("dashboard.start.entryTitle"),
      body: t("dashboard.start.entryBody"),
      done: stats.value.entries > 0,
      to: firstType ? `/content/${firstType.name}` : null,
    },
    {
      key: "design",
      title: t("dashboard.start.connectTitle"),
      body: t("dashboard.start.connectBody"),
      done: false,
      to: "/website/general",
    },
  ];
});

const statusItems = computed(() => [

  {
    label: t("dashboard.stats.entries"),
    value: stats.value.entries,
    caption: t("dashboard.stats.totalEntries"),
    icon: "mdi:file-document-outline",
  },
  {
    label: t("dashboard.stats.contentTypes"),
    value: stats.value.content_types,
    caption: t("dashboard.stats.totalContentTypes"),
    icon: "mdi:shape-outline",
    to: canSchema("schema.read") ? "/content-types" : undefined,
  },
  {
    label: t("dashboard.stats.cmsVersion"),
    value: `v${appVersion.value || "..."}`,
    caption: t("dashboard.stats.currentVersion"),
    icon: "mdi:rocket-launch-outline",
    to: "/update",
  },
  {
    label: t("dashboard.stats.documentation"),
    value: t("dashboard.stats.openDocs"),
    caption: t("dashboard.stats.docsCaption"),
    icon: "mdi:book-open-page-variant-outline",
    href: "https://GetCometCMS.github.io/CometCMS/",
  },
]);

let loadGeneration = 0;
async function loadDashboard() {
  const generation = ++loadGeneration;
  const workspace = getActiveWorkspace();
  const current = () => generation === loadGeneration && workspace === getActiveWorkspace();
  loading.value = true;
  websiteStatus.value = null;
  try {
    const [dashboard, app, website, workspaces] = await Promise.allSettled([
      api.dashboard(),
      api.appInfo(),
      api.site.status(),
      auth.can("workspaces.read") ? api.workspaces.list() : Promise.resolve({ data: [] }),
    ]);
    if (!current()) return;
    if (dashboard.status === "fulfilled") stats.value = dashboard.value.data;
    if (app.status === "fulfilled") appVersion.value = app.value.data?.version ?? "";
    if (website.status === "fulfilled") websiteStatus.value = website.value.data;
    workspaceLabel.value =
      (workspaces.status === "fulfilled"
        ? (workspaces.value.data ?? []).find((w) => w.slug === workspace)?.label
        : null) ?? workspace;
  } finally {
    if (current()) loading.value = false;
  }
}

onMounted(() => {
  loadDashboard();
  window.addEventListener("cometcms:workspace-changed", loadDashboard);
});

onBeforeUnmount(() => {
  loadGeneration += 1;
  window.removeEventListener("cometcms:workspace-changed", loadDashboard);
});
</script>
