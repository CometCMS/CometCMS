// node tests/browser/mobility-infographic.mjs
// Installs only into temporary storage, then uses the real CMS renderer/runtime.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url)).replaceAll('\\', '/');
const rendered = spawnSync('php', [], { encoding: 'utf8', input: `<?php
require ${JSON.stringify(root + 'tests/php/bootstrap.php')};
use CometCMS\\Extensions\\Extension;
use CometCMS\\Site\\Blocks\\BlockScripts;
use CometCMS\\Site\\LayoutSchema;
use CometCMS\\Site\\NodeRenderer;
use CometCMS\\Site\\SiteContext;
use CometCMS\\Site\\SiteRepository;
use CometCMS\\Site\\Theme;
comet_test_reset_storage();
try {
    $files = Extension::readDirectory(${JSON.stringify(root + 'examples/extensions/comet-mobility')});
    $site = new SiteRepository();
    $site->blocks()->installFiles($files, allowScripts: true);
    $context = SiteContext::make($site);
    $renderer = new NodeRenderer($context);
    $kind = 'block:verkehrsinitiative/mobility-infographic';
    $nodes = [LayoutSchema::node($kind), LayoutSchema::node($kind, ['initial_mode' => 'car', 'car_text' => 'Second independent instance.'])];
    $nodes = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], $nodes)], 'column', $site->blocks())[0]['children'];
    $html = '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>';
    $markup = $renderer->render($nodes);
    $html .= file_get_contents(COMET_APP . '/Site/assets/site.css') . Theme::stylesheet($site->theme()) . $renderer->blockStylesheet();
    $html .= 'body{margin:0;background:#faf9f6;font-family:Arial,sans-serif}main{width:100%;max-width:840px;margin:24px auto;padding:0 16px;display:grid;gap:32px}.c-block-verkehrsinitiative__mobility-infographic{color:#172033}</style><body class="c-site"><main>' . $markup . '</main><button id="outside">Outside</button>';
    $html .= implode('', BlockScripts::elements($renderer->blockScripts(), $site->blocks(), $context)) . '</body></html>';
    $assets = [];
    foreach ($files as $path => $bytes) {
        if (str_starts_with($path, 'assets/') || str_ends_with($path, '/view.js')) {
            $assets[hash('sha256', $bytes) . '.' . pathinfo($path, PATHINFO_EXTENSION)] = base64_encode($bytes);
        }
    }
    echo json_encode(['html' => $html, 'assets' => $assets], JSON_THROW_ON_ERROR);
} finally {
    comet_test_remove_directory(COMET_STORAGE);
}
` });
assert.equal(rendered.status, 0, rendered.stderr || rendered.stdout);
const fixture = JSON.parse(rendered.stdout);
const executablePath = process.env.CHROME_BIN || [
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  '/usr/bin/google-chrome', '/usr/bin/chromium',
].find(existsSync);
const browser = await chromium.launch({ executablePath, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const errors = [];
try {
  const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  await context.route('https://comet.test/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const file = pathname.split('/').pop();
    if (fixture.assets[file]) {
      await route.fulfill({ body: Buffer.from(fixture.assets[file], 'base64'), contentType: file.endsWith('.js') ? 'text/javascript' : 'image/svg+xml' });
    } else if (pathname === '/') {
      await route.fulfill({ body: fixture.html, contentType: 'text/html; charset=utf-8' });
    } else {
      errors.push(`Unexpected network request: ${route.request().url()}`);
      await route.abort();
    }
  });
  const page = await context.newPage();
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('https://comet.test/');
  await page.waitForSelector('.vi-mobility--interactive');
  const first = page.locator('.vi-mobility').nth(0);
  const second = page.locator('.vi-mobility').nth(1);
  const mode = (name) => first.locator(`[data-mode="${name}"]`);
  const panel = (name) => first.locator(`[data-panel="${name}"]`);
  const visiblePanels = (instance) => instance.locator('[data-panel]:visible').count();
  assert.equal(await visiblePanels(first), 0, 'No initial selection');
  assert.equal(await second.locator('[data-panel="car"]').isVisible(), true, 'Configurable initial selection');
  assert.equal(await second.locator('[data-panel="car"]').textContent().then((text) => text.includes('Second independent instance.')), true, 'Edited text renders');
  assert.equal(await page.locator('.vi-mobility__art').evaluateAll((nodes) => nodes.every((node) => node.complete && node.naturalWidth > 0)), true, 'Bundled artwork loads through hashed asset URLs');
  const ids = await page.locator('[id]').evaluateAll((nodes) => nodes.map((node) => node.id));
  assert.equal(new Set(ids).size, ids.length, 'Multiple instances use unique relationships');
  if (process.env.COMET_MOBILITY_SCREENSHOT) {
    await first.screenshot({ path: process.env.COMET_MOBILITY_SCREENSHOT });
  }

  for (const width of [1280, 768, 375, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${width}px: no horizontal overflow`);
    for (const name of ['bicycle', 'bus', 'train', 'walking', 'truck', 'car']) {
      const button = mode(name);
      await button.click();
      assert.equal(await button.getAttribute('aria-pressed'), 'true', `${width}px: ${name} selects`);
      assert.equal(await panel(name).isVisible(), true);
      assert.equal(await visiblePanels(first), 1);
      assert.equal(await first.locator('[data-details]').evaluate((node) => {
        const bounds = node.getBoundingClientRect();
        return bounds.left >= 11 && bounds.right <= innerWidth - 11 && bounds.top >= 11 && bounds.bottom <= innerHeight - 11;
      }), true, `${width}px: popover stays inside viewport`);
      await page.mouse.move(0, 0);
      assert.equal(await panel(name).isVisible(), true, 'Click pins selection');
      const controlled = await button.getAttribute('aria-controls');
      assert.equal(await panel(name).getAttribute('id'), controlled);
      await button.click();
      assert.equal(await visiblePanels(first), 0, `${width}px: ${name}: click again closes; visible=${await first.locator('[data-panel]:visible').evaluateAll((nodes) => nodes.map((node) => node.dataset.panel).join(','))}`);
    }
  }
  assert.equal(await second.locator('[data-panel="car"]').isVisible(), false, 'Outside click dismisses another instance without selecting it');

  await page.setViewportSize({ width: 1280, height: 1000 });
  const originalHeight = (await first.boundingBox()).height;
  await mode('bicycle').hover();
  assert.equal(await panel('bicycle').isVisible(), true, 'Hover previews');
  assert.equal((await first.boundingBox()).height, originalHeight, 'Popover does not add content beneath the graphic');
  const target = await mode('bicycle').boundingBox();
  const pointer = { x: target.x + target.width / 2, y: target.y + target.height / 2 };
  let popup = await first.locator('[data-details]').boundingBox();
  assert.ok(Math.abs(popup.x - pointer.x - 16) < 2 && Math.abs(popup.y - pointer.y - 16) < 2, 'Hover popover appears beneath the mouse');
  await page.mouse.move(pointer.x + 10, pointer.y + 8);
  popup = await first.locator('[data-details]').boundingBox();
  assert.ok(Math.abs(popup.x - pointer.x - 26) < 2 && Math.abs(popup.y - pointer.y - 24) < 2, 'Unpinned popover follows pointer movement');
  if (process.env.COMET_MOBILITY_SCREENSHOT) {
    await page.screenshot({ path: process.env.COMET_MOBILITY_SCREENSHOT.replace(/\.png$/, '-popover.png') });
  }
  await panel('bicycle').hover();
  assert.equal(await panel('bicycle').isVisible(), true, 'Hover card stays readable');
  await page.mouse.move(0, 0);
  await panel('bicycle').waitFor({ state: 'hidden' });
  assert.equal(await visiblePanels(first), 0, 'Leaving widget dismisses hover');
  await mode('bus').focus();
  assert.equal(await panel('bus').isVisible(), true, 'Keyboard focus previews');
  await page.keyboard.press('Enter');
  assert.equal(await mode('bus').getAttribute('aria-pressed'), 'true');
  await page.keyboard.press('Escape');
  assert.equal(await visiblePanels(first), 0, 'Escape dismisses');
  await page.keyboard.press('ArrowRight');
  assert.equal(await mode('train').evaluate((node) => node === document.activeElement), true, 'Arrow keys move focus');
  assert.equal(await panel('train').isVisible(), true, 'Keyboard preview works after Escape');
  await page.keyboard.press('Space');
  assert.equal(await panel('train').isVisible(), true, 'Space selects');
  await first.locator('[data-close]').click();
  assert.equal(await visiblePanels(first), 0, 'Close dismisses');
  assert.equal(await mode('train').evaluate((node) => node === document.activeElement), true, 'Close restores focus');
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('Tab');
  assert.equal(await first.locator('[data-close]').evaluate((node) => node === document.activeElement), true, 'Keyboard can reach hover-card close control');
  await page.keyboard.press('Escape');
  assert.equal(await mode('car').evaluate((node) => node === document.activeElement), true, 'Escape from close restores mode focus');
  await mode('walking').click();
  await page.locator('#outside').click();
  assert.equal(await visiblePanels(first), 0, 'Outside click dismisses');
  await mode('bicycle').hover();
  assert.equal(await panel('bicycle').isVisible(), true, 'Hover works immediately after outside dismissal');
  await page.mouse.move(0, 0);

  await page.evaluate(() => globalThis.cometBlocks.unmount());
  assert.equal(await first.locator('[data-controls]').isHidden(), true, 'Cleanup removes controls');
  assert.equal(await visiblePanels(first), 6, 'Cleanup restores readable fallback');
  await page.evaluate(() => globalThis.cometBlocks.mount());
  assert.equal(await visiblePanels(first), 0, 'Preview remount resets initial state');
  await mode('car').click();
  assert.equal(await panel('car').isVisible(), true, 'Remount does not duplicate listeners');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  assert.equal(await mode('car').evaluate((node) => getComputedStyle(node).transitionDuration), '0s');

  const touchContext = await browser.newContext({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });
  await touchContext.route('https://comet.test/**', async (route) => {
    const file = new URL(route.request().url()).pathname.split('/').pop();
    await route.fulfill(fixture.assets[file]
      ? { body: Buffer.from(fixture.assets[file], 'base64'), contentType: file.endsWith('.js') ? 'text/javascript' : 'image/svg+xml' }
      : { body: fixture.html, contentType: 'text/html; charset=utf-8' });
  });
  const touch = await touchContext.newPage();
  touch.on('pageerror', (error) => errors.push(error.message));
  await touch.goto('https://comet.test/');
  await touch.waitForSelector('.vi-mobility--interactive');
  const touchFirst = touch.locator('.vi-mobility').first();
  await touchFirst.locator('[data-mode="car"]').tap();
  assert.equal(await touchFirst.locator('[data-panel="car"]').isVisible(), true, 'Touch selects');
  if (process.env.COMET_MOBILITY_SCREENSHOT) {
    await touchFirst.screenshot({ path: process.env.COMET_MOBILITY_SCREENSHOT.replace(/\.png$/, '-mobile.png') });
  }
  await touchFirst.locator('[data-mode="car"]').tap();
  assert.equal(await visiblePanels(touchFirst), 0, 'Touch toggles off');
  await touchContext.close();

  const fallbackContext = await browser.newContext({ javaScriptEnabled: false });
  await fallbackContext.route('https://comet.test/**', (route) => {
    const file = new URL(route.request().url()).pathname.split('/').pop();
    return route.fulfill(fixture.assets[file]
      ? { body: Buffer.from(fixture.assets[file], 'base64'), contentType: 'image/svg+xml' }
      : { body: fixture.html, contentType: 'text/html; charset=utf-8' });
  });
  const fallback = await fallbackContext.newPage();
  await fallback.goto('https://comet.test/');
  assert.equal(await fallback.locator('[data-panel]:visible').count(), 12, 'No-JavaScript fallback shows all explanations');
  assert.equal(await fallback.locator('[data-controls]:visible').count(), 0, 'No dead controls without JavaScript');
  assert.deepEqual(errors, []);
  console.log('Mobility infographic checks passed: real CMS rendering/runtime, artwork, six modes, desktop/mobile, keyboard, touch, multiple instances, cleanup/remount, reduced motion and no-JavaScript fallback.');
} finally {
  await browser.close();
}
