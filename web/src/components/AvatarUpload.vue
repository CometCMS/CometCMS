<template>
  <div class="flex items-center gap-5">
    <div
      class="relative group shrink-0 cursor-pointer"
      :class="[sizeClass, { 'drag-over': isDragging }]"
      :title="uploadTitle"
      @click="fileInput?.click()"
      @dragover.prevent="onDragOver"
      @dragleave.prevent="onDragLeave"
      @drop.prevent="onDrop"
    >
      <div
        class="w-full h-full overflow-hidden bg-theme-600 flex items-center justify-center text-white font-semibold select-none"
        :class="shapeClass"
      >
        <img
          v-if="src"
          :src="src"
          class="w-full h-full object-cover"
          :alt="fallback"
        />
        <span v-else :class="fallbackSizeClass">{{ fallback }}</span>
      </div>
      <div
        class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 flex items-center justify-center transition-opacity pointer-events-none"
        :class="shapeClass"
      >
        <Icon icon="mdi:camera" class="w-5 h-5 text-white" />
      </div>
    </div>

    <div v-if="!hideDescription" class="space-y-1.5 text-sm text-slate-500">
      <slot>
        <p>{{ instructions }}</p>
        <p>{{ requirements }}</p>
        <button
          v-if="src"
          type="button"
          @click="$emit('delete')"
          class="text-xs text-red-500 hover:text-red-700 transition-colors"
        >
          {{ removeLabel }}
        </button>
      </slot>
    </div>

    <input
      ref="fileInput"
      type="file"
      class="hidden"
      accept="image/jpeg,image/png,image/webp,image/gif"
      @change="handleFileChange"
    />
  </div>
</template>

<script setup>
import { ref, computed } from "vue";
import { Icon } from "@iconify/vue";

const props = defineProps({
  src: {
    type: String,
    default: null,
  },
  fallback: {
    type: String,
    default: "",
  },
  size: {
    type: String,
    default: "md", // 'sm' | 'md' | 'lg'
  },
  shape: {
    type: String,
    default: "circle", // 'circle' | 'rounded'
  },
  uploadTitle: {
    type: String,
    default: "Click to upload image",
  },
  instructions: {
    type: String,
    default: "Click the image to upload a new photo.",
  },
  requirements: {
    type: String,
    default: "JPEG, PNG, WebP or GIF · max 10 MB",
  },
  removeLabel: {
    type: String,
    default: "Remove image",
  },
  hideDescription: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(["upload", "delete"]);

const fileInput = ref(null);
const isDragging = ref(false);

function onDragOver() {
  isDragging.value = true;
}

function onDragLeave() {
  isDragging.value = false;
}

function onDrop(event) {
  isDragging.value = false;
  const file = event.dataTransfer?.files?.[0];
  if (file) {
    emit("upload", file);
  }
}

const sizeClass = computed(
  () =>
    ({
      sm: "w-10 h-10",
      md: "w-16 h-16",
      lg: "w-20 h-20",
    })[props.size] ?? "w-16 h-16",
);

const fallbackSizeClass = computed(
  () =>
    ({
      sm: "text-sm",
      md: "text-xl",
      lg: "text-2xl",
    })[props.size] ?? "text-xl",
);

const shapeClass = computed(() =>
  props.shape === "rounded" ? "rounded-xl" : "rounded-full",
);

function handleFileChange(event) {
  const file = event.target.files?.[0];
  if (file) {
    emit("upload", file);
  }
  event.target.value = "";
}
</script>

<style scoped>
.drag-over > div:first-child {
  outline: 2px dashed #60a5fa;
  outline-offset: 2px;
}
</style>
