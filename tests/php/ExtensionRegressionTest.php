<?php

declare(strict_types=1);

use CometCMS\Content\ContentRepository;
use CometCMS\Extensions\Extension;
use CometCMS\Extensions\ExtensionReview;
use CometCMS\Extensions\ExtensionException;
use CometCMS\Extensions\ExtensionRegistry;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\SiteRepository;
use CometCMS\Site\SiteSearch;

test('extension regression preserves long translated content bindings and editable props through disable and removal', function (): void {
    $site = comet_site_test_setup();
    $registry = $site->blocks();
    $files = comet_block_single([
        'message' => ['type' => 'text', 'label' => 'Message', 'max' => 20000],
        'body' => ['type' => 'richtext', 'label' => 'Body'],
        'heading' => ['type' => 'string', 'label' => 'Heading'],
        'items' => ['type' => 'list', 'label' => 'Items', 'fields' => ['link' => ['type' => 'link', 'label' => 'Link']]],
    ], '<div>{{message}}{{{body}}}<slot></slot></div>');
    $registry->installFiles($files);
    $block = LayoutSchema::node('block:test/thing', [
        'message' => str_repeat('a', 18000), 'body' => '<p>' . str_repeat('b', 40000) . '</p>',
        'heading' => ['type' => 'template', 'parts' => array_fill(0, 500, 'x')],
        'items' => ['type' => 'localized', 'values' => ['en' => [['link' => ['type' => 'url', 'url' => '/work', 'new_tab' => true]]]]],
    ], [], [LayoutSchema::node('text', ['html' => '<p>Child</p>'])]);
    $block['editable'] = ['message', 'body'];
    $component = $site->createComponent('Preserved', [LayoutSchema::node('column', [], [], [$block])]);
    $before = $site->component($component['id'])['nodes'];
    foreach (['disable', 'delete'] as $action) {
        if ($action === 'disable') $registry->setEnabled('test/kit', false);
        else $registry->delete('test/kit');
        $site = new SiteRepository();
        assert_same($before, $site->component($component['id'])['nodes']);
        $site->saveComponent($component['id'], $site->component($component['id'])['nodes']);
        if ($action === 'disable') $registry->setEnabled('test/kit', true);
        else $registry->installFiles($files);
        assert_same($before, (new SiteRepository())->component($component['id'])['nodes']);
    }
});

test('extension regression warns before tightening prop limits including list fields', function (): void {
    $registry = ExtensionRegistry::active();
    $props = [
        'quantity' => ['type' => 'int', 'label' => 'Quantity', 'min' => 0, 'max' => 10],
        'message' => ['type' => 'text', 'label' => 'Message', 'max' => 100],
        'items' => ['type' => 'list', 'label' => 'Items', 'max' => 10, 'fields' => ['label' => ['type' => 'string', 'label' => 'Label', 'max' => 100]]],
    ];
    $registry->installFiles(comet_block_single($props, '<div>{{quantity}}{{message}}</div>'));
    $props['quantity']['max'] = 5;
    $props['message']['max'] = 50;
    $props['items']['max'] = 5;
    $props['items']['fields']['label']['max'] = 50;
    $update = comet_block_single($props, '<div>{{quantity}}{{message}}</div>');
    try {
        $registry->installFiles($update);
        throw new RuntimeException('Expected confirmation.');
    } catch (ExtensionReview $e) {
        assert_same(4, count($e->changes));
    }
    assert_same(10, $registry->get('block:test/thing')['props']['quantity']['max']);
    $registry->installFiles($update, acceptChanges: true);
    // Widening the limits does not need confirmation.
    $props['quantity']['max'] = 20;
    $props['message']['max'] = 200;
    $props['items']['max'] = 20;
    $props['items']['fields']['label']['max'] = 200;
    assert_same([], $registry->installFiles(comet_block_single($props, '<div>{{quantity}}{{message}}</div>'))['changes']);
});

test('extension regression accepts bundled default exports and rejects nested list iteration', function (): void {
    foreach (["function mount() {}\nexport {\n  mount as default\n};", 'function n(){}export{n as default};'] as $source) {
        $compiled = Extension::compile(comet_block_single([], '<div>Bundled</div>', ['blocks/thing/view.js' => $source]));
        assert_true($compiled['blocks']['block:test/thing']['script'] !== '');
    }
    $props = ['items' => ['type' => 'list', 'label' => 'Items', 'fields' => ['label' => ['type' => 'string', 'label' => 'Label']]]];
    assert_throws(ExtensionException::class, fn() => Extension::compile(comet_block_single($props, '{{#items}}{{#items}}{{label}}{{/items}}{{/items}}')));
    // Scalar sections inside a list and separate list sections remain valid.
    Extension::compile(comet_block_single($props, '{{#items}}{{#label}}{{label}}{{/label}}{{/items}}{{#items}}{{label}}{{/items}}'));
});

test('extension regression rejects skipped missing and incompatible template component references', function (): void {
    $registry = ExtensionRegistry::active();
    $files = [
        'comet-extension.json' => '{"api_version":1,"name":"test/seeds","title":"Seeds","version":"1.0.0"}',
        'content-types/project.json' => '{"name":"project","label":"Projects"}',
        'components/hero.json' => json_encode(['name' => 'Hero', 'root' => 'section', 'type' => '', 'nodes' => [['kind' => 'section']]]),
        'templates/project/detail.json' => json_encode(['templates' => [SiteRepository::defaultTemplate()], 'layouts' => ['default' => [['kind' => 'component', 'props' => ['component' => 'hero', 'root' => 'section']]]]]),
    ];
    assert_throws(ExtensionException::class, fn() => $registry->installFiles($files, seeds: ['components/hero' => 'skip']));
    assert_null($registry->extension('test/seeds'));
    $registry->installFiles($files, seeds: []);
    assert_same('hero', (new SiteRepository())->layout('project', 'detail')[0]['props']['component']);
    $bad = json_decode($files['templates/project/detail.json'], true);
    $bad['layouts']['default'][0]['props']['root'] = 'row';
    assert_throws(ExtensionException::class, fn() => $registry->installFiles(array_replace($files, ['templates/project/detail.json' => json_encode($bad)]), seeds: []));
});

test('extension regression indexes translated text rich text and list content in entry page fields', function (): void {
    $site = comet_site_test_setup();
    $site->blocks()->installFiles(comet_block_single([
        'message' => ['type' => 'text', 'label' => 'Message'],
        'body' => ['type' => 'richtext', 'label' => 'Body'],
        'items' => ['type' => 'list', 'label' => 'Items', 'fields' => ['label' => ['type' => 'string', 'label' => 'Label']]],
    ], '<div>{{message}}{{{body}}}{{#items}}<p>{{label}}</p>{{/items}}</div>'));
    $block = LayoutSchema::node('block:test/thing', [
        'message' => ['type' => 'localized', 'values' => ['en' => 'Unicorn consultancy', 'de' => 'Einhorn Beratung']],
        'body' => '<p>Migration &amp; hosting</p>', 'items' => [['label' => 'Neptune observatory']],
    ]);
    $nodes = comet_site_search_page($block);
    assert_same('Unicorn consultancy Migration & hosting Neptune observatory', LayoutSchema::text($nodes, 'en', 'en'));
    assert_true(str_contains(LayoutSchema::text($nodes, 'de', 'en'), 'Einhorn Beratung'));
    $types = new \CometCMS\Content\ContentTypeRepository();
    $type = $types->find('about');
    $type['fields']['content'] = ['type' => 'page', 'label' => 'Content'];
    $types->save($type);
    $content = ContentRepository::make();
    $entry = $content->findByIdentifier('about', 'about');
    $content->savePage('about', $entry['id'], 'content', $nodes, comet_site_test_user(), $entry['revision']);
    $search = new SiteSearch(comet_site_test_context());
    assert_same(1, count($search->search('unicorn', ['about'], ['content'])));
    assert_same(1, count($search->search('neptune', ['about'], ['content'])));
    ExtensionRegistry::active()->setEnabled('test/kit', false);
    assert_same('', LayoutSchema::text($nodes, 'en', 'en'));
});

test('extension regression supports MCP schemas lists bindings slots and unrelated element edits', function (): void {
    [$token, $layout] = comet_test_builder_setup();
    ExtensionRegistry::active()->installFiles(comet_block_single([
        'title' => ['type' => 'string', 'label' => 'Title'],
        'items' => ['type' => 'list', 'label' => 'Items', 'fields' => ['label' => ['type' => 'string', 'label' => 'Label']]],
    ], '<div>{{title}}<slot></slot></div>'));
    [, $schema] = comet_test_mcp_tool($token, 'get_builder_schema', ['kind' => 'block:test/thing']);
    assert_same('text', $schema['data']['props']['title']['binding']);
    assert_true($schema['data']['props']['items']['localized']);
    $sections = comet_test_builder_sections();
    $sections[0]['children'][0]['children'][0]['children'][] = ['id' => 'custom', 'kind' => 'block:test/thing', 'props' => [
        'title' => ['type' => 'reference', 'source' => 'current', 'path' => ['title'], 'filters' => []],
        'items' => ['type' => 'localized', 'values' => ['en' => [['label' => 'One']], 'de' => [['label' => 'Eins']]]],
    ], 'children' => [['id' => 'slot-child', 'kind' => 'heading', 'props' => ['text' => 'In slot']]]];
    $target = ['collection' => 'pages', 'variant' => 'detail'];
    [, $saved] = comet_test_mcp_tool($token, 'set_layout', $target + ['revision' => $layout['revision'], 'sections' => $sections]);
    assert_true(isset($saved['data']['revision']), json_encode($saved));
    [, $updated] = comet_test_mcp_tool($token, 'update_element', $target + ['revision' => $saved['data']['revision'], 'element_id' => 'heading', 'props' => ['text' => 'Updated built-in']]);
    assert_true(isset($updated['data']['revision']), json_encode($updated));
    [, $read] = comet_test_mcp_tool($token, 'get_element', $target + ['element_id' => 'custom']);
    assert_same('reference', $read['data']['element']['props']['title']['type']);
    assert_same('Eins', $read['data']['element']['props']['items']['values']['de'][0]['label']);
    assert_same('slot-child', $read['data']['element']['children'][0]['id']);
    [, $bad] = comet_test_mcp_tool($token, 'update_element', $target + ['revision' => $updated['data']['revision'], 'element_id' => 'custom', 'props' => ['items' => [['label' => []]]]]);
    assert_same(422, $bad['error']['status']);
    assert_same($updated['data']['revision'], comet_test_builder_layout($token)['revision']);
});

test('extension regression reviews scripts breaking changes and seeds together without writes', function (): void {
    $registry = ExtensionRegistry::active();
    $initial = comet_block_single(['old' => ['type' => 'string', 'label' => 'Old']], '<p>{{old}}</p>');
    $registry->installFiles($initial);
    $update = comet_block_single([], '<div>Updated</div>', [
        'blocks/thing/view.js' => 'export default function mount() {}',
        'content-types/project.json' => '{"name":"project","label":"Projects"}',
    ]);
    foreach ([[], ['acceptChanges' => true], ['allowScripts' => true], ['seeds' => []]] as $consent) {
        try {
            $registry->installFiles($update, ...$consent);
            throw new RuntimeException('Expected complete install review.');
        } catch (\CometCMS\Extensions\ExtensionReview $e) {
            assert_same(1, count($e->changes));
            assert_same(['test/thing'], $e->scripts);
            assert_same('project', $e->plan[0]['id']);
        }
        assert_same($initial['comet-extension.json'], file_get_contents($registry->directory() . '/test/kit/comet-extension.json'));
        assert_false((new \CometCMS\Content\ContentTypeRepository())->exists('project'));
    }
    $registry->installFiles($update, acceptChanges: true, allowScripts: true, seeds: []);
    assert_true((new \CometCMS\Content\ContentTypeRepository())->exists('project'));
});

test('extension regression transfers seed tracking only on explicit overwrite', function (): void {
    $registry = ExtensionRegistry::active();
    $files = ['comet-extension.json' => '{"api_version":1,"name":"test/first","title":"First","version":"1.0.0"}',
        'content-types/project.json' => '{"name":"project","label":"Projects"}'];
    $registry->installFiles($files, seeds: []);
    $files['comet-extension.json'] = '{"api_version":1,"name":"test/second","title":"Second","version":"1.0.0"}';
    $registry->installFiles($files, seeds: ['content_types/project' => 'skip']);
    assert_same(1, count($registry->details('test/first')['seeded']));
    assert_same([], $registry->details('test/second')['seeded']);
    $registry->installFiles($files, seeds: ['content_types/project' => 'overwrite']);
    assert_same([], $registry->details('test/first')['seeded']);
    assert_same(1, count($registry->details('test/second')['seeded']));
    $registry->setEnabled('test/first', false);
    assert_true((new \CometCMS\Content\ContentTypeRepository())->exists('project'));
    $registry->delete('test/first');
    $registry->setEnabled('test/second', false);
    assert_false((new \CometCMS\Content\ContentTypeRepository())->exists('project'));
    $registry->delete('test/second');
    assert_true((new \CometCMS\Content\ContentTypeRepository())->exists('project'));
});
