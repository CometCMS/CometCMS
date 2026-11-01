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

test('content read snapshots follow writes from other repositories and stay workspace scoped', function (): void {
    comet_content_test_save_posts_schema();
    $reader = ContentRepository::make();
    $writer = ContentRepository::make();
    $entry = $writer->save('posts', ['title' => 'First', 'status' => 'published'], comet_content_test_user());
    assert_same('First', $reader->findByIdentifier('posts', $entry['uid'])['title']);
    assert_same(1, $reader->query('posts', [])['meta']['total']);

    $writer->save('posts', ['title' => 'Renamed', 'slug' => 'renamed'], comet_content_test_user(), $entry['id']);
    assert_same('Renamed', $reader->findByIdentifier('posts', $entry['uid'])['title']);
    assert_null($reader->findByIdentifier('posts', 'first'));
    assert_same($entry['uid'], $reader->findByIdentifier('posts', 'renamed')['uid']);
    $writer->softDelete('posts', $entry['id']);
    assert_null($reader->findByIdentifier('posts', $entry['uid']));
    assert_same(0, $reader->query('posts', [])['meta']['total']);
    assert_same($entry['uid'], $reader->findByIdentifier('posts', $entry['uid'], true)['uid']);
    $writer->restore('posts', $entry['id']);
    assert_same(1, $reader->query('posts', [])['meta']['total']);

    $other = ContentRepository::make(new \CometCMS\Workspaces\WorkspaceContext('other'));
    assert_null($other->findByIdentifier('posts', $entry['uid']));
    assert_same(0, $other->query('posts', [])['meta']['total']);
});

test('content writers refresh snapshots after acquiring the lock and reject stale revisions', function (): void {
    comet_content_test_save_posts_schema();
    $reader = ContentRepository::make();
    $entry = $reader->save('posts', ['title' => 'Original'], comet_content_test_user());
    $reader->query('posts', [], true);
    $store = new \CometCMS\Storage\JsonStore(comet_test_workspace_path() . '/content');
    $raw = $store->read('posts', $entry['id']);
    $raw['revision'] = 'changed-elsewhere';
    // A different PHP request does not share the in-process mutation generation.
    file_put_contents($store->path('posts', $entry['id']) . '.json', json_encode($raw));
    assert_throws(\CometCMS\Core\ConflictException::class, fn() => $reader->save('posts', ['title' => 'Stale', 'revision' => $entry['revision']], comet_content_test_user(), $entry['id']));

    $reader->query('posts', [], true);
    $raw['id'] = 'taken';
    $raw['uid'] = 'external-entry';
    $raw['values']['slug'] = \CometCMS\Localization\LocalizedValue::set(null, 'en', 'taken', 'en');
    file_put_contents($store->path('posts', 'taken') . '.json', json_encode($raw));
    assert_same('taken-2', $reader->save('posts', ['title' => 'Taken'], comet_content_test_user())['slug']);
});

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

    assert_same('launch-notes-2', $copy['slug']);
    assert_true($copy['id'] !== $created['id']);
    assert_true($copy['uid'] !== $created['uid']);
    assert_same('draft', $copy['status']);
    assert_same('Copy of Launch Notes', $copy['title']);
    assert_same('Initial summary', $copy['summary']);
    assert_same(5, $copy['views']);
    assert_same(true, $copy['featured']);
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
    assert_false(array_key_exists('de', $afterDelete['publication']));
    assert_true(array_key_exists('en', $afterDelete['publication']));
});

test('content repository bulk updates only the selected language', function (): void {
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

    assert_same('Bulk summary', $updated['values']['summary']['values']['en']);
    assert_same('Deutsche Zusammenfassung', $updated['values']['summary']['values']['de']);
    assert_same('Hallo', $updated['values']['title']['values']['de']);
    assert_same('Deutsche Zusammenfassung', $repository->resolveLocaleFields($updated, 'de')['summary']);
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
    // Partial updates leave unrelated fields and languages untouched.

    $updated = $repository->bulkUpdateFields('posts', $existing, ['client' => 'hospiz'], $user);

    assert_same('hospiz', $updated['values']['client']['values']['en']);
    assert_same('kunde', $updated['values']['client']['values']['de']);
    assert_same('new', $updated['values']['legacy']['values']['de']);
    assert_same('kunde', $repository->resolveLocaleFields($updated, 'de')['client']);
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

    assert_same('hello-2', $copy['slug']);
    assert_true($copy['uid'] !== $created['uid']);
    assert_same('draft', $copy['status']);
    assert_same('Copy of Hello', $copy['title']);
    assert_same('Copy of Hello', $copy['values']['title']['values']['en']);
    assert_same('Copy of Hallo', $copy['values']['title']['values']['de']);
    assert_same('Shared summary', $copy['values']['summary']);
    assert_same('Shared summary', $copy['values']['summary']);
    assert_same(7, $copy['values']['views']['values']['de']);
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
    assert_same(['new.jpg'], $stored['values']['hero']['values']['en']);
    assert_same(['new.jpg'], $stored['values']['gallery']['values']['de']);
    assert_same(['new.jpg'], $stored['values']['blocks']['values']['de'][0]['image']);
});

test('content repository rejects changes to shared values from another language', function (): void {
    comet_content_test_save_posts_schema(['locales' => ['en', 'de'], 'fields' => ['summary' => ['type' => 'textarea', 'localized' => false], 'views' => ['type' => 'number']]]);
    $repository = comet_content_test_repository(); $user = comet_content_test_user();
    $entry = $repository->save('posts', ['locale' => 'en', 'title' => 'Hello', 'summary' => 'Shared summary'], $user);
    assert_throws(ValidationException::class, fn() => $repository->save('posts', ['locale' => 'de', 'summary' => 'Attempted change'], $user, $entry['id']));
    $updated = $repository->save('posts', ['locale' => 'de', 'title' => 'Hallo', 'views' => 8], $user, $entry['id']);
    assert_same('Shared summary', $updated['values']['summary']);
    assert_same('Shared summary', $repository->resolveLocaleFields($updated, 'de')['summary']);
    assert_same(8, $updated['views']);
});

test('enabling another language does not rewrite saved values or create a translation', function (): void {
    comet_content_test_save_posts_schema();
    $repository = comet_content_test_repository();
    $entry = $repository->save('posts', ['title' => 'Plain Entry', 'summary' => 'Source'], comet_content_test_user());
    $path = comet_test_workspace_path() . '/content/posts/' . $entry['id'] . '.json';
    $before = file_get_contents($path);
    comet_content_test_save_posts_schema(['locales' => ['en', 'de']]);
    assert_same($before, file_get_contents($path));
    $stored = $repository->find('posts', $entry['id']);
    assert_same('Plain Entry', $stored['values']['title']['values']['en']);
    assert_false(isset($stored['publication']['de']));
});

test('fields named body and data are kept on read and survive partial updates', function (): void {
    comet_content_test_types()->save([
        'name' => 'articles',
        'fields' => [
            'body' => ['type' => 'textarea'],
            'data' => ['type' => 'json'],
            'summary' => ['type' => 'text'],
        ],
    ]);
    $repository = comet_content_test_repository();
    $user = comet_content_test_user();

    $entry = $repository->save('articles', ['title' => 'Hello', 'body' => 'Long text', 'data' => ['a' => 1], 'summary' => 'Short'], $user);
    assert_same('Long text', $repository->find('articles', $entry['id'])['body'] ?? null);
    assert_same(['a' => 1], $repository->find('articles', $entry['id'])['data'] ?? null);

    // A bulk edit of another field must not wipe body/data.
    $repository->bulkUpdateFields('articles', $repository->find('articles', $entry['id']), ['summary' => 'Changed'], $user);
    $reloaded = $repository->all('articles')[0];
    assert_same('Long text', $reloaded['body'] ?? null);
    assert_same(['a' => 1], $reloaded['data'] ?? null);
    assert_same('Changed', $reloaded['summary'] ?? null);
});

test('legacy root body and data keys are dropped when the schema does not define them', function (): void {
    comet_content_test_save_posts_schema();
    $repository = comet_content_test_repository();
    $entry = $repository->normalizeStoredEntry(['id' => 'old', 'collection' => 'posts', 'title' => 'Old', 'body' => 'legacy', 'data' => ['x' => 1]]);

    assert_false(array_key_exists('body', $entry));
    assert_false(array_key_exists('data', $entry));
});
