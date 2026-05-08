import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'CometCMS',
  description: 'Documentation for CometCMS — a lightweight file-based CMS.',
  base: '/CometCMS/',
  themeConfig: {
    logo: { light: '/cms-logo-black.png', dark: '/cms-logo-white.png' },
    siteTitle: false,
    nav: [
      { text: 'Guide', link: '/guide/introduction' },
      { text: 'API Reference', link: '/api/public-api' },
    ],
    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Introduction', link: '/guide/introduction' },
          { text: 'Installation', link: '/guide/installation' },
          { text: 'First Login', link: '/guide/first-login' },
        ],
      },
      {
        text: 'Workspaces',
        items: [
          { text: 'Workspaces', link: '/guide/workspaces' },
        ],
      },
      {
        text: 'Content',
        items: [
          { text: 'Content Types', link: '/guide/content-types' },
          { text: 'Field Types', link: '/guide/field-types' },
          { text: 'Content Entries', link: '/guide/content-entries' },
          { text: 'Media Library', link: '/guide/media' },
        ],
      },
      {
        text: 'Users & Access',
        items: [
          { text: 'Managing Users', link: '/guide/users' },
          { text: 'API Tokens', link: '/guide/api-tokens' },
        ],
      },
      {
        text: 'Import & Export',
        items: [
          { text: 'Backup & Migration', link: '/guide/backups' },
        ],
      },
      {
        text: 'Integrations',
        items: [
          { text: 'Webhooks', link: '/guide/webhooks' },
        ],
      },
      {
        text: 'Recovery',
        items: [
          { text: 'Admin Lockout', link: '/guide/recovery' },
        ],
      },
      {
        text: 'API Reference',
        items: [
          { text: 'Public API', link: '/api/public-api' },
          { text: 'Admin API', link: '/api/admin-api' },
          { text: 'OpenAPI', link: '/api/openapi' },
        ],
      },
    ],
    socialLinks: [],
    search: {
      provider: 'local',
    },
    footer: {
      message: 'CometCMS Documentation',
    },
  },
})
