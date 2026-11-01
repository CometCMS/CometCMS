<template>
  <div class="app-shell flex h-screen overflow-hidden">
    <!-- Mobile sidebar backdrop -->
    <Transition name="backdrop">
      <div
        v-if="sidebarOpen"
        class="fixed inset-0 z-30 bg-black/50 lg:hidden"
        aria-hidden="true"
        @click="sidebarOpen = false"
      />
    </Transition>

    <!-- Sidebar -->
    <Transition name="sidebar">
      <aside
        id="primary-navigation"
        class="admin-sidebar fixed inset-y-0 left-0 z-40 w-[17.5rem] max-w-[calc(100vw-2rem)] flex-shrink-0 bg-sidebar border-r border-sidebar-border flex flex-col lg:static lg:translate-x-0 lg:z-20"
        :class="[
          sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
          { 'is-collapsed': sidebarCollapsed },
        ]"
      >
        <div class="sidebar-header flex h-16 shrink-0 items-center justify-between gap-2 px-3">
          <a :href="api.site.homePreviewUrl()" :title="t('site.preview.title')" class="sidebar-brand flex min-w-0 items-center" @click="sidebarOpen = false">
            <img :src="logoSrc" alt="CometCMS" class="brand-logo h-10 w-auto max-w-full object-contain" />
          </a>
          <button
            type="button"
            :aria-label="sidebarToggleLabel"
            :title="navigationCollapsed ? undefined : sidebarToggleLabel"
            v-tooltip="navigationCollapsed && { title: sidebarToggleLabel, placement: 'right' }"
            aria-controls="primary-navigation"
            :aria-expanded="!sidebarCollapsed"
            class="sidebar-action hidden h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors hover:bg-sidebar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-theme-500 lg:inline-flex"
            @click="sidebarCollapsed = !sidebarCollapsed"
          >
            <Icon :icon="sidebarCollapsed ? 'mdi:chevron-double-right' : 'mdi:chevron-double-left'" class="h-5 w-5" aria-hidden="true" />
          </button>
          <button
            type="button"
            :aria-label="t('app.actions.closeNavigation')"
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
          <component
            :is="canSwitchWorkspaces ? 'button' : 'div'"
            :type="canSwitchWorkspaces ? 'button' : undefined"
            :aria-expanded="canSwitchWorkspaces ? workspaceSwitcherOpen : undefined"
            :aria-label="`${t(canSwitchWorkspaces ? 'app.nav.switchWorkspace' : 'app.nav.workspace')}: ${activeWorkspaceLabel}`"
            v-tooltip="navigationCollapsed && { title: activeWorkspaceLabel, placement: 'right' }"
            :aria-haspopup="canSwitchWorkspaces ? 'menu' : undefined"
            @click="canSwitchWorkspaces && (workspaceSwitcherOpen = !workspaceSwitcherOpen)"
            class="sidebar-workspace sidebar-surface h-9 w-full flex items-center gap-2.5 rounded-lg px-2 group"
          >
            <div
              class="w-6 h-6 shrink-0 rounded-md overflow-hidden bg-theme-600 flex items-center justify-center text-white text-[10px] font-bold select-none leading-none shadow-sm"
            >
              <img
                v-if="activeWorkspace.has_icon"
                :src="`${ADMIN_API_BASE}/workspaces/${selectedWorkspace}/icon?v=${iconVersions[selectedWorkspace] ?? 0}`"
                class="w-full h-full object-cover"
                :alt="activeWorkspaceLabel"
              />
              <span v-else>{{ workspaceInitials }}</span>
            </div>
            <div class="sidebar-text flex-1 min-w-0 text-left">
              <div class="text-[13px] font-semibold truncate leading-tight">
                {{ activeWorkspaceLabel }}
              </div>
            </div>
            <Icon
              v-if="canSwitchWorkspaces"
              icon="mdi:unfold-more-horizontal"
              class="sidebar-text w-4 h-4 opacity-50 shrink-0 group-hover:opacity-80 transition-opacity"
            />
          </component>

          <Transition name="ws-dropdown">
            <div
              v-if="canSwitchWorkspaces && workspaceSwitcherOpen"
              role="menu"
              class="sidebar-menu absolute left-3 right-3 top-full z-50 mt-1 rounded-xl border border-slate-200 bg-white p-1 shadow-xl"
            >
              <div class="px-2.5 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-400">
                {{ t("app.nav.switchWorkspace") }}
              </div>
              <button
                v-for="workspace in workspaces"
                :key="workspace.slug"
                type="button"
                role="menuitemradio"
                :aria-checked="workspace.slug === selectedWorkspace"
                @click="switchWorkspace(workspace.slug)"
                class="w-full flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-left transition-colors"
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
                    :src="`${ADMIN_API_BASE}/workspaces/${workspace.slug}/icon?v=${iconVersions[workspace.slug] ?? 0}`"
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
                  class="w-4 h-4 shrink-0 text-theme-600"
                />
              </button>
              <template v-if="auth.can('workspaces.manage')">
                <div class="my-1 border-t border-slate-100" />
                <router-link
                  to="/website/workspaces"
                  @click="
                    workspaceSwitcherOpen = false;
                    sidebarOpen = false;
                  "
                  class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors"
                >
                  <Icon icon="mdi:cog-outline" class="w-4 h-4 text-slate-400" />
                  {{ t("workspaces.manageCta") }}
                </router-link>
              </template>
            </div>
          </Transition>
        </div>

        <div class="px-3 pb-1">
          <button
            type="button"
            class="sidebar-search sidebar-surface flex h-9 w-full items-center gap-2.5 rounded-lg px-2.5 text-sm"
            :aria-label="t('app.search.open')"
            v-tooltip="navigationCollapsed && { title: `${t('app.search.open')} (${shortcutLabel})`, placement: 'right' }"
            aria-haspopup="dialog"
            @click="paletteOpen = true; sidebarOpen = false"
          >
            <Icon icon="mdi:magnify" class="h-[18px] w-[18px] shrink-0 sidebar-muted" />
            <span class="sidebar-text flex-1 text-left sidebar-muted">{{ t("app.search.open") }}</span>
            <span class="sidebar-text sidebar-kbd">{{ shortcutLabel }}</span>
          </button>
        </div>

        <nav
          :aria-label="t('app.actions.primaryNavigation')"
          class="nav-scrollbar flex-1 overflow-y-auto px-3 pb-4"
        >
          <div v-for="group in navGroups" :key="group.key" class="sidebar-nav-group" role="group" :aria-label="group.label">
            <div v-if="group.label" class="sidebar-text sidebar-label" aria-hidden="true">{{ group.label }}</div>
            <div class="space-y-0.5" :class="{ 'pt-2': !group.label }">
              <template v-for="item in group.items" :key="item.key">
                <router-link
                  v-if="!item.children"
                  :to="item.to"
                  class="nav-link"
                  :class="{ 'is-active': isActive(item, route.path) }"
                  :aria-current="isActive(item, route.path) ? 'page' : undefined"
                  :aria-label="item.label"
                  v-tooltip="navigationCollapsed && { title: item.label, placement: 'right' }"
                  @click="sidebarOpen = false"
                >
                  <Icon :icon="item.icon" class="nav-icon" />
                  <span class="sidebar-text truncate">{{ item.label }}</span>
                </router-link>
                <div v-else>
                  <button
                    type="button"
                    class="nav-link w-full text-left"
                    :class="{ 'is-section-active': isActive(item, route.path) }"
                    :aria-label="item.label"
                    :aria-expanded="!navigationCollapsed && isSectionExpanded(item)"
                    :aria-controls="`nav-section-${item.key}`"
                    v-tooltip="navigationCollapsed && { title: item.label, placement: 'right' }"
                    @click="toggleNavSection(item)"
                  >
                    <Icon :icon="item.icon" class="nav-icon" />
                    <span class="sidebar-text min-w-0 flex-1 truncate">{{ item.label }}</span>
                    <Icon
                      icon="mdi:chevron-right"
                      class="sidebar-text nav-chevron"
                      :class="{ 'rotate-90': isSectionExpanded(item) }"
                      aria-hidden="true"
                    />
                  </button>
                  <div
                    v-show="!navigationCollapsed && isSectionExpanded(item)"
                    :id="`nav-section-${item.key}`"
                    class="nav-sub"
                    role="group"
                    :aria-label="item.label"
                  >
                    <router-link
                      v-for="child in item.children"
                      :key="child.key"
                      :to="child.to"
                      class="nav-sublink"
                      :class="{ 'is-active': isActive(child, route.path) }"
                      :aria-current="isActive(child, route.path) ? 'page' : undefined"
                      @click="sidebarOpen = false"
                    >
                      <span class="truncate">{{ child.label }}</span>
                    </router-link>
                  </div>
                </div>
              </template>
              <router-link
                v-if="group.key === 'content' && allTypes.length === 0 && auth.can('schema.create')"
                to="/content-types/new"
                class="nav-link opacity-70"
                :aria-label="t('app.nav.addType')"
                v-tooltip="navigationCollapsed && { title: t('app.nav.addType'), placement: 'right' }"
                @click="sidebarOpen = false"
              >
                <Icon icon="mdi:plus" class="nav-icon" />
                <span class="sidebar-text truncate">{{ t("app.nav.addType") }}</span>
              </router-link>
            </div>
          </div>
        </nav>

        <!-- User footer -->
        <div
          ref="accountMenuRef"
          class="relative border-t border-sidebar-border p-3"
        >
          <Transition name="ws-dropdown">
            <div
              v-if="accountMenuOpen"
              role="menu"
              class="sidebar-menu absolute bottom-full left-3 right-3 z-50 mb-2 overflow-hidden rounded-xl border border-slate-200 bg-white p-1 shadow-xl"
            >
              <div class="border-b border-slate-100 px-2.5 pb-2.5 pt-1.5 mb-1">
                <div class="truncate text-sm font-semibold text-slate-900">
                  {{ auth.user?.display_name || auth.user?.username }}
                </div>
                <div class="truncate text-xs text-slate-500">
                  {{ auth.user?.email || auth.user?.username }}
                </div>
              </div>
              <RouterLink
                to="/profile"
                role="menuitem"
                class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-50"
                @click="closeAccountMenu"
              >
                <Icon
                  icon="mdi:account-outline"
                  class="h-4 w-4 text-slate-400"
                />
                {{ t("app.account.profile") }}
              </RouterLink>
              <RouterLink
                to="/update"
                role="menuitem"
                class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-50"
                @click="closeAccountMenu"
              >
                <Icon
                  icon="mdi:information-outline"
                  class="h-4 w-4 text-slate-400"
                />
                <span class="flex-1">{{ t("app.account.version") }}</span>
                <span v-if="appVersion" class="text-xs text-slate-400"
                  >v{{ appVersion }}</span
                >
              </RouterLink>
              <div class="my-1 border-t border-slate-100" />
              <button
                type="button"
                role="menuitem"
                class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm text-slate-700 transition-colors hover:bg-slate-50"
                @click="handleLogout"
              >
                <Icon icon="mdi:logout" class="h-4 w-4 text-slate-400" />
                {{ t("app.actions.logout") }}
              </button>
            </div>
          </Transition>

          <button
            type="button"
            class="sidebar-profile-link flex w-full min-w-0 items-center gap-2.5 rounded-lg p-1.5 text-left transition-colors hover:bg-sidebar-hover"
            aria-haspopup="menu"
            :aria-expanded="accountMenuOpen"
            :aria-label="auth.user?.display_name || auth.user?.username"
            v-tooltip="navigationCollapsed && { title: auth.user?.display_name || auth.user?.username, placement: 'right' }"
            @click="accountMenuOpen = !accountMenuOpen"
          >
            <div
              class="w-8 h-8 shrink-0 rounded-full overflow-hidden bg-theme-600 flex items-center justify-center text-white text-xs font-semibold select-none"
            >
              <img
                v-if="auth.user?.has_avatar"
                :src="`${ADMIN_API_BASE}/users/${auth.user.id}/avatar?v=${avatarVersion}`"
                class="w-full h-full object-cover"
                :alt="auth.user?.username"
              />
              <span v-else>{{ auth.user?.username?.[0]?.toUpperCase() }}</span>
            </div>
            <span class="sidebar-text min-w-0 flex-1">
              <span class="block truncate text-sm font-medium leading-tight">{{
                auth.user?.display_name || auth.user?.username
              }}</span>
              <span class="sidebar-muted block truncate text-xs leading-tight">{{
                roleLabel
              }}</span>
            </span>
            <Icon
              icon="mdi:chevron-up"
              class="sidebar-text h-4 w-4 shrink-0 opacity-50 transition-transform"
              :class="{ 'rotate-180': accountMenuOpen }"
            />
          </button>
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
          type="button"
          :aria-label="t('app.actions.openNavigation')"
          aria-controls="primary-navigation"
          :aria-expanded="sidebarOpen"
          class="sidebar-action transition-colors p-1 -ml-1"
          @click="sidebarOpen = true"
        >
          <Icon icon="mdi:menu" class="w-6 h-6" />
        </button>
        <img :src="logoSrc" alt="CometCMS" class="brand-logo h-7 w-auto" />
        <button
          type="button"
          class="sidebar-action ml-auto p-1 transition-colors"
          :aria-label="t('app.search.open')"
          @click="paletteOpen = true"
        >
          <Icon icon="mdi:magnify" class="h-6 w-6" />
        </button>
      </header>

      <ToastStack />
      <CommandPalette v-model:open="paletteOpen" />

      <main class="flex-1 overflow-y-auto">
        <div class="max-w-7xl mx-auto w-full px-4 py-6 sm:px-8 sm:py-10">
          <slot />
        </div>
      </main>
      <ApiEndpointFooter />
    </div>
  </div>
</template>

<script setup>
import { ADMIN_API_BASE, adminAsset } from "../basePath.js";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Icon } from "@iconify/vue";
import { useAuthStore } from "../stores/auth.js";
import { useContentTypesStore } from "../stores/contentTypes.js";
import { useSiteStore } from "../stores/site.js";
import {
  api,
  getActiveWorkspace,
  setActiveWorkspace,
  setDefaultWorkspace,
} from "../api/index.js";
import { logoForTheme } from "../theme.js";
import { useI18n } from "../i18n/index.js";
import ApiEndpointFooter from "./ApiEndpointFooter.vue";
import ToastStack from "./ToastStack.vue";
import CommandPalette from "./CommandPalette.vue";
import { useNavigation } from "../composables/useNavigation.js";
import { useNavigationSections } from "../composables/useNavigationSections.js";
import { vTooltip } from "../directives/tooltip.js";

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const { groups: navGroups, allTypes, isActive } = useNavigation();
const paletteOpen = ref(false);
const isMac = typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
const shortcutLabel = isMac ? "⌘K" : "Ctrl K";
const roleLabel = computed(() => {
  const role = auth.user?.role;
  if (!role) return "";
  return typeof role === "string" ? role.charAt(0).toUpperCase() + role.slice(1) : role.label ?? "";
});
const typesStore = useContentTypesStore();
const site = useSiteStore();
const { t } = useI18n();
const avatarVersion = ref(Date.now());
const appVersion = ref("");
const sidebarOpen = ref(false);
const sidebarStorageKey = "cometcms-admin-sidebar-collapsed";
const sidebarCollapsed = ref(readSidebarCollapsed());
const desktopViewport = ref(false);
const navigationCollapsed = computed(() => sidebarCollapsed.value && desktopViewport.value);
const { isExpanded: isSectionExpanded, toggle: toggleSection, reveal: revealSection } = useNavigationSections(
  navGroups, computed(() => route.path), isActive,
);
let desktopQuery;
function syncDesktopViewport() {
  desktopViewport.value = desktopQuery.matches;
}
function toggleNavSection(item) {
  if (navigationCollapsed.value) {
    sidebarCollapsed.value = false;
    revealSection(item);
  } else {
    toggleSection(item);
  }
}
const sidebarToggleLabel = computed(() =>
  t(sidebarCollapsed.value ? "app.actions.expandNavigation" : "app.actions.collapseNavigation"),
);

function readSidebarCollapsed() {
  try {
    return localStorage.getItem(sidebarStorageKey) === "true";
  } catch {
    return false;
  }
}

watch(sidebarCollapsed, (collapsed) => {
  try {
    localStorage.setItem(sidebarStorageKey, String(collapsed));
  } catch {
    // Keep the control usable when browser storage is unavailable.
  }
});

const workspaces = ref([]);
const canSwitchWorkspaces = computed(() => workspaces.value.length > 1);
const selectedWorkspace = ref(getActiveWorkspace());
const workspaceSwitcherOpen = ref(false);
watch(canSwitchWorkspaces, () => { workspaceSwitcherOpen.value = false; });
const switcherRef = ref(null);
const accountMenuOpen = ref(false);
const accountMenuRef = ref(null);
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
  if (accountMenuRef.value && !accountMenuRef.value.contains(e.target)) {
    accountMenuOpen.value = false;
  }
}

function handleGlobalShortcut(event) {
  if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === "k") {
    event.preventDefault();
    paletteOpen.value = !paletteOpen.value;
  }
}

function closeAccountMenu() {
  accountMenuOpen.value = false;
  sidebarOpen.value = false;
}

function handleWorkspaceSync() {
  fetchWorkspaces();
}
// Resolve against this module so dev assets stay on Vite's origin even though
// PHP serves the document from a different port.
const assetBase = adminAsset("");
const logoSrc = computed(() => logoForTheme(auth.user?.theme, assetBase));

// Types are refetched after creating, deleting or switching workspaces.
watch(
  () => typesStore.list,
  () => {
    site.loadStatus().catch(() => {});
  },
  { immediate: true },
);


onMounted(() => {
  desktopQuery = window.matchMedia("(min-width: 1024px)");
  syncDesktopViewport();
  desktopQuery.addEventListener("change", syncDesktopViewport);
  if (auth.can("workspaces.read")) {
    fetchWorkspaces();
  }
  typesStore.fetch();
  fetchAppInfo();
  document.addEventListener("click", handleSwitcherOutsideClick);
  document.addEventListener("keydown", handleGlobalShortcut);
  window.addEventListener(workspaceSyncEvent, handleWorkspaceSync);
});

onBeforeUnmount(() => {
  desktopQuery?.removeEventListener("change", syncDesktopViewport);
  document.removeEventListener("click", handleSwitcherOutsideClick);
  document.removeEventListener("keydown", handleGlobalShortcut);
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
  typesStore.invalidate({ clear: true });
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
  accountMenuOpen.value = false;
  await auth.logout();
  router.push("/login");
}
</script>

<style scoped>
/* Keep the mobile drawer expanded regardless of the desktop preference. */
@media (min-width: 1024px) {
  .admin-sidebar.is-collapsed {
    width: 4.5rem;
  }

  .is-collapsed .sidebar-text,
  .is-collapsed .sidebar-brand {
    display: none;
  }

  .is-collapsed .sidebar-header,
  .is-collapsed .sidebar-workspace,
  .is-collapsed .sidebar-search,
  .is-collapsed .sidebar-profile-link,
  .is-collapsed .nav-link {
    justify-content: center;
  }

  .is-collapsed .sidebar-workspace,
  .is-collapsed .sidebar-search,
  .is-collapsed .sidebar-profile-link,
  .is-collapsed .nav-link {
    padding-left: 0;
    padding-right: 0;
  }

  /* The section button expands the sidebar to reveal its sub-pages. */
  .is-collapsed .nav-sub {
    display: none;
  }

  .is-collapsed .sidebar-nav-group + .sidebar-nav-group {
    margin-top: 0.75rem;
    padding-top: 0.5rem;
    border-top: 1px solid rgb(var(--color-sidebar-border) / var(--color-sidebar-border-alpha));
  }

  .is-collapsed .sidebar-menu {
    right: auto;
    width: 15rem;
  }

  .is-collapsed .nav-scrollbar {
    scrollbar-gutter: auto;
  }
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

/* Modern sidebar scrollbar */
.nav-scrollbar {
  scrollbar-width: thin;
  scrollbar-color: rgb(var(--color-sidebar-text) / 0.22) transparent;
  scrollbar-gutter: stable;
}

.nav-scrollbar::-webkit-scrollbar {
  width: 10px;
}

.nav-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}

.nav-scrollbar::-webkit-scrollbar-thumb {
  background: rgb(var(--color-sidebar-text) / 0.16);
  border: 3px solid transparent;
  border-radius: 999px;
  background-clip: padding-box;
}

.nav-scrollbar::-webkit-scrollbar-thumb:hover {
  background: rgb(var(--color-sidebar-text) / 0.28);
  border: 3px solid transparent;
  background-clip: padding-box;
}

.nav-scrollbar::-webkit-scrollbar-thumb:active {
  background: rgb(var(--color-sidebar-text) / 0.38);
  border: 3px solid transparent;
  background-clip: padding-box;
}
</style>
