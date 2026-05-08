<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\Http;
use CometCMS\Core\MimeDetector;
use CometCMS\Core\Security;
use CometCMS\Media\MediaRepository;
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
        $this->requirePermission('media.read', ['type' => 'media']);
        $category = array_key_exists('category', $_GET) ? (string) $_GET['category'] : null;
        $type = (string) ($_GET['type'] ?? 'all');
        $sort = (string) ($_GET['sort'] ?? 'newest');
        $visibilityParam = (string) ($_GET['visibility'] ?? '');
        $visibility = in_array($visibilityParam, ['public', 'private'], true) ? $visibilityParam : null;
        $isLimited = array_key_exists('limit', $_GET) || array_key_exists('offset', $_GET);

        if (!$isLimited) {
            $data = array_map(fn(array $file): array => $this->withMediaUrl($file), $this->media->files((string) ($_GET['q'] ?? ''), $category, $type, $sort, $visibility));
            $this->json(['data' => $data, 'meta' => ['categories' => $this->media->categories()]]);
        }

        $limit = array_key_exists('limit', $_GET) ? (int) $_GET['limit'] : null;
        $offset = (int) ($_GET['offset'] ?? 0);
        $result = $this->media->limitedFiles((string) ($_GET['q'] ?? ''), $category, $limit, $offset, $type, $sort, $visibility);
        $data = array_map(fn(array $file): array => $this->withMediaUrl($file), $result['data']);

        $this->json(['data' => $data, 'meta' => array_replace($result['meta'], ['categories' => $this->media->categories()])]);
    }

    public function store(): never
    {
        $user = $this->requirePermission('media.upload', ['type' => 'media', 'category' => $_POST['category'] ?? '']);
        $this->verifyCsrf();
        $uploads = $this->uploadedFiles('media');

        if ($uploads === []) {
            $message = isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > (int) ini_get('post_max_size') * 1024 * 1024
                ? 'The upload exceeds the server\'s post_max_size limit.'
                : 'Upload failed.';
            $this->json(['error' => ['code' => 'upload_failed', 'message' => $message]], 422);
        }

        $max = (int) comet_config('media.max_upload_bytes', 8388608);
        $allowed = comet_config('media.allowed_mime_types', []);
        $items = [];

        foreach ($uploads as $file) {
            $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($uploadError !== UPLOAD_ERR_OK) {
                $message = ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE)
                    ? 'File exceeds the server\'s upload size limit.'
                    : 'Upload failed.';
                $this->json(['error' => ['code' => 'upload_failed', 'message' => $message]], 422);
            }

            $size = (int) ($file['size'] ?? 0);

            if ($size <= 0 || $size > $max) {
                $this->json(['error' => ['code' => 'file_too_large', 'message' => 'File is too large.']], 422);
            }

            $mime = MimeDetector::detect((string) $file['tmp_name'], (string) ($file['name'] ?? ''));

            if (!in_array($mime, is_array($allowed) ? $allowed : [], true)) {
                $this->json(['error' => ['code' => 'file_type_not_allowed', 'message' => 'File type is not allowed.']], 422);
            }

            $original = (string) ($file['name'] ?? 'upload');
            $extension = pathinfo($original, PATHINFO_EXTENSION);
            $ext = $extension !== '' ? '.' . strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension)) : '';
            $base = Security::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'upload';
            $name = $base;
            $suffix = 2;
            while (file_exists($this->media->directory() . '/' . $name . $ext)) {
                $name = $base . '-' . $suffix;
                $suffix++;
            }
            $target = $this->media->directory() . '/' . $name . $ext;

            if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
                $this->json(['error' => ['code' => 'upload_failed', 'message' => 'Could not store uploaded file.']], 500);
            }

            $stored = basename($target);
            $item = $this->media->setUploadedCategory($stored, (string) ($_POST['category'] ?? ''));
            $this->media->setUploadedBy($stored, (string) ($user['id'] ?? ''));
            $item = $this->media->item($stored);
            $item['mime'] = $mime;
            $items[] = $this->withMediaUrl($item);
            $this->logger->info('media.uploaded', ['file' => $stored, 'user_id' => $user['id'] ?? null]);
        }

        $this->cache->clear();

        $this->json(['data' => $items, 'meta' => ['categories' => $this->media->categories()]], 201);
    }

    public function categoryStore(): never
    {
        $this->requirePermission('media.update', ['type' => 'media']);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $name = (string) ($body['name'] ?? '');
        $parent = (string) ($body['parent'] ?? '');

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
        $this->requirePermission('media.update', ['type' => 'media', 'file' => rawurldecode($file)]);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $item = $this->media->assignCategory($file, (string) ($body['category'] ?? ''));

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
        $this->requirePermission('media.update', ['type' => 'media']);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $files = (array) ($body['files'] ?? []);

        if ($files === []) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Select at least one media file.']], 422);
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
        $user = $this->requirePermission('media.delete', ['type' => 'media', 'file' => rawurldecode($file)]);
        $this->verifyCsrf();
        $this->media->delete($file);
        $this->cache->clear();
        $this->logger->info('media.deleted', ['file' => $file, 'user_id' => $user['id'] ?? null]);

        $this->json(['data' => ['ok' => true]]);
    }

    public function bulkDelete(): never
    {
        $user = $this->requirePermission('media.delete', ['type' => 'media']);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $files = (array) ($body['files'] ?? []);

        if ($files === []) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Select at least one media file.']], 422);
        }

        $deleted = $this->media->deleteMany($files);
        $this->cache->clear();
        $this->logger->info('media.bulk_deleted', ['count' => count($deleted), 'user_id' => $user['id'] ?? null]);

        $this->json(['data' => ['deleted' => $deleted]]);
    }

    public function updateMeta(string $file): never
    {
        $this->requirePermission('media.update', ['type' => 'media', 'file' => rawurldecode($file)]);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $item = $this->media->updateMeta($file, (string) ($body['alt'] ?? ''), (string) ($body['title'] ?? ''));

        if ($item === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Media file not found.']], 404);
        }

        $this->cache->clear();
        $this->json(['data' => $this->withMediaUrl($item)]);
    }

    public function updateVisibility(string $file): never
    {
        $this->requirePermission('media.update', ['type' => 'media', 'file' => rawurldecode($file)]);
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
        $this->requirePermission('media.update', ['type' => 'media']);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $files = (array) ($body['files'] ?? []);

        if ($files === []) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Select at least one media file.']], 422);
        }

        $items = $this->media->updateVisibilityForMany($files, (string) ($body['visibility'] ?? 'public'));
        $this->cache->clear();

        $this->json(['data' => array_map(fn(array $file): array => $this->withMediaUrl($file), $items)]);
    }

    public function rename(string $file): never
    {
        $this->requirePermission('media.update', ['type' => 'media', 'file' => rawurldecode($file)]);
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
        $this->requirePermission('media.read', ['type' => 'media']);

        $schemaMap = [];

        foreach ($this->types->all() as $schema) {
            $name = (string) ($schema['name'] ?? '');

            if ($name !== '') {
                $schemaMap[$name] = $schema;
            }
        }

        $usages = [];

        foreach ($this->content->collections() as $collection) {
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

        $this->json(['data' => $usages]);
    }

    public function regenerateThumbnails(): never
    {
        $this->requirePermission('media.update', ['type' => 'media']);
        $this->verifyCsrf();

        $summary = $this->media->regenerateThumbnails();
        $this->cache->clear();

        $this->json(['data' => $summary]);
    }

    private function withMediaUrl(array $file): array
    {
        $workspace = WorkspaceContext::active()->slug();
        $file['url'] = $this->http->url('/media/' . rawurlencode($workspace) . '/' . rawurlencode((string) $file['name']));
        $file['thumb_url'] = ($file['thumb'] ?? null) !== null
            ? $this->http->url('/media-thumbs/' . rawurlencode($workspace) . '/' . rawurlencode((string) $file['name']))
            : $file['url'];

        return $file;
    }

    private function uploadedFiles(string $field): array
    {
        $files = $_FILES[$field] ?? null;

        if (!is_array($files) || !array_key_exists('name', $files)) {
            return [];
        }

        if (!is_array($files['name'])) {
            return [$files];
        }

        $uploads = [];

        foreach (array_keys($files['name']) as $index) {
            $uploads[] = [
                'name' => $files['name'][$index] ?? null,
                'type' => $files['type'][$index] ?? null,
                'tmp_name' => $files['tmp_name'][$index] ?? null,
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }

        return $uploads;
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
