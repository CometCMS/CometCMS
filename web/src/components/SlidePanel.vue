<template>
  <Teleport to="body">
    <Transition name="slide-panel">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-40 flex justify-end bg-slate-900/30"
        @mousedown.self="$emit('update:modelValue', false)"
      >
        <aside
          class="h-full w-full flex flex-col bg-white shadow-xl"
          :style="{ maxWidth: width }"
        >
          <!-- Header -->
          <div
            class="px-6 py-4 border-b border-slate-200 flex items-start justify-between gap-3 shrink-0"
          >
            <div class="min-w-0">
              <h2
                class="text-lg font-semibold text-slate-900 leading-tight truncate"
              >
                {{ title }}
              </h2>
              <p v-if="subtitle" class="text-xs text-slate-500 mt-0.5">
                {{ subtitle }}
              </p>
            </div>
            <button
              type="button"
              class="btn-secondary py-1.5 px-3 shrink-0"
              @click="$emit('update:modelValue', false)"
            >
              Close
            </button>
          </div>

          <!-- Body -->
          <div class="flex-1 overflow-y-auto">
            <slot />
          </div>
        </aside>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
defineProps({
  modelValue: { type: Boolean, required: true },
  title: { type: String, default: "" },
  subtitle: { type: String, default: "" },
  width: { type: String, default: "36rem" },
});

defineEmits(["update:modelValue"]);
</script>

<style scoped>
.slide-panel-enter-active,
.slide-panel-leave-active {
  transition: opacity 0.2s ease;
}
.slide-panel-enter-active aside,
.slide-panel-leave-active aside {
  transition: transform 0.2s ease;
}
.slide-panel-enter-from,
.slide-panel-leave-to {
  opacity: 0;
}
.slide-panel-enter-from aside,
.slide-panel-leave-to aside {
  transform: translateX(100%);
}
</style>
