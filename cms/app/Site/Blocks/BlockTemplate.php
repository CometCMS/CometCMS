<?php

declare(strict_types=1);

namespace CometCMS\Site\Blocks;

use CometCMS\Core\Security;

/**
 * Mustache-style block templates: escaped values, rich text, conditional and list
 * sections, enum comparisons and a slot for child elements. Templates compile once at
 * install into a small tree; rendering never parses or evaluates anything.
 *
 * Rendering takes resolved values: scalars, lists of item value maps, and for media,
 * colors and links a map whose "_" entry is the main value (URL, color key, href).
 */
final class BlockTemplate
{
    public const MAX_DEPTH = 16;
    /** Sub-values a dotted tag may read, by prop type. */
    private const FIELDS = ['media' => ['url', 'alt']];

    /**
     * @param array<string, array> $props declared prop specs
     * @param array<string, string> $assets package path => published asset file
     */
    public static function compile(string $source, array $props, array $assets, string $file, array &$errors): array
    {
        $before = count($errors);
        [$html, $tags] = self::tokenize($source, $props, $file, $errors);
        if (count($errors) > $before) {
            return [];
        }
        [$html, $extras, $contexts] = BlockMarkup::normalize($html, $file, $tags, $assets, $errors);
        if (count($errors) > $before) {
            return [];
        }
        $stack = [[]];
        preg_match_all(BlockMarkup::MARKERS, $html, $markers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $offset = 0;
        foreach ($markers as $marker) {
            if ($marker[0][1] > $offset) {
                $stack[count($stack) - 1][] = ['text', substr($html, $offset, $marker[0][1] - $offset)];
            }
            $offset = $marker[0][1] + strlen($marker[0][0]);
            if (isset($marker[2]) && $marker[2][1] >= 0) {
                $stack[count($stack) - 1][] = $extras[(int) $marker[2][0]];
                continue;
            }
            $id = (int) $marker[1][0];
            $tag = $tags[$id];
            if ($tag['type'] === 'value') {
                $stack[count($stack) - 1][] = ['value', $tag['key'], self::field($tag, $contexts[$id] ?? 'text')];
            } elseif ($tag['type'] === 'raw') {
                $stack[count($stack) - 1][] = ['raw', $tag['key']];
            } elseif ($tag['type'] === '/') {
                $children = array_pop($stack);
                $open = array_pop($stack[count($stack) - 1]);
                $stack[count($stack) - 1][] = [$open[0], $open[1], $children, $open[2]];
            } else {
                $stack[count($stack) - 1][] = [$tag['type'], $tag['key'], $tag['option']];
                $stack[] = [];
            }
        }
        if ($offset < strlen($html)) {
            $stack[count($stack) - 1][] = ['text', substr($html, $offset)];
        }

        return $stack[0];
    }

    public static function hasSlot(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node[0] === 'slot' || (in_array($node[0], ['#', '^'], true) && self::hasSlot($node[2]))) return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $values resolved prop values
     * @param callable(string): string $assetUrl published asset file => URL
     * @param string $slot the rendered child elements
     */
    public static function render(array $nodes, array $values, callable $assetUrl, string $slot = ''): string
    {
        return self::nodes($nodes, [$values], $assetUrl, $slot);
    }

    /** @param list<array> $scopes the block's values, then each enclosing list item */
    private static function nodes(array $nodes, array $scopes, callable $assetUrl, string $slot): string
    {
        $html = '';
        foreach ($nodes as $node) {
            $value = in_array($node[0], ['value', 'raw', 'target', '#', '^'], true) ? self::lookup($scopes, $node[1]) : null;
            if ($node[0] === '#' && is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $html .= self::nodes($node[2], [...$scopes, is_array($item) ? $item : []], $assetUrl, $slot);
                }
                continue;
            }
            $html .= match ($node[0]) {
                'text' => $node[1],
                'value' => self::value($value, $node[2]),
                'raw' => is_string($value) ? $value : '',
                'asset' => Security::e($assetUrl($node[1])),
                'slot' => $slot,
                'target' => is_array($value) && !empty($value['new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '',
                '#', '^' => self::matches($value, $node[3]) === ($node[0] === '#') ? self::nodes($node[2], $scopes, $assetUrl, $slot) : '',
                default => '',
            };
        }

        return $html;
    }

    private static function lookup(array $scopes, string $key): mixed
    {
        for ($index = count($scopes) - 1; $index >= 0; $index--) {
            if (array_key_exists($key, $scopes[$index])) return $scopes[$index][$key];
        }

        return null;
    }

    private static function value(mixed $value, ?string $field): string
    {
        if (is_array($value) && !array_is_list($value)) {
            $value = $value[match ($field) { 'alt' => 'alt', 'css' => 'css', default => '_' }] ?? '';
        }
        $text = Security::e(is_scalar($value) ? (string) $value : '');

        return $field === 'lines' ? nl2br($text, false) : $text;
    }

    private static function matches(mixed $value, ?string $option): bool
    {
        $main = is_array($value) && !array_is_list($value) ? ($value['_'] ?? null) : $value;

        return $option !== null ? $main === $option : !empty($main);
    }

    /** Which part of a value a tag shows, given where it stands. */
    private static function field(array $tag, string $context): ?string
    {
        return match ($tag['spec']['type']) {
            'media' => $tag['field'] ?? 'url',
            'color' => $context === 'css' ? 'css' : null,
            'text' => $context === 'text' ? 'lines' : null,
            default => null,
        };
    }

    /** @return array{0: string, 1: list<array>} the source with tags swapped for markers, and the tags by marker number */
    private static function tokenize(string $source, array $props, string $file, array &$errors): array
    {
        if (preg_match('/[\x{E000}-\x{E003}]/u', $source) === 1 || str_contains($source, 'data-comet-flag-')) {
            $errors[] = ['file' => $file, 'line' => null, 'message' => 'Templates cannot contain the private-use characters U+E000 to U+E003 or data-comet-flag- attributes.'];
            return ['', []];
        }
        $error = static function (int $line, string $message) use ($file, &$errors): void {
            $errors[] = ['file' => $file, 'line' => $line, 'message' => $message];
        };
        $tags = [];
        $open = [];
        // Names visible at this point: the block's props, then the fields of each enclosing list.
        $scopes = [$props];
        $html = '';
        $offset = 0;
        $slots = 0;
        $context = ['tag' => false, 'quote' => ''];
        // The prop spec a tag refers to, or null after reporting why it cannot.
        $resolve = static function (string $raw, string $type, string $key, ?string $field, ?string $option, int $line, bool $rawOutput = false) use (&$scopes, $error): ?array {
            $spec = null;
            for ($index = count($scopes) - 1; $index >= 0 && $spec === null; $index--) {
                $spec = $scopes[$index][$key] ?? null;
            }
            if ($spec === null) {
                $names = array_unique(array_merge(...array_map('array_keys', $scopes)));
                $error($line, $raw . ': unknown prop "' . $key . '". ' . (count($scopes) > 1 ? 'Available here: ' : 'Declared props: ') . ($names === [] ? '(none)' : implode(', ', $names)) . '.');
                return null;
            }
            $problem = match (true) {
                $field !== null && !in_array($field, self::FIELDS[$spec['type']] ?? [], true)
                    => isset(self::FIELDS[$spec['type']]) ? $key . ' offers .' . implode(' and .', self::FIELDS[$spec['type']]) . '.' : 'dotted names only work on media props, as in {{photo.alt}}.',
                $field !== null && $type !== '' => 'sections test the whole prop; write {{#' . $key . '}}.',
                $rawOutput && $spec['type'] !== 'richtext' => 'raw output is only for richtext props; values are always escaped. Use {{' . $key . '}}.',
                !$rawOutput && $type === '' && $spec['type'] === 'richtext' => $key . ' is rich text: output its HTML with {{{' . $key . '}}}.',
                $type === '' && $spec['type'] === 'list' => $key . ' is a list: repeat content for each item with {{#' . $key . '}}…{{/' . $key . '}}.',
                $option !== null && $type === '' => 'comparisons only work in sections, like {{#' . $key . '=' . $option . '}}…{{/' . $key . '}}.',
                $option !== null && $spec['type'] !== 'enum' => 'only enum props can be compared with =.',
                $option !== null && !in_array($option, $spec['options'], true) => '"' . $option . '" is not an option of ' . $key . '. Options: ' . implode(', ', $spec['options']) . '.',
                default => null,
            };
            if ($problem !== null) {
                $error($line, $raw . ': ' . $problem);
                return null;
            }
            return $spec;
        };
        preg_match_all('/\{\{(\{?)(.*?)\}\}(\}?)/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            [$raw, $position] = $match[0];
            if ($position < $offset) continue;
            $line = substr_count($source, "\n", 0, $position) + 1;
            $text = self::text($source, $offset, $position, $error);
            if ($open !== [] && preg_match('/<slot[\s>\/]/i', $text) === 1) {
                $error($line, '<slot> cannot be inside a section: the elements placed in the block always need a place to appear.');
            }
            $slots += preg_match_all('/<slot[\s>\/]/i', $text);
            $html .= $text;
            $context = self::context($text, $context);
            $offset = $position + strlen($raw);
            $inner = trim($match[2][0]);
            if (str_starts_with($inner, '!')) {
                // Comments vanish; keep their newlines so later line numbers still match the source.
                $html .= str_repeat("\n", substr_count($raw, "\n"));
                continue;
            }
            $rawOutput = $match[1][0] === '{' && $match[3][0] === '}';
            if (str_starts_with($inner, '&')) {
                $rawOutput = true;
                $inner = ltrim(substr($inner, 1));
            }
            $hint = match (true) {
                ($match[1][0] === '') !== ($match[3][0] === '') => 'Unbalanced braces. Use {{name}}, or {{{name}}} for rich text.',
                str_starts_with($inner, '>') => 'Partials ({{> name}}) are not supported. Use <slot></slot> to let editors place elements inside the block.',
                str_starts_with($inner, '=') => 'Changing delimiters ({{= =}}) is not supported.',
                str_contains($raw, "\n") => 'Template tags must stay on one line.',
                default => null,
            };
            if ($hint !== null) {
                $error($line, $raw . ': ' . $hint);
                continue;
            }
            if ($context['tag'] && $context['quote'] === '') {
                // Inside a start tag, a section may only toggle one boolean attribute: <details {{#open}}open{{/open}}>.
                $flag = '/\G\{\{\s*([#^])\s*([a-z][a-z0-9_]{0,63})(?:\s*=\s*([^\s{}]+))?\s*\}\}\s*([a-z][a-z-]{0,39})\s*\{\{\s*\/\s*\2(?:\s*=\s*\3)?\s*\}\}/';
                if ($rawOutput || preg_match($flag, $source, $toggle, 0, $position) !== 1) {
                    $error($line, $raw . ': inside an HTML tag, template tags can only toggle a boolean attribute, as in <details {{#open}}open{{/open}}>. Put values in quoted attribute values, as in class="{{name}}".');
                    continue;
                }
                $offset = $position + strlen($toggle[0]);
                $option = isset($toggle[3]) && $toggle[3] !== '' ? $toggle[3] : null;
                $spec = $resolve($raw, $toggle[1], $toggle[2], null, $option, $line);
                if ($spec === null) continue;
                if ($spec['type'] === 'list') {
                    $error($line, $raw . ': lists cannot toggle attributes.');
                    continue;
                }
                $id = count($tags);
                $tags[] = ['type' => $toggle[1], 'key' => $toggle[2], 'field' => null, 'option' => $option, 'spec' => $spec, 'line' => $line, 'pair' => $id + 1, 'source' => $raw, 'attribute' => $toggle[4]];
                $tags[] = ['type' => '/', 'key' => $toggle[2], 'field' => null, 'option' => $option, 'spec' => null, 'line' => $line, 'pair' => $id, 'source' => '{{/' . $toggle[2] . '}}'];
                $html .= ' data-comet-flag-' . $id . ' ';
                continue;
            }
            if (!preg_match('/^([#^\/]?)\s*([a-z][a-z0-9_]{0,63})(?:\.([a-z][a-z0-9_]{0,63}))?(?:\s*=\s*([^\s{}]+))?$/D', $inner, $tag)
                || ($rawOutput && $tag[1] !== '')) {
                $error($line, $raw . ': Unrecognized tag. Use {{name}}, {{{richtext}}}, {{#name}}…{{/name}}, {{^name}}…{{/name}}, {{#name=option}}…{{/name}} or {{! comment }}.');
                continue;
            }
            [, $type, $key] = $tag;
            $field = isset($tag[3]) && $tag[3] !== '' ? $tag[3] : null;
            $option = isset($tag[4]) && $tag[4] !== '' ? $tag[4] : null;
            if ($type === '/') {
                $last = end($open);
                if ($last === false || $tags[$last]['key'] !== $key || ($option !== null && $tags[$last]['option'] !== $option)) {
                    $error($line, $raw . ($last === false ? ' closes a section that was never opened.' : ' does not match the open section ' . $tags[$last]['source'] . ' (line ' . $tags[$last]['line'] . ').'));
                    continue;
                }
                array_pop($open);
                if (($tags[$last]['spec']['type'] ?? '') === 'list' && $tags[$last]['type'] === '#') array_pop($scopes);
                $tags[$last]['pair'] = count($tags);
                $tags[] = ['type' => '/', 'key' => $key, 'field' => null, 'option' => $option, 'spec' => null, 'line' => $line, 'pair' => $last, 'source' => $raw];
                $html .= sprintf(BlockMarkup::TAG, count($tags) - 1);
                continue;
            }
            $spec = $resolve($raw, $type, $key, $field, $option, $line, $rawOutput);
            if ($type === '') {
                if ($spec === null) continue;
                $tags[] = ['type' => $rawOutput ? 'raw' : 'value', 'key' => $key, 'field' => $field, 'option' => null, 'spec' => $spec, 'line' => $line, 'pair' => null, 'source' => $raw];
                $html .= sprintf(BlockMarkup::TAG, count($tags) - 1);
                continue;
            }
            if (count($open) >= self::MAX_DEPTH) {
                $error($line, 'Sections can nest at most ' . self::MAX_DEPTH . ' levels deep.');
                continue;
            }
            // A rejected opener still opens its section, so its closing tag does not add a second error.
            $open[] = count($tags);
            $tags[] = ['type' => $type, 'key' => $key, 'field' => null, 'option' => $option, 'spec' => $spec, 'line' => $line, 'pair' => null, 'source' => $raw];
            if (($spec['type'] ?? '') === 'list' && $type === '#') $scopes[] = $spec['fields'];
            $html .= sprintf(BlockMarkup::TAG, count($tags) - 1);
        }
        $text = self::text($source, $offset, strlen($source), $error);
        $slots += preg_match_all('/<slot[\s>\/]/i', $text);
        $html .= $text;
        foreach ($open as $id) {
            $error($tags[$id]['line'], $tags[$id]['source'] . ' is never closed. Add {{/' . $tags[$id]['key'] . '}}.');
        }
        if ($slots > 1) {
            $error(1, 'A block has at most one <slot>.');
        }

        return [$html, $tags];
    }

    /** Track whether the text so far leaves us inside a start tag, and inside a quoted attribute value. */
    private static function context(string $text, array $context): array
    {
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if (!$context['tag']) {
                if ($char === '<' && $i + 1 < $length && (ctype_alpha($text[$i + 1]) || $text[$i + 1] === '/')) $context['tag'] = true;
            } elseif ($context['quote'] !== '') {
                if ($char === $context['quote']) $context['quote'] = '';
            } elseif ($char === '"' || $char === "'") {
                $context['quote'] = $char;
            } elseif ($char === '>') {
                $context['tag'] = false;
            }
        }

        return $context;
    }

    /** The literal text between two tags, which must not contain half a tag. */
    private static function text(string $source, int $from, int $to, callable $error): string
    {
        $text = substr($source, $from, $to - $from);
        foreach (['{{' => 'Unclosed template tag: add the closing }}.', '}}' => 'Stray }} without an opening {{.'] as $needle => $message) {
            $position = strpos($text, $needle);
            if ($position !== false) {
                $error(substr_count($source, "\n", 0, $from + $position) + 1, $message);
            }
        }

        return $text;
    }
}
