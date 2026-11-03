<?php

declare(strict_types=1);

namespace CometCMS\Site;

/**
 * Section shape dividers and element masks: fixed SVG paths, so layouts only
 * store a shape's name. The admin builder draws its shape pickers from the
 * same paths (see spec()).
 */
final class Shapes
{
    /**
     * Dividers along a section's top edge, filled from the edge inwards in a
     * 1200×120 box that stretches to the section's width and the chosen height.
     */
    public const DIVIDER_WIDTH = 1200;
    public const DIVIDER_HEIGHT = 120;

    private const DIVIDERS = [
        'wave' => 'M0,0V64C200,124 400,124 600,64S1000,4 1200,64V0Z',
        'curve' => 'M0,0Q600,240 1200,0Z',
        'slope' => 'M0,0V120L1200,0Z',
        'triangle' => 'M0,0L600,120L1200,0Z',
        'double_wave' => 'M0,0V60C100,-20 200,-20 300,60S500,140 600,60S800,-20 900,60S1100,140 1200,60V0Z',
        'asymmetric_curve' => 'M0,0V120C240,120 300,0 1200,0Z',
        'mountains' => 'M0,0V80L180,25L360,100L540,40L720,120L960,20L1200,80V0Z',
        'steps' => 'M0,0V120H240V96H480V72H720V48H960V24H1200V0Z',
        'scallops' => 'M0,0V20Q100,220 200,20Q300,220 400,20Q500,220 600,20Q700,220 800,20Q900,220 1000,20Q1100,220 1200,20V0Z',
        'arrow' => 'M0,0V35H440L600,120L760,35H1200V0Z',
    ];

    /** Teeth of the zigzag divider. */
    private const ZIGZAG_TEETH = 24;

    /**
     * Masks crop a node to a shape drawn in a 100×100 box, which stretches to
     * the node's box or fits inside it (see maskSvg()). Grouped roughly:
     * basic forms, polygons, decorative shapes, then edges that only shape one side.
     */
    public const MASK_SIZE = 100;

    private const MASKS = [
        'circle' => 'M50,0A50,50 0 1 1 50,100A50,50 0 1 1 50,0Z',
        'squircle' => 'M50,0C90,0 100,10 100,50C100,90 90,100 50,100C10,100 0,90 0,50C0,10 10,0 50,0Z',
        'arch' => 'M0,100V50A50,50 0 0 1 100,50V100Z',
        'leaf' => 'M0,100V50A50,50 0 0 1 50,0H100V50A50,50 0 0 1 50,100Z',
        'triangle' => 'M50,0L100,100H0Z',
        'diamond' => 'M50,0L100,50L50,100L0,50Z',
        'pentagon' => 'M50,0L100,38L81,100H19L0,38Z',
        'hexagon' => 'M25,0H75L100,50L75,100H25L0,50Z',
        'octagon' => 'M30,0H70L100,30V70L70,100H30L0,70V30Z',
        'parallelogram' => 'M20,0H100L80,100H0Z',
        'chevron' => 'M0,0H75L100,50L75,100H0L25,50Z',
        'cross' => 'M33,0H67V33H100V67H67V100H33V67H0V33H33Z',
        'star' => 'M50,0L61,35H98L68,57L79,91L50,70L21,91L32,57L2,35H39Z',
        'heart' => 'M50,100C18,76 0,58 0,31C0,13 13,0 29,0C39,0 46,5 50,14C54,5 61,0 71,0C87,0 100,13 100,31C100,58 82,76 50,100Z',
        'shield' => 'M50,0L100,15V45C100,75 78,92 50,100C22,92 0,75 0,45V15Z',
        'speech_bubble' => 'M10,0H90A10,10 0 0 1 100,10V70A10,10 0 0 1 90,80H40L20,100V80H10A10,10 0 0 1 0,70V10A10,10 0 0 1 10,0Z',
        'blob' => 'M54,2C74,2 94,14 98,36C100,58 92,82 72,94C52,100 26,98 12,82C0,66 2,40 12,22C22,6 38,2 54,2Z',
        'blob_alt' => 'M30,4C46,0 60,8 76,8C92,10 100,28 98,46C96,62 86,72 82,86C76,100 56,100 40,94C22,88 4,80 2,60C0,40 12,10 30,4Z',
        'wave' => 'M0,0H100V88C83,100 67,100 50,88C33,76 17,76 0,88Z',
        'slant' => 'M0,0H100V80L0,100Z',
        'notch' => 'M0,0H100V85L50,100L0,85Z',
    ];

    /** Points of the badge mask and petals of the flower mask, drawn around the box's center. */
    private const BADGE_POINTS = 16;
    private const FLOWER_PETALS = 10;

    /** @return list<string> */
    public static function dividers(): array
    {
        return ['none', ...array_keys(self::DIVIDERS), 'zigzag'];
    }

    public static function dividerPath(string $shape): string
    {
        if ($shape !== 'zigzag') {
            return self::DIVIDERS[$shape] ?? '';
        }
        $tooth = self::DIVIDER_WIDTH / self::ZIGZAG_TEETH;
        $path = 'M0,0';
        for ($i = 0; $i < self::ZIGZAG_TEETH; $i++) {
            $path .= 'L' . ($i * $tooth + $tooth / 2) . ',' . self::DIVIDER_HEIGHT . 'L' . (($i + 1) * $tooth) . ',0';
        }

        return $path . 'Z';
    }

    /**
     * A divider's SVG, filled with the current color. Inverted dividers fill
     * the rest of the box instead, turned towards the edge; flipped ones are
     * mirrored sideways. The SVG mirrors the path itself: a mirrored element
     * would let browsers bleed its far edge into a hairline.
     */
    public static function dividerSvg(string $shape, string $edge, bool $flip, bool $invert): string
    {
        $path = self::dividerPath($shape);
        if ($path === '') {
            return '';
        }
        [$width, $height] = [self::DIVIDER_WIDTH, self::DIVIDER_HEIGHT];
        if ($invert) {
            $path = 'M0,0H' . $width . 'V' . $height . 'H0Z' . $path;
        }
        $x = $flip ? -1 : 1;
        $y = ($edge === 'bottom') !== $invert ? -1 : 1;
        $transform = $x === 1 && $y === 1 ? '' : ' transform="matrix(' . $x . ' 0 0 ' . $y . ' ' . ($x === -1 ? $width : 0) . ' ' . ($y === -1 ? $height : 0) . ')"';

        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" focusable="false">'
            . '<path d="' . $path . '"' . $transform . ' fill="currentColor" fill-rule="evenodd"/></svg>';
    }

    /** @return list<string> */
    public static function masks(): array
    {
        $names = array_keys(self::MASKS);
        // The generated shapes sit with the other decorative ones, before the edges.
        array_splice($names, array_search('blob', $names, true), 0, ['badge', 'flower']);

        return ['none', ...$names];
    }

    public static function maskPath(string $shape): string
    {
        $center = self::MASK_SIZE / 2;
        $point = static fn(float $angle, float $radius): string => round($center + $radius * cos($angle), 2) . ',' . round($center + $radius * sin($angle), 2);

        if ($shape === 'badge') {
            $path = '';
            for ($i = 0; $i < self::BADGE_POINTS * 2; $i++) {
                $path .= ($i === 0 ? 'M' : 'L') . $point(M_PI * $i / self::BADGE_POINTS - M_PI / 2, $i % 2 === 0 ? 50 : 42);
            }

            return $path . 'Z';
        }
        if ($shape === 'flower') {
            // Each petal bulges from the inner circle out to the box's edge.
            $step = 2 * M_PI / self::FLOWER_PETALS;
            $path = 'M' . $point(-M_PI / 2, 40);
            for ($i = 0; $i < self::FLOWER_PETALS; $i++) {
                $angle = $step * $i - M_PI / 2;
                $path .= 'Q' . $point($angle + $step / 2, 60) . ' ' . $point($angle + $step, 40);
            }

            return $path . 'Z';
        }

        return self::MASKS[$shape] ?? '';
    }

    /**
     * A mask's SVG, ready for a CSS mask image: it stretches to the box it
     * is drawn in, and is mirrored sideways ($flipX) or upside down ($flipY).
     * Like dividers, the SVG mirrors the path itself rather than the element.
     */
    public static function maskSvg(string $shape, bool $flipX = false, bool $flipY = false): string
    {
        $path = self::maskPath($shape);
        if ($path === '') {
            return '';
        }
        $size = self::MASK_SIZE;
        $transform = $flipX || $flipY
            ? ' transform="matrix(' . ($flipX ? -1 : 1) . ' 0 0 ' . ($flipY ? -1 : 1) . ' ' . ($flipX ? $size : 0) . ' ' . ($flipY ? $size : 0) . ')"'
            : '';

        // Its own size gives it proportions to keep when fitted (contain); a stretched one ignores them.
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" preserveAspectRatio="none">'
            . '<path d="' . $path . '"' . $transform . '/></svg>';
    }

    /** The CSS image of a mask, or '' for none. */
    public static function maskImage(string $shape, bool $flipX = false, bool $flipY = false): string
    {
        $svg = self::maskSvg($shape, $flipX, $flipY);

        return $svg === '' ? '' : 'url(data:image/svg+xml,' . rawurlencode($svg) . ')';
    }

    /** Paths and boxes the admin builder draws its shape pickers with. */
    public static function spec(): array
    {
        $dividers = [];
        foreach (self::dividers() as $shape) {
            if ($shape !== 'none') $dividers[$shape] = self::dividerPath($shape);
        }
        $masks = [];
        foreach (self::masks() as $shape) {
            if ($shape !== 'none') $masks[$shape] = self::maskPath($shape);
        }

        return [
            'divider' => ['width' => self::DIVIDER_WIDTH, 'height' => self::DIVIDER_HEIGHT, 'paths' => $dividers],
            'mask' => ['width' => self::MASK_SIZE, 'height' => self::MASK_SIZE, 'paths' => $masks],
        ];
    }
}
