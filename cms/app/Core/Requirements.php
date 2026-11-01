<?php

declare(strict_types=1);

namespace CometCMS\Core;

/**
 * PHP features CometCMS cannot run without. Missing ones otherwise surface much later,
 * as "Call to undefined function" errors in whatever request happens to use them first.
 */
final class Requirements
{
    public const PHP_VERSION = '8.2.0';

    /** Extensions with the function that proves each is usable; polyfills count. */
    private const EXTENSIONS = ['json' => 'json_encode', 'mbstring' => 'mb_substr'];

    /** @return list<string> what is missing, as names an administrator can install */
    public static function missing(?string $version = null, ?callable $available = null): array
    {
        $available ??= static fn(string $extension, string $function): bool => extension_loaded($extension) || function_exists($function);
        $missing = version_compare($version ?? PHP_VERSION, self::PHP_VERSION, '<') ? ['PHP ' . self::PHP_VERSION . ' or later'] : [];
        foreach (self::EXTENSIONS as $extension => $function) {
            if (!$available($extension, $function)) $missing[] = 'the PHP extension "' . $extension . '"';
        }

        return $missing;
    }

    /** How to fix it, naming the package for the running PHP version on Debian and Ubuntu as an example. */
    public static function hint(array $missing): string
    {
        $packages = [];
        foreach ($missing as $item) {
            if (preg_match('/extension "([a-z]+)"/', $item, $match) && $match[1] !== 'json') {
                $packages[] = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-' . $match[1];
            }
        }

        return 'This server lacks ' . implode(' and ', $missing) . '. Install ' . (count($missing) > 1 ? 'them' : 'it') . ' and restart PHP or the web server.'
            . ($packages !== [] ? ' On Debian and Ubuntu: sudo apt install ' . implode(' ', $packages) . '.' : '');
    }
}
