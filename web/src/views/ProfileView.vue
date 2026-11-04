<template>
  <div>
    <PageHeader :title="t('profile.title')" :description="t('profile.description')" />

    <div class="space-y-6">
      <!-- Avatar -->
      <div class="card p-6">
        <h2 class="card-title mb-4">
          {{ t("profile.picture.title") }}
        </h2>
        <AvatarUpload
          :src="
            auth.user?.has_avatar
              ? `${ADMIN_API_BASE}/users/${auth.user.id}/avatar?v=${avatarVersion}`
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
        <h2 class="card-title mb-4">
          {{ t("profile.info.title") }}
        </h2>
        <form @submit.prevent="handleSaveProfile" class="space-y-4">
          <div>
            <label for="profile-username" class="form-label"
              >{{ t("profile.username") }}
              <span class="text-slate-400 font-normal">{{
                t("profile.usernameLocked")
              }}</span></label
            >
            <input
              id="profile-username"
              :value="auth.user?.username"
              type="text"
              disabled
              class="form-input w-full rounded-lg border-slate-300 text-sm bg-slate-50 text-slate-400 cursor-not-allowed"
            />
          </div>
          <div>
            <label for="profile-display-name" class="form-label">{{ t("profile.displayName") }}</label>
            <input
              id="profile-display-name"
              v-model="profileForm.display_name"
              type="text"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
              :placeholder="t('profile.displayNamePlaceholder')"
            />
          </div>
          <div>
            <label for="profile-email" class="form-label">{{ t("profile.email") }}</label>
            <input
              id="profile-email"
              v-model="profileForm.email"
              type="email"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
              placeholder="you@example.com"
            />
          </div>
          <div>
            <div id="profile-color-mode-label" class="form-label">{{ t("profile.colorMode") }}</div>
            <div class="max-w-xs" role="group" aria-labelledby="profile-color-mode-label">
              <SegmentedControl
                v-model="profileForm.color_mode"
                :options="MODES.map((mode) => ({ value: mode, label: t(`colorMode.${mode}`), icon: modeIcons[mode], showLabel: true }))"
              />
            </div>
          </div>
          <div>
            <div id="profile-theme-label" class="form-label">{{ t("profile.theme") }}</div>
            <div role="group" aria-labelledby="profile-theme-label" class="flex items-center gap-3">
              <div class="flex gap-2">
                <button
                  v-for="theme in THEMES"
                  :key="theme.value"
                  type="button"
                  @click="profileForm.theme = theme.value"
                  :class="[
                    'h-8 w-8 rounded-full border-2 transition-all',
                    themeColors[theme.value],
                    profileForm.theme === theme.value
                      ? 'border-slate-900 scale-110 shadow-md'
                      : 'border-transparent hover:border-slate-300',
                  ]"
                  :aria-label="t(`theme.${theme.value}`)"
                  :title="t(`theme.${theme.value}`)"
                />
              </div>
              <span class="text-sm text-slate-600">
                {{ t(`theme.${profileForm.theme}`) }}
              </span>
            </div>
          </div>
          <div>
            <label for="profile-language" class="form-label">{{ t("profile.language") }}</label>
            <select
              id="profile-language"
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
        <h2 class="card-title mb-4">
          {{ t("profile.password.title") }}
        </h2>
        <form @submit.prevent="handleChangePassword" class="space-y-4">
          <div>
            <label for="profile-current-password" class="form-label">{{
              t("profile.password.current")
            }}</label>
            <input
              id="profile-current-password"
              v-model="pwForm.old_password"
              type="password"
              required
              autocomplete="current-password"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
            />
          </div>
          <div>
            <label for="profile-new-password" class="form-label">{{ t("profile.password.new") }}</label>
            <input
              id="profile-new-password"
              v-model="pwForm.password"
              type="password"
              required
              minlength="8"
              autocomplete="new-password"
              class="form-input w-full rounded-lg border-slate-300 text-sm"
            />
          </div>
          <div>
            <label for="profile-confirm-password" class="form-label">{{
              t("profile.password.confirm")
            }}</label>
            <input
              id="profile-confirm-password"
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
import PageHeader from "../components/PageHeader.vue";
import { ADMIN_API_BASE } from "../basePath.js";
import { computed, ref, onMounted, onBeforeUnmount } from "vue";
import AvatarUpload from "../components/AvatarUpload.vue";
import ConfirmModal from "../components/ConfirmModal.vue";
import ToggleSwitch from "../components/ToggleSwitch.vue";
import { api } from "../api/index.js";
import { useAuthStore } from "../stores/auth.js";
import { useToastStore } from "../stores/toast.js";
import { useApiEndpointStore } from "../stores/apiEndpoint.js";
import SegmentedControl from "../components/site/SegmentedControl.vue";
import { applyTheme, DEFAULT_MODE, DEFAULT_THEME, MODES, THEMES } from "../theme.js";

const modeIcons = {
  light: "mdi:white-balance-sunny",
  dark: "mdi:weather-night",
  auto: "mdi:theme-light-dark",
};

const themeColors = {
  blue: "bg-blue-500",
  green: "bg-green-500",
  purple: "bg-purple-500",
  orange: "bg-orange-500",
  red: "bg-red-500",
  cyan: "bg-cyan-500",
};
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
  color_mode: DEFAULT_MODE,
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
  profileForm.value.color_mode = auth.user?.color_mode ?? DEFAULT_MODE;
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
      color_mode: profileForm.value.color_mode,
      language: profileForm.value.language,
      show_api_footer: profileForm.value.show_api_footer,
    });
    if (auth.user) {
      auth.user.display_name = res.data.display_name;
      auth.user.email = res.data.email;
      auth.user.theme = res.data.theme;
      auth.user.color_mode = res.data.color_mode;
      auth.user.language = res.data.language;
      auth.user.show_api_footer = res.data.show_api_footer;
      applyTheme(res.data.theme, res.data.color_mode);
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
