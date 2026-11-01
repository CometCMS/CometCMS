<?php

declare(strict_types=1);

use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\SiteContext;
use CometCMS\Site\SmartValue;

test('shared region buttons stay visible without destinations across pages and builders', function (): void {
    $site = comet_site_test_setup();
    foreach (['header', 'footer'] as $region) {
        $buttons = [];
        foreach (['none', 'current', 'home'] as $destination) {
            $button = LayoutSchema::node('button', [
                'label' => $region . '-' . $destination,
                'link' => ['type' => $destination],
            ]);
            $button['id'] = $region . '-' . $destination;
            $buttons[] = $button;
        }
        $site->saveRegion($region, [LayoutSchema::node('section', [], [], [
            LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], $buttons)]),
        ])]);
    }

    foreach ([false, true] as $editing) {
        $ctx = comet_site_test_context($site);
        $ctx->preview = $editing;
        $ctx->annotate = $editing;
        $renderer = new PageRenderer($ctx);
        $type = $ctx->type('blog');
        $entry = $ctx->findEntry('blog', 'hello');
        $pages = $editing ? [
            'index' => $renderer->layoutPreview($type, 'index', null),
            'detail' => $renderer->layoutPreview($type, 'detail', $entry),
            'page' => $renderer->pagePreview($type, $entry, 'body'),
            'header' => $renderer->regionPreview('header'),
            'footer' => $renderer->regionPreview('footer'),
        ] : [
            'index' => $renderer->indexPage($type),
            'detail' => $renderer->entryPage($type, $entry),
        ];
        foreach ($pages as $page => $html) {
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            foreach (['header', 'footer'] as $region) {
                foreach (['none', 'current', 'home'] as $destination) {
                    $label = $region . '-' . $destination;
                    $buttons = $xpath->query('//' . $region . '//*[contains(concat(" ", normalize-space(@class), " "), " c-button ")][span[@class="c-button-label" and text()="' . $label . '"]]');
                    assert_same(1, $buttons->length, $label . ' remains visible on ' . $page);
                    $button = $buttons->item(0);
                    $linked = $destination === 'home' || ($destination === 'current' && in_array($page, ['detail', 'page'], true));
                    assert_same($linked ? 'a' : 'span', $button->tagName);
                    assert_same($linked, $button->hasAttribute('href'));
                }
                assert_same($editing && $page === $region, str_contains($html, 'data-node="' . $region . '-none"'));
            }
        }
    }
});

test('fields and gallery images honor page URL anchor and dynamic links with new tab settings', function (): void {
    $site = comet_site_test_setup();
    foreach (['first.svg', 'second.svg'] as $file) file_put_contents(comet_test_workspace_path() . '/media/' . $file, '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="40"></svg>');
    $ctx = SiteContext::make($site, '/cms', 'https://example.test');
    $state = ['entry' => $ctx->findEntry('blog', 'hello'), 'type' => $ctx->type('blog')];
    $state['type']['fields']['photo'] = ['type' => 'media'];
    $state['entry']['photo'] = ['first.svg'];
    $state['type']['fields']['destination'] = ['type' => 'text'];
    $state['entry']['destination'] = '/chosen-page';
    $renderer = new NodeRenderer($ctx);
    $destinations = [
        [['type' => 'none'], null],
        [['type' => 'current'], '/cms/blog/hello'],
        [['type' => 'home'], '/cms/'],
        [['type' => 'index', 'collection' => 'blog'], '/cms/blog'],
        [['type' => 'entry', 'collection' => 'blog', 'entry' => $state['entry']['uid']], '/cms/blog/hello'],
        [['type' => 'anchor', 'anchor' => 'contact'], '#contact'],
        [['type' => 'url', 'url' => 'https://example.org/other'], 'https://example.org/other'],
        [['type' => 'url', 'url' => '/other-page'], '/cms/other-page'],
        [['type' => 'dynamic', 'value' => SmartValue::reference('destination')], '/cms/chosen-page'],
        [['type' => 'dynamic', 'value' => SmartValue::reference('url')], '/cms/blog/hello'],
    ];
    foreach ($destinations as [$destination, $href]) {
        $destination['new_tab'] = $href !== null && $destination['type'] !== 'anchor';
        foreach ([
            LayoutSchema::node('field', ['source' => SmartValue::reference('title'), 'link' => $destination]),
            LayoutSchema::node('field', ['source' => SmartValue::reference('title'), 'tag' => 'div', 'link' => $destination]),
            LayoutSchema::node('field', ['source' => SmartValue::reference('photo'), 'link' => $destination]),
            LayoutSchema::node('gallery', ['media' => ['first.svg', 'second.svg'], 'link' => $destination]),
        ] as $node) {
            $html = $renderer->render([$node], $state);
            if ($href === null) {
                assert_false(str_contains($html, '<a '));
                continue;
            }
            assert_same($node['kind'] === 'gallery' ? 2 : 1, substr_count($html, 'href="' . $href . '"'), $node['kind'] . ' uses ' . $destination['type']);
            assert_same($destination['new_tab'], str_contains($html, 'target="_blank" rel="noopener noreferrer"'));
        }
    }
});

test('dynamic field and gallery destinations reject unsafe missing and multiple values', function (): void {
    comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="40"></svg>');
    $ctx = comet_site_test_context();
    $state = ['type' => $ctx->type('blog'), 'entry' => $ctx->findEntry('blog', 'hello')];
    $state['type']['fields']['destination'] = ['type' => 'text'];
    $renderer = new NodeRenderer($ctx);
    foreach ([null, '', 'javascript:alert(1)', ['https://example.org/one', 'https://example.org/two']] as $value) {
        $state['entry']['destination'] = $value;
        foreach (['field', 'gallery'] as $kind) {
            $props = $kind === 'field' ? ['source' => SmartValue::reference('title')] : ['media' => ['photo.svg']];
            $props['link'] = ['type' => 'dynamic', 'value' => SmartValue::reference('destination')];
            assert_false(str_contains($renderer->render([LayoutSchema::node($kind, $props)], $state), '<a '));
        }
    }
    $unsafe = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [LayoutSchema::node('gallery', ['link' => ['type' => 'url', 'url' => 'javascript:alert(1)']])])], 'column');
    assert_same('', $unsafe[0]['children'][0]['props']['link']['url']);
});

test('dynamic links in repeater templates resolve separately for each item', function (): void {
    comet_site_test_setup();
    $ctx = comet_site_test_context();
    $state = [
        'type' => ['fields' => ['steps' => ['type' => 'repeater', 'subfields' => [['key' => 'label', 'type' => 'text'], ['key' => 'destination', 'type' => 'text']]]]],
        'entry' => ['steps' => [['label' => 'First', 'destination' => '/first'], ['label' => 'Second', 'destination' => '/second']]],
    ];
    $node = LayoutSchema::node('field', ['source' => SmartValue::reference('steps')], [], [
        LayoutSchema::node('field', ['source' => SmartValue::reference('label', 'item'), 'link' => ['type' => 'dynamic', 'value' => SmartValue::reference('destination', 'item')]]),
    ]);
    $html = (new NodeRenderer($ctx))->render([$node], $state);
    assert_true(str_contains($html, '<a href="/first">First</a>'));
    assert_true(str_contains($html, '<a href="/second">Second</a>'));
});
