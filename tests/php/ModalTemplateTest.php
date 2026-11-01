<?php

declare(strict_types=1);

use CometCMS\Core\ValidationException;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\SiteRepository;

function comet_modal_test_template(string $title = 'Shared modal frame', array $props = []): array
{
    return [LayoutSchema::node(LayoutSchema::MODAL, $props + ['size' => 'md', 'placement' => 'right'], ['background' => 'surface', 'radius' => 'lg', 'padding_top' => 'md', 'padding_bottom' => 'md'], [
        LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [
            LayoutSchema::node('heading', ['text' => $title]),
            LayoutSchema::node('modal_title', ['level' => 'h3']),
        ]), LayoutSchema::node('column', [], [], [LayoutSchema::node('modal_close', ['show_label' => true, 'label' => 'Done'])])]),
        LayoutSchema::node(LayoutSchema::SITE_CONTENT, [], ['radius' => 'md']),
    ])];
}

test('the modal template is one panel that keeps its site content through save history reset and restore', function (): void {
    $site = comet_site_test_setup();
    $defaults = $site->region(LayoutSchema::MODAL);
    assert_same(LayoutSchema::MODAL, $defaults[0]['kind']);
    assert_same(['modal_close', LayoutSchema::SITE_CONTENT], array_column($defaults[0]['children'], 'kind'));
    assert_true(in_array(LayoutSchema::MODAL, LayoutSchema::spec()['special_pages'], true));
    assert_same([LayoutSchema::MODAL], LayoutSchema::spec(false, true)['root']);
    foreach (LayoutSchema::MODAL_ELEMENTS as $kind) {
        assert_false(in_array($kind, LayoutSchema::spec()['elements'], true));
        assert_false(in_array($kind, LayoutSchema::spec(true)['kinds']['column']['children'], true));
        assert_true(in_array($kind, LayoutSchema::spec(false, true)['kinds']['column']['children'], true));
        assert_true(in_array($kind, LayoutSchema::spec(false, true)['kinds'][LayoutSchema::MODAL]['children'], true));
    }
    $saved = $site->saveRegion(LayoutSchema::MODAL, comet_modal_test_template(), comet_site_test_user());
    assert_same($saved, (new SiteRepository())->region(LayoutSchema::MODAL));
    assert_same('right', $saved[0]['props']['placement']);
    assert_same('surface', $saved[0]['style']['background']);
    $site->saveRegion(LayoutSchema::MODAL, comet_modal_test_template('Updated frame'), comet_site_test_user());
    $revisions = $site->regionRevisions(LayoutSchema::MODAL);
    $previous = array_values(array_filter($revisions, static fn(array $revision): bool => ($site->regionRevision(LayoutSchema::MODAL, $revision['id'])['nodes'][0]['children'][0]['children'][0]['children'][0]['props']['text'] ?? '') === 'Shared modal frame'))[0];
    assert_same($saved, $site->restoreRegionRevision(LayoutSchema::MODAL, $previous['id'])['nodes']);
    assert_same($defaults, $site->resetRegion(LayoutSchema::MODAL));
    assert_false($site->regionCustomized(LayoutSchema::MODAL));
});

test('modal templates reject a missing or repeated site content element and stray panels or sections', function (): void {
    $site = comet_site_test_setup();
    $saved = $site->saveRegion(LayoutSchema::MODAL, comet_modal_test_template());
    $duplicate = comet_modal_test_template();
    $duplicate[0]['children'][0]['children'][1]['children'][] = LayoutSchema::node(LayoutSchema::SITE_CONTENT);
    $carded = comet_modal_test_template();
    $carded[0]['children'][1] = LayoutSchema::node('collection', ['type' => 'blog'], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node(LayoutSchema::SITE_CONTENT)])]);
    foreach ([[], [LayoutSchema::node('section')], $duplicate, $carded] as $invalid) {
        assert_throws(ValidationException::class, fn() => $site->saveRegion(LayoutSchema::MODAL, $invalid));
        assert_same($saved, $site->region(LayoutSchema::MODAL));
    }
    // Only the first panel counts; other documents cannot hold a panel or its elements.
    assert_same(1, count(LayoutSchema::normalizeRegion(LayoutSchema::MODAL, [...comet_modal_test_template(), ...comet_modal_test_template()])));
    assert_same([], LayoutSchema::normalize(comet_modal_test_template()));
    assert_same([], LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [LayoutSchema::node('modal_close'), LayoutSchema::node(LayoutSchema::SITE_CONTENT)])], 'column')[0]['children']);
});

test('modal builder previews show the panel open with a placeholder and live pages use the saved template', function (): void {
    $site = comet_site_test_setup();
    $saved = $site->saveRegion(LayoutSchema::MODAL, comet_modal_test_template('Shared modal frame', ['backdrop' => 'blurred', 'backdrop_close' => false]));
    $ctx = comet_site_test_context($site);
    $ctx->preview = $ctx->annotate = true;
    $html = (new PageRenderer($ctx))->regionPreview(LayoutSchema::MODAL);
    assert_true(str_contains($html, '<dialog class="c-modal c-modal-md c-modal-at-right c-modal-h-auto c-modal-backdrop-blurred c-modal-preview'));
    assert_true(str_contains($html, ' open aria-label="Modal"'));
    assert_true(str_contains($html, 'data-drop-root'));
    assert_true(str_contains($html, 'data-kind="modal"'));
    assert_true(str_contains($html, 'data-kind="site_content"'));
    assert_true(str_contains($html, 'data-placeholder="siteContent"'));
    assert_true(str_contains($html, '>Link text</h3>'));
    assert_true(str_contains($html, 'data-modal-close tabindex="-1"><svg'));
    assert_true(str_contains($html, '<span>Done</span>'));
    foreach (['<script', '<header', '<footer', 'data-link-modal-dialog'] as $missing) assert_false(str_contains($html, $missing), $missing);
    $button = LayoutSchema::node('button', ['link' => ['type' => 'home', 'open_modal' => true]]);
    $site->saveLayout('blog', 'detail', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$button])])])]);
    $ctx = comet_site_test_context($site);
    $html = (new PageRenderer($ctx))->entryPage($ctx->type('blog'), $ctx->findEntry('blog', 'hello'));
    assert_same(1, substr_count($html, ' data-link-modal-dialog'));
    assert_true(str_contains($html, ' data-link-modal-dialog aria-label="Modal"'), 'No backdrop close');
    assert_same(1, substr_count($html, ' c-el-site-content'));
    assert_true(str_contains($html, 'Shared modal frame'));
    assert_true(str_contains($html, 'data-modal-title></h3>'));
    assert_false(str_contains($html, 'tabindex="-1"'), 'tabindex="-1"');
    assert_false(str_contains($html, 'data-placeholder'), 'data-placeholder');
    assert_false(str_contains($html, 'data-node="' . $saved[0]['id'] . '"'));
});

test('a modal template saved in an older shape falls back to the default panel', function (): void {
    $site = comet_site_test_setup();
    $site->saveRegion(LayoutSchema::MODAL, comet_modal_test_template());
    $file = comet_test_workspace_path() . '/site/theme/regions/modal.json';
    assert_true(is_file($file));
    $stored = json_decode((string) file_get_contents($file), true);
    $stored['nodes'] = [LayoutSchema::node('section')];
    file_put_contents($file, json_encode($stored));
    assert_same(LayoutSchema::MODAL, $site->region(LayoutSchema::MODAL)[0]['kind']);
});

test('modal editor API exposes the panel schema and previews unsaved template content', function (): void {
    $controller = '(new \\CometCMS\\Controllers\\Admin\\SiteController(new \\CometCMS\\Core\\Http()))';
    [$status, $json] = comet_admin_request('admin', '', 'GET', '/admin/api/site/regions/modal', $controller . '->region("modal");');
    assert_same(200, $status);
    assert_same([LayoutSchema::MODAL], $json['data']['spec']['root']);
    assert_true(in_array('modal_close', $json['data']['spec']['elements'], true));
    [$status, $json] = comet_admin_request('admin', '', 'POST', '/admin/api/site/preview', $controller . '->preview();', ['target' => 'region', 'region' => 'modal', 'nodes' => comet_modal_test_template('Unsaved modal frame')]);
    assert_same(200, $status);
    assert_true(str_contains($json['data']['html'], 'Unsaved modal frame'));
    assert_true(str_contains($json['data']['html'], 'data-kind="site_content"'));
    [$status, $json] = comet_admin_request('admin', '', 'GET', '/admin/api/site', $controller . '->index();');
    assert_same(200, $status);
    assert_same(false, $json['data']['special_pages']['modal']);
});

test('modal template interactions keep unique identifiers alongside the parent page', function (): void {
    $site = comet_site_test_setup();
    $tabs = static fn(string $prefix): array => LayoutSchema::node('tabs', [], [], [
        LayoutSchema::node('tab', ['title' => $prefix . ' first'], [], [LayoutSchema::node('heading', ['text' => 'First panel'])]),
        LayoutSchema::node('tab', ['title' => $prefix . ' second'], [], [LayoutSchema::node('heading', ['text' => 'Second panel'])]),
    ]);
    $component = $site->createComponent('Shared tabs', [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$tabs('Shared')])])]);
    $instance = static fn(): array => LayoutSchema::node(LayoutSchema::COMPONENT, ['component' => $component['id'], 'root' => 'row']);
    $template = comet_modal_test_template();
    $template[0]['children'][] = $instance();
    $site->saveRegion(LayoutSchema::MODAL, $template);
    $site->saveLayout('blog', 'detail', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [
        $instance(), LayoutSchema::node('button', ['link' => ['type' => 'home', 'open_modal' => true]]),
    ])])])]);
    $ctx = comet_site_test_context($site);
    $html = (new PageRenderer($ctx))->entryPage($ctx->type('blog'), $ctx->findEntry('blog', 'hello'));
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $ids = [];
    foreach ($xpath->query('//*[@id]') as $element) $ids[] = $element->getAttribute('id');
    assert_same(count($ids), count(array_unique($ids)), 'Parent and modal controls must not share IDs.');
    assert_same(4, $xpath->query('//*[@role="tab"]')->length);
});
