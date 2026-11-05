<?php

declare(strict_types=1);

use CometCMS\Content\ContentTypeRepository;
use CometCMS\Mcp\PageBuilderTools;
use CometCMS\Site\SiteRepository;

function comet_test_builder_token(): string
{
    return comet_test_mcp_token([
        ['actions' => ['site.manage', 'schema.create', 'content.create'], 'resources' => ['workspace:default:*']],
    ]);
}

function comet_test_builder_sections(): array
{
    return [
        ['id' => 'hero', 'kind' => 'section', 'children' => [
            ['id' => 'row', 'kind' => 'row', 'children' => [
                ['id' => 'column', 'kind' => 'column', 'children' => [
                    ['id' => 'heading', 'kind' => 'heading', 'props' => ['text' => 'Welcome', 'level' => 'h1']],
                    ['id' => 'body', 'kind' => 'text', 'props' => ['html' => '<p>Body</p>']],
                ]],
            ]],
        ]],
        ['id' => 'other', 'kind' => 'section', 'name' => 'Keep me'],
    ];
}

function comet_test_builder_layout(string $token): array
{
    [, $payload] = comet_test_mcp_tool($token, 'get_layout', ['collection' => 'pages', 'variant' => 'detail']);
    assert_true(isset($payload['data']), json_encode($payload));
    return $payload['data'];
}

function comet_test_builder_setup(): array
{
    $token = comet_test_builder_token();
    [, $created] = comet_test_mcp_tool($token, 'create_content_type', ['name' => 'pages']);
    assert_same('pages', $created['data']['name']);
    $layout = comet_test_builder_layout($token);
    [, $saved] = comet_test_mcp_tool($token, 'set_layout', ['collection' => 'pages', 'variant' => 'detail', 'revision' => $layout['revision'], 'sections' => comet_test_builder_sections()]);
    assert_true(isset($saved['data']['revision']), json_encode($saved));
    return [$token, $saved['data']];
}

test('mcp builder discovery and execution require workspace site manage grants', function (): void {
    $headless = comet_test_mcp_token([['actions' => ['content.*'], 'resources' => ['workspace:default:content:*']]]);
    $wrongWorkspace = comet_test_mcp_token([['actions' => ['site.manage'], 'resources' => ['workspace:other:site:*']]]);
    $denied = comet_test_mcp_token([
        ['actions' => ['site.manage'], 'resources' => ['*']],
        ['effect' => 'deny', 'actions' => ['site.manage'], 'resources' => ['workspace:default:site:*']],
    ]);
    foreach ([null, $headless, $wrongWorkspace, $denied] as $token) {
        [$response] = comet_test_mcp_request($token, 'tools/list');
        $names = array_column($response['result']['tools'], 'name');
        assert_same([], array_values(array_intersect(PageBuilderTools::NAMES, $names)));
        foreach (PageBuilderTools::NAMES as $name) {
            [$response, $payload] = comet_test_mcp_tool($token, $name);
            assert_true($response['result']['isError']);
            assert_same($token === null ? 401 : 403, $payload['error']['status']);
            assert_same(['site.manage'], $payload['error']['required_permissions'][0]['actions']);
        }
    }
    [$response] = comet_test_mcp_request(comet_test_builder_token(), 'tools/list');
    $names = array_column($response['result']['tools'], 'name');
    assert_same(PageBuilderTools::NAMES, array_values(array_intersect(PageBuilderTools::NAMES, $names)));
    foreach ($response['result']['tools'] as $tool) {
        // JSON Schema clients (e.g. zod-based MCP SDKs) reject `"properties": []`.
        assert_true(str_contains(json_encode($tool['inputSchema']), '"properties":{'), $tool['name'] . ' inputSchema.properties must encode as an object');
        if ($tool['name'] === 'set_layout') {
            assert_true(in_array('revision', $tool['inputSchema']['required'], true));
            assert_true(isset($tool['inputSchema']['$defs']['node']));
            assert_false($tool['annotations']['readOnlyHint']);
        }
    }
});

test('mcp builder creates layouts and edits element props end to end', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    assert_same(['hero', 'other'], array_column($layout['sections'], 'id'));
    assert_same(5, $layout['sections'][0]['node_count']);
    assert_false(isset($layout['sections'][0]['children']));
    assert_false(isset($layout['sections'][0]['props']));
    [$response, $schema] = comet_test_mcp_tool($token, 'get_builder_schema', ['kind' => 'heading']);
    assert_same($schema, $response['result']['structuredContent']);
    assert_same('h2', $schema['data']['props']['level']['default']);
    [, $section] = comet_test_mcp_tool($token, 'get_section', ['collection' => 'pages', 'variant' => 'detail', 'section_id' => 'hero']);
    assert_same('heading', $section['data']['section']['children'][0]['children'][0]['children'][0]['id']);
    [, $updated] = comet_test_mcp_tool($token, 'update_element', [
        'collection' => 'pages', 'variant' => 'detail', 'revision' => $layout['revision'], 'element_id' => 'heading',
        'props' => ['text' => 'Hello world'], 'style' => ['align' => 'center'],
    ]);
    assert_same('Hello world', $updated['data']['element']['props']['text']);
    assert_same('h1', $updated['data']['element']['props']['level']);
    assert_same('hero', $updated['data']['section_id']);
    assert_same('center', $updated['data']['element']['style']['align']);
    assert_same([], $updated['data']['element']['children']);
    assert_true($layout['revision'] !== $updated['data']['revision']);
    [, $read] = comet_test_mcp_tool($token, 'get_element', ['collection' => 'pages', 'variant' => 'detail', 'element_id' => 'heading']);
    assert_same($updated['data']['element'], $read['data']['element']);
    [, $entry] = comet_test_mcp_tool($token, 'create_entry', ['collection' => 'pages', 'entry' => ['title' => 'Home']]);
    assert_same('Home', $entry['data']['title']);
    $site = new SiteRepository();
    $saved = $site->layout('pages', 'detail');
    $html = (new \CometCMS\Site\NodeRenderer(comet_site_test_context($site)))->render($saved);
    assert_true(str_contains($html, 'Hello world</h1>'));
    assert_same('<p>Body</p>', $saved[0]['children'][0]['children'][0]['children'][1]['props']['html']);
    assert_same('Keep me', $saved[1]['name']);
    assert_same('mcp.saved', $site->layoutRevisions('pages', 'detail')[0]['event']);
    assert_true($site->layoutRevisions('pages', 'detail')[0]['created_by'] !== null);
});

test('mcp builder scoped reads limit depth and keep the whole layout revision', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $target = ['collection' => 'pages', 'variant' => 'detail'];
    [, $selected] = comet_test_mcp_tool($token, 'get_layout', $target + ['section_ids' => ['other', 'hero']]);
    assert_same($layout['sections'], $selected['data']['sections'], 'Selectors preserve layout order.');
    [, $selected] = comet_test_mcp_tool($token, 'get_layout', $target + ['section_ids' => ['hero'], 'depth' => 1]);
    $hero = $selected['data']['sections'][0];
    assert_same(['hero'], array_column($selected['data']['sections'], 'id'));
    assert_same(5, $hero['node_count']);
    assert_same('row', $hero['children'][0]['id']);
    assert_same(1, $hero['children'][0]['child_count']);
    assert_true($hero['children'][0]['children_truncated']);
    assert_false(isset($hero['children'][0]['children']));
    assert_same($layout['revision'], $selected['data']['revision']);

    [, $section] = comet_test_mcp_tool($token, 'get_section', $target + ['section_id' => 'hero', 'depth' => 0]);
    assert_same(['id' => 'hero', 'kind' => 'section', 'child_count' => 1, 'children_truncated' => true], $section['data']['section']);
    [, $full] = comet_test_mcp_tool($token, 'get_section', $target + ['section_id' => 'hero', 'mode' => 'full', 'depth' => 3]);
    assert_same('Welcome', $full['data']['section']['children'][0]['children'][0]['children'][0]['props']['text']);
    assert_false(isset($full['data']['section']['children'][0]['children'][0]['children'][0]['children_truncated']));
    [, $fullLayout] = comet_test_mcp_tool($token, 'get_layout', $target + ['section_ids' => ['hero'], 'mode' => 'full']);
    assert_same($full['data']['section'], array_diff_key($fullLayout['data']['sections'][0], ['node_count' => true]));

    // A filtered revision must still detect a concurrent edit to an unselected section.
    [, $updated] = comet_test_mcp_tool($token, 'update_section', $target + ['revision' => $layout['revision'], 'section_id' => 'other', 'patch' => ['name' => 'Changed']]);
    assert_true(isset($updated['data']['revision']), json_encode($updated));
    [, $stale] = comet_test_mcp_tool($token, 'update_section', $target + ['revision' => $selected['data']['revision'], 'section_id' => 'hero', 'patch' => ['name' => 'Stale']]);
    assert_same(409, $stale['error']['status']);
});

test('mcp builder summary reads omit content and preserve stored styles at every level', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $target = ['collection' => 'pages', 'variant' => 'detail'];
    [, $updated] = comet_test_mcp_tool($token, 'update_element', $target + [
        'revision' => $layout['revision'], 'element_id' => 'heading', 'style' => ['align' => 'center'],
        'responsive' => ['mobile' => ['style' => ['font_size' => 'xl']]],
        'states' => ['hover' => ['style' => ['text_color' => 'accent']]],
    ]);
    assert_true(isset($updated['data']['revision']), json_encode($updated));
    [, $read] = comet_test_mcp_tool($token, 'get_section', $target + ['section_id' => 'hero', 'mode' => 'summary']);
    $heading = $read['data']['section']['children'][0]['children'][0]['children'][0];
    assert_same(['id' => 'heading', 'kind' => 'heading', 'style' => ['align' => 'center'], 'responsive' => ['mobile' => ['style' => ['font_size' => 'xl']]], 'states' => ['hover' => ['style' => ['text_color' => 'accent']]]], $heading);
    assert_false(str_contains(json_encode($read), 'Welcome'));
    assert_false(str_contains(json_encode($read), '<p>Body</p>'));
    [, $overview] = comet_test_mcp_tool($token, 'get_layout', $target + ['mode' => 'summary', 'section_ids' => ['hero']]);
    assert_same($read['data']['section'], array_diff_key($overview['data']['sections'][0], ['node_count' => true]));
    assert_same($updated['data']['revision'], $overview['data']['revision']);
    [, $shallow] = comet_test_mcp_tool($token, 'get_layout', $target + ['mode' => 'summary', 'depth' => 0]);
    assert_true($shallow['data']['sections'][0]['children_truncated']);
    assert_false(isset($shallow['data']['sections'][1]['children_truncated']), 'Empty sections are complete.');
});

test('mcp builder invalid read controls fail with actionable errors and no writes', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $target = ['collection' => 'pages', 'variant' => 'detail'];
    $before = (new SiteRepository())->layoutRevisions('pages', 'detail');
    foreach ([['depth' => -1], ['depth' => '1'], ['depth' => 1.5], ['depth' => null], ['mode' => 'compact'], ['mode' => null], ['section_ids' => 'hero'], ['section_ids' => []], ['section_ids' => ['hero', 'hero']], ['section_ids' => [42]], ['typo' => true]] as $args) {
        [$response, $error] = comet_test_mcp_tool($token, 'get_layout', $target + $args);
        assert_true($response['result']['isError']);
        assert_same(422, $error['error']['status'], json_encode($error));
        assert_true(isset($error['error']['details']['expected']));
        assert_true(str_contains($error['error']['recovery'][0], 'tools/list'));
    }
    [, $error] = comet_test_mcp_tool($token, 'get_section', $target + ['section_id' => 'hero', 'depth' => -1]);
    assert_same('depth', $error['error']['details']['path']);
    [, $error] = comet_test_mcp_tool($token, 'get_layout', $target + ['section_ids' => ['hero', 'missing']]);
    assert_same(404, $error['error']['status']);
    assert_same('missing', $error['error']['details']['section_id']);
    assert_same($layout, comet_test_builder_layout($token));
    assert_same($before, (new SiteRepository())->layoutRevisions('pages', 'detail'));
});

test('mcp builder argument errors describe shapes and examples before execution', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $target = ['collection' => 'pages', 'variant' => 'detail', 'revision' => $layout['revision'], 'section_id' => 'hero'];
    foreach (['{"style":{"padding_top":"lg"}}', ['style'], null] as $patch) {
        [, $error] = comet_test_mcp_tool($token, 'update_section', $target + ['patch' => $patch]);
        assert_same(422, $error['error']['status']);
        assert_same('patch', $error['error']['details']['path']);
        assert_same('object', $error['error']['details']['expected']['type']);
        assert_same(['style' => ['padding_top' => 'lg']], $error['error']['details']['example']);
        assert_true(str_contains($error['error']['message'], 'props (optional, object)'));
        assert_true(str_contains($error['error']['message'], 'not a JSON string or array'));
    }
    [, $error] = comet_test_mcp_tool($token, 'update_section', $target + ['patch' => ['style' => 'center']]);
    assert_same('patch.style', $error['error']['details']['path']);
    assert_same('object', $error['error']['details']['expected']['type']);
    [, $error] = comet_test_mcp_tool($token, 'update_section', $target);
    assert_same('patch', $error['error']['details']['path']);
    assert_true(str_contains($error['error']['message'], 'Required argument is missing'));
    [, $error] = comet_test_mcp_tool($token, 'set_layout', array_diff_key($target, ['section_id' => true]) + ['sections' => [['kind' => 'section', 'children' => [['children' => []]]]]]);
    assert_same('sections.0.children.0.kind', $error['error']['details']['path']);
    assert_same($layout, comet_test_builder_layout($token));
    [$listed] = comet_test_mcp_request($token, 'tools/list');
    $tools = array_column($listed['result']['tools'], null, 'name');
    assert_same(['style' => ['padding_top' => 'lg']], $tools['update_section']['inputSchema']['properties']['patch']['examples'][0]);
    assert_same(0, $tools['get_section']['inputSchema']['properties']['depth']['minimum']);
});

test('mcp builder section patches preserve siblings and support explicit children replacement', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $before = (new SiteRepository())->layout('pages', 'detail');
    [, $updated] = comet_test_mcp_tool($token, 'update_section', [
        'collection' => 'pages', 'variant' => 'detail', 'revision' => $layout['revision'], 'section_id' => 'hero',
        'patch' => ['name' => 'Hero', 'props' => ['width' => 'full'], 'style' => ['padding_top' => 'xl', 'padding_bottom' => 'xl']],
    ]);
    $saved = (new SiteRepository())->layout('pages', 'detail');
    assert_same($before[1], $saved[1]);
    assert_same($before[0]['children'], $saved[0]['children']);
    assert_same('full', $saved[0]['props']['width']);
    [, $updated] = comet_test_mcp_tool($token, 'update_section', [
        'collection' => 'pages', 'variant' => 'detail', 'revision' => $updated['data']['revision'], 'section_id' => 'hero',
        'patch' => ['children' => [['kind' => 'row', 'children' => [['kind' => 'column', 'children' => [['kind' => 'heading']]]]]]],
    ]);
    $saved = (new SiteRepository())->layout('pages', 'detail');
    assert_same('full', $saved[0]['props']['width']);
    assert_matches('/^n[a-f0-9]+$/', $saved[0]['children'][0]['id']);
    assert_same('heading', $saved[0]['children'][0]['children'][0]['children'][0]['kind']);
    [, $updated] = comet_test_mcp_tool($token, 'update_section', [
        'collection' => 'pages', 'variant' => 'detail', 'revision' => $updated['data']['revision'], 'section_id' => 'hero', 'patch' => ['children' => []],
    ]);
    assert_same(['id' => 'hero', 'kind' => 'section', 'name' => 'Hero', 'node_count' => 1], $updated['data']['section']);
    assert_false(isset($updated['data']['sections']), 'Patching one section does not repeat the others.');
});

test('mcp builder element patches take style alone and refuse empty patches', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $target = ['collection' => 'pages', 'variant' => 'detail', 'element_id' => 'heading'];
    [, $updated] = comet_test_mcp_tool($token, 'update_element', $target + ['revision' => $layout['revision'], 'style' => ['align' => 'center']]);
    assert_same('center', $updated['data']['element']['style']['align'] ?? null, json_encode($updated));
    assert_same('Welcome', $updated['data']['element']['props']['text']);
    [, $empty] = comet_test_mcp_tool($token, 'update_element', $target + ['revision' => $updated['data']['revision']]);
    assert_same('props', $empty['error']['details']['path'] ?? null, json_encode($empty));
});

test('mcp builder sets element conditions and rejects malformed ones', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $target = ['collection' => 'pages', 'variant' => 'detail', 'element_id' => 'heading'];
    $field = ['type' => 'reference', 'source' => 'current', 'path' => ['title']];
    foreach ([
        ['match' => 'some', 'conditions' => [['field' => $field, 'op' => 'empty']]],
        ['conditions' => [['field' => $field, 'op' => 'greater', 'value' => 1]]],
        ['conditions' => [['field' => $field, 'op' => 'equals', 'value' => ['x']]]],
        ['conditions' => [['field' => $field, 'op' => 'empty', 'value' => 'x']]],
        ['conditions' => [['field' => $field + ['filters' => [['name' => 'fallback', 'value' => 'x']]], 'op' => 'equals', 'value' => 'x']]],
    ] as $visibleIf) {
        [, $error] = comet_test_mcp_tool($token, 'update_element', $target + ['revision' => $layout['revision'], 'style' => ['visible_if' => $visibleIf]]);
        assert_same(422, $error['error']['status'] ?? null, json_encode([$visibleIf, $error]));
    }
    $visibleIf = ['match' => 'any', 'conditions' => [['field' => $field, 'op' => 'contains', 'value' => 'Welcome']]];
    [, $updated] = comet_test_mcp_tool($token, 'update_element', $target + ['revision' => $layout['revision'], 'style' => ['visible_if' => $visibleIf]]);
    assert_same('contains', $updated['data']['element']['style']['visible_if']['conditions'][0]['op'] ?? null, json_encode($updated));
});

test('mcp builder writes tables and explains malformed rows', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $target = ['collection' => 'pages', 'variant' => 'detail'];
    $sections = static function (array $rows): array {
        $sections = comet_test_builder_sections();
        $sections[0]['children'][0]['children'][0]['children'][] = ['id' => 'prices', 'kind' => 'table', 'props' => ['rows' => $rows, 'header_column' => true]];
        return $sections;
    };
    foreach ([
        [[['Plan', 'Price'], ['Basic']], 'same number of cells'],
        [['Plan', 'Price'], 'array of cells'],
        [[['Plan', ['x']]], 'inline HTML'],
        [[['<p>Plan</p>', 'Price']], 'inline HTML'],
    ] as [$rows, $message]) {
        [, $error] = comet_test_mcp_tool($token, 'set_layout', $target + ['revision' => $layout['revision'], 'sections' => $sections($rows)]);
        assert_same(422, $error['error']['status'] ?? null, json_encode([$rows, $error]));
        assert_true(str_contains(json_encode($error), $message), json_encode($error));
    }
    $rows = [['Plan', 'Price'], ['<strong>Basic</strong>', '9 €<br>monthly']];
    [, $saved] = comet_test_mcp_tool($token, 'set_layout', $target + ['revision' => $layout['revision'], 'sections' => $sections($rows)]);
    assert_true(isset($saved['data']['revision']), json_encode($saved));
    [, $element] = comet_test_mcp_tool($token, 'get_element', $target + ['element_id' => 'prices']);
    assert_same($rows, $element['data']['element']['props']['rows'] ?? null, json_encode($element));
});

test('mcp builder rejects stale revisions including intervening admin repository saves', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $site = new SiteRepository();
    $nodes = $site->layout('pages', 'detail');
    $nodes[1]['name'] = 'Concurrent human edit';
    $site->saveLayout('pages', 'detail', $nodes, [], 'saved', $layout['revision']);
    foreach (['set_layout' => ['sections' => []], 'update_section' => ['section_id' => 'hero', 'patch' => ['children' => []]], 'update_element' => ['element_id' => 'heading', 'props' => ['text' => 'Stale']]] as $tool => $extra) {
        [$response, $error] = comet_test_mcp_tool($token, $tool, ['collection' => 'pages', 'variant' => 'detail', 'revision' => $layout['revision']] + $extra);
        assert_true($response['result']['isError']);
        assert_same(409, $error['error']['status']);
        assert_true(str_contains($error['error']['recovery'][0], 'new revision'));
        assert_same($nodes, $site->layout('pages', 'detail'));
    }
    // Exercise the actual under-lock compare-and-save path independently of the early MCP check.
    assert_throws(\CometCMS\Core\ConflictException::class, fn() => $site->saveLayout('pages', 'detail', [], [], 'saved', $layout['revision']));
    assert_same($nodes, $site->layout('pages', 'detail'));
});

test('mcp builder validates inputs without silent normalization or persistence', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $base = ['collection' => 'pages', 'variant' => 'detail', 'revision' => $layout['revision']];
    $cases = [
        ['update_element', ['element_id' => 'heading', 'props' => ['typo' => 'x']]],
        ['update_element', ['element_id' => 'heading', 'props' => ['level' => 'h9']]],
        ['update_element', ['element_id' => 'heading', 'props' => ['level' => null]]],
        ['update_element', ['element_id' => 'heading', 'props' => [], 'style' => ['background_blur' => 99]]],
        ['update_element', ['element_id' => 'heading', 'props' => ['link' => ['type' => 'nonsense']]]],
        ['update_element', ['element_id' => 'body', 'props' => ['html' => '<script>alert(1)</script>']]],
        ['update_element', ['element_id' => 'heading', 'props' => 'bad']],
        ['update_element', ['element_id' => 'hero', 'props' => ['scroll_effect' => [['bad']]]]],
        ['update_element', ['element_id' => 'heading', 'props' => ['link' => ['type' => 'url', 'url' => ['bad']]]]],
        ['update_section', ['section_id' => 'hero', 'patch' => ['kind' => 'row']]],
        ['update_section', ['section_id' => 'hero', 'patch' => ['children' => [['kind' => 'heading']]]]],
        ['update_section', ['section_id' => 'hero', 'patch' => ['props' => ['width' => 'invalid']]]],
        ['update_section', ['section_id' => 'hero', 'patch' => ['name' => str_repeat('x', 121)]]],
        ['set_layout', ['sections' => [['id' => 'dup', 'kind' => 'section'], ['id' => 'dup', 'kind' => 'section']]]],
        ['set_layout', ['sections' => [['kind' => 'section', 'id' => null]]]],
        ['set_layout', ['sections' => [['kind' => 'section', 'props' => []], ['kind' => 'nope']]]],
        ['set_layout', ['sections' => [['kind' => 'section', 'extra' => true]]]],
        ['set_layout', ['sections' => null]],
        ['set_layout', ['sections' => ['bad' => ['kind' => 'section']]]],
        ['set_layout', ['sections' => [['kind' => 'section', 'children' => 'bad']]]],
    ];
    $before = (new SiteRepository())->layoutRevisions('pages', 'detail');
    foreach ($cases as [$tool, $args]) {
        [, $payload] = comet_test_mcp_tool($token, $tool, $base + $args);
        assert_same(422, $payload['error']['status'] ?? null, $tool . ': ' . json_encode($payload));
        assert_true(isset($payload['error']['details']['path']));
        assert_true(str_contains($payload['error']['recovery'][0], 'get_builder_schema'));
        assert_same($layout['revision'], comet_test_builder_layout($token)['revision']);
    }
    assert_same($before, (new SiteRepository())->layoutRevisions('pages', 'detail'));
});

test('mcp builder stable defaults can be edited and empty layouts are explicit', function (): void {
    $token = comet_test_builder_token();
    (new ContentTypeRepository())->save(['name' => 'pages']);
    $first = comet_test_builder_layout($token);
    assert_same($first, comet_test_builder_layout($token));
    [, $updated] = comet_test_mcp_tool($token, 'update_section', [
        'collection' => 'pages', 'variant' => 'detail', 'revision' => $first['revision'], 'section_id' => $first['sections'][0]['id'], 'patch' => ['props' => ['width' => 'full']],
    ]);
    assert_true(isset($updated['data']['revision']), json_encode($updated));
    [, $empty] = comet_test_mcp_tool($token, 'set_layout', ['collection' => 'pages', 'variant' => 'detail', 'revision' => $updated['data']['revision'], 'sections' => []]);
    assert_same([], $empty['data']['sections']);
    assert_same([], (new SiteRepository())->layout('pages', 'detail'));
    [, $unlisted] = comet_test_mcp_tool($token, 'get_layout', ['collection' => 'pages', 'variant' => 'index']);
    assert_true(isset($unlisted['error']) && str_contains(json_encode($unlisted), 'no list page'), 'List pages that are off do not exist for MCP either.');
    (new SiteRepository())->saveSettings(['routes' => ['pages' => ['index_enabled' => true]]]);
    [, $index] = comet_test_mcp_tool($token, 'get_layout', ['collection' => 'pages', 'variant' => 'index']);
    assert_true(count($index['data']['sections']) > 0);
});

test('mcp builder missing targets and revisions guide the next action', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    foreach ([['get_section', ['section_id' => 'missing']], ['get_element', ['element_id' => 'missing']]] as [$tool, $extra]) {
        [, $payload] = comet_test_mcp_tool($token, $tool, ['collection' => 'pages', 'variant' => 'detail'] + $extra);
        assert_same(404, $payload['error']['status']);
        assert_true(str_contains($payload['error']['recovery'][0], 'get_layout'));
    }
    [, $payload] = comet_test_mcp_tool($token, 'get_layout', ['collection' => 'missing', 'variant' => 'detail']);
    assert_same(404, $payload['error']['status']);
    assert_true(str_contains($payload['error']['recovery'][0], 'list_content_types'));
    foreach ([[], ['revision' => ''], ['revision' => null]] as $extra) {
        [, $payload] = comet_test_mcp_tool($token, 'set_layout', ['collection' => 'pages', 'variant' => 'detail', 'sections' => []] + $extra);
        assert_same(422, $payload['error']['status']);
    }
    assert_same($layout, comet_test_builder_layout($token));
});

test('mcp builder preserves localized props and bindings during partial edits and resets style defaults', function (): void {
    (new \CometCMS\Localization\LanguageSettings())->save(['locales' => ['en', 'de'], 'default_locale' => 'en']);
    [$token, $layout] = comet_test_builder_setup();
    $target = ['collection' => 'pages', 'variant' => 'detail', 'element_id' => 'heading'];
    [, $updated] = comet_test_mcp_tool($token, 'update_element', $target + [
        'revision' => $layout['revision'], 'props' => ['text' => ['type' => 'localized', 'values' => ['en' => 'Hello', 'de' => 'Hallo']]],
        'style' => ['align' => 'center'],
    ]);
    assert_true(isset($updated['data']), json_encode($updated));
    $localized = $updated['data']['element']['props']['text'];
    [, $updated] = comet_test_mcp_tool($token, 'update_element', $target + [
        'revision' => $updated['data']['revision'], 'props' => ['level' => 'h3'], 'style' => ['align' => ''],
    ]);
    assert_same($localized, $updated['data']['element']['props']['text']);
    assert_false(isset($updated['data']['element']['style']['align']));
    [, $updated] = comet_test_mcp_tool($token, 'update_element', $target + [
        'revision' => $updated['data']['revision'], 'props' => ['text' => \CometCMS\Site\SmartValue::reference('title')],
    ]);
    assert_same('reference', $updated['data']['element']['props']['text']['type']);
});

test('mcp builder tools list templates, assign their parts and edit each template\'s content', function (): void {
    [$token] = comet_test_builder_setup();
    [, $listed] = comet_test_mcp_tool($token, 'list_templates', ['collection' => 'pages', 'variant' => 'detail']);
    assert_same([SiteRepository::defaultTemplate()], $listed['data']['templates']);
    assert_same(['default', 'none'], $listed['data']['header_footer_options']);

    [, $saved] = comet_test_mcp_tool($token, 'set_template_parts', ['collection' => 'pages', 'variant' => 'detail', 'header' => 'none']);
    assert_same('none', $saved['data']['templates'][0]['header'], json_encode($saved));

    // Layout tools default to the default template's content; another template's is picked by id.
    $layout = comet_test_builder_layout($token);
    assert_same('default', $layout['template']);
    (new SiteRepository())->createTemplate('pages', 'detail', 'Landing');
    [, $landing] = comet_test_mcp_tool($token, 'get_layout', ['collection' => 'pages', 'variant' => 'detail', 'template' => 'landing']);
    assert_same(['landing', $layout['revision']], [$landing['data']['template'], $landing['data']['revision']], 'A new template starts as a copy.');

    foreach ([['footer' => 'no-such-component'], ['template' => 'missing']] as $extra) {
        [$failed] = comet_test_mcp_tool($token, 'set_template_parts', ['collection' => 'pages', 'variant' => 'detail'] + $extra);
        assert_true($failed['result']['isError'] ?? false, json_encode($failed));
    }
    [$failed] = comet_test_mcp_tool($token, 'get_layout', ['collection' => 'pages', 'variant' => 'detail', 'template' => 'missing']);
    assert_true($failed['result']['isError'] ?? false);
    assert_same('none', (new SiteRepository())->templates('pages', 'detail')[1]['header']);
});

function comet_test_builder_entry_setup(array $actions = ['site.manage', 'content.*']): array
{
    (new ContentTypeRepository())->save(['name' => 'pages', 'fields' => [
        'title' => ['type' => 'text', 'label' => 'Title'],
        'page_content' => ['type' => 'page', 'label' => 'Page content'],
    ]]);
    (new SiteRepository())->saveLayout('pages', 'detail', [
        ['id' => 'intro', 'kind' => 'section', 'name' => 'Intro'],
        ['id' => 'area', 'kind' => 'page_content', 'props' => ['field' => 'page_content'], 'children' => [
            ['id' => 'fallback', 'kind' => 'section', 'children' => [['id' => 'frow', 'kind' => 'row', 'children' => [['id' => 'fcol', 'kind' => 'column', 'children' => [
                ['id' => 'fheading', 'kind' => 'heading', 'props' => ['text' => 'Default']],
            ]]]]]],
        ]],
    ], ['id' => 'test']);
    $entry = \CometCMS\Content\ContentRepository::make()->save('pages', ['title' => 'Home', 'slug' => 'home'], ['id' => 'test'], null, true);

    return [comet_test_mcp_token([['actions' => $actions, 'resources' => ['workspace:default:*']]]), $entry];
}

test('mcp builder edits one entry\'s content with revisions and resets it to the template default', function (): void {
    [$token, $entry] = comet_test_builder_entry_setup();
    $target = ['collection' => 'pages', 'variant' => 'detail', 'entry' => 'home'];

    // The template overview points at the per-entry area instead of a dead end.
    $template = comet_test_builder_layout($token);
    assert_same('page_content', $template['sections'][1]['area']);
    assert_true(str_contains($template['next'], 'entry'));

    // Without content of its own the entry shows the default, with IDs stable across reads.
    [, $read] = comet_test_mcp_tool($token, 'get_layout', $target);
    assert_same(['page_content', 'default', false], [$read['data']['area'], $read['data']['template'], $read['data']['customized']], json_encode($read));
    assert_same($read, comet_test_mcp_tool($token, 'get_layout', $target)[1]);
    $sectionId = $read['data']['sections'][0]['id'];
    assert_true($sectionId !== 'fallback', 'The entry copy has IDs of its own.');
    [, $scoped] = comet_test_mcp_tool($token, 'get_layout', $target + ['section_ids' => [$sectionId], 'mode' => 'summary', 'depth' => 0]);
    assert_same($read['data']['revision'], $scoped['data']['revision']);
    assert_same($sectionId, $scoped['data']['sections'][0]['id']);
    assert_true($scoped['data']['sections'][0]['children_truncated']);
    [, $section] = comet_test_mcp_tool($token, 'get_section', $target + ['section_id' => $sectionId]);
    $headingId = $section['data']['section']['children'][0]['children'][0]['children'][0]['id'];

    // The first write gives the entry its own copy; the template stays as it was.
    [, $updated] = comet_test_mcp_tool($token, 'update_element', $target + ['revision' => $read['data']['revision'], 'element_id' => $headingId, 'props' => ['text' => 'Mine']]);
    assert_same('Mine', $updated['data']['element']['props']['text'] ?? null, json_encode($updated));
    $stored = \CometCMS\Content\ContentRepository::make()->find('pages', (string) $entry['id']);
    assert_true(\CometCMS\Site\LayoutSchema::hasPageContent($stored['page_content'] ?? null));
    assert_same($template['revision'], comet_test_builder_layout($token)['revision']);

    // Stale revisions fail; saving other fields of the entry does not touch its content's revision.
    \CometCMS\Content\ContentRepository::make()->save('pages', ['title' => 'Renamed'], ['id' => 'test'], (string) $entry['id'], true);
    [, $stale] = comet_test_mcp_tool($token, 'set_layout', $target + ['revision' => $read['data']['revision'], 'sections' => []]);
    assert_same(409, $stale['error']['status']);

    // Dropped nodes are rejected, as for templates.
    [, $dropped] = comet_test_mcp_tool($token, 'set_layout', $target + ['revision' => $updated['data']['revision'], 'sections' => [['kind' => 'page_content']]]);
    assert_same(422, $dropped['error']['status'], json_encode($dropped));

    // An empty list resets to the default.
    [, $reset] = comet_test_mcp_tool($token, 'set_layout', $target + ['revision' => $updated['data']['revision'], 'sections' => []]);
    assert_false($reset['data']['customized'], json_encode($reset));
    assert_same($read['data']['revision'], $reset['data']['revision']);
});

test('mcp builder entry content needs content grants, a placed area and the detail variant', function (): void {
    [$token] = comet_test_builder_entry_setup(['site.manage', 'content.read']);
    $target = ['collection' => 'pages', 'variant' => 'detail', 'entry' => 'home'];
    [, $read] = comet_test_mcp_tool($token, 'get_layout', $target);
    assert_true(isset($read['data']['revision']), json_encode($read));
    [, $denied] = comet_test_mcp_tool($token, 'set_layout', $target + ['revision' => $read['data']['revision'], 'sections' => []]);
    assert_same(403, $denied['error']['status']);
    assert_same(['content.update'], $denied['error']['required_permissions'][0]['actions']);

    foreach ([['area' => 'nope'], ['template' => 'default'], ['variant' => 'index']] as $extra) {
        [, $failed] = comet_test_mcp_tool($token, 'get_layout', $extra + $target);
        assert_same(422, $failed['error']['status'], json_encode($failed));
    }
    [, $failed] = comet_test_mcp_tool($token, 'get_layout', ['collection' => 'pages', 'variant' => 'detail', 'area' => 'page_content']);
    assert_same('area', $failed['error']['details']['path'] ?? null, 'An area without entry is not ignored.');
    [, $missing] = comet_test_mcp_tool($token, 'get_layout', ['entry' => 'missing'] + $target);
    assert_same(404, $missing['error']['status']);

    $layout = (new SiteRepository())->layout('pages', 'detail');
    (new SiteRepository())->saveLayout('pages', 'detail', [$layout[0]], ['id' => 'test']);
    [, $unplaced] = comet_test_mcp_tool($token, 'get_layout', $target);
    assert_same('no_entry_content_area', $unplaced['error']['details']['code'], json_encode($unplaced));
    assert_true(str_contains($unplaced['error']['recovery'][0], 'without entry'));
});

test('mcp entry tools leave page content to the builder tools', function (): void {
    [$token, $entry] = comet_test_builder_entry_setup();
    foreach ([['update_entry', ['identifier' => 'home', 'entry' => ['page_content' => []]]], ['create_entry', ['entry' => ['title' => 'New', 'page_content' => []]]]] as [$tool, $args]) {
        [, $payload] = comet_test_mcp_tool($token, $tool, ['collection' => 'pages'] + $args);
        assert_same('page_field', $payload['error']['details']['code'] ?? null, json_encode($payload));
    }
    [, $read] = comet_test_mcp_tool($token, 'get_entry', ['collection' => 'pages', 'identifier' => 'home']);
    assert_same(false, $read['data']['data']['page_content']['own_content'], json_encode($read));
});

test('mcp entry tools reject lossy page payloads without changing customized content or history', function (): void {
    [$token, $entry] = comet_test_builder_entry_setup();
    $target = ['collection' => 'pages', 'variant' => 'detail', 'entry' => 'home'];
    [, $layout] = comet_test_mcp_tool($token, 'get_layout', $target);
    [, $saved] = comet_test_mcp_tool($token, 'set_layout', $target + ['revision' => $layout['data']['revision'], 'sections' => comet_test_builder_sections()]);
    assert_true($saved['data']['customized'] ?? false, json_encode($saved));
    $content = \CometCMS\Content\ContentRepository::make();
    $before = $content->find('pages', (string) $entry['id']);
    $history = $content->revisions('pages', (string) $entry['id']);

    $deep = ['kind' => 'heading', 'props' => ['text' => 'Would be dropped']];
    for ($i = 0; $i <= \CometCMS\Site\LayoutSchema::MAX_ROW_DEPTH; $i++) {
        $deep = ['kind' => 'row', 'children' => [['kind' => 'column', 'children' => [$deep]]]];
    }
    $payloads = [
        [], null, ['nodes' => []],
        ['format_version' => \CometCMS\Site\LayoutSchema::FORMAT_VERSION, 'nodes' => []],
        ['format_version' => \CometCMS\Site\LayoutSchema::FORMAT_VERSION, 'nodes' => [['kind' => 'section', 'children' => [$deep]]]],
        ['format_version' => \CometCMS\Site\LayoutSchema::FORMAT_VERSION, 'nodes' => [['kind' => 'section', 'children' => [['kind' => 'column']]]]],
    ];
    foreach ($payloads as $value) {
        foreach ([['page_content' => $value], ['values' => ['page_content' => $value]]] as $fields) {
            foreach (['update_entry', 'create_entry'] as $tool) {
                // Deliberately omit revision, as in the reported unsafe write path.
                $args = ['collection' => 'pages', 'entry' => ['title' => 'Must not be saved'] + $fields];
                if ($tool === 'update_entry') $args['identifier'] = 'home';
                [$response, $failed] = comet_test_mcp_tool($token, $tool, $args);
                assert_true($response['result']['isError']);
                assert_same(422, $failed['error']['status'], json_encode($failed));
                assert_same('page_field', $failed['error']['details']['code']);
                assert_same(['page_content'], $failed['error']['details']['fields']);
                assert_true(str_contains($failed['error']['recovery'][0], 'revision'));
                assert_same($before, $content->find('pages', (string) $entry['id']));
                assert_same($history, $content->revisions('pages', (string) $entry['id']));
                assert_same(1, count($content->all('pages', true)));
            }
        }
    }

    // Ordinary field updates must not re-normalize or clear omitted page content.
    [, $updated] = comet_test_mcp_tool($token, 'update_entry', ['collection' => 'pages', 'identifier' => 'home', 'entry' => ['title' => 'Renamed']]);
    assert_same('Renamed', $updated['data']['title'] ?? null, json_encode($updated));
    assert_same($before['page_content'], $content->find('pages', (string) $entry['id'])['page_content']);
    [, $after] = comet_test_mcp_tool($token, 'get_layout', $target);
    assert_same($saved['data']['revision'], $after['data']['revision']);
});

test('mcp builder placement errors name the node and how to place it', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $base = ['collection' => 'pages', 'variant' => 'detail', 'revision' => $layout['revision']];
    $column = static fn(array $children): array => ['kind' => 'column', 'children' => $children];
    $cases = [
        // The two placements a model lost cards to before: a column in a column, an element in a section.
        [['kind' => 'section', 'children' => [['kind' => 'row', 'children' => [$column([$column([])])]]]], 'sections.0.children.0.children.0.children.0', 'Wrap it in row.'],
        [['kind' => 'section', 'children' => [['kind' => 'heading']]], 'sections.0.children.0', 'Wrap it in row > column.'],
        [['kind' => 'heading'], 'sections.0', 'cannot be placed at the top level'],
        [['kind' => 'section', 'children' => [['kind' => 'row', 'children' => [$column([['kind' => 'collection', 'children' => [$column([]), $column([])]]])]]]], 'sections.0.children.0.children.0.children.0.children', 'single card'],
    ];
    foreach ($cases as [$section, $path, $message]) {
        [, $payload] = comet_test_mcp_tool($token, 'set_layout', $base + ['sections' => [$section]]);
        assert_same($path, $payload['error']['details']['path'] ?? null, json_encode($payload));
        assert_true(str_contains($payload['error']['message'], $message), $payload['error']['message']);
    }
    $nested = ['kind' => 'heading'];
    for ($i = 0; $i < 5; $i++) $nested = ['kind' => 'row', 'children' => [$column([$nested])]];
    [, $payload] = comet_test_mcp_tool($token, 'set_layout', $base + ['sections' => [['kind' => 'section', 'children' => [$nested]]]]);
    assert_true(str_contains($payload['error']['message'] ?? '', 'Rows nest at most'), json_encode($payload));
    [, $payload] = comet_test_mcp_tool($token, 'set_layout', $base + ['sections' => [['kind' => 'page_content', 'props' => ['field' => 'a']], ['kind' => 'page_content', 'props' => ['field' => 'a']]]]);
    assert_same('sections.1.props.field', $payload['error']['details']['path'] ?? null, json_encode($payload));
    assert_same($layout['revision'], comet_test_builder_layout($token)['revision']);
});

test('mcp builder value errors say which values are allowed', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    $base = ['collection' => 'pages', 'variant' => 'detail', 'revision' => $layout['revision'], 'element_id' => 'heading'];
    foreach ([
        [['props' => ['level' => 'h9']], 'h1, h2'],
        [['props' => [], 'style' => ['background_blur' => 99]], 'from 0 to 40'],
        [['props' => ['text' => str_repeat('x', 301)]], 'at most 300 characters'],
    ] as [$args, $message]) {
        [, $payload] = comet_test_mcp_tool($token, 'update_element', $base + $args);
        assert_true(str_contains($payload['error']['message'] ?? '', $message), json_encode($payload));
    }
    [, $payload] = comet_test_mcp_tool($token, 'update_element', ['element_id' => 'body', 'props' => ['html' => '<p onclick="x">Hi</p>']] + $base);
    assert_true(str_contains($payload['error']['message'] ?? '', 'h1, h2'), json_encode($payload));
});

test('mcp builder schema sends style values and icons once and kind details on demand', function (): void {
    $token = comet_test_builder_token();
    [, $overview] = comet_test_mcp_tool($token, 'get_builder_schema');
    $overview = $overview['data'];
    $spacing = array_values(array_filter(array_keys($overview['style']), static fn(string $keys): bool => in_array('padding_top', explode(', ', $keys), true)))[0];
    assert_true(str_contains($spacing, 'margin_bottom'), 'Keys with equal values are listed together.');
    assert_same('enum', $overview['style'][$spacing]['type']);
    assert_true(in_array('heading', array_merge(...array_map(static fn(string $keys): array => explode(', ', $keys), array_keys($overview['children']))), true));
    assert_false(str_contains(json_encode(array_keys($overview['children'])), 'modal_title'), 'Modal template elements cannot be placed in layouts.');
    assert_false(in_array('', $overview['icons'], true));

    [, $heading] = comet_test_mcp_tool($token, 'get_builder_schema', ['kind' => 'heading']);
    assert_true(in_array('padding_top', $heading['data']['style'], true));
    assert_false(isset($heading['data']['style']['padding_top']), 'A kind lists style key names only.');
    [, $many] = comet_test_mcp_tool($token, 'get_builder_schema', ['kind' => ['button', 'icon']]);
    assert_same(['button', 'icon'], array_keys($many['data']['kinds']));
    assert_same('icons, or "" for none', $many['data']['kinds']['button']['props']['icon']['options']);
    assert_same('icons', $many['data']['kinds']['icon']['props']['icon']['options']);
    [, $unknown] = comet_test_mcp_tool($token, 'get_builder_schema', ['kind' => ['heading', 'modal_title']]);
    assert_same(422, $unknown['error']['status']);
});
