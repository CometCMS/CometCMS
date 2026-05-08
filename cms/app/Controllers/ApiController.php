<?php

declare(strict_types=1);

namespace CometCMS\Controllers;

use CometCMS\Auth\ApiTokenRepository;
use CometCMS\Auth\PermissionService;
use CometCMS\Cache\ApiCache;
use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\ApiResponder;
use CometCMS\Core\Http;
use CometCMS\Core\MimeDetector;
use CometCMS\Core\Security;
use CometCMS\Core\ValidationException;
use CometCMS\Logging\Logger;
use CometCMS\Media\MediaRepository;
use CometCMS\Workspaces\WorkspaceContext;
use CometCMS\Workspaces\WorkspaceRepository;

final class ApiController
{
    private ApiTokenRepository $tokens;
    private ContentRepository $content;
    private ContentTypeRepository $types;
    private ApiResponder $response;
    private ApiCache $cache;
    private MediaRepository $media;
    private PermissionService $permissions;
    private WorkspaceContext $workspace;
    private bool $scopedUrls = false;

    public function __construct(private readonly Http $http)
    {
        $registry = new WorkspaceRepository();
        $this->useWorkspace($registry->getDefault());
    }

    public function useWorkspace(string $workspace, bool $allowArchived = false, bool $scopedUrls = false): self
    {
        $this->tokens = new ApiTokenRepository();
        $registry = new WorkspaceRepository();
        $workspace = Security::slug($workspace);

        if (!$registry->exists($workspace, $allowArchived)) {
            $this->response = new ApiResponder($this->http);
            $this->response->error('not_found', 'Workspace not found.', 404);
        }

        WorkspaceContext::setActive($workspace);
        $this->workspace = WorkspaceContext::active();
        $this->scopedUrls = $scopedUrls;
        $this->content = ContentRepository::make($this->workspace);
        $this->types = new ContentTypeRepository($this->workspace);
        $this->response = new ApiResponder($this->http);
        $this->cache = ApiCache::fromConfig($this->workspace);
        $this->media = new MediaRepository($this->workspace);
        $this->permissions = new PermissionService();

        return $this;
    }

    public function health(): never
    {
        $this->response->data([
            'ok' => true,
            'name' => comet_config('app.name', 'CometCMS'),
            'version' => comet_version(),
            'time' => Security::now(),
            'extensions' => [
                'gd' => extension_loaded('gd')
                    && function_exists('imagecreatetruecolor')
                    && function_exists('imagecopyresampled')
                    && function_exists('imagejpeg'),
                'zip' => class_exists(\ZipArchive::class) || extension_loaded('zip'),
            ],
        ]);
    }

    public function contentTypes(): never
    {
        $this->publicCached(['data' => $this->types->all()]);
    }

    public function contentTypeShow(string $name): never
    {
        if (!$this->types->exists($name)) {
            $this->response->error('not_found', 'Content type not found.', 404);
        }

        $this->publicCached(['data' => $this->types->find($name)]);
    }

    public function contentTypeStore(): never
    {
        $user = $this->requireToken('schema.create', ['type' => 'schema', 'name' => $name]);
        $body = $this->http->requestJson();
        $name = Security::slug((string) ($body['name'] ?? ''));

        if (!empty($body['singleton']) && $name !== '' && count($this->content->all($name)) > 1) {
            $this->response->error('validation_failed', 'Single page content types can only be enabled when there is at most one active entry.', 422);
        }

        $this->types->save($body);
        $this->cache->clear();
        (new Logger())->info('content_type.created', ['name' => $name, 'user_id' => $user['id'] ?? null]);
        $this->response->data($this->types->find($name), 201);
    }

    public function contentTypeUpdate(string $name): never
    {
        $user = $this->requireToken('schema.update', ['type' => 'schema', 'name' => $name]);

        if (!$this->types->exists($name)) {
            $this->response->error('not_found', 'Content type not found.', 404);
        }

        $body = $this->http->requestJson();
        $body['name'] = $name;

        if (!empty($body['singleton']) && count($this->content->all($name)) > 1) {
            $this->response->error('validation_failed', 'Single page content types can only be enabled when there is at most one active entry.', 422);
        }

        $this->types->save($body);
        $schema = $this->types->find($name);
        $this->content->syncLocalization($name, $schema);
        $this->cache->clear();
        (new Logger())->info('content_type.updated', ['name' => $name, 'user_id' => $user['id'] ?? null]);
        $this->response->data($schema);
    }

    public function contentTypeDelete(string $name): never
    {
        $user = $this->requireToken('schema.delete', ['type' => 'schema', 'name' => $name]);

        if (!$this->types->exists($name)) {
            $this->response->error('not_found', 'Content type not found.', 404);
        }

        $this->types->delete($name);
        $this->cache->clear();
        (new Logger())->info('content_type.deleted', ['name' => $name, 'user_id' => $user['id'] ?? null]);
        $this->response->data(['ok' => true]);
    }

    public function contentIndex(string $collection): never
    {
        $this->requireCollection($collection);
        $admin = $this->optionalTokenWithPermission('content.read', ['type' => 'content', 'collection' => $collection]) !== null;
        $result = $this->content->query($collection, $_GET, $admin);
        $include = $this->includeFields();
        $locale = (string) ($_GET['locale'] ?? '');
        $result['data'] = array_map(fn(array $entry): array => $this->content->expandRelations($entry, $collection, $include, $locale), $result['data']);
        $body = [
            'data' => array_values(array_map(fn(array $entry): array => $this->publicEntry($entry, $collection, $admin), $result['data'])),
            'meta' => $result['meta'],
        ];

        $admin ? $this->http->json($body) : $this->publicCached($body);
    }

    public function contentShow(string $collection, string $id): never
    {
        $this->requireCollection($collection);
        $user = null;
        $token = $this->bearerToken();

        if ($token !== null) {
            $user = $this->tokens->findByToken($token);

            if ($user === null) {
                (new Logger())->warning('invalid api token');
                $this->response->error('unauthorized', 'Invalid bearer token.', 401);
            }
        }

        $entry = $this->content->findByIdentifier($collection, $id, $user !== null);

        if ($entry === null || ($user === null && !$this->content->isPubliclyVisible($entry))) {
            $this->response->error('not_found', 'Content entry not found.', 404);
        }

        if ($user !== null && !$this->permissions->allows($user, 'content.read', ['type' => 'content', 'collection' => $collection, 'entry' => $entry, 'principal' => $user, 'workspace' => $this->workspace->slug()])) {
            $this->response->error('forbidden', 'Forbidden.', 403);
        }

        $locale = (string) ($_GET['locale'] ?? '');
        $schema = $this->types->find($collection);
        $locales = is_array($schema['locales'] ?? null) ? $schema['locales'] : [];

        if ($locale !== '' && in_array($locale, $locales, true)) {
            $entry = $this->content->resolveLocaleFields($entry, $locale);
        }

        $entry = $this->content->expandRelations($entry, $collection, $this->includeFields(), $locale);
        $body = ['data' => $this->publicEntry($entry, $collection, $user !== null)];
        $user ? $this->http->json($body) : $this->publicCached($body);
    }

    public function contentStore(string $collection): never
    {
        $this->requireCollection($collection);
        $body = $this->http->requestJson();
        $user = $this->requireToken('content.create', ['type' => 'content', 'collection' => $collection, 'fields' => $this->payloadFields($body), 'locale' => $body['locale'] ?? null]);
        $this->requirePublishToken($user, $collection, null, $body);

        try {
            $entry = $this->content->save($collection, $body, $user, null, true);
        } catch (ValidationException $e) {
            $this->response->error('validation_failed', $e->getMessage(), 422, $e->fields());
        }

        $this->response->data($this->publicEntry($entry, $collection, true), 201);
    }

    public function contentUpdate(string $collection, string $id): never
    {
        $this->requireCollection($collection);
        $body = $this->http->requestJson();
        $existing = $this->content->findByIdentifier($collection, $id, true);

        if ($existing === null) {
            $this->response->error('not_found', 'Content entry not found.', 404);
        }

        try {
            $user = $this->requireToken('content.update', ['type' => 'content', 'collection' => $collection, 'entry' => $existing, 'fields' => $this->payloadFields($body), 'locale' => $body['locale'] ?? null]);
            $this->requirePublishToken($user, $collection, $existing, $body);
            $entry = $this->content->save($collection, $body, $user, (string) $existing['id'], true);
        } catch (ValidationException $e) {
            $this->response->error('validation_failed', $e->getMessage(), 422, $e->fields());
        }

        $this->response->data($this->publicEntry($entry, $collection, true));
    }

    public function contentDelete(string $collection, string $id): never
    {
        $this->requireCollection($collection);
        $entry = $this->content->findByIdentifier($collection, $id, true);

        if ($entry === null) {
            $this->response->error('not_found', 'Content entry not found.', 404);
        }

        $user = $this->requireToken('content.delete', ['type' => 'content', 'collection' => $collection, 'entry' => $entry]);
        $this->content->softDelete($collection, (string) $entry['id'], $user);
        $this->response->data(['ok' => true]);
    }

    public function mediaIndex(): never
    {
        $authed = $this->optionalTokenWithPermission('media.read', ['type' => 'media']) !== null;
        $visibility = $authed ? null : 'public';
        $category = array_key_exists('category', $_GET) ? (string) $_GET['category'] : null;
        $isLimited = array_key_exists('limit', $_GET) || array_key_exists('offset', $_GET);
        $result = $isLimited
            ? $this->media->limitedFiles((string) ($_GET['q'] ?? ''), $category, array_key_exists('limit', $_GET) ? (int) $_GET['limit'] : null, (int) ($_GET['offset'] ?? 0), 'all', 'newest', $visibility)
            : $this->media->limitedFiles((string) ($_GET['q'] ?? ''), $category, null, 0, 'all', 'newest', $visibility);
        $body = [
            'data' => array_map(fn(array $file): array => $this->publicMediaItem($file), $result['data']),
            'meta' => array_replace($result['meta'], ['categories' => $this->media->categories()]),
        ];

        $authed ? $this->http->json($body) : $this->publicCached($body);
    }

    public function mediaStore(): never
    {
        $user = $this->requireToken('media.upload', ['type' => 'media', 'category' => $_POST['category'] ?? '']);
        $uploads = $this->uploadedFiles('media');

        if ($uploads === []) {
            $message = isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > (int) ini_get('post_max_size') * 1024 * 1024
                ? 'The upload exceeds the server\'s post_max_size limit.'
                : 'Upload failed.';
            $this->response->error('upload_failed', $message, 422);
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
                $this->response->error('upload_failed', $message, 422);
            }

            $size = (int) ($file['size'] ?? 0);

            if ($size <= 0 || $size > $max) {
                $this->response->error('file_too_large', 'File is too large.', 422);
            }

            $mime = MimeDetector::detect((string) $file['tmp_name'], (string) ($file['name'] ?? ''));

            if (!in_array($mime, is_array($allowed) ? $allowed : [], true)) {
                $this->response->error('file_type_not_allowed', 'File type is not allowed.', 422);
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
                $this->response->error('upload_failed', 'Could not store uploaded file.', 500);
            }

            $stored = basename($target);
            $this->media->setUploadedCategory($stored, (string) ($_POST['category'] ?? ''));
            $this->media->setUploadedBy($stored, (string) ($user['id'] ?? ''));
            $items[] = $this->publicMediaItem($this->media->item($stored));
        }

        $this->cache->clear();
        $this->http->json(['data' => $items, 'meta' => ['categories' => $this->media->categories()]], 201);
    }

    public function mediaCategoryStore(): never
    {
        $this->requireToken('media.update', ['type' => 'media']);
        $body = $this->http->requestJson();
        $name = trim((string) ($body['name'] ?? ''));
        $parent = trim((string) ($body['parent'] ?? ''));

        try {
            $categories = $this->media->addCategory($name, $parent);
        } catch (\InvalidArgumentException $e) {
            $this->response->error('validation_failed', $e->getMessage(), 422);
        }

        $this->cache->clear();
        $this->response->data(['name' => $this->matchingCategory($parent === '' ? $name : $parent . ' / ' . $name, $categories)], 201, ['categories' => $categories]);
    }

    public function mediaCategoryRename(string $category): never
    {
        $this->requireToken('media.update', ['type' => 'media', 'category' => rawurldecode($category)]);
        $body = $this->http->requestJson();
        $name = trim((string) ($body['name'] ?? ''));

        try {
            $categories = $this->media->renameCategory(rawurldecode($category), $name);
        } catch (\InvalidArgumentException $e) {
            $this->response->error('validation_failed', $e->getMessage(), 422);
        }

        $this->cache->clear();
        $this->response->data(['name' => $this->matchingCategory($name, $categories)], 200, ['categories' => $categories]);
    }

    public function mediaCategoryDelete(string $category): never
    {
        $this->requireToken('media.update', ['type' => 'media', 'category' => rawurldecode($category)]);

        try {
            $categories = $this->media->deleteCategory(rawurldecode($category));
        } catch (\InvalidArgumentException $e) {
            $this->response->error('validation_failed', $e->getMessage(), 422);
        }

        $this->cache->clear();
        $this->response->data(['ok' => true], 200, ['categories' => $categories]);
    }

    public function mediaCategoryUpdate(string $file): never
    {
        $this->requireToken('media.update', ['type' => 'media', 'file' => rawurldecode($file)]);
        $body = $this->http->requestJson();
        $item = $this->media->assignCategory($file, (string) ($body['category'] ?? ''));

        if ($item === null) {
            $this->response->error('not_found', 'Media file not found.', 404);
        }

        $this->cache->clear();
        $this->http->json([
            'data' => $this->publicMediaItem($item),
            'meta' => ['categories' => $this->media->categories()],
        ]);
    }

    public function mediaUpdateVisibility(string $file): never
    {
        $this->requireToken('media.update', ['type' => 'media']);
        $body = $this->http->requestJson();
        $item = $this->media->updateVisibility($file, (string) ($body['visibility'] ?? 'public'));

        if ($item === null) {
            $this->response->error('not_found', 'Media file not found.', 404);
        }

        $this->cache->clear();
        $this->response->data($this->publicMediaItem($item));
    }

    public function mediaBulkUpdateVisibility(): never
    {
        $this->requireToken('media.update', ['type' => 'media', 'file' => rawurldecode($file)]);
        $body = $this->http->requestJson();
        $files = (array) ($body['files'] ?? []);

        if ($files === []) {
            $this->response->error('validation_failed', 'Select at least one media file.', 422);
        }

        $items = $this->media->updateVisibilityForMany($files, (string) ($body['visibility'] ?? 'public'));
        $this->cache->clear();
        $this->http->json(['data' => array_map(fn(array $file): array => $this->publicMediaItem($file), $items)]);
    }

    public function mediaUpdateMeta(string $file): never
    {
        $this->requireToken('media.update', ['type' => 'media']);
        $body = $this->http->requestJson();
        $item = $this->media->updateMeta($file, (string) ($body['alt'] ?? ''), (string) ($body['title'] ?? ''));

        if ($item === null) {
            $this->response->error('not_found', 'Media file not found.', 404);
        }

        $this->cache->clear();
        $this->response->data($this->publicMediaItem($item));
    }

    public function mediaDelete(string $file): never
    {
        $this->requireToken('media.delete', ['type' => 'media', 'file' => rawurldecode($file)]);
        $this->media->delete($file);
        $this->cache->clear();
        $this->response->data(['ok' => true]);
    }

    public function mediaShow(string $file): never
    {
        $file = basename(rawurldecode($file));
        $path = $this->media->filePath($file);

        if (!is_file($path)) {
            $this->http->notFound();
        }

        if ($this->media->isPrivate($file)) {
            $this->requireToken('media.read', ['type' => 'media', 'file' => $file]);
        }

        $mime = MimeDetector::detect($path);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function mediaThumbShow(string $file): never
    {
        $file = basename(rawurldecode($file));
        $originalPath = $this->media->filePath($file);

        if (!is_file($originalPath)) {
            $this->http->notFound();
        }

        if ($this->media->isPrivate($file)) {
            $this->requireToken('media.read', ['type' => 'media', 'file' => $file]);
        }

        $path = $this->media->thumbnailPath($file) ?? $originalPath;
        $mime = MimeDetector::detect($path);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private function publicCached(array $body): never
    {
        $key = $this->cache->key($this->http->path(), $_SERVER['QUERY_STRING'] ?? '');
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            header('X-CometCMS-Cache: HIT');
            $this->http->json($cached);
        }

        $this->cache->put($key, $body);
        header('X-CometCMS-Cache: MISS');
        $this->http->json($body);
    }

    private function includeFields(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['include'] ?? '')))));
    }

    private function requireCollection(string $collection): void
    {
        if (!$this->types->exists($collection)) {
            $this->response->error('not_found', 'Content collection not found.', 404);
        }
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

    private function publicEntry(array $entry, string $collection, bool $admin): array
    {
        $schema = $this->types->find($collection);
        $fields = [];

        foreach (is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field => $config) {
            if (in_array($field, ['title', 'slug'], true)) {
                continue;
            }

            $config = is_array($config) ? $config : [];

            if (array_key_exists($field, $entry)) {
                $fields[$field] = $this->publicFieldValue($entry[$field], $config, $admin);
            }
        }

        return array_filter([
            'id' => $this->content->stableId($entry),
            'slug' => (string) ($entry['slug'] ?? $entry['id'] ?? ''),
            'type' => $collection,
            'status' => (string) ($entry['status'] ?? 'draft'),
            'title' => (string) ($entry['title'] ?? ''),
            'published_at' => $entry['published_at'] ?? null,
            'created_at' => (string) ($entry['created_at'] ?? ''),
            'updated_at' => (string) ($entry['updated_at'] ?? ''),
            'author_id' => $entry['author_id'] ?? null,
            'updated_by' => $entry['updated_by'] ?? null,
            'data' => $fields,
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function publicFieldValue(mixed $value, array $config, bool $admin): mixed
    {
        if (($config['type'] ?? '') === 'media') {
            return $this->publicMediaValue($value);
        }

        if (($config['type'] ?? '') === 'repeater') {
            return $this->publicRepeaterValue($value, $config, $admin);
        }

        if (($config['type'] ?? '') !== 'relation') {
            return $this->publicValue($value, $admin);
        }

        $target = (string) ($config['target'] ?? '');

        if ((bool) ($config['multiple'] ?? false)) {
            return array_values(array_filter(array_map(
                fn(mixed $item): mixed => $this->publicRelationValue($item, $target, $admin),
                is_array($value) ? $value : []
            ), static fn(mixed $item): bool => $item !== null));
        }

        return $this->publicRelationValue($value, $target, $admin);
    }

    private function publicRepeaterValue(mixed $value, array $config, bool $admin): array
    {
        if (!is_array($value)) {
            return [];
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

        return array_values(array_filter(array_map(function (mixed $row) use ($subfields, $admin): ?array {
            if (!is_array($row)) {
                return null;
            }

            $mapped = [];

            foreach ($row as $key => $item) {
                $fieldConfig = $subfields[(string) $key] ?? [];
                $mapped[$key] = $this->publicFieldValue($item, $fieldConfig, $admin);
            }

            return $mapped;
        }, $value)));
    }

    private function publicMediaValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn(mixed $file): ?string => $this->publicMediaUrl($file),
            $value
        )));
    }

    private function publicMediaUrl(mixed $file): ?string
    {
        $filename = basename(trim((string) $file));

        if ($filename === '') {
            return null;
        }

        return $this->absoluteUrl($this->mediaRoute('/media', $filename));
    }

    private function publicMediaItem(array $file): array
    {
        return [
            'filename' => $file['name'],
            'name' => $file['name'],
            'url' => $this->absoluteUrl($this->mediaRoute('/media', (string) $file['name'])),
            'thumb_url' => ($file['thumb'] ?? null) !== null
                ? $this->absoluteUrl($this->mediaRoute('/media-thumbs', (string) $file['name']))
                : $this->absoluteUrl($this->mediaRoute('/media', (string) $file['name'])),
            'size' => $file['size'],
            'mime' => $file['mime'],
            'thumb' => $file['thumb'] ?? null,
            'category' => $file['category'],
            'uploaded_by' => $file['uploaded_by'] ?? null,
            'uploaded_at' => $file['uploaded_at'] ?? null,
            'width' => $file['width'] ?? null,
            'height' => $file['height'] ?? null,
            'alt' => $file['alt'] ?? '',
            'title' => $file['title'] ?? '',
            'visibility' => $file['visibility'] ?? 'public',
        ];
    }

    private function uploadedFiles(string $field): array
    {
        $source = $_FILES[$field] ?? null;

        if (!is_array($source)) {
            return [];
        }

        if (!is_array($source['name'] ?? null)) {
            return [$source];
        }

        $files = [];

        foreach ($source['name'] as $index => $name) {
            $files[] = [
                'name' => $name,
                'type' => $source['type'][$index] ?? '',
                'tmp_name' => $source['tmp_name'][$index] ?? '',
                'error' => $source['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $source['size'][$index] ?? 0,
            ];
        }

        return $files;
    }

    private function mediaRoute(string $base, string $filename): string
    {
        $path = $base . '/';

        if ($this->scopedUrls) {
            $path .= rawurlencode($this->workspace->slug()) . '/';
        }

        return $path . rawurlencode($filename);
    }

    private function absoluteUrl(string $path): string
    {
        $url = $this->http->url($path);
        $host = $_SERVER['HTTP_HOST'] ?? '';

        if (!is_string($host) || $host === '') {
            return $url;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return $scheme . '://' . $host . $url;
    }

    private function publicRelationValue(mixed $value, string $target, bool $admin): mixed
    {
        if (is_array($value)) {
            return $this->publicValue($value, $admin);
        }

        if ($target === '' || $value === null || $value === '') {
            return $value;
        }

        $entry = $this->content->find($target, (string) $value);

        if ($entry === null) {
            return (string) $value;
        }

        if (!$admin && !$this->content->isPubliclyVisible($entry)) {
            return null;
        }

        return $this->content->stableId($entry);
    }

    private function publicValue(mixed $value, bool $admin): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['collection'], $value['id'])) {
            if (!$admin && !$this->content->isPubliclyVisible($value)) {
                return null;
            }

            return $this->publicEntry($value, (string) $value['collection'], $admin);
        }

        $mapped = [];

        foreach ($value as $key => $item) {
            $mapped[$key] = $this->publicValue($item, $admin);
        }

        return array_is_list($mapped) ? array_values(array_filter($mapped, static fn(mixed $item): bool => $item !== null)) : $mapped;
    }

    private function optionalTokenWithPermission(string $action, array $context = []): ?array
    {
        $token = $this->bearerToken();

        if ($token === null) {
            return null;
        }

        $user = $this->tokens->findByToken($token);

        if ($user === null) {
            (new Logger())->warning('invalid api token');
            $this->response->error('unauthorized', 'Invalid bearer token.', 401);
        }

        $context['principal'] = $user;
        $context['workspace'] ??= $this->workspace->slug();
        if (!$this->permissions->allows($user, $action, $context)) {
            $this->response->error('forbidden', 'Forbidden.', 403);
        }

        return $user;
    }

    private function requireToken(string $action, array $context = []): array
    {
        $token = $this->bearerToken();

        if ($token === null) {
            $this->response->error('unauthorized', 'Missing bearer token.', 401);
        }

        $user = $this->tokens->findByToken($token);

        if ($user === null) {
            (new Logger())->warning('invalid api token');
            $this->response->error('unauthorized', 'Invalid bearer token.', 401);
        }

        $context['principal'] = $user;
        $context['workspace'] ??= $this->workspace->slug();
        if (!$this->permissions->allows($user, $action, $context)) {
            $this->response->error('forbidden', 'Forbidden.', 403);
        }

        return $user;
    }

    private function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? $_SERVER['Authorization']
            ?? '';

        if (!is_string($header) || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    private function payloadFields(array $payload): array
    {
        return array_values(array_diff(array_keys($payload), ['id', 'uid', 'collection', 'created_at', 'updated_at', 'deleted_at', 'deleted_by', 'updated_by', 'translations', 'locale']));
    }

    private function requirePublishToken(array $user, string $collection, ?array $entry, array $payload): void
    {
        if (($payload['status'] ?? null) !== 'published') {
            return;
        }

        if ($this->permissions->allows($user, 'content.publish', ['type' => 'content', 'collection' => $collection, 'entry' => $entry ?? [], 'principal' => $user, 'workspace' => $this->workspace->slug()])) {
            return;
        }

        $this->response->error('forbidden', 'Forbidden.', 403);
    }
}
