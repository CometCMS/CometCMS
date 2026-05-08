<template>
  <div class="border-b border-slate-100 last:border-0">
    <div class="flex items-center gap-2 py-2">
      <button
        type="button"
        class="flex flex-1 min-w-0 items-center gap-2 text-left"
        @click="expanded = !expanded"
      >
        <Icon
          icon="mdi:chevron-right"
          class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
          :class="{ 'rotate-90': expanded }"
        />
        <div class="min-w-0">
          <span class="text-sm font-semibold text-slate-800">{{
            token.name
          }}</span>
          <span v-if="token.revoked_at" class="ml-1.5 text-xs text-slate-400">{{
            t("tokens.revoked")
          }}</span>
          <span class="ml-1.5 text-xs text-slate-400"
            >· {{ t("tokens.created") }}
            {{ formatDate(token.created_at) }}</span
          >
          <p
            v-if="token.description"
            class="mt-0.5 truncate text-xs text-slate-500"
          >
            {{ token.description }}
          </p>
        </div>
      </button>
      <button
        v-if="!token.revoked_at"
        type="button"
        class="btn-secondary text-xs py-1 px-2 shrink-0"
        @click="$emit('revoke')"
      >
        {{ t("tokens.revoke") }}
      </button>
    </div>
    <div v-if="expanded" class="pb-3 pl-6">
      <PermissionBadges :permissions="token.permissions ?? []" />
    </div>
  </div>
</template>

<script setup>
import { ref } from "vue";
import { Icon } from "@iconify/vue";
import PermissionBadges from "./PermissionBadges.vue";
import { useI18n } from "../i18n/index.js";

defineProps({
  token: { type: Object, required: true },
});

defineEmits(["revoke"]);

const { t } = useI18n();
const expanded = ref(false);

function formatDate(iso) {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString();
}
</script>
