<?php

declare(strict_types=1);

namespace CometCMS\Site\Blocks;

use CometCMS\Extensions\ExtensionRegistry;
use CometCMS\Site\SiteContext;

/**
 * Block scripts on the website and in previews. A block's view.js is served as a
 * content-hashed module; a small loader per block on the page imports it and mounts
 * it on every instance. The Content-Security-Policy allows exactly these loaders by
 * hash and exactly these module URLs, never the whole origin.
 */
final class BlockScripts
{
    public const MAX_BYTES = 131072;

    /** The inline module that mounts one block's script on each of its instances. */
    public static function loader(string $name, string $url): string
    {
        $selector = json_encode('[data-comet-block="' . $name . '"]', JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return 'import mount from ' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n"
            . 'for (const element of document.querySelectorAll(' . $selector . ")) {\n"
            . "  try { mount(element, JSON.parse(element.dataset.cometProps || \"{}\")); }\n"
            . '  catch (error) { console.error(' . json_encode($name, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ", error); }\n"
            . '}';
    }

    /** @return list<string> the loader elements for the given block kinds */
    public static function elements(array $kinds, ExtensionRegistry $blocks, SiteContext $ctx): array
    {
        $elements = [];
        foreach ($kinds as $kind) {
            $block = $blocks->get($kind);
            if (($block['script'] ?? '') !== '') {
                $elements[] = '<script type="module">' . self::loader($block['name'], $ctx->blockAssetUrl($block['script'])) . '</script>';
            }
        }

        return $elements;
    }

    /**
     * script-src entries for the block loaders a page contains: each loader's hash and
     * the one module URL it imports. Loaders are recognised only when they match an
     * installed block's script exactly.
     *
     * @return list<string>
     */
    public static function cspSources(string $html, SiteContext $ctx): array
    {
        $host = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $ctx->origin);
        if ($host === '' || !str_contains($html, '<script type="module">import mount from ')) {
            return [];
        }
        $sources = [];
        foreach ($ctx->site->blocks()->all() as $block) {
            if (($block['script'] ?? '') === '') continue;
            $url = $ctx->blockAssetUrl($block['script']);
            $loader = self::loader($block['name'], $url);
            if (str_contains($html, '<script type="module">' . $loader . '</script>')) {
                $sources[] = "'sha256-" . base64_encode(hash('sha256', $loader, true)) . "'";
                $sources[] = $host . $url;
            }
        }

        return $sources;
    }

    /** Problems with a view.js, which must be one self-contained module with a default export. */
    public static function problems(string $source): array
    {
        $problems = [];
        if (strlen($source) > self::MAX_BYTES) {
            $problems[] = [null, 'view.js is limited to ' . (self::MAX_BYTES >> 10) . ' KiB.'];
        }
        if (str_contains(strtolower($source), '</script')) {
            $problems[] = [null, 'view.js cannot contain "</script".'];
        }
        if (preg_match('/^\s*export\s+default\s/m', $source) !== 1) {
            $problems[] = [null, 'view.js must export its mount function: export default function (element, props) { … }'];
        }
        if (preg_match_all('/^\s*import(?:\s+[\w*{\'"]|\s*[{*\'"(])/m', $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [, $offset]) {
                $problems[] = [substr_count($source, "\n", 0, $offset) + 1, 'view.js must be self-contained: imports are not allowed. Bundle dependencies into the file, for example with esbuild --bundle --format=esm.'];
            }
        }

        return $problems;
    }
}
