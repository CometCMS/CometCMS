<template>
  <section class="card overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <div class="flex flex-wrap items-center gap-2">
          <h2 class="text-base font-semibold text-slate-900">
            API Query Builder
          </h2>
        </div>
        <p class="mt-1 text-sm text-slate-500">
          Build a ready-to-use public API URL from your content model.
        </p>
      </div>
      <button type="button" class="btn-secondary shrink-0 px-3 py-1.5 text-sm" @click="resetBuilder">
        <Icon icon="mdi:refresh" class="h-4 w-4" />
        Reset
      </button>
    </div>

    <div class="border-t border-slate-100 bg-slate-50/60 p-5">
      <div class="mb-2 flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-slate-900">Your API endpoint</h3>
        <button type="button" class="btn-secondary px-3 py-1.5 text-sm" @click="copy(endpointUrl)">
          <Icon icon="mdi:content-copy" class="h-4 w-4" />
          Copy URL
        </button>
      </div>

      <div class="flex min-h-12 items-center gap-3 rounded-lg bg-slate-950 px-3 py-2 text-sm text-slate-100">
        <span class="shrink-0 font-mono text-xs font-semibold text-theme-300">GET</span>
        <code
          class="min-w-0 flex-1 overflow-x-auto whitespace-nowrap font-mono text-xs leading-6">{{ endpointUrl }}</code>
      </div>

      <div class="mt-3 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
          <span>Method:
            <span
              class="rounded-full bg-white px-2 py-0.5 font-semibold text-slate-700 ring-1 ring-slate-200">GET</span></span>
          <span>Format:
            <span
              class="rounded-full bg-white px-2 py-0.5 font-semibold text-slate-700 ring-1 ring-slate-200">JSON</span></span>
          <span v-if="authMode === 'bearer'">Auth:
            <span
              class="rounded-full bg-white px-2 py-0.5 font-semibold text-slate-700 ring-1 ring-slate-200">Bearer</span></span>
        </div>

        <button type="button"
          class="inline-flex items-center gap-1.5 text-sm font-medium text-theme-700 hover:text-theme-800"
          @click="copy(curlCommand)">
          <Icon icon="mdi:console-line" class="h-4 w-4" />
          Copy curl
        </button>
      </div>
    </div>

    <div>
      <div class="grid gap-0 lg:grid-cols-3">
        <div class="border-b border-slate-100 p-5 lg:border-r">
          <div class="mb-4 flex items-start gap-3">
            <span
              class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-theme-600 text-xs font-bold text-white">1</span>
            <div>
              <h3 class="text-sm font-semibold text-slate-900">
                What do you want to fetch?
              </h3>
              <p class="text-sm text-slate-500">
                Choose a public API resource.
              </p>
            </div>
          </div>

          <div class="grid gap-3">
            <div v-for="resource in resources" :key="resource.value" class="space-y-2">
              <button type="button"
                class="flex w-full items-center justify-between gap-3 rounded-lg border p-3 text-left transition"
                :class="selectedResource === resource.value
                    ? 'border-theme-500 bg-theme-50/60 ring-1 ring-theme-500'
                    : 'border-slate-200 bg-white hover:border-theme-200 hover:bg-slate-50'
                  " @click="selectResource(resource.value)">
                <span class="flex min-w-0 items-start gap-3">
                  <Icon :icon="resource.icon" class="mt-0.5 h-5 w-5 shrink-0" :class="selectedResource === resource.value
                      ? 'text-theme-600'
                      : 'text-slate-500'
                    " />
                  <span>
                    <span class="block text-sm font-semibold" :class="selectedResource === resource.value
                        ? 'text-theme-800'
                        : 'text-slate-800'
                      ">{{ resource.label }}</span>
                    <span class="mt-0.5 block text-sm text-slate-500">{{
                      resource.description
                      }}</span>
                    <span v-if="
                      resource.value === 'content' && selectedCollectionLabel
                    " class="mt-1 block text-xs font-semibold text-theme-700">
                      Selected: {{ selectedCollectionLabel }}
                    </span>
                  </span>
                </span>
                <Icon v-if="resource.value === 'content'" :icon="isCollectionsExpanded && selectedResource === 'content'
                    ? 'mdi:chevron-up'
                    : 'mdi:chevron-down'
                  " class="mt-0.5 h-5 w-5 shrink-0" :class="selectedResource === resource.value
                      ? 'text-theme-600'
                      : 'text-slate-500'
                    " />
              </button>

              <div v-if="
                resource.value === 'content' &&
                selectedResource === 'content' &&
                isCollectionsExpanded
              " class="rounded-lg border border-slate-200 bg-white p-2">
                <p v-if="collectionTypes.length > 0"
                  class="px-2 pb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Collections
                </p>
                <p v-if="collections.length === 0" class="px-2 pb-1 text-sm text-slate-500">
                  Create a content type first
                </p>
                <template v-else>
                  <div v-if="collectionTypes.length > 0" class="flex flex-wrap gap-2">
                    <button v-for="collection in collectionTypes" :key="collection.name" type="button"
                      class="rounded-md border px-3 py-1.5 text-sm transition"
                      :class="collectionButtonClass(collection)" @click="selectCollection(collection.name)">
                      {{ collection.label || collection.name }}
                    </button>
                  </div>

                  <template v-if="singletonTypes.length > 0">
                    <p class="px-2 pb-2 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                      Single
                    </p>
                    <div class="flex flex-wrap gap-2">
                      <button v-for="collection in singletonTypes" :key="collection.name" type="button"
                        class="rounded-md border px-3 py-1.5 text-sm transition"
                        :class="collectionButtonClass(collection)" @click="selectCollection(collection.name)">
                        {{ collection.label || collection.name }}
                      </button>
                    </div>
                  </template>
                </template>
              </div>
            </div>
          </div>
        </div>

        <div class="border-b border-slate-100 p-5 lg:col-span-2">
          <div class="mb-4 flex items-start gap-3">
            <span
              class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-theme-600 text-xs font-bold text-white">2</span>
            <div>
              <h3 class="text-sm font-semibold text-slate-900">
                Configure parameters
              </h3>
              <p class="text-sm text-slate-500">
                Add pagination, search, filters, or a single item.
              </p>
            </div>
          </div>

          <div v-if="selectedResource === 'content'" class="space-y-4">
            <div>
              <label class="form-label">Fetch mode</label>
              <div class="grid grid-cols-2 rounded-lg border border-slate-200 bg-slate-50 p-1">
                <button type="button" class="rounded-md px-3 py-2 text-sm font-medium transition" :class="contentMode === 'list'
                    ? 'bg-white text-slate-900 shadow-sm'
                    : isActiveSingleton
                      ? 'text-slate-300'
                      : 'text-slate-500 hover:text-slate-800'
                  " :disabled="isActiveSingleton" @click="contentMode = 'list'">
                  List
                </button>
                <button type="button" class="rounded-md px-3 py-2 text-sm font-medium transition" :class="contentMode === 'single'
                    ? 'bg-white text-slate-900 shadow-sm'
                    : 'text-slate-500 hover:text-slate-800'
                  " @click="contentMode = 'single'">
                  Single
                </button>
              </div>
            </div>

            <div v-if="isActiveSingleton">
              <label class="form-label">Fixed slug</label>
              <input :value="collectionName" type="text" disabled
                class="form-input w-full rounded-lg border-slate-300 bg-slate-50 text-sm text-slate-500" />
            </div>

            <div v-else-if="contentMode === 'single'">
              <label class="form-label">Slug or stable ID</label>
              <SearchableSelect v-model="identifier" :options="entryOptions" :loading="entriesLoading"
                :allow-free-input="true" :clearable="false" placeholder="my-entry-slug" @open="loadEntriesIfNeeded"
                @search="onEntrySearch" />
              <p v-if="entriesError" class="mt-1 text-xs text-red-600">
                {{ entriesError }}
              </p>
            </div>

            <template v-if="contentMode === 'list'">
              <div class="grid gap-3 sm:grid-cols-2">
                <div>
                  <label class="form-label">Limit</label>
                  <input v-model.trim="limit" type="number" min="1" placeholder="20"
                    class="form-input w-full rounded-lg border-slate-300 text-sm" />
                </div>
                <div>
                  <label class="form-label">Offset</label>
                  <input v-model.trim="offset" type="number" min="0" placeholder="0"
                    class="form-input w-full rounded-lg border-slate-300 text-sm" />
                </div>
              </div>

              <div class="grid gap-3 sm:grid-cols-2">
                <div>
                  <label class="form-label">Search</label>
                  <input v-model.trim="search" type="text" placeholder="home page"
                    class="form-input w-full rounded-lg border-slate-300 text-sm" />
                </div>
                <div>
                  <label class="form-label">Sort</label>
                  <select v-model="sort" class="form-select w-full rounded-lg border-slate-300 text-sm">
                    <option value="-created_at">Newest first</option>
                    <option value="created_at">Oldest first</option>
                    <option value="title">Title A-Z</option>
                    <option value="-updated_at">Recently updated</option>
                    <option value="-published_at">Recently published</option>
                  </select>
                </div>
              </div>

              <div class="grid gap-3 sm:grid-cols-2">
                <div>
                  <label class="form-label">Filter field</label>
                  <select v-model="filterField" class="form-select w-full rounded-lg border-slate-300 text-sm">
                    <option value="">No filter</option>
                    <optgroup label="System fields">
                      <option v-for="field in systemFilterFields" :key="field.key" :value="field.key">
                        {{ field.label }}
                      </option>
                    </optgroup>
                    <optgroup v-if="contentFilterFields.length > 0" label="Content fields">
                      <option v-for="field in contentFilterFields" :key="field.key" :value="field.key">
                        {{ field.label }}
                      </option>
                    </optgroup>
                  </select>
                </div>
                <div>
                  <label class="form-label">Operator</label>
                  <select v-model="filterOperator" class="form-select w-full rounded-lg border-slate-300 text-sm"
                    :disabled="!filterField">
                    <option v-for="operator in filterOperators" :key="operator.value" :value="operator.value">
                      {{ operator.label }}
                    </option>
                  </select>
                </div>
                <div class="sm:col-span-2">
                  <label class="form-label">Value</label>
                  <FieldValueInput v-if="selectedFilterField" v-model="filterValue" :field="selectedFilterValueField"
                    :multiple="filterUsesMultiple" input-class="w-full" :placeholder="filterPlaceholder" />
                  <input v-else type="text" disabled placeholder="Choose a field first"
                    class="form-input w-full rounded-lg border-slate-300 text-sm" />
                </div>
              </div>
            </template>

            <div v-if="relationFields.length > 0" class="grid gap-3 sm:grid-cols-2">
              <div>
                <label class="form-label">Include relations</label>
                <select v-model="include" class="form-select w-full rounded-lg border-slate-300 text-sm">
                  <option value="">None</option>
                  <option v-for="field in relationFields" :key="field" :value="field">
                    {{ field }}
                  </option>
                  <option value="__custom">Custom comma-separated list</option>
                </select>
              </div>
              <div>
                <label class="form-label">Custom include</label>
                <input v-model.trim="customInclude" type="text" placeholder="author,categories"
                  class="form-input w-full rounded-lg border-slate-300 text-sm" :disabled="include !== '__custom'" />
              </div>
            </div>

            <div v-if="collectionLocales.length > 0">
              <label class="form-label">Locale</label>
              <select v-model="locale" class="form-select w-full rounded-lg border-slate-300 text-sm">
                <option value="">Default (no locale param)</option>
                <option v-for="loc in collectionLocales" :key="loc" :value="loc">
                  {{ loc }}
                </option>
              </select>
            </div>
          </div>

          <div v-else-if="selectedResource === 'content-types'" class="space-y-4">
            <div>
              <label class="form-label">Schema</label>
              <select v-model="typeMode" class="form-select w-full rounded-lg border-slate-300 text-sm">
                <option value="all">All content types</option>
                <option value="single">One content type</option>
              </select>
            </div>

            <div v-if="typeMode === 'single'">
              <label class="form-label">Content type</label>
              <select v-model="selectedCollection" class="form-select w-full rounded-lg border-slate-300 text-sm"
                :disabled="collections.length === 0">
                <option v-if="collections.length === 0" value="">
                  Create a content type first
                </option>
                <option v-for="collection in collections" :key="collection.name" :value="collection.name">
                  {{ collection.label || collection.name
                  }}{{ collection.singleton ? " (Single)" : "" }}
                </option>
              </select>
            </div>
          </div>

          <div v-else-if="selectedResource === 'media'" class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2">
              <div>
                <label class="form-label">Limit</label>
                <input v-model.trim="limit" type="number" min="1" placeholder="20"
                  class="form-input w-full rounded-lg border-slate-300 text-sm" />
              </div>
              <div>
                <label class="form-label">Offset</label>
                <input v-model.trim="offset" type="number" min="0" placeholder="0"
                  class="form-input w-full rounded-lg border-slate-300 text-sm" />
              </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
              <div>
                <label class="form-label">Search filename</label>
                <input v-model.trim="search" type="text" placeholder="hero"
                  class="form-input w-full rounded-lg border-slate-300 text-sm" />
              </div>
              <div>
                <label class="form-label">Category</label>
                <select v-model="mediaCategory" class="form-select w-full rounded-lg border-slate-300 text-sm"
                  :disabled="mediaCategoriesLoading">
                  <option value="">{{ mediaCategoryDefaultLabel }}</option>
                  <option v-for="category in mediaCategoryOptions" :key="category.path" :value="category.path">
                    {{ category.optionLabel }}
                  </option>
                </select>
                <p v-if="mediaCategoriesError" class="mt-1 text-xs text-red-600">
                  {{ mediaCategoriesError }}
                </p>
              </div>
            </div>
          </div>

          <div v-else class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
            Select a resource in step 1 to configure parameters.
          </div>
        </div>

        <div class="border-b border-slate-100 p-5 lg:col-span-3">
          <div class="mb-4 flex items-start gap-3">
            <span
              class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-theme-600 text-xs font-bold text-white">3</span>
            <div>
              <h3 class="text-sm font-semibold text-slate-900">
                Authorization
              </h3>
              <p class="text-sm text-slate-500">
                Add a bearer token when private content should be included.
              </p>
            </div>
          </div>

          <div class="grid gap-4 lg:grid-cols-3">
            <div>
              <label class="form-label">Header</label>
              <select v-model="authMode" class="form-select w-full rounded-lg border-slate-300 text-sm">
                <option value="none">No authorization</option>
                <option value="bearer">Bearer token</option>
              </select>
            </div>

            <div v-if="authMode === 'bearer'">
              <label class="form-label">Token</label>
              <div class="relative">
                <input v-model.trim="token" :type="showToken ? 'text' : 'password'" placeholder="ctcms_..."
                  class="form-input w-full rounded-lg border-slate-300 pr-10 text-sm" />
                <button type="button"
                  class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 hover:text-slate-700"
                  @click="showToken = !showToken">
                  <Icon :icon="showToken ? 'mdi:eye-off-outline' : 'mdi:eye-outline'
                    " class="h-4 w-4" />
                </button>
              </div>
            </div>

            <div class="rounded-lg border p-3 text-sm" :class="authMode === 'bearer'
                ? 'border-green-200 bg-green-50 text-green-800'
                : 'border-slate-200 bg-slate-50 text-slate-600'
              ">
              <div class="flex gap-2">
                <Icon :icon="authMode === 'bearer'
                    ? 'mdi:check'
                    : 'mdi:information-outline'
                  " class="mt-0.5 h-4 w-4 shrink-0" />
                <p>{{ authStatus }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import { Icon } from "@iconify/vue";
import { api } from "../api/index.js";
import { useToastStore } from "../stores/toast.js";
import FieldValueInput from "./FieldValueInput.vue";
import SearchableSelect from "./SearchableSelect.vue";

const props = defineProps({
  apiBase: {
    type: String,
    required: true,
  },
  collections: {
    type: Array,
    default: () => [],
  },
});

const toast = useToastStore();

const resources = [
  {
    value: "content",
    label: "Content",
    description: "List entries or fetch one item",
    icon: "mdi:folder-outline",
  },
  {
    value: "content-types",
    label: "Content Types",
    description: "Read schemas for your frontend",
    icon: "mdi:cube-outline",
  },
  {
    value: "media",
    label: "Media",
    description: "List uploaded files",
    icon: "mdi:image-multiple-outline",
  },
];

const selectedResource = ref("");
const selectedCollection = ref("");
const isCollectionsExpanded = ref(false);
const contentMode = ref("list");
const typeMode = ref("all");
const identifier = ref("");
const limit = ref("20");
const offset = ref("0");
const search = ref("");
const sort = ref("-created_at");
const filterField = ref("");
const filterOperator = ref("eq");
const filterValue = ref("");
const include = ref("");
const customInclude = ref("");
const locale = ref("");
const mediaCategory = ref("");
const mediaCategories = ref([]);
const mediaCategoriesLoading = ref(false);
const mediaCategoriesLoaded = ref(false);
const mediaCategoriesError = ref("");
const authMode = ref("none");
const token = ref("");
const showToken = ref(false);

const entries = ref([]);
const entriesLoading = ref(false);
const entriesError = ref("");
let entriesLoaded = false;

const activeCollection = computed(
  () =>
    props.collections.find(
      (collection) => collection.name === selectedCollection.value,
    ) ?? null,
);
const collectionTypes = computed(() =>
  props.collections.filter((collection) => !collection.singleton),
);
const singletonTypes = computed(() =>
  props.collections.filter((collection) => collection.singleton),
);
const isActiveSingleton = computed(() => !!activeCollection.value?.singleton);
const collectionLocales = computed(() =>
  Array.isArray(activeCollection.value?.locales)
    ? activeCollection.value.locales
    : [],
);
const collectionName = computed(() => selectedCollection.value || "");
const selectedCollectionLabel = computed(() => {
  const label =
    activeCollection.value?.label || activeCollection.value?.name || "";
  return activeCollection.value?.singleton && label
    ? `${label} (Single)`
    : label;
});
const relationFields = computed(() => {
  const fields = activeCollection.value?.fields ?? {};
  return Object.entries(fields)
    .filter(([, config]) => config?.type === "relation")
    .map(([name]) => name);
});
const systemFilterFields = computed(() => [
  { key: "id", label: "ID / stable ID", kind: "text" },
  { key: "slug", label: "Slug", kind: "text" },
  { key: "status", label: "Status", kind: "status" },
  { key: "title", label: "Title", kind: "text" },
  { key: "published_at", label: "Published at", kind: "datetime" },
  { key: "created_at", label: "Created at", kind: "datetime" },
  { key: "updated_at", label: "Updated at", kind: "datetime" },
  { key: "author_id", label: "Author ID", kind: "text" },
  { key: "updated_by", label: "Updated by", kind: "text" },
]);
const contentFilterFields = computed(() => {
  const fields = activeCollection.value?.fields ?? {};
  return Object.entries(fields).map(([key, config]) => ({
    key,
    label: fieldLabel(key, config),
    kind: fieldKind(config?.type),
    config: config ?? {},
  }));
});
const filterFields = computed(() => [
  ...systemFilterFields.value,
  ...contentFilterFields.value,
]);
const selectedFilterField = computed(
  () =>
    filterFields.value.find((field) => field.key === filterField.value) ?? null,
);
const filterOperators = computed(() =>
  operatorsForField(selectedFilterField.value),
);
const filterUsesMultiple = computed(() => filterOperator.value === "in");
const selectedFilterValueField = computed(() => {
  if (!selectedFilterField.value) return null;

  return {
    ...selectedFilterField.value,
    config: {
      ...(selectedFilterField.value.config ?? {}),
      multiple: filterUsesMultiple.value,
    },
  };
});
const filterPlaceholder = computed(() =>
  filterOperator.value === "in" ? "value-1,value-2" : "Value",
);
const entryOptions = computed(() =>
  entries.value.map((e) => ({
    value: e.slug || e.id,
    label: e.title ? `${e.title} (${e.slug || e.id})` : e.slug || e.id,
  })),
);

const mediaCategoryDefaultLabel = computed(() => {
  if (mediaCategoriesLoading.value) return "Loading categories...";
  if (mediaCategoryOptions.value.length === 0) return "All categories";
  return "All categories";
});
const mediaCategoryOptions = computed(() =>
  mediaCategories.value.map((category) => {
    const parts = categoryParts(category);
    const label = parts[parts.length - 1] ?? category;
    const depth = Math.max(0, parts.length - 1);
    return { path: category, optionLabel: `${"  ".repeat(depth)}${label}` };
  }),
);

const endpointPath = computed(() => {
  if (!selectedResource.value) {
    return "/{resource}";
  }

  if (selectedResource.value === "content-types") {
    return typeMode.value === "single" && collectionName.value
      ? `/content-types/${encodeURIComponent(collectionName.value)}`
      : "/content-types";
  }

  if (selectedResource.value === "media") {
    return "/media";
  }

  const collection = collectionName.value || "{collection}";
  const base = `/content/${encodeURIComponent(collection)}`;
  const item = identifier.value.trim();

  if (isActiveSingleton.value) {
    return base;
  }

  return contentMode.value === "single" && item !== ""
    ? `${base}/${encodeURIComponent(item)}`
    : base;
});

const queryItems = computed(() => {
  const items = [];

  if (selectedResource.value === "content") {
    const includeValue =
      include.value === "__custom" ? customInclude.value : include.value;

    if (contentMode.value === "list") {
      addQuery(items, "limit", limit.value);
      addQuery(items, "offset", offset.value);
      addQuery(items, "sort", sort.value);
      addQuery(items, "q", search.value);

      if (filterField.value && hasFilterValue(filterValue.value)) {
        const key =
          filterOperator.value === "eq"
            ? `filter[${filterField.value}]`
            : `filter[${filterField.value}][${filterOperator.value}]`;
        addQuery(items, key, filterValue.value);
      }
    }

    addQuery(items, "include", includeValue);
    addQuery(items, "locale", locale.value);
  }

  if (selectedResource.value === "media") {
    addQuery(items, "limit", limit.value);
    addQuery(items, "offset", offset.value);
    addQuery(items, "q", search.value);
    addQuery(items, "category", mediaCategory.value);
  }

  return items;
});

const queryString = computed(() =>
  queryItems.value
    .map(
      ([key, value]) => `${encodeQueryKey(key)}=${encodeURIComponent(value)}`,
    )
    .join("&"),
);
const endpointUrl = computed(
  () =>
    `${props.apiBase}${endpointPath.value}${queryString.value ? `?${queryString.value}` : ""}`,
);
const authHeader = computed(
  () => `Authorization: Bearer ${token.value || "YOUR_TOKEN_HERE"}`,
);
const authStatus = computed(() => {
  if (authMode.value === "bearer") {
    return "Bearer header will be included. Use a token with content:read to include drafts and protected entries.";
  }

  return "Public reads work without authorization and return public content only.";
});
const curlCommand = computed(() => {
  if (authMode.value !== "bearer") {
    return `curl "${endpointUrl.value}"`;
  }

  return `curl -H "${authHeader.value}" \\\n  "${endpointUrl.value}"`;
});

watch(
  () => props.collections,
  (collections) => {
    if (
      selectedCollection.value &&
      !collections.some(
        (collection) => collection.name === selectedCollection.value,
      )
    ) {
      selectedCollection.value = "";
    }
  },
  { immediate: true },
);

watch(selectedCollection, () => {
  if (isActiveSingleton.value) {
    contentMode.value = "single";
    identifier.value = collectionName.value;
  } else if (identifier.value === selectedCollection.value) {
    identifier.value = "";
  }

  if (
    !relationFields.value.includes(include.value) &&
    include.value !== "__custom"
  ) {
    include.value = "";
  }

  if (!filterFields.value.some((field) => field.key === filterField.value)) {
    filterField.value = "";
  }

  if (!collectionLocales.value.includes(locale.value)) {
    locale.value = "";
  }

  entries.value = [];
  entriesError.value = "";
  entriesLoaded = false;
});

watch(filterField, () => {
  filterValue.value = "";
  filterOperator.value = filterOperators.value[0]?.value ?? "eq";
});

watch(filterOperators, (operators) => {
  if (!operators.some((operator) => operator.value === filterOperator.value)) {
    filterOperator.value = operators[0]?.value ?? "eq";
  }
});

watch(filterOperator, () => {
  if (Array.isArray(filterValue.value) && !filterUsesMultiple.value) {
    filterValue.value = filterValue.value[0] ?? "";
  }
});

watch(mediaCategories, (categories) => {
  if (mediaCategory.value && !categories.includes(mediaCategory.value)) {
    mediaCategory.value = "";
  }
});

async function loadEntries() {
  if (!selectedCollection.value) return;
  if (isActiveSingleton.value) {
    entries.value = [
      {
        id: collectionName.value,
        slug: collectionName.value,
        title: selectedCollectionLabel.value,
      },
    ];
    entriesLoaded = true;
    return;
  }

  entriesLoading.value = true;
  entriesError.value = "";

  try {
    const res = await api.content.list(selectedCollection.value, {
      limit: 200,
      sort: "title",
    });
    entries.value = (res.data ?? []).map((e) => ({
      id: e.id,
      slug: e.slug ?? "",
      title: e.title ?? "",
    }));
    entriesLoaded = true;
  } catch (err) {
    entriesError.value = err.message ?? "Could not load entries.";
  } finally {
    entriesLoading.value = false;
  }
}

function loadEntriesIfNeeded() {
  if (!entriesLoaded && !entriesLoading.value) loadEntries();
}

function onEntrySearch() {
  if (!entriesLoaded && !entriesLoading.value) loadEntries();
}

function selectResource(resource) {
  const changed = selectedResource.value !== resource;
  const wasExpanded = isCollectionsExpanded.value;

  selectedResource.value = resource;

  if (resource === "content") {
    isCollectionsExpanded.value = changed ? true : !wasExpanded;
  } else {
    isCollectionsExpanded.value = false;
  }

  if (!changed) {
    if (resource === "media") {
      loadMediaCategories();
    }
    return;
  }

  search.value = "";
  filterField.value = "";
  filterValue.value = "";
  filterOperator.value = "eq";
  mediaCategory.value = "";

  if (resource === "media") {
    loadMediaCategories();
  }
}

function selectCollection(collectionName) {
  selectedResource.value = "content";
  selectedCollection.value = collectionName;
  isCollectionsExpanded.value = true;
}

function collectionButtonClass(collection) {
  return selectedCollection.value === collection.name
    ? "border-theme-300 bg-theme-50 text-theme-800 ring-1 ring-theme-200"
    : collection.singleton
      ? "border-violet-200 text-violet-700 hover:bg-violet-50"
      : "border-slate-200 text-slate-700 hover:bg-slate-50";
}

function resetBuilder() {
  selectedResource.value = "";
  selectedCollection.value = "";
  isCollectionsExpanded.value = false;
  contentMode.value = "list";
  typeMode.value = "all";
  identifier.value = "";
  limit.value = "20";
  offset.value = "0";
  search.value = "";
  sort.value = "-created_at";
  filterField.value = "";
  filterOperator.value = "eq";
  filterValue.value = "";
  include.value = "";
  customInclude.value = "";
  locale.value = "";
  mediaCategory.value = "";
  authMode.value = "none";
  token.value = "";
  showToken.value = false;
  entries.value = [];
  entriesError.value = "";
  entriesLoaded = false;
}

async function loadMediaCategories() {
  if (mediaCategoriesLoaded.value || mediaCategoriesLoading.value) return;

  mediaCategoriesLoading.value = true;
  mediaCategoriesError.value = "";

  try {
    const res = await api.media.list({ limit: 1 });
    mediaCategories.value = res.meta?.categories ?? res.categories ?? [];
    mediaCategoriesLoaded.value = true;
  } catch (err) {
    mediaCategoriesError.value =
      err.message ?? "Could not load media categories.";
  } finally {
    mediaCategoriesLoading.value = false;
  }
}

function addQuery(items, key, value) {
  const normalized = normalizeQueryValue(value);
  if (normalized !== "") {
    items.push([key, normalized]);
  }
}

function normalizeQueryValue(value) {
  if (Array.isArray(value)) {
    return value
      .map((item) => String(item ?? "").trim())
      .filter(Boolean)
      .join(",");
  }

  return String(value ?? "").trim();
}

function hasFilterValue(value) {
  return normalizeQueryValue(value) !== "";
}

function categoryParts(category) {
  return String(category)
    .split("/")
    .map((part) => part.trim())
    .filter(Boolean);
}

function fieldLabel(key, config) {
  if (config?.label) return String(config.label);
  return key
    .replace(/[_-]+/g, " ")
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function fieldKind(type) {
  return [
    "text",
    "slug",
    "textarea",
    "number",
    "range",
    "boolean",
    "date",
    "datetime",
    "select",
  ].includes(type)
    ? type
    : "text";
}

function operatorsForField(field) {
  if (!field) return [{ value: "eq", label: "=" }];

  if (["number", "range", "date", "datetime"].includes(field.kind)) {
    return [
      { value: "eq", label: "=" },
      { value: "ne", label: "!=" },
      { value: "gt", label: ">" },
      { value: "gte", label: ">=" },
      { value: "lt", label: "<" },
      { value: "lte", label: "<=" },
      { value: "in", label: "in" },
    ];
  }

  if (field.kind === "boolean") {
    return [
      { value: "eq", label: "=" },
      { value: "ne", label: "!=" },
    ];
  }

  if (field.kind === "select" || field.config?.multiple) {
    return [
      { value: "eq", label: "=" },
      { value: "ne", label: "!=" },
      { value: "in", label: "in" },
      { value: "contains", label: "contains" },
    ];
  }

  return [
    { value: "eq", label: "=" },
    { value: "ne", label: "!=" },
    { value: "contains", label: "contains" },
    { value: "in", label: "in" },
  ];
}

function encodeQueryKey(key) {
  return encodeURIComponent(key).replace(/%5B/g, "[").replace(/%5D/g, "]");
}

async function copy(value) {
  try {
    await navigator.clipboard.writeText(value);
    toast.success("Copied to clipboard.");
  } catch {
    toast.error("Could not copy to clipboard.");
  }
}
</script>
