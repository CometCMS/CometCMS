<?php

declare(strict_types=1);

namespace CometCMS\Extensions;

/**
 * Semantic version constraints as npm and Composer write them: "*", "1.2.3", "^1.2",
 * "~1.2.3", ">=1.0 <2.0", partial versions ("1", "1.4") and alternatives joined by "||".
 */
final class VersionConstraint
{
    private const VERSION = '(\d{1,5})(?:\.(\d{1,5}|[xX*]))?(?:\.(\d{1,5}|[xX*]))?(?:-([a-zA-Z0-9.-]{1,40}))?';

    public static function valid(mixed $constraint): bool
    {
        return is_string($constraint) && trim($constraint) !== '' && strlen($constraint) <= 100 && self::ranges($constraint) !== null;
    }

    public static function satisfies(string $version, string $constraint): bool
    {
        $parsed = self::parse($version);
        if ($parsed === null) return false;
        foreach (self::ranges($constraint) ?? [] as $range) {
            if (array_filter($range, static fn(array $bound): bool => !self::holds($parsed, $bound)) === []) return true;
        }

        return false;
    }

    /** @return list<list<array{0: string, 1: array}>>|null each alternative as [operator, version] bounds */
    private static function ranges(string $constraint): ?array
    {
        $ranges = [];
        foreach (explode('||', $constraint) as $alternative) {
            $alternative = trim($alternative);
            if ($alternative === '' || $alternative === '*') {
                $ranges[] = [];
                continue;
            }
            $bounds = [];
            foreach (preg_split('/\s+/', $alternative) ?: [] as $part) {
                if (!preg_match('/^(\^|~|>=|<=|>|<|=)?v?' . self::VERSION . '$/D', $part, $match)) return null;
                array_push($bounds, ...self::bounds($match[1], $match));
            }
            $ranges[] = $bounds;
        }

        return $ranges;
    }

    /** One comparator as lower and upper bounds. Missing or wildcard parts widen the range. */
    private static function bounds(string $operator, array $match): array
    {
        $major = (int) $match[2];
        $minor = isset($match[3]) && is_numeric($match[3]) ? (int) $match[3] : null;
        $patch = $minor !== null && isset($match[4]) && is_numeric($match[4]) ? (int) $match[4] : null;
        $pre = ($match[5] ?? '') !== '' ? explode('.', $match[5]) : [];
        $low = [$major, $minor ?? 0, $patch ?? 0, $pre];
        $next = $minor === null ? [$major + 1, 0, 0, []] : ($patch === null ? [$major, $minor + 1, 0, []] : null);

        return match ($operator) {
            '^' => [['>=', $low], ['<', match (true) {
                $major > 0 || $minor === null => [$major + 1, 0, 0, []],
                $minor > 0 || $patch === null => [0, $minor + 1, 0, []],
                default => [0, 0, $patch + 1, []],
            }]],
            '~' => [['>=', $low], ['<', $minor === null ? [$major + 1, 0, 0, []] : [$major, $minor + 1, 0, []]]],
            '>=' => [['>=', $low]],
            '>' => $next === null ? [['>', $low]] : [['>=', $next]],
            '<' => [['<', $low]],
            '<=' => $next === null ? [['<=', $low]] : [['<', $next]],
            default => $next === null ? [['=', $low]] : [['>=', $low], ['<', $next]],
        };
    }

    private static function holds(array $version, array $bound): bool
    {
        $order = self::compare($version, $bound[1]);

        return match ($bound[0]) {
            '>=' => $order >= 0, '>' => $order > 0, '<' => $order < 0, '<=' => $order <= 0, default => $order === 0,
        };
    }

    private static function parse(string $version): ?array
    {
        if (!preg_match('/^(\d{1,5})\.(\d{1,5})\.(\d{1,5})(?:-([a-zA-Z0-9.-]{1,40}))?$/D', $version, $match)) return null;

        return [(int) $match[1], (int) $match[2], (int) $match[3], ($match[4] ?? '') !== '' ? explode('.', $match[4]) : []];
    }

    /** Release parts numerically; a pre-release sorts before its release, identifiers as semver orders them. */
    private static function compare(array $a, array $b): int
    {
        for ($i = 0; $i < 3; $i++) {
            if ($a[$i] !== $b[$i]) return $a[$i] <=> $b[$i];
        }
        if ($a[3] === [] || $b[3] === []) return ($a[3] === []) <=> ($b[3] === []);
        foreach ($a[3] as $index => $part) {
            if (!isset($b[3][$index])) return 1;
            $other = $b[3][$index];
            if ($part === $other) continue;
            if (ctype_digit($part) && ctype_digit($other)) return (int) $part <=> (int) $other;
            if (ctype_digit($part) !== ctype_digit($other)) return ctype_digit($part) ? -1 : 1;
            return strcmp($part, $other) <=> 0;
        }

        return count($a[3]) <=> count($b[3]);
    }
}
