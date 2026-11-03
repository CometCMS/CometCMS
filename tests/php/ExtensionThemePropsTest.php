<?php

declare(strict_types=1);

use CometCMS\Extensions\Extension;
use CometCMS\Extensions\ExtensionRegistry;
use CometCMS\Site\Blocks\BlockTemplate;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\SiteRepository;
use CometCMS\Site\Theme;

/** A one-block extension: the manifest and block.json as arrays, the template as HTML. */
function comet_theme_extension(array $block, string $template, array $manifest = [], array $files = []): array
{
    return [
        'comet-extension.json' => json_encode($manifest + ['api_version' => 1, 'name' => 'acme/tokens', 'title' => 'Tokens', 'version' => '1.0.0'], JSON_UNESCAPED_SLASHES),
        'blocks/panel/block.json' => json_encode($block + ['title' => 'Panel'], JSON_UNESCAPED_SLASHES),
        'blocks/panel/template.html' => $template,
    ] + $files;
}

test('space, radius and shadow props hold theme values and print their CSS in style properties', function (): void {
    $props = [
        'gap' => ['type' => 'space', 'label' => 'Gap', 'default' => 'md'],
        'corners' => ['type' => 'radius', 'label' => 'Corners', 'default' => 'full'],
        'depth' => ['type' => 'shadow', 'label' => 'Shadow', 'default' => 'sm'],
        'tint' => ['type' => 'color', 'label' => 'Tint'],
    ];
    $template = '<div class="panel panel--{{corners}}" style="--gap: {{gap}}; --radius: {{corners}}; --shadow: {{depth}}; --tint: {{tint}}">{{#corners=full}}<span>pill</span>{{/corners}}</div>';
    $block = Extension::compile(comet_theme_extension(['props' => $props], $template))['blocks']['block:acme/panel'];
    assert_same(['type' => 'space', 'label' => 'Gap', 'default' => 'md'], $block['props']['gap']);
    assert_same('sm', $block['props']['depth']['default']);

    $values = [];
    foreach (['gap' => 'none', 'corners' => 'full', 'depth' => 'md', 'tint' => ''] as $key => $value) {
        $values[$key] = BlockTemplate::themeValue($props[$key]['type'], $value);
    }
    assert_same(
        '<div class="panel panel--full" style="--gap:0px;--radius:9999px;--shadow:var(--shadow-md,none);--tint:initial"><span>pill</span></div>',
        BlockTemplate::render($block['template'], $values, strval(...)),
    );
    assert_same(['_' => 'theme', 'css' => 'var(--radius-box)'], BlockTemplate::themeValue('radius', 'theme'));
    assert_same(['_' => 'lg', 'css' => 'var(--space-lg)'], BlockTemplate::themeValue('space', 'lg'));
    assert_same(['_' => 'none', 'css' => 'none'], BlockTemplate::themeValue('shadow', 'none'));
    // A custom shadow from the shadow editor is bounded, so its CSS is safe to print.
    $custom = BlockTemplate::themeValue('shadow', ['color' => 'primary', 'x' => 2, 'y' => 4, 'blur' => 8, 'spread' => 0, 'opacity' => 50, 'inset' => false]);
    assert_same('custom', $custom['_']);
    assert_same('2px 4px 8px 0px color-mix(in srgb,var(--c-primary,var(--c-primary)) 50%,transparent)', $custom['css']);

    // The builder edits them with the Style panel's tokens, and saved values are normalized against them.
    $spec = LayoutSchema::propSpec('block:acme/panel', ExtensionRegistry::active()->preview(Extension::compile(comet_theme_extension(['props' => $props], $template))));
    assert_same(['type' => 'enum', 'options' => ['', 'none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'], 'theme' => 'space'], array_intersect_key($spec['gap'], array_flip(['type', 'options', 'theme'])));
    assert_same(['', 'none', 'theme', 'sm', 'md', 'lg', 'full'], $spec['corners']['options']);
    assert_same('shadow', $spec['depth']['type']);

    // Theme presets are variables too, so blocks can use them.
    $css = Theme::stylesheet(Theme::defaults());
    assert_true(str_contains($css, '--shadow-md:'));
    assert_true(str_contains($css, '--shadow-hard:'));
});

test('theme value props reject values outside the theme scale and style attributes from other props', function (): void {
    $errors = comet_block_errors(static fn() => Extension::compile(comet_theme_extension(['props' => [
        'gap' => ['type' => 'space', 'label' => 'Gap', 'default' => '3xl'],
        'corners' => ['type' => 'radius', 'label' => 'Corners', 'default' => 'round'],
        'depth' => ['type' => 'shadow', 'label' => 'Shadow', 'default' => 'hard'],
        'size' => ['type' => 'space', 'label' => 'Size', 'option_labels' => ['md' => 'Medium']],
        'name' => ['type' => 'string', 'label' => 'Name'],
    ]], '<div style="--name: {{name}}">{{#depth=md}}x{{/depth}}</div>')));
    assert_same([
        'blocks/panel/block.json: props.gap: default must be empty or one of the theme spacing values: none, xs, sm, md, lg, xl, 2xl.',
        'blocks/panel/block.json: props.corners: default must be empty or one of the theme radius values: none, theme, sm, md, lg, full.',
        'blocks/panel/block.json: props.depth: default must be empty or one of the built-in shadows: none, sm, md, lg.',
        'blocks/panel/block.json: props.size: option_labels does not apply to space props.',
    ], $errors);

    $props = ['props' => ['depth' => ['type' => 'shadow', 'label' => 'Shadow'], 'name' => ['type' => 'string', 'label' => 'Name']]];
    assert_same(['blocks/panel/template.html:1: {{#depth=md}}: only enum, space and radius props can be compared with =.'],
        comet_block_errors(static fn() => Extension::compile(comet_theme_extension($props, '<div>{{#depth=md}}x{{/depth}}</div>'))));
    assert_same(['blocks/panel/template.html:1: The style attribute may only set CSS custom properties from color, space, radius, shadow props, as in style="--accent: {{accent}}". Put other CSS in style.css.'],
        comet_block_errors(static fn() => Extension::compile(comet_theme_extension($props, '<div style="--name: {{name}}"></div>'))));
});

test('an enum can become a radius prop without a breaking change when option_aliases map its options', function (): void {
    $site = comet_site_test_setup();
    $blocks = $site->blocks();
    $template = '<div style="--radius: {{corners}}">Panel</div>';
    $enum = '<div class="r-{{corners}}">Panel</div>';
    $blocks->installFiles(comet_theme_extension(['props' => ['corners' => ['type' => 'enum', 'label' => 'Corners', 'options' => ['round', 'square'], 'default' => 'round']]], $enum));
    comet_block_page($site, [LayoutSchema::node('block:acme/panel', ['corners' => 'square'])]);

    $result = $blocks->installFiles(comet_theme_extension(['props' => ['corners' => [
        'type' => 'radius', 'label' => 'Corners', 'default' => 'full', 'option_aliases' => ['round' => 'full', 'square' => 'none'],
    ]]], $template, ['version' => '1.1.0']));
    assert_same([], $result['changes']);
    $site = new SiteRepository();
    assert_same('none', $site->layout('about', 'detail')[0]['children'][0]['children'][0]['children'][0]['props']['corners']);
    $entry = \CometCMS\Content\ContentRepository::make()->findByIdentifier('about', 'about');
    $html = (new PageRenderer(comet_site_test_context($site)))->entryPage($site->types()['about'], $entry);
    assert_true(str_contains($html, 'style="--radius:0px"'));

    // An option without an alias is still reported.
    try {
        $blocks->installFiles(comet_theme_extension(['props' => ['corners' => ['type' => 'enum', 'label' => 'Corners', 'options' => ['round', 'square']]]], $enum, ['version' => '1.2.0']), acceptChanges: true);
        $blocks->installFiles(comet_theme_extension(['props' => ['corners' => ['type' => 'radius', 'label' => 'Corners', 'option_aliases' => ['round' => 'full']]]], $template, ['version' => '1.3.0']));
        throw new RuntimeException('Expected a review.');
    } catch (\CometCMS\Extensions\ExtensionReview $review) {
        assert_same(['acme/panel: the prop "corners" no longer offers square. Instances using them fall back to the default.'], $review->changes);
    }
});

test('blocks reuse shared props from the manifest and inherit their translations', function (): void {
    $manifest = ['shared_props' => [
        'corners' => ['type' => 'radius', 'label' => 'Corners', 'default' => 'md', 'group' => 'Look'],
        'density' => ['type' => 'enum', 'label' => 'Spacing', 'options' => ['compact', 'roomy'], 'option_labels' => ['roomy' => 'Roomy']],
    ]];
    $block = ['props' => [
        'corners' => ['use' => 'corners'],
        'card_corners' => ['use' => 'corners', 'label' => 'Card corners', 'default' => 'lg'],
        'density' => ['use' => 'density', 'visible_when' => ['corners' => ['md', 'lg']]],
        'items' => ['type' => 'list', 'label' => 'Items', 'fields' => ['corners' => ['use' => 'corners']]],
    ]];
    $i18n = ['i18n/de.json' => json_encode([
        'shared_props' => ['corners' => ['label' => 'Ecken', 'group' => 'Aussehen'], 'density' => ['options' => ['compact' => 'Kompakt']]],
        'blocks' => ['panel' => ['props' => ['density' => ['label' => 'Abstände']]]],
    ])];
    $extension = Extension::compile(comet_theme_extension($block, '<div style="--r: {{corners}}"></div>', $manifest, $i18n));
    assert_false(array_key_exists('shared_props', $extension));
    $props = $extension['blocks']['block:acme/panel']['props'];
    assert_same(['type' => 'radius', 'label' => 'Corners', 'group' => 'Look', 'default' => 'md', 'use' => 'corners', 'l10n' => ['de' => ['label' => 'Ecken', 'group' => 'Aussehen']]], $props['corners']);
    // A block's own wording wins over the shared translation of that text.
    assert_same('Card corners', $props['card_corners']['label']);
    assert_same('lg', $props['card_corners']['default']);
    assert_same(['group' => 'Aussehen'], $props['card_corners']['l10n']['de']);
    assert_same(['option_labels' => ['compact' => 'Kompakt'], 'label' => 'Abstände'], $props['density']['l10n']['de']);
    assert_same(['roomy' => 'Roomy'], $props['density']['option_labels']);
    assert_same('radius', $props['items']['fields']['corners']['type']);

    $errors = comet_block_errors(static fn() => Extension::compile(comet_theme_extension(['props' => [
        'a' => ['use' => 'missing'],
        'b' => ['use' => 'corners', 'type' => 'string'],
        'c' => ['use' => 'broken'],
    ]], '<div></div>', ['shared_props' => [
        'corners' => ['type' => 'radius', 'label' => 'Corners'],
        'broken' => ['type' => 'enum', 'label' => 'Broken'],
        'nested' => ['use' => 'corners'],
        'conditional' => ['type' => 'bool', 'label' => 'Conditional', 'visible_when' => ['x' => true]],
    ]])));
    assert_same([
        'comet-extension.json: shared_props.broken: options must be a list of 1 to 50 distinct values made of letters, digits, hyphens and underscores, for example ["info", "success"].',
        'comet-extension.json: shared_props.nested: a shared prop cannot use another shared prop.',
        'comet-extension.json: shared_props.conditional: visible_when depends on the block, so set it where the block uses the prop.',
        'blocks/panel/block.json: props.a: use: there is no shared prop "missing". Declare it in comet-extension.json under shared_props, which has: corners, broken.',
        'blocks/panel/block.json: props.b: type comes from the shared prop "corners". Remove it here, or declare a separate prop.',
        'blocks/panel/block.json: props.c: use: the shared prop "broken" has errors, reported for comet-extension.json.',
    ], $errors);
});

test('manifests, blocks and translations accept a $schema reference for editors', function (): void {
    $files = comet_theme_extension(['$schema' => '../../schemas/block.schema.json', 'props' => []], '<div></div>', ['$schema' => 'schemas/comet-extension.schema.json'], ['i18n/de.json' => '{"$schema": "x", "title": "Marken"}']);
    $extension = Extension::compile($files);
    assert_same('Tokens', $extension['title']);
    assert_false(array_key_exists('$schema', $extension));
});
