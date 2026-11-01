<?php

declare(strict_types=1);

namespace CometCMS\Site;

use CometCMS\Core\HtmlSanitizer;
use CometCMS\Core\ValidationException;
use CometCMS\Localization\LocalizedValue;
use CometCMS\Localization\LanguageSettings;
use CometCMS\Site\Blocks\BlockRegistry;

/**
 * The vocabulary of website layouts.
 *
 * A layout is a tree of nodes: section > row > column > element. Columns
 * may hold rows of their own (up to MAX_ROW_DEPTH deep), and a collection
 * holds one column as the card it repeats for each entry. Every node
 * has the same shape ({id, kind, props, style, children}) and an optional
 * editor-only name. Optional `responsive` ({tablet, mobile}) and `states`
 * ({hover, focus}) hold {props, style} values that differ on smaller screens
 * or while hovered or focused (see StyleVariants). Props and style values are checked against this schema
 * whenever a layout is saved or
 * previewed, so the renderer only ever sees known keys and values. The admin
 * builder reads the same schema (see spec()) to draw its inspector.
 *
 * Reusable components are stored separately (see SiteRepository); a layout
 * only holds {kind: "component", props: {component, root, span}} instances,
 * whose children are what the instance put into the component's content areas.
 */
final class LayoutSchema
{
    public const FORMAT_VERSION = 3;

    public const VARIANTS = ['detail', 'index'];

    public const REGIONS = ['header', 'footer'];

    /** A layout's header or footer: the site's own (default), none, or a section component's id. */
    public const FRAME_DEFAULT = 'default';
    public const FRAME_NONE = 'none';

    /** Special page templates edited and stored like regions. */
    public const SPECIAL_PAGES = ['not_found', self::MAINTENANCE, self::MODAL, self::SEARCH_RESULTS];

    /** Everything stored and edited as a region document. */
    public const REGION_DOCUMENTS = [...self::REGIONS, ...self::SPECIAL_PAGES];

    public const STRUCTURE = ['section', 'row', 'column'];

    /**
     * A placed reusable component. It stands in for a node of its component's
     * root kind (props.root) and is placed wherever such a node may go.
     */
    public const COMPONENT = 'component';

    /**
     * Where an entry's own page content goes in its type's detail layout.
     * Its children (sections) are the layout's default content, shown while
     * the entry has none of its own in the page field named by props.field.
     * It sits between sections or in a column, never inside another one or
     * in a collection's card, and each page field has one place.
     */
    public const PAGE_CONTENT = 'page_content';

    /**
     * A content area of a component: where each placement adds elements of
     * its own. It sits in one of the component's columns or tabs, outside
     * cards, item templates and the areas of components inside it, and has
     * no content of its own. Its name labels it for editors.
     */
    public const COMPONENT_AREA = 'component_area';

    /**
     * What one instance put into one content area of its component
     * (props.area: the area's node id). An instance's children are these,
     * one per area; content for an area the component no longer has is
     * kept but not shown.
     */
    public const AREA_CONTENT = 'area_content';

    /**
     * The dialog of links that open in a modal, and the region document of
     * the same name that designs it. The template is one modal node: the
     * dialog panel itself, which holds elements and rows like a column.
     */
    public const MODAL = 'modal';

    /** The page every public URL shows while the site's maintenance mode is on. */
    public const MAINTENANCE = 'maintenance';

    /** The page search elements send their query to (/search?q=), when they are set to. */
    public const SEARCH_RESULTS = 'search_results';

    /** Where the page a modal link opens appears, inside the modal template. */
    public const SITE_CONTENT = 'site_content';

    /** Elements only the modal template offers, anywhere inside its panel. */
    public const MODAL_ELEMENTS = [self::SITE_CONTENT, 'modal_title', 'modal_close'];

    /** Link destinations that may open in the modal: pages of this website. */
    public const MODAL_LINK_TYPES = ['current', 'home', 'entry', 'index'];

    public const ELEMENTS = [
        'field',
        'heading',
        'text',
        'html',
        'image',
        'video',
        'gallery',
        'button',
        'accordion',
        'table',
        'tabs',
        'icon',
        'spacer',
        'divider',
        'collection',
        'form',
        'menu',
        'search',
        'site_title',
        'language_switcher',
        'theme_toggle',
    ];

    /** Elements allowed inside a repeater field's item template. */
    public const ITEM_ELEMENTS = ['field', 'heading', 'text', 'html', 'image', 'video', 'gallery', 'button', 'accordion', 'table', 'icon', 'spacer', 'divider'];

    public const SYSTEM_FIELDS = ['title', 'published_at', 'created_at', 'updated_at'];

    /** Display modes per field type; the first one is the default. */
    public const FIELD_DISPLAYS = [
        'text' => ['text'],
        'slug' => ['text'],
        'textarea' => ['text', 'excerpt'],
        'markdown' => ['rich', 'excerpt'],
        'html' => ['rich', 'excerpt'],
        'number' => ['number'],
        'range' => ['number', 'meter'],
        'boolean' => ['text', 'badge'],
        'select' => ['text', 'badges'],
        'date' => ['date'],
        'datetime' => ['date'],
        'color' => ['swatch', 'text'],
        'media' => ['image', 'gallery', 'files'],
        'relation' => ['cards', 'links', 'text'],
        'repeater' => ['items'],
        'json' => ['code'],
    ];

    public const MAX_NODES = 1500;

    public const MAX_NODE_NAME_LENGTH = 120;

    public const TABLE_MAX_ROWS = 300;
    public const TABLE_MAX_COLUMNS = 30;
    public const TABLE_CELL_TAGS = ['a', 'strong', 'b', 'em', 'i', 'u', 's', 'code', 'mark', 'sub', 'sup', 'br', 'span'];

    /** How deep rows may nest inside columns (a section's own rows count as 0). */
    public const MAX_ROW_DEPTH = 3;

    /** Upper bound of an image element's width options, in pixels. */
    public const MAX_IMAGE_WIDTH = 2400;

    /** Focal points for background images and cropped images. */
    public const POSITIONS = ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'];

    private const SPACE = ['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'];

    private const SPACING_KEYS = ['padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left'];

    private const LINK_TYPES = ['none', 'url', 'current', 'entry', 'index', 'home', 'anchor'];

    /** Background image style options. */
    private const BACKGROUND_IMAGE_KEYS = ['background_image', 'background_size', 'background_position', 'background_repeat', 'background_blur', 'background_effect', 'overlay'];

    /** Border sides and how they are drawn; the look only applies once a side is picked. */
    private const BORDER_KEYS = ['border', 'border_width', 'border_style', 'border_color'];

    /** Entrance animation and its timing; the timing only applies to an animation. */
    private const ANIMATION_KEYS = ['animation', 'animation_trigger', 'animation_duration', 'animation_delay'];

    /** Taking an element out of the flow, pinned to its section or the viewport. */
    private const POSITION_KEYS = ['position', 'pin', 'offset_x', 'offset_y'];

    /** Style a tablet or mobile override may change: values that render as a class, and the offsets (see NodeRenderer::offsetStyle). */
    public const RESPONSIVE_STYLE = ['padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'background', 'text_color', 'link_color', 'align', 'font_size', 'font_weight', 'font_family', 'text_shadow', 'radius', 'shadow', 'max_width', ...self::POSITION_KEYS, 'z_index', 'opacity'];

    /** Props a tablet or mobile override may change, per kind. */
    public const RESPONSIVE_PROPS = [
        'section' => ['vertical_align', 'divider_top_height', 'divider_top_width', 'divider_bottom_height', 'divider_bottom_width'],
        'row' => ['gap', 'align'],
        'column' => ['vertical_align', 'spacing', 'content_justify', 'content_align'],
        'button' => ['size', 'full_width'],
        'spacer' => ['size'],
    ];

    /** Style a hover or focus state may change. */
    public const STATE_STYLE = ['background', 'text_color', 'link_color', 'border_color', 'shadow', 'text_shadow', 'opacity'];

    /** Additional style options available only in Hover. */
    public const HOVER_ANIMATION_KEYS = ['animation', 'animation_duration', 'animation_delay'];

    /** Props a hover or focus state may change, per kind. */
    public const STATE_PROPS = [
        'button' => ['button_color', 'button_text_color', 'button_border_color'],
    ];

    public const LOCALIZED_PROPS = [
        'heading' => ['text', 'link'], 'text' => ['html'], 'html' => ['html'],
        'video' => ['media', 'poster', 'video_url', 'title'],
        'image' => ['media', 'link', 'alt', 'caption'], 'gallery' => ['media', 'link'],
        'button' => ['label', 'link'], 'accordion' => ['items'], 'table' => ['rows', 'caption'],
        'tabs' => ['title'], 'tab' => ['title'], 'icon' => ['label', 'link'],
        'collection' => ['empty_text'], 'field' => ['prefix', 'suffix', 'true_text', 'false_text', 'link'],
        'search' => ['placeholder', 'no_results_text'],
        'theme_toggle' => ['label'],
        'modal_close' => ['label'],
        'column' => ['link'],
    ];

    public static function styleSpec(): array
    {
        return [
            'margin_bottom' => ['type' => 'enum', 'options' => ['', ...self::SPACE], 'default' => ''],
            'padding_top' => ['type' => 'enum', 'options' => ['', ...self::SPACE], 'default' => ''],
            'padding_right' => ['type' => 'enum', 'options' => ['', ...self::SPACE], 'default' => ''],
            'padding_bottom' => ['type' => 'enum', 'options' => ['', ...self::SPACE], 'default' => ''],
            'padding_left' => ['type' => 'enum', 'options' => ['', ...self::SPACE], 'default' => ''],
            'margin_top' => ['type' => 'enum', 'options' => ['', ...self::SPACE], 'default' => ''],
            'margin_right' => ['type' => 'enum', 'options' => ['', ...self::SPACE], 'default' => ''],
            'margin_left' => ['type' => 'enum', 'options' => ['', ...self::SPACE], 'default' => ''],
            'background' => ['type' => 'color', 'options' => ['', ...Theme::COLOR_KEYS], 'default' => ''],
            'background_gradient' => ['type' => 'gradient', 'options' => Theme::COLOR_KEYS, 'default' => []],
            // An image is layered over the background color, which shows
            // through transparent images and while the image loads.
            'background_image' => ['type' => 'media', 'default' => '', 'localized' => true],
            'background_size' => ['type' => 'enum', 'options' => ['cover', 'contain', 'original'], 'default' => 'cover'],
            'background_position' => ['type' => 'enum', 'options' => self::POSITIONS, 'default' => 'center'],
            'background_repeat' => ['type' => 'bool', 'default' => false],
            'background_blur' => ['type' => 'int', 'min' => 0, 'max' => 40, 'default' => 0],
            // Fixed holds the image still while the page scrolls; parallax moves it slower than the page.
            'background_effect' => ['type' => 'enum', 'options' => ['none', 'fixed', 'parallax'], 'default' => 'none'],
            'overlay' => ['type' => 'enum', 'options' => ['none', 'light', 'dark'], 'default' => 'none'],
            'text_color' => ['type' => 'color', 'options' => ['', ...Theme::COLOR_KEYS, 'inverse'], 'default' => ''],
            // Links inside the node; buttons keep their own colors.
            'link_color' => ['type' => 'color', 'options' => ['', ...Theme::COLOR_KEYS, 'inverse'], 'default' => ''],
            'text_gradient' => ['type' => 'gradient', 'options' => Theme::COLOR_KEYS, 'default' => []],
            'align' => ['type' => 'enum', 'options' => ['', 'left', 'center', 'right'], 'default' => ''],
            'font_size' => ['type' => 'enum', 'options' => ['', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl', '7xl', '8xl', '9xl'], 'default' => ''],
            'font_weight' => ['type' => 'enum', 'options' => ['', 'normal', 'medium', 'semibold', 'bold'], 'default' => ''],
            'font_family' => ['type' => 'font', 'options' => ['', 'body', 'heading'], 'default' => ''],
            'text_shadow' => ['type' => 'shadow', 'text' => true, 'options' => Theme::COLOR_KEYS, 'default' => ''],
            'radius' => ['type' => 'enum', 'options' => ['', 'none', 'theme', 'sm', 'md', 'lg', 'full'], 'default' => ''],
            'shadow' => ['type' => 'shadow', 'options' => Theme::COLOR_KEYS, 'default' => ''],
            'border' => ['type' => 'set', 'options' => ['left', 'top', 'right', 'bottom'], 'default' => []],
            'border_width' => ['type' => 'int', 'min' => 1, 'max' => 6, 'default' => 1],
            'border_style' => ['type' => 'enum', 'options' => ['solid', 'dashed', 'dotted', 'double'], 'default' => 'solid'],
            'border_color' => ['type' => 'color', 'options' => ['', ...Theme::COLOR_KEYS], 'default' => ''],
            'max_width' => ['type' => 'enum', 'options' => ['', 'narrow', 'prose'], 'default' => ''],
            'animation' => ['type' => 'enum', 'options' => ['none', 'fade', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'zoom-in', 'zoom-out', 'blur'], 'default' => 'none'],
            // Entrance animations play on scroll or load; hover effects live in states.hover.style.
            'animation_trigger' => ['type' => 'enum', 'options' => ['scroll', 'load'], 'default' => 'scroll'],
            'animation_duration' => ['type' => 'enum', 'options' => ['fast', 'normal', 'slow'], 'default' => 'normal'],
            // Stagger step: later steps start a little after earlier ones.
            'animation_delay' => ['type' => 'int', 'min' => 0, 'max' => 6, 'default' => 0],
            // Absolute pins to the section; fixed pins to the viewport while scrolling.
            // Static only matters as a device override back to the flow.
            'position' => ['type' => 'enum', 'options' => ['', 'static', 'absolute', 'fixed'], 'default' => ''],
            'pin' => ['type' => 'enum', 'options' => self::POSITIONS, 'default' => 'top-left'],
            // Tenths of a percent of the page width on both axes, so the layout scales with it (see site.css).
            'offset_x' => ['type' => 'int', 'min' => -1000, 'max' => 1000, 'unit' => '%', 'scale' => 10, 'default' => 0],
            'offset_y' => ['type' => 'int', 'min' => -1000, 'max' => 1000, 'unit' => '%', 'scale' => 10, 'default' => 0],
            'z_index' => ['type' => 'enum', 'options' => ['', 'behind', '1', '2', '3', '4', '5'], 'default' => ''],
            'opacity' => ['type' => 'enum', 'options' => ['', '0', '10', '25', '50', '75', '90'], 'default' => ''],
            'hide_on' => ['type' => 'set', 'options' => ['mobile', 'tablet', 'desktop'], 'default' => []],
            // Show only when entry fields match; see ElementConditions.
            'visible_if' => ['type' => 'conditions', 'default' => [], 'description' => 'Show the element only when conditions on bound fields hold: {match: "all"|"any", conditions: [{field: <reference>, op: "equals"|"not_equals"|"contains"|"empty"|"not_empty", value: <scalar>}]}. A field that cannot be read counts as empty. Elements whose conditions fail are left out of the page.'],
            'class' => ['type' => 'class', 'default' => ''],
            'anchor' => ['type' => 'key', 'default' => ''],
        ];
    }

    /** Style keys each kind offers in the inspector. */
    public static function styleKeys(string $kind): array
    {
        return match ($kind) {
            'section' => [...self::SPACING_KEYS, 'background', 'background_gradient', ...self::BACKGROUND_IMAGE_KEYS, 'text_color', 'link_color', 'align', ...self::BORDER_KEYS, 'radius', 'shadow', ...self::ANIMATION_KEYS, 'z_index', 'opacity', 'class', 'anchor', 'hide_on', 'visible_if'],
            'row' => [...self::SPACING_KEYS, 'background', 'background_gradient', ...self::BACKGROUND_IMAGE_KEYS, 'text_color', 'link_color', 'radius', 'shadow', ...self::BORDER_KEYS, ...self::ANIMATION_KEYS, 'z_index', 'opacity', 'class', 'anchor', 'hide_on', 'visible_if'],
            self::COMPONENT, self::PAGE_CONTENT, self::COMPONENT_AREA, self::AREA_CONTENT => [],
            self::MODAL => [...self::SPACING_KEYS, 'background', 'background_gradient', ...self::BACKGROUND_IMAGE_KEYS, 'text_color', 'link_color', 'align', ...self::BORDER_KEYS, 'radius', 'shadow', 'class'],
            self::SITE_CONTENT => [...self::SPACING_KEYS, 'background', ...self::BORDER_KEYS, 'radius', 'shadow', 'class'],
            'column' => [...self::SPACING_KEYS, 'background', 'background_gradient', ...self::BACKGROUND_IMAGE_KEYS, 'text_color', 'link_color', 'align', 'radius', 'shadow', ...self::BORDER_KEYS, ...self::POSITION_KEYS, ...self::ANIMATION_KEYS, 'z_index', 'opacity', 'class', 'hide_on', 'visible_if'],
            default => [...self::SPACING_KEYS, 'background', 'background_gradient', ...self::BACKGROUND_IMAGE_KEYS, 'text_color', ...($kind === 'button' ? [] : ['link_color']), 'align', 'font_size', 'font_weight', 'font_family', ...(in_array($kind, ['text', 'heading'], true) ? ['text_gradient', 'text_shadow'] : []), 'radius', 'shadow', ...self::BORDER_KEYS, 'max_width', ...self::POSITION_KEYS, 'z_index', 'opacity', ...self::ANIMATION_KEYS, 'class', 'anchor', 'hide_on', 'visible_if'],
        };
    }

    /** Block prop types that hold content, and so are translated. */
    private const BLOCK_CONTENT_TYPES = ['string', 'text', 'richtext', 'url', 'link', 'media', 'list'];

    /** What a bindable block prop of each type is bound to (see SmartValue::PROPERTIES). */
    private const BLOCK_BINDINGS = ['string' => 'text', 'richtext' => 'html', 'media' => 'media', 'link' => 'link'];

    /** Props that hold per-language values. Content props of custom blocks are translatable. */
    public static function localizedProps(string $kind, ?BlockRegistry $blocks = null): array
    {
        if (!BlockRegistry::isKind($kind)) {
            return self::LOCALIZED_PROPS[$kind] ?? [];
        }
        $props = ($blocks ?? BlockRegistry::active())->get($kind)['props'] ?? [];

        return array_keys(array_filter($props, static fn(array $spec): bool => in_array($spec['type'], self::BLOCK_CONTENT_TYPES, true)));
    }

    /** Props that can be bound to content fields, and what they bind to. */
    public static function bindings(string $kind, ?BlockRegistry $blocks = null): array
    {
        if (!BlockRegistry::isKind($kind)) {
            return SmartValue::PROPERTIES[$kind] ?? [];
        }
        $props = ($blocks ?? BlockRegistry::active())->get($kind)['props'] ?? [];

        return array_filter(array_map(static fn(array $spec): ?string => self::BLOCK_BINDINGS[$spec['type']] ?? null, $props));
    }

    /** A block prop in the vocabulary of built-in props, which supplies its editor and normalization. */
    private static function blockProp(array $prop): array
    {
        return match ($prop['type']) {
            'richtext' => ['type' => 'html'] + $prop,
            'color' => ['options' => ['', ...Theme::COLOR_KEYS]] + $prop,
            'list' => ['fields' => array_map(self::blockProp(...), $prop['fields'])] + $prop,
            default => $prop,
        };
    }

    public static function propSpec(string $kind, ?BlockRegistry $blocks = null): array
    {
        if (BlockRegistry::isKind($kind)) {
            return array_map(self::blockProp(...), ($blocks ?? BlockRegistry::active())->get($kind)['props'] ?? []);
        }
        $ratio = ['type' => 'enum', 'options' => ['', '1:1', '4:3', '3:2', '16:9', '21:9', '3:4'], 'default' => ''];
        $fit = ['type' => 'enum', 'options' => ['cover', 'contain'], 'default' => 'cover'];
        $position = ['type' => 'enum', 'options' => self::POSITIONS, 'default' => 'center'];

        return match ($kind) {
            'section' => [
                'width' => ['type' => 'enum', 'options' => ['contained', 'narrow', 'full'], 'default' => 'contained'],
                'min_height' => ['type' => 'enum', 'options' => ['', 'quarter', 'half', 'three-quarter', 'screen'], 'default' => ''],
                'vertical_align' => ['type' => 'enum', 'options' => ['start', 'center', 'end'], 'default' => 'start'],
                // Always stays at the top; reveal hides it while scrolling down and brings it back on the way up.
                'sticky' => ['type' => 'enum', 'options' => ['none', 'always', 'reveal'], 'default' => 'none'],
                // Takes no room: the content below starts underneath, as behind a transparent header.
                'overlap' => ['type' => 'bool', 'default' => false],
                // A floating section is transparent at the top of the page, or keeps its background throughout.
                'overlap_background' => ['type' => 'enum', 'options' => ['transparent', 'background'], 'default' => 'transparent'],
                // How a sticky or floating section changes once the page is scrolled.
                'scroll_effect' => ['type' => 'set', 'options' => ['shadow', 'glass', 'compact'], 'default' => []],
                // Shapes along the top and bottom edge, usually in the color of the neighboring section (see Shapes).
                ...self::dividerProps('top'),
                ...self::dividerProps('bottom'),
            ],
            'row' => [
                'gap' => ['type' => 'enum', 'options' => ['none', 'xs', 'sm', 'md', 'lg', 'xl'], 'default' => 'md'],
                'align' => ['type' => 'enum', 'options' => ['stretch', 'start', 'center', 'end'], 'default' => 'stretch'],
                'justify' => ['type' => 'enum', 'options' => ['start', 'center', 'end', 'between'], 'default' => 'start'],
                'reverse_mobile' => ['type' => 'bool', 'default' => false],
                'reverse_tablet' => ['type' => 'bool', 'default' => false],
            ],
            'column' => [
                'span' => ['type' => 'spans', 'default' => ['mobile' => 12, 'tablet' => 0, 'desktop' => 0]],
                // Elements underneath each other, or side by side (wrapping when they run out of room).
                'direction' => ['type' => 'enum', 'options' => ['column', 'row'], 'default' => 'column'],
                // Space between the column's elements; empty keeps the theme's flow.
                'spacing' => ['type' => 'enum', 'options' => ['', 'none', 'xs', 'sm', 'md', 'lg', 'xl'], 'default' => ''],
                'vertical_align' => ['type' => 'enum', 'options' => ['start', 'center', 'end'], 'default' => 'start'],
                'content_justify' => ['type' => 'enum', 'options' => ['start', 'center', 'end', 'between'], 'default' => 'start'],
                'content_align' => ['type' => 'enum', 'options' => ['start', 'center', 'end', 'stretch'], 'default' => 'center'],
                // Makes the whole column a link; links inside it stay clickable on their own.
                'link' => ['type' => 'link', 'default' => ['type' => 'none']],
            ],
            // Only a column component's instances use their own span.
            self::COMPONENT => [
                'component' => ['type' => 'key', 'default' => ''],
                'root' => ['type' => 'enum', 'options' => self::STRUCTURE, 'default' => 'section'],
                'span' => ['type' => 'spans', 'default' => ['mobile' => 12, 'tablet' => 0, 'desktop' => 0]],
            ],
            self::PAGE_CONTENT => [
                'field' => ['type' => 'page_field', 'default' => ''],
            ],
            self::AREA_CONTENT => [
                'area' => ['type' => 'key', 'default' => ''],
            ],
            self::MODAL => [
                'size' => ['type' => 'enum', 'options' => ['sm', 'md', 'lg', 'xl', 'full'], 'default' => 'lg'],
                // Centered, or a panel along one edge of the screen (top and bottom span its width, left and right its height).
                'placement' => ['type' => 'enum', 'options' => ['center', 'top', 'bottom', 'left', 'right'], 'default' => 'center'],
                // As tall as its content (scrolling beyond the screen), or the full screen height.
                'height' => ['type' => 'enum', 'options' => ['auto', 'full'], 'default' => 'auto'],
                'backdrop' => ['type' => 'enum', 'options' => ['dark', 'blurred', 'light', 'none'], 'default' => 'dark'],
                'backdrop_close' => ['type' => 'bool', 'default' => true],
            ],
            // Shows the link's text while the modal is open.
            'modal_title' => [
                'level' => ['type' => 'enum', 'options' => ['h2', 'h3', 'h4', 'p'], 'default' => 'h2'],
            ],
            // An empty label uses the visitor's language.
            'modal_close' => [
                'icon' => ['type' => 'icon', 'options' => Icons::names(), 'default' => 'mdi:close'],
                'size' => ['type' => 'int', 'min' => 12, 'max' => 96, 'unit' => 'px', 'default' => 24],
                'variant' => ['type' => 'enum', 'options' => ['plain', 'outline', 'filled'], 'default' => 'plain'],
                'show_label' => ['type' => 'bool', 'default' => false],
                'label' => ['type' => 'string', 'max' => 120, 'default' => ''],
            ],
            'field' => [
                'source' => ['type' => 'reference', 'default' => ''],
                'display' => ['type' => 'display', 'default' => ''],
                'tag' => ['type' => 'enum', 'options' => ['', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'div'], 'default' => ''],
                'label' => ['type' => 'bool', 'default' => false],
                'link' => ['type' => 'link', 'default' => ['type' => 'none']],
                'truncate' => ['type' => 'int', 'min' => 0, 'max' => 500, 'default' => 0],
                'date_format' => ['type' => 'enum', 'options' => ['date', 'long', 'datetime', 'time'], 'default' => 'date'],
                'ratio' => $ratio,
                'fit' => $fit,
                'position' => $position,
                'columns' => ['type' => 'int', 'min' => 1, 'max' => 6, 'default' => 1],
                'prefix' => ['type' => 'string', 'max' => 60, 'default' => ''],
                'suffix' => ['type' => 'string', 'max' => 60, 'default' => ''],
                'true_text' => ['type' => 'string', 'max' => 120, 'default' => ''],
                'false_text' => ['type' => 'string', 'max' => 120, 'default' => ''],
            ],
            'heading' => [
                'text' => ['type' => 'string', 'max' => 300, 'default' => 'Heading'],
                'level' => ['type' => 'enum', 'options' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'default' => 'h2'],
                'link' => ['type' => 'link', 'default' => ['type' => 'none']],
            ],
            'text' => [
                'html' => ['type' => 'html', 'default' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>'],
            ],
            // Builder-authored source, including embedded styles, is kept verbatim.
            'html' => [
                'html' => ['type' => 'raw_html', 'max' => 50000, 'default' => ''],
            ],
            'image' => [
                'alt' => ['type' => 'string', 'max' => 1000, 'default' => ''],
                'caption' => ['type' => 'string', 'max' => 2000, 'default' => ''],
                'media' => ['type' => 'media', 'default' => ''],
                'ratio' => $ratio,
                'fit' => $fit,
                'position' => $position,
                // Rendered width bounds in pixels; 0 leaves that side to the image and its column.
                'min_width' => ['type' => 'int', 'min' => 0, 'max' => self::MAX_IMAGE_WIDTH, 'slider_max' => 1000, 'unit' => 'px', 'default' => 0],
                'max_width' => ['type' => 'int', 'min' => 0, 'max' => self::MAX_IMAGE_WIDTH, 'slider_max' => 1000, 'unit' => 'px', 'default' => 0],
                'link' => ['type' => 'link', 'default' => ['type' => 'none']],
            ],
            'video' => [
                // A library video takes precedence over the URL; the poster only applies to file playback.
                'media' => ['type' => 'media', 'default' => ''],
                'poster' => ['type' => 'media', 'default' => ''],
                'video_url' => ['type' => 'string', 'max' => 2000, 'default' => ''],
                'title' => ['type' => 'string', 'max' => 300, 'default' => 'Video'],
                'ratio' => ['type' => 'enum', 'options' => ['16:9', '4:3', '1:1', '3:4', '9:16', '21:9'], 'default' => '16:9'],
                'autoplay' => ['type' => 'bool', 'default' => false],
                'muted' => ['type' => 'bool', 'default' => false],
                'loop' => ['type' => 'bool', 'default' => false],
                'controls' => ['type' => 'bool', 'default' => true],
                'fullscreen' => ['type' => 'bool', 'default' => true],
                'start_time' => ['type' => 'int', 'min' => 0, 'max' => 86400, 'unit' => 's', 'default' => 0],
            ],
            'gallery' => [
                'media' => ['type' => 'media_list', 'default' => []],
                'label' => ['type' => 'bool', 'default' => false],
                'masonry' => ['type' => 'bool', 'default' => false],
                'ratio' => ['default' => '16:9'] + $ratio,
                'fit' => $fit,
                'position' => $position,
                'columns' => ['type' => 'int', 'min' => 1, 'max' => 6, 'default' => 3],
                'gap' => ['type' => 'enum', 'options' => ['none', 'xs', 'sm', 'md', 'lg', 'xl'], 'default' => 'sm'],
                'captions' => ['type' => 'bool', 'default' => false],
                'lightbox' => ['type' => 'bool', 'default' => false],
                'lightbox_navigation' => ['type' => 'bool', 'default' => true],
                'link' => ['type' => 'link', 'default' => ['type' => 'none']],
            ],
            'button' => [
                'label' => ['type' => 'string', 'max' => 120, 'default' => 'Learn more'],
                'link' => ['type' => 'link', 'default' => ['type' => 'current']],
                'variant' => ['type' => 'enum', 'options' => ['primary', 'secondary', 'outline', 'link'], 'default' => 'primary'],
                'size' => ['type' => 'enum', 'options' => ['sm', 'md', 'lg'], 'default' => 'md'],
                'full_width' => ['type' => 'bool', 'default' => false],
                // Colors of the button itself, in place of its variant's.
                'button_color' => ['type' => 'color', 'options' => ['', ...Theme::COLOR_KEYS], 'default' => ''],
                'button_text_color' => ['type' => 'color', 'options' => ['', ...Theme::COLOR_KEYS, 'inverse'], 'default' => ''],
                'button_border_color' => ['type' => 'color', 'options' => ['', ...Theme::COLOR_KEYS], 'default' => ''],
                // An icon before or after the label, sized with its text.
                'icon' => ['type' => 'icon', 'options' => ['', ...Icons::names()], 'default' => ''],
                'icon_position' => ['type' => 'enum', 'options' => ['start', 'end'], 'default' => 'start'],
                'icon_gap' => ['type' => 'enum', 'options' => ['xs', 'sm', 'md', 'lg'], 'default' => 'sm'],
            ],
            'accordion' => [
                'items' => ['type' => 'accordion_items', 'default' => [[
                    'title' => 'Accordion title',
                    'html' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>',
                    'open' => false,
                ]]],
                'single_open' => ['type' => 'bool', 'default' => false],
            ],
            'table' => [
                'rows' => ['type' => 'table_rows', 'default' => [['Name', 'Value'], ['Item', 'Detail'], ['Item', 'Detail']],
                    'description' => 'Cells by row: [["Plan","Price"],["Basic","9 €"]]. Every row has the same number of cells (up to ' . self::TABLE_MAX_COLUMNS . ' columns, ' . self::TABLE_MAX_ROWS . ' rows). A cell is inline HTML (strong, em, a, br, code …) or a binding.'],
                'header_row' => ['type' => 'bool', 'default' => true],
                'header_column' => ['type' => 'bool', 'default' => false],
                'caption' => ['type' => 'string', 'max' => 300, 'default' => ''],
                'variant' => ['type' => 'enum', 'options' => ['lines', 'grid', 'minimal'], 'default' => 'lines'],
                'striped' => ['type' => 'bool', 'default' => false],
                'compact' => ['type' => 'bool', 'default' => false],
                // Small screens scroll a wide table sideways, or show each row as a card labelled by the header row.
                'mobile' => ['type' => 'enum', 'options' => ['scroll', 'stack'], 'default' => 'scroll'],
            ],
            'tabs' => [
                'title' => ['type' => 'string', 'max' => 300, 'default' => 'Tabs'],
                'initial_tab' => ['type' => 'int', 'min' => 1, 'max' => self::MAX_NODES, 'default' => 1],
            ],
            'tab' => [
                'title' => ['type' => 'string', 'max' => 300, 'default' => 'Tab'],
            ],
            'icon' => [
                'icon' => ['type' => 'icon', 'options' => Icons::names(), 'default' => 'mdi:star-outline'],
                'size' => ['type' => 'int', 'min' => 12, 'max' => 240, 'unit' => 'px', 'default' => 48],
                'shape' => ['type' => 'enum', 'options' => ['none', 'circle', 'square', 'squircle'], 'default' => 'none'],
                'shape_color' => ['type' => 'color', 'options' => Theme::COLOR_KEYS, 'default' => 'surface'],
                'shape_padding' => ['type' => 'int', 'min' => 0, 'max' => 120, 'unit' => 'px', 'default' => 16],
                'label' => ['type' => 'string', 'max' => 120, 'default' => ''],
                'link' => ['type' => 'link', 'default' => ['type' => 'none']],
            ],
            'spacer' => [
                'size' => ['type' => 'enum', 'options' => ['xs', 'sm', 'md', 'lg', 'xl', '2xl'], 'default' => 'md'],
            ],
            'divider' => [],
            'collection' => [
                // 'types' lists the entries of several types together; 'search' the hits of the query
                // the search results page was opened with. Both take their types from 'collections'.
                'list_source' => ['type' => 'enum', 'options' => ['type', 'types', 'search'], 'default' => 'type'],
                'type' => ['type' => 'content_type', 'default' => ''],
                'collections' => ['type' => 'content_types', 'default' => []],
                'search_fields' => ['type' => 'search_fields', 'default' => []],
                'columns' => ['type' => 'int', 'min' => 1, 'max' => 6, 'default' => 3],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 100, 'default' => 6],
                'sort_field' => ['type' => 'sort_field', 'default' => 'published_at'],
                'sort_order' => ['type' => 'enum', 'options' => ['desc', 'asc'], 'default' => 'desc'],
                'filter_field' => ['type' => 'key', 'default' => ''],
                'filter_value' => ['type' => 'string', 'max' => 200, 'default' => ''],
                'exclude_current' => ['type' => 'bool', 'default' => true],
                // Controls visitors filter and sort the list with (see ListFilters).
                'filters' => ['type' => 'list_filters', 'default' => []],
                'sort_choices' => ['type' => 'sort_fields', 'default' => []],
                'paginate' => ['type' => 'bool', 'default' => false],
                'empty_text' => ['type' => 'string', 'max' => 300, 'default' => ''],
            ],
            // A content type's form; retain form IDs for existing placements (see FormRepository).
            'form' => [
                'form' => ['type' => 'form', 'default' => ''],
                'labels' => ['type' => 'enum', 'options' => ['above', 'hidden'], 'default' => 'above'],
                'button_variant' => ['type' => 'enum', 'options' => ['primary', 'secondary', 'outline'], 'default' => 'primary'],
                'button_full_width' => ['type' => 'bool', 'default' => false],
            ],
            'menu' => [
                'menu' => ['type' => 'menu', 'default' => 'main'],
                'always_button' => ['type' => 'bool', 'default' => false],
                'orientation' => ['type' => 'enum', 'options' => ['horizontal', 'vertical'], 'default' => 'horizontal'],
                'collapse_mobile' => ['type' => 'bool', 'default' => true],
                // How hovered links and the current page stand out; an empty hover color uses the text or highlight color.
                'hover_effect' => ['type' => 'enum', 'options' => ['background', 'color', 'underline', 'overline', 'grow'], 'default' => 'background'],
                'hover_color' => ['type' => 'color', 'options' => ['', ...Theme::COLOR_KEYS, 'inverse'], 'default' => ''],
                'active_style' => ['type' => 'enum', 'options' => ['bold', 'effect', 'color'], 'default' => 'bold'],
                // How the menu button shows the list.
                'panel' => ['type' => 'enum', 'options' => ['dropdown', 'fullscreen', 'drawer'], 'default' => 'dropdown'],
                'panel_side' => ['type' => 'enum', 'options' => ['left', 'right'], 'default' => 'right'],
                'panel_background' => ['type' => 'color', 'options' => ['', ...Theme::COLOR_KEYS], 'default' => ''],
                'panel_text_color' => ['type' => 'color', 'options' => ['', ...Theme::COLOR_KEYS, 'inverse'], 'default' => ''],
            ],
            // Empty texts use the visitor's language; empty lists search every page type by its text fields.
            'search' => [
                'placeholder' => ['type' => 'string', 'max' => 120, 'default' => ''],
                'collections' => ['type' => 'content_types', 'default' => []],
                'fields' => ['type' => 'search_fields', 'default' => []],
                'max_results' => ['type' => 'int', 'min' => 1, 'max' => SiteSearch::MAX_RESULTS, 'default' => SiteSearch::MAX_RESULTS],
                'min_chars' => ['type' => 'int', 'min' => SiteSearch::MIN_QUERY_LENGTH, 'max' => 5, 'default' => 2],
                'icon_position' => ['type' => 'enum', 'options' => ['left', 'right', 'none'], 'default' => 'left'],
                'icon' => ['type' => 'icon', 'options' => Icons::names(), 'default' => 'mdi:magnify'],
                'variant' => ['type' => 'enum', 'options' => ['outline', 'filled', 'underline'], 'default' => 'outline'],
                'size' => ['type' => 'enum', 'options' => ['sm', 'md', 'lg'], 'default' => 'md'],
                'show_type' => ['type' => 'bool', 'default' => true],
                'no_results_text' => ['type' => 'string', 'max' => 200, 'default' => ''],
                // Enter opens the search results page instead of the best hit.
                'results_page' => ['type' => 'bool', 'default' => false],
            ],
            'site_title' => [
                'show_logo' => ['type' => 'bool', 'default' => true],
                'show_name' => ['type' => 'bool', 'default' => true],
                'show_tagline' => ['type' => 'bool', 'default' => false],
            ],
            'language_switcher' => [
                // Flags beside the text, or instead of it.
                'flag' => ['type' => 'enum', 'options' => ['none', 'with_text', 'only'], 'default' => 'none'],
                'format' => ['type' => 'enum', 'options' => ['codes', 'names'], 'default' => 'codes'],
            ],
            // Each icon shows while its mode is on; an empty label uses the visitor's language.
            'theme_toggle' => [
                'light_icon' => ['type' => 'icon', 'options' => Icons::names(), 'default' => 'mdi:white-balance-sunny'],
                'dark_icon' => ['type' => 'icon', 'options' => Icons::names(), 'default' => 'mdi:weather-night'],
                'size' => ['type' => 'int', 'min' => 12, 'max' => 96, 'unit' => 'px', 'default' => 20],
                'variant' => ['type' => 'enum', 'options' => ['plain', 'outline', 'filled'], 'default' => 'plain'],
                'show_label' => ['type' => 'bool', 'default' => false],
                'label' => ['type' => 'string', 'max' => 120, 'default' => ''],
            ],
            default => [],
        };
    }

    /** A section's divider along one edge; width stretches or squashes it sideways, flip mirrors it, invert fills the other side of its line. */
    private static function dividerProps(string $edge): array
    {
        return [
            'divider_' . $edge => ['type' => 'enum', 'options' => Shapes::dividers(), 'default' => 'none', 'picker' => 'divider', 'edge' => $edge],
            'divider_' . $edge . '_color' => ['type' => 'color', 'options' => Theme::COLOR_KEYS, 'default' => 'background'],
            'divider_' . $edge . '_height' => ['type' => 'int', 'min' => 10, 'max' => 500, 'slider_max' => 300, 'unit' => 'px', 'default' => 80],
            'divider_' . $edge . '_width' => ['type' => 'int', 'min' => 20, 'max' => 400, 'unit' => '%', 'default' => 100],
            'divider_' . $edge . '_flip' => ['type' => 'bool', 'default' => false],
            'divider_' . $edge . '_invert' => ['type' => 'bool', 'default' => false],
        ];
    }

    /** Kinds a node of $parent may contain; "root" is the layout itself. */
    public static function allowedChildren(string $parent, ?BlockRegistry $blocks = null): array
    {
        $custom = in_array($parent, ['column', 'tab', 'field', 'slot', 'item-slot', self::MODAL, self::AREA_CONTENT], true) ? array_keys(($blocks ?? BlockRegistry::active())->all()) : [];
        return match ($parent) {
            'root' => ['section'],
            'section' => ['row'],
            'row' => ['column'],
            'column', 'tab' => [...self::ELEMENTS, ...$custom, 'row'],
            'tabs' => ['tab'],
            'field' => [...self::ITEM_ELEMENTS, ...$custom],
            // Inside a block's <slot>, outside and inside a repeater item.
            'slot' => [...self::ELEMENTS, ...$custom],
            'item-slot' => [...self::ITEM_ELEMENTS, ...$custom],
            // The card a collection repeats for each entry.
            'collection' => ['column'],
            self::PAGE_CONTENT => ['section'],
            self::MODAL => [...self::ELEMENTS, ...$custom, 'row'],
            // An instance holds what it put into each content area, which holds what a column does.
            self::COMPONENT => [self::AREA_CONTENT],
            self::AREA_CONTENT => [...self::ELEMENTS, ...$custom, 'row'],
            // The top of a component's own tree holds just its root kind; the modal template's is its panel.
            'region:' . self::MODAL => [self::MODAL],
            'component:section' => ['section'],
            'component:row' => ['row'],
            'component:column' => ['column'],
            default => [],
        };
    }

    /**
     * Everything the admin builder needs to draw palettes and inspectors.
     * With $pageContent (detail layouts) the page content place is offered;
     * $modal describes the modal template, a single panel with its own elements;
     * $component a component's tree, which offers content areas.
     */
    public static function spec(bool $pageContent = false, bool $modal = false, bool $component = false, ?BlockRegistry $blocks = null): array
    {
        $blocks ??= BlockRegistry::active();
        $custom = array_keys($blocks->all());
        $kinds = [];

        foreach ([...self::STRUCTURE, ...self::ELEMENTS, ...$custom, 'tab', self::COMPONENT, self::PAGE_CONTENT, self::COMPONENT_AREA, self::AREA_CONTENT, self::MODAL, ...self::MODAL_ELEMENTS] as $kind) {
            $props = self::propSpec($kind, $blocks);
            foreach (self::localizedProps($kind, $blocks) as $key) $props[$key]['localized'] = true;
            foreach (self::bindings($kind, $blocks) as $key => $binding) {
                $props[$key]['binding'] = $binding;
            }
            $kinds[$kind] = [
                'props' => $props,
                'style' => self::styleKeys($kind),
                'children' => self::childrenFor(($blocks->get($kind)['slot'] ?? false) ? 'slot' : $kind, $pageContent, $modal, $component, $blocks),
                'responsive' => ['props' => self::RESPONSIVE_PROPS[$kind] ?? [], 'style' => array_values(array_intersect(self::RESPONSIVE_STYLE, self::styleKeys($kind)))],
                'states' => [
                    'props' => self::STATE_PROPS[$kind] ?? [],
                    'style' => array_values(array_intersect(self::STATE_STYLE, self::styleKeys($kind))),
                    'variants' => ['hover' => ['style' => array_values(array_intersect(self::HOVER_ANIMATION_KEYS, self::styleKeys($kind)))]],
                ],
                ...($blocks->get($kind) !== null ? [
                    'label' => $blocks->get($kind)['title'],
                    'description' => $blocks->get($kind)['description'],
                    'icon' => $blocks->get($kind)['icon'],
                    'slot' => $blocks->get($kind)['slot'],
                    'script' => $blocks->get($kind)['script'] !== '',
                    'extension' => true,
                ] : []),
            ];
        }

        $style = self::styleSpec();
        foreach (SmartValue::STYLE_PROPERTIES as $key => $binding) {
            $style[$key]['binding'] = $binding;
        }

        return [
            'format_version' => self::FORMAT_VERSION,
            'bindings' => ['sources' => SmartValue::SOURCES, 'date_formats' => SmartValue::DATE_FORMATS, 'properties' => SmartValue::PROPERTIES + array_filter(array_combine($custom, array_map(static fn(string $kind): array => self::bindings($kind, $blocks), $custom))), 'style_properties' => SmartValue::STYLE_PROPERTIES],
            'kinds' => $kinds,
            'root' => $modal ? self::allowedChildren('region:' . self::MODAL, $blocks) : self::childrenFor('root', $pageContent, false, $component, $blocks),
            'style' => $style,
            'elements' => [...self::ELEMENTS, ...$custom, ...($pageContent ? [self::PAGE_CONTENT] : []), ...($component ? [self::COMPONENT_AREA] : []), ...($modal ? self::MODAL_ELEMENTS : [])],
            'item_elements' => [...self::ITEM_ELEMENTS, ...$custom],
            'field_displays' => self::FIELD_DISPLAYS,
            'system_fields' => self::SYSTEM_FIELDS,
            'variants' => self::VARIANTS,
            'max_row_depth' => self::MAX_ROW_DEPTH,
            'max_node_name_length' => self::MAX_NODE_NAME_LENGTH,
            'regions' => self::REGIONS,
            'special_pages' => self::SPECIAL_PAGES,
            // The card a list of search results starts its own from: only fields every entry has.
            'search_card' => self::withNewIds(self::defaultCardTemplate([])),
            'link_types' => self::LINK_TYPES,
            'shapes' => Shapes::spec(),
            'devices' => array_keys(StyleVariants::DEVICES),
            'states' => StyleVariants::STATES,
        ];
    }

    /**
     * Validate a whole layout. Unknown kinds, misplaced nodes, props and style
     * values are dropped rather than rejected so an older or hand-edited
     * layout keeps rendering what is still valid.
     */
    public static function normalize(mixed $nodes, bool $pageContent = false, ?BlockRegistry $blocks = null): array
    {
        $count = 0;
        $seen = [];
        $places = $pageContent ? [] : null;

        return self::normalizeNodes(is_array($nodes) ? $nodes : [], 'root', $count, $seen, false, 0, $blocks ?? BlockRegistry::active(), $places);
    }

    /**
     * A region document: sections, or the modal template's single panel.
     * Saving ($requireContent) also needs the modal's one site content element.
     */
    public static function normalizeRegion(string $region, mixed $nodes, bool $requireContent = false, ?BlockRegistry $blocks = null): array
    {
        if ($region !== self::MODAL) {
            return self::normalize($nodes, blocks: $blocks);
        }

        $count = 0;
        $seen = [];
        $nodes = array_slice(self::normalizeNodes(is_array($nodes) ? $nodes : [], 'region:' . self::MODAL, $count, $seen, false, 0, $blocks ?? BlockRegistry::active()), 0, 1);
        if ($requireContent && self::countKind($nodes, self::SITE_CONTENT) !== 1) {
            throw new ValidationException(['nodes' => 'The modal template must contain exactly one Linked page element.']);
        }

        return $nodes;
    }

    private static function countKind(array $nodes, string $kind): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            $count += ($node['kind'] === $kind ? 1 : 0) + self::countKind($node['children'], $kind);
        }

        return $count;
    }

    /** Kinds a node may contain, with the page content place, content areas and modal elements where the document offers them. */
    public static function childrenFor(string $parent, bool $pageContent, bool $inModal, bool $areas = false, ?BlockRegistry $blocks = null): array
    {
        $children = self::allowedChildren($parent, $blocks);
        if ($pageContent && in_array($parent, ['root', 'column', 'tab'], true)) {
            $children[] = self::PAGE_CONTENT;
        }
        if ($areas && in_array($parent, ['column', 'tab'], true)) {
            $children[] = self::COMPONENT_AREA;
        }
        if ($inModal && in_array($parent, [self::MODAL, 'column', 'tab'], true)) {
            array_push($children, ...self::MODAL_ELEMENTS);
        }

        return $children;
    }

    /**
     * An entry's page content as stored in its page field: sections, with
     * the format they were saved in. Content saved in another format stays
     * as it is (see pageNodes()) instead of being dropped.
     */
    public static function pageValue(mixed $nodes): array
    {
        return ['format_version' => self::FORMAT_VERSION, 'nodes' => self::normalize($nodes)];
    }

    /** The sections of a stored page field value; empty when the entry has none. */
    public static function pageNodes(mixed $value): array
    {
        return is_array($value) && is_array($value['nodes'] ?? null) ? self::normalize($value['nodes']) : [];
    }

    /** Whether a stored page field value holds content of the entry's own. */
    public static function hasPageContent(mixed $value): bool
    {
        return is_array($value) && is_array($value['nodes'] ?? null) && $value['nodes'] !== [];
    }

    /** Whether a stored page field value was saved in an older format and needs saving again. */
    public static function pageNeedsUpgrade(mixed $value): bool
    {
        return self::hasPageContent($value) && ($value['format_version'] ?? null) !== self::FORMAT_VERSION;
    }

    /** The page content places of a detail layout: field => node. */
    public static function pagePlaces(array $nodes): array
    {
        $places = [];
        foreach ($nodes as $node) {
            if (($node['kind'] ?? '') === self::PAGE_CONTENT) {
                $field = (string) ($node['props']['field'] ?? '');
                if ($field !== '' && !isset($places[$field])) $places[$field] = $node;
                continue;
            }
            $places += self::pagePlaces(is_array($node['children'] ?? null) ? $node['children'] : []);
        }

        return $places;
    }

    /**
     * The words visitors read in a tree, for search and descriptions:
     * literal texts in $locale. Texts bound to fields are left out; the
     * fields are read on their own.
     */
    public static function text(array $nodes, string $locale, string $defaultLocale): string
    {
        $parts = [];
        $visit = static function (array $nodes) use (&$visit, &$parts, $locale, $defaultLocale): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $props = LocalizedValue::tree(is_array($node['props'] ?? null) ? $node['props'] : [], $locale, $defaultLocale);
                foreach (['text', 'html', 'label', 'title', 'alt', 'caption'] as $key) {
                    $value = $props[$key] ?? null;
                    if (is_string($value) && $value !== '' && in_array($key, self::LOCALIZED_PROPS[$node['kind'] ?? ''] ?? [], true)) {
                        // Search indexes visible words, excluding embedded CSS and scripts.
                        if (($node['kind'] ?? '') === 'html') $value = HtmlSanitizer::sanitize($value);
                        $parts[] = $key === 'html' ? html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], ' ', $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
                    }
                }
                if (($node['kind'] ?? '') === 'table' && is_array($props['rows'] ?? null)) {
                    foreach ($props['rows'] as $row) foreach (is_array($row) ? $row : [] as $cell) {
                        if (is_string($cell) && $cell !== '') $parts[] = html_entity_decode(strip_tags(str_replace('<br>', ' ', $cell)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
                $visit(is_array($node['children'] ?? null) ? $node['children'] : []);
            }
        };
        $visit($nodes);

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /** The kind a node is placed as: a component instance counts as its root kind. */
    public static function placementKind(array $node): string
    {
        $kind = (string) ($node['kind'] ?? '');
        if ($kind !== self::COMPONENT) {
            return $kind;
        }
        $root = (string) ($node['props']['root'] ?? '');

        return in_array($root, self::STRUCTURE, true) ? $root : '';
    }

    /** Whether any bound value in the tree reads from $source (e.g. "current"). */
    public static function usesSource(array $nodes, string $source): bool
    {
        $scan = static function (mixed $value) use (&$scan, $source): bool {
            if (!is_array($value)) {
                return false;
            }
            if (($value['type'] ?? null) === 'reference' && ($value['source'] ?? null) === $source) {
                return true;
            }
            foreach ($value as $item) {
                if ($scan($item)) {
                    return true;
                }
            }
            return false;
        };

        foreach ($nodes as $node) {
            if ($scan($node['props'] ?? []) || $scan($node['style'] ?? []) || self::usesSource($node['children'] ?? [], $source)) {
                return true;
            }
        }

        return false;
    }

    /** Ids of the components placed anywhere in the tree. */
    public static function componentRefs(array $nodes): array
    {
        $refs = [];

        foreach ($nodes as $node) {
            if (($node['kind'] ?? '') === self::COMPONENT && ($node['props']['component'] ?? '') !== '') {
                $refs[(string) $node['props']['component']] = true;
            }
            foreach (self::componentRefs($node['children'] ?? []) as $ref) {
                $refs[$ref] = true;
            }
        }

        return array_keys($refs);
    }

    /** Validate a component's tree: its first node of kind $root, nothing else. */
    public static function normalizeComponent(mixed $nodes, string $root, ?BlockRegistry $blocks = null): array
    {
        $count = 0;
        $seen = [];
        $first = array_values(array_filter(is_array($nodes) ? $nodes : [], static fn(mixed $node): bool => is_array($node) && ($node['kind'] ?? '') === $root));

        $places = null;

        return self::normalizeNodes(array_slice($first, 0, 1), 'component:' . $root, $count, $seen, false, 0, $blocks ?? BlockRegistry::active(), $places, areas: true);
    }

    /** The content areas of a component's tree in order: [{id, name}], name '' when it has none. */
    public static function componentAreas(array $nodes): array
    {
        $areas = [];
        foreach ($nodes as $node) {
            if (($node['kind'] ?? '') === self::COMPONENT_AREA) {
                $areas[] = ['id' => (string) $node['id'], 'name' => (string) ($node['name'] ?? '')];
                continue;
            }
            array_push($areas, ...self::componentAreas($node['children'] ?? []));
        }

        return $areas;
    }

    /**
     * An instance as an ordinary copy of its component's $tree: with the
     * instance's own values, and what it put into each content area in place
     * of the area. The copy keeps the instance's id, name and column width;
     * the area content keeps its ids, the component's nodes get new ones.
     */
    public static function detachInstance(array $instance, array $tree): array
    {
        $fills = [];
        foreach ($instance['children'] ?? [] as $child) {
            if (($child['kind'] ?? '') === self::AREA_CONTENT) $fills[(string) ($child['props']['area'] ?? '')] ??= $child['children'] ?? [];
        }
        $copy = self::detachedNodes(self::applyOverrides([$tree], $instance['overrides'] ?? []), $fills)[0];
        $copy['id'] = $instance['id'];
        if (isset($instance['name'])) {
            $copy['name'] = $instance['name'];
        }
        if ($copy['kind'] === 'column') {
            $copy['props']['span'] = $instance['props']['span'] ?? $copy['props']['span'];
        }

        return $copy;
    }

    private static function detachedNodes(array $nodes, array $fills): array
    {
        $copies = [];
        foreach ($nodes as $node) {
            if (($node['kind'] ?? '') === self::COMPONENT_AREA) {
                array_push($copies, ...($fills[$node['id']] ?? []));
                continue;
            }
            $node['id'] = self::newId();
            unset($node['editable']);
            $node['children'] = self::detachedNodes($node['children'] ?? [], $fills);
            $copies[] = $node;
        }

        return $copies;
    }

    /**
     * An instance's own values for props its component marks editable:
     * {inner node id: {prop: value}}. Only their shape is checked here; the
     * values are checked against the inner node when they are applied, as
     * the component may have changed since.
     */
    private static function instanceOverrides(mixed $value): array
    {
        $normalized = [];
        foreach (array_slice(is_array($value) ? $value : [], 0, self::MAX_NODES, true) as $id => $props) {
            $id = self::key($id);
            if ($id === '' || !is_array($props)) {
                continue;
            }
            foreach (array_slice($props, 0, 50, true) as $key => $item) {
                $key = self::key($key);
                if ($key !== '' && $item !== null) {
                    $normalized[$id][$key] = $item;
                }
            }
        }

        return $normalized;
    }

    /**
     * A component's tree as one instance shows it: each of its own values
     * replaces the value of a prop the component marks editable. Values for
     * props that are no longer editable are kept on the instance but ignored.
     */
    public static function applyOverrides(array $nodes, array $overrides): array
    {
        if ($overrides === []) {
            return $nodes;
        }

        foreach ($nodes as &$node) {
            $values = $overrides[$node['id'] ?? ''] ?? [];
            $own = array_intersect_key($values, array_flip($node['editable'] ?? []));
            if ($own !== [] && ($node['kind'] ?? '') !== self::COMPONENT) {
                $node['props'] = self::normalizeProps((string) $node['kind'], array_replace($node['props'] ?? [], $own));
            }
            $node['children'] = self::applyOverrides($node['children'] ?? [], $overrides);
        }

        return $nodes;
    }

    /** A copy of $nodes with fresh ids throughout. */
    public static function withNewIds(array $nodes): array
    {
        return array_map(static fn(array $node): array => ['id' => self::newId(), 'children' => self::withNewIds($node['children'] ?? [])] + $node, $nodes);
    }

    public static function newId(): string
    {
        return 'n' . bin2hex(random_bytes(5));
    }

    public static function node(string $kind, array $props = [], array $style = [], array $children = []): array
    {
        $defaults = [];

        foreach (self::propSpec($kind) as $key => $spec) {
            $defaults[$key] = $spec['default'];
        }

        return [
            'id' => self::newId(),
            'kind' => $kind,
            'props' => array_replace($defaults, $props),
            'style' => $style,
            'children' => $children,
        ];
    }

    public static function defaultDisplay(string $fieldType): string
    {
        return self::FIELD_DISPLAYS[$fieldType][0] ?? 'text';
    }

    /** A sensible starting layout built from a content type's fields. */
    public static function defaultLayout(array $type, string $variant): array
    {
        $nodes = self::bindDefaultNodes(self::buildDefaultLayout($type, $variant), self::defaultFields($type));
        // Entries of a type with a page field design their page; the generated layout is where they start.
        $field = $variant === 'detail' ? self::firstFieldOfType(is_array($type['fields'] ?? null) ? $type['fields'] : [], ['page']) : null;
        if ($field !== null) {
            $nodes = [self::node(self::PAGE_CONTENT, ['field' => $field], [], $nodes)];
        }

        return self::withDefaultIds($nodes, 'layout:' . ($type['name'] ?? '') . ':' . $variant);
    }

    /** Generated defaults must keep their revision hash across reads and requests. */
    private static function withDefaultIds(array $nodes, string $path): array
    {
        foreach ($nodes as $index => &$node) {
            $nodePath = $path . ':' . $index;
            $node['id'] = 'n' . substr(hash('sha256', $nodePath), 0, 10);
            $node['children'] = self::withDefaultIds($node['children'] ?? [], $nodePath);
        }

        return $nodes;
    }

    /** The standard card of a content type: one column, as a collection repeats it. */
    public static function defaultCardTemplate(array $type): array
    {
        return self::bindDefaultNodes(self::buildDefaultLayout($type, 'card'), self::defaultFields($type));
    }

    private static function defaultFields(array $type): array
    {
        $fields = ($type['fields'] ?? []) + array_fill_keys(self::SYSTEM_FIELDS, ['type' => 'datetime']);
        $fields['title'] = ['type' => 'text'];

        return $fields;
    }

    private static function buildDefaultLayout(array $type, string $variant): array
    {
        $fields = is_array($type['fields'] ?? null) ? $type['fields'] : [];
        $slugField = (string) ($type['slug_field'] ?? 'slug');

        if ($variant === 'index') {
            return [
                self::node('section', ['width' => 'contained'], ['padding_top' => 'xl', 'padding_bottom' => 'xl'], [
                    self::node('row', [], [], [
                        self::node('column', [], [], [
                            self::node('heading', ['text' => (string) ($type['label'] ?? $type['name'] ?? ''), 'level' => 'h1']),
                            self::node('collection', [
                                'type' => (string) ($type['name'] ?? ''),
                                'paginate' => true,
                                'limit' => 12,
                                'columns' => 3,
                            ]),
                        ]),
                    ]),
                ]),
            ];
        }

        if ($variant === 'card') {
            $media = self::firstFieldOfType($fields, ['media']);
            $excerpt = self::firstFieldOfType($fields, ['textarea', 'markdown', 'html']);
            $top = [];

            if ($media !== null) {
                $top[] = self::node('row', ['gap' => 'none'], [], [
                    self::node('column', [], [], [
                        self::node('field', ['source' => SmartValue::reference($media), 'display' => 'image', 'ratio' => '16:9', 'link' => ['type' => 'current']], ['margin_bottom' => 'none']),
                    ]),
                ]);
            }

            $body = [
                self::node('field', ['source' => SmartValue::reference('title'), 'tag' => 'h3', 'link' => ['type' => 'current']], ['margin_bottom' => 'xs', 'font_size' => 'xl']),
                self::node('field', ['source' => SmartValue::reference('published_at'), 'display' => 'date'], ['text_color' => 'muted', 'font_size' => 'sm', 'margin_bottom' => 'sm']),
            ];

            if ($excerpt !== null) {
                $body[] = self::node('field', ['source' => SmartValue::reference($excerpt), 'display' => 'excerpt', 'truncate' => 28], ['margin_bottom' => 'none']);
            }

            $top[] = self::node('row', ['gap' => 'none'], [], [
                self::node('column', [], ['padding_top' => 'md', 'padding_bottom' => 'md', 'padding_left' => 'md', 'padding_right' => 'md'], $body),
            ]);

            return [
                self::node('column', [], ['border' => ['left', 'top', 'right', 'bottom'], 'radius' => 'theme', 'background' => 'background'], $top),
            ];
        }

        $elements = [
            self::node('field', ['source' => SmartValue::reference('title'), 'tag' => 'h1']),
            self::node('field', ['source' => SmartValue::reference('published_at'), 'display' => 'date', 'date_format' => 'long'], ['text_color' => 'muted', 'margin_bottom' => 'lg']),
        ];

        foreach ($fields as $name => $config) {
            $name = (string) $name;
            if (!is_array($config) || in_array($name, ['title', 'slug', $slugField], true)) {
                continue;
            }

            $fieldType = (string) ($config['type'] ?? 'text');
            if (in_array($fieldType, ['json', 'page'], true)) {
                continue;
            }

            $props = ['source' => SmartValue::reference($name), 'display' => self::defaultDisplay($fieldType)];
            if ($fieldType === 'media') {
                $props += ['ratio' => '16:9'];
            }
            if (in_array($fieldType, ['relation', 'repeater'], true)) {
                $props += ['label' => true, 'columns' => $fieldType === 'relation' ? 3 : 1];
            }
            if (in_array($fieldType, ['number', 'range', 'boolean', 'select', 'date', 'datetime', 'color'], true)) {
                $props += ['label' => true];
            }

            $children = [];
            if ($fieldType === 'repeater') {
                foreach (is_array($config['subfields'] ?? null) ? $config['subfields'] : [] as $subfield) {
                    $key = is_array($subfield) ? (string) ($subfield['key'] ?? '') : '';
                    if ($key !== '' && ($subfield['type'] ?? '') !== 'json') {
                        $subProps = ['source' => SmartValue::reference($key), 'display' => self::defaultDisplay((string) ($subfield['type'] ?? 'text'))];
                        // The first text subfield reads as the item's heading.
                        if ($children === [] && ($subfield['type'] ?? 'text') === 'text') {
                            $subProps['tag'] = 'h4';
                        }
                        $children[] = self::node('field', $subProps, ['margin_bottom' => 'xs']);
                    }
                }
            }

            $elements[] = self::node('field', $props, [], $children);
        }

        return [
            self::node('section', ['width' => 'narrow'], ['padding_top' => 'xl', 'padding_bottom' => 'xl'], [
                self::node('row', [], [], [self::node('column', [], [], $elements)]),
            ]),
        ];
    }

    private static function bindDefaultNodes(array $nodes, array $fields, bool $item = false): array
    {
        foreach ($nodes as &$node) {
            if ($node['kind'] !== 'field') {
                $node['children'] = self::bindDefaultNodes($node['children'], $fields, $item);
                continue;
            }
            $props = $node['props'];
            $key = $props['source']['path'][0] ?? '';
            $config = $fields[$key] ?? ['type' => 'text'];
            $source = SmartValue::reference($key, $item ? 'item' : 'current');
            if (!empty($props['truncate'])) $source['filters'][] = ['name' => 'truncate', 'value' => $props['truncate']];
            if (in_array($config['type'], ['date', 'datetime'], true)) $source['filters'][] = ['name' => 'date', 'value' => $props['date_format']];
            $simple = in_array($config['type'], ['text', 'slug', 'textarea', 'markdown', 'html', 'number', 'date', 'datetime'], true)
                || ($config['type'] === 'media' && empty($config['multiple']));
            if ($simple) {
                if ($config['type'] === 'media') {
                    $next = self::node('image', ['media' => $source, 'ratio' => $props['ratio'], 'fit' => $props['fit'], 'position' => $props['position']]);
                } elseif (str_starts_with($props['tag'], 'h')) {
                    $next = self::node('heading', ['text' => $source, 'level' => $props['tag']]);
                } else {
                    $content = $props['label']
                        ? ['type' => 'template', 'parts' => ['<p><span class="c-field-label">' . \CometCMS\Core\Security::e((string) ($config['label'] ?? $key)) . '</span>', $source, '</p>']]
                        : $source;
                    $next = self::node('text', ['html' => $content]);
                }
                if (isset(self::propSpec($next['kind'])['link'])) $next['props']['link'] = self::normalizeLink($props['link']);
                $next['id'] = $node['id'];
                $next['style'] = $node['style'];
                $node = $next;
            } elseif ($config['type'] === 'media' && !empty($config['multiple'])) {
                $node['props']['source'] = $source;
                $node = self::galleryFromField($node);
            } else {
                $node['props']['source'] = SmartValue::reference($key, $item ? 'item' : 'current');
                $subfields = [];
                foreach ($config['subfields'] ?? [] as $sub) $subfields[$sub['key']] = $sub;
                $node['children'] = self::bindDefaultNodes($node['children'], $subfields, true);
            }
        }
        return $nodes;
    }

    public static function defaultRegion(string $region): array
    {
        return self::withDefaultIds(self::buildDefaultRegion($region), 'region:' . $region);
    }

    private static function buildDefaultRegion(string $region): array
    {
        if ($region === self::MODAL) {
            return [self::node(self::MODAL, [], ['background' => 'background', 'radius' => 'theme', 'shadow' => 'lg'], [
                self::node('modal_close', [], ['position' => 'absolute', 'pin' => 'top-right', 'offset_x' => -8, 'offset_y' => 8, 'z_index' => '2']),
                self::node(self::SITE_CONTENT),
            ])];
        }
        if ($region === self::MAINTENANCE) {
            return [
                self::node('section', ['width' => 'narrow', 'min_height' => 'screen', 'vertical_align' => 'center'], ['padding_top' => '2xl', 'padding_bottom' => '2xl', 'background' => 'surface', 'align' => 'center'], [
                    self::node('row', [], [], [
                        self::node('column', [], ['align' => 'center'], [
                            self::node('site_title', ['show_logo' => true, 'show_name' => true], ['margin_bottom' => 'xl']),
                            self::node('icon', ['icon' => 'mdi:tools', 'size' => 56], ['text_color' => 'primary']),
                            self::node('heading', ['text' => 'We\'ll be back soon', 'level' => 'h1']),
                            self::node('text', ['html' => '<p>This website is currently undergoing maintenance. Please check back shortly.</p>'], ['text_color' => 'muted', 'font_size' => 'lg']),
                        ]),
                    ]),
                ]),
            ];
        }
        if ($region === self::SEARCH_RESULTS) {
            return [
                self::node('section', ['width' => 'narrow'], ['padding_top' => '2xl', 'padding_bottom' => '2xl'], [
                    self::node('row', [], [], [
                        self::node('column', [], [], [
                            self::node('heading', ['text' => 'Search results', 'level' => 'h1']),
                            self::node('search', ['results_page' => true], ['margin_bottom' => 'xl']),
                            self::node('collection', ['list_source' => 'search', 'columns' => 1, 'limit' => 10, 'paginate' => true]),
                        ]),
                    ]),
                ]),
            ];
        }
        if ($region === 'not_found') {
            return [
                self::node('section', ['width' => 'narrow'], ['padding_top' => '2xl', 'padding_bottom' => '2xl'], [
                    self::node('row', [], [], [
                        self::node('column', [], [], [
                            self::node('heading', ['text' => 'Page not found', 'level' => 'h1']),
                            self::node('text', ['html' => '<p>The page you are looking for does not exist or has moved.</p>']),
                            self::node('button', ['label' => 'Back to the homepage', 'link' => ['type' => 'home']]),
                        ]),
                    ]),
                ]),
            ];
        }

        if ($region === 'header') {
            return [
                self::node('section', ['width' => 'contained'], ['padding_top' => 'sm', 'padding_bottom' => 'sm', 'border' => ['bottom'], 'background' => 'background'], [
                    self::node('row', ['align' => 'center', 'justify' => 'between'], [], [
                        self::node('column', ['span' => ['mobile' => 8, 'tablet' => 4, 'desktop' => 0]], [], [
                            self::node('site_title', [], ['margin_bottom' => 'none']),
                        ]),
                        self::node('column', ['span' => ['mobile' => 4, 'tablet' => 8, 'desktop' => 0]], ['align' => 'right'], [
                            self::node('menu', ['menu' => 'main'], ['margin_bottom' => 'none']),
                        ]),
                    ]),
                ]),
            ];
        }

        return [
            self::node('section', ['width' => 'contained'], ['padding_top' => 'lg', 'padding_bottom' => 'lg', 'background' => 'surface', 'border' => ['top']], [
                self::node('row', ['align' => 'center'], [], [
                    self::node('column', ['span' => ['mobile' => 12, 'tablet' => 6, 'desktop' => 0]], [], [
                        self::node('text', ['html' => ['type' => 'template', 'parts' => ['<p>© ', SmartValue::reference('year', 'site'), ' ', SmartValue::reference('title', 'site'), '</p>']]], ['text_color' => 'muted', 'font_size' => 'sm', 'margin_bottom' => 'none']),
                    ]),
                    self::node('column', ['span' => ['mobile' => 12, 'tablet' => 6, 'desktop' => 0]], ['align' => 'right'], [
                        self::node('menu', ['menu' => 'footer', 'collapse_mobile' => false], ['font_size' => 'sm', 'margin_bottom' => 'none']),
                    ]),
                ]),
            ]),
        ];
    }

    public static function normalizeLink(mixed $link): array
    {
        $link = is_array($link) ? $link : [];
        if (($link['type'] ?? '') === 'dynamic') {
            return ['type' => 'dynamic', 'value' => SmartValue::normalize($link['value'] ?? null, 'link'), 'new_tab' => (bool) ($link['new_tab'] ?? false)];
        }
        $type = in_array($link['type'] ?? null, self::LINK_TYPES, true) ? (string) $link['type'] : 'none';
        $normalized = ['type' => $type];

        if ($type === 'url') {
            $url = trim(substr((string) ($link['url'] ?? ''), 0, 2000));
            $normalized['url'] = HtmlSanitizer::isSafeUrl($url) ? $url : '';
        }

        if ($type === 'entry' || $type === 'index') {
            $normalized['collection'] = self::key($link['collection'] ?? '');
        }

        if ($type === 'entry') {
            $normalized['entry'] = self::key($link['entry'] ?? '');
        }

        if (in_array($type, ['anchor', 'home', 'entry', 'index'], true)) {
            $anchor = self::key(ltrim(trim((string) ($link['anchor'] ?? '')), '#'));
            if ($anchor !== '' || $type === 'anchor') {
                $normalized['anchor'] = $anchor;
            }
        }

        if ($type !== 'none') {
            $normalized['new_tab'] = $type !== 'anchor' && (bool) ($link['new_tab'] ?? false);
        }
        if (in_array($type, self::MODAL_LINK_TYPES, true) && !empty($link['open_modal'])) {
            $normalized['open_modal'] = true;
            $normalized['new_tab'] = false;
        }

        return $normalized;
    }

    public static function key(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^[A-Za-z0-9_-]{1,80}$/', $value) ? $value : '';
    }

    /**
     * $rows counts the rows around $nodes, to bound how deep rows nest in
     * columns. $places collects the page fields that already have a place
     * (null where no page content place may go); $areas allows a component's
     * content areas.
     */
    private static function normalizeNodes(array $nodes, string $parent, int &$count, array &$seen, bool $insideItem, int $rows, BlockRegistry $blocks, ?array &$places = null, bool $inModal = false, string $path = 'root', bool $areas = false): array
    {
        $allowed = self::childrenFor($parent, $places !== null, $inModal, $areas, $blocks);
        $normalized = [];
        $filled = [];

        foreach (array_values($nodes) as $index => $node) {
            if (!is_array($node) || $count >= self::MAX_NODES) {
                continue;
            }

            $kind = (string) ($node['kind'] ?? '');
            $placed = self::placementKind($node);
            $missingBlock = BlockRegistry::isKind($kind) && in_array($parent, ['column', 'tab', 'field', 'slot', 'item-slot', self::MODAL, self::AREA_CONTENT], true);
            if ($placed === '' || (!in_array($placed, $allowed, true) && !$missingBlock) || ($placed === 'row' && $rows > self::MAX_ROW_DEPTH)) {
                continue;
            }

            // Each page field has one place, which needs a field to show.
            if ($kind === self::PAGE_CONTENT) {
                $field = self::key($node['props']['field'] ?? '');
                if ($field !== '' && isset($places[$field])) {
                    continue;
                }
                if ($field !== '') {
                    $places[$field] = true;
                }
            }
            // An instance has one content per area of its component.
            if ($kind === self::AREA_CONTENT) {
                $area = self::key($node['props']['area'] ?? '');
                if ($area === '' || isset($filled[$area])) {
                    continue;
                }
                $filled[$area] = true;
            }

            $count++;
            $id = self::key($node['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                // Legacy/hand-edited files are normalized on every read. Random
                // repairs would change their revision without any actual edit.
                $attempt = 0;
                do {
                    $id = 'n' . substr(hash('sha256', $path . ':' . $index . ':' . $attempt++), 0, 10);
                } while (isset($seen[$id]));
            }
            $seen[$id] = true;

            // A repeater's item template may not contain another template.
            $childParent = $kind === 'field' ? ($insideItem ? '' : 'field') : $kind;
            // A block holds children in its slot; a missing block keeps them for a reinstall.
            $slot = BlockRegistry::isKind($kind) && ($blocks->get($kind)['slot'] ?? $blocks->get($kind) === null);
            if ($slot) $childParent = $insideItem ? 'item-slot' : 'slot';
            // Cards, item templates, the default content and instances' area content hold neither content place, content area nor modal elements.
            $repeated = in_array($kind, ['field', 'collection', self::PAGE_CONTENT, self::COMPONENT], true);
            $childPlaces = $repeated ? null : $places;
            // An instance's area content sits in the component's rows, at least one unless the component is a column.
            $childRows = match ($kind) {
                self::PAGE_CONTENT => 0,
                self::COMPONENT => $rows + (($node['props']['root'] ?? '') === 'column' ? 0 : 1),
                default => $rows + ($kind === 'row' ? 1 : 0),
            };
            $children = ($slot || in_array($kind, [...self::STRUCTURE, 'field', 'collection', 'tabs', 'tab', self::PAGE_CONTENT, self::MODAL, self::COMPONENT, self::AREA_CONTENT], true)) && is_array($node['children'] ?? null)
                ? self::normalizeNodes($node['children'], $childParent, $count, $seen, $insideItem || $kind === 'field', $childRows, $blocks, $childPlaces, ($inModal || $kind === self::MODAL) && !$repeated, $path . ':' . $index, $areas && !$repeated && $kind !== self::AREA_CONTENT)
                : [];
            if ($childPlaces !== null) {
                $places = $childPlaces;
            }
            // A collection repeats a single card.
            if ($kind === 'collection') {
                $children = array_slice($children, 0, 1);
            }

            $props = is_array($node['props'] ?? null) ? $node['props'] : [];
            $style = is_array($node['style'] ?? null) ? $node['style'] : [];

            $entry = [
                'id' => $id,
                'kind' => $kind,
                'props' => self::normalizeProps($kind, $props, $blocks),
                'style' => self::normalizeStyle($kind, $style),
                'children' => $children,
            ];
            foreach (['responsive' => array_keys(StyleVariants::DEVICES), 'states' => StyleVariants::STATES] as $group => $variants) {
                $overrides = self::normalizeOverrides($kind, $node[$group] ?? null, $group, $variants);
                if ($overrides !== []) {
                    $entry[$group] = $overrides;
                }
            }
            $name = is_string($node['name'] ?? null)
                ? trim(Text::limit(str_replace(["\r", "\n"], ' ', $node['name']), self::MAX_NODE_NAME_LENGTH))
                : '';
            if ($name !== '') {
                $entry['name'] = $name;
            }
            // Inside a component: props each placement may set (see applyOverrides()).
            $editableKeys = self::keyList($node['editable'] ?? null);
            $editable = in_array($kind, [self::COMPONENT, self::AREA_CONTENT], true) ? [] : array_values(array_intersect($editableKeys, array_keys(self::propSpec($kind, $blocks))));
            if ($editable !== []) {
                $entry['editable'] = $editable;
            }
            $overrides = $kind === self::COMPONENT ? self::instanceOverrides($node['overrides'] ?? null) : [];
            if ($overrides !== []) {
                $entry['overrides'] = $overrides;
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    public static function normalizeProps(string $kind, array $props, ?BlockRegistry $blocks = null): array
    {
        // Keep bounded inert values when a package is unavailable so a later reinstall restores it.
        if (BlockRegistry::isKind($kind) && ($blocks ?? BlockRegistry::active())->get($kind) === null) {
            $props = array_filter($props, static fn(mixed $key): bool => is_string($key) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) === 1, ARRAY_FILTER_USE_KEY);
            return self::inert(array_slice($props, 0, 32, true), 0);
        }
        $normalized = [];

        foreach (self::propSpec($kind, $blocks) as $key => $spec) {
            $value = $props[$key] ?? $spec['default'];
            $binding = self::bindings($kind, $blocks)[$key] ?? null;
            $normalize = static fn(mixed $item): mixed => $binding !== null && SmartValue::isDynamic($item)
                ? SmartValue::normalize($item, $binding) : self::normalizeValue($spec, $item);
            $normalized[$key] = in_array($key, self::localizedProps($kind, $blocks), true)
                ? LocalizedValue::normalize($value, $normalize, (new LanguageSettings())->all()['locales'])
                : $normalize($value);
        }

        return $normalized;
    }

    private static function galleryFromField(array $node): array
    {
        $props = $node['props'];
        $node['kind'] = 'gallery';
        $node['props'] = self::node('gallery', [
            'media' => $props['source'] ?? '',
            'columns' => max(2, (int) ($props['columns'] ?? 1)),
            'ratio' => $props['ratio'] ?? '',
        ] + array_intersect_key($props, array_flip(['fit', 'position', 'label', 'link'])))['props'];
        $node['children'] = [];
        return $node;
    }

    /** Only values that differ from their default are kept. */
    private static function normalizeStyle(string $kind, array $style): array
    {
        $specs = self::styleSpec();
        $normalized = [];

        foreach (self::styleKeys($kind) as $key) {
            if (!array_key_exists($key, $style)) {
                continue;
            }

            $binding = SmartValue::STYLE_PROPERTIES[$key] ?? null;
            $normalize = static fn(mixed $item): mixed => $binding !== null && SmartValue::isDynamic($item)
                ? SmartValue::normalize($item, $binding) : self::normalizeValue($specs[$key], $item);
            $value = !empty($specs[$key]['localized'])
                ? LocalizedValue::normalize($style[$key], $normalize, (new LanguageSettings())->all()['locales'])
                : $normalize($style[$key]);
            if ($value !== $specs[$key]['default']) {
                $normalized[$key] = $value;
            }
        }

        if (!isset($normalized['border'])) {
            unset($normalized['border_width'], $normalized['border_style'], $normalized['border_color']);
        }
        if (!isset($normalized['animation'])) {
            unset($normalized['animation_trigger'], $normalized['animation_duration'], $normalized['animation_delay']);
        }

        return $normalized;
    }

    /**
     * Tablet and mobile, or hover and focus values of a node: only keys the
     * kind offers for them, and only set values. An empty value follows the
     * normal one (or the larger screen), so it is not kept.
     */
    private static function normalizeOverrides(string $kind, mixed $value, string $group, array $variants): array
    {
        if (!is_array($value)) {
            return [];
        }
        $keys = $group === 'responsive'
            ? ['props' => self::RESPONSIVE_PROPS[$kind] ?? [], 'style' => self::RESPONSIVE_STYLE]
            : ['props' => self::STATE_PROPS[$kind] ?? [], 'style' => self::STATE_STYLE];
        $specs = ['props' => self::propSpec($kind), 'style' => self::styleSpec()];
        $styleKeys = self::styleKeys($kind);
        $normalized = [];

        foreach ($variants as $variant) {
            $variantKeys = $keys;
            if ($group === 'states' && $variant === 'hover') {
                $variantKeys['style'] = [...$variantKeys['style'], ...self::HOVER_ANIMATION_KEYS];
            }
            foreach (['props', 'style'] as $part) {
                $values = $value[$variant][$part] ?? null;
                if (!is_array($values)) {
                    continue;
                }
                foreach ($variantKeys[$part] as $key) {
                    if (!array_key_exists($key, $values) || ($part === 'style' && !in_array($key, $styleKeys, true))) {
                        continue;
                    }
                    $item = self::normalizeValue($specs[$part][$key], $values[$key]);
                    if ($item !== '') {
                        $normalized[$variant][$part][$key] = $item;
                    }
                }
            }
        }

        if ($group === 'states' && isset($normalized['hover']['style'])) {
            $hover = &$normalized['hover']['style'];
            if (($hover['animation'] ?? 'none') === 'none') {
                foreach (self::HOVER_ANIMATION_KEYS as $key) unset($hover[$key]);
            } else {
                foreach (['animation_duration', 'animation_delay'] as $key) {
                    if (($hover[$key] ?? null) === $specs['style'][$key]['default']) unset($hover[$key]);
                }
            }
            unset($hover);
            if ($normalized['hover']['style'] === []) unset($normalized['hover']['style']);
            if ($normalized['hover'] === []) unset($normalized['hover']);
        }
        return $normalized;
    }

    private static function normalizeValue(array $spec, mixed $value): mixed
    {
        $default = $spec['default'];

        return match ($spec['type']) {
            'accordion_items' => self::normalizeAccordionItems($value),
            'table_rows' => self::normalizeTableRows($value),
            'gradient' => Gradient::normalize($value),
            'shadow' => Shadow::normalize($value, $spec['text'] ?? false),
            'color' => is_string($value) && (in_array($value, $spec['options'], true) || Theme::isCustomColorKey($value)) ? $value : $default,
            'enum', 'icon' => is_scalar($value) && in_array((string) $value, $spec['options'], true) ? (string) $value : $default,
            'set' => array_values(array_intersect($spec['options'], array_map('strval', is_array($value) ? $value : []))),
            'bool' => is_bool($value) ? $value : (bool) $default,
            'int' => is_numeric($value) ? max($spec['min'], min($spec['max'], (int) $value)) : $default,
            'font' => in_array($value, ['', 'body', 'heading'], true) || FontRegistry::validId($value) ? $value : $default,
            'string' => is_scalar($value) ? trim(Text::limit(str_replace(["\r", "\n"], ' ', (string) $value), $spec['max'] ?? 200)) : $default,
            'text' => is_scalar($value) ? trim(Text::limit(str_replace(["\r\n", "\r"], "\n", (string) $value), $spec['max'] ?? 2000)) : $default,
            'list' => self::normalizeList($spec, $value),
            'url' => self::normalizeBlockUrl($spec, $value),
            'html' => is_scalar($value) ? HtmlSanitizer::sanitize(Text::limit((string) $value, 50000)) : $default,
            'raw_html' => is_string($value) ? Text::limit($value, $spec['max']) : $default,
            'media' => is_scalar($value) && preg_match('/^[^\/\\\\\0]{1,255}$/', basename((string) $value)) ? basename((string) $value) : '',
            'media_list' => self::normalizeMediaList($value),
            'link' => self::normalizeLink($value),
            'reference' => SmartValue::isDynamic($value) ? SmartValue::normalize($value, 'value') : '',
            'spans' => self::normalizeSpans($value),
            'class' => self::cssClasses($value),
            'sort_field' => self::key($value) !== '' ? self::key($value) : $default,
            'field', 'display', 'content_type', 'menu', 'form', 'key', 'page_field' => self::key($value),
            'content_types', 'search_fields' => self::keyList($value),
            'sort_fields' => array_slice(self::keyList($value), 0, ListFilters::MAX_SORTS),
            'list_filters' => ListFilters::normalize($value),
            'conditions' => ElementConditions::normalize($value),
            default => $default,
        };
    }


    private static function normalizeAccordionItems(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) return [];
        $items = [];
        $locales = (new LanguageSettings())->all()['locales'];
        foreach (array_slice($value, 0, self::MAX_NODES) as $item) {
            if (!is_array($item)) continue;
            $entry = [];
            foreach (['title' => ['type' => 'string', 'max' => 300, 'default' => ''], 'html' => ['type' => 'html', 'default' => '']] as $key => $spec) {
                $target = $key === 'html' ? 'html' : 'text';
                $entry[$key] = LocalizedValue::normalize($item[$key] ?? '', static fn(mixed $part): mixed => SmartValue::isDynamic($part)
                    ? SmartValue::normalize($part, $target) : self::normalizeValue($spec, $part), $locales);
            }
            $entry['open'] = ($item['open'] ?? false) === true;
            $items[] = $entry;
        }
        return $items;
    }

    /**
     * Table cells as a rectangle: rows are padded with empty cells to the
     * widest row. A cell is inline HTML or a binding.
     */
    private static function normalizeTableRows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) return [];
        $rows = array_values(array_filter(array_slice($value, 0, self::TABLE_MAX_ROWS), static fn(mixed $row): bool => is_array($row) && array_is_list($row)));
        $width = min(self::TABLE_MAX_COLUMNS, max(0, ...array_map('count', $rows ?: [[]])));
        if ($width === 0) return [];
        return array_map(static fn(array $row): array => array_map(
            static fn(mixed $cell): mixed => SmartValue::isDynamic($cell) ? SmartValue::normalize($cell, 'html') : self::tableCell($cell),
            array_pad(array_slice($row, 0, $width), $width, ''),
        ), $rows);
    }

    /** Inline HTML a table cell keeps: text formatting, links and line breaks. */
    public static function tableCell(mixed $value): string
    {
        if (!is_scalar($value)) return '';
        // Paragraphs from a rich text editor become line breaks within the cell.
        $html = preg_replace(['#</(p|div|li|h[1-6])>\s*<(p|div|li|h[1-6])(\s[^>]*)?>#i', '#<br\s*/?>#i'], ['<br>', '<br>'], mb_substr((string) $value, 0, 5000)) ?? '';

        return trim(strip_tags(HtmlSanitizer::sanitize($html), self::TABLE_CELL_TAGS));
    }

    /** Distinct keys in their saved order, e.g. the content types a search looks through. */
    private static function keyList(mixed $value): array
    {
        $keys = array_map(self::key(...), array_slice(is_array($value) ? array_values($value) : [], 0, 100));

        return array_values(array_unique(array_filter($keys, static fn(string $key): bool => $key !== '')));
    }

    /** Plain data, bounded in size and depth: what a missing block's props are kept as. */
    private static function inert(array $values, int $depth): array
    {
        $kept = [];
        foreach (array_slice($values, 0, 100, true) as $key => $value) {
            if (is_string($value)) {
                $kept[$key] = Text::limit($value, 5000);
            } elseif (is_scalar($value) || $value === null) {
                $kept[$key] = $value;
            } elseif (is_array($value) && $depth < 4) {
                $kept[$key] = self::inert($value, $depth + 1);
            }
        }

        return $kept;
    }

    /** Items of a block's list prop, each with exactly its declared fields. */
    private static function normalizeList(array $spec, mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return $spec['default'];
        }
        $items = [];
        foreach (array_slice($value, 0, $spec['max']) as $item) {
            $normalized = [];
            foreach ($spec['fields'] as $name => $field) {
                $normalized[$name] = self::normalizeValue($field, is_array($item) && array_key_exists($name, $item) ? $item[$name] : $field['default']);
            }
            $items[] = $normalized;
        }

        return $items;
    }

    private static function normalizeBlockUrl(array $spec, mixed $value): string
    {
        if (!is_string($value)) return $spec['default'];
        $value = trim(Text::limit(trim($value), $spec['max']));

        return HtmlSanitizer::isSafeUrl($value) ? $value : $spec['default'];
    }

    private static function normalizeMediaList(mixed $value): array
    {
        $items = [];
        foreach (array_slice(is_array($value) ? $value : [], 0, 500) as $file) {
            $name = self::normalizeValue(['type' => 'media', 'default' => ''], $file);
            if ($name !== '') $items[] = $name;
        }
        return array_values(array_unique($items));
    }

    private static function normalizeSpans(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $span = static fn(mixed $span, int $min): int => is_numeric($span) ? max($min, min(12, (int) $span)) : $min;

        return [
            'mobile' => isset($value['mobile']) ? $span($value['mobile'], 1) : 12,
            'tablet' => $span($value['tablet'] ?? 0, 0),
            'desktop' => $span($value['desktop'] ?? 0, 0),
        ];
    }

    private static function cssClasses(mixed $value): string
    {
        $classes = preg_split('/\s+/', is_scalar($value) ? trim((string) $value) : '') ?: [];
        $classes = array_filter($classes, static fn(string $class): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,60}$/', $class) === 1);

        return implode(' ', array_slice(array_values(array_unique($classes)), 0, 8));
    }

    private static function firstFieldOfType(array $fields, array $types): ?string
    {
        foreach ($fields as $name => $config) {
            if (is_array($config) && in_array((string) ($config['type'] ?? ''), $types, true)) {
                return (string) $name;
            }
        }

        return null;
    }
}
