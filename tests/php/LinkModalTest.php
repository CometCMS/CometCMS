<?php

declare(strict_types=1);

use CometCMS\Site\LayoutSchema;
use CometCMS\Site\LinkModal;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\SiteContext;
use CometCMS\Site\SiteScripts;
use CometCMS\Site\SmartValue;

test('internal page modal links survive saving and render across all link elements', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_files(), allowScripts: true);
    file_put_contents(comet_test_workspace_path() . '/media/photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="40"></svg>');
    $ctx = SiteContext::make($site, '/cms', 'https://example.test');
    $state = ['entry' => $ctx->findEntry('blog', 'hello'), 'type' => $ctx->type('blog')];
    $destinations = [
        ['type' => 'current'],
        ['type' => 'home', 'anchor' => 'contact'],
        ['type' => 'index', 'collection' => 'blog'],
        ['type' => 'entry', 'collection' => 'blog', 'entry' => $state['entry']['uid'], 'anchor' => 'contact'],
    ];
    $nodes = [];
    foreach ($destinations as $destination) {
        foreach (['button', 'heading', 'icon', 'image', 'field', 'gallery', 'block:cometcms/callout', 'block:cometcms/card'] as $kind) {
            $props = ['link' => $destination + ['open_modal' => true, 'new_tab' => true]];
            if ($kind === 'image') $props['media'] = 'photo.svg';
            if ($kind === 'gallery') $props['media'] = ['photo.svg'];
            if ($kind === 'field') $props['source'] = SmartValue::reference('title');
            $nodes[] = LayoutSchema::node($kind, $props);
        }
    }
    $site->saveLayout('blog', 'detail', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], $nodes)])])]);
    $saved = $site->layout('blog', 'detail');
    foreach ($saved[0]['children'][0]['children'][0]['children'] as $node) {
        assert_true($node['props']['link']['open_modal']);
        assert_false($node['props']['link']['new_tab']);
        $html = (new NodeRenderer($ctx))->render([$node], $state);
        assert_same(1, substr_count($html, ' data-link-modal '), $node['kind']);
        assert_true(str_contains($html, 'href="/cms/'));
        assert_true(str_contains($html, 'aria-haspopup="dialog"'));
        assert_false(str_contains($html, 'target="_blank"'));
    }
});

test('modal flags never change custom URL anchor or dynamic link behavior', function (): void {
    comet_site_test_setup();
    $ctx = comet_site_test_context();
    $state = ['entry' => $ctx->findEntry('blog', 'hello'), 'type' => $ctx->type('blog')];
    foreach ([
        ['type' => 'url', 'url' => 'https://example.org/other', 'new_tab' => true],
        ['type' => 'url', 'url' => '//example.org/other', 'new_tab' => false],
        ['type' => 'url', 'url' => '/custom', 'new_tab' => false],
        ['type' => 'anchor', 'anchor' => 'contact', 'new_tab' => false],
        ['type' => 'dynamic', 'value' => SmartValue::reference('url'), 'new_tab' => true],
        ['type' => 'none'],
    ] as $link) {
        $flagged = $link + ['open_modal' => true];
        assert_false(isset(LayoutSchema::normalizeLink($flagged)['open_modal']));
        // Also defend against unnormalized or older saved props.
        $renderer = new NodeRenderer($ctx);
        assert_same(
            $renderer->render([LayoutSchema::node('button', ['label' => 'Destination', 'link' => $link])], $state),
            $renderer->render([LayoutSchema::node('button', ['label' => 'Destination', 'link' => $flagged])], $state)
        );
    }
    $unavailable = LayoutSchema::node('button', ['link' => ['type' => 'entry', 'collection' => 'blog', 'entry' => 'missing', 'open_modal' => true]]);
    assert_false(str_contains((new NodeRenderer($ctx))->render([$unavailable], $state), 'data-link-modal'));
});

test('page modal scripts load once only when needed with exact CSP hashes and script free builder previews', function (): void {
    $site = comet_site_test_setup();
    $button = LayoutSchema::node('button', ['link' => ['type' => 'index', 'collection' => 'blog', 'open_modal' => true]]);
    $tree = [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$button, $button])])])];
    $ctx = comet_site_test_context($site);
    $type = $ctx->type('blog');
    $entry = $ctx->findEntry('blog', 'hello');
    assert_false(str_contains((new PageRenderer($ctx))->entryPage($type, $entry), 'initLinkModal'));
    $site->saveLayout('blog', 'detail', $tree);
    $ctx = comet_site_test_context($site);
    $renderer = new PageRenderer($ctx);
    $html = $renderer->entryPage($type, $entry);
    assert_same(1, substr_count($html, '<script type="module">'));
    assert_true(str_contains($html, '>' . LinkModal::script() . '</script>'));
    assert_same('script-src ' . LinkModal::cspSource(), SiteScripts::cspDirective($html));
    $ctx->preview = true;
    $ctx->annotate = true;
    $preview = $renderer->layoutPreview($type, 'detail', $entry);
    assert_true(str_contains($preview, 'data-link-modal'));
    assert_false(str_contains($preview, '<script'));
    $ctx->annotate = false;
    $ctx->browsePreview = true;
    $preview = $renderer->entryPage($type, $entry);
    assert_true(str_contains($preview, 'initLinkModal(document)'));
    assert_true(str_contains($preview, LinkModal::cspSource()));
});

test('embedded pages show only their main content and keep forms embedded', function (): void {
    $site = comet_site_test_setup();
    $ctx = SiteContext::make($site, '/cms', 'https://example.test');
    $full = (new \CometCMS\Site\SiteService($ctx))->respond('/blog/hello', [])['body'];
    assert_true(str_contains($full, '<header'));
    $ctx = SiteContext::make($site, '/cms', 'https://example.test');
    $html = (new \CometCMS\Site\SiteService($ctx))->respond('/blog/hello', ['embed' => '1'])['body'];
    assert_true(str_contains($html, '<main id="main"'));
    assert_true(str_contains($html, 'c-embed'));
    assert_true(str_contains($html, 'noindex'));
    foreach (['<header', '<footer', 'c-skip-link', 'data-link-modal-dialog'] as $missing) assert_false(str_contains($html, $missing), $missing);
    assert_same('/cms/blog/hello?embed=1', $ctx->embedUrl('/cms/blog/hello'));
    assert_same('/cms/admin/site-preview?path=%2F&embed=1', $ctx->embedUrl('/cms/admin/site-preview?path=%2F'));
});
