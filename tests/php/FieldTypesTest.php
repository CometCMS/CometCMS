<?php

declare(strict_types=1);

use CometCMS\Fields\FieldRegistry;
use CometCMS\Fields\BooleanFieldType;
use CometCMS\Fields\ColorFieldType;
use CometCMS\Fields\DateFieldType;
use CometCMS\Fields\DateTimeFieldType;
use CometCMS\Fields\JsonFieldType;
use CometCMS\Fields\MediaFieldType;
use CometCMS\Fields\NumberFieldType;
use CometCMS\Fields\RangeFieldType;
use CometCMS\Fields\RelationFieldType;
use CometCMS\Fields\RepeaterFieldType;
use CometCMS\Fields\SelectFieldType;
use CometCMS\Fields\SlugFieldType;
use CometCMS\Storage\JsonStore;
use CometCMS\Workspaces\WorkspaceContext;

test('field registry exposes built-in field types', function (): void {
    $registry = FieldRegistry::builtins();

    assert_same(
        [
            'text',
            'textarea',
            'markdown',
            'number',
            'range',
            'boolean',
            'select',
            'date',
            'datetime',
            'media',
            'slug',
            'json',
            'relation',
            'repeater',
            'color',
        ],
        $registry->names()
    );
});

test('number fields validate and normalize numeric input', function (): void {
    $field = new NumberFieldType();

    assert_true($field->validate('42.5', [])['valid']);
    assert_false($field->validate('forty-two', [])['valid']);
    assert_same(42.5, $field->normalize('42.5', []));
    assert_same(42, $field->normalize('42', []));
    assert_null($field->normalize('', []));
});

test('select fields enforce configured options', function (): void {
    $field = new SelectFieldType();

    assert_true($field->validate('draft', ['options' => ['draft', 'published']])['valid']);
    $invalid = $field->validate('archived', ['options' => ['draft', 'published']]);

    assert_false($invalid['valid']);
    assert_same('Choose a valid option. Valid values: draft, published.', $invalid['message'] ?? null);
});

test('select field validation lists valid stored values for mapped options', function (): void {
    $field = new SelectFieldType();
    $invalid = $field->validate('archived', [
        'options' => [
            'draft' => 'Draft',
            'published' => 'Published',
        ],
    ]);

    assert_false($invalid['valid']);
    assert_same('Choose a valid option. Valid values: draft, published.', $invalid['message'] ?? null);
});

test('multi-select fields normalize unique non-empty values', function (): void {
    $field = new SelectFieldType();

    assert_same(['news', 'launch'], $field->normalize([' news ', '', 'launch', 'news'], ['multiple' => true]));
});

test('range fields validate min max and step constraints', function (): void {
    $field = new RangeFieldType();

    assert_true($field->validate('5', ['min' => 1, 'max' => 10, 'step' => 1])['valid']);
    assert_false($field->validate('0', ['min' => 1])['valid']);
    assert_false($field->validate('11', ['max' => 10])['valid']);
    assert_false($field->validate('5', ['step' => 0])['valid']);
});

test('range fields normalize default and numeric values', function (): void {
    $field = new RangeFieldType();

    assert_same(3, $field->normalize('', ['default' => 3]));
    assert_same(2.5, $field->normalize('2.5', []));
    assert_null($field->normalize('', []));
});

test('boolean fields normalize truthy and falsy values', function (): void {
    $field = new BooleanFieldType();

    assert_true($field->normalize('1', []));
    assert_true($field->normalize('true', []));
    assert_true($field->normalize('on', []));
    assert_false($field->normalize('0', []));
    assert_false($field->normalize('false', []));
    assert_false($field->normalize('', []));
});

test('date and datetime fields validate valid and invalid dates', function (): void {
    $date = new DateFieldType();
    $datetime = new DateTimeFieldType();

    assert_true($date->validate('2026-05-08', [])['valid']);
    assert_false($date->validate('not-a-date', [])['valid']);

    assert_true($datetime->validate('2026-05-08 13:45:00', [])['valid']);
    assert_false($datetime->validate('not-a-datetime', [])['valid']);
});

test('media fields validate existing files and normalize to deduplicated basenames', function (): void {
    $field = new MediaFieldType();
    $mediaPath = WorkspaceContext::active()->path('media');
    $existing = $mediaPath . '/hero.jpg';

    file_put_contents($existing, 'binary-image-content');

    assert_true($field->validate(['hero.jpg'], ['multiple' => true])['valid']);
    assert_false($field->validate(['missing.jpg'], ['multiple' => true])['valid']);
    assert_same(
        ['hero.jpg'],
        $field->normalize(['hero.jpg', '/tmp/hero.jpg', 'hero.jpg'], ['multiple' => true])
    );
});

test('slug fields normalize free text into slug values', function (): void {
    $field = new SlugFieldType();

    assert_same('hello-world-2026', $field->normalize(' Hello World 2026! ', []));
});

test('json fields validate and normalize json values', function (): void {
    $field = new JsonFieldType();

    assert_true($field->validate('{"ok":true}', [])['valid']);
    assert_false($field->validate('{"ok":', [])['valid']);
    assert_same(['ok' => true], $field->normalize('{"ok":true}', []));
    assert_same(['ok' => true], $field->normalize(['ok' => true], []));
});

test('relation fields validate target collection lookup', function (): void {
    $field = new RelationFieldType();
    $store = new JsonStore(WorkspaceContext::active()->path('content'));

    $store->write(['id' => 'entry-1', 'title' => 'Entry 1'], 'posts', 'entry-1');

    assert_true($field->validate('entry-1', ['target' => 'posts'])['valid']);
    assert_false($field->validate('missing', ['target' => 'posts'])['valid']);
});

test('repeater fields validate nested subfields row by row', function (): void {
    $field = new RepeaterFieldType();
    $config = [
        'subfields' => [
            ['key' => 'title', 'type' => 'text', 'required' => true],
            ['key' => 'rating', 'type' => 'number'],
        ],
    ];

    assert_true($field->validate([
        ['title' => 'First row', 'rating' => '5'],
        ['title' => 'Second row', 'rating' => '3'],
    ], $config)['valid']);

    assert_false($field->validate([
        ['title' => '', 'rating' => '5'],
    ], $config)['valid']);
});

test('color fields validate hex format and normalize lowercased trimmed values', function (): void {
    $field = new ColorFieldType();

    assert_true($field->validate('#A1B2C3', [])['valid']);
    assert_false($field->validate('blue', [])['valid']);
    assert_same('#a1b2c3', $field->normalize(' #A1B2C3 ', []));
});
