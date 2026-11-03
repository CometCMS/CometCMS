// node tests/browser/fancy-bg-containers.mjs
// Renders the installed blocks and real theme CSS using isolated test storage.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
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
    $renderer = new NodeRenderer(SiteContext::make($site));
    $nodes = [];
    foreach (['geometry' => 'Geometric Forms', 'lights' => 'Soft Lights', 'space' => 'Deep Space', 'grid' => 'Futuristic Grid'] as $effect => $title) {
        $kind = 'block:cometcms/fancy-' . $effect;
        $variants = LayoutSchema::propSpec($kind, $site->blocks())['variant']['options'];
        foreach ($variants as $variant) {
            $nodes[] = LayoutSchema::node($kind, ['variant' => $variant], ['radius' => 'lg'], [
                LayoutSchema::node('heading', ['level' => 'h3', 'text' => $title]),
                LayoutSchema::node('text', ['html' => '<p>' . ucfirst($variant) . ' background. Your content stays in focus.</p>']),
                LayoutSchema::node('button', ['label' => 'Explore ' . $effect, 'link' => ['type' => 'url', 'url' => '#explore']]),
            ]);
        }
    }
    $nodes[] = LayoutSchema::node('block:cometcms/fancy-geometry', ['motion' => 'still', 'scale' => 'small', 'intensity' => 'subtle'], [], [
        LayoutSchema::node('block:cometcms/fancy-lights', ['motion' => 'lively', 'scale' => 'large', 'intensity' => 'vivid', 'height' => 'content'], [], [
            LayoutSchema::node('heading', ['text' => 'Nested content']),
        ]),
    ]);
    $nodes = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], $nodes)], 'column', $site->blocks())[0]['children'];
    $html = $renderer->render($nodes);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>';
    echo file_get_contents(COMET_APP . '/Site/assets/site.css') . Theme::stylesheet($site->theme()) . $renderer->blockStylesheet();
    echo 'body{margin:0;background:#070b16}main{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;max-width:1280px;margin:auto;padding:24px}@media(max-width:700px){main{grid-template-columns:minmax(0,1fr);padding:12px}}</style><body class="c-site"><main>' . $html . '</main></body></html>';
} finally {
    comet_test_remove_directory(COMET_STORAGE);
}
` });
assert.equal(rendered.status, 0, rendered.stderr);

const browser = await chromium.launch({
  executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome',
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
try {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, reducedMotion: 'no-preference' });
  await page.setContent(rendered.stdout);
  const check = async () => page.evaluate(() => {
    const css = (node) => getComputedStyle(node);
    const roots = [...document.querySelectorAll('.cometcms-fbg')];
    const effects = roots.map((node) => node.querySelector(':scope > .cometcms-fbg__effects'));
    return {
      count: roots.length,
      overflow: document.documentElement.scrollWidth > innerWidth,
      clippedContent: roots.some((node) => css(node).overflow !== 'visible'),
      pointerEvents: effects.every((node) => css(node).pointerEvents === 'none'),
      decorative: effects.every((node) => node.getAttribute('aria-hidden') === 'true'),
      active: effects.slice(0, 9).every((node) => node.getAnimations({ subtree: true }).length > 0),
      still: effects[9].getAnimations().length === 0 && [...effects[9].children].every((node) => css(node).animationName === 'none'),
      nested: css(roots[10]).getPropertyValue('--fbg-unit').trim() === '100px'
        && css(roots[10]).getPropertyValue('--fbg-period').trim() === '12s'
        && css(effects[10]).opacity === '0.85'
        && effects[10].getAnimations({ subtree: true }).length > 0,
    };
  });
  for (const width of [1280, 375]) {
    await page.setViewportSize({ width, height: 900 });
    const result = await check();
    assert.equal(result.count, 11);
    assert.equal(result.overflow, false, `${width}px: no horizontal overflow`);
    assert.equal(result.clippedContent, false, 'Content and focus outlines are not clipped');
    for (const name of ['pointerEvents', 'decorative', 'active', 'still', 'nested']) assert.equal(result[name], true, `${width}px: ${name}`);
  }
  const link = page.getByRole('link', { name: 'Explore geometry', exact: true }).first();
  await link.click();
  assert.equal(await page.evaluate(() => location.hash), '#explore', 'Decoration does not intercept clicks');
  await link.focus();
  assert.equal(await link.evaluate((node) => node === document.activeElement), true, 'Content retains keyboard focus');

  // Normal Style controls must win over extension defaults in the real cascade.
  assert.equal(await page.evaluate(() => {
    const wrapper = document.querySelector('.c-block-cometcms__fancy-geometry');
    wrapper.classList.add('c-bg-surface', 'c-fg-text');
    const reference = document.createElement('div');
    reference.style.cssText = 'background:var(--c-surface);color:var(--c-text)';
    document.body.append(reference);
    const a = getComputedStyle(wrapper), b = getComputedStyle(reference);
    const matches = a.backgroundColor === b.backgroundColor && a.color === b.color;
    wrapper.classList.remove('c-bg-surface', 'c-fg-text');
    reference.remove();
    return matches;
  }), true, 'Surface and text controls override defaults');

  await page.emulateMedia({ reducedMotion: 'reduce' });
  assert.equal(await page.locator('.cometcms-fbg__effects').evaluateAll((nodes) => nodes.every((node) => node.getAnimations({ subtree: true }).length === 0)), true, 'Reduced motion stops every effect');
  await page.emulateMedia({ reducedMotion: 'reduce', forcedColors: 'active' });
  assert.equal(await page.locator('.cometcms-fbg__effects').evaluateAll((nodes) => nodes.every((node) => getComputedStyle(node).display === 'none')), true, 'Forced colors hides decoration');
  if (process.env.COMET_BG_SCREENSHOT) {
    await page.emulateMedia({ reducedMotion: 'reduce', forcedColors: 'none' });
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.screenshot({ path: process.env.COMET_BG_SCREENSHOT, fullPage: true });
  }
  console.log('Fancy BG Containers browser checks passed (all variants, desktop/mobile, nested settings, content interaction, Style overrides, still/reduced motion, forced colors).');
} finally {
  await browser.close();
}
