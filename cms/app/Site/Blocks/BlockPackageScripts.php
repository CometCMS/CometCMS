<?php

declare(strict_types=1);

namespace CometCMS\Site\Blocks;

/** A package with JavaScript, awaiting the administrator's consent to run it on the website. */
final class BlockPackageScripts extends \RuntimeException
{
    /** @param list<string> $blocks the blocks that have a script */
    public function __construct(public readonly string $package, public readonly array $blocks)
    {
        parent::__construct(sprintf('%s contains JavaScript that runs on the website and in previews.', $package));
    }
}
