<?php

declare(strict_types=1);

namespace CometCMS\Extensions;

use CometCMS\Site\LayoutSchema;
use CometCMS\Workspaces\WorkspaceContext;
use CometCMS\Storage\JsonStore;

/**
 * The extensions installed in one workspace, at site/extensions/<vendor>/<extension>/.
 * Extensions compile once into a cached index that is keyed by the files' sizes and
 * modification times, so direct deployments take effect without a registration step
 * while ordinary requests only stat the files.
 */
final class ExtensionRegistry
{
    public const MAX_EXTENSIONS = 64;
    public const MAX_BLOCKS = 256;
    /** Bump whenever compiled extensions change shape, so cached indexes are rebuilt. */
    private const COMPILER = 9;
    private const SETTINGS = 'extensions-settings';

    private ?array $index = null;
    private ?string $signature = null;
    private ?array $settings = null;

    public function __construct(public readonly WorkspaceContext $workspace) {}

    public static function active(): self
    {
        return new self(WorkspaceContext::active());
    }

    public function directory(): string
    {
        return $this->workspace->path('site') . '/extensions';
    }

    /** Site-owned types temporarily hidden with the extension that imported them. */
    public static function disabledContentTypes(WorkspaceContext $workspace): array
    {
        return (new HiddenSeeds($workspace))->ids('content_types');
    }

    public static function isKind(string $kind): bool
    {
        return str_starts_with($kind, 'block:') && Extension::validName(substr($kind, 6));
    }

    public static function cssClass(string $name): string
    {
        return 'c-block-' . str_replace('/', '__', $name);
    }

    /** @return array<string, array> block kind => compiled block */
    public function all(): array
    {
        return array_filter($this->index()['blocks'], fn(array $block): bool => $this->index()['extensions'][$block['extension']]['enabled']);
    }

    public function get(string $kind): ?array
    {
        $block = $this->index()['blocks'][$kind] ?? null;
        return $block !== null && $this->index()['extensions'][$block['extension']]['enabled'] ? $block : null;
    }

    public function extension(string $name): ?array
    {
        return $this->index()['extensions'][$name] ?? null;
    }

    /** The current kind of a block stored under one of its earlier names, or null. */
    public function alias(string $kind): ?string
    {
        $current = $this->index()['aliases'][$kind] ?? null;

        return $current !== null && $this->get($current) !== null ? $current : null;
    }

    /** An extension's setting values, with defaults for those an administrator has not set. */
    public function settings(string $name): array
    {
        $specs = $this->index()['extensions'][$name]['settings'] ?? [];
        if ($specs === []) return [];
        $this->settings ??= (new JsonStore($this->workspace->path('site')))->read(self::SETTINGS) ?? [];
        $stored = $this->settings[$name] ?? [];

        return LayoutSchema::normalizeSettings($specs, is_array($stored) ? $stored : []);
    }

    /** Store an extension's settings; unknown keys are dropped and values normalized like block props. */
    public function setSettings(string $name, array $values): ?array
    {
        if (!Extension::validName($name)) throw new \InvalidArgumentException('Invalid extension name.');
        return $this->locked(function () use ($name, $values): ?array {
            $this->index = $this->signature = $this->settings = null;
            $specs = $this->index()['extensions'][$name]['settings'] ?? null;
            if ($specs === null) return null;
            $store = new JsonStore($this->workspace->path('site'));
            $all = $store->read(self::SETTINGS) ?? [];
            $all[$name] = LayoutSchema::normalizeSettings($specs, $values);
            $store->write($all, self::SETTINGS);
            $this->index = $this->signature = $this->settings = null;
            return $all[$name];
        });
    }

    /** Installed metadata plus the current state of the site-owned seeds. */
    public function details(string $name): ?array
    {
        foreach ($this->metadata()['extensions'] as $extension) {
            if ($extension['name'] === $name) return $extension + ['seeded' => (new ExtensionSeeder($this->workspace, $name))->seeded()];
        }
        // A broken extension can still be uninstalled.
        if (Extension::validName($name) && is_dir($this->directory() . '/' . $name)) {
            return ['name' => $name, 'title' => $name, 'seeded' => (new ExtensionSeeder($this->workspace, $name))->seeded()];
        }
        return null;
    }

    /** Resolve seeds against the installed blocks with this extension's update overlaid. */
    public function preview(array $extension): self
    {
        $preview = clone $this;
        $preview->index = $this->index();
        foreach ($preview->index['extensions'][$extension['name']]['blocks'] ?? [] as $kind) unset($preview->index['blocks'][$kind]);
        $preview->index['blocks'] = $extension['blocks'] + $preview->index['blocks'];
        $preview->index['extensions'][$extension['name']]['enabled'] = true;
        return $preview;
    }

    /** What the admin lists: extensions with their blocks, and extensions that failed to load. */
    public function metadata(): array
    {
        $index = $this->index();
        $extensions = [];
        foreach ($index['extensions'] as $extension) {
            $extensions[] = array_intersect_key($extension, array_flip(['name', 'title', 'description', 'version', 'author', 'license', 'homepage', 'icon', 'requires', 'cms', 'permissions', 'settings', 'l10n'])) + [
                'settings_values' => $this->settings($extension['name']),
                'enabled' => $extension['enabled'],
                'dependency_errors' => $extension['dependency_errors'],
                'counts' => ['blocks' => count($extension['blocks'])] + array_count_values(array_column($extension['seeds'], 'kind')),
                'scripts' => self::scriptBlocks($extension['blocks'], $index['blocks']) !== [],
                'blocks' => array_map(static fn(string $kind): array => [
                    'name' => $index['blocks'][$kind]['name'],
                    'title' => $index['blocks'][$kind]['title'],
                    'description' => $index['blocks'][$kind]['description'],
                    'icon' => $index['blocks'][$kind]['icon'],
                    'script' => $index['blocks'][$kind]['script'] !== '',
                    'deprecated' => $index['blocks'][$kind]['deprecated'],
                    'l10n' => $index['blocks'][$kind]['l10n'] ?? (object) [],
                ], $extension['blocks']),
            ];
        }

        return ['extensions' => $extensions, 'errors' => $index['errors']];
    }

    /** Changes whenever any extension file changes; part of the public page cache key. */
    public function fingerprint(): string
    {
        return $this->signature ??= $this->signature();
    }

    /** @return array{path: string, type: string}|null an installed asset by its published file name */
    public function asset(string $file): ?array
    {
        $path = $this->index()['assets'][$file] ?? null;
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        if ($path === null || !isset(Extension::SERVED_TYPES[$extension])) {
            return null;
        }
        $absolute = $this->directory() . '/' . $path;

        // The index is rebuilt whenever a file changes, so its hash still describes the file.
        return is_file($absolute) && !is_link($absolute) ? ['path' => $absolute, 'type' => Extension::SERVED_TYPES[$extension]] : null;
    }

    public function install(string $zipPath, bool $acceptChanges = false, bool $allowScripts = false, ?array $seeds = null): array
    {
        return $this->installFiles(Extension::readZip($zipPath), $acceptChanges, $allowScripts, $seeds);
    }

    /**
     * Install or update an extension from its files. Validation completes before anything
     * is written; an update that would break placed blocks needs $acceptChanges.
     *
     * @param array<string, string> $files extension path => bytes
     */
    public function installFiles(array $files, bool $acceptChanges = false, bool $allowScripts = false, ?array $seeds = null): array
    {
        $extension = Extension::compile($files);

        return $this->locked(function () use ($files, $extension, $acceptChanges, $allowScripts, $seeds): array {
            $this->index = $this->signature = null;
            $index = $this->index();
            $name = $extension['name'];
            $previous = $index['extensions'][$name] ?? null;
            $candidates = $index['extensions'];
            $candidates[$name] = ['enabled' => true] + $extension;
            $this->requireDependencies($candidates, $name);
            $this->requireCompatibleDependents($candidates, $name);
            foreach (array_keys($extension['blocks']) as $kind) {
                $owner = $index['blocks'][$kind]['extension'] ?? $name;
                if ($owner !== $name) {
                    throw ExtensionException::single('blocks/' . explode('/', $kind)[1] . '/', 'The block ' . substr($kind, 6) . ' is already provided by the extension ' . $owner . '.');
                }
            }
            $blockCount = count($index['blocks']) - count($previous['blocks'] ?? []) + count($extension['blocks']);
            if (($previous === null && count($index['extensions']) >= self::MAX_EXTENSIONS) || $blockCount > self::MAX_BLOCKS) {
                throw ExtensionException::single('', sprintf('A workspace holds at most %d extensions and %d blocks.', self::MAX_EXTENSIONS, self::MAX_BLOCKS));
            }
            $seeder = new ExtensionSeeder($this->workspace, $name, $this->preview($extension));
            $extension['seeds'] = $seeder->resolve($extension['seeds']);
            $validated = $seeder->validate($extension['seeds']);
            $changes = $previous === null ? [] : $this->breakingChanges($previous, $extension);
            // Scripts run with the website's origin, so installing them is a separate decision.
            $scripts = self::scriptBlocks(array_keys($extension['blocks']), $extension['blocks']);
            if (($changes !== [] && !$acceptChanges) || ($scripts !== [] && !$allowScripts) || ($validated !== [] && $seeds === null)) {
                throw new ExtensionReview($name, $previous['version'] ?? null, $extension['version'], $changes, $scripts, $seeder->plan($extension['seeds']), $extension['permissions']);
            }
            // Revalidate the effective dependency graph after skip/overwrite choices.
            $seeder->validate($seeder->selected($extension['seeds'], $seeds ?? []));
            $this->write($name, $files, function () use ($seeder, $extension, $seeds): void {
                $this->index = $this->signature = null;
                if ($extension['seeds'] !== []) $seeder->apply($extension['seeds'], $seeds ?? []);
            });
            $this->index = $this->signature = null;

            return [
                'name' => $name,
                'title' => $extension['title'],
                'version' => $extension['version'],
                'previous_version' => $previous['version'] ?? null,
                'blocks' => array_map(static fn(string $kind): string => substr($kind, 6), array_keys($extension['blocks'])),
                'changes' => $changes,
                'scripts' => $scripts,
                'permissions' => $extension['permissions'],
            ];
        });
    }

    /** Toggle runtime blocks without changing extension files or site-owned content. */
    public function setEnabled(string $name, bool $enabled): bool
    {
        if (!Extension::validName($name)) throw new \InvalidArgumentException('Invalid extension name.');
        return $this->locked(function () use ($name, $enabled): bool {
            $this->index = $this->signature = null;
            $extensions = $this->index()['extensions'];
            if (!isset($extensions[$name])) return false;
            if ($enabled) $this->requireDependencies($extensions, $name);
            else $this->requireNoDependents($extensions, $name, enabledOnly: true);
            $store = new JsonStore($this->workspace->path('site'));
            $state = $store->read('extensions-state') ?? [];
            $state[$name] = $enabled;
            $store->write($state, 'extensions-state');
            $this->index = $this->signature = null;
            return true;
        });
    }

    /** Remove an extension; placed instances keep their values for a later reinstall. */
    public function delete(string $name, array $remove = []): bool
    {
        if (!Extension::validName($name)) {
            throw new \InvalidArgumentException('Invalid extension name.');
        }

        return $this->locked(function () use ($name, $remove): bool {
            $directory = $this->directory() . '/' . $name;
            if (is_link(dirname($directory)) || is_link($directory)) {
                throw new \InvalidArgumentException('Extension folders cannot be symbolic links.');
            }
            if (!is_dir($directory)) {
                return false;
            }
            $this->index = $this->signature = null;
            $this->requireNoDependents($this->index()['extensions'], $name);
            $seeder = new ExtensionSeeder($this->workspace, $name);
            if (array_diff($remove, array_map(ExtensionSeeder::key(...), $seeder->seeded())) !== []) {
                throw new \InvalidArgumentException('Unknown seeded item selected for removal.');
            }
            $removed = $this->workspace->path('site') . '/.extensions-deleted-' . bin2hex(random_bytes(10));
            if (!rename($directory, $removed)) {
                throw new \RuntimeException('Could not delete the extension.');
            }
            $this->index = $this->signature = null;
            try {
                $seeder->remove($remove);
            } catch (\Throwable $e) {
                rename($removed, $directory);
                $this->index = $this->signature = null;
                throw $e;
            }
            $store = new JsonStore($this->workspace->path('site'));
            $state = $store->read('extensions-state') ?? [];
            unset($state[$name]);
            $store->write($state, 'extensions-state');
            $settings = $store->read(self::SETTINGS) ?? [];
            if (isset($settings[$name])) {
                unset($settings[$name]);
                $store->write($settings, self::SETTINGS);
            }
            self::remove($removed);
            if (count(scandir(dirname($directory)) ?: []) === 2) {
                rmdir(dirname($directory));
            }

            return true;
        });
    }

    /**
     * What an update removes or changes for blocks already placed in layouts.
     *
     * @return list<string>
     */
    private function breakingChanges(array $previous, array $extension): array
    {
        $changes = [];
        $aliases = [];
        foreach ($extension['blocks'] as $kind => $block) {
            foreach ($block['aliases'] as $alias) $aliases['block:' . $alias] = $kind;
        }
        foreach ($previous['blocks'] as $kind) {
            $old = $this->index()['blocks'][$kind] ?? null;
            $new = $extension['blocks'][$kind] ?? (isset($aliases[$kind]) ? $extension['blocks'][$aliases[$kind]] : null);
            $block = substr($kind, 6);
            if ($old === null) continue;
            if ($new === null) {
                $changes[] = $block . ' is removed. Placed instances will show as missing blocks.';
                continue;
            }
            if (($old['slot'] ?? false) && !$new['slot']) {
                $changes[] = $block . ' no longer has a slot. Elements placed inside it are removed the next time a layout is saved.';
            }
            array_push($changes, ...self::propChanges($block . ': the prop "', $old['props'], $new['props']));
        }

        return $changes;
    }

    /** @return list<string> the names of the given blocks that have a script */
    private static function scriptBlocks(array $kinds, array $blocks): array
    {
        return array_values(array_map(static fn(string $kind): string => substr($kind, 6), array_filter($kinds, static fn(string $kind): bool => ($blocks[$kind]['script'] ?? '') !== '')));
    }

    /** @return list<string> removed props, changed types and dropped enum options, also inside list items */
    private static function propChanges(string $prefix, array $old, array $new): array
    {
        $changes = [];
        $renamed = [];
        foreach ($new as $key => $spec) {
            foreach ($spec['renamed_from'] ?? [] as $earlier) $renamed[$earlier] = $key;
        }
        foreach ($old as $key => $spec) {
            $next = $new[$key] ?? (isset($renamed[$key]) ? $new[$renamed[$key]] : null);
            if ($next === null) {
                $changes[] = $prefix . $key . '" is removed. Its saved values are dropped the next time a layout is saved.';
            } elseif ($next['type'] !== $spec['type']) {
                $changes[] = $prefix . $key . '" changes from ' . $spec['type'] . ' to ' . $next['type'] . '. Saved values that do not fit fall back to the default.';
            } elseif ($spec['type'] === 'enum' && array_diff($spec['options'], $next['options'], array_keys($next['option_aliases'] ?? [])) !== []) {
                $changes[] = $prefix . $key . '" no longer offers ' . implode(', ', array_diff($spec['options'], $next['options'], array_keys($next['option_aliases'] ?? []))) . '. Instances using them fall back to the default.';
            } else {
                if ($spec['type'] === 'int' && ($next['min'] > $spec['min'] || $next['max'] < $spec['max'])) {
                    $changes[] = $prefix . $key . '" narrows its range to ' . $next['min'] . '–' . $next['max'] . '. Saved values outside it are clamped.';
                }
                if (in_array($spec['type'], ['string', 'text', 'url', 'list'], true) && $next['max'] < $spec['max']) {
                    $changes[] = $prefix . $key . '" reduces its limit from ' . $spec['max'] . ' to ' . $next['max'] . '. Saved content beyond it is dropped the next time a layout is saved.';
                }
                if ($spec['type'] === 'list') array_push($changes, ...self::propChanges($prefix . $key . '" item field "', $spec['fields'], $next['fields']));
            }
        }

        return $changes;
    }

    private function write(string $name, array $files, callable $seed): void
    {
        $directory = $this->directory() . '/' . $name;
        if (is_link($this->directory()) || is_link(dirname($directory)) || is_link($directory)) {
            throw new \InvalidArgumentException('Extension folders cannot be symbolic links.');
        }
        if (!is_dir(dirname($directory)) && !mkdir(dirname($directory), 0775, true) && !is_dir(dirname($directory))) {
            throw new \RuntimeException('Could not create the extension folder.');
        }
        $staged = $this->workspace->path('site') . '/.extensions-staged-' . bin2hex(random_bytes(10));
        $backup = $staged . '-previous';
        if (!mkdir($staged, 0775)) {
            throw new \RuntimeException('Could not stage the extension.');
        }
        $installed = false;
        try {
            foreach ($files as $path => $bytes) {
                $target = $staged . '/' . $path;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                    throw new \RuntimeException('Could not stage the extension.');
                }
                if (file_put_contents($target, $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new \RuntimeException('Could not write ' . $path . '.');
                }
            }
            if (is_dir($directory) && !rename($directory, $backup)) {
                throw new \RuntimeException('Could not replace the extension.');
            }
            if (!rename($staged, $directory)) {
                if (is_dir($backup)) rename($backup, $directory);
                throw new \RuntimeException('Could not install the extension.');
            }
            try {
                $seed();
                $installed = true;
            } catch (\Throwable $e) {
                self::remove($directory);
                if (is_dir($backup) && !rename($backup, $directory)) {
                    throw new \RuntimeException('Could not restore the previous extension at ' . $backup, 0, $e);
                }
                $this->index = $this->signature = null;
                throw $e;
            }
        } finally {
            // Keep the old extension for recovery if an I/O failure also prevented the rollback.
            foreach ($installed ? [$staged, $backup] : [$staged] as $leftover) {
                if (is_dir($leftover)) self::remove($leftover);
            }
        }
    }

    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $signature = $this->fingerprint();
        $cache = $this->workspace->path('cache') . '/extensions.json';
        $cached = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
        if (is_array($cached) && ($cached['signature'] ?? null) === $signature) {
            return $this->index = $cached;
        }
        $this->index = $this->compileAll() + ['signature' => $signature];
        $this->store($cache, $this->index);

        return $this->index;
    }

    private function compileAll(): array
    {
        $index = ['extensions' => [], 'blocks' => [], 'aliases' => [], 'assets' => [], 'errors' => []];
        $state = (new JsonStore($this->workspace->path('site')))->read('extensions-state') ?? [];
        if (is_link($this->directory())) {
            $index['errors'][] = ['extension' => '', 'file' => 'site/extensions', 'line' => null, 'message' => 'The extensions folder cannot be a symbolic link.'];
            return $index;
        }
        $directories = glob($this->directory() . '/*/*', GLOB_ONLYDIR) ?: [];
        sort($directories);
        foreach ($directories as $directory) {
            $name = basename(dirname($directory)) . '/' . basename($directory);
            try {
                if (!Extension::validName($name)) {
                    throw ExtensionException::single('', 'Extension folders are named site/extensions/<vendor>/<extension> in lowercase letters, digits and hyphens.');
                }
                if (is_link(dirname($directory))) {
                    throw ExtensionException::single('', 'Extension folders cannot be symbolic links.');
                }
                $extension = Extension::compile(Extension::readDirectory($directory));
                if ($extension['name'] !== $name) {
                    throw ExtensionException::single(Extension::MANIFEST, 'The extension is named ' . $extension['name'] . ' but installed in site/extensions/' . $name . '. The folder must match the name.');
                }
                foreach (array_keys($extension['blocks']) as $kind) {
                    if (isset($index['blocks'][$kind])) {
                        throw ExtensionException::single('', 'The block ' . substr($kind, 6) . ' is already provided by the extension ' . $index['blocks'][$kind]['extension'] . '.');
                    }
                }
                if (count($index['extensions']) >= self::MAX_EXTENSIONS || count($index['blocks']) + count($extension['blocks']) > self::MAX_BLOCKS) {
                    throw ExtensionException::single('', sprintf('A workspace holds at most %d extensions and %d blocks.', self::MAX_EXTENSIONS, self::MAX_BLOCKS));
                }
            } catch (ExtensionException $e) {
                foreach ($e->errors() as $error) {
                    $index['errors'][] = ['extension' => $name] + $error;
                }
                continue;
            }
            foreach ($extension['assets'] as $path => $file) {
                $index['assets'][$file] = $name . '/' . $path;
            }
            $index['blocks'] += $extension['blocks'];
            $index['extensions'][$name] = ['enabled' => ($state[$name] ?? true) !== false, 'blocks' => array_keys($extension['blocks'])] + $extension;
            unset($index['extensions'][$name]['assets']);
        }
        // Resolve after reading every directory, independent of vendor/name order.
        $extensions = $index['extensions'];
        foreach ($extensions as $name => $extension) {
            $problems = self::dependencyProblems($extensions, $name);
            $index['extensions'][$name]['dependency_errors'] = $problems;
            if ($problems !== []) {
                $index['extensions'][$name]['enabled'] = false;
                if ($extension['enabled']) {
                    foreach ($problems as $message) $index['errors'][] = ['extension' => $name, 'file' => Extension::MANIFEST, 'line' => null, 'message' => $message];
                }
            }
        }
        ksort($index['blocks']);
        // A block's earlier names resolve to it, unless another block has taken the name since.
        foreach ($index['blocks'] as $kind => $block) {
            foreach ($block['aliases'] as $alias) {
                if (!isset($index['blocks']['block:' . $alias])) $index['aliases']['block:' . $alias] = $kind;
            }
        }

        return $index;
    }

    /** Dependencies are explicit names with version constraints; nothing is downloaded automatically. */
    private static function dependencyProblems(array $extensions, string $name): array
    {
        $problems = [];
        $visited = [];
        $walk = function (string $candidate, array $trail) use (&$walk, &$problems, &$visited, $extensions, $name): void {
            if (in_array($candidate, $trail, true)) {
                $problems[] = 'Dependency cycle: ' . implode(' → ', [...$trail, $candidate]) . '.';
                return;
            }
            if (isset($visited[$candidate])) return;
            $visited[$candidate] = true;
            $trail[] = $candidate;
            $cms = $extensions[$candidate]['cms'] ?? '';
            if ($cms !== '' && !VersionConstraint::satisfies(Extension::cmsVersion(), $cms)) {
                $problems[] = ($candidate === $name ? 'Requires' : $candidate . ' requires') . ' CometCMS ' . $cms . ', but this site runs ' . Extension::cmsVersion() . '.';
            }
            foreach ($extensions[$candidate]['requires'] ?? [] as $dependency => $constraint) {
                if (!isset($extensions[$dependency])) {
                    $problems[] = 'Requires ' . $dependency . ($constraint === '*' ? '' : ' ' . $constraint) . '. Install it first.';
                } elseif (!VersionConstraint::satisfies((string) $extensions[$dependency]['version'], $constraint)) {
                    $problems[] = 'Requires ' . $dependency . ' ' . $constraint . ', but version ' . $extensions[$dependency]['version'] . ' is installed. Install a matching version first.';
                } elseif (!$extensions[$dependency]['enabled']) {
                    $problems[] = 'Requires ' . $dependency . '. Enable it first.';
                } else {
                    $walk($dependency, $trail);
                }
            }
        };
        $walk($name, []);
        return array_values(array_unique($problems));
    }

    private function requireDependencies(array $extensions, string $name): void
    {
        $problems = self::dependencyProblems($extensions, $name);
        if ($problems !== []) throw new ExtensionException(array_map(static fn(string $message): array => ['file' => Extension::MANIFEST, 'line' => null, 'message' => $message], $problems));
    }

    /** An update must still satisfy the version constraints of enabled extensions that require it. */
    private function requireCompatibleDependents(array $extensions, string $name): void
    {
        $problems = [];
        foreach ($extensions as $candidate => $extension) {
            $constraint = $extension['requires'][$name] ?? null;
            if ($candidate !== $name && $extension['enabled'] && $constraint !== null && !VersionConstraint::satisfies($extensions[$name]['version'], $constraint)) {
                $problems[] = ['file' => Extension::MANIFEST, 'line' => null, 'message' => $candidate . ' requires ' . $name . ' ' . $constraint . ', which version ' . $extensions[$name]['version'] . ' does not satisfy. Update or disable ' . $candidate . ' first.'];
            }
        }
        if ($problems !== []) throw new ExtensionException($problems);
    }

    private function requireNoDependents(array $extensions, string $name, bool $enabledOnly = false): void
    {
        $dependents = [];
        foreach ($extensions as $candidate => $extension) {
            if ((!$enabledOnly || $extension['enabled']) && isset($extension['requires'][$name])) $dependents[] = $candidate;
        }
        if ($dependents !== []) {
            throw ExtensionException::single(Extension::MANIFEST, 'Required by ' . implode(', ', $dependents) . '. ' . ($enabledOnly ? 'Disable those extensions first.' : 'Delete those extensions first.'));
        }
    }

    /** Sizes, times and inodes of every file below site/extensions, without reading them. */
    private function signature(): string
    {
        $state = $this->workspace->path('site') . '/extensions-state.json';
        $settings = $this->workspace->path('site') . '/' . self::SETTINGS . '.json';
        $parts = ['compiler ' . self::COMPILER, 'state ' . (is_file($state) ? (string) file_get_contents($state) : ''), 'settings ' . (is_file($settings) ? (string) file_get_contents($settings) : '')];
        $directory = $this->directory();
        clearstatcache();
        if (is_link($directory)) {
            $parts[] = 'linked';
        } elseif (is_dir($directory)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $path => $info) {
                $parts[] = substr((string) $path, strlen($directory)) . ' ' . ($info->isLink() ? 'link' : $info->getSize() . ' ' . $info->getMTime() . ' ' . $info->getInode());
            }
        }
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    private function store(string $path, array $index): void
    {
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            return;
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        // The cache only saves work; a read-only cache folder just means recompiling.
        if (@file_put_contents($temporary, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function locked(callable $callback): mixed
    {
        $cache = $this->workspace->path('cache');
        if (!is_dir($cache) && !mkdir($cache, 0775, true) && !is_dir($cache)) {
            throw new \RuntimeException('Could not create the extension lock folder.');
        }
        if (is_link($this->directory())) {
            throw new \InvalidArgumentException('The extensions folder cannot be a symbolic link.');
        }
        $lock = fopen($cache . '/extensions.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not open the block lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the extensions.');
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function remove(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) { unlink($directory); return; }
        if (!is_dir($directory)) return;
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isDir() && !$file->isLink()) {
                self::remove($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
