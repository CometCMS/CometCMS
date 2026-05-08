<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <router-link
          to="/users"
          class="text-sm text-slate-500 hover:text-slate-800 transition-colors"
        >
          {{ t("roles.usersTokens") }}
        </router-link>
        <h1 class="text-2xl font-bold text-slate-900 mt-1">
          {{ t("roles.title") }}
        </h1>
      </div>
      <button
        v-if="auth.can('roles.create')"
        @click="openCreate"
        class="btn-primary"
      >
        {{ t("roles.new") }}
      </button>
    </div>

    <LoadingSpinner v-if="loading" />

    <div v-else class="space-y-4">
      <div v-if="showCreate" class="card p-5">
        <h2 class="text-sm font-semibold text-slate-700 mb-4">
          {{ t("roles.createTitle") }}
        </h2>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label class="form-label">{{ t("roles.name") }}</label>
            <input
              v-model="createForm.label"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
              placeholder="Publisher"
            />
          </div>
          <div>
            <label class="form-label">{{ t("roles.roleId") }}</label>
            <input
              v-model="createForm.id"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
              placeholder="publisher"
            />
          </div>
          <div class="md:col-span-2">
            <label class="form-label">{{ t("roles.permissionGrants") }}</label>
            <PermissionGrantsEditor v-model="createForm.permissions" />
          </div>
        </div>
        <p v-if="createError" class="mt-3 text-sm text-red-600">
          {{ createError }}
        </p>
        <div class="mt-4 flex gap-2">
          <button @click="handleCreate" class="btn-primary">
            {{ t("roles.create") }}
          </button>
          <button @click="showCreate = false" class="btn-secondary">
            {{ t("common.cancel") }}
          </button>
        </div>
      </div>

      <div v-for="role in roles" :key="role.id" class="card p-5">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="font-semibold text-slate-900">{{ role.label }}</h2>
            <p class="text-xs text-slate-400">{{ role.id }}</p>
          </div>
          <div class="flex gap-2">
            <button
              v-if="auth.can('roles.update')"
              :disabled="role.locked"
              @click="openEdit(role)"
              class="btn-secondary text-xs py-1 px-3 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ t("roles.edit") }}
            </button>
            <button
              v-if="auth.can('roles.delete')"
              :disabled="role.locked"
              @click="handleDelete(role)"
              class="btn-danger text-xs py-1 px-3 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ t("roles.delete") }}
            </button>
          </div>
        </div>

        <div class="mt-3">
          <PermissionBadges :permissions="role.permissions" />
        </div>

        <div
          v-if="editForm.id === role.id"
          class="mt-4 border-t border-slate-100 pt-4"
        >
          <div class="grid gap-4 md:grid-cols-2">
            <div>
              <label class="form-label">{{ t("roles.name") }}</label>
              <input
                v-model="editForm.label"
                class="form-input w-full rounded-lg border-slate-300 text-sm"
              />
            </div>
            <div>
              <label class="form-label">{{ t("roles.roleId") }}</label>
              <input
                :value="editForm.id"
                disabled
                class="form-input w-full rounded-lg border-slate-200 bg-slate-50 text-sm"
              />
            </div>
            <div class="md:col-span-2">
              <label class="form-label">{{
                t("roles.permissionGrants")
              }}</label>
              <PermissionGrantsEditor v-model="editForm.permissions" />
            </div>
          </div>
          <p v-if="editError" class="mt-3 text-sm text-red-600">
            {{ editError }}
          </p>
          <div class="mt-4 flex gap-2">
            <button @click="handleUpdate(role.id)" class="btn-primary">
              {{ t("roles.saveChanges") }}
            </button>
            <button @click="editForm.id = null" class="btn-secondary">
              {{ t("common.cancel") }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <ConfirmModal
      v-model="showDeleteModal"
      :title="t('roles.deleteConfirm', { name: deleteTargetRole?.label })"
      :confirm-label="t('roles.delete')"
      :loading="deletingRole"
      @confirm="executeDelete"
    />
  </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import LoadingSpinner from "../components/LoadingSpinner.vue";
import PermissionBadges from "../components/PermissionBadges.vue";
import PermissionGrantsEditor from "../components/PermissionGrantsEditor.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import { api } from "../api/index.js";
import { useAuthStore } from "../stores/auth.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";

const auth = useAuthStore();
const toast = useToastStore();
const { t } = useI18n();
const loading = ref(true);
const roles = ref([]);
const showCreate = ref(false);
const createError = ref("");
const editError = ref("");

const createForm = ref({
  id: "",
  label: "",
  permissions: [],
});

const editForm = ref({
  id: null,
  label: "",
  permissions: [],
});

async function load() {
  loading.value = true;
  try {
    roles.value = (await api.roles.list()).data ?? [];
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  createError.value = "";
  createForm.value = {
    id: "",
    label: "",
    permissions: [],
  };
  showCreate.value = true;
}

function openEdit(role) {
  editError.value = "";
  editForm.value = {
    id: role.id,
    label: role.label,
    permissions: clonePermissions(role.permissions),
  };
}

async function handleCreate() {
  createError.value = "";
  try {
    await api.roles.create(createForm.value);
    toast.success(t("roles.created"));
    showCreate.value = false;
    await load();
  } catch (err) {
    createError.value = err.message;
  }
}

async function handleUpdate(id) {
  editError.value = "";
  try {
    await api.roles.update(id, {
      label: editForm.value.label,
      permissions: editForm.value.permissions,
    });
    toast.success(t("roles.updated"));
    editForm.value.id = null;
    await load();
    if (auth.user?.role === id) {
      await auth.refresh();
    }
  } catch (err) {
    editError.value = err.message;
  }
}

const showDeleteModal = ref(false);
const deleteTargetRole = ref(null);
const deletingRole = ref(false);

function handleDelete(role) {
  if (role.locked) return;
  deleteTargetRole.value = role;
  showDeleteModal.value = true;
}

async function executeDelete() {
  deletingRole.value = true;
  try {
    await api.roles.delete(deleteTargetRole.value.id);
    showDeleteModal.value = false;
    toast.success(t("roles.deleted"));
    await load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    deletingRole.value = false;
  }
}

function clonePermissions(permissions) {
  return JSON.parse(JSON.stringify(permissions ?? []));
}

onMounted(load);
</script>
