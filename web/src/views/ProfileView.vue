<template>
  <div>
    <div class="flex items-center gap-3 mb-6">
      <h1 class="text-2xl font-bold text-slate-900">
        {{ t("profile.title") }}
      </h1>
    </div>

    <div class="space-y-6">
      <!-- Avatar -->
      <div class="card p-6">
        <h2 class="text-sm font-semibold text-slate-700 mb-4">
          {{ t("profile.picture.title") }}
        </h2>
        <AvatarUpload
          :src="
            auth.user?.has_avatar
              ? `/admin/api/users/${auth.user.id}/avatar?v=${avatarVersion}`
              : null
          "
          :fallback="auth.user?.username?.[0]?.toUpperCase()"
          :upload-title="t('app.profile.changePicture')"
          :instructions="t('profile.picture.instructions')"
          :requirements="t('profile.picture.requirements')"
          :remove-label="t('profile.picture.remove')"
          @upload="handleAvatarUpload"
          @delete="showDeleteAvatarModal = true"
        />
      </div>

      <!-- Profile info -->
      <div class="card p-6">
        <h2 class="text-sm font-semibold text-slate-700 mb-4">
          {{ t("profile.info.title") }}
        </h2>
        <form @submit.prevent="handleSaveProfile" class="space-y-4">
          <div>
            <label class="form-label"
              >{{ t("profile.username") }}
              <span class="text-slate-400 font-normal">{{
                t("profile.usernameLocked")
              }}</span></label
            >
            <input
              :value="auth.user?.username"
              type="text"
              disabled
              class="form-input w-full rounded-lg border-slate-300 text-sm bg-slate-50 text-slate-400 cursor-not-allowed"
            />
          </div>
          <div>
            <label class="form-label">{{ t("profile.displayName") }}</label>
            <input
              v-model="profileForm.display_name"
              type="text"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
              :placeholder="t('profile.displayNamePlaceholder')"
            />
          </div>
          <div>
            <label class="form-label">{{ t("profile.email") }}</label>
            <input
              v-model="profileForm.email"
              type="email"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
              placeholder="you@example.com"
            />
          </div>
          <div>
            <label class="form-label">{{ t("profile.theme") }}</label>
            <select
              v-model="profileForm.theme"
              class="form-select w-full rounded-lg border-slate-300 text-sm"
            >
              <option
                v-for="theme in THEMES"
                :key="theme.value"
                :value="theme.value"
              >
                {{ t(`theme.${theme.value}`) }}
              </option>
            </select>
          </div>
          <div>
            <label class="form-label">{{ t("profile.language") }}</label>
            <select
              v-model="profileForm.language"
              class="form-select w-full rounded-lg border-slate-300 text-sm"
            >
              <option
                v-for="language in languageOptions"
                :key="language.value"
                :value="language.value"
              >
                {{ language.label }}
              </option>
            </select>
          </div>
          <div
            v-if="canManageApiTokens"
            class="flex items-start justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3"
          >
            <div>
              <label class="text-sm font-medium text-slate-700">{{
                t("profile.apiFooter")
              }}</label>
              <p class="mt-1 text-xs text-slate-500">
                {{ t("profile.apiFooterDescription") }}
              </p>
            </div>
            <ToggleSwitch
              v-model="profileForm.show_api_footer"
              class="mt-0.5"
              :aria-label="t('profile.apiFooter')"
            />
          </div>
          <div v-if="profileError" class="text-sm text-red-600">
            {{ profileError }}
          </div>
          <button type="submit" :disabled="profileSaving" class="btn-primary">
            {{ profileSaving ? t("common.saving") : t("profile.save") }}
          </button>
        </form>
      </div>

      <!-- Password change -->
      <div class="card p-6">
        <h2 class="text-sm font-semibold text-slate-700 mb-4">
          {{ t("profile.password.title") }}
        </h2>
        <form @submit.prevent="handleChangePassword" class="space-y-4">
          <div>
            <label class="form-label">{{
              t("profile.password.current")
            }}</label>
            <input
              v-model="pwForm.old_password"
              type="password"
              required
              autocomplete="current-password"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
            />
          </div>
          <div>
            <label class="form-label">{{ t("profile.password.new") }}</label>
            <input
              v-model="pwForm.password"
              type="password"
              required
              minlength="8"
              autocomplete="new-password"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
            />
          </div>
          <div>
            <label class="form-label">{{
              t("profile.password.confirm")
            }}</label>
            <input
              v-model="pwForm.confirm"
              type="password"
              required
              minlength="8"
              autocomplete="new-password"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
            />
          </div>
          <div v-if="pwError" class="text-sm text-red-600">{{ pwError }}</div>
          <button type="submit" :disabled="pwSaving" class="btn-primary">
            {{ pwSaving ? t("common.saving") : t("profile.password.change") }}
          </button>
        </form>
      </div>
    </div>

    <ConfirmModal
      v-model="showDeleteAvatarModal"
      :title="t('profile.picture.removeTitle')"
      :message="t('profile.picture.removeMessage')"
      :confirm-label="t('common.remove')"
      @confirm="handleDeleteAvatar"
    />
  </div>
</template>

<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from "vue";
import AvatarUpload from "../components/AvatarUpload.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import ToggleSwitch from "../components/ToggleSwitch.vue";
import { api } from "../api/index.js";
import { useAuthStore } from "../stores/auth.js";
import { useToastStore } from "../stores/toast.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import { applyTheme, DEFAULT_THEME, THEMES } from "../theme.js";
import { DEFAULT_ADMIN_LOCALE, setLocale, useI18n } from "../i18n/index.js";
import { userAdminEndpoint } from "../composables/apiEndpoint.js";

const auth = useAuthStore();
const toast = useToastStore();
const apiEndpointStore = useApiEndpointStore();
const { languageOptions, t } = useI18n();
const apiEndpointOwner = "profile";
const canManageApiTokens = computed(
  () =>
    auth.can("tokens.read") ||
    auth.can("tokens.create") ||
    auth.can("tokens.revoke"),
);

const avatarVersion = ref(Date.now());
const showDeleteAvatarModal = ref(false);

const profileForm = ref({
  display_name: "",
  email: "",
  theme: DEFAULT_THEME,
  language: DEFAULT_ADMIN_LOCALE,
  show_api_footer: true,
});
const profileError = ref("");
const profileSaving = ref(false);

const pwForm = ref({ old_password: "", password: "", confirm: "" });
const pwError = ref("");
const pwSaving = ref(false);

onMounted(() => {
  profileForm.value.display_name = auth.user?.display_name ?? "";
  profileForm.value.email = auth.user?.email ?? "";
  profileForm.value.theme = auth.user?.theme ?? DEFAULT_THEME;
  profileForm.value.language = auth.user?.language ?? DEFAULT_ADMIN_LOCALE;
  profileForm.value.show_api_footer = auth.user?.show_api_footer ?? true;

  if (auth.user?.id) {
    apiEndpointStore.setEndpoint(
      {
        label: "Profile",
        authLabel: "AUTH",
        url: userAdminEndpoint(auth.user.id),
      },
      apiEndpointOwner,
    );
  }
});

onBeforeUnmount(() => {
  apiEndpointStore.clearEndpoint(apiEndpointOwner);
});

async function handleAvatarUpload(file) {
  const formData = new FormData();
  formData.append("file", file);
  try {
    await api.profile.uploadAvatar(formData);
    if (auth.user) auth.user.has_avatar = true;
    avatarVersion.value = Date.now();
    toast.success(t("app.profile.pictureUpdated"));
  } catch (err) {
    toast.error(err.message ?? t("app.errors.uploadFailed"));
  }
}

async function handleDeleteAvatar() {
  try {
    await api.profile.deleteAvatar();
    if (auth.user) auth.user.has_avatar = false;
    avatarVersion.value = Date.now();
    toast.success(t("profile.picture.removed"));
  } catch (err) {
    toast.error(err.message);
  }
}

async function handleSaveProfile() {
  profileError.value = "";
  profileSaving.value = true;
  try {
    const res = await api.profile.update({
      display_name: profileForm.value.display_name,
      email: profileForm.value.email,
      theme: profileForm.value.theme,
      language: profileForm.value.language,
      show_api_footer: profileForm.value.show_api_footer,
    });
    if (auth.user) {
      auth.user.display_name = res.data.display_name;
      auth.user.email = res.data.email;
      auth.user.theme = res.data.theme;
      auth.user.language = res.data.language;
      auth.user.show_api_footer = res.data.show_api_footer;
      applyTheme(res.data.theme);
      setLocale(res.data.language);
    }
    toast.success(t("profile.saved"));
  } catch (err) {
    profileError.value = err.message;
  } finally {
    profileSaving.value = false;
  }
}

async function handleChangePassword() {
  pwError.value = "";
  if (pwForm.value.password !== pwForm.value.confirm) {
    pwError.value = t("profile.password.mismatch");
    return;
  }
  pwSaving.value = true;
  try {
    await api.profile.update({
      old_password: pwForm.value.old_password,
      password: pwForm.value.password,
    });
    pwForm.value = { old_password: "", password: "", confirm: "" };
    toast.success(t("profile.password.changed"));
  } catch (err) {
    pwError.value = err.message;
  } finally {
    pwSaving.value = false;
  }
}
</script>
