<?php

declare(strict_types=1);

use CometCMS\Site\FontRegistry;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\Theme;
use CometCMS\Site\ThemeToggle;

test('theme toggles render a pressed state, both icons and an accessible name', function (): void {
    $site = comet_site_test_setup();
    $ctx = comet_site_test_context($site);
    $renderer = new NodeRenderer($ctx);

    $html = $renderer->render([LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [LayoutSchema::node('theme_toggle')])], 'column')[0]['children'][0]], []);
    assert_true(str_contains($html, 'data-theme-toggle'));
    assert_true(str_contains($html, 'aria-pressed="false"'));
    assert_true(str_contains($html, 'aria-label="Dark mode"'));
    assert_true(str_contains($html, 'c-theme-icon-light'));
    assert_true(str_contains($html, 'c-theme-icon-dark'));

    $labelled = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [
        LayoutSchema::node('theme_toggle', ['show_label' => true, 'label' => 'Night <b>', 'variant' => 'bogus', 'light_icon' => 'mdi:not-an-icon']),
    ])], 'column')[0]['children'][0];
    assert_same('plain', $labelled['props']['variant']);
    assert_same('mdi:white-balance-sunny', $labelled['props']['light_icon']);
    $html = $renderer->render([$labelled], []);
    assert_true(str_contains($html, '<span>Night &lt;b&gt;</span>'));
    assert_false(str_contains($html, 'aria-label='), 'Visible text names the button.');
});

test('theme stylesheets let a saved choice override the mode', function (): void {
    $site = comet_site_test_setup();
    $ctx = comet_site_test_context($site);
    $css = Theme::stylesheet($ctx->theme(), new FontRegistry($site->workspace()), '');
    assert_true(str_contains($css, ':root[data-theme="light"]{color-scheme:light;'));
    assert_true(str_contains($css, ':root[data-theme="dark"]{color-scheme:dark;'));
});

test('pages with a theme toggle load its hashed script in the head, previews stay script free', function (): void {
    $site = comet_site_test_setup();
    $site->saveLayout('blog', 'detail', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [
        LayoutSchema::node('theme_toggle'), LayoutSchema::node('theme_toggle'),
    ])])])]);
    $ctx = comet_site_test_context($site);
    $renderer = new PageRenderer($ctx);
    $html = $renderer->entryPage($ctx->type('blog'), $ctx->findEntry('blog', 'hello'));
    assert_same(1, substr_count($html, '<script>' . ThemeToggle::script() . '</script>'));
    assert_true(strpos($html, ThemeToggle::script()) < strpos($html, '</head>'));
    assert_same("'sha256-" . base64_encode(hash('sha256', ThemeToggle::script(), true)) . "'", ThemeToggle::cspSource());

    $ctx->preview = true;
    $preview = $renderer->layoutPreview($ctx->type('blog'), 'detail', $ctx->findEntry('blog', 'hello'));
    assert_false(str_contains($preview, '<script'));
    assert_true(str_contains($preview, 'data-theme-toggle'));
});
