<?php

declare(strict_types=1);

use CometCMS\Extensions\Extension;
use CometCMS\Extensions\ExtensionException;
use CometCMS\Extensions\ExtensionRegistry;
use CometCMS\Extensions\ExtensionReview;
use CometCMS\Extensions\VersionConstraint;
use CometCMS\Site\Blocks\BlockScripts;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\SiteRepository;

/** A one-block extension whose manifest and block.json take extra fields. */
function comet_api_kit(array $manifest = [], array $block = [], array $files = []): array
{
    return array_replace([
        'comet-extension.json' => json_encode(['api_version' => 1, 'name' => 'test/kit', 'title' => 'Kit', 'version' => '1.0.0'] + $manifest, JSON_UNESCAPED_SLASHES),
        'blocks/thing/block.json' => json_encode(['title' => 'Thing'] + $block + ['props' => ['title' => ['type' => 'string', 'label' => 'Title', 'default' => 'Hi']]], JSON_UNESCAPED_SLASHES),
        'blocks/thing/template.html' => '<p>{{title}}</p>',
    ], $files);
}

test('version constraints follow semver ranges, carets, tildes, partial versions and alternatives', function (): void {
    foreach ([
        ['1.7.0', '^1.5', true], ['2.0.0', '^1.5', false], ['0.2.9', '^0.2.3', true], ['0.3.0', '^0.2.3', false],
        ['1.2.9', '~1.2', true], ['1.3.0', '~1.2', false], ['1.0.0-beta.1', '>=1.0.0', false], ['1.0.0', '>=1.0.0-rc.1', true],
        ['1.5.0', '>=1.0 <2', true], ['2.1.0', '^1 || ^2', true], ['1.4.2', '1.4', true], ['1.5.0', '1.4', false],
        ['1.9.9', '<=1', true], ['2.0.0', '>1', true], ['1.9.0', '>1', false], ['3.0.0', '*', true],
    ] as [$version, $constraint, $expected]) {
        assert_same($expected, VersionConstraint::satisfies($version, $constraint), $version . ' ' . $constraint);
    }
    foreach (['', ' ', 'abc', '>= 1', '1.x.y', '^1 ||| 2', 1] as $invalid) {
        assert_false(VersionConstraint::valid($invalid));
    }
});

test('dependency versions and the cms constraint are enforced on install, update and folder deployment', function (): void {
    $registry = ExtensionRegistry::active();
    $registry->installFiles(comet_dependency_files('test/base'));
    $errors = comet_block_errors(fn() => $registry->installFiles(comet_dependency_files('test/dependent', ['test/base' => '^2.0'])));
    assert_same(['comet-extension.json: Requires test/base ^2.0, but version 1.0.0 is installed. Install a matching version first.'], $errors);
    $registry->installFiles(comet_dependency_files('test/dependent', ['test/base' => '^1.0']));
    // Updating the dependency beyond what a dependent accepts is refused.
    $major = json_decode(comet_dependency_files('test/base')['comet-extension.json'], true);
    $major['version'] = '2.0.0';
    $errors = comet_block_errors(fn() => $registry->installFiles(['comet-extension.json' => json_encode($major)]));
    assert_true(str_contains($errors[0], 'test/dependent requires test/base ^1.0'));
    $major['version'] = '1.4.0';
    $registry->installFiles(['comet-extension.json' => json_encode($major)]);
    assert_same('1.4.0', $registry->extension('test/base')['version']);

    $future = comet_api_kit(['cms' => '>=999.0']);
    $errors = comet_block_errors(fn() => $registry->installFiles($future));
    assert_true(str_contains($errors[0], 'Requires CometCMS >=999.0, but this site runs ' . Extension::cmsVersion()));
    assert_same('>=1.0', Extension::compile(comet_api_kit(['cms' => '>=1.0']))['cms']);
    assert_throws(ExtensionException::class, fn() => Extension::compile(comet_api_kit(['cms' => 'soon'])));
});

test('connect permissions need scripts, are reviewed and extend the CSP only on pages using the block', function (): void {
    $script = 'export default function mount(element, props, ctx) { return () => {}; }';
    $files = comet_api_kit(['permissions' => ['connect' => ['https://api.example.com', 'https://*.tiles.example.org']]], [], ['blocks/thing/view.js' => $script]);
    assert_same(['connect' => ['https://api.example.com', 'https://*.tiles.example.org']], Extension::compile($files)['permissions']);
    foreach ([['connect' => ['http://api.example.com']], ['connect' => ['https://api.example.com/v1']], ['frame' => ['https://a.example.com']], ['connect' => []], ['https://a.example.com']] as $bad) {
        assert_throws(ExtensionException::class, fn() => Extension::compile(comet_api_kit(['permissions' => $bad], [], ['blocks/thing/view.js' => $script])));
    }
    assert_same(['comet-extension.json: permissions only apply to block scripts (blocks/<name>/view.js), and this extension has none. Remove permissions.'],
        comet_block_errors(fn() => Extension::compile(comet_api_kit(['permissions' => ['connect' => ['https://api.example.com']]]))));

    $site = comet_site_test_setup();
    try {
        $site->blocks()->installFiles($files);
        throw new RuntimeException('Expected a review.');
    } catch (ExtensionReview $review) {
        assert_same(['connect' => ['https://api.example.com', 'https://*.tiles.example.org']], $review->details()['permissions']);
    }
    $site->blocks()->installFiles($files, allowScripts: true);
    comet_block_page($site, [LayoutSchema::node('block:test/thing')]);
    $site = new SiteRepository();
    $ctx = comet_site_test_context($site);
    $html = (new \CometCMS\Site\PageRenderer($ctx))->entryPage($site->types()['about'], \CometCMS\Content\ContentRepository::make()->findByIdentifier('about', 'about'));
    $policy = "default-src 'self'; script-src 'none'";
    assert_same($policy . "; connect-src 'self' https://api.example.com https://*.tiles.example.org", BlockScripts::extendPolicy($policy, $html, $ctx));
    assert_same($policy, BlockScripts::extendPolicy($policy, '<p>No blocks</p>', $ctx));
    assert_same("connect-src 'self' x https://api.example.com https://*.tiles.example.org", BlockScripts::extendPolicy("connect-src 'self' x", $html, $ctx));
});

test('extension settings are validated, stored per workspace and reach templates and scripts', function (): void {
    $settings = [
        'greeting' => ['type' => 'string', 'label' => 'Greeting', 'default' => 'Hello', 'max' => 40],
        'units' => ['type' => 'enum', 'label' => 'Units', 'options' => ['metric', 'imperial'], 'option_labels' => ['metric' => 'Metric (°C)']],
        'accent' => ['type' => 'color', 'label' => 'Accent', 'default' => 'primary'],
        'api' => ['type' => 'url', 'label' => 'API', 'default' => 'https://api.example.com', 'group' => 'Advanced', 'visible_when' => ['units' => 'metric']],
    ];
    $files = comet_api_kit(['settings' => $settings], [], [
        'blocks/thing/template.html' => '<p class="u-{{@units}}" style="--kit-accent: {{@accent}}">{{@greeting}}, {{title}}{{#@units=imperial}} (°F){{/@units}}</p><a href="{{@api}}">API</a>',
        'blocks/thing/view.js' => 'export default function (element, props, ctx) { element.dataset.units = ctx.settings.units; }',
    ]);
    $compiled = Extension::compile($files);
    assert_same('Advanced', $compiled['settings']['api']['group']);
    foreach ([['x' => ['type' => 'media', 'label' => 'Logo']], ['x' => ['type' => 'list', 'label' => 'L', 'fields' => ['a' => ['type' => 'string', 'label' => 'A']]]], ['x' => ['type' => 'string']]] as $bad) {
        assert_throws(ExtensionException::class, fn() => Extension::compile(comet_api_kit(['settings' => $bad])));
    }
    assert_true(str_contains(comet_block_errors(fn() => Extension::compile(comet_api_kit([], [], ['blocks/thing/template.html' => '<p>{{@missing}}</p>'])))[0], 'unknown setting "missing"'));

    $site = comet_site_test_setup();
    $registry = $site->blocks();
    $registry->installFiles($files, allowScripts: true);
    assert_same(['greeting' => 'Hello', 'units' => 'metric', 'accent' => 'primary', 'api' => 'https://api.example.com'], $registry->settings('test/kit'));
    assert_same(['greeting' => 'Howdy', 'units' => 'imperial', 'accent' => 'primary', 'api' => 'https://api.example.com'],
        $registry->setSettings('test/kit', ['greeting' => "Howdy\n", 'units' => 'imperial', 'accent' => 'nope', 'unknown' => 1, 'api' => 'javascript:alert(1)']));
    assert_null($registry->setSettings('test/none', []));
    $html = (new NodeRenderer(comet_site_test_context(new SiteRepository())))->render([LayoutSchema::node('block:test/thing')]);
    assert_true(str_contains($html, '<p class="u-imperial" style="--kit-accent:var(--c-primary)">Howdy, Hi (°F)</p>'));
    assert_true(str_contains($html, 'href="https://api.example.com"'));
    $loader = BlockScripts::loader('test/thing', '/x.js', ['greeting' => '</script>', 'units' => 'metric']);
    assert_false(str_contains($loader, '</script>'));
    assert_true(str_contains($loader, 'blocks.define("test/thing", mount, {"greeting":"\u003C/script\u003E","units":"metric"}, false);'));
    // Settings change the extension fingerprint, which keys cached public pages.
    $before = (new ExtensionRegistry($registry->workspace))->fingerprint();
    (new ExtensionRegistry($registry->workspace))->setSettings('test/kit', ['greeting' => 'Hey']);
    assert_false($before === (new ExtensionRegistry($registry->workspace))->fingerprint());
    $registry = new ExtensionRegistry($registry->workspace);
    $registry->delete('test/kit');
    $registry->installFiles($files, allowScripts: true);
    assert_same('Hello', $registry->settings('test/kit')['greeting']);
});

test('extension settings API needs an administrator, CSRF and an object', function (): void {
    ExtensionRegistry::active()->installFiles(comet_api_kit(['settings' => ['greeting' => ['type' => 'string', 'label' => 'Greeting']]]));
    $source = file_get_contents(COMET_TEST_ROOT . '/cms/app/Controllers/Admin/SiteController.php');
    assert_true(str_contains($source, "'site.extension_configured'"));
    assert_true(str_contains($source, "Settings must be an object of setting values."));
});

test('inspector metadata validates groups, help, placeholders, conditions and option labels', function (): void {
    $props = [
        'tone' => ['type' => 'enum', 'label' => 'Tone', 'options' => ['info', 'warning'], 'option_labels' => ['info' => 'Information'], 'group' => 'Look'],
        'show_link' => ['type' => 'bool', 'label' => 'Show link', 'help' => 'Adds a button below the text.'],
        'link_label' => ['type' => 'string', 'label' => 'Link label', 'placeholder' => 'Learn more', 'visible_when' => ['show_link' => true]],
        'icon' => ['type' => 'icon', 'label' => 'Icon', 'visible_when' => ['tone' => ['warning']]],
        'items' => ['type' => 'list', 'label' => 'Items', 'fields' => [
            'kind' => ['type' => 'enum', 'label' => 'Kind', 'options' => ['text', 'link']],
            'url' => ['type' => 'url', 'label' => 'URL', 'visible_when' => ['kind' => 'link']],
        ]],
    ];
    $block = Extension::compile(comet_block_single($props, '<p>{{tone}}</p>'))['blocks']['block:test/thing'];
    assert_same(['show_link' => [true]], $block['props']['link_label']['visible_when']);
    assert_same(['kind' => ['link']], $block['props']['items']['fields']['url']['visible_when']);
    assert_same(['info' => 'Information'], $block['props']['tone']['option_labels']);
    foreach ([
        ['link_label' => ['visible_when' => ['missing' => true]]],
        ['link_label' => ['visible_when' => ['tone' => 'nope']]],
        ['link_label' => ['visible_when' => ['show_link' => 'yes']]],
        ['link_label' => ['visible_when' => ['link_label' => true]]],
        ['link_label' => ['visible_when' => []]],
        ['show_link' => ['placeholder' => 'x']],
        ['tone' => ['option_labels' => ['other' => 'Other']]],
        ['items' => ['fields' => ['kind' => ['type' => 'enum', 'label' => 'Kind', 'options' => ['a'], 'group' => 'G']]]],
        ['link_label' => ['help' => str_repeat('x', 301)]],
    ] as $change) {
        $changed = array_replace_recursive($props, $change);
        if (isset($change['link_label']['visible_when']) && $change['link_label']['visible_when'] === []) $changed['link_label']['visible_when'] = [];
        assert_throws(ExtensionException::class, fn() => Extension::compile(comet_block_single($changed, '<p>{{tone}}</p>')), json_encode($change));
    }
});

test('renamed props, option aliases and block aliases migrate saved layouts on read', function (): void {
    $v1 = comet_api_kit([], ['props' => [
        'link_url' => ['type' => 'url', 'label' => 'Link'],
        'tone' => ['type' => 'enum', 'label' => 'Tone', 'options' => ['info', 'danger']],
        'rows' => ['type' => 'list', 'label' => 'Rows', 'fields' => ['caption' => ['type' => 'string', 'label' => 'Caption']]],
    ]], ['blocks/thing/template.html' => '<a href="{{link_url}}">{{tone}}</a>']);
    $registry = ExtensionRegistry::active();
    $registry->installFiles($v1);
    $saved = LayoutSchema::node('block:test/thing', ['link_url' => '/contact', 'tone' => 'danger', 'rows' => [['caption' => 'One']]]);

    $v2 = comet_api_kit(['version' => '2.0.0'], ['aliases' => ['test/thing'], 'props' => [
        'cta' => ['type' => 'url', 'label' => 'Link', 'renamed_from' => ['link_url']],
        'tone' => ['type' => 'enum', 'label' => 'Tone', 'options' => ['info', 'error'], 'option_aliases' => ['danger' => 'error']],
        'rows' => ['type' => 'list', 'label' => 'Rows', 'fields' => ['label' => ['type' => 'string', 'label' => 'Label', 'renamed_from' => ['caption']]]],
    ]]);
    $v2['blocks/notice/block.json'] = $v2['blocks/thing/block.json'];
    $v2['blocks/notice/template.html'] = '<a href="{{cta}}">{{tone}}{{#rows}} {{label}}{{/rows}}</a>';
    unset($v2['blocks/thing/block.json'], $v2['blocks/thing/template.html']);
    // Neither the rename, the alias nor the renamed option count as breaking changes.
    $result = $registry->installFiles($v2);
    assert_same([], $result['changes']);
    $registry = new ExtensionRegistry($registry->workspace);
    $section = LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$saved])])]);
    $node = LayoutSchema::normalize([$section], blocks: $registry)[0]['children'][0]['children'][0]['children'][0];
    assert_same('block:test/notice', $node['kind']);
    assert_same('/contact', $node['props']['cta']);
    assert_same('error', $node['props']['tone']);
    assert_same('One', $node['props']['rows'][0]['label']);
    assert_false(isset($node['props']['link_url']));
    foreach ([
        ['aliases' => ['other/thing']],
        ['aliases' => ['test/notice']],
        ['props' => ['a' => ['type' => 'string', 'label' => 'A'], 'b' => ['type' => 'string', 'label' => 'B', 'renamed_from' => ['a']]]],
        ['props' => ['t' => ['type' => 'enum', 'label' => 'T', 'options' => ['a', 'b'], 'option_aliases' => ['a' => 'b']]]],
        ['props' => ['t' => ['type' => 'enum', 'label' => 'T', 'options' => ['a'], 'option_aliases' => ['old' => 'missing']]]],
    ] as $change) {
        $files = comet_api_kit([], $change, ['blocks/notice/block.json' => json_encode(['title' => 'N']), 'blocks/notice/template.html' => '<p>N</p>']);
        if (isset($change['props'])) $files['blocks/thing/template.html'] = '<p>x</p>';
        assert_throws(ExtensionException::class, fn() => Extension::compile($files), json_encode($change));
    }
});

test('block categories, keywords, variations, deprecation and slot rules reach the builder spec', function (): void {
    $files = comet_api_kit([], [
        'category' => 'Marketing',
        'keywords' => ['notice', 'alert'],
        'deprecated' => 'Use Callout instead.',
        'allowed_children' => ['heading', 'button', 'test/thing'],
        'variations' => [['name' => 'loud', 'title' => 'Loud thing', 'icon' => 'mdi:bullhorn', 'props' => ['title' => 'HEY']]],
    ], ['blocks/thing/template.html' => '<div><p>{{title}}</p><slot></slot></div>']);
    $registry = ExtensionRegistry::active();
    $registry->installFiles($files);
    $kind = LayoutSchema::spec(blocks: $registry)['kinds']['block:test/thing'];
    assert_same('Marketing', $kind['category']);
    assert_same(['notice', 'alert'], $kind['keywords']);
    assert_same('Use Callout instead.', $kind['deprecated']);
    assert_same([['name' => 'loud', 'title' => 'Loud thing', 'description' => '', 'icon' => 'mdi:bullhorn', 'props' => ['title' => 'HEY']]], $kind['variations']);
    assert_same(['heading', 'button', 'block:test/thing'], $kind['children']);
    // Slot children outside allowed_children are dropped.
    $block = LayoutSchema::node('block:test/thing', [], [], [LayoutSchema::node('heading'), LayoutSchema::node('image'), LayoutSchema::node('button')]);
    $section = LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$block])])]);
    $children = LayoutSchema::normalize([$section], blocks: $registry)[0]['children'][0]['children'][0]['children'][0]['children'];
    assert_same(['heading', 'button'], array_column($children, 'kind'));
    foreach ([
        ['variations' => [['name' => 'x', 'title' => 'X', 'props' => ['missing' => 1]]]],
        ['variations' => [['name' => 'x', 'title' => 'X', 'props' => ['title' => 5]]]],
        ['variations' => [['name' => 'x', 'title' => 'X', 'props' => []], ['name' => 'x', 'title' => 'Y', 'props' => []]]],
        ['allowed_children' => ['nonsense']],
        ['keywords' => 'notice'],
        ['deprecated' => 5],
    ] as $change) {
        assert_throws(ExtensionException::class, fn() => Extension::compile(comet_api_kit([], $change, ['blocks/thing/template.html' => '<div><slot></slot></div>'])), json_encode($change));
    }
    assert_throws(ExtensionException::class, fn() => Extension::compile(comet_api_kit([], ['allowed_children' => ['heading']])));
});

test('i18n files translate extension, block, prop, option, field, variation and setting texts', function (): void {
    $files = comet_api_kit(['settings' => ['units' => ['type' => 'enum', 'label' => 'Units', 'options' => ['metric', 'imperial']]]], [
        'category' => 'Content',
        'variations' => [['name' => 'loud', 'title' => 'Loud', 'props' => []]],
        'props' => [
            'title' => ['type' => 'string', 'label' => 'Title', 'help' => 'Shown big.'],
            'tone' => ['type' => 'enum', 'label' => 'Tone', 'options' => ['info', 'warning']],
            'rows' => ['type' => 'list', 'label' => 'Rows', 'fields' => ['label' => ['type' => 'string', 'label' => 'Label']]],
        ],
    ], ['i18n/de.json' => json_encode([
        'title' => 'Baukasten',
        'settings' => ['units' => ['label' => 'Einheiten', 'options' => ['metric' => 'Metrisch']]],
        'blocks' => ['thing' => [
            'title' => 'Ding', 'category' => 'Inhalt', 'keywords' => ['sache'],
            'variations' => ['loud' => ['title' => 'Laut']],
            'props' => [
                'title' => ['label' => 'Titel', 'help' => 'Groß angezeigt.'],
                'tone' => ['options' => ['warning' => 'Warnung']],
                'rows' => ['label' => 'Zeilen', 'fields' => ['label' => ['label' => 'Beschriftung']]],
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE)]);
    $compiled = Extension::compile($files);
    $block = $compiled['blocks']['block:test/thing'];
    assert_same(['title' => 'Baukasten'], $compiled['l10n']['de']);
    assert_same(['label' => 'Einheiten', 'option_labels' => ['metric' => 'Metrisch']], $compiled['settings']['units']['l10n']['de']);
    assert_same(['title' => 'Ding', 'category' => 'Inhalt', 'keywords' => ['sache'], 'variations' => ['loud' => ['title' => 'Laut']]], $block['l10n']['de']);
    assert_same(['label' => 'Titel', 'help' => 'Groß angezeigt.'], $block['props']['title']['l10n']['de']);
    assert_same(['option_labels' => ['warning' => 'Warnung']], $block['props']['tone']['l10n']['de']);
    assert_same(['label' => 'Beschriftung'], $block['props']['rows']['fields']['label']['l10n']['de']);
    $registry = ExtensionRegistry::active();
    $registry->installFiles($files);
    assert_same('Titel', LayoutSchema::spec(blocks: $registry)['kinds']['block:test/thing']['props']['title']['l10n']['de']['label']);

    foreach ([
        ['blocks' => ['missing' => ['title' => 'X']]],
        ['blocks' => ['thing' => ['props' => ['nope' => ['label' => 'X']]]]],
        ['blocks' => ['thing' => ['props' => ['tone' => ['options' => ['other' => 'X']]]]]],
        ['blocks' => ['thing' => ['props' => ['title' => ['fields' => []]]]]],
        ['blocks' => ['thing' => ['variations' => ['quiet' => ['title' => 'X']]]]],
        ['settings' => ['nope' => ['label' => 'X']]],
        ['title' => ''],
        ['extra' => true],
    ] as $translation) {
        $broken = $files;
        $broken['i18n/de.json'] = json_encode($translation);
        assert_throws(ExtensionException::class, fn() => Extension::compile($broken), json_encode($translation));
    }
    $broken = $files;
    $broken['i18n/German.json'] = '{}';
    assert_throws(ExtensionException::class, fn() => Extension::compile($broken));
});

test('the script loader passes ctx and keeps cleanups so previews can remount in place', function (): void {
    $loader = BlockScripts::loader('test/thing', '/x.js', [], true);
    assert_true(str_starts_with($loader, 'import mount from "/x.js";'));
    foreach (['globalThis.cometBlocks ??=', 'module.mount(element, JSON.parse(element.dataset.cometProps || "{}"), ctx)', 'reducedMotion', 'signal: controller.signal', 'remountable()', 'blocks.define("test/thing", mount, {}, true);'] as $part) {
        assert_true(str_contains($loader, $part), $part);
    }
    assert_same([], BlockScripts::problems('export default function mount(element, props, ctx) { return () => {}; }'));
});

test('the weather example installs with its permission, settings, translations and migrations', function (): void {
    $files = Extension::readDirectory(COMET_TEST_ROOT . '/examples/extensions/comet-weather');
    $site = comet_site_test_setup();
    $registry = $site->blocks();
    $registry->installFiles($files, allowScripts: true);
    assert_same(['connect' => ['https://api.open-meteo.com']], $registry->extension('cometcms/weather')['permissions']);
    $kind = LayoutSchema::spec(blocks: $registry)['kinds']['block:cometcms/weather'];
    assert_same('Wetter', $kind['l10n']['de']['title']);
    assert_same('Live data', $kind['category']);
    // A layout saved by the 1.x block keeps its values under the 2.0 names.
    $old = ['id' => 'w1', 'kind' => 'block:cometcms/forecast', 'props' => ['coordinates' => '48.137, 11.575', 'layout' => 'wide'], 'style' => [], 'children' => []];
    $section = LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$old])])]);
    $node = LayoutSchema::normalize([$section], blocks: $registry)[0]['children'][0]['children'][0]['children'][0];
    assert_same(['block:cometcms/weather', '48.137, 11.575', 'card'], [$node['kind'], $node['props']['location'], $node['props']['layout']]);
    $html = (new NodeRenderer(comet_site_test_context(new SiteRepository())))->render([$node]);
    assert_true(str_contains($html, 'Weather data by Open-Meteo.com'));
    $registry->setSettings('cometcms/weather', ['credit' => false, 'units' => 'imperial']);
    $html = (new NodeRenderer(comet_site_test_context(new SiteRepository())))->render([$node]);
    assert_false(str_contains($html, 'Open-Meteo.com'));
});

test('essentials maps read their tile server and attribution from extension settings', function (): void {
    $site = comet_site_test_setup();
    $registry = $site->blocks();
    $registry->installFiles(comet_block_files(), allowScripts: true);
    $map = LayoutSchema::node('block:cometcms/maps');
    $render = static fn(): string => (new NodeRenderer(comet_site_test_context(new SiteRepository())))->render([$map]);
    assert_true(str_contains($render(), '<a class="cometcms-map__attribution" href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">© OpenStreetMap contributors</a>'));
    $registry->setSettings('cometcms/essentials', ['attribution' => '© Example Maps', 'attribution_url' => 'https://maps.example.com/terms']);
    assert_true(str_contains($render(), 'href="https://maps.example.com/terms" target="_blank" rel="noopener noreferrer">© Example Maps</a>'));
});
