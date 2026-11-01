<?php

declare(strict_types=1);

namespace CometCMS\Site;

/**
 * Website design tokens. Themes are data only: every value is checked against
 * a fixed vocabulary before it reaches the generated stylesheet, so a theme
 * can never inject markup or script. Custom CSS is the single free-form value
 * and is restricted to administrators by the controller.
 */
final class Theme
{
    public const COLOR_KEYS = ['primary', 'secondary', 'accent', 'background', 'surface', 'text', 'muted', 'border'];

    public const MODES = ['light', 'dark', 'auto'];

    /** Default corner for buttons, images and cards: none, a radius token, or fully round. */
    public const RADII = ['none', 'sm', 'md', 'lg', 'full'];

    /** Corner radius tokens in px. */
    public const RADIUS_TOKENS = ['sm', 'md', 'lg'];

    /** Spacing scale tokens in px, used by padding, space below, gaps and spacers. */
    public const SPACE_TOKENS = ['xs', 'sm', 'md', 'lg', 'xl', '2xl'];

    public const TYPE_SCALES = ['1.125', '1.2', '1.25', '1.333'];

    /** Entrance animation speeds in ms. */
    public const MOTION_SPEEDS = ['fast', 'normal', 'slow'];

    /** Heading levels and the type scale step each one uses by default. */
    public const HEADING_LEVELS = ['h1' => 5, 'h2' => 4, 'h3' => 3, 'h4' => 2, 'h5' => 1, 'h6' => 0];

    public const TEXT_TRANSFORMS = ['', 'uppercase', 'lowercase', 'capitalize'];

    /** When text links show their underline. */
    public const LINK_DECORATIONS = ['underline', 'hover', 'none'];

    /** Custom heading sizes from this many px shrink on phones (see MOBILE_HEADING_FACTOR). */
    private const MOBILE_HEADING_FROM = 32;

    private const MOBILE_HEADING_FACTOR = 0.8;

    public const MAX_CUSTOM_CSS = 100000;

    public const MAX_CUSTOM_COLORS = 64;

    public const MAX_SHADOWS = 32;

    /**
     * Local font stacks only: nothing is fetched from third-party font CDNs,
     * so visitors' IP addresses never leave the site.
     */
    public const FONT_STACKS = [
        'system' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        'neo-grotesque' => 'Inter, Roboto, "Helvetica Neue", "Arial Nova", "Nimbus Sans", Arial, sans-serif',
        'humanist' => 'Seravek, "Gill Sans Nova", Ubuntu, Calibri, "DejaVu Sans", source-sans-pro, sans-serif',
        'geometric' => 'Avenir, Montserrat, Corbel, "URW Gothic", source-sans-pro, sans-serif',
        'rounded' => 'ui-rounded, "Hiragino Maru Gothic ProN", Quicksand, Comfortaa, Manjari, "Arial Rounded MT", "Arial Rounded MT Bold", Calibri, source-sans-pro, sans-serif',
        'transitional' => 'Charter, "Bitstream Charter", "Sitka Text", Cambria, serif',
        'old-style' => '"Iowan Old Style", "Palatino Linotype", "URW Palladio L", P052, serif',
        'didone' => 'Didot, "Bodoni MT", "Noto Serif Display", "URW Palladio L", P052, Sylfaen, serif',
        'slab' => 'Rockwell, "Rockwell Nova", "Roboto Slab", "DejaVu Serif", "Sitka Small", serif',
        'monospace' => 'ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, Consolas, "DejaVu Sans Mono", monospace',
    ];

    public static function defaults(): array
    {
        return [
            'mode' => 'light',
            'colors' => [
                'primary' => '#2563eb',
                'secondary' => '#0f172a',
                'accent' => '#f59e0b',
                'background' => '#ffffff',
                'surface' => '#f1f5f9',
                'text' => '#0f172a',
                'muted' => '#64748b',
                'border' => '#e2e8f0',
            ],
            'dark_colors' => [
                'primary' => '#60a5fa',
                'secondary' => '#e2e8f0',
                'accent' => '#fbbf24',
                'background' => '#0b1120',
                'surface' => '#162033',
                'text' => '#e2e8f0',
                'muted' => '#94a3b8',
                'border' => '#1e293b',
            ],
            'custom_colors' => [],
            'shadow_scale' => Shadow::scaleDefaults(),
            // Additional box shadow presets alongside the editable S–L scale.
            // An empty label shows the preset's translated name.
            'shadows' => [
                'hard' => ['label' => '', 'color' => 'primary', 'x' => 6, 'y' => 6, 'blur' => 0, 'spread' => 0, 'opacity' => 100, 'inset' => false],
                'glow' => ['label' => '', 'color' => 'primary', 'x' => 0, 'y' => 0, 'blur' => 24, 'spread' => 4, 'opacity' => 55, 'inset' => false],
                'inset' => ['label' => '', 'color' => Shadow::BLACK, 'x' => 0, 'y' => 2, 'blur' => 8, 'spread' => 0, 'opacity' => 25, 'inset' => true],
            ],
            'fonts' => [
                'body' => 'system',
                'heading' => 'system',
            ],
            'base_size' => 17,
            'line_height' => 1.65,
            'type_scale' => '1.25',
            'heading_weight' => 700,
            'heading_line_height' => 1.2,
            'heading_letter_spacing' => -0.01,
            'heading_transform' => '',
            // Per-level overrides; '' and null inherit the values above.
            'headings' => array_fill_keys(array_keys(self::HEADING_LEVELS), self::headingDefaults()),
            'radius' => 'md',
            'radii' => ['sm' => 4, 'md' => 8, 'lg' => 14],
            'container_width' => 1200,
            'narrow_width' => 760,
            'section_padding' => 'md',
            'grid_padding' => 'md',
            'column_padding' => 'md',
            // Tablet and phone versions; '' keeps the next larger screen's value.
            'section_padding_tablet' => 'sm',
            'grid_padding_tablet' => 'sm',
            'column_padding_tablet' => 'sm',
            'section_padding_mobile' => '',
            'grid_padding_mobile' => '',
            'column_padding_mobile' => '',
            'space' => ['xs' => 4, 'sm' => 8, 'md' => 16, 'lg' => 32, 'xl' => 56, '2xl' => 96],
            // Text links; an empty hover color keeps the link color. Thickness 0 is the font's own.
            'links' => ['color' => 'primary', 'hover_color' => '', 'decoration' => 'underline', 'thickness' => 0, 'offset' => 0.18],
            // Animation speeds, the extra delay per stagger step and the hover state transition, in ms.
            'motion' => ['fast' => 400, 'normal' => 700, 'slow' => 1100, 'stagger' => 120, 'hover' => 200],
            'custom_css' => '',
        ];
    }

    private static function fontChoice(mixed $value, string $default): string
    {
        return FontRegistry::validId($value) ? $value : $default;
    }

    public static function normalize(array $theme): array
    {
        $defaults = self::defaults();
        $customColors = self::customColors($theme['custom_colors'] ?? []);

        return [
            'mode' => self::oneOf($theme['mode'] ?? null, self::MODES, $defaults['mode']),
            'colors' => self::colors($theme['colors'] ?? [], $defaults['colors']),
            'dark_colors' => self::colors($theme['dark_colors'] ?? [], $defaults['dark_colors']),
            'custom_colors' => $customColors,
            'shadow_scale' => self::shadowScale($theme['shadow_scale'] ?? []),
            // Themes saved before presets existed get the shipped ones; an emptied list stays empty.
            'shadows' => array_key_exists('shadows', $theme) ? self::shadows($theme['shadows']) : $defaults['shadows'],
            'fonts' => [
                'body' => self::fontChoice($theme['fonts']['body'] ?? null, $defaults['fonts']['body']),
                'heading' => self::fontChoice($theme['fonts']['heading'] ?? null, $defaults['fonts']['heading']),
            ],
            'base_size' => self::clampInt($theme['base_size'] ?? null, 12, 24, $defaults['base_size']),
            'line_height' => round(self::clampFloat($theme['line_height'] ?? null, 1.1, 2.2, $defaults['line_height']), 2),
            'type_scale' => self::oneOf(isset($theme['type_scale']) ? (string) $theme['type_scale'] : null, self::TYPE_SCALES, $defaults['type_scale']),
            'heading_weight' => self::clampInt($theme['heading_weight'] ?? null, 300, 900, $defaults['heading_weight'], 100),
            'heading_line_height' => round(self::clampFloat($theme['heading_line_height'] ?? null, 0.8, 2.2, $defaults['heading_line_height']), 2),
            'heading_letter_spacing' => round(self::clampFloat($theme['heading_letter_spacing'] ?? null, -0.1, 0.5, $defaults['heading_letter_spacing']), 3),
            'heading_transform' => self::oneOf($theme['heading_transform'] ?? null, self::TEXT_TRANSFORMS, $defaults['heading_transform']),
            'headings' => self::headings($theme['headings'] ?? []),
            'radius' => self::oneOf($theme['radius'] ?? null, self::RADII, $defaults['radius']),
            'radii' => self::radii($theme, $defaults['radii']),
            'container_width' => self::clampInt($theme['container_width'] ?? null, 720, 1920, $defaults['container_width']),
            'narrow_width' => self::clampInt($theme['narrow_width'] ?? null, 480, 1200, $defaults['narrow_width']),
            'section_padding' => self::oneOf($theme['section_padding'] ?? null, ['none', ...self::SPACE_TOKENS], $defaults['section_padding']),
            'grid_padding' => self::oneOf($theme['grid_padding'] ?? null, ['none', ...self::SPACE_TOKENS], $defaults['grid_padding']),
            'column_padding' => self::oneOf($theme['column_padding'] ?? null, ['none', ...self::SPACE_TOKENS], $defaults['column_padding']),
            ...self::devicePaddings($theme, $defaults),
            'space' => self::space($theme, $defaults['space']),
            'links' => self::links($theme, $customColors, $defaults['links']),
            'motion' => self::motion($theme['motion'] ?? [], $defaults['motion']),
            'custom_css' => self::customCss((string) ($theme['custom_css'] ?? '')),
        ];
    }

    /**
     * CSS custom properties for a normalized theme, followed by the shipped
     * base stylesheet and the administrator's custom CSS.
     */
    public static function stylesheet(array $theme, ?FontRegistry $fonts = null, string $basePath = ''): string
    {
        $theme = self::normalize($theme);
        $fonts ??= FontRegistry::active();
        $stacks = $fonts->stacks();
        $scale = (float) $theme['type_scale'];
        $radius = match ($theme['radius']) {
            'none' => '0',
            'full' => '9999px',
            default => 'var(--radius-' . $theme['radius'] . ')',
        };

        $root = [
            '--font-body' => ($stacks[$theme['fonts']['body']] ?? self::FONT_STACKS['system']),
            '--font-heading' => ($stacks[$theme['fonts']['heading']] ?? self::FONT_STACKS['system']),
            '--font-size' => $theme['base_size'] . 'px',
            '--line-height' => (string) $theme['line_height'],
            '--heading-weight' => (string) $theme['heading_weight'],
            '--heading-line-height' => self::number($theme['heading_line_height']),
            '--heading-letter-spacing' => self::number($theme['heading_letter_spacing']) . 'em',
            '--heading-transform' => $theme['heading_transform'] !== '' ? $theme['heading_transform'] : 'none',
            '--radius' => $radius,
            // Surfaces (images, cards, sections) never become pills: "round"
            // only rounds buttons and other small controls fully.
            '--radius-box' => $theme['radius'] === 'full' ? 'var(--radius-lg)' : $radius,
            '--container' => $theme['container_width'] . 'px',
            '--narrow' => $theme['narrow_width'] . 'px',
            ...self::paddingVariables($theme, ''),
            '--link-color' => 'var(--c-' . $theme['links']['color'] . ')',
            '--link-decoration' => $theme['links']['decoration'] === 'underline' ? 'underline' : 'none',
            '--link-hover-decoration' => $theme['links']['decoration'] === 'none' ? 'none' : 'underline',
            '--link-thickness' => $theme['links']['thickness'] > 0 ? $theme['links']['thickness'] . 'px' : 'auto',
            '--link-offset' => self::number($theme['links']['offset']) . 'em',
        ];

        if ($theme['links']['hover_color'] !== '') {
            $root['--link-hover-color'] = 'var(--c-' . $theme['links']['hover_color'] . ')';
        }

        foreach ($theme['radii'] as $token => $px) {
            $root['--radius-' . $token] = $px . 'px';
        }

        // Unitless ms, so the stylesheet can turn them into times and scroll distances.
        foreach ($theme['motion'] as $key => $ms) {
            $root['--motion-' . $key] = (string) $ms;
        }

        foreach ($theme['space'] as $token => $px) {
            $root['--space-' . $token] = self::number($px / 16) . 'rem';
        }

        foreach (['sm' => -1, 'base' => 0, 'lg' => 1, 'xl' => 2, '2xl' => 3, '3xl' => 4, '4xl' => 5, '5xl' => 6, '6xl' => 7, '7xl' => 8, '8xl' => 9, '9xl' => 10] as $step => $power) {
            $root['--fs-' . $step] = self::number(pow($scale, $power)) . 'rem';
        }

        $light = self::colorVariables($theme['colors']);
        $dark = self::colorVariables($theme['dark_colors']);
        foreach ($theme['custom_colors'] as $key => $color) {
            $light['--c-' . $key] = $color['color'];
            $light['--c-on-' . $key] = self::contrastColor($color['color']);
            $dark['--c-' . $key] = $color['dark_color'];
            $dark['--c-on-' . $key] = self::contrastColor($color['dark_color']);
        }
        $css = ':root{' . self::declarations($root) . '}';
        foreach (StyleVariants::DEVICES as $device => $width) {
            $paddings = self::paddingVariables($theme, '_' . $device);
            if ($paddings !== []) {
                $css .= '@media (max-width: ' . $width . 'px){:root{' . self::declarations($paddings) . '}}';
            }
        }

        if ($theme['mode'] === 'dark') {
            $css .= ':root{color-scheme:dark;' . self::declarations($dark) . '}';
        } else {
            $css .= ':root{color-scheme:light;' . self::declarations($light) . '}';
        }

        if ($theme['mode'] === 'auto') {
            $css .= '@media (prefers-color-scheme: dark){:root{color-scheme:dark;' . self::declarations($dark) . '}}';
        }

        // A visitor's choice from a theme toggle (see ThemeToggle) wins over the mode.
        $css .= ':root[data-theme="light"]{color-scheme:light;' . self::declarations($light) . '}'
            . ':root[data-theme="dark"]{color-scheme:dark;' . self::declarations($dark) . '}';

        $css .= "\n" . self::baseStylesheet();
        $css .= self::headingStyles($theme['headings'], $stacks) . $fonts->css($basePath);

        foreach ($theme['custom_colors'] as $key => $_color) {
            $css .= "\n.c-bg-" . $key . '{--c-fill:var(--c-' . $key . ');background-color:var(--c-' . $key . ');color:var(--c-on-' . $key . ');}';
            $css .= '.c-fg-' . $key . '{color:var(--c-' . $key . ');}';
            $css .= '.c-bd.c-bdc-' . $key . '{--c-bd-c:var(--c-' . $key . ');}';
            $css .= '.c-bg-' . $key . ' a:not(.c-button){color:inherit;}';
            $css .= '.c-bg-' . $key . '{--c-menu-accent:currentColor;}';
            $css .= '.c-lc-' . $key . ' a:not(.c-button){color:var(--c-' . $key . ');}';
            $css .= '.c-button.c-btn-bg-' . $key . '{background:var(--c-' . $key . ');color:var(--c-on-' . $key . ');}';
            $css .= '.c-button.c-btn-fg-' . $key . '{color:var(--c-' . $key . ');}';
            $css .= '.c-button.c-btn-bdc-' . $key . '{border-color:var(--c-' . $key . ');}';
        }

        // Before the variants, so scale edits and presets apply on devices and states too.
        foreach ($theme['shadow_scale'] as $key => $shadow) {
            $css .= "\n.c-sh-" . $key . '{box-shadow:' . Shadow::scaleCss($key, $shadow) . ';}';
        }

        foreach ($theme['shadows'] as $key => $shadow) {
            $css .= "\n.c-sh-" . $key . '{box-shadow:' . Shadow::css($shadow) . ';}';
        }

        // Custom CSS comes last, so it can still change any override.
        $css .= StyleVariants::css($css);

        if ($theme['custom_css'] !== '') {
            $css .= "\n/* Custom CSS */\n" . $theme['custom_css'] . "\n";
        }

        return $css;
    }

    /** Tablet and phone default paddings: '' or a padding token. */
    private static function devicePaddings(array $theme, array $defaults): array
    {
        $paddings = [];
        foreach (array_keys(StyleVariants::DEVICES) as $device) {
            foreach (['section', 'grid', 'column'] as $kind) {
                $key = $kind . '_padding_' . $device;
                // Themes saved before these existed get the defaults, not '' (which oneOf would let through).
                $paddings[$key] = isset($theme[$key]) ? self::oneOf($theme[$key], ['', 'none', ...self::SPACE_TOKENS], $defaults[$key]) : $defaults[$key];
            }
        }
        return $paddings;
    }

    /** Default padding variables for one screen size; $suffix '' is desktop, '_tablet' or '_mobile' skips inherited ones. */
    private static function paddingVariables(array $theme, string $suffix): array
    {
        $vars = [];
        foreach (['section', 'grid', 'column'] as $kind) {
            $token = $theme[$kind . '_padding' . $suffix];
            if ($token !== '') {
                $vars['--' . $kind . '-padding'] = $token === 'none' ? '0px' : 'var(--space-' . $token . ')';
            }
        }
        return $vars;
    }

    /** @return array{font: string, size: ?int, weight: ?int, line_height: ?float, letter_spacing: ?float, transform: string} */
    public static function headingDefaults(): array
    {
        return ['font' => '', 'size' => null, 'weight' => null, 'line_height' => null, 'letter_spacing' => null, 'transform' => ''];
    }

    /**
     * Rules for customized heading levels. They follow the base stylesheet so
     * they replace its defaults, while builder style classes (font size,
     * weight, family) still win through their higher specificity.
     */
    private static function headingStyles(array $headings, array $stacks): string
    {
        $css = '';
        $mobile = '';

        foreach ($headings as $level => $heading) {
            $declarations = array_filter([
                'font-family' => $heading['font'] !== '' ? ($stacks[$heading['font']] ?? self::FONT_STACKS['system']) : null,
                'font-size' => $heading['size'] !== null ? $heading['size'] . 'px' : null,
                'font-weight' => $heading['weight'] !== null ? (string) $heading['weight'] : null,
                'line-height' => $heading['line_height'] !== null ? self::number($heading['line_height']) : null,
                'letter-spacing' => $heading['letter_spacing'] !== null ? self::number($heading['letter_spacing']) . 'em' : null,
                'text-transform' => $heading['transform'] !== '' ? $heading['transform'] : null,
            ], static fn(?string $value): bool => $value !== null);

            if ($declarations !== []) {
                $css .= $level . '{' . self::declarations($declarations) . '}';
            }

            if ($heading['size'] !== null && $heading['size'] >= self::MOBILE_HEADING_FROM) {
                $mobile .= $level . '{font-size:' . (int) round($heading['size'] * self::MOBILE_HEADING_FACTOR) . 'px;}';
            }
        }

        if ($mobile !== '') {
            $css .= '@media (max-width: 767px){' . $mobile . '}';
        }

        return $css === '' ? '' : "\n/* Headings */\n" . $css;
    }

    public static function baseStylesheet(): string
    {
        static $css = null;

        return $css ??= (string) file_get_contents(__DIR__ . '/assets/site.css');
    }

    /** Black or white, whichever reads better on the given background. */
    public static function contrastColor(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $channel = static function (int $value): float {
            $c = $value / 255;
            return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        };
        $luminance = 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);

        return $luminance > 0.4 ? '#0f172a' : '#ffffff';
    }

    private static function colorVariables(array $colors): array
    {
        $variables = [];

        foreach (self::COLOR_KEYS as $key) {
            $variables['--c-' . $key] = $colors[$key];
        }

        foreach (self::COLOR_KEYS as $key) {
            $variables['--c-on-' . $key] = self::contrastColor($colors[$key]);
            [$r, $g, $b] = self::rgb($colors[$key]);
            $variables['--c-' . $key . '-rgb'] = $r . ' ' . $g . ' ' . $b;
        }

        return $variables;
    }

    private static function declarations(array $values): string
    {
        $css = '';

        foreach ($values as $name => $value) {
            $css .= $name . ':' . $value . ';';
        }

        return $css;
    }

    private static function colors(mixed $colors, array $defaults): array
    {
        $colors = is_array($colors) ? $colors : [];
        $normalized = [];

        foreach (self::COLOR_KEYS as $key) {
            $normalized[$key] = self::hexColor($colors[$key] ?? null) ?? $defaults[$key];
        }

        return $normalized;
    }

    /** Stable tokens let color names and values change without rewriting layouts. */
    public static function isCustomColorKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^custom-[a-z0-9][a-z0-9-]{0,47}$/', $key) === 1;
    }

    private static function customColors(mixed $colors): array
    {
        $normalized = [];
        foreach (is_array($colors) ? $colors : [] as $key => $color) {
            if (count($normalized) >= self::MAX_CUSTOM_COLORS) {
                break;
            }
            if (!self::isCustomColorKey($key) || !is_array($color)) {
                continue;
            }
            $value = self::hexColor($color['color'] ?? null);
            if ($value === null) {
                continue;
            }
            $label = is_string($color['label'] ?? null) ? trim(strip_tags($color['label'])) : '';
            $normalized[$key] = [
                'label' => Text::limit($label !== '' ? $label : $key, 80),
                'color' => $value,
                'dark_color' => self::hexColor($color['dark_color'] ?? null) ?? $value,
            ];
        }

        return $normalized;
    }

    /**
     * Link colors are palette tokens, so they follow dark mode. Themes saved
     * before link settings existed carry only the underline switch, and links
     * have always underlined on hover.
     */
    private static function links(array $theme, array $customColors, array $defaults): array
    {
        $links = is_array($theme['links'] ?? null) ? $theme['links'] : [];
        if (!isset($links['decoration']) && array_key_exists('link_underline', $theme)) {
            $links['decoration'] = $theme['link_underline'] ? 'underline' : 'hover';
        }
        $color = static fn(mixed $value, string $default): string => is_string($value) && (in_array($value, self::COLOR_KEYS, true) || isset($customColors[$value])) ? $value : $default;

        return [
            'color' => $color($links['color'] ?? null, $defaults['color']),
            'hover_color' => $color($links['hover_color'] ?? null, $defaults['hover_color']),
            'decoration' => self::oneOf($links['decoration'] ?? null, self::LINK_DECORATIONS, $defaults['decoration']),
            'thickness' => self::clampInt($links['thickness'] ?? null, 0, 6, $defaults['thickness']),
            'offset' => round(self::clampFloat($links['offset'] ?? null, 0, 0.5, $defaults['offset']), 2),
        ];
    }

    private static function shadowScale(mixed $scale): array
    {
        $normalized = Shadow::scaleDefaults();
        foreach ($normalized as $key => $default) {
            $value = is_array($scale) && is_array($scale[$key] ?? null) ? Shadow::normalize($scale[$key] + $default) : '';
            if (is_array($value)) $normalized[$key] = $value;
        }
        return $normalized;
    }

    private static function shadows(mixed $shadows): array
    {
        $normalized = [];
        foreach (is_array($shadows) ? $shadows : [] as $key => $shadow) {
            if (count($normalized) >= self::MAX_SHADOWS) {
                break;
            }
            $value = Shadow::isPresetKey($key) && is_array($shadow) ? Shadow::normalize($shadow) : '';
            if (!is_array($value)) {
                continue;
            }
            $label = is_string($shadow['label'] ?? null) ? trim(strip_tags($shadow['label'])) : '';
            $normalized[$key] = ['label' => Text::limit($label, 80)] + $value;
        }

        return $normalized;
    }

    private static function hexColor(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        if (preg_match('/^#([0-9a-f]{3})$/', $value, $match)) {
            $value = '#' . $match[1][0] . $match[1][0] . $match[1][1] . $match[1][1] . $match[1][2] . $match[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : null;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    private static function customCss(string $css): string
    {
        $css = substr(str_replace("\0", '', $css), 0, self::MAX_CUSTOM_CSS);

        // The stylesheet is also inlined into <style> for previews; a closing
        // tag sequence must never be able to end that element early.
        return trim(str_ireplace(['</style', '<style', '<!--', '-->'], '', $css));
    }

    private static function headings(mixed $headings): array
    {
        $headings = is_array($headings) ? $headings : [];
        $normalized = [];

        foreach (array_keys(self::HEADING_LEVELS) as $level) {
            $heading = is_array($headings[$level] ?? null) ? $headings[$level] : [];
            $normalized[$level] = [
                'font' => self::fontChoice($heading['font'] ?? null, ''),
                'size' => self::optionalInt($heading['size'] ?? null, 10, 160),
                'weight' => self::optionalInt($heading['weight'] ?? null, 100, 900, 100),
                'line_height' => self::optionalFloat($heading['line_height'] ?? null, 0.8, 2.2, 2),
                'letter_spacing' => self::optionalFloat($heading['letter_spacing'] ?? null, -0.1, 0.5, 3),
                'transform' => self::oneOf($heading['transform'] ?? null, self::TEXT_TRANSFORMS, ''),
            ];
        }

        return $normalized;
    }

    /** Null (inherit) unless a number is given; empty input means inherit too. */
    private static function optionalInt(mixed $value, int $min, int $max, int $step = 1): ?int
    {
        return is_numeric($value) ? self::clampInt($value, $min, $max, $min, $step) : null;
    }

    private static function optionalFloat(mixed $value, float $min, float $max, int $precision): ?float
    {
        return is_numeric($value) ? round(self::clampFloat($value, $min, $max, $min), $precision) : null;
    }

    private static function radii(array $theme, array $defaults): array
    {
        $radii = [];

        foreach (self::RADIUS_TOKENS as $token) {
            $radii[$token] = self::clampInt($theme['radii'][$token] ?? null, 0, 64, $defaults[$token]);
        }

        return $radii;
    }

    /** The explicit spacing scale. */
    private static function space(array $theme, array $defaults): array
    {
        $space = [];

        foreach (self::SPACE_TOKENS as $token) {
            $space[$token] = self::clampInt($theme['space'][$token] ?? null, 0, 400, $defaults[$token]);
        }

        return $space;
    }

    private static function motion(mixed $motion, array $defaults): array
    {
        $motion = is_array($motion) ? $motion : [];
        $normalized = [];

        foreach (self::MOTION_SPEEDS as $speed) {
            $normalized[$speed] = self::clampInt($motion[$speed] ?? null, 100, 5000, $defaults[$speed], 50);
        }
        $normalized['stagger'] = self::clampInt($motion['stagger'] ?? null, 0, 5000, $defaults['stagger'], 10);
        $normalized['hover'] = self::clampInt($motion['hover'] ?? null, 0, 1000, $defaults['hover'], 10);

        return $normalized;
    }

    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function clampInt(mixed $value, int $min, int $max, int $default, int $step = 1): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $number = (int) round((float) $value / $step) * $step;

        return max($min, min($max, $number));
    }

    private static function clampFloat(mixed $value, float $min, float $max, float $default): float
    {
        return is_numeric($value) ? max($min, min($max, (float) $value)) : $default;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
