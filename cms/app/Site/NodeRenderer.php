<?php

declare(strict_types=1);

namespace CometCMS\Site;

use CometCMS\Core\HtmlSanitizer;
use CometCMS\Core\Security;
use CometCMS\Localization\LocalizedValue;
use CometCMS\Localization\Messages;
use CometCMS\Extensions\ExtensionRegistry;
use CometCMS\Site\Blocks\BlockTemplate;

/**
 * Turns layout trees into HTML.
 *
 * Every dynamic value is escaped with Security::e() or passed through the
 * HTML allow-list; style values use a fixed vocabulary (see LayoutSchema)
 * and generated CSS declarations, so layouts cannot inject markup.
 *
 * Render state ($state) carries what the nodes are bound to:
 *   entry      the entry the layout shows (null for header/footer without one)
 *   type       its content type schema
 *   row        current repeater row inside an item template
 *   row_fields subfield configs of that repeater, keyed by subfield key
 *   depth      nesting of card renders, to stop relation cycles
 *   nested     inside a column: rows here size their columns by the column's width
 *   embedded   sections of page content placed in a column: they fill the column
 *   nested_cols the columns of such a nested row
 *   components ids of the components being rendered, to stop cycles
 *   areas      inside a component: what its instance put into each content
 *              area, and how the page around the instance renders
 *   page       current page number for paginated collections
 *   path       current URL path, for pagination and active menu items
 *   alternates locale => URL of the current page, for language switchers
 *   sizes      "sizes" hint for responsive images inside grids
 */
final class NodeRenderer
{
    private const MAX_CARD_DEPTH = 2;

    private const MAX_COMPONENT_DEPTH = 5;

    /** Typical screen widths in CSS pixels, from site.css's breakpoints; backgrounds are chosen for them. */
    private const SCREEN_WIDTHS = ['desktop' => 1920, 'tablet' => 1024, 'mobile' => 430];

    /** Screens are assumed this dense, so images stay sharp on most phones and laptops. */
    private const PIXEL_DENSITY = 2;

    /** Utility class of each style value, without its `c-` (see styleClasses). */
    private const STYLE_TOKENS = [
        'padding_top' => 'pt-',
        'padding_right' => 'pr-',
        'padding_bottom' => 'pb-',
        'padding_left' => 'pl-',
        'margin_top' => 'mt-',
        'margin_right' => 'mr-',
        'margin_left' => 'ml-',
        'margin_bottom' => 'mb-',
        'background' => 'bg-',
        'text_color' => 'fg-',
        'link_color' => 'lc-',
        'align' => 'ta-',
        'font_size' => 'fs-',
        'font_weight' => 'fw-',
        'font_family' => 'ff-',
        'text_shadow' => 'ts-',
        'radius' => 'r-',
        'shadow' => 'sh-',
        'max_width' => 'mw-',
        'border_color' => 'bdc-',
        'position' => 'pos-',
        'pin' => 'pin-',
        'z_index' => 'z-',
        'opacity' => 'op-',
    ];

    /** Offset variables of each device; site.css resolves them, mobile following tablet. */
    private const OFFSET_VARIABLES = [
        'normal' => ['offset_x' => '--c-ox', 'offset_y' => '--c-oy'],
        'tablet' => ['offset_x' => '--c-t-ox', 'offset_y' => '--c-t-oy'],
        'mobile' => ['offset_x' => '--c-m-ox', 'offset_y' => '--c-m-oy'],
    ];

    /** Utility class of each prop a device may change on the node's own element. */
    private const PROP_TOKENS = [
        'section' => ['vertical_align' => 'v-'],
        'row' => ['gap' => 'gap-', 'align' => 'items-'],
        'column' => ['vertical_align' => 'self-', 'spacing' => 'stack-', 'content_justify' => 'flow-justify-', 'content_align' => 'flow-align-'],
        'spacer' => ['size' => 'spacer-'],
    ];

    /** Button color props and their classes on the button itself. */
    private const BUTTON_COLOR_TOKENS = ['button_color' => 'btn-bg-', 'button_text_color' => 'btn-fg-', 'button_border_color' => 'btn-bdc-'];

    private int $annotationsSuspended = 0;

    private int $menuSequence = 0;

    private int $formSequence = 0;

    private int $tabsSequence = 0;

    private int $accordionSequence = 0;

    private int $searchSequence = 0;

    /** Most pages of any paginated list rendered so far; 0 without one. */
    public int $listPages = 0;

    private SmartValue $smart;

    private array $blockStyles = [];
    /** @var array<string, true> */
    private array $blockScripts = [];

    public function __construct(private readonly SiteContext $ctx)
    {
        $this->smart = new SmartValue($ctx);
    }

    public function render(array $nodes, array $state = []): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $html .= $this->node($node, $state);
        }

        return $html;
    }

    /** Render without builder annotations (repeated cards would duplicate node ids). */
    public function renderPlain(array $nodes, array $state = []): string
    {
        $this->annotationsSuspended++;

        try {
            return $this->render($nodes, $state);
        } finally {
            $this->annotationsSuspended--;
        }
    }

    private function node(array $node, array $state, ?int $order = null): string
    {
        foreach ($node['props'] ?? [] as $key => $value) if (LocalizedValue::is($value)) $node['_localized'][$key] = true;
        $node['props'] = LocalizedValue::tree($node['props'] ?? [], $this->ctx->htmlLang(), $this->ctx->defaultLocale());
        $node['style'] = LocalizedValue::tree($node['style'] ?? [], $this->ctx->htmlLang(), $this->ctx->defaultLocale());
        // An element whose conditions fail is left out; the builder keeps it, marked, so it stays selectable.
        if (($node['style']['visible_if'] ?? []) !== [] && !ElementConditions::matches($node['style']['visible_if'], $this->smart, $state)) {
            if (!$this->annotating()) return '';
            $node['style']['_condition_off'] = true;
        }
        $warnings = false;
        foreach (LayoutSchema::bindings((string) ($node['kind'] ?? ''), $this->ctx->site->blocks()) as $key => $target) {
            $binding = $node['props'][$key] ?? null;
            if ($target === 'link' && ($binding['type'] ?? '') === 'dynamic') {
                $resolved = $this->smart->resolve($binding['value'], $state);
                $node['props'][$key] = $this->boundLink($resolved, (bool) ($binding['new_tab'] ?? false));
                $warnings = $warnings || $resolved['missing'];
                continue;
            }
            if (!SmartValue::isDynamic($binding) || $key === 'source') continue;
            $resolved = $this->resolveBinding($binding, $target, $state);
            $node['props'][$key] = $resolved['value'];
            if ($target === 'media_list') $node['_gallery_label'] = $resolved['label'] ?? '';
            if (in_array($target, ['text', 'html'], true)) {
                $node['_resolved'][$key] = true;
            }
            $warnings = $warnings || $resolved['missing'];
        }
        if (($node['kind'] ?? '') === 'accordion') {
            foreach ($node['props']['items'] ?? [] as $index => $item) {
                foreach (['title' => 'text', 'html' => 'html'] as $key => $target) {
                    if (!SmartValue::isDynamic($item[$key] ?? null)) continue;
                    $resolved = $this->resolveBinding($item[$key], $target, $state);
                    $node['props']['items'][$index][$key] = $resolved['value'];
                    $warnings = $warnings || $resolved['missing'];
                }
            }
        }
        if (($node['kind'] ?? '') === 'table') {
            foreach ($node['props']['rows'] ?? [] as $r => $row) {
                foreach ($row as $c => $cell) {
                    if (!SmartValue::isDynamic($cell)) continue;
                    $resolved = $this->resolveBinding($cell, 'html', $state);
                    $node['props']['rows'][$r][$c] = $resolved['value'];
                    $warnings = $warnings || $resolved['missing'];
                }
            }
        }
        foreach (SmartValue::STYLE_PROPERTIES as $key => $target) {
            $binding = $node['style'][$key] ?? null;
            if (!SmartValue::isDynamic($binding)) continue;
            $resolved = $this->resolveBinding($binding, $target, $state);
            $node['style'][$key] = $resolved['value'];
            $warnings = $warnings || $resolved['missing'];
        }
        $html = match ($node['kind'] ?? '') {
            'section' => $this->section($node, $state),
            'row' => $this->row($node, $state),
            'column' => $this->column($node, $state, $order),
            LayoutSchema::COMPONENT => $this->component($node, $state, $order),
            LayoutSchema::PAGE_CONTENT => $this->pageContent($node, $state),
            LayoutSchema::COMPONENT_AREA => $this->componentArea($node, $state),
            // Area content shows where its component's area is.
            LayoutSchema::AREA_CONTENT => '',
            LayoutSchema::MODAL => $this->modal($node, $state),
            LayoutSchema::SITE_CONTENT => $this->siteContent($node, $state),
            'modal_title' => $this->modalTitle($node, $state),
            'modal_close' => $this->modalClose($node, $state),
            'field' => $this->field($node, $state),
            'heading' => $this->heading($node, $state),
            'text' => $this->text($node, $state),
            'html' => $this->html($node),
            'image' => $this->image($node, $state),
            'video' => $this->video($node),
            'gallery' => $this->gallery($node, $state),
            'button' => $this->button($node, $state),
            'accordion' => $this->accordion($node),
            'table' => $this->table($node),
            'tabs' => $this->tabs($node, $state),
            'tab' => $this->element($node, $this->render($node['children'], $state)),
            'icon' => $this->icon($node, $state),
            'spacer' => $this->element($node, '', ['c-spacer', 'c-spacer-' . $node['props']['size']]),
            'divider' => $this->element($node, '<hr class="c-divider' . (!empty($node['style']['text_color']) ? ' c-divider-colored' : '') . '">'),
            'collection' => $this->collection($node, $state),
            'form' => $this->form($node, $state),
            'menu' => $this->menu($node, $state),
            'search' => $this->search($node),
            'site_title' => $this->siteTitle($node, $state),
            'language_switcher' => $this->languageSwitcher($node, $state),
            'theme_toggle' => $this->themeToggle($node),
            default => ExtensionRegistry::isKind((string) ($node['kind'] ?? '')) ? $this->block($node, $state) : '',
        };
        if ($warnings && $this->annotating()) {
            $warning = '<div class="c-placeholder c-placeholder-warning" role="status"' . $this->placeholder('unresolvedFallback') . '>Unresolved content reference (using fallback if configured)</div>';
            // Keep the warning inside the annotated root so hover, selection and
            // the node badge use its full bounds even when the content is empty.
            $html = $html === ''
                ? $this->element($node, $warning)
                : substr_replace($html, $warning, strpos($html, '>') + 1, 0);
        }

        return $html;
    }

    /** @return list<string> block kinds rendered so far whose scripts the page loads */
    public function blockScripts(): array
    {
        return array_keys($this->blockScripts);
    }

    /** CSS of the extensions and blocks rendered so far: each extension's shared CSS, then its blocks'. */
    public function blockStylesheet(): string
    {
        $blocks = $this->ctx->site->blocks();
        $assetUrl = $this->ctx->blockAssetUrl(...);
        $css = [];
        foreach ($this->blockStyles as $name => $kinds) {
            $parts = $blocks->extension($name)['css'] ?? [];
            foreach (array_keys($kinds) as $kind) {
                array_push($parts, "\n", ...($blocks->get($kind)['css'] ?? []));
            }
            $css[] = trim(implode('', array_map(static fn(string|array $part): string => is_array($part) ? $assetUrl($part[1]) : $part, $parts)));
        }

        return implode("\n", array_filter($css));
    }

    private function block(array $node, array $state): string
    {
        $blocks = $this->ctx->site->blocks();
        $block = $blocks->get($node['kind']);
        if ($block === null) {
            return $this->annotating() ? $this->element($node, '<div class="c-placeholder">Missing block: ' . Security::e(substr($node['kind'], 6)) . '</div>') : '';
        }
        $slot = '';
        if ($block['slot']) {
            $slot = $this->render($node['children'] ?? [], $state);
            if ($slot === '' && $this->annotating()) {
                $slot = '<div class="c-placeholder c-placeholder-drop">Drop elements here</div>';
            }
        }
        $values = $this->blockValues($block['props'], $node['props'] ?? [], $state);
        // Compiled templates are well-formed allow-listed HTML; render only escapes values.
        $html = BlockTemplate::render($block['template'], $values, $this->ctx->blockAssetUrl(...), $slot);
        $this->blockStyles[$block['extension']][$node['kind']] = true;
        // Installed block scripts also mount in previews, using the same resolved props.
        $attributes = '';
        if ($block['script'] !== '') {
            $this->blockScripts[$node['kind']] = true;
            $attributes = ' data-comet-block="' . Security::e($block['name']) . '" data-comet-props="'
                . Security::e(json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)) . '"';
        }

        return $this->element($node, $html, [ExtensionRegistry::cssClass($block['name'])], '', $attributes);
    }

    /**
     * Saved block props (already localized and with bindings resolved) as template
     * values: media become URLs with alt text, colors theme keys with their CSS
     * value, links an href with the editor's new-tab choice.
     */
    private function blockValues(array $specs, array $props, array $state): array
    {
        $values = [];
        foreach ($specs as $key => $spec) {
            $value = $props[$key] ?? $spec['default'];
            $values[$key] = match ($spec['type']) {
                'media' => $this->blockMedia(is_string($value) ? $value : ''),
                'color' => is_string($value) && $value !== '' ? ['_' => $value, 'css' => 'var(--c-' . $value . ')'] : ['_' => '', 'css' => 'initial'],
                'link' => ($link = is_array($value) ? $this->resolveLink($value, $state) : null) !== null ? ['_' => $link['href'], 'new_tab' => $link['new_tab']] : ['_' => ''],
                'url' => is_string($value) && HtmlSanitizer::isSafeUrl(trim($value)) ? trim($value) : '',
                // Saved layouts are sanitized already; like the text element, render does it again.
                'richtext' => is_string($value) ? HtmlSanitizer::sanitize($value) : '',
                'list' => array_map(fn(mixed $item): array => $this->blockValues($spec['fields'], is_array($item) ? $item : [], $state), is_array($value) ? array_values($value) : []),
                default => is_scalar($value) ? $value : '',
            };
        }

        return $values;
    }

    private function blockMedia(string $file): array
    {
        $item = $file !== '' ? $this->ctx->mediaItem($file) : null;

        return $item === null ? ['_' => '', 'alt' => ''] : ['_' => $this->ctx->mediaUrl($item['name']), 'alt' => (string) ($item['alt'] ?? '')];
    }

    /**
     * The value of a bound property or style: text and HTML are rendered,
     * media must come from a media field, links become URLs.
     *
     * @return array{value: mixed, missing: bool, label?: string}
     */
    private function resolveBinding(array $binding, string $target, array $state): array
    {
        if (in_array($target, ['text', 'html'], true)) {
            return $this->smart->text($binding, $state, $target === 'html');
        }

        $resolved = $this->smart->resolve($binding, $state);
        if ($target === 'link') {
            return ['value' => $this->boundLink($resolved, false), 'missing' => $resolved['missing'] || is_array($resolved['value'])];
        }

        $value = $resolved['value'];
        $missing = $resolved['missing'];
        if ($target === 'media_list') {
            $valid = ($resolved['config']['type'] ?? '') === 'media';
            return [
                'value' => $valid ? (is_array($value) ? $value : (is_string($value) && $value !== '' ? [$value] : [])) : [],
                'missing' => $missing || !$valid,
                'label' => $valid ? $this->fieldLabel(implode('.', $binding['path']), $resolved['config']) : '',
            ];
        }
        $fallback = array_filter($binding['filters'] ?? [], static fn(array $filter): bool => $filter['name'] === 'fallback') !== [];
        if ($target === 'media' && ($resolved['config']['type'] ?? '') !== 'media' && !($missing && $fallback)) {
            $value = '';
            $missing = true;
        }
        if ($target === 'media' && is_array($value) && empty($resolved['config']['multiple']) && count($value) <= 1) {
            $value = $value[0] ?? '';
        }
        if (is_array($value)) {
            $missing = true;
        }

        return ['value' => is_scalar($value) ? (string) $value : '', 'missing' => $missing];
    }

    /**
     * A link to a bound destination. Page URLs (`url` of an entry) are final
     * paths that already carry the base path and locale; other values are
     * treated like a typed URL.
     */
    private function boundLink(array $resolved, bool $newTab): array
    {
        $value = is_scalar($resolved['value']) ? (string) $resolved['value'] : '';
        $href = ($resolved['config']['type'] ?? '') === 'url' ? $value : $this->siteUrl($value);

        return ['type' => 'href', 'href' => (string) $href, 'new_tab' => $newTab];
    }

    // ─── Structure ───────────────────────────────────────────────────────────

    private function section(array $node, array $state): string
    {
        $props = $node['props'];
        $classes = ['c-section', 'c-section-' . $props['width']];

        if ($props['min_height'] !== '') {
            $classes[] = 'c-min-h-' . $props['min_height'];
        }
        if ($props['vertical_align'] !== 'start') {
            $classes[] = 'c-v-' . $props['vertical_align'];
        }
        if ($props['sticky'] !== 'none') {
            $classes[] = 'c-sticky';
            if ($props['sticky'] === 'reveal') {
                $classes[] = 'c-sticky-reveal';
            }
        }
        if ($props['overlap']) {
            $classes[] = 'c-overlap';
            if ($props['overlap_background'] === 'background') {
                $classes[] = 'c-overlap-opaque';
            }
        }
        // Scroll effects only show on a section that is still in view once the page scrolls.
        if ($props['sticky'] !== 'none' || $props['overlap']) {
            foreach ($props['scroll_effect'] as $effect) {
                $classes[] = 'c-scroll-' . $effect;
            }
        }

        // The section spans the screen; its content is held to the container's width.
        $background = $this->background($node['style'], $state, $props['min_height'] !== '');

        $embedded = ($state['embedded'] ?? false) === true;
        $slot = $this->slot($state);
        if (!$embedded && $props['width'] !== 'full') {
            $theme = $this->ctx->theme();
            $slot = $this->narrowSlot($slot, [], (float) ($props['width'] === 'narrow' ? ($theme['narrow_width'] ?? 760) : ($theme['container_width'] ?? 1200)));
        }
        $inner = $this->render($node['children'], ['nested' => $embedded, 'embedded' => false, 'slot' => $slot] + $state);
        if ($inner === '' && !$this->annotating()) {
            return '';
        }

        $container = $props['width'] === 'full' ? $inner : '<div class="c-container c-container-' . $props['width'] . '">' . $inner . '</div>';
        $dividers = $this->divider($node, 'top') . $this->divider($node, 'bottom');
        if ($dividers !== '') {
            $classes[] = 'c-has-shape';
        }

        return '<section' . $this->attributes($node, [...$classes, ...$background['classes'], ...$this->styleClasses($node['style'])], $background['style']) . '>' . $background['layer'] . $dividers . $container . '</section>';
    }

    /**
     * A section's shape along one edge, behind its content; its height and width may differ per device.
     * A shape with a width of its own is drawn as a mask instead, so it can be stretched and squashed:
     * one copy centred and scaled to that share of the section's width, repeated sideways.
     */
    private function divider(array $node, string $edge): string
    {
        $props = $node['props'];
        $prefix = 'divider_' . $edge;
        $svg = Shapes::dividerSvg((string) ($props[$prefix] ?? 'none'), $edge, ($props[$prefix . '_flip'] ?? false) === true, ($props[$prefix . '_invert'] ?? false) === true);
        if ($svg === '') {
            return '';
        }

        $classes = ['c-shape', 'c-shape-' . $edge, 'c-fg-' . $props[$prefix . '_color']];
        $sizes = ['--c-shape-h:' . (int) $props[$prefix . '_height']];
        $width = (int) ($props[$prefix . '_width'] ?? 100);
        if ($width !== 100) $sizes[] = '--c-shape-w:' . $width;
        foreach (['tablet' => '--c-t-shape-', 'mobile' => '--c-m-shape-'] as $device => $variable) {
            foreach (['height' => 'h', 'width' => 'w'] as $option => $suffix) {
                $size = $node['responsive'][$device]['props'][$prefix . '_' . $option] ?? null;
                if (is_int($size)) $sizes[] = $variable . $suffix . ':' . $size;
            }
        }

        if (preg_grep('/^--c-(?:[tm]-)?shape-w:/', $sizes) !== []) {
            $classes[] = 'c-shape-tiled';
            $sizes[] = '--c-shape-mask:url(data:image/svg+xml,' . rawurlencode(str_replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ', $svg)) . ')';
            $svg = '';
        }

        return '<div class="' . Security::e(implode(' ', $classes)) . '" style="' . implode(';', $sizes) . '" aria-hidden="true">' . $svg . '</div>';
    }

    private function row(array $node, array $state): string
    {
        $props = $node['props'];
        $nested = ($state['nested'] ?? false) === true;
        $classes = ['c-row', 'c-gap-' . $props['gap'], 'c-items-' . $props['align'], 'c-justify-' . $props['justify']];

        if ($nested) {
            $classes[] = 'c-row-nested';
        }

        if ($props['reverse_mobile']) {
            $classes[] = 'c-reverse-mobile';
        }
        if ($props['reverse_tablet']) {
            $classes[] = 'c-reverse-tablet';
        }

        $reverse = $props['reverse_mobile'] || $props['reverse_tablet'];
        $columnState = ['nested_cols' => $nested] + $state;
        $inner = '';
        foreach ($node['children'] as $index => $column) {
            $order = $reverse ? -$index : null;
            $inner .= $this->node($column, $columnState, $order);
        }
        // Spacers contribute height without text or media, including inside nested rows.
        $hasSpacer = preg_match('/class="[^"]*\bc-spacer\b/', $inner) === 1;
        if (!$hasSpacer && trim(strip_tags($inner, '<img><video><audio><iframe><hr><svg><input>')) === '' && !$this->annotating()) {
            // The entry builder renders shared rows without annotations. Keep
            // rows around empty page areas so their drop targets and area
            // switchers survive, including all enclosing rows and sections.
            $hasPageAreas = $this->ctx->annotate && $this->ctx->pageDraft !== null
                && LayoutSchema::pagePlaces($node['children']) !== [];
            if (!$hasPageAreas) {
                return '';
            }
        }

        $background = $this->background($node['style'], $state);

        return '<div' . $this->attributes($node, [...$classes, ...$background['classes'], ...$this->styleClasses($node['style'])], $background['style']) . '>' . $background['layer'] . $inner . '</div>';
    }

    private function column(array $node, array $state, ?int $order = null): string
    {
        $span = $node['props']['span'];
        $classes = ['c-col', 'c-col-' . $span['mobile']];
        // Columns of a nested row switch by the width of their row, not of the screen.
        $prefix = ($state['nested_cols'] ?? false) === true ? 'c-c' : 'c-';

        if ($span['tablet'] > 0) {
            $classes[] = $prefix . 't-col-' . $span['tablet'];
        }
        if ($span['desktop'] > 0) {
            $classes[] = $prefix . 'd-col-' . $span['desktop'];
        }
        if ($node['props']['vertical_align'] !== 'start') {
            $classes[] = 'c-self-' . $node['props']['vertical_align'];
        }
        if ($node['props']['spacing'] !== '') {
            $classes[] = 'c-stack-' . $node['props']['spacing'];
        }
        if ($node['props']['direction'] === 'row') {
            $classes[] = 'c-flow-row';
            $classes[] = 'c-flow-justify-' . $node['props']['content_justify'];
            $classes[] = 'c-flow-align-' . $node['props']['content_align'];
        }

        // A column fills its share of the row, which falls back to the smaller screen's share.
        $tablet = $span['tablet'] ?: $span['mobile'];
        $state['slot'] = $this->narrowSlot($this->slot($state), [
            'mobile' => $span['mobile'] / 12,
            'tablet' => $tablet / 12,
            'desktop' => ($span['desktop'] ?: $tablet) / 12,
        ]);
        $inner = $this->render($node['children'], ['nested' => true, 'nested_cols' => false] + $state);
        if ($inner === '' && $this->annotating()) {
            $inner = '<div class="c-placeholder c-placeholder-drop"' . $this->placeholder('emptyColumn') . '>Empty column</div>';
        }

        // A linked column covers itself with one link instead of wrapping its elements, which may hold links of their own.
        $link = $this->annotating() ? null : $this->resolveLink($node['props']['link'] ?? ['type' => 'none'], $state);
        if ($link !== null) {
            $classes[] = 'c-linked';
            // The cover has no text of its own, so it is named after the column's.
            $label = mb_substr(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5))), 0, 160);
            $inner .= str_replace('<a ', '<a aria-label="' . Security::e($label) . '" ', $this->anchor($link, '', 'c-linked-cover'));
        }

        $background = $this->background($node['style'], $state);
        $style = implode(';', array_filter([$order !== null ? '--c-column-order:' . $order : '', $background['style']]));

        return '<div' . $this->attributes($node, [...$classes, ...$background['classes'], ...$this->styleClasses($node['style'])], $style) . '>' . $background['layer'] . $inner . '</div>';
    }

    /**
     * A placed component renders its own tree. Inside the builder the whole
     * component is one node: its inner nodes carry no annotations, so two
     * instances never repeat ids and clicks select the instance. What the
     * instance put into the component's content areas belongs to the page
     * around it, and is annotated like it (see componentArea()).
     */
    private function component(array $node, array $state, ?int $order = null): string
    {
        $id = (string) $node['props']['component'];
        $root = (string) $node['props']['root'];
        $stack = $state['components'] ?? [];
        $component = $id !== '' ? $this->ctx->component($id) : null;
        $tree = $component['nodes'][0] ?? null;

        if ($tree === null || $tree['kind'] !== $root || in_array($id, $stack, true) || count($stack) >= self::MAX_COMPONENT_DEPTH) {
            return $tree === null ? $this->componentPlaceholder($node, 'missingComponent', 'Missing component') : $this->componentPlaceholder($node, 'componentLoop', 'A component cannot contain itself');
        }

        $tree = LayoutSchema::applyOverrides([$tree], $node['overrides'] ?? [])[0];
        if ($root === 'column') {
            $tree['props']['span'] = $node['props']['span'];
        }
        $fills = [];
        foreach ($node['children'] ?? [] as $child) {
            if ($child['kind'] === LayoutSchema::AREA_CONTENT) $fills[$child['props']['area']] = $child;
        }
        $state['areas'] = ['fills' => $fills, 'components' => $stack, 'suspended' => $this->annotationsSuspended];
        $state['components'] = [...$stack, $id];
        $annotate = $this->annotating();

        $this->annotationsSuspended++;
        try {
            $html = $this->node($tree, $state, $order);
        } finally {
            $this->annotationsSuspended--;
        }

        if (!$annotate) {
            return $html;
        }
        if ($html === '') {
            return $this->componentPlaceholder($node, 'emptyComponent', 'Empty component');
        }

        return (string) preg_replace('/^<([a-z][a-z0-9]*)/', '<$1' . $this->componentAttributes($node), $html, 1);
    }

    /**
     * A component's content area: what the instance being rendered put
     * there, in place of the area and laid out by the column around it. The
     * content belongs to the instance's page, so it renders as part of it:
     * annotated where the instance is, and free to hold this component again.
     * The component's own builder shows where each instance adds its content.
     */
    private function componentArea(array $node, array $state): string
    {
        $areas = $state['areas'] ?? null;
        unset($state['areas']);
        if ($areas === null) {
            return $this->annotating()
                ? '<div' . $this->attributes($node, ['c-area']) . '><div class="c-placeholder c-placeholder-drop"' . $this->placeholder('componentArea') . '>Content area: each placement of the component adds its own elements here.</div></div>'
                : '';
        }

        $fill = $areas['fills'][$node['id']] ?? null;
        $state['components'] = $areas['components'];
        $suspended = $this->annotationsSuspended;
        $this->annotationsSuspended = $areas['suspended'];
        try {
            $inner = $fill !== null ? $this->render($fill['children'], $state) : '';
            if (!$this->annotating()) {
                return $inner;
            }
            if ($fill === null) {
                // Added to the component after this instance was saved: the builder fills it on its next load.
                return '<div class="c-area"><div class="c-placeholder"' . $this->placeholder('areaNotLoaded') . '>New content area: reload the builder to add elements here.</div></div>';
            }
            if ($inner === '') {
                $inner = '<div class="c-placeholder c-placeholder-drop"' . $this->placeholder('areaContent') . '>Content area: drop elements here.</div>';
            }

            return '<div' . $this->attributes($fill, ['c-area']) . '>' . $inner . '</div>';
        } finally {
            $this->annotationsSuspended = $suspended;
        }
    }

    /**
     * The page's own content: the sections the entry designed in its page
     * field, or the layout's default content (the node's children) while it
     * has none. The layout builder always shows the default content, which
     * it edits; the page builder of one entry shows that entry's draft.
     */
    private function pageContent(array $node, array $state): string
    {
        $field = (string) $node['props']['field'];
        $draft = $this->ctx->pageDraft !== null && $this->ctx->pageDraft['field'] === $field ? $this->ctx->pageDraft['nodes'] : null;
        $value = ($state['type']['fields'][$field]['type'] ?? '') === 'page' ? ($state['entry'][$field] ?? null) : null;
        $nodes = $draft ?? (!$this->annotating() && LayoutSchema::hasPageContent($value) ? LayoutSchema::pageNodes($value) : $node['children']);
        // In a column, the sections fill the column instead of the page.
        $embedded = ($state['nested'] ?? false) === true;
        $classes = ['c-page-content', ...($embedded ? ['c-page-content-embedded'] : [])];
        $state = ['embedded' => $embedded] + $state;

        if ($draft !== null) {
            // Only the draft is edited: it carries annotations inside an otherwise plain page.
            $suspended = $this->annotationsSuspended;
            $this->annotationsSuspended = 0;
            try {
                $inner = $this->render($nodes, $state);
            } finally {
                $this->annotationsSuspended = $suspended;
            }

            return '<div class="' . implode(' ', $classes) . '" data-drop-root>' . $inner . '</div>';
        }

        $inner = $this->render($nodes, $state);
        if ($this->annotating()) {
            if ($inner === '') {
                $inner = '<div class="c-placeholder c-placeholder-drop"' . $this->placeholder('pageContent') . '>Page content: each entry designs this area. Add sections here as the default content.</div>';
            }

            return '<div' . $this->attributes($node, $classes) . '>' . $inner . '</div>';
        }

        if ($this->ctx->pageDraft !== null) {
            // Another area of the entry being edited: the builder switches to it on click.
            return '<div class="' . implode(' ', [...$classes, 'c-page-area']) . '" data-page-area="' . Security::e($field) . '">' . $inner . '</div>';
        }

        return $inner === '' ? '' : '<div class="' . implode(' ', $classes) . '">' . $inner . '</div>';
    }

    /**
     * The modal template's panel. It is the dialog itself, so its style is
     * the look of the modal; builder previews ($state['modal_preview']) show
     * it open in place, with nothing to press.
     */
    private function modal(array $node, array $state): string
    {
        $props = $node['props'];
        $preview = !empty($state['modal_preview']);
        $background = $this->background($node['style']);
        $classes = [
            'c-modal',
            'c-modal-' . $props['size'],
            'c-modal-at-' . $props['placement'],
            'c-modal-h-' . $props['height'],
            'c-modal-backdrop-' . $props['backdrop'],
            ...($preview ? ['c-modal-preview'] : []),
            ...$background['classes'],
            ...$this->styleClasses($node['style'])
        ];

        return '<dialog' . $this->attributes($node, $classes, $background['style'])
            . ($preview ? ' open' : ' data-link-modal-dialog' . ($props['backdrop_close'] ? ' data-backdrop-close' : ''))
            . ' aria-label="' . Security::e(Messages::get('modal', $this->ctx->htmlLang())) . '">'
            . $background['layer'] . $this->render($node['children'], $state) . '</dialog>';
    }

    /** Where the opened page appears; the modal script loads it into a frame of its own. */
    private function siteContent(array $node, array $state): string
    {
        $inner = !empty($state['modal_preview'])
            ? '<div class="c-placeholder c-placeholder-drop"' . $this->placeholder('siteContent') . '>' . Security::e(Messages::get('site_content_placeholder', $this->ctx->htmlLang())) . '</div>'
            : '';

        return $this->element($node, $inner);
    }

    /** The text of the link that opened the modal; previews show a sample. */
    private function modalTitle(array $node, array $state): string
    {
        $tag = $node['props']['level'];
        $sample = !empty($state['modal_preview']) ? Messages::get('modal_title_sample', $this->ctx->htmlLang()) : '';

        return $this->element($node, '<' . $tag . ' class="' . ($tag === 'p' ? '' : 'c-heading ') . 'c-modal-title" data-modal-title>' . Security::e($sample) . '</' . $tag . '>');
    }

    private function modalClose(array $node, array $state): string
    {
        $props = $node['props'];
        $icon = Icons::catalog()[$props['icon']] ?? null;
        $size = (int) $props['size'];
        $label = $props['label'] !== '' ? $props['label'] : Messages::get('close', $this->ctx->htmlLang());
        $svg = $icon !== null
            ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="currentColor" focusable="false" aria-hidden="true">' . $icon['body'] . '</svg>'
            : '';
        $text = $props['show_label'] ? '<span>' . Security::e($label) . '</span>' : '';

        return $this->element($node, '<button type="button" class="c-modal-close c-modal-close-' . $props['variant'] . '" data-modal-close'
            . ($text === '' ? ' aria-label="' . Security::e($label) . '" title="' . Security::e($label) . '"' : '')
            . (!empty($state['modal_preview']) ? ' tabindex="-1"' : '') . '>' . $svg . $text . '</button>');
    }

    private function componentPlaceholder(array $node, string $key, string $message): string
    {
        if (!$this->annotating()) {
            return '';
        }
        $classes = $node['props']['root'] === 'column' ? 'c-col c-col-12 ' : '';

        return '<div class="' . $classes . 'c-placeholder c-placeholder-warning" role="status"' . $this->placeholder($key) . $this->componentAttributes($node) . '>' . $message . '</div>';
    }

    private function componentAttributes(array $node): string
    {
        return ' data-node="' . Security::e($node['id']) . '" data-kind="' . LayoutSchema::COMPONENT . '"'
            . ' data-component="' . Security::e((string) $node['props']['component']) . '" data-component-root="' . Security::e((string) $node['props']['root']) . '"';
    }

    // ─── Elements ────────────────────────────────────────────────────────────

    private function heading(array $node, array $state): string
    {
        $level = $node['props']['level'];
        if (!empty($node['_resolved']['text']) && $node['props']['text'] === '' && !$this->annotating()) return '';
        $text = Security::e($node['props']['text']);
        $link = $this->resolveLink($node['props']['link'], $state);

        if ($link !== null) {
            $text = $this->anchor($link, $text);
        }

        return $this->element($node, '<' . $level . $this->textGradientAttributes($node, 'c-heading') . '>' . $text . '</' . $level . '>');
    }

    private function text(array $node, array $state): string
    {
        $html = HtmlSanitizer::sanitize($node['props']['html']);
        if (!empty($node['_resolved']['html']) && trim(strip_tags($html, '<img><video><audio><hr>')) === '' && !$this->annotating()) return '';

        return $this->element($node, '<div' . $this->textGradientAttributes($node, 'c-prose') . '>' . $html . '</div>');
    }

    private function html(array $node): string
    {
        $html = $node['props']['html'];
        if (trim($html) === '') {
            return $this->annotating() ? $this->element($node, '<div class="c-placeholder"' . $this->placeholder('addHtml') . '>Add custom HTML</div>') : '';
        }

        return $this->element($node, $html);
    }

    private function tabs(array $node, array $state): string
    {
        $tabs = $node['children'];
        if ($tabs === []) {
            return $this->annotating() ? $this->element($node, '<div class="c-placeholder"' . $this->placeholder('addTab') . '>Add a tab</div>') : '';
        }
        // A layout can repeat in cards and components, so node IDs alone are not unique here.
        $id = 'tabs-' . $node['id'] . '-' . ++$this->tabsSequence;
        $initial = min(count($tabs), max(1, (int) $node['props']['initial_tab'])) - 1;
        $buttons = '';
        $panels = '';
        foreach ($tabs as $index => $tab) {
            $key = $id . '-' . $index;
            $title = Security::e(trim($tab['props']['title']) ?: 'Tab ' . ($index + 1));
            $buttons .= '<button type="button" role="tab" id="' . $key . '-button" aria-controls="' . $key . '-panel"'
                . ' aria-selected="' . ($index === $initial ? 'true' : 'false') . '" tabindex="' . ($index === $initial ? '0' : '-1') . '"'
                . ' data-tab-key="' . Security::e($tab['id']) . '">' . $title . '</button>';
            // All content remains readable when scripts are unavailable.
            $panels .= '<div class="c-tabs-panel" role="tabpanel" id="' . $key . '-panel" aria-labelledby="' . $key . '-button" tabindex="0">'
                . '<h3 class="c-tabs-fallback-title">' . $title . '</h3>' . $this->render([$tab], $state) . '</div>';
        }
        return $this->element($node, '<div class="c-tabs" data-tabs="' . Security::e($id) . '" data-tabs-initial="' . Security::e($tabs[$initial]['id']) . '">'
            . '<div class="c-tabs-list" role="tablist" aria-label="' . Security::e($node['props']['title']) . '">' . $buttons . '</div>'
            . $panels . '</div>');
    }

    private function accordion(array $node): string
    {
        $props = $node['props'];
        $single = !empty($props['single_open']);
        // A unique native disclosure group for each render, including repeated components.
        $name = 'accordion-' . ++$this->accordionSequence;
        $opened = false;
        $html = '';
        foreach ($props['items'] ?? [] as $item) {
            $open = !empty($item['open']) && (!$single || !$opened);
            $opened = $opened || $open;
            $html .= '<details class="c-accordion"' . ($single ? ' name="' . $name . '"' : '') . ($open ? ' open' : '') . '>'
                . '<summary class="c-accordion-title">' . Security::e($item['title'] ?? '') . '</summary>'
                . '<div class="c-accordion-content c-prose">' . HtmlSanitizer::sanitize($item['html'] ?? '') . '</div></details>';
        }
        $html = '<div class="c-accordion-group">' . $html . '</div>';

        return $this->element($node, $html);
    }

    private function table(array $node): string
    {
        $props = $node['props'];
        $rows = is_array($props['rows'] ?? null) ? array_values($props['rows']) : [];
        if ($rows === []) {
            return $this->annotating() ? $this->element($node, '<div class="c-placeholder"' . $this->placeholder('addTableRows') . '>Add table rows</div>') : '';
        }
        $cell = static fn(mixed $value): string => LayoutSchema::tableCell(is_string($value) ? $value : '');
        $headerRow = !empty($props['header_row']);
        $headerColumn = !empty($props['header_column']);
        $stack = ($props['mobile'] ?? '') === 'stack';
        // Stacked rows label each cell with its column heading.
        $labels = $headerRow && $stack ? array_map(static fn(mixed $value): string => trim(html_entity_decode(strip_tags($cell($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $rows[0]) : [];
        $annotate = $this->annotating();
        $html = '';
        foreach ($rows as $r => $row) {
            $head = $headerRow && $r === 0;
            $cells = '';
            foreach (array_values($row) as $c => $value) {
                $tag = $head || ($headerColumn && $c === 0) ? 'th' : 'td';
                $scope = $tag === 'th' ? ' scope="' . ($head ? 'col' : 'row') . '"' : '';
                $label = !$head && ($labels[$c] ?? '') !== '' ? ' data-label="' . Security::e($labels[$c]) . '"' : '';
                $cells .= '<' . $tag . $scope . $label . ($annotate ? ' data-cell="' . $r . ':' . $c . '"' : '') . '>' . $cell($value) . '</' . $tag . '>';
            }
            $html .= ($head ? '<thead>' : ($r === ($headerRow ? 1 : 0) ? '<tbody>' : '')) . '<tr>' . $cells . '</tr>' . ($head ? '</thead>' : '');
        }
        if (count($rows) > ($headerRow ? 1 : 0)) $html .= '</tbody>';
        $caption = trim((string) ($props['caption'] ?? ''));
        $classes = ['c-table', 'c-table-' . ($props['variant'] ?? 'lines')];
        if (!empty($props['striped'])) $classes[] = 'c-table-striped';
        if (!empty($props['compact'])) $classes[] = 'c-table-compact';
        if ($headerColumn) $classes[] = 'c-table-header-column';

        return $this->element($node, '<div class="c-table-wrap' . ($stack ? ' c-table-stack' : '') . '"><table class="' . implode(' ', $classes) . '">'
            . ($caption !== '' ? '<caption>' . Security::e($caption) . '</caption>' : '') . $html . '</table></div>');
    }

    private function icon(array $node, array $state): string
    {
        $props = $node['props'];
        $icon = Icons::catalog()[$props['icon']] ?? null;
        if ($icon === null) return '';

        $link = $this->resolveLink($props['link'], $state);
        // Linked icons need a name even when no custom label was entered.
        $label = $props['label'];
        if ($label === '' && $link !== null) {
            $label = ucwords(str_replace('-', ' ', substr($props['icon'], 4)));
        }
        $accessibility = $label !== '' ? ' role="img" aria-label="' . Security::e($label) . '"' : ' aria-hidden="true"';
        $html = '<svg class="c-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="' . (int) $props['size']
            . '" height="' . (int) $props['size'] . '" fill="currentColor" focusable="false"' . $accessibility . '>' . $icon['body'] . '</svg>';
        if (($props['shape'] ?? 'none') !== 'none') {
            $padding = (int) $props['shape_padding'];
            $html = '<span class="c-icon-shape c-icon-shape-' . Security::e($props['shape']) . '" style="width:' . ((int) $props['size'] + 2 * $padding)
                . 'px;padding:' . $padding . 'px;background-color:var(--c-' . Security::e($props['shape_color']) . ')">' . $html . '</span>';
        }
        if ($link !== null) $html = $this->anchor($link, $html, 'c-icon-link');

        return $this->element($node, $html);
    }

    /** Provider marks for canvas placeholders, so editors can tell what will play without loading it. */
    private const VIDEO_BADGES = [
        'youtube' => '<span class="c-video-badge"><svg viewBox="0 0 28 20" width="28" height="20" aria-hidden="true"><rect width="28" height="20" rx="5" fill="#f00"/><path d="M11 5.5v9l8-4.5z" fill="#fff"/></svg>YouTube</span>',
        'vimeo' => '<span class="c-video-badge"><svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><rect width="20" height="20" rx="4" fill="#1ab7ea"/><path d="M4 7.5l1 1.2c1-.8 1.5-1 1.8-.3l1.9 6.3c.6 1.3 1.3 1.6 2.6.4C13.8 12.6 16 9.4 16 7c0-2.2-2.6-2.4-4.3-.2 2-.8 2.4.6 1 2.7-1.5 2.2-2.2 2.1-2.6.4L9.3 6.2C8.8 4.6 7.5 4.8 4 7.5z" fill="#fff"/></svg>Vimeo</span>',
        'file' => '<span class="c-video-badge"><svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><rect width="20" height="20" rx="4" fill="#64748b"/><path d="M8 6v8l6-4z" fill="#fff"/></svg>Video file</span>',
    ];

    private function video(array $node): string
    {
        $props = $node['props'];
        // Canvas previews never load third-party sources or autoplay media; library
        // videos are same-origin, so the canvas shows their player. The full-page
        // preview uses the same interactive player as the website.
        $item = ($props['media'] ?? '') !== '' ? $this->ctx->mediaItem($props['media']) : null;
        $library = $item !== null && str_starts_with((string) ($item['mime'] ?? ''), 'video/');
        $source = $library
            ? ['type' => 'file', 'url' => $this->ctx->mediaUrl($item['name']) . ($props['start_time'] ? '#t=' . $props['start_time'] : '')]
            : VideoEmbed::source($props);
        $canvas = $this->ctx->preview && !$this->ctx->browsePreview;
        $title = Security::e($props['title'] ?: 'Video');
        $ratio = 'c-ratio c-ratio-' . str_replace(':', '-', $props['ratio']);
        if ($source === null || ($canvas && !$library)) {
            if (!$this->ctx->annotate && $source === null) return '';
            $key = $source === null ? 'chooseVideo' : 'videoPreview';
            $label = $source === null ? 'Choose a library video or enter a YouTube, Vimeo or direct video URL' : 'Video preview: open the full-page preview to play';
            $badge = $source === null ? '' : self::VIDEO_BADGES[$source['provider']];
            return $this->element($node, '<div class="' . $ratio . ' c-video-placeholder">' . $badge . '<div class="c-placeholder"' . $this->placeholder($key) . '>' . $label . '</div><span>' . $title . '</span></div>');
        }
        $src = Security::e($source['url']);
        $autoplay = $props['autoplay'] && !$canvas;
        if ($source['type'] === 'file') {
            $attrs = ' playsinline preload="metadata"';
            foreach (['controls', 'loop'] as $key) if ($props[$key]) $attrs .= ' ' . $key;
            if ($props['muted'] || $autoplay) $attrs .= ' muted';
            if ($autoplay) $attrs .= ' autoplay';
            if (!$props['fullscreen']) $attrs .= ' controlslist="nofullscreen"';
            $poster = ($props['poster'] ?? '') !== '' ? $this->ctx->mediaItem($props['poster']) : null;
            if ($poster !== null && str_starts_with((string) ($poster['mime'] ?? ''), 'image/')) $attrs .= ' poster="' . Security::e($this->imageSource($poster)) . '"';
            $player = '<video src="' . $src . '" aria-label="' . $title . '"' . $attrs . '></video>';
        } else {
            $allow = 'autoplay; encrypted-media; picture-in-picture';
            if ($props['fullscreen']) $allow .= '; fullscreen';
            $player = '<iframe src="' . $src . '" title="' . $title . '" loading="' . ($autoplay ? 'eager' : 'lazy') . '" allow="' . $allow . '"'
                . ($props['fullscreen'] ? ' allowfullscreen' : '') . ' referrerpolicy="strict-origin-when-cross-origin"></iframe>';
        }
        return $this->element($node, '<div class="' . $ratio . ' c-video-embed">' . $player . '</div>');
    }

    private function image(array $node, array $state): string
    {
        $props = $node['props'];
        $item = $props['media'] !== '' ? $this->ctx->mediaItem($props['media']) : null;

        if ($item === null) {
            return $this->annotating() ? $this->element($node, '<div class="c-placeholder"' . $this->placeholder('chooseImage') . '>Choose an image</div>') : '';
        }

        if (isset($node['_localized']['alt']) || ($props['alt'] ?? '') !== '') $item['alt'] = $props['alt'];
        $html = $this->mediaElement($item, $props, $state);
        $link = $this->resolveLink($props['link'], $state);
        if ($link !== null) {
            $html = $this->anchor($link, $html, 'c-image-link');
        }

        $caption = (string) ((isset($node['_localized']['caption']) || ($props['caption'] ?? '') !== '') ? $props['caption'] : ($item['title'] ?? ''));
        if ($caption !== '') {
            $html = '<figure class="c-figure">' . $html . '<figcaption>' . Security::e($caption) . '</figcaption></figure>';
        }

        [$classes, $style] = $this->imageWidth($props);

        return $this->element($node, $html, $classes, $style);
    }

    /**
     * Width bounds of an image element, as a class and the custom properties
     * site.css reads. A minimum above the maximum is taken as the maximum, so
     * equal or crossed values give one exact width.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function imageWidth(array $props): array
    {
        $max = (int) ($props['max_width'] ?? 0);
        $min = (int) ($props['min_width'] ?? 0);
        if ($max > 0) $min = min($min, $max);

        $style = [];
        if ($min > 0) $style[] = '--c-img-min:' . $min . 'px';
        if ($max > 0) $style[] = '--c-img-max:' . $max . 'px';

        return $style === [] ? [[], ''] : [['c-img-sized'], implode(';', $style)];
    }

    private function gallery(array $node, array $state): string
    {
        $props = $node['props'];
        // Masonry uses natural image heights; keep the saved crop for grid mode.
        if ($props['masonry']) $props['ratio'] = '';
        $items = [];
        foreach (is_array($props['media']) ? $props['media'] : [] as $file) {
            $item = is_string($file) ? $this->ctx->mediaItem($file) : null;
            if ($item !== null) $items[] = $item;
        }
        if ($items === []) {
            return $this->annotating() ? $this->element($node, '<div class="c-placeholder"' . $this->placeholder('chooseGallery') . '>Choose gallery images</div>') : '';
        }

        $columns = $props['columns'];
        $state['slot'] = $this->gridSlot($this->slot($state), $columns);
        $link = $this->resolveLink($props['link'], $state);
        $html = '';
        foreach ($items as $item) {
            $image = $this->mediaElement($item, $props, $state);
            if ($props['lightbox'] && str_starts_with((string) ($item['mime'] ?? ''), 'image/')) {
                $image = '<a class="c-gallery-zoom" data-gallery-image href="' . Security::e($this->imageSource($item)) . '" aria-haspopup="dialog" aria-label="' . Messages::get('enlarge', $this->ctx->htmlLang()) . '"'
                    . ($props['captions'] ? ' data-caption="' . Security::e((string) ($item['title'] ?? '')) . '"' : '') . '>' . $image . '</a>';
            } elseif ($link !== null && str_starts_with((string) ($item['mime'] ?? ''), 'image/')) {
                $image = $this->anchor($link, $image, 'c-image-link');
            }
            $caption = $props['captions'] ? (string) ($item['title'] ?? '') : '';
            $html .= '<figure class="c-gallery-item c-figure">' . $image
                . ($caption !== '' ? '<figcaption>' . Security::e($caption) . '</figcaption>' : '') . '</figure>';
        }
        $layout = $props['masonry'] ? 'c-gallery-masonry' : 'c-grid';
        $masonryStyle = $props['masonry']
            ? ' style="--c-gallery-columns:' . $columns . ';--c-gallery-gap:' . ($props['gap'] === 'none' ? '0px' : 'var(--space-' . $props['gap'] . ')') . '"'
            : '';
        $lightbox = $props['lightbox']
            ? ' data-gallery-lightbox data-gallery-navigation="' . ($props['lightbox_navigation'] ? 'true' : 'false') . '"'
            . ' data-gallery-label="' . Messages::get('gallery', $this->ctx->htmlLang()) . '" data-gallery-close="' . Messages::get('close', $this->ctx->htmlLang()) . '"'
            . ' data-gallery-previous="' . Messages::get('previous_image', $this->ctx->htmlLang()) . '" data-gallery-next="' . Messages::get('next_image', $this->ctx->htmlLang()) . '"'
            : '';
        $html = '<div class="' . $layout . ' c-gallery c-cols-' . $columns . ' c-gap-' . $props['gap'] . '"' . $masonryStyle . $lightbox . '>' . $html . '</div>';
        if ($props['label'] && ($node['_gallery_label'] ?? '') !== '') {
            $html = '<div class="c-field-label">' . Security::e($node['_gallery_label']) . '</div>' . $html;
        }
        return $this->element($node, $html);
    }

    private function button(array $node, array $state): string
    {
        $props = $node['props'];
        $link = $this->resolveLink($props['link'], $state);
        $classes = ['c-button', 'c-button-' . $props['variant'], 'c-button-' . $props['size']];
        if ($props['full_width']) {
            $classes[] = 'c-button-block';
        }
        foreach (array_keys(StyleVariants::DEVICES) as $device) {
            $override = $node['responsive'][$device]['props'] ?? [];
            if (isset($override['size'])) {
                $classes[] = StyleVariants::PREFIXES[$device] . 'button-' . $override['size'];
            }
            if (isset($override['full_width'])) {
                $classes[] = StyleVariants::PREFIXES[$device] . 'button-' . ($override['full_width'] ? 'block' : 'inline');
            }
        }
        foreach (self::BUTTON_COLOR_TOKENS as $key => $token) {
            if (($props[$key] ?? '') !== '') {
                $classes[] = 'c-' . $token . $props[$key];
            }
            foreach (StyleVariants::STATES as $variant) {
                if (($node['states'][$variant]['props'][$key] ?? '') !== '') {
                    $classes[] = StyleVariants::PREFIXES[$variant] . $token . $node['states'][$variant]['props'][$key];
                }
            }
        }
        if ($props['icon'] !== '' && ($props['icon_gap'] ?? 'sm') !== 'sm') {
            $classes[] = 'c-button-gap-' . $props['icon_gap'];
        }
        $classes = implode(' ', $classes);
        // The label has an element of its own, so the builder edits it apart from the icon.
        $label = '<span class="c-button-label">' . Security::e($props['label']) . '</span>';
        $icon = $props['icon'] !== '' ? (Icons::catalog()[$props['icon']] ?? null) : null;
        if ($icon !== null) {
            $svg = '<svg class="c-button-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1em" height="1em" fill="currentColor" focusable="false" aria-hidden="true">' . $icon['body'] . '</svg>';
            $label = $props['icon_position'] === 'end' ? $label . $svg : $svg . $label;
        }

        if ($link === null) {
            // A missing destination removes the link, not the button's content.
            // Shared regions must render the same inside and outside their builder.
            return $this->element($node, '<span class="' . $classes . '">' . $label . '</span>');
        }

        return $this->element($node, $this->anchor($link, $label, $classes));
    }

    private function siteTitle(array $node, array $state): string
    {
        $props = $node['props'];
        $settings = $this->ctx->settings();
        $parts = '';
        $logo = $props['show_logo'] && $settings['logo'] !== '' ? $this->ctx->mediaItem($settings['logo']) : null;

        if ($logo !== null) {
            $parts .= '<img class="c-logo" src="' . Security::e($this->imageSource($logo)) . '" alt="' . ($props['show_name'] ? '' : Security::e($this->ctx->siteTitle())) . '"'
                . $this->dimensions($logo) . '>';
        }

        $text = '';
        if ($props['show_name']) {
            $text .= '<span class="c-site-name">' . Security::e($this->ctx->siteTitle()) . '</span>';
        }
        if ($props['show_tagline'] && $settings['tagline'] !== '') {
            $text .= '<span class="c-site-tagline">' . Security::e($settings['tagline']) . '</span>';
        }
        if ($text !== '') {
            $parts .= '<span class="c-site-text">' . $text . '</span>';
        }

        return $this->element($node, '<a class="c-site-title" href="' . Security::e($this->ctx->homeUrl()) . '" rel="home">' . $parts . '</a>');
    }

    private function languageSwitcher(array $node, array $state): string
    {
        $locales = $this->ctx->locales();

        if (count($locales) < 2) {
            return $this->annotating() ? $this->element($node, '<div class="c-placeholder"' . $this->placeholder('noLanguages') . '>Add website languages to show the switcher</div>') : '';
        }

        $alternates = is_array($state['alternates'] ?? null) ? $state['alternates'] : [];
        $flag = $node['props']['flag'];
        $items = '';

        foreach ($locales as $locale) {
            if (!isset($alternates[$locale])) continue;
            $url = $alternates[$locale];
            $name = $this->localeName($locale);
            $label = $node['props']['format'] === 'names' ? $name : strtoupper($locale);
            $current = $locale === ($this->ctx->locale !== '' ? $this->ctx->locale : $this->ctx->defaultLocale());
            $src = $flag !== 'none' ? $this->ctx->flagUrl($locale) : null;
            $image = $src !== null ? '<img class="c-flag" src="' . Security::e($src) . '" alt="" width="20" height="15">' : '';
            // A flag on its own is named for screen readers and on hover; a language without a flag keeps its text.
            $named = $flag === 'only' && $image !== '';
            $items .= '<li><a href="' . Security::e($url) . '" hreflang="' . Security::e($locale) . '" lang="' . Security::e($locale) . '"'
                . ($named ? ' aria-label="' . Security::e($name) . '" title="' . Security::e($name) . '"' : '')
                . ($current ? ' aria-current="true"' : '') . '>' . $image . ($named ? '' : '<span>' . Security::e($label) . '</span>') . '</a></li>';
        }

        if ($items === '') return $this->annotating() ? $this->element($node, '<div class="c-placeholder"' . $this->placeholder('noTranslations') . '>No published translations for this page</div>') : '';
        return $this->element($node, '<nav class="c-languages' . ($flag === 'only' ? ' c-languages-flags' : '') . '" aria-label="' . Security::e(Messages::get('language', $this->ctx->htmlLang())) . '"><ul>' . $items . '</ul></nav>');
    }

    /**
     * A light/dark switch. It shows the icon of the mode that is on and is
     * pressed while dark; the head script (ThemeToggle) keeps both in sync.
     */
    private function themeToggle(array $node): string
    {
        $props = $node['props'];
        $catalog = Icons::catalog();
        $size = (int) $props['size'];
        $svg = static fn(?array $icon, string $mode): string => $icon !== null
            ? '<svg class="c-theme-icon c-theme-icon-' . $mode . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="' . $size . '" height="' . $size
            . '" fill="currentColor" focusable="false" aria-hidden="true">' . $icon['body'] . '</svg>'
            : '';
        $label = $props['label'] !== '' ? $props['label'] : Messages::get('dark_mode', $this->ctx->htmlLang());
        $text = $props['show_label'] ? '<span>' . Security::e($label) . '</span>' : '';

        return $this->element($node, '<button type="button" class="c-theme-toggle c-theme-toggle-' . $props['variant'] . '" data-theme-toggle'
            . ' aria-pressed="' . (($this->ctx->theme()['mode'] ?? '') === 'dark' ? 'true' : 'false') . '"'
            . ($text === '' ? ' aria-label="' . Security::e($label) . '" title="' . Security::e($label) . '"' : '')
            // In the builder the button is part of the canvas, not something to press.
            . ($this->annotating() ? ' tabindex="-1"' : '') . '>'
            . $svg($catalog[$props['light_icon']] ?? null, 'light') . $svg($catalog[$props['dark_icon']] ?? null, 'dark') . $text
            . '</button>');
    }

    /**
     * A website form. It posts to SiteController::submitForm(), which sends
     * the visitor back to #{anchor}-sent or #{anchor}-error; the matching
     * message is shown with :target, so this works without scripts and the
     * page stays cacheable.
     */
    private function form(array $node, array $state): string
    {
        $props = $node['props'];
        $form = $this->ctx->form($props['form']);
        $inputs = $form !== null ? $this->ctx->forms()->inputs($form, $this->ctx->htmlLang()) : [];

        if ($inputs === []) {
            $message = $form === null ? 'Choose a content type' : 'The form “' . Security::e($form['name']) . '” has no fields to show';
            $key = $form === null ? $this->placeholder('chooseFormType') : $this->placeholder('formEmpty', ['name' => (string) $form['name']]);
            return $this->annotating() ? $this->element($node, '<div class="c-placeholder"' . $key . '>' . $message . '</div>') : '';
        }

        $anchor = 'form-' . $node['id'] . (++$this->formSequence > 1 ? '-' . $this->formSequence : '');
        $success = ($form['success_message'] !== '' || !empty($form['_localized']['success_message'])) ? $form['success_message'] : Messages::get('form_success', $this->ctx->htmlLang());
        $failure = ($form['error_message'] !== '' || !empty($form['_localized']['error_message'])) ? $form['error_message'] : Messages::get('form_error', $this->ctx->htmlLang());
        $submit = ($form['submit_label'] !== '' || !empty($form['_localized']['submit_label'])) ? $form['submit_label'] : Messages::get('send', $this->ctx->htmlLang());
        $fields = '';

        foreach ($inputs as $input) {
            $fields .= $this->formField($anchor, $input, $props['labels'] === 'hidden');
        }

        $hasMedia = in_array('media', array_column(array_column($inputs, 'config'), 'type'), true);
        $hidden = ($hasMedia ? '<input type="hidden" name="MAX_FILE_SIZE" value="' . FormUploads::maxBytes() . '">' : '')
            . '<input type="hidden" name="_return" value="' . Security::e($this->ctx->embedUrl((string) ($state['path'] ?? '/'))) . '">'
            . '<input type="hidden" name="_anchor" value="' . Security::e($anchor) . '">'
            . ($this->ctx->locale !== '' ? '<input type="hidden" name="_locale" value="' . Security::e($this->ctx->locale) . '">' : '');
        // Hidden from people and assistive technology; bots filling in every input give themselves away.
        $honeypot = '<div class="c-form-hp" aria-hidden="true"><label>Website <input type="text" name="' . FormSubmission::HONEYPOT . '" value="" tabindex="-1" autocomplete="off"></label></div>';
        $captcha = (new FormCaptcha($this->ctx->site))->markup($form, $this->ctx->htmlLang(), $this->ctx->preview);
        $button = '<button type="' . ($this->annotating() ? 'button' : 'submit') . '" class="c-button c-button-' . $props['button_variant'] . ' c-button-md' . ($props['button_full_width'] ? ' c-button-block' : '') . '">' . Security::e($submit) . '</button>';

        return $this->element(
            $node,
            '<div id="' . Security::e($anchor) . '-sent" class="c-form-status c-form-sent" role="status">' . $this->paragraphs($success) . '</div>'
                . '<div id="' . Security::e($anchor) . '-error" class="c-form-status c-form-failed" role="alert">' . $this->paragraphs($failure) . '</div>'
                . '<form class="c-form' . ($props['labels'] === 'hidden' ? ' c-form-labels-hidden' : '') . '" method="post" action="' . Security::e($this->ctx->formAction($form['id'])) . '" accept-charset="UTF-8"' . ($hasMedia ? ' enctype="multipart/form-data"' : '') . '>'
                . $hidden . '<div class="c-form-grid">' . $fields . '</div>' . $honeypot . $captcha
                . '<div class="c-form-actions">' . $button . '</div></form>'
        );
    }

    /** @param array{key: string, config: array, label: string} $input */
    private function formField(string $anchor, array $input, bool $hiddenLabels): string
    {
        $config = $input['config'];
        $type = (string) ($config['type'] ?? 'text');
        $key = $input['key'];
        $id = $anchor . '-' . $key;
        $name = 'fields[' . $key . ']';
        $required = !empty($config['required']);
        $default = $config['default'] ?? null;
        $width = (string) ($config['layout']['width'] ?? 'full');
        $width = in_array($width, ['1/3', '1/2', '2/3'], true) ? str_replace('/', '-', $width) : 'full';
        $help = trim((string) ($config['description'] ?? ''));
        if ($type === 'media') {
            $limit = rtrim(rtrim(number_format(FormUploads::maxBytes() / 1048576, 2, '.', ''), '0'), '.');
            $uploadHelp = Messages::get('upload_limit', $this->ctx->htmlLang(), ['{limit}' => $limit]);
            $help = trim($help . ' ' . $uploadHelp);
        }
        $describedBy = $help !== '' ? ' aria-describedby="' . Security::e($id) . '-help"' : '';
        // Without visible labels, the label doubles as the placeholder.
        $attributes = ' id="' . Security::e($id) . '" name="' . Security::e($name) . '"' . ($required ? ' required' : '') . $describedBy
            . ($hiddenLabels ? ' placeholder="' . Security::e($input['label']) . '"' : '');
        $label = Security::e($input['label']) . ($required ? '<span class="c-form-required" aria-hidden="true">*</span>' : '');
        $labelClass = $hiddenLabels ? ' class="c-form-label c-visually-hidden"' : ' class="c-form-label"';
        $helpHtml = $help !== '' ? '<p id="' . Security::e($id) . '-help" class="c-form-help">' . Security::e($help) . '</p>' : '';

        if ($type === 'boolean') {
            return '<div class="c-form-field c-form-w-' . $width . ' c-form-check">'
                . '<label><input type="checkbox" id="' . Security::e($id) . '" name="' . Security::e($name) . '" value="1"' . ($required ? ' required' : '') . $describedBy . (!empty($default) ? ' checked' : '') . '> <span>' . $label . '</span></label>'
                . $helpHtml . '</div>';
        }

        $options = [];
        if ($type === 'select') {
            $raw = is_array($config['options'] ?? null) ? $config['options'] : [];
            foreach ($raw as $optionKey => $optionLabel) {
                $options[array_is_list($raw) ? (string) $optionLabel : (string) $optionKey] = (string) $optionLabel;
            }
        }

        if ($type === 'select' && !empty($config['multiple'])) {
            $defaults = array_map('strval', is_array($default) ? $default : []);
            $boxes = '';
            foreach ($options as $value => $text) {
                $boxes .= '<label><input type="checkbox" name="' . Security::e($name) . '[]" value="' . Security::e($value) . '"' . (in_array($value, $defaults, true) ? ' checked' : '') . '> <span>' . Security::e($text) . '</span></label>';
            }

            return '<fieldset class="c-form-field c-form-w-' . $width . ' c-form-choices"' . $describedBy . '><legend' . $labelClass . '>' . $label . '</legend>' . $boxes . $helpHtml . '</fieldset>';
        }

        $value = is_scalar($default) ? (string) $default : '';
        $control = match ($type) {
            'media' => '<input type="file" id="' . Security::e($id) . '" name="' . Security::e($name . (!empty($config['multiple']) ? '[]' : '')) . '"'
                . ($required ? ' required' : '') . $describedBy . (!empty($config['multiple']) ? ' multiple' : '') . '>',
            'textarea', 'markdown' => '<textarea' . $attributes . ' rows="5">' . Security::e($value) . '</textarea>',
            'select' => '<select' . $attributes . '><option value="">' . Security::e($hiddenLabels ? $input['label'] : '–') . '</option>'
                . implode('', array_map(static fn(string $option, string $text): string => '<option value="' . Security::e($option) . '"' . ($option === $value ? ' selected' : '') . '>' . Security::e($text) . '</option>', array_keys($options), $options))
                . '</select>',
            'number', 'range' => '<input type="number"' . $attributes . ' value="' . Security::e($value) . '"'
                . (is_numeric($config['min'] ?? null) ? ' min="' . Security::e((string) $config['min']) . '"' : '')
                . (is_numeric($config['max'] ?? null) ? ' max="' . Security::e((string) $config['max']) . '"' : '')
                . ' step="' . (is_numeric($config['step'] ?? null) && (float) $config['step'] > 0 ? Security::e((string) $config['step']) : 'any') . '">',
            'date' => '<input type="date"' . $attributes . ' value="' . Security::e(substr($value, 0, 10)) . '">',
            'datetime' => '<input type="datetime-local"' . $attributes . ' value="' . Security::e($value) . '">',
            'color' => '<input type="color"' . $attributes . ' value="' . Security::e(preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : '#000000') . '">',
            default => '<input type="' . $this->textInputType($key) . '"' . $attributes . ' value="' . Security::e($value) . '"' . $this->autocomplete($key) . '>',
        };

        return '<div class="c-form-field c-form-w-' . $width . '"><label for="' . Security::e($id) . '"' . $labelClass . '>' . $label . '</label>' . $control . $helpHtml . '</div>';
    }

    /** Text fields named like an email address, phone number or link get the matching keyboard and checks. */
    private function textInputType(string $key): string
    {
        $key = strtolower($key);

        return match (true) {
            (bool) preg_match('/e-?mail/', $key) => 'email',
            (bool) preg_match('/(^|[_-])(phone|tel|telefon|mobile|handy)($|[_-])/', $key) => 'tel',
            (bool) preg_match('/(^|[_-])(url|website|homepage)($|[_-])/', $key) => 'url',
            default => 'text',
        };
    }

    private function autocomplete(string $key): string
    {
        $hint = match ($this->textInputType($key)) {
            'email' => 'email',
            'tel' => 'tel',
            'url' => 'url',
            default => in_array(strtolower($key), ['name', 'full_name', 'fullname'], true) ? 'name' : '',
        };

        return $hint !== '' ? ' autocomplete="' . $hint . '"' : '';
    }

    private function menu(array $node, array $state): string
    {
        $props = $node['props'];
        $menu = $this->ctx->menu($props['menu']);

        if ($menu === null || $menu['items'] === []) {
            return $this->annotating() ? $this->element($node, '<div class="c-placeholder"' . $this->placeholder('menuEmpty', ['name' => (string) $props['menu']]) . '>Menu “' . Security::e($props['menu']) . '” has no items</div>') : '';
        }

        // A component or card can render the same menu node several times.
        $id = 'menu-' . Security::e($node['id']) . '-' . ++$this->menuSequence;
        $list = $this->menuItems($menu['items'], $state, 'c-menu-list', $id);
        $collapsible = $props['always_button'] || $props['collapse_mobile'];
        $hasPanel = $collapsible && $props['panel'] !== 'dropdown';
        $classes = 'c-menu c-menu-' . $props['orientation'] . ($collapsible ? ' c-menu-collapsible' : '')
            . ($props['always_button'] ? ' c-menu-always-button' : '')
            . ($hasPanel ? ' c-menu-' . $props['panel'] : '')
            . ($hasPanel && $props['panel'] === 'drawer' ? ' c-menu-drawer-' . $props['panel_side'] : '')
            . ' c-menu-fx-' . $props['hover_effect'] . ' c-menu-active-' . $props['active_style'];
        $toggle = $close = '';
        $panelStyle = [];
        if (!empty($node['style']['text_color'])) {
            $color = $node['style']['text_color'] === 'inverse' ? 'background' : $node['style']['text_color'];
            $panelStyle[] = '--c-menu-color:var(--c-' . $color . ')';
        }
        if ($props['hover_color'] !== '') {
            $panelStyle[] = '--c-menu-hover:var(--c-' . ($props['hover_color'] === 'inverse' ? 'background' : $props['hover_color']) . ')';
        }
        // Submenu dropdowns and the button's panel share one palette.
        if ($props['panel_background'] !== '') {
            $panelStyle[] = '--c-menu-background:var(--c-' . $props['panel_background'] . ')';
        }
        if ($props['panel_text_color'] !== '') {
            $panelStyle[] = '--c-menu-text:var(--c-' . ($props['panel_text_color'] === 'inverse' ? 'background' : $props['panel_text_color']) . ')';
        } elseif ($props['panel_background'] !== '') {
            // Without a text color of its own, a panel reads like a section with that fill.
            $panelStyle[] = '--c-menu-text:var(--c-' . (in_array($props['panel_background'], ['background', 'surface'], true) ? 'text' : 'on-' . $props['panel_background']) . ')';
        }

        if ($collapsible) {
            $toggle = '<input type="checkbox" id="' . $id . '" class="c-menu-toggle" aria-label="' . Security::e(Messages::get('menu', $this->ctx->htmlLang())) . '">'
                . '<label for="' . $id . '" class="c-menu-button" aria-hidden="true"><span></span><span></span><span></span></label>';
            // A full-screen or side panel covers the menu button, so it brings its own way to close.
            if ($props['panel'] !== 'dropdown') {
                $close = '<label for="' . $id . '" class="c-menu-close" aria-hidden="true"></label>'
                    . ($props['panel'] === 'drawer' ? '<label for="' . $id . '" class="c-menu-backdrop" aria-hidden="true"></label>' : '');
            }
        }

        $style = $panelStyle !== [] ? ' style="' . Security::e(implode(';', $panelStyle)) . '"' : '';

        // The menu script only closes submenus and panels that are open; the markup works without it.
        $script = $hasPanel || str_contains($list, 'c-menu-disclosure') ? ' data-menu' : '';

        return $this->element($node, '<nav class="' . $classes . '"' . $style . $script . ' aria-label="' . Security::e($menu['label']) . '">' . $toggle . $list . $close . '</nav>');
    }

    /** Opening an item closes its siblings, which share the disclosure name $group. */
    private function menuItems(array $items, array $state, string $class, string $group): string
    {
        $html = '';
        $currentPath = (string) ($state['path'] ?? '');

        foreach ($items as $index => $item) {
            $link = $this->resolveLink($item['link'], $state);
            $label = (string) $item['label'];

            if ($label === '') {
                $label = $this->linkTitle($item['link']);
            }

            $text = Security::e($label);
            // The icon sits inside the link, so it is clickable and the label still names it.
            $icon = Icons::catalog()[$item['icon'] ?? ''] ?? null;
            if ($icon !== null) {
                $text = '<svg class="c-menu-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1em" height="1em" fill="currentColor" focusable="false" aria-hidden="true">' . $icon['body'] . '</svg>' . $text;
            }
            $active = $link !== null && $currentPath !== '' && explode('#', $link['href'], 2)[0] === $currentPath;
            $inner = $link !== null
                ? '<a href="' . Security::e($link['href']) . '"' . ($link['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : '') . ($active ? ' aria-current="page"' : '') . '>' . $text . '</a>'
                : '<span>' . $text . '</span>';

            if ($item['children'] === []) {
                $html .= '<li><div class="c-menu-label">' . $inner . '</div></li>';
                continue;
            }

            $submenu = $this->menuItems($item['children'], $state, 'c-submenu', $group . '-' . $index);
            $details = '<details class="c-menu-disclosure" name="' . $group . '">';
            $chevron = '<span class="c-menu-chevron" aria-hidden="true"></span>';
            // A heading without a link opens its submenu itself; a link keeps its target beside a toggle.
            $html .= $link === null
                ? '<li class="c-has-children c-menu-group">' . $details . '<summary class="c-menu-label">' . $inner . $chevron . '</summary>' . $submenu . '</details></li>'
                : '<li class="c-has-children"><div class="c-menu-label">' . $inner . '</div>' . $details . '<summary aria-label="' . Security::e($label) . '">' . $chevron . '</summary>' . $submenu . '</details></li>';
        }

        return '<ul class="' . $class . '">' . $html . '</ul>';
    }

    /**
     * A search field whose hits drop down below it. The search runs in
     * SiteController::search() and the bundled search script draws the hits,
     * so the page itself stays cacheable; the element only says what to search.
     * Set to the results page, it is a GET form to /search?q=, which also
     * works without JavaScript; there it shows the query it was opened with.
     */
    private function search(array $node): string
    {
        $props = $node['props'];
        $lang = $this->ctx->htmlLang();
        $id = 'search-' . $node['id'] . '-' . ++$this->searchSequence;
        $placeholder = $props['placeholder'] !== '' ? $props['placeholder'] : Messages::get('search_placeholder', $lang);
        $empty = $props['no_results_text'] !== '' ? $props['no_results_text'] : Messages::get('no_results', $lang);
        $icon = $props['icon_position'] !== 'none' ? (Icons::catalog()[$props['icon']] ?? null) : null;
        $classes = 'c-search c-search-' . $props['size'] . ' c-search-' . $props['variant'] . ($icon !== null ? ' c-search-icon-' . $props['icon_position'] : '');
        $data = [
            'search' => $this->ctx->searchUrl(),
            'search-types' => implode(',', $props['collections']),
            'search-fields' => implode(',', $props['fields']),
            'search-limit' => (string) $props['max_results'],
            'search-min' => (string) $props['min_chars'],
            'search-locale' => $this->ctx->locale,
            'search-empty' => $empty,
            'search-count' => Messages::get('search_results', $lang),
            'search-page' => $props['results_page'] ? $this->ctx->searchPageUrl() : '',
        ];
        $attributes = '';
        foreach (array_filter($data, static fn(string $value): bool => $value !== '') as $name => $value) {
            $attributes .= ' data-' . $name . '="' . Security::e($value) . '"';
        }
        if ($props['show_type']) {
            $attributes .= ' data-search-show-type';
        }

        $warning = '';
        if ($this->annotating() && (new SiteSearch($this->ctx))->targets($props['collections'], $props['fields']) === []) {
            $warning = '<div class="c-placeholder c-placeholder-warning" role="status"' . $this->placeholder('searchNothing') . '>Nothing to search: choose content types with website pages and fields they have</div>';
        }
        $svg = $icon !== null
            ? '<svg class="c-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" focusable="false" aria-hidden="true">' . $icon['body'] . '</svg>'
            : '';

        // A results page form keeps the query of its address (a preview's workspace and path) in hidden fields.
        $tag = $props['results_page'] ? 'form' : 'div';
        $form = '';
        if ($props['results_page']) {
            $action = $this->ctx->searchPageUrl();
            $attributes .= ' action="' . Security::e((string) strtok($action, '?')) . '" method="get"';
            parse_str((string) parse_url($action, PHP_URL_QUERY), $hidden);
            foreach ($hidden as $name => $value) {
                if (is_string($value)) $form .= '<input type="hidden" name="' . Security::e((string) $name) . '" value="' . Security::e($value) . '">';
            }
        }

        return $this->element(
            $node,
            $warning
                . '<' . $tag . ' class="' . $classes . '" role="search"' . $attributes . '>' . $form
                . '<label for="' . Security::e($id) . '" class="c-visually-hidden">' . Security::e(Messages::get('search', $lang)) . '</label>'
                . '<div class="c-search-field">' . $svg
                . '<input type="search" id="' . Security::e($id) . '" class="c-search-input" placeholder="' . Security::e($placeholder) . '"'
                . ($props['results_page'] ? ' name="q" value="' . Security::e($this->ctx->searchQuery) . '"' : '')
                . ' autocomplete="off" spellcheck="false" enterkeyhint="search" maxlength="' . SiteSearch::MAX_QUERY_LENGTH . '"'
                . ' role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . Security::e($id) . '-results"'
                // In the builder the field is part of the canvas, not something to type into.
                . ($this->annotating() ? ' readonly tabindex="-1"' : '') . '>'
                . '</div>'
                . '<ul id="' . Security::e($id) . '-results" class="c-search-results" role="listbox" aria-label="' . Security::e(Messages::get('search_results_label', $lang)) . '" hidden></ul>'
                . '<p class="c-search-status c-visually-hidden" role="status" aria-live="polite"></p>'
                . '</' . $tag . '>'
        );
    }

    private function collection(array $node, array $state): string
    {
        $props = $node['props'];
        if ($props['list_source'] === 'search') {
            return $this->searchCollection($node, $state);
        }
        if ($props['list_source'] === 'types') {
            return $this->mixedCollection($node, $state);
        }
        $type = $props['type'];

        if ($type === '' || !$this->ctx->isPublicType($type) || ($state['depth'] ?? 0) > self::MAX_CARD_DEPTH) {
            return $this->annotating() ? $this->element($node, '<div class="c-placeholder"' . $this->placeholder('chooseType') . '>Choose a public content type</div>') : '';
        }

        $schema = $this->ctx->type($type);
        $filters = new ListFilters($this->ctx, $node, $schema ?? []);
        $page = $props['paginate'] ? max(1, (int) ($state['page'] ?? 1)) : 1;
        $params = [
            'sort' => $filters->sort() ?? ($props['sort_order'] === 'desc' ? '-' : '') . $props['sort_field'],
            'limit' => $props['limit'],
            'offset' => ($page - 1) * $props['limit'],
        ];

        if ($props['filter_field'] !== '' && $props['filter_value'] !== '') {
            $params['filter'] = [$props['filter_field'] => ['eq' => $props['filter_value']]];
        }

        // Visitor filters narrow the editor's own filter further.
        foreach ($filters->conditions() as $field => $condition) {
            $params['filter'][$field] = ($params['filter'][$field] ?? []) + $condition;
        }

        $current = $state['entry'] ?? null;
        if ($props['exclude_current'] && is_array($current) && ($current['collection'] ?? '') === $type) {
            $params['filter']['slug'] = ['ne' => (string) ($current['slug'] ?? $current['id'] ?? '')];
        }

        $result = $this->ctx->query($type, $params);
        $bar = $filters->html((string) ($state['path'] ?? ''));
        if ($props['paginate']) {
            $this->listPages = max($this->listPages, 1, (int) ceil($result['total'] / $props['limit']));
        }

        if ($result['data'] === []) {
            $empty = $props['empty_text'] !== '' ? '<p class="c-empty">' . Security::e($props['empty_text']) . '</p>' : '';
            if ($empty === '' && $filters->chosen() !== []) {
                $empty = '<p class="c-empty">' . Security::e(Messages::get('no_results', $this->ctx->htmlLang())) . '</p>';
            }
            if ($empty === '' && $this->annotating()) {
                $empty = '<div class="c-placeholder"' . $this->placeholder('noEntries', ['type' => (string) ($schema['label'] ?? $type)]) . '>No published ' . Security::e((string) ($schema['label'] ?? $type)) . ' yet</div>';
            }

            // A filter that matches nothing keeps its bar, so it can be changed again.
            return $empty === '' && $bar === '' ? '' : $this->element($node, $bar . $empty);
        }

        $html = $bar . $this->cardGrid($type, $result['data'], $props['columns'], $state, $node['children']);

        if ($props['paginate'] && $result['total'] > $props['limit']) {
            $html .= $this->pagination($page, (int) ceil($result['total'] / $props['limit']), ['path' => $filters->url((string) ($state['path'] ?? ''), $filters->chosen())] + $state);
        }

        return $this->element($node, $html);
    }

    /**
     * Entries of several types in one list, each as a card of its own type.
     * Each type is asked for the entries up to the end of the page, which are
     * then merged by the field the list sorts by. Visitor filters use the
     * fields of all the types; a type without a filtered field has no match.
     */
    private function mixedCollection(array $node, array $state): string
    {
        $props = $node['props'];
        $types = array_values(array_filter($props['collections'], fn(string $type): bool => $this->ctx->isPublicType($type)));

        if ($types === [] || ($state['depth'] ?? 0) > self::MAX_CARD_DEPTH) {
            return $this->annotating() ? $this->element($node, '<div class="c-placeholder"' . $this->placeholder('chooseTypes') . '>Choose public content types</div>') : '';
        }

        $schemas = array_combine($types, array_map(fn(string $type): array => $this->ctx->type($type) ?? [], $types));
        $filters = new ListFilters($this->ctx, $node, ListFilters::combinedSchema($schemas));
        $sort = $filters->sort();
        // Only dates mean the same in every type, so the list's own order uses one; a visitor may choose another field.
        $field = $sort !== null ? ltrim($sort, '-') : (in_array($props['sort_field'], ListFilters::DATE_FIELDS, true) ? $props['sort_field'] : 'published_at');
        $desc = $sort !== null ? str_starts_with($sort, '-') : $props['sort_order'] === 'desc';
        $conditions = $filters->conditions();
        $everywhere = array_flip(['title', ...ListFilters::DATE_FIELDS]);
        $page = $props['paginate'] ? max(1, (int) ($state['page'] ?? 1)) : 1;
        $offset = ($page - 1) * $props['limit'];
        $current = $state['entry'] ?? null;

        $items = [];
        $total = 0;
        foreach ($types as $type) {
            $fields = $schemas[$type]['fields'] ?? [];
            if (array_diff_key($conditions, $fields, $everywhere) !== []) continue;
            $sortable = isset($fields[$field]) || isset($everywhere[$field]);
            $params = ['sort' => ($desc ? '-' : '') . ($sortable ? $field : 'published_at'), 'limit' => $offset + $props['limit']];
            if ($conditions !== []) $params['filter'] = $conditions;
            if ($props['exclude_current'] && is_array($current) && ($current['collection'] ?? '') === $type) {
                $params['filter']['slug'] = ['ne' => (string) ($current['slug'] ?? $current['id'] ?? '')];
            }
            $result = $this->ctx->query($type, $params);
            $total += $result['total'];
            foreach ($result['data'] as $entry) {
                $items[] = ['type' => $type, 'entry' => $entry, 'value' => $sortable ? ($entry[$field] ?? null) : null];
            }
        }
        // Entries without the field come last either way.
        usort($items, static function (array $a, array $b) use ($desc): int {
            if (SmartValue::empty($a['value']) || SmartValue::empty($b['value'])) return SmartValue::empty($a['value']) <=> SmartValue::empty($b['value']);
            $order = is_numeric($a['value']) && is_numeric($b['value']) ? (float) $a['value'] <=> (float) $b['value']
                : strnatcasecmp(is_scalar($a['value']) ? (string) $a['value'] : '', is_scalar($b['value']) ? (string) $b['value'] : '');
            return $desc ? -$order : $order;
        });
        $items = array_slice($items, $offset, $props['limit']);
        $bar = $filters->html((string) ($state['path'] ?? ''));
        if ($props['paginate']) {
            $this->listPages = max($this->listPages, 1, (int) ceil($total / $props['limit']));
        }

        if ($items === []) {
            $empty = $props['empty_text'] !== '' ? '<p class="c-empty">' . Security::e($props['empty_text']) . '</p>' : '';
            if ($empty === '' && $filters->chosen() !== []) {
                $empty = '<p class="c-empty">' . Security::e(Messages::get('no_results', $this->ctx->htmlLang())) . '</p>';
            }
            if ($empty === '' && $this->annotating()) {
                $labels = implode(', ', array_map(fn(string $type): string => (string) ($schemas[$type]['label'] ?? $type), $types));
                $empty = '<div class="c-placeholder"' . $this->placeholder('noEntries', ['type' => $labels]) . '>No published ' . Security::e($labels) . ' yet</div>';
            }

            return $empty === '' && $bar === '' ? '' : $this->element($node, $bar . $empty);
        }

        $html = $bar . $this->cardGrid('', array_column($items, 'entry'), $props['columns'], $state, $node['children'], array_column($items, 'type'));
        if ($props['paginate'] && $total > $props['limit']) {
            $html .= $this->pagination($page, (int) ceil($total / $props['limit']), ['path' => $filters->url((string) ($state['path'] ?? ''), $filters->chosen())] + $state);
        }

        return $this->element($node, $html);
    }

    /**
     * The hits of the query the search results page was opened with
     * (SiteContext::$searchQuery), best first, each hit as a card of its own
     * type. Without a query it shows nothing; a query without hits shows the
     * empty text or the standard "no results".
     */
    private function searchCollection(array $node, array $state): string
    {
        $props = $node['props'];
        $query = $this->ctx->searchQuery;

        if (($state['depth'] ?? 0) > self::MAX_CARD_DEPTH || ($query === '' && !$this->annotating())) {
            return '';
        }
        // Only the search results page has a query; anywhere else the list stays empty.
        if (!$this->ctx->searchPage) {
            return $this->annotating()
                ? $this->element($node, '<div class="c-placeholder c-placeholder-warning" role="status"' . $this->placeholder('searchResultsElsewhere') . '>Search results only show on the search results page</div>')
                : '';
        }
        // The builder has no query; the newest entries stand in for hits, so the card can be designed.
        if ($query === '') {
            // As many as the list shows, so the canvas looks like a full page of hits.
            $samples = (new SiteSearch($this->ctx))->samples($props['collections'], $props['limit']);
            $note = '<div class="c-placeholder"' . $this->placeholder('searchResults') . '>Lists the hits of the submitted search</div>';
            return $this->element($node, $note . ($samples === [] ? '' : $this->cardGrid('', array_column($samples, 'entry'), $props['columns'], $state, $node['children'], array_column($samples, 'type'))));
        }

        $page = $props['paginate'] ? max(1, (int) ($state['page'] ?? 1)) : 1;
        $result = (new SiteSearch($this->ctx))->page($query, $props['collections'], $props['search_fields'], $props['limit'], ($page - 1) * $props['limit']);

        if ($result['data'] === []) {
            $text = $props['empty_text'] !== '' ? $props['empty_text'] : Messages::get('no_results', $this->ctx->htmlLang());
            return $this->element($node, '<p class="c-empty">' . Security::e($text) . '</p>');
        }

        $html = $this->cardGrid('', array_column($result['data'], 'entry'), $props['columns'], $state, $node['children'], array_column($result['data'], 'type'));
        if ($props['paginate'] && $result['total'] > $props['limit']) {
            // Page links keep the query, which the page's own path leaves out.
            $path = $this->ctx->searchPageUrl();
            $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query(['q' => $query]);
            $html .= $this->pagination($page, (int) ceil($result['total'] / $props['limit']), ['path' => $path] + $state);
        }

        return $this->element($node, $html);
    }

    /**
     * Cards for $entries: the collection's own card ($template), or the
     * type's standard card. Only the first card of an own template carries
     * builder annotations, so it is the one edited in place. $types gives
     * entries of a mixed list their own types, by position.
     */
    private function cardGrid(string $type, array $entries, int $columns, array $state, array $template = [], array $types = []): string
    {
        $editable = $template !== [];
        $items = '';
        $childState = [
            'depth' => ($state['depth'] ?? 0) + 1,
            'path' => $state['path'] ?? '',
            'components' => $state['components'] ?? [],
            'slot' => $this->gridSlot($this->slot($state), $columns),
        ];

        foreach (array_values($entries) as $index => $entry) {
            $entryType = (string) ($types[$index] ?? $type);
            $entryState = ['entry' => $this->ctx->localize($entryType, $entry), 'type' => $this->ctx->type($entryType)] + $childState;
            $card = $editable ? $template : $this->ctx->cardTemplate($entryType);
            $inner = $editable && $index === 0 ? $this->render($card, $entryState) : $this->renderPlain($card, $entryState);
            $items .= '<article class="c-card">' . $inner . '</article>';
        }

        return '<div class="c-grid c-cols-' . max(1, min(6, $columns)) . '">' . $items . '</div>';
    }

    private function pagination(int $page, int $pages, array $state): string
    {
        $path = (string) ($state['path'] ?? '');
        $link = static fn(int $target): string => Security::e($path . ($target > 1 ? (str_contains($path, '?') ? '&' : '?') . 'page=' . $target : ''));
        $html = '<nav class="c-pagination" aria-label="' . Security::e(Messages::get('pagination', $this->ctx->htmlLang())) . '"><ul>';

        $html .= $page > 1
            ? '<li><a href="' . $link($page - 1) . '" rel="prev">‹</a></li>'
            : '<li><span aria-hidden="true">‹</span></li>';

        for ($i = 1; $i <= $pages; $i++) {
            if ($pages > 9 && $i !== 1 && $i !== $pages && abs($i - $page) > 2) {
                if ($i === 2 || $i === $pages - 1) {
                    $html .= '<li><span>…</span></li>';
                }
                continue;
            }

            $html .= $i === $page
                ? '<li><span aria-current="page">' . $i . '</span></li>'
                : '<li><a href="' . $link($i) . '">' . $i . '</a></li>';
        }

        $html .= $page < $pages
            ? '<li><a href="' . $link($page + 1) . '" rel="next">›</a></li>'
            : '<li><span aria-hidden="true">›</span></li>';

        return $html . '</ul></nav>';
    }

    // ─── Field binding ───────────────────────────────────────────────────────

    private function field(array $node, array $state): string
    {
        $props = $node['props'];
        $source = $props['source'] ?? null;
        $name = is_array($source) ? implode('.', $source['path']) : '';
        $resolved = is_array($source) ? $this->smart->resolve($source, $state) : ['value' => null, 'config' => null];
        $value = $resolved['value'];
        $config = $resolved['config'];

        if ($config === null) {
            return $this->annotating()
                ? $this->element($node, '<div class="c-placeholder c-placeholder-warning"' . ($name === '' ? $this->placeholder('chooseField') : $this->placeholder('missingField', ['name' => $name])) . '>' . ($name === '' ? 'Choose a field' : 'Missing field “' . Security::e($name) . '”') . '</div>')
                : '';
        }

        $fieldType = (string) ($config['type'] ?? 'text');
        $displays = LayoutSchema::FIELD_DISPLAYS[$fieldType] ?? ['text'];
        if ($fieldType === 'media' && !empty($config['multiple'])) $displays = ['gallery', 'files'];
        $display = in_array($props['display'], $displays, true) ? $props['display'] : $displays[0];
        $html = $this->isEmpty($value) ? '' : $this->fieldHtml($node, $value, $config, $fieldType, $display, $state);

        if ($html === '') {
            return $this->annotating()
                ? $this->element($node, '<div class="c-placeholder"' . $this->placeholder('fieldEmpty', ['name' => $this->fieldLabel($name, $config)]) . '>' . Security::e($this->fieldLabel($name, $config)) . ' (empty)</div>')
                : '';
        }

        if ($props['label']) {
            $html = '<div class="c-field-label">' . Security::e($this->fieldLabel($name, $config)) . '</div>' . $html;
        }

        if (!empty($resolved['missing']) && $this->annotating()) $html = '<div class="c-placeholder c-placeholder-warning"' . $this->placeholder('unresolved') . '>Unresolved content reference</div>' . $html;
        return $this->element($node, $html, ['c-field', 'c-field-' . $fieldType]);
    }

    private function fieldHtml(array $node, mixed $value, array $config, string $fieldType, string $display, array $state): string
    {
        $props = $node['props'];

        return match ($fieldType) {
            'markdown', 'html' => $display === 'excerpt'
                ? '<p>' . Security::e(Text::words($this->plainText($value, $fieldType), $props['truncate'] ?: 30)) . '</p>'
                : '<div class="c-prose">' . ($fieldType === 'markdown' ? Markdown::toHtml((string) $value) : HtmlSanitizer::sanitize((string) $value)) . '</div>',
            'textarea' => $display === 'excerpt' || $props['truncate'] > 0
                ? $this->textTag($props, Security::e(Text::words((string) $value, $props['truncate'] ?: 30)), $state, 'p')
                : $this->textTag($props, $this->paragraphs((string) $value), $state, 'div'),
            'number', 'range' => $display === 'meter' && $fieldType === 'range'
                ? $this->meter($value, $config, $props)
                : $this->textTag($props, Security::e($props['prefix'] . $this->formatNumber($value) . $props['suffix']), $state, 'p'),
            'boolean' => $this->booleanHtml((bool) $value, $props, $display),
            'select' => $this->selectHtml($value, $config, $props, $display, $state),
            'date', 'datetime' => '<p><time datetime="' . Security::e((string) $value) . '">'
                . Security::e($this->formatDate((string) $value, $fieldType === 'date' && $props['date_format'] === 'datetime' ? 'date' : $props['date_format'])) . '</time></p>',
            'color' => $this->colorHtml((string) $value, $display),
            'media' => $this->mediaField($value, $props, $display, $state),
            'relation' => $this->relationField($value, $config, $props, $display, $state),
            'repeater' => $this->repeaterField($node, $value, $config, $state),
            'json' => '<pre class="c-code"><code>' . Security::e(is_string($value) ? $value : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</code></pre>',
            default => $this->textTag($props, Security::e($props['truncate'] > 0 ? Text::words((string) $value, $props['truncate']) : (string) $value), $state, 'p'),
        };
    }

    /** Wrap text in the chosen tag, with the configured link destination. */
    private function textTag(array $props, string $html, array $state, string $defaultTag): string
    {
        $tag = $props['tag'] !== '' ? $props['tag'] : $defaultTag;

        $link = $this->resolveLink($props['link'], $state);
        if ($link !== null) $html = $this->anchor($link, $html);

        $class = str_starts_with($tag, 'h') ? ' class="c-heading"' : '';

        return '<' . $tag . $class . '>' . $html . '</' . $tag . '>';
    }

    private function paragraphs(string $text): string
    {
        $blocks = preg_split('/\R{2,}/', trim($text)) ?: [];

        return implode('', array_map(static fn(string $block): string => '<p>' . nl2br(Security::e(trim($block)), false) . '</p>', $blocks));
    }

    private function booleanHtml(bool $value, array $props, string $display): string
    {
        $text = $value ? ($props['true_text'] !== '' ? $props['true_text'] : Messages::get('yes', $this->ctx->htmlLang())) : ($props['false_text'] !== '' ? $props['false_text'] : Messages::get('no', $this->ctx->htmlLang()));

        return $display === 'badge'
            ? '<span class="c-badge' . ($value ? ' c-badge-on' : '') . '">' . Security::e($text) . '</span>'
            : '<p>' . Security::e($text) . '</p>';
    }

    private function selectHtml(mixed $value, array $config, array $props, string $display, array $state): string
    {
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $labels = array_map(static function (mixed $item) use ($options): string {
            $item = (string) $item;
            return !array_is_list($options) && isset($options[$item]) ? (string) $options[$item] : $item;
        }, is_array($value) ? $value : [$value]);

        if ($display === 'badges') {
            return '<div class="c-badges">' . implode('', array_map(static fn(string $label): string => '<span class="c-badge">' . Security::e($label) . '</span>', $labels)) . '</div>';
        }

        return $this->textTag($props, Security::e($props['prefix'] . implode(', ', $labels) . $props['suffix']), $state, 'p');
    }

    private function colorHtml(string $value, string $display): string
    {
        if (!preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value)) {
            return '';
        }

        $text = '<code>' . Security::e($value) . '</code>';

        return $display === 'swatch'
            ? '<p class="c-color"><span class="c-swatch" style="background:' . Security::e($value) . '"></span>' . $text . '</p>'
            : '<p>' . $text . '</p>';
    }

    private function meter(mixed $value, array $config, array $props): string
    {
        $min = is_numeric($config['min'] ?? null) ? (float) $config['min'] : 0.0;
        $max = is_numeric($config['max'] ?? null) ? (float) $config['max'] : 100.0;
        $number = (float) $value;

        return '<div class="c-meter"><meter min="' . $min . '" max="' . $max . '" value="' . $number . '"></meter><span>'
            . Security::e($props['prefix'] . $this->formatNumber($value) . $props['suffix']) . '</span></div>';
    }

    private function mediaField(mixed $value, array $props, string $display, array $state): string
    {
        $items = array_values(array_filter(array_map(
            fn(mixed $file): ?array => is_scalar($file) ? $this->ctx->mediaItem((string) $file) : null,
            is_array($value) ? $value : [$value]
        )));

        if ($items === []) {
            return '';
        }

        if ($display === 'files') {
            $links = '';
            foreach ($items as $item) {
                $label = (string) (($item['title'] ?? '') ?: $item['name']);
                $links .= '<li><a href="' . Security::e($this->ctx->mediaUrl($item['name'])) . '" download>' . Security::e($label) . '</a> <span class="c-muted">' . Security::e($this->fileSize((int) $item['size'])) . '</span></li>';
            }

            return '<ul class="c-files">' . $links . '</ul>';
        }

        if ($display === 'gallery' && count($items) > 1) {
            $columns = max(2, $props['columns']);
            $gallery = '';
            $state['slot'] = $this->gridSlot($this->slot($state), $columns);
            foreach ($items as $item) {
                $gallery .= '<div class="c-gallery-item">' . $this->linkedMedia($item, $props, $state) . '</div>';
            }

            return '<div class="c-grid c-gallery c-gap-sm c-cols-' . $columns . '">' . $gallery . '</div>';
        }

        return $this->linkedMedia($items[0], $props, $state);
    }

    private function linkedMedia(array $item, array $props, array $state): string
    {
        $html = $this->mediaElement($item, $props, $state);
        $link = $this->resolveLink($props['link'], $state);
        return $link !== null && str_starts_with((string) ($item['mime'] ?? ''), 'image/')
            ? $this->anchor($link, $html, 'c-image-link') : $html;
    }

    private function relationField(mixed $value, array $config, array $props, string $display, array $state): string
    {
        $target = (string) ($config['target'] ?? '');

        if ($target === '' || !$this->ctx->isPublicType($target)) {
            return '';
        }

        $entries = [];
        foreach (is_array($value) ? $value : [$value] as $id) {
            $entry = is_scalar($id) ? $this->ctx->findEntry($target, (string) $id) : null;
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        if ($entries === []) {
            return '';
        }

        if ($display === 'cards' && ($state['depth'] ?? 0) < self::MAX_CARD_DEPTH) {
            return $this->cardGrid($target, $entries, max(1, $props['columns']), $state);
        }

        $titles = array_map(function (array $entry) use ($target, $display): string {
            $title = Security::e((string) ($entry['title'] ?? ''));
            $url = $display === 'text' ? null : $this->ctx->entryUrl($target, $entry);

            return $url !== null ? '<a href="' . Security::e($url) . '">' . $title . '</a>' : $title;
        }, $entries);

        if ($display === 'text' || count($titles) === 1) {
            return '<p>' . implode(', ', $titles) . '</p>';
        }

        return '<ul class="c-links">' . implode('', array_map(static fn(string $title): string => '<li>' . $title . '</li>', $titles)) . '</ul>';
    }

    private function repeaterField(array $node, mixed $rows, array $config, array $state): string
    {
        if (!is_array($rows) || $rows === []) {
            return '';
        }

        $subfields = [];
        foreach (is_array($config['subfields'] ?? null) ? $config['subfields'] : [] as $subfield) {
            if (is_array($subfield) && ($subfield['key'] ?? '') !== '') {
                $subfields[(string) $subfield['key']] = $subfield;
            }
        }

        $template = $node['children'];
        if ($template === []) {
            foreach ($subfields as $key => $subfield) {
                $template[] = LayoutSchema::node('field', ['source' => SmartValue::reference($key, 'item'), 'display' => LayoutSchema::defaultDisplay((string) ($subfield['type'] ?? 'text'))]);
            }
        }

        $items = '';
        $first = true;
        foreach (array_values($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowState = ['row' => $row, 'row_fields' => $subfields] + $state;
            // Only the first item carries builder annotations; ids must stay unique.
            $inner = $first ? $this->render($template, $rowState) : $this->renderPlain($template, $rowState);
            $items .= '<div class="c-item">' . $inner . '</div>';
            $first = false;
        }

        $columns = max(1, $node['props']['columns']);

        return '<div class="c-items c-grid c-cols-' . $columns . '">' . $items . '</div>';
    }

    // ─── Media ───────────────────────────────────────────────────────────────

    private function mediaElement(array $item, array $props, array $state): string
    {
        $mime = (string) ($item['mime'] ?? '');
        $alt = (string) ($item['alt'] ?? '');

        if (str_starts_with($mime, 'video/')) {
            return '<video class="c-video" controls preload="metadata" src="' . Security::e($this->ctx->mediaUrl($item['name'])) . '"></video>';
        }

        if (str_starts_with($mime, 'audio/')) {
            return '<audio class="c-audio" controls preload="metadata" src="' . Security::e($this->ctx->mediaUrl($item['name'])) . '"></audio>';
        }

        if (!str_starts_with($mime, 'image/')) {
            $label = (string) (($item['title'] ?? '') ?: $item['name']);

            return '<a class="c-file" href="' . Security::e($this->ctx->mediaUrl($item['name'])) . '" download>' . Security::e($label) . '</a>';
        }

        // The browser picks the file from srcset by how wide the image is shown.
        [$src, $candidates] = $this->imageCandidates($item);
        $maxWidth = (int) ($props['max_width'] ?? 0);
        $slot = $maxWidth > 0 ? $this->narrowSlot($this->slot($state), [], (float) $maxWidth) : $this->slot($state);
        $srcset = count($candidates) > 1
            ? ' srcset="' . Security::e(implode(', ', $candidates)) . '" sizes="' . Security::e($this->sizesAttribute($slot)) . '"'
            : '';

        $img = '<img src="' . Security::e($src) . '"' . $srcset . ' alt="' . Security::e($alt) . '"'
            . $this->dimensions($item) . ' loading="lazy" decoding="async">';

        $ratio = $props['ratio'] ?? '';
        if ($ratio !== '') {
            $fit = ($props['fit'] ?? 'cover') === 'contain' ? ' c-fit-contain' : '';
            $position = $props['position'] ?? 'center';
            $fit .= $position !== 'center' && in_array($position, LayoutSchema::POSITIONS, true) ? ' c-obj-pos-' . $position : '';

            return '<div class="c-ratio c-ratio-' . str_replace(':', '-', $ratio) . $fit . '">' . $img . '</div>';
        }

        return $img;
    }

    /** The largest file an image is shown with: the large size, or the original when it is not larger. */
    private function imageSource(array $item): string
    {
        return $this->imageCandidates($item)[0];
    }

    /**
     * srcset candidates for every named size and the largest of them. The
     * original is only offered when it is not larger than the large size, so
     * huge uploads are never sent to visitors.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function imageCandidates(array $item): array
    {
        $candidates = [];
        $src = '';
        foreach ($item['sizes'] ?? [] as $name => $dimensions) {
            $src = $this->ctx->mediaUrl($item['name'], ['size' => $name]);
            $candidates[] = $src . ' ' . (int) $dimensions['width'] . 'w';
        }
        if (isset($item['sizes']['large'])) {
            return [$src, $candidates];
        }

        $src = $this->ctx->mediaUrl($item['name']);
        if (!empty($item['width'])) {
            $candidates[] = $src . ' ' . (int) $item['width'] . 'w';
        }

        return [$src, $candidates];
    }

    /**
     * Where an element sits on each device: the share of the screen it may
     * fill and the most CSS pixels it may reach, as sections and columns
     * narrow it. Gaps and gutters are left out, so images err on sharp.
     *
     * @return array<string, array{0: float, 1: ?float}>
     */
    private function slot(array $state): array
    {
        return $state['slot'] ?? ['desktop' => [1.0, null], 'tablet' => [1.0, null], 'mobile' => [1.0, null]];
    }

    /** $slot with each device's share scaled by its factor, then held to $max pixels. */
    private function narrowSlot(array $slot, array $factors, ?float $max = null): array
    {
        foreach ($slot as $device => [$share, $cap]) {
            $factor = $factors[$device] ?? 1.0;
            $cap = $cap === null ? null : $cap * $factor;
            $slot[$device] = [$share * $factor, $max === null ? $cap : min($cap ?? $max, $max)];
        }

        return $slot;
    }

    /** One cell of a .c-grid with $columns columns: one per row on phones, two on tablets. */
    private function gridSlot(array $slot, int $columns): array
    {
        $columns = max(1, $columns);

        return $this->narrowSlot($slot, ['mobile' => 1.0, 'tablet' => $columns > 1 ? 0.5 : 1.0, 'desktop' => 1 / $columns]);
    }

    /** A sizes attribute for $slot, naming each device only where it differs from the next smaller one. */
    private function sizesAttribute(array $slot): string
    {
        $width = static function (array $device): string {
            [$share, $cap] = $device;
            $vw = max(1, (int) ceil($share * 100)) . 'vw';

            return $cap === null ? $vw : 'min(' . $vw . ', ' . (int) ceil($cap) . 'px)';
        };
        [$desktop, $tablet, $mobile] = [$width($slot['desktop']), $width($slot['tablet']), $width($slot['mobile'])];
        $sizes = [];
        if ($desktop !== $tablet) $sizes[] = '(min-width: 1024px) ' . $desktop;
        if ($tablet !== $mobile) $sizes[] = '(min-width: 768px) ' . $tablet;
        $sizes[] = $mobile;

        return implode(', ', $sizes);
    }

    /** The CSS pixels an element in $slot is about as wide on a typical screen of $device. */
    private function slotPixels(array $slot, string $device): float
    {
        [$share, $cap] = $slot[$device];

        return min(self::SCREEN_WIDTHS[$device] * $share, $cap ?? INF);
    }

    private function dimensions(array $item): string
    {
        $width = (int) ($item['width'] ?? 0);
        $height = (int) ($item['height'] ?? 0);

        return $width > 0 && $height > 0 ? ' width="' . $width . '" height="' . $height . '"' : '';
    }

    // ─── Links ───────────────────────────────────────────────────────────────

    /** @return array{href: string, new_tab: bool, open_modal: bool}|null */
    private function resolveLink(array|bool $link, array $state): ?array
    {
        $href = match ($link['type'] ?? 'none') {
            'url' => $this->siteUrl((string) ($link['url'] ?? '')),
            // Bound destinations, resolved by boundLink() before rendering.
            'href' => (string) ($link['href'] ?? ''),
            'dynamic' => $this->boundLink($this->smart->resolve($link['value'], $state), false)['href'],
            'current' => $this->currentEntryUrl($state),
            'home' => $this->ctx->homeUrl(),
            'index' => $this->ctx->indexUrl((string) ($link['collection'] ?? '')),
            'entry' => $this->linkedEntryUrl((string) ($link['collection'] ?? ''), (string) ($link['entry'] ?? '')),
            'anchor' => ($link['anchor'] ?? '') !== '' ? '#' . $link['anchor'] : null,
            default => null,
        };

        if ($href !== null && $href !== '' && in_array($link['type'] ?? '', ['home', 'entry', 'index'], true) && ($link['anchor'] ?? '') !== '') {
            $href .= '#' . $link['anchor'];
        }

        // Older or hand-edited links may carry the flag on other destinations.
        $modal = in_array($link['type'] ?? '', LayoutSchema::MODAL_LINK_TYPES, true) && !empty($link['open_modal']);

        return $href === null || $href === '' ? null : ['href' => $href, 'new_tab' => !$modal && (bool) ($link['new_tab'] ?? false), 'open_modal' => $modal];
    }

    private function linkedEntryUrl(string $type, string $slug): ?string
    {
        if ($type === '' || $slug === '') {
            return null;
        }

        $entry = $this->ctx->findEntry($type, $slug);

        return $entry !== null ? $this->ctx->entryUrl($type, $entry) : null;
    }

    private function linkTitle(array $link): string
    {
        return match ($link['type'] ?? 'none') {
            'home' => Messages::get('home', $this->ctx->htmlLang()),
            'index' => (string) ($this->ctx->route((string) ($link['collection'] ?? ''))['index_title'] ?? '') ?: (string) ($this->ctx->type((string) ($link['collection'] ?? ''))['label'] ?? ''),
            'entry' => (string) ($this->ctx->findEntry((string) ($link['collection'] ?? ''), (string) ($link['entry'] ?? ''))['title'] ?? ''),
            'url' => (string) ($link['url'] ?? ''),
            'anchor' => '#' . ($link['anchor'] ?? ''),
            default => '',
        };
    }

    /** Root-relative URLs typed by editors are relative to the website, not the server. */
    private function siteUrl(string $url): ?string
    {
        if ($url === '' || !HtmlSanitizer::isSafeUrl($url)) {
            return null;
        }

        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? $this->ctx->path($url) : $url;
    }

    private function currentEntryUrl(array $state): ?string
    {
        $entry = $state['entry'] ?? null;

        if (!is_array($entry) || ($entry['collection'] ?? '') === '') {
            return null;
        }

        return $this->ctx->entryUrl((string) $entry['collection'], $entry);
    }

    private function anchor(array $link, string $html, string $class = ''): string
    {
        return '<a href="' . Security::e($link['href']) . '"' . ($class !== '' ? ' class="' . $class . '"' : '')
            . (!empty($link['open_modal']) ? ' data-link-modal aria-haspopup="dialog"' : '')
            . ($link['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $html . '</a>';
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** @param string $attributes further escaped attributes, each with its leading space */
    private function element(array $node, string $inner, array $extraClasses = [], string $style = '', string $attributes = ''): string
    {
        $background = $this->background($node['style']);
        $classes = ['c-el', 'c-el-' . preg_replace('/[^a-z0-9-]/', '-', str_replace('_', '-', $node['kind'])), ...$extraClasses, ...$background['classes'], ...$this->styleClasses($node['style'])];
        $style = implode(';', array_filter([$background['style'], $style]));

        return '<div' . $this->attributes($node, $classes, $style) . $attributes . '>' . $background['layer'] . $inner . '</div>';
    }

    private function textGradientAttributes(array $node, string $class): string
    {
        $gradient = Gradient::css($node['style']['text_gradient'] ?? []);

        return ' class="' . $class . ($gradient !== '' ? ' c-text-gradient' : '') . '"'
            . ($gradient !== '' ? ' style="--c-text-gradient:' . Security::e($gradient) . '"' : '');
    }

    /**
     * Classes, inline style and decorative image layer for a background.
     * Image backgrounds only use public images. Blur and scroll effects
     * apply to the separate layer, so the content stays sharp and in place.
     *
     * @return array{classes: list<string>, style: string, layer: string}
     */
    /**
     * A tall element ($tall) is covered by its image on portrait screens too,
     * which shows the image larger than the element is wide.
     */
    private function background(array $style, array $state = [], bool $tall = false): array
    {
        $gradient = Gradient::css($style['background_gradient'] ?? []);
        if ($gradient !== '') {
            return ['classes' => [], 'style' => 'background-image:' . $gradient, 'layer' => ''];
        }
        $file = (string) ($style['background_image'] ?? '');
        $item = $file !== '' ? $this->ctx->mediaItem($file) : null;

        if ($item === null || !str_starts_with((string) $item['mime'], 'image/')) {
            return ['classes' => [], 'style' => '', 'layer' => ''];
        }

        $spec = LayoutSchema::styleSpec();
        $option = static fn(string $key): mixed => $style[$key] ?? $spec[$key]['default'];
        $sources = $this->backgroundSources($item, $this->slot($state), $tall);
        $image = 'url(\'' . $sources['desktop'] . '\')';
        $responsive = count(array_unique($sources)) > 1;
        $blur = (int) $option('background_blur');
        $effect = (string) $option('background_effect');
        $classes = [
            'c-has-bg-image',
            'c-bg-size-' . $option('background_size'),
            'c-bg-pos-' . $option('background_position'),
            $option('background_repeat') ? 'c-bg-repeat' : 'c-bg-no-repeat',
        ];

        if ($option('overlay') !== 'none') {
            $classes[] = 'c-overlay-' . $option('overlay');
        }

        // Each device loads its own size; site.css swaps them by screen width.
        $images = $responsive
            ? implode(';', array_map(static fn(string $device): string => '--c-bg-' . $device[0] . ':url(\'' . $sources[$device] . '\')', array_keys($sources)))
            : '';
        if ($responsive) {
            $classes[] = 'c-bg-responsive';
        }

        if ($blur === 0 && $effect === 'none') {
            return ['classes' => $classes, 'style' => $responsive ? $images : 'background-image:' . $image, 'layer' => ''];
        }

        $classes[] = 'c-has-bg-layer';
        if ($effect !== 'none') {
            $classes[] = 'c-bg-effect-' . $effect;
        }

        return [
            'classes' => $classes,
            'style' => ($responsive ? $images : '--c-background-image:' . $image) . ($blur > 0 ? ';--c-background-blur:' . $blur . 'px' : ''),
            'layer' => '<span class="c-background-layer" aria-hidden="true"></span>',
        ];
    }

    /**
     * The file a background loads on each device: the smallest size that
     * covers the element on a typical screen of that device.
     *
     * @return array{desktop: string, tablet: string, mobile: string}
     */
    private function backgroundSources(array $item, array $slot, bool $tall): array
    {
        $sources = [];
        foreach (array_keys(self::SCREEN_WIDTHS) as $device) {
            $needed = $this->slotPixels($slot, $device) * self::PIXEL_DENSITY * ($tall && $device !== 'desktop' ? 2 : 1);
            $sources[$device] = $this->imageSource($item);
            foreach ($item['sizes'] ?? [] as $name => $dimensions) {
                if ($dimensions['width'] >= $needed) {
                    $sources[$device] = $this->ctx->mediaUrl($item['name'], ['size' => $name]);
                    break;
                }
            }
        }

        return $sources;
    }

    private function attributes(array $node, array $classes, string $style = ''): string
    {
        $classes = [...$classes, ...$this->overrideClasses($node)];
        $style = implode(';', array_filter([$style, $this->offsetStyle($node), $this->shadowStyle($node)]));
        $attributes = ' class="' . Security::e(implode(' ', array_unique(array_filter($classes)))) . '"';

        if (($node['style']['anchor'] ?? '') !== '') {
            $attributes .= ' id="' . Security::e($node['style']['anchor']) . '"';
        }

        if ($style !== '') {
            $attributes .= ' style="' . Security::e($style) . '"';
        }

        if ($this->annotating()) {
            $attributes .= ' data-node="' . Security::e($node['id']) . '" data-kind="' . Security::e($node['kind']) . '"';
        }

        return $attributes;
    }

    /**
     * Classes of a node's tablet, mobile, hover and focus values (see
     * StyleVariants). Button props go on the button itself (see button()).
     */
    private function overrideClasses(array $node): array
    {
        $classes = [];
        $tokens = ['props' => self::PROP_TOKENS[$node['kind']] ?? [], 'style' => self::STYLE_TOKENS];

        foreach (['responsive', 'states'] as $group) {
            foreach ($node[$group] ?? [] as $variant => $values) {
                foreach (['props', 'style'] as $part) {
                    foreach ($values[$part] ?? [] as $key => $value) {
                        if ($part === 'style' && in_array($key, ['shadow', 'text_shadow'], true) && is_array($value)) {
                            $classes[] = StyleVariants::PREFIXES[$variant] . $tokens[$part][$key] . 'custom-' . $variant;
                        }
                        if (isset($tokens[$part][$key]) && is_string($value) && $value !== '') {
                            $classes[] = StyleVariants::PREFIXES[$variant] . $tokens[$part][$key] . $value;
                        }
                    }
                }
            }
        }
        $hover = $node['states']['hover']['style'] ?? [];
        if (($hover['animation'] ?? 'none') !== 'none') {
            $classes[] = 'c-hover-anim';
            $classes[] = 'c-hover-anim-' . $hover['animation'];
            if (($hover['animation_duration'] ?? 'normal') !== 'normal') $classes[] = 'c-hover-anim-' . $hover['animation_duration'];
            if (($hover['animation_delay'] ?? 0) > 0) $classes[] = 'c-hover-anim-d-' . $hover['animation_delay'];
        }
        if (($node['states'] ?? []) !== []) {
            $classes[] = 'c-stateful';
        }

        return $classes;
    }

    /** Each variant owns its variable, so its settings never overwrite another variant. */
    private function shadowStyle(array $node): string
    {
        $declarations = [];
        $variants = ['normal' => $node['style'] ?? []];
        foreach (['responsive', 'states'] as $group) {
            foreach ($node[$group] ?? [] as $variant => $values) $variants[$variant] = $values['style'] ?? [];
        }
        foreach ($variants as $variant => $style) {
            foreach (['shadow' => 'sh', 'text_shadow' => 'ts'] as $key => $token) {
                if (is_array($style[$key] ?? null)) {
                    $css = Shadow::css($style[$key], $key === 'text_shadow');
                    if ($css !== '') $declarations[] = '--c-' . $token . '-custom-' . $variant . ':' . $css;
                }
            }
        }
        return implode(';', $declarations);
    }

    /** A free node's offsets in tenths of a percent of the page width, per device. */
    private function offsetStyle(array $node): string
    {
        $declarations = [];

        foreach (self::OFFSET_VARIABLES as $device => $variables) {
            $values = $device === 'normal' ? $node['style'] : ($node['responsive'][$device]['style'] ?? []);
            foreach ($variables as $key => $variable) {
                if (is_int($values[$key] ?? null) && ($device !== 'normal' || $values[$key] !== 0)) {
                    $declarations[] = $variable . ':' . $values[$key];
                }
            }
        }

        return implode(';', $declarations);
    }

    private function styleClasses(array $style): array
    {
        $classes = [];

        foreach (self::STYLE_TOKENS as $key => $token) {
            if ($key !== 'border_color' && ($style[$key] ?? '') !== '') {
                $classes[] = 'c-' . $token . (is_array($style[$key]) ? 'custom-normal' : $style[$key]);
            }
        }

        $sides = (array) ($style['border'] ?? []);
        if ($sides !== []) {
            $classes[] = 'c-bd';
            foreach ($sides as $side) {
                $classes[] = 'c-bd-' . $side;
            }
            if (($style['border_width'] ?? 1) !== 1) {
                $classes[] = 'c-bdw-' . $style['border_width'];
            }
            if (($style['border_style'] ?? 'solid') !== 'solid') {
                $classes[] = 'c-bds-' . $style['border_style'];
            }
            if (($style['border_color'] ?? '') !== '') {
                $classes[] = 'c-bdc-' . $style['border_color'];
            }
        }

        if (($style['animation'] ?? 'none') !== 'none') {
            $classes[] = 'c-anim';
            $classes[] = 'c-anim-' . $style['animation'];
            if (($style['animation_trigger'] ?? 'scroll') === 'load') {
                $classes[] = 'c-anim-' . $style['animation_trigger'];
            }
            if (($style['animation_duration'] ?? 'normal') !== 'normal') {
                $classes[] = 'c-anim-' . $style['animation_duration'];
            }
            if (($style['animation_delay'] ?? 0) > 0) {
                $classes[] = 'c-anim-d-' . $style['animation_delay'];
            }
        }

        foreach ((array) ($style['hide_on'] ?? []) as $device) {
            $classes[] = 'c-hide-' . $device;
        }
        if (!empty($style['_condition_off'])) {
            $classes[] = 'c-condition-off';
        }

        if (($style['class'] ?? '') !== '') {
            array_push($classes, ...explode(' ', (string) $style['class']));
        }

        return $classes;
    }

    /**
     * Marks a builder placeholder so the admin shows it in its own language
     * (see BuilderCanvas); the English text stays as the fallback.
     */
    private function placeholder(string $key, array $params = []): string
    {
        return ' data-placeholder="' . Security::e($key) . '"'
            . ($params !== [] ? ' data-placeholder-params="' . Security::e((string) json_encode($params, JSON_UNESCAPED_UNICODE)) . '"' : '');
    }

    private function annotating(): bool
    {
        return $this->ctx->annotate && $this->annotationsSuspended === 0;
    }

    private function fieldLabel(string $name, array $config): string
    {
        $label = trim((string) ($config['label'] ?? ''));

        return $label !== '' ? $label : ucfirst(str_replace(['_', '-'], ' ', $name));
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function plainText(mixed $value, string $type): string
    {
        return $type === 'markdown'
            ? Markdown::toText((string) $value)
            : trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], ' ', (string) $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function formatNumber(mixed $value): string
    {
        return Text::number($value, $this->ctx->htmlLang());
    }

    private function formatDate(string $value, string $format): string
    {
        return Text::date($value, $format, $this->ctx->htmlLang());
    }

    private function fileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) $bytes : number_format($size, 1)) . ' ' . $units[$unit];
    }

    private function localeName(string $locale): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayName($locale, $locale);
            if (is_string($name) && $name !== '') {
                return ucfirst($name);
            }
        }

        return strtoupper($locale);
    }
}
