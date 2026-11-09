<template>
  <section id="submissions" class="scroll-mt-24 space-y-6">
    <div class="card space-y-4 p-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="card-title">{{ t("site.forms.title") }}</h2>
        <router-link v-if="name" :to="`/content/${name}`" class="text-sm text-theme-600 hover:underline">{{ t("site.forms.viewSubmissions") }}</router-link>
      </div>
      <div>
        <label class="form-label" for="submission-status">{{ t("site.forms.status") }}</label>
        <select id="submission-status" :value="settings.status" class="form-select rounded-lg border-slate-300 text-sm" @change="update({ status: $event.target.value })">
          <option value="draft">{{ t("status.draft") }}</option>
          <option value="published">{{ t("status.published") }}</option>
        </select>
        <p class="mt-1 text-xs text-slate-500">{{ t("site.forms.statusHint") }}</p>
      </div>
      <p class="text-xs text-slate-500">{{ t("contentTypeEdit.submissionFieldsHint") }}</p>
      <p v-if="!fillable.length" class="text-xs text-amber-700">{{ t("site.forms.noFields") }}</p>
      <p v-if="unsupported.length" class="text-xs text-amber-700">{{ t("site.forms.unsupported", { fields: unsupported.join(", ") }) }}</p>
    </div>
    <!-- Texts -->
    <section class="card space-y-4 p-6">
      <h2 class="card-title">{{ t("site.forms.texts") }}</h2>
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="form-label" for="form-submit">{{ t("site.forms.submitLabel") }}</label>
          <LocalizedInput id="form-submit" :model-value="settings.submit_label" @update:model-value="(value) => update({ submit_label: value })" maxlength="80" class="form-input w-full rounded-lg border-slate-300 text-sm" :placeholder="t('site.forms.submitDefault')"  />
        </div>
        <div class="md:col-span-2">
          <label class="form-label" for="form-success">{{ t("site.forms.successMessage") }}</label>
          <LocalizedInput id="form-success" :model-value="settings.success_message" @update:model-value="(value) => update({ success_message: value })" multiline rows="2" maxlength="1000" class="form-textarea w-full rounded-lg border-slate-300 text-sm" :placeholder="t('site.forms.successDefault')" />
        </div>
        <div class="md:col-span-2">
          <label class="form-label" for="form-error">{{ t("site.forms.errorMessage") }}</label>
          <LocalizedInput id="form-error" :model-value="settings.error_message" @update:model-value="(value) => update({ error_message: value })" multiline rows="2" maxlength="1000" class="form-textarea w-full rounded-lg border-slate-300 text-sm" :placeholder="t('site.forms.errorDefault')" />
        </div>
      </div>
      <p class="text-xs text-slate-500">{{ t("site.forms.textsHint") }}</p>
    </section>

    <!-- Spam protection -->
    <section class="card space-y-4 p-6">
      <h2 class="card-title">{{ t("site.forms.spam") }}</h2>
      <ul class="space-y-2 text-sm text-slate-600">
        <li class="flex items-start gap-2">
          <Icon icon="mdi:check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-green-600" />
          <span>{{ t("site.forms.honeypot") }}</span>
        </li>
        <li class="flex items-start gap-2">
          <Icon icon="mdi:check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-green-600" />
          <span>{{ t("site.forms.rateLimit") }}</span>
        </li>
      </ul>
      <div class="max-w-sm">
        <label class="form-label" for="form-captcha">{{ t("site.forms.captcha") }}</label>
        <select id="form-captcha" :value="settings.captcha" @change="update({ captcha: $event.target.value })" class="form-select w-full rounded-lg border-slate-300 text-sm">
          <option value="none">{{ t("site.forms.captchaNone") }}</option>
          <option value="turnstile">Cloudflare Turnstile</option>
          <option value="recaptcha">Google reCAPTCHA v2</option>
          <option value="recaptcha_v3">Google reCAPTCHA v3</option>
        </select>
      </div>
      <p v-if="settings.captcha !== 'none'" class="text-xs text-slate-500">{{ t("site.forms.captchaSetup") }}</p>
      <p v-if="settings.captcha === 'recaptcha_v3'" class="text-xs text-slate-500">{{ t("site.forms.captchaV3Hint") }}</p>
      <router-link v-if="auth.can('site.manage')" to="/website/forms" class="inline-block text-sm text-theme-600 hover:underline">{{ t('site.forms.openSetup') }}</router-link>
    </section>

  </section>
</template>

<script setup>
import LocalizedInput from "../LocalizedInput.vue";
import { computed } from "vue";
import { Icon } from "@iconify/vue";
import { useI18n } from "../../i18n/index.js";
import { useAuthStore } from "../../stores/auth.js";

const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
  name: { type: String, default: "" },
  fields: { type: Array, default: () => [] },
});
const emit = defineEmits(["update:modelValue"]);
const { t } = useI18n();
const auth = useAuthStore();
const settings = computed(() => ({ status: "draft", captcha: "none", ...props.modelValue }));
const supported = new Set(["text", "textarea", "markdown", "number", "range", "boolean", "select", "date", "datetime", "color", "media"]);
const fillable = computed(() => props.fields.filter((field) => supported.has(field.type ?? "text")));
const unsupported = computed(() => props.fields.filter((field) => !supported.has(field.type ?? "text")).map((field) => field.label || field.key));
function update(patch) {
  emit("update:modelValue", { ...settings.value, ...patch });
}
</script>
