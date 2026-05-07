<template>
  <div class="app-shell flex h-screen overflow-hidden">
    <!-- Mobile sidebar backdrop -->
    <Transition name="backdrop">
      <div
        v-if="sidebarOpen"
        class="fixed inset-0 z-30 bg-black/50 lg:hidden"
        @click="sidebarOpen = false"
      />
    </Transition>

    <!-- Sidebar -->
    <Transition name="sidebar">
      <aside
        class="fixed inset-y-0 left-0 z-40 w-56 flex-shrink-0 bg-sidebar flex flex-col lg:static lg:translate-x-0 lg:z-auto"
        :class="
          sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
        "
      >
        <div class="px-5 pt-6 pb-4 flex items-center justify-between">
          <img :src="logoSrc" alt="CometCMS" class="brand-logo h-8 w-auto" />
          <button
            class="sidebar-action lg:hidden transition-colors p-1"
            @click="sidebarOpen = false"
          >
            <Icon icon="mdi:close" class="w-5 h-5" />
          </button>
        </div>

        <nav class="flex-1 px-3 space-y-0.5 overflow-y-auto pb-4">
          <router-link
            to="/dashboard"
            class="nav-link"
            @click="sidebarOpen = false"
          >
            <Icon icon="mdi:home-outline" class="w-4 h-4 opacity-60" />
            {{ t("app.nav.dashboard") }}
          </router-link>

          <router-link
            v-if="auth.can('schema.read')"
            to="/content-types"
            class="nav-link"
            @click="sidebarOpen = false"
          >
            <Icon icon="mdi:view-list-outline" class="w-4 h-4 opacity-60" />
            {{ t("app.nav.contentTypes") }}
          </router-link>

          <router-link
            to="/media"
            class="nav-link"
            @click="sidebarOpen = false"
          >
            <Icon
              icon="mdi:image-multiple-outline"
              class="w-4 h-4 opacity-60"
            />
            {{ t("app.nav.media") }}
          </router-link>

          <template
            v-if="collectionTypes.length > 0 || allSidebarTypes.length === 0"
          >
            <div
              class="sidebar-label pt-3 pb-1 px-3 text-xs font-semibold uppercase tracking-wider"
            >
              {{ t("app.nav.collections") }}
            </div>

            <router-link
              v-for="type in collectionTypes"
              :key="type.name"
              :to="`/content/${type.name}`"
              class="nav-link"
              @click="sidebarOpen = false"
            >
              <Icon
                :icon="type.icon || defaultCollectionIcon"
                class="w-4 h-4 opacity-60 shrink-0"
              />
              {{ type.label }}
            </router-link>

            <router-link
              v-if="allSidebarTypes.length === 0 && auth.can('schema.create')"
              to="/content-types/new"
              class="nav-link text-slate-400 italic"
              @click="sidebarOpen = false"
            >
              <Icon icon="mdi:plus" class="w-4 h-4 opacity-60 shrink-0" />
              {{ t("app.nav.addType") }}
            </router-link>
          </template>

          <template v-if="pageTypes.length > 0">
            <div
              class="sidebar-label pt-3 pb-1 px-3 text-xs font-semibold uppercase tracking-wider"
            >
              {{ t("app.nav.single") }}
            </div>

            <router-link
              v-for="type in pageTypes"
              :key="type.name"
              :to="`/content/${type.name}`"
              class="nav-link"
              @click="sidebarOpen = false"
            >
              <Icon
                :icon="type.icon || defaultPageIcon"
                class="w-4 h-4 opacity-60 shrink-0"
              />
              {{ type.label }}
            </router-link>
          </template>

          <template v-if="auth.can('users.read') || auth.can('backups.read')">
            <div
              class="sidebar-label pt-3 pb-1 px-3 text-xs font-semibold uppercase tracking-wider"
            >
              {{ t("app.nav.system") }}
            </div>

            <router-link
              v-if="auth.can('users.read')"
              to="/users"
              class="nav-link"
              @click="sidebarOpen = false"
            >
              <Icon
                icon="mdi:account-group-outline"
                class="w-4 h-4 opacity-60"
              />
              {{ t("app.nav.users") }}
            </router-link>

            <router-link
              v-if="auth.can('backups.read')"
              to="/backups"
              class="nav-link"
              @click="sidebarOpen = false"
            >
              <Icon icon="mdi:backup-restore" class="w-4 h-4 opacity-60" />
              {{ t("app.nav.backupRestore") }}
            </router-link>
          </template>

          <div
            class="sidebar-label pt-3 pb-1 px-3 text-xs font-semibold uppercase tracking-wider"
          >
            {{ t("app.nav.developer") }}
          </div>

          <router-link
            v-if="auth.can('webhooks.manage')"
            to="/webhooks"
            class="nav-link"
            @click="sidebarOpen = false"
          >
            <Icon icon="mdi:webhook" class="w-4 h-4 opacity-60" />
            {{ t("app.nav.webhooks") }}
          </router-link>

          <router-link
            v-if="auth.can('tokens.read')"
            to="/api-tokens"
            class="nav-link"
            @click="sidebarOpen = false"
          >
            <Icon icon="mdi:key-chain" class="w-4 h-4 opacity-60" />
            {{ t("app.nav.apiTokens") }}
          </router-link>

        </nav>

        <!-- User footer -->
        <div class="px-4 py-3 border-t border-sidebar-border">
          <div class="flex items-center justify-between gap-2">
            <router-link
              to="/profile"
              class="sidebar-profile-link flex min-w-0 items-center gap-2 text-xs transition-colors"
              @click="sidebarOpen = false"
            >
              <div
                class="w-8 h-8 shrink-0 rounded-full overflow-hidden bg-theme-600 flex items-center justify-center text-white text-xs font-semibold select-none"
              >
                <img
                  v-if="auth.user?.has_avatar"
                  :src="`/admin/api/users/${auth.user.id}/avatar?v=${avatarVersion}`"
                  class="w-full h-full object-cover"
                  :alt="auth.user?.username"
                />
                <span v-else>{{
                  auth.user?.username?.[0]?.toUpperCase()
                }}</span>
              </div>
              <span class="truncate">{{
                auth.user?.display_name || auth.user?.username
              }}</span>
            </router-link>
            <button
              type="button"
              :title="t('app.actions.logout')"
              :aria-label="t('app.actions.logout')"
              class="sidebar-action p-1.5 rounded-lg transition-colors shrink-0 hover:bg-sidebar-hover"
              @click="handleLogout"
            >
              <Icon icon="mdi:logout" class="w-4 h-4" />
            </button>
          </div>
          <router-link
            v-if="appVersion"
            to="/update"
            class="sidebar-footer-text mt-3 block text-[11px] leading-none transition-colors"
            @click="sidebarOpen = false"
          >
            {{ t("app.version", { version: appVersion }) }}
          </router-link>
        </div>
      </aside>
    </Transition>

    <!-- Main area -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Mobile header bar -->
      <header
        class="lg:hidden flex items-center gap-3 px-4 py-3 bg-sidebar border-b border-sidebar-border shrink-0"
      >
        <button
          class="sidebar-action transition-colors p-1 -ml-1"
          @click="sidebarOpen = true"
        >
          <Icon icon="mdi:menu" class="w-6 h-6" />
        </button>
        <img :src="logoSrc" alt="CometCMS" class="brand-logo h-7 w-auto" />
      </header>

      <!-- Toast notifications -->
      <div class="fixed top-4 right-4 z-50 space-y-2 pointer-events-none">
        <transition-group name="toast">
          <div
            v-for="toast in toastStore.toasts"
            :key="toast.id"
            class="pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-sm font-medium"
            :class="
              toast.type === 'error'
                ? 'bg-red-600 text-white'
                : 'bg-slate-900 text-white'
            "
          >
            {{ toast.message }}
          </div>
        </transition-group>
      </div>

      <main class="flex-1 overflow-y-auto">
        <div class="max-w-7xl mx-auto w-full px-4 py-6 sm:px-8 sm:py-8">
          <slot />
        </div>
      </main>
      <ApiEndpointFooter />
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { Icon } from "@iconify/vue";
import { useAuthStore } from "../stores/auth.js";
import { useToastStore } from "../stores/toast.js";
import { useContentTypesStore } from "../stores/contentTypes.js";
import { api } from "../api/index.js";
import { logoForTheme } from "../theme.js";
import { useI18n } from "../i18n/index.js";
import ApiEndpointFooter from "./ApiEndpointFooter.vue";

const auth = useAuthStore();
const router = useRouter();
const toastStore = useToastStore();
const typesStore = useContentTypesStore();
const { t } = useI18n();
const defaultCollectionIcon = "mdi:file-document-outline";
const defaultPageIcon = "mdi:file-document-edit-outline";
const avatarVersion = ref(Date.now());
const appVersion = ref("");
const sidebarOpen = ref(false);
const assetBase = import.meta.env.BASE_URL;
const logoSrc = computed(() => logoForTheme(auth.user?.theme, assetBase));
const allSidebarTypes = computed(() =>
  typesStore.list.length > 0
    ? typesStore.list
    : (auth.user?.accessible_content_types ?? []),
);
const collectionTypes = computed(() =>
  allSidebarTypes.value
    .filter((type) => !type.singleton)
    .filter((type) => auth.can("content.read", `content:${type.name}:*`)),
);
const pageTypes = computed(() =>
  allSidebarTypes.value
    .filter((type) => type.singleton)
    .filter((type) => auth.can("content.read", `content:${type.name}:*`)),
);

onMounted(() => {
  typesStore.fetch();
  fetchAppInfo();
});

async function fetchAppInfo() {
  try {
    const res = await api.appInfo();
    appVersion.value = res.data?.version ?? "";
  } catch {
    appVersion.value = "";
  }
}

async function handleLogout() {
  await auth.logout();
  router.push("/login");
}
</script>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.25s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

/* Sidebar slide animation (mobile) */
.sidebar-enter-active,
.sidebar-leave-active {
  transition: transform 0.25s ease;
}

.sidebar-enter-from,
.sidebar-leave-to {
  transform: translateX(-100%);
}

/* Backdrop fade */
.backdrop-enter-active,
.backdrop-leave-active {
  transition: opacity 0.25s ease;
}

.backdrop-enter-from,
.backdrop-leave-to {
  opacity: 0;
}
</style>
