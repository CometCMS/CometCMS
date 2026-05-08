<template>
  <div class="min-h-screen bg-slate-100 flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
      <div class="text-center mb-8">
        <!-- Logo / wordmark -->
        <img
          v-if="!logoFailed"
          src="/img/cms-logo-black.png"
          alt="CometCMS"
          class="h-14 mx-auto object-contain"
          @error="logoFailed = true"
        />
        <h1 v-else class="text-2xl font-bold text-slate-900">CometCMS</h1>
      </div>

      <!-- Installation success banner -->
      <div class="card p-6 mb-4">
        <div class="flex items-start gap-3">
          <Icon
            icon="mdi:check-circle"
            class="h-6 w-6 text-green-500 shrink-0"
          />
          <div class="flex-1">
            <p class="text-sm font-medium text-slate-800">
              {{ t("setup.successTitle") }}
            </p>
            <p class="text-slate-500 mt-1 text-sm">
              {{ t("setup.successBody") }}
            </p>
          </div>
        </div>
      </div>

      <!-- Step indicator -->
      <div class="flex items-center mb-4 px-1">
        <div class="flex flex-col items-center gap-1">
          <div
            class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold transition-colors"
            :class="
              step === 1
                ? 'bg-theme-600 text-white'
                : 'bg-emerald-500 text-white'
            "
          >
            <Icon v-if="step > 1" icon="mdi:check" class="h-4 w-4" />
            <span v-else>1</span>
          </div>
          <span
            class="text-[10px] font-medium leading-none"
            :class="step === 1 ? 'text-theme-600' : 'text-emerald-600'"
          >
            {{ t("setup.step1Title") }}
          </span>
        </div>
        <div
          class="mx-3 flex-1 h-px"
          :class="step > 1 ? 'bg-emerald-400' : 'bg-slate-200'"
        />
        <div class="flex flex-col items-center gap-1">
          <div
            class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold transition-colors"
            :class="
              step === 2
                ? 'bg-theme-600 text-white'
                : 'bg-slate-200 text-slate-400'
            "
          >
            2
          </div>
          <span
            class="text-[10px] font-medium leading-none"
            :class="step === 2 ? 'text-theme-600' : 'text-slate-400'"
          >
            {{ t("setup.step2Title") }}
          </span>
        </div>
      </div>

      <div class="card p-6">
        <!-- Step 1: Workspace -->
        <form v-if="step === 1" @submit.prevent="handleStep1" class="space-y-4">
          <div>
            <p class="text-sm font-semibold text-slate-800 mb-1">
              {{ t("setup.step1Title") }}
            </p>
            <p class="text-xs text-slate-500 mb-4">
              {{ t("setup.step1Body") }}
            </p>
          </div>

          <div
            v-if="errorMsg"
            class="p-3 bg-red-50 text-red-700 rounded-lg text-sm"
          >
            {{ errorMsg }}
          </div>

          <div>
            <label class="form-label">{{ t("setup.workspace") }}</label>
            <input
              v-model="workspaceName"
              type="text"
              required
              autofocus
              class="form-input w-full rounded-lg border-slate-300"
            />
          </div>

          <div>
            <label class="form-label">{{ t("setup.workspaceSlug") }}</label>
            <input
              v-model="workspaceSlug"
              type="text"
              required
              class="form-input w-full rounded-lg border-slate-300 font-mono text-sm"
              :class="
                slugInvalid
                  ? 'border-red-400 focus:border-red-400 focus:ring-red-300'
                  : ''
              "
              pattern="[A-Za-z0-9_-]+"
              @input="onSlugInput"
            />
            <p v-if="slugInvalid" class="mt-1 text-xs text-red-600">
              {{ t("setup.workspaceSlugInvalid") }}
            </p>
            <p v-else class="mt-1 text-xs text-slate-400">
              {{ t("setup.workspaceSlugHelp") }}
            </p>
          </div>

          <button
            type="submit"
            class="btn-primary w-full justify-center"
            :disabled="slugInvalid"
          >
            {{ t("setup.continue") }}
          </button>
        </form>

        <!-- Step 2: Admin account -->
        <form v-else @submit.prevent="handleSetup" class="space-y-4">
          <div>
            <p class="text-sm font-semibold text-slate-800 mb-1">
              {{ t("setup.step2Title") }}
            </p>
            <p class="text-xs text-slate-500 mb-4">
              {{ t("setup.step2Body") }}
            </p>
          </div>

          <div
            v-if="errorMsg"
            class="p-3 bg-red-50 text-red-700 rounded-lg text-sm"
          >
            {{ errorMsg }}
          </div>

          <div>
            <label class="form-label">{{ t("login.username") }}</label>
            <input
              v-model="username"
              type="text"
              required
              autofocus
              class="form-input w-full rounded-lg border-slate-300"
            />
          </div>

          <div>
            <label class="form-label"
              >{{ t("login.password") }}
              <span class="text-slate-400 font-normal">{{
                t("setup.passwordMin")
              }}</span>
            </label>
            <input
              v-model="password"
              type="password"
              required
              minlength="8"
              class="form-input w-full rounded-lg border-slate-300"
            />
          </div>

          <div class="flex gap-2">
            <button
              type="button"
              class="btn-secondary"
              @click="
                step = 1;
                errorMsg = '';
              "
            >
              {{ t("setup.back") }}
            </button>
            <button
              type="submit"
              :disabled="loading"
              class="btn-primary flex-1 justify-center"
            >
              <span v-if="loading">{{ t("setup.creating") }}</span>
              <span v-else>{{ t("setup.createAccount") }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth.js";
import { Icon } from "@iconify/vue";
import { useI18n } from "../i18n/index.js";
import { useAutoSlug } from "../composables/useAutoSlug.js";
import { toSlug } from "../composables/fieldBuilderUtils.js";

const auth = useAuthStore();
const router = useRouter();
const { t } = useI18n();

const step = ref(1);
const username = ref("admin");
const password = ref("");
const workspaceName = ref("");
const loading = ref(false);
const errorMsg = ref("");
const logoFailed = ref(false);

const {
  slug: workspaceSlug,
  touched: slugTouched,
  onSlugInput,
} = useAutoSlug(workspaceName);

const SLUG_RE = /^[A-Za-z0-9_-]+$/;

const slugInvalid = computed(
  () =>
    slugTouched.value &&
    workspaceSlug.value !== "" &&
    !SLUG_RE.test(workspaceSlug.value),
);

function handleStep1() {
  errorMsg.value = "";
  const slug = toSlug(workspaceSlug.value);
  if (!slug || !SLUG_RE.test(slug)) {
    slugTouched.value = true;
    return;
  }
  workspaceSlug.value = slug;
  step.value = 2;
}

async function handleSetup() {
  errorMsg.value = "";
  loading.value = true;
  try {
    await auth.setup(
      username.value,
      password.value,
      workspaceName.value,
      workspaceSlug.value,
    );
    router.push("/dashboard");
  } catch (err) {
    errorMsg.value = err.message ?? t("setup.failed");
  } finally {
    loading.value = false;
  }
}
</script>
