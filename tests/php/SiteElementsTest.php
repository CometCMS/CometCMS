<?php

declare(strict_types=1);

use CometCMS\Site\Icons;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\SmartValue;

test('gallery elements save ordered media and nested bindings without losing identity', function (): void {
    $galleryNode = LayoutSchema::node('gallery', ['media' => SmartValue::reference('photos', 'item'), 'columns' => 4, 'ratio' => '4:3', 'position' => 'top', 'label' => true, 'link' => ['type' => 'current', 'new_tab' => false]], ['radius' => 'lg']);
    $galleryNode['name'] = 'Project images';
    $column = LayoutSchema::node('column', [], [], [
        LayoutSchema::node('gallery', ['media' => ['second.svg', null, 'first.svg', 'second.svg'], 'columns' => 99]),
        LayoutSchema::node('field', ['source' => SmartValue::reference('steps'), 'display' => 'items'], [], [$galleryNode]),
        LayoutSchema::node('field', ['source' => SmartValue::reference('files'), 'display' => 'files']),
    ]);
    $normalized = LayoutSchema::normalizeComponent([$column], 'column');
    $children = $normalized[0]['children'];
    assert_same(['second.svg', 'first.svg'], $children[0]['props']['media']);
    assert_same(6, $children[0]['props']['columns']);
    $gallery = $children[1]['children'][0];
    assert_same('gallery', $gallery['kind']);
    assert_same($galleryNode['id'], $gallery['id']);
    assert_same($galleryNode['name'], $gallery['name']);
    assert_same($galleryNode['style'], $gallery['style']);
    assert_same($galleryNode['props']['media'], $gallery['props']['media']);
    foreach (['columns', 'ratio', 'position', 'label', 'link'] as $key) assert_same($galleryNode['props'][$key], $gallery['props'][$key]);
    assert_same('files', $children[2]['props']['display']);
    assert_same($normalized, LayoutSchema::normalizeComponent($normalized, 'column'));
    assert_same('media_list', LayoutSchema::spec()['kinds']['gallery']['props']['media']['binding']);
});

test('galleries render ordered media bindings captions and one-column grids while respecting visibility', function (): void {
    comet_site_test_setup();
    foreach (['first.svg', 'second.svg'] as $file) file_put_contents(comet_test_workspace_path() . '/media/' . $file, '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"></svg>');
    $media = new \CometCMS\Media\MediaRepository();
    $media->updateMeta('second.svg', 'Alt "two"', 'Title <two>');
    $ctx = comet_site_test_context();
    $renderer = new NodeRenderer($ctx);
    $node = LayoutSchema::node('gallery', ['media' => ['second.svg', 'missing.svg', 'first.svg'], 'columns' => 1, 'gap' => 'lg', 'captions' => true, 'ratio' => '4:3', 'fit' => 'contain', 'position' => 'top']);
    $html = $renderer->render([$node]);
    assert_same(2, substr_count($html, '<img'));
    assert_true(strpos($html, 'second.svg') < strpos($html, 'first.svg'));
    assert_true(str_contains($html, 'c-cols-1 c-gap-lg'));
    assert_true(str_contains($html, 'c-ratio-4-3 c-fit-contain c-obj-pos-top'));
    assert_true(str_contains($html, 'alt="Alt &quot;two&quot;"'));
    assert_true(str_contains($html, '<figcaption>Title &lt;two&gt;</figcaption>'));
    $node['props']['captions'] = false;
    assert_false(str_contains($renderer->render([$node]), '<figcaption>'));

    $state = ['type' => ['fields' => ['photos' => ['type' => 'media', 'multiple' => true, 'label' => 'Project photos']]], 'entry' => ['photos' => ['second.svg', 'first.svg']]];
    $node['props']['media'] = SmartValue::reference('photos');
    $node['props']['label'] = true;
    $html = $renderer->render([$node], $state);
    assert_same(2, substr_count($html, '<img'));
    assert_true(str_contains($html, 'Project photos</div>'));
    $state['type']['fields']['photos']['type'] = 'text';
    assert_same('', $renderer->render([$node], $state));
    $state['type']['fields']['photos']['type'] = 'media';
    $media->updateVisibility('second.svg', 'private');
    $html = (new NodeRenderer(comet_site_test_context()))->render([$node], $state);
    assert_same(1, substr_count($html, '<img'));
    assert_false(str_contains($html, 'second.svg'));
    $node['props']['media'] = [];
    assert_same('', $renderer->render([$node]));
    $ctx->annotate = true;
    assert_true(str_contains($renderer->render([$node]), 'Choose gallery images'));
});

test('masonry galleries retain natural heights and restore saved crops when disabled', function (): void {
    comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/tall.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="40"></svg>');
    $media = new \CometCMS\Media\MediaRepository();
    $media->updateMeta('tall.svg', 'Tall image', 'Caption');
    $gallery = LayoutSchema::node('gallery', ['media' => ['tall.svg'], 'masonry' => true, 'ratio' => '4:3', 'fit' => 'contain', 'position' => 'top', 'columns' => 4, 'gap' => 'lg', 'captions' => true]);
    $saved = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$gallery])], 'column')[0]['children'][0];
    assert_same(true, $saved['props']['masonry']);
    assert_same('4:3', $saved['props']['ratio']);
    assert_same(false, LayoutSchema::node('gallery')['props']['masonry']);
    $renderer = new NodeRenderer(comet_site_test_context());
    $html = $renderer->render([$saved]);
    assert_true(str_contains($html, 'c-gallery-masonry c-gallery c-cols-4 c-gap-lg'));
    assert_true(str_contains($html, '--c-gallery-columns:4;--c-gallery-gap:var(--space-lg)'));
    assert_true(str_contains($html, '<figcaption>Caption</figcaption>'));
    assert_true(str_contains($html, 'alt="Tall image"'));
    assert_false(str_contains($html, 'c-ratio'));
    $saved['props']['gap'] = 'none';
    assert_true(str_contains($renderer->render([$saved]), '--c-gallery-gap:0px'));
    $saved['props']['masonry'] = false;
    $html = $renderer->render([$saved]);
    assert_false(str_contains($html, 'c-gallery-masonry'));
    assert_true(str_contains($html, 'c-grid c-gallery'));
    assert_true(str_contains($html, 'c-ratio-4-3 c-fit-contain c-obj-pos-top'));
});

test('default layouts use galleries for multiple media including repeater items', function (): void {
    $type = ['name' => 'projects', 'fields' => ['photos' => ['type' => 'media', 'multiple' => true], 'steps' => ['type' => 'repeater', 'subfields' => [['key' => 'photos', 'type' => 'media', 'multiple' => true]]]]];
    $layout = LayoutSchema::defaultLayout($type, 'detail');
    $elements = $layout[0]['children'][0]['children'][0]['children'];
    $galleries = array_values(array_filter($elements, static fn(array $node): bool => $node['kind'] === 'gallery'));
    assert_same(1, count($galleries));
    assert_same(SmartValue::reference('photos'), $galleries[0]['props']['media']);
    $repeaters = array_values(array_filter($elements, static fn(array $node): bool => $node['kind'] === 'field'));
    assert_same('gallery', $repeaters[0]['children'][0]['kind']);
    assert_same(SmartValue::reference('photos', 'item'), $repeaters[0]['children'][0]['props']['media']);
    $saved = LayoutSchema::normalize($layout)[0]['children'][0]['children'][0]['children'];
    assert_same($galleries[0], $saved[2]);
    assert_same($repeaters[0]['children'][0], $saved[3]['children'][0]);
});

test('accordion and icon elements survive layout saves and repeater templates', function (): void {
    $elements = [
        LayoutSchema::node('accordion', ['items' => [['title' => 'Question', 'html' => '<p onclick="evil()">Answer<script>evil()</script></p>', 'open' => true]]]),
        LayoutSchema::node('icon', ['icon' => 'mdi:heart-outline', 'size' => 72]),
    ];
    $nodes = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [
        ...$elements,
        LayoutSchema::node('field', ['source' => SmartValue::reference('steps')], [], $elements),
    ])], 'column')[0]['children'];

    assert_same(['accordion', 'icon', 'field'], array_column($nodes, 'kind'));
    assert_same(['accordion', 'icon'], array_column($nodes[2]['children'], 'kind'));
    assert_same('<p>Answer</p>', $nodes[0]['props']['items'][0]['html']);
    assert_same(true, $nodes[0]['props']['items'][0]['open']);
    assert_same('mdi:heart-outline', $nodes[1]['props']['icon']);
    assert_same(72, $nodes[1]['props']['size']);
    $spec = LayoutSchema::spec();
    assert_true(in_array('accordion', $spec['elements'], true));
    assert_true(in_array('icon', $spec['elements'], true));
    assert_same(Icons::names(), $spec['kinds']['icon']['props']['icon']['options']);
});

test('accordion renders safe native disclosure markup and supports content bindings', function (): void {
    $site = comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context($site));
    $node = LayoutSchema::node('accordion', ['items' => [['title' => '<script>Question</script>', 'html' => '<p onclick="evil()">Answer</p>']]]);
    $html = $renderer->render([$node]);
    assert_true(str_contains($html, '<details class="c-accordion">'));
    assert_true(str_contains($html, '&lt;script&gt;Question&lt;/script&gt;'));
    assert_true(str_contains($html, '<div class="c-accordion-content c-prose"><p>Answer</p>'));
    assert_false(str_contains($html, 'onclick'));
    $node['props']['items'][0]['open'] = true;
    assert_true(str_contains($renderer->render([$node]), '<details class="c-accordion" open>'));

    $bound = LayoutSchema::node('accordion', ['items' => [[
        'title' => SmartValue::reference('title', 'site'),
        'html' => ['type' => 'template', 'parts' => ['<p>About ', SmartValue::reference('title', 'site'), '</p>']],
    ]]]);
    $html = $renderer->render([$bound]);
    assert_true(str_contains($html, '>Comet Site</summary>'));
    assert_true(str_contains($html, '<p>About Comet Site</p>'));
});

test('tables keep rectangular rows of inline cells through saves and repeater templates', function (): void {
    $table = LayoutSchema::node('table', ['rows' => [
        ['Plan', '<strong onclick="evil()">Price</strong><script>evil()</script>'],
        ['Basic', '<p>9 €<br>monthly</p>', 'extra'],
        ['Pro'],
        'not a row',
    ], 'variant' => 'grid', 'mobile' => 'sideways']);
    $nodes = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [
        $table,
        LayoutSchema::node('field', ['source' => SmartValue::reference('steps')], [], [$table]),
    ])], 'column')[0]['children'];

    assert_same([['Plan', '<strong>Price</strong>', ''], ['Basic', '9 €<br>monthly', 'extra'], ['Pro', '', '']], $nodes[0]['props']['rows']);
    assert_same('grid', $nodes[0]['props']['variant']);
    assert_same('scroll', $nodes[0]['props']['mobile']);
    assert_same('table', $nodes[1]['children'][0]['kind']);
    assert_same($nodes, LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], $nodes)], 'column')[0]['children']);
    $spec = LayoutSchema::spec();
    assert_true(in_array('table', $spec['elements'], true));
    assert_true(in_array('table', $spec['item_elements'], true));
    assert_true($spec['kinds']['table']['props']['rows']['localized']);

    $wide = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [LayoutSchema::node('table', ['rows' => [array_fill(0, 40, 'x')]])])], 'column');
    assert_same(LayoutSchema::TABLE_MAX_COLUMNS, count($wide[0]['children'][0]['props']['rows'][0]));
    assert_same('Our plans Plan Price Basic 9 € monthly extra Pro', LayoutSchema::text([['kind' => 'table', 'props' => ['caption' => 'Our plans'] + $nodes[0]['props']]], 'en', 'en'));
});

test('tables render header rows and columns captions stacked labels and cell bindings', function (): void {
    $site = comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context($site));
    $node = LayoutSchema::node('table', ['rows' => [['Plan', 'Price'], ['Basic', '9 €'], ['Site', SmartValue::reference('title', 'site')]], 'caption' => 'Prices <2026>', 'header_column' => true, 'striped' => true, 'mobile' => 'stack']);
    $html = $renderer->render([$node]);
    assert_true(str_contains($html, '<div class="c-table-wrap c-table-stack"><table class="c-table c-table-lines c-table-striped c-table-header-column">'));
    assert_true(str_contains($html, '<caption>Prices &lt;2026&gt;</caption>'));
    assert_true(str_contains($html, '<thead><tr><th scope="col">Plan</th><th scope="col">Price</th></tr></thead><tbody>'));
    assert_true(str_contains($html, '<tr><th scope="row" data-label="Plan">Basic</th><td data-label="Price">9 €</td></tr>'));
    assert_true(str_contains($html, '<td data-label="Price">Comet Site</td></tr></tbody></table>'));
    assert_false(str_contains($html, 'data-cell'));

    $plain = LayoutSchema::node('table', ['rows' => [['a', 'b']], 'header_row' => false]);
    $html = $renderer->render([$plain]);
    assert_true(str_contains($html, '<tbody><tr><td>a</td><td>b</td></tr></tbody>'));
    assert_false(str_contains($html, '<thead>'));
});

test('icons render bundled SVG with theme styling and accessible optional links', function (): void {
    $site = comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context($site));
    $node = LayoutSchema::node('icon', ['icon' => 'mdi:heart-outline', 'size' => 72], ['text_color' => 'primary', 'align' => 'center']);
    $html = $renderer->render([$node]);
    assert_true(str_contains($html, 'c-fg-primary c-ta-center'));
    assert_true(str_contains($html, 'width="72" height="72"'));
    assert_true(str_contains($html, 'fill="currentColor"'));
    assert_true(str_contains($html, 'aria-hidden="true"'));
    assert_true(str_contains($html, Icons::catalog()['mdi:heart-outline']['body']));
    assert_false(str_contains($html, '<script'));
    assert_false(str_contains($html, 'api.iconify'));

    $node['props']['label'] = 'Our "favorites"';
    $node['props']['link'] = ['type' => 'url', 'url' => 'https://example.test/favorites', 'new_tab' => true];
    $html = $renderer->render([$node]);
    assert_true(str_contains($html, 'href="https://example.test/favorites"'));
    assert_true(str_contains($html, 'aria-label="Our &quot;favorites&quot;"'));
    assert_true(str_contains($html, 'role="img"'));
    assert_true(str_contains($html, 'rel="noopener noreferrer"'));
    $node['props']['label'] = '';
    assert_true(str_contains($renderer->render([$node]), 'aria-label="Heart Outline"'));
});

test('icon validation restricts identifiers to bundled assets and clamps size', function (): void {
    $nodes = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [
        LayoutSchema::node('icon', ['icon' => '../../evil.svg', 'size' => 9999]),
        LayoutSchema::node('icon', ['icon' => '<svg onload="evil()">', 'size' => -20, 'link' => ['type' => 'url', 'url' => 'javascript:evil()']]),
    ])], 'column')[0]['children'];
    assert_same('mdi:star-outline', $nodes[0]['props']['icon']);
    assert_same(240, $nodes[0]['props']['size']);
    assert_same('mdi:star-outline', $nodes[1]['props']['icon']);
    assert_same(12, $nodes[1]['props']['size']);
    assert_same('', $nodes[1]['props']['link']['url']);
});

test('icon shapes retain independent colors and padding with accessible links', function (): void {
    comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context());
    foreach (['none', 'circle', 'square', 'squircle'] as $shape) {
        $nodes = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [
            LayoutSchema::node('icon', [
                'shape' => $shape, 'shape_color' => 'primary', 'shape_padding' => 24, 'size' => 48,
                'label' => 'Visit home', 'link' => ['type' => 'home'],
            ], ['text_color' => 'inverse', 'align' => 'right']),
        ])], 'column');
        $node = $nodes[0]['children'][0];
        assert_same($shape, $node['props']['shape']);
        $html = $renderer->render([$node]);
        assert_true(str_contains($html, 'c-fg-inverse c-ta-right'));
        assert_true(str_contains($html, 'class="c-icon-link"'));
        assert_true(str_contains($html, 'aria-label="Visit home"'));
        if ($shape === 'none') {
            assert_false(str_contains($html, 'c-icon-shape'));
        } else {
            assert_true(str_contains($html, 'c-icon-shape-' . $shape));
            assert_true(str_contains($html, 'width:96px;padding:24px;background-color:var(--c-primary)'));
            assert_matches('/<a[^>]*><span class="c-icon-shape[^>]*><svg.*<\/svg><\/span><\/a>/', $html);
        }
    }
});

test('icon labels render beside the icon and name its link', function (): void {
    comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context());
    $nodes = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [
        LayoutSchema::node('icon', ['text' => 'Call <us>', 'text_position' => 'bottom', 'text_gap' => 'lg', 'link' => ['type' => 'home']]),
        LayoutSchema::node('icon', ['text' => 'Mail', 'text_position' => 'sideways', 'text_gap' => 'huge']),
        LayoutSchema::node('icon'),
    ])], 'column')[0]['children'];
    assert_same('end', $nodes[1]['props']['text_position']);
    assert_same('sm', $nodes[1]['props']['text_gap']);
    assert_same('', $nodes[2]['props']['text']);
    $html = $renderer->render([$nodes[0]]);
    assert_matches('/<a[^>]*class="c-icon-link"[^>]*><span class="c-icon-item c-icon-item-bottom c-icon-gap-lg"><svg[^>]*aria-hidden="true"[^>]*>.*<\/svg><span class="c-icon-text">Call &lt;us&gt;<\/span><\/span><\/a>/s', $html);
    assert_false(str_contains($html, 'aria-label'));
    assert_true(str_contains($renderer->render([$nodes[1]]), 'c-icon-item c-icon-item-end c-icon-gap-sm'));
    assert_false(str_contains($renderer->render([$nodes[2]]), 'c-icon-item'));
});

test('icon shape validation defaults old blocks and rejects invalid styling', function (): void {
    $nodes = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [
        LayoutSchema::node('icon'),
        LayoutSchema::node('icon', ['shape' => '<script>', 'shape_color' => 'red);color:red', 'shape_padding' => 999]),
        LayoutSchema::node('icon', ['shape' => 'squircle', 'shape_color' => 'custom-brand', 'shape_padding' => -10]),
    ])], 'column')[0]['children'];
    assert_same('none', $nodes[0]['props']['shape']);
    assert_same('none', $nodes[1]['props']['shape']);
    assert_same('surface', $nodes[1]['props']['shape_color']);
    assert_same(120, $nodes[1]['props']['shape_padding']);
    assert_same('custom-brand', $nodes[2]['props']['shape_color']);
    assert_same(0, $nodes[2]['props']['shape_padding']);
});

test('section minimum heights survive saving and render viewport presets including existing values', function (): void {
    comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context());
    foreach (['', 'quarter', 'half', 'three-quarter', 'screen'] as $height) {
        $nodes = LayoutSchema::normalize([LayoutSchema::node('section', ['min_height' => $height, 'vertical_align' => 'center'], [], [
            LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading', ['text' => 'Section content'])])]),
        ])]);
        assert_same($height, $nodes[0]['props']['min_height']);
        $html = $renderer->render($nodes);
        assert_true(str_contains($html, 'c-v-center'));
        if ($height === '') {
            assert_false(str_contains($html, 'c-min-h-'));
        } else {
            assert_true(str_contains($html, 'c-min-h-' . $height));
        }
    }
    $invalid = LayoutSchema::normalize([LayoutSchema::node('section', ['min_height' => 'invalid'])]);
    assert_same('', $invalid[0]['props']['min_height']);
});

test('accordion entries are sanitized, ordered, and isolated into disclosure groups', function (): void {
    $site = comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context($site));
    $node = LayoutSchema::node('accordion', ['single_open' => true, 'items' => [
        ['title' => 'First', 'html' => '<p onclick="evil()">One<script>evil()</script></p>', 'open' => true],
        ['title' => SmartValue::reference('title', 'site'), 'html' => '<p>Two</p>', 'open' => true],
    ]]);
    $saved = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$node])], 'column')[0]['children'][0];
    assert_same('<p>One</p>', $saved['props']['items'][0]['html']);
    assert_same($saved, LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$saved])], 'column')[0]['children'][0]);
    $html = $renderer->render([$saved, $saved]);
    assert_same(4, substr_count($html, '<details '));
    assert_same(2, substr_count($html, ' open>'));
    assert_same(2, substr_count($html, '>Comet Site</summary>'));
    preg_match_all('/<details[^>]* name="([^"]+)"/', $html, $matches);
    assert_same($matches[1][0], $matches[1][1]);
    assert_same($matches[1][2], $matches[1][3]);
    assert_false($matches[1][0] === $matches[1][2]);

    $saved['props']['single_open'] = false;
    $html = $renderer->render([$saved]);
    assert_false(str_contains($html, ' name='));
    assert_same(2, substr_count($html, ' open>'));
    $saved['props']['items'] = [];
    assert_false(str_contains($renderer->render([$saved]), '<details'));
});
