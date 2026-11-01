<template>
  <div>
    <div class="editor-sticky-header flex flex-wrap items-center gap-3">
      <div class="min-w-0 flex-1">
        <h1 class="page-title">{{ t("site.title") }}</h1>
        <p class="mt-0.5 text-sm text-slate-500">{{ t("site.description") }}</p>
      </div>

      <router-link v-if="site.data" :to="builderLink" class="btn-secondary">
        <Icon icon="mdi:view-dashboard-edit-outline" class="h-4 w-4" />
        {{ t("site.builderCta.open") }}
      </router-link>
      <button
        v-if="site.draft"
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

    <div v-if="site.loading && !site.draft" class="flex justify-center py-16">
      <LoadingSpinner />
    </div>

    <div v-else-if="site.error && !site.draft" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
      {{ site.error }}
    </div>

    <template v-else-if="site.draft">
      <div
        v-if="Object.keys(site.fieldErrors).length > 0"
        class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
      >
        <p class="font-medium">{{ t("site.saveErrors") }}</p>
        <ul class="mt-1 list-disc pl-5">
          <li v-for="(message, key) in site.fieldErrors" :key="key">{{ message }}</li>
        </ul>
      </div>

      <TabNavigation
        class="mb-6"
        :items="tabs"
        :model-value="activeTab"
        :aria-label="t('site.navigation')"
      >
        <template v-if="activeTab === 'menus'" #end><LanguageTabs /></template>
      </TabNavigation>

      <div v-if="activeTab === 'design'" class="grid items-start gap-6 lg:grid-cols-2">
        <div class="min-w-0 space-y-4">
          <SiteDesignPanel layout="page" />
          <div class="card overflow-hidden">
            <button
              type="button"
              class="flex w-full items-center gap-2 px-4 py-3 text-left text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-theme-500"
              :aria-expanded="cssEditorOpen"
              aria-controls="design-custom-css"
              @click="cssEditorOpen = !cssEditorOpen"
            >
              <Icon icon="mdi:language-css3" class="h-4 w-4" />
              {{ t("site.theme.customCss") }}
              <Icon :icon="cssEditorOpen ? 'mdi:chevron-up' : 'mdi:chevron-down'" class="ml-auto h-4 w-4" />
            </button>
            <section v-if="cssEditorOpen" id="design-custom-css" class="h-[28rem] overflow-hidden border-t border-slate-200" :aria-label="t('site.theme.customCss')">
              <CustomCssPanel :show-header="false" />
            </section>
          </div>
        </div>
        <SiteThemePreview class="min-w-0 lg:sticky lg:top-24" />
      </div>
      <SiteTemplatesPanel v-else-if="activeTab === 'templates'" />
      <SiteComponentsPanel v-else-if="activeTab === 'components'" />
      <section v-else-if="activeTab === 'fonts'" class="card p-6">
        <div class="mb-4">
          <h2 class="card-title">{{ t("site.fonts.libraryTitle") }}</h2>
          <p class="card-description">{{ t("site.fonts.libraryHint") }}</p>
        </div>
        <FontLibrary layout="page" />
      </section>
      <SiteMenusPanel v-else />
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
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { Icon } from "@iconify/vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import LoadingSpinner from "../components/LoadingSpinner.vue";
import TabNavigation from "../components/TabNavigation.vue";
import LanguageTabs from "../components/LanguageTabs.vue";
import SiteDesignPanel from "../components/site/builder/SiteDesignPanel.vue";
import CustomCssPanel from "../components/site/builder/CustomCssPanel.vue";
import SiteThemePreview from "../components/site/SiteThemePreview.vue";
import SiteMenusPanel from "../components/site/SiteMenusPanel.vue";
import SiteComponentsPanel from "../components/site/SiteComponentsPanel.vue";
import SiteTemplatesPanel from "../components/site/SiteTemplatesPanel.vue";
import FontLibrary from "../components/site/FontLibrary.vue";
import { useUnsavedChangesGuard } from "../composables/useUnsavedChangesGuard.js";
import { useSiteStore } from "../stores/site.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";
import { provideLanguageSwitcher } from "../composables/useLanguages.js";

const route = useRoute();
const site = useSiteStore();
const toast = useToastStore();
const { t } = useI18n();
const cssEditorOpen = ref(false);
provideLanguageSwitcher();
/** Global design, menus and templates share a draft across these tabs. */
const PARTS = ["theme", "menus", "templates"];
const isDirty = computed(() => site.dirtyParts.some((part) => PARTS.includes(part)));
// Opens the homepage layout, or the header when no homepage is chosen. Uses saved
// settings because leaving discards any pending edits.
const builderLink = computed(() => {
  const homepage = site.data.settings.homepage;
  const type = site.data.types.find((type) => type.name === homepage.type && type.visibility !== "private");
  if (!type) return "/page-builder/builder/region/header";

  return {
    path: `/page-builder/builder/layout/${encodeURIComponent(type.name)}/${homepage.entry ? "detail" : "index"}`,
    query: homepage.entry ? { entry: homepage.entry } : {},
  };
});
const { leavePromptOpen, confirmLeave } = useUnsavedChangesGuard(isDirty);

const validTabs = new Set(["design", "templates", "menus", "fonts", "components"]);
const activeTab = computed(() => (validTabs.has(route.params.tab) ? route.params.tab : "design"));
const tabs = computed(() => [
  { value: "design", label: t("site.builder.designSettings"), icon: "mdi:palette-outline", to: "/page-builder/design" },
  { value: "templates", label: t("site.templates.title"), icon: "mdi:page-layout-header-footer", to: "/page-builder/templates" },
  { value: "menus", label: t("site.tab.menus"), icon: "mdi:menu", to: "/page-builder/menus" },
  { value: "components", label: t("site.component.group"), icon: "mdi:puzzle-outline", to: "/page-builder/components" },
  { value: "fonts", label: t("site.tab.fonts"), icon: "mdi:format-font", to: "/page-builder/fonts" },
]);

async function save() {
  try {
    await site.save({ parts: PARTS });
    toast.success(t("site.saved"));
  } catch (err) {
    toast.error(err.message);
  }
}

function leave() {
  site.resetDraft();
  confirmLeave();
}

function onKeydown(event) {
  if (event.target?.closest?.('[role="dialog"], [role="alertdialog"]')) return;
  if ((event.ctrlKey || event.metaKey) && event.key === "s") {
    event.preventDefault();
    if (isDirty.value && !site.saving) save();
  }
}

function onWorkspaceChanged() {
  site.invalidate();
  site.load().catch(() => {});
}

onMounted(() => {
  // Tabs are separate routes; the draft survives switching between them.
  site.load().catch(() => {});
  window.addEventListener("keydown", onKeydown);
  window.addEventListener("cometcms:workspace-changed", onWorkspaceChanged);
});

onBeforeUnmount(() => {
  window.removeEventListener("keydown", onKeydown);
  window.removeEventListener("cometcms:workspace-changed", onWorkspaceChanged);
});
</script>
