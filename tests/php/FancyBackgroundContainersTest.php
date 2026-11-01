<?php

declare(strict_types=1);

use CometCMS\Extensions\Extension;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\SiteRepository;

test('fancy background containers preserve nested content and render in published pages and builder previews without scripts', function (): void {
    $site = comet_site_test_setup();
    $files = Extension::readDirectory(COMET_TEST_ROOT . '/examples/extensions/comet-fancy-bg-containers');
    $installed = $site->blocks()->installFiles($files);
    assert_same([], $installed['scripts']);
    $spec = LayoutSchema::spec(blocks: $site->blocks())['kinds'];
    $elements = [];
    foreach (['geometry', 'lights', 'space', 'grid'] as $effect) {
        $kind = 'block:cometcms/fancy-' . $effect;
        assert_true($spec[$kind]['slot']);
        $elements[] = LayoutSchema::node($kind, ['intensity' => 'subtle', 'motion' => 'still', 'accent' => 'secondary'], [], [
            LayoutSchema::node('heading', ['text' => 'Inside ' . $effect]),
            LayoutSchema::node('button', ['label' => 'Explore ' . $effect, 'link' => ['type' => 'home']]),
            LayoutSchema::node('block:cometcms/fancy-lights', ['motion' => 'lively', 'intensity' => 'vivid', 'padding' => 'none'], [], [
                LayoutSchema::node('text', ['html' => '<p>Nested content</p>']),
            ]),
        ]);
    }
    comet_block_page($site, $elements);
    $site = new SiteRepository();
    $saved = $site->layout('about', 'detail')[0]['children'][0]['children'][0]['children'];
    assert_same(4, count($saved));
    foreach ($saved as $node) {
        assert_same('still', $node['props']['motion']);
        assert_same('lively', $node['children'][2]['props']['motion']);
        assert_same('<p>Nested content</p>', $node['children'][2]['children'][0]['props']['html']);
    }
    $entry = \CometCMS\Content\ContentRepository::make()->findByIdentifier('about', 'about');
    foreach ([false, true] as $preview) {
        $ctx = comet_site_test_context($site);
        $ctx->preview = $preview;
        $ctx->annotate = $preview;
        $html = (new PageRenderer($ctx))->entryPage($site->types()['about'], $entry);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        assert_same(8, $xpath->query('//div[@class="cometcms-fbg__effects" and @aria-hidden="true"]')->length);
        assert_same(4, $xpath->query('//div[@class="cometcms-fbg__content"]//a[@href="/" and contains(., "Explore")]')->length);
        assert_same(4, substr_count($html, 'Nested content'));
        assert_same(1, substr_count($html, '<style data-comet-blocks>'));
        assert_same(1, substr_count($html, '@keyframes cometcms-fbg-float'));
        assert_true(str_contains($html, '--fbg-a:var(--c-secondary)'));
        assert_false(str_contains($html, 'import mount from'));
        assert_false(str_contains($html, '<slot>'));
        assert_same($preview, str_contains($html, 'data-kind="block:cometcms/fancy-space"'));
    }
});
