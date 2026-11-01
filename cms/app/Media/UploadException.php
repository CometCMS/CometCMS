<?php

declare(strict_types=1);

namespace CometCMS\Media;

final class UploadException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
