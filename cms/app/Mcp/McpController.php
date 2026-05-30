<?php

declare(strict_types=1);

namespace CometCMS\Mcp;

use CometCMS\Core\Http;

final class McpController
{
    public function __construct(private readonly Http $http)
    {
    }

    public function handle(string $workspace): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->respond([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => 'MCP endpoint only accepts POST requests.',
                ],
            ], 405);
        }

        $raw = file_get_contents('php://input') ?: '';
        $token = $this->bearerToken();
        $server = new McpServer($this->http);
        [$payload, $status] = $server->handleRaw($raw, $workspace, $token);

        $this->respond($payload, $status);
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

    private function respond(mixed $payload, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        if ($payload !== null) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        exit;
    }
}
