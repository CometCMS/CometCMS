<template>
  <div>
    <div class="editor-sticky-header flex items-center gap-3">
      <router-link
        :to="backLink"
        class="text-slate-500 hover:text-slate-800 transition-colors"
      >
        <Icon icon="mdi:chevron-left" class="w-5 h-5" />
      </router-link>
      <h1 class="text-2xl font-bold text-slate-900 truncate">
        {{ editorTitle }}
      </h1>
      <div class="ml-auto flex items-center gap-2">
        <button
          v-if="!isNew"
          @click="openRevisions"
          class="btn-secondary"
          type="button"
        >
          {{ t("contentEdit.history") }}
        </button>
        <span
          v-if="isReadOnly"
          class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200"
        >
          {{ t("contentEdit.preview") }}
        </span>
        <button
          v-if="canSaveEntry"
          type="submit"
          :disabled="saving"
          class="btn-primary relative"
          form="entry-form"
        >
          {{ saving ? t("common.saving") : saveLabel }}
          <span
            v-if="isDirty && !saving"
            class="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-amber-400 ring-2 ring-white"
          ></span>
        </button>
      </div>
    </div>

    <!-- Restore revision banner -->
    <div
      v-if="previewingRevision"
      class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center justify-between gap-4"
    >
      <p class="text-sm text-amber-800">
        {{
          t("contentEdit.restoreBanner", {
            date: formatDateTime(previewingRevision.created_at),
          })
        }}
      </p>
      <button
        type="button"
        @click="cancelRestore"
        class="text-xs text-amber-700 hover:text-amber-900 underline shrink-0"
      >
        {{ t("contentEdit.cancelRestore") }}
      </button>
    </div>

    <div
      v-if="loadError"
      class="p-3 bg-red-50 text-red-700 rounded-lg text-sm mb-4"
    >
      {{ loadError }}
    </div>
    <div
      v-else-if="isReadOnly"
      class="mb-4 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600"
    >
      {{ t("contentEdit.readOnlyDescription") }}
    </div>

    <!-- Locale switcher (only for localized content types) -->
    <div v-if="contentTypeLocales.length > 0 && !isNew" class="mb-4 space-y-2">
      <div class="flex items-center gap-1.5 flex-wrap">
        <span class="text-xs font-medium text-slate-500 mr-1">{{
          t("contentEdit.locales")
        }}</span>
        <template v-for="loc in orderedContentTypeLocales" :key="loc">
          <span
            v-if="hasTranslation(loc)"
            data-locale-menu-root
            :class="[
              'group/locale relative inline-flex items-center gap-1 rounded-full text-xs font-medium ring-1 ring-inset transition-colors',
              currentLocale === loc
                ? 'bg-theme-600 text-white ring-theme-600'
                : 'bg-white text-slate-600 ring-slate-300',
            ]"
          >
            <button
              type="button"
              @click="switchLocale(loc)"
              class="pl-3 py-1 hover:opacity-80 transition-opacity inline-flex items-center gap-1"
            >
              <span>{{ localeLabel(loc) }}</span>
              <span
                v-if="loc === defaultLocale"
                class="text-[10px] uppercase tracking-wide opacity-75"
                >{{ t("contentEdit.default") }}</span
              >
            </button>
            <button
              type="button"
              :class="[
                'mr-1 flex h-5 w-5 items-center justify-center rounded-full opacity-0 transition hover:opacity-100 focus:opacity-100 group-hover/locale:opacity-100',
                currentLocale === loc
                  ? 'hover:bg-white/15'
                  : 'hover:bg-slate-100',
              ]"
              :title="t('contentEdit.localeActions')"
              @click.stop="toggleLocaleMenu(loc)"
            >
              <Icon icon="mdi:dots-horizontal" class="w-4 h-4" />
            </button>
            <div
              v-if="localeMenu === loc"
              class="absolute left-0 top-8 z-20 w-60 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-sm text-slate-700 shadow-lg"
            >
              <button
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50 disabled:cursor-not-allowed disabled:text-slate-400 disabled:hover:bg-white"
                :disabled="isReadOnly || loc === defaultLocale"
                @click.stop="syncLocaleFromDefault(loc)"
              >
                <Icon icon="mdi:sync" class="h-4 w-4" />
                {{ t("contentEdit.syncContentDefault") }}
              </button>
              <button
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:text-red-300 disabled:hover:bg-white"
                :disabled="
                  isReadOnly || loc === defaultLocale || deletingTranslation
                "
                @click.stop="handleDeleteTranslation(loc)"
              >
                <Icon icon="mdi:trash-can-outline" class="h-4 w-4" />
                {{ t("contentEdit.remove") }}
              </button>
            </div>
          </span>
          <span
            v-else
            data-locale-menu-root
            :class="[
              'group/locale relative inline-flex items-center gap-1 rounded-full text-xs font-medium ring-1 ring-dashed transition-colors',
              currentLocale === loc
                ? 'bg-theme-50 text-theme-600 ring-theme-300'
                : 'bg-slate-50 text-slate-400 ring-slate-300 hover:ring-theme-400 hover:text-theme-500',
            ]"
          >
            <button
              type="button"
              :disabled="isReadOnly"
              @click="createTranslation(loc)"
              class="pl-3 py-1 inline-flex items-center gap-1 disabled:cursor-default"
            >
              <Icon icon="mdi:plus" class="w-3 h-3" />
              <span>{{ localeLabel(loc) }}</span>
              <span
                v-if="loc === defaultLocale"
                class="text-[10px] uppercase tracking-wide"
                >{{ t("contentEdit.default") }}</span
              >
            </button>
            <button
              type="button"
              class="mr-1 flex h-5 w-5 items-center justify-center rounded-full opacity-0 transition hover:bg-slate-100 hover:opacity-100 focus:opacity-100 group-hover/locale:opacity-100"
              :title="t('contentEdit.localeActions')"
              @click.stop="toggleLocaleMenu(loc)"
            >
              <Icon icon="mdi:dots-horizontal" class="w-4 h-4" />
            </button>
            <div
              v-if="localeMenu === loc"
              class="absolute left-0 top-8 z-20 w-60 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-sm text-slate-700 shadow-lg"
            >
              <button
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50 disabled:cursor-not-allowed disabled:text-slate-400 disabled:hover:bg-white"
                :disabled="isReadOnly || loc === defaultLocale"
                @click.stop="syncLocaleFromDefault(loc)"
              >
                <Icon icon="mdi:sync" class="h-4 w-4" />
                {{ t("contentEdit.syncContentDefault") }}
              </button>
              <button
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:text-red-300 disabled:hover:bg-white"
                disabled
              >
                <Icon icon="mdi:trash-can-outline" class="h-4 w-4" />
                {{ t("contentEdit.remove") }}
              </button>
            </div>
          </span>
        </template>
      </div>
      <p
        v-if="unsupportedTranslationLocales.length > 0"
        class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"
      >
        {{
          t("contentEdit.unsupportedLocales", {
            locales: unsupportedTranslationLocales.join(", "),
          })
        }}
      </p>
    </div>

    <form
      v-if="!loadError"
      id="entry-form"
      @submit.prevent="handleSave"
      class="space-y-6"
    >
      <!-- Core fields -->
      <div class="card p-6 space-y-4">
        <h2 class="text-sm font-semibold text-slate-700">
          {{ t("contentEdit.core") }}
        </h2>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label">{{ t("contentEdit.status") }}</label>
            <select
              v-model="form.status"
              :disabled="isReadOnly"
              class="form-select w-full rounded-lg border-slate-300 text-sm"
            >
              <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>

          <div v-if="isNew && contentTypeLocales.length > 0">
            <label class="form-label">{{ t("contentEdit.locale") }}</label>
            <select
              v-model="form.locale"
              :disabled="isReadOnly"
              class="form-select w-full rounded-lg border-slate-300 text-sm"
            >
              <option
                v-for="loc in orderedContentTypeLocales"
                :key="loc"
                :value="loc"
              >
                {{ loc }}
              </option>
            </select>
          </div>
          <div v-else>
            <label class="form-label">{{ t("contentEdit.author") }}</label>
            <select
              v-model="form.author_id"
              :disabled="isReadOnly"
              class="form-select w-full rounded-lg border-slate-300 text-sm"
            >
              <option :value="null" disabled>
                {{ t("contentEdit.select") }}
              </option>
              <option v-for="u in users" :key="u.id" :value="u.id">
                {{ u.username }}
              </option>
            </select>
          </div>

          <div class="col-span-2">
            <label class="form-label">{{ t("contentEdit.title") }}</label>
            <input
              v-model="form.title"
              type="text"
              required
              :readonly="isReadOnly"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
            />
          </div>

          <div v-if="!isSingleton">
            <label class="form-label">
              Slug
              <span class="text-slate-400 font-normal ml-1">{{
                t("contentEdit.slugGenerated")
              }}</span>
            </label>
            <input
              v-model="form.slug"
              type="text"
              :readonly="isReadOnly"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
            />
            <p
              v-if="contentTypeLocales.length > 0"
              class="mt-1 text-xs text-slate-500"
            >
              {{ t("contentEdit.slugShared") }}
            </p>
          </div>

          <div>
            <label class="form-label">
              <template v-if="form.status === 'published' && isScheduled"
                >{{ t("contentEdit.scheduledFor") }}
                <span class="text-slate-400 font-normal ml-1">{{
                  t("contentEdit.autoPublishes")
                }}</span></template
              >
              <template v-else>{{ t("contentEdit.publishedAt") }}</template>
            </label>
            <input
              v-model="form.published_at"
              type="datetime-local"
              :readonly="isReadOnly"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
            />
          </div>
        </div>

        <!-- Entry metadata (read-only) -->
        <div
          v-if="!isNew"
          class="pt-2 border-t border-slate-100 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs text-slate-500"
        >
          <div class="flex gap-1.5">
            <span class="font-medium text-slate-400 shrink-0">{{
              t("contentEdit.created")
            }}</span>
            <span>{{ formatDateTime(entryMeta.created_at) }}</span>
          </div>
          <div class="flex gap-1.5">
            <span class="font-medium text-slate-400 shrink-0">{{
              t("contentEdit.updated")
            }}</span>
            <span>{{ formatDateTime(entryMeta.updated_at) }}</span>
          </div>
        </div>
      </div>

      <!-- Custom fields -->
      <div v-if="hasCustomFields" class="card p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
          <h2 class="text-sm font-semibold text-slate-700">
            {{ t("contentEdit.fields") }}
          </h2>
          <router-link
            v-if="canEditContentType"
            :to="contentTypeEditLink"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
            :title="t('contentEdit.editContentType')"
            :aria-label="t('contentEdit.editContentType')"
          >
            <Icon icon="mdi:cog-outline" class="h-5 w-5" />
          </router-link>
        </div>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-12">
          <div
            v-for="(config, fieldName) in customSchema"
            :key="fieldName"
            :class="fieldLayoutClass(config)"
          >
            <BaseField
              :name="fieldName"
              :config="config"
              :error="fieldErrors[fieldName]"
              :readonly="isReadOnly || isReadonlyUniversalField(config)"
            >
              <FieldInput
                :name="fieldName"
                :config="config"
                v-model="form[fieldName]"
                :readonly="isReadOnly || isReadonlyUniversalField(config)"
              />
            </BaseField>
          </div>
        </div>
      </div>

      <div
        v-if="saveError"
        class="p-3 bg-red-50 text-red-700 rounded-lg text-sm"
      >
        {{ saveError }}
      </div>

      <div v-if="!isNew && canDeleteEntry" class="flex justify-end">
        <button
          type="button"
          @click="showDeleteModal = true"
          class="btn-danger"
        >
          {{ deleteLabel }}
        </button>
      </div>
    </form>

    <ConfirmModal
      v-model="showDeleteModal"
      :title="t('contentEdit.moveTrashTitle')"
      :message="deleteMessage"
      :confirm-label="t('contentEdit.moveTrashConfirm')"
      :loading="deleting"
      @confirm="handleDelete"
    />

    <ConfirmModal
      v-model="showDeleteTranslationModal"
      :title="
        t('contentEdit.deleteLocaleTitle', { locale: pendingDeleteLocale })
      "
      :message="t('contentEdit.deleteLocaleMessage')"
      :confirm-label="t('contentEdit.deleteLocaleConfirm')"
      :loading="deletingTranslation"
      @confirm="confirmDeleteTranslation"
    />

    <SlidePanel
      v-model="showRevisions"
      :title="t('contentEdit.entryHistory')"
      :subtitle="`${revisions.length} ${t(revisions.length === 1 ? 'contentEdit.savedRevision' : 'contentEdit.savedRevisions')}`"
    >
      <div
        v-if="revisionError"
        class="m-4 p-3 bg-red-50 text-red-700 rounded-lg text-sm"
      >
        {{ revisionError }}
      </div>

      <div v-if="loadingRevisions" class="p-6 text-sm text-slate-500">
        {{ t("contentEdit.loading") }}
      </div>
      <div
        v-else-if="revisions.length === 0"
        class="p-6 text-sm text-slate-500"
      >
        {{ t("contentEdit.noRevisions") }}
      </div>

      <div v-else class="divide-y divide-slate-100">
        <!-- Current state entry -->
        <article class="p-5 space-y-3">
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <h3
                class="text-sm font-semibold text-slate-900 flex items-center gap-2"
              >
                {{ form.title || form.slug }}
                <span
                  class="text-xs font-medium bg-theme-100 text-theme-700 px-1.5 py-0.5 rounded"
                  >{{ t("contentEdit.current") }}</span
                >
              </h3>
              <p class="text-xs text-slate-500 capitalize">{{ form.status }}</p>
            </div>
          </div>
          <div v-if="revisions.length > 0">
            <template v-if="currentVsFirstDiff.length > 0">
              <ul class="text-xs space-y-1">
                <li
                  v-for="change in currentVsFirstDiff"
                  :key="change.field"
                  class="flex items-baseline gap-1 min-w-0"
                >
                  <span class="text-slate-400 shrink-0 font-medium"
                    >{{ change.field }}:</span
                  >
                  <span
                    class="text-slate-400 line-through truncate max-w-[100px]"
                    >{{ formatRevVal(change.from) }}</span
                  >
                  <span class="text-slate-400 shrink-0">→</span>
                  <span class="text-slate-700 truncate max-w-[100px]">{{
                    formatRevVal(change.to)
                  }}</span>
                </li>
              </ul>
            </template>
            <p v-else class="text-xs text-slate-400">
              {{ t("contentEdit.noChanges") }}
            </p>
          </div>
        </article>

        <article
          v-for="(revision, index) in revisions"
          :key="revision.id"
          class="p-5 space-y-3"
        >
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <h3 class="text-sm font-semibold text-slate-900">
                {{ revision.title || revision.slug }}
              </h3>
              <p class="text-xs text-slate-500">
                {{ formatDateTime(revision.created_at) }} ·
                {{ revision.status }}
              </p>
              <!-- Author -->
              <div
                v-if="userMap[revision.created_by]"
                class="flex items-center gap-1.5 mt-1"
              >
                <div
                  class="w-4 h-4 rounded-full overflow-hidden bg-theme-600 flex items-center justify-center text-white text-xs font-semibold select-none shrink-0"
                >
                  <img
                    v-if="userMap[revision.created_by].has_avatar"
                    :src="`/admin/api/users/${revision.created_by}/avatar`"
                    class="w-full h-full object-cover"
                    :alt="userMap[revision.created_by].username"
                  />
                  <span v-else style="font-size: 9px">{{
                    userMap[revision.created_by].username?.[0]?.toUpperCase()
                  }}</span>
                </div>
                <span class="text-xs text-slate-500">{{
                  userMap[revision.created_by].username
                }}</span>
              </div>
              <div
                v-else-if="revision.created_by"
                class="flex items-center gap-1.5 mt-1"
              >
                <div
                  class="w-4 h-4 rounded-full bg-slate-300 flex items-center justify-center shrink-0"
                >
                  <span style="font-size: 9px" class="text-slate-500">?</span>
                </div>
                <span class="text-xs text-slate-400"
                  >{{ revision.created_by }}
                  <span class="italic">{{
                    t("contentEdit.deletedUser")
                  }}</span></span
                >
              </div>
            </div>
            <button
              v-if="canSaveEntry"
              type="button"
              class="btn-secondary text-xs py-1.5 px-3 shrink-0"
              @click="restoreRevision(revision)"
            >
              {{ t("contentEdit.restore") }}
            </button>
          </div>

          <!-- Changes vs previous revision -->
          <div v-if="index < revisions.length - 1">
            <template v-if="revisionDiff(index).length > 0">
              <ul class="text-xs space-y-1">
                <li
                  v-for="change in revisionDiff(index)"
                  :key="change.field"
                  class="flex items-baseline gap-1 min-w-0"
                >
                  <span class="text-slate-400 shrink-0 font-medium"
                    >{{ change.field }}:</span
                  >
                  <span
                    class="text-slate-400 line-through truncate max-w-[100px]"
                    >{{ formatRevVal(change.from) }}</span
                  >
                  <span class="text-slate-400 shrink-0">→</span>
                  <span class="text-slate-700 truncate max-w-[100px]">{{
                    formatRevVal(change.to)
                  }}</span>
                </li>
              </ul>
            </template>
            <p v-else class="text-xs text-slate-400">
              {{ t("contentEdit.noFieldChanges") }}
            </p>
          </div>
          <p v-else class="text-xs text-slate-400 italic">
            {{ t("contentEdit.oldestRevision") }}
          </p>
        </article>
      </div>
    </SlidePanel>
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
import FieldInput from "../components/FieldInput.vue";
import BaseField from "../components/BaseField.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import SlidePanel from "../components/SlidePanel.vue";
import { api } from "../api/index.js";
import { fieldDefaultValue } from "../composables/fieldDefaults.js";
import { localeLabel } from "../composables/localeOptions.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";
import { useAuthStore } from "../stores/auth.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import {
  contentCollectionEndpoint,
  contentEntryEndpoint,
} from "../composables/apiEndpoint.js";

const users = ref([]);
const userMap = computed(() =>
  Object.fromEntries(users.value.map((u) => [u.id, u])),
);

async function loadUsers() {
  try {
    users.value = (await api.users.list()).data;
  } catch {
    users.value = [];
  }
}

const route = useRoute();
const router = useRouter();
const toast = useToastStore();
const { t } = useI18n();
const auth = useAuthStore();
const apiEndpointStore = useApiEndpointStore();
const collection = route.params.collection;
const apiEndpointOwner = "content-edit";
const singletonCreateMode = ref(false);
const isSingleton = computed(() => !!contentTypeSchema.value?.singleton);
const entryId = computed(
  () => route.params.id || (isSingleton.value ? collection : ""),
);
const isNew = computed(() => !entryId.value || singletonCreateMode.value);
const backLink = computed(() =>
  isSingleton.value ? "/dashboard" : `/content/${collection}`,
);
const editorTitle = computed(() => {
  if (isSingleton.value)
    return (
      contentTypeSchema.value?.label ||
      form.value.title ||
      t("contentEdit.singlePage")
    );
  return isNew.value
    ? t("contentEdit.newEntryTitle")
    : t("contentEdit.editEntryTitle", { title: form.value.title });
});
const saveLabel = computed(() =>
  isSingleton.value ? t("contentEdit.savePage") : t("contentEdit.saveEntry"),
);
const deleteLabel = computed(() =>
  isSingleton.value
    ? t("contentEdit.deletePage")
    : t("contentEdit.deleteEntry"),
);
const contentTypeEditLink = computed(() => `/content-types/${collection}/edit`);
const canEditContentType = computed(() => {
  const resource = `schema:${collection}`;
  return (
    auth.can("schema.read", resource) && auth.can("schema.update", resource)
  );
});
const contentResourceCandidates = computed(() => {
  const candidates = [
    `content:${collection}:*`,
    `content:${collection}`,
    "content:*",
    "*",
  ];

  if (entryId.value) {
    candidates.unshift(`content:${collection}:${entryId.value}`);
  }

  if (form.value.slug && form.value.slug !== entryId.value) {
    candidates.unshift(`content:${collection}:${form.value.slug}`);
  }

  return [...new Set(candidates)];
});
const canCreateEntry = computed(() => {
  return [
    `content:${collection}:*`,
    `content:${collection}`,
    "content:*",
    "*",
  ].some((resource) => auth.can("content.create", resource));
});
const canUpdateEntry = computed(() =>
  contentResourceCandidates.value.some((resource) =>
    auth.can("content.update", resource),
  ),
);
const canDeleteEntry = computed(() =>
  contentResourceCandidates.value.some((resource) =>
    auth.can("content.delete", resource),
  ),
);
const canSaveEntry = computed(() =>
  isNew.value ? canCreateEntry.value : canUpdateEntry.value,
);
const isReadOnly = computed(() => !isNew.value && !canUpdateEntry.value);
const deleteMessage = computed(() => {
  return isSingleton.value
    ? t("contentEdit.pageTrashMessage")
    : t("contentEdit.entryTrashMessage");
});
const statuses = ["draft", "published", "protected", "archived"];
const isScheduled = computed(() => {
  if (form.value.status !== "published" || !form.value.published_at)
    return false;
  return new Date(form.value.published_at) > new Date();
});

const form = ref({
  status: "draft",
  title: "",
  slug: "",
  published_at: "",
  author_id: null,
  locale: "",
});
const contentTypeLocales = ref([]);
const fullEntry = ref(null); // full raw entry including translations sub-object
const currentLocale = ref(""); // active locale tab
const defaultLocale = computed(
  () =>
    contentTypeSchema.value?.default_locale ||
    contentTypeLocales.value[0] ||
    "",
);
const orderedContentTypeLocales = computed(() =>
  orderLocales(contentTypeLocales.value, defaultLocale.value),
);
const apiEndpointUrl = computed(() => {
  if (!isNew.value || isSingleton.value) {
    return contentEntryEndpoint({
      collection,
      entryId: entryId.value || collection,
      locale: currentLocale.value,
    });
  }

  return contentCollectionEndpoint({
    collection,
    limit: 20,
    offset: 0,
    sortKey: "created_at",
    sortDir: "desc",
  });
});
const unsupportedTranslationLocales = computed(() => {
  const translations = fullEntry.value?.translations;
  if (!translations || contentTypeLocales.value.length === 0) return [];
  return Object.keys(translations).filter(
    (loc) => !contentTypeLocales.value.includes(loc),
  );
});
function hasTranslation(loc) {
  return (
    fullEntry.value?.translations != null && loc in fullEntry.value.translations
  );
}

function orderLocales(locales, defaultLocale) {
  const unique = [...new Set(locales)];
  if (!defaultLocale || !unique.includes(defaultLocale)) return unique;
  return [defaultLocale, ...unique.filter((loc) => loc !== defaultLocale)];
}

function populateFormFromLocale(entry, loc) {
  // translations[loc] is always the authoritative source for per-locale content.
  // Root-level fields are only a search/sort copy and may be stale after a default
  // locale change, so we always prefer the translation data and fall back to root.
  const translation = entry.translations?.[loc] ?? null;
  const source = translation ?? entry;
  const defaultSource = localeContentSource(defaultLocale.value) ?? entry;
  form.value.title = source.title ?? "";
  for (const name of Object.keys(customSchema.value)) {
    const config = customSchema.value[name];
    form.value[name] = isUniversalField(config)
      ? fieldValueForForm(defaultSource[name] ?? entry[name] ?? null, config)
      : fieldValueForForm(source[name] ?? null, config);
  }
  form.value.locale = loc;
}

function switchLocale(loc) {
  if (!fullEntry.value) return;
  currentLocale.value = loc;
  populateFormFromLocale(fullEntry.value, loc);
}

function createTranslation(loc) {
  if (isReadOnly.value) return;
  localeMenu.value = null;
  currentLocale.value = loc;
  const defaultSource =
    localeContentSource(defaultLocale.value) ?? fullEntry.value ?? {};
  form.value.title = "";
  for (const name of Object.keys(customSchema.value)) {
    const config = customSchema.value[name];
    form.value[name] = isUniversalField(config)
      ? fieldValueForForm(defaultSource[name] ?? null, config)
      : null;
  }
  form.value.locale = loc;
}

async function handleDeleteTranslation(loc) {
  if (isReadOnly.value || deletingTranslation.value) return;
  localeMenu.value = null;
  pendingDeleteLocale.value = loc;
  showDeleteTranslationModal.value = true;
}

function toggleLocaleMenu(loc) {
  localeMenu.value = localeMenu.value === loc ? null : loc;
}

function closeLocaleMenu(event) {
  if (localeMenu.value === null) return;
  const target = event.target;
  if (target instanceof Element && target.closest("[data-locale-menu-root]"))
    return;
  localeMenu.value = null;
}

function onLocaleMenuKeydown(event) {
  if (event.key === "Escape") localeMenu.value = null;
}

function cloneLocaleValue(value) {
  if (value === undefined) return null;
  if (Array.isArray(value)) return value.map((item) => cloneLocaleValue(item));
  if (value && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value).map(([key, item]) => [key, cloneLocaleValue(item)]),
    );
  }
  return value;
}

function isUniversalField(config) {
  return contentTypeLocales.value.length > 0 && config?.localized === false;
}

function isReadonlyUniversalField(config) {
  return (
    isUniversalField(config) &&
    currentLocale.value !== "" &&
    currentLocale.value !== defaultLocale.value
  );
}

function fieldLayoutClass(config) {
  const width = String(config?.layout?.width ?? "full");

  return {
    "md:col-span-4": width === "1/3",
    "md:col-span-6": width === "1/2",
    "md:col-span-8": width === "2/3",
    "md:col-span-12": !["1/3", "1/2", "2/3"].includes(width),
  };
}

function localeContentSource(loc) {
  const entry = fullEntry.value;
  if (!entry) return null;
  const translation = entry.translations?.[loc];
  return translation && typeof translation === "object"
    ? { ...entry, ...translation }
    : entry;
}

function syncLocaleFromDefault(loc) {
  if (isReadOnly.value) return;
  localeMenu.value = null;
  if (!fullEntry.value || loc === defaultLocale.value) return;

  const source = localeContentSource(defaultLocale.value);
  if (!source) {
    toast.error(t("contentEdit.defaultUnavailable"));
    return;
  }

  const next = {
    ...form.value,
    title: source.title ?? "",
    locale: loc,
  };

  for (const name of Object.keys(customSchema.value)) {
    next[name] = Object.prototype.hasOwnProperty.call(source, name)
      ? fieldValueForForm(source[name], customSchema.value[name])
      : fieldDefaultValue(customSchema.value[name]);
  }

  currentLocale.value = loc;
  form.value = next;
  toast.success(t("contentEdit.contentSynced"));
}

async function confirmDeleteTranslation() {
  const loc = pendingDeleteLocale.value;
  if (!loc || !entryId.value) return;

  deletingTranslation.value = true;
  try {
    const res = await api.content.deleteTranslation(
      collection,
      entryId.value,
      loc,
    );
    fullEntry.value = res.data;
    toast.success(t("contentEdit.localeDeleted", { locale: loc }));

    // Switch to default locale
    currentLocale.value = defaultLocale.value;
    populateFormFromLocale(fullEntry.value, defaultLocale.value);
  } catch (err) {
    toast.error(err.message ?? t("contentEdit.deleteLocaleFailed"));
  } finally {
    deletingTranslation.value = false;
    showDeleteTranslationModal.value = false;
    pendingDeleteLocale.value = "";
  }
}
const isDirty = ref(false);
let _formLoaded = false;
const entryMeta = ref({ created_at: null, updated_at: null, id: null });
const contentTypeSchema = ref(null);

watch(
  form,
  () => {
    if (_formLoaded) isDirty.value = true;
  },
  { deep: true },
);
const customSchema = ref({});
const fieldErrors = ref({});
const loadError = ref("");
const showDeleteModal = ref(false);
const deleting = ref(false);
const deletingTranslation = ref(false);
const showDeleteTranslationModal = ref(false);
const pendingDeleteLocale = ref("");
const localeMenu = ref(null);
const saving = ref(false);
const saveError = ref("");
const showRevisions = ref(false);
const revisions = ref([]);
const loadingRevisions = ref(false);
const revisionError = ref("");
const previewingRevision = ref(null);

const hasCustomFields = computed(
  () => Object.keys(customSchema.value).length > 0,
);

onMounted(async () => {
  document.addEventListener("pointerdown", closeLocaleMenu);
  document.addEventListener("keydown", onLocaleMenuKeydown);

  loadUsers();

  try {
    const typeRes = await api.contentTypes.get(collection);
    contentTypeSchema.value = typeRes.data;
    const builtins = new Set(["title", "slug"]);
    customSchema.value = Object.fromEntries(
      Object.entries(typeRes.data.fields ?? {}).filter(
        ([k]) => !builtins.has(k),
      ),
    );
    contentTypeLocales.value = typeRes.data.locales ?? [];

    if (isSingleton.value && route.params.id) {
      router.replace(`/content/${collection}`);
      return;
    }

    if (isNew.value) {
      if (isSingleton.value) {
        form.value.title = typeRes.data.label || collection;
        form.value.slug = collection;
      }
      if (defaultLocale.value) {
        form.value.locale = defaultLocale.value;
        currentLocale.value = defaultLocale.value;
      }
    }

    form.value = applyFieldDefaults(form.value);
  } catch {
    // Field defaults are best-effort; the entry load below still shows API errors.
  }

  if (!isNew.value) {
    try {
      const res = await api.content.get(collection, entryId.value);
      const entry = res.data;
      fullEntry.value = entry;
      entryMeta.value = {
        created_at: entry.created_at ?? null,
        updated_at: entry.updated_at ?? null,
        id: entry.id ?? null,
      };

      const initLocale =
        contentTypeLocales.value.length > 0 ? defaultLocale.value : "";
      currentLocale.value = initLocale;

      form.value = {
        status: entry.status ?? "draft",
        slug: entry.slug ?? "",
        published_at: entry.published_at
          ? toDatetimeLocal(entry.published_at)
          : "",
        author_id: entry.author_id ?? null,
        locale: initLocale,
        title: "",
      };

      if (contentTypeLocales.value.length > 0 && initLocale) {
        populateFormFromLocale(entry, initLocale, defaultLocale.value);
      } else {
        form.value.title = entry.title ?? "";
        for (const name of Object.keys(customSchema.value)) {
          form.value[name] = fieldValueForForm(
            entry[name] ?? null,
            customSchema.value[name],
          );
        }
      }

      form.value = applyFieldDefaults(form.value);
    } catch (err) {
      if (
        isSingleton.value &&
        entryId.value === collection &&
        err.status === 404
      ) {
        singletonCreateMode.value = true;
        form.value = applyFieldDefaults({
          ...form.value,
          title: contentTypeSchema.value?.label || collection,
          slug: collection,
          locale: defaultLocale.value,
        });
        currentLocale.value = defaultLocale.value;
      } else {
        loadError.value = err.message;
      }
    }
  }

  await nextTick();
  _formLoaded = true;
});

onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", closeLocaleMenu);
  document.removeEventListener("keydown", onLocaleMenuKeydown);
  apiEndpointStore.clearEndpoint(apiEndpointOwner);
});

watch(
  apiEndpointUrl,
  (url) => {
    apiEndpointStore.setEndpoint(
      {
        label: isNew.value && !isSingleton.value ? "Collection" : "Entry",
        url,
      },
      apiEndpointOwner,
    );
  },
  { immediate: true },
);

async function handleSave() {
  if (!canSaveEntry.value) return;
  saveError.value = "";
  fieldErrors.value = {};
  saving.value = true;

  try {
    if (isSingleton.value) {
      form.value.slug = collection;
    }

    if (isNew.value) {
      const res = await api.content.create(collection, payloadForSave());
      toast.success(
        isSingleton.value
          ? t("contentEdit.pageCreated")
          : t("contentEdit.entryCreated"),
      );
      if (isSingleton.value) {
        singletonCreateMode.value = false;
        isDirty.value = false;
        fullEntry.value = res.data;
        entryMeta.value = {
          created_at: res.data.created_at ?? null,
          updated_at: res.data.updated_at ?? null,
          id: res.data.id ?? null,
        };
      } else {
        router.push(`/content/${collection}/${res.data.id}`);
      }
    } else {
      const res = await api.content.update(
        collection,
        entryId.value,
        payloadForSave(),
      );
      toast.success(
        isSingleton.value
          ? t("contentEdit.pageSaved")
          : t("contentEdit.entrySaved"),
      );
      isDirty.value = false;
      fullEntry.value = res.data;
      entryMeta.value = {
        created_at: res.data.created_at ?? null,
        updated_at: res.data.updated_at ?? null,
        id: res.data.id ?? null,
      };
      if (!isSingleton.value && res.data.id && res.data.id !== entryId.value) {
        router.replace(`/content/${collection}/${res.data.id}`);
      }
    }
  } catch (err) {
    saveError.value = err.message;
    fieldErrors.value = err.fields ?? {};
  } finally {
    saving.value = false;
  }
}

async function openRevisions() {
  showRevisions.value = true;
  revisionError.value = "";
  loadingRevisions.value = true;

  try {
    const res = await api.content.revisions(collection, entryId.value);
    revisions.value = res.data ?? [];
  } catch (err) {
    revisionError.value = err.message;
  } finally {
    loadingRevisions.value = false;
  }
}

function restoreRevision(revision) {
  if (!canSaveEntry.value) return;
  const entry = revision.entry ?? revision;
  form.value = {
    status: entry.status ?? "draft",
    title: entry.title ?? "",
    slug: entry.slug ?? "",
    published_at: entry.published_at ? toDatetimeLocal(entry.published_at) : "",
    author_id: entry.author_id ?? form.value.author_id,
    ...entry.data,
  };
  form.value = applyFieldDefaults(form.value);
  form.value = formValuesForEditing(form.value);
  previewingRevision.value = revision;
  showRevisions.value = false;
  toast.success(t("contentEdit.revisionLoaded"));
}

function payloadForSave() {
  const payload = { ...form.value };
  payload.published_at = localDatetimeToUtcIso(payload.published_at);

  for (const [fieldName, config] of Object.entries(customSchema.value)) {
    if (
      config?.type === "datetime" &&
      Object.prototype.hasOwnProperty.call(payload, fieldName)
    ) {
      payload[fieldName] = localDatetimeToUtcIso(payload[fieldName]);
    }
  }

  return payload;
}

function formValuesForEditing(source) {
  const next = { ...source };
  if (next.published_at) next.published_at = toDatetimeLocal(next.published_at);

  for (const [fieldName, config] of Object.entries(customSchema.value)) {
    if (Object.prototype.hasOwnProperty.call(next, fieldName)) {
      next[fieldName] = fieldValueForForm(next[fieldName], config);
    }
  }

  return next;
}

function fieldValueForForm(value, config) {
  if (config?.type === "datetime" && value) {
    return toDatetimeLocal(value);
  }

  return cloneLocaleValue(value);
}

function cancelRestore() {
  previewingRevision.value = null;
}

function applyFieldDefaults(source) {
  const next = { ...source };

  for (const [fieldName, config] of Object.entries(customSchema.value)) {
    if (next[fieldName] === undefined) {
      next[fieldName] = fieldDefaultValue(config);
    } else if (config?.type === "repeater" && Array.isArray(next[fieldName])) {
      next[fieldName] = next[fieldName].map((row) =>
        applySubfieldDefaults(row, config.subfields ?? []),
      );
    }
  }

  return next;
}

function applySubfieldDefaults(row, subfields) {
  const next = { ...(row ?? {}) };

  for (const subfield of subfields) {
    if (!subfield?.key || next[subfield.key] !== undefined) continue;
    next[subfield.key] = fieldDefaultValue(subfield);
  }

  return next;
}

const REVISION_SKIP = new Set([
  "id",
  "collection",
  "created_at",
  "updated_at",
  "author_id",
  "deleted_at",
  "deleted_by",
  "updated_by",
  "data",
]);

const currentVsFirstDiff = computed(() => {
  if (revisions.value.length === 0) return [];
  const current = flatRevisionEntry(form.value);
  const previous = flatRevisionEntry(revisions.value[0]?.entry);
  const allKeys = new Set([...Object.keys(current), ...Object.keys(previous)]);
  const changes = [];
  for (const key of allKeys) {
    if (
      JSON.stringify(normDiffVal(current[key])) !==
      JSON.stringify(normDiffVal(previous[key]))
    ) {
      changes.push({ field: key, from: previous[key], to: current[key] });
    }
  }
  return changes;
});

function flatRevisionEntry(entry) {
  const out = {};
  for (const [k, v] of Object.entries(entry ?? {})) {
    if (!REVISION_SKIP.has(k)) out[k] = v;
  }
  for (const [k, v] of Object.entries((entry ?? {}).data ?? {})) {
    if (!REVISION_SKIP.has(k)) out[k] = v;
  }
  return out;
}

function revisionDiff(index) {
  const current = flatRevisionEntry(revisions.value[index]?.entry);
  const previous = flatRevisionEntry(revisions.value[index + 1]?.entry);
  const allKeys = new Set([...Object.keys(current), ...Object.keys(previous)]);
  const changes = [];
  for (const key of allKeys) {
    if (
      JSON.stringify(normDiffVal(current[key])) !==
      JSON.stringify(normDiffVal(previous[key]))
    ) {
      changes.push({ field: key, from: previous[key], to: current[key] });
    }
  }
  return changes;
}

function normDiffVal(v) {
  return v == null || v === "" ? null : v;
}

function formatRevVal(v) {
  if (v == null || v === "") return t("contentEdit.empty");
  if (typeof v === "boolean") return v ? "true" : "false";
  if (Array.isArray(v))
    return `[${t("contentEdit.items", { count: v.length })}]`;
  if (typeof v === "object") return "{…}";
  const s = String(v);
  return s.length > 35 ? s.slice(0, 35) + "…" : s;
}

async function handleDelete() {
  if (!canDeleteEntry.value) return;
  try {
    deleting.value = true;
    await api.content.delete(collection, entryId.value);
    toast.success(
      isSingleton.value
        ? t("contentEdit.pageMovedTrash")
        : t("contentEdit.entryMovedTrash"),
    );
    router.push(isSingleton.value ? "/dashboard" : `/content/${collection}`);
  } catch (err) {
    saveError.value = err.message;
    showDeleteModal.value = false;
  } finally {
    deleting.value = false;
  }
}

function toDatetimeLocal(iso) {
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function localDatetimeToUtcIso(value) {
  if (!value) return value ?? "";
  const date = new Date(String(value));
  if (Number.isNaN(date.getTime())) return value;
  return date.toISOString().replace(/\.\d{3}Z$/, "Z");
}

function formatDateTime(iso) {
  if (!iso) return "—";
  return new Date(iso).toLocaleString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}
</script>
