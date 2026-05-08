<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-slate-900">{{ t("tokens.title") }}</h1>
      <button @click="openTokenForm" class="btn-primary">
        {{ t("tokens.newToken") }}
      </button>
    </div>

    <div v-if="showTokenForm" class="card p-6 mb-6 space-y-4">
      <h2 class="text-sm font-semibold text-slate-700">
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

    <div v-else class="card p-5">
      <div v-if="tokens.length === 0" class="text-sm text-slate-400">
        {{ t("tokens.noTokens") }}
      </div>
      <TokenCard
        v-for="token in tokens"
        :key="token.id"
        :token="token"
        @revoke="handleRevokeToken(token.id)"
      />
    </div>

    <ConfirmModal
      v-model="showRevokeModal"
      :title="t('tokens.revokeConfirm')"
      :message="t('tokens.revokeMessage')"
      :confirm-label="t('tokens.revoke')"
      :loading="revokingToken"
      @confirm="confirmRevokeToken"
    />
  </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import LoadingSpinner from "../components/LoadingSpinner.vue";
import PermissionGrantsEditor from "../components/PermissionGrantsEditor.vue";
import TokenCard from "../components/TokenCard.vue";
import { api } from "../api/index.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";

const toast = useToastStore();
const { t } = useI18n();
const loading = ref(true);
const tokens = ref([]);
const showTokenForm = ref(false);
const newTokenValue = ref("");
const showRevokeModal = ref(false);
const revokingToken = ref(false);
const pendingRevokeTokenId = ref("");

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
  pendingRevokeTokenId.value = String(tokenId || "");
  if (!pendingRevokeTokenId.value) {
    return;
  }

  showRevokeModal.value = true;
}

async function confirmRevokeToken() {
  if (!pendingRevokeTokenId.value || revokingToken.value) {
    return;
  }

  revokingToken.value = true;
  try {
    await api.tokens.delete(pendingRevokeTokenId.value);
    toast.success(t("tokens.tokenRevoked"));
    showRevokeModal.value = false;
    pendingRevokeTokenId.value = "";
    await load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    revokingToken.value = false;
  }
}

function clonePermissions(permissions) {
  return JSON.parse(JSON.stringify(permissions ?? []));
}

onMounted(load);
</script>
