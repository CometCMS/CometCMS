<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-slate-900">
        {{ t("apiExplorer.title") }}
      </h1>
    </div>

    <ApiQueryBuilder :api-base="apiBase" :collections="collections" />

    <section class="card mt-6 overflow-hidden">
      <div
        class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-start sm:justify-between"
      >
        <div>
          <div class="flex flex-wrap items-center gap-2">
            <Icon icon="mdi:server-network" class="h-5 w-5 text-theme-600" />
            <h2 class="text-base font-semibold text-slate-900">
              MCP Endpoint
            </h2>
          </div>
          <p class="mt-1 text-sm text-slate-500">
            Connect MCP clients with the same workspace and API token permissions.
          </p>
        </div>
        <button
          type="button"
          class="btn-secondary shrink-0 px-3 py-1.5 text-sm"
          @click="copy(mcpUrl)"
        >
          <Icon icon="mdi:content-copy" class="h-4 w-4" />
          Copy URL
        </button>
      </div>

      <div class="bg-slate-50/60 p-5">
        <div
          class="flex min-h-12 items-center gap-3 rounded-lg bg-slate-950 px-3 py-2 text-sm text-slate-100"
        >
          <span class="shrink-0 font-mono text-xs font-semibold text-theme-300"
            >POST</span
          >
          <code
            class="min-w-0 flex-1 overflow-x-auto whitespace-nowrap font-mono text-xs leading-6"
            >{{ mcpUrl }}</code
          >
        </div>

        <div
          class="mt-3 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
        >
          <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
            <span
              >Transport:
              <span
                class="rounded-full bg-white px-2 py-0.5 font-semibold text-slate-700 ring-1 ring-slate-200"
                >HTTP</span
              ></span
            >
            <span
              >Auth:
              <span
                class="rounded-full bg-white px-2 py-0.5 font-semibold text-slate-700 ring-1 ring-slate-200"
                >Bearer</span
              ></span
            >
          </div>

          <button
            type="button"
            class="inline-flex items-center gap-1.5 text-sm font-medium text-theme-700 hover:text-theme-800"
            @click="copy(mcpCurlCommand)"
          >
            <Icon icon="mdi:console-line" class="h-4 w-4" />
            Copy curl
          </button>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import ApiQueryBuilder from "../components/ApiQueryBuilder.vue";
import { computed, ref, onMounted } from "vue";
import { Icon } from "@iconify/vue";
import { api } from "../api/index.js";
import { useI18n } from "../i18n/index.js";
import {
  workspacedApiBase,
  workspacedMcpEndpoint,
} from "../composables/apiEndpoint.js";
import { useToastStore } from "../stores/toast.js";

const origin = window.location.origin;
const apiBase = workspacedApiBase(origin);
const mcpUrl = workspacedMcpEndpoint(origin);
const collections = ref([]);
const { t } = useI18n();
const toast = useToastStore();

const mcpCurlCommand = computed(
  () =>
    `curl -X POST -H "Content-Type: application/json" -H "Authorization: Bearer YOUR_TOKEN_HERE" \\\n  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \\\n  "${mcpUrl}"`,
);

async function copy(value) {
  await navigator.clipboard.writeText(value);
  toast.success("Copied to clipboard.");
}

onMounted(async () => {
  try {
    const res = await api.contentTypes.list();
    collections.value = res.data ?? [];
  } catch {
    // silently ignore
  }
});
</script>
