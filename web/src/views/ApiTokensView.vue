<template>
  <div>
    <div
      class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
      :class="embedded ? 'mb-4' : 'mb-6'"
    >
      <!-- Embedded under the "Access tokens" tab: explain the tab instead of repeating its name. -->
      <p v-if="embedded" class="max-w-3xl text-sm text-slate-500">{{ t("tokens.intro") }}</p>
      <h1 v-else class="text-2xl font-bold text-slate-900">{{ t("tokens.title") }}</h1>
      <button v-if="!showTokenForm && tokens.length > 0" @click="openTokenForm" class="btn-primary shrink-0">
        <Icon icon="mdi:plus" class="h-4 w-4" />
        {{ t("tokens.newToken") }}
      </button>
    </div>

    <div v-if="showTokenForm" class="card p-6 mb-6 space-y-4">
      <h2 class="card-title">
        {{ t("tokens.createToken") }}
      </h2>
      <div class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="form-label">{{ t("tokens.tokenName") }}</label>
          <input
            v-model="tokenForm.name"
            type="text"
            :placeholder="t('tokens.tokenPlaceholder')"
            class="form-input w-full rounded-lg border-slate-300 text-sm"
          />
        </div>
        <div>
          <label class="form-label">{{ t("tokens.description") }}</label>
          <input
            v-model="tokenForm.description"
            type="text"
            :placeholder="t('tokens.descriptionPlaceholder')"
            class="form-input w-full rounded-lg border-slate-300 text-sm"
          />
        </div>
      </div>
      <div>
        <label class="form-label">{{ t("tokens.permissionGrants") }}</label>
        <PermissionGrantsEditor v-model="tokenForm.permissions" />
      </div>
      <div class="flex gap-2">
        <button @click="handleCreateToken" class="btn-primary">
          {{ t("tokens.create") }}
        </button>
        <button @click="showTokenForm = false" class="btn-secondary">
          {{ t("common.cancel") }}
        </button>
      </div>
    </div>

    <div v-if="newTokenValue" class="mb-6 p-4 bg-green-50 rounded-lg">
      <p class="text-sm text-green-700 font-semibold mb-1">
        {{ t("tokens.tokenCreatedCopy") }}
      </p>
      <code class="text-xs break-all text-green-900 font-mono">{{
        newTokenValue
      }}</code>
    </div>

    <LoadingSpinner v-if="loading" />

    <div v-else-if="tokens.length === 0 && !showTokenForm" class="card">
      <EmptyState icon="mdi:key-outline" :title="t('tokens.noTokens')" :description="t('tokens.emptyHint')">
        <button type="button" class="btn-primary" @click="openTokenForm">
          <Icon icon="mdi:plus" class="h-4 w-4" />
          {{ t("tokens.newToken") }}
        </button>
      </EmptyState>
    </div>

    <div v-else-if="tokens.length > 0" class="card p-5">
      <TokenCard
        v-for="token in tokens"
        :key="token.id"
        :token="token"
        @revoke="handleRevokeToken(token.id)"
        @delete="handleDeleteToken(token.id)"
      />
    </div>

    <ConfirmModal
      v-model="showTokenActionModal"
      :title="tokenActionTitle"
      :message="tokenActionMessage"
      :confirm-label="tokenActionConfirmLabel"
      :loading="processingTokenAction"
      @confirm="confirmTokenAction"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import { Icon } from "@iconify/vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import EmptyState from "../components/EmptyState.vue";
import LoadingSpinner from "../components/LoadingSpinner.vue";
import PermissionGrantsEditor from "../components/PermissionGrantsEditor.vue";
import TokenCard from "../components/TokenCard.vue";
import { api } from "../api/index.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";

const toast = useToastStore();
defineProps({ embedded: { type: Boolean, default: false } });
const { t } = useI18n();
const loading = ref(true);
const tokens = ref([]);
const showTokenForm = ref(false);
const newTokenValue = ref("");
const showTokenActionModal = ref(false);
const processingTokenAction = ref(false);
const pendingTokenId = ref("");
const pendingTokenAction = ref("revoke");

const defaultTokenPermissions = [
  {
    effect: "allow",
    actions: ["content.read", "content.create", "content.update"],
    resources: ["content:*"],
  },
];

const tokenForm = ref({
  name: "",
  description: "",
  permissions: clonePermissions(defaultTokenPermissions),
});
const tokenActionTitle = computed(() =>
  pendingTokenAction.value === "delete"
    ? t("tokens.deleteConfirm")
    : t("tokens.revokeConfirm"),
);
const tokenActionMessage = computed(() =>
  pendingTokenAction.value === "delete"
    ? t("tokens.deleteMessage")
    : t("tokens.revokeMessage"),
);
const tokenActionConfirmLabel = computed(() =>
  pendingTokenAction.value === "delete" ? t("tokens.delete") : t("tokens.revoke"),
);

function openTokenForm() {
  newTokenValue.value = "";
  tokenForm.value = {
    name: "",
    description: "",
    permissions: clonePermissions(defaultTokenPermissions),
  };
  showTokenForm.value = true;
}

async function load() {
  loading.value = true;
  try {
    const res = await api.tokens.list();
    tokens.value = res.data ?? [];
  } finally {
    loading.value = false;
  }
}

async function handleCreateToken() {
  try {
    const res = await api.tokens.create({
      name: tokenForm.value.name,
      description: tokenForm.value.description,
      permissions: tokenForm.value.permissions,
    });
    newTokenValue.value = res.data.token;
    showTokenForm.value = false;
    await load();
  } catch (err) {
    toast.error(err.message);
  }
}

async function handleRevokeToken(tokenId) {
  pendingTokenId.value = String(tokenId || "");
  if (!pendingTokenId.value) {
    return;
  }

  pendingTokenAction.value = "revoke";
  showTokenActionModal.value = true;
}

async function handleDeleteToken(tokenId) {
  pendingTokenId.value = String(tokenId || "");
  if (!pendingTokenId.value) {
    return;
  }

  pendingTokenAction.value = "delete";
  showTokenActionModal.value = true;
}

async function confirmTokenAction() {
  if (!pendingTokenId.value || processingTokenAction.value) {
    return;
  }

  processingTokenAction.value = true;
  try {
    await api.tokens.delete(pendingTokenId.value);
    toast.success(
      pendingTokenAction.value === "delete"
        ? t("tokens.tokenDeleted")
        : t("tokens.tokenRevoked"),
    );
    showTokenActionModal.value = false;
    pendingTokenId.value = "";
    await load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    processingTokenAction.value = false;
  }
}

function clonePermissions(permissions) {
  return JSON.parse(JSON.stringify(permissions ?? []));
}

onMounted(load);
</script>
