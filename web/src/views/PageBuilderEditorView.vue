<template>
  <div class="builder-shell flex h-screen flex-col bg-slate-100">
    <!-- Top bar -->
    <header class="builder-topbar relative flex min-h-12 shrink-0 flex-wrap items-center gap-x-2 gap-y-1 border-b px-2 py-1.5 lg:flex-nowrap">
      <router-link :to="exitTo" class="topbar-action" :title="t('site.builder.back')">
        <Icon icon="mdi:arrow-left" class="h-[18px] w-[18px]" />
        <span class="sr-only">{{ t("site.builder.back") }}</span>
      </router-link>
      <span class="topbar-divider" aria-hidden="true"></span>

      <nav class="builder-breadcrumbs min-w-0 flex-1 pl-1" :aria-label="t('app.breadcrumbs')">
        <ol class="flex min-w-0 items-center gap-1 sm:gap-2">
          <li class="breadcrumb-root w-max min-w-20 max-w-xs shrink-0">
            <BuilderPageSwitcher :heading="target === 'page' ? builder.type.value?.label ?? name : heading" :target="target" :name="name" />
            <p
              class="topbar-muted flex items-center gap-1 truncate text-[11px] leading-tight pl-1"
              :class="{ 'cursor-help': scopeBar }"
              v-tooltip="scopeBar && { title: scopeBar.title, text: scopeBar.text }"
              :tabindex="scopeBar ? 0 : undefined"
              :aria-label="scopeBar ? `${subheading}. ${scopeBar.text}` : undefined"
            >
              <Icon v-if="scopeBar" :icon="scopeBar.icon" class="h-3 w-3 shrink-0" aria-hidden="true" />
              <span class="truncate">{{ subheading }}</span>
            </p>
          </li>
          <!-- The active crumb is the editing scope; its descendants retain the preview context. -->
          <li v-if="templateMenu" class="flex min-w-0 items-center gap-1 sm:gap-2">
            <Icon icon="mdi:chevron-right" class="breadcrumb-separator h-4 w-4 shrink-0" aria-hidden="true" />
            <BuilderTemplateMenu
              v-bind="templateMenu"
              :active="target === 'layout'"
              :disabled="saving || loading"
              @navigate="target === 'page' && goToView(templateView)"
              @open="openTemplate"
              @new="templateDialogOpen = true"
            />
          </li>
          <li v-if="templateMenu && entrySide" class="flex min-w-0 items-center gap-1 sm:gap-2">
            <Icon icon="mdi:chevron-right" class="breadcrumb-separator h-4 w-4 shrink-0" aria-hidden="true" />
            <BuilderEntrySwitcher
              class="scope-page"
              icon="mdi:file-document-edit-outline"
              :label="entryTitle"
              :title="entrySide.title"
              :unavailable="entrySide.unavailable"
              :active="target === 'page'"
              :model-value="target === 'page' ? pageEntryId : sampleId"
              :entries="samples"
              :template-id="target === 'layout' ? editedTemplate : liveTemplate"
              :disabled="saving || loading"
              @navigate="goToView(pageView)"
              @update:model-value="selectEntry"
            />
          </li>
          <!-- A component of a content type only previews an entry. -->
          <li v-else-if="!templateMenu && showsSamples && samples.length" class="flex min-w-0 items-center gap-1 sm:gap-2">
            <Icon icon="mdi:chevron-right" class="breadcrumb-separator h-4 w-4 shrink-0" aria-hidden="true" />
            <BuilderEntrySwitcher
              icon="mdi:eye-outline"
              :title="t('site.builder.sampleEntry')"
              :label="samples.find((entry) => entry.id === sampleId)?.title || sampleId"
              :model-value="sampleId"
              :entries="samples"
              :type-labels="sampleTypeLabels"
              :navigable="false"
              :disabled="saving || loading"
              @update:model-value="selectEntry"
            />
          </li>
        </ol>
      </nav>

      <div class="builder-toolbar ml-auto flex shrink-0 items-center gap-1.5">
        <div v-if="locales.length > 1" class="topbar-group hidden md:flex">
          <!-- The language also sets the mode: other languages only translate texts. -->
          <div class="flex items-center gap-2 px-1.5">
            <LanguageTabs v-model="locale" class="topbar-languages" :locales="locales" :disabled="saving" compact />
            <span
              v-if="builder.translating.value"
              class="inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800"
              :title="t('localization.translateHint')"
            >
              <Icon icon="mdi:translate" class="h-3.5 w-3.5 shrink-0" />
              <span class="hidden xl:inline">{{ t('localization.translate') }}</span>
            </span>
          </div>
        </div>

        <DeviceToggle v-model="device" class="topbar-devices" allow-full-width />

        <span class="topbar-divider" aria-hidden="true"></span>
        <button type="button" class="topbar-action" :disabled="!builder.canUndo.value" :title="`${t('site.builder.undo')} (Ctrl+Z)`" @click="builder.undo()">
          <Icon icon="mdi:undo" class="h-[18px] w-[18px]" />
          <span class="sr-only">{{ t("site.builder.undo") }}</span>
        </button>
        <button type="button" class="topbar-action" :disabled="!builder.canRedo.value" :title="`${t('site.builder.redo')} (Ctrl+Shift+Z)`" @click="builder.redo()">
          <Icon icon="mdi:redo" class="h-[18px] w-[18px]" />
          <span class="sr-only">{{ t("site.builder.redo") }}</span>
        </button>
        <BuilderHelpMenu @guide="guideOpen = true" @reset-layout="panelLayout.reset()" />
        <span class="topbar-divider" aria-hidden="true"></span>

        <span
          v-if="saveStatus !== 'saved'"
          class="hidden items-center gap-1.5 whitespace-nowrap px-1 text-xs xl:flex"
          :class="saveStatus === 'unsaved' ? 'font-medium text-white' : 'topbar-muted'"
          role="status"
          :title="saveStatus === 'unsaved' ? t('site.builder.status.unsavedHint') : undefined"
        >
          <Icon v-if="saveStatus === 'saving'" icon="mdi:loading" class="h-3.5 w-3.5 animate-spin" />
          <span v-else class="h-2 w-2 rounded-full bg-amber-400"></span>
          {{ t(`site.builder.status.${saveStatus}`) }}
        </span>

        <SplitButton
          variant="inverse"
          :primary-disabled="saving || loading || switching || (!layoutSavable && !themeDirty && !templatesDirty && !entryFields.isDirty.value)"
          :menu-label="t('common.moreActions')"
          :items="saveMenuItems"
          @click="save"
          @select="onSaveMenu"
        >
          {{ saving ? t("common.saving") : saveLabel }}
          <template #badge>
            <span v-if="(builder.isDirty.value || themeDirty || templatesDirty || entryFields.isDirty.value) && !saving" class="topbar-save-badge pointer-events-none absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full bg-amber-400 ring-2"></span>
          </template>
        </SplitButton>
      </div>
      <div v-if="switchingSlow" class="builder-progress pointer-events-none absolute inset-x-0 bottom-0 h-0.5 overflow-hidden" role="progressbar" :aria-label="t('site.builder.switching')"></div>
    </header>

    <!-- The scope itself is the subtitle's hint; only what keeps an entry from being designed here takes a row. -->
    <div v-if="scopeBar?.note" role="note" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-amber-200 bg-amber-50 px-4 py-1.5 text-xs text-amber-800">
      <Icon icon="mdi:information-outline" class="h-4 w-4 shrink-0" aria-hidden="true" />
      <p class="min-w-0 flex-1 basis-64">{{ scopeBar.note }}</p>
      <div class="flex items-center gap-3">
        <button
          v-for="action in scopeBar.actions"
          :key="action.key"
          type="button"
          class="inline-flex items-center gap-1 font-medium underline-offset-2 hover:underline disabled:opacity-50"
          :disabled="saving || switching"
          @click="action.run()"
        >
          <Icon :icon="action.icon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          {{ action.label }}
        </button>
      </div>
    </div>
    <div v-if="recoveredDraft || layoutConflict || recoveryFailed" role="status" class="flex items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
      <p>{{ t(recoveryFailed ? 'site.builder.recoveryFailed' : layoutConflict ? 'site.builder.saveConflict' : 'site.builder.draftRecovered') }}</p>
      <div class="flex shrink-0 items-center gap-2">
        <button v-if="recoveredDraft || layoutConflict" type="button" class="btn-secondary btn-sm" :disabled="saving || switching" @click="discardRecovery">{{ t('site.builder.discardRecovered') }}</button>
        <button v-if="layoutConflict" type="button" class="btn-secondary btn-sm" :disabled="saving || switching" @click="overwriteOpen = true">{{ t('site.builder.saveMyLayout') }}</button>
      </div>
    </div>
    <div v-for="scope in ['layout', 'theme'].filter((scope) => loadedRevisions[scope])" :key="scope" role="status" class="flex items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
      <p v-if="!loadedRevisions[scope].revision">{{ t('site.builder.resetPending') }}</p>
      <p v-else>{{ t('site.history.loadedBanner', { scope: t(scope === 'theme' ? 'site.history.scope.design' : historyScopeKey), date: formatRevisionDate(loadedRevisions[scope].revision.created_at) }) }}</p>
      <button type="button" class="btn-secondary btn-sm shrink-0" :disabled="saving" @click="cancelRevision(scope)">{{ t('common.cancel') }}</button>
    </div>
    <p v-if="upgradeRequired" role="status" class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">{{ t("site.binding.upgrade") }}</p>
    <p v-if="builder.hasSyntaxErrors.value" role="alert" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ t("site.binding.invalidSyntax") }}</p>
    <div v-if="loadError" class="m-6 rounded-lg bg-red-50 p-4 text-sm text-red-700">{{ loadError }}</div>

    <div v-else-if="loading" class="flex flex-1 items-center justify-center"><LoadingSpinner /></div>

    <!-- Another view keeps this one on screen, untouchable, until its content arrives. -->
    <div
      v-else
      ref="builderAreaRef"
      class="relative isolate flex min-h-0 flex-1 overflow-hidden transition-opacity duration-150"
      :class="{ 'opacity-60': switchingSlow }"
      :inert="switching || null"
      :aria-busy="switching"
    >

      <!-- Panel rail -->
      <nav
        class="relative z-[150] flex w-[52px] shrink-0 flex-col items-center gap-1 border-r border-slate-200 bg-white py-2"
        :aria-label="t('site.builder.panels')"
      >
        <template v-for="panel in panels" :key="panel.value">
          <!-- Separator before entry fields -->
          <div
            v-if="panel.value === 'fields'"
            class="my-1 h-px w-6 shrink-0 bg-slate-200"
            aria-hidden="true"
          ></div>

          <button
            type="button"
            class="rail-action group"
            :class="{ 'is-active': panelWindows[panel.value]?.open }"
            :aria-pressed="panelWindows[panel.value]?.open ?? false"
            :aria-label="panel.label"
            v-tooltip="{ title: panel.label, text: t('site.builder.panelWindowHint'), placement: 'right' }"
            @pointerdown="panelLayout.startMove($event, panel.value, true)"
            @click="panelLayout.toggle(panel.value)"
          >
            <Icon :icon="panel.icon" class="h-5 w-5" />
            <span
              v-if="panel.dirty"
              class="pointer-events-none absolute right-1 top-1 h-2 w-2 rounded-full bg-amber-400 ring-2 ring-white"
            ></span>
          </button>

          <!-- Separator before design settings -->
          <div
            v-if="(panel.value === 'outline' && !hasEntryFields) || panel.value === 'fields'"
            class="my-1 h-px w-6 shrink-0 bg-slate-200"
            aria-hidden="true"
          ></div>
        </template>
      </nav>


      <BuilderPanelWindow
        v-for="panel in visiblePanels"
        :key="panel.value"
        :state="panelWindows[panel.value]"
        :limits="panelLayout.limits(panel.value)"
        :title="panel.label"
        :icon="panel.icon"
        @toggle-dock="panelLayout.toggleDock(panel.value)"
        @close="panelLayout.close(panel.value)"
        @focus="panelLayout.focus(panel.value)"
        @resize="(width, height) => panelLayout.resize(panel.value, width, height)"
        @move="panelLayout.startMove($event, panel.value)"
        @resize-start="panelLayout.startResize($event, panel.value)"
      >
        <SiteDesignPanel v-if="panel.value === 'design'" :show-header="false" :draft-nodes="builder.nodes.value" />
        <CustomCssPanel v-else-if="panel.value === 'css'" :show-header="false" />
        <BuilderInspector
          v-else-if="panel.value === 'inspector'"
          :template-part="selectedTemplatePart"
          :frame="selectedFrame"
          :search-page="target === 'region' && name === 'search_results'"
          @edit-template-part="editTemplatePart"
          @assign-template-part="assignTemplatePart"
        >
          <template #empty>
            <BuilderOverview
              :title="overview.title"
              :description="overview.description"
              :icon="overview.icon"
              :with-fields="hasEntryFields"
              @open-panel="panelLayout.open($event)"
              @open-guide="guideOpen = true"
            />
          </template>
        </BuilderInspector>
        <template v-else>
          <p v-if="panelHint(panel.value)" class="flex gap-2 border-b border-slate-100 bg-slate-50/60 px-3 py-2 text-[11px] leading-snug text-slate-500">
            <Icon icon="mdi:information-outline" class="mt-px h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden="true" />
            <span>{{ panelHint(panel.value) }}</span>
          </p>
          <div v-if="panel.value === 'history'" class="border-b border-slate-200 p-2">
            <SegmentedControl
              v-model="historyScope"
              :options="[
                { value: 'layout', label: t(historyScopeKey) },
                { value: 'theme', label: t('site.history.scope.design') },
              ]"
            />
          </div>
          <div class="relative z-0 min-h-0 flex-1 overflow-y-auto overflow-x-hidden">
            <BuilderPalette v-if="panel.value === 'add'" :with-fields="builder.currentAvailable.value" />
            <BuilderOutline v-else-if="panel.value === 'outline'" />
            <BuilderEntryFields v-else-if="panel.value === 'fields'" :state="entryFields" :busy="saving" :reveal="revealedField" />
            <BuilderHistory
              v-else-if="historyScope === 'theme'"
              :key="`theme-${historyVersion}`"
              :fetch-revisions="api.site.themeRevisions"
              :fetch-revision="api.site.themeRevision"
              :busy="saving || site.saving || site.loading"
              @loaded="onThemeRevisionLoaded"
            />
            <BuilderHistory
              v-else
              :key="`layout-${historyVersion}-${editedTemplate}`"
              :fetch-revisions="fetchRevisions"
              :fetch-revision="fetchRevision"
              :busy="saving"
              @loaded="onRevisionLoaded"
            />
          </div>
        </template>
      </BuilderPanelWindow>

      <!-- Canvas -->
      <main class="builder-stage order-1 min-w-0 flex-1" :class="device === 'full' ? 'p-0' : 'p-2 xl:p-4'">
        <BuilderCanvas ref="canvasRef" :body="previewBody" :device="device" :selected-template-part="selectedTemplatePart" @keydown="onKeydown" @select-template-part="selectedTemplatePart = $event" @edit-field="editEntryField" @switch-page-area="selectPageArea" @rendered="onRendered" />
      </main>

      <div
        v-if="dropPreview"
        class="pointer-events-none absolute inset-y-0 z-[200] flex flex-col items-center justify-center gap-1 border-2 border-dashed border-theme-500 bg-theme-100/70 p-4 text-center"
        :style="{ [dropPreview.side]: `${dropPreview.offset}px`, width: `${dropPreview.width}px` }"
        aria-hidden="true"
      >
        <Icon :icon="dropPreview.side === 'left' ? 'mdi:dock-left' : 'mdi:dock-right'" class="h-6 w-6 text-theme-600" />
        <p class="text-sm font-semibold text-theme-800">{{ t(dropPreview.side === 'left' ? 'site.builder.dockLeft' : 'site.builder.dockRight') }}</p>
        <p v-if="dropPreview.occupant" class="text-xs text-theme-700">{{ t('site.builder.dockOutcome.replace', { panel: panelLabel(dropPreview.occupant) }) }}</p>
      </div>
      <div v-if="panelLayout.dragging.value" class="absolute inset-0 z-[1000] select-none" :class="panelLayout.resizing.value ? 'cursor-se-resize' : 'cursor-move'" aria-hidden="true"></div>

    </div>

    <BuilderContextMenu :device="device" />

    <BuilderGuide v-model="guideOpen" />

    <ConfirmModal
      v-model="overwriteOpen"
      :title="t('site.builder.saveMyLayout')"
      :message="t('site.builder.overwriteMessage')"
      :confirm-label="t('site.builder.saveMyLayout')"
      :loading="saving"
      @confirm="overwriteLayout"
    />

    <ComponentNameModal
      :model-value="builder.componentDialog.value !== null"
      :title="t('site.component.saveTitle')"
      :message="t('site.component.saveMessage')"
      :confirm-label="t('site.component.create')"
      :loading="converting"
      @update:model-value="(open) => { if (!open) builder.componentDialog.value = null }"
      @confirm="convert"
    />

    <ComponentNameModal
      v-model="templateDialogOpen"
      :title="t('site.templates.newTitle')"
      :message="t('site.templates.newFromShownMessage', { name: shownTemplate ? templateName(t, shownTemplate) : t('site.templates.defaultName') })"
      :confirm-label="t('site.templates.create')"
      :label="t('site.templates.name')"
      :placeholder="t('site.templates.namePlaceholder')"
      icon="mdi:page-layout-header-footer"
      :loading="creatingTemplate"
      @confirm="createTemplate"
    />

    <ComponentNameModal
      :model-value="builder.fieldDialog.value !== null"
      :title="t('site.field.convertTitle')"
      :message="t('site.field.convertMessage', { type: builder.type.value?.label ?? builder.type.value?.name ?? '' })"
      :confirm-label="t('site.field.create')"
      :label="t('site.field.name')"
      :placeholder="t('site.field.namePlaceholder')"
      :initial="fieldDialogInitial"
      icon="mdi:database-plus-outline"
      :loading="creatingField"
      @update:model-value="(open) => { if (!open) builder.fieldDialog.value = null }"
      @confirm="convertField"
    />

    <ConfirmModal
      v-model="leavePromptOpen"
      :title="t('unsaved.title')"
      :message="t('unsaved.message')"
      :confirm-label="t('unsaved.leave')"
      :cancel-label="t('unsaved.stay')"
      variant="warning"
      @confirm="confirmLeave"
    />
    <ConfirmModal
      :model-value="pendingView !== null"
      :title="t('unsaved.title')"
      :message="pendingView?.save ? t('site.scope.saveFirst', { title: entryTitle }) : t('unsaved.message')"
      :confirm-label="pendingView?.save ? t('site.scope.saveAndContinue') : t('unsaved.leave')"
      :loading-label="t('common.saving')"
      :cancel-label="t('unsaved.stay')"
      :loading="saving"
      variant="warning"
      @update:model-value="(open) => { if (!open) pendingView = null }"
      @confirm="confirmPendingView"
    />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Icon } from "@iconify/vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import LoadingSpinner from "../components/LoadingSpinner.vue";
import { useSitePreview } from "../composables/useSitePreview.js";
import SplitButton from "../components/SplitButton.vue";
import DeviceToggle from "../components/site/DeviceToggle.vue";
import SegmentedControl from "../components/site/SegmentedControl.vue";
import BuilderCanvas from "../components/site/builder/BuilderCanvas.vue";
import BuilderContextMenu from "../components/site/builder/BuilderContextMenu.vue";
import BuilderInspector from "../components/site/builder/BuilderInspector.vue";
import BuilderOutline from "../components/site/builder/BuilderOutline.vue";
import BuilderPageSwitcher from "../components/site/builder/BuilderPageSwitcher.vue";
import BuilderEntrySwitcher from "../components/site/builder/BuilderEntrySwitcher.vue";
import BuilderPalette from "../components/site/builder/BuilderPalette.vue";
import { componentIcon } from "../components/site/builder/kindIcons.js";
import BuilderEntryFields from "../components/site/builder/BuilderEntryFields.vue";
import BuilderPanelWindow from "../components/site/builder/BuilderPanelWindow.vue";
import BuilderHistory from "../components/site/builder/BuilderHistory.vue";
import CustomCssPanel from "../components/site/builder/CustomCssPanel.vue";
import SiteDesignPanel from "../components/site/builder/SiteDesignPanel.vue";
import BuilderGuide from "../components/site/builder/BuilderGuide.vue";
import BuilderHelpMenu from "../components/site/builder/BuilderHelpMenu.vue";
import BuilderOverview from "../components/site/builder/BuilderOverview.vue";
import BuilderTemplateMenu from "../components/site/builder/BuilderTemplateMenu.vue";
import ComponentNameModal from "../components/site/ComponentNameModal.vue";
import LanguageTabs from "../components/LanguageTabs.vue";
import { api, getActiveWorkspace } from "../api/index.js";
import { createBuilderRecovery } from "../composables/builderRecovery.js";
import { createSiteBuilder } from "../composables/useSiteBuilder.js";
import { useBuilderPanels } from "../composables/useBuilderPanels.js";
import { useBuilderEntry } from "../composables/useBuilderEntry.js";
import { usePermissions } from "../composables/usePermissions.js";
import { MODAL, findNode } from "../composables/layoutTree.js";
import { DEFAULT_TEMPLATE, FRAME_DEFAULT, FRAME_NONE, FRAME_REGIONS, frameComponents, templateById, templateList, templateName, usesTemplates, withTemplate } from "../composables/templates.js";
import { useUnsavedChangesGuard } from "../composables/useUnsavedChangesGuard.js";
import { useAuthStore } from "../stores/auth.js";
import { useSiteStore } from "../stores/site.js";
import { useToastStore } from "../stores/toast.js";
import { resolveLocalized } from "../composables/localizedValues.js";
import { useLanguages } from "../composables/useLanguages.js";
import { useI18n } from "../i18n/index.js";
import { vTooltip } from "../directives/tooltip.js";

const route = useRoute();
const router = useRouter();
const selectedTemplatePart = ref(null);
const site = useSiteStore();
const auth = useAuthStore();
const { canContent } = usePermissions();
const toast = useToastStore();
const fullPreview = useSitePreview();
const { t, locale: uiLocale } = useI18n();
const editorLanguages = useLanguages();
const builder = createSiteBuilder({
  t,
  componentApi: {
    create: async (body) => (await api.site.createComponent(body)).data,
    rename: async (id, name) => (await api.site.renameComponent(id, name)).data,
    remove: async (id) => (await api.site.deleteComponent(id)).data.component,
    load: async (id) => {
      try {
        return (await api.site.component(id)).data.component;
      } catch (err) {
        toast.error(err.message);
        return null;
      }
    },
    // Unsaved changes are guarded like any other way out of the builder.
    edit: (id) => router.push({ path: `/page-builder/builder/component/${id}`, query: { back: route.fullPath } }),
  },
  templateApi: {
    load: async (type) => {
      try {
        return (await api.site.cardTemplate(type)).data;
      } catch (err) {
        toast.error(err.message);
        return null;
      }
    },
  },
  fieldApi: {
    // Only offered to those who may change the content type.
    can: (type) => auth.can("schema.read", `schema:${type}`) && auth.can("schema.update", `schema:${type}`),
    create: async ({ type, key, config }) => {
      const schema = (await api.contentTypes.get(type)).data;
      if (schema.fields?.[key]) throw new Error(t("site.field.exists", { key }));
      return (await api.contentTypes.update(type, { ...schema, fields: { ...schema.fields, [key]: config } })).data;
    },
    update: async ({ type, key, config }) => {
      const schema = (await api.contentTypes.get(type)).data;
      if (!schema.fields?.[key]) throw new Error(t("site.field.missing", { key }));
      return (await api.contentTypes.update(type, { ...schema, fields: { ...schema.fields, [key]: { ...schema.fields[key], label: config.label } } })).data;
    },
  },
  // Design and header/footer assignments are undone and redone together with layout edits.
  linked: {
    get: () => site.draft ? { theme: site.draft.theme, templates: site.draft.templates } : null,
    set: (linked) => {
      if (!site.draft || !linked) return;
      site.draft.theme = linked.theme;
      site.draft.templates = linked.templates ?? {};
    },
  },
});
// Record design edits together with layout edits, including revision loading.
let changingRevisionTheme = false;
watch(() => [site.draft?.theme, site.draft?.templates], () => {
  builder.linkedChanged({ record: !changingRevisionTheme && !site.saving && !site.loading });
}, { deep: true, flush: "sync" });

const loadedRevisions = ref({ layout: null, theme: null });
const clone = (value) => JSON.parse(JSON.stringify(value));

function formatRevisionDate(value) {
  return new Date(value).toLocaleString(uiLocale.value, { dateStyle: "medium", timeStyle: "medium" });
}

function changeRevisionTheme(theme) {
  changingRevisionTheme = true;
  try {
    builder.mutate(() => { site.draft.theme = clone(theme); });
  } finally {
    changingRevisionTheme = false;
  }
}

function onThemeRevisionLoaded(theme, revision) {
  if (!site.draft) return;
  const before = loadedRevisions.value.theme?.before ?? clone(site.draft.theme);
  loadedRevisions.value.theme = { before, revision };
  changeRevisionTheme(theme);
}

function cancelRevision(scope) {
  const pending = loadedRevisions.value[scope];
  if (!pending) return;
  if (scope === "theme") changeRevisionTheme(pending.before);
  else builder.loadRevision(pending.before);
  loadedRevisions.value[scope] = null;
}

/** Pending design edits belong to the whole site: they stay while moving between builder pages. */
const themeDirty = computed(() => site.dirtyParts.includes("theme"));
/** Template changes (parts assigned in the inspector) are saved with the layout, but stored apart (see SiteRepository::templates()). */
const templatesDirty = computed(() => site.dirtyParts.includes("templates"));
/** The pending design edits include the custom stylesheet, or are only that. */
const cssDirty = computed(() => themeDirty.value && (site.draft?.theme?.custom_css ?? "") !== (site.data?.theme?.custom_css ?? ""));
const cssOnlyDirty = computed(() => cssDirty.value && JSON.stringify({ ...site.draft.theme, custom_css: "" }) === JSON.stringify({ ...site.data.theme, custom_css: "" }));
const { leavePromptOpen, confirmLeave, bypass } = useUnsavedChangesGuard(
  (to) => builder.isDirty.value || entryFields.isDirty.value || ((themeDirty.value || templatesDirty.value) && !isBuilderPath(to?.path)),
  { guardUpdates: true },
);

const COMPONENTS_PATH = "/page-builder/components";
const builderAreaRef = ref(null);
const panelLayout = useBuilderPanels({
  area: builderAreaRef,
  ids: ["inspector", "add", "outline", "fields", "design", "css", "history"],
  initial: ["design", "css"].includes(route.query.settings) ? route.query.settings : null,
});
const panelWindows = panelLayout.windows;
const dropPreview = panelLayout.dropPreview;
const historyScope = ref("layout");
/** Bumped after saving so an open history list shows the version the save kept. */
const historyVersion = ref(0);
const panels = computed(() => [
  { value: "add", label: t("site.builder.add"), icon: "mdi:plus-box-outline" },
  { value: "inspector", label: t("site.builder.inspector"), icon: "mdi:tune-variant" },
  { value: "outline", label: t("site.builder.outline"), icon: "mdi:file-tree-outline" },
  ...(hasEntryFields.value ? [{ value: "fields", label: t("site.builder.entryFields"), icon: "mdi:file-document-edit-outline", dirty: entryFields.isDirty.value }] : []),
  { value: "design", label: t("site.builder.designSettings"), icon: "mdi:palette-outline", dirty: themeDirty.value && !cssOnlyDirty.value },
  { value: "css", label: t("site.theme.customCss"), icon: "mdi:language-css3", dirty: cssDirty.value },
  { value: "history", label: t("site.history.title"), icon: "mdi:history" },
]);
const visiblePanels = computed(() => panels.value.filter((panel) => panelWindows.value[panel.value].open));
function panelLabel(value) {
  return panels.value.find((panel) => panel.value === value)?.label ?? value;
}
function panelHint(panel) {
  const key = `site.builder.panelHint.${panel}`;
  const text = t(key);
  return text === key ? "" : text;
}

const GUIDE_SEEN_KEY = "cometcms-builder-guide-seen";
const guideOpen = ref(false);
// First visit: explain the builder once.
try {
  if (!localStorage.getItem(GUIDE_SEEN_KEY)) {
    guideOpen.value = true;
    localStorage.setItem(GUIDE_SEEN_KEY, "1");
  }
} catch {
  // Storage may be unavailable (private mode); the guide stays one click away.
}

/** What a builder route asks to edit. */
function contextFor(to) {
  const target = String(to.params.target);
  const page = target === "page";
  return {
    target,
    name: String(to.params.name),
    /** The page builder of one entry edits its page content inside the type's detail layout. */
    pageEntry: page ? String(to.params.variant ?? "") : "",
    variant: page ? "detail" : String(to.params.variant || "detail"),
    field: page && typeof to.query.field === "string" ? to.query.field : "",
    /** The template whose content a layout edits; empty lets the server pick the entry's template. */
    template: !page && typeof to.query.template === "string" ? to.query.template : "",
  };
}
// No template asked for means whichever template is loaded.
const sameContext = (a, b) => ["target", "name", "pageEntry", "variant", "field"].every((key) => a[key] === b[key])
  && (a.template === b.template || !a.template || !b.template);
/** What the builder shows. Moving to another builder page keeps this one until the next one's content arrives. */
const shown = ref(contextFor(route));
/** What the builder shows or is loading: the latest request wins. */
let requested = shown.value;
const target = computed(() => shown.value.target);
const name = computed(() => shown.value.name);
const pageEntry = computed(() => shown.value.pageEntry);
const variant = computed(() => shown.value.variant);
/** The page field being edited; the type's first until the page loads. */
const pageField = ref(shown.value.field);
/** The entry has page content of its own (not the layout's default content). */
const pageCustomized = ref(false);
const pageTitle = ref("");
/** The default design (generated layout, header or footer, or the layout's default page content) that Reset brings back. */
const defaultNodes = ref(null);
/** A layout, header or footer has a saved design of its own (not the generated default). */
const layoutCustomized = ref(false);
/** The entry's stable ID: builders name entries by it, as slugs differ between languages. */
const pageEntryId = ref(pageEntry.value);
const historyScopeKey = computed(() => {
  if (target.value === "page") return "site.history.scope.page";
  return target.value === "component" ? "site.history.scope.component" : "site.history.scope.layout";
});

/** Nothing to show yet: the first load. */
const loading = ref(true);
/** Another view of this builder loads while the current one stays on screen. */
const switching = ref(false);
/** The switch takes long enough to say so; quick ones show nothing. */
const switchingSlow = ref(false);
let switchingTimer = null;
watch(switching, (value) => {
  clearTimeout(switchingTimer);
  if (value) switchingTimer = setTimeout(() => { switchingSlow.value = true; }, 150);
  else switchingSlow.value = false;
});
/** Only the latest load applies; slower earlier ones are dropped. */
let loadSequence = 0;
const upgradeRequired = ref(false);
const layoutRevision = ref(null);
const loadError = ref("");
const saving = ref(false);
const samples = ref([]);
const sampleId = ref("");
const locales = ref([]);
const locale = ref("");
const device = ref("desktop");
// The inspector edits overrides of the previewed device; full width shows the desktop layout.
watch(device, (value) => { builder.device.value = value === "full" ? "desktop" : value; }, { immediate: true });
/** A layer to switch to once unsaved changes may be left behind. */
const pendingView = ref(null);
const canvasRef = ref(null);
const indexUrl = ref(null);
const homeUrl = ref(null);
/** The website's address answers for another workspace, so there is no live page to open. */
const liveConflict = ref(false);
const entryUrl = ref(null);
const component = ref(null);
const usages = ref([]);
const converting = ref(false);

/** The layout has changes that can be saved. */
const layoutSavable = computed(() => (builder.isDirty.value || upgradeRequired.value) && !builder.hasSyntaxErrors.value);

/** Type names of a component's samples, which come from every type. */
const sampleTypeLabels = computed(() => Object.fromEntries(builder.types.value.map((type) => [type.name, resolveLocalized(type.label, locale.value, builder.settings.value?.default_locale || "en") || type.name])));
/** The entry the preview shows: the chosen sample. */
const previewEntry = computed(() => sampleId.value);
/** Layouts of single entries and components of a content type preview an entry. */
const showsSamples = computed(() => (target.value === "layout" && variant.value !== "index") || (target.value === "component" && !!builder.type.value));
const hasEntryFields = computed(() => (showsSamples.value || target.value === "page") && !!previewEntry.value);
watch([hasEntryFields, loading], ([available, pending]) => {
  if (!available && !pending) panelLayout.close("fields");
});
const entryFields = useBuilderEntry({
  type: builder.type,
  entryId: computed(() => hasEntryFields.value ? previewEntry.value : ""),
  locale,
  contentApi: api.content,
  canUpdate: (type, entry) => canContent("content.update", type, entry),
});

const recovery = createBuilderRecovery({ workspace: getActiveWorkspace(), user: auth.user?.id || auth.user?.username });
const layoutBaseline = ref([]);
const recoveredDraft = ref(false);
const recoveryFailed = ref(false);
const layoutConflict = ref(false);
const overwriteOpen = ref(false);
let recoveryActive = false;
let recoveryTimer = null;

function checkpointRecovery() {
  clearTimeout(recoveryTimer);
  if (!recoveryActive || loading.value || switching.value || loadError.value) return;
  const layout = builder.isDirty.value || upgradeRequired.value ? {
    nodes: builder.nodes.value, baseline: layoutBaseline.value, revision: layoutRevision.value,
    pending: loadedRevisions.value.layout,
  } : null;
  if (!layout) layoutConflict.value = false;
  if (!layout && !themeDirty.value && !templatesDirty.value && !entryFields.isDirty.value) {
    recovery.remove(shown.value);
    recoveredDraft.value = recoveryFailed.value = false;
    return;
  }
  recoveryFailed.value = !recovery.write(shown.value, {
    layout,
    theme: themeDirty.value ? site.draft.theme : null,
    templates: templatesDirty.value ? { draft: site.draft.templates, revision: site.data.templates_revision } : null,
    entries: entryFields.recoveryDraft.value,
    layoutConflict: layoutConflict.value,
  });
}

async function restoreRecovery(current) {
  const saved = recovery.read(shown.value);
  if (!saved) return;
  await site.load();
  if (current !== loadSequence) return;
  if (saved.layout && Array.isArray(saved.layout.nodes) && Array.isArray(saved.layout.baseline)) {
    // Retain the original revision; recovering a draft must not silently overwrite newer work.
    layoutConflict.value = saved.layoutConflict || saved.layout.revision !== layoutRevision.value;
    layoutRevision.value = saved.layout.revision;
    layoutBaseline.value = saved.layout.baseline;
    builder.load(saved.layout.baseline);
    builder.loadRevision(saved.layout.nodes);
    loadedRevisions.value.layout = saved.layout.pending;
  }
  if (saved.theme) site.draft.theme = saved.theme;
  if (saved.templates) {
    site.draft.templates = saved.templates.draft;
    site.data.templates_revision = saved.templates.revision;
  }
  entryFields.restoreDraft(saved.entries);
  recoveryActive = true;
  recoveredDraft.value = true;
}

// Once a save is attempted, later edits also update its recovery copy.
watch(() => [builder.nodes.value, site.draft?.theme, site.draft?.templates, entryFields.recoveryDraft.value], () => {
  clearTimeout(recoveryTimer);
  if (recoveryActive) recoveryTimer = setTimeout(checkpointRecovery, 200);
}, { deep: true });

/** Drop a recovered draft and show the saved state again, without saving anything. */
async function discardRecovery() {
  if (saving.value || switching.value) return;
  recovery.remove(shown.value);
  leftView();
  await site.load({ force: true });
  await load(shown.value);
}

async function overwriteLayout() {
  if (saving.value || switching.value || !layoutConflict.value) return;
  saving.value = true;
  try {
    let response;
    if (target.value === "page") response = await api.site.page(name.value, pageEntry.value, pageField.value);
    else if (target.value === "region") response = await api.site.region(name.value);
    else if (target.value === "component") response = await api.site.component(name.value);
    else response = await api.site.layout(name.value, variant.value, sampleId.value, editedTemplate.value);
    layoutRevision.value = response.data.revision;
    overwriteOpen.value = false;
  } catch (error) {
    toast.error(error.message);
    return;
  } finally {
    saving.value = false;
  }
  // A second concurrent change still returns a conflict: no revision check is bypassed.
  await save();
}

const editingModal = computed(() => target.value === "region" && name.value === MODAL);

/** The live page of what is being edited, when there is one. */
const liveUrl = computed(() => {
  if (target.value === "component" || editingModal.value || liveConflict.value) return null;
  const settings = builder.settings.value;
  const prefix = locale.value && locale.value !== settings?.default_locale ? `/${locale.value}` : '';
  const root = (homeUrl.value || '').replace(/\/$/, '');
  // The search results page has an address of its own; other regions show on the homepage.
  if (target.value === "region" && name.value === "search_results") return `${root}${prefix}/search`;
  if (target.value === "region") return root + prefix || homeUrl.value;
  if (variant.value === "index") {
    const home = settings?.homepage;
    const path = home?.type === name.value && !home.entry ? '/' : resolveLocalized(settings?.routes?.[name.value]?.index_path, locale.value, settings?.default_locale);
    return path ? root + prefix + (path === '/' && prefix ? '' : path) : indexUrl.value;
  }
  return entryUrl.value;
});
/** Why the live page cannot be opened, shown on the disabled menu item. */
const liveHint = computed(() => {
  if (editingModal.value) return t("site.modal.noPage");
  if (target.value === "component") return t("site.builder.componentNoPage");
  if (liveConflict.value) return t("site.builder.liveConflict");
  if (target.value === "region") return t("site.builder.siteOff");
  if (variant.value === "index") return t("site.builder.noLiveIndex");
  return previewEntry.value ? t("site.builder.notLive") : t("site.builder.noEntry");
});
/** Full-page previews use the same draft as the canvas. */
const canPreview = computed(() => !loading.value && (target.value !== "layout" || variant.value === "index" || !!previewEntry.value));
async function onSaveMenuSelect(item) {
  if (item.key !== "preview" || !canPreview.value || !previewBody.value) return;
  try {
    await fullPreview.open(previewBody.value);
  } catch (error) {
    toast.error(error.message);
  }
}
const previewHint = computed(() => (target.value === "component" ? t("site.builder.componentNoPage") : t("site.builder.noEntry")));
// Preview and live actions are always offered; unavailable ones say why.
const saveMenuItems = computed(() => [
  ...(target.value === "page" ? [
    {
      key: "reset-page",
      label: t("site.page.reset"),
      icon: "mdi:restore",
      disabled: saving.value || (!pageCustomized.value && !builder.isDirty.value),
      title: pageCustomized.value || builder.isDirty.value ? t("site.page.resetHint") : t("site.page.usesDefault"),
    },
  ] : []),
  ...(["layout", "region"].includes(target.value) ? [{
    key: "reset-layout",
    label: t("site.builder.resetDefault"),
    icon: "mdi:restore",
    disabled: saving.value || (!layoutCustomized.value && !builder.isDirty.value),
    title: layoutCustomized.value || builder.isDirty.value ? t("site.builder.resetDefaultHint") : t("site.builder.usesDefault"),
  }] : []),
  {
    key: "preview",
    label: t("site.entry.preview"),
    icon: "mdi:eye-outline",
    disabled: !canPreview.value || fullPreview.opening.value,
    title: !canPreview.value ? previewHint.value : t("site.builder.previewUnsaved"),
  },
  {
    key: "live",
    label: t("site.builder.viewLive"),
    icon: "mdi:open-in-new",
    href: liveUrl.value,
    target: "_blank",
    disabled: !liveUrl.value,
    title: liveUrl.value || liveHint.value,
  },
  {
    key: "exit",
    label: t("site.builder.exit"),
    icon: "mdi:exit-to-app",
    separatorBefore: true,
  },
]);
watch([previewEntry, locale], async ([entry]) => {
  entryUrl.value = null;
  if (!["layout", "page"].includes(target.value) || !entry) return;
  try {
    entryUrl.value = (await api.site.links(name.value, entry, { locale: locale.value })).data?.url ?? null;
  } catch {
    entryUrl.value = null;
  }
});

/**
 * The template the picked entry uses on the website. Before the first preview
 * names it, only a page kind without templates is certain.
 */
const liveTemplate = computed(() => (showsTemplates.value ? shownTemplate.value?.live ?? null : DEFAULT_TEMPLATE));

/**
 * The entry's own page content: reached from its entry page without losing the
 * entry or where the builder was opened from. On a template it is only offered
 * while the canvas holds a page content area and the entry uses this template
 * on the website, so the entry is never designed somewhere it does not appear.
 */
const pageView = computed(() => {
  const type = builder.type.value;
  if (!type || !(target.value === "page" || (target.value === "layout" && variant.value === "detail"))) return null;
  const entry = target.value === "page" ? pageEntryId.value : sampleId.value;
  if (!entry) return null;
  const field = target.value === "page" ? pageField.value : editedTemplate.value === liveTemplate.value ? designableField() : null;
  if (!field) return null;
  const back = typeof route.query.back === "string" ? { back: route.query.back } : {};
  return {
    key: "page",
    entryTitle: entryTitle.value,
    to: { path: `/page-builder/builder/page/${name.value}/${entry}`, query: { field, ...back } },
  };
});

/** The template's area an entry designs: the selected Entry content element's, else the first. */
function designableField() {
  const placed = builder.placedPageFields();
  const selected = builder.selectedId.value ? findNode(builder.nodes.value, builder.selectedId.value) : null;
  return (placed.find((field) => selected?.kind === "page_content" && field.key === selected.props?.field) ?? placed[0])?.key ?? null;
}

/** The entry the canvas shows: the edited one, or the template's sample. */
const entryTitle = computed(() => {
  const entry = target.value === "page" ? pageEntryId.value : sampleId.value;
  return (target.value === "page" ? pageTitle.value : samples.value.find((sample) => sample.id === entry)?.title) || entry;
});

/**
 * The entry breadcrumb, on detail pages with entries. When the
 * entry cannot be designed on its own here, it stays visible and says why.
 */
const entrySide = computed(() => {
  if (frameLayout.value?.variant !== "detail" || !entryTitle.value) return null;
  const named = (id) => templateName(t, templateById(site.draft?.templates, name.value, "detail", id));
  const live = liveTemplate.value;
  const unavailable = pageView.value ? ""
    : live && live !== editedTemplate.value ? t("site.scope.entryOtherTemplate", { title: entryTitle.value, template: named(live) })
    : t("site.scope.entryNoArea", { template: named(editedTemplate.value) });
  return { title: t("site.view.pageHint", { title: entryTitle.value }), unavailable };
});
const currentView = computed(() => (target.value === "page" ? "page" : variant.value));
/** The shared template around the entry: without a template asked for, the builder opens the entry's. */
const templateView = computed(() => {
  const entry = target.value === "page" ? pageEntryId.value : sampleId.value;
  return {
    key: "detail",
    to: {
      path: `/page-builder/builder/layout/${name.value}/detail`,
      query: { ...(entry ? { entry } : {}), ...(typeof route.query.back === "string" ? { back: route.query.back } : {}) },
    },
  };
});
/** Both exit controls leave the builder, regardless of navigation between its views. */
const exitTo = computed(() => {
  if (target.value === "page") return `/content/${name.value}/${pageEntryId.value || pageEntry.value}`;
  if (target.value === "layout") return `/content/${name.value}`;
  const back = typeof route.query.back === "string" ? route.query.back : "";
  if (back.startsWith("/content/")) return back;
  if (target.value === "component") return COMPONENTS_PATH;
  return "/dashboard";
});
const heading = computed(() => {
  if (target.value === "component") return component.value?.name ?? "";
  if (target.value === "page") return pageTitle.value || pageEntry.value;
  return target.value === "region" ? t(`site.region.${name.value}`) : builder.type.value?.label ?? name.value;
});
const subheading = computed(() => {
  if (target.value === "component") {
    const kind = t("site.component.instanceOf", { kind: builder.kindLabel(component.value?.root ?? "section") });
    // Components fit every type; the entry switcher beside it shows which one the preview uses.
    return `${kind} · ${t("site.component.usedIn", { count: usages.value.length })}`;
  }
  if (target.value === "page") {
    const fields = builder.pageFields();
    // The crumbs already name the type, layout and entry: the subtitle says what saving changes.
    const area = fields.length > 1 ? ` · ${fields.find((field) => field.key === pageField.value)?.label ?? pageField.value}` : "";
    return `${t("site.scope.entryContent")}${area}`;
  }
  if (target.value === "layout" && variant.value === "detail") return t("site.scope.sharedLayout");
  if (target.value === "region") return t(editingModal.value ? "site.builder.modalSubtitle" : name.value === "not_found" ? "site.builder.notFoundSubtitle" : name.value === "maintenance" ? "site.builder.maintenanceSubtitle" : name.value === "search_results" ? "site.builder.searchResultsSubtitle" : "site.builder.regionSubtitle");
  return t(`site.variant.${variant.value}`);
});

const saveLabel = computed(() => {
  if (target.value === "page") return t("site.builder.savePage");
  return target.value === "layout" ? t("site.builder.saveTemplate") : t("site.builder.save");
});

/** Says whether the canvas changes the shared template or only one entry, with the way to the other. */
const scopeBar = computed(() => {
  if (loading.value || loadError.value) return null;
  if (target.value === "page") {
    const own = pageCustomized.value;
    return {
      kind: "page",
      icon: "mdi:file-document-edit-outline",
      title: t("site.scope.entryContent"),
      text: t(own ? "site.scope.pageOwn" : "site.scope.pageDefault"),
      actions: [
        { key: "template", icon: "mdi:arrow-left", label: t("site.scope.toTemplate"), run: () => goToView(templateView.value) },
        // A reset already waiting to be saved has its own banner.
        ...(own && !loadedRevisions.value.layout ? [{ key: "reset", icon: "mdi:restore", label: t("site.page.reset"), run: resetToDefault }] : []),
      ],
    };
  }
  if (target.value !== "layout" || variant.value !== "detail") return null;
  const live = liveTemplate.value;
  const elsewhere = !pageView.value && entrySide.value && live && live !== editedTemplate.value;
  return {
    kind: "template",
    icon: "mdi:page-layout-header-footer",
    title: t("site.scope.sharedLayout"),
    text: t(builder.placedPageFields().length ? "site.scope.templateWithPages" : "site.scope.templateText", { type: builder.type.value?.label ?? name.value }),
    note: entrySide.value?.unavailable ?? "",
    actions: pageView.value
      ? [{ key: "page", icon: "mdi:arrow-right", label: t("site.scope.toPage", { title: pageView.value.entryTitle }), run: () => goToView(pageView.value) }]
      : elsewhere
        ? [{ key: "live", icon: "mdi:arrow-right", label: t("site.scope.openTemplate", { template: templateName(t, templateById(site.draft?.templates, name.value, "detail", live)) }), run: () => openTemplate(live) }]
        : [],
  };
});

const saveStatus = computed(() => {
  if (saving.value) return "saving";
  return builder.isDirty.value || themeDirty.value || templatesDirty.value || entryFields.isDirty.value ? "unsaved" : "saved";
});

/** What the inspector shows while nothing is selected. */
const overview = computed(() => {
  if (target.value === "component") {
    return {
      title: heading.value,
      description: t("site.builder.overview.component", { count: usages.value.length }),
      icon: componentIcon(component.value),
    };
  }
  if (target.value === "page") {
    return {
      title: heading.value,
      description: t("site.builder.overview.page", { type: builder.type.value?.label ?? name.value }),
      icon: "mdi:file-document-edit-outline",
    };
  }
  if (target.value === "region") {
    return {
      title: heading.value,
      description: t(`site.region.${name.value}Hint`),
      icon: { footer: "mdi:page-layout-footer", not_found: "mdi:file-question-outline", maintenance: "mdi:tools", search_results: "mdi:text-search", [MODAL]: "mdi:dock-window" }[name.value] ?? "mdi:page-layout-header",
    };
  }
  const type = builder.type.value?.label ?? name.value;
  return {
    title: `${type} · ${t(`site.variant.${variant.value}`)}`,
    description: t(variant.value === "index" ? "site.builder.overview.index" : showsTemplates.value ? "site.builder.overview.detailArea" : "site.builder.overview.detail", { type }),
    icon: builder.type.value?.icon || (variant.value === "index" ? "mdi:view-grid-outline" : "mdi:file-document-outline"),
  };
});

const previewBody = computed(() => {
  if (loading.value) return null;
  // The canvas always shows the draft design: unsaved edits, and a restored version as soon as it arrives.
  const theme = {
    ...(site.draft?.theme ? { theme: site.draft.theme, font_version: site.data?.font_version } : {}),
    ...(site.draft?.menus ? { menus: site.draft.menus } : {}),
    ...(site.draft?.templates ? { templates: site.draft.templates } : {}),
    entries: entryFields.previewEntries(),
  };
  if (target.value === "component") {
    return { target: "component", component: name.value, nodes: builder.nodes.value, entry: sampleId.value, entry_type: builder.type.value?.name ?? "", locale: locale.value, ...theme };
  }
  if (target.value === "page") {
    return { target: "entry_page", type: name.value, entry: pageEntry.value, field: pageField.value, nodes: builder.nodes.value, locale: locale.value, ...theme };
  }
  return target.value === "region"
    ? { target: "region", region: name.value, nodes: builder.nodes.value, locale: locale.value, ...theme }
    : { target: "layout", type: name.value, variant: variant.value, template: editedTemplate.value, nodes: builder.nodes.value, entry: sampleId.value, locale: locale.value, ...theme };
});

function isBuilderPath(path) {
  return typeof path === "string" && path.startsWith("/page-builder/builder/");
}

/** The page kind whose templates the canvas shows, when there is one. */
const frameLayout = computed(() => ["layout", "page"].includes(target.value) && builder.type.value
  ? { type: name.value, variant: target.value === "page" ? "detail" : variant.value } : null);

/** The template the preview last showed around the content: `{ …template, reason, live }`. */
const shownTemplate = ref(null);
/** Another entry was picked: the canvas moves to the template it uses on the website, once its preview names it. */
let followEntry = "";
/** What a preview shows; one that arrives for another view, entry or template is left out. */
const RENDER_KEYS = ["target", "type", "variant", "template", "entry", "field", "component", "region"];
function onRendered(data, body) {
  const current = previewBody.value;
  if (!current || RENDER_KEYS.some((key) => body?.[key] !== current[key])) return;
  shownTemplate.value = data?.template ?? null;
  if (!followEntry || body.entry !== followEntry) return;
  followEntry = "";
  const live = shownTemplate.value?.live;
  if (target.value === "layout" && !builder.isDirty.value && live && live !== editedTemplate.value) openTemplate(live);
}

/** The shown template as the draft has it now: the inspector assigns its header and footer. */
const shownDraft = computed(() => {
  const layout = frameLayout.value;
  return layout && shownTemplate.value ? templateById(site.draft?.templates, layout.type, layout.variant, shownTemplate.value.id) : null;
});

/** The template whose content the canvas edits (on an entry page: the one the entry uses). */
const editedTemplate = computed(() => shown.value.template || DEFAULT_TEMPLATE);
function openTemplate(id) {
  const entry = sampleId.value || pageEntryId.value;
  const back = typeof route.query.back === "string" ? { back: route.query.back } : {};
  router.push({ path: `/page-builder/builder/layout/${name.value}/${frameLayout.value?.variant ?? variant.value}`, query: { template: id, ...(entry ? { entry } : {}), ...back } });
}

const templateDialogOpen = ref(false);
const creatingTemplate = ref(false);
/** A new template starting as a copy of the shown one, header, footer and content; the canvas then edits it. */
async function createTemplate(newName) {
  const layout = frameLayout.value;
  const source = shownDraft.value;
  if (!layout || creatingTemplate.value) return;
  creatingTemplate.value = true;
  try {
    const created = await site.createTemplate(layout.type, layout.variant, newName, source?.id ?? "", source ? { header: source.header, footer: source.footer } : {});
    templateDialogOpen.value = false;
    toast.success(t("site.templates.created", { name: created.name }));
    openTemplate(created.id);
  } catch (err) {
    toast.error(err.message);
  } finally {
    creatingTemplate.value = false;
  }
}

/** Template controls only show once the page kind has more than its default template. */
const showsTemplates = computed(() => !!frameLayout.value && usesTemplates(site.draft?.templates, frameLayout.value.type, frameLayout.value.variant));

/** The layout breadcrumb's menu, for layouts and entry pages. */
const templateMenu = computed(() => {
  const layout = frameLayout.value;
  if (!layout) return null;
  // Until the first preview says why, a layout names the template it edits.
  const edited = target.value === "layout" ? templateById(site.draft?.templates, layout.type, layout.variant, editedTemplate.value) : null;
  return {
    shown: shownTemplate.value ? { ...(shownDraft.value ?? shownTemplate.value), reason: shownTemplate.value.reason, live: shownTemplate.value.live } : edited,
    templates: templateList(site.draft?.templates, layout.type, layout.variant),
    type: layout.type,
    variant: layout.variant,
    schema: builder.type.value,
    components: builder.components.value,
  };
});

/** What the selected header or footer shows in the shown template, and what else it may show. */
const selectedFrame = computed(() => {
  const layout = frameLayout.value;
  const template = shownDraft.value;
  if (!layout || !template || !FRAME_REGIONS.includes(selectedTemplatePart.value)) return null;
  return {
    value: template[selectedTemplatePart.value],
    components: frameComponents(builder.components.value),
    scope: templateName(t, template),
  };
});

function assignTemplatePart(part, value) {
  const layout = frameLayout.value;
  const template = shownDraft.value;
  if (!layout || !template || !site.draft || !FRAME_REGIONS.includes(part)) return;
  site.draft.templates = withTemplate(site.draft.templates, layout.type, layout.variant, template.id, { [part]: value });
}

function editTemplatePart(part) {
  if (!FRAME_REGIONS.includes(part)) return;
  const value = selectedFrame.value?.value ?? FRAME_DEFAULT;
  if (value === FRAME_NONE) return;
  const path = value === FRAME_DEFAULT ? `/page-builder/builder/region/${part}` : `/page-builder/builder/component/${encodeURIComponent(value)}`;
  router.push({ path, query: { back: route.fullPath } });
}

watch(() => builder.pageFieldError.value, (message) => {
  if (!message) return;
  toast.error(message);
  builder.pageFieldError.value = "";
});

watch(() => builder.selectedId.value, (id) => {
  if (id) {
    selectedTemplatePart.value = null;
    panelLayout.open("inspector");
  }
});
watch(selectedTemplatePart, (part) => {
  if (part) panelLayout.open("inspector");
});

/** The field the Custom Fields panel brings into view: `{ name }`. */
const revealedField = ref(null);
/** Double-clicked smart tag content edits its field of the previewed entry. */
function editEntryField({ fields, text }) {
  const names = hasEntryFields.value ? fields.filter((field) => field in entryFields.fields.value) : [];
  if (!names.length) return;
  const plain = (field) => String(entryFields.valueFor(field) ?? "").replace(/<[^>]+>/g, " ");
  // In text mixing several tags, the clicked word picks the field that contains it.
  const name = (text && names.find((field) => plain(field).includes(text))) || names[0];
  panelLayout.open("fields");
  revealedField.value = { name };
}

watch(locale, (value) => { editorLanguages.activeLocale.value = value; builder.locale.value = value; });

async function load(next = requested) {
  const current = ++loadSequence;
  // Once the builder shows, later loads keep it on screen until their content arrives.
  if (loading.value || loadError.value) loading.value = true;
  else switching.value = true;
  loadError.value = "";
  requested = next;
  const { target, name, pageEntry, variant, field } = next;

  try {
    const entry = typeof route.query.entry === "string" ? route.query.entry : "";
    let res;
    if (target === "page") res = await api.site.page(name, pageEntry, field);
    else if (target === "region") res = await api.site.region(name);
    else res = target === "component" ? await api.site.component(name, entry) : await api.site.layout(name, variant, entry, next.template);
    if (current !== loadSequence) return;
    const data = res.data;
    const moved = !sameContext(next, shown.value);
    // Clear what belonged to the page that was left before the new one's state comes in.
    if (moved) {
      recovery.remove(shown.value);
      leftView();
    }
    shown.value = next;
    loadedRevisions.value.layout = null;
    layoutRevision.value = data.revision;
    builder.types.value = data.types ?? [];
    builder.menus.value = data.menus ?? [];
    builder.forms.value = data.forms ?? [];
    builder.settings.value = data.settings ?? null;
    builder.type.value = data.type ?? null;
    builder.components.value = data.components ?? [];
    if (target === "component") {
      component.value = data.component;
      usages.value = data.usages ?? [];
      builder.componentId.value = data.component.id;
      builder.rootKind.value = data.component.root;
      // A component's tree is its one root node.
      builder.spec.value = { ...data.spec, root: [data.component.root] };
      // Components fit every type, so the entry's fields are always on offer.
      builder.currentAvailable.value = true;
    } else {
      builder.spec.value = data.spec;
      builder.currentAvailable.value = ["layout", "page"].includes(target) && variant !== "index";
      builder.componentId.value = null;
      // The modal template is its one panel, like a component's root.
      builder.rootKind.value = target === "region" && name === MODAL ? MODAL : null;
    }
    if (target === "page") {
      pageField.value = data.field;
      // The server picks the first area when none was asked for.
      requested = shown.value = { ...next, field: data.field };
      pageCustomized.value = !!data.customized;
      pageTitle.value = data.entry?.title ?? "";
      pageEntryId.value = data.entry?.id || pageEntry;
    }
    if (target === "layout") {
      // The server picks the entry's (or the default) template when none was asked for.
      requested = shown.value = { ...shown.value, template: data.template };
    }
    shownTemplate.value = data.shown_template ?? null;
    layoutCustomized.value = !!data.customized;
    defaultNodes.value = data.default_nodes ?? (target === "page" ? data.nodes : null);
    upgradeRequired.value = !!data.upgrade_required;
    samples.value = data.samples ?? [];
    // The server names the requested entry by its stable ID, whatever the link used.
    const wanted = data.sample || (typeof route.query.entry === "string" ? route.query.entry : "");
    sampleId.value = target === "page" ? data.entry.id
      : samples.value.some((sample) => sample.id === wanted) ? wanted
      : samples.value.find((sample) => sample.status === "published")?.id ?? samples.value[0]?.id ?? "";
    if (target !== "page" && sampleId.value && route.query.entry !== sampleId.value && isBuilderPath(route.path)) {
      router.replace({ query: { ...route.query, entry: sampleId.value } });
    }
    indexUrl.value = data.index_url ?? null;
    homeUrl.value = data.home_url ?? null;
    liveConflict.value = !!data.live_conflict;
    locales.value = data.locales ?? [];
    locale.value = locales.value.includes(route.query.locale) ? route.query.locale
      : locales.value.includes(editorLanguages.activeLocale.value) ? editorLanguages.activeLocale.value : data.default_locale ?? "";
    builder.load(data.nodes);
    layoutBaseline.value = clone(builder.nodes.value);
    layoutConflict.value = false;
    await restoreRecovery(current);
  } catch (err) {
    if (current !== loadSequence) return;
    // A list page that is turned off does not exist: open the entry page instead.
    if (target === "layout" && variant === "index" && err.status === 404) {
      router.replace({ path: `/page-builder/builder/layout/${name}/detail`, query: route.query });
      return;
    }
    // The entry's template shows none of its areas: its template opens, which says why.
    if (target === "page" && err.code === "page_not_placed") {
      const back = typeof route.query.back === "string" ? { back: route.query.back } : {};
      router.replace({ path: `/page-builder/builder/layout/${name}/detail`, query: { entry: pageEntry, ...back } });
      return;
    }
    loadError.value = err.message;
    // The error stands for the requested page: nothing of the previous one may be saved there.
    shown.value = next;
    leftView();
    builder.load([]);
  } finally {
    if (current === loadSequence) {
      loading.value = false;
      switching.value = false;
    }
  }
}

/** Save pending entry values, layout and site design together. */
async function save() {
  const saveLayout = layoutSavable.value;
  const saveTheme = themeDirty.value;
  const saveTemplates = templatesDirty.value;
  const saveContent = entryFields.isDirty.value;
  if (saving.value || switching.value || (!saveLayout && !saveTheme && !saveTemplates && !saveContent)) return;
  saving.value = true;

  recoveryActive = true;
  checkpointRecovery();
  let saveStage = "content";

  try {
    if (saveContent) {
      const entries = await entryFields.save();
      for (const entry of entries) {
        const sample = samples.value.find((item) => item.id === (entry.uid || entry.id));
        if (sample) sample.title = entry.title;
        // The page content is saved on top of the version the fields just saved.
        if (target.value === "page" && (entry.uid || entry.id) === sampleId.value) {
          layoutRevision.value = entry.revision;
          pageTitle.value = entry.title;
        }
      }
    }
    if (saveLayout) {
      saveStage = "layout";
      const nodes = builder.nodes.value;
      const pending = loadedRevisions.value.layout;
      const restoreDefault = pending?.customized === false && JSON.stringify(nodes) === pending.nodes;
      let res;
      if (target.value === "page") {
        res = restoreDefault
          ? await api.site.resetPage(name.value, pageEntry.value, pageField.value, layoutRevision.value)
          : await api.site.savePage(name.value, pageEntry.value, pageField.value, nodes, layoutRevision.value);
      } else if (restoreDefault) {
        res = target.value === "region" ? await api.site.resetRegion(name.value) : await api.site.resetLayout(name.value, variant.value, editedTemplate.value);
      } else if (target.value === "region") {
        res = await api.site.saveRegion(name.value, nodes, layoutRevision.value);
      } else if (target.value === "component") {
        res = await api.site.saveComponent(name.value, nodes, layoutRevision.value);
      } else {
        res = await api.site.saveLayout(name.value, variant.value, nodes, layoutRevision.value, editedTemplate.value);
        if (res.data.type) builder.type.value = res.data.type;
      }
      builder.markSaved(res.data.nodes);
      layoutBaseline.value = clone(builder.nodes.value);
      layoutConflict.value = false;
      layoutRevision.value = res.data.revision;
      upgradeRequired.value = false;
      loadedRevisions.value.layout = null;
      if (target.value === "component") component.value = res.data.component;
      else if (target.value === "page") savedPage(res.data);
      else {
        if (target.value === "region" || editedTemplate.value === DEFAULT_TEMPLATE) site.markCustomized(target.value, name.value, variant.value, res.data.customized);
        layoutCustomized.value = res.data.customized;
      }
    }
    if (saveTheme) {
      saveStage = "theme";
      await site.save({ parts: ["theme"] });
      loadedRevisions.value.theme = null;
    }
    if (saveTemplates) {
      saveStage = "templates";
      await site.save({ parts: ["templates"] });
    }
    if (!builder.isDirty.value) loadedRevisions.value.layout = null;
    if (!themeDirty.value) loadedRevisions.value.theme = null;
    historyVersion.value++;
    const message = saveContent ? "site.builder.changesSaved" : !saveLayout ? "site.builder.designSaved"
      : target.value === "component" ? "site.component.saved" : target.value === "page" ? "site.page.saved" : "site.builder.saved";
    toast.success(t(message));
  } catch (err) {
    if (saveStage === "layout" && err.status === 409) layoutConflict.value = true;
    if (err.entryId) {
      sampleId.value = err.entryId;
      locale.value = err.locale;
      panelLayout.open("fields");
    }
    toast.error(err.message);
  } finally {
    checkpointRecovery();
    if (saveContent) canvasRef.value?.refresh();
    saving.value = false;
  }
}

function fetchRevisions() {
  if (target.value === "page") return api.site.pageRevisions(name.value, pageEntry.value, pageField.value);
  if (target.value === "component") return api.site.componentRevisions(name.value);
  return target.value === "region" ? api.site.regionRevisions(name.value) : api.site.layoutRevisions(name.value, variant.value, editedTemplate.value);
}

function fetchRevision(id) {
  if (target.value === "page") return api.site.pageRevision(name.value, pageEntry.value, pageField.value, id);
  if (target.value === "component") return api.site.componentRevision(name.value, id);
  return target.value === "region" ? api.site.regionRevision(name.value, id) : api.site.layoutRevision(name.value, variant.value, id, editedTemplate.value);
}

function onRevisionLoaded(data, revision) {
  const before = loadedRevisions.value.layout?.before ?? clone(builder.nodes.value);
  loadedRevisions.value.layout = { before, revision, customized: data.customized, nodes: JSON.stringify(data.nodes) };
  builder.loadRevision(data.nodes);
}

/** After the page content was saved or reset: the entry's new version, also for the fields panel. */
function savedPage(data) {
  layoutRevision.value = data.revision;
  pageCustomized.value = data.customized;
  entryFields.setRevision(data.revision);
}

/**
 * Show the default design again as an ordinary edit: it can be undone or
 * cancelled, and saving it removes the custom version (see save()).
 */
function resetToDefault() {
  if (!defaultNodes.value) return;
  const nodes = clone(defaultNodes.value);
  const before = loadedRevisions.value.layout?.before ?? clone(builder.nodes.value);
  loadedRevisions.value.layout = { before, revision: null, customized: false, nodes: JSON.stringify(nodes) };
  builder.loadRevision(nodes);
}

function onSaveMenu(item) {
  if (item.key === "exit") return router.push(exitTo.value);
  if (item.key === "preview") return onSaveMenuSelect(item);
  if (item.key === "reset-page" || item.key === "reset-layout") resetToDefault();
}

/** Turn the node waiting in the name dialog into a component instance. */
async function convert(componentName) {
  const pending = builder.componentDialog.value;
  if (!pending || converting.value) return;
  converting.value = true;
  try {
    await builder.convertToComponent(pending.id, componentName);
    builder.componentDialog.value = null;
    toast.success(t("site.component.created"));
  } catch (err) {
    toast.error(err.message);
  } finally {
    converting.value = false;
  }
}

const creatingField = ref(false);
/** The element's kind as a starting name, e.g. "Heading". */
const fieldDialogInitial = computed(() => {
  const node = builder.fieldDialog.value ? findNode(builder.nodes.value, builder.fieldDialog.value.id) : null;
  return node ? builder.kindLabel(node.kind) : "";
});

/** Turn the element waiting in the field dialog into a field of the content type. */
async function convertField(label) {
  const pending = builder.fieldDialog.value;
  if (!pending || creatingField.value) return;
  creatingField.value = true;
  try {
    const key = await builder.convertToField(pending.id, label);
    builder.fieldDialog.value = null;
    if (key) toast.success(t("site.field.created", { key }));
  } catch (err) {
    toast.error(err.message);
  } finally {
    creatingField.value = false;
  }
}

/** Entry selection changes the content context without leaving a shared template. */
function selectEntry(entry) {
  if (saving.value || loading.value) return;
  if (target.value !== "page") {
    if (entry === sampleId.value) return;
    if (showsTemplates.value && target.value === "layout") followEntry = entry;
    // A component previews entries of every type; the entry's own type comes with it.
    const sampleType = samples.value.find((sample) => sample.id === entry)?.type;
    if (target.value === "component" && sampleType) builder.type.value = builder.types.value.find((type) => type.name === sampleType) ?? builder.type.value;
    sampleId.value = entry;
    // The link names the entry shown, so later builder links and reloads keep it.
    router.replace({ query: { ...route.query, entry } });
    return;
  }
  if (entry === pageEntryId.value) return;
  goToView({
    key: `entry:${entry}`,
    to: {
      path: `/page-builder/builder/page/${name.value}/${entry}`,
      query: {
        ...(pageField.value ? { field: pageField.value } : {}),
        ...(typeof route.query.back === "string" ? { back: route.query.back } : {}),
      },
    },
  });
}

/** Edit another page content area of the entry, in place. */
function selectPageArea(field) {
  if (target.value !== "page" || saving.value || loading.value || field === requested.field) return;
  goToView({ key: `field:${field}`, field, to: { path: route.path, query: { ...route.query, field } } });
}

function goToView(view) {
  if (view.key === currentView.value) return;
  if (!switching.value && (builder.isDirty.value || entryFields.isDirty.value)) {
    // The entry starts from the saved template's area; leaving its edits behind could remove that area.
    pendingView.value = view.key === "page" && target.value === "layout" ? { ...view, save: true } : view;
    return;
  }
  switchView(view);
}

async function confirmPendingView() {
  const view = pendingView.value;
  if (!view?.save) return switchView(view);
  await save();
  if (builder.isDirty.value || entryFields.isDirty.value) {
    pendingView.value = null;
    return;
  }
  switchView(view);
}

function switchView(view) {
  pendingView.value = null;
  // Another area of the same entry replaces the URL; the route watcher loads it.
  if (view.field) {
    router.replace(view.to);
    return;
  }
  bypass();
  router.push(view.to);
}

/**
 * Reset what belongs to the page that was left: the builder stays mounted
 * between builder pages, so nothing of it is recreated. Pending design edits,
 * open panels, the device and the language carry over.
 */
function leftView() {
  clearTimeout(recoveryTimer);
  recoveryActive = false;
  recoveredDraft.value = recoveryFailed.value = layoutConflict.value = overwriteOpen.value = false;
  builder.select(null);
  builder.closeContextMenu();
  builder.componentDialog.value = null;
  builder.fieldDialog.value = null;
  selectedTemplatePart.value = null;
  revealedField.value = null;
  pendingView.value = null;
  component.value = null;
  usages.value = [];
  entryFields.discard();
  // The history list belongs to the page that was left.
  historyVersion.value++;
}

// Moving between builder pages (header to footer, layout to page content, back and forward) loads in place.
watch(() => route.fullPath, () => {
  if (!isBuilderPath(route.path)) return;
  const next = contextFor(route);
  const entry = typeof route.query.entry === "string" ? route.query.entry : "";
  if (!sameContext(next, requested)) {
    load(next);
  } else if (entry && entry !== sampleId.value) {
    if (samples.value.some((sample) => sample.id === entry)) sampleId.value = entry;
    // An entry found by search may not be among the loaded samples: the server adds it on reload.
    else if (showsSamples.value) load(next);
  }
  if (["design", "css"].includes(route.query.settings)) panelLayout.open(route.query.settings);
});

function onKeydown(event) {
  if (event.target?.closest?.('[role="dialog"], [role="alertdialog"]')) return;
  const element = event.target;
  const editing = ["INPUT", "TEXTAREA", "SELECT"].includes(element?.tagName) || element?.isContentEditable;
  const modifier = event.ctrlKey || event.metaKey;
  const key = event.key.toLowerCase();

  if (modifier && key === "s") {
    event.preventDefault();
    save();
    return;
  }

  // Edits to a view on its way out would be lost.
  if (switching.value) return;

  if (editing) return;

  if (event.key === "?" && !modifier) {
    event.preventDefault();
    guideOpen.value = true;
    return;
  }

  // Shift+F10 and the context-menu key open the menu for the selection.
  if ((event.key === "ContextMenu" || (event.shiftKey && event.key === "F10")) && builder.selectedId.value) {
    event.preventDefault();
    const point = canvasRef.value?.positionFor(builder.selectedId.value) ?? { x: window.innerWidth / 2, y: 120 };
    builder.openContextMenu(builder.selectedId.value, point.x, point.y);
    return;
  }

  const hasTextSelection = !!window.getSelection()?.toString();

  if (modifier && key === "c" && builder.selectedId.value && !hasTextSelection) {
    builder.copy();
  } else if (modifier && key === "v" && builder.clipboard.value) {
    event.preventDefault();
    builder.paste();
  } else if (modifier && key === "z") {
    event.preventDefault();
    if (event.shiftKey) builder.redo();
    else builder.undo();
  } else if (modifier && key === "y") {
    event.preventDefault();
    builder.redo();
  } else if (modifier && key === "d" && builder.selectedId.value) {
    event.preventDefault();
    builder.duplicate();
  } else if ((event.key === "Delete" || event.key === "Backspace") && builder.selectedId.value) {
    event.preventDefault();
    builder.remove();
  } else if (event.key === "Escape") {
    if (builder.contextMenu.value) builder.closeContextMenu();
    else {
      builder.select(null);
      selectedTemplatePart.value = null;
    }
  } else if (event.altKey && (event.key === "ArrowUp" || event.key === "ArrowDown") && builder.selectedId.value) {
    event.preventDefault();
    builder.shift(builder.selectedId.value, event.key === "ArrowUp" ? -1 : 1);
  }
}

onMounted(() => {
  load();
  // Swatches and token hints show the saved theme; refresh unless edits are pending.
  site.load({ force: !site.isDirty }).catch(() => {});
  window.addEventListener("keydown", onKeydown);
  window.addEventListener("beforeunload", checkpointRecovery);
});

onBeforeUnmount(() => {
  clearTimeout(recoveryTimer);
  clearTimeout(switchingTimer);
  window.removeEventListener("keydown", onKeydown);
  window.removeEventListener("beforeunload", checkpointRecovery);
  recovery.remove(shown.value);
  // Design edits the user chose to leave behind must not resurface elsewhere.
  if (!isBuilderPath(router.currentRoute.value.path)) site.discardTheme();
});
</script>

<style scoped>
.builder-topbar {
  --builder-toolbar-text: 255 255 255;
  --builder-toolbar-hover: rgb(255 255 255 / 0.15);
  --builder-toolbar-selected: #fff;
  --builder-toolbar-selected-text: rgb(var(--color-theme-800));
  background: rgb(var(--color-theme-700));
  border-color: rgb(0 0 0 / 0.12);
  color: rgb(var(--builder-toolbar-text));
}
.builder-topbar :deep(:is(button, a, select):focus-visible) {
  outline: 2px solid white;
  outline-offset: 2px;
}
.builder-topbar :deep([role="menu"] :is(button, a):focus-visible) {
  outline-color: rgb(var(--color-theme-500));
  outline-offset: -2px;
}
.topbar-muted {
  color: rgb(var(--builder-toolbar-text) / 0.8);
}
.builder-topbar :deep(.topbar-devices) {
  background: rgb(0 0 0 / 0.12);
}
.builder-topbar :deep(.topbar-devices button) {
  background: transparent;
  color: rgb(var(--builder-toolbar-text) / 0.8);
}
.builder-topbar :deep(.topbar-devices button:hover) {
  background: var(--builder-toolbar-hover);
  color: rgb(var(--builder-toolbar-text));
}
.builder-topbar :deep(.topbar-devices button[aria-pressed="true"]) {
  background: var(--builder-toolbar-selected);
  color: var(--builder-toolbar-selected-text);
  box-shadow: 0 1px 2px rgb(0 0 0 / 0.1);
}
/* The entry's own content keeps the teal of its area on the canvas. */
.builder-breadcrumbs :deep(.scope-page.is-active) {
  color: #17657a;
}
.breadcrumb-separator {
  color: rgb(var(--builder-toolbar-text) / 0.45);
}
@media (min-width: 640px) {
  .builder-breadcrumbs :deep(.scope-page .builder-switcher-menu) {
    left: auto;
    right: 0;
  }
}
@media (max-width: 1023px) {
  .builder-breadcrumbs {
    flex-basis: calc(100% - 5rem);
  }
  .builder-toolbar {
    justify-content: flex-end;
    width: 100%;
  }
}
@media (max-width: 639px) {
  .breadcrumb-root {
    max-width: 25vw;
  }
  .builder-breadcrumbs :deep(:is(.template-primary, .entry-primary)) {
    gap: 0.25rem;
    padding-inline: 0.375rem;
  }
  .builder-toolbar {
    flex-wrap: wrap;
    gap: 0.25rem;
  }
  .builder-toolbar .topbar-divider {
    display: none;
  }
  .builder-toolbar :deep(.topbar-devices button) {
    padding: 0.25rem;
  }
  .builder-toolbar :deep(.btn) {
    padding-inline: 0.5rem;
    font-size: 0.75rem;
  }
  /* Anchor menus to the header so every breadcrumb's menu fits the viewport. */
  .builder-breadcrumbs :deep(:is(.page-switcher, .template-switcher, .entry-switcher)) {
    position: static;
  }
  .builder-breadcrumbs :deep(.builder-switcher-menu) {
    left: 0.5rem;
    width: calc(100% - 1rem);
    max-width: 20rem;
  }
}
.builder-topbar :deep(.topbar-languages [data-tab-value]) {
  background: transparent;
  color: rgb(var(--builder-toolbar-text) / 0.8);
  --tw-ring-color: rgb(var(--builder-toolbar-text) / 0.3);
}
.builder-topbar :deep(.topbar-languages [data-tab-value]:has([aria-selected="true"])) {
  background: var(--builder-toolbar-selected);
  color: var(--builder-toolbar-selected-text);
  --tw-ring-color: transparent;
}
.topbar-save-badge {
  --tw-ring-color: rgb(var(--color-theme-700));
}
.topbar-action {
  position: relative;
  display: inline-flex;
  height: 2rem;
  width: 2rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 0.5rem;
  color: rgb(var(--builder-toolbar-text) / 0.85);
  transition: background-color 150ms, color 150ms;
}
.topbar-action:hover:not(:disabled) {
  background: var(--builder-toolbar-hover);
  color: rgb(var(--builder-toolbar-text));
}
.topbar-action:disabled {
  opacity: 0.35;
}
.topbar-divider {
  height: 1.25rem;
  width: 1px;
  flex-shrink: 0;
  background: rgb(var(--builder-toolbar-text) / 0.25);
}
.topbar-group {
  height: 2rem;
  min-width: 0;
  align-items: center;
  border-radius: 0.5rem;
  background: rgb(0 0 0 / 0.12);
}
.topbar-group > * + * {
  border-left: 1px solid rgb(var(--builder-toolbar-text) / 0.25);
}
.builder-progress::before {
  content: "";
  position: absolute;
  inset-block: 0;
  width: 30%;
  background: rgb(var(--builder-toolbar-text) / 0.85);
  animation: builder-progress 1s ease-in-out infinite;
}

@keyframes builder-progress {
  from { left: -30%; }
  to { left: 100%; }
}

@media (prefers-reduced-motion: reduce) {
  .builder-progress::before {
    width: 100%;
    animation: none;
    opacity: 0.5;
  }
}

.builder-stage {
  background-color: rgb(var(--color-slate-100));
  background-image: radial-gradient(rgb(var(--color-slate-300) / 0.7) 1px, transparent 1px);
  background-size: 16px 16px;
}
.rail-action {
  touch-action: none;
  position: relative;
  display: flex;
  height: 2.5rem;
  width: 2.5rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 0.625rem;
  color: rgb(var(--color-slate-500));
  transition: background-color 150ms, color 150ms, transform 100ms;
}
/* Brief press feedback in place of a lingering focus ring. */
.rail-action:active {
  transform: scale(0.92);
  background: rgb(var(--color-slate-200));
}
.rail-action:hover {
  background: rgb(var(--color-slate-100));
  color: rgb(var(--color-slate-900));
}
.rail-action.is-active {
  background: rgb(var(--color-theme-50, 239 246 255));
  color: rgb(var(--color-theme-text, 29 78 216));
}
/* Marks open panels on the rail's edge. */
.rail-action.is-active::before {
  content: "";
  position: absolute;
  left: -0.375rem;
  top: 0.625rem;
  bottom: 0.625rem;
  width: 3px;
  border-radius: 0 3px 3px 0;
  background: rgb(var(--color-theme-600));
}
</style>
