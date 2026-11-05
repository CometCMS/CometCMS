// node tests/browser/section-backgrounds.mjs
// Renders sections with extension backgrounds (Fancy Backgrounds and Water Ripple) and the
// real site CSS in isolated test storage, then checks them in Chrome: the layer covers its
// section behind rows and columns, leaves content clickable, and the ripple script draws,
// reacts to the pointer, pauses for reduced motion and cleans up after itself.
// Set COMET_BG_SCREENSHOT=/path/to/file.png to keep a screenshot.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const rendered = spawnSync('php', [], { encoding: 'utf8', input: `<?php
require ${JSON.stringify(root + 'tests/php/bootstrap.php')};
use CometCMS\\Extensions\\Extension;
use CometCMS\\Site\\LayoutSchema;
use CometCMS\\Site\\NodeRenderer;
use CometCMS\\Site\\SiteContext;
use CometCMS\\Site\\SiteRepository;
use CometCMS\\Site\\Theme;
comet_test_reset_storage();
try {
    $site = new SiteRepository();
    $site->blocks()->installFiles(Extension::readDirectory(${JSON.stringify(root + 'examples/extensions/comet-fancy-bg-containers')}));
    $site->blocks()->installFiles(Extension::readDirectory(${JSON.stringify(root + 'examples/extensions/comet-water-ripple')}), allowScripts: true);
    $blocks = $site->blocks();
    $renderer = new NodeRenderer(SiteContext::make($site));
    $columns = static fn(string $title, string $slug): array => [LayoutSchema::node('row', [], [], [
        LayoutSchema::node('column', ['span' => ['mobile' => 12, 'tablet' => 6, 'desktop' => 0]], [], [
            LayoutSchema::node('heading', ['level' => 'h2', 'text' => $title]),
            LayoutSchema::node('button', ['label' => 'Explore ' . $slug, 'link' => ['type' => 'url', 'url' => '#' . $slug]]),
        ]),
        LayoutSchema::node('column', ['span' => ['mobile' => 12, 'tablet' => 6, 'desktop' => 0]], [], [
            LayoutSchema::node('text', ['html' => '<p>A second column on the same background.</p>']),
        ]),
    ])];
    $sections = [];
    foreach ($blocks->backgroundKinds() as $kind) {
        $block = $blocks->get($kind);
        $variants = $block['props']['variant']['options'] ?? [''];
        foreach ($variants as $variant) {
            $slug = $block['name'] === 'cometcms/water-ripple' ? 'ripple' : explode('/', $block['name'])[1] . ($variant !== '' ? '-' . $variant : '');
            $props = $variant !== '' ? ['variant' => $variant] : ['drift' => 0];
            $sections[] = LayoutSchema::node('section', $block['section']['props'], $block['section']['style'] + ['background_fx' => ['kind' => $kind, 'props' => $props]], $columns($block['title'], $slug));
        }
    }
    $nodes = LayoutSchema::normalize($sections, blocks: $blocks);
    $html = $renderer->render($nodes);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>';
    echo file_get_contents(COMET_APP . '/Site/assets/site.css') . Theme::stylesheet($site->theme()) . $renderer->blockStylesheet();
    echo 'body{margin:0}main{display:grid;gap:24px;max-width:1280px;margin:auto;padding:24px}</style><body class="c-site"><main>' . $html . '</main></body></html>';
} finally {
    comet_test_remove_directory(COMET_STORAGE);
}
` });
assert.equal(rendered.status, 0, rendered.stderr);
const ripple = readFileSync(new URL('../../examples/extensions/comet-water-ripple/backgrounds/water-ripple/view.js', import.meta.url), 'utf8');

const browser = await chromium.launch({
  executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome',
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
try {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, reducedMotion: 'no-preference' });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.setContent(rendered.stdout);
  // The site loads view.js from the asset route; here the same module mounts from a data URL.
  await page.evaluate(async (source) => {
    const module = await import(`data:text/javascript;base64,${btoa(unescape(encodeURIComponent(source)))}`);
    window.rippleMounts = [...document.querySelectorAll('[data-comet-block="cometcms/water-ripple"]')]
      .map((layer) => module.default(layer, JSON.parse(layer.dataset.cometProps), { preview: false, reducedMotion: false }));
  }, ripple);

  const layout = async () => page.evaluate(() => {
    const sections = [...document.querySelectorAll('section.c-section')];
    return sections.map((section) => {
      const layer = section.querySelector(':scope > .c-background-fx');
      const box = section.getBoundingClientRect();
      const fill = layer?.getBoundingClientRect();
      const columns = [...section.querySelectorAll('.c-col')].map((column) => column.getBoundingClientRect());
      const heading = section.querySelector('h2');
      const point = heading.getBoundingClientRect();
      heading.scrollIntoView({ block: 'center' });
      const at = heading.getBoundingClientRect();
      const hit = document.elementFromPoint(at.left + 4, at.top + at.height / 2);
      return {
        name: heading.textContent,
        layerFirst: section.firstElementChild === layer,
        covers: !!fill && Math.abs(fill.width - box.width) < 1 && Math.abs(fill.height - box.height) < 1 && Math.abs(fill.top - box.top) < 1,
        behind: Number(getComputedStyle(layer).zIndex) < 0 && getComputedStyle(layer).pointerEvents === 'none' && layer.getAttribute('aria-hidden') === 'true',
        clipsOnlyLayer: getComputedStyle(section).overflow === 'visible',
        reachable: heading.contains(hit),
        columns: columns.length,
        sideBySide: columns.length === 2 && Math.abs(columns[0].top - columns[1].top) < 1 && columns[1].left > columns[0].right - 1,
        surface: getComputedStyle(section).backgroundColor !== 'rgba(0, 0, 0, 0)',
        tall: point.height > 0 && box.height >= innerHeight * 0.5 - 1,
      };
    });
  });

  for (const width of [1280, 375]) {
    await page.setViewportSize({ width, height: 900 });
    const sections = await layout();
    assert.equal(sections.length, 10, 'Nine fancy variants and the water ripple');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${width}px: no horizontal overflow`);
    for (const section of sections) {
      for (const check of ['layerFirst', 'covers', 'behind', 'clipsOnlyLayer', 'reachable', 'surface', 'tall']) assert.equal(section[check], true, `${width}px ${section.name}: ${check}`);
      assert.equal(section.columns, 2, `${section.name}: rows and columns sit on the background`);
      assert.equal(section.sideBySide, width > 767, `${width}px ${section.name}: columns side by side only on wider screens`);
    }
  }
  await page.setViewportSize({ width: 1280, height: 900 });
  assert.equal(await page.locator('.c-background-fx .cometcms-fbg__effects').evaluateAll((nodes) => nodes.every((node) => node.getAnimations({ subtree: true }).length > 0)), true, 'Fancy backgrounds animate');

  const link = page.getByRole('link', { name: 'Explore ripple', exact: true });
  await link.scrollIntoViewIfNeeded();
  await link.click();
  assert.equal(await page.evaluate(() => location.hash), '#ripple', 'The background does not intercept clicks');

  // The ripple draws a lattice, then reacts to the pointer and settles again.
  const canvas = page.locator('.c-block-cometcms__water-ripple canvas.cometcms-ripple__canvas');
  assert.equal(await canvas.count(), 1);
  const pixels = () => canvas.evaluate((node) => {
    const data = node.getContext('2d').getImageData(0, 0, node.width, node.height).data;
    let painted = 0, sum = 0;
    for (let i = 3; i < data.length; i += 4) if (data[i]) { painted++; sum += data[i] * (i % 9973); }
    return { painted, sum, size: [node.width, node.height] };
  });
  const rest = await pixels();
  const box = await canvas.boundingBox();
  assert.ok(rest.painted > 1000, 'The lattice is drawn');
  assert.ok(Math.abs(rest.size[0] - Math.round(box.width * (await page.evaluate(() => Math.min(devicePixelRatio, 2))))) <= 1, 'The canvas matches its layer');
  await page.mouse.move(box.x + box.width * 0.2, box.y + box.height * 0.3);
  for (let step = 1; step <= 12; step++) await page.mouse.move(box.x + box.width * (0.2 + step * 0.04), box.y + box.height * 0.35, { steps: 2 });
  await page.waitForTimeout(120);
  const moving = await pixels();
  assert.notEqual(moving.sum, rest.sum, 'Pointer strokes ripple the lattice');
  await page.mouse.move(0, 0);
  await page.waitForTimeout(7000);
  assert.equal((await pixels()).sum, rest.sum, 'A settled lattice returns to its resting frame');

  // Reduced motion holds the lattice still under the pointer.
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.mouse.move(box.x + box.width * 0.5, box.y + box.height * 0.5);
  for (let step = 1; step <= 8; step++) await page.mouse.move(box.x + box.width * (0.5 + step * 0.03), box.y + box.height * 0.5, { steps: 2 });
  await page.waitForTimeout(120);
  assert.equal((await pixels()).sum, rest.sum, 'Reduced motion ignores the pointer');
  await page.emulateMedia({ reducedMotion: 'reduce', forcedColors: 'active' });
  assert.equal(await page.locator('.c-background-fx').evaluateAll((nodes) => nodes.every((node) => getComputedStyle(node).display === 'none')), true, 'Forced colors hide every background');
  await page.emulateMedia({ reducedMotion: 'no-preference', forcedColors: 'none' });

  if (process.env.COMET_BG_SCREENSHOT) {
    await page.evaluate(() => scrollTo(0, 0));
    await page.screenshot({ path: process.env.COMET_BG_SCREENSHOT, fullPage: true });
  }
  await page.evaluate(() => window.rippleMounts.forEach((cleanup) => cleanup()));
  assert.equal(await canvas.count(), 0, 'Unmounting removes the canvas');
  assert.deepEqual(errors, []);
  console.log('Section background browser checks passed (all fancy variants and the water ripple, desktop/mobile, rows and columns, content interaction, pointer ripples, settling, reduced motion, forced colors, cleanup).');
} finally {
  await browser.close();
}
