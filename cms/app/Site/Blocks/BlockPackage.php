<?php

declare(strict_types=1);

namespace CometCMS\Site\Blocks;

use CometCMS\Core\HtmlSanitizer;

/**
 * A block package: comet-package.json, blocks/<name>/{block.json, template.html, style.css}
 * and assets/. Packages are data. Nothing in them is executed; templates and CSS
 * compile once, here, into the structures the renderer uses.
 */
final class BlockPackage
{
    public const API_VERSION = 1;
    public const MANIFEST = 'comet-package.json';
    public const MAX_FILES = 500;
    public const MAX_BYTES = 26214400;
    public const MAX_ASSET = 8388608;
    public const MAX_BLOCKS = 64;
    public const MAX_PROPS = 32;
    public const RESERVED_VENDORS = ['comet', 'core'];

    public const ASSET_TYPES = [
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'woff2' => 'font/woff2', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
    ];

    private const TEXT_LIMITS = ['comet-package.json' => 16384, 'block.json' => 32768, 'template.html' => 65536, 'style.css' => 131072];
    private const DOCUMENTS = ['README.md', 'LICENSE', 'LICENSE.md', 'LICENSE.txt', 'CHANGELOG.md'];
    private const DOCUMENT_LIMIT = 262144;
    private const PACKAGE_FIELDS = ['api_version', 'name', 'title', 'description', 'version', 'author', 'license', 'homepage'];
    private const BLOCK_FIELDS = ['title', 'description', 'icon', 'props'];
    private const PROP_FIELDS = ['type', 'label', 'default', 'max', 'min', 'options', 'fields', 'control'];
    public const PROP_TYPES = ['string', 'text', 'richtext', 'url', 'link', 'bool', 'int', 'enum', 'color', 'media', 'list'];
    /** Theme colors a color prop may default to; workspaces add their own custom colors. */
    public const COLOR_KEYS = ['primary', 'secondary', 'accent', 'background', 'surface', 'text', 'muted', 'border'];
    public const ICONS = ['icon.svg' => 'image/svg+xml', 'icon.png' => 'image/png'];
    /** What the asset route serves: assets, plus block scripts. */
    public const SERVED_TYPES = self::ASSET_TYPES + ['js' => 'text/javascript'];
    private const ICON_LIMIT = 65536;

    /** Names are vendor/name; each part starts with a letter and holds up to 40 lowercase letters, digits or hyphens. */
    public static function validName(mixed $name): bool
    {
        return is_string($name) && preg_match('~^[a-z][a-z0-9-]{0,39}/[a-z][a-z0-9-]{0,39}$~D', $name) === 1;
    }

    /**
     * Read a ZIP upload. A single top-level folder around the package is unwrapped,
     * and operating-system clutter (dotfiles, __MACOSX) is ignored.
     *
     * @return array<string, string> package path => bytes
     */
    public static function readZip(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to install block packages.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw BlockPackageException::single('', 'The upload is not a readable ZIP file.');
        }
        try {
            if ($zip->numFiles > self::MAX_FILES * 4) {
                throw BlockPackageException::single('', 'The ZIP holds too many entries.');
            }
            $entries = [];
            $errors = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = (string) ($stat['name'] ?? '');
                $attributes = 0;
                $system = 0;
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                if ($system === \ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0xF000) === 0xA000) {
                    $errors[] = ['file' => $name, 'line' => null, 'message' => 'Symbolic links are not allowed.'];
                    continue;
                }
                if (str_ends_with($name, '/')) continue;
                $problem = self::pathProblem($name);
                if ($problem === false) continue;
                if ($problem !== null) {
                    $errors[] = ['file' => $name, 'line' => null, 'message' => $problem];
                    continue;
                }
                $total += (int) ($stat['size'] ?? 0);
                if ((int) ($stat['size'] ?? 0) > self::MAX_ASSET || $total > self::MAX_BYTES) {
                    throw BlockPackageException::single($name, sprintf('Packages are limited to %d MiB and files to %d MiB.', self::MAX_BYTES >> 20, self::MAX_ASSET >> 20));
                }
                $entries[$name] = [$index, (int) $stat['size']];
            }
            if ($errors !== []) {
                throw new BlockPackageException($errors);
            }
            $entries = self::unwrap($entries);
            if (count($entries) > self::MAX_FILES) {
                throw BlockPackageException::single('', 'Packages hold at most ' . self::MAX_FILES . ' files.');
            }
            $files = [];
            foreach ($entries as $name => [$index, $size]) {
                $bytes = $size === 0 ? '' : $zip->getFromIndex($index, $size + 1);
                if (!is_string($bytes) || strlen($bytes) !== $size) {
                    throw BlockPackageException::single($name, 'The file could not be extracted.');
                }
                $files[$name] = $bytes;
            }

            return $files;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, string> package path => bytes */
    public static function readDirectory(string $directory): array
    {
        if (is_link($directory) || !is_dir($directory)) {
            throw BlockPackageException::single('', 'Package folders cannot be symbolic links.');
        }
        $files = [];
        $errors = [];
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $path => $info) {
            $name = str_replace('\\', '/', substr((string) $path, strlen($directory) + 1));
            $problem = self::pathProblem($name);
            if ($problem === false) continue;
            if ($info->isLink() || self::linkedParent($directory, $name)) {
                $errors[] = ['file' => $name, 'line' => null, 'message' => 'Symbolic links are not allowed.'];
            } elseif ($problem !== null) {
                $errors[] = ['file' => $name, 'line' => null, 'message' => $problem];
            } elseif (!$info->isFile() || $info->getSize() > self::MAX_ASSET || ($total += $info->getSize()) > self::MAX_BYTES || count($files) >= self::MAX_FILES) {
                throw BlockPackageException::single($name, 'The package exceeds its file count or size limits.');
            } else {
                $bytes = file_get_contents((string) $path);
                if ($bytes === false) throw BlockPackageException::single($name, 'The file could not be read.');
                $files[$name] = $bytes;
            }
        }
        if ($errors !== []) {
            throw new BlockPackageException($errors);
        }
        ksort($files);

        return $files;
    }

    /**
     * Validate a whole package and compile it, reporting every problem at once.
     *
     * @param array<string, string> $files package path => bytes
     */
    public static function compile(array $files): array
    {
        $errors = [];
        $blocks = [];
        $assets = [];
        $documents = [];
        $icons = [];
        $scripts = [];
        foreach ($files as $path => $bytes) {
            $role = self::role((string) $path);
            if (is_string($role)) {
                $errors[] = ['file' => $path, 'line' => null, 'message' => $role];
            } elseif ($role[0] === 'icon') {
                $documents[] = $path;
                if (isset($icons[$role[1]])) {
                    $errors[] = ['file' => $path, 'line' => null, 'message' => 'Use either icon.svg or icon.png, not both.'];
                }
                $icons[$role[1]] = self::icon($path, $bytes, $errors);
            } elseif ($role[0] === 'script') {
                foreach (BlockScripts::problems($bytes) as [$line, $message]) {
                    $errors[] = ['file' => $path, 'line' => $line, 'message' => $message];
                }
                $scripts[$role[1]] = hash('sha256', $bytes) . '.js';
            } elseif ($role[0] === 'asset') {
                $file = self::asset($path, $bytes, $errors);
                if ($file !== null) $assets[$path] = $file;
            } elseif ($role[0] === 'document') {
                $documents[] = $path;
                if (strlen($bytes) > self::DOCUMENT_LIMIT) $errors[] = ['file' => $path, 'line' => null, 'message' => 'Documents are limited to 256 KiB.'];
            } elseif ($role[0] === 'block' && strlen($bytes) <= self::TEXT_LIMITS[$role[2]]) {
                $blocks[$role[1]][$role[2]] = $bytes;
            } elseif ($role[0] === 'block' || strlen($bytes) > self::TEXT_LIMITS[basename($path)]) {
                $errors[] = ['file' => $path, 'line' => null, 'message' => sprintf('This file is limited to %d KiB.', self::TEXT_LIMITS[basename($path)] >> 10)];
            }
        }
        foreach ($files as $path => $bytes) {
            if (!isset($assets[$path]) && !in_array($path, $documents, true) && preg_match('//u', $bytes) !== 1) {
                $errors[] = ['file' => $path, 'line' => null, 'message' => 'Text files must be UTF-8.'];
            }
        }
        if (!isset($files[self::MANIFEST])) {
            $errors[] = ['file' => self::MANIFEST, 'line' => null, 'message' => 'Every package needs a ' . self::MANIFEST . ' at its root.'];
            throw new BlockPackageException($errors);
        }
        $package = self::packageManifest($files[self::MANIFEST], $errors);
        if ($blocks === []) {
            $errors[] = ['file' => 'blocks/', 'line' => null, 'message' => 'The package contains no blocks. Add blocks/<name>/block.json and blocks/<name>/template.html.'];
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            $errors[] = ['file' => 'blocks/', 'line' => null, 'message' => 'Packages hold at most ' . self::MAX_BLOCKS . ' blocks.'];
        }
        $vendor = explode('/', (string) ($package['name'] ?? 'invalid/'))[0];
        $compiled = [];
        ksort($blocks);
        foreach ($blocks as $slug => $parts) {
            $folder = 'blocks/' . $slug . '/';
            foreach (['block.json', 'template.html'] as $required) {
                if (!isset($parts[$required])) $errors[] = ['file' => $folder . $required, 'line' => null, 'message' => 'Every block needs ' . $required . '.'];
            }
            if (!isset($parts['block.json'], $parts['template.html'])) continue;
            $block = self::blockManifest($parts['block.json'], $folder . 'block.json', $errors);
            if ($block === null) continue;
            if (isset($icons[$slug]) && $block['icon'] !== '') {
                $errors[] = ['file' => $folder . 'block.json', 'line' => null, 'message' => 'The block has both an "icon" name and an icon file. Keep one.'];
            }
            $name = $vendor . '/' . $slug;
            $template = BlockTemplate::compile($parts['template.html'], $block['props'], $assets, $folder . 'template.html', $errors);
            $compiled['block:' . $name] = [
                'name' => $name,
                'package' => $package['name'] ?? '',
                'icon' => $icons[$slug] ?? ($block['icon'] !== '' ? $block['icon'] : ($icons[''] ?? '')),
                'slot' => BlockTemplate::hasSlot($template),
                'script' => $scripts[$slug] ?? '',
            ] + $block + [
                'template' => $template,
                'css' => self::css($parts['style.css'] ?? '', $folder . 'style.css', $assets, $errors),
            ];
        }
        foreach (array_diff_key($scripts, $blocks) as $slug => $_) {
            $errors[] = ['file' => 'blocks/' . $slug . '/view.js', 'line' => null, 'message' => 'There is no block "' . $slug . '" for this script. Add block.json and template.html next to it.'];
        }
        $css = self::css($files['style.css'] ?? '', 'style.css', $assets, $errors);
        if ($errors !== []) {
            throw new BlockPackageException($errors);
        }

        $scriptFiles = [];
        foreach ($scripts as $slug => $file) {
            $scriptFiles['blocks/' . $slug . '/view.js'] = $file;
        }

        return $package + ['css' => $css, 'assets' => $assets + $scriptFiles, 'blocks' => $compiled];
    }

    /**
     * What a package path is for: ['block', slug, file], ['asset'], ['document'], ['root'],
     * or a message explaining why the file does not belong.
     */
    private static function role(string $path): array|string
    {
        if (in_array($path, [self::MANIFEST, 'style.css'], true)) return ['root'];
        if (isset(self::ICONS[$path])) return ['icon', ''];
        if (in_array($path, self::DOCUMENTS, true)) return ['document'];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $segments = explode('/', $path);
        if ($segments[0] === 'blocks' && count($segments) === 3 && $segments[2] === 'view.js' && preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $segments[1])) {
            return ['script', $segments[1]];
        }
        if (in_array($extension, ['js', 'mjs', 'cjs', 'ts'], true)) {
            return 'A block\'s script is blocks/<name>/view.js, one self-contained module. Bundle other files into it.';
        }
        if (in_array($extension, ['php', 'phtml', 'phar'], true)) {
            return 'PHP files are not allowed in block packages.';
        }
        if ($segments[0] === 'assets' && count($segments) >= 2 && count($segments) <= 5) {
            return isset(self::ASSET_TYPES[$extension])
                ? ['asset']
                : 'Assets must be ' . implode(', ', array_keys(self::ASSET_TYPES)) . ' files.';
        }
        if ($segments[0] === 'blocks' && count($segments) === 3) {
            if (!preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $segments[1])) {
                return 'Block folder names start with a lowercase letter and hold up to 40 lowercase letters, digits or hyphens.';
            }
            if (in_array($segments[2], ['block.json', 'template.html', 'style.css'], true)) return ['block', $segments[1], $segments[2]];
            if (isset(self::ICONS[$segments[2]])) return ['icon', $segments[1]];
            if ($segments[2] === 'README.md') return ['document'];
            if ($segments[2] === 'template.mustache') return 'Rename template.mustache to template.html.';
        }

        return 'Unexpected file. Packages contain ' . self::MANIFEST . ', style.css, icon.svg, README/LICENSE/CHANGELOG, blocks/<name>/{block.json, template.html, style.css, view.js, icon.svg} and assets/.';
    }

    /** null when the path is fine, false when it is clutter to ignore, or a message. */
    private static function pathProblem(string $name): string|false|null
    {
        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '__MACOSX' || ($segment !== '' && $segment[0] === '.' && $segment !== '.' && $segment !== '..')) return false;
        }
        if (str_contains($name, '\\') || str_starts_with($name, '/') || in_array('', $segments, true) || in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return 'Unsafe path.';
        }
        foreach ($segments as $segment) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D', $segment)) {
                return 'File and folder names may only use letters, digits, dots, hyphens and underscores.';
            }
        }

        return null;
    }

    private static function linkedParent(string $root, string $name): bool
    {
        for ($parent = dirname($name); $parent !== '.'; $parent = dirname($parent)) {
            if (is_link($root . '/' . $parent)) return true;
        }

        return false;
    }

    /** @param array<string, array{int, int}> $entries */
    private static function unwrap(array $entries): array
    {
        if (isset($entries[self::MANIFEST]) || $entries === []) return $entries;
        $roots = array_unique(array_map(static fn(string $name): string => explode('/', $name)[0], array_keys($entries)));
        if (count($roots) !== 1 || !isset($entries[$roots[0] . '/' . self::MANIFEST])) return $entries;
        $unwrapped = [];
        foreach ($entries as $name => $entry) {
            $unwrapped[substr($name, strlen($roots[0]) + 1)] = $entry;
        }

        return $unwrapped;
    }

    private static function packageManifest(string $json, array &$errors): array
    {
        $manifest = self::json($json, self::MANIFEST, $errors);
        if ($manifest === null) return [];
        $error = static function (string $message) use (&$errors): void {
            $errors[] = ['file' => self::MANIFEST, 'line' => null, 'message' => $message];
        };
        foreach (array_diff(array_keys($manifest), self::PACKAGE_FIELDS) as $unknown) {
            $error('Unknown field "' . $unknown . '". Allowed: ' . implode(', ', self::PACKAGE_FIELDS) . '.');
        }
        if (($manifest['api_version'] ?? null) !== self::API_VERSION) {
            $error('api_version must be ' . self::API_VERSION . '.');
        }
        $name = $manifest['name'] ?? null;
        if (!self::validName($name)) {
            $error('name must be "vendor/package": lowercase letters, digits and hyphens, each part starting with a letter.');
        } elseif (in_array(explode('/', $name)[0], self::RESERVED_VENDORS, true)) {
            $error('The vendor "' . explode('/', $name)[0] . '" is reserved for CometCMS.');
        }
        if (!is_string($manifest['version'] ?? null) || !preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}(?:-[a-zA-Z0-9.-]{1,40})?$/D', $manifest['version'])) {
            $error('version must be a semantic version such as 1.0.0 or 1.1.0-beta.1.');
        }
        self::text($manifest, 'title', 120, true, $error);
        foreach (['description' => 500, 'author' => 200, 'license' => 100] as $field => $max) {
            self::text($manifest, $field, $max, false, $error);
        }
        if (isset($manifest['homepage']) && (!is_string($manifest['homepage']) || strlen($manifest['homepage']) > 2000
            || !preg_match('~^https?://~i', $manifest['homepage']) || !HtmlSanitizer::isSafeUrl($manifest['homepage']))) {
            $error('homepage must be an http(s) URL.');
        }

        return [
            'name' => is_string($name) ? $name : '',
            'title' => is_string($manifest['title'] ?? null) ? trim($manifest['title']) : '',
            'description' => is_string($manifest['description'] ?? null) ? trim($manifest['description']) : '',
            'version' => is_string($manifest['version'] ?? null) ? $manifest['version'] : '',
            'author' => is_string($manifest['author'] ?? null) ? trim($manifest['author']) : '',
            'license' => is_string($manifest['license'] ?? null) ? trim($manifest['license']) : '',
            'homepage' => is_string($manifest['homepage'] ?? null) ? $manifest['homepage'] : '',
        ];
    }

    private static function blockManifest(string $json, string $file, array &$errors): ?array
    {
        $before = count($errors);
        $manifest = self::json($json, $file, $errors);
        if ($manifest === null) return null;
        $error = static function (string $message) use ($file, &$errors): void {
            $errors[] = ['file' => $file, 'line' => null, 'message' => $message];
        };
        foreach (array_diff(array_keys($manifest), self::BLOCK_FIELDS) as $unknown) {
            $error($unknown === 'name'
                ? 'Remove "name": a block is named after its package vendor and folder, for example blocks/callout becomes cometcms/callout.'
                : 'Unknown field "' . $unknown . '". Allowed: ' . implode(', ', self::BLOCK_FIELDS) . '.');
        }
        self::text($manifest, 'title', 120, true, $error);
        self::text($manifest, 'description', 500, false, $error);
        $icon = $manifest['icon'] ?? '';
        if (!is_string($icon) || ($icon !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*:[a-z0-9]+(?:-[a-z0-9]+)*$/D', $icon))) {
            $error('icon must be an Iconify icon name such as "mdi:bullhorn-outline", or put an icon.svg in the block folder.');
        }
        $props = [];
        $declared = $manifest['props'] ?? [];
        if (!is_array($declared) || ($declared !== [] && array_is_list($declared))) {
            $error('props must be an object keyed by prop name, for example {"title": {"type": "string", "label": "Title"}}.');
            $declared = [];
        } elseif (count($declared) > self::MAX_PROPS) {
            $error('Blocks have at most ' . self::MAX_PROPS . ' props.');
        }
        foreach ($declared as $key => $spec) {
            $prop = self::prop((string) $key, $spec, static fn(string $message) => $error('props.' . $key . ': ' . $message));
            if ($prop !== null) $props[$key] = $prop;
        }
        if (count($errors) > $before) return null;

        return ['title' => trim($manifest['title']), 'description' => trim($manifest['description'] ?? ''), 'icon' => $icon, 'props' => $props];
    }

    /** A complete prop spec, with the documented defaults filled in. List fields use the same rules, minus lists. */
    private static function prop(string $key, mixed $spec, callable $error, bool $field = false): ?array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) || in_array($key, ['constructor', 'prototype'], true)) {
            $error('prop names start with a lowercase letter and use lowercase letters, digits and underscores (up to 64).');
            return null;
        }
        if (!is_array($spec) || array_is_list($spec)) {
            $error('each prop is an object with at least "type" and "label".');
            return null;
        }
        $valid = true;
        $fail = static function (string $message) use ($error, &$valid): void {
            $error($message);
            $valid = false;
        };
        foreach (array_diff(array_keys($spec), self::PROP_FIELDS) as $unknown) {
            $fail('unknown field "' . $unknown . '". Allowed: ' . implode(', ', self::PROP_FIELDS) . '.');
        }
        $type = $spec['type'] ?? null;
        $types = $field ? array_diff(self::PROP_TYPES, ['list']) : self::PROP_TYPES;
        if (!in_array($type, $types, true)) {
            $fail('type must be one of ' . implode(', ', $types) . '.' . ($field && $type === 'list' ? ' Lists cannot contain lists.' : ''));
            return null;
        }
        if (!is_string($spec['label'] ?? null) || trim($spec['label']) === '' || strlen($spec['label']) > 120) {
            $fail('label is required text of at most 120 bytes.');
        }
        $prop = ['type' => $type, 'label' => trim((string) ($spec['label'] ?? ''))];
        if (array_key_exists('control', $spec)) {
            if ($type !== 'int' || !in_array($spec['control'], ['number', 'slider'], true)) {
                $fail('control is only supported on int props and must be "number" or "slider".');
            } else {
                $prop['control'] = $spec['control'];
            }
        }
        $int = static fn(string $name, int $min, int $max, int $fallback) => !array_key_exists($name, $spec) ? $fallback
            : (is_int($spec[$name]) && $spec[$name] >= $min && $spec[$name] <= $max ? $spec[$name] : $fail(sprintf('%s must be an integer from %d to %d.', $name, $min, $max)));
        $allowed = match ($type) {
            'string', 'url', 'text' => ['max'], 'int' => ['min', 'max'], 'enum' => ['options'], 'list' => ['fields', 'max'], default => [],
        };
        foreach (['min', 'max', 'options', 'fields'] as $name) {
            if (array_key_exists($name, $spec) && !in_array($name, $allowed, true)) $fail($name . ' does not apply to ' . $type . ' props.');
        }
        $default = $spec['default'] ?? null;
        switch ($type) {
            case 'string':
            case 'url':
                $prop['max'] = $int('max', 1, 5000, $type === 'url' ? 2000 : 500);
                $prop['default'] = $default ?? '';
                break;
            case 'text':
                $prop['max'] = $int('max', 1, 20000, 2000);
                $prop['default'] = $default ?? '';
                break;
            case 'richtext':
            case 'link':
                $prop['default'] = self::convertDefault($type, $default);
                break;
            case 'bool':
                $prop['default'] = $default ?? false;
                break;
            case 'int':
                $prop['min'] = $int('min', -1000000, 1000000, -1000000);
                $prop['max'] = $int('max', -1000000, 1000000, 1000000);
                $prop['default'] = $default ?? max((int) $prop['min'], min(0, (int) $prop['max']));
                if (is_int($prop['min']) && is_int($prop['max']) && $prop['min'] > $prop['max']) $fail('min cannot be greater than max.');
                break;
            case 'enum':
                $options = $spec['options'] ?? null;
                if (!is_array($options) || !array_is_list($options) || $options === [] || count($options) > 50 || count(array_unique($options, SORT_REGULAR)) !== count($options)
                    || array_filter($options, static fn(mixed $option): bool => !is_string($option) || !preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $option)) !== []) {
                    $fail('options must be a list of 1 to 50 distinct values made of letters, digits, hyphens and underscores, for example ["info", "success"].');
                    return null;
                }
                $prop['options'] = $options;
                $prop['default'] = $default ?? $options[0];
                break;
            case 'color':
            case 'media':
                $prop['default'] = $default ?? '';
                break;
            case 'list':
                $fields = $spec['fields'] ?? null;
                if (!is_array($fields) || $fields === [] || array_is_list($fields) || count($fields) > 16) {
                    $fail('fields must be an object of 1 to 16 item fields, for example {"title": {"type": "string", "label": "Title"}}.');
                    return null;
                }
                $prop['max'] = $int('max', 1, 100, 20);
                $prop['fields'] = [];
                foreach ($fields as $name => $fieldSpec) {
                    $compiled = self::prop((string) $name, $fieldSpec, static fn(string $message) => $fail('fields.' . $name . ': ' . $message), true);
                    if ($compiled !== null) $prop['fields'][$name] = $compiled;
                }
                if (!$valid) return null;
                $prop['default'] = is_array($default) && array_is_list($default) ? array_map(static fn(mixed $item): mixed => self::listItem($prop['fields'], $item), $default) : ($default ?? []);
                break;
        }
        $problem = $valid ? self::defaultProblem($prop, $prop['default']) : null;
        if ($problem !== null) $fail('default ' . $problem);
        if ($type === 'list' && $valid) {
            $prop['default'] = array_map(static function (array $item): array {
                unset($item['_unknown']);
                return $item;
            }, $prop['default']);
        }

        return $valid ? $prop : null;
    }

    /** A list item default with missing fields defaulted; unknown fields are kept aside to report them. */
    private static function listItem(array $fields, mixed $item): mixed
    {
        // JSON's {} decodes to an empty array, which is an empty item, not a list.
        if (!is_array($item) || ($item !== [] && array_is_list($item))) return $item;
        $values = [];
        foreach ($fields as $name => $spec) {
            $values[$name] = array_key_exists($name, $item) ? self::convertDefault($spec['type'], $item[$name]) : $spec['default'];
        }

        return $values + ['_unknown' => array_diff_key($item, $fields)];
    }

    /** Defaults in their stored form: sanitized HTML, and links from a suggested URL. */
    private static function convertDefault(string $type, mixed $value): mixed
    {
        return match ($type) {
            'richtext' => is_string($value) ? HtmlSanitizer::sanitize($value) : ($value ?? ''),
            'link' => $value === null || $value === '' ? ['type' => 'none'] : (is_string($value) ? ['type' => 'url', 'url' => trim($value)] : $value),
            default => $value,
        };
    }

    /** Why a default does not fit its prop, or null when it does. */
    private static function defaultProblem(array $prop, mixed $value): ?string
    {
        return match ($prop['type']) {
            'string' => is_string($value) && strlen($value) <= $prop['max'] && !str_contains($value, "\n") ? null : 'must be single-line text no longer than max.',
            'text' => is_string($value) && strlen($value) <= $prop['max'] ? null : 'must be text no longer than max.',
            'richtext' => is_string($value) && strlen($value) <= 50000 ? null : 'must be HTML text.',
            'url' => is_string($value) && strlen($value) <= $prop['max'] && HtmlSanitizer::isSafeUrl(trim($value)) ? null : 'must be a relative, http(s), mailto or tel URL.',
            'link' => ($value['type'] ?? '') === 'none' || (($value['type'] ?? '') === 'url' && is_string($value['url'] ?? null) && HtmlSanitizer::isSafeUrl($value['url'])) ? null : 'must be a URL.',
            'bool' => is_bool($value) ? null : 'must be true or false.',
            'int' => is_int($value) && $value >= (int) $prop['min'] && $value <= (int) $prop['max'] ? null : 'must be an integer between min and max.',
            'enum' => in_array($value, $prop['options'], true) ? null : 'must be one of the options.',
            'color' => $value === '' || in_array($value, self::COLOR_KEYS, true) ? null : 'must be empty or a theme color: ' . implode(', ', self::COLOR_KEYS) . '.',
            'media' => $value === '' ? null : 'is not supported: media comes from the library. Show a fallback with {{^name}}…{{/name}}.',
            'list' => self::listProblem($prop, $value),
            default => 'is invalid.',
        };
    }

    private static function listProblem(array $prop, mixed $items): ?string
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > $prop['max']) {
            return 'must be a list of at most ' . $prop['max'] . ' items.';
        }
        foreach ($items as $index => $item) {
            if (!is_array($item) || ($item !== [] && array_is_list($item))) return 'item ' . $index . ' must be an object of field values.';
            foreach (array_keys($item['_unknown'] ?? []) as $unknown) return 'item ' . $index . ' has no field "' . $unknown . '".';
            foreach ($prop['fields'] as $name => $field) {
                $problem = self::defaultProblem($field, $item[$name]);
                if ($problem !== null) return 'item ' . $index . ': ' . $name . ' ' . $problem;
            }
        }

        return null;
    }

    /**
     * CSS is trusted like theme CSS, but must not close its style element. url(assets/…)
     * references become published asset URLs; other relative URLs would resolve against
     * each page and are rejected.
     *
     * @return list<string|array{0: string, 1: string}> text and ['asset', file] parts
     */
    private static function css(string $css, string $file, array $assets, array &$errors): array
    {
        $position = strpos($css, '<');
        if ($position !== false) {
            $errors[] = ['file' => $file, 'line' => substr_count($css, "\n", 0, $position) + 1, 'message' => 'CSS cannot contain "<". Escape it as \3C in strings.'];
            return [];
        }
        $parts = [];
        $offset = 0;
        preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $css, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $url = trim($match[2][0]);
            $line = substr_count($css, "\n", 0, $match[0][1]) + 1;
            if (str_starts_with($url, 'assets/')) {
                if (!isset($assets[$url])) {
                    $errors[] = ['file' => $file, 'line' => $line, 'message' => 'The package has no file ' . $url . '.'];
                    continue;
                }
                $parts[] = substr($css, $offset, $match[0][1] - $offset) . 'url("';
                $parts[] = ['asset', $assets[$url]];
                $parts[] = '")';
                $offset = $match[0][1] + strlen($match[0][0]);
            } elseif ($url !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|#)~i', $url)) {
                $errors[] = ['file' => $file, 'line' => $line, 'message' => 'The relative URL "' . $url . '" would resolve against each page\'s address. Reference package files as url(assets/…).'];
            }
        }
        $parts[] = substr($css, $offset);

        return array_values(array_filter($parts, static fn(string|array $part): bool => $part !== ''));
    }

    /** A block or package icon, inlined for the editor. It is shown as a mask, so only its shape matters. */
    private static function icon(string $path, string $bytes, array &$errors): string
    {
        $type = self::ICONS[basename($path)];
        $valid = strlen($bytes) <= self::ICON_LIMIT && ($type === 'image/png'
            ? str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
            : preg_match('/<svg[\s>]/i', substr($bytes, 0, 4096)) === 1 && preg_match('/<script|\son[a-z]+\s*=|<foreignObject/i', $bytes) !== 1);
        if (!$valid) {
            $errors[] = ['file' => $path, 'line' => null, 'message' => 'Icons must be an SVG or PNG of at most 64 KiB, without scripts.'];
            return '';
        }

        return 'data:' . $type . ';base64,' . base64_encode($bytes);
    }

    /** Store each asset under its content hash, after checking it is the type its name says. */
    private static function asset(string $path, string $bytes, array &$errors): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $valid = match ($extension) {
            'svg' => preg_match('/<svg[\s>]/i', substr($bytes, 0, 4096)) === 1,
            'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'gif' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
            'webp' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
            'avif' => in_array(substr($bytes, 4, 8), ['ftypavif', 'ftypavis'], true),
            'woff2' => str_starts_with($bytes, 'wOF2'),
            'mp4' => substr($bytes, 4, 4) === 'ftyp',
            'webm' => str_starts_with($bytes, "\x1A\x45\xDF\xA3"),
            default => false,
        };
        if (!$valid) {
            $errors[] = ['file' => $path, 'line' => null, 'message' => 'The file content does not match its .' . $extension . ' extension.'];
            return null;
        }

        return hash('sha256', $bytes) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
    }

    private static function json(string $json, string $file, array &$errors): ?array
    {
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $errors[] = ['file' => $file, 'line' => null, 'message' => 'Invalid JSON: ' . $e->getMessage() . '.'];
            return null;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = ['file' => $file, 'line' => null, 'message' => 'The file must contain a JSON object.'];
            return null;
        }

        return $value;
    }

    private static function text(array $manifest, string $field, int $max, bool $required, callable $error): void
    {
        $value = $manifest[$field] ?? null;
        if (($value === null && $required) || ($value !== null && (!is_string($value) || ($required && trim($value) === '') || strlen($value) > $max))) {
            $error($field . ' must be ' . ($required ? 'non-empty ' : '') . 'text of at most ' . $max . ' bytes.');
        }
    }
}
