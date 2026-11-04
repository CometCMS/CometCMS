<template>
  <AppDialog
    :model-value="modelValue"
    :title="t('site.builder.settings.title')"
    icon="mdi:cog-outline"
    :busy="saving"
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <p class="mb-5 text-sm text-slate-500">{{ t('site.builder.settings.description') }}</p>
    <div class="flex items-start justify-between gap-4">
      <div>
        <p :id="`${id}-label`" class="text-sm font-medium text-slate-900">{{ t('site.builder.settings.parentOutlines') }}</p>
        <p :id="`${id}-hint`" class="mt-1 text-xs text-slate-500">{{ t('site.builder.settings.parentOutlinesHint') }}</p>
      </div>
      <ToggleSwitch
        :model-value="auth.user?.builder_preferences?.show_parent_outlines === true"
        :disabled="saving"
        :aria-labelledby="`${id}-label`"
        :aria-describedby="`${id}-hint`"
        @update:model-value="setParentOutlines"
      />
    </div>
    <p v-if="error" role="alert" class="mt-4 text-sm text-red-600">{{ error }}</p>
    <template #footer="{ close }">
      <span v-if="saving" role="status" class="mr-auto self-center text-xs text-slate-500">{{ t('common.saving') }}</span>
      <button type="button" class="btn-secondary" :disabled="saving" @click="close">{{ t('common.close') }}</button>
    </template>
  </AppDialog>
</template>

<script setup>
import { ref, useId } from 'vue'
import AppDialog from '../../AppDialog.vue'
import ToggleSwitch from '../../ToggleSwitch.vue'
import { api } from '../../../api/index.js'
import { useAuthStore } from '../../../stores/auth.js'
import { useI18n } from '../../../i18n/index.js'

defineProps({ modelValue: { type: Boolean, required: true } })
defineEmits(['update:modelValue'])
const auth = useAuthStore()
const { t } = useI18n()
const id = `builder-settings-${useId()}`
const saving = ref(false)
const error = ref('')

async function setParentOutlines(enabled) {
  if (saving.value || !auth.user) return
  const user = auth.user
  const previous = user.builder_preferences
  user.builder_preferences = { ...previous, show_parent_outlines: enabled }
  saving.value = true
  error.value = ''
  try {
    const response = await api.profile.update({ builder_preferences: { show_parent_outlines: enabled } })
    user.builder_preferences = response.data.builder_preferences
  } catch (err) {
    user.builder_preferences = previous
    error.value = err.message
  } finally {
    saving.value = false
  }
}
</script>
