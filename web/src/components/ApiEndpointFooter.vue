<template>
  <footer v-if="canViewFooter && endpoint?.url"
    class="api-endpoint-footer group hidden w-full sticky bottom-0 z-20 shrink-0 border-t px-4 py-1.5 backdrop-blur sm:px-8 lg:flex">
    <div class="mx-auto grid w-full max-w-7xl grid-cols-[minmax(10rem,auto)_minmax(0,1fr)_auto] items-center gap-3">
      <RouterLink to="/api-explorer"
        class="api-endpoint-footer-link flex min-w-0 items-center gap-2 text-xs font-medium transition-colors">
        <Icon icon="mdi:api" class="api-endpoint-footer-icon h-4 w-4" />
        <span class="shrink-0">Build Query</span>
        <span v-if="endpoint.authLabel"
          class="api-endpoint-footer-badge hidden shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 xl:inline-flex">
          {{ endpoint.authLabel }}
        </span>
        <Icon icon="mdi:open-in-new" class="api-endpoint-footer-icon h-4 w-4 shrink-0" />
      </RouterLink>

      <div class="api-endpoint-footer-url flex min-h-8 min-w-0 items-center gap-3 rounded-md border px-3 py-1 text-sm">
        <span class="api-endpoint-footer-method shrink-0 font-mono text-xs font-semibold">
          {{ endpoint.method }}
        </span>
        <code
          class="min-w-0 flex-1 overflow-x-auto whitespace-nowrap font-mono text-xs leading-5">{{ endpoint.url }}</code>
        <button type="button" class="btn-secondary shrink-0 px-2 py-1 text-xs" @click="copyUrl">
          <Icon icon="mdi:content-copy" class="h-4 w-4" />
          Copy
        </button>
      </div>

      <button type="button"
        class="api-endpoint-footer-dismiss flex h-8 w-8 shrink-0 items-center justify-center rounded-lg opacity-0 transition-all duration-150 group-hover:opacity-100 group-focus-within:opacity-100 focus:opacity-100"
        title="Hide API footer" aria-label="Hide API footer" @click="dismissFooter">
        <Icon icon="mdi:close" class="h-4 w-4" />
      </button>
    </div>
  </footer>
</template>

<script setup>
import { computed } from "vue";
import { Icon } from "@iconify/vue";
import { RouterLink } from "vue-router";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import { useAuthStore } from "../stores/auth.js";
import { useToastStore } from "../stores/toast.js";
import { api } from "../api/index.js";

const endpointStore = useApiEndpointStore();
const auth = useAuthStore();
const toast = useToastStore();
const endpoint = computed(() => endpointStore.current);
const canViewFooter = computed(
  () =>
    auth.user?.show_api_footer !== false &&
    (auth.can("tokens.read") ||
      auth.can("tokens.create") ||
      auth.can("tokens.revoke")),
);

async function copyUrl() {
  try {
    await navigator.clipboard.writeText(endpoint.value.url);
    toast.success("Copied to clipboard.");
  } catch {
    toast.error("Could not copy to clipboard.");
  }
}

async function dismissFooter() {
  if (!auth.user) return;

  const previousValue = auth.user.show_api_footer ?? true;
  auth.user.show_api_footer = false;

  try {
    const res = await api.profile.update({ show_api_footer: false });
    auth.user.show_api_footer = res.data?.show_api_footer ?? false;
    toast.success("API footer hidden.");
  } catch {
    auth.user.show_api_footer = previousValue;
    toast.error("Could not hide API footer.");
  }
}
</script>
