<?php

declare(strict_types=1);

use CometCMS\Content\ContentRepository;
use CometCMS\Site\ElementConditions;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\SmartValue;

function condition_test_if(string $field, string $op, mixed $value = null, string $source = 'current', string $match = 'all'): array
{
    return ['match' => $match, 'conditions' => [['field' => SmartValue::reference($field, $source), 'op' => $op, 'value' => $value]]];
}

function condition_test_heading(string $text, array $visibleIf): array
{
    return LayoutSchema::node('heading', ['text' => $text], ['visible_if' => $visibleIf]);
}

test('element conditions keep valid rows and drop the key when none are left', function (): void {
    $ref = SmartValue::reference('category');
    $normalized = ElementConditions::normalize(['match' => 'any', 'conditions' => [
        ['field' => $ref + ['filters' => [['name' => 'fallback', 'value' => 'x']]], 'op' => 'equals', 'value' => str_repeat('a', 600)],
        ['field' => $ref, 'op' => 'empty', 'value' => 'ignored'],
        ['field' => $ref, 'op' => 'greater', 'value' => 1],
        ['field' => ['type' => 'reference', 'source' => 'nowhere', 'path' => ['x']], 'op' => 'equals', 'value' => 1],
        ['field' => $ref, 'op' => 'equals', 'value' => ['array']],
    ]]);
    assert_same('any', $normalized['match']);
    assert_same(2, count($normalized['conditions']));
    assert_same([], $normalized['conditions'][0]['field']['filters']);
    assert_same(500, mb_strlen($normalized['conditions'][0]['value']));
    assert_null($normalized['conditions'][1]['value']);
    assert_same('all', ElementConditions::normalize(['match' => 'nope', 'conditions' => [['field' => $ref, 'op' => 'not_empty']]])['match']);
    assert_same([], ElementConditions::normalize(['conditions' => [['field' => $ref, 'op' => 'bad']]]));
    assert_same([], ElementConditions::normalize('bad'));

    $heading = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [
        condition_test_heading('Kept', condition_test_if('category', 'equals', 'tips')),
        condition_test_heading('Plain', ['conditions' => []]),
    ])])])])[0]['children'][0]['children'][0]['children'];
    assert_same('tips', $heading[0]['style']['visible_if']['conditions'][0]['value']);
    assert_false(isset($heading[1]['style']['visible_if']));
    assert_false(in_array('visible_if', LayoutSchema::styleKeys(LayoutSchema::MODAL), true));
});

test('elements render only when their conditions hold for the entry', function (): void {
    comet_site_test_setup();
    [$context, $state] = smart_test_state();
    $renderer = new NodeRenderer($context);
    $render = static fn(array $visibleIf, ?array $renderState = null): string => $renderer->render([condition_test_heading('Conditional', $visibleIf)], $renderState ?? $state);

    assert_true(str_contains($render(condition_test_if('category', 'equals', 'tips')), 'Conditional'));
    assert_same('', $render(condition_test_if('category', 'equals', 'news')));
    assert_same('', $render(condition_test_if('category', 'empty')));
    assert_true(str_contains($render(condition_test_if('body', 'contains', 'BOLD')), 'Conditional'));
    // Relation paths and any/all.
    $bio = condition_test_if('author', 'equals', 'Writes code.');
    $bio['conditions'][0]['field']['path'][] = 'bio';
    assert_true(str_contains($render($bio), 'Conditional'));
    $either = ['match' => 'any', 'conditions' => [...condition_test_if('category', 'equals', 'news')['conditions'], ...condition_test_if('category', 'equals', 'tips')['conditions']]];
    assert_true(str_contains($render($either), 'Conditional'));
    assert_same('', $render(['match' => 'all'] + $either));
    // Without an entry, fields read as empty.
    assert_true(str_contains($render(condition_test_if('category', 'empty'), []), 'Conditional'));
    assert_same('', $render(condition_test_if('category', 'equals', 'tips'), []));

    // The builder keeps failed elements so they stay selectable, marked as such.
    $context->annotate = true;
    $marked = (new NodeRenderer($context))->render([condition_test_heading('Conditional', condition_test_if('category', 'equals', 'news'))], $state);
    assert_true(str_contains($marked, 'c-condition-off') && str_contains($marked, 'Conditional'));
    $shown = (new NodeRenderer($context))->render([condition_test_heading('Conditional', condition_test_if('category', 'equals', 'tips'))], $state);
    assert_false(str_contains($shown, 'c-condition-off'));
});

test('element conditions read each card entry and each repeater row', function (): void {
    comet_site_test_setup();
    ContentRepository::make()->save('blog', ['title' => 'News post', 'slug' => 'news-post', 'category' => 'news', 'status' => 'published'], comet_site_test_user());
    [$context, $state] = smart_test_state();
    $renderer = new NodeRenderer($context);

    $cards = LayoutSchema::normalize([LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [
        LayoutSchema::node('collection', ['type' => 'blog'], [], [LayoutSchema::node('column', [], [], [
            condition_test_heading('Tip badge', condition_test_if('category', 'equals', 'tips')),
        ])]),
    ])])])]);
    $html = $renderer->render($cards, ['path' => '/']);
    assert_same(2, substr_count($html, '<article class="c-card">'));
    assert_same(1, substr_count($html, 'Tip badge'));

    $repeater = [LayoutSchema::node('field', ['source' => SmartValue::reference('steps'), 'display' => 'items'], [], [
        LayoutSchema::node('text', ['html' => SmartValue::reference('heading', 'item')], ['visible_if' => condition_test_if('heading', 'contains', 'second', 'item')]),
    ])];
    $html = $renderer->render($repeater, $state);
    assert_false(str_contains($html, 'First step'));
    assert_true(str_contains($html, 'Second step'));
});
