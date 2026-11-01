<?php

declare(strict_types=1);

use CometCMS\Auth\PermissionService;
use CometCMS\Auth\RoleRepository;
use CometCMS\Cache\ApiCache;
use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\ValidationException;
use CometCMS\Site\Flags;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\SiteContext;
use CometCMS\Site\SiteRepository;
use CometCMS\Site\SiteService;
use CometCMS\Site\SmartValue;
use CometCMS\Site\Theme;
use CometCMS\Storage\JsonStore;

function comet_site_test_user(): array
{
    return ['id' => 'admin', 'role' => 'admin'];
}

/** A "blog" collection, an "about" page served at /about and a private "notes" type. */
function comet_site_test_setup(array $routes = ['blog' => ['enabled' => true], 'about' => ['enabled' => true, 'pattern' => '/{slug}', 'index_path' => '']], array $settings = []): SiteRepository
{
    $types = new ContentTypeRepository();
    $types->save([
        'name' => 'authors',
        'fields' => ['bio' => ['type' => 'textarea']],
    ]);
    $types->save([
        'name' => 'blog',
        'label' => 'Blog',
        'fields' => [
            'body' => ['type' => 'markdown', 'label' => 'Body'],
            'category' => ['type' => 'select', 'options' => ['news' => 'News', 'tips' => 'Tips & tricks']],
            'author' => ['type' => 'relation', 'target' => 'authors'],
            'steps' => ['type' => 'repeater', 'subfields' => [
                ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                ['key' => 'detail', 'type' => 'textarea', 'label' => 'Detail'],
            ]],
        ],
    ]);
    $types->save(['name' => 'about', 'fields' => ['intro' => ['type' => 'textarea']]]);
    $types->save(['name' => 'notes', 'visibility' => 'private', 'fields' => []]);

    $content = ContentRepository::make();
    $user = comet_site_test_user();
    $content->save('authors', ['title' => 'Ada', 'bio' => 'Writes code.', 'status' => 'published'], $user);
    $content->save('blog', [
        'title' => 'Hello <script>alert(1)</script>',
        'slug' => 'hello',
        'body' => "Some **bold** text.\n\n<script>alert('x')</script>\n\n[bad](javascript:alert(1))",
        'category' => 'tips',
        'author' => 'ada',
        'steps' => [['heading' => 'First step', 'detail' => 'Do it'], ['heading' => 'Second step', 'detail' => 'Again']],
        'status' => 'published',
    ], $user);
    $content->save('blog', ['title' => 'Secret draft', 'slug' => 'secret-draft', 'status' => 'draft'], $user);
    $content->save('about', ['title' => 'About us', 'slug' => 'about', 'intro' => 'We build things.', 'status' => 'published'], $user);
    $content->save('notes', ['title' => 'Private note', 'status' => 'published'], $user);

    $site = new SiteRepository();
    $site->saveSettings(array_replace_recursive(['enabled' => true, 'title' => 'Comet Site', 'routes' => $routes], $settings));

    return new SiteRepository();
}

function comet_site_test_context(?SiteRepository $site = null): SiteContext
{
    return SiteContext::make($site ?? new SiteRepository(), '', 'https://example.test');
}

function comet_site_test_get(string $path, array $query = []): array
{
    return (new SiteService(comet_site_test_context()))->respond($path, $query);
}

test('layout schema drops unknown kinds, misplaced nodes and unsafe values', function (): void {
    $nodes = LayoutSchema::normalize([
        ['kind' => 'heading', 'props' => ['text' => 'Not allowed at the root']],
        ['id' => 'dup', 'kind' => 'section', 'props' => ['width' => 'gigantic', 'unknown' => 1], 'style' => ['padding_top' => 'lg', 'padding_bottom' => 'lg', 'background' => 'url(evil)', 'class' => 'ok bad"class <x> also-ok'], 'children' => [
            ['id' => 'dup', 'kind' => 'row', 'children' => [
                ['kind' => 'column', 'props' => ['span' => ['mobile' => 40, 'tablet' => 6]], 'children' => [
                    ['kind' => 'text', 'props' => ['html' => '<p onclick="x()">Hi<script>alert(1)</script></p>']],
                    ['kind' => 'button', 'props' => ['link' => ['type' => 'url', 'url' => 'javascript:alert(1)']]],
                    ['kind' => 'section'],
                    ['kind' => 'marquee'],
                    ['kind' => 'field', 'props' => ['field' => 'steps'], 'children' => [
                        ['kind' => 'field', 'props' => ['field' => 'heading'], 'children' => [['kind' => 'text']]],
                        ['kind' => 'collection'],
                    ]],
                ]],
            ]],
        ]],
    ]);

    assert_same(1, count($nodes));
    $section = $nodes[0];
    assert_same('contained', $section['props']['width']);
    assert_false(array_key_exists('unknown', $section['props']));
    assert_same(['padding_top' => 'lg', 'padding_bottom' => 'lg', 'class' => 'ok also-ok'], $section['style']);

    $row = $section['children'][0];
    assert_true($row['id'] !== 'dup', 'Duplicate ids are replaced.');
    $column = $row['children'][0];
    assert_same(['mobile' => 12, 'tablet' => 6, 'desktop' => 0], $column['props']['span']);
    assert_same(['text', 'button', 'field'], array_column($column['children'], 'kind'));
    assert_same('<p>Hi</p>', $column['children'][0]['props']['html']);
    assert_same('', $column['children'][1]['props']['link']['url']);

    $template = $column['children'][2]['children'];
    assert_same(['field'], array_column($template, 'kind'), 'Collections are not allowed in item templates.');
    assert_same([], $template[0]['children'], 'Item templates cannot nest.');
});

test('individual spacing survives saving and renders on every edge', function (): void {
    $site = comet_site_test_setup();
    $style = [
        'padding_top' => 'none', 'padding_right' => '2xl', 'padding_bottom' => 'sm', 'padding_left' => 'xs',
        'margin_top' => 'xs', 'margin_right' => 'sm', 'margin_bottom' => 'md', 'margin_left' => 'lg',
    ];
    $nodes = [LayoutSchema::node('section', [], $style, [
        LayoutSchema::node('row', [], $style, [LayoutSchema::node('column', [], $style, [
            LayoutSchema::node('text', ['html' => '<p>Spaced</p>'], $style),
        ])]),
    ])];
    $saved = $site->saveLayout('about', 'detail', $nodes);
    $reloaded = (new SiteRepository())->layout('about', 'detail');
    assert_same($saved, $reloaded);
    $current = $reloaded[0];
    foreach (['section', 'row', 'column', 'text'] as $kind) {
        assert_same($kind, $current['kind']);
        foreach ($style as $key => $value) {
            assert_same($value, $current['style'][$key]);
        }
        $current = $current['children'][0] ?? [];
    }
    $html = (new NodeRenderer(comet_site_test_context($site)))->render($reloaded);
    foreach (['pt-none', 'pr-2xl', 'pb-sm', 'pl-xs', 'mt-xs', 'mr-sm', 'mb-md', 'ml-lg'] as $class) {
        assert_same(4, substr_count($html, 'c-' . $class));
    }
    $invalid = LayoutSchema::normalize([LayoutSchema::node('section', [], ['padding_top' => '999px', 'margin_left' => '-1', 'margin_right' => 'none'])]);
    assert_same(['margin_right' => 'none'], $invalid[0]['style']);
});

test('layout names survive saving and loading without changing rendered content', function (): void {
    $site = comet_site_test_setup();
    // Persist the header so generated menu ids stay stable between renders.
    $site->saveRegion('header', $site->region('header'));
    $nodes = LayoutSchema::defaultLayout($site->types()['blog'], 'detail');
    $before = $site->saveLayout('blog', 'detail', $nodes);
    $html = comet_site_test_get('/blog/hello')['body'];
    $nodes[0]['name'] = '  Hero section  ';
    $nodes[0]['children'][0]['name'] = 'Übersicht 🪐';
    $saved = $site->saveLayout('blog', 'detail', $nodes);
    assert_same('Hero section', $saved[0]['name']);
    assert_same('Übersicht 🪐', $saved[0]['children'][0]['name']);
    assert_same($saved, (new SiteRepository())->layout('blog', 'detail'));
    assert_same($html, comet_site_test_get('/blog/hello')['body']);
    unset($saved[0]['name'], $saved[0]['children'][0]['name']);
    assert_same($before, $saved);
});

test('layout names are optional bounded strings on every kind of node', function (): void {
    $nodes = LayoutSchema::normalize([
        ['kind' => 'section', 'name' => 'ä' . str_repeat('🪐', 130), 'children' => [
            ['kind' => 'row', 'name' => ['invalid'], 'children' => [
                ['kind' => 'column', 'name' => " \n ", 'children' => [
                    ['kind' => 'heading', 'name' => "Heading\nname"],
                ]],
            ]],
        ]],
        ['kind' => 'section'],
        ['kind' => 'component', 'name' => 'Footer instance', 'props' => ['component' => 'shared', 'root' => 'section']],
    ]);
    assert_same('ä' . str_repeat('🪐', LayoutSchema::MAX_NODE_NAME_LENGTH - 1), $nodes[0]['name']);
    $row = $nodes[0]['children'][0];
    assert_false(array_key_exists('name', $row));
    assert_false(array_key_exists('name', $row['children'][0]));
    assert_same('Heading name', $row['children'][0]['children'][0]['name']);
    assert_false(array_key_exists('name', $nodes[1]));
    assert_same('Footer instance', $nodes[2]['name']);
    assert_same($nodes, LayoutSchema::normalize($nodes));
    $component = LayoutSchema::normalizeComponent([$nodes[0]], 'section');
    assert_same($nodes[0]['name'], $component[0]['name']);
});

test('rows nest inside columns up to a depth and collections repeat one card', function (): void {
    $nest = static function (int $depth) use (&$nest): array {
        return LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], $depth > 1 ? [$nest($depth - 1)] : [])]);
    };
    $column = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [
        LayoutSchema::node('column', [], [], [
            $nest(LayoutSchema::MAX_ROW_DEPTH + 2),
            LayoutSchema::node('collection', ['type' => 'blog'], [], [LayoutSchema::node('column'), LayoutSchema::node('column'), LayoutSchema::node('heading')]),
        ]),
    ])])])[0]['children'][0]['children'][0];

    $depth = 0;
    for ($node = $column['children'][0]; $node !== null; $node = $node['children'][0]['children'][0] ?? null) {
        $depth++;
    }
    assert_same(LayoutSchema::MAX_ROW_DEPTH, $depth, 'Rows deeper than the limit are dropped.');
    assert_same(['column'], array_column($column['children'][1]['children'], 'kind'), 'A collection keeps one card.');
});

test('collections render their own card for every entry', function (): void {
    $site = comet_site_test_setup();
    ContentRepository::make()->save('blog', ['title' => 'Second post', 'slug' => 'second', 'status' => 'published'], comet_site_test_user());
    $card = LayoutSchema::node('column', [], ['shadow' => 'md'], [
        LayoutSchema::node('text', ['html' => ['type' => 'template', 'parts' => ['<p>Card for ', SmartValue::reference('title'), '</p>']]]),
        LayoutSchema::node('row', [], [], [
            LayoutSchema::node('column', ['span' => ['mobile' => 12, 'tablet' => 0, 'desktop' => 6]], [], [LayoutSchema::node('text', ['html' => '<p>Left</p>'])]),
            LayoutSchema::node('column', ['span' => ['mobile' => 12, 'tablet' => 0, 'desktop' => 6]], [], [LayoutSchema::node('text', ['html' => '<p>Right</p>'])]),
        ]),
    ]);
    $site->saveLayout('blog', 'index', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [
        LayoutSchema::node('column', ['span' => ['mobile' => 12, 'tablet' => 0, 'desktop' => 6]], [], [
            LayoutSchema::node('collection', ['type' => 'blog'], [], [$card]),
        ]),
    ])])]);

    $html = comet_site_test_get('/blog')['body'];
    assert_true(str_contains($html, 'Card for Second post') && str_contains($html, 'Card for Hello &lt;script&gt;'), 'Current entry is the entry of each card.');
    assert_same(2, substr_count($html, 'c-row-nested'), 'Rows inside the card are nested rows.');
    assert_same(4, substr_count($html, 'c-cd-col-6'), 'Nested columns switch by their row width.');
    assert_true(str_contains($html, 'c-d-col-6'), 'Top-level columns keep switching by the screen width.');
});

test('collections without a card of their own show the standard card', function (): void {
    $site = comet_site_test_setup();
    $site->saveLayout('blog', 'index', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [
        LayoutSchema::node('column', [], [], [LayoutSchema::node('collection', ['type' => 'blog'])]),
    ])])]);
    assert_true(str_contains(comet_site_test_get('/blog')['body'], 'Hello &lt;script&gt;'), 'The generated card shows the title.');

    $store = new JsonStore(comet_test_workspace_path() . '/site/theme');
    $store->write(['format_version' => LayoutSchema::FORMAT_VERSION, 'nodes' => [
        LayoutSchema::node('section', [], ['background' => 'accent', 'padding_top' => 'xl', 'padding_bottom' => 'xl'], [LayoutSchema::node('row', [], [], [
            LayoutSchema::node('column', [], [], [LayoutSchema::node('text', ['html' => '<p>Legacy card</p>'])]),
        ])]),
    ]], 'layouts', 'blog', 'card');
    $template = $site->cardTemplate('blog');
    assert_same(['column'], array_column($template, 'kind'));
    assert_false(str_contains(comet_site_test_get('/blog')['body'], 'Legacy card'), 'Retired per-type cards are ignored.');
});

test('default and legacy builder designs can be saved with their loaded revisions', function (): void {
    comet_site_test_setup();
    $theme = new JsonStore(comet_test_workspace_path() . '/site/theme');

    foreach ([false, true] as $legacy) {
        foreach ([['about', 'detail'], ['blog', 'detail'], ['blog', 'index']] as [$type, $variant]) {
            $theme->delete('content', $type, $variant, 'default');
            if ($legacy) $theme->write(['format_version' => 0, 'nodes' => []], 'content', $type, $variant, 'default');
            $site = new SiteRepository();
            assert_same($legacy, $site->needsLayoutUpgrade($type, $variant));
            $nodes = $site->layout($type, $variant);
            $revision = \CometCMS\Localization\LocalizedValue::hash($nodes);
            $reloaded = new SiteRepository();
            assert_same($nodes, $reloaded->layout($type, $variant));
            $saved = $reloaded->saveLayout($type, $variant, $nodes, [], 'saved', $revision);
            assert_same($saved, (new SiteRepository())->layout($type, $variant));
            assert_false($reloaded->needsLayoutUpgrade($type, $variant));
            assert_throws(\CometCMS\Core\ConflictException::class, fn() => $reloaded->saveLayout($type, $variant, [], [], 'saved', 'stale'));
            $reset = $reloaded->resetLayout($type, $variant);
            $reloaded->saveLayout($type, $variant, $reset, [], 'saved', \CometCMS\Localization\LocalizedValue::hash($reset));
        }

        foreach (['header', 'footer'] as $region) {
            $theme->delete('regions', $region);
            if ($legacy) $theme->write(['format_version' => 0, 'nodes' => []], 'regions', $region);
            $site = new SiteRepository();
            assert_same($legacy, $site->needsRegionUpgrade($region));
            $nodes = $site->region($region);
            $revision = \CometCMS\Localization\LocalizedValue::hash($nodes);
            $reloaded = new SiteRepository();
            assert_same($nodes, $reloaded->region($region));
            $saved = $reloaded->saveRegion($region, $nodes, [], 'saved', $revision);
            assert_same($saved, (new SiteRepository())->region($region));
            assert_false($reloaded->needsRegionUpgrade($region));
            assert_throws(\CometCMS\Core\ConflictException::class, fn() => $reloaded->saveRegion($region, [], [], 'saved', 'stale'));
            $reset = $reloaded->resetRegion($region);
            $reloaded->saveRegion($region, $reset, [], 'saved', \CometCMS\Localization\LocalizedValue::hash($reset));
        }
    }
});

test('legacy builder element ID repairs keep revision checks stable', function (): void {
    comet_site_test_setup();
    $theme = new JsonStore(comet_test_workspace_path() . '/site/theme');
    $nodes = LayoutSchema::defaultLayout((new SiteRepository())->types()['about'], 'detail');
    $damageIds = function (array $tree) use (&$damageIds): array {
        foreach ($tree as $index => &$node) {
            // A hand-edited or migrated design may have absent and repeated IDs.
            if ($index % 2 === 0) unset($node['id']);
            else $node['id'] = 'duplicate';
            $node['children'] = $damageIds($node['children'] ?? []);
        }
        return $tree;
    };
    $nodes = $damageIds($nodes);
    foreach (['layout', 'region'] as $target) {
        if ($target === 'layout') $theme->write(['format_version' => LayoutSchema::FORMAT_VERSION, 'nodes' => $nodes], 'content', 'about', 'detail', 'default');
        else $theme->write(['format_version' => LayoutSchema::FORMAT_VERSION, 'nodes' => $nodes], 'regions', 'header');
        $read = static fn(SiteRepository $site): array => $target === 'layout' ? $site->layout('about', 'detail') : $site->region('header');
        $loaded = $read(new SiteRepository());
        assert_same($loaded, $read(new SiteRepository()), 'Repairing element IDs must not change the revision on the next request.');
        assert_same($loaded, LayoutSchema::normalize($loaded), 'Repaired IDs must stay unique and stable.');
        $revision = \CometCMS\Localization\LocalizedValue::hash($loaded);
        $site = new SiteRepository();
        $saved = $target === 'layout'
            ? $site->saveLayout('about', 'detail', [], [], 'saved', $revision)
            : $site->saveRegion('header', [], [], 'saved', $revision);
        assert_same([], $saved);
        assert_throws(\CometCMS\Core\ConflictException::class, fn() => $target === 'layout'
            ? $site->saveLayout('about', 'detail', $loaded, [], 'saved', $revision)
            : $site->saveRegion('header', $loaded, [], 'saved', $revision));
    }
});

test('default layouts are generated from the content type fields', function (): void {
    comet_site_test_setup();
    $site = new SiteRepository();
    $detail = json_encode($site->layout('blog', 'detail'));

    foreach (['"title"', '"body"', '"category"', '"author"', '"steps"', '"heading"'] as $needle) {
        assert_true(str_contains($detail, $needle), 'Detail layout should bind ' . $needle);
    }

    assert_true(str_contains(json_encode($site->layout('blog', 'index')), '"collection"'));
    assert_false($site->layoutCustomized('blog', 'detail'));
    assert_throws(InvalidArgumentException::class, fn() => $site->layout('blog', 'card'), 'Cards live in collections now.');
});

test('website settings reject reserved, malformed and duplicate URLs', function (): void {
    comet_site_test_setup();
    $site = new SiteRepository();

    $cases = [
        ['routes' => ['blog' => ['enabled' => true, 'pattern' => '/admin/{slug}']]],
        ['routes' => ['blog' => ['enabled' => true, 'pattern' => '/blog']]],
        ['routes' => ['about' => ['enabled' => true, 'pattern' => '/about']]],
        ['routes' => ['blog' => ['enabled' => true, 'index_path' => '/about'], 'about' => ['enabled' => true, 'pattern' => '/about/{slug}', 'index_path' => '/about']]],
        ['routes' => ['blog' => ['enabled' => true, 'pattern' => '/Blog Posts/{slug}']]],
    ];

    foreach ($cases as $index => $settings) {
        assert_throws(ValidationException::class, fn() => $site->saveSettings($settings), 'Case ' . $index . ' should be rejected.');
    }

    $saved = $site->saveSettings(['enabled' => true, 'routes' => ['notes' => ['enabled' => true], 'blog' => ['enabled' => true, 'pattern' => '/posts//{slug}/']]]);
    assert_false($saved['routes']['notes']['enabled'], 'Private types can never be routed.');
    assert_same('/posts/{slug}', $saved['routes']['blog']['pattern']);
});

test('list pages are optional and only exist once turned on', function (): void {
    comet_site_test_setup(['blog' => ['enabled' => true, 'index_enabled' => false], 'about' => ['enabled' => true, 'pattern' => '/{slug}', 'index_path' => '']]);
    $site = new SiteRepository();
    assert_false($site->settings()['routes']['blog']['index_enabled']);
    assert_false($site->settings()['routes']['about']['index_enabled'], 'Routes saved without a list URL keep having no list page.');
    assert_same(['detail'], $site->enabledVariantsFor($site->types()['blog']));
    assert_same(404, comet_site_test_get('/blog')['status']);
    assert_same(200, comet_site_test_get('/blog/hello')['status']);
    assert_null(comet_site_test_context()->indexUrl('blog'));
    assert_false(str_contains(comet_site_test_get('/sitemap.xml')['body'], 'example.test/blog<'), 'The sitemap omits list pages that are turned off.');

    // A list page cannot be the homepage until it is turned on.
    assert_throws(ValidationException::class, fn() => $site->saveSettings(['homepage' => ['type' => 'blog', 'entry' => ''], 'routes' => ['blog' => ['enabled' => true, 'index_enabled' => false]]]));
    // The URL of a list page that is off is neither validated nor claimed.
    $site->saveSettings(['routes' => ['blog' => ['enabled' => true, 'index_enabled' => false, 'index_path' => '/about'], 'about' => ['enabled' => true, 'pattern' => '/{slug}', 'index_path' => '']]]);
    assert_throws(ValidationException::class, fn() => $site->saveSettings(['routes' => ['blog' => ['enabled' => true, 'index_enabled' => true, 'index_path' => '']]]), 'A list page that is on needs a URL.');

    $site->saveSettings(['routes' => ['blog' => ['enabled' => true, 'index_enabled' => true, 'index_path' => '/blog']]]);
    assert_same(200, comet_site_test_get('/blog')['status']);
    assert_same(['detail', 'index'], (new SiteRepository())->enabledVariantsFor($site->types()['blog']));
});

test('website renders published entries with escaped content and sanitized markdown', function (): void {
    comet_site_test_setup();
    $response = comet_site_test_get('/blog/hello');

    assert_same(200, $response['status']);
    $html = $response['body'];
    assert_true(str_contains($html, 'Hello &lt;script&gt;alert(1)&lt;/script&gt;'), 'Titles are escaped.');
    assert_true(str_contains($html, '<strong>bold</strong>'), 'Markdown is rendered.');
    assert_false(str_contains($html, '<script'), 'No script tags reach the page.');
    assert_false(str_contains($html, 'javascript:'), 'Unsafe links are removed.');
    assert_true(str_contains($html, 'Tips &amp; tricks'), 'Select values show their labels.');
    assert_true(str_contains($html, 'First step') && str_contains($html, 'Second step'), 'Repeater rows render through the item template.');
    assert_true(str_contains($html, 'Ada'), 'Relations render related entries.');
    assert_true(str_contains($html, '<link rel="canonical" href="https://example.test/blog/hello">'));
    assert_true(str_contains($html, '<title>Hello &lt;script&gt;alert(1)&lt;/script&gt; · Comet Site</title>'));
    assert_true(str_contains($html, '/_site/default/style.css?v='));
});

test('a 404 page built in the Page Builder replaces the default message', function (): void {
    $site = comet_site_test_setup();
    assert_true(str_contains(comet_site_test_get('/nothing/here')['body'], 'Page not found'), 'The default 404 message is used until the page is customized.');

    $site->saveRegion('not_found', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading', ['text' => 'Lost in space', 'level' => 'h1'])])])])]);
    $response = comet_site_test_get('/nothing/here');
    assert_same(404, $response['status']);
    assert_true(str_contains($response['body'], 'Lost in space'));
    assert_true(str_contains($response['body'], 'c-header'), 'Header and footer still surround the custom 404 page.');
    assert_false(str_contains(comet_site_test_get('/blog/hello')['body'], 'Lost in space'), 'The 404 page is not shown on other pages.');

    $site->resetRegion('not_found');
    assert_false(str_contains(comet_site_test_get('/nothing/here')['body'], 'Lost in space'));
});

test('website hides drafts, private types and unknown paths', function (): void {
    comet_site_test_setup();

    assert_same(404, comet_site_test_get('/blog/secret-draft')['status']);
    assert_same(404, comet_site_test_get('/notes/private-note')['status']);
    assert_same(404, comet_site_test_get('/nothing/here')['status']);

    $index = comet_site_test_get('/blog');
    assert_same(200, $index['status']);
    assert_true(str_contains($index['body'], '/blog/hello'));
    assert_false(str_contains($index['body'], 'Secret draft'));

    $about = comet_site_test_get('/about');
    assert_same(200, $about['status']);
    assert_true(str_contains($about['body'], 'We build things.'));

    $preview = (new SiteService(comet_site_test_context()))->previewEntry('blog', 'secret-draft');
    assert_true(is_string($preview) && str_contains($preview, 'Secret draft'), 'Drafts can be previewed by editors.');
    assert_true(str_contains((string) $preview, 'noindex'));
    assert_null((new SiteService(comet_site_test_context()))->previewEntry('notes', 'private-note'));
});

test('list page and homepage previews render saved layouts while the website is off', function (): void {
    $site = comet_site_test_setup(['blog' => ['enabled' => true]], ['enabled' => false]);
    $site->saveRegion('header', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading', ['text' => 'Saved header'])])])])]);

    $index = (new SiteService(comet_site_test_context()))->previewIndex('blog');
    assert_true(is_string($index) && str_contains($index, '/blog/hello'));
    assert_false(str_contains($index, 'Secret draft'), 'List pages only list published entries.');
    assert_true(str_contains($index, 'c-preview-bar') && str_contains($index, 'noindex'));
    assert_null((new SiteService(comet_site_test_context()))->previewIndex('notes'));
    assert_null((new SiteService(comet_site_test_context()))->previewIndex('missing'));

    $home = (new SiteService(comet_site_test_context()))->previewHome();
    assert_true(str_contains($home, 'Saved header'), 'Without a homepage, header and footer frame a stand-in.');
    assert_true(str_contains($home, 'c-preview-bar'));
    assert_false(str_contains($home, ' data-node="'), 'Previews carry no builder annotations.');

    $site->saveSettings(['homepage' => ['type' => 'blog', 'entry' => '']]);
    $home = (new SiteService(comet_site_test_context()))->previewHome();
    assert_true(str_contains($home, 'Saved header') && str_contains($home, 'Hello &lt;script'), 'The real homepage shows with the saved header.');
});

test('website homepage shows a branded placeholder until a homepage is chosen', function (): void {
    $site = comet_site_test_setup();
    $auto = comet_site_test_get('/');
    assert_same(200, $auto['status']);
    assert_true(str_contains($auto['body'], 'Comet Site'));
    assert_true(str_contains($auto['body'], 'alt="CometCMS"'));
    assert_true(str_contains($auto['body'], 'noindex,nofollow'));
    assert_false(str_contains($auto['body'], '/blog/hello'), 'The placeholder does not list content.');
    assert_false(str_contains($auto['body'], 'c-site-header'), 'The placeholder is independent of the website layout.');
    assert_same(200, comet_site_test_get('/blog/hello')['status'], 'Frontend pages remain accessible.');

    $settings = $site->settings();
    $settings['homepage'] = ['type' => 'about', 'entry' => 'about'];
    $site->saveSettings($settings);

    $home = comet_site_test_get('/');
    assert_true(str_contains($home['body'], 'We build things.'));
    $about = comet_site_test_get('/about');
    assert_true(str_contains($about['body'], '<link rel="canonical" href="https://example.test/">'), 'The homepage entry is canonical at the root.');
});

test('unavailable homepage entries fall back without exposing unpublished content', function (): void {
    $site = comet_site_test_setup();
    foreach (['secret-draft', 'missing-entry'] as $entry) {
        $settings = $site->settings();
        $settings['homepage'] = ['type' => 'blog', 'entry' => $entry];
        $site->saveSettings($settings);
        $response = comet_site_test_get('/');
        assert_same(200, $response['status']);
        assert_true(str_contains($response['body'], 'alt="CometCMS"'));
        assert_false(str_contains($response['body'], 'Secret draft'));
    }
});

test('website routes localized entries under a locale prefix', function (): void {
    (new ContentTypeRepository())->save(['name' => 'pages', 'locales' => ['en', 'de'], 'default_locale' => 'en', 'fields' => ['intro' => ['type' => 'textarea']]]);
    $content = ContentRepository::make();
    $content->save('pages', ['title' => 'Welcome', 'slug' => 'welcome', 'intro' => 'English intro', 'status' => 'published', 'locale' => 'en'], comet_site_test_user());
    $content->save('pages', ['title' => 'Willkommen', 'slug' => 'welcome', 'intro' => 'Deutsche Einleitung', 'status' => 'published', 'locale' => 'de'], comet_site_test_user(), 'welcome');

    (new SiteRepository())->saveSettings(['enabled' => true, 'locales' => ['en', 'de'], 'default_locale' => 'en', 'routes' => ['pages' => ['enabled' => true]]]);

    $english = comet_site_test_get('/pages/welcome');
    $german = comet_site_test_get('/de/pages/welcome');

    assert_true(str_contains($english['body'], 'English intro'));
    assert_true(str_contains($german['body'], 'Deutsche Einleitung'));
    assert_true(str_contains($german['body'], '<html lang="de">'));
    assert_true(str_contains($german['body'], 'hreflang="en" href="https://example.test/pages/welcome"'));
    assert_true(str_contains($german['body'], 'hreflang="de" href="https://example.test/de/pages/welcome"'));
});

test('language flags follow the admin mapping, regional codes and only known files', function (): void {
    assert_same('gb', Flags::country('en'));
    assert_same('br', Flags::country('pt-BR'));
    assert_same('at', Flags::country('de-at'));
    assert_same('de', Flags::country('de-xx'), 'An unknown region falls back to the language.');
    assert_same('gb-wls', Flags::country('cy'));
    assert_same('', Flags::country('tlh'));
    assert_true(Flags::file('gb-wls') !== null);
    foreach (['../site', 'de/../de', 'zz', 'DE', 'de.svg', ''] as $country) {
        assert_null(Flags::file($country));
    }
});

test('language switcher shows flags beside or instead of the text and keeps every link named', function (): void {
    (new ContentTypeRepository())->save(['name' => 'pages', 'locales' => ['en', 'de-at', 'tlh'], 'default_locale' => 'en', 'fields' => []]);
    $site = new SiteRepository();
    $site->saveSettings(['enabled' => true, 'locales' => ['en', 'de-at', 'tlh'], 'default_locale' => 'en', 'routes' => ['pages' => ['enabled' => true]]]);

    foreach (['none' => 0, 'with_text' => 2, 'only' => 2] as $flag => $flags) {
        $switcher = LayoutSchema::node('language_switcher', ['flag' => $flag]);
        $layout = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$switcher])])])]);
        $html = (new NodeRenderer(comet_site_test_context($site)))->render($layout, ['alternates' => ['en' => '/', 'de-at' => '/de-at', 'tlh' => '/tlh']]);

        assert_same($flags, substr_count($html, 'class="c-flag"'));
        assert_same($flags > 0, str_contains($html, 'src="/_site/flags/at.svg"'));
        assert_same($flag === 'only', str_contains($html, 'c-languages-flags'));
        // Named by an aria-label when the flag stands alone, by its text otherwise.
        assert_same($flag === 'only' ? 2 : 0, substr_count($html, 'aria-label="') - 1);
        assert_same($flag === 'only' ? 1 : 3, substr_count($html, '<span>'), 'A language without a flag keeps its text.');
        assert_true(str_contains($html, '<span>TLH</span>'));
    }
});

test('website sitemap and robots list only public pages', function (): void {
    comet_site_test_setup();
    $sitemap = comet_site_test_get('/sitemap.xml');

    assert_same('application/xml; charset=utf-8', $sitemap['content_type']);
    assert_true(str_contains($sitemap['body'], '<loc>https://example.test/blog/hello</loc>'));
    assert_true(str_contains($sitemap['body'], '<loc>https://example.test/about</loc>'));
    assert_false(str_contains($sitemap['body'], 'secret-draft'));
    assert_false(str_contains($sitemap['body'], 'private-note'));
    assert_true(str_contains(comet_site_test_get('/robots.txt')['body'], 'Sitemap: https://example.test/sitemap.xml'));
});

test('hidden websites stay crawlable so search engines read their noindex', function (): void {
    comet_site_test_setup(settings: ['noindex' => true]);
    $robots = comet_site_test_get('/robots.txt')['body'];

    assert_false(str_contains($robots, "Disallow: /\n"));
    assert_true(str_contains($robots, 'Disallow: /admin'));
    assert_false(str_contains($robots, 'Sitemap:'), 'A hidden site does not advertise its pages.');
    assert_true(str_contains(comet_site_test_get('/blog/hello')['body'], '<meta name="robots" content="noindex,nofollow">'));
});

test('paginated lists keep their page in canonical URLs and end with a 404', function (): void {
    $site = comet_site_test_setup();
    $content = ContentRepository::make();
    foreach (['second', 'third'] as $slug) {
        $content->save('blog', ['title' => ucfirst($slug), 'slug' => $slug, 'status' => 'published'], comet_site_test_user());
    }
    $list = static fn(): array => [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [
        LayoutSchema::node('collection', ['type' => 'blog', 'paginate' => true, 'limit' => 1]),
    ])])])];
    $site->saveLayout('blog', 'index', $list());
    $site->saveLayout('about', 'detail', $list());

    foreach (['/blog', '/about'] as $path) {
        $last = comet_site_test_get($path, ['page' => 3]);
        assert_same(200, $last['status']);
        assert_true(str_contains($last['body'], '<link rel="canonical" href="https://example.test' . $path . '?page=3">'), $path);

        $beyond = comet_site_test_get($path, ['page' => 4]);
        assert_same(404, $beyond['status'], $path . ' has no fourth page.');
        assert_true(str_contains($beyond['body'], 'noindex'));
    }

    $plain = comet_site_test_get('/blog/hello', ['page' => 2]);
    assert_same(200, $plain['status'], 'A page without a paginated list ignores the page number.');
    assert_true(str_contains($plain['body'], '<link rel="canonical" href="https://example.test/blog/hello">'));
});

test('website answers for its domains or as the default workspace', function (): void {
    assert_null(SiteService::workspaceForHost('example.test'), 'No website until it has something to show.');

    comet_site_test_setup();
    assert_same('default', SiteService::workspaceForHost('anything.test')?->slug());

    $site = new SiteRepository();
    $settings = $site->settings();
    $settings['domains'] = ['https://www.example.test:443/path', 'bad domain'];
    $saved = $site->saveSettings($settings);

    assert_same(['www.example.test'], $saved['domains']);
    assert_same('default', SiteService::workspaceForHost('WWW.example.test:8080')?->slug());
    assert_null(SiteService::workspaceForHost('other.test'), 'A site limited to its domains ignores other hosts.');
});

test('saving website changes clears cached pages', function (): void {
    comet_site_test_setup();
    $cache = ApiCache::fromConfig();
    $key = $cache->key('site|example.test|/blog/hello', '');
    $cache->put($key, ['status' => 200, 'body' => 'cached', 'content_type' => 'text/html']);
    assert_true($cache->get($key) !== null);

    (new SiteRepository())->saveLayout('blog', 'detail', LayoutSchema::defaultLayout((new ContentTypeRepository())->find('blog'), 'detail'));

    assert_null($cache->get($key));
});

test('website status reports frontend pages and the derived live state', function (): void {
    $seed = <<<'PHP'
$users->create('editor', 'secret-password', 'editor');
$types = new \CometCMS\Content\ContentTypeRepository();
$types->save(['name' => 'pages']);
$types->save(['name' => 'data-only']);
$types->save(['name' => 'homepage-only']);
$types->save(['name' => 'notes', 'visibility' => 'private']);
(new \CometCMS\Site\SiteRepository())->saveSettings([
    'homepage' => ['type' => 'homepage-only'],
    'routes' => ['pages' => ['enabled' => true], 'notes' => ['enabled' => true]],
]);
PHP;

    [$status, $json] = comet_admin_request('editor', $seed, 'GET', '/admin/api/site/status',
        '(new \CometCMS\Controllers\Admin\SiteController(new \CometCMS\Core\Http()))->status();');

    assert_same(200, $status);
    assert_true($json['data']['enabled']);
    assert_false($json['data']['can_manage'], 'Editors can see the status without website management permission.');
    assert_true($json['data']['types']['pages']['pages_enabled']);
    assert_true($json['data']['types']['pages']['enabled']);
    assert_true($json['data']['types']['pages']['routed']);
    assert_true($json['data']['types']['homepage-only']['routed'], 'Homepage templates remain editable without enabling a separate URL.');
    assert_false($json['data']['types']['homepage-only']['pages_enabled']);
    assert_false($json['data']['types']['data-only']['pages_enabled']);
    assert_false($json['data']['types']['data-only']['routed']);
    assert_false($json['data']['types']['notes']['pages_enabled'], 'Private types cannot enable public pages.');
});

test('the website is live only while it has a homepage or frontend pages', function (): void {
    $types = new ContentTypeRepository();
    $types->save(['name' => 'pages']);
    $types->save(['name' => 'notes', 'visibility' => 'private']);
    $site = new SiteRepository();
    $live = static fn(array $settings): bool => (new SiteRepository())->saveSettings($settings)['enabled'];

    assert_false($site->settings()['enabled'], 'A new workspace is headless.');
    assert_false($live(['enabled' => true]), 'The former switch alone no longer publishes anything.');
    assert_null(SiteService::workspaceForHost('example.test'));
    assert_false($live(['routes' => ['notes' => ['enabled' => true]]]), 'Private types never make the website live.');

    assert_true($live(['homepage' => ['type' => 'pages']]), 'A homepage alone publishes the website.');
    assert_same('default', SiteService::workspaceForHost('example.test')?->slug());
    assert_true($live(['routes' => ['pages' => ['enabled' => true]]]), 'Frontend pages alone publish the website.');

    assert_false($live([]), 'Unsetting both returns to a fully headless setup.');
    assert_null(SiteService::workspaceForHost('example.test'));
    assert_false(array_key_exists('enabled', (new JsonStore(comet_test_workspace_path() . '/site'))->read('settings') ?? []), 'The derived state is never stored.');
});

test('layout previews annotate nodes and render unsaved changes', function (): void {
    comet_site_test_setup();
    $context = comet_site_test_context();
    $context->preview = true;
    $context->annotate = true;
    $context->override('layout:blog:detail:default', LayoutSchema::normalize([
        ['id' => 'sec1', 'kind' => 'section', 'children' => [
            ['id' => 'row1', 'kind' => 'row', 'children' => [
                ['id' => 'col1', 'kind' => 'column', 'children' => [
                    ['id' => 'h1', 'kind' => 'heading', 'props' => ['text' => 'Unsaved heading']],
                    ['id' => 'f1', 'kind' => 'field', 'props' => ['source' => \CometCMS\Site\SmartValue::reference('removed_field')]],
                ]],
            ]],
        ]],
    ]));

    $type = (new ContentTypeRepository())->find('blog');
    $html = (new PageRenderer($context))->layoutPreview($type, 'detail', $context->findEntry('blog', 'secret-draft', true));

    assert_true(str_contains($html, 'Unsaved heading'));
    assert_true(str_contains($html, 'data-node="h1"'));
    assert_true(str_contains($html, 'Missing field “removed_field”'));
    assert_true(str_contains($html, '<style>'), 'Previews inline the theme.');
    assert_true(str_contains($html, 'data-node="sec1"'));
});

test('layout previews of a draft resolve its publish date', function (): void {
    comet_site_test_setup();
    $context = comet_site_test_context();
    $context->preview = true;
    $context->annotate = true;
    $context->override('layout:blog:detail:default', LayoutSchema::normalize([
        ['id' => 'sec1', 'kind' => 'section', 'children' => [
            ['id' => 'row1', 'kind' => 'row', 'children' => [
                ['id' => 'col1', 'kind' => 'column', 'children' => [
                    ['id' => 'f1', 'kind' => 'field', 'props' => ['source' => \CometCMS\Site\SmartValue::reference('published_at')]],
                ]],
            ]],
        ]],
    ]));

    $type = (new ContentTypeRepository())->find('blog');
    $draft = $context->findEntry('blog', 'secret-draft', true);
    assert_true(empty($draft['published_at']), 'The fixture draft has no publish date yet.');
    $html = (new PageRenderer($context))->layoutPreview($type, 'detail', $draft);

    assert_false(str_contains($html, 'Unresolved content reference'));
    assert_true(str_contains($html, '<time'), 'The draft previews with the date it would get when published.');
});

test('theme stores custom css separately and reads direct stylesheet edits', function (): void {
    $site = comet_site_test_setup();
    $saved = $site->saveTheme(['colors' => ['primary' => '#123456'], 'custom_css' => '.custom { color: red; }']);
    $path = $site->workspace()->path('site') . '/theme/style.css';
    $tokens = (new JsonStore($site->workspace()->path('site') . '/theme'))->read('theme');
    assert_same($saved['custom_css'], file_get_contents($path));
    assert_false(array_key_exists('custom_css', $tokens));
    assert_same('#123456', $tokens['colors']['primary']);
    assert_same($saved, (new SiteRepository())->theme());
    $version = $site->customStylesheetVersion();
    $fullVersion = $site->stylesheetVersion();

    file_put_contents($path, '.edited { color: blue; }');
    assert_same('.edited { color: blue; }', $site->theme()['custom_css']);
    assert_false($version === $site->customStylesheetVersion());
    assert_false($fullVersion === $site->stylesheetVersion());
    file_put_contents($path, '');
    assert_same('', $site->theme()['custom_css'], 'An empty stylesheet clears custom CSS.');
});

test('retired combined themes do not override current theme files', function (): void {
    $site = comet_site_test_setup();
    $site->saveTheme(['custom_css' => '.current {}']);
    (new JsonStore($site->workspace()->path('site')))->write(['custom_css' => '.retired {}'], 'theme');
    assert_same('.current {}', $site->theme()['custom_css']);
});

test('custom stylesheet edits update cached page links while previews keep draft css inline', function (): void {
    $site = comet_site_test_setup();
    $site->saveTheme(['custom_css' => '.saved { color: red; }']);
    $context = comet_site_test_context($site);
    $service = new SiteService($context);
    $before = $service->respondCached('/blog/hello', []);
    assert_same('MISS', $before['cache_status']);
    assert_same('HIT', $service->respondCached('/blog/hello', [])['cache_status']);
    assert_true(strpos($before['body'], $context->stylesheetUrl()) < strpos($before['body'], $context->customStylesheetUrl()));
    assert_false(str_contains($before['body'], '.saved {'));

    file_put_contents($site->workspace()->path('site') . '/theme/style.css', '.edited {}');
    $after = $service->respondCached('/blog/hello', []);
    assert_same('MISS', $after['cache_status']);
    assert_false($before['body'] === $after['body']);
    assert_true(str_contains($after['body'], $context->customStylesheetUrl()));

    $context->preview = true;
    $context->override('theme', [...$site->theme(), 'custom_css' => '.draft { color: blue; }']);
    $preview = $service->respond('/blog/hello', [])['body'];
    assert_true(str_contains($preview, '.draft { color: blue; }'));
    assert_false(str_contains($preview, '/theme/style.css'));
    assert_same('.edited {}', $site->theme()['custom_css'], 'Previewing does not write draft CSS.');
});

test('theme normalizes values and keeps custom css inside its style element', function (): void {
    $theme = Theme::normalize([
        'colors' => ['primary' => '#ABC', 'text' => 'red; background:url(x)'],
        'fonts' => ['body' => 'comic-sans'],
        'base_size' => 99,
        'custom_css' => 'body{color:red}</style><script>alert(1)</script>',
    ]);

    assert_same('#aabbcc', $theme['colors']['primary']);
    assert_same(Theme::defaults()['colors']['text'], $theme['colors']['text']);
    assert_same('system', $theme['fonts']['body']);
    assert_same(24, $theme['base_size']);

    $css = Theme::stylesheet($theme);
    assert_true(str_contains($css, '--c-primary:#aabbcc;'));
    assert_true(str_contains($css, '--c-on-primary:'));
    assert_false(stripos($css, '</style') !== false);
    assert_true(str_contains($css, 'body{color:red}'));
});

test('shared heading settings normalize and become root variables', function (): void {
    $theme = Theme::normalize(['heading_line_height' => 9, 'heading_letter_spacing' => '0.05', 'heading_transform' => 'blink']);

    assert_same(2.2, $theme['heading_line_height']);
    assert_same(0.05, $theme['heading_letter_spacing']);
    assert_same('', $theme['heading_transform']);

    $css = Theme::stylesheet(['heading_line_height' => 1.1, 'heading_transform' => 'uppercase']);
    assert_true(str_contains($css, '--heading-line-height:1.1;--heading-letter-spacing:-0.01em;--heading-transform:uppercase;'));
});

test('heading levels normalize their overrides and only customized levels get rules', function (): void {
    $theme = Theme::normalize(['headings' => [
        'h1' => ['font' => 'comic', 'size' => '999', 'weight' => 450, 'line_height' => 5, 'letter_spacing' => -1, 'transform' => 'blink'],
        'h2' => ['font' => 'neo-grotesque', 'size' => 48, 'weight' => '800', 'line_height' => '1.05', 'letter_spacing' => 0.02, 'transform' => 'uppercase'],
        'h3' => ['size' => '', 'weight' => null, 'font' => '"x;}body{'],
        'h7' => ['size' => 20],
    ]]);

    assert_same(['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], array_keys($theme['headings']));
    assert_same(['font' => '', 'size' => 160, 'weight' => 500, 'line_height' => 2.2, 'letter_spacing' => -0.1, 'transform' => ''], $theme['headings']['h1']);
    assert_same(['font' => 'neo-grotesque', 'size' => 48, 'weight' => 800, 'line_height' => 1.05, 'letter_spacing' => 0.02, 'transform' => 'uppercase'], $theme['headings']['h2']);
    assert_same(Theme::headingDefaults(), $theme['headings']['h3'], 'Empty and invalid values inherit.');
    assert_same(Theme::defaults()['headings'], Theme::normalize(['headings' => 'invalid'])['headings']);

    $css = Theme::stylesheet(['custom_css' => '.after { color: red; }'] + $theme);
    $h2 = 'h2{font-family:' . Theme::FONT_STACKS['neo-grotesque'] . ';font-size:48px;font-weight:800;line-height:1.05;letter-spacing:0.02em;text-transform:uppercase;}';
    assert_true(str_contains($css, $h2));
    assert_true(str_contains($css, '@media (max-width: 767px){h1{font-size:128px;}h2{font-size:38px;}}'), 'Large custom sizes shrink on phones.');
    assert_false((bool) preg_match('/(^|})h3\{/', substr($css, strpos($css, '/* Headings */'))), 'Inheriting levels get no rule.');
    assert_true(strpos($css, Theme::baseStylesheet()) < strpos($css, $h2), 'Heading rules replace the base defaults.');
    assert_true(strpos($css, $h2) < strpos($css, '/* Custom CSS */'), 'Custom CSS can still override heading rules.');
    assert_false(str_contains(Theme::stylesheet([]), '/* Headings */'), 'Default themes add no heading rules.');
});

test('custom theme colors validate tokens and values and generate both palettes', function (): void {
    $theme = Theme::normalize([
        'mode' => 'auto',
        'custom_colors' => [
            'custom-brand' => ['label' => ' Brand ', 'color' => '#ABC', 'dark_color' => '#123456'],
            'custom-fallback' => ['label' => '<b>Fallback</b>', 'color' => '#ff0000', 'dark_color' => 'invalid'],
            'custom-invalid' => ['label' => 'Bad', 'color' => 'red; background:url(x)'],
            'custom-array' => ['color' => ['#ffffff']],
            'custom-</style><script>' => ['color' => '#ffffff'],
            'primary' => ['color' => '#ffffff'],
        ],
    ]);
    assert_same(['custom-brand', 'custom-fallback'], array_keys($theme['custom_colors']));
    assert_same(['label' => 'Brand', 'color' => '#aabbcc', 'dark_color' => '#123456'], $theme['custom_colors']['custom-brand']);
    assert_same('Fallback', $theme['custom_colors']['custom-fallback']['label']);
    assert_same('#ff0000', $theme['custom_colors']['custom-fallback']['dark_color']);
    assert_same([], Theme::normalize([])['custom_colors']);

    $css = Theme::stylesheet($theme);
    assert_true(str_contains($css, '--c-custom-brand:#aabbcc;'));
    assert_true(str_contains($css, '@media (prefers-color-scheme: dark){:root{'));
    assert_true(str_contains($css, '--c-custom-brand:#123456;'));
    assert_true(str_contains($css, '.c-bg-custom-brand{--c-fill:var(--c-custom-brand);background-color:var(--c-custom-brand);color:var(--c-on-custom-brand);}'));
    assert_true(str_contains($css, '.c-fg-custom-brand{color:var(--c-custom-brand);}'));
    assert_false(str_contains($css, '</style>'));
    assert_false(str_contains($css, 'background:url(x)'));
    assert_true(str_contains(Theme::stylesheet([...$theme, 'mode' => 'dark']), ':root{color-scheme:dark;'));

    $colors = [];
    for ($index = 0; $index < 70; $index++) {
        $colors['custom-color-' . $index] = ['label' => 'Color', 'color' => '#abcdef'];
    }
    assert_same(Theme::MAX_CUSTOM_COLORS, count(Theme::normalize(['custom_colors' => $colors])['custom_colors']));
});

test('saved custom colors keep their layout references when renamed or recolored', function (): void {
    $site = comet_site_test_setup();
    $site->saveTheme(['custom_colors' => ['custom-brand' => ['label' => 'Brand', 'color' => '#abcdef']]]);
    $layout = $site->saveLayout('about', 'detail', [LayoutSchema::node('section', [], ['background' => 'custom-brand', 'text_color' => 'border'])]);
    assert_same('custom-brand', $layout[0]['style']['background']);
    assert_same('border', $layout[0]['style']['text_color']);

    $site->saveTheme(['custom_colors' => ['custom-brand' => ['label' => 'Renamed', 'color' => '#fedcba']]]);
    $reloaded = new SiteRepository();
    assert_same('Renamed', $reloaded->theme()['custom_colors']['custom-brand']['label']);
    assert_same('custom-brand', $reloaded->layout('about', 'detail')[0]['style']['background']);
    assert_true(str_contains(Theme::stylesheet($reloaded->theme()), '--c-custom-brand:#fedcba;'));

    $invalid = LayoutSchema::normalize([LayoutSchema::node('section', [], ['background' => 'custom-evil; color:red', 'text_color' => ['primary']])])[0];
    assert_same([], $invalid['style']);
});

test('dividers use their text color for the line and preserve the default border color', function (): void {
    $site = comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context($site));
    foreach (['accent', 'custom-brand'] as $color) {
        $node = LayoutSchema::node('divider', [], ['text_color' => $color]);
        $html = $renderer->render([$node]);
        assert_true(str_contains($html, 'c-fg-' . $color));
        assert_true(str_contains($html, '<hr class="c-divider c-divider-colored">'));
    }
    assert_true(str_contains($renderer->render([LayoutSchema::node('divider')]), '<hr class="c-divider">'));
});

test('columns lay out their elements underneath each other or side by side', function (): void {
    $site = comet_site_test_setup();
    $renderer = new NodeRenderer(comet_site_test_context($site));
    $stacked = $renderer->render([LayoutSchema::node('column', [], [], [LayoutSchema::node('button')])]);
    assert_false(str_contains($stacked, 'c-flow-row'));

    $column = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [
        LayoutSchema::node('column', ['direction' => 'row', 'content_justify' => 'between', 'content_align' => 'end'], [], [LayoutSchema::node('button')]),
    ])])])[0]['children'][0]['children'][0];
    $html = $renderer->render([$column]);
    foreach (['c-flow-row', 'c-flow-justify-between', 'c-flow-align-end'] as $class) {
        assert_true(str_contains($html, $class));
    }

    $invalid = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [
        LayoutSchema::node('column', ['direction' => 'diagonal']),
    ])])])[0]['children'][0]['children'][0];
    assert_same('column', $invalid['props']['direction']);
});

test('deleting a content type removes its website layouts and route', function (): void {
    comet_site_test_setup();
    $site = new SiteRepository();
    $site->saveLayout('blog', 'index', []);
    assert_true($site->layoutCustomized('blog', 'index'));
    $store = new JsonStore(comet_test_workspace_path() . '/site');
    $theme = new JsonStore(comet_test_workspace_path() . '/site/theme');

    (new ContentTypeRepository())->delete('blog');

    assert_null($theme->read('content', 'blog', 'index', 'default'));
    assert_same([], (new JsonStore(comet_test_workspace_path() . '/revisions/site'))->all('content', 'blog', 'index', 'default'));
    assert_false(isset($store->read('settings')['routes']['blog']));
});

test('layout saves archive defaults and previous versions and restores can be reversed', function (): void {
    $site = comet_site_test_setup();
    $user = ['id' => 'andi', 'username' => 'Andi'];
    assert_same([], $site->layoutRevisions('about', 'detail'));
    $first = $site->saveLayout('about', 'detail', [LayoutSchema::node('section', [], ['background' => 'accent'])], $user);
    $defaultRevision = $site->layoutRevisions('about', 'detail')[0];
    assert_false($defaultRevision['customized']);
    assert_same('Andi', $defaultRevision['created_by_name']);
    assert_false(isset($defaultRevision['snapshot']), 'History lists contain metadata rather than every layout snapshot.');

    $second = $site->saveLayout('about', 'detail', [], $user);
    $revision = $site->layoutRevisions('about', 'detail')[0];
    assert_same(2, count($site->layoutRevisions('about', 'detail')));
    assert_true($revision['customized']);
    assert_same([], $second);
    $restored = $site->restoreLayoutRevision('about', 'detail', $revision['id'], $user);
    assert_same($first, $restored['nodes']);
    assert_true($restored['customized']);
    $undoRestore = $site->layoutRevisions('about', 'detail')[0];
    assert_same('restored', $undoRestore['event']);
    assert_same([], $site->restoreLayoutRevision('about', 'detail', $undoRestore['id'], $user)['nodes']);

    assert_false($site->restoreLayoutRevision('about', 'detail', $defaultRevision['id'], $user)['customized']);
    assert_false($site->layoutCustomized('about', 'detail'));
    assert_null($site->restoreLayoutRevision('about', 'detail', 'missing', $user));
    assert_same([], $site->layoutRevisions('blog', 'index'), 'History is scoped to the content type and variant.');
    assert_null($site->restoreLayoutRevision('blog', 'index', $revision['id'], $user));
    assert_throws(InvalidArgumentException::class, fn() => $site->restoreLayoutRevision('about', 'detail', '../theme', $user));
});

test('layout and region resets preserve the customized version for restoration', function (): void {
    $site = comet_site_test_setup();
    $layout = $site->saveLayout('about', 'detail', [], ['id' => 'andi']);
    $site->resetLayout('about', 'detail', ['id' => 'andi']);
    $revision = $site->layoutRevisions('about', 'detail')[0];
    assert_same('reset', $revision['event']);
    assert_same($layout, $site->restoreLayoutRevision('about', 'detail', $revision['id'])['nodes']);
    assert_true($site->layoutCustomized('about', 'detail'));

    $header = $site->saveRegion('header', [LayoutSchema::node('section')], ['id' => 'andi']);
    $defaultRevision = $site->regionRevisions('header')[0];
    $site->resetRegion('header', ['id' => 'andi']);
    $revision = $site->regionRevisions('header')[0];
    assert_same('reset', $revision['event']);
    assert_same($header, $site->restoreRegionRevision('header', $revision['id'])['nodes']);
    assert_true($site->regionCustomized('header'));
    assert_false($site->restoreRegionRevision('header', $defaultRevision['id'])['customized']);
    assert_false($site->regionCustomized('header'));
    assert_same([], $site->regionRevisions('footer'));
    assert_null($site->restoreRegionRevision('footer', $revision['id']));
});

test('theme revisions restore colors and css while preserving current css for non-admin restores', function (): void {
    $site = comet_site_test_setup();
    assert_same([], $site->themeRevisions());
    $first = $site->saveTheme(['colors' => ['primary' => '#123456'], 'custom_css' => '.old {color:red}'], ['id' => 'andi']);
    $site->saveTheme(['colors' => ['primary' => '#abcdef'], 'custom_css' => '.new {color:blue}']);
    $revision = $site->themeRevisions()[0];
    $theme = $site->restoreThemeRevision($revision['id'], ['id' => 'designer'], false);
    assert_same('#123456', $theme['colors']['primary']);
    assert_same('.new {color:blue}', $theme['custom_css']);
    $theme = $site->restoreThemeRevision($revision['id'], ['id' => 'andi'], true);
    assert_same($first['custom_css'], $theme['custom_css']);
    assert_same('restored', $site->themeRevisions()[0]['event']);
    $count = count($site->themeRevisions());
    assert_null($site->restoreThemeRevision('missing'));
    assert_same($count, count($site->themeRevisions()), 'Missing revisions must not change saved data or history.');
});

test('website revisions have configurable retention and survive reopening the repository', function (): void {
    global $cometConfig;
    $old = $cometConfig['site']['max_revisions'];
    $site = comet_site_test_setup();
    try {
        $cometConfig['site']['max_revisions'] = 2;
        $site->saveTheme(['base_size' => 14]);
        $site->saveTheme(['base_size' => 15]);
        $site->saveTheme(['base_size' => 16]);
        $reopened = new SiteRepository();
        $revisions = $reopened->themeRevisions();
        assert_same(2, count($revisions));
        $cometConfig['site']['max_revisions'] = -1;
        assert_same(15, $reopened->restoreThemeRevision($revisions[0]['id'])['base_size']);
        assert_same(14, $reopened->restoreThemeRevision($revisions[1]['id'])['base_size']);
        assert_same(4, count($reopened->themeRevisions()));
        $reopened->saveTheme(['base_size' => 17]);
        assert_same(5, count($reopened->themeRevisions()));
        $cometConfig['site']['max_revisions'] = 0;
        $reopened->saveTheme(['base_size' => 18]);
        assert_same([], $reopened->themeRevisions());
    } finally {
        $cometConfig['site']['max_revisions'] = $old;
    }
});

test('website revision history is isolated by workspace', function (): void {
    $one = comet_site_test_setup();
    $one->saveTheme(['base_size' => 15]);
    $revision = $one->themeRevisions()[0];
    $workspace = new \CometCMS\Workspaces\WorkspaceContext('other-site');
    $other = new SiteRepository($workspace);
    assert_same([], $other->themeRevisions());
    assert_null($other->restoreThemeRevision($revision['id']));
    assert_same(15, $one->theme()['base_size']);
});

test('the locked admin role always holds new built-in permissions', function (): void {
    $store = new JsonStore(COMET_STORAGE . '/roles');
    $store->write(['id' => 'admin', 'name' => 'admin', 'permissions' => [['effect' => 'allow', 'actions' => ['dashboard.read'], 'resources' => ['*']]]], 'admin');

    $permissions = new PermissionService(new RoleRepository());
    assert_true($permissions->allows(['id' => 'a', 'role' => 'admin'], 'site.manage', ['resource' => 'site:*']));
    assert_false($permissions->allows(['id' => 'e', 'role' => 'editor'], 'site.manage', ['resource' => 'site:*']));
});

test('background images and cropped images follow their size and focal point', function (): void {
    // Background images are stored in style.
    $nodes = LayoutSchema::normalize([
        ['kind' => 'section', 'style' => ['background_image' => 'x.jpg', 'background_size' => 'contain', 'background_position' => 'top-right', 'background_repeat' => true], 'children' => [
            ['kind' => 'row', 'children' => [['kind' => 'column', 'children' => [
                ['kind' => 'image', 'props' => ['media' => 'x.jpg', 'ratio' => '16:9', 'position' => 'bottom-left']],
                ['kind' => 'image', 'props' => ['position' => 'somewhere']],
            ]]]],
        ]],
    ]);

    $section = $nodes[0]['style'];
    assert_same(['x.jpg', 'contain', 'top-right', true], [$section['background_image'], $section['background_size'], $section['background_position'], $section['background_repeat']]);
    assert_same([], array_intersect(array_keys($nodes[0]['props']), ['background_image', 'background_size', 'background_position', 'background_repeat']));
    $images = $nodes[0]['children'][0]['children'][0]['children'];
    assert_same('bottom-left', $images[0]['props']['position']);
    assert_same('center', $images[1]['props']['position'], 'Unknown focal points fall back to the center.');

    $css = Theme::stylesheet([]);
    foreach (['.c-bg-size-contain', '.c-bg-pos-top-right', '.c-bg-repeat', '.c-obj-pos-bottom-left > img'] as $selector) {
        assert_true(str_contains($css, $selector), $selector . ' is styled.');
    }
});

test('column reversal persists independently per device and orders every column', function (): void {
    $site = comet_site_test_setup();
    $columns = array_map(static fn(string $text): array => LayoutSchema::node('column', [], [], [
        LayoutSchema::node('heading', ['text' => $text]),
    ]), ['First column', 'Second column', 'Third column', 'Fourth column']);

    foreach ([[false, false], [true, false], [false, true], [true, true]] as [$mobile, $tablet]) {
        $site->saveLayout('about', 'detail', [LayoutSchema::node('section', [], [], [
            LayoutSchema::node('row', ['reverse_mobile' => $mobile, 'reverse_tablet' => $tablet], [], $columns),
        ])]);
        $nodes = (new SiteRepository())->layout('about', 'detail');
        $props = $nodes[0]['children'][0]['props'];
        assert_same([$mobile, $tablet], [$props['reverse_mobile'], $props['reverse_tablet']]);

        foreach ([false, true] as $annotate) {
            $ctx = comet_site_test_context();
            $ctx->annotate = $annotate;
            $html = (new NodeRenderer($ctx))->render($nodes);
            assert_same($mobile, str_contains($html, 'c-reverse-mobile'));
            assert_same($tablet, str_contains($html, 'c-reverse-tablet'));
            assert_same($mobile || $tablet ? 4 : 0, substr_count($html, '--c-column-order:'));
            if ($mobile || $tablet) {
                foreach ([0, -1, -2, -3] as $order) {
                    assert_true(str_contains($html, '--c-column-order:' . $order . '"'));
                }
            }
            assert_true(strpos($html, 'First column') < strpos($html, 'Second column'), 'Reading order is preserved.');
            assert_true(strpos($html, 'Second column') < strpos($html, 'Third column'));
            assert_true(strpos($html, 'Third column') < strpos($html, 'Fourth column'));
        }
    }
});

test('section scroll behavior accepts only current sticky modes and only adds effects to sections that stay in view', function (): void {
    $site = comet_site_test_setup();
    $row = fn(): array => LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading')])]);

    foreach ([
        [['sticky' => true], 'none', []],
        [['sticky' => false], 'none', []],
        [['sticky' => 'sideways'], 'none', []],
        [['sticky' => 'reveal', 'scroll_effect' => ['shadow', 'glass', 'bogus']], 'reveal', ['c-sticky', 'c-sticky-reveal', 'c-scroll-shadow', 'c-scroll-glass']],
        [['overlap' => true, 'scroll_effect' => ['compact']], 'none', ['c-overlap', 'c-scroll-compact']],
        // A floating section can keep its background instead of starting transparent.
        [['sticky' => 'always', 'overlap' => true, 'overlap_background' => 'background'], 'always', ['c-sticky', 'c-overlap', 'c-overlap-opaque']],
        [['overlap' => true, 'overlap_background' => 'bogus'], 'none', ['c-overlap']],
        // A section that scrolls away has nothing to show its effects on.
        [['scroll_effect' => ['shadow']], 'none', []],
    ] as [$props, $sticky, $classes]) {
        $saved = $site->saveRegion('header', [['kind' => 'section', 'props' => $props, 'children' => [$row()]]]);
        $reloaded = (new SiteRepository())->region('header');
        assert_same($saved, $reloaded);
        assert_same($sticky, $reloaded[0]['props']['sticky']);
        assert_same(array_values(array_intersect(['shadow', 'glass', 'compact'], (array) ($props['scroll_effect'] ?? []))), $reloaded[0]['props']['scroll_effect']);

        $html = (new NodeRenderer(comet_site_test_context($site)))->render($reloaded);
        preg_match('/<section class="([^"]*)"/', $html, $match);
        $rendered = array_values(array_filter(explode(' ', $match[1]), static fn(string $class): bool => preg_match('/^c-(sticky|overlap|scroll-)/', $class) === 1));
        assert_same($classes, $rendered);
    }
});

test('background blur is bounded and saved with the section style', function (): void {
    $site = comet_site_test_setup();
    foreach ([[[], 0], [['background_blur' => -5], 0], [['background_blur' => 100], 40], [['background_blur' => 12], 12]] as [$style, $expected]) {
        $site->saveLayout('about', 'detail', [LayoutSchema::node('section', [], $style)]);
        $nodes = (new SiteRepository())->layout('about', 'detail');
        assert_same($expected, $nodes[0]['style']['background_blur'] ?? 0);
        assert_same($expected === 0, !array_key_exists('background_blur', $nodes[0]['style']), 'Defaults are not stored.');
    }
});

test('background blur renders a separate decorative layer for public images only', function (): void {
    comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/background.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20" fill="red"/></svg>');
    file_put_contents(comet_test_workspace_path() . '/media/document.txt', 'Not an image');

    foreach (['none', 'light', 'dark'] as $overlay) {
        foreach ([0, 8, 40] as $blur) {
            $nodes = [LayoutSchema::node('section', [], [
                'background_image' => 'background.svg', 'background_blur' => $blur,
                'background_size' => 'contain', 'background_position' => 'top-right',
                'background_repeat' => true, 'overlay' => $overlay, 'radius' => 'lg',
            ], [LayoutSchema::node('row', [], [], [
                LayoutSchema::node('column', [], [], [LayoutSchema::node('heading', ['text' => 'Sharp content'])]),
            ])])];

            foreach ([false, true] as $annotate) {
                $ctx = comet_site_test_context();
                $ctx->annotate = $annotate;
                $html = (new NodeRenderer($ctx))->render($nodes);
                assert_same($blur > 0, str_contains($html, 'class="c-background-layer" aria-hidden="true"'));
                assert_same($blur > 0, str_contains($html, '--c-background-blur:' . $blur . 'px'));
                assert_true(str_contains($html, 'c-bg-size-contain c-bg-pos-top-right c-bg-repeat'));
                assert_same($overlay !== 'none', str_contains($html, 'c-overlay-' . $overlay));
                assert_true(str_contains($html, 'Sharp content'));
                assert_false(str_contains($html, 'filter:'), 'The content never receives an inline blur filter.');
            }
        }
    }

    (new \CometCMS\Media\MediaRepository())->updateVisibility('background.svg', 'private');
    foreach (['', 'missing.svg', 'document.txt', 'background.svg'] as $file) {
        $ctx = comet_site_test_context();
        $ctx->annotate = true;
        $html = (new NodeRenderer($ctx))->render([LayoutSchema::node('section', [], ['background_image' => $file, 'background_blur' => 8])]);
        assert_false(str_contains($html, 'c-background-layer'), 'Missing, private and non-image backgrounds have no blur layer.');
        assert_false(str_contains($html, '--c-background-image'));
    }
});

test('background scroll effects move the image to its own layer', function (): void {
    comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/background.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20" fill="red"/></svg>');

    foreach ([[[], 'none'], [['background_effect' => 'fixed'], 'fixed'], [['background_effect' => 'parallax'], 'parallax'], [['background_effect' => 'zoom'], 'none']] as [$style, $expected]) {
        $nodes = LayoutSchema::normalize([LayoutSchema::node('section', [], ['background_image' => 'background.svg', ...$style])]);
        assert_same($expected, $nodes[0]['style']['background_effect'] ?? 'none');
        assert_same($expected === 'none', !array_key_exists('background_effect', $nodes[0]['style']), 'Defaults are not stored.');
    }

    foreach (['fixed', 'parallax'] as $effect) {
        foreach ([0, 8] as $blur) {
            $html = (new NodeRenderer(comet_site_test_context()))->render([LayoutSchema::node('column', [], ['background_image' => 'background.svg', 'background_effect' => $effect, 'background_blur' => $blur, 'radius' => 'lg'])]);
            assert_true(str_contains($html, 'c-has-bg-layer c-bg-effect-' . $effect));
            assert_same(1, substr_count($html, 'class="c-background-layer" aria-hidden="true"'));
            assert_true(str_contains($html, '--c-background-image:url('));
            assert_same($blur > 0, str_contains($html, '--c-background-blur:'), 'Unblurred layers carry no blur.');
            assert_false(str_contains($html, 'style="background-image:'), 'The element itself keeps no image.');
        }
    }

    $html = (new NodeRenderer(comet_site_test_context()))->render([LayoutSchema::node('section', [], ['background_effect' => 'parallax'])]);
    assert_false(str_contains($html, 'c-bg-effect-'), 'Effects need an image.');

    $css = Theme::stylesheet([]);
    assert_true(str_contains($css, '.c-bg-effect-fixed > .c-background-layer::before { position: fixed; }'));
    assert_true(str_contains($css, 'animation-timeline: --c-bg-parallax;'));
});

test('text shadows survive layout normalization and render in preview and public output', function (): void {
    comet_site_test_setup();
    foreach (['text', 'heading'] as $kind) {
        assert_true(in_array('text_shadow', LayoutSchema::spec()['kinds'][$kind]['style'], true));
        foreach (['', 'none', 'sm', 'md', 'lg', 'invalid'] as $shadow) {
            $nodes = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [
                LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [
                    LayoutSchema::node($kind, ['text' => 'Heading', 'html' => '<p>Text</p>'], ['text_shadow' => $shadow]),
                ])]),
            ])]);
            $style = $nodes[0]['children'][0]['children'][0]['children'][0]['style'];
            $expected = in_array($shadow, ['none', 'sm', 'md', 'lg'], true) ? $shadow : '';
            assert_same($expected, $style['text_shadow'] ?? '');
            assert_same($nodes, LayoutSchema::normalize($nodes));
            foreach ([false, true] as $annotate) {
                $ctx = comet_site_test_context();
                $ctx->annotate = $annotate;
                $html = (new NodeRenderer($ctx))->render($nodes);
                assert_same($expected !== '', str_contains($html, 'c-ts-'));
                if ($expected !== '') {
                    assert_true(str_contains($html, 'c-ts-' . $expected));
                }
            }
        }
    }
    assert_false(in_array('text_shadow', LayoutSchema::styleKeys('image'), true));
});

test('image elements support translated overrides with media metadata as defaults', function (): void {
    comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"></svg>');
    $media = new \CometCMS\Media\MediaRepository();
    $nodes = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [
        LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [
            LayoutSchema::node('image', ['media' => 'photo.svg']),
        ])]),
    ])]);
    $props = $nodes[0]['children'][0]['children'][0]['children'][0]['props'];
    assert_true(array_key_exists('alt', $props));
    assert_true(array_key_exists('caption', $props));
    $spec = LayoutSchema::spec()['kinds']['image']['props'];
    assert_true($spec['alt']['localized']);
    assert_true($spec['caption']['localized']);

    $media->updateMeta('photo.svg', 'Library "alt" & text', 'Library <title>');
    foreach ([false, true] as $annotate) {
        $ctx = comet_site_test_context();
        $ctx->annotate = $annotate;
        $html = (new NodeRenderer($ctx))->render($nodes);
        assert_true(str_contains($html, 'alt="Library &quot;alt&quot; &amp; text"'));
        assert_true(str_contains($html, '<figcaption>Library &lt;title&gt;</figcaption>'));
        assert_false(str_contains($html, 'Old alt'));
        assert_false(str_contains($html, 'Old caption'));
    }

    $media->updateMeta('photo.svg', 'Updated alt', 'Updated title');
    $html = (new NodeRenderer(comet_site_test_context()))->render($nodes);
    assert_true(str_contains($html, 'alt="Updated alt"'));
    assert_true(str_contains($html, '<figcaption>Updated title</figcaption>'));

    $media->updateMeta('photo.svg', '', '');
    $html = (new NodeRenderer(comet_site_test_context()))->render($nodes);
    assert_true(str_contains($html, 'alt=""'));
    assert_false(str_contains($html, '<figcaption>'));
});

test('image elements are rendered within their width bounds', function (): void {
    comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20" fill="red"/></svg>');
    $render = static function (array $props, array $style = []): string {
        $nodes = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [
            LayoutSchema::node('column', [], [], [LayoutSchema::node('image', ['media' => 'photo.svg', ...$props], $style)]),
        ])])]);

        return (new NodeRenderer(comet_site_test_context()))->render($nodes);
    };

    $html = $render([]);
    assert_false(str_contains($html, 'c-img-sized'), 'Images without bounds keep their natural size.');
    assert_false(str_contains($html, '--c-img-'));

    $html = $render(['min_width' => 200, 'max_width' => 480], ['background_image' => 'photo.svg']);
    assert_true(str_contains($html, 'c-img-sized'));
    assert_true(str_contains($html, '--c-img-min:200px;--c-img-max:480px'));
    assert_true(str_contains($html, 'background-image:'), 'Width bounds join the element\'s other inline styles.');

    assert_true(str_contains($render(['min_width' => 900, 'max_width' => 300]), '--c-img-min:300px;--c-img-max:300px'), 'A minimum above the maximum gives one exact width.');
    assert_true(str_contains($render(['min_width' => 320]), 'style="--c-img-min:320px"'));

    $nodes = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [
        LayoutSchema::node('column', [], [], [LayoutSchema::node('image', ['min_width' => -5, 'max_width' => 999999])]),
    ])])]);
    $props = $nodes[0]['children'][0]['children'][0]['children'][0]['props'];
    assert_same([0, LayoutSchema::MAX_IMAGE_WIDTH], [$props['min_width'], $props['max_width']]);
});

test('rows, columns and elements take a background image over their background color', function (): void {
    comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/texture.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20" fill="red"/></svg>');
    $background = ['background' => 'surface', 'background_image' => 'texture.svg', 'overlay' => 'dark', 'background_blur' => 4];
    $nodes = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], ['background_image' => 'texture.svg', 'radius' => 'lg', 'shadow' => 'md', 'border' => ['left', 'top', 'right', 'bottom']], [
        LayoutSchema::node('column', [], $background, [LayoutSchema::node('heading', ['text' => 'Column'])]),
        LayoutSchema::node('column', [], [], [LayoutSchema::node('text', ['html' => '<p>Element</p>'], $background)]),
    ])])]);

    assert_same('texture.svg', $nodes[0]['children'][0]['style']['background_image'], 'Rows keep their background image.');
    $columnStyle = $nodes[0]['children'][0]['children'][0]['style'];
    ksort($columnStyle);
    ksort($background);
    assert_same($background, $columnStyle);

    $html = (new NodeRenderer(comet_site_test_context()))->render($nodes);
    assert_same(2, substr_count($html, 'class="c-background-layer" aria-hidden="true"'));
    assert_same(3, substr_count($html, 'c-has-bg-image'));
    assert_true((bool) preg_match('/class="c-row [^"]*c-has-bg-image[^"]*c-r-lg c-sh-md c-bd c-bd-left c-bd-top c-bd-right c-bd-bottom"/', $html), 'Rows render their image, radius, shadow and border.');
    assert_true((bool) preg_match('/class="c-col c-col-12 c-has-bg-image[^"]*c-overlay-dark[^"]*c-bg-surface"/', $html), 'Columns keep their color under the image.');
    assert_true((bool) preg_match('/class="c-el c-el-text c-has-bg-image[^"]*c-bg-surface"/', $html));
    assert_true(str_contains(Theme::stylesheet([]), ':where(.c-has-bg-image) { position: relative; }'), 'Overlay and blur layers are anchored outside sections too.');
});

test('borders pick their sides and only keep a look while a side is set', function (): void {
    $nodes = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [
        LayoutSchema::node('text', [], ['border' => ['bottom', 'left', 'diagonal'], 'border_width' => 9, 'border_style' => 'dashed', 'border_color' => 'custom-brand']),
        LayoutSchema::node('text', [], ['border' => [], 'border_width' => 3, 'border_style' => 'dotted', 'border_color' => 'primary']),
        LayoutSchema::node('text', [], ['border' => ['top']]),
        LayoutSchema::node('text', [], ['border' => 'none']),
    ])])])]);
    $elements = $nodes[0]['children'][0]['children'][0]['children'];

    assert_same(['border' => ['left', 'bottom'], 'border_width' => 6, 'border_style' => 'dashed', 'border_color' => 'custom-brand'], $elements[0]['style'], 'Sides keep their order and the width is clamped.');
    assert_same([], $elements[1]['style'], 'Without a side there is nothing to style.');
    assert_same(['border' => ['top']], $elements[2]['style'], 'A list of border sides is preserved.');
    assert_same([], $elements[3]['style']);

    $html = (new NodeRenderer(comet_site_test_context()))->render([$elements[0]]);
    assert_true(str_contains($html, 'c-bd c-bd-left c-bd-bottom c-bdw-6 c-bds-dashed c-bdc-custom-brand'));
    $css = Theme::stylesheet(['custom_colors' => ['custom-brand' => ['color' => '#ff0000']]]);
    assert_true(str_contains($css, '.c-bd.c-bdc-custom-brand{--c-bd-c:var(--c-custom-brand);}'), 'Custom colors color borders too.');
});

test('entrance animations keep their timing only while an animation is set', function (): void {
    $nodes = LayoutSchema::normalize([LayoutSchema::node('section', [], ['animation' => 'fade-up', 'animation_trigger' => 'load', 'animation_duration' => 'slow', 'animation_delay' => 9], [
        LayoutSchema::node('row', [], ['animation' => 'spin', 'animation_delay' => 2], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading', ['text' => 'Hello'])])]),
        LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading', ['text' => 'Hover'])])]) + ['states' => ['hover' => ['style' => ['animation' => 'zoom-in', 'animation_duration' => 'fast', 'animation_delay' => 2]]]],
        LayoutSchema::node('row', [], ['animation_trigger' => 'hover']),
    ])]);

    assert_same(['animation' => 'fade-up', 'animation_trigger' => 'load', 'animation_duration' => 'slow', 'animation_delay' => 6], $nodes[0]['style'], 'Delay steps are clamped.');
    assert_same([], $nodes[0]['children'][0]['style'], 'Unknown animations and their orphaned timing are dropped.');
    assert_same([], $nodes[0]['children'][1]['style']);
    assert_same(['animation' => 'zoom-in', 'animation_duration' => 'fast', 'animation_delay' => 2], $nodes[0]['children'][1]['states']['hover']['style'], 'Hover settings keep their own state.');
    assert_same([], $nodes[0]['children'][2]['style'], 'Hover is dropped without an animation.');

    $html = (new NodeRenderer(comet_site_test_context()))->render($nodes);
    assert_true(str_contains($html, 'c-anim c-anim-fade-up c-anim-load c-anim-slow c-anim-d-6'));
    assert_true(str_contains($html, 'c-hover-anim c-hover-anim-zoom-in c-hover-anim-fast c-hover-anim-d-2'));
    assert_true(str_contains(Theme::stylesheet([]), '@keyframes c-anim-fade-up'));
});

test('theme animation speeds and stagger are clamped and become stylesheet variables', function (): void {
    $theme = Theme::normalize(['motion' => ['fast' => 20, 'normal' => 833, 'slow' => 9000, 'stagger' => 'slow', 'hover' => 4000]]);
    assert_same(['fast' => 100, 'normal' => 850, 'slow' => 5000, 'stagger' => 120, 'hover' => 1000], $theme['motion']);
    assert_same(200, Theme::normalize([])['motion']['hover']);

    $css = Theme::stylesheet($theme);
    assert_true(str_contains($css, '--motion-normal:850;--motion-slow:5000;--motion-stagger:120;--motion-hover:1000;'));
    // Hover state changes follow the theme's hover transition.
    assert_true(str_contains($css, '.c-stateful{transition-property:background-color,color,border-color,box-shadow,text-shadow,opacity;transition-duration:calc(var(--motion-hover,200) * 1ms);'));
});

test('theme link settings are palette tokens and fixed choices', function (): void {
    assert_same(['color' => 'primary', 'hover_color' => '', 'decoration' => 'underline', 'thickness' => 0, 'offset' => 0.18], Theme::normalize([])['links']);
    $css = Theme::stylesheet([]);
    assert_true(str_contains($css, '--link-color:var(--c-primary);--link-decoration:underline;--link-hover-decoration:underline;--link-thickness:auto;--link-offset:0.18em;'));
    assert_false(str_contains($css, '--link-hover-color:'), 'Without a hover color, links keep their color.');

    $theme = Theme::normalize([
        'custom_colors' => ['custom-brand' => ['label' => 'Brand', 'color' => '#ff0000']],
        'links' => ['color' => 'custom-brand', 'hover_color' => 'accent', 'decoration' => 'none', 'thickness' => 9, 'offset' => 0.333],
    ]);
    assert_same(['color' => 'custom-brand', 'hover_color' => 'accent', 'decoration' => 'none', 'thickness' => 6, 'offset' => 0.33], $theme['links']);
    $css = Theme::stylesheet($theme);
    assert_true(str_contains($css, '--link-color:var(--c-custom-brand);--link-decoration:none;--link-hover-decoration:none;--link-thickness:6px;--link-offset:0.33em;--link-hover-color:var(--c-accent);'));

    // Unknown or removed colors and values fall back.
    $links = Theme::normalize(['links' => ['color' => 'custom-gone', 'hover_color' => 'red;}', 'decoration' => 'blink', 'thickness' => 'x', 'offset' => -1]])['links'];
    assert_same(['color' => 'primary', 'hover_color' => '', 'decoration' => 'underline', 'thickness' => 0, 'offset' => 0.0], $links);

    // Themes saved with the old switch keep their look: links always underlined on hover.
    assert_same('hover', Theme::normalize(['link_underline' => false])['links']['decoration']);
    assert_same('underline', Theme::normalize(['link_underline' => true])['links']['decoration']);
    assert_same('none', Theme::normalize(['link_underline' => true, 'links' => ['decoration' => 'none']])['links']['decoration']);
    assert_false(array_key_exists('link_underline', Theme::normalize(['link_underline' => false])));
});

test('theme spacing and radius tokens drive the stylesheet', function (): void {
    $theme = Theme::normalize([
        'space' => ['xs' => 2, 'md' => 24, 'xl' => 999],
        'radii' => ['sm' => 3, 'lg' => 99],
        'radius' => 'lg',
    ]);

    assert_same(['xs' => 2, 'sm' => 8, 'md' => 24, 'lg' => 32, 'xl' => 400, '2xl' => 96], $theme['space']);
    assert_same(['sm' => 3, 'md' => 8, 'lg' => 64], $theme['radii']);

    $css = Theme::stylesheet($theme);
    assert_true(str_contains($css, '--space-md:1.5rem;'), 'Spacing tokens become rem values.');
    assert_true(str_contains($css, '--radius-sm:3px;'));
    assert_true(str_contains($css, '--radius:var(--radius-lg);'), 'The default corner points at a token.');
    assert_true(str_contains($css, '.c-r-md { border-radius: var(--radius-md); }'));
    assert_true(str_contains(Theme::stylesheet(['radius' => 'full']), '--radius-box:var(--radius-lg);'), 'Round corners do not turn images into pills.');

    // Themes saved before tokens existed keep their look.
    $legacy = Theme::normalize(['spacing' => 'relaxed', 'radius' => 'xl']);
    assert_same(Theme::defaults()['space']['lg'], $legacy['space']['lg'], 'Density presets are retired.');
    assert_same(Theme::defaults()['radius'], $legacy['radius']);
    assert_false(array_key_exists('spacing', $legacy));
});

test('website status opens the homepage editor for selected entries and lists', function (): void {
    $seed = <<<'PHP'
$types = new \CometCMS\Content\ContentTypeRepository();
$types->save(['name' => 'pages']);
$content = \CometCMS\Content\ContentRepository::make();
$entry = $content->save('pages', ['title' => 'Welcome', 'slug' => 'welcome', 'status' => 'published'], ['id' => 'admin']);
$site = new \CometCMS\Site\SiteRepository();
PHP;
    foreach ([
        ["['type' => '', 'entry' => '']", null],
        // The builder names the entry by its stable ID, whichever way the setting does.
        ["['type' => 'pages', 'entry' => 'welcome']", '#^/page-builder/builder/layout/pages/detail\\?entry=(?!welcome$)[A-Za-z0-9_-]+$#'],
        ["['type' => 'pages', 'entry' => \$content->stableId(\$entry)]", '#^/page-builder/builder/layout/pages/detail\\?entry=(?!welcome$)[A-Za-z0-9_-]+$#'],
        ["['type' => 'pages', 'entry' => '']", '#^/page-builder/builder/layout/pages/index$#'],
        ["['type' => 'pages', 'entry' => 'deleted']", null],
    ] as [$homepage, $expected]) {
        [$status, $json] = comet_admin_request('admin',
            $seed . "\n\$site->saveSettings(['homepage' => " . $homepage . ", 'routes' => ['pages' => ['enabled' => true]]]);",
            'GET', '/admin/api/site/status',
            '(new \CometCMS\Controllers\Admin\SiteController(new \CometCMS\Core\Http()))->status();');
        assert_same(200, $status);
        assert_true($json['data']['can_manage']);
        if ($expected === null) assert_null($json['data']['homepage_editor']);
        else assert_matches($expected, (string) $json['data']['homepage_editor']);
    }
});

test('hover animations are independent, validated and restricted to hover', function (): void {
    $section = LayoutSchema::node('section', [], ['animation' => 'fade-up', 'animation_trigger' => 'load', 'animation_duration' => 'slow', 'animation_delay' => 1], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading', ['text' => 'Animated'])])])]);
    $section['states'] = [
        'hover' => ['style' => ['animation' => 'zoom-in', 'animation_duration' => 'fast', 'animation_delay' => 99, 'animation_trigger' => 'load', 'background' => 'primary']],
        'focus' => ['style' => ['animation' => 'blur', 'animation_duration' => 'slow', 'animation_delay' => 2, 'background' => 'accent']],
    ];
    $nodes = LayoutSchema::normalize([$section]);
    assert_same(['background' => 'primary', 'animation' => 'zoom-in', 'animation_duration' => 'fast', 'animation_delay' => 6], $nodes[0]['states']['hover']['style']);
    assert_same(['background' => 'accent'], $nodes[0]['states']['focus']['style']);
    assert_same($section['style'], $nodes[0]['style']);
    assert_same($nodes, LayoutSchema::normalize($nodes), 'Save/reload is stable.');
    $html = (new NodeRenderer(comet_site_test_context()))->render($nodes);
    assert_true(str_contains($html, 'c-anim c-anim-fade-up c-anim-load c-anim-slow c-anim-d-1'), $html);
    assert_true(str_contains($html, 'c-hover-anim c-hover-anim-zoom-in c-hover-anim-fast c-hover-anim-d-6'), $html);
    $spec = LayoutSchema::spec();
    assert_same(['scroll', 'load'], $spec['style']['animation_trigger']['options']);
    assert_same(LayoutSchema::HOVER_ANIMATION_KEYS, $spec['kinds']['section']['states']['variants']['hover']['style']);
    assert_false(in_array('animation', $spec['kinds']['section']['states']['style'], true));

    foreach (['none', 'spin', null] as $effect) {
        $section['states']['hover']['style'] = ['animation' => $effect, 'animation_duration' => 'slow', 'animation_delay' => 3, 'opacity' => '75'];
        $node = LayoutSchema::normalize([$section])[0];
        assert_false(isset($node['states']['hover']['style']['animation']));
        assert_false(isset($node['states']['hover']['style']['animation_duration']));
        assert_false(isset($node['states']['hover']['style']['animation_delay']));
    }
    $section['states']['hover']['style'] = ['animation' => 'blur', 'animation_duration' => 'invalid', 'animation_delay' => -5];
    assert_same(['animation' => 'blur'], LayoutSchema::normalize([$section])[0]['states']['hover']['style']);
});
