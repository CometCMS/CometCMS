<?php

declare(strict_types=1);

use CometCMS\Extensions\Extension;
use CometCMS\Extensions\ExtensionException;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\SiteRepository;

function comet_menu_variations_install(SiteRepository $site): array
{
    return $site->blocks()->installFiles(Extension::readDirectory(COMET_TEST_ROOT . '/examples/extensions/comet-menu-variations'));
}

test('menu props validate references and require navigation HTML in a block container', function (): void {
    $props = ['navigation' => ['type' => 'menu', 'label' => 'Menu', 'default' => 'main']];
    $compiled = Extension::compile(comet_block_single($props, '<div>{{{navigation}}}</div>'));
    assert_same('main', $compiled['blocks']['block:test/thing']['props']['navigation']['default']);
    foreach (['../main', '<script>', str_repeat('a', 81), 123, []] as $invalid) {
        assert_throws(ExtensionException::class, fn() => Extension::compile(comet_block_single([
            'navigation' => array_replace($props['navigation'], ['default' => $invalid]),
        ], '<div>{{{navigation}}}</div>')));
    }
    foreach (['<div>{{navigation}}</div>', '<div title="{{{navigation}}}"></div>', '<p>{{{navigation}}}</p>', '<a href="{{navigation}}">Link</a>'] as $invalid) {
        assert_throws(ExtensionException::class, fn() => Extension::compile(comet_block_single($props, $invalid)));
    }
    // Adding trusted menu HTML must not enable raw HTML for ordinary string props.
    assert_throws(ExtensionException::class, fn() => Extension::compile(comet_block_single([
        'navigation' => ['type' => 'string', 'label' => 'Menu'],
    ], '<div>{{{navigation}}}</div>')));
});

test('menu variations install alongside essentials and expose persistent menu selections', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    $installed = comet_menu_variations_install($site);
    assert_same('CometCMS - Menu Variations', $installed['title']);
    assert_same([], $installed['scripts']);
    assert_same(6, count($installed['blocks']));
    foreach ($installed['blocks'] as $name) {
        $kind = 'block:' . $name;
        $spec = LayoutSchema::spec(blocks: $site->blocks())['kinds'][$kind]['props']['navigation'];
        // Every variation reuses the extension's shared menu prop, with its translations.
        assert_same(['type' => 'menu', 'label' => 'Menu', 'default' => 'main', 'use' => 'navigation', 'l10n' => ['de' => ['label' => 'Menü']]], $spec);
        assert_same('Navigation', $site->blocks()->get($kind)['category']);
        $node = LayoutSchema::node($kind, ['navigation' => 'footer']);
        comet_block_page($site, [$node]);
        $saved = (new SiteRepository())->layout('about', 'detail');
        assert_same('footer', $saved[0]['children'][0]['children'][0]['children'][0]['props']['navigation']);
        assert_same('', LayoutSchema::normalizeProps($kind, ['navigation' => '../main'], $site->blocks())['navigation']);
    }
});

test('menu variations preserve localized trees, links, icons and active states in public and builder views', function (): void {
    $site = comet_site_test_setup(settings: ['locales' => ['en', 'de'], 'default_locale' => 'en']);
    $installed = comet_menu_variations_install($site);
    $site->saveMenus([['name' => 'main', 'label' => 'Main', 'items' => [
        comet_menu_test_item('home', [comet_menu_test_item('child', [comet_menu_test_item('grandchild')])]) + ['icon' => 'mdi:star-outline'],
        comet_menu_test_item('external', [], ['type' => 'url', 'url' => 'https://example.org/?a=1&b=2', 'new_tab' => true]),
        comet_menu_test_item('group', [comet_menu_test_item('group-child')], ['type' => 'none']),
        array_replace(comet_menu_test_item('escaped'), ['label' => '<img src=x onerror=alert(1)>']),
    ]]]);
    $nodes = array_map(static fn(string $name): array => LayoutSchema::node('block:' . $name), $installed['blocks']);
    foreach ([false, true] as $annotate) {
        $context = comet_site_test_context($site);
        $context->locale = 'de';
        $context->annotate = $annotate;
        $html = (new NodeRenderer($context))->render($nodes, ['path' => '/de']);
        assert_same(6, substr_count($html, '<nav '));
        assert_true(str_contains($html, 'DE home'));
        assert_true(str_contains($html, 'DE grandchild'));
        assert_true(str_contains($html, 'href="/de" aria-current="page"'));
        assert_true(str_contains($html, 'href="https://example.org/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer"'));
        assert_true(str_contains($html, 'class="c-menu-icon"'));
        assert_true(str_contains($html, '<summary class="c-menu-label"><span>DE group</span>'));
        assert_true(str_contains($html, '&lt;img src=x onerror=alert(1)&gt;'));
        assert_false(str_contains($html, '<img src=x'));
        preg_match_all('/<details[^>]* name="([^"]+)"/', $html, $names);
        assert_same(12, count(array_unique($names[1]))); // Each navigation and its nested level has its own group.
    }
});

test('menu props resolve current site data and keep empty menus out of the public navigation', function (): void {
    $site = comet_site_test_setup();
    comet_menu_variations_install($site);
    $node = LayoutSchema::node('block:cometcms/menu-pills');
    foreach (['missing', 'empty', ''] as $name) {
        $site->saveMenus([['name' => 'empty', 'label' => 'Empty', 'items' => []]]);
        $empty = array_replace($node, ['props' => array_replace($node['props'], ['navigation' => $name])]);
        assert_false(str_contains((new NodeRenderer(comet_site_test_context($site)))->render([$empty]), '<nav'));
        $context = comet_site_test_context($site);
        $context->annotate = true;
        assert_true(str_contains((new NodeRenderer($context))->render([$empty]), 'class="c-placeholder"'));
    }
    foreach (['First version', 'Updated menu'] as $label) {
        $site->saveMenus([['name' => 'main', 'items' => [array_replace(comet_menu_test_item('home'), ['label' => $label])]]]);
        assert_true(str_contains((new NodeRenderer(comet_site_test_context($site)))->render([$node]), $label));
    }
});
