<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\Http;
use CometCMS\Core\ValidationException;
use CometCMS\Media\MediaRepository;
use CometCMS\Media\MediaUpload;
use CometCMS\Media\UploadException;
use CometCMS\Workspaces\WorkspaceContext;

final class MediaController extends BaseController
{
    private MediaRepository $media;
    private ContentRepository $content;
    private ContentTypeRepository $types;

    public function __construct(Http $http)
    {
        parent::__construct($http);
        $this->media = new MediaRepository();
        $this->content = ContentRepository::make();
        $this->types = new ContentTypeRepository();
    }

    public function index(): never
    {
        $visible = $this->readableFilter('media.read');
        $category = array_key_exists('category', $_GET) ? (string) $_GET['category'] : null;
        $type = (string) ($_GET['type'] ?? 'all');
        $sort = (string) ($_GET['sort'] ?? 'newest');
        $visibilityParam = (string) ($_GET['visibility'] ?? '');
        $visibility = in_array($visibilityParam, ['public', 'private'], true) ? $visibilityParam : null;
        $usedFiles = (string) ($_GET['usage'] ?? '') === 'unused' ? $this->buildUsages() : null;
        $isLimited = array_key_exists('limit', $_GET) || array_key_exists('offset', $_GET) || $usedFiles !== null;
        $result = $this->media->listing((string) ($_GET['q'] ?? ''), $category,
            isset($_GET['limit']) ? (int) $_GET['limit'] : null, (int) ($_GET['offset'] ?? 0),
            $type, $sort, $visibility, $usedFiles, $visible);
        $meta = $isLimited ? $result['meta'] : array_intersect_key($result['meta'], ['categories' => true, 'stats' => true]);
        $this->json(['data' => array_map(fn(array $file): array => $this->withMediaUrl($file), $result['data']), 'meta' => $meta]);
    }

    public function store(): never
    {
        $user = $this->requirePermission('media.upload', ['type' => 'media', 'category' => $_POST['category'] ?? '']);
        $this->verifyCsrf();
        try {
            $items = (new MediaUpload($this->media))->upload(MediaUpload::files($_FILES['media'] ?? null),
                (string) ($_POST['category'] ?? ''), (string) ($user['id'] ?? ''), (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
                fn(array $item) => $this->logger->info('media.uploaded', ['file' => $item['filename'], 'user_id' => $user['id'] ?? null]));
        } catch (UploadException $error) {
            $this->json(['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
        }
        $this->json(['data' => array_map(fn(array $file): array => $this->withMediaUrl($file), $items), 'meta' => ['categories' => $this->media->categories()]], 201);
    }

    public function categoryStore(): never
    {
        $body = $this->requestJson();
        $name = (string) ($body['name'] ?? '');
        $parent = (string) ($body['parent'] ?? '');
        $this->requirePermission('media.update', ['type' => 'media', 'category' => trim($parent) === '' ? trim($name) : trim($parent) . ' / ' . trim($name)]);
        $this->verifyCsrf();

        try {
            $categories = $this->media->addCategory($name, $parent);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage()]], 422);
        }

        $this->cache->clear();
        $this->json(['data' => ['name' => $this->matchingCategory($parent === '' ? $name : $parent . ' / ' . $name, $categories)], 'meta' => ['categories' => $categories]], 201);
    }

    public function categoryRename(string $category): never
    {
        $this->requirePermission('media.update', ['type' => 'media', 'category' => rawurldecode($category)]);
        $this->verifyCsrf();
        $body = $this->requestJson();

        try {
            $categories = $this->media->renameCategory(rawurldecode($category), (string) ($body['name'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage()]], 422);
        }

        $this->cache->clear();
        $this->json(['data' => ['name' => $this->matchingCategory((string) ($body['name'] ?? ''), $categories)], 'meta' => ['categories' => $categories]]);
    }

    public function categoryDelete(string $category): never
    {
        $this->requirePermission('media.update', ['type' => 'media', 'category' => rawurldecode($category)]);
        $this->verifyCsrf();

        try {
            $categories = $this->media->deleteCategory(rawurldecode($category));
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage()]], 422);
        }

        $this->cache->clear();
        $this->json(['data' => ['ok' => true], 'meta' => ['categories' => $categories]]);
    }

    public function categoryUpdate(string $file): never
    {
        $this->requirePermission('media.update', $this->media->permissionContext(rawurldecode($file)));
        $this->verifyCsrf();
        $body = $this->requestJson();
        $target = (string) ($body['category'] ?? '');
        if ($target !== '' && !$this->can($this->requireUser(), 'media.update', ['type' => 'media', 'category' => $target])) {
            $this->json(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to move files into this category.']], 403);
        }
        $item = $this->media->assignCategory($file, $target);

        if ($item === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Media file not found.']], 404);
        }

        $this->cache->clear();
        $this->json([
            'data' => $this->withMediaUrl($item),
            'meta' => ['categories' => $this->media->categories()],
        ]);
    }

    public function bulkCategoryUpdate(): never
    {
        $user = $this->requireUser();
        $this->verifyCsrf();
        $body = $this->requestJson();
        $files = (array) ($body['files'] ?? []);

        if ($files === []) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Select at least one media file.']], 422);
        }

        $this->requireEachFile($user, 'media.update', $files);
        // Moving files into a category requires access to that category too.
        if (!$this->can($user, 'media.update', ['type' => 'media', 'category' => (string) ($body['category'] ?? '')])
            && (string) ($body['category'] ?? '') !== '') {
            $this->json(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to move files into this category.']], 403);
        }

        $items = $this->media->assignCategoryToMany($files, (string) ($body['category'] ?? ''));
        $this->cache->clear();

        $this->json([
            'data' => array_map(fn(array $file): array => $this->withMediaUrl($file), $items),
            'meta' => ['categories' => $this->media->categories()],
        ]);
    }

    public function destroy(string $file): never
    {
        $user = $this->requirePermission('media.delete', $this->media->permissionContext(rawurldecode($file)));
        $this->verifyCsrf();
        $this->media->delete($file);
        $this->cache->clear();
        $this->logger->info('media.deleted', ['file' => $file, 'user_id' => $user['id'] ?? null]);

        $this->json(['data' => ['ok' => true]]);
    }

    public function bulkDelete(): never
    {
        $user = $this->requireUser();
        $this->verifyCsrf();
        $body = $this->requestJson();
        $files = (array) ($body['files'] ?? []);

        if ($files === []) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Select at least one media file.']], 422);
        }

        $this->requireEachFile($user, 'media.delete', $files);

        $deleted = $this->media->deleteMany($files);
        $this->cache->clear();
        $this->logger->info('media.bulk_deleted', ['count' => count($deleted), 'user_id' => $user['id'] ?? null]);

        $this->json(['data' => ['deleted' => $deleted]]);
    }

    public function updateMeta(string $file): never
    {
        $this->requirePermission('media.update', $this->media->permissionContext(rawurldecode($file)));
        $this->verifyCsrf();
        $body = $this->requestJson();
        $item = $this->media->updateMeta($file, $body['alt'] ?? '', $body['title'] ?? '', $body['revision'] ?? null);

        if ($item === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Media file not found.']], 404);
        }

        $this->cache->clear();
        $this->json(['data' => $this->withMediaUrl($item)]);
    }

    public function updateVisibility(string $file): never
    {
        $this->requirePermission('media.update', $this->media->permissionContext(rawurldecode($file)));
        $this->verifyCsrf();
        $body = $this->requestJson();
        $item = $this->media->updateVisibility($file, (string) ($body['visibility'] ?? 'public'));

        if ($item === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Media file not found.']], 404);
        }

        $this->cache->clear();
        $this->json(['data' => $this->withMediaUrl($item)]);
    }

    public function bulkUpdateVisibility(): never
    {
        $user = $this->requireUser();
        $this->verifyCsrf();
        $body = $this->requestJson();
        $files = (array) ($body['files'] ?? []);

        if ($files === []) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Select at least one media file.']], 422);
        }

        $this->requireEachFile($user, 'media.update', $files);

        $items = $this->media->updateVisibilityForMany($files, (string) ($body['visibility'] ?? 'public'));
        $this->cache->clear();

        $this->json(['data' => array_map(fn(array $file): array => $this->withMediaUrl($file), $items)]);
    }

    public function rename(string $file): never
    {
        $this->requirePermission('media.update', $this->media->permissionContext(rawurldecode($file)));
        $this->verifyCsrf();
        $body = $this->requestJson();
        $oldFilename = rawurldecode($file);

        try {
            $item = $this->media->rename($file, (string) ($body['name'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage()]], 422);
        } catch (\RuntimeException $e) {
            $this->json(['error' => ['code' => 'rename_failed', 'message' => $e->getMessage()]], 500);
        }

        if ($item === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Media file not found.']], 404);
        }

        $updatedEntries = $this->content->replaceMediaFilename($oldFilename, (string) ($item['name'] ?? ''));
        $this->cache->clear();
        $this->json([
            'data' => $this->withMediaUrl($item),
            'meta' => [
                'categories' => $this->media->categories(),
                'updated_entries' => $updatedEntries,
            ],
        ]);
    }

    public function usages(): never
    {
        $this->readableFilter('media.read');

        $this->json(['data' => $this->buildUsages()]);
    }

    /**
     * Require that the user may perform $action on at least some media and return
     * a listing filter, or null when every file is accessible.
     */
    private function readableFilter(string $action): ?\Closure
    {
        $user = $this->requireUser();

        if ($this->can($user, $action, ['type' => 'media'])) {
            return null;
        }

        if (!$this->permissions->grantsAction($user, $action)) {
            $this->json(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to perform this action.']], 403);
        }

        return fn(array $context): bool => $this->can($user, $action, $context);
    }

    /** Bulk actions are all-or-nothing: refuse when any selected file is out of reach. */
    private function requireEachFile(array $user, string $action, array $files): void
    {
        $denied = [];

        foreach ($files as $file) {
            $file = basename((string) $file);
            if (!$this->can($user, $action, $this->media->permissionContext($file))) {
                $denied[] = $file;
            }
        }

        if ($denied !== []) {
            $this->json(['error' => [
                'code' => 'forbidden',
                'message' => 'You do not have permission to change ' . count($denied) . ' of the selected files.',
                'files' => $denied,
            ]], 403);
        }
    }

    private function buildUsages(): array
    {
        $user = $this->requireUser();

        $schemaMap = [];

        foreach ($this->types->all() as $schema) {
            $name = (string) ($schema['name'] ?? '');

            if ($name !== '') {
                $schemaMap[$name] = $schema;
            }
        }

        $usages = [];

        foreach ($this->content->collections() as $collection) {
            // Never reveal entry titles from collections the user cannot read.
            if (!$this->can($user, 'content.read', ['type' => 'content', 'collection' => (string) $collection])) {
                continue;
            }

            $schema = $schemaMap[$collection] ?? null;
            $rawFields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

            $mediaFieldKeys = [];
            $repeaterMediaSubfields = [];

            foreach ($rawFields as $key => $field) {
                if (!is_array($field)) {
                    continue;
                }

                if (($field['type'] ?? '') === 'media') {
                    $mediaFieldKeys[] = (string) $key;
                } elseif (($field['type'] ?? '') === 'repeater') {
                    $subfields = is_array($field['subfields'] ?? null) ? $field['subfields'] : [];

                    foreach ($subfields as $sub) {
                        if (is_array($sub) && ($sub['type'] ?? '') === 'media') {
                            $repeaterMediaSubfields[(string) $key][] = (string) ($sub['key'] ?? '');
                        }
                    }
                }
            }

            try {
                $entries = $this->content->all($collection);
            } catch (\Throwable) {
                continue;
            }

            foreach ($entries as $entry) {
                $id = (string) ($entry['id'] ?? '');

                if ($id === '') {
                    continue;
                }

                $title = null;

                foreach (['title', 'name', 'label', 'heading', 'slug'] as $tf) {
                    if (isset($entry[$tf]) && is_string($entry[$tf]) && $entry[$tf] !== '') {
                        $title = $entry[$tf];
                        break;
                    }
                }

                $ref = ['collection' => $collection, 'id' => $id, 'title' => $title ?? $id];

                foreach ($mediaFieldKeys as $key) {
                    $value = $entry[$key] ?? null;

                    if (is_array($value)) {
                        foreach ($value as $file) {
                            if (is_string($file) && $file !== '') {
                                $usages[$file][] = $ref;
                            }
                        }
                    }
                }

                foreach ($repeaterMediaSubfields as $repeaterKey => $subKeys) {
                    $rows = $entry[$repeaterKey] ?? null;

                    if (!is_array($rows)) {
                        continue;
                    }

                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }

                        foreach ($subKeys as $subKey) {
                            $value = $row[$subKey] ?? null;

                            if (is_array($value)) {
                                foreach ($value as $file) {
                                    if (is_string($file) && $file !== '') {
                                        $usages[$file][] = $ref;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($usages as $file => $refs) {
            $seen = [];
            $usages[$file] = array_values(array_filter($refs, function (array $ref) use (&$seen): bool {
                $key = $ref['collection'] . ':' . $ref['id'];

                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            }));
        }

        return $usages;
    }

    public function regenerateThumbnails(): never
    {
        $this->requirePermission('media.update', ['type' => 'media']);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $files = array_values(array_filter((array) ($body['files'] ?? []), static fn(mixed $file): bool => is_string($file) && $file !== ''));

        $summary = $this->media->regenerateSizes($files);
        $this->cache->clear();

        $this->json(['data' => $summary]);
    }

    /** The files a full rebuild goes through; the editor sends them back in small batches. */
    public function sizesFiles(): never
    {
        $this->requirePermission('media.update', ['type' => 'media']);
        $this->json(['data' => $this->media->resizableFiles()]);
    }

    public function sizesShow(): never
    {
        $this->requirePermission('media.read', ['type' => 'media']);
        $this->json(['data' => $this->media->sizes()->all()]);
    }

    /** New sizes apply as images are next requested; regenerating makes them all at once. */
    public function sizesUpdate(): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();

        try {
            $sizes = $this->media->sizes()->save($this->requestJson());
        } catch (ValidationException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->fields()]], 422);
        }

        $this->cache->clear();
        $this->logger->info('media.sizes_updated', $sizes);
        $this->json(['data' => $sizes]);
    }

    private function withMediaUrl(array $file): array
    {
        $workspace = WorkspaceContext::active()->slug();
        $file['url'] = $this->http->url('/media/' . rawurlencode($workspace) . '/' . rawurlencode((string) $file['name']));
        $file['thumb_url'] = ($file['thumb'] ?? null) !== null
            ? $file['url'] . '?size=thumbnail'
            : $file['url'];

        return $file;
    }


    private function matchingCategory(string $name, array $categories): string
    {
        foreach ($categories as $category) {
            if (strcasecmp((string) $category, trim($name)) === 0) {
                return (string) $category;
            }
        }

        return trim($name);
    }
}
