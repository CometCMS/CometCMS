// npm run build && node tests/browser/builder-backgrounds.mjs
// Drives the real builder with the Fancy Backgrounds and Water Ripple extensions installed:
// adds an effect section from the palette, switches its background and variant in the
// inspector, places an element on it, saves, and checks the published page, where the
// ripple script mounts under the real Content-Security-Policy. Uses an isolated copy of
// dist/ (COMET_TEST_DIST overrides it) and Chrome/Chromium (CHROME_BIN overrides it).
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { cpSync, mkdtempSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const directory = mkdtempSync(join(tmpdir(), 'comet-backgrounds-browser-'));
const install = join(directory, 'site');
cpSync(process.env.COMET_TEST_DIST || join(root, 'dist'), install, { recursive: true });
const seed = spawnSync('php', [], { encoding: 'utf8', input: `<?php
require ${JSON.stringify(join(install, 'app/bootstrap.php'))};
(new \\CometCMS\\Workspaces\\WorkspaceContext())->ensure();
(new \\CometCMS\\Workspaces\\WorkspaceRepository())->all();
(new \\CometCMS\\Auth\\RoleRepository())->seed();
$user = (new \\CometCMS\\Auth\\UserRepository())->create('bg-admin', 'bg-test-password', 'admin');
(new \\CometCMS\\Content\\ContentTypeRepository())->save(['name' => 'home', 'label' => 'Home', 'fields' => []]);
\\CometCMS\\Content\\ContentRepository::make()->save('home', ['title' => 'Home', 'status' => 'published'], $user);
$site = new \\CometCMS\\Site\\SiteRepository();
$site->saveSettings(['title' => 'Background example', 'homepage' => ['type' => 'home', 'entry' => 'home']]);
$site->saveLayout('home', 'detail', []);
foreach (['comet-fancy-bg-containers' => false, 'comet-water-ripple' => true] as $folder => $scripts) {
    $site->blocks()->installFiles(\\CometCMS\\Extensions\\Extension::readDirectory(${JSON.stringify(join(root, 'examples/extensions'))} . '/' . $folder), allowScripts: $scripts);
}
` });
assert.equal(seed.status, 0, seed.stderr || 'Seed installation');
const port = await new Promise((resolve) => {
  const server = createServer();
  server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
});
const origin = `http://127.0.0.1:${port}`;
const php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', install, join(install, 'router.php')], { stdio: 'ignore' });
const browser = await chromium.launch({
  executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome',
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

try {
  for (let attempt = 0; attempt < 50; attempt++) {
    try { await fetch(`${origin}/admin`); break; } catch { await new Promise((resolve) => setTimeout(resolve, 100)); }
  }
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'no-preference' });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error' && !message.text().startsWith('Failed to load resource')) errors.push(message.text()); });
  // Failed requests are named by URL; signed-out session checks and favicons are expected.
  page.on('response', (response) => {
    if (response.status() >= 400 && !/favicon|\/api\/(?:admin\/)?(?:me|session|auth)/.test(response.url())) errors.push(`${response.status()} ${response.url()}`);
  });

  await page.goto(`${origin}/admin`);
  await page.locator('#login-username').fill('bg-admin');
  await page.locator('#login-password').fill('bg-test-password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL(/\/dashboard$/);
  await page.goto(`${origin}/admin/page-builder/builder/layout/home/detail`);
  await page.locator('[role="dialog"][aria-labelledby^="builder-guide-"] button[aria-label="Collapse panel"]').click({ timeout: 5000 }).catch(() => {});

  // The palette offers each background as a section, and the deprecated containers no longer.
  const effects = page.locator('section', { has: page.getByRole('button', { name: 'Effect sections', exact: true }) });
  await effects.getByRole('button', { name: /^Water Ripple/ }).first().waitFor();
  for (const name of ['Deep Space', 'Futuristic Grid', 'Geometric Forms', 'Soft Lights', 'Water Ripple', 'Drifting Ripple']) {
    assert.equal(await effects.getByRole('button', { name: new RegExp(`^${name}`) }).count(), 1, `Palette offers ${name}`);
  }
  assert.equal(await page.locator('button').filter({ hasText: /^Deep Space$/ }).count(), 1, 'The space container is no element any more');

  await effects.getByRole('button', { name: /^Water Ripple/ }).first().click();
  const frame = page.frameLocator('iframe').first();
  const layer = frame.locator('section.c-section > .c-background-fx');
  await layer.first().waitFor();
  assert.match(await layer.first().getAttribute('class'), /c-block-cometcms__water-ripple/);
  // The script mounts in the preview too.
  await frame.locator('.c-block-cometcms__water-ripple canvas.cometcms-ripple__canvas').waitFor();

  // Switch the background in the inspector: the new one draws with its own props.
  await page.getByRole('tab', { name: 'Style' }).click();
  const backgroundGroup = page.getByRole('button', { name: 'Background', exact: true });
  if ((await backgroundGroup.getAttribute('aria-expanded')) !== 'true') await backgroundGroup.click();
  const picker = page.locator('select[id$="-style-background_fx"]');
  await picker.waitFor();
  assert.equal(await picker.inputValue(), 'block:cometcms/water-ripple');
  await picker.selectOption('block:cometcms/deep-space');
  await frame.locator('.c-background-fx.c-block-cometcms__deep-space .cometcms-fbg--space[data-variant="nebula"]').waitFor();
  assert.equal(await frame.locator('.cometcms-ripple__canvas').count(), 0, 'The ripple unmounted');
  // Short options show as a segmented control, like those of extension blocks.
  await page.getByRole('radio', { name: 'Starfield', exact: true }).click();
  await frame.locator('.cometcms-fbg--space[data-variant="starfield"]').waitFor();
  // Back to the ripple, with a stronger drift.
  await picker.selectOption('block:cometcms/water-ripple');
  await page.locator('input[type="range"][id$="-fx-props-drift"]').fill('50');
  await frame.locator('.c-block-cometcms__water-ripple canvas').waitFor();
  assert.equal(JSON.parse(await frame.locator('.c-background-fx').getAttribute('data-comet-props')).drift, 50);

  // Content goes on the section's own column, over the background.
  await page.getByRole('tab', { name: 'Content' }).click();
  await page.locator('button').filter({ hasText: /^Heading$/ }).first().click();
  await frame.locator('section.c-has-bg-fx .c-col .c-el-heading').waitFor();

  await page.getByRole('button', { name: 'Save layout' }).click();
  await page.waitForFunction(() => document.body.innerText.includes('Layout saved.') || [...document.querySelectorAll('button')].some((el) => el.textContent.trim() === 'Save layout' && el.disabled));

  // The published page mounts the ripple through the CSP-approved loader.
  const response = await page.goto(`${origin}/`);
  const csp = response.headers()['content-security-policy'] ?? '';
  assert.match(csp, /script-src [^;]*'sha256-/, 'The loader is allowed by hash');
  assert.doesNotMatch(csp, /script-src[^;]*'unsafe/);
  const published = page.locator('section.c-section.c-has-bg-fx');
  await published.locator('> .c-background-fx.c-block-cometcms__water-ripple canvas.cometcms-ripple__canvas').waitFor();
  assert.equal(await published.locator('.c-col .c-el-heading').count(), 1, 'Published content sits on the background');
  const box = await published.boundingBox();
  assert.ok(box.height >= 1000 * 0.5 - 1, 'The palette section starts half the screen tall');
  const painted = await published.locator('canvas').evaluate((canvas) => {
    const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
    let count = 0;
    for (let i = 3; i < data.length; i += 4) if (data[i]) count++;
    return count;
  });
  assert.ok(painted > 1000, 'The published ripple draws its lattice');
  assert.deepEqual(errors, []);
  console.log('Builder background checks passed (effect palette, preview mounting, background switching and props, content on the background, save, published CSP and ripple).');
} finally {
  await browser.close();
  php.kill();
  rmSync(directory, { recursive: true, force: true });
}
