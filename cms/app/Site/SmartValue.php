<?php

declare(strict_types=1);

namespace CometCMS\Site;

use CometCMS\Core\HtmlSanitizer;
use CometCMS\Core\Security;
use CometCMS\Core\ValidationException;

/** Structured bindings are data, never executable expressions or recursive templates. */
final class SmartValue
{
    public const SOURCES = ['current', 'item', 'entry', 'site'];
    public const DATE_FORMATS = ['date', 'long', 'datetime', 'time'];
    public const PROPERTIES = [
        'heading' => ['text' => 'text', 'link' => 'link'],
        'text' => ['html' => 'html'],
        'image' => ['media' => 'media', 'link' => 'link'],
        'gallery' => ['media' => 'media_list', 'link' => 'link'],
        'button' => ['label' => 'text', 'link' => 'link'],
        'icon' => ['label' => 'text', 'link' => 'link'],
        'field' => ['source' => 'value', 'link' => 'link'],
    ];
    /** Style keys that accept a binding, on every kind that offers them. */
    public const STYLE_PROPERTIES = ['background_image' => 'media', 'background_poster' => 'media'];

    public function __construct(private readonly SiteContext $ctx) {}

    public static function reference(string $field, string $source = 'current'): array
    {
        return ['type' => 'reference', 'source' => $source, 'path' => [$field], 'filters' => []];
    }

    public static function isDynamic(mixed $value): bool
    {
        return is_array($value) && in_array($value['type'] ?? '', ['reference', 'template', 'invalid'], true);
    }

    public static function normalize(mixed $value, string $target = 'text'): array
    {
        if (!is_array($value)) {
            self::invalid();
        }
        if (($value['type'] ?? '') === 'template' && in_array($target, ['text', 'html'], true)) {
            $parts = $value['parts'] ?? null;
            if (!is_array($parts) || !array_is_list($parts) || count($parts) > 500) {
                self::invalid();
            }
            $normalized = [];
            $length = 0;
            foreach ($parts as $part) {
                if (is_string($part)) {
                    $length += strlen($part);
                    $normalized[] = $part;
                } else {
                    $normalized[] = self::normalize($part, 'value');
                    if ($normalized[array_key_last($normalized)]['type'] !== 'reference') {
                        self::invalid();
                    }
                }
            }
            if ($length > 50000) {
                self::invalid();
            }
            if ($target === 'html') {
                // References may only occur in HTML text nodes. Sanitize the whole
                // document with opaque markers so markup split across parts is safe.
                $prefix = 'SMART' . bin2hex(random_bytes(8)) . 'TOKEN';
                $html = '';
                $refs = [];
                foreach ($normalized as $part) {
                    if (is_string($part)) {
                        $html .= $part;
                    } else {
                        $marker = $prefix . count($refs) . 'END';
                        $refs[$marker] = $part;
                        $html .= $marker;
                    }
                }
                foreach (preg_split('/(<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $fragment) {
                    if (str_starts_with($fragment, '<') && str_contains($fragment, $prefix)) {
                        self::invalid();
                    }
                }
                $html = HtmlSanitizer::sanitize($html);
                $normalized = [];
                foreach (preg_split('/(' . $prefix . '\d+END)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                    $normalized[] = $refs[$part] ?? $part;
                }
            }
            return ['type' => 'template', 'parts' => $normalized];
        }
        $source = $value['source'] ?? '';
        $path = $value['path'] ?? null;
        if (($value['type'] ?? '') !== 'reference' || !in_array($source, self::SOURCES, true)
            || !is_array($path) || !array_is_list($path) || count($path) < 1 || count($path) > 6) {
            self::invalid();
        }
        foreach ($path as $key) {
            if (!is_string($key) || LayoutSchema::key($key) === '') {
                self::invalid();
            }
        }
        $result = ['type' => 'reference', 'source' => $source, 'path' => $path, 'filters' => []];
        if ($source === 'entry') {
            $result['collection'] = LayoutSchema::key($value['collection'] ?? '');
            $result['entry'] = LayoutSchema::key($value['entry'] ?? '');
            if ($result['collection'] === '' || $result['entry'] === '') {
                self::invalid();
            }
        }
        $filters = $value['filters'] ?? [];
        if (!is_array($filters) || !array_is_list($filters) || count($filters) > 10) {
            self::invalid();
        }
        foreach ($filters as $filter) {
            if (!is_array($filter)) self::invalid();
            $name = $filter['name'] ?? '';
            $arg = $filter['value'] ?? null;
            $valid = match ($name) {
                'fallback' => is_string($arg) && strlen($arg) <= 2000,
                'date' => in_array($arg, self::DATE_FORMATS, true),
                'truncate' => is_int($arg) && $arg > 0 && $arg <= 500,
                default => false,
            };
            if (!$valid || (in_array($target, ['media', 'media_list', 'link'], true) && $name !== 'fallback')) {
                self::invalid();
            }
            $result['filters'][] = ['name' => $name, 'value' => $arg];
        }
        return $result;
    }

    private static function invalid(): never
    {
        throw new ValidationException(['nodes' => ['Invalid smart content reference.']], 'Invalid smart content reference.');
    }

    public static function empty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** @return array{value: mixed, config: ?array, missing: bool} */
    public function resolve(array $ref, array $state): array
    {
        $source = $ref['source'];
        $type = $state['type'] ?? null;
        $entry = null;
        $config = null;
        $value = null;
        $missing = false;
        if ($source === 'site') {
            $value = ['title' => $this->ctx->siteTitle(), 'tagline' => $this->ctx->settings()['tagline'] ?? '', 'year' => date('Y')];
            if (count($ref['path']) === 1 && array_key_exists($ref['path'][0], $value)) {
                $value = $value[$ref['path'][0]];
                $config = ['type' => 'text'];
            } else {
                $value = null;
            }
        } else {
            if ($source === 'item') {
                $value = $state['row'] ?? null;
                $fields = $state['row_fields'] ?? [];
            } else {
                if ($source === 'current') {
                    $entry = $state['entry'] ?? null;
                } else {
                    $type = $this->ctx->type($ref['collection']);
                    $entry = $this->ctx->findEntry($ref['collection'], $ref['entry']);
                    if ($entry !== null && ($entry['uid'] ?? '') !== $ref['entry']) $entry = null;
                }
                $value = $entry;
                $fields = $type['fields'] ?? [];
            }
            $hops = 0;
            foreach ($ref['path'] as $index => $key) {
                $last = $index === count($ref['path']) - 1;
                if ($key === 'url' && $last && $entry !== null) {
                    $value = $this->ctx->entryUrl((string) ($type['name'] ?? ''), $entry);
                    $config = ['type' => 'url'];
                    break;
                }
                $config = $fields[$key] ?? (in_array($key, [...LayoutSchema::SYSTEM_FIELDS, 'slug'], true) && ($source !== 'item' || $entry !== null)
                    ? ['type' => in_array($key, ['title', 'slug'], true) ? 'text' : 'datetime'] : null);
                if (!is_array($config) || !is_array($value)) {
                    $value = null;
                    $config = null;
                    break;
                }
                $value = $value[$key] ?? null;
                if (!$last) {
                    if (($config['type'] ?? '') !== 'relation' || !empty($config['multiple']) || ++$hops > 5 || !is_scalar($value)) {
                        $value = null;
                        $config = null;
                        break;
                    }
                    $target = (string) ($config['target'] ?? '');
                    $entry = $this->ctx->findEntry($target, (string) $value);
                    $type = $this->ctx->type($target);
                    $fields = $type['fields'] ?? [];
                    $value = $entry;
                }
            }
        }
        // An empty field that falls back is resolved; a field that does not exist never is.
        $missing = $config === null || self::empty($value);
        foreach ($ref['filters'] ?? [] as $filter) {
            if ($filter['name'] === 'fallback' && self::empty($value)) {
                $missing = $config === null;
                $value = $filter['value'];
                // Media and rich text fall back to a value of their own kind.
                $config = in_array($config['type'] ?? '', ['media', 'html'], true) ? $config : ['type' => 'text'];
            } elseif ($filter['name'] === 'date' && !self::empty($value) && is_scalar($value)) {
                $value = Text::date((string) $value, $filter['value'], $this->ctx->htmlLang());
                $config = ['type' => 'text'];
            } elseif ($filter['name'] === 'truncate' && !self::empty($value)) {
                $value = Text::words($this->plain($value, $config), $filter['value']);
                $config = ['type' => 'text'];
            }
        }
        return ['value' => $value, 'config' => $config, 'missing' => $missing];
    }

    public function plain(mixed $value, ?array $config = null): string
    {
        if (is_array($value) || is_object($value) || $value === null || in_array($config['type'] ?? '', ['media', 'relation', 'repeater', 'json'], true)) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (($config['type'] ?? '') === 'select') {
            $options = $config['options'] ?? [];
            return !array_is_list($options) && isset($options[(string) $value]) ? (string) $options[(string) $value] : (string) $value;
        }
        if (($config['type'] ?? '') === 'markdown') {
            $value = Markdown::toHtml((string) $value);
        }
        if (in_array($config['type'] ?? '', ['markdown', 'html'], true)) {
            return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (in_array($config['type'] ?? '', ['number', 'range'], true)) {
            return Text::number($value, $this->ctx->htmlLang());
        }
        if (in_array($config['type'] ?? '', ['date', 'datetime'], true)) {
            return Text::date((string) $value, 'date', $this->ctx->htmlLang());
        }
        return (string) $value;
    }

    public function text(array $value, array $state, bool $html = false): array
    {
        if ($value['type'] === 'reference') {
            $resolved = $this->resolve($value, $state);
            $raw = $resolved['value'];
            $type = $resolved['config']['type'] ?? '';
            $incompatible = is_array($raw) || in_array($type, ['media', 'relation', 'repeater', 'json'], true);
            $text = $this->plain($raw, $resolved['config']);
            $resolved['missing'] = $resolved['missing'] || $incompatible;
            if ($html) {
                $text = match ($type) {
                    'markdown' => Markdown::toHtml(is_scalar($raw) ? (string) $raw : ''),
                    'html' => HtmlSanitizer::sanitize(is_scalar($raw) ? (string) $raw : ''),
                    default => '<p>' . nl2br(Security::e($text), false) . '</p>',
                };
            }
            return ['value' => $text, 'missing' => $resolved['missing']];
        }
        $text = '';
        $missing = false;
        foreach ($value['parts'] as $part) {
            if (is_string($part)) {
                $text .= $part;
            } else {
                $resolved = $this->resolve($part, $state);
                $missing = $missing || $resolved['missing'] || is_array($resolved['value']) || in_array($resolved['config']['type'] ?? '', ['media', 'relation', 'repeater', 'json'], true);
                $plain = $this->plain($resolved['value'], $resolved['config']);
                $text .= $html ? Security::e($plain) : $plain;
            }
        }
        return ['value' => $html ? HtmlSanitizer::sanitize($text) : $text, 'missing' => $missing];
    }
}
