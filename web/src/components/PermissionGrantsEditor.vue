<template>
  <div class="rounded-lg border border-slate-200 bg-white p-4 space-y-4">
    <div>
      <label class="text-xs font-medium text-slate-500 block mb-1">{{ t("perm.areaLabel") }}</label>
      <TabNavigation
        :items="areaOptions"
        :model-value="selectedArea"
        :aria-label="t('perm.areaAria')"
        variant="segmented"
        @select="selectAreaTab"
      />
    </div>

    <div
      v-if="selectedArea !== 'all'"
      class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50/70 p-3 md:grid-cols-3"
    >

      <div v-if="selectedArea === 'content' || selectedArea === 'schema'">
        <label class="text-xs text-slate-500 block mb-0.5">{{ t("perm.contentType") }}</label>
        <select
          v-model="selectedCollection"
          class="form-select w-full rounded-lg border-slate-300 text-xs"
          @change="onCollectionChange"
        >
          <option value="*">{{ t("perm.allTypes") }}</option>
          <option
            v-for="type in contentTypes.list"
            :key="type.name"
            :value="type.name"
          >
            {{ type.label || type.name }}
          </option>
        </select>
      </div>

      <div v-if="selectedArea === 'content'">
        <label class="text-xs text-slate-500 block mb-0.5">{{ t("perm.entry") }}</label>
        <SearchableSelect
          :model-value="selectedEntry"
          :options="entryOptions"
          :loading="entriesLoading"
          :disabled="selectedCollection === '*'"
          :allow-free-input="true"
          :clearable="false"
          :placeholder="t('perm.allEntries')"
          @update:model-value="onEntryChange"
          @open="loadEntriesIfNeeded"
          @search="loadEntriesIfNeeded"
        />
        <p v-if="entriesError" class="mt-1 text-xs text-red-600">
          {{ entriesError }}
        </p>
      </div>

      <div v-if="selectedArea === 'media'">
        <label class="text-xs text-slate-500 block mb-0.5">{{ t("perm.mediaCategory") }}</label>
        <input
          v-model.trim="selectedMediaCategory"
          type="text"
          :placeholder="t('perm.allMedia')"
          class="form-input w-full rounded-lg border-slate-300 text-xs"
          @input="emitChange"
        />
      </div>

      <div v-if="selectedArea === 'system'">
        <label class="text-xs text-slate-500 block mb-0.5">{{ t("perm.systemSection") }}</label>
        <select
          v-model="selectedSystemResource"
          class="form-select w-full rounded-lg border-slate-300 text-xs"
          @change="emitChange"
        >
          <option value="*">{{ t("perm.allSystem") }}</option>
          <option value="dashboard:*">{{ t("perm.action.dashboard.read") }}</option>
          <option value="activity:*">{{ t("perm.action.activity.read") }}</option>
          <option value="backups:*">{{ t("perm.section.backups") }}</option>
          <option value="webhooks:*">{{ t("perm.action.webhooks.manage") }}</option>
          <option value="workspaces:*">{{ t("perm.allWorkspaces") }}</option>
          <option
            v-for="ws in workspaces"
            :key="ws.slug"
            :value="`workspaces:${ws.slug}`"
          >
            {{ ws.label || ws.slug }}
          </option>
          <option value="updates:*">{{ t("perm.section.updates") }}</option>
        </select>
      </div>
    </div>

    <!-- Bulk area grants when "Everything" is selected -->
    <div v-if="selectedArea === 'all'">
      <label class="text-xs text-slate-500 block mb-1">{{ t("perm.grantAllForArea") }}</label>
      <div class="flex flex-wrap gap-2">
        <label
          v-for="group in bulkAreaGroups"
          :key="group.area"
          class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs cursor-pointer select-none transition-colors"
          :class="
            areaGrantState(group.area) !== 'none'
              ? 'border-theme-300 bg-theme-50 text-theme-700'
              : 'border-slate-200 bg-slate-50 text-slate-600'
          "
        >
          <input
            type="checkbox"
            :checked="areaGrantState(group.area) !== 'none'"
            v-indeterminate="areaGrantState(group.area) === 'some'"
            @change="toggleAreaAllGrants(group.area)"
            class="rounded border-slate-300 text-theme-600 h-3 w-3"
          />
          {{ group.label }}
        </label>
      </div>
    </div>

    <!-- Per-action checkboxes for all other areas -->
    <div v-else>
      <div class="mb-1 flex items-center justify-between gap-3">
        <label class="text-xs font-medium text-slate-500">{{ t("perm.actions") }}</label>
        <button
          v-if="currentVisibleActions.length > 1"
          type="button"
          class="text-xs font-medium text-theme-700 hover:text-theme-800"
          @click="toggleAllCurrentActions"
        >
          {{ allVisibleActionsSelected ? t("perm.clearAll") : t("perm.selectAll") }}
        </button>
      </div>
      <div class="flex flex-wrap gap-2">
        <label
          v-for="action in currentVisibleActions"
          :key="action.value"
          class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs cursor-pointer select-none transition-colors"
          :class="
            currentActions.includes(action.value)
              ? 'border-theme-300 bg-theme-50 text-theme-700'
              : 'border-slate-200 bg-slate-50 text-slate-600'
          "
        >
          <input
            v-model="currentActions"
            type="checkbox"
            :value="action.value"
            class="rounded border-slate-300 text-theme-600 h-3 w-3"
            @change="setCurrentActions"
          />
          {{ action.label }}
        </label>
      </div>
      <p v-if="selectedArea === 'site'" class="mt-2 text-xs text-slate-500">{{ t("perm.siteHint") }}</p>
      <p
        v-if="hiddenActionLabels.length > 0"
        class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800"
      >
        {{ t("perm.hiddenActions", { actions: hiddenActionLabels.join(", ") }) }}
      </p>
    </div>

    <div v-if="selectedArea === 'content'" class="grid gap-3 md:grid-cols-3">
      <div class="md:col-span-2">
        <label class="text-xs text-slate-500 block mb-0.5">{{ t("perm.fields") }}</label>
        <div
          v-if="fieldsForCurrentContentType.length > 0"
          class="flex flex-wrap gap-2"
        >
          <label
            v-for="field in fieldsForCurrentContentType"
            :key="field"
            class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700"
          >
            <input
              v-model="currentFields"
              type="checkbox"
              :value="field"
              class="rounded border-slate-300 text-theme-600"
              @change="setCurrentFields"
            />
            {{ field }}
          </label>
        </div>
        <p v-else class="text-xs text-slate-400">
          {{ t("perm.fieldsHint") }}
        </p>
      </div>

      <label class="inline-flex items-center gap-2 text-xs text-slate-700 pt-5">
        <input
          v-model="currentOwnOnly"
          type="checkbox"
          class="rounded border-slate-300 text-theme-600"
          @change="setCurrentOwnOnly"
        />
        {{ t("perm.ownOnly") }}
      </label>
    </div>

    <div v-if="['content', 'schema', 'media'].includes(selectedArea)">
      <label class="text-xs text-slate-500 block mb-0.5">{{ t("perm.workspace") }}</label>
      <select
        v-model="selectedWorkspace"
        class="form-select w-full rounded-lg border-slate-300 text-xs"
        @change="emitChange"
      >
        <option value="">{{ t("perm.allWorkspaces") }}</option>
        <option v-for="ws in workspaces" :key="ws.slug" :value="ws.slug">
          {{ ws.label }} ({{ ws.slug }})
        </option>
      </select>
    </div>

    <p v-if="selectedArea === 'content'" class="text-xs text-slate-400">
      {{ t("perm.entryHint") }}
    </p>

    <p class="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-500">
      {{ summary }}
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";

const vIndeterminate = {
  mounted(el, binding) {
    el.indeterminate = !!binding.value;
  },
  updated(el, binding) {
    el.indeterminate = !!binding.value;
  },
};
import { api } from "../api/index.js";
import { useContentTypesStore } from "../stores/contentTypes.js";
import SearchableSelect from "./SearchableSelect.vue";
import TabNavigation from "./TabNavigation.vue";
import { useI18n } from "../i18n/index.js";
import {
  PERMISSION_AREAS,
  permissionActionLabel,
  permissionActionOptions,
  permissionAreaLabel,
} from "../composables/permissionLabels.js";

const { t } = useI18n();

const props = defineProps({
  modelValue: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(["update:modelValue"]);
const contentTypes = useContentTypesStore();
const selectedArea = ref("all");
const selectedCollection = ref("*");
const selectedEntry = ref("");
const selectedMediaCategory = ref("");
const selectedSystemResource = ref("*");
const selectedWorkspace = ref("");
const workspaces = ref([]);
const permissionState = ref({});
const currentActions = ref([]);
const currentFields = ref([]);
const currentOwnOnly = ref(false);
const entriesByCollection = ref({});
const entryLoadState = ref({});
let lastEmitted = "";

const areaOptions = computed(() =>
  ["all", ...PERMISSION_AREAS].map((value) => ({ value, label: permissionAreaLabel(value) })),
);

// { area: [{ value, label }] } in the current admin language.
const actionGroups = computed(() =>
  Object.fromEntries(["all", ...PERMISSION_AREAS].map((area) => [area, permissionActionOptions(area)])),
);

const currentKey = computed(() => stateKeyForCurrentSelection());

const currentVisibleActions = computed(() => actionsFor(selectedArea.value));

const currentVisibleActionValues = computed(() =>
  currentVisibleActions.value.map((action) => action.value),
);

const allVisibleActionsSelected = computed(
  () =>
    currentVisibleActions.value.length > 0 &&
    currentVisibleActionValues.value.every((action) =>
      currentActions.value.includes(action),
    ),
);

const hiddenActionLabels = computed(() => {
  const visible = new Set(currentVisibleActionValues.value);
  const labels = Object.fromEntries(
    Object.values(actionGroups.value)
      .flat()
      .map((action) => [action.value, action.label]),
  );

  return currentActions.value
    .filter((action) => !visible.has(action))
    .map((action) => labels[action] ?? action);
});

const fieldsForCurrentContentType = computed(() => {
  if (selectedArea.value !== "content" || selectedCollection.value === "*")
    return [];

  const schema = contentTypes.list.find(
    (type) => type.name === selectedCollection.value,
  );
  const schemaFields =
    schema?.fields && typeof schema.fields === "object"
      ? Object.keys(schema.fields)
      : [];
  const selected = currentFields.value;

  return [
    ...new Set([
      "title",
      "slug",
      "status",
      "published_at",
      ...schemaFields,
      ...selected,
    ]),
  ];
});

const summary = computed(() => {
  const actions =
    currentActions.value.length > 0
      ? currentActions.value.map(permissionActionLabel).join(", ")
      : t("perm.noActions");
  return t("perm.summary", { actions, resource: resourceForCurrentSelection() });
});

const entriesLoading = computed(
  () => entryLoadState.value[selectedCollection.value]?.loading === true,
);
const entriesError = computed(
  () => entryLoadState.value[selectedCollection.value]?.error ?? "",
);
const entryOptions = computed(() =>
  (entriesByCollection.value[selectedCollection.value] ?? []).map((entry) => ({
    value: entry.slug || entry.id,
    label: entry.title
      ? `${entry.title} (${entry.slug || entry.id})`
      : entry.slug || entry.id,
  })),
);

watch(
  () => props.modelValue,
  (value) => {
    const signature = JSON.stringify(value ?? []);
    if (signature === lastEmitted) return;

    permissionState.value = stateFromApiGrants(value);
    selectInitialArea();
    loadCurrentState();
  },
  {
    immediate: true,
    deep: true,
  },
);

watch(currentKey, () => {
  loadCurrentState();
});

onMounted(async () => {
  contentTypes.fetch();
  try {
    const res = await api.workspaces.list();
    workspaces.value = res.data ?? [];
  } catch {
    workspaces.value = [];
  }
});

function selectArea(area) {
  if (selectedArea.value === area) return;
  selectedArea.value = area;
  emitChange();
}

function selectAreaTab(area) {
  selectArea(area.value);
}

function actionsFor(area) {
  if (area === "system") return systemActionsForResource();
  return actionGroups.value[area] ?? actionGroups.value.content;
}

function systemActionsForResource() {
  const resource = selectedSystemResource.value;
  if (resource === "dashboard:*") return actionGroups.value.system.slice(0, 1);
  if (resource === "activity:*") return actionGroups.value.system.slice(1, 2);
  if (resource === "backups:*")
    return actionGroups.value.system.filter((action) =>
      action.value.startsWith("backups."),
    );
  if (resource === "webhooks:*")
    return actionGroups.value.system.filter((action) =>
      action.value.startsWith("webhooks."),
    );
  if (resource.startsWith("workspaces:"))
    return actionGroups.value.system.filter((action) =>
      action.value.startsWith("workspaces."),
    );
  if (resource === "updates:*")
    return actionGroups.value.system.filter((action) =>
      action.value.startsWith("updates."),
    );

  return actionGroups.value.system;
}

const bulkAreaGroups = computed(() =>
  PERMISSION_AREAS.map((area) => ({ area, label: permissionAreaLabel(area) })),
);

const bulkAreaResourceMap = {
  system: { resource: "*", key: "*" },
  schema: { resource: "schema:*", key: "schema:*" },
  content: { resource: "content:*:*", key: "content:*:*" },
  media: { resource: "media:*", key: "media:*" },
  site: { resource: "*", key: "site:*" },
  users: { resource: "*", key: "users:*" },
};

function areaHasGrants(area) {
  return Object.values(permissionState.value).some(
    (item) => item.area === area && item.actions.length > 0,
  );
}

function areaGrantState(area) {
  const existingActions = Object.values(permissionState.value)
    .filter((item) => item.area === area)
    .flatMap((item) => item.actions);
  if (existingActions.length === 0) return "none";
  const allActions = (actionGroups.value[area] ?? []).map((a) => a.value);
  return allActions.every((action) => existingActions.includes(action))
    ? "all"
    : "some";
}

function toggleAreaAllGrants(area) {
  if (areaHasGrants(area)) {
    const newState = {};
    for (const [k, item] of Object.entries(permissionState.value)) {
      if (item.area !== area) newState[k] = item;
    }
    permissionState.value = newState;
  } else {
    const allActions = (actionGroups.value[area] ?? []).map((a) => a.value);
    const { resource, key } = bulkAreaResourceMap[area];
    permissionState.value = {
      ...permissionState.value,
      [key]: { area, resource, actions: allActions, fields: [], own: false },
    };
  }
  emitState();
}

function setCurrentActions() {
  const allowed = new Set(currentVisibleActionValues.value);
  currentActions.value = currentActions.value.filter((action) =>
    allowed.has(action),
  );
  setCurrentState({ actions: [...currentActions.value] });
}

function toggleAllCurrentActions() {
  currentActions.value = allVisibleActionsSelected.value
    ? []
    : [...currentVisibleActionValues.value];
  setCurrentActions();
}

function setCurrentFields() {
  setCurrentState({ fields: [...currentFields.value] });
}

function setCurrentOwnOnly() {
  setCurrentState({ own: currentOwnOnly.value });
}

function emitChange() {
  loadCurrentState();
  emitState();
}

function onEntryChange(value) {
  selectedEntry.value = String(value ?? "").trim();
  emitChange();
}

function onCollectionChange() {
  if (selectedArea.value === "content") {
    selectedEntry.value = "";
  }

  emitChange();
}

async function loadEntriesIfNeeded() {
  if (selectedArea.value !== "content" || selectedCollection.value === "*")
    return;
  const state = entryLoadState.value[selectedCollection.value] ?? {};
  if (state.loaded || state.loading) return;

  await loadEntries();
}

async function loadEntries() {
  const collection = selectedCollection.value;
  if (!collection || collection === "*") return;

  entryLoadState.value = {
    ...entryLoadState.value,
    [collection]: { loading: true, loaded: false, error: "" },
  };

  try {
    const res = await api.content.list(collection, {
      limit: 200,
      sort: "title",
    });
    entriesByCollection.value = {
      ...entriesByCollection.value,
      [collection]: (res.data ?? []).map((entry) => ({
        id: entry.id,
        slug: entry.slug ?? "",
        title: entry.title ?? "",
      })),
    };

    entryLoadState.value = {
      ...entryLoadState.value,
      [collection]: { loading: false, loaded: true, error: "" },
    };
  } catch (err) {
    entryLoadState.value = {
      ...entryLoadState.value,
      [collection]: {
        loading: false,
        loaded: false,
        error: err.message ?? t("perm.entriesLoadFailed"),
      },
    };
  }
}

function loadCurrentState() {
  const state =
    permissionState.value[currentKey.value] ??
    defaultStateForArea(selectedArea.value);
  currentActions.value = [...state.actions];
  currentFields.value = [...(state.fields ?? [])];
  currentOwnOnly.value = state.own === true;
}

function setCurrentState(patch) {
  const existing =
    permissionState.value[currentKey.value] ??
    defaultStateForArea(selectedArea.value);
  permissionState.value = {
    ...permissionState.value,
    [currentKey.value]: {
      ...existing,
      area: selectedArea.value,
      resource: resourceForCurrentSelection(),
      ...patch,
    },
  };
  emitState();
}

function emitState() {
  const apiGrants = apiGrantsFromState(permissionState.value);
  lastEmitted = JSON.stringify(apiGrants);
  emit("update:modelValue", apiGrants);
}

function stateFromApiGrants(items) {
  const state = {};

  for (const grant of Array.isArray(items) ? items : []) {
    const actions = Array.isArray(grant.actions)
      ? grant.actions.filter(Boolean)
      : [];
    if (actions.length === 0) continue;

    const resource = Array.isArray(grant.resources)
      ? String(grant.resources[0] ?? "*")
      : "*";
    const area = inferArea(actions, resource);
    const parsed = parseResource(area, resource);
    const key = stateKey(area, parsed);

    state[key] = {
      area,
      resource,
      actions,
      fields: Array.isArray(grant.fields) ? grant.fields.map(String) : [],
      own: grant.conditions?.own === true,
    };
  }

  return state;
}

function apiGrantsFromState(state) {
  return Object.values(state)
    .filter((item) => Array.isArray(item.actions) && item.actions.length > 0)
    .map((item) => {
      const grant = {
        effect: "allow",
        actions: [...item.actions],
        resources: [item.resource],
      };

      if (
        item.area === "content" &&
        Array.isArray(item.fields) &&
        item.fields.length > 0
      ) {
        grant.fields = [...item.fields];
      }

      if (item.area === "content" && item.own) {
        grant.conditions = { own: true };
      }

      return grant;
    });
}

function selectInitialArea() {
  selectedArea.value = "all";
}

function stateKeyForCurrentSelection() {
  const key = stateKey(selectedArea.value, {
    collection: selectedCollection.value,
    entry: selectedEntry.value,
    mediaCategory: selectedMediaCategory.value,
    systemResource: selectedSystemResource.value,
  });

  return selectedWorkspace.value &&
    ["content", "schema", "media"].includes(selectedArea.value)
    ? `workspace:${selectedWorkspace.value}:${key}`
    : key;
}

function stateKey(area, parsed) {
  const prefix = parsed.workspace ? `workspace:${parsed.workspace}:` : "";
  if (area === "all") return "all:*";
  if (area === "content")
    return `${prefix}content:${parsed.collection || "*"}:${parsed.entry || "*"}`;
  if (area === "schema") return `${prefix}schema:${parsed.collection || "*"}`;
  if (area === "media")
    return (
      prefix +
      (parsed.mediaCategory
        ? `media:category:${parsed.mediaCategory}`
        : "media:*")
    );
  if (area === "users") return "users:*";
  if (area === "site") return "site:*";
  return parsed.systemResource || "*";
}

function resourceForCurrentSelection() {
  if (selectedArea.value === "all") return "*";
  let resource;
  if (selectedArea.value === "content")
    resource = `content:${selectedCollection.value || "*"}:${selectedEntry.value || "*"}`;
  else if (selectedArea.value === "schema")
    resource = `schema:${selectedCollection.value || "*"}`;
  else if (selectedArea.value === "media")
    resource = selectedMediaCategory.value
      ? `media:category:${selectedMediaCategory.value}`
      : "media:*";
  else if (selectedArea.value === "users" || selectedArea.value === "site") resource = "*";
  else resource = selectedSystemResource.value || "*";

  return selectedWorkspace.value &&
    ["content", "schema", "media"].includes(selectedArea.value)
    ? `workspace:${selectedWorkspace.value}:${resource}`
    : resource;
}

function inferArea(actions, resource) {
  resource = stripWorkspaceResource(resource).resource;
  if (actions.includes("*")) return "all";
  if (
    actions.some((action) => action.startsWith("schema.")) ||
    resource.startsWith("schema:")
  )
    return "schema";
  if (
    actions.some((action) => action.startsWith("media.")) ||
    resource.startsWith("media:")
  )
    return "media";
  if (
    actions.some(
      (action) =>
        action.startsWith("users.") ||
        action.startsWith("tokens.") ||
        action.startsWith("roles."),
    )
  )
    return "users";
  if (actions.some((action) => action.startsWith("site."))) return "site";
  if (
    actions.some((action) =>
      [
        "dashboard.",
        "activity.",
        "backups.",
        "webhooks.",
        "updates.",
        "workspaces.",
      ].some((prefix) => action.startsWith(prefix)),
    )
  )
    return "system";
  if (resource === "*") return "all";
  return "content";
}

function parseResource(area, resource) {
  const workspaceResource = stripWorkspaceResource(resource);
  resource = workspaceResource.resource;
  const fallback = {
    collection: "*",
    entry: "",
    mediaCategory: "",
    systemResource: "*",
    workspace: workspaceResource.workspace,
  };

  if (area === "content") {
    const parts = resource.split(":");
    return {
      ...fallback,
      collection: parts[1] || "*",
      entry: parts[2] === "*" ? "" : parts[2] || "",
    };
  }

  if (area === "schema") {
    return { ...fallback, collection: resource.split(":")[1] || "*" };
  }

  if (area === "media" && resource.startsWith("media:category:")) {
    return {
      ...fallback,
      mediaCategory: resource.replace("media:category:", ""),
    };
  }

  if (area === "system") {
    return { ...fallback, systemResource: resource };
  }

  return fallback;
}

function stripWorkspaceResource(resource) {
  const match = String(resource).match(/^workspace:([A-Za-z0-9_-]+):(.+)$/);

  return match
    ? { workspace: match[1], resource: match[2] }
    : { workspace: "", resource };
}

function defaultStateForArea(area) {
  return {
    area,
    resource: resourceForCurrentSelection(),
    actions: [],
    fields: [],
    own: false,
  };
}
</script>
