// Run with: node tests/browser/positioning.mjs; add --builder after npm run build.
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { cpSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const directory = mkdtempSync(join(tmpdir(), 'cometcms-positioning-'));
const close = (actual, expected, message) => assert(Math.abs(actual - expected) < 1, `${message}: ${actual} vs ${expected}`);
let php, browser;
try {
  for (const path of ['app', 'config', 'index.php']) cpSync(join(root, 'cms', path), join(directory, path), { recursive: true });
  if (process.argv.includes('--builder')) cpSync(join(root, 'dist/admin'), join(directory, 'admin'), { recursive: true });
  writeFileSync(join(directory, 'router.php'), `<?php
if (str_starts_with($_SERVER['REQUEST_URI'], '/admin/assets/') && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) return false;
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';`);
  writeFileSync(join(directory, 'seed.php'), `<?php
require __DIR__ . '/app/bootstrap.php';
(new \\CometCMS\\Auth\\UserRepository())->create('position-admin', 'secret-password', 'admin');
(new \\CometCMS\\Workspaces\\WorkspaceRepository())->all();
(new \\CometCMS\\Content\\ContentTypeRepository())->save(['name' => 'posts']);
\\CometCMS\\Content\\ContentRepository::make()->save('posts', ['title' => 'Positioning', 'slug' => 'positioning', 'status' => 'published'], ['id' => 'position-admin']);
$site = new \\CometCMS\\Site\\SiteRepository();
$site->saveSettings(['routes' => ['posts' => ['enabled' => true, 'pattern' => '/posts/{slug}']]]);
$node = \\CometCMS\\Site\\LayoutSchema::node(...);
$fixed = $node('heading', ['text' => 'Fixed heading'], ['position' => 'fixed', 'pin' => 'bottom-right', 'offset_x' => -20, 'offset_y' => -30, 'z_index' => '5', 'class' => 'test-fixed']);
$fixed['id'] = 'fixed-heading';
$fixed['responsive'] = ['mobile' => ['style' => ['position' => 'static']]];
$free = $node('heading', ['text' => 'Free heading'], ['position' => 'absolute', 'pin' => 'top-right', 'offset_x' => -20, 'offset_y' => 30, 'class' => 'test-free']);
$free['id'] = 'free-heading';
$responsive = $node('heading', ['text' => 'Device heading'], ['class' => 'test-device']);
$responsive['responsive'] = ['tablet' => ['style' => ['position' => 'fixed', 'pin' => 'center', 'offset_x' => 0, 'offset_y' => 0]], 'mobile' => ['style' => ['position' => 'absolute', 'pin' => 'top-left']]];
$section = fn($children) => $node('section', ['min_height' => 'screen'], [], [$node('row', [], [], [$node('column', [], [], $children)])]);
$floating = $node('column', ['span' => ['mobile' => 12, 'tablet' => 6, 'desktop' => 3]], ['position' => 'fixed', 'pin' => 'top-right', 'offset_x' => -20, 'offset_y' => 160, 'background' => 'surface', 'padding_left' => 'sm', 'padding_right' => 'sm', 'padding_top' => 'sm', 'padding_bottom' => 'sm', 'class' => 'test-column'], [
    $node('heading', ['text' => 'Floating group']),
    $node('row', [], [], [$node('column', ['span' => ['mobile' => 12, 'tablet' => 6, 'desktop' => 6]], ['class' => 'test-nested'], [$node('text', ['html' => '<p>Nested content</p>'])])]),
]);
$floating['id'] = 'floating-column';
$floating['responsive'] = ['tablet' => ['style' => ['position' => 'absolute', 'offset_x' => 0]], 'mobile' => ['style' => ['position' => 'static']]];
$first = $section([$fixed, $free, $responsive]);
$first['children'][0]['children'][] = $floating;
$site->saveLayout('posts', 'detail', [$first, $section([$node('heading', ['text' => 'More content'])]), $section([])]);
session_id('position-browser-session'); session_start(); $_SESSION['user_id'] = 'position-admin'; session_write_close();`);
  execFileSync('php', [join(directory, 'seed.php')]);
  const port = await new Promise((resolve) => {
    const server = createServer();
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
  });
  const origin = `http://127.0.0.1:${port}`;
  php = spawn('php', ['-S', `127.0.0.1:${port}`, join(directory, 'router.php')], { cwd: directory, stdio: 'ignore' });
  browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  page.setDefaultTimeout(10000);
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(origin + '/posts/positioning');
  const fixed = page.locator('.test-fixed');
  const free = page.locator('.test-free');
  const column = page.locator('.test-column');
  const columnBefore = await column.boundingBox();
  const before = await fixed.boundingBox();
  const freeBefore = await free.boundingBox();
  const viewport = await page.evaluate(() => ({ width: document.documentElement.clientWidth, height: document.documentElement.clientHeight, unit: innerWidth / 1000 }));
  close(before.x + before.width, viewport.width - 20 * viewport.unit, 'Fixed right pin and horizontal offset');
  close(before.y + before.height, viewport.height - 30 * viewport.unit, 'Fixed bottom pin and vertical offset');
  close(columnBefore.width, viewport.width / 4, 'A fixed column keeps its desktop span relative to the viewport');
  close(await page.locator('.test-nested').evaluate((el) => el.getBoundingClientRect().width), await page.locator('.test-nested').evaluate((el) => {
    const row = el.parentElement, style = getComputedStyle(row);
    return row.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
  }), 'Nested columns fit the row inside the floating column, including its padding');
  await page.evaluate(() => scrollTo(0, 400));
  await page.waitForFunction(() => scrollY === 400);
  close((await fixed.boundingBox()).y, before.y, 'Fixed stays in the viewport while scrolling');
  close((await free.boundingBox()).y, freeBefore.y - 400, 'Free keeps moving with the section');
  close((await column.boundingBox()).y, columnBefore.y, 'Fixed columns stay in the viewport with their children');

  // All nine pin points share the same offset calculation.
  for (const [pin, ax, ay] of [['top-left', 0, 0], ['top', .5, 0], ['top-right', 1, 0], ['left', 0, .5], ['center', .5, .5], ['right', 1, .5], ['bottom-left', 0, 1], ['bottom', .5, 1], ['bottom-right', 1, 1]]) {
    await fixed.evaluate((el, pin) => { el.className = el.className.replace(/c-pin-[\w-]+/, 'c-pin-' + pin); }, pin);
    const rect = await fixed.boundingBox();
    close(rect.x, ax * (viewport.width - rect.width) - 20 * viewport.unit, pin + ' horizontal anchor');
    close(rect.y, ay * (viewport.height - rect.height) - 30 * viewport.unit, pin + ' vertical anchor');
  }
  await page.setViewportSize({ width: 820, height: 800 });
  assert.equal(await fixed.evaluate((el) => getComputedStyle(el).position), 'fixed');
  assert.equal(await column.evaluate((el) => getComputedStyle(el).position), 'absolute');
  close((await column.boundingBox()).width, 410, 'Free columns use their tablet span in the section');
  const device = page.locator('.test-device');
  assert.equal(await device.evaluate((el) => getComputedStyle(el).position), 'fixed');
  const center = await device.boundingBox();
  close(center.x + center.width / 2, 410, 'Tablet center pin');
  close(center.y + center.height / 2, 400, 'Tablet center pin vertically');
  await page.setViewportSize({ width: 390, height: 800 });
  assert.equal(await fixed.evaluate((el) => getComputedStyle(el).position), 'static');
  assert.equal(await fixed.evaluate((el) => getComputedStyle(el).translate), 'none');
  assert.equal(await device.evaluate((el) => getComputedStyle(el).position), 'absolute');
  assert.equal(await column.evaluate((el) => getComputedStyle(el).position), 'static');
  close(await column.evaluate((el) => el.getBoundingClientRect().width), await column.evaluate((el) => {
    const row = el.parentElement, style = getComputedStyle(row);
    return row.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
  }), 'Flow columns fill the mobile row within its padding');

  if (process.argv.includes('--builder')) {
    await page.setViewportSize({ width: 1600, height: 1000 });
    await page.context().addCookies([{ name: 'cometcms_admin', value: 'position-browser-session', url: origin }]);
    await page.addInitScript(() => localStorage.setItem('cometcms-builder-guide-seen', '1'));
    await page.goto(origin + '/admin/page-builder/builder/layout/posts/detail');
    const canvas = page.frameLocator('.builder-stage iframe');
    for (const id of ['free-heading', 'floating-column']) {
      const heading = canvas.locator(`[data-node="${id}"]`);
      const selectionX = id === 'floating-column' ? (await heading.boundingBox()).width / 2 : 4;
      await heading.click({ position: { x: selectionX, y: 1 } });
      assert.equal(await canvas.locator('[data-builder-selected]').getAttribute('data-node'), id, 'Canvas selects the intended element or column');
      await page.getByRole('tab', { name: 'Style', exact: true }).click();
      const positionGroup = page.getByRole('button', { name: 'Position', exact: true });
      if (await positionGroup.getAttribute('aria-expanded') !== 'true') await positionGroup.click();
      if (id === 'floating-column') {
        await page.getByRole('radio', { name: 'Free', exact: true }).click();
        await canvas.locator('[data-node="floating-column"].c-pos-absolute').waitFor({ state: 'attached' });
      }
      await page.getByRole('radio', { name: 'Fixed', exact: true }).click();
      await heading.locator('xpath=self::*[contains(@class,"c-pos-fixed")]').waitFor();
      await page.getByRole('radiogroup', { name: 'Pinned to', exact: true }).waitFor();
      const frame = page.frames().find((frame) => frame.parentFrame());
      if (id === 'floating-column') {
        const rect = await heading.boundingBox();
        await page.mouse.move(rect.x + rect.width - 2, rect.y + rect.height / 2);
        const edge = canvas.locator('.cometcms-resize');
        await edge.waitFor({ state: 'visible' });
        const handle = await edge.boundingBox();
        const step = (await page.locator('.builder-stage iframe').boundingBox()).width / 12;
        await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2);
        await page.mouse.down();
        await page.mouse.move(handle.x + handle.width / 2 + step, handle.y + handle.height / 2, { steps: 8 });
        await Promise.all([
          page.waitForResponse((response) => response.url().includes('/api/site/preview') && response.request().method() === 'POST'),
          page.mouse.up(),
        ]);
        await canvas.locator('[data-node="floating-column"].c-d-col-4').waitFor();
        close(await heading.evaluate((el) => el.getBoundingClientRect().width), 1280 / 3, 'The resize grip changes the fixed column span');
      }
      await frame.evaluate(() => scrollTo(0, 300));
      await frame.waitForFunction(() => scrollY === 300);
      const grip = canvas.locator('.cometcms-move');
      const box = await grip.boundingBox();
      const initial = await heading.boundingBox();
      await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
      await page.mouse.down();
      await page.mouse.move(box.x + box.width / 2 - 80, box.y + box.height / 2 + 80, { steps: 8 });
      const previewed = page.waitForResponse((response) => response.url().includes('/api/site/preview') && response.request().method() === 'POST');
      await page.mouse.up();
      await previewed;
      const moved = await heading.boundingBox();
      close(moved.x, initial.x - 80, 'Dragging fixed horizontally at a scrolled position');
      close(moved.y, initial.y + 80, 'Dragging fixed vertically at a scrolled position');
      const gripY = (await grip.boundingBox()).y;
      await frame.evaluate(() => scrollTo(0, 500));
      await frame.waitForFunction(() => scrollY === 500);
      close((await heading.boundingBox()).y, moved.y, 'Fixed drag remains pinned after scrolling');
      close((await grip.boundingBox()).y, gripY, 'Move grip follows the fixed element while scrolling');
      await page.locator('.topbar-devices').getByRole('button', { name: 'Mobile', exact: true }).click();
      await page.getByRole('radio', { name: 'In the flow', exact: true }).click();
      await frame.waitForFunction((id) => getComputedStyle(document.querySelector(`[data-node="${id}"]`)).position === 'static', id);
      assert.equal(await grip.isVisible(), false, 'Flow overrides hide the move grip');
      assert.equal(await page.getByRole('radiogroup', { name: 'Pinned to', exact: true }).count(), 0, 'Flow overrides hide pin controls');
      await page.locator('.topbar-devices').getByRole('button', { name: 'Tablet', exact: true }).click();
      await frame.waitForFunction((id) => getComputedStyle(document.querySelector(`[data-node="${id}"]`)).position === (id === 'floating-column' ? 'absolute' : 'fixed'), id);
      await page.getByRole('radiogroup', { name: 'Pinned to', exact: true }).waitFor();
      await page.locator('.topbar-devices').getByRole('button', { name: 'Desktop', exact: true }).click();
      const [saved] = await Promise.all([
        page.waitForResponse((response) => response.url().includes('/api/site/layouts/posts/detail') && response.request().method() === 'PUT'),
        page.getByRole('button', { name: 'Save layout', exact: true }).click(),
      ]);
      assert.equal(saved.status(), 200);
      await page.reload();
      await canvas.locator(`[data-node="${id}"].c-pos-fixed`).waitFor();
    }
    await page.goto(origin + '/posts/positioning');
    assert.equal(await free.evaluate((el) => getComputedStyle(el).position), 'fixed', 'Saved fixed positioning renders on the published page');
    assert.equal(await column.evaluate((el) => getComputedStyle(el).position), 'fixed');
    close((await column.boundingBox()).width, 1600 / 3, 'Saved fixed column widths render on the published page');
    await page.setViewportSize({ width: 390, height: 800 });
    assert.equal(await free.evaluate((el) => getComputedStyle(el).position), 'static', 'Saved mobile overrides render on the published page');
    assert.equal(await column.evaluate((el) => getComputedStyle(el).position), 'static');
  }
  assert.deepEqual(errors, []);
  console.log('Positioning browser checks passed (element and column pins, widths, nested columns, offsets, scrolling, device overrides' + (process.argv.includes('--builder') ? ', editor selection, dragging, column resizing, save and reload' : '') + ').');
} finally {
  await browser?.close();
  if (php) await new Promise((resolve) => { php.once('exit', resolve); if (!php.kill('SIGTERM')) resolve(); });
  rmSync(directory, { recursive: true, force: true });
}
