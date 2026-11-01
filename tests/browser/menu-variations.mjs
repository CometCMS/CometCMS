// node tests/browser/menu-variations.mjs
// Render installed blocks with real site CSS; no running CMS or workspace changes.
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
    $installed = $site->blocks()->installFiles(Extension::readDirectory(${JSON.stringify(root + 'examples/extensions/comet-menu-variations')}));
    $item = static fn(string $id, string $label, array $children = [], bool $link = true): array => [
        'id' => $id, 'label' => $label, 'link' => $link ? ['type' => 'url', 'url' => '#' . $id] : ['type' => 'none'], 'children' => $children,
    ];
    $site->saveMenus([['name' => 'main', 'label' => 'Explore the site', 'items' => [
        $item('start', 'Start here') + ['icon' => 'mdi:star-outline'],
        $item('work', 'Selected work', [$item('websites', 'Websites', [$item('comet', 'CometCMS')])]),
        $item('studio', 'Inside the studio', [$item('team', 'Meet the team')], false),
        $item('contact', 'Let’s make something together — contact our studio'),
    ]]]);
    $renderer = new NodeRenderer(SiteContext::make($site));
    $html = '';
    foreach ($installed['blocks'] as $name) {
        $node = LayoutSchema::node('block:' . $name, ['accent' => 'accent']);
        $html .= '<section><h2>' . $site->blocks()->get('block:' . $name)['title'] . '</h2>' . $renderer->render([$node]) . '</section>';
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>';
    echo file_get_contents(COMET_APP . '/Site/assets/site.css') . Theme::stylesheet($site->theme()) . $renderer->blockStylesheet();
    echo 'body{margin:0}main{max-width:1100px;padding:24px;margin:auto}section{margin-bottom:48px}h2{font-size:14px;margin-bottom:18px}</style><body class="c-site"><main>' . $html . '</main></body></html>';
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
  const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
  await page.setContent(rendered.stdout);
  assert.equal(await page.getByRole('navigation', { name: 'Explore the site' }).count(), 6);
  for (const width of [1280, 768, 375, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    // All parents and their grandchildren must remain in flow, including on narrow screens.
    await page.locator('details').evaluateAll((nodes) => nodes.forEach((node) => { node.open = true; }));
    const result = await page.evaluate(() => ({
      overflow: document.documentElement.scrollWidth > innerWidth,
      columns: getComputedStyle(document.querySelector('.cometcms-mv--cards .c-menu-list')).gridTemplateColumns.split(' ').length,
      linksVisible: [...document.querySelectorAll('nav a')].every((node) => node.getBoundingClientRect().width > 0),
      inlineSubmenus: [...document.querySelectorAll('.c-submenu')].every((node) => getComputedStyle(node).position !== 'absolute'),
    }));
    assert.equal(result.overflow, false, `${width}px: long labels and open trees fit the viewport`);
    assert.equal(result.columns, width <= 480 ? 1 : width <= 760 ? 2 : 3);
    assert.equal(result.linksVisible, true);
    assert.equal(result.inlineSubmenus, true);
  }

  await page.setViewportSize({ width: 1280, height: 1000 });
  await page.locator('details').evaluateAll((nodes) => nodes.forEach((node) => { node.open = false; }));
  for (const nav of await page.getByRole('navigation').all()) {
    const workToggle = nav.locator(':scope > ul > li > details > summary[aria-label="Selected work"]');
    await workToggle.focus();
    await page.keyboard.press('Enter');
    assert.equal(await workToggle.evaluate((node) => node.parentElement.open), true, 'Keyboard opens a linked parent’s submenu');
    await nav.getByRole('link', { name: 'Websites', exact: true }).focus();
    assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Websites');
    await nav.getByText('Inside the studio', { exact: true }).click();
    assert.equal(await nav.getByRole('link', { name: 'Meet the team' }).isVisible(), true, 'A group without a destination opens its submenu');
  }
  // Opening the same menu in another element must not close another element's disclosure.
  assert.equal(await page.locator('nav > ul > li > details[open]').count(), 6);
  const start = page.getByRole('link', { name: 'Start here', exact: true }).first();
  await start.click();
  assert.equal(await page.evaluate(() => location.hash), '#start');
  assert.equal(await page.locator('nav .c-menu-icon').count(), 6);
  await start.hover();
  await start.evaluate((node) => Promise.all(node.getAnimations().map((animation) => animation.finished)));
  assert.equal(await start.evaluate((node) => {
    const reference = document.createElement('span');
    reference.style.color = 'var(--c-accent)';
    node.after(reference);
    const matches = getComputedStyle(node).color === getComputedStyle(reference).color;
    reference.remove();
    return matches;
  }), true, 'Accent properties follow the active theme');

  await page.emulateMedia({ reducedMotion: 'reduce' });
  assert.equal(await page.locator('.cometcms-mv a').evaluateAll((nodes) => nodes.every((node) => getComputedStyle(node).transitionDuration === '0s')), true);
  if (process.env.COMET_MENU_SCREENSHOT) {
    await page.screenshot({ path: process.env.COMET_MENU_SCREENSHOT, fullPage: true });
  }
  console.log('Menu Variations browser checks passed (desktop/mobile, long labels, nested menus, independent instances, keyboard, links, theme accents, reduced motion).');
} finally {
  await browser.close();
}
