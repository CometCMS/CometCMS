<?php

declare(strict_types=1);

namespace CometCMS\Trash;

use CometCMS\Core\Security;
use CometCMS\Storage\JsonStore;
use CometCMS\Workspaces\WorkspaceContext;

final class TrashStore
{
    private JsonStore $content;

    public function __construct(?WorkspaceContext $workspace = null)
    {
        $workspace ??= WorkspaceContext::active();
        WorkspaceContext::setActive($workspace->slug());
        $workspace->ensure();
        $this->content = new JsonStore($workspace->path('trash') . '/content');
    }

    public function putContent(string $collection, string $id, array $entry): void
    {
        $this->content->write($entry, $collection, $id);
    }

    public function findContent(string $collection, string $id): ?array
    {
        return $this->content->read($collection, $id);
    }

    public function allContent(string $collection): array
    {
        Security::assertSafeName($collection);

        return $this->content->all($collection);
    }

    public function removeContent(string $collection, string $id): void
    {
        $this->content->delete($collection, $id);
    }
}
