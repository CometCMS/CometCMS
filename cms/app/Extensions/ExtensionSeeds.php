<?php

declare(strict_types=1);

namespace CometCMS\Extensions;

/** The administrator chooses which site-owned items to copy from an extension. */
final class ExtensionSeeds extends \RuntimeException
{
    public function __construct(public readonly string $extension, public readonly array $plan)
    {
        parent::__construct($extension . ' brings site content. Choose which items to create, overwrite or skip.');
    }
}
