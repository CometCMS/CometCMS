<?php

declare(strict_types=1);

use CometCMS\Core\ValidationException;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\SiteRepository;
use CometCMS\Storage\JsonStore;

/** A section holding one heading with $text. */
function comet_template_test_section(string $text, string $prefix = 't'): array
{
    return comet_component_test_section([['id' => $prefix . 'h', 'kind' => 'heading', 'props' => ['text' => $text]]], $prefix);
}

function comet_template_test(string $id, array $parts = [], array $conditions = []): array
{
    return ['id' => $id, 'name' => ucfirst($id), 'conditions' => $conditions] + $parts + ['header' => 'default', 'footer' => 'default'];
}

test('every page kind starts with the default template around its generated content', function (): void {
    $site = comet_site_test_setup();
    assert_same([SiteRepository::defaultTemplate()], $site->templates('blog', 'detail'));
    assert_false($site->layoutCustomized('blog', 'detail'));

    $html = comet_site_test_get('/blog/hello')['body'];
    assert_true(str_contains($html, '<header class="c-region c-header">'));
    assert_true(str_contains($html, '<footer class="c-region c-footer">'));

    // Saving only the default template stores nothing.
    $site->saveTemplates(['blog' => ['detail' => [SiteRepository::defaultTemplate()]]]);
    assert_null((new JsonStore($site->workspace()->path('site') . '/theme'))->read('templates', 'blog', 'detail'));
});

test('the first template whose conditions match an entry assembles its page', function (): void {
    $site = comet_site_test_setup();
    $tips = $site->createTemplate('blog', 'detail', 'Tips', '', ['header' => 'none', 'conditions' => [['field' => 'category', 'op' => 'equals', 'value' => 'tips']]]);
    assert_same('tips', $tips['id']);
    $site->saveLayout('blog', 'detail', [comet_template_test_section('Long read content')], [], 'saved', null, 'tips');
    $news = $site->createTemplate('blog', 'detail', 'News', '', ['footer' => 'none', 'conditions' => [['field' => 'category', 'op' => 'equals', 'value' => 'news']]]);
    $site->saveTemplates(['blog' => ['detail' => [$news, $tips, SiteRepository::defaultTemplate()]]]);

    $html = comet_site_test_get('/blog/hello')['body'];
    assert_true(str_contains($html, 'Long read content'));
    assert_false(str_contains($html, '<header'), 'The tips template has no header.');
    assert_true(str_contains($html, '<footer class="c-region c-footer">'));
    assert_true(str_contains(comet_site_test_get('/blog')['body'], '<header class="c-region c-header">'), 'List pages keep their own templates.');

    // Earlier templates win when several match.
    $everything = $site->createTemplate('blog', 'detail', 'Everything', '', ['footer' => 'none']);
    $everything['conditions'] = [['field' => 'nope', 'op' => 'not_empty', 'value' => null]];
    assert_throws(ValidationException::class, fn() => $site->saveTemplates(['blog' => ['detail' => [$everything]]]), 'Unknown fields are rejected.');
    $everything['conditions'] = [['field' => 'body', 'op' => 'not_empty', 'value' => null]];
    $site->saveTemplates(['blog' => ['detail' => [$everything, $tips, $news, SiteRepository::defaultTemplate()]]]);
    $html = comet_site_test_get('/blog/hello')['body'];
    assert_false(str_contains($html, 'Long read content'));
    assert_false(str_contains($html, '<footer'));
});

test('templates resolve by entry pick, conditions, order and default', function (): void {
    $default = SiteRepository::defaultTemplate();
    $featured = comet_template_test('featured', [], [['field' => 'featured', 'op' => 'equals', 'value' => true], ['field' => 'tags', 'op' => 'contains', 'value' => 'news']]);
    $templates = [$featured, $default];

    assert_same('conditions', SiteRepository::resolveTemplate($templates, ['featured' => true, 'tags' => ['news', 'tips']])['reason']);
    assert_same('default', SiteRepository::resolveTemplate($templates, ['featured' => true, 'tags' => ['tips']])['reason']);
    assert_same('default', SiteRepository::resolveTemplate($templates, null)['reason']);
    assert_same(['featured', 'picked'], array_values([SiteRepository::resolveTemplate($templates, ['featured' => false], 'featured')['template']['id'], SiteRepository::resolveTemplate($templates, ['featured' => false], 'featured')['reason']]));
    assert_same('default', SiteRepository::resolveTemplate($templates, ['featured' => false], 'missing')['reason'], 'A picked template that is gone is ignored.');
    assert_same('first', SiteRepository::resolveTemplate([comet_template_test('plain'), $default], null)['reason']);

    foreach ([
        [['field' => 'n', 'op' => 'equals', 'value' => '3'], ['n' => 3.0], true],
        [['field' => 'n', 'op' => 'not_equals', 'value' => 3], ['n' => 4], true],
        [['field' => 'text', 'op' => 'contains', 'value' => 'HELLO'], ['text' => '<p>hello world</p>'], true],
        [['field' => 'text', 'op' => 'empty', 'value' => null], ['text' => ''], true],
        [['field' => 'text', 'op' => 'empty', 'value' => null], [], true],
        [['field' => 'flag', 'op' => 'equals', 'value' => false], ['flag' => false], true],
        [['field' => 'flag', 'op' => 'equals', 'value' => 'true'], ['flag' => true], true],
        [['field' => 'text', 'op' => 'not_empty', 'value' => null], ['text' => null], false],
    ] as [$condition, $entry, $expected]) {
        assert_same($expected, SiteRepository::matchesConditions([$condition], $entry), json_encode([$condition, $entry]));
    }
});

test('saving templates rejects unknown parts and conditions that do not fit', function (): void {
    $site = comet_site_test_setup();
    $column = $site->createComponent('Column', [['id' => 'c', 'kind' => 'column', 'children' => []]]);
    $about = $site->createComponent('About header', [comet_component_test_section([LayoutSchema::node('field', ['source' => \CometCMS\Site\SmartValue::reference('intro')])])], 'about');
    $blog = $site->createComponent('Blog header', [comet_component_test_section([LayoutSchema::node('field', ['source' => \CometCMS\Site\SmartValue::reference('category')])], 'b')], 'blog');
    $default = SiteRepository::defaultTemplate();

    foreach ([
        'nonsense',
        ['missing' => ['detail' => [$default]]],
        ['blog' => ['card' => [$default]]],
        ['blog' => ['detail' => ['not a list' => $default]]],
        ['blog' => ['detail' => [comet_template_test('a', ['header' => 'missing-component'])]]],
        ['blog' => ['detail' => [comet_template_test('a', ['header' => $column['id']])]]],
        ['blog' => ['detail' => [comet_template_test('a'), comet_template_test('a')]]],
        ['blog' => ['detail' => [['id' => 'a', 'name' => '  '] + comet_template_test('a')]]],
        ['blog' => ['detail' => [comet_template_test('a', [], [['field' => 'steps', 'op' => 'empty']])]]],
        ['blog' => ['detail' => [comet_template_test('a', [], [['field' => 'author', 'op' => 'equals', 'value' => 'ada']])]]],
        ['blog' => ['detail' => [comet_template_test('a', [], [['field' => 'category', 'op' => 'equals', 'value' => ['tips']]])]]],
        ['blog' => ['detail' => [SiteRepository::defaultTemplate() + ['conditions' => []], comet_template_test('default', [], [['field' => 'body', 'op' => 'empty']])]]],
        ['blog' => ['index' => [comet_template_test('a', [], [['field' => 'body', 'op' => 'empty']])]]],
    ] as $templates) {
        assert_throws(ValidationException::class, fn() => $site->saveTemplates($templates), json_encode($templates));
    }

    // Unknown keys are dropped and the default template moves last.
    $saved = $site->saveTemplates(['blog' => ['index' => [$default, comet_template_test('a', ['header' => $blog['id'], 'content' => 'default', 'sidebar' => 'none'])]]]);
    assert_same([comet_template_test('a', ['header' => $blog['id']]), $default], $saved['blog']['index']);
    // Components fit every type, so another type's section works as a footer.
    $saved = $site->saveTemplates(['blog' => ['detail' => [comet_template_test('a', ['footer' => $about['id']]), $default]]]);
    assert_same($about['id'], $saved['blog']['detail'][0]['footer']);
});

test('entry template conditions select any stable UID and survive title and slug changes', function (): void {
    $site = comet_site_test_setup();
    $content = \CometCMS\Content\ContentRepository::make();
    $hello = $content->findByIdentifier('blog', 'hello');
    $draft = $content->findByIdentifier('blog', 'secret-draft');
    $condition = ['field' => 'uid', 'op' => 'equals', 'value' => [$hello['uid'], $draft['uid']]];
    $chosen = $site->createTemplate('blog', 'detail', 'Chosen entries', '', ['header' => 'none', 'conditions' => [$condition]]);
    $site->saveLayout('blog', 'detail', [comet_template_test_section('Selected entry design')], [], 'saved', null, $chosen['id']);
    assert_same([$condition], (new SiteRepository())->templates('blog', 'detail')[0]['conditions']);
    assert_true(SiteRepository::matchesConditions([$condition], $hello));
    assert_true(SiteRepository::matchesConditions([$condition], $draft));
    assert_false(SiteRepository::matchesConditions([$condition], ['uid' => 'another-entry', 'slug' => 'hello']));
    assert_false(SiteRepository::matchesConditions([$condition], ['id' => $hello['uid']]));
    assert_false(SiteRepository::matchesConditions([['value' => []] + $condition], $hello));
    assert_false(SiteRepository::matchesConditions([$condition, ['field' => 'category', 'op' => 'equals', 'value' => 'news']], $hello));
    assert_same('default', SiteRepository::resolveTemplate([$chosen, SiteRepository::defaultTemplate()], null)['reason']);
    assert_true(str_contains(comet_site_test_get('/blog/hello')['body'], 'Selected entry design'));

    $renamed = $content->save('blog', ['title' => 'Renamed entry', 'slug' => 'renamed-entry'], comet_site_test_user(), $hello['id']);
    assert_same($hello['uid'], $renamed['uid']);
    assert_true(str_contains(comet_site_test_get('/blog/renamed-entry')['body'], 'Selected entry design'));

    // The preview reason describes the matching entry and handles an array value.
    $context = comet_site_test_context();
    $context->preview = $context->browsePreview = $context->previewBanner = true;
    $context->adminUser = ['username' => 'admin', 'language' => 'en'];
    $context->adminCan = static fn(): bool => true;
    $html = (new \CometCMS\Site\SiteService($context))->respond('/blog/renamed-entry', [])['body'];
    assert_true(str_contains($html, 'Its conditions match: Entry = Renamed entry'));
});

test('entry template conditions validate UID lists and keep empty selections from matching', function (): void {
    $site = comet_site_test_setup();
    assert_same(['equals'], SiteRepository::conditionOps('entry'));
    foreach ([null, 'one', true, 1, ['one' => 'two'], [null], [1], [['uid' => 'one']], [''], ['../one'], [str_repeat('a', 129)]] as $value) {
        assert_throws(ValidationException::class, fn() => $site->saveTemplates(['blog' => ['detail' => [comet_template_test('chosen', [], [['field' => 'uid', 'op' => 'equals', 'value' => $value]])]]]));
    }
    foreach (['not_equals', 'contains', 'empty', 'not_empty'] as $op) {
        assert_throws(ValidationException::class, fn() => $site->createTemplate('blog', 'detail', 'Chosen', '', ['conditions' => [['field' => 'uid', 'op' => $op, 'value' => ['one']]]]));
    }
    $chosen = $site->createTemplate('blog', 'detail', 'Chosen', '', ['conditions' => [['field' => 'uid', 'op' => 'equals', 'value' => ['one', 'two', 'one']]]]);
    assert_same(['one', 'two'], $chosen['conditions'][0]['value']);
    $chosen['conditions'][0]['value'] = [];
    $site->saveTemplates(['blog' => ['detail' => [$chosen]]]);
    $templates = (new SiteRepository())->templates('blog', 'detail');
    assert_same([], $templates[0]['conditions'][0]['value']);
    assert_same('default', SiteRepository::resolveTemplate($templates, ['uid' => 'one'])['reason']);
});

test('each template owns its content area: a copy when created, deleted with the template', function (): void {
    $site = comet_site_test_setup();
    $site->saveLayout('blog', 'detail', [comet_template_test_section('Original')]);
    $compact = $site->createTemplate('blog', 'detail', 'Compact');
    assert_same(['id' => 'compact', 'name' => 'Compact', 'conditions' => [], 'header' => 'default', 'footer' => 'default'], $compact);
    assert_same($site->layout('blog', 'detail'), $site->layout('blog', 'detail', 'compact'), 'It starts as a copy of the default template.');
    $site->saveLayout('blog', 'detail', [comet_template_test_section('Compact design')], [], 'saved', null, 'compact');
    assert_true(str_contains(json_encode($site->layout('blog', 'detail')), 'Original'), 'The copy is its own.');

    // A duplicate copies the parts, conditions and content of its source.
    $site->saveTemplates(['blog' => ['detail' => [['header' => 'none', 'conditions' => [['field' => 'body', 'op' => 'empty', 'value' => null]]] + $compact, SiteRepository::defaultTemplate()]]]);
    $copy = $site->createTemplate('blog', 'detail', 'Compact', 'compact');
    assert_same(['compact-2', 'none', 1], [$copy['id'], $copy['header'], count($copy['conditions'])]);
    assert_same($site->layout('blog', 'detail', 'compact'), $site->layout('blog', 'detail', 'compact-2'));
    assert_same(['compact', 'compact-2', 'default'], array_column($site->templates('blog', 'detail'), 'id'));
    assert_throws(ValidationException::class, fn() => $site->createTemplate('blog', 'detail', 'X', 'missing'));
    assert_throws(ValidationException::class, fn() => $site->createTemplate('blog', 'detail', '  '));

    // Resetting goes back to the generated content.
    assert_same(LayoutSchema::defaultLayout($site->types()['blog'], 'detail'), $site->resetLayout('blog', 'detail', [], 'reset', 'compact'));
    assert_same(2, count($site->layoutRevisions('blog', 'detail', 'compact')), 'Saved and reset.');

    // Leaving a template out of the list deletes it with its content and history.
    $site->saveTemplates(['blog' => ['detail' => [$copy, SiteRepository::defaultTemplate()]]]);
    assert_false($site->hasTemplate('blog', 'detail', 'compact'));
    assert_same([], $site->layoutRevisions('blog', 'detail', 'compact'));
    assert_null((new JsonStore($site->workspace()->path('site') . '/theme'))->read('content', 'blog', 'detail', 'compact'));
    assert_throws(\InvalidArgumentException::class, fn() => $site->saveLayout('blog', 'detail', [], [], 'saved', null, 'compact'));
    // An unknown template renders the default one's content.
    assert_same($site->layout('blog', 'detail'), $site->layout('blog', 'detail', 'compact'));
});

test('deleting a component or content type cleans up the templates that used it', function (): void {
    $site = comet_site_test_setup();
    $component = $site->createComponent('Blog footer', [comet_template_test_section('Blog footer here', 'f')]);
    $site->saveTemplates([
        'blog' => ['detail' => [SiteRepository::defaultTemplate(['footer' => $component['id']])]],
        'about' => ['detail' => [SiteRepository::defaultTemplate(['header' => 'none'])]],
    ]);
    assert_matches('#<footer class="c-region c-footer">.*Blog footer here.*</footer>#s', comet_site_test_get('/blog/hello')['body']);
    assert_same([['target' => 'template', 'type' => 'blog', 'variant' => 'detail', 'id' => 'default', 'name' => 'Default', 'region' => 'footer']], $site->componentUsages($component['id']));

    $site->deleteComponent($component['id']);
    assert_same('default', $site->templates('blog', 'detail')[0]['footer']);
    assert_false(str_contains(comet_site_test_get('/blog/hello')['body'], 'Blog footer here'));

    $site->createTemplate('blog', 'detail', 'Compact');
    $site->saveLayout('blog', 'detail', [], [], 'saved', null, 'compact');
    $site->forgetType('blog');
    $theme = comet_test_workspace_path() . '/site/theme';
    assert_false(is_dir($theme . '/content/blog'));
    assert_false(is_dir(comet_test_workspace_path() . '/revisions/site/content/blog'));
    assert_same('none', $site->templates('about', 'detail')[0]['header']);
});

test('builder previews show the edited template around its content area, with unsaved templates', function (): void {
    $site = comet_site_test_setup();
    $compact = $site->createTemplate('blog', 'detail', 'Compact', '', ['conditions' => [['field' => 'category', 'op' => 'equals', 'value' => 'news']]]);
    $context = comet_site_test_context();
    $context->preview = true;
    $context->annotate = true;
    $context->override('templates', $site->validateTemplates(['blog' => ['detail' => [
        ['header' => 'none'] + $compact,
        SiteRepository::defaultTemplate(),
    ]]]));
    $renderer = new PageRenderer($context);

    // The compact content shows inside its template, even for an entry it would not match.
    $html = $renderer->layoutPreview($site->types()['blog'], 'detail', null, 'compact');
    assert_same(['compact', 'edited', 'default'], [$renderer->template()['template']['id'], $renderer->template()['reason'], $renderer->template()['live']]);
    assert_true(str_contains($html, 'class="c-region c-header c-region-none"'));
    assert_true(str_contains($html, 'data-placeholder="headerNone"'));

    $renderer->layoutPreview($site->types()['blog'], 'detail', null);
    assert_same(['default', 'default'], [$renderer->template()['template']['id'], $renderer->template()['reason']]);
    assert_same('default', $site->templates('blog', 'detail')[0]['header'], 'Nothing is saved.');
});

test('an entry can pick its template, and a pick that is gone falls back to the automatic one', function (): void {
    $site = comet_site_test_setup();
    $site->createTemplate('blog', 'detail', 'Landing', '', ['header' => 'none', 'conditions' => [['field' => 'category', 'op' => 'equals', 'value' => 'news']]]);
    $site->saveLayout('blog', 'detail', [comet_template_test_section('Landing content')], [], 'saved', null, 'landing');
    $content = \CometCMS\Content\ContentRepository::make();
    $user = comet_site_test_user();
    assert_false(str_contains(comet_site_test_get('/blog/hello')['body'], 'Landing content'));

    $saved = $content->save('blog', ['page_template' => 'landing'], $user, 'hello');
    assert_same('landing', $saved['page_template']);
    assert_true(str_contains(comet_site_test_get('/blog/hello')['body'], 'Landing content'), 'The pick wins over conditions.');
    assert_throws(ValidationException::class, fn() => $content->save('blog', ['page_template' => '../x'], $user, 'hello'));

    $site->saveTemplates(['blog' => ['detail' => [SiteRepository::defaultTemplate()]]]);
    assert_false(str_contains(comet_site_test_get('/blog/hello')['body'], 'Landing content'), 'A deleted template is ignored.');
    assert_false(array_key_exists('page_template', $content->save('blog', ['page_template' => ''], $user, 'hello')), 'Empty goes back to automatic.');
    assert_throws(ValidationException::class, fn() => (new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'odd', 'fields' => ['page_template' => ['type' => 'text']]]));
});

test('the preview admin bar names the template of the page, why it applies and links to its parts', function (): void {
    $site = comet_site_test_setup();
    $footer = $site->createComponent('Promo footer', [comet_template_test_section('Promo', 'f')]);
    $site->createTemplate('blog', 'detail', 'Tips', '', ['header' => 'none', 'footer' => $footer['id'], 'conditions' => [['field' => 'category', 'op' => 'equals', 'value' => 'tips']]]);
    $context = comet_site_test_context();
    $context->preview = true;
    $context->browsePreview = true;
    $context->previewBanner = true;
    $context->adminUser = ['username' => 'admin', 'language' => 'en'];
    $context->adminCan = static fn(): bool => true;
    $html = (new \CometCMS\Site\SiteService($context))->respond('/blog/hello', [])['body'];
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $menu = (new DOMXPath($document))->query('//details[contains(@class,"c-admin-templates")]')->item(0);
    assert_true($menu !== null, 'The bar has a template menu.');
    $text = preg_replace('/\s+/', ' ', $menu->textContent);
    foreach (['Layout: Tips', 'Its conditions match: category = tips', 'No header', 'ContentTips', 'Promo footer', 'Edit layout'] as $expected) {
        assert_true(str_contains($text, $expected), $expected . ' in ' . $text);
    }
    $links = array_map(static fn(DOMElement $link): string => $link->getAttribute('href'), iterator_to_array((new DOMXPath($document))->query('.//a', $menu)));
    assert_true((bool) array_filter($links, static fn(string $href): bool => str_contains($href, '/page-builder/builder/layout/blog/detail') && str_contains($href, 'template=tips')));
    assert_true(in_array(true, array_map(static fn(string $href): bool => str_contains($href, '/page-builder/builder/component/' . $footer['id']), $links), true));
    assert_true((bool) array_filter($links, static fn(string $href): bool => str_contains($href, '/page-builder/templates') && str_contains($href, 'template=tips')));

    $context->adminCan = static fn(string $action): bool => $action !== 'site.manage';
    assert_false(str_contains((new \CometCMS\Site\SiteService($context))->respond('/blog/hello', [])['body'], '<details class="c-admin-dropdown c-admin-templates"'), 'Only site designers see it.');
});

test('the preview admin bar keeps its template menu out of the way until a page kind uses templates', function (): void {
    $site = comet_site_test_setup();
    $menu = '<details class="c-admin-dropdown c-admin-templates"';
    // A fresh context per page, as each request has.
    $render = static function (): string {
        $context = comet_site_test_context();
        $context->preview = true;
        $context->browsePreview = true;
        $context->previewBanner = true;
        $context->adminUser = ['username' => 'admin', 'language' => 'en'];
        $context->adminCan = static fn(): bool => true;
        return (new \CometCMS\Site\SiteService($context))->respond('/blog/hello', [])['body'];
    };

    assert_false(str_contains($render(), $menu), 'Only the default template.');
    $site->createTemplate('blog', 'detail', 'Plain');
    assert_true(str_contains($render(), $menu), 'A second template brings the menu.');
});

test('the builder previews a template with an entry that uses it, so that entry can be designed there', function (): void {
    $seed = <<<'PHP'
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'blog', 'fields' => ['category' => ['type' => 'select', 'options' => ['news' => 'News', 'tips' => 'Tips']]]]);
$content = \CometCMS\Content\ContentRepository::make();
$content->save('blog', ['title' => 'News', 'slug' => 'news', 'category' => 'news', 'status' => 'published'], ['id' => 'admin']);
usleep(1100000);
$content->save('blog', ['title' => 'Tip', 'slug' => 'tip', 'category' => 'tips', 'status' => 'published'], ['id' => 'admin']);
$site = new \CometCMS\Site\SiteRepository();
$site->saveSettings(['routes' => ['blog' => ['enabled' => true]]]);
$site->createTemplate('blog', 'detail', 'Tips', '', ['conditions' => [['field' => 'category', 'op' => 'equals', 'value' => 'tips']]]);
PHP;
    $open = static function (string $query) use ($seed): array {
        [$status, $body] = comet_admin_request('admin', $seed, 'GET', '/admin/api/site/layouts/blog/detail' . $query,
            '(new \CometCMS\Controllers\Admin\SiteController(new \CometCMS\Core\Http()))->layout("blog", "detail");');
        assert_same(200, $status);
        $titles = array_column($body['data']['samples'], 'title', 'id');

        return [$body['data']['template'], $titles[$body['data']['sample']] ?? ''];
    };

    assert_same(['default', 'News'], $open(''), 'The newest entry uses another template, so an older one that uses the default is shown.');
    assert_same(['tips', 'Tip'], $open('?template=tips&entry=news'), 'A carried entry that does not use the template gives way to one that does.');
    assert_same(['default', 'News'], $open('?entry=news'), 'An entry asked for opens its own template.');
});

test('builder entry samples name their resolved template for default, conditions and manual picks', function (): void {
    $seed = <<<'PHP'
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'blog', 'fields' => ['category' => ['type' => 'text']]]);
$site = new \CometCMS\Site\SiteRepository();
$site->createTemplate('blog', 'detail', 'Tips', '', ['conditions' => [['field' => 'category', 'op' => 'equals', 'value' => 'tips']]]);
$content = \CometCMS\Content\ContentRepository::make();
$content->save('blog', ['title' => 'Default entry', 'category' => 'news'], ['id' => 'admin']);
$content->save('blog', ['title' => 'Matching entry', 'category' => 'tips'], ['id' => 'admin']);
$content->save('blog', ['title' => 'Assigned entry', 'category' => 'news', 'page_template' => 'tips'], ['id' => 'admin']);
PHP;
    [$status, $body] = comet_admin_request('admin', $seed, 'GET', '/admin/api/site/layouts/blog/detail?template=tips',
        '(new \CometCMS\Controllers\Admin\SiteController(new \CometCMS\Core\Http()))->layout("blog", "detail");');
    assert_same(200, $status);
    $templates = array_column($body['data']['samples'], 'template', 'title');
    assert_same('default', $templates['Default entry'], 'Previewing another template does not change an entry\'s assignment.');
    assert_same('tips', $templates['Matching entry'], 'Automatic conditions resolve through the website\'s template rules.');
    assert_same('tips', $templates['Assigned entry'], 'An explicit assignment applies even when the conditions do not match.');
});

test('the entry builder only opens content areas the entry\'s template shows, and names that template up front', function (): void {
    $seed = <<<'PHP'
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'blog', 'fields' => [
    'category' => ['type' => 'select', 'options' => ['news' => 'News', 'tips' => 'Tips']],
    'design' => ['type' => 'page'],
    'aside' => ['type' => 'page'],
]]);
$content = \CometCMS\Content\ContentRepository::make();
$news = $content->save('blog', ['title' => 'News', 'slug' => 'news', 'category' => 'news', 'status' => 'published'], ['id' => 'admin']);
$tip = $content->save('blog', ['title' => 'Tip', 'slug' => 'tip', 'category' => 'tips', 'status' => 'published'], ['id' => 'admin']);
$site = new \CometCMS\Site\SiteRepository();
$site->saveSettings(['routes' => ['blog' => ['enabled' => true]]]);
$site->createTemplate('blog', 'detail', 'Tips', '', ['conditions' => [['field' => 'category', 'op' => 'equals', 'value' => 'tips']]]);
$site->saveLayout('blog', 'detail', [['id' => 'area', 'kind' => 'page_content', 'props' => ['field' => 'aside']]]);
$site->saveLayout('blog', 'detail', [['id' => 'title', 'kind' => 'heading', 'props' => ['text' => 'Tips', 'level' => 'h1']]], area: 'tips');
PHP;
    $controller = '(new \CometCMS\Controllers\Admin\SiteController(new \CometCMS\Core\Http()))';

    [$status, $body] = comet_admin_request('admin', $seed, 'GET', '/admin/api/site/pages/blog/news?field=design', $controller . '->page("blog", $news["uid"]);');
    assert_same(200, $status);
    assert_same('aside', $body['data']['field'], 'An area the template does not show gives way to one it does.');
    assert_same(['default', 'default'], [$body['data']['shown_template']['id'], $body['data']['shown_template']['live']]);

    [$status, $body] = comet_admin_request('admin', $seed, 'GET', '/admin/api/site/pages/blog/tip', $controller . '->page("blog", $tip["uid"]);');
    assert_same(409, $status);
    assert_same('page_not_placed', $body['error']['code'], 'An entry whose template has no Entry content area cannot be designed.');

    [$status, $body] = comet_admin_request('admin', $seed, 'GET', '/admin/api/site/layouts/blog/detail?template=default&entry=tip', $controller . '->layout("blog", "detail");');
    assert_same(200, $status);
    assert_same(['default', 'default'], [$body['data']['shown_template']['id'], $body['data']['shown_template']['live']], 'The sample switches to an entry using the template.');

    [$status, $body] = comet_admin_request('admin', $seed, 'GET', '/admin/api/site/layouts/blog/detail?entry=tip', $controller . '->layout("blog", "detail");');
    assert_same(['tips', 'tips', 'conditions'], [$body['data']['template'], $body['data']['shown_template']['live'], $body['data']['shown_template']['reason']]);
});
