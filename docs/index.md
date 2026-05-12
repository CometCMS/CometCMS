---
layout: home

hero:
  name: CometCMS
  text: The CMS that fits on any PHP host
  tagline: File-based, headless, zero-dependency. Upload a ZIP, open /admin — you're done.
  image:
    light: /cms-logo-black.png
    dark: /cms-logo-white.png
    alt: CometCMS
  actions:
    - theme: brand
      text: Get Started
      link: /guide/introduction
    - theme: alt
      text: Documentation
      link: /guide/installation
    - theme: alt
      text: Download
      link: https://github.com/CometCMS/CometCMS/releases/latest

features:
  - icon: 📁
    title: File-based storage
    details: Content is stored as plain JSON files. No database to set up, migrate, or maintain.
    link: /guide/content-types
    linkText: Content types
  - icon: 🔌
    title: Headless & API-first
    details: A clean public REST API lets you consume content from any frontend — Next.js, Astro, SvelteKit, or plain fetch.
    link: /api/public-api
    linkText: API reference
  - icon: 🧩
    title: Flexible field types
    details: Build schemas with text, rich text, media, relations, selects, dates, and more.
    link: /guide/field-types
    linkText: Field types
  - icon: 🚀
    title: Zero server dependencies
    details: PHP 8.1+ is the only production requirement. No Composer, no database, no CLI, no SSH, no Node.js on the server.
    link: /guide/installation
    linkText: Installation
  - icon: 🔑
    title: Scoped API tokens
    details: Fine-grained permissions per action, content type, media category, and field. No overly broad keys.
    link: /guide/api-tokens
    linkText: API tokens
  - icon: 💾
    title: Built-in backups
    details: Create and restore full backups of all content, media, and settings directly from the admin UI.
    link: /guide/backups
    linkText: Backup & restore
---
