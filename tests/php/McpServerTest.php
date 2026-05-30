<?php

declare(strict_types=1);

use CometCMS\Auth\ApiTokenRepository;
use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\Http;
use CometCMS\Mcp\McpServer;
use CometCMS\Workspaces\WorkspaceContext;

function comet_test_mcp_request(string $token, string $method, array $params = [], mixed $id = 1): array
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

function comet_test_mcp_tool(string $token, string $name, array $arguments = []): array
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
    assert_true(in_array('delete_media', $names, true));
    assert_false(in_array('upload_media', $names, true));

    $json = json_encode($response, JSON_UNESCAPED_SLASHES);
    assert_true(str_contains((string) $json, '"properties":{}'));
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

test('mcp reports missing invalid and underpermissioned tokens', function (): void {
    $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

    [$missing, $missingStatus] = (new McpServer(new Http()))->handle($request, 'default', null);
    assert_same(401, $missingStatus);
    assert_same('Missing bearer token.', $missing['error']['message'] ?? null);

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
