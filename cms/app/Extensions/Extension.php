<?php

declare(strict_types=1);

namespace CometCMS\Extensions;

use CometCMS\Core\HtmlSanitizer;
use CometCMS\Core\Security;
use CometCMS\Site\FontFiles;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\Icons;
use CometCMS\Site\Shadow;
use CometCMS\Site\SiteRepository;
use CometCMS\Site\Theme;
use CometCMS\Site\Blocks\BlockScripts;
use CometCMS\Site\Blocks\BlockTemplate;

/**
 * An extension: a manifest, optional blocks, assets and site-content seeds.
 * Templates and CSS compile here; optional trusted view.js modules run in the browser.
 */
final class Extension
{
    public const API_VERSION = 1;
    public const MANIFEST = 'comet-extension.json';
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

    private const TEXT_LIMITS = ['comet-extension.json' => 16384, 'block.json' => 32768, 'template.html' => 65536, 'style.css' => 131072];
    private const DOCUMENTS = ['README.md', 'LICENSE', 'LICENSE.md', 'LICENSE.txt', 'CHANGELOG.md'];
    private const SEED_LIMIT = 1048576;
    private const DOCUMENT_LIMIT = 262144;
    // "$schema" lets editors and agents validate the JSON while writing it; CometCMS ignores it.
    public const EXTENSION_FIELDS = ['$schema', 'api_version', 'name', 'title', 'description', 'version', 'author', 'license', 'homepage', 'requires', 'cms', 'permissions', 'settings', 'shared_props'];
    public const BLOCK_FIELDS = ['$schema', 'title', 'description', 'icon', 'category', 'keywords', 'deprecated', 'aliases', 'allowed_children', 'variations', 'props'];
    public const PROP_FIELDS = ['use', 'type', 'label', 'default', 'max', 'min', 'options', 'fields', 'control', 'group', 'help', 'placeholder', 'visible_when', 'option_labels', 'option_aliases', 'renamed_from', 'deprecated'];
    /** Prop fields a block may set on top of a shared prop it uses. */
    private const OVERRIDABLE_TEXTS = ['label', 'help', 'placeholder', 'group'];
    public const MAX_SHARED_PROPS = 32;
    /** Prop types an extension setting can have: plain values an administrator sets once per workspace. */
    public const SETTING_TYPES = ['string', 'text', 'url', 'bool', 'int', 'enum', 'color'];
    public const MAX_SETTINGS = 32;
    /** CSP directives an extension can extend for its block scripts. */
    public const PERMISSIONS = ['connect'];
    /** Editor texts translations may replace, per prop. */
    private const PROP_TEXTS = ['label', 'help', 'placeholder', 'group'];
    public const PROP_TYPES = ['string', 'text', 'richtext', 'url', 'link', 'bool', 'int', 'enum', 'icon', 'color', 'space', 'radius', 'shadow', 'media', 'menu', 'list'];
    /** Prop types that hold a theme value and print its CSS in style custom properties. */
    public const THEME_TYPES = ['color', 'space', 'radius', 'shadow'];
    /** Theme colors a color prop may default to; workspaces add their own custom colors. */
    public const COLOR_KEYS = ['primary', 'secondary', 'accent', 'background', 'surface', 'text', 'muted', 'border'];
    public const ICONS = ['icon.svg' => 'image/svg+xml', 'icon.png' => 'image/png'];
    /** What the asset route serves: assets, plus block scripts. */
    public const SERVED_TYPES = self::ASSET_TYPES + ['js' => 'text/javascript'];
    private const ICON_LIMIT = 65536;

    /**
     * The values a space or radius prop offers: the theme tokens of the matching
     * Style control, so blocks and the builder stay in step. '' is "not set".
     */
    public static function themeOptions(string $type): array
    {
        $style = LayoutSchema::styleSpec();

        return match ($type) {
            'space' => $style['padding_top']['options'],
            'radius' => $style['radius']['options'],
            default => [],
        };
    }

    /** The options a prop can be compared with: an enum's own, or the theme tokens of space and radius props. */
    public static function options(array $spec): array
    {
        return $spec['type'] === 'enum' ? $spec['options'] : self::themeOptions($spec['type']);
    }

    /** The running CometCMS release, which a manifest's "cms" constraint is checked against. */
    public static function cmsVersion(): string
    {
        $version = function_exists('comet_version') ? \comet_version() : '';
        // Build metadata ("+abc123") and other suffixes do not take part in constraints.
        return preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}(?:-[a-zA-Z0-9.-]{1,40})?/', $version, $match) ? $match[0] : '0.0.0';
    }

    /** Names are vendor/name; each part starts with a letter and holds up to 40 lowercase letters, digits or hyphens. */
    public static function validName(mixed $name): bool
    {
        return is_string($name) && preg_match('~^[a-z][a-z0-9-]{0,39}/[a-z][a-z0-9-]{0,39}$~D', $name) === 1;
    }

    /**
     * Read a ZIP upload. A single top-level folder around the extension is unwrapped,
     * and operating-system clutter (dotfiles, __MACOSX) is ignored.
     *
     * @return array<string, string> extension path => bytes
     */
    public static function readZip(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to install extensions.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw ExtensionException::single('', 'The upload is not a readable ZIP file.');
        }
        try {
            if ($zip->numFiles > self::MAX_FILES * 4) {
                throw ExtensionException::single('', 'The ZIP holds too many entries.');
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
                    throw ExtensionException::single($name, sprintf('Extensions are limited to %d MiB and files to %d MiB.', self::MAX_BYTES >> 20, self::MAX_ASSET >> 20));
                }
                $entries[$name] = [$index, (int) $stat['size']];
            }
            if ($errors !== []) {
                throw new ExtensionException($errors);
            }
            $entries = self::unwrap($entries);
            if (count($entries) > self::MAX_FILES) {
                throw ExtensionException::single('', 'Extensions hold at most ' . self::MAX_FILES . ' files.');
            }
            $files = [];
            foreach ($entries as $name => [$index, $size]) {
                $bytes = $size === 0 ? '' : $zip->getFromIndex($index, $size + 1);
                if (!is_string($bytes) || strlen($bytes) !== $size) {
                    throw ExtensionException::single($name, 'The file could not be extracted.');
                }
                $files[$name] = $bytes;
            }

            return $files;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, string> extension path => bytes */
    public static function readDirectory(string $directory): array
    {
        if (is_link($directory) || !is_dir($directory)) {
            throw ExtensionException::single('', 'Extension folders cannot be symbolic links.');
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
                throw ExtensionException::single($name, 'The extension exceeds its file count or size limits.');
            } else {
                $bytes = file_get_contents((string) $path);
                if ($bytes === false) throw ExtensionException::single($name, 'The file could not be read.');
                $files[$name] = $bytes;
            }
        }
        if ($errors !== []) {
            throw new ExtensionException($errors);
        }
        ksort($files);

        return $files;
    }

    /**
     * Validate a whole extension and compile it, reporting every problem at once.
     *
     * @param array<string, string> $files extension path => bytes
     */
    public static function compile(array $files): array
    {
        $errors = [];
        $blocks = [];
        $assets = [];
        $documents = [];
        $icons = [];
        $extensionIcons = [];
        $scripts = [];
        $seeds = [];
        $presetIds = [];
        $translations = [];
        if (count($files) > self::MAX_FILES || array_sum(array_map('strlen', $files)) > self::MAX_BYTES) {
            throw ExtensionException::single('', 'The extension exceeds its file count or size limits.');
        }
        foreach ($files as $path => $bytes) {
            $problem = self::pathProblem((string) $path);
            if ($problem !== null) {
                if ($problem !== false) $errors[] = ['file' => $path, 'line' => null, 'message' => $problem];
                continue;
            }
            if (strlen($bytes) > self::MAX_ASSET) {
                $errors[] = ['file' => $path, 'line' => null, 'message' => 'Files are limited to 8 MiB.'];
                continue;
            }
            $role = self::role((string) $path);
            if (is_string($role)) {
                $errors[] = ['file' => $path, 'line' => null, 'message' => $role];
            } elseif ($role[0] === 'seed') {
                if (strlen($bytes) > self::SEED_LIMIT) {
                    $errors[] = ['file' => $path, 'line' => null, 'message' => 'Seed files are limited to 1 MiB.'];
                    continue;
                }
                $data = self::json($bytes, $path, $errors);
                if ($data !== null) {
                    $kind = $role[1];
                    $id = $role[2];
                    if ($kind === 'presets') {
                        if (!is_string($data['name'] ?? null) || trim($data['name']) === '' || strlen($data['name']) > 120) {
                            $errors[] = ['file' => $path, 'line' => null, 'message' => 'A theme preset needs a name of at most 120 bytes.'];
                            continue;
                        }
                        $data['name'] = trim($data['name']);
                        $id = SiteRepository::componentId($data['name'], static fn(string $id): bool => false);
                        if (isset($presetIds[$id])) {
                            $errors[] = ['file' => $path, 'line' => null, 'message' => 'Theme preset names must produce distinct ids.'];
                            continue;
                        }
                        $presetIds[$id] = true;
                    }
                    if ($kind === 'content_types' && isset($data['name']) && $data['name'] !== $id) {
                        $errors[] = ['file' => $path, 'line' => null, 'message' => 'The content type name must match its file name.'];
                    }
                    $seeds[] = ['kind' => $kind, 'id' => $id, 'name' => $data['label'] ?? $data['name'] ?? $id, 'data' => $data, 'source' => $path];
                }
            } elseif ($role[0] === 'icon') {
                $documents[] = $path;
                if ($role[1] !== '' && isset($icons[$role[1]])) {
                    $errors[] = ['file' => $path, 'line' => null, 'message' => 'Use either icon.svg or icon.png, not both.'];
                }
                $icon = self::icon($path, $bytes, $errors);
                // PNG artwork identifies the extension; an optional root SVG
                // keeps the monochrome fallback used by its block palette.
                if ($role[1] === '') $extensionIcons[$path] = $icon;
                if ($role[1] !== '' || $path === 'icon.svg' || !isset($icons[''])) $icons[$role[1]] = $icon;
            } elseif ($role[0] === 'script') {
                foreach (BlockScripts::problems($bytes) as [$line, $message]) {
                    $errors[] = ['file' => $path, 'line' => $line, 'message' => $message];
                }
                $scripts[$role[1]] = hash('sha256', $bytes) . '.js';
            } elseif ($role[0] === 'asset') {
                $file = self::asset($path, $bytes, $errors);
                if ($file !== null) $assets[$path] = $file;
            } elseif ($role[0] === 'i18n') {
                if (strlen($bytes) > self::TEXT_LIMITS['block.json']) {
                    $errors[] = ['file' => $path, 'line' => null, 'message' => 'Translation files are limited to 32 KiB.'];
                    continue;
                }
                $translations[$role[1]] = $bytes;
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
            $errors[] = ['file' => self::MANIFEST, 'line' => null, 'message' => 'Every extension needs a ' . self::MANIFEST . ' at its root.'];
            throw new ExtensionException($errors);
        }
        $extension = self::extensionManifest($files[self::MANIFEST], $errors);
        if (count($blocks) > self::MAX_BLOCKS) {
            $errors[] = ['file' => 'blocks/', 'line' => null, 'message' => 'Extensions hold at most ' . self::MAX_BLOCKS . ' blocks.'];
        }
        $vendor = explode('/', (string) ($extension['name'] ?? 'invalid/'))[0];
        foreach ($seeds as $index => $seed) {
            if ($seed['kind'] !== 'fonts') continue;
            $font = self::font($seed['data'], $seed['source'], $files, $assets, $errors);
            if ($font === null) {
                unset($seeds[$index]);
                continue;
            }
            // A stable site font id per extension and file, so updates refresh the same family.
            $seeds[$index] = ['id' => 'custom-' . substr(hash('sha256', ($extension['name'] ?? '') . '/' . $seed['id']), 0, 24), 'name' => $font['name'], 'data' => $font] + $seed;
        }
        $seeds = array_values($seeds);
        // Presets name bundled fonts by file name; installation knows them by their site id.
        $fontIds = [];
        foreach ($seeds as $seed) if ($seed['kind'] === 'fonts') $fontIds[basename($seed['source'], '.json')] = $seed['id'];
        foreach ($seeds as &$seed) {
            if ($seed['kind'] !== 'presets' || !is_array($seed['data']['theme']['fonts'] ?? null)) continue;
            foreach ($seed['data']['theme']['fonts'] as &$choice) {
                if (is_string($choice) && !isset(Theme::FONT_STACKS[$choice]) && isset($fontIds[$choice])) $choice = $fontIds[$choice];
            }
            unset($choice);
        }
        unset($seed);
        $compiled = [];
        ksort($blocks);
        foreach ($blocks as $slug => $parts) {
            $folder = 'blocks/' . $slug . '/';
            foreach (['block.json', 'template.html'] as $required) {
                if (!isset($parts[$required])) $errors[] = ['file' => $folder . $required, 'line' => null, 'message' => 'Every block needs ' . $required . '.'];
            }
            if (!isset($parts['block.json'], $parts['template.html'])) continue;
            $block = self::blockManifest($parts['block.json'], $folder . 'block.json', $errors, $extension['shared_props'] ?? []);
            if ($block === null) continue;
            if (isset($icons[$slug]) && $block['icon'] !== '') {
                $errors[] = ['file' => $folder . 'block.json', 'line' => null, 'message' => 'The block has both an "icon" name and an icon file. Keep one.'];
            }
            $name = $vendor . '/' . $slug;
            $template = BlockTemplate::compile($parts['template.html'], $block['props'], $assets, $folder . 'template.html', $errors, $extension['settings'] ?? []);
            foreach ($block['aliases'] as $alias) {
                if (explode('/', $alias)[0] !== $vendor || $alias === $name) {
                    $errors[] = ['file' => $folder . 'block.json', 'line' => null, 'message' => 'aliases: "' . $alias . '" must be an earlier name of this block from the vendor ' . $vendor . ', such as ' . $vendor . '/old-' . $slug . '.'];
                }
            }
            if ($block['allowed_children'] !== [] && !BlockTemplate::hasSlot($template) && $template !== []) {
                $errors[] = ['file' => $folder . 'block.json', 'line' => null, 'message' => 'allowed_children only applies to blocks whose template has a <slot></slot>.'];
            }
            $compiled['block:' . $name] = [
                'name' => $name,
                'extension' => $extension['name'] ?? '',
                'icon' => $icons[$slug] ?? ($block['icon'] !== '' ? $block['icon'] : ($icons[''] ?? '')),
                'slot' => BlockTemplate::hasSlot($template),
                'script' => $scripts[$slug] ?? '',
            ] + $block + [
                'template' => $template,
                'css' => self::css($parts['style.css'] ?? '', $folder . 'style.css', $assets, $errors),
            ];
        }
        $aliases = [];
        foreach ($compiled as $kind => $block) {
            foreach ($block['aliases'] as $alias) {
                if (isset($compiled['block:' . $alias]) || isset($aliases[$alias])) {
                    $errors[] = ['file' => 'blocks/' . explode('/', $block['name'])[1] . '/block.json', 'line' => null, 'message' => 'aliases: ' . $alias . ' is already the name or alias of another block in this extension.'];
                }
                $aliases[$alias] = $kind;
            }
        }
        if (($extension['permissions'] ?? []) !== [] && $scripts === []) {
            $errors[] = ['file' => self::MANIFEST, 'line' => null, 'message' => 'permissions only apply to block scripts (blocks/<name>/view.js), and this extension has none. Remove permissions.'];
        }
        self::translations($translations, $extension, $compiled, $vendor, $errors);
        foreach ($compiled as &$block) {
            $block['props'] = self::withoutOwnTexts($block['props']);
        }
        unset($block);
        unset($extension['shared_props']);
        foreach (array_diff_key($scripts, $blocks) as $slug => $_) {
            $errors[] = ['file' => 'blocks/' . $slug . '/view.js', 'line' => null, 'message' => 'There is no block "' . $slug . '" for this script. Add block.json and template.html next to it.'];
        }
        $css = self::css($files['style.css'] ?? '', 'style.css', $assets, $errors);
        if ($errors !== []) {
            throw new ExtensionException($errors);
        }

        $scriptFiles = [];
        foreach ($scripts as $slug => $file) {
            $scriptFiles['blocks/' . $slug . '/view.js'] = $file;
        }

        return $extension + ['icon' => $extensionIcons['icon.png'] ?? $extensionIcons['icon.svg'] ?? '', 'css' => $css, 'assets' => $assets + $scriptFiles, 'blocks' => $compiled, 'seeds' => $seeds];
    }

    /**
     * What a extension path is for: ['block', slug, file], ['asset'], ['document'], ['root'],
     * or a message explaining why the file does not belong.
     */
    private static function role(string $path): array|string
    {
        if (in_array($path, [self::MANIFEST, 'style.css'], true)) return ['root'];
        if ($path === 'theme.json') return ['seed', 'presets', ''];
        if (preg_match('~^(content-types|components|presets|fonts)/([^/]+)\.json$~D', $path, $match)) {
            $id = $match[2];
            try {
                Security::assertSafeName($id);
            } catch (\InvalidArgumentException) {
                return 'Invalid seed name.';
            }
            if (in_array($match[1], ['components', 'presets', 'fonts'], true) && ($id === '' || LayoutSchema::key($id) !== $id)) return 'Invalid seed id.';
            return ['seed', $match[1] === 'content-types' ? 'content_types' : $match[1], $id];
        }
        if (preg_match('~^templates/([^/]+)/([^/]+)\.json$~D', $path, $match)) {
            try {
                Security::assertSafeName($match[1]);
                Security::assertSafeName($match[2]);
            } catch (\InvalidArgumentException) {
                return 'Invalid template name.';
            }
            return ['seed', 'templates', $match[1] . '/' . $match[2]];
        }
        if (preg_match('~^i18n/([^/]+)\.json$~D', $path, $match)) {
            return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D', $match[1]) === 1
                ? ['i18n', $match[1]]
                : 'Translation files are named after a lowercase language code, such as i18n/de.json or i18n/pt-br.json.';
        }
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
            return 'PHP files are not allowed in extensions.';
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

        return 'Unexpected file. Extensions contain ' . self::MANIFEST . ', style.css, icon.svg/icon.png, README/LICENSE/CHANGELOG, i18n/<locale>.json, blocks/<name>/{block.json, template.html, style.css, view.js, icon.svg} assets/, content-types/<name>.json, components/<id>.json, templates/<type>/<variant>.json, presets/<id>.json, fonts/<id>.json and theme.json.';
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

    private static function extensionManifest(string $json, array &$errors): array
    {
        $manifest = self::json($json, self::MANIFEST, $errors);
        if ($manifest === null) return [];
        $error = static function (string $message) use (&$errors): void {
            $errors[] = ['file' => self::MANIFEST, 'line' => null, 'message' => $message];
        };
        foreach (array_diff(array_keys($manifest), self::EXTENSION_FIELDS) as $unknown) {
            $error('Unknown field "' . $unknown . '". Allowed: ' . implode(', ', self::EXTENSION_FIELDS) . '.');
        }
        if (($manifest['api_version'] ?? null) !== self::API_VERSION) {
            $error('api_version must be ' . self::API_VERSION . '.');
        }
        $name = $manifest['name'] ?? null;
        if (!self::validName($name)) {
            $error('name must be "vendor/extension": lowercase letters, digits and hyphens, each part starting with a letter.');
        } elseif (in_array(explode('/', $name)[0], self::RESERVED_VENDORS, true)) {
            $error('The vendor "' . explode('/', $name)[0] . '" is reserved for CometCMS.');
        }
        if (!is_string($manifest['version'] ?? null) || !preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}(?:-[a-zA-Z0-9.-]{1,40})?$/D', $manifest['version'])) {
            $error('version must be a semantic version such as 1.0.0 or 1.1.0-beta.1.');
        }
        $declared = array_key_exists('requires', $manifest) ? $manifest['requires'] : [];
        if (!is_array($declared) || ($declared !== [] && array_is_list($declared)) || count($declared) > 32) {
            $error('requires must be an object of at most 32 extension names with version constraints, for example {"cometcms/essentials": "^1.5"}.');
            $declared = [];
        }
        $requires = [];
        foreach ($declared as $dependency => $constraint) {
            $dependency = (string) $dependency;
            if (!self::validName($dependency)) {
                $error('Each dependency must be an extension name such as "vendor/extension".');
            } elseif ($dependency === $name) {
                $error('An extension cannot require itself.');
            } elseif (!VersionConstraint::valid($constraint)) {
                $error('requires.' . $dependency . ' must be a version constraint such as "^1.5", "~1.2.0", ">=1.2 <2" or "*".');
            } else {
                $requires[$dependency] = $constraint;
            }
        }
        $cms = $manifest['cms'] ?? '';
        if (!VersionConstraint::valid($cms) && $cms !== '') {
            $error('cms must be a version constraint for the CometCMS release, such as ">=2.0" or "^2.0".');
            $cms = '';
        }
        $permissions = self::permissions($manifest['permissions'] ?? [], $error);
        $settings = [];
        $declared = $manifest['settings'] ?? [];
        if (!is_array($declared) || ($declared !== [] && array_is_list($declared)) || count($declared) > self::MAX_SETTINGS) {
            $error('settings must be an object of at most ' . self::MAX_SETTINGS . ' settings keyed by name, for example {"tile_url": {"type": "url", "label": "Tile server"}}.');
            $declared = [];
        }
        foreach ($declared as $key => $spec) {
            $setting = self::prop((string) $key, $spec, static fn(string $message) => $error('settings.' . $key . ': ' . $message), types: self::SETTING_TYPES);
            if ($setting !== null) $settings[$key] = $setting;
        }
        self::relations($settings, static fn(string $message) => $error('settings.' . $message));
        // Shared props are checked on their own here, and again in each block that uses them.
        $shared = [];
        $declared = $manifest['shared_props'] ?? [];
        if (!is_array($declared) || ($declared !== [] && array_is_list($declared)) || count($declared) > self::MAX_SHARED_PROPS) {
            $error('shared_props must be an object of at most ' . self::MAX_SHARED_PROPS . ' prop definitions keyed by name, for example {"corners": {"type": "radius", "label": "Corners"}}.');
            $declared = [];
        }
        foreach ($declared as $key => $spec) {
            $fail = static fn(string $message) => $error('shared_props.' . $key . ': ' . $message);
            if (is_array($spec) && array_key_exists('use', $spec)) {
                $fail('a shared prop cannot use another shared prop.');
                continue;
            }
            if (is_array($spec) && array_key_exists('visible_when', $spec)) {
                $fail('visible_when depends on the block, so set it where the block uses the prop.');
                continue;
            }
            // Invalid ones stay known, so blocks using them are not told the prop is missing.
            $shared[(string) $key] = self::prop((string) $key, $spec, $fail) !== null ? $spec : null;
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
            'requires' => $requires,
            'cms' => $cms,
            'permissions' => $permissions,
            'settings' => $settings,
            // The declarations as written; blocks merge them before compiling (see blockManifest).
            'shared_props' => $shared,
        ];
    }

    /** @return array<string, list<string>> CSP directive => https origins */
    private static function permissions(mixed $declared, callable $error): array
    {
        if (!is_array($declared) || ($declared !== [] && array_is_list($declared))) {
            $error('permissions must be an object such as {"connect": ["https://api.example.com"]}.');
            return [];
        }
        $permissions = [];
        foreach ($declared as $directive => $origins) {
            if (!in_array($directive, self::PERMISSIONS, true)) {
                $error('Unknown permission "' . $directive . '". Allowed: ' . implode(', ', self::PERMISSIONS) . '.');
                continue;
            }
            if (!is_array($origins) || !array_is_list($origins) || $origins === [] || count($origins) > 16) {
                $error('permissions.' . $directive . ' must be a list of 1 to 16 origins.');
                continue;
            }
            foreach ($origins as $origin) {
                if (!is_string($origin) || !preg_match('~^https://(?:\*\.)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+(?::\d{1,5})?$~D', $origin)) {
                    $error('permissions.' . $directive . ': ' . json_encode($origin, JSON_UNESCAPED_SLASHES) . ' must be a lowercase https origin without a path, such as https://api.example.com or https://*.example.com.');
                    continue;
                }
                $permissions[$directive][] = $origin;
            }
            if (isset($permissions[$directive])) $permissions[$directive] = array_values(array_unique($permissions[$directive]));
        }

        return $permissions;
    }

    private static function blockManifest(string $json, string $file, array &$errors, array $shared = []): ?array
    {
        $before = count($errors);
        $manifest = self::json($json, $file, $errors);
        if ($manifest === null) return null;
        $error = static function (string $message) use ($file, &$errors): void {
            $errors[] = ['file' => $file, 'line' => null, 'message' => $message];
        };
        foreach (array_diff(array_keys($manifest), self::BLOCK_FIELDS) as $unknown) {
            $error($unknown === 'name'
                ? 'Remove "name": a block is named after its extension vendor and folder, for example blocks/callout becomes cometcms/callout.'
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
            $prop = self::prop((string) $key, $spec, static fn(string $message) => $error('props.' . $key . ': ' . $message), shared: $shared);
            if ($prop !== null) $props[$key] = $prop;
        }
        self::relations($props, static fn(string $message) => $error('props.' . $message));
        self::text($manifest, 'category', 40, false, $error);
        $keywords = $manifest['keywords'] ?? [];
        if (!is_array($keywords) || !array_is_list($keywords) || count($keywords) > 12
            || array_filter($keywords, static fn(mixed $word): bool => !is_string($word) || trim($word) === '' || strlen($word) > 40) !== []) {
            $error('keywords must be a list of at most 12 search words of up to 40 bytes each.');
            $keywords = [];
        }
        $aliases = $manifest['aliases'] ?? [];
        if (!is_array($aliases) || !array_is_list($aliases) || count($aliases) > 8 || count(array_unique($aliases, SORT_REGULAR)) !== count($aliases)
            || array_filter($aliases, static fn(mixed $alias): bool => !self::validName($alias)) !== []) {
            $error('aliases must be a list of up to 8 earlier block names, such as ["cometcms/notice"].');
            $aliases = [];
        }
        $children = $manifest['allowed_children'] ?? [];
        if (!is_array($children) || !array_is_list($children) || count($children) > 64
            || array_filter($children, static fn(mixed $kind): bool => !in_array($kind, LayoutSchema::ELEMENTS, true) && !self::validName($kind)) !== []) {
            $error('allowed_children must be a list of up to 64 built-in elements, such as "heading" or "button", and block names, such as "cometcms/card".');
            $children = [];
        }
        $variations = self::variations($manifest['variations'] ?? [], $props, $error);
        $deprecated = self::deprecation($manifest, $error);
        if (count($errors) > $before) return null;

        return [
            'title' => trim($manifest['title']),
            'description' => trim($manifest['description'] ?? ''),
            'icon' => $icon,
            'category' => trim($manifest['category'] ?? ''),
            'keywords' => array_map('trim', $keywords),
            'deprecated' => $deprecated,
            'aliases' => $aliases,
            'allowed_children' => array_map(static fn(string $kind): string => str_contains($kind, '/') ? 'block:' . $kind : $kind, $children),
            'variations' => $variations,
            'props' => $props,
        ];
    }

    /**
     * Apply i18n/<locale>.json files: editor texts of the extension, its settings and its
     * blocks, per language. Each translated item gets "l10n": {locale: {field: text}}; the
     * admin falls back to the manifest's own texts. Unknown blocks, props or fields are errors.
     */
    private static function translations(array $files, array &$extension, array &$blocks, string $vendor, array &$errors): void
    {
        ksort($files);
        foreach ($files as $locale => $bytes) {
            $file = 'i18n/' . $locale . '.json';
            $data = self::json($bytes, $file, $errors);
            if ($data === null) continue;
            $error = static function (string $message) use ($file, &$errors): void {
                $errors[] = ['file' => $file, 'line' => null, 'message' => $message];
            };
            foreach (array_diff(array_keys($data), ['$schema', 'title', 'description', 'settings', 'shared_props', 'blocks']) as $unknown) {
                $error('Unknown field "' . $unknown . '". Allowed: title, description, settings, shared_props, blocks.');
            }
            $texts = self::texts($data, ['title' => 120, 'description' => 500], '', $error);
            if ($texts !== []) $extension['l10n'][$locale] = $texts;
            foreach (self::entries($data, 'settings', '', $error) as $key => $entry) {
                if (!isset($extension['settings'][$key])) {
                    $error('settings.' . $key . ': the extension has no setting "' . $key . '".');
                    continue;
                }
                self::propTexts($extension['settings'][$key], $entry, $locale, 'settings.' . $key, $error);
            }
            // A shared prop's translation applies wherever a block uses it; block entries below can still reword it.
            foreach (self::entries($data, 'shared_props', '', $error) as $key => $entry) {
                $spec = $extension['shared_props'][$key] ?? null;
                if (!array_key_exists($key, $extension['shared_props'] ?? [])) {
                    $error('shared_props.' . $key . ': the extension has no shared prop "' . $key . '".');
                    continue;
                }
                if ($spec === null) continue;
                $checked = false;
                foreach ($blocks as &$block) {
                    foreach ($block['props'] as &$prop) {
                        if (($prop['use'] ?? null) !== $key) continue;
                        // Report problems once, not for every block using the prop.
                        $texts = is_array($entry) && !array_is_list($entry) ? array_diff_key($entry, array_flip($prop['own_texts'] ?? [])) : $entry;
                        self::propTexts($prop, $texts, $locale, 'shared_props.' . $key, $checked ? static fn() => null : $error);
                        $checked = true;
                    }
                    unset($prop);
                }
                unset($block);
            }
            foreach (self::entries($data, 'blocks', '', $error) as $slug => $entry) {
                $kind = 'block:' . $vendor . '/' . $slug;
                if (!isset($blocks[$kind])) {
                    $error('blocks.' . $slug . ': the extension has no block "' . $slug . '".');
                    continue;
                }
                $path = 'blocks.' . $slug;
                if (!is_array($entry) || array_is_list($entry)) {
                    $error($path . ' must be an object.');
                    continue;
                }
                foreach (array_diff(array_keys($entry), ['title', 'description', 'category', 'keywords', 'deprecated', 'props', 'variations']) as $unknown) {
                    $error($path . ': unknown field "' . $unknown . '". Allowed: title, description, category, keywords, deprecated, props, variations.');
                }
                $texts = self::texts($entry, ['title' => 120, 'description' => 500, 'category' => 40, 'deprecated' => 300], $path . '.', $error);
                if (array_key_exists('keywords', $entry)) {
                    $keywords = $entry['keywords'];
                    if (!is_array($keywords) || !array_is_list($keywords) || count($keywords) > 12
                        || array_filter($keywords, static fn(mixed $word): bool => !is_string($word) || trim($word) === '' || strlen($word) > 40) !== []) {
                        $error($path . '.keywords must be a list of at most 12 search words of up to 40 bytes each.');
                    } else {
                        $texts['keywords'] = array_map('trim', $keywords);
                    }
                }
                $names = array_column($blocks[$kind]['variations'], null, 'name');
                foreach (self::entries($entry, 'variations', $path . '.', $error) as $name => $variation) {
                    if (!isset($names[$name])) {
                        $error($path . '.variations.' . $name . ': the block has no variation "' . $name . '".');
                        continue;
                    }
                    $variationTexts = is_array($variation) && !array_is_list($variation) ? self::texts($variation, ['title' => 120, 'description' => 500], $path . '.variations.' . $name . '.', $error) : [];
                    foreach (is_array($variation) ? array_diff(array_keys($variation), ['title', 'description']) : [] as $unknown) {
                        $error($path . '.variations.' . $name . ': unknown field "' . $unknown . '". Allowed: title, description.');
                    }
                    if ($variationTexts !== []) $texts['variations'][$name] = $variationTexts;
                }
                if ($texts !== []) $blocks[$kind]['l10n'][$locale] = $texts;
                foreach (self::entries($entry, 'props', $path . '.', $error) as $key => $propEntry) {
                    if (!isset($blocks[$kind]['props'][$key])) {
                        $error($path . '.props.' . $key . ': the block has no prop "' . $key . '".');
                        continue;
                    }
                    self::propTexts($blocks[$kind]['props'][$key], $propEntry, $locale, $path . '.props.' . $key, $error);
                }
            }
        }
    }

    /** Props without the bookkeeping translations needed. */
    private static function withoutOwnTexts(array $props): array
    {
        foreach ($props as &$prop) {
            unset($prop['own_texts']);
            if ($prop['type'] === 'list') $prop['fields'] = self::withoutOwnTexts($prop['fields']);
        }

        return $props;
    }

    /** One prop's translated editor texts, option labels and, for lists, item fields. */
    private static function propTexts(array &$prop, mixed $entry, string $locale, string $path, callable $error): void
    {
        if (!is_array($entry) || array_is_list($entry)) {
            $error($path . ' must be an object such as {"label": "…"}.');
            return;
        }
        $allowed = [...self::PROP_TEXTS, ...($prop['type'] === 'enum' ? ['options'] : []), ...($prop['type'] === 'list' ? ['fields'] : [])];
        foreach (array_diff(array_keys($entry), $allowed) as $unknown) {
            $error($path . ': unknown field "' . $unknown . '". Allowed here: ' . implode(', ', $allowed) . '.');
        }
        $texts = self::texts($entry, ['label' => 120, 'help' => 300, 'placeholder' => 120, 'group' => 40], $path . '.', $error);
        foreach (self::entries($entry, 'options', $path . '.', $error) as $option => $label) {
            if (!in_array((string) $option, $prop['options'] ?? [], true)) {
                $error($path . '.options.' . $option . ': not an option of this prop.');
            } elseif (!is_string($label) || trim($label) === '' || strlen($label) > 120) {
                $error($path . '.options.' . $option . ' must be non-empty text of at most 120 bytes.');
            } else {
                $texts['option_labels'][(string) $option] = trim($label);
            }
        }
        // Merged, so a block's own translation keeps what its shared prop's translation did not change.
        if ($texts !== []) $prop['l10n'][$locale] = array_replace_recursive($prop['l10n'][$locale] ?? [], $texts);
        foreach (self::entries($entry, 'fields', $path . '.', $error) as $name => $fieldEntry) {
            if (!isset($prop['fields'][$name])) {
                $error($path . '.fields.' . $name . ': the list has no field "' . $name . '".');
                continue;
            }
            self::propTexts($prop['fields'][$name], $fieldEntry, $locale, $path . '.fields.' . $name, $error);
        }
    }

    /** The given text fields that are present, each non-empty and within its byte limit. */
    private static function texts(array $data, array $limits, string $path, callable $error): array
    {
        $texts = [];
        foreach ($limits as $field => $max) {
            if (!array_key_exists($field, $data)) continue;
            if (!is_string($data[$field]) || trim($data[$field]) === '' || strlen($data[$field]) > $max) {
                $error($path . $field . ' must be non-empty text of at most ' . $max . ' bytes.');
                continue;
            }
            $texts[$field] = trim($data[$field]);
        }

        return $texts;
    }

    /** An optional object of named entries; anything else is reported and yields none. */
    private static function entries(array $data, string $field, string $path, callable $error): array
    {
        $value = $data[$field] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $error($path . $field . ' must be an object keyed by name.');
            return [];
        }

        return $value;
    }

    /** null when not deprecated, otherwise a note for editors, which may be empty. */
    private static function deprecation(array $spec, callable $error): ?string
    {
        $value = $spec['deprecated'] ?? false;
        if ($value === false) return null;
        if ($value === true) return '';
        if (is_string($value) && strlen($value) <= 300) return trim($value);
        $error('deprecated must be true, false or a note for editors of at most 300 bytes.');

        return null;
    }

    /** Named starting values offered as separate palette entries. */
    private static function variations(mixed $declared, array $props, callable $error): array
    {
        if (!is_array($declared) || !array_is_list($declared) || count($declared) > 16) {
            $error('variations must be a list of at most 16 objects with name, title and props.');
            return [];
        }
        $variations = [];
        foreach ($declared as $index => $variation) {
            $fail = static fn(string $message) => $error('variations[' . $index . ']: ' . $message);
            if (!is_array($variation) || array_is_list($variation)) {
                $fail('each variation is an object such as {"name": "warning", "title": "Warning", "props": {"tone": "warning"}}.');
                continue;
            }
            foreach (array_diff(array_keys($variation), ['name', 'title', 'description', 'icon', 'props']) as $unknown) {
                $fail('unknown field "' . $unknown . '". Allowed: name, title, description, icon, props.');
            }
            $name = $variation['name'] ?? null;
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $name) || isset($variations[$name])) {
                $fail('name must be a distinct lowercase name of up to 40 letters, digits or hyphens.');
                continue;
            }
            self::text($variation, 'title', 120, true, $fail);
            self::text($variation, 'description', 500, false, $fail);
            $icon = $variation['icon'] ?? '';
            if (!is_string($icon) || ($icon !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*:[a-z0-9]+(?:-[a-z0-9]+)*$/D', $icon))) {
                $fail('icon must be an Iconify icon name such as "mdi:alert-outline".');
            }
            $values = $variation['props'] ?? null;
            if (!is_array($values) || ($values !== [] && array_is_list($values))) {
                $fail('props must be an object of prop values, for example {"tone": "warning"}.');
                continue;
            }
            $converted = [];
            foreach ($values as $key => $value) {
                $spec = $props[$key] ?? null;
                if ($spec === null) {
                    $fail('props.' . $key . ': the block has no prop "' . $key . '".');
                    continue;
                }
                $value = $spec['type'] === 'list' && is_array($value) && array_is_list($value)
                    ? array_map(static fn(mixed $item): mixed => self::listItem($spec['fields'], $item), $value)
                    : self::convertDefault($spec['type'], $value);
                $problem = self::defaultProblem($spec, $value);
                if ($problem !== null) {
                    $fail('props.' . $key . ' ' . $problem);
                    continue;
                }
                $converted[$key] = $spec['type'] === 'list' ? array_map(static function (array $item): array {
                    unset($item['_unknown']);
                    return $item;
                }, $value) : $value;
            }
            $variations[$name] = [
                'name' => $name,
                'title' => trim((string) ($variation['title'] ?? '')),
                'description' => trim((string) ($variation['description'] ?? '')),
                'icon' => $icon,
                'props' => $converted,
            ];
        }

        return array_values($variations);
    }

    /**
     * Checks between sibling props: visible_when conditions refer to existing props with
     * fitting values, and earlier names in renamed_from are neither declared nor claimed twice.
     */
    private static function relations(array &$props, callable $error, string $prefix = ''): void
    {
        $claimed = [];
        foreach ($props as $key => &$prop) {
            foreach ($prop['renamed_from'] ?? [] as $old) {
                if (isset($props[$old]) || isset($claimed[$old])) {
                    $error($prefix . $key . '.renamed_from: "' . $old . '" is ' . (isset($props[$old]) ? 'still a declared prop.' : 'already an earlier name of ' . $claimed[$old] . '.'));
                }
                $claimed[$old] = $key;
            }
            foreach ($prop['visible_when'] ?? [] as $other => $values) {
                $target = $props[$other] ?? null;
                $problem = match (true) {
                    $other === $key => 'a prop cannot depend on itself.',
                    $target === null => 'there is no prop "' . $other . '" here. Conditions refer to props of the same block, or to fields of the same list item.',
                    in_array($target['type'], ['enum', 'space', 'radius'], true) => array_diff($values, self::options($target)) !== [] ? 'must be one or more of the options ' . implode(', ', self::options($target)) . '.' : null,
                    $target['type'] === 'int' => array_filter($values, static fn(mixed $value): bool => !is_int($value)) !== [] ? 'must be one or more integers.' : null,
                    default => array_filter($values, static fn(mixed $value): bool => !is_bool($value)) !== [] || count($values) > 1
                        ? 'must be true (has a value) or false (is empty).' : null,
                };
                if ($problem !== null) $error($prefix . $key . '.visible_when.' . $other . ': ' . $problem);
            }
            if ($prop['type'] === 'list') self::relations($prop['fields'], $error, $prefix . $key . '.fields.');
        }
    }

    /** A complete prop spec, with the documented defaults filled in. List fields use the same rules, minus lists. */
    private static function prop(string $key, mixed $spec, callable $error, bool $field = false, ?array $types = null, array $shared = []): ?array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) || in_array($key, ['constructor', 'prototype'], true)) {
            $error('prop names start with a lowercase letter and use lowercase letters, digits and underscores (up to 64).');
            return null;
        }
        if (!is_array($spec) || array_is_list($spec)) {
            $error('each prop is an object with at least "type" and "label".');
            return null;
        }
        // {"use": "corners"} starts from the extension's shared prop; other fields here replace its own.
        $use = null;
        if (array_key_exists('use', $spec)) {
            $use = $spec['use'];
            if (is_string($use) && array_key_exists($use, $shared) && $shared[$use] === null) {
                $error('use: the shared prop "' . $use . '" has errors, reported for comet-extension.json.');
                return null;
            }
            if (!is_string($use) || !isset($shared[$use])) {
                $error('use: there is no shared prop ' . json_encode($use, JSON_UNESCAPED_SLASHES) . '. Declare it in comet-extension.json under shared_props'
                    . ($shared === [] ? '.' : ', which has: ' . implode(', ', array_keys($shared)) . '.'));
                return null;
            }
            if (array_key_exists('type', $spec)) {
                $error('type comes from the shared prop "' . $use . '". Remove it here, or declare a separate prop.');
                return null;
            }
            $own = array_diff_key($spec, ['use' => true]);
            $spec = array_replace($shared[$use], $own);
            // List item fields have no groups; the shared prop's group only applies at the top level.
            if ($field && !array_key_exists('group', $own)) unset($spec['group']);
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
        $types ??= $field ? array_diff(self::PROP_TYPES, ['list']) : self::PROP_TYPES;
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
        foreach (['group' => 40, 'help' => 300, 'placeholder' => 120] as $name => $max) {
            if (!array_key_exists($name, $spec)) continue;
            if (!is_string($spec[$name]) || trim($spec[$name]) === '' || strlen($spec[$name]) > $max) {
                $fail($name . ' must be non-empty text of at most ' . $max . ' bytes.');
            } elseif ($name === 'placeholder' && !in_array($type, ['string', 'text', 'url'], true)) {
                $fail('placeholder only applies to string, text and url props.');
            } elseif ($name === 'group' && $field) {
                $fail('list item fields cannot have a group.');
            } else {
                $prop[$name] = trim($spec[$name]);
            }
        }
        $deprecated = self::deprecation($spec, $fail);
        if ($deprecated !== null) $prop['deprecated'] = $deprecated;
        if (array_key_exists('renamed_from', $spec)) {
            $old = $spec['renamed_from'];
            if (!is_array($old) || !array_is_list($old) || $old === [] || count($old) > 8 || count(array_unique($old, SORT_REGULAR)) !== count($old)
                || array_filter($old, static fn(mixed $name): bool => !is_string($name) || $name === $key || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)) !== []) {
                $fail('renamed_from must be a list of 1 to 8 earlier names of this prop, such as ["link_url"].');
            } else {
                $prop['renamed_from'] = $old;
            }
        }
        if (array_key_exists('visible_when', $spec)) {
            $conditions = $spec['visible_when'];
            if (!is_array($conditions) || $conditions === [] || array_is_list($conditions) || count($conditions) > 4) {
                $fail('visible_when must be an object of 1 to 4 conditions on other props, for example {"show_link": true} or {"tone": ["info", "warning"]}.');
            } else {
                foreach ($conditions as $other => $expected) {
                    $values = is_array($expected) ? $expected : [$expected];
                    if ($values === [] || !array_is_list($values) || count($values) > 50
                        || array_filter($values, static fn(mixed $value): bool => !is_bool($value) && !is_int($value) && !is_string($value)) !== []) {
                        $fail('visible_when.' . $other . ' must be true, false, a number, an option or a list of options.');
                        continue;
                    }
                    $prop['visible_when'][(string) $other] = $values;
                }
            }
        }
        $int = static fn(string $name, int $min, int $max, int $fallback) => !array_key_exists($name, $spec) ? $fallback
            : (is_int($spec[$name]) && $spec[$name] >= $min && $spec[$name] <= $max ? $spec[$name] : $fail(sprintf('%s must be an integer from %d to %d.', $name, $min, $max)));
        $allowed = match ($type) {
            'string', 'url', 'text' => ['max'], 'int' => ['min', 'max'], 'enum' => ['options', 'option_labels', 'option_aliases'], 'space', 'radius' => ['option_aliases'], 'list' => ['fields', 'max'], default => [],
        };
        // Earlier values mapped to current options, so renamed options (or an enum that became a
        // space or radius prop) keep their meaning in saved layouts.
        $optionAliases = static function (array $options) use ($spec, &$prop, $fail): void {
            if (!array_key_exists('option_aliases', $spec)) return;
            $aliases = $spec['option_aliases'];
            if (!is_array($aliases) || $aliases === [] || array_is_list($aliases)
                || array_filter(array_keys($aliases), static fn(mixed $old): bool => in_array((string) $old, $options, true) || !preg_match('/^[A-Za-z0-9_-]{1,120}$/D', (string) $old)) !== []
                || array_filter($aliases, static fn(mixed $new): bool => !in_array($new, $options, true)) !== []) {
                $fail('option_aliases must map earlier option values to current options, for example {"danger": "error"}. Earlier values cannot still be options.');
            } else {
                $prop['option_aliases'] = array_combine(array_map('strval', array_keys($aliases)), array_values($aliases));
            }
        };
        foreach (['min', 'max', 'options', 'fields', 'option_labels', 'option_aliases'] as $name) {
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
                if (array_key_exists('option_labels', $spec)) {
                    $labels = $spec['option_labels'];
                    if (!is_array($labels) || $labels === [] || array_is_list($labels) || array_diff(array_map('strval', array_keys($labels)), $options) !== []
                        || array_filter($labels, static fn(mixed $label): bool => !is_string($label) || trim($label) === '' || strlen($label) > 120) !== []) {
                        $fail('option_labels must be an object that names options for editors, for example {"info": "Information"}. Keys must be options; labels up to 120 bytes.');
                    } else {
                        $prop['option_labels'] = array_map('trim', $labels);
                    }
                }
                $optionAliases($options);
                break;
            case 'space':
            case 'radius':
                $prop['default'] = $default ?? '';
                $optionAliases(self::themeOptions($type));
                break;
            case 'shadow':
            case 'color':
            case 'icon':
            case 'media':
            case 'menu':
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
                    $compiled = self::prop((string) $name, $fieldSpec, static fn(string $message) => $fail('fields.' . $name . ': ' . $message), true, shared: $shared);
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

        if ($use !== null) {
            $prop['use'] = $use;
            // Translations of the shared prop apply to the texts this block did not reword.
            $prop['own_texts'] = array_values(array_intersect(self::OVERRIDABLE_TEXTS, array_keys($own)));
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
            'space', 'radius' => in_array($value, self::themeOptions($prop['type']), true) ? null : 'must be empty or one of the theme ' . ($prop['type'] === 'space' ? 'spacing' : 'radius') . ' values: ' . implode(', ', array_filter(self::themeOptions($prop['type']))) . '.',
            // Theme presets differ per workspace, so defaults use the built-in scale.
            'shadow' => in_array($value, Shadow::FIXED, true) ? null : 'must be empty or one of the built-in shadows: ' . implode(', ', array_filter(Shadow::FIXED)) . '.',
            'icon' => is_string($value) && ($value === '' || isset(Icons::catalog()[$value])) ? null : 'must be empty or a bundled site icon name.',
            'media' => $value === '' ? null : 'is not supported: media comes from the library. Show a fallback with {{^name}}…{{/name}}.',
            'menu' => is_string($value) && ($value === '' || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $value)) ? null : 'must be empty or a menu name of up to 80 letters, digits, hyphens or underscores.',
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
                    $errors[] = ['file' => $file, 'line' => $line, 'message' => 'The extension has no file ' . $url . '.'];
                    continue;
                }
                $parts[] = substr($css, $offset, $match[0][1] - $offset) . 'url("';
                $parts[] = ['asset', $assets[$url]];
                $parts[] = '")';
                $offset = $match[0][1] + strlen($match[0][0]);
            } elseif ($url !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|#)~i', $url)) {
                $errors[] = ['file' => $file, 'line' => $line, 'message' => 'The relative URL "' . $url . '" would resolve against each page\'s address. Reference extension files as url(assets/…).'];
            }
        }
        $parts[] = substr($css, $offset);

        return array_values(array_filter($parts, static fn(string|array $part): bool => $part !== ''));
    }

    /** A block or extension icon, inlined for the editor. It is shown as a mask, so only its shape matters. */
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

    /**
     * A bundled font family: fonts/<id>.json lists faces whose "file" is a
     * WOFF2 under assets/. Installing copies them into the site font library.
     */
    private static function font(array $data, string $file, array $files, array $assets, array &$errors): ?array
    {
        $count = count($errors);
        if (isset(Theme::FONT_STACKS[basename($file, '.json')])) $errors[] = ['file' => $file, 'line' => null, 'message' => 'This name belongs to a built-in font stack. Rename the file.'];
        $error = static function (string $message) use ($file, &$errors): void {
            $errors[] = ['file' => $file, 'line' => null, 'message' => $message];
        };
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if ($name === '' || strlen($name) > 120 || preg_match('/[\x00-\x1F"\\\\<>]/', $name)) $error('A font needs a name of at most 120 bytes without quotes, backslashes or angle brackets.');
        $category = $data['category'] ?? 'sans-serif';
        if (!in_array($category, ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy'], true)) $error('category must be serif, sans-serif, monospace, cursive or fantasy.');
        $license = $data['license'] ?? '';
        if (!is_string($license) || strlen($license) > 131072) $error('license must be text of at most 128 KiB.');
        $faces = $data['faces'] ?? null;
        if (!is_array($faces) || !array_is_list($faces) || $faces === [] || count($faces) > 64) {
            $error('faces must list between 1 and 64 font faces.');
            $faces = [];
        }
        $normalized = [];
        foreach ($faces as $position => $face) {
            $path = is_array($face) ? ($face['file'] ?? null) : null;
            if (!is_string($path) || !isset($assets[$path]) || !str_ends_with($path, '.woff2')) {
                $error(sprintf('faces[%d].file must name a .woff2 file under assets/.', $position));
                continue;
            }
            try {
                $hash = FontFiles::validate($files[$path]);
                $normalized[] = FontFiles::face(['hash' => $hash] + $face) + ['file' => $path];
            } catch (\InvalidArgumentException $e) {
                $error(sprintf('faces[%d]: %s', $position, $e->getMessage()));
            }
        }
        return count($errors) === $count ? ['name' => $name, 'category' => $category, 'license' => $license, 'faces' => $normalized] : null;
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
            $value = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
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
