// Run with: node tests/browser/design-settings.mjs (requires PHP and Chrome/Chromium).
// Mounts the real controls with shipped theme data; no workspace data is read or written.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from 'tailwindcss';
import loadConfig from 'tailwindcss/loadConfig.js';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const tailwindConfig = loadConfig(join(root, 'web/tailwind.config.js'));
const defaults = JSON.parse(execFileSync('php', ['-r', `require '${root}cms/app/Site/Shadow.php'; require '${root}cms/app/Site/Theme.php'; echo json_encode(CometCMS\\Site\\Theme::defaults());`], { encoding: 'utf8' }));
const paddingStyles = JSON.parse(execFileSync('php', ['-r', 'require $argv[1]; comet_test_reset_storage(); echo json_encode(array_map(fn($theme) => CometCMS\\Site\\Theme::stylesheet($theme), json_decode($argv[2], true)));',
  join(root, 'tests/php/bootstrap.php'), JSON.stringify([{}, { section_padding: 'lg', grid_padding: 'none', column_padding: 'sm' }, { base_size: 16, space: { md: 24 }, grid_padding: 'sm', column_padding: 'none' }, { base_size: 16, space: { md: 24 } }])], { encoding: 'utf8', maxBuffer: 5 * 1024 * 1024 }));
const entry = `
import { createApp, h, reactive } from 'vue';
import { createPinia } from 'pinia';
import DesignPanel from '${root}web/src/components/site/builder/SiteDesignPanel.vue';
import { useSiteStore } from '${root}web/src/stores/site.js';
import Motion from '${root}web/src/components/site/builder/ThemeMotionSettings.vue';
import Shadows from '${root}web/src/components/site/builder/ThemeShadowsSettings.vue';
import ShadowEditor from '${root}web/src/components/site/builder/ShadowEditor.vue';
import { setLocale } from '${root}web/src/i18n/index.js';
window.setDesignLocale = setLocale;
import '${root}web/src/style.css';
const theme = reactive(${JSON.stringify(defaults)});
const pinia = createPinia();
const site = useSiteStore(pinia);
site.data = { theme_defaults: ${JSON.stringify(defaults)}, theme_presets: [], fonts: ['system'], font_stacks: { system: 'system-ui' } };
site.draft = { theme };
window.designTheme = theme;
createApp({ setup: () => () => h('main', { class: 'grid items-start gap-6 p-4', style: 'grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),380px))' }, [
  h('section', { id: 'motion', class: 'card p-4' }, [h(Motion, { motion: theme.motion })]),
  h('section', { id: 'shadows', class: 'card p-4' }, [h(Shadows, { theme, colors: [{ value: 'primary', label: 'Primary', swatch: '#2563eb' }] })]),
  h('section', { id: 'element', class: 'card p-4' }, [h(ShadowEditor, { id: 'element-shadow', modelValue: 'md', scale: theme.shadow_scale, presets: theme.shadows, options: [] })]),
  h('section', { id: 'design', class: 'card' }, [h(DesignPanel, { showHeader: false })]),
]) }).use(pinia).mount('#app');
`;
let server, browser;
try {
  server = await createServer({
    configFile: false, root: join(root, 'web'), base: '/admin/', logLevel: 'error',
    css: { postcss: { plugins: [tailwindcss({ ...tailwindConfig, content: [join(root, 'web/src/**/*.{vue,js}'), { raw: entry, extension: 'js' }] })] } },
    plugins: [vue(), {
      name: 'design-settings-fixture',
      resolveId(id) { if (id === 'virtual:design-settings') return '\0virtual:design-settings'; },
      load(id) { if (id === '\0virtual:design-settings') return entry; },
      configureServer(server) {
        server.middlewares.use(async (req, res, next) => {
          if (!req.url?.endsWith('/design-settings')) return next();
          const html = await server.transformIndexHtml('/admin/design-settings', '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="app"></div><script type="module" src="/@id/__x00__virtual:design-settings"></script></body></html>');
          res.setHeader('Content-Type', 'text/html'); res.end(html);
        });
      },
    }], server: { host: '127.0.0.1', port: 0 },
  });
  await server.listen();
  const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
  browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(() => localStorage.setItem('cometcms-admin-locale', 'en'));
  await page.goto(`${origin}/admin/design-settings`);
  const design = page.locator('#design');
  await design.getByRole('button', { name: 'Sizes & spacing', exact: true }).click();
  for (const kind of ['section', 'grid', 'column']) {
    const picker = design.getByRole('combobox', { name: `Default ${kind} padding: M · ${defaults.space.md}px`, exact: true });
    await picker.click();
    const list = page.getByRole('listbox', { name: `Default ${kind} padding`, exact: true });
    assert.equal(await list.getByRole('option', { name: `M ${defaults.space.md} px`, exact: true }).getAttribute('aria-selected'), 'true');
    await list.getByRole('option', { name: { section: `L ${defaults.space.lg} px`, grid: '0 0 px', column: `S ${defaults.space.sm} px` }[kind], exact: true }).click();
    assert.equal(await picker.count(), 0);
  }
  assert.deepEqual(await page.evaluate(() => [window.designTheme.section_padding, window.designTheme.grid_padding, window.designTheme.column_padding]), ['lg', 'none', 'sm']);
  // Each smaller device can override or inherit its padding, including an explicit zero.
  await page.evaluate(() => { window.designTheme.section_padding_tablet = ''; });
  await design.getByRole('button', { name: 'Tablet', exact: true }).click();
  const tabletSection = design.getByRole('combobox', { name: /^Default section padding:/ });
  assert.equal(await tabletSection.textContent(), '=');
  await tabletSection.press('ArrowDown');
  await tabletSection.press('End');
  await tabletSection.press('Enter');
  assert.equal(await page.evaluate(() => window.designTheme.section_padding_tablet), '2xl');
  assert.equal(await tabletSection.evaluate(el => el === document.activeElement), true);
  await design.getByRole('button', { name: 'Mobile', exact: true }).click();
  const mobileSection = design.getByRole('combobox', { name: /^Default section padding:/ });
  await mobileSection.click();
  await page.getByRole('option', { name: '0 0 px', exact: true }).click();
  assert.equal(await page.evaluate(() => window.designTheme.section_padding_mobile), 'none');
  await mobileSection.click();
  await page.getByRole('option', { name: 'Same as tablet', exact: true }).click();
  assert.equal(await page.evaluate(() => window.designTheme.section_padding_mobile), '');
  await mobileSection.click();
  await mobileSection.press('Escape');
  assert.equal(await page.getByRole('listbox').count(), 0);
  await mobileSection.click();
  await design.getByRole('button', { name: 'Desktop', exact: true }).click();
  assert.equal(await page.getByRole('listbox').count(), 0);
  assert.equal(await page.evaluate(() => window.designTheme.section_padding), 'lg');
  const motion = page.locator('#motion');
  const shadows = page.locator('#shadows');
  await motion.getByRole('button', { name: /Normal/ }).waitFor();
  await motion.getByRole('button', { name: /Fast/ }).click();
  const duration = motion.getByRole('spinbutton', { name: 'Fast duration' });
  await duration.fill('8'); await duration.press('Tab');
  assert.equal(await page.evaluate(() => window.designTheme.motion.fast), 5000);
  await duration.fill('0'); await duration.press('Tab');
  assert.equal(await page.evaluate(() => window.designTheme.motion.fast), 100);
  await duration.fill('0.85'); await duration.press('Tab');
  assert.equal(await page.evaluate(() => window.designTheme.motion.fast), 850);
  await motion.getByRole('button', { name: /Slow/ }).click();
  assert.equal(await motion.getByRole('spinbutton', { name: 'Slow duration' }).inputValue(), '1.1');
  const stagger = motion.getByRole('spinbutton', { name: 'Delay per stagger step' });
  await stagger.fill('0.25'); await stagger.press('Tab');
  await motion.getByRole('button', { name: 'Preview', exact: true }).click();
  assert.equal(await motion.locator('.motion-sample').nth(2).evaluate(el => getComputedStyle(el).animationDelay), '0.5s');
  const hoverTime = motion.getByRole('spinbutton', { name: 'Transition duration' });
  await hoverTime.fill('0.65'); await hoverTime.press('Tab');
  const sample = motion.getByRole('button', { name: 'Hover or focus to try' });
  assert.equal(await sample.evaluate(el => getComputedStyle(el).transitionDuration), '0.65s');
  await page.mouse.move(0, 0); await sample.blur();
  const beforeHover = await sample.evaluate(el => getComputedStyle(el).backgroundColor);
  await sample.hover(); await page.waitForTimeout(750);
  assert.notEqual(await sample.evaluate(el => getComputedStyle(el).backgroundColor), beforeHover);
  await page.mouse.move(0, 0); await sample.focus();
  assert.equal(await sample.evaluate(el => el.matches(':focus-visible')), true);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  assert.equal(await sample.evaluate(el => getComputedStyle(el).transitionProperty), 'none');
  assert.equal(await motion.locator('.motion-sample').first().evaluate(el => getComputedStyle(el).animationName), 'none');
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  // M is selected initially; editing it updates both the theme and element preview.
  const y = shadows.getByRole('spinbutton', { name: 'Vertical px' }).first();
  await y.fill('17');
  assert.equal(await page.evaluate(() => window.designTheme.shadow_scale.md.y), 17);
  assert.match(await page.locator('#element span').first().evaluate(el => el.style.boxShadow), /17px/);
  await shadows.getByRole('button', { name: 'Reset size' }).click();
  assert.equal(await page.evaluate(() => window.designTheme.shadow_scale.md.y), 6);
  assert.deepEqual(await shadows.locator('button[aria-pressed]').allTextContents().then(labels => labels.map(label => label.trim())), ['S', 'M', 'L']);
  await shadows.getByRole('button', { name: 'Add shadow', exact: true }).click();
  const custom = shadows.locator('details[open]');
  await custom.getByRole('textbox', { name: 'Shadow name' }).fill('Raised card');
  await custom.getByRole('spinbutton', { name: 'Blur px' }).fill('32');
  assert.equal(await page.evaluate(() => Object.values(window.designTheme.shadows).find(s => s.label === 'Raised card').blur), 32);
  await custom.getByRole('button', { name: 'Remove Raised card' }).click();
  assert.equal(await page.evaluate(() => Object.values(window.designTheme.shadows).some(s => s.label === 'Raised card')), false);
  await shadows.getByRole('button', { name: 'M', exact: true }).click();
  for (const width of [1280, 360]) {
    await page.setViewportSize({ width, height: 1000 });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `No overflow at ${width}px`);
  }
  if (process.env.DESIGN_SCREENSHOT) {
    await page.setViewportSize({ width: 1280, height: 1000 });
    await page.mouse.move(1275, 995); await sample.blur();
    await page.waitForTimeout(200);
    await page.screenshot({ path: process.env.DESIGN_SCREENSHOT, fullPage: true, animations: 'disabled' });
    await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; });
    await page.waitForTimeout(200);
    await page.screenshot({ path: process.env.DESIGN_SCREENSHOT.replace('.png', '-dark.png'), fullPage: true, animations: 'disabled' });
  }
  await page.evaluate(() => { window.setDesignLocale('de'); document.documentElement.dataset.theme = 'dark'; });
  await design.getByRole('combobox', { name: /^Standard-Innenabstand für Abschnitte:/ }).waitFor();
  await design.getByRole('combobox', { name: /^Standard-Innenabstand für Raster:/ }).waitFor();
  await design.getByRole('combobox', { name: /^Standard-Innenabstand für Spalten:/ }).waitFor();
  await page.setViewportSize({ width: 360, height: 1000 });
  await motion.getByRole('button', { name: /Schnell/ }).click();
  await motion.getByRole('spinbutton', { name: 'Dauer: Schnell' }).waitFor();
  await shadows.getByRole('button', { name: 'Größe zurücksetzen' }).waitFor();
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'German sidebar has no overflow');
  await page.waitForTimeout(200);
  assert.equal(await shadows.getByRole('button', { name: 'L', exact: true }).evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(24, 28, 38)');
  if (process.env.DESIGN_SCREENSHOT) await page.screenshot({ path: process.env.DESIGN_SCREENSHOT.replace('.png', '-de-sidebar.png'), fullPage: true, animations: 'disabled' });
  assert.deepEqual(errors, []);
  // Verify the generated website CSS, including nested grids and explicit device overrides.
  const rendered = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  await rendered.setContent(`<style id="theme">${paddingStyles[0]}</style>
    <header class="c-region"><section id="region" class="c-section"></section></header>
    <section id="section" class="c-section c-pt-xl c-t-pt-xs c-m-pt-none">
      <div id="grid" class="c-row c-pl-none c-t-pr-lg c-m-pr-xs">
        <div id="column" class="c-col"><div id="nested" class="c-row"><div id="nested-column" class="c-col"></div></div></div>
        <div id="column-overrides" class="c-col c-pt-2xl c-pr-none c-t-pb-lg c-m-pb-none"></div>
      </div>
    </section>`);
  const padding = (id) => rendered.locator(`#${id}`).evaluate(el => {
    const css = getComputedStyle(el);
    return [css.paddingTop, css.paddingRight, css.paddingBottom, css.paddingLeft];
  });
  assert.deepEqual(await padding('region'), ['17px', '17px', '17px', '17px']);
  assert.deepEqual(await padding('section'), ['59.5px', '17px', '17px', '17px']);
  assert.deepEqual(await padding('grid'), ['17px', '17px', '17px', '0px']);
  assert.deepEqual(await padding('nested'), ['17px', '17px', '17px', '17px']);
  assert.deepEqual(await padding('column'), ['17px', '17px', '17px', '17px']);
  assert.deepEqual(await padding('nested-column'), ['17px', '17px', '17px', '17px']);
  assert.deepEqual(await padding('column-overrides'), ['102px', '0px', '17px', '17px']);
  await rendered.locator('#theme').evaluate((el, css) => { el.textContent = css; }, paddingStyles[1]);
  assert.deepEqual(await padding('section'), ['59.5px', '34px', '34px', '34px']);
  assert.deepEqual(await padding('grid'), ['0px', '0px', '0px', '0px']);
  assert.deepEqual(await padding('nested'), ['0px', '0px', '0px', '0px']);
  assert.deepEqual(await padding('column'), ['8.5px', '8.5px', '8.5px', '8.5px']);
  assert.deepEqual(await padding('nested-column'), ['8.5px', '8.5px', '8.5px', '8.5px']);
  assert.deepEqual(await padding('column-overrides'), ['102px', '0px', '8.5px', '8.5px']);
  for (const [width, top, right] of [[900, '4.25px', '34px'], [600, '0px', '4.25px']]) {
    await rendered.setViewportSize({ width, height: 800 });
    assert.equal((await padding('section'))[0], top);
    assert.equal((await padding('grid'))[1], right);
    assert.equal((await padding('column-overrides'))[2], width === 900 ? '34px' : '0px');
  }
  await rendered.setViewportSize({ width: 1280, height: 800 });
  await rendered.locator('#theme').evaluate((el, css) => { el.textContent = css; }, paddingStyles[2]);
  assert.deepEqual(await padding('region'), ['24px', '24px', '24px', '24px']);
  assert.deepEqual(await padding('nested'), ['8px', '8px', '8px', '8px']);
  assert.deepEqual(await padding('column'), ['0px', '0px', '0px', '0px']);
  assert.deepEqual(await padding('nested-column'), ['0px', '0px', '0px', '0px']);
  await rendered.locator('#theme').evaluate((el, css) => { el.textContent = css; }, paddingStyles[3]);
  assert.deepEqual(await padding('column'), ['24px', '24px', '24px', '24px']);
  assert.deepEqual(await padding('nested-column'), ['24px', '24px', '24px', '24px']);
  console.log('Design settings browser checks passed: timing, shadows, padding controls, global defaults, nested grids and columns, spacing scale, explicit and responsive overrides, narrow layout.');
} finally {
  await browser?.close(); await server?.close();
}
