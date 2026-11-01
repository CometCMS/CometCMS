<?php

declare(strict_types=1);

namespace CometCMS\Mcp;

use CometCMS\Auth\ApiTokenRepository;
use CometCMS\Auth\PermissionService;
use CometCMS\Cache\ApiCache;
use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Content\ExternalReadPolicy;
use CometCMS\Core\Http;
use CometCMS\Core\Security;
use CometCMS\Core\ValidationException;
use CometCMS\Logging\Logger;
use CometCMS\Media\MediaRepository;
use CometCMS\Site\LayoutSchema;
use CometCMS\Workspaces\WorkspaceContext;
use CometCMS\Workspaces\WorkspaceRepository;

final class McpServer
{
    private const PROTOCOL_VERSION = '2025-06-18';
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const SYSTEM_FIELDS = ['id', 'uid', 'collection', 'created_at', 'updated_at', 'deleted_at', 'deleted_by', 'updated_by', 'values', 'publication', 'revision', 'reset_fields', 'locale'];

    private string $readLocale = '';
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
        $this->readLocale = '';
        $workspace = Security::slug($workspace);
        $registry = new WorkspaceRepository();

        if (!$registry->exists($workspace)) {
            throw new McpError('Workspace not found.', 404, ['code' => 'not_found']);
        }

        WorkspaceContext::setActive($workspace);
        $this->workspace = WorkspaceContext::active();
        $this->tokens = new ApiTokenRepository();
        $this->permissions = new PermissionService();
        $anonymous = $token === null || trim($token) === '';
        $principal = $anonymous ? [] : $this->tokens->findByToken($token);

        if ($principal === null) {
            (new Logger())->warning('invalid mcp api token');
            throw new McpError('Invalid bearer token.', 401, ['code' => 'unauthorized'], [], [
                'Check the access token and make sure it has not been revoked.',
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
        $icon = $this->absoluteUrl('/admin/img/cms-icon.png');

        return [
            'protocolVersion' => $requested !== '' ? $requested : self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => 'cometcms',
                'title' => 'CometCMS',
                'version' => $this->version(),
                'description' => 'CMS content, schema, media, and permission-scoped page-builder tools.',
                'icons' => [[
                    'src' => $icon,
                    'mimeType' => 'image/png',
                    'sizes' => ['374x374'],
                ]],
                'icon' => $icon,
            ],
            'instructions' => implode(' ', [
                $this->principal === [] ? 'Unauthenticated access is read-only: only public schemas, published entries, and public media are available. Send a bearer access token for private reads or writes.' : 'Authenticated access uses the bearer token permission grants.',
                'Use CometCMS tools to inspect schemas, manage entries, and work with media.',
                $this->canUseBuilder() ? 'Page-builder tools edit shared, live content areas and templates of collections. Start with get_builder_schema, list_templates and get_layout; every layout write requires the latest revision.' : 'Page-builder tools are available to bearer tokens with site.manage permission.',
                'Content type updates are surgical by default: omitted label, icon, and fields are preserved. All content types use the workspace languages and default language.',
                'When updating content type fields, pass only the fields to add or change; use remove_fields to delete fields, or replace_fields for a full field map replacement.',
                'Entry writes target one locale and preserve other languages. Include locale, revision for optimistic concurrency, and reset_fields to remove translated overrides. Publication status and URL slugs belong to each locale.',
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
            if ($this->principal === [] && !$this->toolSchemas()[$name]['annotations']['readOnlyHint']) {
                throw new McpError('A bearer token is required for write tools.', 401, ['code' => 'unauthorized'], $this->requiredPermissionsForTool($name, $args));
            }
            if (in_array($name, PageBuilderTools::NAMES, true)) $this->requirePermission($name, $args);
            ToolArguments::validate($name, array_key_exists('arguments', $params) ? $params['arguments'] : [], [
                'type' => 'object', 'additionalProperties' => true,
                'description' => 'Pass arguments as an object of named tool inputs. Use tools/list for this tool\'s inputSchema; do not pass a JSON string or a list.',
            ]);
            $callback = $this->toolCallbacks()[$name];
            return $this->textResult($callback($args), false, in_array($name, PageBuilderTools::NAMES, true));
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
        } catch (\CometCMS\Core\ConflictException $e) {
            return $this->textResult(['error' => ['message' => $e->getMessage(), 'status' => 409, 'details' => ['code' => 'conflict']]], true);
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
        $builder = [];
        foreach (PageBuilderTools::NAMES as $name) {
            $builder[$name] = function (array $args) use ($name): array {
                $this->requirePermission($name, $args);
                return (new PageBuilderTools($this->workspace, $this->principal, $this->permissions))->call($name, $args);
            };
        }
        return $builder + [
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
            'get_media_item' => fn(array $args): array => $this->getMediaItem($args),
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
        return ['data' => array_values(array_filter($this->types->all(), fn(array $type): bool => $this->principal !== [] || (ExternalReadPolicy::apiEnabled($type) && ($type['visibility'] ?? 'public') !== 'private')))];
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

        if ($this->types->exists($name)) {
            throw new McpError('A content type with this API name already exists.', 422, ['code' => 'validation_failed', 'fields' => ['name' => ['A content type with this API name already exists.']]]);
        }

        $body = ['name' => $name];
        foreach (['label', 'icon', 'fields', 'visibility'] as $key) {
            if (array_key_exists($key, $args)) {
                $body[$key] = $args[$key];
            }
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

        $this->types->save($body);
        $schema = $this->types->find($collection);
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

        $admin = $this->principal !== [];
        $result = $this->content->query($collection, $params, $admin);
        $include = $this->includeFields($params['include']);
        $locale = $params['locale'];
        $result['data'] = array_map(fn(array $entry): array => $this->content->expandRelations($entry, $collection, $include, $locale, fn(array $target): bool => $this->canReadRelatedEntry($target)), $result['data']);
        $result['data'] = array_values(array_map(fn(array $entry): array => $this->publicEntry($entry, $collection, $admin), $result['data']));

        return $this->pageInfo($result);
    }

    private function getEntry(array $args): array
    {
        $collection = $this->segment($args['collection'] ?? '', 'collection');
        $identifier = $this->segment($args['identifier'] ?? '', 'identifier');
        $this->requireCollection($collection, 'Content collection not found.');

        if ($this->principal === []) {
            $this->requirePermission('get_entry', ['collection' => $collection]);
        }
        $admin = $this->principal !== [];
        $locale = $this->content->locale((string) ($args['locale'] ?? ''));
        $entry = $this->content->findByIdentifier($collection, $identifier, $admin, $locale);
        if ($entry === null || (!$admin && !$this->content->isPubliclyVisible($entry, $locale))) {
            throw new McpError('Content entry not found.', 404, ['code' => 'not_found']);
        }

        $this->requirePermission('get_entry', ['collection' => $collection, 'identifier' => $identifier, 'entry' => $entry]);
        $entry = $this->content->resolveLocaleFields($entry, $locale, !$admin);

        $entry = $this->content->expandRelations($entry, $collection, $this->includeFields((string) ($args['include'] ?? '')), $locale, fn(array $target): bool => $this->canReadRelatedEntry($target));

        return ['data' => $this->publicEntry($entry, $collection, $admin)];
    }

    private function createEntry(array $args): array
    {
        $collection = $this->segment($args['collection'] ?? '', 'collection');
        $entry = is_array($args['entry'] ?? null) ? $args['entry'] : [];
        $entry['locale'] = $this->content->locale((string) ($entry['locale'] ?? ''));
        $this->requireCollection($collection, 'Content collection not found.');
        $this->rejectPageFields($collection, $entry);
        $this->requirePermission('create_entry', ['collection' => $collection, 'entry_payload' => $entry, 'locale' => $entry['locale']]);
        $this->requirePublishPermission($collection, null, $entry);

        $saved = $this->content->save($collection, $entry, $this->principal, null, true);

        return ['data' => $this->publicEntry($saved, $collection, true)];
    }

    private function updateEntry(array $args): array
    {
        $collection = $this->segment($args['collection'] ?? '', 'collection');
        $identifier = $this->segment($args['identifier'] ?? '', 'identifier');
        $entry = is_array($args['entry'] ?? null) ? $args['entry'] : [];
        $entry['locale'] = $this->content->locale((string) ($entry['locale'] ?? ''));
        $this->requireCollection($collection, 'Content collection not found.');
        $this->rejectPageFields($collection, $entry);
        $existing = $this->content->findByIdentifier($collection, $identifier, true, $entry['locale']);

        if ($existing === null) {
            throw new McpError('Content entry not found.', 404, ['code' => 'not_found']);
        }

        $existing = $this->content->resolveLocaleFields($existing, $entry['locale']);
        $this->requirePermission('update_entry', ['collection' => $collection, 'identifier' => $identifier, 'entry' => $existing, 'entry_payload' => $entry, 'locale' => $entry['locale']]);
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
            'all',
            'newest',
            $this->principal === [] ? 'public' : null,
        );

        $result['data'] = array_map(fn(array $file): array => $this->mediaSummaryItem($file), $result['data']);
        $result['meta']['categories'] = $this->mediaCategoryOverview();
        $result['meta']['details_tool'] = 'get_media_item';

        return $this->pageInfo($result);
    }

    private function getMediaItem(array $args): array
    {
        $this->readLocale = (new \CometCMS\Localization\LanguageSettings($this->workspace))->requireLocale((string) ($args['locale'] ?? ''));
        $filename = trim((string) ($args['filename'] ?? ''));
        $this->requirePermission('get_media_item', ['filename' => $filename]);
        $item = $this->media->find($filename);

        if ($item === null) {
            throw new McpError('Media file not found.', 404, ['code' => 'not_found']);
        }

        if ($this->principal === [] && ($item['visibility'] ?? 'public') === 'private') {
            throw new McpError('A bearer token is required for private media.', 401, ['code' => 'unauthorized'], $this->requiredPermissionsForTool('get_media_item', $args));
        }

        return ['data' => $this->publicMediaItem($item)];
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
        if (!$this->canUseBuilder()) {
            $schemas = array_diff_key($schemas, array_flip(PageBuilderTools::NAMES));
        }
        if ($this->principal === []) {
            $schemas = array_filter($schemas, static fn(array $schema): bool => $schema['annotations']['readOnlyHint']);
        }
        return array_values(array_map(static fn(string $name, array $schema): array => [
            'name' => $name,
            'title' => $schema['title'],
            'description' => $schema['description'],
            'inputSchema' => $schema['inputSchema'],
            'annotations' => $schema['annotations'],
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
        $visibility = ['type' => 'string', 'enum' => ['public', 'private']];

        return PageBuilderTools::schemas() + [
            'comet_health' => ['title' => 'CometCMS health', 'description' => 'Check the configured CometCMS public API health endpoint.', 'inputSchema' => $this->schema([]), 'annotations' => $this->toolAnnotations(true)],
            'list_content_types' => ['title' => 'List content types', 'description' => 'List CometCMS content type schemas.', 'inputSchema' => $this->schema([]), 'annotations' => $this->toolAnnotations(true)],
            'get_content_type' => ['title' => 'Get content type', 'description' => 'Fetch a single CometCMS content type schema.', 'inputSchema' => $this->schema(['collection' => $string], ['collection']), 'annotations' => $this->toolAnnotations(true)],
            'create_content_type' => ['title' => 'Create content type', 'description' => 'Create a new content type schema. Requires schema.create permission.', 'inputSchema' => $this->schema(['name' => $string, 'label' => $string, 'icon' => $string, 'fields' => $object, 'visibility' => $visibility], ['name']), 'annotations' => $this->toolAnnotations(false, false, false)],
            'update_content_type' => ['title' => 'Update content type', 'description' => 'Surgically update an existing content type schema. Omitted schema parts are preserved.', 'inputSchema' => $this->schema(['revision' => $optionalString, 'collection' => $string, 'label' => $string, 'icon' => $string, 'fields' => $object, 'remove_fields' => ['type' => 'array', 'items' => $string], 'replace_fields' => ['type' => 'boolean'], 'visibility' => $visibility], ['collection']), 'annotations' => $this->toolAnnotations(false, true, true)],
            'delete_content_type' => ['title' => 'Delete content type', 'description' => 'Permanently delete a content type and all its entries.', 'inputSchema' => $this->schema(['collection' => $string], ['collection']), 'annotations' => $this->toolAnnotations(false, true, true)],
            'list_entries' => ['title' => 'List content entries', 'description' => 'Fetch one paginated page of entries.', 'inputSchema' => $this->schema(['collection' => $string, 'q' => $optionalString, 'sort' => $optionalString, 'include' => $optionalString, 'locale' => $optionalString, 'filters' => $object] + $pagination, ['collection']), 'annotations' => $this->toolAnnotations(true)],
            'get_entry' => ['title' => 'Get content entry', 'description' => 'Fetch one entry by stable id or slug.', 'inputSchema' => $this->schema(['collection' => $string, 'identifier' => $string, 'include' => $optionalString, 'locale' => $optionalString], ['collection', 'identifier']), 'annotations' => $this->toolAnnotations(true)],
            'create_entry' => ['title' => 'Create content entry', 'description' => 'Create a content entry.', 'inputSchema' => $this->schema(['collection' => $string, 'entry' => $object], ['collection', 'entry']), 'annotations' => $this->toolAnnotations(false, false, false)],
            'update_entry' => ['title' => 'Update content entry', 'description' => 'Update a content entry by stable id or slug. Page content (Entry content areas) is edited with the page-builder tools and entry.', 'inputSchema' => $this->schema(['collection' => $string, 'identifier' => $string, 'entry' => $object], ['collection', 'identifier', 'entry']), 'annotations' => $this->toolAnnotations(false, true, true)],
            'delete_entry' => ['title' => 'Delete content entry', 'description' => 'Soft-delete a content entry by stable id or slug.', 'inputSchema' => $this->schema(['collection' => $string, 'identifier' => $string], ['collection', 'identifier']), 'annotations' => $this->toolAnnotations(false, true, true)],
            'list_media' => ['title' => 'List media', 'description' => 'Fetch a compact overview of categories and one paginated page of media files. Use get_media_item for URLs and full metadata.', 'inputSchema' => $this->schema(['q' => $optionalString, 'category' => $optionalString] + $pagination), 'annotations' => $this->toolAnnotations(true)],
            'get_media_item' => ['title' => 'Get media item', 'description' => 'Fetch one media file with URLs, dimensions, visibility, and editable metadata.', 'inputSchema' => $this->schema(['filename' => $string, 'locale' => $optionalString], ['filename']), 'annotations' => $this->toolAnnotations(true)],
            'create_media_category' => ['title' => 'Create media category', 'description' => 'Create a media category or subcategory.', 'inputSchema' => $this->schema(['name' => $string, 'parent' => $optionalString], ['name']), 'annotations' => $this->toolAnnotations(false, false, true)],
            'set_media_category' => ['title' => 'Set media category', 'description' => 'Assign or clear a media file category.', 'inputSchema' => $this->schema(['filename' => $string, 'category' => $optionalString], ['filename']), 'annotations' => $this->toolAnnotations(false, true, true)],
            'delete_media' => ['title' => 'Delete media', 'description' => 'Delete a media file.', 'inputSchema' => $this->schema(['filename' => $string], ['filename']), 'annotations' => $this->toolAnnotations(false, true, true)],
        ];
    }

    private function toolAnnotations(
        bool $readOnly,
        bool $destructive = false,
        bool $idempotent = true,
    ): array {
        return [
            'readOnlyHint' => $readOnly,
            'destructiveHint' => $destructive,
            'idempotentHint' => $idempotent,
            'openWorldHint' => false,
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

    private function canUseBuilder(): bool
    {
        return $this->principal !== [] && $this->permissions->allows($this->principal, 'site.manage', [
            'type' => 'site', 'workspace' => $this->workspace->slug(), 'principal' => $this->principal,
        ]);
    }

    private function requirePermission(string $tool, array $args): void
    {
        if (in_array($tool, PageBuilderTools::NAMES, true) && !$this->canUseBuilder()) {
            throw new McpError('Page-builder tools require a bearer token with site.manage permission.', $this->principal === [] ? 401 : 403, ['code' => $this->principal === [] ? 'unauthorized' : 'forbidden'], $this->requiredPermissionsForTool($tool, $args), [
                'Use an access token with site.manage on workspace:' . $this->workspace->slug() . ':site:*. Headless content grants do not grant builder access.',
            ]);
        }
        if ($this->principal === []) {
            $schema = isset($args['collection']) ? $this->types->find((string) $args['collection']) : null;
            if ($this->toolSchemas()[$tool]['annotations']['readOnlyHint'] && ($schema['visibility'] ?? 'public') !== 'private') {
                return;
            }

            throw new McpError('A bearer token is required for this operation.', 401, ['code' => 'unauthorized'], $this->requiredPermissionsForTool($tool, $args));
        }
        foreach ($this->permissionChecks($tool, $args) as [$action, $context]) {
            $context['principal'] = $this->principal;
            $context['workspace'] ??= $this->workspace->slug();

            if (!$this->permissions->allows($this->principal, $action, $context)) {
                throw new McpError('Forbidden.', 403, ['code' => 'forbidden'], $this->requiredPermissionsForTool($tool, $args), [
                    'The token is valid but lacks permission. Update the token in Access Tokens or use a token that already has the required grant.',
                ]);
            }
        }
    }

    private function requirePublishPermission(string $collection, ?array $entry, array $payload): void
    {
        if (($payload['status'] ?? null) !== 'published') {
            return;
        }

        $context = ['type' => 'content', 'collection' => $collection, 'entry' => $entry ?? [], 'principal' => $this->principal, 'workspace' => $this->workspace->slug(), 'locale' => $payload['locale'] ?? $this->content->locale()];
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
            'create_entry' => [['content.create', ['type' => 'content', 'collection' => (string) ($args['collection'] ?? '*'), 'fields' => $this->changedEntryFields($args), 'locale' => $args['locale'] ?? '']]],
            'update_entry' => [['content.update', ['type' => 'content', 'collection' => (string) ($args['collection'] ?? '*'), 'entry' => is_array($args['entry'] ?? null) ? $args['entry'] : [], 'fields' => $this->changedEntryFields($args), 'locale' => $args['locale'] ?? '']]],
            'delete_entry' => [['content.delete', ['type' => 'content', 'collection' => (string) ($args['collection'] ?? '*'), 'entry' => is_array($args['entry'] ?? null) ? $args['entry'] : []]]],
            'list_media' => [['media.read', ['type' => 'media']]],
            'get_media_item' => [['media.read', $this->mediaPermissionContext($args)]],
            'create_media_category' => [['media.update', ['type' => 'media']]],
            'set_media_category' => [['media.update', $this->mediaPermissionContext($args)]],
            'delete_media' => [['media.delete', $this->mediaPermissionContext($args)]],
            default => [],
        };
    }

    private function mediaPermissionContext(array $args): array
    {
        $filename = trim((string) ($args['filename'] ?? ''));

        return $filename !== '' ? $this->media->permissionContext($filename) : ['type' => 'media', 'file' => '*'];
    }

    private function requiredPermissionsForTool(string $tool, array $args): array
    {
        $workspace = $this->workspace->slug();
        if (in_array($tool, PageBuilderTools::NAMES, true)) return [permissionGrant('site.manage', $workspace, 'site:*')];

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
            'get_media_item' => [permissionGrant('media.read', $workspace, $this->mediaResource($args))],
            'create_media_category' => [permissionGrant('media.update', $workspace, 'media:*')],
            'set_media_category' => [permissionGrant('media.update', $workspace, isset($args['filename']) ? 'media:' . trim((string) $args['filename']) : 'media:*')],
            'delete_media' => [permissionGrant('media.delete', $workspace, $this->mediaResource($args))],
            default => [],
        };
    }

    private function recovery(McpError $error): array
    {
        $recovery = $error->recovery();
        if ($recovery !== [] && in_array($error->status(), [404, 409, 422], true)) return $recovery;

        if ($error->status() === 401) {
            $recovery[] = 'Check the bearer access token and make sure it has permission grants for the operation.';
        } elseif ($error->status() === 403) {
            $recovery[] = 'The token is valid but lacks permission. Update the token in Access Tokens or use a token that already has the required grant.';
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
            'revision' => $updates['revision'] ?? null,
            'label' => $updates['label'] ?? $current['label'] ?? null,
            'icon' => $updates['icon'] ?? $current['icon'] ?? null,
            'visibility' => $updates['visibility'] ?? $current['visibility'] ?? 'public',
            'slug_field' => $current['slug_field'] ?? null,
            'slug_source' => $current['slug_source'] ?? null,
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
                $fields[$field] = $this->publicValue($entry[$field], is_array($config) ? $config : [], $admin, (string) $entry['locale']);
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

    /**
     * Page content is edited only through the page-builder tools, which
     * check revisions and reject nodes the schema would drop.
     */
    private function rejectPageFields(string $collection, array $entry): void
    {
        $fields = $this->types->find($collection)['fields'] ?? [];
        $keys = array_keys($entry + (is_array($entry['values'] ?? null) ? $entry['values'] : []));
        $pages = array_values(array_filter($keys, static fn(mixed $key): bool => ($fields[$key]['type'] ?? '') === 'page'));
        if ($pages === []) return;

        throw new McpError('Entry content areas cannot be written with entry tools: ' . implode(', ', $pages) . '.', 422, ['code' => 'page_field', 'fields' => $pages], [], [
            'Remove these keys and use the page-builder tools with entry (id or slug) and area, e.g. get_layout(collection, variant: detail, entry, area), then set_layout or update_section with its revision.',
        ]);
    }

    private function publicValue(mixed $value, array $config, bool $admin, string $locale = ''): mixed
    {
        if (($config['type'] ?? '') === 'page') {
            return ['own_content' => LayoutSchema::hasPageContent($value), 'section_count' => count(LayoutSchema::pageNodes($value)), 'edit_with' => 'Page-builder tools with entry and area (get_layout first).'];
        }

        if (($config['type'] ?? '') === 'media') {
            if (!is_array($value)) {
                return [];
            }

            return array_values(array_filter(array_map(fn(mixed $file): ?string => $this->mediaUrl((string) $file), $value)));
        }

        if (($config['type'] ?? '') === 'relation' && !(is_array($value) && isset($value['collection'], $value['id']))) {
            if (is_array($value)) {
                return array_values(array_filter(array_map(fn(mixed $item): mixed => $this->publicValue($item, $config, $admin, $locale), $value), static fn(mixed $item): bool => $item !== null));
            }
            if ($value === null || $value === '') return $value;
            $target = (string) ($config['target'] ?? '');
            if ($target === '' || !$this->types->exists($target)) return null;
            $related = $this->content->findByIdentifier($target, (string) $value, false, $locale);
            if ($related === null) return null;
            $related = $this->content->resolveLocaleFields($related, $this->content->locale($locale), !$admin);
            return $this->canReadRelatedEntry($related) ? $value : null;
        }

        if (($config['type'] ?? '') === 'repeater' && is_array($value)) {
            $subfields = [];
            foreach ((array) ($config['subfields'] ?? []) as $subfield) {
                if (is_array($subfield) && isset($subfield['key'])) $subfields[(string) $subfield['key']] = $subfield;
            }
            return array_map(function (mixed $row) use ($subfields, $admin, $locale): mixed {
                if (!is_array($row)) return $row;
                $mapped = [];
                foreach ($row as $key => $item) $mapped[$key] = $this->publicValue($item, $subfields[$key] ?? [], $admin, $locale);
                return $mapped;
            }, $value);
        }

        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['collection'], $value['id'])) {
            if (!$this->canReadRelatedEntry($value)) {
                return null;
            }

            return $this->publicEntry($value, (string) $value['collection'], $admin);
        }

        $mapped = [];
        foreach ($value as $key => $item) {
            $mapped[$key] = $this->publicValue($item, [], $admin, $locale);
        }

        return array_is_list($mapped) ? array_values(array_filter($mapped, static fn(mixed $item): bool => $item !== null)) : $mapped;
    }

    private function canReadRelatedEntry(array $entry): bool
    {
        return ExternalReadPolicy::allowsEntry($entry, $this->content, $this->types, $this->workspace, $this->principal ?: null, true);
    }

    private function publicMediaItem(array $file): array
    {
        $languages = (new \CometCMS\Localization\LanguageSettings($this->workspace))->all();
        $file = \CometCMS\Localization\LocalizedValue::tree($file, $this->readLocale ?: $languages['default_locale'], $languages['default_locale']);
        return [
            'filename' => $file['name'],
            'name' => $file['name'],
            'url' => $this->mediaUrl((string) $file['name']),
            'thumb_url' => ($file['thumb'] ?? null) !== null
                ? $this->mediaUrl((string) $file['name']) . '?size=thumbnail'
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
            'sizes' => array_map(fn(array $dimensions): array => $dimensions, $file['sizes'] ?? []),
        ];
    }

    private function mediaSummaryItem(array $file): array
    {
        return [
            'filename' => $file['name'],
            'name' => $file['name'],
            'category' => $file['category'],
            'mime' => $file['mime'],
            'size' => $file['size'],
            'uploaded_at' => $file['uploaded_at'] ?? null,
        ];
    }

    private function mediaCategoryOverview(): array
    {
        $counts = [];

        foreach ($this->media->categories() as $category) {
            $counts[$category] = 0;
        }

        foreach ($this->media->files(visibility: $this->principal === [] ? 'public' : null) as $file) {
            $category = (string) ($file['category'] ?? '');
            if ($category === '') {
                $category = 'Uncategorized';
            }

            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }

        return array_map(
            static fn(string $name, int $count): array => ['name' => $name, 'count' => $count],
            array_keys($counts),
            array_values($counts),
        );
    }

    private function textResult(mixed $value, bool $isError = false, bool $structured = false): array
    {
        return array_filter([
            'content' => [[
                'type' => 'text',
                'text' => json_encode($value, ($structured ? 0 : JSON_PRETTY_PRINT) | JSON_UNESCAPED_SLASHES),
            ]],
            'structuredContent' => $structured ? $value : null,
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
        if (!$this->types->exists($collection) || ($this->principal === [] && !ExternalReadPolicy::apiEnabled($this->types->find($collection)))) {
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

        return array_values(array_unique(array_merge((array) ($entry['reset_fields'] ?? []), array_diff(array_keys($entry), self::SYSTEM_FIELDS))));
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
        return function_exists('comet_version') ? \comet_version() : (string) $this->config('app.version', '1.1.0');
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
