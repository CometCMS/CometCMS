<template>
  <div :id="id" class="space-y-1.5" role="group" :aria-label="label">
    <p class="text-[11px] text-slate-500">{{ t(rows.length ? "site.condition.hint" : "site.condition.none") }}</p>
    <OptionBar
      v-if="rows.length > 1"
      :model-value="match"
      :options="matchOptions"
      @update:model-value="emitValue(rows, $event)"
    />
    <div
      v-for="(row, index) in rows"
      :key="index"
      class="space-y-1.5 rounded-lg border border-slate-200 bg-white p-2"
      :class="{ 'border-amber-300 bg-amber-50': !configFor(row) }"
    >
      <div class="flex min-w-0 items-center gap-1.5">
        <button
          type="button"
          class="flex min-w-0 flex-1 items-center gap-1.5 rounded-md border border-theme-200 bg-theme-50 px-2 py-1 text-left text-xs text-theme-800 hover:bg-theme-100"
          :title="t('site.condition.changeField')"
          :aria-expanded="picking === index"
          @click="pick(index)"
        >
          <Icon icon="mdi:database-outline" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          <span class="truncate">{{ smartSummary(row.field) }}</span>
        </button>
        <button type="button" class="row-action hover:!text-red-600" :title="t('site.condition.remove')" :aria-label="t('site.condition.remove')" @click="remove(index)">
          <Icon icon="mdi:close" class="h-4 w-4" />
        </button>
      </div>
      <p v-if="!configFor(row)" role="status" class="text-[11px] text-amber-700">{{ t("site.binding.unavailable") }}</p>
      <div class="flex min-w-0 items-center gap-1.5">
        <select
          class="form-select min-w-0 flex-1 rounded-md border-slate-300 py-1 text-xs"
          :value="row.op"
          :aria-label="t('site.templates.conditionOp')"
          @change="update(index, { op: $event.target.value })"
        >
          <option v-for="op in opsFor(row)" :key="op" :value="op">{{ t(`site.templates.opName.${op}`) }}</option>
        </select>
        <template v-if="needsValue(row.op)">
          <select
            v-if="valueOptions(row)"
            class="form-select min-w-0 flex-1 rounded-md border-slate-300 py-1 text-xs"
            :value="String(row.value ?? '')"
            :aria-label="t('site.templates.conditionValue')"
            @change="update(index, { value: parseConditionValue(configFor(row), $event.target.value) })"
          >
            <option v-for="option in valueOptions(row)" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
          <input
            v-else
            :type="['number', 'range'].includes(configFor(row)?.type) ? 'number' : 'text'"
            class="form-input min-w-0 flex-1 rounded-md border-slate-300 py-1 text-xs"
            :value="row.value ?? ''"
            maxlength="500"
            :aria-label="t('site.templates.conditionValue')"
            @change="update(index, { value: parseConditionValue(configFor(row), $event.target.value) })"
          />
        </template>
      </div>
    </div>

    <SmartReferencePicker
      v-if="picking !== null"
      :key="picking"
      class="relative z-40"
      :node="node"
      target="condition"
      :initial="picking === 'new' ? null : rows[picking]?.field ?? null"
      @select="chooseField"
      @close="picking = null"
    />
    <button
      v-else-if="rows.length < MAX_CONDITIONS"
      type="button"
      class="w-full rounded-lg border border-dashed border-slate-300 px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50"
      @click="pick('new')"
    >
      <Icon icon="mdi:plus" class="inline h-3.5 w-3.5" aria-hidden="true" /> {{ t("site.condition.add") }}
    </button>
  </div>
</template>

<script setup>
import { computed, ref } from "vue";
import { Icon } from "@iconify/vue";
import OptionBar from "./OptionBar.vue";
import SmartReferencePicker from "./SmartReferencePicker.vue";
import { MAX_CONDITIONS, conditionOps, conditionValueOptions, initialConditionValue, needsValue, parseConditionValue } from "../../../composables/templates.js";
import { referenceConfig, smartSummary } from "../../../composables/smartValues.js";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useI18n } from "../../../i18n/index.js";

/** Every comparison, for a field this context can't read (same as ElementConditions::OPS). */
const ALL_OPS = ["equals", "not_equals", "contains", "empty", "not_empty"];

/** The style value visible_if: `{ match, conditions: [{ field, op, value }] }`, or [] for none. */
const props = defineProps({
  id: { type: String, required: true },
  label: { type: String, default: "" },
  node: { type: Object, required: true },
  modelValue: { default: () => [] },
});
const emit = defineEmits(["update:modelValue"]);
const builder = useSiteBuilder();
const { t } = useI18n();

/** The row whose field is being chosen, "new" for an added one, or null. */
const picking = ref(null);
const rows = computed(() => (Array.isArray(props.modelValue?.conditions) ? props.modelValue.conditions : []));
const match = computed(() => (props.modelValue?.match === "any" ? "any" : "all"));
const matchOptions = computed(() => ["all", "any"].map((value) => ({ value, label: t(`site.condition.match.${value}`), title: t(`site.condition.match.${value}`) })));

/** The bound field's config where the element is, e.g. a card's own type inside a list. */
function configFor(row) {
  const owner = builder.templateOwner(props.node.id);
  return referenceConfig(row.field, { type: builder.entryContext(props.node.id).type, types: builder.types.value, itemFields: owner ? builder.subfieldsOf(owner) : [] });
}

function opsFor(row) {
  const config = configFor(row);
  return config ? conditionOps(config.type) : ALL_OPS;
}

const valueOptions = (row) => conditionValueOptions(configFor(row), t);

/** A row whose op and value fit its field, with no value for empty/not_empty. */
function fitted(row) {
  const ops = opsFor(row);
  const op = ops.includes(row.op) ? row.op : ops[0];
  if (!needsValue(op)) return { field: row.field, op, value: null };
  return { field: row.field, op, value: row.value ?? initialConditionValue(configFor(row), t) };
}

function emitValue(next, nextMatch = match.value) {
  emit("update:modelValue", next.length ? { match: nextMatch, conditions: next } : []);
}

function pick(index) {
  picking.value = picking.value === index ? null : index;
}

function chooseField(field) {
  if (picking.value === "new") emitValue([...rows.value, fitted({ field, op: "", value: null })]);
  else emitValue(rows.value.map((row, i) => (i === picking.value ? fitted({ field, op: row.op, value: null }) : row)));
  picking.value = null;
}

function update(index, patch) {
  emitValue(rows.value.map((row, i) => (i === index ? fitted({ ...row, ...patch }) : row)));
}

function remove(index) {
  picking.value = null;
  emitValue(rows.value.filter((_, i) => i !== index));
}
</script>

<style scoped>
.row-action {
  display: inline-flex;
  height: 1.5rem;
  width: 1.5rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 0.375rem;
  color: rgb(var(--color-slate-400));
}
.row-action:hover {
  background: rgb(var(--color-slate-100));
  color: rgb(var(--color-slate-900));
}
</style>
