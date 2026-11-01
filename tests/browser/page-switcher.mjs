// Run with: node tests/browser/page-switcher.mjs (requires Chrome/Chromium).
// Exercises the actual header menu with in-memory API data only.
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from 'tailwindcss';
import loadConfig from 'tailwindcss/loadConfig.js';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const entry = `
import { createApp, h } from 'vue';
import { createPinia } from 'pinia';
import { createRouter, createMemoryHistory, useRoute } from 'vue-router';
import Switcher from '${root}web/src/components/site/builder/BuilderPageSwitcher.vue';
import { createSiteBuilder } from '${root}web/src/composables/useSiteBuilder.js';
import { useSiteStore } from '${root}web/src/stores/site.js';
import { api } from '${root}web/src/api/index.js';
import { defaultTemplate } from '${root}web/src/composables/templates.js';
import { setLocale } from '${root}web/src/i18n/index.js';
import '${root}web/src/style.css';
window.setLocale = setLocale;
window.entryReads = [];
window.failEntries = false;
api.content.list = async (type, params) => {
  window.entryReads.push({ type, ...params });
  if (window.failEntries) throw new Error('Failed');
  const all = Array.from({ length: type === 'pages' ? 102 : 0 }, (_, i) => ({ id: 'entry-' + i, title: 'Entry ' + i }));
  const matching = params.q ? all.filter(entry => entry.title.toLowerCase().includes(params.q.toLowerCase())) : all;
  return { data: matching.slice(params.offset, params.offset + params.limit), meta: { total: matching.length } };
};
api.site.areas = async (type) => ({ data: [{ key: 'design', templates: type === 'unused' ? [] : ['default'] }] });
const types = ['pages', 'empty', 'unused', 'plain'].map(name => ({ name, label: name, fields: name === 'plain' ? {} : { design: { type: 'page' } } }));
const pinia = createPinia();
const site = useSiteStore(pinia);
site.data = { regions: { header: true }, special_pages: {} };
site.draft = { templates: { pages: { detail: [{ ...defaultTemplate(), id: 'highlights', name: 'Highlights' }, defaultTemplate()] } } };
const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/page-builder/builder/:target/:name/:variant?', component: { render: () => null } }] });
await router.push('/page-builder/builder/layout/pages/detail?back=/content/pages');
window.router = router;
createApp({ setup() {
  const route = useRoute();
  const builder = createSiteBuilder({ t: key => key });
  builder.types.value = types;
  builder.settings.value = { routes: Object.fromEntries(types.map(type => [type.name, { enabled: true, index_enabled: true }])) };
  window.builder = builder;
  return () => h('main', { class: 'p-4' }, [h(Switcher, { heading: 'Pages', target: route.params.target, name: route.params.name })]);
} }).use(pinia).use(router).mount('#app');
`;

let server, browser;
try {
  server = await createServer({
    configFile: false, root: join(root, 'web'), base: '/admin/', logLevel: 'error',
    css: { postcss: { plugins: [tailwindcss({ ...loadConfig(join(root, 'web/tailwind.config.js')), content: [join(root, 'web/src/**/*.{vue,js}')] })] } },
    plugins: [vue(), {
      name: 'page-switcher-fixture',
      resolveId(id) { if (id === 'virtual:page-switcher') return '\0virtual:page-switcher'; },
      load(id) { if (id === '\0virtual:page-switcher') return entry; },
      configureServer(server) {
        server.middlewares.use(async (req, res, next) => {
          if (!req.url?.endsWith('/page-switcher')) return next();
          const html = await server.transformIndexHtml('/admin/page-switcher', '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="app"></div><script type="module" src="/@id/__x00__virtual:page-switcher"></script></body></html>');
          res.setHeader('Content-Type', 'text/html'); res.end(html);
        });
      },
    }], server: { host: '127.0.0.1', port: 0 },
  });
  await server.listen();
  browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(() => localStorage.setItem('cometcms-admin-locale', 'en'));
  await page.goto(`http://127.0.0.1:${server.httpServer.address().port}/admin/page-switcher`);
  const trigger = page.getByRole('button', { name: 'Pages', exact: true });
  const toggle = page.getByRole('menuitem', { name: 'Entries in pages', exact: true });
  await trigger.click();
  await toggle.waitFor();
  assert.equal(await page.getByRole('menuitem', { name: 'Entries in unused', exact: true }).count(), 0);
  assert.equal(await page.getByRole('menuitem', { name: 'Entries in plain', exact: true }).count(), 0);
  assert.deepEqual(await page.evaluate(() => window.entryReads), []);

  // Load on expansion, paginate past the first hundred, and navigate directly.
  await toggle.click();
  await page.getByRole('menuitem', { name: 'Entry 49', exact: true }).waitFor();
  await page.getByRole('menuitem', { name: 'Load more (50 of 102)', exact: true }).click();
  await page.getByRole('menuitem', { name: 'Entry 99', exact: true }).waitFor();
  await page.getByRole('menuitem', { name: 'Load more (100 of 102)', exact: true }).click();
  await page.getByRole('menuitem', { name: 'Entry 101', exact: true }).click();
  await page.waitForFunction(() => window.router.currentRoute.value.path.endsWith('/page/pages/entry-101'));
  assert.equal(await page.evaluate(() => window.router.currentRoute.value.query.back), '/content/pages');
  assert.deepEqual(await page.evaluate(() => window.entryReads.map(read => read.offset)), [0, 50, 100]);

  // Active entries are marked instead of marking the shared template.
  await trigger.click();
  await toggle.click();
  assert.equal(await page.getByRole('menuitem', { name: 'Entry 101', exact: true }).getAttribute('aria-current'), 'page');
  assert.equal(await page.locator('[aria-current="page"]').count(), 1);

  // Keyboard expansion, focus restoration, and Escape operate within the menu.
  await toggle.focus();
  await toggle.press('ArrowRight');
  await page.waitForFunction(() => document.activeElement?.textContent.trim() === 'Entry 0');
  await page.keyboard.press('ArrowLeft');
  assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
  assert.equal(await toggle.evaluate(element => element === document.activeElement), true);
  await toggle.press('ArrowRight');
  await page.keyboard.press('Escape');
  assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
  assert.equal(await trigger.getAttribute('aria-expanded'), 'true');

  // Existing template navigation remains available and preserves the return path.
  await page.getByRole('menuitem', { name: 'Highlights', exact: true }).click();
  await page.waitForFunction(() => window.router.currentRoute.value.query.template === 'highlights');
  assert.equal(await page.evaluate(() => window.router.currentRoute.value.path), '/page-builder/builder/layout/pages/detail');

  // A failed lazy load can be retried; empty content types have a clear state.
  await trigger.click();
  await page.evaluate(() => { window.failEntries = true; });
  await page.getByRole('menuitem', { name: 'Entries in empty', exact: true }).click();
  await page.getByRole('alert').waitFor();
  await page.evaluate(() => { window.failEntries = false; });
  await page.getByRole('menuitem', { name: 'Try again', exact: true }).click();
  await page.getByRole('status').filter({ hasText: 'No entries yet.' }).waitFor();

  // Global entry search still uses the same edit links, without a stale submenu.
  await page.getByRole('searchbox', { name: 'Search pages and entries…' }).fill('Entry 101');
  await page.getByRole('menuitem', { name: 'Entry 101 pages', exact: true }).click();
  await page.waitForFunction(() => window.router.currentRoute.value.path.endsWith('/page/pages/entry-101'));
  await trigger.click();
  await page.evaluate(() => window.setLocale('de'));
  await page.getByRole('menuitem', { name: 'Einträge in pages', exact: true }).waitFor();
  await page.setViewportSize({ width: 360, height: 800 });
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow on mobile');
  await page.mouse.click(350, 780);
  assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
  assert.deepEqual(errors, []);
  console.log('Page switcher passed: content-area gating, lazy entry submenu, pagination, edit links, current entry, keyboard, templates, retry, empty state, search, localization, and mobile layout.');
} finally {
  await browser?.close();
  await server?.close();
}
