<?php

declare(strict_types=1);

namespace CometCMS\Content;

use CometCMS\Cache\ApiCache;
use CometCMS\Core\Security;
use CometCMS\Core\ConflictException;
use CometCMS\Localization\LocalizedValue;
use CometCMS\Localization\LanguageSettings;
use CometCMS\Core\ValidationException;
use CometCMS\Fields\FieldRegistry;
use CometCMS\Logging\Logger;
use CometCMS\Site\LayoutSchema;
use CometCMS\Storage\JsonStore;
use CometCMS\Trash\TrashStore;
use CometCMS\Webhooks\WebhookDispatcher;
use CometCMS\Workspaces\WorkspaceContext;

final class ContentRepository
{
    private JsonStore $store;
    private JsonStore $revisions;
    private WorkspaceContext $workspace;
    private array $collectionsCache = [];
    private array $identifiersCache = [];
    private int $readGeneration = -1;

    private function invalidateReads(): void
    {
        $this->collectionsCache = $this->identifiersCache = [];
        $this->readGeneration = JsonStore::generation();
    }

    /** Stored values stay unresolved until the caller chooses its locale. */
    private function storedEntries(string $collection): array
    {
        Security::assertSafeName($collection);
        if ($this->types->isDisabled($collection)) return [];
        if ($this->readGeneration !== JsonStore::generation()) $this->invalidateReads();
        return $this->collectionsCache[$collection] ??= $this->store->allUnsorted($collection);
    }

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

    public static function make(?WorkspaceContext $workspace = null, bool $includeDisabled = false): self
    {
        $workspace ??= WorkspaceContext::active();

        return new self(new ContentTypeRepository($workspace, $includeDisabled), FieldRegistry::builtins(), new Logger(), ApiCache::fromConfig($workspace), new WebhookDispatcher(), $workspace);
    }

    public function collections(): array
    {
        return array_values(array_filter($this->store->directories(), fn(string $name): bool => !$this->types->isDisabled($name)));
    }

    public function all(string $collection, bool $includeDeleted = false): array
    {
        Security::assertSafeName($collection);
        $entries = $this->storedEntries($collection);
        JsonStore::sortByModified($entries);
        $entries = array_map(fn(array $entry): array => $this->normalizeStoredEntry($entry), $entries);

        if (!$includeDeleted) {
            $entries = array_values(array_filter($entries, static fn(array $entry): bool => empty($entry['deleted_at'])));
        }

        return $entries;
    }

    public function find(string $collection, string $id, bool $includeDeleted = false): ?array
    {
        Security::assertSafeName($collection);
        Security::assertSafeName($id);
        if ($this->types->isDisabled($collection)) return null;
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

    public function findByIdentifier(string $collection, string $identifier, bool $includeDeleted = false, string $locale = ''): ?array
    {
        $locale = $this->locale($locale);
        $direct = $this->find($collection, $identifier, $includeDeleted);
        if ($direct !== null && ($this->stableId($direct) === $identifier || $this->resolveLocaleFields($direct, $locale)['slug'] === $identifier)) return $direct;
        $entries = $this->storedEntries($collection);
        $key = $collection . '|' . $locale . '|' . ($includeDeleted ? '1' : '0');
        if (!isset($this->identifiersCache[$key])) {
            JsonStore::sortByModified($entries);
            $lookup = [];
            foreach ($entries as $candidate) {
                if (!$includeDeleted && !empty($candidate['deleted_at'])) continue;
                $lookup[$this->stableId($candidate)] ??= $candidate['id'];
                $slug = $this->resolveLocaleFields($candidate, $locale)['slug'] ?? '';
                if ($slug !== '') $lookup[$slug] ??= $candidate['id'];
            }
            $this->identifiersCache[$key] = $lookup;
        }
        $id = $this->identifiersCache[$key][$identifier] ?? null;
        return $id !== null ? $this->find($collection, $id, $includeDeleted) : null;
    }

    public function locale(string $locale = ''): string
    {
        return (new LanguageSettings($this->workspace))->requireLocale($locale);
    }

    public function stableId(array $entry): string
    {
        return trim((string) ($entry['uid'] ?? ''));
    }

    /** Normalize an editor draft without writing entries, revisions or events. */
    public function preview(string $collection, array $payload, array $user, ?array $existing): array
    {
        Security::assertSafeName($collection);
        $entry = $this->normalizeAndValidate($collection, $payload, $user, $existing, true, array_keys($payload));
        if ($existing !== null) {
            // A preview does not change the saved modification date or editor.
            $entry['updated_at'] = $existing['updated_at'];
            $entry['updated_by'] = $existing['updated_by'] ?? null;
        }
        return $entry;
    }

    public function save(
        string $collection,
        array $payload,
        array $user,
        ?string $existingId = null,
        bool $autoResolveSlugConflicts = true,
        ?array $validateOnlyFields = null,
    ): array {
        return (new JsonStore($this->workspace->root()))->synchronized(fn(): array => $this->saveUnlocked($collection, $payload, $user, $existingId, $autoResolveSlugConflicts, $validateOnlyFields), 'routing');
    }

    private function saveUnlocked(string $collection, array $payload, array $user, ?string $existingId, bool $autoResolveSlugConflicts, ?array $validateOnlyFields): array
    {
        // A writer may have changed storage while we waited for the routing lock.
        $this->invalidateReads();
        Security::assertSafeName($collection);
        $existing = $existingId !== null ? $this->find($collection, $existingId, true) : null;
        if (isset($payload['revision']) && $existing !== null && $payload['revision'] !== ($existing['revision'] ?? '')) throw new ConflictException();
        $entry = $this->normalizeAndValidate($collection, $payload, $user, $existing, $autoResolveSlugConflicts, $validateOnlyFields);
        $site = new \CometCMS\Site\SiteRepository($this->workspace);
        $site->validateEntryUrls($site->settings(), $entry);
        $locale = $this->locale((string) ($payload['locale'] ?? ''));
        $oldStatus = $existing['publication'][$locale]['status'] ?? null;

        if ($existing !== null) {
            $this->writeRevision($collection, (string) $entry['id'], $existing, 'content.updated', $user);
        }

        $this->store->write($this->stored($entry), $collection, (string) $entry['id']);

        $event = $existing === null ? 'content.created' : 'content.updated';
        $this->afterWrite($event, $collection, (string) $entry['id'], $user);

        if (($entry['publication'][$locale]['status'] ?? '') === 'published' && $oldStatus !== 'published') {
            $this->afterWrite('content.published', $collection, (string) $entry['id'], $user);
        }

        if ($oldStatus === 'published' && ($entry['publication'][$locale]['status'] ?? '') !== 'published') {
            $this->afterWrite('content.unpublished', $collection, (string) $entry['id'], $user);
        }

        return $this->resolveLocaleFields($entry, $this->locale((string) ($payload['locale'] ?? '')));
    }

    /**
     * Save an entry's page content from the page builder, or clear it with
     * null so the entry shows its layout's default content again. Page
     * content is shared by all languages, so this works from any of them.
     */
    public function savePage(string $collection, string $id, string $field, ?array $nodes, array $user, ?string $revision = null): array
    {
        return (new JsonStore($this->workspace->root()))->synchronized(function () use ($collection, $id, $field, $nodes, $user, $revision): array {
            $existing = $this->find($collection, $id);
            if ($existing === null) throw new \RuntimeException('Entry not found.');
            if ($revision !== null && $revision !== ($existing['revision'] ?? '')) throw new ConflictException();
            if (($this->types->find($collection)['fields'][$field]['type'] ?? '') !== 'page') throw new ValidationException([$field => 'Choose a page content field.']);
            $entry = $this->stored($existing);
            $entry['values'][$field] = $this->fields->get('page')->normalize($nodes, []);
            $entry['revision'] = bin2hex(random_bytes(12));
            $entry['updated_at'] = Security::now();
            $entry['updated_by'] = $user['id'] ?? null;
            $this->writeRevision($collection, $id, $existing, 'content.updated', $user);
            $this->store->write($entry, $collection, $id);
            $this->afterWrite('content.updated', $collection, $id, $user);

            return $this->normalizeStoredEntry($entry);
        }, 'routing');
    }

    /**
     * Rewrite the page content of every entry of $collection's page fields
     * with $change (stored value => new stored value), including entries in
     * the trash and their revisions, without new revisions of their own.
     */
    public function rewritePages(string $collection, callable $change): int
    {
        $fields = array_keys(array_filter($this->types->find($collection)['fields'], static fn(mixed $config): bool => ($config['type'] ?? '') === 'page'));
        if ($fields === []) return 0;
        $rewrite = function (array $entry) use ($fields, $change): ?array {
            $changed = false;
            foreach ($fields as $field) {
                $value = $entry['values'][$field] ?? null;
                if (!LayoutSchema::hasPageContent($value)) continue;
                $next = $change($value);
                if ($next !== $value) {
                    $entry['values'][$field] = $next;
                    $changed = true;
                }
            }
            return $changed ? $entry : null;
        };
        $updated = 0;
        foreach ($this->all($collection, true) as $entry) {
            $next = $rewrite($this->stored($entry));
            if ($next !== null) {
                $this->store->write($next, $collection, (string) $entry['id']);
                $updated++;
            }
            foreach ($this->revisions->all($collection, (string) $entry['id']) as $revision) {
                $next = is_array($revision['entry'] ?? null) && array_key_exists('values', $revision['entry']) ? $rewrite($revision['entry']) : null;
                if ($next !== null) {
                    $revision['entry'] = $next;
                    $this->revisions->write($revision, $collection, (string) $entry['id'], (string) $revision['id']);
                }
            }
        }
        if ($updated) $this->cache->clear();
        return $updated;
    }

    public function bulkUpdateFields(string $collection, array $existing, array $data, array $user): array
    {
        return $this->save($collection, $data, $user, $existing['id'], true, array_keys($data));
    }

    public function isHierarchical(string $collection): bool
    {
        return !empty($this->types->find($collection)['hierarchical']);
    }

    /** @return list<array> the direct children of $parentUid ('' for top-level pages), in their saved order */
    public function children(string $collection, string $parentUid): array
    {
        $children = array_values(array_filter($this->all($collection), static fn(array $entry): bool => PageTree::parent($entry) === $parentUid));
        usort($children, static fn(array $a, array $b): int => [(int) ($a['position'] ?? 0), (string) $a['created_at']] <=> [(int) ($b['position'] ?? 0), (string) $b['created_at']]);
        return $children;
    }

    /**
     * Place an entry of a hierarchical type under $parent ('' for top level)
     * at $index among its new siblings, renumbering the siblings in between.
     */
    public function move(string $collection, string $id, string $parent, int $index, array $user): array
    {
        return (new JsonStore($this->workspace->root()))->synchronized(function () use ($collection, $id, $parent, $index, $user): array {
            if (!$this->isHierarchical($collection)) throw new ValidationException(['parent' => 'This content type has no page hierarchy.']);
            $existing = $this->find($collection, $id);
            if ($existing === null) throw new \RuntimeException('Entry not found.');
            $siblings = array_values(array_filter($this->children($collection, $parent), static fn(array $entry): bool => $entry['id'] !== $existing['id']));
            $index = max(0, min($index, count($siblings)));
            // Save in a language the entry has, so moving never adds a translation.
            $locale = isset($existing['publication'][$this->locale()]) ? $this->locale() : (string) (array_key_first($existing['publication']) ?? $this->locale());
            $saved = $this->saveUnlocked($collection, ['parent' => $parent, 'position' => $index, 'locale' => $locale], $user, (string) $existing['id'], true, ['parent', 'position']);
            array_splice($siblings, $index, 0, [null]);
            foreach ($siblings as $position => $sibling) {
                if ($sibling === null || (int) ($sibling['position'] ?? 0) === $position) continue;
                // Reordering is not a content change: no revision, date or webhook.
                $sibling['position'] = $position;
                $this->store->write($this->stored($sibling), $collection, (string) $sibling['id']);
            }
            $this->cache->clear();
            return $saved;
        }, 'routing');
    }

    /** Entry ids ordered so that children come before their parents, e.g. for deleting a whole branch. */
    public function deepestFirst(string $collection, array $ids): array
    {
        if (!$this->isHierarchical($collection)) return $ids;
        $byUid = PageTree::byUid($this->all($collection));
        $depth = [];
        foreach ($byUid as $entry) $depth[(string) $entry['id']] = count(PageTree::ancestors($byUid, $entry));
        usort($ids, static fn(string $a, string $b): int => ($depth[$b] ?? 0) <=> ($depth[$a] ?? 0));
        return $ids;
    }

    private function nextPosition(string $collection, string $parentUid): int
    {
        $children = $this->children($collection, $parentUid);
        return $children === [] ? 0 : max(array_map(static fn(array $entry): int => (int) ($entry['position'] ?? 0), $children)) + 1;
    }

    /** The parent's uid, or '' with an error in $errors when it cannot hold $entry. */
    private function validParent(string $collection, mixed $value, array $entry, array &$errors): string
    {
        $identifier = is_string($value) ? trim($value) : '';
        if ($identifier === '') return '';
        $byUid = PageTree::byUid($this->all($collection));
        $parent = $byUid[$identifier] ?? (preg_match('/^[A-Za-z0-9_-]+$/', $identifier) ? $this->findByIdentifier($collection, $identifier) : null);
        if ($parent === null) {
            $errors['parent'] = 'Choose an existing entry of this type.';
            return PageTree::parent($entry);
        }
        $uid = (string) $parent['uid'];
        $ancestors = PageTree::ancestors($byUid, $parent);
        if ($uid === $entry['uid'] || in_array($entry['uid'], $ancestors, true)) {
            $errors['parent'] = 'A page cannot be placed below itself.';
        } elseif (count($ancestors) + 2 + PageTree::height($byUid, (string) $entry['uid']) > PageTree::MAX_DEPTH) {
            $errors['parent'] = 'Pages can be nested at most ' . PageTree::MAX_DEPTH . ' levels deep.';
        }
        return isset($errors['parent']) ? PageTree::parent($entry) : $uid;
    }

    public function duplicate(string $collection, string $id, array $user): ?array
    {
        $existing = $this->find($collection, $id, true);
        if ($existing === null) return null;
        $entry = $this->stored($existing);
        $entry['id'] = Security::opaqueId();
        $entry['uid'] = Security::opaqueId();
        $entry['revision'] = bin2hex(random_bytes(12));
        $entry['created_at'] = $entry['updated_at'] = Security::now();
        $entry['author_id'] = $entry['updated_by'] = $user['id'] ?? null;
        unset($entry['deleted_at'], $entry['deleted_by']);
        $siblingsOf = $this->isHierarchical($collection) ? PageTree::parent($entry) : null;
        if ($siblingsOf !== null) $entry['position'] = $this->nextPosition($collection, $siblingsOf);
        foreach ($entry['publication'] as $locale => &$publication) {
            $publication = ['status' => 'draft', 'published_at' => null];
            $title = LocalizedValue::resolve($entry['values']['title'], $locale, $locale);
            $entry['values']['title'] = LocalizedValue::set($entry['values']['title'], $locale, 'Copy of ' . $title, $this->locale());
            $slug = (string) LocalizedValue::resolve($entry['values']['slug'], $locale, $locale);
            $entry['values']['slug'] = LocalizedValue::set($entry['values']['slug'], $locale, $this->uniqueSlug($collection, $slug, '', $locale, $siblingsOf), $this->locale());
        }
        unset($publication);
        $this->store->write($this->stored($entry), $collection, $entry['id']);
        $this->afterWrite('content.created', $collection, $entry['id'], $user);
        return $this->normalizeStoredEntry($entry);
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

    public function restoreRevision(string $collection, string $id, string $revisionId, array $user, ?callable $authorize = null): ?array
    {
        return (new JsonStore($this->workspace->root()))->synchronized(
            fn(): ?array => $this->restoreRevisionUnlocked($collection, $id, $revisionId, $user, $authorize),
            'routing',
        );
    }

    private function restoreRevisionUnlocked(string $collection, string $id, string $revisionId, array $user, ?callable $authorize): ?array
    {
        $this->invalidateReads();
        Security::assertSafeName($collection);
        Security::assertSafeName($id);
        Security::assertSafeName($revisionId);

        $revision = $this->revisions->read($collection, $id, $revisionId);

        if ($revision === null || !is_array($revision['entry'] ?? null)) {
            return null;
        }

        $current = $this->find($collection, $id);
        if ($current === null) return null;
        // History restores content, never ownership or creation metadata.
        $historical = $this->normalizeStoredEntry($revision['entry']);
        $entry = $this->normalizeStoredEntry(array_replace($this->stored($current), array_intersect_key($historical, array_flip(['values', 'publication']))));
        $entry['collection'] = $collection;
        $entry['id'] = $id;
        // Preserve the current entry's immutable uid
        $entry['uid'] = $current['uid'] ?? $entry['uid'] ?? '';
        if ($entry['uid'] === '') {
            throw new \RuntimeException('Cannot restore an entry without an immutable uid.');
        }
        $entry['updated_at'] = Security::now();
        $entry['updated_by'] = $user['id'] ?? null;
        unset($entry['deleted_at'], $entry['deleted_by']);

        $entry['revision'] = bin2hex(random_bytes(12));

        if ($authorize !== null) $authorize($current, $entry, $this->revisionChanges($current, $entry));
        $site = new \CometCMS\Site\SiteRepository($this->workspace);
        $site->validateEntryUrls($site->settings(), $entry);

        $this->writeRevision($collection, $id, $current, 'content.revision_restore', $user);

        $this->store->write($this->stored($entry), $collection, (string) $entry['id']);

        $this->afterWrite('content.updated', $collection, (string) $entry['id'], $user);

        return $this->normalizeStoredEntry($entry);
    }

    /** Raw override changes matter even when their resolved values agree. */
    private function revisionChanges(array $current, array $restored): array
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($current['values']), array_keys($restored['values']))) as $field) {
            $before = $current['values'][$field] ?? null;
            $after = $restored['values'][$field] ?? null;
            if ($before === $after && array_key_exists($field, $current['values']) === array_key_exists($field, $restored['values'])) continue;
            if ($before === null && $after === null) {
                $changes[''][] = $field;
                continue;
            }
            if ((LocalizedValue::is($before) || $before === null) && (LocalizedValue::is($after) || $after === null)) {
                $before ??= ['values' => []];
                $after ??= ['values' => []];
                foreach (array_unique(array_merge(array_keys($before['values']), array_keys($after['values']), array_keys($before['sources'] ?? []), array_keys($after['sources'] ?? []))) as $locale) {
                    if (($before['values'][$locale] ?? null) !== ($after['values'][$locale] ?? null)
                        || array_key_exists($locale, $before['values']) !== array_key_exists($locale, $after['values'])
                        || ($before['sources'][$locale] ?? null) !== ($after['sources'][$locale] ?? null)) {
                        $changes[$locale][] = $field;
                    }
                }
            } else {
                // Shared fields (including page content) require a global grant.
                $changes[''][] = $field;
            }
        }
        foreach (array_unique(array_merge(array_keys($current['publication']), array_keys($restored['publication']))) as $locale) {
            $before = $current['publication'][$locale] ?? [];
            $after = $restored['publication'][$locale] ?? [];
            if ($before !== $after) $changes[$locale] = array_merge($changes[$locale] ?? [], ['status', 'published_at']);
        }
        return $changes;
    }

    public function softDelete(string $collection, string $id, ?array $user = null): void
    {
        $entry = $this->find($collection, $id, true);

        if ($entry === null) {
            return;
        }

        if ($this->isHierarchical($collection) && $this->children($collection, (string) $entry['uid']) !== []) {
            throw new ValidationException(['parent' => 'Move or delete its child pages first.']);
        }

        $entry['deleted_at'] = Security::now();
        $entry['deleted_by'] = $user['id'] ?? null;
        $this->store->write($this->stored($entry), $collection, $id);
        (new TrashStore($this->workspace))->putContent($collection, $id, $this->stored($entry));
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

        unset($entry['deleted_at'], $entry['deleted_by']);
        // A page whose parent is gone comes back as a top-level page.
        if (PageTree::parent($entry) !== '' && !isset(PageTree::byUid($this->all($collection))[PageTree::parent($entry)])) {
            $entry['parent'] = '';
            $entry['position'] = $this->nextPosition($collection, '');
        }
        $entry['updated_at'] = Security::now();
        $entry['revision'] = bin2hex(random_bytes(12));
        $site = new \CometCMS\Site\SiteRepository($this->workspace);
        $site->validateEntryUrls($site->settings(), $entry);
        $this->store->write($this->stored($entry), $collection, $id);
        (new TrashStore($this->workspace))->removeContent($collection, $id);
        $this->afterWrite('content.restored', $collection, $id, $user ?? []);

        return $this->normalizeStoredEntry($entry);
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
        if ($entry === null || $locale === $this->locale()) return null;
        $this->locale($locale);
        $entry = $this->stored($entry);
        $this->writeRevision($collection, $id, $entry, 'content.translation_deleted', $user);
        foreach ($entry['values'] as &$value) $value = LocalizedValue::remove($value, $locale);
        unset($value, $entry['publication'][$locale]);
        $entry['revision'] = bin2hex(random_bytes(12));
        $entry['updated_at'] = Security::now();
        $this->store->write($this->stored($entry), $collection, $id);
        $this->afterWrite('content.updated', $collection, $id, $user);
        return $this->normalizeStoredEntry($entry);
    }

    public function replaceMediaFilename(string $oldFilename, string $newFilename): int
    {
        $updated = 0;
        foreach ($this->types->all() as $schema) foreach ($this->all($schema['name'], true) as $entry) {
            $before = $entry['values'];
            foreach ($schema['fields'] as $name => $config) {
                $value = $entry['values'][$name] ?? null;
                if (LocalizedValue::is($value)) {
                    foreach ($value['values'] as &$item) $item = $this->replaceMediaValue($item, $config, $oldFilename, $newFilename);
                    unset($item);
                } else $value = $this->replaceMediaValue($value, $config, $oldFilename, $newFilename);
                $entry['values'][$name] = $value;
            }
            if ($before !== $entry['values']) {
                $this->store->write($this->stored($entry), $schema['name'], $entry['id']);
                $updated++;
            }
        }
        if ($updated) $this->cache->clear();
        return $updated;
    }

    public function migrateFields(string $collection, array $migrations): void
    {
        foreach ($this->all($collection, true) as $entry) {
            foreach ($migrations as $migration) {
                $from = $migration['from_key']; $to = $migration['to_key'];
                if (array_key_exists($from, $entry['values']) && !array_key_exists($to, $entry['values'])) {
                    $entry['values'][$to] = $entry['values'][$from];
                    unset($entry['values'][$from]);
                }
            }
            $this->store->write($this->stored($entry), $collection, $entry['id']);
        }
        $this->cache->clear();
    }

    public function isPubliclyVisible(array $entry, string $locale = ''): bool
    {
        if (!empty($entry['deleted_at'])) return false;
        $locale = $locale ?: ($entry['locale'] ?? $this->locale());
        if (!in_array($locale, (new LanguageSettings($this->workspace))->all()['locales'], true)) return false;
        $publication = $entry['publication'][$locale] ?? null;
        if (!is_array($publication) || ($publication['status'] ?? '') !== 'published') return false;
        $at = strtotime((string) ($publication['published_at'] ?? ''));
        return $at === false || $at <= time();
    }

    public function query(string $collection, array $params, bool $admin = false, array $overrides = []): array
    {
        $entries = $this->storedEntries($collection);
        if (!$admin || ($params['trash'] ?? '') !== '1') {
            $entries = array_values(array_filter($entries, static fn(array $entry): bool => empty($entry['deleted_at'])));
        }
        foreach ($this->types->isDisabled($collection) ? [] : $overrides as $override) {
            $entries = array_values(array_filter($entries, static fn(array $entry): bool =>
                $entry['uid'] !== $override['uid']));
            $entries[] = $override;
        }

        $locale = $this->locale((string) ($params['locale'] ?? ''));
        if (!$admin) $entries = array_values(array_filter($entries, fn(array $entry): bool => $this->isPubliclyVisible($entry, $locale)));
        $entries = array_map(fn(array $entry): array => $this->resolveLocaleFields($entry, $locale, !$admin), $entries);

        $entries = ContentQuery::applySearch($this->types, $entries, (string) ($params['q'] ?? ''), $collection);
        $entries = ContentQuery::applyFilters($entries, ContentQuery::filtersFromParams($this->types, $params, $collection));

        $total = count($entries);
        $sortParam = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($params['sort'] ?? '-created_at')) ?: '-created_at';
        $order = strtolower((string) ($params['order'] ?? '')) === 'asc' ? 'asc' : 'desc';
        $sort = ltrim($sortParam, '-');

        if (str_starts_with($sortParam, '-')) {
            $order = 'desc';
        } elseif (!array_key_exists('order', $params)) {
            $order = 'asc';
        }

        $sortable = array_map(static fn(array $entry): array => [
            'entry' => $entry, 'key' => ContentQuery::sortKey(ContentQuery::valueAt($entry, $sort)),
            'modified' => (string) ($entry['updated_at'] ?? $entry['created_at'] ?? ''),
        ], $entries);
        usort($sortable, static function (array $a, array $b) use ($order): int {
            $result = ContentQuery::compareSortKeys($a['key'], $b['key']);

            return ($order === 'asc' ? $result : -$result) ?: strcmp($b['modified'], $a['modified']);
        });
        $entries = array_column($sortable, 'entry');

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

    public function expandRelations(array $entry, string $collection, array $include, string $locale = '', ?callable $authorize = null): array
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
                $entry[$field] = array_values(array_filter(array_map(fn(mixed $id): ?array => $this->resolveRelationLocale($this->findByIdentifier($target, (string) $id, false, $locale), $locale, $authorize), (array) $entry[$field])));
            } else {
                $entry[$field] = $entry[$field] ? $this->resolveRelationLocale($this->findByIdentifier($target, (string) $entry[$field], false, $locale), $locale, $authorize) : null;
            }
        }

        return $entry;
    }

    public function normalizeStoredEntry(array $entry): array
    {
        return $this->resolveLocaleFields($entry, $this->locale());
    }


    private function stored(array $entry): array
    {
        return array_intersect_key($entry, array_flip(['id', 'uid', 'collection', 'parent', 'position', 'page_template', 'values', 'publication', 'revision', 'created_at', 'updated_at', 'author_id', 'updated_by', 'deleted_at', 'deleted_by']));
    }

    private function normalizeAndValidate(string $collection, array $payload, array $user, ?array $existing, bool $autoResolveSlugConflicts, ?array $validateOnlyFields = null): array
    {
        if ($this->types->isDisabled($collection)) throw new ValidationException(['collection' => 'Enable the extension before editing this collection.']);
        $schema = $this->types->find($collection);
        $default = $this->locale();
        $locale = $this->locale((string) ($payload['locale'] ?? ''));
        foreach (['values', 'publication', 'translations'] as $reserved) {
            if (array_key_exists($reserved, $payload)) throw new ValidationException([$reserved => 'Write ordinary field values for one explicit locale.']);
        }
        $entry = $existing !== null ? $this->stored($existing) : [
            'id' => '', 'uid' => Security::opaqueId(), 'collection' => $collection,
            'values' => [], 'publication' => [], 'created_at' => Security::now(),
            'author_id' => $user['id'] ?? null,
        ];
        $resolved = $existing !== null ? $this->resolveLocaleFields($existing, $locale) : [];
        $status = (string) ($payload['status'] ?? $entry['publication'][$locale]['status'] ?? 'draft');
        $errors = [];
        if (!in_array($status, ['draft', 'published', 'protected', 'archived'], true)) $errors['status'] = 'Choose a valid status.';
        $resets = $payload['reset_fields'] ?? [];
        if (!is_array($resets)) throw new ValidationException(['reset_fields' => 'Expected a list of field keys.']);
        foreach ($resets as $name) if (!is_string($name) || !array_key_exists($name, $schema['fields'])) throw new ValidationException(['reset_fields' => 'Choose an existing localized field.']);
        $slugField = $schema['slug_field'] ?? 'slug';
        $hierarchical = !empty($schema['hierarchical']);
        $siblingsOf = null;
        if ($hierarchical) {
            $previousParent = PageTree::parent($entry);
            $entry['parent'] = array_key_exists('parent', $payload) ? $this->validParent($collection, $payload['parent'], $entry, $errors) : $previousParent;
            $entry['position'] = array_key_exists('position', $payload)
                ? max(0, (int) $payload['position'])
                : ($existing !== null && $entry['parent'] === $previousParent ? (int) ($entry['position'] ?? 0) : $this->nextPosition($collection, $entry['parent']));
            // Slugs only need to be unique among the pages that share a parent.
            $siblingsOf = $entry['parent'];
        }
        // The website template the entry's page uses instead of the automatic one (see SiteRepository::resolveTemplate()).
        if (array_key_exists('page_template', $payload)) {
            $picked = is_string($payload['page_template']) ? trim($payload['page_template']) : '';
            if ($picked !== '' && !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $picked)) $errors['page_template'] = 'Choose a template of this content type.';
            elseif ($picked === '') unset($entry['page_template']);
            else $entry['page_template'] = $picked;
        }
        $fields = $schema['fields'];
        foreach ($fields as $name => $config) {
            $localized = in_array($name, ['title', 'slug', $slugField], true) || ($config['localized'] ?? true) !== false;
            if (in_array($name, $resets, true)) {
                if (!$localized || $locale === $default || in_array($name, ['slug', $slugField], true)) {
                    $errors[$name] = 'This value cannot inherit.';
                } else $entry['values'][$name] = LocalizedValue::remove($entry['values'][$name] ?? null, $locale);
                continue;
            }
            $provided = array_key_exists($name, $payload);
            if (!$provided && $existing !== null) continue;
            if (!$localized && $locale !== $default) {
                if ($provided && ($entry['values'][$name] ?? null) !== $payload[$name]) $errors[$name] = 'Shared values can only be edited in the default language.';
                continue;
            }
            $type = $this->fields->get((string) ($config['type'] ?? 'text'));
            $value = $provided ? $payload[$name] : ($config['default'] ?? null);
            if (LocalizedValue::is($value)) { $errors[$name] = 'Write one locale at a time.'; continue; }
            if ($name === $slugField || $name === 'slug') {
                $source = $payload[$schema['slug_source'] ?? 'title'] ?? $resolved['title'] ?? '';
                $value = Security::slug((string) ($value ?: $source));
                $unique = $this->uniqueSlug($collection, $value, (string) ($existing['id'] ?? ''), $locale, $siblingsOf);
                if ($unique !== $value && $provided) $errors[$name] = 'Slug must be unique in this language.';
                elseif ($autoResolveSlugConflicts) $value = $unique;
            }
            $validation = $type->validate($value, $config, ['collection' => $collection, 'entry' => $resolved]);
            if (!($validation['valid'] ?? false)) { $errors[$name] = $validation['message'] ?? 'Invalid value.'; continue; }
            $value = $type->normalize($value, $config, ['collection' => $collection, 'entry' => $resolved]);
            $entry['values'][$name] = $localized ? LocalizedValue::set($entry['values'][$name] ?? null, $locale, $value, $default) : $value;
        }
        // Slugs are explicit for every locale, even when its other content inherits.
        if (!isset($entry['values'][$slugField]['values'][$locale])) {
            $source = (string) ($payload['title'] ?? $resolved['title'] ?? $collection);
            $slug = $this->uniqueSlug($collection, Security::slug($source), (string) ($existing['id'] ?? ''), $locale, $siblingsOf);
            $entry['values'][$slugField] = LocalizedValue::set($entry['values'][$slugField] ?? null, $locale, $slug, $default);
        }
        if ($hierarchical && $existing !== null && $entry['parent'] !== $previousParent && !isset($errors['parent'])) {
            foreach (array_keys($entry['publication']) as $other) {
                $slug = (string) LocalizedValue::resolve($entry['values'][$slugField] ?? null, $other, $default);
                if ($this->uniqueSlug($collection, $slug, (string) $existing['id'], $other, $entry['parent']) !== $slug) {
                    $errors['parent'] = 'A page with the same slug already exists there.';
                    break;
                }
            }
        }
        if ($slugField !== 'slug') $entry['values']['slug'] = $entry['values'][$slugField];
        if ($errors !== []) throw new ValidationException($errors);
        $publishedAt = $this->normalizeDateTime($payload['published_at'] ?? $entry['publication'][$locale]['published_at'] ?? null);
        if ($status === 'published' && $publishedAt === null) $publishedAt = Security::now();
        $entry['publication'][$locale] = ['status' => $status, 'published_at' => $publishedAt];
        $entry['id'] = $existing['id'] ?? (string) LocalizedValue::resolve($entry['values']['slug'], $locale, $default);
        if ($existing === null && $this->store->read($collection, $entry['id']) !== null) $entry['id'] = $entry['uid'];
        $entry['revision'] = bin2hex(random_bytes(12));
        $entry['updated_at'] = Security::now();
        $entry['updated_by'] = $user['id'] ?? null;
        if (isset($payload['author_id'])) $entry['author_id'] = $payload['author_id'];
        if ($status === 'published' && $validateOnlyFields === null) {
            $visible = $this->resolveLocaleFields($entry, $locale, true);
            foreach ($fields as $name => $config) {
                if (empty($config['required'])) continue;
                $validation = $this->fields->get($config['type'] ?? 'text')->validate($visible[$name] ?? null, $config, ['collection' => $collection, 'entry' => $visible]);
                if (!($validation['valid'] ?? false)) $errors[$name] = $validation['message'] ?? 'A published translation needs a visible value.';
            }
            if ($errors !== []) throw new ValidationException($errors);
        }
        return $entry;
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
            'entry' => $this->stored($entry),
        ];

        $this->revisions->write($revision, $collection, $id, $revisionId);
        $this->pruneRevisions($collection, $id);
    }

    private function pruneRevisions(string $collection, string $id): void
    {
        $maxRevisions = (int) comet_config('content.max_revisions', 55);

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

    /** $siblingsOf limits the check to the children of one parent ('' for top-level pages). */
    private function uniqueSlug(string $collection, string $slug, string $ignoreId = '', string $locale = '', ?string $siblingsOf = null): string
    {
        $locale = $locale ?: $this->locale();
        $used = [];
        foreach ($this->all($collection, true) as $entry) {
            if ($siblingsOf !== null && PageTree::parent($entry) !== $siblingsOf) continue;
            if ($entry['id'] !== $ignoreId && isset($entry['publication'][$locale])) {
                // Shared tree moves and duplication also check dormant slugs.
                $used[] = LocalizedValue::resolve($entry['values']['slug'], $locale, $this->locale());
            }
        }
        $candidate = $slug;
        $count = 2;
        while (in_array($candidate, $used, true)) $candidate = $slug . '-' . $count++;
        return $candidate;
    }

    public function resolveLocaleFields(array $entry, string $locale, bool $public = false): array
    {
        $default = $this->locale();
        $this->locale($locale);
        $resolved = $this->stored($entry);
        foreach ($entry['values'] ?? [] as $key => $value) {
            if ($public && $locale !== $default && LocalizedValue::is($value) && !array_key_exists($locale, $value['values']) && !$this->isPubliclyVisible($entry, $default)) {
                $resolved[$key] = null;
            } else $resolved[$key] = LocalizedValue::resolve($value, $locale, $default);
        }
        $publication = $entry['publication'][$locale] ?? ['status' => 'draft', 'published_at' => null];
        return $publication + ['locale' => $locale] + $resolved;
    }

    private function resolveRelationLocale(?array $entry, string $locale, ?callable $authorize = null): ?array
    {
        if ($entry === null) return null;
        $locale = $this->locale($locale);
        if (!$this->isPubliclyVisible($entry, $locale)) return null;
        $entry = $this->resolveLocaleFields($entry, $locale, true);
        return $authorize === null || $authorize($entry) ? $entry : null;
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
        $this->invalidateReads();
        $entry = $this->find($collection, $id, true);
        $this->cache->clear();
        $this->logger->info($event, ['type' => $collection, 'slug' => $id, 'user_id' => $user['id'] ?? null]);
        $this->webhooks->dispatch($event, [
            'type' => $collection,
            'id' => $entry !== null ? $this->stableId($entry) : $id,
            'slug' => (string) ($entry['slug'] ?? $id),
        ]);
    }

}
