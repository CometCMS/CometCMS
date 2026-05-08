<template>
  <div class="min-h-screen bg-slate-100 flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
      <div class="text-center mb-8">
        <img
          src="/img/cms-logo-black.png"
          alt="CometCMS Logo"
          class="mx-auto mb-4 h-16"
        />
        <p class="text-slate-500 mt-1 text-sm">{{ t("login.subtitle") }}</p>
      </div>

      <div class="card p-6">
        <form @submit.prevent="handleLogin" class="space-y-4">
          <div
            v-if="errorMsg"
            class="p-3 rounded-lg text-sm"
            :class="
              rateLimited
                ? 'bg-amber-50 text-amber-800'
                : 'bg-red-50 text-red-700'
            "
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
            <label class="form-label">{{ t("login.password") }}</label>
            <input
              v-model="password"
              type="password"
              required
              class="form-input w-full rounded-lg border-slate-300"
            />
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="btn-primary w-full justify-center"
          >
            <span v-if="loading">{{ t("login.signingIn") }}</span>
            <span v-else>{{ t("login.signIn") }}</span>
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth.js";
import { useI18n } from "../i18n/index.js";

const auth = useAuthStore();
const router = useRouter();
const { t } = useI18n();
const username = ref("");
const password = ref("");
const loading = ref(false);
const errorMsg = ref("");
const rateLimited = ref(false);

async function handleLogin() {
  errorMsg.value = "";
  rateLimited.value = false;
  loading.value = true;
  try {
    await auth.login(username.value, password.value);
    router.push("/dashboard");
  } catch (err) {
    rateLimited.value = err.status === 429;
    errorMsg.value = rateLimited.value
      ? rateLimitMessage(err.retryAfter)
      : (err.message ?? t("login.failed"));
  } finally {
    loading.value = false;
  }
}

function rateLimitMessage(seconds) {
  const retryAfter = Number(seconds);

  if (!Number.isFinite(retryAfter) || retryAfter <= 0) {
    return t("login.rateLimited");
  }

  const minutes = Math.ceil(retryAfter / 60);

  return t("login.rateLimitedMinutes", {
    minutes,
    unit: t(minutes === 1 ? "login.minute" : "login.minutes"),
  });
}
</script>
