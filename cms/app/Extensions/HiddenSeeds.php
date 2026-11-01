<?php

declare(strict_types=1);

namespace CometCMS\Extensions;

use CometCMS\Storage\JsonStore;
use CometCMS\Workspaces\WorkspaceContext;

/**
 * Seeded items hidden because the extension that seeded them is disabled
 * (see ExtensionSeeder::KINDS). Their files stay in site storage, so
 * re-enabling brings them back unchanged. Each repository keeps its own
 * instance: the cache follows storage writes, and a new instance also sees
 * extension folders deployed directly.
 */
final class HiddenSeeds
{
    /** @var array<string, array<string, true>> kind => id set */
    private array $hidden = [];
    private int $generation = -1;

    public function __construct(private readonly WorkspaceContext $workspace) {}

    public function has(string $kind, string $id): bool
    {
        return isset($this->all()[$kind][$id]);
    }

    /** @return list<string> */
    public function ids(string $kind): array
    {
        return array_keys($this->all()[$kind] ?? []);
    }

    /** @return array<string, array<string, true>> */
    private function all(): array
    {
        if ($this->generation === JsonStore::generation()) return $this->hidden;
        $store = new JsonStore($this->workspace->path('site'));
        $ledger = $store->read('extensions-seeded') ?? [];
        $this->hidden = [];
        if ($ledger !== []) {
            $state = $store->read('extensions-state') ?? [];
            $registry = new ExtensionRegistry($this->workspace);
            foreach ($ledger as $name => $items) {
                // An uninstalled owner no longer hides anything; a suspended one does.
                if (($state[$name] ?? true) !== false && ($registry->extension((string) $name)['enabled'] ?? true)) continue;
                foreach ($items as $item) {
                    $kind = $item['kind'] ?? null;
                    if (is_string($kind) && (ExtensionSeeder::KINDS[$kind] ?? false) && is_string($item['id'] ?? null)) $this->hidden[$kind][$item['id']] = true;
                }
            }
        }
        $this->generation = JsonStore::generation();
        return $this->hidden;
    }
}
