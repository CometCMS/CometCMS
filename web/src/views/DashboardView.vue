<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-slate-900">
        {{ t("dashboard.title") }}
      </h1>
    </div>

    <LoadingSpinner v-if="loading" />

    <template v-else>
      <!-- Stats -->
      <div class="grid gap-4 mb-8 sm:grid-cols-3">
        <div class="card p-5">
          <p
            class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1"
          >
            {{ t("dashboard.stats.collections") }}
          </p>
          <p class="text-3xl font-bold text-slate-900">
            {{ stats.collections }}
          </p>
          <p class="mt-1 text-sm text-slate-500">
            {{ t("dashboard.stats.totalCollections") }}
          </p>
        </div>
        <div class="card p-5">
          <p
            class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1"
          >
            {{ t("dashboard.stats.entries") }}
          </p>
          <p class="text-3xl font-bold text-slate-900">{{ stats.entries }}</p>
          <p class="mt-1 text-sm text-slate-500">
            {{ t("dashboard.stats.totalEntries") }}
          </p>
        </div>
        <div class="card p-5">
          <p
            class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1"
          >
            {{ t("dashboard.stats.contentTypes") }}
          </p>
          <p class="text-3xl font-bold text-slate-900">
            {{ stats.content_types }}
          </p>
          <p class="mt-1 text-sm text-slate-500">
            {{ t("dashboard.stats.totalContentTypes") }}
          </p>
        </div>
      </div>

      <div class="grid gap-5 lg:grid-cols-2">
        <!-- Recent Activity -->
        <ActivityFeed />

        <!-- Quick actions -->
        <section class="card p-5">
          <h2 class="text-sm font-semibold text-slate-900 mb-4">
            {{ t("dashboard.quickActions") }}
          </h2>
          <div class="grid gap-3 sm:grid-cols-2">
            <router-link
              v-for="action in quickActions"
              :key="action.label"
              :to="action.to"
              class="flex items-center justify-between rounded-lg border border-slate-200 bg-white p-4 text-sm font-medium text-slate-800 transition hover:border-theme-300 hover:bg-theme-50/40"
            >
              <span class="flex min-w-0 items-center gap-3">
                <Icon
                  :icon="action.icon"
                  class="h-5 w-5 shrink-0 text-slate-500"
                />
                <span class="truncate">{{ action.label }}</span>
              </span>
              <Icon
                icon="mdi:chevron-right"
                class="h-4 w-4 shrink-0 text-slate-400"
              />
            </router-link>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>

<script setup>
import LoadingSpinner from "../components/LoadingSpinner.vue";
import ActivityFeed from "../components/ActivityFeed.vue";
import { computed, ref, onMounted } from "vue";
import { Icon } from "@iconify/vue";
import { api } from "../api/index.js";
import { useI18n } from "../i18n/index.js";

const loading = ref(true);
const stats = ref({ collections: 0, entries: 0, content_types: 0 });
const collections = ref([]);
const { t } = useI18n();

const firstCollection = computed(() => collections.value[0]?.name ?? "");
const quickActions = computed(() => [
  {
    label: t("dashboard.actions.viewCollections"),
    to: firstCollection.value
      ? `/content/${firstCollection.value}`
      : "/content-types",
    icon: "mdi:folder-outline",
  },
  {
    label: t("dashboard.actions.addEntry"),
    to: firstCollection.value
      ? `/content/${firstCollection.value}/new`
      : "/content-types/new",
    icon: "mdi:plus-circle-outline",
  },
  {
    label: t("dashboard.actions.manageMedia"),
    to: "/media",
    icon: "mdi:image-multiple-outline",
  },
  {
    label: t("app.nav.apiExplorer"),
    to: "/api-explorer",
    icon: "mdi:code-json",
  },
]);

onMounted(async () => {
  try {
    const [dashboard, types] = await Promise.allSettled([
      api.dashboard(),
      api.contentTypes.list(),
    ]);
    if (dashboard.status === "fulfilled") stats.value = dashboard.value.data;
    if (types.status === "fulfilled")
      collections.value = types.value.data ?? [];
  } finally {
    loading.value = false;
  }
});
</script>
