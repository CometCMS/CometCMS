<?php

declare(strict_types=1);

use CometCMS\Site\SiteRepository;
use CometCMS\Site\Theme;

test('default structure padding validates, persists and follows the spacing scale', function (): void {
    $site = comet_site_test_setup();
    foreach ([Theme::defaults(), Theme::normalize([]), Theme::normalize(['section_padding' => 'bad', 'grid_padding' => [], 'column_padding' => '24px'])] as $theme) {
        assert_same('md', $theme['section_padding']);
        assert_same('md', $theme['grid_padding']);
        assert_same('md', $theme['column_padding']);
    }

    foreach (['none', ...Theme::SPACE_TOKENS] as $token) {
        $saved = $site->saveTheme(['section_padding' => $token, 'grid_padding' => $token, 'column_padding' => $token]);
        assert_same($token, $saved['section_padding']);
        assert_same($token, (new SiteRepository())->theme()['grid_padding']);
        assert_same($token, (new SiteRepository())->theme()['column_padding']);
        $padding = $token === 'none' ? '0px' : 'var(--space-' . $token . ')';
        assert_true(str_contains(Theme::stylesheet($saved), '--column-padding:' . $padding . ';'));
    }
    $saved = $site->saveTheme(['section_padding' => 'lg', 'grid_padding' => 'none', 'column_padding' => 'sm', 'space' => ['lg' => 48]]);
    $preset = $site->createThemePreset('Padding', $saved);
    assert_same('lg', $preset['theme']['section_padding']);
    assert_same('none', $preset['theme']['grid_padding']);
    assert_same('sm', $preset['theme']['column_padding']);
    $css = Theme::stylesheet($saved);
    assert_true(str_contains($css, '--section-padding:var(--space-lg);--grid-padding:0px;'));
    assert_true(str_contains($css, '--space-lg:3rem;'));
});

test('default structure padding has tablet and phone values', function (): void {
    $site = comet_site_test_setup();
    $theme = Theme::normalize(['section_padding_mobile' => 'bad', 'grid_padding_tablet' => 'huge']);
    assert_same('', $theme['section_padding_mobile']);
    assert_same('sm', $theme['grid_padding_tablet']);

    $saved = $site->saveTheme(['section_padding' => 'lg', 'section_padding_tablet' => 'md', 'grid_padding_mobile' => 'none', 'column_padding_mobile' => '']);
    assert_same('md', (new SiteRepository())->theme()['section_padding_tablet']);
    assert_same('', $saved['column_padding_mobile']);
    $css = Theme::stylesheet($saved);
    assert_true(str_contains($css, '@media (max-width: 1023px){:root{--section-padding:var(--space-md);--grid-padding:var(--space-sm);--column-padding:var(--space-sm);}}'));
    assert_true(str_contains($css, '@media (max-width: 767px){:root{--grid-padding:0px;}}'));
});

test('components get readable ids that stay when they are renamed', function (): void {
    $site = comet_site_test_setup();
    $section = [['kind' => 'section', 'children' => [['kind' => 'row']]]];

    assert_same('hero-banner', $site->createComponent('Hero Banner', $section)['id']);
    assert_same('hero-banner-2', $site->createComponent('Hero banner!', $section)['id']);
    assert_same('ueber-uns-strasse', $site->createComponent('Über uns · Straße', $section)['id']);
    assert_same('component', $site->createComponent('★', $section)['id']);
    assert_same('c0123456789', $site->createComponent('c0123456789', $section)['id'], 'All name slugs are available.');
    assert_file_exists_at($site->workspace()->path('site') . '/theme/components/hero-banner.json');

    $renamed = $site->renameComponent('hero-banner', 'Startseite');
    assert_same('hero-banner', $renamed['id']);
    assert_same('Startseite', $site->component('hero-banner')['name']);
});

test('theme files store no save time and stay unchanged when saved unchanged', function (): void {
    $site = comet_site_test_setup();
    $theme = $site->workspace()->path('site') . '/theme';
    $section = [['kind' => 'section', 'children' => [['kind' => 'row']]]];

    $saved = $site->saveTheme(['colors' => ['primary' => '#123456']]);
    $component = $site->createComponent('Promo', $section);
    $site->saveLayout('blog', 'detail', [comet_component_test_instance('p1', $component['id'])]);
    $site->saveRegion('footer', [comet_component_test_instance('p2', $component['id'])]);
    $files = ['theme.json', 'components/promo.json', 'content/blog/detail/default.json', 'regions/footer.json'];
    $before = [];
    foreach ($files as $file) {
        $before[$file] = file_get_contents($theme . '/' . $file);
        assert_false(str_contains($before[$file], 'updated_at'), $file . ' has no save time.');
    }
    assert_matches('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $saved['updated_at']);
    assert_same($saved['updated_at'], $site->theme()['updated_at']);
    assert_same($site->component('promo')['updated_at'], $component['updated_at']);

    $site->saveTheme($site->theme());
    $site->saveComponent('promo', $site->component('promo')['nodes']);
    $site->saveLayout('blog', 'detail', $site->layout('blog', 'detail'));
    $site->saveRegion('footer', $site->region('footer'));
    foreach ($files as $file) {
        assert_same($before[$file], file_get_contents($theme . '/' . $file), $file . ' is unchanged.');
    }
});


test('theme presets keep a design by name without custom CSS', function (): void {
    $site = comet_site_test_setup();

    $preset = $site->createThemePreset('Mein Design', ['colors' => ['primary' => '#123456'], 'radius' => 'lg', 'custom_css' => 'body { color: red; }']);
    assert_same('mein-design', $preset['id']);
    assert_same('#123456', $preset['theme']['colors']['primary']);
    assert_same('lg', $preset['theme']['radius']);
    assert_false(array_key_exists('custom_css', $preset['theme']), 'Custom CSS stays out of presets.');
    assert_file_exists_at($site->workspace()->path('site') . '/theme/presets/mein-design.json');

    assert_same('mein-design-2', $site->createThemePreset('Mein Design', [])['id']);
    assert_same(['Mein Design', 'Mein Design'], array_column($site->themePresets(), 'name'));
    assert_throws(\CometCMS\Core\ValidationException::class, fn() => $site->createThemePreset('  ', []));
    assert_throws(\CometCMS\Core\ValidationException::class, fn() => $site->createThemePreset('No theme', 'red'));
    assert_same(null, $site->themePreset('../theme'));

    $site->deleteThemePreset('mein-design');
    assert_same(null, $site->themePreset('mein-design'));
    assert_same(['mein-design-2'], array_column($site->themePresets(), 'id'));
});
