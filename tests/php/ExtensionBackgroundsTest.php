<?php

declare(strict_types=1);

use CometCMS\Extensions\Extension;
use CometCMS\Extensions\ExtensionRegistry;
use CometCMS\Extensions\ExtensionReview;
use CometCMS\Site\Blocks\BlockScripts;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\SiteRepository;

/** A minimal extension with one background, plus any further or replaced files. */
function comet_background_files(array $files = []): array
{
    return array_filter($files + [
        'comet-extension.json' => json_encode(['api_version' => 1, 'name' => 'acme/glow', 'title' => 'Glow', 'version' => '1.0.0']),
        'backgrounds/aurora/background.json' => json_encode([
            'title' => 'Aurora',
            'props' => [
                'tone' => ['type' => 'color', 'label' => 'Tone', 'default' => 'primary'],
                'speed' => ['type' => 'int', 'label' => 'Speed', 'min' => 1, 'max' => 60, 'default' => 20],
            ],
            'section' => ['props' => ['min_height' => 'half'], 'style' => ['background' => 'text', 'text_color' => 'background']],
        ]),
        'backgrounds/aurora/template.html' => '<div class="aurora" style="--tone: {{tone}}" data-speed="{{speed}}"></div>',
        'backgrounds/aurora/style.css' => '.aurora { position: absolute; inset: 0; }',
    ], static fn(?string $bytes): bool => $bytes !== null);
}

function comet_background_page(SiteRepository $site, array $sectionStyle, array $elements = [], array $rowStyle = [], array $columnStyle = []): void
{
    $site->saveLayout('about', 'detail', [LayoutSchema::node('section', [], $sectionStyle, [
        LayoutSchema::node('row', [], $rowStyle, [LayoutSchema::node('column', [], $columnStyle, [LayoutSchema::node('heading', ['text' => 'On top']), ...$elements])]),
    ])]);
    $site->saveSettings(array_replace($site->settings(), ['homepage' => ['type' => 'about', 'entry' => 'about']]));
}

function comet_background_html(SiteRepository $site, bool $preview = false): string
{
    $site = new SiteRepository();
    $ctx = comet_site_test_context($site);
    $ctx->preview = $preview;
    $ctx->annotate = $preview;
    $entry = \CometCMS\Content\ContentRepository::make()->findByIdentifier('about', 'about');

    return (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
}

test('backgrounds compile like blocks but are offered to sections, rows and columns instead of as elements', function (): void {
    $site = comet_site_test_setup();
    $installed = $site->blocks()->installFiles(comet_background_files());
    assert_same(['acme/aurora'], $installed['blocks']);
    $block = $site->blocks()->get('block:acme/aurora');
    assert_true($block['background']);
    assert_false($block['slot']);
    assert_same(['props' => ['min_height' => 'half'], 'style' => ['background' => 'text', 'text_color' => 'background']], $block['section']);

    $spec = LayoutSchema::spec(blocks: $site->blocks());
    assert_same(['block:acme/aurora'], $spec['backgrounds']);
    foreach (['elements', 'item_elements'] as $list) assert_false(in_array('block:acme/aurora', $spec[$list], true));
    foreach (['column', 'tab', 'modal'] as $kind) assert_false(in_array('block:acme/aurora', $spec['kinds'][$kind]['children'], true));
    assert_true($spec['kinds']['block:acme/aurora']['background']);
    assert_same(20, $spec['kinds']['block:acme/aurora']['props']['speed']['default']);
    foreach (['section', 'row', 'column'] as $kind) assert_true(in_array('background_fx', $spec['kinds'][$kind]['style'], true));
    foreach (['heading', 'modal', 'button'] as $kind) assert_false(in_array('background_fx', $spec['kinds'][$kind]['style'], true));
    // Backgrounds count apart from blocks in the admin.
    $metadata = $site->blocks()->metadata()['extensions'][0];
    assert_same(['blocks' => 0, 'backgrounds' => 1], $metadata['counts']);
    assert_true($metadata['blocks'][0]['background']);
});

test('background styles keep a known background with normalized props and drop anything else', function (): void {
    $blocks = ExtensionRegistry::active();
    $blocks->installFiles(comet_background_files());
    $blocks->installFiles(comet_block_files(), allowScripts: true);
    $normalize = static fn(mixed $value, string $kind = 'section'): array => LayoutSchema::normalize([
        ['kind' => 'section', 'style' => $kind === 'section' ? ['background_fx' => $value] : [], 'children' => [
            ['kind' => 'row', 'style' => $kind === 'row' ? ['background_fx' => $value] : [], 'children' => [
                ['kind' => 'column', 'style' => $kind === 'column' ? ['background_fx' => $value] : [], 'children' => [
                    ['kind' => 'heading', 'style' => ['background_fx' => $value]],
                    // A background is never an element.
                    ['kind' => 'block:acme/aurora'],
                ]],
            ]],
        ]],
    ], blocks: $blocks)[0];

    foreach (['section' => [], 'row' => ['children', 0], 'column' => ['children', 0, 'children', 0]] as $kind => $path) {
        $node = $normalize(['kind' => 'block:acme/aurora', 'props' => ['speed' => 99, 'tone' => 'accent', 'unknown' => 'x']], $kind);
        foreach ($path as $step) $node = $node[$step];
        assert_same(['background_fx' => ['kind' => 'block:acme/aurora', 'props' => ['tone' => 'accent', 'speed' => 60]]], $node['style'], $kind);
    }
    $column = $normalize(['kind' => 'block:acme/aurora'])['children'][0]['children'][0];
    assert_same(['heading'], array_column($column['children'], 'kind'));
    assert_same([], $column['children'][0]['style']);
    assert_same(['kind' => 'block:acme/aurora', 'props' => ['tone' => 'primary', 'speed' => 20]], $normalize(['kind' => 'block:acme/aurora', 'props' => ['bad', 'list']])['style']['background_fx']);
    // Element blocks, built-in kinds and malformed values are no background.
    foreach ([['kind' => 'block:cometcms/callout'], ['kind' => 'heading'], ['kind' => ''], 'block:acme/aurora', [], ['props' => ['tone' => 'accent']]] as $value) {
        assert_same([], $normalize($value)['style'], json_encode($value));
    }
    // A background whose extension is gone keeps bounded props for a reinstall.
    $missing = $normalize(['kind' => 'block:gone/ripple', 'props' => ['level' => 3, 'Bad Key' => 1]]);
    assert_same(['kind' => 'block:gone/ripple', 'props' => ['level' => 3]], $missing['style']['background_fx']);
});

test('backgrounds render as a decorative layer behind sections, rows and columns, with their CSS once', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_background_files());
    $fx = static fn(int $speed): array => ['background_fx' => ['kind' => 'block:acme/aurora', 'props' => ['speed' => $speed]]];
    comet_background_page($site, ['background' => 'text', 'background_image' => '', ...$fx(5)], [], $fx(6), $fx(7));

    foreach ([false, true] as $preview) {
        $html = comet_background_html($site, $preview);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $layers = $xpath->query('//div[contains(concat(" ", @class, " "), " c-background-fx ") and @aria-hidden="true"]');
        assert_same(3, $layers->length);
        foreach (['section', 'row', 'col'] as $index => $kind) {
            $layer = $layers->item($index);
            assert_true(str_contains($layer->getAttribute('class'), 'c-block-acme__aurora'));
            assert_true(str_contains(' ' . $layer->parentNode->getAttribute('class') . ' ', ' c-' . $kind . ' '), $kind);
            assert_true(str_contains($layer->parentNode->getAttribute('class'), 'c-has-bg-fx'));
            // The layer comes first, so the content paints over it.
            assert_same($layer, $layer->parentNode->firstChild);
            assert_same((string) (5 + $index), $layer->firstChild->getAttribute('data-speed'));
            assert_same('--tone:var(--c-primary)', $layer->firstChild->getAttribute('style'));
        }
        assert_same(1, substr_count($html, '.aurora { position: absolute; inset: 0; }'));
        assert_false(str_contains($html, 'data-comet-block="'));
        assert_true(str_contains($html, 'On top'));
        assert_same($preview, str_contains($html, 'data-kind="section"'));
    }
});

test('a disabled background extension draws nothing until it returns, keeping the choice', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_background_files());
    comet_background_page($site, ['background_fx' => ['kind' => 'block:acme/aurora', 'props' => ['speed' => 9]]]);
    assert_true($site->blocks()->setEnabled('acme/glow', false));
    $html = comet_background_html($site);
    assert_false(str_contains($html, 'c-background-fx'));
    assert_false(str_contains($html, 'c-has-bg-fx'));
    assert_true(str_contains($html, 'On top'));
    $site = new SiteRepository();
    $site->saveLayout('about', 'detail', $site->layout('about', 'detail'));
    assert_true((new SiteRepository())->blocks()->setEnabled('acme/glow', true));
    assert_same(['kind' => 'block:acme/aurora', 'props' => ['tone' => 'primary', 'speed' => 9]], (new SiteRepository())->layout('about', 'detail')[0]['style']['background_fx']);
    assert_true(str_contains(comet_background_html($site), 'data-speed="9"'));
});

test('background scripts need the same consent as block scripts and mount on the layer with resolved props', function (): void {
    $site = comet_site_test_setup();
    $files = Extension::readDirectory(COMET_TEST_ROOT . '/examples/extensions/comet-water-ripple');
    try {
        $site->blocks()->installFiles($files);
        throw new RuntimeException('Expected a script review.');
    } catch (ExtensionReview $review) {
        assert_same(['cometcms/water-ripple'], $review->scripts);
    }
    $installed = $site->blocks()->installFiles($files, allowScripts: true);
    assert_same(['cometcms/water-ripple'], $installed['scripts']);
    $spec = LayoutSchema::spec(blocks: $site->blocks());
    assert_same(['block:cometcms/water-ripple'], $spec['backgrounds']);
    assert_true($spec['kinds']['block:cometcms/water-ripple']['script']);
    assert_same('drifting', $spec['kinds']['block:cometcms/water-ripple']['variations'][0]['name']);

    comet_background_page($site, ['background' => 'text', 'background_fx' => ['kind' => 'block:cometcms/water-ripple', 'props' => ['drift' => 40, 'color' => 'secondary']]]);
    $html = comet_background_html($site);
    preg_match('/<div class="c-background-fx c-block-cometcms__water-ripple" aria-hidden="true" data-comet-block="cometcms\/water-ripple" data-comet-props="([^"]+)"><\/div>/', $html, $match);
    assert_true($match !== [], 'The script mounts on an empty layer.');
    $props = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
    assert_same(['_' => 'secondary', 'css' => 'var(--c-secondary)'], $props['color']);
    assert_same(['_' => 'primary', 'css' => 'var(--c-primary)'], $props['highlight']);
    assert_same([40, 100, 'pointer', 'medium'], [$props['drift'], $props['intensity'], $props['interaction'], $props['density']]);
    assert_same(1, substr_count($html, '<script type="module">import mount from '));
    assert_true(str_contains($html, '.cometcms-ripple__canvas'));
    $ctx = comet_site_test_context(new SiteRepository());
    assert_same(2, count(BlockScripts::cspSources($html, $ctx)));
});

test('extension validation explains what backgrounds may hold', function (): void {
    $manifest = static fn(array $fields): string => json_encode(['title' => 'Aurora'] + $fields);
    $cases = [
        'A background sits behind content and has no <slot></slot>' => ['backgrounds/aurora/template.html' => '<div><slot></slot></div>'],
        'allowed_children does not apply' => ['backgrounds/aurora/background.json' => $manifest(['allowed_children' => ['heading']])],
        'Unknown field "category"' => ['backgrounds/aurora/background.json' => $manifest(['category' => 'Fancy'])],
        'props.title: type must be one of bool, int, enum, icon, color, space, radius, shadow' => ['backgrounds/aurora/background.json' => $manifest(['props' => ['title' => ['type' => 'string', 'label' => 'Title']]])],
        'section.style.background_fx: sections have no style "background_fx"; the palette sets the background itself.' => ['backgrounds/aurora/background.json' => $manifest(['section' => ['style' => ['background_fx' => []]]])],
        'section.props.min_height: "gigantic" is not a value sections keep' => ['backgrounds/aurora/background.json' => $manifest(['section' => ['props' => ['min_height' => 'gigantic']]])],
        'section.props.typo: sections have no prop "typo"' => ['backgrounds/aurora/background.json' => $manifest(['section' => ['props' => ['typo' => 1]]])],
        'section must be an object with "props" and/or "style"' => ['backgrounds/aurora/background.json' => $manifest(['section' => ['extra' => []]])],
        'A background needs template.html, view.js or both.' => ['backgrounds/aurora/template.html' => null],
        'Every background needs background.json.' => ['backgrounds/aurora/background.json' => null],
        'There is no background "mist" for this script. Add background.json next to it.' => ['backgrounds/mist/view.js' => 'export default function () {}'],
        'blocks/aurora/ and backgrounds/aurora/ would both be named aurora' => ['blocks/aurora/template.html' => '<p>Hi</p>'],
        'A background is described by background.json.' => ['backgrounds/aurora/block.json' => '{}'],
        'backgrounds.mist: the extension has no background "mist".' => ['i18n/de.json' => json_encode(['backgrounds' => ['mist' => ['title' => 'Nebel']]])],
        'blocks.aurora: the extension has no block "aurora". It is a background; translate it under backgrounds.' => ['i18n/de.json' => json_encode(['blocks' => ['aurora' => ['title' => 'Polarlicht']]])],
        'A background\'s script is backgrounds/<name>/view.js' => ['backgrounds/aurora/lib.js' => 'export const x = 1;'],
        // A script in the other folder neither turns a block into a background nor the reverse.
        'backgrounds/callout/view.js: This script belongs next to the block in blocks/callout/.' => [
            'blocks/callout/block.json' => json_encode(['title' => 'Callout']), 'blocks/callout/template.html' => '<p>Hi</p>', 'backgrounds/callout/view.js' => 'export default function () {}',
        ],
        'blocks/aurora/view.js: This script belongs next to the background in backgrounds/aurora/.' => ['blocks/aurora/view.js' => 'export default function () {}'],
    ];
    foreach ($cases as $expected => $files) {
        $errors = comet_block_errors(static fn() => Extension::compile(comet_background_files($files)));
        assert_true(array_filter($errors, static fn(string $error): bool => str_contains($error, $expected)) !== [], $expected . "\n" . implode("\n", $errors));
    }
    // A script-only background needs no template, and translations reach it under backgrounds.
    $compiled = Extension::compile(comet_background_files([
        'backgrounds/aurora/template.html' => null,
        'backgrounds/aurora/view.js' => 'export default function (element) { element.dataset.on = "1"; }',
        'i18n/de.json' => json_encode(['backgrounds' => ['aurora' => ['title' => 'Polarlicht', 'props' => ['speed' => ['label' => 'Tempo']]]]]),
    ]));
    $aurora = $compiled['blocks']['block:acme/aurora'];
    assert_same([], $aurora['template']);
    assert_same('Polarlicht', $aurora['l10n']['de']['title']);
    assert_same('Tempo', $aurora['props']['speed']['l10n']['de']['label']);
    assert_true(isset($compiled['assets']['backgrounds/aurora/view.js']));
});

test('the fancy backgrounds replace their containers, which keep rendering for existing pages', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(Extension::readDirectory(COMET_TEST_ROOT . '/examples/extensions/comet-fancy-bg-containers'));
    $spec = LayoutSchema::spec(blocks: $site->blocks());
    $backgrounds = ['geometric-forms' => 'geometry', 'soft-lights' => 'lights', 'deep-space' => 'space', 'futuristic-grid' => 'grid'];
    assert_same(array_map(static fn(string $name): string => 'block:cometcms/' . $name, ['deep-space', 'futuristic-grid', 'geometric-forms', 'soft-lights']), $spec['backgrounds']);
    foreach ($backgrounds as $name => $effect) {
        $kind = 'block:cometcms/' . $name;
        assert_same(['variant', 'accent', 'secondary', 'intensity', 'motion', 'scale'], array_keys($spec['kinds'][$kind]['props']));
        assert_same('Effekt', $spec['kinds'][$kind]['props']['accent']['l10n']['de']['group']);
        assert_same(['background' => 'text', 'text_color' => 'background', 'link_color' => 'background'], $spec['kinds'][$kind]['section']['style']);
        assert_true(is_string($spec['kinds']['block:cometcms/fancy-' . $effect]['deprecated']));
    }
    comet_background_page($site, ['background' => 'text', 'background_fx' => ['kind' => 'block:cometcms/futuristic-grid', 'props' => ['variant' => 'flat']]], [
        LayoutSchema::node('block:cometcms/fancy-space', ['motion' => 'still'], [], [LayoutSchema::node('text', ['html' => '<p>Old container</p>'])]),
    ]);
    $html = comet_background_html($site);
    assert_true(str_contains($html, '<div class="c-background-fx c-block-cometcms__futuristic-grid" aria-hidden="true"><div class="cometcms-fbg cometcms-fbg--grid cometcms-fbg--layer" data-variant="flat"'));
    assert_true(str_contains($html, 'Old container'));
    // Both share the extension CSS, which now holds every effect.
    assert_same(1, substr_count($html, '<style data-comet-blocks>'));
    assert_same(1, substr_count($html, '@keyframes cometcms-fbg-float'));
    assert_true(str_contains($html, '.cometcms-fbg--grid[data-variant="flat"]'));
    assert_true(str_contains($html, '.cometcms-fbg--space .cometcms-fbg__nebula'));
});

test('mcp agents set backgrounds as style and learn their props from the schema', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    ExtensionRegistry::active()->installFiles(comet_background_files());
    [, $extensions] = comet_test_mcp_tool($token, 'list_extensions', []);
    $listed = array_column($extensions['data']['extensions'], null, 'name')['acme/glow']['blocks'][0];
    assert_same(['block:acme/aurora', true], [$listed['kind'], $listed['background']]);
    [, $schema] = comet_test_mcp_tool($token, 'get_builder_schema', ['kind' => 'block:acme/aurora']);
    assert_same(20, $schema['data']['props']['speed']['default']);
    $base = ['collection' => 'pages', 'variant' => 'detail', 'revision' => $layout['revision'], 'section_id' => 'hero'];
    foreach ([['kind' => 'block:acme/aurora', 'props' => ['typo' => 1]], ['kind' => 'block:acme/aurora', 'props' => ['speed' => 99]], 'aurora', ['kind' => 'block:acme/aurora', 'extra' => true]] as $value) {
        [$response] = comet_test_mcp_tool($token, 'update_section', $base + ['patch' => ['style' => ['background_fx' => $value]]]);
        assert_true($response['result']['isError'], json_encode($value));
    }
    [, $updated] = comet_test_mcp_tool($token, 'update_section', $base + ['patch' => ['style' => ['background_fx' => ['kind' => 'block:acme/aurora', 'props' => ['speed' => 12]]]]]);
    assert_true(isset($updated['data']['revision']), json_encode($updated));
    assert_same(['kind' => 'block:acme/aurora', 'props' => ['tone' => 'primary', 'speed' => 12]], (new SiteRepository())->layout('pages', 'detail')[0]['style']['background_fx']);
});
