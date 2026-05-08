<template>
  <LoadingSpinner v-if="loading" />
  <div
    v-else-if="loadError"
    class="p-3 bg-red-50 text-red-700 rounded-lg text-sm"
  >
    {{ loadError }}
  </div>
  <ContentEditView v-else-if="isSingleton" />
  <ContentListView v-else />
</template>

<script setup>
import { computed, ref, watch } from "vue";
import { useRoute } from "vue-router";
import LoadingSpinner from "../components/LoadingSpinner.vue";
import ContentEditView from "./ContentEditView.vue";
import ContentListView from "./ContentListView.vue";
import { api } from "../api/index.js";
import { useAuthStore } from "../stores/auth.js";

const route = useRoute();
const auth = useAuthStore();
const loading = ref(true);
const loadError = ref("");
const contentTypeSchema = ref(null);
const collection = computed(() => route.params.collection);
const isSingleton = computed(() => !!contentTypeSchema.value?.singleton);

watch(collection, loadContentType, { immediate: true });

async function loadContentType() {
  loading.value = true;
  loadError.value = "";
  contentTypeSchema.value = null;

  try {
    const res = await api.contentTypes.get(collection.value);
    contentTypeSchema.value = res.data;
  } catch (err) {
    if (err.status === 403) {
      // No schema.read — fall back to the minimal info bundled in /me
      const fallback = (auth.user?.accessible_content_types ?? []).find(
        (t) => t.name === collection.value,
      );
      contentTypeSchema.value = fallback ?? null;
      if (!fallback) {
        loadError.value = err.message ?? "Content type could not be loaded.";
      }
    } else {
      loadError.value = err.message ?? "Content type could not be loaded.";
    }
  } finally {
    loading.value = false;
  }
}
</script>
