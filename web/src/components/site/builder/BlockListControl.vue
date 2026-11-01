<template>
  <div class="space-y-1.5">
    <p v-if="!items.length" class="text-xs text-slate-500">{{ t("site.extensions.listEmpty") }}</p>
    <div v-for="(item, index) in items" :key="index" class="rounded-lg border border-slate-200 bg-white">
      <div class="flex items-center gap-1 px-2 py-1.5">
        <button
          type="button"
          class="flex min-w-0 flex-1 items-center gap-1.5 text-left text-xs font-medium text-slate-700"
          :aria-expanded="open === index"
          @click="open = open === index ? null : index"
        >
          <Icon icon="mdi:chevron-right" class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="{ 'rotate-90': open === index }" />
          <span class="truncate">{{ title(item, index) }}</span>
        </button>
        <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="index === 0" :aria-label="t('site.builder.moveUp')" @click="move(index, -1)">
          <Icon icon="mdi:arrow-up" class="h-3.5 w-3.5" />
        </button>
        <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="index === items.length - 1" :aria-label="t('site.builder.moveDown')" @click="move(index, 1)">
          <Icon icon="mdi:arrow-down" class="h-3.5 w-3.5" />
        </button>
        <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="t('common.remove')" @click="remove(index)">
          <Icon icon="mdi:trash-can-outline" class="h-3.5 w-3.5" />
        </button>
      </div>
      <div v-if="open === index" class="space-y-3 border-t border-slate-100 px-2 py-2">
        <BuilderControl
          v-for="(field, key) in def.fields"
          :key="key"
          embedded
          :node="node"
          :name="`${name}-${index}-${key}`"
          :def="field"
          :value="item[key] ?? field.default"
          @update="(value) => update(index, key, value)"
        />
      </div>
    </div>
    <button type="button" class="btn-secondary btn-sm w-full" :disabled="items.length >= (def.max ?? 20)" @click="add">
      <Icon icon="mdi:plus" class="h-4 w-4" />
      {{ t("site.extensions.listAdd") }}
    </button>
  </div>
</template>

<script setup>
import { computed, ref } from "vue";
import { Icon } from "@iconify/vue";
import BuilderControl from "./BuilderControl.vue";
import { useI18n } from "../../../i18n/index.js";
import { clone } from "../../../composables/layoutTree.js";

const props = defineProps({
  node: { type: Object, required: true },
  name: { type: String, required: true },
  /** A list prop: item fields, and at most `max` items. */
  def: { type: Object, required: true },
  value: { type: Array, default: () => [] },
});
const emit = defineEmits(["update"]);
const { t } = useI18n();
const open = ref(null);
const items = computed(() => (Array.isArray(props.value) ? props.value : []));

/** The first text field names an item; untitled items are numbered. */
function title(item, index) {
  const key = Object.keys(props.def.fields ?? {}).find((name) => ["string", "text"].includes(props.def.fields[name].type));
  const text = key ? String(item?.[key] ?? "").trim() : "";
  return text || t("site.extensions.listItem", { number: index + 1 });
}

function add() {
  const item = Object.fromEntries(Object.entries(props.def.fields ?? {}).map(([key, field]) => [key, clone(field.default ?? null)]));
  emit("update", [...items.value, item]);
  open.value = items.value.length;
}

function update(index, key, value) {
  emit("update", items.value.map((item, position) => (position === index ? { ...item, [key]: value } : item)));
}

function move(index, offset) {
  const next = [...items.value];
  [next[index], next[index + offset]] = [next[index + offset], next[index]];
  emit("update", next);
  if (open.value === index) open.value = index + offset;
}

function remove(index) {
  emit("update", items.value.filter((_, position) => position !== index));
  if (open.value === index) open.value = null;
  else if (open.value !== null && open.value > index) open.value--;
}
</script>
