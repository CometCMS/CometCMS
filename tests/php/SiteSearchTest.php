<?php

declare(strict_types=1);

use CometCMS\Content\ContentRepository;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\SiteSearch;

function comet_site_search_page(array $search): array
{
    return [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$search])])])];
}

test('search elements keep known content types and fields and bound their options', function (): void {
    $column = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [
        LayoutSchema::node('search', [
            'collections' => ['blog', 'bad key!', 'blog', 7, ['x'], 'about'],
            'fields' => 'title',
            'max_results' => 50,
            'min_chars' => 0,
            'icon' => 'mdi:not-an-icon',
            'variant' => 'neon',
            'placeholder' => "Find\nposts",
        ]),
    ])], 'column');
    $props = $column[0]['children'][0]['props'];

    assert_same(['blog', '7', 'about'], $props['collections']);
    assert_same([], $props['fields']);
    assert_same(SiteSearch::MAX_RESULTS, $props['max_results']);
    assert_same(2, $props['min_chars']);
    assert_same('mdi:magnify', $props['icon']);
    assert_same('outline', $props['variant']);
    assert_same('Find posts', $props['placeholder']);
    assert_true(in_array('search', LayoutSchema::spec()['elements'], true));
    assert_true(LayoutSchema::spec()['kinds']['search']['props']['placeholder']['localized']);
    assert_same($column, LayoutSchema::normalizeComponent($column, 'column'));
});

test('site search finds published pages of public routed types in the chosen fields', function (): void {
    $site = comet_site_test_setup();
    $search = new SiteSearch(comet_site_test_context($site));
    $titles = static fn(array $hits): array => array_column($hits, 'title');

    $hits = $search->search('HELLO');
    assert_same(1, count($hits));
    assert_same(['title' => 'Hello <script>alert(1)</script>', 'url' => '/blog/hello', 'type' => 'Blog'], $hits[0]);
    assert_same([], $search->search('secret'), 'Drafts are not found.');
    assert_same([], $search->search('private'), 'Private types are not found.');
    assert_same([], $search->search('ada'), 'Types without pages are not found.');
    assert_same([], $search->search('   '));
    assert_same([], $search->search('h'), 'Single characters are too broad to search.');

    assert_same(['Hello <script>alert(1)</script>'], $titles($search->search('bold')), 'Text fields are searched.');
    assert_same([], $search->search('bold', [], ['title']));
    assert_same(['Hello <script>alert(1)</script>'], $titles($search->search('tricks', ['blog'], ['category'])), 'Select fields match their labels.');
    assert_same([], $search->search('<script>', [], ['body']), 'Markup is not searched.');
    assert_same(['About us'], $titles($search->search('build', ['about'])));
    assert_same([], $search->search('build', ['blog']));
    assert_same([], $search->search('hello', ['notes', 'authors', 'missing']));

    assert_same(['blog', 'about'], array_keys($search->targets()));
    assert_same(['title', 'body', 'category', 'slug'], array_keys($search->targets(['blog'])['blog']));
    assert_same(['about' => ['intro' => ['type' => 'textarea']]], $search->targets([], ['intro']));
});

test('site search ranks title matches first and returns at most seven hits', function (): void {
    $site = comet_site_test_setup();
    $content = ContentRepository::make();
    foreach (['Rebuilding', 'How we build', 'Build log'] as $index => $title) {
        $content->save('about', ['title' => $title, 'slug' => 'b' . $index, 'status' => 'published'], comet_site_test_user());
    }
    for ($i = 1; $i <= 9; $i++) {
        $content->save('about', ['title' => 'Item ' . $i, 'slug' => 'item-' . $i, 'status' => 'published'], comet_site_test_user());
    }
    $search = new SiteSearch(comet_site_test_context($site));

    assert_same(['Build log', 'How we build', 'Rebuilding', 'About us'], array_column($search->search('build'), 'title'));
    assert_same(7, count($search->search('item')));
    assert_same(3, count($search->search('item', [], [], 3)));
    assert_same(7, count($search->search('item', [], [], 99)));
    assert_same(1, count($search->search('item', [], [], 0)));
});

test('search markup is an escaped accessible combobox that says what to search', function (): void {
    $site = comet_site_test_setup();
    $ctx = comet_site_test_context($site);
    $renderer = new NodeRenderer($ctx);
    $node = LayoutSchema::node('search', ['placeholder' => 'Find <posts>', 'collections' => ['blog', 'about'], 'fields' => ['title', 'body'], 'max_results' => 5, 'size' => 'lg', 'variant' => 'filled']);
    $html = $renderer->render([$node, $node]);

    assert_true(str_contains($html, 'placeholder="Find &lt;posts&gt;"'));
    assert_true(str_contains($html, 'class="c-search c-search-lg c-search-filled c-search-icon-left" role="search"'));
    assert_true(str_contains($html, 'data-search="/_site/search/default"'));
    assert_true(str_contains($html, 'data-search-types="blog,about" data-search-fields="title,body" data-search-limit="5" data-search-min="2"'));
    assert_true(str_contains($html, 'data-search-show-type'));
    assert_true(str_contains($html, 'role="combobox" aria-autocomplete="list" aria-expanded="false"'));
    assert_same(2, substr_count($html, '<svg class="c-search-icon"'));
    assert_false(str_contains($html, 'readonly'));
    preg_match_all('/\bid="([^"]+)"/', $html, $ids);
    assert_same(count($ids[1]), count(array_unique($ids[1])));
    preg_match_all('/aria-controls="([^"]+)"/', $html, $controls);
    foreach ($controls[1] as $id) assert_true(in_array($id, $ids[1], true));

    $plain = $renderer->render([LayoutSchema::node('search', ['icon_position' => 'none', 'show_type' => false])]);
    assert_true(str_contains($plain, 'placeholder="Search…"'));
    assert_true(str_contains($plain, 'data-search-empty="No results found"'));
    assert_false(str_contains($plain, 'data-search-types'));
    assert_false(str_contains($plain, 'data-search-show-type'));
    assert_false(str_contains($plain, '<svg'));

    $ctx->annotate = true;
    $builder = (new NodeRenderer($ctx))->render([LayoutSchema::node('search', ['collections' => ['authors']])]);
    assert_true(str_contains($builder, 'Nothing to search'));
    assert_true(str_contains($builder, 'readonly'));
    assert_false(str_contains((new NodeRenderer($ctx))->render([$node]), 'Nothing to search'));
});

test('search pages load the hashed search module and previews stay script free', function (): void {
    $site = comet_site_test_setup();
    $site->saveLayout('blog', 'detail', comet_site_search_page(LayoutSchema::node('search')));
    $ctx = comet_site_test_context($site);
    $renderer = new PageRenderer($ctx);
    $html = $renderer->entryPage($ctx->type('blog'), $ctx->findEntry('blog', 'hello'));

    assert_same(1, substr_count($html, '<script type="module">' . SiteSearch::script() . '</script>'));
    assert_same("'sha256-" . base64_encode(hash('sha256', SiteSearch::script(), true)) . "'", SiteSearch::cspSource());
    $ctx->preview = true;
    $preview = $renderer->layoutPreview($ctx->type('blog'), 'detail', $ctx->findEntry('blog', 'hello'));
    assert_false(str_contains($preview, '<script'));
    assert_true(str_contains($preview, 'data-search='));
});

test('the public search endpoint answers with hits in root and subdirectory installs', function (): void {
    $root = comet_site_preview_test_install();
    comet_test_run_php(['-r', 'require ' . var_export($root . '/app/bootstrap.php', true) . ';' . <<<'PHP'
(new \CometCMS\Workspaces\WorkspaceRepository())->all();
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'about', 'label' => 'Pages', 'fields' => ['intro' => ['type' => 'textarea']]]);
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'notes', 'visibility' => 'private']);
\CometCMS\Content\ContentRepository::make()->save('about', ['title' => 'About', 'intro' => 'Our story', 'status' => 'published'], ['id' => 'admin']);
\CometCMS\Content\ContentRepository::make()->save('notes', ['title' => 'About secrets', 'status' => 'published'], ['id' => 'admin']);
$site = new \CometCMS\Site\SiteRepository();
$site->saveSettings(['enabled' => true, 'routes' => ['about' => ['enabled' => true, 'pattern' => '/{slug}', 'index_path' => '']]]);
$search = \CometCMS\Site\LayoutSchema::node('search');
$site->saveLayout('about', 'detail', [\CometCMS\Site\LayoutSchema::node('section', [], [], [\CometCMS\Site\LayoutSchema::node('row', [], [], [\CometCMS\Site\LayoutSchema::node('column', [], [], [$search])])])]);
PHP]);
    $port = 28080 + random_int(0, 2000);
    $server = comet_test_start_php_server('127.0.0.1', $port, $root . '/router.php');
    try {
        usleep(300000);
        foreach (['', '/cms'] as $base) {
            [$status, $body, $headers] = comet_site_preview_test_request($port, $base . '/_site/search/default?q=about&types=about,notes');
            assert_same(200, $status, $body);
            assert_same(['data' => [['title' => 'About', 'url' => $base . '/about', 'type' => 'Pages']]], json_decode($body, true));
            assert_same('public, max-age=60', $headers['cache-control']);

            [, $body] = comet_site_preview_test_request($port, $base . '/_site/search/default?q=story&fields=title');
            assert_same(['data' => []], json_decode($body, true));
            [, $body] = comet_site_preview_test_request($port, $base . '/_site/search/default?q=story&fields=intro');
            assert_same('About', json_decode($body, true)['data'][0]['title']);

            [$status, $html, $headers] = comet_site_preview_test_request($port, $base . '/about');
            assert_same(200, $status);
            assert_true(str_contains($html, 'data-search="' . $base . '/_site/search/default"'));
            assert_true(str_contains($headers['content-security-policy'], SiteSearch::cspSource()));
        }
        assert_same(404, comet_site_preview_test_request($port, '/_site/search/missing?q=about')[0]);
    } finally {
        comet_test_stop_process($server);
    }
});

test('site search pages through every match for the results page', function (): void {
    $site = comet_site_test_setup();
    $content = ContentRepository::make();
    for ($i = 1; $i <= 12; $i++) {
        $content->save('about', ['title' => 'Item ' . $i, 'slug' => 'item-' . $i, 'status' => 'published'], comet_site_test_user());
    }
    $search = new SiteSearch(comet_site_test_context($site));

    $first = $search->page('item', [], [], 5, 0);
    assert_same(12, $first['total']);
    assert_same(5, count($first['data']));
    assert_same('about', $first['data'][0]['type']);
    assert_same(2, count($search->page('item', [], [], 5, 10)['data']));
    assert_same(['data' => [], 'total' => 0], $search->page('i', [], [], 5, 0));
    assert_same('', SiteSearch::normalizeQuery(' x '));
    assert_same('abc', SiteSearch::normalizeQuery(' abc '));
    assert_same('', SiteSearch::normalizeQuery(['abc']));
});

test('the search results page lists the hits of its query with paging and an empty state', function (): void {
    $site = comet_site_test_setup();
    $content = ContentRepository::make();
    for ($i = 1; $i <= 12; $i++) {
        $content->save('about', ['title' => 'Item ' . $i, 'slug' => 'item-' . $i, 'status' => 'published'], comet_site_test_user());
    }
    assert_true(in_array(LayoutSchema::SEARCH_RESULTS, LayoutSchema::SPECIAL_PAGES, true));

    $response = comet_site_test_get('/search', ['q' => 'item']);
    assert_same(200, $response['status']);
    assert_same(10, substr_count($response['body'], '<article class="c-card">'));
    assert_true(str_contains($response['body'], 'href="/search?q=item&amp;page=2"'));
    assert_true(str_contains($response['body'], 'noindex'));
    assert_true(str_contains($response['body'], '<form class="c-search'));
    assert_true(str_contains($response['body'], 'action="/search" method="get"'));
    assert_true(str_contains($response['body'], 'name="q" value="item"'));
    assert_true(str_contains($response['body'], 'data-search-page="/search"'));

    assert_same(2, substr_count(comet_site_test_get('/search', ['q' => 'item', 'page' => '2'])['body'], '<article class="c-card">'));
    assert_true(str_contains(comet_site_test_get('/search', ['q' => 'nothing here'])['body'], 'No results found'));
    $none = comet_site_test_get('/search');
    assert_same(200, $none['status']);
    assert_false(str_contains($none['body'], '<article class="c-card">'));
    assert_false(str_contains($none['body'], '<p class="c-empty">'));
});

test('lists set to the search show nothing without a query and a placeholder in the builder', function (): void {
    $site = comet_site_test_setup();
    $ctx = comet_site_test_context($site);
    $node = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [LayoutSchema::node('collection', ['list_source' => 'search', 'collections' => ['blog', 'bad key!']])])], 'column')[0]['children'][0];
    assert_same('search', $node['props']['list_source']);
    assert_same(['blog'], $node['props']['collections']);

    $ctx->searchQuery = 'hello';
    assert_same('', (new NodeRenderer($ctx))->render([$node]), 'Off the results page, a query shows nothing either.');
    $ctx->annotate = true;
    assert_true(str_contains((new NodeRenderer($ctx))->render([$node]), 'only show on the search results page'));
    $ctx->annotate = false;
    $ctx->searchPage = true;
    $ctx->searchQuery = '';
    assert_same('', (new NodeRenderer($ctx))->render([$node]));
    $ctx->searchQuery = 'hello';
    assert_true(str_contains((new NodeRenderer($ctx))->render([$node]), 'c-card'));
    $ctx->searchQuery = '';
    $ctx->annotate = true;
    assert_true(str_contains((new NodeRenderer($ctx))->render([$node]), 'Lists the hits of the submitted search'));
});

test('search results pages are cached per query', function (): void {
    comet_site_test_setup();
    $service = new \CometCMS\Site\SiteService(comet_site_test_context());
    $first = $service->respondCached('/search', ['q' => 'hello']);
    assert_same('MISS', $first['cache_status']);
    assert_true(str_contains($first['body'], 'name="q" value="hello"'), 'The query reaches the page.');
    assert_true(str_contains($first['body'], '<article class="c-card">'));
    assert_same('HIT', $service->respondCached('/search', ['q' => 'hello'])['cache_status']);
    assert_same('MISS', $service->respondCached('/search', ['q' => 'about'])['cache_status']);
});

test('a search list card shows fields of every searched type and leaves missing ones empty', function (): void {
    $site = comet_site_test_setup();
    $ctx = comet_site_test_context($site);
    $card = LayoutSchema::node('column', [], [], [
        LayoutSchema::node('field', ['source' => \CometCMS\Site\SmartValue::reference('title')]),
        LayoutSchema::node('field', ['source' => \CometCMS\Site\SmartValue::reference('intro')]),
    ]);
    $node = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [LayoutSchema::node('collection', ['list_source' => 'search', 'columns' => 1], [], [$card])])], 'column')[0]['children'][0];

    $ctx->searchPage = true;
    $ctx->searchQuery = 'hello';
    $html = (new NodeRenderer($ctx))->render([$node]);
    assert_same(1, substr_count($html, '<article class="c-card">'));
    assert_true(str_contains($html, 'Hello &lt;script&gt;'));

    $ctx->searchQuery = 'build';
    assert_true(str_contains((new NodeRenderer($ctx))->render([$node]), 'We build'), 'A field of the hit\'s own type shows.');

    $ctx->searchQuery = '';
    $ctx->annotate = true;
    $builder = (new NodeRenderer($ctx))->render([$node]);
    assert_true(str_contains($builder, 'Lists the hits of the submitted search'));
    assert_true(substr_count($builder, '<article class="c-card">') >= 2, 'The builder shows the newest entries as sample hits.');

    $searchCard = LayoutSchema::spec()['search_card'];
    assert_same('column', $searchCard[0]['kind']);
    assert_true(str_contains(json_encode($searchCard), '"title"'));
});

test('previews of the results page search for a word of the newest entry', function (): void {
    $site = comet_site_test_setup();
    $ctx = comet_site_test_context($site);
    $query = (new SiteSearch($ctx))->sampleQuery();
    assert_true(mb_strlen($query) >= SiteSearch::MIN_QUERY_LENGTH);
    assert_true((new SiteSearch($ctx))->page($query, [], [], 10, 0)['total'] > 0, 'The example query finds something.');
});

test('lists of several types show entries of each with their own cards and page through them all', function (): void {
    $site = comet_site_test_setup();
    $ctx = comet_site_test_context($site);
    $list = static fn(array $props): array => LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [LayoutSchema::node('collection', ['list_source' => 'types'] + $props)])], 'column')[0]['children'][0];

    $html = (new NodeRenderer($ctx))->render([$list(['collections' => ['authors', 'about', 'notes'], 'limit' => 10])]);
    assert_same(2, substr_count($html, '<article class="c-card">'), 'Private types stay out.');
    assert_true(str_contains($html, 'Ada') && str_contains($html, 'About us'));

    $paged = (new NodeRenderer($ctx))->render([$list(['collections' => ['authors', 'about'], 'limit' => 1, 'paginate' => true, 'sort_field' => 'title'])]);
    assert_same(1, substr_count($paged, '<article class="c-card">'));
    assert_true(str_contains($paged, 'c-pagination'), 'The pages count the entries of every type.');

    assert_same('', (new NodeRenderer($ctx))->render([$list([])]));
    $ctx->annotate = true;
    assert_true(str_contains((new NodeRenderer($ctx))->render([$list([])]), 'Choose public content types'));
});
