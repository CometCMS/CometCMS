<?php

declare(strict_types=1);

use CometCMS\Site\Blocks\BlockPackage;
use CometCMS\Site\Blocks\BlockPackageChanges;
use CometCMS\Site\Blocks\BlockPackageException;
use CometCMS\Site\Blocks\BlockRegistry;
use CometCMS\Site\Blocks\BlockTemplate;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\SiteRepository;
use CometCMS\Site\SiteService;
use CometCMS\Workspaces\WorkspaceContext;

const COMET_BLOCK_EXAMPLE = COMET_TEST_ROOT . '/examples/blocks/comet-essentials';

/** The example package as path => bytes, optionally changed. */
function comet_block_files(array $changes = []): array
{
    $files = BlockPackage::readDirectory(COMET_BLOCK_EXAMPLE);
    foreach ($changes as $path => $bytes) {
        if ($bytes === null) {
            unset($files[$path]);
        } else {
            $files[$path] = is_array($bytes) ? json_encode($bytes, JSON_UNESCAPED_SLASHES) : $bytes;
        }
    }

    return $files;
}

function comet_block_manifest(string $path, array $changes): array
{
    return array_replace(json_decode(comet_block_files()[$path], true), $changes);
}

/** @param array<string, string> $files entry name => bytes */
function comet_block_zip(array $files, string $prefix = ''): string
{
    $path = COMET_STORAGE . '/package-' . bin2hex(random_bytes(4)) . '.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($files as $name => $bytes) {
        $zip->addFromString($prefix . $name, $bytes);
    }
    $zip->close();

    return $path;
}

/** Upload a ZIP to the admin endpoint through PHP's built-in server, so $_FILES is real. */
function comet_block_upload(string $zipPath, array $fields = [], string $seed = ''): array
{
    $router = COMET_STORAGE . '/block-upload-router-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($router, "<?php\nrequire " . var_export(__DIR__ . '/bootstrap.php', true) . ";\n"
        . "session_start();\n\$users = new \\CometCMS\\Auth\\UserRepository();\n(new \\CometCMS\\Auth\\RoleRepository())->seed();\n"
        . "if (\$users->find('admin') === null) \$users->create('admin', 'secret-password', 'admin');\n" . $seed . "\n"
        . "\$_SESSION['user_id'] = 'admin';\n\$_SESSION['cometcms_csrf'] = 'test-token';\n"
        . "(new \\CometCMS\\Controllers\\Admin\\SiteController(new \\CometCMS\\Core\\Http()))->installBlock();\n");
    $boundary = 'comet' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
    }
    $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"package\"; filename=\"package.zip\"\r\nContent-Type: application/zip\r\n\r\n"
        . file_get_contents($zipPath) . "\r\n--$boundary--\r\n";
    $port = 18080 + random_int(0, 4000);
    $server = comet_test_start_php_server('127.0.0.1', $port, $router);
    try {
        usleep(300000);
        $response = (string) file_get_contents('http://127.0.0.1:' . $port . '/admin/api/site/blocks', false, stream_context_create(['http' => [
            'method' => 'POST',
            'ignore_errors' => true,
            'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\nX-CSRF-Token: test-token\r\n",
            'content' => $body,
        ]]));
        $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : (${'http_response_header'} ?? []);
        preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0] ?? '', $match);

        return [(int) ($match[1] ?? 0), json_decode($response, true)];
    } finally {
        comet_test_stop_process($server);
    }
}

function comet_block_page(SiteRepository $site, array $elements): void
{
    $site->saveLayout('about', 'detail', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], $elements)])])]);
    $site->saveSettings(array_replace($site->settings(), ['homepage' => ['type' => 'about', 'entry' => 'about']]));
}

function comet_block_errors(callable $callback): array
{
    try {
        $callback();
    } catch (BlockPackageException $e) {
        return array_map([BlockPackageException::class, 'format'], $e->errors());
    }
    throw new RuntimeException('Expected the package to be rejected.');
}

test('block packages register their blocks without changing built-ins or crossing workspaces', function (): void {
    $blocks = BlockRegistry::active();
    $result = $blocks->installFiles(comet_block_files(), allowScripts: true);
    assert_same(['name' => 'cometcms/essentials', 'title' => 'Comet Essentials', 'version' => '1.5.0', 'previous_version' => null,
        'blocks' => ['cometcms/before-after', 'cometcms/callout', 'cometcms/card', 'cometcms/faq', 'cometcms/feature', 'cometcms/maps', 'cometcms/pricing', 'cometcms/slideshow', 'cometcms/stats', 'cometcms/testimonial'], 'changes' => [], 'scripts' => ['cometcms/before-after', 'cometcms/maps', 'cometcms/slideshow', 'cometcms/stats']], $result);
    $spec = LayoutSchema::spec(blocks: $blocks);
    assert_true(in_array('heading', $spec['elements'], true));
    foreach (['block:cometcms/callout', 'block:cometcms/faq', 'block:cometcms/feature'] as $kind) {
        assert_true(in_array($kind, $spec['elements'], true));
        assert_true(in_array($kind, $spec['item_elements'], true));
    }
    $callout = $spec['kinds']['block:cometcms/callout'];
    assert_same('Callout', $callout['label']);
    assert_same(['type' => 'url', 'label' => 'Link URL', 'max' => 2000, 'default' => '#details', 'localized' => true], $callout['props']['url']);
    assert_true($callout['props']['title']['localized']);
    assert_false(isset($callout['props']['tone']['localized']));
    assert_same([], $callout['children']);
    assert_same('Something worth sharing', LayoutSchema::node('block:cometcms/callout')['props']['title']);
    $other = new BlockRegistry(new WorkspaceContext('other'));
    assert_same([], $other->all());
    assert_false(in_array('block:cometcms/callout', LayoutSchema::spec(blocks: $other)['elements'], true));
    $metadata = $blocks->metadata();
    assert_same([], $metadata['errors']);
    assert_same('cometcms/essentials', $metadata['packages'][0]['name']);
    assert_same(['name' => 'cometcms/faq', 'title' => 'FAQ', 'description' => 'Questions that expand to their answers. Opening one closes the others in the same group.', 'icon' => 'mdi:frequently-asked-questions', 'script' => false], $metadata['packages'][0]['blocks'][3]);
    assert_file_exists_at($blocks->directory() . '/cometcms/essentials/blocks/callout/template.html');
    assert_file_exists_at($blocks->directory() . '/cometcms/essentials/assets/check.svg');
    assert_false(BlockRegistry::cssClass('a/b--c') === BlockRegistry::cssClass('a--b/c'));
});

test('block package ZIPs unwrap one top folder, skip OS clutter and reject unsafe entries', function (): void {
    $files = comet_block_files();
    $wrapped = comet_block_zip($files + ['.DS_Store' => 'x', '__MACOSX/._comet-package.json' => 'x'], 'comet-essentials/');
    assert_same(array_keys($files), array_keys(BlockPackage::readZip($wrapped)));
    assert_same('cometcms/essentials', BlockRegistry::active()->install($wrapped, allowScripts: true)['name']);

    $before = BlockRegistry::active()->fingerprint();
    foreach ([
        ['../escape.txt' => 'x'],
        ['blocks/callout/view.js' => 'alert(1)'],
        ['blocks/callout/render.php' => '<?php echo 1;'],
        ['notes.txt' => 'x'],
        ['assets/logo.svg' => 'not an svg'],
    ] as $extra) {
        assert_throws(BlockPackageException::class, fn() => BlockRegistry::active()->install(comet_block_zip($files + $extra)));
        assert_same($before, BlockRegistry::active()->fingerprint());
    }
    $notZip = COMET_STORAGE . '/not-a.zip';
    file_put_contents($notZip, 'plain text');
    assert_same(['The upload is not a readable ZIP file.'], comet_block_errors(fn() => BlockPackage::readZip($notZip)));
    assert_false(is_file(BlockRegistry::active()->directory() . '/cometcms/escape.txt'));
    assert_same(['blocks/callout/lib.js: A block\'s script is blocks/<name>/view.js, one self-contained module. Bundle other files into it.'], comet_block_errors(fn() => BlockPackage::compile($files + ['blocks/callout/lib.js' => 'export default 1;'])));

    $link = comet_block_zip($files);
    $zip = new ZipArchive();
    $zip->open($link);
    $zip->addFromString('assets/link.svg', '/etc/passwd');
    $zip->setExternalAttributesName('assets/link.svg', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    assert_same(['assets/link.svg: Symbolic links are not allowed.'], comet_block_errors(fn() => BlockPackage::readZip($link)));
});

test('block package validation reports every problem with its file and line', function (): void {
    $errors = comet_block_errors(fn() => BlockPackage::compile(comet_block_files([
        'comet-package.json' => comet_block_manifest('comet-package.json', ['api_version' => 2, 'scripts' => ['build' => 'x']]),
        'blocks/callout/block.json' => comet_block_manifest('blocks/callout/block.json', ['name' => 'cometcms/callout']),
        'blocks/faq/template.html' => "<details>\n  <summary style=\"color: red\">{{group}}</summary>\n  {{{answer}}}\n</details>",
        'blocks/feature/style.css' => ".x { background: url(images/bg.png); }",
        'blocks/empty/style.css' => '',
    ])));
    assert_same([
        'comet-package.json: Unknown field "scripts". Allowed: api_version, name, title, description, version, author, license, homepage.',
        'comet-package.json: api_version must be 1.',
        'blocks/callout/block.json: Remove "name": a block is named after its package vendor and folder, for example blocks/callout becomes cometcms/callout.',
        'blocks/empty/block.json: Every block needs block.json.',
        'blocks/empty/template.html: Every block needs template.html.',
        'blocks/faq/template.html:3: {{{answer}}}: unknown prop "answer". Declared props: items, group.',
        'blocks/feature/style.css:1: The relative URL "images/bg.png" would resolve against each page\'s address. Reference package files as url(assets/…).',
    ], $errors);
    assert_same(['blocks/faq/template.html:2: The style attribute may only set CSS custom properties from color props, as in style="--accent: {{accent}}". Put other CSS in style.css.'], comet_block_errors(fn() => BlockPackage::compile(comet_block_files([
        'blocks/faq/template.html' => "<details>\n  <summary style=\"color: red\">{{group}}</summary>\n</details>",
    ]))));
    foreach ([
        ['props' => ['tone' => ['type' => 'enum', 'label' => 'Tone', 'options' => ['a'], 'default' => 'b']]],
        ['props' => ['title' => ['type' => 'html', 'label' => 'Title']]],
        ['props' => ['title' => ['type' => 'string', 'label' => 'Title', 'max' => 9000]]],
        ['props' => ['title' => ['type' => 'string']]],
        ['props' => [['type' => 'string', 'label' => 'x']]],
    ] as $change) {
        assert_throws(BlockPackageException::class, fn() => BlockPackage::compile(comet_block_files(['blocks/feature/block.json' => comet_block_manifest('blocks/feature/block.json', $change)])));
    }
    foreach (['name' => 'comet/blocks', 'version' => '1.0', 'title' => ''] as $field => $value) {
        assert_throws(BlockPackageException::class, fn() => BlockPackage::compile(comet_block_files(['comet-package.json' => comet_block_manifest('comet-package.json', [$field => $value])])));
    }
});

test('block templates compile allow-listed HTML and render only escaped values', function (): void {
    $props = [
        'title' => ['type' => 'string', 'max' => 200, 'default' => ''],
        'link' => ['type' => 'url', 'max' => 200, 'default' => '#'],
        'open' => ['type' => 'bool', 'default' => false],
        'tone' => ['type' => 'enum', 'options' => ['info', 'success'], 'default' => 'info'],
    ];
    $compile = static function (string $source) use ($props): array {
        $errors = [];
        $tree = BlockTemplate::compile($source, $props, ['assets/icon.svg' => 'abc.svg'], 'template.html', $errors);
        if ($errors !== []) throw new BlockPackageException($errors);
        return $tree;
    };
    $url = static fn(string $file): string => '/a/' . $file;
    $tree = $compile('{{! note }}<details {{#open}}open{{/open}} class="t-{{tone}}"><summary>{{title}}</summary>{{#tone=success}}<img src="assets/icon.svg" alt="">{{/tone=success}}<a href="{{link}}" target="_blank">Go</a></details>');
    assert_same('<details open class="t-success"><summary>&lt;b&gt;{{tone}}&lt;/b&gt;</summary><img src="/a/abc.svg" alt><a href="https://x.test/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">Go</a></details>',
        BlockTemplate::render($tree, ['open' => true, 'title' => '<b>{{tone}}</b>', 'tone' => 'success', 'link' => 'https://x.test/?a=1&b=2'], $url));
    assert_same('<details class="t-info"><summary></summary><a href="#" target="_blank" rel="noopener noreferrer">Go</a></details>',
        BlockTemplate::render($tree, ['open' => false, 'title' => '', 'tone' => 'info', 'link' => '#'], $url));
    // Parsing normalizes markup, so stored templates are always well-formed.
    assert_same('<ul><li>a</li><li>b &amp; c</li></ul><svg viewbox="0 0 24 24"><path d="M1 1"></path><circle r="2"></circle></svg>',
        BlockTemplate::render($compile('<ul><li>a<li>b & c</ul><svg viewBox="0 0 24 24"><path d="M1 1"/><circle r="2"/></svg>'), [], $url));
    assert_same('<picture><source srcset="/a/abc.svg 1x"><img src="/a/abc.svg" alt=""></picture><p>after</p>',
        BlockTemplate::render($compile('<picture><source srcset="assets/icon.svg 1x"><img src="assets/icon.svg" alt="{{title}}"></picture><p>after</p>'), ['title' => ''], $url));
    foreach ([
        '{{{title}}}', '{{& title}}', '{{> partial}}', '{{title.x}}', '{{unknown}}', '{{#open}}x{{/tone}}', '{{/open}}', '{{#open}}', '{{title',
        '{{#tone=warning}}x{{/tone}}', '{{#open=yes}}x{{/open}}', '<div {{title}}>x</div>', '<div {{#open}}class{{/open}}>x</div>',
        '<script>alert(1)</script>', '<div onclick="x()">x</div>', '<div style="color: red">x</div>', '<iframe src="https://x.test"></iframe>',
        '<a href="{{title}}">x</a>', '<a href="javascript:alert(1)">x</a>', '<a href="{{link}}#top">x</a>', '<img src="logo.png" alt="">',
        '<img src="assets/missing.svg" alt="">', '<div>{{#open}}<p>a</div>{{/open}}', '<p><section>x</section></p>', '<!-- {{title}} -->',
        '<p>a</p></div><p>b</p>', '<button type="submit">x</button>', '<path d="M1"/>', '<svg><div>x</div></svg>', "{{#open\n}}x{{/open}}",
    ] as $invalid) {
        assert_throws(BlockPackageException::class, fn() => $compile($invalid), 'Expected rejection: ' . $invalid);
    }
    assert_throws(BlockPackageException::class, fn() => $compile(str_repeat('{{#open}}', 17) . str_repeat('{{/open}}', 17)));
});

test('custom blocks normalize props, keep translations and work in columns tabs repeaters and component roots', function (): void {
    $blocks = BlockRegistry::active();
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    $block = LayoutSchema::node('block:cometcms/callout', ['title' => "  Hello\nworld  ", 'show_link' => 'yes', 'tone' => 'invalid', 'url' => 'javascript:alert(1)', 'unknown' => 'drop'], ['padding_y' => 'lg']);
    $column = LayoutSchema::node('column', [], [], [
        $block,
        LayoutSchema::node('tabs', [], [], [LayoutSchema::node('tab', [], [], [$block])]),
        LayoutSchema::node('field', [], [], [$block]),
        LayoutSchema::node('collection', [], [], [LayoutSchema::node('column', [], [], [$block])]),
    ]);
    $nodes = LayoutSchema::normalizeComponent([$column], 'column', $blocks);
    $saved = $nodes[0]['children'][0];
    assert_same('Hello world', $saved['props']['title']);
    assert_same(true, $saved['props']['show_link']);
    assert_same('info', $saved['props']['tone']);
    assert_same('#details', $saved['props']['url']);
    assert_false(isset($saved['props']['unknown']));
    foreach ([$nodes[0]['children'][1]['children'][0]['children'][0], $nodes[0]['children'][2]['children'][0], $nodes[0]['children'][3]['children'][0]['children'][0]] as $nested) {
        assert_same('block:cometcms/callout', $nested['kind']);
    }
    assert_same($nodes, LayoutSchema::normalizeComponent($nodes, 'column', $blocks));
    assert_same([], LayoutSchema::normalize([$block], blocks: $blocks));

    $translated = LayoutSchema::normalizeProps('block:cometcms/callout', ['title' => ['type' => 'localized', 'values' => ['en' => "Hi\nthere", 'de' => 'Hallo']]], $blocks);
    assert_same(['en' => 'Hi there', 'de' => 'Hallo'], $translated['title']['values']);
    $blocks->delete('cometcms/essentials');
    $kept = LayoutSchema::normalizeProps('block:cometcms/callout', ['title' => ['type' => 'localized', 'values' => ['en' => 'Hi', 'de' => ['nested']]], 'tone' => 'success'], new BlockRegistry($blocks->workspace));
    assert_same(['title' => ['type' => 'localized', 'values' => ['en' => 'Hi', 'de' => ['nested']]], 'tone' => 'success'], $kept);
});

test('block pages render package CSS once, asset URLs and identical output in previews', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    $callout = LayoutSchema::node('block:cometcms/callout', ['title' => 'Hello <world>', 'message' => 'Text & more', 'tone' => 'success', 'url' => 'https://example.test/info']);
    comet_block_page($site, [$callout, $callout, LayoutSchema::node('block:cometcms/faq')]);
    $site = new SiteRepository();
    $entry = \CometCMS\Content\ContentRepository::make()->findByIdentifier('about', 'about');
    $ctx = comet_site_test_context($site);
    $html = (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
    $check = '/_site/default/blocks/' . hash_file('sha256', COMET_BLOCK_EXAMPLE . '/assets/check.svg') . '.svg';
    $chevron = '/_site/default/blocks/' . hash_file('sha256', COMET_BLOCK_EXAMPLE . '/assets/chevron.svg') . '.svg';
    assert_same(2, substr_count($html, '<h3 class="cometcms-callout__title">Hello &lt;world&gt;</h3>'));
    assert_same(2, substr_count($html, '<img class="cometcms-callout__icon" src="' . $check . '"'));
    assert_same(2, substr_count($html, '<details class="cometcms-faq__item" name="faq">'));
    assert_true(str_contains($html, '<div class="cometcms-faq__answer"><p>It uses the browser\'s native <strong>details</strong> element.</p></div>'));
    assert_same(1, substr_count($html, '<style data-comet-blocks>'));
    assert_same(1, substr_count($html, '--cometcms-accent: var(--c-primary'));
    assert_same(1, substr_count($html, '.c-block-cometcms__callout .cometcms-callout {'));
    assert_true(str_contains($html, 'url("' . $chevron . '")'));
    assert_false(str_contains($html, 'cometcms-feature'));
    assert_true(str_contains($html, 'href="https://example.test/info"'));
    assert_true(str_contains($html, 'class="c-el c-el-block-cometcms-callout c-block-cometcms__callout'));
    $ctx->preview = true;
    $ctx->annotate = true;
    $preview = (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
    assert_same(2, substr_count($preview, '<h3 class="cometcms-callout__title">Hello &lt;world&gt;</h3>'));
    assert_true(str_contains($preview, 'data-kind="block:cometcms/callout"'));
    assert_true(str_contains($preview, "script-src 'none'"));

    $asset = $site->blocks()->asset(basename($check));
    assert_same(['path' => $site->blocks()->directory() . '/cometcms/essentials/assets/check.svg', 'type' => 'image/svg+xml'], $asset);
    assert_null($site->blocks()->asset(str_repeat('0', 64) . '.svg'));
});

test('block package updates that break placed blocks need confirmation', function (): void {
    $blocks = BlockRegistry::active();
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    $manifest = comet_block_manifest('blocks/callout/block.json', []);
    unset($manifest['props']['link_label']);
    $manifest['props']['tone']['options'] = ['info'];
    $update = comet_block_files([
        'comet-package.json' => comet_block_manifest('comet-package.json', ['version' => '2.0.0']),
        'blocks/callout/block.json' => $manifest,
        'blocks/callout/template.html' => '<p class="cometcms-callout cometcms-callout--{{tone}}">{{title}}</p>',
        'blocks/feature/block.json' => null, 'blocks/feature/template.html' => null, 'blocks/feature/style.css' => null,
    ]);
    try {
        $blocks->installFiles($update, allowScripts: true);
        throw new RuntimeException('Expected confirmation to be required.');
    } catch (BlockPackageChanges $e) {
        assert_same(['cometcms/callout: the prop "tone" no longer offers success. Instances using them fall back to the default.',
            'cometcms/callout: the prop "link_label" is removed. Its saved values are dropped the next time a layout is saved.',
            'cometcms/feature is removed. Placed instances will show as missing blocks.'], $e->changes);
        assert_same('1.5.0', $e->installedVersion);
    }
    assert_same('1.5.0', BlockRegistry::active()->package('cometcms/essentials')['version']);
    $result = BlockRegistry::active()->installFiles($update, true, true);
    assert_same('1.5.0', $result['previous_version']);
    assert_same(3, count($result['changes']));
    assert_null(BlockRegistry::active()->get('block:cometcms/feature'));
    $pricing = comet_block_manifest('blocks/pricing/block.json', []);
    unset($pricing['props']['features']['fields']['included']);
    $pricing['props']['features']['default'] = [['text' => 'Unlimited pages']];
    $slotless = array_replace($update, [
        'blocks/card/template.html' => '<div class="cometcms-card">{{title}}</div>',
        'blocks/pricing/block.json' => json_encode($pricing),
        'blocks/pricing/template.html' => '<ul>{{#features}}<li>{{text}}</li>{{/features}}</ul>',
    ]);
    try {
        BlockRegistry::active()->installFiles($slotless, allowScripts: true);
        throw new RuntimeException('Expected confirmation to be required.');
    } catch (BlockPackageChanges $e) {
        assert_same(['cometcms/card no longer has a slot. Elements placed inside it are removed the next time a layout is saved.',
            'cometcms/pricing: the prop "features" item field "included" is removed. Its saved values are dropped the next time a layout is saved.'], $e->changes);
    }
    // Additions alone are not breaking.
    $manifest['props']['subtitle'] = ['type' => 'string', 'label' => 'Subtitle'];
    assert_same([], BlockRegistry::active()->installFiles(array_replace($update, ['blocks/callout/block.json' => json_encode($manifest)]), allowScripts: true)['changes']);
});

test('block names belong to one package and package folders must match their names', function (): void {
    $blocks = BlockRegistry::active();
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    $other = comet_block_files(['comet-package.json' => comet_block_manifest('comet-package.json', ['name' => 'cometcms/other'])]);
    assert_same(['blocks/before-after/: The block cometcms/before-after is already provided by the package cometcms/essentials.'], comet_block_errors(fn() => $blocks->installFiles($other, allowScripts: true)));
    $moved = $blocks->directory() . '/cometcms/renamed';
    mkdir($moved);
    foreach (comet_block_files() as $path => $bytes) {
        if (!is_dir(dirname($moved . '/' . $path))) mkdir(dirname($moved . '/' . $path), 0775, true);
        file_put_contents($moved . '/' . $path, $bytes);
    }
    $metadata = BlockRegistry::active()->metadata();
    assert_same(1, count($metadata['packages']));
    assert_same('cometcms/renamed', $metadata['errors'][0]['package']);
    assert_matches('/installed in site\/blocks\/cometcms\/renamed/', $metadata['errors'][0]['message']);
});

test('the compiled block index is cached until a package file changes', function (): void {
    $blocks = BlockRegistry::active();
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    $cache = $blocks->workspace->path('cache') . '/blocks.json';
    $first = BlockRegistry::active()->all();
    assert_file_exists_at($cache);
    $stored = json_decode((string) file_get_contents($cache), true);
    $stored['blocks']['block:cometcms/callout']['title'] = 'Served from cache';
    file_put_contents($cache, json_encode($stored));
    assert_same('Served from cache', BlockRegistry::active()->get('block:cometcms/callout')['title']);
    file_put_contents($blocks->directory() . '/cometcms/essentials/blocks/callout/block.json', json_encode(comet_block_manifest('blocks/callout/block.json', ['title' => 'Edited directly'])));
    assert_same('Edited directly', BlockRegistry::active()->get('block:cometcms/callout')['title']);
    assert_same(array_keys($first), array_keys(BlockRegistry::active()->all()));
});

test('missing or malformed packages preserve placed block values and do not disable valid packages', function (): void {
    $blocks = BlockRegistry::active();
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    $column = LayoutSchema::node('column', [], [], [LayoutSchema::node('block:cometcms/callout', ['title' => 'Keep me'], ['padding_y' => 'lg'])]);
    $saved = LayoutSchema::normalizeComponent([$column], 'column', $blocks);
    file_put_contents($blocks->directory() . '/cometcms/essentials/blocks/callout/block.json', '{bad');
    $missing = BlockRegistry::active();
    assert_same([], $missing->all());
    assert_same('cometcms/essentials', $missing->metadata()['errors'][0]['package']);
    assert_same('blocks/callout/block.json', $missing->metadata()['errors'][0]['file']);
    assert_same($saved, LayoutSchema::normalizeComponent($saved, 'column', $missing));
    $ctx = comet_site_test_context();
    assert_same('', (new NodeRenderer($ctx))->render($saved[0]['children']));
    $ctx->annotate = true;
    assert_true(str_contains((new NodeRenderer($ctx))->render($saved[0]['children']), 'Missing block: cometcms/callout'));
    $missing->installFiles(comet_block_files(), allowScripts: true);
    assert_true(str_contains((new NodeRenderer(comet_site_test_context()))->render($saved[0]['children']), '>Keep me</h3>'));

    $broken = $blocks->directory() . '/other/broken';
    mkdir($broken, 0775, true);
    file_put_contents($broken . '/comet-package.json', '{broken');
    $registry = BlockRegistry::active();
    assert_same(10, count($registry->all()));
    assert_same('other/broken', $registry->metadata()['errors'][0]['package']);
    $template = $blocks->directory() . '/cometcms/essentials/blocks/callout/template.html';
    unlink($template);
    symlink(COMET_BLOCK_EXAMPLE . '/blocks/callout/template.html', $template);
    assert_same([], BlockRegistry::active()->all());
    // Reinstalling repairs the linked file without following it when the old package is removed.
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    assert_same(10, count(BlockRegistry::active()->all()));
    assert_true(is_file(COMET_BLOCK_EXAMPLE . '/blocks/callout/template.html'));
});

test('direct block edits and uploaded updates invalidate cached public output', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    comet_block_page($site, [LayoutSchema::node('block:cometcms/callout')]);
    $respond = static fn(): array => (new SiteService(comet_site_test_context(new SiteRepository())))->respondCached('/', []);
    assert_true(str_contains($respond()['body'], 'Something worth sharing'));
    assert_same('HIT', $respond()['cache_status']);
    file_put_contents($site->blocks()->directory() . '/cometcms/essentials/blocks/callout/template.html', '<p>Updated directly: {{title}}</p>');
    $updated = $respond();
    assert_same('MISS', $updated['cache_status']);
    assert_true(str_contains($updated['body'], 'Updated directly:'));
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    assert_false(str_contains($respond()['body'], 'Updated directly:'));
});

test('deleting a block package preserves layout instances, invalidates public output and stays within its workspace', function (): void {
    $site = comet_site_test_setup();
    $blocks = $site->blocks();
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    (new BlockRegistry(new WorkspaceContext('other')))->installFiles(comet_block_files(), allowScripts: true);
    comet_block_page($site, [LayoutSchema::node('block:cometcms/callout', ['title' => 'Restore this'])]);
    $saved = $site->layout('about', 'detail');
    $respond = static fn(): array => (new SiteService(comet_site_test_context(new SiteRepository())))->respondCached('/', []);
    assert_true(str_contains($respond()['body'], '>Restore this</h3>'));
    assert_true($blocks->delete('cometcms/essentials'));
    assert_false(is_dir($blocks->directory() . '/cometcms'));
    assert_same([], $blocks->all());
    assert_same($saved, (new SiteRepository())->layout('about', 'detail'));
    assert_same('Callout', (new BlockRegistry(new WorkspaceContext('other')))->get('block:cometcms/callout')['title']);
    assert_false($blocks->delete('cometcms/essentials'));
    assert_throws(InvalidArgumentException::class, fn() => $blocks->delete('../other'));
    $deleted = $respond();
    assert_same('MISS', $deleted['cache_status']);
    assert_false(str_contains($deleted['body'], 'Restore this'));
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    assert_true(str_contains($respond()['body'], '>Restore this</h3>'));
});

test('block packages survive a site backup and restore', function (): void {
    $blocks = BlockRegistry::active();
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    $backup = (new \CometCMS\Backups\BackupService())->create(['id' => 'admin'], ['site']);
    comet_test_remove_directory($blocks->directory());
    assert_same([], BlockRegistry::active()->all());
    (new \CometCMS\Backups\RestoreService())->restore(COMET_STORAGE . '/backups/' . $backup['name'], true, ['id' => 'admin'], ['site']);
    assert_same('Callout', BlockRegistry::active()->get('block:cometcms/callout')['title']);
    assert_same(comet_block_files()['assets/check.svg'], file_get_contents($blocks->directory() . '/cometcms/essentials/assets/check.svg'));
});

test('block package uploads report every problem and ask before breaking placed blocks', function (): void {
    [$status, $body] = comet_block_upload(comet_block_zip(comet_block_files()), ['allow_scripts' => '1']);
    assert_same(201, $status);
    assert_same('cometcms/essentials', $body['data']['name']);
    [$status, $body] = comet_block_upload(comet_block_zip(comet_block_files(['blocks/faq/template.html' => '<p>{{missing}}</p>', 'notes.txt' => 'x'])));
    assert_same(422, $status);
    assert_same('invalid_block_package', $body['error']['code']);
    assert_same(['notes.txt', 'blocks/faq/template.html'], array_column($body['error']['details'], 'file'));
    assert_same(1, $body['error']['details'][1]['line']);
    $update = comet_block_zip(comet_block_files(['blocks/feature/block.json' => null, 'blocks/feature/template.html' => null, 'blocks/feature/style.css' => null]));
    $seed = '\\CometCMS\\Site\\Blocks\\BlockRegistry::active()->installFiles(\\CometCMS\\Site\\Blocks\\BlockPackage::readDirectory(' . var_export(COMET_BLOCK_EXAMPLE, true) . '), allowScripts: true);';
    [$status, $body] = comet_block_upload($update, ['allow_scripts' => '1'], $seed);
    assert_same(409, $status);
    assert_same('block_package_changes', $body['error']['code']);
    assert_same(['cometcms/feature is removed. Placed instances will show as missing blocks.'], $body['error']['details']['changes']);
    [$status, $body] = comet_block_upload($update, ['accept_changes' => '1', 'allow_scripts' => '1'], $seed);
    assert_same(201, $status);
    assert_same(['cometcms/before-after', 'cometcms/callout', 'cometcms/card', 'cometcms/faq', 'cometcms/maps', 'cometcms/pricing', 'cometcms/slideshow', 'cometcms/stats', 'cometcms/testimonial'], $body['data']['blocks']);
});

test('block inventory requires site permission and installation and deletion require an administrator and CSRF', function (): void {
    $action = '(new \\CometCMS\\Controllers\\Admin\\SiteController(new \\CometCMS\\Core\\Http()))->';
    $seed = '$users->create("ed", "secret-password", "editor");';
    [$status] = comet_admin_request('ed', $seed, 'POST', '/admin/api/site/blocks', $action . 'installBlock();');
    assert_same(403, $status);
    [$status, $body] = comet_admin_request('admin', '', 'GET', '/admin/api/site/blocks', $action . 'blocks();');
    assert_same(200, $status);
    assert_same(['packages' => [], 'errors' => [], 'can_install' => true], $body['data']);
    [$status] = comet_admin_request('admin', '', 'POST', '/admin/api/site/blocks', 'unset($_SERVER["HTTP_X_CSRF_TOKEN"]);' . $action . 'installBlock();');
    assert_same(419, $status);
    [$status, $body] = comet_admin_request('admin', '', 'POST', '/admin/api/site/blocks', $action . 'installBlock();');
    assert_same(422, $status);
    assert_same('Upload one block package as a .zip file.', $body['error']['message']);

    $delete = $action . 'deleteBlock("cometcms", "essentials");';
    $uri = '/admin/api/site/blocks/cometcms/essentials';
    [$status] = comet_admin_request('ed', $seed, 'DELETE', $uri, $delete);
    assert_same(403, $status);
    [$status] = comet_admin_request('admin', '', 'DELETE', $uri, 'unset($_SERVER["HTTP_X_CSRF_TOKEN"]);' . $delete);
    assert_same(419, $status);
    [$status] = comet_admin_request('admin', '', 'DELETE', $uri, $delete);
    assert_same(404, $status);
    $install = '\\CometCMS\\Site\\Blocks\\BlockRegistry::active()->installFiles(\\CometCMS\\Site\\Blocks\\BlockPackage::readDirectory(' . var_export(COMET_BLOCK_EXAMPLE, true) . '), allowScripts: true);';
    [$status, $body] = comet_admin_request('admin', $install, 'DELETE', $uri, $delete);
    assert_same(200, $status);
    assert_same('cometcms/essentials', $body['data']['name']);
});

/** A one-block package around the given block.json props and template. */
function comet_block_single(array $props, string $template, array $extra = []): array
{
    return [
        'comet-package.json' => json_encode(['api_version' => 1, 'name' => 'test/kit', 'title' => 'Kit', 'version' => '1.0.0']),
        'blocks/thing/block.json' => json_encode(['title' => 'Thing', 'props' => $props], JSON_UNESCAPED_SLASHES),
        'blocks/thing/template.html' => $template,
    ] + $extra;
}

function comet_block_render(array $node, array $state = [], bool $annotate = false): string
{
    $ctx = comet_site_test_context();
    $ctx->annotate = $annotate;

    return (new NodeRenderer($ctx))->render([$node], $state);
}

test('block icons come from an icon name, the block folder or the package root', function (): void {
    $blocks = BlockRegistry::active();
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    $spec = LayoutSchema::spec(blocks: $blocks)['kinds'];
    assert_same('mdi:card-text-outline', $spec['block:cometcms/card']['icon']);
    assert_same('data:image/svg+xml;base64,' . base64_encode(comet_block_files()['blocks/feature/icon.svg']), $spec['block:cometcms/feature']['icon']);
    assert_same('data:image/svg+xml;base64,' . base64_encode(comet_block_files()['icon.svg']), $spec['block:cometcms/callout']['icon']);
    assert_same(['blocks/feature/block.json: The block has both an "icon" name and an icon file. Keep one.'], comet_block_errors(fn() => BlockPackage::compile(comet_block_files([
        'blocks/feature/block.json' => comet_block_manifest('blocks/feature/block.json', ['icon' => 'mdi:star']),
    ]))));
    foreach ([['blocks/card/block.json' => comet_block_manifest('blocks/card/block.json', ['icon' => 'star'])], ['icon.svg' => '<svg onload="alert(1)"></svg>'], ['icon.png' => 'not a png']] as $change) {
        assert_throws(BlockPackageException::class, fn() => BlockPackage::compile(comet_block_files($change)));
    }
});

test('block prop schemas cover text, rich text, links, colors, media and lists with checked defaults', function (): void {
    $package = BlockPackage::compile(comet_block_single([
        'body' => ['type' => 'richtext', 'label' => 'Body', 'default' => '<p onclick="x()">Hi <script>bad()</script></p>'],
        'cta' => ['type' => 'link', 'label' => 'Link', 'default' => '/contact'],
        'tint' => ['type' => 'color', 'label' => 'Tint'],
        'rows' => ['type' => 'list', 'label' => 'Rows', 'max' => 3, 'fields' => [
            'label' => ['type' => 'string', 'label' => 'Label'],
            'href' => ['type' => 'link', 'label' => 'Link'],
        ], 'default' => [['label' => 'One', 'href' => 'https://x.test'], []]],
    ], '<div>{{{body}}}{{#rows}}<a href="{{href}}">{{label}}</a>{{/rows}}</div>'));
    $props = $package['blocks']['block:test/thing']['props'];
    assert_same('<p>Hi </p>', $props['body']['default']);
    assert_same(['type' => 'url', 'url' => '/contact'], $props['cta']['default']);
    assert_same('', $props['tint']['default']);
    assert_same([['label' => 'One', 'href' => ['type' => 'url', 'url' => 'https://x.test']], ['label' => '', 'href' => ['type' => 'none']]], $props['rows']['default']);
    foreach ([
        ['media' => ['type' => 'media', 'label' => 'Photo', 'default' => 'photo.jpg']],
        ['tint' => ['type' => 'color', 'label' => 'Tint', 'default' => 'hotpink']],
        ['cta' => ['type' => 'link', 'label' => 'Link', 'default' => 'javascript:alert(1)']],
        ['rows' => ['type' => 'list', 'label' => 'Rows', 'fields' => []]],
        ['rows' => ['type' => 'list', 'label' => 'Rows', 'fields' => ['inner' => ['type' => 'list', 'label' => 'Inner', 'fields' => ['a' => ['type' => 'bool', 'label' => 'A']]]]]],
        ['rows' => ['type' => 'list', 'label' => 'Rows', 'max' => 1, 'fields' => ['a' => ['type' => 'bool', 'label' => 'A']], 'default' => [[], []]]],
        ['rows' => ['type' => 'list', 'label' => 'Rows', 'fields' => ['a' => ['type' => 'bool', 'label' => 'A']], 'default' => [['b' => true]]]],
        ['note' => ['type' => 'text', 'label' => 'Note', 'max' => 3, 'default' => "ab\ncd"]],
    ] as $props) {
        assert_throws(BlockPackageException::class, fn() => BlockPackage::compile(comet_block_single($props, '<p>x</p>')), json_encode($props));
    }
});

test('block templates iterate lists, place rich text and slots, and use media, color and link values by context', function (): void {
    $props = [
        'title' => ['type' => 'string', 'max' => 200, 'default' => ''],
        'body' => ['type' => 'richtext', 'default' => ''],
        'note' => ['type' => 'text', 'max' => 200, 'default' => ''],
        'photo' => ['type' => 'media', 'default' => ''],
        'tint' => ['type' => 'color', 'default' => ''],
        'cta' => ['type' => 'link', 'default' => ['type' => 'none']],
        'rows' => ['type' => 'list', 'max' => 5, 'default' => [], 'fields' => [
            'label' => ['type' => 'string', 'max' => 50, 'default' => ''],
            'on' => ['type' => 'bool', 'default' => false],
        ]],
    ];
    $compile = static function (string $source) use ($props): array {
        $errors = [];
        $tree = BlockTemplate::compile($source, $props, [], 'template.html', $errors);
        if ($errors !== []) throw new BlockPackageException($errors);
        return $tree;
    };
    $tree = $compile('<section class="t-{{tint}}" style="--tint: {{tint}}"><img src="{{photo}}" alt="{{photo.alt}}"><div>{{{body}}}</div><p>{{note}}</p>'
        . '<ul>{{#rows}}<li class="{{#on}}on{{/on}}">{{label}} / {{title}}</li>{{/rows}}{{^rows}}<li>none</li>{{/rows}}</ul>'
        . '<a href="{{cta}}">Go</a><div><slot></slot></div></section>');
    assert_true(BlockTemplate::hasSlot($tree));
    $values = [
        'title' => 'T', 'body' => '<p>Rich</p>', 'note' => "a\nb<c>", 'photo' => ['_' => '/m/p.jpg', 'alt' => 'A "photo"'], 'tint' => ['_' => 'accent', 'css' => 'var(--c-accent)'],
        'cta' => ['_' => '/about', 'new_tab' => true], 'rows' => [['label' => 'One', 'on' => true], ['label' => 'Two', 'on' => false]],
    ];
    assert_same('<section class="t-accent" style="--tint:var(--c-accent)"><img src="/m/p.jpg" alt="A &quot;photo&quot;"><div><p>Rich</p></div><p>a<br>' . "\n" . 'b&lt;c&gt;</p>'
        . '<ul><li class="on">One / T</li><li class="">Two / T</li></ul><a href="/about" target="_blank" rel="noopener noreferrer">Go</a><div><b>child</b></div></section>',
        BlockTemplate::render($tree, $values, static fn(string $file): string => $file, '<b>child</b>'));
    assert_true(str_contains(BlockTemplate::render($tree, ['rows' => [], 'tint' => ['_' => '', 'css' => 'initial'], 'cta' => ['_' => '#']] + $values, static fn(string $file): string => $file), '<ul><li>none</li></ul><a href="#">'));
    foreach ([
        '<p>{{body}}</p>', '<p>{{{title}}}</p>', '<p>{{{body}}}</p>', '<a title="{{{body}}}">x</a>', '<p>{{rows}}</p>', '{{#rows=x}}a{{/rows}}',
        '<p>{{photo.size}}</p>', '<p>{{title.alt}}</p>', '<p>{{label}}</p>', '<img src="{{title}}" alt="">', '<img src="{{photo.alt}}" alt="">',
        '<img src="{{cta}}" alt="">', '<div style="color: {{tint}}">x</div>', '<div style="--x: {{title}}">x</div>', '<div style="--x: red">x</div>',
        '<div><slot></slot><slot></slot></div>', '{{#title}}<div><slot></slot></div>{{/title}}', '<p><slot></slot></p>', '<div><slot>fallback</slot></div>',
        '{{> slot}}',
    ] as $invalid) {
        assert_throws(BlockPackageException::class, fn() => $compile($invalid), 'Expected rejection: ' . $invalid);
    }
});

test('rendered blocks resolve media, theme colors, links, lists, bindings and translated lists', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    comet_media_test_file('hero.jpg', 'jpeg');
    (new \CometCMS\Media\MediaRepository())->updateMeta('hero.jpg', 'A hero', 'Hero');
    $entry = \CometCMS\Content\ContentRepository::make()->findByIdentifier('about', 'about');
    $card = LayoutSchema::node('block:cometcms/card', [
        'image' => 'hero.jpg',
        'title' => ['type' => 'reference', 'source' => 'current', 'path' => ['title'], 'filters' => []],
        'accent' => 'accent',
        'link' => ['type' => 'home', 'new_tab' => true],
    ]);
    $html = comet_block_render($card, ['entry' => $entry, 'type' => $site->types()['about']]);
    assert_true(str_contains($html, 'style="--cometcms-card-accent:var(--c-accent)"'));
    assert_true(str_contains($html, '<img class="cometcms-card__image" src="/media/default/hero.jpg" alt="A hero" loading="lazy">'));
    assert_true(str_contains($html, '<h3 class="cometcms-card__title">' . htmlspecialchars($entry['title']) . '</h3>'));
    assert_true(str_contains($html, '<a class="cometcms-card__link" href="/" target="_blank" rel="noopener noreferrer">Read more</a>'));
    assert_same('text', LayoutSchema::spec(blocks: $site->blocks())['kinds']['block:cometcms/card']['props']['title']['binding']);

    $plain = comet_block_render(LayoutSchema::node('block:cometcms/card', ['accent' => '']));
    assert_true(str_contains($plain, 'style="--cometcms-card-accent:initial"'));
    assert_false(str_contains($plain, '<img'));
    assert_false(str_contains($plain, 'cometcms-card__link'));

    $pricing = comet_block_render(LayoutSchema::node('block:cometcms/pricing', ['note' => "Billed yearly.\nCancel anytime."]));
    assert_same(3, substr_count($pricing, '<li class="cometcms-plan__feature'));
    assert_true(str_contains($pricing, '<li class="cometcms-plan__feature cometcms-plan__feature--excluded">Priority support</li>'));
    assert_true(str_contains($pricing, '<a class="cometcms-plan__cta" href="#signup">Choose plan</a>'));
    assert_true(str_contains($pricing, '<p class="cometcms-plan__note">Billed yearly.<br>' . "\n" . 'Cancel anytime.</p>'));

    (new \CometCMS\Localization\LanguageSettings())->save(['default_locale' => 'en', 'locales' => ['en', 'de']]);
    $faq = LayoutSchema::node('block:cometcms/faq', ['items' => ['type' => 'localized', 'values' => [
        'en' => [['question' => 'Why?', 'answer' => '<p>Because.</p>']],
        'de' => [['question' => 'Warum?', 'answer' => '<p>Darum.<script>x</script></p>']],
    ]]]);
    $faq['props'] = LayoutSchema::normalizeProps('block:cometcms/faq', $faq['props'], $site->blocks());
    assert_same('<p>Darum.</p>', $faq['props']['items']['values']['de'][0]['answer']);
    $ctx = comet_site_test_context();
    $ctx->locale = 'de';
    $translated = (new NodeRenderer($ctx))->render([$faq]);
    assert_true(str_contains($translated, '<summary class="cometcms-faq__question">Warum?</summary>'));
    assert_false(str_contains($translated, 'Why?'));
});

test('slot blocks hold child elements in layouts, repeaters and while their package is missing', function (): void {
    $blocks = BlockRegistry::active();
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    $spec = LayoutSchema::spec(blocks: $blocks)['kinds'];
    assert_true($spec['block:cometcms/card']['slot']);
    assert_false($spec['block:cometcms/callout']['slot']);
    assert_true(in_array('heading', $spec['block:cometcms/card']['children'], true));
    assert_true(in_array('block:cometcms/card', $spec['block:cometcms/card']['children'], true));
    assert_false(in_array('row', $spec['block:cometcms/card']['children'], true));
    assert_same([], $spec['block:cometcms/callout']['children']);

    $card = LayoutSchema::node('block:cometcms/card', [], [], [
        LayoutSchema::node('text', ['html' => '<p>Inside</p>']),
        LayoutSchema::node('section'),
        LayoutSchema::node('tabs'),
    ]);
    $column = LayoutSchema::node('column', [], [], [$card, LayoutSchema::node('field', [], [], [$card]), LayoutSchema::node('block:cometcms/callout', [], [], [LayoutSchema::node('text')])]);
    $saved = LayoutSchema::normalizeComponent([$column], 'column', $blocks)[0]['children'];
    assert_same(['text', 'tabs'], array_column($saved[0]['children'], 'kind'));
    assert_same(['text'], array_column($saved[1]['children'][0]['children'], 'kind'));
    assert_same([], $saved[2]['children']);

    $html = comet_block_render($saved[0]);
    assert_true(str_contains($html, '<div class="cometcms-card__body">'));
    assert_matches('~<h3 class="cometcms-card__title">Card heading</h3>\s*<div[^>]*c-el-text[^>]*>.*Inside~s', $html);
    assert_true(str_contains(comet_block_render(LayoutSchema::node('block:cometcms/card'), [], true), 'Drop elements here'));

    $blocks->delete('cometcms/essentials');
    $missing = new BlockRegistry($blocks->workspace);
    $kept = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$saved[0]])], 'column', $missing)[0]['children'][0];
    assert_same(['text', 'tabs'], array_column($kept['children'], 'kind'));
});

test('block scripts are single self-contained modules with a default export', function (): void {
    $errors = comet_block_errors(fn() => BlockPackage::compile(comet_block_files([
        'blocks/card/view.js' => "import confetti from 'confetti';\nconsole.log('</script>');\n",
        'blocks/missing/view.js' => 'export default () => {};',
    ])));
    assert_same([
        'blocks/card/view.js: view.js cannot contain "</script".',
        'blocks/card/view.js: view.js must export its mount function: export default function (element, props) { … }',
        'blocks/card/view.js:1: view.js must be self-contained: imports are not allowed. Bundle dependencies into the file, for example with esbuild --bundle --format=esm.',
        'blocks/missing/view.js: There is no block "missing" for this script. Add block.json and template.html next to it.',
    ], $errors);
    $package = BlockPackage::compile(comet_block_files(['blocks/card/view.js' => "const importantValue = 1;\nexport default function (element) { element.dataset.value = importantValue; }"]));
    assert_same(hash('sha256', comet_block_files()['blocks/stats/view.js']) . '.js', $package['blocks']['block:cometcms/stats']['script']);
    assert_true($package['blocks']['block:cometcms/card']['script'] !== '');
    assert_same('', $package['blocks']['block:cometcms/callout']['script']);
});

test('installing block scripts needs explicit consent and is reported in the inventory', function (): void {
    $blocks = BlockRegistry::active();
    try {
        $blocks->installFiles(comet_block_files());
        throw new RuntimeException('Expected consent to be required.');
    } catch (\CometCMS\Site\Blocks\BlockPackageScripts $e) {
        assert_same('cometcms/essentials', $e->package);
        assert_same(['cometcms/before-after', 'cometcms/maps', 'cometcms/slideshow', 'cometcms/stats'], $e->blocks);
    }
    assert_same([], BlockRegistry::active()->all());
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    $metadata = BlockRegistry::active()->metadata()['packages'][0];
    assert_true($metadata['scripts']);
    assert_same(['cometcms/before-after', 'cometcms/maps', 'cometcms/slideshow', 'cometcms/stats'], array_column(array_filter($metadata['blocks'], static fn(array $block): bool => $block['script']), 'name'));
    assert_true(LayoutSchema::spec(blocks: BlockRegistry::active())['kinds']['block:cometcms/stats']['script']);
    // Packages without scripts install as before.
    $plain = comet_block_files(['blocks/before-after/view.js' => null, 'blocks/maps/view.js' => null, 'blocks/slideshow/view.js' => null, 'blocks/stats/view.js' => null, 'blocks/stats/block.json' => null, 'blocks/stats/template.html' => null, 'blocks/stats/style.css' => null]);
    assert_same([], BlockRegistry::active()->installFiles($plain, true)['scripts']);
    $file = hash('sha256', comet_block_files()['blocks/stats/view.js']) . '.js';
    assert_null(BlockRegistry::active()->asset($file));
    BlockRegistry::active()->installFiles(comet_block_files(), allowScripts: true);
    assert_same('text/javascript', BlockRegistry::active()->asset($file)['type']);
});

test('published pages and previews mount block scripts through exact CSP sources', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    $stats = LayoutSchema::node('block:cometcms/stats', ['duration' => 800]);
    comet_block_page($site, [$stats, $stats, LayoutSchema::node('block:cometcms/callout')]);
    $site = new SiteRepository();
    $entry = \CometCMS\Content\ContentRepository::make()->findByIdentifier('about', 'about');
    $ctx = comet_site_test_context($site);
    $html = (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
    $url = '/_site/default/blocks/' . hash('sha256', comet_block_files()['blocks/stats/view.js']) . '.js';
    $loader = \CometCMS\Site\Blocks\BlockScripts::loader('cometcms/stats', $url);
    assert_same(1, substr_count($html, '<script type="module">' . $loader . '</script>'));
    assert_same(2, substr_count($html, 'data-comet-block="cometcms/stats"'));
    assert_true(str_contains($html, 'data-comet-props="{&quot;items&quot;:[{&quot;value&quot;:250,&quot;suffix&quot;:&quot;+&quot;,&quot;label&quot;:&quot;Websites&quot;}'));
    assert_true(str_contains($html, '&quot;duration&quot;:800}"'));
    assert_false(str_contains($html, 'data-comet-block="cometcms/callout"'));
    assert_same(["'sha256-" . base64_encode(hash('sha256', $loader, true)) . "'", 'example.test' . $url], \CometCMS\Site\Blocks\BlockScripts::cspSources($html, $ctx));
    // Only loaders that match an installed script exactly are allowed.
    $tampered = str_replace($url, '/_site/default/blocks/' . str_repeat('a', 64) . '.js', $html);
    assert_same([], \CometCMS\Site\Blocks\BlockScripts::cspSources($tampered, $ctx));

    $ctx->preview = true;
    $preview = (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
    assert_true(str_contains($preview, 'data-comet-block='));
    assert_true(str_contains($preview, 'import mount from'));
    $builder = comet_site_test_context($site);
    $builder->annotate = true;
    assert_true(str_contains((new NodeRenderer($builder))->render([$stats]), 'data-comet-block='));
    $builder->preview = true;
    $annotated = (new PageRenderer($builder))->entryPage($site->types()['about'], $entry);
    assert_true(str_contains($annotated, 'data-node='));
    assert_same(1, substr_count($annotated, '<script type="module">' . $loader . '</script>'));
    foreach (\CometCMS\Site\Blocks\BlockScripts::cspSources($annotated, $builder) as $source) {
        assert_true(str_contains($annotated, $source));
    }
    assert_true(str_contains($preview, \CometCMS\Site\SiteScripts::cspDirective($preview, $ctx)));
});

test('block package uploads with scripts ask for consent before installing', function (): void {
    [$status, $body] = comet_block_upload(comet_block_zip(comet_block_files()));
    assert_same(409, $status);
    assert_same('block_package_scripts', $body['error']['code']);
    assert_same(['package' => 'cometcms/essentials', 'blocks' => ['cometcms/before-after', 'cometcms/maps', 'cometcms/slideshow', 'cometcms/stats']], $body['error']['details']);
    [$status, $body] = comet_block_upload(comet_block_zip(comet_block_files()), ['allow_scripts' => '1']);
    assert_same(201, $status);
    assert_same(['cometcms/before-after', 'cometcms/maps', 'cometcms/slideshow', 'cometcms/stats'], $body['data']['scripts']);
});

test('example slideshows render translated media slides and load their script in previews', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    comet_media_test_file('slide.jpg', 'jpeg');
    (new \CometCMS\Media\MediaRepository())->updateMeta('slide.jpg', 'A "slide" & more', 'Slide');
    $slides = ['type' => 'localized', 'values' => [
        'en' => [['title' => 'Our <story>', 'caption' => "First line\nSecond line", 'image' => 'slide.jpg', 'link' => ['type' => 'url', 'url' => 'https://example.test/story', 'new_tab' => true], 'link_label' => 'Read & explore']],
        'de' => [['title' => 'Unsere Geschichte', 'caption' => 'Mehr erfahren']],
    ]];
    $node = LayoutSchema::node('block:cometcms/slideshow', ['slides' => $slides]);
    comet_block_page($site, [$node, LayoutSchema::node('block:cometcms/slideshow', ['slides' => []])]);
    $site = new SiteRepository();
    $ctx = comet_site_test_context($site);
    $entry = $ctx->findEntry('about', 'about');
    $html = (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
    assert_true(str_contains($html, '<h3 class="cometcms-slideshow__title">Our &lt;story&gt;</h3>'));
    assert_true(str_contains($html, 'src="/media/default/slide.jpg" alt="A &quot;slide&quot; &amp; more"'));
    assert_true(str_contains($html, 'href="https://example.test/story" target="_blank" rel="noopener noreferrer">Read &amp; explore</a>'));
    assert_true(str_contains($html, 'Add slides to start your slideshow.'));
    assert_false(str_contains($html, 'Unsere Geschichte'));
    $script = hash_file('sha256', COMET_BLOCK_EXAMPLE . '/blocks/slideshow/view.js') . '.js';
    $loader = \CometCMS\Site\Blocks\BlockScripts::loader('cometcms/slideshow', '/_site/default/blocks/' . $script);
    assert_same(1, substr_count($html, '<script type="module">' . $loader . '</script>'));
    assert_same(["'sha256-" . base64_encode(hash('sha256', $loader, true)) . "'", 'example.test/_site/default/blocks/' . $script], \CometCMS\Site\Blocks\BlockScripts::cspSources($html, $ctx));

    $ctx->preview = true;
    $preview = (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
    assert_true(str_contains($preview, 'Our &lt;story&gt;'));
    assert_true(str_contains($preview, 'import mount'));
    assert_true(str_contains($preview, 'data-comet-block='));
    $ctx->locale = 'de';
    $translated = (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
    assert_true(str_contains($translated, 'Unsere Geschichte'));
    assert_false(str_contains($translated, 'Our &lt;story&gt;'));
});

test('integer block controls expose sliders and preserve bounded values', function (): void {
    $blocks = BlockRegistry::active();
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    $zoom = LayoutSchema::spec(blocks: $blocks)['kinds']['block:cometcms/maps']['props']['zoom'];
    assert_same('int', $zoom['type']);
    assert_same('slider', $zoom['control']);
    assert_same(1, LayoutSchema::normalizeProps('block:cometcms/maps', ['zoom' => -50], $blocks)['zoom']);
    assert_same(19, LayoutSchema::normalizeProps('block:cometcms/maps', ['zoom' => 100], $blocks)['zoom']);
    assert_same(13, LayoutSchema::normalizeProps('block:cometcms/maps', ['zoom' => 'invalid'], $blocks)['zoom']);
    foreach ([['type' => 'string', 'control' => 'slider'], ['type' => 'int', 'control' => 'unknown']] as $change) {
        $manifest = comet_block_manifest('blocks/maps/block.json', []);
        $manifest['props']['zoom'] = ['label' => 'Zoom'] + $change;
        assert_throws(BlockPackageException::class, fn() => BlockPackage::compile(comet_block_files(['blocks/maps/block.json' => $manifest])));
    }
    // The same integer control works in a list item's inspector.
    $manifest = comet_block_manifest('blocks/stats/block.json', []);
    $manifest['props']['items']['fields']['value']['control'] = 'slider';
    $package = BlockPackage::compile(comet_block_files(['blocks/stats/block.json' => $manifest]));
    assert_same('slider', $package['blocks']['block:cometcms/stats']['props']['items']['fields']['value']['control']);
});

test('maps render escaped location labels and include their script in editor previews', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    $node = LayoutSchema::node('block:cometcms/maps', ['label' => 'Our <office>', 'location' => '0, 0', 'zoom' => 7]);
    comet_block_page($site, [$node]);
    $context = comet_site_test_context($site);
    $type = $site->types()['about'];
    $entry = \CometCMS\Content\ContentRepository::make()->findByIdentifier('about', 'about');
    $html = (new PageRenderer($context))->entryPage($type, $entry);
    assert_true(str_contains($html, '<strong>Our &lt;office&gt;</strong>'));
    assert_true(str_contains($html, '<span>0, 0</span>'));
    assert_true(str_contains($html, '© OpenStreetMap contributors'));
    assert_true(str_contains($html, 'data-comet-block="cometcms/maps"'));
    assert_true(str_contains($html, '&quot;zoom&quot;:7'));
    $context->preview = true;
    $preview = (new PageRenderer($context))->entryPage($type, $entry);
    assert_true(str_contains($preview, 'The interactive map loads on the published website.'));
    assert_true(str_contains($preview, 'import mount'));
    assert_false(str_contains($preview, 'tile.openstreetmap.org'));
});

test('example comparison and testimonial blocks render safe translated content and media with preview fallbacks', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    comet_media_test_file('before.jpg', 'jpeg');
    comet_media_test_file('after.jpg', 'jpeg');
    (new \CometCMS\Media\MediaRepository())->updateMeta('before.jpg', 'Before "renovation"', 'Before');
    $comparison = LayoutSchema::node('block:cometcms/before-after', [
        'before_image' => 'before.jpg', 'after_image' => 'after.jpg', 'position' => 140,
        'before_label' => ['type' => 'localized', 'values' => ['en' => 'Before <work>', 'de' => 'Vorher']],
        'caption' => "First line\nSecond line",
    ], ['background' => 'primary', 'text_color' => 'inverse']);
    assert_same(100, LayoutSchema::normalizeProps('block:cometcms/before-after', $comparison['props'])['position']);
    $testimonial = LayoutSchema::node('block:cometcms/testimonial', [
        'quote' => ['type' => 'localized', 'values' => ['en' => 'A <wonderful> result & team.', 'de' => 'Ein tolles Ergebnis.']],
        'name' => 'Alex <Morgan>', 'portrait' => 'before.jpg', 'rating' => '4', 'layout' => 'centered',
    ]);
    comet_block_page($site, [$comparison, $testimonial, LayoutSchema::node('block:cometcms/before-after'), LayoutSchema::node('block:cometcms/testimonial', ['name' => '', 'role' => '', 'company' => ''])]);
    $site = new SiteRepository();
    $ctx = comet_site_test_context($site);
    $entry = $ctx->findEntry('about', 'about');
    $html = (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
    assert_true(str_contains($html, 'src="/media/default/before.jpg" alt="Before &quot;renovation&quot;"'));
    assert_true(str_contains($html, 'src="/media/default/after.jpg"'));
    assert_true(str_contains($html, 'Before &lt;work&gt;'));
    assert_true(str_contains($html, 'A &lt;wonderful&gt; result &amp; team.'));
    assert_true(str_contains($html, 'Alex &lt;Morgan&gt;'));
    assert_true(str_contains($html, 'aria-label="Rating: 4 / 5"'));
    assert_true(str_contains($html, '★★★★☆'));
    assert_same(1, substr_count($html, 'cometcms-testimonial__rating"'));
    assert_true(str_contains($html, 'cometcms-testimonial--centered'));
    assert_true(str_contains($html, 'Choose an image to compare.'));
    assert_true(str_contains($html, 'data-control hidden'));
    assert_true(str_contains($html, 'c-bg-primary c-fg-inverse'));
    assert_same(2, substr_count($html, 'data-comet-block="cometcms/before-after"'));
    assert_false(str_contains($html, 'data-comet-block="cometcms/testimonial"'));
    $script = hash_file('sha256', COMET_BLOCK_EXAMPLE . '/blocks/before-after/view.js') . '.js';
    $loader = \CometCMS\Site\Blocks\BlockScripts::loader('cometcms/before-after', '/_site/default/blocks/' . $script);
    assert_same(1, substr_count($html, '<script type="module">' . $loader . '</script>'));

    $ctx->preview = true;
    $preview = (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
    assert_true(str_contains($preview, 'Before &lt;work&gt;'));
    assert_true(str_contains($preview, 'import mount'));
    assert_false(str_contains($preview, ' data-ready='));
    $ctx->locale = 'de';
    $translated = (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
    assert_true(str_contains($translated, '>Vorher</span>'));
    assert_true(str_contains($translated, 'Ein tolles Ergebnis.'));
    assert_false(str_contains($translated, 'A &lt;wonderful&gt;'));
});

test('block packages keep custom content and scripts in named page templates', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    $template = $site->createTemplate('about', 'detail', 'Custom blocks');
    $nodes = [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [
        LayoutSchema::node('block:cometcms/callout', ['title' => 'Named template block']),
        LayoutSchema::node('block:cometcms/stats'),
    ])])])];
    $saved = $site->saveLayout('about', 'detail', $nodes, area: $template['id']);
    assert_same($saved, (new SiteRepository())->layout('about', 'detail', $template['id']));
    assert_false($site->layoutCustomized('about', 'detail'));
    $copy = $site->createTemplate('about', 'detail', 'Copied blocks', $template['id']);
    assert_same($saved, $site->layout('about', 'detail', $copy['id']));
    $ctx = comet_site_test_context($site);
    $html = (new PageRenderer($ctx))->entryPage($ctx->type('about'), $ctx->findEntry('about', 'about'));
    assert_true(str_contains($html, 'Named template block'));
    assert_true(str_contains($html, 'data-comet-block="cometcms/stats"'));
    assert_true(str_contains($html, 'data-comet-blocks'));
    assert_false(\CometCMS\Site\SiteScripts::cspDirective($html, $ctx) === "script-src 'none'");
});

test('modal templates save block packages alongside the linked page and modal controls', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    $spec = LayoutSchema::spec(modal: true, blocks: $site->blocks());
    assert_true(in_array('block:cometcms/callout', $spec['kinds'][LayoutSchema::MODAL]['children'], true));
    $nodes = [LayoutSchema::node(LayoutSchema::MODAL, [], [], [
        LayoutSchema::node('block:cometcms/callout', ['title' => 'Modal package content']),
        LayoutSchema::node('modal_close'),
        LayoutSchema::node(LayoutSchema::SITE_CONTENT),
    ])];
    $saved = $site->saveRegion(LayoutSchema::MODAL, $nodes);
    assert_same(['block:cometcms/callout', 'modal_close', LayoutSchema::SITE_CONTENT], array_column($saved[0]['children'], 'kind'));
    assert_same($saved, (new SiteRepository())->region(LayoutSchema::MODAL));
    $ctx = comet_site_test_context($site);
    $ctx->preview = $ctx->annotate = true;
    $html = (new PageRenderer($ctx))->regionPreview(LayoutSchema::MODAL);
    assert_true(str_contains($html, 'Modal package content'));
    assert_true(str_contains($html, 'data-kind="site_content"'));
    assert_true(str_contains($html, 'data-modal-close'));
});
