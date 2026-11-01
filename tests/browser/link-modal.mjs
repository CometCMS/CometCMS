// Run with: node tests/browser/link-modal.mjs (requires PHP and Chrome/Chromium).
// Add --builder after npm run build to exercise the template editor too.
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { cpSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const directory = mkdtempSync(join(tmpdir(), 'cometcms-link-modal-'));
let php, browser;
try {
  for (const path of ['app', 'config', 'index.php']) cpSync(join(root, 'cms', path), join(directory, path), { recursive: true });
  if (process.argv.includes('--builder')) cpSync(join(root, 'dist/admin'), join(directory, 'admin'), { recursive: true });
  writeFileSync(join(directory, 'router.php'), `<?php
if (str_starts_with($_SERVER['REQUEST_URI'], '/admin/assets/') && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) return false;
$_SERVER['SCRIPT_NAME'] = str_starts_with($_SERVER['REQUEST_URI'], '/cms/') ? '/cms/index.php' : '/index.php';
require __DIR__ . '/index.php';`);
  writeFileSync(join(directory, 'seed.php'), `<?php
require __DIR__ . '/app/bootstrap.php';
(new \\CometCMS\\Auth\\UserRepository())->create('modal-admin', 'secret-password', 'admin');
(new \\CometCMS\\Workspaces\\WorkspaceRepository())->all();
(new \\CometCMS\\Content\\ContentTypeRepository())->save(['name' => 'posts']);
$content = \\CometCMS\\Content\\ContentRepository::make();
$content->save('posts', ['title' => 'Source page', 'slug' => 'source', 'status' => 'published'], ['id' => 'modal-admin']);
$target = $content->save('posts', ['title' => 'Target page', 'slug' => 'target', 'status' => 'published'], ['id' => 'modal-admin']);
$site = new \\CometCMS\\Site\\SiteRepository();
$site->saveSettings(['homepage' => ['type' => 'posts', 'entry' => ''], 'routes' => ['posts' => ['enabled' => true, 'pattern' => '/posts/{slug}', 'index_enabled' => true, 'index_path' => '/posts']]]);
$node = \\CometCMS\\Site\\LayoutSchema::node(...);
$site->saveLayout('posts', 'detail', [$node('section', [], [], [$node('row', [], [], [$node('column', [], [], [
    $node('heading', ['text' => \\CometCMS\\Site\\SmartValue::reference('title')]),
    $node('button', ['label' => 'Entry modal', 'link' => ['type' => 'entry', 'collection' => 'posts', 'entry' => $target['uid'], 'anchor' => 'main', 'open_modal' => true]]),
    $node('button', ['label' => 'List modal', 'link' => ['type' => 'index', 'collection' => 'posts', 'open_modal' => true]]),
    $node('button', ['label' => 'Home modal', 'link' => ['type' => 'home', 'open_modal' => true]]),
    $node('button', ['label' => 'Current modal', 'link' => ['type' => 'current', 'open_modal' => true]]),
    $node('button', ['label' => 'Normal internal', 'link' => ['type' => 'entry', 'collection' => 'posts', 'entry' => $target['uid']]]),
    $node('button', ['label' => 'External link', 'link' => ['type' => 'url', 'url' => 'https://example.org/other', 'open_modal' => true, 'new_tab' => true]]),
    $node('tabs', [], [], [$node('tab', ['title' => 'First'], [], [$node('heading', ['text' => 'First panel'])]), $node('tab', ['title' => 'Second'], [], [$node('heading', ['text' => 'Second panel'])])]),
])])])]);
session_id('modal-browser-session'); session_start(); $_SESSION['user_id'] = 'modal-admin'; session_write_close();`);
  execFileSync('php', [join(directory, 'seed.php')]);
  const port = await new Promise((resolve) => {
    const server = createServer();
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
  });
  const origin = `http://127.0.0.1:${port}`;
  php = spawn('php', ['-S', `127.0.0.1:${port}`, join(directory, 'router.php')], { cwd: directory, stdio: 'ignore' });
  browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  page.setDefaultTimeout(10000);
  page.on('pageerror', (error) => console.error(error));
  for (const base of ['', '/cms']) {
    const source = origin + base + '/posts/source';
    const response = await page.goto(source);
    assert.equal(response.status(), 200);
    assert.match(response.headers()['content-security-policy'], /frame-src 'self'/);
    assert.match(response.headers()['content-security-policy'], /script-src 'sha256-/);
    await page.evaluate(() => { window.violations = []; document.addEventListener('securitypolicyviolation', (event) => window.violations.push(event.violatedDirective)); });
    const history = await page.evaluate(() => window.history.length);
    const opener = page.getByRole('link', { name: 'Entry modal', exact: true });
    await opener.click();
    const dialog = page.getByRole('dialog', { name: 'Entry modal' });
    await dialog.waitFor({ state: 'visible' });
    assert(await dialog.evaluate((element) => element.matches(':modal')));
    assert.equal(page.url(), source, 'Opening a modal leaves the parent URL unchanged');
    const content = page.frameLocator('.c-modal-frame');
    await content.getByRole('heading', { name: 'Target page', exact: true }).waitFor();
    assert.equal(await dialog.locator('iframe').getAttribute('src'), origin + base + '/posts/target?embed=1#main');
    assert.equal(await content.locator('header, footer, .c-skip-link').count(), 0, 'Embedded pages show only their main content');
    await page.waitForFunction(() => {
      const frame = document.querySelector('.c-modal-frame');
      return Math.abs(frame.getBoundingClientRect().height - frame.contentDocument.body.getBoundingClientRect().height) <= 1;
    });
    assert((await dialog.boundingBox()).height < 700, 'The modal is as tall as the embedded page');
    await content.getByRole('tab', { name: 'Second', exact: true }).click();
    assert.equal(await content.getByRole('tab', { name: 'Second', exact: true }).getAttribute('aria-selected'), 'true', 'Destination scripts work under their CSP');
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden' });
    assert(await opener.evaluate((element) => element === document.activeElement), 'Escape from the frame restores focus');
    assert.equal(await page.locator('.c-modal-frame').count(), 0, 'Closing removes the frame');

    for (const label of ['List modal', 'Home modal', 'Current modal']) {
      await page.getByRole('link', { name: label, exact: true }).click();
      await page.locator('.c-modal[open]').waitFor();
      await content.locator('main').waitFor();
      await page.getByRole('button', { name: 'Close', exact: true }).click();
      await page.locator('.c-modal[open]').waitFor({ state: 'hidden' });
    }
    assert.equal(await page.evaluate(() => window.history.length), history, 'Modals never add history entries');

    // Inside the modal, modal links swap the page in place and other links leave for the whole window.
    await opener.click();
    await content.getByRole('heading', { name: 'Target page', exact: true }).waitFor();
    await content.getByRole('link', { name: 'List modal', exact: true }).click();
    await page.getByRole('dialog', { name: 'List modal' }).waitFor();
    // The list page is also the homepage here.
    await page.waitForFunction(() => /\/\?embed=1$|\/posts\?embed=1$/.test(document.querySelector('.c-modal-frame')?.getAttribute('src') ?? ''));
    await content.locator('main').waitFor();
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await opener.click();
    await content.getByRole('link', { name: 'Normal internal', exact: true }).click();
    await page.waitForURL(origin + base + '/posts/target');
    await page.goto(source);

    const external = page.getByRole('link', { name: 'External link', exact: true });
    assert.equal(await external.getAttribute('data-link-modal'), null);
    assert.equal(await external.getAttribute('target'), '_blank');
    assert.equal(await external.getAttribute('href'), 'https://example.org/other');
    const modified = await opener.evaluate((element) => {
      const event = new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true });
      let prevented;
      document.addEventListener('click', (event) => { prevented = event.defaultPrevented; event.preventDefault(); }, { once: true });
      element.dispatchEvent(event);
      return prevented;
    });
    assert.equal(modified, false, 'Modified clicks retain browser navigation');
    assert.equal(await page.locator('.c-modal[open]').count(), 0);
    // Defend against an external URL accidentally receiving a trigger attribute.
    assert.equal(await external.evaluate((element) => {
      element.dataset.linkModal = '';
      let prevented;
      document.addEventListener('click', (event) => { prevented = event.defaultPrevented; event.preventDefault(); }, { once: true });
      element.click();
      return prevented;
    }), false);

    // On a short screen the page scrolls inside the frame; the close button stays in view.
    await page.setViewportSize({ width: 375, height: 320 });
    await page.evaluate(() => { window.violations = []; document.addEventListener('securitypolicyviolation', (event) => window.violations.push(event.violatedDirective)); });
    await opener.click();
    await content.getByRole('heading', { name: 'Target page', exact: true }).waitFor();
    const bounds = await page.locator('.c-modal').boundingBox();
    assert(bounds.width <= 375 && bounds.height <= 320, 'Modal fits small screens');
    assert.equal(await page.locator('.c-modal').evaluate((element) => element.scrollHeight <= element.clientHeight), true, 'The panel itself does not scroll');
    const close = await page.getByRole('button', { name: 'Close', exact: true }).boundingBox();
    assert(close.y >= bounds.y && close.y + close.height <= bounds.y + bounds.height, 'The close button stays visible');
    await page.mouse.click(1, 1);
    await page.locator('.c-modal[open]').waitFor({ state: 'hidden' });
    assert.deepEqual(await page.evaluate(() => window.violations), []);
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.getByRole('link', { name: 'Normal internal', exact: true }).click();
    await page.waitForURL(origin + base + '/posts/target');
  }

  // A custom template applies to every link: here a drawer with a title and its own close button.
  writeFileSync(join(directory, 'customize.php'), `<?php
require __DIR__ . '/app/bootstrap.php';
$node = \\CometCMS\\Site\\LayoutSchema::node(...);
(new \\CometCMS\\Site\\SiteRepository())->saveRegion('modal', [$node('modal', ['size' => 'sm', 'placement' => 'right', 'backdrop_close' => false], ['background' => 'surface', 'padding_top' => 'md', 'padding_bottom' => 'md'], [
    $node('modal_title', ['level' => 'h2']),
    $node('modal_close', ['show_label' => true, 'label' => 'Done']),
    $node('site_content'),
])]);`);
  execFileSync('php', [join(directory, 'customize.php')]);
  await page.goto(origin + '/posts/source');
  await page.getByRole('link', { name: 'Entry modal', exact: true }).click();
  await page.getByRole('dialog').getByRole('heading', { name: 'Entry modal', exact: true }).waitFor();
  await page.frameLocator('.c-modal-frame').getByRole('heading', { name: 'Target page', exact: true }).waitFor();
  await page.locator('.c-modal').evaluate((element) => Promise.all(element.getAnimations().map((animation) => animation.finished)));
  const drawer = await page.locator('.c-modal').boundingBox();
  assert(Math.abs(drawer.x + drawer.width - 1280) <= 1 && Math.abs(drawer.y) <= 1 && Math.abs(drawer.height - 720) <= 1 && drawer.width < 640, 'Drawers sit along their edge');
  await page.mouse.click(1, 1);
  assert.equal(await page.locator('.c-modal[open]').count(), 1, 'The backdrop can be set to keep the modal open');
  await page.getByRole('button', { name: 'Done', exact: true }).click();
  await page.locator('.c-modal[open]').waitFor({ state: 'hidden' });

  await page.context().addCookies([{ name: 'cometcms_admin', value: 'modal-browser-session', url: origin }]);
  await page.goto(origin + '/cms/admin/site-preview/posts/source?workspace=default');
  await page.getByRole('link', { name: 'Entry modal', exact: true }).click();
  await page.frameLocator('.c-modal-frame').getByRole('heading', { name: 'Target page', exact: true }).waitFor();
  assert.match(await page.locator('.c-modal-frame').getAttribute('src'), /\/cms\/admin\/site-preview\?.*embed=1/);
  assert.equal(await page.frameLocator('.c-modal-frame').locator('.c-preview-bar').count(), 0, 'Embedded previews leave out the admin bar');
  await page.getByRole('button', { name: 'Done', exact: true }).click();
  if (process.argv.includes('--builder')) {
    await page.addInitScript(() => localStorage.setItem('cometcms-builder-guide-seen', '1'));
    await page.goto(origin + '/admin/page-builder/builder/layout/posts/detail');
    await page.locator('.page-switcher-trigger').click();
    await page.getByRole('menuitem', { name: 'Modal', exact: true }).click();
    await page.waitForURL('**/page-builder/builder/region/modal');
    const canvas = page.frameLocator('.builder-stage iframe');
    await canvas.locator('[data-kind="site_content"] .c-placeholder').waitFor();
    assert.equal(await canvas.locator('iframe').count(), 0, 'The builder shows a placeholder rather than a live embedded page');
    await canvas.locator('[data-kind="modal"]').click({ position: { x: 4, y: 4 } });
    const backdrop = page.getByRole('switch', { name: 'Close on backdrop click', exact: true });
    assert.equal(await backdrop.getAttribute('aria-checked'), 'false');
    await backdrop.click();
    const saved = page.waitForResponse((response) => response.url().includes('/api/site/regions/modal') && response.request().method() === 'PUT');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    assert.equal((await saved).status(), 200);
    await page.reload();
    await canvas.locator('[data-kind="modal"].c-modal-at-right').waitFor();
    await page.locator('header').getByRole('button', { name: 'More actions', exact: true }).click();
    const popup = page.waitForEvent('popup');
    await page.getByRole('menuitem', { name: 'Preview', exact: true }).click();
    const preview = await popup;
    await preview.locator('.c-modal-preview[open] .c-placeholder').waitFor();
    assert.equal(await preview.locator('iframe').count(), 0, 'Full-page previews retain the content placeholder');
    await preview.close();
    await page.goto(origin + '/posts/source');
    await page.getByRole('link', { name: 'Entry modal', exact: true }).click();
    await page.mouse.click(1, 1);
    await page.locator('.c-modal[open]').waitFor({ state: 'hidden' });
    console.log('Modal template editor checks passed (header dropdown, panel selection, content placeholder, save, reload and live rendering).');
  }
  console.log('Link modal browser checks passed (internal destinations, embedded pages, history, content height, links inside the modal, external links, focus, Escape, backdrop, small screens, custom drawer template, root/subdirectory installs, preview and real CSP).');
} finally {
  await browser?.close();
  if (php) await new Promise((resolve) => { php.once('exit', resolve); if (!php.kill('SIGTERM')) resolve(); });
  rmSync(directory, { recursive: true, force: true });
}
