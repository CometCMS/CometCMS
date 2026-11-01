<?php

declare(strict_types=1);

namespace CometCMS\Site\Blocks;

use CometCMS\Core\HtmlSanitizer;

/**
 * Install-time HTML checks for block templates. A template is parsed once, checked
 * against an allow-list and re-serialized, so stored templates are always well-formed
 * and rendering only has to escape prop values. Disallowed markup is an error, never
 * silently removed.
 */
final class BlockMarkup
{
    /** Template tags, and extras such as assets and the slot, stand in as these markers while the HTML is parsed. */
    public const TAG = "\u{E000}%d\u{E001}";
    public const EXTRA = "\u{E002}%d\u{E003}";
    public const MARKERS = '/\x{E000}(\d+)\x{E001}|\x{E002}(\d+)\x{E003}/u';
    private const TAG_MARKER = '/\x{E000}(\d+)\x{E001}/u';
    private const ROOT = '__comet_block_root__';

    private const GLOBAL_ATTRIBUTES = ['class', 'id', 'title', 'role', 'lang', 'dir', 'hidden', 'inert', 'popover', 'translate', 'tabindex'];

    private const ELEMENTS = [
        'a' => ['href', 'target', 'rel', 'hreflang'],
        'abbr' => [], 'address' => [], 'article' => [], 'aside' => [], 'b' => [], 'bdi' => [], 'blockquote' => [], 'br' => [],
        'button' => ['type', 'popovertarget', 'popovertargetaction', 'disabled'],
        'caption' => [], 'cite' => [], 'code' => [], 'col' => ['span'], 'colgroup' => ['span'], 'dd' => [], 'del' => ['datetime'],
        'details' => ['open', 'name'], 'dfn' => [], 'div' => [], 'dl' => [], 'dt' => [], 'em' => [], 'figcaption' => [], 'figure' => [],
        'footer' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [], 'header' => [], 'hgroup' => [], 'hr' => [], 'i' => [],
        'img' => ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding', 'fetchpriority'],
        'ins' => ['datetime'], 'kbd' => [], 'li' => ['value'], 'mark' => [], 'nav' => [], 'ol' => ['start', 'reversed', 'type'], 'p' => [],
        'picture' => [], 'pre' => [], 'q' => [], 's' => [], 'samp' => [], 'search' => [], 'section' => [], 'small' => [],
        'source' => ['src', 'srcset', 'sizes', 'media', 'type', 'width', 'height'],
        'span' => [], 'strong' => [], 'sub' => [], 'summary' => [], 'sup' => [],
        'table' => [], 'tbody' => [], 'td' => ['colspan', 'rowspan', 'headers'], 'tfoot' => [], 'th' => ['colspan', 'rowspan', 'headers', 'scope'],
        'thead' => [], 'time' => ['datetime'], 'tr' => [], 'u' => [], 'ul' => [], 'var' => [],
        'video' => ['src', 'poster', 'controls', 'autoplay', 'muted', 'loop', 'playsinline', 'preload', 'width', 'height'],
        'wbr' => [],
    ];

    /** Lowercase, as libxml reports them; browsers restore SVG's camelCase names. */
    private const SVG_ELEMENTS = [
        'svg' => ['viewbox', 'width', 'height', 'xmlns', 'preserveaspectratio'],
        'g' => [], 'defs' => [], 'path' => ['d', 'pathlength'], 'circle' => ['cx', 'cy', 'r'], 'ellipse' => ['cx', 'cy', 'rx', 'ry'],
        'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry'], 'line' => ['x1', 'y1', 'x2', 'y2'],
        'polyline' => ['points'], 'polygon' => ['points'],
        'lineargradient' => ['x1', 'y1', 'x2', 'y2', 'gradientunits', 'gradienttransform'],
        'radialgradient' => ['cx', 'cy', 'r', 'fx', 'fy', 'gradientunits', 'gradienttransform'],
        'stop' => ['offset', 'stop-color', 'stop-opacity'],
    ];

    private const SVG_ATTRIBUTES = [
        'class', 'id', 'role', 'focusable', 'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width', 'stroke-linecap',
        'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'transform', 'vector-effect',
    ];

    private const VOID = ['br', 'col', 'hr', 'img', 'source', 'wbr'];
    /** Attributes a section may toggle inside a start tag: <details {{#open}}open{{/open}}>. */
    private const BOOLEAN_ATTRIBUTES = ['open', 'hidden', 'inert', 'disabled', 'controls', 'autoplay', 'muted', 'loop', 'playsinline', 'reversed'];
    private const URL_ATTRIBUTES = ['href', 'src', 'poster'];
    /** Browsers close an open <p> before these, which libxml does not know for HTML5 elements. */
    private const CLOSES_PARAGRAPH = [
        'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'header', 'hgroup', 'hr', 'nav', 'ol', 'p', 'pre', 'search', 'section', 'table', 'ul',
    ];

    /** Elements whose content may be block-level HTML: where rich text and slots can go. */
    private const FLOW = ['root', 'div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'search', 'li', 'dd', 'td', 'th', 'blockquote', 'figure', 'figcaption', 'details'];
    /** Prop types whose value is a URL. Links only fit href; media and plain URLs fit any URL attribute. */
    private const URL_TYPES = ['url', 'link', 'media'];

    private const HINTS = [
        'script' => 'Put JavaScript in the block\'s view.js module.',
        'style' => 'Put CSS in the block\'s style.css.',
        'link' => 'Put CSS in the block\'s style.css.',
        'iframe' => 'Embeds are not supported.',
        'form' => 'Use the built-in form element for forms.',
        'input' => 'Use the built-in form element for forms.',
        'select' => 'Use the built-in form element for forms.',
        'textarea' => 'Use the built-in form element for forms.',
    ];

    /** @var array<int, string> marker number => where it was found */
    private array $seen = [];
    /** @var array<int, string> value marker number => text, attr, url or css */
    private array $contexts = [];
    /** @var list<array> extra template nodes: ['asset', file], ['slot'] and ['target', key] */
    private array $extras = [];

    /**
     * @param list<array{type: string, key: string, field: ?string, option: ?string, spec: ?array, line: int, pair: ?int, source: string}> $tags by marker number
     * @param array<string, string> $assets package path => published asset file
     */
    private function __construct(
        private readonly string $file,
        private readonly array $tags,
        private readonly array $assets,
        private array &$errors,
    ) {}

    /**
     * @return array{0: string, 1: list<array>, 2: array<int, string>} the serialized template,
     *         the extra nodes its extra markers refer to, and the context of each value
     */
    public static function normalize(string $html, string $file, array $tags, array $assets, array &$errors): array
    {
        $markup = new self($file, $tags, $assets, $errors);
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8"><div id="' . self::ROOT . '">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        foreach (libxml_get_errors() as $error) {
            $message = trim($error->message);
            // libxml predates HTML5 and SVG: unknown elements are checked against the allow-list instead.
            if (preg_match('/^Tag \S+ invalid$/D', $message) || str_starts_with($message, 'htmlParseEntityRef')) {
                continue;
            }
            $markup->error($error->line, 'HTML: ' . rtrim($message, '.') . '.');
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById(self::ROOT);
        if (!$root instanceof \DOMElement) {
            $markup->error(null, 'The template could not be parsed as HTML.');
            return ['', [], []];
        }
        if ($root->nextSibling !== null) {
            $markup->error($root->nextSibling->getLineNo(), 'The template closes more elements than it opens.');
        }
        $output = $markup->children($root, 'root');
        $markup->checkTags();

        return [$output, $markup->extras, $markup->contexts];
    }

    private function children(\DOMNode $node, string $container): string
    {
        $html = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $html .= $this->node($child, $container);
        }

        return $html;
    }

    private function node(\DOMNode $node, string $container): string
    {
        if ($node instanceof \DOMText) {
            $text = $node->nodeValue ?? '';
            $this->mark($text, $container, 'text');
            foreach ($this->markers($text) as $id) {
                if ($this->tags[$id]['type'] === 'raw' && !$this->flow($node->parentNode)) {
                    $this->error($node->getLineNo(), $this->tags[$id]['source'] . ' outputs HTML paragraphs and lists, so it must be inside a <div> or another block container, not <' . $this->tagName($node->parentNode) . '>.');
                }
            }
            return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if ($node instanceof \DOMComment) {
            if (preg_match(self::TAG_MARKER, $node->nodeValue ?? '') === 1) {
                $this->error($node->getLineNo(), 'Template tags cannot be inside HTML comments. Use {{! comment }} for template comments.');
                $this->mark($node->nodeValue ?? '', 'comment');
            }
            return '';
        }

        return $node instanceof \DOMElement ? $this->element($node, $container) : '';
    }

    private function element(\DOMElement $element, string $container): string
    {
        $tag = strtolower($element->tagName);
        $line = $element->getLineNo();
        if ($tag === 'slot') {
            return $this->slot($element, $line);
        }
        $svg = isset(self::SVG_ELEMENTS[$tag]);
        if (!$svg && !isset(self::ELEMENTS[$tag])) {
            $this->error($line, '<' . $tag . '> is not allowed in block templates.' . (isset(self::HINTS[$tag]) ? ' ' . self::HINTS[$tag] : ''));
            $this->mark($element->textContent, 'removed');
            return '';
        }
        $insideSvg = $this->inside($element, 'svg');
        if ($svg && $tag !== 'svg' && !$insideSvg) {
            $this->error($line, '<' . $tag . '> must be inside <svg>.');
        } elseif (!$svg && $insideSvg) {
            $this->error($line, '<' . $tag . '> cannot be inside <svg>.');
        }
        if (in_array($tag, self::CLOSES_PARAGRAPH, true) && $this->inside($element, 'p')) {
            $this->error($line, '<' . $tag . '> cannot be inside <p>: browsers close the paragraph before it. Use a <div> instead of the <p>.');
        }

        $self = (string) spl_object_id($element);
        $attributes = '';
        $linkTarget = null;
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = $attribute->nodeValue ?? '';
            if (preg_match(self::TAG_MARKER, $name) === 1) {
                $this->error($line, 'Template tags can only be used inside quoted attribute values. To toggle an attribute, write <details {{#open}}open{{/open}}>, or vary a class.');
                $this->mark($name, 'attribute-name');
                continue;
            }
            if (preg_match('/^data-comet-flag-(\d+)$/D', $name, $flag) === 1 && isset($this->tags[(int) $flag[1]]['attribute'])) {
                $attributes .= $this->flag((int) $flag[1], $tag, $svg, $line, $self);
                continue;
            }
            if ($name === 'style' && !$svg) {
                $value = $this->style($value, $line, $self . '@style');
                if ($value !== null) $attributes .= ' style="' . $value . '"';
                continue;
            }
            if (!$this->allowedAttribute($tag, $name, $svg)) {
                $this->error($line, 'Attribute "' . $name . '" is not allowed on <' . $tag . '>.'
                    . (str_starts_with($name, 'on') ? ' Put JavaScript in the block\'s view.js module.' : ''));
                $this->mark($value, 'removed');
                continue;
            }
            if ($tag === 'a' && $name === 'rel' && $element->hasAttribute('target')) {
                continue;
            }
            if ($tag === 'button' && $name === 'type' && $value !== 'button') {
                $this->error($line, 'Buttons must use type="button"; forms are not supported in block templates.');
            }
            $value = $this->attributeValue($tag, $name, $value, $line, $self . '@' . $name, $linkTarget);
            if ($value !== null) {
                $attributes .= ' ' . $name . ($value === '' ? '' : '="' . $value . '"');
            }
        }
        // Links that open a new browsing context must not get a handle on this page.
        if ($tag === 'a' && $element->hasAttribute('target')) {
            $rel = preg_split('/\s+/', trim($element->getAttribute('rel')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $attributes .= ' rel="' . htmlspecialchars(implode(' ', array_unique([...$rel, 'noopener', 'noreferrer'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            $this->mark($element->getAttribute('rel'), $self . '@rel', 'attr');
        } elseif ($linkTarget !== null) {
            // A link prop the editor set to open in a new tab adds target and rel itself.
            $attributes .= $this->extra(['target', $linkTarget]);
        }
        if ($linkTarget !== null && !$element->hasAttribute('data-link-modal')) {
            $attributes .= $this->extra(['modal', $linkTarget, !$element->hasAttribute('aria-haspopup')]);
        }

        // libxml nests the siblings of HTML5 void elements it does not know; browsers do not.
        return in_array($tag, self::VOID, true)
            ? '<' . $tag . $attributes . '>' . $this->children($element, $container)
            : '<' . $tag . $attributes . '>' . $this->children($element, $self) . '</' . $tag . '>';
    }

    /** Where a block's child elements go. */
    private function slot(\DOMElement $element, ?int $line): string
    {
        if ($element->attributes->length > 0 || trim($element->textContent) !== '' || $element->getElementsByTagName('*')->length > 0) {
            $this->error($line, '<slot> stays empty and takes no attributes: it marks where the elements placed in the block appear.');
        }
        if (array_filter($this->extras, static fn(array $extra): bool => $extra[0] === 'slot') !== []) {
            $this->error($line, 'A block has at most one <slot>.');
        }
        if (!$this->flow($element->parentNode)) {
            $this->error($line, '<slot> holds block elements, so it must be inside a <div> or another block container, not <' . $this->tagName($element->parentNode) . '>.');
        }

        return $this->extra(['slot']);
    }

    /** A boolean attribute that a section switches on or off. */
    private function flag(int $open, string $tag, bool $svg, ?int $line, string $element): string
    {
        $section = $this->tags[$open];
        $this->seen[$open] = $this->seen[$section['pair']] = $element . '@flag';
        if (!in_array($section['attribute'], self::BOOLEAN_ATTRIBUTES, true) || !$this->allowedAttribute($tag, $section['attribute'], $svg)) {
            $this->error($line, $section['source'] . $section['attribute'] . '{{/' . $section['key'] . '}}: only boolean attributes that <' . $tag . '> allows can be toggled (' . implode(', ', self::BOOLEAN_ATTRIBUTES) . ').');
            return '';
        }

        return sprintf(self::TAG, $open) . ' ' . $section['attribute'] . sprintf(self::TAG, $section['pair']);
    }

    /** Inline styles may only set custom properties from color props: style="--accent: {{accent}}". */
    private function style(string $value, ?int $line, string $location): ?string
    {
        $declaration = '(--[a-z][a-z0-9-]{0,40})\s*:\s*(\x{E000}(\d+)\x{E001})\s*';
        preg_match_all('/' . $declaration . '/u', $value, $declarations, PREG_SET_ORDER);
        $colors = array_filter($declarations, fn(array $match): bool => $this->tags[(int) $match[3]]['type'] === 'value' && ($this->tags[(int) $match[3]]['spec']['type'] ?? '') === 'color');
        if (preg_match('/^\s*(?:' . $declaration . '(?:;\s*|$))+$/uD', $value) !== 1 || count($colors) !== count($declarations)) {
            $this->error($line, 'The style attribute may only set CSS custom properties from color props, as in style="--accent: {{accent}}". Put other CSS in style.css.');
            $this->mark($value, 'removed');
            return null;
        }
        $this->mark($value, $location, 'css');

        return implode(';', array_map(static fn(array $match): string => $match[1] . ':' . $match[2], $declarations));
    }

    private function allowedAttribute(string $tag, string $name, bool $svg): bool
    {
        if (preg_match('/^(?:aria|data)-[a-z0-9-]+$/D', $name) === 1) {
            return true;
        }

        return $svg
            ? in_array($name, self::SVG_ELEMENTS[$tag], true) || in_array($name, self::SVG_ATTRIBUTES, true)
            : in_array($name, self::ELEMENTS[$tag], true) || in_array($name, self::GLOBAL_ATTRIBUTES, true);
    }

    private function attributeValue(string $tag, string $name, string $value, ?int $line, string $location, ?string &$linkTarget): ?string
    {
        foreach ($this->markers($value) as $id) {
            if ($this->tags[$id]['type'] === 'raw') {
                $this->error($line, $this->tags[$id]['source'] . ' outputs HTML, which cannot go into an attribute.');
                $this->mark($value, 'removed');
                return null;
            }
        }
        if ($name === 'srcset') {
            if (preg_match(self::TAG_MARKER, $value) === 1) {
                $this->error($line, 'srcset accepts literal URLs and assets/ paths only.');
                $this->mark($value, $location);
                return null;
            }
            $candidates = [];
            foreach (preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
                $parts = preg_split('/\s+/', $candidate, 2) ?: [''];
                $url = $this->literalUrl($parts[0], $line, $name);
                if ($url === null) return null;
                $candidates[] = $url . (isset($parts[1]) ? ' ' . htmlspecialchars($parts[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '');
            }
            return implode(', ', $candidates);
        }
        if (in_array($name, self::URL_ATTRIBUTES, true)) {
            $trimmed = trim($value);
            if (preg_match('/^\x{E000}(\d+)\x{E001}$/uD', $trimmed, $match) === 1) {
                $marked = $this->tags[(int) $match[1]];
                $type = $marked['spec']['type'] ?? '';
                if ($marked['type'] === 'value' && in_array($type, self::URL_TYPES, true) && $marked['field'] !== 'alt' && ($type !== 'link' || ($name === 'href' && $tag === 'a'))) {
                    $this->mark($trimmed, $location, 'url');
                    if ($type === 'link') $linkTarget = $marked['key'];
                    return $trimmed;
                }
            }
            if (preg_match(self::TAG_MARKER, $value) === 1) {
                $this->error($line, $name . ' accepts a literal URL, an assets/ path or exactly one url, link or media prop, such as {{link}} or {{photo}}. link props only fit <a href>.');
                $this->mark($value, $location);
                return null;
            }
            return $this->literalUrl($trimmed, $line, $name);
        }
        $this->mark($value, $location, 'attr');

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function literalUrl(string $url, ?int $line, string $name): ?string
    {
        if (str_starts_with($url, 'assets/')) {
            if (!isset($this->assets[$url])) {
                $this->error($line, 'The package has no file ' . $url . '.');
                return null;
            }
            return $this->extra(['asset', $this->assets[$url]]);
        }
        if ($url !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|#|\?)~i', $url)) {
            $this->error($line, 'The relative URL "' . $url . '" in ' . $name . ' would resolve against each page\'s address. Reference package files as assets/…, and site pages with a leading /.');
            return null;
        }
        if (!HtmlSanitizer::isSafeUrl($url)) {
            $this->error($line, 'Unsafe URL in ' . $name . ': use http, https, mailto, tel or a path.');
            return null;
        }

        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function extra(array $node): string
    {
        $this->extras[] = $node;

        return sprintf(self::EXTRA, count($this->extras) - 1);
    }

    private function inside(\DOMElement $element, string $tag): bool
    {
        for ($parent = $element->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
            if ($parent->getAttribute('id') === self::ROOT) return false;
            if (strtolower($parent->tagName) === $tag) return true;
        }

        return false;
    }

    private function flow(?\DOMNode $parent): bool
    {
        return in_array($this->tagName($parent), self::FLOW, true);
    }

    private function tagName(?\DOMNode $node): string
    {
        if (!$node instanceof \DOMElement) return '';

        return $node->getAttribute('id') === self::ROOT ? 'root' : strtolower($node->tagName);
    }

    /** @return list<int> the tag markers in a string */
    private function markers(string $value): array
    {
        preg_match_all(self::TAG_MARKER, $value, $matches);

        return array_map('intval', $matches[1]);
    }

    private function mark(string $value, string $location, ?string $context = null): void
    {
        foreach ($this->markers($value) as $id) {
            $this->seen[$id] ??= $location;
            if ($context !== null) $this->contexts[$id] ??= $context;
        }
    }

    /** Every tag must survive parsing, and each section must open and close in the same place. */
    private function checkTags(): void
    {
        foreach ($this->tags as $id => $tag) {
            if ($tag['type'] === '/') continue;
            $location = $this->seen[$id] ?? null;
            if ($location === null) {
                $this->error($tag['line'], $tag['source'] . ' is in a place HTML cannot hold it, such as inside a tag name.');
            } elseif (in_array($location, ['removed', 'comment', 'attribute-name'], true)) {
                continue;
            } elseif ($tag['pair'] !== null && ($this->seen[$tag['pair']] ?? null) !== $location
                && !in_array($this->seen[$tag['pair']] ?? null, ['removed', 'comment', 'attribute-name'], true)) {
                $this->error($tag['line'], 'The section ' . $tag['source'] . ' closes in a different element than it opens (line ' . $this->tags[$tag['pair']]['line']
                    . '). Open and close each section inside the same element, or inside the same attribute value.');
            }
        }
    }

    private function error(?int $line, string $message): void
    {
        $this->errors[] = ['file' => $this->file, 'line' => $line, 'message' => $message];
    }
}
