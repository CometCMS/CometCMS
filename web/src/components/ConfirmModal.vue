<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50"
        @mousedown.self="$emit('update:modelValue', false)"
      >
        <div
          class="bg-white rounded-xl shadow-xl w-full max-w-md p-6 space-y-4"
        >
          <div class="flex items-start gap-3">
            <div
              class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center"
              :class="iconBg"
            >
              <Icon icon="mdi:alert" class="w-5 h-5" :class="iconColor" />
            </div>
            <div class="flex-1 min-w-0">
              <h3 class="text-base font-semibold text-slate-900">
                {{ displayTitle }}
              </h3>
              <p v-if="message" class="mt-1 text-sm text-slate-600">
                {{ message }}
              </p>
              <slot />
            </div>
          </div>

          <div class="flex justify-end gap-2">
            <button
              type="button"
              class="btn-secondary"
              @click="$emit('update:modelValue', false)"
            >
              {{ displayCancelLabel }}
            </button>
            <button
              type="button"
              :class="confirmClass"
              :disabled="loading"
              @click="$emit('confirm')"
            >
              {{ loading ? displayLoadingLabel : displayConfirmLabel }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed } from "vue";
import { Icon } from "@iconify/vue";
import { useI18n } from "../i18n/index.js";

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  title: { type: String, default: "" },
  message: { type: String, default: "" },
  confirmLabel: { type: String, default: "" },
  loadingLabel: { type: String, default: "" },
  cancelLabel: { type: String, default: "" },
  variant: { type: String, default: "danger" }, // 'danger' | 'warning'
  loading: { type: Boolean, default: false },
});

defineEmits(["update:modelValue", "confirm"]);

const { t } = useI18n();

const displayTitle = computed(() => props.title || t("common.areYouSure"));
const displayConfirmLabel = computed(
  () => props.confirmLabel || t("common.delete"),
);
const displayLoadingLabel = computed(
  () => props.loadingLabel || t("common.deleting"),
);
const displayCancelLabel = computed(
  () => props.cancelLabel || t("common.cancel"),
);
const iconBg = computed(() =>
  props.variant === "danger" ? "bg-red-50" : "bg-amber-50",
);
const iconColor = computed(() =>
  props.variant === "danger" ? "text-red-600" : "text-amber-600",
);
const confirmClass = computed(() =>
  props.variant === "danger" ? "btn-danger" : "btn-primary",
);
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.15s ease;
}
.modal-enter-active .bg-white,
.modal-leave-active .bg-white {
  transition:
    transform 0.15s ease,
    opacity 0.15s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
.modal-enter-from .bg-white,
.modal-leave-to .bg-white {
  transform: scale(0.95);
  opacity: 0;
}
</style>
