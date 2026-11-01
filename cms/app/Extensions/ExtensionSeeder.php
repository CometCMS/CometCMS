<?php

declare(strict_types=1);

namespace CometCMS\Extensions;

use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\Security;
use CometCMS\Core\ValidationException;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\SiteRepository;
use CometCMS\Site\Theme;
use CometCMS\Storage\JsonStore;
use CometCMS\Workspaces\WorkspaceContext;

/** Copies extension seeds into normal site storage. Only the ledger records their origin. */
final class ExtensionSeeder
{
    private ContentTypeRepository $types;
    private SiteRepository $site;
    private JsonStore $store;

    public function __construct(private readonly WorkspaceContext $workspace, private readonly string $extension, private readonly ?ExtensionRegistry $blocks = null)
    {
        $this->types = new ContentTypeRepository($workspace, includeDisabled: true);
        $this->site = new SiteRepository($workspace, $this->types);
        $this->store = new JsonStore($workspace->path('site'));
    }

    public static function key(array $seed): string
    {
        return $seed['kind'] . '/' . $seed['id'];
    }

    public function ledger(): array
    {
        return $this->store->read('extensions-seeded') ?? [];
    }

    /** Presets are matched by display name, while the ledger keeps their immutable site id. */
    public function resolve(array $seeds): array
    {
        $tracked = array_values(array_filter($this->ledger()[$this->extension] ?? [], static fn(array $item): bool => $item['kind'] === 'presets'))[0] ?? null;
        foreach ($seeds as &$seed) {
            if ($seed['kind'] !== 'presets') continue;
            $matched = null;
            foreach ($this->site->themePresets() as $preset) {
                if ($preset['name'] === $seed['data']['name']) { $matched = $preset['id']; break; }
            }
            $seed['id'] = $matched ?? $tracked['id'] ?? SiteRepository::componentId($seed['data']['name'], fn(string $id): bool => $this->site->themePreset($id) !== null);
        }
        unset($seed);
        return $seeds;
    }

    /** @return list<array{kind: string, id: string, name: string, exists: bool}> */
    public function plan(array $seeds): array
    {
        $tracked = array_fill_keys(array_map(self::key(...), $this->ledger()[$this->extension] ?? []), true);
        return array_map(function (array $seed) use ($tracked): array {
            return array_intersect_key($seed, array_flip(['kind', 'id', 'name'])) + [
                'exists' => isset($tracked[self::key($seed)]) || $this->exists($seed),
            ];
        }, $this->resolve($seeds));
    }

    private function exists(array $seed): bool
    {
        return match ($seed['kind']) {
            'content_types' => $this->types->exists($seed['id']),
            'components' => $this->site->component($seed['id']) !== null,
            'templates' => $this->templateExists($seed['id']),
            'presets' => $this->site->themePreset($seed['id']) !== null,
        };
    }

    private function templateExists(string $id): bool
    {
        [$type, $variant] = explode('/', $id);
        return $this->types->exists($type) && $this->site->hasTemplate($type, $variant, SiteRepository::DEFAULT_TEMPLATE);
    }

    /** Normalize all seeds against the site's schema plus the seeds being installed, without writes. */
    public function validate(array $seeds): array
    {
        $errors = [];
        $types = $this->site->types();
        $components = array_column($this->site->components(), null, 'id');
        $blocks = $this->blocks ?? $this->site->blocks();
        $normalized = [];
        $validate = function (array $seed, callable $operation) use (&$errors, &$normalized): void {
            try {
                $normalized[self::key($seed)] = array_replace($seed, ['data' => $operation()]);
            } catch (\Throwable $e) {
                $messages = $e instanceof ValidationException ? array_values($e->fields()) : [$e->getMessage()];
                foreach ($messages as $message) $errors[] = ['file' => self::file($seed), 'line' => null, 'message' => (string) $message];
            }
        };
        foreach ($seeds as $seed) {
            if ($seed['kind'] !== 'content_types') continue;
            $validate($seed, function () use ($seed, &$types): array {
                $data = $this->types->normalize($seed['id'], $seed['data'] + ['name' => $seed['id']]);
                unset($data['revision']);
                return $types[$seed['id']] = $data;
            });
        }
        foreach ($seeds as $seed) {
            if ($seed['kind'] === 'components') $components[$seed['id']] = $seed['data'] + ['id' => $seed['id']];
        }
        foreach ($seeds as $seed) {
            if ($seed['kind'] !== 'components') continue;
            $validate($seed, function () use ($seed, $types, &$components, $blocks): array {
                $this->validateNodes($seed['data']['nodes'] ?? [], $blocks, $components, $seed['data']['type'] ?? '');
                $data = $this->site->normalizeExtensionComponent($seed['id'], $seed['data'], $types, $components, $blocks);
                return $components[$seed['id']] = $data;
            });
        }
        // A cycle can span several components, including an existing component being overwritten.
        $visiting = [];
        $visited = [];
        $visit = function (string $id) use (&$visit, &$visiting, &$visited, $components): void {
            if (isset($visiting[$id])) throw new ValidationException(['nodes' => 'Components cannot contain each other in a cycle.']);
            if (isset($visited[$id])) return;
            $visiting[$id] = true;
            $component = $components[$id] ?? null;
            $nodes = $component['nodes'] ?? $this->site->component($id)['nodes'] ?? [];
            foreach (LayoutSchema::componentRefs($nodes) as $ref) $visit($ref);
            unset($visiting[$id]);
            $visited[$id] = true;
        };
        foreach ($seeds as $seed) {
            if ($seed['kind'] === 'components') $validate($seed, function () use ($seed, $visit, &$normalized): array {
                $visit($seed['id']);
                return $normalized[self::key($seed)]['data'] ?? $seed['data'];
            });
            if ($seed['kind'] === 'templates') $validate($seed, function () use ($seed, $types, $components, $blocks): array {
                [$type, $variant] = explode('/', $seed['id']);
                $data = $seed['data'];
                $list = $this->site->validateTemplates([$type => [$variant => $data['templates'] ?? null]], $types, $components)[$type][$variant];
                $layouts = $data['layouts'] ?? [];
                if (!is_array($layouts)) throw new ValidationException(['layouts' => 'Layouts must be keyed by template id.']);
                foreach ($layouts as $area => &$nodes) {
                    if (!in_array($area, array_column($list, 'id'), true) || !is_array($nodes)) throw new ValidationException(['layouts' => 'Each layout must belong to a template in this list.']);
                    $this->validateNodes($nodes, $blocks, $components, $type);
                    $nodes = LayoutSchema::normalize($nodes, $variant === 'detail', $blocks);
                }
                unset($nodes);
                return ['templates' => $list, 'layouts' => $layouts];
            });
            if ($seed['kind'] === 'presets') $validate($seed, function () use ($seed): array {
                if (!is_array($seed['data']['theme'] ?? null)) throw new ValidationException(['theme' => 'A preset needs a theme.']);
                if ($this->site->themePreset($seed['id']) === null && count($this->site->themePresets()) >= SiteRepository::MAX_THEME_PRESETS) {
                    throw new ValidationException(['theme' => 'The site has reached its theme preset limit.']);
                }
                return ['name' => $seed['data']['name'], 'theme' => Theme::normalize($seed['data']['theme'])];
            });
        }
        if ($errors !== []) throw new ExtensionException($errors);
        return array_values($normalized);
    }

    private function validateNodes(mixed $nodes, ExtensionRegistry $blocks, array $components, mixed $type): void
    {
        if (!is_array($nodes)) throw new ValidationException(['nodes' => 'Nodes must be a list.']);
        foreach ($nodes as $node) {
            if (!is_array($node)) throw new ValidationException(['nodes' => 'Invalid layout node.']);
            $kind = $node['kind'] ?? '';
            if (is_string($kind) && str_starts_with($kind, 'block:') && $blocks->get($kind) === null) {
                throw new ValidationException(['nodes' => 'Unknown extension block: ' . $kind]);
            }
            if ($kind === LayoutSchema::COMPONENT) {
                $id = $node['props']['component'] ?? '';
                $component = is_string($id) ? ($components[$id] ?? null) : null;
                if ($component === null || ($node['props']['root'] ?? 'section') !== $component['root']
                    || (($component['type'] ?? '') !== '' && $component['type'] !== $type)) {
                    throw new ValidationException(['nodes' => 'Unknown or incompatible component reference: ' . (is_string($id) ? $id : '')]);
                }
            }
            $this->validateNodes($node['children'] ?? [], $blocks, $components, $type);
        }
    }

    private static function file(array $seed): string
    {
        return match ($seed['kind']) {
            'content_types' => 'content-types/' . $seed['id'] . '.json',
            'components', 'templates' => $seed['kind'] . '/' . $seed['id'] . '.json',
            'presets' => 'theme.json',
        };
    }

    /** Validate choices again against current data, so a concurrent install cannot silently overwrite it. */
    public function selected(array $seeds, array $choices): array
    {
        $seeds = $this->resolve($seeds);
        $plan = $this->plan($seeds);
        $known = array_map(self::key(...), $plan);
        if (array_diff(array_keys($choices), $known) !== []) throw ExtensionException::single('', 'Unknown seed choice.');
        $selected = [];
        foreach ($seeds as $index => $seed) {
            $choice = $choices[self::key($seed)] ?? ($plan[$index]['exists'] ? 'skip' : 'create');
            if (!in_array($choice, ['create', 'overwrite', 'skip'], true) || ($choice === 'create' && $plan[$index]['exists'])) {
                throw ExtensionException::single(self::file($seed), 'Choose skip or overwrite for an existing item.');
            }
            if ($choice !== 'skip') $selected[] = $seed;
        }
        return $selected;
    }

    /** Write in dependency order and record hashes only after every write succeeds. */
    public function apply(array $seeds, array $choices): array
    {
        // Read the newly installed extension files rather than a preflight registry cached on the site.
        $this->site = new SiteRepository($this->workspace, $this->types);
        $seeds = $this->validate($this->selected($seeds, $choices));
        $ledger = $this->ledger();
        $entries = [];
        foreach ($ledger[$this->extension] ?? [] as $entry) $entries[self::key($entry)] = $entry;
        $components = array_column($this->site->components(), null, 'id');
        foreach ($seeds as $seed) if ($seed['kind'] === 'components') $components[$seed['id']] = $seed['data'];
        $snapshots = $this->snapshot($seeds);
        $written = [];
        try {
            foreach (['content_types', 'components', 'templates', 'presets'] as $kind) {
                foreach ($seeds as $seed) {
                    if ($seed['kind'] !== $kind) continue;
                    $data = $seed['data'];
                    switch ($kind) {
                        case 'content_types':
                            $this->types->save($data);
                            $this->site = new SiteRepository($this->workspace, $this->types);
                            break;
                        case 'components':
                            $this->site->seedComponent($seed['id'], $data, $components);
                            break;
                        case 'templates':
                            [$type, $variant] = explode('/', $seed['id']);
                            $this->site->saveTemplates([$type => [$variant => $data['templates']]]);
                            foreach ($data['layouts'] as $area => $nodes) $this->site->saveLayout($type, $variant, $nodes, area: $area);
                            break;
                        case 'presets':
                            $previous = $this->site->themePreset($seed['id']);
                            if ($previous !== null) $this->site->deleteThemePreset($previous['id']);
                            $preset = $this->site->createThemePreset($data['name'], $data['theme']);
                            unset($entries[self::key($seed)]);
                            $seed['id'] = $preset['id'];
                            break;
                    }
                    $written[] = $seed;
                    $entries[self::key($seed)] = ['kind' => $kind, 'id' => $seed['id'], 'hash' => '', 'seeded_at' => Security::now()];
                }
            }
            // Layout saves can add page fields to types. Hash the final stored items.
            foreach ($written as $seed) {
                $entries[self::key($seed)]['hash'] = $this->hash($seed);
                // An explicit overwrite transfers tracking; an older importer must not
                // hide or delete content now maintained by a different extension.
                foreach ($ledger as $owner => $items) {
                    if ($owner === $this->extension) continue;
                    $ledger[$owner] = array_values(array_filter($items, static fn(array $item): bool => self::key($item) !== self::key($seed)));
                    if ($ledger[$owner] === []) unset($ledger[$owner]);
                }
            }
            if ($entries !== []) $ledger[$this->extension] = array_values($entries);
            $this->store->write($ledger, 'extensions-seeded');
        } catch (\Throwable $e) {
            $this->restore($snapshots);
            throw $e;
        }
        return array_values($entries);
    }

    public function seeded(): array
    {
        $items = [];
        foreach ($this->ledger()[$this->extension] ?? [] as $entry) {
            $name = match ($entry['kind']) {
                'content_types' => $this->types->find($entry['id'])['label'],
                'components' => $this->site->component($entry['id'])['name'] ?? $entry['id'],
                'presets' => $this->site->themePreset($entry['id'])['name'] ?? $entry['id'],
                default => $entry['id'],
            };
            $item = ['kind' => $entry['kind'], 'id' => $entry['id'], 'name' => $name, 'modified' => $this->hash($entry) !== $entry['hash']];
            if ($entry['kind'] === 'content_types') $item['entry_count'] = count(ContentRepository::make($this->workspace, includeDisabled: true)->all($entry['id']));
            $items[] = $item;
        }
        return $items;
    }

    /** Only explicitly selected ledger entries may be removed; the rest become ordinary site data. */
    public function remove(array $remove): void
    {
        $ledger = $this->ledger();
        $entries = $ledger[$this->extension] ?? [];
        if (array_diff($remove, array_map(self::key(...), $entries)) !== []) throw new \InvalidArgumentException('Unknown seeded item selected for removal.');
        foreach (['templates', 'components', 'content_types', 'presets'] as $kind) {
            foreach ($entries as $entry) {
                if ($entry['kind'] !== $kind || !in_array(self::key($entry), $remove, true)) continue;
                switch ($kind) {
                    case 'templates':
                        [$type, $variant] = explode('/', $entry['id']);
                        $this->site->deleteTemplateSet($type, $variant);
                        break;
                    case 'components':
                        if ($this->site->component($entry['id']) !== null) $this->site->deleteComponent($entry['id']);
                        break;
                    case 'content_types':
                        $this->types->delete($entry['id']);
                        break;
                    case 'presets':
                        $preset = $this->site->themePreset($entry['id']);
                        if ($preset !== null) $this->site->deleteThemePreset($preset['id']);
                        break;
                }
            }
        }
        unset($ledger[$this->extension]);
        $this->store->write($ledger, 'extensions-seeded');
    }

    /** Hash the JSON bytes as written, including every layout in a template set. */
    private function hash(array $entry): string
    {
        $root = $this->workspace->path('site') . '/theme';
        $paths = match ($entry['kind']) {
            'content_types' => [$this->workspace->path('content-types') . '/' . $entry['id'] . '.json'],
            'components' => [$root . '/components/' . $entry['id'] . '.json'],
            'templates' => [$root . '/templates/' . $entry['id'] . '.json', ...glob($root . '/content/' . $entry['id'] . '/*.json') ?: []],
            'presets' => [$root . '/presets/' . $entry['id'] . '.json'],
        };
        sort($paths);
        $hash = hash_init('sha256');
        foreach ($paths as $path) {
            $bytes = is_file($path) ? (string) file_get_contents($path) : '<missing>';
            if ($entry['kind'] === 'templates') hash_update($hash, basename($path) . ':' . strlen($bytes) . ':');
            hash_update($hash, $bytes);
        }
        return hash_final($hash);
    }

    /** Journal just the destinations this install may change, including layout revisions and type order. */
    private function snapshot(array $seeds): array
    {
        $root = $this->workspace->path('site');
        $paths = [$root . '/extensions-seeded.json', COMET_STORAGE . '/settings.json'];
        foreach ($seeds as $seed) {
            switch ($seed['kind']) {
                case 'content_types': $paths[] = $this->workspace->path('content-types') . '/' . $seed['id'] . '.json'; break;
                case 'components': $paths[] = $root . '/theme/components/' . $seed['id'] . '.json'; break;
                case 'templates':
                    [$type] = explode('/', $seed['id']);
                    $paths[] = $this->workspace->path('content-types') . '/' . $type . '.json';
                    $paths[] = $root . '/theme/templates/' . $seed['id'] . '.json';
                    $paths[] = $root . '/theme/content/' . $seed['id'];
                    $paths[] = $this->workspace->path('revisions') . '/site/content/' . $seed['id'];
                    break;
                case 'presets': $paths[] = $root . '/theme/presets'; break;
            }
        }
        $snapshots = [];
        foreach (array_unique($paths) as $path) {
            for ($parent = dirname($path); $parent !== dirname($parent); $parent = dirname($parent)) {
                if (is_link($parent)) throw new \RuntimeException('Seed destinations cannot be symbolic links.');
            }
            $snapshots[$path] = $this->capture($path);
        }
        return $snapshots;
    }

    private function capture(string $path): array|string|null
    {
        if (is_link($path)) throw new \RuntimeException('Seed destinations cannot be symbolic links.');
        if (is_file($path)) return (string) file_get_contents($path);
        if (!is_dir($path)) return null;
        $files = [];
        foreach (scandir($path) ?: [] as $file) if ($file !== '.' && $file !== '..') $files[$file] = $this->capture($path . '/' . $file);
        return $files;
    }

    private function restore(array $snapshots): void
    {
        foreach ($snapshots as $path => $snapshot) {
            ExtensionRegistry::remove($path);
            $this->restorePath($path, $snapshot);
        }
    }

    private function restorePath(string $path, array|string|null $snapshot): void
    {
        if ($snapshot === null) return;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        if (is_string($snapshot)) { file_put_contents($path, $snapshot, LOCK_EX); return; }
        if (!is_dir($path)) mkdir($path, 0775, true);
        foreach ($snapshot as $name => $value) $this->restorePath($path . '/' . $name, $value);
    }
}
