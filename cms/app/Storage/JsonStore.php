<?php

declare(strict_types=1);

namespace CometCMS\Storage;

use CometCMS\Core\Security;

final class JsonStore
{
    private static int $generation = 0;

    /** Invalidate request-local read snapshots after any JSON mutation. */
    public static function generation(): int
    {
        return self::$generation;
    }

    public function __construct(private readonly string $root)
    {
        if (!is_dir($this->root)) {
            mkdir($this->root, 0775, true);
        }
    }

    public function path(string ...$segments): string
    {
        $path = $this->root;

        foreach ($segments as $segment) {
            Security::assertSafeName($segment);
            $path .= '/' . $segment;
        }

        return $path;
    }

    public function read(string ...$segments): ?array
    {
        $path = $this->path(...$segments) . '.json';

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function write(array $data, string ...$segments): void
    {
        $path = $this->path(...$segments) . '.json';
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Could not encode JSON.');
        }

        try {
            if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) !== strlen($json) + 1 || !rename($tmp, $path)) {
                throw new \RuntimeException('Could not write JSON: ' . implode('/', $segments));
            }
        } finally {
            if (is_file($tmp)) unlink($tmp);
        }
        self::$generation++;
    }

    /** Serialize read/modify/write operations, including optimistic revision checks. */
    public function synchronized(callable $operation, string ...$segments): mixed
    {
        $directory = COMET_STORAGE . '/locks';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create lock directory.');
        $handle = fopen($directory . '/' . hash('sha256', $this->root . '/' . implode('/', $segments)) . '.lock', 'c');
        if ($handle === false) throw new \RuntimeException('Cannot open storage lock.');
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Cannot lock storage.');
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function delete(string ...$segments): void
    {
        $path = $this->path(...$segments) . '.json';

        if (is_file($path)) {
            unlink($path);
            self::$generation++;
        }
    }

    public function all(string ...$segments): array
    {
        $items = $this->allUnsorted(...$segments);
        self::sortByModified($items);
        return $items;
    }

    public static function sortByModified(array &$items): void
    {
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? $b['created_at'] ?? ''), (string) ($a['updated_at'] ?? $a['created_at'] ?? '')));
    }

    /** Read records without imposing an order a query will replace. */
    public function allUnsorted(string ...$segments): array
    {
        $directory = $this->path(...$segments);

        if (!is_dir($directory)) {
            return [];
        }

        $items = [];

        foreach (glob($directory . '/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }

        return $items;
    }

    public function directories(): array
    {
        $directories = [];

        foreach (glob($this->root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $directories[] = basename($directory);
        }

        sort($directories);

        return $directories;
    }
}

