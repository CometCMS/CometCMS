<?php

declare(strict_types=1);

use CometCMS\Extensions\Extension;
use CometCMS\Extensions\ExtensionException;
use CometCMS\Extensions\ExtensionRegistry;
use CometCMS\Extensions\ExtensionReview;
use CometCMS\Site\SiteRepository;
use CometCMS\Storage\JsonStore;

function comet_theme_pack_files(): array
{
    return Extension::readDirectory(COMET_TEST_ROOT . '/examples/extensions/comet-theme-pack');
}

test('theme pack installs four independent designs without applying one and preserves them on keep', function (): void {
    $registry = ExtensionRegistry::active();
    $site = new SiteRepository();
    $before = $site->theme();
    $files = comet_theme_pack_files();
    $compiled = Extension::compile($files);
    assert_same('Comet ThemePack', $compiled['title']);
    assert_same(7, count($compiled['seeds']));
    $registry->installFiles($files, seeds: []);
    assert_same($before, $site->theme());
    $presets = $site->themePresets();
    assert_same(['Industrial', 'Material-inspired', 'Neo-Brutalism', 'Orbital'], array_column($presets, 'name'));
    assert_same(3, count($site->components()));
    assert_same(4, count(array_unique(array_column($presets, 'id'))));
    foreach ($presets as $preset) {
        assert_false(array_key_exists('custom_css', $preset['theme']));
        assert_same(8, count($preset['theme']['colors']));
        assert_same(8, count($preset['theme']['dark_colors']));
    }
    assert_same(900, $site->themePreset('neo-brutalism')['theme']['heading_weight']);
    assert_same('monospace', $site->themePreset('industrial')['theme']['fonts']['heading']);
    assert_same(24, $site->themePreset('material-inspired')['theme']['radii']['lg']);
    assert_same('dark', $site->themePreset('orbital')['theme']['mode']);
    $applied = $site->saveTheme($site->themePreset('orbital')['theme']);
    $registry->setEnabled('cometcms/neo-brutalism', false);
    assert_same([], $site->themePresets());
    assert_same($applied, $site->theme());
    $registry->setEnabled('cometcms/neo-brutalism', true);
    assert_same(4, count($site->themePresets()));
    $registry->delete('cometcms/neo-brutalism');
    assert_same(4, count($site->themePresets()));
    assert_same(3, count($site->components()));
    assert_same($applied, $site->theme());
});

test('theme pack upgrades legacy neo brutalism and tracks renamed presets separately', function (): void {
    $registry = ExtensionRegistry::active();
    $files = comet_theme_pack_files();
    $legacy = array_filter($files, static fn(string $path): bool => !str_starts_with($path, 'presets/'), ARRAY_FILTER_USE_KEY);
    $manifest = json_decode($legacy['comet-extension.json'], true);
    $manifest['title'] = 'Comet Neo-Brutalism';
    $manifest['version'] = '1.0.0';
    $legacy['comet-extension.json'] = json_encode($manifest);
    $registry->installFiles($legacy, seeds: []);
    // Reproduce the ledger format from before per-preset source tracking.
    $store = new JsonStore($registry->workspace->path('site'));
    $ledger = $store->read('extensions-seeded');
    foreach ($ledger['cometcms/neo-brutalism'] as &$entry) unset($entry['source']);
    unset($entry);
    $store->write($ledger, 'extensions-seeded');
    $themeStore = new JsonStore($registry->workspace->path('site') . '/theme');
    $neo = $themeStore->read('presets', 'neo-brutalism');
    $neo['name'] = 'My Neo';
    $themeStore->write($neo, 'presets', 'neo-brutalism');
    try {
        $registry->installFiles($files);
        throw new RuntimeException('Expected a theme pack review.');
    } catch (ExtensionReview $e) {
        $plan = array_column($e->plan, null, 'id');
        assert_same(7, count($plan));
        assert_same('skip', $plan['neo-brutalism']['default']);
        assert_same('create', $plan['industrial']['default']);
        assert_same('create', $plan['material-inspired']['default']);
        assert_same('create', $plan['orbital']['default']);
    }
    $registry->installFiles($files, seeds: []);
    $orbital = $themeStore->read('presets', 'orbital');
    $orbital['name'] = 'My Space';
    $themeStore->write($orbital, 'presets', 'orbital');
    try {
        $registry->installFiles($files);
        throw new RuntimeException('Expected another theme pack review.');
    } catch (ExtensionReview $e) {
        $plan = array_column($e->plan, null, 'id');
        assert_same('skip', $plan['orbital']['default']);
        assert_same('overwrite', $plan['industrial']['default']);
        assert_same('overwrite', $plan['material-inspired']['default']);
    }
    $registry->installFiles($files, seeds: []);
    $site = new SiteRepository();
    assert_same(4, count($site->themePresets()));
    assert_same('My Neo', $site->themePreset('neo-brutalism')['name']);
    assert_same('My Space', $site->themePreset('orbital')['name']);
    $registry->installFiles($files, seeds: ['presets/orbital' => 'overwrite']);
    assert_same('Orbital', $site->themePreset('orbital')['name']);
    assert_same('My Neo', $site->themePreset('neo-brutalism')['name']);
});

test('theme pack rejects duplicate preset identities and reports the preset source on invalid data', function (): void {
    $files = comet_theme_pack_files();
    assert_throws(ExtensionException::class, fn() => Extension::compile($files + ['presets/duplicate.json' => $files['theme.json']]));
    assert_throws(ExtensionException::class, fn() => Extension::compile($files + ['presets/Bad Name.json' => $files['theme.json']]));
    $files['presets/industrial.json'] = '{"name":"Industrial","theme":"invalid"}';
    try {
        ExtensionRegistry::active()->installFiles($files, seeds: []);
        throw new RuntimeException('Expected invalid theme data to fail.');
    } catch (ExtensionException $e) {
        assert_same('presets/industrial.json', $e->errors()[0]['file']);
    }
    assert_null(ExtensionRegistry::active()->extension('cometcms/neo-brutalism'));
    assert_same([], (new SiteRepository())->themePresets());
});

test('theme pack checks the combined preset limit after skip choices before writing', function (): void {
    $site = new SiteRepository();
    for ($i = 0; $i < SiteRepository::MAX_THEME_PRESETS - 1; $i++) $site->createThemePreset('Saved ' . $i, []);
    $files = comet_theme_pack_files();
    $registry = ExtensionRegistry::active();
    assert_throws(ExtensionException::class, fn() => $registry->installFiles($files, seeds: []));
    assert_null($registry->extension('cometcms/neo-brutalism'));
    assert_same([], $site->components());
    $registry->installFiles($files, seeds: ['presets/industrial' => 'skip', 'presets/material-inspired' => 'skip', 'presets/orbital' => 'skip']);
    assert_same(SiteRepository::MAX_THEME_PRESETS, count($site->themePresets()));
    assert_true($site->themePreset('neo-brutalism') !== null);
});
