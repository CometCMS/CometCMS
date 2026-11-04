<?php

declare(strict_types=1);

use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\Shapes;

test('section dividers keep known shapes and bounded settings, with sizes per device', function (): void {
    $section = LayoutSchema::node('section', [
        'divider_top' => 'wave', 'divider_top_color' => 'custom-brand', 'divider_top_height' => 9999, 'divider_top_width' => 5, 'divider_top_flip' => true,
        'divider_bottom' => '<script>', 'divider_bottom_color' => 'red;x', 'divider_bottom_invert' => 'yes',
    ], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column')])]);
    $section['responsive'] = ['tablet' => ['props' => ['divider_top_height' => 40, 'divider_top_width' => 250]], 'mobile' => ['props' => ['divider_top_height' => -5, 'divider_top' => 'curve']]];
    $props = LayoutSchema::normalize([$section])[0]['props'];
    $normalized = LayoutSchema::normalize([$section])[0];

    assert_same('wave', $props['divider_top']);
    assert_same('custom-brand', $props['divider_top_color']);
    assert_same(500, $props['divider_top_height']);
    assert_same(20, $props['divider_top_width']);
    assert_same(100, $props['divider_bottom_width']);
    assert_true($props['divider_top_flip']);
    assert_same('none', $props['divider_bottom']);
    assert_same('background', $props['divider_bottom_color']);
    assert_false($props['divider_bottom_invert']);
    // Only the height and width may change per device.
    assert_same(['tablet' => ['props' => ['divider_top_height' => 40, 'divider_top_width' => 250]], 'mobile' => ['props' => ['divider_top_height' => 10]]], $normalized['responsive']);
    assert_same(['none', 'wave', 'curve', 'slope', 'triangle', 'double_wave', 'asymmetric_curve', 'mountains', 'steps', 'scallops', 'arrow', 'zigzag'], LayoutSchema::spec()['kinds']['section']['props']['divider_top']['options']);
    assert_same('divider', LayoutSchema::spec()['kinds']['section']['props']['divider_bottom']['picker']);
});

test('sections render dividers behind their content in the theme color and leave sections without any unchanged', function (): void {
    comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context());
    $content = [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading')])])];
    $plain = $renderer->render([LayoutSchema::node('section', [], [], $content)]);
    assert_false(str_contains($plain, 'c-shape'));

    $section = LayoutSchema::node('section', ['divider_top' => 'zigzag', 'divider_top_height' => 120, 'divider_bottom' => 'curve', 'divider_bottom_color' => 'primary', 'divider_bottom_flip' => true, 'divider_bottom_invert' => true, 'divider_bottom_width' => 200], [], $content);
    $section['responsive'] = ['mobile' => ['props' => ['divider_bottom_height' => 30, 'divider_bottom_width' => 150]]];
    $html = $renderer->render(LayoutSchema::normalize([$section]));

    assert_true(str_contains($html, 'c-section c-section-contained c-has-shape'));
    assert_true(str_contains($html, '<div class="c-shape c-shape-top c-fg-background" style="--c-shape-h:120" aria-hidden="true"><svg viewBox="0 0 1200 120" preserveAspectRatio="none"'));
    // A width of its own draws the shape as a repeating mask, without the inline SVG.
    assert_true(str_contains($html, '<div class="c-shape c-shape-bottom c-fg-primary c-shape-tiled" style="--c-shape-h:80;--c-shape-w:200;--c-m-shape-h:30;--c-m-shape-w:150;--c-shape-mask:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox'));
    assert_true(str_contains($html, '%3C%2Fsvg%3E)" aria-hidden="true"></div>'));
    // Inverted: the whole box with the shape cut out; at the bottom that already faces its edge, so it is only flipped sideways.
    assert_true(str_contains(Shapes::dividerSvg('curve', 'bottom', true, true), 'd="M0,0H1200V120H0ZM0,0Q600,240 1200,0Z" transform="matrix(-1 0 0 1 1200 0)"'));
    assert_true(str_contains(Shapes::dividerSvg('wave', 'bottom', false, false), 'transform="matrix(1 0 0 -1 0 120)"'));
    assert_false(str_contains(Shapes::dividerSvg('wave', 'top', false, false), 'transform'));
    assert_same(24, substr_count(Shapes::dividerPath('zigzag'), 'L') / 2);
    // Dividers come before the content, so they stay behind it.
    assert_true(strpos($html, 'c-shape-bottom') < strpos($html, 'c-container'));
});

test('masks keep known shapes and drop their options without one', function (): void {
    $nodes = LayoutSchema::normalize([LayoutSchema::node('section', [], ['mask' => 'heart', 'mask_fit' => 'contain', 'mask_flip_x' => true, 'mask_flip_y' => 'yes'], [
        LayoutSchema::node('row', [], ['mask' => '<script>', 'mask_fit' => 'contain', 'mask_flip_x' => true], [LayoutSchema::node('column', [], ['mask' => 'blob'], [
            LayoutSchema::node('image', [], ['mask' => 'circle', 'mask_fit' => 'sideways']),
        ])]),
    ])]);

    assert_same(['mask' => 'heart', 'mask_fit' => 'contain', 'mask_flip_x' => true], $nodes[0]['style']);
    assert_same([], $nodes[0]['children'][0]['style']);
    assert_same(['mask' => 'blob'], $nodes[0]['children'][0]['children'][0]['style']);
    assert_same(['mask' => 'circle'], $nodes[0]['children'][0]['children'][0]['children'][0]['style']);
    $spec = LayoutSchema::spec();
    assert_same(Shapes::masks(), $spec['style']['mask']['options']);
    assert_same('mask', $spec['style']['mask']['picker']);
    assert_true(in_array('mask', $spec['kinds']['heading']['style'], true));
    assert_false(in_array('mask', $spec['kinds']['site_content']['style'], true));
    // Every shape has a path the picker draws, inside its 100×100 box.
    foreach (array_slice(Shapes::masks(), 1) as $shape) {
        $path = $spec['shapes']['mask']['paths'][$shape] ?? '';
        assert_true(str_starts_with($path, 'M') && str_ends_with($path, 'Z'), $shape);
        preg_match_all('/-?\d+(?:\.\d+)?/', preg_replace('/A[^A-Z]*?(?=[A-Z])/', 'A', $path) ?? '', $numbers);
        assert_true(array_filter($numbers[0], static fn(string $n): bool => (float) $n < -20 || (float) $n > 120) === [], $shape);
    }
});

test('masked nodes render their shape as a mask image and unmasked ones stay unchanged', function (): void {
    comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context());
    $plain = $renderer->render(LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading')])])])]));
    assert_false(str_contains($plain, 'c-mask'));

    $html = $renderer->render(LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [
        LayoutSchema::node('heading', [], ['mask' => 'star', 'mask_fit' => 'contain', 'mask_flip_y' => true]),
        LayoutSchema::node('text', [], ['mask' => 'wave']),
    ])])])]));

    assert_true(str_contains($html, 'c-mask c-mask-contain" style="--c-mask:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22'));
    assert_true(str_contains($html, rawurlencode('transform="matrix(1 0 0 -1 0 100)"')));
    assert_matches('/class="c-el c-el-text[^"]* c-mask" style="--c-mask:url\(data:image\/svg\+xml,[^"]*\)"/', $html);
    assert_true(str_contains(Shapes::maskSvg('circle'), 'width="100" height="100" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M50,0A50,50'));
    assert_same('', Shapes::maskImage('none'));
    assert_same(32, substr_count(Shapes::maskPath('badge'), 'L') + 1);
});
