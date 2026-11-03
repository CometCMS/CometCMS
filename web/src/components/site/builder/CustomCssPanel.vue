<template>
  <div class="flex h-full flex-col">
    <div v-if="showHeader" class="relative z-10 flex shrink-0 items-center gap-2 border-b border-slate-200 px-4 py-3">
      <Icon icon="mdi:language-css3" class="h-5 w-5 shrink-0 text-theme-600" />
      <div class="flex min-w-0 flex-1 items-center gap-1">
        <h2 class="truncate text-sm font-semibold text-slate-900">{{ t("site.theme.customCss") }}</h2>
        <BuilderHelp :label="`${t('site.theme.customCss')} — ${t('site.builder.help')}`" :hints="[t('site.builder.customCssPanelHint')]" />
      </div>
      <button type="button" class="panel-action" :title="t('site.builder.closePanel')" @click="emit('close')">
        <Icon icon="mdi:chevron-double-left" class="h-4 w-4" />
        <span class="sr-only">{{ t("site.builder.closePanel") }}</span>
      </button>
    </div>

    <p v-if="!theme && site.error" role="alert" class="m-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ site.error }}</p>
    <div v-else-if="!theme" class="flex flex-1 items-center justify-center"><LoadingSpinner /></div>

    <div v-else class="flex min-h-0 flex-1 flex-col gap-3 p-3">
      <div v-if="builder && canEdit" class="flex flex-col items-start gap-2">
        <button type="button" class="btn-secondary btn-sm" :class="{ 'is-active': builder.cssPicker.value }" :aria-pressed="!!builder.cssPicker.value" :disabled="elementScope && !activeClass" @click="togglePicker">
          <Icon icon="mdi:cursor-default-click-outline" class="h-4 w-4" />
          {{ t('site.customCss.addElement') }}
        </button>
        <p v-if="builder.cssPicker.value" role="status" class="text-xs text-slate-500">{{ t('site.customCss.pickHint') }}</p>
      </div>
      <SegmentedControl
        v-if="node"
        v-model="scope"
        :options="[
          { value: 'element', label: t('site.customCss.scopeElement'), icon: 'mdi:cursor-default-click-outline', showLabel: true },
          { value: 'all', label: t('site.customCss.scopeAll'), icon: 'mdi:file-document-outline', showLabel: true },
        ]"
      />

      <template v-if="elementScope">
        <div class="flex min-w-0 items-center gap-2 text-xs text-slate-600">
          <KindIcon :icon="nodeIcon(node, builder.isStacked(node), builder.componentOf(node), builder.spec.value)" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
          <span class="truncate font-medium text-slate-800">{{ builder.nodeLabel(node) }}</span>
        </div>

        <p v-if="!canHaveClass" class="text-xs text-slate-500">{{ t("site.customCss.noClassSupport") }}</p>
        <div v-else-if="!classes.length" class="flex flex-col items-start gap-2">
          <p class="text-xs text-slate-500">{{ t("site.customCss.noClass") }}</p>
          <button v-if="canEdit" type="button" class="btn-secondary btn-sm" @click="addClass">
            <Icon icon="mdi:plus" class="h-4 w-4" />
            {{ t("site.customCss.addClass", { name: suggestedClass }) }}
          </button>
        </div>
        <template v-else>
          <div v-if="classes.length > 1" class="flex flex-wrap gap-1" role="radiogroup" :aria-label="t('site.style.class')">
            <button
              v-for="name in classes"
              :key="name"
              type="button"
              role="radio"
              class="class-chip"
              :class="{ 'is-active': name === activeClass }"
              :aria-checked="name === activeClass"
              @click="activeClass = name"
            >.{{ name }}</button>
          </div>
          <p class="text-xs text-slate-500">{{ t("site.customCss.elementHint", { name: activeClass }) }}</p>
          <div v-if="looseCount" class="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
            <Icon icon="mdi:information-outline" class="h-4 w-4 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1">{{ t("site.customCss.looseRules", { name: activeClass, count: looseCount }) }}</span>
            <button v-if="canEdit" type="button" class="shrink-0 font-semibold underline hover:no-underline" @click="adoptRules">
              {{ t("site.customCss.adoptRules") }}
            </button>
          </div>
          <CssCodeEditor
            ref="editorRef"
            :key="activeClass"
            v-model="regionCss"
            class="flex-1"
            :disabled="!canEdit"
            :label="`${t('site.theme.customCss')} — .${activeClass}`"
            :placeholder="t('site.customCss.elementPlaceholder')"
          />
        </template>
      </template>

      <template v-else>
        <p class="text-xs text-slate-500">
          {{ canEdit ? t("site.theme.customCssHint") : t("site.theme.customCssAdminOnly") }}
        </p>
        <CssCodeEditor
          ref="editorRef"
          v-model="theme.custom_css"
          class="flex-1"
          :disabled="!canEdit"
          :label="t('site.theme.customCss')"
          placeholder=".c-site-name { letter-spacing: 0.05em; }"
        />
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Icon } from "@iconify/vue";
import BuilderHelp from "./BuilderHelp.vue";
import CssCodeEditor from "../../CssCodeEditor.vue";
import LoadingSpinner from "../../LoadingSpinner.vue";
import SegmentedControl from "../SegmentedControl.vue";
import { nodeIcon } from "./kindIcons.js";
import KindIcon from "./KindIcon.vue";
import { normalizeCssClasses, MAX_CSS_CLASSES } from "../../../composables/cssClasses.js";
import { adoptLooseRules, looseRules, scopedText, setScopedText } from "../../../composables/cssRegions.js";
import { useOptionalSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useSiteStore } from "../../../stores/site.js";
import { useI18n } from "../../../i18n/index.js";

/** The site's own stylesheet, part of the design and saved with it. */
const emit = defineEmits(["close"]);
defineProps({ showHeader: { type: Boolean, default: true } });

const site = useSiteStore();
const { t } = useI18n();
const theme = computed(() => site.draft?.theme ?? null);
const canEdit = computed(() => !!site.data?.can_edit_css);

// The design page has no builder; there the panel always shows the whole stylesheet.
const builder = useOptionalSiteBuilder();
const node = computed(() => builder?.selected.value ?? null);
const scope = ref("element");
const elementScope = computed(() => scope.value === "element" && !!node.value);
const canHaveClass = computed(() => !!node.value && (builder.spec.value?.kinds?.[node.value.kind]?.style ?? []).includes("class"));
const classes = computed(() => normalizeCssClasses(node.value ? builder.valueFor(node.value, "style", "class") : ""));

/** The element's class whose section is shown; the first one unless picked. */
const activeClass = ref("");
const editorRef = ref(null);
watch(classes, (names) => {
  if (!names.includes(activeClass.value)) activeClass.value = names[0] ?? "";
}, { immediate: true });

const regionCss = computed({
  get: () => scopedText(theme.value?.custom_css ?? "", activeClass.value),
  set: (text) => { theme.value.custom_css = setScopedText(theme.value.custom_css ?? "", activeClass.value, text); },
});
const looseCount = computed(() => (activeClass.value ? looseRules(theme.value?.custom_css ?? "", activeClass.value).length : 0));

function stopPicker() {
  if (builder) builder.cssPicker.value = null;
}

function togglePicker() {
  if (builder.cssPicker.value) return stopPicker();
  if (!canEdit.value || (elementScope.value && !activeClass.value)) return;
  builder.cssPicker.value = {
    rootId: elementScope.value ? node.value.id : null,
    className: elementScope.value ? activeClass.value : null,
    onPick: (selector) => editorRef.value?.addRule(selector),
  };
}

watch([() => node.value?.id, scope, activeClass, canEdit], stopPicker);
function onPickerKeydown(event) {
  if (event.key !== "Escape" || !builder?.cssPicker.value) return;
  event.preventDefault();
  event.stopPropagation();
  stopPicker();
}
onMounted(() => document.addEventListener("keydown", onPickerKeydown, true));
onBeforeUnmount(() => {
  stopPicker();
  document.removeEventListener("keydown", onPickerKeydown, true);
});

function adoptRules() {
  theme.value.custom_css = adoptLooseRules(theme.value.custom_css ?? "", activeClass.value);
}

/** A readable, unique-enough class such as "heading-3f2a9". */
const suggestedClass = computed(() => {
  if (!node.value) return "";
  const suffix = String(node.value.id).replace(/[^A-Za-z0-9]/g, "").slice(-5).toLowerCase();
  return `${node.value.kind.replace(/[^A-Za-z0-9]+/g, "-")}-${suffix}`.slice(0, 61);
});

function addClass() {
  if (classes.value.length >= MAX_CSS_CLASSES) return;
  builder.updateValue(node.value.id, "style", "class", [...classes.value, suggestedClass.value].join(" "));
}
</script>

<style scoped>
button[aria-pressed="true"] {
  border-color: rgb(var(--color-theme-500));
  background: rgb(var(--color-theme-50));
  color: rgb(var(--color-theme-700));
}
.class-chip {
  border-radius: 0.375rem;
  border: 1px solid rgb(var(--color-slate-200));
  padding: 0.125rem 0.5rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11px;
  color: rgb(var(--color-slate-600));
}
.class-chip:hover {
  color: rgb(var(--color-slate-900));
}
.class-chip.is-active {
  border-color: rgb(var(--color-theme-500));
  background: rgb(var(--color-theme-50));
  color: rgb(var(--color-theme-700));
}
.panel-action {
  display: inline-flex;
  height: 1.75rem;
  width: 1.75rem;
  align-items: center;
  justify-content: center;
  border-radius: 0.375rem;
  color: rgb(var(--color-slate-500));
}
.panel-action:hover {
  background: rgb(var(--color-slate-100));
  color: rgb(var(--color-slate-900));
}
</style>
