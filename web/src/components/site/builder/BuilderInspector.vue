<template>
  <div v-if="templatePart" class="flex h-full flex-col overflow-y-auto">
    <div class="flex items-center gap-3 border-b border-slate-200 p-4">
      <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[#f8eaf3] text-[#8c3b73] ring-1 ring-inset ring-[#ac477e]/50">
        <Icon :icon="templatePart === 'header' ? 'mdi:page-layout-header' : 'mdi:page-layout-footer'" class="h-5 w-5" aria-hidden="true" />
      </span>
      <div class="min-w-0">
        <p class="text-xs text-slate-500">{{ t("site.builder.templatePart") }}</p>
        <h2 class="text-sm font-semibold text-slate-900">{{ t(`site.region.${templatePart}`) }}</h2>
      </div>
    </div>
    <div class="space-y-4 p-4">
      <div v-if="frame" class="space-y-1.5">
        <label for="builder-frame-assign" class="block text-xs font-semibold text-slate-700">{{ t("site.frame.assign") }}</label>
        <select
          id="builder-frame-assign"
          class="form-select w-full rounded-lg border-slate-300 text-sm"
          :value="frame.value"
          aria-describedby="builder-frame-scope"
          @change="$emit('assign-template-part', templatePart, $event.target.value)"
        >
          <option :value="FRAME_DEFAULT">{{ t(`site.frame.default.${templatePart}`) }}</option>
          <option :value="FRAME_NONE">{{ t(`site.frame.none.${templatePart}`) }}</option>
          <optgroup v-if="frame.components.length" :label="t('site.component.group')">
            <option v-for="component in frame.components" :key="component.id" :value="component.id">{{ component.name }}</option>
          </optgroup>
          <option v-if="frameMissing" :value="frame.value" disabled>{{ t("site.frame.missing") }}</option>
        </select>
        <p id="builder-frame-scope" class="text-xs text-slate-500">{{ t("site.frame.scope", { scope: frame.scope }) }}</p>
        <p v-if="!frame.components.length" class="text-xs text-slate-500">{{ t("site.frame.noComponents") }}</p>
      </div>
      <p class="text-sm leading-relaxed text-slate-600">{{ templatePartHint }}</p>
      <button v-if="frame?.value !== FRAME_NONE" type="button" class="btn-primary w-full justify-between" @click="$emit('edit-template-part', templatePart)">
        <span>{{ editTemplatePartLabel }}</span>
        <Icon icon="mdi:arrow-right" class="h-4 w-4 shrink-0" aria-hidden="true" />
      </button>
    </div>
  </div>

  <div v-else-if="node" class="flex h-full flex-col">
    <nav :aria-label="t('site.builder.hierarchy')" class="shrink-0 border-b border-slate-100 px-2 py-1">
      <ol class="flex flex-wrap items-center gap-x-0.5 text-[11px]">
        <li v-for="(ancestor, index) in nodePath" :key="ancestor.id" class="flex min-w-0 max-w-full items-center gap-0.5">
          <Icon v-if="index" icon="mdi:chevron-right" class="h-3 w-3 shrink-0 text-slate-400" aria-hidden="true" />
          <span
            v-if="ancestor.id === node.id"
            aria-current="location"
            :title="builder.nodeLabel(ancestor)"
            class="max-w-[9rem] truncate rounded px-1.5 py-0.5 font-semibold text-theme-700"
          >{{ builder.nodeLabel(ancestor) }}</span>
          <button
            v-else
            type="button"
            :title="t('site.menu.selectParent', { name: builder.nodeLabel(ancestor) })"
            class="min-w-0 max-w-[9rem] truncate rounded px-1.5 py-0.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-theme-500"
            @click="builder.select(ancestor.id)"
          >{{ builder.nodeLabel(ancestor) }}</button>
        </li>
      </ol>
    </nav>
    <div class="relative z-10 flex shrink-0 items-center gap-2.5 px-3 pb-2 pt-2.5" :class="{ 'border-b border-slate-200': isInstance }">
      <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-theme-50 text-theme-600 ring-1 ring-inset ring-theme-100">
        <Icon :icon="nodeIcon(node, builder.isStacked(node), builder.componentOf(node))" class="h-[18px] w-[18px]" />
      </span>
      <div class="min-w-0 flex-1">
        <div class="flex min-w-0 items-center gap-1">
          <p class="truncate text-sm font-semibold text-slate-900" :title="builder.nodeLabel(node)">{{ builder.nodeLabel(node) }}</p>
          <BuilderHelp
            :key="node.id"
            :label="`${builder.nodeLabel(node)} — ${t('site.builder.elementHelp')}`"
            :hints="helpHints"
          />
        </div>
        <p class="min-h-[1rem] truncate text-xs text-slate-500">{{ builder.nodeSummary(node) }}</p>
      </div>
      <button type="button" class="inspector-action" :title="t('site.builder.duplicate')" :disabled="locked || builder.isCard(node) || node.kind === 'area_content'" @click="builder.duplicate(node.id)">
        <Icon icon="mdi:content-copy" class="h-4 w-4" />
      </button>
      <button type="button" class="inspector-action hover:!text-red-600" :title="t('common.delete')" :disabled="locked" @click="builder.remove(node.id)">
        <Icon icon="mdi:trash-can-outline" class="h-4 w-4" />
      </button>
    </div>

    <div v-if="!isInstance" class="mx-3 mb-1 flex shrink-0 gap-1 rounded-xl border border-slate-200 bg-slate-100 p-1" role="tablist">
      <button
        v-for="option in inspectorTabs"
        :key="option.value"
        type="button"
        role="tab"
        :aria-selected="tab === option.value"
        class="inspector-tab"
        :class="{ 'is-active': tab === option.value }"
        @click="tab = option.value"
      >
        <Icon :icon="option.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
        {{ option.label }}
      </button>
    </div>

    <div class="flex-1 space-y-4 overflow-y-auto overflow-x-hidden px-3 py-3 pb-32">
      <p v-if="!isInstance && builder.device.value !== 'desktop'" class="flex items-start gap-2 rounded-lg bg-theme-50 px-3 py-2 text-xs text-theme-800 ring-1 ring-theme-100">
        <Icon :icon="DEVICE_ICONS[builder.device.value]" class="mt-px h-4 w-4 shrink-0" aria-hidden="true" />
        <span>{{ t(`site.override.deviceHint.${builder.device.value}`) }}</span>
      </p>
      <template v-if="isInstance">
        <div class="rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-600 ring-1 ring-slate-200">
          <p v-if="!component" class="text-amber-800">{{ t("site.component.missingHint") }}</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button v-if="component" type="button" class="btn-primary btn-sm" @click="builder.editComponent(component.id)">
              <Icon icon="mdi:puzzle-edit-outline" class="mr-1 h-4 w-4" />{{ t("site.component.edit") }}
            </button>
            <button v-if="component" type="button" class="btn-secondary btn-sm" :title="t('site.component.detachHint')" @click="builder.detach(node.id)">
              <Icon icon="mdi:link-variant-off" class="mr-1 h-4 w-4" />{{ t("site.component.detach") }}
            </button>
          </div>
        </div>
        <BuilderControl
          v-for="key in visibleProps"
          :key="`${node.id}-p-${key}`"
          :node="node"
          :name="key"
          :def="propSpec[key]"
          :value="node.props[key]"
          @update="(value) => updateProp(key, value)"
        />
        <template v-if="component">
          <div v-for="item in instanceFields" :key="item.node.id" class="space-y-3">
            <h4 class="truncate border-t border-slate-100 pt-3 text-xs font-semibold text-slate-700" :title="builder.nodeLabel(item.node)">{{ builder.nodeLabel(item.node) }}</h4>
            <BuilderControl
              v-for="key in item.keys"
              :key="`${node.id}-${item.node.id}-${key}`"
              :node="item.node"
              :name="key"
              :def="builder.spec.value.kinds[item.node.kind].props[key]"
              :value="item.node.props[key]"
              :variants="false"
              :on-reset-translation="() => builder.resetInstanceValue(node.id, item.node.id, key, { translation: true })"
              @update="(value) => builder.setInstanceValue(node.id, item.node.id, key, value)"
            >
              <template v-if="builder.hasInstanceValue(node, item.node.id, key) && !builder.translating.value" #status>
                <button
                  type="button"
                  class="inline-flex rounded-sm text-slate-400 hover:text-theme-600"
                  :title="t('site.component.resetValue')"
                  :aria-label="t('site.component.resetValue')"
                  @click="builder.resetInstanceValue(node.id, item.node.id, key)"
                >
                  <Icon icon="mdi:restore" class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
              </template>
            </BuilderControl>
          </div>
          <p v-if="builder.componentTrees.value[component.id] && !instanceFields.length && !instanceAreas.length" class="text-xs text-slate-500">{{ t("site.component.noEditable") }}</p>
        </template>
        <div v-if="instanceAreas.length" class="space-y-2 border-t border-slate-100 pt-3">
          <h4 class="text-xs font-semibold text-slate-700">{{ t("site.area.title") }}</h4>
          <p class="text-[11px] text-slate-500">{{ t("site.area.instanceHint") }}</p>
          <ul class="space-y-1">
            <li v-for="item in instanceAreas" :key="item.content?.id ?? item.area.id">
              <button
                type="button"
                class="flex w-full min-w-0 items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs ring-1 ring-inset transition-colors disabled:cursor-default"
                :class="item.removed ? 'bg-amber-50 text-amber-800 ring-amber-200 hover:bg-amber-100' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'"
                :disabled="!item.content"
                @click="builder.select(item.content.id)"
              >
                <Icon :icon="item.removed ? 'mdi:alert-outline' : 'mdi:tray-arrow-down'" class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate font-medium">{{ item.removed ? t("site.area.removedName") : item.area.name || t("site.kind.component_area") }}</span>
                <span class="shrink-0" :class="item.removed ? 'text-amber-700' : 'text-slate-400'">
                  {{ areaCount(item.content) }}
                </span>
              </button>
            </li>
          </ul>
        </div>
      </template>

      <template v-else-if="tab === 'content'">
        <ColumnLayoutPicker v-if="['row', 'column'].includes(node.kind) && !builder.isCard(node)" :node="node" />
        <div v-if="fieldWarning" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200">
          {{ fieldWarning }}
        </div>
        <BuilderControl
          v-for="key in contentProps"
          :key="`${node.id}-p-${key}`"
          :node="node"
          :name="key"
          :def="propSpec[key]"
          :value="builder.viewValue(node, 'props', key)"
          @update="(value) => updateProp(key, value)"
        >
          <template v-if="canMarkEditable" #status>
            <button
              type="button"
              class="inline-flex rounded-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-theme-500"
              :class="builder.isEditable(node, key) ? 'text-theme-600 hover:text-theme-800' : 'text-slate-300 hover:text-slate-500'"
              :aria-pressed="builder.isEditable(node, key)"
              :title="t(builder.isEditable(node, key) ? 'site.component.editableOn' : 'site.component.editableOff')"
              :aria-label="t(builder.isEditable(node, key) ? 'site.component.editableOn' : 'site.component.editableOff')"
              :disabled="builder.translating.value"
              @click="builder.toggleEditable(node.id, key)"
            >
              <Icon :icon="builder.isEditable(node, key) ? 'mdi:pencil-circle' : 'mdi:pencil-circle-outline'" class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </template>
        </BuilderControl>
        <BuilderGroup
          v-if="scrollProps.length"
          v-model:open="scrollingOpen"
          heading-tag="h4"
          :title="t('site.builder.scrolling')"
          content-class="mt-3 space-y-3"
        >
          <BuilderControl
            v-for="key in scrollProps"
            :key="`${node.id}-p-${key}`"
            :node="node"
            :name="key"
            :def="propSpec[key]"
            :value="node.props[key]"
            @update="(value) => updateProp(key, value)"
          />
        </BuilderGroup>
        <BuilderTabs v-if="node.kind === 'tabs'" :group="node" />
        <p v-if="node.kind === 'tab'" class="text-xs text-slate-500">{{ t('site.tabs.contentHint') }}</p>
        <div v-if="node.kind === 'page_content' && areaChoices.length > 1">
          <label class="mb-1 block text-xs text-slate-500" :for="`builder-${node.id}-area`">{{ t("site.page.area") }}</label>
          <select
            :id="`builder-${node.id}-area`"
            class="form-select w-full rounded-lg border-slate-300 py-1.5 text-xs"
            :value="pageFieldLabel === null ? '' : node.props.field"
            :disabled="renamingArea || builder.translating?.value"
            @change="chooseArea($event.target.value)"
          >
            <option v-for="choice in areaChoices" :key="choice.key" :value="choice.key">{{ choice.label }}</option>
          </select>
          <p class="mt-1 text-[11px] text-slate-400">{{ t("site.page.areaHint") }}</p>
        </div>
        <BuilderAreaCleanup v-if="node.kind === 'page_content'" />
        <div v-if="node.kind === 'page_content' && pageFieldLabel !== null">
          <label class="mb-1 block text-xs text-slate-500" :for="`builder-${node.id}-area-name`">{{ t("site.page.areaName") }}</label>
          <input
            :id="`builder-${node.id}-area-name`"
            :key="`${node.props.field}-${builder.locale.value}`"
            type="text"
            class="form-input w-full rounded-lg border-slate-300 py-1.5 text-xs"
            :value="pageFieldLabel"
            :disabled="!builder.canAddPageField() || renamingArea"
            @keydown.enter="$event.target.blur()"
            @change="renameArea($event.target.value)"
          />
          <p class="mt-1 text-[11px] text-slate-400">{{ t("site.page.areaNameHint") }}</p>
        </div>
        <p v-if="node.kind === 'page_content' && node.props.field && pageFieldLabel === null" class="rounded-lg bg-amber-50 px-3 py-3 text-xs text-amber-800 ring-1 ring-amber-200">
          {{ t("site.page.missingArea") }}
        </p>
        <button
          v-if="node.kind === 'page_content' && pageFieldLabel === null && areaChoices.length <= 1 && builder.canAddPageField()"
          type="button"
          class="btn-primary btn-sm"
          :disabled="renamingArea"
          @click="setUpArea"
        >
          <Icon icon="mdi:file-document-edit-outline" class="mr-1 h-4 w-4" />{{ t("site.page.setUpArea") }}
        </button>
        <p v-if="node.kind === 'page_content'" class="rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-600 ring-1 ring-slate-200">
          {{ node.props.field || builder.canAddPageField() ? t("site.page.placeHint") : t("site.page.noFieldHint") }}
        </p>
        <p v-if="node.kind === 'site_content'" class="rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-600 ring-1 ring-slate-200">{{ t('site.modal.contentHint') }}</p>
        <p v-if="node.kind === 'component_area'" class="rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-600 ring-1 ring-slate-200">{{ t("site.area.componentHint") }}</p>
        <template v-if="node.kind === 'area_content'">
          <p v-if="builder.isRemovedArea(node)" class="rounded-lg bg-amber-50 px-3 py-3 text-xs text-amber-800 ring-1 ring-amber-200">{{ t("site.area.removedHint") }}</p>
          <p v-else class="rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-600 ring-1 ring-slate-200">
            {{ t("site.area.contentHint", { area: builder.nodeLabel(node), component: areaInstance ? builder.nodeLabel(areaInstance) : "" }) }}
          </p>
          <button v-if="areaInstance" type="button" class="btn-secondary btn-sm" @click="builder.select(areaInstance.id)">
            <Icon icon="mdi:puzzle-outline" class="mr-1 h-4 w-4" />{{ t("site.area.selectPlacement") }}
          </button>
        </template>
        <div v-if="node.kind === 'collection'" class="rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-600 ring-1 ring-slate-200">
          <p class="font-medium text-slate-800">{{ hasCard ? t("site.card.custom") : t("site.card.standard") }}</p>
          <p class="mt-1">{{ t(cardHint) }}</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button v-if="!hasCard" type="button" class="btn-primary btn-sm" :disabled="!canCustomizeCard || cardBusy" @click="customizeCard">
              <Icon icon="mdi:card-text-outline" class="mr-1 h-4 w-4" />{{ t("site.card.customize") }}
            </button>
            <template v-else>
              <button type="button" class="btn-primary btn-sm" @click="builder.select(node.children[0].id)">
                <Icon icon="mdi:pencil-outline" class="mr-1 h-4 w-4" />{{ t("site.card.edit") }}
              </button>
              <button type="button" class="btn-secondary btn-sm" @click="builder.resetCard(node.id)">
                <Icon icon="mdi:restore" class="mr-1 h-4 w-4" />{{ t("site.card.reset") }}
              </button>
            </template>
          </div>
        </div>
        <p v-if="visibleProps.length === 0 && !['site_content', 'component_area', 'area_content'].includes(node.kind)" class="text-xs text-slate-500">{{ t("site.builder.noOptions") }}</p>
      </template>

      <template v-else>
        <div v-if="hasStates" class="space-y-1.5">
          <SegmentedControl :model-value="builder.styleState.value" :options="stateOptions" @update:model-value="(value) => (builder.styleState.value = value)" />
          <p v-if="builder.styleState.value !== 'normal'" class="text-[11px] text-slate-500">{{ t(`site.override.stateHint.${builder.styleState.value}`) }}</p>
        </div>
        <BuilderGroup
          v-for="group in styleGroups"
          :key="group.key"
          v-model:open="expandedStyleGroups[group.key]"
          heading-tag="h4"
          :title="groupTitle(group)"
          content-class="mt-3 space-y-3"
        >
          <SpacingControl
            v-if="group.key === 'spacing'"
            :node="node"
            :spec="styleSpec"
            :keys="group.keys"
            :value-for="(key) => builder.viewValue(node, 'style', key)"
            :overridden="(key) => ownOverride('style', key)"
            @update="(values) => builder.editValues(node.id, 'style', values)"
          />
          <template v-else>
            <BuilderControl
              v-for="key in group.keys"
              :key="`${node.id}-${group.part}-${key}`"
              :node="node"
              :name="key"
              :group="group.part"
              :def="group.part === 'props' ? propSpec[key] : styleSpec[key]"
              :value="builder.viewValue(node, group.part, key) ?? (group.part === 'props' ? propSpec[key] : styleSpec[key]).default"
              @update="(value) => builder.editValue(node.id, group.part, key, value)"
            />
          </template>
        </BuilderGroup>
        <p v-if="!styleGroups.length" class="text-xs text-slate-500">{{ t("site.builder.noOptions") }}</p>
      </template>
    </div>
  </div>

  <div v-else class="h-full">
    <slot name="empty">
      <div class="flex h-full flex-col items-center justify-center px-6 text-center">
        <Icon icon="mdi:cursor-default-click-outline" class="h-10 w-10 text-slate-300" />
        <p class="mt-3 text-sm font-medium text-slate-700">{{ t("site.builder.nothingSelected") }}</p>
        <p class="mt-1 text-xs text-slate-500">{{ t("site.builder.nothingSelectedHint") }}</p>
      </div>
    </slot>
  </div>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import { Icon } from "@iconify/vue";
import BuilderControl from "./BuilderControl.vue";
import SpacingControl from "./SpacingControl.vue";
import BuilderHelp from "./BuilderHelp.vue";
import BuilderGroup from "./BuilderGroup.vue";
import BuilderTabs from "./BuilderTabs.vue";
import BuilderAreaCleanup from "./BuilderAreaCleanup.vue";
import ColumnLayoutPicker from "./ColumnLayoutPicker.vue";
import SegmentedControl from "../SegmentedControl.vue";
import { DEVICE_ICONS, STATE_ICONS } from "./controlOptions.js";
import { nodeIcon } from "./kindIcons.js";
import { COMPONENT, findNode, locate } from "../../../composables/layoutTree.js";
import { FRAME_DEFAULT, FRAME_NONE, frameLabel } from "../../../composables/templates.js";
import { useBuilderBreadcrumbs } from "../../../composables/useBuilderBreadcrumbs.js";
import { useSiteBuilder } from "../../../composables/useSiteBuilder.js";
import { useI18n } from "../../../i18n/index.js";

const props = defineProps({
  templatePart: { type: String, default: null },
  /** The layout's header or footer: `{ value, components, scope }`, or null where it can't be assigned. */
  frame: { type: Object, default: null },
  /** Whether the search results page is edited: only there do lists have search results to show. */
  searchPage: { type: Boolean, default: false },
});
defineEmits(["edit-template-part", "assign-template-part"]);

/** A button's own colors sit with its style, though they are props. */
const BUTTON_COLORS = ["button_color", "button_text_color", "button_border_color"];
const ICON_SHAPE = ["shape", "shape_color", "shape_padding"];
/** So do a section's shape dividers, one group per edge. */
const dividerKeys = (edge) => [`divider_${edge}`, ...["color", "height", "width", "flip", "invert"].map((option) => `divider_${edge}_${option}`)];

const STYLE_GROUPS = [
  { key: "button", part: "props", keys: BUTTON_COLORS },
  { key: "icon", part: "props", keys: ICON_SHAPE },
  { key: "spacing", keys: ["padding_top", "padding_right", "padding_bottom", "padding_left", "margin_top", "margin_right", "margin_bottom", "margin_left"] },
  { key: "background", keys: ["background", "background_gradient", "background_image", "background_size", "background_position", "background_repeat", "background_blur", "background_effect", "overlay"] },
  { key: "dividerTop", part: "props", keys: dividerKeys("top") },
  { key: "dividerBottom", part: "props", keys: dividerKeys("bottom") },
  { key: "text", keys: ["text_color", "link_color", "text_gradient", "align", "font_size", "font_weight", "font_family", "text_shadow"] },
  { key: "shape", keys: ["radius", "shadow", "opacity", "border", "border_width", "border_style", "border_color", "max_width"] },
  { key: "position", keys: ["position", "pin", "offset_x", "offset_y", "z_index"] },
  { key: "animation", keys: ["animation", "animation_trigger", "animation_duration", "animation_delay"] },
  { key: "conditions", keys: ["visible_if"] },
  { key: "advanced", keys: ["hide_on", "anchor", "class"] },
];

const builder = useSiteBuilder();
const { t } = useI18n();
const frameMissing = computed(() => !!props.frame && ![FRAME_DEFAULT, FRAME_NONE].includes(props.frame.value)
  && !props.frame.components.some((component) => component.id === props.frame.value));
const templatePartHint = computed(() => {
  const value = props.frame?.value ?? FRAME_DEFAULT;
  if (value === FRAME_DEFAULT) return t("site.builder.templatePartHint");
  if (value === FRAME_NONE) return t(`site.frame.noneHint.${props.templatePart}`);
  return t("site.frame.componentHint", { name: frameLabel(t, value, props.templatePart, props.frame.components) });
});
const editTemplatePartLabel = computed(() => {
  const value = props.frame?.value ?? FRAME_DEFAULT;
  return value === FRAME_DEFAULT
    ? t("site.builder.editTemplatePart", { name: t(`site.region.${props.templatePart}`) })
    : t("site.frame.editComponent", { name: frameLabel(t, value, props.templatePart, props.frame.components) });
});
const tab = ref("content");
const inspectorTabs = computed(() => [
  { value: "content", label: t("site.builder.content"), icon: "mdi:tune-variant" },
  { value: "style", label: t("site.builder.style"), icon: "mdi:palette-swatch-outline" },
]);
/** Only the most used groups start open. */
const OPEN_STYLE_GROUPS = ["button", "icon", "spacing", "text"];
const expandedStyleGroups = ref(Object.fromEntries(STYLE_GROUPS.map(({ key }) => [key, OPEN_STYLE_GROUPS.includes(key)])));

const node = computed(() => builder.selected.value);
/** A closed Conditional group still tells that the element has conditions. */
function groupTitle(group) {
  const count = group.key === "conditions" ? node.value?.style?.visible_if?.conditions?.length ?? 0 : 0;
  return count ? `${t(`site.styleGroup.${group.key}`)} (${count})` : t(`site.styleGroup.${group.key}`);
}
const kindSpec = computed(() => builder.spec.value?.kinds?.[node.value?.kind] ?? {});
/** Hover and focus edit only their own values, so each element starts in its normal state. */
const hasStates = computed(() => !isInstance.value && ["props", "style"].some((part) => (kindSpec.value.states?.[part]?.length || kindSpec.value.states?.variants?.hover?.[part]?.length)));
watch(() => node.value?.id, () => { builder.styleState.value = "normal"; });
const stateOptions = computed(() => ["normal", "hover", "focus"].map((value) => ({
  value,
  label: t(`site.state.${value}`),
  icon: STATE_ICONS[value],
  showLabel: true,
})));

/** Whether the key has its own value on the previewed device or in the edited state. */
function ownOverride(part, key) {
  const target = builder.overrideTarget(node.value, part, key);
  if (!target) return false;
  return builder.overridesOf(node.value, part, key)[target.group === "states" ? "states" : "devices"].includes(target.variant);
}
/** One sentence on what the selected kind of element is for. */
const kindHint = computed(() => {
  if (!node.value) return "";
  const kind = builder.isCard(node.value) ? "card" : node.value.kind;
  const key = `site.kindHint.${kind}`;
  const text = t(key);
  return text === key ? "" : text;
});
const helpHints = computed(() => {
  const current = node.value;
  if (!current) return [];
  const hints = [current.kind === COMPONENT && builder.componentOf(current) ? t("site.component.instanceHint") : kindHint.value];
  if (current.kind === "field" && builder.isTemplateField(current)) hints.push(t("site.builder.templateHint"));
  if (["heading", "text", "button"].includes(current.kind)) hints.push(t("site.builder.placeholderHint"));
  return hints.filter(Boolean);
});
const propSpec = computed(() => {
  const spec = builder.spec.value?.kinds?.[node.value?.kind]?.props ?? {};
  // Search results exist only on the search results page; a search list copied elsewhere keeps the choice so it can be switched back.
  if (node.value?.kind !== "collection" || props.searchPage || node.value.props?.list_source === "search") return spec;
  return { ...spec, list_source: { ...spec.list_source, options: spec.list_source.options.filter((option) => option !== "search") } };
});
const styleSpec = computed(() => builder.spec.value?.style ?? {});
const isInstance = computed(() => node.value?.kind === COMPONENT);
const component = computed(() => builder.componentOf(node.value));
watch(() => component.value?.id, (id) => builder.loadComponentTree(id), { immediate: true });
/** What the component lets this placement set. */
const instanceFields = computed(() => (isInstance.value ? builder.instanceFields(node.value) : []));
/** What this placement shows in each content area of its component. */
const instanceAreas = computed(() => (isInstance.value ? builder.instanceAreas(node.value) : []));
function areaCount(content) {
  const count = content?.children?.length ?? 0;
  return count === 0 ? t("site.area.empty") : count === 1 ? t("site.area.elementCountOne") : t("site.area.elementCount", { count });
}
/** The placement whose content for one area is selected. */
const areaInstance = computed(() => (node.value?.kind === "area_content" ? locate(builder.nodes.value, node.value.id)?.parent ?? null : null));
/** In a component, each content prop can be left for every placement to set. */
const canMarkEditable = computed(() => !!builder.componentId.value && !!node.value && ![COMPONENT, "page_content", "component_area", "area_content"].includes(node.value.kind));
const locked = computed(() => builder.translating?.value || (!!node.value && builder.isLockedRoot(node.value.id)));
const nodePath = useBuilderBreadcrumbs(builder);
const fieldConfig = computed(() => (node.value?.kind === "field" ? builder.fieldConfig(node.value) : null));
/** The name entries show for the selected page content area: its field's label. */
const pageFieldLabel = computed(() => {
  if (node.value?.kind !== "page_content" || !node.value.props?.field) return null;
  return builder.pageFields().find((field) => field.key === node.value.props.field)?.label ?? null;
});
const renamingArea = ref(false);
/**
 * What the selected area element can show: its area, the type's areas no
 * other element here shows (their entries' designs come back), and a new one.
 */
const areaChoices = computed(() => {
  if (node.value?.kind !== "page_content") return [];
  const current = pageFieldLabel.value === null ? [] : [{ key: node.value.props.field, label: pageFieldLabel.value }];
  const free = builder.freePageFields(node.value.id).filter((field) => field.key !== node.value.props.field);
  const add = builder.canAddPageField() ? [{ key: "", label: t("site.page.newArea") }] : [];
  return [...current, ...free, ...add];
});

async function chooseArea(key) {
  renamingArea.value = true;
  try {
    await builder.choosePageArea(node.value.id, key);
  } catch (error) {
    builder.pageFieldError.value = error.message;
  } finally {
    renamingArea.value = false;
  }
}

async function setUpArea() {
  renamingArea.value = true;
  try {
    await builder.setUpPageArea(node.value.id);
  } catch (error) {
    builder.pageFieldError.value = error.message;
  } finally {
    renamingArea.value = false;
  }
}

async function renameArea(value) {
  if (!value.trim() || value.trim() === pageFieldLabel.value) return;
  renamingArea.value = true;
  try {
    await builder.renamePageField(node.value.props.field, value);
  } catch (error) {
    builder.pageFieldError.value = error.message;
  } finally {
    renamingArea.value = false;
  }
}

const hasCard = computed(() => node.value?.kind === "collection" && (node.value.children?.length ?? 0) > 0);
const cardBusy = ref(false);
const listSource = computed(() => (node.value?.kind === "collection" && node.value.props?.list_source) || "type");
const cardHint = computed(() => {
  const prefix = { search: "site.card.search", types: "site.card.mixed" }[listSource.value];
  const suffix = hasCard.value ? "CustomHint" : "StandardHint";
  return prefix ? prefix + suffix : `site.card.${hasCard.value ? "customHint" : "standardHint"}`;
});
// A search list without chosen types searches all of them; the other lists need their types first.
const canCustomizeCard = computed(() => ({ search: true, types: (node.value?.props?.collections?.length ?? 0) > 0 })[listSource.value] ?? !!node.value?.props?.type);

async function customizeCard() {
  cardBusy.value = true;
  try {
    await builder.customizeCard(node.value.id);
  } finally {
    cardBusy.value = false;
  }
}

const fieldWarning = computed(() => {
  if (node.value?.kind !== "field") return "";
  if (!node.value.props.source) return t("site.builder.chooseFieldHint");
  return fieldConfig.value ? "" : t("site.builder.missingFieldHint", { field: builder.nodeSummary(node.value) });
});

/** Image options only apply once there is an image; covering images never repeat. */
const BACKGROUND_IMAGE_OPTIONS = ["background_size", "background_position", "background_repeat", "background_blur", "background_effect", "overlay"];

/** How a border looks only matters once a side is picked; timing only once there is an animation. */
const BORDER_OPTIONS = ["border_width", "border_style", "border_color"];
const ANIMATION_OPTIONS = ["animation_trigger", "animation_duration", "animation_delay"];
/** The pin point and offsets only apply to an element taken out of the flow. */
const POSITION_OPTIONS = ["pin", "offset_x", "offset_y"];

function styleVisible(key) {
  const style = node.value?.style ?? {};
  if (style.background_gradient?.type && ["background_image", ...BACKGROUND_IMAGE_OPTIONS].includes(key)) return false;
  if (BACKGROUND_IMAGE_OPTIONS.includes(key) && !style.background_image) return false;
  if (BORDER_OPTIONS.includes(key) && !style.border?.length) return false;
  if (key === "animation_trigger" && builder.styleState.value !== "normal") return false;
  if (ANIMATION_OPTIONS.includes(key) && (builder.viewValue(node.value, "style", "animation") || "none") === "none") return false;
  if (POSITION_OPTIONS.includes(key) && !["absolute", "fixed"].includes(builder.viewValue(node.value, "style", "position"))) return false;
  return key !== "background_repeat" || (style.background_size ?? styleSpec.value.background_size?.default) !== "cover";
}

/** A divider's color, height and direction only apply once it has a shape. */
function propVisible(key) {
  if (node.value?.kind === "icon" && ["shape_color", "shape_padding"].includes(key)) return (node.value.props.shape ?? "none") !== "none";
  const edge = key.match(/^divider_(top|bottom)_/)?.[1];
  return !edge || (node.value?.props?.[`divider_${edge}`] ?? "none") !== "none";
}

const styleGroups = computed(() => {
  const allowed = { props: Object.keys(propSpec.value), style: kindSpec.value.style ?? [] };
  return STYLE_GROUPS
    .map((group) => {
      const part = group.part ?? "style";
      const keys = group.keys.filter((key) => allowed[part].includes(key) && (part === "props" ? propVisible(key) : styleVisible(key)) && builder.editableInState(node.value, part, key));
      return { ...group, part, keys };
    })
    .filter((group) => group.keys.length > 0);
});

/** Which props make sense for the node in its current configuration. */
const visibleProps = computed(() => {
  const current = node.value;
  if (!current) return [];
  const keys = Object.keys(propSpec.value);
  const props = current.props ?? {};

  if (current.kind === "field") {
    const type = fieldConfig.value?.type;
    const displays = type === 'media' && fieldConfig.value?.multiple ? ['gallery', 'files'] : builder.spec.value?.field_displays?.[type] ?? [];
    const display = props.display && displays.includes(props.display) ? props.display : displays[0];
    const rules = {
      source: true,
      display: displays.length > 1,
      label: !!type,
      tag: ["text", "slug", "select", "number", "range", "textarea"].includes(type) && display !== "excerpt",
      link: ["text", "slug"].includes(type) || (type === "media" && display === "image") || (type === "textarea" && display === "excerpt"),
      truncate: ["text", "textarea"].includes(type) || (["markdown", "html"].includes(type) && display === "excerpt"),
      date_format: ["date", "datetime"].includes(type),
      ratio: type === "media" && display !== "files",
      position: type === "media" && display !== "files" && !!props.ratio,
      fit: type === "media" && display !== "files" && !!props.ratio,
      columns: (type === "media" && display === "gallery") || (type === "relation" && display === "cards") || type === "repeater",
      prefix: ["number", "range", "select"].includes(type),
      suffix: ["number", "range", "select"].includes(type),
      true_text: type === "boolean",
      false_text: type === "boolean",
    };
    return keys.filter((key) => rules[key]);
  }

  // Each page content area owns its field; the inspector names it instead. Area content belongs to its component's area.
  if (["page_content", "component_area", "area_content"].includes(current.kind)) return [];

  if (current.kind === "icon") return keys.filter((key) => !ICON_SHAPE.includes(key));

  // Only a column component's instances have something of their own: their width.
  if (current.kind === COMPONENT) {
    return props.root === "column" ? ["span"] : [];
  }

  if (current.kind === "collection") {
    // Search results come from the query, not from one type, its sorting or visitor filters.
    // Lists of several types sort by date on their own; visitors filter and sort by the fields of all of them.
    const source = props.list_source ?? "type";
    const hidden = {
      search: ["type", "sort_field", "sort_order", "filter_field", "filter_value", "exclude_current", "filters", "sort_choices"],
      types: ["type", "search_fields", "filter_field", "filter_value"],
    }[source] ?? ["collections", "search_fields"];
    // Visitor filters and sorting need the list's fields.
    const hasFields = source === "types" ? !!props.collections?.length : !!props.type;
    return keys.filter((key) => !hidden.includes(key) && (key !== "filter_value" || props.filter_field) && (!["filters", "sort_choices"].includes(key) || hasFields));
  }

  if (["image", "gallery"].includes(current.kind)) {
    return keys.filter((key) => {
      if (props.masonry && ["ratio", "fit", "position"].includes(key)) return false;
      if (["fit", "position"].includes(key) && !props.ratio) return false;
      if (key === "label") return props.media?.type === "reference";
      if (key === "lightbox_navigation") return !!props.lightbox;
      if (key === "link") return !props.lightbox;
      return true;
    });
  }

  if (current.kind === "column") {
    // A card fills its place in the collection's grid; it has no width of its own.
    return keys.filter((key) => (props.direction === "row" || !["content_justify", "content_align"].includes(key)) && (key !== "span" || !builder.isCard(current)));
  }

  if (current.kind === "language_switcher") {
    // Flags on their own leave no text to format.
    return keys.filter((key) => key !== "format" || props.flag !== "only");
  }

  if (current.kind === "button") {
    // Colors are set with the style; spacing and position only matter with an icon.
    return keys.filter((key) => !BUTTON_COLORS.includes(key) && (!["icon_position", "icon_gap"].includes(key) || !!props.icon));
  }

  if (current.kind === "search") {
    // Without an icon there is none to choose.
    return keys.filter((key) => key !== "icon" || props.icon_position !== "none");
  }

  if (current.kind === "section") {
    // Scroll effects show on a section that stays in view while scrolling; only a floating one can start transparent.
    const rules = {
      scroll_effect: (props.sticky ?? "none") !== "none" || !!props.overlap,
      overlap_background: !!props.overlap,
    };
    // Dividers are set with the style.
    return keys.filter((key) => rules[key] !== false && !key.startsWith("divider_"));
  }

  if (current.kind === "modal") {
    // Panels along the top or bottom span the screen's width; those at the side its height.
    const side = ["left", "right"].includes(props.placement);
    return keys.filter((key) => (key !== "size" || !["top", "bottom"].includes(props.placement)) && (key !== "height" || !side));
  }

  if (current.kind === "menu") {
    // The panel style only matters once there is a menu button; its colors also dress horizontal submenus.
    const hasButton = props.always_button || props.collapse_mobile;
    const hasPopover = hasButton || props.orientation !== "vertical";
    const rules = {
      panel: hasButton,
      panel_side: hasButton && props.panel === "drawer",
      panel_background: hasPopover,
      panel_text_color: hasPopover,
    };
    return keys.filter((key) => rules[key] !== false && (!props.always_button || !["orientation", "collapse_mobile"].includes(key)));
  }

  return keys;
});

/** How a section behaves while the page scrolls, set up in one group of its own. */
const SCROLL_PROPS = ["sticky", "overlap", "overlap_background", "scroll_effect"];
const scrollingOpen = ref(true);
const scrollProps = computed(() => (node.value?.kind === "section" ? visibleProps.value.filter((key) => SCROLL_PROPS.includes(key)) : []));
const contentProps = computed(() => visibleProps.value.filter((key) => !scrollProps.value.includes(key)));

function updateProp(key, value) {
  if (key !== "source") {
    builder.editValue(node.value.id, "props", key, value);
    return;
  }

  // A new field binding starts with that field's default display; one undo step.
  const id = node.value.id;
  builder.mutate((tree) => {
    const target = findNode(tree, id);
    if (!target) return;
    target.props = { ...target.props, source: value };
    const config = builder.fieldConfig(target);
    const displays = config?.type === 'media' && config?.multiple ? ['gallery', 'files'] : builder.spec.value?.field_displays?.[config?.type] ?? [];
    if (!displays.includes(target.props.display)) target.props.display = displays[0] ?? "";
  });
}
</script>

<style scoped>
.inspector-action {
  display: inline-flex;
  height: 1.75rem;
  width: 1.75rem;
  align-items: center;
  justify-content: center;
  border-radius: 0.375rem;
  color: rgb(var(--color-slate-500));
}
.inspector-action:hover:not(:disabled) {
  background: rgb(var(--color-slate-100));
  color: rgb(var(--color-slate-900));
}
.inspector-action:disabled {
  opacity: 0.35;
}
.inspector-tab {
  display: inline-flex;
  flex: 1;
  min-height: 2rem;
  align-items: center;
  justify-content: center;
  gap: 0.375rem;
  padding: 0.25rem 0.625rem;
  border: 1px solid transparent;
  border-radius: 0.5rem;
  font-size: 0.8125rem;
  font-weight: 600;
  color: rgb(var(--color-slate-600));
  transition: color 150ms, background-color 150ms, border-color 150ms, box-shadow 150ms;
}
.inspector-tab:hover {
  background: rgb(var(--color-slate-50));
  color: rgb(var(--color-slate-900));
}
.inspector-tab.is-active {
  background: rgb(var(--color-surface));
  border-color: rgb(var(--color-theme-200));
  color: rgb(var(--color-theme-700));
  box-shadow: 0 1px 3px rgb(0 0 0 / 0.08);
}
.inspector-tab:focus-visible {
  outline: 2px solid rgb(var(--color-theme-500));
  outline-offset: 1px;
}
.kbd {
  display: inline-block;
  min-width: 1.25rem;
  padding: 0 0.3rem;
  border: 1px solid rgb(var(--color-slate-300));
  border-bottom-width: 2px;
  border-radius: 0.25rem;
  font: 600 10px/1.4 ui-monospace, monospace;
  text-align: center;
  background: rgb(var(--color-surface));
}
</style>
