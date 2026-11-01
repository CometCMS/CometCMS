<?php

declare(strict_types=1);

namespace CometCMS\Site\Blocks;

/** An update that would remove or change what placed blocks rely on, awaiting confirmation. */
final class BlockPackageChanges extends \RuntimeException
{
    /** @param list<string> $changes */
    public function __construct(
        public readonly string $package,
        public readonly string $installedVersion,
        public readonly string $version,
        public readonly array $changes,
    ) {
        parent::__construct(sprintf('Updating %s from %s to %s changes blocks that may already be placed in layouts.', $package, $installedVersion, $version));
    }
}
