<template>
  <div class="space-y-3">
    <fieldset :disabled="builder.translating?.value" class="min-w-0">
      <label class="mb-1 block text-xs text-slate-500" :for="inputId">{{ t("site.style.background_fx") }}</label>
      <select :id="inputId" class="form-select w-full rounded-lg border-slate-300 py-1.5 text-xs" :value="kind" :aria-describedby="hint ? `${inputId}-hint` : undefined" @change="choose($event.target.value)">
        <option value="">{{ t("site.option.none") }}</option>
        <option v-for="option in options" :key="option.kind" :value="option.kind">{{ option.label }}</option>
        <option v-if="kind && !available" :value="kind" disabled>{{ t("site.backgroundFx.missing", { name: kind.slice(6) }) }}</option>
      </select>
      <p v-if="hint" :id="`${inputId}-hint`" class="mt-1 text-[11px] leading-snug text-slate-500">{{ hint }}</p>
    </fieldset>
    <p v-if="available && own.script" class="flex items-start gap-1 text-[11px] leading-snug text-amber-700">
      <Icon icon="mdi:language-javascript" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ t("site.extensions.scriptPreviewHint") }}
    </p>
    <p v-if="typeof own.deprecated === 'string'" class="flex items-start gap-1 text-[11px] leading-snug text-amber-700">
      <Icon icon="mdi:alert-outline" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ extensionText(own, "deprecated", locale) || t("site.extensions.deprecatedBlock") }}
    </p>
    <div v-for="group in groups" :key="group.key" class="space-y-3">
      <h5 v-if="group.title" class="pt-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ group.title }}</h5>
      <BuilderControl
        v-for="key in group.keys"
        :key="`${node.id}-fx-${kind}-${key}`"
        :node="effectNode"
        :name="key"
        :def="propSpec[key]"
        :value="effectProps[key] ?? propSpec[key].default"
        :variants="false"
        @update="(value) => update(key, value)"
      />
    </div>
  </div>
</template>

<script setup>
import { computed } from "vue";
import { Icon } from "@iconify/vue";
import BuilderControl from "./BuilderControl.vue";
import { clone, defaultProps, hasBackgroundEffect } from "../../../composables/layoutTree.js";
import { conditionsHold, extensionText } from "../../../composables/extensionText.js";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useI18n } from "../../../i18n/index.js";

/**
 * The extension background of a section, row or column (style background_fx):
 * which one, then its own props with the controls extension blocks use. The
 * value is { kind, props }; none is an empty list, the style's default.
 */
const props = defineProps({
  node: { type: Object, required: true },
  value: { default: null },
});
const emit = defineEmits(["update"]);

const builder = useSiteBuilder();
const { t, locale } = useI18n();
const inputId = computed(() => `builder-${props.node.id}-style-background_fx`);
const spec = computed(() => builder.spec.value ?? {});
const kind = computed(() => (hasBackgroundEffect(props.value) ? props.value.kind : ""));
const own = computed(() => spec.value.kinds?.[kind.value] ?? {});
const available = computed(() => !!kind.value && (spec.value.backgrounds ?? []).includes(kind.value));
/** Installed backgrounds; deprecated ones only while chosen. */
const options = computed(() => (spec.value.backgrounds ?? [])
  .filter((option) => typeof spec.value.kinds?.[option]?.deprecated !== "string" || option === kind.value)
  .map((option) => ({ kind: option, label: builder.kindLabel(option) }))
  .sort((a, b) => a.label.localeCompare(b.label)));
const hint = computed(() => (available.value && own.value.description ? extensionText({ description: own.value.description, l10n: own.value.l10n }, "description", locale.value) : ""));
const propSpec = computed(() => (available.value ? own.value.props ?? {} : {}));
const effectProps = computed(() => (hasBackgroundEffect(props.value) && props.value.props && typeof props.value.props === "object" ? props.value.props : {}));
/** The background as a node of its own kind, so each prop gets the control and texts of extension blocks. */
const effectNode = computed(() => ({ id: `${props.node.id}-fx`, kind: kind.value, props: effectProps.value, style: {}, children: [] }));
/** Visible props (visible_when, not deprecated), gathered by their group in declaration order. */
const groups = computed(() => {
  const values = { ...defaultProps(spec.value, kind.value), ...effectProps.value };
  const result = new Map();
  for (const [key, def] of Object.entries(propSpec.value)) {
    if (def.deprecated !== undefined || !conditionsHold(def, values)) continue;
    const group = def.group ?? "";
    if (!result.has(group)) result.set(group, { key: group || "_", title: group ? extensionText(def, "group", locale.value) : "", keys: [] });
    result.get(group).keys.push(key);
  }
  return [...result.values()];
});

function choose(next) {
  if (next === kind.value) return;
  emit("update", next ? { kind: next, props: defaultProps(spec.value, next) } : []);
}

function update(key, value) {
  emit("update", { kind: kind.value, props: { ...clone(effectProps.value), [key]: value } });
}
</script>
