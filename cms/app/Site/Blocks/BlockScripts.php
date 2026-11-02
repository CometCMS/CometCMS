<?php

declare(strict_types=1);

namespace CometCMS\Site\Blocks;

use CometCMS\Core\HtmlSanitizer;
use CometCMS\Extensions\ExtensionRegistry;
use CometCMS\Site\SiteContext;

/**
 * Block scripts on the website and in previews. A block's view.js is served as a
 * content-hashed module; a small loader per block on the page imports it and mounts
 * it on every instance. The Content-Security-Policy allows exactly these loaders by
 * hash and exactly these module URLs, never the whole origin.
 *
 * Each instance is mounted as mount(element, props, ctx). ctx holds the extension's
 * settings, the page language, whether this is a preview, the reduced-motion
 * preference and an AbortSignal. A mount that returns a cleanup function can be
 * unmounted and mounted again, which lets the builder update its preview in place.
 */
final class BlockScripts
{
    public const MAX_BYTES = 131072;

    /** Shared by every loader on a page: the first to run defines globalThis.cometBlocks. */
    private const RUNTIME = <<<'JS'
const blocks = globalThis.cometBlocks ??= {
  modules: new Map(),
  live: new Map(),
  define(name, mount, settings, preview) {
    this.modules.set(name, { mount, settings, preview });
    this.mount();
  },
  has(name) { return this.modules.has(name); },
  mount() {
    for (const element of document.querySelectorAll("[data-comet-block]")) {
      const name = element.dataset.cometBlock, module = this.modules.get(name);
      if (!module || this.live.has(element)) continue;
      const controller = new AbortController();
      const ctx = { settings: module.settings, preview: module.preview, locale: document.documentElement.lang || "", reducedMotion: matchMedia("(prefers-reduced-motion: reduce)").matches, signal: controller.signal };
      let cleanup = null;
      try {
        const result = module.mount(element, JSON.parse(element.dataset.cometProps || "{}"), ctx);
        if (typeof result === "function") cleanup = result;
      } catch (error) { console.error(name, error); }
      this.live.set(element, { controller, cleanup });
    }
  },
  unmount() {
    for (const [element, instance] of this.live) {
      instance.controller.abort();
      try { instance.cleanup?.(); } catch (error) { console.error(element.dataset.cometBlock, error); }
    }
    this.live.clear();
  },
  remountable() { return [...this.live.values()].every((instance) => instance.cleanup); },
};
JS;

    /** The inline module that mounts one block's script on each of its instances. */
    public static function loader(string $name, string $url, array $settings = [], bool $preview = false): string
    {
        $json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);

        return 'import mount from ' . $json($url) . ";\n" . self::RUNTIME . "\n"
            . 'blocks.define(' . $json($name) . ', mount, ' . $json((object) $settings) . ', ' . ($preview ? 'true' : 'false') . ');';
    }

    /** @return list<string> the loader elements for the given block kinds */
    public static function elements(array $kinds, ExtensionRegistry $blocks, SiteContext $ctx): array
    {
        $elements = [];
        foreach ($kinds as $kind) {
            $block = $blocks->get($kind);
            if (($block['script'] ?? '') !== '') {
                $elements[] = '<script type="module">' . self::blockLoader($block, $blocks, $ctx) . '</script>';
            }
        }

        return $elements;
    }

    /**
     * Setting values as templates and scripts receive them: colors as their key with
     * the CSS value, unsafe URLs empty, everything else as stored.
     */
    public static function settingValues(array $specs, array $values): array
    {
        $resolved = [];
        foreach ($specs as $key => $spec) {
            $value = $values[$key] ?? $spec['default'];
            $resolved[$key] = match ($spec['type']) {
                'color' => BlockTemplate::themeValue('color', $value),
                'url' => is_string($value) && HtmlSanitizer::isSafeUrl(trim($value)) ? trim($value) : '',
                default => is_scalar($value) ? $value : '',
            };
        }

        return $resolved;
    }

    private static function blockLoader(array $block, ExtensionRegistry $blocks, SiteContext $ctx): string
    {
        $specs = $blocks->extension($block['extension'])['settings'] ?? [];

        return self::loader($block['name'], $ctx->blockAssetUrl($block['script']), self::settingValues($specs, $blocks->settings($block['extension'])), $ctx->preview);
    }

    /**
     * connect-src origins the extensions of the scripts on this page asked for in their
     * permissions. Only loaders that match an installed block exactly count.
     *
     * @return list<string>
     */
    public static function connectSources(string $html, SiteContext $ctx): array
    {
        if (!str_contains($html, '<script type="module">import mount from ')) {
            return [];
        }
        $blocks = $ctx->site->blocks();
        $origins = [];
        foreach ($blocks->all() as $block) {
            if (($block['script'] ?? '') === '' || !str_contains($html, '<script type="module">' . self::blockLoader($block, $blocks, $ctx) . '</script>')) continue;
            array_push($origins, ...($blocks->extension($block['extension'])['permissions']['connect'] ?? []));
        }

        return array_values(array_unique($origins));
    }

    /** Add the page's connect-src permissions to a policy, or leave it as it is without any. */
    public static function extendPolicy(string $policy, string $html, SiteContext $ctx): string
    {
        $origins = self::connectSources($html, $ctx);
        if ($origins === []) return $policy;

        return str_contains($policy, 'connect-src ')
            ? (string) preg_replace('/(connect-src [^;]*)/', '$1 ' . implode(' ', $origins), $policy, 1)
            : $policy . "; connect-src 'self' " . implode(' ', $origins);
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
        $blocks = $ctx->site->blocks();
        foreach ($blocks->all() as $block) {
            if (($block['script'] ?? '') === '') continue;
            $url = $ctx->blockAssetUrl($block['script']);
            $loader = self::blockLoader($block, $blocks, $ctx);
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
        // Bundlers emit export { mount as default }, often without whitespace before {.
        if (preg_match('/\bexport\b\s*(?:default\b|\{[^}]*\bas\s+default\b)/s', $source) !== 1) {
            $problems[] = [null, 'view.js must export its mount function: export default function (element, props, ctx) { … }'];
        }
        if (preg_match_all('/^\s*import(?:\s+[\w*{\'"]|\s*[{*\'"(])/m', $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [, $offset]) {
                $problems[] = [substr_count($source, "\n", 0, $offset) + 1, 'view.js must be self-contained: imports are not allowed. Bundle dependencies into the file, for example with esbuild --bundle --format=esm.'];
            }
        }

        return $problems;
    }
}
