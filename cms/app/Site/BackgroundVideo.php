<?php

declare(strict_types=1);

namespace CometCMS\Site;

/** Bundled background video playback, allowed by its exact CSP hash. */
final class BackgroundVideo
{
    public static function script(): string
    {
        return (string) file_get_contents(__DIR__ . '/assets/background-video.js') . "\ninitBackgroundVideos(document);";
    }

    public static function cspSource(): string
    {
        return "'sha256-" . base64_encode(hash('sha256', self::script(), true)) . "'";
    }
}
