<template>
  <!-- readonly preview -->
  <div
    v-if="readonly"
    class="min-h-10 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800"
  >
    <div v-if="isEmptyValue" class="text-slate-400">—</div>

    <div
      v-else-if="config.type === 'boolean'"
      class="inline-flex items-center gap-2"
    >
      <Icon
        :icon="modelValue ? 'mdi:check-circle' : 'mdi:close-circle'"
        :class="modelValue ? 'text-emerald-600' : 'text-slate-400'"
        class="h-4 w-4"
      />
      <span>{{ modelValue ? "true" : "false" }}</span>
    </div>

    <div
      v-else-if="config.type === 'color'"
      class="inline-flex items-center gap-2"
    >
      <span
        class="h-5 w-5 rounded border border-slate-300 shadow-sm"
        :style="{ backgroundColor: colorHex }"
      ></span>
      <span class="font-mono">{{ colorHex }}</span>
    </div>

    <div
      v-else-if="config.type === 'markdown'"
      class="prose-preview"
      v-html="readonlyMarkdownHtml"
    ></div>

    <pre
      v-else-if="config.type === 'json'"
      class="whitespace-pre-wrap break-words font-mono text-xs"
      >{{ jsonValue }}</pre
    >

    <div v-else-if="config.type === 'media'" class="space-y-2">
      <div class="flex flex-wrap gap-2">
        <a
          v-for="file in mediaValues"
          :key="file"
          :href="mediaUrl(file)"
          target="_blank"
          rel="noreferrer"
          class="inline-flex max-w-full items-center gap-2 rounded-md border border-slate-200 bg-white px-2 py-1 text-xs text-slate-700 hover:border-theme-300 hover:text-theme-700"
        >
          <span
            class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded bg-slate-100"
          >
            <img
              v-if="isImage(file)"
              :src="mediaUrl(file)"
              :alt="file"
              class="h-full w-full object-cover"
            />
            <Icon v-else v-bind="getFileIcon(file)" class="h-4 w-4" />
          </span>
          <span class="truncate">{{ file }}</span>
        </a>
      </div>
    </div>

    <div v-else-if="config.type === 'repeater'" class="space-y-2">
      <div
        v-for="(row, rowIndex) in repeaterRows"
        :key="row._rid"
        class="rounded-lg border border-slate-200 bg-white p-3"
      >
        <div class="mb-2 text-xs font-semibold uppercase text-slate-400">
          Row {{ rowIndex + 1 }}
        </div>
        <div class="grid gap-3" :class="subFieldGridCols">
          <div v-for="sub in config.subfields ?? []" :key="sub.key">
            <div class="mb-1 text-xs font-medium text-slate-500">
              {{ sub.label || sub.key }}
            </div>
            <FieldInput
              :name="`${name}[${rowIndex}][${sub.key}]`"
              :config="sub"
              :model-value="row[sub.key] ?? null"
              readonly
            />
          </div>
        </div>
      </div>
    </div>

    <div v-else-if="Array.isArray(modelValue)" class="flex flex-wrap gap-1.5">
      <span
        v-for="item in modelValue"
        :key="String(item)"
        class="rounded-md bg-white px-2 py-1 text-xs text-slate-700 ring-1 ring-slate-200"
        >{{ item }}</span
      >
    </div>

    <div v-else class="whitespace-pre-wrap break-words">{{ modelValue }}</div>
  </div>

  <!-- text / slug / number -->
  <input
    v-else-if="['text', 'slug', 'number'].includes(config.type)"
    :type="config.type === 'number' ? 'number' : 'text'"
    :name="name"
    :value="modelValue ?? ''"
    class="form-input w-full rounded-lg border-slate-300 text-sm"
    @input="$emit('update:modelValue', $event.target.value)"
  />

  <!-- range -->
  <div v-else-if="config.type === 'range'" class="space-y-2">
    <div class="flex items-center gap-3">
      <input
        type="range"
        :name="name"
        :min="rangeMin"
        :max="rangeMax"
        :step="rangeStep"
        :value="rangeValue"
        class="w-full accent-theme-600"
        @input="emitRangeValue($event.target.value)"
      />
      <output
        class="min-w-12 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-center text-sm font-medium text-slate-700"
      >
        {{ formatRangeNumber(rangeValue) }}
      </output>
    </div>
    <div class="flex justify-between text-xs text-slate-400">
      <span>{{ formatRangeNumber(rangeMin) }}</span>
      <span>{{ formatRangeNumber(rangeMax) }}</span>
    </div>
  </div>

  <!-- textarea -->
  <textarea
    v-else-if="config.type === 'textarea'"
    :name="name"
    :value="modelValue ?? ''"
    rows="4"
    class="form-textarea w-full rounded-lg border-slate-300 text-sm font-mono"
    @input="$emit('update:modelValue', $event.target.value)"
  />

  <!-- markdown -->
  <div v-else-if="config.type === 'markdown'" class="space-y-1.5">
    <div class="flex items-center justify-end gap-1">
      <button
        type="button"
        class="text-xs px-2.5 py-1 rounded-md border transition-colors"
        :class="
          markdownMode === 'visual'
            ? 'border-theme-400 bg-theme-50 text-theme-700 font-medium'
            : 'border-slate-200 text-slate-500 hover:border-slate-300'
        "
        @click="setMarkdownMode('visual')"
      >
        Visual
      </button>
      <button
        type="button"
        class="text-xs px-2.5 py-1 rounded-md border transition-colors"
        :class="
          markdownMode === 'raw'
            ? 'border-theme-400 bg-theme-50 text-theme-700 font-medium'
            : 'border-slate-200 text-slate-500 hover:border-slate-300'
        "
        @click="setMarkdownMode('raw')"
      >
        Raw
      </button>
    </div>

    <textarea
      v-if="markdownMode === 'raw'"
      :name="name"
      :value="modelValue ?? ''"
      rows="10"
      class="form-textarea w-full rounded-lg border-slate-300 text-sm font-mono"
      @input="$emit('update:modelValue', $event.target.value)"
    />

    <div
      v-else
      class="rounded-lg border border-slate-300 overflow-hidden focus-within:border-theme-400 focus-within:ring-1 focus-within:ring-theme-400"
    >
      <div
        class="flex flex-wrap gap-0.5 p-1.5 border-b border-slate-200 bg-slate-50"
      >
        <button
          type="button"
          class="tiptap-btn font-bold"
          title="Bold"
          @click="editor?.chain().focus().toggleBold().run()"
          :class="{ 'is-active': editor?.isActive('bold') }"
        >
          B
        </button>
        <button
          type="button"
          class="tiptap-btn italic"
          title="Italic"
          @click="editor?.chain().focus().toggleItalic().run()"
          :class="{ 'is-active': editor?.isActive('italic') }"
        >
          I
        </button>
        <button
          type="button"
          class="tiptap-btn"
          title="Strikethrough"
          @click="editor?.chain().focus().toggleStrike().run()"
          :class="{ 'is-active': editor?.isActive('strike') }"
        >
          <s>S</s>
        </button>
        <span class="w-px bg-slate-200 mx-0.5 self-stretch"></span>
        <button
          type="button"
          class="tiptap-btn"
          title="Heading 1"
          @click="editor?.chain().focus().toggleHeading({ level: 1 }).run()"
          :class="{ 'is-active': editor?.isActive('heading', { level: 1 }) }"
        >
          H1
        </button>
        <button
          type="button"
          class="tiptap-btn"
          title="Heading 2"
          @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()"
          :class="{ 'is-active': editor?.isActive('heading', { level: 2 }) }"
        >
          H2
        </button>
        <button
          type="button"
          class="tiptap-btn"
          title="Heading 3"
          @click="editor?.chain().focus().toggleHeading({ level: 3 }).run()"
          :class="{ 'is-active': editor?.isActive('heading', { level: 3 }) }"
        >
          H3
        </button>
        <span class="w-px bg-slate-200 mx-0.5 self-stretch"></span>
        <button
          type="button"
          class="tiptap-btn"
          title="Bullet list"
          @click="editor?.chain().focus().toggleBulletList().run()"
          :class="{ 'is-active': editor?.isActive('bulletList') }"
        >
          • List
        </button>
        <button
          type="button"
          class="tiptap-btn"
          title="Ordered list"
          @click="editor?.chain().focus().toggleOrderedList().run()"
          :class="{ 'is-active': editor?.isActive('orderedList') }"
        >
          1. List
        </button>
        <span class="w-px bg-slate-200 mx-0.5 self-stretch"></span>
        <button
          type="button"
          class="tiptap-btn"
          title="Blockquote"
          @click="editor?.chain().focus().toggleBlockquote().run()"
          :class="{ 'is-active': editor?.isActive('blockquote') }"
        >
          "
        </button>
        <button
          type="button"
          class="tiptap-btn font-mono text-xs"
          title="Inline code"
          @click="editor?.chain().focus().toggleCode().run()"
          :class="{ 'is-active': editor?.isActive('code') }"
        >
          &lt;/&gt;
        </button>
        <button
          type="button"
          class="tiptap-btn font-mono text-xs"
          title="Code block"
          @click="editor?.chain().focus().toggleCodeBlock().run()"
          :class="{ 'is-active': editor?.isActive('codeBlock') }"
        >
          ```
        </button>
        <span class="w-px bg-slate-200 mx-0.5 self-stretch"></span>
        <button
          type="button"
          class="tiptap-btn"
          title="Horizontal rule"
          @click="editor?.chain().focus().setHorizontalRule().run()"
        >
          —
        </button>
        <button
          type="button"
          class="tiptap-btn"
          title="Undo"
          @click="editor?.chain().focus().undo().run()"
        >
          ↩
        </button>
        <button
          type="button"
          class="tiptap-btn"
          title="Redo"
          @click="editor?.chain().focus().redo().run()"
        >
          ↪
        </button>
      </div>
      <EditorContent :editor="editor" class="tiptap-content" />
    </div>
  </div>

  <!-- boolean -->
  <ToggleSwitch
    v-else-if="config.type === 'boolean'"
    :model-value="!!modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
  />

  <!-- date -->
  <input
    v-else-if="config.type === 'date'"
    type="date"
    :name="name"
    :value="modelValue ?? ''"
    class="form-input w-full rounded-lg border-slate-300 text-sm"
    @input="$emit('update:modelValue', $event.target.value)"
  />

  <!-- datetime -->
  <input
    v-else-if="config.type === 'datetime'"
    type="datetime-local"
    :name="name"
    :value="modelValue ?? ''"
    class="form-input w-full rounded-lg border-slate-300 text-sm"
    @input="$emit('update:modelValue', $event.target.value)"
  />

  <!-- select -->
  <SearchableSelect
    v-else-if="config.type === 'select' && config.multiple"
    :options="(config.options ?? []).map((o) => ({ value: o, label: o }))"
    :model-value="normalizeChoiceValues(modelValue)"
    :multiple="true"
    placeholder="Select options…"
    @update:model-value="$emit('update:modelValue', $event)"
  />

  <select
    v-else-if="config.type === 'select'"
    :name="name"
    :value="normalizeChoiceValue(modelValue) ?? ''"
    class="form-select w-full rounded-lg border-slate-300 text-sm"
    @change="$emit('update:modelValue', $event.target.value)"
  >
    <option value="">— select —</option>
    <option v-for="opt in config.options ?? []" :key="opt" :value="opt">
      {{ opt }}
    </option>
  </select>

  <!-- relation -->
  <div v-else-if="config.type === 'relation'">
    <SearchableSelect
      :options="relationOptions"
      :model-value="relationValue"
      :multiple="!!config.multiple"
      :placeholder="
        config.target ? 'Select entry…' : 'Choose a target post type first'
      "
      :disabled="!config.target"
      :loading="relationLoading"
      @update:model-value="$emit('update:modelValue', $event)"
      @search="onRelationSearch"
    />
  </div>

  <!-- media -->
  <div v-else-if="config.type === 'media'" class="space-y-2">
    <div class="flex items-start gap-3">
      <div
        v-if="config.multiple"
        class="grid h-24 w-24 shrink-0 grid-cols-2 gap-1 rounded-lg border border-slate-200 bg-slate-100 p-1 transition-colors"
        :class="
          isMediaDropActive
            ? 'border-theme-400 bg-theme-50 ring-2 ring-theme-200'
            : ''
        "
        @dragenter.prevent="onMediaDragEnter"
        @dragover.prevent="onMediaDragOver"
        @dragleave.prevent="onMediaDragLeave"
        @drop.prevent="onMediaDrop"
      >
        <div
          v-for="file in mediaPreviewTiles"
          :key="file"
          class="overflow-hidden rounded-md bg-slate-200 flex items-center justify-center"
        >
          <img
            v-if="isImage(file)"
            :src="mediaUrl(file)"
            class="h-full w-full object-cover"
            :alt="file"
          />
          <Icon v-else v-bind="getFileIcon(file)" class="w-5 h-5" />
        </div>
        <div
          v-if="mediaPreviewOverflow > 0"
          class="rounded-md bg-slate-900/75 text-white text-xs font-semibold flex items-center justify-center"
        >
          +{{ mediaPreviewOverflow }}
        </div>
        <div
          v-for="index in mediaPreviewEmptyTiles"
          :key="`media-empty-${index}`"
          class="rounded-md bg-slate-200/70"
        ></div>
      </div>

      <div
        v-else
        class="w-24 h-24 rounded-lg border border-slate-200 bg-slate-100 overflow-hidden flex items-center justify-center shrink-0 transition-colors"
        :class="
          isMediaDropActive
            ? 'border-theme-400 bg-theme-50 ring-2 ring-theme-200'
            : ''
        "
        @dragenter.prevent="onMediaDragEnter"
        @dragover.prevent="onMediaDragOver"
        @dragleave.prevent="onMediaDragLeave"
        @drop.prevent="onMediaDrop"
      >
        <template v-if="mediaValues.length > 0 && isImage(mediaValues[0])">
          <img
            :src="mediaUrl(mediaValues[0])"
            class="w-full h-full object-cover"
            :alt="String(mediaValues[0])"
          />
        </template>
        <template v-else-if="mediaValues.length > 0">
          <Icon v-bind="getFileIcon(mediaValues[0])" class="w-8 h-8" />
        </template>
        <Icon v-else icon="mdi:image-outline" class="w-8 h-8 text-slate-400" />
      </div>

      <div class="min-w-0 flex-1 space-y-2">
        <input
          v-for="file in mediaValues"
          :key="file"
          type="hidden"
          :name="`${name}[]`"
          :value="file"
        />
        <input
          v-if="mediaValues.length === 0"
          type="hidden"
          :name="name"
          value=""
        />
        <p
          class="text-sm text-slate-700 truncate"
          :title="String(modelValue ?? '')"
        >
          {{ mediaLabel }}
        </p>
        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="btn-secondary"
            :class="
              isMediaDropActive ? 'ring-2 ring-theme-300 ring-offset-1' : ''
            "
            :disabled="mediaUploading"
            @click="pickerOpen = true"
            @dragenter.prevent="onMediaDragEnter"
            @dragover.prevent="onMediaDragOver"
            @dragleave.prevent="onMediaDragLeave"
            @drop.prevent="onMediaDrop"
          >
            {{ config.multiple ? "Choose media files" : "Choose media" }}
          </button>
          <button
            v-if="mediaValues.length > 0"
            type="button"
            class="btn-secondary"
            @click="$emit('update:modelValue', [])"
          >
            Clear
          </button>
        </div>
        <p v-if="mediaUploading" class="text-xs text-theme-700">
          Uploading dropped file{{ config.multiple ? "s" : "" }}...
        </p>
        <p v-else-if="isMediaDropActive" class="text-xs text-theme-700">
          Drop to upload and select
        </p>
        <p v-if="mediaUploadError" class="text-xs text-red-600">
          {{ mediaUploadError }}
        </p>
      </div>
    </div>

    <!-- Reorderable selected files list (multiple mode) -->
    <div
      v-if="config.multiple && mediaValues.length > 0"
      class="overflow-hidden rounded-lg border border-slate-200"
    >
      <div
        v-for="(file, index) in mediaValues"
        :key="file"
        class="flex items-center gap-2 border-b border-slate-100 bg-white px-2 py-1.5 last:border-0 transition-colors"
        :class="
          reorderDropIdx === index && reorderDragIdx !== index
            ? 'bg-theme-50 ring-1 ring-inset ring-theme-300'
            : ''
        "
        draggable="true"
        @dragstart="onMediaItemDragStart(index, $event)"
        @dragover.prevent="reorderDropIdx = index"
        @dragend="onMediaItemDragEnd"
        @drop.prevent="onMediaItemDrop(index)"
      >
        <Icon
          icon="mdi:drag-vertical"
          class="h-4 w-4 shrink-0 cursor-grab text-slate-400 active:cursor-grabbing"
        />
        <div
          class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded bg-slate-100"
        >
          <img
            v-if="isImage(file)"
            :src="mediaUrl(file)"
            :alt="file"
            class="h-full w-full object-cover"
          />
          <Icon v-else v-bind="getFileIcon(file)" class="h-4 w-4" />
        </div>
        <span
          class="min-w-0 flex-1 truncate text-xs text-slate-700"
          :title="file"
          >{{ file }}</span
        >
        <button
          type="button"
          class="shrink-0 text-slate-400 transition-colors hover:text-red-500"
          @click="removeMediaItem(index)"
        >
          <Icon icon="mdi:close" class="h-4 w-4" />
        </button>
      </div>
    </div>

    <MediaPickerModal
      v-if="pickerOpen"
      :selected="mediaValues"
      :multiple="!!config.multiple"
      @close="pickerOpen = false"
      @select="selectMedia"
    />
  </div>

  <!-- json -->
  <textarea
    v-else-if="config.type === 'json'"
    :name="name"
    :value="jsonValue"
    rows="5"
    class="form-textarea w-full rounded-lg border-slate-300 text-sm font-mono"
    @input="onJsonInput($event.target.value)"
  />

  <!-- repeater -->
  <div v-else-if="config.type === 'repeater'" class="space-y-2">
    <div
      v-for="(row, rowIndex) in repeaterRows"
      :key="row._rid"
      class="rounded-xl border border-slate-200 bg-slate-50 overflow-hidden"
    >
      <!-- Row header -->
      <div
        class="flex items-center justify-between px-3 py-2 bg-slate-100 border-b border-slate-200 cursor-pointer select-none"
        @click="toggleRepeaterRow(row._rid)"
      >
        <div class="flex items-center gap-2 min-w-0">
          <Icon
            icon="mdi:chevron-right"
            class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
            :class="{ 'rotate-90': !collapsedRows.has(row._rid) }"
          />
          <span
            class="text-xs font-semibold text-slate-500 uppercase tracking-wider"
            >Row {{ rowIndex + 1 }}</span
          >
          <span
            v-if="collapsedRows.has(row._rid)"
            class="truncate text-xs text-slate-400"
            >{{ repeaterRowSummary(row) }}</span
          >
        </div>
        <div class="flex items-center gap-1" @click.stop>
          <button
            type="button"
            :disabled="rowIndex === 0"
            class="p-1 rounded text-slate-400 hover:text-slate-700 disabled:opacity-30 transition-colors"
            title="Move up"
            @click="moveRepeaterRow(rowIndex, -1)"
          >
            <Icon icon="mdi:chevron-up" class="w-4 h-4" />
          </button>
          <button
            type="button"
            :disabled="rowIndex === repeaterRows.length - 1"
            class="p-1 rounded text-slate-400 hover:text-slate-700 disabled:opacity-30 transition-colors"
            title="Move down"
            @click="moveRepeaterRow(rowIndex, 1)"
          >
            <Icon icon="mdi:chevron-down" class="w-4 h-4" />
          </button>
          <button
            type="button"
            class="p-1 rounded text-slate-400 hover:text-red-500 transition-colors"
            title="Remove row"
            @click="removeRepeaterRow(rowIndex)"
          >
            <Icon icon="mdi:close" class="w-4 h-4" />
          </button>
        </div>
      </div>
      <!-- Row fields -->
      <div
        v-if="!collapsedRows.has(row._rid)"
        class="p-3 grid gap-3"
        :class="subFieldGridCols"
      >
        <div v-for="sub in config.subfields ?? []" :key="sub.key">
          <label class="text-xs font-medium text-slate-600 block mb-1">
            {{ sub.label || sub.key }}
            <span v-if="sub.required" class="text-red-500 ml-0.5">*</span>
          </label>
          <FieldInput
            :name="`${name}[${rowIndex}][${sub.key}]`"
            :config="sub"
            :model-value="row[sub.key] ?? null"
            @update:model-value="updateRepeaterCell(rowIndex, sub.key, $event)"
          />
        </div>
      </div>
    </div>

    <button
      type="button"
      class="w-full py-2.5 rounded-xl border-2 border-dashed border-slate-300 text-sm text-slate-500 hover:border-theme-400 hover:text-theme-600 transition-colors"
      @click="addRepeaterRow"
    >
      + Add row
    </button>
  </div>

  <!-- color -->
  <div v-else-if="config.type === 'color'" class="flex items-center gap-2">
    <div class="relative">
      <input
        type="color"
        :value="colorHex"
        :id="`${name}-color-picker`"
        class="sr-only"
        @input="$emit('update:modelValue', $event.target.value)"
      />
      <label
        :for="`${name}-color-picker`"
        class="block w-9 h-9 rounded-lg border border-slate-300 cursor-pointer shadow-sm transition-shadow hover:shadow-md"
        :style="{ backgroundColor: colorHex }"
        :title="colorHex"
      ></label>
    </div>
    <input
      type="text"
      :value="colorHex"
      class="form-input w-32 rounded-lg border-slate-300 text-sm font-mono"
      placeholder="#000000"
      maxlength="7"
      @blur="onColorHexInput($event.target.value)"
      @keydown.enter.prevent="onColorHexInput($event.target.value)"
    />
  </div>

  <!-- fallback -->
  <input
    v-else
    type="text"
    :name="name"
    :value="modelValue ?? ''"
    class="form-input w-full rounded-lg border-slate-300 text-sm"
    @input="$emit('update:modelValue', $event.target.value)"
  />
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import MediaPickerModal from "./MediaPickerModal.vue";
import SearchableSelect from "./SearchableSelect.vue";
import ToggleSwitch from "./ToggleSwitch.vue";
import FieldInput from "./FieldInput.vue";
import { api, getActiveWorkspace } from "../api/index.js";
import { useEditor, EditorContent } from "@tiptap/vue-3";
import StarterKit from "@tiptap/starter-kit";
import { marked } from "marked";
import TurndownService from "turndown";
import { Icon } from "@iconify/vue";
import { fieldDefaultValue } from "../composables/fieldDefaults.js";

const turndown = new TurndownService({
  headingStyle: "atx",
  codeBlockStyle: "fenced",
});

const props = defineProps({
  name: { type: String, required: true },
  config: { type: Object, required: true },
  modelValue: { default: null },
  readonly: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);
const pickerOpen = ref(false);
const mediaUploadError = ref("");
const mediaUploading = ref(false);
const mediaDragDepth = ref(0);
const isMediaDropActive = computed(() => mediaDragDepth.value > 0);

// ---- Markdown visual editor ----
const markdownMode = ref("visual");

const editor = useEditor({
  extensions: [StarterKit],
  content: "",
  onCreate({ editor }) {
    if (props.config.type !== "markdown") return;
    const html = marked.parse(String(props.modelValue ?? ""), { async: false });
    editor.commands.setContent(html, false);
  },
  onUpdate({ editor }) {
    if (props.config.type !== "markdown") return;
    if (markdownMode.value === "visual") {
      emit("update:modelValue", turndown.turndown(editor.getHTML()));
    }
  },
});

function setMarkdownMode(mode) {
  if (props.config.type !== "markdown") return;
  if (mode === markdownMode.value) return;
  if (mode === "visual") {
    const html = marked.parse(String(props.modelValue ?? ""), { async: false });
    editor.value?.commands.setContent(html, false);
  }
  markdownMode.value = mode;
}

watch(
  () => props.modelValue,
  (val) => {
    if (props.config.type !== "markdown") return;
    if (markdownMode.value !== "visual") return;
    const html = marked.parse(String(val ?? ""), { async: false });
    const currentHtml = editor.value?.getHTML() ?? "";
    if (currentHtml !== html) {
      editor.value?.commands.setContent(html, false);
    }
  },
);

watch(
  () => props.modelValue,
  (val) => {
    if (props.config.type !== "media") return;

    const normalized = normalizeMediaModel(val);

    if (!Array.isArray(val)) {
      emit("update:modelValue", normalized);
      return;
    }

    const current = val
      .map((item) => String(item ?? "").trim())
      .filter(Boolean);

    if (JSON.stringify(current) !== JSON.stringify(normalized)) {
      emit("update:modelValue", normalized);
    }
  },
  { immediate: true },
);

watch(
  () => props.modelValue,
  (val) => {
    if (!props.config.multiple) return;
    if (props.config.type !== "relation" && props.config.type !== "select")
      return;

    const normalized = normalizeChoiceValues(val);

    if (!Array.isArray(val)) {
      emit("update:modelValue", normalized);
      return;
    }

    const current = val
      .map((item) => String(item ?? "").trim())
      .filter(Boolean);

    if (JSON.stringify(current) !== JSON.stringify(normalized)) {
      emit("update:modelValue", normalized);
    }
  },
  { immediate: true },
);

onBeforeUnmount(() => editor.value?.destroy());
const relationEntries = ref([]);
const relationLoading = ref(false);
const relationSearch = ref("");
let relationSearchTimer = null;

const jsonValue = computed(() => {
  if (props.modelValue === null || props.modelValue === undefined) return "";
  if (typeof props.modelValue === "string") return props.modelValue;
  return JSON.stringify(props.modelValue, null, 2);
});

const isEmptyValue = computed(() => {
  const value = props.modelValue;
  if (value === null || value === undefined || value === "") return true;
  if (Array.isArray(value)) return value.length === 0;
  if (typeof value === "object") return Object.keys(value).length === 0;
  return false;
});

const readonlyMarkdownHtml = computed(() =>
  marked.parse(String(props.modelValue ?? ""), { async: false }),
);

const rangeMin = computed(() => numberOr(props.config.min, 0));
const rangeMax = computed(() =>
  Math.max(numberOr(props.config.max, 100), rangeMin.value),
);
const rangeStep = computed(() => {
  const step = numberOr(props.config.step, 1);
  return step > 0 ? step : 1;
});
const rangeValue = computed(() => {
  const fallback =
    props.config.default === null ||
    props.config.default === undefined ||
    props.config.default === ""
      ? rangeMin.value
      : props.config.default;

  return clamp(
    numberOr(props.modelValue, numberOr(fallback, rangeMin.value)),
    rangeMin.value,
    rangeMax.value,
  );
});

function onJsonInput(raw) {
  try {
    emit("update:modelValue", JSON.parse(raw));
  } catch {
    emit("update:modelValue", raw);
  }
}

// ---- Color ----
const colorHex = computed(() => {
  const v = String(props.modelValue ?? "");
  return /^#[0-9a-fA-F]{3,6}$/.test(v) ? v : "#000000";
});

function onColorHexInput(value) {
  const normalized = value.trim().startsWith("#")
    ? value.trim()
    : `#${value.trim()}`;
  if (
    /^#[0-9a-fA-F]{3}$/.test(normalized) ||
    /^#[0-9a-fA-F]{6}$/.test(normalized)
  ) {
    emit("update:modelValue", normalized.toLowerCase());
  }
}

function emitRangeValue(value) {
  emit("update:modelValue", numberOr(value, rangeMin.value));
}

function formatRangeNumber(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) return String(value ?? "");

  const decimals = props.config.display_decimals;
  if (decimals === "full") {
    return String(value);
  }

  const fixedDigits =
    decimals === null || decimals === undefined || decimals === ""
      ? 0
      : Number(decimals);
  if (!Number.isInteger(fixedDigits) || fixedDigits < 0 || fixedDigits > 3) {
    return String(value);
  }

  return number.toLocaleString(undefined, {
    minimumFractionDigits: fixedDigits,
    maximumFractionDigits: fixedDigits,
  });
}

const reorderDragIdx = ref(-1);
const reorderDropIdx = ref(-1);

function onMediaItemDragStart(index, event) {
  reorderDragIdx.value = index;
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("text/plain", String(index));
  }
}

function onMediaItemDragEnd() {
  reorderDragIdx.value = -1;
  reorderDropIdx.value = -1;
}

function onMediaItemDrop(index) {
  const from = reorderDragIdx.value;
  if (from < 0 || from === index) return;
  const arr = [...mediaValues.value];
  const [item] = arr.splice(from, 1);
  arr.splice(index, 0, item);
  emit("update:modelValue", arr);
  reorderDragIdx.value = -1;
  reorderDropIdx.value = -1;
}

function removeMediaItem(index) {
  const arr = [...mediaValues.value];
  arr.splice(index, 1);
  emit("update:modelValue", arr);
}

function selectMedia(file) {
  emit("update:modelValue", normalizeMediaModel(file));
  pickerOpen.value = false;
}

function mediaEventHasFiles(event) {
  return Array.from(event.dataTransfer?.types ?? []).includes("Files");
}

function onMediaDragEnter(event) {
  if (!mediaEventHasFiles(event)) return;
  mediaDragDepth.value++;
}

function onMediaDragOver(event) {
  if (!mediaEventHasFiles(event)) return;
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = "copy";
  }
}

function onMediaDragLeave(event) {
  if (!mediaEventHasFiles(event)) return;
  mediaDragDepth.value = Math.max(0, mediaDragDepth.value - 1);
}

async function onMediaDrop(event) {
  const dropped = Array.from(event.dataTransfer?.files ?? []);
  mediaDragDepth.value = 0;

  if (dropped.length === 0) return;

  await uploadDroppedMedia(dropped);
}

async function uploadDroppedMedia(files) {
  mediaUploadError.value = "";
  mediaUploading.value = true;

  const formData = new FormData();
  for (const file of files) {
    formData.append("media[]", file);
  }

  try {
    const res = await api.media.upload(formData);
    const uploaded = Array.isArray(res?.data) ? res.data : [];
    const uploadedNames = uploaded
      .map((item) => uploadedMediaFilename(item))
      .filter(Boolean);

    if (uploadedNames.length === 0) {
      mediaUploadError.value = "Upload finished, but no files were returned.";
      return;
    }

    emit(
      "update:modelValue",
      props.config.multiple ? uploadedNames : uploadedNames.slice(0, 1),
    );
  } catch (err) {
    mediaUploadError.value = err.message ?? "Could not upload dropped media.";
  } finally {
    mediaUploading.value = false;
  }
}

function uploadedMediaFilename(item) {
  if (item && typeof item === "object") {
    if (item.url) {
      const fromUrl = extractMediaFilename(item.url);
      if (fromUrl !== "") {
        return fromUrl;
      }
    }

    if (item.filename) {
      const fromFilename = extractMediaFilename(item.filename);
      if (fromFilename !== "") {
        return fromFilename;
      }
    }

    const rawName = String(item.name ?? item.filename ?? "").trim();
    if (rawName !== "") {
      return rawName;
    }
  }

  return extractMediaFilename(item);
}

const imageExts = new Set(["jpg", "jpeg", "png", "gif", "webp", "svg", "avif"]);

function getFileIcon(name) {
  const ext = String(name).split(".").pop()?.toLowerCase() ?? "";
  if (ext === "pdf") return { icon: "mdi:file-pdf-box", class: "text-red-500" };
  if (["doc", "docx", "odt"].includes(ext))
    return { icon: "mdi:file-word-box", class: "text-blue-600" };
  if (["xls", "xlsx", "ods", "csv"].includes(ext))
    return { icon: "mdi:file-excel-box", class: "text-green-600" };
  if (["ppt", "pptx", "odp"].includes(ext))
    return { icon: "mdi:file-powerpoint-box", class: "text-orange-500" };
  if (["zip", "rar", "7z", "tar", "gz", "bz2"].includes(ext))
    return { icon: "mdi:zip-box", class: "text-yellow-600" };
  if (
    [
      "mp4",
      "webm",
      "mov",
      "m4v",
      "avi",
      "mkv",
      "mpeg",
      "mpg",
      "ogv",
      "3gp",
      "3g2",
    ].includes(ext)
  )
    return { icon: "mdi:file-video-outline", class: "text-pink-500" };
  if (["mp3", "wav", "ogg", "m4a", "aac", "flac"].includes(ext))
    return { icon: "mdi:file-music-outline", class: "text-purple-500" };
  if (["txt", "md", "rtf"].includes(ext))
    return { icon: "mdi:file-document-outline", class: "text-slate-500" };
  return { icon: "mdi:file-outline", class: "text-slate-400" };
}
const mediaValues = computed(() => normalizeMediaValues(props.modelValue));
const mediaLabel = computed(() => {
  if (mediaValues.value.length === 0) return "No media selected";
  if (props.config.multiple)
    return `${mediaValues.value.length} media file${mediaValues.value.length === 1 ? "" : "s"} selected`;
  return mediaValues.value[0];
});
const mediaPreviewTiles = computed(() =>
  mediaValues.value.slice(0, mediaValues.value.length > 4 ? 3 : 4),
);
const mediaPreviewOverflow = computed(() =>
  Math.max(mediaValues.value.length - mediaPreviewTiles.value.length, 0),
);
const mediaPreviewEmptyTiles = computed(() =>
  Math.max(
    4 -
      mediaPreviewTiles.value.length -
      (mediaPreviewOverflow.value > 0 ? 1 : 0),
    0,
  ),
);
const relationValue = computed(() => {
  if (props.config.multiple) {
    return normalizeChoiceValues(props.modelValue);
  }

  return normalizeChoiceValue(props.modelValue) ?? "";
});

function isImage(value) {
  const ext = String(value).split(".").pop()?.toLowerCase() ?? "";
  return imageExts.has(ext);
}

function normalizeMediaValues(value) {
  return normalizeMediaModel(value);
}

function normalizeMediaModel(value) {
  const source = Array.isArray(value) ? value : toStringList(value);
  const normalized = Array.from(
    new Set(source.map(extractMediaFilename).filter(Boolean)),
  );

  return props.config.multiple ? normalized : normalized.slice(0, 1);
}

function toStringList(value) {
  if (Array.isArray(value)) {
    return value.flatMap((item) => splitListLikeValue(item));
  }

  return splitListLikeValue(value);
}

function splitListLikeValue(value) {
  if (value === null || value === undefined) {
    return [];
  }

  if (typeof value === "object" && !Array.isArray(value)) {
    const choice = String(value.value ?? value.id ?? "").trim();
    if (choice !== "") {
      return [choice];
    }

    const media = extractMediaFilename(value);
    if (media !== "") {
      return [media];
    }

    return [];
  }

  const raw = String(value).trim();

  if (!raw) {
    return [];
  }

  if (raw.startsWith("[") && raw.endsWith("]")) {
    try {
      const parsed = JSON.parse(raw);

      if (Array.isArray(parsed)) {
        return parsed.map((item) => String(item ?? "").trim()).filter(Boolean);
      }
    } catch {
      // Fall through to comma-separated parsing.
    }
  }

  return raw
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
}

function extractMediaFilename(value) {
  let raw =
    value && typeof value === "object"
      ? String(value.name ?? value.filename ?? value.url ?? "")
      : String(value ?? "");

  raw = raw.trim();

  if (!raw) {
    return "";
  }

  try {
    raw = decodeURIComponent(raw);
  } catch {
    // Keep raw value if it is not URI encoded.
  }

  const withoutQuery = raw.split(/[?#]/, 1)[0];
  const normalizedPath = withoutQuery.replace(/\\+/g, "/");
  const parts = normalizedPath.split("/").filter(Boolean);

  return parts.length > 0 ? parts[parts.length - 1] : "";
}

function normalizeChoiceValue(value) {
  if (value === null || value === undefined || value === "") return null;
  if (Array.isArray(value)) {
    const first = normalizeChoiceValues(value)[0];
    return first ?? null;
  }

  if (typeof value === "object") {
    const objectValue = String(value.value ?? value.id ?? "").trim();
    return objectValue === "" ? null : objectValue;
  }

  return String(value);
}

function normalizeChoiceValues(value) {
  return Array.from(new Set(toStringList(value)));
}

function mediaUrl(value) {
  return `/media/${encodeURIComponent(getActiveWorkspace())}/${encodeURIComponent(String(value))}`;
}

function numberOr(value, fallback) {
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max);
}

function onRelationSearch(q) {
  relationSearch.value = q;
}

const relationOptions = computed(() =>
  relationEntries.value.map((entry) => ({
    value: entry.id,
    label: entry.title || entry.id,
  })),
);

async function loadRelationEntries() {
  if (props.config.type !== "relation" || !props.config.target) {
    relationEntries.value = [];
    return;
  }

  relationLoading.value = true;
  try {
    const params = { limit: 100, sort: "title", order: "asc" };
    if (relationSearch.value.trim()) {
      params.q = relationSearch.value.trim();
    }

    const res = await api.content.list(props.config.target, params);
    relationEntries.value = res.data;
  } finally {
    relationLoading.value = false;
  }
}

onMounted(loadRelationEntries);

watch(
  () => props.config.target,
  () => {
    emit("update:modelValue", props.config.multiple ? [] : null);
    loadRelationEntries();
  },
);

watch(relationSearch, () => {
  clearTimeout(relationSearchTimer);
  relationSearchTimer = setTimeout(loadRelationEntries, 250);
});

// ---- Repeater ----
let ridCounter = 0;

function toRepeaterRows(value) {
  if (!Array.isArray(value)) return [];
  return value.map((row) => ({ ...row, _rid: ridCounter++ }));
}

const repeaterRows = ref(toRepeaterRows(props.modelValue));
const collapsedRows = ref(new Set());

watch(
  () => props.modelValue,
  (val) => {
    if (props.config.type !== "repeater") return;
    // Only sync from outside if it genuinely differs (avoid stomping in-progress edits)
    const external = repeaterRows.value.map(({ _rid, ...r }) => r);
    if (JSON.stringify(external) !== JSON.stringify(val)) {
      repeaterRows.value = toRepeaterRows(val);
    }
  },
  { deep: true },
);

function emitRepeater() {
  emit(
    "update:modelValue",
    repeaterRows.value.map(({ _rid, ...row }) => row),
  );
}

function addRepeaterRow() {
  const row = { _rid: ridCounter++ };
  for (const sub of props.config.subfields ?? []) {
    row[sub.key] = fieldDefaultValue(sub);
  }
  repeaterRows.value.push(row);
  emitRepeater();
}

function removeRepeaterRow(index) {
  const rid = repeaterRows.value[index]._rid;
  const next = new Set(collapsedRows.value);
  next.delete(rid);
  collapsedRows.value = next;
  repeaterRows.value.splice(index, 1);
  emitRepeater();
}

function toggleRepeaterRow(rid) {
  const next = new Set(collapsedRows.value);
  if (next.has(rid)) {
    next.delete(rid);
  } else {
    next.add(rid);
  }
  collapsedRows.value = next;
}

function repeaterRowSummary(row) {
  for (const sub of props.config.subfields ?? []) {
    const val = row[sub.key];
    if (val === null || val === undefined || val === "") continue;
    if (typeof val === "string")
      return val.length > 60 ? val.slice(0, 60) + "…" : val;
    if (typeof val === "number" || typeof val === "boolean") return String(val);
    if (Array.isArray(val) && val.length > 0)
      return `${val.length} item${val.length === 1 ? "" : "s"}`;
  }
  return "—";
}

function moveRepeaterRow(index, direction) {
  const target = index + direction;
  if (target < 0 || target >= repeaterRows.value.length) return;
  const rows = repeaterRows.value;
  [rows[index], rows[target]] = [rows[target], rows[index]];
  emitRepeater();
}

function updateRepeaterCell(rowIndex, key, value) {
  repeaterRows.value[rowIndex][key] = value;
  emitRepeater();
}

const subFieldGridCols = computed(() => {
  const count = (props.config.subfields ?? []).length;
  if (count >= 3) return "grid-cols-1 sm:grid-cols-2 lg:grid-cols-3";
  if (count === 2) return "grid-cols-1 sm:grid-cols-2";
  return "grid-cols-1";
});
</script>

<style scoped>
.tiptap-btn {
  @apply text-xs px-2 py-1 rounded text-slate-600 hover:bg-slate-200 transition-colors;
}

.tiptap-btn.is-active {
  @apply bg-slate-200 text-slate-900 font-semibold;
}

.tiptap-content :deep(.ProseMirror) {
  @apply p-3 min-h-[16rem] text-sm text-slate-800 outline-none;
}

.tiptap-content :deep(.ProseMirror h1) {
  @apply text-2xl font-bold mt-4 mb-2;
}

.tiptap-content :deep(.ProseMirror h2) {
  @apply text-xl font-bold mt-3 mb-2;
}

.tiptap-content :deep(.ProseMirror h3) {
  @apply text-lg font-semibold mt-3 mb-1;
}

.tiptap-content :deep(.ProseMirror p) {
  @apply my-2;
}

.tiptap-content :deep(.ProseMirror ul) {
  @apply list-disc pl-5 my-2;
}

.tiptap-content :deep(.ProseMirror ol) {
  @apply list-decimal pl-5 my-2;
}

.tiptap-content :deep(.ProseMirror blockquote) {
  @apply border-l-4 border-slate-300 pl-4 italic text-slate-500 my-2;
}

.tiptap-content :deep(.ProseMirror code) {
  @apply bg-slate-100 rounded px-1 py-0.5 font-mono text-xs;
}

.tiptap-content :deep(.ProseMirror pre) {
  @apply bg-slate-900 text-slate-100 rounded-lg p-3 my-2 font-mono text-xs overflow-x-auto;
}

.tiptap-content :deep(.ProseMirror pre code) {
  @apply bg-transparent p-0;
}

.tiptap-content :deep(.ProseMirror hr) {
  @apply border-slate-300 my-4;
}

.tiptap-content :deep(.ProseMirror strong) {
  @apply font-bold;
}

.tiptap-content :deep(.ProseMirror em) {
  @apply italic;
}

.tiptap-content :deep(.ProseMirror s) {
  @apply line-through;
}
</style>
