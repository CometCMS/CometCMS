import { computed, ref } from "vue";
import { defineStore } from "pinia";

export const useApiEndpointStore = defineStore("apiEndpoint", () => {
  const endpoint = ref(null);

  function setEndpoint(nextEndpoint, owner = "default") {
    endpoint.value = nextEndpoint ? { method: "GET", ...nextEndpoint, owner } : null;
  }

  function clearEndpoint(owner = null) {
    if (owner === null || endpoint.value?.owner === owner) {
      endpoint.value = null;
    }
  }

  const current = computed(() => endpoint.value);

  return { current, setEndpoint, clearEndpoint };
});
