<?php

declare(strict_types=1);

namespace CometCMS\Site;

/**
 * Tablet, mobile, hover and focus versions of the style classes.
 *
 * A node's overrides (see LayoutSchema::normalizeOverrides) render as the
 * same utility classes with a prefix: `c-t-pt-lg` is `c-pt-lg` on tablets
 * and phones, `c-h-bg-primary` is `c-bg-primary` while hovered. Rather than
 * repeating every utility rule by hand, they are derived from the
 * stylesheet's own top-level rules, so custom colors and installed fonts
 * get them too.
 */
final class StyleVariants
{
    /** Widest screen of each device, as in site.css; mobile follows tablet. */
    public const DEVICES = ['tablet' => 1023, 'mobile' => 767];

    public const STATES = ['hover', 'focus'];

    /** Class prefix of each override. */
    public const PREFIXES = ['tablet' => 'c-t-', 'mobile' => 'c-m-', 'hover' => 'c-h-', 'focus' => 'c-f-'];

    /**
     * When a state shows. The builder previews one with its class on the
     * selected element (see BuilderCanvas); focus also covers a focused link
     * inside, so a card lights up while it is tabbed through.
     */
    private const PSEUDO = [
        'hover' => ':is(:hover,.c-preview-hover)',
        'focus' => ':is(:focus-visible,:has(:focus-visible),.c-preview-focus)',
    ];

    /** A link color changes with the link itself, not with its element. */
    private const LINK_PSEUDO = [
        'hover' => ':is(:hover,.c-preview-hover *)',
        'focus' => ':is(:focus-visible,.c-preview-focus *)',
    ];

    /** Utility classes devices may change; background image classes keep their own. */
    private const DEVICE_TOKENS = '(?:p[trbl]|m[trbl]|bg(?!-(?:size|pos)-|-(?:no-)?repeat\b)|fg|lc|ta|fs|fw|ff|ts|r|sh|mw|pos|pin|z|op|v|gap|items|self|stack|flow-justify|flow-align|spacer|btn-(?:bg|fg|bdc))-[A-Za-z0-9_-]+|button-(?:sm|md|lg|block|inline)';

    /** Utility classes states may change. */
    private const STATE_TOKENS = '(?:bg(?!-(?:size|pos)-|-(?:no-)?repeat\b)|fg|lc|bdc|sh|ts|op|btn-(?:bg|fg|bdc))-[A-Za-z0-9_-]+';

    /** Rules for every device and state, from the stylesheet built so far. */
    public static function css(string $stylesheet): string
    {
        $rules = self::rules($stylesheet);
        $css = '';

        foreach (self::DEVICES as $device => $width) {
            $block = self::variant($rules, self::DEVICE_TOKENS, self::PREFIXES[$device], '');
            if ($block !== '') {
                $css .= '@media (max-width: ' . $width . 'px){' . $block . '}';
            }
        }
        foreach (self::STATES as $state) {
            $css .= self::variant($rules, self::STATE_TOKENS, self::PREFIXES[$state], self::PSEUDO[$state], self::LINK_PSEUDO[$state]);
        }

        return "\n/* Device and state overrides */\n.c-stateful{transition-property:background-color,color,border-color,box-shadow,text-shadow,opacity;transition-duration:calc(var(--motion-hover,200) * 1ms);transition-timing-function:ease;}" . $css;
    }

    /**
     * Top-level rules as [selectors, declarations]; at-rules (media queries,
     * font faces, keyframes) are left out.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function rules(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $rules = [];
        $length = strlen($css);
        $start = 0;
        $depth = 0;
        $open = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($char === '{') {
                if ($depth === 0) {
                    $open = $i;
                }
                $depth++;
            } elseif ($char === '}' && $depth > 0) {
                $depth--;
                if ($depth === 0) {
                    $selector = trim(substr($css, $start, $open - $start));
                    if ($selector !== '' && $selector[0] !== '@') {
                        $rules[] = [$selector, trim(substr($css, $open + 1, $i - $open - 1))];
                    }
                    $start = $i + 1;
                }
            } elseif ($char === ';' && $depth === 0) {
                // A statement at-rule such as @import.
                $start = $i + 1;
            }
        }

        return $rules;
    }

    /** The rules that use a token, renamed with $prefix and limited to $pseudo. */
    private static function variant(array $rules, string $tokens, string $prefix, string $pseudo, string $linkPseudo = ''): string
    {
        $css = '';

        foreach ($rules as [$selectors, $declarations]) {
            $renamed = [];
            foreach (self::split($selectors) as $selector) {
                $selector = self::rename($selector, $tokens, $prefix, $pseudo, $linkPseudo);
                if ($selector !== null) {
                    $renamed[] = $selector;
                }
            }
            if ($renamed !== []) {
                $css .= implode(',', $renamed) . '{' . $declarations . '}';
            }
        }

        return $css;
    }

    /**
     * One selector with its tokens renamed, or null when it has none. Tokens
     * inside :not() stay as they are: there they describe nodes without that
     * base class, which an override does not change.
     */
    private static function rename(string $selector, string $tokens, string $prefix, string $pseudo, string $linkPseudo): ?string
    {
        $kept = [];
        $masked = (string) preg_replace_callback('/:not\((?:[^()]|\((?:[^()])*\))*\)/', static function (array $match) use (&$kept): string {
            $kept[] = $match[0];
            return "\0" . (count($kept) - 1) . "\0";
        }, $selector);

        $found = false;
        $link = false;
        $masked = (string) preg_replace_callback(
            '/\.c-(' . $tokens . ')(?![A-Za-z0-9_-])|\[class\*="c-(' . $tokens . ')"\]/',
            static function (array $match) use (&$found, &$link, $prefix, $pseudo, $linkPseudo): string {
                $found = true;
                if (($match[2] ?? '') !== '') {
                    return '[class*="' . $prefix . $match[2] . '"]';
                }
                if ($linkPseudo !== '' && str_starts_with($match[1], 'lc-')) {
                    $link = true;
                    return '.' . $prefix . $match[1];
                }
                return '.' . $prefix . $match[1] . $pseudo;
            },
            $masked,
        );
        if (!$found) {
            return null;
        }
        if ($link) {
            $masked .= $linkPseudo;
        }

        return (string) preg_replace_callback('/\0(\d+)\0/', static fn(array $match): string => $kept[(int) $match[1]], $masked);
    }

    /** Selectors of a list, keeping commas inside :is() and the like. */
    private static function split(string $selectors): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($selectors) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }
}
