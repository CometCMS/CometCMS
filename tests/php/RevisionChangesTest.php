<?php

declare(strict_types=1);

use CometCMS\Site\LayoutSchema;
use CometCMS\Site\RevisionChanges;

function revision_changes_tree(array $elements, string $sectionId = 's1'): array
{
    $section = LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], $elements)])]);
    $section['id'] = $sectionId;
    $section['children'][0]['id'] = $sectionId . '-row';
    $section['children'][0]['children'][0]['id'] = $sectionId . '-col';

    return [$section];
}

function revision_changes_node(string $id, string $kind, array $props = [], array $style = []): array
{
    return ['id' => $id] + LayoutSchema::node($kind, $props, $style);
}

function revision_changes_actions(array $changes): array
{
    return array_map(static fn(array $change): string => $change['action'] . ':' . $change['kind'] . ':' . $change['label'], $changes);
}

test('revision changes tell added, removed, edited, styled and moved elements apart', function (): void {
    $heading = revision_changes_node('h', 'heading', ['text' => 'Welcome']);
    $button = revision_changes_node('b', 'button', ['label' => 'Learn more']);
    $text = revision_changes_node('t', 'text', ['html' => '<p>Hello <strong>there</strong></p>']);
    $before = revision_changes_tree([$heading, $button, $text]);

    assert_same([], RevisionChanges::nodes($before, $before));

    $edited = $heading;
    $edited['props']['text'] = 'Hello world';
    $styled = $button;
    $styled['style']['background'] = 'accent';
    $image = revision_changes_node('i', 'image', ['alt' => 'Team photo']);
    assert_same(
        ['edited:heading:Hello world', 'styled:button:Learn more', 'added:image:Team photo', 'removed:text:Hello there'],
        revision_changes_actions(RevisionChanges::nodes($before, revision_changes_tree([$edited, $styled, $image]))),
    );

    // Moving one element counts once, not every sibling whose index shifted.
    assert_same(['moved:text:Hello there'], revision_changes_actions(RevisionChanges::nodes($before, revision_changes_tree([$text, $heading, $button]))));

    // A new section is one change, not one per element inside it.
    $after = [...$before, ...revision_changes_tree([revision_changes_node('h2', 'heading', ['text' => 'More'])], 's2')];
    assert_same(['added:section:'], revision_changes_actions(RevisionChanges::nodes($before, $after)));
    assert_same(['removed:section:'], revision_changes_actions(RevisionChanges::nodes($after, $before)));

    // Names win over content, and long texts are shortened.
    $named = $heading;
    $named['name'] = 'Hero title';
    assert_same(['styled:heading:Hero title'], revision_changes_actions(RevisionChanges::nodes($before, revision_changes_tree([$named, $button, $text]))));
    $long = $heading;
    $long['props']['text'] = ['type' => 'localized', 'values' => ['en' => str_repeat('Long text ', 10)]];
    $label = RevisionChanges::nodes($before, revision_changes_tree([$long, $button, $text]))[0]['label'];
    assert_true(mb_strlen($label) <= 40 && str_ends_with($label, '…'));
});

test('revision changes group theme differences and list changed entry fields', function (): void {
    $theme = ['colors' => ['primary' => '#000'], 'fonts' => ['body' => 'a'], 'section_padding' => 'md', 'custom_css' => '', 'updated_at' => '1'];
    $after = ['colors' => ['primary' => '#fff'], 'fonts' => ['body' => 'a'], 'section_padding' => 'lg', 'custom_css' => 'a{}', 'updated_at' => '2'];
    assert_same(['colors', 'css', 'spacing'], array_column(RevisionChanges::theme($theme, $after), 'group'));
    assert_same([], RevisionChanges::theme($theme, ['updated_at' => '3'] + $theme));

    $fields = RevisionChanges::fields(['title' => 'A', 'body' => 'x', 'page' => 1], ['title' => 'B', 'body' => 'x', 'page' => 2], ['title' => 'Title', 'body' => 'Body', 'page' => 'Page'], 'page');
    assert_same(['field:field:Title'], revision_changes_actions($fields));
});

test('revision lists say what the save after each version changed', function (): void {
    $site = comet_site_test_setup();
    $user = ['id' => 'andi', 'username' => 'Andi'];
    $heading = revision_changes_node('h', 'heading', ['text' => 'Welcome']);
    $first = $site->saveLayout('about', 'detail', revision_changes_tree([$heading]), $user);
    $site->saveLayout('about', 'detail', [...$first, ...revision_changes_tree([revision_changes_node('b', 'button', ['label' => 'Go'])], 's2')], $user);

    $revisions = $site->layoutRevisions('about', 'detail');
    assert_same(2, count($revisions));
    assert_same(['added:section:'], revision_changes_actions($revisions[0]['changes']['items']), 'The newest save added the second section.');
    assert_same(1, $revisions[0]['changes']['total']);
    assert_true($revisions[1]['changes']['total'] > 0, 'The first save replaced the default layout.');
    assert_false(isset($revisions[0]['snapshot']));

    $theme = $site->theme();
    $theme['colors']['primary'] = '#123456';
    $site->saveTheme($theme, $user);
    assert_same(['colors'], array_column($site->themeRevisions()[0]['changes']['items'], 'group'));
});
