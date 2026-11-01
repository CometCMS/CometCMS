<template>
  <div>
    <div class="editor-sticky-header flex flex-wrap items-center gap-3">
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
          <h1 class="page-title">{{ showsWorkspaces ? t("workspaces.title") : t("website.title") }}</h1>
          <span
            v-if="site.data && editsSite"
            class="badge badge-dot"
            :class="!site.data.settings.enabled ? 'bg-slate-100 text-slate-600 ring-slate-500/15' : site.data.settings.maintenance ? 'bg-amber-50 text-amber-700 ring-amber-600/20' : 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'"
            :title="site.data.settings.enabled && site.data.settings.maintenance ? t('site.overview.maintenanceActive') : undefined"
          >
            {{ !site.data.settings.enabled ? t("site.status.off") : site.data.settings.maintenance ? t("site.status.maintenance") : t("site.status.live") }}
          </span>
        </div>
        <p v-if="!showsWorkspaces" class="mt-0.5 text-sm text-slate-500">{{ t("website.description") }}</p>
      </div>

      <a
        v-if="editsSite && site.data?.settings.enabled"
        :href="site.data.site_url + '/'"
        target="_blank"
        rel="noopener"
        class="btn-secondary"
      >
        <Icon icon="mdi:open-in-new" class="h-4 w-4" />
        {{ t("site.openWebsite") }}
      </a>
      <button
        v-if="editsSite && activeTab !== 'extensions' && site.draft"
        type="button"
        class="btn-primary relative"
        :disabled="site.saving || !isDirty"
        @click="save"
      >
        {{ site.saving ? t("common.saving") : t("site.save") }}
        <span
          v-if="isDirty && !site.saving"
          class="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full bg-amber-400 ring-2 ring-white"
        ></span>
      </button>
    </div>

    <TabNavigation
      v-if="!showsWorkspaces && tabs.length > 1"
      class="mb-6"
      :items="tabs"
      :model-value="activeTab"
      :aria-label="t('website.navigation')"
    >
      <!-- One language switcher for every localized field on the page -->
      <template v-if="editsSite && activeTab !== 'extensions' && site.draft" #end><LanguageTabs /></template>
    </TabNavigation>

    <WorkspacesPanel v-if="showsWorkspaces" />
    <ExtensionLibrary v-else-if="editsSite && activeTab === 'extensions'" />

    <template v-else-if="editsSite">
      <div v-if="site.loading && !site.draft" class="flex justify-center py-16">
        <LoadingSpinner />
      </div>

      <div v-else-if="site.error && !site.draft" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
        {{ site.error }}
      </div>

      <template v-else-if="site.draft">
        <div
          v-if="!site.live"
          class="mb-6 flex flex-col gap-3 rounded-xl border border-theme-200 bg-theme-50 px-4 py-3 text-sm text-theme-900 sm:flex-row sm:items-center"
        >
          <Icon icon="mdi:information-outline" class="h-5 w-5 shrink-0 text-theme-600" />
          <p class="flex-1">{{ t("site.offNotice") }}</p>
          <router-link to="/website/general#homepage" class="btn-secondary btn-sm shrink-0">
            {{ t("site.chooseHomepage") }}
          </router-link>
          <router-link to="/content-types" class="btn-secondary btn-sm shrink-0">
            {{ t("site.enablePages") }}
          </router-link>
        </div>

        <div
          v-if="Object.keys(site.fieldErrors).length > 0"
          class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
          <p class="font-medium">{{ t("site.saveErrors") }}</p>
          <ul class="mt-1 list-disc pl-5">
            <li v-for="(message, key) in site.fieldErrors" :key="key">{{ message }}</li>
          </ul>
        </div>

        <SiteFormsPanel v-if="activeTab === 'forms'" />
        <SiteSeoPanel v-else-if="activeTab === 'seo'" />
        <SiteGeneralPanel v-else />
      </template>
    </template>

    <ConfirmModal
      v-model="leavePromptOpen"
      :title="t('unsaved.title')"
      :message="t('unsaved.message')"
      :confirm-label="t('unsaved.leave')"
      :cancel-label="t('unsaved.stay')"
      variant="warning"
      @confirm="leave"
    />
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, watch } from "vue";
import { useRoute } from "vue-router";
import { Icon } from "@iconify/vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import LoadingSpinner from "../components/LoadingSpinner.vue";
import TabNavigation from "../components/TabNavigation.vue";
import LanguageTabs from "../components/LanguageTabs.vue";
import WorkspacesPanel from "../components/WorkspacesPanel.vue";
import SiteGeneralPanel from "../components/site/SiteGeneralPanel.vue";
import SiteSeoPanel from "../components/site/SiteSeoPanel.vue";
import SiteFormsPanel from "../components/site/SiteFormsPanel.vue";
import ExtensionLibrary from "../components/site/ExtensionLibrary.vue";
import { useUnsavedChangesGuard } from "../composables/useUnsavedChangesGuard.js";
import { useAuthStore } from "../stores/auth.js";
import { useContentTypesStore } from "../stores/contentTypes.js";
import { useSiteStore } from "../stores/site.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";
import { provideLanguageSwitcher } from "../composables/useLanguages.js";

/** Website-wide settings: identity, homepage, SEO defaults and the workspaces behind them. */
const route = useRoute();
const auth = useAuthStore();
const site = useSiteStore();
const contentTypes = useContentTypesStore();
const toast = useToastStore();
const { t } = useI18n();
provideLanguageSwitcher();
/** Settings and the workspace languages edited on the General tab save together. */
const PARTS = ["languages", "settings", "form_settings"];
const isDirty = computed(() => site.dirtyParts.some((part) => PARTS.includes(part)));
const { leavePromptOpen, confirmLeave } = useUnsavedChangesGuard(isDirty);

const tabs = computed(() =>
  auth.can("site.manage")
    ? [
        { value: "general", label: t("website.tab.general"), icon: "mdi:cog-outline", to: "/website/general" },
        { value: "seo", label: t("website.tab.seo"), icon: "mdi:magnify", to: "/website/seo" },
        ...(site.data?.types?.some((type) => type.allow_submissions)
          ? [{ value: "forms", label: t("website.tab.forms"), icon: "mdi:form-select", to: "/website/forms" }]
          : []),
        { value: "extensions", label: t("site.extensions.title"), icon: "mdi:puzzle-outline", to: "/website/extensions" },
      ]
    : [],
);
/** Workspaces span more than the website, so they get their own page here (Administration in the sidebar). */
const showsWorkspaces = computed(
  () => auth.can("workspaces.manage") && (route.params.tab === "workspaces" || !auth.can("site.manage")),
);
const activeTab = computed(() =>
  tabs.value.some((tab) => tab.value === route.params.tab) ? route.params.tab : "general",
);
const editsSite = computed(() => !showsWorkspaces.value && auth.can("site.manage"));

async function save() {
  const languagesChanged = site.dirtyParts.includes("languages");
  try {
    await site.save({ parts: PARTS });
    toast.success(t("site.saved"));
  } catch (err) {
    toast.error(err.message);
  } finally {
    // Languages may have saved even when the settings after them failed.
    if (languagesChanged && !site.dirtyParts.includes("languages")) {
      contentTypes.invalidate();
      window.dispatchEvent(new CustomEvent("cometcms:workspaces-updated"));
    }
  }
}

function leave() {
  site.resetDraft();
  confirmLeave();
}

function onKeydown(event) {
  if (!editsSite.value) return;
  if (event.target?.closest?.('[role="dialog"], [role="alertdialog"]')) return;
  if ((event.ctrlKey || event.metaKey) && event.key === "s") {
    event.preventDefault();
    if (isDirty.value && !site.saving) save();
  }
}

function onWorkspaceChanged() {
  site.invalidate();
  if (editsSite.value) site.load().catch(() => {});
}

// Editing a workspace's languages invalidates the site; reload it when coming back.
watch(
  editsSite,
  (edits) => {
    if (edits) site.load({ force: !site.isDirty }).catch(() => {});
  },
  { immediate: true },
);

// Links like "Choose homepage" jump to the homepage card once it is rendered.
watch(
  () => route.hash === "#homepage" && activeTab.value === "general" && !!site.draft,
  async (ready) => {
    if (!ready) return;
    await nextTick();
    document.getElementById("homepage")?.scrollIntoView({ behavior: "smooth", block: "start" });
    document.getElementById("site-home-type")?.focus({ preventScroll: true });
  },
  { immediate: true },
);

onMounted(() => {
  window.addEventListener("keydown", onKeydown);
  window.addEventListener("cometcms:workspace-changed", onWorkspaceChanged);
});

onBeforeUnmount(() => {
  window.removeEventListener("keydown", onKeydown);
  window.removeEventListener("cometcms:workspace-changed", onWorkspaceChanged);
});
</script>
