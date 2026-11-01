<?php

declare(strict_types=1);

namespace CometCMS\Extensions;

/** One install preview covers destructive changes, trusted scripts and site-content choices. */
final class ExtensionReview extends \RuntimeException
{
    public function __construct(
        public readonly string $extension,
        public readonly ?string $installedVersion,
        public readonly string $version,
        public readonly array $changes,
        public readonly array $scripts,
        public readonly array $plan,
    ) {
        parent::__construct('Review ' . $extension . ' before installing.');
    }

    public function details(): array
    {
        return ['extension' => $this->extension, 'installed_version' => $this->installedVersion, 'version' => $this->version,
            'changes' => $this->changes, 'scripts' => $this->scripts, 'plan' => $this->plan];
    }
}
