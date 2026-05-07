<template>
  <div>
    <div class="editor-sticky-header flex items-center gap-3">
      <router-link
        to="/content-types"
        class="text-slate-500 hover:text-slate-800 transition-colors"
      >
        <Icon icon="mdi:chevron-left" class="w-5 h-5" />
      </router-link>
      <h1 class="text-2xl font-bold text-slate-900 truncate">
        {{
          isNew
            ? t("contentTypeEdit.newTitle")
            : t("contentTypeEdit.editTitle", { name: form.name })
        }}
      </h1>
      <button
        type="submit"
        :disabled="saving"
        class="btn-primary ml-auto relative"
        form="type-form"
      >
        {{ saving ? t("common.saving") : t("contentTypeEdit.saveType") }}
        <span
          v-if="isDirty && !saving"
          class="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-amber-400 ring-2 ring-white"
        ></span>
      </button>
    </div>

    <div
      v-if="loadError"
      class="p-3 bg-red-50 text-red-700 rounded-lg text-sm mb-4"
    >
      {{ loadError }}
    </div>

    <form id="type-form" @submit.prevent="handleSave" class="space-y-6">
      <!-- Basics -->
      <div class="card p-6 space-y-4">
        <h2 class="text-sm font-semibold text-slate-700">
          {{ t("contentTypeEdit.settings") }}
        </h2>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label"
              >{{ t("contentTypeEdit.apiName") }}
              <span class="text-slate-400 font-normal">{{
                t("contentTypeEdit.slugNoSpaces")
              }}</span></label
            >
            <input
              v-model="form.name"
              type="text"
              required
              :disabled="!isNew"
              placeholder="blog-posts"
              class="form-input w-full rounded-lg border-slate-300 text-sm disabled:opacity-50"
              @blur="fixSlugOnBlur"
            />
          </div>

          <div>
            <label class="form-label">{{
              t("contentTypeEdit.displayLabel")
            }}</label>
            <input
              v-model="form.label"
              type="text"
              placeholder="Blog posts"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
            />
          </div>

          <div>
            <label class="form-label">{{
              t("contentTypeEdit.sidebarIcon")
            }}</label>
            <IconPickerGrid v-model="form.icon" :placeholder="defaultIcon" />
          </div>

          <div class="col-span-1">
            <label class="form-label">{{
              t("contentTypeEdit.contentModel")
            }}</label>
            <div
              class="inline-flex rounded-lg border border-slate-200 bg-white p-1"
            >
              <button
                type="button"
                :class="modelButtonClass(!form.singleton)"
                @click="form.singleton = false"
              >
                {{ t("contentTypes.collection") }}
              </button>
              <button
                type="button"
                :class="modelButtonClass(form.singleton)"
                @click="form.singleton = true"
              >
                {{ t("contentTypeEdit.singlePage") }}
              </button>
            </div>
            <p class="mt-1 text-xs text-slate-500">
              {{ t("contentTypeEdit.singleHint") }}
            </p>
          </div>
        </div>
      </div>

      <!-- Localization -->
      <div class="card p-6 space-y-4">
        <div>
          <h2 class="text-sm font-semibold text-slate-700">
            {{ t("contentTypeEdit.localization") }}
          </h2>
          <p class="text-xs text-slate-500 mt-0.5">
            {{ t("contentTypeEdit.localizationHint") }}
          </p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label">{{ t("contentTypeEdit.locales") }}</label>
            <SearchableSelect
              v-model="form.locales"
              :options="LOCALE_OPTIONS"
              :multiple="true"
              :searchable="true"
              :placeholder="t('contentTypeEdit.searchLocales')"
            />
          </div>

          <div>
            <label class="form-label">{{
              t("contentTypeEdit.defaultLocale")
            }}</label>
            <SearchableSelect
              v-model="form.default_locale"
              :options="selectedLocaleOptions"
              :disabled="form.locales.length === 0"
              :placeholder="t('contentTypeEdit.addLocalesFirst')"
            />
            <p
              v-if="form.locales.length > 0"
              class="mt-1 text-xs text-slate-500"
            >
              {{ t("contentTypeEdit.defaultLocaleHint") }}
            </p>
          </div>
        </div>

        <div
          v-if="localizationChanged"
          class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
        >
          {{ t("contentTypeEdit.localizationWarning") }}
        </div>
      </div>

      <!-- Fields -->
      <div class="card p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
          <h2 class="text-sm font-semibold text-slate-700">
            {{ t("contentTypeEdit.fields") }}
            <span class="text-slate-400 font-normal ml-1">{{
              t("contentTypeEdit.builtinFieldsHint")
            }}</span>
          </h2>

          <div class="flex items-center gap-2 text-xs">
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

        <FieldBuilder
          ref="fieldBuilder"
          v-model="customFields"
          :field-types="fieldTypes"
          :content-types="contentTypes"
          :field-errors="customFieldErrors"
        />
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
      v-model="showDeleteModal"
      :title="t('contentTypeEdit.deleteTitle')"
      :message="t('contentTypeEdit.deleteMessage', { name: form.name })"
      :confirm-label="t('contentTypeEdit.deleteConfirm')"
      :loading="deleting"
      @confirm="handleDelete"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Icon } from "@iconify/vue";
import FieldBuilder from "../components/FieldBuilder.vue";
import IconPickerGrid from "../components/IconPickerGrid.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import SearchableSelect from "../components/SearchableSelect.vue";
import { api } from "../api/index.js";
import { supportsConfiguredDefault } from "../composables/fieldDefaults.js";
import { LOCALE_OPTIONS } from "../composables/localeOptions.js";
import { useToastStore } from "../stores/toast.js";
import { useContentTypesStore } from "../stores/contentTypes.js";
import { useAuthStore } from "../stores/auth.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import { contentTypeEndpoint } from "../composables/apiEndpoint.js";
import { useI18n } from "../i18n/index.js";

const route = useRoute();
const router = useRouter();
const toast = useToastStore();
const typesStore = useContentTypesStore();
const auth = useAuthStore();
const apiEndpointStore = useApiEndpointStore();
const { t } = useI18n();
const apiEndpointOwner = "content-type-edit";

const isNew = computed(() => !route.params.name);
const defaultIcon = "mdi:file-document-outline";

const form = ref({
  name: "",
  label: "",
  icon: defaultIcon,
  singleton: false,
  locales: [],
  default_locale: "",
  fields: {},
});
const originalLocalization = ref({ locales: [], default_locale: "" });

// Derived option list for the default_locale select (only chosen locales)
const selectedLocaleOptions = computed(() =>
  LOCALE_OPTIONS.filter((o) => form.value.locales.includes(o.value)),
);
const localizationChanged = computed(() => {
  if (isNew.value) return false;
  return (
    JSON.stringify([...form.value.locales].sort()) !==
      JSON.stringify([...originalLocalization.value.locales].sort()) ||
    form.value.default_locale !== originalLocalization.value.default_locale
  );
});

// Auto-set default_locale when locales change; clear it if it's no longer in the list
watch(
  () => form.value.locales,
  (locales) => {
    if (locales.length === 0) {
      form.value.default_locale = "";
    } else if (!locales.includes(form.value.default_locale)) {
      form.value.default_locale = locales[0];
    }
  },
);
const isDirty = ref(false);
let _formLoaded = false;

// We store only the "custom" fields (not title/slug) in the builder.
const customFields = ref([]);
const fieldTypes = ref([]);
const contentTypes = ref([]);
const loadError = ref("");
const saveError = ref("");
const customFieldErrors = ref({});
const saving = ref(false);
const deleting = ref(false);
const showDeleteModal = ref(false);
const fieldBuilder = ref(null);

watch(
  [form, customFields],
  () => {
    if (_formLoaded) isDirty.value = true;
  },
  { deep: true },
);

watch(
  () => route.params.name,
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

onMounted(async () => {
  try {
    const res = await api.contentTypes.list();
    fieldTypes.value = (res.meta?.field_types ?? res.field_types ?? []).filter(
      (type) => type !== "slug",
    );
    contentTypes.value = res.data ?? [];
  } catch (err) {
    loadError.value = err.message;
  }

  if (!isNew.value) {
    try {
      const res = await api.contentTypes.get(route.params.name);
      const type = res.data;
      form.value = { ...type };
      originalLocalization.value = {
        locales: Array.isArray(type.locales) ? [...type.locales] : [],
        default_locale: type.default_locale ?? "",
      };

      // Strip built-in fields; only show the extras in the builder.
      const builtins = new Set(["title", "slug"]);
      customFields.value = Object.entries(type.fields ?? {})
        .filter(([k]) => !builtins.has(k))
        .map(([key, cfg]) => ({ key, ...cfg }));
    } catch (err) {
      loadError.value = err.message;
    }
  }

  await nextTick();
  _formLoaded = true;
});

async function handleSave() {
  saveError.value = "";
  customFieldErrors.value = {};

  const validation = validateCustomFields(customFields.value);
  if (!validation.ok) {
    customFieldErrors.value = validation.fieldErrors;
    saveError.value = validation.message;
    return;
  }

  saving.value = true;

  try {
    // Merge custom fields back into the payload (built-ins are added server-side).
    // _originalKey / _originalType are internal tracking fields – strip them before sending to the API.
    const fields = {};
    const migrations = [];

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

    const payload = {
      ...form.value,
      fields,
      ...(!isNew.value && migrations.length ? { migrations } : {}),
    };

    if (isNew.value) {
      await api.contentTypes.create(payload);
      toast.success(t("contentTypeEdit.saved"));
      router.push("/content-types");
    } else {
      await api.contentTypes.update(route.params.name, payload);
      toast.success(t("contentTypeEdit.updated"));
      originalLocalization.value = {
        locales: [...form.value.locales],
        default_locale: form.value.default_locale,
      };
    }

    isDirty.value = false;
    typesStore.invalidate();
    auth.refresh().catch(() => {});
  } catch (err) {
    saveError.value = err.message;
  } finally {
    saving.value = false;
  }
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

function fixSlugOnBlur() {
  if (!isNew.value || !form.value.name) return;
  let v = form.value.name.toLowerCase().trim();
  v = v.replace(/[^a-z0-9_-]+/g, "-");
  v = v.replace(/^[-_]+|[-_]+$/g, "");
  if (v) form.value.name = v;
}

function modelButtonClass(active) {
  return [
    "rounded-md px-3 py-1.5 text-sm font-medium transition-colors",
    active
      ? "bg-theme-600 text-white shadow-sm"
      : "text-slate-600 hover:bg-slate-50",
  ];
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
    router.push("/content-types");
  } catch (err) {
    saveError.value = err.message;
    showDeleteModal.value = false;
  } finally {
    deleting.value = false;
  }
}
</script>
