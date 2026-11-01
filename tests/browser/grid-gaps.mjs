// Run with: node tests/browser/grid-gaps.mjs (requires PHP and Chrome/Chromium).
// Uses the shared website CSS and the real builder canvas; no workspace data is touched.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const base = readFileSync(join(root, 'cms/app/Site/assets/site.css'), 'utf8');
const variants = execFileSync('php', ['-r', 'require $argv[1]; echo \\CometCMS\\Site\\StyleVariants::css(file_get_contents($argv[2]));',
  join(root, 'cms/app/Site/StyleVariants.php'), join(root, 'cms/app/Site/assets/site.css')], { encoding: 'utf8' });
const css = `${base}\n${variants}
  :root { --space-xs:4px; --space-sm:8px; --space-md:17px; --space-lg:34px; --space-xl:60px; --grid-padding:16px; --column-padding:0px; }
  body { display:block; } .c-col { min-height:40px; }`;
const column = (span, extra = '') => `<div class="c-col c-col-${span} ${extra}"></div>`;
const close = (actual, expected, message) => assert(Math.abs(actual - expected) < .15, `${message}: ${actual} vs ${expected}`);
let server, browser;
try {
  browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  const cases = [];
  for (const justify of ['start', 'center', 'end', 'between']) {
    for (const gap of ['none', 'sm', 'md', 'xl']) {
      for (const width of [240, 320, 484, 700, 1200]) {
        for (const spans of [[12], [6, 6], [4, 8], [3, 3, 3, 3]]) {
          cases.push(`<div class="c-row c-justify-${justify} c-gap-${gap}" style="width:${width}px">${spans.map((span) => column(span)).join('')}</div>`);
        }
      }
    }
  }
  await page.setContent(`<style>${css}</style>${cases.join('')}`);
  const overflows = await page.locator('.c-row').evaluateAll((rows) => rows.flatMap((row) => {
    const bounds = row.getBoundingClientRect(), style = getComputedStyle(row);
    const left = bounds.left + parseFloat(style.paddingLeft), right = bounds.right - parseFloat(style.paddingRight);
    return [...row.children].filter((col) => col.getBoundingClientRect().left < left - .15 || col.getBoundingClientRect().right > right + .15)
      .map((col) => ({ row: row.className, width: bounds.width, column: col.className, actual: col.getBoundingClientRect().width }));
  }));
  assert.deepEqual(overflows, [], 'Every column fits within its row, including large gaps and distributed rows');

  await page.setContent(`<style>${css}</style>
    <div id="roomy" class="c-row c-gap-xl" style="width:1200px">${column(6)}${column(6)}</div>
    <div id="responsive" class="c-row c-gap-none c-t-gap-xl c-m-gap-sm" style="width:320px">${column(12)}</div>
    <div class="c-row c-gap-xl" style="width:484px"><div class="c-col c-col-12">
      <div id="nested" class="c-row c-row-nested c-gap-xl">${column(12, 'c-ct-col-6 c-cd-col-4')}${column(12, 'c-ct-col-6 c-cd-col-4')}</div>
      <div id="gallery" class="c-gallery c-gap-xl"></div>
    </div></div>`);
  const numericGap = (id) => page.locator(`#${id}`).evaluate((row) => {
    const box = document.createElement('div'), measure = document.createElement('div');
    const style = getComputedStyle(row);
    box.style.width = `${row.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight)}px`;
    measure.style.width = style.columnGap;
    box.append(measure); document.body.append(box);
    const gap = measure.getBoundingClientRect().width; box.remove(); return gap;
  });
  close(await numericGap('roomy'), 60, 'Roomy rows keep the selected gap');
  close(await numericGap('responsive'), 0, 'Desktop gap override');
  close(await numericGap('gallery'), 60, 'Galleries keep their gap inside a capped row');
  for (const [width, expected] of [[900, 24], [600, 8]]) {
    await page.setViewportSize({ width, height: 800 });
    close(await numericGap('responsive'), expected, 'Device gap remains capped');
    close(await page.locator('#nested > .c-col').first().evaluate((el) => el.getBoundingClientRect().width), 420, 'Nested full-span columns fit their own row');
  }

  // Mount the actual canvas to verify dragging uses the capped gap, including
  // percentage expressions that getComputedStyle does not resolve to pixels.
  const entry = `
    import { createApp, h } from 'vue';
    import BuilderCanvas from '${root}web/src/components/site/builder/BuilderCanvas.vue';
    import { createSiteBuilder } from '${root}web/src/composables/useSiteBuilder.js';
    import { api } from '${root}web/src/api/index.js';
    import { t } from '${root}web/src/i18n/index.js';
    const justify = new URLSearchParams(location.search).get('justify') || 'start';
    const nodes = [{ id:'row', kind:'row', props:{}, children:[1,2].map((n) => ({ id:'col'+n, kind:'column', props:{ span:{mobile:6,tablet:6,desktop:6} }, style:{}, children:[] })) }];
    const html = '<!doctype html><html><head><style>' + ${JSON.stringify(css)} + '</style></head><body class="c-builder"><div data-node="row" data-kind="row" class="c-row c-gap-xl c-justify-' + justify + '">' + [1,2].map((n) => '<div data-node="col'+n+'" data-kind="column" class="c-col c-col-6">Column</div>').join('') + '</div></body></html>';
    api.site.preview = async () => ({ data:{ html } });
    createApp({ setup() {
      const builder = createSiteBuilder({ t }); builder.load(nodes); builder.device.value = 'mobile'; window.builder = builder;
      return () => h('main', { style:'width:484px;height:760px' }, [h(BuilderCanvas, { body:{}, device:'full' })]);
    } }).mount('#app');`;
  server = await createServer({
    configFile: false, root: join(root, 'web'), base: '/admin/', logLevel: 'error',
    css: { postcss: { plugins: [] } },
    plugins: [vue(), {
      name: 'grid-gaps-fixture',
      resolveId(id) { if (id === 'virtual:grid-gaps') return '\0virtual:grid-gaps'; },
      load(id) { if (id === '\0virtual:grid-gaps') return entry; },
      configureServer(server) {
        server.middlewares.use(async (req, res, next) => {
          if (!req.url?.split('?')[0].endsWith('/grid-gaps')) return next();
          const html = await server.transformIndexHtml('/admin/grid-gaps', '<!doctype html><html><head><style>html,body{margin:0}main>div{width:100%;height:100%;overflow:hidden}</style></head><body><div id="app"></div><script type="module" src="/@id/__x00__virtual:grid-gaps"></script></body></html>');
          res.setHeader('Content-Type', 'text/html'); res.end(html);
        });
      },
    }],
    server: { host: '127.0.0.1', port: 0 },
  });
  await server.listen();
  const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  for (const justify of ['start', 'center']) {
    await page.goto(`${origin}/admin/grid-gaps?justify=${justify}`);
    const frame = page.frameLocator('iframe');
    const col = frame.locator('[data-node="col1"]');
    await col.waitFor();
    const before = await col.boundingBox();
    await page.mouse.move(before.x + before.width - 1, before.y + 4);
    const grip = frame.locator('.cometcms-resize');
    await grip.waitFor({ state: 'visible' });
    const handle = await grip.boundingBox();
    await page.mouse.move(handle.x + handle.width / 2, handle.y + 4);
    await page.mouse.down();
    await page.mouse.move(handle.x + handle.width / 2 + (452 + 452 / 12) / 12, handle.y + 4);
    await page.mouse.up();
    assert.equal(await page.evaluate(() => window.builder.nodes.value[0].children[0].props.span.mobile), 7, `${justify}: dragging saves the next span`);
    close((await col.boundingBox()).width - before.width, (452 + 452 / 12) / 12, `${justify}: dragging advances exactly one track`);
  }
  assert.deepEqual(errors, [], 'No canvas errors');
  console.log('Grid gaps passed: narrow and roomy rows, spans, alignment, device overrides, nested grids, galleries and canvas resizing.');
} finally {
  await browser?.close();
  await server?.close();
}
