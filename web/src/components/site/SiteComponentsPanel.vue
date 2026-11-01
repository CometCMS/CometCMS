<template>
  <section class="card p-6">
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 class="card-title">{{ t("site.component.group") }}</h2>
        <p class="card-description">{{ t("site.component.libraryHint") }}</p>
      </div>
      <div class="w-full sm:w-72">
        <label class="sr-only" :for="searchId">{{ t("site.component.search") }}</label>
        <input :id="searchId" v-model="search" type="search" :placeholder="t('site.component.search')"
          class="form-input w-full rounded-lg border-slate-300 text-sm" @keydown.esc.stop.prevent="search = ''" />
      </div>
    </div>
    <div v-if="loading" class="flex justify-center py-10"><LoadingSpinner /></div>
    <div v-else-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
      <p>{{ error }}</p>
      <button type="button" class="btn-secondary btn-sm mt-3" @click="load">{{ t('common.retry') }}</button>
    </div>
    <ul v-else class="divide-y divide-slate-100">
      <li v-for="component in components" :key="component.id" class="flex flex-wrap items-center gap-3 py-4">
        <IconPickerGrid
          compact
          class="shrink-0"
          :model-value="component.icon || ''"
          placeholder="mdi:puzzle-outline"
          :icons="SITE_ICONS"
          :allow-custom="false"
          :label="t('site.component.changeIcon', { name: component.name })"
          :reset-label="t('site.component.resetIcon')"
          :disabled="busy"
          @update:model-value="setIcon(component, $event)"
        />
        <div class="min-w-0 flex-1">
          <router-link :to="editPath(component)" class="break-words text-sm font-semibold text-slate-900 hover:text-theme-700" :class="{ 'pointer-events-none': busy }" :aria-disabled="busy || undefined" :tabindex="busy ? -1 : undefined">{{ component.name }}</router-link>
          <p class="mt-1 text-xs text-slate-500">
            {{ t(`site.kind.${component.root}`) }}
            · {{ t('site.component.usedIn', { count: component.usages ?? 0 }) }}
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <router-link :to="editPath(component)" class="btn-secondary btn-sm" :class="{ 'pointer-events-none opacity-50': busy }" :aria-disabled="busy || undefined" :tabindex="busy ? -1 : undefined">
            <Icon icon="mdi:pencil-outline" class="h-4 w-4" />{{ t('site.component.edit') }}
            <span class="sr-only">: {{ component.name }}</span>
          </router-link>
          <button type="button" class="btn-secondary btn-sm" :disabled="busy" @click="renameTarget = component">
            <Icon icon="mdi:form-textbox" class="h-4 w-4" />{{ t('site.component.rename') }}
            <span class="sr-only">: {{ component.name }}</span>
          </button>
          <button type="button" class="btn-danger-subtle btn-sm" :disabled="busy" @click="deleteTarget = component">
            <Icon icon="mdi:trash-can-outline" class="h-4 w-4" />{{ t('common.delete') }}
            <span class="sr-only">: {{ component.name }}</span>
          </button>
        </div>
      </li>
    </ul>
    <p v-if="!loading && !error && !allComponents.length" class="py-6 text-sm text-slate-500">{{ t("site.component.paletteEmpty") }}</p>
    <p v-else-if="!loading && !error && !components.length" role="status" class="py-6 text-sm text-slate-500">{{ t("site.builder.noSearchResults") }}</p>

    <ComponentNameModal
      :model-value="renameTarget !== null"
      :title="t('site.component.renameTitle')"
      :confirm-label="t('site.component.rename')"
      :initial="renameTarget?.name ?? ''"
      :loading="busy"
      @update:model-value="(open) => { if (!open) renameTarget = null }"
      @confirm="rename"
    />

    <ConfirmModal
      :model-value="deleteTarget !== null"
      :title="t('site.component.deleteTitle', { name: deleteTarget?.name ?? '' })"
      :message="t('site.component.deleteMessage')"
      :confirm-label="t('common.delete')"
      :loading="busy"
      @update:model-value="(open) => { if (!open) deleteTarget = null }"
      @confirm="remove"
    />
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId } from "vue";
import { Icon } from "@iconify/vue";
import ConfirmModal from "../ConfirmModal.vue";
import LoadingSpinner from "../LoadingSpinner.vue";
import IconPickerGrid from "../IconPickerGrid.vue";
import ComponentNameModal from "./ComponentNameModal.vue";
import { api } from "../../api/index.js";
import { useToastStore } from "../../stores/toast.js";
import { useI18n } from "../../i18n/index.js";
import { SITE_ICONS } from "../../composables/siteIcons.js";

const toast = useToastStore();
const { t } = useI18n();
const search = ref("");
const searchId = `site-components-search-${useId()}`;
const busy = ref(false);
const loading = ref(true);
const error = ref("");
const allComponents = ref([]);
let active = true;
onBeforeUnmount(() => { active = false; });
onMounted(load);

async function load() {
  loading.value = true;
  error.value = "";
  try {
    const response = await api.site.components();
    if (active) allComponents.value = response.data;
  } catch (err) {
    if (active) error.value = err.message;
  } finally {
    if (active) loading.value = false;
  }
}

function editPath(component) {
  return { path: `/page-builder/builder/component/${encodeURIComponent(component.id)}`, query: { back: "/page-builder/components" } };
}
const renameTarget = ref(null);
const deleteTarget = ref(null);
const components = computed(() => {
  const terms = search.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
  return [...allComponents.value]
    .filter((component) => {
      const text = [component.name, t(`site.kind.${component.root}`)].join(" ").toLowerCase();
      return terms.every((term) => text.includes(term));
    })
    .sort((a, b) => a.name.localeCompare(b.name));
});

async function rename(name) {
  if (busy.value || !renameTarget.value) return;
  busy.value = true;
  try {
    const id = renameTarget.value.id;
    const { data: updated } = await api.site.renameComponent(id, name);
    if (!active) return;
    allComponents.value = allComponents.value.map((component) => component.id === id ? { ...component, ...updated } : component);
    renameTarget.value = null;
  } catch (err) {
    toast.error(err.message);
  } finally {
    busy.value = false;
  }
}

async function setIcon(component, icon) {
  if (busy.value || icon === (component.icon || '')) return;
  busy.value = true;
  try {
    const { data: updated } = await api.site.setComponentIcon(component.id, icon);
    if (!active) return;
    allComponents.value = allComponents.value.map((item) => item.id === component.id ? { ...item, ...updated } : item);
  } catch (err) {
    toast.error(err.message);
  } finally {
    busy.value = false;
  }
}

async function remove() {
  if (busy.value || !deleteTarget.value) return;
  busy.value = true;
  try {
    const id = deleteTarget.value.id;
    await api.site.deleteComponent(id);
    if (!active) return;
    allComponents.value = allComponents.value.filter((component) => component.id !== id);
    // Other components may have contained the deleted one; refresh their usage counts.
    await load();
    deleteTarget.value = null;
    toast.success(t("site.component.deleted"));
  } catch (err) {
    toast.error(err.message);
  } finally {
    busy.value = false;
  }
}
</script>
