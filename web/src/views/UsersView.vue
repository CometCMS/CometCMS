<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-slate-900">{{ t('users.title') }}</h1>
      <div class="flex items-center gap-2">
        <router-link v-if="auth.can('roles.read')" to="/roles" class="btn-secondary">
          {{ t('users.editRoles') }}
        </router-link>
        <button @click="showNewUser = !showNewUser" class="btn-primary">
          {{ t('users.newUser') }}
        </button>
      </div>
    </div>

    <!-- New user form -->
    <div v-if="showNewUser" class="card p-6 mb-6">
      <h2 class="text-sm font-semibold text-slate-700 mb-4">{{ t('users.createUser') }}</h2>
      <form @submit.prevent="handleCreateUser" class="grid grid-cols-3 gap-4">
        <div>
          <label class="form-label">{{ t('users.username') }}</label>
          <input v-model="newUser.username" type="text" required
            class="form-input w-full rounded-lg border-slate-300 text-sm" />
        </div>
        <div>
          <label class="form-label">{{ t('users.password') }}</label>
          <input v-model="newUser.password" type="password" required minlength="8"
            class="form-input w-full rounded-lg border-slate-300 text-sm" />
        </div>
        <div>
          <label class="form-label">{{ t('users.rolePreset') }}</label>
          <select v-model="newUser.role" class="form-select w-full rounded-lg border-slate-300 text-sm" required>
            <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.label }}</option>
          </select>
        </div>
        <div v-if="createError" class="col-span-3 text-red-600 text-sm">
          {{ createError }}
        </div>
        <div class="col-span-3 flex gap-2">
          <button type="submit" class="btn-primary">{{ t('users.create') }}</button>
          <button type="button" @click="showNewUser = false" class="btn-secondary">
            {{ t('common.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <!-- Users list -->
    <LoadingSpinner v-if="loading" />

    <template v-else>
      <template v-for="role in roleGroups" :key="role.id">
        <template v-if="sortedUsers.some((u) => u.role === role.id)">
          <h2 class="text-sm font-semibold text-slate-500 tracking-wider mt-6 mb-3 capitalize">
            {{ role.label }}
          </h2>
          <div class="space-y-4">
            <div v-for="user in sortedUsers.filter((u) => u.role === role.id)" :key="user.id" class="card p-5">
              <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                  <div
                    class="w-9 h-9 rounded-full overflow-hidden bg-theme-600 flex items-center justify-center text-white text-sm font-semibold select-none shrink-0">
                    <img v-if="user.has_avatar" :src="`/admin/api/users/${user.id}/avatar`"
                      class="w-full h-full object-cover" :alt="user.username" />
                    <span v-else>{{ user.username?.[0]?.toUpperCase() }}</span>
                  </div>
                  <div>
                    <p class="font-semibold text-slate-900">
                      {{ user.display_name || user.username }}
                    </p>
                    <p class="text-xs text-slate-500">
                      {{ user.username }} ·
                      <span>{{ roleLabel(user.role) }}</span>
                    </p>
                    <p v-if="user.email" class="text-xs text-slate-400">
                      {{ user.email }}
                    </p>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <button v-if="user.id !== currentUser?.id" @click="openEditUser(user)"
                    class="btn-secondary text-xs py-1 px-3">
                    {{ t('users.edit') }}
                  </button>
                  <button v-if="user.id !== currentUser?.id" @click="handleDeleteUser(user.id)"
                    class="btn-danger text-xs py-1 px-3">
                    {{ t('users.delete') }}
                  </button>
                </div>
              </div>

              <!-- Edit user form -->
              <div v-if="editForm.userId === user.id" class="border-t border-slate-100 pt-4 mb-4">
                <h3 class="text-xs font-semibold text-slate-600 uppercase tracking-wider mb-3">
                  {{ t('users.editUser') }}
                </h3>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="text-xs text-slate-500 block mb-0.5">{{ t('users.displayName') }}</label>
                    <input v-model="editForm.display_name" type="text"
                      class="form-input w-full rounded-lg border-slate-300 text-sm" />
                  </div>
                  <div>
                    <label class="text-xs text-slate-500 block mb-0.5">{{ t('users.email') }}</label>
                    <input v-model="editForm.email" type="email"
                      class="form-input w-full rounded-lg border-slate-300 text-sm" />
                  </div>
                  <div>
                    <label class="text-xs text-slate-500 block mb-0.5">{{ t('users.rolePreset') }}</label>
                    <select v-model="editForm.role" class="form-select w-full rounded-lg border-slate-300 text-sm"
                      required>
                      <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.label }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="text-xs text-slate-500 block mb-0.5">{{ t('users.newPassword') }}
                      <span class="text-slate-400">{{ t('users.leaveBlank') }}</span></label>
                    <input v-model="editForm.password" type="password" minlength="8" autocomplete="new-password"
                      class="form-input w-full rounded-lg border-slate-300 text-sm" />
                  </div>
                </div>
                <div v-if="editError" class="mt-2 text-xs text-red-600">
                  {{ editError }}
                </div>
                <div class="mt-3 flex gap-2">
                  <button @click="handleUpdateUser(user.id)" class="btn-primary text-xs py-1.5 px-3">
                    {{ t('users.saveChanges') }}
                  </button>
                  <button @click="editForm.userId = null" class="btn-secondary text-xs py-1.5 px-3">
                    {{ t('common.cancel') }}
                  </button>
                </div>
              </div>

            </div>
            <!-- end card -->
          </div>
          <!-- end space-y-4 -->
        </template><!-- end role group --> </template><!-- end role loop -->
    </template><!-- end v-else -->

    <ConfirmModal v-model="showDeleteUserModal" :title="t('users.deleteConfirm')" :confirm-label="t('users.delete')"
      :loading="deletingUser" @confirm="executeDeleteUser" />
  </div>
</template>

<script setup>
import LoadingSpinner from "../components/LoadingSpinner.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import { ref, computed, onBeforeUnmount, onMounted } from "vue";
import { api } from "../api/index.js";
import { usersAdminEndpoint } from "../composables/apiEndpoint.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import { useAuthStore } from "../stores/auth.js";
import { useToastStore } from "../stores/toast.js";
import { useI18n } from "../i18n/index.js";

const auth = useAuthStore();
const apiEndpointStore = useApiEndpointStore();
const toast = useToastStore();
const { t } = useI18n();
const apiEndpointOwner = "users";
const loading = ref(true);
const users = ref([]);
const roles = ref([]);
const currentUser = auth.user;

const roleOrder = computed(() => Object.fromEntries(roles.value.map((role, index) => [role.id, index])));
const sortedUsers = computed(() =>
  [...users.value].sort(
    (a, b) => (roleOrder.value[a.role] ?? 100) - (roleOrder.value[b.role] ?? 100),
  ),
);

const roleGroups = computed(() => {
  const known = roles.value.filter((role) => users.value.some((user) => user.role === role.id));
  const unknown = [...new Set(users.value.map((user) => user.role))]
    .filter((id) => id && !roles.value.some((role) => role.id === id))
    .map((id) => ({ id, label: id }));

  return [...known, ...unknown];
});

const showNewUser = ref(false);
const newUser = ref({
  username: "",
  password: "",
  role: "viewer",
});
const createError = ref("");

const editForm = ref({
  userId: null,
  display_name: "",
  email: "",
  role: "viewer",
  password: "",
});
const editError = ref("");

function openEditUser(user) {
  editError.value = "";
  editForm.value = {
    userId: user.id,
    display_name: user.display_name ?? "",
    email: user.email ?? "",
    role: user.role,
    password: "",
  };
}

async function handleUpdateUser(id) {
  editError.value = "";
  try {
    const payload = {
      display_name: editForm.value.display_name,
      email: editForm.value.email,
      role: editForm.value.role,
    };
    if (editForm.value.password) payload.password = editForm.value.password;
    await api.users.update(id, payload);
    toast.success(t("users.updated"));
    editForm.value.userId = null;
    await load();
  } catch (err) {
    editError.value = err.message;
  }
}

async function load() {
  loading.value = true;
  try {
    const userRes = await api.users.list();
    users.value = userRes.data;

    try {
      roles.value = (await api.roles.list()).data ?? [];
    } catch {
      roles.value = [...new Set(users.value.map((user) => user.role))]
        .filter(Boolean)
        .map((id) => ({ id, label: id }));
    }
  } finally {
    loading.value = false;
  }
}

async function handleCreateUser() {
  createError.value = "";
  try {
    await api.users.create({
      username: newUser.value.username,
      password: newUser.value.password,
      role: newUser.value.role,
    });
    toast.success(t("users.userCreated"));
    showNewUser.value = false;
    newUser.value = {
      username: "",
      password: "",
      role: roles.value.some((role) => role.id === "viewer") ? "viewer" : roles.value[0]?.id ?? "",
    };
    await load();
  } catch (err) {
    createError.value = err.message;
  }
}

const showDeleteUserModal = ref(false);
const deleteTargetUserId = ref(null);
const deletingUser = ref(false);

function handleDeleteUser(id) {
  deleteTargetUserId.value = id;
  showDeleteUserModal.value = true;
}

async function executeDeleteUser() {
  deletingUser.value = true;
  try {
    await api.users.delete(deleteTargetUserId.value);
    showDeleteUserModal.value = false;
    toast.success(t("users.deleted"));
    await load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    deletingUser.value = false;
  }
}

function roleLabel(id) {
  return roles.value.find((role) => role.id === id)?.label ?? id;
}

onMounted(load);

onMounted(() => {
  apiEndpointStore.setEndpoint(
    {
      label: "Users",
      authLabel: "AUTH",
      url: usersAdminEndpoint(),
    },
    apiEndpointOwner,
  );
});

onBeforeUnmount(() => {
  apiEndpointStore.clearEndpoint(apiEndpointOwner);
});
</script>
