// node tests/browser/os-download.mjs (optional COMET_TEST_BROWSER executable path)
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const source = join(root, 'examples/extensions/comet-essentials');
const directory = mkdtempSync(join(tmpdir(), 'comet-os-download-'));
let browser;
try {
  const props = join(directory, 'props.json');
  writeFileSync(props, JSON.stringify({ downloads: ['windows', 'macos', 'linux'].map((os) => ({
    os, label: `Download for ${os}`, caption: `${os} installer`, link: `https://example.test/${os}`, accent: 'primary',
  })) }));
  const { html } = JSON.parse(execFileSync('php', [join(root, 'scripts/extension.php'), 'render', source, 'os-download', props, '--json'], { encoding: 'utf8' }));
  const css = readFileSync(join(source, 'blocks/os-download/style.css'), 'utf8');
  const js = readFileSync(join(source, 'blocks/os-download/view.js'), 'utf8');
  browser = await chromium.launch({ executablePath: process.env.COMET_TEST_BROWSER || undefined });
  const page = await browser.newPage({ viewport: { width: 320, height: 700 } });
  await page.setContent(`<style>:root { --c-primary: #2563eb; --c-on-primary: #fff; } ${css}</style>${html}`);
  assert.equal(await page.locator('.cometcms-download__button:visible').count(), 3, 'All links work before scripts run');
  assert.equal(await page.locator('[data-recommended]:visible').count(), 0);
  assert.equal(await page.locator('.cometcms-download__empty').isVisible(), false);
  await page.evaluate(async (js) => {
    Object.defineProperty(navigator, 'platform', { configurable: true, value: 'Win32' });
    Object.defineProperty(navigator, 'userAgent', { configurable: true, value: 'Windows NT 10.0' });
    const { default: mount } = await import(`data:text/javascript;base64,${btoa(js)}`);
    window.downloadMount = mount;
    window.downloadCleanup = mount(document.querySelector('.c-el'), { display: 'all' });
  }, js);
  assert.equal(await page.locator('[data-matched] .cometcms-download__button').textContent(), 'Download for windows');
  assert.equal(await page.locator('[data-recommended]:visible').count(), 1);
  assert.equal(await page.locator('.cometcms-download__button:visible').count(), 3);
  assert.equal(await page.locator('[data-matched] a').evaluate((el) => getComputedStyle(el).backgroundColor), 'rgb(37, 99, 235)');
  await page.evaluate(() => {
    window.downloadCleanup();
    window.downloadCleanup = window.downloadMount(document.querySelector('.c-el'), { display: 'matched' });
  });
  assert.equal(await page.locator('.cometcms-download__button:visible').count(), 1);
  await page.keyboard.press('Tab');
  assert.equal(await page.locator('a:focus').textContent(), 'Download for windows');
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'Buttons fit a narrow viewport');
  await page.evaluate(() => {
    window.downloadCleanup();
    Object.defineProperty(navigator, 'platform', { configurable: true, value: 'Unknown' });
    Object.defineProperty(navigator, 'userAgent', { configurable: true, value: 'Unknown' });
    window.downloadMount(document.querySelector('.c-el'), { display: 'matched' });
  });
  assert.equal(await page.locator('.cometcms-download__button:visible').count(), 3, 'Unknown OS keeps alternatives visible');
  await page.setContent(`<style>${css}</style><div class="c-block-cometcms__os-download"><div class="cometcms-download__items"></div><p class="cometcms-download__empty">Add links</p></div>`);
  assert.equal(await page.locator('.cometcms-download__empty').isVisible(), true);
  console.log('OS Download browser checks passed.');
} finally {
  await browser?.close();
  rmSync(directory, { recursive: true, force: true });
}
