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
        ref="firstSetting"
        :model-value="auth.user?.builder_preferences?.show_parent_outlines === true"
        :disabled="saving"
        :aria-labelledby="`${id}-label`"
        :aria-describedby="`${id}-hint`"
        @update:model-value="savePreferences({ show_parent_outlines: $event })"
      />
    </div>
    <p v-if="error" role="alert" class="mt-4 text-sm text-red-600">{{ error }}</p>
    <template #footer="{ close }">
      <div class="mr-auto flex items-center gap-3">
        <button v-if="hasOverrides || resetting" type="button" class="btn-ghost" :disabled="saving" @click="resetPreferences">{{ t('site.builder.settings.reset') }}</button>
        <span v-if="saving" role="status" class="text-xs text-slate-500">{{ t('common.saving') }}</span>
      </div>
      <button type="button" class="btn-secondary" :disabled="saving" @click="close">{{ t('common.close') }}</button>
    </template>
  </AppDialog>
</template>

<script setup>
import { computed, nextTick, ref, useId } from 'vue'
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
const resetting = ref(false)
const error = ref('')
const firstSetting = ref(null)
// The server owns the defaults; every declared preference participates in reset.
const defaults = computed(() => auth.user?.builder_preferences_defaults ?? {})
const hasOverrides = computed(() => Object.entries(defaults.value).some(([key, value]) =>
  JSON.stringify(auth.user?.builder_preferences?.[key] ?? value) !== JSON.stringify(value),
))

async function resetPreferences() {
  resetting.value = true
  await savePreferences(defaults.value)
  // Keep keyboard focus in the dialog when the reset button disappears.
  if (!hasOverrides.value) firstSetting.value?.$el.focus()
  resetting.value = false
}

async function savePreferences(changes) {
  if (saving.value || !auth.user) return
  const user = auth.user
  const previous = user.builder_preferences
  user.builder_preferences = { ...defaults.value, ...previous, ...changes }
  saving.value = true
  error.value = ''
  try {
    const response = await api.profile.update({ builder_preferences: changes })
    user.builder_preferences = response.data.builder_preferences
  } catch (err) {
    user.builder_preferences = previous
    error.value = err.message
  } finally {
    saving.value = false
    await nextTick()
  }
}
</script>
