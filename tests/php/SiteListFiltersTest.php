<?php

declare(strict_types=1);

use CometCMS\Content\ContentRepository;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\ListFilters;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\SiteService;

function comet_list_filters_site(): \CometCMS\Site\SiteRepository
{
    $site = comet_site_test_setup();
    ContentRepository::make()->save('blog', ['title' => 'News of the week', 'slug' => 'week', 'category' => 'news', 'status' => 'published'], comet_site_test_user());

    return $site;
}

function comet_list_filters_node(array $props = []): array
{
    $node = LayoutSchema::node('collection', $props + [
        'type' => 'blog',
        'filters' => [['field' => 'category'], ['field' => 'title', 'label' => 'Find'], ['field' => 'author', 'multiple' => true]],
        'sort_choices' => ['title', 'published_at'],
    ]);
    $node['id'] = 'list1';

    return $node;
}

test('list filter rows keep distinct known fields and bound their number', function (): void {
    $rows = ListFilters::normalize([
        ['field' => 'category', 'label' => "Kind\nof post", 'multiple' => 'yes'],
        ['field' => 'category'],
        ['field' => ''],
        'author',
        ...array_map(static fn(int $i): array => ['field' => 'f' . $i], range(1, 20)),
    ]);

    assert_same(['field' => 'category', 'label' => 'Kind of post', 'multiple' => false], $rows[0]);
    assert_same(['field' => 'author', 'label' => '', 'multiple' => false], $rows[1]);
    assert_same(ListFilters::MAX_FILTERS, count($rows));
    assert_same([], LayoutSchema::node('collection')['props']['filters']);
});

test('visitor query parameters are reduced to strings and short lists by list', function (): void {
    $lists = ListFilters::fromQuery([
        'list1' => ['category' => ['news', ['x'], 'news'], 'title' => "  hi\0 ", 'empty' => '', 'bad key!' => 'x'],
        'bad id!' => ['category' => 'news'],
        'list0' => 'nope',
    ]);

    assert_same(['list1' => ['category' => ['news'], 'title' => 'hi']], $lists);
    assert_same([], ListFilters::fromQuery('c'));
});

test('entry lists render a filter bar and narrow, search and sort by the visitor choice', function (): void {
    $ctx = comet_site_test_context(comet_list_filters_site());
    $render = static function (array $params, array $props = []) use ($ctx): string {
        $ctx->listParams = ListFilters::fromQuery(['list1' => $params]);
        return (new NodeRenderer($ctx))->render([comet_list_filters_node($props)], ['path' => '/blog']);
    };

    $all = $render([]);
    assert_true(str_contains($all, 'class="c-list-filters" id="c-list-list1"'));
    assert_true(str_contains($all, 'action="/blog#c-list-list1"'));
    assert_true(str_contains($all, 'name="c[list1][category]" value="tips"'));
    assert_true(str_contains($all, '<span>Tips &amp; tricks</span>'));
    assert_true(str_contains($all, 'name="c[list1][author][]" value="'), 'Linked entries are multiple choice chips.');
    assert_true(str_contains($all, '<span>Ada</span>'));
    assert_true(str_contains($all, 'placeholder="Find"'));
    assert_true(str_contains($all, '<option value="-published_at">Date: newest first</option>'));
    assert_false(str_contains($all, 'c-filter-reset'));
    assert_true(str_contains($all, 'News of the week') && str_contains($all, 'Hello'));

    $news = $render(['category' => 'news']);
    assert_true(str_contains($news, 'News of the week'));
    assert_false(str_contains($news, 'Hello &lt;script'));
    assert_true(str_contains($news, 'value="news" checked'));
    assert_true(str_contains($news, 'href="/blog#c-list-list1">Reset filters'));

    $search = $render(['title' => 'hello']);
    assert_false(str_contains($search, 'News of the week'));
    assert_true(str_contains($search, 'value="hello"'));

    assert_true(str_contains($render(['category' => 'nothing']), 'News of the week'), 'Unknown options are ignored.');
    $none = $render(['title' => 'zzz']);
    assert_true(str_contains($none, 'No results found') && str_contains($none, 'c-list-filters'), 'A filter without results keeps its bar.');

    $sorted = $render(['sort' => '-title']);
    assert_true(strpos($sorted, 'News of the week') < strpos($sorted, 'Hello &lt;script'));
    $sorted = $render(['sort' => 'title']);
    assert_true(strpos($sorted, 'Hello &lt;script') < strpos($sorted, 'News of the week'));
    assert_true(str_contains($sorted, '<option value="title" selected>'));

    $editorFiltered = $render(['title' => 'week'], ['filter_field' => 'category', 'filter_value' => 'tips']);
    assert_false(str_contains($editorFiltered, 'News of the week'), 'The editor filter always applies.');

    $paged = $render(['category' => 'news'], ['paginate' => true, 'limit' => 1]);
    assert_false(str_contains($paged, 'page=2'), 'One result needs no second page.');
    $paged = $render(['sort' => 'title'], ['paginate' => true, 'limit' => 1]);
    assert_true(str_contains($paged, '/blog?c%5Blist1%5D%5Bsort%5D=title&amp;page=2'), 'Page links keep the choices.');
});

test('lists without filter controls render no bar and builder previews ignore the address', function (): void {
    $ctx = comet_site_test_context(comet_list_filters_site());
    $plain = (new NodeRenderer($ctx))->render([LayoutSchema::node('collection', ['type' => 'blog'])], ['path' => '/']);
    assert_false(str_contains($plain, 'c-list-filters'));

    $ctx->annotate = true;
    $ctx->listParams = ['list1' => ['category' => 'news']];
    $html = (new NodeRenderer($ctx))->render([comet_list_filters_node()], ['path' => '/']);
    assert_true(str_contains($html, 'Hello'));
    assert_false(str_contains($html, 'data-list-filters'));
    assert_true(str_contains($html, 'tabindex="-1"'));
});

test('filtered pages load the hashed filter module and cache each choice separately', function (): void {
    $site = comet_list_filters_site();
    $site->saveLayout('blog', 'index', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [comet_list_filters_node()])])])]);
    $service = new SiteService(comet_site_test_context($site));

    $page = $service->respondCached('/blog', []);
    assert_true(str_contains($page['body'], '<script type="module">' . ListFilters::script() . '</script>'));
    assert_same('MISS', $service->respondCached('/blog', ['c' => ['list1' => ['category' => 'news']]])['cache_status']);
    $news = $service->respondCached('/blog', ['c' => ['list1' => ['category' => 'news']]]);
    assert_same('HIT', $news['cache_status']);
    assert_false(str_contains($news['body'], 'Hello &lt;script'));
    assert_true(str_contains($service->respondCached('/blog', [])['body'], 'Hello &lt;script'));
});

test('lists of several types filter by the fields of all of them and sort across types', function (): void {
    $ctx = comet_site_test_context(comet_list_filters_site());
    $node = LayoutSchema::node('collection', [
        'list_source' => 'types',
        'collections' => ['blog', 'about'],
        'filters' => [['field' => 'category'], ['field' => 'title', 'label' => 'Find']],
        'sort_choices' => ['title'],
        'columns' => 1,
    ], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading', ['text' => \CometCMS\Site\SmartValue::reference('title')])])]);
    $node['id'] = 'list1';
    $render = static function (array $params, array $props = []) use ($ctx, $node): string {
        $ctx->listParams = ListFilters::fromQuery(['list1' => $params]);
        $node['props'] = $props + $node['props'];
        return (new NodeRenderer($ctx))->render([$node], ['path' => '/']);
    };

    $all = $render([]);
    assert_true(str_contains($all, 'class="c-list-filters"'), 'The bar shows for several types too.');
    assert_true(str_contains($all, '<span>Tips &amp; tricks</span>'));
    foreach (['About us', 'News of the week', 'Hello'] as $title) assert_true(str_contains($all, $title), $title);

    // Only blog has a category, so about drops out of a category filter.
    $news = $render(['category' => 'news']);
    assert_true(str_contains($news, 'News of the week'));
    assert_false(str_contains($news, 'About us') || str_contains($news, 'Hello &lt;script'));

    // Fields every type has search all of them.
    $about = $render(['title' => 'about']);
    assert_true(str_contains($about, 'About us'));
    assert_false(str_contains($about, 'News of the week'));

    $az = $render(['sort' => 'title']);
    assert_true(strpos($az, 'About us') < strpos($az, 'Hello &lt;script') && strpos($az, 'Hello &lt;script') < strpos($az, 'News of the week'));
    $za = $render(['sort' => '-title']);
    assert_true(strpos($za, 'News of the week') < strpos($za, 'About us'));

    $paged = $render(['sort' => 'title'], ['paginate' => true, 'limit' => 1]);
    assert_true(str_contains($paged, '/?c%5Blist1%5D%5Bsort%5D=title&amp;page=2'), 'Page links keep the choices.');
});

test('combined schemas join select options across types', function (): void {
    $schema = ListFilters::combinedSchema([
        ['fields' => ['kind' => ['type' => 'select', 'options' => ['a' => 'A']], 'body' => ['type' => 'html', 'label' => 'Body']]],
        ['fields' => ['kind' => ['type' => 'select', 'options' => ['b', 'a']], 'body' => ['type' => 'text', 'label' => 'Other']]],
    ]);
    assert_same(['a' => 'A', 'b' => 'b'], $schema['fields']['kind']['options']);
    assert_same('html', $schema['fields']['body']['type'], 'The first type sets a field up.');
});
