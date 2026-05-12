<script setup>
const steps = [
  {
    num: '1',
    icon: '⬇️',
    title: 'Download',
    desc: 'Grab the latest release ZIP from GitHub.',
    action: {
      text: 'Download latest release',
      href: 'https://github.com/CometCMS/CometCMS/releases/latest',
    },
  },
  {
    num: '2',
    icon: '📤',
    title: 'Upload',
    desc: 'Extract the ZIP and upload its contents to your server\'s web root via FTP or your host\'s file manager.',
  },
  {
    num: '3',
    icon: '🚀',
    title: 'Open /admin',
    desc: 'Visit yourdomain.com/admin in your browser and create your admin account on the setup screen.',
  },
]

const requirements = ['PHP 8.1+', 'Apache · Nginx · Caddy', 'No database', 'No Composer', 'No CLI / SSH']
</script>

<template>
  <section class="download-section">
    <div class="download-inner">
      <h2 class="landing-title">Get started in minutes</h2>
      <p class="landing-sub">No build tools on the server. No environment variables. Just upload and go.</p>

      <div class="steps">
        <div v-for="step in steps" :key="step.num" class="step-card">
          <span class="step-num" aria-hidden="true">{{ step.num }}</span>
          <div class="step-icon" aria-hidden="true">{{ step.icon }}</div>
          <h3 class="step-title">{{ step.title }}</h3>
          <p class="step-desc">{{ step.desc }}</p>
          <a
            v-if="step.action"
            :href="step.action.href"
            class="step-btn"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ step.action.text }}
          </a>
        </div>
      </div>

      <div class="requirements" aria-label="Server requirements">
        <span v-for="req in requirements" :key="req" class="req-badge">{{ req }}</span>
      </div>

      <p class="docs-link">
        Need more details?
        <a href="./guide/installation">Read the full installation guide →</a>
      </p>
    </div>
  </section>
</template>

<style scoped>
.download-section {
  padding: 80px 24px;
  border-top: 1px solid var(--vp-c-divider);
}

.download-inner {
  max-width: 860px;
  margin: 0 auto;
}

.steps {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
  margin-bottom: 36px;
}

@media (max-width: 640px) {
  .steps {
    grid-template-columns: 1fr;
  }
}

.step-card {
  position: relative;
  background: var(--vp-c-bg-soft);
  border: 1px solid var(--vp-c-divider);
  border-radius: 12px;
  padding: 28px 22px 22px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 6px;
}

.step-num {
  position: absolute;
  top: 14px;
  left: 14px;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: var(--vp-c-brand-1);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.step-icon {
  font-size: 30px;
  margin-top: 4px;
}

.step-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--vp-c-text-1);
  margin: 4px 0 0;
}

.step-desc {
  font-size: 14px;
  color: var(--vp-c-text-2);
  margin: 0;
  flex: 1;
  line-height: 1.55;
}

.step-btn {
  display: inline-block;
  margin-top: 14px;
  padding: 8px 18px;
  background: var(--vp-c-brand-1);
  color: #fff;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 500;
  text-decoration: none;
  transition: background 0.15s;
}

.step-btn:hover {
  background: #1d4ed8;
}

.requirements {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
  margin-bottom: 28px;
}

.req-badge {
  padding: 4px 12px;
  border-radius: 4px;
  background: var(--vp-c-brand-soft);
  color: var(--vp-c-brand-1);
  font-size: 13px;
  font-weight: 500;
}

.docs-link {
  text-align: center;
  font-size: 14px;
  color: var(--vp-c-text-2);
  margin: 0;
}

.docs-link a {
  color: var(--vp-c-brand-1);
  font-weight: 500;
  text-decoration: none;
}

.docs-link a:hover {
  text-decoration: underline;
}
</style>
