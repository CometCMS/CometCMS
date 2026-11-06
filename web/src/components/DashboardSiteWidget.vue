<template>
  <PageBuilderCta
    v-if="visible"
    class="!p-5"
    :title="t('site.builderCta.title')"
    :description="t(status.homepage_editor ? 'site.builderCta.description' : 'dashboard.site.noHomepage')"
    :to="status.homepage_editor || '/website/general#homepage'"
    :action-label="t(status.homepage_editor ? 'site.builderCta.open' : 'dashboard.site.chooseHomepage')"
  />
</template>

<script setup>
import { computed } from "vue";
import PageBuilderCta from "./site/PageBuilderCta.vue";
import { useI18n } from "../i18n/index.js";

const props = defineProps({ status: { type: Object, default: null } });
const { t } = useI18n();
const visible = computed(() => props.status?.can_edit_layouts && (
  props.status.enabled || Object.values(props.status.types ?? {}).some((type) => type.pages_enabled)
));
</script>
