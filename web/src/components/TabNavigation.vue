<template>
  <nav
    role="tablist"
    :aria-label="ariaLabel || undefined"
    :class="
      variant === 'pills'
        ? 'flex flex-wrap items-center gap-1.5'
        : variant === 'segmented'
          ? 'grid grid-cols-2 overflow-hidden rounded-lg border border-slate-200 bg-slate-50 sm:grid-cols-3 lg:flex'
          : 'flex flex-wrap items-end gap-1 overflow-visible border-b border-slate-200'
    "
  >
    <div
      v-for="item in items"
      :key="item.value"
      class="group relative inline-flex shrink-0"
      :class="[variant === 'segmented' ? 'items-stretch' : 'items-center', itemWrapperClass(item)]"
      :data-tab-value="item.value"
    >
      <component
        :is="item.to ? RouterLink : 'button'"
        :to="item.to"
        :type="item.to ? undefined : 'button'"
        role="tab"
        :aria-selected="item.value === modelValue"
        :disabled="!item.to && item.disabled"
        :class="itemButtonClass(item)"
        @click="selectItem(item, $event)"
      >
        <Icon v-if="item.icon" :icon="item.icon" class="h-4 w-4 shrink-0" />
        <span>{{ item.label }}</span>
        <span
          v-if="item.flagCountry"
          :class="['fi', `fi-${item.flagCountry}`, 'shrink-0 rounded-sm']"
          aria-hidden="true"
        />
        <span v-if="item.suffix">{{ item.suffix }}</span>
        <Icon
          v-if="item.metaIcon"
          :icon="item.metaIcon"
          class="h-3.5 w-3.5 shrink-0 opacity-70"
          :aria-label="item.metaLabel || undefined"
          :title="item.metaLabel || undefined"
        />
        <span
          v-if="item.meta"
          class="text-[10px] uppercase tracking-wide opacity-75"
        >{{ item.meta }}</span>
      </component>
      <slot
        name="actions"
        :item="item"
        :active="item.value === modelValue"
      />
    </div>
    <div v-if="$slots.end" class="ml-auto self-center py-1.5"><slot name="end" /></div>
  </nav>
</template>

<script setup>
import { Icon } from "@iconify/vue";
import { RouterLink } from "vue-router";

const props = defineProps({
  items: { type: Array, default: () => [] },
  modelValue: { type: [String, Number], default: "" },
  ariaLabel: { type: String, default: "" },
  variant: {
    type: String,
    default: "underline",
    validator: (value) => ["underline", "pills", "segmented"].includes(value),
  },
});

const emit = defineEmits(["update:modelValue", "select"]);

function selectItem(item, event) {
  if (item.disabled) {
    event.preventDefault();
    return;
  }
  emit("update:modelValue", item.value);
  emit("select", item);
}

function itemWrapperClass(item) {
  if (props.variant === "segmented") {
    return "min-w-0 border-b border-r border-slate-200 last:border-r-0 lg:flex-1 lg:border-b-0";
  }
  if (props.variant !== "pills") {
    if (!item.hasActions) return "";
    return [
      "-mb-px border-b-2",
      item.value === props.modelValue
        ? "border-theme-600 text-theme-700"
        : "border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800",
      item.muted && item.value !== props.modelValue ? "text-slate-400" : "",
    ];
  }
  if (item.muted) {
    return item.value === props.modelValue
      ? "rounded-full bg-theme-50 text-theme-600 ring-1 ring-inset ring-theme-300"
      : "rounded-full bg-slate-50 text-slate-400 ring-1 ring-inset ring-dashed ring-slate-300 hover:text-theme-500 hover:ring-theme-400";
  }
  return item.value === props.modelValue
    ? "rounded-full bg-theme-600 text-white ring-1 ring-inset ring-theme-600"
    : "rounded-full bg-white text-slate-600 ring-1 ring-inset ring-slate-300";
}

function itemButtonClass(item) {
  const base = "inline-flex items-center gap-1 font-medium transition-colors";
  if (props.variant === "segmented") {
    return [
      base,
      "min-h-9 w-full justify-center px-3 py-2 text-center text-xs",
      item.value === props.modelValue
        ? "bg-white text-theme-700 shadow-sm"
        : "text-slate-600 hover:bg-white hover:text-slate-900",
    ];
  }
  if (props.variant === "pills") {
    return [
      base,
      "py-1 text-xs hover:opacity-80 disabled:cursor-default",
      item.hasActions ? "pl-3" : "px-3",
    ];
  }
  return [
    base,
    "px-4 py-3 text-sm font-semibold",
    item.hasActions ? "" : "-mb-px border-b-2",
    !item.hasActions && item.value === props.modelValue
      ? "border-theme-600 text-theme-700"
      : !item.hasActions
        ? "border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800"
        : "",
  ];
}
</script>
