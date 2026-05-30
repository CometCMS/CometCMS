<?php

declare(strict_types=1);

namespace CometCMS\Mcp;

final class McpError extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly mixed $details = null,
        private readonly array $requiredPermissions = [],
        private readonly array $recovery = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): mixed
    {
        return $this->details;
    }

    public function requiredPermissions(): array
    {
        return $this->requiredPermissions;
    }

    public function recovery(): array
    {
        return $this->recovery;
    }
}
