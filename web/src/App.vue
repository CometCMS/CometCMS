<template>
  <AppLayout v-if="$route.meta.requiresAuth">
    <router-view v-slot="{ Component }">
      <transition name="page" mode="out-in">
        <component :is="Component" :key="$route.path" />
      </transition>
    </router-view>
  </AppLayout>

  <router-view v-else v-slot="{ Component }">
    <transition name="page" mode="out-in">
      <component :is="Component" :key="$route.path" />
    </transition>
  </router-view>
</template>

<script setup>
import AppLayout from "./components/AppLayout.vue";
</script>

<style>
.page-enter-active {
  transition: opacity 0.12s ease;
}
.page-leave-active {
  transition: none;
}
.page-enter-from {
  opacity: 0;
}
</style>
