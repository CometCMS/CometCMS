<template>
  <Teleport to="body">
    <Transition name="app-dialog">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-50 flex items-center justify-center bg-ink-950/40 p-4 backdrop-blur-[2px]"
        @mousedown.self="close"
      >
        <component
          :is="as"
          ref="dialogRef"
          class="app-dialog-panel flex max-h-[90vh] w-full flex-col rounded-xl bg-white shadow-xl"
          :class="SIZES[size] ?? SIZES.md"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          :aria-describedby="subtitle ? subtitleId : undefined"
          :aria-busy="busy || undefined"
          tabindex="-1"
          @submit.prevent="$emit('submit', $event)"
        >
          <header class="flex shrink-0 items-start gap-3 border-b border-slate-200 px-6 py-4">
            <slot name="icon">
              <span v-if="icon" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-theme-50">
                <Icon :icon="icon" class="h-5 w-5 text-theme-600" aria-hidden="true" />
              </span>
            </slot>
            <div class="min-w-0 flex-1">
              <h2 :id="titleId" class="truncate text-base font-semibold leading-tight text-slate-900">{{ title }}</h2>
              <p v-if="subtitle" :id="subtitleId" class="mt-0.5 truncate text-xs text-slate-500">{{ subtitle }}</p>
            </div>
            <button
              type="button"
              class="-mr-2 shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50"
              :aria-label="t('common.close')"
              :disabled="busy"
              @click="close"
            >
              <Icon icon="mdi:close" class="h-5 w-5" aria-hidden="true" />
            </button>
          </header>

          <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
            <slot />
          </div>

          <footer v-if="$slots.footer" class="flex shrink-0 justify-end gap-2 rounded-b-xl border-t border-slate-200 bg-slate-50 px-6 py-3">
            <slot name="footer" :close="close" />
          </footer>
        </component>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, toRef, useId } from "vue";
import { Icon } from "@iconify/vue";
import { useDialogFocus } from "../composables/useDialogFocus.js";
import { useI18n } from "../i18n/index.js";

/**
 * Generic modal dialog: header with title and close button, scrolling body,
 * optional footer for actions. Pass `as="form"` to make the whole dialog a
 * form, so a submit button in the footer emits `submit`.
 */
const SIZES = { sm: "max-w-md", md: "max-w-lg", lg: "max-w-2xl", xl: "max-w-4xl" };

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  title: { type: String, required: true },
  subtitle: { type: String, default: "" },
  icon: { type: String, default: "" },
  size: { type: String, default: "md" },
  as: { type: String, default: "div" },
  /** Blocks closing (Escape, backdrop, close button) while work is in flight. */
  busy: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue", "submit"]);
const { t } = useI18n();
const dialogRef = ref(null);
const componentId = useId();
const titleId = `${componentId}-title`;
const subtitleId = `${componentId}-subtitle`;

function close() {
  if (!props.busy) emit("update:modelValue", false);
}

useDialogFocus({ open: toRef(props, "modelValue"), container: dialogRef, close, closeBlocked: () => props.busy });
</script>

<style scoped>
.app-dialog-enter-active,
.app-dialog-leave-active,
.app-dialog-enter-active .app-dialog-panel,
.app-dialog-leave-active .app-dialog-panel {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.app-dialog-enter-from,
.app-dialog-leave-to {
  opacity: 0;
}
.app-dialog-enter-from .app-dialog-panel,
.app-dialog-leave-to .app-dialog-panel {
  transform: scale(0.95);
}
</style>
