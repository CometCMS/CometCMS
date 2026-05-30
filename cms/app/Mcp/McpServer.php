<?php

declare(strict_types=1);

namespace CometCMS\Mcp;

use CometCMS\Auth\ApiTokenRepository;
use CometCMS\Auth\PermissionService;
use CometCMS\Cache\ApiCache;
use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\Http;
use CometCMS\Core\Security;
use CometCMS\Core\ValidationException;
use CometCMS\Logging\Logger;
use CometCMS\Media\MediaRepository;
use CometCMS\Workspaces\WorkspaceContext;
use CometCMS\Workspaces\WorkspaceRepository;

final class McpServer
{
    private const PROTOCOL_VERSION = '2025-06-18';
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const SYSTEM_FIELDS = ['id', 'uid', 'collection', 'created_at', 'updated_at', 'deleted_at', 'deleted_by', 'updated_by', 'translations', 'locale'];

    private ApiTokenRepository $tokens;
    private PermissionService $permissions;
    private ContentRepository $content;
    private ContentTypeRepository $types;
    private MediaRepository $media;
    private ApiCache $cache;
    private WorkspaceContext $workspace;
    private array $principal = [];

    public function __construct(private readonly Http $http = new Http()) {}

    public function handleRaw(string $raw, string $workspace, ?string $token): array
    {
        try {
            $request = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [$this->jsonRpcError(null, -32700, 'Parse error.'), 400];
        }

        return $this->handle($request, $workspace, $token);
    }

    public function handle(mixed $request, string $workspace, ?string $token): array
    {
        $id = is_array($request) && array_key_exists('id', $request) ? $request['id'] : null;

        if (!$this->isRequest($request)) {
            return [$this->jsonRpcError($id, -32600, 'Invalid Request.'), 400];
        }

        try {
            $this->boot($workspace, $token);
            $result = $this->dispatch((string) $request['method'], is_array($request['params'] ?? null) ? $request['params'] : []);
        } catch (McpError $e) {
            return [$this->jsonRpcError($id, -32000, $e->getMessage(), [
                'status' => $e->status(),
                'details' => $e->details(),
                'required_permissions' => $e->requiredPermissions(),
                'recovery' => $e->recovery(),
            ]), $e->status()];
        } catch (\Throwable $e) {
            return [$this->jsonRpcError($id, -32603, 'Internal error.', [
                'message' => (bool) $this->config('app.debug', false) ? $e->getMessage() : 'An internal MCP error occurred.',
            ]), 500];
        }

        if (!array_key_exists('id', $request)) {
            return [null, 202];
        }

        return [[
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], 200];
    }

    private function boot(string $workspace, ?string $token): void
    {
        $workspace = Security::slug($workspace);
        $registry = new WorkspaceRepository();

        if (!$registry->exists($workspace)) {
            throw new McpError('Workspace not found.', 404, ['code' => 'not_found']);
        }

        if ($token === null || trim($token) === '') {
            throw new McpError('Missing bearer token.', 401, ['code' => 'unauthorized'], [], [
                'Create an API token in CometCMS and send it as Authorization: Bearer <token>.',
            ]);
        }

        WorkspaceContext::setActive($workspace);
        $this->workspace = WorkspaceContext::active();
        $this->tokens = new ApiTokenRepository();
        $this->permissions = new PermissionService();
        $principal = $this->tokens->findByToken($token);

        if ($principal === null) {
            (new Logger())->warning('invalid mcp api token');
            throw new McpError('Invalid bearer token.', 401, ['code' => 'unauthorized'], [], [
                'Check the API token and make sure it has not been revoked.',
            ]);
        }

        $this->principal = $principal;
        $this->types = new ContentTypeRepository($this->workspace);
        $this->content = ContentRepository::make($this->workspace);
        $this->media = new MediaRepository($this->workspace);
        $this->cache = ApiCache::fromConfig($this->workspace);
    }

    private function dispatch(string $method, array $params): array
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'notifications/initialized', 'ping' => [],
            'tools/list' => ['tools' => $this->tools()],
            'tools/call' => $this->callTool($params),
            default => throw new McpError('Method not found.', 404, ['method' => $method]),
        };
    }

    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? self::PROTOCOL_VERSION);

        return [
            'protocolVersion' => $requested !== '' ? $requested : self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => 'cometcms',
                'version' => $this->version(),
                'icon' => $this->absoluteUrl('/admin/img/cms-icon.png'),
            ],
            'instructions' => implode(' ', [
                'Use CometCMS tools to inspect schemas, manage entries, and work with media.',
                'Content type updates are surgical by default: omitted label, icon, locales, default_locale, and fields are preserved.',
                'When updating content type fields, pass only the fields to add or change; use remove_fields to delete fields, or replace_fields for a full field map replacement.',
                'For localized entries, include locale in create_entry or update_entry only when targeting a specific translation; otherwise the CMS default locale is used.',
            ]),
        ];
    }

    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($name === '' || !array_key_exists($name, $this->toolCallbacks())) {
            throw new McpError('Unknown tool.', 404, ['tool' => $name]);
        }

        try {
            $callback = $this->toolCallbacks()[$name];
            return $this->textResult($callback($args));
        } catch (McpError $e) {
            return $this->textResult([
                'error' => [
                    'message' => $e->getMessage(),
                    'status' => $e->status(),
                    'details' => $e->details(),
                    'required_permissions' => $e->requiredPermissions(),
                    'recovery' => $this->recovery($e),
                ],
            ], true);
        } catch (ValidationException $e) {
            return $this->textResult([
                'error' => [
                    'message' => $e->getMessage(),
                    'status' => 422,
                    'details' => ['code' => 'validation_failed', 'fields' => $e->fields()],
                    'required_permissions' => $this->requiredPermissionsForTool($name, $args),
                    'recovery' => ['Validation failed. Check error.details.fields and compare against get_content_type for allowed fields, field types, locales, and required values.'],
                ],
            ], true);
        } catch (\Throwable $e) {
            return $this->textResult([
                'error' => [
                    'message' => (bool) $this->config('app.debug', false) ? $e->getMessage() : 'Tool execution failed.',
                    'status' => 500,
                    'details' => null,
                    'required_permissions' => $this->requiredPermissionsForTool($name, $args),
                    'recovery' => [],
                ],
            ], true);
        }
    }

    private function toolCallbacks(): array
    {
        return [
            'comet_health' => fn(array $args): array => $this->health(),
            'list_content_types' => fn(array $args): array => $this->listContentTypes(),
            'get_content_type' => fn(array $args): array => $this->getContentType($args),
            'create_content_type' => fn(array $args): array => $this->createContentType($args),
            'update_content_type' => fn(array $args): array => $this->updateContentType($args),
            'delete_content_type' => fn(array $args): array => $this->deleteContentType($args),
            'list_entries' => fn(array $args): array => $this->listEntries($args),
            'get_entry' => fn(array $args): array => $this->getEntry($args),
            'create_entry' => fn(array $args): array => $this->createEntry($args),
            'update_entry' => fn(array $args): array => $this->updateEntry($args),
            'delete_entry' => fn(array $args): array => $this->deleteEntry($args),
            'list_media' => fn(array $args): array => $this->listMedia($args),
            'create_media_category' => fn(array $args): array => $this->createMediaCategory($args),
            'set_media_category' => fn(array $args): array => $this->setMediaCategory($args),
            'delete_media' => fn(array $args): array => $this->deleteMedia($args),
        ];
    }

    private function health(): array
    {
        return [
            'data' => [
                'ok' => true,
                'name' => $this->config('app.name', 'CometCMS'),
                'version' => $this->version(),
                'time' => Security::now(),
                'extensions' => [
                    'gd' => extension_loaded('gd')
                        && function_exists('imagecreatetruecolor')
                        && function_exists('imagecopyresampled')
                        && function_exists('imagejpeg'),
                    'zip' => class_exists(\ZipArchive::class) || extension_loaded('zip'),
                ],
            ],
        ];
    }

    private function listContentTypes(): array
    {
        $this->requirePermission('list_content_types', []);
        return ['data' => $this->types->all()];
    }

    private function getContentType(array $args): array
    {
        $collection = $this->segment($args['collection'] ?? '', 'collection');
        $this->requirePermission('get_content_type', ['collection' => $collection]);
        $this->requireCollection($collection, 'Content type not found.');

        return ['data' => $this->types->find($collection)];
    }

    private function createContentType(array $args): array
    {
        $name = $this->segment($args['name'] ?? '', 'name');
        $this->requirePermission('create_content_type', ['name' => $name]);

        $body = ['name' => $name];
        foreach (['label', 'icon', 'fields', 'locales', 'default_locale', 'singleton'] as $key) {
            if (array_key_exists($key, $args)) {
                $body[$key] = $args[$key];
            }
        }

        if (!empty($body['singleton']) && count($this->content->all($name)) > 1) {
            throw new McpError('Single page content types can only be enabled when there is at most one active entry.', 422, ['code' => 'validation_failed']);
        }

        $this->types->save($body);
        $this->cache->clear();
        (new Logger())->info('content_type.created', ['name' => $name, 'user_id' => $this->principal['id'] ?? null]);

        return ['data' => $this->types->find($name)];
    }

    private function updateContentType(array $args): array
    {
        $collection = $this->segment($args['collection'] ?? '', 'collection');
        $this->requirePermission('update_content_type', ['collection' => $collection]);
        $this->requireCollection($collection, 'Content type not found.');

        $current = $this->types->find($collection);
        $body = $this->mergeContentTypeSchema($current, $args + ['collection' => $collection]);

        if (!empty($body['singleton']) && count($this->content->all($collection)) > 1) {
            throw new McpError('Single page content types can only be enabled when there is at most one active entry.', 422, ['code' => 'validation_failed']);
        }

        $this->types->save($body);
        $schema = $this->types->find($collection);
        $this->content->syncLocalization($collection, $schema);
        $this->cache->clear();
        (new Logger())->info('content_type.updated', ['name' => $collection, 'user_id' => $this->principal['id'] ?? null]);

        return ['data' => $schema];
    }

    private function deleteContentType(array $args): array
    {
        $collection = $this->segment($args['collection'] ?? '', 'collection');
        $this->requirePermission('delete_content_type', ['collection' => $collection]);
        $this->requireCollection($collection, 'Content type not found.');
        $this->types->delete($collection);
        $this->cache->clear();
        (new Logger())->info('content_type.deleted', ['name' => $collection, 'user_id' => $this->principal['id'] ?? null]);

        return ['data' => ['ok' => true]];
    }

    private function listEntries(array $args): array
    {
        $collection = $this->segment($args['collection'] ?? '', 'collection');
        $this->requireCollection($collection, 'Content collection not found.');
        $this->requirePermission('list_entries', ['collection' => $collection]);

        $params = [
            'q' => (string) ($args['q'] ?? ''),
            'sort' => (string) ($args['sort'] ?? '-created_at'),
            'include' => (string) ($args['include'] ?? ''),
            'locale' => (string) ($args['locale'] ?? ''),
            'limit' => $this->limit($args['limit'] ?? self::DEFAULT_LIMIT),
            'offset' => max(0, (int) ($args['offset'] ?? 0)),
            'filter' => $this->normalizeFilters($args['filters'] ?? []),
        ];

        $result = $this->content->query($collection, $params, true);
        $include = $this->includeFields($params['include']);
        $locale = $params['locale'];
        $result['data'] = array_map(fn(array $entry): array => $this->content->expandRelations($entry, $collection, $include, $locale), $result['data']);
        $result['data'] = array_values(array_map(fn(array $entry): array => $this->publicEntry($entry, $collection, true), $result['data']));

        return $this->pageInfo($result);
    }

    private function getEntry(array $args): array
    {
        $collection = $this->segment($args['collection'] ?? '', 'collection');
        $identifier = $this->segment($args['identifier'] ?? '', 'identifier');
        $this->requireCollection($collection, 'Content collection not found.');

        $entry = $this->content->findByIdentifier($collection, $identifier, true);
        if ($entry === null) {
            throw new McpError('Content entry not found.', 404, ['code' => 'not_found']);
        }

        $this->requirePermission('get_entry', ['collection' => $collection, 'identifier' => $identifier, 'entry' => $entry]);
        $locale = (string) ($args['locale'] ?? '');
        $schema = $this->types->find($collection);
        $locales = is_array($schema['locales'] ?? null) ? $schema['locales'] : [];

        if ($locale !== '' && in_array($locale, $locales, true)) {
            $entry = $this->content->resolveLocaleFields($entry, $locale);
        }

        $entry = $this->content->expandRelations($entry, $collection, $this->includeFields((string) ($args['include'] ?? '')), $locale);

        return ['data' => $this->publicEntry($entry, $collection, true)];
    }

    private function createEntry(array $args): array
    {
        $collection = $this->segment($args['collection'] ?? '', 'collection');
        $entry = is_array($args['entry'] ?? null) ? $args['entry'] : [];
        $this->requireCollection($collection, 'Content collection not found.');
        $this->requirePermission('create_entry', ['collection' => $collection, 'entry_payload' => $entry]);
        $this->requirePublishPermission($collection, null, $entry);

        $saved = $this->content->save($collection, $entry, $this->principal, null, true);

        return ['data' => $this->publicEntry($saved, $collection, true)];
    }

    private function updateEntry(array $args): array
    {
        $collection = $this->segment($args['collection'] ?? '', 'collection');
        $identifier = $this->segment($args['identifier'] ?? '', 'identifier');
        $entry = is_array($args['entry'] ?? null) ? $args['entry'] : [];
        $this->requireCollection($collection, 'Content collection not found.');
        $existing = $this->content->findByIdentifier($collection, $identifier, true);

        if ($existing === null) {
            throw new McpError('Content entry not found.', 404, ['code' => 'not_found']);
        }

        $this->requirePermission('update_entry', ['collection' => $collection, 'identifier' => $identifier, 'entry' => $existing, 'entry_payload' => $entry]);
        $this->requirePublishPermission($collection, $existing, $entry);
        $saved = $this->content->save($collection, $entry, $this->principal, (string) $existing['id'], true);

        return ['data' => $this->publicEntry($saved, $collection, true)];
    }

    private function deleteEntry(array $args): array
    {
        $collection = $this->segment($args['collection'] ?? '', 'collection');
        $identifier = $this->segment($args['identifier'] ?? '', 'identifier');
        $this->requireCollection($collection, 'Content collection not found.');
        $entry = $this->content->findByIdentifier($collection, $identifier, true);

        if ($entry === null) {
            throw new McpError('Content entry not found.', 404, ['code' => 'not_found']);
        }

        $this->requirePermission('delete_entry', ['collection' => $collection, 'identifier' => $identifier, 'entry' => $entry]);
        $this->content->softDelete($collection, (string) $entry['id'], $this->principal);

        return ['data' => ['ok' => true]];
    }

    private function listMedia(array $args): array
    {
        $this->requirePermission('list_media', []);
        $result = $this->media->limitedFiles(
            (string) ($args['q'] ?? ''),
            array_key_exists('category', $args) ? (string) $args['category'] : null,
            $this->limit($args['limit'] ?? self::DEFAULT_LIMIT),
            max(0, (int) ($args['offset'] ?? 0)),
        );

        $result['data'] = array_map(fn(array $file): array => $this->publicMediaItem($file), $result['data']);
        $result['meta']['categories'] = $this->media->categories();

        return $this->pageInfo($result);
    }

    private function createMediaCategory(array $args): array
    {
        $this->requirePermission('create_media_category', []);
        $name = trim((string) ($args['name'] ?? ''));
        $parent = trim((string) ($args['parent'] ?? ''));

        try {
            $categories = $this->media->addCategory($name, $parent);
        } catch (\InvalidArgumentException $e) {
            throw new McpError($e->getMessage(), 422, ['code' => 'validation_failed'], $this->requiredPermissionsForTool('create_media_category', $args));
        }

        $this->cache->clear();
        return [
            'data' => ['name' => $this->matchingCategory($parent === '' ? $name : $parent . ' / ' . $name, $categories)],
            'meta' => ['categories' => $categories],
        ];
    }

    private function setMediaCategory(array $args): array
    {
        $filename = trim((string) ($args['filename'] ?? ''));
        $this->requirePermission('set_media_category', ['filename' => $filename]);
        $item = $this->media->assignCategory($filename, (string) ($args['category'] ?? ''));

        if ($item === null) {
            throw new McpError('Media file not found.', 404, ['code' => 'not_found']);
        }

        $this->cache->clear();
        return [
            'data' => $this->publicMediaItem($item),
            'meta' => ['categories' => $this->media->categories()],
        ];
    }

    private function deleteMedia(array $args): array
    {
        $filename = trim((string) ($args['filename'] ?? ''));
        $this->requirePermission('delete_media', ['filename' => $filename]);
        $this->media->delete($filename);
        $this->cache->clear();

        return ['data' => ['ok' => true]];
    }

    private function tools(): array
    {
        $schemas = $this->toolSchemas();
        return array_values(array_map(static fn(string $name, array $schema): array => [
            'name' => $name,
            'title' => $schema['title'],
            'description' => $schema['description'],
            'inputSchema' => $schema['inputSchema'],
        ], array_keys($schemas), $schemas));
    }

    private function toolSchemas(): array
    {
        $string = ['type' => 'string'];
        $optionalString = ['type' => 'string'];
        $pagination = [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ];
        $object = ['type' => 'object', 'additionalProperties' => true];

        return [
            'comet_health' => ['title' => 'CometCMS health', 'description' => 'Check the configured CometCMS public API health endpoint.', 'inputSchema' => $this->schema([])],
            'list_content_types' => ['title' => 'List content types', 'description' => 'List CometCMS content type schemas.', 'inputSchema' => $this->schema([])],
            'get_content_type' => ['title' => 'Get content type', 'description' => 'Fetch a single CometCMS content type schema.', 'inputSchema' => $this->schema(['collection' => $string], ['collection'])],
            'create_content_type' => ['title' => 'Create content type', 'description' => 'Create a new content type schema. Requires schema.create permission.', 'inputSchema' => $this->schema(['name' => $string, 'label' => $string, 'icon' => $string, 'fields' => $object, 'locales' => ['type' => 'array', 'items' => $string], 'default_locale' => $string, 'singleton' => ['type' => 'boolean']], ['name'])],
            'update_content_type' => ['title' => 'Update content type', 'description' => 'Surgically update an existing content type schema. Omitted schema parts are preserved.', 'inputSchema' => $this->schema(['collection' => $string, 'label' => $string, 'icon' => $string, 'fields' => $object, 'remove_fields' => ['type' => 'array', 'items' => $string], 'replace_fields' => ['type' => 'boolean'], 'locales' => ['type' => 'array', 'items' => $string], 'default_locale' => $string, 'singleton' => ['type' => 'boolean']], ['collection'])],
            'delete_content_type' => ['title' => 'Delete content type', 'description' => 'Permanently delete a content type and all its entries.', 'inputSchema' => $this->schema(['collection' => $string], ['collection'])],
            'list_entries' => ['title' => 'List content entries', 'description' => 'Fetch one paginated page of entries.', 'inputSchema' => $this->schema(['collection' => $string, 'q' => $optionalString, 'sort' => $optionalString, 'include' => $optionalString, 'locale' => $optionalString, 'filters' => $object] + $pagination, ['collection'])],
            'get_entry' => ['title' => 'Get content entry', 'description' => 'Fetch one entry by stable id or slug.', 'inputSchema' => $this->schema(['collection' => $string, 'identifier' => $string, 'include' => $optionalString, 'locale' => $optionalString], ['collection', 'identifier'])],
            'create_entry' => ['title' => 'Create content entry', 'description' => 'Create a content entry.', 'inputSchema' => $this->schema(['collection' => $string, 'entry' => $object], ['collection', 'entry'])],
            'update_entry' => ['title' => 'Update content entry', 'description' => 'Update a content entry by stable id or slug.', 'inputSchema' => $this->schema(['collection' => $string, 'identifier' => $string, 'entry' => $object], ['collection', 'identifier', 'entry'])],
            'delete_entry' => ['title' => 'Delete content entry', 'description' => 'Soft-delete a content entry by stable id or slug.', 'inputSchema' => $this->schema(['collection' => $string, 'identifier' => $string], ['collection', 'identifier'])],
            'list_media' => ['title' => 'List media', 'description' => 'Fetch one paginated page of media files.', 'inputSchema' => $this->schema(['q' => $optionalString, 'category' => $optionalString] + $pagination)],
            'create_media_category' => ['title' => 'Create media category', 'description' => 'Create a media category or subcategory.', 'inputSchema' => $this->schema(['name' => $string, 'parent' => $optionalString], ['name'])],
            'set_media_category' => ['title' => 'Set media category', 'description' => 'Assign or clear a media file category.', 'inputSchema' => $this->schema(['filename' => $string, 'category' => $optionalString], ['filename'])],
            'delete_media' => ['title' => 'Delete media', 'description' => 'Delete a media file.', 'inputSchema' => $this->schema(['filename' => $string], ['filename'])],
        ];
    }

    private function schema(array $properties, array $required = []): array
    {
        return array_filter([
            'type' => 'object',
            'properties' => $properties === [] ? (object) [] : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ], static fn(mixed $value): bool => $value !== [] || $value === $required);
    }

    private function requirePermission(string $tool, array $args): void
    {
        foreach ($this->permissionChecks($tool, $args) as [$action, $context]) {
            $context['principal'] = $this->principal;
            $context['workspace'] ??= $this->workspace->slug();

            if (!$this->permissions->allows($this->principal, $action, $context)) {
                throw new McpError('Forbidden.', 403, ['code' => 'forbidden'], $this->requiredPermissionsForTool($tool, $args), [
                    'The token is valid but lacks permission. Update the token in API-Tokens or use a token that already has the required grant.',
                ]);
            }
        }
    }

    private function requirePublishPermission(string $collection, ?array $entry, array $payload): void
    {
        if (($payload['status'] ?? null) !== 'published') {
            return;
        }

        $context = ['type' => 'content', 'collection' => $collection, 'entry' => $entry ?? [], 'principal' => $this->principal, 'workspace' => $this->workspace->slug()];
        if (!$this->permissions->allows($this->principal, 'content.publish', $context)) {
            throw new McpError('Forbidden.', 403, ['code' => 'forbidden'], [permissionGrant('content.publish', $this->workspace->slug(), $this->contentResource(['collection' => $collection], $entry !== null))]);
        }
    }

    private function permissionChecks(string $tool, array $args): array
    {
        return match ($tool) {
            'list_content_types' => [['schema.read', ['type' => 'schema', 'name' => '*']]],
            'get_content_type' => [['schema.read', ['type' => 'schema', 'name' => (string) ($args['collection'] ?? '*')]]],
            'create_content_type' => [['schema.create', ['type' => 'schema', 'name' => (string) ($args['name'] ?? '*')]]],
            'update_content_type' => [['schema.update', ['type' => 'schema', 'name' => (string) ($args['collection'] ?? '*')]]],
            'delete_content_type' => [['schema.delete', ['type' => 'schema', 'name' => (string) ($args['collection'] ?? '*')]]],
            'list_entries' => [['content.read', ['type' => 'content', 'collection' => (string) ($args['collection'] ?? '*')]]],
            'get_entry' => [['content.read', ['type' => 'content', 'collection' => (string) ($args['collection'] ?? '*'), 'entry' => is_array($args['entry'] ?? null) ? $args['entry'] : []]]],
            'create_entry' => [['content.create', ['type' => 'content', 'collection' => (string) ($args['collection'] ?? '*'), 'fields' => $this->changedEntryFields($args)]]],
            'update_entry' => [['content.update', ['type' => 'content', 'collection' => (string) ($args['collection'] ?? '*'), 'entry' => is_array($args['entry'] ?? null) ? $args['entry'] : [], 'fields' => $this->changedEntryFields($args)]]],
            'delete_entry' => [['content.delete', ['type' => 'content', 'collection' => (string) ($args['collection'] ?? '*'), 'entry' => is_array($args['entry'] ?? null) ? $args['entry'] : []]]],
            'list_media' => [['media.read', ['type' => 'media']]],
            'create_media_category' => [['media.update', ['type' => 'media']]],
            'set_media_category' => [['media.update', ['type' => 'media', 'file' => (string) ($args['filename'] ?? '*')]]],
            'delete_media' => [['media.delete', ['type' => 'media', 'file' => (string) ($args['filename'] ?? '*')]]],
            default => [],
        };
    }

    private function requiredPermissionsForTool(string $tool, array $args): array
    {
        $workspace = $this->workspace->slug();

        return match ($tool) {
            'list_content_types' => [permissionGrant('schema.read', $workspace, 'schema:*')],
            'get_content_type' => [permissionGrant('schema.read', $workspace, $this->schemaResource($args))],
            'create_content_type' => [permissionGrant('schema.create', $workspace, $this->schemaResource($args))],
            'update_content_type' => [permissionGrant('schema.update', $workspace, $this->schemaResource($args))],
            'delete_content_type' => [permissionGrant('schema.delete', $workspace, $this->schemaResource($args))],
            'list_entries' => [permissionGrant('content.read', $workspace, $this->contentResource($args))],
            'get_entry' => [permissionGrant('content.read', $workspace, $this->contentResource($args, true))],
            'create_entry' => [permissionGrant('content.create', $workspace, $this->contentResource($args), $this->changedEntryFields($args))],
            'update_entry' => [permissionGrant('content.update', $workspace, $this->contentResource($args, true), $this->changedEntryFields($args))],
            'delete_entry' => [permissionGrant('content.delete', $workspace, $this->contentResource($args, true))],
            'list_media' => [permissionGrant('media.read', $workspace, 'media:*')],
            'create_media_category' => [permissionGrant('media.update', $workspace, 'media:*')],
            'set_media_category' => [permissionGrant('media.update', $workspace, isset($args['filename']) ? 'media:' . trim((string) $args['filename']) : 'media:*')],
            'delete_media' => [permissionGrant('media.delete', $workspace, $this->mediaResource($args))],
            default => [],
        };
    }

    private function recovery(McpError $error): array
    {
        $recovery = $error->recovery();

        if ($error->status() === 401) {
            $recovery[] = 'Check the bearer API token and make sure it has permission grants for the operation.';
        } elseif ($error->status() === 403) {
            $recovery[] = 'The token is valid but lacks permission. Update the token in API-Tokens or use a token that already has the required grant.';
        } elseif ($error->status() === 404) {
            $recovery[] = 'Verify the collection, identifier, or filename. Use list_content_types, list_entries, or list_media to see available options.';
        } elseif ($error->status() === 422) {
            $recovery[] = 'Validation failed. Check error.details for field-specific messages and compare against get_content_type for allowed fields, field types, locales, and required values.';
        }

        return array_values(array_unique($recovery));
    }

    private function mergeContentTypeSchema(array $current, array $updates): array
    {
        $currentFields = is_array($current['fields'] ?? null) ? $current['fields'] : [];
        $body = [
            'name' => (string) ($updates['collection'] ?? $current['name'] ?? ''),
            'label' => $updates['label'] ?? $current['label'] ?? null,
            'icon' => $updates['icon'] ?? $current['icon'] ?? null,
            'singleton' => array_key_exists('singleton', $updates) ? (bool) $updates['singleton'] : (bool) ($current['singleton'] ?? false),
            'slug_field' => $current['slug_field'] ?? null,
            'slug_source' => $current['slug_source'] ?? null,
            'locales' => array_key_exists('locales', $updates) ? $updates['locales'] : (is_array($current['locales'] ?? null) ? $current['locales'] : []),
            'default_locale' => $updates['default_locale'] ?? $current['default_locale'] ?? null,
            'fields' => !empty($updates['replace_fields'])
                ? (is_array($updates['fields'] ?? null) ? $updates['fields'] : [])
                : array_replace($currentFields, is_array($updates['fields'] ?? null) ? $updates['fields'] : []),
        ];

        foreach (is_array($updates['remove_fields'] ?? null) ? $updates['remove_fields'] : [] as $field) {
            unset($body['fields'][(string) $field]);
        }

        return array_filter($body, static fn(mixed $value): bool => $value !== null);
    }

    private function publicEntry(array $entry, string $collection, bool $admin): array
    {
        $schema = $this->types->find($collection);
        $fields = [];

        foreach (is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field => $config) {
            if (in_array($field, ['title', 'slug'], true)) {
                continue;
            }

            if (array_key_exists($field, $entry)) {
                $fields[$field] = $this->publicValue($entry[$field], is_array($config) ? $config : [], $admin);
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

    private function publicValue(mixed $value, array $config, bool $admin): mixed
    {
        if (($config['type'] ?? '') === 'media') {
            if (!is_array($value)) {
                return [];
            }

            return array_values(array_filter(array_map(fn(mixed $file): ?string => $this->mediaUrl((string) $file), $value)));
        }

        if (($config['type'] ?? '') === 'repeater') {
            return is_array($value) ? $value : [];
        }

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
            $mapped[$key] = $item;
        }

        return array_is_list($mapped) ? array_values(array_filter($mapped, static fn(mixed $item): bool => $item !== null)) : $mapped;
    }

    private function publicMediaItem(array $file): array
    {
        return [
            'filename' => $file['name'],
            'name' => $file['name'],
            'url' => $this->mediaUrl((string) $file['name']),
            'thumb_url' => ($file['thumb'] ?? null) !== null
                ? $this->absoluteUrl('/media-thumbs/' . rawurlencode($this->workspace->slug()) . '/' . rawurlencode((string) $file['name']))
                : $this->mediaUrl((string) $file['name']),
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

    private function textResult(mixed $value, bool $isError = false): array
    {
        return array_filter([
            'content' => [[
                'type' => 'text',
                'text' => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
            'isError' => $isError ?: null,
        ], static fn(mixed $item): bool => $item !== null);
    }

    private function jsonRpcError(mixed $id, int $code, string $message, mixed $data = null): array
    {
        return array_filter([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'data' => $data,
            ], static fn(mixed $value): bool => $value !== null),
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function isRequest(mixed $request): bool
    {
        return is_array($request)
            && ($request['jsonrpc'] ?? null) === '2.0'
            && is_string($request['method'] ?? null);
    }

    private function segment(mixed $value, string $label): string
    {
        $text = trim((string) $value);

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
            throw new McpError($label . ' may only contain letters, numbers, underscores, and dashes.', 422, ['code' => 'validation_failed']);
        }

        return $text;
    }

    private function requireCollection(string $collection, string $message): void
    {
        if (!$this->types->exists($collection)) {
            throw new McpError($message, 404, ['code' => 'not_found']);
        }
    }

    private function limit(mixed $value): int
    {
        return max(1, min(self::MAX_LIMIT, (int) $value));
    }

    private function normalizeFilters(mixed $filters): array
    {
        return is_array($filters) ? $filters : [];
    }

    private function includeFields(string $include): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $include))));
    }

    private function pageInfo(array $payload): array
    {
        $meta = $payload['meta'] ?? null;
        if (!is_array($meta) || !isset($meta['total'], $meta['offset'], $meta['limit'])) {
            return $payload;
        }

        $nextOffset = (int) $meta['offset'] + (int) $meta['limit'];
        $payload['meta']['next_offset'] = $nextOffset < (int) $meta['total'] ? $nextOffset : null;

        return $payload;
    }

    private function changedEntryFields(array $args): array
    {
        $entry = is_array($args['entry_payload'] ?? null)
            ? $args['entry_payload']
            : (is_array($args['entry'] ?? null) ? $args['entry'] : []);

        return array_values(array_diff(array_keys($entry), self::SYSTEM_FIELDS));
    }

    private function contentResource(array $args, bool $includeEntry = false): string
    {
        $collection = trim((string) ($args['collection'] ?? '*')) ?: '*';
        $identifier = $includeEntry ? (trim((string) ($args['identifier'] ?? '*')) ?: '*') : '*';

        return 'content:' . $collection . ':' . $identifier;
    }

    private function schemaResource(array $args): string
    {
        $collection = trim((string) ($args['collection'] ?? $args['name'] ?? '*')) ?: '*';

        return 'schema:' . $collection;
    }

    private function mediaResource(array $args): string
    {
        if (!empty($args['category'])) {
            return 'media:category:' . trim((string) $args['category']);
        }

        if (!empty($args['filename'])) {
            return 'media:' . trim((string) $args['filename']);
        }

        return 'media:*';
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

    private function mediaUrl(string $filename): ?string
    {
        $filename = basename(trim($filename));

        return $filename === '' ? null : $this->absoluteUrl('/media/' . rawurlencode($this->workspace->slug()) . '/' . rawurlencode($filename));
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

    private function config(string $key, mixed $default = null): mixed
    {
        return function_exists('comet_config') ? \comet_config($key, $default) : $default;
    }

    private function version(): string
    {
        return function_exists('comet_version') ? \comet_version() : (string) $this->config('app.version', '1.0.0');
    }
}

function permissionGrant(string|array $actions, string $workspace, string|array $resources, array $fields = []): array
{
    $scopedResources = array_map(
        static fn(string $resource): string => $resource === '*' ? $resource : 'workspace:' . $workspace . ':' . $resource,
        array_map('strval', (array) $resources)
    );
    $grant = [
        'effect' => 'allow',
        'actions' => array_values(array_map('strval', (array) $actions)),
        'resources' => array_values($scopedResources),
    ];

    if ($fields !== []) {
        $grant['fields'] = array_values($fields);
    }

    return $grant;
}
