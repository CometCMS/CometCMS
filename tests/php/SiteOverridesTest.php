<?php

declare(strict_types=1);

use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\StyleVariants;
use CometCMS\Site\Theme;

test('device and state overrides keep only offered keys and set values', function (): void {
    $heading = LayoutSchema::node('heading', [], ['padding_top' => 'lg']);
    $heading['responsive'] = [
        'tablet' => ['style' => ['padding_top' => 'sm', 'animation' => 'fade', 'font_size' => ''], 'props' => ['level' => 'h1']],
        'mobile' => ['style' => ['font_size' => 'nope']],
        'watch' => ['style' => ['padding_top' => 'xl']],
    ];
    $heading['states'] = ['hover' => ['style' => ['text_color' => 'primary', 'padding_top' => 'xl']], 'focus' => 'invalid'];
    $row = LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$heading])]);
    $row['responsive'] = ['mobile' => ['props' => ['gap' => 'none', 'justify' => 'end']]];
    $section = LayoutSchema::node('section', [], [], [$row]);

    $normalized = LayoutSchema::normalize([$section]);
    $savedRow = $normalized[0]['children'][0];
    $savedHeading = $savedRow['children'][0]['children'][0];
    // Unknown devices and keys, empty values and keys that do not render as classes are dropped.
    assert_same(['tablet' => ['style' => ['padding_top' => 'sm']]], $savedHeading['responsive']);
    assert_same(['hover' => ['style' => ['text_color' => 'primary']]], $savedHeading['states']);
    assert_same(['mobile' => ['props' => ['gap' => 'none']]], $savedRow['responsive']);
    assert_false(isset($normalized[0]['responsive']));
    assert_same($normalized, LayoutSchema::normalize($normalized));

    $spec = LayoutSchema::spec();
    assert_same(['tablet', 'mobile'], $spec['devices']);
    assert_same(['size', 'full_width'], $spec['kinds']['button']['responsive']['props']);
    assert_same(['button_color', 'button_text_color', 'button_border_color'], $spec['kinds']['button']['states']['props']);
    assert_false(in_array('link_color', $spec['kinds']['button']['style'], true));
});

test('overrides render as prefixed classes and buttons carry their own', function (): void {
    comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context());
    $column = LayoutSchema::node('column', ['vertical_align' => 'center'], ['background' => 'surface']);
    $column['responsive'] = ['tablet' => ['props' => ['vertical_align' => 'start'], 'style' => ['padding_top' => 'xs']], 'mobile' => ['style' => ['background' => 'primary']]];
    $column['states'] = ['hover' => ['style' => ['background' => 'accent']]];
    $button = LayoutSchema::node('button', ['link' => ['type' => 'url', 'url' => 'https://example.com'], 'size' => 'lg', 'button_color' => 'secondary']);
    $button['responsive'] = ['mobile' => ['props' => ['size' => 'sm', 'full_width' => true]]];
    $button['states'] = ['hover' => ['props' => ['button_color' => 'accent']], 'focus' => ['props' => ['button_border_color' => 'text']]];
    $column['children'] = [$button];
    $nodes = LayoutSchema::normalizeComponent([$column], 'column');

    $html = $renderer->render($nodes);
    foreach (['c-self-center', 'c-t-self-start', 'c-t-pt-xs', 'c-m-bg-primary', 'c-h-bg-accent', 'c-stateful'] as $class) {
        assert_true(str_contains($html, $class), $class);
    }
    assert_true(str_contains($html, 'class="c-button c-button-primary c-button-lg c-m-button-sm c-m-button-block c-btn-bg-secondary c-h-btn-bg-accent c-f-btn-bdc-text"'));
});

test('a button\'s background and text color styles color the button, not a strip behind it', function (): void {
    comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context());
    $button = LayoutSchema::node('button', ['link' => ['type' => 'url', 'url' => 'https://example.com']], ['background' => 'accent', 'text_color' => 'text']);
    $button['responsive'] = ['mobile' => ['style' => ['background' => 'surface']]];
    $button['states'] = ['hover' => ['style' => ['background' => 'secondary']]];
    $html = $renderer->render(LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$button])], 'column'));
    assert_true(str_contains($html, 'class="c-button c-button-primary c-button-md c-btn-bg-accent c-btn-fg-text c-m-btn-bg-surface c-h-btn-bg-secondary"'), $html);
    assert_true((bool) preg_match('~class="c-el c-el-button(?![^"]*c-(?:[mh]-)?(?:bg|fg)-)[^"]*"~', $html), $html);

    // The button's own color wins over the style that stands in for it.
    $button['props']['button_color'] = 'primary';
    $html = $renderer->render(LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$button])], 'column'));
    assert_true(str_contains($html, 'c-btn-bg-primary c-btn-fg-text'));
    assert_false(str_contains($html, 'c-btn-bg-accent'));

    $css = StyleVariants::css(Theme::baseStylesheet());
    assert_true(str_contains($css, '.c-button.c-m-btn-bg-surface{'));
});

test('buttons show an optional bundled icon before or after their label', function (): void {
    comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context());
    $props = ['label' => 'Next', 'link' => ['type' => 'url', 'url' => 'https://example.com']];
    $plain = $renderer->render(LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [LayoutSchema::node('button', $props)])], 'column'));
    assert_true(str_contains($plain, '<span class="c-button-label">Next</span></a>'));
    assert_false(str_contains($plain, '<svg'));

    $button = LayoutSchema::node('button', $props + ['icon' => 'mdi:arrow-right', 'icon_position' => 'end', 'icon_gap' => 'lg']);
    $html = $renderer->render(LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$button])], 'column'));
    assert_true(str_contains($html, 'c-button-gap-lg'));
    assert_true((bool) preg_match('~<span class="c-button-label">Next</span><svg class="c-button-icon"[^>]*aria-hidden="true">~', $html));

    $button['props']['icon'] = 'mdi:not-bundled';
    $saved = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$button])], 'column');
    assert_same('', $saved[0]['children'][0]['props']['icon']);
});

test('the stylesheet derives device and state classes from its utilities', function (): void {
    $css = StyleVariants::css(Theme::baseStylesheet() . '.c-bg-brand{background-color:var(--c-brand);}');
    assert_true(str_contains($css, '@media (max-width: 1023px){'));
    assert_true(str_contains($css, '.c-t-pt-lg{'));
    assert_true(str_contains($css, '.c-m-bg-brand{background-color:var(--c-brand);}'));
    assert_true(str_contains($css, '.c-h-bg-primary:is(:hover,.c-preview-hover){'));
    assert_true(str_contains($css, '.c-button.c-f-btn-bg-primary:is(:focus-visible,:has(:focus-visible),.c-preview-focus){'));
    // Link colors change with the hovered link, and selectors inside :not() describe the normal value.
    assert_true(str_contains($css, '.c-h-lc-primary a:not(.c-button):is(:hover,.c-preview-hover *){'));
    assert_false(str_contains($css, 'c-t-py-none'));
    assert_false(str_contains($css, 'c-t-bg-size'));
    // Mobile follows tablet by coming later.
    assert_true(strpos($css, 'max-width: 1023px') < strpos($css, 'max-width: 767px'));
});

test('mcp builder reads and writes device and state overrides', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $target = ['collection' => 'pages', 'variant' => 'detail'];
    [, $updated] = comet_test_mcp_tool($token, 'update_element', $target + [
        'revision' => $layout['revision'], 'element_id' => 'heading', 'props' => [],
        'responsive' => ['mobile' => ['style' => ['font_size' => 'xl']]], 'states' => ['hover' => ['style' => ['text_color' => 'accent']]],
    ]);
    assert_same(['mobile' => ['style' => ['font_size' => 'xl']]], $updated['data']['element']['responsive']);
    assert_same(['hover' => ['style' => ['text_color' => 'accent']]], $updated['data']['element']['states']);

    // Values the schema would drop are refused rather than lost.
    [$response] = comet_test_mcp_tool($token, 'update_element', $target + [
        'revision' => $updated['data']['revision'], 'element_id' => 'heading', 'props' => [], 'responsive' => ['mobile' => ['style' => ['animation' => 'fade']]],
    ]);
    assert_true($response['result']['isError']);
    [, $schema] = comet_test_mcp_tool($token, 'get_builder_schema', ['kind' => 'button']);
    assert_same(['size', 'full_width'], $schema['data']['responsive']['props']);
    assert_same(['hover', 'focus'], $schema['data']['states']['states']);
});

test('positioned elements render their pin, layer and per-device offsets', function (): void {
    comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context());
    $heading = LayoutSchema::node('heading', [], ['position' => 'absolute', 'pin' => 'bottom-right', 'offset_x' => -24, 'offset_y' => 12, 'z_index' => '2', 'opacity' => '75']);
    $heading['responsive'] = ['tablet' => ['style' => ['offset_x' => 0]], 'mobile' => ['style' => ['position' => 'static']]];
    $heading['states'] = ['hover' => ['style' => ['opacity' => '50', 'offset_x' => 5]]];
    $nodes = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$heading])], 'column');

    // Hover cannot move an element.
    assert_same(['hover' => ['style' => ['opacity' => '50']]], $nodes[0]['children'][0]['states']);
    $html = $renderer->render($nodes);
    foreach (['c-pos-absolute', 'c-pin-bottom-right', 'c-z-2', 'c-op-75', 'c-m-pos-static', 'c-h-op-50'] as $class) {
        assert_true(str_contains($html, $class), $class);
    }
    // A tablet offset of 0 still resets the desktop one.
    assert_true(str_contains($html, 'style="--c-ox:-24;--c-oy:12;--c-t-ox:0"'));

    $css = StyleVariants::css(Theme::baseStylesheet());
    assert_true(str_contains($css, '.c-t-pos-absolute{'));
    assert_true(str_contains($css, '.c-m-pin-center{'));
    assert_true(str_contains($css, '.c-h-op-50:is(:hover,.c-preview-hover){'));
});

test('fixed positioning survives saving and supports device overrides and rendering', function (): void {
    comet_site_test_setup();
    $heading = LayoutSchema::node('heading', [], ['position' => 'fixed', 'pin' => 'bottom-right', 'offset_x' => -20, 'offset_y' => -30, 'z_index' => '5']);
    $heading['responsive'] = ['tablet' => ['style' => ['position' => 'absolute']], 'mobile' => ['style' => ['position' => 'static']]];
    $text = LayoutSchema::node('text');
    $text['responsive'] = ['mobile' => ['style' => ['position' => 'fixed', 'pin' => 'bottom', 'offset_y' => -10]]];
    $nodes = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$heading, $text])], 'column');
    assert_same('fixed', $nodes[0]['children'][0]['style']['position']);
    assert_same('fixed', $nodes[0]['children'][1]['responsive']['mobile']['style']['position']);
    assert_same($nodes, LayoutSchema::normalizeComponent($nodes, 'column'));
    assert_true(in_array('fixed', LayoutSchema::spec()['style']['position']['options'], true));

    $html = (new NodeRenderer(comet_site_test_context()))->render($nodes);
    foreach (['c-pos-fixed', 'c-t-pos-absolute', 'c-m-pos-static', 'c-m-pos-fixed', 'c-pin-bottom-right', 'c-z-5', '--c-ox:-20', '--c-oy:-30', '--c-m-oy:-10'] as $value) {
        assert_true(str_contains($html, $value), $value);
    }
    $css = StyleVariants::css(Theme::baseStylesheet());
    assert_true(str_contains($css, '.c-t-pos-fixed{'));
    assert_true(str_contains($css, '.c-m-pos-fixed{'));
});

test('columns support free and fixed positioning with responsive pins and offsets', function (): void {
    comet_site_test_setup();
    $column = LayoutSchema::node('column', ['span' => ['mobile' => 12, 'tablet' => 6, 'desktop' => 3]], ['position' => 'fixed', 'pin' => 'bottom-left', 'offset_x' => 20, 'offset_y' => -30], [LayoutSchema::node('heading')]);
    $column['responsive'] = ['tablet' => ['style' => ['position' => 'absolute', 'offset_x' => 0]], 'mobile' => ['style' => ['position' => 'static']]];
    $column['states'] = ['hover' => ['style' => ['position' => 'fixed', 'offset_x' => 10, 'opacity' => '50']]];
    $nodes = LayoutSchema::normalizeComponent([$column], 'column');
    assert_same('fixed', $nodes[0]['style']['position']);
    assert_same($column['responsive'], $nodes[0]['responsive']);
    assert_same(['hover' => ['style' => ['opacity' => '50']]], $nodes[0]['states']);
    assert_same($nodes, LayoutSchema::normalizeComponent($nodes, 'column'));
    foreach (['position', 'pin', 'offset_x', 'offset_y'] as $key) {
        assert_true(in_array($key, LayoutSchema::spec()['kinds']['column']['responsive']['style'], true));
    }
    $html = (new NodeRenderer(comet_site_test_context()))->render($nodes);
    foreach (['c-col-12', 'c-t-col-6', 'c-d-col-3', 'c-pos-fixed', 'c-t-pos-absolute', 'c-m-pos-static', 'c-pin-bottom-left', '--c-ox:20', '--c-oy:-30', '--c-t-ox:0'] as $value) {
        assert_true(str_contains($html, $value), $value);
    }
});
