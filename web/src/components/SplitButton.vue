<template>
  <div ref="rootRef" class="relative inline-flex shrink-0" @focusout="onFocusout">
    <button
      :type="type"
      :form="form"
      :class="[buttonClass, { 'rounded-r-none': items.length }]"
      :disabled="disabled || primaryDisabled"
      @click="emit('click', $event)"
    >
      <slot />
    </button>
    <button
      v-if="items.length"
      ref="triggerRef"
      type="button"
      class="rounded-l-none border-l px-2"
      :class="[buttonClass, variant === 'primary' ? 'border-white/25' : '-ml-px']"
      :disabled="disabled"
      :aria-label="menuLabel"
      aria-haspopup="menu"
      :aria-expanded="open"
      :aria-controls="open ? menuId : undefined"
      @click="open ? close() : openMenu()"
      @keydown.down.prevent="openMenu()"
      @keydown.up.prevent="openMenu(true)"
      @keydown.esc.stop.prevent="close(true)"
    >
      <Icon icon="mdi:chevron-down" class="h-4 w-4" aria-hidden="true" />
    </button>
    <slot name="badge" />

    <div
      v-if="open"
      :id="menuId"
      ref="menuRef"
      role="menu"
      tabindex="-1"
      :aria-label="menuLabel"
      class="absolute right-0 top-full z-50 mt-1 min-w-[16rem] rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg"
      @keydown="onMenuKeydown"
    >
      <template v-for="item in items" :key="item.key">
        <div v-if="item.separatorBefore" role="separator" class="my-1 border-t border-slate-200"></div>
        <component
          :is="item.href && !item.disabled ? 'a' : 'button'"
          :type="item.href && !item.disabled ? undefined : 'button'"
          role="menuitem"
          tabindex="-1"
          :href="item.disabled ? undefined : item.href"
          :target="item.target"
          :rel="item.target === '_blank' ? 'noopener noreferrer' : undefined"
          :disabled="item.disabled || undefined"
          :aria-disabled="item.disabled || undefined"
          :title="item.title"
          class="flex w-full items-center gap-2 px-3 py-2 text-left text-slate-700 hover:bg-slate-50 focus:bg-slate-50 disabled:cursor-default disabled:opacity-40"
          @click="select(item, $event)"
        >
          <Icon v-if="item.icon" :icon="item.icon" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
          {{ item.label }}
        </component>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from "vue";
import { Icon } from "@iconify/vue";

const props = defineProps({
  type: { type: String, default: 'button' },
  form: { type: String, default: undefined },
  /** Each item has a key and label, and optionally an href, target, icon, title, disabled flag or separatorBefore flag. */
  items: { type: Array, default: () => [] },
  menuLabel: { type: String, required: true },
  variant: { type: String, default: 'primary', validator: (value) => ['primary', 'secondary', 'inverse'].includes(value) },
  disabled: { type: Boolean, default: false },
  /** Disable only the primary action, keeping the menu available. */
  primaryDisabled: { type: Boolean, default: false },
});
const emit = defineEmits(["click", "select"]);
const buttonClass = computed(() => props.variant === 'inverse' ? 'btn btn-inverse' : props.variant === 'secondary' ? 'btn-secondary' : 'btn-primary');
const rootRef = ref(null);
const triggerRef = ref(null);
const menuRef = ref(null);
const open = ref(false);
const menuId = `split-button-${useId()}`;

function enabledItems() {
  return [...(menuRef.value?.querySelectorAll('[role="menuitem"]:not(:disabled)') ?? [])];
}

async function openMenu(last = false) {
  if (props.disabled || !props.items.length) return;
  open.value = true;
  await nextTick();
  const items = enabledItems();
  (items[last ? items.length - 1 : 0] ?? menuRef.value)?.focus();
}

function close(restoreFocus = false) {
  open.value = false;
  if (restoreFocus) triggerRef.value?.focus();
}

function select(item, event) {
  if (item.disabled) {
    event.preventDefault();
    return;
  }
  close(true);
  emit("select", item);
}

function onMenuKeydown(event) {
  if (event.key === "Escape") {
    event.preventDefault();
    event.stopPropagation();
    close(true);
    return;
  }
  if (event.key === " " && event.target.closest('[role="menuitem"]')) {
    event.preventDefault();
    event.target.closest('[role="menuitem"]').click();
    return;
  }
  if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
  event.preventDefault();
  event.stopPropagation();
  const items = enabledItems();
  const current = items.indexOf(document.activeElement);
  const index = event.key === "Home" ? 0
    : event.key === "End" ? items.length - 1
    : (current + (event.key === "ArrowDown" ? 1 : -1) + items.length) % items.length;
  items[index]?.focus();
}

function onOutsidePointerdown(event) {
  if (!rootRef.value?.contains(event.target)) close();
}

function onFocusout(event) {
  if (!rootRef.value?.contains(event.relatedTarget)) close();
}

watch(() => props.disabled || !props.items.length, (unavailable) => {
  if (unavailable) close();
});
onMounted(() => document.addEventListener("pointerdown", onOutsidePointerdown));
onBeforeUnmount(() => document.removeEventListener("pointerdown", onOutsidePointerdown));
</script>

<style scoped>
.btn-inverse {
  border-color: rgb(var(--color-theme-800) / 0.2);
  background: #fff;
  color: rgb(var(--color-theme-800));
}
.btn-inverse:hover:not(:disabled) {
  background: #f1f5f9;
}
.btn-inverse:disabled {
  background: rgb(255 255 255 / 0.65);
  cursor: not-allowed;
}
</style>
