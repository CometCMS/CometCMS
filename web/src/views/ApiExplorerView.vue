<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-slate-900">
        {{ t("apiExplorer.title") }}
      </h1>
    </div>

    <ApiQueryBuilder :api-base="apiBase" :collections="collections" />
  </div>
</template>

<script setup>
import ApiQueryBuilder from "../components/ApiQueryBuilder.vue";
import { ref, onMounted } from "vue";
import { api } from "../api/index.js";
import { useI18n } from "../i18n/index.js";
import { workspacedApiBase } from "../composables/apiEndpoint.js";

const origin = window.location.origin;
const apiBase = workspacedApiBase(origin);
const collections = ref([]);
const { t } = useI18n();

onMounted(async () => {
  try {
    const res = await api.contentTypes.list();
    collections.value = res.data ?? [];
  } catch {
    // silently ignore
  }
});
</script>
