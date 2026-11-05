// Capture the real admin with fictional data in a disposable installation.
// Requires PHP (zip and GD), Playwright Chromium, or CHROME_BIN.
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, mkdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';

const root = fileURLToPath(new URL('../', import.meta.url));
assert(existsSync(join(root, 'documentation/.vitepress/config.js')), 'Clone https://github.com/CometCMS/documentation.git into documentation/ before capturing screenshots.');
const temporary = mkdtempSync(join(tmpdir(), 'comet-docs-'));
const install = join(temporary, 'site');
const output = join(root, 'documentation/screenshots');
const workspace = 'northstar-studio';
let php, browser, page;
const errors = [];
const failedRequests = [];
try {
  const build = spawnSync(process.execPath, ['scripts/build.mjs', 'full', install], { cwd: root, stdio: 'inherit' });
  if (build.error) throw build.error;
  assert.equal(build.status, 0, 'Temporary installation build');
  const port = await new Promise((resolve) => {
    const server = createServer();
    server.listen(0, '127.0.0.1', () => {
      const port = server.address().port;
      server.close(() => resolve(port));
    });
  });
  const origin = `http://127.0.0.1:${port}`;
  php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', install, join(install, 'router.php')], { stdio: 'ignore' });
  let ready = false;
  for (let attempt = 0; attempt < 60; attempt++) {
    try { await fetch(`${origin}/admin`); ready = true; break; }
    catch { await new Promise((resolve) => setTimeout(resolve, 100)); }
  }
  assert(ready, 'PHP server started');
  browser = await chromium.launch({
    ...(process.env.CHROME_BIN ? { executablePath: process.env.CHROME_BIN } : {}),
    args: ['--no-sandbox'],
  });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'en-GB', timezoneId: 'Europe/Berlin', colorScheme: 'light', reducedMotion: 'reduce' });
  await context.addInitScript(() => {
    localStorage.setItem('cometcms-admin-locale', 'en');
    localStorage.setItem('cometcms-builder-guide-seen', '1');
  });
  // Fetch the original Iconify responses with Node, avoiding browser network retries.
  const pendingIcons = new Set();
  const iconErrors = [];
  await context.route(/^https:\/\/(api\.iconify\.design|api\.simplesvg\.com|api\.unisvg\.com)\//, async (route) => {
    const request = (async () => {
      try {
        const response = await fetch(route.request().url(), { signal: AbortSignal.timeout(15000) });
        assert(response.ok, `Icon request: ${response.status}`);
        await route.fulfill({ contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: await response.text() });
      } catch (error) {
        iconErrors.push(error.message);
        await route.abort();
      }
    })();
    pendingIcons.add(request);
    await request;
    pendingIcons.delete(request);
  });
  page = await context.newPage();
  page.setDefaultTimeout(20000);
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('response', (response) => {
    if (response.url().includes('/admin/api/') && response.status() >= 400 && !response.url().endsWith('/me')) {
      failedRequests.push(`${response.status()} ${new URL(response.url()).pathname}`);
    }
  });
  mkdirSync(output, { recursive: true });
  async function capture(name, fullPage = false) {
    // Iconify batches requests after mounting; wait for the batch and Vue's paint.
    await page.waitForTimeout(300);
    await Promise.all([...pendingIcons]);
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await page.evaluate(async () => {
      await document.fonts.ready;
      await Promise.all([...document.images].map((img) => img.decode().catch(() => {})));
    });
    await page.mouse.move(1435, 995);
    await page.screenshot({ path: join(output, `${name}.png`), animations: 'disabled', fullPage });
    console.log(`Captured ${name}`);
  }
  async function visit(path, selector) {
    await page.goto(`${origin}/admin/${path}`);
    await page.locator(selector).first().waitFor();
    await page.getByText('Loading…', { exact: true }).waitFor({ state: 'hidden' });
  }
  await visit('setup', '#setup-workspace');
  await page.locator('#setup-workspace').fill('Northstar Studio');
  await page.locator('#setup-workspace-slug').fill(workspace);
  await capture('first-login', true);

  // Use real endpoints to seed mock content; no requests touch cms/storage/.
  let csrf = '';
  async function api(method, path, data) {
    const response = await context.request.fetch(`${origin}/admin/api/${path}`, {
      method, data,
      headers: { 'X-CSRF-Token': csrf, 'X-Comet-Workspace': workspace },
      timeout: 60000,
    });
    csrf = response.headers()['x-csrf-token'] || csrf;
    assert(response.ok(), `${method} ${path}: ${response.status()} ${await response.text()}`);
    return (await response.json()).data;
  }
  await api('POST', 'setup', {
    username: 'demo-admin', password: 'documentation-only-2026',
    workspace: 'Northstar Studio', workspace_slug: workspace, starter_template: 'website',
    languages: { locales: ['en', 'de'], default_locale: 'en' },
  });
  const pages = await api('GET', 'content/pages');
  const about = pages.find((entry) => entry.slug === 'about');
  assert(about, 'Starter About page');
  await api('PUT', `content/pages/${about.id}`, {
    title: 'About our studio', intro: 'Thoughtful design for people and places.',
    body: '## Small team, shared curiosity\n\nWe are a fictional creative studio exploring design, community and useful ideas.\n\n## What we believe\n\nGood work starts with listening. We bring clear thinking and careful craft to every project.',
    status: 'published', published_at: '2026-10-20T09:00:00Z',
  });
  await api('PUT', `content/pages/${about.id}`, { locale: 'de', title: 'Über unser Studio', slug: 'ueber-uns', intro: 'Gutes Design für Menschen und Orte.', status: 'draft' });
  for (const [title, slug, status] of [
    ['Our approach', 'our-approach', 'published'],
    ['Visit the studio', 'visit-the-studio', 'draft'],
    ['Winter programme', 'winter-programme', 'protected'],
  ]) await api('POST', 'content/pages', { title, slug, intro: 'Fictional sample content for the documentation.', status });
  await api('POST', 'content-types', {
    name: 'inquiries', label: 'Inquiries', icon: 'mdi:email-outline', api_enabled: false,
    visibility: 'private', allow_submissions: true,
    fields: { email: { type: 'text', label: 'Email address', required: true }, message: { type: 'textarea', label: 'Message' } },
  });
  for (const [username, role] of [['demo-editor', 'editor'], ['demo-reviewer', 'viewer']]) {
    await api('POST', 'users', { username, password: 'documentation-only-2026', role });
  }
  await api('POST', 'workspaces', { label: 'Northstar Journal', slug: 'northstar-journal', starter_template: 'empty' });
  await api('POST', 'workspaces', { label: 'Northstar Staging', slug: 'northstar-staging', starter_template: 'empty' });
  await api('POST', 'tokens', {
    name: 'Website reader', description: 'Fictional documentation integration',
    permissions: [
      { effect: 'allow', actions: ['content.read'], resources: [`workspace:${workspace}:content:*`] },
      { effect: 'allow', actions: ['schema.read'], resources: [`workspace:${workspace}:schema:*`] },
      { effect: 'allow', actions: ['media.read'], resources: [`workspace:${workspace}:media:*`] },
    ],
  });
  await api('POST', 'tokens', {
    name: 'Editorial assistant', description: 'Fictional documentation integration',
    permissions: [{ effect: 'allow', actions: ['content.read', 'content.update'], resources: [`workspace:${workspace}:content:pages:*`] }],
  });

  // Render local geometric artwork into PNG fixtures using the browser canvas.
  // These are upload samples, not generated screenshots or external photos.
  for (const [index, filename] of ['studio-hills.png', 'workshop-shapes.png', 'brand-palette.png'].entries()) {
    const encoded = await page.evaluate((index) => {
      const canvas = document.createElement('canvas'); canvas.width = 960; canvas.height = 640;
      const ctx = canvas.getContext('2d');
      const palettes = [['#e8eee9', '#d9ab72', '#526d5b', '#293f35'], ['#efe7df', '#d6886f', '#516a87', '#253e55'], ['#e8e5f1', '#b6a7d1', '#70628e', '#3e3656']];
      const [bg, sun, near, far] = palettes[index];
      ctx.fillStyle = bg; ctx.fillRect(0, 0, 960, 640);
      ctx.fillStyle = sun; ctx.beginPath(); ctx.arc(705, 190, 90, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = near; ctx.beginPath(); ctx.moveTo(0, 475); ctx.bezierCurveTo(220, 200, 420, 580, 960, 330); ctx.lineTo(960, 640); ctx.lineTo(0, 640); ctx.fill();
      ctx.fillStyle = far; ctx.beginPath(); ctx.moveTo(0, 570); ctx.bezierCurveTo(380, 340, 560, 660, 960, 485); ctx.lineTo(960, 640); ctx.lineTo(0, 640); ctx.fill();
      return canvas.toDataURL('image/png').split(',')[1];
    }, index);
    const response = await context.request.post(`${origin}/admin/api/media`, {
      headers: { 'X-CSRF-Token': csrf, 'X-Comet-Workspace': workspace },
      multipart: { 'media[]': { name: filename, mimeType: 'image/png', buffer: Buffer.from(encoded, 'base64') }, category: 'Studio / Illustrations' },
    });
    csrf = response.headers()['x-csrf-token'] || csrf;
    assert(response.ok(), `Upload mock media: ${await response.text()}`);
  }
  // Disabled hooks demonstrate settings without sending to an external service.
  await api('PUT', 'webhooks', { webhooks: [{ name: 'Publish notification (demo)', url: 'https://example.com/hooks/site-published', secret: 'fictional-demo-secret', enabled: false, events: ['content.published', 'content.unpublished'] }] });
  await api('POST', 'backups', { parts: ['content_types', 'content', 'media', 'site'] });

  await visit('dashboard', 'h1');
  await capture('view-dashboard');
  await visit('content-types', 'table tbody tr');
  await capture('view-content-types');
  await visit('content-types/events/edit', '#content-type-submissions');
  await capture('edit-content-type');
  await visit('content/pages', 'h1');
  await capture('view-content-entries');
  await visit(`content/pages/${about.id}`, '#content-title');
  await capture('edit-content-entry');
  await visit('media', 'img[src*="/media/"]');
  await capture('view-media');
  await visit('website/workspaces', 'h1');
  await capture('view-workspaces');
  await visit('website/general', '#site-title');
  await capture('view-website-settings');
  await visit('connect/access-tokens', 'h2');
  await page.getByRole('button', { name: /^Website reader/ }).click();
  await capture('view-api-tokens');
  await visit('connect/webhooks', 'h2');
  await page.locator('.card button.shrink-0.text-slate-400').click();
  await page.locator('input[type="url"]').waitFor();
  await capture('view-webhooks');
  await visit('backups', 'h1');
  await page.getByText(/cometcms-backup-/).first().click();
  await page.getByRole('button', { name: 'Restore selected parts', exact: true }).waitFor();
  await capture('view-backups');
  await visit('roles', 'h1');
  const editorRole = page.locator('.card').filter({ has: page.locator('h2', { hasText: 'Editor' }) });
  await editorRole.getByRole('button', { name: 'Edit', exact: true }).click();
  await editorRole.locator('input').first().waitFor();
  await editorRole.getByRole('tab', { name: 'Content', exact: true }).click();
  await editorRole.evaluate((el) => el.scrollIntoView({ block: 'start' }));
  await capture('view-user-role-permissions');
  await visit('page-builder/menus', 'h1');
  await capture('view-menus');
  await visit(`page-builder/builder/page/pages/${about.id}`, 'iframe');
  const canvas = page.frameLocator('iframe');
  await canvas.locator('[data-kind="heading"]').first().waitFor();
  await canvas.locator('[data-kind="heading"]').first().click();
  await page.getByText('Loading…', { exact: true }).waitFor({ state: 'hidden' });
  await capture('view-page-builder');
  assert.deepEqual(iconErrors, [], 'All screenshot icons loaded');
  assert.deepEqual(errors, [], 'No browser runtime errors');
  assert.deepEqual(failedRequests, [], 'No failed admin API requests');
  console.log('Documentation screenshots captured successfully. All data was fictional; temporary installation removed.');
} catch (error) {
  if (page) {
    const debug = join(tmpdir(), 'comet-docs-capture-error.png');
    await page.screenshot({ path: debug }).catch(() => {});
    console.error(`Capture failed at ${page.url()}; debug screenshot: ${debug}`);
  }
  throw error;
} finally {
  await browser?.close();
  php?.kill();
  rmSync(temporary, { recursive: true, force: true });
}
