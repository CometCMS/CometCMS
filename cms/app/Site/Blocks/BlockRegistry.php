<?php

declare(strict_types=1);

namespace CometCMS\Site\Blocks;

use CometCMS\Workspaces\WorkspaceContext;

/**
 * The block packages installed in one workspace, at site/blocks/<vendor>/<package>/.
 * Packages compile once into a cached index that is keyed by the files' sizes and
 * modification times, so direct deployments take effect without a registration step
 * while ordinary requests only stat the files.
 */
final class BlockRegistry
{
    public const MAX_PACKAGES = 64;
    public const MAX_BLOCKS = 256;
    /** Bump whenever compiled packages change shape, so cached indexes are rebuilt. */
    private const COMPILER = 2;

    private ?array $index = null;
    private ?string $signature = null;

    public function __construct(public readonly WorkspaceContext $workspace) {}

    public static function active(): self
    {
        return new self(WorkspaceContext::active());
    }

    public function directory(): string
    {
        return $this->workspace->path('site') . '/blocks';
    }

    public static function isKind(string $kind): bool
    {
        return str_starts_with($kind, 'block:') && BlockPackage::validName(substr($kind, 6));
    }

    public static function cssClass(string $name): string
    {
        return 'c-block-' . str_replace('/', '__', $name);
    }

    /** @return array<string, array> block kind => compiled block */
    public function all(): array
    {
        return $this->index()['blocks'];
    }

    public function get(string $kind): ?array
    {
        return $this->index()['blocks'][$kind] ?? null;
    }

    public function package(string $name): ?array
    {
        return $this->index()['packages'][$name] ?? null;
    }

    /** What the admin lists: packages with their blocks, and packages that failed to load. */
    public function metadata(): array
    {
        $index = $this->index();
        $packages = [];
        foreach ($index['packages'] as $package) {
            $packages[] = array_intersect_key($package, array_flip(['name', 'title', 'description', 'version', 'author', 'license', 'homepage'])) + [
                'scripts' => self::scriptBlocks($package['blocks'], $index['blocks']) !== [],
                'blocks' => array_map(static fn(string $kind): array => [
                    'name' => $index['blocks'][$kind]['name'],
                    'title' => $index['blocks'][$kind]['title'],
                    'description' => $index['blocks'][$kind]['description'],
                    'icon' => $index['blocks'][$kind]['icon'],
                    'script' => $index['blocks'][$kind]['script'] !== '',
                ], $package['blocks']),
            ];
        }

        return ['packages' => $packages, 'errors' => $index['errors']];
    }

    /** Changes whenever any package file changes; part of the public page cache key. */
    public function fingerprint(): string
    {
        return $this->signature ??= $this->signature();
    }

    /** @return array{path: string, type: string}|null an installed asset by its published file name */
    public function asset(string $file): ?array
    {
        $path = $this->index()['assets'][$file] ?? null;
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        if ($path === null || !isset(BlockPackage::SERVED_TYPES[$extension])) {
            return null;
        }
        $absolute = $this->directory() . '/' . $path;

        // The index is rebuilt whenever a file changes, so its hash still describes the file.
        return is_file($absolute) && !is_link($absolute) ? ['path' => $absolute, 'type' => BlockPackage::SERVED_TYPES[$extension]] : null;
    }

    public function install(string $zipPath, bool $acceptChanges = false, bool $allowScripts = false): array
    {
        return $this->installFiles(BlockPackage::readZip($zipPath), $acceptChanges, $allowScripts);
    }

    /**
     * Install or update a package from its files. Validation completes before anything
     * is written; an update that would break placed blocks needs $acceptChanges.
     *
     * @param array<string, string> $files package path => bytes
     */
    public function installFiles(array $files, bool $acceptChanges = false, bool $allowScripts = false): array
    {
        $package = BlockPackage::compile($files);

        return $this->locked(function () use ($files, $package, $acceptChanges, $allowScripts): array {
            $this->index = $this->signature = null;
            $index = $this->index();
            $name = $package['name'];
            $previous = $index['packages'][$name] ?? null;
            foreach (array_keys($package['blocks']) as $kind) {
                $owner = $index['blocks'][$kind]['package'] ?? $name;
                if ($owner !== $name) {
                    throw BlockPackageException::single('blocks/' . explode('/', $kind)[1] . '/', 'The block ' . substr($kind, 6) . ' is already provided by the package ' . $owner . '.');
                }
            }
            $blockCount = count($index['blocks']) - count($previous['blocks'] ?? []) + count($package['blocks']);
            if (($previous === null && count($index['packages']) >= self::MAX_PACKAGES) || $blockCount > self::MAX_BLOCKS) {
                throw BlockPackageException::single('', sprintf('A workspace holds at most %d packages and %d blocks.', self::MAX_PACKAGES, self::MAX_BLOCKS));
            }
            $changes = $previous === null ? [] : $this->breakingChanges($previous, $package);
            if ($changes !== [] && !$acceptChanges) {
                throw new BlockPackageChanges($name, $previous['version'], $package['version'], $changes);
            }
            // Scripts run with the website's origin, so installing them is a separate decision.
            $scripts = self::scriptBlocks(array_keys($package['blocks']), $package['blocks']);
            if ($scripts !== [] && !$allowScripts) {
                throw new BlockPackageScripts($name, $scripts);
            }
            $this->write($name, $files);
            $this->index = $this->signature = null;

            return [
                'name' => $name,
                'title' => $package['title'],
                'version' => $package['version'],
                'previous_version' => $previous['version'] ?? null,
                'blocks' => array_map(static fn(string $kind): string => substr($kind, 6), array_keys($package['blocks'])),
                'changes' => $changes,
                'scripts' => $scripts,
            ];
        });
    }

    /** Remove a package; placed instances keep their values for a later reinstall. */
    public function delete(string $name): bool
    {
        if (!BlockPackage::validName($name)) {
            throw new \InvalidArgumentException('Invalid package name.');
        }

        return $this->locked(function () use ($name): bool {
            $directory = $this->directory() . '/' . $name;
            if (is_link(dirname($directory)) || is_link($directory)) {
                throw new \InvalidArgumentException('Package folders cannot be symbolic links.');
            }
            if (!is_dir($directory)) {
                return false;
            }
            $removed = $this->workspace->path('site') . '/.blocks-deleted-' . bin2hex(random_bytes(10));
            if (!rename($directory, $removed)) {
                throw new \RuntimeException('Could not delete the package.');
            }
            $this->index = $this->signature = null;
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
    private function breakingChanges(array $previous, array $package): array
    {
        $changes = [];
        foreach ($previous['blocks'] as $kind) {
            $old = $this->get($kind);
            $new = $package['blocks'][$kind] ?? null;
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
        foreach ($old as $key => $spec) {
            $next = $new[$key] ?? null;
            if ($next === null) {
                $changes[] = $prefix . $key . '" is removed. Its saved values are dropped the next time a layout is saved.';
            } elseif ($next['type'] !== $spec['type']) {
                $changes[] = $prefix . $key . '" changes from ' . $spec['type'] . ' to ' . $next['type'] . '. Saved values that do not fit fall back to the default.';
            } elseif ($spec['type'] === 'enum' && array_diff($spec['options'], $next['options']) !== []) {
                $changes[] = $prefix . $key . '" no longer offers ' . implode(', ', array_diff($spec['options'], $next['options'])) . '. Instances using them fall back to the default.';
            } elseif ($spec['type'] === 'list') {
                array_push($changes, ...self::propChanges($prefix . $key . '" item field "', $spec['fields'], $next['fields']));
            }
        }

        return $changes;
    }

    private function write(string $name, array $files): void
    {
        $directory = $this->directory() . '/' . $name;
        if (is_link($this->directory()) || is_link(dirname($directory)) || is_link($directory)) {
            throw new \InvalidArgumentException('Package folders cannot be symbolic links.');
        }
        if (!is_dir(dirname($directory)) && !mkdir(dirname($directory), 0775, true) && !is_dir(dirname($directory))) {
            throw new \RuntimeException('Could not create the package folder.');
        }
        $staged = $this->workspace->path('site') . '/.blocks-staged-' . bin2hex(random_bytes(10));
        $backup = $staged . '-previous';
        if (!mkdir($staged, 0775)) {
            throw new \RuntimeException('Could not stage the package.');
        }
        $installed = false;
        try {
            foreach ($files as $path => $bytes) {
                $target = $staged . '/' . $path;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                    throw new \RuntimeException('Could not stage the package.');
                }
                if (file_put_contents($target, $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new \RuntimeException('Could not write ' . $path . '.');
                }
            }
            if (is_dir($directory) && !rename($directory, $backup)) {
                throw new \RuntimeException('Could not replace the package.');
            }
            if (!rename($staged, $directory)) {
                if (is_dir($backup)) rename($backup, $directory);
                throw new \RuntimeException('Could not install the package.');
            }
            $installed = true;
        } finally {
            // Keep the old package for recovery if an I/O failure also prevented the rollback.
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
        $cache = $this->workspace->path('cache') . '/blocks.json';
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
        $index = ['packages' => [], 'blocks' => [], 'assets' => [], 'errors' => []];
        if (is_link($this->directory())) {
            $index['errors'][] = ['package' => '', 'file' => 'site/blocks', 'line' => null, 'message' => 'The blocks folder cannot be a symbolic link.'];
            return $index;
        }
        $directories = glob($this->directory() . '/*/*', GLOB_ONLYDIR) ?: [];
        sort($directories);
        foreach ($directories as $directory) {
            $name = basename(dirname($directory)) . '/' . basename($directory);
            try {
                if (!BlockPackage::validName($name)) {
                    throw BlockPackageException::single('', 'Package folders are named site/blocks/<vendor>/<package> in lowercase letters, digits and hyphens.');
                }
                if (is_link(dirname($directory))) {
                    throw BlockPackageException::single('', 'Package folders cannot be symbolic links.');
                }
                $package = BlockPackage::compile(BlockPackage::readDirectory($directory));
                if ($package['name'] !== $name) {
                    throw BlockPackageException::single(BlockPackage::MANIFEST, 'The package is named ' . $package['name'] . ' but installed in site/blocks/' . $name . '. The folder must match the name.');
                }
                foreach (array_keys($package['blocks']) as $kind) {
                    if (isset($index['blocks'][$kind])) {
                        throw BlockPackageException::single('', 'The block ' . substr($kind, 6) . ' is already provided by the package ' . $index['blocks'][$kind]['package'] . '.');
                    }
                }
                if (count($index['packages']) >= self::MAX_PACKAGES || count($index['blocks']) + count($package['blocks']) > self::MAX_BLOCKS) {
                    throw BlockPackageException::single('', sprintf('A workspace holds at most %d packages and %d blocks.', self::MAX_PACKAGES, self::MAX_BLOCKS));
                }
            } catch (BlockPackageException $e) {
                foreach ($e->errors() as $error) {
                    $index['errors'][] = ['package' => $name] + $error;
                }
                continue;
            }
            foreach ($package['assets'] as $path => $file) {
                $index['assets'][$file] = $name . '/' . $path;
            }
            $index['blocks'] += $package['blocks'];
            $index['packages'][$name] = ['blocks' => array_keys($package['blocks'])] + $package;
            unset($index['packages'][$name]['assets']);
        }
        ksort($index['blocks']);

        return $index;
    }

    /** Sizes, times and inodes of every file below site/blocks, without reading them. */
    private function signature(): string
    {
        $parts = ['compiler ' . self::COMPILER];
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
            throw new \RuntimeException('Could not create the block lock folder.');
        }
        if (is_link($this->directory())) {
            throw new \InvalidArgumentException('The blocks folder cannot be a symbolic link.');
        }
        $lock = fopen($cache . '/blocks.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not open the block lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the block packages.');
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function remove(string $directory): void
    {
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
