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
        <div
          v-if="workspaces.length > 0 && auth.can('workspaces.read')"
          class="px-3 pb-2 relative"
          ref="switcherRef"
        >
          <button
            @click="workspaceSwitcherOpen = !workspaceSwitcherOpen"
            class="w-full flex items-center gap-2.5 px-2 py-1.5 rounded-lg transition-colors hover:bg-white/10 group"
          >
            <div
              class="w-7 h-7 shrink-0 rounded-md overflow-hidden bg-theme-600 flex items-center justify-center text-white text-[10px] font-bold select-none leading-none"
            >
              <img
                v-if="activeWorkspace.has_icon"
                :src="`/admin/api/workspaces/${selectedWorkspace}/icon?v=${iconVersions[selectedWorkspace] ?? 0}`"
                class="w-full h-full object-cover"
                :alt="activeWorkspaceLabel"
              />
              <span v-else>{{ workspaceInitials }}</span>
            </div>
            <div class="flex-1 min-w-0 text-left">
              <div
                class="text-xs font-semibold truncate leading-tight"
                style="color: rgb(var(--color-sidebar-text))"
              >
                {{ activeWorkspaceLabel }}
              </div>
            </div>
            <Icon
              icon="mdi:unfold-more-horizontal"
              class="w-4 h-4 opacity-40 shrink-0 group-hover:opacity-70 transition-opacity"
              style="color: rgb(var(--color-sidebar-text))"
            />
          </button>

          <Transition name="ws-dropdown">
            <div
              v-if="workspaceSwitcherOpen"
              class="absolute left-1 right-1 top-full z-50 mt-0.5 rounded-lg border border-slate-200 bg-white shadow-lg overflow-hidden"
            >
              <div class="py-1">
                <button
                  v-for="workspace in workspaces"
                  :key="workspace.slug"
                  @click="switchWorkspace(workspace.slug)"
                  class="w-full flex items-center gap-2.5 px-3 py-2 text-xs text-left transition-colors"
                  :class="
                    workspace.slug === selectedWorkspace
                      ? 'bg-theme-50 text-theme-700'
                      : 'text-slate-700 hover:bg-slate-50'
                  "
                >
                  <div
                    class="w-6 h-6 shrink-0 rounded-md overflow-hidden bg-theme-600 flex items-center justify-center text-white text-[10px] font-bold select-none leading-none"
                  >
                    <img
                      v-if="workspace.has_icon"
                      :src="`/admin/api/workspaces/${workspace.slug}/icon?v=${iconVersions[workspace.slug] ?? 0}`"
                      class="w-full h-full object-cover"
                      :alt="workspace.label"
                    />
                    <span v-else>{{
                      workspaceInitials_(workspace.label)
                    }}</span>
                  </div>
                  <span class="flex-1 truncate font-medium">{{
                    workspace.label
                  }}</span>
                  <Icon
                    v-if="workspace.slug === selectedWorkspace"
                    icon="mdi:check"
                    class="w-3.5 h-3.5 shrink-0 text-theme-600"
                  />
                </button>
              </div>
              <template v-if="auth.can('workspaces.manage')">
                <div class="border-t border-slate-100" />
                <div class="py-1">
                  <router-link
                    to="/workspaces"
                    @click="
                      workspaceSwitcherOpen = false;
                      sidebarOpen = false;
                    "
                    class="flex items-center gap-2 px-3 py-2 text-xs text-slate-500 hover:text-slate-700 hover:bg-slate-50 transition-colors"
                  >
                    <Icon icon="mdi:cog-outline" class="w-3.5 h-3.5" />
                    {{ t("workspaces.manageCta") }}
                  </router-link>
                </div>
              </template>
            </div>
          </Transition>
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
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { Icon } from "@iconify/vue";
import { useAuthStore } from "../stores/auth.js";
import { useToastStore } from "../stores/toast.js";
import { useContentTypesStore } from "../stores/contentTypes.js";
import {
  api,
  getActiveWorkspace,
  setActiveWorkspace,
  setDefaultWorkspace,
} from "../api/index.js";
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
const workspaces = ref([]);
const selectedWorkspace = ref(getActiveWorkspace());
const workspaceSwitcherOpen = ref(false);
const switcherRef = ref(null);
const iconVersions = ref({});
const workspaceSyncEvent = "cometcms:workspaces-updated";
const activeWorkspace = computed(
  () =>
    workspaces.value.find((w) => w.slug === selectedWorkspace.value) ?? {
      slug: selectedWorkspace.value,
      has_icon: false,
    },
);
const activeWorkspaceLabel = computed(
  () => activeWorkspace.value?.label ?? selectedWorkspace.value,
);
const workspaceInitials = computed(() =>
  workspaceInitials_(activeWorkspaceLabel.value),
);

function workspaceInitials_(label) {
  return String(label ?? "")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0].toUpperCase())
    .join("");
}

function handleSwitcherOutsideClick(e) {
  if (switcherRef.value && !switcherRef.value.contains(e.target)) {
    workspaceSwitcherOpen.value = false;
  }
}

function handleWorkspaceSync() {
  fetchWorkspaces();
}
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
  if (auth.can("workspaces.read")) {
    fetchWorkspaces();
  }
  typesStore.fetch();
  fetchAppInfo();
  document.addEventListener("click", handleSwitcherOutsideClick);
  window.addEventListener(workspaceSyncEvent, handleWorkspaceSync);
});

onBeforeUnmount(() => {
  document.removeEventListener("click", handleSwitcherOutsideClick);
  window.removeEventListener(workspaceSyncEvent, handleWorkspaceSync);
});

async function fetchWorkspaces() {
  try {
    const res = await api.workspaces.list();
    workspaces.value = res.data ?? [];
    const defaultWs = workspaces.value.find((w) => w.default);
    if (defaultWs) {
      setDefaultWorkspace(defaultWs.slug);
    }
    if (
      !workspaces.value.some(
        (workspace) => workspace.slug === selectedWorkspace.value,
      )
    ) {
      selectedWorkspace.value =
        defaultWs?.slug ?? workspaces.value[0]?.slug ?? selectedWorkspace.value;
      setActiveWorkspace(selectedWorkspace.value);
    }
  } catch {
    workspaces.value = [];
  }
}

async function switchWorkspace(slug) {
  workspaceSwitcherOpen.value = false;
  if (slug === selectedWorkspace.value) return;
  selectedWorkspace.value = slug;
  setActiveWorkspace(slug);
  typesStore.invalidate();
  await auth.refresh();
  await typesStore.fetch();
  router.push("/dashboard");
}

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

/* Workspace dropdown */
.ws-dropdown-enter-active,
.ws-dropdown-leave-active {
  transition:
    opacity 0.15s ease,
    transform 0.15s ease;
}

.ws-dropdown-enter-from,
.ws-dropdown-leave-to {
  opacity: 0;
  transform: translateY(-4px);
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
