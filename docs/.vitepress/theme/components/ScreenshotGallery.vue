<script setup>
import { ref } from 'vue'
import imgDashboard from '../../../screenshots/view-dashboard.png'
import imgContentTypes from '../../../screenshots/view-content-types.png'
import imgEditSchema from '../../../screenshots/edit-content-type.png'
import imgMedia from '../../../screenshots/view-media.png'
import imgUsers from '../../../screenshots/view-user-role-permissions.png'
import imgBackups from '../../../screenshots/view-backups.png'
import imgWebhooks from '../../../screenshots/view-webhooks.png'

const screenshots = [
  { label: 'Dashboard',     src: imgDashboard,    alt: 'CometCMS admin dashboard' },
  { label: 'Content Types', src: imgContentTypes, alt: 'Content types overview' },
  { label: 'Edit Schema',   src: imgEditSchema,   alt: 'Editing a content type schema' },
  { label: 'Media',         src: imgMedia,        alt: 'Media library' },
  { label: 'Users & Roles', src: imgUsers,        alt: 'Role permissions editor' },
  { label: 'Backups',       src: imgBackups,      alt: 'Backup and restore screen' },
  { label: 'Webhooks',      src: imgWebhooks,     alt: 'Webhooks configuration' },
]

const active = ref(0)
</script>

<template>
  <section class="gallery-section">
    <div class="gallery-inner">
      <h2 class="landing-title">See CometCMS in action</h2>
      <p class="landing-sub">A clean, intuitive admin UI — everything you need, nothing you don't.</p>

      <div class="tabs" role="tablist" aria-label="Screenshot tabs">
        <button
          v-for="(s, i) in screenshots"
          :key="i"
          :class="['tab', { active: active === i }]"
          role="tab"
          :aria-selected="active === i"
          @click="active = i"
        >
          {{ s.label }}
        </button>
      </div>

      <div class="preview-wrap" role="tabpanel">
        <Transition name="fade" mode="out-in">
          <img
            :key="active"
            :src="screenshots[active].src"
            :alt="screenshots[active].alt"
            class="preview-img"
          />
        </Transition>
      </div>
    </div>
  </section>
</template>

<style scoped>
.gallery-section {
  background: var(--vp-c-bg-soft);
  padding: 96px 24px 80px;
  border-top: 1px solid var(--vp-c-divider);
  margin-top: 48px;
}

.gallery-inner {
  max-width: 960px;
  margin: 0 auto;
}

.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
  margin-bottom: 28px;
}

.tab {
  padding: 6px 16px;
  border-radius: 20px;
  border: 1px solid var(--vp-c-divider);
  background: var(--vp-c-bg);
  color: var(--vp-c-text-2);
  font-size: 14px;
  cursor: pointer;
  transition: border-color 0.15s, color 0.15s, background 0.15s;
}

.tab:hover {
  border-color: var(--vp-c-brand-1);
  color: var(--vp-c-brand-1);
}

.tab.active {
  background: var(--vp-c-brand-1);
  border-color: var(--vp-c-brand-1);
  color: #fff;
}

.preview-wrap {
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 4px 32px rgba(0, 0, 0, 0.12);
  border: 1px solid var(--vp-c-divider);
  background: var(--vp-c-bg);
}

.preview-img {
  width: 100%;
  display: block;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.18s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
