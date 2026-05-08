<template>
  <div>
    <div class="flex items-center gap-3 mb-6">
      <router-link
        :to="`/content/${collection}`"
        class="text-slate-500 hover:text-slate-800 transition-colors"
      >
        <Icon icon="mdi:chevron-left" class="w-5 h-5" />
      </router-link>
      <h1 class="text-2xl font-bold text-slate-900 capitalize">
        {{ t("trash.title", { collection }) }}
      </h1>
      <button
        v-if="items.length > 0"
        type="button"
        class="btn-danger ml-auto"
        @click="showEmptyModal = true"
      >
        {{ t("trash.emptyAction") }}
      </button>
    </div>

    <LoadingSpinner v-if="loading" />

    <div
      v-else-if="error"
      class="p-3 bg-red-50 text-red-700 rounded-lg text-sm"
    >
      {{ error }}
    </div>

    <template v-else>
      <div class="card overflow-hidden">
        <table class="w-full">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200">
              <th
                class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3"
              >
                {{ t("trash.titleColumn") }}
              </th>
              <th
                class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3"
              >
                {{ t("trash.deleted") }}
              </th>
              <th
                class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3"
              >
                {{ t("trash.status") }}
              </th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="items.length === 0">
              <td
                colspan="4"
                class="px-4 py-10 text-center text-slate-500 text-sm"
              >
                {{ t("trash.emptyForCollection") }}
              </td>
            </tr>
            <tr v-for="item in items" :key="item.id" class="hover:bg-slate-50">
              <td class="px-4 py-3 text-sm font-medium text-slate-900">
                {{ item.title ?? item.id }}
              </td>
              <td class="px-4 py-3 text-sm text-slate-500">
                {{ formatDate(item.deleted_at) }}
              </td>
              <td class="px-4 py-3 text-sm">
                <span :class="`badge-${item.status}`">{{ item.status }}</span>
              </td>
              <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-2">
                  <button
                    type="button"
                    class="btn-secondary py-1 px-3 text-xs"
                    :disabled="restoringId === item.id"
                    @click="restore(item)"
                  >
                    {{
                      restoringId === item.id
                        ? t("trash.restoring")
                        : t("trash.restore")
                    }}
                  </button>
                  <button
                    type="button"
                    class="btn-danger py-1 px-3 text-xs"
                    @click="confirmPurge(item)"
                  >
                    {{ t("trash.deletePermanently") }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <ConfirmModal
      v-model="showPurgeModal"
      :title="t('trash.purgeTitle')"
      :message="
        t('trash.purgeMessage', { name: purgeTarget?.title ?? purgeTarget?.id })
      "
      :confirm-label="t('trash.deletePermanently')"
      :loading="purging"
      @confirm="purge"
    />

    <ConfirmModal
      v-model="showEmptyModal"
      :title="t('trash.emptyTitle')"
      :message="
        t('trash.emptyMessage', {
          count: items.length,
          itemLabel: t(items.length === 1 ? 'trash.item' : 'trash.items'),
        })
      "
      :confirm-label="t('trash.emptyAction')"
      :loading="emptying"
      @confirm="emptyTrash"
    />
  </div>
</template>

<script setup>
import LoadingSpinner from "../components/LoadingSpinner.vue";
import { ref, onMounted } from "vue";
import { useRoute } from "vue-router";
import { Icon } from "@iconify/vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import { api } from "../api/index.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";

const route = useRoute();
const toast = useToastStore();
const { t } = useI18n();
const collection = route.params.collection;

const loading = ref(true);
const error = ref("");
const items = ref([]);

const restoringId = ref("");
const showPurgeModal = ref(false);
const purgeTarget = ref(null);
const purging = ref(false);
const showEmptyModal = ref(false);
const emptying = ref(false);

async function load() {
  loading.value = true;
  error.value = "";
  try {
    const res = await api.trash.list(collection);
    items.value = res.data ?? [];
  } catch (err) {
    error.value = err.message;
  } finally {
    loading.value = false;
  }
}

async function restore(item) {
  restoringId.value = item.id;
  try {
    await api.trash.restore(collection, item.id);
    items.value = items.value.filter((i) => i.id !== item.id);
    toast.success(t("trash.restored", { name: item.title ?? item.id }));
  } catch (err) {
    toast.error(err.message);
  } finally {
    restoringId.value = "";
  }
}

function confirmPurge(item) {
  purgeTarget.value = item;
  showPurgeModal.value = true;
}

async function purge() {
  const item = purgeTarget.value;
  purging.value = true;
  try {
    await api.trash.purge(collection, item.id);
    items.value = items.value.filter((i) => i.id !== item.id);
    toast.success(
      t("trash.permanentlyDeleted", { name: item.title ?? item.id }),
    );
    showPurgeModal.value = false;
  } catch (err) {
    toast.error(err.message);
    showPurgeModal.value = false;
  } finally {
    purging.value = false;
    purgeTarget.value = null;
  }
}

async function emptyTrash() {
  emptying.value = true;
  try {
    await api.trash.empty(collection);
    items.value = [];
    toast.success(t("trash.emptied"));
    showEmptyModal.value = false;
  } catch (err) {
    toast.error(err.message);
    showEmptyModal.value = false;
  } finally {
    emptying.value = false;
  }
}

function formatDate(iso) {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" });
}

onMounted(load);
</script>
