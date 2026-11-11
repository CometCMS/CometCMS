<?php

declare(strict_types=1);

use CometCMS\Auth\ApiTokenRepository;
use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\Http;
use CometCMS\Media\MediaRepository;
use CometCMS\Mcp\McpServer;
use CometCMS\Workspaces\WorkspaceContext;

function comet_test_mcp_request(?string $token, string $method, array $params = [], mixed $id = 1): array
{
    $request = [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
    ];

    if ($params !== []) {
        $request['params'] = $params;
    }

    return (new McpServer(new Http()))->handle($request, 'default', $token);
}

function comet_test_mcp_tool(?string $token, string $name, array $arguments = []): array
{
    [$response, $status] = comet_test_mcp_request($token, 'tools/call', [
        'name' => $name,
        'arguments' => $arguments,
    ]);

    assert_same(200, $status);
    $text = $response['result']['content'][0]['text'] ?? '';
    $decoded = json_decode((string) $text, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Expected MCP tool response text to contain JSON.');
    }

    return [$response, $decoded];
}

function comet_test_mcp_token(array $permissions): string
{
    return (new ApiTokenRepository())->create('MCP test', '', $permissions);
}

test('mcp rejects malformed argument envelopes with guiding 422 errors', function (): void {
    foreach ([null, 'serialized JSON', ['a list']] as $arguments) {
        [$response, $status] = comet_test_mcp_request(null, 'tools/call', ['name' => 'comet_health', 'arguments' => $arguments]);
        assert_same(200, $status);
        assert_true($response['result']['isError']);
        $error = json_decode($response['result']['content'][0]['text'], true)['error'];
        assert_same(422, $error['status']);
        assert_same('arguments', $error['details']['path']);
        assert_same('object', $error['details']['expected']['type']);
        assert_true(str_contains($error['message'], 'object of named tool inputs'));
        assert_true(str_contains($error['recovery'][0], 'tools/list'));
    }
});

test('anonymous mcp initializes and advertises only read tools', function (): void {
    foreach ([null, '', '   '] as $token) {
        [$response, $status] = comet_test_mcp_request($token, 'initialize');
        assert_same(200, $status);
        assert_true(str_contains($response['result']['instructions'], 'read-only'));
        [$response, $status] = comet_test_mcp_request($token, 'tools/list');
        assert_same(200, $status);
        assert_same(['comet_health', 'list_content_types', 'get_content_type', 'list_entries', 'get_entry', 'list_media', 'get_media_item'], array_column($response['result']['tools'], 'name'));
    }
    [, $health] = comet_test_mcp_tool(null, 'comet_health');
    assert_same(true, $health['data']['ok']);
    [$response, $status] = (new McpServer())->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], 'default', null);
    assert_same(202, $status);
    assert_null($response);
});

test('anonymous mcp rejects every write tool before execution', function (): void {
    foreach (['create_content_type', 'update_content_type', 'delete_content_type', 'create_entry', 'update_entry', 'delete_entry', 'create_media_category', 'set_media_category', 'delete_media'] as $name) {
        [$response, $payload] = comet_test_mcp_tool(null, $name);
        assert_same(true, $response['result']['isError']);
        assert_same(401, $payload['error']['status']);
        assert_true($payload['error']['required_permissions'] !== []);
    }
});

test('anonymous mcp reads public schemas and published entries only', function (): void {
    $types = new ContentTypeRepository();
    $types->save(['name' => 'posts', 'api_enabled' => true, 'fields' => ['title' => ['type' => 'text']]]);
    $types->save(['name' => 'members', 'visibility' => 'private']);
    $content = ContentRepository::make();
    $user = ['id' => 'tester'];
    $content->save('posts', ['title' => 'Public', 'slug' => 'public', 'status' => 'published'], $user);
    $content->save('posts', ['title' => 'Draft', 'slug' => 'draft', 'status' => 'draft'], $user);
    $content->save('posts', ['title' => 'Future', 'slug' => 'future', 'status' => 'published', 'published_at' => '2099-01-01T00:00:00Z'], $user);
    [, $payload] = comet_test_mcp_tool(null, 'list_content_types');
    assert_true(in_array('posts', array_column($payload['data'], 'name'), true));
    assert_false(in_array('members', array_column($payload['data'], 'name'), true));
    [, $payload] = comet_test_mcp_tool(null, 'get_content_type', ['collection' => 'posts']);
    assert_same('posts', $payload['data']['name']);
    foreach (['get_content_type', 'list_entries', 'get_entry'] as $name) {
        [, $payload] = comet_test_mcp_tool(null, $name, ['collection' => 'members', 'identifier' => 'missing']);
        assert_same($name === 'get_content_type' ? 401 : 404, $payload['error']['status']);
    }
    [, $payload] = comet_test_mcp_tool(null, 'list_entries', ['collection' => 'posts', 'filters' => ['status' => 'draft']]);
    assert_same([], $payload['data']);
    [, $payload] = comet_test_mcp_tool(null, 'list_entries', ['collection' => 'posts', 'limit' => 1]);
    assert_same(1, $payload['meta']['total']);
    assert_null($payload['meta']['next_offset']);
    assert_same('Public', $payload['data'][0]['title']);
    [, $payload] = comet_test_mcp_tool(null, 'get_entry', ['collection' => 'posts', 'identifier' => 'public']);
    assert_same('Public', $payload['data']['title']);
    foreach (['draft', 'future'] as $identifier) {
        [, $payload] = comet_test_mcp_tool(null, 'get_entry', ['collection' => 'posts', 'identifier' => $identifier]);
        assert_same(404, $payload['error']['status']);
    }
});

test('anonymous mcp media listings and category counts exclude private files', function (): void {
    file_put_contents(comet_test_workspace_path() . '/media/public.jpg', 'image');
    file_put_contents(comet_test_workspace_path() . '/media/private.jpg', 'image');
    $media = new MediaRepository();
    $media->assignCategory('public.jpg', 'Images');
    $media->assignCategory('private.jpg', 'Images');
    $media->updateVisibility('private.jpg', 'private');
    [, $payload] = comet_test_mcp_tool(null, 'list_media');
    assert_same(['public.jpg'], array_column($payload['data'], 'filename'));
    assert_same(1, $payload['meta']['total']);
    $counts = array_column($payload['meta']['categories'], 'count', 'name');
    assert_same(1, $counts['Images']);
    [, $payload] = comet_test_mcp_tool(null, 'get_media_item', ['filename' => 'public.jpg']);
    assert_same('public.jpg', $payload['data']['filename']);
    [, $payload] = comet_test_mcp_tool(null, 'get_media_item', ['filename' => 'private.jpg']);
    assert_same(401, $payload['error']['status']);
});

test('anonymous mcp omits private and unpublished expanded relations', function (): void {
    $types = new ContentTypeRepository();
    $types->save(['name' => 'members', 'visibility' => 'private']);
    $types->save(['name' => 'posts', 'api_enabled' => true]);
    $types->save(['name' => 'pages', 'api_enabled' => true, 'fields' => [
        'member' => ['type' => 'relation', 'target' => 'members'],
        'posts' => ['type' => 'relation', 'target' => 'posts', 'multiple' => true],
    ]]);
    $content = ContentRepository::make();
    $user = ['id' => 'tester'];
    $member = $content->save('members', ['title' => 'Secret', 'status' => 'published'], $user);
    $published = $content->save('posts', ['title' => 'Visible', 'status' => 'published'], $user);
    $draft = $content->save('posts', ['title' => 'Hidden', 'status' => 'draft'], $user);
    $content->save('pages', ['title' => 'Home', 'slug' => 'home', 'status' => 'published', 'member' => $member['id'], 'posts' => [$published['id'], $draft['id']]], $user);
    foreach (['list_entries', 'get_entry'] as $name) {
        [, $payload] = comet_test_mcp_tool(null, $name, ['collection' => 'pages', 'identifier' => 'home', 'include' => 'member,posts']);
        $entry = $name === 'list_entries' ? $payload['data'][0] : $payload['data'];
        assert_null($entry['data']['member']);
        assert_same(['Visible'], array_column($entry['data']['posts'], 'title'));
    }
});

test('anonymous mcp never reads submission types including legacy public schemas and expanded relations', function (): void {
    $types = new ContentTypeRepository();
    $store = new \CometCMS\Storage\JsonStore(comet_test_workspace_path() . '/content-types');
    $content = ContentRepository::make();
    $types->save(['name' => 'pages', 'api_enabled' => true, 'fields' => [
        'inquiry' => ['type' => 'relation', 'target' => 'inquiries'],
        'inquiries' => ['type' => 'relation', 'target' => 'inquiries', 'multiple' => true],
    ]]);
    foreach ([false, true] as $enabled) {
        foreach (['private', 'public'] as $visibility) {
            foreach (['draft', 'published'] as $status) {
                $store->write([
                    'name' => 'inquiries', 'api_enabled' => $enabled, 'visibility' => $visibility, 'allow_submissions' => true,
                    'submission_settings' => ['status' => $status], 'fields' => ['email' => ['type' => 'text']],
                ], 'inquiries');
                $entry = $content->save('inquiries', ['title' => 'Private submission', 'slug' => 'private', 'email' => 'private@example.com', 'status' => $status], ['id' => 'admin'], 'private');
                $content->save('pages', ['title' => 'Visible', 'slug' => 'visible', 'status' => 'published', 'inquiry' => $entry['id'], 'inquiries' => [$entry['id']]], ['id' => 'admin'], 'visible');
                [, $listing] = comet_test_mcp_tool(null, 'list_content_types');
                assert_same(['pages'], array_column($listing['data'], 'name'));
                foreach (['get_content_type', 'list_entries', 'get_entry'] as $tool) {
                    [$response, $payload] = comet_test_mcp_tool(null, $tool, ['collection' => 'inquiries', 'identifier' => $entry['id']]);
                    assert_true($response['result']['isError']);
                    assert_same($tool === 'get_content_type' || $enabled ? 401 : 404, $payload['error']['status']);
                    assert_false(isset($payload['data']));
                }
                foreach (['list_entries', 'get_entry'] as $tool) {
                    [, $payload] = comet_test_mcp_tool(null, $tool, ['collection' => 'pages', 'identifier' => 'visible', 'include' => 'inquiry,inquiries']);
                    $page = $tool === 'list_entries' ? $payload['data'][0] : $payload['data'];
                    assert_null($page['data']['inquiry']);
                    assert_same([], $page['data']['inquiries']);
                    assert_false(str_contains(json_encode($payload), 'private@example.com'));
                }
            }
        }
    }
    $token = comet_test_mcp_token([['actions' => ['content.read', 'schema.read'], 'resources' => ['*']]]);
    foreach (['list_entries', 'get_entry'] as $tool) {
        [, $payload] = comet_test_mcp_tool($token, $tool, ['collection' => 'inquiries', 'identifier' => $entry['id']]);
        $submission = $tool === 'list_entries' ? $payload['data'][0] : $payload['data'];
        assert_same('private@example.com', $submission['data']['email']);
    }
    $denied = comet_test_mcp_token([['actions' => ['content.read'], 'resources' => ['content:other:*']]]);
    [, $payload] = comet_test_mcp_tool($denied, 'get_entry', ['collection' => 'inquiries', 'identifier' => $entry['id']]);
    assert_same(403, $payload['error']['status']);
});

test('mcp initialize returns server metadata and capabilities', function (): void {
    $token = comet_test_mcp_token([
        ['actions' => ['schema.read'], 'resources' => ['workspace:default:schema:*']],
    ]);

    [$response, $status] = comet_test_mcp_request($token, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'clientInfo' => ['name' => 'test', 'version' => '1.0.0'],
    ]);

    assert_same(200, $status);
    assert_same('2.0', $response['jsonrpc'] ?? null);
    assert_same('2025-06-18', $response['result']['protocolVersion'] ?? null);
    assert_same('cometcms', $response['result']['serverInfo']['name'] ?? null);
    assert_same('CometCMS', $response['result']['serverInfo']['title'] ?? null);
    assert_same('image/png', $response['result']['serverInfo']['icons'][0]['mimeType'] ?? null);
    assert_same(['374x374'], $response['result']['serverInfo']['icons'][0]['sizes'] ?? null);
    assert_true(str_ends_with((string) ($response['result']['serverInfo']['icons'][0]['src'] ?? ''), '/admin/img/cms-icon.png'));
    assert_same($response['result']['serverInfo']['icons'][0]['src'] ?? null, $response['result']['serverInfo']['icon'] ?? null);
    assert_true(isset($response['result']['capabilities']['tools']));
    assert_true(is_string($response['result']['instructions'] ?? null));
});

test('mcp tools list includes embedded tools and omits upload_media', function (): void {
    $token = comet_test_mcp_token([
        ['actions' => ['schema.read'], 'resources' => ['workspace:default:schema:*']],
    ]);

    [$response, $status] = comet_test_mcp_request($token, 'tools/list');

    assert_same(200, $status);
    $names = array_map(static fn(array $tool): string => (string) $tool['name'], $response['result']['tools'] ?? []);
    assert_true(in_array('list_content_types', $names, true));
    assert_true(in_array('get_media_item', $names, true));
    assert_true(in_array('delete_media', $names, true));
    assert_false(in_array('upload_media', $names, true));

    $json = json_encode($response, JSON_UNESCAPED_SLASHES);
    assert_true(str_contains((string) $json, '"properties":{}'));
});

test('mcp tools advertise behavioral annotations', function (): void {
    $token = comet_test_mcp_token([
        ['actions' => ['schema.read'], 'resources' => ['workspace:default:schema:*']],
    ]);

    [$response, $status] = comet_test_mcp_request($token, 'tools/list');

    assert_same(200, $status);
    $tools = [];
    foreach ($response['result']['tools'] ?? [] as $tool) {
        $tools[(string) ($tool['name'] ?? '')] = $tool;
        assert_same(false, $tool['annotations']['openWorldHint'] ?? null);
    }

    assert_same([
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ], $tools['get_entry']['annotations'] ?? null);
    assert_same([
        'readOnlyHint' => false,
        'destructiveHint' => false,
        'idempotentHint' => false,
        'openWorldHint' => false,
    ], $tools['create_entry']['annotations'] ?? null);
    assert_same([
        'readOnlyHint' => false,
        'destructiveHint' => true,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ], $tools['delete_media']['annotations'] ?? null);
});

test('mcp list media is compact and get media item returns details', function (): void {
    WorkspaceContext::setActive('default');
    file_put_contents(comet_test_workspace_path() . '/media/hero.jpg', 'image');
    file_put_contents(comet_test_workspace_path() . '/media/guide.pdf', 'pdf');
    (new MediaRepository())->assignCategory('hero.jpg', 'Images / Heroes');

    $token = comet_test_mcp_token([
        ['actions' => ['media.read'], 'resources' => ['workspace:default:media:*']],
    ]);

    [, $listPayload] = comet_test_mcp_tool($token, 'list_media', [
        'limit' => 10,
        'offset' => 0,
    ]);

    $first = $listPayload['data'][0] ?? [];
    assert_true(isset($first['filename']));
    assert_false(isset($first['url']));
    assert_false(isset($first['thumb_url']));
    assert_same('get_media_item', $listPayload['meta']['details_tool'] ?? null);
    assert_true(in_array('Images / Heroes', array_column($listPayload['meta']['categories'] ?? [], 'name'), true));

    [, $detailPayload] = comet_test_mcp_tool($token, 'get_media_item', [
        'filename' => 'hero.jpg',
    ]);

    assert_same('hero.jpg', $detailPayload['data']['filename'] ?? null);
    assert_true(isset($detailPayload['data']['url']));
    assert_same('Images / Heroes', $detailPayload['data']['category'] ?? null);
});

test('mcp tools call can list content types with an authorized token', function (): void {
    WorkspaceContext::setActive('default');
    (new ContentTypeRepository())->save([
        'name' => 'posts',
        'label' => 'Posts',
        'fields' => [
            'title' => ['type' => 'text', 'required' => true],
            'slug' => ['type' => 'slug', 'required' => true, 'unique' => true],
            'body' => ['type' => 'textarea'],
        ],
    ]);
    $token = comet_test_mcp_token([
        ['actions' => ['schema.read'], 'resources' => ['workspace:default:schema:*']],
    ]);

    [, $payload] = comet_test_mcp_tool($token, 'list_content_types');

    $names = array_map(static fn(array $type): string => (string) $type['name'], $payload['data'] ?? []);
    assert_true(in_array('posts', $names, true));
});

test('mcp reports invalid and underpermissioned tokens', function (): void {
    $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

    [$invalid, $invalidStatus] = (new McpServer(new Http()))->handle($request, 'default', 'ctcms_missing');
    assert_same(401, $invalidStatus);
    assert_same('Invalid bearer token.', $invalid['error']['message'] ?? null);

    $token = comet_test_mcp_token([
        ['actions' => ['content.read'], 'resources' => ['workspace:default:content:*']],
    ]);
    [$response, $payload] = comet_test_mcp_tool($token, 'list_content_types');

    assert_true(($response['result']['isError'] ?? false) === true);
    assert_same(403, $payload['error']['status'] ?? null);
    assert_same('schema.read', $payload['error']['required_permissions'][0]['actions'][0] ?? null);
});

test('mcp update content type preserves omitted fields and removes requested fields', function (): void {
    WorkspaceContext::setActive('default');
    (new ContentTypeRepository())->save([
        'name' => 'posts',
        'label' => 'Posts',
        'icon' => 'mdi:newspaper',
        'fields' => [
            'title' => ['type' => 'text', 'required' => true],
            'slug' => ['type' => 'slug', 'required' => true, 'unique' => true],
            'body' => ['type' => 'textarea'],
            'summary' => ['type' => 'text'],
        ],
    ]);
    $token = comet_test_mcp_token([
        ['actions' => ['schema.update'], 'resources' => ['workspace:default:schema:posts']],
    ]);

    [, $payload] = comet_test_mcp_tool($token, 'update_content_type', [
        'collection' => 'posts',
        'fields' => [
            'subtitle' => ['type' => 'text'],
        ],
        'remove_fields' => ['summary'],
    ]);

    assert_same('Posts', $payload['data']['label'] ?? null);
    assert_same('mdi:newspaper', $payload['data']['icon'] ?? null);
    assert_true(isset($payload['data']['fields']['body']));
    assert_true(isset($payload['data']['fields']['subtitle']));
    assert_false(isset($payload['data']['fields']['summary']));
});

test('mcp list entries returns pagination with next offset', function (): void {
    WorkspaceContext::setActive('default');
    $types = new ContentTypeRepository();
    $types->save([
        'name' => 'posts',
        'fields' => [
            'title' => ['type' => 'text', 'required' => true],
            'slug' => ['type' => 'slug', 'required' => true, 'unique' => true],
        ],
    ]);
    $content = ContentRepository::make(WorkspaceContext::active());
    $user = ['id' => 'tester'];
    $content->save('posts', ['title' => 'First', 'slug' => 'first', 'status' => 'draft'], $user);
    $content->save('posts', ['title' => 'Second', 'slug' => 'second', 'status' => 'draft'], $user);
    $token = comet_test_mcp_token([
        ['actions' => ['content.read'], 'resources' => ['workspace:default:content:posts:*']],
    ]);

    [, $payload] = comet_test_mcp_tool($token, 'list_entries', [
        'collection' => 'posts',
        'limit' => 1,
        'offset' => 0,
        'sort' => 'title',
    ]);

    assert_same(2, $payload['meta']['total'] ?? null);
    assert_same(1, $payload['meta']['limit'] ?? null);
    assert_same(0, $payload['meta']['offset'] ?? null);
    assert_same(1, $payload['meta']['next_offset'] ?? null);
});

test('mcp invalid json rpc shape returns protocol error', function (): void {
    $token = comet_test_mcp_token([
        ['actions' => ['schema.read'], 'resources' => ['workspace:default:schema:*']],
    ]);

    [$response, $status] = (new McpServer(new Http()))->handle(['method' => 'tools/list'], 'default', $token);

    assert_same(400, $status);
    assert_same(-32600, $response['error']['code'] ?? null);
});
