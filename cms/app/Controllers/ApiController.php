<?php

declare(strict_types=1);

namespace CometCMS\Controllers;

use CometCMS\Auth\ApiTokenRepository;
use CometCMS\Auth\Auth;
use CometCMS\Auth\UserRepository;
use CometCMS\Auth\PermissionService;
use CometCMS\Cache\ApiCache;
use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Content\ExternalReadPolicy;
use CometCMS\Core\ApiResponder;
use CometCMS\Core\Http;
use CometCMS\Core\MimeDetector;
use CometCMS\Core\Security;
use CometCMS\Core\ValidationException;
use CometCMS\Logging\Logger;
use CometCMS\Media\MediaRepository;
use CometCMS\Media\MediaUpload;
use CometCMS\Media\UploadException;
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
    private ?array $readPrincipal = null;

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
        $principal = $this->tokenPrincipal();
        $types = array_values(array_filter($this->types->all(), function (array $type) use ($principal): bool {
            if (!ExternalReadPolicy::apiEnabled($type)) {
                return false;
            }
            if (($type['visibility'] ?? 'public') !== 'private') {
                return true;
            }

            return $principal !== null && $this->permissions->allows($principal, 'schema.read', [
                'type' => 'schema',
                'name' => (string) ($type['name'] ?? ''),
                'principal' => $principal,
                'workspace' => $this->workspace->slug(),
            ]);
        }));
        $body = ['data' => $types];

        $principal === null ? $this->publicCached($body) : $this->http->json($body);
    }

    public function contentTypeShow(string $name): never
    {
        if (!$this->types->exists($name)) {
            $this->response->error('not_found', 'Content type not found.', 404);
        }

        $schema = $this->types->find($name);
        if (!ExternalReadPolicy::apiEnabled($schema)) {
            $this->response->error('not_found', 'Content type is not exposed through the API.', 404);
        }

        if (($schema['visibility'] ?? 'public') === 'private') {
            $this->requireToken('schema.read', ['type' => 'schema', 'name' => $name]);
            $this->http->json(['data' => $schema]);
        }

        $this->publicCached(['data' => $schema]);
    }

    public function contentTypeStore(): never
    {
        $body = $this->http->requestJson();
        $name = $this->schemaName($body['name'] ?? '');
        $user = $this->requireToken('schema.create', ['type' => 'schema', 'name' => $name]);

        if ($name === '') {
            $this->response->error('validation_failed', 'Choose an identifier for this content type.', 422, ['name' => ['Choose an identifier for this content type.']]);
        }

        if ($this->types->exists($name)) {
            $this->response->error('validation_failed', 'A content type with this identifier already exists.', 422, ['name' => ['A content type with this identifier already exists.']]);
        }

        $body['name'] = $name;

        try {
            $this->types->save($body);
        } catch (ValidationException $e) {
            $this->response->error('validation_failed', $e->getMessage(), 422, $e->fields());
        }
        $this->cache->clear();
        (new Logger())->info('content_type.created', ['name' => $name, 'user_id' => $user['id'] ?? null]);
        $this->response->data($this->types->find($name), 201);
    }

    private function schemaName(mixed $value): string
    {
        $name = strtolower(trim((string) $value));
        $name = preg_replace('/[^a-z0-9_-]+/', '-', $name) ?? '';

        return trim($name, '-_');
    }

    public function contentTypeUpdate(string $name): never
    {
        $user = $this->requireToken('schema.update', ['type' => 'schema', 'name' => $name]);

        if (!$this->types->exists($name)) {
            $this->response->error('not_found', 'Content type not found.', 404);
        }

        $body = $this->http->requestJson();
        $body['name'] = $name;

        try {
            $this->types->save($body);
        } catch (ValidationException $e) {
            $this->response->error('validation_failed', $e->getMessage(), 422, $e->fields());
        }
        $schema = $this->types->find($name);
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
        $schema = $this->types->find($collection);

        $this->readPrincipal = ($schema['visibility'] ?? 'public') === 'private'
            ? $this->requireToken('content.read', ['type' => 'content', 'collection' => $collection])
            : $this->optionalTokenWithPermission('content.read', ['type' => 'content', 'collection' => $collection]);
        $admin = $this->readPrincipal !== null;
        $buildBody = function () use ($collection, $admin): array {
            $result = $this->content->query($collection, $_GET, $admin);
            $include = $this->includeFields();
            $locale = (string) ($_GET['locale'] ?? '');
            $result['data'] = array_map(fn(array $entry): array => $this->content->expandRelations($entry, $collection, $include, $locale, fn(array $target): bool => $this->canReadRelatedEntry($target)), $result['data']);

            return [
                'data' => array_values(array_map(fn(array $entry): array => $this->publicEntry($entry, $collection, $admin), $result['data'])),
                'meta' => $result['meta'],
            ];
        };

        $admin ? $this->http->json($buildBody()) : $this->publicCached($buildBody);
    }

    public function contentShow(string $collection, string $id): never
    {
        $this->requireCollection($collection);
        $schema = $this->types->find($collection);
        $user = null;
        $token = $this->bearerToken();

        if ($token === null && ($schema['visibility'] ?? 'public') === 'private') {
            $this->response->error('unauthorized', 'This content type requires a bearer token.', 401);
        }

        if ($token !== null) {
            $user = $this->tokens->findByToken($token);

            if ($user === null) {
                (new Logger())->warning('invalid api token');
                $this->response->error('unauthorized', 'Invalid bearer token.', 401);
            }
        }

        $locale = $this->content->locale((string) ($_GET['locale'] ?? ''));
        $entry = $this->content->findByIdentifier($collection, $id, $user !== null, $locale);

        if ($entry === null || ($user === null && !$this->content->isPubliclyVisible($entry, $locale))) {
            $this->response->error('not_found', 'Content entry not found.', 404);
        }

        if ($user !== null && !$this->permissions->allows($user, 'content.read', ['type' => 'content', 'collection' => $collection, 'entry' => $entry, 'principal' => $user, 'workspace' => $this->workspace->slug()])) {
            $this->response->error('forbidden', 'Forbidden.', 403);
        }

        $this->readPrincipal = $user;

        $entry = $this->content->resolveLocaleFields($entry, $locale, $user === null);

        $entry = $this->content->expandRelations($entry, $collection, $this->includeFields(), $locale, fn(array $target): bool => $this->canReadRelatedEntry($target));
        $body = ['data' => $this->publicEntry($entry, $collection, $user !== null)];
        $user ? $this->http->json($body) : $this->publicCached($body);
    }

    public function contentStore(string $collection): never
    {
        $this->requireCollection($collection);
        $body = $this->http->requestJson();
        $body['locale'] = $this->content->locale((string) ($body['locale'] ?? ''));
        $user = $this->requireToken('content.create', ['type' => 'content', 'collection' => $collection, 'fields' => $this->payloadFields($body), 'locale' => $body['locale']]);
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
        $body['locale'] = $this->content->locale((string) ($body['locale'] ?? ''));
        $existing = $this->content->findByIdentifier($collection, $id, true, $body['locale']);

        if ($existing === null) {
            $this->response->error('not_found', 'Content entry not found.', 404);
        }

        $existing = $this->content->resolveLocaleFields($existing, $body['locale']);
        try {
            $user = $this->requireToken('content.update', ['type' => 'content', 'collection' => $collection, 'entry' => $existing, 'fields' => $this->payloadFields($body), 'locale' => $body['locale']]);
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
        $entry = $this->content->findByIdentifier($collection, $id, true, (string) ($_GET['locale'] ?? ''));

        if ($entry === null) {
            $this->response->error('not_found', 'Content entry not found.', 404);
        }

        $user = $this->requireToken('content.delete', ['type' => 'content', 'collection' => $collection, 'entry' => $entry]);
        try {
            $this->content->softDelete($collection, (string) $entry['id'], $user);
        } catch (ValidationException $e) {
            $this->response->error('validation_failed', $e->getMessage(), 422, $e->fields());
        }
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
        try {
            $items = (new MediaUpload($this->media))->upload(MediaUpload::files($_FILES['media'] ?? null),
                (string) ($_POST['category'] ?? ''), (string) ($user['id'] ?? ''), (int) ($_SERVER['CONTENT_LENGTH'] ?? 0));
        } catch (UploadException $error) {
            $this->response->error($error->errorCode, $error->getMessage(), $error->status);
        }
        $this->http->json(['data' => array_map(fn(array $file): array => $this->publicMediaItem($file), $items), 'meta' => ['categories' => $this->media->categories()]], 201);
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
        $this->requireToken('media.update', $this->media->permissionContext(rawurldecode($file)));
        $body = $this->http->requestJson();
        $target = (string) ($body['category'] ?? '');
        if ($target !== '') {
            $this->requireToken('media.update', ['type' => 'media', 'category' => $target]);
        }
        $item = $this->media->assignCategory($file, $target);

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
        $this->requireToken('media.update', $this->media->permissionContext(rawurldecode($file)));
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
        $principal = $this->requireToken('media.update', ['type' => 'media']);
        $body = $this->http->requestJson();
        $files = (array) ($body['files'] ?? []);

        if ($files === []) {
            $this->response->error('validation_failed', 'Select at least one media file.', 422);
        }

        foreach ($files as $file) {
            $context = $this->media->permissionContext((string) $file) + ['principal' => $principal, 'workspace' => $this->workspace->slug()];
            if (!$this->permissions->allows($principal, 'media.update', $context)) {
                $this->response->error('forbidden', 'Forbidden.', 403);
            }
        }

        $items = $this->media->updateVisibilityForMany($files, (string) ($body['visibility'] ?? 'public'));
        $this->cache->clear();
        $this->http->json(['data' => array_map(fn(array $file): array => $this->publicMediaItem($file), $items)]);
    }

    public function mediaUpdateMeta(string $file): never
    {
        $this->requireToken('media.update', $this->media->permissionContext(rawurldecode($file)));
        $body = $this->http->requestJson();
        $item = $this->media->updateMeta($file, $body['alt'] ?? '', $body['title'] ?? '', $body['revision'] ?? null);

        if ($item === null) {
            $this->response->error('not_found', 'Media file not found.', 404);
        }

        $this->cache->clear();
        $this->response->data($this->publicMediaItem($item));
    }

    public function mediaDelete(string $file): never
    {
        $this->requireToken('media.delete', $this->media->permissionContext(rawurldecode($file)));
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

        $private = $this->media->isPrivate($file);
        if ($private) {
            $this->requirePrivateMediaAccess($file);
        }

        $size = $_GET['size'] ?? null;
        if (is_string($size) && $size !== '' && $size !== 'original') {
            $format = is_string($_GET['format'] ?? null) && $_GET['format'] !== '' ? strtolower($_GET['format']) : null;
            $format = $format === 'jpg' ? 'jpeg' : $format;
            $formats = $this->media->variantDescriptor($file)['formats'] ?? [];
            if (!in_array($size, \CometCMS\Media\MediaSizes::NAMES, true) || ($format !== null && !in_array($format, $formats, true))) {
                $this->response->error('invalid_image_variant', 'Choose one of the image sizes and an available format.', 422);
            }
            $variant = $this->media->sizeVariant($file, $size, $format);
            if ($variant !== null) {
                $this->streamMediaFile($variant['path'], $variant['mime'], $private);
            }
        }

        if (array_key_exists('w', $_GET) || array_key_exists('h', $_GET)) {
            try {
                $variant = $this->media->variant($file, [
                    'w' => $_GET['w'] ?? null,
                    'h' => $_GET['h'] ?? null,
                    'fit' => $_GET['fit'] ?? null,
                    'format' => $_GET['format'] ?? null,
                ]);
            } catch (\InvalidArgumentException $e) {
                $this->response->error('invalid_image_variant', $e->getMessage(), 422);
            }

            $this->streamMediaFile($variant['path'], $variant['mime'], $private);
        }

        $this->streamMediaFile($path, MimeDetector::detect($path), $private);
    }

    public function mediaThumbShow(string $file): never
    {
        $file = basename(rawurldecode($file));
        $originalPath = $this->media->filePath($file);

        if (!is_file($originalPath)) {
            $this->http->notFound();
        }

        $private = $this->media->isPrivate($file);
        if ($private) {
            $this->requirePrivateMediaAccess($file);
        }

        $path = $this->media->thumbnailPath($file) ?? $originalPath;
        $mime = MimeDetector::detect($path);

        $this->streamMediaFile($path, $mime, $private);
    }

    private function publicCached(array|callable $body): never
    {
        $key = $this->cache->key($this->http->path(), $_SERVER['QUERY_STRING'] ?? '');
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            header('X-CometCMS-Cache: HIT');
            $this->http->json($cached);
        }

        if (is_callable($body)) {
            $body = $body();
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
        if (!$this->types->exists($collection) || !ExternalReadPolicy::apiEnabled($this->types->find($collection))) {
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
            'locale' => $entry['locale'],
            'revision' => $entry['revision'],
            'status' => (string) ($entry['status'] ?? 'draft'),
            'title' => (string) ($entry['title'] ?? ''),
            // Hierarchical types: the parent's id ('' at the top level) and the order among siblings.
            'parent' => !empty($schema['hierarchical']) ? \CometCMS\Content\PageTree::parent($entry) : null,
            'position' => !empty($schema['hierarchical']) ? (int) ($entry['position'] ?? 0) : null,
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
        $languages = (new \CometCMS\Localization\LanguageSettings($this->workspace))->all();
        $locale = (new \CometCMS\Localization\LanguageSettings($this->workspace))->requireLocale((string) ($_GET['locale'] ?? ''));
        $file = \CometCMS\Localization\LocalizedValue::tree($file, $locale, $languages['default_locale']);
        $variantDescriptor = $this->media->variantDescriptor((string) $file['name']);
        $variants = null;

        if ($variantDescriptor !== null && $variantDescriptor['formats'] !== []) {
            $baseUrl = $this->absoluteUrl($this->mediaRoute('/media', (string) $file['name']));
            $format = (string) $variantDescriptor['default_format'];
            $variants = $variantDescriptor + [
                'urls' => array_combine(
                    array_map('strval', $variantDescriptor['widths']),
                    array_map(
                        static fn(int $width): string => $baseUrl . '?' . http_build_query(['w' => $width, 'format' => $format]),
                        $variantDescriptor['widths']
                    )
                ) ?: [],
            ];
        }

        $baseUrl = $this->absoluteUrl($this->mediaRoute('/media', (string) $file['name']));
        $sizes = [];
        foreach ($file['sizes'] ?? [] as $name => $dimensions) {
            $sizes[$name] = $dimensions + ['url' => $baseUrl . '?size=' . $name];
        }

        return [
            'filename' => $file['name'],
            'name' => $file['name'],
            'url' => $this->absoluteUrl($this->mediaRoute('/media', (string) $file['name'])),
            'thumb_url' => ($file['thumb'] ?? null) !== null
                ? $this->absoluteUrl($this->mediaRoute('/media', (string) $file['name'])) . '?size=thumbnail'
                : $this->absoluteUrl($this->mediaRoute('/media', (string) $file['name'])),
            'size' => $file['size'],
            'mime' => $file['mime'],
            'thumb' => $file['thumb'] ?? null,
            'category' => $file['category'],
            'uploaded_by' => $file['uploaded_by'] ?? null,
            'uploaded_at' => $file['uploaded_at'] ?? null,
            'width' => $file['width'] ?? null,
            'height' => $file['height'] ?? null,
            'revision' => $file['revision'],
            'alt' => $file['alt'] ?? '',
            'title' => $file['title'] ?? '',
            'visibility' => $file['visibility'] ?? 'public',
            'variants' => $variants,
            // Named sizes smaller than the original; a missing size is served by `url`.
            'sizes' => $sizes === [] ? new \stdClass() : $sizes,
        ];
    }

    private function streamMediaFile(string $path, string $mime, bool $private): never
    {
        $modified = filemtime($path) ?: time();
        $size = filesize($path) ?: 0;
        $etag = '"' . sha1($path . '|' . (string) $size . '|' . (string) $modified) . '"';
        $headers = [
            'Cache-Control' => $private ? 'private, no-store' : 'public, max-age=86400',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $modified) . ' GMT',
        ];

        // Media is served from the admin's origin. Opening an uploaded SVG (or
        // any other active document) directly must not run its scripts with the
        // viewer's session, so render it in a sandboxed, opaque origin. PDFs are
        // exempt: Chrome refuses to show its viewer in sandboxed documents, and
        // that viewer is already isolated from the page's origin.
        if ($mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox";
        }

        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        $ifModifiedSince = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if (!$private && ($ifNoneMatch === $etag || ($ifNoneMatch === '' && $ifModifiedSince !== false && $ifModifiedSince >= $modified))) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(304);
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
            exit;
        }

        $this->http->streamFile($path, $mime, $headers);
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
            if (isset($value['collection'], $value['id'])) return $this->publicValue($value, $admin);
            return array_values(array_filter(array_map(fn(mixed $item): mixed => $this->publicRelationValue($item, $target, $admin), $value), static fn(mixed $item): bool => $item !== null));
        }
        if ($target === '' || $value === null || $value === '') return $value;
        $locale = $this->content->locale((string) ($_GET['locale'] ?? ''));
        $entry = $this->content->findByIdentifier($target, (string) $value, false, $locale);
        if ($entry === null) return null;
        $entry = $this->content->resolveLocaleFields($entry, $locale, !$admin);
        return $this->canReadRelatedEntry($entry) ? $this->content->stableId($entry) : null;
    }

    private function publicValue(mixed $value, bool $admin): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['collection'], $value['id'])) {
            if (!$this->canReadRelatedEntry($value)) {
                return null;
            }

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

    private function canReadRelatedEntry(array $entry): bool
    {
        return ExternalReadPolicy::allowsEntry($entry, $this->content, $this->types, $this->workspace, $this->readPrincipal);
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

    private function tokenPrincipal(): ?array
    {
        $token = $this->bearerToken();

        if ($token === null) {
            return null;
        }

        $principal = $this->tokens->findByToken($token);
        if ($principal === null) {
            (new Logger())->warning('invalid api token');
            $this->response->error('unauthorized', 'Invalid bearer token.', 401);
        }

        return $principal;
    }

    /**
     * Private files are readable with an access token, or by a signed-in admin
     * user — otherwise the admin's own media library could not preview them.
     */
    private function requirePrivateMediaAccess(string $file): void
    {
        $context = $this->media->permissionContext($file);

        if ($this->bearerToken() === null) {
            $user = $this->sessionUser();
            if ($user !== null && $this->permissions->allows($user, 'media.read', $context + ['principal' => $user, 'workspace' => $this->workspace->slug()])) {
                return;
            }
        }

        $this->requireToken('media.read', $context);
    }

    /** The admin user of this browser session, read without holding the session lock. */
    private function sessionUser(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!isset($_COOKIE[session_name()]) || !is_string($_COOKIE[session_name()])) {
                return null;
            }

            if (!@session_start(['read_and_close' => true])) {
                return null;
            }
        }

        return (new Auth(new UserRepository()))->user();
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
        return array_values(array_unique(array_merge((array) ($payload['reset_fields'] ?? []), array_diff(array_keys($payload), ['revision', 'reset_fields', 'id', 'uid', 'collection', 'created_at', 'updated_at', 'deleted_at', 'deleted_by', 'updated_by', 'values', 'publication', 'revision', 'reset_fields', 'locale']))));
    }

    private function requirePublishToken(array $user, string $collection, ?array $entry, array $payload): void
    {
        if (($payload['status'] ?? null) !== 'published') {
            return;
        }

        if ($this->permissions->allows($user, 'content.publish', ['type' => 'content', 'collection' => $collection, 'entry' => $entry ?? [], 'principal' => $user, 'workspace' => $this->workspace->slug(), 'locale' => $payload['locale'] ?? $this->content->locale()])) {
            return;
        }

        $this->response->error('forbidden', 'Forbidden.', 403);
    }
}
