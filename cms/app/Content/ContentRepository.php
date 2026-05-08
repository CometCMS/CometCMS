<?php

declare(strict_types=1);

namespace CometCMS\Content;

use CometCMS\Cache\ApiCache;
use CometCMS\Core\Security;
use CometCMS\Core\ValidationException;
use CometCMS\Fields\FieldRegistry;
use CometCMS\Logging\Logger;
use CometCMS\Storage\JsonStore;
use CometCMS\Trash\TrashStore;
use CometCMS\Webhooks\WebhookDispatcher;
use CometCMS\Workspaces\WorkspaceContext;

final class ContentRepository
{
    private JsonStore $store;
    private JsonStore $revisions;
    private WorkspaceContext $workspace;

    public function __construct(
        private readonly ContentTypeRepository $types = new ContentTypeRepository(),
        private readonly FieldRegistry $fields = new FieldRegistry(),
        private readonly Logger $logger = new Logger(),
        private readonly ApiCache $cache = new ApiCache(),
        private readonly WebhookDispatcher $webhooks = new WebhookDispatcher(),
        ?WorkspaceContext $workspace = null,
    ) {
        $this->workspace = $workspace ?? WorkspaceContext::active();
        WorkspaceContext::setActive($this->workspace->slug());
        $this->workspace->ensure();
        $this->store = new JsonStore($this->workspace->path('content'));
        $this->revisions = new JsonStore($this->workspace->path('revisions') . '/content');
    }

    public static function make(?WorkspaceContext $workspace = null): self
    {
        $workspace ??= WorkspaceContext::active();

        return new self(new ContentTypeRepository($workspace), FieldRegistry::builtins(), new Logger(), ApiCache::fromConfig($workspace), new WebhookDispatcher(), $workspace);
    }

    public function collections(): array
    {
        return $this->store->directories();
    }

    public function all(string $collection, bool $includeDeleted = false): array
    {
        Security::assertSafeName($collection);
        $entries = array_map(fn(array $entry): array => $this->normalizeStoredEntry($entry), $this->store->all($collection));

        if (!$includeDeleted) {
            $entries = array_values(array_filter($entries, static fn(array $entry): bool => empty($entry['deleted_at'])));
        }

        return $entries;
    }

    public function find(string $collection, string $id, bool $includeDeleted = false): ?array
    {
        Security::assertSafeName($collection);
        Security::assertSafeName($id);
        $entry = $this->store->read($collection, $id);

        if ($entry === null) {
            return null;
        }

        $entry = $this->normalizeStoredEntry($entry);

        if (!$includeDeleted && !empty($entry['deleted_at'])) {
            return null;
        }

        return $entry;
    }

    public function findByIdentifier(string $collection, string $identifier, bool $includeDeleted = false): ?array
    {
        $entry = $this->find($collection, $identifier, $includeDeleted);

        if ($entry !== null) {
            return $entry;
        }

        if ($identifier === $collection && $this->isSingleton($collection)) {
            return $this->singletonEntry($collection, $includeDeleted);
        }

        foreach ($this->all($collection, $includeDeleted) as $candidate) {
            if ($this->stableId($candidate) === $identifier) {
                return $candidate;
            }
        }

        return null;
    }

    public function stableId(array $entry): string
    {
        $uid = trim((string) ($entry['uid'] ?? ''));

        if ($uid !== '') {
            return $uid;
        }

        return substr(hash('sha256', implode('|', [
            (string) ($entry['collection'] ?? ''),
            (string) ($entry['created_at'] ?? ''),
            (string) ($entry['id'] ?? ''),
        ])), 0, 12);
    }

    public function save(
        string $collection,
        array $payload,
        array $user,
        ?string $existingId = null,
        bool $autoResolveSlugConflicts = true,
        ?array $validateOnlyFields = null,
    ): array {
        Security::assertSafeName($collection);
        $existing = $existingId !== null ? $this->find($collection, $existingId, true) : null;
        $entry = $this->normalizeAndValidate($collection, $payload, $user, $existing, $autoResolveSlugConflicts, $validateOnlyFields);
        $oldStatus = $existing['status'] ?? null;
        $oldId = $existing['id'] ?? null;

        if ($existing === null && $this->isSingleton($collection) && $this->singletonEntry($collection) !== null) {
            throw new ValidationException([
                'singleton' => 'This single page already has content. Edit the existing page instead.',
            ]);
        }

        if ($existing !== null) {
            if ($oldId !== null && $oldId !== $entry['id']) {
                $this->moveRevisionHistory($collection, (string) $oldId, (string) $entry['id']);
            }

            $this->writeRevision($collection, (string) $entry['id'], $existing, 'content.updated', $user);
        }

        $this->store->write($entry, $collection, (string) $entry['id']);

        if ($oldId !== null && $oldId !== $entry['id']) {
            $this->store->delete($collection, (string) $oldId);
        }

        $event = $existing === null ? 'content.created' : 'content.updated';
        $this->afterWrite($event, $collection, (string) $entry['id'], $user);

        if (($entry['status'] ?? '') === 'published' && $oldStatus !== 'published') {
            $this->afterWrite('content.published', $collection, (string) $entry['id'], $user);
        }

        if ($oldStatus === 'published' && ($entry['status'] ?? '') !== 'published') {
            $this->afterWrite('content.unpublished', $collection, (string) $entry['id'], $user);
        }

        return $entry;
    }

    public function bulkUpdateFields(string $collection, array $existing, array $data, array $user): array
    {
        $schema = $this->types->find($collection);
        $locales = is_array($schema['locales'] ?? null) ? $schema['locales'] : [];

        if ($locales === [] || !$this->containsLocalizedBulkField($data, $schema)) {
            return $this->save($collection, array_merge($existing, $data), $user, (string) ($existing['id'] ?? ''), true, array_keys($data));
        }

        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $slugField = (string) ($schema['slug_field'] ?? 'slug');
        $localizedData = [];
        $rootData = [];
        $errors = [];

        foreach ($data as $name => $value) {
            $name = (string) $name;

            if ($name === 'title') {
                $localizedData[$name] = (string) $value;
                continue;
            }

            $config = $fields[$name] ?? null;

            if (is_array($config) && $name !== $slugField) {
                $typeName = (string) ($config['type'] ?? 'text');
                $type = $this->fields->get($typeName);
                $result = $type->validate($value, $config, ['collection' => $collection, 'entry' => $existing]);

                if (($result['valid'] ?? false) === false) {
                    $errors[$name] = (string) ($result['message'] ?? 'Invalid value.');
                    continue;
                }

                $normalized = $type->normalize($value, $config, ['collection' => $collection, 'entry' => $existing]);

                if ($this->isLocalizedField($config)) {
                    $localizedData[$name] = $normalized;
                } else {
                    $rootData[$name] = $normalized;
                }

                continue;
            }

            $rootData[$name] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $entry = array_merge($existing, $rootData);
        $translations = is_array($entry['translations'] ?? null) ? $entry['translations'] : [];

        foreach ($this->bulkUpdateLocales($entry, $schema) as $locale) {
            $translation = is_array($translations[$locale] ?? null) ? $translations[$locale] : [];

            if (!array_key_exists('title', $translation)) {
                $translation['title'] = (string) ($entry['title'] ?? '');
            }

            $translations[$locale] = array_merge($translation, $localizedData);
        }

        $defaultLocale = (string) ($schema['default_locale'] ?? ($locales[0] ?? ''));
        $translations = $this->syncUniversalTranslationFields($translations, $fields, $slugField, $defaultLocale);
        $entry['translations'] = $translations;

        $defaultTranslation = $translations[$defaultLocale] ?? null;
        if (is_array($defaultTranslation)) {
            $entry = $this->applyTranslationToRoot($entry, $defaultTranslation, $schema);
        }

        $entry['updated_at'] = Security::now();
        $entry['updated_by'] = $user['id'] ?? null;

        $id = (string) ($entry['id'] ?? '');
        if ($id === '') {
            throw new ValidationException(['id' => 'Entry ID is required.']);
        }

        $this->writeRevision($collection, $id, $existing, 'content.updated', $user);
        $this->store->write($entry, $collection, $id);
        $this->afterWrite('content.updated', $collection, $id, $user);

        return $entry;
    }

    public function duplicate(string $collection, string $id, array $user): ?array
    {
        Security::assertSafeName($collection);
        Security::assertSafeName($id);

        $existing = $this->find($collection, $id, true);

        if ($existing === null) {
            return null;
        }

        if ($this->isSingleton($collection)) {
            throw new ValidationException([
                'singleton' => 'This single page already has content. Edit the existing page instead.',
            ]);
        }

        $schema = $this->types->find($collection);
        $locales = is_array($schema['locales'] ?? null) ? $schema['locales'] : [];
        $defaultLocale = (string) ($schema['default_locale'] ?? ($locales[0] ?? ''));
        $now = Security::now();
        $baseSlug = Security::slug((string) ($existing['slug'] ?? $existing['id'] ?? $existing['title'] ?? 'copy'));
        $slug = $this->uniqueSlug($collection, $baseSlug !== '' ? $baseSlug : 'copy');

        $entry = $existing;
        $entry['id'] = $slug;
        $entry['uid'] = Security::opaqueId();
        $entry['collection'] = $collection;
        $entry['slug'] = $slug;
        $entry['status'] = 'draft';
        $entry['created_at'] = $now;
        $entry['updated_at'] = $now;
        $entry['author_id'] = $user['id'] ?? null;
        $entry['updated_by'] = $user['id'] ?? null;

        unset($entry['scheduled_at'], $entry['deleted_at'], $entry['deleted_by'], $entry['_links']);

        $this->prefixCopyTitle($entry);

        if ($locales !== [] && is_array($entry['translations'] ?? null)) {
            foreach ($entry['translations'] as $locale => $translation) {
                if (!is_array($translation)) {
                    continue;
                }

                $this->prefixCopyTitle($translation);
                $entry['translations'][$locale] = $translation;
            }

            $entry['translations'] = $this->syncUniversalTranslationFields(
                $entry['translations'],
                is_array($schema['fields'] ?? null) ? $schema['fields'] : [],
                (string) ($schema['slug_field'] ?? 'slug'),
                $defaultLocale
            );
            $defaultTranslation = $defaultLocale !== '' ? ($entry['translations'][$defaultLocale] ?? null) : null;

            if (is_array($defaultTranslation)) {
                $entry = $this->applyTranslationToRoot($entry, $defaultTranslation, $schema);
            }

            $entry['id'] = $slug;
            $entry['slug'] = $slug;
        }

        $this->store->write($entry, $collection, $slug);
        $this->afterWrite('content.created', $collection, $slug, $user);

        return $entry;
    }

    public function revisions(string $collection, string $id): array
    {
        Security::assertSafeName($collection);
        Security::assertSafeName($id);

        return array_map(
            fn(array $revision): array => $this->normalizeRevision($revision),
            $this->revisions->all($collection, $id)
        );
    }

    public function restoreRevision(string $collection, string $id, string $revisionId, array $user): ?array
    {
        Security::assertSafeName($collection);
        Security::assertSafeName($id);
        Security::assertSafeName($revisionId);

        $revision = $this->revisions->read($collection, $id, $revisionId);

        if ($revision === null || !is_array($revision['entry'] ?? null)) {
            return null;
        }

        $current = $this->find($collection, $id, true);
        $entry = $this->normalizeStoredEntry($revision['entry']);
        $entry['collection'] = $collection;
        $entry['id'] = Security::slug((string) ($entry['id'] ?? $entry['slug'] ?? $id));
        $entry['slug'] = (string) ($entry['slug'] ?? $entry['id']);
        // Preserve the current entry's immutable uid
        $currentUid = $current !== null ? trim((string) ($current['uid'] ?? '')) : '';
        $revisionUid = trim((string) ($entry['uid'] ?? ''));
        $entry['uid'] = $currentUid !== '' ? $currentUid : ($revisionUid !== '' ? $revisionUid : Security::opaqueId());
        $entry['updated_at'] = Security::now();
        $entry['updated_by'] = $user['id'] ?? null;
        unset($entry['deleted_at'], $entry['deleted_by']);

        if ($entry['id'] === '') {
            $entry['id'] = $id;
            $entry['slug'] = $id;
        }

        if ($this->isSingleton($collection)) {
            $entry['id'] = $collection;
            $entry['slug'] = $collection;
        }

        if ($current !== null) {
            if ($id !== $entry['id']) {
                $this->moveRevisionHistory($collection, $id, (string) $entry['id']);
            }

            $this->writeRevision($collection, (string) $entry['id'], $current, 'content.revision_restore', $user);
        }

        $this->store->write($entry, $collection, (string) $entry['id']);

        if ($id !== $entry['id']) {
            $this->store->delete($collection, $id);
        }

        $this->afterWrite('content.updated', $collection, (string) $entry['id'], $user);

        return $entry;
    }

    public function softDelete(string $collection, string $id, ?array $user = null): void
    {
        $entry = $this->find($collection, $id, true);

        if ($entry === null) {
            return;
        }

        $entry['deleted_at'] = Security::now();
        $entry['deleted_by'] = $user['id'] ?? null;
        $this->store->write($entry, $collection, $id);
        (new TrashStore($this->workspace))->putContent($collection, $id, $entry);
        $this->afterWrite('content.deleted', $collection, $id, $user ?? []);
    }

    public function restore(string $collection, string $id, ?array $user = null): ?array
    {
        $entry = $this->find($collection, $id, true);

        if ($entry === null) {
            $entry = (new TrashStore($this->workspace))->findContent($collection, $id);
        }

        if ($entry === null) {
            return null;
        }

        $targetId = $id;
        if ($this->isSingleton($collection)) {
            $active = $this->singletonEntry($collection);
            if ($active !== null) {
                return null;
            }

            $targetId = $collection;
            $entry['id'] = $collection;
            $entry['slug'] = $collection;
        }

        unset($entry['deleted_at'], $entry['deleted_by']);
        $entry['updated_at'] = Security::now();
        $this->store->write($entry, $collection, $targetId);
        if ($targetId !== $id) {
            $this->store->delete($collection, $id);
        }
        (new TrashStore($this->workspace))->removeContent($collection, $id);
        $this->afterWrite('content.restored', $collection, $targetId, $user ?? []);

        return $entry;
    }

    public function purge(string $collection, string $id, ?array $user = null): void
    {
        $this->store->delete($collection, $id);
        (new TrashStore($this->workspace))->removeContent($collection, $id);
        $this->cache->clear();
        $this->logger->warning('content purged', ['type' => $collection, 'id' => $id, 'user_id' => $user['id'] ?? null]);
    }

    public function deleteTranslation(string $collection, string $id, string $locale, array $user): ?array
    {
        $entry = $this->find($collection, $id);

        if ($entry === null) {
            return null;
        }

        $schema = $this->types->find($collection);
        $locales = is_array($schema['locales'] ?? null) ? $schema['locales'] : [];
        $defaultLocale = (string) ($schema['default_locale'] ?? ($locales[0] ?? ''));

        if ($locale === $defaultLocale || !in_array($locale, $locales, true)) {
            return null; // cannot delete the default locale
        }

        $translations = is_array($entry['translations'] ?? null) ? $entry['translations'] : [];
        unset($translations[$locale]);
        $entry['translations'] = $translations;
        $entry['updated_at'] = Security::now();
        $entry['updated_by'] = $user['id'] ?? null;

        $this->store->write($entry, $collection, (string) $entry['id']);
        $this->cache->clear();
        $this->logger->info('content translation deleted', ['type' => $collection, 'id' => $id, 'locale' => $locale, 'user_id' => $user['id'] ?? null]);

        return $this->normalizeStoredEntry($entry);
    }

    public function replaceMediaFilename(string $oldFilename, string $newFilename): int
    {
        $oldFilename = basename($oldFilename);
        $newFilename = basename($newFilename);

        if ($oldFilename === '' || $newFilename === '' || $oldFilename === $newFilename) {
            return 0;
        }

        $updated = 0;

        foreach ($this->types->all() as $schema) {
            $collection = (string) ($schema['name'] ?? '');
            $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

            if ($collection === '' || $fields === []) {
                continue;
            }

            foreach ($this->all($collection, true) as $entry) {
                $changed = false;

                foreach ($fields as $name => $config) {
                    if (!is_array($config)) {
                        continue;
                    }

                    if (array_key_exists($name, $entry)) {
                        $value = $this->replaceMediaValue($entry[$name], $config, $oldFilename, $newFilename);
                        if ($value !== $entry[$name]) {
                            $entry[$name] = $value;
                            $changed = true;
                        }
                    }

                    if (is_array($entry['translations'] ?? null)) {
                        foreach ($entry['translations'] as $locale => $translation) {
                            if (!is_array($translation) || !array_key_exists($name, $translation)) {
                                continue;
                            }

                            $value = $this->replaceMediaValue($translation[$name], $config, $oldFilename, $newFilename);

                            if ($value !== $translation[$name]) {
                                $translation[$name] = $value;
                                $entry['translations'][$locale] = $translation;
                                $changed = true;
                            }
                        }
                    }
                }

                if ($changed) {
                    $id = (string) ($entry['id'] ?? '');

                    if ($id !== '') {
                        $this->store->write($entry, $collection, $id);
                        $updated++;
                    }
                }
            }
        }

        if ($updated > 0) {
            $this->cache->clear();
        }

        return $updated;
    }

    /**
     * Bulk-rename field keys across all stored entries.
     * Each migration item must contain 'from_key' and 'to_key'.
     * Data under the old key is copied to the new key only when the old key
     * has a value and the new key is not yet populated, so existing data is
     * never silently overwritten.
     *
     * @param array<int, array{from_key: string, to_key: string}> $migrations
     */
    public function migrateFields(string $collection, array $migrations): void
    {
        Security::assertSafeName($collection);

        $keyRenames = [];

        foreach ($migrations as $migration) {
            if (!is_array($migration)) {
                continue;
            }

            $fromKey = Security::slug((string) ($migration['from_key'] ?? ''));
            $toKey   = Security::slug((string) ($migration['to_key']   ?? ''));

            if ($fromKey !== '' && $toKey !== '' && $fromKey !== $toKey) {
                $keyRenames[$fromKey] = $toKey;
            }
        }

        if ($keyRenames === []) {
            return;
        }

        foreach ($this->store->all($collection) as $entry) {
            $changed = false;

            foreach ($keyRenames as $fromKey => $toKey) {
                if (array_key_exists($fromKey, $entry) && !array_key_exists($toKey, $entry)) {
                    $entry[$toKey] = $entry[$fromKey];
                    unset($entry[$fromKey]);
                    $changed = true;
                }

                if (is_array($entry['translations'] ?? null)) {
                    foreach ($entry['translations'] as $locale => $translation) {
                        if (!is_array($translation) || !array_key_exists($fromKey, $translation) || array_key_exists($toKey, $translation)) {
                            continue;
                        }

                        $translation[$toKey] = $translation[$fromKey];
                        unset($translation[$fromKey]);
                        $entry['translations'][$locale] = $translation;
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                $id = (string) ($entry['id'] ?? '');

                if ($id !== '') {
                    $this->store->write($entry, $collection, $id);
                }
            }
        }

        $this->cache->clear();
    }

    public function syncLocalization(string $collection, array $schema): void
    {
        Security::assertSafeName($collection);

        $locales = is_array($schema['locales'] ?? null) ? $schema['locales'] : [];
        $defaultLocale = (string) ($schema['default_locale'] ?? ($locales[0] ?? ''));

        if ($locales === [] || $defaultLocale === '') {
            return;
        }

        foreach ($this->store->all($collection) as $entry) {
            $translation = is_array($entry['translations'] ?? null) ? ($entry['translations'][$defaultLocale] ?? null) : null;

            if (!is_array($translation)) {
                continue;
            }

            $entry['translations'] = $this->syncUniversalTranslationFields(
                is_array($entry['translations'] ?? null) ? $entry['translations'] : [],
                is_array($schema['fields'] ?? null) ? $schema['fields'] : [],
                (string) ($schema['slug_field'] ?? 'slug'),
                $defaultLocale
            );
            $translation = $entry['translations'][$defaultLocale] ?? $translation;
            $updated = $this->applyTranslationToRoot($entry, $translation, $schema);

            if ($updated !== $entry) {
                $id = (string) ($entry['id'] ?? '');

                if ($id !== '') {
                    $this->store->write($updated, $collection, $id);
                }
            }
        }

        $this->cache->clear();
    }

    public function seedDefaultLocaleFromRoot(string $collection, array $schema): void
    {
        Security::assertSafeName($collection);

        $locales = is_array($schema['locales'] ?? null) ? $schema['locales'] : [];
        $defaultLocale = (string) ($schema['default_locale'] ?? ($locales[0] ?? ''));

        if ($locales === [] || $defaultLocale === '') {
            return;
        }

        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $slugField = (string) ($schema['slug_field'] ?? 'slug');

        foreach ($this->store->all($collection) as $entry) {
            $translations = is_array($entry['translations'] ?? null) ? $entry['translations'] : [];

            if (is_array($translations[$defaultLocale] ?? null)) {
                continue;
            }

            $translation = [];

            if (array_key_exists('title', $entry)) {
                $translation['title'] = $entry['title'];
            }

            foreach ($fields as $name => $config) {
                if (!is_array($config) || $name === $slugField || !array_key_exists($name, $entry)) {
                    continue;
                }

                $translation[$name] = $entry[$name];
            }

            if ($translation === []) {
                continue;
            }

            $translations[$defaultLocale] = $translation;
            $entry['translations'] = $translations;
            $id = (string) ($entry['id'] ?? '');

            if ($id !== '') {
                $this->store->write($entry, $collection, $id);
            }
        }

        $this->cache->clear();
    }

    public function isPubliclyVisible(array $entry): bool
    {
        if (!empty($entry['deleted_at'])) {
            return false;
        }

        if (($entry['status'] ?? 'draft') !== 'published') {
            return false;
        }

        $publishedAt = strtotime((string) ($entry['published_at'] ?? ''));

        return $publishedAt === false || $publishedAt <= time();
    }

    public function query(string $collection, array $params, bool $admin = false): array
    {
        $entries = $this->all($collection, $admin && (($params['trash'] ?? '') === '1'));

        if (!$admin) {
            $entries = array_values(array_filter($entries, fn(array $entry): bool => $this->isPubliclyVisible($entry)));
        }

        $locale = (string) ($params['locale'] ?? '');

        if ($locale !== '') {
            $schema = $this->types->find($collection);
            $locales = is_array($schema['locales'] ?? null) ? $schema['locales'] : [];

            if (in_array($locale, $locales, true)) {
                $entries = array_map(fn(array $entry): array => $this->resolveLocaleFields($entry, $locale), $entries);
            }
        }

        $entries = $this->applySearch($entries, (string) ($params['q'] ?? ''), $collection);
        $entries = $this->applyFilters($entries, $this->filtersFromParams($params, $collection));

        $total = count($entries);
        $sortParam = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($params['sort'] ?? '-created_at')) ?: '-created_at';
        $order = strtolower((string) ($params['order'] ?? '')) === 'asc' ? 'asc' : 'desc';
        $sort = ltrim($sortParam, '-');

        if (str_starts_with($sortParam, '-')) {
            $order = 'desc';
        } elseif (!array_key_exists('order', $params)) {
            $order = 'asc';
        }

        usort($entries, static function (array $a, array $b) use ($sort, $order): int {
            $result = self::compareSortValues(self::valueAt($a, $sort), self::valueAt($b, $sort));

            return $order === 'asc' ? $result : -$result;
        });

        $offset = max(0, (int) ($params['offset'] ?? 0));
        $limit = array_key_exists('limit', $params)
            ? max(1, (int) $params['limit'])
            : max(0, $total - $offset);

        return [
            'data' => array_slice($entries, $offset, $limit),
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'sort' => $sort,
                'order' => $order,
            ],
        ];
    }

    public function expandRelations(array $entry, string $collection, array $include, string $locale = ''): array
    {
        if ($include === []) {
            return $entry;
        }

        $schema = $this->types->find($collection);

        foreach ($include as $field) {
            $field = trim($field);
            $config = $schema['fields'][$field] ?? null;

            if (!is_array($config) || ($config['type'] ?? '') !== 'relation' || !array_key_exists($field, $entry)) {
                continue;
            }

            $target = (string) ($config['target'] ?? '');

            if ($target === '') {
                continue;
            }

            if ($config['multiple'] ?? false) {
                $entry[$field] = array_values(array_filter(array_map(fn(mixed $id): ?array => $this->resolveRelationLocale($target, $this->find($target, (string) $id), $locale), (array) $entry[$field])));
            } else {
                $entry[$field] = $entry[$field] ? $this->resolveRelationLocale($target, $this->find($target, (string) $entry[$field]), $locale) : null;
            }
        }

        return $entry;
    }

    public function normalizeStoredEntry(array $entry): array
    {
        $entry['status'] ??= 'draft';
        $entry['published_at'] ??= ($entry['status'] === 'published' ? ($entry['created_at'] ?? Security::now()) : null);
        $entry['created_at'] ??= Security::now();
        $entry['updated_at'] ??= $entry['created_at'];
        unset($entry['data'], $entry['body']);

        return $entry;
    }

    private function normalizeAndValidate(
        string $collection,
        array $payload,
        array $user,
        ?array $existing,
        bool $autoResolveSlugConflicts,
        ?array $validateOnlyFields = null,
    ): array {
        $schema = $this->types->find($collection);
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $now = Security::now();
        $isCreating = $existing === null;
        $payload = array_replace($existing ?? [], $payload);
        $partialFields = $validateOnlyFields !== null ? array_flip(array_map('strval', $validateOnlyFields)) : null;
        $status = (string) ($payload['status'] ?? 'draft');
        $publishedAt = $this->normalizeDateTime($payload['published_at'] ?? null);
        $errors = [];

        if (!in_array($status, ['draft', 'published', 'protected', 'archived'], true)) {
            $errors['status'] = 'Choose a valid status.';
        }

        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = $existing['published_at'] ?? $now;
        }

        // Locale handling
        $locales = is_array($schema['locales'] ?? null) ? $schema['locales'] : [];
        $isLocalized = $locales !== [];
        $locale = '';
        $defaultLocale = '';

        if ($isLocalized) {
            $defaultLocale = (string) ($schema['default_locale'] ?? ($locales[0] ?? ''));
            $locale = (string) ($payload['locale'] ?? $defaultLocale);

            if ($locale === '' || !in_array($locale, $locales, true)) {
                $errors['locale'] = 'Choose a valid locale (' . implode(', ', $locales) . ').';
            }
        }

        $slugField = (string) ($schema['slug_field'] ?? 'slug');

        if ($isLocalized) {
            $defaultSource = is_array($existing['translations'] ?? null) && is_array($existing['translations'][$defaultLocale] ?? null)
                ? $existing['translations'][$defaultLocale]
                : ($existing ?? []);

            foreach ($fields as $name => $config) {
                if (!is_array($config) || $name === $slugField || $this->isLocalizedField($config) || $locale === $defaultLocale) {
                    continue;
                }

                if (array_key_exists($name, $defaultSource)) {
                    $payload[$name] = $defaultSource[$name];
                } elseif (is_array($existing) && array_key_exists($name, $existing)) {
                    $payload[$name] = $existing[$name];
                }
            }
        }

        $slugSource = (string) ($schema['slug_source'] ?? 'title');
        $source = (string) ($payload[$slugSource] ?? $payload['title'] ?? '');
        $manualSlug = isset($payload[$slugField]) && trim((string) $payload[$slugField]) !== '';
        $slug = Security::slug($manualSlug ? (string) $payload[$slugField] : $source);

        if (!empty($schema['singleton'])) {
            $slug = $collection;
        }

        if ($slug === '') {
            $errors[$slugField] = 'Slug cannot be empty.';
        } else {
            $uniqueSlug = $this->uniqueSlug($collection, $slug, (string) ($existing['id'] ?? ''));

            if ($uniqueSlug !== $slug && empty($schema['singleton'])) {
                if ($manualSlug && !$autoResolveSlugConflicts) {
                    $errors[$slugField] = 'Slug must be unique.';
                } else {
                    $slug = $uniqueSlug;
                }
            }
        }

        $existingUid = trim((string) ($existing['uid'] ?? ''));
        $uid = $existingUid !== '' ? $existingUid : Security::opaqueId();

        $entry = [
            'id' => $slug,
            'uid' => $uid,
            'collection' => $collection,
            'status' => $status,
            'published_at' => $publishedAt,
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
            'author_id' => $payload['author_id'] ?? $existing['author_id'] ?? $user['id'] ?? null,
            'updated_by' => $user['id'] ?? null,
        ];

        foreach ($fields as $name => $config) {
            if (!is_array($config)) {
                continue;
            }

            if ($partialFields !== null && !array_key_exists($name, $partialFields)) {
                $entry[$name] = $existing[$name] ?? null;
                continue;
            }

            $typeName = (string) ($config['type'] ?? 'text');
            $type = $this->fields->get($typeName);
            $raw = $name === $slugField ? $slug : $this->fieldPayloadValue($name, $payload, $config, $typeName, $isCreating);
            $result = $type->validate($raw, $config, ['collection' => $collection, 'entry' => $entry]);

            if (($result['valid'] ?? false) === false) {
                $errors[$name] = (string) ($result['message'] ?? 'Invalid value.');
                continue;
            }

            $entry[$name] = $name === $slugField ? $slug : $type->normalize($raw, $config, ['collection' => $collection, 'entry' => $entry]);
        }

        $entry['title'] = (string) ($entry['title'] ?? $payload['title'] ?? '');
        $entry['slug'] = $slug;

        if ($entry['title'] === '') {
            $errors['title'] = 'Title is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // For localized content types: store translatable content in translations sub-object
        if ($isLocalized && $locale !== '') {
            // Collect translatable fields (all schema fields except the slug field) + title
            $translationContent = ['title' => $entry['title']];

            foreach ($fields as $name => $config) {
                if (is_array($config) && $name !== $slugField && array_key_exists($name, $entry)) {
                    $translationContent[$name] = $entry[$name];
                }
            }

            // Preserve existing translations for other locales
            $existingTranslations = is_array($existing['translations'] ?? null) ? $existing['translations'] : [];
            $existingTranslations[$locale] = $translationContent;
            $existingTranslations = $this->syncUniversalTranslationFields($existingTranslations, $fields, $slugField, $defaultLocale);

            $entry['translations'] = $existingTranslations;

            $defaultTranslation = $existingTranslations[$defaultLocale] ?? null;

            if (is_array($defaultTranslation)) {
                $entry = $this->applyTranslationToRoot($entry, $defaultTranslation, $schema);
            } elseif ($locale !== $defaultLocale && $existing !== null) {
                $entry = $this->applyTranslationToRoot($entry, $existing, $schema);
            }
        } elseif ($existing !== null && is_array($existing['translations'] ?? null)) {
            $entry['translations'] = $existing['translations'];
        }

        if (!empty($existing['deleted_at'])) {
            $entry['deleted_at'] = $existing['deleted_at'];
            $entry['deleted_by'] = $existing['deleted_by'] ?? null;
        }

        return $entry;
    }

    private function syncUniversalTranslationFields(array $translations, array $fields, string $slugField, string $defaultLocale): array
    {
        if ($defaultLocale === '' || !is_array($translations[$defaultLocale] ?? null)) {
            return $translations;
        }

        $defaultTranslation = $translations[$defaultLocale];
        $universalValues = [];

        foreach ($fields as $name => $config) {
            if (!is_string($name) || !is_array($config) || $name === $slugField || $this->isLocalizedField($config) || !array_key_exists($name, $defaultTranslation)) {
                continue;
            }

            $universalValues[$name] = $defaultTranslation[$name];
        }

        if ($universalValues === []) {
            return $translations;
        }

        foreach ($translations as $locale => $translation) {
            if (!is_array($translation)) {
                continue;
            }

            foreach ($universalValues as $name => $value) {
                $translation[$name] = $value;
            }

            $translations[$locale] = $translation;
        }

        return $translations;
    }

    private function isLocalizedField(array $config): bool
    {
        return ($config['localized'] ?? true) !== false;
    }

    private function containsLocalizedBulkField(array $data, array $schema): bool
    {
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $slugField = (string) ($schema['slug_field'] ?? 'slug');

        foreach (array_keys($data) as $name) {
            if ($name === 'title') {
                return true;
            }

            $config = $fields[$name] ?? null;
            if (is_array($config) && $name !== $slugField && $this->isLocalizedField($config)) {
                return true;
            }
        }

        return false;
    }

    private function bulkUpdateLocales(array $entry, array $schema): array
    {
        $locales = is_array($schema['locales'] ?? null) ? $schema['locales'] : [];
        $defaultLocale = (string) ($schema['default_locale'] ?? ($locales[0] ?? ''));
        $translations = is_array($entry['translations'] ?? null) ? $entry['translations'] : [];
        $existingLocales = array_values(array_filter(
            $locales,
            static fn(string $locale): bool => is_array($translations[$locale] ?? null)
        ));

        if ($existingLocales === []) {
            return $defaultLocale !== '' ? [$defaultLocale] : [];
        }

        if ($defaultLocale !== '' && in_array($defaultLocale, $existingLocales, true)) {
            return [$defaultLocale, ...array_values(array_filter($existingLocales, static fn(string $locale): bool => $locale !== $defaultLocale))];
        }

        return $existingLocales;
    }

    private function isSingleton(string $collection): bool
    {
        $schema = $this->types->find($collection);

        return !empty($schema['singleton']);
    }

    private function singletonEntry(string $collection, bool $includeDeleted = false): ?array
    {
        foreach ($this->all($collection, $includeDeleted) as $entry) {
            return $entry;
        }

        return null;
    }

    private function fieldPayloadValue(string $name, array $payload, array $config, string $type, bool $isCreating): mixed
    {
        if (array_key_exists($name, $payload)) {
            return $payload[$name];
        }

        if ($isCreating && $this->supportsConfiguredDefault($type) && array_key_exists('default', $config)) {
            return $config['default'];
        }

        return null;
    }

    private function supportsConfiguredDefault(string $type): bool
    {
        return in_array($type, [
            'text',
            'textarea',
            'markdown',
            'number',
            'range',
            'boolean',
            'select',
            'date',
            'datetime',
            'json',
            'color',
        ], true);
    }

    private function normalizeRevision(array $revision): array
    {
        $entry = is_array($revision['entry'] ?? null) ? $this->normalizeStoredEntry($revision['entry']) : [];

        return [
            'id' => (string) ($revision['id'] ?? ''),
            'collection' => (string) ($revision['collection'] ?? ''),
            'entry_id' => (string) ($revision['entry_id'] ?? ''),
            'created_at' => (string) ($revision['created_at'] ?? ''),
            'created_by' => $revision['created_by'] ?? null,
            'event' => (string) ($revision['event'] ?? 'content.updated'),
            'title' => (string) ($entry['title'] ?? $entry['id'] ?? ''),
            'slug' => (string) ($entry['slug'] ?? $entry['id'] ?? ''),
            'status' => (string) ($entry['status'] ?? 'draft'),
            'updated_at' => (string) ($entry['updated_at'] ?? ''),
            'entry' => $entry,
        ];
    }

    private function writeRevision(string $collection, string $id, array $entry, string $event, array $user): void
    {
        $revisionId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $revision = [
            'id' => $revisionId,
            'collection' => $collection,
            'entry_id' => $id,
            'created_at' => Security::now(),
            'created_by' => $user['id'] ?? null,
            'event' => $event,
            'entry' => $this->normalizeStoredEntry($entry),
        ];

        $this->revisions->write($revision, $collection, $id, $revisionId);
        $this->pruneRevisions($collection, $id);
    }

    private function pruneRevisions(string $collection, string $id): void
    {
        $maxRevisions = (int) comet_config('content.max_revisions', 50);

        if ($maxRevisions < 0) {
            return;
        }

        $revisions = $this->revisions->all($collection, $id);

        foreach (array_slice($revisions, $maxRevisions) as $revision) {
            $revisionId = (string) ($revision['id'] ?? '');

            if ($revisionId !== '') {
                $this->revisions->delete($collection, $id, $revisionId);
            }
        }
    }

    private function moveRevisionHistory(string $collection, string $fromId, string $toId): void
    {
        if ($fromId === $toId) {
            return;
        }

        foreach ($this->revisions->all($collection, $fromId) as $revision) {
            $revisionId = (string) ($revision['id'] ?? '');

            if ($revisionId === '') {
                continue;
            }

            $revision['entry_id'] = $toId;
            $this->revisions->write($revision, $collection, $toId, $revisionId);
            $this->revisions->delete($collection, $fromId, $revisionId);
        }
    }

    private function replaceMediaValue(mixed $value, array $config, string $oldFilename, string $newFilename): mixed
    {
        if (($config['type'] ?? '') === 'media') {
            $values = is_array($value) ? $value : [];

            return array_map(
                static fn(mixed $file): mixed => basename((string) $file) === $oldFilename ? $newFilename : $file,
                $values
            );
        }

        if (($config['type'] ?? '') !== 'repeater' || !is_array($value)) {
            return $value;
        }

        $subfields = [];

        foreach (is_array($config['subfields'] ?? null) ? $config['subfields'] : [] as $subfield) {
            if (!is_array($subfield)) {
                continue;
            }

            $key = (string) ($subfield['key'] ?? '');

            if ($key !== '') {
                $subfields[$key] = $subfield;
            }
        }

        return array_map(function (mixed $row) use ($subfields, $oldFilename, $newFilename): mixed {
            if (!is_array($row)) {
                return $row;
            }

            foreach ($subfields as $key => $subfield) {
                if (array_key_exists($key, $row)) {
                    $row[$key] = $this->replaceMediaValue($row[$key], $subfield, $oldFilename, $newFilename);
                }
            }

            return $row;
        }, $value);
    }

    private function uniqueSlug(string $collection, string $slug, string $ignoreId = ''): string
    {
        $base = $slug;
        $candidate = $slug;
        $count = 2;

        while (($existing = $this->find($collection, $candidate, true)) !== null && (string) ($existing['id'] ?? '') !== $ignoreId) {
            $candidate = $base . '-' . $count++;
        }

        return $candidate;
    }

    private function prefixCopyTitle(array &$entry): void
    {
        foreach (['title', 'name', 'heading', 'label'] as $titleField) {
            if (isset($entry[$titleField]) && is_string($entry[$titleField]) && $entry[$titleField] !== '') {
                $entry[$titleField] = 'Copy of ' . $entry[$titleField];
                return;
            }
        }
    }

    public function resolveLocaleFields(array $entry, string $locale): array
    {
        $translation = is_array($entry['translations'] ?? null) ? ($entry['translations'][$locale] ?? null) : null;

        if ($translation !== null) {
            $collection = (string) ($entry['collection'] ?? '');
            $schema = $collection !== '' ? $this->types->find($collection) : [];
            $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

            foreach ($translation as $key => $value) {
                $config = $fields[$key] ?? null;
                if (is_array($config) && !$this->isLocalizedField($config)) {
                    continue;
                }

                $entry[$key] = $value;
            }
        }

        return $entry;
    }

    private function applyTranslationToRoot(array $entry, array $translation, array $schema): array
    {
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $slugField = (string) ($schema['slug_field'] ?? 'slug');

        if (array_key_exists('title', $translation)) {
            $entry['title'] = $translation['title'];
        }

        foreach ($fields as $name => $config) {
            if (!is_array($config) || $name === $slugField || !array_key_exists($name, $translation)) {
                continue;
            }

            $entry[$name] = $translation[$name];
        }

        return $entry;
    }

    private function resolveRelationLocale(string $collection, ?array $entry, string $locale): ?array
    {
        if ($entry === null || $locale === '') {
            return $entry;
        }

        $schema = $this->types->find($collection);
        $locales = is_array($schema['locales'] ?? null) ? $schema['locales'] : [];

        return in_array($locale, $locales, true) ? $this->resolveLocaleFields($entry, $locale) : $entry;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = strtotime((string) $value);

        return $time === false ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    private function afterWrite(string $event, string $collection, string $id, array $user): void
    {
        $entry = $this->find($collection, $id, true);
        $this->cache->clear();
        $this->logger->info($event, ['type' => $collection, 'slug' => $id, 'user_id' => $user['id'] ?? null]);
        $this->webhooks->dispatch($event, [
            'type' => $collection,
            'id' => $entry !== null ? $this->stableId($entry) : $id,
            'slug' => (string) ($entry['slug'] ?? $id),
        ]);
    }

    private function applySearch(array $entries, string $query, string $collection): array
    {
        $query = trim(strtolower($query));

        if ($query === '') {
            return $entries;
        }

        $schema = $this->types->find($collection);
        $searchFields = ['title', 'slug', 'summary'];

        foreach ($schema['fields'] ?? [] as $name => $config) {
            if (in_array($config['type'] ?? '', ['text', 'textarea', 'markdown'], true)) {
                $searchFields[] = $name;
            }
        }

        return array_values(array_filter($entries, static function (array $entry) use ($query, $searchFields): bool {
            foreach (array_unique($searchFields) as $field) {
                if (str_contains(strtolower((string) self::valueAt($entry, $field)), $query)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function applyFilters(array $entries, array $filters): array
    {
        foreach ($filters as $field => $condition) {
            $entries = array_values(array_filter($entries, static fn(array $entry): bool => self::matchesFilter($entry, (string) $field, $condition)));
        }

        return $entries;
    }

    private function filtersFromParams(array $params, string $collection): array
    {
        $filters = [];
        $filterableFields = $this->filterableFields($collection);

        foreach (is_array($params['filter'] ?? null) ? $params['filter'] : [] as $field => $condition) {
            if (!is_string($field) || !in_array($field, $filterableFields, true)) {
                continue;
            }

            $filters[$field] = $condition;
        }

        return $filters;
    }

    private function filterableFields(string $collection): array
    {
        $schema = $this->types->find($collection);
        $fields = array_keys(is_array($schema['fields'] ?? null) ? $schema['fields'] : []);

        return array_values(array_unique(array_merge([
            'id',
            'uid',
            'collection',
            'status',
            'published_at',
            'created_at',
            'updated_at',
            'author_id',
            'updated_by',
            'title',
            'slug',
        ], $fields)));
    }

    private static function matchesFilter(array $entry, string $field, mixed $condition): bool
    {
        $value = self::valueAt($entry, $field);

        if (!is_array($condition)) {
            return self::compare($value, 'eq', $condition);
        }

        foreach ($condition as $operator => $expected) {
            if (!self::compare($value, (string) $operator, $expected)) {
                return false;
            }
        }

        return true;
    }

    private static function compare(mixed $value, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'ne' => !self::equalsFilterValue($value, $expected),
            'gt' => self::compareOrdered($value, $expected) > 0,
            'gte' => self::compareOrdered($value, $expected) >= 0,
            'lt' => self::compareOrdered($value, $expected) < 0,
            'lte' => self::compareOrdered($value, $expected) <= 0,
            'contains' => self::containsFilterValue($value, $expected),
            'in' => self::matchesAnyFilterValue($value, self::expectedValues($expected)),
            default => self::equalsFilterValue($value, $expected),
        };
    }

    private static function equalsFilterValue(mixed $value, mixed $expected): bool
    {
        if (is_array($value)) {
            return self::matchesAnyFilterValue($value, [$expected]);
        }

        if (is_bool($value) || is_bool($expected) || is_int($value) || is_int($expected)) {
            $valueBool = self::booleanValue($value);
            $expectedBool = self::booleanValue($expected);

            if ($valueBool !== null || $expectedBool !== null) {
                return $valueBool !== null && $expectedBool !== null && $valueBool === $expectedBool;
            }
        }

        if (is_numeric($value) && is_numeric($expected)) {
            return (float) $value === (float) $expected;
        }

        if ($value === null) {
            return $expected === null || $expected === '';
        }

        return (string) $value === (string) $expected;
    }

    private static function matchesAnyFilterValue(mixed $value, array $expectedValues): bool
    {
        $values = is_array($value) ? array_values($value) : [$value];

        foreach ($values as $item) {
            foreach ($expectedValues as $expected) {
                if (self::equalsFilterValue($item, $expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function containsFilterValue(mixed $value, mixed $expected): bool
    {
        $needle = strtolower(self::filterValueToString($expected));
        $values = is_array($value) ? array_values($value) : [$value];

        foreach ($values as $item) {
            if (str_contains(strtolower(self::filterValueToString($item)), $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function compareOrdered(mixed $value, mixed $expected): int
    {
        if (is_numeric($value) && is_numeric($expected)) {
            return (float) $value <=> (float) $expected;
        }

        return self::filterValueToString($value) <=> self::filterValueToString($expected);
    }

    private static function compareSortValues(mixed $a, mixed $b): int
    {
        if ($a === $b) {
            return 0;
        }

        if ($a === null || $a === '') {
            return -1;
        }

        if ($b === null || $b === '') {
            return 1;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }

        $aTime = self::timestampValue($a);
        $bTime = self::timestampValue($b);

        if ($aTime !== null && $bTime !== null) {
            return $aTime <=> $bTime;
        }

        return strcasecmp(self::filterValueToString($a), self::filterValueToString($b));
    }

    private static function timestampValue(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    private static function expectedValues(mixed $expected): array
    {
        if (is_array($expected)) {
            return array_values($expected);
        }

        return array_map('trim', explode(',', (string) $expected));
    }

    private static function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                0 => false,
                1 => true,
                default => null,
            };
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '0', 'false', 'no', 'off' => false,
                '1', 'true', 'yes', 'on' => true,
                default => null,
            };
        }

        return null;
    }

    private static function filterValueToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return implode(',', array_map(static fn(mixed $item): string => self::filterValueToString($item), $value));
        }

        return (string) $value;
    }

    private static function valueAt(array $entry, string $field): mixed
    {
        if ($field === 'id') {
            $uid = trim((string) ($entry['uid'] ?? ''));

            if ($uid !== '') {
                return $uid;
            }

            return substr(hash('sha256', implode('|', [
                (string) ($entry['collection'] ?? ''),
                (string) ($entry['created_at'] ?? ''),
                (string) ($entry['id'] ?? ''),
            ])), 0, 12);
        }

        return $entry[$field] ?? null;
    }
}
