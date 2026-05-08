<?php

declare(strict_types=1);

namespace CometCMS\Cache;

use CometCMS\Workspaces\WorkspaceContext;

final class ApiCache
{
    private readonly string $path;

    public function __construct(
        private readonly bool $enabled = true,
        private readonly int $ttl = 300,
        ?string $path = null,
    ) {
        $this->path = $path ?? WorkspaceContext::active()->path('cache') . '/api';
    }

    public static function fromConfig(?WorkspaceContext $workspace = null): self
    {
        $workspace ??= WorkspaceContext::active();

        return new self(
            (bool) comet_config('cache.enabled', true),
            (int) comet_config('cache.ttl', 300),
            $workspace->path('cache') . '/api',
        );
    }

    public function key(string $path, string $query): string
    {
        return hash('sha256', $path . '?' . $query . '|public-v1');
    }

    public function get(string $key): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $file = $this->file($key);

        if (!is_file($file)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($file), true);

        if (!is_array($payload) || (int) ($payload['expires_at'] ?? 0) < time()) {
            @unlink($file);

            return null;
        }

        return is_array($payload['body'] ?? null) ? $payload['body'] : null;
    }

    public function put(string $key, array $body): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!is_dir($this->path)) {
            mkdir($this->path, 0775, true);
        }

        file_put_contents($this->file($key), json_encode([
            'expires_at' => time() + $this->ttl,
            'body' => $body,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    }

    public function clear(): void
    {
        foreach (glob($this->path . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function file(string $key): string
    {
        return $this->path . '/' . $key . '.json';
    }
}
