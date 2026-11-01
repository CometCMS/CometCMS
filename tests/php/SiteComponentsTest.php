<?php

declare(strict_types=1);

use CometCMS\Cache\ApiCache;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\ValidationException;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\SiteRepository;
use CometCMS\Site\SmartValue;
use CometCMS\Storage\JsonStore;

/** A section with one column holding the given elements. */
function comet_component_test_section(array $elements, string $prefix = 'x'): array
{
    return ['id' => $prefix . 'sec', 'kind' => 'section', 'children' => [
        ['id' => $prefix . 'row', 'kind' => 'row', 'children' => [
            ['id' => $prefix . 'col', 'kind' => 'column', 'children' => $elements],
        ]],
    ]];
}

function comet_component_test_instance(string $id, string $component, string $root = 'section'): array
{
    return ['id' => $id, 'kind' => 'component', 'props' => ['component' => $component, 'root' => $root]];
}

test('repeated navigation components give every menu its own matching toggle and label', function (): void {
    $site = comet_site_test_setup();
    $component = $site->createComponent('Navigation', [comet_component_test_section([LayoutSchema::node('menu', ['menu' => 'main'])])]);
    $site->saveRegion('header', [comet_component_test_instance('header-nav', $component['id'])]);
    $site->saveLayout('blog', 'detail', [
        comet_component_test_instance('first-nav', $component['id']),
        comet_component_test_instance('second-nav', $component['id']),
    ]);
    $html = comet_site_test_get('/blog/hello')['body'];
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $toggles = $xpath->query('//input[@class="c-menu-toggle"]');
    assert_same(3, $toggles->length);
    $ids = [];
    foreach ($toggles as $toggle) {
        $id = $toggle->getAttribute('id');
        assert_false(in_array($id, $ids, true), 'Each rendered menu needs a unique toggle ID.');
        $ids[] = $id;
        assert_same($id, $xpath->query('label[@class="c-menu-button"]', $toggle->parentNode)->item(0)->getAttribute('for'));
    }
});

test('component instances are placed like their root kind and keep only area content as children', function (): void {
    $nodes = LayoutSchema::normalize([
        comet_component_test_instance('i1', 'cabc', 'section'),
        comet_component_test_instance('i2', 'cabc', 'column'),
        ['id' => 'i3', 'kind' => 'component', 'props' => ['component' => 'cabc', 'root' => 'heading']],
        ['id' => 's1', 'kind' => 'section', 'children' => [
            comet_component_test_instance('i4', 'cabc', 'row'),
            ['id' => 'r1', 'kind' => 'row', 'children' => [
                comet_component_test_instance('i5', 'cabc', 'column') + ['children' => [['id' => 'h1', 'kind' => 'heading']]],
                comet_component_test_instance('i6', 'cabc', 'section'),
            ]],
        ]],
    ]);

    assert_same(['i1', 's1'], array_column($nodes, 'id'));
    assert_same(['i4', 'r1'], array_column($nodes[1]['children'], 'id'));
    assert_same(['i5'], array_column($nodes[1]['children'][1]['children'], 'id'));
    assert_same([], $nodes[1]['children'][1]['children'][0]['children']);
    assert_same(['mobile' => 12, 'tablet' => 0, 'desktop' => 0], $nodes[1]['children'][1]['children'][0]['props']['span']);
    assert_same([], $nodes[0]['style']);
});

test('components are created from one structure node and keep their root kind', function (): void {
    $site = comet_site_test_setup();
    $column = ['id' => 'c1', 'kind' => 'column', 'props' => ['span' => ['mobile' => 12, 'tablet' => 6]], 'children' => [['id' => 'h1', 'kind' => 'heading', 'props' => ['text' => 'Shared']]]];

    $component = $site->createComponent('  Promo  ', [$column]);
    assert_same('Promo', $component['name']);
    assert_same('column', $component['root']);
    assert_same('', $component['type']);
    assert_same('promo', $component['id'], 'Components are named after their name.');
    assert_same('Shared', $site->component($component['id'])['nodes'][0]['children'][0]['props']['text']);
    assert_same([['id' => $component['id'], 'name' => 'Promo', 'root' => 'column', 'type' => '']], array_map(
        static fn(array $item): array => array_intersect_key($item, ['id' => 1, 'name' => 1, 'root' => 1, 'type' => 1]),
        $site->components(),
    ));

    assert_throws(ValidationException::class, fn() => $site->createComponent('', [$column]));
    assert_throws(ValidationException::class, fn() => $site->createComponent('Two', [$column, $column]));
    assert_throws(ValidationException::class, fn() => $site->createComponent('Heading', [['id' => 'h', 'kind' => 'heading']]));
    assert_throws(ValidationException::class, fn() => $site->saveComponent($component['id'], [comet_component_test_section([])]), 'The root kind cannot change.');

    $saved = $site->saveComponent($component['id'], [['kind' => 'column', 'children' => [['kind' => 'text']]], ['kind' => 'column']]);
    assert_same(1, count($saved['nodes']), 'Only the root node is kept.');
    assert_same('text', $saved['nodes'][0]['children'][0]['kind']);
    assert_same('Renamed', $site->renameComponent($component['id'], 'Renamed')['name']);
    assert_same(1, count($site->componentRevisions($component['id'])));
});

test('component icons persist through renaming, tree saves and history restores', function (): void {
    $site = comet_site_test_setup();
    $component = $site->createComponent('Promo', [comet_component_test_section([['kind' => 'heading']])]);
    $id = $component['id'];
    assert_same('', $component['icon']);

    $updated = $site->patchComponent($id, ['icon' => 'mdi:star-outline']);
    assert_same('mdi:star-outline', (new SiteRepository())->component($id)['icon']);
    assert_same('mdi:star-outline', $site->components()[0]['icon']);
    assert_same($component['nodes'], $updated['nodes']);
    assert_same([], $site->componentRevisions($id));
    assert_same('mdi:star-outline', $site->renameComponent($id, 'Renamed')['icon']);
    $saved = $site->saveComponent($id, [comet_component_test_section([['kind' => 'text']])]);
    assert_same('mdi:star-outline', $saved['icon']);
    $revision = $site->componentRevisions($id)[0];
    $site->patchComponent($id, ['icon' => 'mdi:heart-outline']);
    assert_same('mdi:heart-outline', $site->restoreComponentRevision($id, $revision['id'])['component']['icon']);
    assert_same('', $site->patchComponent($id, ['icon' => ''])['icon']);

    // Definitions from before icon metadata was introduced retain their default.
    $path = $site->workspace()->path('site') . '/theme/components/' . $id . '.json';
    $stored = json_decode(file_get_contents($path), true);
    unset($stored['icon']);
    file_put_contents($path, json_encode($stored));
    assert_same('', (new SiteRepository())->component($id)['icon']);
});

test('component metadata rejects invalid icons without partially saving a rename', function (): void {
    $site = comet_site_test_setup();
    $component = $site->createComponent('Promo', [comet_component_test_section([])]);
    foreach ([null, [], 42, '<svg/>', 'invalid icon', 'mdi:star-outline extra', str_repeat('x', 121)] as $icon) {
        assert_throws(ValidationException::class, fn() => $site->patchComponent($component['id'], ['name' => 'Changed', 'icon' => $icon]));
        assert_same('Promo', $site->component($component['id'])['name']);
        assert_same('', $site->component($component['id'])['icon']);
    }
});

test('component icon API accepts icon-only patches and enforces site permissions', function (): void {
    $seed = '$site = new \\CometCMS\\Site\\SiteRepository(); $site->createComponent("Shared", [["kind" => "section"]]);';
    $action = '(new \\CometCMS\\Controllers\\Admin\\SiteController(new \\CometCMS\\Core\\Http()))->patchComponent("shared");';
    [$status, $json] = comet_admin_request('admin', $seed, 'PATCH', '/admin/api/site/components/shared', $action, ['icon' => 'mdi:star-outline']);
    assert_same(200, $status);
    assert_same('Shared', $json['data']['name']);
    assert_same('mdi:star-outline', $json['data']['icon']);

    [$status, $json] = comet_admin_request('admin', $seed, 'PATCH', '/admin/api/site/components/shared', $action, ['icon' => '<svg/>']);
    assert_same(422, $status);

    [$status] = comet_admin_request('editor', '$users->create("editor", "secret-password", "editor");' . $seed, 'PATCH', '/admin/api/site/components/shared', $action, ['icon' => 'mdi:star-outline']);
    assert_same(403, $status);
});

test('components show the fields of whichever entry they are placed for', function (): void {
    $site = comet_site_test_setup();
    $section = comet_component_test_section([
        ['id' => 'h1', 'kind' => 'heading', 'props' => ['text' => SmartValue::reference('title')]],
        ['id' => 't1', 'kind' => 'text', 'props' => ['html' => SmartValue::reference('intro')]],
    ]);

    // Without a type, or with an unknown one, it is simply not tied to one.
    assert_same('', $site->createComponent('Untyped', [$section])['type']);
    assert_same('', $site->createComponent('Unknown', [$section], 'unknown')['type']);
    $component = $site->createComponent('Title', [$section], 'blog');
    assert_same('blog', $component['type'], 'The type stays as the one to preview with.');

    $site->saveLayout('blog', 'detail', [comet_component_test_instance('i1', $component['id'])]);
    $site->saveLayout('about', 'detail', [comet_component_test_instance('i2', $component['id'])]);
    $blog = comet_site_test_get('/blog/hello')['body'];
    assert_true(str_contains($blog, 'Hello &lt;script&gt;alert(1)&lt;/script&gt;'), 'References resolve against the page entry.');
    $about = comet_site_test_get('/about')['body'];
    assert_true(str_contains($about, 'About us') && str_contains($about, 'We build things.'), 'The same component shows another type\'s entry.');
});

test('the component builder previews with entries of every public type', function (): void {
    $seed = <<<'PHP'
$types = new \CometCMS\Content\ContentTypeRepository();
$types->save(['name' => 'blog', 'fields' => ['body' => ['type' => 'markdown']]]);
$types->save(['name' => 'about', 'fields' => ['intro' => ['type' => 'textarea']]]);
$types->save(['name' => 'notes', 'visibility' => 'private', 'fields' => []]);
$content = \CometCMS\Content\ContentRepository::make();
$content->save('blog', ['title' => 'Post', 'slug' => 'post', 'status' => 'published'], ['id' => 'admin']);
$content->save('about', ['title' => 'Team', 'slug' => 'team', 'status' => 'published'], ['id' => 'admin']);
$content->save('notes', ['title' => 'Secret', 'status' => 'published'], ['id' => 'admin']);
(new \CometCMS\Site\SiteRepository())->createComponent('Card', [['kind' => 'section']], 'about');
PHP;
    $open = static function (string $query = '') use ($seed): array {
        [$status, $body] = comet_admin_request('admin', $seed, 'GET', '/admin/api/site/components/card' . $query,
            '(new \CometCMS\Controllers\Admin\SiteController(new \CometCMS\Core\Http()))->component("card");');
        assert_same(200, $status, json_encode($body));
        return $body['data'];
    };

    $data = $open();
    // The component's own type comes first; private types never show.
    assert_same([['about', 'Team'], ['blog', 'Post']], array_map(static fn(array $sample): array => [$sample['type'], $sample['title']], $data['samples']));
    assert_same('about', $data['type']['name']);
    // Each request seeds afresh, so ask by slug; the answer names the entry by its stable ID.
    $data = $open('?entry=post');
    assert_same('Post', array_column($data['samples'], 'title', 'id')[$data['sample']] ?? null);
    assert_same('blog', $data['type']['name'], 'The requested entry decides the type to preview with.');
});

test('components cannot contain themselves but hold components of any type', function (): void {
    $site = comet_site_test_setup();
    $section = static fn(array $children): array => [['kind' => 'section', 'children' => $children]];
    $row = $site->createComponent('Row', [['kind' => 'row', 'children' => [['kind' => 'column']]]]);
    $outer = $site->createComponent('Outer', $section([comet_component_test_instance('i1', $row['id'], 'row')]));

    // Outer places Row, so Row may not place Outer, and nothing places itself.
    assert_throws(ValidationException::class, fn() => $site->saveComponent($row['id'], [['kind' => 'row', 'children' => [comet_component_test_instance('i2', $outer['id'], 'column')]]]));
    assert_throws(ValidationException::class, fn() => $site->saveComponent($outer['id'], $section([comet_component_test_instance('i3', $outer['id'], 'row')])));

    $typed = $site->createComponent('Typed', [['kind' => 'row', 'children' => [['kind' => 'column', 'children' => [['kind' => 'heading', 'props' => ['text' => SmartValue::reference('title')]]]]]]], 'blog');
    $site->saveComponent($outer['id'], $section([comet_component_test_instance('i4', $typed['id'], 'row')]));
    $aboutOuter = $site->createComponent('About outer', $section([]), 'about');
    $site->saveComponent($aboutOuter['id'], $section([comet_component_test_instance('i5', $typed['id'], 'row')]));
});

test('deleting a component detaches all placements and removes its history', function (): void {
    $site = comet_site_test_setup();
    $component = $site->createComponent('Promo', [comet_component_test_section([['kind' => 'heading']])]);
    $component = $site->saveComponent($component['id'], [comet_component_test_section([['kind' => 'text', 'props' => ['html' => '<p>Keep this content</p>']]])]);
    $site->saveRegion('footer', [comet_component_test_instance('i1', $component['id']) + ['name' => 'Footer promo']]);
    $site->saveLayout('blog', 'index', [comet_component_test_instance('i2', $component['id'])]);
    assert_same([
        ['target' => 'layout', 'type' => 'blog', 'variant' => 'index', 'template' => 'default'],
        ['target' => 'region', 'region' => 'footer'],
    ], $site->componentUsages($component['id']));
    $cache = ApiCache::fromConfig();
    $key = $cache->key('site|example.test|/blog', '');
    $cache->put($key, ['status' => 200, 'body' => 'cached', 'content_type' => 'text/html']);
    $site->deleteComponent($component['id']);
    assert_null($site->component($component['id']));
    assert_same([], $site->componentUsages($component['id']));
    assert_same([], (new JsonStore(comet_test_workspace_path() . '/revisions/site'))->all('components', $component['id']));
    assert_null($cache->get($key));

    $copies = [$site->region('footer')[0], $site->layout('blog', 'index')[0]];
    assert_same('Footer promo', $copies[0]['name']);
    $childIds = [];
    foreach ($copies as $copy) {
        assert_same('section', $copy['kind']);
        assert_same('<p>Keep this content</p>', $copy['children'][0]['children'][0]['children'][0]['props']['html']);
        assert_false(in_array($copy['children'][0]['id'], $childIds, true), 'Each placement has its own node ids.');
        assert_false($copy['children'][0]['id'] === $component['nodes'][0]['children'][0]['id']);
        $childIds[] = $copy['children'][0]['id'];
    }
    assert_true(str_contains(comet_site_test_get('/blog')['body'], 'Keep this content'));
    assert_false(str_contains(comet_site_test_get('/blog')['body'], 'Missing component'));
});

test('deleting a nested column component preserves widths and detaches saved history', function (): void {
    $site = comet_site_test_setup();
    $column = $site->createComponent('Column', [['kind' => 'column', 'props' => ['span' => ['mobile' => 12, 'tablet' => 6]], 'style' => ['background' => 'primary'], 'children' => [['kind' => 'heading', 'props' => ['text' => 'Kept heading']]]]]);
    $instance = comet_component_test_instance('placed-column', $column['id'], 'column');
    $instance['props']['span'] = ['mobile' => 12, 'tablet' => 4, 'desktop' => 3];
    $outer = $site->createComponent('Outer', [['kind' => 'section', 'children' => [['kind' => 'row', 'children' => [$instance, array_replace($instance, ['id' => 'second-column'])]]]]]);
    $site->saveComponent($outer['id'], $outer['nodes']);
    $site->saveLayout('blog', 'detail', [comet_component_test_instance('outer-placement', $outer['id'])]);
    $site->saveLayout('about', 'detail', [['kind' => 'section', 'children' => [['kind' => 'row', 'children' => [$instance]]]]]);
    $site->saveLayout('about', 'detail', []);
    $site->saveRegion('header', [['kind' => 'section', 'children' => [['kind' => 'row', 'children' => [$instance]]]]]);
    $site->saveRegion('header', []);

    $site->deleteComponent($column['id']);

    $detached = $site->component($outer['id'])['nodes'][0]['children'][0]['children'][0];
    assert_same('column', $detached['kind']);
    assert_same('placed-column', $detached['id']);
    assert_same($instance['props']['span'], $detached['props']['span']);
    assert_same($column['nodes'][0]['style'], $detached['style']);
    assert_same('Kept heading', $detached['children'][0]['props']['text']);
    assert_same([$outer['id']], LayoutSchema::componentRefs($site->layout('blog', 'detail')), 'Other component links stay intact.');

    $revisions = new JsonStore(comet_test_workspace_path() . '/revisions/site');
    foreach ([['content', 'about', 'detail', 'default'], ['regions', 'header'], ['components', $outer['id']]] as $path) {
        foreach ($revisions->all(...$path) as $revision) {
            assert_same([], LayoutSchema::componentRefs($revision['snapshot']['nodes']));
        }
    }
    $savedLayout = array_values(array_filter($revisions->all('content', 'about', 'detail', 'default'), static fn(array $revision): bool => $revision['snapshot']['nodes'] !== [] && $revision['snapshot']['customized']))[0];
    $restored = $site->restoreLayoutRevision('about', 'detail', $savedLayout['id']);
    assert_same('Kept heading', $restored['nodes'][0]['children'][0]['children'][0]['children'][0]['props']['text']);
    assert_false(str_contains(comet_site_test_get('/blog/hello')['body'], 'Missing component'));
});

test('component deletion API detaches used components and returns the definition for open drafts', function (): void {
    $seed = <<<'PHP'
$site = new \CometCMS\Site\SiteRepository();
$component = $site->createComponent('Shared', [['kind' => 'section', 'children' => [['kind' => 'row']]]]);
$site->saveRegion('header', [['id' => 'placement', 'kind' => 'component', 'props' => ['component' => $component['id'], 'root' => 'section']]]);
PHP;
    $action = '(new \\CometCMS\\Controllers\\Admin\\SiteController(new \\CometCMS\\Core\\Http()))->deleteComponent($component[\'id\']);';
    [$status, $json] = comet_admin_request('admin', $seed, 'DELETE', '/admin/api/site/components/shared', $action);
    assert_same(200, $status);
    assert_true($json['data']['deleted']);
    assert_same('Shared', $json['data']['component']['name']);
    assert_same('section', $json['data']['component']['nodes'][0]['kind']);

    [$status] = comet_admin_request('editor', '$users->create(\'editor\', \'secret-password\', \'editor\');' . $seed, 'DELETE', '/admin/api/site/components/shared', $action);
    assert_same(403, $status);
});

test('component instances render their component once per placement', function (): void {
    $site = comet_site_test_setup();
    $component = $site->createComponent('Promo', [comet_component_test_section([['id' => 'inner', 'kind' => 'heading', 'props' => ['text' => 'Shared promo']]])]);
    $column = $site->createComponent('Aside', [['kind' => 'column', 'children' => [['kind' => 'text', 'props' => ['html' => '<p>Aside text</p>']]]]]);
    $site->saveLayout('blog', 'detail', [
        comet_component_test_instance('i1', $component['id']),
        ['id' => 's1', 'kind' => 'section', 'children' => [['id' => 'r1', 'kind' => 'row', 'children' => [
            ['id' => 'c1', 'kind' => 'column'],
            ['kind' => 'component', 'id' => 'i2', 'props' => ['component' => $column['id'], 'root' => 'column', 'span' => ['mobile' => 12, 'tablet' => 4]]],
        ]]]],
        comet_component_test_instance('i3', $component['id']),
        comet_component_test_instance('i4', 'cmissing00'),
    ]);

    $html = comet_site_test_get('/blog/hello')['body'];
    assert_same(2, substr_count($html, 'Shared promo'));
    assert_true(str_contains($html, 'c-t-col-4'), 'Column instances use their own span.');
    assert_true(str_contains($html, 'Aside text'));
    assert_false(str_contains($html, 'data-node'));
    assert_false(str_contains($html, 'Missing component'));

    $updated = $site->saveComponent($component['id'], [comet_component_test_section([['kind' => 'heading', 'props' => ['text' => 'Edited promo']]])]);
    $html = comet_site_test_get('/blog/hello')['body'];
    assert_same(2, substr_count($html, 'Edited promo'), 'Editing the component changes every placement.');

    $context = comet_site_test_context();
    $context->preview = true;
    $context->annotate = true;
    $type = (new ContentTypeRepository())->find('blog');
    $html = (new PageRenderer($context))->layoutPreview($type, 'detail', null);
    assert_true(str_contains($html, '<section data-node="i1" data-kind="component" data-component="' . $updated['id'] . '" data-component-root="section"'));
    assert_true(str_contains($html, 'data-node="i3"'));
    assert_false(str_contains($html, 'data-node="' . $updated['nodes'][0]['id'] . '"'), 'Inner nodes are not annotated.');
    assert_true(str_contains($html, 'data-node="i4"') && str_contains($html, 'Missing component'));
});

test('component previews and overrides reach nested instances, and saving clears the cache', function (): void {
    $site = comet_site_test_setup();
    $inner = $site->createComponent('Inner', [['kind' => 'row', 'children' => [['kind' => 'column', 'children' => [['kind' => 'heading', 'props' => ['text' => 'Saved inner']]]]]]]);
    $outer = $site->createComponent('Outer', [['kind' => 'section', 'children' => [comet_component_test_instance('i1', $inner['id'], 'row')]]]);
    $site->saveLayout('blog', 'detail', [comet_component_test_instance('i2', $outer['id'])]);

    $context = comet_site_test_context();
    $context->preview = true;
    $context->annotate = true;
    $edited = $inner;
    $edited['nodes'] = LayoutSchema::normalizeComponent([['kind' => 'row', 'children' => [['kind' => 'column', 'children' => [['kind' => 'heading', 'props' => ['text' => 'Unsaved inner']]]]]]], 'row');
    $context->override('component:' . $inner['id'], $edited);
    $renderer = new PageRenderer($context);

    $html = $renderer->layoutPreview((new ContentTypeRepository())->find('blog'), 'detail', null);
    assert_true(str_contains($html, 'Unsaved inner'));
    $preview = $renderer->componentPreview($edited, null, null);
    assert_true(str_contains($preview, '<section class="c-section c-section-contained c-pt-xl c-pb-xl">'), 'Rows are previewed inside a section.');
    assert_true(str_contains($preview, 'data-node="' . $edited['nodes'][0]['id'] . '"'), 'The edited component is annotated.');

    $cache = ApiCache::fromConfig();
    $key = $cache->key('site|example.test|/blog/hello', '');
    $cache->put($key, ['status' => 200, 'body' => 'cached', 'content_type' => 'text/html']);
    (new SiteRepository())->saveComponent($inner['id'], $edited['nodes']);
    assert_null($cache->get($key));
});

test('instances set only the props their component marks editable', function (): void {
    $site = comet_site_test_setup();
    $component = $site->createComponent('Promo', [comet_component_test_section([
        ['id' => 'title', 'kind' => 'heading', 'props' => ['text' => 'Shared title'], 'editable' => ['text', 'nope']],
        ['id' => 'note', 'kind' => 'heading', 'props' => ['text' => 'Fixed note']],
    ])]);
    assert_same(['text'], $component['nodes'][0]['children'][0]['children'][0]['children'][0]['editable']);

    $site->saveLayout('blog', 'detail', [
        comet_component_test_instance('own', $component['id']) + ['overrides' => [
            'title' => ['text' => 'Own title'],
            'note' => ['text' => 'Not allowed'],
            '../bad' => ['text' => 'Dropped'],
        ]],
        comet_component_test_instance('plain', $component['id']),
    ]);
    $saved = $site->layout('blog', 'detail');
    assert_same(['title', 'note'], array_keys($saved[0]['overrides']));
    assert_false(isset($saved[1]['overrides']));

    $html = comet_site_test_get('/blog/hello')['body'];
    assert_true(str_contains($html, 'Own title'));
    assert_true(str_contains($html, 'Shared title'));
    assert_same(2, substr_count($html, 'Fixed note'));
    assert_false(str_contains($html, 'Not allowed'));
});

test('instance values are checked against the prop they replace', function (): void {
    $tree = [['id' => 'h', 'kind' => 'heading', 'props' => LayoutSchema::node('heading', ['level' => 'h2'])['props'], 'editable' => ['level'], 'children' => []]];

    assert_same('h4', LayoutSchema::applyOverrides($tree, ['h' => ['level' => 'h4']])[0]['props']['level']);
    assert_same(LayoutSchema::node('heading')['props']['level'], LayoutSchema::applyOverrides($tree, ['h' => ['level' => '<script>']])[0]['props']['level']);
});
