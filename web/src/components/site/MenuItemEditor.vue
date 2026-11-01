<template>
  <div class="space-y-5">
    <LocalizedInput :id="`menu-item-${item.id}`" ref="labelInput" v-model="item.label" :label="t('site.menus.displayText')" maxlength="120" />
    <div>
      <label :for="`menu-item-icon-${item.id}`" class="form-label">{{ t('site.menus.icon') }}</label>
      <div class="flex items-center gap-1.5">
        <IconPickerGrid :id="`menu-item-icon-${item.id}`" class="min-w-0 flex-1" :model-value="item.icon || ''" :icons="SITE_ICONS"
          :allow-custom="false" :empty-label="t('site.option.none')" @update:model-value="item.icon = $event" />
        <button v-if="item.icon" type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-900"
          :title="t('site.builder.removeIcon')" :aria-label="t('site.builder.removeIcon')" @click="item.icon = ''">
          <Icon icon="mdi:close" class="h-4 w-4" />
        </button>
      </div>
      <p class="mt-1 text-xs text-slate-500">{{ t('site.menus.iconHelp') }}</p>
    </div>
    <div>
      <h3 class="form-label">{{ t('site.menus.destination') }}</h3>
      <LocalizedInput v-model="item.link">
        <template #default="{ value, update }">
          <div class="space-y-3 rounded-lg border border-slate-200 bg-white p-3">
            <MenuLinkSummary explain-unavailable :link="value" :types="types" :settings="settings" :context="item.id" verbose />
            <p v-if="linkIssue(value, item.label, settings.default_locale)" class="text-xs text-amber-700">{{ t(`site.link.${linkIssue(value, item.label, settings.default_locale)}`) }}</p>
            <button type="button" class="text-sm font-medium text-theme-700 underline-offset-2 hover:underline" @click="changeDestination(value, update)">{{ t('site.menus.changeDestination') }}</button>
          </div>
          <LinkOptions :link="value ?? {}" task-focused @update="update({ ...value, ...$event })" />
        </template>
      </LocalizedInput>
    </div>
    <MenuDestinationDialog v-model="destinationOpen" :link="destination" :types="types" :settings="settings" @confirm="applyDestination" />
  </div>
</template>
<script setup>
import { ref } from 'vue'
import { Icon } from '@iconify/vue'
import IconPickerGrid from '../IconPickerGrid.vue'
import LocalizedInput from '../LocalizedInput.vue'
import MenuLinkSummary from './MenuLinkSummary.vue'
import LinkOptions from './LinkOptions.vue'
import MenuDestinationDialog from './MenuDestinationDialog.vue'
import { SITE_ICONS } from '../../composables/siteIcons.js'
import { linkIssue } from '../../composables/siteLinks.js'
import { useI18n } from '../../i18n/index.js'
defineProps({ item: { type: Object, required: true }, types: { type: Array, default: () => [] }, settings: { type: Object, required: true } })
const { t } = useI18n()
const labelInput = ref(null)
const destinationOpen = ref(false)
const destination = ref({})
let updateDestination = null
function changeDestination(value, update) { destination.value = value ?? {}; updateDestination = update; destinationOpen.value = true }
function applyDestination({ link }) { updateDestination?.(link) }
defineExpose({ focusLabel: () => labelInput.value?.focus() })
</script>
