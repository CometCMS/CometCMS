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

    <div
      v-else-if="config.type === 'html'"
      class="prose-preview"
      v-html="readonlyHtml"
    ></div>

    <pre
      v-else-if="config.type === 'json'"
      class="whitespace-pre-wrap break-words font-mono text-xs"
      >{{ jsonValue }}</pre
    >

    <div v-else-if="config.type === 'media'" class="space-y-2">
      <div class="flex flex-wrap gap-2">
        <div
          v-for="file in mediaValues"
          :key="file"
          class="inline-flex max-w-full items-center gap-2 rounded-md border border-slate-200 bg-white px-2 py-1 text-xs text-slate-700 hover:border-theme-300 hover:text-theme-700"
        >
          <button
            type="button"
            class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded bg-slate-100"
            :title="`Preview ${file}`"
            @click="openMediaPreview(file)"
          >
            <img
              v-if="isImage(file)"
              :src="mediaThumbUrl(file)"
              :alt="file"
              class="h-full w-full object-cover"
            />
            <Icon v-else v-bind="getFileIcon(file)" class="h-4 w-4" />
          </button>
          <a
            :href="mediaUrl(file)"
            target="_blank"
            rel="noreferrer"
            class="truncate"
            >{{ file }}</a
          >
        </div>
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

  <!-- markdown / html -->
  <div v-else-if="['markdown', 'html'].includes(config.type)" class="space-y-1.5">
    <div class="flex items-center justify-end gap-1">
      <button
        type="button"
        class="text-xs px-2.5 py-1 rounded-md border transition-colors"
        :class="
          richTextMode === 'visual'
            ? 'border-theme-400 bg-theme-50 text-theme-700 font-medium'
            : 'border-slate-200 text-slate-500 hover:border-slate-300'
        "
        @click="setRichTextMode('visual')"
        :aria-pressed="richTextMode === 'visual'"
      >
        {{ t("editor.visual") }}
      </button>
      <button
        type="button"
        class="text-xs px-2.5 py-1 rounded-md border transition-colors"
        :class="
          richTextMode === 'raw'
            ? 'border-theme-400 bg-theme-50 text-theme-700 font-medium'
            : 'border-slate-200 text-slate-500 hover:border-slate-300'
        "
        @click="setRichTextMode('raw')"
        :aria-pressed="richTextMode === 'raw'"
      >
        {{ t("editor.raw") }}
      </button>
    </div>

    <textarea
      v-if="richTextMode === 'raw'"
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
      <RichTextToolbar ref="richTextToolbar" :editor="editor" class="border-b border-slate-200 p-1.5" />
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
    :options="selectInputOptions"
    :model-value="normalizeChoiceValues(modelValue)"
    :multiple="true"
    :placeholder="t('fieldValue.selectOptions')"
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
    <option v-for="opt in selectInputOptions" :key="opt.value" :value="opt.value">
      {{ opt.label }}
    </option>
  </select>

  <!-- relation -->
  <div v-else-if="config.type === 'relation'">
    <SearchableSelect
      :options="relationOptions"
      :model-value="relationValue"
      :multiple="!!config.multiple"
      :placeholder="
        config.target ? t('fieldInput.selectEntry') : t('fieldInput.noRelationTarget')
      "
      :disabled="!config.target"
      :loading="relationLoading"
      @update:model-value="$emit('update:modelValue', $event)"
      @search="onRelationSearch"
    />
  </div>

  <!-- media -->
  <div v-else-if="config.type === 'media'" class="space-y-2">
    <div class="flex items-start" :class="compact ? 'gap-2' : 'gap-3'">
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
        <button
          v-for="file in mediaPreviewTiles"
          :key="file"
          type="button"
          class="overflow-hidden rounded-md bg-slate-200 flex items-center justify-center"
          :title="`Preview ${file}`"
          @click.stop="openMediaPreview(file)"
        >
          <img
            v-if="isImage(file)"
            :src="mediaThumbUrl(file)"
            class="h-full w-full object-cover"
            :alt="file"
          />
          <Icon v-else v-bind="getFileIcon(file)" class="w-5 h-5" />
        </button>
        <div
          v-if="mediaPreviewOverflow > 0"
          class="rounded-md bg-ink-900/75 text-white text-xs font-semibold flex items-center justify-center"
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
        class="rounded-lg border border-slate-200 bg-slate-100 overflow-hidden flex items-center justify-center shrink-0 transition-colors"
        :class="[
          compact ? 'h-12 w-12' : 'h-24 w-24',
          isMediaDropActive
            ? 'border-theme-400 bg-theme-50 ring-2 ring-theme-200'
            : '',
        ]"
        @dragenter.prevent="onMediaDragEnter"
        @dragover.prevent="onMediaDragOver"
        @dragleave.prevent="onMediaDragLeave"
        @drop.prevent="onMediaDrop"
      >
        <button
          v-if="mediaValues.length > 0"
          type="button"
          class="h-full w-full"
          :title="`Preview ${mediaValues[0]}`"
          @click.stop="openMediaPreview(mediaValues[0])"
        >
          <img
            v-if="isImage(mediaValues[0])"
            :src="mediaThumbUrl(mediaValues[0])"
            class="w-full h-full object-cover"
            :alt="String(mediaValues[0])"
          />
          <span
            v-else
            class="flex h-full w-full items-center justify-center"
          >
            <Icon v-bind="getFileIcon(mediaValues[0])" :class="compact ? 'h-5 w-5' : 'h-8 w-8'" />
          </span>
        </button>
        <Icon v-else icon="mdi:image-outline" class="text-slate-400" :class="compact ? 'h-5 w-5' : 'h-8 w-8'" />
      </div>

      <div class="min-w-0 flex-1" :class="compact ? 'space-y-1' : 'space-y-2'">
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
          class="text-slate-700 truncate"
          :class="compact ? 'text-xs' : 'text-sm'"
          :title="String(modelValue ?? '')"
        >
          {{ mediaLabel }}
        </p>
        <div class="flex flex-wrap" :class="compact ? 'gap-1' : 'gap-2'">
          <button
            type="button"
            class="btn-secondary"
            :class="[
              { 'btn-sm': compact },
              isMediaDropActive ? 'ring-2 ring-theme-300 ring-offset-1' : '',
            ]"
            :disabled="mediaUploading"
            @click="pickerOpen = true"
            @dragenter.prevent="onMediaDragEnter"
            @dragover.prevent="onMediaDragOver"
            @dragleave.prevent="onMediaDragLeave"
            @drop.prevent="onMediaDrop"
          >
            {{ config.multiple ? t("fieldInput.chooseMediaFiles") : t("fieldInput.chooseMedia") }}
          </button>
          <button
            v-if="mediaValues.length > 0"
            type="button"
            class="btn-secondary"
            :class="{ 'btn-sm': compact }"
            @click="$emit('update:modelValue', [])"
          >
            {{ t("fieldInput.clear") }}
          </button>
        </div>
        <p v-if="mediaUploading" class="text-xs text-theme-700">
          {{ t("fieldInput.uploadingDropped") }}
        </p>
        <p v-else-if="isMediaDropActive" class="text-xs text-theme-700">
          {{ t("fieldInput.dropToSelect") }}
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
        <button
          type="button"
          class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded bg-slate-100"
          :title="`Preview ${file}`"
          @click.stop="openMediaPreview(file)"
        >
          <img
            v-if="isImage(file)"
            :src="mediaThumbUrl(file)"
            :alt="file"
            class="h-full w-full object-cover"
          />
          <Icon v-else v-bind="getFileIcon(file)" class="h-4 w-4" />
        </button>
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
            :title="t('fieldInput.moveUp')"
            :aria-label="t('fieldInput.moveUp')"
            @click="moveRepeaterRow(rowIndex, -1)"
          >
            <Icon icon="mdi:chevron-up" class="w-4 h-4" />
          </button>
          <button
            type="button"
            :disabled="rowIndex === repeaterRows.length - 1"
            class="p-1 rounded text-slate-400 hover:text-slate-700 disabled:opacity-30 transition-colors"
            :title="t('fieldInput.moveDown')"
            :aria-label="t('fieldInput.moveDown')"
            @click="moveRepeaterRow(rowIndex, 1)"
          >
            <Icon icon="mdi:chevron-down" class="w-4 h-4" />
          </button>
          <button
            type="button"
            class="p-1 rounded text-slate-400 hover:text-red-500 transition-colors"
            :title="t('fieldInput.removeRow')"
            :aria-label="t('fieldInput.removeRow')"
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
      + {{ t("fieldInput.addRow") }}
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

  <Teleport to="body">
  <div
    v-if="mediaPreviewFile"
    class="fixed inset-0 z-[310] flex items-center justify-center bg-ink-950/70 px-4 py-6"
    @click.self="closeMediaPreview"
  >
    <div
      class="flex max-h-full w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl"
    >
      <div class="flex items-center gap-3 border-b border-slate-200 px-4 py-3">
        <h2 class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-800">
          {{ mediaPreviewFile }}
        </h2>
        <a
          :href="mediaPreviewUrl"
          target="_blank"
          rel="noreferrer"
          class="btn-secondary inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs"
        >
          <Icon icon="mdi:open-in-new" class="h-4 w-4" />
          {{ t("media.open") }}
        </a>
        <button
          type="button"
          class="text-slate-400 transition-colors hover:text-slate-700"
          :title="t('common.close')"
          :aria-label="t('common.close')"
          @click="closeMediaPreview"
        >
          <Icon icon="mdi:close" class="h-5 w-5" />
        </button>
      </div>
      <div class="flex min-h-0 flex-1 items-center justify-center bg-ink-950 p-4">
        <img
          v-if="mediaPreviewIsImage"
          :src="mediaPreviewUrl"
          :alt="mediaPreviewFile"
          class="max-h-[76vh] max-w-full object-contain"
        />
        <div
          v-else
          class="flex min-h-64 w-full max-w-lg flex-col items-center justify-center gap-4 rounded-lg bg-white p-8 text-center"
        >
          <Icon v-bind="getFileIcon(mediaPreviewFile)" class="h-16 w-16" />
          <p class="max-w-full truncate text-sm font-medium text-slate-700">
            {{ mediaPreviewFile }}
          </p>
        </div>
      </div>
    </div>
  </div>
  </Teleport>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import MediaPickerModal from "./MediaPickerModal.vue";
import SearchableSelect from "./SearchableSelect.vue";
import ToggleSwitch from "./ToggleSwitch.vue";
import FieldInput from "./FieldInput.vue";
import { api, getActiveWorkspace } from "../api/index.js";
import { useEditor, EditorContent } from "@tiptap/vue-3";
import RichTextToolbar from "./RichTextToolbar.vue";
import { richTextExtensions } from "../composables/richTextEditor.js";
import { Icon } from "@iconify/vue";
import { fieldDefaultValue } from "../composables/fieldDefaults.js";
import { markdownToHtml, sanitizeHtml, turndown } from "../composables/richText.js";
import {
  getFileIcon,
  isImageFile,
  mediaUrl as buildMediaUrl,
  mediaThumbUrl as buildMediaThumbUrl,
  normalizeChoiceValue,
  normalizeChoiceValues,
  normalizeMediaModel as normalizeMediaModelValue,
  uploadedMediaFilename,
} from "../composables/mediaUtils.js";
import { selectOptions } from "../composables/fieldBuilderUtils.js";
import { resolveLocalizedTree } from "../composables/localizedValues.js";
import { useLanguages } from "../composables/useLanguages.js";
import { useI18n } from "../i18n/index.js";

const { t } = useI18n();

const props = defineProps({
  name: { type: String, required: true },
  config: { type: Object, required: true },
  locale: { type: String, default: "" },
  modelValue: { default: null },
  readonly: { type: Boolean, default: false },
  /** Smaller media thumbnail and buttons, for narrow sidebars. */
  compact: { type: Boolean, default: false },
});

const languages = useLanguages();
const config = computed(() => resolveLocalizedTree(props.config, props.locale || languages.defaultLocale.value, languages.defaultLocale.value));

const emit = defineEmits(["update:modelValue"]);
const pickerOpen = ref(false);
const mediaPreviewFile = ref("");
const mediaUploadError = ref("");
const mediaUploading = ref(false);
const mediaDragDepth = ref(0);
const isMediaDropActive = computed(() => mediaDragDepth.value > 0);

// ---- Rich text visual editor ----
const richTextMode = ref("visual");
const richTextToolbar = ref(null);

const isRichTextField = computed(() =>
  ["markdown", "html"].includes(config.value.type),
);

function richTextToEditorHtml(value) {
  if (config.value.type === "html") {
    return sanitizeHtml(value);
  }

  return sanitizeHtml(markdownToHtml(value));
}

function editorStoredValue() {
  const html = sanitizeHtml(editor.value?.getHTML() ?? "");

  if (config.value.type === "html") {
    return html;
  }

  return turndown.turndown(html);
}

const editor = useEditor({
  extensions: richTextExtensions(),
  editorProps: {
    handleKeyDown(_view, event) {
      if (event.key.toLowerCase() !== "k" || !(event.metaKey || event.ctrlKey)) return false;
      event.preventDefault();
      richTextToolbar.value?.openLink();
      return true;
    },
  },
  content: "",
  onCreate({ editor }) {
    if (!isRichTextField.value) return;
    const html = richTextToEditorHtml(props.modelValue);
    editor.commands.setContent(html, { emitUpdate: false });
  },
  onUpdate({ editor }) {
    if (!isRichTextField.value) return;
    if (richTextMode.value === "visual") {
      const value =
        config.value.type === "html"
          ? sanitizeHtml(editor.getHTML())
          : turndown.turndown(sanitizeHtml(editor.getHTML()));
      if (value !== String(props.modelValue ?? "")) {
        emit("update:modelValue", value);
      }
    }
  },
});

function setRichTextMode(mode) {
  if (!isRichTextField.value) return;
  if (mode === richTextMode.value) return;
  if (mode === "visual") {
    const html = richTextToEditorHtml(props.modelValue);
    editor.value?.commands.setContent(html, { emitUpdate: false });
  }
  richTextMode.value = mode;
}

watch(
  () => props.modelValue,
  (val) => {
    if (!isRichTextField.value) return;
    if (richTextMode.value !== "visual") return;
    const value = String(val ?? "");
    if (editorStoredValue() !== value) {
      const html = richTextToEditorHtml(value);
      editor.value?.commands.setContent(html, { emitUpdate: false });
    }
  },
);

watch(
  () => props.modelValue,
  (val) => {
    if (config.value.type !== "media") return;

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
    if (!config.value.multiple) return;
    if (config.value.type !== "relation" && config.value.type !== "select")
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
  sanitizeHtml(markdownToHtml(props.modelValue)),
);

const readonlyHtml = computed(() => sanitizeHtml(props.modelValue));

const rangeMin = computed(() => numberOr(config.value.min, 0));
const rangeMax = computed(() =>
  Math.max(numberOr(config.value.max, 100), rangeMin.value),
);
const rangeStep = computed(() => {
  const step = numberOr(config.value.step, 1);
  return step > 0 ? step : 1;
});
const rangeValue = computed(() => {
  const fallback =
    config.value.default === null ||
    config.value.default === undefined ||
    config.value.default === ""
      ? rangeMin.value
      : config.value.default;

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

  const decimals = config.value.display_decimals;
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
      mediaUploadError.value = t("fieldInput.uploadNoFiles");
      return;
    }

    emit(
      "update:modelValue",
      config.value.multiple ? uploadedNames : uploadedNames.slice(0, 1),
    );
  } catch (err) {
    mediaUploadError.value = err.message ?? t("fieldInput.uploadFailed");
  } finally {
    mediaUploading.value = false;
  }
}

const mediaValues = computed(() => normalizeMediaValues(props.modelValue));
const mediaLabel = computed(() => {
  if (mediaValues.value.length === 0) return t("fieldInput.noMedia");
  if (config.value.multiple)
    return t(mediaValues.value.length === 1 ? "fieldInput.mediaSelectedOne" : "fieldInput.mediaSelectedMany", { count: mediaValues.value.length });
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
const mediaPreviewUrl = computed(() => mediaUrl(mediaPreviewFile.value));
const mediaPreviewIsImage = computed(() => isImage(mediaPreviewFile.value));
const selectInputOptions = computed(() => selectOptions(config.value.options));
const relationValue = computed(() => {
  if (config.value.multiple) {
    return normalizeChoiceValues(props.modelValue);
  }

  return normalizeChoiceValue(props.modelValue) ?? "";
});

function isImage(value) {
  return isImageFile(value);
}

function normalizeMediaValues(value) {
  return normalizeMediaModel(value);
}

function normalizeMediaModel(value) {
  return normalizeMediaModelValue(value, config.value.multiple);
}

function mediaUrl(value) {
  return buildMediaUrl(getActiveWorkspace(), value);
}

/** Previews load the small size; other files are served as they are. */
function mediaThumbUrl(value) {
  return buildMediaThumbUrl(getActiveWorkspace(), value);
}

function openMediaPreview(file) {
  mediaPreviewFile.value = String(file ?? "").trim();
}

function closeMediaPreview() {
  mediaPreviewFile.value = "";
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
    value: entry.uid || entry.id,
    label: entry.title || entry.id,
  })),
);

async function loadRelationEntries() {
  if (config.value.type !== "relation" || !config.value.target) {
    relationEntries.value = [];
    return;
  }

  relationLoading.value = true;
  try {
    const params = { limit: 100, sort: "title", order: "asc" };
    if (relationSearch.value.trim()) {
      params.q = relationSearch.value.trim();
    }

    const res = await api.content.list(config.value.target, params);
    relationEntries.value = res.data;
  } finally {
    relationLoading.value = false;
  }
}

onMounted(loadRelationEntries);

watch(
  () => config.value.target,
  () => {
    emit("update:modelValue", config.value.multiple ? [] : null);
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
    if (config.value.type !== "repeater") return;
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
  for (const sub of config.value.subfields ?? []) {
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
  for (const sub of config.value.subfields ?? []) {
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
  const count = (config.value.subfields ?? []).length;
  if (count >= 3) return "grid-cols-1 sm:grid-cols-2 lg:grid-cols-3";
  if (count === 2) return "grid-cols-1 sm:grid-cols-2";
  return "grid-cols-1";
});
</script>

<style scoped>
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
  @apply bg-ink-900 text-ink-100 rounded-lg p-3 my-2 font-mono text-xs overflow-x-auto;
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

.tiptap-content :deep(.ProseMirror u) {
  @apply underline;
}

.tiptap-content :deep(.ProseMirror a) {
  @apply text-theme-600 underline underline-offset-2;
}

.tiptap-content :deep(.ProseMirror mark) {
  background: rgb(253 224 71 / 0.6);
  color: inherit;
  border-radius: 0.15rem;
  padding: 0 0.1rem;
}

:root[data-mode="dark"] .tiptap-content :deep(.ProseMirror mark) {
  background: rgb(250 204 21 / 0.3);
}
</style>
