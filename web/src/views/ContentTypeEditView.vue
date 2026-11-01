<template>
  <div>
    <div class="editor-sticky-header flex items-center gap-3">
      <router-link
        to="/content-types"
        class="icon-btn"
        :aria-label="t('app.back')"
      >
        <Icon icon="mdi:arrow-left" class="w-[18px] h-[18px]" />
      </router-link>
      <div class="min-w-0">
        <div class="truncate text-xs font-medium text-slate-500">{{ t("contentTypes.title") }}</div>
      <h1 class="text-xl font-semibold tracking-tight text-slate-900 truncate">
        {{
          isNew
            ? t("contentTypeEdit.newTitle")
            : t("contentTypeEdit.editTitle", { name: form.label || form.name })
        }}
      </h1>
      </div>
      <div class="ml-auto flex items-center gap-3">
        <!-- Field labels, options and website texts all follow this one switcher -->
        <LanguageTabs compact />
        <button
          type="submit"
          :disabled="saving"
          class="btn-primary relative"
          form="type-form"
        >
          {{ saving ? t("common.saving") : t("contentTypeEdit.saveType") }}
          <span
            v-if="isDirty && !saving"
            class="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-amber-400 ring-2 ring-white"
          ></span>
        </button>
      </div>
    </div>

    <div
      v-if="loadError"
      class="p-3 bg-red-50 text-red-700 rounded-lg text-sm mb-4"
    >
      {{ loadError }}
    </div>

    <router-link v-if="backToBuilder" :to="backToBuilder" class="mb-4 inline-flex items-center gap-1 text-sm text-theme-600 hover:underline">
      <Icon icon="mdi:arrow-left" class="h-4 w-4" />
      {{ t("site.forms.backToBuilder") }}
    </router-link>

    <TabNavigation
      v-model="activeTab"
      :items="tabs"
      :aria-label="t('contentTypes.title')"
      class="mb-6"
    />

    <form id="type-form" @submit.prevent="handleSave" @invalid.capture="revealInvalidField" class="space-y-6">
      <!-- Basics -->
      <div v-show="activeTab === 'base'" data-settings-tab="base" role="tabpanel" :aria-label="t('contentTypeEdit.tab.base')" class="space-y-6">
        <div class="card p-6 space-y-4">
          <h2 class="card-title">
            {{ t("contentTypeEdit.settings") }}
          </h2>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label for="content-type-label" class="form-label">{{
                t("contentTypeEdit.displayLabel")
              }}</label>
              <input
                id="content-type-label"
                v-model="form.label"
                type="text"
                :placeholder="t('contentTypeEdit.displayLabelPlaceholder')"
                :autofocus="isNew"
                class="form-input w-full rounded-lg border-slate-300 text-sm"
              />
            </div>

            <div>
              <label for="content-type-name" class="form-label">{{
                t("contentTypeEdit.apiName")
              }}</label>
              <input
                id="content-type-name"
                v-model="form.name"
                type="text"
                required
                :disabled="!isNew"
                placeholder="blog-posts"
                aria-describedby="content-type-name-help"
                class="form-input w-full rounded-lg border-slate-300 font-mono text-sm disabled:bg-slate-50 disabled:text-slate-500"
                @input="nameTouched = form.name !== ''"
                @blur="fixSlugOnBlur"
              />
              <p id="content-type-name-help" class="mt-1 text-xs text-slate-500">
                {{ t(isNew ? "contentTypeEdit.apiNameHelp" : "contentTypeEdit.apiNameLocked") }}
              </p>
            </div>

            <div>
              <label class="form-label">{{
                t("contentTypeEdit.sidebarIcon")
              }}</label>
              <IconPickerGrid v-model="form.icon" :placeholder="defaultIcon" />
            </div>

            <div v-if="websiteStatus" id="frontend-page-setting" class="col-span-1 scroll-mt-24">
              <label for="content-type-frontend" class="form-label">{{ t("site.typeCard.title") }}</label>
              <label class="flex items-center gap-3 text-sm text-slate-700">
                <ToggleSwitch
                  id="content-type-frontend"
                  v-model="frontendPagesEnabled"
                  :disabled="!websiteStatus.can_manage || !websiteRoute"
                  :aria-label="t('site.pages.showInFrontend')"
                  aria-describedby="content-type-frontend-help"
                />
                {{ t("site.pages.showInFrontend") }}
              </label>
              <p id="content-type-frontend-help" class="mt-1 text-xs text-slate-500">
                {{ t("site.pages.frontendHint") }}
              </p>
            </div>
            <div class="col-span-1">
              <label for="content-type-api" class="form-label">{{ t("contentTypeEdit.apiAccess") }}</label>
              <label class="flex items-center gap-3 text-sm text-slate-700">
                <ToggleSwitch id="content-type-api" v-model="form.api_enabled" aria-describedby="content-type-api-help" />
                {{ t("contentTypeEdit.exposeApi") }}
              </label>
              <p id="content-type-api-help" class="mt-1 text-xs text-slate-500">{{ t("contentTypeEdit.apiHint") }}</p>
            </div>

            <div class="col-span-1">
              <label for="content-type-submissions" class="form-label">{{ t("site.forms.title") }}</label>
              <label class="flex items-center gap-3 text-sm text-slate-700">
                <ToggleSwitch id="content-type-submissions" v-model="form.allow_submissions"  aria-describedby="content-type-submissions-help" />
                {{ t("contentTypeEdit.allowSubmissions") }}
              </label>
              <p v-if="submissionError" class="mt-1 text-xs text-red-600" role="alert">{{ submissionError }}</p>
              <p id="content-type-submissions-help" class="mt-1 text-xs text-slate-500">{{ t("contentTypeEdit.submissionsHint") }}</p>
            </div>

            <div class="col-span-1">
              <label for="content-type-hierarchical" class="form-label">{{ t("contentTypeEdit.hierarchy") }}</label>
              <label class="flex items-center gap-3 text-sm text-slate-700">
                <ToggleSwitch id="content-type-hierarchical" v-model="form.hierarchical" aria-describedby="content-type-hierarchical-help" />
                {{ t("contentTypeEdit.hierarchical") }}
              </label>
              <p id="content-type-hierarchical-help" class="mt-1 text-xs text-slate-500">{{ t("contentTypeEdit.hierarchicalHint") }}</p>
            </div>
          </div>
        </div>

        <TypeSubmissionSettings
          v-if="form.allow_submissions"
          v-model="form.submission_settings"
          :name="isNew ? '' : form.name"
          :fields="customFields"
        />
      </div>

      <div v-show="activeTab === 'builder'" data-settings-tab="builder" role="tabpanel" :aria-label="t('contentTypeEdit.tab.builder')" class="space-y-6">
        <TypeWebsiteCard
          section="builder"
          v-model="websiteRoute"
          :status="websiteStatus"
          :name="isNew ? toSlug(form.name) : form.name"
          :label="form.label"
          :visibility="form.visibility"
          :fields="customFields"
          :errors="websiteErrors"
          @touched="routeTouched = true"
        />

        <!-- Content areas are managed in layouts, separately from custom fields. -->
        <section v-if="areaFields.length" aria-labelledby="content-areas-title" class="card p-6">
          <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <h2 id="content-areas-title" class="card-title flex items-center gap-2">
                <Icon icon="lucide:panels-top-left" class="h-4 w-4 text-slate-500" />
                {{ t("contentTypeEdit.areasTitle") }}
              </h2>
              <p class="mt-2 text-xs text-slate-500">{{ t("contentTypeEdit.areasHint") }}</p>
            </div>
            <RouterLink :to="`/page-builder/builder/layout/${form.name}/detail`" class="btn-secondary btn-sm shrink-0">
              {{ t("contentTypeEdit.editAreas") }}
            </RouterLink>
          </div>
          <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-slate-50 text-xs text-slate-600">
            <li v-for="area in areaRows" :key="area.key" class="flex items-center justify-between gap-2 px-3 py-3">
              <div class="min-w-0">
                <span class="font-medium text-slate-700">{{ areaLabel(area) }}</span>
                <span class="ml-1.5 font-mono text-[11px] text-slate-400">{{ area.key }}</span>
                <span v-if="area.templates" class="ml-2 text-slate-500">
                  {{ area.templates.length ? t("contentTypeEdit.areaPlaced", { count: area.templates.length }) : t("contentTypeEdit.areaUnplaced") }}
                  · {{ t("site.page.areaEntries", { count: area.entries }) }}
                </span>
              </div>
              <button
                v-if="canDeleteAreas"
                type="button"
                class="shrink-0 rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
                :title="t('contentTypeEdit.deleteArea')"
                :aria-label="t('contentTypeEdit.deleteArea')"
                @click="areaToDelete = area"
              >
                <Icon icon="lucide:trash-2" class="h-3.5 w-3.5" />
              </button>
            </li>
          </ul>
        </section>
      </div>

      <div v-show="activeTab === 'seo'" data-settings-tab="seo" role="tabpanel" :aria-label="t('contentTypeEdit.tab.seo')">
        <TypeWebsiteCard
          v-model="websiteRoute"
          section="seo"
          :status="websiteStatus"
          :name="isNew ? toSlug(form.name) : form.name"
          :label="form.label"
          :visibility="form.visibility"
          :fields="customFields"
          :errors="websiteErrors"
        />
      </div>

      <div v-show="activeTab === 'fields'" data-settings-tab="fields" role="tabpanel" :aria-label="t('contentTypeEdit.tab.fields')">
        <!-- Custom fields supply values to shared page layouts. -->
        <div class="card p-6">
          <div class="mb-4 flex items-center justify-between gap-3">
            <h2 class="card-title">
              {{ t("contentTypeEdit.fields") }}
              <span class="text-slate-400 font-normal ml-1">{{
                t("contentTypeEdit.builtinFieldsHint")
              }}</span>
            </h2>

            <div v-if="customFields.length > 1" class="flex items-center gap-2 text-xs">
              <button
                type="button"
                class="font-medium text-slate-500 transition-colors hover:text-theme-600"
                @click="fieldBuilder?.expandAllFields()"
              >
                {{ t("contentTypeEdit.expandAll") }}
              </button>
              <span class="text-slate-300">/</span>
              <button
                type="button"
                class="font-medium text-slate-500 transition-colors hover:text-theme-600"
                @click="fieldBuilder?.collapseAllFields()"
              >
                {{ t("contentTypeEdit.collapseAll") }}
              </button>
            </div>
          </div>

          <p class="mb-4 text-xs text-slate-500">{{ t("contentTypeEdit.fieldsHint") }}</p>

          <FieldBuilder
            ref="fieldBuilder"
            v-model="customFields"
            :field-types="fieldTypes"
            :content-types="contentTypes"
            :field-errors="customFieldErrors"
          />
        </div>

      </div>

      <div
        v-if="saveError"
        class="p-3 bg-red-50 text-red-700 rounded-lg text-sm"
      >
        {{ saveError }}
      </div>

      <div v-if="!isNew" class="flex justify-end">
        <button
          type="button"
          @click="showDeleteModal = true"
          class="btn-danger"
        >
          {{ t("contentTypeEdit.deleteType") }}
        </button>
      </div>
    </form>

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
      v-model="showDeleteModal"
      :title="t('contentTypeEdit.deleteTitle')"
      :message="t('contentTypeEdit.deleteMessage', { name: form.name })"
      :confirm-label="t('contentTypeEdit.deleteConfirm')"
      :loading="deleting"
      @confirm="handleDelete"
    />
    <ConfirmModal
      :model-value="!!areaToDelete"
      :title="t('contentTypeEdit.deleteAreaTitle', { name: areaToDelete ? areaLabel(areaToDelete) : '' })"
      :message="areaToDelete?.templates?.length ? t('contentTypeEdit.deleteAreaPlacedMessage', { count: areaToDelete.templates.length }) : t('contentTypeEdit.deleteAreaMessage')"
      :confirm-label="t('contentTypeEdit.deleteArea')"
      :loading="deletingArea"
      @update:model-value="(open) => { if (!open) areaToDelete = null; }"
      @confirm="deleteArea"
    />
  </div>
</template>

<script setup>
import {
  ref,
  computed,
  onMounted,
  onBeforeUnmount,
  watch,
  nextTick,
} from "vue";
import { useRoute, useRouter } from "vue-router";
import { Icon } from "@iconify/vue";
import FieldBuilder from "../components/FieldBuilder.vue";
import IconPickerGrid from "../components/IconPickerGrid.vue";
import ToggleSwitch from "../components/ToggleSwitch.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import { useUnsavedChangesGuard } from "../composables/useUnsavedChangesGuard.js";
import { api } from "../api/index.js";
import { supportsConfiguredDefault } from "../composables/fieldDefaults.js";
import { useToastStore } from "../stores/toast.js";
import { useContentTypesStore } from "../stores/contentTypes.js";
import { useAuthStore } from "../stores/auth.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import { useSiteStore } from "../stores/site.js";
import TypeSubmissionSettings from "../components/site/TypeSubmissionSettings.vue";
import TypeWebsiteCard from "../components/site/TypeWebsiteCard.vue";
import { contentTypeEndpoint } from "../composables/apiEndpoint.js";
import { useI18n } from "../i18n/index.js";
import TabNavigation from "../components/TabNavigation.vue";
import LanguageTabs from "../components/LanguageTabs.vue";
import { provideLanguageSwitcher } from "../composables/useLanguages.js";
import { toSlug } from "../composables/fieldBuilderUtils.js";
import { resolveLocalized } from "../composables/localizedValues.js";

const route = useRoute();
const router = useRouter();
const toast = useToastStore();
const typesStore = useContentTypesStore();
const auth = useAuthStore();
const apiEndpointStore = useApiEndpointStore();
const { t } = useI18n();
provideLanguageSwitcher();
const apiEndpointOwner = "content-type-edit";

const isNew = computed(() => !route.params.name);
const backToBuilder = computed(() => {
  const back = typeof route.query.back === "string" ? route.query.back : "";
  return back.startsWith("/page-builder/builder/") ? back : "";
});
const activeTab = ref("base");
const tabs = computed(() => [
  { value: "base", label: t("contentTypeEdit.tab.base"), icon: "mdi:cog-outline" },
  { value: "builder", label: t("contentTypeEdit.tab.builder"), icon: "lucide:panels-top-left" },
  { value: "seo", label: t("contentTypeEdit.tab.seo"), icon: "mdi:magnify" },
  { value: "fields", label: t("contentTypeEdit.tab.fields"), icon: "mdi:form-select" },
]);

// Keep existing links to website and submission settings working with tabs.
watch(() => route.hash, (hash) => {
  if (hash === "#website") activeTab.value = "builder";
  else if (hash === "#submissions") activeTab.value = "base";
}, { immediate: true });

async function revealInvalidField(event) {
  const panel = event.target.closest('[role="tabpanel"]');
  if (!panel || panel.style.display !== "none") return;
  event.preventDefault();
  activeTab.value = panel.dataset.settingsTab;
  await nextTick();
  event.target.reportValidity();
}

const defaultIcon = "mdi:file-document-outline";

const form = ref({
  name: "",
  label: "",
  icon: defaultIcon,
  visibility: "public",
  api_enabled: false,
  hierarchical: false,
  allow_submissions: false,
  submission_settings: { status: "draft", captcha: "none", submit_label: "", success_message: "", error_message: "" },
  fields: {},
});
// While creating a type, derive the API name from the label until it is edited by hand.
const nameTouched = ref(false);
watch(
  () => form.value.label,
  (label) => {
    if (isNew.value && !nameTouched.value) form.value.name = toSlug(label ?? "");
  },
);
const isDirty = ref(false);
const {
  leavePromptOpen,
  confirmLeave,
  bypass: bypassLeaveGuard,
} = useUnsavedChangesGuard(isDirty);
const cleanState = ref("");
let _formLoaded = false;

// We store only the "custom" fields (not title/slug) in the builder.
const customFields = ref([]);
const fieldTypes = ref([]);
/** Page fields: the storage of the detail templates' content areas, kept out of the field list. */
const areaFields = ref([]);
/** Where the templates place each area and how many entries designed it, when the site is readable. */
const areaUsage = ref({});
const areaRows = computed(() => areaFields.value.map((field) => ({ ...field, ...areaUsage.value[field.key] })));
const areaToDelete = ref(null);
const deletingArea = ref(false);
const canDeleteAreas = computed(() => auth.can("site.manage") && auth.can("schema.update"));

function areaLabel(area) {
  return resolveLocalized(area.label, form.value.default_locale, form.value.default_locale) || area.key;
}

async function loadAreaUsage() {
  if (!areaFields.value.length || !auth.can("site.manage")) return;
  try {
    const areas = (await api.site.areas(form.value.name)).data ?? [];
    areaUsage.value = Object.fromEntries(areas.map(({ key, templates, entries }) => [key, { templates, entries }]));
  } catch {
    areaUsage.value = {};
  }
}

/** Templates placing the area keep the node and show it as missing. */
async function deleteArea() {
  const key = areaToDelete.value.key;
  deletingArea.value = true;
  try {
    await api.site.deleteArea(form.value.name, key);
    areaFields.value = areaFields.value.filter((field) => field.key !== key);
    // The delete gave the type a new revision; carry it so unsaved edits here still save.
    const { revision } = (await api.contentTypes.get(form.value.name)).data ?? {};
    if (revision) form.value.revision = revision;
    toast.success(t("contentTypeEdit.areaDeleted"));
    areaToDelete.value = null;
  } catch (error) {
    toast.error(error.fields?.field ?? error.message);
  } finally {
    deletingArea.value = false;
  }
}
const contentTypes = ref([]);
const loadError = ref("");
const saveError = ref("");
const submissionError = ref("");
const customFieldErrors = ref({});
const saving = ref(false);
const deleting = ref(false);
const showDeleteModal = ref(false);
const fieldBuilder = ref(null);

watch(
  [form, customFields],
  () => {
    if (_formLoaded) updateDirtyState();
  },
  { deep: true },
);

watch(
  () => form.value.api_enabled ? route.params.name : "",
  (name) => {
    if (!name) {
      apiEndpointStore.clearEndpoint(apiEndpointOwner);
      return;
    }

    apiEndpointStore.setEndpoint(
      {
        label: "Content type",
        url: contentTypeEndpoint(name),
      },
      apiEndpointOwner,
    );
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  apiEndpointStore.clearEndpoint(apiEndpointOwner);
});

// Website settings of this type. Users who manage the website edit them here;
// they are saved together with the content type.
const site = useSiteStore();
const websiteStatus = ref(null);
const websiteRoute = ref(null);
const frontendPagesEnabled = computed({
  get: () => !!(
    websiteRoute.value?.enabled ?? websiteStatus.value?.types?.[form.value.name]?.pages_enabled
  ),
  set: (enabled) => {
    if (!websiteRoute.value || !websiteStatus.value?.can_manage) return;
    if (enabled) form.value.visibility = "public";
    websiteRoute.value = { ...websiteRoute.value, enabled };
  },
});
const websiteErrors = ref({});
const routeTouched = ref(false);
let routeClean = "null";

function defaultRoute(name) {
  return {
    enabled: isNew.value,
    pattern: `/${name}/{slug}`,
    index_enabled: false,
    index_path: `/${name}`,
    index_title: "",
    seo_description_field: "",
    seo_image_field: "",
    sitemap: true,
  };
}

function routeDirty() {
  return websiteRoute.value !== null && JSON.stringify(websiteRoute.value) !== routeClean;
}

async function loadWebsite() {
  try {
    websiteStatus.value = (await api.site.status()).data;
    if (websiteStatus.value.can_manage) {
      await site.load();
      const stored = isNew.value ? null : site.data?.settings?.routes?.[route.params.name];
      const name = isNew.value ? toSlug(form.value.name) || "new" : route.params.name;
      websiteRoute.value = JSON.parse(JSON.stringify(stored ?? defaultRoute(name)));
      routeClean = JSON.stringify(websiteRoute.value);
    }
  } catch {
    // The card is optional; the editor works without it.
  }

  if (route.hash === "#website") {
    await nextTick();
    (document.getElementById("website") ?? document.getElementById("frontend-page-setting"))?.scrollIntoView({ behavior: "smooth", block: "start" });
  }
}

/** Save this type's website settings; returns false when they were rejected. */
async function saveWebsiteRoute(name) {
  if (!websiteRoute.value || !websiteStatus.value?.can_manage) return true;
  if (isNew.value ? !websiteRoute.value.enabled : !routeDirty()) return true;

  websiteErrors.value = {};
  try {
    const saved = await site.saveRoute(name, websiteRoute.value);
    websiteRoute.value = JSON.parse(JSON.stringify(saved));
    routeClean = JSON.stringify(websiteRoute.value);
    websiteStatus.value = {
      ...websiteStatus.value,
      enabled: !!site.data?.settings?.enabled,
      types: { ...websiteStatus.value.types, [name]: { ...saved, pages_enabled: saved.enabled, enabled: saved.enabled } },
    };
    return true;
  } catch (err) {
    const prefix = `routes.${name}.`;
    websiteErrors.value = Object.fromEntries(
      Object.entries(err.fields ?? {})
        .filter(([key]) => key.startsWith(prefix))
        .map(([key, message]) => [key.slice(prefix.length), message]),
    );
    activeTab.value = Object.keys(websiteErrors.value).some((key) =>
      ["index_title", "seo_description_field", "seo_image_field", "sitemap"].includes(key)
    ) ? "seo" : "builder";
    // Other settings can object too, e.g. a homepage that shows this type's list page.
    toast.error(t("site.typeCard.saveFailed", { message: Object.values(websiteErrors.value)[0] ?? Object.values(err.fields ?? {})[0] ?? err.message }));
    return false;
  }
}

// New types suggest URLs from their name until the URLs are edited by hand.
watch(
  () => form.value.name,
  () => {
    if (!isNew.value || routeTouched.value || !websiteRoute.value) return;
    const next = defaultRoute(toSlug(form.value.name) || "new");
    websiteRoute.value = { ...websiteRoute.value, pattern: next.pattern, index_path: next.index_path };
  },
);

watch(websiteRoute, () => { if (_formLoaded) updateDirtyState(); }, { deep: true });
watch(() => form.value.api_enabled, (enabled) => { if (enabled) form.value.visibility = "public"; });

onMounted(async () => {
  try {
    const res = await api.contentTypes.list();
    fieldTypes.value = (res.meta?.field_types ?? res.field_types ?? []).filter(
      (type) => type !== "slug" && type !== "page",
    );
    contentTypes.value = res.data ?? [];
  } catch (err) {
    loadError.value = err.message;
  }

  if (!isNew.value) {
    try {
      const res = await api.contentTypes.get(route.params.name);
      const type = res.data;
      form.value = {
        ...type,
        // Private types remain protected when loading API settings.
        visibility: type.visibility ?? "public",
        api_enabled: type.visibility !== "private" && !!type.api_enabled,
        submission_settings: type.submission_settings,
      };

      // Strip built-in fields; only show the extras in the builder.
      const builtins = new Set(["title", "slug"]);
      const fields = Object.entries(type.fields ?? {}).filter(([k]) => !builtins.has(k));
      customFields.value = fields.filter(([, cfg]) => cfg.type !== "page").map(([key, cfg]) => ({ key, ...cfg }));
      areaFields.value = fields.filter(([, cfg]) => cfg.type === "page").map(([key, cfg]) => ({ key, ...cfg }));
    } catch (err) {
      loadError.value = err.message;
    }
  }

  await Promise.all([loadWebsite(), loadAreaUsage()]);
  await nextTick();
  if (route.hash === "#submissions") document.getElementById("submissions")?.scrollIntoView({ behavior: "smooth", block: "start" });
  markClean();
  _formLoaded = true;
});

async function handleSave() {
  if (saving.value) return;

  saveError.value = "";
  submissionError.value = "";
  customFieldErrors.value = {};

  if (hasNameConflict()) {
    activeTab.value = "base";
    failSave(t("contentTypeEdit.nameConflict"));
    return;
  }

  const validation = validateCustomFields(customFields.value);
  if (!validation.ok) {
    activeTab.value = "fields";
    customFieldErrors.value = validation.fieldErrors;
    failSave(validation.message);
    return;
  }

  saving.value = true;

  try {
    // Merge custom fields back into the payload (built-ins are added server-side).
    // _originalKey / _originalType are internal tracking fields – strip them before sending to the API.
    const fields = {};
    const migrations = [];
    // Areas go back unchanged and in place; the server keeps them either way.
    for (const { key, ...cfg } of areaFields.value) fields[key] = cfg;

    for (const f of customFields.value) {
      if (f.key) {
        const { key, _originalKey, _originalType, ...cfg } = f;
        fields[key] = cfg;

        // Record key renames so the server can migrate existing entry data.
        if (!isNew.value && _originalKey && _originalKey !== key) {
          migrations.push({ from_key: _originalKey, to_key: key });
        }
      }
    }

    const { locales, default_locale, ...schema } = form.value;
    const payload = {
      ...schema,
      fields,
      ...(!isNew.value && migrations.length ? { migrations } : {}),
    };

    if (isNew.value) {
      const res = await api.contentTypes.create(payload);
      const name = res.data?.name ?? toSlug(form.value.name);
      toast.success(t("contentTypeEdit.saved"));
      const websiteSaved = await saveWebsiteRoute(name);
      bypassLeaveGuard();
      // If the website settings were rejected, continue on the new type to fix them.
      router.push(websiteSaved ? "/content-types" : `/content-types/${name}/edit#website`);
    } else {
      const response = await api.contentTypes.update(route.params.name, payload);
      form.value.revision = response.data.revision;
      toast.success(t("contentTypeEdit.updated"));
      await saveWebsiteRoute(route.params.name);
    }

    markClean();
    typesStore.invalidate();
    auth.refresh().catch(() => {});
  } catch (err) {
    const error = err.fields?.allow_submissions;
    submissionError.value = Array.isArray(error) ? error[0] : error ?? "";
    if (submissionError.value) activeTab.value = "base";
    failSave(submissionError.value || err.message);
  } finally {
    saving.value = false;
  }
}

// The inline message sits below the long field list, far from the Save button
// in the sticky header, so failures are announced as a toast as well.
function failSave(message) {
  saveError.value = message;
  toast.error(message);
}

function validateCustomFields(fields) {
  const fieldErrors = {};
  const seen = new Map();
  const reserved = new Set(["title", "slug", "body"]);

  for (const [index, field] of fields.entries()) {
    const key = String(field.key ?? "");

    if (key === "") {
      fieldErrors[index] = t("contentTypeEdit.fieldKeyRequired");
      continue;
    }

    if (!/^[a-z0-9_]+$/.test(key)) {
      fieldErrors[index] = t("contentTypeEdit.fieldKeyInvalid");
      continue;
    }

    if (reserved.has(key)) {
      fieldErrors[index] = t("contentTypeEdit.fieldKeyReserved");
      continue;
    }

    if (seen.has(key)) {
      const firstIndex = seen.get(key);
      fieldErrors[firstIndex] = t("contentTypeEdit.fieldKeyUnique");
      fieldErrors[index] = t("contentTypeEdit.fieldKeyUnique");
      continue;
    }

    seen.set(key, index);

    const defaultError = validateFieldDefault(field);
    if (defaultError) {
      fieldErrors[index] = defaultError;
      continue;
    }
  }

  return {
    ok: Object.keys(fieldErrors).length === 0,
    fieldErrors,
    message: t("contentTypeEdit.fixFieldErrors"),
  };
}

function hasNameConflict() {
  if (!isNew.value) return false;

  const name = toSlug(form.value.name);
  return (
    name !== "" && contentTypes.value.some((type) => type.name === name)
  );
}

function markClean() {
  cleanState.value = currentState();
  isDirty.value = routeDirty();
}

function updateDirtyState() {
  isDirty.value = currentState() !== cleanState.value || routeDirty();
}

function currentState() {
  const formState = { ...form.value };
  delete formState.fields;
  delete formState.revision;

  return JSON.stringify({
    form: formState,
    customFields: customFields.value,
  });
}

function fixSlugOnBlur() {
  if (!isNew.value || !form.value.name) return;
  const v = toSlug(form.value.name);
  if (v) form.value.name = v;
}

function validateFieldDefault(field) {
  if (!supportsConfiguredDefault(field) || !("default" in field)) {
    return "";
  }

  const value = field.default;

  if (field.type === "number" || field.type === "range") {
    const number = Number(value);
    if (!Number.isFinite(number))
      return t("contentTypeEdit.defaultValidNumber");
    if (
      field.type === "range" &&
      Number.isFinite(Number(field.min)) &&
      number < Number(field.min)
    )
      return t("contentTypeEdit.defaultWithinRange");
    if (
      field.type === "range" &&
      Number.isFinite(Number(field.max)) &&
      number > Number(field.max)
    )
      return t("contentTypeEdit.defaultWithinRange");
  }

  if (field.type === "boolean" && typeof value !== "boolean") {
    return t("contentTypeEdit.defaultBoolean");
  }

  if (field.type === "select") {
    const rawOptions = field.options;
    const options = Array.isArray(rawOptions)
      ? rawOptions.map(String)
      : rawOptions && typeof rawOptions === "object"
        ? Object.keys(rawOptions)
        : [];
    const values = field.multiple
      ? Array.isArray(value)
        ? value
        : []
      : value === ""
        ? []
        : [value];

    if (values.some((item) => !options.includes(String(item)))) {
      return t("contentTypeEdit.defaultSelectOption");
    }
  }

  if (
    field.type === "date" &&
    value !== "" &&
    !/^\d{4}-\d{2}-\d{2}$/.test(String(value))
  ) {
    return t("contentTypeEdit.defaultDate");
  }

  if (
    field.type === "datetime" &&
    value !== "" &&
    Number.isNaN(Date.parse(String(value)))
  ) {
    return t("contentTypeEdit.defaultDateTime");
  }

  if (field.type === "json" && typeof value === "string") {
    return t("contentTypeEdit.defaultJson");
  }

  if (
    field.type === "color" &&
    value !== "" &&
    !/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/.test(String(value))
  ) {
    return t("contentTypeEdit.defaultHexColor");
  }

  return "";
}

async function handleDelete() {
  try {
    deleting.value = true;
    await api.contentTypes.delete(route.params.name);
    toast.success(t("contentTypeEdit.deleted"));
    typesStore.invalidate();
    auth.refresh().catch(() => {});
    bypassLeaveGuard();
    router.push("/content-types");
  } catch (err) {
    failSave(err.message);
    showDeleteModal.value = false;
  } finally {
    deleting.value = false;
  }
}
</script>
