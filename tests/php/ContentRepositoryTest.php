<?php

declare(strict_types=1);

use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\ValidationException;

function comet_content_test_types(): ContentTypeRepository
{
    return new ContentTypeRepository();
}

function comet_content_test_repository(): ContentRepository
{
    return ContentRepository::make();
}

function comet_content_test_user(string $id = 'admin'): array
{
    return ['id' => $id, 'role' => 'admin'];
}

function comet_content_test_save_posts_schema(array $overrides = []): void
{
    comet_content_test_types()->save([
        'name' => 'posts',
        'fields' => [
            'summary' => ['type' => 'textarea'],
            'views' => ['type' => 'number'],
            'featured' => ['type' => 'boolean'],
        ],
        ...$overrides,
    ]);
}

test('content repository creates updates and records revisions', function (): void {
    comet_content_test_save_posts_schema();
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $created = $repository->save('posts', [
        'title' => 'Launch Notes',
        'summary' => 'Initial summary',
        'views' => '5',
        'featured' => '1',
        'status' => 'published',
    ], $user);

    assert_same('launch-notes', $created['id']);
    assert_same(5, $created['views']);
    assert_same(true, $created['featured']);
    assert_true($created['published_at'] !== null);

    $updated = $repository->save('posts', [
        'title' => 'Launch Notes Revised',
        'slug' => 'launch-notes',
        'summary' => 'Updated summary',
        'views' => 12,
    ], $user, 'launch-notes');

    assert_same('launch-notes', $updated['id']);
    assert_same($created['uid'], $updated['uid']);
    assert_same('Updated summary', $repository->find('posts', 'launch-notes')['summary']);

    $revisions = $repository->revisions('posts', 'launch-notes');
    assert_same(1, count($revisions));
    assert_same('content.updated', $revisions[0]['event']);
    assert_same('Launch Notes', $revisions[0]['entry']['title']);
});

test('content repository resolves automatic slug conflicts and rejects manual conflicts', function (): void {
    comet_content_test_save_posts_schema();
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $first = $repository->save('posts', ['title' => 'Same Title'], $user);
    $second = $repository->save('posts', ['title' => 'Same Title'], $user);

    assert_same('same-title', $first['id']);
    assert_same('same-title-2', $second['id']);
    assert_throws(ValidationException::class, static fn() => $repository->save('posts', [
        'title' => 'Manual Conflict',
        'slug' => 'same-title',
    ], $user, null, false));
});

test('content repository duplicates entries as draft copies', function (): void {
    comet_content_test_save_posts_schema();
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $created = $repository->save('posts', [
        'title' => 'Launch Notes',
        'summary' => 'Initial summary',
        'views' => 5,
        'featured' => true,
        'status' => 'published',
    ], $user);

    $copy = $repository->duplicate('posts', 'launch-notes', $user);

    assert_same('launch-notes-2', $copy['id']);
    assert_true($copy['uid'] !== $created['uid']);
    assert_same('draft', $copy['status']);
    assert_same('Copy of Launch Notes', $copy['title']);
    assert_same('Initial summary', $copy['summary']);
    assert_same(5, $copy['views']);
    assert_same(true, $copy['featured']);
});

test('content repository enforces singleton content types', function (): void {
    comet_content_test_types()->save([
        'name' => 'homepage',
        'singleton' => true,
    ]);
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $entry = $repository->save('homepage', ['title' => 'Home'], $user);

    assert_same('homepage', $entry['id']);
    assert_throws(ValidationException::class, static fn() => $repository->save('homepage', ['title' => 'Another Home'], $user));
});

test('content repository soft deletes restores and purges entries', function (): void {
    comet_content_test_save_posts_schema();
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $repository->save('posts', ['title' => 'Disposable'], $user);
    $repository->softDelete('posts', 'disposable', $user);

    assert_null($repository->find('posts', 'disposable'));
    assert_true($repository->find('posts', 'disposable', true)['deleted_at'] !== null);

    $restored = $repository->restore('posts', 'disposable', $user);
    assert_same('disposable', $restored['id']);
    assert_true($repository->find('posts', 'disposable') !== null);

    $repository->softDelete('posts', 'disposable', $user);
    $repository->purge('posts', 'disposable', $user);
    assert_null($repository->find('posts', 'disposable', true));
});

test('content repository queries visible searchable sortable and filterable entries', function (): void {
    comet_content_test_save_posts_schema();
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $repository->save('posts', [
        'title' => 'Alpha',
        'summary' => 'Public guide',
        'views' => 10,
        'featured' => true,
        'status' => 'published',
        'published_at' => '2024-01-01T00:00:00Z',
    ], $user);
    $repository->save('posts', [
        'title' => 'Beta',
        'summary' => 'Draft guide',
        'views' => 2,
        'status' => 'draft',
    ], $user);
    $repository->save('posts', [
        'title' => 'Gamma',
        'summary' => 'Future guide',
        'views' => 20,
        'status' => 'published',
        'published_at' => '2999-01-01T00:00:00Z',
    ], $user);

    assert_same(['alpha'], array_column($repository->query('posts', [], false)['data'], 'id'));
    assert_same(['beta'], array_column($repository->query('posts', ['filter' => ['status' => 'draft']], true)['data'], 'id'));
    assert_same(['alpha'], array_column($repository->query('posts', ['q' => 'public'], true)['data'], 'id'));
    assert_same(['gamma', 'alpha'], array_column($repository->query('posts', [
        'filter' => ['views' => ['gte' => 10]],
        'sort' => '-views',
    ], true)['data'], 'id'));
});

test('content repository stores and resolves localized entries', function (): void {
    comet_content_test_save_posts_schema([
        'locales' => ['en', 'de'],
        'default_locale' => 'en',
    ]);
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $repository->save('posts', [
        'locale' => 'en',
        'title' => 'Hello',
        'summary' => 'English summary',
    ], $user);
    $repository->save('posts', [
        'locale' => 'de',
        'title' => 'Hallo',
        'slug' => 'hello',
        'summary' => 'Deutsche Zusammenfassung',
    ], $user, 'hello');

    $stored = $repository->find('posts', 'hello');

    assert_same('Hello', $stored['title']);
    assert_same('English summary', $stored['summary']);
    assert_same('Hallo', $repository->resolveLocaleFields($stored, 'de')['title']);
    assert_same('Deutsche Zusammenfassung', $repository->query('posts', ['locale' => 'de', 'q' => 'deutsche'], true)['data'][0]['summary']);
    assert_null($repository->deleteTranslation('posts', 'hello', 'en', $user));
    $afterDelete = $repository->deleteTranslation('posts', 'hello', 'de', $user);
    assert_false(array_key_exists('de', $afterDelete['translations']));
    assert_true(array_key_exists('en', $afterDelete['translations']));
});

test('content repository bulk updates localized fields in all existing translations', function (): void {
    comet_content_test_save_posts_schema([
        'locales' => ['en', 'de'],
        'default_locale' => 'en',
    ]);
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $repository->save('posts', [
        'locale' => 'en',
        'title' => 'Hello',
        'summary' => 'English summary',
    ], $user);
    $repository->save('posts', [
        'locale' => 'de',
        'title' => 'Hallo',
        'slug' => 'hello',
        'summary' => 'Deutsche Zusammenfassung',
    ], $user, 'hello');

    $existing = $repository->find('posts', 'hello');
    $updated = $repository->bulkUpdateFields('posts', $existing, ['summary' => 'Bulk summary'], $user);

    assert_same('Bulk summary', $updated['translations']['en']['summary']);
    assert_same('Bulk summary', $updated['translations']['de']['summary']);
    assert_same('Hallo', $updated['translations']['de']['title']);
    assert_same('Bulk summary', $repository->resolveLocaleFields($updated, 'de')['summary']);
});

test('content repository bulk updates localized key-label select fields without validating unrelated fields', function (): void {
    comet_content_test_save_posts_schema([
        'locales' => ['en', 'de'],
        'default_locale' => 'en',
        'fields' => [
            'summary' => ['type' => 'textarea'],
            'client' => ['type' => 'select', 'options' => ['kunde' => 'Name Des Kunden', 'hospiz' => 'Hospiz Bewegung Salzburg']],
            'legacy' => ['type' => 'select', 'options' => ['new']],
        ],
    ]);
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $repository->save('posts', [
        'locale' => 'en',
        'title' => 'Hello',
        'summary' => 'English summary',
        'client' => 'kunde',
        'legacy' => 'new',
    ], $user);
    $repository->save('posts', [
        'locale' => 'de',
        'title' => 'Hallo',
        'slug' => 'hello',
        'summary' => 'Deutsche Zusammenfassung',
        'client' => 'kunde',
        'legacy' => 'new',
    ], $user, 'hello');

    $existing = $repository->find('posts', 'hello');
    $existing['legacy'] = 'old';
    $existing['translations']['en']['legacy'] = 'old';
    $existing['translations']['de']['legacy'] = 'old';

    $updated = $repository->bulkUpdateFields('posts', $existing, ['client' => 'hospiz'], $user);

    assert_same('hospiz', $updated['translations']['en']['client']);
    assert_same('hospiz', $updated['translations']['de']['client']);
    assert_same('old', $updated['translations']['de']['legacy']);
    assert_same('hospiz', $repository->resolveLocaleFields($updated, 'de')['client']);
});

test('content repository duplicates localized entries with all translations', function (): void {
    comet_content_test_save_posts_schema([
        'locales' => ['en', 'de'],
        'default_locale' => 'en',
        'fields' => [
            'summary' => ['type' => 'textarea', 'localized' => false],
            'views' => ['type' => 'number'],
            'featured' => ['type' => 'boolean'],
        ],
    ]);
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $repository->save('posts', [
        'locale' => 'en',
        'title' => 'Hello',
        'summary' => 'Shared summary',
        'views' => 5,
    ], $user);
    $repository->save('posts', [
        'locale' => 'de',
        'title' => 'Hallo',
        'slug' => 'hello',
        'summary' => 'Shared summary',
        'views' => 7,
    ], $user, 'hello');
    $created = $repository->save('posts', [
        'locale' => 'en',
        'slug' => 'hello',
        'title' => 'Hello',
        'summary' => 'Shared summary',
        'views' => 5,
    ], $user, 'hello');

    $copy = $repository->duplicate('posts', 'hello', $user);

    assert_same('hello-2', $copy['id']);
    assert_true($copy['uid'] !== $created['uid']);
    assert_same('draft', $copy['status']);
    assert_same('Copy of Hello', $copy['title']);
    assert_same('Copy of Hello', $copy['translations']['en']['title']);
    assert_same('Copy of Hallo', $copy['translations']['de']['title']);
    assert_same('Shared summary', $copy['translations']['en']['summary']);
    assert_same('Shared summary', $copy['translations']['de']['summary']);
    assert_same(7, $copy['translations']['de']['views']);
    assert_false(array_key_exists('translation_locks', $copy));
    assert_same('Copy of Hallo', $repository->resolveLocaleFields($copy, 'de')['title']);
});

test('content repository replaces media filenames in root translations and repeaters', function (): void {
    file_put_contents(comet_test_workspace_path() . '/media/old.jpg', 'old');
    file_put_contents(comet_test_workspace_path() . '/media/new.jpg', 'new');

    comet_content_test_save_posts_schema([
        'locales' => ['en', 'de'],
        'default_locale' => 'en',
        'fields' => [
            'hero' => ['type' => 'media'],
            'gallery' => ['type' => 'media', 'multiple' => true],
            'blocks' => [
                'type' => 'repeater',
                'subfields' => [
                    ['key' => 'image', 'type' => 'media'],
                ],
            ],
        ],
    ]);
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $repository->save('posts', [
        'locale' => 'en',
        'title' => 'Hello',
        'hero' => ['old.jpg'],
        'gallery' => ['old.jpg'],
        'blocks' => [['image' => ['old.jpg']]],
    ], $user);
    $repository->save('posts', [
        'locale' => 'de',
        'title' => 'Hallo',
        'slug' => 'hello',
        'hero' => ['old.jpg'],
        'gallery' => ['old.jpg'],
        'blocks' => [['image' => ['old.jpg']]],
    ], $user, 'hello');

    $updated = $repository->replaceMediaFilename('old.jpg', 'new.jpg');
    $stored = $repository->find('posts', 'hello');

    assert_same(1, $updated);
    assert_same(['new.jpg'], $stored['hero']);
    assert_same(['new.jpg'], $stored['gallery']);
    assert_same(['new.jpg'], $stored['blocks'][0]['image']);
    assert_same(['new.jpg'], $stored['translations']['en']['hero']);
    assert_same(['new.jpg'], $stored['translations']['de']['gallery']);
    assert_same(['new.jpg'], $stored['translations']['de']['blocks'][0]['image']);
});

test('content repository uses default locale values for universal fields', function (): void {
    comet_content_test_save_posts_schema([
        'locales' => ['en', 'de'],
        'default_locale' => 'en',
        'fields' => [
            'summary' => ['type' => 'textarea', 'localized' => false],
            'views' => ['type' => 'number'],
            'featured' => ['type' => 'boolean'],
        ],
    ]);
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $repository->save('posts', [
        'locale' => 'en',
        'title' => 'Hello',
        'summary' => 'Shared summary',
        'views' => 5,
    ], $user);
    $repository->save('posts', [
        'locale' => 'de',
        'title' => 'Hallo',
        'slug' => 'hello',
        'summary' => 'Deutsche Zusammenfassung',
        'views' => 7,
    ], $user, 'hello');

    $synced = $repository->save('posts', [
        'locale' => 'en',
        'slug' => 'hello',
        'title' => 'Hello',
        'summary' => 'Shared summary',
        'views' => 5,
    ], $user, 'hello');

    assert_same('Shared summary', $synced['translations']['de']['summary']);
    assert_false(array_key_exists('translation_locks', $synced));

    $updated = $repository->save('posts', [
        'locale' => 'de',
        'slug' => 'hello',
        'title' => 'Hallo',
        'summary' => 'Attempted change',
        'views' => 8,
    ], $user, 'hello');

    assert_same('Shared summary', $updated['translations']['de']['summary']);
    assert_same('Shared summary', $repository->resolveLocaleFields($updated, 'de')['summary']);
    assert_same(8, $repository->resolveLocaleFields($updated, 'de')['views']);
});

test('content repository seeds default locale from root content', function (): void {
    comet_content_test_save_posts_schema();
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $repository->save('posts', [
        'title' => 'Plain Entry',
        'summary' => 'Root summary',
        'views' => 7,
        'featured' => true,
    ], $user);

    comet_content_test_save_posts_schema([
        'locales' => ['en', 'de'],
        'default_locale' => 'en',
    ]);
    $schema = comet_content_test_types()->find('posts');

    $repository->seedDefaultLocaleFromRoot('posts', $schema);
    $stored = $repository->find('posts', 'plain-entry');

    assert_same('Plain Entry', $stored['translations']['en']['title']);
    assert_same('Root summary', $stored['translations']['en']['summary']);
    assert_same(7, $stored['translations']['en']['views']);
    assert_same(true, $stored['translations']['en']['featured']);
    assert_false(array_key_exists('slug', $stored['translations']['en']));

    $repository->save('posts', [
        'locale' => 'en',
        'title' => 'Edited English',
        'slug' => 'plain-entry',
        'summary' => 'Edited summary',
    ], $user, 'plain-entry');

    $repository->seedDefaultLocaleFromRoot('posts', $schema);
    $stored = $repository->find('posts', 'plain-entry');

    assert_same('Edited English', $stored['translations']['en']['title']);
    assert_same('Edited summary', $stored['translations']['en']['summary']);
});
