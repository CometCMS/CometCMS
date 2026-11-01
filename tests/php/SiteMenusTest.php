<?php

declare(strict_types=1);

use CometCMS\Content\ContentRepository;
use CometCMS\Core\ValidationException;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\SiteContext;
use CometCMS\Site\SiteRepository;

function comet_menu_test_item(string $id, array $children = [], array $link = ['type' => 'home']): array
{
    return ['id' => $id, 'label' => ['type' => 'localized', 'values' => ['en' => $id, 'de' => 'DE ' . $id]], 'link' => $link, 'children' => $children];
}

function comet_menu_test_chain(int $levels): array
{
    return comet_menu_test_item('level' . $levels, $levels > 1 ? [comet_menu_test_chain($levels - 1)] : []);
}

function comet_menu_test_render(SiteContext $context, array $state = []): string
{
    $menu = LayoutSchema::node('menu', ['menu' => 'main']);
    $column = LayoutSchema::node('column', [], [], [$menu]);
    $row = LayoutSchema::node('row', [], [], [$column]);
    $layout = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [$row])]);

    return (new NodeRenderer($context))->render($layout, $state);
}

test('menu button mode persists and overrides mobile collapsing without changing existing defaults', function (): void {
    $site = comet_site_test_setup();
    $site->saveMenus([['name' => 'main', 'label' => 'Main', 'items' => [comet_menu_test_item('home')]]]);

    foreach ([
        [[], false, true],
        [['collapse_mobile' => false], false, false],
        [['always_button' => true], true, true],
        [['always_button' => true, 'collapse_mobile' => false, 'orientation' => 'vertical'], true, false],
    ] as [$props, $alwaysButton, $collapseMobile]) {
        $menu = LayoutSchema::node('menu', $props);
        $column = LayoutSchema::node('column', [], [], [$menu]);
        $row = LayoutSchema::node('row', [], [], [$column]);
        $saved = $site->saveRegion('header', [LayoutSchema::node('section', [], [], [$row])]);
        $reloaded = (new SiteRepository())->region('header');
        assert_same($saved, $reloaded);
        $savedProps = $reloaded[0]['children'][0]['children'][0]['children'][0]['props'];
        assert_same($alwaysButton, $savedProps['always_button']);
        assert_same($collapseMobile, $savedProps['collapse_mobile']);

        foreach ([false, true] as $annotate) {
            $context = comet_site_test_context($site);
            $context->annotate = $annotate;
            $html = (new NodeRenderer($context))->render($reloaded);
            assert_same($alwaysButton, str_contains($html, ' c-menu-always-button'));
            assert_same($alwaysButton || $collapseMobile, str_contains($html, ' c-menu-collapsible'));
            assert_same($alwaysButton || $collapseMobile, str_contains($html, 'class="c-menu-toggle"'));
            assert_same($alwaysButton || $collapseMobile, str_contains($html, 'class="c-menu-button"'));
            assert_true(str_contains($html, 'class="c-menu-list"'));
        }
    }
});

test('menu panels render a close control only when a button opens them', function (): void {
    $site = comet_site_test_setup();
    $site->saveMenus([['name' => 'main', 'label' => 'Main', 'items' => [comet_menu_test_item('home')]]]);

    foreach ([
        [['panel' => 'fullscreen'], ' c-menu-fullscreen', 1, 0],
        [['panel' => 'drawer', 'always_button' => true], ' c-menu-drawer', 1, 1],
        [['panel' => 'drawer', 'collapse_mobile' => false], '', 0, 0],
        [['panel' => 'sideways'], '', 0, 0],
        [[], '', 0, 0],
    ] as [$props, $class, $closes, $backdrops]) {
        $menu = LayoutSchema::node('menu', $props);
        $layout = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$menu])])])]);
        $html = (new NodeRenderer(comet_site_test_context($site)))->render($layout);
        foreach (['fullscreen', 'drawer'] as $panel) {
            assert_same($class === ' c-menu-' . $panel, str_contains($html, ' c-menu-' . $panel));
        }
        assert_same($closes, substr_count($html, 'class="c-menu-close"'));
        assert_same($backdrops, substr_count($html, 'class="c-menu-backdrop"'));
        // Every close control toggles this menu's own switch.
        preg_match('/id="(menu-[^"]+)"/', $html, $toggle);
        assert_same($closes + $backdrops + ($toggle ? 1 : 0), $toggle ? substr_count($html, 'for="' . $toggle[1] . '"') : 0);
    }
});

test('menu element text colors tint bar links while dropdowns and panels keep their own palette', function (): void {
    $site = comet_site_test_setup();
    $site->saveMenus([['name' => 'main', 'label' => 'Main', 'items' => [comet_menu_test_item('home')]]]);

    foreach (['primary' => 'primary', 'inverse' => 'background', 'custom-brand' => 'custom-brand'] as $choice => $token) {
        foreach (['dropdown', 'fullscreen', 'drawer'] as $panel) {
            $menu = LayoutSchema::node('menu', ['menu' => 'main', 'panel' => $panel, 'always_button' => true, 'panel_text_color' => 'accent'], ['text_color' => $choice]);
            $layout = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$menu])], 'column');
            foreach ([false, true] as $annotate) {
                $context = comet_site_test_context($site);
                $context->annotate = $annotate;
                $html = (new NodeRenderer($context))->render($layout);
                assert_true(str_contains($html, '--c-menu-color:var(--c-' . $token . ')'));
                assert_true(str_contains($html, '--c-menu-text:var(--c-accent)'));
            }
        }
    }
});

test('menu panel position and palette persist and render in public and builder views', function (): void {
    $site = comet_site_test_setup();
    $site->saveMenus([['name' => 'main', 'label' => 'Main', 'items' => [comet_menu_test_item('home')]]]);

    foreach ([
        ['drawer', 'left', 'custom-brand', 'inverse', 'background'],
        ['drawer', 'right', 'surface', 'text', 'text'],
        ['fullscreen', 'left', 'primary', 'custom-brand', 'custom-brand'],
    ] as [$panel, $side, $background, $text, $textToken]) {
        $menu = LayoutSchema::node('menu', [
            'panel' => $panel, 'panel_side' => $side, 'always_button' => true,
            'panel_background' => $background, 'panel_text_color' => $text,
        ]);
        $saved = $site->saveRegion('header', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$menu])])])]);
        $reloaded = (new SiteRepository())->region('header');
        assert_same($saved, $reloaded);
        $props = $reloaded[0]['children'][0]['children'][0]['children'][0]['props'];
        assert_same($side, $props['panel_side']);
        assert_same($background, $props['panel_background']);
        assert_same($text, $props['panel_text_color']);

        foreach ([false, true] as $annotate) {
            $context = comet_site_test_context($site);
            $context->annotate = $annotate;
            $html = (new NodeRenderer($context))->render($reloaded);
            assert_same($panel === 'drawer', str_contains($html, ' c-menu-drawer-' . $side));
            assert_true(str_contains($html, '--c-menu-background:var(--c-' . $background . ')'));
            assert_true(str_contains($html, '--c-menu-text:var(--c-' . $textToken . ')'));
        }
    }
});

test('menu panel options default safely and dress dropdowns as well as panels', function (): void {
    $site = comet_site_test_setup();
    $site->saveMenus([['name' => 'main', 'label' => 'Main', 'items' => [comet_menu_test_item('home')]]]);
    foreach ([[], ['panel_side' => 'top', 'panel_background' => 'custom-evil; color:red', 'panel_text_color' => ['primary']]] as $props) {
        $menu = LayoutSchema::node('menu', $props);
        $normalized = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$menu])])])]);
        $savedProps = $normalized[0]['children'][0]['children'][0]['children'][0]['props'];
        assert_same('right', $savedProps['panel_side']);
        assert_same('', $savedProps['panel_background']);
        assert_same('', $savedProps['panel_text_color']);
    }

    foreach ([['panel' => 'dropdown'], ['panel' => 'drawer', 'collapse_mobile' => false], ['panel' => 'fullscreen', 'collapse_mobile' => false]] as $props) {
        $menu = LayoutSchema::node('menu', $props + ['panel_side' => 'left', 'panel_background' => 'primary', 'panel_text_color' => 'inverse']);
        $layout = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$menu])])])]);
        $html = (new NodeRenderer(comet_site_test_context($site)))->render($layout);
        assert_true(str_contains($html, '--c-menu-background:var(--c-primary)'));
        assert_true(str_contains($html, '--c-menu-text:var(--c-background)'));
        assert_false(str_contains($html, ' c-menu-drawer-left'));
    }

    // A background alone brings its contrasting text color, as a section fill does.
    foreach (['primary' => 'on-primary', 'surface' => 'text', 'custom-brand' => 'on-custom-brand'] as $background => $textToken) {
        $menu = LayoutSchema::node('menu', ['panel_background' => $background]);
        $layout = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$menu])])])]);
        $html = (new NodeRenderer(comet_site_test_context($site)))->render($layout);
        assert_true(str_contains($html, '--c-menu-text:var(--c-' . $textToken . ')'));
    }
    $html = comet_menu_test_render(comet_site_test_context($site));
    assert_false(str_contains($html, '--c-menu-background'));
    assert_false(str_contains($html, '--c-menu-text'));
});

test('menu groups open from their label while links keep a toggle and siblings share one disclosure name', function (): void {
    $site = comet_site_test_setup();
    $site->saveMenus([['name' => 'main', 'label' => 'Main', 'items' => [
        comet_menu_test_item('group', [comet_menu_test_item('one', [comet_menu_test_item('deep')]), comet_menu_test_item('two', [comet_menu_test_item('deeper')])], ['type' => 'none']),
        comet_menu_test_item('home', [comet_menu_test_item('child')]),
        comet_menu_test_item('plain'),
    ]]]);
    $html = comet_menu_test_render(comet_site_test_context($site));
    assert_true(str_contains($html, '<li class="c-has-children c-menu-group"><details class="c-menu-disclosure" name="'));
    assert_true(str_contains($html, '<summary class="c-menu-label"><span>group</span><span class="c-menu-chevron" aria-hidden="true"></span></summary>'));
    assert_true(str_contains($html, '<div class="c-menu-label"><a href="/">home</a></div><details'));
    assert_true(str_contains($html, '<summary aria-label="home"><span class="c-menu-chevron" aria-hidden="true"></span></summary>'));
    assert_true(str_contains($html, '<li><div class="c-menu-label"><a href="/">plain</a></div></li>'));
    preg_match_all('/<details class="c-menu-disclosure" name="([^"]+)">/', $html, $names);
    // The bar's items share a name, and so do the siblings one level down, but not with each other.
    assert_same(4, count($names[1]));
    assert_same($names[1][0], $names[1][3]);
    assert_same($names[1][1], $names[1][2]);
    assert_true($names[1][0] !== $names[1][1]);
    assert_true(str_contains($html, ' data-menu '));
});

test('menus load their hashed closing module only for submenus and panels while previews stay script free', function (): void {
    $site = comet_site_test_setup();
    foreach ([[[comet_menu_test_item('home')], [], false], [[comet_menu_test_item('home', [comet_menu_test_item('child')])], [], true], [[comet_menu_test_item('home')], ['panel' => 'drawer'], true]] as [$items, $props, $loads]) {
        $site->saveMenus([['name' => 'main', 'label' => 'Main', 'items' => $items]]);
        $menu = LayoutSchema::node('menu', $props);
        $site->saveRegion('header', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$menu])])])]);
        $ctx = comet_site_test_context($site);
        $renderer = new \CometCMS\Site\PageRenderer($ctx);
        $html = $renderer->entryPage($ctx->type('blog'), $ctx->findEntry('blog', 'hello'));
        assert_same($loads ? 1 : 0, substr_count($html, '<script type="module">' . \CometCMS\Site\Menus::script()));
        $ctx->preview = true;
        assert_false(str_contains($renderer->regionPreview('header'), '<script'));
    }
    assert_same("'sha256-" . base64_encode(hash('sha256', \CometCMS\Site\Menus::script(), true)) . "'", \CometCMS\Site\Menus::cspSource());
});

test('menus preserve five levels with IDs labels links and sibling order after reload', function (): void {
    $site = new SiteRepository();
    $menus = [['name' => 'main', 'label' => 'Main', 'items' => [comet_menu_test_chain(5), comet_menu_test_item('second')]]];
    $saved = $site->saveMenus($menus);
    assert_same($saved, (new SiteRepository())->menus());
    assert_same(['level5', 'second'], array_column($saved[0]['items'], 'id'));
    $node = $saved[0]['items'][0];
    for ($level = 5; $level > 0; $level--) {
        assert_same('level' . $level, $node['id']);
        assert_same('DE level' . $level, $node['label']['values']['de']);
        assert_same('home', $node['link']['type']);
        if ($level > 1) $node = $node['children'][0];
    }
});

test('menus reject excessive depth sibling counts and menu counts without changing saved data', function (): void {
    $site = new SiteRepository();
    $original = $site->saveMenus([['name' => 'main', 'items' => [comet_menu_test_item('original')]]]);
    $cases = [
        [['name' => 'main', 'items' => [comet_menu_test_chain(6)]]],
        [['name' => 'main', 'items' => array_map(fn($i) => comet_menu_test_item('item' . $i), range(1, 61))]],
        array_map(fn($i) => ['name' => 'menu' . $i, 'items' => []], range(1, 21)),
    ];
    foreach ($cases as $menus) {
        assert_throws(ValidationException::class, fn() => $site->saveMenus($menus));
        assert_same($original, $site->menus());
    }
});

test('menu validation rejects incomplete links and invalid anchors but preserves missing references', function (): void {
    $site = new SiteRepository();
    foreach ([
        ['type' => 'url', 'url' => 'javascript:alert(1)'],
        ['type' => 'url', 'url' => ''],
        ['type' => 'entry', 'collection' => 'blog'],
        ['type' => 'index'],
        ['type' => 'anchor', 'anchor' => 'two words'],
        ['type' => 'home', 'anchor' => str_repeat('x', 81)],
    ] as $link) {
        assert_throws(ValidationException::class, fn() => $site->saveMenus([['name' => 'main', 'items' => [comet_menu_test_item('item', [], $link)]]]));
    }
    $missing = ['type' => 'entry', 'collection' => 'removed', 'entry' => 'old-slug'];
    $saved = $site->saveMenus([['name' => 'main', 'items' => [comet_menu_test_item('missing', [], $missing)]]]);
    assert_same('old-slug', $saved[0]['items'][0]['link']['entry']);
    $incomplete = [['name' => 'main', 'items' => [['label' => '', 'link' => ['type' => 'none'], 'children' => []]]]];
    assert_throws(ValidationException::class, fn() => $site->saveMenus($incomplete));
    $site->validateMenus($incomplete, false); // Previews can render in-progress items.
});

test('anchors normalize like builder IDs and preserve existing URL link formats', function (): void {
    assert_same(['type' => 'anchor', 'anchor' => 'contact', 'new_tab' => false], LayoutSchema::normalizeLink(['type' => 'anchor', 'anchor' => ' #contact ', 'new_tab' => true]));
    foreach (['/about#team', 'https://example.test/#team', 'mailto:hello@example.test', 'tel:+43123'] as $url) {
        assert_same($url, LayoutSchema::normalizeLink(['type' => 'url', 'url' => $url])['url']);
    }
    assert_same(['type' => 'home', 'new_tab' => false], LayoutSchema::normalizeLink(['type' => 'home']));
});

test('published menus render independent disclosures and localized prefixed anchor destinations', function (): void {
    $site = comet_site_test_setup(['blog' => ['enabled' => true], 'about' => ['enabled' => true]], ['locales' => ['en', 'de'], 'default_locale' => 'en']);
    (new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'languages', 'locales' => ['en', 'de'], 'default_locale' => 'en']);
    $site = new SiteRepository();
    $site->saveSettings(['enabled' => true, 'locales' => ['en', 'de'], 'default_locale' => 'en', 'routes' => ['blog' => ['enabled' => true], 'about' => ['enabled' => true]]]);
    (new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'blog', 'locales' => ['en', 'de']]);
    $entry = ContentRepository::make()->findByIdentifier('blog', 'hello');
    ContentRepository::make()->save('blog', ['locale' => 'de', 'title' => 'Hallo', 'slug' => 'hello', 'status' => 'published'], comet_site_test_user(), $entry['id']);
    ContentRepository::make()->save('blog', ['title' => 'Future', 'slug' => 'future', 'status' => 'published', 'published_at' => gmdate('c', time() + 86400)], comet_site_test_user());
    $site->saveMenus([['name' => 'main', 'label' => 'Main', 'items' => [
        comet_menu_test_item('home', [comet_menu_test_chain(4)], ['type' => 'home', 'anchor' => '#intro']),
        comet_menu_test_item('entry', [], ['type' => 'entry', 'collection' => 'blog', 'entry' => $entry['uid'], 'anchor' => 'details']),
        comet_menu_test_item('listing', [], ['type' => 'index', 'collection' => 'blog', 'anchor' => 'latest']),
        comet_menu_test_item('section', [], ['type' => 'anchor', 'anchor' => 'contact']),
        comet_menu_test_item('group', [comet_menu_test_item('group-child')], ['type' => 'none']),
        comet_menu_test_item('draft', [], ['type' => 'entry', 'collection' => 'blog', 'entry' => 'secret-draft']),
        comet_menu_test_item('scheduled', [], ['type' => 'entry', 'collection' => 'blog', 'entry' => 'future']),
        comet_menu_test_item('external', [], ['type' => 'url', 'url' => 'https://example.org', 'new_tab' => true]),
    ]]]);
    $context = SiteContext::make(new SiteRepository(), '/cms', 'https://example.test');
    $context->locale = 'de';
    $html = comet_menu_test_render($context, ['path' => '/cms/de/blog/hello']);
    foreach (['href="/cms/de#intro"', 'href="/cms/de/blog/hello#details"', 'href="/cms/de/blog#latest"', 'href="#contact"', '<span>DE group</span>', 'rel="noopener noreferrer"'] as $needle) {
        assert_true(str_contains($html, $needle), $needle);
    }
    assert_same(5, substr_count($html, '<details class="c-menu-disclosure"'));
    assert_true(str_contains($html, '<span>DE draft</span>'));
    assert_false(str_contains($html, 'href="/cms/de/blog/secret-draft'));
    assert_true(str_contains($html, '<span>DE scheduled</span>'));
    assert_false(str_contains($html, 'href="/cms/de/blog/future'));
    assert_false(str_contains($html, '<script'));
    assert_true(str_contains($html, 'href="/cms/de/blog/hello#details" aria-current="page"'));
    assert_true(str_contains($html, '</a></div><details'), 'Parent links and disclosure toggles are separate controls.');
});

test('stable menu destinations survive entry slug changes', function (): void {
    $site = comet_site_test_setup();
    $content = ContentRepository::make();
    $entry = $content->findByIdentifier('blog', 'hello');
    $site->saveMenus([['name' => 'main', 'items' => [comet_menu_test_item('entry', [], ['type' => 'entry', 'collection' => 'blog', 'entry' => $entry['uid']])]]]);
    $content->save('blog', ['title' => 'Renamed', 'slug' => 'renamed', 'status' => 'published'], comet_site_test_user(), $entry['id']);
    assert_true(str_contains(comet_menu_test_render(comet_site_test_context(new SiteRepository())), 'href="/blog/renamed"'));
});

test('menu API exposes limits and returns field errors instead of truncating submissions', function (): void {
    [$status, $body] = comet_admin_request('admin', '', 'GET', '/admin/api/site', '(new \\CometCMS\\Controllers\\Admin\\SiteController(new \\CometCMS\\Core\\Http()))->index();');
    assert_same(200, $status);
    assert_same(SiteRepository::MENU_LIMITS, $body['data']['menu_limits']);
    [$status, $body] = comet_admin_request('admin', '', 'PUT', '/admin/api/site/menus', '(new \\CometCMS\\Controllers\\Admin\\SiteController(new \\CometCMS\\Core\\Http()))->updateMenus();', [
        'menus' => [['name' => 'main', 'items' => [comet_menu_test_chain(6)]]],
    ]);
    assert_same(422, $status);
    assert_same('validation_failed', $body['error']['code']);
    assert_true(count($body['error']['fields']) > 0);
});

test('menu hover effects, hover colors and current page styles are fixed choices', function (): void {
    $site = comet_site_test_setup();
    $site->saveMenus([['name' => 'main', 'label' => 'Main', 'items' => [comet_menu_test_item('home')]]]);
    $render = function (array $props) use ($site): string {
        $menu = LayoutSchema::node('menu', $props);
        $layout = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$menu])])])]);

        return (new NodeRenderer(comet_site_test_context($site)))->render($layout);
    };

    $html = comet_menu_test_render(comet_site_test_context($site));
    assert_true(str_contains($html, ' c-menu-fx-background c-menu-active-bold'), 'Existing menus keep the row highlight and bold current page.');
    assert_false(str_contains($html, '--c-menu-hover'));

    $html = $render(['hover_effect' => 'grow', 'hover_color' => 'accent', 'active_style' => 'effect']);
    assert_true(str_contains($html, ' c-menu-fx-grow c-menu-active-effect'));
    assert_true(str_contains($html, '--c-menu-hover:var(--c-accent)'));
    assert_true(str_contains($render(['hover_color' => 'inverse']), '--c-menu-hover:var(--c-background)'));
    assert_true(str_contains($render(['hover_color' => 'custom-brand']), '--c-menu-hover:var(--c-custom-brand)'));

    $html = $render(['hover_effect' => 'blink; color:red', 'hover_color' => 'red;}', 'active_style' => ['bold']]);
    assert_true(str_contains($html, ' c-menu-fx-background c-menu-active-bold'));
    assert_false(str_contains($html, '--c-menu-hover'));
});

test('menu item icons are optional, validated against the bundled set and rendered inside the link', function (): void {
    $site = comet_site_test_setup();
    $items = [
        comet_menu_test_item('home') + ['icon' => 'mdi:home-outline'],
        comet_menu_test_item('group', [comet_menu_test_item('child') + ['icon' => 'mdi:star-outline']], ['type' => 'none']) + ['icon' => 'mdi:folder-outline'],
        comet_menu_test_item('plain') + ['icon' => 'not-an-icon'],
    ];
    $site->saveMenus([['name' => 'main', 'label' => 'Main', 'items' => $items]]);
    $saved = (new SiteRepository())->menu('main')['items'];
    assert_same('mdi:home-outline', $saved[0]['icon']);
    assert_same('mdi:folder-outline', $saved[1]['icon']);
    assert_same('mdi:star-outline', $saved[1]['children'][0]['icon']);
    assert_same('', $saved[2]['icon']);

    $html = comet_menu_test_render(comet_site_test_context($site));
    assert_same(3, substr_count($html, 'class="c-menu-icon"'));
    assert_true((bool) preg_match('~<a [^>]*><svg class="c-menu-icon"[^>]*aria-hidden="true">.+?</svg>home</a>~', $html));
    assert_true((bool) preg_match('~<summary class="c-menu-label"><span><svg class="c-menu-icon"[^>]*>.+?</svg>group</span>~', $html));
    assert_true(str_contains($html, '>plain</a>'));
});
