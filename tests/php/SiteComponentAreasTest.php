<?php

declare(strict_types=1);

use CometCMS\Content\ContentTypeRepository;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\PageRenderer;

/** A component section whose column holds a fixed heading and a content area. */
function comet_area_test_component(\CometCMS\Site\SiteRepository $site, string $name = 'Card'): array
{
    return $site->createComponent($name, [comet_component_test_section([
        ['id' => 'title', 'kind' => 'heading', 'props' => ['text' => 'Shared title']],
        ['id' => 'body', 'kind' => LayoutSchema::COMPONENT_AREA, 'name' => 'Body', 'children' => [['kind' => 'heading']]],
    ])]);
}

/** An instance with its own elements in the area $area. */
function comet_area_test_instance(string $id, string $component, string $area, array $children): array
{
    return comet_component_test_instance($id, $component) + ['children' => [
        ['id' => $id . '-fill', 'kind' => LayoutSchema::AREA_CONTENT, 'props' => ['area' => $area], 'children' => $children],
    ]];
}

test('content areas are offered only in component trees, outside cards, items and other areas', function (): void {
    assert_false(in_array(LayoutSchema::COMPONENT_AREA, LayoutSchema::spec(true)['elements'], true));
    $spec = LayoutSchema::spec(component: true);
    assert_true(in_array(LayoutSchema::COMPONENT_AREA, $spec['elements'], true));
    assert_true(in_array(LayoutSchema::COMPONENT_AREA, $spec['kinds']['column']['children'], true));
    assert_same([LayoutSchema::AREA_CONTENT], $spec['kinds']['component']['children']);
    assert_false(in_array(LayoutSchema::COMPONENT_AREA, $spec['kinds'][LayoutSchema::AREA_CONTENT]['children'], true));

    $area = static fn(string $id): array => ['id' => $id, 'kind' => LayoutSchema::COMPONENT_AREA, 'name' => 'Area ' . $id];
    $tree = LayoutSchema::normalizeComponent([comet_component_test_section([
        $area('a1'),
        ['id' => 'list', 'kind' => 'collection', 'props' => ['type' => 'blog'], 'children' => [['id' => 'card', 'kind' => 'column', 'children' => [$area('a2')]]]],
        comet_component_test_instance('nested', 'other', 'row') + ['children' => [
            ['id' => 'fill', 'kind' => LayoutSchema::AREA_CONTENT, 'props' => ['area' => 'x'], 'children' => [$area('a3'), ['id' => 'r', 'kind' => 'row', 'children' => [['id' => 'c', 'kind' => 'column', 'children' => [$area('a4')]]]]]],
        ]],
    ])], 'section');
    assert_same([['id' => 'a1', 'name' => 'Area a1']], LayoutSchema::componentAreas($tree));
    assert_same([], $tree[0]['children'][0]['children'][0]['children'][0]['children'], 'An area holds no content of its own.');

    // Layouts have no areas of their own.
    $layout = LayoutSchema::normalize([comet_component_test_section([$area('a5'), ['id' => 'h', 'kind' => 'heading']])], true);
    assert_same(['h'], array_column($layout[0]['children'][0]['children'][0]['children'], 'id'));
});

test('instances keep one area content per area, holding what a column holds', function (): void {
    $nodes = LayoutSchema::normalize([comet_component_test_instance('i1', 'card') + ['children' => [
        ['id' => 'f1', 'kind' => LayoutSchema::AREA_CONTENT, 'props' => ['area' => 'body'], 'children' => [
            ['id' => 'h1', 'kind' => 'heading'],
            ['id' => 's1', 'kind' => 'section'],
            ['id' => 'p1', 'kind' => LayoutSchema::PAGE_CONTENT, 'props' => ['field' => 'page']],
            ['id' => 'r1', 'kind' => 'row', 'children' => [['id' => 'c1', 'kind' => 'column', 'children' => [['id' => 'h2', 'kind' => 'heading']]]]],
        ]],
        ['id' => 'f2', 'kind' => LayoutSchema::AREA_CONTENT, 'props' => ['area' => 'body']],
        ['id' => 'f3', 'kind' => LayoutSchema::AREA_CONTENT, 'props' => ['area' => '']],
        ['id' => 'h3', 'kind' => 'heading'],
    ]]], true);

    assert_same(['f1'], array_column($nodes[0]['children'], 'id'));
    assert_same(['h1', 'r1'], array_column($nodes[0]['children'][0]['children'], 'id'));
    assert_same(['area' => 'body'], $nodes[0]['children'][0]['props']);
    assert_same([], LayoutSchema::pagePlaces($nodes), 'Area content holds no Entry content area.');
    assert_same(['card'], LayoutSchema::componentRefs($nodes));
});

test('instances show their own elements in each content area of their component', function (): void {
    $site = comet_site_test_setup();
    $component = comet_area_test_component($site);
    assert_same([['id' => 'body', 'name' => 'Body']], $component['areas']);
    assert_same([['id' => 'body', 'name' => 'Body']], array_column($site->components(), 'areas')[0]);
    $site->saveLayout('blog', 'detail', [
        comet_area_test_instance('first', $component['id'], 'body', [['id' => 'own', 'kind' => 'text', 'props' => ['html' => '<p>First body</p>']]]),
        comet_area_test_instance('second', $component['id'], 'body', [['kind' => 'heading', 'props' => ['text' => 'Second body']]]),
        comet_component_test_instance('third', $component['id']),
    ]);

    $html = comet_site_test_get('/blog/hello')['body'];
    assert_same(3, substr_count($html, 'Shared title'));
    assert_true(str_contains($html, 'First body') && str_contains($html, 'Second body'));
    assert_false(str_contains($html, 'c-area'), 'Websites show area content straight in its column.');

    $context = comet_site_test_context();
    $context->preview = true;
    $context->annotate = true;
    $html = (new PageRenderer($context))->layoutPreview((new ContentTypeRepository())->find('blog'), 'detail', null);
    assert_true(str_contains($html, '<div class="c-area" data-node="first-fill" data-kind="area_content">'));
    assert_true(str_contains($html, 'data-node="own" data-kind="text"'), 'Area content is selectable inside the instance.');
    assert_false(str_contains($html, 'data-node="title"'), 'The component\'s own nodes stay one unit.');
    assert_true(str_contains($html, 'data-placeholder="areaNotLoaded"'), 'An instance without content for an area shows where it goes.');
    assert_false(str_contains($html, 'data-placeholder="areaContent"'));

    $site->saveLayout('blog', 'detail', [comet_area_test_instance('empty', $component['id'], 'body', [])]);
    $context = comet_site_test_context();
    $context->preview = true;
    $context->annotate = true;
    $html = (new PageRenderer($context))->layoutPreview((new ContentTypeRepository())->find('blog'), 'detail', null);
    assert_true(str_contains($html, 'data-node="empty-fill"') && str_contains($html, 'data-placeholder="areaContent"'));

    $preview = (new PageRenderer($context))->componentPreview($component, null, null);
    assert_true(str_contains($preview, 'data-node="body" data-kind="component_area"') && str_contains($preview, 'data-placeholder="componentArea"'));
});

test('area content of a removed area is kept but not shown', function (): void {
    $site = comet_site_test_setup();
    $component = comet_area_test_component($site);
    $site->saveLayout('blog', 'detail', [comet_area_test_instance('i1', $component['id'], 'body', [['kind' => 'heading', 'props' => ['text' => 'Own heading']]])]);
    $site->saveComponent($component['id'], [comet_component_test_section([['kind' => 'heading', 'props' => ['text' => 'Shared title']]])]);

    assert_same('Own heading', $site->layout('blog', 'detail')[0]['children'][0]['children'][0]['props']['text']);
    assert_false(str_contains(comet_site_test_get('/blog/hello')['body'], 'Own heading'));
});

test('area content may hold the same component again, and detaching puts it in place of the area', function (): void {
    $site = comet_site_test_setup();
    $component = $site->createComponent('Strip', [['kind' => 'row', 'children' => [['kind' => 'column', 'children' => [
        ['id' => 'title', 'kind' => 'heading', 'props' => ['text' => 'Shared title'], 'editable' => ['text']],
        ['id' => 'body', 'kind' => LayoutSchema::COMPONENT_AREA],
        ['id' => 'end', 'kind' => 'divider'],
    ]]]]]);
    $instance = static fn(string $id, array $children): array => ['id' => $id, 'kind' => 'component', 'props' => ['component' => $component['id'], 'root' => 'row'], 'children' => [
        ['id' => $id . '-fill', 'kind' => LayoutSchema::AREA_CONTENT, 'props' => ['area' => 'body'], 'children' => $children],
    ]];
    $site->saveLayout('blog', 'detail', [['id' => 'sec', 'kind' => 'section', 'children' => [
        $instance('i1', [
            ['id' => 'own', 'kind' => 'text', 'props' => ['html' => '<p>Own body</p>']],
            $instance('inner', [['id' => 'deep', 'kind' => 'heading', 'props' => ['text' => 'Deep heading']]]),
        ]) + ['overrides' => ['title' => ['text' => 'Own title']]],
    ]]]);

    $html = comet_site_test_get('/blog/hello')['body'];
    assert_true(str_contains($html, 'Own title') && str_contains($html, 'Deep heading'));
    assert_same(1, substr_count($html, 'Shared title'), 'The nested placement shows the component again.');

    $site->deleteComponent($component['id']);
    $copy = $site->layout('blog', 'detail')[0]['children'][0];
    assert_same(['i1', 'row'], [$copy['id'], $copy['kind']]);
    $column = $copy['children'][0]['children'];
    assert_same(['heading', 'text', 'row', 'divider'], array_column($column, 'kind'));
    assert_same('Own title', $column[0]['props']['text'], 'The instance\'s own values stay.');
    assert_false(isset($column[0]['editable']));
    assert_false($column[0]['id'] === 'title', 'The component\'s nodes get new ids.');
    assert_same('own', $column[1]['id'], 'Area content keeps its ids.');
    $nested = $column[2]['children'][0]['children'];
    assert_same(['heading', 'heading', 'divider'], array_column($nested, 'kind'), 'Instances inside area content are detached too.');
    assert_same('deep', $nested[1]['id']);
});

test('mcp instances get content for every area and name the areas they may fill', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $component = comet_area_test_component(new \CometCMS\Site\SiteRepository());
    $target = ['collection' => 'pages', 'variant' => 'detail'];

    [, $error] = comet_test_mcp_tool($token, 'set_layout', $target + ['revision' => $layout['revision'], 'sections' => [
        comet_component_test_instance('i1', $component['id']) + ['children' => [['kind' => 'area_content', 'props' => ['area' => 'nope']]]],
    ]]);
    assert_true(str_contains($error['error']['message'], 'body (Body)'), json_encode($error));

    [, $saved] = comet_test_mcp_tool($token, 'set_layout', $target + ['revision' => $layout['revision'], 'sections' => [comet_component_test_instance('i1', $component['id'])]]);
    assert_true(isset($saved['data']['revision']), json_encode($saved));
    [, $element] = comet_test_mcp_tool($token, 'get_element', $target + ['element_id' => 'i1']);
    $area = $element['data']['element']['content_areas'][0];
    assert_same(['body', 'Body'], [$area['id'], $area['name']]);
    assert_same('area_content', $element['data']['element']['children'][0]['kind']);

    [, $patched] = comet_test_mcp_tool($token, 'update_section', $target + ['revision' => $saved['data']['revision'], 'section_id' => 'i1', 'patch' => ['children' => [
        ['id' => $area['content_id'], 'kind' => 'area_content', 'props' => ['area' => 'body'], 'children' => [['kind' => 'heading', 'props' => ['text' => 'From MCP']]]],
    ]]]);
    assert_true(isset($patched['data']['revision']), json_encode($patched));
    assert_true(str_contains(json_encode((new \CometCMS\Site\SiteRepository())->layout('pages', 'detail')), 'From MCP'));
});
