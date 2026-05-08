<?php

declare(strict_types=1);

namespace CometCMS\Workspaces;

use CometCMS\Core\Security;

final class WorkspaceContext
{
    public const DEFAULT = 'default';

    private static string $active = self::DEFAULT;

    public function __construct(private readonly string $slug = self::DEFAULT)
    {
        Security::assertSafeName($slug);
    }

    public static function active(): self
    {
        return new self(self::$active);
    }

    public static function setActive(string $slug): void
    {
        Security::assertSafeName($slug);
        self::$active = $slug;
    }

    public static function reset(): void
    {
        self::$active = (new WorkspaceRepository())->getDefault();
    }

    public static function fromRequest(bool $allowArchived = false): self
    {
        $registry = new WorkspaceRepository();
        $header = trim((string) ($_SERVER['HTTP_X_COMET_WORKSPACE'] ?? ''));
        $query = trim((string) ($_GET['workspace'] ?? ''));
        $requested = $header !== '' ? $header : $query;
        $slug = $requested !== '' ? Security::slug($requested) : $registry->getDefault();

        if (!$registry->exists($slug, $allowArchived)) {
            throw new \RuntimeException('Workspace not found.');
        }

        self::setActive($slug);

        return new self($slug);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function root(): string
    {
        return COMET_STORAGE . '/workspaces/' . $this->slug;
    }

    public function path(string $name): string
    {
        Security::assertSafeName($name);

        return $this->root() . '/' . $name;
    }

    public function ensure(): void
    {
        foreach (
            [
                $this->root(),
                $this->path('content-types'),
                $this->path('content'),
                $this->path('media'),
                $this->path('media-thumbs'),
                $this->path('media-meta'),
                $this->path('revisions'),
                $this->path('revisions') . '/content',
                $this->path('trash'),
                $this->path('trash') . '/content',
                $this->path('trash') . '/media',
                $this->path('cache'),
                $this->path('cache') . '/api',
            ] as $directory
        ) {
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
        }
    }
}
