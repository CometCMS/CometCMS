// Run after npm run build: node tests/browser/custom-blocks.mjs
// Uses Node, PHP and Chrome. Only an isolated temporary installation is modified.
import { spawn, spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';

const root = fileURLToPath(new URL('../../', import.meta.url));
const directory = mkdtempSync(join(tmpdir(), 'comet-blocks-browser-'));
const install = join(directory, 'site');
cpSync(process.env.COMET_TEST_DIST || join(root, 'dist'), install, { recursive: true });
const example = join(root, 'examples/blocks/comet-essentials');
const zips = { v1: join(directory, 'comet-essentials.zip'), v2: join(directory, 'comet-essentials-2.zip') };
const assert = (ok, message) => { if (!ok) throw new Error(message); };
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const seed = spawnSync('php', [], { encoding: 'utf8', input: `<?php
require ${JSON.stringify(join(install, 'app/bootstrap.php'))};
(new \\CometCMS\\Workspaces\\WorkspaceContext())->ensure();
(new \\CometCMS\\Workspaces\\WorkspaceRepository())->all();
(new \\CometCMS\\Auth\\RoleRepository())->seed();
$user = (new \\CometCMS\\Auth\\UserRepository())->create('block-admin', 'block-test-password', 'admin');
(new \\CometCMS\\Content\\ContentTypeRepository())->save(['name' => 'home', 'label' => 'Home', 'fields' => []]);
\\CometCMS\\Content\\ContentRepository::make()->save('home', ['title' => 'Home', 'status' => 'published'], $user);
(new \\CometCMS\\Site\\SiteRepository())->saveSettings(['title' => 'Block example', 'homepage' => ['type' => 'home', 'entry' => 'home']]);
// Local image fixtures keep comparison tests independent of external media.
$media = (new \\CometCMS\\Workspaces\\WorkspaceContext())->path('media');
foreach (['compare-before.svg' => '#64748b', 'compare-after.svg' => '#0d9488'] as $file => $color) {
    file_put_contents($media . '/' . $file, '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="' . $color . '"/></svg>');
    (new \\CometCMS\\Media\\MediaRepository())->updateMeta($file, $file === 'compare-before.svg' ? 'Original room' : 'Renovated room', 'Comparison');
}
// Version 2 drops the feature block, which needs confirmation when updating.
foreach ([${JSON.stringify(zips.v1)} => false, ${JSON.stringify(zips.v2)} => true] as $target => $breaking) {
    $zip = new ZipArchive();
    $zip->open($target, ZipArchive::CREATE);
    foreach (\\CometCMS\\Site\\Blocks\\BlockPackage::readDirectory(${JSON.stringify(example)}) as $path => $bytes) {
        if ($breaking && str_starts_with($path, 'blocks/feature/')) continue;
        if ($path === 'blocks/testimonial/block.json') {
            $manifest = json_decode($bytes, true);
            $manifest['props']['rating']['default'] = '4';
            $manifest['props']['layout']['default'] = 'centered';
            $bytes = json_encode($manifest);
        }
        $zip->addFromString('comet-essentials/' . $path, $breaking && $path === 'comet-package.json' ? str_replace('"1.5.0"', '"2.0.0"', $bytes) : $bytes);
    }
    $zip->close();
}
echo (new \\CometCMS\\Workspaces\\WorkspaceContext())->slug();
` });
assert(seed.status === 0, seed.stderr || 'Seed installation');
const workspace = seed.stdout.trim();
const port = await new Promise((resolve) => {
  const server = createServer();
  server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
});
const origin = `http://127.0.0.1:${port}`;
const php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', install, join(install, 'router.php')], { stdio: 'ignore' });
const profile = join(directory, 'chrome');
const chrome = spawn(process.env.CHROME_BIN || 'google-chrome', [
  '--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--disable-background-networking',
  '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank',
], { stdio: 'ignore' });
let socket;
let sequence = 0;
const pending = new Map();
const errors = [];

async function command(method, params = {}) {
  const id = ++sequence;
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Timed out: ${method}`)); }, 30000);
    pending.set(id, { resolve: (result) => { clearTimeout(timer); resolve(result); }, reject: (error) => { clearTimeout(timer); reject(error); } });
    socket.send(JSON.stringify({ id, method, params }));
  });
}
async function evaluate(expression) {
  const result = await command('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
  return result.result.value;
}
async function waitFor(expression, label) {
  const until = Date.now() + 20000;
  while (Date.now() < until) {
    if (await evaluate(expression)) return;
    await delay(100);
  }
  throw new Error(`Timed out: ${label}. ${await evaluate('document.body.innerText.slice(-2000)')}`);
}
async function field(selector, value) {
  await evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) throw new Error('Missing field'); el.value = ${JSON.stringify(value)}; el.dispatchEvent(new Event('input', { bubbles: true })); })()`);
}
async function click(text, selector = 'button') {
  await evaluate(`(() => { const button = [...document.querySelectorAll(${JSON.stringify(selector)})].find((el) => el.textContent.trim() === ${JSON.stringify(text)}); if (!button) throw new Error('Missing button: ' + ${JSON.stringify(text)}); button.click(); })()`);
}
// Check the real cascade in both the builder iframe and the published document.
async function checkBlockColors(documentExpression) {
  await waitFor(`(() => { const doc = ${documentExpression}; return !!doc.defaultView.getComputedStyle(doc.documentElement).getPropertyValue('--c-primary').trim(); })()`, 'theme stylesheet loaded');
  const result = await evaluate(`(() => {
    const doc = ${documentExpression};
    const surface = doc.querySelector('.cometcms-callout');
    const wrapper = surface.closest('.c-block-cometcms__callout');
    const original = wrapper.className;
    const css = (el) => doc.defaultView.getComputedStyle(el);
    const colors = doc.createElement('span');
    colors.style.cssText = 'color:var(--c-accent);background-color:var(--c-primary)';
    wrapper.append(colors);
    try {
      wrapper.classList.add('c-bg-primary', 'c-fg-accent', 'c-lc-accent');
      const normal = css(wrapper).backgroundColor === css(colors).backgroundColor
        && css(surface).backgroundColor === 'rgba(0, 0, 0, 0)'
        && css(surface.querySelector('h3')).color === css(colors).color
        && css(surface.querySelector('p')).color === css(colors).color
        && css(surface.querySelector('a')).color === css(colors).color;
      wrapper.classList.add('c-h-bg-accent', 'c-h-fg-primary', 'c-h-lc-primary', 'c-preview-hover');
      const hover = css(wrapper).backgroundColor === css(colors).color
        && css(surface.querySelector('p')).color === css(colors).backgroundColor
        && css(surface.querySelector('a')).color === css(colors).backgroundColor;
      return { normal, hover };
    } finally {
      wrapper.className = original;
      colors.remove();
    }
  })()`);
  assert(result.normal && result.hover, 'Block surface, text and links honor Style colors and hover overrides: ' + JSON.stringify(result));
}
async function selectBlockMedia(label, file) {
  await evaluate(`(() => { const label = [...document.querySelectorAll('span')].find((el) => el.textContent === ${JSON.stringify(label)}); const button = [...label.closest('.relative').querySelectorAll('button')].find((el) => el.textContent.trim() === 'Choose media'); button.click(); })()`);
  await waitFor(`!!document.querySelector('[role="dialog"] [title="${file}"]')`, 'media picker image');
  await evaluate(`document.querySelector('[role="dialog"] [title="${file}"]').closest('button').click()`);
  await waitFor('!document.querySelector(\'[role="dialog"][aria-modal="true"]\')', 'media picker closed');
}
/** Packages with JavaScript ask before installing. */
async function allowScripts() {
  await waitFor('!!document.querySelector(\'[role="alertdialog"]\') && document.body.innerText.includes("Allow JavaScript?")', 'script consent');
  assert(await evaluate('[...document.querySelectorAll(\'[role="alertdialog"]\')].some((dialog) => dialog.innerText.includes("Allow JavaScript?") && dialog.innerText.includes("cometcms/before-after") && dialog.innerText.includes("cometcms/stats") && dialog.innerText.includes("cometcms/slideshow"))'), 'Consent names the blocks with scripts');
  await click('Install with JavaScript', '[role="alertdialog"] button');
  // Its overlay fades out; a drop before then would land on it.
  await waitFor('!document.querySelector(\'[role="alertdialog"]\')', 'script consent closed');
}
async function dropFiles(paths) {
  const point = await evaluate(`(() => { const el = document.querySelector('#block-drop-zone'); el.scrollIntoView({ block: 'center' }); const rect = el.getBoundingClientRect(); return { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 }; })()`);
  const data = { items: [], files: paths, dragOperationsMask: 1 };
  for (const type of ['dragEnter', 'dragOver', 'drop']) {
    await command('Input.dispatchDragEvent', { type, ...point, data });
  }
}

try {
  const activePort = join(profile, 'DevToolsActivePort');
  for (let count = 0; count < 100 && !existsSync(activePort); count++) await delay(100);
  assert(existsSync(activePort), 'Chrome started');
  const chromePort = readFileSync(activePort, 'utf8').split('\n')[0];
  const targets = await fetch(`http://127.0.0.1:${chromePort}/json`).then((response) => response.json());
  socket = new WebSocket(targets.find((target) => target.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
  socket.onmessage = ({ data }) => {
    const message = JSON.parse(data);
    if (pending.has(message.id)) {
      const task = pending.get(message.id); pending.delete(message.id);
      if (message.error) task.reject(new Error(message.error.message)); else task.resolve(message.result);
    }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
  };
  await command('Page.enable');
  await command('Runtime.enable');
  // Headless Chrome otherwise changes activeElement without dispatching focus events.
  await command('Emulation.setFocusEmulationEnabled', { enabled: true });
  await command('Page.navigate', { url: `${origin}/admin` });
  await waitFor('!!document.querySelector("#login-username")', 'login');
  await field('#login-username', 'block-admin');
  await field('#login-password', 'block-test-password');
  await click('Sign in');
  await waitFor('location.pathname.endsWith("/dashboard")', 'dashboard');
  await command('Page.navigate', { url: `${origin}/admin/page-builder/blocks` });
  await waitFor('!!document.querySelector("#block-package")', 'package upload');
  await dropFiles([join(example, 'comet-package.json')]);
  await waitFor('document.body.innerText.includes("Choose a single .zip file.")', 'non-ZIP rejected before upload');
  await command('DOM.enable');
  const { root: documentNode } = await command('DOM.getDocument');
  const { nodeId } = await command('DOM.querySelector', { nodeId: documentNode.nodeId, selector: '#block-package' });
  await command('DOM.setFileInputFiles', { nodeId, files: [zips.v1] });
  await allowScripts();
  await waitFor('document.body.innerText.includes("Comet Essentials installed.")', 'example package installation');
  assert(await evaluate('document.body.innerText.includes("JavaScript")'), 'Inventory marks packages with scripts');
  assert(await evaluate('document.body.innerText.includes("cometcms/essentials") && document.body.innerText.includes("Pricing plan")'), 'Inventory lists the package and its blocks');
  const asset = await evaluate(`fetch('/_site/${workspace}/blocks/${createHash('sha256').update(readFileSync(join(example, 'assets/check.svg'))).digest('hex')}.svg').then((res) => ({ status: res.status, type: res.headers.get('content-type'), csp: res.headers.get('content-security-policy'), cache: res.headers.get('cache-control') }))`);
  assert(asset.status === 200 && asset.type === 'image/svg+xml' && asset.csp.includes('sandbox') && asset.cache.includes('immutable'), 'Package asset is served safely: ' + JSON.stringify(asset));
  await command('Page.navigate', { url: `${origin}/admin/page-builder/builder/layout/home/detail` });
  await waitFor('[...document.querySelectorAll("button")].some((el) => el.textContent.trim() === "Callout")', 'custom palette entry');
  await evaluate('document.querySelector(\'[role="dialog"][aria-labelledby^="builder-guide-"] button[aria-label="Collapse panel"]\')?.click()');
  await click('Callout');
  // Single-line text props are bindable and use the smart value editor; the multi-line message is a textarea.
  await waitFor('!!document.querySelector(\'textarea[id$="-props-message"]\')', 'generated inspector');
  await field('textarea[id$="-props-message"]', 'Custom blocks work');
  const previewDocument = '[...document.querySelectorAll("iframe")].map((el) => el.contentDocument).find((doc) => doc?.querySelector(".cometcms-callout"))';
  await waitFor(`(${previewDocument})?.querySelector('.cometcms-callout p')?.textContent === 'Custom blocks work'`, 'live custom block preview');
  const preview = await evaluate(`(() => { const doc = ${previewDocument}; const el = doc.querySelector('.cometcms-callout'); const copy = el.cloneNode(true); copy.querySelectorAll('[draggable]').forEach((node) => node.removeAttribute('draggable')); return { html: copy.outerHTML, border: doc.defaultView.getComputedStyle(el).borderInlineStartWidth, annotated: !!el.closest('[data-kind="block:cometcms/callout"]'), cssCount: doc.querySelectorAll('style[data-comet-blocks]').length }; })()`);
  assert(preview.border === '4px', 'Preview loads block CSS');
  await checkBlockColors(`(${previewDocument})`);
  assert(preview.annotated && preview.cssCount === 1, 'Builder annotations and one block stylesheet');
  assert(await evaluate('!document.querySelector("style[data-comet-blocks]")'), 'Package CSS stays out of admin parent');
  const icons = await evaluate(`(() => { const item = (label) => [...document.querySelectorAll("button")].find((el) => el.textContent.trim() === label); return { feature: !!item("Feature")?.querySelector('span[style*="mask"]'), card: !!item("Card")?.querySelector("svg") }; })()`);
  assert(icons.feature && icons.card, 'Palette shows file and named block icons: ' + JSON.stringify(icons));
  if (process.env.COMET_BLOCK_SCREENSHOT) {
    const palette = await command('Page.captureScreenshot', { format: 'png' });
    writeFileSync(process.env.COMET_BLOCK_SCREENSHOT.replace(/\.png$/, '-builder.png'), Buffer.from(palette.data, 'base64'));
  }
  await click('Testimonial');
  await waitFor('!!document.querySelector(\'textarea[id$="-props-quote"]\')', 'testimonial quote inspector');
  await field('textarea[id$="-props-quote"]', 'A wonderful result, from start to finish.');
  await selectBlockMedia('Portrait', 'compare-before.svg');
  await waitFor(`(${previewDocument})?.querySelector('.cometcms-testimonial__quote p')?.textContent === 'A wonderful result, from start to finish.'`, 'testimonial live preview');
  assert(await evaluate(`(${previewDocument}).querySelector('.cometcms-testimonial__rating').getAttribute('aria-label') === 'Rating: 4 / 5' && !!(${previewDocument}).querySelector('.cometcms-testimonial__portrait')`), 'Testimonial rating and portrait preview');
  await click('Before / after');
  await waitFor('!!document.querySelector(\'input[type="range"][id$="-props-position"]\')', 'comparison split inspector');
  await field('input[type="range"][id$="-props-position"]', '35');
  await selectBlockMedia('Before image', 'compare-before.svg');
  await selectBlockMedia('After image', 'compare-after.svg');
  await waitFor(`(${previewDocument})?.querySelectorAll('.cometcms-compare__side img').length === 2`, 'comparison media preview');
  await waitFor(`!!(${previewDocument})?.querySelector('.cometcms-compare[data-ready]')`, 'comparison script mounts in builder preview');
  assert(await evaluate(`(${previewDocument}).querySelector('.cometcms-compare input').value === '35'`), 'Comparison preview uses unsaved split and media');
  await click('Before / after');
  await waitFor(`(${previewDocument})?.querySelectorAll('.cometcms-compare').length === 2`, 'independent comparison instances');
  await selectBlockMedia('Before image', 'compare-before.svg');
  await selectBlockMedia('After image', 'compare-after.svg');
  await click('Stats');
  await waitFor(`!!(${previewDocument})?.querySelector('.cometcms-stats')`, 'stats block in preview');
  await waitFor(`!!(${previewDocument})?.querySelector('[data-comet-block="cometcms/stats"][data-mounted="true"]')`, 'builder executes block modules under the preview CSP');
  await click('Slideshow');
  await waitFor(`(${previewDocument})?.querySelectorAll('.cometcms-slideshow [data-slide]').length === 3`, 'slideshow preview');
  await waitFor(`!!(${previewDocument})?.querySelector('[data-comet-block="cometcms/slideshow"][data-mounted="true"]')`, 'slideshow mounts in preview');
  assert(await evaluate(`!(${previewDocument}).querySelector('.cometcms-slideshow [data-controls]').hidden && (${previewDocument}).querySelectorAll('.cometcms-slideshow [data-slide]:not([hidden])').length === 1`), 'Preview renders the active slideshow');
  await click('Add item');
  await waitFor('!!document.querySelector(\'input[id$="-props-slides-3-title"]\')', 'new slide fields');
  await field('input[id$="-props-slides-3-title"]', 'A custom slide');
  await waitFor(`(${previewDocument})?.querySelectorAll('.cometcms-slideshow [data-slide]')[3]?.textContent.includes('A custom slide')`, 'slide content in preview');
  await waitFor(`(${previewDocument})?.querySelector('.cometcms-slideshow [data-status]')?.textContent === '1 / 4'`, 'slideshow remounts with unsaved slide');
  assert(await evaluate(`(${previewDocument}).querySelectorAll('.cometcms-slideshow [data-selectors] button').length === 4`), 'Reload resets generated controls without duplicating them');
  await click('Pricing plan');
  await waitFor('[...document.querySelectorAll("button")].some((el) => el.textContent.trim() === "Add item")', 'list editor');
  await click('Add item');
  await waitFor('!!document.querySelector(\'input[id$="-props-features-3-text"]\')', 'new list item fields');
  await field('input[id$="-props-features-3-text"]', 'Custom feature');
  await waitFor(`[...(${previewDocument})?.querySelectorAll('.cometcms-plan__feature') ?? []].map((el) => el.textContent).includes('Custom feature')`, 'list item in preview');
  await click('Maps');
  await waitFor('!!document.querySelector(\'input[type="range"][id$="-props-zoom"]\')', 'map zoom slider');
  await field('input[type="range"][id$="-props-zoom"]', '15');
  await evaluate(`(() => {
    const label = [...document.querySelectorAll('span')].find((el) => el.textContent === 'Coordinates (latitude, longitude)');
    const editor = label.closest('.relative').querySelector('[contenteditable="true"]');
    editor.focus();
    const range = document.createRange(); range.selectNodeContents(editor);
    const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
  })()`);
  await command('Input.insertText', { text: '0, 0' });
  await waitFor(`(${previewDocument})?.querySelector('.cometcms-map__caption span')?.textContent === '0, 0'`, 'map coordinates in preview');
  await waitFor(`!!(${previewDocument})?.querySelector('[data-comet-block="cometcms/maps"][data-mounted="true"]')`, 'map mounts in preview');
  assert(await evaluate(`!(${previewDocument}).querySelector('.cometcms-map [data-controls]').hidden && (${previewDocument}).querySelector('.cometcms-map [data-status]').textContent.endsWith('15')`), 'Map preview uses unsaved coordinates and zoom');
  // A selected slot block takes the next element inside it.
  await click('Card');
  await waitFor(`!!(${previewDocument})?.querySelector('.cometcms-card .c-placeholder-drop')`, 'empty card slot');
  await click('Heading');
  await waitFor(`!!(${previewDocument})?.querySelector('.cometcms-card__body .c-el-heading')`, 'heading placed in card slot');
  await click('Save template');
  await waitFor('document.body.innerText.includes("Layout saved.") || [...document.querySelectorAll("button")].some((el) => el.textContent.trim() === "Save template" && el.disabled)', 'layout saved');
  await command('Page.navigate', { url: `${origin}/admin/site-preview?workspace=${workspace}` });
  await waitFor(`!!document.querySelector('[data-comet-block="cometcms/slideshow"][data-mounted="true"]')`, 'full-page preview mounts installed scripts under both CSP policies');
  await evaluate(`document.querySelector('.cometcms-slideshow [data-next]').click()`);
  assert(await evaluate(`document.querySelector('.cometcms-slideshow [data-status]').textContent === '2 / 4'`), 'Full-page preview slideshow navigation works');
  const previewCsp = await evaluate(`fetch(location.href).then((res) => res.headers.get('content-security-policy'))`);
  assert(previewCsp.includes('/blocks/') && !/script-src[^;]*'unsafe/.test(previewCsp), 'Full-page preview header allows exact installed block sources');
  await command('Page.navigate', { url: `${origin}/` });
  await waitFor('!!document.querySelector(".cometcms-callout")', 'published custom block');
  await checkBlockColors('document');
  await waitFor('document.querySelectorAll(".cometcms-compare[data-ready]").length === 2', 'comparison images loaded and sliders mounted');
  const comparison = 'document.querySelector(".cometcms-compare")';
  assert(await evaluate(`(${comparison}).querySelector('input').value === '35' && (${comparison}).querySelector('input').getAttribute('aria-valuetext') === 'Before 35% / After 65%'`), 'Comparison starts at the saved split with an accessible value');
  assert(await evaluate(`(() => { const frame = (${comparison}).querySelector('[data-frame]').getBoundingClientRect(); const before = (${comparison}).querySelector('.cometcms-compare__before'); const image = before.querySelector('img').getBoundingClientRect(); return Math.abs(frame.width - image.width) < 1 && Math.abs(frame.height - image.height) < 1 && getComputedStyle(before).clipPath.includes('65%'); })()`), 'Both images fill the same frame and the initial split clips the before image');
  await evaluate(`(${comparison}).querySelector('input').focus()`);
  for (const key of ['End', 'Home', 'ArrowRight']) {
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key, code: key });
    await command('Input.dispatchKeyEvent', { type: 'keyUp', key, code: key });
    const expected = { End: '100', Home: '0', ArrowRight: '1' }[key];
    assert(await evaluate(`(${comparison}).querySelector('input').value === '${expected}' && (${comparison}).style.getPropertyValue('--cometcms-compare-position') === '${expected}%'`), 'Comparison supports ' + key);
  }
  const drag = await evaluate(`(() => { const frame = (${comparison}).querySelector('[data-frame]'); frame.scrollIntoView({ block: 'center' }); const rect = frame.getBoundingClientRect(); return { x: rect.left + rect.width * .25, end: rect.left + rect.width * .75, y: rect.top + rect.height / 2 }; })()`);
  await command('Input.dispatchMouseEvent', { type: 'mousePressed', x: drag.x, y: drag.y, button: 'left', clickCount: 1 });
  await command('Input.dispatchMouseEvent', { type: 'mouseMoved', x: drag.end, y: drag.y, button: 'left', buttons: 1 });
  await command('Input.dispatchMouseEvent', { type: 'mouseReleased', x: drag.end, y: drag.y, button: 'left', clickCount: 1 });
  assert(await evaluate(`(${comparison}).querySelector('input').value === '75' && document.querySelectorAll('.cometcms-compare')[1].querySelector('input').value === '50'`), 'Dragging updates the divider and leaves other instances unchanged');
  await command('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: drag.x, y: drag.y }] });
  assert(await evaluate(`(${comparison}).querySelector('input').value === '25'`), 'Touch starts at the contact position');
  await command('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: drag.end, y: drag.y }] });
  await command('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  assert(await evaluate(`(${comparison}).querySelector('input').value === '75'`), 'Comparison supports touch dragging');
  const beforeSource = await evaluate(`(() => { const img = (${comparison}).querySelector('img'); const source = img.src; img.src = '/missing-comparison-image.svg'; return source; })()`);
  await waitFor(`!(${comparison}).hasAttribute('data-ready') && (${comparison}).querySelector('[data-control]').hidden`, 'failed image restores side-by-side fallback');
  await evaluate(`(${comparison}).querySelector('img').src = ${JSON.stringify(beforeSource)}`);
  await waitFor(`(${comparison}).hasAttribute('data-ready')`, 'reloaded image restores comparison');
  assert(await evaluate(`document.querySelector('.cometcms-testimonial__quote p').textContent === 'A wonderful result, from start to finish.' && document.querySelector('.cometcms-testimonial__rating').getAttribute('aria-label') === 'Rating: 4 / 5' && !document.querySelector('[data-comet-block="cometcms/testimonial"]')`), 'Published testimonial matches preview without JavaScript');

  const published = await evaluate(`({ html: document.querySelector('.cometcms-callout').outerHTML, border: getComputedStyle(document.querySelector('.cometcms-callout')).borderInlineStartWidth, annotated: !!document.querySelector('[data-node]') })`);
  assert(published.html === preview.html, 'Preview and published custom markup match: ' + JSON.stringify({ preview: preview.html, published: published.html }));
  assert(published.border === preview.border, 'Published CSS matches preview');
  assert(!published.annotated, 'Public output excludes editor annotations');
  assert(await evaluate(`!!document.querySelector('.cometcms-card__body .c-el-heading') && [...document.querySelectorAll('.cometcms-plan__feature')].some((el) => el.textContent === 'Custom feature')`), 'Published slot children and list items');
  await waitFor('!!document.querySelector(\'[data-comet-block="cometcms/stats"][data-mounted="true"]\')', 'block script mounted under the site CSP');
  const map = 'document.querySelector(\'[data-comet-block="cometcms/maps"]\')';
  await waitFor(`!!${map}?.dataset.mounted`, 'map script mounted under CSP');
  await evaluate(`(${map}).scrollIntoView({ block: 'center' })`);
  await waitFor(`(${map}).querySelector('[data-tiles] img')?.src.includes('/15/')`, 'map renders tiles at saved zoom');
  assert(await evaluate(`(${map}).querySelector('[data-open]').href.includes('mlat=0&mlon=0#map=15/0/0')`), 'Zero coordinates are preserved');
  await evaluate(`(${map}).querySelector('[data-zoom-in]').click()`);
  await waitFor(`(${map}).querySelector('[data-status]').textContent.endsWith('16') && (${map}).querySelector('[data-tiles] img')?.src.includes('/16/')`, 'map zoom button updates tiles and status');
  await evaluate(`(${map}).querySelector('[data-zoom-out]').click()`);
  await waitFor(`(${map}).querySelector('[data-status]').textContent.endsWith('15')`, 'map zoom out');
  const csp = await evaluate(`fetch('/').then((res) => res.headers.get('content-security-policy'))`);
  assert(/script-src 'sha256-[^']+' 127\.0\.0\.1:\d+\/_site\/[^ ]+\/blocks\/[a-f0-9]{64}\.js/.test(csp) && !csp.includes("'self' 'sha") && !/script-src[^;]*'unsafe/.test(csp), 'CSP allows exactly the block script: ' + csp);
  await waitFor('!!document.querySelector(\'[data-comet-block="cometcms/slideshow"][data-mounted="true"]\')', 'slideshow mounted under the site CSP');
  const slideshow = 'document.querySelector(\'[data-comet-block="cometcms/slideshow"]\')';
  const activeSlide = `(${slideshow}).querySelector('[data-slide]:not([hidden])')`;
  assert(await evaluate(`(${slideshow}).querySelectorAll('[data-slide]:not([hidden])').length === 1 && (${slideshow}).querySelector('[data-status]').textContent === '1 / 4'`), 'Only the first slide is active');
  await evaluate(`(${slideshow}).querySelector('[data-previous]').click()`);
  assert(await evaluate(`(${activeSlide}).textContent.includes('A custom slide')`), 'Previous wraps to the last slide');
  await evaluate(`(${slideshow}).querySelectorAll('[data-selectors] button')[1].click()`);
  assert(await evaluate(`(${activeSlide}).textContent.includes('Make it yours') && (${slideshow}).querySelectorAll('[aria-current="true"]').length === 1`), 'Slide selectors update content and their accessible state');
  await evaluate(`(${slideshow}).querySelector('[data-next]').focus(); (${slideshow}).querySelector('[data-next]').dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true }))`);
  assert(await evaluate(`(${activeSlide}).textContent.includes('A custom slide')`), 'End selects the last slide');
  await evaluate(`(${slideshow}).querySelector('[data-next]').dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }))`);
  assert(await evaluate(`(${activeSlide}).textContent.includes('Share your story')`), 'Right arrow wraps to the first slide');
  // Mount extra instances from the same CSP-approved module to exercise edge cases and timers.
  const slideshowUrl = `/_site/${workspace}/blocks/${createHash('sha256').update(readFileSync(join(example, 'blocks/slideshow/view.js'))).digest('hex')}.js`;
  await evaluate(`(async () => {
    const { default: mount } = await import(${JSON.stringify(slideshowUrl)});
    window.slideshowFixture = (id, count, autoplay = false) => {
      const copy = (${slideshow}).cloneNode(true);
      copy.id = id;
      copy.querySelector('[data-selectors]').replaceChildren();
      [...copy.querySelectorAll('[data-slide]')].slice(count).forEach((slide) => slide.remove());
      document.body.append(copy);
      mount(copy, { ...JSON.parse(copy.dataset.cometProps), autoplay, interval: 2000 });
      return copy;
    };
    window.slideshowFixture('slideshow-empty', 0);
    window.slideshowFixture('slideshow-single', 1, true);
    window.slideshowFixture('slideshow-auto', 4, true);
  })()`);
  assert(await evaluate(`document.querySelector('#slideshow-empty [data-controls]').hidden && document.querySelector('#slideshow-single [data-controls]').hidden && document.querySelectorAll('#slideshow-single [data-slide]:not([hidden])').length === 1`), 'Empty and single-slide instances hide navigation');
  await waitFor(`document.querySelector('#slideshow-auto [data-status]').textContent === '2 / 4'`, 'autoplay advances independently');
  assert(await evaluate(`(${slideshow}).querySelector('[data-status]').textContent === '1 / 4'`), 'Instances keep their navigation independent');
  await evaluate(`document.querySelector('#slideshow-auto [data-next]').focus()`);
  const pausedSlide = await evaluate(`document.querySelector('#slideshow-auto [data-status]').textContent`);
  await delay(2200);
  const focusState = await evaluate(`({ status: document.querySelector('#slideshow-auto [data-status]').textContent, play: document.querySelector('#slideshow-auto [data-play]').textContent, active: document.activeElement?.outerHTML, focused: document.hasFocus() })`);
  assert(focusState.status === pausedSlide && focusState.play === 'Play slideshow', 'Keyboard focus stops autoplay: ' + JSON.stringify({ pausedSlide, ...focusState }));
  await evaluate(`document.querySelector('#slideshow-auto [data-play]').click(); document.querySelector('#slideshow-auto .cometcms-slideshow').dispatchEvent(new PointerEvent('pointerenter'))`);
  await delay(2200);
  assert(await evaluate(`document.querySelector('#slideshow-auto [data-status]').textContent === ${JSON.stringify(pausedSlide)}`), 'Hover pauses autoplay after explicitly resuming');
  await evaluate(`document.querySelector('#slideshow-auto .cometcms-slideshow').dispatchEvent(new PointerEvent('pointerleave'))`);
  await waitFor(`document.querySelector('#slideshow-auto [data-status]').textContent !== ${JSON.stringify(pausedSlide)}`, 'autoplay resumes after hover');
  await evaluate(`document.querySelector('#slideshow-auto [data-play]').click()`);
  const buttonPausedSlide = await evaluate(`document.querySelector('#slideshow-auto [data-status]').textContent`);
  await command('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
  await evaluate(`window.slideshowFixture('slideshow-reduced', 4, true)`);
  await delay(2200);
  assert(await evaluate(`document.querySelector('#slideshow-reduced [data-status]').textContent === '1 / 4' && document.querySelector('#slideshow-reduced [data-play]').textContent === 'Play slideshow'`), 'Reduced motion suppresses autoplay');
  assert(await evaluate(`document.querySelector('#slideshow-auto [data-status]').textContent === ${JSON.stringify(buttonPausedSlide)} && document.querySelector('#slideshow-auto [data-play]').textContent === 'Play slideshow'`), 'Pause button stops autoplay');
  await command('Emulation.setEmulatedMedia', { features: [] });
  await evaluate(`document.querySelectorAll('[id^="slideshow-"]').forEach((element) => element.remove())`);
  if (process.env.COMET_BLOCK_SCREENSHOT) {
    const { cssContentSize: size } = await command('Page.getLayoutMetrics');
    const screenshot = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: size.width, height: size.height, scale: 1 } });
    writeFileSync(process.env.COMET_BLOCK_SCREENSHOT, Buffer.from(screenshot.data, 'base64'));
  }
  await command('Page.navigate', { url: `${origin}/admin/page-builder/blocks` });
  await waitFor('!!document.querySelector(\'button[aria-label="Delete package: Comet Essentials"]\')', 'delete action');
  await click('Delete');
  await waitFor('!!document.querySelector(\'[role="alertdialog"]\')', 'delete confirmation');
  await click('Cancel', '[role="alertdialog"] button');
  assert(await evaluate('document.body.innerText.includes("cometcms/essentials")'), 'Cancel keeps installed package');
  await click('Delete');
  await waitFor('!!document.querySelector(\'[role="alertdialog"]\')', 'delete confirmation reopened');
  await click('Delete', '[role="alertdialog"] button');
  await waitFor('document.body.innerText.includes("Package deleted.")', 'package deleted');
  assert(await evaluate('document.body.innerText.includes("No block packages installed.")'), 'Deleted package leaves inventory');
  assert(await evaluate(`fetch('/').then((res) => res.text()).then((html) => !html.includes('Custom blocks work'))`), 'Deletion invalidates published output');
  await command('Page.navigate', { url: `${origin}/admin/page-builder/builder/layout/home/detail` });
  await waitFor('[...document.querySelectorAll("iframe")].some((frame) => frame.contentDocument?.body?.textContent.includes("Missing block: cometcms/callout"))', 'placed instance preserved as missing block');
  await command('Page.navigate', { url: `${origin}/admin/page-builder/blocks` });
  await waitFor('!!document.querySelector("#block-drop-zone")', 'drop area');
  await dropFiles([zips.v1]);
  await allowScripts();
  await waitFor('document.body.innerText.includes("Comet Essentials installed.")', 'ZIP drop installation');
  assert(await evaluate(`fetch('/').then((res) => res.text()).then((html) => html.includes('>Custom blocks work</p>'))`), 'Reinstall restores saved instance props');
  await dropFiles([zips.v2]);
  await waitFor('!!document.querySelector(\'[role="alertdialog"]\') && document.body.innerText.includes("cometcms/feature is removed.")', 'breaking update confirmation');
  await click('Update anyway', '[role="alertdialog"] button');
  await allowScripts();
  await waitFor('document.body.innerText.includes("updated from 1.5.0 to 2.0.0")', 'confirmed update');
  assert(errors.length === 0, errors.join('\n'));
  console.log('Custom blocks browser checks passed (ZIP picker/drop, script consent and CSP, asset serving, icons, list editing, map slider/coordinates/tiles/zoom, comparison drag/touch/keyboard/instances/fallback, testimonial, slideshow navigation/autoplay/reduced motion, slots, palette, preview, publish, deletion, cancel, reinstall and confirmed update).');
} finally {
  socket?.close();
  chrome.kill();
  php.kill();
  await delay(300);
  rmSync(directory, { recursive: true, force: true });
}
