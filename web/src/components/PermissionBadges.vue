<template>
  <div
    v-if="groups.length > 0"
    class="grid grid-cols-2 gap-x-5 gap-y-3 md:grid-cols-3 lg:grid-cols-5"
  >
    <div v-for="group in groups" :key="group.area">
      <p
        class="text-[10px] font-semibold uppercase tracking-wide mb-1.5"
        :class="group.headingClass"
      >
        {{ group.label }}
      </p>
      <div class="flex flex-wrap gap-1 lg:flex-col">
        <span
          v-for="badge in group.badges"
          :key="badge.key"
          class="inline-block rounded px-1.5 py-0.5 text-xs font-medium leading-none"
          :class="group.badgeClass"
        >
          {{ badge.label
          }}<span v-if="badge.scope" class="ml-0.5 opacity-60">
            · {{ badge.scope }}</span
          >
        </span>
      </div>
    </div>
  </div>
  <p v-else class="text-xs text-slate-400 italic">No permissions granted</p>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
  permissions: {
    type: Array,
    default: () => [],
  },
});

const AREA_ORDER = ["system", "schema", "content", "media", "users"];

const AREA_CONFIG = {
  system: {
    label: "System",
    headingClass: "text-slate-400",
    badgeClass: "bg-slate-100 text-slate-600",
  },
  schema: {
    label: "Content types",
    headingClass: "text-violet-500",
    badgeClass: "bg-violet-50 text-violet-700",
  },
  content: {
    label: "Content",
    headingClass: "text-sky-500",
    badgeClass: "bg-sky-50 text-sky-700",
  },
  media: {
    label: "Media",
    headingClass: "text-emerald-500",
    badgeClass: "bg-emerald-50 text-emerald-700",
  },
  users: {
    label: "Users, Tokens & Roles",
    headingClass: "text-amber-500",
    badgeClass: "bg-amber-50 text-amber-700",
  },
};

const ACTION_AREA = {
  dashboard: "system",
  activity: "system",
  profile: "system",
  backups: "system",
  webhooks: "system",
  workspaces: "system",
  updates: "system",
  schema: "schema",
  content: "content",
  media: "media",
  users: "users",
  tokens: "users",
  roles: "users",
};

const ACTION_LABELS = {
  "dashboard.read": "Dashboard",
  "activity.read": "Activity log",
  "profile.read": "Read profile",
  "profile.update": "Edit profile",
  "backups.read": "View backups",
  "backups.create": "Create backups",
  "backups.restore": "Restore backups",
  "backups.delete": "Delete backups",
  "webhooks.manage": "Webhooks",
  "workspaces.read": "View workspaces",
  "workspaces.manage": "Manage workspaces",
  "updates.read": "View updates",
  "updates.check": "Check updates",
  "updates.download": "Download updates",
  "updates.install": "Install updates",
  "schema.read": "Read",
  "schema.create": "Create",
  "schema.update": "Edit",
  "schema.delete": "Delete",
  "content.read": "Read",
  "content.create": "Create",
  "content.update": "Edit",
  "content.publish": "Publish",
  "content.delete": "Delete",
  "content.restore": "Restore",
  "content.revisions.read": "View revisions",
  "content.revisions.restore": "Restore revisions",
  "media.read": "Read",
  "media.upload": "Upload",
  "media.update": "Edit",
  "media.delete": "Delete",
  "users.read": "View users",
  "users.create": "Create users",
  "users.update": "Edit users",
  "users.delete": "Delete users",
  "tokens.read": "View tokens",
  "tokens.create": "Create tokens",
  "tokens.revoke": "Revoke tokens",
  "roles.read": "View roles",
  "roles.create": "Create roles",
  "roles.update": "Edit roles",
  "roles.delete": "Delete roles",
};

function getArea(action) {
  return ACTION_AREA[action.split(".")[0]] ?? "system";
}

function getLabel(action) {
  return ACTION_LABELS[action] ?? action;
}

function formatScope(resources) {
  if (!resources?.length) return null;
  const joined = resources.join(", ");
  return joined === "*" ? null : joined;
}

const groups = computed(() => {
  if (!Array.isArray(props.permissions) || props.permissions.length === 0)
    return [];

  const areaMap = new Map();

  for (const grant of props.permissions) {
    const scope = formatScope(grant.resources);
    for (const action of grant.actions ?? []) {
      const area = getArea(action);
      if (!areaMap.has(area)) areaMap.set(area, new Map());
      const key = scope ? `${action}@${scope}` : action;
      if (!areaMap.get(area).has(key)) {
        areaMap.get(area).set(key, { key, label: getLabel(action), scope });
      }
    }
  }

  return AREA_ORDER.filter((area) => areaMap.has(area)).map((area) => ({
    area,
    ...AREA_CONFIG[area],
    badges: Array.from(areaMap.get(area).values()),
  }));
});
</script>
